<?php

namespace App\Services;

use App\Models\PresentationSource;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PublicPresentationScanner
{
    private const MAX_PAGE_BYTES = 2_500_000;
    private const MAX_REDIRECTS = 3;
    private const MAX_DISCOVERY_PAGES = 6;

    public function scan(PresentationSource $source): array
    {
        $sourceUrl = $this->normalizeUrl($source->url);

        if ($this->presentationType($sourceUrl)) {
            return [[
                'title' => $this->fallbackTitle($sourceUrl),
                'file_url' => $sourceUrl,
                'file_type' => $this->presentationType($sourceUrl),
                'source_page_url' => $sourceUrl,
            ]];
        }

        $candidates = [];
        $page = $this->fetchText($sourceUrl);

        foreach ($this->extractCandidates($page['body'], $page['url']) as $candidate) {
            $candidates[$candidate['file_url']] = $candidate;
        }

        $origin = $this->origin($page['url']);

        foreach ($this->extractDiscoveryUrls($page['body'], $page['url'], $origin) as $discoveryUrl) {
            try {
                $discoveryPage = $this->fetchText($discoveryUrl, false);
            } catch (RuntimeException) {
                continue;
            }

            if ($this->origin($discoveryPage['url']) !== $origin) {
                continue;
            }

            foreach ($this->extractCandidates($discoveryPage['body'], $discoveryPage['url']) as $candidate) {
                $candidates[$candidate['file_url']] = $candidate;
            }
        }

        $sitemapUrl = $origin.'/sitemap.xml';

        if ($sitemapUrl !== $page['url']) {
            try {
                $sitemap = $this->fetchText($sitemapUrl, false);

                foreach ($this->extractCandidates($sitemap['body'], $sitemap['url']) as $candidate) {
                    $candidates[$candidate['file_url']] = $candidate;
                }
            } catch (RuntimeException) {
                // Sitemap is optional. The source/discovery pages remain useful.
            }
        }

        return array_values($candidates);
    }

    private function fetchText(string $url, bool $failOnNotFound = true): array
    {
        $current = $url;

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect += 1) {
            $this->assertPublicUrl($current);

            $response = Http::accept('text/html,application/xhtml+xml,application/xml,text/xml;q=0.9,*/*;q=0.1')
                ->withHeaders([
                    'User-Agent' => 'Zampolit73PresentationIndex/0.2 (+https://zampolit73.duckdns.org)',
                ])
                ->withOptions(['allow_redirects' => false])
                ->connectTimeout(3)
                ->timeout(6)
                ->get($current);

            if ($response->redirect()) {
                $location = $response->header('Location');
                if (! $location) {
                    throw new RuntimeException('Источник вернул редирект без адреса назначения.');
                }

                $current = $this->resolveUrl($current, $location);
                continue;
            }

            if ($response->status() === 404 && ! $failOnNotFound) {
                throw new RuntimeException('Страница не найдена.');
            }

            if (! $response->successful()) {
                throw new RuntimeException('Источник ответил HTTP '.$response->status().'.');
            }

            return [
                'body' => $this->limitedBody($response),
                'url' => $current,
            ];
        }

        throw new RuntimeException('Слишком много редиректов у источника.');
    }

    private function limitedBody(Response $response): string
    {
        $body = $response->body();

        if (strlen($body) > self::MAX_PAGE_BYTES) {
            throw new RuntimeException('HTML/XML страница слишком большая для лёгкого сканирования.');
        }

        return $body;
    }

    private function extractCandidates(string $body, string $baseUrl): array
    {
        $candidates = [];

        preg_match_all('~https?://[^\\s<>"\']+?\\.(?:pdf|pptx?|PDF|PPTX?)(?:\\?[^\\s<>"\']*)?~u', html_entity_decode($body), $matches);

        foreach ($matches[0] ?? [] as $url) {
            $this->addCandidate($candidates, $url, null, $baseUrl);
        }

        $document = $this->htmlDocument($body);

        if (! $document) {
            return array_values($candidates);
        }

        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $url = $this->resolveUrl($baseUrl, $href);
            $title = trim(preg_replace('/\\s+/u', ' ', $anchor->textContent ?? '') ?? '');
            $this->addCandidate(
                $candidates,
                $url,
                $this->usefulAnchorTitle($title) ? $title : null,
                $baseUrl,
            );
        }

        foreach ($xpath->query('//*[local-name()="loc"]') ?: [] as $loc) {
            $url = trim((string) $loc->textContent);
            if ($url !== '') {
                $this->addCandidate($candidates, $url, null, $baseUrl);
            }
        }

        return array_values($candidates);
    }

    private function extractDiscoveryUrls(string $body, string $baseUrl, string $origin): array
    {
        $document = $this->htmlDocument($body);

        if (! $document) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $ranked = [];

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (! ($anchor instanceof DOMElement)) {
                continue;
            }

            $href = trim((string) $anchor->getAttribute('href'));

            if (
                $href === ''
                || str_starts_with($href, '#')
                || preg_match('~^(?:mailto:|tel:|javascript:)~i', $href)
            ) {
                continue;
            }

            $url = $this->stripFragment($this->resolveUrl($baseUrl, $href));

            if (
                $url === $this->stripFragment($baseUrl)
                || $this->presentationType($url)
                || $this->origin($url) !== $origin
            ) {
                continue;
            }

            $path = mb_strtolower((string) parse_url($url, PHP_URL_PATH));
            $text = mb_strtolower(trim(preg_replace('/\\s+/u', ' ', $anchor->textContent ?? '') ?? ''));
            $haystack = $path.' '.$text;
            $score = 0;

            if (preg_match('~/(?:abstracts?|presentations?|materials?|reports?|speakers?|program(?:me)?)(?:/|$)~iu', $path)) {
                $score += 7;
            }

            if (preg_match('~/abstracts?/\\d+(?:/|$)~u', $path)) {
                $score += 6;
            }

            if (preg_match('~(?:презентац|материал|доклад|спикер|presentation|material|abstract|report|speaker)~iu', $haystack)) {
                $score += 4;
            }

            if (preg_match('~(?:cio|cto|cdo|ит.директор|директор по ит|цифров|industrial|промышлен)~iu', $haystack)) {
                $score += 2;
            }

            if (preg_match('~/(?:login|register|registration|sponsors?|partners?|contacts?)(?:/|$)~iu', $path)) {
                $score -= 10;
            }

            if ($score > 0) {
                $ranked[$url] = max($ranked[$url] ?? 0, $score);
            }
        }

        arsort($ranked);

        return array_slice(array_keys($ranked), 0, self::MAX_DISCOVERY_PAGES);
    }

    private function htmlDocument(string $body): ?DOMDocument
    {
        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<meta charset="utf-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    private function usefulAnchorTitle(string $title): bool
    {
        $normalized = mb_strtolower(trim($title));

        return $normalized !== ''
            && ! in_array($normalized, [
                'скачать',
                'скачать презентацию',
                'презентация',
                'download',
                'download presentation',
            ], true);
    }

    private function addCandidate(array &$candidates, string $url, ?string $title, string $sourcePage): void
    {
        $url = html_entity_decode(trim($url));
        $type = $this->presentationType($url);

        if (! $type || ! preg_match('~^https?://~i', $url)) {
            return;
        }

        $candidates[$url] = [
            'title' => $title ?: $this->fallbackTitle($url),
            'file_url' => $url,
            'file_type' => $type,
            'source_page_url' => $sourcePage,
        ];
    }

    private function presentationType(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['pdf', 'ppt', 'pptx'], true) ? $extension : null;
    }

    private function fallbackTitle(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $name = urldecode(pathinfo($path, PATHINFO_FILENAME));

        return trim(str_replace(['_', '-'], ' ', $name)) ?: 'Презентация';
    }

    private function normalizeUrl(string $url): string
    {
        return trim($url);
    }

    private function stripFragment(string $url): string
    {
        return explode('#', $url, 2)[0];
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return strtolower($origin);
    }

    private function resolveUrl(string $base, string $href): string
    {
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }

        $baseParts = parse_url($base);
        $scheme = $baseParts['scheme'] ?? 'https';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':'.$baseParts['port'] : '';

        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }

        if (str_starts_with($href, '/')) {
            return $scheme.'://'.$host.$port.$href;
        }

        $basePath = $baseParts['path'] ?? '/';
        $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
        $path = ($directory === '' ? '' : $directory).'/'.$href;

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $scheme.'://'.$host.$port.'/'.implode('/', $segments);
    }

    private function assertPublicUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        $port = $parts['port'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Разрешены только публичные HTTP/HTTPS источники.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('URL с логином или паролем не поддерживаются.');
        }

        if ($port !== null && ! in_array((int) $port, [80, 443], true)) {
            throw new RuntimeException('Для сканирования разрешены только стандартные HTTP/HTTPS порты.');
        }

        if (app()->environment('testing') && str_ends_with($host, '.example')) {
            return;
        }

        if ($host === 'localhost' || str_ends_with($host, '.local')) {
            throw new RuntimeException('Локальные адреса сканировать нельзя.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);
            return;
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);

        if (! is_array($records) || $records === []) {
            throw new RuntimeException('Не удалось разрешить домен источника.');
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($ip) {
                $this->assertPublicIp($ip);
            }
        }
    }

    private function assertPublicIp(string $ip): void
    {
        $valid = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );

        if ($valid === false) {
            throw new RuntimeException('Источник ведёт на приватный или служебный IP-адрес.');
        }
    }
}

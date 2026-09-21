<?php

namespace App\Services;

use App\Models\PresentationSource;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class PublicPresentationScanner
{
    private const MAX_PAGE_BYTES = 2_500_000;
    private const MAX_REDIRECTS = 3;

    public function scan(PresentationSource $source): array
    {
        $sourceUrl = $this->normalizeUrl($source->url);
        $page = $this->fetchText($sourceUrl);

        if ($this->presentationType($page['url'])) {
            return [[
                'title' => $this->fallbackTitle($page['url']),
                'file_url' => $page['url'],
                'file_type' => $this->presentationType($page['url']),
                'source_page_url' => $sourceUrl,
            ]];
        }

        $candidates = [];

        foreach ($this->extractCandidates($page['body'], $page['url']) as $candidate) {
            $candidates[$candidate['file_url']] = $candidate;
        }

        $parts = parse_url($page['url']);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        $sitemapUrl = $origin.'/sitemap.xml';

        if ($sitemapUrl !== $page['url']) {
            try {
                $sitemap = $this->fetchText($sitemapUrl, false);

                foreach ($this->extractCandidates($sitemap['body'], $sitemap['url']) as $candidate) {
                    $candidates[$candidate['file_url']] = $candidate;
                }
            } catch (RuntimeException) {
                // Sitemap is optional. The source page itself remains useful.
            }
        }

        return array_values($candidates);
    }

    private function fetchText(string $url, bool $failOnNotFound = true): array
    {
        $current = $url;

        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect += 1) {
            $this->assertPublicUrl($current);

            // A source (or redirect) may itself be a presentation. Keep its URL only.
            if ($this->presentationType($current)) {
                return ['body' => '', 'url' => $current];
            }

            try {
                $response = Http::accept('text/html,application/xhtml+xml,application/xml,text/xml;q=0.9')
                    ->withHeaders([
                        'User-Agent' => 'Zampolit73PresentationIndex/0.1 (+https://zampolit73.duckdns.org)',
                    ])
                    ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 6])
                    ->connectTimeout(3)
                    ->timeout(6)
                    ->get($current);
            } catch (ConnectionException $exception) {
                throw new RuntimeException('Не удалось подключиться к источнику. Попробуй повторить сканирование позже.', 0, $exception);
            }

            try {
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
            } finally {
                $response->toPsrResponse()->getBody()->close();
            }
        }

        throw new RuntimeException('Слишком много редиректов у источника.');
    }

    private function limitedBody(Response $response): string
    {
        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if (! in_array($contentType, ['text/html', 'application/xhtml+xml', 'application/xml', 'text/xml'], true)) {
            throw new RuntimeException('Источник должен вернуть HTML/XML страницу. Файлы презентаций не скачиваются.');
        }

        if ((int) $response->header('Content-Length') > self::MAX_PAGE_BYTES) {
            throw new RuntimeException('HTML/XML страница слишком большая для лёгкого сканирования.');
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        $deadline = microtime(true) + 6;

        while (! $stream->eof() && strlen($body) <= self::MAX_PAGE_BYTES) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Источник слишком долго передаёт HTML/XML страницу.');
            }

            $chunk = $stream->read(min(8192, self::MAX_PAGE_BYTES + 1 - strlen($body)));
            if ($chunk === '' && ! $stream->eof()) {
                throw new RuntimeException('Не удалось дочитать HTML/XML страницу источника.');
            }
            $body .= $chunk;
        }

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

        $document = new DOMDocument();

        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<meta charset="utf-8">'.$body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return array_values($candidates);
        }

        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            try {
                $url = $this->resolveUrl($baseUrl, $href);
            } catch (RuntimeException) {
                // One malformed link must not discard the rest of a source page.
                continue;
            }
            $title = trim(preg_replace('/\\s+/u', ' ', $anchor->textContent ?? '') ?? '');
            $this->addCandidate($candidates, $url, $title !== '' ? $title : null, $baseUrl);
        }

        foreach ($xpath->query('//*[local-name()="loc"]') ?: [] as $loc) {
            $url = trim((string) $loc->textContent);
            if ($url !== '') {
                $this->addCandidate($candidates, $url, null, $baseUrl);
            }
        }

        return array_values($candidates);
    }

    private function addCandidate(array &$candidates, string $url, ?string $title, string $sourcePage): void
    {
        $url = $this->normalizeUrl(html_entity_decode(trim($url)));
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
        return explode('#', trim($url), 2)[0];
    }

    private function resolveUrl(string $base, string $href): string
    {
        try {
            return (string) UriResolver::resolve(new Uri($base), new Uri(trim($href)))->withFragment('');
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Источник содержит некорректный URL.', 0, $exception);
        }
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

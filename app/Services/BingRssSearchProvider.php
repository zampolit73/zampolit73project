<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class BingRssSearchProvider
{
    /**
     * @return array<int, array{title:string,url:string,snippet:string,published_at:?string}>
     */
    public function search(string $query, int $maxResults = 8): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; Zampolit73VacancyResearch/1.0)',
                'Accept' => 'application/rss+xml, application/xml, text/xml;q=0.9, */*;q=0.5',
            ])
                ->connectTimeout(5)
                ->timeout(12)
                ->get('https://www.bing.com/search', [
                    'q' => $query,
                    'format' => 'rss',
                    'mkt' => 'ru-RU',
                    'setlang' => 'ru-RU',
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Bing RSS connection failed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Bing RSS returned HTTP '.$response->status().'.');
        }

        $body = trim($response->body());

        if ($body === '') {
            throw new RuntimeException('Bing RSS returned an empty response.');
        }

        $xml = $this->parseXml($body);
        $items = $xml->channel->item ?? [];
        $results = [];
        $seen = [];

        foreach ($items as $item) {
            $title = $this->clean((string) ($item->title ?? ''));
            $url = trim((string) ($item->link ?? ''));
            $snippet = $this->clean((string) ($item->description ?? ''));
            $publishedAt = trim((string) ($item->pubDate ?? ''));

            if (! filter_var($url, FILTER_VALIDATE_URL)) {
                continue;
            }

            $key = mb_strtolower(rtrim($url, '/'));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $results[] = [
                'title' => $title,
                'url' => $url,
                'snippet' => $snippet,
                'published_at' => $publishedAt !== '' ? $publishedAt : null,
            ];

            if (count($results) >= max(1, min($maxResults, 12))) {
                break;
            }
        }

        return $results;
    }

    private function parseXml(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

            if (! $xml instanceof SimpleXMLElement) {
                throw new RuntimeException('Bing RSS returned invalid XML.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
    }
}

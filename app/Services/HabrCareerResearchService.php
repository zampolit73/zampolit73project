<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class HabrCareerResearchService
{
    private const INTERMEDIARY_MARKERS = [
        'recruit',
        'recruitment',
        'staffing',
        'outstaff',
        'outstaffing',
        'кадров',
        'рекрут',
        'подбор персонала',
        'аутстафф',
        'hr agency',
        'hr-агент',
    ];

    public function __construct(
        private readonly VacancySignalExtractor $extractor,
        private readonly BingRssSearchProvider $search,
    ) {
    }

    public function research(string $text): array
    {
        $signals = $this->extractor->extract($text);
        $queries = $this->buildQueries($signals);
        $discovered = [];
        $searchSuccesses = 0;
        $failures = [];

        foreach ($queries as $query) {
            try {
                $results = $this->search->search(
                    $query,
                    (int) config('vacancy_source.habr.max_results_per_query', 6),
                );
                $searchSuccesses++;
            } catch (Throwable $exception) {
                $failures[] = Str::limit($exception->getMessage(), 160);
                continue;
            }

            foreach ($results as $result) {
                if (! $this->isHabrVacancyUrl((string) ($result['url'] ?? ''))) {
                    continue;
                }

                $key = mb_strtolower(rtrim((string) $result['url'], '/'));
                $discovered[$key] ??= [
                    'url' => (string) $result['url'],
                    'search_query' => $query,
                ];
            }
        }

        $sources = [];
        $pageLimit = (int) config('vacancy_source.habr.max_pages', 5);

        foreach (array_slice(array_values($discovered), 0, max(1, $pageLimit)) as $item) {
            try {
                $page = $this->fetchVacancy($item['url']);
            } catch (Throwable $exception) {
                $failures[] = Str::limit($exception->getMessage(), 160);
                continue;
            }

            $evidence = $this->scoreSource($signals, $page['text']);

            if ($evidence['score'] < (int) config('vacancy_source.habr.minimum_source_score', 30)) {
                continue;
            }

            $sources[] = [
                'provider' => 'habr_career',
                'title' => $page['title'],
                'url' => $item['url'],
                'snippet' => Str::limit($page['description'], 1800, '…'),
                'published_at' => null,
                'search_query' => $item['search_query'],
                'evidence_score' => $evidence['score'],
                'phrase_hits' => $evidence['phrase_hits'],
                'technology_hits' => $evidence['technology_hits'],
                'role_hits' => $evidence['role_hits'],
                'reason' => $evidence['reason'],
                'candidate_name' => $page['company'],
                'candidate_is_intermediary' => $this->looksLikeIntermediary(
                    $page['company'].' '.$page['company_context'],
                ),
            ];
        }

        usort($sources, fn (array $a, array $b) => $b['evidence_score'] <=> $a['evidence_score']);
        $sources = array_slice(
            $sources,
            0,
            (int) config('vacancy_source.habr.max_saved_sources', 12),
        );

        return [
            'signals' => $signals,
            'queries' => $queries,
            'sources' => $sources,
            'candidates' => $this->buildCandidates($sources),
            'provider_successes' => $searchSuccesses,
            'provider_failures' => $failures,
            'partial' => $searchSuccesses === 0,
        ];
    }

    private function buildQueries(array $signals): array
    {
        $queries = [];
        $tech = implode(' ', array_slice($signals['technologies'], 0, 5));
        $roles = implode(' ', array_slice($signals['role_terms'], 0, 2));

        foreach (array_slice($signals['phrases'], 0, 2) as $phrase) {
            $quoted = '"'.str_replace('"', '', Str::limit($phrase, 115, '')).'"';
            $queries[] = trim('site:career.habr.com/vacancies/ '.$quoted.' '.$tech);
        }

        if ($tech !== '' || $roles !== '') {
            $queries[] = trim('site:career.habr.com/vacancies/ '.$roles.' '.$tech);
        }

        if ($queries === []) {
            $tokens = array_slice(
                $this->extractor->significantTokens($signals['normalized_text']),
                0,
                8,
            );
            $queries[] = 'site:career.habr.com/vacancies/ '.implode(' ', $tokens);
        }

        return array_slice(
            array_values(array_unique(array_filter($queries))),
            0,
            (int) config('vacancy_source.habr.max_queries', 3),
        );
    }

    private function fetchVacancy(string $url): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; Zampolit73VacancyResearch/1.0)',
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.5',
            ])
                ->connectTimeout(5)
                ->timeout(12)
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Habr Career connection failed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('Habr Career returned HTTP '.$response->status().'.');
        }

        $html = $response->body();

        if (trim($html) === '') {
            throw new RuntimeException('Habr Career returned an empty page.');
        }

        return $this->parseVacancyHtml($html);
    }

    private function parseVacancyHtml(string $html): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument();
            $loaded = $document->loadHTML(
                '<?xml encoding="utf-8" ?>'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            if (! $loaded) {
                throw new RuntimeException('Habr Career returned invalid HTML.');
            }

            $xpath = new DOMXPath($document);
            $seoTitle = $this->nodeText($xpath->query('//title')->item(0));
            $title = $this->nodeText($xpath->query('//h1')->item(0));

            if ($title === '') {
                $title = preg_replace('/\s*[—-]\s*Хабр Карьера.*$/u', '', $seoTitle) ?: $seoTitle;
            }

            $companyNode = $xpath->query(
                '//a[contains(@href, "/companies/")][normalize-space(.) != ""][1]'
            )->item(0);
            $company = $this->companyFromSeoTitle($seoTitle);

            if ($company === null) {
                $company = $this->nodeText($companyNode);
            }

            if (! $company) {
                throw new RuntimeException('Habr Career vacancy employer was not found.');
            }

            $body = $this->nodeText($xpath->query('//body')->item(0));
            $description = $this->extractDescription($xpath) ?: $body;
            $companyContext = $this->companyContextFromNode($companyNode)
                ?: $this->extractCompanyContext($xpath);

            return [
                'title' => Str::limit($title ?: 'Вакансия на Хабр Карьере', 500, ''),
                'company' => Str::limit($company, 120, ''),
                'text' => $this->extractor->normalize($title.' '.$company.' '.$description),
                'description' => $description,
                'company_context' => $companyContext,
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function extractDescription(DOMXPath $xpath): string
    {
        $heading = $xpath->query(
            '//*[self::h2 or self::h3][contains(normalize-space(.), "Описание вакансии")]'
        )->item(0);

        if (! $heading) {
            return '';
        }

        $parts = [];
        $node = $heading->nextSibling;

        while ($node) {
            if (
                $node->nodeType === XML_ELEMENT_NODE
                && in_array(mb_strtolower($node->nodeName), ['h2', 'h3'], true)
            ) {
                break;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?: '');

            if ($text !== '') {
                $parts[] = $text;
            }

            $node = $node->nextSibling;
        }

        return trim(implode(' ', $parts));
    }

    private function companyContextFromNode(?\DOMNode $node): string
    {
        if (! $node) {
            return '';
        }

        $container = $node->parentNode?->parentNode ?? $node->parentNode;
        $text = $this->nodeText($container);

        return Str::limit($text, 700, '');
    }

    private function extractCompanyContext(DOMXPath $xpath): string
    {
        $heading = $xpath->query(
            '//*[self::h2 or self::h3][contains(normalize-space(.), "Компания")]'
        )->item(0);

        if (! $heading) {
            return '';
        }

        $parts = [];
        $node = $heading->nextSibling;
        $steps = 0;

        while ($node && $steps < 8) {
            if (
                $node->nodeType === XML_ELEMENT_NODE
                && in_array(mb_strtolower($node->nodeName), ['h2', 'h3'], true)
            ) {
                break;
            }

            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?: '');

            if ($text !== '') {
                $parts[] = $text;
            }

            $steps++;
            $node = $node->nextSibling;
        }

        return Str::limit(implode(' ', $parts), 700, '');
    }

    private function companyFromSeoTitle(string $title): ?string
    {
        if (! preg_match('/работа\s+в\s+компании\s+[«"“](.+?)[»"”]/iu', $title, $matches)) {
            return null;
        }

        $company = trim($matches[1]);

        return $company !== '' ? $company : null;
    }

    private function scoreSource(array $signals, string $pageText): array
    {
        $sourceSignals = $this->extractor->extract($pageText);
        $score = 0;
        $phraseHits = [];
        $technologyHits = array_values(array_intersect(
            $signals['technologies'],
            $sourceSignals['technologies'],
        ));
        $roleHits = array_values(array_intersect(
            $signals['role_terms'],
            $sourceSignals['role_terms'],
        ));

        foreach ($signals['phrases'] as $index => $phrase) {
            $normalizedPhrase = $this->extractor->normalize($phrase);

            if (mb_strlen($normalizedPhrase) >= 18 && str_contains($pageText, $normalizedPhrase)) {
                $phraseHits[] = $phrase;
                $score += $index === 0
                    ? (int) config('vacancy_source.scoring.primary_phrase', 34)
                    : (int) config('vacancy_source.scoring.secondary_phrase', 22);
            }
        }

        $score += min(
            (int) config('vacancy_source.scoring.technology_cap', 28),
            count($technologyHits) * (int) config('vacancy_source.scoring.technology_each', 7),
        );
        $score += min(
            (int) config('vacancy_source.scoring.role_cap', 12),
            count($roleHits) * (int) config('vacancy_source.scoring.role_each', 6),
        );

        $inputTokens = $this->extractor->significantTokens($signals['normalized_text']);
        $sourceTokens = $this->extractor->significantTokens($pageText);
        $common = array_intersect($inputTokens, $sourceTokens);
        $ratio = count($inputTokens) > 0 ? count($common) / count($inputTokens) : 0;

        $score += min(
            (int) config('vacancy_source.scoring.token_overlap_cap', 18),
            (int) round($ratio * (int) config('vacancy_source.scoring.token_overlap_multiplier', 70)),
        );

        $score = min(95, $score);
        $reason = [];

        if ($phraseHits !== []) {
            $reason[] = count($phraseHits).' точн. редк. фраз';
        }

        if ($technologyHits !== []) {
            $reason[] = 'стек: '.implode(', ', array_slice($technologyHits, 0, 5));
        }

        if ($roleHits !== []) {
            $reason[] = 'роль: '.implode(', ', $roleHits);
        }

        if ($reason === []) {
            $reason[] = 'текстовое сходство';
        }

        return [
            'score' => $score,
            'phrase_hits' => $phraseHits,
            'technology_hits' => $technologyHits,
            'role_hits' => $roleHits,
            'reason' => implode('; ', $reason),
        ];
    }

    private function buildCandidates(array $sources): array
    {
        $groups = [];

        foreach ($sources as $source) {
            $company = $source['candidate_name'];

            if (! $company || $source['evidence_score'] < 40) {
                continue;
            }

            $key = $this->companyKey($company);
            $groups[$key]['company_name'] ??= $company;
            $groups[$key]['sources'][] = $source;
        }

        $candidates = [];

        foreach ($groups as $group) {
            $sources = $group['sources'];
            usort($sources, fn (array $a, array $b) => $b['evidence_score'] <=> $a['evidence_score']);
            $best = $sources[0];
            $confidence = min(95, (int) $best['evidence_score']);

            if ($confidence < (int) config('vacancy_source.minimum_confidence', 60)) {
                continue;
            }

            $isEndClient = ! $best['candidate_is_intermediary'];

            $candidates[] = [
                'company_name' => $group['company_name'],
                'candidate_type' => count($best['phrase_hits']) > 0 && $best['evidence_score'] >= 78
                    ? 'direct'
                    : 'indirect',
                'confidence' => $confidence,
                'is_end_client' => $isEndClient,
                'explanation' => 'Хабр Карьера '.$best['evidence_score'].'/100: '.$best['reason']
                    .'. Работодатель взят из структурированной страницы вакансии.'
                    .($isEndClient ? '' : ' Профиль работодателя похож на рекрутингового/аутстафф-посредника.'),
                'source_urls' => array_values(array_unique(array_column(array_slice($sources, 0, 3), 'url'))),
                'providers' => ['habr_career'],
            ];
        }

        usort($candidates, fn (array $a, array $b) => $b['confidence'] <=> $a['confidence']);

        return $candidates;
    }

    private function isHabrVacancyUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $host === 'career.habr.com'
            && preg_match('#^/vacancies/\d+/?$#', $path) === 1;
    }

    private function looksLikeIntermediary(string $value): bool
    {
        $normalized = $this->extractor->normalize($value);

        foreach (self::INTERMEDIARY_MARKERS as $marker) {
            if (str_contains($normalized, $this->extractor->normalize($marker))) {
                return true;
            }
        }

        return false;
    }

    private function companyKey(string $company): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $this->extractor->normalize($company)) ?: $company;
    }

    private function nodeText(?\DOMNode $node): string
    {
        if (! $node) {
            return '';
        }

        return trim(preg_replace('/\s+/u', ' ', $node->textContent ?? '') ?: '');
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Str;
use Throwable;

class VacancyWebResearchService
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
        'hr agency',
        'hr-агент',
    ];

    private const KNOWN_JOB_DOMAINS = [
        'hh.ru',
        'career.habr.com',
        'geekjob.ru',
        'superjob.ru',
        'linkedin.com',
        'indeed.com',
        'glassdoor.com',
        'zarplata.ru',
        'rabota.ru',
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
        $sourcesByUrl = [];
        $successes = 0;
        $failures = [];

        foreach ($queries as $query) {
            try {
                $results = $this->search->search($query, (int) config('vacancy_source.web.max_results_per_query', 8));
                $successes++;
            } catch (Throwable $exception) {
                $failures[] = Str::limit($exception->getMessage(), 160);
                continue;
            }

            foreach ($results as $result) {
                $urlKey = mb_strtolower(rtrim($result['url'], '/'));
                $evidence = $this->scoreSource($signals, $result);
                $company = $this->inferCompany($signals, $result);

                $source = [
                    'provider' => 'bing_rss',
                    'title' => $result['title'],
                    'url' => $result['url'],
                    'snippet' => $result['snippet'],
                    'published_at' => $result['published_at'],
                    'search_query' => $query,
                    'evidence_score' => $evidence['score'],
                    'phrase_hits' => $evidence['phrase_hits'],
                    'technology_hits' => $evidence['technology_hits'],
                    'role_hits' => $evidence['role_hits'],
                    'reason' => $evidence['reason'],
                    'candidate_name' => $company,
                ];

                if (! isset($sourcesByUrl[$urlKey]) || $source['evidence_score'] > $sourcesByUrl[$urlKey]['evidence_score']) {
                    $sourcesByUrl[$urlKey] = $source;
                }
            }
        }

        $sources = array_values($sourcesByUrl);
        usort($sources, fn (array $a, array $b) => $b['evidence_score'] <=> $a['evidence_score']);
        $sources = array_values(array_filter(
            array_slice($sources, 0, (int) config('vacancy_source.web.max_saved_sources', 18)),
            fn (array $source) => $source['evidence_score'] >= 18,
        ));

        $candidates = $this->buildCandidates($signals, $sources);
        $summary = $this->buildSummary($signals, $candidates, $sources, $successes, $failures);

        return [
            'signals' => $signals,
            'queries' => $queries,
            'sources' => $sources,
            'candidates' => $candidates,
            'summary' => $summary,
            'provider_successes' => $successes,
            'provider_failures' => $failures,
            'partial' => $successes === 0,
        ];
    }

    private function buildQueries(array $signals): array
    {
        $queries = [];
        $phrases = $signals['phrases'];
        $technologies = array_slice($signals['technologies'], 0, 4);
        $roles = array_slice($signals['role_terms'], 0, 2);
        $techTail = implode(' ', $technologies);
        $roleTail = implode(' ', $roles);

        foreach (array_slice($phrases, 0, 2) as $phrase) {
            $quoted = '"'.str_replace('"', '', Str::limit($phrase, 115, '')).'"';
            $queries[] = trim($quoted.' '.$techTail);
        }

        if ($phrases !== []) {
            $quoted = '"'.str_replace('"', '', Str::limit($phrases[0], 105, '')).'"';
            $queries[] = 'site:hh.ru/vacancy '.$quoted;
            $queries[] = 'site:career.habr.com/vacancies '.$quoted;
        }

        if ($technologies !== [] || $roles !== []) {
            $queries[] = trim('вакансия '.$roleTail.' '.$techTail);
            $queries[] = trim('job '.$this->englishRoles($roles).' '.$techTail);
        }

        if ($signals['explicit_company']) {
            $queries[] = '"'.$signals['explicit_company'].'" '.($phrases[0] ?? $techTail);
        }

        $queries = array_values(array_unique(array_filter(
            array_map(fn (string $query) => trim(Str::limit($query, 260, '')), $queries),
        )));

        if ($queries === []) {
            $tokens = array_slice($this->extractor->significantTokens($signals['normalized_text']), 0, 7);
            $queries[] = 'вакансия '.implode(' ', $tokens);
        }

        return array_slice($queries, 0, (int) config('vacancy_source.web.max_queries', 6));
    }

    private function scoreSource(array $signals, array $result): array
    {
        $haystack = $this->extractor->normalize(($result['title'] ?? '').' '.($result['snippet'] ?? ''));
        $sourceSignals = $this->extractor->extract($haystack);
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

            if (mb_strlen($normalizedPhrase) >= 18 && str_contains($haystack, $normalizedPhrase)) {
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
        $sourceTokens = $this->extractor->significantTokens($haystack);
        $common = array_intersect($inputTokens, $sourceTokens);
        $tokenRatio = count($inputTokens) > 0 ? count($common) / count($inputTokens) : 0;
        $score += min(
            (int) config('vacancy_source.scoring.token_overlap_cap', 18),
            (int) round($tokenRatio * (int) config('vacancy_source.scoring.token_overlap_multiplier', 70)),
        );

        if (
            $signals['explicit_company']
            && str_contains($haystack, $this->extractor->normalize($signals['explicit_company']))
        ) {
            $score += (int) config('vacancy_source.scoring.explicit_company_source', 14);
        }

        $score = min(95, $score);

        $reasonParts = [];

        if ($phraseHits !== []) {
            $reasonParts[] = count($phraseHits).' точн. редк. фраз';
        }

        if ($technologyHits !== []) {
            $reasonParts[] = 'стек: '.implode(', ', array_slice($technologyHits, 0, 5));
        }

        if ($roleHits !== []) {
            $reasonParts[] = 'роль: '.implode(', ', $roleHits);
        }

        if ($reasonParts === []) {
            $reasonParts[] = 'только слабое текстовое сходство';
        }

        return [
            'score' => $score,
            'phrase_hits' => $phraseHits,
            'technology_hits' => $technologyHits,
            'role_hits' => $roleHits,
            'reason' => implode('; ', $reasonParts),
        ];
    }

    private function inferCompany(array $signals, array $result): ?string
    {
        $title = trim((string) ($result['title'] ?? ''));
        $snippet = trim((string) ($result['snippet'] ?? ''));
        $combined = $title.' '.$snippet;

        if ($signals['explicit_company']) {
            $needle = $this->extractor->normalize($signals['explicit_company']);

            if (str_contains($this->extractor->normalize($combined), $needle)) {
                return $signals['explicit_company'];
            }
        }

        if (preg_match(
            '/(?:работа\\s+)?в\\s+компании\\s+[«"“]?([^|—,]{2,80}?)[»"”]?\\s*$/iu',
            $title,
            $matches,
        )) {
            $company = $this->cleanCompany($matches[1]);

            if ($company !== null) {
                return $company;
            }
        }

        $patterns = [
            '/работа\s+в\s+компании\s+[«"“]?(.+?)[»"”]?(?:\s*[|—]|,\s*(?:москва|санкт|россия)|$)/iu',
            '/в\s+компании\s+[«"“]?(.+?)[»"”]?(?:\s*[|—]|,|$)/iu',
            '/компания\s+[«"“]?(.+?)[»"”]?\s+(?:ищет|приглашает|предлагает|открыла)/iu',
            '/(?:company|employer)\s*[:\-]\s*([A-ZА-ЯЁ0-9][^|,;]{1,70})/u',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $combined, $matches)) {
                continue;
            }

            $company = $this->cleanCompany($matches[1]);

            if ($company !== null) {
                return $company;
            }
        }

        $host = parse_url((string) ($result['url'] ?? ''), PHP_URL_HOST);
        $host = is_string($host) ? mb_strtolower(preg_replace('/^www\./', '', $host) ?: $host) : '';

        if ($host !== '' && ! $this->isKnownJobDomain($host)) {
            $parts = explode('.', $host);
            $label = $parts[count($parts) >= 2 ? count($parts) - 2 : 0] ?? '';

            if (mb_strlen($label) >= 3 && ! in_array($label, ['jobs', 'career', 'careers', 'vacancy', 'vacancies'], true)) {
                return Str::headline(str_replace(['-', '_'], ' ', $label));
            }
        }

        return null;
    }

    private function buildCandidates(array $signals, array $sources): array
    {
        $groups = [];

        foreach ($sources as $source) {
            $company = $source['candidate_name'];

            if (! $company || $source['evidence_score'] < 28) {
                continue;
            }

            $key = $this->companyKey($company);
            $groups[$key]['company_name'] ??= $company;
            $groups[$key]['sources'][] = $source;
        }

        $candidates = [];

        foreach ($groups as $group) {
            $company = $group['company_name'];
            $candidateSources = $group['sources'];
            usort($candidateSources, fn (array $a, array $b) => $b['evidence_score'] <=> $a['evidence_score']);

            $best = $candidateSources[0];
            $domains = array_values(array_unique(array_filter(array_map(
                fn (array $source) => parse_url($source['url'], PHP_URL_HOST),
                $candidateSources,
            ))));

            $confidence = $best['evidence_score'];
            $confidence += min(
                (int) config('vacancy_source.scoring.corroboration_cap', 14),
                max(0, count($domains) - 1)
                    * (int) config('vacancy_source.scoring.corroborating_domain_each', 7),
            );

            if (
                $signals['explicit_company']
                && $this->companyKey($signals['explicit_company']) === $this->companyKey($company)
            ) {
                $confidence += (int) config('vacancy_source.scoring.explicit_company_candidate', 8);
            }

            $confidence = min(95, $confidence);
            $isEndClient = ! $this->looksLikeIntermediary($company);
            $candidateType = count($best['phrase_hits']) > 0 && $best['evidence_score'] >= 78
                ? 'direct'
                : 'indirect';

            if ($confidence < (int) config('vacancy_source.minimum_confidence', 60)) {
                continue;
            }

            $explanation = 'Сильнейший источник '.$best['evidence_score'].'/100: '.$best['reason'].'.';

            if (count($domains) > 1) {
                $explanation .= ' Подтверждение на '.count($domains).' разных доменах.';
            }

            if ($signals['explicit_company'] && $this->companyKey($signals['explicit_company']) === $this->companyKey($company)) {
                $explanation .= ' Название компании присутствует в исходном тексте.';
            }

            if (! $isEndClient) {
                $explanation .= ' Название похоже на рекрутингового/аутстафф-посредника, поэтому не считаю его конечным клиентом.';
            }

            $candidates[] = [
                'company_name' => $company,
                'candidate_type' => $candidateType,
                'confidence' => $confidence,
                'is_end_client' => $isEndClient,
                'explanation' => $explanation,
                'source_urls' => array_values(array_unique(array_column(array_slice($candidateSources, 0, 3), 'url'))),
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a['is_end_client'] !== $b['is_end_client']) {
                return $a['is_end_client'] ? -1 : 1;
            }

            return $b['confidence'] <=> $a['confidence'];
        });

        $endClients = array_values(array_filter($candidates, fn (array $candidate) => $candidate['is_end_client']));
        $intermediaries = array_values(array_filter($candidates, fn (array $candidate) => ! $candidate['is_end_client']));

        return array_merge(array_slice($endClients, 0, 3), array_slice($intermediaries, 0, 3));
    }

    private function buildSummary(
        array $signals,
        array $candidates,
        array $sources,
        int $successes,
        array $failures,
    ): string {
        if ($successes === 0) {
            return 'Веб-поиск сейчас недоступен: все поисковые запросы завершились ошибкой. Я не выдаю клиента без проверяемых источников.';
        }

        $endClients = array_values(array_filter($candidates, fn (array $candidate) => $candidate['is_end_client']));
        $intermediaries = array_values(array_filter($candidates, fn (array $candidate) => ! $candidate['is_end_client']));

        if ($endClients !== []) {
            $best = $endClients[0];
            $label = $best['candidate_type'] === 'direct' ? 'прямое совпадение' : 'косвенная гипотеза';

            $summary = 'Вероятный конечный клиент: '.$best['company_name'].' — '.$best['confidence'].'% ('.$label.').';

            if (count($endClients) > 1) {
                $summary .= ' Есть ещё '.(count($endClients) - 1).' кандидат(а) выше порога '.config('vacancy_source.minimum_confidence', 60).'%.';
            }

            if ($intermediaries !== []) {
                $summary .= ' Посредники вынесены отдельно.';
            }

            return $summary.' Telegram-корпус пока не подключён, поэтому это web-only результат.';
        }

        if ($intermediaries !== []) {
            return 'Надёжный конечный клиент не определён. Найден вероятный посредник: '.$intermediaries[0]['company_name'].' ('.$intermediaries[0]['confidence'].'%). Посредник не занимает слот конечного клиента. Telegram-корпус пока не подключён.';
        }

        $signalCount = count($signals['phrases']) + count($signals['technologies']);

        return 'Надёжный конечный клиент не определён. Проверено '.count($sources).' релевантных веб-источников по '.$signalCount.' сигналам, но ни один кандидат не набрал порог '.config('vacancy_source.minimum_confidence', 60).'%. Telegram-корпус пока не подключён.';
    }

    private function cleanCompany(string $value): ?string
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim($value, " \t\n\r\0\x0B«»“”\"'");
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
        $value = preg_replace('/\s+(?:на hh\.ru|в москве|в санкт-петербурге)$/iu', '', $value) ?: $value;

        if (
            mb_strlen($value) < 2
            || mb_strlen($value) > 80
            || preg_match('/^(москв|росси|санкт|удален|remote|ваканси|работа)/iu', $value)
        ) {
            return null;
        }

        return Str::limit($value, 80, '');
    }

    private function looksLikeIntermediary(string $company): bool
    {
        $normalized = $this->extractor->normalize($company);

        foreach (self::INTERMEDIARY_MARKERS as $marker) {
            if (str_contains($normalized, $this->extractor->normalize($marker))) {
                return true;
            }
        }

        return false;
    }

    private function isKnownJobDomain(string $host): bool
    {
        foreach (self::KNOWN_JOB_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function companyKey(string $company): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $this->extractor->normalize($company)) ?: $company;
    }

    private function englishRoles(array $roles): string
    {
        $map = [
            'frontend' => 'frontend',
            'backend' => 'backend',
            'fullstack' => 'fullstack',
            'developer' => 'developer',
            'architect' => 'architect',
            'analyst' => 'analyst',
            'qa' => 'QA',
            'devops' => 'DevOps',
            'data' => 'data engineer',
        ];

        return implode(' ', array_map(fn (string $role) => $map[$role] ?? $role, $roles));
    }
}

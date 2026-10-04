<?php

namespace App\Services;

use Illuminate\Support\Str;
use Throwable;

class VacancyTelegramResearchService
{
    private const INTERMEDIARY_MARKERS = [
        'recruit', 'recruitment', 'staffing', 'outstaff', 'outstaffing',
        'кадров', 'рекрут', 'подбор персонала', 'hr agency', 'hr-агент',
    ];

    public function __construct(
        private readonly VacancySignalExtractor $extractor,
        private readonly TelegramReaderClient $reader,
    ) {
    }

    public function research(string $text): array
    {
        $signals = $this->extractor->extract($text);
        $query = $this->buildQuery($signals);
        $failures = [];

        try {
            $hits = $this->reader->search(
                $query,
                (int) config('vacancy_source.telegram.max_hits', 40),
            );
        } catch (Throwable $exception) {
            $failures[] = Str::limit($exception->getMessage(), 160);

            return [
                'signals' => $signals,
                'query' => $query,
                'sources' => [],
                'candidates' => [],
                'provider_successes' => 0,
                'provider_failures' => $failures,
                'partial' => true,
            ];
        }

        $clusters = [];

        foreach ($hits as $hit) {
            $normalized = $this->extractor->normalize((string) ($hit['text'] ?? ''));

            if ($normalized === '') {
                continue;
            }

            $fingerprint = hash('sha256', $normalized);
            $evidence = $this->scoreHit($signals, $normalized);

            if ($evidence['score'] < (int) config('vacancy_source.telegram.minimum_source_score', 28)) {
                continue;
            }

            $source = [
                'provider' => 'telegram_reader',
                'title' => 'Telegram · '.Str::limit((string) ($hit['chat_title'] ?? 'рабочий чат'), 120, '…'),
                'url' => $this->sourceUrl($hit),
                'snippet' => Str::limit((string) ($hit['text'] ?? ''), 1800, '…'),
                'search_query' => $query,
                'evidence_score' => $evidence['score'],
                'phrase_hits' => $evidence['phrase_hits'],
                'technology_hits' => $evidence['technology_hits'],
                'role_hits' => $evidence['role_hits'],
                'reason' => $evidence['reason'],
                'candidate_name' => $this->inferCompany($signals, (string) ($hit['text'] ?? '')),
                'telegram_peer_id' => (int) ($hit['peer_id'] ?? 0),
                'telegram_message_id' => (int) ($hit['message_id'] ?? 0),
                'telegram_message_date' => $hit['message_date'] ?? null,
                'cluster_fingerprint' => $fingerprint,
            ];

            if (
                ! isset($clusters[$fingerprint])
                || $source['evidence_score'] > $clusters[$fingerprint]['evidence_score']
            ) {
                $clusters[$fingerprint] = $source;
            }
        }

        $candidateSources = array_values($clusters);
        usort($candidateSources, fn (array $a, array $b) => $b['evidence_score'] <=> $a['evidence_score']);

        $sources = $this->collapseSourcesForDisplay($candidateSources);
        $sources = array_slice(
            $sources,
            0,
            (int) config('vacancy_source.telegram.max_saved_sources', 8),
        );

        return [
            'signals' => $signals,
            'query' => $query,
            'sources' => $sources,
            'candidates' => $this->buildCandidates($candidateSources),
            'provider_successes' => 1,
            'provider_failures' => [],
            'partial' => false,
        ];
    }

    private function buildQuery(array $signals): string
    {
        $parts = [
            ...array_slice($signals['phrases'], 0, 2),
            ...array_slice($signals['technologies'], 0, 5),
            ...array_slice($signals['role_terms'], 0, 2),
        ];

        if ($parts === []) {
            $parts = array_slice(
                $this->extractor->significantTokens($signals['normalized_text']),
                0,
                10,
            );
        }

        return Str::limit(implode(' ', array_unique($parts)), 480, '');
    }

    private function scoreHit(array $signals, string $normalizedText): array
    {
        $sourceSignals = $this->extractor->extract($normalizedText);
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
            $needle = $this->extractor->normalize($phrase);

            if (mb_strlen($needle) >= 18 && str_contains($normalizedText, $needle)) {
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
        $sourceTokens = $this->extractor->significantTokens($normalizedText);
        $common = array_intersect($inputTokens, $sourceTokens);
        $ratio = count($inputTokens) > 0 ? count($common) / count($inputTokens) : 0;
        $score += min(
            (int) config('vacancy_source.scoring.token_overlap_cap', 18),
            (int) round($ratio * (int) config('vacancy_source.scoring.token_overlap_multiplier', 70)),
        );

        if (
            $signals['explicit_company']
            && str_contains($normalizedText, $this->extractor->normalize($signals['explicit_company']))
        ) {
            $score += (int) config('vacancy_source.scoring.explicit_company_source', 14);
        }

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
            $company = $group['company_name'];
            $candidateSources = $group['sources'];
            usort($candidateSources, fn (array $a, array $b) => $b['evidence_score'] <=> $a['evidence_score']);

            $best = $candidateSources[0];
            $confidence = min(95, (int) $best['evidence_score']);

            if ($confidence < (int) config('vacancy_source.hypothesis_confidence', 40)) {
                continue;
            }

            $isEndClient = ! $this->looksLikeIntermediary($company);

            $candidates[] = [
                'company_name' => $company,
                'candidate_type' => count($best['phrase_hits']) > 0 && $best['evidence_score'] >= 78
                    ? 'direct'
                    : 'indirect',
                'confidence' => $confidence,
                'is_end_client' => $isEndClient,
                'explanation' => 'Сильнейшее совпадение в Telegram '.$best['evidence_score'].'/100: '.$best['reason'].'.'
                    .($isEndClient ? '' : ' Название похоже на рекрутингового/аутстафф-посредника.'),
                'source_urls' => array_values(array_unique(array_column(array_slice($candidateSources, 0, 3), 'url'))),
                'providers' => ['telegram_reader'],
            ];
        }

        usort($candidates, fn (array $a, array $b) => $b['confidence'] <=> $a['confidence']);

        return $candidates;
    }

    private function collapseSourcesForDisplay(array $sources): array
    {
        $byChat = [];

        foreach ($sources as $source) {
            $chatKey = (string) ($source['telegram_peer_id'] ?? $source['title']);

            if (! isset($byChat[$chatKey])) {
                $source['telegram_match_count'] = 1;
                $byChat[$chatKey] = $source;
                continue;
            }

            $byChat[$chatKey]['telegram_match_count']++;

            if ($source['evidence_score'] > $byChat[$chatKey]['evidence_score']) {
                $count = $byChat[$chatKey]['telegram_match_count'];
                $source['telegram_match_count'] = $count;
                $byChat[$chatKey] = $source;
            }
        }

        $result = array_values($byChat);

        foreach ($result as &$source) {
            $count = (int) ($source['telegram_match_count'] ?? 1);

            if ($count > 1) {
                $source['title'] .= ' · '.$count.' совпадений';
            }
        }
        unset($source);

        usort($result, fn (array $a, array $b) => $b['evidence_score'] <=> $a['evidence_score']);

        return $result;
    }

    private function inferCompany(array $signals, string $text): ?string
    {
        $normalized = $this->extractor->normalize($text);

        if (
            $signals['explicit_company']
            && str_contains($normalized, $this->extractor->normalize($signals['explicit_company']))
        ) {
            return $signals['explicit_company'];
        }

        $patterns = [
            '/(?:заказчик|клиент|работодатель)\s*[:—-]\s*[«"“]?([^\n,;|]{2,80})/iu',
            '/компания\s*[:—-]\s*[«"“]?([^\n,;|]{2,80})/iu',
            '/(?:для|в)\s+компани[ию]\s+[«"“]?([^\n,;|]{2,80})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $text, $matches)) {
                continue;
            }

            $company = $this->cleanCompany($matches[1]);

            if ($company !== null) {
                return $company;
            }
        }

        return null;
    }

    private function cleanCompany(string $value): ?string
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = trim($value, " \t\n\r\0\x0B«»“”\"'");
        $value = preg_replace('/\s+/u', ' ', $value) ?: $value;

        if (
            mb_strlen($value) < 2
            || mb_strlen($value) > 80
            || preg_match('/^(ваканси|проект|команд|удален|remote)/iu', $value)
        ) {
            return null;
        }

        return Str::limit($value, 80, '');
    }

    private function sourceUrl(array $hit): string
    {
        $source = trim((string) ($hit['source_link'] ?? ''));

        if ($source !== '') {
            return $source;
        }

        return 'telegram://message/'
            .(int) ($hit['peer_id'] ?? 0)
            .'/'
            .(int) ($hit['message_id'] ?? 0);
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

    private function companyKey(string $company): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $this->extractor->normalize($company)) ?: $company;
    }
}

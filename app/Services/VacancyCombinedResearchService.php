<?php

namespace App\Services;

class VacancyCombinedResearchService
{
    public function __construct(
        private readonly VacancyWebResearchService $web,
        private readonly VacancyTelegramResearchService $telegram,
        private readonly VacancySignalExtractor $extractor,
    ) {
    }

    public function research(string $text): array
    {
        $telegram = $this->telegram->research($text);
        $web = $this->web->research($text);

        $sources = [...$web['sources'], ...$telegram['sources']];
        usort($sources, fn (array $a, array $b) => $b['evidence_score'] <=> $a['evidence_score']);
        $sources = array_slice(
            $sources,
            0,
            (int) config('vacancy_source.max_saved_sources', 28),
        );

        $candidates = $this->mergeCandidates(
            $web['candidates'],
            $telegram['candidates'],
            $sources,
        );

        return [
            'signals' => $web['signals'] ?? $telegram['signals'],
            'queries' => $web['queries'] ?? [],
            'telegram_query' => $telegram['query'] ?? null,
            'sources' => $sources,
            'candidates' => $candidates,
            'summary' => $this->buildSummary($candidates, $sources, $web, $telegram),
            'provider_successes' => (int) ($web['provider_successes'] ?? 0)
                + (int) ($telegram['provider_successes'] ?? 0),
            'provider_failures' => [
                ...($web['provider_failures'] ?? []),
                ...($telegram['provider_failures'] ?? []),
            ],
            'partial' => (bool) ($web['partial'] ?? true) && (bool) ($telegram['partial'] ?? true),
        ];
    }

    private function mergeCandidates(array $webCandidates, array $telegramCandidates, array $sources): array
    {
        $groups = [];

        foreach ($webCandidates as $candidate) {
            $this->pushCandidate($groups, $candidate, 'bing_rss');
        }

        foreach ($telegramCandidates as $candidate) {
            $this->pushCandidate($groups, $candidate, 'telegram_reader');
        }

        $result = [];

        foreach ($groups as $key => $group) {
            $providerSet = array_values(array_unique($group['providers']));
            $best = $group['candidates'][0];

            foreach ($group['candidates'] as $candidate) {
                if ($candidate['confidence'] > $best['confidence']) {
                    $best = $candidate;
                }
            }

            $confidence = (int) $best['confidence'];

            if (count($providerSet) > 1) {
                $confidence += (int) config('vacancy_source.scoring.cross_provider_corroboration', 10);
            }

            $confidence = min(95, $confidence);

            if ($confidence < (int) config('vacancy_source.minimum_confidence', 60)) {
                continue;
            }

            $matchingSources = array_values(array_filter(
                $sources,
                fn (array $source) => $source['candidate_name']
                    && $this->companyKey($source['candidate_name']) === $key,
            ));

            $providersLabel = [];
            if (in_array('bing_rss', $providerSet, true)) {
                $providersLabel[] = 'web';
            }
            if (in_array('telegram_reader', $providerSet, true)) {
                $providersLabel[] = 'Telegram';
            }

            $explanation = $best['explanation'];
            if (count($providerSet) > 1) {
                $explanation .= ' Независимое подтверждение: web + Telegram.';
            } elseif ($providerSet === ['telegram_reader']) {
                $explanation .= ' Подтверждение только Telegram; независимого веб-подтверждения нет.';
            } elseif ($providerSet === ['bing_rss']) {
                $explanation .= ' Telegram-корпус не дал независимого подтверждения этому кандидату.';
            }

            $result[] = [
                'company_name' => $group['company_name'],
                'candidate_type' => in_array('direct', array_column($group['candidates'], 'candidate_type'), true)
                    ? 'direct'
                    : 'indirect',
                'confidence' => $confidence,
                'is_end_client' => ! in_array(false, array_column($group['candidates'], 'is_end_client'), true),
                'explanation' => $explanation,
                'source_urls' => array_values(array_unique(array_column(array_slice($matchingSources, 0, 3), 'url'))),
                'providers' => $providerSet,
                'provider_label' => implode(' + ', $providersLabel),
            ];
        }

        usort($result, function (array $a, array $b): int {
            if ($a['is_end_client'] !== $b['is_end_client']) {
                return $a['is_end_client'] ? -1 : 1;
            }

            return $b['confidence'] <=> $a['confidence'];
        });

        $endClients = array_values(array_filter($result, fn (array $candidate) => $candidate['is_end_client']));
        $intermediaries = array_values(array_filter($result, fn (array $candidate) => ! $candidate['is_end_client']));

        return array_merge(array_slice($endClients, 0, 3), array_slice($intermediaries, 0, 3));
    }

    private function pushCandidate(array &$groups, array $candidate, string $provider): void
    {
        $key = $this->companyKey($candidate['company_name']);
        $groups[$key]['company_name'] ??= $candidate['company_name'];
        $groups[$key]['candidates'][] = $candidate;
        $groups[$key]['providers'][] = $provider;
    }

    private function buildSummary(array $candidates, array $sources, array $web, array $telegram): string
    {
        $endClients = array_values(array_filter($candidates, fn (array $candidate) => $candidate['is_end_client']));
        $webCount = count(array_filter($sources, fn (array $source) => $source['provider'] === 'bing_rss'));
        $telegramCount = count(array_filter($sources, fn (array $source) => $source['provider'] === 'telegram_reader'));

        if ($endClients !== []) {
            $best = $endClients[0];
            $providers = $best['providers'] ?? [];

            $summary = 'Вероятный конечный клиент: '.$best['company_name'].' — '.$best['confidence'].'%.';

            if (in_array('telegram_reader', $providers, true) && in_array('bing_rss', $providers, true)) {
                $summary .= ' Кандидат подтверждается независимо web и Telegram.';
            } elseif (in_array('telegram_reader', $providers, true)) {
                $summary .= ' Подтверждение найдено только в Telegram-корпусе; независимого web-подтверждения нет.';
            } else {
                $summary .= ' Подтверждение найдено в открытом web; Telegram независимого подтверждения не дал.';
            }

            return $summary.' Сильных источников: web '.$webCount.', Telegram '.$telegramCount.'.';
        }

        if (($web['partial'] ?? true) && ($telegram['partial'] ?? true)) {
            return 'Оба исследовательских источника сейчас недоступны. Клиент не определён без проверяемых доказательств.';
        }

        return 'Надёжный конечный клиент не определён. После дедупликации проверено сильных источников: web '
            .$webCount.', Telegram '.$telegramCount
            .'. Ни один кандидат не набрал порог '
            .config('vacancy_source.minimum_confidence', 60).'%.';
    }

    private function companyKey(string $company): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $this->extractor->normalize($company)) ?: $company;
    }
}

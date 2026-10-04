<?php

namespace App\Services;

class VacancyCombinedResearchService
{
    public function __construct(
        private readonly VacancyWebResearchService $web,
        private readonly HabrCareerResearchService $habr,
        private readonly VacancyTelegramResearchService $telegram,
        private readonly VacancySignalExtractor $extractor,
    ) {
    }

    public function research(string $text, ?callable $progress = null): array
    {
        $telegram = $this->telegram->research($text);

        if ($progress) {
            $progress(
                'habr_search',
                'Telegram-корпус проверен. Теперь ищу совпадения на Habr Career',
            );
        }

        $habr = $this->habr->research($text);

        if ($progress) {
            $progress(
                'web_search',
                'Habr Career проверен. Теперь проверяю остальной открытый web',
            );
        }

        $web = $this->web->research($text);

        $sources = [...$web['sources'], ...$habr['sources'], ...$telegram['sources']];
        usort($sources, fn (array $a, array $b) => $b['evidence_score'] <=> $a['evidence_score']);
        $sources = array_slice(
            $sources,
            0,
            (int) config('vacancy_source.max_saved_sources', 28),
        );

        $candidates = $this->mergeCandidates(
            $web['candidates'],
            $habr['candidates'],
            $telegram['candidates'],
            $sources,
        );

        return [
            'signals' => $web['signals'] ?? $telegram['signals'],
            'queries' => $web['queries'] ?? [],
            'telegram_query' => $telegram['query'] ?? null,
            'habr_queries' => $habr['queries'] ?? [],
            'sources' => $sources,
            'candidates' => $candidates,
            'summary' => $this->buildSummary($candidates, $sources, $web, $habr, $telegram),
            'provider_successes' => (int) ($web['provider_successes'] ?? 0)
                + (int) ($habr['provider_successes'] ?? 0)
                + (int) ($telegram['provider_successes'] ?? 0),
            'provider_failures' => [
                ...($web['provider_failures'] ?? []),
                ...($habr['provider_failures'] ?? []),
                ...($telegram['provider_failures'] ?? []),
            ],
            'partial' => (bool) ($web['partial'] ?? true)
                && (bool) ($habr['partial'] ?? true)
                && (bool) ($telegram['partial'] ?? true),
        ];
    }

    private function mergeCandidates(
        array $webCandidates,
        array $habrCandidates,
        array $telegramCandidates,
        array $sources,
    ): array
    {
        $groups = [];

        foreach ($webCandidates as $candidate) {
            $this->pushCandidate($groups, $candidate, 'bing_rss');
        }

        foreach ($habrCandidates as $candidate) {
            $this->pushCandidate($groups, $candidate, 'habr_career');
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

            if ($confidence < (int) config('vacancy_source.hypothesis_confidence', 40)) {
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
            if (in_array('habr_career', $providerSet, true)) {
                $providersLabel[] = 'Habr Career';
            }
            if (in_array('telegram_reader', $providerSet, true)) {
                $providersLabel[] = 'Telegram';
            }

            $explanation = $best['explanation'];
            if (count($providerSet) > 1) {
                $explanation .= ' Независимое подтверждение: '.implode(' + ', $providersLabel).'.';
            } elseif ($providerSet === ['telegram_reader']) {
                $explanation .= ' Подтверждение только Telegram; независимого web/Habr подтверждения нет.';
            } elseif ($providerSet === ['habr_career']) {
                $explanation .= ' Подтверждение найдено на Habr Career; других независимых источников пока нет.';
            } elseif ($providerSet === ['bing_rss']) {
                $explanation .= ' Habr Career и Telegram не дали независимого подтверждения этому кандидату.';
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

    private function buildSummary(
        array $candidates,
        array $sources,
        array $web,
        array $habr,
        array $telegram,
    ): string
    {
        $endClients = array_values(array_filter($candidates, fn (array $candidate) => $candidate['is_end_client']));
        $webCount = count(array_filter($sources, fn (array $source) => $source['provider'] === 'bing_rss'));
        $habrCount = count(array_filter($sources, fn (array $source) => $source['provider'] === 'habr_career'));
        $telegramCount = count(array_filter($sources, fn (array $source) => $source['provider'] === 'telegram_reader'));

        if ($endClients !== []) {
            $best = $endClients[0];
            $providers = $best['providers'] ?? [];
            $isStrong = $best['confidence'] >= (int) config('vacancy_source.minimum_confidence', 60);

            $summary = $isStrong
                ? 'Вероятный конечный клиент: '.$best['company_name'].' — '.$best['confidence'].'%.'
                : 'Надёжный конечный клиент пока не подтверждён. Лучшая гипотеза: '
                    .$best['company_name'].' — '.$best['confidence'].'%.';

            $labels = [];
            if (in_array('bing_rss', $providers, true)) {
                $labels[] = 'web';
            }
            if (in_array('habr_career', $providers, true)) {
                $labels[] = 'Habr Career';
            }
            if (in_array('telegram_reader', $providers, true)) {
                $labels[] = 'Telegram';
            }

            if (count($labels) > 1) {
                $summary .= ' Подтверждается независимо: '.implode(' + ', $labels).'.';
            } elseif ($labels !== []) {
                $summary .= ' Пока подтверждается только источником: '.$labels[0].'.';
            }

            if (! $isStrong) {
                $summary .= ' Нужен ещё хотя бы один независимый сигнал, чтобы поднять её выше порога '
                    .config('vacancy_source.minimum_confidence', 60).'%.';
            }

            return $summary;
        }

        if (($web['partial'] ?? true) && ($habr['partial'] ?? true) && ($telegram['partial'] ?? true)) {
            return 'Все исследовательские источники сейчас недоступны. Клиент не определён без проверяемых доказательств.';
        }

        return 'Надёжный конечный клиент не определён: ни один источник не позволил даже сформировать проверяемую гипотезу выше '
            .config('vacancy_source.hypothesis_confidence', 40).'%.';
    }

    private function companyKey(string $company): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $this->extractor->normalize($company)) ?: $company;
    }
}

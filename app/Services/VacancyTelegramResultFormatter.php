<?php

namespace App\Services;

use App\Models\VacancyInvestigation;
use Illuminate\Support\Str;

class VacancyTelegramResultFormatter
{
    public function format(VacancyInvestigation $investigation): string
    {
        if (! $investigation->relationLoaded('candidates')) {
            $investigation->load([
                'candidates' => fn ($query) => $query->orderBy('rank'),
                'sources' => fn ($query) => $query->orderByDesc('evidence_score'),
            ]);
        } elseif (! $investigation->relationLoaded('sources')) {
            $investigation->load([
                'sources' => fn ($query) => $query->orderByDesc('evidence_score'),
            ]);
        }

        $lines = [
            'Проверка #'.$investigation->id.' готова.',
            '',
            $investigation->result_summary ?: 'Результат сохранён в веб-истории.',
        ];

        $endClients = $investigation->candidates
            ->where('is_end_client', true)
            ->sortBy('rank')
            ->take(3)
            ->values();

        if ($endClients->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Кандидаты:';

            foreach ($endClients as $index => $candidate) {
                $type = $candidate->candidate_type === 'direct'
                    ? 'Прямое совпадение'
                    : 'Косвенная гипотеза';

                $lines[] = ($index + 1).'. '.$candidate->company_name.' — '.$candidate->confidence.'% · '.$type;
                $lines[] = Str::limit((string) $candidate->explanation, 420, '…');
            }
        }

        $intermediaries = $investigation->candidates
            ->where('is_end_client', false)
            ->sortByDesc('confidence')
            ->take(2)
            ->values();

        if ($intermediaries->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Вероятные посредники:';

            foreach ($intermediaries as $candidate) {
                $lines[] = '• '.$candidate->company_name.' — '.$candidate->confidence.'%';
            }
        }

        $topSources = $investigation->sources
            ->sortByDesc('evidence_score')
            ->take(3)
            ->values();

        if ($topSources->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Сильнейшие источники:';

            foreach ($topSources as $source) {
                $title = $source->title
                    ? Str::limit($source->title, 110, '…')
                    : (parse_url($source->url, PHP_URL_HOST) ?: 'Источник');

                $lines[] = '• '.$title;
                $lines[] = $source->url;
            }
        }

        return Str::limit(implode("\n", $lines), 4000, '…');
    }
}

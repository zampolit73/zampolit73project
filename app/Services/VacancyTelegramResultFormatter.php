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
            $strong = $endClients
                ->filter(fn ($candidate) => $candidate->confidence >= (int) config('vacancy_source.minimum_confidence', 60))
                ->values();
            $hypotheses = $endClients
                ->filter(fn ($candidate) => $candidate->confidence < (int) config('vacancy_source.minimum_confidence', 60))
                ->values();

            if ($strong->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'Вероятные заказчики:';

                foreach ($strong as $index => $candidate) {
                    $lines[] = ($index + 1).'. '.$candidate->company_name.' — '.$candidate->confidence.'%';
                    $lines[] = Str::limit((string) $candidate->explanation, 360, '…');
                }
            }

            if ($hypotheses->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'Гипотезы — пока недостаточно подтверждений:';

                foreach ($hypotheses as $index => $candidate) {
                    $lines[] = ($index + 1).'. '.$candidate->company_name.' — '.$candidate->confidence.'%';
                    $lines[] = Str::limit((string) $candidate->explanation, 320, '…');
                }
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
            ->groupBy('provider')
            ->flatMap(fn ($group) => $group->take($group->first()->provider === 'telegram_reader' ? 1 : 2))
            ->sortByDesc('evidence_score')
            ->take(5)
            ->values();

        if ($topSources->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Что подтверждает результат:';

            foreach ($topSources as $source) {
                $title = $source->title
                    ? Str::limit($source->title, 110, '…')
                    : (parse_url($source->url, PHP_URL_HOST) ?: 'Источник');
                $label = match ($source->provider) {
                    'telegram_reader' => 'Telegram',
                    'habr_career' => 'Habr Career',
                    default => 'Web',
                };

                $lines[] = '• ['.$label.'] '.$title;

                if (str_starts_with($source->url, 'http://') || str_starts_with($source->url, 'https://')) {
                    $lines[] = $source->url;
                }
            }
        }

        return Str::limit(implode("\n", $lines), 4000, '…');
    }
}

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
                    $lines[] = Str::limit((string) $candidate->explanation, 340, '…');
                    $this->appendCandidateEvidence($lines, $investigation, $candidate->id);
                }
            }

            if ($hypotheses->isNotEmpty()) {
                $lines[] = '';
                $lines[] = 'Гипотезы — пока недостаточно подтверждений:';

                foreach ($hypotheses as $index => $candidate) {
                    $lines[] = ($index + 1).'. '.$candidate->company_name.' — '.$candidate->confidence.'%';
                    $lines[] = Str::limit((string) $candidate->explanation, 300, '…');
                    $this->appendCandidateEvidence($lines, $investigation, $candidate->id);
                }
            }
        }

        $intermediaries = $investigation->candidates
            ->where('is_end_client', false)
            ->sortByDesc('confidence')
            ->take(3)
            ->values();

        if ($intermediaries->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Посредники / публикаторы:';

            foreach ($intermediaries as $candidate) {
                $lines[] = '• '.$candidate->company_name.' — '.$candidate->confidence.'%';
                $lines[] = Str::limit((string) $candidate->explanation, 260, '…');
                $this->appendCandidateEvidence($lines, $investigation, $candidate->id, 1);
            }
        }

        return Str::limit(implode("\n", $lines), 4000, '…');
    }

    private function appendCandidateEvidence(
        array &$lines,
        VacancyInvestigation $investigation,
        int $candidateId,
        int $limit = 2,
    ): void {
        $sources = $investigation->sources
            ->where('candidate_id', $candidateId)
            ->sortByDesc('evidence_score')
            ->take($limit)
            ->values();

        foreach ($sources as $source) {
            $label = match ($source->provider) {
                'telegram_reader' => 'Telegram',
                'habr_career' => 'Habr Career',
                default => 'Web',
            };
            $title = $source->title
                ? Str::limit($source->title, 95, '…')
                : (parse_url($source->url, PHP_URL_HOST) ?: 'Источник');

            $lines[] = '  ↳ ['.$label.'] '.$title;

            if (str_starts_with($source->url, 'http://') || str_starts_with($source->url, 'https://')) {
                $lines[] = '     '.$source->url;
            }
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\RunVacancyInvestigation;
use App\Models\VacancyInvestigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VacancySourceController extends Controller
{
    public function index(Request $request): Response
    {
        $history = $this->visibleInvestigations($request)
            ->with([
                'user:id,username',
                'candidates' => fn ($query) => $query->orderBy('rank'),
                'review',
            ])
            ->latest()
            ->limit(40)
            ->get()
            ->map(fn (VacancyInvestigation $investigation) => $this->serializeInvestigation($investigation))
            ->values();

        $active = null;
        $activeId = $request->integer('active');

        if ($activeId > 0) {
            $active = $this->visibleInvestigations($request)
                ->with([
                    'user:id,username',
                    'candidates' => fn ($query) => $query->orderBy('rank'),
                    'sources' => fn ($query) => $query->orderByDesc('evidence_score'),
                    'review',
                ])
                ->find($activeId);
        } else {
            $active = VacancyInvestigation::query()
                ->where('user_id', $request->user()->id)
                ->whereIn('status', ['queued', 'running'])
                ->with([
                    'user:id,username',
                    'candidates' => fn ($query) => $query->orderBy('rank'),
                    'sources' => fn ($query) => $query->orderByDesc('evidence_score'),
                    'review',
                ])
                ->latest()
                ->first();
        }

        return Inertia::render('VacancySource', [
            'history' => $history,
            'activeInvestigation' => $active ? $this->serializeInvestigation($active) : null,
            'isAdmin' => $request->user()->role === 'admin',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'input_text' => ['required', 'string', 'min:20', 'max:30000'],
        ], [
            'input_text.min' => 'Добавь чуть больше текста вакансии — минимум 20 символов.',
            'input_text.max' => 'Текст вакансии слишком большой для одной проверки.',
        ]);

        $investigation = VacancyInvestigation::query()->create([
            'user_id' => $request->user()->id,
            'input_source' => 'web',
            'input_text' => trim($data['input_text']),
            'status' => 'queued',
            'progress_stage' => 'queued',
            'progress_text' => 'Проверка поставлена в очередь',
            'queued_at' => now(),
        ]);

        RunVacancyInvestigation::dispatch($investigation->id);

        return redirect()->route('projects.vacancy-source', ['active' => $investigation->id]);
    }

    public function status(Request $request, int $investigation): JsonResponse
    {
        $record = $this->visibleInvestigations($request)
            ->with([
                'user:id,username',
                'candidates' => fn ($query) => $query->orderBy('rank'),
                'sources' => fn ($query) => $query->orderByDesc('evidence_score'),
                'review',
            ])
            ->findOrFail($investigation);

        return response()
            ->json($this->serializeInvestigation($record))
            ->header('Cache-Control', 'no-store');
    }

    public function cancel(Request $request, int $investigation): RedirectResponse
    {
        $record = $this->visibleInvestigations($request)->findOrFail($investigation);

        if ($record->status !== 'queued') {
            return back()->withErrors([
                'investigation' => 'Отменить можно только проверку, которая ещё ждёт в очереди.',
            ]);
        }

        $cancelled = VacancyInvestigation::query()
            ->whereKey($record->id)
            ->where('status', 'queued')
            ->update([
                'status' => 'cancelled',
                'progress_stage' => 'cancelled',
                'progress_text' => 'Проверка отменена до запуска',
                'cancelled_at' => now(),
                'finished_at' => now(),
            ]);

        if ($cancelled === 0) {
            return back()->withErrors([
                'investigation' => 'Проверка уже успела запуститься и больше не может быть отменена.',
            ]);
        }

        return redirect()->route('projects.vacancy-source', ['active' => $record->id]);
    }

    private function visibleInvestigations(Request $request): Builder
    {
        $query = VacancyInvestigation::query();

        if ($request->user()->role !== 'admin') {
            $query->where('user_id', $request->user()->id);
        }

        return $query;
    }

    private function serializeInvestigation(VacancyInvestigation $investigation): array
    {
        $bestCandidate = $investigation->relationLoaded('candidates')
            ? $investigation->candidates->where('is_end_client', true)->sortBy('rank')->first()
            : null;

        return [
            'id' => $investigation->id,
            'user' => $investigation->relationLoaded('user') && $investigation->user
                ? [
                    'id' => $investigation->user->id,
                    'username' => $investigation->user->username,
                ]
                : null,
            'input_source' => $investigation->input_source,
            'status' => $investigation->status,
            'progress_stage' => $investigation->progress_stage,
            'progress_text' => $investigation->progress_text,
            'result_summary' => $investigation->result_summary,
            'queued_at' => $investigation->queued_at?->toIso8601String(),
            'started_at' => $investigation->started_at?->toIso8601String(),
            'finished_at' => $investigation->finished_at?->toIso8601String(),
            'created_at' => $investigation->created_at?->toIso8601String(),
            'can_cancel' => $investigation->status === 'queued',
            'best_candidate' => $bestCandidate ? [
                'company_name' => $bestCandidate->company_name,
                'confidence' => $bestCandidate->confidence,
                'candidate_type' => $bestCandidate->candidate_type,
                'is_end_client' => $bestCandidate->is_end_client,
            ] : null,
            'candidates' => $investigation->relationLoaded('candidates')
                ? $investigation->candidates
                    ->sortBy('rank')
                    ->values()
                    ->map(fn ($candidate) => [
                        'id' => $candidate->id,
                        'company_name' => $candidate->company_name,
                        'confidence' => $candidate->confidence,
                        'candidate_type' => $candidate->candidate_type,
                        'is_end_client' => $candidate->is_end_client,
                        'rank' => $candidate->rank,
                        'explanation' => $candidate->explanation,
                    ])
                    ->all()
                : [],
            'sources' => $investigation->relationLoaded('sources')
                ? $investigation->sources
                    ->sortByDesc('evidence_score')
                    ->take(8)
                    ->values()
                    ->map(fn ($source) => [
                        'id' => $source->id,
                        'candidate_id' => $source->candidate_id,
                        'provider' => $source->provider,
                        'title' => $source->title,
                        'url' => $source->url,
                        'snippet' => $source->snippet,
                        'evidence_score' => $source->evidence_score,
                    ])
                    ->all()
                : [],
            'review_status' => $investigation->relationLoaded('review')
                ? $investigation->review?->status
                : null,
        ];
    }
}

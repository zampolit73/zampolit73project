<?php

namespace App\Http\Controllers;

use App\Models\InvestigationCandidate;
use App\Models\SalesProspect;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SalesProspectController extends Controller
{
    private const STATUSES = [
        'new' => 'Новый',
        'research' => 'Изучаем',
        'contacted' => 'Контакт установлен',
        'meeting' => 'Переговоры',
        'paused' => 'Отложен',
        'closed' => 'Закрыт',
    ];

    private const PRIORITIES = ['high' => 'Высокий', 'medium' => 'Средний', 'low' => 'Низкий'];

    public function index(Request $request): Response
    {
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 120);
        $status = (string) $request->query('status', '');
        if (! array_key_exists($status, self::STATUSES)) $status = '';

        $prospects = SalesProspect::query()
            ->with(['owner:id,username', 'sourceCandidate:id,investigation_id'])
            ->when($search !== '', fn ($query) => $query->where(
                fn ($nested) => $nested->where('name', 'like', '%'.$search.'%')
                    ->orWhere('inn', 'like', '%'.$search.'%')
                    ->orWhere('contact_name', 'like', '%'.$search.'%')
            ))
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest('updated_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('IrlixSales', [
            'prospects' => $prospects,
            'filters' => ['search' => $search, 'status' => $status],
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'users' => User::query()->orderBy('username')->get(['id', 'username']),
            'stats' => [
                'total' => SalesProspect::query()->count(),
                'new' => SalesProspect::query()->where('status', 'new')->count(),
                'active' => SalesProspect::query()
                    ->whereIn('status', ['research', 'contacted', 'meeting'])->count(),
            ],
            'highlight' => $request->integer('highlight'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateProspect($request);
        $data['normalized_name'] = $this->normalize($data['name']);

        if (SalesProspect::query()->where('normalized_name', $data['normalized_name'])->exists()) {
            return back()->withErrors(['name' => 'Такая компания уже есть. Найди её через поиск.']);
        }

        $prospect = SalesProspect::query()->create($data);
        return redirect()->route('projects.irlix-sales', ['highlight' => $prospect->id]);
    }

    public function update(Request $request, SalesProspect $prospect): RedirectResponse
    {
        $data = $this->validateProspect($request, $prospect);
        $data['normalized_name'] = $this->normalize($data['name']);

        if (SalesProspect::query()->where('normalized_name', $data['normalized_name'])
            ->where('id', '!=', $prospect->id)->exists()) {
            return back()->withErrors(['name' => 'Такая компания уже есть в базе.']);
        }

        $prospect->update($data);
        return redirect()->route('projects.irlix-sales', ['highlight' => $prospect->id]);
    }

    public function importCandidate(Request $request, InvestigationCandidate $candidate): RedirectResponse
    {
        $candidate->loadMissing('investigation');
        abort_unless($candidate->is_end_client && $candidate->investigation, 404);
        abort_unless(
            $request->user()->role === 'admin'
                || $candidate->investigation->user_id === $request->user()->id,
            404
        );

        $name = trim($candidate->company_name);
        abort_if($name === '' || mb_strlen($name) > 200, 422);

        $prospect = SalesProspect::query()->firstOrCreate(
            ['normalized_name' => $this->normalize($name)],
            [
                'name' => $name,
                'status' => 'new',
                'priority' => 'medium',
                'source_candidate_id' => $candidate->id,
                'notes' => 'Гипотеза из Vacancy Source (проверка #'.$candidate->investigation_id
                    .', оценка алгоритма '.$candidate->confidence.'%). '
                    .'Не считать закупку аутстаффинга подтверждённой. '.$candidate->explanation,
            ],
        );
        return redirect()->route('projects.irlix-sales', ['highlight' => $prospect->id]);
    }

    private function validateProspect(Request $request, ?SalesProspect $prospect = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'inn' => ['nullable', 'regex:/^(?:\d{10}|\d{12})$/',
                Rule::unique('sales_prospects', 'inn')->ignore($prospect?->id)],
            'website' => ['nullable', 'url:http,https', 'max:2048'],
            'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'priority' => ['required', Rule::in(array_keys(self::PRIORITIES))],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'contact_role' => ['nullable', 'string', 'max:200'],
            'contact_url' => ['nullable', 'url:http,https', 'max:2048'],
            'evidence_url' => ['nullable', 'url:http,https', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'next_step' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function normalize(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }
}

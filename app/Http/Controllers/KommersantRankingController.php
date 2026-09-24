<?php

namespace App\Http\Controllers;

use App\Models\KommersantActivity;
use App\Models\KommersantCandidate;
use App\Models\KommersantCategory;
use App\Models\KommersantManager;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class KommersantRankingController extends Controller
{
    public function index(Request $request): Response
    {
        $currentUserId = $request->user()->id;
        $categories = KommersantCategory::query()
            ->withCount('managers')
            ->orderBy('position')
            ->get();

        $categorySlugs = $categories->pluck('slug')->all();
        $requestedTab = $request->string('tab')->toString();
        $tab = in_array($requestedTab, [...$categorySlugs, 'candidates'], true)
            ? $requestedTab
            : 'overview';

        $search = trim($request->string('search')->toString());
        $company = trim($request->string('company')->toString());
        $linkedin = $request->string('linkedin')->toString();
        $assignee = $request->string('assignee')->toString();
        $status = $request->string('status')->toString();

        $managers = null;
        $candidates = null;
        $companies = [];
        $activeCategory = null;
        $listStats = null;

        if (in_array($tab, $categorySlugs, true)) {
            $activeCategory = $categories->firstWhere('slug', $tab);
            $listStats = [
                'total' => KommersantManager::query()->where('category_id', $activeCategory->id)->count(),
                'free' => KommersantManager::query()->where('category_id', $activeCategory->id)->whereNull('assigned_to_user_id')->count(),
                'inWork' => KommersantManager::query()->where('category_id', $activeCategory->id)->whereNotNull('assigned_to_user_id')->count(),
                'mine' => KommersantManager::query()->where('category_id', $activeCategory->id)->where('assigned_to_user_id', $currentUserId)->count(),
            ];

            $query = KommersantManager::query()
                ->with('assignee:id,username')
                ->where('category_id', $activeCategory->id);

            $companies = KommersantManager::query()
                ->where('category_id', $activeCategory->id)
                ->whereNotNull('company')
                ->distinct()
                ->orderBy('company')
                ->pluck('company')
                ->values()
                ->all();

            $this->applyManagerFilters($query, $search, $company, $linkedin, $assignee, $currentUserId);

            $managers = $query
                ->orderBy('row_number')
                ->paginate(50)
                ->withQueryString();
        }

        if ($tab === 'candidates') {
            $listStats = [
                'total' => KommersantCandidate::query()->count(),
                'free' => KommersantCandidate::query()->whereNull('assigned_to_user_id')->count(),
                'inWork' => KommersantCandidate::query()->whereNotNull('assigned_to_user_id')->count(),
                'mine' => KommersantCandidate::query()->where('assigned_to_user_id', $currentUserId)->count(),
            ];

            $query = KommersantCandidate::query()->with('assignee:id,username');

            $companies = KommersantCandidate::query()
                ->whereNotNull('company')
                ->distinct()
                ->orderBy('company')
                ->pluck('company')
                ->values()
                ->all();

            $this->applyCandidateFilters($query, $search, $company, $status, $assignee, $currentUserId);

            $candidates = $query
                ->orderBy('id')
                ->paginate(50)
                ->withQueryString();
        }

        return Inertia::render('KommersantRanking', [
            'tab' => $tab,
            'categories' => $categories->map(fn (KommersantCategory $category) => [
                'slug' => $category->slug,
                'label' => $category->label,
                'title' => $category->title,
                'source_note' => $category->source_note,
                'pdf_pages' => $category->pdf_pages,
                'newspaper_pages' => $category->newspaper_pages,
                'position' => $category->position,
                'managers_count' => $category->managers_count,
            ])->values(),
            'activeCategory' => $activeCategory ? [
                'slug' => $activeCategory->slug,
                'label' => $activeCategory->label,
                'title' => $activeCategory->title,
                'source_note' => $activeCategory->source_note,
                'position' => $activeCategory->position,
            ] : null,
            'stats' => [
                'total' => KommersantManager::query()->count(),
                'withLinkedin' => KommersantManager::query()->whereNotNull('linkedin_url')->count(),
                'free' => KommersantManager::query()->whereNull('assigned_to_user_id')->count(),
                'inWork' => KommersantManager::query()->whereNotNull('assigned_to_user_id')->count(),
                'mine' => KommersantManager::query()->where('assigned_to_user_id', $currentUserId)->count(),
                'candidates' => KommersantCandidate::query()->count(),
            ],
            'listStats' => $listStats,
            'filters' => [
                'search' => $search,
                'company' => $company,
                'linkedin' => $linkedin,
                'assignee' => $assignee,
                'status' => $status,
            ],
            'companies' => $companies,
            'candidateStatuses' => KommersantCandidate::query()
                ->whereNotNull('confidence_status')
                ->distinct()
                ->orderBy('confidence_status')
                ->pluck('confidence_status')
                ->values(),
            'managers' => $managers,
            'candidates' => $candidates,
            'assignees' => User::query()
                ->select(['id', 'username'])
                ->orderBy('username')
                ->get(),
            'currentUserId' => $currentUserId,
            'recentActivity' => KommersantActivity::query()
                ->with('user:id,username')
                ->latest('created_at')
                ->limit(10)
                ->get(),
            'source' => [
                'title' => 'ТОП-1000 российских менеджеров по направлениям',
                'publication' => 'газета «Коммерсантъ», №171 от 17 сентября 2026 года',
                'file' => 'KOM_171_170926.pdf',
            ],
        ]);
    }

    public function updateManager(Request $request, KommersantManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'linkedin_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'assignment_action' => ['sometimes', 'in:take,release'],
        ]);

        if (array_key_exists('linkedin_url', $data)) {
            $url = $this->normalizeLinkedinUrl($data['linkedin_url']);
            $oldUrl = $manager->linkedin_url;

            if ($oldUrl !== $url) {
                $manager->update(['linkedin_url' => $url]);
                $this->recordActivity(
                    $request->user(),
                    'manager',
                    $manager->id,
                    $manager->full_name,
                    $url ? 'linkedin_updated' : 'linkedin_removed',
                );
            }
        }

        if (isset($data['assignment_action'])) {
            $this->applyAssignment($manager, $data['assignment_action'], $request->user(), 'manager');
        }

        return back();
    }

    public function updateCandidate(Request $request, KommersantCandidate $candidate): RedirectResponse
    {
        $data = $request->validate([
            'linkedin_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'assignment_action' => ['sometimes', 'in:take,release'],
        ]);

        if (array_key_exists('linkedin_url', $data)) {
            $url = $this->normalizeLinkedinUrl($data['linkedin_url']);
            $oldUrl = $candidate->linkedin_url;

            if ($oldUrl !== $url) {
                $candidate->update(['linkedin_url' => $url]);
                $this->recordActivity(
                    $request->user(),
                    'candidate',
                    $candidate->id,
                    $candidate->full_name,
                    $url ? 'linkedin_updated' : 'linkedin_removed',
                );
            }
        }

        if (isset($data['assignment_action'])) {
            $this->applyAssignment($candidate, $data['assignment_action'], $request->user(), 'candidate');
        }

        return back();
    }

    private function applyManagerFilters($query, string $search, string $company, string $linkedin, string $assignee, int $currentUserId): void
    {
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('full_name', 'like', $like)
                    ->orWhere('company', 'like', $like)
                    ->orWhere('job_title', 'like', $like)
                    ->orWhere('industry', 'like', $like)
                    ->orWhere('linkedin_url', 'like', $like);
            });
        }

        if ($company !== '') {
            $query->where('company', $company);
        }

        if ($linkedin === 'found') {
            $query->whereNotNull('linkedin_url');
        } elseif ($linkedin === 'missing') {
            $query->whereNull('linkedin_url');
        }

        $this->applyAssigneeFilter($query, $assignee, $currentUserId);
    }

    private function applyCandidateFilters($query, string $search, string $company, string $status, string $assignee, int $currentUserId): void
    {
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($inner) use ($like) {
                $inner->where('full_name', 'like', $like)
                    ->orWhere('company', 'like', $like)
                    ->orWhere('job_title', 'like', $like)
                    ->orWhere('result_reason', 'like', $like)
                    ->orWhere('source_check', 'like', $like);
            });
        }

        if ($company !== '') {
            $query->where('company', $company);
        }

        if ($status !== '') {
            $query->where('confidence_status', $status);
        }

        $this->applyAssigneeFilter($query, $assignee, $currentUserId);
    }

    private function applyAssigneeFilter($query, string $assignee, int $currentUserId): void
    {
        if ($assignee === 'unassigned') {
            $query->whereNull('assigned_to_user_id');
        } elseif ($assignee === 'assigned') {
            $query->whereNotNull('assigned_to_user_id');
        } elseif ($assignee === 'mine') {
            $query->where('assigned_to_user_id', $currentUserId);
        } elseif (preg_match('/^user:(\\d+)$/', $assignee, $matches)) {
            $query->where('assigned_to_user_id', (int) $matches[1]);
        }
    }

    private function normalizeLinkedinUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host !== 'linkedin.com' && ! str_ends_with($host, '.linkedin.com')) {
            throw ValidationException::withMessages([
                'linkedin_url' => 'Укажи ссылку на профиль LinkedIn.',
            ]);
        }

        return $url;
    }

    private function applyAssignment(Model $record, string $action, User $user, string $subjectType): void
    {
        $modelClass = $record::class;

        if ($action === 'take') {
            if ($record->assigned_to_user_id === $user->id) {
                return;
            }

            $assigned = $modelClass::query()
                ->whereKey($record->getKey())
                ->whereNull('assigned_to_user_id')
                ->update([
                    'assigned_to_user_id' => $user->id,
                    'assigned_at' => now(),
                ]);

            if ($assigned === 0) {
                throw ValidationException::withMessages([
                    'assignment' => 'Эта запись уже взята в работу другим пользователем.',
                ]);
            }

            $record->refresh();
            $this->recordActivity($user, $subjectType, $record->getKey(), $record->full_name, 'assignment_taken');

            return;
        }

        $released = $modelClass::query()
            ->whereKey($record->getKey())
            ->where('assigned_to_user_id', $user->id)
            ->update([
                'assigned_to_user_id' => null,
                'assigned_at' => null,
            ]);

        if ($released === 0) {
            throw ValidationException::withMessages([
                'assignment' => 'Снять с работы можно только запись, назначенную на тебя.',
            ]);
        }

        $this->recordActivity($user, $subjectType, $record->getKey(), $record->full_name, 'assignment_released');
    }

    private function recordActivity(User $user, string $subjectType, int $subjectId, string $subjectName, string $action): void
    {
        KommersantActivity::query()->create([
            'user_id' => $user->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'subject_name' => $subjectName,
            'action' => $action,
            'created_at' => now(),
        ]);
    }
}

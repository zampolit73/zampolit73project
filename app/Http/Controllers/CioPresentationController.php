<?php

namespace App\Http\Controllers;

use App\Models\Presentation;
use App\Models\PresentationSource;
use App\Services\PublicPresentationScanner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

class CioPresentationController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim($request->string('search')->toString());
        $status = $request->string('status')->toString();
        $fileType = $request->string('file_type')->toString();
        $sourceId = $request->integer('source_id') ?: null;
        $canManage = $request->user()?->role === 'admin';
        $allowedTabs = $canManage
            ? ['overview', 'presentations', 'sources', 'search']
            : ['overview', 'presentations'];
        $requestedTab = $request->string('tab')->toString();
        $tab = in_array($requestedTab, $allowedTabs, true) ? $requestedTab : 'overview';

        $query = Presentation::query()->with('source:id,name,domain');

        if ($search !== '') {
            $query->where(function ($inner) use ($search) {
                $like = '%'.$search.'%';
                $inner->where('title', 'like', $like)
                    ->orWhere('speaker_name', 'like', $like)
                    ->orWhere('job_title', 'like', $like)
                    ->orWhere('company', 'like', $like)
                    ->orWhere('event_name', 'like', $like)
                    ->orWhere('file_url', 'like', $like);
            });
        }

        if (in_array($status, ['new', 'verified', 'rejected', 'archived'], true)) {
            $query->where('review_status', $status);
        }

        if (in_array($fileType, ['pdf', 'ppt', 'pptx'], true)) {
            $query->where('file_type', $fileType);
        }

        if ($sourceId) {
            $query->where('source_id', $sourceId);
        }

        return Inertia::render('CioPresentations', [
            'tab' => $tab,
            'canManage' => $canManage,
            'stats' => [
                'total' => Presentation::query()->count(),
                'new' => Presentation::query()->where('review_status', 'new')->count(),
                'verified' => Presentation::query()->where('review_status', 'verified')->count(),
                'withEmail' => Presentation::query()->where('has_email', true)->count(),
                'withPhone' => Presentation::query()->where('has_phone', true)->count(),
                'sources' => PresentationSource::query()->count(),
            ],
            'filters' => [
                'search' => $search,
                'status' => $status,
                'file_type' => $fileType,
                'source_id' => $sourceId,
            ],
            'presentations' => $query
                ->orderByDesc('discovered_at')
                ->orderByDesc('id')
                ->paginate(30)
                ->withQueryString(),
            'latest' => Presentation::query()
                ->with('source:id,name,domain')
                ->orderByDesc('discovered_at')
                ->orderByDesc('id')
                ->limit(6)
                ->get(),
            'sources' => PresentationSource::query()
                ->withCount('presentations')
                ->orderByDesc('priority')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function storeSource(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'url' => ['required', 'url:http,https', 'max:2048'],
        ]);

        $url = trim($data['url']);
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        PresentationSource::query()->updateOrCreate(
            ['url' => $url],
            [
                'name' => trim($data['name']),
                'domain' => $host,
                'is_active' => true,
            ],
        );

        return back();
    }

    public function scanSource(PresentationSource $source, PublicPresentationScanner $scanner): RedirectResponse
    {
        try {
            $candidates = $scanner->scan($source);
            $new = 0;

            foreach ($candidates as $candidate) {
                $presentation = Presentation::query()->firstOrCreate(
                    ['file_url' => $candidate['file_url']],
                    [
                        'source_id' => $source->id,
                        'title' => $candidate['title'],
                        'file_type' => $candidate['file_type'],
                        'source_page_url' => $candidate['source_page_url'],
                        'review_status' => 'new',
                        'link_status' => 'unknown',
                        'discovered_at' => now(),
                    ],
                );

                if ($presentation->wasRecentlyCreated) {
                    $new += 1;
                }
            }

            $source->update([
                'last_scanned_at' => now(),
                'last_scan_found' => $new,
                'last_error' => null,
            ]);
        } catch (RuntimeException $exception) {
            $this->recordScanFailure($source, $exception->getMessage());

            throw ValidationException::withMessages([
                'scan' => $exception->getMessage(),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $message = 'Не удалось обработать ответ источника. Ошибка записана в журнал; попробуй повторить сканирование позже.';
            $this->recordScanFailure($source, $message);

            throw ValidationException::withMessages([
                'scan' => $message,
            ]);
        }

        return back();
    }

    private function recordScanFailure(PresentationSource $source, string $message): void
    {
        $source->update([
            'last_scanned_at' => now(),
            'last_scan_found' => 0,
            'last_error' => $message,
        ]);
    }

    public function storePresentation(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_id' => ['nullable', 'integer', 'exists:presentation_sources,id'],
            'title' => ['nullable', 'string', 'max:500'],
            'speaker_name' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'event_name' => ['nullable', 'string', 'max:255'],
            'event_year' => ['nullable', 'integer', 'min:1990', 'max:2100'],
            'file_url' => ['required', 'url:http,https', 'max:2048', 'unique:presentations,file_url'],
            'file_type' => ['nullable', 'in:pdf,ppt,pptx'],
            'source_page_url' => ['nullable', 'url:http,https', 'max:2048'],
        ]);

        $path = (string) parse_url($data['file_url'], PHP_URL_PATH);
        $inferred = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $fileType = $data['file_type'] ?? (in_array($inferred, ['pdf', 'ppt', 'pptx'], true) ? $inferred : null);

        if (! $fileType) {
            throw ValidationException::withMessages([
                'file_type' => 'Укажи тип файла: PDF, PPT или PPTX.',
            ]);
        }

        Presentation::query()->create([
            ...$data,
            'file_type' => $fileType,
            'review_status' => 'new',
            'link_status' => 'unknown',
            'discovered_at' => now(),
        ]);

        return back();
    }

    public function clearPresentations(): RedirectResponse
    {
        DB::transaction(function () {
            Presentation::query()->delete();

            PresentationSource::query()->update([
                'last_scanned_at' => null,
                'last_scan_found' => 0,
                'last_error' => null,
            ]);
        });

        return back();
    }

    public function updatePresentation(Request $request, Presentation $presentation): RedirectResponse
    {
        $data = $request->validate([
            'review_status' => ['sometimes', 'in:new,verified,rejected,archived'],
            'link_status' => ['sometimes', 'in:unknown,working,dead'],
            'has_email' => ['sometimes', 'boolean'],
            'has_phone' => ['sometimes', 'boolean'],
            'is_good_lead' => ['sometimes', 'boolean'],
            'speaker_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if (array_key_exists('review_status', $data) && in_array($data['review_status'], ['verified', 'rejected'], true)) {
            $data['reviewed_at'] = now();
        }

        $presentation->update($data);

        return back();
    }
}

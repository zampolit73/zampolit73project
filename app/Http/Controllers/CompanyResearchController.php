<?php

namespace App\Http\Controllers;

use App\Services\CompanyResearchImportService;
use App\Services\CompanyResearchJsonRepairService;
use App\Services\CompanyResearchRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use ZipArchive;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CompanyResearchController extends Controller
{
    public function index(CompanyResearchRepository $reports): Response
    {
        return Inertia::render('IrlixResearch', [
            'reports' => $reports->all(),
            'importResult' => session('research_import_result'),
            'batchImportResult' => session('research_batch_import_result'),
        ]);
    }

    /**
     * Preview only: repair known ChatGPT aliases and return a downloadable
     * canonical document. Never store the uploaded file or any revisions.
     */
    public function repair(Request $request, CompanyResearchJsonRepairService $repairer): JsonResponse
    {
        $request->validate([
            'report_file' => ['required', 'file', 'max:256'],
        ]);

        $file = $request->file('report_file');
        if (! $file || strtolower($file->getClientOriginalExtension()) !== 'json') {
            throw ValidationException::withMessages(['report_file' => 'Нужен файл .json.']);
        }

        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes)) {
            throw ValidationException::withMessages(['report_file' => 'Не удалось прочитать JSON.']);
        }

        return response()->json($repairer->repair($bytes), 200, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function store(Request $request, CompanyResearchImportService $imports, CompanyResearchJsonRepairService $repairer): RedirectResponse
    {
        $request->validate([
            'report_file' => ['required', 'file', 'max:256'],
            'reviewed' => ['required', 'accepted'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('report_file');
        if (! $file || strtolower($file->getClientOriginalExtension()) !== 'json') {
            throw ValidationException::withMessages(['report_file' => 'Нужен .json из IRLIX Sales Analyst.']);
        }

        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes)) {
            throw ValidationException::withMessages(['report_file' => 'Не удалось прочитать файл.']);
        }

        $normalized = $repairer->repair($bytes)['report'];
        $saved = $imports->save($normalized, $request->user()->id, $request->boolean('replace_existing'));

        return redirect()->route('projects.irlix-sales.research')
            ->with('research_import_result', [
                'id' => $saved['id'],
                'company' => $saved['name'],
                'revision' => $saved['revision'],
                'changed' => $saved['changed'],
            ]);
    }

    /**
     * Authenticated, reviewed bulk import from a ZIP of up to 20 JSON files.
     * Everything is validated before the first write; all updates roll back
     * together if any item fails. No uploaded research is copied into Git.
     */
    public function importArchive(
        Request $request,
        CompanyResearchImportService $imports,
        CompanyResearchJsonRepairService $repairer,
    ): RedirectResponse {
        $request->validate([
            'archive' => ['required', 'file', 'max:2048'],
            'reviewed' => ['required', 'accepted'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('archive');
        if (! $file || strtolower($file->getClientOriginalExtension()) !== 'zip') {
            throw ValidationException::withMessages(['archive' => 'Выбери ZIP-архив с JSON-исследованиями.']);
        }

        $zip = new ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            throw ValidationException::withMessages(['archive' => 'Архив ZIP не открывается.']);
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 20) {
                throw ValidationException::withMessages(['archive' => 'В архиве должно быть от 1 до 20 JSON-файлов.']);
            }

            $reports = [];
            $ids = [];
            $inns = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? ($stat['name'] ?? '') : '';

                // No nested paths, symlinks, executable files or hidden extras.
                if (! is_string($name)
                    || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,110}\.json$/i', $name)
                    || ($stat['size'] ?? 0) < 1
                    || ($stat['size'] ?? 0) > 262144) {
                    throw ValidationException::withMessages([
                        'archive' => 'Файл №'.($i + 1).': нужен обычный JSON до 256 КБ без вложенных папок.',
                    ]);
                }

                $bytes = $zip->getFromIndex($i);
                if (! is_string($bytes) || strlen($bytes) > 262144) {
                    throw ValidationException::withMessages([
                        'archive' => $name.': невозможно безопасно прочитать JSON.',
                    ]);
                }

                try {
                    $report = $repairer->repair($bytes)['report'];
                } catch (ValidationException $exception) {
                    $detail = collect($exception->errors())->flatten()->first() ?: 'неверный формат';
                    throw ValidationException::withMessages(['archive' => $name.': '.$detail]);
                }

                $id = $report['id'];
                $inn = $report['company']['inn'] ?: null;

                if (isset($ids[$id]) || ($inn && isset($inns[$inn]))) {
                    throw ValidationException::withMessages([
                        'archive' => $name.': дублирующаяся компания в архиве (ID или ИНН).',
                    ]);
                }

                $ids[$id] = true;
                if ($inn) {
                    $inns[$inn] = true;
                }
                $reports[] = $report;
            }
        } finally {
            $zip->close();
        }

        // Preflight every duplicate before opening a transaction.
        if (! $request->boolean('replace_existing')) {
            foreach ($reports as $report) {
                if ($imports->existing($report)) {
                    throw ValidationException::withMessages([
                        'replace_existing' => 'Компания «'.$report['company']['name']
                            .'» уже есть. Разреши обновление существующих карточек.',
                    ]);
                }
            }
        }

        $saved = DB::transaction(function () use ($imports, $reports, $request): array {
            $results = [];
            foreach ($reports as $report) {
                $results[] = $imports->save(
                    $report,
                    $request->user()->id,
                    $request->boolean('replace_existing'),
                );
            }
            return $results;
        });

        return redirect()->route('projects.irlix-sales.research')
            ->with('research_batch_import_result', [
                'total' => count($saved),
                'created_or_updated' => count(array_filter($saved, fn ($item) => $item['changed'])),
                'companies' => array_map(fn ($item) => $item['name'], $saved),
            ]);
    }
}

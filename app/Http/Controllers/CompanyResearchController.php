<?php

namespace App\Http\Controllers;

use App\Services\CompanyResearchImportService;
use App\Services\CompanyResearchRepository;
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
        ]);
    }

    public function store(Request $request, CompanyResearchImportService $imports): RedirectResponse
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

        $normalized = $imports->prepare($bytes);
        $saved = $imports->save($normalized, $request->user()->id, $request->boolean('replace_existing'));

        return redirect()->route('projects.irlix-sales.research')
            ->with('research_import_result', [
                'id' => $saved['id'],
                'company' => $saved['name'],
                'revision' => $saved['revision'],
                'changed' => $saved['changed'],
            ]);
    }
}

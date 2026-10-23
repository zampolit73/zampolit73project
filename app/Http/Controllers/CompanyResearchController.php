<?php

namespace App\Http\Controllers;

use App\Models\CompanyResearchImport;
use App\Services\CompanyResearchRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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

    /**
     * Imports a single user-reviewed JSON document into persistent SQLite.
     * Nothing from this endpoint is committed to public GitHub or public storage.
     */
    public function store(Request $request, CompanyResearchRepository $reports): RedirectResponse
    {
        $request->validate([
            'report_file' => ['required', 'file', 'max:256'],
            'reviewed' => ['required', 'accepted'],
            'replace_existing' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('report_file');
        if (! $file || strtolower($file->getClientOriginalExtension()) !== 'json') {
            throw ValidationException::withMessages(['report_file' => 'Нужен файл формата .json из IRLIX Sales Analyst.']);
        }

        $bytes = file_get_contents($file->getRealPath());
        if (! is_string($bytes) || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw ValidationException::withMessages(['report_file' => 'Файл должен содержать корректный UTF-8 JSON.']);
        }

        $payload = json_decode($bytes, true, 32);
        if (! is_array($payload) || array_is_list($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages(['report_file' => 'Не удалось прочитать объект JSON. Проверьте формат экспорта.']);
        }

        Validator::make($payload, [
            'schema_version' => ['required', 'integer', 'in:1'],
            'id' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{2,79}$/'],
            'company' => ['required', 'array'],
            'company.name' => ['required', 'string', 'max:200'],
            'company.inn' => ['nullable', 'regex:/^\d{10}(\d{2})?$/'],
            'researched_at' => ['required', 'date_format:Y-m-d\TH:i:s\Z'],
            'outstaff_demand' => ['required', 'array'],
            'outstaff_demand.assessment' => ['required', 'in:confirmed,likely,unclear,no_evidence'],
            'evidence' => ['present', 'array', 'max:30'],
            'decision_makers' => ['sometimes', 'array', 'max:30'],
            'business_contacts' => ['sometimes', 'array', 'max:30'],
            'tenders' => ['sometimes', 'array', 'max:20'],
            'financials' => ['sometimes', 'array', 'max:12'],
            'buying_signals' => ['sometimes', 'array', 'max:25'],
            'projects' => ['sometimes', 'array', 'max:25'],
            'contractors' => ['sometimes', 'array', 'max:20'],
        ])->validate();

        $normalized = $reports->normalizePayload($payload);
        if (! $normalized) {
            throw ValidationException::withMessages(['report_file' => 'Отчёт не соответствует утверждённой схеме IRLIX Intelligence.']);
        }

        if (preg_match('/^inn-(\d{10}|\d{12})$/', $normalized['id'], $matched)
            && $normalized['company']['inn'] !== $matched[1]) {
            throw ValidationException::withMessages(['report_file' => 'ИНН внутри файла не соответствует идентификатору файла.']);
        }

        // Never silently drop source links, contact cards or other critical lists.
        foreach (['evidence','decision_makers','business_contacts','financials','projects','buying_signals','tenders'] as $key) {
            if (count($payload[$key] ?? []) !== count($normalized[$key])) {
                throw ValidationException::withMessages(['report_file' => 'В разделе '.$key.' есть неполные данные или небезопасные ссылки. Исправьте экспорт.']);
            }
        }
        if ($normalized['outstaff_demand']['assessment'] !== $payload['outstaff_demand']['assessment']) {
            throw ValidationException::withMessages(['report_file' => 'Нельзя подтверждать IT-аутстаффинг без источников.']);
        }

        $inn = $normalized['company']['inn'] ?: null;
        $existing = CompanyResearchImport::query()
            ->where('identifier', $normalized['id'])
            ->when($inn, fn ($query) => $query->orWhere('company_inn', $inn))
            ->first();

        // Public Git-backed cards also count as existing companies.
        $existsInGit = collect($reports->all())->contains(
            fn ($item) => ($item['id'] === $normalized['id'] ||
                ($inn !== null && $item['company']['inn'] === $inn))
        );
        if (($existing || $existsInGit) && ! $request->boolean('replace_existing')) {
            throw ValidationException::withMessages(['replace_existing' => 'Компания уже есть. Отметьте подтверждение замены, чтобы создать новую версию без дубля.']);
        }

        // Save only normalized whitelisted fields, never arbitrary extra keys.
        $data = ['schema_version' => 1] + $normalized;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);

        $result = DB::transaction(function () use ($normalized, $inn, $existing, $json, $hash, $request) {
            $entry = $existing ? CompanyResearchImport::query()->lockForUpdate()->findOrFail($existing->id) : null;
            if ($entry && $entry->payload_sha256 === $hash) {
                return [$entry, false];
            }
            if (! $entry) {
                $entry = new CompanyResearchImport([
                    'identifier' => $normalized['id'],
                    'company_inn' => $inn,
                    'revision' => 0,
                ]);
            }
            $entry->company_name = $normalized['company']['name'];
            $entry->company_inn = $inn;
            $entry->payload = $json;
            $entry->payload_sha256 = $hash;
            $entry->imported_by = $request->user()->id;
            $entry->revision++;
            $entry->save();
            $entry->revisions()->create([
                'revision' => $entry->revision,
                'payload' => $json,
                'payload_sha256' => $hash,
                'imported_by' => $request->user()->id,
            ]);

            return [$entry, true];
        });

        [$saved, $changed] = $result;

        return redirect()->route('projects.irlix-sales.research')
            ->with('research_import_result', [
                'id' => $saved->identifier,
                'company' => $saved->company_name,
                'revision' => $saved->revision,
                'changed' => $changed,
            ]);
    }
}

<?php

namespace App\Services;

use App\Models\CompanyResearchImport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CompanyResearchImportService
{
    public function __construct(private readonly CompanyResearchRepository $reports)
    {
    }

    /**
     * Both the web uploader and Telegram bot use exactly the same server-side
     * validation. Return normalized, whitelisted report fields only.
     */
    public function prepare(string $bytes): array
    {
        if (strlen($bytes) > 262144 || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw ValidationException::withMessages(['report_file' => 'JSON должен быть в UTF-8 и не больше 256 КБ.']);
        }

        $payload = json_decode($bytes, true, 32);
        if (! is_array($payload) || array_is_list($payload) || json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages(['report_file' => 'Нужен корректный JSON-объект IRLIX Intelligence.']);
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

        $normalized = $this->reports->normalizePayload($payload);
        if (! $normalized) {
            throw ValidationException::withMessages(['report_file' => 'Отчёт не соответствует схеме IRLIX Intelligence v1.']);
        }

        if (preg_match('/^inn-(\d{10}|\d{12})$/', $normalized['id'], $matched)
            && $normalized['company']['inn'] !== $matched[1]) {
            throw ValidationException::withMessages(['report_file' => 'ИНН внутри JSON не соответствует ID компании.']);
        }

        foreach (['evidence','decision_makers','business_contacts','financials','projects','buying_signals','tenders'] as $key) {
            if (count($payload[$key] ?? []) !== count($normalized[$key])) {
                throw ValidationException::withMessages(['report_file' => 'Некорректные данные или ссылки в разделе '.$key.'.']);
            }
        }
        if ($normalized['outstaff_demand']['assessment'] !== $payload['outstaff_demand']['assessment']) {
            throw ValidationException::withMessages(['report_file' => 'Закупка IT-аутстаффинга не может быть подтверждена без источников.']);
        }

        return $normalized;
    }

    public function existing(array $report): ?array
    {
        $inn = $report['company']['inn'] ?: null;
        $db = CompanyResearchImport::query()->where('identifier', $report['id'])
            ->when($inn, fn ($query) => $query->orWhere('company_inn', $inn))
            ->first();

        if ($db) {
            return ['id' => $db->identifier, 'name' => $db->company_name, 'origin' => 'sqlite'];
        }

        foreach ($this->reports->all() as $item) {
            if ($item['id'] === $report['id']
                || ($inn && $item['company']['inn'] === $inn)) {
                return ['id' => $item['id'], 'name' => $item['company']['name'], 'origin' => $item['origin']];
            }
        }

        return null;
    }

    /**
     * The caller must explicitly confirm replacement when a company exists.
     * Persist a new revision only for a changed normalized report.
     *
     * @return array{id:string,name:string,revision:int,changed:bool}
     */
    public function save(array $normalized, int $userId, bool $replaceExisting): array
    {
        $existing = $this->existing($normalized);
        if ($existing && ! $replaceExisting) {
            throw ValidationException::withMessages([
                'replace_existing' => 'Компания уже есть. Подтверди обновление, чтобы сохранить новую версию.',
            ]);
        }

        $inn = $normalized['company']['inn'] ?: null;
        $json = json_encode(['schema_version' => 1] + $normalized,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);

        return DB::transaction(function () use ($normalized, $inn, $json, $hash, $userId) {
            $entry = CompanyResearchImport::query()
                ->where('identifier', $normalized['id'])
                ->when($inn, fn ($query) => $query->orWhere('company_inn', $inn))
                ->lockForUpdate()
                ->first();

            if ($entry && $entry->payload_sha256 === $hash) {
                return [
                    'id' => $entry->identifier, 'name' => $entry->company_name,
                    'revision' => $entry->revision, 'changed' => false,
                ];
            }

            if (! $entry) {
                $entry = new CompanyResearchImport([
                    'identifier' => $normalized['id'],
                    'company_inn' => $inn,
                    'revision' => 0,
                ]);
            }

            // Keep stable identity when the imported report uses a different slug
            // but the same INN as an existing SQLite research record.
            if ($entry->exists && $entry->identifier !== $normalized['id']) {
                $normalized['id'] = $entry->identifier;
                $json = json_encode(['schema_version' => 1] + $normalized,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $hash = hash('sha256', $json);
            }

            $entry->company_name = $normalized['company']['name'];
            $entry->company_inn = $inn;
            $entry->payload = $json;
            $entry->payload_sha256 = $hash;
            $entry->imported_by = $userId;
            $entry->revision++;
            $entry->save();
            $entry->revisions()->create([
                'revision' => $entry->revision,
                'payload' => $json,
                'payload_sha256' => $hash,
                'imported_by' => $userId,
            ]);

            return [
                'id' => $entry->identifier,
                'name' => $entry->company_name,
                'revision' => $entry->revision,
                'changed' => true,
            ];
        });
    }
}

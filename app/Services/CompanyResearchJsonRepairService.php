<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Repairs documented ChatGPT JSON aliases before applying the exact same
 * validation as normal web / Telegram imports. Never persists the upload.
 */
class CompanyResearchJsonRepairService
{
    public function __construct(private readonly CompanyResearchImportService $imports)
    {
    }

    /** @return array{report:array,changes:array,warnings:array} */
    public function repair(string $bytes): array
    {
        if (strlen($bytes) > 262144 || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw ValidationException::withMessages(['report_file' => 'JSON должен быть в UTF-8 и не больше 256 КБ.']);
        }

        $document = json_decode($bytes, true, 32);
        if (! is_array($document) || array_is_list($document) || json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages(['report_file' => 'Нужен корректный JSON-объект.']);
        }

        $changes = [];
        $warnings = [];

        // Work on the actual arrays, not temporary null-coalescing copies.
        foreach (['projects', 'buying_signals', 'financials', 'contractors', 'sales_plan'] as $section) {
            if (! array_key_exists($section, $document)) {
                $document[$section] = [];
            } elseif (! is_array($document[$section])) {
                throw ValidationException::withMessages([
                    'report_file' => "$section: ожидается массив.",
                ]);
            }
        }

        foreach ($document['projects'] as $i => &$project) {
            if (! is_array($project)) {
                continue;
            }
            $path = "projects[$i]";
            $this->alias($project, 'url', 'source_url', $path, $changes);
            $this->alias($project, 'description', 'details', $path, $changes);
            $this->appendMetadata($project, 'details', [
                'contractor' => 'Подрядчик', 'year' => 'Год', 'status' => 'Статус',
            ], $path, $changes, $warnings);
            $this->warnExtras($project, ['name', 'details', 'source_url'], $path, $warnings);
        }
        unset($project);

        foreach ($document['buying_signals'] as $i => &$signal) {
            if (! is_array($signal)) {
                continue;
            }
            $path = "buying_signals[$i]";
            $this->alias($signal, 'url', 'source_url', $path, $changes);
            $this->alias($signal, 'title', 'signal', $path, $changes);
            $this->alias($signal, 'finding', 'details', $path, $changes);
            if (! isset($signal['evidence_level'])) {
                // A job opening or product announcement is not proof of outstaffing.
                $signal['evidence_level'] = 'C';
                $changes[] = "$path.evidence_level: установлен осторожный уровень C (требует проверки)";
            }
            $this->appendMetadata($signal, 'details', [
                'signal_type' => 'Тип сигнала', 'category' => 'Категория',
                'published_at' => 'Опубликовано',
            ], $path, $changes, $warnings);
            $this->warnExtras($signal, ['signal', 'details', 'source_url', 'evidence_level'], $path, $warnings);
        }
        unset($signal);

        foreach ($document['financials'] as $i => &$item) {
            if (! is_array($item)) {
                continue;
            }
            $path = "financials[$i]";
            $this->alias($item, 'revenue', 'revenue_rub', $path, $changes);
            $this->alias($item, 'net_profit', 'profit_rub', $path, $changes);
            if (isset($item['currency']) && strtoupper((string) $item['currency']) !== 'RUB') {
                throw ValidationException::withMessages([
                    'report_file' => "$path: валюта не RUB. Автоматический пересчёт финансов запрещён.",
                ]);
            }
            $this->warnExtras($item, ['year', 'revenue_rub', 'profit_rub', 'source_url', 'currency'], $path, $warnings);
        }
        unset($item);

        foreach ($document['contractors'] as $i => &$contractor) {
            if (! is_array($contractor)) {
                continue;
            }
            $path = "contractors[$i]";
            if (($contractor['relationship'] ?? null) === 'confirmed_external_development') {
                $contractor['relationship'] = 'confirmed';
                $changes[] = "$path.relationship: внешняя разработка подтверждена; аутстаффинг НЕ подтверждён";
            }
            $this->alias($contractor, 'notes', 'note', $path, $changes);
            $this->appendMetadata($contractor, 'note', [
                'service' => 'Услуга', 'source_url' => 'Источник',
            ], $path, $changes, $warnings);
            $this->warnExtras($contractor, ['name', 'relationship', 'note'], $path, $warnings);
        }
        unset($contractor);

        foreach ($document['sales_plan'] as $i => &$step) {
            if (! is_array($step)) {
                continue;
            }
            $path = "sales_plan[$i]";
            $this->alias($step, 'audience', 'target', $path, $changes);
            $this->appendMetadata($step, 'action', ['goal' => 'Цель'], $path, $changes, $warnings);
            $this->warnExtras($step, ['step', 'action', 'target'], $path, $warnings);
        }
        unset($step);

        // The strict, shared importer validates all URLs, identity, dimensions,
        // required data, and evidence for "confirmed" claims.
        $prepared = $this->imports->prepare(json_encode(
            $document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));

        $report = ['schema_version' => 1] + $prepared;
        $size = strlen(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($size > 262144) {
            throw ValidationException::withMessages(['report_file' => 'Исправленный JSON превышает 256 КБ.']);
        }

        $known = [
            'schema_version', 'id', 'company', 'researched_at', 'summary', 'outstaff_demand',
            'technologies', 'contractors', 'decision_makers', 'business_contacts',
            'legal_profile', 'financials', 'projects', 'buying_signals', 'tenders',
            'procurement', 'sales_assessment', 'sales_plan', 'crm_summary', 'evidence',
            'recommendation', 'open_questions',
        ];
        $this->warnExtras($document, $known, 'report', $warnings);

        return ['report' => $report, 'changes' => $changes, 'warnings' => $warnings];
    }

    private function alias(array &$row, string $from, string $to, string $path, array &$changes): void
    {
        if (! array_key_exists($from, $row)) {
            return;
        }
        if (array_key_exists($to, $row) && $row[$to] !== $row[$from]) {
            throw ValidationException::withMessages([
                'report_file' => "$path: одновременно заданы разные значения $from и $to. Нужна ручная проверка.",
            ]);
        }
        $row[$to] = $row[$from];
        unset($row[$from]);
        $changes[] = "$path.$from → $to";
    }

    private function appendMetadata(
        array &$row, string $field, array $extra, string $path, array &$changes, array &$warnings
    ): void {
        foreach ($extra as $key => $label) {
            if (! array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            unset($row[$key]);
            if ($value === null || $value === '') {
                continue;
            }
            // Only preserve source URLs if syntactically safe; unsafe URLs must
            // be rejected rather than laundered into descriptive text.
            if ($key === 'source_url' && (! is_string($value)
                || ! filter_var($value, FILTER_VALIDATE_URL)
                || ! in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true)
                || parse_url($value, PHP_URL_USER) !== null
                || parse_url($value, PHP_URL_PASS) !== null)) {
                throw ValidationException::withMessages(['report_file' => "$path.$key: недопустимая ссылка."]);
            }
            if (! is_scalar($value)) {
                $warnings[] = "$path.$key: сложное значение не перенесено, требуется ручная проверка";
                continue;
            }
            $text = trim((string) ($row[$field] ?? ''));
            $part = "$label: $value";
            $row[$field] = $text === '' ? $part : "$text; $part";
            $changes[] = "$path.$key → $field";
        }
    }

    private function warnExtras(array $row, array $known, string $path, array &$warnings): void
    {
        foreach (array_diff(array_keys($row), $known) as $field) {
            $warnings[] = "$path.$field: поле не поддерживается и не попадёт в итоговый JSON";
        }
    }
}

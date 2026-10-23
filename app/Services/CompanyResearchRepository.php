<?php

namespace App\Services;

class CompanyResearchRepository
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? base_path('data/company-reports');
    }

    /**
     * Read-only, Git-backed public reports. No network access and no database migrations.
     * Invalid documents are ignored instead of breaking the admin workspace.
     */
    public function all(): array
    {
        $reports = [];

        foreach (glob($this->directory.'/*.json') ?: [] as $file) {
            if (! is_file($file) || filesize($file) > 131072) {
                continue;
            }

            $record = json_decode((string) file_get_contents($file), true);

            if (! is_array($record) || ($record['schema_version'] ?? null) !== 1) {
                continue;
            }

            $id = $record['id'] ?? '';
            $company = $record['company'] ?? [];
            if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9-]{2,79}$/', $id)
                || pathinfo($file, PATHINFO_FILENAME) !== $id
                || ! is_array($company)
                || ! is_string($company['name'] ?? null)
                || trim($company['name']) === '') {
                continue;
            }

            $date = $record['researched_at'] ?? '';
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $date)
                || strtotime($date) === false) {
                continue;
            }

            $evidence = [];
            foreach (array_slice(is_array($record['evidence'] ?? null) ? $record['evidence'] : [], 0, 30) as $source) {
                if (! is_array($source) || ! $this->safeUrl($source['url'] ?? null)) {
                    continue;
                }

                $evidence[] = [
                    'title' => $this->s($source['title'] ?? '', 220),
                    'url' => $source['url'],
                    'finding' => $this->s($source['finding'] ?? '', 1800),
                ];
            }

            $demand = is_array($record['outstaff_demand'] ?? null) ? $record['outstaff_demand'] : [];
            $status = in_array($demand['assessment'] ?? null, ['confirmed', 'likely', 'unclear', 'no_evidence'], true)
                ? $demand['assessment'] : 'unclear';

            // A "confirmed" market signal without a cited source is not confirmed.
            if ($status === 'confirmed' && count($evidence) === 0) {
                $status = 'unclear';
            }

            $contractors = [];
            foreach (array_slice(is_array($record['contractors'] ?? null) ? $record['contractors'] : [], 0, 20) as $contractor) {
                if (is_array($contractor) && is_string($contractor['name'] ?? null)) {
                    $contractors[] = [
                        'name' => $this->s($contractor['name'], 180),
                        'relationship' => in_array($contractor['relationship'] ?? null, ['confirmed', 'hypothesis'], true)
                            ? $contractor['relationship'] : 'hypothesis',
                        'note' => $this->s($contractor['note'] ?? '', 800),
                    ];
                }
            }

            // Only published professional contacts with attributable source links.
            // Never derive work emails from a person's name.
            $contacts = [];
            foreach (array_slice(is_array($record['decision_makers'] ?? null) ? $record['decision_makers'] : [], 0, 30) as $contact) {
                if (! is_array($contact) || $this->s($contact['name'] ?? '', 180) === '') {
                    continue;
                }

                $sourceUrl = $contact['source_url'] ?? ($contact['public_url'] ?? null);
                if (! $this->safeUrl($sourceUrl)) {
                    continue;
                }

                $category = $contact['category'] ?? 'other';
                $roleStatus = $contact['role_status'] ?? 'needs_verification';
                $contacts[] = [
                    'name' => $this->s($contact['name'], 180),
                    'role' => $this->s($contact['role'] ?? '', 180),
                    'company_unit' => $this->s($contact['company_unit'] ?? '', 180),
                    'category' => in_array($category, ['it_leadership', 'engineering', 'product', 'it_procurement', 'it_recruiting', 'other'], true) ? $category : 'other',
                    'priority' => in_array($contact['priority'] ?? null, [1, 2, 3], true) ? $contact['priority'] : 3,
                    'role_status' => in_array($roleStatus, ['current', 'needs_verification', 'former'], true) ? $roleStatus : 'needs_verification',
                    'last_checked_at' => $this->date($contact['last_checked_at'] ?? null),
                    'responsibility' => $this->s($contact['responsibility'] ?? '', 750),
                    'outreach_angle' => $this->s($contact['outreach_angle'] ?? '', 800),
                    'public_url' => $this->safeUrl($contact['public_url'] ?? null) ? $contact['public_url'] : null,
                    'source_url' => $sourceUrl,
                    'work_email' => $this->email($contact['work_email'] ?? null),
                    'work_phone' => $this->phone($contact['work_phone'] ?? null),
                ];
            }
            usort($contacts, fn ($a, $b) => $a['priority'] <=> $b['priority']);

            $businessContacts = [];
            foreach (array_slice(is_array($record['business_contacts'] ?? null) ? $record['business_contacts'] : [], 0, 30) as $channel) {
                if (! is_array($channel) || ! $this->safeUrl($channel['source_url'] ?? null)) {
                    continue;
                }
                $email = $this->email($channel['work_email'] ?? null);
                $phone = $this->phone($channel['work_phone'] ?? null);
                if ($email === null && $phone === null) {
                    continue;
                }
                $businessContacts[] = [
                    'department' => $this->s($channel['department'] ?? '', 180),
                    'contact_name' => $this->s($channel['contact_name'] ?? '', 180),
                    'work_email' => $email,
                    'work_phone' => $phone,
                    'purpose' => $this->s($channel['purpose'] ?? '', 700),
                    'source_url' => $channel['source_url'],
                    'last_checked_at' => $this->date($channel['last_checked_at'] ?? null),
                ];
            }

            $reports[] = [
                'id' => $id,
                'company' => [
                    'name' => $this->s($company['name'], 200),
                    'inn' => $this->s($company['inn'] ?? '', 12),
                    'website' => $this->safeUrl($company['website'] ?? null) ? $company['website'] : null,
                    'industry' => $this->s($company['industry'] ?? '', 180),
                ],
                'researched_at' => $date,
                'summary' => $this->s($record['summary'] ?? '', 6000),
                'outstaff_demand' => [
                    'assessment' => $status,
                    'explanation' => $this->s($demand['explanation'] ?? '', 3500),
                ],
                'technologies' => $this->strings($record['technologies'] ?? [], 35, 90),
                'contractors' => $contractors,
                'decision_makers' => $contacts,
                'business_contacts' => $businessContacts,
                'evidence' => $evidence,
                'recommendation' => $this->s($record['recommendation'] ?? '', 4000),
                'open_questions' => $this->strings($record['open_questions'] ?? [], 20, 350),
            ];
        }

        usort($reports, fn (array $a, array $b) =>
            strcmp($b['researched_at'], $a['researched_at']) ?: strcmp($a['id'], $b['id'])
        );

        return $reports;
    }

    private function s(mixed $value, int $max): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }

    private function strings(mixed $items, int $limit, int $max): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($item) => $this->s($item, $max), array_slice($items, 0, $limit)),
            fn ($item) => $item !== ''
        ));
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year) ? $value : null;
    }

    private function email(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 254) {
            return null;
        }

        $email = trim($value);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function phone(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 32 || ! preg_match('/^\+?[0-9 ()-]{7,32}$/', $value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        return strlen($digits) >= 7 && strlen($digits) <= 15 ? trim($value) : null;
    }

    private function safeUrl(mixed $value): bool
    {
        if (! is_string($value) || strlen($value) > 2048 || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($value);

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! empty($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}

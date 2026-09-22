<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $sources = [
            ['4CIO — ОКИТ 2026: презентации ИТ-служб', 'https://okit2026.4cio.ru/report', 130],
            ['4CIO — ОКИТ 2025: презентации ИТ-служб', 'https://okit2025.4cio.ru/report', 115],
            ['4CIO — ОКИТ 2024: презентации ИТ-служб', 'https://okit2024.4cio.ru/report', 105],
            ['ИТ-Диалог — Киберконтур 2025: презентации', 'https://xn--90ard6a.xn--80agbpbtv1a.xn--p1ai/kc2025', 100],
            ['CNews FORUM Кейсы 2026 — архив презентаций', 'https://cnewsforum.ru/cases/2026/presentations', 130],
            ['CNews FORUM Кейсы 2025 — архив презентаций', 'https://cnewsforum.ru/cases/2025/presentations', 115],
            ['CNews FORUM Кейсы 2024 — архив презентаций', 'https://cnewsforum.ru/cases/2024/presentations', 105],
        ];

        $now = now();

        DB::table('presentation_sources')->insertOrIgnore(array_map(
            fn (array $source) => [
                'name' => $source[0],
                'url' => $source[1],
                'domain' => parse_url($source[1], PHP_URL_HOST),
                'priority' => $source[2],
                'is_active' => true,
                'last_scan_found' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $sources,
        ));
    }

    public function down(): void
    {
        // Sources become user-managed rows and may have existed before this migration.
        // Preserve them and their presentation history when rolling back.
    }
};

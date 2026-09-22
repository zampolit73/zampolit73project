<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function sources(): array
    {
        return [
            [
                'name' => 'CNews FORUM Кейсы 2026 — презентации',
                'url' => 'https://cnewsforum.ru/cases/presentations',
                'domain' => 'cnewsforum.ru',
                'priority' => 130,
            ],
            [
                'name' => 'Industrial++ 2025 — доклады и презентации',
                'url' => 'https://industrialconf.ru/2025/abstracts',
                'domain' => 'industrialconf.ru',
                'priority' => 126,
            ],
            [
                'name' => 'ЦИПР — архив докладов и презентаций',
                'url' => 'https://cipr-reports.ru/',
                'domain' => 'cipr-reports.ru',
                'priority' => 130,
            ],
            [
                'name' => 'ЦИПР 2025 — отчёт и презентации спикеров',
                'url' => 'https://cipr.ru/media-2025/',
                'domain' => 'cipr.ru',
                'priority' => 118,
            ],
            [
                'name' => 'Цифровая устойчивость промышленных систем — материалы',
                'url' => 'https://tsups.ib-bank.ru/materials',
                'domain' => 'tsups.ib-bank.ru',
                'priority' => 124,
            ],
        ];
    }

    public function up(): void
    {
        $now = now();

        $rows = array_map(
            fn (array $source) => [
                ...$source,
                'is_active' => true,
                'last_scan_found' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $this->sources(),
        );

        DB::table('presentation_sources')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('presentation_sources')
            ->whereIn('url', array_column($this->sources(), 'url'))
            ->delete();
    }
};

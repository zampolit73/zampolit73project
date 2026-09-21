<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function sources(): array
    {
        return [
            [
                'name' => 'TAdviser SummIT 2026 — лучшие ИТ-практики, 28 мая',
                'url' => 'https://tadvisersummit.ru/a/2026-1/',
                'domain' => 'tadvisersummit.ru',
                'priority' => 120,
            ],
            [
                'name' => 'TAdviser SummIT 2026 — итоги года и планы 2027, 26 ноября',
                'url' => 'https://tadvisersummit.ru/',
                'domain' => 'tadvisersummit.ru',
                'priority' => 119,
            ],
            [
                'name' => 'TAdviser IT Prize 2026',
                'url' => 'https://itprize.tadviser.ru/',
                'domain' => 'itprize.tadviser.ru',
                'priority' => 110,
            ],
            [
                'name' => 'CNews FORUM 2026 — ИТ-директора России',
                'url' => 'https://www.cnews.ru/news/top/2026-09-16_sotni_it-direktorov_rossii',
                'domain' => 'www.cnews.ru',
                'priority' => 120,
            ],
            [
                'name' => 'CNews FORUM 2026 — первые докладчики',
                'url' => 'https://www.cnews.ru/news/top/2026-08-13_cnews_forum_2026_pervye_dokladchiki',
                'domain' => 'www.cnews.ru',
                'priority' => 118,
            ],
            [
                'name' => 'CNews FORUM Кейсы 2026 — опыт ИТ-лидеров',
                'url' => 'https://www.cnews.ru/news/top/2026-05-13_cnews_forum_kejsy_2026_sotni_it-direktorov',
                'domain' => 'www.cnews.ru',
                'priority' => 117,
            ],
            [
                'name' => 'CNews FORUM Кейсы 2026 — промышленные кейсы',
                'url' => 'https://www.cnews.ru/articles/2026-06-29_ot_temnyh_dannyh_do_avtonomnyh/4',
                'domain' => 'www.cnews.ru',
                'priority' => 116,
            ],
        ];
    }

    public function up(): void
    {
        DB::transaction(function () {
            // User requested a clean restart: remove presentation/history data,
            // but preserve the approved source catalog itself.
            DB::table('presentations')->delete();

            DB::table('presentation_sources')->update([
                'last_scanned_at' => null,
                'last_scan_found' => 0,
                'last_error' => null,
                'updated_at' => now(),
            ]);

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
        });
    }

    public function down(): void
    {
        DB::table('presentation_sources')
            ->whereIn('url', array_column($this->sources(), 'url'))
            ->delete();
    }
};

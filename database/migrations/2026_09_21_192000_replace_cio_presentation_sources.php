<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function previousPresetUrls(): array
    {
        return [
            'https://1c.ru/bf/default.jsp',
            'https://1c.ru/bf/2025/default.jsp',
            'https://1c.ru/bf/2024/default.jsp',
            'https://1c.ru/bf/2023/default.jsp',
            'https://1c.ru/bf/2022/default.jsp',
            'https://1c.ru/bf/2021/default.jsp',
            'https://v8.1c.ru/cpm-erp/poleznye-materialy/presentations/',
            'https://zoom.cnews.ru/soft/news/top/2026-07-16_iz-za_sanktsij_poteryali_bolshuyu',
            'https://www.cnews.ru/news/top/2026-07-03_it-direktor_osk_rasskazal',
            'https://www.cnews.ru/articles/2015-11-24_mobilnye_tehnologii_na_cnews_forum_2015_kuda_privedut_mirovye/2',
            'https://ru.globalcio.ru/pharma26_dm1',
        ];
    }

    private function approvedSources(): array
    {
        return [
            [
                'name' => 'TAdviser SummIT — архив и планы 2025',
                'url' => 'https://summit.tadviser.ru/a/2024-2/',
                'domain' => 'summit.tadviser.ru',
                'priority' => 100,
            ],
            [
                'name' => 'TAdviser SummIT 2017 — программа',
                'url' => 'https://summit2017.tadviser.ru/',
                'domain' => 'summit2017.tadviser.ru',
                'priority' => 95,
            ],
            [
                'name' => 'TAdviser Summit 2017 — спикеры',
                'url' => 'https://summit2017-2.tadviser.ru/',
                'domain' => 'summit2017-2.tadviser.ru',
                'priority' => 94,
            ],
            [
                'name' => 'TAdviser SummIT 2016',
                'url' => 'https://summit2016.tadviser.ru/',
                'domain' => 'summit2016.tadviser.ru',
                'priority' => 90,
            ],
            [
                'name' => 'CNews — индекс материалов CIO / ИТ-директор',
                'url' => 'https://www.cnews.ru/book/mutual/1667/2195',
                'domain' => 'www.cnews.ru',
                'priority' => 100,
            ],
            [
                'name' => 'CNews FORUM Кейсы 2026 — ИТ-директор ОСК',
                'url' => 'https://www.cnews.ru/news/top/2026-07-03_it-direktor_osk_rasskazal',
                'domain' => 'www.cnews.ru',
                'priority' => 98,
            ],
            [
                'name' => 'CNews FORUM — ИТ-директора и импортозамещение',
                'url' => 'https://www.cnews.ru/news/top/2024-11-20_pochemu_biznes_ostaetsya_na',
                'domain' => 'www.cnews.ru',
                'priority' => 90,
            ],
            [
                'name' => 'CNews FORUM 2015 — материалы конференции',
                'url' => 'https://www.cnews.ru/articles/2015-11-24_mobilnye_tehnologii_na_cnews_forum_2015_kuda_privedut_mirovye/2',
                'domain' => 'www.cnews.ru',
                'priority' => 80,
            ],
        ];
    }

    public function up(): void
    {
        DB::table('presentation_sources')
            ->whereIn('url', $this->previousPresetUrls())
            ->delete();

        $now = now();

        $rows = array_map(
            fn (array $source) => [
                ...$source,
                'is_active' => true,
                'last_scan_found' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $this->approvedSources(),
        );

        DB::table('presentation_sources')->insertOrIgnore($rows);
    }

    public function down(): void
    {
        DB::table('presentation_sources')
            ->whereIn('url', array_column($this->approvedSources(), 'url'))
            ->delete();
    }
};

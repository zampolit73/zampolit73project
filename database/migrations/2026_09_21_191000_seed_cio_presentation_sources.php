<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function sources(): array
    {
        return [
            [
                'name' => '1С:ERP — архив бизнес-форумов',
                'url' => 'https://1c.ru/bf/default.jsp',
                'domain' => '1c.ru',
                'priority' => 100,
            ],
            [
                'name' => '1С:ERP 2025 — программа и материалы',
                'url' => 'https://1c.ru/bf/2025/default.jsp',
                'domain' => '1c.ru',
                'priority' => 99,
            ],
            [
                'name' => '1С:ERP 2024 — программа и материалы',
                'url' => 'https://1c.ru/bf/2024/default.jsp',
                'domain' => '1c.ru',
                'priority' => 98,
            ],
            [
                'name' => '1С:ERP 2023 — программа и материалы',
                'url' => 'https://1c.ru/bf/2023/default.jsp',
                'domain' => '1c.ru',
                'priority' => 97,
            ],
            [
                'name' => '1С:ERP 2022 — программа и материалы',
                'url' => 'https://1c.ru/bf/2022/default.jsp',
                'domain' => '1c.ru',
                'priority' => 96,
            ],
            [
                'name' => '1С:ERP 2021 — программа и материалы',
                'url' => 'https://1c.ru/bf/2021/default.jsp',
                'domain' => '1c.ru',
                'priority' => 95,
            ],
            [
                'name' => '1С:ERP / Управление холдингом — презентации',
                'url' => 'https://v8.1c.ru/cpm-erp/poleznye-materialy/presentations/',
                'domain' => 'v8.1c.ru',
                'priority' => 85,
            ],
            [
                'name' => 'CNews FORUM Кейсы 2026 — ИТ-директор Автозавода',
                'url' => 'https://zoom.cnews.ru/soft/news/top/2026-07-16_iz-za_sanktsij_poteryali_bolshuyu',
                'domain' => 'zoom.cnews.ru',
                'priority' => 90,
            ],
            [
                'name' => 'CNews — ИТ-директор ОСК и ИИ',
                'url' => 'https://www.cnews.ru/news/top/2026-07-03_it-direktor_osk_rasskazal',
                'domain' => 'www.cnews.ru',
                'priority' => 89,
            ],
            [
                'name' => 'CNews FORUM 2015 — мобильные технологии',
                'url' => 'https://www.cnews.ru/articles/2015-11-24_mobilnye_tehnologii_na_cnews_forum_2015_kuda_privedut_mirovye/2',
                'domain' => 'www.cnews.ru',
                'priority' => 75,
            ],
            [
                'name' => 'Global CIO — Pharma 2026',
                'url' => 'https://ru.globalcio.ru/pharma26_dm1',
                'domain' => 'ru.globalcio.ru',
                'priority' => 70,
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

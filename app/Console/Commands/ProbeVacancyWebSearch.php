<?php

namespace App\Console\Commands;

use App\Services\BingRssSearchProvider;
use Illuminate\Console\Command;
use Throwable;

class ProbeVacancyWebSearch extends Command
{
    protected $signature = 'vacancy:web:probe';

    protected $description = 'Run a safe public-web search probe for Vacancy Source';

    public function handle(BingRssSearchProvider $search): int
    {
        try {
            $results = $search->search('site:hh.ru/vacancy JavaScript React', 3);
        } catch (Throwable $exception) {
            $this->warn('vacancy_web_search=failed '.mb_strimwidth($exception->getMessage(), 0, 240, '…'));

            return self::SUCCESS;
        }

        $this->info('vacancy_web_search=ok results='.count($results));

        foreach ($results as $result) {
            $host = parse_url($result['url'], PHP_URL_HOST);
            $this->line('source_host='.(is_string($host) ? $host : 'unknown'));
        }

        return self::SUCCESS;
    }
}

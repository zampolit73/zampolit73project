<?php

namespace App\Jobs;

use App\Models\VacancyInvestigation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class RunVacancyInvestigation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 300;
    public bool $failOnTimeout = true;

    public function __construct(public int $investigationId)
    {
        $this->onQueue('vacancy-source');
    }

    public function handle(): void
    {
        $claimed = VacancyInvestigation::query()
            ->whereKey($this->investigationId)
            ->where('status', 'queued')
            ->update([
                'status' => 'running',
                'progress_stage' => 'starting',
                'progress_text' => 'Запускаю расследование',
                'started_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $this->advance('telegram_search', 'Проверяю Telegram-источники — технический демо-этап');
        $this->pause();

        $this->advance('web_search', 'Проверяю веб-источники — технический демо-этап');
        $this->pause();

        $this->advance('candidate_analysis', 'Собираю кандидатов и объяснение — технический демо-этап');
        $this->pause();

        VacancyInvestigation::query()
            ->whereKey($this->investigationId)
            ->update([
                'status' => 'completed',
                'progress_stage' => 'completed',
                'progress_text' => 'Готово',
                'result_summary' => 'Технический каркас расследования работает. Реальный поиск по Telegram и вебу будет подключён следующими итерациями.',
                'finished_at' => now(),
            ]);
    }

    public function failed(?Throwable $exception): void
    {
        VacancyInvestigation::query()
            ->whereKey($this->investigationId)
            ->where('status', '!=', 'cancelled')
            ->update([
                'status' => 'failed',
                'progress_stage' => 'failed',
                'progress_text' => Str::limit(
                    $exception?->getMessage() ?: 'Расследование завершилось с ошибкой.',
                    480,
                ),
                'finished_at' => now(),
            ]);
    }

    private function advance(string $stage, string $message): void
    {
        VacancyInvestigation::query()
            ->whereKey($this->investigationId)
            ->update([
                'progress_stage' => $stage,
                'progress_text' => $message,
            ]);
    }

    private function pause(): void
    {
        $milliseconds = max(0, (int) config('vacancy_source.demo_stage_delay_ms', 700));

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}

<?php

namespace App\Jobs;

use App\Models\InvestigationCandidate;
use App\Models\InvestigationSource;
use App\Models\VacancyInvestigation;
use App\Services\TelegramBotClient;
use App\Services\VacancySignalExtractor;
use App\Services\VacancyTelegramResultFormatter;
use App\Services\VacancyWebResearchService;
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

    public function handle(
        TelegramBotClient $telegramBot,
        VacancyWebResearchService $research,
        VacancySignalExtractor $extractor,
        VacancyTelegramResultFormatter $formatter,
    ): void {
        $claimed = VacancyInvestigation::query()
            ->whereKey($this->investigationId)
            ->where('status', 'queued')
            ->update([
                'status' => 'running',
                'progress_stage' => 'starting',
                'progress_text' => 'Разбираю вакансию на сильные сигналы',
                'started_at' => now(),
            ]);

        if ($claimed === 0) {
            return;
        }

        $investigation = VacancyInvestigation::query()
            ->with('user.telegramAccount')
            ->findOrFail($this->investigationId);

        $telegramChatId = $investigation->input_source === 'telegram'
            ? $investigation->user?->telegramAccount?->telegram_chat_id
            : null;

        $this->notifyTelegram(
            $telegramBot,
            $telegramChatId,
            'Проверка #'.$investigation->id.': разбираю вакансию и выделяю редкие требования.',
        );

        $this->advance(
            'telegram_search',
            'Telegram-корпус вакансий ещё не подключён — этот источник пока пропускаю',
        );

        $this->advance('web_search', 'Ищу совпадения в открытом вебе по редким фразам и стеку');
        $this->notifyTelegram(
            $telegramBot,
            $telegramChatId,
            'Проверка #'.$investigation->id.': ищу совпадения в открытом вебе.',
        );

        $result = $research->research($investigation->input_text);

        $this->advance('candidate_analysis', 'Проверяю кандидатов, источники и силу совпадений');
        $this->notifyTelegram(
            $telegramBot,
            $telegramChatId,
            'Проверка #'.$investigation->id.': нашёл источники, проверяю кандидатов и уверенность.',
        );

        InvestigationSource::query()
            ->where('investigation_id', $investigation->id)
            ->delete();

        InvestigationCandidate::query()
            ->where('investigation_id', $investigation->id)
            ->delete();

        $candidateIds = [];

        foreach ($result['candidates'] as $index => $candidate) {
            $record = InvestigationCandidate::query()->create([
                'investigation_id' => $investigation->id,
                'company_name' => $candidate['company_name'],
                'candidate_type' => $candidate['candidate_type'],
                'confidence' => $candidate['confidence'],
                'is_end_client' => $candidate['is_end_client'],
                'rank' => $index + 1,
                'explanation' => $candidate['explanation'],
            ]);

            $candidateIds[$this->companyKey($candidate['company_name'], $extractor)] = $record->id;
        }

        foreach ($result['sources'] as $source) {
            $candidateId = null;

            if ($source['candidate_name']) {
                $candidateId = $candidateIds[$this->companyKey($source['candidate_name'], $extractor)] ?? null;
            }

            InvestigationSource::query()->create([
                'investigation_id' => $investigation->id,
                'candidate_id' => $candidateId,
                'provider' => $source['provider'],
                'title' => Str::limit($source['title'], 500, ''),
                'url' => $source['url'],
                'snippet' => Str::limit($source['snippet'], 1800, '…'),
                'search_query' => Str::limit($source['search_query'], 500, ''),
                'evidence_score' => $source['evidence_score'],
            ]);
        }

        $status = $result['partial'] ? 'partial' : 'completed';

        VacancyInvestigation::query()
            ->whereKey($this->investigationId)
            ->update([
                'normalized_text' => $result['signals']['normalized_text'],
                'fingerprint' => $result['signals']['fingerprint'],
                'status' => $status,
                'progress_stage' => 'completed',
                'progress_text' => $result['partial'] ? 'Готово частично' : 'Готово',
                'result_summary' => $result['summary'],
                'finished_at' => now(),
            ]);

        $completedInvestigation = VacancyInvestigation::query()
            ->with([
                'candidates' => fn ($query) => $query->orderBy('rank'),
                'sources' => fn ($query) => $query->orderByDesc('evidence_score'),
            ])
            ->findOrFail($investigation->id);

        $this->notifyTelegram(
            $telegramBot,
            $telegramChatId,
            $formatter->format($completedInvestigation),
        );
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

        $investigation = VacancyInvestigation::query()
            ->with('user.telegramAccount')
            ->find($this->investigationId);

        if (
            config('services.telegram.push_enabled')
            && $investigation?->input_source === 'telegram'
            && $investigation->user?->telegramAccount?->telegram_chat_id
        ) {
            app(TelegramBotClient::class)->sendMessage(
                $investigation->user->telegramAccount->telegram_chat_id,
                'Проверка #'.$investigation->id.' завершилась с ошибкой. Попробуй запустить её ещё раз позже.',
            );
        }
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

    private function notifyTelegram(TelegramBotClient $bot, int|string|null $chatId, string $message): void
    {
        if (! config('services.telegram.push_enabled')) {
            return;
        }

        if ($chatId !== null) {
            $bot->sendMessage($chatId, $message);
        }
    }

    private function companyKey(string $company, VacancySignalExtractor $extractor): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $extractor->normalize($company)) ?: $company;
    }
}

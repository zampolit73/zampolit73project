<?php

namespace App\Console\Commands;

use App\Models\TelegramInvite;
use App\Models\UserTelegramAccount;
use App\Services\TelegramBotClient;
use Illuminate\Console\Command;

class DiagnoseTelegramBot extends Command
{
    protected $signature = 'telegram:bot:diagnose';

    protected $description = 'Print safe Telegram Bot diagnostics without exposing secrets';

    public function handle(TelegramBotClient $bot): int
    {
        $this->line('Telegram Bot diagnostics');
        $this->line('token_configured='.(filled(config('services.telegram.token')) ? 'yes' : 'no'));
        $this->line('webhook_secret_configured='.(filled(config('services.telegram.webhook_secret')) ? 'yes' : 'no'));
        $this->line('linked_accounts='.UserTelegramAccount::query()->count());
        $this->line('used_invites='.TelegramInvite::query()->whereNotNull('used_at')->count());
        $this->line('unused_invites='.TelegramInvite::query()->whereNull('used_at')->count());

        $probe = $bot->probe();

        if ($probe['ok']) {
            $username = $probe['username'] ? '@'.$probe['username'] : 'unknown';
            $this->info('outbound_api=ok '.$username);

            return self::SUCCESS;
        }

        $this->warn('outbound_api=failed '.$probe['description']);

        return self::SUCCESS;
    }
}

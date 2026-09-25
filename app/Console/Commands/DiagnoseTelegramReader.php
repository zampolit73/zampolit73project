<?php

namespace App\Console\Commands;

use App\Services\TelegramReaderClient;
use Illuminate\Console\Command;
use RuntimeException;

class DiagnoseTelegramReader extends Command
{
    protected $signature = 'telegram-reader:diagnose';

    protected $description = 'Print safe Telegram Reader service status';

    public function handle(TelegramReaderClient $reader): int
    {
        try {
            $status = $reader->status();
        } catch (RuntimeException $exception) {
            $this->warn('telegram_reader=unavailable '.$exception->getMessage());

            return self::SUCCESS;
        }

        $this->line('telegram_reader=ok');
        $this->line('connected='.(($status['connected'] ?? false) ? 'yes' : 'no'));
        $this->line('authorized='.(($status['authorized'] ?? false) ? 'yes' : 'no'));
        $this->line('auth_state='.(string) ($status['auth_state'] ?? 'unknown'));
        $this->line('selected_folder='.(
            is_array($status['selected_folder'] ?? null)
                ? (string) ($status['selected_folder']['title'] ?? 'selected')
                : 'none'
        ));
        $this->line('chat_count='.(int) ($status['chat_count'] ?? 0));
        $this->line('indexed_message_count='.(int) ($status['indexed_message_count'] ?? 0));
        $this->line('fts_enabled='.(($status['fts_enabled'] ?? false) ? 'yes' : 'no'));

        if ($status['last_error'] ?? null) {
            $this->warn('last_error='.(string) $status['last_error']);
        }

        return self::SUCCESS;
    }
}

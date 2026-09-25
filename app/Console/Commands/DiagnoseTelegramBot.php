<?php

namespace App\Console\Commands;

use App\Models\TelegramInvite;
use App\Models\UserTelegramAccount;
use App\Services\TelegramBotClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class DiagnoseTelegramBot extends Command
{
    protected $signature = 'telegram:bot:diagnose';

    protected $description = 'Print safe Telegram Bot and network diagnostics without exposing secrets';

    public function handle(TelegramBotClient $bot): int
    {
        $this->line('Telegram Bot diagnostics');
        $this->line('token_configured='.(filled(config('services.telegram.token')) ? 'yes' : 'no'));
        $this->line('webhook_secret_configured='.(filled(config('services.telegram.webhook_secret')) ? 'yes' : 'no'));
        $this->line('linked_accounts='.UserTelegramAccount::query()->count());
        $this->line('used_invites='.TelegramInvite::query()->whereNotNull('used_at')->count());
        $this->line('unused_invites='.TelegramInvite::query()->whereNull('used_at')->count());

        if (app()->environment('production')) {
            $this->newLine();
            $this->line('Network diagnostics');
            $this->line('default_route='.$this->commandOutput('ip -4 route show default'));
            $this->line('ufw_status='.$this->commandOutput('ufw status verbose'));
            $this->line('iptables_output='.$this->commandOutput('iptables -S OUTPUT'));
            $this->line('iptables_input='.$this->commandOutput('iptables -S INPUT'));

            $telegramIps = $this->telegramIpv4Addresses();

            if ($telegramIps === []) {
                $this->warn('telegram_dns=failed');
            } else {
                $this->line('telegram_dns=ok '.implode(',', $telegramIps));
            }

            foreach ($telegramIps as $ip) {
                $this->line('telegram_route_'.$ip.'='.$this->commandOutput('ip -4 route get '.escapeshellarg($ip)));
                $this->line('telegram_tcp_'.$ip.'='.$this->tcpProbe($ip, 443));

                $curl = sprintf(
                    "curl -4 -sS -o /dev/null -w 'http=%%{http_code} remote=%%{remote_ip} connect=%%{time_connect} tls=%%{time_appconnect} total=%%{time_total}' --connect-timeout 5 --max-time 12 --resolve api.telegram.org:443:%s https://api.telegram.org/",
                    escapeshellarg($ip),
                );
                $this->line('telegram_tls_'.$ip.'='.$this->commandOutput($curl));
            }

            $this->line('github_https='.$this->httpsProbe('https://github.com/'));
            $this->line('cloudflare_https='.$this->httpsProbe('https://www.cloudflare.com/'));
            $this->line('telegram_https='.$this->httpsProbe('https://api.telegram.org/'));
        }

        $probe = $bot->probe();

        if ($probe['ok']) {
            $username = $probe['username'] ? '@'.$probe['username'] : 'unknown';
            $this->info('outbound_api=ok '.$username);
        } else {
            $this->warn('outbound_api=failed '.$probe['description']);
        }

        return self::SUCCESS;
    }

    private function telegramIpv4Addresses(): array
    {
        $records = dns_get_record('api.telegram.org', DNS_A);

        if (! is_array($records)) {
            return [];
        }

        $ips = [];

        foreach ($records as $record) {
            $ip = $record['ip'] ?? null;

            if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $ip;
            }
        }

        return array_values(array_unique($ips));
    }

    private function tcpProbe(string $ip, int $port): string
    {
        $errno = 0;
        $error = '';
        $started = microtime(true);

        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $ip, $port),
            $errno,
            $error,
            5,
            STREAM_CLIENT_CONNECT,
        );

        $elapsed = round(microtime(true) - $started, 3);

        if (is_resource($socket)) {
            fclose($socket);

            return 'ok time='.$elapsed.'s';
        }

        return 'failed errno='.$errno.' time='.$elapsed.'s error='.$this->sanitize($error);
    }

    private function httpsProbe(string $url): string
    {
        $started = microtime(true);

        try {
            $response = Http::withOptions(['force_ip_resolve' => 'v4'])
                ->connectTimeout(5)
                ->timeout(12)
                ->get($url);
        } catch (ConnectionException $exception) {
            return 'failed time='.round(microtime(true) - $started, 3).'s '.$this->sanitize($exception->getMessage());
        }

        return 'ok status='.$response->status().' time='.round(microtime(true) - $started, 3).'s';
    }

    private function commandOutput(string $command): string
    {
        if (! function_exists('shell_exec')) {
            return 'unavailable';
        }

        $output = @shell_exec($command.' 2>&1');
        $output = trim((string) $output);

        if ($output === '') {
            return 'empty';
        }

        return $this->sanitize(preg_replace('/\s+/', ' ', $output) ?: $output);
    }

    private function sanitize(string $value): string
    {
        $token = trim((string) config('services.telegram.token'));

        if ($token !== '') {
            $value = str_replace($token, '[redacted]', $value);
        }

        return mb_strimwidth($value, 0, 1200, '…');
    }
}

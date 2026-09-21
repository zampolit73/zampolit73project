<?php

namespace App\Console\Commands;

use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Console\Command;

class SendDeployPush extends Command
{
    protected $signature = 'push:deploy-success';
    protected $description = 'Send a successful deployment notification to administrators';

    public function handle(WebPushService $webPush): int
    {
        $subscriptions = PushSubscription::query()
            ->whereHas('user', fn ($query) => $query->where('role', 'admin'))
            ->get();

        $sent = 0;

        foreach ($subscriptions as $subscription) {
            if ($webPush->sendToSubscription($subscription, [
                'title' => 'Zampolit73 — деплой готов',
                'body' => 'Новая версия успешно прошла проверку и опубликована.',
                'url' => '/',
            ])) {
                $sent++;
            }
        }

        $this->info("Administrator push subscriptions: {$subscriptions->count()}; sent: {$sent}");

        return self::SUCCESS;
    }
}

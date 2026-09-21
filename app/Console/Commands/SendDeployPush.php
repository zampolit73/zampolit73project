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
        $sent = 0;
        foreach (PushSubscription::whereHas('user', fn ($query) => $query->where('role', 'admin'))->get() as $subscription) {
            if ($webPush->sendToSubscription($subscription, [
                'title' => config('app.name'),
                'body' => 'Деплой успешно завершён',
                'url' => '/',
            ])) $sent++;
        }
        $this->info("Deployment push sent: {$sent}");
        return self::SUCCESS;
    }
}

<?php

namespace App\Services;

use App\Models\PushSubscription;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function sendToSubscription(PushSubscription $storedSubscription, array $payload): bool
    {
        $publicKey = config('webpush.public_key');
        $privateKey = config('webpush.private_key');
        $subject = config('webpush.subject');

        if (!$publicKey || !$privateKey || !$subject) {
            return false;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $subject,
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ], [
            'TTL' => 300,
            'urgency' => 'normal',
        ]);

        $report = $webPush->sendOneNotification(
            Subscription::create([
                'endpoint' => $storedSubscription->endpoint,
                'keys' => [
                    'p256dh' => $storedSubscription->public_key,
                    'auth' => $storedSubscription->auth_token,
                ],
                'contentEncoding' => $storedSubscription->content_encoding ?: 'aes128gcm',
            ]),
            json_encode($payload, JSON_THROW_ON_ERROR),
        );

        if ($report->isSuccess()) {
            return true;
        }

        if ($report->isSubscriptionExpired()) {
            $storedSubscription->delete();
        }

        return false;
    }
}

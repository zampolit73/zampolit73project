<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

$envPath = $argv[1] ?? null;
$subject = $argv[2] ?? null;

if (!$envPath || !$subject) {
    fwrite(STDERR, "Usage: php scripts/ensure-vapid.php <env-path> <subject>\n");
    exit(2);
}

$env = file_get_contents($envPath);
if ($env === false) {
    fwrite(STDERR, "Unable to read production .env\n");
    exit(3);
}

$hasPublic = preg_match('/^VAPID_PUBLIC_KEY=.+$/m', $env) === 1;
$hasPrivate = preg_match('/^VAPID_PRIVATE_KEY=.+$/m', $env) === 1;

$setValue = static function (string $content, string $key, string $value): string {
    $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
    $replacement = $key.'='.$value;

    $updated = preg_replace($pattern, $replacement, $content, 1, $count);
    if ($updated === null) {
        throw new RuntimeException("Unable to update {$key}");
    }

    if ($count === 0) {
        $updated = rtrim($updated)."\n".$replacement."\n";
    }

    return $updated;
};

$env = $setValue($env, 'VAPID_SUBJECT', $subject);

if (!$hasPublic || !$hasPrivate) {
    $keys = VAPID::createVapidKeys();
    $env = $setValue($env, 'VAPID_PUBLIC_KEY', $keys['publicKey']);
    $env = $setValue($env, 'VAPID_PRIVATE_KEY', $keys['privateKey']);
}

if (file_put_contents($envPath, $env) === false) {
    fwrite(STDERR, "Unable to write production .env\n");
    exit(4);
}

echo "VAPID configuration is ready.\n";

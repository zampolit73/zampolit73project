<?php

declare(strict_types=1);

/**
 * Online SQLite snapshot for production deployments.
 *
 * Usage: php scripts/backup-sqlite.php SOURCE.sqlite BACKUP.sqlite
 * Never places credentials or database contents in GitHub logs.
 */

if (PHP_SAPI !== 'cli' || $argc !== 3) {
    fwrite(STDERR, "Usage: php backup-sqlite.php SOURCE.sqlite BACKUP.sqlite\n");
    exit(2);
}

[$script, $sourceFile, $backupFile] = $argv;

if (! is_file($sourceFile) || filesize($sourceFile) === 0) {
    fwrite(STDERR, "Source database missing or empty; refusing to deploy without backup.\n");
    exit(3);
}

if (file_exists($backupFile)) {
    fwrite(STDERR, "Backup destination already exists; refusing to overwrite it.\n");
    exit(4);
}

try {
    $source = new SQLite3($sourceFile, SQLITE3_OPEN_READONLY);
    $source->busyTimeout(15000);
    $destination = new SQLite3($backupFile, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

    if (! $source->backup($destination)) {
        throw new RuntimeException('SQLite backup operation failed');
    }

    if ($destination->querySingle('PRAGMA quick_check') !== 'ok') {
        throw new RuntimeException('SQLite backup integrity check failed');
    }

    $destination->close();
    $source->close();
    echo "Online SQLite snapshot verified.\n";
} catch (Throwable $exception) {
    if (isset($destination)) {
        $destination->close();
    }
    if (isset($source)) {
        $source->close();
    }
    @unlink($backupFile);
    fwrite(STDERR, "Backup could not be verified; deployment stopped: ".$exception->getMessage()."\n");
    exit(5);
}

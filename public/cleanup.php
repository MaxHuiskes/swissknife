<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

define('STORAGE_IN', '/var/www/swissknife/storage/uploads/');
define('STORAGE_OUT', '/var/www/swissknife/storage/outputs/');
define('MAX_AGE_SECONDS', 30 * 60);

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function purge_old_files(string $dir, int $maxAge): int {
    if (!is_dir($dir)) {
        return 0;
    }

    $removed = 0;
    $cutoff = time() - $maxAge;
    $entries = scandir($dir);
    if ($entries === false) {
        return 0;
    }

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . $name;
        if (!is_file($path)) {
            continue;
        }
        $mtime = filemtime($path);
        if ($mtime !== false && $mtime < $cutoff) {
            if (@unlink($path)) {
                $removed++;
            }
        }
    }

    return $removed;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Method Not Allowed']);
}

$removed = purge_old_files(STORAGE_IN, MAX_AGE_SECONDS)
         + purge_old_files(STORAGE_OUT, MAX_AGE_SECONDS);

respond(200, [
    'ok' => true,
    'removed' => $removed,
    'message' => "Cleanup completed. Removed {$removed} file(s) older than 30 minutes.",
]);

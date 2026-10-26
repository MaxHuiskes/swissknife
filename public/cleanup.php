<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Method Not Allowed']);
}

$script = '/var/www/swissknife/scripts/cleanup.sh';

if (!is_file($script) || !is_executable($script)) {
    respond(500, ['error' => 'Cleanup script missing or not executable.']);
}

$cmd = escapeshellarg($script);
$descriptor = [
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];
$proc = proc_open($cmd, $descriptor, $pipes);
if (!is_resource($proc)) {
    respond(500, ['error' => 'Failed to start cleanup.']);
}

$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$code = proc_close($proc);

if ($code !== 0) {
    respond(500, [
        'error' => 'Cleanup failed.',
        'detail' => trim($stderr !== false && $stderr !== '' ? $stderr : $stdout),
    ]);
}

respond(200, ['ok' => true, 'message' => 'Cleanup completed (files older than 30 minutes removed).']);

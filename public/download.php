<?php
declare(strict_types=1);

$id = $_GET['id'] ?? '';
$filename = $_GET['name'] ?? 'download';

// Prevent traversal attacks: ensure file is solely a basename and matches safe naming
if (!$id || basename($id) !== $id || !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $id)) {
    http_response_code(400);
    exit('Invalid file ID');
}

$path = '/var/www/swissknife/storage/outputs/' . $id;

if (!file_exists($path)) {
    http_response_code(404);
    exit('Requested file has expired or does not exist.');
}

$safeDownloadName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $filename);

header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($path));

readfile($path);
exit;
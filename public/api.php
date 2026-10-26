<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

define('STORAGE_IN', '/var/www/swissknife/storage/uploads/');
define('STORAGE_OUT', '/var/www/swissknife/storage/outputs/');

function respond(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Method Not Allowed']);
}

$action = filter_input(INPUT_POST, 'action', FILTER_DEFAULT);
if (!$action) {
    respond(400, ['error' => 'Action required']);
}

// Generate an isolated processing token
$jobId = bin2hex(random_bytes(16));

function exec_cmd(string $cmd): bool {
    $descriptor = [
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w']  // stderr
    ];
    $proc = proc_open($cmd, $descriptor, $pipes);
    if (!is_resource($proc)) return false;
    
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($proc) === 0;
}

try {
    switch ($action) {
        case 'pdf_merge':
            if (empty($_FILES['files']['tmp_name']) || count($_FILES['files']['tmp_name']) < 2) {
                respond(400, ['error' => 'At least two PDF files are required for merging.']);
            }
            $inputPaths = [];
            foreach ($_FILES['files']['tmp_name'] as $idx => $tmpName) {
                $safeName = STORAGE_IN . "{$jobId}_{$idx}.pdf";
                move_uploaded_file($tmpName, $safeName);
                $inputPaths[] = escapeshellarg($safeName);
            }
            $outName = "merged_{$jobId}.pdf";
            $cmd = "qpdf --empty --pages " . implode(' ', $inputPaths) . " -- " . escapeshellarg(STORAGE_OUT . $outName);
            
            if (exec_cmd($cmd)) {
                respond(200, ['download_id' => $outName, 'filename' => 'merged.pdf']);
            }
            respond(500, ['error' => 'PDF merge failed.']);
            break;

        case 'pdf_compress':
            if (empty($_FILES['file']['tmp_name'])) respond(400, ['error' => 'No file uploaded.']);
            $in = STORAGE_IN . "{$jobId}.pdf";
            $outName = "compressed_{$jobId}.pdf";
            $out = STORAGE_OUT . $outName;
            move_uploaded_file($_FILES['file']['tmp_name'], $in);

            // Ghostscript preset for strong compression (ebook: ~150 DPI)
            $cmd = sprintf(
                "gs -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dQUIET -dBATCH -sOutputFile=%s %s",
                escapeshellarg($out),
                escapeshellarg($in)
            );
            if (exec_cmd($cmd)) {
                respond(200, ['download_id' => $outName, 'filename' => 'compressed.pdf']);
            }
            respond(500, ['error' => 'Compression failed.']);
            break;

        case 'pdf_unlock':
            if (empty($_FILES['file']['tmp_name'])) respond(400, ['error' => 'No file uploaded.']);
            $pass = $_POST['password'] ?? '';
            $in = STORAGE_IN . "{$jobId}.pdf";
            $outName = "unlocked_{$jobId}.pdf";
            $out = STORAGE_OUT . $outName;
            move_uploaded_file($_FILES['file']['tmp_name'], $in);

            $cmd = sprintf(
                "qpdf --password=%s --decrypt %s %s",
                escapeshellarg($pass),
                escapeshellarg($in),
                escapeshellarg($out)
            );
            if (exec_cmd($cmd)) {
                respond(200, ['download_id' => $outName, 'filename' => 'unlocked.pdf']);
            }
            respond(400, ['error' => 'Failed to unlock PDF. Please verify your password.']);
            break;

        case 'image_convert':
            if (empty($_FILES['file']['tmp_name'])) respond(400, ['error' => 'No file uploaded.']);
            $format = strtolower((string)($_POST['format'] ?? 'webp'));
            $allowed = ['webp', 'jpg', 'jpeg', 'png', 'svg'];
            if (!in_array($format, $allowed, true)) respond(400, ['error' => 'Invalid output format.']);

            $in = STORAGE_IN . "{$jobId}_src";
            $outName = "converted_{$jobId}.{$format}";
            $out = STORAGE_OUT . $outName;
            move_uploaded_file($_FILES['file']['tmp_name'], $in);

            $cmd = sprintf("magick %s %s", escapeshellarg($in), escapeshellarg($out));
            if (exec_cmd($cmd)) {
                respond(200, ['download_id' => $outName, 'filename' => "converted.{$format}"]);
            }
            respond(500, ['error' => 'Image conversion failed.']);
            break;

        case 'image_strip_exif':
            if (empty($_FILES['file']['tmp_name'])) respond(400, ['error' => 'No file uploaded.']);
            $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION) ?: 'jpg';
            $in = STORAGE_IN . "{$jobId}.{$ext}";
            $outName = "stripped_{$jobId}.{$ext}";
            $out = STORAGE_OUT . $outName;
            move_uploaded_file($_FILES['file']['tmp_name'], $in);

            // -strip removes all profiles, comments, and metadata tags
            $cmd = sprintf("magick %s -strip %s", escapeshellarg($in), escapeshellarg($out));
            if (exec_cmd($cmd)) {
                respond(200, ['download_id' => $outName, 'filename' => "clean.{$ext}"]);
            }
            respond(500, ['error' => 'EXIF stripping failed.']);
            break;

        case 'video_to_gif':
            if (empty($_FILES['file']['tmp_name'])) respond(400, ['error' => 'No file uploaded.']);
            $fps = (int)($_POST['fps'] ?? 12);
            $width = (int)($_POST['width'] ?? 480);
            $fps = max(5, min(24, $fps));
            $width = max(240, min(800, $width));

            $in = STORAGE_IN . "{$jobId}_vid";
            $outName = "clip_{$jobId}.gif";
            $out = STORAGE_OUT . $outName;
            move_uploaded_file($_FILES['file']['tmp_name'], $in);

            // Two-pass palette generation for sharp, optimized GIF output
            $filter = "fps={$fps},scale={$width}:-1:flags=lanczos";
            $cmd = sprintf(
                "ffmpeg -y -i %s -vf \"%s,palettegen=stats_mode=diff\" -f image2 -c:v png - | ffmpeg -y -i %s -i - -lavfi \"%s [x]; [x][1:v] paletteuse=dither=bayer:bayer_scale=3\" %s",
                escapeshellarg($in),
                $filter,
                escapeshellarg($in),
                $filter,
                escapeshellarg($out)
            );
            if (exec_cmd($cmd)) {
                respond(200, ['download_id' => $outName, 'filename' => 'output.gif']);
            }
            respond(500, ['error' => 'GIF conversion failed.']);
            break;

        default:
            respond(400, ['error' => 'Unknown operation requested.']);
    }
} catch (Throwable $e) {
    respond(500, ['error' => 'Internal server error: ' . $e->getMessage()]);
}
<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth();
session_write_close();
set_time_limit(0);

$materialId = (int) ($_GET['material_id'] ?? 0);
if ($materialId <= 0) {
    http_response_code(400);
    exit('Invalid material id.');
}

$stmt = $pdo->prepare(
    'SELECT m.id, m.title, m.type, m.file_name, m.file_path, m.is_published, m.created_by, u.role
     FROM materials m
     JOIN users u ON u.id = m.created_by
     WHERE m.id = :id
     LIMIT 1'
);
$stmt->execute(['id' => $materialId]);
$material = $stmt->fetch();

if (!$material || empty($material['file_path'])) {
    http_response_code(404);
    exit('File not found.');
}

$relativePath = (string) $material['file_path'];
$absolutePath = APP_ROOT . '/' . ltrim($relativePath, '/');

if (!is_file($absolutePath) || !is_readable($absolutePath)) {
    http_response_code(404);
    exit('File not found.');
}

$fileSize = filesize($absolutePath);
if ($fileSize === false) {
    http_response_code(500);
    exit('Unable to read file size.');
}

$extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
$mimeMap = [
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'mov' => 'video/quicktime',
    'mkv' => 'video/x-matroska',
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
];

$mimeType = $mimeMap[$extension] ?? 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo !== false) {
        $detected = finfo_file($finfo, $absolutePath);
        if (is_string($detected) && $detected !== '') {
            $mimeType = $detected;
        }
        finfo_close($finfo);
    }
}

$download = isset($_GET['download']) && $_GET['download'] === '1';
$filename = (string) ($material['file_name'] ?: basename($absolutePath));
$disposition = $download ? 'attachment' : 'inline';
$safeFilename = str_replace(['"', "\r", "\n"], '', $filename);

header('X-Content-Type-Options: nosniff');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"');

$range = $_SERVER['HTTP_RANGE'] ?? '';
if ($range !== '' && preg_match('/bytes=(\d+)-(\d*)/', $range, $matches) === 1) {
    $start = (int) $matches[1];
    $end = $matches[2] !== '' ? (int) $matches[2] : ($fileSize - 1);

    if ($start < 0 || $end < $start || $start >= $fileSize) {
        header('Content-Range: bytes */' . $fileSize);
        http_response_code(416);
        exit;
    }

    $end = min($end, $fileSize - 1);
    $length = $end - $start + 1;

    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    header('Content-Length: ' . $length);
    http_response_code(206);

    $handle = fopen($absolutePath, 'rb');
    if ($handle === false) {
        http_response_code(500);
        exit('Unable to open file.');
    }

    fseek($handle, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($handle)) {
        $chunkSize = min(8192, $remaining);
        $buffer = fread($handle, $chunkSize);
        if ($buffer === false || $buffer === '') {
            break;
        }

        echo $buffer;
        $remaining -= strlen($buffer);
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        flush();
    }

    fclose($handle);
    exit;
}

header('Content-Length: ' . $fileSize);
readfile($absolutePath);
exit;
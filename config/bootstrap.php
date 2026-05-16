<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

define('APP_ROOT', dirname(__DIR__));

$envFile = APP_ROOT . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
	$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	foreach ($lines as $line) {
		$line = trim((string) $line);
		if ($line === '' || str_starts_with($line, '#') || strpos($line, '=') === false) {
			continue;
		}

		[$key, $value] = explode('=', $line, 2);
		$key = trim($key);
		$value = trim($value);

		if ($key === '') {
			continue;
		}

		if (
			(str_starts_with($value, '"') && str_ends_with($value, '"'))
			|| (str_starts_with($value, "'") && str_ends_with($value, "'"))
		) {
			$value = substr($value, 1, -1);
		}

		putenv($key . '=' . $value);
		$_ENV[$key] = $value;
		$_SERVER[$key] = $value;
	}
}

require_once APP_ROOT . '/app/helpers.php';
require_once APP_ROOT . '/config/database.php';

chemnama_start_session();

$pdo = null;
try {
	$pdo = chemnama_pdo();
} catch (Throwable $exception) {
	http_response_code(503);
	$message = 'Tidak dapat terhubung ke database MySQL. Pastikan layanan MySQL/XAMPP sedang aktif, lalu periksa CHEMNAMA_DB_HOST, CHEMNAMA_DB_PORT, dan kredensial database.';

	echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Database tidak tersedia</title><style>body{font-family:Arial,Helvetica,sans-serif;background:#f5f7fb;color:#1f2937;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}.card{max-width:720px;width:100%;background:#fff;border:1px solid #e5e7eb;border-radius:16px;box-shadow:0 20px 50px rgba(15,23,42,.08);padding:28px}.badge{display:inline-block;background:#fee2e2;color:#991b1b;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.02em;text-transform:uppercase}.title{margin:16px 0 12px;font-size:28px;line-height:1.2}.text{margin:0 0 14px;font-size:16px;line-height:1.7;color:#4b5563}.list{margin:16px 0 0;padding-left:20px;color:#374151;line-height:1.8}.code{margin-top:18px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;padding:14px 16px;overflow:auto;color:#111827;font-size:14px}</style></head><body><main class="card"><div class="badge">Database offline</div><h1 class="title">Koneksi database belum tersedia</h1><p class="text">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p class="text">Setelah MySQL aktif, muat ulang halaman ini. Jika Anda menggunakan port selain 3306, sesuaikan juga nilai port di environment atau file <code>.env</code>.</p><ul class="list"><li>Pastikan MySQL aktif di XAMPP Control Panel.</li><li>Pastikan host database benar, biasanya 127.0.0.1 atau localhost.</li><li>Pastikan nama database dan kredensial sesuai konfigurasi aplikasi.</li></ul><div class="code">' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8') . '</div></main></body></html>';
	exit;
}
$siteData = chemnama_home_data($pdo);

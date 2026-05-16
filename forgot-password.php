<?php
require_once __DIR__ . '/config/bootstrap.php';

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (chemnama_current_user()) {
    header('Location: ' . (chemnama_current_user()['role'] === 'guru' ? 'guru_pilih_kelas.php' : 'dashboard.php'));
    exit;
}

function chemnama_mask_email_address(string $email): string
{
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) {
        return '-';
    }

    [$localPart, $domainPart] = explode('@', $email, 2);
    $maskedLocal = mb_substr($localPart, 0, 1) . str_repeat('*', max(strlen($localPart) - 1, 2));
    $domainPieces = explode('.', $domainPart);
    $firstDomain = $domainPieces[0] ?? '';
    $maskedDomain = mb_substr($firstDomain, 0, 1) . str_repeat('*', max(strlen($firstDomain) - 1, 2));
    $suffix = count($domainPieces) > 1 ? '.' . implode('.', array_slice($domainPieces, 1)) : '';

    return $maskedLocal . '@' . $maskedDomain . $suffix;
}

function chemnama_clear_forgot_session(): void
{
    unset(
        $_SESSION['forgot_role'],
        $_SESSION['forgot_user_id'],
        $_SESSION['forgot_user_name'],
        $_SESSION['forgot_user_email'],
        $_SESSION['forgot_reset_code_hash'],
        $_SESSION['forgot_reset_expires_at'],
        $_SESSION['forgot_reset_attempts']
    );
}

function chemnama_send_reset_code_email(string $email, string $name, string $resetCode, ?string &$sendError = null): bool
{
    $sendError = null;
    $subject = 'Nom Comp - Kode Reset Password';
    $message = implode("\r\n", [
        'Halo ' . $name . ',',
        '',
        'Kode reset password Anda adalah: ' . $resetCode,
        'Kode ini berlaku selama 15 menit dan hanya dapat dipakai sekali.',
        '',
        'Jika Anda tidak meminta reset password, abaikan email ini.',
    ]);

    $smtpHost = trim((string) (getenv('CHEMNAMA_SMTP_HOST') ?: ''));
    $smtpPort = (int) (getenv('CHEMNAMA_SMTP_PORT') ?: 2525);
    $smtpUsername = trim((string) (getenv('CHEMNAMA_SMTP_USERNAME') ?: ''));
    $smtpPassword = trim((string) (getenv('CHEMNAMA_SMTP_PASSWORD') ?: ''));
    $smtpEncryption = strtolower(trim((string) (getenv('CHEMNAMA_SMTP_ENCRYPTION') ?: 'tls')));
    $fromEmail = trim((string) (getenv('CHEMNAMA_SMTP_FROM_EMAIL') ?: 'no-reply@nomcomp.local'));
    $fromName = trim((string) (getenv('CHEMNAMA_SMTP_FROM_NAME') ?: 'Nom Comp Support'));

    // Gmail App Password is often copied with spaces (e.g. xxxx xxxx xxxx xxxx).
    $smtpPassword = str_replace(' ', '', $smtpPassword);

    if (
        strcasecmp($smtpUsername, 'your_email@gmail.com') === 0
        || strcasecmp($smtpPassword, 'your_16_char_app_password') === 0
    ) {
        $sendError = 'SMTP belum dikonfigurasi: isi CHEMNAMA_SMTP_USERNAME dan CHEMNAMA_SMTP_PASSWORD di file .env';
        return false;
    }

    if (strcasecmp($smtpHost, 'smtp.gmail.com') === 0 && strlen($smtpPassword) < 16) {
        $sendError = 'Autentikasi Gmail gagal: gunakan App Password 16 karakter (bukan password Gmail biasa).';
        return false;
    }

    $canUseSmtp = $smtpHost !== ''
        && $smtpPort > 0
        && $smtpUsername !== ''
        && $smtpPassword !== ''
        && class_exists('PHPMailer\\PHPMailer\\PHPMailer');

    if ($canUseSmtp) {
        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->Port = $smtpPort;
            $mail->SMTPAuth = true;
            $mail->Username = $smtpUsername;
            $mail->Password = $smtpPassword;
            $mail->CharSet = 'UTF-8';

            if ($smtpEncryption === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtpEncryption === 'none') {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            } else {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }

            // For Gmail SMTP, sender should match authenticated account.
            if (strcasecmp($smtpHost, 'smtp.gmail.com') === 0) {
                $fromEmail = $smtpUsername;
            }

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($email, $name);
            $mail->Subject = $subject;
            $mail->Body = $message;

            return $mail->send();
        } catch (Throwable $exception) {
            $sendError = $exception->getMessage();
            if (
                strcasecmp($smtpHost, 'smtp.gmail.com') === 0
                && stripos($sendError, 'Could not authenticate') !== false
            ) {
                $sendError = 'Autentikasi Gmail gagal. Pastikan 2-Step Verification aktif, lalu pakai App Password 16 karakter di CHEMNAMA_SMTP_PASSWORD.';
            }
            return false;
        }
    }

    $headers = implode("\r\n", [
        'From: ' . $fromEmail,
        'Content-Type: text/plain; charset=UTF-8',
    ]);

    return @mail($email, $subject, $message, $headers);
}

$error = null;
$success = null;
$step = 1;
$role = $_GET['role'] ?? ($_SESSION['forgot_role'] ?? 'siswa');
$role = in_array($role, ['guru', 'siswa'], true) ? $role : 'siswa';
$roleLabel = chemnama_role_label($role);
$roleExample = $role === 'guru' ? '198712102010011001' : '22004567';
$maskedEmail = (string) ($_SESSION['forgot_user_email'] ?? '');
$hasSmtpCredentials = trim((string) (getenv('CHEMNAMA_SMTP_HOST') ?: '')) !== ''
    && trim((string) (getenv('CHEMNAMA_SMTP_USERNAME') ?: '')) !== ''
    && trim((string) (getenv('CHEMNAMA_SMTP_PASSWORD') ?: '')) !== '';
$hostName = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$isLocalhost = in_array($hostName, ['localhost', '127.0.0.1', '::1'], true)
    || (string) (getenv('CHEMNAMA_DEBUG_MODE') ?: '0') === '1'
    || strpos($hostName, 'localhost') !== false
    || strpos($hostName, 'xampp') !== false;
$showCodeInBrowser = $isLocalhost && !$hasSmtpCredentials;
$debugResetCode = null;

if (isset($_GET['role'])) {
    $previousRole = (string) ($_SESSION['forgot_role'] ?? '');
    // Clear only if role is different or this is a fresh start
    if ($previousRole !== $role) {
        chemnama_clear_forgot_session();
    }
    $_SESSION['forgot_role'] = $role;
}

if (isset($_SESSION['forgot_reset_code_hash'], $_SESSION['forgot_reset_expires_at'], $_SESSION['forgot_user_id'])) {
    $step = 2;
    $maskedEmail = chemnama_mask_email_address((string) ($_SESSION['forgot_user_email'] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['request_code'])) {
        $role = (string) ($_SESSION['forgot_role'] ?? $role);
        $loginId = chemnama_normalize_login_id((string) ($_POST['login_id'] ?? ''));

        if ($loginId === '') {
            $error = chemnama_role_login_label($role) . ' harus diisi.';
        } elseif (!chemnama_is_valid_login_id($role, $loginId)) {
            $error = chemnama_role_login_label($role) . ' tidak valid.';
        } else {
            $statement = $pdo->prepare('SELECT id, name, email FROM users WHERE login_id = :login_id AND role = :role LIMIT 1');
            $statement->execute([
                'login_id' => $loginId,
                'role' => $role,
            ]);
            $user = $statement->fetch();

            if (!$user) {
                $error = 'Akun dengan ' . chemnama_role_login_label($role) . ' ini tidak ditemukan.';
            } elseif (empty($user['email'])) {
                $error = 'Email akun ini belum tersedia. Hubungi admin/guru untuk pemulihan akun.';
            } else {
                $resetCode = (string) random_int(100000, 999999);
                $expiresAt = time() + 15 * 60;

                $_SESSION['forgot_role'] = $role;
                $_SESSION['forgot_user_id'] = (int) $user['id'];
                $_SESSION['forgot_user_name'] = (string) $user['name'];
                $_SESSION['forgot_user_email'] = (string) $user['email'];
                $_SESSION['forgot_reset_code_hash'] = password_hash($resetCode, PASSWORD_DEFAULT);
                $_SESSION['forgot_reset_expires_at'] = $expiresAt;
                $_SESSION['forgot_reset_attempts'] = 0;
                $maskedEmail = chemnama_mask_email_address((string) $user['email']);
                $step = 2;

                $mailSendError = null;
                $mailSent = chemnama_send_reset_code_email((string) $user['email'], (string) $user['name'], $resetCode, $mailSendError);
                if (!$mailSent && !$showCodeInBrowser) {
                    $error = 'Kode reset gagal dikirim. Periksa konfigurasi SMTP Anda.';
                    if ($mailSendError) {
                        $error .= ' Detail: ' . $mailSendError;
                    }
                    chemnama_clear_forgot_session();
                    $step = 1;
                } elseif (!$mailSent && $showCodeInBrowser) {
                    $debugResetCode = $resetCode;
                    $step = 2;
                }
                // Jangan set $success di sini - biarkan form Step 2 tampil
            }
        }
    } elseif (isset($_POST['verify_code'])) {
        $userId = (int) ($_SESSION['forgot_user_id'] ?? 0);
        $storedName = (string) ($_SESSION['forgot_user_name'] ?? '');
        $storedHash = (string) ($_SESSION['forgot_reset_code_hash'] ?? '');
        $expiresAt = (int) ($_SESSION['forgot_reset_expires_at'] ?? 0);
        $attempts = (int) ($_SESSION['forgot_reset_attempts'] ?? 0);
        $resetCode = trim((string) ($_POST['reset_code'] ?? ''));
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if ($userId === 0 || $storedName === '' || $storedHash === '' || $expiresAt === 0) {
            $error = 'Sesi reset tidak ditemukan. Silakan ulangi dari awal.';
            chemnama_clear_forgot_session();
            $step = 1;
        } elseif (time() > $expiresAt) {
            $error = 'Kode reset sudah kedaluwarsa. Silakan minta kode baru.';
            chemnama_clear_forgot_session();
            $step = 1;
        } elseif ($attempts >= 5) {
            $error = 'Terlalu banyak percobaan. Silakan minta kode baru.';
            chemnama_clear_forgot_session();
            $step = 1;
        } elseif (!password_verify($resetCode, $storedHash)) {
            $_SESSION['forgot_reset_attempts'] = $attempts + 1;
            $error = 'Kode verifikasi tidak sesuai.';
            $step = 2;
            $maskedEmail = chemnama_mask_email_address((string) ($_SESSION['forgot_user_email'] ?? ''));
        } elseif ($newPassword === '' || strlen($newPassword) < 6) {
            $error = 'Password baru minimal 6 karakter.';
            $step = 2;
            $maskedEmail = chemnama_mask_email_address((string) ($_SESSION['forgot_user_email'] ?? ''));
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Konfirmasi password tidak cocok.';
            $step = 2;
            $maskedEmail = chemnama_mask_email_address((string) ($_SESSION['forgot_user_email'] ?? ''));
        } else {
            $update = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
            $updated = $update->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $userId,
            ]);

            if ($updated) {
                chemnama_clear_forgot_session();
                $success = 'Password berhasil diubah. Silakan login dengan password baru Anda.';
                $step = 1;
            } else {
                $error = 'Gagal mengubah password. Silakan coba lagi.';
                $step = 2;
                $maskedEmail = chemnama_mask_email_address((string) ($_SESSION['forgot_user_email'] ?? ''));
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lupa Password - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .forgot-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            position: relative;
            z-index: 1;
        }

        .forgot-container {
            background: rgba(10, 14, 36, 0.18);
            border-radius: 24px;
            box-shadow: 0 24px 70px rgba(2, 6, 23, 0.28);
            border: 1px solid rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(18px);
            width: 100%;
            max-width: 560px;
            padding: 2.25rem 2rem;
        }

        .forgot-header {
            text-align: center;
            margin-bottom: 1.25rem;
        }

        .forgot-header h1 {
            font-size: 2rem;
            margin: 0 0 0.35rem 0;
            color: #f8f7ff;
            letter-spacing: -0.04em;
        }

        .forgot-header p {
            margin: 0;
            color: rgba(248, 247, 255, 0.72);
            font-size: 0.95rem;
        }

        .step-indicator {
            text-align: center;
            margin-bottom: 1rem;
            color: rgba(248, 247, 255, 0.76);
            font-size: 0.85rem;
            font-weight: 600;
            letter-spacing: 0.02em;
        }

        .forgot-form {
            display: none;
            background: rgba(10, 14, 36, 0.22);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 1.25rem;
        }

        .forgot-form.is-active {
            display: block;
        }

        .forgot-form label {
            display: block;
            margin-bottom: 1rem;
        }

        .forgot-form label span {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #f8f7ff;
            font-size: 0.95rem;
        }

        .forgot-form input {
            width: 100%;
            padding: 0.9rem 1rem;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            font-family: inherit;
            font-size: 0.95rem;
            background: rgba(10, 14, 36, 0.22);
            color: #f8f7ff;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .forgot-form input:focus {
            outline: none;
            border-color: rgba(124, 58, 237, 0.42);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.14);
        }

        .forgot-form button {
            width: 100%;
            padding: 0.95rem 1rem;
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-weight: 700;
            font-size: 0.95rem;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .forgot-form button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(124, 58, 237, 0.3);
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
            line-height: 1.45;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.14);
            color: #fecaca;
            border: 1px solid rgba(239, 68, 68, 0.28);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.14);
            color: #d1fae5;
            border: 1px solid rgba(16, 185, 129, 0.28);
        }

        .info-box {
            background: rgba(59, 130, 246, 0.12);
            border: 1px solid rgba(96, 165, 250, 0.22);
            border-radius: 14px;
            padding: 1rem;
            margin-bottom: 1rem;
            color: #dbeafe;
            font-size: 0.9rem;
        }

        .success-box {
            background: rgba(10, 14, 36, 0.22);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 18px;
            padding: 1.5rem;
            text-align: center;
        }

        .success-box h2 {
            color: #f8f7ff;
            margin: 0 0 1rem 0;
            font-size: 1.25rem;
        }

        .success-box p {
            color: rgba(248, 247, 255, 0.76);
            margin: 0.5rem 0;
            font-size: 0.92rem;
        }

        .code-box {
            background: rgba(10, 14, 36, 0.32);
            border: 1px dashed rgba(124, 58, 237, 0.38);
            border-radius: 14px;
            padding: 1.2rem;
            margin: 1rem 0;
            font-size: 1.15rem;
            font-weight: 800;
            color: #f8f7ff;
            font-family: 'Courier New', monospace;
            word-break: break-all;
            text-align: center;
            letter-spacing: 0.2em;
        }

        .back-btn,
        .secondary-btn {
            width: 100%;
            padding: 0.9rem 1rem;
            border-radius: 12px;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: 0.75rem;
        }

        .back-btn {
            background: rgba(255, 255, 255, 0.06);
            color: #f8f7ff;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .secondary-btn {
            background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);
            color: #ffffff;
            border: none;
        }

        .back-link {
            text-align: center;
            margin-top: 1.25rem;
        }

        .back-link a {
            color: #a78bfa;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .hint {
            font-size: 0.85rem;
            color: rgba(248, 247, 255, 0.62);
            margin-top: 0.5rem;
        }

        .code-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: 1fr;
        }

        @media (min-width: 640px) {
            .code-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>
<body class="auth-page">
<div class="ambient ambient-one"></div>
<div class="ambient ambient-two"></div>

<div class="forgot-wrapper">
    <div class="forgot-container">
        <div class="forgot-header">
            <h1>Lupa Password?</h1>
            <p>Reset password untuk akun <?= chemnama_e(chemnama_role_label($role)); ?>.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= chemnama_e($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="success-box">
                <h2>✓ Password Berhasil Diubah</h2>
                <p><?= chemnama_e($success); ?></p>
                <a href="login.php?role=<?= chemnama_e($role); ?>" class="secondary-btn">Kembali ke Login</a>
            </div>
        <?php else: ?>
            <?php if ($debugResetCode): ?>
                <div class="info-box" style="background: rgba(10, 14, 36, 0.22); border-color: rgba(255, 255, 255, 0.08); color: #f8f7ff;">
                    <strong>🔧 Mode Debug Aktif:</strong> Gunakan kode berikut untuk melanjutkan reset:
                    <div class="code-box" style="margin-top: 0.75rem; background: rgba(10, 14, 36, 0.32); border-color: rgba(124, 58, 237, 0.38); color: #f8f7ff;"><?= chemnama_e($debugResetCode); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
                <div class="step-indicator">Langkah 1 dari 2 • <?= chemnama_e($roleLabel); ?></div>
                <form method="post" class="forgot-form is-active">
                    <label>
                        <span><?= chemnama_e($roleLabel); ?></span>
                        <input type="text" name="login_id" placeholder="Masukkan <?= chemnama_e(strtolower($roleLabel)); ?> Anda" required>
                        <div class="hint">Contoh: <?= chemnama_e($roleExample); ?>. Kode verifikasi akan dikirim ke email terdaftar.</div>
                    </label>
                    <button type="submit" name="request_code">Kirim Kode Reset</button>
                </form>
            <?php else: ?>
                <div class="step-indicator">Langkah 2 dari 2 • Verifikasi Email</div>
                <div class="info-box">
                    Kode reset sudah dikirim ke email terdaftar: <strong><?= chemnama_e($maskedEmail); ?></strong>
                </div>
                <form method="post" class="forgot-form is-active">
                    <div class="code-grid">
                        <label>
                            <span>Kode Verifikasi</span>
                            <input type="text" name="reset_code" placeholder="6 digit kode" inputmode="numeric" maxlength="6" required autofocus>
                        </label>
                        <label>
                            <span>Password Baru</span>
                            <input type="password" name="new_password" placeholder="Password baru" required>
                        </label>
                    </div>
                    <label>
                        <span>Konfirmasi Password Baru</span>
                        <input type="password" name="confirm_password" placeholder="Ulangi password baru" required>
                    </label>
                    <button type="submit" name="verify_code">Ubah Password</button>
                    <a href="forgot-password.php?role=<?= chemnama_e($role); ?>" class="back-btn" style="text-decoration: none;">Kirim Ulang Kode</a>
                </form>
            <?php endif; ?>
        <?php endif; ?>

        <div class="back-link">
            <a href="login.php?role=<?= chemnama_e($role); ?>">← Kembali ke Login</a>
        </div>
    </div>
</div>
</body>
</html>

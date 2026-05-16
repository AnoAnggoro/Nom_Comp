<?php
require_once __DIR__ . '/config/bootstrap.php';

if (chemnama_current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$success = null;
$role = 'siswa';
$loginId = '';
$kelasOptions = chemnama_standard_class_options();
$kelas = $kelasOptions[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? 'siswa';
    $role = in_array($role, ['guru', 'siswa'], true) ? $role : 'siswa';
    $name = trim((string) ($_POST['name'] ?? ''));
    $loginId = chemnama_normalize_login_id((string) ($_POST['login_id'] ?? ''));
    $roleCode = trim((string) ($_POST['role_code'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $kelas = trim((string) ($_POST['kelas'] ?? $kelasOptions[0]));

    if (!in_array($kelas, $kelasOptions, true)) {
        $kelas = $kelasOptions[0];
    }

    if ($name === '' || $loginId === '' || strlen($password) < 6 || $email === '') {
        $error = 'Isi semua field (nama, ' . chemnama_role_login_label($role) . ', email, dan password minimal 6 karakter).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email tidak valid.';
    } elseif (!chemnama_is_valid_login_id($role, $loginId)) {
        $error = chemnama_role_login_label($role) . ' tidak valid.';
    } elseif (!hash_equals(chemnama_role_access_code($role), $roleCode)) {
        $error = 'Kode akses ' . chemnama_role_label($role) . ' tidak sesuai.';
    } else {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM users WHERE login_id = :login_id');
        $statement->execute(['login_id' => $loginId]);

        if ((int) $statement->fetchColumn() > 0) {
            $error = chemnama_role_login_label($role) . ' sudah digunakan.';
        } else {
            $profileTableExists = true;

            try {
                $pdo->beginTransaction();

                $insert = $pdo->prepare(
                    'INSERT INTO users (name, login_id, email, role, password_hash, avatar_color)
                     VALUES (:name, :login_id, :email, :role, :password_hash, :avatar_color)'
                );
                $insert->execute([
                    'name' => $name,
                    'login_id' => $loginId,
                    'email' => $email,
                    'role' => $role,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'avatar_color' => $role === 'guru' ? '#0f9d58' : '#2563eb',
                ]);

                $profileIdStmt = $pdo->prepare('SELECT id FROM users WHERE login_id = :login_id AND role = :role ORDER BY id DESC LIMIT 1');
                $profileIdStmt->execute([
                    'login_id' => $loginId,
                    'role' => $role,
                ]);
                $createdUserId = (int) $profileIdStmt->fetchColumn();

                if ($createdUserId <= 0) {
                    throw new RuntimeException('ID user baru tidak ditemukan.');
                }

                try {
                    $pdo->query('SELECT kelas FROM user_profiles LIMIT 1');
                } catch (Throwable $exception) {
                    $profileTableExists = false;
                }

                if ($profileTableExists) {
                    $profileInsert = $pdo->prepare(
                        'INSERT INTO user_profiles (user_id, kelas) VALUES (:user_id, :kelas)'
                    );
                    $profileInsert->execute([
                        'user_id' => $createdUserId,
                        'kelas' => $kelas,
                    ]);
                }

                $pdo->commit();
                $success = 'Akun berhasil dibuat. Silakan masuk.';
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Gagal membuat akun. Coba lagi.';
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
    <title>Daftar - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="auth-page">
<div class="ambient ambient-one"></div>
<div class="ambient ambient-two"></div>
<main class="auth-modal-shell">
    <section class="auth-modal auth-modal-register">
        <div class="auth-modal-header">
            <div>
                <h1>Daftar</h1>
                <p>Buat akun guru atau siswa untuk masuk ke Nom Comp.</p>
            </div>
            <a class="modal-close" href="index.php" aria-label="Kembali ke beranda">&times;</a>
        </div>

        <div class="role-switch role-switch-modal" data-role-switch>
            <button class="role-btn <?= $role === 'siswa' ? 'is-active' : ''; ?>" type="button" data-role-value="siswa">Siswa</button>
            <button class="role-btn <?= $role === 'guru' ? 'is-active' : ''; ?>" type="button" data-role-value="guru">Guru</button>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= chemnama_e($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= chemnama_e($success); ?></div>
        <?php endif; ?>

        <form method="post" class="auth-form auth-form-modal">
            <input type="hidden" name="role" value="<?= chemnama_e($role); ?>" data-role-input>
            <label>
                <span>Nama Lengkap</span>
                <input type="text" name="name" placeholder="Nama lengkap" required>
            </label>
            <label>
                <span data-auth-login-label><?= chemnama_e(chemnama_role_login_label($role)); ?></span>
                <input type="text" name="login_id" value="<?= chemnama_e($loginId); ?>" placeholder="<?= $role === 'guru' ? 'Contoh: 198712102010011001' : 'Contoh: 22004567'; ?>" required data-auth-login-id data-placeholder-siswa="Contoh: 22004567" data-placeholder-guru="Contoh: 198712102010011001">
            </label>
            <label>
                <span data-auth-role-label>Kode Akses <?= chemnama_e(chemnama_role_label($role)); ?></span>
                <input type="text" name="role_code" placeholder="Masukkan kode akses <?= strtolower(chemnama_role_label($role)); ?>" required data-auth-role-code data-role-code-placeholder-siswa="Masukkan kode akses siswa" data-role-code-placeholder-guru="Masukkan kode akses guru">
            </label>
            <label>
                <span>Email</span>
                <input type="email" name="email" placeholder="Masukkan email Anda" required>
            </label>
            <label>
                <span>Password</span>
                <div class="password-field">
                    <input type="password" name="password" placeholder="Password" required data-auth-password>
                    <button class="password-toggle" type="button" data-password-toggle data-eye-on="<?= chemnama_e(chemnama_icon('eye', '#64748b')); ?>" data-eye-off="<?= chemnama_e(chemnama_icon('eye-off', '#0f9d58')); ?>" aria-label="Tampilkan password">
                        <?= chemnama_icon('eye', '#64748b'); ?>
                    </button>
                </div>
            </label>
            <label>
                <span>Kelas</span>
                <?php $selectedKelas = $kelas !== '' ? $kelas : ($kelasOptions[0] ?? ''); ?>
                <div class="custom-select" data-custom-select>
                    <input type="hidden" name="kelas" value="<?= chemnama_e($selectedKelas); ?>" data-custom-select-value>
                    <button class="custom-select-trigger" type="button" data-custom-select-trigger aria-haspopup="listbox" aria-expanded="false">
                        <span data-custom-select-label><?= chemnama_e($selectedKelas); ?></span>
                        <span class="custom-select-caret" aria-hidden="true"></span>
                    </button>
                    <div class="custom-select-menu" data-custom-select-menu role="listbox">
                        <?php foreach ($kelasOptions as $kelasOption): ?>
                            <button class="custom-select-option" type="button" data-custom-select-option data-value="<?= chemnama_e($kelasOption); ?>" data-label="<?= chemnama_e($kelasOption); ?>">
                                <?= chemnama_e($kelasOption); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </label>
            <button class="btn btn-primary btn-block btn-lg" type="submit">Daftar Sekarang</button>
        </form>

        <p class="auth-link">Sudah punya akun? <a href="login.php">Masuk</a></p>
    </section>
</main>
<script src="assets/js/app.js"></script>
</body>
</html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth();

$user = chemnama_current_user();
if (($user['role'] ?? 'siswa') !== 'siswa') {
    header('Location: dashboard.php');
    exit;
}

$userId = (int) ($user['id'] ?? 0);

$avatarUploadDir = APP_ROOT . '/storage/uploads/avatars';
if (!is_dir($avatarUploadDir)) {
    mkdir($avatarUploadDir, 0777, true);
}

$allowedAvatarExt = ['jpg', 'jpeg', 'png', 'webp'];
$errors = [];
$action = (string) ($_POST['action'] ?? '');

if ($action === 'remove_avatar') {
    $existingAvatarStmt = $pdo->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
    $existingAvatarStmt->execute(['id' => $userId]);
    $existingAvatarPath = (string) ($existingAvatarStmt->fetchColumn() ?: '');

    if ($existingAvatarPath !== '') {
        $oldAbs = APP_ROOT . '/' . ltrim($existingAvatarPath, '/');
        if (is_file($oldAbs)) {
            @unlink($oldAbs);
        }
    }

    $clearStmt = $pdo->prepare('UPDATE users SET avatar_path = NULL WHERE id = :id');
    $clearStmt->execute(['id' => $userId]);

    chemnama_flash('Foto profil berhasil dihapus.', 'success');
    header('Location: siswa_profil.php');
    exit;
}

if ($action === 'update_profile') {
    $newName = trim((string) ($_POST['name'] ?? ''));
    $newEmail = trim((string) ($_POST['email'] ?? ''));
    $newPassword = trim((string) ($_POST['new_password'] ?? ''));
    $confirmPassword = trim((string) ($_POST['confirm_password'] ?? ''));

    if ($newName === '') {
        $errors[] = 'Nama wajib diisi.';
    }

    if ($newEmail === '' || filter_var($newEmail, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = 'Email tidak valid.';
    }

    if ($newPassword !== '' && strlen($newPassword) < 6) {
        $errors[] = 'Password baru minimal 6 karakter.';
    }

    if ($newPassword !== '' && $newPassword !== $confirmPassword) {
        $errors[] = 'Konfirmasi password tidak cocok.';
    }

    $emailCheck = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $emailCheck->execute(['email' => $newEmail, 'id' => $userId]);
    if ($emailCheck->fetch()) {
        $errors[] = 'Email sudah digunakan akun lain.';
    }

    $existingAvatarStmt = $pdo->prepare('SELECT avatar_path FROM users WHERE id = :id LIMIT 1');
    $existingAvatarStmt->execute(['id' => $userId]);
    $existingAvatarPath = (string) ($existingAvatarStmt->fetchColumn() ?: '');
    $nextAvatarPath = $existingAvatarPath !== '' ? $existingAvatarPath : null;

    if (isset($_FILES['avatar_file']) && (int) $_FILES['avatar_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int) $_FILES['avatar_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload foto profil gagal, coba lagi.';
        } else {
            $originalName = (string) $_FILES['avatar_file']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $tmpPath = (string) $_FILES['avatar_file']['tmp_name'];
            $fileSize = (int) ($_FILES['avatar_file']['size'] ?? 0);

            if (!in_array($extension, $allowedAvatarExt, true)) {
                $errors[] = 'Format foto harus JPG, PNG, atau WEBP.';
            }

            if ($fileSize > 2 * 1024 * 1024) {
                $errors[] = 'Ukuran foto maksimal 2MB.';
            }

            $imageInfo = @getimagesize($tmpPath);
            if ($imageInfo === false) {
                $errors[] = 'File upload bukan gambar yang valid.';
            }

            if (count($errors) === 0) {
                $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
                $safeName = trim((string) $safeName, '-');
                if ($safeName === '') {
                    $safeName = 'avatar';
                }

                $newFile = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $safeName . '.' . $extension;
                $target = $avatarUploadDir . '/' . $newFile;

                if (!move_uploaded_file($tmpPath, $target)) {
                    $errors[] = 'Foto profil tidak dapat disimpan.';
                } else {
                    if ($existingAvatarPath !== '') {
                        $oldAbs = APP_ROOT . '/' . ltrim($existingAvatarPath, '/');
                        if (is_file($oldAbs)) {
                            @unlink($oldAbs);
                        }
                    }
                    $nextAvatarPath = 'storage/uploads/avatars/' . $newFile;
                }
            }
        }
    }

    if (count($errors) === 0) {
        $params = [
            'name' => $newName,
            'email' => $newEmail,
            'avatar_path' => $nextAvatarPath,
            'id' => $userId,
        ];

        if ($newPassword !== '') {
            $update = $pdo->prepare(
                'UPDATE users
                 SET name = :name, email = :email, avatar_path = :avatar_path, password_hash = :password_hash
                 WHERE id = :id'
            );
            $params['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
            $update->execute($params);
        } else {
            $update = $pdo->prepare(
                'UPDATE users
                 SET name = :name, email = :email, avatar_path = :avatar_path
                 WHERE id = :id'
            );
            $update->execute($params);
        }

        $_SESSION['user']['name'] = $newName;
        $_SESSION['user']['email'] = $newEmail;

        chemnama_flash('Profil berhasil diperbarui.', 'success');
        header('Location: siswa_profil.php');
        exit;
    }
}

$profileStmt = $pdo->prepare(
    'SELECT u.name, u.email, u.avatar_color, u.avatar_path, u.created_at,
            COALESCE(up.kelas, "X IPA 1") AS kelas,
            COALESCE(sp.material_count, 0) AS material_count,
            COALESCE(sp.quiz_count, 0) AS quiz_count,
            COALESCE(sp.average_score, 0) AS average_score,
            COALESCE(sp.status_label, "Aktif") AS status_label
     FROM users u
     LEFT JOIN user_profiles up ON up.user_id = u.id
     LEFT JOIN student_progress sp ON sp.user_id = u.id
     WHERE u.id = :user_id
     LIMIT 1'
);
$profileStmt->execute(['user_id' => $userId]);
$profile = $profileStmt->fetch();

if (!$profile) {
    header('Location: dashboard.php');
    exit;
}

$studentClass = (string) ($profile['kelas'] ?? 'X IPA 1');
$materialCount = (int) ($profile['material_count'] ?? 0);
$quizCount = (int) ($profile['quiz_count'] ?? 0);
$avgScore = (float) ($profile['average_score'] ?? 0);
$scorePercent = (int) round(max(0, min(100, $avgScore)));

$learningPoints = (int) round(($materialCount * 20) + ($quizCount * 12) + $avgScore);
$learningStatus = 'Perlu Latihan';
if ($scorePercent >= 85) {
    $learningStatus = 'Sangat Baik';
} elseif ($scorePercent >= 70) {
    $learningStatus = 'Baik';
}

$essayActivityStmt = $pdo->prepare(
    'SELECT et.prompt_text, ea.submitted_at, ea.graded_at, ea.score
     FROM essay_answers ea
     JOIN essay_tasks et ON et.id = ea.task_id
     WHERE ea.student_id = :student_id
     ORDER BY ea.submitted_at DESC, ea.id DESC
     LIMIT 1'
);
$essayActivityStmt->execute(['student_id' => $userId]);
$lastEssay = $essayActivityStmt->fetch();

$homeworkActivityStmt = $pdo->prepare(
    'SELECT ht.title, hs.submitted_at, hs.is_checked
     FROM homework_submissions hs
     JOIN homework_tasks ht ON ht.id = hs.task_id
     WHERE hs.student_id = :student_id
     ORDER BY hs.submitted_at DESC, hs.id DESC
     LIMIT 1'
);
$homeworkActivityStmt->execute(['student_id' => $userId]);
$lastHomework = $homeworkActivityStmt->fetch();

$forumCountStmt = $pdo->prepare(
    'SELECT
        (SELECT COUNT(*)
         FROM forum_threads ft
         WHERE ft.author_id = :student_id_1 AND ft.class_name = :class_name_1) AS thread_count,
        (SELECT COUNT(*)
         FROM forum_replies fr
         JOIN forum_threads ft2 ON ft2.id = fr.thread_id
         WHERE fr.author_id = :student_id_2 AND ft2.class_name = :class_name_2) AS reply_count'
);
$forumCountStmt->execute([
    'student_id_1' => $userId,
    'class_name_1' => $studentClass,
    'student_id_2' => $userId,
    'class_name_2' => $studentClass,
]);
$forumCounts = $forumCountStmt->fetch() ?: ['thread_count' => 0, 'reply_count' => 0];
$openEditModal = $action === 'update_profile' && count($errors) > 0;

$studentMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Materi', 'icon' => 'file', 'href' => 'siswa_materi.php', 'active' => false],
    // ['label' => 'Games', 'icon' => 'beaker', 'href' => 'siswa_games.php', 'active' => false],
    // ['label' => 'Kuis PG', 'icon' => 'stack', 'href' => 'siswa_quiz.php', 'active' => false],
    ['label' => 'Exercise', 'icon' => 'edit', 'href' => 'siswa_essay.php', 'active' => false],
    ['label' => 'Forum Diskusi', 'icon' => 'chat', 'href' => 'siswa_forum.php', 'active' => false],
    ['label' => 'Profil', 'icon' => 'user', 'href' => 'siswa_profil.php', 'active' => true],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil Siswa - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<main class="guru-page student-page">
    <button class="guru-mobile-toggle" type="button" data-guru-sidebar-toggle aria-label="Buka navigasi" aria-controls="studentSidebar" aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
    </button>
    <div class="guru-sidebar-overlay" data-guru-sidebar-overlay></div>

    <aside class="guru-sidebar student-sidebar" id="studentSidebar">
        <div class="guru-brand">
            <a class="brand" href="index.php">
                <?= chemnama_icon('brand', '#0f9d58'); ?>
                <span>Nom Comp</span>
            </a>
            <p>Panel Siswa</p>
            <div class="guru-class-chip"><?= chemnama_e($studentClass); ?></div>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">MENU</span>
            <nav class="guru-menu-list">
                <?php foreach ($studentMenus as $menu): ?>
                    <a class="guru-menu-item <?= $menu['active'] ? 'is-active' : ''; ?>" href="<?= chemnama_e($menu['href']); ?>">
                        <?= chemnama_icon($menu['icon'], $menu['active'] ? '#0f9d58' : '#6b7280'); ?>
                        <span><?= chemnama_e($menu['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">AKUN</span>
            <nav class="guru-menu-list">
                <a class="guru-menu-item logout" href="logout.php">
                    <?= chemnama_icon('logout', '#ef4444'); ?>
                    <span>Keluar</span>
                </a>
            </nav>
        </div>
    </aside>

    <section class="guru-content student-content siswa-profile-page">
        <div class="siswa-profile-head">
            <h1>Profil Saya</h1>
            <p>Informasi akun dan ringkasan progres belajar.</p>
        </div>

        <article class="guru-panel siswa-profile-card">
            <div class="siswa-profile-main">
                <?php if (!empty($profile['avatar_path'])): ?>
                    <img class="siswa-profile-avatar-image" src="<?= chemnama_e((string) $profile['avatar_path']); ?>" alt="Foto profil siswa">
                <?php else: ?>
                    <div class="siswa-profile-avatar" style="background: <?= chemnama_e((string) $profile['avatar_color']); ?>;">
                        <?= chemnama_e(strtoupper(substr((string) $profile['name'], 0, 1))); ?>
                    </div>
                <?php endif; ?>
                <div class="siswa-profile-identity">
                    <h2><?= chemnama_e((string) $profile['name']); ?></h2>
                    <p><?= chemnama_e((string) $profile['email']); ?></p>
                    <div class="siswa-profile-tags">
                        <span class="siswa-profile-tag role">Siswa</span>
                        <span class="siswa-profile-tag class"><?= chemnama_e($studentClass); ?></span>
                        <span class="siswa-profile-tag status"><?= chemnama_e((string) $profile['status_label']); ?></span>
                    </div>
                </div>
            </div>

            <div class="siswa-profile-stats">
                <div class="siswa-profile-stat">
                    <strong><?= chemnama_e((string) $learningPoints); ?></strong>
                    <span>Poin Belajar</span>
                </div>
                <div class="siswa-profile-stat">
                    <strong><?= chemnama_e((string) $materialCount); ?></strong>
                    <span>Materi Dibaca</span>
                </div>
                <div class="siswa-profile-stat">
                    <strong><?= chemnama_e((string) $quizCount); ?></strong>
                    <span>Kuis Dikerjakan</span>
                </div>
                <div class="siswa-profile-stat">
                    <strong><?= chemnama_e((string) $scorePercent); ?>%</strong>
                    <span>Rata-rata Nilai</span>
                </div>
            </div>
        </article>

        <?php if (count($errors) > 0): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= chemnama_e($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="siswa-profile-edit-trigger">
            <button class="btn btn-primary" type="button" data-profile-modal-open>
                <?= chemnama_icon('edit', '#ffffff'); ?> Edit Profil
            </button>
        </div>

        <div class="siswa-profile-grid">
            <article class="guru-panel">
                <h3>Status Belajar</h3>
                <div class="siswa-profile-checklist">
                    <div>
                        <strong><?= chemnama_e($learningStatus); ?></strong>
                        <p>Evaluasi berdasarkan rata-rata nilai saat ini.</p>
                    </div>
                    <div>
                        <strong>Kelas Aktif: <?= chemnama_e($studentClass); ?></strong>
                        <p>Akun dibuat pada <?= chemnama_e(chemnama_format_date((string) $profile['created_at'])); ?>.</p>
                    </div>
                    <div>
                        <strong>Partisipasi Forum</strong>
                        <p><?= chemnama_e((string) $forumCounts['thread_count']); ?> thread dan <?= chemnama_e((string) $forumCounts['reply_count']); ?> balasan di kelas ini.</p>
                    </div>
                </div>
            </article>

            <article class="guru-panel">
                <h3>Aktivitas Terakhir</h3>
                <div class="siswa-profile-activity-list">
                    <div class="siswa-profile-activity">
                        <span><?= chemnama_icon('edit', '#d97706'); ?></span>
                        <div>
                            <strong>Essay</strong>
                            <p>
                                <?php if ($lastEssay): ?>
                                    <?= chemnama_e(substr((string) $lastEssay['prompt_text'], 0, 70)); ?><?= strlen((string) $lastEssay['prompt_text']) > 70 ? '...' : ''; ?>
                                    · <?= chemnama_e((string) $lastEssay['submitted_at']); ?>
                                <?php else: ?>
                                    Belum ada pengumpulan essay.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div class="siswa-profile-activity">
                        <span><?= chemnama_icon('task', '#0f9d58'); ?></span>
                        <div>
                            <strong>PR / Homework</strong>
                            <p>
                                <?php if ($lastHomework): ?>
                                    <?= chemnama_e((string) $lastHomework['title']); ?> · <?= chemnama_e((string) $lastHomework['submitted_at']); ?>
                                    (<?= (int) ($lastHomework['is_checked'] ?? 0) === 1 ? 'Sudah Dicek' : 'Menunggu Dicek'; ?>)
                                <?php else: ?>
                                    Belum ada upload PR.
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </article>
        </div>

        <article class="guru-panel siswa-profile-actions">
            <h3>Aksi Cepat</h3>
            <div class="siswa-profile-action-row">
                <a class="btn btn-primary" href="siswa_materi.php"><?= chemnama_icon('file', '#ffffff'); ?> Buka Materi</a>
                <a class="btn btn-ghost" href="siswa_essay.php"><?= chemnama_icon('edit', '#d97706'); ?> Tugas Essay</a>
                <a class="btn btn-ghost" href="siswa_essay.php#pr-homework"><?= chemnama_icon('task', '#0f9d58'); ?> PR / Homework</a>
                <a class="btn btn-ghost" href="siswa_forum.php"><?= chemnama_icon('chat', '#2563eb'); ?> Forum Diskusi</a>
            </div>
        </article>

        <div class="profile-modal-backdrop <?= $openEditModal ? 'is-open' : ''; ?>" data-profile-modal-backdrop></div>
        <div class="profile-modal <?= $openEditModal ? 'is-open' : ''; ?>" data-profile-modal role="dialog" aria-modal="true" aria-labelledby="profileModalTitle">
            <article class="guru-panel siswa-profile-edit-panel">
                <div class="siswa-profile-modal-head">
                    <h3 id="profileModalTitle">Edit Profil</h3>
                    <button class="profile-modal-close" type="button" data-profile-modal-close aria-label="Tutup">
                        <?= chemnama_icon('x', '#64748b'); ?>
                    </button>
                </div>

                <form method="post" enctype="multipart/form-data" class="siswa-profile-form-grid">
                    <input type="hidden" name="action" value="update_profile">

                    <label>
                        <span>Nama Lengkap</span>
                        <input type="text" name="name" value="<?= chemnama_e((string) ($_POST['name'] ?? $profile['name'])); ?>" required>
                    </label>

                    <label>
                        <span>Email</span>
                        <input type="email" name="email" value="<?= chemnama_e((string) ($_POST['email'] ?? $profile['email'])); ?>" required>
                    </label>

                    <label>
                        <span>Password Baru (opsional)</span>
                        <input type="password" name="new_password" minlength="6" placeholder="Kosongkan jika tidak diganti">
                    </label>

                    <label class="span-2">
                        <span>Konfirmasi Password Baru</span>
                        <input type="password" name="confirm_password" minlength="6" placeholder="Ulangi password baru">
                    </label>

                     <label>
                        <span>Foto Profil (opsional)</span>
                        <input type="file" name="avatar_file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-avatar-input>
                        <div class="siswa-avatar-preview-wrap" data-avatar-preview-wrap>
                            <?php if (!empty($profile['avatar_path'])): ?>
                                <img src="<?= chemnama_e((string) $profile['avatar_path']); ?>" alt="Preview avatar" data-avatar-preview>
                            <?php else: ?>
                                <div class="siswa-avatar-preview-empty" data-avatar-preview-empty>Belum ada foto</div>
                                <img src="" alt="Preview avatar" data-avatar-preview style="display:none;">
                            <?php endif; ?>
                        </div>
                        <small>Format JPG/PNG/WEBP, maksimal 2MB.</small>
                    </label> <br>

                        <div class="form-action-row span-2">
                        <button class="btn btn-primary" type="submit">Simpan Perubahan</button>
                        <button class="btn btn-ghost" type="button" data-profile-modal-close>Batal</button>
                    </div>
                </form>

                <?php if (!empty($profile['avatar_path'])): ?>
                    <form method="post" class="siswa-profile-remove-avatar-form" onsubmit="return confirm('Hapus foto profil sekarang?');">
                        <input type="hidden" name="action" value="remove_avatar">
                        <button class="btn btn-ghost" type="submit">Hapus Foto Profil</button>
                    </form>
                <?php endif; ?>
            </article>
        </div>
    </section>
</main>
<script src="assets/js/app.js?v=20260407"></script>
<script>
    (function () {
        const modal = document.querySelector('[data-profile-modal]');
        const backdrop = document.querySelector('[data-profile-modal-backdrop]');
        const openBtn = document.querySelector('[data-profile-modal-open]');
        const closeBtns = document.querySelectorAll('[data-profile-modal-close]');

        if (!modal || !backdrop || !openBtn) {
            return;
        }

        function openModal() {
            modal.classList.add('is-open');
            backdrop.classList.add('is-open');
            document.body.classList.add('is-modal-open');
        }

        function closeModal() {
            modal.classList.remove('is-open');
            backdrop.classList.remove('is-open');
            document.body.classList.remove('is-modal-open');
        }

        openBtn.addEventListener('click', openModal);
        closeBtns.forEach((button) => button.addEventListener('click', closeModal));
        backdrop.addEventListener('click', closeModal);

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });

        if (modal.classList.contains('is-open')) {
            document.body.classList.add('is-modal-open');
        }

        const avatarInput = document.querySelector('[data-avatar-input]');
        const avatarPreview = document.querySelector('[data-avatar-preview]');
        const avatarPreviewEmpty = document.querySelector('[data-avatar-preview-empty]');

        if (avatarInput && avatarPreview) {
            avatarInput.addEventListener('change', function () {
                const file = avatarInput.files && avatarInput.files[0] ? avatarInput.files[0] : null;
                if (!file) {
                    return;
                }

                const objectUrl = URL.createObjectURL(file);
                avatarPreview.src = objectUrl;
                avatarPreview.style.display = '';
                if (avatarPreviewEmpty) {
                    avatarPreviewEmpty.style.display = 'none';
                }
            });
        }
    })();
</script>
</body>
</html>

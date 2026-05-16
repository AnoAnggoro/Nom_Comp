<?php
require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('guru');

$user = chemnama_current_user();
$userId = $user['id'];

$avatarUploadDir = APP_ROOT . '/storage/uploads/avatars';
if (!is_dir($avatarUploadDir)) {
    mkdir($avatarUploadDir, 0777, true);
}

$allowedAvatarExt = ['jpg', 'jpeg', 'png', 'webp'];

// Get complete user data with created_at
$userStmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$userComplete = $userStmt->fetch();

// Get user profile data (kelas)
$profileStmt = $pdo->prepare(
    'SELECT up.kelas
     FROM user_profiles up
     WHERE up.user_id = ?'
);
$profileStmt->execute([$userId]);
$profile = $profileStmt->fetch();

if (!$profile) {
    $profile = ['kelas' => 'X IPA 1'];
}

$teacherKelas = chemnama_get_guru_active_class($pdo, (int) $userId);

// Get student count in teacher's class
$stmtStudentCount = $pdo->prepare(
    'SELECT COUNT(*) FROM user_profiles up
     JOIN users u ON u.id = up.user_id
     WHERE up.kelas = ? AND u.role = "siswa"'
);
$stmtStudentCount->execute([$teacherKelas]);
$studentCount = (int) $stmtStudentCount->fetchColumn();

// Get class assignments for this teacher's class only
$classStatsStmt = $pdo->prepare(
    'SELECT ? AS kelas, COUNT(*) AS siswa_count
     FROM user_profiles up
     JOIN users u ON u.id = up.user_id
     WHERE up.kelas = ? AND u.role = "siswa"'
);
$classStatsStmt->execute([$teacherKelas, $teacherKelas]);
$classStats = $classStatsStmt->fetchAll();

// Get material stats for each module
$moduleStatsStmt = $pdo->prepare(
    'SELECT m.id, m.badge, m.title, COUNT(DISTINCT ma.id) AS material_count, COUNT(DISTINCT q.id) AS question_count
     FROM modules m
     LEFT JOIN materials ma ON ma.module_id = m.id AND ma.created_by = ?
     LEFT JOIN questions q ON q.module_id = m.id AND q.created_by = ?
     WHERE ma.created_by = ? OR q.created_by = ?
     GROUP BY m.id, m.badge, m.title
     ORDER BY m.sort_order, m.id'
);
$moduleStatsStmt->execute([$userId, $userId, $userId, $userId]);
$moduleStats = $moduleStatsStmt->fetchAll();

// Get total statistics
$stmtMaterials = $pdo->prepare('SELECT COUNT(*) FROM materials WHERE created_by = ?');
$stmtMaterials->execute([$userId]);
$totalMaterials = (int) $stmtMaterials->fetchColumn();

$stmtQuestions = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE created_by = ?');
$stmtQuestions->execute([$userId]);
$totalQuestions = (int) $stmtQuestions->fetchColumn();

$stmtEssays = $pdo->prepare('SELECT COUNT(*) FROM essay_tasks WHERE created_by = ?');
$stmtEssays->execute([$userId]);
$totalEssays = (int) $stmtEssays->fetchColumn();

$stmtHomework = $pdo->prepare('SELECT COUNT(*) FROM homework_tasks WHERE created_by = ?');
$stmtHomework->execute([$userId]);
$totalHomework = (int) $stmtHomework->fetchColumn();

// Get essays and homework stats
$essayStmt = $pdo->prepare(
    'SELECT COUNT(*) total, SUM(CASE WHEN graded_at IS NOT NULL THEN 1 ELSE 0 END) graded
     FROM essay_answers ea
     JOIN essay_tasks et ON ea.task_id = et.id
     WHERE et.created_by = ?'
);
$essayStmt->execute([$userId]);
$essayStats = $essayStmt->fetch();

$homeworkStmt = $pdo->prepare(
    'SELECT COUNT(*) total, SUM(CASE WHEN is_checked = 1 THEN 1 ELSE 0 END) checked
     FROM homework_submissions hs
     JOIN homework_tasks ht ON hs.task_id = ht.id
     WHERE ht.created_by = ?'
);
$homeworkStmt->execute([$userId]);
$homeworkStats = $homeworkStmt->fetch();

$guruNotification = chemnama_guru_notification_summary($pdo, (int) $userId, $teacherKelas);
$pendingRepeatRequestCount = (int) ($guruNotification['quiz_repeat_pending'] ?? 0);
$essayPendingCount = (int) ($guruNotification['essay_pending'] ?? 0);
$homeworkPendingCount = (int) ($guruNotification['homework_pending'] ?? 0);
$totalNotificationCount = (int) ($guruNotification['total'] ?? 0);

// Get recent activity
$recentActivityStmt = $pdo->prepare(
    'SELECT "material" as type, title, created_at FROM materials WHERE created_by = ? 
     UNION ALL
     SELECT "soal", question_text, created_at FROM questions WHERE created_by = ?
     UNION ALL
     SELECT "essay", prompt_text, created_at FROM essay_tasks WHERE created_by = ?
     UNION ALL
     SELECT "homework", title, created_at FROM homework_tasks WHERE created_by = ?
     ORDER BY created_at DESC LIMIT 5'
);
$recentActivityStmt->execute([$userId, $userId, $userId, $userId]);
$recentActivity = $recentActivityStmt->fetchAll();

$joinedDate = new DateTime($userComplete['created_at'] ?? date('Y-m-d H:i:s'));
$now = new DateTime();
$daysActive = $now->diff($joinedDate)->days;

$flash = chemnama_flash();
$profileModal = (string) ($_GET['modal'] ?? '');
$profileModalError = $_SESSION['guru_profile_modal_error'] ?? null;
unset($_SESSION['guru_profile_modal_error']);

$avatarColorOptions = [
    '#0f9d58' => 'Hijau ChemNama',
    '#2563eb' => 'Biru Akademik',
    '#d97706' => 'Oranye Guru',
    '#7c3aed' => 'Ungu Kelas',
    '#ef4444' => 'Merah Aksi',
    '#0ea5e9' => 'Cyan Modern',
];

$selectedAvatarColor = (string) ($userComplete['avatar_color'] ?? '#0f9d58');
if (!isset($avatarColorOptions[$selectedAvatarColor])) {
    $selectedAvatarColor = '#0f9d58';
}
$selectedAvatarColorLabel = (string) ($avatarColorOptions[$selectedAvatarColor] ?? 'Pilih warna avatar');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save_settings') {
        $newAvatarColor = trim((string) ($_POST['avatar_color'] ?? ''));
        $removeAvatar = isset($_POST['remove_avatar']) && (string) $_POST['remove_avatar'] === '1';
        if (!isset($avatarColorOptions[$newAvatarColor])) {
            $_SESSION['guru_profile_modal_error'] = 'Warna avatar tidak valid.';
        } else {
            $currentAvatarStmt = $pdo->prepare('SELECT avatar_path FROM users WHERE id = ? LIMIT 1');
            $currentAvatarStmt->execute([$userId]);
            $existingAvatarPath = (string) ($currentAvatarStmt->fetchColumn() ?: '');
            $nextAvatarPath = $existingAvatarPath !== '' ? $existingAvatarPath : null;

            if ($removeAvatar && $existingAvatarPath !== '') {
                $oldAbs = APP_ROOT . '/' . ltrim($existingAvatarPath, '/');
                if (is_file($oldAbs)) {
                    @unlink($oldAbs);
                }
                $nextAvatarPath = null;
            }

            if (isset($_FILES['avatar_file']) && (int) $_FILES['avatar_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ((int) $_FILES['avatar_file']['error'] !== UPLOAD_ERR_OK) {
                    $_SESSION['guru_profile_modal_error'] = 'Upload foto profil gagal, coba lagi.';
                } else {
                    $originalName = (string) $_FILES['avatar_file']['name'];
                    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    $tmpPath = (string) $_FILES['avatar_file']['tmp_name'];
                    $fileSize = (int) ($_FILES['avatar_file']['size'] ?? 0);

                    if (!in_array($extension, $allowedAvatarExt, true)) {
                        $_SESSION['guru_profile_modal_error'] = 'Format foto harus JPG, PNG, atau WEBP.';
                    }

                    if ($fileSize > 2 * 1024 * 1024) {
                        $_SESSION['guru_profile_modal_error'] = 'Ukuran foto maksimal 2MB.';
                    }

                    $imageInfo = @getimagesize($tmpPath);
                    if ($imageInfo === false) {
                        $_SESSION['guru_profile_modal_error'] = 'File upload bukan gambar yang valid.';
                    }

                    if (empty($_SESSION['guru_profile_modal_error'])) {
                        $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
                        $safeName = trim((string) $safeName, '-');
                        if ($safeName === '') {
                            $safeName = 'avatar';
                        }

                        $newFile = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $safeName . '.' . $extension;
                        $target = $avatarUploadDir . '/' . $newFile;

                        if (!move_uploaded_file($tmpPath, $target)) {
                            $_SESSION['guru_profile_modal_error'] = 'Foto profil tidak dapat disimpan.';
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

            if (empty($_SESSION['guru_profile_modal_error'])) {
                $updateSettingsStmt = $pdo->prepare('UPDATE users SET avatar_color = ?, avatar_path = ? WHERE id = ?');
                $updateSettingsStmt->execute([$newAvatarColor, $nextAvatarPath, $userId]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Pengaturan guru berhasil disimpan.'];
            }
        }

        header('Location: guru_profil.php?modal=settings');
        exit;
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($currentPassword, (string) $userComplete['password_hash'])) {
            $_SESSION['guru_profile_modal_error'] = 'Password saat ini salah.';
        } elseif (strlen($newPassword) < 6) {
            $_SESSION['guru_profile_modal_error'] = 'Password baru minimal 6 karakter.';
        } elseif ($newPassword !== $confirmPassword) {
            $_SESSION['guru_profile_modal_error'] = 'Konfirmasi password tidak cocok.';
        } else {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePasswordStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
            $updatePasswordStmt->execute([$newPasswordHash, $userId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Password guru berhasil diperbarui.'];
        }

        header('Location: guru_profil.php?modal=password');
        exit;
    }
}

$settingsModalOpen = $profileModal === 'settings';
$passwordModalOpen = $profileModal === 'password';
$detailsModalOpen = $profileModal === 'details';
$notificationsModalOpen = $profileModal === 'notifications';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Profil Guru - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .guru-profil-header {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 24px;
            align-items: start;
            padding: 24px;
            background: rgba(10, 14, 36, 0.14);
            backdrop-filter: blur(14px);
            border-radius: 16px;
            margin-bottom: 24px;
            border: 1px solid rgba(148, 163, 184, 0.2);
        }

        .guru-profil-header > div {
            min-width: 0;
        }

        .guru-profil-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 120px;
            height: 120px;
            border-radius: 16px;
            font-size: 48px;
            font-weight: 600;
            color: white;
            overflow: hidden;
        }

        .guru-profil-avatar-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .guru-profil-info h2 {
            font-size: 24px;
            margin: 0 0 6px 0;
            color: #f1f5f9;
            overflow-wrap: anywhere;
        }

        .guru-profil-info p {
            margin: 0 0 12px 0;
            color: #cbd5e1;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .guru-profil-badges {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .profil-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 500;
            background: rgba(51, 65, 85, 0.6);
            border: 1px solid rgba(148, 163, 184, 0.3);
            color: #cbd5e1;
        }

        .profil-badge.role {
            color: #fbbf24;
            border-color: rgba(251, 191, 36, 0.4);
            background: rgba(251, 191, 36, 0.1);
        }

        .profil-badge.class {
            color: #86efac;
            border-color: rgba(134, 239, 172, 0.4);
            background: rgba(134, 239, 172, 0.1);
        }

        .profil-action-buttons {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 0;
        }

        .profil-action-buttons button {
            display: flex;
            align-items: center;
            gap: 10px;
            text-align: left;
            padding: 10px 16px;
            border-radius: 8px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            background: rgba(51, 65, 85, 0.5);
            color: #cbd5e1;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s ease;
            min-width: 0;
        }

        .profil-action-buttons button strong {
            display: block;
            color: #f1f5f9;
            font-size: 14px;
            font-weight: 700;
        }

        .profil-action-buttons button small {
            display: block;
            color: #cbd5e1;
            font-size: 12px;
            font-weight: 500;
        }

        .profil-action-icon {
            width: 36px;
            height: 36px;
            border-radius: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: rgba(15, 157, 88, 0.25);
            color: #86efac;
        }

        .profil-action-icon .icon-mark {
            width: 18px;
            height: 18px;
        }

        .guru-profile-modal-note {
            margin: 0;
            color: #cbd5e1;
            line-height: 1.6;
        }

        .guru-profile-setting-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            margin-top: 12px;
        }

        .guru-profile-setting-grid label {
            display: grid;
            gap: 6px;
            font-weight: 700;
            color: #f1f5f9;
        }

        .guru-profile-setting-grid select,
        .guru-profile-setting-grid input {
            width: 100%;
            min-height: 46px;
            border: 1px solid rgba(148, 163, 184, 0.24);
            border-radius: 12px;
            padding: 0 12px;
            font: inherit;
            color: #f1f5f9;
            background: rgba(51, 65, 85, 0.5);
        }

        .guru-profile-setting-grid select::placeholder,
        .guru-profile-setting-grid input::placeholder {
            color: #94a3b8;
        }

        .guru-profile-setting-grid select::-webkit-input-placeholder,
        .guru-profile-setting-grid input::-webkit-input-placeholder {
            color: #94a3b8;
        }

        .guru-profile-setting-grid .span-2 {
            grid-column: 1 / -1;
        }

        .guru-profile-setting-grid .forum-custom-select {
            position: relative;
            z-index: 4;
        }

        .guru-profile-setting-grid .forum-custom-select-menu {
            z-index: 30;
        }

        .guru-profile-setting-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 6px;
        }

        .guru-profile-setting-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 6px 10px;
            background: rgba(148, 163, 184, 0.15);
            color: #cbd5e1;
            font-size: 0.82rem;
            font-weight: 700;
        }

        .guru-profile-setting-chip .swatch {
            width: 14px;
            height: 14px;
            border-radius: 999px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            box-shadow: 0 0 0 1px rgba(148, 163, 184, 0.24);
        }

        .profil-action-buttons button:hover {
            background: rgba(51, 65, 85, 0.7);
            color: #f1f5f9;
            border-color: rgba(148, 163, 184, 0.3);
        }

        .guru-profil-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .profil-stat-card {
            background: rgba(10, 14, 36, 0.14);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
        }

        .profil-stat-card strong {
            display: block;
            font-size: 32px;
            color: #4ade80;
            margin: 0 0 6px 0;
        }

        .profil-stat-card p {
            margin: 0;
            color: #cbd5e1;
            font-size: 14px;
        }

        .guru-profil-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .profil-section {
            background: rgba(10, 14, 36, 0.14);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 12px;
            padding: 20px;
        }

        .profil-section h3 {
            margin: 0 0 16px 0;
            font-size: 16px;
            color: #f1f5f9;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .class-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .class-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background: rgba(51, 65, 85, 0.4);
            border-radius: 8px;
            font-size: 14px;
            border: 1px solid rgba(148, 163, 184, 0.15);
        }

        .class-name {
            font-weight: 500;
            color: #f1f5f9;
        }

        .class-count {
            color: #cbd5e1;
            font-size: 13px;
        }

        .module-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
        }

        .module-stat-item {
            background: rgba(51, 65, 85, 0.4);
            border-radius: 8px;
            padding: 12px;
            text-align: center;
            border-left: 3px solid #fbbf24;
            border: 1px solid rgba(148, 163, 184, 0.15);
            border-left: 3px solid #fbbf24;
        }

        .module-stat-item strong {
            display: block;
            font-size: 18px;
            color: #fbbf24;
            margin-bottom: 4px;
        }

        .module-stat-item .module-name {
            font-size: 12px;
            color: #cbd5e1;
            margin-bottom: 6px;
        }

        .module-stat-item .counts {
            font-size: 12px;
            color: #94a3b8;
        }

        .activity-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .activity-item {
            display: flex;
            gap: 12px;
            padding: 12px;
            background: rgba(51, 65, 85, 0.4);
            border-radius: 8px;
            font-size: 13px;
            border-left: 3px solid rgba(148, 163, 184, 0.3);
            border: 1px solid rgba(148, 163, 184, 0.15);
            border-left: 3px solid rgba(148, 163, 184, 0.3);
        }

        .activity-item.material {
            border-left-color: #fbbf24;
        }

        .activity-item.soal {
            border-left-color: #60a5fa;
        }

        .activity-item.essay {
            border-left-color: #c084fc;
        }

        .activity-item.homework {
            border-left-color: #86efac;
        }

        .activity-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            border-radius: 6px;
            color: white;
            font-size: 11px;
            flex-shrink: 0;
            font-weight: 600;
        }

        .activity-badge.material {
            background: #d97706;
        }

        .activity-badge.soal {
            background: #2563eb;
        }

        .activity-badge.essay {
            background: #7c3aed;
        }

        .activity-badge.homework {
            background: #0f9d58;
        }

        .activity-text {
            flex: 1;
        }

        .activity-text strong {
            display: block;
            color: #f1f5f9;
            margin-bottom: 2px;
        }

        .activity-date {
            color: #94a3b8;
            font-size: 12px;
        }

        @media (max-width: 1000px) {
            .guru-profil-header {
                grid-template-columns: 1fr;
            }

            .profil-action-buttons {
                flex-direction: row;
                flex-wrap: wrap;
            }

            .profil-action-buttons button {
                flex: 1 1 220px;
            }

            .guru-profil-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .guru-profil-grid {
                grid-template-columns: 1fr;
            }

            .guru-profile-setting-grid {
                grid-template-columns: 1fr;
            }

            .guru-profile-setting-grid .span-2 {
                grid-column: span 1;
            }
        }

        @media (max-width: 600px) {
            .guru-profil-header {
                padding: 16px;
            }

            .guru-profil-avatar {
                width: 80px;
                height: 80px;
                font-size: 36px;
            }

            .guru-profil-info h2 {
                font-size: 20px;
            }

            .guru-profil-stats {
                grid-template-columns: 1fr;
            }

            .profil-action-buttons {
                flex-direction: column;
            }

            .profil-action-buttons button {
                width: 100%;
                flex: 1 1 auto;
            }

            .profil-stat-card strong {
                font-size: 24px;
            }
        }
    </style>
</head>
<body class="dashboard-page">
<main class="guru-page">
    <button class="guru-mobile-toggle" type="button" data-guru-sidebar-toggle aria-label="Buka navigasi" aria-controls="guruSidebar" aria-expanded="false">
        <span></span>
        <span></span>
        <span></span>
    </button>
    <div class="guru-sidebar-overlay" data-guru-sidebar-overlay></div>

    <aside class="guru-sidebar" id="guruSidebar">
        <div class="guru-brand">
            <a class="brand" href="index.php">
                <?= chemnama_icon('brand', '#d97706'); ?>
                <span>Nom Comp</span>
            </a>
            <p>Panel Guru</p>
            <div class="guru-class-chip"><?= chemnama_e($teacherKelas); ?></div>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">MENU</span>
            <nav class="guru-menu-list">
                <a class="guru-menu-item" href="dashboard.php">
                    <?= chemnama_icon('chart', '#6b7280'); ?>
                    <span>Dashboard</span>
                </a>
                <a class="guru-menu-item" href="guru_materi.php">
                    <?= chemnama_icon('file', '#6b7280'); ?>
                    <span>Kelola Materi</span>
                </a>
                 <a class="guru-menu-item" href="guru_soal_pg.php">
                    <?= chemnama_icon('stack', '#6b7280'); ?>
                    <span>Bank Soal PG</span>
                </a>
                   <a class="guru-menu-item" href="guru_essay.php">
                    <?= chemnama_icon('edit', '#6b7280'); ?>
                    <span>Tugas Essay</span>
                </a>
                <a class="guru-menu-item" href="guru_homework.php">
                    <?= chemnama_icon('task', '#6b7280'); ?>
                    <span>PR / Homework</span>
                </a>
                 <a class="guru-menu-item" href="guru_quiz_pg.php">
                    <?= chemnama_icon('stack', '#6b7280'); ?>
                    <span>Quick / Quiz</span>
                </a>
                <a class="guru-menu-item" href="guru_games.php">
                    <?= chemnama_icon('beaker', '#6b7280'); ?>
                    <span>Pengaturan Games</span>
                </a>    
                <a class="guru-menu-item" href="guru_intro_siswa.php">
                    <?= chemnama_icon('user', '#6b7280'); ?>
                    <span>Edit Intro Siswa</span>
                </a>
                <a class="guru-menu-item" href="guru_forum.php">
                    <?= chemnama_icon('chat', '#6b7280'); ?>
                    <span>Forum Diskusi</span>
                </a>
                <a class="guru-menu-item" href="guru_data_siswa.php">
                    <?= chemnama_icon('users', '#6b7280'); ?>
                    <span>Data Siswa</span>
                </a>
                <a class="guru-menu-item is-active" href="guru_profil.php">
                    <?= chemnama_icon('user', '#0f9d58'); ?>
                    <span>Profil</span>
                </a>
            </nav>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">NAVIGASI</span>
            <nav class="guru-menu-list">
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_profil.php">
                    <?= chemnama_icon('switch', '#d97706'); ?>
                    <span>Ganti Kelas</span>
                </a>
                <a class="guru-menu-item logout" href="logout.php">
                    <?= chemnama_icon('logout', '#ef4444'); ?>
                    <span>Keluar</span>
                </a>
            </nav>
        </div>
    </aside>

    <section class="guru-content">
        <div class="guru-headline-row">
            <div>
                <h1>Profil Guru</h1>
                <p>Informasi akun anda.</p>
            </div>
            <div class="guru-head-actions">
            </div>
        </div>

        <!-- Profile Header -->
        <div class="guru-profil-header">
            <div class="guru-profil-avatar" style="background: <?= chemnama_e($userComplete['avatar_color'] ?? '#0f9d58'); ?>;">
                <?php if (!empty($userComplete['avatar_path'])): ?>
                    <img class="guru-profil-avatar-image" src="<?= chemnama_e((string) $userComplete['avatar_path']); ?>" alt="Foto profil guru">
                <?php else: ?>
                    <?= substr($user['name'], 0, 1); ?>
                <?php endif; ?>
            </div>
            <div class="guru-profil-info">
                <h2><?= chemnama_e($user['name']); ?></h2>
                <p><?= chemnama_e($user['email']); ?></p>
                <div class="guru-profil-badges">
                    <span class="profil-badge role">
                        <?= chemnama_icon('check', '#d97706'); ?>
                        <?= chemnama_role_label($user['role']); ?>
                    </span>
                    <span class="profil-badge class">
                        <?= chemnama_icon('check', '#0f9d58'); ?>
                        <?= chemnama_e($teacherKelas); ?>
                    </span>
                </div>
            </div>
            <div class="profil-action-buttons">
                <button type="button" data-guru-profile-modal-open="settings">
                    <span class="profil-action-icon"><?= chemnama_icon('magic', '#0f9d58'); ?></span>
                    <span>
                        <strong>Pengaturan</strong>
                        <small>Warna profil & kelas aktif</small>
                    </span>
                </button>
                <button type="button" data-guru-profile-modal-open="password">
                    <span class="profil-action-icon"><?= chemnama_icon('switch', '#d97706'); ?></span>
                    <span>
                        <strong>Ganti Password</strong>
                        <small>Amankan akun guru</small>
                    </span>
                </button>
                <button type="button" data-guru-profile-modal-open="details">
                    <span class="profil-action-icon"><?= chemnama_icon('info', '#2563eb'); ?></span>
                    <span>
                        <strong>Detail Akun</strong>
                        <small>Lihat identitas akun</small>
                    </span>
                </button>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= chemnama_e($flash['type']); ?>"><?= chemnama_e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if (!empty($profileModalError)): ?>
            <div class="alert alert-danger"><?= chemnama_e((string) $profileModalError); ?></div>
        <?php endif; ?>

        <div class="profile-modal-backdrop <?= $settingsModalOpen || $passwordModalOpen || $detailsModalOpen || $notificationsModalOpen ? 'is-open' : ''; ?>" data-guru-profile-modal-backdrop></div>

        <div class="profile-modal <?= $settingsModalOpen ? 'is-open' : ''; ?>" data-guru-profile-modal="settings" role="dialog" aria-modal="true" aria-labelledby="guruSettingsTitle">
            <article class="guru-panel siswa-profile-edit-panel">
                <div class="siswa-profile-modal-head">
                    <h3 id="guruSettingsTitle">Pengaturan Guru</h3>
                    <button class="profile-modal-close" type="button" data-guru-profile-modal-close aria-label="Tutup">
                        <?= chemnama_icon('x', '#64748b'); ?>
                    </button>
                </div>
                <p class="guru-profile-modal-note">Atur tampilan akun guru dan akses kelas aktif dengan cepat.</p>
                <form method="post" enctype="multipart/form-data" class="guru-profile-setting-grid">
                    <input type="hidden" name="action" value="save_settings">
                    <label class="span-2">
                        <span>Kelas aktif</span>
                        <input type="text" value="<?= chemnama_e($teacherKelas); ?>" readonly>
                        <div class="guru-profile-setting-chip-row">
                            <a class="guru-profile-setting-chip" href="guru_pilih_kelas.php?redirect_to=guru_profil.php">
                                <?= chemnama_icon('switch', '#d97706'); ?> Ganti Kelas
                            </a>
                        </div>
                    </label>
                    <label class="span-2">
                        <span>Warna Avatar</span>
                        <div class="forum-custom-select" data-guru-custom-select="avatar_color">
                            <input type="hidden" name="avatar_color" value="<?= chemnama_e($selectedAvatarColor); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <span class="forum-custom-select-label"><?= chemnama_e($selectedAvatarColorLabel); ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <?php foreach ($avatarColorOptions as $color => $label): ?>
                                    <button class="forum-custom-select-option<?= $selectedAvatarColor === $color ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= chemnama_e($color); ?>" data-label="<?= chemnama_e($label); ?>" aria-selected="<?= $selectedAvatarColor === $color ? 'true' : 'false'; ?>">
                                        <?= chemnama_e($label); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </label>
                    <label class="span-2">
                        <span>Foto Profil</span>
                        <input type="file" name="avatar_file" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                        <div class="guru-profile-setting-chip-row">
                            <?php if (!empty($userComplete['avatar_path'])): ?>
                                <span class="guru-profile-setting-chip">
                                    <?= chemnama_icon('check', '#0f9d58'); ?> Foto profil aktif
                                </span>
                                <label class="guru-profile-setting-chip" style="cursor:pointer;">
                                    <input type="checkbox" name="remove_avatar" value="1" style="accent-color:#ef4444; margin-right:6px;">
                                    Hapus foto saat simpan
                                </label>
                            <?php else: ?>
                                <span class="guru-profile-setting-chip">
                                    <?= chemnama_icon('upload', '#2563eb'); ?> Belum ada foto, gunakan inisial
                                </span>
                            <?php endif; ?>
                        </div>
                    </label>
                    <div class="form-action-row span-2">
                        <button class="btn btn-primary" type="submit"><?= chemnama_icon('check', '#ffffff'); ?> Simpan Pengaturan</button>
                        <button class="btn btn-ghost" type="button" data-guru-profile-modal-close>Batal</button>
                    </div>
                </form>
            </article>
        </div>

        <div class="profile-modal <?= $passwordModalOpen ? 'is-open' : ''; ?>" data-guru-profile-modal="password" role="dialog" aria-modal="true" aria-labelledby="guruPasswordTitle">
            <article class="guru-panel siswa-profile-edit-panel">
                <div class="siswa-profile-modal-head">
                    <h3 id="guruPasswordTitle">Ganti Password</h3>
                    <button class="profile-modal-close" type="button" data-guru-profile-modal-close aria-label="Tutup">
                        <?= chemnama_icon('x', '#64748b'); ?>
                    </button>
                </div>
                <p class="guru-profile-modal-note">Gunakan password yang kuat agar akun guru lebih aman.</p>
                <form method="post" class="guru-profile-setting-grid">
                    <input type="hidden" name="action" value="change_password">
                    <label class="span-2">
                        <span>Password Saat Ini</span>
                        <input type="password" name="current_password" required placeholder="Masukkan password saat ini">
                    </label>
                    <label>
                        <span>Password Baru</span>
                        <input type="password" name="new_password" minlength="6" required placeholder="Minimal 6 karakter">
                    </label>
                    <label>
                        <span>Konfirmasi Password Baru</span>
                        <input type="password" name="confirm_password" minlength="6" required placeholder="Ulangi password baru">
                    </label>
                    <div class="form-action-row span-2">
                        <button class="btn btn-primary" type="submit"><?= chemnama_icon('check', '#ffffff'); ?> Simpan Password</button>
                        <button class="btn btn-ghost" type="button" data-guru-profile-modal-close>Batal</button>
                    </div>
                </form>
            </article>
        </div>

        <div class="profile-modal <?= $detailsModalOpen ? 'is-open' : ''; ?>" data-guru-profile-modal="details" role="dialog" aria-modal="true" aria-labelledby="guruDetailsTitle">
            <article class="guru-panel siswa-profile-edit-panel">
                <div class="siswa-profile-modal-head">
                    <h3 id="guruDetailsTitle">Detail Akun Guru</h3>
                    <button class="profile-modal-close" type="button" data-guru-profile-modal-close aria-label="Tutup">
                        <?= chemnama_icon('x', '#64748b'); ?>
                    </button>
                </div>
                <p class="guru-profile-modal-note">Informasi akun ini dipakai untuk identitas guru dan aktivitas pengajaran.</p>
                <div class="guru-profil-grid">
                    <div class="profil-section">
                        <h3><?= chemnama_icon('user', '#0f9d58'); ?> Identitas</h3>
                        <div class="class-list">
                            <div class="class-item"><span class="class-name">Nama</span><span class="class-count"><?= chemnama_e($userComplete['name'] ?? $user['name']); ?></span></div>
                            <div class="class-item"><span class="class-name">Email</span><span class="class-count"><?= chemnama_e($userComplete['email'] ?? $user['email']); ?></span></div>
                            <div class="class-item"><span class="class-name">Peran</span><span class="class-count"><?= chemnama_role_label('guru'); ?></span></div>
                            <div class="class-item"><span class="class-name">Warna Avatar</span><span class="class-count"><?= chemnama_e($userComplete['avatar_color'] ?? '#0f9d58'); ?></span></div>
                        </div>
                    </div>
                    <div class="profil-section">
                        <h3><?= chemnama_icon('clock', '#2563eb'); ?> Aktivitas Guru</h3>
                        <div class="class-list">
                            <div class="class-item"><span class="class-name">Bergabung</span><span class="class-count"><?= chemnama_e(chemnama_format_date((string) ($userComplete['created_at'] ?? null))); ?></span></div>
                            <div class="class-item"><span class="class-name">Hari aktif</span><span class="class-count"><?= chemnama_e((string) $daysActive); ?> hari</span></div>
                            <div class="class-item"><span class="class-name">Kelas aktif</span><span class="class-count"><?= chemnama_e($teacherKelas); ?></span></div>
                            <div class="class-item"><span class="class-name">Siswa di kelas</span><span class="class-count"><?= chemnama_e((string) $studentCount); ?> siswa</span></div>
                        </div>
                    </div>
                </div>
                <div class="form-action-row" style="margin-top: 14px;">
                    <button class="btn btn-primary" type="button" data-guru-profile-modal-close>Oke</button>
                </div>
            </article>
        </div>

        <div class="profile-modal <?= $notificationsModalOpen ? 'is-open' : ''; ?>" data-guru-profile-modal="notifications" role="dialog" aria-modal="true" aria-labelledby="guruNotificationsTitle">
            <article class="guru-panel siswa-profile-edit-panel">
                <div class="siswa-profile-modal-head">
                    <h3 id="guruNotificationsTitle"><?= chemnama_icon('bell', '#d97706'); ?> Notifikasi Guru</h3>
                    <button class="profile-modal-close" type="button" data-guru-profile-modal-close aria-label="Tutup">
                        <?= chemnama_icon('x', '#64748b'); ?>
                    </button>
                </div>
                <p class="guru-profile-modal-note">Ringkasan item yang perlu perhatian anda.</p>
                <div class="class-list" style="margin-top: 12px;">
                    <div class="class-item">
                        <span class="class-name"><?= chemnama_icon('bell', '#d97706'); ?> Permintaan ulang quiz</span>
                        <span class="class-count"><?= chemnama_e((string) $pendingRepeatRequestCount); ?> pending</span>
                    </div>
                    <div class="class-item">
                        <span class="class-name"><?= chemnama_icon('bell', '#d97706'); ?> Essay belum dinilai</span>
                        <span class="class-count"><?= chemnama_e((string) $essayPendingCount); ?> tugas</span>
                    </div>
                    <div class="class-item">
                        <span class="class-name"><?= chemnama_icon('bell', '#d97706'); ?> PR belum dicek</span>
                        <span class="class-count"><?= chemnama_e((string) $homeworkPendingCount); ?> tugas</span>
                    </div>
                </div>
                <div class="form-action-row" style="margin-top: 14px;">
                    <a class="btn btn-primary" href="guru_quiz_pg.php">Cek Permintaan Quiz</a>
                    <button class="btn btn-ghost" type="button" data-guru-profile-modal-close>Tutup</button>
                </div>
            </article>
        </div>

        <!-- Statistics Cards -->
        <div class="guru-profil-stats">
            <div class="profil-stat-card">
                <strong><?= $studentCount; ?></strong>
                <p>Siswa</p>
            </div>
            <div class="profil-stat-card">
                <strong><?= $totalMaterials; ?></strong>
                <p>Materi</p>
            </div>
            <div class="profil-stat-card">
                <strong><?= $totalQuestions; ?></strong>
                <p>Soal</p>
            </div>
            <div class="profil-stat-card">
                <strong><?= $totalEssays + $totalHomework; ?></strong>
                <p>Total Tugas</p>
            </div>
        </div>

        <!-- Main Content Grid -->
        <div class="guru-profil-grid">
            <!-- Kelas yang Diampu -->
            <div class="profil-section">
                <h3>
                    <?= chemnama_icon('users', '#0f9d58'); ?>
                    Kelas yang Diampu
                </h3>
                <div class="class-list">
                    <?php if (!empty($classStats)): ?>
                        <?php foreach ($classStats as $class): ?>
                            <div class="class-item">
                                <span class="class-name"><?= chemnama_e($class['kelas']); ?></span>
                                <span class="class-count"><?= $class['siswa_count']; ?> siswa</span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #6b7280; font-size: 14px;">Belum ada kelas yang ditugaskan</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics -->
            <div class="profil-section">
                <h3>
                    <?= chemnama_icon('stack', '#2563eb'); ?>
                    Ringkasan Konten
                </h3>
                <div class="module-stats-grid">
                    <div class="module-stat-item">
                        <strong><?= $totalMaterials; ?></strong>
                        <div class="counts">Materi</div>
                    </div>
                    <div class="module-stat-item" style="border-color: #2563eb;">
                        <strong><?= $totalQuestions; ?></strong>
                        <div class="counts">Soal PG</div>
                    </div>
                    <div class="module-stat-item" style="border-color: #7c3aed;">
                        <strong><?= $totalEssays; ?></strong>
                        <div class="counts">Tugas Essay</div>
                    </div>
                    <div class="module-stat-item" style="border-color: #0f9d58;">
                        <strong><?= $totalHomework; ?></strong>
                        <div class="counts">PR/Homework</div>
                    </div>
                </div>
            </div>

            <!-- Penilaian -->
            <div class="profil-section">
                <h3>
                    <?= chemnama_icon('edit', '#7c3aed'); ?>
                    Status Penilaian
                </h3>
                <div class="module-stats-grid">
                    <div class="module-stat-item" style="border-color: #7c3aed;">
                        <strong><?= $essayStats['graded'] ?? 0; ?>/<?= $essayStats['total'] ?? 0; ?></strong>
                        <div class="counts">Essay Dinilai</div>
                    </div>
                    <div class="module-stat-item" style="border-color: #10b981;">
                        <strong><?= $homeworkStats['checked'] ?? 0; ?>/<?= $homeworkStats['total'] ?? 0; ?></strong>
                        <div class="counts">PR Dicek</div>
                    </div>
                </div>
            </div>

            <!-- Aktivitas Terbaru -->
            <div class="profil-section">
                <h3>
                    <?= chemnama_icon('clock', '#f59e0b'); ?>
                    Aktivitas Terbaru
                </h3>
                <div class="activity-list">
                    <?php if (!empty($recentActivity)): ?>
                        <?php foreach ($recentActivity as $activity): ?>
                            <div class="activity-item <?= chemnama_e($activity['type']); ?>">
                                <span class="activity-badge <?= chemnama_e($activity['type']); ?>">
                                    <?= strtoupper(substr($activity['type'], 0, 1)); ?>
                                </span>
                                <div class="activity-text">
                                    <strong><?= chemnama_e(substr($activity['title'] ?? '', 0, 50)); ?><?= strlen($activity['title'] ?? '') > 50 ? '...' : ''; ?></strong>
                                    <span class="activity-date"><?= chemnama_format_date($activity['created_at']); ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="color: #6b7280; font-size: 14px;">Belum ada aktivitas</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Info Akun -->
            <div class="profil-section">
                <h3>
                    <?= chemnama_icon('info', '#6b7280'); ?>
                    Informasi Akun
                </h3>
                <div style="display: flex; flex-direction: column; gap: 12px; font-size: 14px;">
                    <div>
                        <p style="margin: 0 0 4px 0; color: #6b7280;">Email</p>
                        <strong style="display: block; color: #ffffff;"><?= chemnama_e($user['email']); ?></strong>
                    </div>
                    <div>
                        <p style="margin: 0 0 4px 0; color: #6b7280;">Bergabung Sejak</p>
                        <strong style="display: block; color: #ffffff;"><?= chemnama_format_date($userComplete['created_at']); ?></strong>
                    </div>
                    <div>
                        <p style="margin: 0 0 4px 0; color: #6b7280;">Hari Aktif</p>
                        <strong style="display: block; color: #ffffff;"><?= $daysActive; ?> hari</strong>
                    </div>
                    <div>
                        <p style="margin: 0 0 4px 0; color: #6b7280;">Status</p>
                        <strong style="display: block; color: #0f9d58;">✓ Aktif</strong>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<script src="assets/js/app.js?v=20260407"></script>
<script>
    // Guru custom selects handler
    (function(){
        document.querySelectorAll('[data-guru-custom-select]').forEach((selectRoot) => {
            const trigger = selectRoot.querySelector('.forum-custom-select-trigger');
            const menu = selectRoot.querySelector('.forum-custom-select-menu');
            const valueInput = selectRoot.querySelector('input[type="hidden"]');
            const label = selectRoot.querySelector('.forum-custom-select-label');
            const options = selectRoot.querySelectorAll('.forum-custom-select-option');

            if (!trigger || !menu || !valueInput || !label || !options.length) return;

            const closeMenu = () => {
                menu.setAttribute('hidden', '');
                trigger.setAttribute('aria-expanded', 'false');
            };

            const openMenu = () => {
                menu.removeAttribute('hidden');
                trigger.setAttribute('aria-expanded', 'true');
            };

            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                if (menu.hasAttribute('hidden')) {
                    openMenu();
                } else {
                    closeMenu();
                }
            });

            options.forEach((option) => {
                option.addEventListener('click', (e) => {
                    e.preventDefault();
                    const value = option.getAttribute('data-value') || '';
                    const optionLabel = option.getAttribute('data-label') || option.textContent;

                    valueInput.value = value;
                    label.textContent = optionLabel;

                    options.forEach((opt) => {
                        if (opt === option) {
                            opt.classList.add('is-selected');
                            opt.setAttribute('aria-selected', 'true');
                        } else {
                            opt.classList.remove('is-selected');
                            opt.setAttribute('aria-selected', 'false');
                        }
                    });

                    closeMenu();
                });
            });

            document.addEventListener('click', (event) => {
                const clickTarget = event.target;
                if (!clickTarget || !selectRoot.contains(clickTarget)) {
                    closeMenu();
                }
            });

            window.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeMenu();
                }
            });
        });
    })();
</script>
<script>
    (function () {
        const modalMap = {
            settings: document.querySelector('[data-guru-profile-modal="settings"]'),
            password: document.querySelector('[data-guru-profile-modal="password"]'),
            details: document.querySelector('[data-guru-profile-modal="details"]'),
            notifications: document.querySelector('[data-guru-profile-modal="notifications"]'),
        };
        const backdrop = document.querySelector('[data-guru-profile-modal-backdrop]');
        const openButtons = document.querySelectorAll('[data-guru-profile-modal-open]');
        const closeButtons = document.querySelectorAll('[data-guru-profile-modal-close]');
        const initialModal = <?= json_encode($profileModal); ?>;

        function closeAll() {
            Object.values(modalMap).forEach((modal) => {
                if (modal) {
                    modal.classList.remove('is-open');
                }
            });

            if (backdrop) {
                backdrop.classList.remove('is-open');
            }

            document.body.classList.remove('is-modal-open');
        }

        function openModal(name) {
            closeAll();
            const modal = modalMap[name];
            if (!modal || !backdrop) {
                return;
            }

            modal.classList.add('is-open');
            backdrop.classList.add('is-open');
            document.body.classList.add('is-modal-open');
        }

        openButtons.forEach((button) => {
            button.addEventListener('click', () => openModal(button.dataset.guruProfileModalOpen || 'details'));
        });

        closeButtons.forEach((button) => {
            button.addEventListener('click', closeAll);
        });

        if (backdrop) {
            backdrop.addEventListener('click', closeAll);
        }

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAll();
            }
        });

        if (initialModal && modalMap[initialModal]) {
            openModal(initialModal);
        }
    })();
</script>
</body>
</html>

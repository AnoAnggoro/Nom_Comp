<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth();

$user = chemnama_current_user();
if (($user['role'] ?? 'siswa') !== 'guru') {
    header('Location: dashboard.php');
    exit;
}

$activeClass = chemnama_get_guru_active_class($pdo, (int) $user['id']);
$guruNotification = chemnama_guru_notification_summary($pdo, (int) $user['id'], $activeClass);
$guruNotificationCount = (int) ($guruNotification['total'] ?? 0);
$modules = $pdo->query('SELECT id, badge, title, accent FROM modules ORDER BY sort_order, id')->fetchAll();
$moduleMap = [];
foreach ($modules as $module) {
    $moduleMap[(int) $module['id']] = $module;
}

$errors = [];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$action = (string) ($_POST['action'] ?? '');
$editId = (int) ($_GET['edit'] ?? 0);
$filterModule = (int) ($_GET['module'] ?? 0);
$currentTab = (string) ($_GET['tab'] ?? 'quiz_pg');
$openQuickForm = isset($_GET['open_quick_form']) ? true : false;

// Quick Quiz Upload Setup
$uploadDir = APP_ROOT . '/storage/uploads/quick_quiz_images';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
$allowedImageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxImageSize = 5 * 1024 * 1024; // 5MB

// Quick Quiz - now uses module_id directly (no material mapping needed)

if ($action === 'save_set') {
    $quizSetId = (int) ($_POST['quiz_set_id'] ?? 0);
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $questionTimeLimitUnit = strtolower(trim((string) ($_POST['question_time_limit_unit'] ?? 'detik')));
    if (!in_array($questionTimeLimitUnit, ['detik', 'menit'], true)) {
        $questionTimeLimitUnit = 'detik';
    }
    $questionTimeLimitValue = (int) ($_POST['question_time_limit_value'] ?? ($_POST['question_time_limit_seconds'] ?? 0));
    $questionTimeLimitSeconds = $questionTimeLimitUnit === 'menit'
        ? $questionTimeLimitValue * 60
        : $questionTimeLimitValue;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    if ($moduleId <= 0 || !isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul tidak valid.';
    }
    if ($title === '') {
        $errors[] = 'Judul quiz tidak boleh kosong.';
    }
    if ($questionTimeLimitValue < 0) {
        $errors[] = 'Timer per soal tidak boleh negatif.';
    }
    if ($questionTimeLimitSeconds > 600) {
        $errors[] = 'Timer per soal maksimal 600 detik (10 menit).';
    }

    if (count($errors) === 0) {
        if ($quizSetId > 0) {
            $stmt = $pdo->prepare(
                'UPDATE quiz_sets
                  SET module_id = ?, class_name = ?, title = ?, description = ?, question_time_limit_seconds = ?, is_published = ?, published_at = IF(? = 1 AND published_at IS NULL, NOW(), published_at), sort_order = ?, updated_at = NOW()
                  WHERE id = ? AND created_by = ? AND class_name = ?'
            );
              $stmt->execute([$moduleId, $activeClass, $title, $description !== '' ? $description : null, $questionTimeLimitSeconds, $isPublished, $isPublished, $sortOrder, $quizSetId, $user['id'], $activeClass]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Quiz berhasil diperbarui.'];
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO quiz_sets (module_id, created_by, class_name, title, description, question_time_limit_seconds, is_published, published_at, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $moduleId,
                $user['id'],
                $activeClass,
                $title,
                $description !== '' ? $description : null,
                $questionTimeLimitSeconds,
                $isPublished,
                $isPublished ? date('Y-m-d H:i:s') : null,
                $sortOrder,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Quiz baru berhasil dibuat.'];
        }

        header('Location: guru_quiz_pg.php');
        exit;
    }
}

if ($action === 'delete_set') {
    $quizSetId = (int) ($_POST['quiz_set_id'] ?? 0);
    if ($quizSetId > 0) {
        $stmt = $pdo->prepare('DELETE FROM quiz_sets WHERE id = ? AND created_by = ? AND class_name = ?');
        $stmt->execute([$quizSetId, $user['id'], $activeClass]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Quiz berhasil dihapus.'];
        header('Location: guru_quiz_pg.php');
        exit;
    }
}

if ($action === 'toggle_publish') {
    $quizSetId = (int) ($_POST['quiz_set_id'] ?? 0);
    if ($quizSetId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE quiz_sets
             SET is_published = CASE WHEN is_published = 1 THEN 0 ELSE 1 END,
                 published_at = CASE WHEN is_published = 1 THEN published_at ELSE NOW() END,
                 updated_at = NOW()
               WHERE id = ? AND created_by = ? AND class_name = ?'
        );
           $stmt->execute([$quizSetId, $user['id'], $activeClass]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'Status publish quiz diperbarui.'];
        header('Location: guru_quiz_pg.php');
        exit;
    }
}

if ($action === 'review_request') {
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $decision = (string) ($_POST['decision'] ?? '');
    if ($requestId > 0 && in_array($decision, ['approved', 'rejected'], true)) {
        $stmt = $pdo->prepare(
            'UPDATE quiz_repeat_requests qr
             JOIN quiz_sets qs ON qs.id = qr.quiz_set_id
             SET qr.status = ?, qr.decided_at = NOW(), qr.decided_by = ?
               WHERE qr.id = ? AND qs.created_by = ? AND qs.class_name = ?'
        );
           $stmt->execute([$decision, $user['id'], $requestId, $user['id'], $activeClass]);
        $_SESSION['flash'] = ['type' => 'success', 'message' => $decision === 'approved' ? 'Permintaan diizinkan.' : 'Permintaan ditolak.'];
        header('Location: guru_quiz_pg.php?tab=' . $currentTab);
        exit;
    }
}

// Quick Quiz Actions
if ($action === 'save_quick_quiz') {
    $quizId = (int) ($_POST['quick_quiz_id'] ?? 0);
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $questionText = trim((string) ($_POST['question_text'] ?? ''));
    $optionA = trim((string) ($_POST['option_a'] ?? ''));
    $optionB = trim((string) ($_POST['option_b'] ?? ''));
    $optionC = trim((string) ($_POST['option_c'] ?? ''));
    $optionD = trim((string) ($_POST['option_d'] ?? ''));
    $correctOption = strtolower(trim((string) ($_POST['correct_option'] ?? '')));
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    // Validation
    if ($moduleId <= 0 || !isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul wajib dipilih.';
    }

    if ($questionText === '') {
        $errors[] = 'Pertanyaan wajib diisi.';
    }
    if ($optionA === '' || $optionB === '' || $optionC === '' || $optionD === '') {
        $errors[] = 'Semua opsi jawaban wajib diisi.';
    }
    if (!in_array($correctOption, ['a', 'b', 'c', 'd'], true)) {
        $errors[] = 'Jawaban benar harus salah satu dari a, b, c, atau d.';
    }

    $imageUrl = null;
    if (isset($_FILES['image']) && (int) $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int) $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload gambar gagal.';
        } else if ((int) $_FILES['image']['size'] > $maxImageSize) {
            $errors[] = 'Ukuran gambar terlalu besar (max 5MB).';
        } else {
            $originalName = (string) $_FILES['image']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedImageExt, true)) {
                $errors[] = 'Format gambar harus JPG, PNG, GIF, atau WEBP.';
            } else {
                $newName = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
                $target = $uploadDir . '/' . $newName;

                if (!move_uploaded_file((string) $_FILES['image']['tmp_name'], $target)) {
                    $errors[] = 'Gambar tidak dapat disimpan ke server.';
                } else {
                    $imageUrl = 'storage/uploads/quick_quiz_images/' . $newName;
                    // Delete old image if editing
                    if ($quizId > 0) {
                        $oldQuiz = $pdo->prepare('SELECT image_url FROM quick_quizzes WHERE id = :id LIMIT 1');
                        $oldQuiz->execute(['id' => $quizId]);
                        $oldRow = $oldQuiz->fetch();
                        if ($oldRow && !empty($oldRow['image_url'])) {
                            $oldPath = APP_ROOT . '/' . ltrim((string) $oldRow['image_url'], '/');
                            if (is_file($oldPath)) {
                                @unlink($oldPath);
                            }
                        }
                    }
                }
            }
        }
    }

    if (count($errors) === 0) {
        if ($quizId > 0) {
            // Update
            $updateImagePart = $imageUrl !== null 
                ? ', image_url = :image_url' 
                : '';
            $updateSql = 'UPDATE quick_quizzes SET
                module_id = :module_id,
                question_text = :question_text,
                option_a = :option_a,
                option_b = :option_b,
                option_c = :option_c,
                option_d = :option_d,
                correct_option = :correct_option,
                is_published = :is_published,
                updated_at = NOW()
                ' . $updateImagePart . '
                WHERE id = :id AND created_by = :created_by';
            
            $stmt = $pdo->prepare($updateSql);
            $params = [
                'id' => $quizId,
                'created_by' => (int)$user['id'],
                'module_id' => $moduleId,
                'question_text' => $questionText,
                'option_a' => $optionA,
                'option_b' => $optionB,
                'option_c' => $optionC,
                'option_d' => $optionD,
                'correct_option' => $correctOption,
                'is_published' => $isPublished,
            ];
            if ($imageUrl !== null) {
                $params['image_url'] = $imageUrl;
            }
            $stmt->execute($params);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Quick Quiz berhasil diperbarui.'];
        } else {
            // Insert
            $stmt = $pdo->prepare(
                'INSERT INTO quick_quizzes (
                    module_id, created_by, question_text, image_url,
                    option_a, option_b, option_c, option_d, correct_option, is_published
                ) VALUES (
                    :module_id, :created_by, :question_text, :image_url,
                    :option_a, :option_b, :option_c, :option_d, :correct_option, :is_published
                )'
            );
            $stmt->execute([
                'module_id' => $moduleId,
                'created_by' => (int)$user['id'],
                'question_text' => $questionText,
                'image_url' => $imageUrl,
                'option_a' => $optionA,
                'option_b' => $optionB,
                'option_c' => $optionC,
                'option_d' => $optionD,
                'correct_option' => $correctOption,
                'is_published' => $isPublished,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Quick Quiz berhasil ditambahkan.'];
        }

        header('Location: guru_quiz_pg.php?tab=' . $currentTab);
        exit;
    }
}

if ($action === 'delete_quick_quiz') {
    $quizId = (int) ($_POST['quick_quiz_id'] ?? 0);
    if ($quizId > 0) {
        $quiz = $pdo->prepare('SELECT image_url FROM quick_quizzes WHERE id = :id AND created_by = :created_by LIMIT 1');
        $quiz->execute(['id' => $quizId, 'created_by' => (int)$user['id']]);
        $row = $quiz->fetch();

        if ($row) {
            // Delete image file
            if (!empty($row['image_url'])) {
                $imagePath = APP_ROOT . '/' . ltrim((string) $row['image_url'], '/');
                if (is_file($imagePath)) {
                    @unlink($imagePath);
                }
            }
            // Delete from database
            $delete = $pdo->prepare('DELETE FROM quick_quizzes WHERE id = :id AND created_by = :created_by');
            $delete->execute(['id' => $quizId, 'created_by' => (int)$user['id']]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Quick Quiz berhasil dihapus.'];
        }
    }

    header('Location: guru_quiz_pg.php?tab=' . $currentTab);
    exit;
}

$quizSetsStmt = $pdo->prepare(
    'SELECT qs.id, qs.module_id, qs.title, qs.description, qs.question_time_limit_seconds, qs.is_published, qs.published_at, qs.sort_order,
            m.badge AS module_badge, m.title AS module_title,
            (SELECT COUNT(*) FROM questions q WHERE q.quiz_set_id = qs.id AND q.is_published = 1) AS total_questions,
            (SELECT COUNT(*) FROM quiz_attempt_runs qar WHERE qar.quiz_set_id = qs.id) AS attempt_count
     FROM quiz_sets qs
     JOIN modules m ON m.id = qs.module_id
     WHERE qs.created_by = :created_by
      AND qs.class_name = :class_name
     ORDER BY m.sort_order, qs.sort_order, qs.id'
);
$quizSetsStmt->execute(['created_by' => $user['id'], 'class_name' => $activeClass]);
$quizSets = $quizSetsStmt->fetchAll();

// Get quick quizzes
$quickQuizzesStmt = $pdo->prepare(
    'SELECT qq.*, mo.title AS module_title, mo.badge AS module_badge,
            COUNT(qqa.id) AS attempt_count
     FROM quick_quizzes qq
     JOIN modules mo ON mo.id = qq.module_id
     LEFT JOIN quick_quiz_attempts qqa ON qqa.quick_quiz_id = qq.id
     WHERE qq.created_by = :created_by
     GROUP BY qq.id
     ORDER BY mo.sort_order, qq.created_at DESC'
);
$quickQuizzesStmt->execute(['created_by' => (int)$user['id']]);
$allQuickQuizzes = $quickQuizzesStmt->fetchAll();

// Group quick quizzes by module
$quickQuizzesByModule = [];
foreach ($allQuickQuizzes as $quiz) {
    $moduleId = (int) $quiz['module_id'];
    if (!isset($quickQuizzesByModule[$moduleId])) {
        $quickQuizzesByModule[$moduleId] = [];
    }
    $quickQuizzesByModule[$moduleId][] = $quiz;
}

// For form - get modules for dropdown
$formModules = $modules; // Use existing $modules list

if ($filterModule > 0) {
    $quizSets = array_values(array_filter($quizSets, static fn (array $quizSet): bool => (int) $quizSet['module_id'] === $filterModule));
}

$quizSetCount = count($quizSets);
$publishedCount = 0;
$totalQuestions = 0;
foreach ($quizSets as $quizSet) {
    $totalQuestions += (int) $quizSet['total_questions'];
    if ((int) $quizSet['is_published'] === 1) {
        $publishedCount += 1;
    }
}

$pendingRequestsStmt = $pdo->prepare(
    'SELECT qr.id, qr.reason, qr.status, qr.requested_at, qr.decided_at,
            u.name AS student_name,
            qs.title AS quiz_title,
            m.badge AS module_badge
     FROM quiz_repeat_requests qr
     JOIN quiz_sets qs ON qs.id = qr.quiz_set_id
     JOIN modules m ON m.id = qs.module_id
     JOIN users u ON u.id = qr.student_id
    WHERE qs.created_by = :created_by AND qs.class_name = :class_name AND qr.status = "pending"
     ORDER BY qr.requested_at ASC, qr.id ASC'
);
$pendingRequestsStmt->execute(['created_by' => $user['id'], 'class_name' => $activeClass]);
$pendingRequests = $pendingRequestsStmt->fetchAll();

$selectedQuizSet = null;
if ($editId > 0) {
    foreach ($quizSets as $quizSet) {
        if ((int) $quizSet['id'] === $editId) {
            $selectedQuizSet = $quizSet;
            break;
        }
    }
}

// Get editing quick quiz
$editingQuickQuiz = null;
$editingQuickQuizId = (int) ($_GET['edit_quick'] ?? 0);
if ($editingQuickQuizId > 0) {
    $editStmt = $pdo->prepare('SELECT qq.* FROM quick_quizzes qq WHERE qq.id = :id AND qq.created_by = :created_by LIMIT 1');
    $editStmt->execute(['id' => $editingQuickQuizId, 'created_by' => (int)$user['id']]);
    $editingQuickQuiz = $editStmt->fetch();
    if (!$editingQuickQuiz) {
        $editingQuickQuizId = 0;
    }
}

$quickFormData = [
    'quick_quiz_id' => $editingQuickQuiz['id'] ?? 0,
    'module_id' => (int) ($editingQuickQuiz['module_id'] ?? ($modules[0]['id'] ?? 0)),
    'question_text' => (string) ($editingQuickQuiz['question_text'] ?? ''),
    'option_a' => (string) ($editingQuickQuiz['option_a'] ?? ''),
    'option_b' => (string) ($editingQuickQuiz['option_b'] ?? ''),
    'option_c' => (string) ($editingQuickQuiz['option_c'] ?? ''),
    'option_d' => (string) ($editingQuickQuiz['option_d'] ?? ''),
    'correct_option' => (string) ($editingQuickQuiz['correct_option'] ?? ''),
    'image_url' => (string) ($editingQuickQuiz['image_url'] ?? ''),
    'is_published' => (int) ($editingQuickQuiz['is_published'] ?? 1),
];

$quizModalOpen = ($currentTab === 'quiz_pg' && isset($_GET['edit']) || count($errors) > 0 && $currentTab === 'quiz_pg');
$selectedQuizTimerSeconds = (int) ($selectedQuizSet['question_time_limit_seconds'] ?? 0);
$selectedQuizTimerUnit = $selectedQuizTimerSeconds > 0 && $selectedQuizTimerSeconds % 60 === 0 ? 'menit' : 'detik';
$selectedQuizTimerValue = $selectedQuizTimerUnit === 'menit'
    ? intdiv($selectedQuizTimerSeconds, 60)
    : $selectedQuizTimerSeconds;

$guruMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
    ['label' => 'Quick / Quiz', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => true],
    ['label' => 'Pengaturan Games', 'icon' => 'beaker', 'href' => 'guru_games.php', 'active' => false],
    ['label' => 'Edit Intro Siswa', 'icon' => 'edit', 'href' => 'guru_intro_siswa.php', 'active' => false],
    ['label' => 'Forum Diskusi', 'icon' => 'chat', 'href' => 'guru_forum.php', 'active' => false],
    ['label' => 'Data Siswa', 'icon' => 'users', 'href' => 'guru_data_siswa.php', 'active' => false],
    ['label' => 'Profil', 'icon' => 'user', 'href' => 'guru_profil.php', 'active' => false],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quiz PG - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .quiz-tabs {
            display: flex;
            gap: 12px;
            margin: 16px 0 24px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 0;
        }

        .quiz-tab-btn {
            padding: 12px 20px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: #ffffff;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
            font-size: 0.95rem;
        }

        .quiz-tab-btn:hover {
        }

        .quiz-tab-btn.is-active {
            color: #ffffff;
            border-bottom-color: #ffffff;
        }

        .quick-quiz-image-preview {
            margin-top: 12px;
            max-width: 200px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }

        .quick-quiz-image-preview img {
            width: 100%;
            height: auto;
            display: block;
        }

        .quick-quiz-option-preview {
            padding: 8px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 6px;
            font-size: 0.85rem;
            margin-bottom: 4px;
        }

        .quick-quiz-option-preview.correct {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
            border-left: 3px solid #22c55e;
            padding-left: 6px;
        }

        .quick-quiz-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            transition: all 0.25s ease;
        }

        .quick-quiz-card:hover {
            border-color: rgba(124, 58, 237, 0.3);
            background: rgba(255, 255, 255, 0.05);
        }

        .quick-quiz-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }

        .quick-quiz-card-title {
            font-weight: 600;
            color: #e2e8f0;
            margin: 0;
        }

        .quick-quiz-card-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .quick-quiz-badge {
            display: inline-block;
            padding: 4px 8px;
            background: rgba(124, 58, 237, 0.2);
            color: #a78bfa;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .quick-quiz-badge.published {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .quick-quiz-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .hidden-tab {
            display: none;
        }

        .visible-tab {
            display: block;
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
            <div class="guru-class-chip"><?= chemnama_e($activeClass); ?></div>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">MENU</span>
            <nav class="guru-menu-list">
                <?php foreach ($guruMenus as $menu): ?>
                    <a class="guru-menu-item <?= $menu['active'] ? 'is-active' : ''; ?>" href="<?= chemnama_e($menu['href']); ?>">
                        <?= chemnama_icon($menu['icon'], $menu['active'] ? '#0f9d58' : '#6b7280'); ?>
                        <span><?= chemnama_e($menu['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">NAVIGASI</span>
            <nav class="guru-menu-list">
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_quiz_pg.php">
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
                <h1>Quiz & Quick Quiz</h1>
                <p>Kelola Quiz PG dan Quick Quiz per materi, publish sebelum tampil ke siswa.</p>
            </div>
        </div>
        <a class="guru-back-link guru-back-link-inline" href="javascript:history.back()"><?= chemnama_icon('switch', '#64748b'); ?> Kembali</a>

        <!-- Tab Navigation -->
        <div class="quiz-tabs">
            <a href="guru_quiz_pg.php?tab=quiz_pg" class="quiz-tab-btn <?= $currentTab === 'quiz_pg' ? 'is-active' : ''; ?>">
                <?= chemnama_icon('stack', 'currentColor'); ?> Quiz PG
            </a>
            <a href="guru_quiz_pg.php?tab=quick_quiz" class="quiz-tab-btn <?= $currentTab === 'quick_quiz' ? 'is-active' : ''; ?>">
                <?= chemnama_icon('check', 'currentColor'); ?> Quick Quiz (Step 2)
            </a>
        </div>

        <!-- Action Buttons per Tab -->
        <?php if ($currentTab === 'quiz_pg'): ?>
            <div class="guru-head-actions" style="margin-bottom: 20px;">
                <a class="btn btn-primary" href="guru_quiz_pg.php?tab=quiz_pg&edit=0#quizFormModal">
                    <?= chemnama_icon('plus', '#ffffff'); ?> Tambah Quiz Baru
                </a>
            </div>
        <?php else: ?>
            <div class="guru-head-actions" style="margin-bottom: 20px;">
                <a class="btn btn-primary" href="guru_quiz_pg.php?tab=quick_quiz&open_quick_form=1#quickQuizModal">
                    <?= chemnama_icon('plus', '#ffffff'); ?> Tambah Quick Quiz
                </a>
            </div>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="alert alert-<?= chemnama_e($flash['type']); ?>"><?= chemnama_e($flash['message']); ?></div>
        <?php endif; ?>

        <?php if (count($errors) > 0): ?>
            <div class="alert alert-danger">
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= chemnama_e($err); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- QUIZ PG TAB CONTENT -->
        <div id="quiz-pg-tab" class="<?= $currentTab === 'quiz_pg' ? 'visible-tab' : 'hidden-tab'; ?>">

        <div class="guru-stats-row">
            <article class="guru-stat-card">
                <div class="guru-stat-icon" style="color:#0f9d58;background:#0f9d5873;">
                    <?= chemnama_icon('stack', '#0f9d58'); ?>
                </div>
                <strong><?= chemnama_e((string) $quizSetCount); ?></strong>
                <p>Quiz Set</p>
            </article>
            <article class="guru-stat-card">
                <div class="guru-stat-icon" style="color:#d97706;background:#d9770673;">
                    <?= chemnama_icon('check', '#d97706'); ?>
                </div>
                <strong><?= chemnama_e((string) $publishedCount); ?></strong>
                <p>Sudah Publish</p>
            </article>
            <article class="guru-stat-card">
                <div class="guru-stat-icon" style="color:#2563eb;background:#2563eb73;">
                    <?= chemnama_icon('file', '#2563eb'); ?>
                </div>
                <strong><?= chemnama_e((string) $totalQuestions); ?></strong>
                <p>Total Soal</p>
            </article>
            <article class="guru-stat-card">
                <div class="guru-stat-icon" style="color:#ec4899;background:#ec489973;">
                    <?= chemnama_icon('clock', '#ec4899'); ?>
                </div>
                <strong><?= chemnama_e((string) count($pendingRequests)); ?></strong>
                <p>Request Ulang</p>
            </article>
        </div>

        <div class="guru-grid guru-grid-top">
            <article class="guru-panel">
                <h3>Tambah Quiz Baru</h3>
                <p style="margin:0;color:#ffffff;line-height:1.7;">Klik tombol Tambah Quiz Baru di kanan atas untuk membuka form popup. Edit nama quiz juga dibuka dari popup yang sama.</p>
                <div class="panel-list compact-list" style="margin-top:14px;">
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#0f9d58;">
                            <?= chemnama_icon('plus', '#0f9d58'); ?>
                        </div>
                        <div>
                            <strong>Form popup</strong>
                            <p>Lebih ringkas saat membuat atau mengubah quiz.</p>
                        </div>
                    </div>
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#2563eb;">
                            <?= chemnama_icon('stack', '#2563eb'); ?>
                        </div>
                        <div>
                            <strong>Kelola soal</strong>
                            <p>Masuk ke halaman soal quiz yang dipilih.</p>
                        </div>
                    </div>
                </div>
            </article>

            <article class="guru-panel">
                <h3>Request Ulang</h3>
                <div class="panel-list compact-list">
                    <?php if (!empty($pendingRequests)): ?>
                        <?php foreach ($pendingRequests as $request): ?>
                            <div class="panel-item compact-item">
                                <div class="panel-icon" style="color:#0f9d58;">
                                    <?= chemnama_icon('chat', '#0f9d58'); ?>
                                </div>
                                <div>
                                    <strong><?= chemnama_e($request['student_name']); ?></strong>
                                    <p><?= chemnama_e($request['module_badge'] . ' - ' . $request['quiz_title']); ?></p>
                                    <small><?= chemnama_e((string) ($request['reason'] ?: 'Tanpa alasan')); ?></small>
                                </div>
                                <div class="homework-row-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="review_request">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id']; ?>">
                                        <input type="hidden" name="decision" value="approved">
                                        <button class="btn btn-primary btn-sm" type="submit">Izinkan</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="review_request">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id']; ?>">
                                        <input type="hidden" name="decision" value="rejected">
                                        <button class="btn btn-ghost btn-sm" type="submit">Tolak</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="panel-item compact-item">
                            <div class="panel-icon" style="color:#0f9d58;">
                                <?= chemnama_icon('check', '#0f9d58'); ?>
                            </div>
                            <div>
                                <strong>Tidak ada request</strong>
                                <p>Semua permintaan retake akan muncul di sini.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </div>

        <div class="guru-grid guru-grid-bottom">
            <article class="guru-panel">
                <h3>Daftar Quiz</h3>
                <div class="panel-list compact-list">
                    <?php if (!empty($quizSets)): ?>
                        <?php foreach ($quizSets as $quizSet): ?>
                            <div class="panel-item compact-item quiz-compact-item">
                                <div class="panel-icon" style="color:#0f9d58;">
                                    <?= chemnama_icon('stack', '#0f9d58'); ?>
                                </div>
                                <div>
                                    <strong><?= chemnama_e($quizSet['module_badge'] . ' - ' . $quizSet['title']); ?></strong>
                                    <p><?= chemnama_e((string) ($quizSet['description'] ?: 'Tidak ada deskripsi')); ?></p>
                                    <small>
                                        <?= chemnama_e((string) $quizSet['total_questions']); ?> soal · <?= chemnama_e((string) $quizSet['attempt_count']); ?> attempt
                                        <?php if ((int) ($quizSet['question_time_limit_seconds'] ?? 0) > 0): ?>
                                            · timer <?= (int) $quizSet['question_time_limit_seconds']; ?> detik/soal
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="homework-row-actions">
                                    <a class="btn btn-ghost btn-sm" href="guru_soal_pg.php?quiz_set_id=<?= (int) $quizSet['id']; ?>&manage_quiz=1">Kelola Soal</a>
                                    <a class="btn btn-primary btn-sm" href="guru_quiz_pg.php?tab=quiz_pg&edit=<?= (int) $quizSet['id']; ?>#quizFormModal">Edit</a>
                                    <form method="post" onsubmit="return confirm('Hapus quiz ini?');">
                                        <input type="hidden" name="action" value="toggle_publish">
                                        <input type="hidden" name="quiz_set_id" value="<?= (int) $quizSet['id']; ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit"><?= (int) $quizSet['is_published'] === 1 ? 'Unpublish' : 'Publish'; ?></button>
                                    </form>
                                    <form method="post" onsubmit="return confirm('Hapus quiz ini? Semua soal dan attempt terkait akan ikut hilang.');">
                                        <input type="hidden" name="action" value="delete_set">
                                        <input type="hidden" name="quiz_set_id" value="<?= (int) $quizSet['id']; ?>">
                                        <button class="btn btn-ghost btn-sm" type="submit">Hapus</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="panel-item compact-item">
                            <div class="panel-icon" style="color:#0f9d58;">
                                <?= chemnama_icon('stack', '#0f9d58'); ?>
                            </div>
                            <div>
                                <strong>Belum ada quiz</strong>
                                <p>Buat quiz pertama untuk modul yang aktif.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="guru-panel">
                <h3>Panduan Singkat</h3>
                <div class="panel-list compact-list">
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#d97706;">
                            <?= chemnama_icon('file', '#d97706'); ?>
                        </div>
                        <div>
                            <strong>1. Buat Quiz</strong>
                            <p>Buat set quiz terlebih dahulu untuk modul yang diinginkan.</p>
                        </div>
                    </div>
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#2563eb;">
                            <?= chemnama_icon('edit', '#2563eb'); ?>
                        </div>
                        <div>
                            <strong>2. Isi Soal</strong>
                            <p>Pindah ke Bank Soal PG untuk menempelkan soal ke quiz set tersebut.</p>
                        </div>
                    </div>
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#0f9d58;">
                            <?= chemnama_icon('check', '#0f9d58'); ?>
                        </div>
                        <div>
                            <strong>3. Publish</strong>
                            <p>Quiz baru akan tampil ke siswa hanya setelah dipublish.</p>
                        </div>
                    </div>
                </div>
            </article>
        </div>
        </div> <!-- END QUIZ PG TAB -->

        <!-- QUICK QUIZ TAB CONTENT -->
        <div id="quick-quiz-tab" class="<?= $currentTab === 'quick_quiz' ? 'visible-tab' : 'hidden-tab'; ?>">
            <?php if ($currentTab === 'quick_quiz'): ?>
                <!-- Stats for Quick Quiz -->
                <div class="guru-stats-row">
                    <article class="guru-stat-card">
                        <div class="guru-stat-icon" style="color:#7c3aed;background:#7c3aed73;">
                            <?= chemnama_icon('check', '#7c3aed'); ?>
                        </div>
                        <strong><?= count($allQuickQuizzes); ?></strong>
                        <p>Quick Quiz</p>
                    </article>
                    <article class="guru-stat-card">
                        <div class="guru-stat-icon" style="color:#d97706;background:#d9770673;">
                            <?= chemnama_icon('check', '#d97706'); ?>
                        </div>
                        <strong><?= count(array_filter($allQuickQuizzes, static fn (array $q): bool => (int)$q['is_published'] === 1)); ?></strong>
                        <p>Sudah Publish</p>
                    </article>
                    <article class="guru-stat-card">
                        <div class="guru-stat-icon" style="color:#2563eb;background:#2563eb73;">
                            <?= chemnama_icon('users', '#2563eb'); ?>
                        </div>
                        <strong><?= array_sum(array_map(static fn (array $q): int => (int)$q['attempt_count'], $allQuickQuizzes)); ?></strong>
                        <p>Total Jawaban</p>
                    </article>
                </div>

                <!-- Quick Quiz List by Module -->
                <div class="guru-grid guru-grid-bottom">
                    <article class="guru-panel">
                        <h3>Daftar Quick Quiz</h3>
                        <div class="panel-list compact-list">
                            <?php if (empty($allQuickQuizzes)): ?>
                                <div class="panel-item compact-item">
                                    <div class="panel-icon" style="color:#7c3aed;">
                                        <?= chemnama_icon('check', '#7c3aed'); ?>
                                    </div>
                                    <div>
                                        <strong>Belum ada quick quiz</strong>
                                        <p>Buat quick quiz pertama dengan tombol di atas.</p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($modules as $module): ?>
                                    <?php if (isset($quickQuizzesByModule[(int)$module['id']])): ?>
                                        <?php foreach ($quickQuizzesByModule[(int)$module['id']] as $quiz): ?>
                                            <div class="panel-item compact-item">
                                                <div class="panel-icon" style="color:#7c3aed;">
                                                    <?= chemnama_icon('check', '#7c3aed'); ?>
                                                </div>
                                                <div>
                                                    <strong><?= chemnama_e($quiz['module_badge'] . ' - ' . $quiz['module_title']); ?></strong>
                                                    <p><?= chemnama_e(substr((string)$quiz['question_text'], 0, 60) . (strlen((string)$quiz['question_text']) > 60 ? '...' : '')); ?></p>
                                                    <small>
                                                        <span class="quick-quiz-badge <?= (int)$quiz['is_published'] === 1 ? 'published' : ''; ?>">
                                                            <?= (int)$quiz['is_published'] === 1 ? '✓ Publish' : 'Draft'; ?>
                                                        </span>
                                                        <?= (int)$quiz['attempt_count']; ?> dijawab
                                                    </small>
                                                </div>
                                                <div class="homework-row-actions">
                                                    <a class="btn btn-primary btn-sm" href="guru_quiz_pg.php?tab=quick_quiz&edit_quick=<?= (int)$quiz['id']; ?>#quickQuizModal">Edit</a>
                                                    <form method="post" onsubmit="return confirm('Hapus quick quiz ini?');" style="display:inline;">
                                                        <input type="hidden" name="action" value="delete_quick_quiz">
                                                        <input type="hidden" name="quick_quiz_id" value="<?= (int)$quiz['id']; ?>">
                                                        <button class="btn btn-ghost btn-sm" type="submit">Hapus</button>
                                                    </form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>

                    <article class="guru-panel">
                        <h3>Panduan Quick Quiz</h3>
                        <div class="panel-list compact-list">
                            <div class="panel-item compact-item">
                                <div class="panel-icon" style="color:#7c3aed;">
                                    <?= chemnama_icon('file', '#7c3aed'); ?>
                                </div>
                                <div>
                                    <strong>Step 2 Materi</strong>
                                    <p>Quick Quiz adalah kuis cepat dengan 1 soal pilihan ganda yang muncul di Step 2 pembelajaran materi.</p>
                                </div>
                            </div>
                            <div class="panel-item compact-item">
                                <div class="panel-icon" style="color:#d97706;">
                                    <?= chemnama_icon('image', '#d97706'); ?>
                                </div>
                                <div>
                                    <strong>Dengan Gambar</strong>
                                    <p>Dukung upload gambar untuk tebak gambar atau visual learning. Opsional untuk soal teks biasa.</p>
                                </div>
                            </div>
                            <div class="panel-item compact-item">
                                <div class="panel-icon" style="color:#0f9d58;">
                                    <?= chemnama_icon('check', '#0f9d58'); ?>
                                </div>
                                <div>
                                    <strong>1 Kali Jawab</strong>
                                    <p>Setiap siswa hanya bisa menjawab quick quiz sekali. Feedback langsung ditampilkan saat submit.</p>
                                </div>
                            </div>
                        </div>
                    </article>
                </div>
            <?php endif; ?>
        </div> <!-- END QUICK QUIZ TAB -->
    </section>
</main>

<div class="quiz-modal <?= $quizModalOpen ? 'is-open' : ''; ?>" id="quizFormModal" aria-hidden="<?= $quizModalOpen ? 'false' : 'true'; ?>">
    <div class="quiz-modal-backdrop" data-quiz-modal-close></div>
    <div class="quiz-modal-card guru-panel">
        <div class="quiz-modal-head">
            <div>
                <h3><?= $selectedQuizSet ? 'Edit Quiz' : 'Tambah Quiz Baru'; ?></h3>
                <p>Isi detail quiz lalu simpan. Form ini muncul sebagai popup.</p>
            </div>
            <a class="quiz-modal-close" href="guru_quiz_pg.php" aria-label="Tutup form">&times;</a>
        </div>
        <form method="post" class="quiz-form-grid">
            <input type="hidden" name="action" value="save_set">
            <input type="hidden" name="quiz_set_id" value="<?= chemnama_e((string) ($selectedQuizSet['id'] ?? 0)); ?>">

            <label>
                <span>Modul</span>
                <div class="forum-custom-select" data-guru-custom-select="quiz_module_id">
                    <input type="hidden" name="module_id" value="<?= chemnama_e((string) ($selectedQuizSet['module_id'] ?? 0)); ?>">
                    <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                        <?php
                            $selectedQuizModuleLabel = 'Pilih Modul';
                            $currentQuizModuleId = (int) ($selectedQuizSet['module_id'] ?? 0);
                            foreach ($modules as $m) {
                                if ((int) $m['id'] === $currentQuizModuleId) {
                                    $selectedQuizModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                    break;
                                }
                            }
                        ?>
                        <span class="forum-custom-select-label"><?= chemnama_e($selectedQuizModuleLabel); ?></span>
                        <span class="forum-custom-select-caret" aria-hidden="true"></span>
                    </button>
                    <div class="forum-custom-select-menu" role="listbox" hidden>
                        <?php foreach ($modules as $module): ?>
                            <button class="forum-custom-select-option<?= $currentQuizModuleId === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= (int) $module['id']; ?>" data-label="<?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?php echo $currentQuizModuleId === (int) $module['id'] ? 'true' : 'false'; ?>">
                                <?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </label>

            <label>
                <span>Judul Quiz</span>
                <input type="text" name="title" required value="<?= chemnama_e($selectedQuizSet['title'] ?? ''); ?>" placeholder="Quiz Modul 1 A">
            </label>

            <label class="span-2">
                <span>Deskripsi</span>
                <textarea name="description" rows="4" placeholder="Quiz untuk latihan penamaan senyawa..."><?= chemnama_e($selectedQuizSet['description'] ?? ''); ?></textarea>
            </label>

            <label>
                <span>Urutan</span>
                <input type="number" name="sort_order" value="<?= chemnama_e((string) ($selectedQuizSet['sort_order'] ?? 0)); ?>" min="0">
            </label>

            <label>
                <span>Timer per Soal</span>
                <div class="quiz-timer-input-row">
                    <input type="number" name="question_time_limit_value" value="<?= chemnama_e((string) $selectedQuizTimerValue); ?>" min="0" step="1" placeholder="0 = tanpa timer">
                    <div class="forum-custom-select" data-guru-custom-select="quiz_time_unit">
                        <input type="hidden" name="question_time_limit_unit" value="<?= $selectedQuizTimerUnit; ?>">
                        <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                            <?php
                                $timerUnitLabel = $selectedQuizTimerUnit === 'menit' ? 'Menit' : 'Detik';
                            ?>
                            <span class="forum-custom-select-label"><?= $timerUnitLabel; ?></span>
                            <span class="forum-custom-select-caret" aria-hidden="true"></span>
                        </button>
                        <div class="forum-custom-select-menu" role="listbox" hidden>
                            <button class="forum-custom-select-option<?= $selectedQuizTimerUnit === 'detik' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="detik" data-label="Detik" aria-selected="<?= $selectedQuizTimerUnit === 'detik' ? 'true' : 'false'; ?>">Detik</button>
                            <button class="forum-custom-select-option<?= $selectedQuizTimerUnit === 'menit' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="menit" data-label="Menit" aria-selected="<?= $selectedQuizTimerUnit === 'menit' ? 'true' : 'false'; ?>">Menit</button>
                        </div>
                    </div>
                </div>
                <small>Isi 0 untuk mematikan timer. Batas maksimal 10 menit per soal.</small>
            </label>

            <label>
                <span>Status</span>
                <div class="siswa-profile-action-row">
                    <label style="display:inline-flex;align-items:center;gap:8px;font-weight:700;">
                        <input type="checkbox" name="is_published" value="1" <?= empty($selectedQuizSet) || (int) ($selectedQuizSet['is_published'] ?? 0) === 1 ? 'checked' : ''; ?>>
                        Publish ke siswa
                    </label>
                </div>
            </label>

            <div class="form-action-row span-2">
                <button type="submit" class="btn btn-primary">
                    <?= chemnama_icon('check', '#ffffff'); ?> Simpan Quiz
                </button>
                <a href="guru_quiz_pg.php?tab=quiz_pg" class="btn btn-ghost">
                    <?= chemnama_icon('x', '#6b7280'); ?> Batal
                </a>
            </div>
        </form>
    </div>
</div>

<!-- QUICK QUIZ MODAL -->
<div class="quiz-modal <?= $currentTab === 'quick_quiz' && ($editingQuickQuizId > 0 || count($errors) > 0 || $openQuickForm) ? 'is-open' : ''; ?>" id="quickQuizModal" aria-hidden="<?= $currentTab === 'quick_quiz' && ($editingQuickQuizId > 0 || count($errors) > 0 || $openQuickForm) ? 'false' : 'true'; ?>">
    <div class="quiz-modal-backdrop" data-quiz-modal-close></div>
    <div class="quiz-modal-card guru-panel" style="max-width: 600px;">
        <div class="quiz-modal-head">
            <div>
                <h3><?= $editingQuickQuizId > 0 ? 'Edit Quick Quiz' : 'Buat Quick Quiz Baru'; ?></h3>
                <p>Isi detail quick quiz dengan gambar opsional.</p>
            </div>
            <a class="quiz-modal-close" href="guru_quiz_pg.php?tab=quick_quiz" aria-label="Tutup form">&times;</a>
        </div>
        <form method="post" enctype="multipart/form-data" class="quiz-form-grid">
            <input type="hidden" name="action" value="save_quick_quiz">
            <input type="hidden" name="quick_quiz_id" value="<?= (int)$quickFormData['quick_quiz_id']; ?>">

            <label>
                <span>Modul Pembelajaran *</span>
                <div class="forum-custom-select" data-guru-custom-select="quick_quiz_module_id">
                    <input type="hidden" name="module_id" value="<?= (int)$quickFormData['module_id']; ?>">
                    <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                        <?php
                            $selectedQqModuleLabel = 'Pilih Modul';
                            $currentQqModuleId = (int)$quickFormData['module_id'];
                            foreach ($modules as $m) {
                                if ((int) $m['id'] === $currentQqModuleId) {
                                    $selectedQqModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                    break;
                                }
                            }
                        ?>
                        <span class="forum-custom-select-label"><?= chemnama_e($selectedQqModuleLabel); ?></span>
                        <span class="forum-custom-select-caret" aria-hidden="true"></span>
                    </button>
                    <div class="forum-custom-select-menu" role="listbox" hidden>
                        <?php foreach ($modules as $module): ?>
                            <button class="forum-custom-select-option<?= $currentQqModuleId === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= (int) $module['id']; ?>" data-label="<?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?php echo $currentQqModuleId === (int) $module['id'] ? 'true' : 'false'; ?>">
                                <?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <small>Quick Quiz akan ditampilkan di Step 2 modul ini.</small>
            </label>

            <label class="span-2">
                <span>Pertanyaan *</span>
                <textarea name="question_text" required rows="3" placeholder="Apa nama senyawa ini?"><?= chemnama_e($quickFormData['question_text']); ?></textarea>
            </label>

            <label class="span-2">
                <span>Gambar (Opsional)</span>
                <input type="file" name="image" accept="image/*">
                <small>Format: JPG, PNG, GIF, WEBP (Max 5MB)</small>
                <?php if (!empty($quickFormData['image_url'])): ?>
                    <div class="quick-quiz-image-preview">
                        <img src="<?= chemnama_e($quickFormData['image_url']); ?>" alt="Preview">
                    </div>
                <?php endif; ?>
            </label>

            <label>
                <span>Opsi A *</span>
                <input type="text" name="option_a" required value="<?= chemnama_e($quickFormData['option_a']); ?>" placeholder="Opsi A">
            </label>

            <label>
                <span>Opsi B *</span>
                <input type="text" name="option_b" required value="<?= chemnama_e($quickFormData['option_b']); ?>" placeholder="Opsi B">
            </label>

            <label>
                <span>Opsi C *</span>
                <input type="text" name="option_c" required value="<?= chemnama_e($quickFormData['option_c']); ?>" placeholder="Opsi C">
            </label>

            <label>
                <span>Opsi D *</span>
                <input type="text" name="option_d" required value="<?= chemnama_e($quickFormData['option_d']); ?>" placeholder="Opsi D">
            </label>

            <label>
                <span>Jawaban Benar *</span>
                <div class="forum-custom-select" data-guru-custom-select="quick_quiz_correct_option">
                    <input type="hidden" name="correct_option" value="<?= $quickFormData['correct_option']; ?>">
                    <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                        <?php
                            $qqCorrectOptValue = $quickFormData['correct_option'];
                            $qqCorrectOptLabel = $qqCorrectOptValue ? strtoupper($qqCorrectOptValue) : 'Pilih Jawaban';
                        ?>
                        <span class="forum-custom-select-label"><?= $qqCorrectOptLabel; ?></span>
                        <span class="forum-custom-select-caret" aria-hidden="true"></span>
                    </button>
                    <div class="forum-custom-select-menu" role="listbox" hidden>
                        <button class="forum-custom-select-option<?php echo $qqCorrectOptValue === 'a' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="a" data-label="A" aria-selected="<?php echo $qqCorrectOptValue === 'a' ? 'true' : 'false'; ?>">A</button>
                        <button class="forum-custom-select-option<?php echo $qqCorrectOptValue === 'b' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="b" data-label="B" aria-selected="<?php echo $qqCorrectOptValue === 'b' ? 'true' : 'false'; ?>">B</button>
                        <button class="forum-custom-select-option<?php echo $qqCorrectOptValue === 'c' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="c" data-label="C" aria-selected="<?php echo $qqCorrectOptValue === 'c' ? 'true' : 'false'; ?>">C</button>
                        <button class="forum-custom-select-option<?php echo $qqCorrectOptValue === 'd' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="d" data-label="D" aria-selected="<?php echo $qqCorrectOptValue === 'd' ? 'true' : 'false'; ?>">D</button>
                    </div>
                </div>
            </label>

            <label style="display:flex;align-items:center;gap:8px;font-weight:700;margin-top:12px;">
                <input type="checkbox" name="is_published" value="1" <?= $quickFormData['is_published'] ? 'checked' : ''; ?>>
                Publish ke siswa
            </label>

            <div class="form-action-row span-2">
                <button type="submit" class="btn btn-primary">
                    <?= chemnama_icon('check', '#ffffff'); ?> <?= $editingQuickQuizId > 0 ? 'Update' : 'Buat'; ?>
                </button>
                <a href="guru_quiz_pg.php?tab=quick_quiz" class="btn btn-ghost">
                    <?= chemnama_icon('x', '#6b7280'); ?> Batal
                </a>
            </div>
        </form>
    </div>
</div>

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
                if (!selectRoot.contains(event.target)) {
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
</body>
</html>

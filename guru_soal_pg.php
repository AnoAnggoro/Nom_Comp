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

$quizSetsStmt = $pdo->prepare('SELECT id, module_id, title, is_published FROM quiz_sets WHERE created_by = ? AND class_name = ? ORDER BY sort_order, id');
$quizSetsStmt->execute([$user['id'], $activeClass]);
$quizSets = $quizSetsStmt->fetchAll();
$quizSetMap = [];
foreach ($quizSets as $quizSet) {
    $quizSetMap[(int) $quizSet['id']] = $quizSet;
}
$selectedQuizSetId = (int) ($_GET['quiz_set_id'] ?? 0);
$selectedQuizSet = $selectedQuizSetId > 0 && isset($quizSetMap[$selectedQuizSetId]) ? $quizSetMap[$selectedQuizSetId] : null;
$manageQuizContextRequest = (int) ($_GET['manage_quiz'] ?? $_POST['manage_quiz'] ?? 0) === 1;
$isManagingQuizContext = $manageQuizContextRequest && $selectedQuizSet !== null;

$difficultyLabels = [
    'mudah' => 'Mudah',
    'sedang' => 'Sedang',
    'sulit' => 'Sulit',
];

$errors = [];
$success = false;

// Handle POST requests
$action = $_POST['action'] ?? '';

if ($action === 'delete') {
    $questionId = (int) ($_POST['question_id'] ?? 0);
    if ($questionId > 0) {
        try {
            $stmt = $pdo->prepare(
                'DELETE q FROM questions q
                 JOIN quiz_sets qs ON qs.id = q.quiz_set_id
                 WHERE q.id = ? AND q.created_by = ? AND qs.class_name = ?'
            );
            $stmt->execute([$questionId, $user['id'], $activeClass]);
            $success = true;
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Soal berhasil dihapus.'];
        } catch (Exception $e) {
            $errors[] = 'Gagal menghapus soal: ' . $e->getMessage();
        }
    }
} elseif ($action === 'save') {
    $moduleId = $selectedQuizSetId > 0 && $selectedQuizSet
        ? (int) $selectedQuizSet['module_id']
        : (int) ($_POST['module_id'] ?? 0);
    $quizSetId = $selectedQuizSetId > 0 && $selectedQuizSet
        ? (int) $selectedQuizSet['id']
        : (int) ($_POST['quiz_set_id'] ?? 0);
    $questionText = trim($_POST['question_text'] ?? '');
    $optionA = trim($_POST['option_a'] ?? '');
    $optionB = trim($_POST['option_b'] ?? '');
    $optionC = trim($_POST['option_c'] ?? '');
    $optionD = trim($_POST['option_d'] ?? '');
    $correctOption = strtolower($_POST['correct_option'] ?? '');
    $difficulty = $_POST['difficulty'] ?? 'sedang';
    $points = (int) ($_POST['points'] ?? 10);
    $questionId = (int) ($_POST['question_id'] ?? 0);

    // Validation
    if ($moduleId <= 0 || !isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul tidak valid.';
    }
    if ($quizSetId <= 0 || !isset($quizSetMap[$quizSetId])) {
        $errors[] = 'Quiz harus dipilih.';
    } elseif ((int) $quizSetMap[$quizSetId]['module_id'] !== $moduleId) {
        $errors[] = 'Quiz harus sesuai dengan modul yang dipilih.';
    }
    if (empty($questionText)) {
        $errors[] = 'Pertanyaan tidak boleh kosong.';
    }
    if (empty($optionA) || empty($optionB) || empty($optionC) || empty($optionD)) {
        $errors[] = 'Semua pilihan jawaban harus diisi.';
    }
    if (!in_array($correctOption, ['a', 'b', 'c', 'd'], true)) {
        $errors[] = 'Jawaban yang benar harus dipilih.';
    }
    if (!isset($difficultyLabels[$difficulty])) {
        $errors[] = 'Tingkat kesulitan tidak valid.';
    }
    if ($points < 1 || $points > 100) {
        $errors[] = 'Poin harus antara 1-100.';
    }

    if (count($errors) === 0) {
        try {
            if ($questionId > 0) {
                // Update
                $stmt = $pdo->prepare(
                    'UPDATE questions SET module_id = ?, quiz_set_id = ?, question_text = ?, option_a = ?, option_b = ?, 
                     option_c = ?, option_d = ?, correct_option = ?, difficulty = ?, points = ?, 
                     updated_at = CURRENT_TIMESTAMP WHERE id = ? AND created_by = ? AND quiz_set_id IN (SELECT id FROM quiz_sets WHERE created_by = ? AND class_name = ?)'
                );
                $stmt->execute([
                    $moduleId, $quizSetId, $questionText, $optionA, $optionB, $optionC, $optionD,
                    $correctOption, $difficulty, $points, $questionId, $user['id'], $user['id'], $activeClass
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Soal berhasil diperbarui.'];
            } else {
                // Insert
                $stmt = $pdo->prepare(
                    'INSERT INTO questions (module_id, quiz_set_id, created_by, question_text, option_a, option_b, 
                     option_c, option_d, correct_option, difficulty, points)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $moduleId, $quizSetId, $user['id'], $questionText, $optionA, $optionB, $optionC, $optionD,
                    $correctOption, $difficulty, $points
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Soal berhasil ditambahkan.'];
            }
            $redirect = 'guru_soal_pg.php';
            if ($selectedQuizSet) {
                $redirect .= '?quiz_set_id=' . (int) $selectedQuizSet['id'];
                if ($isManagingQuizContext) {
                    $redirect .= '&manage_quiz=1';
                }
            }
            header('Location: ' . $redirect);
            exit;
        } catch (Exception $e) {
            $errors[] = 'Gagal menyimpan soal: ' . $e->getMessage();
        }
    }
}

$selectedQuizTitle = $selectedQuizSet['title'] ?? '';

// Determine page mode
$mode = $_GET['mode'] ?? 'list';
$editId = (int) ($_GET['edit'] ?? 0);
$editData = null;

if ($mode === 'edit' && $editId > 0) {
    $stmt = $pdo->prepare(
        'SELECT q.*
         FROM questions q
         JOIN quiz_sets qs ON qs.id = q.quiz_set_id
         WHERE q.id = ? AND q.created_by = ? AND qs.class_name = ?'
    );
    $stmt->execute([$editId, $user['id'], $activeClass]);
    $editData = $stmt->fetch();
    if (!$editData) {
        $mode = 'list';
    }
}

// Get filter module and count
$filterModule = (int) ($_GET['module'] ?? 0);
$moduleCounts = [];
$moduleCountStmt = $pdo->prepare(
    'SELECT COALESCE(qs.module_id, q.module_id) AS module_id, COUNT(*) as cnt
     FROM questions q
     LEFT JOIN quiz_sets qs ON qs.id = q.quiz_set_id
     WHERE q.created_by = ?
    AND qs.class_name = ?
     GROUP BY COALESCE(qs.module_id, q.module_id)'
);
$moduleCountStmt->execute([$user['id'], $activeClass]);
$modulesCounts = $moduleCountStmt->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ($modules as $module) {
    $moduleCounts[$module['id']] = $modulesCounts[$module['id']] ?? 0;
}
$totalQuestions = array_sum($moduleCounts);

// Get questions list
$query = 'SELECT q.*, COALESCE(qs.module_id, q.module_id) AS effective_module_id,
                 m.badge, m.title as module_title, u.name as creator_name, qs.title AS quiz_title, qs.is_published AS quiz_is_published
          FROM questions q
          LEFT JOIN quiz_sets qs ON qs.id = q.quiz_set_id
          JOIN modules m ON m.id = COALESCE(qs.module_id, q.module_id)
          JOIN users u ON u.id = q.created_by
          WHERE q.created_by = ? AND qs.class_name = ?';
$params = [$user['id'], $activeClass];
if ($selectedQuizSetId > 0 && $mode !== 'list') {
    $query .= ' AND q.quiz_set_id = ?';
    $params[] = $selectedQuizSetId;
} elseif ($isManagingQuizContext) {
    $query .= ' AND q.quiz_set_id = ?';
    $params[] = $selectedQuizSetId;
}
$query .= ' ORDER BY m.sort_order ASC, COALESCE(qs.sort_order, 0) ASC, q.id ASC';
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$questions = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
if ($flash) {
    unset($_SESSION['flash']);
}

?><!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bank Soal PG - ChemNama</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
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
                <span>ChemNama</span>
            </a>
            <p>Panel Guru</p>
            <div class="guru-class-chip"><?= chemnama_e($activeClass); ?></div>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">MENU</span>
            <nav class="guru-menu-list">
                <?php 
                    $guruMenus = [
                    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
                    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
                    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => true],
                    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
                    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
                    ['label' => 'Quick / Quiz', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => false],
                    ['label' => 'Pengaturan Games', 'icon' => 'beaker', 'href' => 'guru_games.php', 'active' => false],
                    ['label' => 'Edit Intro Siswa', 'icon' => 'edit', 'href' => 'guru_intro_siswa.php', 'active' => false],
                    ['label' => 'Forum Diskusi', 'icon' => 'chat', 'href' => 'guru_forum.php', 'active' => false],
                    ['label' => 'Data Siswa', 'icon' => 'users', 'href' => 'guru_data_siswa.php', 'active' => false],
                    ['label' => 'Profil', 'icon' => 'user', 'href' => 'guru_profil.php', 'active' => false],
                    ];
                    foreach ($guruMenus as $menu):
                ?>
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
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_soal_pg.php">
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

    <section class="guru-content soal-content">
        <div class="guru-headline-row">
            <div>
                <h1>Bank Soal PG</h1>
                <p>Kelola soal pilihan ganda.</p>
            </div>
            <div class="guru-head-actions">
                <a class="btn btn-primary" href="guru_soal_pg.php?mode=add<?= $selectedQuizSet ? '&quiz_set_id=' . (int) $selectedQuizSet['id'] : ''; ?><?= $isManagingQuizContext ? '&manage_quiz=1' : ''; ?>"><?= chemnama_icon('plus', '#ffffff'); ?> Tambah Soal</a>
            </div>
        </div>
        <a class="guru-back-link guru-back-link-inline" href="javascript:history.back()"><?= chemnama_icon('switch', '#64748b'); ?> Kembali</a>

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

        <?php if ($selectedQuizSet && ($mode !== 'list' || $isManagingQuizContext)): ?>
            <div class="quiz-context-banner">
                <div>
                    <strong><?= chemnama_e($moduleMap[$selectedQuizSet['module_id']]['badge'] . ' - ' . $selectedQuizSet['title']); ?></strong>
                    <p><?= $mode === 'list' ? 'Menampilkan soal untuk quiz yang sedang dikelola.' : 'Form tambah/edit soal otomatis memakai quiz yang sama.'; ?></p>
                </div>
                <a class="btn btn-ghost btn-sm" href="guru_soal_pg.php">Lihat semua quiz</a>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'list'): ?>
            <div class="soal-tabs" data-filter-tabs="soal">
                <a href="guru_soal_pg.php" class="soal-tab js-module-tab <?php echo $filterModule === 0 ? 'is-active' : ''; ?>" data-module="0">
                    Semua (<?php echo $totalQuestions; ?>)
                </a>
                <?php foreach ($modules as $module): ?>
                    <a href="guru_soal_pg.php?module=<?php echo $module['id']; ?>"
                       class="soal-tab js-module-tab <?php echo $filterModule === (int) $module['id'] ? 'is-active' : ''; ?>"
                       data-module="<?php echo $module['id']; ?>">
                        <?php echo chemnama_e($module['badge']); ?> (<?php echo $moduleCounts[$module['id']]; ?>)
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="module-filter-mobile" data-filter-mobile="soal">
                <?php
                    $selectedModuleLabel = 'Semua (' . $totalQuestions . ')';
                    if ($filterModule > 0) {
                        foreach ($modules as $module) {
                            if ((int) $module['id'] === $filterModule) {
                                $selectedModuleLabel = (string) $module['badge'] . ' (' . ((int) ($moduleCounts[$module['id']] ?? 0)) . ')';
                                break;
                            }
                        }
                    }
                ?>
                <div class="module-filter-dropdown" data-filter-dropdown="soal">
                    <button class="module-filter-trigger" type="button" data-filter-trigger="soal" aria-expanded="false" aria-controls="soalFilterMenu">
                        <span data-filter-current-label="soal"><?php echo chemnama_e($selectedModuleLabel); ?></span>
                        <span class="module-filter-caret" aria-hidden="true"></span>
                    </button>
                    <div class="module-filter-menu" id="soalFilterMenu" data-filter-menu="soal" hidden>
                        <button class="module-filter-option <?php echo $filterModule === 0 ? 'is-active' : ''; ?>" type="button" data-filter-option="soal" data-module="0">Semua (<?php echo $totalQuestions; ?>)</button>
                        <?php foreach ($modules as $module): ?>
                            <button class="module-filter-option <?php echo $filterModule === (int) $module['id'] ? 'is-active' : ''; ?>" type="button" data-filter-option="soal" data-module="<?php echo (int) $module['id']; ?>"><?php echo chemnama_e($module['badge']); ?> (<?php echo $moduleCounts[$module['id']]; ?>)</button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            

            <article class="guru-panel empty-state" data-filter-empty="soal" style="<?= count($questions) === 0 ? '' : 'display: none;'; ?>">
                <h3>Belum ada soal</h3>
                <p>Silakan tambahkan soal baru untuk modul ini.</p>
            </article>

            <?php if (count($questions) > 0): ?>
                <div class="soal-list" data-filter-list="soal">
                    <?php foreach ($questions as $q): ?>
                        <article class="guru-panel soal-item" data-module-id="<?php echo (int) ($q['effective_module_id'] ?? $q['module_id']); ?>">
                            <div class="soal-item-header">
                                <div>
                                    <div class="soal-module"><?php echo chemnama_e($moduleMap[(int) ($q['effective_module_id'] ?? $q['module_id'])]['badge'] ?? $q['badge']); ?></div>
                                    <?php if (!empty($q['quiz_title'])): ?>
                                      
                                    <?php endif; ?>
                                    <h3><?php echo chemnama_e(substr($q['question_text'], 0, 100)); ?></h3>
                                </div>
                                <div class="soal-actions">
                                    <a href="guru_soal_pg.php?mode=edit&edit=<?php echo $q['id']; ?><?= $selectedQuizSet ? '&quiz_set_id=' . (int) $selectedQuizSet['id'] : ''; ?><?= $isManagingQuizContext ? '&manage_quiz=1' : ''; ?>" 
                                       class="icon-btn" title="Edit">
                                        <?= chemnama_icon('edit', '#6b7280'); ?>
                                    </a>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Yakin ingin menghapus soal ini?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                        <?php if ($isManagingQuizContext): ?>
                                            <input type="hidden" name="quiz_set_id" value="<?php echo (int) $selectedQuizSetId; ?>">
                                            <input type="hidden" name="manage_quiz" value="1">
                                        <?php endif; ?>
                                        <button type="submit" class="icon-btn delete-btn" title="Hapus">
                                            <?= chemnama_icon('trash', '#ef4444'); ?>
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <div class="soal-item-options">
                                <div class="option-row">
                                    <div class="option-col">
                                        <label>A.</label>
                                        <span><?php echo chemnama_e($q['option_a']); ?></span>
                                    </div>
                                    <div class="option-col">
                                        <label>B.</label>
                                        <span><?php echo chemnama_e($q['option_b']); ?></span>
                                    </div>
                                </div>
                                <div class="option-row">
                                    <div class="option-col">
                                        <label>C.</label>
                                        <span><?php echo chemnama_e($q['option_c']); ?></span>
                                    </div>
                                    <div class="option-col">
                                        <label>D.</label>
                                        <span><?php echo chemnama_e($q['option_d']); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="soal-item-footer">
                                <div class="soal-meta">
                                    <span class="badge" style="background-color: <?php echo $q['correct_option'] === 'a' ? '#10b981' : '#e5e7eb'; ?>; color: <?php echo $q['correct_option'] === 'a' ? '#ffffff' : '#6b7280'; ?>">A</span>
                                    <span class="badge" style="background-color: <?php echo $q['correct_option'] === 'b' ? '#10b981' : '#e5e7eb'; ?>; color: <?php echo $q['correct_option'] === 'b' ? '#ffffff' : '#6b7280'; ?>">B</span>
                                    <span class="badge" style="background-color: <?php echo $q['correct_option'] === 'c' ? '#10b981' : '#e5e7eb'; ?>; color: <?php echo $q['correct_option'] === 'c' ? '#ffffff' : '#6b7280'; ?>">C</span>
                                    <span class="badge" style="background-color: <?php echo $q['correct_option'] === 'd' ? '#10b981' : '#e5e7eb'; ?>; color: <?php echo $q['correct_option'] === 'd' ? '#ffffff' : '#6b7280'; ?>">D</span>
                                </div>
                                <small class="text-muted">
                                    <?php echo chemnama_e($difficultyLabels[$q['difficulty']] ?? $q['difficulty']); ?> • 
                                    <?php echo $q['points']; ?> poin
                                </small>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php else: // Add/Edit mode ?>
            <article class="guru-panel soal-form-container">
                <h3><?php echo $editData ? 'Edit Soal' : 'Tambah Soal Baru'; ?></h3>
                
                <form method="POST" class="soal-form-grid">
                    <input type="hidden" name="action" value="save">
                    <?php if ($isManagingQuizContext): ?>
                        <input type="hidden" name="manage_quiz" value="1">
                    <?php endif; ?>
                    <?php if ($editData): ?>
                        <input type="hidden" name="question_id" value="<?php echo $editData['id']; ?>">
                    <?php endif; ?>

                    <?php if ($selectedQuizSet): ?>
                        <div class="quiz-select-fixed span-2">
                            <span>Modul & Quiz</span>
                            <input type="hidden" name="module_id" value="<?php echo (int) $selectedQuizSet['module_id']; ?>">
                            <input type="hidden" name="quiz_set_id" value="<?php echo (int) $selectedQuizSet['id']; ?>">
                            <input type="text" value="<?php echo chemnama_e($moduleMap[$selectedQuizSet['module_id']]['badge'] . ' - ' . $selectedQuizSet['title']); ?>" readonly>
                        </div>
                    <?php else: ?>
                        <label>
                            <span>Modul<span class="required">*</span></span>
                            <div class="forum-custom-select" data-guru-custom-select="soal_module_id">
                                <input type="hidden" name="module_id" value="<?php echo (int) ($editData['module_id'] ?? $_POST['module_id'] ?? 0); ?>">
                                <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                    <?php
                                        $selectedSoalModuleLabel = 'Pilih Modul';
                                        $currentModuleId = (int) ($editData['module_id'] ?? $_POST['module_id'] ?? 0);
                                        foreach ($modules as $m) {
                                            if ((int) $m['id'] === $currentModuleId) {
                                                $selectedSoalModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                                break;
                                            }
                                        }
                                    ?>
                                    <span class="forum-custom-select-label"><?php echo chemnama_e($selectedSoalModuleLabel); ?></span>
                                    <span class="forum-custom-select-caret" aria-hidden="true"></span>
                                </button>
                                <div class="forum-custom-select-menu" role="listbox" hidden>
                                    <?php foreach ($modules as $module): ?>
                                        <button class="forum-custom-select-option<?= $currentModuleId === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?php echo $module['id']; ?>" data-label="<?php echo chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?php echo $currentModuleId === (int) $module['id'] ? 'true' : 'false'; ?>">
                                            <?php echo chemnama_e($module['badge'] . ' - ' . $module['title']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </label>

                      
                    <?php endif; ?>

                    <label class="span-2">
                        <span>Pertanyaan<span class="required">*</span></span>
                        <textarea name="question_text" required placeholder="Tuliskan pertanyaan di sini..."><?php 
                            echo chemnama_e($editData['question_text'] ?? $_POST['question_text'] ?? ''); 
                        ?></textarea>
                    </label>

                    <label>
                        <span>Pilihan A<span class="required">*</span></span>
                        <input type="text" name="option_a" required placeholder="Jawaban A..."
                               value="<?php echo chemnama_e($editData['option_a'] ?? $_POST['option_a'] ?? ''); ?>">
                    </label>

                    <label>
                        <span>Pilihan B<span class="required">*</span></span>
                        <input type="text" name="option_b" required placeholder="Jawaban B..."
                               value="<?php echo chemnama_e($editData['option_b'] ?? $_POST['option_b'] ?? ''); ?>">
                    </label>

                    <label>
                        <span>Pilihan C<span class="required">*</span></span>
                        <input type="text" name="option_c" required placeholder="Jawaban C..."
                               value="<?php echo chemnama_e($editData['option_c'] ?? $_POST['option_c'] ?? ''); ?>">
                    </label>

                    <label>
                        <span>Pilihan D<span class="required">*</span></span>
                        <input type="text" name="option_d" required placeholder="Jawaban D..."
                               value="<?php echo chemnama_e($editData['option_d'] ?? $_POST['option_d'] ?? ''); ?>">
                    </label>

                    <label>
                        <span>Jawaban yang Benar<span class="required">*</span></span>
                        <div class="forum-custom-select" data-guru-custom-select="soal_correct_option">
                            <input type="hidden" name="correct_option" value="<?php echo ($editData['correct_option'] ?? $_POST['correct_option'] ?? ''); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <?php
                                    $correctOptValue = $editData['correct_option'] ?? $_POST['correct_option'] ?? '';
                                    $correctOptLabel = $correctOptValue ? strtoupper($correctOptValue) : 'Pilih Jawaban';
                                ?>
                                <span class="forum-custom-select-label"><?php echo $correctOptLabel; ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <button class="forum-custom-select-option<?php echo $correctOptValue === 'a' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="a" data-label="A" aria-selected="<?php echo $correctOptValue === 'a' ? 'true' : 'false'; ?>">A</button>
                                <button class="forum-custom-select-option<?php echo $correctOptValue === 'b' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="b" data-label="B" aria-selected="<?php echo $correctOptValue === 'b' ? 'true' : 'false'; ?>">B</button>
                                <button class="forum-custom-select-option<?php echo $correctOptValue === 'c' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="c" data-label="C" aria-selected="<?php echo $correctOptValue === 'c' ? 'true' : 'false'; ?>">C</button>
                                <button class="forum-custom-select-option<?php echo $correctOptValue === 'd' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="d" data-label="D" aria-selected="<?php echo $correctOptValue === 'd' ? 'true' : 'false'; ?>">D</button>
                            </div>
                        </div>
                    </label>

                    <label>
                        <span>Tingkat Kesulitan</span>
                        <div class="forum-custom-select" data-guru-custom-select="soal_difficulty">
                            <input type="hidden" name="difficulty" value="<?php echo $editData['difficulty'] ?? $_POST['difficulty'] ?? 'sedang'; ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <?php
                                    $diffValue = $editData['difficulty'] ?? $_POST['difficulty'] ?? 'sedang';
                                    $diffLabel = $difficultyLabels[$diffValue] ?? 'Sedang';
                                ?>
                                <span class="forum-custom-select-label"><?php echo $diffLabel; ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <?php foreach ($difficultyLabels as $key => $label): ?>
                                    <button class="forum-custom-select-option<?php echo $diffValue === $key ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?php echo $key; ?>" data-label="<?php echo $label; ?>" aria-selected="<?php echo $diffValue === $key ? 'true' : 'false'; ?>"><?php echo $label; ?></button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </label>

                    <label>
                        <span>Poin</span>
                        <input type="number" name="points" min="1" max="100" value="<?php 
                            echo $editData['points'] ?? $_POST['points'] ?? 10; 
                        ?>">
                    </label>

                    <div class="form-action-row span-2">
                        <button type="submit" class="btn btn-primary">
                            <?= chemnama_icon('check', '#ffffff'); ?> 
                            <?php echo $editData ? 'Simpan Perubahan' : 'Simpan Soal'; ?>
                        </button>
                        <a href="guru_soal_pg.php<?= $selectedQuizSet ? '?quiz_set_id=' . (int) $selectedQuizSet['id'] : ''; ?><?= $isManagingQuizContext && $selectedQuizSet ? '&manage_quiz=1' : ''; ?>" class="btn btn-ghost">
                            <?= chemnama_icon('x', '#6b7280'); ?> Batal
                        </a>
                    </div>
                </form>
            </article>
        <?php endif; ?>
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
<script>
    (function () {
        const tabs = document.querySelectorAll('[data-filter-tabs="soal"] .js-module-tab');
        const mobileDropdown = document.querySelector('[data-filter-dropdown="soal"]');
        const mobileTrigger = document.querySelector('[data-filter-trigger="soal"]');
        const mobileMenu = document.querySelector('[data-filter-menu="soal"]');
        const mobileOptions = document.querySelectorAll('[data-filter-option="soal"]');
        const mobileCurrentLabel = document.querySelector('[data-filter-current-label="soal"]');
        const items = document.querySelectorAll('[data-filter-list="soal"] .soal-item');
        const list = document.querySelector('[data-filter-list="soal"]');
        const emptyState = document.querySelector('[data-filter-empty="soal"]');

        function readQueryParams() {
            const params = {};
            const query = window.location.search.replace(/^\?/, '');
            if (!query) {
                return params;
            }

            query.split('&').forEach((pair) => {
                if (!pair) {
                    return;
                }

                const parts = pair.split('=');
                const key = decodeURIComponent(parts[0] || '');
                if (!key) {
                    return;
                }

                const value = decodeURIComponent(parts.slice(1).join('=') || '');
                params[key] = value;
            });

            return params;
        }

        function writeQueryParams(params) {
            const pairs = [];
            Object.keys(params).forEach((key) => {
                if (params[key] === '' || params[key] === null || typeof params[key] === 'undefined') {
                    return;
                }

                pairs.push(`${encodeURIComponent(key)}=${encodeURIComponent(params[key])}`);
            });

            const query = pairs.length > 0 ? `?${pairs.join('&')}` : '';
            return window.location.pathname + query + window.location.hash;
        }

        if (!tabs.length || !emptyState) {
            return;
        }

        const initialParams = readQueryParams();
        const initialModule = initialParams.module || '0';

        function applyFilter(moduleId, updateUrl) {
            let visibleCount = 0;

            items.forEach((item) => {
                const matches = moduleId === '0' || item.dataset.moduleId === moduleId;
                item.style.display = matches ? '' : 'none';
                if (matches) {
                    visibleCount += 1;
                }
            });

            tabs.forEach((tab) => {
                tab.classList.toggle('is-active', tab.dataset.module === moduleId);
            });

            if (mobileOptions.length) {
                mobileOptions.forEach((option) => {
                    const isActive = option.dataset.module === moduleId;
                    option.classList.toggle('is-active', isActive);
                    if (isActive && mobileCurrentLabel) {
                        mobileCurrentLabel.textContent = option.textContent || '';
                    }
                });
            }

            if (visibleCount === 0) {
                emptyState.style.display = '';
                if (list) {
                    list.style.display = 'none';
                }
            } else {
                emptyState.style.display = 'none';
                if (list) {
                    list.style.display = '';
                }
            }

            if (updateUrl) {
                const nextParams = readQueryParams();
                if (moduleId === '0') {
                    delete nextParams.module;
                } else {
                    nextParams.module = moduleId;
                }
                const nextUrl = writeQueryParams(nextParams);
                window.history.replaceState({}, '', nextUrl);
            }
        }

        tabs.forEach((tab) => {
            tab.addEventListener('click', (event) => {
                event.preventDefault();
                applyFilter(tab.dataset.module || '0', true);
            });
        });

        const closeMobileDropdown = () => {
            if (!mobileDropdown || !mobileTrigger || !mobileMenu) {
                return;
            }
            mobileDropdown.classList.remove('is-open');
            mobileTrigger.setAttribute('aria-expanded', 'false');
            mobileMenu.hidden = true;
        };

        const openMobileDropdown = () => {
            if (!mobileDropdown || !mobileTrigger || !mobileMenu) {
                return;
            }
            mobileDropdown.classList.add('is-open');
            mobileTrigger.setAttribute('aria-expanded', 'true');
            mobileMenu.hidden = false;
        };

        if (mobileTrigger && mobileMenu && mobileDropdown) {
            mobileTrigger.addEventListener('click', () => {
                if (mobileDropdown.classList.contains('is-open')) {
                    closeMobileDropdown();
                } else {
                    openMobileDropdown();
                }
            });

            document.addEventListener('click', (event) => {
                if (!mobileDropdown.contains(event.target)) {
                    closeMobileDropdown();
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeMobileDropdown();
                }
            });
        }

        if (mobileOptions.length) {
            mobileOptions.forEach((option) => {
                option.addEventListener('click', () => {
                    applyFilter(option.dataset.module || '0', true);
                    closeMobileDropdown();
                });
            });
        }

        applyFilter(initialModule, false);
    })();
</script>
</body>
</html>

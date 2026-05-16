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
$action = (string) ($_POST['action'] ?? '');

if ($action === 'save_task') {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $promptText = trim((string) ($_POST['prompt_text'] ?? ''));
    $dueAtRaw = trim((string) ($_POST['due_at'] ?? ''));
    $dueAt = null;
    if ($dueAtRaw !== '') {
        $normalizedDueAt = str_replace('T', ' ', $dueAtRaw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalizedDueAt)) {
            $normalizedDueAt .= ' 23:59:00';
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalizedDueAt)) {
            $normalizedDueAt .= ':00';
        }
        $dueAt = $normalizedDueAt;
    }

    if (!isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul wajib dipilih.';
    }

    if ($promptText === '') {
        $errors[] = 'Pertanyaan essay wajib diisi.';
    }

    if ($dueAt !== null && strtotime($dueAt) === false) {
        $errors[] = 'Format deadline tidak valid.';
    }

    if (count($errors) === 0) {
        if ($taskId > 0) {
            $update = $pdo->prepare(
                'UPDATE essay_tasks
                 SET module_id = :module_id, class_name = :class_name, prompt_text = :prompt_text, due_at = :due_at
                 WHERE id = :id AND created_by = :created_by'
            );
            $update->execute([
                'module_id' => $moduleId,
                'class_name' => $activeClass,
                'prompt_text' => $promptText,
                'due_at' => $dueAt,
                'id' => $taskId,
                'created_by' => $user['id'],
            ]);

            chemnama_flash('Tugas essay berhasil diperbarui.', 'success');
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO essay_tasks (module_id, created_by, class_name, prompt_text, due_at)
                 VALUES (:module_id, :created_by, :class_name, :prompt_text, :due_at)'
            );
            $insert->execute([
                'module_id' => $moduleId,
                'created_by' => $user['id'],
                'class_name' => $activeClass,
                'prompt_text' => $promptText,
                'due_at' => $dueAt,
            ]);

            chemnama_flash('Tugas essay berhasil dibuat.', 'success');
        }

        header('Location: guru_essay.php');
        exit;
    }
}

if ($action === 'delete_task') {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    if ($taskId > 0) {
        $delete = $pdo->prepare('DELETE FROM essay_tasks WHERE id = :id AND created_by = :created_by AND class_name = :class_name');
        $delete->execute(['id' => $taskId, 'created_by' => $user['id'], 'class_name' => $activeClass]);
        chemnama_flash('Tugas essay berhasil dihapus.', 'success');
    }

    header('Location: guru_essay.php');
    exit;
}

if ($action === 'grade_answer') {
    $answerId = (int) ($_POST['answer_id'] ?? 0);
    $scoreRaw = trim((string) ($_POST['score'] ?? ''));
    $feedback = trim((string) ($_POST['feedback'] ?? ''));

    if ($scoreRaw === '' || !is_numeric($scoreRaw)) {
        $errors[] = 'Nilai wajib berupa angka.';
    }

    $score = (int) $scoreRaw;
    if ($score < 0 || $score > 100) {
        $errors[] = 'Nilai harus di antara 0 sampai 100.';
    }

    if ($answerId <= 0) {
        $errors[] = 'Jawaban tidak valid.';
    }

    if (count($errors) === 0) {
        $check = $pdo->prepare(
            'SELECT a.id
             FROM essay_answers a
             JOIN essay_tasks t ON t.id = a.task_id
             JOIN user_profiles up_student ON up_student.user_id = a.student_id
             WHERE a.id = :answer_id AND t.created_by = :created_by
                            AND t.class_name = :task_class
                             AND up_student.kelas = :student_class
             LIMIT 1'
        );
        $check->execute([
            'answer_id' => $answerId,
            'created_by' => $user['id'],
            'task_class' => $activeClass,
            'student_class' => $activeClass,
        ]);

        if ($check->fetch()) {
            $update = $pdo->prepare(
                'UPDATE essay_answers
                 SET score = :score, feedback = :feedback, graded_at = NOW(), graded_by = :graded_by
                 WHERE id = :id'
            );
            $update->execute([
                'score' => $score,
                'feedback' => $feedback !== '' ? $feedback : null,
                'graded_by' => $user['id'],
                'id' => $answerId,
            ]);

            chemnama_flash('Nilai essay berhasil disimpan.', 'success');
            header('Location: guru_essay.php');
            exit;
        }

        $errors[] = 'Jawaban tidak ditemukan.';
    }
}

$mode = (string) ($_GET['mode'] ?? 'list');
$editId = (int) ($_GET['edit'] ?? 0);
$selectedModule = (int) ($_GET['module'] ?? 0);

$taskEditing = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM essay_tasks WHERE id = :id AND created_by = :created_by AND class_name = :class_name LIMIT 1');
        $editStmt->execute(['id' => $editId, 'created_by' => $user['id'], 'class_name' => $activeClass]);
    $taskEditing = $editStmt->fetch();

    if (!$taskEditing) {
        $editId = 0;
    }
}

$taskStmt = $pdo->prepare(
    'SELECT t.*, m.badge AS module_badge, m.title AS module_title
     FROM essay_tasks t
     JOIN modules m ON m.id = t.module_id
     WHERE t.created_by = :created_by
             AND t.class_name = :class_name
     ORDER BY t.created_at DESC'
);
$taskStmt->execute(['created_by' => $user['id'], 'class_name' => $activeClass]);
$tasks = $taskStmt->fetchAll();

$countStmt = $pdo->prepare(
        'SELECT module_id, COUNT(*) AS total
         FROM essay_tasks
         WHERE created_by = :created_by
                AND class_name = :class_name
         GROUP BY module_id'
);
$countStmt->execute(['created_by' => $user['id'], 'class_name' => $activeClass]);
$countRows = $countStmt->fetchAll();
$moduleCounts = [];
$totalCount = 0;
foreach ($countRows as $row) {
    $mid = (int) $row['module_id'];
    $cnt = (int) $row['total'];
    $moduleCounts[$mid] = $cnt;
    $totalCount += $cnt;
}

$answerStmt = $pdo->prepare(
    'SELECT a.*, u.name AS student_name
     FROM essay_answers a
     JOIN essay_tasks t ON t.id = a.task_id
     JOIN users u ON u.id = a.student_id
     JOIN user_profiles up_student ON up_student.user_id = u.id
     WHERE t.created_by = :created_by
       AND up_student.kelas = :student_class
             AND t.class_name = :task_class
     ORDER BY a.updated_at DESC'
);
$answerStmt->execute([
    'created_by' => $user['id'],
    'student_class' => $activeClass,
    'task_class' => $activeClass,
]);
$answers = $answerStmt->fetchAll();

$answersByTask = [];
foreach ($answers as $answer) {
    $tid = (int) $answer['task_id'];
    if (!isset($answersByTask[$tid])) {
        $answersByTask[$tid] = [];
    }
    $answersByTask[$tid][] = $answer;
}

$formData = [
    'task_id' => (int) ($taskEditing['id'] ?? 0),
    'module_id' => (int) ($taskEditing['module_id'] ?? ($modules[0]['id'] ?? 1)),
    'prompt_text' => (string) ($taskEditing['prompt_text'] ?? ''),
    'due_at' => (string) ($taskEditing['due_at'] ?? ''),
];

if ($action === 'save_task' && count($errors) > 0) {
    $formData = [
        'task_id' => (int) ($_POST['task_id'] ?? 0),
        'module_id' => (int) ($_POST['module_id'] ?? ($modules[0]['id'] ?? 1)),
        'prompt_text' => trim((string) ($_POST['prompt_text'] ?? '')),
        'due_at' => trim((string) ($_POST['due_at'] ?? '')),
    ];
}

$flash = chemnama_flash();

$dueDateValue = '';
if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($formData['due_at'] ?? ''), $dueDateMatch)) {
    $dueDateValue = (string) $dueDateMatch[0];
}
$dueDateLabel = $dueDateValue !== '' ? $dueDateValue : 'Pilih tanggal deadline';

$guruMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => true],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
    ['label' => 'Quick / Quiz', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => false],
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
    <title>Tugas Essay - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .forum-calendar-menu {
            padding: 10px;
        }

        .forum-calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            gap: 8px;
        }

        .forum-calendar-month {
            font-weight: 800;
            color: #f8fafc;
            font-size: 0.92rem;
        }

        .forum-calendar-nav {
            width: 30px;
            height: 30px;
            border-radius: 8px;
            border: 1px solid rgba(148, 163, 184, 0.26);
            background: rgba(51, 65, 85, 0.45);
            color: #e2e8f0;
            font-weight: 700;
            cursor: pointer;
        }

        .forum-calendar-weekdays,
        .forum-calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 4px;
        }

        .forum-calendar-weekdays {
            margin-bottom: 4px;
        }

        .forum-calendar-weekday {
            text-align: center;
            font-size: 0.72rem;
            font-weight: 700;
            color: rgba(226, 232, 240, 0.72);
            padding: 2px 0;
        }

        .forum-calendar-day {
            min-height: 32px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: #e2e8f0;
            font: inherit;
            font-size: 0.82rem;
            cursor: pointer;
        }

        .forum-calendar-day:hover {
            background: rgba(217, 119, 6, 0.14);
            color: #fff;
        }

        .forum-calendar-day.is-muted {
            color: rgba(148, 163, 184, 0.55);
            cursor: default;
        }

        .forum-calendar-day.is-selected {
            background: rgba(217, 119, 6, 0.22);
            color: #fff;
            font-weight: 800;
        }

        .forum-calendar-actions {
            margin-top: 8px;
            display: flex;
            gap: 6px;
        }

        .forum-calendar-action {
            flex: 1 1 0;
            min-height: 30px;
            border-radius: 8px;
            border: 1px solid rgba(148, 163, 184, 0.24);
            background: rgba(51, 65, 85, 0.4);
            color: #e2e8f0;
            font: inherit;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
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
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_essay.php">
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

    <section class="guru-content essay-content">
        <div class="guru-headline-row">
            <div>
                <h1>Tugas Essay</h1>
                <p>Buat, cek, dan nilai jawaban essay siswa kelas <?= chemnama_e($activeClass); ?>.</p>
            </div>
            <div class="guru-head-actions">
                <a class="btn btn-primary" href="guru_essay.php?mode=add"><?= chemnama_icon('plus', '#ffffff'); ?> Buat Essay</a>
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

        <?php if ($mode === 'add' || $editId > 0): ?>
            <article class="guru-panel essay-form-panel">
                <h3><?= $editId > 0 ? 'Edit Tugas Essay' : 'Buat Tugas Essay Baru'; ?></h3>
                <form method="post" class="essay-form-grid">
                    <input type="hidden" name="action" value="save_task">
                    <input type="hidden" name="task_id" value="<?= chemnama_e((string) $formData['task_id']); ?>">
                    

                    <label>
                        <span>Modul <span class="required">*</span></span>
                        <div class="forum-custom-select" data-guru-custom-select="essay_module_id">
                            <input type="hidden" name="module_id" value="<?= chemnama_e((string) $formData['module_id']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <?php
                                    $selectedEssayModuleLabel = isset($modules[0]) ? $modules[0]['badge'] . ' - ' . $modules[0]['title'] : 'Pilih Modul';
                                    foreach ($modules as $m) {
                                        if ((int) $m['id'] === (int) $formData['module_id']) {
                                            $selectedEssayModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                            break;
                                        }
                                    }
                                ?>
                                <span class="forum-custom-select-label"><?= chemnama_e($selectedEssayModuleLabel); ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <?php foreach ($modules as $module): ?>
                                    <button class="forum-custom-select-option<?= (int) $formData['module_id'] === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= chemnama_e((string) $module['id']); ?>" data-label="<?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?= (int) $formData['module_id'] === (int) $module['id'] ? 'true' : 'false'; ?>">
                                        <?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </label>
                       
                        <input type="datetime-local" name="due_at" value="<?= chemnama_e($formData['due_at'] !== '' ? str_replace(' ', 'T', substr((string) $formData['due_at'], 0, 16)) : ''); ?>">
                    </label>

                    <label class="span-2">
                        <span>Pertanyaan Essay <span class="required">*</span></span>
                        <textarea name="prompt_text" required placeholder="Tulis pertanyaan essay untuk siswa..."><?= chemnama_e($formData['prompt_text']); ?></textarea>
                    </label>

                    <div class="form-action-row span-2">
                        <button type="submit" class="btn btn-primary"><?= chemnama_icon('check', '#ffffff'); ?> <?= $editId > 0 ? 'Simpan Perubahan' : 'Buat Essay'; ?></button>
                        <a href="guru_essay.php" class="btn btn-ghost">Batal</a>
                    </div>
                    
                </form>
            </article>
        <?php endif; ?>

        <div class="essay-tabs" data-filter-tabs="essay">
            <a class="essay-tab js-module-tab <?= $selectedModule === 0 ? 'is-active' : ''; ?>" href="guru_essay.php" data-module="0">Semua (<?= $totalCount; ?>)</a>
            <?php foreach ($modules as $module): ?>
                <?php $count = $moduleCounts[(int) $module['id']] ?? 0; ?>
                <a class="essay-tab js-module-tab <?= $selectedModule === (int) $module['id'] ? 'is-active' : ''; ?>" href="guru_essay.php?module=<?= chemnama_e((string) $module['id']); ?>" data-module="<?= chemnama_e((string) $module['id']); ?>">
                    <?= chemnama_e($module['badge']); ?> (<?= $count; ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <div class="module-filter-mobile" data-filter-mobile="essay">
            <?php
                $selectedModuleLabel = 'Semua (' . $totalCount . ')';
                if ($selectedModule > 0) {
                    foreach ($modules as $module) {
                        if ((int) $module['id'] === $selectedModule) {
                            $selectedModuleLabel = (string) $module['badge'] . ' (' . ((int) ($moduleCounts[(int) $module['id']] ?? 0)) . ')';
                            break;
                        }
                    }
                }
            ?>
            <div class="module-filter-dropdown" data-filter-dropdown="essay">
                <button class="module-filter-trigger" type="button" data-filter-trigger="essay" aria-expanded="false" aria-controls="essayFilterMenu">
                    <span data-filter-current-label="essay"><?= chemnama_e($selectedModuleLabel); ?></span>
                    <span class="module-filter-caret" aria-hidden="true"></span>
                </button>
                <div class="module-filter-menu" id="essayFilterMenu" data-filter-menu="essay" hidden>
                    <button class="module-filter-option <?= $selectedModule === 0 ? 'is-active' : ''; ?>" type="button" data-filter-option="essay" data-module="0">Semua (<?= $totalCount; ?>)</button>
                    <?php foreach ($modules as $module): ?>
                        <?php $count = $moduleCounts[(int) $module['id']] ?? 0; ?>
                        <button class="module-filter-option <?= $selectedModule === (int) $module['id'] ? 'is-active' : ''; ?>" type="button" data-filter-option="essay" data-module="<?= chemnama_e((string) $module['id']); ?>"><?= chemnama_e($module['badge']); ?> (<?= $count; ?>)</button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        

        <div class="essay-list" data-filter-list="essay">
            <article class="guru-panel empty-state" data-filter-empty="essay" style="<?= count($tasks) === 0 ? '' : 'display:none;'; ?>">
                <h3>Belum ada tugas essay</h3>
                <p>Buat tugas essay pertama untuk mulai penilaian manual.</p>
            </article>

            <?php foreach ($tasks as $task): ?>
                <?php $taskAnswers = $answersByTask[(int) $task['id']] ?? []; ?>
                <article class="guru-panel essay-item" data-module-id="<?= chemnama_e((string) $task['module_id']); ?>">
                    <div class="essay-item-head">
                        <div>
                            <span class="essay-module-chip"><?= chemnama_e((string) $task['module_badge']); ?></span>
                            <h3><?= chemnama_e((string) $task['module_title']); ?></h3>
                        </div>
                        <div class="essay-actions">
                            <a class="icon-btn" href="guru_essay.php?mode=add&edit=<?= chemnama_e((string) $task['id']); ?>" title="Edit"><?= chemnama_icon('edit', '#2563eb'); ?></a>
                            <form method="post" onsubmit="return confirm('Hapus tugas essay ini?')">
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="task_id" value="<?= chemnama_e((string) $task['id']); ?>">
                                <button type="submit" class="icon-btn delete-btn" title="Hapus"><?= chemnama_icon('trash', '#ef4444'); ?></button>
                            </form>
                        </div>
                    </div>

                    <p class="essay-question"><?= nl2br(chemnama_e((string) $task['prompt_text'])); ?></p>
                    <small class="text-muted">Deadline: <?= $task['due_at'] ? chemnama_e((string) $task['due_at']) : '-'; ?> · Jawaban masuk: <?= count($taskAnswers); ?></small>

                    <?php if (count($taskAnswers) > 0): ?>
                        <div class="essay-answer-list">
                            <?php foreach ($taskAnswers as $answer): ?>
                                <article class="essay-answer-card">
                                    <div class="essay-answer-head">
                                        <strong><?= chemnama_e((string) $answer['student_name']); ?></strong>
                                        <span><?= chemnama_e((string) $answer['submitted_at']); ?></span>
                                    </div>
                                    <p><?= nl2br(chemnama_e((string) $answer['answer_text'])); ?></p>

                                    <form method="post" class="essay-grade-form">
                                        <input type="hidden" name="action" value="grade_answer">
                                        <input type="hidden" name="answer_id" value="<?= chemnama_e((string) $answer['id']); ?>">

                                        <label>
                                            <span>Nilai (0-100)</span>
                                            <input type="number" name="score" min="0" max="100" value="<?= chemnama_e((string) ($answer['score'] ?? '')); ?>" required>
                                        </label>

                                        <label>
                                            <span>Catatan Guru</span>
                                            <input type="text" name="feedback" placeholder="Masukan untuk siswa" value="<?= chemnama_e((string) ($answer['feedback'] ?? '')); ?>">
                                        </label>

                                        <button type="submit" class="btn btn-secondary">Simpan Nilai</button>
                                    </form>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="essay-no-answer">Belum ada jawaban dari siswa untuk tugas ini.</div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
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
        const calendarRoot = document.querySelector('[data-guru-calendar-select]');
        if (!calendarRoot) {
            return;
        }

        const input = calendarRoot.querySelector('[data-guru-calendar-input]');
        const trigger = calendarRoot.querySelector('[data-guru-calendar-trigger]');
        const label = calendarRoot.querySelector('[data-guru-calendar-label]');
        const menu = calendarRoot.querySelector('[data-guru-calendar-menu]');
        const monthLabel = calendarRoot.querySelector('[data-guru-calendar-month]');
        const grid = calendarRoot.querySelector('[data-guru-calendar-grid]');
        const prevBtn = calendarRoot.querySelector('[data-guru-calendar-prev]');
        const nextBtn = calendarRoot.querySelector('[data-guru-calendar-next]');
        const clearBtn = calendarRoot.querySelector('[data-guru-calendar-clear]');
        const todayBtn = calendarRoot.querySelector('[data-guru-calendar-today]');

        if (!input || !trigger || !label || !menu || !monthLabel || !grid || !prevBtn || !nextBtn || !clearBtn || !todayBtn) {
            return;
        }

        const monthNames = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        const makeDate = (...args) => new window['Date'](...args);

        function parseIsoDate(value) {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) {
                return null;
            }

            const parts = value.split('-').map(Number);
            return makeDate(parts[0], parts[1] - 1, parts[2]);
        }

        function toIsoDate(date) {
            const y = date.getFullYear();
            const m = String(date.getMonth() + 1).padStart(2, '0');
            const d = String(date.getDate()).padStart(2, '0');
            return `${y}-${m}-${d}`;
        }

        const today = makeDate();
        const selectedDate = parseIsoDate(input.value);
        let viewYear = (selectedDate || today).getFullYear();
        let viewMonth = (selectedDate || today).getMonth();

        function updateLabel() {
            label.textContent = input.value ? input.value : 'Pilih tanggal deadline';
        }

        function closeMenu() {
            menu.hidden = true;
            trigger.setAttribute('aria-expanded', 'false');
        }

        function openMenu() {
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
        }

        function renderCalendar() {
            monthLabel.textContent = `${monthNames[viewMonth]} ${viewYear}`;
            grid.innerHTML = '';

            const firstDay = makeDate(viewYear, viewMonth, 1).getDay();
            const daysInMonth = makeDate(viewYear, viewMonth + 1, 0).getDate();

            for (let i = 0; i < firstDay; i += 1) {
                const filler = document.createElement('span');
                filler.className = 'forum-calendar-day is-muted';
                grid.appendChild(filler);
            }

            for (let day = 1; day <= daysInMonth; day += 1) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'forum-calendar-day';
                button.textContent = String(day);

                const date = makeDate(viewYear, viewMonth, day);
                const isoDate = toIsoDate(date);
                if (input.value === isoDate) {
                    button.classList.add('is-selected');
                }

                button.addEventListener('click', () => {
                    input.value = isoDate;
                    updateLabel();
                    renderCalendar();
                    closeMenu();
                });

                grid.appendChild(button);
            }
        }

        trigger.addEventListener('click', () => {
            if (menu.hidden) {
                openMenu();
            } else {
                closeMenu();
            }
        });

        prevBtn.addEventListener('click', () => {
            viewMonth -= 1;
            if (viewMonth < 0) {
                viewMonth = 11;
                viewYear -= 1;
            }
            renderCalendar();
        });

        nextBtn.addEventListener('click', () => {
            viewMonth += 1;
            if (viewMonth > 11) {
                viewMonth = 0;
                viewYear += 1;
            }
            renderCalendar();
        });

        clearBtn.addEventListener('click', () => {
            input.value = '';
            updateLabel();
            renderCalendar();
            closeMenu();
        });

        todayBtn.addEventListener('click', () => {
            input.value = toIsoDate(today);
            viewYear = today.getFullYear();
            viewMonth = today.getMonth();
            updateLabel();
            renderCalendar();
            closeMenu();
        });

        document.addEventListener('click', (event) => {
            if (!calendarRoot.contains(event.target)) {
                closeMenu();
            }
        });

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeMenu();
            }
        });

        updateLabel();
        renderCalendar();
    })();
</script>
<script>
    (function () {
        const tabs = document.querySelectorAll('[data-filter-tabs="essay"] .js-module-tab');
        const mobileDropdown = document.querySelector('[data-filter-dropdown="essay"]');
        const mobileTrigger = document.querySelector('[data-filter-trigger="essay"]');
        const mobileMenu = document.querySelector('[data-filter-menu="essay"]');
        const mobileOptions = document.querySelectorAll('[data-filter-option="essay"]');
        const mobileCurrentLabel = document.querySelector('[data-filter-current-label="essay"]');
        const items = document.querySelectorAll('[data-filter-list="essay"] .essay-item');
        const emptyState = document.querySelector('[data-filter-empty="essay"]');

        if (!tabs.length || !emptyState) {
            return;
        }

        const url = new window['URL'](window.location.href);
        const initialModule = url.searchParams.get('module') || '0';

        function applyFilter(moduleId, updateUrl) {
            let visibleCount = 0;

            items.forEach((item) => {
                const matches = moduleId === '0' || item.dataset.moduleId === moduleId;
                item.style.display = matches ? '' : 'none';
                if (matches) {
                    visibleCount += 1;
                }
            });

            emptyState.style.display = visibleCount === 0 ? '' : 'none';

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

            if (updateUrl) {
                const nextUrl = new window['URL'](window.location.href);
                if (moduleId === '0') {
                    nextUrl.searchParams.delete('module');
                } else {
                    nextUrl.searchParams.set('module', moduleId);
                }
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

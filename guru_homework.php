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

$modules = $pdo->query('SELECT id, badge, title FROM modules ORDER BY sort_order, id')->fetchAll();
$moduleMap = [];
foreach ($modules as $module) {
    $moduleMap[(int) $module['id']] = $module;
}

$totalStudentsStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM user_profiles up
     JOIN users u ON u.id = up.user_id
     WHERE up.kelas = :kelas AND u.role = "siswa"'
);
$totalStudentsStmt->execute(['kelas' => $activeClass]);
$totalStudents = (int) $totalStudentsStmt->fetchColumn();

$errors = [];
$action = (string) ($_POST['action'] ?? '');

if ($action === 'save_task') {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $dueAtRaw = trim((string) ($_POST['due_at'] ?? ''));
    $dueAt = $dueAtRaw !== '' ? str_replace('T', ' ', $dueAtRaw) . ':00' : null;

    if (!isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul wajib dipilih.';
    }

    if ($title === '' || $description === '') {
        $errors[] = 'Judul dan deskripsi PR wajib diisi.';
    }

    if ($dueAt !== null && strtotime($dueAt) === false) {
        $errors[] = 'Format deadline tidak valid.';
    }

    if (count($errors) === 0) {
        if ($taskId > 0) {
            $update = $pdo->prepare(
                'UPDATE homework_tasks
                 SET module_id = :module_id, class_name = :class_name, title = :title, description = :description, due_at = :due_at
                 WHERE id = :id AND created_by = :created_by'
            );
            $update->execute([
                'module_id' => $moduleId,
                'class_name' => $activeClass,
                'title' => $title,
                'description' => $description,
                'due_at' => $dueAt,
                'id' => $taskId,
                'created_by' => $user['id'],
            ]);

            chemnama_flash('PR berhasil diperbarui.', 'success');
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO homework_tasks (module_id, created_by, class_name, title, description, due_at)
                 VALUES (:module_id, :created_by, :class_name, :title, :description, :due_at)'
            );
            $insert->execute([
                'module_id' => $moduleId,
                'created_by' => $user['id'],
                'class_name' => $activeClass,
                'title' => $title,
                'description' => $description,
                'due_at' => $dueAt,
            ]);

            chemnama_flash('PR berhasil dibuat.', 'success');
        }

        header('Location: guru_homework.php');
        exit;
    }
}

if ($action === 'delete_task') {
    $taskId = (int) ($_POST['task_id'] ?? 0);
    if ($taskId > 0) {
        $delete = $pdo->prepare(
            'DELETE FROM homework_tasks
             WHERE id = :id AND created_by = :created_by
             AND class_name = :class_name'
        );
        $delete->execute(['id' => $taskId, 'created_by' => $user['id'], 'class_name' => $activeClass]);
        chemnama_flash('PR berhasil dihapus.', 'success');
    }

    header('Location: guru_homework.php');
    exit;
}

if ($action === 'check_submission') {
    $submissionId = (int) ($_POST['submission_id'] ?? 0);
    $feedback = trim((string) ($_POST['teacher_feedback'] ?? ''));

    if ($submissionId <= 0) {
        $errors[] = 'Data pengumpulan tidak valid.';
    }

    if (count($errors) === 0) {
        $check = $pdo->prepare(
            'SELECT s.id
             FROM homework_submissions s
             JOIN homework_tasks t ON t.id = s.task_id
             JOIN user_profiles up_student ON up_student.user_id = s.student_id
             WHERE s.id = :id AND t.created_by = :created_by
               AND up_student.kelas = :student_class
                             AND t.class_name = :task_class
             LIMIT 1'
        );
        $check->execute([
            'id' => $submissionId,
            'created_by' => $user['id'],
            'student_class' => $activeClass,
            'task_class' => $activeClass,
        ]);

        if ($check->fetch()) {
            $update = $pdo->prepare(
                'UPDATE homework_submissions
                 SET teacher_feedback = :teacher_feedback, is_checked = 1, checked_at = NOW(), checked_by = :checked_by
                 WHERE id = :id'
            );
            $update->execute([
                'teacher_feedback' => $feedback !== '' ? $feedback : null,
                'checked_by' => $user['id'],
                'id' => $submissionId,
            ]);

            chemnama_flash('Pengumpulan PR sudah dicek.', 'success');
            header('Location: guru_homework.php');
            exit;
        }

        $errors[] = 'Pengumpulan tidak ditemukan.';
    }
}

$mode = (string) ($_GET['mode'] ?? 'list');
$editId = (int) ($_GET['edit'] ?? 0);
$selectedModule = (int) ($_GET['module'] ?? 0);

$taskEditing = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare(
        'SELECT *
         FROM homework_tasks
         WHERE id = :id AND created_by = :created_by
                     AND class_name = :class_name
         LIMIT 1'
    );
    $editStmt->execute(['id' => $editId, 'created_by' => $user['id'], 'class_name' => $activeClass]);
    $taskEditing = $editStmt->fetch();
    if (!$taskEditing) {
        $editId = 0;
    }
}

$taskStmt = $pdo->prepare(
    'SELECT t.*, m.badge AS module_badge, m.title AS module_title,
            (
                SELECT COUNT(*)
                FROM homework_submissions hs
                JOIN user_profiles up_count ON up_count.user_id = hs.student_id
                WHERE hs.task_id = t.id AND up_count.kelas = :submit_class
            ) AS submit_count,
            (
                SELECT COUNT(*)
                FROM homework_submissions hs
                JOIN user_profiles up_checked ON up_checked.user_id = hs.student_id
                WHERE hs.task_id = t.id AND hs.is_checked = 1 AND up_checked.kelas = :checked_class
            ) AS checked_count
     FROM homework_tasks t
     JOIN modules m ON m.id = t.module_id
     WHERE t.created_by = :created_by
             AND t.class_name = :task_class
     ORDER BY t.created_at DESC'
);
$taskStmt->execute([
    'submit_class' => $activeClass,
    'checked_class' => $activeClass,
    'created_by' => $user['id'],
    'task_class' => $activeClass,
]);
$tasks = $taskStmt->fetchAll();

$countStmt = $pdo->prepare(
    'SELECT module_id, COUNT(*) AS total
     FROM homework_tasks
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

$submissionStmt = $pdo->prepare(
    'SELECT s.*, u.name AS student_name
     FROM homework_submissions s
     JOIN homework_tasks t ON t.id = s.task_id
     JOIN users u ON u.id = s.student_id
     JOIN user_profiles up_student ON up_student.user_id = u.id
     WHERE t.created_by = :created_by
       AND up_student.kelas = :student_class
             AND t.class_name = :task_class
     ORDER BY s.updated_at DESC'
);
$submissionStmt->execute([
    'created_by' => $user['id'],
    'student_class' => $activeClass,
    'task_class' => $activeClass,
]);
$submissions = $submissionStmt->fetchAll();

$submissionsByTask = [];
foreach ($submissions as $submission) {
    $tid = (int) $submission['task_id'];
    if (!isset($submissionsByTask[$tid])) {
        $submissionsByTask[$tid] = [];
    }
    $submissionsByTask[$tid][] = $submission;
}

$formData = [
    'task_id' => (int) ($taskEditing['id'] ?? 0),
    'module_id' => (int) ($taskEditing['module_id'] ?? ($modules[0]['id'] ?? 1)),
    'title' => (string) ($taskEditing['title'] ?? ''),
    'description' => (string) ($taskEditing['description'] ?? ''),
    'due_at' => (string) ($taskEditing['due_at'] ?? ''),
];

if ($action === 'save_task' && count($errors) > 0) {
    $formData = [
        'task_id' => (int) ($_POST['task_id'] ?? 0),
        'module_id' => (int) ($_POST['module_id'] ?? ($modules[0]['id'] ?? 1)),
        'title' => trim((string) ($_POST['title'] ?? '')),
        'description' => trim((string) ($_POST['description'] ?? '')),
        'due_at' => trim((string) ($_POST['due_at'] ?? '')),
    ];
}

$flash = chemnama_flash();

$guruMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => true],
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
    <title>PR / Homework - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
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
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_homework.php">
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

    <section class="guru-content homework-content">
        <div class="guru-headline-row">
            <div>
                <h1>PR / Homework</h1>
                <p>Upload PR dan cek pengumpulan siswa.</p>
            </div>
            <div class="guru-head-actions">
                <a class="btn btn-primary" href="guru_homework.php?mode=add"><?= chemnama_icon('plus', '#ffffff'); ?> Buat PR</a>
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
            <article class="guru-panel homework-form-panel">
                <h3><?= $editId > 0 ? 'Edit PR' : 'Buat PR Baru'; ?></h3>
                <form method="post" class="homework-form-grid">
                    <input type="hidden" name="action" value="save_task">
                    <input type="hidden" name="task_id" value="<?= chemnama_e((string) $formData['task_id']); ?>">

                    <label>
                        <span>Modul</span>
                        <div class="forum-custom-select" data-guru-custom-select="homework_module_id">
                            <input type="hidden" name="module_id" value="<?= chemnama_e((string) $formData['module_id']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <?php
                                    $selectedHwModuleLabel = isset($modules[0]) ? $modules[0]['badge'] . ' - ' . $modules[0]['title'] : 'Pilih Modul';
                                    foreach ($modules as $m) {
                                        if ((int) $m['id'] === (int) $formData['module_id']) {
                                            $selectedHwModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                            break;
                                        }
                                    }
                                ?>
                                <span class="forum-custom-select-label"><?= chemnama_e($selectedHwModuleLabel); ?></span>
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

                    <label>
                        <span>Deadline</span>
                        <input type="datetime-local" name="due_at" value="<?= chemnama_e($formData['due_at'] !== '' ? str_replace(' ', 'T', substr((string) $formData['due_at'], 0, 16)) : ''); ?>">
                    </label>

                    <label class="span-2">
                        <span>Judul PR</span>
                        <input type="text" name="title"placeholder="Judul PR" value="<?= chemnama_e($formData['title']); ?>" required>
                    </label>

                    <label class="span-2">
                        <span>Deskripsi PR</span>
                        <textarea name="description" placeholder="Jelaskan soal PR..." required><?= chemnama_e($formData['description']); ?></textarea>
                    </label>

                    <div class="form-action-row span-2">
                        <button class="btn btn-primary" type="submit">Buat PR</button>
                        <a class="btn btn-ghost" href="guru_homework.php">Batal</a>
                    </div>
                </form>
            </article>
        <?php endif; ?>

        <div class="homework-tabs" data-filter-tabs="homework">
            <a class="homework-tab js-module-tab <?= $selectedModule === 0 ? 'is-active' : ''; ?>" href="guru_homework.php" data-module="0">Semua (<?= $totalCount; ?>)</a>
            <?php foreach ($modules as $module): ?>
                <?php $count = $moduleCounts[(int) $module['id']] ?? 0; ?>
                <a class="homework-tab js-module-tab <?= $selectedModule === (int) $module['id'] ? 'is-active' : ''; ?>" href="guru_homework.php?module=<?= chemnama_e((string) $module['id']); ?>" data-module="<?= chemnama_e((string) $module['id']); ?>"><?= chemnama_e($module['badge']); ?> (<?= $count; ?>)</a>
            <?php endforeach; ?>
        </div>

        <div class="homework-filter-mobile">
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
            <div class="module-filter-dropdown" data-filter-dropdown="homework">
                <button class="module-filter-trigger" type="button" data-filter-trigger="homework" aria-expanded="false" aria-controls="homeworkFilterMenu">
                    <span data-filter-current-label="homework"><?= chemnama_e($selectedModuleLabel); ?></span>
                    <span class="module-filter-caret" aria-hidden="true"></span>
                </button>
                <div class="module-filter-menu" id="homeworkFilterMenu" data-filter-menu="homework" hidden>
                    <button class="module-filter-option <?= $selectedModule === 0 ? 'is-active' : ''; ?>" type="button" data-filter-option="homework" data-module="0">Semua (<?= $totalCount; ?>)</button>
                    <?php foreach ($modules as $module): ?>
                        <?php $count = $moduleCounts[(int) $module['id']] ?? 0; ?>
                        <button class="module-filter-option <?= $selectedModule === (int) $module['id'] ? 'is-active' : ''; ?>" type="button" data-filter-option="homework" data-module="<?= chemnama_e((string) $module['id']); ?>"><?= chemnama_e($module['badge']); ?> (<?= $count; ?>)</button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="homework-list" data-filter-list="homework">
            <article class="guru-panel empty-state" data-filter-empty="homework" style="<?= count($tasks) === 0 ? '' : 'display:none;'; ?>">
                <h3>Belum ada PR</h3>
                <p>Buat tugas PR agar siswa bisa mulai mengumpulkan berkas.</p>
            </article>

            <?php foreach ($tasks as $task): ?>
                <?php $taskSubs = $submissionsByTask[(int) $task['id']] ?? []; ?>
                <article class="guru-panel homework-item" data-module-id="<?= chemnama_e((string) $task['module_id']); ?>">
                    <div class="homework-item-head">
                        <div>
                            <div class="homework-chip-row">
                                <span class="homework-module-chip"><?= chemnama_e((string) $task['module_badge']); ?></span>
                                <strong><?= chemnama_e((string) $task['title']); ?></strong>
                                <span class="homework-count-chip"><?= chemnama_e((string) $task['submit_count']); ?> kumpul</span>
                            </div>
                            <p class="homework-description"><?= chemnama_e((string) $task['description']); ?></p>
                            <small class="text-muted">Deadline: <?= $task['due_at'] ? chemnama_e((string) $task['due_at']) : '-'; ?></small>
                        </div>
                        <div class="homework-actions">
                            <a class="icon-btn" href="guru_homework.php?mode=add&edit=<?= chemnama_e((string) $task['id']); ?>" title="Edit"><?= chemnama_icon('edit', '#2563eb'); ?></a>
                            <form method="post" onsubmit="return confirm('Hapus PR ini?')">
                                <input type="hidden" name="action" value="delete_task">
                                <input type="hidden" name="task_id" value="<?= chemnama_e((string) $task['id']); ?>">
                                <button type="submit" class="icon-btn delete-btn" title="Hapus"><?= chemnama_icon('trash', '#ef4444'); ?></button>
                            </form>
                        </div>
                    </div>

                    <div class="homework-submission-summary">
                        <span><?= chemnama_icon('users', '#475569'); ?> Pengumpulan siswa: <strong><?= chemnama_e((string) $task['submit_count']); ?></strong> dari <strong><?= chemnama_e((string) $totalStudents); ?></strong> siswa</span>
                        <span class="homework-summary-sep">•</span>
                        <span>Sudah dicek: <strong><?= chemnama_e((string) $task['checked_count']); ?></strong></span>
                    </div>

                    <div class="homework-submission-list">
                        <?php if (count($taskSubs) === 0): ?>
                            <div class="homework-no-submission">Belum ada pengumpulan dari siswa.</div>
                        <?php else: ?>
                            <?php foreach ($taskSubs as $sub): ?>
                                <div class="homework-submission-row">
                                    <div class="homework-student-block">
                                        <span class="student-initial"><?= strtoupper(substr((string) $sub['student_name'], 0, 1)); ?></span>
                                        <div>
                                            <strong><?= chemnama_e((string) $sub['student_name']); ?></strong>
                                            <small>
                                                <?= chemnama_icon('file', '#64748b'); ?>
                                                <?php if (!empty($sub['file_path'])): ?>
                                                    <a class="homework-file-link" href="<?= chemnama_e((string) $sub['file_path']); ?>" target="_blank" rel="noopener"><?= chemnama_e((string) $sub['file_name']); ?></a>
                                                <?php else: ?>
                                                    <?= chemnama_e((string) $sub['file_name']); ?>
                                                <?php endif; ?>
                                                · <?= chemnama_e((string) $sub['submitted_at']); ?>
                                            </small>
                                        </div>
                                    </div>

                                    <?php if ((int) ($sub['is_checked'] ?? 0) === 1): ?>
                                        <div class="homework-row-actions">
                                            <span class="homework-checked-pill"><?= chemnama_icon('check', '#0f9d58'); ?> Dicek</span>
                                            <?php if (!empty($sub['file_path'])): ?>
                                                <a class="homework-view-btn" href="<?= chemnama_e((string) $sub['file_path']); ?>" target="_blank" rel="noopener"><?= chemnama_icon('eye', '#0f172a'); ?> Lihat</a>
                                            <?php else: ?>
                                                <span class="homework-view-btn is-disabled"><?= chemnama_icon('eye', '#94a3b8'); ?> Lihat</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <form method="post" class="homework-check-form">
                                            <input type="hidden" name="action" value="check_submission">
                                            <input type="hidden" name="submission_id" value="<?= chemnama_e((string) $sub['id']); ?>">
                                            <input type="text" name="teacher_feedback" placeholder="Tulis feedback..." value="<?= chemnama_e((string) ($sub['teacher_feedback'] ?? '')); ?>">
                                            <button type="submit" class="btn btn-primary btn-sm">Cek</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
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
        const tabs = document.querySelectorAll('[data-filter-tabs="homework"] .js-module-tab');
        const mobileDropdown = document.querySelector('[data-filter-dropdown="homework"]');
        const mobileTrigger = document.querySelector('[data-filter-trigger="homework"]');
        const mobileMenu = document.querySelector('[data-filter-menu="homework"]');
        const mobileOptions = document.querySelectorAll('[data-filter-option="homework"]');
        const mobileCurrentLabel = document.querySelector('[data-filter-current-label="homework"]');
        const items = document.querySelectorAll('[data-filter-list="homework"] .homework-item');
        const emptyState = document.querySelector('[data-filter-empty="homework"]');

        if (!tabs.length || !emptyState) {
            return;
        }

        const url = new URL(window.location.href);
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
                const nextUrl = new URL(window.location.href);
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

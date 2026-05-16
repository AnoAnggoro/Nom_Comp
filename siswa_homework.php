<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth();

$user = chemnama_current_user();
if (($user['role'] ?? 'siswa') !== 'siswa') {
    header('Location: dashboard.php');
    exit;
}

$uploadDir = APP_ROOT . '/storage/uploads/homework';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$allowedExt = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'rar'];

$studentClassStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$studentClassStmt->execute(['user_id' => (int) $user['id']]);
$studentClass = (string) ($studentClassStmt->fetchColumn() ?: 'X IPA 1');

$modules = $pdo->query('SELECT id, badge, title FROM modules ORDER BY sort_order, id')->fetchAll();
$errors = [];
$action = (string) ($_POST['action'] ?? '');

if ($action === 'submit_homework') {
    $taskId = (int) ($_POST['task_id'] ?? 0);

    if ($taskId <= 0) {
        $errors[] = 'Tugas PR tidak valid.';
    }

    if (!isset($_FILES['submission_file']) || (int) $_FILES['submission_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'File PR wajib diupload.';
    }

    $taskStmt = $pdo->prepare(
        'SELECT t.id, t.due_at
         FROM homework_tasks t
         JOIN users g ON g.id = t.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = g.id
         WHERE t.id = :id
           AND t.is_published = 1
           AND g.role = "guru"
           AND (
               t.class_name = :kelas_exact
               OR (
                    t.class_name IS NULL
                                        AND INSTR(
                                            CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
                                            CONCAT(CHAR(44), :kelas_fallback, CHAR(44))
                                        ) > 0
               )
           )
         LIMIT 1'
    );
    $taskStmt->execute([
        'id' => $taskId,
        'kelas_exact' => $studentClass,
        'kelas_fallback' => $studentClass,
    ]);
    $taskRow = $taskStmt->fetch();
    if (!$taskRow) {
        $errors[] = 'Tugas PR tidak ditemukan.';
    } elseif (!empty($taskRow['due_at']) && strtotime((string) $taskRow['due_at']) !== false && time() > strtotime((string) $taskRow['due_at'])) {
        $errors[] = 'Deadline PR sudah lewat, file tidak bisa dikumpulkan.';
    }

    $existingStmt = $pdo->prepare('SELECT * FROM homework_submissions WHERE task_id = :task_id AND student_id = :student_id LIMIT 1');
    $existingStmt->execute(['task_id' => $taskId, 'student_id' => $user['id']]);
    $existing = $existingStmt->fetch();

    if ($existing && (int) ($existing['is_checked'] ?? 0) === 1) {
        $errors[] = 'PR sudah dicek guru dan tidak bisa diubah lagi.';
    }

    $uploadedFileName = null;
    $uploadedFilePath = null;

    if (isset($_FILES['submission_file']) && (int) $_FILES['submission_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int) $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload file gagal, silakan coba lagi.';
        } else {
            $originalName = (string) $_FILES['submission_file']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if (!in_array($extension, $allowedExt, true)) {
                $errors[] = 'Format file tidak didukung untuk pengumpulan PR.';
            } else {
                $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
                $safeName = trim((string) $safeName, '-');
                if ($safeName === '') {
                    $safeName = 'pr';
                }

                $newName = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $safeName . '.' . $extension;
                $target = $uploadDir . '/' . $newName;

                if (!move_uploaded_file((string) $_FILES['submission_file']['tmp_name'], $target)) {
                    $errors[] = 'File tidak dapat disimpan ke server.';
                } else {
                    if ($existing && !empty($existing['file_path'])) {
                        $oldAbsolute = APP_ROOT . '/' . ltrim((string) $existing['file_path'], '/');
                        if (is_file($oldAbsolute)) {
                            @unlink($oldAbsolute);
                        }
                    }

                    $uploadedFileName = $originalName;
                    $uploadedFilePath = 'storage/uploads/homework/' . $newName;
                }
            }
        }
    }

    if (count($errors) === 0 && $uploadedFileName !== null && $uploadedFilePath !== null) {
        if ($existing) {
            $update = $pdo->prepare(
                'UPDATE homework_submissions
                 SET file_name = :file_name, file_path = :file_path, submitted_at = NOW(),
                     teacher_feedback = NULL, is_checked = 0, checked_at = NULL, checked_by = NULL
                 WHERE id = :id'
            );
            $update->execute([
                'file_name' => $uploadedFileName,
                'file_path' => $uploadedFilePath,
                'id' => $existing['id'],
            ]);
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO homework_submissions (task_id, student_id, file_name, file_path)
                 VALUES (:task_id, :student_id, :file_name, :file_path)'
            );
            $insert->execute([
                'task_id' => $taskId,
                'student_id' => $user['id'],
                'file_name' => $uploadedFileName,
                'file_path' => $uploadedFilePath,
            ]);
        }

        chemnama_flash('PR berhasil dikumpulkan.', 'success');
        header('Location: siswa_homework.php');
        exit;
    }
}

$selectedModule = (int) ($_GET['module'] ?? 0);

$taskStmt = $pdo->prepare(
    'SELECT t.*, m.badge AS module_badge, m.title AS module_title,
            s.id AS submission_id, s.file_name, s.file_path, s.teacher_feedback, s.is_checked, s.checked_at, s.submitted_at,
            (
                SELECT COUNT(*)
                FROM homework_submissions hs
                JOIN user_profiles up_count ON up_count.user_id = hs.student_id
                WHERE hs.task_id = t.id AND up_count.kelas = :student_class_count
            ) AS submit_count
     FROM homework_tasks t
     JOIN modules m ON m.id = t.module_id
     JOIN users g ON g.id = t.created_by
     LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = g.id
     LEFT JOIN homework_submissions s ON s.task_id = t.id AND s.student_id = :student_id
     WHERE t.is_published = 1
       AND g.role = "guru"
       AND (
           t.class_name = :kelas_exact
           OR (
                t.class_name IS NULL
                AND INSTR(
                    CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
                    CONCAT(CHAR(44), :kelas_fallback, CHAR(44))
                ) > 0
           )
       )
     ORDER BY t.created_at DESC'
);
$taskStmt->execute([
    'student_class_count' => $studentClass,
    'student_id' => $user['id'],
    'kelas_exact' => $studentClass,
    'kelas_fallback' => $studentClass,
]);
$tasks = $taskStmt->fetchAll();

$countStmt = $pdo->prepare(
    'SELECT t.module_id, COUNT(*) AS total
     FROM homework_tasks t
     JOIN users g ON g.id = t.created_by
     LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = g.id
     WHERE t.is_published = 1
       AND g.role = "guru"
       AND (
           t.class_name = :kelas_exact
           OR (
                t.class_name IS NULL
                AND INSTR(
                    CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
                    CONCAT(CHAR(44), :kelas_fallback, CHAR(44))
                ) > 0
           )
       )
     GROUP BY t.module_id'
);
$countStmt->execute([
    'kelas_exact' => $studentClass,
    'kelas_fallback' => $studentClass,
]);
$countRows = $countStmt->fetchAll();
$moduleCounts = [];
$totalCount = 0;
foreach ($countRows as $row) {
    $mid = (int) $row['module_id'];
    $cnt = (int) $row['total'];
    $moduleCounts[$mid] = $cnt;
    $totalCount += $cnt;
}

$flash = chemnama_flash();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PR / Homework Siswa - Nom Comp</title>
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
                <a class="guru-menu-item" href="dashboard.php"><?= chemnama_icon('chart', '#6b7280'); ?><span>Dashboard</span></a>
                <a class="guru-menu-item" href="siswa_materi.php"><?= chemnama_icon('file', '#6b7280'); ?><span>Materi Guru</span></a>
                <!-- <a class="guru-menu-item" href="siswa_games.php"><?= chemnama_icon('beaker', '#6b7280'); ?><span>Games</span></a>
                <a class="guru-menu-item" href="siswa_quiz.php"><?= chemnama_icon('stack', '#6b7280'); ?><span>Kuis PG</span></a> -->
                <a class="guru-menu-item" href="siswa_essay.php"><?= chemnama_icon('edit', '#6b7280'); ?><span>Tugas Essay</span></a>
                <a class="guru-menu-item is-active" href="siswa_homework.php"><?= chemnama_icon('task', '#0f9d58'); ?><span>PR / Homework</span></a>
                <a class="guru-menu-item" href="siswa_forum.php"><?= chemnama_icon('chat', '#6b7280'); ?><span>Forum Diskusi</span></a>
                <a class="guru-menu-item" href="siswa_profil.php"><?= chemnama_icon('user', '#6b7280'); ?><span>Profil</span></a>
            </nav>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">AKUN</span>
            <nav class="guru-menu-list">
                <a class="guru-menu-item logout" href="logout.php"><?= chemnama_icon('logout', '#ef4444'); ?><span>Keluar</span></a>
            </nav>
        </div>
    </aside>

    <section class="guru-content student-content">
        <div class="siswa-essay-shell">
        <div class="siswa-essay-head">
            <h1>PR / Homework</h1>
            <p>Lihat dan kumpulkan PR.</p>
        </div>

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

        <div class="homework-tabs" data-filter-tabs="homework">
            <a class="homework-tab js-module-tab <?= $selectedModule === 0 ? 'is-active' : ''; ?>" href="siswa_homework.php" data-module="0">Semua (<?= $totalCount; ?>)</a>
            <?php foreach ($modules as $module): ?>
                <?php $count = $moduleCounts[(int) $module['id']] ?? 0; ?>
                <a class="homework-tab js-module-tab <?= $selectedModule === (int) $module['id'] ? 'is-active' : ''; ?>" href="siswa_homework.php?module=<?= chemnama_e((string) $module['id']); ?>" data-module="<?= chemnama_e((string) $module['id']); ?>">
                    <?= chemnama_e($module['badge']); ?> (<?= $count; ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <div class="module-filter-mobile" data-filter-mobile="homework">
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
                <button class="module-filter-trigger" type="button" data-filter-trigger="homework" aria-expanded="false" aria-controls="siswaHomeworkFilterMenu">
                    <span data-filter-current-label="homework"><?= chemnama_e($selectedModuleLabel); ?></span>
                    <span class="module-filter-caret" aria-hidden="true"></span>
                </button>
                <div class="module-filter-menu" id="siswaHomeworkFilterMenu" data-filter-menu="homework" hidden>
                    <button class="module-filter-option <?= $selectedModule === 0 ? 'is-active' : ''; ?>" type="button" data-filter-option="homework" data-module="0">Semua (<?= $totalCount; ?>)</button>
                    <?php foreach ($modules as $module): ?>
                        <?php $count = $moduleCounts[(int) $module['id']] ?? 0; ?>
                        <button class="module-filter-option <?= $selectedModule === (int) $module['id'] ? 'is-active' : ''; ?>" type="button" data-filter-option="homework" data-module="<?= chemnama_e((string) $module['id']); ?>"><?= chemnama_e($module['badge']); ?> (<?= $count; ?>)</button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="homework-list" data-filter-list="homework">
            <article class="panel-card empty-state" data-filter-empty="homework" style="<?= count($tasks) === 0 ? '' : 'display:none;'; ?>">
                <h3>Belum ada PR</h3>
                <p>PR dari guru akan tampil di sini.</p>
            </article>

            <?php foreach ($tasks as $task): ?>
                <?php
                    $submitted = !empty($task['submission_id']);
                    $checked = (int) ($task['is_checked'] ?? 0) === 1;
                ?>
                <article class="panel-card siswa-essay-item" data-module-id="<?= chemnama_e((string) $task['module_id']); ?>">
                    <div class="essay-item-head">
                        <div>
                            <span class="homework-module-chip"><?= chemnama_e((string) $task['module_badge']); ?></span>
                            <h3><?= chemnama_e((string) $task['title']); ?></h3>
                        </div>
                        <span class="essay-status <?= $checked ? 'graded' : ($submitted ? 'submitted' : 'pending'); ?>">
                            <?= $checked ? 'Dicek Guru' : ($submitted ? 'Sudah Upload' : 'Belum Upload'); ?>
                        </span>
                    </div>

                    <p class="essay-question"><?= chemnama_e((string) $task['description']); ?></p>
                    <small class="text-muted">Deadline: <?= $task['due_at'] ? chemnama_e((string) $task['due_at']) : '-'; ?> · Total yang kumpul: <?= chemnama_e((string) $task['submit_count']); ?></small>

                    <form method="post" enctype="multipart/form-data" class="homework-upload-form">
                        <input type="hidden" name="action" value="submit_homework">
                        <input type="hidden" name="task_id" value="<?= chemnama_e((string) $task['id']); ?>">

                        <label>
                            <span>Upload berkas jawaban</span>
                            <input type="file" name="submission_file" <?= $checked ? 'disabled' : ''; ?>>
                        </label>

                        <div class="essay-answer-meta">
                            <small>
                                Berkas terakhir:
                                <?php if (!empty($task['file_name'])): ?>
                                    <?php if (!empty($task['file_path'])): ?>
                                        <a href="<?= chemnama_e((string) $task['file_path']); ?>" target="_blank" rel="noopener"><?= chemnama_e((string) $task['file_name']); ?></a>
                                    <?php else: ?>
                                        <?= chemnama_e((string) $task['file_name']); ?>
                                    <?php endif; ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </small>
                            <small>Waktu upload: <?= !empty($task['submitted_at']) ? chemnama_e((string) $task['submitted_at']) : '-'; ?></small>
                        </div>

                        <div class="form-action-row">
                            <button type="submit" class="btn btn-primary" <?= $checked ? 'disabled' : ''; ?>><?= $submitted ? 'Upload Ulang' : 'Kumpulkan PR'; ?></button>
                        </div>
                    </form>

                    <?php if ($checked): ?>
                        <div class="essay-grade-result">
                            <strong>Status: Sudah dicek guru</strong>
                            <p><?= !empty($task['teacher_feedback']) ? chemnama_e((string) $task['teacher_feedback']) : 'Tidak ada feedback tambahan.'; ?></p>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        </div>
    </section>
</main>

<script src="assets/js/app.js?v=20260407"></script>
<script>
    (function () {
        const tabs = document.querySelectorAll('[data-filter-tabs="homework"] .js-module-tab');
        const mobileDropdown = document.querySelector('[data-filter-dropdown="homework"]');
        const mobileTrigger = document.querySelector('[data-filter-trigger="homework"]');
        const mobileMenu = document.querySelector('[data-filter-menu="homework"]');
        const mobileOptions = document.querySelectorAll('[data-filter-option="homework"]');
        const mobileCurrentLabel = document.querySelector('[data-filter-current-label="homework"]');
        const items = document.querySelectorAll('[data-filter-list="homework"] .siswa-essay-item');
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

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

$errors = [];
$action = (string) ($_POST['action'] ?? '');
$selectedModuleId = (int) ($_POST['module_id'] ?? 0);

$selectedModuleLabel = 'Semua Modul';
if ($selectedModuleId > 0 && isset($moduleMap[$selectedModuleId])) {
    $selectedModuleLabel = $moduleMap[$selectedModuleId]['badge'] . ' - ' . $moduleMap[$selectedModuleId]['title'];
}

if ($action === 'create_thread') {
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $body = trim((string) ($_POST['body'] ?? ''));

    if ($body === '') {
        $errors[] = 'Pesan diskusi tidak boleh kosong.';
    }

    if ($moduleId > 0 && !isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul tidak valid.';
    }

    if (count($errors) === 0) {
        $plainBody = preg_replace('/\s+/', ' ', strip_tags($body));
        $plainBody = trim((string) $plainBody);
        $title = substr($plainBody, 0, 80);
        if ($title === '') {
            $title = 'Pengumuman kelas';
        }

        $insert = $pdo->prepare(
            'INSERT INTO forum_threads (module_id, class_name, author_id, title, body, thread_type)
             VALUES (:module_id, :class_name, :author_id, :title, :body, :thread_type)'
        );
        $insert->execute([
            'module_id' => $moduleId > 0 ? $moduleId : null,
            'class_name' => $activeClass,
            'author_id' => $user['id'],
            'title' => $title,
            'body' => $body,
            'thread_type' => 'announcement',
        ]);

        chemnama_flash('Pengumuman berhasil dikirim.', 'success');
        header('Location: guru_forum.php');
        exit;
    }
}

if ($action === 'reply_thread') {
    $threadId = (int) ($_POST['thread_id'] ?? 0);
    $replyBody = trim((string) ($_POST['reply_body'] ?? ''));

    if ($threadId <= 0) {
        $errors[] = 'Thread tidak valid.';
    }

    if ($replyBody === '') {
        $errors[] = 'Balasan tidak boleh kosong.';
    }

    if (count($errors) === 0) {
        $checkThread = $pdo->prepare(
            'SELECT id FROM forum_threads WHERE id = :id AND class_name = :class_name LIMIT 1'
        );
        $checkThread->execute([
            'id' => $threadId,
            'class_name' => $activeClass,
        ]);

        if ($checkThread->fetch()) {
            $insertReply = $pdo->prepare(
                'INSERT INTO forum_replies (thread_id, author_id, body) VALUES (:thread_id, :author_id, :body)'
            );
            $insertReply->execute([
                'thread_id' => $threadId,
                'author_id' => $user['id'],
                'body' => $replyBody,
            ]);

            // Bubble active discussions to top after a new reply.
            $touchThread = $pdo->prepare('UPDATE forum_threads SET updated_at = NOW() WHERE id = :id');
            $touchThread->execute(['id' => $threadId]);

            chemnama_flash('Balasan berhasil dikirim.', 'success');
            header('Location: guru_forum.php');
            exit;
        }

        $errors[] = 'Thread tidak ditemukan untuk kelas ini.';
    }
}

$threadStmt = $pdo->prepare(
    'SELECT t.*, m.badge AS module_badge, u.name AS author_name, u.role AS author_role,
            (SELECT COUNT(*) FROM forum_replies r WHERE r.thread_id = t.id) AS reply_count
     FROM forum_threads t
     LEFT JOIN modules m ON m.id = t.module_id
     JOIN users u ON u.id = t.author_id
     WHERE t.class_name = :class_name
     ORDER BY t.updated_at DESC, t.id DESC'
);
$threadStmt->execute(['class_name' => $activeClass]);
$threads = $threadStmt->fetchAll();

$replyStmt = $pdo->prepare(
    'SELECT r.*, u.name AS author_name, u.role AS author_role
     FROM forum_replies r
     JOIN forum_threads t ON t.id = r.thread_id
     JOIN users u ON u.id = r.author_id
     WHERE t.class_name = :class_name
     ORDER BY r.created_at ASC, r.id ASC'
);
$replyStmt->execute(['class_name' => $activeClass]);
$replies = $replyStmt->fetchAll();

$repliesByThread = [];
foreach ($replies as $reply) {
    $threadId = (int) $reply['thread_id'];
    if (!isset($repliesByThread[$threadId])) {
        $repliesByThread[$threadId] = [];
    }
    $repliesByThread[$threadId][] = $reply;
}

$flash = chemnama_flash();
$showComposer = (string) ($_GET['mode'] ?? '') === 'add' || $action === 'create_thread';

$guruMenus = [
       ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
    ['label' => 'Quick / Quiz', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => false],
    ['label' => 'Pengaturan Games', 'icon' => 'beaker', 'href' => 'guru_games.php', 'active' => false],
    ['label' => 'Edit Intro Siswa', 'icon' => 'edit', 'href' => 'guru_intro_siswa.php', 'active' => false],
    ['label' => 'Forum Diskusi', 'icon' => 'chat', 'href' => 'guru_forum.php', 'active' => true],
    ['label' => 'Data Siswa', 'icon' => 'users', 'href' => 'guru_data_siswa.php', 'active' => false],
    ['label' => 'Profil', 'icon' => 'user', 'href' => 'guru_profil.php', 'active' => false],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forum Diskusi - Nom Comp</title>
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
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_forum.php">
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

    <section class="guru-content forum-content">
        <div class="guru-headline-row">
            <div>
                <h1>Forum Diskusi</h1>
                <p>Balas pertanyaan siswa dan kirim pengumuman kelas.</p>
            </div>
            <div class="guru-head-actions">
                <a class="btn btn-primary" href="guru_forum.php?mode=add"><?= chemnama_icon('plus', '#ffffff'); ?> Buat Pengumuman</a>
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

        <?php if ($showComposer): ?>
            <article class="guru-panel forum-compose-panel">
                <h3>Buat Pengumuman / Diskusi</h3>
                <form method="post" class="forum-compose-form">
                    <input type="hidden" name="action" value="create_thread">

                    <label>
                        <span>Modul (opsional)</span>
                        <div class="forum-custom-select" data-forum-custom-select>
                            <input type="hidden" name="module_id" value="<?= chemnama_e((string) $selectedModuleId); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <span class="forum-custom-select-label"><?= chemnama_e($selectedModuleLabel); ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <button class="forum-custom-select-option<?= $selectedModuleId === 0 ? ' is-selected' : ''; ?>" type="button" role="option" data-value="0" data-label="Semua Modul" aria-selected="<?= $selectedModuleId === 0 ? 'true' : 'false'; ?>">
                                    Semua Modul
                                </button>
                                <?php foreach ($modules as $module): ?>
                                    <?php $moduleLabel = $module['badge'] . ' - ' . $module['title']; ?>
                                    <button class="forum-custom-select-option<?= $selectedModuleId === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= chemnama_e((string) $module['id']); ?>" data-label="<?= chemnama_e($moduleLabel); ?>" aria-selected="<?= $selectedModuleId === (int) $module['id'] ? 'true' : 'false'; ?>">
                                        <?= chemnama_e($moduleLabel); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </label>

                    <label>
                        <span>Pesan</span>
                        <textarea name="body" placeholder="Tulis pengumuman atau pembuka diskusi..." required><?= chemnama_e((string) ($_POST['body'] ?? '')); ?></textarea>
                    </label>

                    <div class="form-action-row">
                        <button class="btn btn-primary" type="submit">Kirim</button>
                        <a class="btn btn-ghost" href="guru_forum.php">Batal</a>
                    </div>
                </form>
            </article>
        <?php endif; ?>

        <div class="forum-thread-list">
            <?php if (count($threads) === 0): ?>
                <article class="guru-panel empty-state">
                    <h3>Belum ada diskusi</h3>
                    <p>Pertanyaan siswa dan balasan guru akan muncul di sini.</p>
                </article>
            <?php endif; ?>

            <?php foreach ($threads as $thread): ?>
                <?php $threadReplies = $repliesByThread[(int) $thread['id']] ?? []; ?>
                <article class="guru-panel forum-thread-card">
                    <div class="forum-thread-head">
                        <div class="forum-thread-author">
                            <span class="forum-user-avatar <?= ($thread['author_role'] ?? 'siswa') === 'guru' ? 'is-guru' : ''; ?>"><?= strtoupper(substr((string) $thread['author_name'], 0, 1)); ?></span>
                            <div>
                                <div class="forum-author-meta">
                                    <strong><?= chemnama_e((string) $thread['author_name']); ?></strong>
                                    <?php if (!empty($thread['module_badge'])): ?>
                                        <span class="forum-module-pill"><?= chemnama_e((string) $thread['module_badge']); ?></span>
                                    <?php endif; ?>
                                    <span class="forum-role-pill <?= ($thread['author_role'] ?? 'siswa') === 'guru' ? 'is-guru' : 'is-siswa'; ?>"><?= chemnama_e(ucfirst((string) $thread['author_role'])); ?></span>
                                    <small><?= chemnama_e((string) $thread['created_at']); ?></small>
                                </div>
                                <p><?= nl2br(chemnama_e((string) $thread['body'])); ?></p>
                            </div>
                        </div>
                    </div>

                    <?php if (count($threadReplies) > 0): ?>
                        <div class="forum-reply-list">
                            <?php foreach ($threadReplies as $reply): ?>
                                <div class="forum-reply-item <?= ($reply['author_role'] ?? 'siswa') === 'guru' ? 'is-guru' : ''; ?>">
                                    <div class="forum-author-meta">
                                        <strong><?= chemnama_e((string) $reply['author_name']); ?></strong>
                                        <span class="forum-role-pill <?= ($reply['author_role'] ?? 'siswa') === 'guru' ? 'is-guru' : 'is-siswa'; ?>"><?= chemnama_e(ucfirst((string) $reply['author_role'])); ?></span>
                                        <small><?= chemnama_e((string) $reply['created_at']); ?></small>
                                    </div>
                                    <p><?= nl2br(chemnama_e((string) $reply['body'])); ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="forum-reply-form">
                        <input type="hidden" name="action" value="reply_thread">
                        <input type="hidden" name="thread_id" value="<?= chemnama_e((string) $thread['id']); ?>">
                        <input type="text" name="reply_body" placeholder="Tulis balasan..." required>
                        <button type="submit" class="btn btn-primary btn-sm"><?= chemnama_icon('chat', '#ffffff'); ?> Balas</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<script src="assets/js/app.js?v=20260407"></script>
<script>
(function () {
    const selects = document.querySelectorAll('[data-forum-custom-select]');

    const closeAll = () => {
        selects.forEach((select) => {
            const trigger = select.querySelector('.forum-custom-select-trigger');
            const menu = select.querySelector('.forum-custom-select-menu');
            if (trigger) {
                trigger.setAttribute('aria-expanded', 'false');
            }
            if (menu) {
                menu.hidden = true;
            }
        });
    };

    selects.forEach((select) => {
        const trigger = select.querySelector('.forum-custom-select-trigger');
        const menu = select.querySelector('.forum-custom-select-menu');
        const hiddenInput = select.querySelector('input[type="hidden"]');
        const label = select.querySelector('.forum-custom-select-label');

        if (!trigger || !menu || !hiddenInput || !label) {
            return;
        }

        trigger.addEventListener('click', function () {
            const willOpen = menu.hidden;
            closeAll();
            menu.hidden = !willOpen;
            trigger.setAttribute('aria-expanded', String(willOpen));
        });

        menu.querySelectorAll('.forum-custom-select-option').forEach((option) => {
            option.addEventListener('click', function () {
                const value = option.getAttribute('data-value') || '0';
                const optionLabel = option.getAttribute('data-label') || option.textContent.trim();

                hiddenInput.value = value;
                label.textContent = optionLabel;

                menu.querySelectorAll('.forum-custom-select-option').forEach((item) => {
                    item.classList.remove('is-selected');
                    item.setAttribute('aria-selected', 'false');
                });
                option.classList.add('is-selected');
                option.setAttribute('aria-selected', 'true');

                closeAll();
            });
        });
    });

    document.addEventListener('click', function (event) {
        if (!event.target.closest('[data-forum-custom-select]')) {
            closeAll();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeAll();
        }
    });
})();
</script>
</body>
</html>

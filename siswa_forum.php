<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth();

$user = chemnama_current_user();
if (($user['role'] ?? 'siswa') !== 'siswa') {
    header('Location: dashboard.php');
    exit;
}

function chemnama_user_class(PDO $pdo, int $userId): string
{
    try {
        $stmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $className = (string) ($stmt->fetchColumn() ?: '');
        if ($className !== '') {
            return $className;
        }
    } catch (Throwable $exception) {
        // Fallback when user_profiles has no row yet.
    }

    return 'X IPA 1';
}

$activeClass = chemnama_user_class($pdo, (int) $user['id']);
$modules = $pdo->query('SELECT id, badge, title FROM modules ORDER BY sort_order, id')->fetchAll();
$moduleMap = [];
foreach ($modules as $module) {
    $moduleMap[(int) $module['id']] = $module;
}

// Custom select defaults
$selectedModuleId = 0;
$selectedModuleLabel = 'Umum';

$errors = [];
$action = (string) ($_POST['action'] ?? '');

if ($action === 'create_question') {
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $question = trim((string) ($_POST['question'] ?? ''));

    if ($question === '') {
        $errors[] = 'Pertanyaan tidak boleh kosong.';
    }

    if ($moduleId > 0 && !isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul tidak valid.';
    }

    if (count($errors) === 0) {
        $plainQuestion = preg_replace('/\s+/', ' ', strip_tags($question));
        $plainQuestion = trim((string) $plainQuestion);
        $title = substr($plainQuestion, 0, 80);
        if ($title === '') {
            $title = 'Pertanyaan siswa';
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
            'body' => $question,
            'thread_type' => 'question',
        ]);

        chemnama_flash('Pertanyaan berhasil dikirim ke forum kelas.', 'success');
        header('Location: siswa_forum.php');
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

            // Keep active conversations at the top.
            $touchThread = $pdo->prepare('UPDATE forum_threads SET updated_at = NOW() WHERE id = :id');
            $touchThread->execute(['id' => $threadId]);

            chemnama_flash('Balasan berhasil dikirim.', 'success');
            header('Location: siswa_forum.php');
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
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forum Diskusi Siswa - Nom Comp</title>
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
            <div class="guru-class-chip"><?= chemnama_e($activeClass); ?></div>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">MENU</span>
            <nav class="guru-menu-list">
                  <a class="guru-menu-item" href="dashboard.php"><?= chemnama_icon('chart', '#6b7280'); ?><span>Dashboard</span></a>
                <a class="guru-menu-item" href="siswa_materi.php"><?= chemnama_icon('file', '#6b7280'); ?><span>Materi</span></a>
                 <!-- <a class="guru-menu-item" href="siswa_games.php"><?= chemnama_icon('beaker', '#6b7280'); ?><span>Games</span></a> -->
                <!-- <a class="guru-menu-item" href="siswa_quiz.php"><?= chemnama_icon('stack', '#6b7280'); ?><span>Kuis PG</span></a> -->
                <a class="guru-menu-item" href="siswa_essay.php"><?= chemnama_icon('edit', '#6b7280'); ?><span>Exercise</span></a>
                <a class="guru-menu-item is-active" href="siswa_forum.php"><?= chemnama_icon('chat', '#0f9d58'); ?><span>Forum Diskusi</span></a>
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
        <div class="forum-student-shell">
        <div class="siswa-essay-head">
            <h1>Forum Diskusi Kelas</h1>
            <p>Lihat pertanyaan seluruh siswa dan jawaban dari guru.</p>
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

        <article class="panel-card forum-compose-panel">
            <h3>Tanyakan ke guru</h3>
            <form method="post" class="forum-compose-form">
                <input type="hidden" name="action" value="create_question">

                <label>
                    <span>Modul (opsional)</span>
                    <div class="forum-custom-select" data-forum-custom-select>
                        <input type="hidden" name="module_id" value="<?= chemnama_e((string) $selectedModuleId); ?>">
                        <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                            <span class="forum-custom-select-label"><?= chemnama_e($selectedModuleLabel); ?></span>
                            <span class="forum-custom-select-caret" aria-hidden="true"></span>
                        </button>
                        <div class="forum-custom-select-menu" role="listbox" hidden>
                            <button class="forum-custom-select-option<?= $selectedModuleId === 0 ? ' is-selected' : ''; ?>" type="button" role="option" data-value="0" data-label="Umum" aria-selected="<?= $selectedModuleId === 0 ? 'true' : 'false'; ?>">
                                Umum
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
                    <span>Pertanyaan</span>
                    <textarea name="question" placeholder="Tulis pertanyaanmu..." required><?= chemnama_e((string) ($_POST['question'] ?? '')); ?></textarea>
                </label>

                <div class="form-action-row">
                    <button class="btn btn-primary" type="submit">Kirim Pertanyaan</button>
                </div>
            </form>
        </article>

        <div class="forum-thread-list">
            <?php if (count($threads) === 0): ?>
                <article class="panel-card empty-state">
                    <h3>Belum ada pertanyaan</h3>
                    <p>Diskusi kelas akan muncul setelah ada pertanyaan atau pengumuman.</p>
                </article>
            <?php endif; ?>

            <?php foreach ($threads as $thread): ?>
                <?php $threadReplies = $repliesByThread[(int) $thread['id']] ?? []; ?>
                <article class="panel-card forum-thread-card">
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
                    <?php else: ?>
                        <div class="forum-await-reply">Belum ada balasan.</div>
                    <?php endif; ?>

                    <form method="post" class="forum-reply-form">
                        <input type="hidden" name="action" value="reply_thread">
                        <input type="hidden" name="thread_id" value="<?= chemnama_e((string) $thread['id']); ?>">
                        <input type="text" name="reply_body" placeholder="Tulis balasan untuk thread ini..." required>
                        <button type="submit" class="btn btn-primary btn-sm"><?= chemnama_icon('chat', '#ffffff'); ?> Balas</button>
                    </form>
                </article>
            <?php endforeach; ?>
        </div>
        </div>
    </section>
</main>

<script src="assets/js/app.js?v=20260407"></script>
<script>
    // Forum custom select handler
    (function(){
        document.querySelectorAll('[data-forum-custom-select]').forEach((selectRoot) => {
            const trigger = selectRoot.querySelector('.forum-custom-select-trigger');
            const menu = selectRoot.querySelector('.forum-custom-select-menu');
            const valueInput = selectRoot.querySelector('input[name="module_id"]');
            const label = selectRoot.querySelector('.forum-custom-select-label');
            const options = selectRoot.querySelectorAll('.forum-custom-select-option');

            if (!trigger || !menu || !valueInput || !label || !options.length) {
                return;
            }

            const closeMenu = () => {
                menu.setAttribute('hidden', '');
                trigger.setAttribute('aria-expanded', 'false');
            };

            const openMenu = () => {
                menu.removeAttribute('hidden');
                trigger.setAttribute('aria-expanded', 'true');
            };

            // Toggle menu on trigger click
            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                if (menu.hasAttribute('hidden')) {
                    openMenu();
                } else {
                    closeMenu();
                }
            });

            // Handle option selection
            options.forEach((option) => {
                option.addEventListener('click', (e) => {
                    e.preventDefault();
                    const value = option.getAttribute('data-value') || '';
                    const optionLabel = option.getAttribute('data-label') || option.textContent;

                    valueInput.value = value;
                    label.textContent = optionLabel;

                    // Update selected state
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

            // Close menu on outside click
            document.addEventListener('click', (event) => {
                if (!selectRoot.contains(event.target)) {
                    closeMenu();
                }
            });

            // Close menu on Escape key
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

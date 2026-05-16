<?php
require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('guru');

$user = chemnama_current_user();
$activeClass = chemnama_get_guru_active_class($pdo, (int) $user['id']);

$errors = [];
$flash = null;

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string) $_POST['action'];

    if ($action === 'save_intro') {
        $introId = (int) ($_POST['intro_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');

        if ($title === '') {
            $errors[] = 'Judul intro tidak boleh kosong.';
        }
        if ($content === '') {
            $errors[] = 'Konten intro tidak boleh kosong.';
        }

        if (count($errors) === 0) {
            if ($introId > 0) {
                $checkStmt = $pdo->prepare('SELECT id FROM student_intros WHERE id = :id AND created_by = :created_by LIMIT 1');
                $checkStmt->execute(['id' => $introId, 'created_by' => $user['id']]);
                $existing = $checkStmt->fetch();
                if (!$existing) {
                    $errors[] = 'Intro tidak ditemukan atau bukan milik Anda.';
                }
            }

            if (count($errors) === 0) {
                try {
                    if ($introId > 0) {
                        $stmt = $pdo->prepare('UPDATE student_intros SET title = :title, content = :content WHERE id = :id AND created_by = :created_by');
                        $stmt->execute([
                            'title' => $title,
                            'content' => $content,
                            'id' => $introId,
                            'created_by' => $user['id'],
                        ]);
                        chemnama_flash('success', 'Intro berhasil diperbarui.');
                    } else {
                        $stmt = $pdo->prepare('INSERT INTO student_intros (created_by, class_name, title, content) VALUES (:created_by, :class_name, :title, :content)');
                        $stmt->execute([
                            'created_by' => $user['id'],
                            'class_name' => $activeClass,
                            'title' => $title,
                            'content' => $content,
                        ]);
                        chemnama_flash('success', 'Intro berhasil ditambahkan.');
                    }
                } catch (Exception $e) {
                    chemnama_flash('error', 'Terjadi kesalahan: ' . $e->getMessage());
                }
                header('Location: guru_intro_siswa.php');
                exit;
            }
        }
    }

    if ($action === 'delete_intro') {
        $introId = (int) ($_POST['intro_id'] ?? 0);
        if ($introId > 0) {
            $stmt = $pdo->prepare('DELETE FROM student_intros WHERE id = :id AND created_by = :created_by');
            $stmt->execute(['id' => $introId, 'created_by' => $user['id']]);
            chemnama_flash('success', 'Intro berhasil dihapus.');
            header('Location: guru_intro_siswa.php');
            exit;
        }
    }
}

$mode = (string) ($_GET['mode'] ?? 'list');
$editId = (int) ($_GET['edit'] ?? 0);

$editingIntro = null;
if ($editId > 0 && $mode === 'edit') {
    $stmt = $pdo->prepare('SELECT * FROM student_intros WHERE id = :id AND created_by = :created_by LIMIT 1');
    $stmt->execute(['id' => $editId, 'created_by' => $user['id']]);
    $editingIntro = $stmt->fetch();
    if (!$editingIntro) {
        $editId = 0;
        $mode = 'list';
    }
}

$showForm = $mode === 'add' || ($mode === 'edit' && $editingIntro);

// Get all intros for this teacher in active class
$listStmt = $pdo->prepare('SELECT * FROM student_intros WHERE created_by = :created_by AND class_name = :class_name ORDER BY updated_at DESC');
$listStmt->execute(['created_by' => $user['id'], 'class_name' => $activeClass]);
$intros = $listStmt->fetchAll();

$flash = chemnama_flash();

$guruMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
    ['label' => 'Quick / Quiz', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => false],
    ['label' => 'Pengaturan Games', 'icon' => 'beaker', 'href' => 'guru_games.php', 'active' => false],
    ['label' => 'Edit Intro Siswa', 'icon' => 'edit', 'href' => 'guru_intro_siswa.php', 'active' => true],
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
    <title>Edit Intro Siswa - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .intro-preview-box {
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 12px;
            padding: 24px;
            margin-top: 16px;
        }

        .intro-preview-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: #f1f5f9;
            margin: 0 0 16px 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .intro-preview-icon {
            width: 32px;
            height: 32px;
            background: #8b5cf6;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .intro-preview-content {
            color: #cbd5e1;
            line-height: 1.6;
            font-size: 14px;
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
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_intro_siswa.php">
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

    <section class="guru-content materi-content">
        <div class="guru-headline-row">
            <div>
                <h1>Edit Intro Siswa</h1>
                <p>Ubah teks pengantar yang tampil di halaman materi siswa.</p>
            </div>
        </div>

        <a class="guru-back-link guru-back-link-inline" href="javascript:history.back()"><?= chemnama_icon('switch', '#64748b'); ?> Kembali</a>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?= chemnama_e((string) $flash['message']); ?></div>
        <?php endif; ?>

        <?php if (count($errors) > 0): ?>
            <div class="alert alert-error"><?= chemnama_e(implode(' ', $errors)); ?></div>
        <?php endif; ?>

        <?php if ($showForm): ?>
            <article class="guru-panel materi-form-panel">
                <h3><?= $editId > 0 ? 'Edit Intro Siswa' : 'Tambah Intro Siswa'; ?></h3>
                <form method="post" class="materi-form-grid">
                    <input type="hidden" name="action" value="save_intro">
                    <input type="hidden" name="intro_id" value="<?= chemnama_e((string) ($editId > 0 ? $editId : 0)); ?>">

                    <label class="span-2">
                        <span>Judul Intro</span>
                        <input type="text" name="title" placeholder="Contoh: Apa itu Tata Nama Senyawa?" value="<?= chemnama_e((string) ($editingIntro['title'] ?? '')); ?>" required>
                    </label>

                    <label class="span-2">
                        <span>Konten Intro (Ditampilkan di awal materi siswa)</span>
                        <small style="color: #cbd5e1; font-weight: 400;">Tips: gunakan &lt;strong&gt;...&lt;/strong&gt; untuk teks tebal.</small>
                        <textarea name="content" placeholder="Jelaskan senyawa ini..." rows="6" required><?= chemnama_e((string) ($editingIntro['content'] ?? '')); ?></textarea>
                    </label>

                    <div class="form-action-row span-2">
                        <button class="btn btn-primary" type="submit"><?= chemnama_icon('upload', '#ffffff'); ?> <?= $editId > 0 ? 'Perbarui' : 'Tambah'; ?></button>
                        <a class="btn btn-ghost" href="guru_intro_siswa.php">Batal</a>
                    </div>
                </form>

                <?php if ($editingIntro): ?>
                    <div class="intro-preview-box">
                        <h3 style="margin-top: 0;">Preview</h3>
                        <div class="intro-preview-title">
                            <div class="intro-preview-icon"><?= chemnama_icon('file', '#ffffff'); ?></div>
                            <span><?= chemnama_e((string) $editingIntro['title']); ?></span>
                        </div>
                        <div class="intro-preview-content">
                            <?= nl2br(chemnama_e((string) $editingIntro['content'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </article>
        <?php endif; ?>

        <div class="materi-list">
            <?php if (count($intros) === 0): ?>
                <article class="guru-panel materi-item empty">
                    <h3>Belum ada intro</h3>
                    <p>Tambahkan intro untuk siswa agar ditampilkan di halaman materi.</p>
                    <a class="btn btn-primary" href="guru_intro_siswa.php?mode=add"><?= chemnama_icon('plus', '#ffffff'); ?> Tambah Intro</a>
                </article>
            <?php else: ?>
                <?php foreach ($intros as $intro): ?>
                    <article class="guru-panel materi-item">
                        <div class="materi-item-head">
                            <div class="materi-chip-group">
                                <span class="materi-chip" style="color: #8b5cf6; background: #8b5cf615;">
                                    <?= chemnama_icon('file', '#8b5cf6'); ?>
                                    Intro
                                </span>
                                <span class="materi-module-label"><?= (int) $intro['is_active'] === 1 ? 'Aktif' : 'Nonaktif'; ?></span>
                            </div>
                            <div class="materi-actions">
                                <a class="icon-action" href="guru_intro_siswa.php?mode=edit&edit=<?= chemnama_e((string) $intro['id']); ?>" title="Edit">
                                    <?= chemnama_icon('edit', '#2563eb'); ?>
                                </a>
                                <form method="post" onsubmit="return confirm('Hapus intro ini?')" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_intro">
                                    <input type="hidden" name="intro_id" value="<?= chemnama_e((string) $intro['id']); ?>">
                                    <button class="icon-action danger" type="submit" title="Hapus">
                                        <?= chemnama_icon('trash', '#ef4444'); ?>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <h3><?= chemnama_e((string) $intro['title']); ?></h3>
                        <p><?= substr(strip_tags((string) $intro['content']), 0, 100); ?>...</p>
                        <small><?= chemnama_e((string) $intro['updated_at']); ?></small>
                    </article>
                <?php endforeach; ?>
                <div style="margin-top: 24px;">
                    <a class="btn btn-primary" href="guru_intro_siswa.php?mode=add"><?= chemnama_icon('plus', '#ffffff'); ?> Tambah Intro Baru</a>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>
<script>
    // Sidebar toggle (mobile) — update `.guru-page` class to match CSS rules
    (function(){
        const toggle = document.querySelector('[data-guru-sidebar-toggle]');
        const overlay = document.querySelector('[data-guru-sidebar-overlay]');
        const guruPage = document.querySelector('.guru-page');

        function openSidebar() {
            if (!guruPage) return;
            guruPage.classList.add('is-sidebar-open');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
        }

        function closeSidebar() {
            if (!guruPage) return;
            guruPage.classList.remove('is-sidebar-open');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }

        toggle?.addEventListener('click', function (e) {
            e.preventDefault();
            const mobileOnly = window.matchMedia('(max-width: 1080px)').matches;
            if (!mobileOnly) return;
            if (guruPage.classList.contains('is-sidebar-open')) closeSidebar();
            else openSidebar();
        });

        overlay?.addEventListener('click', function () {
            closeSidebar();
        });
    })();
</script>
</html>
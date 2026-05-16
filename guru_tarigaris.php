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
chemnama_ensure_chem_match_reads_table($pdo);

$modules = $pdo->query('SELECT id, badge, title, accent, icon FROM modules ORDER BY sort_order, id')->fetchAll();
$moduleMap = [];
foreach ($modules as $module) {
    $moduleMap[(int) $module['id']] = $module;
}

$simulationCount = (int) $pdo->query('SELECT COUNT(*) FROM simulations')->fetchColumn();
$matchCount = (int) $pdo->query('SELECT COUNT(*) FROM chem_match_games')->fetchColumn();
$wordCount = (int) $pdo->query('SELECT COUNT(*) FROM word_search_games')->fetchColumn();

$classStudentCountStmt = $pdo->prepare('SELECT COUNT(*) FROM users u JOIN user_profiles up ON up.user_id = u.id WHERE u.role = "siswa" AND up.kelas = :kelas');
$classStudentCountStmt->execute(['kelas' => $activeClass]);
$totalClassStudents = (int) $classStudentCountStmt->fetchColumn();

$errors = [];
$action = (string) ($_POST['action'] ?? '');

if ($action === 'save_match') {
    $matchId = (int) ($_POST['match_id'] ?? 0);
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $leftTerm = trim((string) ($_POST['left_term'] ?? ''));
    $rightTerm = trim((string) ($_POST['right_term'] ?? ''));
    $hint = trim((string) ($_POST['hint'] ?? ''));
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    if (!isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul wajib dipilih.';
    }
    if ($leftTerm === '' || $rightTerm === '') {
        $errors[] = 'Kata kiri dan jawaban wajib diisi.';
    }

    if (count($errors) === 0) {
        if ($matchId > 0) {
            $stmt = $pdo->prepare('UPDATE chem_match_games SET module_id = :module_id, left_term = :left_term, right_term = :right_term, hint = :hint, is_published = :is_published WHERE id = :id AND created_by = :created_by');
            $stmt->execute([
                'module_id' => $moduleId,
                'left_term' => $leftTerm,
                'right_term' => $rightTerm,
                'hint' => $hint !== '' ? $hint : null,
                'is_published' => $isPublished,
                'id' => $matchId,
                'created_by' => $user['id'],
            ]);
            chemnama_flash('Tarik garis berhasil diperbarui.', 'success');
        } else {
            $stmt = $pdo->prepare('INSERT INTO chem_match_games (module_id, created_by, class_name, left_term, right_term, hint, is_published) VALUES (:module_id, :created_by, :class_name, :left_term, :right_term, :hint, :is_published)');
            $stmt->execute([
                'module_id' => $moduleId,
                'created_by' => $user['id'],
                'class_name' => $activeClass,
                'left_term' => $leftTerm,
                'right_term' => $rightTerm,
                'hint' => $hint !== '' ? $hint : null,
                'is_published' => $isPublished,
            ]);
            chemnama_flash('Tarik garis berhasil ditambahkan.', 'success');
        }
        header('Location: guru_tarigaris.php');
        exit;
    }
}

if ($action === 'delete_match') {
    $matchId = (int) ($_POST['match_id'] ?? 0);
    if ($matchId > 0) {
        $stmt = $pdo->prepare('DELETE FROM chem_match_games WHERE id = :id AND created_by = :created_by');
        $stmt->execute(['id' => $matchId, 'created_by' => $user['id']]);
        chemnama_flash('Tarik garis berhasil dihapus.', 'success');
    }
    header('Location: guru_tarigaris.php');
    exit;
}

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM chem_match_games WHERE id = :id AND created_by = :created_by LIMIT 1');
    $stmt->execute(['id' => $editId, 'created_by' => $user['id']]);
    $editing = $stmt->fetch();
    if (!$editing) {
        $editId = 0;
    }
}

$listStmt = $pdo->prepare('SELECT g.*, mo.badge AS module_badge, mo.title AS module_title, u.name AS teacher_name, COALESCE(r.read_count, 0) AS read_count FROM chem_match_games g JOIN modules mo ON mo.id = g.module_id JOIN users u ON u.id = g.created_by LEFT JOIN (SELECT chem_match_id, COUNT(*) AS read_count FROM chem_match_reads GROUP BY chem_match_id) r ON r.chem_match_id = g.id WHERE g.created_by = :created_by ORDER BY g.created_at DESC');
$listStmt->execute(['created_by' => $user['id']]);
$games = $listStmt->fetchAll();

$gamesByModule = [];
foreach ($games as $game) {
    $moduleId = (int) $game['module_id'];
    if (!isset($gamesByModule[$moduleId])) {
        $gamesByModule[$moduleId] = [];
    }
    $gamesByModule[$moduleId][] = $game;
}

$formData = [
    'match_id' => $editing['id'] ?? 0,
    'module_id' => (int) ($editing['module_id'] ?? ($modules[0]['id'] ?? 1)),
    'left_term' => (string) ($editing['left_term'] ?? ''),
    'right_term' => (string) ($editing['right_term'] ?? ''),
    'hint' => (string) ($editing['hint'] ?? ''),
    'is_published' => (int) ($editing['is_published'] ?? 1),
];

$guruMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => true],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
    ['label' => 'Quiz Quiz', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => false],
    ['label' => 'Pengaturan Games', 'icon' => 'beaker', 'href' => 'guru_games.php', 'active' => false],
    ['label' => 'Edit Intro Siswa', 'icon' => 'edit', 'href' => 'guru_intro_siswa.php', 'active' => false],
    ['label' => 'Forum Diskusi', 'icon' => 'chat', 'href' => 'guru_forum.php', 'active' => false],
    ['label' => 'Data Siswa', 'icon' => 'users', 'href' => 'guru_data_siswa.php', 'active' => false],
    ['label' => 'Profil', 'icon' => 'user', 'href' => 'guru_profil.php', 'active' => false],
];
$flash = chemnama_flash();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tarik Garis - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<main class="guru-page">
    <button class="guru-mobile-toggle" type="button" data-guru-sidebar-toggle aria-label="Buka navigasi" aria-controls="guruSidebar" aria-expanded="false"><span></span><span></span><span></span></button>
    <div class="guru-sidebar-overlay" data-guru-sidebar-overlay></div>
    <aside class="guru-sidebar" id="guruSidebar">
        <div class="guru-brand"><a class="brand" href="index.php"><?= chemnama_icon('brand', '#d97706'); ?><span>Nom Comp</span></a><p>Panel Guru</p><div class="guru-class-chip"><?= chemnama_e($activeClass); ?></div></div>
        <div class="guru-menu-block"><span class="guru-menu-title">MENU</span><nav class="guru-menu-list"><?php foreach ($guruMenus as $menu): ?><a class="guru-menu-item <?= $menu['active'] ? 'is-active' : ''; ?>" href="<?= chemnama_e($menu['href']); ?>"><?= chemnama_icon($menu['icon'], $menu['active'] ? '#0f9d58' : '#6b7280'); ?><span><?= chemnama_e($menu['label']); ?></span></a><?php endforeach; ?></nav></div>
        <div class="guru-menu-block"><span class="guru-menu-title">NAVIGASI</span><nav class="guru-menu-list"><a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_tarigaris.php"><?= chemnama_icon('switch', '#d97706'); ?><span>Ganti Kelas</span></a><a class="guru-menu-item logout" href="logout.php"><?= chemnama_icon('logout', '#ef4444'); ?><span>Keluar</span></a></nav></div>
    </aside>
    <section class="guru-content materi-content">
        <div class="games-switcher">
            <div class="games-switcher-tabs">
                <a class="games-switcher-tab" href="guru_games.php"><?= chemnama_icon('beaker', '#d8cde9'); ?><span>Simulasi</span><span class="games-switcher-count">(<?= (int) $simulationCount; ?>)</span></a>
                <a class="games-switcher-tab is-active" href="guru_tarigaris.php"><?= chemnama_icon('stack', '#ffffff'); ?><span>ChemMatch</span><span class="games-switcher-count">(<?= (int) $matchCount; ?>)</span></a>
                <a class="games-switcher-tab" href="guru_carikata.php"><?= chemnama_icon('search', '#d8cde9'); ?><span>Cari Kata</span><span class="games-switcher-count">(<?= (int) $wordCount; ?>)</span></a>
            </div>
        </div>

        <div class="guru-headline-row"><div><h1>Tarik Garis</h1><p>Kelola pasangan istilah dan jawaban untuk siswa.</p></div><div class="guru-head-actions"><a class="btn btn-primary materi-btn" href="guru_tarigaris.php?edit=0">+ Tambah</a></div></div>
        <?php if ($flash): ?><div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?= chemnama_e((string) $flash['message']); ?></div><?php endif; ?>
        <?php if ($errors): ?><div class="alert alert-error"><?= chemnama_e(implode(' ', $errors)); ?></div><?php endif; ?>
        <article class="guru-panel materi-form-panel"><h3><?= $editId > 0 ? 'Edit Tarik Garis' : 'Tambah Tarik Garis'; ?></h3><form method="post" class="materi-form-grid"><input type="hidden" name="action" value="save_match"><input type="hidden" name="match_id" value="<?= chemnama_e((string) $formData['match_id']); ?>"><label><span>Modul</span><select name="module_id" required><?php foreach ($modules as $module): ?><option value="<?= chemnama_e((string) $module['id']); ?>" <?= (int) $formData['module_id'] === (int) $module['id'] ? 'selected' : ''; ?>><?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?></option><?php endforeach; ?></select></label><label><span>Kata / Istilah</span><input type="text" name="left_term" value="<?= chemnama_e($formData['left_term']); ?>" placeholder="Contoh: NaCl" required></label><label><span>Pasangan Jawaban</span><input type="text" name="right_term" value="<?= chemnama_e($formData['right_term']); ?>" placeholder="Contoh: Natrium Klorida" required></label><label class="span-2"><span>Hint</span><input type="text" name="hint" value="<?= chemnama_e($formData['hint']); ?>" placeholder="Petunjuk singkat"></label><label class="span-2" style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_published" <?= (int) $formData['is_published'] === 1 ? 'checked' : ''; ?>><span>Publikasikan ke siswa</span></label><div class="form-action-row span-2"><button class="btn btn-primary" type="submit"><?= chemnama_icon('upload', '#ffffff'); ?> Simpan</button><a class="btn btn-ghost" href="guru_tarigaris.php">Batal</a></div></form></article>
        <div class="materi-list"><?php foreach ($modules as $module): $moduleId = (int) $module['id']; $moduleGames = $gamesByModule[$moduleId] ?? []; if (!$moduleGames) continue; ?><article class="guru-panel materi-item"><div class="materi-item-head"><div class="materi-chip-group"><span class="materi-chip" style="color: <?= chemnama_e((string) $module['accent']); ?>; background: <?= chemnama_e((string) $module['accent']); ?>15;"><?= chemnama_icon((string) $module['icon'], (string) $module['accent']); ?><?= chemnama_e((string) $module['badge']); ?></span><span class="materi-module-label"><?= chemnama_e((string) $module['title']); ?></span></div></div><?php foreach ($moduleGames as $game): ?><div class="guru-panel materi-item" style="margin-top:14px;"><div class="materi-item-head"><div class="materi-chip-group"><span class="materi-chip" style="color:#14b8a6;background:#14b8a615;">Tarik Garis</span><span class="materi-module-label">Dibaca <?= (int) ($game['read_count'] ?? 0); ?>/<?= (int) $totalClassStudents; ?></span></div><div class="materi-actions"><a class="icon-action" href="guru_tarigaris.php?edit=<?= chemnama_e((string) $game['id']); ?>"><?= chemnama_icon('edit', '#2563eb'); ?></a><form method="post" onsubmit="return confirm('Hapus item ini?')"><input type="hidden" name="action" value="delete_match"><input type="hidden" name="match_id" value="<?= chemnama_e((string) $game['id']); ?>"><button class="icon-action danger" type="submit"><?= chemnama_icon('trash', '#ef4444'); ?></button></form></div></div><h3><?= chemnama_e((string) $game['left_term']); ?> ↔ <?= chemnama_e((string) $game['right_term']); ?></h3><p><?= chemnama_e((string) ($game['hint'] ?? '')); ?></p><small><?= chemnama_e((string) $game['teacher_name']); ?> · <?= chemnama_e((string) $game['created_at']); ?></small></div><?php endforeach; ?></article><?php endforeach; ?></div>
    </section>
</main>
</body>
</html>

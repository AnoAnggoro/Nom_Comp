<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('siswa');

$user = chemnama_current_user();
$userId = (int) ($user['id'] ?? 0);
$classStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$classStmt->execute(['user_id' => $userId]);
$studentClass = (string) ($classStmt->fetchColumn() ?: 'X IPA 1');

chemnama_ensure_simulation_reads_table($pdo);
chemnama_ensure_chem_match_reads_table($pdo);
chemnama_ensure_word_search_reads_table($pdo);

$modules = $pdo->query('SELECT id, badge, title, accent, icon FROM modules ORDER BY sort_order, id')->fetchAll();

$simulationCount = (int) $pdo->query('SELECT COUNT(*) FROM simulations WHERE is_published = 1')->fetchColumn();
$matchCount = (int) $pdo->query('SELECT COUNT(*) FROM chem_match_games WHERE is_published = 1')->fetchColumn();
$wordCount = (int) $pdo->query('SELECT COUNT(*) FROM word_search_games WHERE is_published = 1')->fetchColumn();

$siswaMenus = [
   ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Materi', 'icon' => 'file', 'href' => 'siswa_materi.php', 'active' => false],
    ['label' => 'Games', 'icon' => 'beaker', 'href' => 'siswa_games.php', 'active' => true],
    ['label' => 'Kuis PG', 'icon' => 'stack', 'href' => 'siswa_quiz.php', 'active' => false],
    ['label' => 'Exercise', 'icon' => 'edit', 'href' => 'siswa_essay.php', 'active' => false],
    ['label' => 'Forum Diskusi', 'icon' => 'chat', 'href' => 'siswa_forum.php', 'active' => false],
    ['label' => 'Profil', 'icon' => 'user', 'href' => 'siswa_profil.php', 'active' => false],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Games - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
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
                <a class="guru-menu-item" href="siswa_materi.php"><?= chemnama_icon('file', '#0f9d58'); ?><span>Materi</span></a>
                <!-- <a class="guru-menu-item is-active" href="siswa_games.php"><?= chemnama_icon('beaker', '#6b7280'); ?><span>Games</span></a> -->
                <!-- <a class="guru-menu-item" href="siswa_quiz.php"><?= chemnama_icon('stack', '#6b7280'); ?><span>Kuis PG</span></a> -->
                <a class="guru-menu-item" href="siswa_essay.php"><?= chemnama_icon('edit', '#6b7280'); ?><span>Exercise</span></a>
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
        <div class="guru-headline-row">
            <div>
                <h1>Games Belajar</h1>
                <p>Pilih game yang ingin kamu mainkan.</p>
            </div>
        </div>

        <div class="guru-profil-stats">
            <a class="profil-stat-card" href="siswa_simulasi.php"><strong><?= $simulationCount; ?></strong><p>Simulasi</p></a>
            <a class="profil-stat-card" href="siswa_games.php#match"><strong><?= $matchCount; ?></strong><p>Tarik Garis</p></a>
            <a class="profil-stat-card" href="siswa_games.php#word"><strong><?= $wordCount; ?></strong><p>Cari Kata</p></a>
            <a class="profil-stat-card" href="siswa_profil.php"><strong><?= count($modules); ?></strong><p>Modul</p></a>
        </div>

        <div class="guru-profil-grid" style="margin-top: 16px;">
            <section class="profil-section" id="simulasi">
                <h3><?= chemnama_icon('beaker', '#14b8a6'); ?> Simulasi</h3>
                <p>Simulasi reaksi untuk mempelajari pembentukan senyawa.</p>
                <div class="form-action-row"><a class="btn btn-primary" href="siswa_simulasi.php">Buka Simulasi</a></div>
            </section>
            <section class="profil-section" id="match">
                <h3><?= chemnama_icon('stack', '#8b5cf6'); ?> Tarik Garis</h3>
                <p>Pasangkan istilah kimia dengan jawabannya.</p>
                <div class="form-action-row"><a class="btn btn-primary" href="siswa_tarigaris.php">Main Tarik Garis</a></div>
            </section>
            <section class="profil-section" id="word">
                <h3><?= chemnama_icon('search', '#f59e0b'); ?> Cari Kata</h3>
                <p>Cari kata target dari petunjuk yang diberikan.</p>
                <div class="form-action-row"><a class="btn btn-primary" href="siswa_carikata.php">Main Cari Kata</a></div>
            </section>
        </div>
    </section>
</main>
</body>
</html>

<?php
require_once __DIR__ . '/config/bootstrap.php';

if (chemnama_current_user()) {
    header('Location: dashboard.php');
    exit;
}

$home = $siteData;

$activeStudentCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'siswa'")->fetchColumn();
$moduleCoreCount = (int) $pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn();

$avgMaterialCountStmt = $pdo->query(
    'SELECT COALESCE(AVG(sp.material_count), 0)
     FROM student_progress sp
     JOIN users u ON u.id = sp.user_id
     WHERE u.role = "siswa"'
);
$avgMaterialCount = (float) $avgMaterialCountStmt->fetchColumn();
$completionPercent = $moduleCoreCount > 0 ? (int) round(max(0, min(100, ($avgMaterialCount / $moduleCoreCount) * 100))) : 0;

$heroStudentsStmt = $pdo->query(
    'SELECT u.name, u.avatar_color, u.avatar_path
     FROM users u
     WHERE u.role = "siswa"
     ORDER BY u.id DESC
     LIMIT 3'
);
$heroStudents = $heroStudentsStmt->fetchAll();

$animModules = $home['modules'];

$homeStatsRealtime = [
    ['label' => 'Siswa Aktif', 'value' => number_format($activeStudentCount, 0, ',', '.') . '+', 'subtitle' => 'Telah bergabung'],
    ['label' => 'Tingkat Selesai', 'value' => $completionPercent . '%', 'subtitle' => 'Materi dibuka rutin'],
    ['label' => 'Modul Inti', 'value' => (string) $moduleCoreCount, 'subtitle' => 'Tata nama senyawa'],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nom Comp - LMS Tata Nama Senyawa</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="home-page">
<div class="ambient ambient-one"></div>
<div class="ambient ambient-two"></div>
<header class="topbar">
    <div class="container nav-shell">
        <a class="brand" href="index.php">
            <?= chemnama_icon('brand', '#0f9d58'); ?>
            <span>Nom Comp</span>
        </a>
        <nav class="nav-links">
            <a href="#beranda">Beranda</a>
            <a href="#fitur">Fitur</a>
            <a href="#materi">Materi</a>
        </nav>
        <div class="nav-actions">
            <a class="btn btn-ghost" href="login.php?role=guru">Masuk</a>
            <a class="btn btn-primary" href="register.php">Daftar</a>
        </div>
    </div>
</header>

<main>
    <section class="hero section-pad" id="beranda">
        <div class="container hero-grid">
            <div class="hero-copy">
                <div class="eyebrow">Platform LMS Kimia</div>
                <h1>Kuasai Tata Nama Senyawa dengan Mudah</h1>
                <p>Sistem manajemen pembelajaran interaktif untuk guru dan siswa. Guru dapat mengunggah materi, siswa belajar dan berlatih kapan saja dari perangkat apa pun.</p>
                <div class="hero-actions">
                    <a class="btn btn-primary btn-lg" href="login.php?role=siswa">Mulai Belajar</a>
                    <a class="btn btn-secondary btn-lg" href="#materi">Pelajari Lebih</a>
                </div>
                <div class="student-stack">
                    <div class="stack-avatars" aria-hidden="true">
                        <?php if (!empty($heroStudents)): ?>
                            <?php foreach ($heroStudents as $stackStudent): ?>
                                <span class="avatar" style="background: <?= chemnama_e((string) ($stackStudent['avatar_color'] ?? '#94a3b8')); ?>;">
                                    <?php if (!empty($stackStudent['avatar_path'])): ?>
                                        <img src="<?= chemnama_e((string) $stackStudent['avatar_path']); ?>" alt="Foto siswa">
                                    <?php else: ?>
                                        <span class="avatar-initial"><?= chemnama_e(strtoupper(substr((string) ($stackStudent['name'] ?? 'Siswa'), 0, 1))); ?></span>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="avatar avatar-a"></span>
                            <span class="avatar avatar-b"></span>
                            <span class="avatar avatar-c"></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <strong><?= chemnama_e((string) $homeStatsRealtime[0]['value']); ?> Siswa Aktif</strong>
                        <p>Telah bergabung</p>
                    </div>
                </div>
                <div class="stat-row">
                    <?php foreach ($homeStatsRealtime as $stat): ?>
                        <article class="stat-pill">
                            <span><?= chemnama_e((string) $stat['label']); ?></span>
                            <strong><?= chemnama_e((string) $stat['value']); ?></strong>
                            <small><?= chemnama_e((string) $stat['subtitle']); ?></small>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>

            <aside class="hero-panel">
                <div class="panel-head">
                    <span class="panel-title"><?= chemnama_icon('file', '#0f9d58'); ?> Upload Terbaru</span>
                    <span class="panel-badge">62%</span>
                </div>
                <div class="panel-list">
                    <?php foreach ($home['hero_updates'] as $update): ?>
                        <div class="panel-item">
                            <div class="panel-icon" style="color: <?= chemnama_e($update['accent']); ?>"><?= chemnama_icon($update['icon'], $update['accent']); ?></div>
                            <div>
                                <strong><?= chemnama_e($update['title']); ?></strong>
                                <p><?= chemnama_e($update['owner']); ?> - <?= chemnama_e($update['meta']); ?></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="progress-block">
                    <div class="progress-head">
                        <span>Progress Kelas</span>
                        <strong>62%</strong>
                    </div>
                    <div class="progress-bar"><span></span></div>
                </div>
            </aside>
        </div>
    </section>

   <!-- <section class="section-pad section-muted" id="animasi">
        <div class="container section-heading">
            <h2>Visualisasi Kimia</h2>
        </div>
        <div class="container feature-network-shell">
            <svg class="feature-network-lines" viewBox="0 0 1200 520" preserveAspectRatio="none" aria-hidden="true">
                <line x1="200" y1="140" x2="600" y2="140"></line>
                <line x1="600" y1="140" x2="1000" y2="140"></line>
                <line x1="200" y1="380" x2="600" y2="380"></line>
                <line x1="600" y1="380" x2="1000" y2="380"></line>
                <line x1="200" y1="140" x2="200" y2="380"></line>
                <line x1="600" y1="140" x2="600" y2="380"></line>
                <line x1="1000" y1="140" x2="1000" y2="380"></line>
            </svg>
            <div class="card-grid three-up feature-network-grid">
            <?php foreach ($animModules as $module): ?>
                <article class="feature-card feature-network-card">
                    <div class="feature-art" style="--module-accent: <?= chemnama_e($module['accent']); ?>; background: linear-gradient(160deg, <?= chemnama_e($module['accent']); ?>18, #ffffff 75%); color: <?= chemnama_e($module['accent']); ?>;">
                        <div class="feature-chem-scene" aria-hidden="true">
                            <span class="chem-orbit orbit-a"></span>
                            <span class="chem-orbit orbit-b"></span>
                            <span class="chem-orbit orbit-c"></span>
                            <span class="chem-particle particle-1"></span>
                            <span class="chem-particle particle-2"></span>
                            <span class="chem-particle particle-3"></span>
                        </div>
                        <?= chemnama_icon($module['icon'], $module['accent']); ?>
                    </div>
                    <div class="feature-body">
                        <span class="module-badge" style="color: <?= chemnama_e($module['accent']); ?>; background: <?= chemnama_e($module['accent']); ?>14;"><?= chemnama_e($module['badge']); ?></span>
                        <h3><?= chemnama_e($module['title']); ?></h3>
                        <p><?= chemnama_e($module['description']); ?></p>
                        <a href="login.php?role=siswa">Pelajari modul ini</a>
                    </div>
                </article>
            <?php endforeach; ?>
            </div>
        </div>
    </section>  -->

    <section class="section-pad" id="fitur">
        <div class="container section-heading">
            <h2>Kenapa Kamu Harus Bisa Tata Senyawa?</h2>
        </div>
        <div class="container card-grid case-grid">
            <?php foreach ($home['use_cases'] as $case): ?>
                <article class="case-card">
                    <div class="case-top" style="background: linear-gradient(160deg, <?= chemnama_e($case['accent']); ?>18, #ffffff 70%);">
                        <?= chemnama_icon($case['icon'], $case['accent']); ?>
                    </div>
                    <div class="case-body">
                        <h3><?= chemnama_e($case['title']); ?></h3>
                        <p><?= chemnama_e($case['description']); ?></p>
                        <div class="case-foot" style="color: <?= chemnama_e($case['accent']); ?>;"><?= chemnama_e($case['summary']); ?></div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
       
    </section>

    <section class="section-pad section-muted" id="materi">
        <div class="container section-heading">
            <h2><?= $moduleCoreCount; ?> Modul Tata Nama Senyawa</h2>
        </div>
        <div class="container card-grid module-grid">
            <?php foreach ($home['modules'] as $module): ?>
                <article class="module-card">
                    <div class="module-icon" style="color: <?= chemnama_e($module['accent']); ?>; background: <?= chemnama_e($module['accent']); ?>12;">
                        <?= chemnama_icon($module['icon'], $module['accent']); ?>
                    </div>
                    <span class="module-badge" style="color: <?= chemnama_e($module['accent']); ?>; background: <?= chemnama_e($module['accent']); ?>14;">
                        <?= chemnama_e($module['badge']); ?>
                    </span>
                    <h3><?= chemnama_e($module['title']); ?></h3>
                    <p><?= chemnama_e($module['description']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>

<footer class="site-footer">
    <div class="container footer-shell">
        <div class="brand footer-brand">
            <?= chemnama_icon('brand', '#0f9d58'); ?>
            <span>Nom Comp</span>
        </div>
        <p>2025 Nom Comp - LMS Tata Nama Senyawa Kimia</p>
    </div>
</footer>

<script src="assets/js/app.js"></script>
</body>
</html>

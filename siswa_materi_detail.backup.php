<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('siswa');

$user = chemnama_current_user();
$userId = (int) ($user['id'] ?? 0);
$materialId = (int) ($_GET['id'] ?? 0);
$selectedSimulationId = (int) ($_GET['sim_id'] ?? 0);
$shouldOpenSimulation = (int) ($_GET['show_simulation'] ?? 0) === 1;

$classStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$classStmt->execute(['user_id' => $userId]);
$studentClass = (string) ($classStmt->fetchColumn() ?: 'X IPA 1');

chemnama_ensure_material_reads_table($pdo);

function chemnama_extract_youtube_embed_url(?string $url): ?string
{
    if (!$url) {
        return null;
    }

    $trimmed = trim($url);
    if ($trimmed === '') {
        return null;
    }

    $parts = parse_url($trimmed);
    if (!is_array($parts)) {
        return null;
    }

    $host = strtolower((string) ($parts['host'] ?? ''));

    if (strpos($host, 'youtu.be') !== false) {
        $videoId = trim((string) ($parts['path'] ?? ''), '/');
        return $videoId !== '' ? 'https://www.youtube.com/embed/' . rawurlencode($videoId) : null;
    }

    if (strpos($host, 'youtube.com') !== false) {
        $path = (string) ($parts['path'] ?? '');

        if ($path === '/watch') {
            parse_str((string) ($parts['query'] ?? ''), $query);
            if (!empty($query['v'])) {
                return 'https://www.youtube.com/embed/' . rawurlencode((string) $query['v']);
            }
        }

        if (strpos($path, '/embed/') === 0) {
            return $trimmed;
        }

        if (strpos($path, '/shorts/') === 0) {
            $videoId = trim(substr($path, 8), '/');
            return $videoId !== '' ? 'https://www.youtube.com/embed/' . rawurlencode($videoId) : null;
        }
    }

    return null;
}

$materialStmt = $pdo->prepare(
    'SELECT m.*, mo.badge AS module_badge, mo.title AS module_title, mo.accent AS module_accent, u.name AS teacher_name
     FROM materials m
     JOIN modules mo ON mo.id = m.module_id
     JOIN users u ON u.id = m.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
     WHERE m.id = :id
       AND m.is_published = 1
       AND u.role = "guru"
             AND INSTR(
                 CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
                 CONCAT(CHAR(44), :kelas, CHAR(44))
             ) > 0
     LIMIT 1'
);
$materialStmt->execute([
    'id' => $materialId,
    'kelas' => $studentClass,
]);
$material = $materialStmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'mark_read') {
    if ($material && chemnama_mark_material_as_read($pdo, $materialId, $userId)) {
        chemnama_flash('Materi ditandai sudah dibaca.', 'success');
    } else {
        chemnama_flash('Materi tidak bisa ditandai dibaca.', 'error');
    }
    header('Location: siswa_materi_detail.php?id=' . $materialId);
    exit;
}

$isMaterialRead = $material ? chemnama_is_material_read($pdo, $materialId, $userId) : false;
$flash = chemnama_flash();

// Get simulations for this module
$simulations = [];
if ($material) {
    $simStmt = $pdo->prepare(
        'SELECT id, product_name, product_formula, atom_first, atom_second, bond_type, molecule_layout,
                reaction_energy, reaction_type, description_about, found_in, daily_usage
         FROM simulations
         WHERE module_id = :module_id
         AND is_published = 1
         LIMIT 3'
    );
    $simStmt->execute(['module_id' => $material['module_id']]);
    $simulations = $simStmt->fetchAll();
}

$primarySimulation = $simulations[0] ?? null;
if ($selectedSimulationId > 0 && count($simulations) > 0) {
    foreach ($simulations as $simRow) {
        if ((int) ($simRow['id'] ?? 0) === $selectedSimulationId) {
            $primarySimulation = $simRow;
            break;
        }
    }
}

if (!$material) {
    http_response_code(404);
}

$typeLabelMap = [
    'teks' => 'Teks/Catatan',
    'pdf' => 'Dokumen PDF',
    'presentasi' => 'Presentasi',
    'video' => 'Video',
    'animasi' => 'Animasi',
];

$typeColorMap = [
    'teks' => '#0f9d58',
    'pdf' => '#ef4444',
    'presentasi' => '#d97706',
    'video' => '#0ea5e9',
    'animasi' => '#ec4899',
];

$youtubeEmbedUrl = $material ? chemnama_extract_youtube_embed_url((string) ($material['youtube_url'] ?? '')) : null;
$type = $material ? (string) $material['type'] : 'teks';
$typeLabel = $typeLabelMap[$type] ?? ucfirst($type);
$typeColor = $typeColorMap[$type] ?? '#64748b';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Detail Materi - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .inline-sim-panel {
            display: none;
            margin-top: 14px;
            gap: 14px;
        }

        .inline-sim-panel.is-open {
            display: grid;
            animation: inlineSimFadeIn 0.28s ease;
        }

        @keyframes inlineSimFadeIn {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .inline-sim-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .inline-sim-header h3 {
            margin: 0;
            font-size: 1.2rem;
            letter-spacing: -0.02em;
        }

        .inline-sim-chip {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 999px;
            background: rgba(15, 157, 88, 0.12);
            color: #0f9d58;
            font-size: 12px;
            font-weight: 800;
        }

        .inline-sim-canvas {
            border-radius: 16px;
            border: 1px solid rgba(15, 157, 88, 0.14);
            background:
                linear-gradient(rgba(16, 185, 129, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(16, 185, 129, 0.05) 1px, transparent 1px),
                linear-gradient(180deg, rgba(2, 6, 23, 0.98), rgba(15, 23, 42, 0.96));
            background-size: 38px 38px, 38px 38px, auto;
            padding: 18px;
            display: grid;
            gap: 14px;
        }

        .inline-sim-equation {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            width: fit-content;
            border-radius: 12px;
            padding: 8px 12px;
            background: rgba(255, 255, 255, 0.06);
            color: #e2e8f0;
            font-weight: 800;
        }

        .inline-sim-atoms {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: clamp(20px, 6vw, 70px);
            min-height: 250px;
            position: relative;
        }

        .inline-sim-atom {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 0 26px rgba(15, 157, 88, 0.25);
            transition: all 0.3s ease;
            color: #0f172a;
            font-weight: 800;
        }

        .inline-sim-atom:hover {
            transform: translateY(-2px) scale(1.04);
        }

        .inline-sim-atom.atom-a {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
        }

        .inline-sim-atom.atom-b {
            background: linear-gradient(135deg, #22d3ee, #14b8a6);
        }

        .inline-sim-atom-symbol {
            font-size: 2.6rem;
            line-height: 1;
        }

        .inline-sim-atom-name {
            font-size: 0.82rem;
            margin-top: 4px;
        }

        .inline-sim-plus {
            color: #94a3b8;
            font-size: 2rem;
            font-weight: 900;
        }

        .inline-sim-hint {
            text-align: center;
            color: #94a3b8;
            font-size: 0.92rem;
        }

        .inline-sim-product {
            display: none;
            text-align: center;
            padding: 8px 0;
        }

        .inline-sim-product.is-show {
            display: grid;
            gap: 6px;
            animation: inlineSimFadeIn 0.25s ease;
        }

        .inline-sim-product strong {
            color: #10b981;
            font-size: 2rem;
            line-height: 1;
            letter-spacing: -0.03em;
        }

        .inline-sim-product span {
            color: #cbd5e1;
        }

        .inline-sim-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }

        .inline-sim-stat {
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.06);
            border: 1px solid rgba(148, 163, 184, 0.18);
            padding: 12px;
            display: grid;
            gap: 6px;
        }

        .inline-sim-stat small {
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 11px;
            font-weight: 800;
        }

        .inline-sim-stat strong {
            color: #e2e8f0;
            font-size: 1.02rem;
        }
    </style>
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
                <a class="guru-menu-item is-active" href="siswa_materi.php"><?= chemnama_icon('file', '#0f9d58'); ?><span>Materi Guru</span></a>
                <!-- <a class="guru-menu-item" href="siswa_games.php"><?= chemnama_icon('beaker', '#6b7280'); ?><span>Games</span></a>
                <a class="guru-menu-item" href="siswa_quiz.php"><?= chemnama_icon('stack', '#6b7280'); ?><span>Kuis PG</span></a> -->
                <a class="guru-menu-item" href="siswa_essay.php"><?= chemnama_icon('edit', '#6b7280'); ?><span>Tugas Essay</span></a>
                <a class="guru-menu-item" href="siswa_essay.php#pr-homework"><?= chemnama_icon('task', '#6b7280'); ?><span>PR / Homework</span></a>
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

    <section class="guru-content student-content siswa-materi-page">
        <div class="siswa-materi-head">
            <h1>Materi dari Guru</h1>
            <p>Semua materi dari guru.</p>
            <a class="siswa-materi-back" href="siswa_materi.php"><?= chemnama_icon('switch', '#64748b'); ?> Kembali</a>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= chemnama_e((string) $flash['type']); ?>"><?= chemnama_e((string) $flash['message']); ?></div>
        <?php endif; ?>

        <?php if (!$material): ?>
            <article class="guru-panel siswa-materi-empty">
                <h3>Materi tidak ditemukan</h3>
                <p>Materi ini tidak tersedia untuk kelas Anda.</p>
            </article>
        <?php else: ?>
            <article class="guru-panel siswa-materi-detail">
                <div class="siswa-materi-detail-head">
                    <span class="siswa-module-icon" style="color: <?= chemnama_e($typeColor); ?>; background: <?= chemnama_e($typeColor); ?>14;">
                        <?= chemnama_icon('file', $typeColor); ?>
                    </span>
                    <div>
                        <small><?= chemnama_e((string) $material['module_badge']); ?> · <?= chemnama_e($typeLabel); ?></small>
                        <h2><?= chemnama_e((string) $material['title']); ?></h2>
                        <div class="siswa-materi-meta-bottom">
                            <span><?= chemnama_e((string) $material['teacher_name']); ?></span>
                            <span><?= chemnama_e((string) $material['created_at']); ?></span>
                            <span class="siswa-materi-read-pill <?= $isMaterialRead ? 'is-read' : 'is-unread'; ?>">
                                <?= $isMaterialRead ? 'Sudah Dibaca' : 'Belum Dibaca'; ?>
                            </span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($material['file_name'])): ?>
                    <div class="siswa-materi-file-box">
                        <div>
                            <?= chemnama_icon('clip', $typeColor); ?>
                            <span><?= chemnama_e((string) $material['file_name']); ?></span>
                        </div>
                        <?php if (!empty($material['file_path'])): ?>
                            <a class="btn btn-ghost" href="<?= chemnama_e((string) $material['file_path']); ?>" download>Unduh</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($youtubeEmbedUrl !== null): ?>
                    <div class="siswa-materi-video-wrap">
                        <iframe src="<?= chemnama_e($youtubeEmbedUrl); ?>" title="Video materi" loading="lazy" allowfullscreen></iframe>
                    </div>
                <?php elseif ($type === 'video' && !empty($material['file_path'])): ?>
                    <div class="siswa-materi-video-wrap">
                        <video controls preload="metadata" src="<?= chemnama_e((string) $material['file_path']); ?>"></video>
                    </div>
                <?php endif; ?>

                <?php if (!empty($material['content_text'])): ?>
                    <div class="siswa-materi-content">
                        <?= nl2br(chemnama_e((string) $material['content_text'])); ?>
                    </div>
                <?php else: ?>
                    <p class="siswa-materi-content-empty">Konten teks belum ditambahkan pada materi ini.</p>
                <?php endif; ?>

                <div class="siswa-materi-detail-actions">
                    <form method="post" style="display:inline-flex;">
                        <input type="hidden" name="action" value="mark_read">
                        <?php if ($isMaterialRead): ?>
                            <button class="btn btn-secondary" type="submit"><?= chemnama_icon('check', '#0f9d58'); ?> Sudah Dibaca</button>
                        <?php else: ?>
                            <button class="btn btn-primary" type="submit"><?= chemnama_icon('check', '#ffffff'); ?> Tandai Dibaca</button>
                        <?php endif; ?>
                    </form>
                    <?php if ($primarySimulation !== null): ?>

                    <?php endif; ?>
                    <a class="btn btn-secondary" href="siswa_quiz.php"><?= chemnama_icon('edit', '#d97706'); ?> Kuis <?= chemnama_e((string) $material['module_badge']); ?></a>
                </div>
            </article>

            <?php if ($primarySimulation !== null): ?>
                <?php
                    preg_match('/^([A-Z][a-z]?)/', (string) $primarySimulation['atom_first'], $simAtomMatch1);
                    preg_match('/^([A-Z][a-z]?)/', (string) $primarySimulation['atom_second'], $simAtomMatch2);
                    $simAtomSymbol1 = (string) ($simAtomMatch1[1] ?? substr((string) $primarySimulation['atom_first'], 0, 1));
                    $simAtomSymbol2 = (string) ($simAtomMatch2[1] ?? substr((string) $primarySimulation['atom_second'], 0, 1));
                ?>
                <article class="guru-panel inline-sim-panel" id="inlineSimulationPanel" data-sim-panel>
                    <div class="inline-sim-header">
                        <h3><?= chemnama_icon('beaker', '#14b8a6'); ?> Simulasi Reaksi</h3>
                        <span class="inline-sim-chip"><?= chemnama_e((string) $primarySimulation['reaction_type']); ?></span>
                    </div>

                    <div class="inline-sim-canvas">
                        <div class="inline-sim-equation">
                            <span><?= chemnama_e((string) $primarySimulation['atom_first']); ?></span>
                            <span>+</span>
                            <span><?= chemnama_e((string) $primarySimulation['atom_second']); ?></span>
                            <span>→</span>
                            <span><?= chemnama_e((string) $primarySimulation['product_formula']); ?></span>
                        </div>

                        <div class="inline-sim-atoms">
                            <div class="inline-sim-atom atom-a" data-sim-atom="a">
                                <div class="inline-sim-atom-symbol"><?= chemnama_e($simAtomSymbol1); ?></div>
                                <div class="inline-sim-atom-name"><?= chemnama_e((string) $primarySimulation['atom_first']); ?></div>
                            </div>
                            <div class="inline-sim-plus">+</div>
                            <div class="inline-sim-atom atom-b" data-sim-atom="b">
                                <div class="inline-sim-atom-symbol"><?= chemnama_e($simAtomSymbol2); ?></div>
                                <div class="inline-sim-atom-name"><?= chemnama_e((string) $primarySimulation['atom_second']); ?></div>
                            </div>
                        </div>

                        <div class="inline-sim-hint" data-sim-hint>Klik kedua atom untuk memulai simulasi.</div>

                        <div class="inline-sim-product" data-sim-product>
                            <strong><?= chemnama_e((string) $primarySimulation['product_formula']); ?></strong>
                            <span><?= chemnama_e((string) $primarySimulation['product_name']); ?></span>
                        </div>

                        <div class="inline-sim-stats">
                            <div class="inline-sim-stat">
                                <small>Ikatan</small>
                                <strong><?= chemnama_e((string) $primarySimulation['bond_type']); ?></strong>
                            </div>
                            <div class="inline-sim-stat">
                                <small>Layout Molekul</small>
                                <strong><?= chemnama_e((string) $primarySimulation['molecule_layout']); ?></strong>
                            </div>
                            <div class="inline-sim-stat">
                                <small>Energi</small>
                                <strong><?= chemnama_e((string) $primarySimulation['reaction_energy']); ?></strong>
                            </div>
                        </div>
                    </div>
                </article>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
<script src="assets/js/app.js?v=20260407"></script>
<script>
    (function () {
        const toggleButton = document.getElementById('toggleInlineSimulation');
        const panel = document.querySelector('[data-sim-panel]');
        const autoOpen = <?= $shouldOpenSimulation ? 'true' : 'false'; ?>;

        if (!toggleButton || !panel) {
            return;
        }

        toggleButton.addEventListener('click', () => {
            const isOpen = panel.classList.contains('is-open');
            panel.classList.toggle('is-open', !isOpen);
            toggleButton.innerHTML = !isOpen
                ? '<?= str_replace("'", "\\'", (string) chemnama_icon('switch', '#14b8a6')); ?> Tutup Simulasi'
                : '<?= str_replace("'", "\\'", (string) chemnama_icon('beaker', '#14b8a6')); ?> Lihat Simulasi';

            if (!isOpen) {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });

        const atomA = panel.querySelector('[data-sim-atom="a"]');
        const atomB = panel.querySelector('[data-sim-atom="b"]');
        const hint = panel.querySelector('[data-sim-hint]');
        const product = panel.querySelector('[data-sim-product]');

        if (!atomA || !atomB || !hint || !product) {
            return;
        }

        let atomAClicked = false;
        let atomBClicked = false;

        function revealProductIfReady() {
            if (!(atomAClicked && atomBClicked)) {
                return;
            }

            atomA.style.opacity = '0.35';
            atomB.style.opacity = '0.35';
            atomA.style.transform = 'scale(0.88)';
            atomB.style.transform = 'scale(0.88)';
            hint.textContent = 'Reaksi berlangsung...';

            setTimeout(() => {
                product.classList.add('is-show');
                hint.textContent = 'Produk berhasil terbentuk.';
            }, 350);
        }

        atomA.addEventListener('click', () => {
            atomAClicked = true;
            revealProductIfReady();
        });

        atomB.addEventListener('click', () => {
            atomBClicked = true;
            revealProductIfReady();
        });

        if (autoOpen && !panel.classList.contains('is-open')) {
            toggleButton.click();
        }
    })();
</script>
</body>
</html>

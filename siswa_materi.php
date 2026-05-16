<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('siswa');

$user = chemnama_current_user();
$userId = (int) ($user['id'] ?? 0);

$classStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$classStmt->execute(['user_id' => $userId]);
$studentClass = (string) ($classStmt->fetchColumn() ?: 'X IPA 1');

if (isset($_GET['completed'])) {
    chemnama_flash('Kamu sudah menyelesaikan step terakhir. Daftar materi dibuka kembali.', 'success');
    header('Location: siswa_materi.php');
    exit;
}

$flash = chemnama_flash();

$introStmt = $pdo->prepare(
    'SELECT id, title, content, updated_at
     FROM student_intros
     WHERE class_name = :class_name
     ORDER BY updated_at DESC, id DESC
     LIMIT 1'
);
$introStmt->execute(['class_name' => $studentClass]);
$studentIntro = $introStmt->fetch() ?: null;

chemnama_ensure_material_reads_table($pdo);
chemnama_ensure_simulation_reads_table($pdo);

$moduleRows = $pdo->query('SELECT id, badge, title, accent, icon FROM modules ORDER BY sort_order, id')->fetchAll();
$moduleMap = [];
foreach ($moduleRows as $module) {
    $moduleMap[(int) $module['id']] = $module;
}

$materialsStmt = $pdo->prepare(
    'SELECT m.*, mo.badge AS module_badge, mo.title AS module_title, mo.accent AS module_accent, u.name AS teacher_name,
            CASE WHEN mr.id IS NULL THEN 0 ELSE 1 END AS is_read
     FROM materials m
     JOIN modules mo ON mo.id = m.module_id
     JOIN users u ON u.id = m.created_by
     LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
     LEFT JOIN material_reads mr ON mr.material_id = m.id AND mr.student_id = :student_id
     WHERE m.is_published = 1
       AND u.role = "guru"
             AND INSTR(
                 CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
                 CONCAT(CHAR(44), :kelas, CHAR(44))
             ) > 0
     ORDER BY mo.sort_order ASC, m.created_at DESC, m.id DESC'
);
$materialsStmt->execute([
    'student_id' => $userId,
    'kelas' => $studentClass,
]);
$materials = $materialsStmt->fetchAll();

$materialsByModule = [];
foreach ($materials as $material) {
    $moduleId = (int) $material['module_id'];
    if (!isset($materialsByModule[$moduleId])) {
        $materialsByModule[$moduleId] = [];
    }
    $materialsByModule[$moduleId][] = $material;
}

// Get simulations
$simulationsStmt = $pdo->prepare(
    'SELECT s.*, mo.badge AS module_badge, mo.title AS module_title,
            CASE WHEN sr.id IS NULL THEN 0 ELSE 1 END AS is_read
     FROM simulations s
     JOIN modules mo ON mo.id = s.module_id
     LEFT JOIN simulation_reads sr ON sr.simulation_id = s.id AND sr.student_id = :student_id
     WHERE s.is_published = 1
     ORDER BY mo.sort_order ASC, s.created_at DESC'
);
$simulationsStmt->execute(['student_id' => $userId]);
$simulations = $simulationsStmt->fetchAll();

$simulationsByModule = [];
foreach ($simulations as $simulation) {
    $moduleId = (int) $simulation['module_id'];
    if (!isset($simulationsByModule[$moduleId])) {
        $simulationsByModule[$moduleId] = [];
    }
    $simulationsByModule[$moduleId][] = $simulation;
}

$moduleItemsByModule = [];
$moduleSummaryByModule = [];
foreach ($moduleRows as $module) {
    $moduleId = (int) $module['id'];
    $moduleItemsByModule[$moduleId] = [];
    $moduleSummaryByModule[$moduleId] = [
        'count' => 0,
        'first_href' => null,
        'first_kind' => null,
        'first_label' => null,
    ];

    foreach ($materialsByModule[$moduleId] ?? [] as $material) {
        $moduleItemsByModule[$moduleId][] = [
            'kind' => 'material',
            'sort_key' => strtotime((string) ($material['created_at'] ?? 'now')) ?: 0,
            'payload' => $material,
        ];
    }

    foreach ($simulationsByModule[$moduleId] ?? [] as $simulation) {
        $moduleItemsByModule[$moduleId][] = [
            'kind' => 'simulation',
            'sort_key' => strtotime((string) ($simulation['created_at'] ?? 'now')) ?: 0,
            'payload' => $simulation,
        ];
    }

    usort($moduleItemsByModule[$moduleId], static function (array $left, array $right): int {
        $rightSort = (int) ($right['sort_key'] ?? 0);
        $leftSort = (int) ($left['sort_key'] ?? 0);

        if ($rightSort === $leftSort) {
            return strcmp((string) ($right['kind'] ?? ''), (string) ($left['kind'] ?? ''));
        }

        return $rightSort <=> $leftSort;
    });

    $moduleSummaryByModule[$moduleId]['count'] = count($moduleItemsByModule[$moduleId]);
    $firstItem = $moduleItemsByModule[$moduleId][0] ?? null;
    if ($firstItem) {
        $moduleSummaryByModule[$moduleId]['first_kind'] = (string) ($firstItem['kind'] ?? '');
        if (($firstItem['kind'] ?? '') === 'simulation') {
            $simulation = $firstItem['payload'];
            $targetMaterialId = 0;
            if (!empty($materialsByModule[$moduleId])) {
                foreach ($materialsByModule[$moduleId] as $mat) {
                    if (trim((string) ($mat['type'] ?? '')) !== 'animasi') {
                        $targetMaterialId = (int) $mat['id'];
                        break;
                    }
                }
                if ($targetMaterialId === 0) {
                    $targetMaterialId = (int) ($materialsByModule[$moduleId][0]['id'] ?? 0);
                }
            }
            $moduleSummaryByModule[$moduleId]['first_href'] = 'siswa_simulasi.php?id=' . (int) $simulation['id'] . '&module_id=' . $moduleId;
            $moduleSummaryByModule[$moduleId]['first_label'] = 'Simulasi Reaksi';
        } else {
            $material = $firstItem['payload'];
            $moduleSummaryByModule[$moduleId]['first_href'] = 'siswa_materi_detail.php?id=' . $moduleId;
            $moduleSummaryByModule[$moduleId]['first_label'] = (string) ($material['title'] ?? 'Materi');
        }
    }
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
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Materi dari Guru - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .siswa-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            max-width: 360px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid rgba(16, 185, 129, 0.35);
            background: rgba(6, 78, 59, 0.95);
            color: #ecfdf5;
            box-shadow: 0 16px 32px rgba(6, 78, 59, 0.35);
            font-size: 0.92rem;
            line-height: 1.45;
            z-index: 1200;
            opacity: 0;
            transform: translateY(-8px);
            transition: opacity 0.28s ease, transform 0.28s ease;
            pointer-events: none;
        }

        .siswa-toast.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .siswa-toast.is-error {
            border-color: rgba(248, 113, 113, 0.38);
            background: rgba(127, 29, 29, 0.96);
            color: #fee2e2;
            box-shadow: 0 16px 32px rgba(127, 29, 29, 0.32);
        }

        @media (max-width: 768px) {
            .siswa-toast {
                left: 14px;
                right: 14px;
                top: 14px;
                max-width: none;
            }
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
                <a class="guru-menu-item is-active" href="siswa_materi.php"><?= chemnama_icon('file', '#0f9d58'); ?><span>Materi</span></a>
                <!-- <a class="guru-menu-item" href="siswa_games.php"><?= chemnama_icon('beaker', '#6b7280'); ?><span>Games</span></a> -->
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

    <?php if ($flash): ?>
        <div class="siswa-toast <?= chemnama_e($flash['type'] === 'success' ? 'is-success' : 'is-error'); ?>" data-siswa-toast role="status" aria-live="polite">
            <?= chemnama_e((string) $flash['message']); ?>
        </div>
    <?php endif; ?>

    <section class="guru-content student-content siswa-materi-page">
        <div class="siswa-materi-head">
            <h1>Materi dari Guru</h1>
            <p>Semua materi dari guru.</p>
        </div>

        <?php if ($studentIntro): ?>
            <article class="siswa-intro-panel">
                <div class="siswa-intro-head">
                    <div class="siswa-intro-icon"><?= chemnama_icon('book', '#a855f7'); ?></div>
                    <div>
                        <small>Intro Siswa</small>
                        <h2><?= chemnama_e((string) $studentIntro['title']); ?></h2>
                    </div>
                </div>

                <div class="siswa-intro-content">
                    <?= nl2br(strip_tags((string) $studentIntro['content'], '<strong><em><b><i><br><p><ul><ol><li>')); ?>
                </div>

                <div class="siswa-intro-chips">
                    <div class="siswa-intro-chip">
                        <strong>IUPAC</strong>
                        <small>Badan standar internasional</small>
                    </div>
                    <div class="siswa-intro-chip">
                        <strong><?= count($moduleRows); ?> Modul</strong>
                        <small>Materi lengkap</small>
                    </div>
                    <div class="siswa-intro-chip">
                        <strong>Interaktif</strong>
                        <small>Video, simulasi, kuis</small>
                    </div>
                </div>

                <div class="siswa-intro-footer">
                    <span>Terakhir diperbarui</span>
                    <span><?= chemnama_e((string) $studentIntro['updated_at']); ?></span>
                </div>
            </article>
        <?php endif; ?>

        <?php if (count($materials) === 0 && count($simulations) === 0): ?>
            <article class="guru-panel siswa-materi-empty">
                <h3>Belum ada materi</h3>
                <p>Guru untuk kelas ini belum mengunggah materi.</p>
            </article>
        <?php else: ?>
            <div class="siswa-module-grid">
                <?php foreach ($moduleRows as $module): ?>
                    <?php $moduleId = (int) $module['id']; ?>
                    <?php if (empty($moduleItemsByModule[$moduleId])): ?>
                        <?php continue; ?>
                    <?php endif; ?>

                    <?php
                        $summary = $moduleSummaryByModule[$moduleId] ?? [];
                        $moduleCount = (int) ($summary['count'] ?? 0);
                        $moduleHref = (string) ($summary['first_href'] ?? '');
                        $moduleLabel = (string) ($summary['first_label'] ?? 'Mulai');
                    ?>

                    <a class="siswa-module-card" href="<?= chemnama_e('siswa_materi_detail.php?id=' . $moduleId . '&step=1'); ?>">
                        <div class="siswa-module-card-icon" style="color: <?= chemnama_e((string) $module['accent']); ?>; background: <?= chemnama_e((string) $module['accent']); ?>16;">
                            <?= chemnama_icon((string) $module['icon'], (string) $module['accent']); ?>
                        </div>
                        <div class="siswa-module-card-label" style="color: <?= chemnama_e((string) $module['accent']); ?>;">MODUL <?= chemnama_e((string) $module['badge']); ?></div>
                        <h2><?= chemnama_e((string) $module['title']); ?></h2>
                        <p><?= chemnama_e((string) ($module['description'] ?? '')); ?></p>
                        <div class="siswa-module-card-footer">
                            <span><?= $moduleCount; ?> materi</span>
                            <span class="siswa-module-card-action" style="color: <?= chemnama_e((string) $module['accent']); ?>;">Mulai →</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </section>
</main>
<script>
    (function () {
        const toast = document.querySelector('[data-siswa-toast]');
        if (!toast) {
            return;
        }

        requestAnimationFrame(function () {
            toast.classList.add('is-visible');
        });

        setTimeout(function () {
            toast.classList.remove('is-visible');
            setTimeout(function () {
                toast.remove();
            }, 300);
        }, 3200);
    })();
</script>
<script src="assets/js/app.js?v=20260407"></script>
</body>
</html>

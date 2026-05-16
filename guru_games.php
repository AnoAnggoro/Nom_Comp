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

chemnama_ensure_simulation_reads_table($pdo);
chemnama_ensure_chem_match_reads_table($pdo);
chemnama_ensure_word_search_reads_table($pdo);

$simulationCount = (int) $pdo->query('SELECT COUNT(*) FROM simulations')->fetchColumn();
$matchCount = (int) $pdo->query('SELECT COUNT(*) FROM chem_match_games')->fetchColumn();
$wordCount = (int) $pdo->query('SELECT COUNT(*) FROM word_search_games')->fetchColumn();

$modules = $pdo->query('SELECT id, badge, title, accent, icon FROM modules ORDER BY sort_order, id')->fetchAll();
$moduleMap = [];
foreach ($modules as $module) {
    $moduleMap[(int) $module['id']] = $module;
}

$errors = [];
$action = (string) ($_POST['action'] ?? '');

if ($action === 'delete_simulation') {
    $simulationId = (int) ($_POST['simulation_id'] ?? 0);
    if ($simulationId > 0) {
        $check = $pdo->prepare('SELECT id FROM simulations WHERE id = :id AND created_by = :created_by LIMIT 1');
        $check->execute(['id' => $simulationId, 'created_by' => $user['id']]);
        $row = $check->fetch();

        if ($row) {
            $delete = $pdo->prepare('DELETE FROM simulations WHERE id = :id');
            $delete->execute(['id' => $simulationId]);
            chemnama_flash('Simulasi berhasil dihapus.', 'success');
        }
    }

    header('Location: guru_games.php');
    exit;
}

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
        header('Location: guru_games.php#chem-match');
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
    header('Location: guru_games.php#chem-match');
    exit;
}

if ($action === 'save_word') {
    $wordId = (int) ($_POST['word_id'] ?? 0);
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $targetWord = trim((string) ($_POST['target_word'] ?? ''));
    $hint = trim((string) ($_POST['hint'] ?? ''));
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    if (!isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul wajib dipilih.';
    }
    if ($targetWord === '' || $hint === '') {
        $errors[] = 'Kata dan hint wajib diisi.';
    }

    if (count($errors) === 0) {
        if ($wordId > 0) {
            $stmt = $pdo->prepare('UPDATE word_search_games SET module_id = :module_id, target_word = :target_word, hint = :hint, is_published = :is_published WHERE id = :id AND created_by = :created_by');
            $stmt->execute([
                'module_id' => $moduleId,
                'target_word' => strtoupper($targetWord),
                'hint' => $hint,
                'is_published' => $isPublished,
                'id' => $wordId,
                'created_by' => $user['id'],
            ]);
            chemnama_flash('Cari kata berhasil diperbarui.', 'success');
        } else {
            $stmt = $pdo->prepare('INSERT INTO word_search_games (module_id, created_by, class_name, target_word, hint, is_published) VALUES (:module_id, :created_by, :class_name, :target_word, :hint, :is_published)');
            $stmt->execute([
                'module_id' => $moduleId,
                'created_by' => $user['id'],
                'class_name' => $activeClass,
                'target_word' => strtoupper($targetWord),
                'hint' => $hint,
                'is_published' => $isPublished,
            ]);
            chemnama_flash('Cari kata berhasil ditambahkan.', 'success');
        }
        header('Location: guru_games.php#cari-kata');
        exit;
    }
}

if ($action === 'delete_word') {
    $wordId = (int) ($_POST['word_id'] ?? 0);
    if ($wordId > 0) {
        $stmt = $pdo->prepare('DELETE FROM word_search_games WHERE id = :id AND created_by = :created_by');
        $stmt->execute(['id' => $wordId, 'created_by' => $user['id']]);
        chemnama_flash('Cari kata berhasil dihapus.', 'success');
    }
    header('Location: guru_games.php#cari-kata');
    exit;
}

if ($action === 'save_simulation') {
    $simulationId = (int) ($_POST['simulation_id'] ?? 0);
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $atomFirst = trim((string) ($_POST['atom_first'] ?? ''));
    $atomSecond = trim((string) ($_POST['atom_second'] ?? ''));
    $productFormula = trim((string) ($_POST['product_formula'] ?? ''));
    $productName = trim((string) ($_POST['product_name'] ?? ''));
    $bondType = trim((string) ($_POST['bond_type'] ?? ''));
    $moleculeLayout = trim((string) ($_POST['molecule_layout'] ?? ''));
    $reactionEnergy = trim((string) ($_POST['reaction_energy'] ?? ''));
    $reactionType = trim((string) ($_POST['reaction_type'] ?? ''));
    $introDescription = trim((string) ($_POST['intro_description'] ?? ''));
    $descriptionAbout = trim((string) ($_POST['description_about'] ?? ''));
    $foundIn = trim((string) ($_POST['found_in'] ?? ''));
    $dailyUsage = trim((string) ($_POST['daily_usage'] ?? ''));

    if (!isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul wajib dipilih.';
    }

    if ($atomFirst === '' || $atomSecond === '' || $productFormula === '' || $productName === '') {
        $errors[] = 'Atom Pertama, Atom Kedua, Rumus Produk, dan Nama Produk wajib diisi.';
    }

    $existing = null;
    if ($simulationId > 0) {
        $check = $pdo->prepare('SELECT * FROM simulations WHERE id = :id AND created_by = :created_by LIMIT 1');
        $check->execute(['id' => $simulationId, 'created_by' => $user['id']]);
        $existing = $check->fetch();
        if (!$existing) {
            $errors[] = 'Data simulasi tidak ditemukan.';
        }
    }

    if (count($errors) === 0) {
        if ($simulationId > 0) {
            $update = $pdo->prepare(
                'UPDATE simulations SET
                    module_id = :module_id,
                    atom_first = :atom_first,
                    atom_second = :atom_second,
                    product_formula = :product_formula,
                    product_name = :product_name,
                    bond_type = :bond_type,
                    molecule_layout = :molecule_layout,
                    reaction_energy = :reaction_energy,
                    reaction_type = :reaction_type,
                    intro_description = :intro_description,
                    description_about = :description_about,
                    found_in = :found_in,
                    daily_usage = :daily_usage
                 WHERE id = :id AND created_by = :created_by'
            );
            $update->execute([
                'module_id' => $moduleId,
                'atom_first' => $atomFirst,
                'atom_second' => $atomSecond,
                'product_formula' => $productFormula,
                'product_name' => $productName,
                'bond_type' => $bondType,
                'molecule_layout' => $moleculeLayout,
                'reaction_energy' => $reactionEnergy,
                'reaction_type' => $reactionType,
                'intro_description' => $introDescription !== '' ? $introDescription : null,
                'description_about' => $descriptionAbout,
                'found_in' => $foundIn,
                'daily_usage' => $dailyUsage,
                'id' => $simulationId,
                'created_by' => $user['id'],
            ]);

            chemnama_flash('Simulasi berhasil diperbarui.', 'success');
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO simulations (
                    module_id, created_by, class_name,
                    atom_first, atom_second, product_formula, product_name, bond_type,
                    molecule_layout, reaction_energy, reaction_type, intro_description, description_about,
                    found_in, daily_usage
                 ) VALUES (
                    :module_id, :created_by, :class_name,
                    :atom_first, :atom_second, :product_formula, :product_name, :bond_type,
                    :molecule_layout, :reaction_energy, :reaction_type, :intro_description, :description_about,
                    :found_in, :daily_usage
                 )'
            );
            $insert->execute([
                'module_id' => $moduleId,
                'created_by' => $user['id'],
                'class_name' => $activeClass,
                'atom_first' => $atomFirst,
                'atom_second' => $atomSecond,
                'product_formula' => $productFormula,
                'product_name' => $productName,
                'bond_type' => $bondType,
                'molecule_layout' => $moleculeLayout,
                'reaction_energy' => $reactionEnergy,
                'reaction_type' => $reactionType,
                'intro_description' => $introDescription !== '' ? $introDescription : null,
                'description_about' => $descriptionAbout,
                'found_in' => $foundIn,
                'daily_usage' => $dailyUsage,
            ]);

            chemnama_flash('Simulasi berhasil ditambahkan.', 'success');
        }

        header('Location: guru_games.php');
        exit;
    }
}

$mode = (string) ($_GET['mode'] ?? 'list-simulasi');
$simulationEditId = (int) ($_GET['edit_sim'] ?? 0);

$simulationEditing = null;
if ($simulationEditId > 0) {
    $qEditSim = $pdo->prepare('SELECT * FROM simulations WHERE id = :id AND created_by = :created_by LIMIT 1');
    $qEditSim->execute(['id' => $simulationEditId, 'created_by' => $user['id']]);
    $simulationEditing = $qEditSim->fetch();
    if (!$simulationEditing) {
        $simulationEditId = 0;
    }
}

$classStudentCountStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM users u
     JOIN user_profiles up ON up.user_id = u.id
     WHERE u.role = "siswa" AND up.kelas = :kelas'
);
$classStudentCountStmt->execute(['kelas' => $activeClass]);
$totalClassStudents = (int) $classStudentCountStmt->fetchColumn();

$simulationListSql = 'SELECT s.*, mo.badge AS module_badge, mo.title AS module_title, u.name AS teacher_name,
                 COALESCE(sr_summary.read_count, 0) AS read_count,
                 COALESCE(sr_summary.read_students, "") AS read_students,
                 COALESCE(class_students_summary.class_students, "") AS class_students
             FROM simulations s
             JOIN modules mo ON mo.id = s.module_id
             JOIN users u ON u.id = s.created_by
             LEFT JOIN (
                 SELECT sr.simulation_id,
                        COUNT(DISTINCT sr.student_id) AS read_count,
                        GROUP_CONCAT(
                            DISTINCT CONCAT(u_read.name, "::", DATE_FORMAT(sr.read_at, "%Y-%m-%d %H:%i:%s"))
                            ORDER BY u_read.name SEPARATOR "||"
                        ) AS read_students
                 FROM simulation_reads sr
                 JOIN users u_read ON u_read.id = sr.student_id AND u_read.role = "siswa"
                 JOIN user_profiles up_read ON up_read.user_id = sr.student_id AND up_read.kelas = :active_class_simulation
                 GROUP BY sr.simulation_id
             ) sr_summary ON sr_summary.simulation_id = s.id
             LEFT JOIN (
                SELECT up_class.kelas,
                       GROUP_CONCAT(DISTINCT u_class.name ORDER BY u_class.name SEPARATOR "||") AS class_students
                FROM user_profiles up_class
                JOIN users u_class ON u_class.id = up_class.user_id AND u_class.role = "siswa"
                     WHERE up_class.kelas = :active_class_simulation_join_sub
                GROUP BY up_class.kelas
             ) class_students_summary ON class_students_summary.kelas = :active_class_simulation_join
             WHERE s.created_by = :created_by
             ORDER BY s.created_at DESC';
$simulationListStmt = $pdo->prepare($simulationListSql);
$simulationListStmt->execute([
    'created_by' => $user['id'],
    'active_class_simulation' => $activeClass,
    'active_class_simulation_join' => $activeClass,
    'active_class_simulation_join_sub' => $activeClass,
]);
$simulations = $simulationListStmt->fetchAll();

foreach ($simulations as &$simulation) {
    $classStudentNames = [];
    $classStudentsRaw = trim((string) ($simulation['class_students'] ?? ''));
    if ($classStudentsRaw !== '') {
        $classStudentNames = array_values(array_filter(array_map('trim', explode('||', $classStudentsRaw)), static fn (string $name): bool => $name !== ''));
    }

    $readEntries = [];
    $readStudentsRaw = trim((string) ($simulation['read_students'] ?? ''));
    if ($readStudentsRaw !== '') {
        foreach (array_values(array_filter(array_map('trim', explode('||', $readStudentsRaw)), static fn (string $entry): bool => $entry !== '')) as $entry) {
            [$readerName, $readAt] = array_pad(explode('::', $entry, 2), 2, '');
            $readerName = trim($readerName);
            $readAt = trim($readAt);
            if ($readerName === '') {
                continue;
            }

            $readEntries[] = [
                'name' => $readerName,
                'read_at' => $readAt,
            ];
        }
    }

    $readerNames = array_values(array_unique(array_map(static fn (array $entry): string => (string) ($entry['name'] ?? ''), $readEntries)));
    $unreadNames = array_values(array_diff($classStudentNames, $readerNames));

    $simulation['read_entries_json'] = json_encode($readEntries, JSON_UNESCAPED_UNICODE);
    $simulation['unread_students_json'] = json_encode($unreadNames, JSON_UNESCAPED_UNICODE);
}
unset($simulation);

$simulationsByModule = [];
foreach ($simulations as $simulation) {
    $moduleId = (int) $simulation['module_id'];
    if (!isset($simulationsByModule[$moduleId])) {
        $simulationsByModule[$moduleId] = [];
    }
    $simulationsByModule[$moduleId][] = $simulation;
}

$matchEditId = (int) ($_GET['edit_match'] ?? 0);
$matchEditing = null;
if ($matchEditId > 0) {
    $matchEditStmt = $pdo->prepare('SELECT * FROM chem_match_games WHERE id = :id AND created_by = :created_by LIMIT 1');
    $matchEditStmt->execute(['id' => $matchEditId, 'created_by' => $user['id']]);
    $matchEditing = $matchEditStmt->fetch();
    if (!$matchEditing) {
        $matchEditId = 0;
    }
}

$matchListStmt = $pdo->prepare('SELECT g.*, mo.badge AS module_badge, mo.title AS module_title, u.name AS teacher_name, COALESCE(r.read_count, 0) AS read_count FROM chem_match_games g JOIN modules mo ON mo.id = g.module_id JOIN users u ON u.id = g.created_by LEFT JOIN (SELECT chem_match_id, COUNT(*) AS read_count FROM chem_match_reads GROUP BY chem_match_id) r ON r.chem_match_id = g.id WHERE g.created_by = :created_by ORDER BY g.created_at DESC');
$matchListStmt->execute(['created_by' => $user['id']]);
$matches = $matchListStmt->fetchAll();

foreach ($matches as &$match) {
    $match['leaderboard'] = chemnama_get_chem_match_leaderboard($pdo, (int) $match['id'], 10);
}
unset($match);

$chemMatchLeaderboardsByModule = [];
foreach ($modules as $module) {
    $moduleId = (int) $module['id'];
    $chemMatchLeaderboardsByModule[$moduleId] = chemnama_get_module_chem_match_leaderboard($pdo, $moduleId, 10);
}

$wordsByModule = [];

$wordEditId = (int) ($_GET['edit_word'] ?? 0);
$wordEditing = null;
if ($wordEditId > 0) {
    $wordEditStmt = $pdo->prepare('SELECT * FROM word_search_games WHERE id = :id AND created_by = :created_by LIMIT 1');
    $wordEditStmt->execute(['id' => $wordEditId, 'created_by' => $user['id']]);
    $wordEditing = $wordEditStmt->fetch();
    if (!$wordEditing) {
        $wordEditId = 0;
    }
}

$wordListStmt = $pdo->prepare('SELECT g.*, mo.badge AS module_badge, mo.title AS module_title, u.name AS teacher_name, COALESCE(r.read_count, 0) AS read_count FROM word_search_games g JOIN modules mo ON mo.id = g.module_id JOIN users u ON u.id = g.created_by LEFT JOIN (SELECT word_search_id, COUNT(*) AS read_count FROM word_search_reads GROUP BY word_search_id) r ON r.word_search_id = g.id WHERE g.created_by = :created_by ORDER BY g.created_at DESC');
$wordListStmt->execute(['created_by' => $user['id']]);
$words = $wordListStmt->fetchAll();

foreach ($words as &$word) {
    $word['leaderboard'] = chemnama_get_word_search_leaderboard($pdo, (int) $word['id'], 10);
}
unset($word);

$wordLeaderboardsByModule = [];
foreach ($modules as $module) {
    $moduleId = (int) $module['id'];
    $wordLeaderboardsByModule[$moduleId] = chemnama_get_module_word_search_leaderboard($pdo, $moduleId, 10);
}

foreach ($words as $word) {
    $moduleId = (int) $word['module_id'];
    if (!isset($wordsByModule[$moduleId])) {
        $wordsByModule[$moduleId] = [];
    }
    $wordsByModule[$moduleId][] = $word;
}

$simulationFormData = [
    'simulation_id' => $simulationEditing['id'] ?? 0,
    'module_id' => (int) ($simulationEditing['module_id'] ?? ($modules[0]['id'] ?? 1)),
    'atom_first' => (string) ($simulationEditing['atom_first'] ?? ''),
    'atom_second' => (string) ($simulationEditing['atom_second'] ?? ''),
    'product_formula' => (string) ($simulationEditing['product_formula'] ?? ''),
    'product_name' => (string) ($simulationEditing['product_name'] ?? ''),
    'bond_type' => (string) ($simulationEditing['bond_type'] ?? ''),
    'molecule_layout' => (string) ($simulationEditing['molecule_layout'] ?? ''),
    'reaction_energy' => (string) ($simulationEditing['reaction_energy'] ?? ''),
    'reaction_type' => (string) ($simulationEditing['reaction_type'] ?? ''),
    'intro_description' => (string) ($simulationEditing['intro_description'] ?? ''),
    'description_about' => (string) ($simulationEditing['description_about'] ?? ''),
    'found_in' => (string) ($simulationEditing['found_in'] ?? ''),
    'daily_usage' => (string) ($simulationEditing['daily_usage'] ?? ''),
];

$matchFormData = [
    'match_id' => $matchEditing['id'] ?? 0,
    'module_id' => (int) ($matchEditing['module_id'] ?? ($modules[0]['id'] ?? 1)),
    'left_term' => (string) ($matchEditing['left_term'] ?? ''),
    'right_term' => (string) ($matchEditing['right_term'] ?? ''),
    'hint' => (string) ($matchEditing['hint'] ?? ''),
    'is_published' => (int) ($matchEditing['is_published'] ?? 1),
];

$wordFormData = [
    'word_id' => $wordEditing['id'] ?? 0,
    'module_id' => (int) ($wordEditing['module_id'] ?? ($modules[0]['id'] ?? 1)),
    'target_word' => (string) ($wordEditing['target_word'] ?? ''),
    'hint' => (string) ($wordEditing['hint'] ?? ''),
    'is_published' => (int) ($wordEditing['is_published'] ?? 1),
];

$flash = chemnama_flash();
$showSimulationForm = $mode === 'add-simulasi' || $simulationEditId > 0;
$showMatchForm = $mode === 'add-match' || $matchEditId > 0;
$showWordForm = $mode === 'add-word' || $wordEditId > 0;

$guruMenus = [
        ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
    ['label' => 'Quick / Quiz', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => false],
    ['label' => 'Pengaturan Games', 'icon' => 'beaker', 'href' => 'guru_games.php', 'active' => true],
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
    <title>Pengaturan Games - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .games-modal-card {
            background: rgba(30, 41, 59, 0.96) !important;
            color: #f1f5f9;
        }

        .games-modal-card h3,
        .games-modal-card h4,
        .games-modal-card p,
        .games-modal-card li,
        .games-modal-card small {
            color: #f1f5f9;
        }

        .games-modal-card .materi-read-list li,
        .games-modal-card .materi-read-summary {
            color: #cbd5e1;
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
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_games.php">
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
                <h1>Pengaturan Games</h1>
                <p>Kelola simulasi, tarik garis, dan cari kata untuk siswa.</p>
            </div>
        </div>

       

        <a class="guru-back-link guru-back-link-inline" href="javascript:history.back()"><?= chemnama_icon('switch', '#64748b'); ?> Kembali</a>

        <div class="games-switcher" data-games-switcher>
            <div class="games-switcher-tabs">
                <a class="games-switcher-tab is-active" href="#simulasi"><?= chemnama_icon('beaker', '#ffffff'); ?><span>Simulasi</span><span class="games-switcher-count">(<?= (int) $simulationCount; ?>)</span></a>
                <a class="games-switcher-tab" href="#chem-match"><?= chemnama_icon('stack', '#d8cde9'); ?><span>ChemMatch</span><span class="games-switcher-count">(<?= (int) $matchCount; ?>)</span></a>
                <a class="games-switcher-tab" href="#cari-kata"><?= chemnama_icon('search', '#d8cde9'); ?><span>Cari Kata</span><span class="games-switcher-count">(<?= (int) $wordCount; ?>)</span></a>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?= chemnama_e((string) $flash['message']); ?></div>
        <?php endif; ?>

        <?php if (count($errors) > 0): ?>
            <div class="alert alert-error"><?= chemnama_e(implode(' ', $errors)); ?></div>
        <?php endif; ?>
        

        <section class="guru-panel materi-form-panel games-panel is-active" id="simulasi" style="margin-top: 0;" data-games-panel>
            <div class="guru-headline-row" style="margin-bottom: 16px;">
                <div>
                    <h1 style="font-size: 1.4rem; margin: 0;">Simulasi</h1>
                    <p>Kelola simulasi reaksi langsung di halaman ini.</p>
                </div>
                <div class="guru-head-actions">
                    <a class="btn btn-primary materi-btn" href="guru_games.php?mode=add-simulasi#simulasi"><?= chemnama_icon('beaker', '#ffffff'); ?> Tambah Simulasi</a>
                </div>
            </div>

            <?php if ($showSimulationForm): ?>
                <article class="guru-panel materi-form-panel" id="simulasi-form">
                    <h3><?= $simulationEditId > 0 ? 'Edit Simulasi' : 'Tambah Simulasi Baru'; ?></h3>
                    <form method="post" class="materi-form-grid">
                        <input type="hidden" name="action" value="save_simulation">
                        <input type="hidden" name="simulation_id" value="<?= chemnama_e((string) $simulationFormData['simulation_id']); ?>">

                        <label>
                            <span>Modul</span>
                            <div class="forum-custom-select" data-guru-custom-select="game_sim_module_id">
                                <input type="hidden" name="module_id" value="<?= chemnama_e((string) $simulationFormData['module_id']); ?>">
                                <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                    <?php
                                        $selectedGameSimModuleLabel = 'Pilih Modul';
                                        $currentGameSimModuleId = (int) $simulationFormData['module_id'];
                                        foreach ($modules as $m) {
                                            if ((int) $m['id'] === $currentGameSimModuleId) {
                                                $selectedGameSimModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                                break;
                                            }
                                        }
                                    ?>
                                    <span class="forum-custom-select-label"><?= chemnama_e($selectedGameSimModuleLabel); ?></span>
                                    <span class="forum-custom-select-caret" aria-hidden="true"></span>
                                </button>
                                <div class="forum-custom-select-menu" role="listbox" hidden>
                                    <?php foreach ($modules as $module): ?>
                                        <button class="forum-custom-select-option<?= $currentGameSimModuleId === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= chemnama_e((string) $module['id']); ?>" data-label="<?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?php echo $currentGameSimModuleId === (int) $module['id'] ? 'true' : 'false'; ?>">
                                            <?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </label>

                        <label>
                            <span>Atom Pertama</span>
                            <input type="text" name="atom_first" placeholder="Contoh: H (Hidrogen)" value="<?= chemnama_e($simulationFormData['atom_first']); ?>" required>
                        </label>

                        <label>
                            <span>Atom Kedua</span>
                            <input type="text" name="atom_second" placeholder="Contoh: Cl (Klor)" value="<?= chemnama_e($simulationFormData['atom_second']); ?>" required>
                        </label>

                        <label>
                            <span>Rumus Produk</span>
                            <input type="text" name="product_formula" placeholder="Contoh: HCl" value="<?= chemnama_e($simulationFormData['product_formula']); ?>" required>
                        </label>

                        <label>
                            <span>Nama Produk</span>
                            <input type="text" name="product_name" placeholder="Contoh: Natrium Klorida" value="<?= chemnama_e($simulationFormData['product_name']); ?>" required>
                        </label>

                        <label>
                            <span>Jenis Ikatan</span>
                            <div class="forum-custom-select" data-guru-custom-select="game_sim_bond_type">
                                <input type="hidden" name="bond_type" value="<?= chemnama_e($simulationFormData['bond_type']); ?>">
                                <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                    <?php
                                        $bondTypeLabel = $simulationFormData['bond_type'] ?: 'Pilih Jenis Ikatan';
                                    ?>
                                    <span class="forum-custom-select-label"><?= chemnama_e($bondTypeLabel); ?></span>
                                    <span class="forum-custom-select-caret" aria-hidden="true"></span>
                                </button>
                                <div class="forum-custom-select-menu" role="listbox" hidden>
                                    <button class="forum-custom-select-option<?= $simulationFormData['bond_type'] === 'Ionik' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Ionik" data-label="Ionik" aria-selected="<?= $simulationFormData['bond_type'] === 'Ionik' ? 'true' : 'false'; ?>">Ionik</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['bond_type'] === 'Kovalen Polar' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Kovalen Polar" data-label="Kovalen Polar" aria-selected="<?= $simulationFormData['bond_type'] === 'Kovalen Polar' ? 'true' : 'false'; ?>">Kovalen Polar</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['bond_type'] === 'Kovalen Non-Polar' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Kovalen Non-Polar" data-label="Kovalen Non-Polar" aria-selected="<?= $simulationFormData['bond_type'] === 'Kovalen Non-Polar' ? 'true' : 'false'; ?>">Kovalen Non-Polar</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['bond_type'] === 'Metalik' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Metalik" data-label="Metalik" aria-selected="<?= $simulationFormData['bond_type'] === 'Metalik' ? 'true' : 'false'; ?>">Metalik</button>
                                </div>
                            </div>
                        </label>

                        <label>
                            <span>Layout Molekul</span>
                            <div class="forum-custom-select" data-guru-custom-select="game_sim_molecule_layout">
                                <input type="hidden" name="molecule_layout" value="<?= chemnama_e($simulationFormData['molecule_layout']); ?>">
                                <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                    <?php
                                        $layoutLabel = $simulationFormData['molecule_layout'] ?: 'Pilih Layout';
                                    ?>
                                    <span class="forum-custom-select-label"><?= chemnama_e($layoutLabel); ?></span>
                                    <span class="forum-custom-select-caret" aria-hidden="true"></span>
                                </button>
                                <div class="forum-custom-select-menu" role="listbox" hidden>
                                    <button class="forum-custom-select-option<?= $simulationFormData['molecule_layout'] === 'Biner' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Biner" data-label="Biner" aria-selected="<?= $simulationFormData['molecule_layout'] === 'Biner' ? 'true' : 'false'; ?>">Biner</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['molecule_layout'] === 'Linear' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Linear" data-label="Linear" aria-selected="<?= $simulationFormData['molecule_layout'] === 'Linear' ? 'true' : 'false'; ?>">Linear</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['molecule_layout'] === 'Trigonal Planar' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Trigonal Planar" data-label="Trigonal Planar" aria-selected="<?= $simulationFormData['molecule_layout'] === 'Trigonal Planar' ? 'true' : 'false'; ?>">Trigonal Planar</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['molecule_layout'] === 'Tetrahedral' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Tetrahedral" data-label="Tetrahedral" aria-selected="<?= $simulationFormData['molecule_layout'] === 'Tetrahedral' ? 'true' : 'false'; ?>">Tetrahedral</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['molecule_layout'] === 'Trigonal Bipyramid' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Trigonal Bipyramid" data-label="Trigonal Bipyramid" aria-selected="<?= $simulationFormData['molecule_layout'] === 'Trigonal Bipyramid' ? 'true' : 'false'; ?>">Trigonal Bipyramid</button>
                                </div>
                            </div>
                        </label>

                        <label>
                            <span>Energi Reaksi</span>
                            <input type="text" name="reaction_energy" placeholder="Contoh: Eksotermik (-411 kJ/mol)" value="<?= chemnama_e($simulationFormData['reaction_energy']); ?>" required>
                        </label>

                        <label>
                            <span>Jenis Reaksi</span>
                            <div class="forum-custom-select" data-guru-custom-select="game_sim_reaction_type">
                                <input type="hidden" name="reaction_type" value="<?= chemnama_e($simulationFormData['reaction_type']); ?>">
                                <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                    <?php
                                        $reactionTypeLabel = $simulationFormData['reaction_type'] ?: 'Pilih Jenis Reaksi';
                                    ?>
                                    <span class="forum-custom-select-label"><?= chemnama_e($reactionTypeLabel); ?></span>
                                    <span class="forum-custom-select-caret" aria-hidden="true"></span>
                                </button>
                                <div class="forum-custom-select-menu" role="listbox" hidden>
                                    <button class="forum-custom-select-option<?= $simulationFormData['reaction_type'] === 'Reaksi Senyawa Ionik' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Reaksi Senyawa Ionik" data-label="Reaksi Senyawa Ionik" aria-selected="<?= $simulationFormData['reaction_type'] === 'Reaksi Senyawa Ionik' ? 'true' : 'false'; ?>">Reaksi Senyawa Ionik</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['reaction_type'] === 'Reaksi Senyawa Kovalen' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Reaksi Senyawa Kovalen" data-label="Reaksi Senyawa Kovalen" aria-selected="<?= $simulationFormData['reaction_type'] === 'Reaksi Senyawa Kovalen' ? 'true' : 'false'; ?>">Reaksi Senyawa Kovalen</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['reaction_type'] === 'Reaksi Redoks' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Reaksi Redoks" data-label="Reaksi Redoks" aria-selected="<?= $simulationFormData['reaction_type'] === 'Reaksi Redoks' ? 'true' : 'false'; ?>">Reaksi Redoks</button>
                                    <button class="forum-custom-select-option<?= $simulationFormData['reaction_type'] === 'Reaksi Asam-Basa' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Reaksi Asam-Basa" data-label="Reaksi Asam-Basa" aria-selected="<?= $simulationFormData['reaction_type'] === 'Reaksi Asam-Basa' ? 'true' : 'false'; ?>">Reaksi Asam-Basa</button>
                                </div>
                            </div>
                        </label>

                        <label class="span-2">
                            <span>Deskripsi Intro (Ditampilkan di awal simulasi)</span>
                            <textarea name="intro_description" placeholder="Jelaskan senyawa ini secara singkat untuk intro..." rows="2"><?= chemnama_e($simulationFormData['intro_description']); ?></textarea>
                        </label>

                        <label class="span-2">
                            <span>Penjelasan Senyawa (Tentang - Ditampilkan saat reaksi)</span>
                            <textarea name="description_about" placeholder="Jelaskan senyawa ini..." rows="3" required><?= chemnama_e($simulationFormData['description_about']); ?></textarea>
                        </label>

                        <label class="span-2">
                            <span>Ditemukan Di Mana? (Ditampilkan saat reaksi)</span>
                            <textarea name="found_in" placeholder="Ditemukan di alam dalam bentuk..." rows="2"><?= chemnama_e($simulationFormData['found_in']); ?></textarea>
                        </label>

                        <label class="span-2">
                            <span>Kegunaan Sehari-hari (Ditampilkan saat reaksi)</span>
                            <textarea name="daily_usage" placeholder="Digunakan untuk..." rows="2"><?= chemnama_e($simulationFormData['daily_usage']); ?></textarea>
                        </label>

                        <div class="form-action-row span-2">
                            <button class="btn btn-primary" type="submit"><?= chemnama_icon('upload', '#ffffff'); ?> <?= $simulationEditId > 0 ? 'Perbarui' : 'Tambah'; ?></button>
                            <a class="btn btn-ghost" href="guru_games.php#simulasi">Batal</a>
                        </div>
                    </form>
                </article>
            <?php endif; ?>

            <div class="materi-list" style="margin-top: 16px;">
                <?php if (count($simulations) === 0): ?>
                    <article class="guru-panel materi-item empty">
                        <h3>Belum ada simulasi</h3>
                        <p>Tambahkan simulasi reaksi agar bisa dipakai siswa.</p>
                    </article>
                <?php else: ?>
                    <?php foreach ($modules as $module): ?>
                        <?php
                            $moduleId = (int) $module['id'];
                            $moduleSimulations = $simulationsByModule[$moduleId] ?? [];
                            if (count($moduleSimulations) === 0) {
                                continue;
                            }
                        ?>

                        <article class="guru-panel materi-item" data-module-id="<?= chemnama_e((string) $moduleId); ?>">
                            <div class="materi-item-head">
                                <div class="materi-chip-group">
                                    <span class="materi-chip" style="color: <?= chemnama_e((string) $module['accent']); ?>; background: <?= chemnama_e((string) $module['accent']); ?>15;">
                                        <?= chemnama_icon((string) $module['icon'], (string) $module['accent']); ?>
                                        <?= chemnama_e((string) $module['badge']); ?>
                                    </span>
                                    <span class="materi-module-label"><?= chemnama_e((string) $module['title']); ?></span>
                                </div>
                            </div>

                            <?php foreach ($moduleSimulations as $simulation): ?>
                                <div class="guru-panel materi-item" style="margin-top: 14px;">
                                    <div class="materi-item-head">
                                        <div class="materi-chip-group">
                                            <span class="materi-chip" style="color: #14b8a6; background: #14b8a615;">
                                                <?= chemnama_icon('beaker', '#14b8a6'); ?>
                                                Simulasi
                                            </span>
                                            <span class="materi-module-label"><?= chemnama_e((string) $simulation['module_badge']); ?></span>
                                           
                                        </div>
                                        <div class="materi-actions">
                                            <a class="icon-action" href="guru_games.php?mode=add-simulasi&edit_sim=<?= chemnama_e((string) $simulation['id']); ?>#simulasi" title="Edit">
                                                <?= chemnama_icon('edit', '#2563eb'); ?>
                                            </a>
                                            <form method="post" onsubmit="return confirm('Hapus simulasi ini?')" style="display: inline;">
                                                <input type="hidden" name="action" value="delete_simulation">
                                                <input type="hidden" name="simulation_id" value="<?= chemnama_e((string) $simulation['id']); ?>">
                                                <button class="icon-action danger" type="submit" title="Hapus">
                                                    <?= chemnama_icon('trash', '#ef4444'); ?>
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <h3><?= chemnama_e((string) $simulation['product_name']); ?></h3>
                                    <p><?= chemnama_e((string) $simulation['product_formula']); ?></p>
                                    <small><?= chemnama_e((string) $simulation['teacher_name']); ?> · <?= chemnama_e((string) $simulation['created_at']); ?></small>
                                </div>
                            <?php endforeach; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <section class="guru-panel materi-form-panel games-panel" id="chem-match" style="margin-top: 0;" data-games-panel hidden>
            <div class="guru-headline-row" style="margin-bottom: 16px;">
                <div>
                    <h1 style="font-size: 1.4rem; margin: 0;">ChemMatch</h1>
                    <p>Kelola tarik garis langsung di halaman ini.</p>
                </div>
                <div class="guru-head-actions">
                    <a class="btn btn-primary materi-btn" href="guru_games.php?mode=add-match#chem-match"><?= chemnama_icon('stack', '#ffffff'); ?> Tambah ChemMatch</a>
                </div>
            </div>

            <?php if ($showMatchForm): ?>
                <article class="guru-panel materi-form-panel" id="chem-match-form">
                    <h3><?= $matchEditId > 0 ? 'Edit ChemMatch' : 'Tambah ChemMatch'; ?></h3>
                    <form method="post" class="materi-form-grid">
                        <input type="hidden" name="action" value="save_match">
                        <input type="hidden" name="match_id" value="<?= chemnama_e((string) $matchFormData['match_id']); ?>">
                        <label>
                            <span>Modul</span>
                            <div class="forum-custom-select" data-guru-custom-select="match_module_id">
                                <input type="hidden" name="module_id" value="<?= chemnama_e((string) $matchFormData['module_id']); ?>">
                                <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                    <?php
                                        $selectedMatchModuleLabel = 'Pilih Modul';
                                        $currentMatchModuleId = (int) $matchFormData['module_id'];
                                        foreach ($modules as $m) {
                                            if ((int) $m['id'] === $currentMatchModuleId) {
                                                $selectedMatchModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                                break;
                                            }
                                        }
                                    ?>
                                    <span class="forum-custom-select-label"><?= chemnama_e($selectedMatchModuleLabel); ?></span>
                                    <span class="forum-custom-select-caret" aria-hidden="true"></span>
                                </button>
                                <div class="forum-custom-select-menu" role="listbox" hidden>
                                    <?php foreach ($modules as $module): ?>
                                        <button class="forum-custom-select-option<?= (int) $matchFormData['module_id'] === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= chemnama_e((string) $module['id']); ?>" data-label="<?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?= (int) $matchFormData['module_id'] === (int) $module['id'] ? 'true' : 'false'; ?>">
                                            <?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </label>
                        <label><span>Kata / Istilah</span><input type="text" name="left_term" value="<?= chemnama_e($matchFormData['left_term']); ?>" placeholder="Contoh: NaCl" required></label>
                        <label><span>Pasangan Jawaban</span><input type="text" name="right_term" value="<?= chemnama_e($matchFormData['right_term']); ?>" placeholder="Contoh: Natrium Klorida" required></label>
                        <label class="span-2"><span>Hint</span><input type="text" name="hint" value="<?= chemnama_e($matchFormData['hint']); ?>" placeholder="Petunjuk singkat"></label>
                        <label class="span-2" style="display:flex;align-items:center;gap:4px;font-size:13px;font-weight:500;"><input type="checkbox" name="is_published" <?= (int) $matchFormData['is_published'] === 1 ? 'checked' : ''; ?>><span>Publikasikan ke siswa</span></label>
                        <div class="form-action-row span-2"><button class="btn btn-primary" type="submit"><?= chemnama_icon('upload', '#ffffff'); ?> Simpan</button><a class="btn btn-ghost" href="guru_games.php#chem-match">Batal</a></div>
                    </form>
                </article>
            <?php endif; ?>

            <div class="materi-list" style="margin-top: 16px;">
                <?php foreach ($modules as $module): ?>
                    <?php $moduleId = (int) $module['id']; $moduleMatches = array_values(array_filter($matches, static fn (array $item): bool => (int) $item['module_id'] === $moduleId)); if (!$moduleMatches) { continue; } ?>
                    <article class="guru-panel materi-item">
                        <div class="materi-item-head"><div class="materi-chip-group"><span class="materi-chip" style="color: <?= chemnama_e((string) $module['accent']); ?>; background: <?= chemnama_e((string) $module['accent']); ?>15;"><?= chemnama_icon((string) $module['icon'], (string) $module['accent']); ?><?= chemnama_e((string) $module['badge']); ?></span><span class="materi-module-label"><?= chemnama_e((string) $module['title']); ?></span></div><div class="materi-actions"><button type="button" class="btn-leaderboard" data-leaderboard-type="chem_match" data-leaderboard-module-id="<?= $moduleId; ?>" data-leaderboard-title="<?= chemnama_e((string) $module['title']); ?>" style="background:none;border:none;cursor:pointer;padding:6px 10px;display:inline-flex;align-items:center;gap:4px;font-size:0.85rem;color:#f59e0b;border-radius:6px;">🏆 <span>Lihat Leaderboard</span></button></div></div>
                        <?php foreach ($moduleMatches as $match): ?>
                            <div class="guru-panel materi-item" style="margin-top: 14px;">
                                <div class="materi-item-head"><div class="materi-chip-group"><span class="materi-chip" style="color:#8b5cf6;background:#8b5cf615;">ChemMatch</span></div><div class="materi-actions"><a class="icon-action" href="guru_games.php?mode=add-match&edit_match=<?= chemnama_e((string) $match['id']); ?>#chem-match"><?= chemnama_icon('edit', '#2563eb'); ?></a><form method="post" onsubmit="return confirm('Hapus item ini?')"><input type="hidden" name="action" value="delete_match"><input type="hidden" name="match_id" value="<?= chemnama_e((string) $match['id']); ?>"><button class="icon-action danger" type="submit"><?= chemnama_icon('trash', '#ef4444'); ?></button></form></div></div>
                                <h3><?= chemnama_e((string) $match['left_term']); ?> ↔ <?= chemnama_e((string) $match['right_term']); ?></h3>
                                <p><?= chemnama_e((string) ($match['hint'] ?? '')); ?></p>
                                <small><?= chemnama_e((string) $match['teacher_name']); ?> · <?= chemnama_e((string) $match['created_at']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="guru-panel materi-form-panel games-panel" id="cari-kata" style="margin-top: 0;" data-games-panel hidden>
            <div class="guru-headline-row" style="margin-bottom: 16px;">
                <div>
                    <h1 style="font-size: 1.4rem; margin: 0;">Cari Kata</h1>
                    <p>Kelola cari kata langsung di halaman ini.</p>
                </div>
                <div class="guru-head-actions">
                    <a class="btn btn-primary materi-btn" href="guru_games.php?mode=add-word#cari-kata"><?= chemnama_icon('search', '#ffffff'); ?> Tambah Cari Kata</a>
                </div>
            </div>

            <?php if ($showWordForm): ?>
                <article class="guru-panel materi-form-panel" id="word-form">
                    <h3><?= $wordEditId > 0 ? 'Edit Cari Kata' : 'Tambah Cari Kata'; ?></h3>
                    <form method="post" class="materi-form-grid">
                        <input type="hidden" name="action" value="save_word">
                        <input type="hidden" name="word_id" value="<?= chemnama_e((string) $wordFormData['word_id']); ?>">
                        <label>
                            <span>Modul</span>
                            <div class="forum-custom-select" data-guru-custom-select="word_module_id">
                                <input type="hidden" name="module_id" value="<?= chemnama_e((string) $wordFormData['module_id']); ?>">
                                <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                    <?php
                                        $selectedWordModuleLabel = 'Pilih Modul';
                                        $currentWordModuleId = (int) $wordFormData['module_id'];
                                        foreach ($modules as $m) {
                                            if ((int) $m['id'] === $currentWordModuleId) {
                                                $selectedWordModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                                break;
                                            }
                                        }
                                    ?>
                                    <span class="forum-custom-select-label"><?= chemnama_e($selectedWordModuleLabel); ?></span>
                                    <span class="forum-custom-select-caret" aria-hidden="true"></span>
                                </button>
                                <div class="forum-custom-select-menu" role="listbox" hidden>
                                    <?php foreach ($modules as $module): ?>
                                        <button class="forum-custom-select-option<?= (int) $wordFormData['module_id'] === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= chemnama_e((string) $module['id']); ?>" data-label="<?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?= (int) $wordFormData['module_id'] === (int) $module['id'] ? 'true' : 'false'; ?>">
                                            <?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </label>
                        <label><span>Kata Target</span><input type="text" name="target_word" value="<?= chemnama_e($wordFormData['target_word']); ?>" placeholder="Contoh: NACL" required></label>
                        <label><span>Hint</span><input type="text" name="hint" value="<?= chemnama_e($wordFormData['hint']); ?>" placeholder="Petunjuk singkat" required></label>
                        <label class="span-2" style="display:flex;align-items:center;gap:4px;font-size:13px;font-weight:500;"><input type="checkbox" name="is_published" <?= (int) $wordFormData['is_published'] === 1 ? 'checked' : ''; ?>><span>Publikasikan ke siswa</span></label>
                        <div class="form-action-row span-2"><button class="btn btn-primary" type="submit"><?= chemnama_icon('upload', '#ffffff'); ?> Simpan</button><a class="btn btn-ghost" href="guru_games.php#cari-kata">Batal</a></div>
                    </form>
                </article>
            <?php endif; ?>

            <div class="materi-list" style="margin-top: 16px;">
                <?php foreach ($modules as $module): ?>
                    <?php $moduleId = (int) $module['id']; $moduleWords = $wordsByModule[$moduleId] ?? []; if (!$moduleWords) { continue; } ?>
                    <article class="guru-panel materi-item">
                        <div class="materi-item-head"><div class="materi-chip-group"><span class="materi-chip" style="color: <?= chemnama_e((string) $module['accent']); ?>; background: <?= chemnama_e((string) $module['accent']); ?>15;"><?= chemnama_icon((string) $module['icon'], (string) $module['accent']); ?><?= chemnama_e((string) $module['badge']); ?></span><span class="materi-module-label"><?= chemnama_e((string) $module['title']); ?></span></div><div class="materi-actions"><button type="button" class="btn-leaderboard" data-leaderboard-type="word_search" data-leaderboard-module-id="<?= $moduleId; ?>" data-leaderboard-title="<?= chemnama_e((string) $module['title']); ?>" style="background:none;border:none;cursor:pointer;padding:6px 10px;display:inline-flex;align-items:center;gap:4px;font-size:0.85rem;color:#f59e0b;border-radius:6px;">🏆 <span>Lihat Leaderboard</span></button></div></div>
                        <?php foreach ($moduleWords as $word): ?>
                            <div class="guru-panel materi-item" style="margin-top: 14px;">
                                <div class="materi-item-head"><div class="materi-chip-group"><span class="materi-chip" style="color:#d97706;background:#d9770615;">Cari Kata</span></div><div class="materi-actions"><a class="icon-action" href="guru_games.php?mode=add-word&edit_word=<?= chemnama_e((string) $word['id']); ?>#cari-kata"><?= chemnama_icon('edit', '#2563eb'); ?></a><form method="post" onsubmit="return confirm('Hapus item ini?')"><input type="hidden" name="action" value="delete_word"><input type="hidden" name="word_id" value="<?= chemnama_e((string) $word['id']); ?>"><button class="icon-action danger" type="submit"><?= chemnama_icon('trash', '#ef4444'); ?></button></form></div></div>
                                <h3><?= chemnama_e((string) $word['target_word']); ?></h3>
                                <p><?= chemnama_e((string) $word['hint']); ?></p>
                                <small><?= chemnama_e((string) $word['teacher_name']); ?> · <?= chemnama_e((string) $word['created_at']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <div class="quiz-modal materi-read-modal" id="materiReadModal" aria-hidden="true">
            <a class="quiz-modal-backdrop" href="guru_games.php" aria-label="Tutup"></a>
            <div class="quiz-modal-card guru-panel games-modal-card" style="max-width: 720px; width: calc(100% - 24px);">
                <div class="quiz-modal-head games-modal-head">
                    <div>
                        <h3 data-material-modal-title style="color: #f1f5f9;">Daftar Pembaca</h3>
                        <p data-material-modal-subtitle style="color: #cbd5e1;">Simulasi</p>
                    </div>
                    <a class="quiz-modal-close" href="guru_games.php" aria-label="Tutup form">&times;</a>
                </div>
                <div class="materi-read-summary" data-material-modal-summary></div>
                <div class="materi-read-grid">
                    <div class="materi-read-list-wrap">
                        <h4>Siswa yang sudah membaca</h4>
                        <ul class="materi-read-list" data-material-modal-read-list></ul>
                    </div>
                    <div class="materi-read-list-wrap">
                        <h4>Siswa yang belum membaca</h4>
                        <ul class="materi-read-list is-unread" data-material-modal-unread-list></ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="quiz-modal materi-read-modal" id="leaderboardModal" aria-hidden="true" data-leaderboard-modal style="display:none;">
            <a class="quiz-modal-backdrop" href="#" aria-label="Tutup leaderboard"></a>
            <div class="quiz-modal-card guru-panel games-modal-card" style="max-width: 720px; width: calc(100% - 24px);">
                <div class="quiz-modal-head games-modal-head">
                    <div>
                        <h3 data-leaderboard-modal-title style="color: #f1f5f9;">Leaderboard</h3>
                        <p data-leaderboard-modal-subtitle style="color: #cbd5e1;">Peringkat waktu tercepat</p>
                    </div>
                    <button type="button" class="quiz-modal-close" data-leaderboard-close aria-label="Tutup leaderboard">&times;</button>
                </div>
                <div class="leaderboard-content" style="padding: 20px; max-height: 500px; overflow-y: auto;">
                    <div data-leaderboard-modal-list style="display: grid; gap: 12px;"></div>
                </div>
            </div>
        </div>
    </section>
</main>
<script>
(function () {
    const switcher = document.querySelector('[data-games-switcher]');
    const panels = Array.from(document.querySelectorAll('[data-games-panel]'));
    const tabs = Array.from(document.querySelectorAll('.games-switcher-tab'));
    const actions = Array.from(document.querySelectorAll('[data-games-action]'));

    if (!switcher || panels.length === 0 || tabs.length === 0) {
        return;
    }

    const showPanel = (targetId) => {
        panels.forEach((panel) => {
            const isActive = `#${panel.id}` === targetId;
            panel.classList.toggle('is-active', isActive);
            panel.hidden = !isActive;
        });

        tabs.forEach((tab) => {
            const isActive = tab.getAttribute('href') === targetId;
            tab.classList.toggle('is-active', isActive);
        });

        actions.forEach((actionButton) => {
            const isActive = actionButton.getAttribute('data-games-action') === targetId.replace('#', '');
            actionButton.hidden = !isActive;
        });
    };

    const initialHash = window.location.hash || '#simulasi';
    showPanel(initialHash);

    tabs.forEach((tab) => {
        tab.addEventListener('click', (event) => {
            const targetId = tab.getAttribute('href');
            if (!targetId || !targetId.startsWith('#')) {
                return;
            }

            event.preventDefault();
            history.replaceState(null, '', `${window.location.pathname}${window.location.search}${targetId}`);
            showPanel(targetId);
        });
    });

    window.addEventListener('hashchange', () => {
        showPanel(window.location.hash || '#simulasi');
    });
})();
</script>
<script>
(() => {
    const leaderboardModal = document.querySelector('[data-leaderboard-modal]');
    const leaderboardButtons = Array.from(document.querySelectorAll('.btn-leaderboard'));
    if (!leaderboardModal || leaderboardButtons.length === 0) {
        return;
    }

    const titleElement = leaderboardModal.querySelector('[data-leaderboard-modal-title]');
    const subtitleElement = leaderboardModal.querySelector('[data-leaderboard-modal-subtitle]');
    const listElement = leaderboardModal.querySelector('[data-leaderboard-modal-list]');
    const closeButton = leaderboardModal.querySelector('[data-leaderboard-close]');
    const backdrop = leaderboardModal.querySelector('.quiz-modal-backdrop');

    const closeLeaderboard = () => {
        leaderboardModal.style.display = 'none';
        leaderboardModal.setAttribute('aria-hidden', 'true');
    };

    const openLeaderboard = async (type, moduleId, moduleTitle) => {
        if (!listElement || !titleElement || !subtitleElement) {
            return;
        }

        const formatCompletionTime = (seconds) => {
            const totalSeconds = Number.parseInt(seconds || '0', 10);
            const minutes = String(Math.floor(totalSeconds / 60)).padStart(2, '0');
            const remainingSeconds = String(totalSeconds % 60).padStart(2, '0');
            return `${minutes}.${remainingSeconds}`;
        };

        titleElement.textContent = `🏆 Leaderboard ${type === 'chem_match' ? 'Tarik Garis' : 'Cari Kata'}`;
        subtitleElement.textContent = moduleTitle || 'Modul';
        listElement.innerHTML = '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.6);">Memuat leaderboard...</div>';
        leaderboardModal.style.display = 'flex';
        leaderboardModal.setAttribute('aria-hidden', 'false');

        try {
            const response = await fetch(`api_get_module_leaderboard.php?type=${encodeURIComponent(type)}&module_id=${encodeURIComponent(moduleId)}`, {
                headers: { 'Accept': 'application/json' },
            });
            const payload = await response.json();
            const rows = Array.isArray(payload.leaderboard) ? payload.leaderboard : [];

            if (rows.length === 0) {
                listElement.innerHTML = '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.6);">Belum ada yang menyelesaikan game ini</div>';
                return;
            }

            listElement.innerHTML = rows.map((entry, index) => {
                const medal = index === 0 ? '🥇' : index === 1 ? '🥈' : index === 2 ? '🥉' : `#${index + 1}`;
                const borderColor = index === 0 ? '#f59e0b' : index === 1 ? '#94a3b8' : index === 2 ? '#f97316' : '#334155';
                const gameLabel = type === 'chem_match'
                    ? `${entry.left_term} ↔ ${entry.right_term}`
                    : `${entry.target_word}`;
                return `
                    <div style="display:grid;grid-template-columns:48px 1fr auto;gap:12px;align-items:center;padding:12px 14px;border-radius:14px;background:rgba(255,255,255,0.04);border-left:4px solid ${borderColor};">
                        <div style="font-size:1.35rem;text-align:center;font-weight:800;">${medal}</div>
                        <div>
                            <div style="font-weight:700;color:#f8fafc;">${entry.name}</div>
                            <div style="font-size:0.82rem;color:#f59e0b;margin-top:2px;">${gameLabel}</div>
                            <div style="font-size:0.85rem;color:rgba(255,255,255,0.55);">${entry.read_at ?? '-'}</div>
                        </div>
                        <div style="font-size:1rem;font-weight:800;color:#4ade80;text-align:right;">${formatCompletionTime(entry.completion_time)}</div>
                    </div>
                `;
            }).join('');
        } catch (error) {
            listElement.innerHTML = '<div style="text-align:center;padding:20px;color:rgba(255,255,255,0.6);">Gagal memuat leaderboard</div>';
        }
    };

    leaderboardButtons.forEach((button) => {
            button.addEventListener('click', () => {
            const type = button.dataset.leaderboardType;
            const moduleId = Number.parseInt(button.dataset.leaderboardModuleId || '0', 10);
            const moduleTitle = button.dataset.leaderboardTitle || '';
            openLeaderboard(type, moduleId, moduleTitle);
        });
    });

    closeButton?.addEventListener('click', closeLeaderboard);
    backdrop?.addEventListener('click', closeLeaderboard);

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && leaderboardModal.getAttribute('aria-hidden') === 'false') {
            closeLeaderboard();
        }
    });
})();
</script>
</script>
<script>
    // Generic custom-select handler for guru pages
    (function () {
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
                if (menu.hasAttribute('hidden')) openMenu(); else closeMenu();
            });

            options.forEach((option) => {
                option.addEventListener('click', (e) => {
                    e.preventDefault();
                    const value = option.getAttribute('data-value') || '';
                    const optionLabel = option.getAttribute('data-label') || option.textContent;
                    valueInput.value = value;
                    label.textContent = optionLabel;
                    options.forEach((opt) => {
                        if (opt === option) { opt.classList.add('is-selected'); opt.setAttribute('aria-selected', 'true'); } else { opt.classList.remove('is-selected'); opt.setAttribute('aria-selected', 'false'); }
                    });
                    closeMenu();
                });
            });

            document.addEventListener('click', (event) => { if (!selectRoot.contains(event.target)) closeMenu(); });
            window.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeMenu(); });
        });
    })();
</script>
<script src="assets/js/app.js?v=20260407"></script>
</body>
</html>
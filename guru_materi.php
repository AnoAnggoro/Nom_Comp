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

$modules = $pdo->query('SELECT id, badge, title, accent, icon FROM modules ORDER BY sort_order, id')->fetchAll();
$moduleMap = [];
foreach ($modules as $module) {
    $moduleMap[(int) $module['id']] = $module;
}

$typeLabels = [
    'teks' => 'Teks/Catatan',
    'pdf' => 'Dokumen PDF',
    'presentasi' => 'Presentasi',
    'video' => 'Video',
    'animasi' => 'Animasi',
];

$typeBadges = [
    'teks' => '#0f9d58',
    'pdf' => '#ef4444',
    'presentasi' => '#d97706',
    'video' => '#db2777',
    'animasi' => '#14b8a6',
];

$uploadDir = APP_ROOT . '/storage/uploads/materials';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$allowedExt = [
    'teks' => ['txt', 'pdf', 'doc', 'docx'],
    'pdf' => ['pdf'],
    'presentasi' => ['ppt', 'pptx', 'doc', 'docx'],
    'video' => ['mp4', 'mov', 'mkv', 'webm'],
    'animasi' => ['zip', 'html', 'json', 'gif', 'mp4'],
];

$errors = [];
$action = (string) ($_POST['action'] ?? '');

if ($action === 'delete') {
    $materialId = (int) ($_POST['material_id'] ?? 0);
    if ($materialId > 0) {
        $check = $pdo->prepare('SELECT file_path FROM materials WHERE id = :id AND created_by = :created_by AND class_name = :class_name LIMIT 1');
        $check->execute(['id' => $materialId, 'created_by' => $user['id'], 'class_name' => $activeClass]);
        $row = $check->fetch();

        if ($row) {
            $delete = $pdo->prepare('DELETE FROM materials WHERE id = :id');
            $delete->execute(['id' => $materialId]);

            if (!empty($row['file_path'])) {
                $absolute = APP_ROOT . '/' . ltrim((string) $row['file_path'], '/');
                if (is_file($absolute)) {
                    @unlink($absolute);
                }
            }

            chemnama_flash('Materi berhasil dihapus.', 'success');
        }
    }

    header('Location: guru_materi.php');
    exit;
}

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

if ($action === 'save') {
    $materialId = (int) ($_POST['material_id'] ?? 0);
    $moduleId = (int) ($_POST['module_id'] ?? 0);
    $type = strtolower(trim((string) ($_POST['type'] ?? 'teks')));
    $title = trim((string) ($_POST['title'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $youtubeUrl = trim((string) ($_POST['youtube_url'] ?? ''));
    $contentText = trim((string) ($_POST['content_text'] ?? ''));
    $animationStyle = trim((string) ($_POST['animation_style'] ?? ''));

    if (!isset($moduleMap[$moduleId])) {
        $errors[] = 'Modul wajib dipilih.';
    }

    if (!array_key_exists($type, $typeLabels)) {
        $errors[] = 'Tipe materi tidak valid.';
    }

    if ($title === '' || $description === '') {
        $errors[] = 'Judul dan deskripsi wajib diisi.';
    }

    if ($youtubeUrl !== '' && filter_var($youtubeUrl, FILTER_VALIDATE_URL) === false) {
        $errors[] = 'Link YouTube tidak valid.';
    }

    $existing = null;
    if ($materialId > 0) {
        $check = $pdo->prepare('SELECT * FROM materials WHERE id = :id AND created_by = :created_by AND class_name = :class_name LIMIT 1');
        $check->execute(['id' => $materialId, 'created_by' => $user['id'], 'class_name' => $activeClass]);
        $existing = $check->fetch();
        if (!$existing) {
            $errors[] = 'Data materi tidak ditemukan.';
        }
    }

    $uploadedFileName = $existing['file_name'] ?? null;
    $uploadedFilePath = $existing['file_path'] ?? null;

    if (isset($_FILES['attachment']) && (int) $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int) $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload file gagal, silakan coba lagi.';
        } else {
            $originalName = (string) $_FILES['attachment']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            $validExtList = $allowedExt[$type] ?? [];
            if (!in_array($extension, $validExtList, true)) {
                $errors[] = 'Format file tidak sesuai dengan tipe materi.';
            } else {
                $safeName = preg_replace('/[^a-zA-Z0-9_-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
                $safeName = trim((string) $safeName, '-');
                if ($safeName === '') {
                    $safeName = 'materi';
                }

                $newName = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '-' . $safeName . '.' . $extension;
                $target = $uploadDir . '/' . $newName;

                if (!move_uploaded_file((string) $_FILES['attachment']['tmp_name'], $target)) {
                    $errors[] = 'File tidak dapat disimpan ke server.';
                } else {
                    if (!empty($uploadedFilePath)) {
                        $oldAbsolute = APP_ROOT . '/' . ltrim((string) $uploadedFilePath, '/');
                        if (is_file($oldAbsolute)) {
                            @unlink($oldAbsolute);
                        }
                    }

                    $uploadedFileName = $originalName;
                    $uploadedFilePath = 'storage/uploads/materials/' . $newName;
                }
            }
        }
    }

    if (count($errors) === 0) {
        if ($materialId > 0) {
            $update = $pdo->prepare(
                'UPDATE materials SET
                    module_id = :module_id,
                    type = :type,
                    title = :title,
                    description = :description,
                    class_name = :class_name,
                    content_text = :content_text,
                    youtube_url = :youtube_url,
                    animation_style = :animation_style,
                    file_name = :file_name,
                    file_path = :file_path
                 WHERE id = :id AND created_by = :created_by AND class_name = :class_name_filter'
            );
            $update->execute([
                'module_id' => $moduleId,
                'type' => $type,
                'title' => $title,
                'description' => $description,
                'class_name' => $activeClass,
                'content_text' => $contentText !== '' ? $contentText : null,
                'youtube_url' => $youtubeUrl !== '' ? $youtubeUrl : null,
                'animation_style' => $animationStyle !== '' ? $animationStyle : null,
                'file_name' => $uploadedFileName,
                'file_path' => $uploadedFilePath,
                'id' => $materialId,
                'created_by' => $user['id'],
                'class_name_filter' => $activeClass,
            ]);

            chemnama_flash('Materi berhasil diperbarui.', 'success');
        } else {
            $insert = $pdo->prepare(
                'INSERT INTO materials (
                    module_id, created_by, class_name, type, title, description, content_text,
                    youtube_url, animation_style, file_name, file_path
                 ) VALUES (
                    :module_id, :created_by, :class_name, :type, :title, :description, :content_text,
                    :youtube_url, :animation_style, :file_name, :file_path
                 )'
            );
            $insert->execute([
                'module_id' => $moduleId,
                'created_by' => $user['id'],
                'class_name' => $activeClass,
                'type' => $type,
                'title' => $title,
                'description' => $description,
                'content_text' => $contentText !== '' ? $contentText : null,
                'youtube_url' => $youtubeUrl !== '' ? $youtubeUrl : null,
                'animation_style' => $animationStyle !== '' ? $animationStyle : null,
                'file_name' => $uploadedFileName,
                'file_path' => $uploadedFilePath,
            ]);

            chemnama_flash('Materi berhasil ditambahkan.', 'success');
        }

        header('Location: guru_materi.php');
        exit;
    }
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

$mode = (string) ($_GET['mode'] ?? 'list');
$editId = (int) ($_GET['edit'] ?? 0);
$selectedModule = (int) ($_GET['module'] ?? 0);

$classStudentCountStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM users u
     JOIN user_profiles up ON up.user_id = u.id
     WHERE u.role = "siswa" AND up.kelas = :kelas'
);
$classStudentCountStmt->execute(['kelas' => $activeClass]);
$totalClassStudents = (int) $classStudentCountStmt->fetchColumn();

$materialEditing = null;
if ($editId > 0) {
    $qEdit = $pdo->prepare('SELECT * FROM materials WHERE id = :id AND created_by = :created_by AND class_name = :class_name LIMIT 1');
    $qEdit->execute(['id' => $editId, 'created_by' => $user['id'], 'class_name' => $activeClass]);
    $materialEditing = $qEdit->fetch();
    if (!$materialEditing) {
        $editId = 0;
    }
}

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

$params = [];

$listSql = 'SELECT m.*, mo.badge AS module_badge, mo.title AS module_title, mo.accent AS module_accent, u.name AS teacher_name,
                   COALESCE(mr_summary.read_count, 0) AS read_count,
                   COALESCE(mr_summary.read_students, "") AS read_students,
                   COALESCE(class_students_summary.class_students, "") AS class_students
            FROM materials m
            JOIN modules mo ON mo.id = m.module_id
            JOIN users u ON u.id = m.created_by
            LEFT JOIN (
                SELECT up_class.kelas,
                       GROUP_CONCAT(DISTINCT u_class.name ORDER BY u_class.name SEPARATOR "||") AS class_students
                FROM user_profiles up_class
                JOIN users u_class ON u_class.id = up_class.user_id AND u_class.role = "siswa"
                WHERE up_class.kelas = :active_class_class_filter
                GROUP BY up_class.kelas
            ) class_students_summary ON class_students_summary.kelas = :active_class_class_join
            LEFT JOIN (
                SELECT mr.material_id,
                       COUNT(DISTINCT mr.student_id) AS read_count,
                       GROUP_CONCAT(
                           DISTINCT CONCAT(u_read.name, "::", DATE_FORMAT(mr.read_at, "%Y-%m-%d %H:%i:%s"))
                           ORDER BY u_read.name SEPARATOR "||"
                       ) AS read_students
                FROM material_reads mr
                JOIN users u_read ON u_read.id = mr.student_id AND u_read.role = "siswa"
                JOIN user_profiles up_read ON up_read.user_id = mr.student_id AND up_read.kelas = :active_class_read
                GROUP BY mr.material_id
            ) mr_summary ON mr_summary.material_id = m.id
            WHERE m.created_by = :created_by
                            AND m.class_name = :active_class_material
            GROUP BY m.id, m.module_id, m.created_by, m.type, m.title, m.description, m.content_text,
                     m.youtube_url, m.animation_style, m.file_name, m.file_path, m.is_published,
                     m.created_at, m.updated_at, mo.badge, mo.title, mo.accent, u.name
            ORDER BY m.created_at DESC';
$params['created_by'] = $user['id'];
$params['active_class_class_filter'] = $activeClass;
$params['active_class_class_join'] = $activeClass;
$params['active_class_read'] = $activeClass;
$params['active_class_material'] = $activeClass;
$listStmt = $pdo->prepare($listSql);
$listStmt->execute($params);
$materials = $listStmt->fetchAll();

foreach ($materials as &$material) {
    $classStudentNames = [];
    $classStudentsRaw = trim((string) ($material['class_students'] ?? ''));
    if ($classStudentsRaw !== '') {
        $classStudentNames = array_values(array_filter(array_map('trim', explode('||', $classStudentsRaw)), static fn (string $name): bool => $name !== ''));
    }

    $readEntries = [];
    $readStudentsRaw = trim((string) ($material['read_students'] ?? ''));
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

    $material['read_entries_json'] = json_encode($readEntries, JSON_UNESCAPED_UNICODE);
    $material['unread_students_json'] = json_encode($unreadNames, JSON_UNESCAPED_UNICODE);
}
unset($material);

$countStmt = $pdo->prepare('SELECT module_id, COUNT(*) AS total FROM materials WHERE created_by = :created_by AND class_name = :class_name GROUP BY module_id');
$countStmt->execute(['created_by' => $user['id'], 'class_name' => $activeClass]);
$countRows = $countStmt->fetchAll();
$moduleCounts = [];
$totalCount = 0;
foreach ($countRows as $row) {
    $mid = (int) $row['module_id'];
    $cnt = (int) $row['total'];
    $moduleCounts[$mid] = $cnt;
    $totalCount += $cnt;
}

$formData = [
    'material_id' => $materialEditing['id'] ?? 0,
    'module_id' => (int) ($materialEditing['module_id'] ?? ($modules[0]['id'] ?? 1)),
    'type' => (string) ($materialEditing['type'] ?? ($mode === 'upload-animasi' ? 'animasi' : 'teks')),
    'title' => (string) ($materialEditing['title'] ?? ''),
    'description' => (string) ($materialEditing['description'] ?? ''),
    'youtube_url' => (string) ($materialEditing['youtube_url'] ?? ''),
    'content_text' => (string) ($materialEditing['content_text'] ?? ''),
    'animation_style' => (string) ($materialEditing['animation_style'] ?? 'Orbit Elektron'),
    'file_name' => (string) ($materialEditing['file_name'] ?? ''),
];

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

$moduleItemsByModule = [];
foreach ($moduleMap as $moduleId => $module) {
    $moduleItemsByModule[$moduleId] = [];

    foreach ($materials as $material) {
        if ((int) $material['module_id'] !== $moduleId) {
            continue;
        }

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
}

foreach ($simulationsByModule as $moduleId => $simulationRows) {
    $count = count($simulationRows);
    if (!isset($moduleCounts[$moduleId])) {
        $moduleCounts[$moduleId] = 0;
    }
    $moduleCounts[$moduleId] += $count;
    $totalCount += $count;
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

$flash = chemnama_flash();
$showMaterialForm = $mode === 'upload-material' || $editId > 0;
$showAnimationForm = $mode === 'upload-animasi' && $editId === 0;
$showSimulationForm = $mode === 'add-simulasi' || $simulationEditId > 0;

if ($mode === 'add-simulasi' || $mode === 'list-simulasi' || $simulationEditId > 0) {
    $redirectQuery = [];
    if ($mode === 'add-simulasi') {
        $redirectQuery['mode'] = 'add-simulasi';
    }
    if ($simulationEditId > 0) {
        $redirectQuery['edit_sim'] = (string) $simulationEditId;
    }

    header('Location: guru_games.php' . ($redirectQuery !== [] ? '?' . http_build_query($redirectQuery) : ''));
    exit;
}

$guruMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => true],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
    ['label' => 'Quick / Quiz', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => false],
    ['label' => 'Pengaturan Games', 'icon' => 'beaker', 'href' => 'guru_games.php', 'active' => false],
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
    <title>Kelola Materi - Nom Comp</title>
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
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_materi.php">
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
                <h1>Kelola Materi</h1>
                <p>Upload materi, video YouTube, dan animasi.</p>
            </div>
            <div class="guru-head-actions">
                <a class="btn btn-primary materi-btn" href="guru_materi.php?mode=upload-material"><?= chemnama_icon('plus', '#ffffff'); ?> Upload Materi</a>
            </div>
        </div>
        <a class="guru-back-link guru-back-link-inline" href="javascript:history.back()"><?= chemnama_icon('switch', '#64748b'); ?> Kembali</a>

        <?php if ($flash): ?>
            <div class="alert <?= $flash['type'] === 'success' ? 'alert-success' : 'alert-error'; ?>"><?= chemnama_e((string) $flash['message']); ?></div>
        <?php endif; ?>

        <?php if (count($errors) > 0): ?>
            <div class="alert alert-error"><?= chemnama_e(implode(' ', $errors)); ?></div>
        <?php endif; ?>

        <?php if ($showMaterialForm): ?>
            <article class="guru-panel materi-form-panel">
                <h3><?= $editId > 0 ? 'Edit Materi' : 'Upload Materi Baru'; ?></h3>
                <form method="post" enctype="multipart/form-data" class="materi-form-grid">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="material_id" value="<?= chemnama_e((string) $formData['material_id']); ?>">

                    <label>
                        <span>Modul</span>
                        <div class="forum-custom-select" data-materi-custom-select="module_id">
                            <input type="hidden" name="module_id" value="<?= chemnama_e((string) $formData['module_id']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <?php
                                    $selectedModuleLabel = $modules[0]['badge'] . ' - ' . $modules[0]['title'];
                                    foreach ($modules as $m) {
                                        if ((int) $m['id'] === (int) $formData['module_id']) {
                                            $selectedModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                            break;
                                        }
                                    }
                                ?>
                                <span class="forum-custom-select-label"><?= chemnama_e($selectedModuleLabel); ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <?php foreach ($modules as $module): ?>
                                    <button class="forum-custom-select-option<?= (int) $formData['module_id'] === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= chemnama_e((string) $module['id']); ?>" data-label="<?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?= (int) $formData['module_id'] === (int) $module['id'] ? 'true' : 'false'; ?>">
                                        <?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </label>

                    <label>
                        <span>Tipe</span>
                        <div class="forum-custom-select" data-materi-custom-select="type">
                            <input type="hidden" name="type" value="<?= chemnama_e($formData['type']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <span class="forum-custom-select-label"><?= chemnama_e($typeLabels[$formData['type']] ?? 'Teks/Catatan'); ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <button class="forum-custom-select-option<?= $formData['type'] === 'teks' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="teks" data-label="Teks/Catatan" aria-selected="<?= $formData['type'] === 'teks' ? 'true' : 'false'; ?>">Teks/Catatan</button>
                                <button class="forum-custom-select-option<?= $formData['type'] === 'presentasi' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="presentasi" data-label="Presentasi" aria-selected="<?= $formData['type'] === 'presentasi' ? 'true' : 'false'; ?>">Presentasi</button>
                                <button class="forum-custom-select-option<?= $formData['type'] === 'pdf' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="pdf" data-label="PDF" aria-selected="<?= $formData['type'] === 'pdf' ? 'true' : 'false'; ?>">PDF</button>
                                <button class="forum-custom-select-option<?= $formData['type'] === 'video' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="video" data-label="Video" aria-selected="<?= $formData['type'] === 'video' ? 'true' : 'false'; ?>">Video</button>
                            </div>
                        </div>
                    </label>

                    <label class="span-2">
                        <span>Judul</span>
                        <input type="text" name="title" placeholder="Judul materi" value="<?= chemnama_e($formData['title']); ?>" required>
                    </label>

                    <label class="span-2">
                        <span>Deskripsi</span>
                        <input type="text" name="description" placeholder="Deskripsi singkat" value="<?= chemnama_e($formData['description']); ?>" required>
                    </label>

                    <label class="span-2">
                        <span>Link YouTube (opsional)</span>
                        <input type="url" name="youtube_url" placeholder="https://www.youtube.com/watch?v=..." value="<?= chemnama_e($formData['youtube_url']); ?>">
                    </label>

                    <label class="span-2">
                        <span>Konten Materi</span>
                        <textarea name="content_text" placeholder="Tulis konten..." rows="4"><?= chemnama_e($formData['content_text']); ?></textarea>
                    </label>

                    <label class="span-2 upload-dropzone">
                        <span>Upload file (opsional)</span>
                        <input type="file" name="attachment">
                        <?php if ($formData['file_name'] !== ''): ?>
                            <small>File saat ini: <?= chemnama_e($formData['file_name']); ?></small>
                        <?php endif; ?>
                    </label>

                    <div class="form-action-row span-2">
                        <button class="btn btn-primary" type="submit"><?= chemnama_icon('upload', '#ffffff'); ?> Upload</button>
                        <a class="btn btn-ghost" href="guru_materi.php">Batal</a>
                    </div>
                </form>
            </article>
        <?php endif; ?>

        <?php if ($showAnimationForm): ?>
            <article class="guru-panel materi-form-panel">
                <h3>Upload Animasi ke Homepage</h3>
                <form method="post" enctype="multipart/form-data" class="materi-form-grid">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="type" value="animasi">

                    <label>
                        <span>Modul</span>
                        <div class="forum-custom-select" data-materi-custom-select="anim_module_id">
                            <input type="hidden" name="module_id" value="<?= chemnama_e((string) $formData['module_id']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <?php
                                    $selectedAnimModuleLabel = $modules[0]['badge'] . ' - ' . $modules[0]['title'];
                                    foreach ($modules as $m) {
                                        if ((int) $m['id'] === (int) $formData['module_id']) {
                                            $selectedAnimModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                            break;
                                        }
                                    }
                                ?>
                                <span class="forum-custom-select-label"><?= chemnama_e($selectedAnimModuleLabel); ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <?php foreach ($modules as $module): ?>
                                    <button class="forum-custom-select-option<?= (int) $formData['module_id'] === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= chemnama_e((string) $module['id']); ?>" data-label="<?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?= (int) $formData['module_id'] === (int) $module['id'] ? 'true' : 'false'; ?>">
                                        <?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </label>

                    <label>
                        <span>Judul</span>
                        <input type="text" name="title" placeholder="Judul animasi" required>
                    </label>

                    <label>
                        <span>Deskripsi</span>
                        <input type="text" name="description" placeholder="Deskripsi singkat" required>
                    </label>

                    <label>
                        <span>Gaya</span>
                        <div class="forum-custom-select" data-materi-custom-select="animation_style">
                            <input type="hidden" name="animation_style" value="<?= chemnama_e($formData['animation_style']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <span class="forum-custom-select-label"><?= chemnama_e($formData['animation_style']); ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <button class="forum-custom-select-option<?= $formData['animation_style'] === 'Orbit Elektron' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Orbit Elektron" data-label="Orbit Elektron" aria-selected="<?= $formData['animation_style'] === 'Orbit Elektron' ? 'true' : 'false'; ?>">Orbit Elektron</button>
                                <button class="forum-custom-select-option<?= $formData['animation_style'] === 'Molekul 3D' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Molekul 3D" data-label="Molekul 3D" aria-selected="<?= $formData['animation_style'] === 'Molekul 3D' ? 'true' : 'false'; ?>">Molekul 3D</button>
                                <button class="forum-custom-select-option<?= $formData['animation_style'] === 'Ikatan Ionik' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Ikatan Ionik" data-label="Ikatan Ionik" aria-selected="<?= $formData['animation_style'] === 'Ikatan Ionik' ? 'true' : 'false'; ?>">Ikatan Ionik</button>
                                <button class="forum-custom-select-option<?= $formData['animation_style'] === 'Reaksi Cepat' ? ' is-selected' : ''; ?>" type="button" role="option" data-value="Reaksi Cepat" data-label="Reaksi Cepat" aria-selected="<?= $formData['animation_style'] === 'Reaksi Cepat' ? 'true' : 'false'; ?>">Reaksi Cepat</button>
                            </div>
                        </div>
                    </label>

                    <label class="span-2 upload-dropzone">
                        <span>Upload file animasi (opsional)</span>
                        <input type="file" name="attachment">
                    </label>

                    <div class="form-action-row span-2">
                        <button class="btn btn-primary" type="submit"><?= chemnama_icon('upload', '#ffffff'); ?> Upload</button>
                        <a class="btn btn-ghost" href="guru_materi.php">Batal</a>
                    </div>
                </form>
            </article>
        <?php endif; ?>

        <?php if ($showSimulationForm): ?>
            <article class="guru-panel materi-form-panel">
                <h3><?= $simulationEditId > 0 ? 'Edit Simulasi' : 'Tambah Simulasi Baru'; ?></h3>
                <form method="post" class="materi-form-grid">
                    <input type="hidden" name="action" value="save_simulation">
                    <input type="hidden" name="simulation_id" value="<?= chemnama_e((string) $simulationFormData['simulation_id']); ?>">

                    <label>
                        <span>Modul</span>
                        <div class="forum-custom-select" data-materi-custom-select="simulation_module_id">
                            <input type="hidden" name="module_id" value="<?= chemnama_e((string) $simulationFormData['module_id']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <?php
                                    $selectedSimModuleLabel = $modules[0]['badge'] . ' - ' . $modules[0]['title'];
                                    foreach ($modules as $m) {
                                        if ((int) $m['id'] === (int) $simulationFormData['module_id']) {
                                            $selectedSimModuleLabel = $m['badge'] . ' - ' . $m['title'];
                                            break;
                                        }
                                    }
                                ?>
                                <span class="forum-custom-select-label"><?= chemnama_e($selectedSimModuleLabel); ?></span>
                                <span class="forum-custom-select-caret" aria-hidden="true"></span>
                            </button>
                            <div class="forum-custom-select-menu" role="listbox" hidden>
                                <?php foreach ($modules as $module): ?>
                                    <button class="forum-custom-select-option<?= (int) $simulationFormData['module_id'] === (int) $module['id'] ? ' is-selected' : ''; ?>" type="button" role="option" data-value="<?= chemnama_e((string) $module['id']); ?>" data-label="<?= chemnama_e($module['badge'] . ' - ' . $module['title']); ?>" aria-selected="<?= (int) $simulationFormData['module_id'] === (int) $module['id'] ? 'true' : 'false'; ?>">
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
                        <div class="forum-custom-select" data-materi-custom-select="bond_type">
                            <input type="hidden" name="bond_type" value="<?= chemnama_e($simulationFormData['bond_type']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <span class="forum-custom-select-label"><?= $simulationFormData['bond_type'] !== '' ? chemnama_e($simulationFormData['bond_type']) : '-- Pilih Jenis Ikatan --'; ?></span>
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
                        <div class="forum-custom-select" data-materi-custom-select="molecule_layout">
                            <input type="hidden" name="molecule_layout" value="<?= chemnama_e($simulationFormData['molecule_layout']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <span class="forum-custom-select-label"><?= $simulationFormData['molecule_layout'] !== '' ? chemnama_e($simulationFormData['molecule_layout']) : '-- Pilih Layout --'; ?></span>
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
                        <div class="forum-custom-select" data-materi-custom-select="reaction_type">
                            <input type="hidden" name="reaction_type" value="<?= chemnama_e($simulationFormData['reaction_type']); ?>">
                            <button class="forum-custom-select-trigger" type="button" aria-haspopup="listbox" aria-expanded="false">
                                <span class="forum-custom-select-label"><?= $simulationFormData['reaction_type'] !== '' ? chemnama_e($simulationFormData['reaction_type']) : '-- Pilih Jenis Reaksi --'; ?></span>
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
                        <a class="btn btn-ghost" href="guru_games.php">Batal</a>
                    </div>
                </form>
            </article>
        <?php endif; ?>

        <?php if ($mode !== 'list-simulasi' && $mode !== 'add-simulasi' && $simulationEditId === 0): ?>
        <div class="materi-tabs" data-filter-tabs="materi">
            <a class="materi-tab js-module-tab <?= $selectedModule === 0 ? 'is-active' : ''; ?>" href="guru_materi.php" data-module="0">Semua (<?= $totalCount; ?>)</a>
            <?php foreach ($modules as $module): ?>
                <?php $count = $moduleCounts[(int) $module['id']] ?? 0; ?>
                <a class="materi-tab js-module-tab <?= $selectedModule === (int) $module['id'] ? 'is-active' : ''; ?>" href="guru_materi.php?module=<?= chemnama_e((string) $module['id']); ?>" data-module="<?= chemnama_e((string) $module['id']); ?>"><?= chemnama_e($module['badge']); ?> (<?= $count; ?>)</a>
            <?php endforeach; ?>
        </div>

        <div class="module-filter-mobile" data-filter-mobile="materi">
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
            <div class="module-filter-dropdown" data-filter-dropdown="materi">
                <button class="module-filter-trigger" type="button" data-filter-trigger="materi" aria-expanded="false" aria-controls="materiFilterMenu">
                    <span data-filter-current-label="materi"><?= chemnama_e($selectedModuleLabel); ?></span>
                    <span class="module-filter-caret" aria-hidden="true"></span>
                </button>
                <div class="module-filter-menu" id="materiFilterMenu" data-filter-menu="materi" hidden>
                    <button class="module-filter-option <?= $selectedModule === 0 ? 'is-active' : ''; ?>" type="button" data-filter-option="materi" data-module="0">Semua (<?= $totalCount; ?>)</button>
                    <?php foreach ($modules as $module): ?>
                        <?php $count = $moduleCounts[(int) $module['id']] ?? 0; ?>
                        <button class="module-filter-option <?= $selectedModule === (int) $module['id'] ? 'is-active' : ''; ?>" type="button" data-filter-option="materi" data-module="<?= chemnama_e((string) $module['id']); ?>"><?= chemnama_e($module['badge']); ?> (<?= $count; ?>)</button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        

        <div class="materi-list" data-filter-list="materi">
            <?php if (count($materials) === 0 && count($simulations) === 0): ?>
                <article class="guru-panel materi-item empty">
                    <h3>Belum ada materi</h3>
                    <p>Silakan upload materi baru agar muncul di dashboard siswa.</p>
                </article>
            <?php else: ?>
                <article class="guru-panel materi-item empty" style="display: none;">
                    <h3>Belum ada materi</h3>
                    <p>Belum ada materi untuk modul ini.</p>
                </article>

                <?php foreach ($modules as $module): ?>
                    <?php
                        $moduleId = (int) $module['id'];
                        $moduleItems = $moduleItemsByModule[$moduleId] ?? [];
                        if (count($moduleItems) === 0) {
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

                        <?php foreach ($moduleItems as $item): ?>
                            <?php if (($item['kind'] ?? '') === 'simulation'): ?>
                                <?php
                                    $simulation = $item['payload'];
                                ?>
                                <div class="guru-panel materi-item" data-module-id="<?= chemnama_e((string) $moduleId); ?>" style="margin-top: 14px;">
                                    <div class="materi-item-head">
                                        <div class="materi-chip-group">
                                            <span class="materi-chip" style="color: #14b8a6; background: #14b8a615;">
                                                <?= chemnama_icon('beaker', '#14b8a6'); ?>
                                                Simulasi
                                            </span>
                                            <span class="materi-module-label"><?= chemnama_e($simulation['module_badge']); ?></span>
                                           
                                        </div>
                                        <div class="materi-actions">
                                            <a class="icon-action" href="guru_games.php?edit_sim=<?= chemnama_e((string) $simulation['id']); ?>" title="Edit">
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
                                    <p><strong>Rumus:</strong> <?= chemnama_e((string) $simulation['product_formula']); ?></p>
                                    <p><strong>Atom:</strong> <?= chemnama_e((string) $simulation['atom_first']); ?> + <?= chemnama_e((string) $simulation['atom_second']); ?></p>
                                    <p><strong>Jenis Ikatan:</strong> <?= chemnama_e((string) $simulation['bond_type']); ?></p>
                                    <p><strong>Jenis Reaksi:</strong> <?= chemnama_e((string) $simulation['reaction_type']); ?></p>

                                    <small><?= chemnama_e((string) $simulation['teacher_name']); ?> · <?= chemnama_e((string) $simulation['created_at']); ?></small>
                                </div>
                            <?php else: ?>
                                <?php
                                    $material = $item['payload'];
                                    $mType = (string) $material['type'];
                                    $mColor = $typeBadges[$mType] ?? '#64748b';
                                    $editMode = $mType === 'animasi' ? 'upload-animasi' : 'upload-material';
                                    $youtubeEmbedUrl = chemnama_extract_youtube_embed_url((string) ($material['youtube_url'] ?? ''));
                                ?>
                                <div class="guru-panel materi-item" data-module-id="<?= chemnama_e((string) $material['module_id']); ?>" style="margin-top: 14px;">
                                    <div class="materi-item-head">
                                        <div class="materi-chip-group">
                                            <span class="materi-chip" style="color: <?= chemnama_e($mColor); ?>; background: <?= chemnama_e($mColor); ?>15;">
                                                <?= chemnama_icon('file', $mColor); ?>
                                                <?= chemnama_e($typeLabels[$mType] ?? ucfirst($mType)); ?>
                                            </span>
                                            <span class="materi-module-label"><?= chemnama_e($material['module_badge']); ?></span>
                                            <?php if (!empty($material['youtube_url'])): ?>
                                                <span class="yt-chip">YouTube</span>
                                            <?php endif; ?>
                                          
                                        </div>
                                        <div class="materi-actions">
                                            <a class="icon-action" href="guru_materi.php?mode=<?= chemnama_e($editMode); ?>&edit=<?= chemnama_e((string) $material['id']); ?>" title="Edit">
                                                <?= chemnama_icon('edit', '#2563eb'); ?>
                                            </a>
                                            <form method="post" onsubmit="return confirm('Hapus materi ini?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="material_id" value="<?= chemnama_e((string) $material['id']); ?>">
                                                <button class="icon-action danger" type="submit" title="Hapus">
                                                    <?= chemnama_icon('trash', '#ef4444'); ?>
                                                </button>
                                            </form>
                                        </div>
                                    </div>

                                    <h3><?= chemnama_e((string) $material['title']); ?></h3>
                                    <p><?= chemnama_e((string) $material['description']); ?></p>
                                  

                                    <?php if ($youtubeEmbedUrl !== null): ?>
                                        <div class="materi-youtube-wrap">
                                            <iframe src="<?= chemnama_e($youtubeEmbedUrl); ?>" title="Video YouTube materi" loading="lazy" allowfullscreen></iframe>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($material['file_name'])): ?>
                                        <?php if (!empty($material['file_path'])): ?>
                                            <a class="file-pill" href="<?= chemnama_e((string) $material['file_path']); ?>" target="_blank" rel="noopener">
                                                <?= chemnama_icon('clip', '#64748b'); ?>
                                                <?= chemnama_e((string) $material['file_name']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="file-pill">
                                                <?= chemnama_icon('clip', '#64748b'); ?>
                                                <?= chemnama_e((string) $material['file_name']); ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <small><?= chemnama_e((string) $material['teacher_name']); ?> · <?= chemnama_e((string) $material['created_at']); ?></small>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="quiz-modal materi-read-modal" id="materiReadModal" aria-hidden="true">
            <a class="quiz-modal-backdrop" href="guru_materi.php" aria-label="Tutup"></a>
            <div class="quiz-modal-card guru-panel" style="max-width: 720px; width: calc(100% - 24px); background: #ffffff;">
                <div class="quiz-modal-head">
                    <div>
                        <h3 data-material-modal-title>Daftar Pembaca</h3>
                        <p data-material-modal-subtitle>Materi</p>
                    </div>
                    <a class="quiz-modal-close" href="guru_materi.php" aria-label="Tutup form">&times;</a>
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
    </section>
</main>
<script src="assets/js/app.js?v=20260407"></script>
<script>
    // Materi custom select handler
    (function(){
        document.querySelectorAll('[data-materi-custom-select]').forEach((selectRoot) => {
            const trigger = selectRoot.querySelector('.forum-custom-select-trigger');
            const menu = selectRoot.querySelector('.forum-custom-select-menu');
            const valueInput = selectRoot.querySelector('input[type="hidden"]');
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

            trigger.addEventListener('click', (e) => {
                e.preventDefault();
                if (menu.hasAttribute('hidden')) {
                    openMenu();
                } else {
                    closeMenu();
                }
            });

            options.forEach((option) => {
                option.addEventListener('click', (e) => {
                    e.preventDefault();
                    const value = option.getAttribute('data-value') || '';
                    const optionLabel = option.getAttribute('data-label') || option.textContent;

                    valueInput.value = value;
                    label.textContent = optionLabel;

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

            document.addEventListener('click', (event) => {
                if (!selectRoot.contains(event.target)) {
                    closeMenu();
                }
            });

            window.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') {
                    closeMenu();
                }
            });
        });
    })();
</script>
<script>
    (function () {
        const tabs = document.querySelectorAll('[data-filter-tabs="materi"] .js-module-tab');
        const mobileDropdown = document.querySelector('[data-filter-dropdown="materi"]');
        const mobileTrigger = document.querySelector('[data-filter-trigger="materi"]');
        const mobileMenu = document.querySelector('[data-filter-menu="materi"]');
        const mobileOptions = document.querySelectorAll('[data-filter-option="materi"]');
        const mobileCurrentLabel = document.querySelector('[data-filter-current-label="materi"]');
        const items = document.querySelectorAll('[data-filter-list="materi"] .materi-item:not(.empty)');
        const emptyState = document.querySelector('[data-filter-list="materi"] .materi-item.empty');
        

        if (!tabs.length || !items.length) {
            return;
        }

        const search = window.location.search || '';
        const match = search.match(/[?&]module=([^&]+)/);
        const initialModule = match ? decodeURIComponent(match[1]) : '0';

        function applyFilter(moduleId, updateUrl) {
            let visibleCount = 0;

            items.forEach((item) => {
                const matches = moduleId === '0' || item.dataset.moduleId === moduleId;
                item.style.display = matches ? '' : 'none';
                if (matches) {
                    visibleCount += 1;
                }
            });

            if (emptyState) {
                emptyState.style.display = visibleCount === 0 ? '' : 'none';
            }

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
                const basePath = window.location.pathname;
                const currentSearch = window.location.search || '';
                let nextSearch = currentSearch;

                if (moduleId === '0') {
                    nextSearch = currentSearch
                        .replace(/([?&])module=[^&]*&?/, '$1')
                        .replace(/[?&]$/, '');
                } else if (currentSearch.indexOf('module=') !== -1) {
                    nextSearch = currentSearch.replace(/([?&])module=[^&]*/, '$1module=' + encodeURIComponent(moduleId));
                } else {
                    nextSearch = currentSearch ? currentSearch + '&module=' + encodeURIComponent(moduleId) : '?module=' + encodeURIComponent(moduleId);
                }

                nextSearch = nextSearch.replace('?&', '?').replace(/&&+/g, '&').replace(/[?&]$/, '');
                window.history.replaceState({}, '', basePath + nextSearch);
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

    (function () {
        const triggers = document.querySelectorAll('[data-material-read-trigger]');
        const modal = document.getElementById('materiReadModal');
        const titleEl = modal ? modal.querySelector('[data-material-modal-title]') : null;
        const subtitleEl = modal ? modal.querySelector('[data-material-modal-subtitle]') : null;
        const summaryEl = modal ? modal.querySelector('[data-material-modal-summary]') : null;
        const readListEl = modal ? modal.querySelector('[data-material-modal-read-list]') : null;
        const unreadListEl = modal ? modal.querySelector('[data-material-modal-unread-list]') : null;

        if (!modal || !titleEl || !subtitleEl || !summaryEl || !readListEl || !unreadListEl || !triggers.length) {
            return;
        }

        const safeParseList = (value) => {
            try {
                const parsed = JSON.parse(value || '[]');
                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
        };

        function renderList(listEl, items, emptyText) {
            listEl.innerHTML = '';

            if (!items.length) {
                const emptyItem = document.createElement('li');
                emptyItem.className = 'is-empty';
                emptyItem.textContent = emptyText;
                listEl.appendChild(emptyItem);
                return;
            }

            items.forEach((itemText) => {
                const entry = document.createElement('li');
                entry.textContent = itemText;
                listEl.appendChild(entry);
            });
        }

        function openModal(trigger) {
            const title = trigger.dataset.materialReadTitle || 'Daftar Pembaca';
            const subtitle = trigger.dataset.materialReadSubtitle || 'Materi';
            const summary = trigger.dataset.materialReadSummary || '';
            const readList = safeParseList(trigger.dataset.materialReadList);
            const unreadList = safeParseList(trigger.dataset.materialReadUnreadList);

            titleEl.textContent = title;
            subtitleEl.textContent = subtitle;
            summaryEl.textContent = summary;

            renderList(readListEl, readList, 'Belum ada siswa yang membaca materi ini.');
            renderList(unreadListEl, unreadList, 'Semua siswa sudah membaca materi ini.');

            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            document.body.classList.add('is-modal-open');
        }

        function closeModal() {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('is-modal-open');
        }

        triggers.forEach((trigger) => {
            trigger.addEventListener('click', () => openModal(trigger));
        });

        modal.addEventListener('click', (event) => {
            const target = event.target;
            if (target && target.nodeType === 1 && target.classList.contains('quiz-modal-backdrop')) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });
    })();
</script>
</body>
</html>

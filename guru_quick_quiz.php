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

// Get modules
$modules = $pdo->query('SELECT id, badge, title, accent, icon FROM modules ORDER BY sort_order, id')->fetchAll();
$moduleMap = [];
foreach ($modules as $module) {
    $moduleMap[(int) $module['id']] = $module;
}

// Get materials for this teacher/class
$materialsStmt = $pdo->prepare(
    'SELECT m.id, m.title, m.module_id, mo.title AS module_title
     FROM materials m
     JOIN modules mo ON mo.id = m.module_id
     WHERE m.created_by = :created_by AND m.class_name = :class_name
     ORDER BY mo.sort_order, m.id'
);
$materialsStmt->execute(['created_by' => (int)$user['id'], 'class_name' => $activeClass]);
$allMaterials = $materialsStmt->fetchAll();

$uploadDir = APP_ROOT . '/storage/uploads/quick_quiz_images';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxFileSize = 5 * 1024 * 1024; // 5MB

$errors = [];
$action = (string) ($_POST['action'] ?? '');
$editId = (int) ($_GET['edit'] ?? 0);
$filterModule = (int) ($_GET['module'] ?? 0);

// Handle save
if ($action === 'save') {
    $quizId = (int) ($_POST['quick_quiz_id'] ?? 0);
    $materialId = (int) ($_POST['material_id'] ?? 0);
    $questionText = trim((string) ($_POST['question_text'] ?? ''));
    $optionA = trim((string) ($_POST['option_a'] ?? ''));
    $optionB = trim((string) ($_POST['option_b'] ?? ''));
    $optionC = trim((string) ($_POST['option_c'] ?? ''));
    $optionD = trim((string) ($_POST['option_d'] ?? ''));
    $correctOption = strtolower(trim((string) ($_POST['correct_option'] ?? '')));
    $isPublished = isset($_POST['is_published']) ? 1 : 0;

    // Validation
    if ($materialId <= 0) {
        $errors[] = 'Materi wajib dipilih.';
    } else {
        $checkMaterial = $pdo->prepare(
            'SELECT id FROM materials WHERE id = :id AND created_by = :created_by AND class_name = :class_name LIMIT 1'
        );
        $checkMaterial->execute(['id' => $materialId, 'created_by' => (int)$user['id'], 'class_name' => $activeClass]);
        if (!$checkMaterial->fetch()) {
            $errors[] = 'Materi tidak valid atau tidak milik Anda.';
        }
    }

    if ($questionText === '') {
        $errors[] = 'Pertanyaan wajib diisi.';
    }
    if ($optionA === '' || $optionB === '' || $optionC === '' || $optionD === '') {
        $errors[] = 'Semua opsi jawaban wajib diisi.';
    }
    if (!in_array($correctOption, ['a', 'b', 'c', 'd'], true)) {
        $errors[] = 'Jawaban benar harus salah satu dari a, b, c, atau d.';
    }

    $imageUrl = null;
    if (isset($_FILES['image']) && (int) $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        if ((int) $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload gambar gagal.';
        } else if ((int) $_FILES['image']['size'] > $maxFileSize) {
            $errors[] = 'Ukuran gambar terlalu besar (max 5MB).';
        } else {
            $originalName = (string) $_FILES['image']['name'];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
            if (!in_array($extension, $allowedExt, true)) {
                $errors[] = 'Format gambar harus JPG, PNG, GIF, atau WEBP.';
            } else {
                $newName = date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
                $target = $uploadDir . '/' . $newName;

                if (!move_uploaded_file((string) $_FILES['image']['tmp_name'], $target)) {
                    $errors[] = 'Gambar tidak dapat disimpan ke server.';
                } else {
                    $imageUrl = 'storage/uploads/quick_quiz_images/' . $newName;
                    // Delete old image if editing
                    if ($quizId > 0) {
                        $oldQuiz = $pdo->prepare('SELECT image_url FROM quick_quizzes WHERE id = :id LIMIT 1');
                        $oldQuiz->execute(['id' => $quizId]);
                        $oldRow = $oldQuiz->fetch();
                        if ($oldRow && !empty($oldRow['image_url'])) {
                            $oldPath = APP_ROOT . '/' . ltrim((string) $oldRow['image_url'], '/');
                            if (is_file($oldPath)) {
                                @unlink($oldPath);
                            }
                        }
                    }
                }
            }
        }
    }

    if (count($errors) === 0) {
        if ($quizId > 0) {
            // Update
            $updateImagePart = $imageUrl !== null 
                ? ', image_url = :image_url' 
                : '';
            $updateSql = 'UPDATE quick_quizzes SET
                material_id = :material_id,
                question_text = :question_text,
                option_a = :option_a,
                option_b = :option_b,
                option_c = :option_c,
                option_d = :option_d,
                correct_option = :correct_option,
                is_published = :is_published,
                updated_at = NOW()
                ' . $updateImagePart . '
                WHERE id = :id AND created_by = :created_by';
            
            $stmt = $pdo->prepare($updateSql);
            $params = [
                'id' => $quizId,
                'created_by' => (int)$user['id'],
                'material_id' => $materialId,
                'question_text' => $questionText,
                'option_a' => $optionA,
                'option_b' => $optionB,
                'option_c' => $optionC,
                'option_d' => $optionD,
                'correct_option' => $correctOption,
                'is_published' => $isPublished,
            ];
            if ($imageUrl !== null) {
                $params['image_url'] = $imageUrl;
            }
            $stmt->execute($params);
            chemnama_flash('Quick Quiz berhasil diperbarui.', 'success');
        } else {
            // Insert
            $stmt = $pdo->prepare(
                'INSERT INTO quick_quizzes (
                    material_id, created_by, question_text, image_url,
                    option_a, option_b, option_c, option_d, correct_option, is_published
                ) VALUES (
                    :material_id, :created_by, :question_text, :image_url,
                    :option_a, :option_b, :option_c, :option_d, :correct_option, :is_published
                )'
            );
            $stmt->execute([
                'material_id' => $materialId,
                'created_by' => (int)$user['id'],
                'question_text' => $questionText,
                'image_url' => $imageUrl,
                'option_a' => $optionA,
                'option_b' => $optionB,
                'option_c' => $optionC,
                'option_d' => $optionD,
                'correct_option' => $correctOption,
                'is_published' => $isPublished,
            ]);
            chemnama_flash('Quick Quiz berhasil ditambahkan.', 'success');
        }

        header('Location: guru_quick_quiz.php');
        exit;
    }
}

// Handle delete
if ($action === 'delete') {
    $quizId = (int) ($_POST['quick_quiz_id'] ?? 0);
    if ($quizId > 0) {
        $quiz = $pdo->prepare('SELECT image_url FROM quick_quizzes WHERE id = :id AND created_by = :created_by LIMIT 1');
        $quiz->execute(['id' => $quizId, 'created_by' => (int)$user['id']]);
        $row = $quiz->fetch();

        if ($row) {
            // Delete image file
            if (!empty($row['image_url'])) {
                $imagePath = APP_ROOT . '/' . ltrim((string) $row['image_url'], '/');
                if (is_file($imagePath)) {
                    @unlink($imagePath);
                }
            }
            // Delete from database
            $delete = $pdo->prepare('DELETE FROM quick_quizzes WHERE id = :id AND created_by = :created_by');
            $delete->execute(['id' => $quizId, 'created_by' => (int)$user['id']]);
            chemnama_flash('Quick Quiz berhasil dihapus.', 'success');
        }
    }

    header('Location: guru_quick_quiz.php');
    exit;
}

// Get editing data
$editing = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM quick_quizzes WHERE id = :id AND created_by = :created_by LIMIT 1');
    $editStmt->execute(['id' => $editId, 'created_by' => (int)$user['id']]);
    $editing = $editStmt->fetch();
    if (!$editing) {
        $editId = 0;
    }
}

// Get list of quick quizzes
$listStmt = $pdo->prepare(
    'SELECT qq.*, m.title AS material_title, mo.title AS module_title, mo.badge AS module_badge,
            COUNT(qqa.id) AS attempt_count
     FROM quick_quizzes qq
     JOIN materials m ON m.id = qq.material_id
     JOIN modules mo ON mo.id = m.module_id
     LEFT JOIN quick_quiz_attempts qqa ON qqa.quick_quiz_id = qq.id
     WHERE qq.created_by = :created_by
     GROUP BY qq.id
     ORDER BY mo.sort_order, m.id, qq.created_at DESC'
);
$listStmt->execute(['created_by' => (int)$user['id']]);
$allQuizzes = $listStmt->fetchAll();

// Group by module
$quizzesByModule = [];
foreach ($allQuizzes as $quiz) {
    $moduleId = (int) $quiz['module_id'];
    if (!isset($quizzesByModule[$moduleId])) {
        $quizzesByModule[$moduleId] = [];
    }
    $quizzesByModule[$moduleId][] = $quiz;
}

$formData = [
    'quick_quiz_id' => $editing['id'] ?? 0,
    'material_id' => (int) ($editing['material_id'] ?? ($allMaterials[0]['id'] ?? 0)),
    'question_text' => (string) ($editing['question_text'] ?? ''),
    'option_a' => (string) ($editing['option_a'] ?? ''),
    'option_b' => (string) ($editing['option_b'] ?? ''),
    'option_c' => (string) ($editing['option_c'] ?? ''),
    'option_d' => (string) ($editing['option_d'] ?? ''),
    'correct_option' => (string) ($editing['correct_option'] ?? ''),
    'image_url' => (string) ($editing['image_url'] ?? ''),
    'is_published' => (int) ($editing['is_published'] ?? 1),
];

$flash = chemnama_flash();

$guruMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
    ['label' => 'Quick Quiz', 'icon' => 'check', 'href' => 'guru_quick_quiz.php', 'active' => true],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
    ['label' => 'Quiz PG', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => false],
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
    <title>Quick Quiz - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .quick-quiz-form {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #cbd5e1;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            background: rgba(255, 255, 255, 0.05);
            color: #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.95rem;
            transition: all 0.25s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #7c3aed;
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .image-preview {
            margin-top: 12px;
            max-width: 200px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }

        .image-preview img {
            width: 100%;
            height: auto;
            display: block;
        }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
        }

        .form-checkbox input[type="checkbox"] {
            width: auto;
            cursor: pointer;
        }

        .form-checkbox label {
            margin: 0;
            cursor: pointer;
            user-select: none;
        }

        .btn-group {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .quiz-card {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 12px;
            transition: all 0.25s ease;
        }

        .quiz-card:hover {
            border-color: rgba(124, 58, 237, 0.3);
            background: rgba(255, 255, 255, 0.05);
        }

        .quiz-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 12px;
        }

        .quiz-card-title {
            font-weight: 600;
            color: #e2e8f0;
            margin: 0;
        }

        .quiz-card-meta {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        .badge {
            display: inline-block;
            padding: 4px 8px;
            background: rgba(124, 58, 237, 0.2);
            color: #a78bfa;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 500;
        }

        .badge.published {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
        }

        .badge.draft {
            background: rgba(148, 163, 184, 0.2);
            color: #cbd5e1;
        }

        .quiz-card-actions {
            display: flex;
            gap: 8px;
        }

        .btn-small {
            padding: 6px 12px;
            font-size: 0.85rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.25s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-small.edit {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .btn-small.edit:hover {
            background: rgba(59, 130, 246, 0.3);
            border-color: rgba(59, 130, 246, 0.5);
        }

        .btn-small.delete {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .btn-small.delete:hover {
            background: rgba(239, 68, 68, 0.3);
            border-color: rgba(239, 68, 68, 0.5);
        }

        .option-preview {
            padding: 8px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 6px;
            font-size: 0.85rem;
            margin-bottom: 4px;
        }

        .option-preview.correct {
            background: rgba(34, 197, 94, 0.2);
            color: #86efac;
            border-left: 3px solid #22c55e;
            padding-left: 6px;
        }

        .section-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: #e2e8f0;
            margin: 32px 0 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }

        .empty-state p {
            margin: 8px 0;
        }
    </style>
</head>
<body class="dashboard-page">
<main class="guru-page">
    <button class="guru-mobile-toggle" type="button" data-guru-sidebar-toggle aria-label="Buka navigasi" aria-controls="guruSidebar" aria-expanded="false"><span></span><span></span><span></span></button>

    <nav class="guru-sidebar" id="guruSidebar">
        <div style="padding: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 20px;">
            <h3 style="margin: 0; color: #cbd5e1;">Guru Portal</h3>
            <p style="margin: 4px 0 0; font-size: 0.85rem; color: #64748b;"><?= chemnama_e((string)($user['name'] ?? '')); ?></p>
        </div>
        <ul class="guru-menu">
            <?php foreach ($guruMenus as $menu): ?>
                <li>
                    <a href="<?= chemnama_e((string)$menu['href']); ?>" class="guru-menu-link <?= $menu['active'] ? 'is-active' : ''; ?>">
                        <?= chemnama_icon($menu['icon'], $menu['active'] ? '#7c3aed' : '#64748b'); ?>
                        <span><?= chemnama_e((string)$menu['label']); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="guru-content">
        <header class="guru-header">
            <h1><?= chemnama_icon('check', '#7c3aed'); ?> Kelola Quick Quiz (Step 2)</h1>
            <p>Buat dan kelola quick quiz dengan gambar untuk pembelajaran materi</p>
        </header>

        <?php if ($flash): ?>
            <div style="background: rgba(<?= $flash['type'] === 'success' ? '34, 197, 94' : '239, 68, 68'; ?>, 0.2); border: 1px solid rgba(<?= $flash['type'] === 'success' ? '34, 197, 94' : '239, 68, 68'; ?>, 0.3); color: <?= $flash['type'] === 'success' ? '#86efac' : '#fca5a5'; ?>; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
                <?= chemnama_e((string)$flash['message']); ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <div class="quick-quiz-form">
            <h2 style="margin-top: 0; color: #e2e8f0;"><?= $editId > 0 ? 'Edit Quick Quiz' : 'Buat Quick Quiz Baru'; ?></h2>

            <?php if (!empty($errors)): ?>
                <div style="background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
                    <strong>Error:</strong>
                    <ul style="margin: 8px 0 0; padding-left: 20px;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= chemnama_e((string)$err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="quick_quiz_id" value="<?= (int)$formData['quick_quiz_id']; ?>">

                <div class="form-row">
                    <div class="form-group">
                        <label for="material_id">Materi Pembelajaran *</label>
                        <select id="material_id" name="material_id" required>
                            <option value="">-- Pilih Materi --</option>
                            <?php foreach ($allMaterials as $mat): ?>
                                <option value="<?= (int)$mat['id']; ?>" <?= (int)$mat['id'] === (int)$formData['material_id'] ? 'selected' : ''; ?>>
                                    <?= chemnama_e((string)$mat['module_title']); ?> > <?= chemnama_e((string)$mat['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label for="question_text">Pertanyaan *</label>
                    <textarea id="question_text" name="question_text" required><?= chemnama_e((string)$formData['question_text']); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="image">Gambar (Opsional)</label>
                    <input type="file" id="image" name="image" accept="image/*">
                    <small style="display: block; margin-top: 4px; color: #94a3b8;">Format: JPG, PNG, GIF, WEBP (Max 5MB)</small>
                    
                    <?php if (!empty($formData['image_url'])): ?>
                        <div class="image-preview">
                            <img src="<?= chemnama_e((string)$formData['image_url']); ?>" alt="Preview">
                        </div>
                    <?php endif; ?>
                </div>

                <h3 style="margin-top: 24px; color: #cbd5e1;">Opsi Jawaban</h3>

                <div class="form-row">
                    <div class="form-group">
                        <label for="option_a">Opsi A *</label>
                        <input type="text" id="option_a" name="option_a" value="<?= chemnama_e((string)$formData['option_a']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="option_b">Opsi B *</label>
                        <input type="text" id="option_b" name="option_b" value="<?= chemnama_e((string)$formData['option_b']); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="option_c">Opsi C *</label>
                        <input type="text" id="option_c" name="option_c" value="<?= chemnama_e((string)$formData['option_c']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="option_d">Opsi D *</label>
                        <input type="text" id="option_d" name="option_d" value="<?= chemnama_e((string)$formData['option_d']); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="correct_option">Jawaban Benar *</label>
                    <select id="correct_option" name="correct_option" required>
                        <option value="">-- Pilih Jawaban Benar --</option>
                        <option value="a" <?= $formData['correct_option'] === 'a' ? 'selected' : ''; ?>>A</option>
                        <option value="b" <?= $formData['correct_option'] === 'b' ? 'selected' : ''; ?>>B</option>
                        <option value="c" <?= $formData['correct_option'] === 'c' ? 'selected' : ''; ?>>C</option>
                        <option value="d" <?= $formData['correct_option'] === 'd' ? 'selected' : ''; ?>>D</option>
                    </select>
                </div>

                <div class="form-checkbox">
                    <input type="checkbox" id="is_published" name="is_published" <?= $formData['is_published'] ? 'checked' : ''; ?>>
                    <label for="is_published">Publikasikan untuk siswa</label>
                </div>

                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">
                        <?= chemnama_icon('save', '#ffffff'); ?> <?= $editId > 0 ? 'Update' : 'Buat'; ?>
                    </button>
                    <?php if ($editId > 0): ?>
                        <a href="guru_quick_quiz.php" class="btn btn-secondary">
                            <?= chemnama_icon('back', '#64748b'); ?> Batal
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- List -->
        <div class="section-title">Daftar Quick Quiz</div>

        <?php if (empty($quizzesByModule)): ?>
            <div class="empty-state">
                <p><?= chemnama_icon('inbox', '#64748b', 48); ?></p>
                <p>Belum ada quick quiz. Buat yang pertama di atas!</p>
            </div>
        <?php else: ?>
            <?php foreach ($modules as $module): ?>
                <?php if (isset($quizzesByModule[(int)$module['id']])): ?>
                    <div style="margin-bottom: 32px;">
                        <h3 style="display: flex; align-items: center; gap: 8px; color: #cbd5e1; margin-bottom: 12px;">
                            <span style="display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: <?= chemnama_e((string)$module['accent']); ?>30; border-radius: 8px; color: <?= chemnama_e((string)$module['accent']); ?>;">
                                <?= chemnama_e((string)$module['badge']); ?>
                            </span>
                            <?= chemnama_e((string)$module['title']); ?>
                        </h3>

                        <?php foreach ($quizzesByModule[(int)$module['id']] as $quiz): ?>
                            <div class="quiz-card">
                                <div class="quiz-card-header">
                                    <div>
                                        <p class="quiz-card-title"><?= chemnama_e((string)$quiz['question_text']); ?></p>
                                        <small style="color: #94a3b8;">Materi: <?= chemnama_e((string)$quiz['material_title']); ?></small>
                                    </div>
                                    <div class="quiz-card-meta">
                                        <span class="badge <?= $quiz['is_published'] ? 'published' : 'draft'; ?>">
                                            <?= $quiz['is_published'] ? 'Dipublikasikan' : 'Draft'; ?>
                                        </span>
                                        <span class="badge"><?= (int)$quiz['attempt_count']; ?> dijawab</span>
                                    </div>
                                </div>

                                <?php if (!empty($quiz['image_url'])): ?>
                                    <div style="margin-bottom: 12px;">
                                        <img src="<?= chemnama_e((string)$quiz['image_url']); ?>" alt="Quiz" style="max-width: 150px; border-radius: 6px;">
                                    </div>
                                <?php endif; ?>

                                <div style="background: rgba(255,255,255,0.02); padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                                    <div class="option-preview <?= $quiz['correct_option'] === 'a' ? 'correct' : ''; ?>">
                                        <strong>A.</strong> <?= chemnama_e((string)$quiz['option_a']); ?>
                                    </div>
                                    <div class="option-preview <?= $quiz['correct_option'] === 'b' ? 'correct' : ''; ?>">
                                        <strong>B.</strong> <?= chemnama_e((string)$quiz['option_b']); ?>
                                    </div>
                                    <div class="option-preview <?= $quiz['correct_option'] === 'c' ? 'correct' : ''; ?>">
                                        <strong>C.</strong> <?= chemnama_e((string)$quiz['option_c']); ?>
                                    </div>
                                    <div class="option-preview <?= $quiz['correct_option'] === 'd' ? 'correct' : ''; ?>">
                                        <strong>D.</strong> <?= chemnama_e((string)$quiz['option_d']); ?>
                                    </div>
                                </div>

                                <div class="quiz-card-actions">
                                    <a href="guru_quick_quiz.php?edit=<?= (int)$quiz['id']; ?>" class="btn-small edit">
                                        <?= chemnama_icon('edit', 'currentColor'); ?> Edit
                                    </a>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Hapus quick quiz ini?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="quick_quiz_id" value="<?= (int)$quiz['id']; ?>">
                                        <button type="submit" class="btn-small delete">
                                            <?= chemnama_icon('delete', 'currentColor'); ?> Hapus
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const toggle = document.querySelector('[data-guru-sidebar-toggle]');
        const sidebar = document.getElementById('guruSidebar');
        
        if (toggle && sidebar) {
            toggle.addEventListener('click', function () {
                const isExpanded = this.getAttribute('aria-expanded') === 'true';
                this.setAttribute('aria-expanded', !isExpanded);
                sidebar.classList.toggle('is-open');
            });
        }
    });
</script>
</body>
</html>

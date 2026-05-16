<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('guru');

$user = chemnama_current_user();
$userId = (int) ($user['id'] ?? 0);
$availableClasses = chemnama_available_classes_for_guru($pdo, $userId);
$classSuggestions = chemnama_available_student_classes($pdo);
$classTemplates = chemnama_standard_class_options();
$currentClass = chemnama_get_guru_active_class($pdo, $userId);
$error = null;
$success = null;

$flash = chemnama_flash();
if ($flash && ($flash['type'] ?? '') === 'success') {
    $success = (string) ($flash['message'] ?? '');
}

$redirectTo = trim((string) ($_POST['redirect_to'] ?? $_GET['redirect_to'] ?? 'dashboard.php'));
if ($redirectTo === '' || preg_match('/^https?:\/\//i', $redirectTo)) {
    $redirectTo = 'dashboard.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'register_class') {
        $selectedTemplate = chemnama_normalize_class_name((string) ($_POST['class_template'] ?? ''));
        $customClass = chemnama_normalize_class_name((string) ($_POST['new_class'] ?? ''));
        $newClass = '';

        if ($selectedTemplate === '__custom__' || $selectedTemplate === '') {
            $newClass = $customClass;
        } elseif (in_array($selectedTemplate, $classTemplates, true)) {
            $newClass = $selectedTemplate;
        } else {
            $error = 'Format kelas tidak valid.';
        }

        if ($error !== null) {
            // Keep existing error from template validation.
        } elseif ($newClass === '') {
            $error = 'Nama kelas wajib diisi.';
        } elseif (!chemnama_is_valid_class_name($newClass)) {
            $error = 'Format nama kelas tidak valid. Gunakan huruf, angka, spasi, titik, strip, atau garis miring.';
        } elseif (in_array($newClass, $availableClasses, true)) {
            $error = 'Kelas tersebut sudah terdaftar di akun Anda.';
        } elseif (!chemnama_register_guru_class($pdo, $userId, $newClass)) {
            $error = 'Gagal mendaftarkan kelas baru. Coba lagi.';
        } else {
            chemnama_set_guru_active_class($pdo, $userId, $newClass);
            chemnama_flash('Kelas ' . $newClass . ' berhasil ditambahkan ke akun Anda.', 'success');
            header('Location: guru_pilih_kelas.php?redirect_to=' . rawurlencode($redirectTo));
            exit;
        }
    } elseif ($action === 'remove_class') {
        $selectedClass = chemnama_normalize_class_name((string) ($_POST['selected_class'] ?? ''));

        if ($selectedClass === '' || !in_array($selectedClass, $availableClasses, true)) {
            $error = 'Kelas yang ingin dihapus tidak valid.';
        } elseif (count($availableClasses) <= 1) {
            $error = 'Minimal harus ada 1 kelas terdaftar.';
        } elseif (!chemnama_unregister_guru_class($pdo, $userId, $selectedClass)) {
            $error = 'Gagal menghapus kelas. Coba lagi.';
        } else {
            $remainingClasses = chemnama_available_classes_for_guru($pdo, $userId);
            if ($currentClass === $selectedClass && !empty($remainingClasses)) {
                chemnama_set_guru_active_class($pdo, $userId, $remainingClasses[0]);
            }

            chemnama_flash('Kelas ' . $selectedClass . ' berhasil dihapus dari akun Anda.', 'success');
            header('Location: guru_pilih_kelas.php?redirect_to=' . rawurlencode($redirectTo));
            exit;
        }
    } else {
        $selectedClass = trim((string) ($_POST['selected_class'] ?? ''));

        if (!chemnama_set_guru_active_class($pdo, $userId, $selectedClass)) {
            $error = 'Kelas yang dipilih tidak valid.';
        } else {
            header('Location: ' . $redirectTo);
            exit;
        }
    }
}

$classStatsStmt = $pdo->prepare(
        'SELECT ht.class_name AS kelas,
                        COUNT(DISTINCT hs.student_id) AS student_count,
                        ROUND(AVG(COALESCE(sp.average_score, 0))) AS average_score
         FROM homework_tasks ht
         LEFT JOIN homework_submissions hs ON hs.task_id = ht.id
         LEFT JOIN student_progress sp ON sp.user_id = hs.student_id
         WHERE ht.created_by = :teacher_id
             AND ht.is_published = 1
             AND ht.class_name IS NOT NULL
         GROUP BY ht.class_name'
);
$classStatsStmt->execute(['teacher_id' => $userId]);

$classStatsMap = [];
foreach ($classStatsStmt->fetchAll() as $row) {
    $kelas = (string) ($row['kelas'] ?? '');
    $classStatsMap[$kelas] = [
        'student_count' => (int) ($row['student_count'] ?? 0),
        'average_score' => (int) ($row['average_score'] ?? 0),
    ];
}

$pendingByClassStmt = $pdo->prepare(
        'SELECT ht.class_name AS kelas, COUNT(*) AS unchecked_count
     FROM homework_submissions hs
     JOIN homework_tasks ht ON ht.id = hs.task_id
         WHERE ht.created_by = :teacher_id
             AND hs.is_checked = 0
             AND ht.class_name IS NOT NULL
         GROUP BY ht.class_name'
);
$pendingByClassStmt->execute(['teacher_id' => $userId]);

$pendingByClass = [];
foreach ($pendingByClassStmt->fetchAll() as $row) {
    $pendingByClass[(string) $row['kelas']] = (int) ($row['unchecked_count'] ?? 0);
}

$activeHomeworkStmt = $pdo->prepare(
        'SELECT class_name AS kelas, COUNT(*) AS active_count
         FROM homework_tasks
         WHERE created_by = :teacher_id
             AND is_published = 1
             AND class_name IS NOT NULL
         GROUP BY class_name'
);
$activeHomeworkStmt->execute(['teacher_id' => $userId]);

$activeHomeworkByClass = [];
foreach ($activeHomeworkStmt->fetchAll() as $row) {
        $activeHomeworkByClass[(string) ($row['kelas'] ?? '')] = (int) ($row['active_count'] ?? 0);
}

$accentPalette = ['#0f9d58', '#d97706', '#2563eb', '#ec4899', '#14b8a6', '#8b5cf6'];
$iconPalette = ['flask', 'atom', 'edit', 'users', 'task', 'chart'];

$selectedClassTemplate = trim((string) ($_POST['class_template'] ?? ''));
if ($selectedClassTemplate !== '' && $selectedClassTemplate !== '__custom__' && !in_array($selectedClassTemplate, $classTemplates, true)) {
    $selectedClassTemplate = '';
}

$selectedClassTemplateLabel = 'Pilih kelas (sesuai registrasi)';
if ($selectedClassTemplate === '__custom__') {
    $selectedClassTemplateLabel = 'Lainnya (isi manual)';
} elseif ($selectedClassTemplate !== '') {
    $selectedClassTemplateLabel = $selectedClassTemplate;
}

$classPickerWallpaper = 'assets/img/bakcground.jpeg?v=20260426';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pilih Kelas - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page class-picker-page" style="--class-picker-wallpaper: url('<?= chemnama_e($classPickerWallpaper); ?>');">
<main class="class-picker-shell">
    <section class="class-picker-header panel-card">
        <span class="class-picker-icon"><?= chemnama_icon('atom', '#d97706'); ?></span>
        <h1>Pilih Kelas</h1>
        <p>Selamat datang, <strong><?= chemnama_e((string) ($user['name'] ?? 'Guru')); ?></strong>. Pilih kelas yang ingin dikelola.</p>
    </section>

    <?php if ($error): ?>
        <div class="alert alert-error class-picker-alert"><?= chemnama_e($error); ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success class-picker-alert"><?= chemnama_e($success); ?></div>
    <?php endif; ?>

    <section class="class-register panel-card">
        <h2><?= chemnama_icon('plus', '#0f9d58'); ?> Daftarkan Kelas Lain</h2>
        <p>Jika Anda mengajar di beberapa kelas, tambahkan kelas agar bisa dipilih di halaman ini.</p>
        <form method="post" class="class-register-form">
            <input type="hidden" name="action" value="register_class">
            <input type="hidden" name="redirect_to" value="<?= chemnama_e($redirectTo); ?>">
            <label for="class_template">Format kelas</label>
            <div class="class-register-row">
                <div class="forum-custom-select" data-guru-custom-select="class_template" data-guru-custom-select-root>
                    <input type="hidden" id="class_template" name="class_template" value="<?= chemnama_e($selectedClassTemplate); ?>" data-guru-custom-select-input>
                    <button type="button" class="forum-custom-select-trigger" data-guru-custom-select-trigger aria-haspopup="listbox" aria-expanded="false">
                        <span class="forum-custom-select-label" data-guru-custom-select-label><?= chemnama_e($selectedClassTemplateLabel); ?></span>
                        <span class="forum-custom-select-caret" aria-hidden="true"></span>
                    </button>
                    <div class="forum-custom-select-menu" data-guru-custom-select-menu role="listbox" hidden>
                        <button type="button" class="forum-custom-select-option<?= $selectedClassTemplate === '' ? ' is-selected' : ''; ?>" data-guru-custom-select-option data-value="" role="option" aria-selected="<?= $selectedClassTemplate === '' ? 'true' : 'false'; ?>">Pilih kelas (sesuai registrasi)</button>
                        <?php foreach ($classTemplates as $classTemplate): ?>
                            <?php $isSelectedTemplate = $selectedClassTemplate === $classTemplate; ?>
                            <button type="button" class="forum-custom-select-option<?= $isSelectedTemplate ? ' is-selected' : ''; ?>" data-guru-custom-select-option data-value="<?= chemnama_e($classTemplate); ?>" role="option" aria-selected="<?= $isSelectedTemplate ? 'true' : 'false'; ?>"><?= chemnama_e($classTemplate); ?></button>
                        <?php endforeach; ?>
                        <button type="button" class="forum-custom-select-option<?= $selectedClassTemplate === '__custom__' ? ' is-selected' : ''; ?>" data-guru-custom-select-option data-value="__custom__" role="option" aria-selected="<?= $selectedClassTemplate === '__custom__' ? 'true' : 'false'; ?>">Lainnya (isi manual)</button>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Tambah</button>
            </div>
            <label for="new_class">Nama kelas manual (opsional)</label>
            <div class="class-register-row class-register-row-manual">
                <input
                    id="new_class"
                    name="new_class"
                    list="class-suggestion-list"
                    type="text"
                    maxlength="30"
                    placeholder="Contoh: XI IPA 2 (isi jika pilih Lainnya)"
                >
            </div>
            <?php if (!empty($classSuggestions)): ?>
                <datalist id="class-suggestion-list">
                    <?php foreach ($classSuggestions as $classSuggestion): ?>
                        <option value="<?= chemnama_e($classSuggestion); ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            <?php endif; ?>
        </form>
    </section>

    <section class="class-picker-grid">
        <?php foreach ($availableClasses as $index => $kelas): ?>
            <?php
                $accent = $accentPalette[$index % count($accentPalette)];
                $icon = $iconPalette[$index % count($iconPalette)];
                $stats = $classStatsMap[$kelas] ?? ['student_count' => 0, 'average_score' => 0];
                $activeHomeworkCount = $activeHomeworkByClass[$kelas] ?? 0;
                $uncheckedCount = $pendingByClass[$kelas] ?? 0;
            ?>
            <form method="post" class="class-card panel-card<?= $kelas === $currentClass ? ' is-selected' : ''; ?>" style="--class-accent: <?= chemnama_e($accent); ?>;">
                <input type="hidden" name="selected_class" value="<?= chemnama_e($kelas); ?>">
                <input type="hidden" name="redirect_to" value="<?= chemnama_e($redirectTo); ?>">
                <span class="class-card-icon"><?= chemnama_icon($icon, $accent); ?></span>
                <h2><?= chemnama_e($kelas); ?></h2>
                <p><?= chemnama_e((string) $stats['student_count']); ?> siswa</p>

                <div class="class-stat-mini-grid">
                    <div class="class-stat-mini">
                        <strong><?= chemnama_e((string) $stats['average_score']); ?>%</strong>
                        <small>Rata-rata</small>
                    </div>
                    <div class="class-stat-mini">
                        <strong><?= $activeHomeworkCount; ?></strong>
                        <small>PR Aktif</small>
                    </div>
                    <div class="class-stat-mini">
                        <strong><?= $uncheckedCount; ?></strong>
                        <small>Belum Dicek</small>
                    </div>
                </div>

                <div class="class-card-actions">
                    <button class="class-card-action" type="submit" name="action" value="set_active_class">
                        Kelola Kelas <?= chemnama_icon('switch', $accent); ?>
                    </button>
                    <button
                        class="class-card-remove"
                        type="submit"
                        name="action"
                        value="remove_class"
                        data-class-remove-confirm
                        <?= count($availableClasses) <= 1 ? 'disabled' : ''; ?>
                    >
                        Hapus Kelas <?= chemnama_icon('trash', '#dc2626'); ?>
                    </button>
                </div>
            </form>
        <?php endforeach; ?>
    </section>

    <div class="class-picker-footer">
        <a class="btn btn-ghost" href="logout.php"><?= chemnama_icon('logout', '#1f2937'); ?> Keluar</a>
    </div>
</main>
<script src="assets/js/app.js?v=20260407"></script>
<script>
(() => {
    const selects = Array.from(document.querySelectorAll('[data-guru-custom-select-root]'));
    if (selects.length === 0) {
        return;
    }

    let opened = null;

    function closeSelect(selectRoot) {
        const trigger = selectRoot.querySelector('[data-guru-custom-select-trigger]');
        const menu = selectRoot.querySelector('[data-guru-custom-select-menu]');
        if (!trigger || !menu) {
            return;
        }

        trigger.setAttribute('aria-expanded', 'false');
        menu.hidden = true;
        if (opened === selectRoot) {
            opened = null;
        }
    }

    function openSelect(selectRoot) {
        const trigger = selectRoot.querySelector('[data-guru-custom-select-trigger]');
        const menu = selectRoot.querySelector('[data-guru-custom-select-menu]');
        if (!trigger || !menu) {
            return;
        }

        if (opened && opened !== selectRoot) {
            closeSelect(opened);
        }

        trigger.setAttribute('aria-expanded', 'true');
        menu.hidden = false;
        opened = selectRoot;
    }

    selects.forEach((selectRoot) => {
        const input = selectRoot.querySelector('[data-guru-custom-select-input]');
        const label = selectRoot.querySelector('[data-guru-custom-select-label]');
        const trigger = selectRoot.querySelector('[data-guru-custom-select-trigger]');
        const options = Array.from(selectRoot.querySelectorAll('[data-guru-custom-select-option]'));

        if (!input || !label || !trigger || options.length === 0) {
            return;
        }

        trigger.addEventListener('click', () => {
            const expanded = trigger.getAttribute('aria-expanded') === 'true';
            if (expanded) {
                closeSelect(selectRoot);
            } else {
                openSelect(selectRoot);
            }
        });

        options.forEach((option) => {
            option.addEventListener('click', () => {
                const value = option.dataset.value || '';
                const optionText = option.textContent || '';

                input.value = value;
                label.textContent = optionText;

                options.forEach((item) => {
                    const isSelected = item === option;
                    item.classList.toggle('is-selected', isSelected);
                    item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                });

                closeSelect(selectRoot);
            });
        });
    });

    document.addEventListener('click', (event) => {
        if (!opened) {
            return;
        }

        const clickTarget = event.target;
        if (clickTarget && opened.contains(clickTarget)) {
            return;
        }

        closeSelect(opened);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !opened) {
            return;
        }

        closeSelect(opened);
    });
})();
</script>
</body>
</html>

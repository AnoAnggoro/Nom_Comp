<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('siswa');

$user = chemnama_current_user();
$userId = (int) ($user['id'] ?? 0);
$classStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$classStmt->execute(['user_id' => $userId]);
$studentClass = (string) ($classStmt->fetchColumn() ?: 'X IPA 1');

chemnama_ensure_word_search_reads_table($pdo);

$gameId = (int) ($_GET['id'] ?? ($_GET['game_id'] ?? 0));
if ($gameId <= 0) {
    $firstStmt = $pdo->prepare('SELECT id FROM word_search_games WHERE is_published = 1 AND (class_name IS NULL OR class_name = :kelas) ORDER BY created_at DESC LIMIT 1');
    $firstStmt->execute(['kelas' => $studentClass]);
    $gameId = (int) $firstStmt->fetchColumn();
}

$gameStmt = $pdo->prepare('SELECT g.*, mo.badge AS module_badge, mo.title AS module_title, u.name AS teacher_name FROM word_search_games g JOIN modules mo ON mo.id = g.module_id JOIN users u ON u.id = g.created_by WHERE g.id = :id AND g.is_published = 1 LIMIT 1');
$gameStmt->execute(['id' => $gameId]);
$game = $gameStmt->fetch();

if (!$game) {
    header('Location: siswa_games.php');
    exit;
}

$isEmbedded = (string) ($_GET['embed'] ?? '') === '1';
$materialUrl = 'siswa_materi_detail.php?id=' . (int) $game['module_id'] . '&step=6';
$gamesUrl = 'siswa_games.php';
$returnUrl = $isEmbedded ? $materialUrl : $gamesUrl;

chemnama_mark_word_search_as_read($pdo, (int) $game['id'], $userId);
$isRead = chemnama_is_word_search_read($pdo, (int) $game['id'], $userId);

$relatedStmt = $pdo->prepare('SELECT id, target_word, hint FROM word_search_games WHERE module_id = :module_id AND is_published = 1 AND id <> :id ORDER BY created_at DESC LIMIT 6');
$relatedStmt->execute(['module_id' => (int) $game['module_id'], 'id' => (int) $game['id']]);
$relatedGames = $relatedStmt->fetchAll();

$wordSearchGames = array_slice(array_merge([$game], $relatedGames), 0, 7);
$boardWords = [];
foreach ($wordSearchGames as $index => $wordSearchGame) {
    $normalizedWord = strtoupper(trim((string) $wordSearchGame['target_word']));
    $normalizedWord = preg_replace('/[^A-Z0-9]/', '', $normalizedWord) ?: '';
    if ($normalizedWord === '') {
        continue;
    }

    $boardWords[] = [
        'index' => $index,
        'id' => (int) $wordSearchGame['id'],
        'word' => $normalizedWord,
        'label' => (string) $wordSearchGame['target_word'],
        'hint' => (string) $wordSearchGame['hint'],
        'module' => (string) ($wordSearchGame['module_title'] ?? $game['module_title']),
        'teacher' => (string) ($wordSearchGame['teacher_name'] ?? $game['teacher_name']),
    ];
}

$siswaMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Materi', 'icon' => 'file', 'href' => 'siswa_materi.php', 'active' => false],
    ['label' => 'Games', 'icon' => 'beaker', 'href' => 'siswa_games.php', 'active' => true],
    // ['label' => 'Quiz', 'icon' => 'stack', 'href' => 'siswa_quiz.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'siswa_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'siswa_essay.php#pr-homework', 'active' => false],
    ['label' => 'Forum Diskusi', 'icon' => 'chat', 'href' => 'siswa_forum.php', 'active' => false],
    ['label' => 'Profil', 'icon' => 'user', 'href' => 'siswa_profil.php', 'active' => false],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cari Kata - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body.word-search-page {
            overflow-x: hidden;
        }

        .word-search-stage {
            min-height: 100vh;
            background: linear-gradient(180deg, rgba(34, 13, 67, 0.98) 0%, rgba(22, 9, 52, 0.98) 52%, rgba(18, 8, 41, 1) 100%);
        }

        .word-search-layout {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 28px;
            padding: 12px 16px 20px;
        }

        .word-search-left {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            padding-left: 22px;
            min-width: 0;
        }

        .word-search-topline {
            width: min(520px, 100%);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            margin-top: 230px;
        }

        .word-search-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            background: rgba(255, 255, 255, 0.08);
            color: #f8fafc;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.18);
            font-weight: 700;
            font-size: 0.95rem;
        }

        .word-search-back:hover {
            background: rgba(255, 255, 255, 0.12);
        }

        .word-search-stats {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            color: rgba(244, 244, 255, 0.82);
            font-size: 1rem;
            font-weight: 600;
            text-align: center;
            flex: 1;
        }

        .word-search-stats strong {
            color: #f8fafc;
        }

        .word-search-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-shrink: 0;
        }

        .word-search-finish {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 46px;
            padding: 0 18px;
            border-radius: 14px;
            border: 1px solid transparent;
            background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%);
            color: #fff;
            box-shadow: 0 10px 20px rgba(245, 158, 11, 0.24);
            font-weight: 800;
            font-size: 0.95rem;
            transition: transform 0.2s ease, opacity 0.2s ease, filter 0.2s ease;
            text-decoration: none;
        }

        .word-search-finish:hover {
            transform: translateY(-1px);
        }

        .word-search-finish.is-disabled {
            opacity: 0.48;
            pointer-events: none;
            filter: grayscale(0.15);
        }

        .word-search-board-area {
            display: flex;
            align-items: flex-start;
            justify-content: flex-end;
            gap: 18px;
            padding-top: 8px;
        }

        .word-search-footer {
            display: flex;
            justify-content: flex-end;
            gap: 14px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .word-search-footer-group {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .word-search-board {
            display: grid;
            grid-template-columns: repeat(10, 1fr);
            gap: 2px;
            padding: 10px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.02);
            touch-action: none;
            user-select: none;
            -webkit-user-select: none;
            overscroll-behavior: contain;
        }

        .word-search-cell {
            width: 34px;
            height: 34px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 7px;
            border: 1px solid rgba(255, 255, 255, 0.05);
            background: rgba(34, 23, 69, 0.92);
            color: rgba(229, 231, 255, 0.84);
            font-weight: 800;
            font-size: 0.95rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.02);
            user-select: none;
            touch-action: none;
            -webkit-user-drag: none;
        }

        .word-search-cell.is-found {
            background: linear-gradient(180deg, rgba(45, 212, 191, 0.95), rgba(20, 184, 166, 0.92));
            color: #eafffb;
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.08), 0 0 18px rgba(20, 184, 166, 0.22);
        }

        .word-search-cell.is-selected {
            background: rgba(45, 212, 191, 0.22);
            border-color: rgba(45, 212, 191, 0.7);
            color: #d8fffb;
        }

        .word-search-panel {
            width: 248px;
            border-radius: 20px;
            padding: 18px 16px 16px;
            background: rgba(43, 31, 78, 0.96);
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: 0 18px 36px rgba(4, 1, 25, 0.32);
        }

        .word-search-panel h3 {
            margin: 0 0 14px;
            color: #f8fafc;
            font-size: 1rem;
            font-weight: 800;
        }

        .word-search-list {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10px;
        }

        .word-search-item {
            appearance: none;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
            border-radius: 11px;
            color: #cfc4eb;
            padding: 10px 11px;
            text-align: left;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 54px;
        }

        .word-search-item strong {
            display: block;
            font-size: 0.92rem;
            font-weight: 800;
            color: inherit;
            line-height: 1.1;
            margin-bottom: 4px;
        }

        .word-search-item small {
            display: block;
            font-size: 0.68rem;
            line-height: 1.15;
            color: rgba(255, 255, 255, 0.46);
        }

        .word-search-item:hover {
            border-color: rgba(45, 212, 191, 0.5);
            background: rgba(45, 212, 191, 0.08);
        }

        .word-search-item.is-active {
            border-color: rgba(45, 212, 191, 0.8);
            background: rgba(45, 212, 191, 0.12);
            color: #7ff9e8;
        }

        .word-search-item.is-found {
            border-color: rgba(45, 212, 191, 0.9);
            background: rgba(45, 212, 191, 0.14);
            color: #69e6d4;
        }

        .word-search-completion-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s ease;
        }

        .word-search-completion-modal.is-visible {
            opacity: 1;
            pointer-events: all;
        }

        .word-search-completion-card {
            position: relative;
            background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(59, 130, 246, 0.1) 100%);
            border: 2px solid rgba(139, 92, 246, 0.4);
            border-radius: 20px;
            padding: 40px 32px;
            max-width: 380px;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.3);
            animation: slideInCard 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        .word-search-completion-close {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
            font-size: 1.2rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: transform 0.2s ease, background 0.2s ease;
        }

        .word-search-completion-close:hover {
            transform: scale(1.05);
            background: rgba(255, 255, 255, 0.18);
        }

        @keyframes slideInCard {
            from {
                opacity: 0;
                transform: scale(0.8) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .word-search-completion-card h2 {
            margin: 0 0 8px;
            color: #f8fafc;
            font-size: 1.8rem;
            font-weight: 900;
            letter-spacing: -0.5px;
        }

        .word-search-completion-card .completion-emoji {
            font-size: 3rem;
            margin-bottom: 16px;
            display: block;
            animation: bounceEmoji 0.6s ease-in-out;
        }

        @keyframes bounceEmoji {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .word-search-completion-card p {
            color: rgba(244, 244, 255, 0.7);
            font-size: 0.95rem;
            line-height: 1.6;
            margin: 16px 0;
        }

        .word-search-completion-stats {
            background: rgba(45, 212, 191, 0.08);
            border-radius: 12px;
            padding: 16px;
            margin: 20px 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .word-search-completion-stat {
            color: #cfc4eb;
            font-size: 0.9rem;
        }

        .word-search-completion-stat strong {
            display: block;
            color: #7ff9e8;
            font-size: 1.3rem;
            font-weight: 800;
            margin-top: 4px;
        }

        .word-search-completion-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .word-search-completion-btn {
            flex: 1;
            padding: 12px 16px;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-block;
            font-size: 0.9rem;
        }

        .word-search-completion-btn.primary {
            background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%);
            color: #fff;
        }

        .word-search-completion-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(139, 92, 246, 0.4);
        }

        .word-search-completion-btn.secondary {
            background: rgba(255, 255, 255, 0.1);
            color: #cfc4eb;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .word-search-completion-btn.secondary:hover {
            background: rgba(255, 255, 255, 0.15);
        }

        body.is-embedded {
            height: 100vh;
            overflow: hidden;
            overscroll-behavior: none;
            background: linear-gradient(180deg, rgba(34, 13, 67, 0.98) 0%, rgba(22, 9, 52, 0.98) 52%, rgba(18, 8, 41, 1) 100%);
            background-image: none;
        }
        body.is-embedded .word-search-stage { min-height: auto; }
        body.is-embedded .word-search-layout {
            width: 100%;
            max-width: none;
            margin: 0;
            grid-template-columns: 1fr;
            gap: 16px;
            padding: 8px 10px;
            background: linear-gradient(180deg, rgba(34, 13, 67, 0.98) 0%, rgba(22, 9, 52, 0.98) 52%, rgba(18, 8, 41, 1) 100%);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            box-sizing: border-box;
        }
        body.is-embedded .word-search-left { justify-content: flex-start; align-items: flex-start; padding-left: 0; }
        body.is-embedded .word-search-topline { margin-top: 0; width: 100%; }
        body.is-embedded .word-search-board-area {
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: nowrap;
            gap: 14px;
            padding-top: 0;
        }
        body.is-embedded .word-search-board { transform: scale(1); transform-origin: top center; }
        /* Constrain panel width and center it to avoid touching right edge on small embeds */
        body.is-embedded .word-search-panel {
            width: 100%;
            max-width: 360px;
            box-sizing: border-box;
            margin: 0 auto;
        }
        body.is-embedded .word-search-cell { width: 50px; height: 50px; font-size: 0.82rem; }
        body.is-embedded .word-search-footer { margin-top: 12px; }
        body.is-embedded .word-search-completion-card { width: min(94vw, 420px); }

        @media (max-width: 820px) {
            .word-search-layout {
                grid-template-columns: 1fr;
                gap: 18px;
            }

            body.is-embedded .word-search-layout {
                min-height: 100dvh;
                padding: 10px 12px 16px;
            }

            .word-search-left {
                justify-content: center;
                padding-left: 0;
            }

            body.is-embedded .word-search-left {
                justify-content: flex-start;
            }

            .word-search-topline {
                margin-top: 18px;
                width: 100%;
                max-width: 900px;
            }

            body.is-embedded .word-search-topline {
                margin-top: 0;
                max-width: 100%;
            }

            .word-search-board-area {
                justify-content: center;
                flex-wrap: wrap;
            }

            body.is-embedded .word-search-board-area {
                width: 100%;
                flex-direction: column;
                align-items: stretch;
                justify-content: flex-start;
                gap: 12px;
            }


            body.is-embedded .word-search-board {
                width: 100%;
                max-width: 100%;
                justify-content: center;
            }

            body.is-embedded .word-search-panel {
                width: calc(100% - 24px);
                max-width: 360px;
                margin: 0 auto;
                padding: 12px;
                border-radius: 14px;
                box-sizing: border-box;
            }

            body.is-embedded .word-search-cell {
                width: 30px;
                height: 30px;
                font-size: 0.72rem;
            }
        }

        @media (max-width: 640px) {
            body.is-embedded {
                height: auto;
                min-height: 100dvh;
                overflow-y: auto;
                overscroll-behavior: contain;
            }

            .word-search-layout {
                padding-inline: 12px;
            }

            .word-search-topline {
                flex-direction: column;
                align-items: center;
                gap: 12px;
            }

            .word-search-stats {
                justify-content: flex-start;
                gap: 12px;
            }

            .word-search-board-area {
                gap: 12px;
            }

            body.is-embedded .word-search-layout {
                padding-inline: 3px;
            }

            body.is-embedded .word-search-cell {
                width: 27px;
                height: 27px;
                font-size: 0.68rem;
            }

            .word-search-panel {
                width: min(100%, 320px);
            }

            /* Move embedded footer closer to content on small screens */
            body.is-embedded .word-search-footer {
                margin-top: 10px;
                padding-inline: 10px;
            }

            body.is-embedded .word-search-footer .word-search-footer-group {
                justify-content: center;
                gap: 8px;
            }

            .word-search-cell {
                width: 28px;
                height: 28px;
                font-size: 0.82rem;
            }

            /* Reduce button sizes and ensure footer/buttons are visible on small embedded views */
            .word-search-back,
            .word-search-finish {
                padding: 8px 10px;
                font-size: 0.88rem;
                min-height: 40px;
                border-radius: 12px;
            }

            .word-search-footer {
                margin-top: 8px;
                padding-bottom: 8px;
                z-index: 20;
                position: sticky;
                bottom: 8px;
            }

            /* When embedded, add extra bottom padding so rounded container doesn't clip buttons */
            body.is-embedded .word-search-layout {
                padding-bottom: 84px;
            }

            /* Make footer buttons sit above background and centered */
            .word-search-footer .word-search-footer-group {
                gap: 8px;
                align-items: center;
            }

            .word-search-back,
            .word-search-finish {
                z-index: 30;
            }
        }
    </style>
</head>
<body class="dashboard-page word-search-page<?= $isEmbedded ? ' is-embedded' : ''; ?>">
<main class="guru-page student-page word-search-stage">
    <?php if (!$isEmbedded): ?>
        <button class="guru-mobile-toggle" type="button" data-guru-sidebar-toggle aria-label="Buka navigasi" aria-controls="studentSidebar" aria-expanded="false"><span></span><span></span><span></span></button>
        <div class="guru-sidebar-overlay" data-guru-sidebar-overlay></div>
        <aside class="guru-sidebar" id="studentSidebar">
            <div class="guru-brand"><a class="brand" href="index.php"><?= chemnama_icon('brand', '#d97706'); ?><span>Nom Comp</span></a><p>Panel Siswa</p><div class="guru-class-chip"><?= chemnama_e($studentClass); ?></div></div>
            <div class="guru-menu-block"><span class="guru-menu-title">MENU</span><nav class="guru-menu-list"><?php foreach ($siswaMenus as $menu): ?><a class="guru-menu-item <?= $menu['active'] ? 'is-active' : ''; ?>" href="<?= chemnama_e($menu['href']); ?>"><?= chemnama_icon($menu['icon'], $menu['active'] ? '#0f9d58' : '#6b7280'); ?><span><?= chemnama_e($menu['label']); ?></span></a><?php endforeach; ?></nav></div>
            <div class="guru-menu-block"><span class="guru-menu-title">NAVIGASI</span><nav class="guru-menu-list"><a class="guru-menu-item logout" href="logout.php"><?= chemnama_icon('logout', '#ef4444'); ?><span>Keluar</span></a></nav></div>
        </aside>
    <?php endif; ?>

    <section class="guru-content student-content word-search-layout">
        <div class="word-search-left">
            <div class="word-search-topline">
                <div class="word-search-stats">
                    <span>Ditemukan: <strong data-found-count>0</strong>/<strong data-total-count><?= count($boardWords); ?></strong></span>
                    <span>Waktu: <strong data-timer>00:00</strong></span>
                </div>
            </div>
        </div>

        <div class="word-search-board-area">
            <div class="word-search-board" data-word-board></div>

            <aside class="word-search-panel">
                <h3>Cari Kata:</h3>
                <div class="word-search-list" data-word-list>
                    <?php foreach ($boardWords as $wordIndex => $wordItem): ?>
                        <button type="button" class="word-search-item<?= $wordIndex === 0 ? ' is-active' : ''; ?>" data-word-card data-word-index="<?= (int) $wordIndex; ?>">
                            <strong><?= chemnama_e($wordItem['label']); ?></strong>
                            <small><?= chemnama_e($wordItem['hint']); ?></small>
                        </button>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>

        <div class="word-search-footer">
            <div class="word-search-footer-group">
                <a class="word-search-back" target="_top" href="<?= chemnama_e($returnUrl); ?>">← Kembali</a>
                <a class="word-search-finish is-disabled" target="_top" href="#" data-word-finish data-target-href="<?= chemnama_e($returnUrl); ?>" aria-disabled="true" tabindex="-1">Selesai</a>
            </div>
        </div>
    </section>

    <!-- Completion Modal -->
    <div class="word-search-completion-modal" data-completion-modal>
        <div class="word-search-completion-card">
            <button type="button" class="word-search-completion-close" data-completion-close aria-label="Tutup notifikasi">×</button>
            <span class="completion-emoji">🎉</span>
            <h2>Selamat!</h2>
            <p>Anda telah menyelesaikan game ini dengan sempurna!</p>
            <div class="word-search-completion-stats">
                <div class="word-search-completion-stat">
                    Waktu
                    <strong data-completion-time>00:00</strong>
                </div>
                <div class="word-search-completion-stat">
                    Kata
                    <strong data-completion-words>0/0</strong>
                </div>
            </div>
            <div class="word-search-completion-actions">
                <?php if ($isEmbedded): ?>
                    <a target="_top" href="<?= chemnama_e($materialUrl); ?>" class="word-search-completion-btn primary">Lanjut ke Materi</a>
                    <a target="_top" href="<?= chemnama_e($gamesUrl); ?>" class="word-search-completion-btn secondary">Ke Games Lain</a>
                <?php else: ?>
                    <a target="_top" href="<?= chemnama_e($gamesUrl); ?>" class="word-search-completion-btn primary">Kembali ke Games</a>
                    <a target="_top" href="<?= chemnama_e($materialUrl); ?>" class="word-search-completion-btn secondary">Buka Materi</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<script>
(() => {
    const wordItems = <?= json_encode($boardWords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const boardSize = 10;
    const boardElement = document.querySelector('[data-word-board]');
    const timerElement = document.querySelector('[data-timer]');
    const foundCountElement = document.querySelector('[data-found-count]');
    const cards = Array.from(document.querySelectorAll('[data-word-card]'));
    const finishButtons = Array.from(document.querySelectorAll('[data-word-finish]'));
    const completionModal = document.querySelector('[data-completion-modal]');
    const completionCloseButton = document.querySelector('[data-completion-close]');
    const alphabet = 'ABCDEFGHIKLMNOPQRSTUVWXYZ0123456789';
    const gameId = <?= (int) $game['id']; ?>;

    if (!boardElement || !timerElement || !foundCountElement || wordItems.length === 0) {
        return;
    }

    const board = Array.from({ length: boardSize }, () => Array(boardSize).fill(null));
    const placements = wordItems.map(() => []);
    const foundWords = [];
    const selectedCells = [];
    
    const savedStateKey = `wordSearchState_${gameId}`;
    localStorage.removeItem(savedStateKey);
    const savedState = null;
    const shouldReset = /(?:\?|&)reset=1(?:&|$)/.test(window.location.search);
    
    function saveBoardState() {
        const state = {
            board: board,
            placements: placements,
            foundWords: foundWords,
            timestamp: Date.now(),
        };
        localStorage.setItem(savedStateKey, JSON.stringify(state));
    }
    
    function loadBoardState(stateJson) {
        const state = JSON.parse(stateJson);
        for (let row = 0; row < boardSize; row += 1) {
            for (let col = 0; col < boardSize; col += 1) {
                board[row][col] = state.board[row][col];
            }
        }
        placements.length = 0;
        placements.push(...state.placements);
        foundWords.length = 0;
        foundWords.push(...state.foundWords);
    }
    let isDragging = false;
    let dragStartCell = null;
    let lastHoverCell = null;
    let isGameComplete = false;
    let timerInterval = null;
    let completionTime = '00:00';

    function setFinishButtonEnabled(enabled) {
        if (finishButtons.length === 0) {
            return;
        }

        finishButtons.forEach((finishButton) => {
            if (enabled) {
                finishButton.classList.remove('is-disabled');
                finishButton.removeAttribute('aria-disabled');
                finishButton.removeAttribute('tabindex');
                finishButton.href = finishButton.dataset.targetHref || finishButton.href;
            } else {
                finishButton.classList.add('is-disabled');
                finishButton.setAttribute('aria-disabled', 'true');
                finishButton.setAttribute('tabindex', '-1');
                finishButton.href = '#';
            }
        });
    }

    function hideCompletionModal() {
        completionModal?.classList.remove('is-visible');
    }

    completionCloseButton?.addEventListener('click', hideCompletionModal);
    completionModal?.addEventListener('click', (event) => {
        if (event.target === completionModal) {
            hideCompletionModal();
        }
    });

    const directions = [
        { row: 1, col: 0 },
        { row: 0, col: 1 },
    ];

    function randomChar() {
        return alphabet[Math.floor(Math.random() * alphabet.length)];
    }

    function canPlace(word, startRow, startCol, direction) {
        for (let index = 0; index < word.length; index += 1) {
            const row = startRow + direction.row * index;
            const col = startCol + direction.col * index;

            if (row < 0 || row >= boardSize || col < 0 || col >= boardSize) {
                return false;
            }

            const existing = board[row][col];
            if (existing !== null && existing !== word[index]) {
                return false;
            }
        }

        return true;
    }

    function placeWord(word, wordIndex) {
        const directionsToTry = wordIndex % 2 === 0 ? [directions[0], directions[1]] : [directions[1], directions[0]];

        for (const direction of directionsToTry) {
            const maxRow = direction.row === 1 ? boardSize - word.length : boardSize - 1;
            const maxCol = direction.col === 1 ? boardSize - word.length : boardSize - 1;

            if (maxRow < 0 || maxCol < 0) {
                continue;
            }

            for (let attempt = 0; attempt < 120; attempt += 1) {
                const startRow = Math.floor(Math.random() * (maxRow + 1));
                const startCol = Math.floor(Math.random() * (maxCol + 1));

                if (!canPlace(word, startRow, startCol, direction)) {
                    continue;
                }

                const positions = [];
                for (let index = 0; index < word.length; index += 1) {
                    const row = startRow + direction.row * index;
                    const col = startCol + direction.col * index;
                    board[row][col] = word[index];
                    positions.push({ row, col });
                }

                placements[wordIndex] = positions;
                return true;
            }
        }

        return false;
    }

    const sortableWords = wordItems
        .map((item, index) => ({ ...item, index }))
        .sort((left, right) => right.word.length - left.word.length);

    // Always generate a fresh board so the game starts empty on each visit
    sortableWords.forEach((item) => {
        placeWord(item.word, item.index);
    });

    for (let row = 0; row < boardSize; row += 1) {
        for (let col = 0; col < boardSize; col += 1) {
            if (board[row][col] === null) {
                board[row][col] = randomChar();
            }
        }
    }
    saveBoardState();
    
    // Clear reset parameter from URL
    if (shouldReset) {
        window.history.replaceState({}, document.title, window.location.pathname + '?id=' + gameId);
    }

    boardElement.innerHTML = '';
    for (let row = 0; row < boardSize; row += 1) {
        for (let col = 0; col < boardSize; col += 1) {
            const cell = document.createElement('div');
            cell.className = 'word-search-cell';
            cell.textContent = board[row][col];
            cell.dataset.row = String(row);
            cell.dataset.col = String(col);

            cell.addEventListener('mousedown', (event) => {
                isDragging = true;
                const position = getCellPosition(cell);
                dragStartCell = position;
                lastHoverCell = position;
                applySelection([position]);
                event.preventDefault();
            });

            cell.addEventListener('mouseenter', () => {
                if (!isDragging || !dragStartCell) {
                    return;
                }

                const currentCell = getCellPosition(cell);
                if (!currentCell) {
                    return;
                }

                lastHoverCell = currentCell;
                const path = buildSelectionPath(dragStartCell, currentCell);
                if (path.length > 0) {
                    applySelection(path);
                }
            });

            cell.addEventListener('mouseup', (event) => {
                finishDrag(event);
            });

            boardElement.appendChild(cell);
        }
    }

    function clearSelection() {
        selectedCells.length = 0;

        boardElement.querySelectorAll('.word-search-cell.is-selected').forEach((cell) => {
            cell.classList.remove('is-selected');
        });
    }

    function resetDragState() {
        dragStartCell = null;
        lastHoverCell = null;
    }

    function positionKey(row, col) {
        return `${row}-${col}`;
    }

    function getCellPosition(cell) {
        if (!cell) {
            return null;
        }

        return {
            row: Number(cell.dataset.row),
            col: Number(cell.dataset.col),
        };
    }

    function getCellFromPointer(event) {
        const element = document.elementFromPoint(event.clientX, event.clientY);
        if (!element) {
            return null;
        }

        const cell = element.closest('.word-search-cell');
        return cell ? getCellPosition(cell) : null;
    }

    function applySelection(cellPositions) {
        clearSelection();

        cellPositions.forEach((cellPosition) => {
            const cell = boardElement.querySelector(`[data-row="${cellPosition.row}"][data-col="${cellPosition.col}"]`);
            if (cell) {
                cell.classList.add('is-selected');
                selectedCells.push(cellPosition);
            }
        });
    }

    function buildSelectionPath(fromCell, toCell) {
        if (!fromCell || !toCell) {
            return [];
        }

        const rowDelta = toCell.row - fromCell.row;
        const colDelta = toCell.col - fromCell.col;
        const steps = Math.max(Math.abs(rowDelta), Math.abs(colDelta));

        if (steps === 0) {
            return [fromCell];
        }

        if (rowDelta !== 0 && colDelta !== 0) {
            return [];
        }

        if (Math.abs(rowDelta) > 0 && Math.abs(colDelta) > 0) {
            return [];
        }

        const direction = {
            row: rowDelta === 0 ? 0 : rowDelta / Math.abs(rowDelta),
            col: colDelta === 0 ? 0 : colDelta / Math.abs(colDelta),
        };

        const path = [];
        for (let index = 0; index <= steps; index += 1) {
            path.push({
                row: fromCell.row + (direction.row * index),
                col: fromCell.col + (direction.col * index),
            });
        }

        return path;
    }

    function cellsMatchPath(path, placement) {
        if (path.length !== placement.length) {
            return false;
        }

        const forwardMatch = path.every((position, index) => position.row === placement[index].row && position.col === placement[index].col);
        if (forwardMatch) {
            return true;
        }

        const reversed = placement.slice().reverse();
        return path.every((position, index) => position.row === reversed[index].row && position.col === reversed[index].col);
    }

    function findMatchingWord(path) {
        return placements.findIndex((placement) => cellsMatchPath(path, placement));
    }

    function render() {
        const totalFound = foundWords.length;
        foundCountElement.textContent = String(totalFound);

        cards.forEach((card) => {
            const index = Number(card.dataset.wordIndex);
            const isFound = foundWords.indexOf(index) !== -1;
            card.classList.toggle('is-found', isFound);
            card.classList.toggle('is-active', isFound);
        });

        placements.forEach((positions, index) => {
            positions.forEach((position) => {
                const cell = boardElement.querySelector(`[data-row="${position.row}"][data-col="${position.col}"]`);
                if (cell) {
                    cell.classList.toggle('is-found', foundWords.indexOf(index) !== -1);
                }
            });
        });

        boardElement.querySelectorAll('.word-search-cell').forEach((cell) => {
            const row = Number(cell.dataset.row);
            const col = Number(cell.dataset.col);
            const isSelected = selectedCells.some((selectedCell) => selectedCell.row === row && selectedCell.col === col);
            cell.classList.toggle('is-selected', isSelected);
        });

        // Check if game is complete
        if (totalFound === wordItems.length && !isGameComplete) {
            isGameComplete = true;
            showCompletionModal();
        }

        setFinishButtonEnabled(isGameComplete);
    }

    function commitSelection(path) {
        if (path.length === 0) {
            clearSelection();
            render();
            return;
        }

        const selectedWord = path.map((position) => board[position.row][position.col]).join('');
        const reversedWord = selectedWord.split('').reverse().join('');
        const matchedWordIndex = wordItems.findIndex((item) => item.word === selectedWord || item.word === reversedWord);

        if (matchedWordIndex !== -1 && foundWords.indexOf(matchedWordIndex) === -1) {
            foundWords.push(matchedWordIndex);
            saveBoardState();
            cards.forEach((card) => {
                const cardIndex = Number(card.dataset.wordIndex);
                if (cardIndex === matchedWordIndex) {
                    card.classList.add('is-active');
                }
            });
        }

        render();
    }

    boardElement.addEventListener('pointerdown', (event) => {
        const cell = event.target.closest('.word-search-cell');
        if (!cell) {
            return;
        }

        isDragging = true;
        const position = getCellPosition(cell);
        dragStartCell = position;
        lastHoverCell = position;
        applySelection([position]);
        boardElement.setPointerCapture?.(event.pointerId);
        event.preventDefault();
    });

    boardElement.addEventListener('pointerenter', (event) => {
        if (!isDragging) {
            return;
        }

        const cell = event.target.closest('.word-search-cell');
        if (!cell) {
            return;
        }

        const currentCell = getCellPosition(cell);
        if (!dragStartCell || !currentCell) {
            return;
        }

        lastHoverCell = currentCell;
        const path = buildSelectionPath(dragStartCell, currentCell);
        if (path.length === 0) {
            return;
        }

        applySelection(path);
    });

    boardElement.addEventListener('pointermove', (event) => {
        if (!isDragging) {
            return;
        }

        const currentCell = getCellFromPointer(event);
        if (!currentCell) {
            return;
        }

        if (!dragStartCell || !currentCell) {
            return;
        }

        lastHoverCell = currentCell;
        const path = buildSelectionPath(dragStartCell, currentCell);
        if (path.length === 0) {
            return;
        }

        applySelection(path);
    });

    function finishDrag(event) {
        if (!isDragging) {
            return;
        }

        isDragging = false;
        const targetCell = event && event.target && typeof event.target.closest === 'function' ? event.target.closest('.word-search-cell') : null;
        const endCell = getCellPosition(targetCell) || (event ? getCellFromPointer(event) : null) || lastHoverCell || dragStartCell;
        const path = buildSelectionPath(dragStartCell, endCell);
        commitSelection(path);
        clearSelection();
        resetDragState();
        render();
    }

    boardElement.addEventListener('pointerup', finishDrag);
    boardElement.addEventListener('pointercancel', finishDrag);
    window.addEventListener('pointerup', finishDrag);

    boardElement.addEventListener('mousedown', (event) => {
        const cell = event.target.closest('.word-search-cell');
        if (!cell) {
            return;
        }

        isDragging = true;
        const position = getCellPosition(cell);
        dragStartCell = position;
        lastHoverCell = position;
        applySelection([position]);
        event.preventDefault();
    });

    boardElement.addEventListener('mousemove', (event) => {
        if (!isDragging) {
            return;
        }

        const currentCell = getCellFromPointer(event);
        if (!currentCell || !dragStartCell) {
            return;
        }

        lastHoverCell = currentCell;
        const path = buildSelectionPath(dragStartCell, currentCell);
        if (path.length === 0) {
            return;
        }

        applySelection(path);
    });

    window.addEventListener('mouseup', (event) => {
        finishDrag(event);
    });

    render();

    function showCompletionModal() {
        // Stop timer
        if (timerInterval) {
            clearInterval(timerInterval);
        }

        // Get current time
        completionTime = timerElement.textContent;

        // Show modal
        const modal = document.querySelector('[data-completion-modal]');
        if (modal) {
            document.querySelector('[data-completion-time]').textContent = completionTime;
            document.querySelector('[data-completion-words]').textContent = `${foundWords.length}/${wordItems.length}`;
            modal.classList.add('is-visible');
        }

        setFinishButtonEnabled(true);

        // Save completion time to localStorage
        const state = JSON.parse(localStorage.getItem(`wordSearchState_${gameId}`) || '{}');
        state.completedAt = Date.now();
        state.completionTime = completionTime;
        localStorage.setItem(`wordSearchState_${gameId}`, JSON.stringify(state));

        const completionSeconds = Math.floor((Date.now() - startedAt) / 1000);
        fetch('api_record_game_completion.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                game_type: 'word_search',
                game_id: gameId,
                completion_time: completionSeconds
            })
        }).catch(() => {});
    }

    const startedAt = Date.now();
    timerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - startedAt) / 1000);
        const minutes = String(Math.floor(elapsed / 60)).padStart(2, '0');
        const seconds = String(elapsed % 60).padStart(2, '0');
        timerElement.textContent = `${minutes}:${seconds}`;
    }, 1000);
})();
</script>
</body>
</html>

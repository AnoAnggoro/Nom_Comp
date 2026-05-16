<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('siswa');

$user = chemnama_current_user();
$userId = (int) ($user['id'] ?? 0);
$classStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$classStmt->execute(['user_id' => $userId]);
$studentClass = (string) ($classStmt->fetchColumn() ?: 'X IPA 1');

chemnama_ensure_chem_match_reads_table($pdo);

$gameId = (int) ($_GET['id'] ?? 0);
if ($gameId <= 0) {
    $firstStmt = $pdo->prepare('SELECT id FROM chem_match_games WHERE is_published = 1 AND (class_name IS NULL OR class_name = :kelas) ORDER BY created_at DESC LIMIT 1');
    $firstStmt->execute(['kelas' => $studentClass]);
    $gameId = (int) $firstStmt->fetchColumn();
}

$gameStmt = $pdo->prepare('SELECT g.*, mo.badge AS module_badge, mo.title AS module_title, u.name AS teacher_name FROM chem_match_games g JOIN modules mo ON mo.id = g.module_id JOIN users u ON u.id = g.created_by WHERE g.id = :id AND g.is_published = 1 LIMIT 1');
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

chemnama_mark_chem_match_as_read($pdo, (int) $game['id'], $userId);
$isRead = chemnama_is_chem_match_read($pdo, (int) $game['id'], $userId);

$relatedStmt = $pdo->prepare('SELECT id, left_term, right_term FROM chem_match_games WHERE module_id = :module_id AND is_published = 1 AND id <> :id ORDER BY created_at DESC LIMIT 6');
$relatedStmt->execute(['module_id' => (int) $game['module_id'], 'id' => (int) $game['id']]);
$relatedGames = $relatedStmt->fetchAll();

$matchGames = array_slice(array_merge([$game], $relatedGames), 0, 6);
$leftChoices = [];
$rightChoices = [];

foreach ($matchGames as $index => $matchGame) {
    $leftTerm = trim((string) ($matchGame['left_term'] ?? ''));
    $rightTerm = trim((string) ($matchGame['right_term'] ?? ''));

    if ($leftTerm === '' || $rightTerm === '') {
        continue;
    }

    $leftChoices[] = [
        'index' => $index,
        'label' => $leftTerm,
    ];

    $rightChoices[] = [
        'index' => $index,
        'label' => $rightTerm,
    ];
}

$shuffledLeftChoices = $leftChoices;
$shuffledRightChoices = $rightChoices;
shuffle($shuffledLeftChoices);
shuffle($shuffledRightChoices);

$pairCount = count($leftChoices);

$siswaMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Materi', 'icon' => 'file', 'href' => 'siswa_materi.php', 'active' => false],
    // ['label' => 'Games', 'icon' => 'beaker', 'href' => 'siswa_games.php', 'active' => true],
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
    <title>Tarik Garis - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body.chem-match-page { overflow-x: hidden; }
        .chem-match-stage { min-height: 100vh; background: linear-gradient(180deg, rgba(34, 13, 67, 0.98) 0%, rgba(22, 9, 52, 0.98) 52%, rgba(18, 8, 41, 1) 100%); }
        .chem-match-page-shell { padding: 18px 20px 24px; }
        .chem-match-topbar { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 16px; }
        .chem-match-branding { display: flex; align-items: flex-start; gap: 14px; }
        .chem-match-logo { width: 58px; height: 58px; border-radius: 18px; display: inline-flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%); color: #fff; font-weight: 900; font-size: 1rem; box-shadow: 0 10px 24px rgba(245, 158, 11, 0.28); flex-shrink: 0; }
        .chem-match-branding h1 { margin: 0 0 4px; color: #f8fafc; font-size: 1.46rem; line-height: 1.2; font-weight: 800; letter-spacing: -0.4px; }
        .chem-match-branding p { margin: 0; color: rgba(244, 244, 255, 0.68); font-size: 0.95rem; }
        .chem-match-topmeta, .chem-match-stats { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .chem-match-topmeta { margin-bottom: 16px; }
        .chem-match-stats { margin-bottom: 18px; }
        .chem-match-pill, .chem-match-count, .chem-match-stat { display: inline-flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 14px; border: 1px solid rgba(255, 255, 255, 0.08); background: rgba(255, 255, 255, 0.05); color: rgba(244, 244, 255, 0.86); font-size: 0.95rem; font-weight: 700; }
        .chem-match-pill { color: #fbbf24; background: rgba(251, 191, 36, 0.12); border-color: rgba(251, 191, 36, 0.22); }
        .chem-match-stat strong { color: #f8fafc; font-size: 1.05rem; }
        .chem-match-board-shell { position: relative; padding: 20px 16px 18px; border-radius: 22px; background: rgba(30, 17, 56, 0.9); border: 1px solid rgba(255, 255, 255, 0.08); box-shadow: 0 20px 40px rgba(2, 1, 10, 0.28); overflow: hidden; min-height: 420px; }
        .chem-match-lines { position: absolute; inset: 0; width: 100%; height: 100%; pointer-events: none; z-index: 1; }
        .chem-match-columns { position: relative; z-index: 2; display: grid; grid-template-columns: 1fr 56px 1fr; gap: 28px; align-items: start; }
        .chem-match-column { display: flex; flex-direction: column; gap: 0; }
        .chem-match-gap { min-height: 100%; }
        .chem-match-item { width: 100%; min-height: 66px; display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: 18px; border: 1px solid rgba(255, 255, 255, 0.08); background: rgba(255, 255, 255, 0.02); color: #e8e6f2; font-weight: 800; font-size: 1.02rem; box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.02); cursor: pointer; transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease; position: relative; }
        .chem-match-item.is-left { justify-content: flex-end; text-align: left; flex-direction: row; }
        .chem-match-item.is-right { justify-content: flex-start; text-align: left; }
        .chem-match-item:hover { transform: translateY(-1px); border-color: rgba(45, 212, 191, 0.3); background: rgba(45, 212, 191, 0.04); }
        .chem-match-item.is-selected { border-color: rgba(168, 85, 247, 0.85); background: rgba(168, 85, 247, 0.1); box-shadow: 0 0 0 1px rgba(168, 85, 247, 0.18), 0 0 18px rgba(168, 85, 247, 0.14); }
        .chem-match-item.is-matched { border-color: rgba(45, 212, 191, 0.82); background: rgba(45, 212, 191, 0.1); color: #dffdf7; }
        .chem-match-item.is-wrong { border-color: rgba(248, 113, 113, 0.85); background: rgba(248, 113, 113, 0.1); animation: wrongPulse 0.28s ease-in-out 2; }
        @keyframes wrongPulse { 0%, 100% { transform: translateX(0); } 50% { transform: translateX(4px); } }
        .chem-match-dot { position: relative; width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0; border: 3px solid rgba(168, 85, 247, 0.95); background: rgba(168, 85, 247, 0.25); box-shadow: 0 0 0 1px rgba(168, 85, 247, 0.18), 0 0 16px rgba(168, 85, 247, 0.3); transition: all 0.25s ease; }
        .chem-match-item.is-selected .chem-match-dot { border-color: rgba(168, 85, 247, 1); background: rgba(168, 85, 247, 0.42); box-shadow: 0 0 0 4px rgba(168, 85, 247, 0.12), 0 0 20px rgba(168, 85, 247, 0.4); animation: dotBreath 1.2s ease-in-out infinite; }
        .chem-match-item.is-matched .chem-match-dot { border-color: rgba(45, 212, 191, 1); background: rgba(45, 212, 191, 0.88); box-shadow: 0 0 0 4px rgba(45, 212, 191, 0.16), 0 0 18px rgba(45, 212, 191, 0.52); animation: dotGlow 1.6s ease-in-out infinite; }
        @keyframes dotBreath { 0%,100% { transform: scale(1); } 50% { transform: scale(1.08); } }
        @keyframes dotGlow { 0%,100% { box-shadow: 0 0 0 4px rgba(45, 212, 191, 0.16), 0 0 18px rgba(45, 212, 191, 0.52); } 50% { box-shadow: 0 0 0 6px rgba(45, 212, 191, 0.22), 0 0 24px rgba(45, 212, 191, 0.66); } }
        .chem-match-footer { display: flex; justify-content: flex-end; gap: 14px; margin-top: 20px; flex-wrap: wrap; }
        .chem-match-footer-group { display: inline-flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .chem-match-action { min-width: 148px; padding: 14px 18px; border-radius: 14px; text-align: center; font-weight: 800; font-size: 0.98rem; text-decoration: none; border: 1px solid transparent; transition: all 0.2s ease; }
        .chem-match-action.is-secondary { color: #ddd6fe; background: rgba(255, 255, 255, 0.08); border-color: rgba(255, 255, 255, 0.1); }
        .chem-match-action.is-primary { color: #fff; background: linear-gradient(135deg, #f59e0b 0%, #f97316 100%); box-shadow: 0 10px 20px rgba(245, 158, 11, 0.24); }
        .chem-match-action:hover { transform: translateY(-1px); }
        .chem-match-action.is-disabled { opacity: 0.48; pointer-events: none; filter: grayscale(0.15); }
        .chem-match-completion-modal { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.58); display: flex; align-items: center; justify-content: center; opacity: 0; pointer-events: none; transition: opacity 0.25s ease; z-index: 9999; }
        .chem-match-completion-modal.is-visible { opacity: 1; pointer-events: auto; }
        .chem-match-completion-card { position: relative; width: min(92vw, 420px); padding: 34px 30px 28px; border-radius: 22px; background: linear-gradient(135deg, rgba(139, 92, 246, 0.18) 0%, rgba(59, 130, 246, 0.12) 100%); border: 1px solid rgba(139, 92, 246, 0.32); box-shadow: 0 24px 50px rgba(0, 0, 0, 0.34); text-align: center; animation: modalPop 0.42s cubic-bezier(0.34, 1.56, 0.64, 1); }
        .chem-match-completion-close { position: absolute; top: 12px; right: 12px; width: 34px; height: 34px; border: 0; border-radius: 50%; background: rgba(255, 255, 255, 0.1); color: #fff; font-size: 1.2rem; line-height: 1; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; transition: transform 0.2s ease, background 0.2s ease; }
        .chem-match-completion-close:hover { transform: scale(1.05); background: rgba(255, 255, 255, 0.18); }
        @keyframes modalPop { from { opacity: 0; transform: scale(0.86) translateY(-16px); } to { opacity: 1; transform: scale(1) translateY(0); } }
        .chem-match-completion-emoji { display: block; margin-bottom: 14px; font-size: 3rem; animation: emojiBounce 0.6s ease-in-out; }
        @keyframes emojiBounce { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
        .chem-match-completion-card h2 { margin: 0 0 8px; color: #f8fafc; font-size: 1.8rem; font-weight: 900; }
        .chem-match-completion-card p { margin: 0 0 18px; color: rgba(244, 244, 255, 0.74); line-height: 1.6; }
        .chem-match-completion-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 22px; }
        .chem-match-completion-stat { padding: 14px 12px; border-radius: 14px; background: rgba(45, 212, 191, 0.08); color: #cfc4eb; font-size: 0.88rem; }
        .chem-match-completion-stat strong { display: block; margin-top: 4px; color: #7ff9e8; font-size: 1.24rem; font-weight: 900; }
        .chem-match-completion-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .chem-match-completion-btn { flex: 1; min-width: 140px; padding: 13px 16px; border-radius: 12px; text-decoration: none; text-align: center; font-weight: 800; font-size: 0.94rem; }
        .chem-match-completion-btn.is-primary { color: #fff; background: linear-gradient(135deg, #8b5cf6 0%, #6366f1 100%); }
        .chem-match-completion-btn.is-secondary { color: #ddd6fe; background: rgba(255, 255, 255, 0.08); border: 1px solid rgba(255, 255, 255, 0.14); }
        body.is-embedded {
            height: auto;
            min-height: 100dvh;
            overflow-y: auto;
            overflow-x: hidden;
            overscroll-behavior: contain;
        }
        body.is-embedded .chem-match-stage { min-height: auto; }
        body.is-embedded .chem-match-page-shell {
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 12px 16px 18px;
            background: linear-gradient(180deg, rgba(34, 13, 67, 0.98) 0%, rgba(22, 9, 52, 0.98) 52%, rgba(18, 8, 41, 1) 100%);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 20px;
            box-sizing: border-box;
        }
        body.is-embedded .chem-match-topbar, body.is-embedded .chem-match-topmeta, body.is-embedded .chem-match-stats, body.is-embedded .chem-match-footer { margin-bottom: 12px; }
        body.is-embedded .chem-match-board-shell { width: 100%; padding: 12px; box-sizing: border-box; }
        body.is-embedded .chem-match-columns { display: flex; gap: 10px; }
        body.is-embedded .chem-match-column { flex: 1; min-width: 0; }
        body.is-embedded .chem-match-gap { display: none; }
        body.is-embedded .chem-match-item { min-height: 52px; padding: 10px 12px; font-size: 0.9rem; }
        body.is-embedded .chem-match-dot { width: 22px; height: 22px; }
        body.is-embedded .chem-match-completion-card { width: min(94vw, 420px); }
        body.is-embedded .chem-match-footer {
            position: sticky;
            bottom: 8px;
            z-index: 20;
        }
        body.is-embedded .chem-match-footer-group {
            width: 100%;
            justify-content: center;
        }
        body.is-embedded .chem-match-action {
            min-width: 0;
            flex: 0 0 auto;
        }
        @media (max-width: 1024px) { .chem-match-columns { grid-template-columns: 1fr 88px 1fr; gap: 18px; } }
        @media (max-width: 820px) { .chem-match-page-shell { padding-inline: 14px; } .chem-match-topbar { flex-direction: column; } .chem-match-board-shell { padding: 16px 12px 14px; } .chem-match-columns { grid-template-columns: 1fr; gap: 12px; } .chem-match-gap { display: none; } .chem-match-item { min-height: 60px; font-size: 0.95rem; } .chem-match-item.is-left, .chem-match-item.is-right { justify-content: space-between; flex-direction: row; text-align: left; } .chem-match-footer { justify-content: stretch; } .chem-match-action { flex: 1 1 0; } }
        @media (max-width: 640px) {
            .chem-match-page-shell { padding-top: 14px; padding-bottom: 88px; }
            .chem-match-logo { width: 52px; height: 52px; border-radius: 16px; }
            .chem-match-branding h1 { font-size: 1.2rem; }
            .chem-match-branding p { font-size: 0.88rem; }
            .chem-match-item { padding: 11px 12px; border-radius: 16px; }
            .chem-match-dot { width: 24px; height: 24px; }
            .chem-match-completion-card { padding: 28px 20px 22px; }

            .chem-match-action {
                padding: 8px 10px;
                font-size: 0.88rem;
                min-height: 40px;
                border-radius: 12px;
                min-width: 0;
            }

            body.is-embedded .chem-match-page-shell {
                padding-bottom: 88px;
            }

            body.is-embedded .chem-match-board-shell {
                width: calc(100% - 10px);
                max-width: 100%;
                margin: 0 auto;
                padding: 10px;
                min-height: 0;
                box-sizing: border-box;
            }

            body.is-embedded .chem-match-columns {
                gap: 10px;
            }

            body.is-embedded .chem-match-footer {
                bottom: 6px;
                margin-top: 10px;
            }

            body.is-embedded .chem-match-footer-group {
                gap: 8px;
               margin-left: 90px;
            }
        }
    </style>
</head>
<body class="dashboard-page chem-match-page<?= $isEmbedded ? ' is-embedded' : ''; ?>">
<main class="guru-page student-page chem-match-stage">
    <?php if (!$isEmbedded): ?>
        <button class="guru-mobile-toggle" type="button" data-guru-sidebar-toggle aria-label="Buka navigasi" aria-controls="studentSidebar" aria-expanded="false"><span></span><span></span><span></span></button>
        <div class="guru-sidebar-overlay" data-guru-sidebar-overlay></div>
        <aside class="guru-sidebar" id="studentSidebar">
            <div class="guru-brand"><a class="brand" href="index.php"><?= chemnama_icon('brand', '#d97706'); ?><span>Nom Comp</span></a><p>Panel Siswa</p><div class="guru-class-chip"><?= chemnama_e($studentClass); ?></div></div>
            <div class="guru-menu-block"><span class="guru-menu-title">MENU</span><nav class="guru-menu-list"><?php foreach ($siswaMenus as $menu): ?><a class="guru-menu-item <?= $menu['active'] ? 'is-active' : ''; ?>" href="<?= chemnama_e($menu['href']); ?>"><?= chemnama_icon($menu['icon'], $menu['active'] ? '#0f9d58' : '#6b7280'); ?><span><?= chemnama_e($menu['label']); ?></span></a><?php endforeach; ?></nav></div>
            <div class="guru-menu-block"><span class="guru-menu-title">NAVIGASI</span><nav class="guru-menu-list"><a class="guru-menu-item logout" href="logout.php"><?= chemnama_icon('logout', '#ef4444'); ?><span>Keluar</span></a></nav></div>
        </aside>
    <?php endif; ?>

    <section class="guru-content student-content chem-match-page-shell">
        <div class="chem-match-stats">
            <span class="chem-match-stat">⏱️ <strong data-match-timer>00:00</strong></span>
            <span class="chem-match-stat">☝ <strong data-match-found>0</strong></span>
        </div>

        <div class="chem-match-board-shell" data-match-board-shell>
            <svg class="chem-match-lines" data-match-lines></svg>
            <div class="chem-match-columns">
                <div class="chem-match-column" data-match-left>
                    <?php foreach ($shuffledLeftChoices as $choice): ?>
                        <button type="button" class="chem-match-item is-left" data-match-item data-side="left" data-pair-index="<?= (int) $choice['index']; ?>">
                            <span><?= chemnama_e($choice['label']); ?></span>
                            <span class="chem-match-dot" aria-hidden="true"></span>
                        </button>
                    <?php endforeach; ?>
                </div>
                <div class="chem-match-gap" aria-hidden="true"></div>
                <div class="chem-match-column" data-match-right>
                    <?php foreach ($shuffledRightChoices as $choice): ?>
                        <button type="button" class="chem-match-item is-right" data-match-item data-side="right" data-pair-index="<?= (int) $choice['index']; ?>">
                            <span class="chem-match-dot" aria-hidden="true"></span>
                            <span><?= chemnama_e($choice['label']); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="chem-match-footer">
            <?php if ($isEmbedded): ?>
                <div class="chem-match-footer-group">
                    <a class="chem-match-action is-secondary" target="_top" href="<?= chemnama_e($materialUrl); ?>">← Kembali</a>
                    <a class="chem-match-action is-primary is-disabled" target="_top" href="#" data-match-finish data-target-href="<?= chemnama_e($materialUrl); ?>" aria-disabled="true" tabindex="-1">Selesai</a>
                </div>
            <?php else: ?>
                <div class="chem-match-footer-group">
                    <a class="chem-match-action is-secondary" target="_top" href="<?= chemnama_e($gamesUrl); ?>">← Kembali</a>
                    <a class="chem-match-action is-primary is-disabled" target="_top" href="#" data-match-finish data-target-href="<?= chemnama_e($gamesUrl); ?>" aria-disabled="true" tabindex="-1">Selesai</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <div class="chem-match-completion-modal" data-match-completion-modal>
        <div class="chem-match-completion-card">
            <button type="button" class="chem-match-completion-close" data-match-completion-close aria-label="Tutup notifikasi">×</button>
            <span class="chem-match-completion-emoji">🎉</span>
            <h2>Selamat!</h2>
            <p>Semua pasangan sudah berhasil dicocokkan.</p>
            <div class="chem-match-completion-stats">
                <div class="chem-match-completion-stat">Waktu<strong data-match-completion-time>00:00</strong></div>
                <div class="chem-match-completion-stat">Pasangan<strong data-match-completion-count>0/0</strong></div>
            </div>
            <div class="chem-match-completion-actions">
                <?php if ($isEmbedded): ?>
                    <a class="chem-match-completion-btn is-primary" target="_top" href="<?= chemnama_e($materialUrl); ?>">Lanjut ke Materi</a>
                    <a class="chem-match-completion-btn is-secondary" target="_top" href="<?= chemnama_e($gamesUrl); ?>">Ke Games Lain</a>
                <?php else: ?>
                    <a class="chem-match-completion-btn is-primary" target="_top" href="<?= chemnama_e($gamesUrl); ?>">Kembali ke Games</a>
                    <a class="chem-match-completion-btn is-secondary" target="_top" href="<?= chemnama_e($materialUrl); ?>">Buka Materi</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<script>
(() => {
    const leftButtons = Array.from(document.querySelectorAll('[data-match-item][data-side="left"]'));
    const rightButtons = Array.from(document.querySelectorAll('[data-match-item][data-side="right"]'));
    const boardShell = document.querySelector('[data-match-board-shell]');
    const linesSvg = document.querySelector('[data-match-lines]');
    const timerElement = document.querySelector('[data-match-timer]');
    const foundElement = document.querySelector('[data-match-found]');
    const completionModal = document.querySelector('[data-match-completion-modal]');
    const completionCloseButton = document.querySelector('[data-match-completion-close]');
    const completionTimeElement = document.querySelector('[data-match-completion-time]');
    const completionCountElement = document.querySelector('[data-match-completion-count]');
    const finishButtons = Array.from(document.querySelectorAll('[data-match-finish]'));
    const pairCount = <?= (int) $pairCount; ?>;
    const gameId = <?= (int) $game['id']; ?>;

    if (!boardShell || !linesSvg || !timerElement || !foundElement || leftButtons.length === 0 || rightButtons.length === 0) {
        return;
    }

    const matchedPairs = [];
    let selected = null;
    let isComplete = false;
    const matchKey = `chemMatchState_${gameId}`;
    const shouldReset = /(?:\?|&)reset=1(?:&|$)/.test(window.location.search);

    localStorage.removeItem(matchKey);

    const savedState = null;
    const startAt = Date.now();
    let timerInterval = null;

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

    setFinishButtonEnabled(matchedPairs.length === pairCount && pairCount > 0);

    function hideCompletionModal() {
        completionModal?.classList.remove('is-visible');
    }

    completionCloseButton?.addEventListener('click', hideCompletionModal);
    completionModal?.addEventListener('click', (event) => {
        if (event.target === completionModal) {
            hideCompletionModal();
        }
    });

    function saveState(extra = {}) {
        localStorage.setItem(matchKey, JSON.stringify({ startedAt: startAt, matchedPairs: matchedPairs.slice(), ...extra }));
    }

    function getItem(side, pairIndex) {
        return document.querySelector(`[data-match-item][data-side="${side}"][data-pair-index="${pairIndex}"]`);
    }

    function markWrong(leftIndex, rightIndex) {
        [getItem('left', leftIndex), getItem('right', rightIndex)].forEach((item) => item && item.classList.add('is-wrong'));
        window.setTimeout(() => {
            [getItem('left', leftIndex), getItem('right', rightIndex)].forEach((item) => item && item.classList.remove('is-wrong'));
        }, 420);
    }

    function completeMatch(pairIndex) {
        if (!matchedPairs.includes(pairIndex)) {
            matchedPairs.push(pairIndex);
        }
        selected = null;
        saveState();
        render();

        if (matchedPairs.length === pairCount && !isComplete) {
            isComplete = true;
            window.clearInterval(timerInterval);
            const finalTime = timerElement.textContent;
            completionTimeElement.textContent = finalTime;
            completionCountElement.textContent = `${matchedPairs.length}/${pairCount}`;
            completionModal.classList.add('is-visible');
            saveState({ completedAt: Date.now(), completionTime: finalTime });
            setFinishButtonEnabled(true);

            const completionSeconds = Math.floor((Date.now() - startAt) / 1000);
            fetch('api_record_game_completion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    game_type: 'chem_match',
                    game_id: gameId,
                    completion_time: completionSeconds
                })
            }).catch(() => {});
        }
    }

    function handleItemClick(event) {
        const button = event.currentTarget;
        const side = button.dataset.side;
        const pairIndex = Number(button.dataset.pairIndex);

        if (matchedPairs.includes(pairIndex)) {
            return;
        }

        if (!selected) {
            selected = { side, pairIndex };
            render();
            return;
        }

        if (selected.side === side) {
            selected = { side, pairIndex };
            render();
            return;
        }

        const leftIndex = selected.side === 'left' ? selected.pairIndex : pairIndex;
        const rightIndex = selected.side === 'right' ? selected.pairIndex : pairIndex;

        if (leftIndex === rightIndex) {
            completeMatch(leftIndex);
        } else {
            markWrong(leftIndex, rightIndex);
            selected = null;
            render();
        }
    }

    function drawLines() {
        const shellRect = boardShell.getBoundingClientRect();
        linesSvg.setAttribute('viewBox', `0 0 ${shellRect.width} ${shellRect.height}`);
        linesSvg.innerHTML = '';

        matchedPairs.forEach((pairIndex) => {
            const leftItem = getItem('left', pairIndex);
            const rightItem = getItem('right', pairIndex);
            const leftDot = leftItem?.querySelector('.chem-match-dot');
            const rightDot = rightItem?.querySelector('.chem-match-dot');

            if (!leftDot || !rightDot) {
                return;
            }

            const leftDotRect = leftDot.getBoundingClientRect();
            const rightDotRect = rightDot.getBoundingClientRect();
            const x1 = leftDotRect.left - shellRect.left + leftDotRect.width / 2;
            const y1 = leftDotRect.top - shellRect.top + leftDotRect.height / 2;
            const x2 = rightDotRect.left - shellRect.left + rightDotRect.width / 2;
            const y2 = rightDotRect.top - shellRect.top + rightDotRect.height / 2;
            const midX = x1 + (x2 - x1) * 0.5;

            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            path.setAttribute('d', `M ${x1} ${y1} C ${midX} ${y1}, ${midX} ${y2}, ${x2} ${y2}`);
            path.setAttribute('fill', 'none');
            path.setAttribute('stroke', 'rgba(45, 212, 191, 0.96)');
            path.setAttribute('stroke-width', '4');
            path.setAttribute('stroke-linecap', 'round');
            path.setAttribute('stroke-linejoin', 'round');
            path.setAttribute('filter', 'drop-shadow(0 0 8px rgba(45, 212, 191, 0.35))');
            linesSvg.appendChild(path);
        });
    }

    function render() {
        leftButtons.forEach((button) => {
            const pairIndex = Number(button.dataset.pairIndex);
            button.classList.toggle('is-selected', selected && selected.side === 'left' && selected.pairIndex === pairIndex);
            button.classList.toggle('is-matched', matchedPairs.includes(pairIndex));
        });

        rightButtons.forEach((button) => {
            const pairIndex = Number(button.dataset.pairIndex);
            button.classList.toggle('is-selected', selected && selected.side === 'right' && selected.pairIndex === pairIndex);
            button.classList.toggle('is-matched', matchedPairs.includes(pairIndex));
        });

        foundElement.textContent = String(matchedPairs.length);
        drawLines();
    }

    leftButtons.forEach((button) => button.addEventListener('click', handleItemClick));
    rightButtons.forEach((button) => button.addEventListener('click', handleItemClick));
    window.addEventListener('resize', () => { if (!document.hidden) { drawLines(); } });

    function formatTime(seconds) {
        const minutes = String(Math.floor(seconds / 60)).padStart(2, '0');
        const remain = String(seconds % 60).padStart(2, '0');
        return `${minutes}:${remain}`;
    }

    timerElement.textContent = formatTime(Math.max(0, Math.floor((Date.now() - startAt) / 1000)));
    timerInterval = window.setInterval(() => {
        const elapsed = Math.floor((Date.now() - startAt) / 1000);
        timerElement.textContent = formatTime(elapsed);
        if (!isComplete) {
            saveState();
        }
    }, 1000);

    if (matchedPairs.length === pairCount && pairCount > 0) {
        isComplete = true;
        window.clearInterval(timerInterval);
        completionTimeElement.textContent = timerElement.textContent;
        completionCountElement.textContent = `${matchedPairs.length}/${pairCount}`;
        completionModal.classList.add('is-visible');
        setFinishButtonEnabled(true);
    }

    render();
})();
</script>
</body>
</html>

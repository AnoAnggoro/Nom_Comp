<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('siswa');

$user = chemnama_current_user();
$userId = (int) ($user['id'] ?? 0);
$simulationId = (int) ($_GET['id'] ?? 0);
$sourceModuleId = (int) ($_GET['module_id'] ?? 0);
$embedMode = (int) ($_GET['embed'] ?? 0) === 1;

$classStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$classStmt->execute(['user_id' => $userId]);
$studentClass = (string) ($classStmt->fetchColumn() ?: 'X IPA 1');

chemnama_ensure_simulation_reads_table($pdo);

$simulationStmt = $pdo->prepare(
    'SELECT s.*, mo.badge AS module_badge, mo.title AS module_title, mo.accent AS module_accent, u.name AS teacher_name
     FROM simulations s
     JOIN modules mo ON mo.id = s.module_id
     JOIN users u ON u.id = s.created_by
     WHERE s.id = :id
       AND s.is_published = 1
     LIMIT 1'
);
$simulationStmt->execute(['id' => $simulationId]);
$simulation = $simulationStmt->fetch();

if (!$simulation) {
    http_response_code(404);
    header('Location: siswa_materi.php');
    exit;
}

chemnama_mark_simulation_as_read($pdo, (int) $simulation['id'], $userId);
$simulationIsRead = chemnama_is_simulation_read($pdo, (int) $simulation['id'], $userId);

// Render the simulation page here. Use provided module_id for back navigation.

$relatedSimulationsStmt = $pdo->prepare(
    'SELECT s.id, s.product_name, s.product_formula, s.atom_first, s.atom_second, s.created_at, mo.badge AS module_badge
     FROM simulations s
     JOIN modules mo ON mo.id = s.module_id
     WHERE s.module_id = :module_id
       AND s.is_published = 1
       AND s.id <> :id
     ORDER BY s.created_at DESC
     LIMIT 6'
);
$relatedSimulationsStmt->execute([
    'module_id' => (int) $simulation['module_id'],
    'id' => (int) $simulation['id'],
]);
$relatedSimulations = $relatedSimulationsStmt->fetchAll();

$siswaMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Materi', 'icon' => 'file', 'href' => 'siswa_materi.php', 'active' => true],
    // ['label' => 'Games', 'icon' => 'beaker', 'href' => 'siswa_games.php', 'active' => false],
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
    <title><?= chemnama_e($simulation['product_name']); ?> - Simulasi Reaksi</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .simulation-canvas {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            border-radius: 16px;
            padding: 40px 20px;
            margin: 20px 0;
            min-height: 680px;
            display: grid;
            grid-template-rows: minmax(0, 1fr) auto;
            gap: 16px;
            align-items: stretch;
            justify-content: stretch;
            position: relative;
            overflow: hidden;
        }

        .simulation-header {
            color: #94a3b8;
            font-size: 12px;
            font-weight: 500;
            letter-spacing: 1px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .reaction-display {
            text-align: center;
            color: #0f9d58;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 30px;
            letter-spacing: 2px;
        }

        .atom-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 60px;
            margin-bottom: 60px;
            position: relative;
        }

        .reaction-particle-layer {
            position: absolute;
            inset: 0;
            pointer-events: none;
            z-index: 1;
            overflow: hidden;
        }

        .electron-particle {
            position: absolute;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: radial-gradient(circle, #fef08a 0%, #f59e0b 46%, rgba(245, 158, 11, 0.15) 72%, transparent 100%);
            box-shadow: 0 0 12px rgba(245, 158, 11, 0.45), 0 0 20px rgba(34, 211, 238, 0.18);
            transform: translate3d(0, 0, 0);
            animation: electronTravel 1.25s ease-in-out forwards;
        }

        .electron-particle.is-return {
            background: radial-gradient(circle, #c7f9cc 0%, #22c55e 46%, rgba(34, 197, 94, 0.18) 72%, transparent 100%);
            box-shadow: 0 0 12px rgba(34, 197, 94, 0.5), 0 0 20px rgba(34, 211, 238, 0.12);
        }

        .atom {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            box-shadow: 0 0 30px rgba(15, 157, 88, 0.3);
            font-weight: 600;
        }

        .atom::before {
            content: '';
            position: absolute;
            inset: -14px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: 0 0 0 1px rgba(15, 157, 88, 0.12), inset 0 0 26px rgba(255, 255, 255, 0.08);
            opacity: 0.55;
            animation: atomOrbit 5s linear infinite;
        }

        .atom::after {
            content: '';
            position: absolute;
            inset: 18%;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.24), transparent 62%);
            filter: blur(2px);
            opacity: 0.65;
            mix-blend-mode: screen;
        }

        .atom.atom-1::before {
            border-color: rgba(245, 158, 11, 0.24);
        }

        .atom.atom-2::before {
            border-color: rgba(34, 197, 94, 0.24);
        }

        .atom.atom-1::after {
            background: radial-gradient(circle, rgba(255, 237, 213, 0.36), transparent 64%);
        }

        .atom.atom-2::after {
            background: radial-gradient(circle, rgba(217, 249, 157, 0.28), transparent 64%);
        }

        .atom.is-charged {
            animation: chargedPulse 1.4s ease-in-out infinite;
        }

        .atom.is-flash {
            animation: atomFlash 0.55s ease-out;
        }

        .atom:hover {
            transform: scale(1.1);
            box-shadow: 0 0 40px rgba(15, 157, 88, 0.6);
        }

        .atom.atom-1 {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #000;
        }

        .atom.atom-2 {
            background: linear-gradient(135deg, #4ade80, #22c55e);
            color: #000;
        }

        .atom-symbol {
            font-size: 48px;
            font-weight: 800;
            line-height: 1;
        }

        .atom-name {
            font-size: 11px;
            margin-top: 4px;
            opacity: 0.8;
        }

        .atom-charge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(2, 6, 23, 0.55);
            border: 1px solid rgba(255, 255, 255, 0.12);
            color: #e2e8f0;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.04em;
            opacity: 0.9;
            backdrop-filter: blur(8px);
        }

        .atom-charge.is-positive {
            color: #fbbf24;
            border-color: rgba(251, 191, 36, 0.22);
            box-shadow: 0 0 16px rgba(251, 191, 36, 0.12);
        }

        .atom-charge.is-negative {
            color: #67e8f9;
            border-color: rgba(103, 232, 249, 0.22);
            box-shadow: 0 0 16px rgba(103, 232, 249, 0.12);
        }

        .atom-label {
            position: absolute;
            top: -30px;
            color: #64748b;
            font-size: 11px;
            font-weight: 500;
            white-space: nowrap;
            width: 100%;
            text-align: center;
        }

        .reaction-arrow {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translateX(-50%) translateY(-50%);
            color: #64748b;
            font-size: 28px;
            pointer-events: none;
            text-shadow: 0 0 14px rgba(34, 211, 238, 0.18);
            animation: arrowFloat 2.6s ease-in-out infinite;
        }

        .simulation-info {
            display: flex;
            gap: 16px;
            margin-top: 40px;
            align-items: stretch;
        }

        .info-card {
            flex: 1 1 180px;
            background: rgba(255, 255, 255, 0.96);
            border: 1px solid rgba(148, 163, 184, 0.16);
            border-radius: 16px;
            padding: 16px;
            color: #0f172a;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
            position: relative;
            overflow: hidden;
        }

        .info-card::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 4px;
            background: var(--card-accent, #0f9d58);
        }

        .info-card--green { --card-accent: #0f9d58; }
        .info-card--cyan { --card-accent: #06b6d4; }
        .info-card--amber { --card-accent: #f59e0b; }
        .info-card--violet { --card-accent: #8b5cf6; }
        .info-card--rose { --card-accent: #f43f5e; }
        .info-card--status { --card-accent: #10b981; }

        .info-card-title {
            color: var(--card-accent, #0f9d58);
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .info-card-value {
            font-size: 14px;
            color: #0f172a;
            line-height: 1.5;
            font-weight: 700;
        }

        .info-card-note {
            color: #64748b;
            font-size: 11px;
            line-height: 1.4;
            margin-top: 4px;
        }

        .simulation-activity {
            width: min(100%, 760px);
            border-radius: 16px;
            border: 1px solid rgba(15, 157, 88, 0.16);
            background: rgba(15, 23, 42, 0.55);
            color: #dbeafe;
            padding: 14px 16px;
            display: grid;
            gap: 4px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.18);
        }

        .simulation-activity-title {
            color: #34d399;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .simulation-activity-text {
            color: #e5e7eb;
            line-height: 1.55;
            font-size: 14px;
        }

        .atom.is-selected {
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.12), 0 0 40px rgba(15, 157, 88, 0.45);
        }

        .atom.is-joined {
            opacity: 0.12;
            pointer-events: none;
        }

        .simulation-stage.is-burst::after {
            content: '';
            position: absolute;
            inset: 14% 12%;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(34, 197, 94, 0.62) 0%, rgba(34, 197, 94, 0.28) 18%, rgba(34, 197, 94, 0.1) 36%, transparent 72%);
            filter: blur(3px);
            animation: burstGlow 0.8s ease-out forwards;
            pointer-events: none;
            z-index: 0;
        }

        .simulation-stage.is-reacting::before {
            animation: stageGlow 4s ease-in-out infinite;
        }

        .simulation-stage.is-burst::before {
            background:
                radial-gradient(circle at 50% 50%, rgba(34, 211, 238, 0.24), transparent 28%),
                radial-gradient(circle at 50% 50%, rgba(245, 158, 11, 0.18), transparent 16%),
                radial-gradient(circle at 50% 30%, rgba(255, 255, 255, 0.1), transparent 22%),
                radial-gradient(circle at 50% 50%, rgba(15, 157, 88, 0.14), transparent 38%);
        }

        .simulation-stage.is-complete::before {
            animation-duration: 2.8s;
        }

        @keyframes burstGlow {
            0% {
                transform: scale(0.6);
                opacity: 0.1;
            }
            45% {
                transform: scale(1);
                opacity: 1;
            }
            100% {
                transform: scale(1.25);
                opacity: 0;
            }
        }

        @keyframes stageGlow {
            0%, 100% {
                opacity: 0.85;
                filter: saturate(1);
            }
            50% {
                opacity: 1;
                filter: saturate(1.15);
            }
        }

        @keyframes particleDrift {
            from {
                transform: translate3d(0, 0, 0);
            }
            to {
                transform: translate3d(0, -48px, 0);
            }
        }

        @keyframes atomOrbit {
            from {
                transform: rotate(0deg) scale(1);
            }
            50% {
                transform: rotate(180deg) scale(1.02);
            }
            to {
                transform: rotate(360deg) scale(1);
            }
        }

        @keyframes reactionPulse {
            0%, 100% {
                opacity: 0.65;
                transform: translateY(-50%) scaleX(1);
            }
            50% {
                opacity: 1;
                transform: translateY(-50%) scaleX(1.08);
            }
        }

        @keyframes arrowFloat {
            0%, 100% {
                transform: translateX(-50%) translateY(-50%) translateY(0);
            }
            50% {
                transform: translateX(-50%) translateY(-50%) translateY(-4px);
            }
        }

        @keyframes electronTravel {
            0% {
                opacity: 0;
                transform: scale(0.5) translate3d(0, 0, 0);
            }
            15% {
                opacity: 1;
            }
            100% {
                opacity: 0;
                transform: scale(1) translate3d(var(--dx, 0px), var(--dy, 0px), 0);
            }
        }

        @keyframes chargedPulse {
            0%, 100% {
                box-shadow: 0 0 22px rgba(15, 157, 88, 0.28);
            }
            50% {
                box-shadow: 0 0 28px rgba(34, 211, 238, 0.28), 0 0 52px rgba(245, 158, 11, 0.16);
            }
        }

        @keyframes atomFlash {
            0% {
                transform: scale(1);
                filter: brightness(1);
            }
            45% {
                transform: scale(1.08);
                filter: brightness(1.2);
            }
            100% {
                transform: scale(1);
                filter: brightness(1);
            }
        }

        .atom-badge {
            position: absolute;
            bottom: -42px;
            left: 50%;
            transform: translateX(-50%);
            min-width: 132px;
            padding: 6px 10px;
            border-radius: 999px;
            background: rgba(2, 6, 23, 0.72);
            border: 1px solid rgba(52, 211, 153, 0.18);
            color: #d1fae5;
            font-size: 11px;
            line-height: 1.35;
            text-align: center;
            opacity: 0;
            transform-origin: center;
            transition: opacity 0.2s ease, transform 0.2s ease;
            pointer-events: none;
        }

        .atom.has-badge .atom-badge {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        .simulation-product-message {
            width: min(100%, 760px);
            border-radius: 16px;
            border: 1px solid rgba(16, 185, 129, 0.24);
            background: rgba(6, 95, 70, 0.12);
            color: #d1fae5;
            padding: 14px 16px;
            display: none;
            align-items: center;
            gap: 10px;
        }

        .simulation-product-message strong {
            color: #34d399;
        }

        .simulation-product-message.is-visible {
            display: flex;
        }

        .product-result {
            text-align: center;
            margin-top: 30px;
        }

        .product-molecule {
            width: 180px;
            height: 180px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: white;
            font-weight: 600;
            box-shadow: 0 0 40px rgba(16, 185, 129, 0.5);
            opacity: 0;
            animation: slideUp 0.6s ease forwards 0.4s;
        }

        .product-formula {
            font-size: 64px;
            line-height: 1;
            margin-bottom: 8px;
        }

        .product-name {
            font-size: 14px;
            opacity: 0.9;
        }

        .product-description {
            font-size: 12px;
            color: #86efac;
            margin-top: 8px;
            opacity: 0.8;
        }

        .click-hint {
            color: #64748b;
            font-size: 12px;
            margin-top: 40px;
            animation: pulse 1.5s ease infinite;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .click-dot {
            width: 6px;
            height: 6px;
            background: #0f9d58;
            border-radius: 50%;
            animation: blink 1s ease infinite;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 0.5;
            }
            50% {
                opacity: 1;
            }
        }

        @keyframes blink {
            0%, 100% {
                opacity: 0;
            }
            50% {
                opacity: 1;
            }
        }

        .simulation-info {
            display:;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-top: 40px;
        }

        .info-card {
            background: rgba(15, 157, 88, 0.1);
            border: 1px solid rgba(15, 157, 88, 0.3);
            border-radius: 12px;
            padding: 16px;
            color: #e2e8f0;
        }

        .info-card-title {
            color: #0f9d58;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 1px;
            margin-bottom: 8px;
            text-transform: uppercase;
        }

        .info-card-value {
            font-size: 14px;
            color: #cbd5e1;
            line-height: 1.5;
        }

        .reset-button {
            margin-top: 30px;
            padding: 10px 24px;
            background: rgba(15, 157, 88, 0.2);
            border: 1px solid #0f9d58;
            color: #0f9d58;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .reset-button:hover {
            background: rgba(15, 157, 88, 0.3);
            transform: scale(1.05);
        }

        .simulation-description {
            background: rgba(15, 157, 88, 0.1);
            border-left: 4px solid #0f9d58;
            border-radius: 8px;
            padding: 20px;
            margin-top: 40px;
            color: #000000;
            line-height: 1.6;
        }

        .simulation-description h4 {
            color: #0f9d58;
            margin-bottom: 10px;
            font-size: 14px;
            font-weight: 600;
        }

        .simulation-intro {
            display: grid;
            gap: 18px;
            padding: 18px;
        }

        .simulation-intro.is-leaving {
            animation: introFadeOut 0.28s ease forwards;
        }

        .simulation-workspace.is-entering {
            animation: workspaceFadeIn 0.36s ease forwards;
        }

        @keyframes introFadeOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }
            to {
                opacity: 0;
                transform: translateY(-8px);
            }
        }

        @keyframes workspaceFadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .simulation-intro-material {
            border-radius: 18px;
            border: 1px solid rgba(148, 163, 184, 0.2);
            box-shadow: 0 12px 26px rgba(15, 23, 42, 0.05);
            padding: 18px;
            display: grid;
            gap: 14px;
        }

        .simulation-intro-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            color: #64748b;
            font-size: 0.92rem;
        }

        .simulation-read-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(15, 157, 88, 0.16);
            color: #0f9d58;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 800;
        }

        .simulation-intro-text {
            color: #475569;
            line-height: 1.8;
        }

        .simulation-intro-text p {
            margin: 0 0 14px;
        }

        .simulation-intro-text p:last-child {
            margin-bottom: 0;
        }

        .simulation-intro-hero {
            display: grid;
            gap: 12px;
        }

        .simulation-intro-hero h2 {
            margin: 0;
            font-size: 1.9rem;
            letter-spacing: -0.04em;
        }

        .simulation-intro-hero p {
            margin: 0;
            color: #64748b;
            line-height: 1.7;
            max-width: 920px;
        }

        .simulation-meta-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .simulation-meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(15, 157, 88, 0.08);
            color: #0f9d58;
            font-size: 12px;
            font-weight: 800;
        }

        .simulation-intro-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .simulation-intro-card {
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: rgba(255, 255, 255, 0.9);
            padding: 16px;
            display: grid;
            gap: 6px;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.05);
        }

        .simulation-intro-card small {
            color: #0f9d58;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .simulation-intro-card strong {
            color: #0f172a;
            font-size: 1rem;
        }

        .simulation-intro-card span {
            color: #64748b;
            line-height: 1.5;
            font-size: 0.95rem;
        }

        .simulation-intro-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 4px;
        }

        .simulation-intro-preview {
            border-radius: 16px;
            border: 1px dashed rgba(20, 184, 166, 0.32);
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.04), rgba(15, 23, 42, 0.02));
            padding: 16px;
            display: grid;
            gap: 10px;
        }

        .simulation-intro-preview-title {
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.04em;
            color: #0f9d58;
            text-transform: uppercase;
        }

        .simulation-intro-preview-stage {
            border-radius: 14px;
            border: 1px solid rgba(15, 157, 88, 0.16);
            background:
                linear-gradient(rgba(16, 185, 129, 0.05) 1px, transparent 1px),
                linear-gradient(90deg, rgba(16, 185, 129, 0.05) 1px, transparent 1px),
                rgba(2, 6, 23, 0.92);
            background-size: 34px 34px, 34px 34px, auto;
            min-height: 130px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            color: #e2e8f0;
            font-weight: 700;
            font-size: 0.95rem;
        }

        .simulation-intro-preview-atom {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            color: #0f172a;
            box-shadow: 0 0 16px rgba(15, 157, 88, 0.25);
        }

        .simulation-intro-preview-atom.atom-a {
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
        }

        .simulation-intro-preview-atom.atom-b {
            background: linear-gradient(135deg, #22d3ee, #06b6d4);
        }

        .simulation-intro-preview-product {
            color: #10b981;
            font-weight: 900;
            font-size: 1.05rem;
        }

        .simulation-start-button {
            border: 0;
            border-radius: 14px;
            padding: 14px 22px;
            background: linear-gradient(135deg, #d97706, #f59e0b);
            color: #ffffff;
            font-weight: 800;
            cursor: pointer;
            box-shadow: 0 18px 30px rgba(217, 119, 6, 0.24);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .simulation-start-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 22px 34px rgba(217, 119, 6, 0.3);
        }

        .simulation-quiz-button {
            border-radius: 14px;
            padding: 14px 22px;
            background: linear-gradient(135deg, #0f9d58, #059669);
            color: #ffffff;
            font-weight: 800;
            box-shadow: 0 18px 30px rgba(15, 157, 88, 0.24);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .simulation-quiz-button:hover {
            transform: translateY(-1px);
            box-shadow: 0 22px 34px rgba(15, 157, 88, 0.3);
        }

        .simulation-notice {
            color: #64748b;
            font-size: 12px;
        }

        .simulation-workspace {
            display: none;
            gap: 18px;
        }

        .simulation-workspace.is-visible {
            display: grid;
        }

        .simulation-hidden-note {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 220px;
            border-radius: 20px;
            border: 1px dashed rgba(20, 184, 166, 0.2);
            background: rgba(20, 184, 166, 0.05);
            color: #0f9d58;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .siswa-simulasi-page {
            gap: 18px;
        }

        .siswa-simulasi-content {
            gap: 18px;
        }

        .siswa-simulasi-hero {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 4px 0 6px;
        }

        .siswa-simulasi-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #0f9d58;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .siswa-simulasi-hero h1 {
            margin: 0;
            font-size: 2.2rem;
            letter-spacing: -0.04em;
        }

        .siswa-simulasi-hero p {
            margin: 6px 0 0;
            color: #64748b;
            max-width: 780px;
        }

        .simulation-panel {
            display: none;
            gap: 18px;
            padding: 18px;
        }

        .simulation-topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .simulation-topbar-reaction {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            border-radius: 14px;
            background: rgba(15, 23, 42, 0.04);
            color: #0f172a;
            font-weight: 800;
            letter-spacing: 0.02em;
        }

        .simulation-status-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(20, 184, 166, 0.12);
            color: #0f9d58;
            font-size: 12px;
            font-weight: 800;
        }

        .simulation-canvas {
            margin: 0;
            min-height: 680px;
            border-radius: 20px;
            padding: 22px;
            display: grid;
            grid-template-rows: minmax(0, 1fr) auto;
            gap: 16px;
            background:
                radial-gradient(circle at 20% 18%, rgba(20, 184, 166, 0.18), transparent 18%),
                radial-gradient(circle at 80% 82%, rgba(245, 158, 11, 0.12), transparent 16%),
                radial-gradient(circle at 50% 50%, rgba(15, 157, 88, 0.18), transparent 32%),
                linear-gradient(180deg, rgba(2, 6, 23, 0.98), rgba(15, 23, 42, 0.96));
            border: 1px solid rgba(148, 163, 184, 0.18);
            box-shadow: inset 0 0 0 1px rgba(15, 157, 88, 0.08);
        }

        .simulation-stage {
            min-height: clamp(340px, 56vh, 520px);
            height: auto;
            border-radius: 18px;
            border: 1px solid rgba(20, 184, 166, 0.12);
            background:
                linear-gradient(rgba(16, 185, 129, 0.04) 1px, transparent 1px),
                linear-gradient(90deg, rgba(16, 185, 129, 0.04) 1px, transparent 1px),
                radial-gradient(circle at 50% 50%, rgba(34, 197, 94, 0.08), transparent 42%),
                rgba(2, 6, 23, 0.36);
            background-size: 48px 48px, 48px 48px, auto;
            display: grid;
            align-content: center;
            justify-items: center;
            gap: 24px;
            padding: 26px;
            position: relative;
            overflow: hidden;
        }

        .simulation-stage::before {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 50% 50%, rgba(15, 157, 88, 0.14), transparent 38%),
                radial-gradient(circle at 50% 30%, rgba(34, 197, 94, 0.16), transparent 22%),
                radial-gradient(circle at 50% 70%, rgba(34, 211, 238, 0.08), transparent 18%);
            pointer-events: none;
            animation: stageGlow 8s ease-in-out infinite;
        }

        .simulation-stage::after {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 18% 22%, rgba(255, 255, 255, 0.18) 0 1px, transparent 2px),
                radial-gradient(circle at 32% 74%, rgba(34, 211, 238, 0.22) 0 1px, transparent 2px),
                radial-gradient(circle at 74% 28%, rgba(245, 158, 11, 0.18) 0 1px, transparent 2px),
                radial-gradient(circle at 82% 76%, rgba(16, 185, 129, 0.18) 0 1px, transparent 2px);
            background-size: 120px 120px, 140px 140px, 180px 180px, 160px 160px;
            opacity: 0.4;
            animation: particleDrift 12s linear infinite;
            pointer-events: none;
        }

        .simulation-stage > * {
            position: relative;
            z-index: 1;
        }

        .atom-container {
            gap: clamp(28px, 5vw, 72px);
            margin-bottom: 0;
            flex-wrap: wrap;
            position: relative;
        }

        .atom-container::before {
            content: '';
            position: absolute;
            left: 15%;
            right: 15%;
            top: 50%;
            height: 2px;
            background: linear-gradient(90deg, transparent, rgba(34, 211, 238, 0.35), rgba(245, 158, 11, 0.45), rgba(34, 211, 238, 0.35), transparent);
            filter: blur(0.2px);
            transform: translateY(-50%);
            opacity: 0.8;
            pointer-events: none;
        }

        .atom-container.is-reacting::before {
            animation: reactionPulse 0.9s ease-in-out infinite;
        }

        .reaction-display {
            display: none;
        }

        .atom {
            width: clamp(100px, 12vw, 130px);
            height: clamp(100px, 12vw, 130px);
            box-shadow: 0 0 22px rgba(15, 157, 88, 0.28);
            isolation: isolate;
        }

        .atom::before {
            content: '';
            position: absolute;
            inset: -14px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: 0 0 0 1px rgba(15, 157, 88, 0.12), inset 0 0 26px rgba(255, 255, 255, 0.08);
            opacity: 0.55;
            animation: atomOrbit 5s linear infinite;
        }

        .atom::after {
            content: '';
            position: absolute;
            inset: 18%;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.24), transparent 62%);
            filter: blur(2px);
            opacity: 0.65;
            mix-blend-mode: screen;
        }

        .atom.atom-1::before {
            border-color: rgba(245, 158, 11, 0.24);
        }

        .atom.atom-2::before {
            border-color: rgba(34, 197, 94, 0.24);
        }

        .atom.atom-1::after {
            background: radial-gradient(circle, rgba(255, 237, 213, 0.36), transparent 64%);
        }

        .atom.atom-2::after {
            background: radial-gradient(circle, rgba(217, 249, 157, 0.28), transparent 64%);
        }

        .atom.is-selected::before {
            opacity: 1;
            transform: scale(1.05);
            box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.2), 0 0 34px rgba(34, 211, 238, 0.18);
        }

        .atom.is-joined::before {
            animation-duration: 1.4s;
            opacity: 0.25;
            transform: scale(1.12);
        }

        .atom.is-joined::after {
            opacity: 0.2;
            animation: none;
        }

        .reaction-arrow {
            color: rgba(148, 163, 184, 0.9);
            font-size: 1.8rem;
            font-weight: 900;
            text-shadow: 0 0 14px rgba(34, 211, 238, 0.18);
            animation: arrowFloat 2.6s ease-in-out infinite;
        }

        .product-result {
            margin-top: 8px;
        }

        .simulation-info-grid {
            margin-top: 6px;
        }

        .simulation-info-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
        }

        .simulation-info-grid.is-awaiting-output .info-card:not(.info-card--status) {
            display: none;
        }

        .simulation-info-grid.is-awaiting-output .info-card--status {
            grid-column: 3 / span 2;
            max-width: 320px;
            justify-self: center;
            min-height: 78px;
            padding: 12px 14px;
        }

        .simulation-info-grid .info-card {
            --card-accent: #10b981;
            min-height: 92px;
            display: grid;
            align-content: start;
            gap: 6px;
            position: relative;
            overflow: hidden;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(16, 185, 129, 0.36);
            background: linear-gradient(130deg, rgba(3, 18, 44, 0.94), rgba(5, 32, 58, 0.9));
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05), 0 10px 24px rgba(2, 8, 23, 0.34);
        }

        .simulation-info-grid .info-card::before {
            content: '';
            position: absolute;
            inset: 0 auto 0 0;
            width: 3px;
            background: var(--card-accent);
        }

        .simulation-info-grid .info-card.info-card--green { --card-accent: #00c17b; }
        .simulation-info-grid .info-card.info-card--cyan { --card-accent: #06d6c7; }
        .simulation-info-grid .info-card.info-card--amber { --card-accent: #fbbf24; }
        .simulation-info-grid .info-card.info-card--violet { --card-accent: #8b5cf6; }
        .simulation-info-grid .info-card.info-card--rose { --card-accent: #f43f5e; }
        .simulation-info-grid .info-card.info-card--status { --card-accent: #22c55e; }

        .simulation-info-grid .info-card .info-card-title {
            color: #00c17b;
            font-size: 0.78rem;
            font-weight: 800;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }

        .simulation-info-grid .info-card .info-card-value {
            color: #d8dee9;
            font-size: clamp(1.12rem, 1.35vw, 1.9rem);
            font-weight: 800;
            letter-spacing: -0.01em;
            line-height: 1.22;
        }

        .simulation-info-grid .info-card .info-card-note {
            color: #9ca3af;
            font-size: 0.93rem;
            line-height: 1.35;
        }

        .simulation-info-grid.is-awaiting-output .info-card--status .info-card-title {
            margin-bottom: 6px;
        }

        .simulation-info-grid.is-awaiting-output .info-card--status .info-card-value {
            font-size: 13px;
        }

        .simulation-info-grid.is-awaiting-output .info-card--status .info-card-note {
            font-size: 10px;
        }

        .info-card.is-status-pending .info-card-value {
            color: #f59e0b;
        }

        .info-card.is-status-active .info-card-value {
            color: #60a5fa;
        }

        .info-card.is-status-done .info-card-value {
            color: #34d399;
        }

        .info-card-note {
            color: #94a3b8;
            font-size: 11px;
            line-height: 1.4;
        }

        @media (max-width: 1200px) {
            .simulation-info-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .simulation-info-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .simulation-details-grid {
            display: none;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }
        
        .simulation-details-grid.is-visible {
            display: grid;
        }

        .simulation-description {
            margin-top: 0;
            min-height: 100%;
        }

        .simulation-related {
            display: grid;
            gap: 14px;
        }

        .simulation-related-head h2 {
            margin: 0;
            font-size: 1.25rem;
            letter-spacing: -0.03em;
        }

        .simulation-related-head p {
            margin: 6px 0 0;
            color: #64748b;
        }

        .simulation-related-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .simulation-related-card {
            border-radius: 16px;
            border: 1px solid rgba(20, 184, 166, 0.12);
            background: rgba(255, 255, 255, 0.9);
            padding: 16px;
            display: grid;
            gap: 8px;
            color: #0f172a;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.05);
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .simulation-related-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 28px rgba(15, 23, 42, 0.08);
        }

        .simulation-related-card strong {
            font-size: 1rem;
            line-height: 1.35;
        }

        .simulation-related-card span {
            color: #64748b;
            font-size: 0.92rem;
        }

        .simulation-related-badge {
            display: inline-flex;
            align-items: center;
            width: fit-content;
            border-radius: 999px;
            padding: 6px 10px;
            background: rgba(20, 184, 166, 0.12);
            color: #0f9d58;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        body.is-embed {
            background: #0f172a;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.45) rgba(15, 23, 42, 0.25);
        }

        body.is-embed::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        body.is-embed::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.25);
            border-radius: 999px;
        }

        body.is-embed::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.45);
            border-radius: 999px;
        }

        body.is-embed::-webkit-scrollbar-thumb:hover {
            background: rgba(148, 163, 184, 0.62);
        }

        body.is-embed .guru-mobile-toggle,
        body.is-embed .guru-sidebar-overlay,
        body.is-embed .guru-sidebar,
        body.is-embed .siswa-simulasi-hero,
        body.is-embed .simulation-intro,
        body.is-embed .simulation-topbar {
            display: none !important;
        }

        body.is-embed .guru-page,
        body.is-embed .guru-content,
        body.is-embed .student-content,
        body.is-embed .siswa-simulasi-content,
        body.is-embed #simulationMainContent {
            display: block;
            width: 100%;
            max-width: none;
            margin: 0;
            padding: 0;
            gap: 0;
        }

        body.is-embed .simulation-workspace,
        body.is-embed .simulation-panel {
            display: block !important;
            border: 0;
            box-shadow: none;
            background: transparent;
            border-radius: 0;
            margin: 0;
            padding: 0;
            gap: 0;
        }

        body.is-embed .simulation-canvas {
            margin: 0;
            min-height: 100dvh;
            height: 100%;
            width: min(100%, 1120px);
            max-width: calc(100% - 24px);
            margin-left: auto;
            margin-right: auto;
            padding-inline: clamp(12px, 1.6vw, 20px);
            border-radius: 0;
            overflow: hidden;
        }

        body.is-embed .simulation-info {
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            margin-top: 12px;
            overflow: visible;
            padding-bottom: 6px;
            scrollbar-width: thin;
            scrollbar-color: rgba(148, 163, 184, 0.45) rgba(15, 23, 42, 0.25);
        }

        body.is-embed .simulation-info::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        body.is-embed .simulation-info::-webkit-scrollbar-track {
            background: rgba(15, 23, 42, 0.2);
            border-radius: 999px;
        }

        body.is-embed .simulation-info::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, 0.4);
            border-radius: 999px;
        }

        body.is-embed .simulation-info-grid {
            display: grid !important;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
            width: 100%;
            min-width: 0;
        }

        body.is-embed .simulation-info-grid .info-card {
            flex: initial;
            width: auto;
            min-width: 0;
            min-height: 92px;
        }

        body.is-embed .simulation-info-grid.is-awaiting-output {
            width: 100%;
            min-width: 100%;
            justify-content: stretch;
        }

        body.is-embed .simulation-info-grid.is-awaiting-output .info-card--status {
            grid-column: 1 / -1;
            max-width: none;
            margin: 0;
        }

        body.is-embed .simulation-stage,
        body.is-embed .simulation-activity,
        body.is-embed .simulation-product-message,
        body.is-embed .simulation-details-grid,
        body.is-embed .simulation-related-grid {
            width: 100%;
            min-width: 0;
            max-width: 100%;
        }

        body.is-embed .simulation-details-grid {
            display: none;
            margin-top: 12px;
        }

        body.is-embed .simulation-details-grid.is-visible {
            display: grid;
        }

        @media (max-width: 768px) {
            .siswa-simulasi-hero {
                flex-direction: column;
            }

            .simulation-topbar {
                align-items: flex-start;
            }

            .simulation-canvas {
                padding: 16px;
            }

            body.is-embed .simulation-canvas {
                width: calc(100% - 12px);
                max-width: calc(100% - 12px);
                min-height: calc(100dvh - 12px);
                margin: 0 auto;
                padding: 12px 10px;
            }

            body.is-embed .simulation-info {
                grid-template-columns: 1fr;
                overflow: visible;
                padding-bottom: 0;
                width: 100%;
            }

            body.is-embed .simulation-info-grid {
                display: grid !important;
                grid-template-columns: 1fr;
                width: 100%;
                min-width: 0;
            }

            body.is-embed .simulation-info-grid .info-card {
                flex: 1 1 auto;
                width: 100%;
                min-width: 0;
                min-height: 84px;
            }

            body.is-embed .simulation-info-grid.is-awaiting-output .info-card--status {
                grid-column: 1 / -1;
                max-width: none;
            }

            body.is-embed .simulation-activity,
            body.is-embed .simulation-product-message,
            body.is-embed .simulation-stage,
            body.is-embed .simulation-details-grid,
            body.is-embed .simulation-related-grid {
                width: 100%;
                min-width: 0;
                max-width: 100%;
            }

            .simulation-stage {
                min-height: 380px;
                padding: 18px;
            }

            .simulation-panel {
                padding: 14px;
            }

            .simulation-intro {
                padding: 14px;
            }
        }
    </style>
</head>
<body class="dashboard-page<?= $embedMode ? ' is-embed' : ''; ?>">
<main class="guru-page student-page siswa-simulasi-page">
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
            <p>Portal Siswa</p>
            <div class="guru-class-chip"><?= chemnama_e($studentClass); ?></div>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">MENU</span>
            <nav class="guru-menu-list">
                <?php foreach ($siswaMenus as $menu): ?>
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
                <a class="guru-menu-item logout" href="logout.php">
                    <?= chemnama_icon('logout', '#ef4444'); ?>
                    <span>Keluar</span>
                </a>
            </nav>
        </div>
    </aside>

    <section class="guru-content student-content siswa-simulasi-content">
        <div class="siswa-simulasi-hero">
            <?php if (!$embedMode): ?>
            <div>
                <div class="siswa-simulasi-kicker">
                    <?= chemnama_icon('beaker', '#14b8a6'); ?> Modul <?= chemnama_e($simulation['module_badge']); ?>
                </div>
                <h1><?= chemnama_e($simulation['product_name']); ?></h1>
                <p><?= chemnama_e($simulation['module_title']); ?> · Baca penjelasan singkat dulu, lalu mulai simulasi untuk melihat atom bereaksi menjadi produk.</p>
                <a class="siswa-materi-back" href="<?= $sourceModuleId > 0 ? 'siswa_materi_detail.php?id=' . $sourceModuleId . '&step=3' : 'siswa_materi.php'; ?>"><?= chemnama_icon('switch', '#64748b'); ?> <span>Kembali</span></a>
            </div>
            <?php endif; ?>
        </div>

        <div id="simulationMainContent">
        <?php if (!$embedMode): ?>
        <article class="guru-panel simulation-intro" id="simulationIntro">
            <div class="simulation-intro-material">
                <div class="simulation-intro-hero">
                    <div class="simulation-meta-row">
                        <span class="simulation-meta-pill"><?= chemnama_e($simulation['module_badge']); ?></span>
                        <span class="simulation-meta-pill"><?= chemnama_e($simulation['reaction_type']); ?></span>
                        <span class="simulation-meta-pill"><?= chemnama_e($simulation['bond_type']); ?></span>
                    </div>
                    <h2><?= chemnama_e($simulation['product_name']); ?></h2>
                    <div class="simulation-intro-meta">
                        <span><?= chemnama_e($simulation['teacher_name']); ?></span>
                        <span>•</span>
                        <span><?= chemnama_e((string) $simulation['created_at']); ?></span>
                        <span class="simulation-read-pill"><?= $simulationIsRead ? 'Sudah Dibaca' : 'Baru Dibuka'; ?></span>
                    </div>
                </div>

                <div class="simulation-intro-text">
                    <?php if (!empty($simulation['intro_description'])): ?>
                        <p><?= chemnama_e($simulation['intro_description']); ?></p>
                    <?php endif; ?>
                </div>

         
            </div>

            <div class="simulation-intro-actions">
                <button class="btn btn-primary" type="button" id="startSimulationButton"><?= chemnama_icon('beaker', '#ffffff'); ?> Lihat Simulasi</button>
                <a class="btn btn-secondary" href="siswa_quiz.php"><?= chemnama_icon('edit', '#d97706'); ?> Kuis <?= chemnama_e($simulation['module_badge']); ?></a>
                <span class="simulation-notice">Klik untuk membuka area interaktif atom dan produk.</span>
            </div>
        </article>
        <?php endif; ?>

            <article class="guru-panel simulation-panel simulation-workspace is-visible" id="simulationWorkspace">
            <div class="simulation-topbar">
                <div class="simulation-topbar-reaction" id="reactionDisplay">
                    <span><?= chemnama_e($simulation['atom_first']); ?></span>
                    <span>+</span>
                    <span><?= chemnama_e($simulation['atom_second']); ?></span>
                    <span>→</span>
                    <span><?= chemnama_e($simulation['product_formula']); ?></span>
                </div>
                <span class="simulation-status-chip"><?= chemnama_e($simulation['reaction_type']); ?></span>
            </div>

            <div class="simulation-canvas" id="simulationCanvas">
                <div class="simulation-stage">
                    <div class="simulation-activity" id="simulationActivity" aria-live="polite">
                        <span class="simulation-activity-title" id="simulationActivityTitle">Siap Memulai</span>
                        <span class="simulation-activity-text" id="simulationActivityText">Klik atom pertama untuk melihat informasi reaktan, lalu klik atom kedua untuk menyatukannya.</span>
                    </div>

                    <div class="reaction-particle-layer" id="reactionParticleLayer" aria-hidden="true"></div>

                    <div class="atom-container">
                        <div class="atom atom-1" id="atom1">
                            <div class="atom-label">Klik atom pertama</div>
                            <div class="atom-charge is-positive" id="atom1Charge">+1</div>
                            <div class="atom-symbol" id="atom1Symbol">
                                <?php
                                preg_match('/^([A-Z][a-z]?)/', $simulation['atom_first'], $matches);
                                echo chemnama_e($matches[1] ?? substr($simulation['atom_first'], 0, 1));
                                ?>
                            </div>
                            <div class="atom-name"><?= chemnama_e(trim(preg_replace('/^\([^)]*\)/', '', $simulation['atom_first']))); ?></div>
                            <div class="atom-badge" id="atom1Badge">Siap dipilih</div>
                        </div>

                        <div class="reaction-arrow">+</div>

                        <div class="atom atom-2" id="atom2">
                            <div class="atom-label">Klik atom kedua</div>
                            <div class="atom-charge is-negative" id="atom2Charge">-1</div>
                            <div class="atom-symbol" id="atom2Symbol">
                                <?php
                                preg_match('/^([A-Z][a-z]?)/', $simulation['atom_second'], $matches);
                                echo chemnama_e($matches[1] ?? substr($simulation['atom_second'], 0, 1));
                                ?>
                            </div>
                            <div class="atom-name"><?= chemnama_e(trim(preg_replace('/^\([^)]*\)/', '', $simulation['atom_second']))); ?></div>
                            <div class="atom-badge" id="atom2Badge">Siap dipilih</div>
                        </div>
                    </div>

                    <div class="simulation-product-message" id="simulationProductMessage">
                        <span><?= chemnama_icon('zap', '#34d399'); ?></span>
                        <div>
                            <strong id="simulationProductTitle"><?= chemnama_e($simulation['product_formula']); ?></strong>
                            <div id="simulationProductText">Atom berhasil menyatu dan membentuk produk baru.</div>
                        </div>
                    </div>

                    <div class="product-result" id="productResult" style="display: none;">
                        <div class="product-molecule">
                            <div class="product-formula"><?= chemnama_e($simulation['product_formula']); ?></div>
                            <div class="product-name"><?= chemnama_e($simulation['product_name']); ?></div>
                            <div class="product-description"><?= chemnama_e($simulation['reaction_type']); ?></div>
                        </div>
                    </div>

                    <div class="click-hint" id="clickHint">
                        <span class="click-dot"></span>
                        Klik kedua atom untuk memulai simulasi
                    </div>

                    <button class="reset-button" id="resetButton" onclick="resetSimulation()" style="display: none;">↻ Ulang Simulasi</button>
                </div>

                <div class="simulation-info simulation-info-grid is-awaiting-output" id="simulationInfoGrid">
                    <div class="info-card info-card--cyan">
                        <div class="info-card-title">Reaktan 1</div>
                        <div class="info-card-value"><?= chemnama_e($simulation['atom_first']); ?></div>
                        <div class="info-card-note">Atom pertama yang dipilih.</div>
                    </div>
                    <div class="info-card info-card--green">
                        <div class="info-card-title">Reaktan 2</div>
                        <div class="info-card-value"><?= chemnama_e($simulation['atom_second']); ?></div>
                        <div class="info-card-note">Atom kedua yang dipilih.</div>
                    </div>
                    <div class="info-card info-card--amber">
                        <div class="info-card-title">Produk</div>
                        <div class="info-card-value"><?= chemnama_e($simulation['product_formula']); ?></div>
                        <div class="info-card-note">Rumus hasil reaksi yang terbentuk.</div>
                    </div>
                    <div class="info-card info-card--violet">
                        <div class="info-card-title">Ikatan</div>
                        <div class="info-card-value"><?= chemnama_e($simulation['bond_type']); ?></div>
                        <div class="info-card-note">Jenis ikatan yang terbentuk.</div>
                    </div>
                    <div class="info-card info-card--rose">
                        <div class="info-card-title">Energi</div>
                        <div class="info-card-value"><?= chemnama_e($simulation['reaction_energy']); ?></div>
                        <div class="info-card-note">Perubahan energi reaksi.</div>
                    </div>
                    <div class="info-card info-card--status is-status-pending" id="simulationStatusCard">
                        <div class="info-card-title">Status</div>
                        <div class="info-card-value" id="simulationStatusValue">Menunggu</div>
                        <div class="info-card-note" id="simulationStatusDetail">Menunggu kedua atom disatukan untuk memulai reaksi.</div>
                    </div>
                </div>
            </div>

            <div class="simulation-details-grid">
                <div class="simulation-description">
                    <h4>💡 Tentang Senyawa Ini</h4>
                    <p><?= chemnama_e($simulation['description_about']); ?></p>
                </div>

                <?php if (!empty($simulation['found_in'])): ?>
                    <div class="simulation-description">
                        <h4>🌍 Ditemukan Di Mana?</h4>
                        <p><?= chemnama_e($simulation['found_in']); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($simulation['daily_usage'])): ?>
                    <div class="simulation-description">
                        <h4>🎯 Kegunaan Sehari-hari</h4>
                        <p><?= chemnama_e($simulation['daily_usage']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
            </article>

        <?php if (count($relatedSimulations) > 0): ?>
            <section class="simulation-related">
                <div class="simulation-related-head">
                    <div>
                        <h2>Simulasi lain dalam modul ini</h2>
                        <p>Pilih simulasi lain tanpa keluar dari modul yang sama.</p>
                    </div>
                </div>

                <div class="simulation-related-grid">
                    <?php foreach ($relatedSimulations as $related): ?>
                        <a class="simulation-related-card" href="siswa_simulasi.php?id=<?= (int) $related['id']; ?>">
                            <span class="simulation-related-badge"><?= chemnama_e((string) $related['module_badge']); ?></span>
                            <strong><?= chemnama_e((string) $related['product_name']); ?></strong>
                            <span><?= chemnama_e((string) $related['atom_first']); ?> + <?= chemnama_e((string) $related['atom_second']); ?> → <?= chemnama_e((string) $related['product_formula']); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        </div>
    </section>
</main>

<script src="assets/js/app.js?v=20260407"></script>
<script>
    function initSimulationBindings() {
        let atom1Clicked = false;
        let atom2Clicked = false;

        const startButton = document.getElementById('startSimulationButton');
        const simulationIntro = document.getElementById('simulationIntro');
        const simulationWorkspace = document.getElementById('simulationWorkspace');
        const atom1 = document.getElementById('atom1');
        const atom2 = document.getElementById('atom2');
        const atom1Charge = document.getElementById('atom1Charge');
        const atom2Charge = document.getElementById('atom2Charge');
        const atom1Label = atom1 ? atom1.querySelector('.atom-label') : null;
        const atom2Label = atom2 ? atom2.querySelector('.atom-label') : null;
        const atom1Badge = document.getElementById('atom1Badge');
        const atom2Badge = document.getElementById('atom2Badge');
        const simulationStage = document.querySelector('.simulation-stage');
        const reactionParticleLayer = document.getElementById('reactionParticleLayer');
        const atomContainer = document.querySelector('.atom-container');
        const productResult = document.getElementById('productResult');
        const clickHint = document.getElementById('clickHint');
        const resetButton = document.getElementById('resetButton');
        const simulationActivityTitle = document.getElementById('simulationActivityTitle');
        const simulationActivityText = document.getElementById('simulationActivityText');
        const simulationProductMessage = document.getElementById('simulationProductMessage');
        const simulationProductTitle = document.getElementById('simulationProductTitle');
        const simulationProductText = document.getElementById('simulationProductText');
        const simulationInfoGrid = document.getElementById('simulationInfoGrid');
        const simulationStatusCard = document.getElementById('simulationStatusCard');
        const simulationStatusValue = document.getElementById('simulationStatusValue');
        const simulationStatusDetail = document.getElementById('simulationStatusDetail');

        function setSimulationStatus(state, detailText) {
            if (!simulationStatusCard || !simulationStatusValue) {
                return;
            }

            simulationStatusCard.classList.remove('is-status-pending', 'is-status-active', 'is-status-done');

            if (state === 'active') {
                simulationStatusCard.classList.add('is-status-active');
                simulationStatusValue.textContent = 'Berlangsung';
            } else if (state === 'done') {
                simulationStatusCard.classList.add('is-status-done');
                simulationStatusValue.textContent = 'Selesai';
            } else {
                simulationStatusCard.classList.add('is-status-pending');
                simulationStatusValue.textContent = 'Menunggu';
            }

            if (detailText) {
                simulationStatusValue.setAttribute('data-status-detail', detailText);
                simulationStatusValue.title = detailText;
                if (simulationStatusDetail) {
                    simulationStatusDetail.textContent = detailText;
                }
            }
        }

        function setSimulationActivity(title, text) {
            if (simulationActivityTitle) simulationActivityTitle.textContent = title;
            if (simulationActivityText) simulationActivityText.textContent = text;
        }

        function showProductMessage(text) {
            if (!simulationProductMessage) return;
            if (simulationProductText) simulationProductText.textContent = text;
            simulationProductMessage.classList.add('is-visible');
        }

        function hideProductMessage() {
            if (simulationProductMessage) simulationProductMessage.classList.remove('is-visible');
        }

        function setAtomState(atomElement, labelElement, badgeElement, labelText, badgeText) {
            if (labelElement) labelElement.textContent = labelText;
            if (badgeElement) {
                badgeElement.textContent = badgeText || (atomElement && atomElement.querySelector('.atom-name') ? atomElement.querySelector('.atom-name').textContent : '');
                atomElement.classList.add('has-badge');
            }
        }

        function setAtomCharge(chargeElement, chargeText, isPositive) {
            if (!chargeElement) {
                return;
            }

            chargeElement.textContent = chargeText;
            chargeElement.classList.toggle('is-positive', isPositive);
            chargeElement.classList.toggle('is-negative', !isPositive);
        }

        function clearElectronParticles() {
            if (!reactionParticleLayer) {
                return;
            }

            reactionParticleLayer.innerHTML = '';
        }

        function emitElectronBurst() {
            if (!reactionParticleLayer || !atom1 || !atom2 || !simulationStage) {
                return;
            }

            clearElectronParticles();

            const stageRect = simulationStage.getBoundingClientRect();
            const atom1Rect = atom1.getBoundingClientRect();
            const atom2Rect = atom2.getBoundingClientRect();
            const startX = atom1Rect.left + atom1Rect.width / 2 - stageRect.left;
            const startY = atom1Rect.top + atom1Rect.height / 2 - stageRect.top;
            const endX = atom2Rect.left + atom2Rect.width / 2 - stageRect.left;
            const endY = atom2Rect.top + atom2Rect.height / 2 - stageRect.top;
            const distanceX = endX - startX;
            const distanceY = endY - startY;

            for (let index = 0; index < 6; index += 1) {
                const particle = document.createElement('span');
                const isReturn = index % 2 === 1;
                const offsetX = (index - 2.5) * 8;
                const offsetY = ((index % 3) - 1) * 10;
                particle.className = `electron-particle${isReturn ? ' is-return' : ''}`;
                particle.style.left = `${startX + offsetX}px`;
                particle.style.top = `${startY + offsetY}px`;
                particle.style.setProperty('--dx', `${distanceX + (isReturn ? -18 : 18)}px`);
                particle.style.setProperty('--dy', `${distanceY + (index % 2 === 0 ? -34 : 24)}px`);
                particle.style.animationDelay = `${index * 0.06}s`;
                reactionParticleLayer.appendChild(particle);
                window.setTimeout(function () {
                    particle.remove();
                }, 1400);
            }
        }

        function resetAtomState(atomElement, labelElement, badgeElement, labelText, badgeText) {
            if (labelElement) labelElement.textContent = labelText;
            if (badgeElement) badgeElement.textContent = badgeText;
            if (atomElement) atomElement.classList.remove('has-badge');
        }

        function checkReaction() {
            if (atom1Clicked && atom2Clicked) {
                showReaction();
            }
        }

        function showReaction() {
            if (!atom1 || !atom2) return;
            if (atomContainer) {
                atomContainer.classList.add('is-reacting');
            }
            if (simulationStage) {
                simulationStage.classList.add('is-reacting');
            }
            atom1.style.transition = 'all 0.5s ease';
            atom2.style.transition = 'all 0.5s ease';
            atom1.style.transform = 'translateX(60px) scale(0.5)';
            atom2.style.transform = 'translateX(-60px) scale(0.5)';
            atom1.style.opacity = '0.2';
            atom2.style.opacity = '0.2';
            atom1.classList.add('is-joined');
            atom2.classList.add('is-joined');
            atom1.classList.add('is-flash');
            atom2.classList.add('is-flash');
            if (simulationStage) {
                simulationStage.classList.add('is-burst');
                setTimeout(function () { simulationStage.classList.remove('is-burst'); }, 820);
            }
            emitElectronBurst();
            if (simulationProductTitle) {
                const formula = document.querySelector('.product-formula') ? document.querySelector('.product-formula').textContent : '';
                simulationProductTitle.textContent = formula;
            }
            setSimulationActivity('Atom bergabung', 'Kedua atom menyatu dan membentuk senyawa baru. Status reaksi sekarang berlangsung sampai produk terbentuk.');
            showProductMessage('Atom berhasil disatukan dan membentuk produk baru.');
            setSimulationStatus('active', 'Kedua atom sudah disatukan, reaksi berlangsung dan sedang membentuk produk baru.');
            setTimeout(function() {
                if (clickHint) clickHint.style.display = 'none';
                if (productResult) productResult.style.display = 'block';
                if (resetButton) resetButton.style.display = 'block';
                if (simulationInfoGrid) {
                    simulationInfoGrid.classList.remove('is-awaiting-output');
                }
                const detailsGrid = document.querySelector('.simulation-details-grid');
                if (detailsGrid) {
                    detailsGrid.classList.add('is-visible');
                }
                if (simulationStage) {
                    simulationStage.classList.add('is-complete');
                }
                setSimulationStatus('done', 'Reaksi selesai. Produk telah terbentuk dan simulasi dapat diulang.');
                setSimulationActivity('Produk terbentuk', 'Reaksi selesai. Produk sudah muncul dan kamu bisa menekan Ulang Simulasi untuk mengulang prosesnya.');
                if (atom1Charge) setAtomCharge(atom1Charge, 'Na⁺', true);
                if (atom2Charge) setAtomCharge(atom2Charge, 'Cl⁻', false);
            }, 600);
        }

        function resetSimulation() {
            atom1Clicked = false; atom2Clicked = false;
            // Hide descriptions when resetting
            if (simulationInfoGrid) {
                simulationInfoGrid.classList.add('is-awaiting-output');
            }
            const detailsGrid = document.querySelector('.simulation-details-grid');
            if (detailsGrid) {
                detailsGrid.classList.remove('is-visible');
            }
            if (atom1) { atom1.style.transition = 'all 0.3s ease'; atom1.style.transform = 'translateX(0) scale(1)'; atom1.style.opacity = '1'; atom1.classList.remove('is-selected','is-joined'); }
            if (atom1) { atom1.classList.remove('is-charged', 'is-flash'); }
            if (atom2) { atom2.style.transition = 'all 0.3s ease'; atom2.style.transform = 'translateX(0) scale(1)'; atom2.style.opacity = '1'; atom2.classList.remove('is-selected','is-joined', 'is-charged', 'is-flash'); }
            if (atomContainer) {
                atomContainer.classList.remove('is-reacting');
            }
            if (simulationStage) {
                simulationStage.classList.remove('is-reacting', 'is-complete');
            }
            resetAtomState(atom1, atom1Label, atom1Badge, 'Klik atom pertama', 'Siap dipilih');
            resetAtomState(atom2, atom2Label, atom2Badge, 'Klik atom kedua', 'Siap dipilih');
            if (atom1Charge) setAtomCharge(atom1Charge, '+1', true);
            if (atom2Charge) setAtomCharge(atom2Charge, '-1', false);
            clearElectronParticles();
            setSimulationStatus('pending', 'Menunggu kedua atom disatukan untuk memulai reaksi.');
            if (productResult) productResult.style.display = 'none';
            hideProductMessage();
            if (clickHint) clickHint.style.display = 'flex';
            if (resetButton) resetButton.style.display = 'none';
            setSimulationActivity('Siap Memulai', 'Klik atom pertama untuk melihat informasi reaktan, lalu klik atom kedua untuk menyatukannya.');
        }

        // Keep the workspace visible immediately on page load.
        if (simulationWorkspace) {
            simulationWorkspace.hidden = false;
            simulationWorkspace.classList.add('is-visible');
        }

        // Attach handlers
        if (startButton && simulationIntro && simulationWorkspace) {
            startButton.addEventListener('click', function () {
                // show workspace in-place under the actions without hiding the intro
                simulationWorkspace.hidden = false;
                simulationWorkspace.classList.add('is-visible','is-entering');
                // scroll to the workspace so it appears under the button
                setTimeout(function () {
                    simulationWorkspace.classList.remove('is-entering');
                }, 380);
                setSimulationActivity('Langkah 1','Klik atom pertama untuk melihat informasi reaktan. Setelah itu klik atom kedua untuk menyatukan keduanya.');
                simulationWorkspace.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        }

        if (atom1) {
            atom1.addEventListener('click', function() {
                if (!atom1Clicked) {
                    atom1Clicked = true;
                    atom1.style.opacity = '0.6'; atom1.style.transform = 'scale(0.9)'; atom1.classList.add('is-selected');
                    setAtomState(atom1, atom1Label, atom1Badge, 'Dipilih - informasi atom pertama');
                    if (atom1Charge) setAtomCharge(atom1Charge, 'Na⁺', true);
                    atom1.classList.add('is-charged');
                    setSimulationActivity('1 atom terpilih', 'Atom pertama sudah dipilih. Sekarang pilih atom kedua agar reaksi dapat berlangsung.');
                    setSimulationStatus('pending', '1 atom terpilih. Pilih atom kedua untuk memulai reaksi.');
                    checkReaction();
                }
            });
        }

        if (atom2) {
            atom2.addEventListener('click', function() {
                if (!atom2Clicked) {
                    atom2Clicked = true;
                    atom2.style.opacity = '0.6'; atom2.style.transform = 'scale(0.9)'; atom2.classList.add('is-selected');
                    setAtomState(atom2, atom2Label, atom2Badge, 'Dipilih - informasi atom kedua');
                    if (atom2Charge) setAtomCharge(atom2Charge, 'Cl⁻', false);
                    atom2.classList.add('is-charged');
                    if (atom1Clicked) setSimulationActivity('Atom kedua dipilih','Kedua atom sudah aktif. Simulasi akan memperlihatkan bagaimana keduanya bergabung menjadi produk.');
                    else setSimulationActivity('Atom kedua dipilih','Atom kedua sudah diaktifkan. Klik atom pertama terlebih dahulu agar reaksi dapat dilanjutkan.');
                    checkReaction();
                }
            });
        }

        if (resetButton) {
            resetButton.addEventListener('click', resetSimulation);
        }

        if (simulationWorkspace && !simulationWorkspace.hidden) {
            setSimulationActivity('Langkah 1','Klik atom pertama untuk melihat informasi reaktan. Setelah itu klik atom kedua untuk menyatukan keduanya.');
        }
        setSimulationStatus('pending', 'Menunggu kedua atom disatukan untuk memulai reaksi.');
        // expose reset for inline onclick fallback
        window.resetSimulation = resetSimulation;
    }

    // Initialize bindings for the initial page
    initSimulationBindings();

    // Intercept clicks on related simulation cards and load content inline
    document.addEventListener('click', function (e) {
        const card = e.target.closest && e.target.closest('.simulation-related-card');
        if (!card) return;
        e.preventDefault();
        fetch(card.href, { credentials: 'same-origin' }).then(function (res) { return res.text(); }).then(function (html) {
            const tmp = document.createElement('div'); tmp.innerHTML = html;
            const newMain = tmp.querySelector('#simulationMainContent');
            if (newMain) {
                const existing = document.getElementById('simulationMainContent');
                if (existing) existing.innerHTML = newMain.innerHTML;
                // update URL
                history.pushState(null, '', card.getAttribute('href'));
                // reinit behaviors on the new content
                initSimulationBindings();
                // scroll into view
                const top = document.getElementById('simulationMainContent').getBoundingClientRect().top + window.scrollY - 20;
                window.scrollTo({ top: top, behavior: 'smooth' });
            }
        }).catch(function () { /* fail silently */ });
    });
</script>
</body>
</html>

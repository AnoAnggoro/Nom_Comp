<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('siswa');

$user = chemnama_current_user();
$userId = (int) ($user['id'] ?? 0);
$moduleId = (int) ($_GET['id'] ?? 0);
$currentStep = (int) ($_GET['step'] ?? 1);
if ($currentStep < 1 || $currentStep > 6) {
    $currentStep = 1;
}

$classStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$classStmt->execute(['user_id' => $userId]);
$studentClass = (string) ($classStmt->fetchColumn() ?: 'X IPA 1');

chemnama_ensure_simulation_reads_table($pdo);
chemnama_ensure_quick_quiz_score_column($pdo);

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

$moduleStmt = $pdo->prepare(
    'SELECT m.* FROM modules m
     WHERE m.id = :id
     LIMIT 1'
);
$moduleStmt->execute(['id' => $moduleId]);
$module = $moduleStmt->fetch();

if (!$module) {
    http_response_code(404);
    header('Location: siswa_materi.php');
    exit;
}

// Get quick quiz (Step 2) - fetch from module_id
$quickQuizStmt = $pdo->prepare(
    'SELECT * FROM quick_quizzes WHERE module_id = :module_id AND is_published = 1 ORDER BY created_at DESC, id DESC LIMIT 1'
);
$quickQuizStmt->execute(['module_id' => $moduleId]);
$quickQuiz = $quickQuizStmt->fetch();

// Get quick quiz attempt
$quickQuizAttempt = null;
if ($quickQuiz) {
    $attemptStmt = $pdo->prepare(
        'SELECT * FROM quick_quiz_attempts WHERE quick_quiz_id = :quiz_id AND student_id = :student_id LIMIT 1'
    );
    $attemptStmt->execute(['quiz_id' => (int)$quickQuiz['id'], 'student_id' => $userId]);
    $quickQuizAttempt = $attemptStmt->fetch();
}

// Get simulations (Step 3) - fetch from module_id
$simulations = [];
$simStmt = $pdo->prepare(
    'SELECT id, product_name, product_formula, atom_first, atom_second, bond_type, molecule_layout,
             reaction_energy, reaction_type, description_about, found_in, daily_usage
     FROM simulations
     WHERE module_id = :module_id AND is_published = 1
     LIMIT 3'
);
$simStmt->execute(['module_id' => $moduleId]);
$simulations = $simStmt->fetchAll();

// Get quiz sets for this module (Step 5)
$quizSets = [];
$quizStmt = $pdo->prepare(
    'SELECT qs.id, qs.title, qs.question_time_limit_seconds, COUNT(q.id) AS total_questions
     FROM quiz_sets qs
     LEFT JOIN questions q ON q.quiz_set_id = qs.id AND q.is_published = 1
     WHERE qs.module_id = :module_id AND qs.is_published = 1
     GROUP BY qs.id
     HAVING total_questions > 0
     LIMIT 1'
);
$quizStmt->execute(['module_id' => $moduleId]);
$quizSets = $quizStmt->fetchAll();

// Get first quiz set questions for inline display
$inlineQuizQuestions = [];
if (!empty($quizSets)) {
    $firstQuizSetId = (int) ($quizSets[0]['id']);
    $questionsStmt = $pdo->prepare(
        'SELECT q.id, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_option, q.points
         FROM questions q
         WHERE q.quiz_set_id = :quiz_set_id AND q.is_published = 1
         ORDER BY q.id'
    );
    $questionsStmt->execute(['quiz_set_id' => $firstQuizSetId]);
    $inlineQuizQuestions = $questionsStmt->fetchAll();
}

// Detect existing submitted Quiz PG attempt for this student + first quiz set.
$restartRequested = isset($_GET['restart']) && $_GET['restart'] === '1';
$savedQuizResultView = null;
if (!empty($quizSets) && !$restartRequested) {
    $lastAttemptStmt = $pdo->prepare(
        'SELECT score, correct_count, total_questions, total_points, submitted_at
         FROM quiz_attempt_runs
         WHERE quiz_set_id = :quiz_set_id AND student_id = :student_id AND submitted_at IS NOT NULL
         ORDER BY submitted_at DESC, id DESC
         LIMIT 1'
    );
    $lastAttemptStmt->execute(['quiz_set_id' => $firstQuizSetId, 'student_id' => $userId]);
    $lastAttempt = $lastAttemptStmt->fetch();
    if ($lastAttempt) {
        $savedQuizResultView = [
            'quiz_set_title' => (string) ($quizSets[0]['title'] ?? 'Quiz'),
            'module_badge' => (string) ($quizSets[0]['module_badge'] ?? '-'),
            'module_title' => (string) ($quizSets[0]['module_title'] ?? '-'),
            'earned_points' => (int) ($lastAttempt['total_points'] ?? 0),
            'max_points' => 0,
            'correct_count' => (int) ($lastAttempt['correct_count'] ?? 0),
            'question_count' => (int) ($lastAttempt['total_questions'] ?? 0),
            'wrong_count' => max(0, (int) ($lastAttempt['total_questions'] ?? 0) - (int) ($lastAttempt['correct_count'] ?? 0)),
            'score' => (int) ($lastAttempt['score'] ?? 0),
        ];
    }
}

// Get games for this module (Step 6)
$games = [];
$tarigaris = [];
$carikataGames = [];

$tarigarisMatcher = $pdo->prepare(
    'SELECT id, left_term, right_term FROM chem_match_games
     WHERE module_id = :module_id AND is_published = 1 LIMIT 5'
);
$tarigarisMatcher->execute(['module_id' => $moduleId]);
$tarigaris = $tarigarisMatcher->fetchAll();

$carikataStmt = $pdo->prepare(
    'SELECT id, target_word, hint FROM word_search_games
     WHERE module_id = :module_id AND is_published = 1 LIMIT 3'
);
$carikataStmt->execute(['module_id' => $moduleId]);
$carikataGames = $carikataStmt->fetchAll();

$activeGameType = (string) ($_GET['play_game'] ?? '');
$activeGameId = (int) ($_GET['game_id'] ?? 0);
$activeGameEmbedUrl = '';

if ($currentStep === 6 && $activeGameType !== '' && $activeGameId > 0) {
    if ($activeGameType === 'tarigaris') {
        $activeGameEmbedUrl = 'siswa_tarigaris.php?id=' . $activeGameId . '&embed=1&reset=1';
    } elseif ($activeGameType === 'carikata') {
        $activeGameEmbedUrl = 'siswa_carikata.php?id=' . $activeGameId . '&embed=1&reset=1';
    }
}

// Get materials for this module (for Step 1 & Step 4)
$materials = [];
$matStmt = $pdo->prepare(
    'SELECT m.id, m.title, m.type, m.youtube_url, m.description, m.content_text, m.file_path, m.created_at, m.is_published, m.created_by, u.name AS teacher_name
     FROM materials m
     JOIN users u ON u.id = m.created_by
     WHERE m.module_id = :module_id AND m.is_published = 1
     ORDER BY m.id'
);
$matStmt->execute(['module_id' => $moduleId]);
$materials = $matStmt->fetchAll();

// If multiple materials exist, only keep those created by the module's connected teacher
// (the teacher who authored the primary material). This enforces "guru yang terhubung".
if (!empty($materials)) {
    $primaryTeacherId = (int) ($materials[0]['created_by'] ?? 0);
    if ($primaryTeacherId > 0) {
        $filtered = [];
        foreach ($materials as $m) {
            if ((int) ($m['created_by'] ?? 0) === $primaryTeacherId) {
                $filtered[] = $m;
            }
        }
        // If filtering removed everything, keep original list as fallback
        if (!empty($filtered)) {
            $materials = $filtered;
        }
    }
}

// Partition materials by type for Step 1 (videos) and Step 4 (text/pdf)
$videoMaterials = [];
$textMaterials = [];
foreach ($materials as $m) {
    $mType = (string) ($m['type'] ?? '');
    $hasYoutube = !empty($m['youtube_url']);
    $hasFile = !empty($m['file_path']);

    if ($hasYoutube || ($mType === 'video' && $hasFile)) {
        $videoMaterials[] = $m;
    }

    if ($mType === 'teks' || $mType === 'pdf') {
        $textMaterials[] = $m;
    }
}

// Use first material as "primary" for display (Step 1)
$primaryMaterial = $materials[0] ?? null;

// Add teacher_name to module from primary material
if ($primaryMaterial) {
    $module['teacher_name'] = $primaryMaterial['teacher_name'];
}

$youtubeEmbedUrl = $primaryMaterial ? chemnama_extract_youtube_embed_url((string) ($primaryMaterial['youtube_url'] ?? '')) : null;
$type = $primaryMaterial ? (string) $primaryMaterial['type'] : 'teks';

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

$typeLabel = $typeLabelMap[$type] ?? ucfirst($type);
$typeColor = $typeColorMap[$type] ?? '#64748b';

// Handle quick quiz submission
$quizMessage = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quick_quiz_answer'])) {
    if ($quickQuiz) {
        $selectedOption = (string) ($_POST['quick_quiz_answer'] ?? '');
        if (in_array($selectedOption, ['a', 'b', 'c', 'd'])) {
            $isCorrect = ($selectedOption === (string)$quickQuiz['correct_option']) ? 1 : 0;
            
            $insertStmt = $pdo->prepare(
                'INSERT INTO quick_quiz_attempts (quick_quiz_id, student_id, selected_option, is_correct, score)
                 VALUES (:quiz_id, :student_id, :option, :is_correct, :score)
                 ON DUPLICATE KEY UPDATE
                    selected_option = VALUES(selected_option),
                    is_correct = VALUES(is_correct),
                    score = VALUES(score),
                    attempted_at = CURRENT_TIMESTAMP'
            );
            $insertStmt->execute([
                'quiz_id' => (int)$quickQuiz['id'],
                'student_id' => $userId,
                'option' => $selectedOption,
                'is_correct' => $isCorrect,
                'score' => $isCorrect ? 100 : 0,
            ]);
            
            $quizMessage = $isCorrect
                ? ['type' => 'success', 'text' => 'Nilai tersimpan: 100/100. Jawaban benar!']
                : ['type' => 'error', 'text' => 'Nilai tersimpan: 0/100. Jawaban masih salah.'];
            
            // Refresh attempt data
            $attemptStmt = $pdo->prepare(
                'SELECT * FROM quick_quiz_attempts WHERE quick_quiz_id = :quiz_id AND student_id = :student_id LIMIT 1'
            );
            $attemptStmt->execute(['quiz_id' => (int)$quickQuiz['id'], 'student_id' => $userId]);
            $quickQuizAttempt = $attemptStmt->fetch();

            header('Location: siswa_materi_detail.php?id=' . $moduleId . '&step=3');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Materi Pembelajaran - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .materi-steps-wrapper {
            display: grid;
            gap: 24px;
        }

        .materi-steps-nav {
            display: flex;
            align-items: center;
            position: relative;
            padding: 32px 0;
            overflow-x: auto;
            scroll-behavior: smooth;
            gap: 150px;
        }

        .materi-steps-nav::-webkit-scrollbar {
            height: 6px;
        }

        .materi-steps-nav::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }

        .materi-steps-nav::-webkit-scrollbar-thumb {
            background: rgba(124, 58, 237, 0.4);
            border-radius: 3px;
        }

        /* Garis penghubung background */
        .materi-steps-nav::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, rgba(124, 58, 237, 0.2) 0%, rgba(124, 58, 237, 0.2) 100%);
            transform: translateY(-50%);
            z-index: 0;
            pointer-events: none;
        }

        .materi-step-btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
            padding: 0;
            border: none;
            background: transparent;
            color: rgba(255, 255, 255, 0.6);
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            white-space: nowrap;
            text-decoration: none;
            position: relative;
            z-index: 2;
            min-width: 80px;
            text-align: center;
            flex: 0 0 auto;
        }

        .materi-step-btn:hover .materi-step-number {
            transform: scale(1.1);
            box-shadow: 0 0 16px rgba(124, 58, 237, 0.4);
        }

        .materi-step-btn.is-active {
            color: #ffffff;
        }

        .materi-step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            border: 2px solid rgba(255, 255, 255, 0.15);
            font-size: 14px;
            font-weight: 800;
            color: rgba(255, 255, 255, 0.6);
            transition: all 0.3s ease;
        }

        .materi-step-btn.is-active .materi-step-number {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            border-color: #7c3aed;
            color: #ffffff;
            box-shadow: 0 0 20px rgba(124, 58, 237, 0.5);
        }

        .materi-step-btn.is-completed .materi-step-number {
            background: linear-gradient(135deg, #10b981, #059669);
            border-color: #10b981;
            color: #ffffff;
            box-shadow: 0 0 18px rgba(16, 185, 129, 0.35);
        }

        .materi-step-check {
            font-size: 18px;
            line-height: 1;
            font-weight: 900;
        }

        .materi-steps-content {
            min-height: 400px;
            opacity: 1; 
        }

        .step-panel {
            display: none;
            width: 100%;
        }

        .step-panel.is-active {
            display: block;
            width: 100%;
            gap: 16px;
        }

        .quick-quiz-container {
            border-radius: 16px;
            padding: 24px;
            gap: 20px;
            display: grid;
            width: 100%;
        }

        .quick-quiz-question {
            display: grid;
            gap: 12px;
        }

        .quick-quiz-image {
            border-radius: 12px;
            max-width: 100%;
            max-height: 300px;
            margin: 0 auto;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .quick-quiz-text {
            font-size: 1.1rem;
            font-weight: 600;
            color: #e2e8f0;
            line-height: 1.5;
        }

        .quick-quiz-options {
            display: grid;
            gap: 10px;
        }

        .quiz-option-btn {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border: 2px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            background: rgba(255, 255, 255, 0.02);
            color: #cbd5e1;
            cursor: pointer;
            transition: all 0.25s ease;
            text-align: left;
            font-size: 0.95rem;
            font-weight: 500;
        }

        .quiz-option-btn:hover:not(:disabled) {
            border-color: rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.05);
            color: #e2e8f0;
        }

        .quiz-option-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .quiz-option-btn.is-correct {
            border-color: #10b981;
            background: rgba(16, 185, 129, 0.15);
            color: #86efac;
        }

        .quiz-option-btn.is-incorrect {
            border-color: #ef4444;
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
        }

        .quiz-option-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 28px;
            height: 28px;
            border-radius: 6px;
            background: rgba(255, 255, 255, 0.08);
            font-weight: 700;
            font-size: 12px;
            flex-shrink: 0;
        }

        .quiz-option-btn.is-correct .quiz-option-label {
            background: rgba(16, 185, 129, 0.25);
            color: #86efac;
        }

        .quiz-option-btn.is-incorrect .quiz-option-label {
            background: rgba(239, 68, 68, 0.25);
            color: #fca5a5;
        }

        .quick-quiz-message {
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .quick-quiz-message.success {
            background: rgba(16, 185, 129, 0.15);
            color: #86efac;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .quick-quiz-message.error {
            background: rgba(239, 68, 68, 0.15);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        /* Notification Toast */
        .quiz-notification-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 300ms ease;
        }

        .quiz-notification-overlay.is-active {
            opacity: 1;
            pointer-events: auto;
        }

        .quiz-notification-card {
            background: linear-gradient(135deg, rgba(124, 58, 237, 0.95), rgba(109, 40, 217, 0.95));
            padding: 32px 40px;
            border-radius: 20px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            transform: scale(0.95);
            transition: transform 300ms cubic-bezier(0.16, 1, 0.3, 1);
            max-width: 400px;
            width: 90%;
        }

        .quiz-notification-overlay.is-active .quiz-notification-card {
            transform: scale(1);
        }

        .quiz-notification-icon {
            font-size: 3.5rem;
            margin-bottom: 16px;
            display: inline-block;
        }

        .quiz-notification-text {
            color: #ffffff;
            font-size: 1.15rem;
            font-weight: 600;
            margin: 0;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .quiz-notification-subtext {
            color: rgba(255, 255, 255, 0.8);
            font-size: 0.9rem;
            margin: 0;
            margin-bottom: 20px;
        }

        .quiz-notification-progress {
            height: 3px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 2px;
            overflow: hidden;
            margin-top: 20px;
        }

        .quiz-notification-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #06b6d4);
            animation: quiz-progress 2.5s ease-in-out forwards;
        }

        @keyframes quiz-progress {
            0% { width: 0%; }
            100% { width: 100%; }
        }

        /* Module completion notification */
        .module-completion-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 300ms ease;
        }

        .module-completion-overlay.is-active {
            opacity: 1;
            pointer-events: auto;
        }

        .module-completion-card {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.95), rgba(5, 150, 105, 0.95));
            padding: 28px 32px;
            border-radius: 16px;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            transform: scale(0.95);
            transition: transform 300ms cubic-bezier(0.16, 1, 0.3, 1);
            max-width: 360px;
            width: 90%;
        }

        .module-completion-overlay.is-active .module-completion-card {
            transform: scale(1);
        }

        .module-completion-icon {
            font-size: 2.5rem;
            margin-bottom: 16px;
            display: inline-block;
            animation: completion-bounce 600ms cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        @keyframes completion-bounce {
            0% { transform: scale(0) rotate(-180deg); opacity: 0; }
            100% { transform: scale(1) rotate(0); opacity: 1; }
        }

        .module-completion-text {
            color: #ffffff;
            font-size: 1rem;
            font-weight: 700;
            margin: 0 0 8px 0;
            line-height: 1.5;
        }

        .module-completion-subtext {
            color: rgba(255, 255, 255, 0.9);
            font-size: 0.85rem;
            margin: 0 0 20px 0;
            line-height: 1.4;
        }

        .module-completion-progress {
            height: 3px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 2px;
            overflow: hidden;
        }

        .module-completion-progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #10b981, #34d399);
            animation: completion-progress 2s ease-in-out forwards;
        }

        @keyframes completion-progress {
            0% { width: 0%; }
            100% { width: 100%; }
        }

        /* Quiz feedback animations */
        .quiz-feedback-overlay { position: absolute; inset: 0; display:flex; align-items:center; justify-content:center; pointer-events:none; z-index:999; opacity:0; transition: opacity 220ms ease; }
        .quiz-feedback-overlay.is-visible { opacity:1; pointer-events:auto; }
        .quiz-feedback-card { display:flex; flex-direction:column; align-items:center; gap:12px; transform: translateY(8px) scale(0.96); transition: transform 260ms cubic-bezier(.2,.9,.3,1); }
        .quiz-feedback-overlay.is-visible .quiz-feedback-card { transform: translateY(0) scale(1); }
        .quiz-emoji { font-size: 4.2rem; filter: drop-shadow(0 10px 24px rgba(0,0,0,0.36)); }
        .quiz-score-badge { display:inline-flex; align-items:center; justify-content:center; min-width:56px; height:36px; padding:6px 12px; border-radius:999px; font-weight:900; color:#fff; background:linear-gradient(90deg,#f59e0b,#ea580c); box-shadow:0 10px 24px rgba(245,158,11,0.18); }

        .games-showcase-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 18px;
        }

        .game-showcase-card {
            display: grid;
            gap: 14px;
            padding: 20px;
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.05), rgba(255, 255, 255, 0.015));
            box-shadow: 0 18px 34px rgba(4, 1, 25, 0.35);
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }

        .game-showcase-card:hover {
            transform: translateY(-3px);
            border-color: rgba(255, 255, 255, 0.18);
            box-shadow: 0 22px 40px rgba(4, 1, 25, 0.45);
        }

        .game-showcase-card.is-clickable {
            color: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .game-showcase-icon {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.95rem;
            font-weight: 800;
            letter-spacing: 0.03em;
            color: #fff;
            margin: 0 auto;
        }

        .game-showcase-icon.is-tarik {
            background: linear-gradient(140deg, rgba(245, 158, 11, 0.95), rgba(249, 115, 22, 0.95));
        }

        .game-showcase-icon.is-carikata {
            background: linear-gradient(140deg, rgba(124, 58, 237, 0.95), rgba(168, 85, 247, 0.95));
        }

        .game-showcase-title {
            margin: 0;
            font-size: 1.2rem;
            line-height: 1.2;
            color: #e2e8f0;
            text-align: left;
        }

        .game-showcase-desc {
            margin: 0;
            color: rgba(226, 232, 240, 0.72);
            font-size: 1rem;
            line-height: 1.45;
        }

        .game-showcase-play {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            min-height: 42px;
            border-radius: 999px;
            text-decoration: none;
            color: #fff;
            font-weight: 800;
            letter-spacing: 0.01em;
            transition: filter 0.2s ease, transform 0.2s ease;
        }

        .game-showcase-play.is-tarik {
            background: linear-gradient(90deg, #f59e0b, #ea580c);
        }

        .game-showcase-play.is-carikata {
            background: linear-gradient(90deg, #9333ea, #7c3aed);
        }

        .game-showcase-play:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
        }

        .simulation-showcase-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 2px;
        }

        .simulation-showcase-chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 9px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.07);
            color: rgba(226, 232, 240, 0.9);
            font-size: 0.78rem;
            font-weight: 700;
        }

        .simulation-showcase-desc {
            margin: 0;
            color: rgba(226, 232, 240, 0.76);
            line-height: 1.5;
            font-size: 0.95rem;
        }

        .simulation-showcase-link {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            width: 100%;
            min-height: 42px;
            border-radius: 999px;
            text-decoration: none;
            color: #fff;
            font-weight: 800;
            letter-spacing: 0.01em;
            background: linear-gradient(90deg, #14b8a6, #0f766e);
            transition: filter 0.2s ease, transform 0.2s ease;
        }

        .simulation-showcase-link:hover {
            filter: brightness(1.08);
            transform: translateY(-1px);
        }

        .simulation-inline-frame {
            width: 100%;
            min-height: 980px;
            height: 92vh;
            border: 0;
            border-radius: 18px;
            overflow: hidden;
            background: rgba(18, 8, 41, 1);
            box-shadow: 0 18px 34px rgba(4, 1, 25, 0.28);
            display: block;
        }

        .simulation-inline-note {
            margin: 0 0 14px;
            color: rgba(226, 232, 240, 0.74);
            font-size: 0.92rem;
            line-height: 1.45;
        }

        .games-showcase-grid.is-hidden {
            display: none;
        }

        .games-showcase-grid.is-hidden {
            display: none;
        }

        .games-inline-launcher {
            margin-top: 22px;
            padding: 20px;
            width: 100%;
            max-width: 1100px;
            margin-left: auto;
            margin-right: auto;
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            background: rgba(255, 255, 255, 0.03);
            box-shadow: 0 18px 34px rgba(4, 1, 25, 0.28);
            box-sizing: border-box;
        }

        .games-inline-launcher-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .games-inline-launcher-head h4 {
            margin: 0;
            color: #e2e8f0;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .games-inline-launcher-head a {
            color: #fbbf24;
            font-weight: 700;
            text-decoration: none;
        }

        .games-inline-frame {
            width: 100%;
            height: 78vh;
            min-height: 720px;
            border: 0;
            border-radius: 18px;
            background: rgba(18, 8, 41, 1);
            overflow: hidden;
            display: block;
        }

        @media (max-width: 640px) {
            .games-showcase-grid {
                grid-template-columns: 1fr;
            }

            .game-showcase-card {
                padding: 16px;
            }

            .game-showcase-title {
                font-size: 1.1rem;
            }

            .games-inline-launcher {
                margin-inline: -14px;
                width: calc(100% + 28px);
                padding: 14px;
                padding-inline: 14px;
                max-width: none;
            }

            .games-inline-frame {
                min-height: 700px;
                height: 78vh;
            }
        }

        .step-content-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }

        .step-content-header h3 {
            margin: 0;
            font-size: 1.2rem;
        }

        .step-content-chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(124, 58, 237, 0.12);
            color: #c4b5fd;
            font-size: 11px;
            font-weight: 800;
        }

        /* Material card styles for Step 4 */
        .materi-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
            border: 1px solid rgba(255,255,255,0.05);
            border-radius: 14px;
            padding: 22px;
            display: block;
        }

        .materi-card-head {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 18px;
        }

        .materi-card-icon {
            width: 64px;
            height: 64px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            flex-shrink: 0;
        }

        .materi-card-meta h4 {
            margin: 0 0 6px 0;
            font-size: 1.15rem;
            color: #e2e8f0;
        }

        .materi-type {
            display: inline-block;
            font-size: 12px;
            color: #c4b5fd;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .materi-meta-line {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.6);
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .materi-read {
            color: #7c3aed;
            font-weight: 700;
        }

        .materi-card-body {
            padding-top: 8px;
            color: #cbd5e1;
            line-height: 1.8;
            font-size: 0.98rem;
        }

        .materi-download-btn {
            display: inline-block;
            margin-top: 12px;
            padding: 10px 14px;
            background: linear-gradient(90deg, #7c3aed, #6d28d9);
            color: #fff;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 700;
        }

        .quiz-inline-container {
            display: grid;
            gap: 20px;
        }

        .quiz-inline-header {
            padding: 12px 14px;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .quiz-inline-header small {
            display: inline-block;
            margin-bottom: 8px;
            padding: 4px 8px;
            background: rgba(168, 85, 247, 0.12);
            color: #d8b4fe;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .quiz-inline-header h4 {
            margin: 4px 0 8px;
            font-size: 1.1rem;
            color: #f1f5f9;
        }

        .quiz-inline-header p {
            margin: 0;
            color: #94a3b8;
            font-size: 0.9rem;
        }

        .quiz-inline-card {
            padding: 12px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .quiz-inline-card:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: rgba(255, 255, 255, 0.12);
        }

        .quiz-inline-card.is-question {
            padding: 16px;
            border-radius: 12px;
        }

        .quiz-inline-label {
            display: inline-block;
            margin-bottom: 8px;
            padding: 4px 8px;
            background: rgba(59, 130, 246, 0.12);
            color: #93c5fd;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .quiz-inline-card.is-question p {
            margin: 0;
            margin-top: 8px;
            font-size: 1rem;
            font-weight: 600;
            color: #f1f5f9;
            line-height: 1.6;
        }

        .quiz-question-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 12px;
        }

        .quiz-question-timer {
            display: inline-flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 2px;
            padding: 10px 14px 11px;
            border-radius: 14px;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.98), rgba(238, 242, 255, 0.98));
            box-shadow: 0 10px 24px rgba(7, 10, 34, 0.22);
            border: 1px solid rgba(147, 197, 253, 0.28);
            min-width: 112px;
        }

        .quiz-question-timer:empty {
            display: none;
        }

        .quiz-question-timer-label {
            color: #1e3a8a;
            font-size: 0.64rem;
            font-weight: 900;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            line-height: 1;
        }

        .quiz-question-timer-value {
            color: #1d4ed8;
            font-size: 1.05rem;
            font-weight: 900;
            letter-spacing: 0.03em;
            line-height: 1;
            min-width: 52px;
            text-align: right;
        }

        .quiz-inline-options {
            display: grid;
            gap: 10px;
        }

        .quiz-inline-card.is-option {
            display: grid;
            grid-template-columns: auto 24px 1fr;
            gap: 12px;
            align-items: center;
            cursor: pointer;
            padding: 12px;
        }

        .quiz-inline-letter {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
            background: rgba(124, 58, 237, 0.12);
            color: #c084fc;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 700;
        }

        .quiz-inline-card.is-option input[type="radio"]:checked + .quiz-inline-letter {
            background: linear-gradient(90deg, #7c3aed, #6d28d9);
            color: #fff;
        }

        .quiz-inline-text {
            display: block;
            color: #cbd5e1;
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .quiz-final-summary {
            display: none;
            padding: 20px;
            border-radius: 18px;
           
            box-shadow: 0 16px 34px rgba(2, 8, 20, 0.55);
            transform: translateY(8px) scale(0.98);
            opacity: 0;
            transition: opacity 240ms ease, transform 240ms ease;
        }

        .quiz-final-summary.is-visible {
            display: block;
            opacity: 1;
            transform: translateY(0) scale(1);
        }

        .quiz-final-topline {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .quiz-final-kicker {
            display: inline-flex;
            align-items: center;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 900;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            color: #dbeafe;
            background: rgba(59, 130, 246, 0.2);
            border: 1px solid rgba(96, 165, 250, 0.35);
        }

        .quiz-final-points {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #fef3c7;
            font-weight: 800;
        }

        .quiz-final-points strong {
            font-size: clamp(1.5rem, 2.6vw, 2rem);
            line-height: 1;
            color: #fde68a;
            text-shadow: 0 0 12px rgba(251, 191, 36, 0.35);
        }

        .quiz-final-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
        }

        .quiz-stat-card {
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.24);
            background: rgba(15, 23, 42, 0.55);
            padding: 12px 12px;
        }

        .quiz-stat-card .label {
            display: block;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #93c5fd;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .quiz-stat-card .value {
            display: block;
            font-size: 1.35rem;
            line-height: 1.1;
            font-weight: 900;
            color: #f8fafc;
        }

        .quiz-stat-card.is-good {
            border-color: rgba(52, 211, 153, 0.45);
            background: rgba(16, 185, 129, 0.12);
        }

        .quiz-stat-card.is-good .label { color: #6ee7b7; }
        .quiz-stat-card.is-good .value { color: #d1fae5; }

        .quiz-stat-card.is-bad {
            border-color: rgba(248, 113, 113, 0.45);
            background: rgba(239, 68, 68, 0.12);
        }

        .quiz-stat-card.is-bad .label { color: #fda4af; }
        .quiz-stat-card.is-bad .value { color: #ffe4e6; }

        .button-primary {
            display: inline-block;
            padding: 12px 24px;
            background: linear-gradient(90deg, #7c3aed, #6d28d9);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .button-primary:hover {
            box-shadow: 0 0 20px rgba(124, 58, 237, 0.4);
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

    <section class="guru-content student-content siswa-materi-page">
        <div class="siswa-materi-head">
            <h1>Pembelajaran Materi</h1>
            <p>5 Langkah Belajar Efektif</p>
            <a class="siswa-materi-back" href="siswa_materi.php"><?= chemnama_icon('switch', '#64748b'); ?> Kembali</a>
        </div>

        <?php if (!$module): ?>
            <article class="guru-panel siswa-materi-empty">
                <h3>Materi tidak ditemukan</h3>
                <p>Materi ini tidak tersedia untuk kelas Anda.</p>
            </article>
        <?php else: ?>
            <article class="guru-panel">
                <div class="siswa-materi-detail-head">
                    <span class="siswa-module-icon" style="color: <?= chemnama_e($typeColor); ?>; background: <?= chemnama_e($typeColor); ?>14;">
                        <?= chemnama_icon('file', $typeColor); ?>
                    </span>
                    <div>
                        <small><?= chemnama_e((string) $module['badge']); ?> · <?= chemnama_e($typeLabel); ?></small>
                        <h2><?= chemnama_e((string) $module['title']); ?></h2>
                        <div class="siswa-materi-meta-bottom">
                            <span><?= chemnama_e((string) $module['teacher_name']); ?></span>
                            <span><?= $primaryMaterial ? chemnama_e((string) $primaryMaterial['created_at']) : 'Modul'; ?></span>
                        </div>
                    </div>
                </div>

                <div class="materi-steps-wrapper">
                    <div class="materi-steps-nav" data-steps-nav>
                        <a href="?id=<?= $moduleId; ?>&step=1" class="materi-step-btn <?= $currentStep == 1 ? 'is-active' : ''; ?> <?= $currentStep > 1 ? 'is-completed' : ''; ?>" data-step-btn="1" title="Video Pembelajaran">
                            <span class="materi-step-number"><?= $currentStep > 1 ? '<span class="materi-step-check">✓</span>' : '1'; ?></span>
                            <span>Video</span>
                        </a>
                        <a href="?id=<?= $moduleId; ?>&step=2" class="materi-step-btn <?= $currentStep == 2 ? 'is-active' : ''; ?> <?= $currentStep > 2 ? 'is-completed' : ''; ?>" data-step-btn="2" title="Quick Quiz">
                            <span class="materi-step-number"><?= $currentStep > 2 ? '<span class="materi-step-check">✓</span>' : '2'; ?></span>
                            <span>Quick Quiz</span>
                        </a>
                        <a href="?id=<?= $moduleId; ?>&step=3" class="materi-step-btn <?= $currentStep == 3 ? 'is-active' : ''; ?> <?= $currentStep > 3 ? 'is-completed' : ''; ?>" data-step-btn="3" title="Simulasi">
                            <span class="materi-step-number"><?= $currentStep > 3 ? '<span class="materi-step-check">✓</span>' : '3'; ?></span>
                            <span>Simulasi</span>
                        </a>
                        <a href="?id=<?= $moduleId; ?>&step=4" class="materi-step-btn <?= $currentStep == 4 ? 'is-active' : ''; ?> <?= $currentStep > 4 ? 'is-completed' : ''; ?>" data-step-btn="4" title="Materi">
                            <span class="materi-step-number"><?= $currentStep > 4 ? '<span class="materi-step-check">✓</span>' : '4'; ?></span>
                            <span>Materi</span>
                        </a>
                        <a href="?id=<?= $moduleId; ?>&step=5" class="materi-step-btn <?= $currentStep == 5 ? 'is-active' : ''; ?> <?= $currentStep > 5 ? 'is-completed' : ''; ?>" data-step-btn="5" title="Kuis PG">
                            <span class="materi-step-number"><?= $currentStep > 5 ? '<span class="materi-step-check">✓</span>' : '5'; ?></span>
                            <span>Kuis PG</span>
                        </a>
                        <a href="?id=<?= $moduleId; ?>&step=6" class="materi-step-btn <?= $currentStep == 6 ? 'is-active' : ''; ?> <?= $currentStep > 6 ? 'is-completed' : ''; ?>" data-step-btn="6" title="Games">
                            <span class="materi-step-number"><?= $currentStep > 6 ? '<span class="materi-step-check">✓</span>' : '6'; ?></span>
                            <span>Games</span>
                        </a>
                    </div>

                    <div class="materi-steps-content">
                        <!-- STEP 1: VIDEO -->
                        <div class="step-panel <?= $currentStep == 1 ? 'is-active' : ''; ?>" data-step-panel="1">
                            <div class="step-content-header">
                                <h3><?= chemnama_icon('film', '#0ea5e9'); ?> Video Pembelajaran</h3>
                            </div>

                            <?php if (!empty($videoMaterials)): ?>
                                <?php foreach ($videoMaterials as $vmat): ?>
                                    <?php $embed = chemnama_extract_youtube_embed_url((string) ($vmat['youtube_url'] ?? '')); ?>
                                    <?php $videoStreamUrl = !empty($vmat['file_path']) ? 'material_file.php?material_id=' . (int) ($vmat['id'] ?? 0) : ''; ?>
                                    <?php if ($embed !== null): ?>
                                        <div class="siswa-materi-video-wrap">
                                            <iframe src="<?= chemnama_e($embed); ?>" title="<?= chemnama_e((string) $vmat['title']); ?>" loading="lazy" allowfullscreen></iframe>
                                        </div>
                                    <?php elseif (!empty($vmat['file_path'])): ?>
                                        <div class="siswa-materi-video-wrap">
                                            <video controls preload="metadata" playsinline style="width:100%; border-radius:16px; background:#000;">
                                                <source src="<?= chemnama_e($videoStreamUrl); ?>" type="video/mp4">
                                                Browser Anda tidak mendukung pemutaran video ini.
                                            </video>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="padding:40px; text-align:center; background:rgba(255,255,255,0.03); border-radius:12px; color:#94a3b8;">
                                    <p>Video pembelajaran belum tersedia.</p>
                                </div>
                            <?php endif; ?>

                        </div>

                        <!-- STEP 2: QUICK QUIZ -->
                        <div class="step-panel <?= $currentStep == 2 ? 'is-active' : ''; ?>" data-step-panel="2">
                            <div class="step-content-header">
                                <h3><?= chemnama_icon('check', '#ec4899'); ?> Quick Quiz</h3>
                                <span class="step-content-chip">1 Soal</span>
                            </div>

                            <?php if ($quickQuiz): ?>
                                <div class="quick-quiz-container">
                                    <?php
                                    $quickQuizScore = (int) ($quickQuizAttempt['score'] ?? 0);
                                    if ($quickQuizScore <= 0 && (int) ($quickQuizAttempt['is_correct'] ?? 0) === 1) {
                                        $quickQuizScore = 100;
                                    }
                                    $quickQuizIsCorrect = (int) ($quickQuizAttempt['is_correct'] ?? 0) === 1;
                                    ?>

                                    <?php if ($quickQuizAttempt && !$restartRequested): ?>
                                        <article class="guru-panel siswa-quiz-result-card">
                                            <div class="siswa-quiz-result-head">
                                                <div>
                                                    <span class="siswa-quiz-eyebrow">Hasil quiz</span>
                                                    <h2><?= chemnama_e((string) $module['title']); ?></h2>
                                                    <p><?= chemnama_e((string) ($module['badge'] ?? 'Quick Quiz')); ?></p>
                                                </div>
                                                <div class="siswa-quiz-score-badge"><?= chemnama_e((string) $quickQuizScore); ?>p</div>
                                            </div>

                                            <div class="siswa-quiz-result-grid">
                                                <div class="siswa-quiz-result-stat is-total">
                                                    <strong><?= chemnama_e((string) $quickQuizScore); ?></strong>
                                                    <span>Poin</span>
                                                </div>
                                                <div class="siswa-quiz-result-stat is-correct">
                                                    <strong><?= $quickQuizIsCorrect ? '1' : '0'; ?></strong>
                                                    <span>Jawaban benar</span>
                                                </div>
                                                <div class="siswa-quiz-result-stat is-wrong">
                                                    <strong><?= $quickQuizIsCorrect ? '0' : '1'; ?></strong>
                                                    <span>Jawaban salah</span>
                                                </div>
                                                <div class="siswa-quiz-result-stat is-total">
                                                    <strong>1</strong>
                                                    <span>Total soal</span>
                                                </div>
                                            </div>

                                            <p class="siswa-quiz-result-note">Nilai tersimpan: <?= chemnama_e((string) $quickQuizScore); ?> poin (<?= chemnama_e((string) $quickQuizScore); ?>%).</p>

                                            <div class="siswa-quiz-action-row">
                                                <a class="btn btn-primary" href="?id=<?= (int) $moduleId; ?>&step=2&restart=1">Ulangi Quick Quiz</a>
                                            </div>
                                        </article>
                                    <?php else: ?>
                                        <!-- Notification Toast -->
                                        <div class="quiz-notification-overlay" data-quiz-notification>
                                            <div class="quiz-notification-card">
                                                <div class="quiz-notification-icon">✓</div>
                                                <p class="quiz-notification-text">Terimakasih sudah menjawab!</p>
                                                <p class="quiz-notification-subtext">Silahkan lanjut ke tahap berikutnya</p>
                                                <div class="quiz-notification-progress">
                                                    <div class="quiz-notification-progress-bar"></div>
                                                </div>
                                            </div>
                                        </div>

                                        <form method="post" data-quick-quiz-form>
                                            <div class="quick-quiz-question">
                                                <?php if (!empty($quickQuiz['image_url'])): ?>
                                                    <img src="<?= chemnama_e((string) $quickQuiz['image_url']); ?>" alt="Quiz image" class="quick-quiz-image">
                                                <?php endif; ?>
                                                <div class="quick-quiz-text"><?= chemnama_e((string) $quickQuiz['question_text']); ?></div>
                                            </div>

                                            <div class="quick-quiz-options">
                                                <?php
                                                $optionLabels = ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];
                                                foreach (['a', 'b', 'c', 'd'] as $option):
                                                    $optionValue = (string) $quickQuiz['option_' . $option];
                                                ?>
                                                    <button type="button"
                                                        class="quiz-option-btn"
                                                        data-option="<?= chemnama_e($option); ?>">
                                                        <span class="quiz-option-label"><?= chemnama_e($optionLabels[$option]); ?></span>
                                                        <span><?= chemnama_e($optionValue); ?></span>
                                                    </button>
                                                <?php endforeach; ?>
                                            </div>

                                            <input type="hidden" name="quick_quiz_answer" id="quizAnswerInput">
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div style="padding:40px; text-align:center; background:rgba(255,255,255,0.03); border-radius:12px; color:#94a3b8;">
                                    <p>Soal quick quiz belum tersedia.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- STEP 3: SIMULASI -->
                        <div class="step-panel <?= $currentStep == 3 ? 'is-active' : ''; ?>" data-step-panel="3">
                            <div class="step-content-header">
                                <h3><?= chemnama_icon('beaker', '#14b8a6'); ?> Simulasi Reaksi</h3>
                                <span class="step-content-chip"><?= count($simulations); ?> Simulasi</span>
                            </div>

                            <?php if (!empty($simulations)): ?>
                                <?php $inlineSimulation = $simulations[0] ?? null; ?>
                                <?php if ($inlineSimulation): ?>
                                    <iframe
                                        class="simulation-inline-frame"
                                        src="siswa_simulasi.php?id=<?= (int) $inlineSimulation['id']; ?>&module_id=<?= $moduleId; ?>&embed=1"
                                        title="<?= chemnama_e((string) $inlineSimulation['product_name']); ?>"
                                        loading="lazy"
                                        allowfullscreen></iframe>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="padding:40px; text-align:center; background:rgba(255,255,255,0.03); border-radius:12px; color:#94a3b8;">
                                    <p>Simulasi reaksi belum tersedia untuk materi ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- STEP 4: MATERI -->
                        <div class="step-panel <?= $currentStep == 4 ? 'is-active' : ''; ?>" data-step-panel="4">
                            <div class="step-content-header">
                                <h3><?= chemnama_icon('file', '#f59e0b'); ?> Materi Pembelajaran</h3>
                            </div>

                            <?php if (!empty($textMaterials)): ?>
                                <?php foreach ($textMaterials as $mat): ?>
                                    <article class="materi-card">
                                        <div class="materi-card-head">
                                            <div class="materi-card-icon" style="background: <?= chemnama_e($typeColorMap[$mat['type']] ?? $typeColor); ?>22; color: <?= chemnama_e($typeColorMap[$mat['type']] ?? $typeColor); ?>;">
                                                <?= chemnama_icon('file', $typeColorMap[$mat['type']] ?? $typeColor); ?>
                                            </div>
                                            <div class="materi-card-meta">
                                                <small class="materi-type"><?= chemnama_e($typeLabelMap[$mat['type']] ?? $typeLabel); ?></small>
                                                <h4><?= chemnama_e((string) $mat['title']); ?></h4>
                                                <div class="materi-meta-line">
                                                    <span><?= chemnama_e((string) $mat['teacher_name']); ?></span>
                                                    <span>·</span>
                                                    <span><?= chemnama_e((string) $mat['created_at']); ?></span>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="materi-card-body">
                                            <?php if (!empty($mat['description'])): ?>
                                                <p style="margin-bottom: 12px; font-size: 0.95rem; color: #94a3b8;"><?= nl2br(chemnama_e((string) $mat['description'])); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($mat['content_text'])): ?>
                                                <div style="margin-bottom: 12px;">
                                                    <?= nl2br(chemnama_e((string) $mat['content_text'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($mat['type'] === 'pdf' && !empty($mat['file_path'])): ?>
                                                <a class="materi-download-btn" href="<?= chemnama_e((string) $mat['file_path']); ?>" download target="_blank">📥 Unduh Dokumen</a>
                                            <?php endif; ?>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div style="padding:40px; text-align:center; background:rgba(255,255,255,0.03); border-radius:12px; color:#94a3b8;">
                                    <p>Catatan materi belum tersedia.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- STEP 5: QUIZ PG -->
                        <div class="step-panel <?= $currentStep == 5 ? 'is-active' : ''; ?>" data-step-panel="5">
                            <div class="step-content-header">
                                <h3><?= chemnama_icon('edit', '#d97706'); ?> Quiz Pilihan Ganda</h3>
                                <?php if (!empty($quizSets)): ?>
                                    <span class="step-content-chip"><?= (int) ($quizSets[0]['total_questions'] ?? 0); ?> Soal</span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($quizSets) && $savedQuizResultView && !$restartRequested): ?>
                                <article class="guru-panel siswa-quiz-result-card">
                                    <div class="siswa-quiz-result-head">
                                        <div>
                                            <span class="siswa-quiz-eyebrow">Hasil quiz</span>
                                            <h2><?= chemnama_e((string) $savedQuizResultView['quiz_set_title']); ?></h2>
                                            <p><?= chemnama_e((string) $savedQuizResultView['module_badge']); ?> · <?= chemnama_e((string) $savedQuizResultView['module_title']); ?></p>
                                        </div>
                                        <div class="siswa-quiz-score-badge"><?= chemnama_e((string) $savedQuizResultView['earned_points']); ?>p</div>
                                    </div>

                                    <div class="siswa-quiz-result-grid">
                                        <div class="siswa-quiz-result-stat is-total">
                                            <strong>
                                                <?= chemnama_e((string) $savedQuizResultView['earned_points']); ?>
                                                <?php if ((int) ($savedQuizResultView['max_points'] ?? 0) > 0): ?>
                                                    /<?= chemnama_e((string) $savedQuizResultView['max_points']); ?>
                                                <?php endif; ?>
                                            </strong>
                                            <span>Poin</span>
                                        </div>
                                        <div class="siswa-quiz-result-stat is-correct">
                                            <strong><?= chemnama_e((string) $savedQuizResultView['correct_count']); ?></strong>
                                            <span>Jawaban benar</span>
                                        </div>
                                        <div class="siswa-quiz-result-stat is-wrong">
                                            <strong><?= chemnama_e((string) $savedQuizResultView['wrong_count']); ?></strong>
                                            <span>Jawaban salah</span>
                                        </div>
                                        <div class="siswa-quiz-result-stat is-total">
                                            <strong><?= chemnama_e((string) $savedQuizResultView['question_count']); ?></strong>
                                            <span>Total soal</span>
                                        </div>
                                    </div>
                                    <p class="siswa-quiz-result-note">Nilai tersimpan: <?= chemnama_e((string) $savedQuizResultView['earned_points']); ?> poin (<?= chemnama_e((string) $savedQuizResultView['score']); ?>%).</p>

                                    <div class="siswa-quiz-action-row">
                                        <a class="btn btn-primary" href="?id=<?= (int) $moduleId; ?>&step=5&restart=1">Ulangi Quiz</a>

                                    </div>
                                </article>
                            <?php elseif (!empty($quizSets) && !empty($inlineQuizQuestions)): ?>
                                <div class="quiz-inline-container" data-quiz-time="<?= (int) ($quizSets[0]['question_time_limit_seconds'] ?? 0); ?>" style="position:relative; overflow:hidden;">
                                    <?php /* Inline quiz navigator: all questions provided to JS */ ?>
                                    <div class="quiz-inline-card is-question" data-quiz-question id="quizQuestionCard">
                                        <span class="quiz-inline-label" data-quiz-counter>Soal 1/<?= (int) ($quizSets[0]['total_questions'] ?? 1); ?></span>
                                        <p data-quiz-text></p>
                                        <div class="quiz-question-footer">
                                            <div id="quizTimer" class="quiz-question-timer">
                                                <span class="quiz-question-timer-label">WAKTU PER SOAL</span>
                                                <span id="quizTimerValue" class="quiz-question-timer-value"></span>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="quiz-inline-options" data-quiz-options></div>

                                    <div id="quizControls" style="display:flex; gap:12px; align-items:center; margin-top:14px;">
                                        <button type="button" class="btn btn-secondary" id="quizPrev">← Kembali</button>
                                        <div style="flex:1"></div>
                                        <div style="display:flex; gap:12px; align-items:center;">
                                            <button type="button" class="btn btn-primary" id="quizNext">Selanjutnya →</button>
                                        </div>
                                    </div>

                                    <div id="quizFinalSummary" class="quiz-final-summary" aria-live="polite"></div>

                                    <!-- feedback overlay removed (badge/emoji disabled) -->

                                    <script>
                                    (function(){
                                        const questions = <?= json_encode($inlineQuizQuestions, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
                                        const quizSetId = <?= (int) ($quizSets[0]['id'] ?? 0); ?>;
                                        const moduleId = <?= (int) $moduleId; ?>;
                                        const quizTitle = <?= json_encode((string) ($quizSets[0]['title'] ?? 'Quiz')); ?>;
                                        const moduleBadge = <?= json_encode((string) ($quizSets[0]['module_badge'] ?? '')); ?>;
                                        const timeLimit = <?= (int) ($quizSets[0]['question_time_limit_seconds'] ?? 0); ?>;
                                        const total = questions.length || 1;
                                        let idx = 0;
                                        let timerId = null;
                                        let countdownId = null;
                                        let locked = false;
                                        let answerMap = {};
                                        let hasSubmitted = false;

                                        const container = document.querySelector('[data-quiz-question]');
                                        const textEl = document.querySelector('[data-quiz-text]');
                                        const counterEl = document.querySelector('[data-quiz-counter]');
                                        const optionsEl = document.querySelector('[data-quiz-options]');
                                        const controlsEl = document.getElementById('quizControls');
                                        const prevBtn = document.getElementById('quizPrev');
                                        const nextBtn = document.getElementById('quizNext');
                                        const timerLabel = document.getElementById('quizTimer');
                                        const timerValue = document.getElementById('quizTimerValue');
                                        const finalSummaryEl = document.getElementById('quizFinalSummary');
                                        const quickQuizContainer = document.querySelector('.quick-quiz-container');
                                        const feedbackDelayMs = 2200; // delay before moving to next question
                                        const maxPoints = questions.reduce(function(sum, item){ return sum + (parseInt(item.points, 10) || 0); }, 0);
                                        let selectedOption = null;
                                        let checkedCurrent = false;

                                        if (!container || !optionsEl || !prevBtn || !nextBtn) return;

                                        function calcEarnedPoints(){
                                            let earned = 0;
                                            Object.keys(answerMap).forEach(function(key){
                                                const row = answerMap[key];
                                                earned += (row && row.isCorrect) ? (row.points || 0) : 0;
                                            });
                                            return earned;
                                        }

                                        function calcCorrectCount(){
                                            let correct = 0;
                                            Object.keys(answerMap).forEach(function(key){
                                                if (answerMap[key] && answerMap[key].isCorrect) {
                                                    correct += 1;
                                                }
                                            });
                                            return correct;
                                        }

                                        function setQuizStageVisibility(isFinal){
                                            if (container) {
                                                container.style.display = isFinal ? 'none' : '';
                                            }
                                            if (optionsEl) {
                                                optionsEl.style.display = isFinal ? 'none' : '';
                                            }
                                            if (controlsEl) {
                                                controlsEl.style.display = isFinal ? 'none' : 'flex';
                                            }
                                        }

                                        function showFinalSummary(){
                                            const earnedPoints = calcEarnedPoints();
                                            const correctCount = calcCorrectCount();
                                            const wrongCount = Math.max(0, total - correctCount);
                                            const isPerfect = earnedPoints > 0 && earnedPoints === maxPoints;
                                            const scorePercent = total > 0 ? Math.round((correctCount / total) * 100) : 0;
                                            setQuizStageVisibility(true);
                                            if (finalSummaryEl) {
                                                finalSummaryEl.classList.add('is-visible');
                                                finalSummaryEl.innerHTML = ''
                                                    + '<article class="guru-panel siswa-quiz-result-card">'
                                                    + '<div class="siswa-quiz-result-head">'
                                                    + '<div>'
                                                    + '<span class="siswa-quiz-eyebrow">Hasil quiz</span>'
                                                    + '<h2>' + quizTitle + '</h2>'
                                                    + '<p>' + moduleBadge + '</p>'
                                                    + '</div>'
                                                    + '<div class="siswa-quiz-score-badge">' + earnedPoints + 'p</div>'
                                                    + '</div>'
                                                    + '<div class="siswa-quiz-result-grid">'
                                                    + '<div class="siswa-quiz-result-stat is-total"><strong>' + earnedPoints + '</strong><span>Poin</span></div>'
                                                    + '<div class="siswa-quiz-result-stat is-correct"><strong>' + correctCount + '</strong><span>Jawaban benar</span></div>'
                                                    + '<div class="siswa-quiz-result-stat is-wrong"><strong>' + wrongCount + '</strong><span>Jawaban salah</span></div>'
                                                    + '<div class="siswa-quiz-result-stat is-total"><strong>' + total + '</strong><span>Total soal</span></div>'
                                                    + '</div>'
                                                    + '<p class="siswa-quiz-result-note">Nilai tersimpan: ' + earnedPoints + ' poin (' + scorePercent + '%).</p>'
                                                    + '<div class="siswa-quiz-action-row">'
                                                    + '<a class="btn btn-primary" href="?id=' + moduleId + '&step=5&restart=1">Ulangi Quiz</a>'
                                                    + '<a class="btn btn-ghost" href="?id=' + moduleId + '&step=5">Tutup</a>'
                                                    + '</div>'
                                                    + '</article>';
                                            }
                                            prevBtn.disabled = true;
                                            nextBtn.disabled = true;
                                            if (timerValue) {
                                                timerValue.textContent = '00:00';
                                            }
                                            submitInlineResult();
                                        }

                                        function buildAnswerPayload(){
                                            const payload = {};
                                            Object.keys(answerMap).forEach(function(key){
                                                const row = answerMap[key];
                                                if (!row) {
                                                    return;
                                                }
                                                payload[key] = row.selected || null;
                                            });
                                            return payload;
                                        }

                                        function submitInlineResult(){
                                            if (hasSubmitted || !quizSetId) {
                                                return;
                                            }
                                            hasSubmitted = true;
                                            const statusEl = document.getElementById('quizSaveStatus');
                                            const payload = {
                                                quiz_set_id: quizSetId,
                                                answers: buildAnswerPayload(),
                                            };

                                            fetch('submit_quiz_pg_inline.php', {
                                                    method: 'POST',
                                                    headers: { 'Content-Type': 'application/json' },
                                                    body: JSON.stringify(payload),
                                                })
                                                    .then(function(response){
                                                        return response.json();
                                                    })
                                                    .then(function(data){
                                                        if (!statusEl) {
                                                            return;
                                                        }
                                                        if (data && data.ok) {
                                                            statusEl.textContent = 'Nilai tersimpan: ' + (data.earned_points || 0) + ' poin (' + (data.score || 0) + '%)';
                                                            // UI already shows the locked result card; no extra DOM append here.
                                                        } else {
                                                            statusEl.textContent = 'Nilai gagal disimpan.';
                                                        }
                                                    })
                                                    .catch(function(){
                                                        if (statusEl) {
                                                            statusEl.textContent = 'Nilai gagal disimpan.';
                                                        }
                                                    });
                                        }

                                        function renderQuestion(i){
                                            const q = questions[i];
                                            const qid = String(q.id || i);
                                            selectedOption = null;
                                            checkedCurrent = false;
                                            locked = false;
                                            setQuizStageVisibility(false);
                                            counterEl.textContent = 'Soal ' + (i+1) + '/' + total;
                                            textEl.textContent = q.question_text || '';
                                            if (finalSummaryEl) {
                                                finalSummaryEl.classList.remove('is-visible');
                                                finalSummaryEl.textContent = '';
                                            }
                                            // build options
                                            optionsEl.innerHTML = '';
                                            ['a','b','c','d'].forEach(function(key){
                                                const opt = q['option_' + key] || '';
                                                const label = document.createElement('label');
                                                label.className = 'quiz-inline-card is-option';
                                                label.innerHTML = '<input type="radio" name="quiz_inline_option" value="' + key + '">'
                                                    + '<span class="quiz-inline-letter">' + key.toUpperCase() + '</span>'
                                                    + '<span class="quiz-inline-text">' + opt + '</span>';
                                                optionsEl.appendChild(label);
                                            });

                                            // attach click handlers for selection first, check on button click
                                            const labels = Array.from(optionsEl.querySelectorAll('label'));
                                            labels.forEach(function(label){
                                                const input = label.querySelector('input');
                                                input.disabled = false;
                                                label.classList.remove('is-correct', 'is-incorrect');
                                                input.addEventListener('click', function onSelect(e){
                                                    if (locked || checkedCurrent) return;
                                                    selectedOption = input.value;
                                                    labels.forEach(function(l){
                                                        l.style.borderColor = '';
                                                        l.style.background = '';
                                                        l.style.color = '';
                                                    });
                                                    label.style.borderColor = '#8b5cf6';
                                                    label.style.background = 'rgba(139,92,246,0.12)';
                                                    label.style.color = '#ddd6fe';
                                                    nextBtn.textContent = 'Cek & Lanjut';
                                                });
                                            });

                                            // buttons
                                            prevBtn.disabled = (i === 0) || (timeLimit > 0);
                                            nextBtn.disabled = false;
                                            nextBtn.textContent = 'Pilih Jawaban Dulu';

                                            // timer
                                            clearTimers();
                                            if (timeLimit > 0) {
                                                startCountdown(timeLimit);
                                            } else {
                                                if (timerValue) {
                                                    timerValue.textContent = '00:00';
                                                }
                                            }
                                        }

                                        function clearTimers(){
                                            if (timerId) { clearTimeout(timerId); timerId = null; }
                                            if (countdownId) { clearInterval(countdownId); countdownId = null; }
                                        }

                                        function renderQuickQuizLockedView(payload){
                                            if (!quickQuizContainer) {
                                                return;
                                            }

                                            const selectedOption = String(payload.selectedOption || '');
                                            const correctOption = String(payload.correctOption || '');
                                            const selectedText = String(payload.selectedText || '');
                                            const correctText = String(payload.correctText || '');
                                            const isCorrect = !!payload.isCorrect;
                                            const scoreValue = parseInt(payload.scoreValue, 10) || 0;
                                            const selectedLabel = String(payload.selectedLabel || '').toUpperCase();
                                            const correctLabel = String(payload.correctLabel || '').toUpperCase();

                                            quickQuizContainer.innerHTML = ''
                                                + '<div class="quick-quiz-question">'
                                                + (payload.imageUrl ? '<img src="' + payload.imageUrl + '" alt="Quiz image" class="quick-quiz-image">' : '')
                                                + '<div class="quick-quiz-text">' + payload.questionText + '</div>'
                                                + '</div>'
                                                + '<div class="siswa-quiz-result-grid" style="margin-top: 16px; margin-bottom: 16px;">'
                                                + '<div class="siswa-quiz-result-stat is-total"><strong>' + scoreValue + '</strong><span>Poin</span></div>'
                                                + '<div class="siswa-quiz-result-stat is-correct"><strong>' + (isCorrect ? '1' : '0') + '</strong><span>Jawaban benar</span></div>'
                                                + '<div class="siswa-quiz-result-stat is-wrong"><strong>' + (isCorrect ? '0' : '1') + '</strong><span>Jawaban salah</span></div>'
                                                + '<div class="siswa-quiz-result-stat is-total"><strong>1</strong><span>Total soal</span></div>'
                                                + '</div>'
                                                + '<p class="siswa-quiz-result-note">Nilai tersimpan: ' + scoreValue + ' poin (' + scoreValue + '%).</p>'
                                                + '<div class="quick-quiz-options">'
                                                + ['a','b','c','d'].map(function(optionKey){
                                                    const optionInfo = payload.options && payload.options[optionKey] ? payload.options[optionKey] : '';
                                                    const isSelected = selectedOption === optionKey;
                                                    const isCorrectOption = correctOption === optionKey;
                                                    const classes = ['quiz-option-btn'];
                                                    if (isCorrectOption) { classes.push('is-correct'); }
                                                    if (isSelected && !isCorrectOption) { classes.push('is-incorrect'); }
                                                    if (isSelected) { classes.push('is-selected'); }
                                                    return '<button type="button" class="' + classes.join(' ') + '" disabled>'
                                                        + '<span class="quiz-option-label">' + optionKey.toUpperCase() + '</span>'
                                                        + '<span>' + optionInfo + '</span>'
                                                        + '</button>';
                                                }).join('')
                                                + '</div>'
                                                + '<div class="siswa-quiz-action-row">'
                                                + '<a class="btn btn-primary" href="?id=' + <?= (int) $moduleId; ?> + '&step=2&restart=1">Ulangi Quick Quiz</a>'
                                                + '<a class="btn btn-ghost" href="?id=' + <?= (int) $moduleId; ?> + '&step=2">Tutup</a>'
                                                + '</div>';
                                        }

                                        function startCountdown(seconds){
                                            let remaining = seconds;
                                            if (timerValue) {
                                                timerValue.textContent = formatTime(remaining);
                                            }
                                            countdownId = setInterval(function(){
                                                remaining -= 1;
                                                if (remaining <= 0) {
                                                    clearTimers();
                                                    if (timerValue) {
                                                        timerValue.textContent = '00:00';
                                                    }
                                                    // auto-advance
                                                    if (idx < total - 1) { idx++; renderQuestion(idx); }
                                                    else { showFinalSummary(); }
                                                    return;
                                                }
                                                if (timerValue) {
                                                    timerValue.textContent = formatTime(remaining);
                                                }
                                            }, 1000);
                                            // safety advance if needed
                                            timerId = setTimeout(function(){
                                                clearTimers();
                                                if (idx < total - 1) { idx++; renderQuestion(idx); }
                                                else { showFinalSummary(); }
                                            }, seconds * 1000);
                                        }

                                        function formatTime(s){
                                            const totalSeconds = Math.max(0, Math.floor(Number(s) || 0));
                                            const mm = Math.floor(totalSeconds / 60);
                                            const ss = totalSeconds % 60;
                                            return String(mm).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
                                        }

                                        prevBtn.addEventListener('click', function(){
                                            if (timeLimit > 0) return;
                                            if (idx > 0) { idx--; renderQuestion(idx); }
                                        });

                                        nextBtn.addEventListener('click', function(){
                                            if (checkedCurrent || locked) return;
                                            if (!selectedOption) {
                                                nextBtn.textContent = 'Pilih Jawaban Dulu';
                                                return;
                                            }

                                            const q = questions[idx];
                                            const qid = String(q.id || idx);
                                            const correct = String(q.correct_option || '').toLowerCase();
                                            const pointValue = parseInt(q.points, 10) || 0;
                                            const selected = selectedOption;
                                            const labels = Array.from(optionsEl.querySelectorAll('label'));

                                            locked = true;
                                            checkedCurrent = true;
                                            clearTimers();

                                            const selectedLabel = labels.find(l => (l.querySelector('input')||{}).value === selected);
                                            if (selectedLabel) {
                                                if (selected === correct) {
                                                    selectedLabel.classList.add('is-correct');
                                                    selectedLabel.style.borderColor = '#10b981';
                                                    selectedLabel.style.background = 'rgba(16,185,129,0.08)';
                                                    selectedLabel.style.color = '#86efac';
                                                } else {
                                                    selectedLabel.classList.add('is-incorrect');
                                                    selectedLabel.style.borderColor = '#ef4444';
                                                    selectedLabel.style.background = 'rgba(239,68,68,0.08)';
                                                    selectedLabel.style.color = '#fca5a5';
                                                }
                                            }

                                            if (selected !== correct) {
                                                const correctLabel = labels.find(l => (l.querySelector('input')||{}).value === correct);
                                                if (correctLabel) {
                                                    correctLabel.classList.add('is-correct');
                                                    correctLabel.style.borderColor = '#10b981';
                                                    correctLabel.style.background = 'rgba(16,185,129,0.08)';
                                                    correctLabel.style.color = '#86efac';
                                                }
                                            }

                                            labels.forEach(function(l){ const inp = l.querySelector('input'); if (inp) inp.disabled = true; });

                                            answerMap[qid] = {
                                                isCorrect: selected === correct,
                                                points: pointValue,
                                                selected: selected
                                            };

                                            renderQuickQuizLockedView({
                                                imageUrl: <?= json_encode((string) ($quickQuiz['image_url'] ?? '')); ?>,
                                                questionText: q.question_text || '',
                                                scoreValue: selected === correct ? 100 : 0,
                                                isCorrect: selected === correct,
                                                selectedOption: selected,
                                                correctOption: correct,
                                                selectedLabel: selected,
                                                correctLabel: correct,
                                                selectedText: q['option_' + selected] || '',
                                                correctText: q['option_' + correct] || '',
                                                options: {
                                                    a: q.option_a || '',
                                                    b: q.option_b || '',
                                                    c: q.option_c || '',
                                                    d: q.option_d || ''
                                                }
                                            });

                                            prevBtn.disabled = true;
                                            nextBtn.disabled = true;
                                            nextBtn.textContent = 'Lanjut otomatis...';

                                            setTimeout(function(){
                                                if (idx < total - 1) { idx++; renderQuestion(idx); }
                                                else { showFinalSummary(); }
                                            }, feedbackDelayMs);

                                            fetch('submit_quiz_pg_inline.php', {
                                                method: 'POST',
                                                headers: { 'Content-Type': 'application/json' },
                                                body: JSON.stringify({
                                                    quiz_set_id: quizSetId,
                                                    answers: buildAnswerPayload()
                                                })
                                            }).catch(function(){
                                                // keep the visual state even if save fails; server retry can happen on refresh
                                            });
                                        });

                                        // initial render
                                        renderQuestion(idx);
                                    })();
                                    </script>

                                </div>
                            <?php elseif (!empty($quizSets)): ?>
                                <div style="padding:40px; text-align:center; background:rgba(255,255,255,0.03); border-radius:12px; color:#94a3b8;">
                                    <p>Soal kuis belum tersedia.</p>
                                </div>
                            <?php else: ?>
                                <div style="padding:40px; text-align:center; background:rgba(255,255,255,0.03); border-radius:12px; color:#94a3b8;">
                                    <p>Kuis pilihan ganda belum tersedia untuk modul ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- STEP 6: GAMES -->
                        <div class="step-panel <?= $currentStep == 6 ? 'is-active' : ''; ?>" data-step-panel="6">
                            <div class="step-content-header">
                                <h3><?= chemnama_icon('star', '#f59e0b'); ?> Games Pembelajaran</h3>
                                <span class="step-content-chip"><?= min(1, count($tarigaris)) + min(1, count($carikataGames)); ?> Games</span>
                            </div>

                            <?php if (!empty($tarigaris) || !empty($carikataGames)): ?>
                                <div class="games-showcase-grid <?= $activeGameEmbedUrl !== '' ? 'is-hidden' : ''; ?>">
                                    <?php foreach (array_slice($tarigaris, 0, 1) as $game): ?>
                                        <article class="game-showcase-card">
                                            <span class="game-showcase-icon is-tarik">TG</span>
                                            <div>
                                                <h4 class="game-showcase-title">Cocokkan Nama Senyawa</h4>
                                                <p class="game-showcase-desc">Cocokkan rumus dengan nama senyawanya.</p>
                                            </div>
                                            <a href="?id=<?= $moduleId; ?>&step=6&play_game=tarigaris&game_id=<?= (int) $game['id']; ?>#games-inline-launcher" class="game-showcase-play is-tarik">▶ Mainkan</a>
                                        </article>
                                    <?php endforeach; ?>

                                    <?php foreach (array_slice($carikataGames, 0, 1) as $game): ?>
                                        <article class="game-showcase-card">
                                            <span class="game-showcase-icon is-carikata">CK</span>
                                            <div>
                                                <h4 class="game-showcase-title">Cari Nama Senyawa</h4>
                                                <p class="game-showcase-desc">Temukan kata tersembunyi.</p>
                                            </div>
                                            <a href="?id=<?= $moduleId; ?>&step=6&play_game=carikata&game_id=<?= (int) $game['id']; ?>#games-inline-launcher" class="game-showcase-play is-carikata">▶ Mainkan</a>
                                        </article>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($activeGameEmbedUrl !== ''): ?>
                                    <div class="games-inline-launcher" id="games-inline-launcher">
                                        <div class="games-inline-launcher-head">
                                            <h4>Game aktif</h4>
                                            <a href="?id=<?= $moduleId; ?>&step=6">Tutup game</a>
                                        </div>
                                        <iframe class="games-inline-frame" src="<?= chemnama_e($activeGameEmbedUrl); ?>" title="Game pembelajaran"></iframe>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div style="padding:40px; text-align:center; background:rgba(255,255,255,0.03); border-radius:12px; color:#94a3b8;">
                                    <p>Games belum tersedia untuk modul ini.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Navigation buttons -->
                    <div style="display: flex; gap: 12px; justify-content: space-between; margin-top: 20px;">
                        <?php if ($currentStep > 1): ?>
                            <a href="?id=<?= $moduleId; ?>&step=<?= $currentStep - 1; ?>" class="btn btn-secondary">
                                <?= chemnama_icon('back', '#64748b'); ?> Sebelumnya
                            </a>
                        <?php else: ?>
                            <div></div>
                        <?php endif; ?>

                        <?php if ($currentStep < 6): ?>
                            <a href="?id=<?= $moduleId; ?>&step=<?= $currentStep + 1; ?>" class="btn btn-primary">
                                Selanjutnya <?= chemnama_icon('next', '#ffffff'); ?>
                            </a>
                        <?php else: ?>
                            <button type="button" class="btn btn-primary" data-complete-module data-redirect="siswa_materi.php">
                                Selesai <?= chemnama_icon('check', '#ffffff'); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
        <?php endif; ?>

        <!-- Module completion notification -->
        <div class="module-completion-overlay" data-completion-overlay>
            <div class="module-completion-card">
                <div class="module-completion-icon">✓</div>
                <h3 class="module-completion-text">Selamat! Modul Selesai</h3>
                <p class="module-completion-subtext">Anda telah menyelesaikan semua langkah pembelajaran. Teruskan dengan modul berikutnya!</p>
                <div class="module-completion-progress">
                    <div class="module-completion-progress-bar"></div>
                </div>
            </div>
        </div>
    </section>
</main>
<script src="assets/js/app.js"></script>
<script>
    // Handle quick quiz option selection
    document.querySelectorAll('[data-option]').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.disabled) return;

            const option = btn.getAttribute('data-option');
            const form = document.querySelector('[data-quick-quiz-form]');
            const hiddenInput = form.querySelector('#quizAnswerInput');
            hiddenInput.value = option;

            // Show selection feedback
            document.querySelectorAll('[data-option]').forEach(b => b.style.opacity = '0.4');
            btn.style.opacity = '1';
            btn.disabled = true;

            // Show notification
            const notification = document.querySelector('[data-quiz-notification]');
            if (notification) {
                notification.classList.add('is-active');
                
                // Submit form after notification animation completes
                setTimeout(() => {
                    form.submit();
                }, 2600);
            } else {
                // Fallback if notification not found
                setTimeout(() => {
                    form.submit();
                }, 300);
            }
        });
    });

    // Mobile scroll adjustment for step nav
    const stepsNav = document.querySelector('[data-steps-nav]');
    const activeStepBtn = stepsNav.querySelector('.materi-step-btn.is-active');
    if (activeStepBtn) {
        activeStepBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
    }

    // Module completion handler
    const completeBtn = document.querySelector('[data-complete-module]');
    const completionOverlay = document.querySelector('[data-completion-overlay]');
    
    if (completeBtn && completionOverlay) {
        completeBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const redirectUrl = completeBtn.getAttribute('data-redirect');
            
            // Show completion notification
            completionOverlay.classList.add('is-active');
            
            // Redirect after 2.2 seconds (after progress bar animation)
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 2200);
        });
    }
</script>
</body>
</html>

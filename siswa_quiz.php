<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth();

$user = chemnama_current_user();
if (($user['role'] ?? 'siswa') !== 'siswa') {
    header('Location: dashboard.php');
    exit;
}

// Prevent browser cache from restoring stale quiz pages when navigating back/refresh.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$userId = (int) $user['id'];
$isDemoUser = chemnama_is_demo_user($user);
$studentClassStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$studentClassStmt->execute(['user_id' => $userId]);
$studentClass = (string) ($studentClassStmt->fetchColumn() ?: 'X IPA 1');

$quizSetsStmt = $pdo->prepare(
    'SELECT qs.id, qs.module_id, qs.title, qs.description, qs.question_time_limit_seconds, qs.is_published, qs.published_at,
                        m.badge AS module_badge, m.title AS module_title,
                        COUNT(q.id) AS total_questions
         FROM quiz_sets qs
         JOIN modules m ON m.id = qs.module_id
         JOIN users u ON u.id = qs.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         LEFT JOIN questions q ON q.quiz_set_id = qs.id AND q.is_published = 1
         WHERE qs.is_published = 1
             AND u.role = "guru"
             AND INSTR(
                 CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
                 CONCAT(CHAR(44), :kelas, CHAR(44))
             ) > 0
         GROUP BY qs.id, qs.module_id, qs.title, qs.description, qs.is_published, qs.published_at, m.badge, m.title, m.sort_order, qs.sort_order
         HAVING total_questions > 0
         ORDER BY m.sort_order, qs.sort_order, qs.id'
);
$quizSetsStmt->execute(['kelas' => $studentClass]);
$quizSets = $quizSetsStmt->fetchAll();
$quizSetMap = [];
foreach ($quizSets as $quizSet) {
    $quizSetMap[(int) $quizSet['id']] = $quizSet;
}

$quizAttemptStatsStmt = $pdo->prepare(
    'SELECT qar.quiz_set_id,
            COUNT(*) AS attempt_count,
            MAX(qar.score) AS best_score,
            MAX(qar.total_points) AS best_points,
                        MAX(qar.submitted_at) AS last_attempt_at,
                        (
                                SELECT qar2.score
                                FROM quiz_attempt_runs qar2
                                WHERE qar2.student_id = :student_id_last_1
                                    AND qar2.quiz_set_id = qar.quiz_set_id
                                    AND qar2.submitted_at IS NOT NULL
                                ORDER BY qar2.submitted_at DESC, qar2.id DESC
                                LIMIT 1
                        ) AS last_score,
                        (
                                SELECT qar3.correct_count
                                FROM quiz_attempt_runs qar3
                                WHERE qar3.student_id = :student_id_last_2
                                    AND qar3.quiz_set_id = qar.quiz_set_id
                                    AND qar3.submitted_at IS NOT NULL
                                ORDER BY qar3.submitted_at DESC, qar3.id DESC
                                LIMIT 1
                        ) AS last_correct_count,
                        (
                                SELECT qar5.total_points
                                FROM quiz_attempt_runs qar5
                                WHERE qar5.student_id = :student_id_last_4
                                    AND qar5.quiz_set_id = qar.quiz_set_id
                                    AND qar5.submitted_at IS NOT NULL
                                ORDER BY qar5.submitted_at DESC, qar5.id DESC
                                LIMIT 1
                        ) AS last_points,
                        (
                                SELECT qar4.total_questions
                                FROM quiz_attempt_runs qar4
                                WHERE qar4.student_id = :student_id_last_3
                                    AND qar4.quiz_set_id = qar.quiz_set_id
                                    AND qar4.submitted_at IS NOT NULL
                                ORDER BY qar4.submitted_at DESC, qar4.id DESC
                                LIMIT 1
                        ) AS last_total_questions
     FROM quiz_attempt_runs qar
     JOIN quiz_sets qs ON qs.id = qar.quiz_set_id
     WHERE qar.student_id = :student_id AND qs.is_published = 1
     GROUP BY qar.quiz_set_id'
);
$quizAttemptStatsStmt->execute([
        'student_id' => $userId,
        'student_id_last_1' => $userId,
        'student_id_last_2' => $userId,
        'student_id_last_3' => $userId,
        'student_id_last_4' => $userId,
]);
$quizAttemptRows = $quizAttemptStatsStmt->fetchAll();
$quizAttemptMap = [];
foreach ($quizAttemptRows as $row) {
    $quizAttemptMap[(int) $row['quiz_set_id']] = [
        'attempt_count' => (int) $row['attempt_count'],
        'best_score' => (int) $row['best_score'],
        'best_points' => (int) ($row['best_points'] ?? 0),
        'last_attempt_at' => $row['last_attempt_at'],
        'last_score' => isset($row['last_score']) ? (int) $row['last_score'] : 0,
        'last_points' => isset($row['last_points']) ? (int) $row['last_points'] : 0,
        'last_correct_count' => isset($row['last_correct_count']) ? (int) $row['last_correct_count'] : 0,
        'last_total_questions' => isset($row['last_total_questions']) ? (int) $row['last_total_questions'] : 0,
    ];
}

$repeatRequestStmt = $pdo->prepare(
    'SELECT qr.*
     FROM quiz_repeat_requests qr
     JOIN quiz_sets qs ON qs.id = qr.quiz_set_id
     WHERE qr.student_id = :student_id AND qs.is_published = 1
     ORDER BY qr.requested_at DESC, qr.id DESC'
);
$repeatRequestStmt->execute(['student_id' => $userId]);
$repeatRequestRows = $repeatRequestStmt->fetchAll();
$repeatRequestMap = [];
foreach ($repeatRequestRows as $row) {
    $quizSetId = (int) $row['quiz_set_id'];
    if (!isset($repeatRequestMap[$quizSetId])) {
        $repeatRequestMap[$quizSetId] = $row;
    }
}

$selectedQuizSetId = (int) ($_GET['quiz_set_id'] ?? $_POST['quiz_set_id'] ?? 0);
$restartRequested = isset($_GET['restart']) && $_GET['restart'] === '1';
$completeRequested = isset($_GET['complete']) && $_GET['complete'] === '1';
$flashError = $_SESSION['quiz_flash_error'] ?? null;
unset($_SESSION['quiz_flash_error']);

$activeRunKey = 'student_quiz_active';
$resultKey = 'student_quiz_results';
$feedbackKey = 'student_quiz_feedback';
$quizTimeoutToken = '__timeout__';

if ($restartRequested && $selectedQuizSetId > 0) {
    $existingRun = $_SESSION[$activeRunKey][$selectedQuizSetId] ?? null;
    $hasActiveRun = is_array($existingRun) && !empty($existingRun['question_ids']) && ((int) ($existingRun['index'] ?? 0) < count((array) ($existingRun['question_ids'] ?? [])));
    if ($hasActiveRun) {
        $_SESSION['quiz_flash_error'] = 'Quiz sedang berjalan. Selesaikan dulu quiz ini sebelum mengulang.';
        header('Location: siswa_quiz.php?quiz_set_id=' . $selectedQuizSetId);
        exit;
    }

    unset($_SESSION[$activeRunKey][$selectedQuizSetId], $_SESSION[$resultKey][$selectedQuizSetId], $_SESSION[$feedbackKey][$selectedQuizSetId]);
}

$insertAttemptRun = $pdo->prepare(
    'INSERT INTO quiz_attempt_runs (quiz_set_id, student_id, attempt_number, score, correct_count, total_questions, total_points, started_at, submitted_at)
     VALUES (:quiz_set_id, :student_id, :attempt_number, NULL, 0, :total_questions, 0, :started_at, NULL)'
);
$updateAttemptRun = $pdo->prepare(
    'UPDATE quiz_attempt_runs
     SET score = :score, correct_count = :correct_count, total_questions = :total_questions, total_points = :total_points, submitted_at = :submitted_at
     WHERE id = :id AND student_id = :student_id'
);
$insertAnswer = $pdo->prepare(
    'INSERT INTO quiz_attempt_answers (attempt_run_id, question_id, selected_option, is_correct, answered_at)
     VALUES (:attempt_run_id, :question_id, :selected_option, :is_correct, :answered_at)
     ON DUPLICATE KEY UPDATE selected_option = VALUES(selected_option), is_correct = VALUES(is_correct), answered_at = VALUES(answered_at)'
);
$insertRepeatRequest = $pdo->prepare(
    'INSERT INTO quiz_repeat_requests (quiz_set_id, student_id, reason, status, requested_at)
     VALUES (:quiz_set_id, :student_id, :reason, "pending", NOW())'
);
$updateRepeatRequest = $pdo->prepare(
    'UPDATE quiz_repeat_requests
     SET status = :status, decided_at = NOW(), decided_by = :decided_by
     WHERE id = :id AND student_id = :student_id'
);
$markRepeatUsed = $pdo->prepare(
    'UPDATE quiz_repeat_requests
     SET status = "used", used_at = NOW()
     WHERE id = :id AND student_id = :student_id'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'request_retry') {
        $selectedQuizSetId = (int) ($_POST['quiz_set_id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if (!isset($quizSetMap[$selectedQuizSetId])) {
            $_SESSION['quiz_flash_error'] = 'Quiz tidak ditemukan.';
            header('Location: siswa_quiz.php');
            exit;
        }

        $insertRepeatRequest->execute([
            'quiz_set_id' => $selectedQuizSetId,
            'student_id' => $userId,
            'reason' => $reason !== '' ? $reason : null,
        ]);

        $_SESSION['quiz_flash_error'] = 'Permintaan ulang sudah dikirim ke guru.';
        header('Location: siswa_quiz.php?quiz_set_id=' . $selectedQuizSetId);
        exit;
    }

    if ($action === 'answer') {
        $selectedQuizSetId = (int) ($_POST['quiz_set_id'] ?? 0);
        $postedQuestionId = (int) ($_POST['current_question_id'] ?? 0);
        $selectedOptionRaw = strtolower(trim((string) ($_POST['selected_option'] ?? '')));
        $isTimeoutSubmit = $selectedOptionRaw === $quizTimeoutToken;
        $selectedOption = $isTimeoutSubmit ? null : $selectedOptionRaw;

        if (!isset($quizSetMap[$selectedQuizSetId])) {
            $_SESSION['quiz_flash_error'] = 'Quiz tidak ditemukan.';
            header('Location: siswa_quiz.php');
            exit;
        }

        if (!$isTimeoutSubmit && !in_array($selectedOption, ['a', 'b', 'c', 'd'], true)) {
            $_SESSION['quiz_flash_error'] = 'Pilih jawaban terlebih dahulu.';
            header('Location: siswa_quiz.php?quiz_set_id=' . $selectedQuizSetId);
            exit;
        }

        $selectedQuizSet = $quizSetMap[$selectedQuizSetId];
        $questionsStmt = $pdo->prepare(
            'SELECT id, question_text, option_a, option_b, option_c, option_d, correct_option, difficulty, points
             FROM questions
             WHERE quiz_set_id = :quiz_set_id AND is_published = 1
             ORDER BY id ASC'
        );
        $questionsStmt->execute(['quiz_set_id' => $selectedQuizSetId]);
        $questions = $questionsStmt->fetchAll();
        if (empty($questions)) {
            $_SESSION['quiz_flash_error'] = 'Belum ada soal pada quiz ini.';
            header('Location: siswa_quiz.php');
            exit;
        }

        $run = $_SESSION[$activeRunKey][$selectedQuizSetId] ?? null;
        if (!$run || empty($run['question_ids'])) {
            $attemptNumberStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(attempt_number), 0) + 1
                 FROM quiz_attempt_runs
                 WHERE quiz_set_id = :quiz_set_id AND student_id = :student_id'
            );
            $attemptNumberStmt->execute([
                'quiz_set_id' => $selectedQuizSetId,
                'student_id' => $userId,
            ]);
            $attemptNumber = (int) $attemptNumberStmt->fetchColumn();

            $questionIds = array_map(static fn (array $question): int => (int) $question['id'], $questions);
            $insertAttemptRun->execute([
                'quiz_set_id' => $selectedQuizSetId,
                'student_id' => $userId,
                'attempt_number' => $attemptNumber,
                'total_questions' => count($questionIds),
                'started_at' => date('Y-m-d H:i:s'),
            ]);

            $runId = (int) $pdo->lastInsertId();
            $retakeRequest = $repeatRequestMap[$selectedQuizSetId] ?? null;
            $_SESSION[$activeRunKey][$selectedQuizSetId] = [
                'run_id' => $runId,
                'quiz_set_id' => $selectedQuizSetId,
                'question_ids' => $questionIds,
                'index' => 0,
                'answers' => [],
                'timeouts' => [],
                'retake_request_id' => $retakeRequest && $retakeRequest['status'] === 'approved' && empty($retakeRequest['used_at']) ? (int) $retakeRequest['id'] : null,
                'started_at' => date('Y-m-d H:i:s'),
                'question_started_at' => date('Y-m-d H:i:s'),
            ];
            $run = $_SESSION[$activeRunKey][$selectedQuizSetId];
        }

        $currentIndex = (int) ($run['index'] ?? 0);
        $questionIds = $run['question_ids'] ?? [];
        $currentQuestionId = $questionIds[$currentIndex] ?? null;
        if ($currentQuestionId === null) {
            unset($_SESSION[$activeRunKey][$selectedQuizSetId]);
            $_SESSION['quiz_flash_error'] = 'Sesi quiz tidak valid. Mulai ulang.';
            header('Location: siswa_quiz.php?quiz_set_id=' . $selectedQuizSetId . '&restart=1');
            exit;
        }

        if ($postedQuestionId !== $currentQuestionId) {
            $_SESSION['quiz_flash_error'] = 'Kamu sedang di soal terbaru. Soal sebelumnya tidak bisa dijawab ulang.';
            header('Location: siswa_quiz.php?quiz_set_id=' . $selectedQuizSetId);
            exit;
        }

        $questionMap = [];
        foreach ($questions as $question) {
            $questionMap[(int) $question['id']] = $question;
        }

        $questionTimeLimitSeconds = max(0, (int) ($selectedQuizSet['question_time_limit_seconds'] ?? 0));
        $questionStartedAtRaw = (string) ($run['question_started_at'] ?? '');
        $questionStartedAtTs = strtotime($questionStartedAtRaw);
        if ($questionStartedAtTs === false) {
            $questionStartedAtTs = time();
            $run['question_started_at'] = date('Y-m-d H:i:s', $questionStartedAtTs);
        }
        $elapsedSeconds = max(0, time() - $questionStartedAtTs);
        $isTimeoutByElapsed = $questionTimeLimitSeconds > 0 && $elapsedSeconds >= $questionTimeLimitSeconds;
        $isTimedOutAnswer = $isTimeoutSubmit || $isTimeoutByElapsed;

        if (!isset($run['answers'][$currentQuestionId])) {
            $question = $questionMap[$currentQuestionId];
            $isCorrect = (!$isTimedOutAnswer && $selectedOption === $question['correct_option']) ? 1 : 0;
            $insertAnswer->execute([
                'attempt_run_id' => (int) $run['run_id'],
                'question_id' => $currentQuestionId,
                'selected_option' => $isTimedOutAnswer ? null : $selectedOption,
                'is_correct' => $isCorrect,
                'answered_at' => date('Y-m-d H:i:s'),
            ]);
            $run['answers'][$currentQuestionId] = $isTimedOutAnswer ? null : $selectedOption;
            $run['timeouts'][$currentQuestionId] = $isTimedOutAnswer ? 1 : 0;
            $run['index'] = $currentIndex + 1;
            $run['question_started_at'] = date('Y-m-d H:i:s');
            $_SESSION[$activeRunKey][$selectedQuizSetId] = $run;

            $_SESSION[$feedbackKey][$selectedQuizSetId] = [
                'question_number' => $currentIndex + 1,
                'question_text' => (string) $question['question_text'],
                'selected_option' => $isTimedOutAnswer ? '' : (string) $selectedOption,
                'correct_option' => (string) $question['correct_option'],
                'is_correct' => $isCorrect === 1,
                'is_timeout' => $isTimedOutAnswer,
                'earned_points' => $isCorrect === 1 ? (int) $question['points'] : 0,
                'max_points' => (int) $question['points'],
                'options' => [
                    'a' => (string) $question['option_a'],
                    'b' => (string) $question['option_b'],
                    'c' => (string) $question['option_c'],
                    'd' => (string) $question['option_d'],
                ],
                'answered_at' => date('Y-m-d H:i:s'),
            ];
        }

        $questionCount = count($questionIds);
        if ($run['index'] >= $questionCount) {
            $correctCount = 0;
            $totalPoints = 0;
            $earnedPoints = 0;
            $review = [];

            foreach ($questionIds as $questionId) {
                $question = $questionMap[$questionId] ?? null;
                if (!$question) {
                    continue;
                }

                $points = (int) $question['points'];
                $totalPoints += $points;
                $chosenOption = $run['answers'][$questionId] ?? null;
                $isTimedOut = (int) ($run['timeouts'][$questionId] ?? 0) === 1;
                $isCorrect = !$isTimedOut && $chosenOption === $question['correct_option'];
                if ($isCorrect) {
                    $correctCount += 1;
                    $earnedPoints += $points;
                }

                $review[] = [
                    'question' => $question,
                    'selected_option' => $chosenOption,
                    'is_timeout' => $isTimedOut,
                    'is_correct' => $isCorrect,
                ];
            }

            $score = $questionCount > 0 ? (int) round(($correctCount / $questionCount) * 100) : 0;
            $updateAttemptRun->execute([
                'id' => (int) $run['run_id'],
                'student_id' => $userId,
                'score' => $score,
                'correct_count' => $correctCount,
                'total_questions' => $questionCount,
                'total_points' => $earnedPoints,
                'submitted_at' => date('Y-m-d H:i:s'),
            ]);

            if (!empty($run['retake_request_id'])) {
                $markRepeatUsed->execute([
                    'id' => (int) $run['retake_request_id'],
                    'student_id' => $userId,
                ]);
            }

            $_SESSION[$resultKey][$selectedQuizSetId] = [
                'quiz_set_id' => $selectedQuizSetId,
                'quiz_set_title' => $selectedQuizSet['title'],
                'module_badge' => $selectedQuizSet['module_badge'],
                'module_title' => $selectedQuizSet['module_title'],
                'question_count' => $questionCount,
                'correct_count' => $correctCount,
                'total_points' => $totalPoints,
                'earned_points' => $earnedPoints,
                'score' => $score,
                'review' => $review,
                'submitted_at' => date('Y-m-d H:i:s'),
            ];

            unset($_SESSION[$activeRunKey][$selectedQuizSetId]);
            unset($_SESSION[$feedbackKey][$selectedQuizSetId]);
            header('Location: siswa_quiz.php?quiz_set_id=' . $selectedQuizSetId . '&complete=1');
            exit;
        }

        header('Location: siswa_quiz.php?quiz_set_id=' . $selectedQuizSetId);
        exit;
    }

}

$selectedQuizSet = $selectedQuizSetId > 0 ? ($quizSetMap[$selectedQuizSetId] ?? null) : null;
$latestRequest = $selectedQuizSetId > 0 ? ($repeatRequestMap[$selectedQuizSetId] ?? null) : null;
$canRetake = $latestRequest && $latestRequest['status'] === 'approved' && empty($latestRequest['used_at']);
$selectedAttemptStat = $selectedQuizSetId > 0 ? ($quizAttemptMap[$selectedQuizSetId] ?? null) : null;
$isQuizRestart = $restartRequested && $selectedQuizSetId > 0;

$requestRetryForId = (int) ($_GET['request_retry_for'] ?? 0);
$requestRetryQuizSet = $requestRetryForId > 0 ? ($quizSetMap[$requestRetryForId] ?? null) : null;
$requestRetryAttempt = $requestRetryForId > 0 ? ($quizAttemptMap[$requestRetryForId] ?? null) : null;
$requestRetryStatus = $requestRetryForId > 0 ? ($repeatRequestMap[$requestRetryForId]['status'] ?? null) : null;
$showRequestRetryModal = $requestRetryQuizSet !== null && $requestRetryAttempt !== null && $requestRetryStatus !== 'approved';

$questions = [];
$questionMap = [];
if ($selectedQuizSet) {
    $questionsStmt = $pdo->prepare(
        'SELECT id, question_text, option_a, option_b, option_c, option_d, correct_option, difficulty, points
         FROM questions
         WHERE quiz_set_id = :quiz_set_id AND is_published = 1
         ORDER BY id ASC'
    );
    $questionsStmt->execute(['quiz_set_id' => $selectedQuizSetId]);
    $questions = $questionsStmt->fetchAll();
    foreach ($questions as $question) {
        $questionMap[(int) $question['id']] = $question;
    }
}

if ($selectedQuizSet && !isset($_SESSION[$activeRunKey][$selectedQuizSetId]) && !empty($questions) && !$completeRequested) {
    $hasPreviousAttempt = isset($quizAttemptMap[$selectedQuizSetId]);
    if (!$hasPreviousAttempt || $canRetake) {
        $attemptNumberStmt = $pdo->prepare(
            'SELECT COALESCE(MAX(attempt_number), 0) + 1
             FROM quiz_attempt_runs
             WHERE quiz_set_id = :quiz_set_id AND student_id = :student_id'
        );
        $attemptNumberStmt->execute([
            'quiz_set_id' => $selectedQuizSetId,
            'student_id' => $userId,
        ]);
        $attemptNumber = (int) $attemptNumberStmt->fetchColumn();

        $questionIds = array_map(static fn (array $question): int => (int) $question['id'], $questions);
        $insertAttemptRun->execute([
            'quiz_set_id' => $selectedQuizSetId,
            'student_id' => $userId,
            'attempt_number' => $attemptNumber,
            'total_questions' => count($questionIds),
            'started_at' => date('Y-m-d H:i:s'),
        ]);
        $runId = (int) $pdo->lastInsertId();
        $approvedRequestId = $canRetake ? (int) $latestRequest['id'] : null;

        $_SESSION[$activeRunKey][$selectedQuizSetId] = [
            'run_id' => $runId,
            'quiz_set_id' => $selectedQuizSetId,
            'question_ids' => $questionIds,
            'index' => 0,
            'answers' => [],
            'timeouts' => [],
            'retake_request_id' => $approvedRequestId,
            'started_at' => date('Y-m-d H:i:s'),
            'question_started_at' => date('Y-m-d H:i:s'),
        ];
    }
}

$activeRun = $selectedQuizSetId > 0 ? ($_SESSION[$activeRunKey][$selectedQuizSetId] ?? null) : null;
$instantFeedback = $selectedQuizSetId > 0 ? ($_SESSION[$feedbackKey][$selectedQuizSetId] ?? null) : null;
if ($selectedQuizSetId > 0) {
    unset($_SESSION[$feedbackKey][$selectedQuizSetId]);
}
$currentQuestion = null;
$currentIndex = 0;
$selectedAnswer = '';
$questionTimeLimitSeconds = $selectedQuizSet ? max(0, (int) ($selectedQuizSet['question_time_limit_seconds'] ?? 0)) : 0;
$questionTimeRemainingSeconds = $questionTimeLimitSeconds;
if ($activeRun && !empty($activeRun['question_ids'])) {
    $questionIds = $activeRun['question_ids'];
    $currentIndex = min((int) ($activeRun['index'] ?? 0), max(0, count($questionIds) - 1));
    $currentQuestionId = $questionIds[$currentIndex] ?? null;
    if ($currentQuestionId !== null && isset($questionMap[$currentQuestionId])) {
        $currentQuestion = $questionMap[$currentQuestionId];
        $selectedAnswer = (string) ($activeRun['answers'][$currentQuestionId] ?? '');

        if ($questionTimeLimitSeconds > 0) {
            $questionStartedAtTs = strtotime((string) ($activeRun['question_started_at'] ?? ''));
            if ($questionStartedAtTs === false) {
                $questionStartedAtTs = time();
            }
            $elapsedSeconds = max(0, time() - $questionStartedAtTs);
            $questionTimeRemainingSeconds = max(0, $questionTimeLimitSeconds - $elapsedSeconds);
        }
    }
}

$formatQuizSeconds = static function (int $seconds): string {
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    $remainingSeconds = $seconds % 60;
    return sprintf('%02d:%02d', $minutes, $remainingSeconds);
};

$liveCorrectCount = 0;
$liveWrongCount = 0;
$liveAnsweredCount = 0;
$liveTotalCount = 0;
if ($activeRun && !empty($activeRun['question_ids'])) {
    $liveTotalCount = count($activeRun['question_ids']);
    $liveAnsweredCount = count((array) ($activeRun['answers'] ?? []));
    foreach ((array) ($activeRun['answers'] ?? []) as $answeredQuestionId => $answerOption) {
        $answeredQuestionId = (int) $answeredQuestionId;
        if (!isset($questionMap[$answeredQuestionId])) {
            continue;
        }

        $correctOption = (string) $questionMap[$answeredQuestionId]['correct_option'];
        if ((string) $answerOption === $correctOption) {
            $liveCorrectCount++;
        } else {
            $liveWrongCount++;
        }
    }
}

$quizResult = $selectedQuizSetId > 0 ? ($_SESSION[$resultKey][$selectedQuizSetId] ?? null) : null;
$quizResultReview = [];
$quizResultView = null;
if (!$isQuizRestart && is_array($quizResult)) {
    $quizResultView = [
        'quiz_set_title' => (string) ($quizResult['quiz_set_title'] ?? $quizResult['module_title'] ?? $selectedQuizSet['title'] ?? 'Quiz'),
        'module_badge' => (string) ($quizResult['module_badge'] ?? $selectedQuizSet['module_badge'] ?? '-'),
        'module_title' => (string) ($quizResult['module_title'] ?? $selectedQuizSet['module_title'] ?? '-'),
        'earned_points' => (int) ($quizResult['earned_points'] ?? 0),
        'max_points' => (int) ($quizResult['total_points'] ?? 0),
        'correct_count' => (int) ($quizResult['correct_count'] ?? 0),
        'question_count' => (int) ($quizResult['question_count'] ?? $quizResult['total_questions'] ?? 0),
        'wrong_count' => max(0, (int) ($quizResult['question_count'] ?? $quizResult['total_questions'] ?? 0) - (int) ($quizResult['correct_count'] ?? 0)),
    ];
    $quizResultReview = is_array($quizResult['review'] ?? null) ? $quizResult['review'] : [];
} elseif (!$isQuizRestart && $selectedQuizSet && $selectedAttemptStat) {
    $quizResultView = [
        'quiz_set_title' => (string) ($selectedQuizSet['title'] ?? 'Quiz'),
        'module_badge' => (string) ($selectedQuizSet['module_badge'] ?? '-'),
        'module_title' => (string) ($selectedQuizSet['module_title'] ?? '-'),
        'earned_points' => (int) ($selectedAttemptStat['last_points'] ?? 0),
        'max_points' => 0,
        'correct_count' => (int) ($selectedAttemptStat['last_correct_count'] ?? 0),
        'question_count' => (int) ($selectedAttemptStat['last_total_questions'] ?? 0),
        'wrong_count' => max(0, (int) ($selectedAttemptStat['last_total_questions'] ?? 0) - (int) ($selectedAttemptStat['last_correct_count'] ?? 0)),
    ];
}

$quizDisplayPoints = $quizResultView ? (int) ($quizResultView['earned_points'] ?? 0) : 0;

$quizRunnerWallpaper = 'none';
$quizRunnerWallpaperPath = APP_ROOT . '/assets/img/walpaper_5.jpg';
if (false && is_readable($quizRunnerWallpaperPath)) {
    $quizRunnerWallpaperBinary = file_get_contents($quizRunnerWallpaperPath);
    if ($quizRunnerWallpaperBinary !== false) {
        $quizRunnerWallpaper = 'data:image/jpeg;base64,' . base64_encode($quizRunnerWallpaperBinary);
    }
}

$studentMenus = [
   ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Materi', 'icon' => 'file', 'href' => 'siswa_materi.php', 'active' => false],
    // ['label' => 'Games', 'icon' => 'beaker', 'href' => 'siswa_games.php', 'active' => false],
    // ['label' => 'Kuis PG', 'icon' => 'stack', 'href' => 'siswa_quiz.php', 'active' => true],
    ['label' => 'Exercise', 'icon' => 'edit', 'href' => 'siswa_essay.php', 'active' => false],
    ['label' => 'Forum Diskusi', 'icon' => 'chat', 'href' => 'siswa_forum.php', 'active' => false],
    ['label' => 'Profil', 'icon' => 'user', 'href' => 'siswa_profil.php', 'active' => false],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kuis PG - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<main class="guru-page student-page siswa-quiz-page">
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
                <?php foreach ($studentMenus as $menu): ?>
                    <a class="guru-menu-item <?= $menu['label'] === 'Kuis PG' ? 'is-active' : ''; ?>" href="<?= chemnama_e($menu['href']); ?>">
                        <?= chemnama_icon($menu['icon'], $menu['label'] === 'Kuis PG' ? '#0f9d58' : '#6b7280'); ?>
                        <span><?= chemnama_e($menu['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">AKUN</span>
            <nav class="guru-menu-list">
                <a class="guru-menu-item logout" href="logout.php">
                    <?= chemnama_icon('logout', '#ef4444'); ?>
                    <span>Keluar</span>
                </a>
            </nav>
        </div>
    </aside>

    <section class="guru-content student-content siswa-quiz-content">
        <div class="guru-headline-row">
            <div>
                <h1>Kuis PG <span><?= chemnama_e($selectedQuizSet['module_badge'] ?? 'Latihan Cepat'); ?></span></h1>
                <p><?= chemnama_e($selectedQuizSet['title'] ?? 'Pilih quiz yang sudah dipublish guru.'); ?></p>
            </div>
        </div>

        <?php if ($flashError): ?>
            <div class="alert alert-danger"><?= chemnama_e($flashError); ?></div>
        <?php endif; ?>

        <?php if ($activeRun && $currentQuestion): ?>
            <article class="guru-panel siswa-quiz-runner-card" style="--quiz-wallpaper: url('<?= chemnama_e($quizRunnerWallpaper); ?>');">
                <div class="siswa-quiz-runner-head">
                    <div>
                        <span class="siswa-quiz-eyebrow"><?= chemnama_e((string) $selectedQuizSet['module_badge']); ?></span>
                        <h2><?= chemnama_e((string) $selectedQuizSet['title']); ?></h2>
                        <p><?= chemnama_e((string) ($selectedQuizSet['description'] ?: 'Jawab satu per satu lalu tekan Berikutnya.')); ?></p>
                    </div>
                    <div class="siswa-quiz-runner-meta">
                        <strong>Soal <?= $currentIndex + 1; ?>/<?= count($activeRun['question_ids']); ?></strong>
                        <small><?= chemnama_e((string) ($quizAttemptMap[$selectedQuizSetId]['attempt_count'] ?? 0)); ?> attempt</small>
                        <?php if ($questionTimeLimitSeconds > 0): ?>
                            <div class="siswa-quiz-timer" data-quiz-timer data-quiz-time-limit="<?= (int) $questionTimeLimitSeconds; ?>" data-quiz-time-remaining="<?= (int) $questionTimeRemainingSeconds; ?>">
                                <span>Waktu per soal</span>
                                <strong data-quiz-timer-label><?= chemnama_e($formatQuizSeconds($questionTimeRemainingSeconds)); ?></strong>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="siswa-quiz-progress">
                    <span style="width: <?= count($activeRun['question_ids']) > 0 ? (int) round((($currentIndex + 1) / count($activeRun['question_ids'])) * 100) : 0; ?>%;"></span>
                </div>

                <form method="post" class="siswa-quiz-form" data-correct-option="<?= chemnama_e((string) $currentQuestion['correct_option']); ?>" data-auto-next-delay="2200" data-quiz-timeout-token="<?= chemnama_e($quizTimeoutToken); ?>">
                    <input type="hidden" name="action" value="answer">
                    <input type="hidden" name="quiz_set_id" value="<?= (int) $selectedQuizSetId; ?>">
                    <input type="hidden" name="current_question_id" value="<?= (int) $currentQuestion['id']; ?>">

                    <div class="siswa-quiz-question-card">
                        <span class="siswa-quiz-question-label">Soal <?= $currentIndex + 1; ?></span>
                        <p><?= chemnama_e((string) $currentQuestion['question_text']); ?></p>
                    </div>

                    <div class="siswa-quiz-live-feedback" data-quiz-live-feedback aria-live="polite"></div>

                    <div class="siswa-quiz-options">
                        <?php foreach (['a' => $currentQuestion['option_a'], 'b' => $currentQuestion['option_b'], 'c' => $currentQuestion['option_c'], 'd' => $currentQuestion['option_d']] as $optionKey => $optionText): ?>
                            <label class="siswa-quiz-option <?= $selectedAnswer === $optionKey ? 'is-selected' : ''; ?>">
                                <input type="radio" name="selected_option" value="<?= chemnama_e($optionKey); ?>" <?= $selectedAnswer === $optionKey ? 'checked' : ''; ?> required>
                                <span class="siswa-quiz-option-letter"><?= strtoupper($optionKey); ?></span>
                                <span class="siswa-quiz-option-text"><?= chemnama_e((string) $optionText); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="siswa-quiz-action-row">
                        <div class="siswa-quiz-nav-actions">
                            <button class="btn btn-primary" type="submit" data-quiz-submit>
                                <?= ($currentIndex + 1) >= count($activeRun['question_ids']) ? 'Selesai Quiz' : 'Cek & Lanjut'; ?>
                            </button>
                        </div>
                    </div>
                </form>
            </article>
        <?php elseif ($quizResultView): ?>
            <article class="guru-panel siswa-quiz-result-card">
                <div class="siswa-quiz-result-head">
                    <div>
                        <span class="siswa-quiz-eyebrow">Hasil quiz</span>
                        <h2><?= chemnama_e((string) $quizResultView['quiz_set_title']); ?></h2>
                        <p><?= chemnama_e((string) $quizResultView['module_badge']); ?> · <?= chemnama_e((string) $quizResultView['module_title']); ?></p>
                    </div>
                    <div class="siswa-quiz-score-badge"><?= chemnama_e((string) $quizResultView['earned_points']); ?>p</div>
                </div>

                <div class="siswa-quiz-result-grid">
                    <div class="siswa-quiz-result-stat is-total">
                        <strong>
                            <?= chemnama_e((string) $quizResultView['earned_points']); ?>
                            <?php if ((int) ($quizResultView['max_points'] ?? 0) > 0): ?>
                                /<?= chemnama_e((string) $quizResultView['max_points']); ?>
                            <?php endif; ?>
                        </strong>
                        <span>Poin</span>
                    </div>
                    <div class="siswa-quiz-result-stat is-correct">
                        <strong><?= chemnama_e((string) $quizResultView['correct_count']); ?></strong>
                        <span>Jawaban benar</span>
                    </div>
                    <div class="siswa-quiz-result-stat is-wrong">
                        <strong><?= chemnama_e((string) $quizResultView['wrong_count']); ?></strong>
                        <span>Jawaban salah</span>
                    </div>
                    <div class="siswa-quiz-result-stat is-total">
                        <strong><?= chemnama_e((string) $quizResultView['question_count']); ?></strong>
                        <span>Total soal</span>
                    </div>
                </div>
                <p class="siswa-quiz-result-note">Hijau = jawaban benar, merah = jawaban salah.</p>

                <?php if (!empty($quizResultReview)): ?>
                    <div class="siswa-quiz-review-list">
                        <?php foreach ($quizResultReview as $reviewIndex => $reviewItem): ?>
                            <?php
                            $reviewQuestion = (array) ($reviewItem['question'] ?? []);
                            $reviewSelected = strtoupper((string) ($reviewItem['selected_option'] ?? '-'));
                            $reviewCorrect = strtoupper((string) ($reviewQuestion['correct_option'] ?? '-'));
                            $reviewTimedOut = !empty($reviewItem['is_timeout']);
                            ?>
                            <article class="siswa-quiz-review-item <?= !empty($reviewItem['is_correct']) ? 'is-correct' : 'is-wrong'; ?>">
                                <strong>Soal <?= (int) $reviewIndex + 1; ?> · <?= !empty($reviewItem['is_correct']) ? 'Benar' : ($reviewTimedOut ? 'Waktu Habis' : 'Salah'); ?></strong>
                                <p><?= chemnama_e((string) ($reviewQuestion['question_text'] ?? '')); ?></p>
                                <small>
                                    <?php if ($reviewTimedOut): ?>
                                        Jawaban kamu: tidak dijawab (timer habis)
                                    <?php else: ?>
                                        Jawaban kamu: <?= chemnama_e($reviewSelected); ?>
                                    <?php endif; ?>
                                    · Jawaban benar: <?= chemnama_e($reviewCorrect); ?>
                                </small>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="siswa-quiz-action-row">
                    <?php if ($latestRequest && $latestRequest['status'] === 'pending'): ?>
                        <a class="btn btn-primary" href="siswa_quiz.php?quiz_set_id=<?= (int) $selectedQuizSetId; ?>&complete=1">Menunggu Persetujuan</a>
                    <?php elseif ($canRetake): ?>
                        <a class="btn btn-primary" href="siswa_quiz.php?quiz_set_id=<?= (int) $selectedQuizSetId; ?>&restart=1">Ulangi Quiz</a>
                    <?php else: ?>
                        <a class="btn btn-primary" href="siswa_quiz.php?request_retry_for=<?= (int) $selectedQuizSetId; ?>#retryQuizModal">Request Ulang</a>
                    <?php endif; ?>
                    <a class="btn btn-ghost" href="siswa_quiz.php">Pilih Quiz Lain</a>
                </div>

                <?php if (isset($repeatRequestMap[$selectedQuizSetId]) && $repeatRequestMap[$selectedQuizSetId]['status'] === 'pending'): ?>
                    <div class="alert alert-info">Permintaan ulang sedang menunggu persetujuan guru.</div>
                <?php elseif (isset($repeatRequestMap[$selectedQuizSetId]) && $repeatRequestMap[$selectedQuizSetId]['status'] === 'approved'): ?>
                    <div class="alert alert-success">Guru sudah mengizinkan kamu mengulangi quiz ini.</div>
                <?php endif; ?>
            </article>
        <?php else: ?>
            <div class="siswa-quiz-overview-grid">
                <article class="guru-panel siswa-quiz-intro-card">
                    <span class="siswa-quiz-eyebrow">Mulai dari sini</span>
                    <h2>Pilih quiz yang sudah dipublish guru.</h2>
                    <p>Satu modul bisa memiliki lebih dari satu quiz. Quiz yang belum dipublish tidak akan tampil di sini.</p>
                </article>

                <div class="siswa-quiz-module-grid">
                    <?php if (!empty($quizSets)): ?>
                        <?php foreach ($quizSets as $quizSet): ?>
                            <?php
                            $quizSetCardId = (int) $quizSet['id'];
                            $attemptStat = $quizAttemptMap[$quizSetCardId] ?? null;
                            $requestStat = $repeatRequestMap[$quizSetCardId] ?? null;
                            $hasAttempt = $attemptStat !== null;
                            $buttonLabel = 'Mulai Quiz';
                            $buttonHref = 'siswa_quiz.php?quiz_set_id=' . $quizSetCardId;
                            if ($hasAttempt && !($requestStat && $requestStat['status'] === 'approved' && empty($requestStat['used_at']))) {
                                $buttonLabel = 'Lihat Nilai';
                                $buttonHref = 'siswa_quiz.php?quiz_set_id=' . $quizSetCardId . '&complete=1';
                            } elseif ($requestStat && $requestStat['status'] === 'approved' && empty($requestStat['used_at'])) {
                                $buttonLabel = 'Ulangi Quiz';
                                $buttonHref = 'siswa_quiz.php?quiz_set_id=' . $quizSetCardId . '&restart=1';
                            }
                            ?>
                            <article class="guru-panel siswa-quiz-module-card">
                                <div class="siswa-quiz-module-head">
                                    <div class="siswa-quiz-module-icon"><?= chemnama_icon('stack', '#0f9d58'); ?></div>
                                    <div>
                                        <span><?= chemnama_e((string) $quizSet['module_badge']); ?></span>
                                        <h3><?= chemnama_e((string) $quizSet['title']); ?></h3>
                                    </div>
                                </div>
                                <p><?= chemnama_e((string) $quizSet['description']); ?></p>
                                <div class="siswa-quiz-module-meta">
                                    <span><?= chemnama_e((string) $quizSet['total_questions']); ?> soal</span>
                                    <span><?= chemnama_e((string) ($attemptStat['best_points'] ?? 0)); ?> poin terbaik</span>
                                </div>
                                <?php if ($requestStat && $requestStat['status'] === 'pending'): ?>
                                    <div class="alert alert-info">Permintaan ulang menunggu persetujuan guru.</div>
                                <?php endif; ?>
                                <a class="btn btn-primary btn-block" href="<?= chemnama_e($buttonHref); ?>">
                                    <?= chemnama_e($buttonLabel); ?>
                                </a>
                            </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <article class="guru-panel siswa-quiz-empty-card">
                            <h3>Belum ada quiz yang dipublish.</h3>
                            <p>Silakan tunggu guru mempublikasikan quiz.</p>
                        </article>
                    <?php endif; ?>
                </div>
            </div>

        <?php endif; ?>
    </section>
</main>

<?php if ($showRequestRetryModal): ?>
    <div class="quiz-modal is-open" id="retryQuizModal" aria-hidden="false">
        <a class="quiz-modal-backdrop" href="siswa_quiz.php" aria-label="Tutup"></a>
        <div class="quiz-modal-card guru-panel siswa-quiz-retry-modal-card">
            <div class="quiz-modal-head">
                <div>
                    <h3>Request Ulang Quiz</h3>
                    <p><?= chemnama_e((string) $requestRetryQuizSet['module_badge']); ?> · <?= chemnama_e((string) $requestRetryQuizSet['title']); ?></p>
                </div>
                <a class="quiz-modal-close" href="siswa_quiz.php" aria-label="Tutup form">&times;</a>
            </div>
            <form method="post" class="siswa-quiz-form siswa-quiz-retry-form">
                <input type="hidden" name="action" value="request_retry">
                <input type="hidden" name="quiz_set_id" value="<?= (int) $requestRetryForId; ?>">
                <label>
                    <span>Alasan request ulang</span>
                    <textarea name="reason" rows="5" placeholder="Contoh: ingin memperbaiki jawaban nomor 3 dan 5."></textarea>
                </label>
                <div class="siswa-quiz-action-row">
                    <button class="btn btn-primary" type="submit">Kirim Permintaan</button>
                    <a class="btn btn-ghost" href="siswa_quiz.php">Batal</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<script src="assets/js/app.js?v=20260407"></script>
</body>
</html>

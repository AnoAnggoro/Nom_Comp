<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('siswa');

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    echo json_encode(['ok' => false, 'message' => 'Payload tidak valid.']);
    exit;
}

$quizSetId = (int) ($payload['quiz_set_id'] ?? 0);
$answers = $payload['answers'] ?? null;
if ($quizSetId <= 0 || !is_array($answers)) {
    echo json_encode(['ok' => false, 'message' => 'Data quiz tidak lengkap.']);
    exit;
}

$user = chemnama_current_user();
$userId = (int) ($user['id'] ?? 0);

$classStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
$classStmt->execute(['user_id' => $userId]);
$studentClass = (string) ($classStmt->fetchColumn() ?: '');

$quizSetStmt = $pdo->prepare(
    'SELECT qs.id, qs.title, qs.module_id, qs.is_published,
            u.id AS teacher_id,
            up_teacher.kelas AS teacher_class
     FROM quiz_sets qs
     JOIN users u ON u.id = qs.created_by
     LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
     WHERE qs.id = :quiz_set_id AND qs.is_published = 1 AND u.role = "guru"
     LIMIT 1'
);
$quizSetStmt->execute(['quiz_set_id' => $quizSetId]);
$quizSet = $quizSetStmt->fetch();
if (!$quizSet) {
    echo json_encode(['ok' => false, 'message' => 'Quiz tidak ditemukan.']);
    exit;
}

$teacherClass = (string) ($quizSet['teacher_class'] ?? '');
if ($studentClass !== '') {
    $normalizedTeacherClass = ',' . str_replace(', ', ',', $teacherClass) . ',';
    $matchToken = ',' . $studentClass . ',';
    if ($teacherClass !== '' && strpos($normalizedTeacherClass, $matchToken) === false) {
        echo json_encode(['ok' => false, 'message' => 'Quiz tidak sesuai kelas.']);
        exit;
    }
}

$questionsStmt = $pdo->prepare(
    'SELECT id, correct_option, points
     FROM questions
     WHERE quiz_set_id = :quiz_set_id AND is_published = 1
     ORDER BY id'
);
$questionsStmt->execute(['quiz_set_id' => $quizSetId]);
$questions = $questionsStmt->fetchAll();
if (count($questions) === 0) {
    echo json_encode(['ok' => false, 'message' => 'Soal tidak tersedia.']);
    exit;
}

$attemptNumberStmt = $pdo->prepare(
    'SELECT COALESCE(MAX(attempt_number), 0) + 1
     FROM quiz_attempt_runs
     WHERE quiz_set_id = :quiz_set_id AND student_id = :student_id'
);
$attemptNumberStmt->execute([
    'quiz_set_id' => $quizSetId,
    'student_id' => $userId,
]);
$attemptNumber = (int) $attemptNumberStmt->fetchColumn();

$totalQuestions = count($questions);
$correctCount = 0;
$earnedPoints = 0;

$pdo->beginTransaction();
try {
    $now = date('Y-m-d H:i:s');
    $answersById = [];
    foreach ($answers as $key => $value) {
        $answersById[(int) $key] = $value !== '' ? $value : null;
    }

    foreach ($questions as $question) {
        $questionId = (int) $question['id'];
        $selected = $answersById[$questionId] ?? null;
        $correctOption = (string) ($question['correct_option'] ?? '');
        $points = (int) ($question['points'] ?? 0);
        $isCorrect = $selected !== null && strtolower((string) $selected) === strtolower($correctOption);
        if ($isCorrect) {
            $correctCount += 1;
            $earnedPoints += $points;
        }
    }

    $score = $totalQuestions > 0 ? (int) round(($correctCount / $totalQuestions) * 100) : 0;

    $insertRun = $pdo->prepare(
        'INSERT INTO quiz_attempt_runs (quiz_set_id, student_id, attempt_number, score, correct_count, total_questions, total_points, started_at, submitted_at)
         VALUES (:quiz_set_id, :student_id, :attempt_number, :score, :correct_count, :total_questions, :total_points, :started_at, :submitted_at)'
    );
    $insertRun->execute([
        'quiz_set_id' => $quizSetId,
        'student_id' => $userId,
        'attempt_number' => $attemptNumber,
        'score' => $score,
        'correct_count' => $correctCount,
        'total_questions' => $totalQuestions,
        'total_points' => $earnedPoints,
        'started_at' => $now,
        'submitted_at' => $now,
    ]);

    $attemptRunId = (int) $pdo->lastInsertId();

    $insertAnswer = $pdo->prepare(
        'INSERT INTO quiz_attempt_answers (attempt_run_id, question_id, selected_option, is_correct, answered_at)
         VALUES (:attempt_run_id, :question_id, :selected_option, :is_correct, :answered_at)'
    );

    foreach ($questions as $question) {
        $questionId = (int) $question['id'];
        $selected = $answersById[$questionId] ?? null;
        $correctOption = (string) ($question['correct_option'] ?? '');
        $points = (int) ($question['points'] ?? 0);
        $isCorrect = $selected !== null && strtolower((string) $selected) === strtolower($correctOption);
        $insertAnswer->execute([
            'attempt_run_id' => $attemptRunId,
            'question_id' => $questionId,
            'selected_option' => $selected !== null ? strtolower((string) $selected) : null,
            'is_correct' => $isCorrect ? 1 : 0,
            'answered_at' => $now,
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'score' => $score,
        'correct_count' => $correctCount,
        'total_questions' => $totalQuestions,
        'earned_points' => $earnedPoints,
    ]);
} catch (Throwable $exception) {
    $pdo->rollBack();
    echo json_encode(['ok' => false, 'message' => 'Gagal menyimpan nilai.']);
}

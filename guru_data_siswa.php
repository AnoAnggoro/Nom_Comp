<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth();

$user = chemnama_current_user();
if (($user['role'] ?? 'siswa') !== 'guru') {
    header('Location: dashboard.php');
    exit;
}

$guruId = (int) ($user['id'] ?? 0);
$activeClass = chemnama_get_guru_active_class($pdo, $guruId);
$guruNotification = chemnama_guru_notification_summary($pdo, $guruId, $activeClass);
$guruNotificationCount = (int) ($guruNotification['total'] ?? 0);

chemnama_ensure_quick_quiz_score_column($pdo);

$studentsStmt = $pdo->prepare(
    'SELECT u.id, u.name, u.avatar_color, up.kelas,
            COALESCE(sp.material_count, 0) AS material_count,
            COALESCE(sp.quiz_count, 0) AS quiz_count,
            sp.average_score,
            COALESCE(sp.status_label, "Aktif") AS status_label,
            COALESCE(sp.sort_order, 999) AS sort_order,
            (SELECT COUNT(*) FROM essay_answers ea
             JOIN essay_tasks et ON et.id = ea.task_id
                         WHERE ea.student_id = u.id
                             AND et.is_published = 1
                             AND et.created_by = :essay_guru_id
                             AND (et.class_name = :essay_class OR et.class_name IS NULL)) AS essay_count,
            (SELECT COUNT(*) FROM homework_submissions hs
             JOIN homework_tasks ht ON ht.id = hs.task_id
                         WHERE hs.student_id = u.id
                             AND ht.is_published = 1
                             AND ht.created_by = :homework_guru_id
                             AND (ht.class_name = :homework_class OR ht.class_name IS NULL)) AS homework_count
     FROM users u
     JOIN user_profiles up ON up.user_id = u.id
     LEFT JOIN student_progress sp ON sp.user_id = u.id
         WHERE u.role = "siswa" AND up.kelas = :kelas_main
     ORDER BY sort_order ASC, u.name ASC'
);
$studentsStmt->execute([
        'essay_guru_id' => $guruId,
        'essay_class' => $activeClass,
        'homework_guru_id' => $guruId,
        'homework_class' => $activeClass,
        'kelas_main' => $activeClass,
]);
$students = $studentsStmt->fetchAll();

$quizModulesStmt = $pdo->prepare(
    'SELECT qs.id AS quiz_set_id, qs.module_id, qs.title AS quiz_title,
            m.badge, m.title AS module_title,
            COUNT(q.id) AS total_questions,
            COALESCE(SUM(q.points), 0) AS total_points
         FROM quiz_sets qs
         JOIN modules m ON m.id = qs.module_id
         JOIN users u_teacher ON u_teacher.id = qs.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u_teacher.id
         LEFT JOIN questions q ON q.quiz_set_id = qs.id AND q.is_published = 1
         WHERE qs.is_published = 1
             AND u_teacher.role = "guru"
             AND INSTR(
                        CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
                        CONCAT(CHAR(44), :kelas, CHAR(44))
             ) > 0
         GROUP BY qs.id, qs.module_id, qs.title, m.badge, m.title, m.sort_order, qs.sort_order
         HAVING total_questions > 0
         ORDER BY m.sort_order, qs.sort_order, qs.id'
);
$quizModulesStmt->execute([
        'kelas' => $activeClass,
]);
$quizModules = $quizModulesStmt->fetchAll();

$quickQuizModulesStmt = $pdo->prepare(
    'SELECT qq.id AS quick_quiz_id, qq.module_id,
          m.badge, m.title AS module_title
    FROM quick_quizzes qq
    JOIN modules m ON m.id = qq.module_id
    JOIN users u_teacher ON u_teacher.id = qq.created_by
    LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u_teacher.id
    WHERE qq.is_published = 1
      AND qq.created_by = :guru_id
      AND qq.id = (
          SELECT qq2.id
          FROM quick_quizzes qq2
          WHERE qq2.module_id = qq.module_id
            AND qq2.created_by = qq.created_by
            AND qq2.is_published = 1
          ORDER BY qq2.created_at DESC, qq2.id DESC
          LIMIT 1
      )
      AND u_teacher.role = "guru"
      AND INSTR(
          CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
          CONCAT(CHAR(44), :kelas, CHAR(44))
      ) > 0
    ORDER BY m.sort_order, qq.id'
);
$quickQuizModulesStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$quickQuizModules = $quickQuizModulesStmt->fetchAll();

$quizTotal = 0;
$quizPointsTotal = 0;
foreach ($quizModules as $quizModule) {
    $quizTotal += (int) $quizModule['total_questions'];
    $quizPointsTotal += (int) ($quizModule['total_points'] ?? 0);
}

$quizAttemptStatsStmt = $pdo->prepare(
                'SELECT qar.student_id, qar.quiz_set_id,
                        COUNT(*) AS attempt_count,
                        (
                                SELECT qar2.correct_count
                                FROM quiz_attempt_runs qar2
                                WHERE qar2.student_id = qar.student_id
                                    AND qar2.quiz_set_id = qar.quiz_set_id
                                    AND qar2.submitted_at IS NOT NULL
                                ORDER BY qar2.submitted_at DESC, qar2.id DESC
                                LIMIT 1
                        ) AS last_correct_count,
                        (
                                SELECT qar3.total_questions
                                FROM quiz_attempt_runs qar3
                                WHERE qar3.student_id = qar.student_id
                                    AND qar3.quiz_set_id = qar.quiz_set_id
                                    AND qar3.submitted_at IS NOT NULL
                                ORDER BY qar3.submitted_at DESC, qar3.id DESC
                                LIMIT 1
                        ) AS last_total_questions,
                        (
                                SELECT qar4.total_points
                                FROM quiz_attempt_runs qar4
                                WHERE qar4.student_id = qar.student_id
                                    AND qar4.quiz_set_id = qar.quiz_set_id
                                    AND qar4.submitted_at IS NOT NULL
                                ORDER BY qar4.submitted_at DESC, qar4.id DESC
                                LIMIT 1
                        ) AS last_points
         FROM quiz_attempt_runs qar
         JOIN quiz_sets qs ON qs.id = qar.quiz_set_id
         JOIN users u_teacher ON u_teacher.id = qs.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u_teacher.id
         JOIN user_profiles up_student ON up_student.user_id = qar.student_id
         WHERE qs.is_published = 1
             AND up_student.kelas = :kelas_student
                         AND qar.submitted_at IS NOT NULL
             AND u_teacher.role = "guru"
             AND INSTR(
                        CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
                        CONCAT(CHAR(44), :kelas_teacher, CHAR(44))
             ) > 0
                 GROUP BY qar.student_id, qar.quiz_set_id'
);
$quizAttemptStatsStmt->execute([
        'kelas_student' => $activeClass,
        'kelas_teacher' => $activeClass,
]);
$quizAttemptStatsRows = $quizAttemptStatsStmt->fetchAll();

$quickQuizAttemptsStmt = $pdo->prepare(
    'SELECT qqa.student_id, qqa.quick_quiz_id, qqa.is_correct, COALESCE(qqa.score, CASE WHEN qqa.is_correct = 1 THEN 100 ELSE 0 END) AS score
     FROM quick_quiz_attempts qqa
     JOIN quick_quizzes qq ON qq.id = qqa.quick_quiz_id
     JOIN users u_teacher ON u_teacher.id = qq.created_by
     LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u_teacher.id
     JOIN user_profiles up_student ON up_student.user_id = qqa.student_id
     WHERE qq.is_published = 1
       AND qq.created_by = :guru_id
       AND up_student.kelas = :kelas_student
       AND u_teacher.role = "guru"
       AND INSTR(
            CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
            CONCAT(CHAR(44), :kelas_teacher, CHAR(44))
       ) > 0'
);
$quickQuizAttemptsStmt->execute([
    'guru_id' => $guruId,
    'kelas_student' => $activeClass,
    'kelas_teacher' => $activeClass,
]);
$quickQuizAttemptRows = $quickQuizAttemptsStmt->fetchAll();

$quizAttemptStatsMap = [];
foreach ($quizAttemptStatsRows as $row) {
    $studentId = (int) $row['student_id'];
    $quizSetId = (int) $row['quiz_set_id'];
    $quizAttemptStatsMap[$studentId][$quizSetId] = [
        'attempt_count' => (int) $row['attempt_count'],
        'last_correct_count' => isset($row['last_correct_count']) ? (int) $row['last_correct_count'] : 0,
        'last_total_questions' => isset($row['last_total_questions']) ? (int) $row['last_total_questions'] : 0,
        'last_points' => isset($row['last_points']) ? (int) $row['last_points'] : 0,
    ];
}

$quickQuizAttemptMap = [];
foreach ($quickQuizAttemptRows as $row) {
    $studentId = (int) $row['student_id'];
    $quickQuizId = (int) $row['quick_quiz_id'];
    $quickQuizAttemptMap[$studentId][$quickQuizId] = [
        'is_correct' => (int) ($row['is_correct'] ?? 0) === 1,
        'score' => (int) ($row['score'] ?? 0),
    ];
}

$quickQuizTotal = count($quickQuizModules);

$essayTotalStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM essay_tasks
     WHERE is_published = 1
       AND created_by = :guru_id
       AND (class_name = :kelas OR class_name IS NULL)'
);
$essayTotalStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$essayTotal = (int) $essayTotalStmt->fetchColumn();

$homeworkTotalStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM homework_tasks
     WHERE is_published = 1
       AND created_by = :guru_id
       AND (class_name = :kelas OR class_name IS NULL)'
);
$homeworkTotalStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$homeworkTotal = (int) $homeworkTotalStmt->fetchColumn();

$chemMatchTotalStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM chem_match_games
     WHERE is_published = 1
       AND created_by = :guru_id
       AND (class_name = :kelas OR class_name IS NULL)'
);
$chemMatchTotalStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$chemMatchTotal = (int) $chemMatchTotalStmt->fetchColumn();

$wordSearchTotalStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM word_search_games
     WHERE is_published = 1
       AND created_by = :guru_id
       AND (class_name = :kelas OR class_name IS NULL)'
);
$wordSearchTotalStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$wordSearchTotal = (int) $wordSearchTotalStmt->fetchColumn();

$simulationTotalStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM simulations
     WHERE is_published = 1
       AND created_by = :guru_id
       AND (class_name = :kelas OR class_name IS NULL)'
);
$simulationTotalStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$simulationTotal = (int) $simulationTotalStmt->fetchColumn();

$chemMatchModuleIdsStmt = $pdo->prepare(
    'SELECT DISTINCT module_id
     FROM chem_match_games
     WHERE is_published = 1
       AND created_by = :guru_id
       AND (class_name = :kelas OR class_name IS NULL)'
);
$chemMatchModuleIdsStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$chemMatchModuleIds = array_map('intval', $chemMatchModuleIdsStmt->fetchAll(PDO::FETCH_COLUMN));

$wordSearchModuleIdsStmt = $pdo->prepare(
    'SELECT DISTINCT module_id
     FROM word_search_games
     WHERE is_published = 1
       AND created_by = :guru_id
       AND (class_name = :kelas OR class_name IS NULL)'
);
$wordSearchModuleIdsStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$wordSearchModuleIds = array_map('intval', $wordSearchModuleIdsStmt->fetchAll(PDO::FETCH_COLUMN));

$simulationModuleIdsStmt = $pdo->prepare(
    'SELECT DISTINCT module_id
     FROM simulations
     WHERE is_published = 1
       AND created_by = :guru_id
       AND (class_name = :kelas OR class_name IS NULL)'
);
$simulationModuleIdsStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$simulationModuleIds = array_map('intval', $simulationModuleIdsStmt->fetchAll(PDO::FETCH_COLUMN));

$gameTotal = $chemMatchTotal + $wordSearchTotal;

$essayTasksStmt = $pdo->prepare(
    'SELECT t.id, t.prompt_text, m.badge AS module_badge
     FROM essay_tasks t
     JOIN modules m ON m.id = t.module_id
     WHERE t.is_published = 1
       AND t.created_by = :guru_id
       AND (t.class_name = :kelas OR t.class_name IS NULL)
     ORDER BY t.created_at DESC, t.id DESC'
);
$essayTasksStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$essayTasks = $essayTasksStmt->fetchAll();

$homeworkTasksStmt = $pdo->prepare(
    'SELECT t.id, t.title, t.description, m.badge AS module_badge
     FROM homework_tasks t
     JOIN modules m ON m.id = t.module_id
     WHERE t.is_published = 1
       AND t.created_by = :guru_id
       AND (t.class_name = :kelas OR t.class_name IS NULL)
     ORDER BY t.created_at DESC, t.id DESC'
);
$homeworkTasksStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
]);
$homeworkTasks = $homeworkTasksStmt->fetchAll();

$essayAnswerRowsStmt = $pdo->prepare(
    'SELECT a.student_id, a.task_id
     FROM essay_answers a
     JOIN essay_tasks t ON t.id = a.task_id
     JOIN user_profiles up_student ON up_student.user_id = a.student_id
     WHERE t.is_published = 1
       AND t.created_by = :guru_id
       AND (t.class_name = :kelas OR t.class_name IS NULL)
       AND up_student.kelas = :kelas_student'
);
$essayAnswerRowsStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
    'kelas_student' => $activeClass,
]);
$essayAnswerRows = $essayAnswerRowsStmt->fetchAll();

$homeworkSubmissionRowsStmt = $pdo->prepare(
    'SELECT s.student_id, s.task_id
     FROM homework_submissions s
     JOIN homework_tasks t ON t.id = s.task_id
     JOIN user_profiles up_student ON up_student.user_id = s.student_id
     WHERE t.is_published = 1
       AND t.created_by = :guru_id
       AND (t.class_name = :kelas OR t.class_name IS NULL)
       AND up_student.kelas = :kelas_student'
);
$homeworkSubmissionRowsStmt->execute([
    'guru_id' => $guruId,
    'kelas' => $activeClass,
    'kelas_student' => $activeClass,
]);
$homeworkSubmissionRows = $homeworkSubmissionRowsStmt->fetchAll();

$essayAnswerMap = [];
foreach ($essayAnswerRows as $row) {
    $studentId = (int) $row['student_id'];
    $taskId = (int) $row['task_id'];
    $essayAnswerMap[$studentId][$taskId] = true;
}

$homeworkSubmissionMap = [];
foreach ($homeworkSubmissionRows as $row) {
    $studentId = (int) $row['student_id'];
    $taskId = (int) $row['task_id'];
    $homeworkSubmissionMap[$studentId][$taskId] = true;
}

$totalStudents = count($students);
$averageScore = 0.0;
$scoredStudents = 0;
$activeCount = 0;
foreach ($students as $student) {
    if ($student['average_score'] !== null) {
        $averageScore += (float) $student['average_score'];
        $scoredStudents += 1;
    }

    if (($student['status_label'] ?? '') === 'Aktif') {
        $activeCount += 1;
    }
}
$averageScore = $scoredStudents > 0 ? $averageScore / $scoredStudents : 0.0;

$averageQuizPoints = 0.0;

foreach ($students as &$student) {
    $studentId = (int) $student['id'];
    $quizAnsweredTotal = 0;
    $quizCorrectTotal = 0;
    $quizPointsEarnedTotal = 0;
    $quizModuleRows = [];
    $quickQuizDoneTotal = 0;
    $quickQuizCorrectTotal = 0;
    $quickQuizPointsEarnedTotal = 0;
    $quickQuizModuleRows = [];

    foreach ($quizModules as $quizModule) {
        $quizSetId = (int) $quizModule['quiz_set_id'];
        $totalQuestions = (int) $quizModule['total_questions'];
        $totalModulePoints = (int) ($quizModule['total_points'] ?? 0);
        $attemptStat = $quizAttemptStatsMap[$studentId][$quizSetId] ?? [
            'attempt_count' => 0,
            'last_correct_count' => 0,
            'last_total_questions' => 0,
            'last_points' => 0,
        ];
        $attemptCount = (int) ($attemptStat['attempt_count'] ?? 0);
        $answeredCount = $attemptCount > 0 ? $totalQuestions : 0;
        $correctCount = min((int) ($attemptStat['last_correct_count'] ?? 0), $totalQuestions);
        $bestPoints = min((int) ($attemptStat['last_points'] ?? 0), $totalModulePoints);

        $quizAnsweredTotal += $answeredCount;
        $quizCorrectTotal += $correctCount;
        $quizPointsEarnedTotal += $bestPoints;

        $quizModuleRows[] = [
            'label' => (string) $quizModule['badge'] . ' - ' . (string) $quizModule['quiz_title'],
            'answered' => $answeredCount,
            'correct' => $correctCount,
            'points' => $bestPoints,
            'max_points' => $totalModulePoints,
            'total' => $totalQuestions,
            'done' => $attemptCount > 0,
        ];
    }

    foreach ($quickQuizModules as $quickQuizModule) {
        $quickQuizId = (int) ($quickQuizModule['quick_quiz_id'] ?? 0);
        $attemptData = $quickQuizAttemptMap[$studentId][$quickQuizId] ?? null;
        $isAttempted = $attemptData !== null;
        $isCorrect = $isAttempted && !empty($attemptData['is_correct']);
        $pointsEarned = $isAttempted ? (int) ($attemptData['score'] ?? ($isCorrect ? 100 : 0)) : 0;

        if ($isAttempted) {
            $quickQuizDoneTotal += 1;
        }
        if ($isCorrect) {
            $quickQuizCorrectTotal += 1;
            $quickQuizPointsEarnedTotal += $pointsEarned;
        }

        $quickQuizModuleRows[] = [
            'label' => (string) $quickQuizModule['badge'] . ' - Quick Quiz',
            'answered' => $isAttempted ? 1 : 0,
            'correct' => $isCorrect ? 1 : 0,
            'points' => $pointsEarned,
            'max_points' => 100,
            'total' => 1,
            'done' => $isAttempted,
        ];
    }

    $essayDone = (int) $student['essay_count'];
    $homeworkDone = (int) $student['homework_count'];
    $gameModuleRows = [];
    foreach (['chem_match', 'word_search'] as $gameType) {
        $gameModules = chemnama_get_student_game_modules($pdo, $studentId, $gameType);
        foreach ($gameModules as $module) {
            $moduleId = (int) $module['id'];

            if ($gameType === 'chem_match') {
                $doneStmt = $pdo->prepare('SELECT COUNT(*) FROM chem_match_reads cmr JOIN chem_match_games cmg ON cmg.id = cmr.chem_match_id WHERE cmr.student_id = :student_id AND cmr.completion_time IS NOT NULL AND cmg.module_id = :module_id');
            } elseif ($gameType === 'word_search') {
                $doneStmt = $pdo->prepare('SELECT COUNT(*) FROM word_search_reads wsr JOIN word_search_games wsg ON wsg.id = wsr.word_search_id WHERE wsr.student_id = :student_id AND wsr.completion_time IS NOT NULL AND wsg.module_id = :module_id');
            } else {
                $doneStmt = $pdo->prepare('SELECT COUNT(*) FROM simulation_reads sr JOIN simulations s ON s.id = sr.simulation_id WHERE sr.student_id = :student_id AND sr.completion_time IS NOT NULL AND s.module_id = :module_id');
            }

            $doneStmt->execute([
                'student_id' => $studentId,
                'module_id' => $moduleId,
            ]);

            $doneCount = (int) $doneStmt->fetchColumn();
            $gameModuleRows[] = [
                'game_label' => $gameType === 'chem_match' ? 'Tarik Garis' : 'Cari Kata',
                'module_label' => trim((string) ($module['badge'] ?? '') . ' - ' . (string) ($module['title'] ?? '')),
                'done' => $doneCount > 0,
                'done_count' => $doneCount,
                'total_count' => 1,
            ];
        }
    }

    $gamesDone = 0;
    foreach ($gameModuleRows as $gameRow) {
        if (!empty($gameRow['done'])) {
            $gamesDone += 1;
        }
    }
    $gameTotal = count($gameModuleRows);
    $quizDoneClamped = $quizTotal > 0 ? min($quizAnsweredTotal, $quizTotal) : 0;
    $quizCorrectClamped = $quizTotal > 0 ? min($quizCorrectTotal, $quizTotal) : 0;
    $essayDoneClamped = $essayTotal > 0 ? min($essayDone, $essayTotal) : 0;
    $homeworkDoneClamped = $homeworkTotal > 0 ? min($homeworkDone, $homeworkTotal) : 0;
    $gamesDoneClamped = $gameTotal > 0 ? min($gamesDone, $gameTotal) : 0;
    $totalPossible = $quizTotal + $essayTotal + $homeworkTotal;
    $totalDone = $quizDoneClamped + $essayDoneClamped + $homeworkDoneClamped;

    $student['nim'] = (string) $studentId;
    $student['quiz_done'] = $quizDoneClamped;
    $student['quiz_correct'] = $quizCorrectClamped;
    $student['quiz_answered'] = $quizDoneClamped;
    $student['quiz_points_earned'] = $quizPointsEarnedTotal;
    $student['quiz_points_total'] = $quizPointsTotal;
    $student['quiz_module_rows'] = $quizModuleRows;
    $student['quick_quiz_done'] = $quickQuizDoneTotal;
    $student['quick_quiz_correct'] = $quickQuizCorrectTotal;
    $student['quick_quiz_points_earned'] = $quickQuizPointsEarnedTotal;
    $student['quick_quiz_points_total'] = $quickQuizTotal * 100;
    $student['quick_quiz_module_rows'] = $quickQuizModuleRows;
    $student['quick_quiz_complete'] = $quickQuizTotal > 0 && $quickQuizDoneTotal >= $quickQuizTotal;
    $student['quiz_complete'] = $quizTotal > 0 && $quizDoneClamped >= $quizTotal;
    $student['essay_complete'] = $essayTotal > 0 && $essayDoneClamped >= $essayTotal;
    $student['homework_complete'] = $homeworkTotal > 0 && $homeworkDoneClamped >= $homeworkTotal;
    $student['games_done'] = $gamesDoneClamped;
    $student['games_complete'] = $gameTotal > 0 && $gamesDoneClamped >= $gameTotal;
    $student['games_total'] = $gameTotal;
    $student['game_module_rows'] = $gameModuleRows;
    $student['progress_percent'] = $totalPossible > 0 ? (int) round(($totalDone / $totalPossible) * 100) : null;

    $student['essay_rows'] = [];
    foreach ($essayTasks as $task) {
        $taskId = (int) $task['id'];
        $done = !empty($essayAnswerMap[$studentId][$taskId]);
        $student['essay_rows'][] = [
            'label' => trim((string) $task['module_badge'] . ' - ' . preg_replace('/\s+/', ' ', trim((string) $task['prompt_text']))),
            'done' => $done,
        ];
    }

    $student['homework_rows'] = [];
    foreach ($homeworkTasks as $task) {
        $taskId = (int) $task['id'];
        $done = !empty($homeworkSubmissionMap[$studentId][$taskId]);
        $student['homework_rows'][] = [
            'label' => trim((string) $task['module_badge'] . ' - ' . (string) $task['title']),
            'done' => $done,
        ];
    }

    $scoreParts = [];
    if ($quizTotal > 0) {
        $scoreParts[] = ['label' => 'Kuis', 'done' => $quizDoneClamped, 'total' => $quizTotal];
    }
    if ($essayTotal > 0) {
        $scoreParts[] = ['label' => 'Essay', 'done' => $essayDoneClamped, 'total' => $essayTotal];
    }
    if ($homeworkTotal > 0) {
        $scoreParts[] = ['label' => 'PR', 'done' => $homeworkDoneClamped, 'total' => $homeworkTotal];
    }

    $reasons = [];
    foreach ($scoreParts as $part) {
        if ($part['done'] <= 0) {
            $reasons[] = $part['label'] . ' belum dikerjakan';
        } elseif ($part['done'] < $part['total']) {
            $reasons[] = $part['label'] . ' ' . $part['done'] . '/' . $part['total'];
        }
    }

    if ($quizPointsTotal > 0 && $quizPointsEarnedTotal <= 0) {
        $reasons[] = 'Nilai poin kuis belum ada';
    }

    if ($gameTotal > 0) {
        if ($gamesDoneClamped <= 0) {
            $reasons[] = 'Games belum dikerjakan';
        } elseif ($gamesDoneClamped < $gameTotal) {
            $reasons[] = 'Games ' . $gamesDoneClamped . '/' . $gameTotal;
        }
    }

    if (count($reasons) === 0) {
        $reasons[] = 'Semua tugas sudah dikerjakan';
    }

    $student['progress_reasons'] = $reasons;
    $student['progress_summary'] = [
        'quiz_done' => $quizDoneClamped,
        'quiz_total' => $quizTotal,
        'essay_done' => $essayDoneClamped,
        'essay_total' => $essayTotal,
        'homework_done' => $homeworkDoneClamped,
        'homework_total' => $homeworkTotal,
    ];
}
unset($student);

if ($totalStudents > 0) {
    $sumPoints = 0;
    foreach ($students as $student) {
        $sumPoints += (int) ($student['quiz_points_earned'] ?? 0);
    }
    $averageQuizPoints = $sumPoints / $totalStudents;
}

$flash = chemnama_flash();

$guruMenus = [
       ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => false],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
    ['label' => 'Bank Soal PG', 'icon' => 'stack', 'href' => 'guru_soal_pg.php', 'active' => false],
    ['label' => 'Tugas Essay', 'icon' => 'edit', 'href' => 'guru_essay.php', 'active' => false],
    ['label' => 'PR / Homework', 'icon' => 'task', 'href' => 'guru_homework.php', 'active' => false],
    ['label' => 'Quick / Quiz', 'icon' => 'stack', 'href' => 'guru_quiz_pg.php', 'active' => false],
    ['label' => 'Pengaturan Games', 'icon' => 'beaker', 'href' => 'guru_games.php', 'active' => false],
    ['label' => 'Edit Intro Siswa', 'icon' => 'edit', 'href' => 'guru_intro_siswa.php', 'active' => false],
    ['label' => 'Forum Diskusi', 'icon' => 'chat', 'href' => 'guru_forum.php', 'active' => false],
    ['label' => 'Data Siswa', 'icon' => 'users', 'href' => 'guru_data_siswa.php', 'active' => true],
    ['label' => 'Profil', 'icon' => 'user', 'href' => 'guru_profil.php', 'active' => false],
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Siswa - Nom Comp</title>
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
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=guru_data_siswa.php">
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

    <section class="guru-content data-siswa-content">
        <div class="guru-headline-row">
            <div>
                <h1>Data Siswa</h1>
                <p>Daftar siswa <?= chemnama_e($activeClass); ?> dan progres belajarnya.</p>
            </div>
            <div class="guru-head-actions">
            </div>
        </div>
        <a class="guru-back-link guru-back-link-inline" href="javascript:history.back()"><?= chemnama_icon('switch', '#64748b'); ?> Kembali</a>

        <?php if ($flash): ?>
            <div class="alert alert-<?= chemnama_e($flash['type']); ?>"><?= chemnama_e($flash['message']); ?></div>
        <?php endif; ?>

        <div class="data-siswa-summary-row">
            <article class="guru-panel data-siswa-summary-card">
                <span class="data-siswa-summary-label">Total Siswa</span>
                <strong><?= chemnama_e((string) $totalStudents); ?></strong>
                <small>Terdaftar di <?= chemnama_e($activeClass); ?></small>
            </article>
            <article class="guru-panel data-siswa-summary-card">
                <span class="data-siswa-summary-label">Rata-rata Poin PG</span>
                <strong><?= chemnama_e(number_format($averageQuizPoints, 0)); ?></strong>
                <small>Dari nilai poin kuis siswa</small>
            </article>
            <article class="guru-panel data-siswa-summary-card">
                <span class="data-siswa-summary-label">Status Aktif</span>
                <strong><?= chemnama_e((string) $activeCount); ?></strong>
                <small>Siswa dengan status aktif</small>
            </article>
        </div>

        <article class="guru-panel data-siswa-panel">
            <div class="data-siswa-toolbar">
                <input type="search" class="data-siswa-search" placeholder="Cari nama..." data-data-siswa-search>
            </div>

            <div class="data-siswa-table-wrap">
                <table class="data-siswa-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>NIM</th>
                            <th>Nama</th>
                            <th>PG</th>
                            <th>Essay</th>
                            <th>PR</th>
                            <th>Games Modul</th>
                            <th>Nilai PG</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($totalStudents === 0): ?>
                            <tr data-data-siswa-empty>
                                <td colspan="9">
                                    <div class="data-siswa-empty">Belum ada siswa di kelas ini.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $index => $student): ?>
                                <?php
                                    $quizDone = (int) $student['quiz_answered'];
                                    $quizCorrect = (int) $student['quiz_correct'];
                                    $essayDone = (int) $student['essay_count'];
                                    $homeworkDone = (int) $student['homework_count'];
                                    $progressPercent = $student['progress_percent'];
                                    $taskBreakdown = $student['progress_reasons'];
                                    $studentId = (int) $student['id'];
                                    $quizPointsEarned = (int) ($student['quiz_points_earned'] ?? 0);
                                    $quizPointsTotalStudent = (int) ($student['quiz_points_total'] ?? 0);
                                    $quickQuizDone = (int) ($student['quick_quiz_done'] ?? 0);
                                    $quickQuizCorrect = (int) ($student['quick_quiz_correct'] ?? 0);
                                    $quickQuizPointsEarned = (int) ($student['quick_quiz_points_earned'] ?? 0);
                                    $quickQuizPointsTotal = (int) ($student['quick_quiz_points_total'] ?? 0);
                                    $gamesDone = (int) ($student['games_done'] ?? 0);
                                    $gamesTotal = (int) ($student['games_total'] ?? 0);
                                ?>
                                <tr data-data-siswa-row data-detail-target="<?= chemnama_e((string) $studentId); ?>" data-name="<?= chemnama_e(strtolower((string) $student['name'])); ?>">
                                    <td><?= $index + 1; ?></td>
                                    <td><?= chemnama_e((string) $student['nim']); ?></td>
                                    <td>
                                        <div class="data-siswa-name-cell">
                                            <span class="data-siswa-avatar" style="background: <?= chemnama_e((string) $student['avatar_color']); ?>16; color: <?= chemnama_e((string) $student['avatar_color']); ?>;">
                                                <?= strtoupper(substr((string) $student['name'], 0, 1)); ?>
                                            </span>
                                            <strong><?= chemnama_e((string) $student['name']); ?></strong>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="data-siswa-task-pill <?= $student['quiz_complete'] ? 'ok' : 'danger'; ?>">
                                            <?= chemnama_icon($student['quiz_complete'] ? 'check' : 'x', $student['quiz_complete'] ? '#0f9d58' : '#ef4444'); ?>
                                            <?= chemnama_e((string) $quizPointsEarned); ?>/<?= chemnama_e((string) $quizPointsTotalStudent); ?> poin
                                        </span>
                                    </td>
                                    <td>
                                        <span class="data-siswa-task-pill <?= $student['essay_complete'] ? 'ok' : 'danger'; ?>">
                                            <?= chemnama_icon($student['essay_complete'] ? 'check' : 'x', $student['essay_complete'] ? '#0f9d58' : '#ef4444'); ?>
                                            <?= chemnama_e((string) $essayDone); ?>/<?= chemnama_e((string) $essayTotal); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="data-siswa-task-pill <?= $student['homework_complete'] ? 'ok' : 'danger'; ?>">
                                            <?= chemnama_icon($student['homework_complete'] ? 'check' : 'x', $student['homework_complete'] ? '#0f9d58' : '#ef4444'); ?>
                                            <?= chemnama_e((string) $homeworkDone); ?>/<?= chemnama_e((string) $homeworkTotal); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="data-siswa-task-pill <?= $student['games_complete'] ? 'ok' : 'danger'; ?>">
                                            <?= chemnama_icon($student['games_complete'] ? 'check' : 'x', $student['games_complete'] ? '#0f9d58' : '#ef4444'); ?>
                                            <?= chemnama_e((string) $gamesDone); ?>/<?= chemnama_e((string) $gamesTotal); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong class="quiz-final-points"><?= chemnama_e((string) $quizPointsEarned); ?> poin</strong>
                                    </td>
                                    <td>
                                        <button class="data-siswa-detail-toggle" type="button" data-detail-toggle="<?= chemnama_e((string) $studentId); ?>" aria-expanded="false">
                                            Lihat Detail
                                        </button>
                                    </td>
                                </tr>
                                <tr class="data-siswa-detail-row" data-detail-for="<?= chemnama_e((string) $studentId); ?>" hidden>
                                    <td colspan="9">
                                        <div class="data-siswa-detail-shell">
                                            <div class="data-siswa-detail-head">
                                                <strong><?= chemnama_e((string) $student['name']); ?></strong>
                                                <span><?= chemnama_e((string) $student['nim']); ?> · <?= chemnama_e($activeClass); ?></span>
                                            </div>

                                            <div class="data-siswa-subtable-grid">
                                                <section class="data-siswa-subtable-card">
                                                    <h4>Detail Quick Quiz (Step 2)</h4>
                                                    <table class="data-siswa-mini-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Quick Quiz</th>
                                                                <th>Status</th>
                                                                <th>Ringkas</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (count($student['quick_quiz_module_rows']) === 0): ?>
                                                                <tr>
                                                                    <td colspan="3" class="data-siswa-mini-empty">Belum ada quick quiz yang dipublikasikan.</td>
                                                                </tr>
                                                            <?php else: ?>
                                                                <?php foreach ($student['quick_quiz_module_rows'] as $quickQuizRow): ?>
                                                                    <tr>
                                                                        <td><?= chemnama_e((string) $quickQuizRow['label']); ?></td>
                                                                        <td>
                                                                            <span class="data-siswa-task-pill <?= $quickQuizRow['done'] ? 'ok' : 'danger'; ?>">
                                                                                <?= chemnama_icon($quickQuizRow['done'] ? 'check' : 'x', $quickQuizRow['done'] ? '#0f9d58' : '#ef4444'); ?>
                                                                                <?= $quickQuizRow['done'] ? 'Sudah Mengerjakan' : 'Belum'; ?>
                                                                            </span>
                                                                        </td>
                                                                        <td>Nilai <?= chemnama_e((string) $quickQuizRow['points']); ?>/<?= chemnama_e((string) $quickQuizRow['max_points']); ?> · Benar <?= chemnama_e((string) $quickQuizRow['correct']); ?>/<?= chemnama_e((string) $quickQuizRow['total']); ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </section>

                                                 <section class="data-siswa-subtable-card">
                                                    <h4>Detail Quiz PG (Step 5)</h4>
                                                    <table class="data-siswa-mini-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Quiz</th>
                                                                <th>Status</th>
                                                                <th>Ringkas</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (count($student['quiz_module_rows']) === 0): ?>
                                                                <tr>
                                                                    <td colspan="3" class="data-siswa-mini-empty">Belum ada soal PG/Quiz yang dipublikasikan.</td>
                                                                </tr>
                                                            <?php else: ?>
                                                                <?php foreach ($student['quiz_module_rows'] as $quizRow): ?>
                                                                    <tr>
                                                                        <td><?= chemnama_e((string) $quizRow['label']); ?></td>
                                                                        <td>
                                                                            <span class="data-siswa-task-pill <?= $quizRow['done'] ? 'ok' : 'danger'; ?>">
                                                                                <?= chemnama_icon($quizRow['done'] ? 'check' : 'x', $quizRow['done'] ? '#0f9d58' : '#ef4444'); ?>
                                                                                <?= $quizRow['done'] ? 'Sudah Mengerjakan' : 'Belum'; ?>
                                                                            </span>
                                                                        </td>
                                                                        <td>Poin <?= chemnama_e((string) $quizRow['points']); ?>/<?= chemnama_e((string) $quizRow['max_points']); ?> · Benar <?= chemnama_e((string) $quizRow['correct']); ?>/<?= chemnama_e((string) $quizRow['total']); ?></td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </section>

                                                

                                                <section class="data-siswa-subtable-card">
                                                    <h4>Detail Essay</h4>
                                                    <table class="data-siswa-mini-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Tugas</th>
                                                                <th>Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (count($student['essay_rows']) === 0): ?>
                                                                <tr>
                                                                    <td colspan="2" class="data-siswa-mini-empty">Belum ada tugas essay yang dipublikasikan.</td>
                                                                </tr>
                                                            <?php else: ?>
                                                                <?php foreach ($student['essay_rows'] as $essayRow): ?>
                                                                    <tr>
                                                                        <td><?= chemnama_e((string) $essayRow['label']); ?></td>
                                                                        <td>
                                                                            <span class="data-siswa-task-pill <?= $essayRow['done'] ? 'ok' : 'danger'; ?>">
                                                                                <?= chemnama_icon($essayRow['done'] ? 'check' : 'x', $essayRow['done'] ? '#0f9d58' : '#ef4444'); ?>
                                                                                <?= $essayRow['done'] ? 'Dikerjakan' : 'Belum'; ?>
                                                                            </span>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </section>

                                                <section class="data-siswa-subtable-card">
                                                    <h4>Detail PR / Homework</h4>
                                                    <table class="data-siswa-mini-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Tugas</th>
                                                                <th>Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (count($student['homework_rows']) === 0): ?>
                                                                <tr>
                                                                    <td colspan="2" class="data-siswa-mini-empty">Belum ada PR yang dipublikasikan.</td>
                                                                </tr>
                                                            <?php else: ?>
                                                                <?php foreach ($student['homework_rows'] as $homeworkRow): ?>
                                                                    <tr>
                                                                        <td><?= chemnama_e((string) $homeworkRow['label']); ?></td>
                                                                        <td>
                                                                            <span class="data-siswa-task-pill <?= $homeworkRow['done'] ? 'ok' : 'danger'; ?>">
                                                                                <?= chemnama_icon($homeworkRow['done'] ? 'check' : 'x', $homeworkRow['done'] ? '#0f9d58' : '#ef4444'); ?>
                                                                                <?= $homeworkRow['done'] ? 'Dikumpulkan' : 'Belum'; ?>
                                                                            </span>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </section>

                                                <section class="data-siswa-subtable-card">
                                                    <h4>Detail Games (Per Modul)</h4>
                                                    <table class="data-siswa-mini-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Game</th>
                                                                <th>Modul</th>
                                                                <th>Status</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php if (count($student['game_module_rows']) === 0): ?>
                                                                <tr>
                                                                    <td colspan="4" class="data-siswa-mini-empty">Belum ada game yang dipublikasikan.</td>
                                                                </tr>
                                                            <?php else: ?>
                                                                <?php foreach ($student['game_module_rows'] as $gameRow): ?>
                                                                    <tr>
                                                                        <td><?= chemnama_e((string) $gameRow['game_label']); ?></td>
                                                                        <td><?= chemnama_e((string) $gameRow['module_label']); ?></td>
                                                                        <td>
                                                                            <span class="data-siswa-task-pill <?= $gameRow['done'] ? 'ok' : 'danger'; ?>">
                                                                                <?= chemnama_icon($gameRow['done'] ? 'check' : 'x', $gameRow['done'] ? '#0f9d58' : '#ef4444'); ?>
                                                                                <?= $gameRow['done'] ? 'Selesai' : 'Belum'; ?>
                                                                            </span>
                                                                        </td>
                                                                    </tr>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </tbody>
                                                    </table>
                                                </section>

                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>
</main>

<script src="assets/js/app.js?v=20260407"></script>
<script>
    (function () {
        const searchInput = document.querySelector('[data-data-siswa-search]');
        const rows = document.querySelectorAll('[data-data-siswa-row]');
        const emptyRow = document.querySelector('[data-data-siswa-empty]');
        const detailButtons = document.querySelectorAll('[data-detail-toggle]');

        if (!searchInput || !rows.length) {
            return;
        }

        function setDetailState(detailId, expanded) {
            const detailRow = document.querySelector('[data-detail-for="' + detailId + '"]');
            const detailButton = document.querySelector('[data-detail-toggle="' + detailId + '"]');

            if (detailRow) {
                detailRow.hidden = !expanded;
            }

            if (detailButton) {
                detailButton.classList.toggle('is-active', expanded);
                detailButton.setAttribute('aria-expanded', expanded ? 'true' : 'false');
                detailButton.textContent = expanded ? 'Tutup Detail' : 'Lihat Detail';
            }
        }

        detailButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const detailId = button.dataset.detailToggle || '';
                const detailRow = document.querySelector('[data-detail-for="' + detailId + '"]');
                const isOpen = detailRow ? detailRow.hidden : true;
                setDetailState(detailId, isOpen);
            });
        });

        function applyFilter() {
            const term = (searchInput.value || '').trim().toLowerCase();
            let visibleCount = 0;

            rows.forEach((row) => {
                const name = row.dataset.name || '';
                const matches = term === '' || name.includes(term);
                row.style.display = matches ? '' : 'none';
                const detailId = row.dataset.detailTarget || '';
                const detailRow = detailId ? document.querySelector('[data-detail-for="' + detailId + '"]') : null;
                if (detailRow) {
                    detailRow.style.display = matches ? '' : 'none';
                    if (!matches) {
                        detailRow.hidden = true;
                        setDetailState(detailId, false);
                    }
                }
                if (matches) {
                    visibleCount += 1;
                }
            });

            if (emptyRow) {
                emptyRow.style.display = visibleCount === 0 ? '' : 'none';
            }
        }

        searchInput.addEventListener('input', applyFilter);
        applyFilter();
    })();
</script>
</body>
</html>

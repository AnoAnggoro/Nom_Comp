<?php
require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth();

$user = chemnama_current_user();
$role = $user['role'] ?? 'siswa';
$userId = (int) ($user['id'] ?? 0);
$isDemoUser = chemnama_is_demo_user($user);

$moduleCount = (int) $pdo->query('SELECT COUNT(*) FROM modules')->fetchColumn();
$studentCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'siswa'")->fetchColumn();
$teacherMaterialCount = 0;

$kelas = 'X IPA 1';
$studentKelas = 'X IPA 1';
$questionCount = 0;
$pendingCount = 0;
$avgClassScore = 0;
$studentProgress = [
    'material_count' => 0,
    'unread_material_count' => 0,
    'quiz_count' => 0,
    'average_score' => 0,
    'status_label' => 'Aktif',
];
$guruNotificationCount = 0;

if ($role === 'guru') {
    $kelas = chemnama_get_guru_active_class($pdo, $userId);
    $guruNotification = chemnama_guru_notification_summary($pdo, $userId, $kelas);
    $guruNotificationCount = (int) ($guruNotification['total'] ?? 0);

    $studentCountStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM user_profiles up
         JOIN users u ON u.id = up.user_id
         WHERE up.kelas = :kelas AND u.role = "siswa"'
    );
    $studentCountStmt->execute(['kelas' => $kelas]);
    $studentCount = (int) $studentCountStmt->fetchColumn();

    $teacherMaterialCountStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT m.id)
         FROM materials m
         JOIN users u ON u.id = m.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         WHERE m.created_by = :created_by
           AND u.role = "guru"
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas, CHAR(44))
           ) > 0'
    );
    $teacherMaterialCountStmt->execute([
        'created_by' => $userId,
        'kelas' => $kelas,
    ]);
    $teacherMaterialCount = (int) $teacherMaterialCountStmt->fetchColumn();

    $questionCountStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT q.id)
         FROM questions q
         JOIN quiz_sets qs ON qs.id = q.quiz_set_id
         JOIN users u ON u.id = qs.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         WHERE qs.created_by = :created_by
           AND q.is_published = 1
           AND qs.is_published = 1
           AND u.role = "guru"
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas, CHAR(44))
           ) > 0'
    );
    $questionCountStmt->execute([
        'created_by' => $userId,
        'kelas' => $kelas,
    ]);
    $questionCount = (int) $questionCountStmt->fetchColumn();

    $pendingStmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*)
             FROM homework_submissions hs
             JOIN homework_tasks ht ON hs.task_id = ht.id
             JOIN user_profiles up ON up.user_id = hs.student_id
                         WHERE ht.created_by = :teacher_id_1
                             AND ht.is_published = 1
                             AND hs.is_checked = 0
                             AND up.kelas = :kelas_1
                             AND (ht.class_name = :kelas_task_1 OR (ht.class_name IS NULL AND up.kelas = :kelas_fallback_1)))
            +
            (SELECT COUNT(*)
             FROM essay_answers ea
             JOIN essay_tasks et ON ea.task_id = et.id
             JOIN user_profiles up ON up.user_id = ea.student_id
                         WHERE et.created_by = :teacher_id_2
                             AND et.is_published = 1
                             AND ea.graded_at IS NULL
                             AND up.kelas = :kelas_2
                             AND (et.class_name = :kelas_task_2 OR (et.class_name IS NULL AND up.kelas = :kelas_fallback_2)))
            AS total_pending'
    );
    $pendingStmt->execute([
        'teacher_id_1' => $userId,
        'kelas_1' => $kelas,
                'kelas_task_1' => $kelas,
                'kelas_fallback_1' => $kelas,
        'teacher_id_2' => $userId,
        'kelas_2' => $kelas,
                'kelas_task_2' => $kelas,
                'kelas_fallback_2' => $kelas,
    ]);
    $pendingCount = (int) $pendingStmt->fetchColumn();
} else {
    $studentClassStmt = $pdo->prepare('SELECT kelas FROM user_profiles WHERE user_id = :user_id LIMIT 1');
    $studentClassStmt->execute(['user_id' => $userId]);
    $studentKelas = (string) ($studentClassStmt->fetchColumn() ?: 'X IPA 1');

    $classCountStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM user_profiles up
         JOIN users u ON u.id = up.user_id
         WHERE up.kelas = :kelas AND u.role = "siswa"'
    );
    $classCountStmt->execute(['kelas' => $studentKelas]);
    $studentCount = (int) $classCountStmt->fetchColumn();

    $studentProgress = array_merge($studentProgress, chemnama_student_realtime_progress($pdo, $userId, $studentKelas));
}

$studentGameStats = chemnama_get_student_game_completion_stats($pdo, $userId);

$modules = $pdo->query('SELECT * FROM modules ORDER BY sort_order, id')->fetchAll();
$tips = $pdo->query('SELECT * FROM knowledge_tips ORDER BY sort_order, id')->fetchAll();

$studentMaterialsStmt = $pdo->prepare(
        'SELECT m.title, m.description, m.type, m.youtube_url, m.file_path, mo.badge AS module_badge, u.name AS teacher_name,
                CASE WHEN mr_student.id IS NULL THEN 0 ELSE 1 END AS is_read
         FROM materials m
         JOIN modules mo ON mo.id = m.module_id
         JOIN users u ON u.id = m.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         LEFT JOIN material_reads mr_student
            ON mr_student.material_id = m.id
           AND mr_student.student_id = :student_id
         WHERE m.is_published = 1
             AND u.role = "guru"
               AND INSTR(
                   CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
                   CONCAT(CHAR(44), :kelas, CHAR(44))
               ) > 0
         ORDER BY is_read ASC, m.created_at DESC
         LIMIT 6'
);
$studentMaterialsStmt->execute([
    'kelas' => $role === 'guru' ? $kelas : $studentKelas,
    'student_id' => $userId,
]);
$studentMaterials = $studentMaterialsStmt->fetchAll();

$studentQuizModulesStmt = $pdo->prepare(
        'SELECT qs.id, qs.title, qs.description, qs.module_id, qs.is_published,
                        m.badge, m.title AS module_title, COUNT(q.id) AS total_questions
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
         GROUP BY qs.id, qs.title, qs.description, qs.module_id, qs.is_published, m.badge, m.title, m.sort_order, qs.sort_order
         HAVING total_questions > 0
         ORDER BY m.sort_order, qs.sort_order, qs.id'
);
$studentQuizModulesStmt->execute(['kelas' => $role === 'guru' ? $kelas : $studentKelas]);
$studentQuizModules = $studentQuizModulesStmt->fetchAll();

$studentQuizProgressStmt = $pdo->prepare(
    'SELECT qs.module_id,
            qar.quiz_set_id,
            COUNT(DISTINCT qaa.question_id) AS answered_questions,
            SUM(CASE WHEN qaa.is_correct = 1 THEN 1 ELSE 0 END) AS correct_answers
     FROM quiz_attempt_runs qar
     JOIN quiz_sets qs ON qs.id = qar.quiz_set_id
     JOIN quiz_attempt_answers qaa ON qaa.attempt_run_id = qar.id
     WHERE qar.student_id = :student_id AND qs.is_published = 1
     GROUP BY qs.module_id, qar.quiz_set_id'
);
$studentQuizProgressStmt->execute(['student_id' => $userId]);
$studentQuizProgressRows = $studentQuizProgressStmt->fetchAll();
$studentQuizProgressMap = [];
foreach ($studentQuizProgressRows as $row) {
    $quizSetId = (int) $row['quiz_set_id'];
    $studentQuizProgressMap[$quizSetId] = [
        'answered_questions' => (int) $row['answered_questions'],
        'correct_answers' => (int) $row['correct_answers'],
    ];
}

$studentQuizAttemptSummaryStmt = $pdo->prepare(
        'SELECT qar.quiz_set_id,
                        COUNT(*) AS attempt_count,
                        (
                                SELECT qar2.total_points
                                FROM quiz_attempt_runs qar2
                                WHERE qar2.student_id = :student_id_last_1
                                    AND qar2.quiz_set_id = qar.quiz_set_id
                                    AND qar2.submitted_at IS NOT NULL
                                ORDER BY qar2.submitted_at DESC, qar2.id DESC
                                LIMIT 1
                        ) AS last_points,
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
         WHERE qar.student_id = :student_id
             AND qar.submitted_at IS NOT NULL
             AND qs.is_published = 1
         GROUP BY qar.quiz_set_id'
);
$studentQuizAttemptSummaryStmt->execute([
        'student_id' => $userId,
        'student_id_last_1' => $userId,
        'student_id_last_2' => $userId,
        'student_id_last_3' => $userId,
]);
$studentQuizAttemptSummaryMap = [];
foreach ($studentQuizAttemptSummaryStmt->fetchAll() as $row) {
        $quizSetId = (int) $row['quiz_set_id'];
        $studentQuizAttemptSummaryMap[$quizSetId] = [
                'attempt_count' => (int) $row['attempt_count'],
                'last_points' => isset($row['last_points']) ? (int) $row['last_points'] : 0,
                'last_correct_count' => isset($row['last_correct_count']) ? (int) $row['last_correct_count'] : 0,
                'last_total_questions' => isset($row['last_total_questions']) ? (int) $row['last_total_questions'] : 0,
        ];
}

$guruStats = [
    ['value' => $studentCount, 'label' => 'Total Siswa', 'icon' => 'users', 'accent' => '#0f9d58'],
    ['value' => $teacherMaterialCount, 'label' => 'Materi', 'icon' => 'file', 'accent' => '#d97706'],
    ['value' => $questionCount, 'label' => 'Soal Bank', 'icon' => 'stack', 'accent' => '#2563eb'],
    ['value' => $pendingCount, 'label' => 'Perlu Dicek', 'icon' => 'clock', 'accent' => '#ec4899'],
];

$moduleScores = $pdo->query('SELECT * FROM modules ORDER BY sort_order, id')->fetchAll();
$moduleColors = ['#10986a', '#df7d00', '#3266d7', '#d72476', '#1c988e', '#7a45e8'];
$moduleScore = [];
$moduleParticipants = [];
$topStudents = [];

if ($role === 'guru') {
    $classStudentsStmt = $pdo->prepare(
        'SELECT u.id, u.name
         FROM users u
         JOIN user_profiles up ON up.user_id = u.id
         WHERE u.role = "siswa" AND up.kelas = :kelas
         ORDER BY u.name ASC'
    );
    $classStudentsStmt->execute(['kelas' => $kelas]);
    $classStudents = $classStudentsStmt->fetchAll();

    $classStudentIds = [];
    $classStudentMap = [];
    foreach ($classStudents as $studentRow) {
        $studentId = (int) $studentRow['id'];
        $classStudentIds[] = $studentId;
        $classStudentMap[$studentId] = [
            'id' => $studentId,
            'name' => (string) $studentRow['name'],
            'initial' => substr((string) $studentRow['name'], 0, 1),
        ];
    }

    $quizTotalsStmt = $pdo->prepare(
        'SELECT qs.id AS quiz_set_id,
                qs.module_id,
                COALESCE(SUM(q.points), 0) AS total_points
         FROM quiz_sets qs
         JOIN users u ON u.id = qs.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         LEFT JOIN questions q ON q.quiz_set_id = qs.id AND q.is_published = 1
         WHERE qs.is_published = 1
           AND qs.created_by = :teacher_id
           AND u.role = "guru"
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas, CHAR(44))
           ) > 0
         GROUP BY qs.id, qs.module_id'
    );
    $quizTotalsStmt->execute([
        'teacher_id' => $userId,
        'kelas' => $kelas,
    ]);
    $quizTotalMap = [];
    $moduleQuizTotals = [];
    foreach ($quizTotalsStmt->fetchAll() as $row) {
        $quizSetId = (int) $row['quiz_set_id'];
        $moduleId = (int) $row['module_id'];
        $totalPoints = (int) $row['total_points'];
        $quizTotalMap[$quizSetId] = $totalPoints;
        $moduleQuizTotals[$moduleId] = ($moduleQuizTotals[$moduleId] ?? 0) + $totalPoints;
    }
    $publishedQuizSetCount = count($quizTotalMap);

    $quizBestStmt = $pdo->prepare(
        'SELECT best_run.student_id,
                qs.module_id,
                best_run.quiz_set_id,
                best_run.points AS earned_points,
                COALESCE(qtot.total_points, 0) AS total_points
         FROM (
             SELECT quiz_set_id, student_id, MAX(total_points) AS points
             FROM quiz_attempt_runs
             WHERE submitted_at IS NOT NULL
             GROUP BY quiz_set_id, student_id
         ) best_run
         JOIN quiz_sets qs ON qs.id = best_run.quiz_set_id
         JOIN users u ON u.id = qs.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         LEFT JOIN (
             SELECT qs2.id AS quiz_set_id, COALESCE(SUM(q2.points), 0) AS total_points
             FROM quiz_sets qs2
             LEFT JOIN questions q2 ON q2.quiz_set_id = qs2.id AND q2.is_published = 1
             WHERE qs2.is_published = 1
             GROUP BY qs2.id
         ) qtot ON qtot.quiz_set_id = qs.id
         WHERE qs.is_published = 1
           AND qs.created_by = :teacher_id
           AND u.role = "guru"
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas, CHAR(44))
           ) > 0'
    );
    $quizBestStmt->execute([
        'teacher_id' => $userId,
        'kelas' => $kelas,
    ]);
    $studentQuizModuleMap = [];
    $studentQuizTotals = [];
    foreach ($quizBestStmt->fetchAll() as $row) {
        $studentId = (int) $row['student_id'];
        $moduleId = (int) $row['module_id'];
        $quizSetId = (int) $row['quiz_set_id'];
        $earnedPoints = (int) $row['earned_points'];
        $totalPoints = (int) ($row['total_points'] ?? 0);

        if ($totalPoints <= 0) {
            continue;
        }

        $studentQuizModuleMap[$studentId][$moduleId]['earned'] = ($studentQuizModuleMap[$studentId][$moduleId]['earned'] ?? 0) + $earnedPoints;
        $studentQuizModuleMap[$studentId][$moduleId]['total'] = ($studentQuizModuleMap[$studentId][$moduleId]['total'] ?? 0) + $totalPoints;
        $studentQuizTotals[$studentId]['earned'] = ($studentQuizTotals[$studentId]['earned'] ?? 0) + $earnedPoints;
        $studentQuizTotals[$studentId]['total'] = ($studentQuizTotals[$studentId]['total'] ?? 0) + $totalPoints;
        $studentQuizTotals[$studentId]['modules'][$moduleId] = true;
    }

    $essayTasksStmt = $pdo->prepare(
        'SELECT t.id, t.module_id
         FROM essay_tasks t
         JOIN users u ON u.id = t.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         WHERE t.is_published = 1
           AND t.created_by = :teacher_id
           AND u.role = "guru"
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas, CHAR(44))
           ) > 0
           AND (t.class_name = :kelas_task OR t.class_name IS NULL)
         ORDER BY t.created_at DESC, t.id DESC'
    );
    $essayTasksStmt->execute([
        'teacher_id' => $userId,
        'kelas' => $kelas,
        'kelas_task' => $kelas,
    ]);
    $essayTasks = $essayTasksStmt->fetchAll();
    $essayTaskTotals = [];
    $moduleEssayTotals = [];
    foreach ($essayTasks as $task) {
        $taskId = (int) $task['id'];
        $moduleId = (int) $task['module_id'];
        $essayTaskTotals[$taskId] = $moduleId;
        $moduleEssayTotals[$moduleId] = ($moduleEssayTotals[$moduleId] ?? 0) + 1;
    }

    $essayScoresStmt = $pdo->prepare(
        'SELECT a.student_id, t.module_id, a.task_id, COALESCE(a.score, 0) AS score
         FROM essay_answers a
         JOIN essay_tasks t ON t.id = a.task_id
         JOIN users u ON u.id = t.created_by
         JOIN user_profiles up_student ON up_student.user_id = a.student_id
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         WHERE t.is_published = 1
           AND t.created_by = :teacher_id
           AND u.role = "guru"
           AND up_student.kelas = :kelas_student
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas_teacher, CHAR(44))
           ) > 0
           AND (t.class_name = :kelas_task OR t.class_name IS NULL)'
    );
    $essayScoresStmt->execute([
        'teacher_id' => $userId,
        'kelas_student' => $kelas,
        'kelas_teacher' => $kelas,
        'kelas_task' => $kelas,
    ]);
    $studentEssayModuleMap = [];
    foreach ($essayScoresStmt->fetchAll() as $row) {
        $studentId = (int) $row['student_id'];
        $moduleId = (int) $row['module_id'];
        $score = (float) $row['score'];
        $studentEssayModuleMap[$studentId][$moduleId]['earned'] = ($studentEssayModuleMap[$studentId][$moduleId]['earned'] ?? 0) + $score;
        $studentEssayModuleMap[$studentId][$moduleId]['count'] = ($studentEssayModuleMap[$studentId][$moduleId]['count'] ?? 0) + 1;
    }

    $homeworkTasksStmt = $pdo->prepare(
        'SELECT t.id, t.module_id
         FROM homework_tasks t
         JOIN users u ON u.id = t.created_by
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         WHERE t.is_published = 1
           AND t.created_by = :teacher_id
           AND u.role = "guru"
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas, CHAR(44))
           ) > 0
           AND (t.class_name = :kelas_task OR t.class_name IS NULL)
         ORDER BY t.created_at DESC, t.id DESC'
    );
    $homeworkTasksStmt->execute([
        'teacher_id' => $userId,
        'kelas' => $kelas,
        'kelas_task' => $kelas,
    ]);
    $homeworkTasks = $homeworkTasksStmt->fetchAll();
    $moduleHomeworkTotals = [];
    foreach ($homeworkTasks as $task) {
        $moduleId = (int) $task['module_id'];
        $moduleHomeworkTotals[$moduleId] = ($moduleHomeworkTotals[$moduleId] ?? 0) + 1;
    }

    $publishedGameCountStmt = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM chem_match_games g WHERE g.created_by = :teacher_id_match AND g.is_published = 1 AND (g.class_name = :kelas_match OR g.class_name IS NULL))
            +
            (SELECT COUNT(*) FROM word_search_games g WHERE g.created_by = :teacher_id_word AND g.is_published = 1 AND (g.class_name = :kelas_word OR g.class_name IS NULL))
            +
            (SELECT COUNT(*) FROM simulations g WHERE g.created_by = :teacher_id_sim AND g.is_published = 1 AND (g.class_name = :kelas_sim OR g.class_name IS NULL)) AS total_games'
    );
    $publishedGameCountStmt->execute([
        'teacher_id_match' => $userId,
        'kelas_match' => $kelas,
        'teacher_id_word' => $userId,
        'kelas_word' => $kelas,
        'teacher_id_sim' => $userId,
        'kelas_sim' => $kelas,
    ]);
    $publishedGameCount = (int) $publishedGameCountStmt->fetchColumn();

    $homeworkStatusStmt = $pdo->prepare(
        'SELECT s.student_id, t.module_id, s.task_id, s.is_checked
         FROM homework_submissions s
         JOIN homework_tasks t ON t.id = s.task_id
         JOIN users u ON u.id = t.created_by
         JOIN user_profiles up_student ON up_student.user_id = s.student_id
         LEFT JOIN user_profiles up_teacher ON up_teacher.user_id = u.id
         WHERE t.is_published = 1
           AND t.created_by = :teacher_id
           AND u.role = "guru"
           AND up_student.kelas = :kelas_student
           AND INSTR(
               CONCAT(CHAR(44), REPLACE(up_teacher.kelas, CONCAT(CHAR(44), CHAR(32)), CHAR(44)), CHAR(44)),
               CONCAT(CHAR(44), :kelas_teacher, CHAR(44))
           ) > 0
           AND (t.class_name = :kelas_task OR t.class_name IS NULL)'
    );
    $homeworkStatusStmt->execute([
        'teacher_id' => $userId,
        'kelas_student' => $kelas,
        'kelas_teacher' => $kelas,
        'kelas_task' => $kelas,
    ]);
    $studentHomeworkModuleMap = [];
    foreach ($homeworkStatusStmt->fetchAll() as $row) {
        $studentId = (int) $row['student_id'];
        $moduleId = (int) $row['module_id'];
        $studentHomeworkModuleMap[$studentId][$moduleId]['checked'] = ($studentHomeworkModuleMap[$studentId][$moduleId]['checked'] ?? 0) + ((int) $row['is_checked'] > 0 ? 1 : 0);
        $studentHomeworkModuleMap[$studentId][$moduleId]['count'] = ($studentHomeworkModuleMap[$studentId][$moduleId]['count'] ?? 0) + 1;
    }

    $studentOverallScores = [];
    $moduleStudentScores = [];

    foreach ($classStudentIds as $studentId) {
        $overallParts = [];

        if (!empty($studentQuizTotals[$studentId]['total'] ?? 0)) {
            $quizPercent = round((($studentQuizTotals[$studentId]['earned'] ?? 0) / max(1, (int) $studentQuizTotals[$studentId]['total'])) * 100, 2);
            $overallParts[] = $quizPercent;
        }

        if (!empty($moduleEssayTotals)) {
            $essayEarned = 0.0;
            $essayTaskCount = 0;
            foreach ($moduleEssayTotals as $moduleId => $taskCount) {
                $essayModuleEarned = (float) ($studentEssayModuleMap[$studentId][$moduleId]['earned'] ?? 0);
                $essayModuleCount = (int) ($studentEssayModuleMap[$studentId][$moduleId]['count'] ?? 0);
                if ($taskCount > 0) {
                    $essayPercent = round(($essayModuleEarned / ($taskCount * 100)) * 100, 2);
                    $moduleStudentScores[$moduleId][$studentId]['essay'] = $essayPercent;
                    $essayEarned += $essayModuleEarned;
                    $essayTaskCount += $taskCount;
                }
            }
            if ($essayTaskCount > 0) {
                $overallParts[] = round(($essayEarned / ($essayTaskCount * 100)) * 100, 2);
            }
        }

        if (!empty($moduleHomeworkTotals)) {
            $homeworkCheckedTotal = 0;
            $homeworkTaskCount = 0;
            foreach ($moduleHomeworkTotals as $moduleId => $taskCount) {
                $homeworkModuleChecked = (int) ($studentHomeworkModuleMap[$studentId][$moduleId]['checked'] ?? 0);
                $homeworkModuleCount = (int) ($studentHomeworkModuleMap[$studentId][$moduleId]['count'] ?? 0);
                if ($taskCount > 0) {
                    $homeworkPercent = round(($homeworkModuleChecked / $taskCount) * 100, 2);
                    $moduleStudentScores[$moduleId][$studentId]['homework'] = $homeworkPercent;
                    $homeworkCheckedTotal += $homeworkModuleChecked;
                    $homeworkTaskCount += $taskCount;
                }
            }
            if ($homeworkTaskCount > 0) {
                $overallParts[] = round(($homeworkCheckedTotal / $homeworkTaskCount) * 100, 2);
            }
        }

        $studentOverallScores[$studentId] = !empty($overallParts) ? round(array_sum($overallParts) / count($overallParts), 2) : 0.0;
    }

    foreach ($moduleScores as $module) {
        $moduleId = (int) $module['id'];
        $perStudentScores = [];

        foreach ($classStudentIds as $studentId) {
            $parts = [];
            $hasActivity = false;

            if (($moduleQuizTotals[$moduleId] ?? 0) > 0) {
                $quizModuleEarned = (float) ($studentQuizModuleMap[$studentId][$moduleId]['earned'] ?? 0);
                $quizModuleTotal = (float) ($studentQuizModuleMap[$studentId][$moduleId]['total'] ?? 0);
                if ($quizModuleTotal > 0) {
                    $parts[] = round(($quizModuleEarned / $quizModuleTotal) * 100, 2);
                    $hasActivity = true;
                }
            }

            if (($moduleEssayTotals[$moduleId] ?? 0) > 0) {
                $essayModuleEarned = (float) ($studentEssayModuleMap[$studentId][$moduleId]['earned'] ?? 0);
                $parts[] = round($essayModuleEarned / max(1, (int) $moduleEssayTotals[$moduleId]), 2);
                $hasActivity = true;
            }

            if (($moduleHomeworkTotals[$moduleId] ?? 0) > 0) {
                $homeworkModuleChecked = (int) ($studentHomeworkModuleMap[$studentId][$moduleId]['checked'] ?? 0);
                $parts[] = round(($homeworkModuleChecked / max(1, (int) $moduleHomeworkTotals[$moduleId])) * 100, 2);
                $hasActivity = true;
            }

            if (!empty($parts)) {
                $studentModuleScore = round(array_sum($parts) / count($parts), 2);
                $perStudentScores[] = $studentModuleScore;
                if ($hasActivity) {
                    $moduleParticipants[$moduleId][$studentId] = true;
                }
            }
        }

        $moduleScore[$moduleId] = !empty($perStudentScores) ? (int) round(array_sum($perStudentScores) / count($perStudentScores)) : 0;
    }

    foreach ($classStudentMap as $studentId => $studentData) {
        $topStudents[] = [
            'name' => $studentData['name'],
            'score' => (int) round($studentOverallScores[$studentId] ?? 0),
            'initial' => $studentData['initial'],
        ];
    }

    usort($topStudents, static function (array $left, array $right): int {
        if ($left['score'] === $right['score']) {
            return strcmp((string) $left['name'], (string) $right['name']);
        }

        return $right['score'] <=> $left['score'];
    });
    $topStudents = array_slice($topStudents, 0, 5);

    $moduleScoreOrdered = [];
    $moduleParticipantsOrdered = [];
    foreach ($moduleScores as $module) {
        $moduleId = (int) $module['id'];
        $moduleScoreOrdered[] = max(0, min(100, (int) ($moduleScore[$moduleId] ?? 0)));
        $moduleParticipantsOrdered[] = isset($moduleParticipants[$moduleId]) ? count($moduleParticipants[$moduleId]) : 0;
    }
    $moduleScore = $moduleScoreOrdered;
    $moduleParticipants = $moduleParticipantsOrdered;

    if (!empty($moduleScore)) {
        $avgClassScore = (int) round(array_sum($moduleScore) / count($moduleScore));
    }
} else {
    foreach ($moduleScores as $index => $module) {
        $moduleScore[] = [78, 72, 69, 81, 75, 70][$index] ?? 70;
        $moduleParticipants[] = 0;
    }

    $topStudents = [
        ['name' => 'Aisyah Putri Ramadhani', 'score' => 91, 'initial' => 'A'],
        ['name' => 'Dewi Lestari', 'score' => 82, 'initial' => 'D'],
        ['name' => 'Siswa ChemNama', 'score' => 76, 'initial' => 'S'],
        ['name' => 'Maya Sari', 'score' => 68, 'initial' => 'M'],
    ];
}

$forumPosts = [];
if ($role === 'guru') {
    $forumPostsStmt = $pdo->prepare(
        'SELECT ft.title, u.name,
                (SELECT COUNT(*) FROM forum_replies WHERE thread_id = ft.id) as reply_count
         FROM forum_threads ft
         JOIN users u ON u.id = ft.author_id
         WHERE ft.class_name = :kelas
         ORDER BY ft.created_at DESC
         LIMIT 2'
    );
    $forumPostsStmt->execute(['kelas' => $kelas]);
    foreach ($forumPostsStmt->fetchAll() as $post) {
        $forumPosts[] = [
            'initial' => substr((string) $post['name'], 0, 1),
            'title' => $post['title'],
            'meta' => $post['name'] . ' · ' . (int) $post['reply_count'] . ' balasan',
            'badge' => '',
        ];
    }
} else {
    $forumPostsStmt = $pdo->prepare(
        'SELECT t.title, u.name,
                (SELECT COUNT(*) FROM forum_replies r WHERE r.thread_id = t.id) AS reply_count
         FROM forum_threads t
         JOIN users u ON u.id = t.author_id
         WHERE t.class_name = :kelas
         ORDER BY t.updated_at DESC, t.id DESC
         LIMIT 2'
    );
    $forumPostsStmt->execute(['kelas' => $studentKelas]);
    foreach ($forumPostsStmt->fetchAll() as $post) {
        $forumPosts[] = [
            'initial' => substr((string) $post['name'], 0, 1),
            'title' => $post['title'],
            'meta' => $post['name'] . ' · ' . (int) $post['reply_count'] . ' balasan',
            'badge' => '',
        ];
    }
}

$homeworkData = ['title' => 'Tidak ada PR', 'due_at' => date('Y-m-d'), 'total' => 0, 'checked' => 0];
$allHomeworkData = [];
if ($role === 'guru') {
    $allHomeworkStmt = $pdo->prepare(
        'SELECT ht.id, ht.title, ht.due_at, ht.is_published,
                COUNT(DISTINCT CASE WHEN up.kelas = :kelas THEN hs.id END) AS total_submission
         FROM homework_tasks ht
         LEFT JOIN homework_submissions hs ON hs.task_id = ht.id
         LEFT JOIN user_profiles up ON up.user_id = hs.student_id
         WHERE ht.created_by = :teacher_id
           AND ht.class_name = :kelas_task
         GROUP BY ht.id, ht.title, ht.due_at, ht.is_published
         ORDER BY ht.due_at ASC'
    );
    $allHomeworkStmt->execute([
        'teacher_id' => $userId,
        'kelas' => $kelas,
        'kelas_task' => $kelas,
    ]);
    $allHomeworkTasks = $allHomeworkStmt->fetchAll();

    $totalStudentsStmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM user_profiles up
         JOIN users u ON u.id = up.user_id
         WHERE up.kelas = :kelas AND u.role = "siswa"'
    );
    $totalStudentsStmt->execute(['kelas' => $kelas]);
    $totalStudentsInClass = (int) $totalStudentsStmt->fetchColumn();

    if (!empty($allHomeworkTasks)) {
        foreach ($allHomeworkTasks as $hw) {
            $allHomeworkData[] = [
                'title' => $hw['title'],
                'due_at' => $hw['due_at'],
                'total' => $totalStudentsInClass,
                'checked' => (int) $hw['total_submission'],
                'is_published' => (int) $hw['is_published'],
            ];
        }
        $homeworkData = $allHomeworkData[0];
    }
}

$guruMenus = [
    ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => true],
    ['label' => 'Kelola Materi', 'icon' => 'file', 'href' => 'guru_materi.php', 'active' => false],
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

$studentMenus = [
  ['label' => 'Dashboard', 'icon' => 'chart', 'href' => 'dashboard.php', 'active' => true],
    ['label' => 'Materi', 'icon' => 'file', 'href' => 'siswa_materi.php', 'active' => false],
    // ['label' => 'Games', 'icon' => 'beaker', 'href' => 'siswa_games.php', 'active' => false],
    // ['label' => 'Kuis PG', 'icon' => 'stack', 'href' => 'siswa_quiz.php', 'active' => false],
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
    <title>Dashboard - Nom Comp</title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="dashboard-page">
<?php if ($role === 'guru'): ?>
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
            <div class="guru-class-chip"><?= chemnama_e($kelas); ?></div>
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
                <a class="guru-menu-item" href="guru_pilih_kelas.php?redirect_to=dashboard.php">
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

    <section class="guru-content">
        <div class="guru-headline-row">
            <div>
                <h1>Dashboard <span><?= chemnama_e($kelas); ?></span></h1>
                <p>Pantau progress pembelajaran.</p>
            </div>
            <div class="guru-head-actions">
                <?php if ($isDemoUser): ?>
                    <span class="head-pill">Demo</span>
                <?php endif; ?>
                <span class="head-pill class"><?= chemnama_e($kelas); ?></span>
            </div>
        </div>

        <div class="guru-stats-row">
            <?php foreach ($guruStats as $stat): ?>
                <article class="guru-stat-card">
                    <div class="guru-stat-icon" style="color: <?= chemnama_e($stat['accent']); ?>; background: <?= chemnama_e($stat['accent']); ?>16;">
                        <?= chemnama_icon($stat['icon'], $stat['accent']); ?>
                    </div>
                    <strong><?= chemnama_e((string) $stat['value']); ?></strong>
                    <p><?= chemnama_e($stat['label']); ?></p>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="guru-grid guru-grid-top">
            <article class="guru-panel chart-panel">
                <h3>Keaktifan Rata-rata per Modul</h3>
                <div class="module-chart">
                    <?php foreach ($moduleScore as $index => $score): ?>
                        <div class="chart-col">
                            <strong style="color: <?= chemnama_e($moduleColors[$index]); ?>;"><?= chemnama_e((string) $score); ?>%</strong>
                            <span class="chart-bar" style="height: <?= 90 + min(160, (int) ($score * 2)); ?>px; background: <?= chemnama_e($moduleColors[$index]); ?>;"></span>
                            <small>M<?= $index + 1; ?></small>
                            <small><?= (int) ($moduleParticipants[$index] ?? 0); ?> siswa aktif</small>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="guru-panel top-student-panel">
                <h3>Top 5 Siswa</h3>
                <div class="student-rank-list">
                    <?php if (!empty($topStudents)): ?>
                        <?php foreach ($topStudents as $idx => $student): ?>
                            <div class="rank-row">
                                <span class="rank-num <?= $idx < 3 ? 'top' : ''; ?>\"><?= $idx + 1; ?></span>
                                <span class="rank-avatar\"><?= chemnama_e($student['initial']); ?></span>
                                <span class="rank-name\"><?= chemnama_e($student['name']); ?></span>
                                <strong class="rank-score\"><?= chemnama_e((string) $student['score']); ?>%</strong>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="rank-row">
                            <span class="rank-name">Belum ada data nilai untuk kelas ini.</span>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </div>

        <div class="guru-grid guru-grid-bottom">
            <article class="guru-panel">
                <h3>Data Guru Terbaru</h3>
                <div class="panel-list compact-list">
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#0f9d58;">
                            <?= chemnama_icon('stack', '#0f9d58'); ?>
                        </div>
                        <div>
                            <strong><?= chemnama_e((string) $publishedQuizSetCount); ?> Quiz Aktif</strong>
                            <p>Quiz PG yang sudah dipublish untuk kelas ini.</p>
                        </div>
                    </div>
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#d97706;">
                            <?= chemnama_icon('flask', '#d97706'); ?>
                        </div>
                        <div>
                            <strong><?= chemnama_e((string) $publishedGameCount); ?> Games Aktif</strong>
                            <p>Tarik Garis, Cari Kata, dan Simulasi yang tersedia.</p>
                        </div>
                    </div>
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#2563eb;">
                            <?= chemnama_icon('edit', '#2563eb'); ?>
                        </div>
                        <div>
                            <strong><?= chemnama_e((string) count($essayTasks)); ?> Essay Aktif</strong>
                            <p>Tugas essay yang masih dipublish.</p>
                        </div>
                    </div>
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#ec4899;">
                            <?= chemnama_icon('task', '#ec4899'); ?>
                        </div>
                        <div>
                            <strong><?= chemnama_e((string) count($homeworkTasks)); ?> PR Aktif</strong>
                            <p>PR / homework yang perlu dikerjakan siswa.</p>
                        </div>
                    </div>
                </div>
            </article>

            <article class="guru-panel">
                <h3>Akses Cepat</h3>
                <div class="panel-list compact-list">
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#0f9d58;">
                            <?= chemnama_icon('users', '#0f9d58'); ?>
                        </div>
                        <div>
                            <strong>Kelola Data Siswa</strong>
                            <p>Lihat progres terbaru dan detail game siswa.</p>
                        </div>
                    </div>
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#d97706;">
                            <?= chemnama_icon('stack', '#d97706'); ?>
                        </div>
                        <div>
                            <strong>Quiz & Quick Quiz</strong>
                            <p>Atur soal dan lihat status publish.</p>
                        </div>
                    </div>
                    <div class="panel-item compact-item">
                        <div class="panel-icon" style="color:#2563eb;">
                            <?= chemnama_icon('magic', '#2563eb'); ?>
                        </div>
                        <div>
                            <strong>Pengaturan Games</strong>
                            <p>Kelola Tarik Garis, Cari Kata, dan Simulasi.</p>
                        </div>
                    </div>
                </div>
            </article>
        </div>
    </section>
</main>
<?php else: ?>
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
            <div class="guru-class-chip"><?= chemnama_e($studentKelas); ?></div>
        </div>

        <div class="guru-menu-block">
            <span class="guru-menu-title">MENU</span>
            <nav class="guru-menu-list">
                <?php foreach ($studentMenus as $menu): ?>
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

    <section class="guru-content student-content">
        <div class="guru-headline-row">
            <div>
                <h1>Halo, <span><?= chemnama_e($user['name']); ?></span></h1>
                <p>Lanjutkan pembelajaran tata nama senyawa.</p>
            </div>
            <div class="guru-head-actions">
                <?php if ($isDemoUser): ?>
                    <span class="head-pill">Demo</span>
                <?php endif; ?>
            </div>
        </div>

        <div class="guru-stats-row">
            <article class="guru-stat-card">
                <div class="guru-stat-icon" style="color:#0f9d58;background:#0f9d5873;">
                    <?= chemnama_icon('file', '#0f9d58'); ?> 
                </div>
                <strong><?= chemnama_e((string) $studentProgress['material_count']); ?></strong>
                <p>Materi Tersedia</p>
            </article>
            <article class="guru-stat-card">
                <div class="guru-stat-icon" style="color:#d97706;background:#d9770673;">
                    <?= chemnama_icon('edit', '#d97706'); ?>
                </div>
                <strong><?= chemnama_e((string) $studentProgress['quiz_count']); ?></strong>
                <p>Kuis Dikerjakan</p>
            </article>
            <article class="guru-stat-card">
                <div class="guru-stat-icon" style="color:#2563eb;background:#2563eb73;">
                    <?= chemnama_icon('chart', '#2563eb'); ?>
                </div>
                <strong><?= chemnama_e((string) round((float) $studentProgress['average_score'])); ?>%</strong>
                <p>Rata-rata Skor</p>
            </article>
            <article class="guru-stat-card">
                <div class="guru-stat-icon" style="color:#14b8a6;background:#14b8a673;">
                    <?= chemnama_icon('magic', '#14b8a6'); ?>
                </div>
                <strong><?= chemnama_e((string) ($studentGameStats['total_games_completed'] ?? 0)); ?></strong>
                <p>Games Diselesaikan</p>
            </article>
        </div>

        <div class="guru-grid guru-grid-top">
            <article class="guru-panel chart-panel">
                <h3>Semua Materi</h3>
                <div class="panel-list compact-list">
                    <?php foreach ($studentMaterials as $item): ?>
                        <div class="panel-item compact-item">
                            <div class="panel-icon" style="color: #0f9d58;">
                                <?= chemnama_icon('file', '#0f9d58'); ?>
                            </div>
                            <div>
                                <strong><?= chemnama_e((string) $item['title']); ?></strong>
                                <p><?= chemnama_e((string) $item['module_badge']); ?> - <?= chemnama_e((string) $item['type']); ?></p>
                               
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="guru-panel top-student-panel">
                <h3>Mulai Belajar</h3>
                <?php $startLearningMaterials = array_slice($studentMaterials, 0, 3); ?>
                <?php if (!empty($startLearningMaterials)): ?>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($startLearningMaterials as $material): ?>
                            <div class="knowledge-card compact-knowledge" style="opacity: 1; transform: none; margin: 0;">
                                <div class="knowledge-icon" style="color:#14b8a6;background:#14b8a614;">
                                    <?= chemnama_icon('file', '#14b8a6'); ?>
                                </div>
                                <div style="flex: 1;">
                                    <h4 style="margin: 0 0 4px 0; font-size: 0.95rem; line-height: 1.3;"><?= chemnama_e((string) $material['title']); ?></h4>
                                    <small style="color: rgba(255,255,255,0.6); display: block; margin-bottom: 4px;"><?= chemnama_e((string) $material['module_badge']); ?> · <?= chemnama_e((string) $material['type']); ?></small>
                                    <a href="siswa_materi.php" style="color: #14b8a6; font-size: 0.85rem; text-decoration: none; font-weight: 600;">Baca →</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="knowledge-card compact-knowledge" style="opacity: 1; transform: none;">
                        <div class="knowledge-icon" style="color:#14b8a6;background:#14b8a614;">
                            <?= chemnama_icon('file', '#14b8a6'); ?>
                        </div>
                        <div>
                            <h3>Belum ada materi</h3>
                            <p>Guru belum mengunggah materi untuk kelas Anda.</p>
                        </div>
                    </div>
                <?php endif; ?>
                <div style="margin-top:16px;">
                    <a class="btn btn-primary btn-block" href="siswa_materi.php">Lihat Semua Materi <?= chemnama_icon('rocket', '#ffffff'); ?></a>
                </div>
            </article>
        </div>

        <div class="guru-grid guru-grid-bottom">
            <article class="guru-panel">
                <h3>Forum Terbaru</h3>
                <div class="forum-list">
                    <?php if (!empty($forumPosts)): ?>
                        <?php foreach ($forumPosts as $post): ?>
                            <div class="forum-item">
                                <span class="forum-avatar"><?= chemnama_e($post['initial']); ?></span>
                                <div>
                                    <p><?= chemnama_e($post['title']); ?></p>
                                    <small><?= chemnama_e($post['meta']); ?></small>
                                </div>
                                <?php if (!empty($post['badge'])): ?>
                                    <span class="forum-badge">Baru</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="forum-item">
                            <span class="forum-avatar">-</span>
                            <div>
                                <p>Belum ada forum untuk kelas ini.</p>
                                <small>Guru belum membuat thread di kelas aktif.</small>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </article>

            <article class="guru-panel">
                <h3>Kuis PG</h3>
                <div class="panel-list compact-list">
                    <?php if (!empty($studentQuizModules)): ?>
                        <?php foreach ($studentQuizModules as $quizModule): ?>
                            <?php
                                $quizSetId = (int) $quizModule['id'];
                                $totalQuestions = (int) $quizModule['total_questions'];
                                $attemptSummary = $studentQuizAttemptSummaryMap[$quizSetId] ?? [
                                    'attempt_count' => 0,
                                    'last_points' => 0,
                                    'last_correct_count' => 0,
                                    'last_total_questions' => $totalQuestions,
                                ];
                                $quizProgress = $studentQuizProgressMap[$quizSetId] ?? ['answered_questions' => 0, 'correct_answers' => 0];
                                $hasSubmittedAttempt = (int) $attemptSummary['attempt_count'] > 0;
                                $statusLabel = $hasSubmittedAttempt ? 'Sudah Mengerjakan' : 'Belum';
                                $statusClass = $hasSubmittedAttempt ? 'is-done' : 'is-pending';
                                $summaryPoints = (int) $attemptSummary['last_points'];
                                $summaryCorrect = (int) $attemptSummary['last_correct_count'];
                                $summaryTotal = max($totalQuestions, (int) $attemptSummary['last_total_questions']);
                                $buttonLabel = $hasSubmittedAttempt ? 'Lihat Hasil' : 'Mulai Quiz';
                                $buttonHref = $hasSubmittedAttempt
                                    ? 'siswa_materi.php?quiz_set_id=' . $quizSetId . '&complete=1'
                                    : 'siswa_materi.php?quiz_set_id=' . $quizSetId;
                            ?>
                            <div class="panel-item compact-item quiz-compact-item">
                                <div class="panel-icon" style="color:#0f9d58;">
                                    <?= chemnama_icon('stack', '#0f9d58'); ?>
                                </div>
                                <div>
                                    <strong><?= chemnama_e((string) $quizModule['title']); ?></strong>
                                    <p><?= chemnama_e((string) $quizModule['badge']); ?> · <?= chemnama_e((string) $totalQuestions); ?> soal</p>
                                    <div class="quiz-compact-meta-row">
                                        <span class="quiz-compact-status <?= chemnama_e($statusClass); ?>"><?= chemnama_e($statusLabel); ?></span>
                                        <small>Poin <?= $summaryPoints; ?> · Benar <?= $summaryCorrect; ?>/<?= $summaryTotal; ?></small>
                                    </div>
                                </div>
                                <a class="quiz-start-btn" href="<?= chemnama_e($buttonHref); ?>"><?= chemnama_e($buttonLabel); ?></a>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="panel-item compact-item">
                            <div class="panel-icon" style="color:#0f9d58;">
                                <?= chemnama_icon('stack', '#0f9d58'); ?>
                            </div>
                            <div>
                                <strong>Belum ada kuis</strong>
                                <p>Guru belum mempublikasikan soal pilihan ganda.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </article>
        </div>
    </section>
</main>
<?php endif; ?>
<script src="assets/js/app.js?v=20260407"></script>
</body>
</html>

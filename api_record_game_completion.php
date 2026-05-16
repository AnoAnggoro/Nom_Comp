<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('siswa');

$user = chemnama_current_user();
$studentId = (int) ($user['id'] ?? 0);

header('Content-Type: application/json');

if ($studentId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$gameType = (string) ($input['game_type'] ?? '');
$gameId = (int) ($input['game_id'] ?? 0);
$completionTime = (int) ($input['completion_time'] ?? 0);

if ($gameType === '' || $gameId <= 0 || $completionTime <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $success = false;

    if ($gameType === 'chem_match') {
        $success = chemnama_record_chem_match_completion_time($pdo, $gameId, $studentId, $completionTime);
    } elseif ($gameType === 'word_search') {
        $success = chemnama_record_word_search_completion_time($pdo, $gameId, $studentId, $completionTime);
    } elseif ($gameType === 'simulation') {
        $success = chemnama_record_simulation_completion_time($pdo, $gameId, $studentId, $completionTime);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid game type']);
        exit;
    }

    http_response_code($success ? 200 : 500);
    echo json_encode(['success' => $success]);
} catch (Throwable $e) {
    error_log('api_record_game_completion.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

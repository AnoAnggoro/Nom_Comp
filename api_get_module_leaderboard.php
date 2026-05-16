<?php
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';

chemnama_require_auth('guru');

header('Content-Type: application/json');

$gameType = (string) ($_GET['type'] ?? '');
$moduleId = (int) ($_GET['module_id'] ?? 0);

if ($gameType === '' || $moduleId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    if ($gameType === 'chem_match') {
        $rows = chemnama_get_module_chem_match_leaderboard($pdo, $moduleId, 10);
    } elseif ($gameType === 'word_search') {
        $rows = chemnama_get_module_word_search_leaderboard($pdo, $moduleId, 10);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid game type']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'game_type' => $gameType,
        'module_id' => $moduleId,
        'leaderboard' => $rows,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('api_get_module_leaderboard.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}

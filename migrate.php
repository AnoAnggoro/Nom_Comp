<?php
require_once __DIR__ . '/config/bootstrap.php';

try {
    $pdo->exec('ALTER TABLE quick_quizzes CHANGE COLUMN material_id module_id BIGINT UNSIGNED NOT NULL');
    echo "✓ Migration successful: material_id → module_id\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

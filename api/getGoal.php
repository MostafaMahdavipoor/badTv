<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); 

require_once __DIR__ . '/../classes/Database.php'; 
require_once __DIR__ . '/../classes/BotHandler.php';

$token = $_GET['token'] ?? null;

if (!$token) {
    http_response_code(400); 
    echo json_encode(['ok' => false, 'error' => 'Token is missing.']);
    exit;
}

try {
    $db = new \Bot\Database(); 
    $goal = $db->getGoalByToken($token); 

    if ($goal) {
        echo json_encode(['ok' => true, 'result' => $goal]);
    } else {
        http_response_code(404); // Not Found
        echo json_encode(['ok' => false, 'error' => 'Goal not found.']);
    }
} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
<?php
// backend/api/admin/auth.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';

if ($password === ADMIN_PASSWORD) {
    echo json_encode(['success' => true, 'message' => 'Authenticated']);
} else {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid password']);
}
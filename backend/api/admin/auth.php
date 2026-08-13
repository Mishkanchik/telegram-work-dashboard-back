<?php
require_once __DIR__ . '/../../../functions.php';

corsHeaders();
initDB();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$password = $input['password'] ?? '';

if (verifyAdminPassword($password)) {
    jsonResponse([
        'success' => true,
        'token' => ADMIN_PASSWORD
    ]);
} else {
    jsonResponse(['success' => false, 'error' => 'Invalid password'], 401);
}
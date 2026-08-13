<?php
// backend/api/admin/stats.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';

$month = $_GET['month'] ?? null;

$stats = getAdminStats($month);

echo json_encode($stats);
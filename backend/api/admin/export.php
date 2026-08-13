<?php
// backend/api/admin/export.php
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="shifts_export.csv"');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';

$month = $_GET['month'] ?? null;

echo exportToCSV($month);
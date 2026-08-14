<?php
// api/admin/export.php
header('Content-Type: text/csv; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Content-Disposition: attachment; filename="shifts_export_' . date('Y-m-d') . '.csv"');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../functions.php';

$userId = $_GET['user_id'] ?? null;
$month = $_GET['month'] ?? null;

$data = exportShiftsToCSV($userId, $month);

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for UTF-8

fputcsv($output, ['Працівник', 'Username', 'Дата', 'Зміна', 'Початок', 'Кінець', 'Години']);

foreach ($data as $row) {
    fputcsv($output, [
        $row['user'],
        $row['username'] ?? '',
        $row['date'],
        $row['shift_type'],
        $row['start_time'],
        $row['end_time'],
        $row['total_hours']
    ]);
}
fclose($output);
exit;

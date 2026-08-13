<?php
require_once __DIR__ . '/../../../functions.php';

corsHeaders();
initDB();
requireAuth();

$userId = $_GET['user_id'] ?? null;
$startDate = $_GET['start_date'] ?? null;
$endDate = $_GET['end_date'] ?? null;

$data = exportShiftsCSV($userId, $startDate, $endDate);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="shifts_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fputcsv($output, ['Працівник', 'Username', 'Дата', 'Зміна', 'Початок', 'Кінець', 'Години']);

foreach ($data as $row) {
    fputcsv($output, [
        $row['full_name'],
        $row['username'],
        $row['date'],
        $row['shift_type'] === 'morning' ? 'Ранкова (7-15)' : 'Вечірня (15-23)',
        date('H:i', strtotime($row['start_time'])),
        $row['end_time'] ? date('H:i', strtotime($row['end_time'])) : '',
        $row['total_hours']
    ]);
}
fclose($output);
exit;
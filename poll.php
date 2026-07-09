<?php
session_start();
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(400);
    exit;
}

$userId = $_SESSION['user_id'];
$pdo = new PDO('sqlite:' . __DIR__ . '/nimbus.db');

$stmt = $pdo->prepare("SELECT * FROM jobs WHERE user_id = ? AND status IN ('pending', 'processing', 'complete', 'error', 'canceled') ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$userId]);
$jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$events = [];

foreach ($jobs as $job) {
    $progress = json_decode($job['progress'], true) ?? [];
    foreach ($progress as $id => $fileProgress) {
        if (empty($fileProgress)) continue;
        $events[] = array_merge(['id' => $id], $fileProgress);
    }
}

echo json_encode($events);
?>

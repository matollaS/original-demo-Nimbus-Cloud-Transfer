<?php
session_start();
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = new Database();
// In a real app we'd add this to database.php, but we can query it directly here or add a method.
// Let's add a method to Database class or just instantiate PDO here for quick fetch.
// Actually, I'll update database.php to have getActiveJobs method.
$pdo = new PDO('sqlite:' . __DIR__ . '/nimbus.db');
$stmt = $pdo->prepare("SELECT id FROM jobs WHERE user_id = ? AND status IN ('pending', 'processing')");
$stmt->execute([$_SESSION['user_id']]);
$jobs = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo json_encode(['activeJobs' => $jobs]);
?>

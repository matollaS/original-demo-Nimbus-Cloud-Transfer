<?php
session_start();
require_once 'database.php';
set_time_limit(0);

if (!isset($_SESSION['user_id'])) {
    http_response_code(400);
    exit;
}

$userId = $_SESSION['user_id'];
session_write_close(); // Prevent session blocking

$pdo = new PDO('sqlite:' . __DIR__ . '/nimbus.db');
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

if (ob_get_level()) ob_end_clean();

while (true) {
    // Fetch all active jobs for this user
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE user_id = ? AND status IN ('pending', 'processing', 'complete', 'error', 'canceled') ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $hasActive = false;
    foreach ($jobs as $job) {
        if ($job['status'] === 'pending' || $job['status'] === 'processing') {
            $hasActive = true;
        }
        
        $progress = json_decode($job['progress'], true) ?? [];
        foreach ($progress as $id => $fileProgress) {
            // Include the job status in case the progress JSON hasn't been updated yet
            if (empty($fileProgress)) continue;
            
            $data = array_merge(['id' => $id], $fileProgress);
            echo "event: ping\n";
            echo "data: " . json_encode($data) . "\n\n";
        }
    }
    
    @ob_flush();
    flush();

    // If there are no active jobs left, we can terminate the stream to save server resources
    // The client will automatically reconnect if they start a new upload, or we can just stay alive.
    // Let's stay alive but sleep to prevent CPU spin.
    sleep(1);
}
?>

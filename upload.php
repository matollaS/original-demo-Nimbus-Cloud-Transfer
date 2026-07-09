<?php
session_start();
require_once 'database.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload || !isset($payload['urls']) || !isset($payload['provider'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$db = new Database();
$urls = json_decode($payload['urls'], true) ?? [];
$jobIds = [];

// Split the batch into individual, concurrent jobs
foreach ($urls as $urlObj) {
    $jobId = bin2hex(random_bytes(16));
    
    // Create payload for this single URL
    $singlePayload = $payload;
    $singlePayload['urlObj'] = $urlObj;
    unset($singlePayload['urls']); // Remove the array

    $db->createJob($jobId, $_SESSION['user_id'], $singlePayload);
    
    // Trigger the background worker asynchronously for THIS specific file
    // We use CLI spawning to ensure true concurrency and bypass built-in server thread limits
    $phpBin = __DIR__ . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
    if (!file_exists($phpBin)) $phpBin = 'php'; // Fallback

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen('start /B "" "' . $phpBin . '" ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'worker.php') . ' ' . escapeshellarg($jobId), 'r'));
    } else {
        exec('"' . $phpBin . '" ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'worker.php') . ' ' . escapeshellarg($jobId) . ' > /dev/null 2>&1 &');
    }
    
    $jobIds[] = $jobId;
}

http_response_code(202);
echo json_encode(['message' => 'Jobs accepted', 'jobIds' => $jobIds]);
?>

<?php
session_start();
require_once 'database.php';

header('Content-Type: application/json');

file_put_contents(__DIR__ . '/job_action.log', "Action invoked\n", FILE_APPEND);

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$input = file_get_contents('php://input');
$payload = json_decode($input, true);

file_put_contents(__DIR__ . '/job_action.log', "Payload: " . $input . "\n", FILE_APPEND);

if (!$payload || !isset($payload['action']) || !isset($payload['jobId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    exit;
}

$db = new Database();
$jobId = $payload['jobId'];
$job = $db->getJob($jobId);

if (!$job || $job['user_id'] != $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$action = $payload['action'];
file_put_contents(__DIR__ . '/job_action.log', "Valid action: $action on job: $jobId\n", FILE_APPEND);

if ($action === 'stop') {
    $db->updateJobStatus($jobId, 'canceled');
    
    $progress = json_decode($job['progress'], true) ?? [];
    if (isset($progress[$jobId])) {
        $progress[$jobId]['status'] = 'canceled';
        $progress[$jobId]['message'] = 'User aborted the transfer.';
        $db->updateJobProgress($jobId, $progress);
    }
    
    echo json_encode(['success' => true, 'message' => 'Job canceled.']);
} 
elseif ($action === 'restart') {
    $db->updateJobStatus($jobId, 'pending');
    
    $progress = json_decode($job['progress'], true) ?? [];
    if (isset($progress[$jobId])) {
        $progress[$jobId]['status'] = 'init';
        $progress[$jobId]['percentage'] = 0;
        unset($progress[$jobId]['message']);
        unset($progress[$jobId]['link']);
        $db->updateJobProgress($jobId, $progress);
    }

    $phpBin = __DIR__ . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe';
    if (!file_exists($phpBin)) $phpBin = 'php'; 

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        pclose(popen('start /B "" "' . $phpBin . '" ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'worker.php') . ' ' . escapeshellarg($jobId), 'r'));
    } else {
        exec('"' . $phpBin . '" ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'worker.php') . ' ' . escapeshellarg($jobId) . ' > /dev/null 2>&1 &');
    }
    
    echo json_encode(['success' => true, 'message' => 'Job restarted.']);
} 
elseif ($action === 'clear') {
    $db->updateJobStatus($jobId, 'cleared');
    echo json_encode(['success' => true, 'message' => 'Job cleared from view.']);
} 
else {
    http_response_code(400);
    echo json_encode(['error' => 'Unknown action']);
}
?>

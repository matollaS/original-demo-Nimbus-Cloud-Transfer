<?php
set_time_limit(0); // Ensure CLI workers never timeout on multi-GB transfers
ignore_user_abort(true);
set_time_limit(0);

require_once __DIR__ . '/database.php';

$jobId = $_GET['job_id'] ?? $argv[1] ?? null;
if (!$jobId) exit;

$db = new Database();
$job = $db->getJob($jobId);
if (!$job || $job['status'] !== 'pending') exit;

$db->updateJobStatus($jobId, 'processing');
$user = $db->getUser($job['user_id']);
$payload = json_decode($job['payload'], true);
$urlObj = $payload['urlObj'] ?? null;

if (!$urlObj) {
    $db->updateJobStatus($jobId, 'error');
    exit;
}

$id = $jobId;
$progress = [];
$progress[$id] = [
    'status' => 'init',
    'filename' => rawurlencode($urlObj['fileName']),
    'percentage' => 0
];
$db->updateJobProgress($jobId, $progress);

$sourceUrl = $urlObj['url'];

// Security check: SSRF Prevention
$parsed = parse_url($sourceUrl);
if (!isset($parsed['host']) || !filter_var(gethostbyname($parsed['host']), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
    $progress[$id]['status'] = 'error';
    $progress[$id]['message'] = 'Invalid or internal IP detected';
    $db->updateJobProgress($jobId, $progress);
    $db->updateJobStatus($jobId, 'error');
    exit;
}

// Get File Size
stream_context_set_default([
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    'http' => ['header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/115.0\r\n"]
]);
$headers = @get_headers($sourceUrl, 1);
$fileSize = 0;
if ($headers !== false) {
    $headers = array_change_key_case($headers);
    if(isset($headers['content-length'])){
        $fileSize = is_array($headers['content-length']) ? end($headers['content-length']) : $headers['content-length'];
    }
}

$inStream = @fopen($sourceUrl, 'rb');
if (!$inStream) {
    $progress[$id]['status'] = 'error';
    $progress[$id]['message'] = 'Failed to open remote stream';
    $db->updateJobProgress($jobId, $progress);
    $db->updateJobStatus($jobId, 'error');
    exit;
}

$lastUpdate = 0;
$progressCb = function($resource, $download_size, $downloaded, $upload_size, $uploaded) use (&$progress, $id, $jobId, $db, &$lastUpdate, $fileSize) {
    // Check for user cancellation every 2 seconds
    static $lastCancelCheck = 0;
    if (time() - $lastCancelCheck > 2) {
        $currentJob = $db->getJob($jobId);
        if ($currentJob && $currentJob['status'] === 'canceled') {
            exit; // Instantly terminate the process to prevent DB overwrites
        }
        $lastCancelCheck = time();
    }

    // Only update DB once per second to prevent sqlite locking
    if ($upload_size > 0 && time() - $lastUpdate > 0) {
        $percentage = round(($uploaded / $upload_size) * 100);
        $progress[$id]['status'] = 'uploading';
        $progress[$id]['percentage'] = $percentage;
        $db->updateJobProgress($jobId, $progress);
        $lastUpdate = time();
    } elseif ($fileSize > 0 && time() - $lastUpdate > 0) {
        // Fallback to uploaded / fileSize if curl doesn't report upload_size properly
        $percentage = round(($uploaded / $fileSize) * 100);
        $progress[$id]['status'] = 'uploading';
        $progress[$id]['percentage'] = $percentage > 100 ? 100 : $percentage;
        $db->updateJobProgress($jobId, $progress);
        $lastUpdate = time();
    }
};

if ($payload['provider'] === 'gdrive') {
    // 1. Init Resumable Upload Session
    $chInit = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable');
    curl_setopt($chInit, CURLOPT_POST, true);
    curl_setopt($chInit, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($chInit, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $user['access_token'],
        'Content-Type: application/json; charset=UTF-8'
    ]);
    curl_setopt($chInit, CURLOPT_POSTFIELDS, json_encode(['name' => $urlObj['fileName'], 'parents' => ['root']]));
    curl_setopt($chInit, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chInit, CURLOPT_HEADER, true);
    
    $response = curl_exec($chInit);
    $header_size = curl_getinfo($chInit, CURLINFO_HEADER_SIZE);
    $header_text = substr($response, 0, $header_size);
    curl_close($chInit);

    $uploadUri = null;
    foreach (explode("\r\n", $header_text) as $line) {
        if (stripos($line, 'Location:') === 0) {
            $uploadUri = trim(substr($line, 9));
            break;
        }
    }

    if (!$uploadUri) {
        $progress[$id]['status'] = 'error';
        $progress[$id]['message'] = 'Failed to initialize GDrive upload session';
        $db->updateJobProgress($jobId, $progress);
    $db->updateJobStatus($jobId, 'error');
    exit;
    }

    // 2. Stream directly from Remote URL to GDrive
    $chPut = curl_init($uploadUri);
    curl_setopt($chPut, CURLOPT_PUT, true);
    curl_setopt($chPut, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($chPut, CURLOPT_NOPROGRESS, false);
    curl_setopt($chPut, CURLOPT_PROGRESSFUNCTION, $progressCb);
    curl_setopt($chPut, CURLOPT_READFUNCTION, function($ch, $fd, $length) use ($inStream) {
        if (feof($inStream)) return '';
        return fread($inStream, $length);
    });
    if ($fileSize > 0) {
        curl_setopt($chPut, CURLOPT_INFILESIZE, $fileSize);
    }
    curl_setopt($chPut, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chPut, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($chPut, CURLOPT_TIMEOUT, 60 * 60 * 8); // 8 Hours for massive files
    
    $resp = curl_exec($chPut);
    $httpCode = curl_getinfo($chPut, CURLINFO_HTTP_CODE);
    curl_close($chPut);
    fclose($inStream);

    if ($httpCode >= 200 && $httpCode < 300) {
        $respObj = json_decode($resp, true);
        $progress[$id]['status'] = 'complete';
        $progress[$id]['percentage'] = 100;
        $progress[$id]['link'] = 'https://drive.google.com/open?id=' . ($respObj['id'] ?? '');
    } else {
        $progress[$id]['status'] = 'error';
        $progress[$id]['message'] = "GDrive Error $httpCode";
    }

} else {
    // WebDAV
    $driveDir = preg_replace('#^https?://#', '', $payload['driveDir']);
    $uploadDir = $payload['uploadDir'] ?? 'Uploads/Nimbus/';
    $destUrl = 'https://' . rtrim($driveDir, '/') . '/' . ltrim($uploadDir, '/') . rawurlencode($urlObj['fileName']);
    
    $chPut = curl_init($destUrl);
    curl_setopt($chPut, CURLOPT_PUT, true);
    curl_setopt($chPut, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($chPut, CURLOPT_NOPROGRESS, false);
    curl_setopt($chPut, CURLOPT_PROGRESSFUNCTION, $progressCb);
    curl_setopt($chPut, CURLOPT_READFUNCTION, function($ch, $fd, $length) use ($inStream) {
        if (feof($inStream)) return '';
        return fread($inStream, $length);
    });
    if ($fileSize > 0) {
        curl_setopt($chPut, CURLOPT_INFILESIZE, $fileSize);
    }
    curl_setopt($chPut, CURLOPT_USERPWD, $payload['user'] . ':' . $payload['password']);
    curl_setopt($chPut, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chPut, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($chPut, CURLOPT_TIMEOUT, 60 * 60 * 8); // 8 Hours for massive files
    
    curl_exec($chPut);
    $httpCode = curl_getinfo($chPut, CURLINFO_HTTP_CODE);
    curl_close($chPut);
    fclose($inStream);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        $progress[$id]['status'] = 'complete';
        $progress[$id]['percentage'] = 100;
        $progress[$id]['link'] = $destUrl;
    } else {
        $progress[$id]['status'] = 'error';
        $progress[$id]['message'] = "WebDAV Error $httpCode";
    }
}

$db->updateJobProgress($jobId, $progress);
$db->updateJobStatus($jobId, 'complete');
?>

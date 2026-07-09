<?php
session_start();
require_once 'database.php';

// Simple .env parser
function getEnvVar($key) {
    static $env = null;
    if ($env === null) {
        $env = [];
        $lines = @file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines) {
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;
                list($k, $v) = explode('=', $line, 2);
                $env[trim($k)] = trim($v);
            }
        }
    }
    return isset($env[$key]) ? $env[$key] : null;
}

$client_id = getEnvVar('GOOGLE_CLIENT_ID');
$client_secret = getEnvVar('GOOGLE_CLIENT_SECRET');
$redirect_uri = 'http://localhost:8000/auth.php?action=callback';

$action = $_GET['action'] ?? 'status';

if ($action === 'login') {
    $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'email profile https://www.googleapis.com/auth/drive.file',
        'access_type' => 'offline',
        'prompt' => 'consent'
    ]);
    header('Location: ' . $auth_url);
    exit;
}

if ($action === 'callback') {
    $code = $_GET['code'] ?? null;
    if (!$code) {
        header('Location: /?error=missing_code');
        exit;
    }

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'redirect_uri' => $redirect_uri,
        'grant_type' => 'authorization_code',
        'code' => $code
    ]));
    $response = curl_exec($ch);
    curl_close($ch);
    
    $tokenData = json_decode($response, true);
    $accessToken = $tokenData['access_token'] ?? null;
    $refreshToken = $tokenData['refresh_token'] ?? null;

    if ($accessToken) {
        // Get user info
        $ch2 = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
        $userInfoResp = curl_exec($ch2);
        curl_close($ch2);
        
        $userInfo = json_decode($userInfoResp, true);
        
        $db = new Database();
        $user = $db->findOrCreateUser(
            $userInfo['id'], 
            $userInfo['email'] ?? '', 
            $userInfo['name'] ?? '', 
            $userInfo['picture'] ?? '', 
            $accessToken, 
            $refreshToken
        );

        $_SESSION['user_id'] = $user['id'];
        header('Location: /');
        exit;
    } else {
        header('Location: /?error=token_failed');
        exit;
    }
}

if ($action === 'logout') {
    session_destroy();
    header('Location: /');
    exit;
}

if ($action === 'status') {
    header('Content-Type: application/json');
    if (isset($_SESSION['user_id'])) {
        $db = new Database();
        $user = $db->getUser($_SESSION['user_id']);
        if ($user) {
            echo json_encode(['authenticated' => true, 'user' => $user]);
            exit;
        }
    }
    echo json_encode(['authenticated' => false]);
    exit;
}
?>

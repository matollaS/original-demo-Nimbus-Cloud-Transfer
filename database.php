<?php
class Database {
    private $pdo;

    public function __construct() {
        $dbPath = __DIR__ . '/nimbus.db';
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->init();
    }

    private function init() {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id TEXT PRIMARY KEY,
                google_id TEXT UNIQUE,
                email TEXT,
                name TEXT,
                picture TEXT,
                access_token TEXT,
                refresh_token TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id TEXT PRIMARY KEY,
                user_id TEXT,
                payload TEXT,
                status TEXT DEFAULT 'pending',
                progress TEXT DEFAULT '{}',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY(user_id) REFERENCES users(id)
            )
        ");
    }

    public function findOrCreateUser($google_id, $email, $name, $picture, $access_token, $refresh_token) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE google_id = :gid");
        $stmt->execute([':gid' => $google_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $stmt = $this->pdo->prepare("UPDATE users SET access_token = :at, refresh_token = COALESCE(:rt, refresh_token) WHERE id = :id");
            $stmt->execute([
                ':at' => $access_token,
                ':rt' => $refresh_token,
                ':id' => $user['id']
            ]);
            $user['access_token'] = $access_token;
            if ($refresh_token) $user['refresh_token'] = $refresh_token;
            return $user;
        } else {
            $id = bin2hex(random_bytes(16));
            $stmt = $this->pdo->prepare("INSERT INTO users (id, google_id, email, name, picture, access_token, refresh_token) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$id, $google_id, $email, $name, $picture, $access_token, $refresh_token]);
            return [
                'id' => $id, 'google_id' => $google_id, 'email' => $email, 
                'name' => $name, 'picture' => $picture, 
                'access_token' => $access_token, 'refresh_token' => $refresh_token
            ];
        }
    }

    public function getUser($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createJob($jobId, $userId, $payload) {
        $stmt = $this->pdo->prepare("INSERT INTO jobs (id, user_id, payload) VALUES (?, ?, ?)");
        $stmt->execute([$jobId, $userId, json_encode($payload)]);
    }

    public function getJob($jobId, $userId = null) {
        if ($userId) {
            $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE id = ? AND user_id = ?");
            $stmt->execute([$jobId, $userId]);
        } else {
            $stmt = $this->pdo->prepare("SELECT * FROM jobs WHERE id = ?");
            $stmt->execute([$jobId]);
        }
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateJobProgress($jobId, $progress, $status = 'processing') {
        $stmt = $this->pdo->prepare("UPDATE jobs SET progress = ?, status = ? WHERE id = ?");
        $stmt->execute([json_encode($progress), $status, $jobId]);
    }
    
    public function updateJobStatus($jobId, $status) {
        $stmt = $this->pdo->prepare("UPDATE jobs SET status = ? WHERE id = ?");
        $stmt->execute([$status, $jobId]);
    }
}
?>

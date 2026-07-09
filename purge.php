<?php
$pdo = new PDO('sqlite:' . __DIR__ . '/nimbus.db');
$pdo->exec("UPDATE jobs SET status='error' WHERE status IN ('init', 'pending', 'processing', 'uploading', 'downloading')");
echo "Purged!";

<?php
$pdo = new PDO('sqlite:' . __DIR__ . '/nimbus.db');
$pdo->exec("DELETE FROM jobs");
echo "All jobs deleted.";

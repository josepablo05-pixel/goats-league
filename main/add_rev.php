<?php
session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
require __DIR__ . '/db.php';

// Auth and Admin Check
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$meInfo = $stmt->fetch();
if (!$meInfo || $meInfo['role'] !== 'admin') { header("Location: index.php"); exit; }

try {
    $pdo->exec("ALTER TABLE matches ADD COLUMN revenue_paid TINYINT(1) DEFAULT 0 AFTER status");
    echo "Added revenue_paid. \n";
} catch(Exception $e) { echo "Error or exists: " . $e->getMessage() . "\n"; }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_finances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        match_id INT NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (team_id) REFERENCES teams(id),
        FOREIGN KEY (match_id) REFERENCES matches(id)
    )");
    echo "Created team_finances table.";
} catch(Exception $e) { echo "Error: " . $e->getMessage(); }
?>

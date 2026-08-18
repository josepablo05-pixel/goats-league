<?php
session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
require_once __DIR__ . '/../db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.password, u.role, u.profile_picture, t.name as team_name 
        FROM users u 
        LEFT JOIN teams t ON u.team_id = t.id 
        WHERE u.username = ?
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user) {
        $stored = $user['password'];
        $isValid = false;
        
        // Check if stored password is bcrypt or plain text
        if (password_get_info($stored)['algo'] !== null && password_get_info($stored)['algo'] !== 0) {
            $isValid = password_verify($password, $stored);
        } else {
            // Legacy plain text comparison
            $isValid = ($password === $stored);
            // Auto-upgrade to bcrypt on successful login
            if ($isValid) {
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
        }
        
        if ($isValid) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['team'] = $user['team_name'];
            $_SESSION['profile_picture'] = $user['profile_picture'];
            header('Location: ../index.php');
            exit;
        } else {
            $error = '⚠ Usuario o contraseña incorrectos';
        }
    } else {
        $error = '⚠ Usuario o contraseña incorrectos';
    }
}
?>
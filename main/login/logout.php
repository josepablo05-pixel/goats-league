<?php
session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
session_unset();
session_destroy();
header('Location: ../index.php');
exit;
?>
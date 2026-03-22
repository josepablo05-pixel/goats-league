<?php
// Railway puede dar un DATABASE_URL o variables individuales MYSQL*
$dbUrl = getenv('DATABASE_URL') ?: getenv('MYSQL_URL') ?: null;

if ($dbUrl) {
    // Parsear el URL: mysql://user:pass@host:port/dbname
    $parts  = parse_url($dbUrl);
    $host   = $parts['host'];
    $user   = $parts['user'];
    $pass   = $parts['pass'] ?? '';
    $port   = $parts['port'] ?? 3306;
    $dbname = ltrim($parts['path'], '/');
} else {
    // Variables individuales (Railway) o fallback local (XAMPP)
    $host   = getenv('MYSQLHOST')     ?: 'localhost';
    $user   = getenv('MYSQLUSER')     ?: 'root';
    $pass   = getenv('MYSQLPASSWORD') ?: '';
    $port   = getenv('MYSQLPORT')     ?: '3306';
    $dbname = getenv('MYSQLDATABASE') ?: 'goats_league';
}

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>

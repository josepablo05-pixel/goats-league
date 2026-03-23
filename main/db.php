<?php
// Configuración para el Volumen persistente en Railway
// El volumen de Railway debe estar montado en /app/data
$volumeDir = '/app/data';
$dbFile = $volumeDir . '/goats-league.sqlite';
$localTemplate = realpath(__DIR__ . '/../goats-league.sqlite');

// Si no existe la base de datos en el volumen persistente (al montar la primera vez):
if (!file_exists($dbFile)) {
    // Intentamos crear la carpeta si no existiera
    if (!file_exists($volumeDir)) {
        @mkdir($volumeDir, 0777, true);
    }
    
    // Si tienes el archivo base en tu código fuente (localTemplate), lo copiamos al volumen.
    // Así empieza con los equipos y usuarios ya creados.
    if ($localTemplate && file_exists($localTemplate)) {
        @copy($localTemplate, $dbFile);
    }
}

// Fallback por si fallan los permisos o estamos en XAMPP local
if (!file_exists($dbFile) && !is_writable($volumeDir)) {
    $dbFile = $localTemplate ?: (__DIR__ . '/../goats-league.sqlite');
}

try {
    // Conexión a SQLite
    $pdo = new PDO("sqlite:" . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Activar foreign keys en SQLite
    $pdo->exec('PRAGMA foreign_keys = ON;');

    // Camino A: Crear tablas si no existen (simplificado)
    // Para simplificar tu código, como ya has hecho git push de goats-league.sqlite con tus datos,
    // ya no necesitas todos los CREATE TABLE aquí, porque el archivo ya tiene los datos :)
    // Pero si algún día se borra, podrías ponerlos aquí abajo:
    /*
    $pdo->exec("CREATE TABLE IF NOT EXISTS teams (...)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (...)");
    */

} catch (PDOException $e) {
    die("Error de conexión a la base de datos (SQLite): " . $e->getMessage());
}
?>

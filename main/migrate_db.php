<?php
require_once __DIR__ . '/db.php';

echo "<h1>Ejecutando Migración...</h1>";

try {
    // Intentar añadir la columna jornada
    $pdo->exec("ALTER TABLE matches ADD COLUMN jornada INT DEFAULT 0 AFTER id");
    echo "<p style='color: green;'>✅ Columna 'jornada' añadida con éxito.</p>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<p style='color: blue;'>ℹ️ La columna 'jornada' ya existe.</p>";
    } else {
        echo "<p style='color: red;'>❌ Error al añadir la columna: " . $e->getMessage() . "</p>";
    }
}

echo "<a href='calendario.php'>Volver al Calendario</a>";
?>

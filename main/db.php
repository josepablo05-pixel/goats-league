<?php
// 1. Configuración de conexión (Mantenemos tu lógica original)
$dbUrl = getenv('DATABASE_URL') ?: getenv('MYSQL_URL') ?: null;

if ($dbUrl) {
    $parts  = parse_url($dbUrl);
    $host   = $parts['host'];
    $user   = $parts['user'];
    $pass   = $parts['pass'] ?? '';
    $port   = $parts['port'] ?? 3306;
    $dbname = ltrim($parts['path'], '/');
} else {
    $host   = getenv('MYSQLHOST')     ?: 'localhost';
    $user   = getenv('MYSQLUSER')     ?: 'root';
    $pass   = getenv('MYSQLPASSWORD') ?: '';
    $port   = getenv('MYSQLPORT')     ?: '3306';
    $dbname = getenv('MYSQLDATABASE') ?: 'goats_league';
}

try {
    // Intentamos conectar (permitir mock DB url en testing)
    if (strpos($dbUrl ?? '', 'sqlite:') === 0) {
        $pdo = new PDO($dbUrl);
    } else {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $user, $pass);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Si la petición viene del JavaScript de carga, respondemos OK
    if (isset($_GET['check_db'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'ready']);
        exit;
    }

} catch (PDOException $e) {
    // Si la petición viene del JS y falla, avisamos que siga esperando
    if (isset($_GET['check_db'])) {
        header('Content-Type: application/json');
        http_response_code(503);
        echo json_encode(['status' => 'sleeping']);
        exit;
    }

    // SI NO ES JS, MOSTRAMOS LA PANTALLA DE CARGA AL USUARIO
    mostrarPantallaCarga();
    exit;
}

// Función que contiene el HTML de espera (solo se ejecuta si la DB falla)
function mostrarPantallaCarga() {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Iniciando Goats League...</title>
        <style>
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0f172a; color: white; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
            .card { text-align: center; background: #1e293b; padding: 2rem; border-radius: 1rem; box-shadow: 0 10px 25px rgba(0,0,0,0.3); max-width: 400px; }
            .spinner { border: 4px solid rgba(255,255,255,0.1); border-top: 4px solid #38bdf8; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 20px auto; }
            @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
            h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
            p { color: #94a3b8; font-size: 0.9rem; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="spinner"></div>
            <h1>Calentando el motor...</h1>
            <p>La base de datos de <strong>Goats League</strong> se está despertando. La página se cargará automáticamente en unos segundos.</p>
        </div>

        <script>
            async function checkDB() {
                try {
                    // Consultamos este mismo archivo con el parámetro check_db
                    const res = await fetch(window.location.href + (window.location.search ? '&' : '?') + 'check_db=1');
                    if (res.ok) {
                        window.location.reload();
                    }
                } catch (e) {
                    console.log("Reintentando...");
                }
            }
            // Intentar cada 3 segundos
            setInterval(checkDB, 3000);
        </script>
    </body>
    </html>
    <?php
}

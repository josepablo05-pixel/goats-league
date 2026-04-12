<?php
session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/']);
session_start();
require_once __DIR__ . '/db.php';

// Validar que se reciba un ID por URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$teamId = (int)$_GET['id'];

// Obtener datos del equipo
$stmt = $pdo->prepare("SELECT * FROM teams WHERE id = ?");
$stmt->execute([$teamId]);
$team = $stmt->fetch();

if (!$team) {
    header('Location: index.php');
    exit;
}

// Obtener los jugadores del equipo
$stmtPlayers = $pdo->prepare("SELECT username, role, profile_picture, rating FROM users WHERE team_id = ? AND role != 'admin' ORDER BY username ASC");
$stmtPlayers->execute([$teamId]);
$players = $stmtPlayers->fetchAll();

// --- CALCULAR VALORACIÓN MEDIA DEL EQUIPO (Media de MVP) ---
// Usamos una lógica similar a estadisticas.php:
// Obtenemos los últimos partidos finalizados de este equipo
$stmtTeamMatches = $pdo->prepare("
    SELECT id FROM matches 
    WHERE (team1_id = ? OR team2_id = ?) AND status = 'finished' AND voting_closed = 1
    ORDER BY match_date DESC 
    LIMIT 10
");
$stmtTeamMatches->execute([$teamId, $teamId]);
$finishedMatches = $stmtTeamMatches->fetchAll();

$teamOverallAvg = 0;
if (count($finishedMatches) > 0) {
    $matchAvgs = [];
    foreach ($finishedMatches as $fm) {
        // En cada partido, tomamos los mejores 7 jugadores (solo si tienen valoración)
        $stmtTop7 = $pdo->prepare("
            SELECT AVG(rating) as player_avg 
            FROM match_ratings 
            WHERE match_id = ? AND target_id IN (SELECT id FROM users WHERE team_id = ?)
            GROUP BY target_id
            ORDER BY player_avg DESC
            LIMIT 7
        ");
        $stmtTop7->execute([$fm['id'], $teamId]);
        $topPlayerRatings = $stmtTop7->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($topPlayerRatings) > 0) {
            $matchAvgs[] = array_sum($topPlayerRatings) / count($topPlayerRatings);
        }
    }
    
    if (count($matchAvgs) > 0) {
        $teamOverallAvg = array_sum($matchAvgs) / count($matchAvgs);
    }
}
// -----------------------------------------------------------
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($team['name']); ?> - Perfil de Equipo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
</head>
<body class="text-white">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">⚽ Goats League</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">Equipos</a>
                        <ul class="dropdown-menu dropdown-menu-dark">
                            <?php
if (!isset($navTeams)) {
    $navTeams = $pdo->query("SELECT id, name FROM teams ORDER BY name ASC")->fetchAll();
}
foreach ($navTeams as $nT):
?>
                                <li><a class="dropdown-item" href="<?php echo(strpos($_SERVER['SCRIPT_NAME'], 'profile/') !== false) ? '../' : './'; ?>team.php?id=<?php echo $nT['id']; ?>"><?php echo htmlspecialchars($nT['name']); ?></a></li>
                            <?php
endforeach; ?>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Clasificación</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="estadisticas.php">Estadísticas</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="jugadores.php">Jugadores</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="mercado.php">Mercado</a>
                    </li>
                    <?php 
                    $mktStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'market_open'");
                    $isMktOpen = (bool)$mktStmt->fetchColumn();
                    if ($isMktOpen && isset($_SESSION['role']) && ($_SESSION['role'] === 'capitan' || $_SESSION['role'] === 'admin')): 
                    ?>
                        <li class="nav-item">
                            <a class="nav-link" href="tratos.php">Tratos</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="pizarra.php">Pizarra Táctica</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="calendario.php">Calendario</a>
                    </li>
                    <li class="nav-item ms-lg-3 d-flex align-items-center">
                        <?php if (isset($_SESSION['user'])): ?>
                            <a class="nav-link text-white me-3 d-flex align-items-center" href="profile/" style="padding: 0;">
                                <?php if (!empty($_SESSION['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>" alt="Perfil" class="rounded-circle" style="width: 36px; height: 36px; object-fit: cover; border: 2px solid #0d6efd;">
                                <?php
    else: ?>
                                    <div class="rounded-circle bg-secondary d-flex justify-content-center align-items-center text-white fw-bold" style="width: 36px; height: 36px; font-size: 16px; border: 2px solid #6c757d;">
                                        <?php echo strtoupper(substr($_SESSION['user'], 0, 1)); ?>
                                    </div>
                                <?php
    endif; ?>
                                <span class="ms-2 d-none d-lg-inline fw-semibold"><?php echo htmlspecialchars($_SESSION['user']); ?></span>
                            </a>
                            <a class="btn btn-outline-danger btn-sm" href="./login/logout.php">Cerrar Sesión</a>
                        <?php
else: ?>
                            <a class="btn btn-outline-light btn-sm" href="./login/login.php">Iniciar Sesión</a>
                        <?php
endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container mt-5">
        
        <!-- Cabecera del Equipo -->
        <div class="row align-items-center mb-5 bg-dark border border-secondary p-4 rounded-4 shadow-sm">
            <div class="col-md-3 text-center mb-3 mb-md-0">
                <?php if (!empty($team['logo']) && file_exists(__DIR__ . '/' . $team['logo'])): ?>
                    <img src="<?php echo htmlspecialchars($team['logo']); ?>" alt="Logo Equipo" class="img-fluid rounded-circle" style="max-width: 150px; border: 5px solid #0d6efd;">
                <?php
else: ?>
                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center mx-auto" style="width: 150px; height: 150px; font-size: 60px; border: 5px solid #6c757d;">
                        <?php echo strtoupper(substr($team['name'], 0, 1)); ?>
                    </div>
                <?php
endif; ?>
            </div>
            <div class="col-md-9 text-center text-md-start">
                <h1 class="display-5 fw-bold text-primary mb-1"><?php echo htmlspecialchars($team['name'] ?? ''); ?></h1>
                <div class="d-flex flex-wrap justify-content-center justify-content-md-start gap-3">
                    <p class="fs-5 text-muted mb-0">Rendimiento: <strong><?php echo htmlspecialchars($team['points'] ?? '0'); ?> Puntos</strong></p>
                    <p class="fs-5 text-muted mb-0">Valoración Media: <strong class="text-warning"><i class="bi bi-star-fill me-1"></i><?php echo $teamOverallAvg > 0 ? number_format($teamOverallAvg, 1) : 'N/A'; ?></strong></p>
                </div>
            </div>
        </div>

        <!-- Plantilla del Equipo -->
        <h3 class="mb-4">Plantilla Actual</h3>
        
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 row-cols-lg-4 g-4 mb-5">
            <?php if (count($players) > 0): ?>
                <?php foreach ($players as $player): ?>
                    <div class="col">
                        <div class="card h-100 bg-dark border-secondary bg-gradient text-light shadow text-center position-relative">
                            <?php if ($player['role'] === 'capitan'): ?>
                                <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-warning text-dark mt-2" style="font-size: 0.8rem; z-index: 2;">
                                    <i class="bi bi-star-fill text-dark me-1"></i> Capitán
                                </span>
                            <?php
        endif; ?>
                            
                            <div class="card-body mt-3">
                                <?php if (!empty($player['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($player['profile_picture']); ?>" alt="Perfil Jugador" class="rounded-circle mb-3" style="width: 80px; height: 80px; object-fit: cover; border: 3px solid #6c757d;">
                                <?php
        else: ?>
                                    <div class="rounded-circle bg-secondary d-flex justify-content-center align-items-center mx-auto mb-3" style="width: 80px; height: 80px; font-size: 35px; color: white;">
                                        <?php echo strtoupper(substr($player['username'], 0, 1)); ?>
                                    </div>
                                <?php
        endif; ?>
                                
                                <h5 class="card-title text-capitalize fw-bold mb-0">
                                    <?php echo htmlspecialchars($player['username']); ?>
                                </h5>
                                <p class="card-text text-muted small mt-1 mb-2">
                                    <?php echo ucfirst(htmlspecialchars($player['role'])); ?>
                                </p>
                                <div class="d-inline-flex align-items-center badge bg-dark border border-warning text-warning px-3 py-2 mt-1 rounded-pill shadow-sm">
                                    <i class="bi bi-star-fill me-2"></i> <span class="fs-6"><?php echo number_format($player['rating'] ?? 0, 1); ?> / 10</span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php
    endforeach; ?>
            <?php
else: ?>
                <div class="col-12">
                    <div class="alert alert-secondary bg-dark border-secondary text-muted text-center" role="alert">
                        Aún no hay jugadores registrados en este equipo.
                    </div>
                </div>
            <?php
endif; ?>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

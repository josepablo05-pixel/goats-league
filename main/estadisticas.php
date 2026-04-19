<?php
session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/']);
session_start();
require_once __DIR__ . '/db.php';

// 1. Calcular Media de los Equipos basados en sus 7 mejores jugadores por partido
$teams = $pdo->query("SELECT * FROM teams")->fetchAll();
$teamRatings = [];

foreach ($teams as $team) {
    $teamId = $team['id'];
    
    // Obtener todos los partidos finalizados y cerrados en los que participó este equipo
    $stmtMatches = $pdo->prepare("SELECT id FROM matches WHERE (team1_id = ? OR team2_id = ?) AND status = 'finished' AND voting_closed = 1");
    $stmtMatches->execute([$teamId, $teamId]);
    $matches = $stmtMatches->fetchAll();
    
    $teamMatchAvgs = [];
    
    foreach ($matches as $match) {
        $matchId = $match['id'];
        
        // Obtener la media de cada jugador de ESTE equipo en ESTE partido (max 7)
        $stmtPlayers = $pdo->prepare("
            SELECT mr.target_id, AVG(mr.rating) as player_avg 
            FROM match_ratings mr
            JOIN users u ON mr.target_id = u.id
            WHERE mr.match_id = ? AND u.team_id = ?
            GROUP BY mr.target_id
            ORDER BY player_avg DESC
            LIMIT 7
        ");
        $stmtPlayers->execute([$matchId, $teamId]);
        $topPlayers = $stmtPlayers->fetchAll();
        
        if (count($topPlayers) > 0) {
            $sum = 0;
            foreach ($topPlayers as $tp) {
                $sum += (float)$tp['player_avg'];
            }
            $teamMatchAvgs[] = $sum / count($topPlayers);
        }
    }
    
    $overallAvg = 0;
    if (count($teamMatchAvgs) > 0) {
        $overallAvg = array_sum($teamMatchAvgs) / count($teamMatchAvgs);
    }
    
    $teamRatings[] = [
        'team' => $team,
        'rating' => $overallAvg
    ];
}

// Ordenar equipos por su rating de mayor a menor
usort($teamRatings, function($a, $b) {
    return $b['rating'] <=> $a['rating'];
});

// 2. Máximos Goleadores
$topScorers = $pdo->query("
    SELECT u.id, u.username, u.profile_picture, t.name as team_name, COUNT(me.id) as total,
           (SELECT COUNT(DISTINCT ml.match_id) FROM match_lineups ml JOIN matches ma ON ml.match_id = ma.id WHERE ml.player_id = u.id AND ma.status = 'finished') as pj
    FROM match_events me
    JOIN users u ON me.player_id = u.id
    LEFT JOIN teams t ON u.team_id = t.id
    WHERE me.event_type = 'goal' AND u.role != 'admin'
    GROUP BY u.id
    ORDER BY total DESC, u.username ASC
    LIMIT 10
")->fetchAll();

// 3. Máximos Asistentes
$topAssists = $pdo->query("
    SELECT u.id, u.username, u.profile_picture, t.name as team_name, COUNT(me.id) as total,
           (SELECT COUNT(DISTINCT ml.match_id) FROM match_lineups ml JOIN matches ma ON ml.match_id = ma.id WHERE ml.player_id = u.id AND ma.status = 'finished') as pj
    FROM match_events me
    JOIN users u ON me.player_id = u.id
    LEFT JOIN teams t ON u.team_id = t.id
    WHERE me.event_type = 'assist' AND u.role != 'admin'
    GROUP BY u.id
    ORDER BY total DESC, u.username ASC
    LIMIT 10
")->fetchAll();

// 4. Jugadores con Más Media de Estrellas
$topRatedPlayers = $pdo->query("
    SELECT u.id, u.username, u.profile_picture, t.name as team_name, AVG(mr.rating) as avg_rating,
           (SELECT COUNT(DISTINCT ml.match_id) FROM match_lineups ml JOIN matches ma ON ml.match_id = ma.id WHERE ml.player_id = u.id AND ma.status = 'finished') as pj
    FROM match_ratings mr
    JOIN matches m ON mr.match_id = m.id
    JOIN users u ON mr.target_id = u.id
    LEFT JOIN teams t ON u.team_id = t.id
    WHERE u.role != 'admin' AND m.voting_closed = 1
    GROUP BY u.id
    ORDER BY avg_rating DESC, u.username ASC
    LIMIT 10
")->fetchAll();

// 5. Máximos Contribuidores (Goles + Asistencias)
$topContributors = $pdo->query("
    SELECT u.id, u.username, u.profile_picture, t.name as team_name,
           SUM(CASE WHEN me.event_type = 'goal'   THEN 1 ELSE 0 END) as goals,
           SUM(CASE WHEN me.event_type = 'assist' THEN 1 ELSE 0 END) as assists,
           COUNT(me.id) as total,
           (SELECT COUNT(DISTINCT ml.match_id) FROM match_lineups ml JOIN matches ma ON ml.match_id = ma.id WHERE ml.player_id = u.id AND ma.status = 'finished') as pj
    FROM match_events me
    JOIN users u ON me.player_id = u.id
    LEFT JOIN teams t ON u.team_id = t.id
    WHERE me.event_type IN ('goal','assist') AND u.role != 'admin'
    GROUP BY u.id
    ORDER BY total DESC, goals DESC, u.username ASC
    LIMIT 10
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Estadísticas - Goats League</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
</head>
<body class="text-white">

    <!-- NAVBAR -->
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
                                <li><a class="dropdown-item" href="<?php echo (strpos($_SERVER['SCRIPT_NAME'], 'profile/') !== false) ? '../' : './'; ?>team.php?id=<?php echo $nT['id']; ?>"><?php echo htmlspecialchars($nT['name']); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="index.php">Clasificación</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="estadisticas.php">Estadísticas</a>
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
                                <?php else: ?>
                                    <div class="rounded-circle bg-secondary d-flex justify-content-center align-items-center text-white fw-bold" style="width: 36px; height: 36px; font-size: 16px; border: 2px solid #6c757d;">
                                        <?php echo strtoupper(substr($_SESSION['user'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                                <span class="ms-2 d-none d-lg-inline fw-semibold"><?php echo htmlspecialchars($_SESSION['user']); ?></span>
                            </a>
                            <a class="btn btn-outline-danger btn-sm" href="./login/logout.php">Cerrar Sesión</a>
                        <?php else: ?>
                            <a class="btn btn-outline-light btn-sm" href="./login/login.php">Iniciar Sesión</a>
                        <?php endif; ?>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container mt-5 mb-5">
        <header class="text-center mb-5">
            <h1 class="display-4 fw-bold">Rendimiento Global</h1>
            <p class="text-muted">Estadísticas de equipos y jugadores de la temporada.</p>
        </header>

        <!-- SECCIÓN DE EQUIPOS -->
        <h3 class="mb-4 border-bottom border-primary text-primary pb-2"><i class="bi bi-shield-fill"></i> Nivel de los Equipos</h3>
        <p class="text-muted small mb-4">La nota media de cada equipo se calcula promediando la nota de los 7 mejores jugadores de ese equipo en cada partido, y luego calculando la media de todos los partidos jugados.</p>
        
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-4 g-4 mb-5">
            <?php foreach ($teamRatings as $tr): 
                $team = $tr['team'];
                $rating = $tr['rating'];
            ?>
                <div class="col">
                    <div class="card h-100 bg-dark border-secondary shadow text-center position-relative">
                        <div class="card-body mt-3">
                            <div class="mb-3 d-flex justify-content-center">
                                <?php if (!empty($team['logo'])): ?>
                                    <img src="<?php echo htmlspecialchars($team['logo'] ?? ''); ?>" alt="Logo" class="rounded-circle shadow" style="width: 100px; height: 100px; object-fit: cover; border: 4px solid #0d6efd;">
                                <?php else: ?>
                                    <div class="rounded-circle bg-secondary d-flex justify-content-center align-items-center shadow" style="width: 100px; height: 100px; font-size: 40px; color: white; border: 4px solid #6c757d;">
                                        <?php echo strtoupper(substr($team['name'], 0, 1)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <h5 class="fw-bold mb-1 text-truncate"><?php echo htmlspecialchars($team['name'] ?? ''); ?></h5>
                            
                            <div class="mt-3">
                                <span class="badge <?php echo $rating > 0 ? 'bg-success' : 'bg-secondary'; ?> fs-5 py-2 px-3 rounded-pill shadow-sm">
                                    <i class="bi bi-star-fill text-warning me-1"></i> 
                                    <?php echo $rating > 0 ? number_format($rating, 2) : 'N/A'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- SECCIÓN DE JUGADORES -->
        <h3 class="mb-4 border-bottom border-warning text-warning pb-2"><i class="bi bi-trophy-fill"></i> TOP Jugadores</h3>
        
        <div class="row g-4 d-flex align-items-stretch">
            <!-- Goleadores -->
            <div class="col-12 col-lg-4">
                <div class="card bg-dark border-secondary h-100 shadow">
                    <div class="card-header bg-transparent border-bottom border-secondary text-light fw-bold">
                        <i class="bi bi-vinyl-fill text-primary me-2"></i> Máximos Goleadores
                    </div>
                    <ul class="list-group list-group-flush bg-transparent">
                        <?php if (count($topScorers) > 0): ?>
                            <?php foreach ($topScorers as $i => $player): ?>
                                <li class="list-group-item bg-transparent text-light border-secondary d-flex align-items-center">
                                    <span class="fw-bold text-muted me-3" style="min-width: 20px;">#<?php echo $i+1; ?></span>
                                    
                                    <?php if (!empty($player['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($player['profile_picture']); ?>" class="rounded-circle me-2 object-fit-cover border border-secondary" style="width: 32px; height: 32px;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold" style="width: 32px; height: 32px; font-size: 14px;">
                                            <?php echo strtoupper(substr($player['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex-grow-1 text-truncate">
                                        <div class="fw-bold fs-6 mb-0"><?php echo htmlspecialchars($player['username']); ?></div>
                                        <small class="text-muted" style="font-size: 0.70rem;">
                                            <?php echo htmlspecialchars($player['team_name'] ?? 'Sin equipo'); ?> 
                                            <span class="ms-1 px-1 bg-secondary text-white rounded" style="font-size:0.65rem; opacity:0.8;"><?php echo $player['pj']; ?> PJ</span>
                                        </small>
                                    </div>
                                    
                                    <span class="badge bg-primary rounded-pill ms-2 fs-6 px-3"><?php echo $player['total']; ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="list-group-item bg-transparent text-muted border-0 p-4 text-center">
                                No hay goles registrados
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Asistentes -->
            <div class="col-12 col-lg-4">
                <div class="card bg-dark border-secondary h-100 shadow">
                    <div class="card-header bg-transparent border-bottom border-secondary text-light fw-bold">
                        <i class="bi bi-cursor-fill text-warning me-2"></i> Máximos Asistentes
                    </div>
                    <ul class="list-group list-group-flush bg-transparent">
                        <?php if (count($topAssists) > 0): ?>
                            <?php foreach ($topAssists as $i => $player): ?>
                                <li class="list-group-item bg-transparent text-light border-secondary d-flex align-items-center">
                                    <span class="fw-bold text-muted me-3" style="min-width: 20px;">#<?php echo $i+1; ?></span>
                                    
                                    <?php if (!empty($player['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($player['profile_picture']); ?>" class="rounded-circle me-2 object-fit-cover border border-secondary" style="width: 32px; height: 32px;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold" style="width: 32px; height: 32px; font-size: 14px;">
                                            <?php echo strtoupper(substr($player['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex-grow-1 text-truncate">
                                        <div class="fw-bold fs-6 mb-0"><?php echo htmlspecialchars($player['username']); ?></div>
                                        <small class="text-muted" style="font-size: 0.70rem;">
                                            <?php echo htmlspecialchars($player['team_name'] ?? 'Sin equipo'); ?> 
                                            <span class="ms-1 px-1 bg-secondary text-white rounded" style="font-size:0.65rem; opacity:0.8;"><?php echo $player['pj']; ?> PJ</span>
                                        </small>
                                    </div>
                                    
                                    <span class="badge bg-warning text-dark rounded-pill ms-2 fs-6 px-3"><?php echo $player['total']; ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="list-group-item bg-transparent text-muted border-0 p-4 text-center">
                                No hay asistencias registradas
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <!-- Mejores Jugadores -->
            <div class="col-12 col-lg-4">
                <div class="card bg-dark border-secondary h-100 shadow">
                    <div class="card-header bg-transparent border-bottom border-secondary text-light fw-bold">
                        <i class="bi bi-star-fill text-success me-2"></i> Mayor Media (MVP)
                    </div>
                    <ul class="list-group list-group-flush bg-transparent">
                        <?php if (count($topRatedPlayers) > 0): ?>
                            <?php foreach ($topRatedPlayers as $i => $player): ?>
                                <li class="list-group-item bg-transparent text-light border-secondary d-flex align-items-center">
                                    <span class="fw-bold text-muted me-3" style="min-width: 20px;">#<?php echo $i+1; ?></span>
                                    
                                    <?php if (!empty($player['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($player['profile_picture']); ?>" class="rounded-circle me-2 object-fit-cover border border-secondary" style="width: 32px; height: 32px;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold" style="width: 32px; height: 32px; font-size: 14px;">
                                            <?php echo strtoupper(substr($player['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex-grow-1 text-truncate">
                                        <div class="fw-bold fs-6 mb-0"><?php echo htmlspecialchars($player['username']); ?></div>
                                        <small class="text-muted" style="font-size: 0.70rem;">
                                            <?php echo htmlspecialchars($player['team_name'] ?? 'Sin equipo'); ?> 
                                            <span class="ms-1 px-1 bg-secondary text-white rounded" style="font-size:0.65rem; opacity:0.8;"><?php echo $player['pj']; ?> PJ</span>
                                        </small>
                                    </div>
                                    
                                    <span class="badge bg-success rounded-pill ms-2 fs-6 px-3"><?php echo number_format($player['avg_rating'], 2); ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="list-group-item bg-transparent text-muted border-0 p-4 text-center">
                                Aún no hay valoraciones
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            
        </div>

        <!-- MÁXIMOS CONTRIBUIDORES -->
        <h3 class="mt-5 mb-4 border-bottom border-danger text-danger pb-2"><i class="bi bi-fire"></i> Máximos Contribuidores (G+A)</h3>
        <p class="text-muted small mb-4">Jugadores ordenados por la suma de goles y asistencias totales en la temporada.</p>
        <div class="table-responsive shadow rounded mb-5">
            <table class="table table-dark table-hover align-middle mb-0 border border-secondary">
                <thead class="table-secondary text-dark small">
                    <tr>
                        <th class="px-3">#</th>
                        <th>Jugador</th>
                        <th>Equipo</th>
                        <th class="text-center text-primary"><i class="bi bi-vinyl-fill"></i> Goles</th>
                        <th class="text-center text-warning"><i class="bi bi-cursor-fill"></i> Asist.</th>
                        <th class="text-center text-danger fw-bold">G+A</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($topContributors) > 0): ?>
                    <?php foreach ($topContributors as $i => $p): ?>
                    <tr>
                        <td class="px-3 fw-bold text-warning">#<?php echo $i + 1; ?></td>
                        <td>
                            <div class="d-flex align-items-center">
                                <?php if (!empty($p['profile_picture']) && str_starts_with($p['profile_picture'], 'http')): ?>
                                    <img src="<?php echo htmlspecialchars($p['profile_picture']); ?>" class="rounded-circle me-2 object-fit-cover border border-secondary shadow-sm" style="width:32px;height:32px;">
                                <?php else: ?>
                                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold shadow-sm" style="width:32px;height:32px;font-size:13px;"><?php echo strtoupper(substr($p['username'],0,1)); ?></div>
                                <?php endif; ?>
                                <span class="fw-bold"><?php echo htmlspecialchars($p['username']); ?></span>
                            </div>
                        </td>
                        <td class="text-muted small">
                            <?php echo htmlspecialchars($p['team_name'] ?? 'Sin equipo'); ?>
                            <span class="ms-1 px-1 bg-secondary text-white rounded" style="font-size:0.65rem; opacity:0.8;"><?php echo $p['pj']; ?> PJ</span>
                        </td>
                        <td class="text-center"><span class="badge bg-primary rounded-pill px-3"><?php echo $p['goals']; ?></span></td>
                        <td class="text-center"><span class="badge bg-warning text-dark rounded-pill px-3"><?php echo $p['assists']; ?></span></td>
                        <td class="text-center"><span class="badge bg-danger rounded-pill fs-6 px-3 fw-bold"><?php echo $p['total']; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No hay contribuciones registradas aún.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

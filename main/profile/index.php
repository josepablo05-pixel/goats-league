<?php
session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/']);
session_start();
include 'data.php';

require_once __DIR__ . '/../db.php';
$userId = $_SESSION['user_id'] ?? null;

// Obtener Goles, Asistencias y Goles en Propia
$stmtStats = $pdo->prepare("SELECT event_type, COUNT(*) as qty FROM match_events WHERE player_id = ? GROUP BY event_type");
$stmtStats->execute([$userId]);
$myStats = ['goal' => 0, 'assist' => 0, 'own_goal' => 0];
foreach ($stmtStats->fetchAll() as $row) {
    if (isset($myStats[$row['event_type']])) {
        $myStats[$row['event_type']] = (int)$row['qty'];
    }
}

// Obtener Goles encajados en todos los partidos jugados y calcular la media
$stmtConceded = $pdo->prepare("
    SELECT 
        COUNT(*) as matches_played,
        SUM(CASE 
                WHEN m.team1_id = ml.team_id THEN m.team2_score 
                ELSE m.team1_score 
            END) as total_goals_conceded
    FROM match_lineups ml
    JOIN matches m ON ml.match_id = m.id
    WHERE ml.player_id = ? AND m.status = 'finished'
");
$stmtConceded->execute([$userId]);
$concededData = $stmtConceded->fetch();
$matchesPlayed = (int)$concededData['matches_played'];
$goalsConceded = (int)$concededData['total_goals_conceded'];
$concededPerMatch = $matchesPlayed > 0 ? round($goalsConceded / $matchesPlayed, 2) : 0;

// Obtener MVPs de la temporada
$stmtMVP = $pdo->prepare("
    SELECT COUNT(*) as mvps FROM (
        SELECT p.match_id
        FROM (
            SELECT mr.match_id, mr.target_id, AVG(mr.rating) as avg_rating
            FROM match_ratings mr
            JOIN matches m ON mr.match_id = m.id
            WHERE m.voting_closed = 1
            GROUP BY mr.match_id, mr.target_id
        ) p
        JOIN (
            SELECT match_id, MAX(avg_rating) as max_rating
            FROM (
                SELECT mr.match_id, mr.target_id, AVG(mr.rating) as avg_rating
                FROM match_ratings mr
                JOIN matches m ON mr.match_id = m.id
                WHERE m.voting_closed = 1
                GROUP BY mr.match_id, mr.target_id
            ) sub
            GROUP BY match_id
        ) m_max ON p.match_id = m_max.match_id AND ABS(p.avg_rating - m_max.max_rating) < 0.001
        WHERE p.target_id = ?
    ) as user_mvps
");
$stmtMVP->execute([$userId]);
$myMVPs = (int)$stmtMVP->fetchColumn();

// Obtener partidos jugados
$stmtPlayed = $pdo->prepare("
    SELECT m.*, t1.name as team1_name, t2.name as team2_name
    FROM matches m
    JOIN match_lineups ml ON m.id = ml.match_id
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    WHERE ml.player_id = ? AND m.status = 'finished'
    ORDER BY m.match_date DESC
");
$stmtPlayed->execute([$userId]);
$playedMatches = $stmtPlayed->fetchAll();

// Obtener próximos partidos convocados
$stmtUpcoming = $pdo->prepare("
    SELECT m.*, t1.name as team1_name, t2.name as team2_name
    FROM matches m
    JOIN match_lineups ml ON m.id = ml.match_id
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    WHERE ml.player_id = ? AND m.status = 'pending'
    ORDER BY m.match_date ASC
");
$stmtUpcoming->execute([$userId]);
$upcomingMatches = $stmtUpcoming->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Perfil - Goats League</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="text-white">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../index.php">⚽ Goats League</a>
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
                        <a class="nav-link" href="../index.php">Clasificación</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../estadisticas.php">Estadísticas</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../jugadores.php">Jugadores</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../pizarra.php">Pizarra Táctica</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../calendario.php">Calendario</a>
                    </li>
                    <li class="nav-item ms-lg-3 d-flex align-items-center">
                        <a class="nav-link text-white me-2 d-flex align-items-center" href="index.php">
                            <?php 
                            $navPicUrl = $_SESSION['profile_picture'] ?? '';
                            if (!empty($navPicUrl)): 
                                $navSrc = str_starts_with($navPicUrl, 'http') ? $navPicUrl : '../' . $navPicUrl;
                            ?>
                                <img src="<?php echo htmlspecialchars($navSrc); ?>" alt="Perfil" class="profile-icon">
                            <?php else: ?>
                                <span class="profile-letter"><?php echo strtoupper(substr($_SESSION['user'], 0, 1)); ?></span>
                            <?php endif; ?>
                            <span class="ms-2 d-none d-lg-inline"><?php echo htmlspecialchars($_SESSION['user']); ?></span>
                        </a>
                        <a class="btn btn-outline-danger btn-sm" href="../login/logout.php">Cerrar Sesión</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container mt-5">
        <h2 class="mb-4 text-center text-md-start">Mi Perfil</h2>
        
        <div class="row">
            <!-- Columna izquierda en escritorio, centro en móviles -->
            <div class="col-12 col-md-4 text-center text-md-start mb-4">
                <div class="position-relative d-inline-block">
                    <?php 
                    $picUrl = $_SESSION['profile_picture'] ?? '';
                    if (!empty($picUrl)): 
                        $src = str_starts_with($picUrl, 'http') ? $picUrl : '../' . $picUrl;
                    ?>
                        <img src="<?php echo htmlspecialchars($src); ?>" alt="Foto de Perfil" class="rounded-circle shadow-lg" style="width: 180px; height: 180px; object-fit: cover; border: 4px solid #0d6efd;">
                    <?php
else: ?>
                        <div class="rounded-circle bg-secondary d-flex justify-content-center align-items-center mx-auto shadow-lg" style="width: 180px; height: 180px; font-size: 80px; color: white;">
                            <?php echo strtoupper(substr($_SESSION['user'], 0, 1)); ?>
                        </div>
                    <?php
endif; ?>
                    
                    <label for="profile_pic" class="position-absolute bottom-0 end-0 bg-primary rounded-circle shadow d-flex justify-content-center align-items-center m-0" style="cursor: pointer; width: 45px; height: 45px; transform: translate(-15px, -15px); border: 3px solid #212529; z-index: 2;" title="Cambiar foto de perfil">
                        <i class="bi bi-camera-fill text-white fs-5"></i>
                    </label>
                </div>

                <form id="uploadForm" action="index.php" method="POST" enctype="multipart/form-data" class="d-none">
                    <input type="file" id="profile_pic" name="profile_pic" accept="image/*" onchange="document.getElementById('uploadForm').submit();" required>
                </form>

                <?php if (!empty($message)): ?>
                    <div class="mt-3"><?php echo $message; ?></div>
                <?php
endif; ?>

                <!-- Estadísticas Globales del Jugador -->
                <div class="mt-4 text-center text-md-start w-100 mx-auto mx-md-0" style="max-width: 250px;">
                    <h5 class="text-primary border-bottom border-primary pb-2 mb-3"><i class="bi bi-bar-chart-fill me-2"></i> Mis Estadísticas</h5>
                    
                    <div class="d-flex flex-column gap-2 w-100">
                        <!-- Ofensiva -->
                        <div class="card bg-secondary bg-opacity-10 border-secondary">
                            <div class="card-body p-2 d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-vinyl-fill text-primary"></i> Goles</span>
                                <strong class="fs-5"><?php echo $myStats['goal']; ?></strong>
                            </div>
                        </div>
                        <div class="card bg-secondary bg-opacity-10 border-secondary">
                            <div class="card-body p-2 d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-cursor-fill text-warning"></i> Asistencias</span>
                                <strong class="fs-5"><?php echo $myStats['assist']; ?></strong>
                            </div>
                        </div>
                        <div class="card bg-secondary bg-opacity-10 border-secondary">
                            <div class="card-body p-2 d-flex justify-content-between align-items-center text-danger">
                                <span><i class="bi bi-x-circle-fill"></i> En propia</span>
                                <strong class="fs-5"><?php echo $myStats['own_goal']; ?></strong>
                            </div>
                        </div>
                        
                        <!-- MVP -->
                        <div class="card bg-dark border-warning mt-2 mb-2">
                            <div class="card-body p-2 d-flex justify-content-between align-items-center">
                                <span><i class="bi bi-star-fill text-warning"></i> MVPs de Partido</span>
                                <strong class="fs-5 text-warning"><?php echo $myMVPs; ?></strong>
                            </div>
                        </div>
                        
                        <!-- Defensiva -->
                        <div class="card bg-dark border-info mt-2">
                            <div class="card-body p-2">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-info"><i class="bi bi-shield-fill-x"></i> Goles Encajados</span>
                                    <strong class="fs-5 text-light"><?php echo $goalsConceded; ?></strong>
                                </div>
                                <div class="text-muted small text-end pt-1 border-top border-secondary">
                                    Recibes <strong><?php echo number_format($concededPerMatch, 2); ?></strong> goles/partido
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Columna derecha -->
            <div class="col-12 col-md-8">

                <!-- Información -->
                <div class="card bg-dark border-secondary text-light mb-4 shadow">
                    <div class="card-body">
                        <h4 class="card-title text-primary"><i class="bi bi-info-circle-fill"></i> Información</h4>
                        <p class="mb-1"><strong>Usuario:</strong> <?php echo htmlspecialchars($_SESSION['user']); ?></p>
                        <p class="mb-1"><strong>Rol:</strong> <?php echo htmlspecialchars($_SESSION['role']); ?></p>
                        <p class="mb-1"><strong>Equipo:</strong> <?php echo htmlspecialchars($_SESSION['team'] ?? 'Sin equipo asignado'); ?></p>
                    </div>
                </div>

                <?php if ($_SESSION['role'] === 'admin'): ?>
                <!-- Panel Administrador -->
                <div class="card bg-dark border-warning text-light mb-4 shadow">
                    <div class="card-header bg-transparent border-warning">
                        <h5 class="mb-0 text-warning"><i class="bi bi-shield-lock-fill me-2"></i> Panel Administrador</h5>
                    </div>
                    <div class="card-body d-flex gap-3 flex-wrap">
                        <a href="../admin_mercado.php" class="btn btn-outline-warning fw-bold flex-grow-1">
                            <i class="bi bi-shop-window"></i> Admin Mercado
                        </a>
                        <a href="../admin_logos.php" class="btn btn-outline-warning fw-bold flex-grow-1">
                            <i class="bi bi-image-fill"></i> Admin Logos
                        </a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Cambio de Contraseña -->
                <div class="card bg-dark border-secondary text-light mb-4 shadow">
                    <div class="card-header bg-transparent border-secondary d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 text-warning"><i class="bi bi-key-fill me-2"></i> Cambiar Contraseña</h5>
                        <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="collapse" data-bs-target="#changePassCollapse">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                    <div class="collapse" id="changePassCollapse">
                        <div class="card-body">
                            <form method="POST" action="index.php">
                                <input type="hidden" name="action" value="change_password">
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-bold">Contraseña Actual</label>
                                    <input type="password" name="current_password" class="form-control bg-dark text-white border-secondary" placeholder="Tu contraseña actual" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-bold">Nueva Contraseña</label>
                                    <input type="password" name="new_password" class="form-control bg-dark text-white border-secondary" placeholder="Nueva contraseña (mín. 6 caracteres)" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label text-muted small fw-bold">Confirmar Nueva Contraseña</label>
                                    <input type="password" name="confirm_password" class="form-control bg-dark text-white border-secondary" placeholder="Repite la nueva contraseña" required>
                                </div>
                                <button type="submit" class="btn btn-warning fw-bold w-100"><i class="bi bi-lock-fill"></i> Guardar Nueva Contraseña</button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <!-- Próximos Partidos (Convocatoria) -->
                    <div class="col-md-6">
                        <div class="card bg-dark border-secondary text-light h-100 shadow">
                            <div class="card-body">
                                <h5 class="card-title text-warning border-bottom border-warning pb-2"><i class="bi bi-calendar-event"></i> Próximos Partidos</h5>
                                <?php if (count($upcomingMatches) > 0): ?>
                                    <ul class="list-group list-group-flush bg-transparent mt-3">
                                        <?php foreach ($upcomingMatches as $pm): ?>
                                            <li class="list-group-item bg-transparent text-light border-secondary px-0">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <strong><?php echo htmlspecialchars($pm['team1_name']) . " vs " . htmlspecialchars($pm['team2_name']); ?></strong>
                                                    <span class="badge bg-warning text-dark ms-2">Pendiente</span>
                                                </div>
                                                <div class="text-muted small"><i class="bi bi-clock me-1"></i> <?php echo date('d/m/Y H:i', strtotime($pm['match_date'])); ?></div>
                                            </li>
                                        <?php
    endforeach; ?>
                                    </ul>
                                <?php
else: ?>
                                    <p class="text-muted mt-3 small"><i class="bi bi-info-circle me-1"></i> No tienes convocatorias para futuros partidos.</p>
                                <?php
endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Partidos Jugados (Historial) -->
                    <div class="col-md-6">
                        <div class="card bg-dark border-secondary text-light h-100 shadow">
                            <div class="card-body">
                                <h5 class="card-title text-success border-bottom border-success pb-2"><i class="bi bi-calendar-check"></i> Partidos Jugados</h5>
                                <?php if (count($playedMatches) > 0): ?>
                                    <ul class="list-group list-group-flush bg-transparent mt-3">
                                        <?php foreach ($playedMatches as $pm): ?>
                                            <li class="list-group-item bg-transparent text-light border-secondary px-0">
                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                    <strong><?php echo htmlspecialchars($pm['team1_name']) . " vs " . htmlspecialchars($pm['team2_name']); ?></strong>
                                                    <span class="badge bg-primary fs-6"><?php echo $pm['team1_score'] . " - " . $pm['team2_score']; ?></span>
                                                </div>
                                                <div class="text-muted small"><i class="bi bi-check-circle me-1"></i> Jugado el <?php echo date('d/m/Y', strtotime($pm['match_date'])); ?></div>
                                            </li>
                                        <?php
    endforeach; ?>
                                    </ul>
                                <?php
else: ?>
                                    <p class="text-muted mt-3 small"><i class="bi bi-info-circle me-1"></i> Aún no has participado en ningún partido finalizado.</p>
                                <?php
endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

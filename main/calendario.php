<?php
session_start();
require_once __DIR__ . '/db.php';

$isAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$errorMsg = '';

// Procesar creacción de partidos (Solo Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_match' && $isAdmin) {
    $team1_id = (int)$_POST['team1_id'];
    $team2_id = (int)$_POST['team2_id'];
    $match_date = !empty($_POST['match_date']) ? $_POST['match_date'] : null;

    if ($team1_id !== $team2_id) {
        try {
            $stmt = $pdo->prepare("INSERT INTO matches (team1_id, team2_id, match_date, status, team1_score, team2_score) VALUES (?, ?, ?, 'pending', 0, 0)");
            $stmt->execute([$team1_id, $team2_id, $match_date]);
            header("Location: calendario.php");
            exit;
        }
        catch (PDOException $e) {
            $errorMsg = "Error en la base de datos al crear el partido.";
        }
    }
    else {
        $errorMsg = "Por favor, selecciona dos equipos diferentes y una fecha válida.";
    }
}

// Obtener equipos para el desplegable del creador de partidos
$allTeams = [];
if ($isAdmin) {
    $allTeams = $pdo->query("SELECT id, name FROM teams ORDER BY name")->fetchAll();
}

// Obtener todos los partidos
$stmtMatches = $pdo->query("
    SELECT m.*, 
           t1.name as team1_name, t1.logo as team1_logo,
           t2.name as team2_name, t2.logo as team2_logo
    FROM matches m
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    ORDER BY m.match_date ASC
");
$matches = $stmtMatches->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Calendario - Goats League</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
    <style>
        .match-card:hover {
            transform: scale(1.02);
            transition: all 0.2s ease-in-out;
            cursor: pointer;
        }
        .team-logo-small {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid #6c757d;
        }
        .jornada-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 1rem;
            margin-top: 2rem;
        }
        .jornada-header .badge-jornada {
            background: linear-gradient(135deg, #0d6efd, #6f42c1);
            color: white;
            font-size: 0.85rem;
            font-weight: 700;
            padding: 6px 16px;
            border-radius: 20px;
            white-space: nowrap;
            letter-spacing: 0.5px;
        }
        .jornada-header hr {
            flex: 1;
            border-color: #444;
            opacity: 1;
            margin: 0;
        }
    </style>
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
                                <li><a class="dropdown-item" href="<?php echo (strpos($_SERVER['SCRIPT_NAME'], 'profile/') !== false) ? '../' : './'; ?>team.php?id=<?php echo $nT['id']; ?>"><?php echo htmlspecialchars($nT['name']); ?></a></li>
                            <?php endforeach; ?>
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
                    <li class="nav-item">
                        <a class="nav-link" href="pizarra.php">Pizarra Táctica</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="calendario.php">Calendario</a>
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
        <header class="text-center mb-5">
            <h1 class="display-4 fw-bold">Calendario Oficial</h1>
            <p class="text-muted">Partidos, alineaciones y resultados totales de la temporada.</p>
        </header>
        
        <?php if ($isAdmin): ?>
            <div class="mb-4 text-center">
                <button class="btn btn-outline-success shadow border-2 px-4 py-2 fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#createMatchForm" aria-expanded="false" aria-controls="createMatchForm">
                    <i class="bi bi-plus-circle-fill me-2"></i> Crear Nuevo Partido
                </button>
            </div>
            
            <div class="collapse mb-5" id="createMatchForm">
                <div class="card bg-dark border-success shadow-lg">
                    <div class="card-body p-4">
                        <h5 class="card-title text-success mb-3"><i class="bi bi-gear-fill me-2"></i>Panel de Administración: Programar Partido</h5>
                        
                        <?php if ($errorMsg): ?>
                            <div class="alert alert-danger bg-danger text-white border-0 py-2"><i class="bi bi-exclamation-triangle-fill me-2"></i> <?php echo htmlspecialchars($errorMsg); ?></div>
                        <?php
    endif; ?>
                        
                        <form method="POST" class="row g-3 align-items-end">
                            <input type="hidden" name="action" value="create_match">
                            
                            <div class="col-md-3">
                                <label class="form-label text-light">Equipo Local</label>
                                <select name="team1_id" class="form-select bg-dark text-white border-secondary" required>
                                    <option value="">Selecciona...</option>
                                    <?php foreach ($allTeams as $t): ?>
                                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                    <?php
    endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-1 text-center text-muted fw-bold d-none d-md-block fs-5">VS</div>
                            
                            <div class="col-md-3">
                                <label class="form-label text-light">Equipo Visitante</label>
                                <select name="team2_id" class="form-select bg-dark text-white border-secondary" required>
                                    <option value="">Selecciona...</option>
                                    <?php foreach ($allTeams as $t): ?>
                                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                    <?php
    endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label text-light">Fecha y Hora (Opcional)</label>
                                <input type="datetime-local" name="match_date" class="form-control bg-dark text-white border-secondary text-white-50" style="color-scheme: dark;">
                            </div>
                            
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-success w-100 fw-bold shadow"><i class="bi bi-save me-1"></i> Programar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php
endif; ?>
        
        <?php if (count($matches) > 0): ?>
            <?php
            $matchesPerJornada = 2;
            $jornadas = array_chunk($matches, $matchesPerJornada);
            foreach ($jornadas as $jornadaNum => $jornada):
                $numJornada = $jornadaNum + 1;
            ?>
                <!-- Cabecera de Jornada -->
                <div class="jornada-header">
                    <hr>
                    <span class="badge-jornada">⚽ Jornada <?php echo $numJornada; ?></span>
                    <hr>
                </div>

                <div class="row row-cols-1 row-cols-md-2 g-4 mb-3">
                    <?php foreach ($jornada as $match): ?>
                        <div class="col">
                            <div class="card h-100 bg-dark border-secondary shadow match-card" onclick="window.location.href='match.php?id=<?php echo $match['id']; ?>'">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <small class="text-muted"><i class="bi bi-calendar-event me-1"></i> <?php echo $match['match_date'] ? date('d/m/Y H:i', strtotime($match['match_date'])) : 'Fecha por definir'; ?></small>
                                        <?php if ($match['status'] === 'finished'): ?>
                                            <span class="badge bg-success">Finalizado</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Pendiente</span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="row align-items-center text-center">
                                        <!-- Equipo 1 -->
                                        <div class="col-4">
                                            <?php if (!empty($match['team1_logo'])): ?>
                                                <img src="<?php echo htmlspecialchars($match['team1_logo']); ?>" class="team-logo-small mb-2" alt="Logo">
                                            <?php else: ?>
                                                <div class="team-logo-small d-flex justify-content-center align-items-center mx-auto mb-2 bg-secondary fw-bold text-white fs-4">
                                                    <?php echo strtoupper(substr($match['team1_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="fw-semibold text-truncate" title="<?php echo htmlspecialchars($match['team1_name']); ?>"><?php echo htmlspecialchars($match['team1_name']); ?></div>
                                        </div>

                                        <!-- Marcador -->
                                        <div class="col-4">
                                            <?php if ($match['status'] === 'finished'): ?>
                                                <div class="fs-2 fw-bold text-primary"><?php echo $match['team1_score']; ?> - <?php echo $match['team2_score']; ?></div>
                                            <?php else: ?>
                                                <div class="fs-4 fw-bold text-muted">VS</div>
                                                <small class="text-muted d-block mt-1">Por jugar</small>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Equipo 2 -->
                                        <div class="col-4">
                                            <?php if (!empty($match['team2_logo'])): ?>
                                                <img src="<?php echo htmlspecialchars($match['team2_logo']); ?>" class="team-logo-small mb-2" alt="Logo">
                                            <?php else: ?>
                                                <div class="team-logo-small d-flex justify-content-center align-items-center mx-auto mb-2 bg-secondary fw-bold text-white fs-4">
                                                    <?php echo strtoupper(substr($match['team2_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="fw-semibold text-truncate" title="<?php echo htmlspecialchars($match['team2_name']); ?>"><?php echo htmlspecialchars($match['team2_name']); ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="card-footer bg-transparent border-secondary text-center">
                                    <span class="text-primary small fw-bold">VER DETALLES <i class="bi bi-arrow-right"></i></span>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="row">
                <div class="col-12 text-center">
                    <div class="alert bg-dark border-secondary text-muted p-5">
                        <i class="bi bi-calendar-x fs-1 d-block mb-3"></i>
                        <h5>No hay partidos programados todavía.</h5>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

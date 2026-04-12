<?php
session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/']);
session_start();
require_once __DIR__ . '/db.php';

// Obtener la lista de todos los equipos para el filtro
$teams = $pdo->query("SELECT id, name FROM teams ORDER BY name")->fetchAll();

// Obtener todas las estadísticas de los jugadores en una sola consulta o en varias
// Vamos a hacer una gran query que recoja la información básica, goles, asistencias y goles en propia.
$query = "
    SELECT 
        u.id, 
        u.username, 
        u.profile_picture, 
        t.name AS team_name,
        
        -- Goles Anotados
        (SELECT COUNT(*) FROM match_events me WHERE me.player_id = u.id AND me.event_type = 'goal') AS goals,
        
        -- Asistencias
        (SELECT COUNT(*) FROM match_events me WHERE me.player_id = u.id AND me.event_type = 'assist') AS assists,
        
        -- Goles en Propia
        (SELECT COUNT(*) FROM match_events me WHERE me.player_id = u.id AND me.event_type = 'own_goal') AS own_goals,
        
        -- Media de Estrellas MVP
        (
            SELECT AVG(mr.rating) 
            FROM match_ratings mr 
            JOIN matches m ON mr.match_id = m.id 
            WHERE mr.target_id = u.id AND m.voting_closed = 1
        ) AS avg_rating,
        
        -- Partidos Jugados (convocado en partidos finalizados)
        (
            SELECT COUNT(DISTINCT ml.match_id) 
            FROM match_lineups ml 
            JOIN matches m ON ml.match_id = m.id 
            WHERE ml.player_id = u.id AND m.status = 'finished'
        ) AS matches_played,
        
        -- Goles Encajados (cuando este jugador estaba en el campo)
        (
            SELECT SUM(CASE 
                WHEN m.team1_id = ml.team_id THEN m.team2_score 
                ELSE m.team1_score 
            END)
            FROM match_lineups ml
            JOIN matches m ON ml.match_id = m.id
            WHERE ml.player_id = u.id AND m.status = 'finished'
        ) AS goals_conceded
        
    FROM users u
    LEFT JOIN teams t ON u.team_id = t.id
    WHERE u.username != 'admin'
    ORDER BY avg_rating DESC
";

$players = $pdo->query($query)->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jugadores - Goats League</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
    <style>
        .sortable:hover {
            cursor: pointer;
            text-decoration: underline;
        }
        .table-dark {
            --bs-table-bg: #010e22;
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
    </style>
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
    
    <div class="container mt-5 mb-5 px-3">
        <header class="text-center mb-5">
            <h1 class="display-4 fw-bold">Jugadores</h1>
            <p class="text-muted">Directorio completo con las estadísticas individuales de cada jugador de la liga.</p>
        </header>

        <!-- Filtros y Buscador -->
        <div class="row mb-4 align-items-end">
            <div class="col-md-4 mb-3 mb-md-0">
                <label class="form-label text-muted small fw-bold">Filtrar por Equipo:</label>
                <select id="teamFilter" class="form-select bg-dark text-white border-secondary">
                    <option value="all">Todos los equipos</option>
                    <?php foreach ($teams as $t): ?>
                        <option value="<?php echo htmlspecialchars($t['name']); ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8 text-end">
                <span class="badge bg-secondary mb-1">💡 Haz clic en el nombre de las columnas para ordenar la tabla.</span>
            </div>
        </div>

        <!-- Tabla -->
        <div class="table-responsive">
            <table class="table table-dark table-hover table-bordered border-secondary align-middle mb-0" id="playersTable">
                <thead class="table-secondary border-secondary">
                    <tr>
                        <th scope="col" class="sortable text-nowrap" onclick="sortTable(0, 'string')">Jugador <i class="bi bi-arrow-down-up small ms-1"></i></th>
                        <th scope="col" class="sortable text-center d-none d-md-table-cell text-nowrap" onclick="sortTable(1, 'string')">Equipo <i class="bi bi-arrow-down-up small ms-1"></i></th>
                        <th scope="col" class="sortable text-center d-none d-md-table-cell" onclick="sortTable(2, 'number')">PJ</th>
                        <th scope="col" class="sortable text-center text-primary" title="Goles" onclick="sortTable(3, 'number')"><i class="bi bi-vinyl-fill"></i><span class="d-none d-sm-inline"> Gol</span> <i class="bi bi-arrow-down-up small ms-1"></i></th>
                        <th scope="col" class="sortable text-center text-warning" title="Asistencias" onclick="sortTable(4, 'number')"><i class="bi bi-cursor-fill"></i><span class="d-none d-sm-inline"> Asi</span> <i class="bi bi-arrow-down-up small ms-1"></i></th>
                        <th scope="col" class="sortable text-center text-danger d-none d-sm-table-cell" title="Goles en Propia" onclick="sortTable(5, 'number')"><i class="bi bi-x-circle-fill"></i><span class="d-none d-md-inline"> PP</span></th>
                        <th scope="col" class="sortable text-center text-info d-none d-lg-table-cell" title="Goles Encajados" onclick="sortTable(6, 'number')"><i class="bi bi-shield-fill-x"></i><span class="d-none d-md-inline"> Encaj</span></th>
                        <th scope="col" class="sortable text-center text-success text-nowrap" title="Nota Media MVP" onclick="sortTable(7, 'number')"><i class="bi bi-star-fill"></i><span class="d-none d-sm-inline"> MVP</span> <i class="bi bi-arrow-down-up small ms-1"></i></th>
                    </tr>
                </thead>
                <tbody id="playersTbody">
                    <?php foreach ($players as $p): 
                        $teamName = $p['team_name'] ?? 'Sin equipo';
                        $pj = (int)$p['matches_played'];
                        $goals = (int)$p['goals'];
                        $assists = (int)$p['assists'];
                        $ownGoals = (int)$p['own_goals'];
                        $conceded = (int)$p['goals_conceded'];
                        $avgRating = $p['avg_rating'] !== null ? (float)$p['avg_rating'] : 0;
                    ?>
                        <tr class="player-row" data-team="<?php echo htmlspecialchars($teamName); ?>">
                            <td>
                                <div class="d-flex align-items-center text-nowrap" title="<?php echo htmlspecialchars($p['username'] ?? ''); ?>">
                                    <?php if (!empty($p['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($p['profile_picture']); ?>" class="rounded-circle me-2 object-fit-cover border border-secondary" style="width: 30px; height: 30px;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold" style="width: 30px; height: 30px; font-size: 14px;">
                                            <?php echo strtoupper(substr($p['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <span class="fw-bold" style="max-width: 90px; overflow: hidden; text-overflow: ellipsis; display: inline-block; vertical-align: middle;"><?php echo htmlspecialchars($p['username'] ?? ''); ?></span>
                                </div>
                            </td>
                            <td class="text-center text-muted small d-none d-md-table-cell"><?php echo htmlspecialchars($teamName); ?></td>
                            <td class="text-center fw-bold text-light d-none d-md-table-cell"><?php echo $pj; ?></td>
                            <td class="text-center fw-bold text-primary fs-5"><?php echo $goals; ?></td>
                            <td class="text-center fw-bold text-warning fs-5"><?php echo $assists; ?></td>
                            <td class="text-center fw-bold text-danger d-none d-sm-table-cell"><?php echo $ownGoals; ?></td>
                            <td class="text-center fw-bold text-info d-none d-lg-table-cell"><?php echo $conceded; ?></td>
                            <td class="text-center fw-bold text-success fs-5"><?php echo number_format($avgRating, 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
    </div>

    <!-- Scripts de Ordenado y Filtrado -->
    <script>
        // Filtrado por Equipo
        document.getElementById('teamFilter').addEventListener('change', function() {
            const filter = this.value;
            const rows = document.querySelectorAll('.player-row');
            
            rows.forEach(row => {
                if (filter === 'all' || row.dataset.team === filter) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });

        // Ordenado de Tabla
        let sortDirections = [true, true, true, true, true, true, true, true]; // True = Desc, False = Asc (Por defecto numérico vamos a bajar, texto a subir)
        // He modificado para que el primer clic a numérico ordene de mayor a menor (Desc)
        
        function sortTable(columnIndex, type) {
            const tbody = document.getElementById("playersTbody");
            const rows = Array.from(tbody.querySelectorAll("tr"));
            const asc = sortDirections[columnIndex];
            
            rows.sort((a, b) => {
                let textA = a.cells[columnIndex].innerText.trim();
                let textB = b.cells[columnIndex].innerText.trim();
                
                if (type === 'number') {
                    let numA = parseFloat(textA) || 0;
                    let numB = parseFloat(textB) || 0;
                    return asc ? numB - numA : numA - numB;
                } else {
                    return asc ? textA.localeCompare(textB) : textB.localeCompare(textA);
                }
            });
            
            // Invertir dirección para la próxima
            sortDirections[columnIndex] = !asc;
            
            // Añadir de nuevo
            rows.forEach(row => tbody.appendChild(row));
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

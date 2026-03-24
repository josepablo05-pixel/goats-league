<?php
session_start();
include 'data.php';
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Goats League - Clasificación</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
</head>

<body class="text-white">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">⚽ Goats League</a>
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

    <div class="container mt-4">
        <header class="text-center mb-5">
            <h1 class="display-4 fw-bold">Clasificación oficial</h1>
            <p>Split 1 - Goats League</p>
        </header>

        <div class="table-responsive shadow-sm rounded">
            <table class="table table-dark table-hover mb-0">
                <thead class="table-active">
                    <tr>
                        <th class="py-3 px-4">#</th>
                        <th class="py-3">Equipo</th>
                        <th class="py-3 text-center d-none d-sm-table-cell" title="Partidos Jugados">PJ</th>
                        <th class="py-3 text-center text-success" title="Victorias">V</th>
                        <th class="py-3 text-center text-warning" title="Empates">E</th>
                        <th class="py-3 text-center text-danger" title="Derrotas">D</th>
                        <th class="py-3 text-center d-none d-md-table-cell" title="Goles a Favor">GF</th>
                        <th class="py-3 text-center d-none d-md-table-cell" title="Goles en Contra">GC</th>
                        <th class="py-3 text-center d-none d-lg-table-cell" title="Diferencia de Goles">DG</th>
                        <th class="py-3 text-center" title="Puntos">Pts</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
$position = 1;
foreach ($teams as $team):
?>
                        <tr>
                            <td class="px-4 fw-bold text-warning"><?php echo $position++; ?>°</td>
                            <td><a href="team.php?id=<?php echo htmlspecialchars($team['id'] ?? ''); ?>" class="text-white text-decoration-none fw-semibold border-bottom border-primary pb-1"><?php echo htmlspecialchars($team['name'] ?? ''); ?></a></td>
                            <td class="text-center text-muted d-none d-sm-table-cell"><?php echo $team['pj']; ?></td>
                            <td class="text-center fw-bold text-success"><?php echo $team['v']; ?></td>
                            <td class="text-center fw-bold text-warning"><?php echo $team['e']; ?></td>
                            <td class="text-center fw-bold text-danger"><?php echo $team['d']; ?></td>
                            <td class="text-center d-none d-md-table-cell"><?php echo $team['gf']; ?></td>
                            <td class="text-center d-none d-md-table-cell"><?php echo $team['gc']; ?></td>
                            <td class="text-center fw-bold d-none d-lg-table-cell <?php echo $team['dg'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($team['dg'] >= 0 ? '+' : '') . $team['dg']; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary fs-6"><?php echo htmlspecialchars($team['points'] ?? '0'); ?></span>
                            </td>
                        </tr>
                    <?php
endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="app.js"></script>
</body>

</html>
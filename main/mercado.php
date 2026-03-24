<?php
session_start();
require_once __DIR__ . '/db.php';

// Auth check
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$myUserId = $_SESSION['user_id'];

// Load my info
$stmtMe = $pdo->prepare("SELECT u.*, t.name as team_name, t.budget FROM users u LEFT JOIN teams t ON u.team_id = t.id WHERE u.id = ?");
$stmtMe->execute([$myUserId]);
$me = $stmtMe->fetch();
$myTeamId = $me['team_id'];
$isCaptain = ($me['role'] === 'capitan');

// --- CALCULAR PRESUPUESTO DINÁMICO ---
// Fórmula: Media del Equipo + (1 * Partidos Jugados)
if ($myTeamId) {
    // 1. Obtener Partidos Jugados (pj) de este equipo
    $stmtPJ = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE (team1_id = ? OR team2_id = ?) AND status = 'finished'");
    $stmtPJ->execute([$myTeamId, $myTeamId]);
    $matchesPlayed = (int)$stmtPJ->fetchColumn();

    // 2. Obtener Media del Equipo (Usamos la misma lógica que team.php)
    $stmtTeamMatches = $pdo->prepare("SELECT id FROM matches WHERE (team1_id = ? OR team2_id = ?) AND status = 'finished' ORDER BY match_date DESC LIMIT 10");
    $stmtTeamMatches->execute([$myTeamId, $myTeamId]);
    $finishedMatches = $stmtTeamMatches->fetchAll();
    
    $teamRating = 0;
    if (count($finishedMatches) > 0) {
        $matchAvgs = [];
        foreach ($finishedMatches as $fm) {
            $stmtTop7 = $pdo->prepare("SELECT AVG(rating) FROM match_ratings WHERE match_id = ? AND target_id IN (SELECT id FROM users WHERE team_id = ?) GROUP BY target_id ORDER BY AVG(rating) DESC LIMIT 7");
            $stmtTop7->execute([$fm['id'], $myTeamId]);
            $topRatings = $stmtTop7->fetchAll(PDO::FETCH_COLUMN);
            if (count($topRatings) > 0) $matchAvgs[] = array_sum($topRatings) / count($topRatings);
        }
        if (count($matchAvgs) > 0) $teamRating = array_sum($matchAvgs) / count($matchAvgs);
    }

    // 3. Sobrescribir el presupuesto en el objeto $me para que se use en toda la página
    $dbBudgetAdjustment = (float)($me['budget'] ?? 0);
    $me['budget'] = $teamRating + (1.0 * $matchesPlayed) + $dbBudgetAdjustment;
}
// -------------------------------------

// Check market state
$mktStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'market_open'");
$isMarketOpen = (bool)$mktStmt->fetchColumn();

// Handle Actions
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // --- PROTECTION LOGIC ---
    if ($_POST['action'] === 'toggle_protect') {
        if (!$isCaptain) { $error = "Solo el capitán puede proteger jugadores."; }
        else if ($isMarketOpen) { $error = "No puedes cambiar las protecciones con el mercado abierto."; }
        else {
            $targetId = (int)$_POST['player_id'];
            $targetProtect = (int)$_POST['protect_state']; // 1 = proteger, 0 = desproteger
            
            // Check if player is in my team
            $stmtP = $pdo->prepare("SELECT team_id, is_protected FROM users WHERE id = ? AND role != 'admin'");
            $stmtP->execute([$targetId]);
            $tp = $stmtP->fetch();
            
            if ($tp && $tp['team_id'] == $myTeamId) {
                if ($targetProtect === 1) {
                    // Check limit (max 2)
                    $cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE team_id = ? AND is_protected = 1");
                    $cnt->execute([$myTeamId]);
                    if ($cnt->fetchColumn() >= 2) {
                        $error = "Ya has bloqueado el máximo de 2 jugadores.";
                    } else {
                        $pdo->prepare("UPDATE users SET is_protected = 1 WHERE id = ?")->execute([$targetId]);
                        $success = "Jugador bloqueado correctamente.";
                    }
                } else {
                    $pdo->prepare("UPDATE users SET is_protected = 0 WHERE id = ?")->execute([$targetId]);
                    $success = "Jugador desbloqueado.";
                }
            }
        }
    }
    
    // --- BUY LOGIC ---
    if ($_POST['action'] === 'buy_player') {
        if (!$isCaptain) { $error = "Solo el capitán puede fichar."; }
        else if (!$isMarketOpen) { $error = "El mercado está cerrado."; }
        else if (!$myTeamId) { $error = "No tienes equipo."; }
        else {
            $pid = (int)$_POST['player_id'];
            
            // Validate player
            $stmtP = $pdo->prepare("SELECT id, username, team_id, is_protected FROM users WHERE id = ? AND role != 'admin'");
            $stmtP->execute([$pid]);
            $player = $stmtP->fetch();
            
            if (!$player || $player['is_protected'] || $player['team_id'] == $myTeamId || !$player['team_id']) {
                $error = "El jugador no existe, está en tu equipo, no tiene equipo o está protegido.";
            } else {
                $sellerTeamId = $player['team_id'];
                
                // Check transfer limits for my team
                // 1. Max 2 total
                $cntTotal = $pdo->prepare("SELECT COUNT(*) FROM transfers WHERE buyer_team_id = ?");
                $cntTotal->execute([$myTeamId]);
                if ($cntTotal->fetchColumn() >= 2) {
                    $error = "Tu equipo ya ha alcanzado el límite máximo de 2 fichajes.";
                } else {
                    // 2. Max 1 from this seller
                    $cntSeller = $pdo->prepare("SELECT COUNT(*) FROM transfers WHERE buyer_team_id = ? AND seller_team_id = ?");
                    $cntSeller->execute([$myTeamId, $sellerTeamId]);
                    if ($cntSeller->fetchColumn() >= 1) {
                        $error = "Solo puedes fichar a 1 jugador de este mismo equipo.";
                    } else {
                        // Calculate price: "Media literal" (User rating column)
                        $stmtPrice = $pdo->prepare("SELECT rating FROM users WHERE id = ?");
                        $stmtPrice->execute([$pid]);
                        $calculatedPrice = (float)$stmtPrice->fetchColumn();
                        if ($calculatedPrice <= 0) $calculatedPrice = 1.0; 
                        
                        if ($me['budget'] < $calculatedPrice) {
                            $error = "Fondos insuficientes. Necesitas " . number_format($calculatedPrice, 2) . " y tienes " . number_format($me['budget'], 2);
                        } else {
                            // EXECUTE TRANSFER
                            $pdo->beginTransaction();
                            try {
                                // Deduct from buyer
                                $pdo->prepare("UPDATE teams SET budget = budget - ? WHERE id = ?")->execute([$calculatedPrice, $myTeamId]);
                                // Add to seller
                                $pdo->prepare("UPDATE teams SET budget = budget + ? WHERE id = ?")->execute([$calculatedPrice, $sellerTeamId]);
                                // Move player
                                $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?")->execute([$myTeamId, $pid]);
                                // Log transfer
                                $pdo->prepare("INSERT INTO transfers (buyer_team_id, player_id, seller_team_id, price) VALUES (?, ?, ?, ?)")
                                    ->execute([$myTeamId, $pid, $sellerTeamId, $calculatedPrice]);
                                $pdo->commit();
                                
                                // Refresh my budget
                                $me['budget'] -= $calculatedPrice;
                                $success = "¡Fichaje completado! " . htmlspecialchars($player['username']) . " ahora juega en tu equipo.";
                            } catch (Exception $e) {
                                $pdo->rollBack();
                                $error = "Error al completar el traspaso.";
                            }
                        }
                    }
                }
            }
        }
    }
}

// Fetch all available players for market
$marketPlayers = [];
if ($myTeamId) {
    // Other teams players to buy
    $stmtMkt = $pdo->prepare("SELECT u.id, u.username, u.profile_picture, u.is_protected, t.name as team_name, t.id as seller_team_id FROM users u JOIN teams t ON u.team_id = t.id WHERE u.team_id != ? AND u.role != 'admin' ORDER BY t.name ASC, u.username ASC");
    $stmtMkt->execute([$myTeamId]);
    $marketPlayersRaw = $stmtMkt->fetchAll();
    
    // Add calculated prices: "Media literal"
    foreach ($marketPlayersRaw as $mp) {
        $stmtPrice = $pdo->prepare("SELECT rating FROM users WHERE id = ?");
        $stmtPrice->execute([$mp['id']]);
        $mp['price'] = (float)$stmtPrice->fetchColumn();
        if ($mp['price'] <= 0) $mp['price'] = 1.0;
        $marketPlayers[] = $mp;
    }
}

// Fetch my team players for protection
$myPlayers = [];
if ($myTeamId) {
    $stmtMy = $pdo->prepare("SELECT id, username, profile_picture, is_protected FROM users WHERE team_id = ? AND role != 'admin' ORDER BY username ASC");
    $stmtMy->execute([$myTeamId]);
    $myPlayers = $stmtMy->fetchAll();
}

$protectedCount = 0;
foreach($myPlayers as $mp) if($mp['is_protected']) $protectedCount++;

// Fetch transfer history
$transfers = $pdo->query("
    SELECT tr.*, u.username as player_name, 
           tb.name as buyer_name, ts.name as seller_name 
    FROM transfers tr
    JOIN users u ON tr.player_id = u.id
    JOIN teams tb ON tr.buyer_team_id = tb.id
    JOIN teams ts ON tr.seller_team_id = ts.id
    ORDER BY tr.created_at DESC LIMIT 20
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mercado - Goats League</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
</head>
<body class="text-white">

    <!-- NAVBAR INJECTED BELOW -->
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
                    <li class="nav-item"><a class="nav-link" href="index.php">Clasificación</a></li>
                    <li class="nav-item"><a class="nav-link" href="estadisticas.php">Estadísticas</a></li>
                    <li class="nav-item"><a class="nav-link" href="jugadores.php">Jugadores</a></li>
                    <li class="nav-item"><a class="nav-link active" href="mercado.php">Mercado</a></li>
                    <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'capitan' || $_SESSION['role'] === 'admin')): ?>
                        <li class="nav-item"><a class="nav-link" href="tratos.php">Tratos</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><a class="nav-link" href="pizarra.php">Pizarra Táctica</a></li>
                    <li class="nav-item"><a class="nav-link" href="calendario.php">Calendario</a></li>

                    <li class="nav-item ms-lg-3 d-flex align-items-center">
                        <a class="nav-link text-white me-3 d-flex align-items-center" href="profile/" style="padding: 0;">
                            <?php if (!empty($me['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($me['profile_picture']); ?>" alt="Perfil" class="rounded-circle shadow-sm" style="width: 36px; height: 36px; object-fit: cover; border: 2px solid #0d6efd;">
                            <?php else: ?>
                                <div class="rounded-circle bg-secondary shadow-sm d-flex justify-content-center align-items-center text-white fw-bold" style="width: 36px; height: 36px; font-size: 16px; border: 2px solid #6c757d;">
                                    <?php echo strtoupper(substr($me['username'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                            <span class="ms-2 d-none d-lg-inline fw-semibold"><?php echo htmlspecialchars($me['username']); ?></span>
                        </a>
                        <a class="btn btn-outline-danger btn-sm" href="./login/logout.php">Cerrar Sesión</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container mt-4 mb-5 px-3">
        <?php if ($error): ?>
            <div class="alert alert-danger shadow"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success shadow"><i class="bi bi-check-circle-fill"></i> <?php echo $success; ?></div>
        <?php endif; ?>

        <!-- HEADER MERCADO -->
        <div class="row mb-4 align-items-center bg-dark p-4 rounded-4 shadow border border-secondary">
            <div class="col-md-6 text-center text-md-start mb-3 mb-md-0">
                <h1 class="display-5 fw-bold text-light mb-1"><i class="bi bi-shop text-primary"></i> Mercado Libre</h1>
                <div class="d-inline-block px-3 py-1 rounded-pill mt-2 <?php echo $isMarketOpen ? 'bg-success text-white' : 'bg-danger text-white'; ?> fw-bold shadow-sm">
                    <i class="bi <?php echo $isMarketOpen ? 'bi-unlock-fill' : 'bi-lock-fill'; ?>"></i> 
                    MERCADO <?php echo $isMarketOpen ? 'ABIERTO' : 'CERRADO'; ?>
                </div>
            </div>
            
            <?php if ($myTeamId): ?>
            <div class="col-md-6 text-center text-md-end">
                <div class="d-inline-block text-start p-3 bg-gradient bg-secondary bg-opacity-25 rounded-3 border border-secondary shadow-sm">
                    <span class="d-block text-uppercase small fw-bold text-muted mb-1">Presupuesto Disponible (<span class="text-light"><?php echo htmlspecialchars($me['team_name']); ?></span>)</span>
                    <h2 class="m-0 text-success fw-bold"><i class="bi bi-bank"></i> <?php echo number_format($me['budget'] ?? 0, 2); ?> <small class="text-muted fs-6">monedas</small></h2>
                </div>
                <?php if ($me['role'] === 'admin'): ?>
                    <a href="admin_mercado.php" class="btn btn-sm btn-outline-warning mt-2 d-block w-50 ms-auto">Panel Admin</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="row g-4">
            
            <!-- MI EQUIPO (PROTECCIONES) -->
            <?php if ($myTeamId): ?>
            <div class="col-lg-4">
                <div class="card bg-dark border-secondary shadow h-100">
                    <div class="card-header border-secondary bg-transparent d-flex justify-content-between align-items-center">
                        <span class="fw-bold text-light"><i class="bi bi-shield-lock text-info"></i> Tus Jugadores (Pilares)</span>
                        <span class="badge bg-secondary"><?php echo $protectedCount; ?>/2 Protegidos</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="alert-dark small p-2 m-0 border-0 border-bottom border-secondary rounded-0 text-muted">
                            <i class="bi bi-info-circle"></i> Antes de que abra el mercado, el capitán puede bloquear a 2 jugadores para evitar que se los roben.
                        </div>
                        <ul class="list-group list-group-flush bg-transparent">
                            <?php foreach($myPlayers as $mp): ?>
                                <li class="list-group-item bg-transparent text-light border-secondary d-flex justify-content-between align-items-center">
                                    <div class="d-flex align-items-center">
                                        <?php if (!empty($mp['profile_picture'])): ?>
                                            <img src="<?php echo htmlspecialchars($mp['profile_picture']); ?>" class="rounded-circle me-2 object-fit-cover border border-secondary" style="width:30px;height:30px;">
                                        <?php else: ?>
                                            <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold" style="width:30px;height:30px;font-size:12px;">
                                                <?php echo strtoupper(substr($mp['username'],0,1)); ?>
                                            </div>
                                        <?php endif; ?>
                                        <span class="<?php echo $mp['is_protected'] ? 'fw-bold text-info' : ''; ?>"><?php echo htmlspecialchars($mp['username']); ?></span>
                                    </div>
                                    
                                    <div>
                                        <?php if ($isCaptain && !$isMarketOpen): ?>
                                            <form method="POST" class="m-0">
                                                <input type="hidden" name="action" value="toggle_protect">
                                                <input type="hidden" name="player_id" value="<?php echo $mp['id']; ?>">
                                                <input type="hidden" name="protect_state" value="<?php echo $mp['is_protected'] ? '0' : '1'; ?>">
                                                <?php if ($mp['is_protected']): ?>
                                                    <button type="submit" class="btn btn-sm btn-info text-dark fw-bold rounded-pill" title="Desproteger"><i class="bi bi-lock-fill"></i></button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary rounded-pill" <?php echo ($protectedCount >= 2) ? 'disabled' : ''; ?> title="Proteger"><i class="bi bi-unlock"></i></button>
                                                <?php endif; ?>
                                            </form>
                                        <?php else: ?>
                                            <?php if ($mp['is_protected']): ?>
                                                <span class="badge bg-info text-dark rounded-pill"><i class="bi bi-lock-fill"></i> Protegido</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            
            <!-- EL MERCADO -->
            <div class="col-lg-8">
                <div class="card bg-dark border-secondary shadow h-100">
                    <div class="card-header border-secondary bg-transparent fw-bold text-light d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-cart3 text-warning"></i> Jugadores Libres</span>
                        <span class="badge bg-danger shadow-sm">Máx. 1 jugador del mismo equipo</span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover align-middle mb-0">
                                <thead class="table-secondary text-dark border-secondary small">
                                    <tr>
                                        <th>Jugador</th>
                                        <th>Club Actual</th>
                                        <th class="text-end">Precio (∑ Media)</th>
                                        <th class="text-center">Acción</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach($marketPlayers as $mp): ?>
                                        <?php if ($mp['is_protected']) continue; // No enseñar los bloqueados en el mercado ?>
                                        <tr>
                                            <td>
                                                <div class="fw-bold d-flex align-items-center">
                                                    <?php if (!empty($mp['profile_picture'])): ?>
                                                        <img src="<?php echo htmlspecialchars($mp['profile_picture']); ?>" class="rounded-circle me-2 object-fit-cover border border-secondary shadow-sm" style="width:32px;height:32px;">
                                                    <?php else: ?>
                                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold shadow-sm" style="width:32px;height:32px;font-size:12px;">
                                                            <?php echo strtoupper(substr($mp['username'],0,1)); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php echo htmlspecialchars($mp['username']); ?>
                                                </div>
                                            </td>
                                            <td class="text-muted small"><?php echo htmlspecialchars($mp['team_name']); ?></td>
                                            <td class="text-end text-success fw-bold fs-5">
                                                <?php echo number_format($mp['price'], 2); ?> <i class="bi bi-coin small"></i>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($isMarketOpen && $isCaptain): ?>
                                                    <form method="POST" class="m-0" onsubmit="return confirm('¿Seguro que quieres fichar a <?php echo htmlspecialchars(addslashes($mp['username'])); ?> por <?php echo number_format($mp['price'],2); ?>?');">
                                                        <input type="hidden" name="action" value="buy_player">
                                                        <input type="hidden" name="player_id" value="<?php echo $mp['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success fw-bold px-3 py-1 shadow" <?php echo ($me['budget'] < $mp['price']) ? 'disabled' : ''; ?>><i class="bi bi-check-lg"></i> Comprar</button>
                                                    </form>
                                                <?php elseif (!$isCaptain): ?>
                                                    <span class="badge bg-secondary">Solo Capitanes</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Mercado Cerrado</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
        </div>

        <!-- ACTIVIDAD RECIENTE -->
        <h4 class="mt-5 mb-3 border-bottom border-secondary pb-2"><i class="bi bi-activity text-danger"></i> Últimos Traspasos</h4>
        <div class="row row-cols-1 row-cols-md-2 row-cols-lg-3 g-3 mb-5">
            <?php if (count($transfers) > 0): ?>
                <?php foreach($transfers as $tr): ?>
                    <div class="col">
                        <div class="card bg-dark border-secondary shadow-sm h-100">
                            <div class="card-body p-3">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <h6 class="fw-bold text-light m-0"><i class="bi bi-person-fill text-primary"></i> <?php echo htmlspecialchars($tr['player_name']); ?></h6>
                                    <span class="badge bg-success shadow-sm"><?php echo number_format($tr['price'], 2); ?> <i class="bi bi-coin"></i></span>
                                </div>
                                <div class="small w-100 d-flex justify-content-between text-muted align-items-center mt-2 border-top border-secondary pt-2">
                                    <div class="text-truncate" style="max-width: 40%;"><span class="text-danger"><i class="bi bi-arrow-up-right"></i> <?php echo htmlspecialchars($tr['seller_name']); ?></span></div>
                                    <i class="bi bi-arrow-right"></i>
                                    <div class="text-truncate text-end" style="max-width: 40%;"><span class="text-success"><i class="bi bi-arrow-down-left"></i> <?php echo htmlspecialchars($tr['buyer_name']); ?></span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12 text-muted text-center w-100 py-4 border border-secondary rounded">
                    Aún no se ha realizado ninguna venta en este mercado.
                </div>
            <?php endif; ?>
        </div>

        <!-- INGRESOS POR JORNADA -->
        <?php
        $financeHistory = $pdo->query("
            SELECT tf.*, t.name as team_name, m.match_date,
                   t1.name as team1_name, t2.name as team2_name
            FROM team_finances tf
            JOIN teams t ON tf.team_id = t.id
            JOIN matches m ON tf.match_id = m.id
            JOIN teams t1 ON m.team1_id = t1.id
            JOIN teams t2 ON m.team2_id = t2.id
            ORDER BY m.match_date DESC, t.name ASC
            LIMIT 60
        ")->fetchAll();
        ?>
        <h4 class="mt-5 mb-3 border-bottom border-secondary pb-2"><i class="bi bi-cash-stack text-warning"></i> Ingresos por Jornada</h4>
        <?php if (count($financeHistory) > 0): ?>
        <div class="table-responsive mb-5 shadow rounded">
            <table class="table table-dark table-hover align-middle mb-0 border border-secondary">
                <thead class="table-secondary text-dark small">
                    <tr>
                        <th>Partido</th>
                        <th>Fecha</th>
                        <th>Equipo</th>
                        <th class="text-end">Ingreso Recibido</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($financeHistory as $f): ?>
                    <tr>
                        <td class="small text-muted">
                            <?php echo htmlspecialchars($f['team1_name']); ?> vs <?php echo htmlspecialchars($f['team2_name']); ?>
                        </td>
                        <td class="small text-muted"><?php echo date('d/m/Y', strtotime($f['match_date'])); ?></td>
                        <td class="fw-bold text-primary"><?php echo htmlspecialchars($f['team_name']); ?></td>
                        <td class="text-end text-success fw-bold">+<?php echo number_format($f['amount'], 2); ?> <i class="bi bi-coin small"></i></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-secondary text-muted mb-5">Aún no se han repartido ingresos de ninguna jornada.</div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

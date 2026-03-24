<?php
session_start();
require_once __DIR__ . '/db.php';

// Mini-API para cargar jugadores por equipo (AJAX)
if (isset($_GET['get_players'])) {
    header('Content-Type: application/json');
    $teamId = (int)$_GET['get_players'];
    $stmt = $pdo->prepare("SELECT id, username FROM users WHERE team_id = ? ORDER BY username");
    $stmt->execute([$teamId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// Auth check
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$myUserId = $_SESSION['user_id'];

// Load my info
$stmtMe = $pdo->prepare("SELECT u.*, t.name as team_name, t.budget as db_budget FROM users u LEFT JOIN teams t ON u.team_id = t.id WHERE u.id = ?");
$stmtMe->execute([$myUserId]);
$me = $stmtMe->fetch();
$myTeamId = $me['team_id'];
$isCaptain = ($me['role'] === 'capitan');
$isAdmin = ($me['role'] === 'admin');

// --- COMPROBAR PRESUPUESTO DINÁMICO ---
if ($myTeamId) {
    // 1. Partidos jugados
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

    // 3. Ajuste de presupuesto (DB)
    $dbBudgetAdjustment = (float)($me['db_budget'] ?? 0);
    $me['budget'] = $teamRating + (1.0 * $matchesPlayed) + $dbBudgetAdjustment;
}

if (!$isCaptain && !$isAdmin) {
    die("Acceso denegado. Solo los capitanes y administradores pueden gestionar tratos.");
}

$error = '';
$success = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- PROPOSE TRADE ---
    if ($action === 'propose_trade') {
        $receiverTeamId = (int)$_POST['receiver_team_id'];
        $offeredPlayers = !empty($_POST['offered_players']) ? implode(',', array_map('intval', $_POST['offered_players'])) : '';
        $requestedPlayers = !empty($_POST['requested_players']) ? implode(',', array_map('intval', $_POST['requested_players'])) : '';
        $offeredMoney = (float)$_POST['offered_money'];
        $requestedMoney = (float)$_POST['requested_money'];

        if ($receiverTeamId == $myTeamId) {
            $error = "No puedes proponer un trato a tu propio equipo.";
        } else {
            $stmt = $pdo->prepare("INSERT INTO trades (proposer_team_id, receiver_team_id, offered_player_ids, requested_player_ids, offered_money, requested_money) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$myTeamId, $receiverTeamId, $offeredPlayers, $requestedPlayers, $offeredMoney, $requestedMoney]);
            $success = "Propuesta de trato enviada correctamente.";
        }
    }

    // --- CANCEL TRADE ---
    if ($action === 'cancel_trade') {
        $tradeId = (int)$_POST['trade_id'];
        $stmt = $pdo->prepare("UPDATE trades SET status = 'cancelled' WHERE id = ? AND proposer_team_id = ? AND status = 'pending'");
        $stmt->execute([$tradeId, $myTeamId]);
        $success = "Trato cancelado.";
    }

    // --- REJECT TRADE ---
    if ($action === 'reject_trade') {
        $tradeId = (int)$_POST['trade_id'];
        $stmt = $pdo->prepare("UPDATE trades SET status = 'rejected' WHERE id = ? AND receiver_team_id = ? AND status = 'pending'");
        $stmt->execute([$tradeId, $myTeamId]);
        $success = "Trato rechazado.";
    }

    // --- ACCEPT TRADE ---
    if ($action === 'accept_trade') {
        $tradeId = (int)$_POST['trade_id'];
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT * FROM trades WHERE id = ? AND receiver_team_id = ? AND status = 'pending' FOR UPDATE");
            $stmt->execute([$tradeId, $myTeamId]);
            $trade = $stmt->fetch();

            if ($trade) {
                // 1. Move players
                if (!empty($trade['offered_player_ids'])) {
                    $offeredIds = explode(',', $trade['offered_player_ids']);
                    foreach ($offeredIds as $pid) {
                        $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?")->execute([$trade['receiver_team_id'], $pid]);
                    }
                }
                if (!empty($trade['requested_player_ids'])) {
                    $requestedIds = explode(',', $trade['requested_player_ids']);
                    foreach ($requestedIds as $pid) {
                        $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ?")->execute([$trade['proposer_team_id'], $pid]);
                    }
                }

                // 2. Adjust money
                // Proposer Team
                $proposerAdj = $trade['requested_money'] - $trade['offered_money'];
                $pdo->prepare("UPDATE teams SET budget = budget + ? WHERE id = ?")->execute([$proposerAdj, $trade['proposer_team_id']]);
                
                // Receiver Team (Me)
                $receiverAdj = $trade['offered_money'] - $trade['requested_money'];
                $pdo->prepare("UPDATE teams SET budget = budget + ? WHERE id = ?")->execute([$receiverAdj, $trade['receiver_team_id']]);

                // 3. Mark as accepted
                $pdo->prepare("UPDATE trades SET status = 'accepted' WHERE id = ?")->execute([$tradeId]);

                $pdo->commit();
                $success = "Trato aceptado. Los jugadores y el presupuesto se han actualizado.";
            } else {
                $pdo->rollBack();
                $error = "No se pudo encontrar el trato o ya no está disponible.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Error al procesar el trato: " . $e->getMessage();
        }
    }
}

// Fetch data for the UI
$allTeams = $pdo->query("SELECT id, name FROM teams WHERE id != " . (int)$myTeamId . " ORDER BY name")->fetchAll();

// My players
$myPlayers = $pdo->prepare("SELECT id, username FROM users WHERE team_id = ? ORDER BY username");
$myPlayers->execute([$myTeamId]);
$myPlayers = $myPlayers->fetchAll();

// Pending offers (Received)
$stmtReceived = $pdo->prepare("
    SELECT t.*, tm.name as proposer_team_name 
    FROM trades t 
    JOIN teams tm ON t.proposer_team_id = tm.id 
    WHERE t.receiver_team_id = ? AND t.status = 'pending'
    ORDER BY t.created_at DESC
");
$stmtReceived->execute([$myTeamId]);
$receivedOffers = $stmtReceived->fetchAll();

// Sent offers (Sent)
$stmtSent = $pdo->prepare("
    SELECT t.*, tm.name as receiver_team_name 
    FROM trades t 
    JOIN teams tm ON t.receiver_team_id = tm.id 
    WHERE t.proposer_team_id = ? AND t.status = 'pending'
    ORDER BY t.created_at DESC
");
$stmtSent->execute([$myTeamId]);
$sentOffers = $stmtSent->fetchAll();

// Function to get player names from IDs
function getPlayerNames($ids, $pdo) {
    if (empty($ids)) return 'Ninguno';
    $stmt = $pdo->prepare("SELECT username FROM users WHERE id IN ($ids)");
    $stmt->execute();
    return implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Tratos - Goats League</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
    <style>
        .trade-card { transition: transform 0.2s; border-left: 5px solid #0d6efd; }
        .trade-card:hover { transform: translateY(-3px); }
        .badge-money { font-size: 0.9em; }
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
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="mercado.php">Mercado</a></li>
                    <li class="nav-item"><a class="nav-link active" href="tratos.php">Tratos</a></li>
                    <li class="nav-item"><a class="nav-link" href="index.php">Volver</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <h2 class="mb-4 d-flex align-items-center justify-content-between">
            <span><i class="bi bi-arrow-left-right text-primary me-2"></i> Centro de Tratos</span>
            <?php if (isset($me['budget'])): ?>
                <span class="badge bg-success fs-5">Presupuesto: <?php echo number_format($me['budget'], 2); ?> €</span>
            <?php endif; ?>
        </h2>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>

        <div class="row">
            <!-- Columna de proponer trato -->
            <div class="col-lg-4 mb-4">
                <div class="card bg-dark border-secondary shadow-sm">
                    <div class="card-header bg-primary text-white fw-bold">
                        <i class="bi bi-plus-circle me-1"></i> Proponer Nuevo Trato
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="action" value="propose_trade">
                            
                            <div class="mb-3">
                                <label class="form-label text-muted small fw-bold">EQUIPO RIVAL</label>
                                <select name="receiver_team_id" id="receiver-team" class="form-select bg-dark text-white border-secondary" required onchange="loadTargetPlayers(this.value)">
                                    <option value="">Selecciona equipo...</option>
                                    <?php foreach ($allTeams as $t): ?>
                                        <option value="<?php echo $t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <hr class="border-secondary my-3">

                            <div class="mb-3">
                                <label class="form-label text-success small fw-bold">TÚ OFRECES</label>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Jugadores de tu equipo:</label>
                                    <?php foreach ($myPlayers as $p): ?>
                                        <div class="form-check small">
                                            <input class="form-check-input" type="checkbox" name="offered_players[]" value="<?php echo $p['id']; ?>" id="op<?php echo $p['id']; ?>">
                                            <label class="form-check-label" for="op<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['username']); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-dark text-success border-secondary">€</span>
                                    <input type="number" step="0.5" name="offered_money" class="form-control bg-dark text-white border-secondary" placeholder="Dinero extra..." value="0">
                                </div>
                            </div>

                            <hr class="border-secondary my-3">

                            <div class="mb-3">
                                <label class="form-label text-warning small fw-bold">TÚ PIDES</label>
                                <div class="mb-2">
                                    <label class="form-label small mb-1">Jugadores del rival:</label>
                                    <div id="target-players-list" class="small text-muted italic">Selecciona un equipo para ver sus jugadores...</div>
                                </div>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-dark text-warning border-secondary">€</span>
                                    <input type="number" step="0.5" name="requested_money" class="form-control bg-dark text-white border-secondary" placeholder="Dinero que pides..." value="0">
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary w-100 fw-bold mt-2">ENVIAR PROPUESTA</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Columna de ofertas -->
            <div class="col-lg-8">
                <!-- Recibidas -->
                <h4 class="mb-3"><i class="bi bi-inbox me-2"></i> Ofertas Recibidas</h4>
                <?php if (count($receivedOffers) === 0): ?>
                    <p class="text-muted italic">No tienes ofertas pendientes.</p>
                <?php else: ?>
                    <?php foreach ($receivedOffers as $tr): ?>
                        <div class="card bg-dark border-secondary mb-3 trade-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0 text-primary"><?php echo htmlspecialchars($tr['proposer_team_name']); ?></h5>
                                    <small class="text-muted"><?php echo date('d/m/Y H:i', strtotime($tr['created_at'])); ?></small>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-5">
                                        <div class="p-2 border border-success border-opacity-25 rounded bg-success bg-opacity-10 h-100">
                                            <div class="small fw-bold text-success mb-1">ELLOS DAN:</div>
                                            <div class="small"><?php echo getPlayerNames($tr['offered_player_ids'], $pdo); ?></div>
                                            <?php if ($tr['offered_money'] > 0): ?>
                                                <div class="mt-1 badge bg-success">+<?php echo number_format($tr['offered_money'], 2); ?> €</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-center justify-content-center">
                                        <i class="bi bi-arrow-left-right fs-4 text-muted"></i>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="p-2 border border-warning border-opacity-25 rounded bg-warning bg-opacity-10 h-100">
                                            <div class="small fw-bold text-warning mb-1">TÚ DAS:</div>
                                            <div class="small"><?php echo getPlayerNames($tr['requested_player_ids'], $pdo); ?></div>
                                            <?php if ($tr['requested_money'] > 0): ?>
                                                <div class="mt-1 badge bg-warning">+<?php echo number_format($tr['requested_money'], 2); ?> €</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 d-flex gap-2">
                                    <form method="POST" class="flex-grow-1">
                                        <input type="hidden" name="action" value="accept_trade">
                                        <input type="hidden" name="trade_id" value="<?php echo $tr['id']; ?>">
                                        <button type="submit" class="btn btn-success w-100 btn-sm fw-bold">ACEPTAR</button>
                                    </form>
                                    <form method="POST" class="flex-grow-1">
                                        <input type="hidden" name="action" value="reject_trade">
                                        <input type="hidden" name="trade_id" value="<?php echo $tr['id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger w-100 btn-sm">RECHAZAR</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Enviadas -->
                <h4 class="mt-5 mb-3"><i class="bi bi-send me-2"></i> Ofertas Enviadas</h4>
                <?php if (count($sentOffers) === 0): ?>
                    <p class="text-muted italic">No has enviado ninguna oferta.</p>
                <?php else: ?>
                    <?php foreach ($sentOffers as $tr): ?>
                        <div class="card bg-dark border-secondary mb-3 trade-card" style="border-left-color: #6c757d;">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h5 class="mb-0 text-muted">A: <?php echo htmlspecialchars($tr['receiver_team_name']); ?></h5>
                                    <span class="badge bg-warning text-dark">Pendiente</span>
                                </div>
                                <div class="row g-3 opacity-75">
                                    <div class="col-md-5">
                                        <div class="p-2 border border-success border-opacity-25 rounded">
                                            <div class="small fw-bold text-success mb-1">TÚ DAS:</div>
                                            <div class="small"><?php echo getPlayerNames($tr['offered_player_ids'], $pdo); ?></div>
                                            <?php if ($tr['offered_money'] > 0): ?>
                                                <div class="mt-1 text-success fw-bold small">+<?php echo number_format($tr['offered_money'], 2); ?> €</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-center justify-content-center">
                                        <i class="bi bi-arrow-right fs-4 text-muted"></i>
                                    </div>
                                    <div class="col-md-5">
                                        <div class="p-2 border border-warning border-opacity-25 rounded">
                                            <div class="small fw-bold text-warning mb-1">TÚ PIDES:</div>
                                            <div class="small"><?php echo getPlayerNames($tr['requested_player_ids'], $pdo); ?></div>
                                            <?php if ($tr['requested_money'] > 0): ?>
                                                <div class="mt-1 text-warning fw-bold small">+<?php echo number_format($tr['requested_money'], 2); ?> €</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="mt-3 text-end">
                                    <form method="POST">
                                        <input type="hidden" name="action" value="cancel_trade">
                                        <input type="hidden" name="trade_id" value="<?php echo $tr['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-link text-danger text-decoration-none">Cancelar oferta</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- API simple para cargar jugadores por equipo (simulado con inline scripts para rapidez) -->
    <script>
    function loadTargetPlayers(teamId) {
        const list = document.getElementById('target-players-list');
        if (!teamId) {
            list.innerHTML = 'Selecciona un equipo para ver sus jugadores...';
            return;
        }
        list.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"></div> Cargando...';
        
        // En una app real usaríamos fetch() a un endpoint API. 
        // Aquí podemos incrustar los jugadores en un objeto JS al cargar la página si son pocos,
        // o usar data.php si existe. Vamos a usar fetch(tratos.php?get_players=teamId)
        fetch('tratos.php?get_players=' + teamId)
            .then(r => r.json())
            .then(data => {
                if (data.length === 0) {
                    list.innerHTML = 'Este equipo no tiene jugadores.';
                } else {
                    list.innerHTML = '';
                    data.forEach(p => {
                        const div = document.createElement('div');
                        div.className = 'form-check small';
                        div.innerHTML = `
                            <input class="form-check-input" type="checkbox" name="requested_players[]" value="${p.id}" id="rp${p.id}">
                            <label class="form-check-label" for="rp${p.id}">${p.username}</label>
                        `;
                        list.appendChild(div);
                    });
                }
            })
            .catch(() => {
                list.innerHTML = '<span class="text-danger">Error al cargar jugadores.</span>';
            });
    }

    </script>
</body>
</html>

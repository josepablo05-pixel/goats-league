<?php
session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/', 'httponly' => true, 'secure' => true, 'samesite' => 'Lax']);
session_start();
require_once __DIR__ . '/db.php';

// Auth and Admin Check
if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$stmt = $pdo->prepare("SELECT role, profile_picture, username FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$meInfo = $stmt->fetch();
if (!$meInfo || $meInfo['role'] !== 'admin') { header("Location: index.php"); exit; }

// --- DATABASE AUTO-FIX ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS team_finances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        team_id INT NOT NULL,
        match_id INT NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Comprobar si existe revenue_paid en matches
    $checkMatchCol = $pdo->query("SHOW COLUMNS FROM matches LIKE 'revenue_paid'");
    if (!$checkMatchCol->fetch()) {
        $pdo->exec("ALTER TABLE matches ADD COLUMN revenue_paid TINYINT(1) DEFAULT 0");
    }
} catch (PDOException $e) {}
// --------------------------

// Toggle Market Status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'toggle_market') {
        $newState = (int)$_POST['state'];
        $pdo->exec("UPDATE settings SET setting_value = '$newState' WHERE setting_key = 'market_open'");
        header("Location: admin_mercado.php?msg=" . ($newState ? 'market_opened' : 'market_closed'));
        exit;
    }
    
    // Distribute Funds Calculation
    if ($_POST['action'] === 'payout') {
        $stmtMatches = $pdo->query("SELECT id, team1_id, team2_id FROM matches WHERE status = 'finished' AND revenue_paid = 0");
        $matches = $stmtMatches->fetchAll();
        $payoutCount = 0;
        
        if (!empty($matches)) {
            $matchIds = array_column($matches, 'id');
            $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
            
            // Bulk fetch all relevant match ratings
            $stmtRatings = $pdo->prepare("
                SELECT mr.match_id, mr.target_id, u.team_id, AVG(mr.rating) as p_avg
                FROM match_ratings mr
                JOIN users u ON mr.target_id = u.id
                WHERE mr.match_id IN ($placeholders)
                GROUP BY mr.match_id, u.team_id, mr.target_id
            ");
            $stmtRatings->execute($matchIds);
            $allRatings = $stmtRatings->fetchAll();

            $teamMatchRatings = [];
            foreach ($allRatings as $r) {
                $mId = $r['match_id'];
                $tId = $r['team_id'];
                $teamMatchRatings[$mId][$tId][] = (float)$r['p_avg'];
            }

            $pdo->beginTransaction();
            try {
                $updateTeamStmt = $pdo->prepare("UPDATE teams SET budget = budget + ? WHERE id = ?");
                $insertFinanceStmt = $pdo->prepare("INSERT INTO team_finances (team_id, match_id, amount) VALUES (?, ?, ?)");
                $updateMatchStmt = $pdo->prepare("UPDATE matches SET revenue_paid = 1 WHERE id = ?");
                
                foreach ($matches as $match) {
                    $mId = $match['id'];
                    $t1 = $match['team1_id'];
                    $t2 = $match['team2_id'];

                    foreach ([$t1, $t2] as $tid) {
                        if (isset($teamMatchRatings[$mId][$tid])) {
                            $ratings = $teamMatchRatings[$mId][$tid];
                            rsort($ratings); // Sort descending to get top 7
                            $top = array_slice($ratings, 0, 7);

                            $tAvg = 0;
                            if (count($top) > 0) {
                                $tAvg = array_sum($top) / count($top);
                            }

                            if ($tAvg > 0) {
                                // Give money
                                $updateTeamStmt->execute([$tAvg, $tid]);
                                // Log finances
                                $insertFinanceStmt->execute([$tid, $mId, $tAvg]);
                            }
                        }
                    }
                    // Mark as paid
                    $updateMatchStmt->execute([$mId]);
                    $payoutCount++;
                }
                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        header("Location: admin_mercado.php?msg=paid&count=" . $payoutCount);
        exit;
    }
}

// Data fetching for UI
$mktStmt = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'market_open'");
$isMarketOpen = (bool)$mktStmt->fetchColumn();

$teams = $pdo->query("SELECT * FROM teams ORDER BY budget DESC")->fetchAll();

$history = $pdo->query("
    SELECT tf.*, t.name as team_name 
    FROM team_finances tf 
    JOIN teams t ON tf.team_id = t.id 
    ORDER BY tf.created_at DESC LIMIT 50
")->fetchAll();

$unpaidCount = $pdo->query("SELECT COUNT(*) FROM matches WHERE status = 'finished' AND revenue_paid = 0")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Mercado - Goats League</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
</head>
<body class="text-white bg-dark">

    <div class="container mt-5 mb-5 px-3">
        <header class="d-flex justify-content-between align-items-center border-bottom border-light pb-3 mb-4">
            <div>
                <h2 class="fw-bold m-0"><i class="bi bi-shield-lock-fill text-danger me-2"></i> Panel Admin: Mercado</h2>
                <p class="text-muted small m-0 mt-1">Gestión de Presupuestos y Fichajes</p>
            </div>
            <a href="index.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Volver</a>
        </header>

        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'paid'): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle"></i> Repartidos los ingresos de <?php echo (int)$_GET['count']; ?> partido(s).</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'market_opened'): ?>
            <div class="alert alert-warning"><i class="bi bi-unlock"></i> Mercado ABIERTO. Los equipos ya pueden fichar.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] == 'market_closed'): ?>
            <div class="alert alert-info"><i class="bi bi-lock"></i> Mercado CERRADO. Ya no se pueden fichar jugadores.</div>
        <?php endif; ?>

        <div class="row g-4">
            <!-- ACCIONES -->
            <div class="col-md-6">
                <div class="card bg-dark border-secondary h-100 shadow">
                    <div class="card-header border-secondary bg-transparent fw-bold text-light">
                        <i class="bi bi-gear-fill text-primary"></i> Controles del Mercado
                    </div>
                    <div class="card-body">
                        
                        <!-- Toggle Mercado -->
                        <div class="d-flex align-items-center justify-content-between p-3 border border-secondary rounded mb-3 bg-gradient <?php echo $isMarketOpen ? 'bg-success bg-opacity-10 border-success' : 'bg-danger bg-opacity-10 border-danger'; ?>">
                            <div>
                                <h5 class="mb-1 <?php echo $isMarketOpen ? 'text-success' : 'text-danger'; ?>">
                                    Estado: <?php echo $isMarketOpen ? 'ABIERTO' : 'CERRADO'; ?>
                                </h5>
                                <small class="text-muted">Los capitanes <?php echo $isMarketOpen ? 'pueden' : 'no pueden'; ?> comprar jugadores ahora.</small>
                            </div>
                            <form method="POST">
                                <input type="hidden" name="action" value="toggle_market">
                                <input type="hidden" name="state" value="<?php echo $isMarketOpen ? '0' : '1'; ?>">
                                <button type="submit" class="btn <?php echo $isMarketOpen ? 'btn-danger' : 'btn-success'; ?> fw-bold">
                                    <?php echo $isMarketOpen ? '<i class="bi bi-lock-fill"></i> CERRAR MERCADO' : '<i class="bi bi-unlock-fill"></i> ABRIR MERCADO'; ?>
                                </button>
                            </form>
                        </div>

                        <!-- Reparto de Ingresos -->
                        <div class="p-3 border border-secondary rounded">
                            <h5 class="mb-2 text-warning"><i class="bi bi-cash-coin"></i> Reparto de Beneficios (Jornadas)</h5>
                            <p class="small text-muted mb-3">Hay <b><?php echo $unpaidCount; ?></b> partidos finalizados pendientes de pagar ingresos a los equipos. El ingreso se calcula en base a la media MVP de los 7 mejores jugadores por equipo en cada partido.</p>
                            <form method="POST">
                                <input type="hidden" name="action" value="payout">
                                <button type="submit" class="btn btn-warning fw-bold w-100" <?php echo $unpaidCount == 0 ? 'disabled' : ''; ?>>
                                    <i class="bi bi-bank"></i> REPARTIR DINERO (<?php echo $unpaidCount; ?> PARTIDOS PENDIENTES)
                                </button>
                            </form>
                        </div>

                    </div>
                </div>
            </div>

            <!-- PRESUPUESTOS -->
            <div class="col-md-6">
                <div class="card bg-dark border-secondary h-100 shadow">
                    <div class="card-header border-secondary bg-transparent fw-bold text-light">
                        <i class="bi bi-wallet-fill text-success"></i> Estado Financiero Actual
                    </div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush bg-transparent">
                            <?php foreach ($teams as $t): ?>
                                <li class="list-group-item bg-transparent text-light border-secondary d-flex justify-content-between align-items-center">
                                    <div class="fw-bold">
                                        <?php if(!empty($t['logo'])): ?>
                                            <img src="<?php echo htmlspecialchars($t['logo']); ?>" style="width:25px;height:25px;object-fit:cover;" class="rounded-circle me-1 border border-secondary">
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($t['name']); ?>
                                    </div>
                                    <span class="badge bg-success fs-6 rounded-pill">
                                        <?php echo number_format($t['budget'], 2); ?> <i class="bi bi-coin"></i>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- TABLA DE HISTORIAL DE INGRESOS -->
        <h4 class="mt-5 mb-3 border-bottom border-secondary pb-2"><i class="bi bi-journal-text text-info"></i> Historial de Ingresos por Jornada</h4>
        <div class="table-responsive">
            <table class="table table-dark table-hover table-bordered border-secondary align-middle">
                <thead class="table-secondary border-secondary text-dark">
                    <tr>
                        <th>Fecha y Hora</th>
                        <th>Equipo</th>
                        <th>ID Partido</th>
                        <th>Ingreso Recibido</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($history) > 0): ?>
                        <?php foreach($history as $h): ?>
                            <tr>
                                <td class="text-muted small"><?php echo date('d/m/Y H:i', strtotime($h['created_at'])); ?></td>
                                <td class="fw-bold text-primary"><?php echo htmlspecialchars($h['team_name']); ?></td>
                                <td class="text-center text-muted">#<?php echo $h['match_id']; ?></td>
                                <td class="text-success fw-bold">+<?php echo number_format($h['amount'], 2); ?> <i class="bi bi-coin"></i></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="text-center text-muted">Aún no se ha repartido dinero de ninguna jornada.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

</body>
</html>

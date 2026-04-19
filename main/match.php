<?php
session_set_cookie_params(['lifetime' => 86400 * 30, 'path' => '/']);
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: calendario.php');
    exit;
}

$matchId = (int)$_GET['id'];
$userId = $_SESSION['user_id'] ?? null;
$userRole = $_SESSION['role'] ?? null;

// Obtener detalles del partido
$stmt = $pdo->prepare("
    SELECT m.*, 
           t1.name as team1_name, t1.logo as team1_logo,
           t2.name as team2_name, t2.logo as team2_logo
    FROM matches m
    JOIN teams t1 ON m.team1_id = t1.id
    JOIN teams t2 ON m.team2_id = t2.id
    WHERE m.id = ?
");
$stmt->execute([$matchId]);
$match = $stmt->fetch();

// AUTO-CERRAR VOTACIONES DESPUÉS DE 3 DÍAS
if ($match && $match['status'] === 'finished' && $match['voting_closed'] == 0 && !empty($match['match_date'])) {
    $matchDate = strtotime($match['match_date']);
    $threeDaysLater = $matchDate + (3 * 86400); // 3 días
    // Auto-cierre
    if (time() >= $threeDaysLater) {
        $pdo->prepare("UPDATE matches SET voting_closed = 1 WHERE id = ?")->execute([$matchId]);
        $match['voting_closed'] = 1;
        
        // Recalcular medias para todos los jugadores que recibieron votos en este partido
        $pdo->prepare("
            UPDATE users u 
            SET rating = COALESCE((
                SELECT AVG(mr.rating) 
                FROM match_ratings mr 
                JOIN matches m ON mr.match_id = m.id 
                WHERE mr.target_id = u.id AND m.voting_closed = 1
            ), 0)
            WHERE u.id IN (SELECT target_id FROM match_ratings WHERE match_id = ?)
        ")->execute([$matchId]);
    }
}

if (!$match) {
    header('Location: calendario.php');
    exit;
}

$isAdmin = ($userRole === 'admin');
$myTeamId = null;
$isCaptainOfPlayingTeam = false;
$imPlayingInMatch = false;

if ($userId) {
    $stmtMe = $pdo->prepare("SELECT team_id, role FROM users WHERE id = ?");
    $stmtMe->execute([$userId]);
    $me = $stmtMe->fetch();
    
    if ($me && ($me['team_id'] == $match['team1_id'] || $me['team_id'] == $match['team2_id'])) {
        $myTeamId = $me['team_id'];
        if ($me['role'] === 'capitan') {
            $isCaptainOfPlayingTeam = true;
        }
    }
}

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Acción: Capitán o Admin añade un jugador a la alineación
    if ($action === 'add_lineup' && ($isAdmin || ($isCaptainOfPlayingTeam && $match['status'] === 'pending'))) {
        $playerId = (int)$_POST['player_id'];
        $teamIdForLineup = $isAdmin ? (int)$_POST['team_id'] : $myTeamId;
        try {
            $stmt = $pdo->prepare("INSERT IGNORE INTO match_lineups (match_id, team_id, player_id) VALUES (?, ?, ?)");
            $stmt->execute([$matchId, $teamIdForLineup, $playerId]);
        } catch (PDOException $e) {}
    }
    
    // Acción: Capitán o Admin elimina a un jugador de la alineación
    if ($action === 'remove_lineup' && ($isAdmin || ($isCaptainOfPlayingTeam && $match['status'] === 'pending'))) {
        $playerId = (int)$_POST['player_id'];
        $teamIdForLineup = $isAdmin ? (int)$_POST['team_id'] : $myTeamId;
        
        $stmt = $pdo->prepare("DELETE FROM match_lineups WHERE match_id = ? AND team_id = ? AND player_id = ?");
        $stmt->execute([$matchId, $teamIdForLineup, $playerId]);

        // Si se elimina un jugador, borrar las valoraciones que haya recibido en este partido
        $stmtDelVotes = $pdo->prepare("DELETE FROM match_ratings WHERE match_id = ? AND target_id = ?");
        $stmtDelVotes->execute([$matchId, $playerId]);
        
        // Actualizar media global del jugador (solo de partidos cerrados)
        $stmtAvg = $pdo->prepare("
            UPDATE users SET rating = COALESCE((
                SELECT AVG(mr.rating) 
                FROM match_ratings mr 
                JOIN matches m ON mr.match_id = m.id 
                WHERE mr.target_id = ? AND m.voting_closed = 1
            ), 0) WHERE id = ?
        ");
        $stmtAvg->execute([$playerId, $playerId]);
    }
    
    // Acción: Capitán finaliza el partido y añade el resultado
    if ($action === 'finish_match' && ($isAdmin || $isCaptainOfPlayingTeam) && $match['status'] === 'pending') {
        $score1 = (int)$_POST['score1'];
        $score2 = (int)$_POST['score2'];
        $winnerId = null;
        if ($score1 > $score2) $winnerId = $match['team1_id'];
        elseif ($score2 > $score1) $winnerId = $match['team2_id'];
        
        $stmt = $pdo->prepare("UPDATE matches SET status = 'finished', team1_score = ?, team2_score = ?, winner_id = ? WHERE id = ?");
        $stmt->execute([$score1, $score2, $winnerId, $matchId]);
        header("Refresh:0");
        exit;
    }

    // Acción: Capitán o Admin añade estadísticas post-partido (Goles/Asistencias)
    if ($action === 'add_event' && ($isAdmin || $isCaptainOfPlayingTeam) && $match['status'] === 'finished') {
        $playerId = (int)$_POST['player_id'];
        $eventType = $_POST['event_type']; 
        
        $allowInsert = true;
        if (in_array($eventType, ['goal', 'assist', 'own_goal'])) {
            
            $stmtTeam = $pdo->prepare("SELECT team_id FROM users WHERE id = ?");
            $stmtTeam->execute([$playerId]);
            $pTeamId = $stmtTeam->fetchColumn();
            $opponentTeamId = ($pTeamId == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];

            // Calcular estadísticas actuales agrupadas
            $stmtStats = $pdo->prepare("
                SELECT u.team_id, me.event_type, COUNT(*) as count 
                FROM match_events me
                JOIN users u ON me.player_id = u.id
                WHERE me.match_id = ?
                GROUP BY u.team_id, me.event_type
            ");
            $stmtStats->execute([$matchId]);
            $stats = [];
            foreach ($stmtStats->fetchAll() as $row) {
                $stats[$row['team_id']][$row['event_type']] = (int)$row['count'];
            }
            
            $myGoals    = $stats[$pTeamId]['goal']     ?? 0;
            $myOwnGoals = $stats[$pTeamId]['own_goal'] ?? 0;
            $myAssists  = $stats[$pTeamId]['assist']   ?? 0;
            $oppGoals     = $stats[$opponentTeamId]['goal']     ?? 0;
            $oppOwnGoals  = $stats[$opponentTeamId]['own_goal'] ?? 0;
            
            if ($eventType === 'goal') {
                $officialScore = ($pTeamId == $match['team1_id']) ? $match['team1_score'] : $match['team2_score'];
                if (($myGoals + $oppOwnGoals) >= $officialScore) {
                    $allowInsert = false;
                    $statError = "El equipo oficialmente solo ha marcado $officialScore goles. No puedes adjudicar más (incluyendo goles en propia del rival).";
                }
            } elseif ($eventType === 'own_goal') {
                $officialOppScore = ($opponentTeamId == $match['team1_id']) ? $match['team1_score'] : $match['team2_score'];
                if (($oppGoals + $myOwnGoals) >= $officialOppScore) {
                    $allowInsert = false;
                    $statError = "No puedes marcar un gol en propia, el equipo rival ya tiene justificados todos sus goles oficiales ($officialOppScore).";
                }
            } elseif ($eventType === 'assist') {
                if ($myAssists >= $myGoals) {
                    $allowInsert = false;
                    $statError = "No puede haber más asistencias que goles normales del equipo.";
                }
            }
            
            if ($allowInsert) {
                $stmt = $pdo->prepare("INSERT INTO match_events (match_id, player_id, event_type) VALUES (?, ?, ?)");
                $stmt->execute([$matchId, $playerId, $eventType]);
            }
        }

        // Si viene de AJAX, devolver JSON y terminar aquí
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            if ($allowInsert) {
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'error' => $statError ?? 'Error desconocido.']);
            }
            exit;
        }
    }

    // Acción: Admin edita el resultado oficial
    if ($action === 'edit_score' && $isAdmin && $match['status'] === 'finished') {
        $score1 = (int)$_POST['score1'];
        $score2 = (int)$_POST['score2'];
        $winnerId = null;
        if ($score1 > $score2) $winnerId = $match['team1_id'];
        elseif ($score2 > $score1) $winnerId = $match['team2_id'];
        
        $stmt = $pdo->prepare("UPDATE matches SET team1_score = ?, team2_score = ?, winner_id = ? WHERE id = ?");
        $stmt->execute([$score1, $score2, $winnerId, $matchId]);
        header("Refresh:0");
        exit;
    }

    // Acción: Admin cambia la fecha y/o jornada del partido
    if ($action === 'update_match_details' && $isAdmin && $match['status'] === 'pending') {
        $newDate = !empty($_POST['new_date']) ? $_POST['new_date'] : null;
        $newJornada = (int)$_POST['new_jornada'];
        $stmt = $pdo->prepare("UPDATE matches SET match_date = ?, jornada = ? WHERE id = ?");
        $stmt->execute([$newDate, $newJornada, $matchId]);
        header("Refresh:0");
        exit;
    }

    // Acción: Admin cambia estado de votaciones (Abrir/Cerrar)
    if ($action === 'toggle_voting' && $isAdmin && $match['status'] === 'finished') {
        $newClosedStatus = $match['voting_closed'] ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE matches SET voting_closed = ? WHERE id = ?");
        $stmt->execute([$newClosedStatus, $matchId]);
        
        // Recalcular medias para todos los jugadores de este partido
        $pdo->prepare("
            UPDATE users u 
            SET rating = COALESCE((
                SELECT AVG(mr.rating) 
                FROM match_ratings mr 
                JOIN matches m ON mr.match_id = m.id 
                WHERE mr.target_id = u.id AND m.voting_closed = 1
            ), 0)
            WHERE u.id IN (SELECT target_id FROM match_ratings WHERE match_id = ?)
        ")->execute([$matchId]);
        
        header("Refresh:0");
        exit;
    }

    // Acción: Admin elimina una estadística o evento
    if ($action === 'remove_event' && $isAdmin && $match['status'] === 'finished') {
        $eventId = (int)$_POST['event_id'];
        $stmt = $pdo->prepare("DELETE FROM match_events WHERE id = ? AND match_id = ?");
        $stmt->execute([$eventId, $matchId]);
    }

    // Acción: Jugador valora a un rival
    if ($action === 'rate_player' && $userId && $match['status'] === 'finished') {
        if ($match['voting_closed']) {
            if (!empty($_POST['ajax'])) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => 'Las votaciones para este partido están cerradas.']);
                exit;
            }
            $_SESSION['error'] = "Las votaciones están cerradas.";
            header("Location: match.php?id=$matchId");
            exit;
        }

        $targetId = (int)$_POST['target_id'];
        $rating = (float)$_POST['rating'];
        $ok = false;
        $errorMsg = 'Error desconocido al valorar.';
        
        // Verificar que yo juegue en este partido
        $stmtCheckMe = $pdo->prepare("SELECT 1 FROM match_lineups WHERE match_id = ? AND player_id = ?");
        $stmtCheckMe->execute([$matchId, $userId]);
        if ($stmtCheckMe->fetchColumn()) {
            // Verificar que el otro jugador sea del equipo contrario y también haya jugado
            $opponentTeamId = ($myTeamId == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];
            $stmtCheckOpponent = $pdo->prepare("SELECT 1 FROM match_lineups WHERE match_id = ? AND player_id = ? AND team_id = ?");
            $stmtCheckOpponent->execute([$matchId, $targetId, $opponentTeamId]);
            
            if ($stmtCheckOpponent->fetchColumn()) {
                try {
                    // Guardar voto
                    $stmtRate = $pdo->prepare("INSERT INTO match_ratings (match_id, voter_id, target_id, rating) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating)");
                    $stmtRate->execute([$matchId, $userId, $targetId, $rating]);
                    
                    // Actualizar la media global del jugador objetivo (solo de partidos cerrados)
                    $stmtAvg = $pdo->prepare("
                        UPDATE users SET rating = COALESCE((
                            SELECT AVG(mr.rating) 
                            FROM match_ratings mr 
                            JOIN matches m ON mr.match_id = m.id 
                            WHERE mr.target_id = ? AND m.voting_closed = 1
                        ), 0) WHERE id = ?
                    ");
                    $stmtAvg->execute([$targetId, $targetId]);
                    
                    $ok = true;
                } catch (PDOException $e) {
                    $errorMsg = 'Error DB (ratings): ' . $e->getMessage();
                }
            } else {
                $errorMsg = 'El jugador que intentas valorar no jugó en el equipo contrario en este partido.';
            }
        } else {
            $errorMsg = 'No jugaste en este partido, no puedes valorar.';
        }

        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            if ($ok) {
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'error' => $errorMsg]);
            }
            exit;
        }
    }
}

// Obtener las alineaciones confirmadas
$stmtLineups = $pdo->prepare("
    SELECT ml.team_id, u.id, u.username, u.profile_picture 
    FROM match_lineups ml 
    JOIN users u ON ml.player_id = u.id 
    WHERE ml.match_id = ?
");
$stmtLineups->execute([$matchId]);
$lineupsRaw = $stmtLineups->fetchAll();
$lineups = [$match['team1_id'] => [], $match['team2_id'] => []];
foreach ($lineupsRaw as $l) {
    if (isset($lineups[$l['team_id']])) {
        $lineups[$l['team_id']][] = $l;
    }
    if ($l['id'] == $userId) {
        $imPlayingInMatch = true;
    }
}
$opponents = [];
if ($myTeamId) {
    $opponentTeamId = ($myTeamId == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];
    $opponents = $lineups[$opponentTeamId];
}

$myVotes = [];
if ($userId && $match['status'] === 'finished') {
    $stmtMyVotes = $pdo->prepare("SELECT target_id, rating FROM match_ratings WHERE match_id = ? AND voter_id = ?");
    $stmtMyVotes->execute([$matchId, $userId]);
    foreach ($stmtMyVotes->fetchAll() as $v) {
        $myVotes[$v['target_id']] = $v['rating'];
    }
}

// Media de notas recibidas por cada jugador en ESTE partido (sólo si las votaciones están cerradas)
$matchPlayerAvgs = [];
if ($match['voting_closed']) {
    $stmtMatchAvgs = $pdo->prepare("SELECT target_id, AVG(rating) as avg_rating, COUNT(rating) as votes FROM match_ratings WHERE match_id = ? GROUP BY target_id");
    $stmtMatchAvgs->execute([$matchId]);
    foreach ($stmtMatchAvgs->fetchAll() as $row) {
        $matchPlayerAvgs[$row['target_id']] = [
            'avg' => (float)$row['avg_rating'],
            'votes' => (int)$row['votes']
        ];
    }
}

$maxMatchAvg = -1;
if (!empty($matchPlayerAvgs)) {
    $maxMatchAvg = max(array_column($matchPlayerAvgs, 'avg'));
}

// Goles y asistencias por jugador en ESTE partido
$playerMatchStats = [];
$stmtStats = $pdo->prepare("SELECT player_id, event_type, COUNT(*) as count FROM match_events WHERE match_id = ? GROUP BY player_id, event_type");
$stmtStats->execute([$matchId]);
foreach ($stmtStats->fetchAll() as $row) {
    if (!isset($playerMatchStats[$row['player_id']])) {
        $playerMatchStats[$row['player_id']] = ['goal' => 0, 'assist' => 0];
    }
    if ($row['event_type'] === 'goal') {
        $playerMatchStats[$row['player_id']]['goal'] = (int)$row['count'];
    } elseif ($row['event_type'] === 'assist') {
        $playerMatchStats[$row['player_id']]['assist'] = (int)$row['count'];
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Partido: <?php echo htmlspecialchars($match['team1_name']); ?> vs <?php echo htmlspecialchars($match['team2_name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
</head>
<body class="text-white">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark border-bottom border-secondary">
        <div class="container-fluid">
            <a class="navbar-brand" href="index.php">⚽ Goats League</a>
            <!-- Botón volver visible siempre (móvil y pc) -->
            <a class="btn btn-outline-secondary btn-sm ms-auto" href="calendario.php">
                <i class="bi bi-arrow-left"></i> Calendario
            </a>
        </div>
    </nav>
    
    <div class="container mt-5">
        <!-- Marcador principal -->
        <div class="row align-items-center mb-5 bg-dark border border-secondary p-4 rounded-4 shadow-sm text-center">
            <div class="col-4">
                <?php if (!empty($match['team1_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($match['team1_logo']); ?>" class="rounded-circle mb-2 shadow" style="width: 80px; height: 80px; object-fit: cover; border: 3px solid #0d6efd;">
                <?php else: ?>
                    <div class="rounded-circle bg-secondary text-white d-flex justify-content-center align-items-center mx-auto mb-2 shadow" style="width: 80px; height: 80px; font-size: 34px; font-weight: bold; border: 3px solid #6c757d;">
                        <?php echo strtoupper(substr($match['team1_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <h4 class="fw-bold text-truncate mt-2"><?php echo htmlspecialchars($match['team1_name']); ?></h4>
            </div>
            <div class="col-4">
                <?php if ($match['status'] === 'finished'): ?>
                    <h1 class="display-3 fw-bold text-primary"><?php echo $match['team1_score']; ?> - <?php echo $match['team2_score']; ?></h1>
                    <span class="badge bg-success mb-2">FINALIZADO</span>
                    <?php if ($isAdmin): ?>
                        <div class="mt-2 text-start">
                            <button class="btn btn-outline-light btn-sm mx-auto d-block" type="button" data-bs-toggle="collapse" data-bs-target="#editScoreForm">
                                <i class="bi bi-pencil-square"></i> <small>Editar Resultado</small>
                            </button>
                            <div class="collapse mt-2" id="editScoreForm">
                                <form method="POST" class="bg-secondary bg-opacity-10 p-2 rounded border border-secondary">
                                    <input type="hidden" name="action" value="edit_score">
                                    <div class="input-group input-group-sm">
                                        <input type="number" min="0" name="score1" class="form-control bg-dark text-white border-secondary text-center" value="<?php echo $match['team1_score']; ?>" required>
                                        <span class="input-group-text bg-dark text-white border-secondary bg-opacity-50">-</span>
                                        <input type="number" min="0" name="score2" class="form-control bg-dark text-white border-secondary text-center" value="<?php echo $match['team2_score']; ?>" required>
                                        <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg"></i></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <h1 class="display-4 fw-bold text-muted">VS</h1>
                    <span class="badge bg-warning text-dark">PENDIENTE</span>
                <?php endif; ?>
            </div>
            <div class="col-4">
                <?php if (!empty($match['team2_logo'])): ?>
                    <img src="<?php echo htmlspecialchars($match['team2_logo']); ?>" class="rounded-circle mb-2 shadow" style="width: 80px; height: 80px; object-fit: cover; border: 3px solid #0d6efd;">
                <?php else: ?>
                    <div class="rounded-circle bg-secondary text-white d-flex justify-content-center align-items-center mx-auto mb-2 shadow" style="width: 80px; height: 80px; font-size: 34px; font-weight: bold; border: 3px solid #6c757d;">
                        <?php echo strtoupper(substr($match['team2_name'], 0, 1)); ?>
                    </div>
                <?php endif; ?>
                <h4 class="fw-bold text-truncate mt-2"><?php echo htmlspecialchars($match['team2_name']); ?></h4>
            </div>
        </div>

        <?php if ($match['status'] === 'pending'): ?>
            <div class="mb-4 text-center">
                <span class="badge bg-secondary mb-2 fs-6"><i class="bi bi-calendar-event me-1"></i> <?php echo $match['match_date'] ? date('d/m/Y H:i', strtotime($match['match_date'])) : 'Fecha por definir'; ?></span>
                
                <?php if ($isAdmin): ?>
                    <form method="POST" class="d-inline-flex flex-wrap align-items-center justify-content-center bg-secondary bg-opacity-10 p-3 rounded border border-secondary w-100 mx-auto" style="max-width: 500px;">
                        <input type="hidden" name="action" value="update_match_details">
                        <div class="row g-2 align-items-center">
                            <div class="col-auto">
                                <label class="text-muted small fw-bold">Jornada:</label>
                                <input type="number" name="new_jornada" class="form-control form-control-sm bg-dark text-white border-secondary" style="width: 70px;" value="<?php echo $match['jornada']; ?>" min="1" required>
                            </div>
                            <div class="col">
                                <label class="text-muted small fw-bold">Fecha:</label>
                                <input type="datetime-local" name="new_date" class="form-control form-control-sm bg-dark text-white border-secondary" style="color-scheme: dark;" value="<?php echo $match['match_date'] ? date('Y-m-d\TH:i', strtotime($match['match_date'])) : ''; ?>">
                            </div>
                            <div class="col-auto align-self-end">
                                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-save"></i> Guardar</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Columna Equipo 1 -->
            <div class="col-md-6 mb-4">
                <h4 class="text-primary border-bottom border-primary pb-2">Alineación: <?php echo htmlspecialchars($match['team1_name'] ?? ''); ?></h4>
                <ul class="list-group list-group-flush bg-transparent">
                    <?php if (count($lineups[$match['team1_id']]) > 0): ?>
                        <?php foreach ($lineups[$match['team1_id']] as $p): ?>
                            <li class="list-group-item bg-dark text-light border-secondary d-flex align-items-center justify-content-between p-2">
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($p['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($p['profile_picture'] ?? ''); ?>" class="rounded-circle me-3 border border-secondary" style="width: 40px; height: 40px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-3 fw-bold" style="width: 40px; height: 40px; font-size: 16px;">
                                            <?php echo strtoupper(substr($p['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <span class="fw-semibold">
                                        <?php echo htmlspecialchars($p['username'] ?? ''); ?>
                                        <?php 
                                        $g = $playerMatchStats[$p['id']]['goal'] ?? 0;
                                        $a = $playerMatchStats[$p['id']]['assist'] ?? 0;
                                        if ($g > 0) echo '<span class="ms-1" style="font-size:0.9em;">' . str_repeat('⚽', $g) . '</span>';
                                        if ($a > 0) echo '<span class="ms-1" style="font-size:0.9em;">' . str_repeat('👟', $a) . '</span>';
                                        ?>
                                    </span>
                                    <?php if (isset($matchPlayerAvgs[$p['id']])): ?>
                                        <span class="badge bg-success bg-opacity-75 ms-2 border border-success" title="<?php echo $matchPlayerAvgs[$p['id']]['votes']; ?> votos">
                                            <i class="bi bi-star-fill text-warning me-1"></i><?php echo number_format($matchPlayerAvgs[$p['id']]['avg'], 1); ?>
                                        </span>
                                        <?php if (abs($matchPlayerAvgs[$p['id']]['avg'] - $maxMatchAvg) < 0.001 && $maxMatchAvg > 0): ?>
                                            <span class="badge bg-warning text-dark ms-1"><i class="bi bi-trophy-fill"></i> MVP</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isAdmin || ($isCaptainOfPlayingTeam && $myTeamId == $match['team1_id'] && $match['status'] === 'pending')): ?>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="remove_lineup">
                                        <input type="hidden" name="team_id" value="<?php echo $match['team1_id']; ?>">
                                        <input type="hidden" name="player_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger border-0 p-1 px-2" title="Eliminar de la convocatoria"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-group-item bg-dark text-muted border-secondary">Aún no se ha confirmado la alineación.</li>
                    <?php endif; ?>
                </ul>
                
                <?php if ($isAdmin || ($isCaptainOfPlayingTeam && $myTeamId == $match['team1_id'] && $match['status'] === 'pending')): ?>
                    <!-- Formulario para añadir jugadores -->
                    <form method="POST" class="mt-3 p-3 bg-secondary bg-opacity-10 border border-secondary rounded">
                        <input type="hidden" name="action" value="add_lineup">
                        <input type="hidden" name="team_id" value="<?php echo $match['team1_id']; ?>">
                        <?php
                        $selectTeamId1 = $isAdmin ? $match['team1_id'] : $myTeamId;
                        $alreadyIn1 = array_column($lineups[$match['team1_id']], 'id');
                        $stmtRo1 = $pdo->prepare("SELECT id, username FROM users WHERE team_id = ? ORDER BY username");
                        $stmtRo1->execute([$selectTeamId1]);
                        $availPlayers1 = array_filter($stmtRo1->fetchAll(), fn($r) => !in_array($r['id'], $alreadyIn1));
                        ?>
                        <?php if (count($availPlayers1) > 0): ?>
                        <div class="input-group">
                            <select name="player_id" class="form-select bg-dark text-light border-secondary" required>
                                <option value="">Selecciona un jugador...</option>
                                <?php foreach ($availPlayers1 as $ro): ?>
                                    <option value="<?php echo $ro['id']; ?>"><?php echo htmlspecialchars($ro['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-outline-primary">Añadir a la lista</button>
                        </div>
                        <?php else: ?>
                            <p class="text-muted small mb-0"><i class="bi bi-check-all"></i> Todos los jugadores del equipo ya están convocados.</p>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Columna Equipo 2 -->
            <div class="col-md-6 mb-4">
                <h4 class="text-primary border-bottom border-primary pb-2">Alineación: <?php echo htmlspecialchars($match['team2_name']); ?></h4>
                <ul class="list-group list-group-flush bg-transparent">
                    <?php if (count($lineups[$match['team2_id']]) > 0): ?>
                        <?php foreach ($lineups[$match['team2_id']] as $p): ?>
                            <li class="list-group-item bg-dark text-light border-secondary d-flex align-items-center justify-content-between p-2">
                                <div class="d-flex align-items-center">
                                    <?php if (!empty($p['profile_picture'])): ?>
                                        <img src="<?php echo htmlspecialchars($p['profile_picture']); ?>" class="rounded-circle me-3 border border-secondary" style="width: 40px; height: 40px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-3 fw-bold" style="width: 40px; height: 40px; font-size: 16px;">
                                            <?php echo strtoupper(substr($p['username'], 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <span class="fw-semibold">
                                        <?php echo htmlspecialchars($p['username']); ?>
                                        <?php 
                                        $g = $playerMatchStats[$p['id']]['goal'] ?? 0;
                                        $a = $playerMatchStats[$p['id']]['assist'] ?? 0;
                                        if ($g > 0) echo '<span class="ms-1" style="font-size:0.9em;">' . str_repeat('⚽', $g) . '</span>';
                                        if ($a > 0) echo '<span class="ms-1" style="font-size:0.9em;">' . str_repeat('👟', $a) . '</span>';
                                        ?>
                                    </span>
                                    <?php if (isset($matchPlayerAvgs[$p['id']])): ?>
                                        <span class="badge bg-success bg-opacity-75 ms-2 border border-success" title="<?php echo $matchPlayerAvgs[$p['id']]['votes']; ?> votos">
                                            <i class="bi bi-star-fill text-warning me-1"></i><?php echo number_format($matchPlayerAvgs[$p['id']]['avg'], 1); ?>
                                        </span>
                                        <?php if (abs($matchPlayerAvgs[$p['id']]['avg'] - $maxMatchAvg) < 0.001 && $maxMatchAvg > 0): ?>
                                            <span class="badge bg-warning text-dark ms-1"><i class="bi bi-trophy-fill"></i> MVP</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isAdmin || ($isCaptainOfPlayingTeam && $myTeamId == $match['team2_id'] && $match['status'] === 'pending')): ?>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="action" value="remove_lineup">
                                        <input type="hidden" name="team_id" value="<?php echo $match['team2_id']; ?>">
                                        <input type="hidden" name="player_id" value="<?php echo $p['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger border-0 p-1 px-2" title="Eliminar de la convocatoria"><i class="bi bi-x-lg"></i></button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="list-group-item bg-dark text-muted border-secondary">Aún no se ha confirmado la alineación.</li>
                    <?php endif; ?>
                </ul>

                <?php if ($isAdmin || ($isCaptainOfPlayingTeam && $myTeamId == $match['team2_id'] && $match['status'] === 'pending')): ?>
                    <!-- Formulario para añadir jugadores -->
                    <form method="POST" class="mt-3 p-3 bg-secondary bg-opacity-10 border border-secondary rounded">
                        <input type="hidden" name="action" value="add_lineup">
                        <input type="hidden" name="team_id" value="<?php echo $match['team2_id']; ?>">
                        <?php
                        $selectTeamId2 = $isAdmin ? $match['team2_id'] : $myTeamId;
                        $alreadyIn2 = array_column($lineups[$match['team2_id']], 'id');
                        $stmtRo2 = $pdo->prepare("SELECT id, username FROM users WHERE team_id = ? ORDER BY username");
                        $stmtRo2->execute([$selectTeamId2]);
                        $availPlayers2 = array_filter($stmtRo2->fetchAll(), fn($r) => !in_array($r['id'], $alreadyIn2));
                        ?>
                        <?php if (count($availPlayers2) > 0): ?>
                        <div class="input-group">
                            <select name="player_id" class="form-select bg-dark text-light border-secondary" required>
                                <option value="">Selecciona un jugador...</option>
                                <?php foreach ($availPlayers2 as $ro): ?>
                                    <option value="<?php echo $ro['id']; ?>"><?php echo htmlspecialchars($ro['username']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-outline-primary">Añadir a la lista</button>
                        </div>
                        <?php else: ?>
                            <p class="text-muted small mb-0"><i class="bi bi-check-all"></i> Todos los jugadores del equipo ya están convocados.</p>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isAdmin && $match['status'] === 'finished'): ?>
            <div class="card bg-dark border-info mt-4 mb-3 shadow">
                <div class="card-header bg-info text-dark fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-gear-fill me-1"></i> Administrar Votaciones</span>
                    <?php if ($match['voting_closed']): ?>
                        <span class="badge bg-danger border border-dark">Cerradas</span>
                    <?php else: ?>
                        <span class="badge bg-success border border-dark">Abiertas</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <form method="POST" class="d-flex align-items-center flex-wrap gap-3">
                        <input type="hidden" name="action" value="toggle_voting">
                        <p class="mb-0 text-muted small flex-grow-1">
                            <?php if ($match['voting_closed']): ?>
                                Las votaciones están cerradas. Las notas ya cuentan para su media global.
                            <?php else: ?>
                                Las votaciones están abiertas. Las notas de este partido aún no son visibles ni cuentan para la media global. 
                            <?php endif; ?>
                        </p>
                        <button type="submit" class="btn <?php echo $match['voting_closed'] ? 'btn-outline-success' : 'btn-outline-danger'; ?> text-nowrap">
                            <?php echo $match['voting_closed'] ? '<i class="bi bi-unlock-fill"></i> Abrir Votaciones' : '<i class="bi bi-lock-fill"></i> Cerrar Votaciones'; ?>
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Acciones Post-Partido -->
        <?php if (($isAdmin || $isCaptainOfPlayingTeam) && $match['status'] === 'pending' && count($lineups[$match['team1_id']]) > 0 && count($lineups[$match['team2_id']]) > 0): ?>
            <div class="card bg-dark border-warning mt-4 mb-5 shadow">
                <div class="card-header bg-warning text-dark fw-bold">
                    <i class="bi bi-trophy-fill me-1"></i> Finalizar Partido
                </div>
                <div class="card-body">
                    <form method="POST" class="row align-items-end g-3">
                        <input type="hidden" name="action" value="finish_match">
                        <div class="col-md-5">
                            <label class="form-label">Goles de <?php echo htmlspecialchars($match['team1_name']); ?></label>
                            <input type="number" min="0" name="score1" class="form-control bg-dark text-light border-secondary" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Goles de <?php echo htmlspecialchars($match['team2_name']); ?></label>
                            <input type="number" min="0" name="score2" class="form-control bg-dark text-light border-secondary" required>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-warning w-100">Cerrar Partido</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        
        <?php
        // --- Calcular si el capitán todavía puede añadir estadísticas ---
        $captainCanAddStats = false;
        if ($isCaptainOfPlayingTeam && $match['status'] === 'finished' && $myTeamId) {
            $opponentTeamIdForCap = ($myTeamId == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];
            $myOfficialScore = ($myTeamId == $match['team1_id']) ? (int)$match['team1_score'] : (int)$match['team2_score'];
            $oppOfficialScore = ($opponentTeamIdForCap == $match['team1_id']) ? (int)$match['team1_score'] : (int)$match['team2_score'];

            $stmtCapStats = $pdo->prepare("
                SELECT u.team_id, me.event_type, COUNT(*) as count
                FROM match_events me
                JOIN users u ON me.player_id = u.id
                WHERE me.match_id = ?
                GROUP BY u.team_id, me.event_type
            ");
            $stmtCapStats->execute([$matchId]);
            $capStats = [];
            foreach ($stmtCapStats->fetchAll() as $row) {
                $capStats[$row['team_id']][$row['event_type']] = (int)$row['count'];
            }
            $myGoalsCap    = $capStats[$myTeamId]['goal']     ?? 0;
            $myOwnGoalsCap = $capStats[$myTeamId]['own_goal'] ?? 0;
            $myAssistsCap  = $capStats[$myTeamId]['assist']   ?? 0;
            $oppGoalsCap   = $capStats[$opponentTeamIdForCap]['goal']     ?? 0;
            $oppOwnGoalsCap= $capStats[$opponentTeamIdForCap]['own_goal'] ?? 0;

            $canAddGoal    = ($myGoalsCap + $oppOwnGoalsCap) < $myOfficialScore;
            $canAddOwn     = ($oppGoalsCap + $myOwnGoalsCap) < $oppOfficialScore;
            $canAddAssist  = $myAssistsCap < $myGoalsCap;

            $captainCanAddStats = $canAddGoal || $canAddOwn || $canAddAssist;
        }
        ?>
        <?php if ($match['status'] === 'finished' && ($isAdmin || $captainCanAddStats)): ?>
            <div class="card bg-dark border-primary mt-4 mb-5 shadow" id="stats-card">
                <div class="card-header bg-primary text-white fw-bold">
                    <i class="bi bi-clipboard-data-fill me-1"></i> Añadir Estadísticas de los Jugadores
                </div>
                <div class="card-body">
                    <div id="stat-error-msg" class="alert alert-danger bg-danger text-white border-0 py-2 mb-3 d-none"></div>
                    <div id="stat-ok-msg" class="alert alert-success bg-success text-white border-0 py-2 mb-3 d-none"></div>
                    <div class="row align-items-end g-3">
                        <div class="col-md-5">
                            <label class="form-label">Jugador de la alineación</label>
                            <select id="stat-player" class="form-select bg-dark text-light border-secondary">
                                <?php
                                $options = $isAdmin ? array_merge($lineups[$match['team1_id']], $lineups[$match['team2_id']]) : $lineups[$myTeamId];
                                foreach ($options as $ro) {
                                    echo '<option value="'.$ro['id'].'">'.htmlspecialchars($ro['username']).'</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Evento</label>
                            <select id="stat-event" class="form-select bg-dark text-light border-secondary">
                                <option value="goal">Marcó un GOL ⚽</option>
                                <option value="assist">Dio una ASISTENCIA 👟</option>
                                <option value="own_goal">Marcó GOL EN PROPIA 🔴</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button id="stat-save-btn" class="btn btn-primary w-100">
                                <span id="stat-btn-text">Guardar Stats</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Votaciones Jugadores Rivales -->
        <?php if ($imPlayingInMatch && $match['status'] === 'finished' && count($opponents) > 0): ?>
            <div class="card bg-dark border-success mt-4 mb-5 shadow">
                <div class="card-header bg-success text-white fw-bold d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-star-fill me-1"></i> Valora a los rivales del partido</span>
                    <?php if ($match['voting_closed']): ?>
                        <span class="badge bg-danger"><i class="bi bi-lock-fill"></i> CERRADO</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if ($match['voting_closed']): ?>
                        <div class="alert alert-danger bg-dark border-danger text-center m-0">
                            <i class="bi bi-lock-fill me-2"></i> El administrador ha cerrado las votaciones para este partido. Ya no se permiten nuevos votos.
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-4">Solo puedes valorar a los jugadores del equipo contrario que hayan participado en este partido.</p>
                        <div class="row row-cols-1 row-cols-md-2 g-3" id="ratings-container">
                            <?php foreach ($opponents as $opp): ?>
                                <div class="col" id="vote-col-<?php echo $opp['id']; ?>">
                                    <?php 
                                    $g = $playerMatchStats[$opp['id']]['goal'] ?? 0;
                                    $a = $playerMatchStats[$opp['id']]['assist'] ?? 0;
                                    $iconsHtml = '';
                                    if ($g > 0) $iconsHtml .= '<span class="ms-1" style="font-size:0.9em;">' . str_repeat('⚽', $g) . '</span>';
                                    if ($a > 0) $iconsHtml .= '<span class="ms-1" style="font-size:0.9em;">' . str_repeat('👟', $a) . '</span>';
                                    ?>
                                    <?php if (isset($myVotes[$opp['id']])): ?>
                                        <div class="d-flex align-items-center bg-secondary bg-opacity-10 p-2 rounded border border-success" id="vote-view-<?php echo $opp['id']; ?>">
                                            <div class="me-auto text-truncate d-flex align-items-center">
                                                <?php if (!empty($opp['profile_picture'])): ?>
                                                    <img src="<?php echo htmlspecialchars($opp['profile_picture']); ?>" class="rounded-circle me-2 border border-secondary" style="width: 32px; height: 32px; object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold" style="width: 32px; height: 32px; font-size: 12px; border: 1px solid #6c757d;">
                                                        <?php echo strtoupper(substr($opp['username'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <strong><?php echo htmlspecialchars($opp['username']); ?><?php echo $iconsHtml; ?></strong>
                                            </div>
                                            <div class="d-flex align-items-center text-success fw-bold text-nowrap">
                                                <i class="bi bi-check-circle-fill me-1"></i> <span class="d-none d-sm-inline">Nota:</span> <span id="nota-text-<?php echo $opp['id']; ?>"><?php echo number_format($myVotes[$opp['id']], 1); ?></span>
                                                <button class="btn btn-sm btn-link text-warning p-0 ms-2 edit-vote-btn" title="Editar voto" data-target="<?php echo $opp['id']; ?>"><i class="bi bi-pencil-fill"></i></button>
                                            </div>
                                        </div>
                                        <div class="d-none align-items-center bg-secondary bg-opacity-10 p-2 rounded border border-warning form-container" id="vote-form-<?php echo $opp['id']; ?>">
                                            <div class="me-auto text-truncate d-flex align-items-center">
                                                <strong><?php echo htmlspecialchars($opp['username']); ?><?php echo $iconsHtml; ?></strong>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <input type="number" step="0.5" min="1" max="10" placeholder="1-10"
                                                    class="form-control form-control-sm bg-dark text-light border-secondary me-2 rating-input"
                                                    style="width: 70px;"
                                                    data-target="<?php echo $opp['id']; ?>" value="<?php echo number_format($myVotes[$opp['id']], 1); ?>">
                                                <button class="btn btn-sm btn-warning vote-btn text-dark fw-bold"
                                                    data-target="<?php echo $opp['id']; ?>"
                                                    data-match="<?php echo $matchId; ?>">
                                                    <i class="bi bi-save"></i>
                                                </button>
                                                <button class="btn btn-sm btn-outline-secondary ms-1 cancel-edit-btn" data-target="<?php echo $opp['id']; ?>"><i class="bi bi-x"></i></button>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="d-flex align-items-center bg-secondary bg-opacity-10 p-2 rounded border border-secondary vote-form-wrap" id="vote-form-<?php echo $opp['id']; ?>">
                                            <div class="me-auto text-truncate d-flex align-items-center">
                                                <?php if (!empty($opp['profile_picture'])): ?>
                                                    <img src="<?php echo htmlspecialchars($opp['profile_picture']); ?>" class="rounded-circle me-2 border border-secondary" style="width: 32px; height: 32px; object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold" style="width: 32px; height: 32px; font-size: 12px; border: 1px solid #6c757d;">
                                                        <?php echo strtoupper(substr($opp['username'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <strong><?php echo htmlspecialchars($opp['username']); ?><?php echo $iconsHtml; ?></strong>
                                            </div>
                                            <div class="d-flex align-items-center">
                                                <input type="number" step="0.5" min="1" max="10" placeholder="1-10"
                                                    class="form-control form-control-sm bg-dark text-light border-secondary me-2 rating-input"
                                                    style="width: 70px;"
                                                    data-target="<?php echo $opp['id']; ?>">
                                                <button class="btn btn-sm btn-outline-success vote-btn"
                                                    data-target="<?php echo $opp['id']; ?>"
                                                    data-match="<?php echo $matchId; ?>">
                                                    Votar
                                                </button>
                                            </div>
                                        </div>
                                        <div class="d-none align-items-center bg-secondary bg-opacity-10 p-2 rounded border border-success" id="vote-view-<?php echo $opp['id']; ?>">
                                            <div class="me-auto text-truncate d-flex align-items-center">
                                                <?php if (!empty($opp['profile_picture'])): ?>
                                                    <img src="<?php echo htmlspecialchars($opp['profile_picture']); ?>" class="rounded-circle me-2 border border-secondary" style="width: 32px; height: 32px; object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center me-2 fw-bold" style="width: 32px; height: 32px; font-size: 12px; border: 1px solid #6c757d;">
                                                        <?php echo strtoupper(substr($opp['username'], 0, 1)); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <strong><?php echo htmlspecialchars($opp['username']); ?><?php echo $iconsHtml; ?></strong>
                                            </div>
                                            <div class="d-flex align-items-center text-success fw-bold text-nowrap">
                                                <i class="bi bi-check-circle-fill me-1"></i> <span class="d-none d-sm-inline">Nota:</span> <span id="nota-text-<?php echo $opp['id']; ?>"></span>
                                                <button class="btn btn-sm btn-link text-warning p-0 ms-2 edit-vote-btn" title="Editar voto" data-target="<?php echo $opp['id']; ?>"><i class="bi bi-pencil-fill"></i></button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Historial de Eventos (Solo diseño básico) -->
        <?php
        $stmtEvents = $pdo->prepare("SELECT me.id as event_id, me.event_type, u.username FROM match_events me JOIN users u ON me.player_id = u.id WHERE me.match_id = ? ORDER BY me.id DESC LIMIT 20");
        $stmtEvents->execute([$matchId]);
        $events = $stmtEvents->fetchAll();
        if (count($events) > 0 && $match['status'] === 'finished'):
        ?>
            <h4 class="mt-5 mb-3">Historial de Eventos</h4>
            <div class="list-group bg-dark mb-5">
                <?php foreach ($events as $ev): ?>
                    <div class="list-group-item bg-dark text-light border-secondary">
                        <?php if ($isAdmin): ?>
                            <form method="POST" class="d-inline float-end ms-2" style="margin: 0;">
                                <input type="hidden" name="action" value="remove_event">
                                <input type="hidden" name="event_id" value="<?php echo $ev['event_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger border-0 p-1 py-0"><i class="bi bi-trash-fill"></i></button>
                            </form>
                        <?php endif; ?>

                        <?php if ($ev['event_type'] === 'goal'): ?>
                            <i class="bi bi-vinyl-fill text-primary me-2"></i>
                            <?php echo htmlspecialchars($ev['username']); ?> ha registrado un <strong>GOL ⚽</strong>.
                        <?php elseif ($ev['event_type'] === 'own_goal'): ?>
                            <i class="bi bi-exclamation-circle-fill text-danger me-2"></i>
                            <?php echo htmlspecialchars($ev['username']); ?> ha registrado un <strong>GOL EN PROPIA 🔴</strong>.
                        <?php else: ?>
                            <i class="bi bi-cursor-fill text-warning me-2"></i>
                            <?php echo htmlspecialchars($ev['username']); ?> ha registrado una <strong>ASISTENCIA 👟</strong>.
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // AJAX para votar sin recargar la página ni subir al inicio
    document.querySelectorAll('.vote-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const matchId  = this.getAttribute('data-match');
            const formWrap = document.getElementById('vote-form-' + targetId);
            const viewWrap = document.getElementById('vote-view-' + targetId);
            const inputEl  = formWrap.querySelector('.rating-input');
            const rating   = parseFloat(inputEl.value);

            if (!rating || rating < 1 || rating > 10) {
                inputEl.classList.add('is-invalid');
                inputEl.focus();
                return;
            }
            inputEl.classList.remove('is-invalid');

            // Deshabilitar mientras enviamos
            btn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'rate_player');
            formData.append('target_id', targetId);
            formData.append('match_id_ajax', matchId);
            formData.append('rating', rating);
            formData.append('ajax', '1');

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(function(res) {
                return res.json();
            }).then(function(data) {
                if (data.ok) {
                    // Update value and toggle views
                    viewWrap.querySelector('#nota-text-' + targetId).textContent = rating.toFixed(1);
                    formWrap.classList.add('d-none');
                    formWrap.classList.remove('d-flex');
                    viewWrap.classList.remove('d-none');
                    viewWrap.classList.add('d-flex');
                    btn.disabled = false;
                } else {
                    btn.disabled = false;
                    alert(data.error || 'Error al guardar el voto. Inténtalo de nuevo.');
                }
            }).catch(function() {
                btn.disabled = false;
                alert('Error de red. Inténtalo de nuevo.');
            });
        });
    });

    // Toggle edit vote form
    document.querySelectorAll('.edit-vote-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const formWrap = document.getElementById('vote-form-' + targetId);
            const viewWrap = document.getElementById('vote-view-' + targetId);
            
            viewWrap.classList.add('d-none');
            viewWrap.classList.remove('d-flex');
            formWrap.classList.remove('d-none');
            formWrap.classList.add('d-flex');
        });
    });

    // Cancel edit vote
    document.querySelectorAll('.cancel-edit-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const formWrap = document.getElementById('vote-form-' + targetId);
            const viewWrap = document.getElementById('vote-view-' + targetId);
            
            formWrap.classList.add('d-none');
            formWrap.classList.remove('d-flex');
            viewWrap.classList.remove('d-none');
            viewWrap.classList.add('d-flex');
        });
    });

    // AJAX para guardar estadísticas sin recargar la página
    const statSaveBtn = document.getElementById('stat-save-btn');
    if (statSaveBtn) {
        statSaveBtn.addEventListener('click', function() {
            const playerSel = document.getElementById('stat-player');
            const eventSel  = document.getElementById('stat-event');
            const errMsg    = document.getElementById('stat-error-msg');
            const okMsg     = document.getElementById('stat-ok-msg');
            const btnText   = document.getElementById('stat-btn-text');

            errMsg.classList.add('d-none');
            okMsg.classList.add('d-none');

            statSaveBtn.disabled = true;
            btnText.textContent  = 'Guardando...';

            const formData = new FormData();
            formData.append('action',     'add_event');
            formData.append('player_id',  playerSel.value);
            formData.append('event_type', eventSel.value);
            formData.append('ajax',       '1');  // Indica al servidor que devuelva JSON

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                statSaveBtn.disabled = false;
                btnText.textContent  = 'Guardar Stats';

                if (data.ok) {
                    // Éxito: mostrar mensaje verde
                    okMsg.innerHTML = '<i class="bi bi-check-circle-fill me-2"></i> Estadística guardada correctamente.';
                    okMsg.classList.remove('d-none');
                    setTimeout(() => { okMsg.classList.add('d-none'); }, 3000);

                    // Refrescar silenciosamente el historial de eventos
                    fetch(window.location.href)
                    .then(r => r.text())
                    .then(fullHtml => {
                        const d2 = new DOMParser().parseFromString(fullHtml, 'text/html');
                        const newHist = d2.querySelector('.list-group.bg-dark.mb-5');
                        const oldHist = document.querySelector('.list-group.bg-dark.mb-5');
                        if (newHist && oldHist) oldHist.replaceWith(newHist);
                        const newCard = d2.getElementById('stats-card');
                        const oldCard = document.getElementById('stats-card');
                        if (!newCard && oldCard) oldCard.remove();
                    });
                } else {
                    // Error: mostrar mensaje rojo con el texto del servidor
                    errMsg.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i> ' + (data.error || 'Error desconocido.');
                    errMsg.classList.remove('d-none');
                }
            })
            .catch(() => {
                statSaveBtn.disabled = false;
                btnText.textContent  = 'Guardar Stats';
                errMsg.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i> Error de red. Inténtalo de nuevo.';
                errMsg.classList.remove('d-none');
            });
        });
    }
    </script>
</body>
</html>

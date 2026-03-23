<?php
session_start();
require_once __DIR__ . '/db.php';

// Redirigir al login si no está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: login/login.php');
    exit;
}

$myUserId = $_SESSION['user_id'];

// Obtener detalles del usuario actual y su equipo (es necesario antes de guardar las tácticas para conocer su $myTeamId)
$stmtMe = $pdo->prepare("SELECT u.*, t.name as team_name, t.logo as team_logo FROM users u LEFT JOIN teams t ON u.team_id = t.id WHERE u.id = ?");
$stmtMe->execute([$myUserId]);
$me = $stmtMe->fetch();
$myTeamId = $me['team_id'];
$userRole = $me['role'] ?? ($_SESSION['role'] ?? 'user');

// Recuperar tácticas antiguas que se guardaron sin team_id hacia el equipo actual si existe (Para migrar las que el usuario dijo que no veía)
if ($myTeamId) {
    $pdo->prepare("UPDATE tactics SET team_id = ? WHERE user_id = ? AND team_id IS NULL")->execute([$myTeamId, $myUserId]);
}

// --- NUEVO: SISTEMA DE GUARDADO DE TÁCTICAS ---
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tactics (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        team_id INT DEFAULT NULL,
        name VARCHAR(100) NOT NULL,
        rival_id INT DEFAULT 0,
        positions JSON NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Intentar añadir la columna team_id si la tabla antigua se creó hace un paso sin ella
    try {
        $pdo->exec("ALTER TABLE tactics ADD COLUMN team_id INT DEFAULT NULL AFTER user_id");
    }
    catch (PDOException $e) {
    } // Ya existe

}
catch (PDOException $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_tactic') {
        $name = trim($_POST['tactic_name']);
        $pos = $_POST['positions'];
        $riv = (int)$_POST['rival_id_save'];
        $t_id = $myTeamId ? $myTeamId : null;

        if (!empty($name) && !empty($pos)) {
            $stmt = $pdo->prepare("INSERT INTO tactics (user_id, team_id, name, rival_id, positions) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$myUserId, $t_id, $name, $riv, $pos]);
        }
        header("Location: pizarra.php?rival_id=" . $riv . "&msg=saved");
        exit;
    }

    if ($_POST['action'] === 'delete_tactic') {
        $tid = (int)$_POST['tactic_id'];
        $riv = (int)$_POST['rival_id_save'];

        // Verificar permisos para borrar: es el autor, o es capitan/admin del equipo
        $stmtCheck = $pdo->prepare("SELECT user_id, team_id FROM tactics WHERE id = ?");
        $stmtCheck->execute([$tid]);
        $tacCheck = $stmtCheck->fetch();

        if ($tacCheck) {
            $isAuthor = ($tacCheck['user_id'] == $myUserId);
            $roleCheck = $me['role'] ?? ($_SESSION['role'] ?? 'user');
            $isCaptainOrAdmin = in_array($roleCheck, ['capitan', 'admin']) && ($tacCheck['team_id'] == $myTeamId || $roleCheck === 'admin');

            if ($isAuthor || $isCaptainOrAdmin) {
                $stmt = $pdo->prepare("DELETE FROM tactics WHERE id = ?");
                $stmt->execute([$tid]);
            }
        }
        header("Location: pizarra.php?rival_id=" . $riv);
        exit;
    }
}

// Obtener tácticas del equipo (o personales si no hay equipo)
if ($myTeamId) {
    $stmtTac = $pdo->prepare("SELECT tactics.*, users.username as creator_name FROM tactics LEFT JOIN users ON tactics.user_id = users.id WHERE tactics.team_id = ? ORDER BY tactics.created_at DESC");
    $stmtTac->execute([$myTeamId]);
}
else {
    $stmtTac = $pdo->prepare("SELECT tactics.*, users.username as creator_name FROM tactics LEFT JOIN users ON tactics.user_id = users.id WHERE tactics.user_id = ? AND tactics.team_id IS NULL ORDER BY tactics.created_at DESC");
    $stmtTac->execute([$myUserId]);
}
$myTactics = $stmtTac->fetchAll();

$loadedPositions = null;
if (isset($_GET['load_tactic'])) {
    $tid = (int)$_GET['load_tactic'];
    // Permitir cargar tácticas propias O de cualquier miembro del equipo
    if ($myTeamId) {
        $stmtLoad = $pdo->prepare("SELECT positions FROM tactics WHERE id = ? AND (user_id = ? OR team_id = ?)");
        $stmtLoad->execute([$tid, $myUserId, $myTeamId]);
    } else {
        $stmtLoad = $pdo->prepare("SELECT positions FROM tactics WHERE id = ? AND user_id = ?");
        $stmtLoad->execute([$tid, $myUserId]);
    }
    $rowLoad = $stmtLoad->fetch();
    if ($rowLoad) {
        $loadedPositions = $rowLoad['positions'];
    }
}
// --- FIN SISTEMA DE GUARDADO ---

$myPlayers = [];

if ($myTeamId) {
    // Obtener jugadores de mi equipo
    $stmtMyPlayers = $pdo->prepare("SELECT id, username, profile_picture FROM users WHERE team_id = ? ORDER BY username ASC");
    $stmtMyPlayers->execute([$myTeamId]);
    $myPlayers = $stmtMyPlayers->fetchAll();
}

// Obtener todos los equipos para el select de rival
$teams = $pdo->query("SELECT id, name, logo FROM teams ORDER BY name")->fetchAll();

// Si se ha seleccionado un rival por GET
$rivalId = isset($_GET['rival_id']) ? (int)$_GET['rival_id'] : 0;
$rivalPlayers = [];

if ($rivalId > 0 && $rivalId !== (int)$myTeamId) {
    $stmtRival = $pdo->prepare("SELECT id, username, profile_picture FROM users WHERE team_id = ? ORDER BY username ASC");
    $stmtRival->execute([$rivalId]);
    $rivalPlayers = $stmtRival->fetchAll();
}

// Determinar el fondo a usar
// Si hay rival -> campo completo. Si no -> medio campo.
$bgImage = ($rivalId > 0) ? 'uploads/campo_entero.jpg' : 'uploads/medio_campo.jpg';
$bgImageMobile = ($rivalId > 0) ? 'uploads/campo_entero_movil.jpg' : 'uploads/medio_campo_movil.jpg';
$playerSize = ($rivalId > 0) ? '40px' : '60px'; // Si es medio campo, los jugadores se ven más grandes

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pizarra Táctica - Goats League</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
    
    <style>
        body {
            color: white;
        }

        #pitch-container {
            width: 100%;
            max-width: 800px;
            height: 600px; /* Altura fija para el campo, se puede ajustar */
            margin: 0 auto;
            background-image: url('<?php echo $bgImage; ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            position: relative;
            border: 4px solid #fff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            /* Quitamos touch-action: none para permitir hacer scroll usando el fondo libre */
        }

        .draggable-item {
            position: absolute;
            cursor: grab;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            user-select: none;
            /* Evita que toque cosas por debajo */
            touch-action: none;
            z-index: 10;
        }

        .draggable-item:active {
            cursor: grabbing;
            z-index: 100 !important;
        }

        .player-avatar {
            width: <?php echo $playerSize; ?>;
            height: <?php echo $playerSize; ?>;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid;
            background-color: #333;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: <?php echo($rivalId > 0) ? '16px' : '24px'; ?>;
            color: white;
            box-shadow: 0 4px 8px rgba(0,0,0,0.5);
        }

        .my-team-avatar {
            border-color: #0d6efd; /* Azul para mi equipo */
        }

        .rival-avatar {
            border-color: #dc3545; /* Rojo para el rival */
        }

        .player-name {
            font-size: 11px;
            font-weight: bold;
            background-color: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            margin-top: 4px;
            white-space: nowrap;
            text-shadow: 1px 1px 2px black;
        }

        .football {
            font-size: <?php echo($rivalId > 0) ? '25px' : '40px'; ?>;
            line-height: 1;
            filter: drop-shadow(0px 3px 3px rgba(0,0,0,0.6));
            z-index: 20;
        }
        
        /* Panel lateral de jugadores (para móviles se apila arriba) */
        #bench {
            background-color: #1e1e1e;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #333;
        }

        @media (max-width: 768px) {
            #pitch-container {
                height: 85vh; /* Aún más alto en móvil para que encaje la vista vertical perfectamente */
                min-height: 600px;
                background-image: url('<?php echo $bgImageMobile; ?>');
            }
        }
    </style>
</head>
<body>

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark border-bottom border-secondary">
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
                        <a class="nav-link active" href="pizarra.php">Pizarra Táctica</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="calendario.php">Calendario</a>
                    </li>

                    <li class="nav-item ms-lg-3 d-flex align-items-center">
                        <a class="nav-link text-white me-3 d-flex align-items-center" href="profile/" style="padding: 0;">
                            <?php if (!empty($me['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($me['profile_picture']); ?>" alt="Perfil" class="rounded-circle shadow-sm" style="width: 36px; height: 36px; object-fit: cover; border: 2px solid #0d6efd;">
                            <?php
else: ?>
                                <div class="rounded-circle bg-secondary shadow-sm d-flex justify-content-center align-items-center text-white fw-bold" style="width: 36px; height: 36px; font-size: 16px; border: 2px solid #6c757d;">
                                    <?php echo strtoupper(substr($me['username'], 0, 1)); ?>
                                </div>
                            <?php
endif; ?>
                            <span class="ms-2 d-none d-lg-inline fw-semibold"><?php echo htmlspecialchars($me['username']); ?></span>
                        </a>
                        <a class="btn btn-outline-danger btn-sm" href="./login/logout.php">Cerrar Sesión</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid mt-4 mb-5">
        <div class="row px-lg-3">
            
            <!-- PANEL DE CONTROL -->
            <div class="col-lg-3 mb-4">
                <div id="bench" class="shadow">
                    <h4 class="fw-bold mb-3 border-bottom border-secondary pb-2"><i class="bi bi-clipboard-data text-warning me-2"></i> Pizarra</h4>
                    
                    <?php if (!$myTeamId): ?>
                        <div class="alert alert-warning small">
                            No estás en ningún equipo. Únete a uno para disfrutar tu plantilla.
                        </div>
                    <?php
else: ?>
                        <div class="mb-4">
                            <h6 class="text-primary fw-bold">Tu Equipo: <?php echo htmlspecialchars($me['team_name']); ?></h6>
                            <p class="small text-muted mb-0">Arrastra los jugadores y el balón dentro del campo libremente.</p>
                        </div>
                        
                        <form method="GET" class="mb-3">
                            <label class="form-label text-white small fw-bold">Modo de Pizarra:</label>
                            <select name="rival_id" class="form-select bg-dark text-white border-secondary mb-2" onchange="this.form.submit()">
                                <option value="0" <?php echo($rivalId == 0) ? 'selected' : ''; ?>>Medio Campo</option>
                                <optgroup label="VS Equipo Rival">
                                    <?php foreach ($teams as $t): ?>
                                        <?php if ($t['id'] != $myTeamId): ?>
                                            <option value="<?php echo $t['id']; ?>" <?php echo($rivalId == $t['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($t['name']); ?></option>
                                        <?php
        endif; ?>
                                    <?php
    endforeach; ?>
                                </optgroup>
                            </select>
                            <small class="text-muted d-block">La imagen del campo cambiará de tamaño según el modo y cargará a los rivales si seleccionas uno.</small>
                        </form>


                        <!-- Filtro de Jugadores Visibles -->
                        <h6 class="text-white border-bottom border-secondary pb-1 fw-bold"><i class="bi bi-eye"></i> Jugadores Visibles</h6>
                        <div class="player-toggles bg-dark p-2 rounded border border-secondary mb-3" style="max-height: 180px; overflow-y: auto;">
                            <?php foreach ($myPlayers as $player): ?>
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input player-toggle-cb" type="checkbox" id="toggle_my_<?php echo $player['id']; ?>" data-target="my_play_<?php echo $player['id']; ?>" checked>
                                    <label class="form-check-label text-light small" for="toggle_my_<?php echo $player['id']; ?>"><?php echo htmlspecialchars($player['username']); ?> (Tú)</label>
                                </div>
                            <?php
    endforeach; ?>
                            
                            <?php if ($rivalId > 0 && count($rivalPlayers) > 0): ?>
                                <hr class="border-secondary my-2">
                                <?php foreach ($rivalPlayers as $player): ?>
                                    <div class="form-check form-switch mb-1">
                                        <input class="form-check-input player-toggle-cb" type="checkbox" id="toggle_riv_<?php echo $player['id']; ?>" data-target="riv_play_<?php echo $player['id']; ?>" checked>
                                        <label class="form-check-label text-light small" for="toggle_riv_<?php echo $player['id']; ?>"><?php echo htmlspecialchars($player['username']); ?> (Rival)</label>
                                    </div>
                                <?php
        endforeach; ?>
                            <?php
    endif; ?>
                            
                            <hr class="border-secondary my-2">
                            <div class="form-check form-switch mb-1">
                                <input class="form-check-input player-toggle-cb" type="checkbox" id="toggle_ball" data-target="ball" checked>
                                <label class="form-check-label text-light small fw-bold" for="toggle_ball">⚽ Balón</label>
                            </div>
                        </div>
                        
                        <!-- Mis Tácticas Guardadas -->
                        <h6 class="text-white border-bottom border-success pb-1 fw-bold mt-4"><i class="bi bi-save"></i> Tácticas del Equipo</h6>
                        <div class="mb-3">
                            <?php if (count($myTactics) > 0): ?>
                                <div class="list-group mb-2" style="max-height: 150px; overflow-y: auto;">
                                    <?php foreach ($myTactics as $tac): ?>
                                        <?php
            $isAuthor = ($tac['user_id'] == $myUserId);
            $roleView = $me['role'] ?? ($_SESSION['role'] ?? 'user');
            $canDelete = $isAuthor || (in_array($roleView, ['capitan', 'admin']) && ($tac['team_id'] == $myTeamId || $roleView === 'admin'));
?>
                                        <div class="list-group-item bg-dark border-secondary d-flex flex-column p-2">
                                            <div class="d-flex justify-content-between align-items-center mb-1">
                                                <a href="?rival_id=<?php echo $tac['rival_id']; ?>&load_tactic=<?php echo $tac['id']; ?>" class="text-decoration-none text-light small fw-bold flex-grow-1 text-truncate" title="Cargar táctica: <?php echo htmlspecialchars($tac['name']); ?>">
                                                    <i class="bi bi-play-fill text-success"></i> <?php echo htmlspecialchars($tac['name']); ?>
                                                </a>
                                                <?php if ($canDelete): ?>
                                                    <form method="POST" style="margin: 0;">
                                                        <input type="hidden" name="action" value="delete_tactic">
                                                        <input type="hidden" name="tactic_id" value="<?php echo $tac['id']; ?>">
                                                        <input type="hidden" name="rival_id_save" value="<?php echo $tac['rival_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0 ms-2 border-0" title="Eliminar"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                <?php
            endif; ?>
                                            </div>
                                            <small class="text-muted" style="font-size: 0.65rem;">Por: <?php echo htmlspecialchars($tac['creator_name'] ?? 'Usuario'); ?></small>
                                        </div>
                                    <?php
        endforeach; ?>
                                </div>
                            <?php
    else: ?>
                                <div class="text-muted small mb-2">No hay tácticas guardadas en el equipo aún.</div>
                            <?php
    endif; ?>
                            
                            <!-- Formulario para guardar -->
                            <form method="POST" id="saveTacticForm" class="input-group input-group-sm">
                                <input type="hidden" name="action" value="save_tactic">
                                <input type="hidden" name="rival_id_save" value="<?php echo $rivalId; ?>">
                                <input type="hidden" name="positions" id="positionsPayload" value="">
                                <input type="text" name="tactic_name" class="form-control bg-dark text-white border-secondary" placeholder="Nueva táctica..." required>
                                <button type="button" onclick="prepareSave()" class="btn btn-success" title="Guardar"><i class="bi bi-floppy"></i></button>
                            </form>
                        </div>
                        
                        <!-- Contenedor inicial para resetear posiciones si se desea -->
                        <div class="mt-4 text-center">
                            <button class="btn btn-sm btn-outline-warning w-100 fw-bold" onclick="resetPositions()"><i class="bi bi-arrow-counterclockwise"></i> Reiniciar Posiciones</button>
                        </div>
                    <?php
endif; ?>
                </div>
            </div>

            <!-- CAMPO DE FÚTBOL -->
            <div class="col-lg-9">
                <div id="pitch-container">
                    
                    <!-- Balón -->
                    <div class="draggable-item" id="ball" style="top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 50;">
                        <span class="football">⚽</span>
                    </div>

                    <?php
// Renderizar jugadores de MI EQUIPO
$topInit = 10;
$leftInit = 5;
foreach ($myPlayers as $i => $player):
    $pid = "my_play_" . $player['id'];
?>
                        <div class="draggable-item" id="<?php echo $pid; ?>" style="top: <?php echo $topInit; ?>%; left: <?php echo $leftInit; ?>%;">
                            <?php if (!empty($player['profile_picture'])): ?>
                                <img src="<?php echo htmlspecialchars($player['profile_picture']); ?>" class="player-avatar my-team-avatar">
                            <?php
    else: ?>
                                <div class="player-avatar my-team-avatar"><?php echo strtoupper(substr($player['username'], 0, 1)); ?></div>
                            <?php
    endif; ?>
                            <div class="player-name bg-primary"><?php echo htmlspecialchars($player['username']); ?></div>
                        </div>
                    <?php
    $topInit += 12; // Repartir al inicio
    if ($topInit > 80) {
        $topInit = 10;
        $leftInit += 10;
    }
endforeach;
?>

                    <?php
// Renderizar jugadores del RIVAL
if ($rivalId > 0 && count($rivalPlayers) > 0):
    $topInit = 10;
    $leftInit = 85;
    foreach ($rivalPlayers as $i => $player):
        $pid = "riv_play_" . $player['id'];
?>
                            <div class="draggable-item" id="<?php echo $pid; ?>" style="top: <?php echo $topInit; ?>%; left: <?php echo $leftInit; ?>%;">
                                <?php if (!empty($player['profile_picture'])): ?>
                                    <img src="<?php echo htmlspecialchars($player['profile_picture']); ?>" class="player-avatar rival-avatar">
                                <?php
        else: ?>
                                    <div class="player-avatar rival-avatar"><?php echo strtoupper(substr($player['username'], 0, 1)); ?></div>
                                <?php
        endif; ?>
                                <div class="player-name bg-danger"><?php echo htmlspecialchars($player['username']); ?></div>
                            </div>
                    <?php
        $topInit += 12; // Repartir al inicio
        if ($topInit > 80) {
            $topInit = 10;
            $leftInit -= 10;
        }
    endforeach;
endif;
?>

                </div>
            </div>

        </div>
    </div>

    <script>
        // Sistema robusto de Drag and Drop para PC y Móviles usando Pointer Events
        const draggables = document.querySelectorAll('.draggable-item');
        const container = document.getElementById('pitch-container');
        
        // Guardar posiciones base para el botón de reinicio
        const initialPositions = new Map();
        
        draggables.forEach(el => {
            initialPositions.set(el.id, { top: el.style.top, left: el.style.left });
            
            let isDragging = false;
            let startX, startY;
            let startLeft, startTop;

            el.addEventListener('pointerdown', (e) => {
                isDragging = true;
                el.setPointerCapture(e.pointerId); // Captura los eventos incluso si el ratón sale rápido
                
                // Elevar Z-index
                draggables.forEach(d => d.style.zIndex = d.id === 'ball' ? 50 : 10);
                el.style.zIndex = "100";

                startX = e.clientX;
                startY = e.clientY;
                
                // Obtener posición actual en pixeles relativos al contenedor
                startLeft = el.offsetLeft;
                startTop = el.offsetTop;
                
                // Evita comportamientos por defecto como scroll o arrastre de imágenes nativo
                e.preventDefault(); 
            });

            el.addEventListener('pointermove', (e) => {
                if (!isDragging) return;
                
                let dx = e.clientX - startX;
                let dy = e.clientY - startY;

                let newLeft = startLeft + dx;
                let newTop = startTop + dy;

                // Limitar al contenedor (opcional, aunque es mejor que no desaparezcan)
                const rect = container.getBoundingClientRect();
                const elWidth = el.offsetWidth;
                const elHeight = el.offsetHeight;

                // Restricciones de bordes
                if (newLeft < 0) newLeft = 0;
                if (newTop < 0) newTop = 0;
                if (newLeft + elWidth > rect.width) newLeft = rect.width - elWidth;
                if (newTop + elHeight > rect.height) newTop = rect.height - elHeight;

                // Cambiar la posición visual
                el.style.left = newLeft + 'px';
                el.style.top = newTop + 'px';
                el.style.transform = 'none'; // Quita el transform de inicializado por si acaso (el balon tiene un translate)
            });

            const endDrag = (e) => {
                if(isDragging) {
                    isDragging = false;
                    el.releasePointerCapture(e.pointerId);
                }
            };

            el.addEventListener('pointerup', endDrag);
            el.addEventListener('pointercancel', endDrag);
            
            // Prevenir drag nativo de HTML sobre imágenes que estropea el pointerdown
            const img = el.querySelector('img');
            if(img) {
                img.addEventListener('dragstart', (e) => e.preventDefault());
            }
        });

        // Función para resetear las posiciones a la formación inicial
        function resetPositions() {
            draggables.forEach(el => {
                const initPos = initialPositions.get(el.id);
                if(initPos) {
                    el.style.top = initPos.top;
                    el.style.left = initPos.left;
                    if(el.id === 'ball') {
                        el.style.transform = "translate(-50%, -50%)"; // Recupera el transform de centrado exacto original
                    } else {
                        el.style.transform = "none";
                    }
                }
                
                // Reset Z-Index
                if (el.id === 'ball') {
                    el.style.zIndex = "50";
                } else {
                    el.style.zIndex = "10";
                }
            });
        }

        // Sistema para Ocultar/Mostrar jugadores desde el menú
        document.querySelectorAll('.player-toggle-cb').forEach(cb => {
            cb.addEventListener('change', function() {
                const targetId = this.getAttribute('data-target');
                const targetEl = document.getElementById(targetId);
                if (targetEl) {
                    targetEl.style.display = this.checked ? 'flex' : 'none';
                }
            });
        });

        // Guardado de Tácticas JS
        function prepareSave() {
            const positions = {};
            draggables.forEach(el => {
                positions[el.id] = {
                    top: el.style.top,
                    left: el.style.left,
                    hidden: el.style.display === 'none'
                };
            });
            document.getElementById('positionsPayload').value = JSON.stringify(positions);
            document.getElementById('saveTacticForm').submit();
        }

        // Cargar tácica si se seleccionó
        const loadedPositionsObj = <?php echo $loadedPositions ? $loadedPositions : 'null'; ?>;
        if (loadedPositionsObj) {
            draggables.forEach(el => {
                if (loadedPositionsObj[el.id]) {
                    el.style.top = loadedPositionsObj[el.id].top;
                    el.style.left = loadedPositionsObj[el.id].left;
                    if (el.id === 'ball') {
                        el.style.transform = "translate(-50%, -50%)";
                    }
                    if (loadedPositionsObj[el.id].hidden) {
                        el.style.display = 'none';
                        const cb = document.querySelector(`.player-toggle-cb[data-target="${el.id}"]`);
                        if(cb) cb.checked = false;
                    }
                }
            });
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

<?php
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/cloudinary.php';

if (!isset($_SESSION['user_id'])) { header("Location: index.php"); exit; }
$me = $pdo->prepare("SELECT role FROM users WHERE id = ?");
$me->execute([$_SESSION['user_id']]);
$meInfo = $me->fetch();
if (!$meInfo || $meInfo['role'] !== 'admin') { header("Location: index.php"); exit; }

$msg = '';

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
    $teamId = (int)$_POST['team_id'];
    $url = cloudinary_upload($_FILES['logo']['tmp_name'], 'goats-league/logos', 'team_' . $teamId . '_' . time());
    if ($url) {
        $pdo->prepare("UPDATE teams SET logo = ? WHERE id = ?")->execute([$url, $teamId]);
        $msg = '<div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Logo actualizado correctamente.</div>';
    } else {
        $msg = '<div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> Error al subir el logo a Cloudinary.</div>';
    }
}

$teams = $pdo->query("SELECT id, name, logo FROM teams ORDER BY name ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Logos Equipos - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="./style.css">
</head>
<body class="text-white bg-dark">
<div class="container mt-5 mb-5 px-3">
    <div class="d-flex justify-content-between align-items-center border-bottom border-secondary pb-3 mb-4">
        <h2 class="fw-bold m-0"><i class="bi bi-image-fill text-warning me-2"></i> Logos de Equipos</h2>
        <a href="admin_mercado.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Admin</a>
    </div>

    <?php echo $msg; ?>

    <div class="row g-4">
        <?php foreach($teams as $t): ?>
        <div class="col-md-6">
            <div class="card bg-dark border-secondary shadow">
                <div class="card-body d-flex align-items-center gap-3">
                    <!-- Logo actual -->
                    <?php if (!empty($t['logo'])): ?>
                        <img src="<?php echo htmlspecialchars($t['logo']); ?>" style="width:60px;height:60px;object-fit:cover;" class="rounded-circle border border-secondary shadow">
                    <?php else: ?>
                        <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center text-white fw-bold shadow" style="width:60px;height:60px;font-size:22px;">
                            <?php echo strtoupper(substr($t['name'],0,1)); ?>
                        </div>
                    <?php endif; ?>

                    <div class="flex-grow-1">
                        <div class="fw-bold text-light mb-2"><?php echo htmlspecialchars($t['name']); ?></div>
                        <form method="POST" enctype="multipart/form-data" class="d-flex gap-2 align-items-center flex-wrap">
                            <input type="hidden" name="team_id" value="<?php echo $t['id']; ?>">
                            <input type="file" name="logo" accept="image/*" class="form-control form-control-sm bg-dark text-white border-secondary" required style="max-width:220px;">
                            <button type="submit" class="btn btn-sm btn-warning fw-bold"><i class="bi bi-cloud-upload-fill"></i> Subir</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

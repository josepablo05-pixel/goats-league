<?php
$target_dir = "/app/data/";
$target_file = $target_dir . "database.sqlite";

// 1. Intentar crear la carpeta si no existe y dar permisos
if (!file_exists($target_dir)) {
    mkdir($target_dir, 0777, true);
}
chmod($target_dir, 0777);

if (isset($_POST["submit"])) {
    if (move_uploaded_file($_FILES["db_file"]["tmp_name"], $target_file)) {
        chmod($target_file, 0666); // Permiso de lectura/escritura para la DB
        echo "✅ ¡Éxito! Archivo subido a: " . $target_file;
    }
    else {
        echo "❌ Error al subir. Detalles: ";
        print_r($_FILES);
        echo "<br>¿La carpeta es escribible?: " . (is_writable($target_dir) ? 'SÍ' : 'NO');
    }
}
?>

<form action="" method="post" enctype="multipart/form-data" style="margin-top:20px;">
  Selecciona tu archivo .sqlite:
  <input type="file" name="db_file" id="db_file">
  <input type="submit" value="Subir Base de Datos" name="submit">
</form>

<hr>
<h3>Estado del sistema:</h3>
<?php
echo "Ruta actual: " . getcwd() . "<br>";
echo "Ruta destino: " . $target_dir . "<br>";
echo "Existe destino: " . (file_exists($target_dir) ? 'SÍ' : 'NO') . "<br>";
?>
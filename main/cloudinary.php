<?php
/**
 * Cloudinary Helper — sube una imagen mediante la API REST sin SDK.
 * Usa variables de entorno o constantes definidas aquí si no las hay.
 */

define('CLOUDINARY_CLOUD_NAME', getenv('CLOUDINARY_CLOUD_NAME') ?: 'dx45ahyhn');
define('CLOUDINARY_API_KEY',    getenv('CLOUDINARY_API_KEY')    ?: '187943889261986');
define('CLOUDINARY_API_SECRET', getenv('CLOUDINARY_API_SECRET') ?: '4OBuLFOgaJywZxOR3NvV1AmYYNw');

/**
 * Sube un archivo local a Cloudinary y devuelve la URL segura.
 * 
 * @param string $filePath  Ruta local del archivo temporal (ejemplo: $_FILES['file']['tmp_name'])
 * @param string $folder    Carpeta de destino en Cloudinary (ejemplo: 'goats-league/profiles')
 * @param string $publicId  ID público opcional (sin extensión). Si no se pasa, Cloudinary genera uno.
 * @return string|null      URL https de la imagen subida, o null en caso de error.
 */
function cloudinary_upload(string $filePath, string $folder = 'goats-league', string $publicId = ''): ?string {
    $timestamp = time();

    $params = ['folder' => $folder, 'timestamp' => $timestamp];
    if (!empty($publicId)) $params['public_id'] = $publicId;

    // Sort and build signature string WITHOUT URL-encoding (Cloudinary needs raw values)
    ksort($params);
    $parts = [];
    foreach ($params as $k => $v) {
        $parts[] = $k . '=' . $v;
    }
    $paramStr  = implode('&', $parts);
    $signature = sha1($paramStr . CLOUDINARY_API_SECRET);

    $postFields = array_merge($params, [
        'api_key'   => CLOUDINARY_API_KEY,
        'signature' => $signature,
        'file'      => new CURLFile($filePath),
    ]);

    $ch = curl_init("https://api.cloudinary.com/v1_1/" . CLOUDINARY_CLOUD_NAME . "/image/upload");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['secure_url'] ?? null;
}
?>

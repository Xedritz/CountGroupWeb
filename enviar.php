<?php
/* Count Group SAS - manejo de formularios (cotización y hoja de vida) */

// ===== CONFIGURACIÓN =====
$DESTINO   = 'info@countgroupsas.com';      // a dónde llegan las solicitudes
$REMITENTE = 'web@countgroupsas.com';       // buzón del dominio que envía (debe existir o estar autorizado en SPF)
$MAX_MB    = 5;
// =========================

header('X-Content-Type-Options: nosniff');

function volver($ok, $tipo = '') {
    header('Location: ' . ($ok ? 'gracias.html' . ($tipo ? '?t=' . $tipo : '') : 'error.html'), true, 303);
    exit;
}
function limpio($v, $max = 500) {
    $v = trim((string)($v ?? ''));
    $v = str_replace(["\r", "\n", "%0a", "%0d"], ' ', $v);
    return mb_substr(strip_tags($v), 0, $max);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') volver(false);
if (!empty($_POST['sitio_web'])) volver(true);          // honeypot: bot, se descarta en silencio
if (empty($_POST['acepta'])) volver(false);

$tipo     = ($_POST['tipo'] ?? '') === 'hoja_de_vida' ? 'hoja_de_vida' : 'cotizacion';
$nombre   = limpio($_POST['nombre'] ?? '', 120);
$empresa  = limpio($_POST['empresa'] ?? '', 120);
$correo   = filter_var(trim($_POST['correo'] ?? ''), FILTER_VALIDATE_EMAIL);
$telefono = limpio($_POST['telefono'] ?? '', 30);
$servicio = limpio($_POST['servicio'] ?? '', 120);
$mensaje  = mb_substr(strip_tags(trim($_POST['mensaje'] ?? '')), 0, 3000);

if (!$nombre || !$correo || !$telefono || !$servicio) volver(false);
if ($tipo === 'cotizacion' && !$mensaje) volver(false);

// Límite simple por IP: 1 envío cada 30 segundos
$ipHash = sys_get_temp_dir() . '/cg_' . md5($_SERVER['REMOTE_ADDR'] ?? 'x');
if (file_exists($ipHash) && time() - filemtime($ipHash) < 30) volver(false);
@touch($ipHash);

$asunto = $tipo === 'cotizacion'
    ? "Solicitud de cotización - $servicio - $nombre"
    : "Hoja de vida - $servicio - $nombre";

$cuerpo  = ($tipo === 'cotizacion' ? "Nueva solicitud de cotización" : "Nueva hoja de vida") . " desde countgroupsas.com\n\n";
$cuerpo .= "Nombre: $nombre\n";
if ($empresa) $cuerpo .= "Empresa: $empresa\n";
$cuerpo .= "Correo: $correo\nTeléfono: $telefono\n";
$cuerpo .= ($tipo === 'cotizacion' ? "Servicio: " : "Área de interés: ") . "$servicio\n\n";
$cuerpo .= "Mensaje:\n" . ($mensaje ?: '(sin mensaje)') . "\n\n";
$cuerpo .= "Autorizó tratamiento de datos: Sí\nFecha: " . date('Y-m-d H:i') . "\nIP: " . ($_SERVER['REMOTE_ADDR'] ?? '') . "\n";

$boundary = 'CG' . bin2hex(random_bytes(12));
$headers  = "From: Sitio web Count Group <$REMITENTE>\r\n";
$headers .= "Reply-To: $nombre <$correo>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n";

$msg  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
$msg .= chunk_split(base64_encode($cuerpo)) . "\r\n";

if ($tipo === 'hoja_de_vida') {
    $f = $_FILES['cv'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] > $MAX_MB * 1024 * 1024) volver(false);
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $permitidos = [
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    ];
    if (!isset($permitidos[$ext])) volver(false);
    $mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : 'application/octet-stream';
    if (!in_array($mime, $permitidos[$ext], true)) volver(false);

    $nombreArchivo = 'HV_' . preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre)) . '.' . $ext;
    $msg .= "--$boundary\r\nContent-Type: $mime; name=\"$nombreArchivo\"\r\n";
    $msg .= "Content-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$nombreArchivo\"\r\n\r\n";
    $msg .= chunk_split(base64_encode(file_get_contents($f['tmp_name']))) . "\r\n";
}
$msg .= "--$boundary--";

$ok = mail($DESTINO, '=?UTF-8?B?' . base64_encode($asunto) . '?=', $msg, $headers, "-f$REMITENTE");
volver($ok, $tipo === 'cotizacion' ? 'c' : 'h');

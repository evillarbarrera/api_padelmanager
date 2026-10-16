<?php
/**
 * Desuscripción Legal de Comunicaciones Comerciales Padelblox
 * Cumple con Ley N° 19.496 (Art. 28 B) y Ley N° 19.628 (Chile)
 */

$email = filter_var($_GET['email'] ?? '', FILTER_VALIDATE_EMAIL);
$leads_file = __DIR__ . '/leads_clubs_chile.json';
$updated = false;

if ($email && file_exists($leads_file)) {
    $data = json_decode(file_get_contents($leads_file), true);
    if ($data && isset($data['clubs'])) {
        foreach ($data['clubs'] as &$c) {
            if (strcasecmp($c['email'], $email) === 0) {
                $c['estado_envio'] = 'desuscrito';
                $c['fecha_desuscripcion'] = date('Y-m-d H:i:s');
                $updated = true;
            }
        }
        if ($updated) {
            file_put_contents($leads_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Desuscripción - Padelblox Chile</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
    .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 36px 30px; max-width: 480px; width: 100%; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.4); }
    .icon { font-size: 3rem; margin-bottom: 12px; }
    h1 { font-size: 1.4rem; color: #ffffff; margin: 0 0 10px; font-weight: 800; }
    p { font-size: 0.95rem; color: #94a3b8; line-height: 1.5; margin: 0 0 20px; }
    .email-box { background: #0f172a; padding: 8px 14px; border-radius: 8px; color: #38bdf8; font-weight: 700; word-break: break-all; margin-bottom: 24px; display: inline-block; }
    .btn { display: inline-block; background: #25D366; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 10px; font-weight: 800; font-size: 0.9rem; transition: background 0.2s; }
    .btn:hover { background: #1ebe5d; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">✅</div>
    <h1>Desuscripción Confirmada</h1>
    <p>Hemos actualizado tus preferencias. No recibirás más comunicaciones promocionales de Padelblox en la dirección:</p>
    <div class="email-box"><?php echo htmlspecialchars($email ?: 'Tu correo electrónico'); ?></div>
    <p>Si fue un error o deseas volver a recibir actualizaciones de torneos y gestión de clubes, puedes escribirnos en cualquier momento.</p>
    <a href="https://padelblox.cl" class="btn">Volver a Padelblox.cl</a>
  </div>
</body>
</html>

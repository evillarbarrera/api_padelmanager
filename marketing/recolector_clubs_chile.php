<?php
/**
 * Recolector y Administrador de Leads de Clubes de Pádel en Chile
 * Padelblox Marketing Engine 2026
 */

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

$leads_file = __DIR__ . '/leads_clubs_chile.json';

// Cargar leads existentes
function get_leads($file) {
    if (!file_exists($file)) {
        return ['campana' => 'Padelblox Chile', 'clubs' => []];
    }
    return json_decode(file_get_contents($file), true) ?: ['clubs' => []];
}

// Guardar leads
function save_leads($file, $data) {
    $data['total_clubs'] = count($data['clubs']);
    $data['actualizado_el'] = date('Y-m-d H:i:s');
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Extraer correos de un sitio web (Web Scraping de contacto)
function scrapear_correos_url($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return [];
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $html = curl_exec($ch);
    curl_close($ch);

    if (!$html) return [];

    // Regex para buscar emails en el código fuente
    $pattern = '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i';
    preg_match_all($pattern, $html, $matches);

    $emails = [];
    if (!empty($matches[0])) {
        foreach (array_unique($matches[0]) as $e) {
            $e = strtolower(trim($e));
            // Filtrar extensiones falsas comunes
            if (!preg_match('/\.(png|jpg|jpeg|gif|webp|svg|css|js)$/i', $e)) {
                $emails[] = $e;
            }
        }
    }
    return $emails;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');
$data = get_leads($leads_file);

// API JSON si se solicita formato json
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
}

// Agregar club manual
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add') {
    $nombre = trim($_POST['nombre_club'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $comuna = trim($_POST['comuna'] ?? 'Santiago');
    $region = trim($_POST['region'] ?? 'Región Metropolitana');
    $telefono = trim($_POST['telefono'] ?? '');
    $canchas = intval($_POST['canchas_aprox'] ?? 4);
    $sitio = trim($_POST['sitio_web'] ?? '');

    if ($nombre && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Verificar duplicados
        $existe = false;
        foreach ($data['clubs'] as $c) {
            if (strcasecmp($c['email'], $email) === 0) {
                $existe = true;
                break;
            }
        }
        if (!$existe) {
            $new_id = count($data['clubs']) > 0 ? max(array_column($data['clubs'], 'id')) + 1 : 1;
            $data['clubs'][] = [
                'id' => $new_id,
                'nombre_club' => $nombre,
                'comuna' => $comuna,
                'region' => $region,
                'email' => $email,
                'telefono' => $telefono,
                'canchas_aprox' => $canchas,
                'sitio_web' => $sitio,
                'estado_envio' => 'pendiente'
            ];
            save_leads($leads_file, $data);
            $msg = "✅ Club '$nombre' agregado exitosamente con correo $email.";
        } else {
            $msg = "⚠️ El correo $email ya existe en la base de datos.";
        }
    } else {
        $msg = "❌ Error: Nombre de club y email válido son requeridos.";
    }
}

// Scraping desde URL
$scraped_emails = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'scrape') {
    $url = trim($_POST['url_scrape'] ?? '');
    if ($url) {
        $scraped_emails = scrapear_correos_url($url);
        if (!empty($scraped_emails)) {
            $msg = "🔍 Encontrados " . count($scraped_emails) . " correos en $url.";
        } else {
            $msg = "⚠️ No se detectaron correos públicos en $url (puede requerir JavaScript o estar ofuscado).";
        }
    }
}

// Estadísticas
$total = count($data['clubs']);
$pendientes = 0;
$enviados = 0;
$desuscritos = 0;
foreach ($data['clubs'] as $c) {
    if (($c['estado_envio'] ?? '') === 'enviado') $enviados++;
    elseif (($c['estado_envio'] ?? '') === 'desuscrito') $desuscritos++;
    else $pendientes++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Recolector de Clubes de Pádel Chile - Padelblox</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; background: #0b1120; color: #f8fafc; margin: 0; padding: 24px; }
    .container { max-width: 1200px; margin: 0 auto; }
    .header-bar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; border-bottom: 1px solid #1e293b; padding-bottom: 16px; flex-wrap: wrap; gap: 12px; }
    h1 { font-size: 1.6rem; font-weight: 800; color: #ffffff; margin: 0; display: flex; align-items: center; gap: 10px; }
    .badge-chile { background: #dc2626; color: #ffffff; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 900; }
    .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
    .stat-card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 4px; }
    .stat-label { font-size: 0.75rem; font-weight: 700; color: #94a3b8; text-transform: uppercase; }
    .stat-num { font-size: 1.8rem; font-weight: 900; color: #38bdf8; }
    .stat-num.pend { color: #fbbf24; }
    .stat-num.env { color: #4ade80; }
    .stat-num.des { color: #f87171; }
    
    .panel-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
    @media (max-width: 850px) { .panel-grid { grid-template-columns: 1fr; } }
    .box { background: #1e293b; border: 1px solid #334155; border-radius: 14px; padding: 20px; }
    .box h2 { font-size: 1.1rem; margin: 0 0 14px; color: #ffffff; display: flex; align-items: center; gap: 8px; }
    
    .form-group { margin-bottom: 12px; display: flex; flex-direction: column; gap: 4px; }
    label { font-size: 0.8rem; font-weight: 700; color: #cbd5e1; }
    input, select { background: #0f172a; border: 1px solid #334155; color: #ffffff; padding: 9px 12px; border-radius: 8px; font-size: 0.85rem; outline: none; }
    input:focus, select:focus { border-color: #38bdf8; }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    
    .btn { background: #2563eb; color: #ffffff; border: none; padding: 10px 16px; border-radius: 8px; font-weight: 800; cursor: pointer; transition: all 0.2s; font-size: 0.85rem; display: inline-flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; }
    .btn:hover { background: #1d4ed8; }
    .btn.wa { background: #25D366; }
    .btn.wa:hover { background: #1ebd5d; }
    .btn.secondary { background: #334155; }
    .btn.secondary:hover { background: #475569; }
    
    .alert-box { background: #0c4a6e; border: 1px solid #0284c7; color: #e0f2fe; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 0.88rem; }
    
    /* Tabla */
    .table-container { background: #1e293b; border: 1px solid #334155; border-radius: 14px; overflow-x: auto; padding: 16px; }
    table { width: 100%; border-collapse: collapse; font-size: 0.82rem; }
    th { text-align: left; padding: 10px 12px; color: #94a3b8; font-weight: 800; border-bottom: 1px solid #334155; text-transform: uppercase; font-size: 0.72rem; }
    td { padding: 10px 12px; border-bottom: 1px solid #263349; color: #e2e8f0; }
    tr:hover td { background: #243048; }
    .badge { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 800; }
    .badge.pendiente { background: #78350f; color: #fde68a; }
    .badge.enviado { background: #064e3b; color: #a7f3d0; }
    .badge.desuscrito { background: #7f1d1d; color: #fecaca; }
  </style>
</head>
<body>
  <div class="container">
    
    <div class="header-bar">
      <h1>🎾 Directorio de Clubes de Pádel Chile <span class="badge-chile">🇨🇱 MKT</span></h1>
      <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <a href="enviar_promocion_padelblox.php" class="btn wa">✉️ Ver / Enviar Promoción</a>
        <?php if ($pendientes > 0): ?>
          <a href="enviar_promocion_padelblox.php?action=send&modo=produccion&token=PADELBLOX_CHILE_2026" 
             onclick="return confirm('⚠️ ¿Estás seguro de enviar la campaña oficial a los <?php echo $pendientes; ?> clubes de pádel pendientes en Chile?');"
             class="btn" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: #ffffff;">
            🔥 Enviar a Todos (<?php echo $pendientes; ?>)
          </a>
        <?php endif; ?>
        <a href="recolector_clubs_chile.php?format=json" target="_blank" class="btn secondary">📥 Exportar JSON</a>
      </div>
    </div>

    <?php if ($msg): ?>
      <div class="alert-box"><?php echo htmlspecialchars($msg); ?></div>
    <?php endif; ?>

    <!-- Estadísticas -->
    <div class="stat-cards">
      <div class="stat-card">
        <span class="stat-label">Total Clubes Registrados</span>
        <span class="stat-num"><?php echo $total; ?></span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Pendientes de Envío</span>
        <span class="stat-num pend"><?php echo $pendientes; ?></span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Promociones Enviadas</span>
        <span class="stat-num env"><?php echo $enviados; ?></span>
      </div>
      <div class="stat-card">
        <span class="stat-label">Desuscritos (Opt-Out)</span>
        <span class="stat-num des"><?php echo $desuscritos; ?></span>
      </div>
    </div>

    <div class="panel-grid">
      <!-- Agregar Club Manual -->
      <div class="box">
        <h2>➕ Agregar Club de Pádel Manualmente</h2>
        <form method="POST" action="recolector_clubs_chile.php">
          <input type="hidden" name="action" value="add">
          <div class="form-group">
            <label>Nombre del Club:</label>
            <input type="text" name="nombre_club" placeholder="Ej: Club Padel Los Trapenses" required>
          </div>
          <div class="form-group">
            <label>Correo Electrónico (Contacto/Gerencia):</label>
            <input type="email" name="email" placeholder="contacto@club.cl" required>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Comuna / Ciudad:</label>
              <input type="text" name="comuna" placeholder="Ej: Lo Barnechea" required>
            </div>
            <div class="form-group">
              <label>Región:</label>
              <select name="region">
                <option value="Región Metropolitana">Región Metropolitana</option>
                <option value="Región de Valparaíso">Región de Valparaíso</option>
                <option value="Región del Biobío">Región del Biobío</option>
                <option value="Región de Coquimbo">Región de Coquimbo</option>
                <option value="Región de Antofagasta">Región de Antofagasta</option>
                <option value="Región de O'Higgins">Región de O'Higgins</option>
                <option value="Región de La Araucanía">Región de La Araucanía</option>
                <option value="Región de Los Lagos">Región de Los Lagos</option>
                <option value="Otra Región">Otra Región</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Teléfono / WhatsApp:</label>
              <input type="text" name="telefono" placeholder="+56 9 1234 5678">
            </div>
            <div class="form-group">
              <label>Canchas Aprox:</label>
              <input type="number" name="canchas_aprox" value="6" min="1" max="50">
            </div>
          </div>
          <div class="form-group">
            <label>Sitio Web o Instagram:</label>
            <input type="text" name="sitio_web" placeholder="https://instagram.com/miclub">
          </div>
          <button type="submit" class="btn" style="width: 100%; margin-top: 6px;">Guardar Club en Directorio</button>
        </form>
      </div>

      <!-- Scraper / Extractor desde Sitio Web -->
      <div class="box">
        <h2>🔍 Extractor de Correos Web (Scraper Público)</h2>
        <p style="font-size: 0.82rem; color: #94a3b8; line-height: 1.4; margin-top: 0;">
          Ingresa la URL del sitio web de un club de pádel (ej: sección <code>/contacto</code> o página principal) para extraer los correos públicos detectados.
        </p>
        <form method="POST" action="recolector_clubs_chile.php">
          <input type="hidden" name="action" value="scrape">
          <div class="form-group">
            <label>URL del Club a Analizar:</label>
            <input type="url" name="url_scrape" placeholder="https://www.clubdepadel.cl/contacto" required>
          </div>
          <button type="submit" class="btn secondary" style="width: 100%;">Escanear Sitio Web</button>
        </form>

        <?php if (!empty($scraped_emails)): ?>
          <div style="margin-top: 16px; background: #0f172a; padding: 12px; border-radius: 8px; border: 1px solid #334155;">
            <strong style="font-size: 0.8rem; color: #4ade80;">Correos detectados:</strong>
            <ul style="margin: 8px 0 0; padding-left: 18px; font-size: 0.8rem; color: #38bdf8;">
              <?php foreach ($scraped_emails as $se): ?>
                <li><?php echo htmlspecialchars($se); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Listado Completo -->
    <div class="table-container">
      <h2 style="font-size: 1.1rem; margin: 0 0 14px; color: #ffffff;">📋 Listado de Clubes de Pádel Registrados en Chile</h2>
      <table>
        <thead>
          <tr>
            <th>ID</th>
            <th>Nombre del Club</th>
            <th>Comuna / Ciudad</th>
            <th>Región</th>
            <th>Email</th>
            <th>WhatsApp</th>
            <th>Canchas</th>
            <th>Estado Envío</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($data['clubs'] as $club): ?>
            <tr>
              <td>#<?php echo $club['id']; ?></td>
              <td><strong><?php echo htmlspecialchars($club['nombre_club']); ?></strong></td>
              <td><?php echo htmlspecialchars($club['comuna']); ?></td>
              <td><?php echo htmlspecialchars($club['region']); ?></td>
              <td><a href="mailto:<?php echo htmlspecialchars($club['email']); ?>" style="color: #38bdf8; text-decoration: none;"><?php echo htmlspecialchars($club['email']); ?></a></td>
              <td><?php echo htmlspecialchars($club['telefono'] ?: '-'); ?></td>
              <td>🎾 <?php echo htmlspecialchars($club['canchas_aprox']); ?></td>
              <td>
                <span class="badge <?php echo htmlspecialchars($club['estado_envio'] ?? 'pendiente'); ?>">
                  <?php echo ucfirst(htmlspecialchars($club['estado_envio'] ?? 'pendiente')); ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>
</body>
</html>

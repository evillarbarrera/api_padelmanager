<?php
/**
 * PADELBLOX API CONFIGURATION & CORE HELPERS
 */

// Habilitar reporte de errores en desarrollo (ajustar en producción si aplica)
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Configuración de encabezados CORS y JSON
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, X-Authorization, Content-Type, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

// Responder inmediatamente a peticiones OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Función helper para habilitar compresión Gzip y gestión de caché HTTP
 */
function setupResponseOptimizations($cacheSeconds = 0) {
    if (!headers_sent() && !ob_start("ob_gzhandler")) {
        ob_start();
    }
    
    if ($cacheSeconds > 0) {
        header("Cache-Control: public, max-age=" . $cacheSeconds);
        header("Expires: " . gmdate('D, d M Y H:i:s', time() + $cacheSeconds) . ' GMT');
    } else {
        header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
    }
}

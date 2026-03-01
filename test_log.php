<?php
header('Content-Type: application/json');

// Crear carpeta logs si no existe
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    if (!mkdir($logDir, 0755, true)) {
        echo json_encode(['error' => 'No se pudo crear carpeta logs', 'dir' => $logDir]);
        exit;
    }
}

// Intentar escribir un archivo de prueba
$testFile = $logDir . '/test.log';
$result = file_put_contents($testFile, "Test - " . date('Y-m-d H:i:s') . "\n");

if ($result === false) {
    echo json_encode(['error' => 'No se pudo escribir archivo', 'file' => $testFile]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Archivo de prueba creado exitosamente',
    'logDir' => $logDir,
    'testFile' => $testFile,
    'fileExists' => file_exists($testFile),
    'fileContent' => file_get_contents($testFile)
]);
?>

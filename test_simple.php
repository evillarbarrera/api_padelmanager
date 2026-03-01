<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Simplísimo test
echo json_encode(['success' => true, 'message' => 'El archivo PHP funciona']);
?>

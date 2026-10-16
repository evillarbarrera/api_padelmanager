<?php

function ensureUserConsentsTable($conn) {
    $sql = "CREATE TABLE IF NOT EXISTS user_consents_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT NOT NULL,
        consent_type VARCHAR(50) NOT NULL,
        status TINYINT(1) NOT NULL DEFAULT 1,
        ip_address VARCHAR(45) NULL,
        user_agent TEXT NULL,
        policy_version VARCHAR(20) DEFAULT 'v1.0',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX (usuario_id),
        INDEX (consent_type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $conn->query($sql);

    // Ensure brand_transition_acknowledged column exists in usuarios table
    $check = $conn->query("SHOW COLUMNS FROM usuarios LIKE 'brand_transition_acknowledged'");
    if ($check && $check->num_rows == 0) {
        $conn->query("ALTER TABLE usuarios ADD brand_transition_acknowledged TINYINT(1) DEFAULT 0");
    }
}

function registrarConsentimiento($conn, $usuario_id, $consent_type, $status = 1, $policy_version = 'v1.0') {
    ensureUserConsentsTable($conn);
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    }
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $statusInt = $status ? 1 : 0;
    
    $stmt = $conn->prepare("INSERT INTO user_consents_log (usuario_id, consent_type, status, ip_address, user_agent, policy_version) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param("isisss", $usuario_id, $consent_type, $statusInt, $ip, $userAgent, $policy_version);
        $stmt->execute();
    }
}

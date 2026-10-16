<?php
// Helper para asegurar tablas del perfil público de entrenadores y reseñas
function ensureCoachPublicProfileTables($conn) {
    static $initialized = false;
    if ($initialized) return;

    $sql1 = "CREATE TABLE IF NOT EXISTS entrenador_perfil_publico (
        entrenador_id INT(11) NOT NULL PRIMARY KEY,
        slug VARCHAR(120) NOT NULL UNIQUE,
        publicado TINYINT(1) NOT NULL DEFAULT 0,
        titulo VARCHAR(160) DEFAULT NULL,
        bio TEXT DEFAULT NULL,
        especialidades LONGTEXT DEFAULT NULL,
        niveles LONGTEXT DEFAULT NULL,
        anios_experiencia INT(4) DEFAULT 1,
        certificaciones TEXT DEFAULT NULL,
        comunas LONGTEXT DEFAULT NULL,
        clubes_ids LONGTEXT DEFAULT NULL,
        banner_url VARCHAR(255) DEFAULT NULL,
        fondo_url VARCHAR(255) DEFAULT NULL,
        telefono_contacto VARCHAR(50) DEFAULT NULL,
        whatsapp VARCHAR(50) DEFAULT NULL,
        instagram VARCHAR(100) DEFAULT NULL,
        facebook VARCHAR(100) DEFAULT NULL,
        google_business_url VARCHAR(255) DEFAULT NULL,
        color_primary VARCHAR(20) DEFAULT '#0f172a',
        color_accent VARCHAR(20) DEFAULT '#ccff00',
        mostrar_packs TINYINT(1) NOT NULL DEFAULT 1,
        mostrar_horarios TINYINT(1) NOT NULL DEFAULT 1,
        mostrar_resenas TINYINT(1) NOT NULL DEFAULT 1,
        visitas INT(11) NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_epp_publicado (publicado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql2 = "CREATE TABLE IF NOT EXISTS resenas_entrenador (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        entrenador_id INT(11) NOT NULL,
        alumno_id INT(11) NOT NULL,
        rating TINYINT(1) NOT NULL,
        comentario TEXT NOT NULL,
        respuesta_entrenador TEXT DEFAULT NULL,
        fecha_respuesta DATETIME DEFAULT NULL,
        visible TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_entrenador_alumno (entrenador_id, alumno_id),
        INDEX idx_resenas_entrenador (entrenador_id, visible),
        INDEX idx_resenas_alumno (alumno_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Tabla para certificados y diplomas subidos por el entrenador
    $sql3 = "CREATE TABLE IF NOT EXISTS entrenador_certificados (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        entrenador_id INT(11) NOT NULL,
        titulo VARCHAR(191) NOT NULL,
        institucion VARCHAR(191) DEFAULT NULL,
        archivo_url VARCHAR(255) NOT NULL,
        tipo_archivo VARCHAR(50) DEFAULT 'image',
        orden INT DEFAULT 0,
        activo TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_cert_entrenador (entrenador_id, activo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    @$conn->query($sql1);
    @$conn->query($sql2);
    @$conn->query($sql3);

    // Migración idempotente para asegurar columna fondo_url
    $chkCol = @$conn->query("SHOW COLUMNS FROM entrenador_perfil_publico LIKE 'fondo_url'");
    if ($chkCol && $chkCol->num_rows === 0) {
        @$conn->query("ALTER TABLE entrenador_perfil_publico ADD COLUMN fondo_url VARCHAR(255) DEFAULT NULL AFTER banner_url");
    }

    $initialized = true;
}

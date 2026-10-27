<?php
// Helper centralizado para ciclo de vida de suscripciones, renovación mensual y bloqueo por no pago

function ensureSubscriptionTablesAndColumns($conn) {
    // 1. Tabla de historial de pagos y renovaciones
    @$conn->query("
        CREATE TABLE IF NOT EXISTS pagos_suscripciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan_id INT NOT NULL,
            monto DECIMAL(10,2) NOT NULL,
            moneda VARCHAR(10) DEFAULT 'CLP',
            metodo_pago VARCHAR(50) DEFAULT 'Mercado Pago',
            payment_id VARCHAR(100) NULL,
            preapproval_id VARCHAR(100) NULL,
            fecha_pago DATETIME DEFAULT CURRENT_TIMESTAMP,
            periodo_inicio DATETIME NOT NULL,
            periodo_fin DATETIME NOT NULL,
            estado VARCHAR(30) DEFAULT 'aprobado',
            INDEX(user_id),
            INDEX(fecha_pago),
            INDEX(payment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // 2. Garantizar columnas en usuarios
    $colsRes = @$conn->query("SHOW COLUMNS FROM usuarios");
    $existing = [];
    if ($colsRes) {
        while ($r = $colsRes->fetch_assoc()) {
            $existing[strtolower($r['Field'])] = true;
        }
    }

    if (!isset($existing['subscription_end_date'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN subscription_end_date DATETIME NULL");
    }
    if (!isset($existing['subscription_status'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN subscription_status VARCHAR(50) DEFAULT 'inactive'");
    }
    if (!isset($existing['last_subscription_payment'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN last_subscription_payment DATETIME NULL");
    }
    if (!isset($existing['plan_id'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN plan_id INT DEFAULT 0");
    }
    if (!isset($existing['mp_preapproval_id'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN mp_preapproval_id VARCHAR(100) NULL");
    }
    if (!isset($existing['paypal_sub_id'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN paypal_sub_id VARCHAR(100) NULL");
    }
    if (!isset($existing['card_last_four'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN card_last_four VARCHAR(10) NULL");
    }
    if (!isset($existing['card_brand'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN card_brand VARCHAR(30) NULL DEFAULT 'Visa'");
    }
    if (!isset($existing['card_holder'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN card_holder VARCHAR(150) NULL");
    }
    if (!isset($existing['card_expiry'])) {
        @$conn->query("ALTER TABLE usuarios ADD COLUMN card_expiry VARCHAR(10) NULL");
    }
}

function isNicoRuizUser($conn, $user_id = 0, $target_user = null) {
    $uid = $user_id ? intval($user_id) : intval($target_user['id'] ?? 0);

    // 1. Revisar target_user en memoria
    if ($target_user) {
        $nombre = strtolower(trim($target_user['nombre'] ?? ''));
        if (
            strpos($nombre, 'nico ruiz') !== false || 
            strpos($nombre, 'nicolas ruiz') !== false ||
            strpos($nombre, 'nicolás ruiz') !== false
        ) {
            return true;
        }

        $usuario = strtolower(trim($target_user['usuario'] ?? ''));
        if (
            strpos($usuario, 'nicoruiz') !== false || 
            strpos($usuario, 'nico.ruiz') !== false || 
            strpos($usuario, 'nico_ruiz') !== false ||
            strpos($usuario, 'nicolasruiz') !== false ||
            strpos($usuario, 'nicolas.ruiz') !== false ||
            strpos($usuario, 'nicolas_ruiz') !== false
        ) {
            return true;
        }
    }

    if (!$conn || $uid <= 0) {
        return false;
    }

    // 2. Revisar en la BD sólo con las columnas reales de usuarios: `nombre` y `usuario`
    try {
        $stmt = $conn->prepare("
            SELECT id, nombre, usuario 
            FROM usuarios 
            WHERE id = ? 
              AND (
                LOWER(nombre) LIKE '%nico%ruiz%' OR
                LOWER(nombre) LIKE '%nicolas%ruiz%' OR
                LOWER(nombre) LIKE '%nicolás%ruiz%' OR
                LOWER(usuario) LIKE '%nicoruiz%' OR
                LOWER(usuario) LIKE '%nico.ruiz%' OR
                LOWER(usuario) LIKE '%nico_ruiz%' OR
                LOWER(usuario) LIKE '%nicolas%ruiz%'
              )
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                return true;
            }
        }
    } catch (\Throwable $t) {
        // En caso de cualquier error de consulta, continuar defensivamente
    }

    return false;
}

function isPowerPadelClub($conn, $club_id = 0, $user_id = 0, $target_user = null, $role = '') {
    $r = strtolower(trim($role ?: ($target_user['rol'] ?? '')));
    
    // Si el rol es exclusivamente de entrenador o jugador, NO es la entidad club PowerPadel
    if ($r === 'entrenador' || $r === 'coach' || $r === 'jugador' || $r === 'alumno') {
        return false;
    }

    $cid = intval($club_id ?: ($target_user['club_id'] ?? 0));
    if ($cid == 17 || $cid == 21) {
        return true;
    }

    if (!empty($target_user['club_nombre'])) {
        $cn = strtolower($target_user['club_nombre']);
        if (strpos($cn, 'powerpadel') !== false || strpos($cn, 'power padel') !== false) {
            return true;
        }
    }

    $uid = $user_id ? intval($user_id) : intval($target_user['id'] ?? 0);

    // Revisar usuario del club (en usuarios la columna es `usuario`)
    if (!empty($target_user['usuario']) && (stripos($target_user['usuario'], 'powerpadel') !== false || stripos($target_user['usuario'], 'power padel') !== false)) {
        return true;
    }

    if (!$conn) return false;

    try {
        if ($cid > 0) {
            $stmtCId = $conn->prepare("SELECT nombre FROM clubes WHERE id = ? LIMIT 1");
            if ($stmtCId) {
                $stmtCId->bind_param("i", $cid);
                $stmtCId->execute();
                $cRow = $stmtCId->get_result()->fetch_assoc();
                if ($cRow) {
                    $cName = strtolower($cRow['nombre'] ?? '');
                    if (strpos($cName, 'powerpadel') !== false || strpos($cName, 'power padel') !== false) {
                        return true;
                    }
                }
            }
        }

        if ($uid > 0) {
            $stmtAdmin = $conn->prepare("
                SELECT id FROM clubes 
                WHERE admin_id = ? 
                  AND (LOWER(nombre) LIKE '%power%padel%' OR LOWER(nombre) LIKE '%powerpadel%' OR id IN (17, 21))
                LIMIT 1
            ");
            if ($stmtAdmin) {
                $stmtAdmin->bind_param("i", $uid);
                $stmtAdmin->execute();
                if ($stmtAdmin->get_result()->num_rows > 0) {
                    return true;
                }
            }
        }
    } catch (\Throwable $t) {
        // En caso de error, continuar defensivamente
    }

    return false;
}

function isPowerPadelUser($conn, $user_id = 0, $target_user = null, $club_id = 0, $role = '') {
    return isPowerPadelClub($conn, $club_id, $user_id, $target_user, $role) || isNicoRuizUser($conn, $user_id, $target_user);
}

function evaluateSubscriptionStatus($target_user, $effective_role, $conn = null, $club_id = 0) {
    $is_club = (strpos($effective_role, 'club') !== false || $effective_role === 'administrador_club' || $effective_role === 'admin_club');

    // EXCEPCIÓN 1: CLUB OFICIAL DE PRUEBA: POWERPADEL (Solo para gestión de club)
    if ($is_club && isPowerPadelClub($conn, $club_id, $target_user['id'] ?? 0, $target_user, $effective_role)) {
        return [
            "status" => "active",
            "is_blocked" => false,
            "days_remaining" => 9999,
            "plan_id" => 4,
            "plan_name" => "Club de Prueba - Servicio Gratuito (PowerPadel)",
            "price_clp" => 0,
            "subscription_status" => "active",
            "has_payment_method" => true,
            "card_last_four" => "FREE",
            "card_brand" => "PowerPadel",
            "card_holder" => "Club de Prueba",
            "card_expiry" => "2099",
            "message" => "Servicio gratuito activo (Club de prueba oficial PowerPadel). Sin cobros ni vencimiento.",
            "is_club" => true,
            "is_free_test_club" => true,
            "is_unlimited_coach" => false,
            "subscription_end_date" => "2099-12-31 23:59:59",
            "next_billing_date" => "2099-12-31",
            "billing_history" => [],
            "pending_commission" => 0,
            "recaudo_mp_activo" => true
        ];
    }

    // EXCEPCIÓN 2: ENTRENADOR ILIMITADO: NICO RUIZ
    if (!$is_club && isNicoRuizUser($conn, $target_user['id'] ?? 0, $target_user)) {
        return [
            "status" => "active",
            "is_blocked" => false,
            "days_remaining" => 9999,
            "plan_id" => 4,
            "plan_name" => "Elite Ilimitado (Academia Nico Ruiz)",
            "price_clp" => 0,
            "subscription_status" => "active",
            "has_payment_method" => true,
            "card_last_four" => "FREE",
            "card_brand" => "Nico Ruiz",
            "card_holder" => "Nico Ruiz",
            "card_expiry" => "2099",
            "message" => "Suscripción activa permanente (Plan Elite Ilimitado Bonificado).",
            "is_club" => false,
            "is_free_test_club" => false,
            "is_unlimited_coach" => true,
            "subscription_end_date" => "2099-12-31 23:59:59",
            "next_billing_date" => "2099-12-31",
            "billing_history" => [],
            "pending_commission" => 0,
            "recaudo_mp_activo" => true
        ];
    }

    $now = new DateTime();
    $created_at = $target_user['created_at'] ?? date('Y-m-d H:i:s');
    $created = new DateTime($created_at);
    $plan_id = (int)($target_user['plan_id'] ?? 0);
    $mp_id = $target_user['mp_preapproval_id'] ?? '';
    $paypal_id = $target_user['paypal_sub_id'] ?? '';
    $card_last_four = $target_user['card_last_four'] ?? null;
    $card_brand = $target_user['card_brand'] ?? 'Visa';
    $card_holder = $target_user['card_holder'] ?? null;
    $card_expiry = $target_user['card_expiry'] ?? null;
    $has_payment_method = (!empty($mp_id) || !empty($paypal_id) || !empty($card_last_four));

    $is_club = (strpos($effective_role, 'club') !== false || $effective_role === 'administrador_club' || $effective_role === 'admin_club');

    if ($is_club) {
        $plan_id = 4;
        $plan_name = "Gestión de Club";
        $price_clp = 49990;
    } else {
        $plan_names = [0 => "Sin asignar", 1 => "Emprendedor", 2 => "Inicial 20", 3 => "Pro 40", 4 => "Elite Ilimitado"];
        $plan_prices = [0 => 0, 1 => 0, 2 => 19990, 3 => 29990, 4 => 49990];
        $plan_name = $plan_names[$plan_id] ?? "Sin Plan";
        $price_clp = $plan_prices[$plan_id] ?? 0;
    }

    // 1. Fecha de vencimiento del período de prueba (90 días desde created_at)
    $trial_expiration = clone $created;
    $trial_expiration->modify('+90 days');

    // 2. Fecha de vencimiento de suscripción explícita o pagada
    $raw_end = trim($target_user['subscription_end_date'] ?? '');
    $has_explicit_end = false;
    $paid_expiration = null;
    if (!empty($raw_end) && $raw_end !== '0000-00-00 00:00:00' && $raw_end !== '0000-00-00' && strpos($raw_end, '1970') === false) {
        try {
            $paid_expiration = new DateTime($raw_end);
            $has_explicit_end = true;
        } catch (\Throwable $e) {}
    }

    // 3. La fecha de vencimiento efectiva es la mayor entre el período de prueba y la suscripción pagada
    // (Si un usuario fue creado hace 2 días, su período de prueba de 90 días está plenamente vigente)
    if ($paid_expiration && $paid_expiration > $trial_expiration) {
        $expiration = $paid_expiration;
    } else {
        $expiration = $trial_expiration;
    }

    $is_expired = ($now > $expiration);
    $days_remaining = 0;
    if (!$is_expired) {
        $diff = $now->diff($expiration);
        $days_remaining = (int)$diff->days;
    }

    $is_blocked = false;
    $status = 'active';
    $message = 'Suscripción activa';
    $db_sub_status = strtolower(trim($target_user['subscription_status'] ?? ''));
    $is_explicitly_blocked = ($db_sub_status === 'blocked');

    if ($is_club) {
        if ($is_expired || $days_remaining <= 0 || $is_explicitly_blocked) {
            $is_blocked = true;
            $status = 'blocked';
            $days_remaining = 0;
            $message = "Tu periodo para la gestión de tu club ha finalizado o tu mensualidad no ha sido pagada. Para continuar usando canchas, reservas, turnos y torneos, debes realizar el pago mensual de tu plan ($49.990 CLP).";
        } else {
            $status = 'active';
            $is_blocked = false;
            if ($has_explicit_end && $expiration === $paid_expiration) {
                $message = "Suscripción mensual de Club activa ($days_remaining días restantes). Próximo cobro: " . $expiration->format('d/m/Y');
            } else {
                $message = "Periodo de prueba activo para Club ($days_remaining días restantes).";
            }
        }
    } else {
        // Entrenador: Si está vencido o en 0 días restantes, debe bloquearse siempre
        if ($is_expired || $days_remaining <= 0 || $is_explicitly_blocked) {
            $is_blocked = true;
            $status = 'blocked';
            $days_remaining = 0;
            if ($plan_id == 0) {
                $message = "Tu periodo de prueba de 90 días ha finalizado. Debes seleccionar un plan para continuar con los servicios de tu academia.";
            } else {
                $message = "Tu periodo de prueba o mensualidad ha finalizado (0 días restantes). Para seguir utilizando tu calendario, agendar alumnos y gestionar tu academia, debes activar o renovar tu plan mensual.";
            }
        } else {
            $status = 'active';
            $is_blocked = false;
            if ($has_explicit_end && $expiration === $paid_expiration) {
                $message = "Suscripción activa ($days_remaining días restantes). Próximo vencimiento: " . $expiration->format('d/m/Y');
            } else {
                $message = "Periodo de prueba activo ($days_remaining días restantes).";
            }
        }
    }

    // Historial de pagos
    $billing_history = [];
    if ($conn && !empty($target_user['id'])) {
        $stmtHist = $conn->prepare("
            SELECT fecha_pago as date, monto, metodo_pago, estado, periodo_inicio, periodo_fin 
            FROM pagos_suscripciones 
            WHERE user_id = ? 
            ORDER BY fecha_pago DESC 
            LIMIT 10
        ");
        if ($stmtHist) {
            $stmtHist->bind_param("i", $target_user['id']);
            $stmtHist->execute();
            $histRes = $stmtHist->get_result();
            while ($hr = $histRes->fetch_assoc()) {
                $billing_history[] = $hr;
            }
        }
    }

    return [
        "status" => $is_blocked ? "blocked" : "active",
        "is_blocked" => $is_blocked,
        "days_remaining" => $days_remaining,
        "plan_id" => $plan_id,
        "plan_name" => $plan_name,
        "price_clp" => $price_clp,
        "subscription_status" => $is_blocked ? "blocked" : "active",
        "has_payment_method" => $has_payment_method,
        "card_last_four" => $card_last_four,
        "card_brand" => $card_brand,
        "card_holder" => $card_holder,
        "card_expiry" => $card_expiry,
        "message" => $message,
        "is_club" => $is_club,
        "is_free_test_club" => false,
        "is_unlimited_coach" => false,
        "subscription_end_date" => $expiration->format('Y-m-d H:i:s'),
        "next_billing_date" => $expiration->format('Y-m-d'),
        "billing_history" => $billing_history,
        "pending_commission" => 0,
        "recaudo_mp_activo" => true
    ];
}

function processSubscriptionPayment($conn, $data) {
    ensureSubscriptionTablesAndColumns($conn);

    $user_id = intval($data['user_id'] ?? ($data['coach_id'] ?? 0));
    $plan_id = intval($data['plan_id'] ?? 0);
    $monto   = floatval($data['amount'] ?? ($data['monto'] ?? 0));
    $moneda  = $data['moneda'] ?? 'CLP';
    $metodo  = $data['metodo_pago'] ?? 'Mercado Pago';
    $payment_id = !empty($data['payment_id']) ? strval($data['payment_id']) : null;
    $preapproval_id = !empty($data['preapproval_id']) ? strval($data['preapproval_id']) : (!empty($data['mp_preapproval_id']) ? strval($data['mp_preapproval_id']) : null);

    if ($user_id <= 0) {
        return ["success" => false, "message" => "ID de usuario inválido"];
    }

    // Idempotencia: Si ya procesamos este payment_id con éxito, no duplicar meses
    if ($payment_id) {
        $stmtChk = $conn->prepare("SELECT id, periodo_fin FROM pagos_suscripciones WHERE payment_id = ? AND estado = 'aprobado' LIMIT 1");
        if ($stmtChk) {
            $stmtChk->bind_param("s", $payment_id);
            $stmtChk->execute();
            $exist = $stmtChk->get_result()->fetch_assoc();
            if ($exist) {
                return [
                    "success" => true,
                    "already_processed" => true,
                    "subscription_end_date" => $exist['periodo_fin'],
                    "message" => "Este pago ya fue procesado y acreditado previamente."
                ];
            }
        }
    }

    // 1. Obtener datos actuales del usuario
    $stmt = $conn->prepare("SELECT id, rol, created_at, subscription_end_date, plan_id FROM usuarios WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    if (!$user) {
        return ["success" => false, "message" => "Usuario no encontrado"];
    }

    $now = new DateTime();
    $current_end = !empty($user['subscription_end_date']) ? new DateTime($user['subscription_end_date']) : null;

    // REGLA DE RENOVACIÓN DE MES (+1 MES):
    // Si la suscripción actual aún está vigente en el futuro, sumamos exactamente 1 mes a su fecha de expiración actual.
    // Si ya expiró o nunca tuvo fecha previa, el nuevo mes inicia en este instante (NOW() + 1 mes).
    if ($current_end && $current_end > $now) {
        $periodo_inicio = clone $current_end;
        $periodo_fin = clone $current_end;
        $periodo_fin->modify('+1 month');
    } else {
        $periodo_inicio = clone $now;
        $periodo_fin = clone $now;
        $periodo_fin->modify('+1 month');
    }

    $nueva_fecha_fin = $periodo_fin->format('Y-m-d H:i:s');
    $p_inicio_str = $periodo_inicio->format('Y-m-d H:i:s');

    // Determinar plan_id si no vino especificado
    if ($plan_id <= 0) {
        $is_club = (strpos(strtolower($user['rol'] ?? ''), 'club') !== false);
        $plan_id = $is_club ? 4 : (int)($user['plan_id'] > 0 ? $user['plan_id'] : 2);
    }

    // Precios por defecto si monto es 0
    if ($monto <= 0) {
        $plan_prices = [1 => 0, 2 => 19990, 3 => 29990, 4 => 49990];
        $monto = $plan_prices[$plan_id] ?? 49990;
    }

    // 2. Actualizar tabla usuarios (activar y extender fecha de fin)
    $stmtUpd = $conn->prepare("
        UPDATE usuarios 
        SET plan_id = ?,
            subscription_status = 'active',
            subscription_end_date = ?,
            last_subscription_payment = NOW()
        WHERE id = ?
    ");
    $stmtUpd->bind_param("isi", $plan_id, $nueva_fecha_fin, $user_id);
    $stmtUpd->execute();

    // 3. Registrar en pagos_suscripciones
    $stmtHist = $conn->prepare("
        INSERT INTO pagos_suscripciones 
        (user_id, plan_id, monto, moneda, metodo_pago, payment_id, preapproval_id, fecha_pago, periodo_inicio, periodo_fin, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, 'aprobado')
    ");
    if ($stmtHist) {
        $stmtHist->bind_param("iidssssss", $user_id, $plan_id, $monto, $moneda, $metodo, $payment_id, $preapproval_id, $p_inicio_str, $nueva_fecha_fin);
        $stmtHist->execute();
    }

    return [
        "success" => true,
        "user_id" => $user_id,
        "plan_id" => $plan_id,
        "periodo_inicio" => $p_inicio_str,
        "subscription_end_date" => $nueva_fecha_fin,
        "days_remaining" => (new DateTime())->diff(new DateTime($nueva_fecha_fin))->days,
        "message" => "Suscripción renovada exitosamente por 1 mes hasta el " . $periodo_fin->format('d/m/Y')
    ];
}

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
}

function evaluateSubscriptionStatus($target_user, $effective_role, $conn = null) {
    $now = new DateTime();
    $created_at = $target_user['created_at'] ?? date('Y-m-d H:i:s');
    $created = new DateTime($created_at);
    $plan_id = (int)($target_user['plan_id'] ?? 0);
    $mp_id = $target_user['mp_preapproval_id'] ?? '';
    $paypal_id = $target_user['paypal_sub_id'] ?? '';
    $has_payment_method = (!empty($mp_id) || !empty($paypal_id));

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

    // Determinar la fecha de vencimiento efectiva
    $has_explicit_end = !empty($target_user['subscription_end_date']);
    if ($has_explicit_end) {
        $expiration = new DateTime($target_user['subscription_end_date']);
    } else {
        // Si no tiene fecha explícita, se calcula periodo inicial de prueba de 90 días desde created_at
        $expiration = clone $created;
        $expiration->modify('+90 days');
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

    if ($is_club) {
        if ($is_expired) {
            $is_blocked = true;
            $status = 'blocked';
            $days_remaining = 0;
            $message = "Tu periodo para la gestión de tu club ha finalizado o tu mensualidad no ha sido pagada. Para continuar usando canchas, reservas, turnos y torneos, debes realizar el pago mensual de tu plan ($49.990 CLP).";
        } else {
            $status = 'active';
            $is_blocked = false;
            if ($has_explicit_end) {
                $message = "Suscripción mensual de Club activa ($days_remaining días restantes). Próximo cobro: " . $expiration->format('d/m/Y');
            } else {
                $message = "Periodo de prueba activo para Club ($days_remaining días restantes).";
            }
        }
    } else {
        // Entrenador
        if ($is_expired) {
            if ($plan_id == 1) {
                // Plan Emprendedor ($0) nunca se bloquea
                $status = 'active';
                $is_blocked = false;
                $message = "Plan Emprendedor activo (Sin costo fijo mensual).";
            } elseif ($plan_id == 0) {
                $is_blocked = true;
                $status = 'blocked';
                $days_remaining = 0;
                $message = "Tu periodo de prueba de 90 días ha finalizado. Debes seleccionar un plan para continuar con los servicios de tu academia.";
            } else {
                $is_blocked = true;
                $status = 'blocked';
                $days_remaining = 0;
                $message = "Tu mensualidad ha finalizado. Para seguir utilizando tu calendario, agendar alumnos y gestionar tu academia, renueva tu plan mensual ($" . number_format($price_clp, 0, '', '.') . " CLP).";
            }
        } else {
            $status = 'active';
            $is_blocked = false;
            if ($has_explicit_end) {
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
        "message" => $message,
        "is_club" => $is_club,
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

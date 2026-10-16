<?php
// Habilitar reporte de errores para depuración si fuera necesario
// error_reporting(E_ALL); ini_set('display_errors', 1);

require_once "db.php";

$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : (isset($_POST['user_id']) ? intval($_POST['user_id']) : 0);
$error = null;
$deleted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete'])) {
    if ($user_id > 0) {
        try {
            // Iniciar transacción para asegurar integridad
            $conn->begin_transaction();

            // 1. Verificar si el usuario existe
            $stmt = $conn->prepare("SELECT id FROM usuarios WHERE id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                
                // 2. Eliminación en cascada manual
                // Desactivamos chequeo de llaves foráneas para permitir el borrado limpio
                $conn->query("SET FOREIGN_KEY_CHECKS = 0");
                
                // Torneos
                $conn->query("DELETE FROM torneo_participantes WHERE jugador_id = $user_id OR jugador2_id = $user_id");
                
                // Pack Jugadores (Tabla identificada en el error)
                $conn->query("DELETE FROM pack_jugadores WHERE jugador_id = $user_id");
                
                // Inscripciones Grupales
                try { $conn->query("DELETE FROM inscripciones_grupales WHERE jugador_id = $user_id"); } catch (Exception $e) {}
                try { $conn->query("DELETE FROM inscripciones_grupales WHERE usuario_id = $user_id"); } catch (Exception $e) {}
                
                // Packs
                try { $conn->query("DELETE FROM packs WHERE jugador_id = $user_id OR usuario_id = $user_id"); } catch (Exception $e) {}
                
                // Reservas
                try { $conn->query("DELETE FROM reservas_cancha WHERE jugador_id = $user_id OR jugador2_id = $user_id OR usuario_id = $user_id"); } catch (Exception $e) {}
                
                // Direcciones
                try { $conn->query("DELETE FROM direcciones_usuarios WHERE jugador_id = $user_id OR usuario_id = $user_id"); } catch (Exception $e) {}
                try { $conn->query("DELETE FROM direcciones WHERE usuario_id = $user_id"); } catch (Exception $e) {}
                
                // Disponibilidad Entrenadores
                try { $conn->query("DELETE FROM disponibilidad_entrenador WHERE entrenador_id = $user_id"); } catch (Exception $e) {}

                // 3. Finalmente el usuario
                $stmt_del = $conn->prepare("DELETE FROM usuarios WHERE id = ?");
                $stmt_del->bind_param("i", $user_id);
                $stmt_del->execute();

                // Reactivamos chequeo
                $conn->query("SET FOREIGN_KEY_CHECKS = 1");

                $conn->commit();
                $deleted = true;
            } else {
                $error = "El usuario con ID $user_id no existe o ya fue eliminado.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error crítico: " . $e->getMessage();
        }
    } else {
        $error = "ID de usuario no válido.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Eliminar Cuenta - PadelManager</title>
    <style>
        :root { --primary: #ef4444; --dark: #0f172a; --bg: #f8fafc; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: var(--bg); color: var(--dark); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .container { background: white; padding: 40px 30px; border-radius: 24px; box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1); max-width: 450px; width: 100%; text-align: center; }
        .icon { font-size: 64px; margin-bottom: 20px; display: block; }
        h1 { font-size: 24px; font-weight: 800; margin: 0 0 16px 0; color: var(--dark); letter-spacing: -0.5px; }
        p { color: #64748b; line-height: 1.6; margin: 0 0 32px 0; font-size: 15px; }
        .btn { display: block; width: 100%; padding: 16px; border-radius: 12px; font-weight: 700; font-size: 15px; cursor: pointer; transition: all 0.2s; border: none; text-decoration: none; box-sizing: border-box; text-transform: uppercase; letter-spacing: 0.5px; }
        .btn-delete { background-color: var(--primary); color: white; margin-bottom: 12px; box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2); }
        .btn-delete:active { transform: scale(0.98); }
        .btn-cancel { background-color: #f1f5f9; color: #64748b; }
        .error-card { background: #fef2f2; color: #b91c1c; padding: 15px; border-radius: 12px; margin-bottom: 25px; font-size: 14px; border: 1px solid #fee2e2; }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($deleted): ?>
            <span class="icon">✅</span>
            <h1>Cuenta Eliminada</h1>
            <p>Tu cuenta y datos personales han sido borrados permanentemente. Ya puedes cerrar esta ventana y desinstalar la aplicación si lo deseas.</p>
            <button onclick="window.close()" class="btn btn-cancel">Cerrar Ventana</button>
        <?php else: ?>
            <span class="icon">🗑️</span>
            <h1>Borrar mi cuenta</h1>
            
            <?php if ($error): ?>
                <div class="error-card"><b>Nota:</b> <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <p>¿Estás seguro de que quieres eliminar tu cuenta de <b>PadelManager</b>? <br><br>Esta acción borrará tus reservas, historial y estadísticas. No se puede deshacer.</p>
            
            <form method="POST" action="delete-account.php?user_id=<?php echo $user_id; ?>">
                <input type="hidden" name="user_id" value="<?php echo $user_id; ?>">
                <button type="submit" name="confirm_delete" class="btn btn-delete">Confirmar Eliminación</button>
                <a href="javascript:history.back()" class="btn btn-cancel">Cancelar</a>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Webpay Mock</title>
    <style>
        body { font-family: -apple-system, system-ui, sans-serif; background: #f0f0f0; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .card { background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); text-align: center; max-width: 400px; width: 100%; }
        h2 { color: #111; margin-bottom: 10px; }
        p { color: #666; margin-bottom: 30px; }
        .btn { display: block; width: 100%; padding: 15px; border: none; border-radius: 12px; font-size: 16px; font-weight: bold; cursor: pointer; margin-bottom: 10px; transition: opacity 0.2s; }
        .btn-pay { background: #ff0040; color: white; }
        .btn-cancel { background: #ddd; color: #333; }
        .btn:hover { opacity: 0.9; }
        .logo { font-size: 40px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">💳</div>
        <h2>Webpay Plus (Simulado)</h2>
        <p>Estás en el ambiente de pruebas MOCK.</p>
        
        <?php 
            $token_ws = $_POST['token_ws'] ?? $_GET['token_ws'] ?? ''; 
        ?>

        <form action="confirm_transaction.php" method="POST">
            <input type="hidden" name="token_ws" value="<?php echo htmlspecialchars($token_ws); ?>">
            <button type="submit" class="btn btn-pay">✅ Simular Pago Exitoso</button>
        </form>

        <form action="confirm_transaction.php" method="POST">
            <input type="hidden" name="token_ws" value="CANCEL_<?php echo htmlspecialchars($token_ws); ?>">
            <button type="submit" class="btn btn-cancel">❌ Cancelar / Rechazar</button>
        </form>
    </div>
</body>
</html>

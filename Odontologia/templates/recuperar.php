<?php
session_start();
$page_title = "Recuperar Contraseña";

$mensaje = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);

    // Aquí deberías verificar si el correo existe y enviar un correo real con un token
    // Simulamos la respuesta como si fuera exitoso
    $mensaje = "Si el correo existe en nuestro sistema, recibirás instrucciones para restablecer tu contraseña.";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= $page_title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .recovery-card {
            max-width: 450px;
            width: 100%;
            padding: 2rem;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            border-radius: 1rem;
            background: white;
        }
    </style>
</head>
<body>

<div class="recovery-card">
    <h4 class="mb-3 text-center">Recuperar Contraseña</h4>
    <?php if ($mensaje): ?>
        <div class="alert alert-info"><?= htmlspecialchars($mensaje) ?></div>
    <?php else: ?>
        <form method="post" class="needs-validation" novalidate>
            <div class="mb-3">
                <label for="email" class="form-label">Correo electrónico</label>
                <input type="email" name="email" id="email" class="form-control" required>
                <div class="invalid-feedback">
                    Por favor ingrese un correo válido.
                </div>
            </div>
            <button type="submit" class="btn btn-primary w-100">Enviar enlace</button>
        </form>
    <?php endif; ?>
    <div class="mt-3 text-center">
        <a href="login.php" class="text-decoration-none">Volver al inicio de sesión</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Validación Bootstrap
    (() => {
        'use strict';
        const forms = document.querySelectorAll('.needs-validation');
        Array.from(forms).forEach(form => {
            form.addEventListener('submit', event => {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    })();
</script>
</body>
</html>

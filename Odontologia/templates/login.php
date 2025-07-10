<?php
session_start();

if (isset($_SESSION['usuario'])) {
    header("Location: ../index.php");
    exit;
}

require_once __DIR__ . '/../config/database.php';
$page_title = "Acceso Odontología";

$login_bloqueado = false;
$segundos_restantes = 0;

// Manejo de intentos fallidos y temporizador de bloqueo
if (isset($_SESSION['bloqueo_login'])) {
    $tiempo_restante = time() - $_SESSION['bloqueo_login'];
    if ($tiempo_restante < 120) { // 2 minutos de bloqueo
        $login_bloqueado = true;
        $segundos_restantes = 120 - $tiempo_restante;
    } else {
        unset($_SESSION['bloqueo_login']);
        $_SESSION['intentos_fallidos'] = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$login_bloqueado) {
    require_once __DIR__ . '/../includes/UsuarioManager.php';
    require_once __DIR__ . '/../includes/Database.php';

    $database = new Database();
    $db = $database->getConnection();

    $manager = new UsuarioManager($db);
    
    try {
        // Validación adicional del email
        if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Formato de correo electrónico inválido");
        }

        $usuario = $manager->authenticate($_POST['email'], $_POST['password']);

        if ($usuario) {
            $_SESSION['usuario'] = [
                'id'        => $usuario->getId(),
                'email'     => $usuario->getEmail(),
                'nombre'    => $usuario->getNombre(),
                'rol_type'  => $usuario->getRolType(),
                'ultimo_login' => date('Y-m-d H:i:s')
            ];
            $_SESSION['intentos_fallidos'] = 0;
            unset($_SESSION['bloqueo_login']);
            
            // Registrar el acceso
            $manager->registrarAcceso($usuario->getId());
            
            // Redirección según rol
            $redirect = "../index.php";

            header("Location: $redirect");
            exit;
        } else {
            if (!isset($_SESSION['intentos_fallidos'])) {
                $_SESSION['intentos_fallidos'] = 1;
            } else {
                $_SESSION['intentos_fallidos']++;
            }

            if ($_SESSION['intentos_fallidos'] >= 5) { // 5 intentos antes de bloquear
                $_SESSION['bloqueo_login'] = time();
                $login_bloqueado = true;
                $segundos_restantes = 120;
                $error = "Demasiados intentos fallidos. Por seguridad, su acceso ha sido bloqueado temporalmente. Intente nuevamente en 2 minutos.";
            } else {
                $intentos_restantes = 5 - $_SESSION['intentos_fallidos'];
                $error = "Credenciales incorrectas. Intento {$_SESSION['intentos_fallidos']} de 5. Le quedan $intentos_restantes intentos.";
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.5/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-blue: #2b6cb0;  /* Azul profesional */
            --dark-blue: #2c5282;
            --light-blue: #ebf8ff;
            --accent-blue: #4299e1;   /* Azul para llamadas a acción */
            --pure-white: #ffffff;
            --soft-gray: #f5f7fa;
            --text-gray: #4a4a4a;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.12);
            --transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        }
        
        body {
            background: linear-gradient(135deg, var(--light-blue) 0%, var(--pure-white) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Poppins', sans-serif;
            color: var(--text-gray);
            line-height: 1.6;
        }
        
        .login-container {
            width: 100%;
            max-width: 480px;
            animation: fadeInUp 0.6s;
        }
        
        .login-card {
            border: none;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            background: var(--pure-white);
        }
        
        .login-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(43, 108, 176, 0.15);
        }
        
        .login-card-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            padding: 2rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path fill="rgba(255,255,255,0.1)" d="M0,100 L100,0 L100,100 Z"></path></svg>') no-repeat;
            background-size: 50% 50%;
            opacity: 0.3;
            animation: wave 15s linear infinite;
        }
        
        @keyframes wave {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .login-logo {
            width: 90px;
            height: 90px;
            margin: 0 auto 1.5rem;
            background: var(--pure-white);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-sm);
            position: relative;
            z-index: 2;
        }
        
        .login-logo i {
            font-size: 2.8rem;
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        
        .login-title {
            font-weight: 700;
            font-size: 2rem;
            color: var(--pure-white);
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .login-subtitle {
            font-weight: 400;
            font-size: 1rem;
            color: rgba(255,255,255,0.9);
            position: relative;
            z-index: 2;
        }
        
        .login-card-body {
            padding: 2.5rem;
            background: var(--pure-white);
        }
        
        .form-label {
            font-weight: 500;
            color: var(--dark-blue);
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .form-control {
            border-radius: 10px;
            padding: 14px 16px;
            border: 2px solid #e0e0e0;
            transition: var(--transition);
            font-size: 0.95rem;
        }
        
        .form-control:focus {
            border-color: var(--primary-blue);
            box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.2);
        }
        
        .form-control::placeholder {
            color: #b0bec5;
            font-weight: 300;
        }
        
        .btn-login {
            background: var(--accent-blue);
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: var(--transition);
            color: white;
            text-transform: uppercase;
            font-size: 0.9rem;
        }
        
        .btn-login:hover {
            background: var(--dark-blue);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(66, 153, 225, 0.3);
            color: white;
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .invalid-feedback {
            font-size: 0.85rem;
            color: #ff5252;
            margin-top: 0.25rem;
        }
        
        .password-container {
            position: relative;
        }
        
        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--primary-blue);
            transition: var(--transition);
            background: none;
            border: none;
            font-size: 1.1rem;
        }
        
        .toggle-password:hover {
            color: var(--dark-blue);
        }
        
        .alert-custom {
            border-radius: 10px;
            padding: 1rem 1.25rem;
            font-size: 0.95rem;
            border-left: 4px solid transparent;
        }
        
        .alert-danger {
            background-color: rgba(255, 82, 82, 0.1);
            border-color: #ff5252;
            color: #ff5252;
            border-left-color: #ff5252;
        }
        
        .alert-warning {
            background-color: rgba(255, 177, 66, 0.1);
            border-color: #ffb142;
            color: #cc8e35;
            border-left-color: #ffb142;
        }
        
        #contador {
            font-weight: bold;
            color: var(--accent-blue);
            font-size: 1.1rem;
        }
        
        .language-selector {
            position: absolute;
            top: 20px;
            right: 20px;
            z-index: 10;
        }
        
        .language-selector .form-select {
            border-radius: 20px;
            padding: 0.35rem 1.75rem 0.35rem 0.75rem;
            border: 2px solid rgba(255,255,255,0.3);
            background-color: rgba(66, 153, 225, 0.2);
            color: white;
            font-size: 0.85rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .language-selector .form-select:hover {
            background-color: rgba(255,255,255,0.3);
        }
        
        .language-selector .form-select:focus {
            box-shadow: 0 0 0 3px rgba(255,255,255,0.3);
        }
        
        .footer-links {
            display: flex;
            justify-content: space-between;
            margin-top: 2rem;
            font-size: 0.9rem;
        }
        
        .footer-links a {
            color: var(--primary-blue);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
        }
        
        .footer-links a:hover {
            color: var(--dark-blue);
            transform: translateX(3px);
        }
        
        .footer-links a i {
            margin-right: 6px;
            font-size: 1rem;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
        }
        
        .remember-me .form-check-input {
            width: 1.1em;
            height: 1.1em;
            margin-top: 0;
            margin-right: 0.5rem;
            border: 2px solid #b0bec5;
            transition: var(--transition);
        }
        
        .remember-me .form-check-input:checked {
            background-color: var(--primary-blue);
            border-color: var(--primary-blue);
        }
        
        .remember-me .form-check-input:focus {
            box-shadow: 0 0 0 3px rgba(43, 108, 176, 0.2);
        }
        
        .remember-me .form-check-label {
            color: var(--text-gray);
            font-size: 0.9rem;
        }
        
        /* Efecto de onda animado en el fondo */
          @keyframes waveMove {
            0% { background-position-x: 0; }
            100% { background-position-x: 1440px; }
        }
        
        /* Responsive adjustments */
        @media (max-width: 576px) {
            .login-card-body {
                padding: 1.75rem;
            }
            
            .login-card-header {
                padding: 1.5rem;
            }
            
            .login-title {
                font-size: 1.75rem;
            }
            
            .footer-links {
                flex-direction: column;
                gap: 0.75rem;
            }
        }
    </style>
</head>
<body>
    <div class="wave-bg"></div>
    <div class="container login-container">
        <div class="card login-card">
            <div class="card-header login-card-header">
                <div class="login-logo">
                    <i class="bi bi-shield-lock"></i>
                </div>
                <h1 class="login-title">Acceso Seguro</h1>
                <p class="login-subtitle">Sistema de Gestión Odontológica</p>
            </div>
            <div class="card-body login-card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-custom animate__animated animate__shakeX mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($login_bloqueado): ?>
                    <div class="alert alert-warning alert-custom animate__animated animate__pulse mb-4">
                        <i class="bi bi-shield-lock-fill me-2"></i> 
                        <span id="block-message">Acceso temporalmente bloqueado. Por seguridad, espere <strong id="contador"><?= $segundos_restantes ?></strong> segundos.</span>
                    </div>
                    <script>
                        let contador = <?= $segundos_restantes ?>;
                        const el = document.getElementById('contador');
                        const blockMessage = document.getElementById('block-message');
                        const interval = setInterval(() => {
                            contador--;
                            if (contador <= 0) {
                                clearInterval(interval);
                                location.reload();
                            }
                            el.textContent = contador;
                            
                            if (contador === 60) {
                                blockMessage.textContent = "Acceso temporalmente bloqueado. Por seguridad, espere 1 minuto.";
                            }
                        }, 1000);
                    </script>
                <?php else: ?>
                    <form method="post" class="needs-validation" novalidate id="loginForm">
                        <div class="mb-4">
                            <label for="email" class="form-label">
                                <i class="bi bi-envelope-fill me-2"></i>Correo Electrónico
                            </label>
                            <input type="email" class="form-control" id="email" name="email" required
                                   placeholder="usuario@dentalpro.com">
                            <div class="invalid-feedback">
                                Por favor ingrese un correo electrónico válido
                            </div>
                        </div>
                        
                        <div class="mb-3 password-container">
                            <label for="password" class="form-label">
                                <i class="bi bi-lock-fill me-2"></i>Contraseña
                            </label>
                            <input type="password" class="form-control" id="password" name="password" required
                                   placeholder="••••••••" minlength="8">
                            <button type="button" class="toggle-password" id="togglePassword">
                                <i class="bi bi-eye-fill"></i>
                            </button>
                            <div class="invalid-feedback">
                                La contraseña debe tener al menos 8 caracteres
                            </div>
                        </div>
                        
                        <div class="remember-me">
                            <input class="form-check-input" type="checkbox" id="remember" name="remember">
                            <label class="form-check-label" for="remember">
                                Recordar mi sesión
                            </label>
                        </div>
                        
                        <div class="d-grid mb-3">
                            <button type="submit" class="btn btn-login">
                                <i class="bi bi-box-arrow-in-right me-2"></i> INGRESAR
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
                
                <div class="footer-links">
                    <a href="register.php">
                        <i class="bi bi-person-plus"></i> Crear cuenta
                    </a>
                    <a href="recuperar.php">
                        <i class="bi bi-key"></i> Recuperar acceso
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validación del formulario
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
        
        // Mostrar/ocultar contraseña
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = togglePassword.querySelector('i');
        
        togglePassword.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            eyeIcon.classList.toggle('bi-eye-fill');
            eyeIcon.classList.toggle('bi-eye-slash-fill');
        });
        
        // Efecto al enfocar campos
        const inputs = document.querySelectorAll('.form-control');
        inputs.forEach(input => {
            input.addEventListener('focus', () => {
                input.parentElement.classList.add('animate__animated', 'animate__pulse');
            });
            
            input.addEventListener('blur', () => {
                input.parentElement.classList.remove('animate__animated', 'animate__pulse');
            });
        });
    </script>
</body>
</html>
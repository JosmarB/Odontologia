<?php
session_start();
$page_title = "Registro";

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/UsuarioManager.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $nombre = trim($_POST['nombre']);
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];

    $database = new Database();
    $db = $database->getConnection();
    $manager = new UsuarioManager($db);

    // Validaciones mejoradas
    if (empty($nombre)) {
        $error = "El nombre completo es obligatorio.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "El correo electrónico no es válido.";
    } elseif (strlen($password) < 8) {
        $error = "La contraseña debe tener al menos 8 caracteres.";
    } elseif (!preg_match('/[A-Z]/', $password)) {
        $error = "La contraseña debe contener al menos una letra mayúscula.";
    } elseif (!preg_match('/[0-9]/', $password)) {
        $error = "La contraseña debe contener al menos un número.";
    } elseif ($password !== $confirm) {
        $error = "Las contraseñas no coinciden.";
    } elseif ($manager->emailExists($email)) {
        $error = "Ya existe un usuario con ese correo electrónico.";
    } else {
        try {
            $manager->createUser($email, $nombre, $password);
            $_SESSION['success_message'] = "¡Registro exitoso! Por favor inicia sesión.";
            header("Location: login.php");
            exit();
        } catch (Exception $e) {
            $error = "Ocurrió un error al registrar el usuario. Por favor intenta nuevamente.";
            // Log del error para administradores
            error_log("Error en registro: " . $e->getMessage());
        }
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
            --primary-blue: #2b6cb0;
            --dark-blue: #2c5282;
            --light-blue: #ebf8ff;
            --accent-blue: #4299e1;
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
        
        .register-container {
            width: 100%;
            max-width: 520px;
            animation: fadeInUp 0.6s;
        }
        
        .register-card {
            border: none;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            background: var(--pure-white);
        }
        
        .register-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 24px rgba(43, 108, 176, 0.15);
        }
        
        .register-card-header {
            background: linear-gradient(135deg, var(--primary-blue) 0%, var(--dark-blue) 100%);
            padding: 1.5rem;
            text-align: center;
            color: var(--pure-white);
        }
        
        .register-title {
            font-weight: 700;
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }
        
        .register-subtitle {
            font-weight: 400;
            font-size: 0.95rem;
            opacity: 0.9;
        }
        
        .register-card-body {
            padding: 2rem;
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
        
        .btn-register {
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
            width: 100%;
        }
        
        .btn-register:hover {
            background: var(--dark-blue);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(66, 153, 225, 0.3);
        }
        
        .password-strength {
            height: 4px;
            background: #e0e0e0;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        
        .strength-meter {
            height: 100%;
            width: 0;
            background: #ff5252;
            transition: width 0.3s ease;
        }
        
        .password-requirements {
            font-size: 0.8rem;
            color: #666;
            margin-top: 8px;
        }
        
        .requirement {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
        }
        
        .requirement i {
            margin-right: 6px;
            font-size: 0.7rem;
        }
        
        .requirement.valid {
            color: var(--primary-blue);
        }
        
        .requirement.valid i {
            color: #4caf50;
        }
        
       
        
        @media (max-width: 576px) {
            .register-card-body {
                padding: 1.75rem;
            }
            
            .register-card-header {
                padding: 1.25rem;
            }
            
            .register-title {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="wave-bg"></div>
    <div class="container register-container">
        <div class="card register-card">
            <div class="card-header register-card-header">
                <h1 class="register-title">Crear Cuenta</h1>
                <p class="register-subtitle">Completa el formulario para registrarte</p>
            </div>
            <div class="card-body register-card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger animate__animated animate__shakeX mb-4">
                        <i class="bi bi-exclamation-triangle-fill me-2"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" class="needs-validation" novalidate id="registerForm">
                    <div class="mb-3">
                        <label for="nombre" class="form-label">
                            <i class="bi bi-person-fill me-2"></i>Nombre Completo
                        </label>
                        <input type="text" class="form-control" id="nombre" name="nombre" required
                               placeholder="Ej: Juan Pérez" value="<?= isset($_POST['nombre']) ? htmlspecialchars($_POST['nombre']) : '' ?>">
                        <div class="invalid-feedback">Por favor ingresa tu nombre completo</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">
                            <i class="bi bi-envelope-fill me-2"></i>Correo Electrónico
                        </label>
                        <input type="email" class="form-control" id="email" name="email" required
                               placeholder="usuario@ejemplo.com" value="<?= isset($_POST['email']) ? htmlspecialchars($_POST['email']) : '' ?>">
                        <div class="invalid-feedback">Ingresa un correo electrónico válido</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="bi bi-lock-fill me-2"></i>Contraseña
                        </label>
                        <input type="password" class="form-control" id="password" name="password" required
                               placeholder="Mínimo 8 caracteres" minlength="8">
                        <div class="password-strength">
                            <div class="strength-meter" id="strengthMeter"></div>
                        </div>
                        <div class="password-requirements" id="passwordRequirements">
                            <div class="requirement" id="reqLength">
                                <i class="bi bi-circle"></i> Mínimo 8 caracteres
                            </div>
                            <div class="requirement" id="reqUpper">
                                <i class="bi bi-circle"></i> Al menos 1 mayúscula
                            </div>
                            <div class="requirement" id="reqNumber">
                                <i class="bi bi-circle"></i> Al menos 1 número
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="confirm_password" class="form-label">
                            <i class="bi bi-lock-fill me-2"></i>Confirmar Contraseña
                        </label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                               placeholder="Vuelve a escribir tu contraseña">
                        <div class="invalid-feedback">Las contraseñas deben coincidir</div>
                    </div>
                    
                    <button type="submit" class="btn btn-register mb-3">
                        <i class="bi bi-person-plus-fill me-2"></i> Registrarse
                    </button>
                    
                    <div class="text-center mt-3">
                        ¿Ya tienes una cuenta? <a href="login.php" class="text-primary">Inicia sesión</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Validación del formulario
        (() => {
            'use strict';
            const form = document.getElementById('registerForm');
            
            form.addEventListener('submit', event => {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                
                form.classList.add('was-validated');
            }, false);
        })();
        
        // Validación de contraseña en tiempo real
        const passwordInput = document.getElementById('password');
        const confirmInput = document.getElementById('confirm_password');
        const strengthMeter = document.getElementById('strengthMeter');
        const reqLength = document.getElementById('reqLength');
        const reqUpper = document.getElementById('reqUpper');
        const reqNumber = document.getElementById('reqNumber');
        
        passwordInput.addEventListener('input', function() {
            const password = this.value;
            let strength = 0;
            
            // Validar longitud
            if (password.length >= 8) {
                strength += 25;
                reqLength.classList.add('valid');
                reqLength.querySelector('i').className = 'bi bi-check-circle-fill';
            } else {
                reqLength.classList.remove('valid');
                reqLength.querySelector('i').className = 'bi bi-circle';
            }
            
            // Validar mayúsculas
            if (/[A-Z]/.test(password)) {
                strength += 25;
                reqUpper.classList.add('valid');
                reqUpper.querySelector('i').className = 'bi bi-check-circle-fill';
            } else {
                reqUpper.classList.remove('valid');
                reqUpper.querySelector('i').className = 'bi bi-circle';
            }
            
            // Validar números
            if (/[0-9]/.test(password)) {
                strength += 25;
                reqNumber.classList.add('valid');
                reqNumber.querySelector('i').className = 'bi bi-check-circle-fill';
            } else {
                reqNumber.classList.remove('valid');
                reqNumber.querySelector('i').className = 'bi bi-circle';
            }
            
            // Validar caracteres especiales (opcional)
            if (/[^A-Za-z0-9]/.test(password)) {
                strength += 25;
            }
            
            // Actualizar medidor de fortaleza
            strengthMeter.style.width = strength + '%';
            
            // Cambiar color según fortaleza
            if (strength < 50) {
                strengthMeter.style.background = '#ff5252'; // Rojo
            } else if (strength < 75) {
                strengthMeter.style.background = '#ffb142'; // Amarillo
            } else {
                strengthMeter.style.background = '#4caf50'; // Verde
            }
        });
        
        // Validar coincidencia de contraseñas
        confirmInput.addEventListener('input', function() {
            if (passwordInput.value !== this.value) {
                this.setCustomValidity("Las contraseñas no coinciden");
            } else {
                this.setCustomValidity("");
            }
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
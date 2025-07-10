<?php
require_once 'config.php';

$page_title = "Inicio 🏥";
require_once 'templates/header.php';

require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Usuario.php';
require_once __DIR__ . '/includes/Paciente.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$rol = $usuario_actual ? $usuario_actual->rol_type : null;
?>

<!-- Fuentes personalizadas y estilos -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&family=Fredoka+One&display=swap" rel="stylesheet">
<style>
    :root {
        --font-heading: 'Fredoka One', cursive;
        --font-body: 'Poppins', sans-serif;
        --primary-color: #4361ee;
        --secondary-color: #3a0ca3;
    }
    
    body {
        font-family: var(--font-body);
    }
    
    h1, h2, h3, h4, h5, h6, .display-1, .display-2, .display-3, .display-4 {
        font-family: var(--font-heading);
        letter-spacing: 0.5px;
    }
    
    .hero-section {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
        border-radius: 0 0 20px 20px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
    }
    
    .emoji-large {
        font-size: 2.5rem;
        margin-right: 10px;
        vertical-align: middle;
    }
    
    .card-icon {
        font-size: 2rem;
        margin-bottom: 1rem;
        color: var(--primary-color);
    }
    
    .feature-icon {
        font-size: 1.5rem;
        margin-right: 10px;
        color: var(--primary-color);
    }
</style>

<!-- Hero Section con emojis -->
<section class="hero-section text-white py-5 mb-4">
    <div class="container py-5">
        <div class="row align-items-center">
            <div class="col-lg-6">
                <h1 class="display-3 fw-bold mb-3">
                    <span class="emoji-large">😁</span>Bienvenido<?= $usuario_actual ? ', ' . htmlspecialchars($usuario_actual->nombre) : '' ?>
                </h1>
                <p class="lead mb-4">
                    <i class="fas fa-teeth-open me-2"></i>Sistema integral de gestión odontológica
                </p>
                <?php if(!$usuario_actual): ?>
                    <a href="/Odontologia/login.php" class="btn btn-light btn-lg px-4 me-2">
                        <i class="fas fa-sign-in-alt me-2"></i>Iniciar Sesión
                    </a>
                    <a href="/Odontologia/register.php" class="btn btn-outline-light btn-lg px-4">
                        <i class="fas fa-user-plus me-2"></i>Registrarse
                    </a>
                <?php endif; ?>
            </div>
            <div class="col-lg-6 d-none d-lg-block text-center">
                <img src="https://cdn-icons-png.flaticon.com/512/2968/2968978.png" alt="Odontología" class="img-fluid" style="max-height: 300px;">
                <div class="mt-3">
                    <span class="emoji-large">🦷</span>
                    <span class="emoji-large">💊</span>
                    <span class="emoji-large">👩‍⚕️</span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Contenido principal -->
<div class="container mb-5">
    <!-- Mensaje personalizado según rol -->
    <?php if($usuario_actual): ?>
        <div class="alert alert-info mb-4">
            <div class="d-flex align-items-center">
                <div class="me-3">
                    <?php 
                    $rol_icon = $rol === 'A' ? '👑' : 
                                ($rol === 'O' ? '👩‍⚕️' : 
                                ($rol === 'S' ? '📋' : '👤'));
                    ?>
                    <span class="emoji-large"><?= $rol_icon ?></span>
                </div>
                <div>
                    <h5 class="alert-heading mb-1">Hola, <?= htmlspecialchars($usuario_actual->nombre) ?></h5>
                    <p class="mb-0">
                        <i class="fas fa-user-tag me-2"></i>Tienes acceso como <?= 
                            $rol === 'A' ? 'Administrador 👑' : 
                            ($rol === 'O' ? 'Odontólogo 🦷' : 
                            ($rol === 'S' ? 'Secretario 📋' : 'Usuario 👤')) ?> 
                    </p>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tarjetas de acceso rápido -->
    <h2 class="mb-4 text-center"><i class="fas fa-bolt me-2"></i>Accesos rápidos</h2>
    <div class="row g-4">
        <?php if(!$usuario_actual): ?>
            <!-- Tarjetas para usuarios no logueados -->
            <div class="col-md-4">
                <div class="card h-100 shadow-sm border-0 text-center p-4">
                    <div class="card-icon">📅</div>
                    <h5 class="card-title">Agenda de Citas</h5>
                    <p class="card-text text-muted">Programa y gestiona tus citas odontológicas de manera eficiente.</p>
                    <a href="/Odontologia/login.php" class="btn btn-outline-primary">
                        <i class="fas fa-sign-in-alt me-2"></i>Inicia sesión
                    </a>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100 shadow-sm border-0 text-center p-4">
                    <div class="card-icon">🦷</div>
                    <h5 class="card-title">Tratamientos</h5>
                    <p class="card-text text-muted">Conoce nuestros servicios y tratamientos odontológicos especializados.</p>
                    <a href="#" class="btn btn-outline-primary">
                        <i class="fas fa-info-circle me-2"></i>Más información
                    </a>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="card h-100 shadow-sm border-0 text-center p-4">
                    <div class="card-icon">📍</div>
                    <h5 class="card-title">Ubicación</h5>
                    <p class="card-text text-muted">Encuentra nuestras clínicas y horarios de atención.</p>
                    <a href="#" class="btn btn-outline-primary">
                        <i class="fas fa-map-marked-alt me-2"></i>Ver ubicaciones
                    </a>
                </div>
            </div>
            
        <?php else: ?>
            <!-- Tarjetas para usuarios logueados según su rol -->
            <?php if($permisos['auth_paciente'] ?? false): ?>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm border-0 text-center p-4">
                        <div class="card-icon">👥</div>
                        <h5 class="card-title">Pacientes</h5>
                        <p class="card-text text-muted">Gestión de pacientes y sus datos personales.</p>
                        <a href="./pacientes/index.php" class="btn btn-primary">
                            <i class="fas fa-users me-2"></i>Ir a Pacientes
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if($permisos['auth_historia_medica'] ?? false): ?>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm border-0 text-center p-4">
                        <div class="card-icon">📋</div>
                        <h5 class="card-title">Historias Médicas</h5>
                        <p class="card-text text-muted">Registro y consulta de historias médicas odontológicas.</p>
                        <a href="./historias_medicas/view.php" class="btn btn-primary">
                            <i class="fas fa-file-medical me-2"></i>Ir a Historias
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if($permisos['auth_odontograma'] ?? false): ?>
                <div class="col-md-4">
                    <div class="card h-100 shadow-sm border-0 text-center p-4">
                        <div class="card-icon">🦷</div>
                        <h5 class="card-title">Odontograma</h5>
                        <p class="card-text text-muted">Generación de Odontogramas personalizados.</p>
                        <a href="./odontograma/index.php" class="btn btn-primary">
                            <i class="fas fa-tooth me-2"></i>Generar Odontograma
                        </a>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Contenido exclusivo para administradores -->
            <?php if($rol === 'A'): ?>
                <div class="col-md-6 mt-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i> Estadísticas 📊</h5>
                        </div>
                        <div class="card-body">
                            <p class="card-text">Acceso a las estadísticas y reportes del sistema.</p>
                            <a href="/Odontologia/templates/estadisticas.php" class="btn btn-outline-primary">
                                <i class="fas fa-chart-pie me-2"></i>Ver estadísticas
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6 mt-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-cogs me-2"></i> Administración ⚙️</h5>
                        </div>
                        <div class="card-body">
                            <p class="card-text">Configuración del sistema y gestión de usuarios.</p>
                            <a href="/Odontologia/admin.php" class="btn btn-outline-primary">
                                <i class="fas fa-user-shield me-2"></i>Ir a Administración
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Contenido exclusivo para odontólogos -->
            <?php if($rol === 'O' && ($permisos['auth_citas'] ?? false)): ?>
                <div class="col-md-6 mt-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i> Agenda 📅</h5>
                        </div>
                        <div class="card-body">
                            <p class="card-text">Gestión de citas y atención a pacientes.</p>
                            <a href="/Odontologia/cita/index.php" class="btn btn-outline-primary">
                                <i class="fas fa-calendar-check me-2"></i>Ver agenda
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Contenido exclusivo para secretarios -->
            <?php if($rol === 'S' && ($permisos['auth_horarios'] ?? false)): ?>
                <div class="col-md-6 mt-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-clock me-2"></i> Horarios ⏰</h5>
                        </div>
                        <div class="card-body">
                            <p class="card-text">Gestión de horarios y disponibilidad.</p>
                            <a href="/Odontologia/horarios/horarios.php" class="btn btn-outline-primary">
                                <i class="fas fa-business-time me-2"></i>Ver horarios
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Sección de características -->
    <div class="row mt-5">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-star me-2"></i> Características principales ✨</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="feature-icon">🚀</div>
                                <div>
                                    <h6>Rápido y eficiente</h6>
                                    <p class="small text-muted">Sistema optimizado para máxima productividad.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="feature-icon">🔒</div>
                                <div>
                                    <h6>Seguro y confiable</h6>
                                    <p class="small text-muted">Protección avanzada para tus datos.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="feature-icon">📱</div>
                                <div>
                                    <h6>Totalmente responsive</h6>
                                    <p class="small text-muted">Accede desde cualquier dispositivo.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="feature-icon">💾</div>
                                <div>
                                    <h6>Backup automático</h6>
                                    <p class="small text-muted">Tus datos siempre protegidos.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="feature-icon">📊</div>
                                <div>
                                    <h6>Reportes detallados</h6>
                                    <p class="small text-muted">Genera reportes con un clic.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="d-flex align-items-start">
                                <div class="feature-icon">🔄</div>
                                <div>
                                    <h6>Actualizaciones constantes</h6>
                                    <p class="small text-muted">Siempre con las últimas mejoras.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php 
// Scripts y recursos adicionales
$additional_scripts = '
<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&family=Fredoka+One&display=swap" rel="stylesheet">
<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Emoji CSS -->
<link href="https://emoji-css.afeld.me/emoji.css" rel="stylesheet">
';

require_once 'templates/footer.php'; 
?>
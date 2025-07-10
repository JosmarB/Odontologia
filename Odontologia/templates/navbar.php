<?php
// Iniciar buffer si no está activo
if (!ob_get_level()) {
    ob_start();
}

// Manejo seguro de sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$usuario_actual = isset($_SESSION['usuario']) ? (object) $_SESSION['usuario'] : null;

// Buscar imagen de perfil
$fotoPerfil = 'default.webp';
if ($usuario_actual) {
    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'] as $ext) {
        $ruta = __DIR__ . "/../imagenes_usuarios/perfil_{$usuario_actual->id}.$ext";
        if (file_exists($ruta)) {
            $fotoPerfil = "perfil_{$usuario_actual->id}.$ext";
            break;
        }
    }
}

// Obtener permisos del usuario
$permisos = [];
if ($usuario_actual) {
    require_once __DIR__ . '/../includes/Database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    $query = $db->prepare("SELECT * FROM auth_secciones WHERE rol_type = ?");
    $query->execute([$usuario_actual->rol_type]);
    $permisos = $query->fetch(PDO::FETCH_ASSOC) ?: [];
}

// Manejar acciones de notificaciones
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['notificacion_action'])) {
    require_once __DIR__ . '/../includes/Notificaciones.php';
    require_once __DIR__ . '/../includes/Database.php';

    $database = new Database();
    $db = $database->getConnection();
    
    try {
        // Limpiar notificaciones antiguas (más de 7 días)
        $db->query("DELETE FROM notificaciones 
                   WHERE fecha_creacion < DATE_SUB(NOW(), INTERVAL 7 DAY) 
                   AND Estado_Sistema = 'Activo'");
        
        if ($usuario_actual && in_array($usuario_actual->rol_type, ['A', 'O', 'S'])) {
            if ($_POST['notificacion_action'] === 'mark_all_read') {
                $db->query("UPDATE notificaciones 
                           SET leida = 1, fecha_lectura = NOW() 
                           WHERE usuario_id = {$usuario_actual->id} 
                           AND leida = 0
                           AND Estado_Sistema = 'Activo'");
            } elseif ($_POST['notificacion_action'] === 'mark_read' && isset($_POST['notificacion_id'])) {
                $id = (int)$_POST['notificacion_id'];
                $db->query("UPDATE notificaciones 
                           SET leida = 1, fecha_lectura = NOW() 
                           WHERE id = $id 
                           AND usuario_id = {$usuario_actual->id}
                           AND Estado_Sistema = 'Activo'");
            } elseif ($_POST['notificacion_action'] === 'clear_all') {
                $db->query("UPDATE notificaciones 
                           SET Estado_Sistema = 'Inactivo' 
                           WHERE usuario_id = {$usuario_actual->id}
                           AND Estado_Sistema = 'Activo'");
            }
        }
    } catch (Exception $e) {
        error_log("Error al manejar notificaciones: " . $e->getMessage());
    }
    
    // Si es AJAX, devolver respuesta JSON y terminar
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

// Inicializar notificaciones solo para roles A, O, S
$notificaciones = [];
$totalNoLeidas = 0;

if ($usuario_actual && in_array($usuario_actual->rol_type, ['A', 'O', 'S'])) {
    require_once __DIR__ . '/../includes/Notificaciones.php';
    require_once __DIR__ . '/../includes/Database.php';

    $database = new Database();
    $db = $database->getConnection();
    
    try {
        $notificacionesHandler = new Notificaciones($db);
        
        // Verificar y crear notificaciones necesarias
        $notificacionesHandler->verificarStock();
        $notificacionesHandler->verificarCitasProximas();
        
        // Obtener notificaciones para el usuario
        $notificaciones = $notificacionesHandler->obtenerNotificaciones($usuario_actual->id);
        $totalNoLeidas = count(array_filter($notificaciones, function($n) { return !$n['leida']; }));
    } catch (Exception $e) {
        error_log("Error al manejar notificaciones: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Odontología</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #0d6efd;
            --primary-dark: #0b5ed7;
            --secondary-color: #f8f9fa;
            --text-dark: #212529;
            --text-light: #f8f9fa;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            background-image: linear-gradient(135deg, #f5f7fa 0%, #e4e8ed 100%);
            min-height: 100vh;
            padding-top: 70px;
        }
        
        .content-layer {
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 10px;
            box-shadow: var(--shadow);
            margin: 20px auto;
            padding: 25px;
            position: relative;
            z-index: 10;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-10px); }
            60% { transform: translateY(-5px); }
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        .navbar {
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            background-size: 200% 200%;
            animation: gradientShift 8s ease infinite;
            position: fixed;
            top: 0;
            width: 100%;
            z-index: 1030;
            padding: 10px 0;
        }
        
        .navbar-brand {
            font-weight: 700;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            font-size: 1.3rem;
        }
        
        .navbar-brand i {
            margin-right: 10px;
            font-size: 1.5rem;
        }
        
        .navbar-brand:hover {
            transform: translateX(2px);
            color: var(--text-light) !important;
        }
        
        .nav-link {
            position: relative;
            padding: 8px 15px;
            transition: all 0.3s ease;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        
        .nav-link i {
            margin-right: 8px;
            font-size: 1.1rem;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 50%;
            width: 0;
            height: 2px;
            background: white;
            transition: all 0.3s ease;
            transform: translateX(-50%);
        }
        
        .nav-link:hover::after {
            width: 70%;
        }
        
        .dropdown-menu {
            animation: fadeIn 0.3s ease forwards;
            border: none;
            box-shadow: var(--shadow);
            border-radius: 8px;
            overflow: hidden;
            margin-top: 5px;
            border: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        .dropdown-item {
            transition: all 0.2s ease;
            padding: 10px 16px;
            display: flex;
            align-items: center;
        }
        
        .dropdown-item i {
            width: 20px;
            text-align: center;
            margin-right: 10px;
        }
        
        .dropdown-item:hover {
            background-color: #f0f2f5;
            transform: translateX(5px);
            color: var(--primary-dark);
        }
        
        .dropdown-notifications {
            width: 350px;
            max-height: 60vh;
            overflow-y: auto;
            padding: 0;
        }
        
        .dropdown-notifications .dropdown-header {
            padding: 12px 16px;
            background-color: var(--primary-color);
            color: white;
            font-weight: 500;
        }
        
        .notification-item {
            transition: all 0.3s ease;
            padding: 0;
        }
        
        .notification-item a {
            padding: 12px 16px;
            display: block;
            color: var(--text-dark);
            text-decoration: none;
        }
        
        .notification-unread {
            background-color: rgba(13, 110, 253, 0.08);
            border-left: 3px solid var(--primary-color);
        }
        
        .notification-item:hover {
            background-color: #f0f2f5;
        }
        
        .badge-notification {
            animation: pulse 1.5s infinite;
            font-size: 0.7em;
            padding: 3px 6px;
            min-width: 20px;
            display: inline-flex;
            justify-content: center;
        }
        
        .navbar-profile-img {
            width: 36px;
            height: 36px;
            object-fit: cover;
            border-radius: 50%;
            border: 2px solid rgba(255, 255, 255, 0.5);
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
        }
        
        .navbar-profile-img:hover {
            transform: scale(1.15);
            border-color: white;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        
        .navbar-profile-name {
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .nav-link:hover .navbar-profile-name {
            color: white !important;
        }
        
        @media (max-width: 991.98px) {
            .navbar-profile-img {
                width: 32px;
                height: 32px;
            }
        }
        .navbar-toggler {
            transition: all 0.3s ease;
            border: none;
            padding: 8px;
        }
        
        .navbar-toggler:focus {
            box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.5);
            animation: pulse 1s;
        }
        
        .navbar-toggler-icon {
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.8%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
        }
        
        .dropdown:hover .dropdown-menu {
            display: block;
        }
        
        .floating-alert {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1060;
            animation: fadeIn 0.5s;
            min-width: 300px;
            text-align: center;
            box-shadow: var(--shadow);
        }
        
        .navbar-scroll-up {
            transform: translateY(0);
            transition: transform 0.3s ease;
        }
        
        .navbar-scroll-down {
            transform: translateY(-100%);
            transition: transform 0.3s ease;
        }
        
        @media (max-width: 992px) {
            .dropdown-menu {
                animation: none;
                margin-top: 0;
            }
            
            .nav-link::after {
                display: none;
            }
            
            .navbar-nav {
                padding: 10px 0;
            }
            
            .dropdown-item {
                padding: 8px 16px;
            }
            
            .dropdown-notifications {
                width: 280px;
                max-height: 50vh;
            }
        }
        
        @media (max-width: 576px) {
            .navbar-brand {
                font-size: 1.1rem;
            }
            
            .navbar-brand i {
                font-size: 1.2rem;
            }
        }
        
        .wave-effect {
            position: relative;
            overflow: hidden;
        }
        
        .wave-effect:after {
            content: "";
            display: block;
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
            background-image: radial-gradient(circle, #fff 10%, transparent 10.01%);
            background-repeat: no-repeat;
            background-position: 50%;
            transform: scale(10, 10);
            opacity: 0;
            transition: transform .5s, opacity 1s;
        }
        
        .wave-effect:active:after {
            transform: scale(0, 0);
            opacity: .3;
            transition: 0s;
        }
        
        .bubble-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }
        
        .bubble {
            position: absolute;
            border-radius: 50%;
            background: rgba(9, 99, 235, 0.1);
            opacity: 0.5;
            animation: float 15s infinite linear;
        }
        
        .bubble-1 {
            width: 150px;
            height: 150px;
            left: 10%;
            top: 20%;
            animation-duration: 20s;
        }
        
        .bubble-2 {
            width: 250px;
            height: 250px;
            left: 60%;
            top: 30%;
            animation-duration: 25s;
            animation-delay: 2s;
        }
        
        .bubble-3 {
            width: 100px;
            height: 100px;
            left: 30%;
            top: 60%;
            animation-duration: 15s;
            animation-delay: 1s;
        }
        
        .bubble-4 {
            width: 180px;
            height: 180px;
            left: 70%;
            top: 70%;
            animation-duration: 18s;
            animation-delay: 3s;
        }
        
        .bubble-5 {
            width: 80px;
            height: 80px;
            left: 40%;
            top: 80%;
            animation-duration: 12s;
        }
        
        @keyframes float {
            0% {
                transform: translateY(0) rotate(0deg);
            }
            50% {
                transform: translateY(-100px) rotate(180deg);
            }
            100% {
                transform: translateY(0) rotate(360deg);
            }
        }
    </style>
</head>
<body>
<div class="bubble-bg">
    <div class="bubble bubble-1"></div>
    <div class="bubble bubble-2"></div>
    <div class="bubble bubble-3"></div>
    <div class="bubble bubble-4"></div>
    <div class="bubble bubble-5"></div>
</div>

<nav class="navbar navbar-expand-lg navbar-dark bg-primary navbar-scroll-up">
    <div class="container">
        <strong><a class="navbar-brand wave-effect" href="/Odontologia/index.php">
            <i class="bi bi-tooth"></i>Odontología
        </a></strong>
        <button class="navbar-toggler wave-effect" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav">
                <?php if ($usuario_actual): ?>
                    <!-- Gestión (solo si tiene al menos un permiso) -->
                    <?php if (!empty($permisos) && ($permisos['auth_paciente'] || $permisos['auth_historia_medica'] || $permisos['auth_horarios'])): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownGestion" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-clipboard2-pulse me-1"></i>Gestión
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($permisos['auth_paciente']): ?>
                                    <li><a class="dropdown-item" href="/Odontologia/pacientes/index.php"><i class="bi bi-people me-2"></i>Pacientes</a></li>
                                <?php endif; ?>
                                <?php if ($permisos['auth_historia_medica']): ?>
                                    <li><a class="dropdown-item" href="/Odontologia/historias_medicas/view.php"><i class="bi bi-file-earmark-medical me-2"></i>Historias Médicas</a></li>
                                <?php endif; ?>
                                <?php if ($permisos['auth_inventario']): ?>
                                    <li><a class="dropdown-item" href="/Odontologia/stock/stock.php"><i class="bi bi-box-seam me-2"></i>Inventario</a></li>
                                <?php endif; ?>
                                <?php if ($permisos['auth_horarios']): ?>
                                    <li><a class="dropdown-item" href="/Odontologia/horarios/horarios.php"><i class="bi bi-calendar-event me-2"></i>Horarios</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Citas (solo si tiene permiso) -->
                    <?php if (!empty($permisos) && ($permisos['auth_citas'] || $permisos['auth_atender_citas'])): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownCitas" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-calendar-check me-1"></i>Citas
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <?php if ($permisos['auth_citas']): ?>
                                    <li><a class="dropdown-item" href="/Odontologia/cita/index.php"><i class="bi bi-plus-circle me-2"></i>Agendar Cita</a></li>
                                <?php endif; ?>
                                <?php if ($permisos['auth_atender_citas']): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><a class="dropdown-item" href="/Odontologia/cita/atender_citas.php"><i class="bi bi-clipboard2-pulse me-2"></i>Atender Cita</a></li>
                                <?php endif; ?>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Administración (solo para admin) -->
                    <?php if ($usuario_actual->rol_type === 'A'): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="navbarDropdownAdmin" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-gear me-1"></i>Administrar
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="/Odontologia/admin.php"><i class="bi bi-people-fill me-2"></i>Roles de Usuarios</a></li>
                                <li><a class="dropdown-item" href="/Odontologia/templates/admin_errores.php"><i class="bi bi-tools me-2"></i>Administración</a></li>
                                <li><a class="dropdown-item" href="/Odontologia/templates/restauracion.php"><i class="bi bi-arrow-counterclockwise me-2"></i>Restaurar Datos</a></li>
                            </ul>
                        </li>
                    <?php endif; ?>

                    <!-- Odontograma (solo con permiso) -->
                    <?php if (!empty($permisos) && $permisos['auth_odontograma']): ?>
                        <li class="nav-item">
                            <a href="/Odontologia/odontograma/index.php" class="nav-link">
                                <i class="bi bi-tooth me-1"></i>Odontograma
                            </a>
                        </li>
                    <?php endif; ?>

                    <!-- Estadísticas (solo para admin) -->
                    <?php if ($usuario_actual->rol_type === 'A'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/Odontologia/templates/estadisticas.php">
                                <i class="bi bi-graph-up me-1"></i>Estadísticas
                            </a>
                        </li>
                    <?php endif; ?>

                <?php endif; ?>
            </ul>

            <ul class="navbar-nav ms-auto">
                <?php if ($usuario_actual): ?>
                    <!-- Notificaciones (solo para A, O, S con permisos) -->
                    <?php if (in_array($usuario_actual->rol_type, ['A', 'O', 'S'])): ?>
                        <li class="nav-item dropdown me-2">
                            <a class="nav-link dropdown-toggle position-relative" href="#" id="navbarDropdownNotificaciones" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-bell-fill"></i>
                                <?php if ($totalNoLeidas > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger badge-notification">
                                        <?= $totalNoLeidas ?>
                                        <span class="visually-hidden">Notificaciones no leídas</span>
                                    </span>
                                <?php endif; ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end dropdown-notifications">
                                <li class="dropdown-header d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-bell me-2"></i>Notificaciones</span>
                                    <button class="btn btn-sm btn-link text-danger p-0" onclick="clearAllNotifications()">
                                        <small><i class="bi bi-trash me-1"></i>Limpiar todas</small>
                                    </button>
                                </li>
                                <?php if (empty($notificaciones)): ?>
                                    <li class="dropdown-item text-muted py-3 text-center">
                                        <i class="bi bi-bell-slash fs-4 text-muted"></i><br>
                                        No hay notificaciones
                                    </li>
                                <?php else: ?>
                                    <?php foreach ($notificaciones as $notif): ?>
                                        <li class="notification-item <?= $notif['leida'] ? '' : 'notification-unread' ?>">
                                            <a class="dropdown-item d-flex justify-content-between align-items-center" href="#" onclick="markNotificationAsRead(<?= $notif['id'] ?>, this)">
                                                <div>
                                                    <div><?= htmlspecialchars($notif['mensaje']) ?></div>
                                                    <small class="text-muted"><?= date('d M H:i', strtotime($notif['fecha_creacion'])) ?></small>
                                                </div>
                                                <?php if (!$notif['leida']): ?>
                                                    <span class="badge bg-primary rounded-pill">Nuevo</span>
                                                <?php endif; ?>
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider m-0"></li>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <li>
                                    <a class="dropdown-item text-center text-primary fw-bold" href="#" onclick="markAllNotificationsAsRead()">
                                        <i class="bi bi-check2-all me-2"></i>Marcar todas como leídas
                                    </a>
                                </li>
                            </ul>
                        </li>
                    <?php endif; ?>
                    
                    <!-- Perfil de usuario -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center" href="#" id="navbarDropdownPerfil" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="/Odontologia/imagenes_usuarios/<?= htmlspecialchars($fotoPerfil) ?>" 
                                 class="navbar-profile-img me-2" 
                                 alt="Perfil"
                                 onerror="this.src='/Odontologia/imagenes_usuarios/default.webp'">
                            <strong class="navbar-profile-name"><?= htmlspecialchars($usuario_actual->nombre) ?></strong>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="/Odontologia/perfil.php"><i class="bi bi-person me-2"></i>Mi Perfil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="/Odontologia/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<?php if ($usuario_actual && in_array($usuario_actual->rol_type, ['A', 'O', 'S'])): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Función para marcar una notificación como leída
function markNotificationAsRead(id, element) {
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: `notificacion_action=mark_read&notificacion_id=${id}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            element.closest('.notification-item').classList.remove('notification-unread');
            const badge = element.querySelector('.badge');
            if (badge) {
                badge.style.animation = 'bounce 0.6s';
                setTimeout(() => {
                    badge.remove();
                    updateNotificationBadge();
                }, 600);
            }
        }
    })
    .catch(error => console.error('Error:', error));
}

// Función para marcar todas las notificaciones como leídas
function markAllNotificationsAsRead() {
    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'notificacion_action=mark_all_read'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.querySelectorAll('.notification-unread').forEach(el => {
                el.classList.remove('notification-unread');
                const badge = el.querySelector('.badge');
                if (badge) {
                    badge.style.animation = 'fadeIn 0.5s reverse';
                    setTimeout(() => badge.remove(), 500);
                }
            });
            
            const feedback = document.createElement('div');
            feedback.innerHTML = '<i class="bi bi-check2-circle me-2"></i>Todas las notificaciones marcadas como leídas';
            feedback.className = 'alert alert-success alert-dismissible fade show floating-alert';
            
            const closeBtn = document.createElement('button');
            closeBtn.className = 'btn-close';
            closeBtn.setAttribute('data-bs-dismiss', 'alert');
            closeBtn.setAttribute('aria-label', 'Close');
            feedback.appendChild(closeBtn);
            
            document.body.appendChild(feedback);
            
            setTimeout(() => {
                feedback.classList.add('fade');
                setTimeout(() => feedback.remove(), 500);
            }, 3000);
            
            updateNotificationBadge();
        }
    })
    .catch(error => console.error('Error:', error));
}

// Función para limpiar todas las notificaciones
function clearAllNotifications() {
    if (confirm('¿Estás seguro de que deseas eliminar todas las notificaciones?')) {
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: 'notificacion_action=clear_all'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const notificationsList = document.querySelector('.dropdown-notifications');
                notificationsList.style.animation = 'fadeIn 0.5s reverse';
                
                setTimeout(() => {
                    notificationsList.innerHTML = `
                        <li class="dropdown-header d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-bell me-2"></i>Notificaciones</span>
                        </li>
                        <li class="dropdown-item text-muted py-3 text-center">
                            <i class="bi bi-bell-slash fs-4 text-muted"></i><br>
                            No hay notificaciones
                        </li>
                    `;
                    notificationsList.style.animation = 'fadeIn 0.5s';
                    
                    const feedback = document.createElement('div');
                    feedback.innerHTML = '<i class="bi bi-trash3 me-2"></i>Notificaciones eliminadas';
                    feedback.className = 'alert alert-info alert-dismissible fade show floating-alert';
                    
                    const closeBtn = document.createElement('button');
                    closeBtn.className = 'btn-close';
                    closeBtn.setAttribute('data-bs-dismiss', 'alert');
                    closeBtn.setAttribute('aria-label', 'Close');
                    feedback.appendChild(closeBtn);
                    
                    document.body.appendChild(feedback);
                    
                    setTimeout(() => {
                        feedback.classList.add('fade');
                        setTimeout(() => feedback.remove(), 500);
                    }, 3000);
                    
                    updateNotificationBadge();
                }, 500);
            }
        })
        .catch(error => console.error('Error:', error));
    }
}

// Función para actualizar el contador de notificaciones
function updateNotificationBadge() {
    const badge = document.querySelector('.navbar .badge');
    if (badge) {
        const currentCount = parseInt(badge.textContent);
        if (currentCount > 1) {
            badge.textContent = currentCount - 1;
            badge.style.animation = 'pulse 1s';
        } else {
            badge.style.animation = 'fadeIn 0.5s reverse';
            setTimeout(() => {
                badge.remove();
            }, 500);
        }
    }
}

// Efecto de scroll mejorado para la navbar
let lastScroll = 0;
const navbar = document.querySelector('.navbar');
const navbarHeight = navbar.offsetHeight;

window.addEventListener('scroll', () => {
    const currentScroll = window.pageYOffset;
    
    if (currentScroll <= 10) {
        navbar.classList.remove('navbar-scroll-down');
        navbar.classList.add('navbar-scroll-up');
        return;
    }
    
    if (currentScroll > lastScroll && currentScroll > navbarHeight) {
        navbar.classList.remove('navbar-scroll-up');
        navbar.classList.add('navbar-scroll-down');
    } else if (currentScroll < lastScroll) {
        navbar.classList.remove('navbar-scroll-down');
        navbar.classList.add('navbar-scroll-up');
    }
    
    lastScroll = currentScroll;
});

// Efecto de onda para elementos interactivos
document.querySelectorAll('.wave-effect').forEach(element => {
    element.addEventListener('click', function(e) {
        const rect = this.getBoundingClientRect();
        const x = e.clientX - rect.left;
        const y = e.clientY - rect.top;
        
        const wave = this.querySelector('.wave') || document.createElement('span');
        wave.className = 'wave';
        wave.style.left = x + 'px';
        wave.style.top = y + 'px';
        
        this.appendChild(wave);
        
        setTimeout(() => wave.remove(), 500);
    });
});

// Inicializar tooltips
const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl, {
        trigger: 'hover'
    });
});
</script>
<?php endif; ?>
</body>
</html>
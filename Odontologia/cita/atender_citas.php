<?php
ob_start();
session_start();
$page_title = "Gestión de Citas Odontológicas";
require_once __DIR__ . '/../config/Database.php';
include '../templates/navbar.php';
if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}
$usuario_actual = (object) $_SESSION['usuario'];
// Verificar permisos
$permisos_acciones = $conn->query("SELECT * FROM auth_acciones WHERE rol_type = '{$usuario_actual->rol_type}'")->fetch(PDO::FETCH_ASSOC);
$permisos_secciones = $conn->query("SELECT * FROM auth_secciones WHERE rol_type = '{$usuario_actual->rol_type}'")->fetch(PDO::FETCH_ASSOC);

if (!$permisos_secciones || !$permisos_secciones['auth_atender_citas']) {
    header("Location: /Odontologia/templates/unauthorized.php");
    exit;
}

// Asegurar que la tabla tenga las columnas necesarias
try {
    $conn->query("SELECT motivo, observaciones, recetas FROM citas LIMIT 1");
} catch (PDOException $e) {
    $conn->exec("ALTER TABLE citas 
                ADD COLUMN motivo TEXT AFTER estado_atencion,
                ADD COLUMN observaciones TEXT AFTER motivo,
                ADD COLUMN recetas TEXT AFTER observaciones");
    $conn->exec("ALTER TABLE citas MODIFY estado_atencion ENUM('Por atender','Atendido','Cancelado','Postergado','No Atendido') NOT NULL DEFAULT 'Por atender'");
}

// Procesar acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['accion'], $_POST['cita_id'])) {
        $permiso_requerido = '';
        switch ($_POST['accion']) {
            case 'confirmar': $permiso_requerido = 'auth_editar'; break;
            case 'atender': $permiso_requerido = 'auth_editar'; break;
            case 'postergar': $permiso_requerido = 'auth_editar'; break;
            case 'no_atender': $permiso_requerido = 'auth_editar'; break;
            case 'cancelar': $permiso_requerido = 'auth_eliminar'; break;
            case 'restaurar': $permiso_requerido = 'auth_crear'; break;
            case 'eliminar': $permiso_requerido = 'auth_eliminar'; break;
        }
        
        if (!$permisos_acciones || !$permisos_acciones[$permiso_requerido]) {
            $_SESSION['mensaje'] = "No tienes permisos para realizar esta acción";
            header("Location: index.php");
            exit;
        }

        $accion = $_POST['accion'];
        $cita_id = intval($_POST['cita_id']);
        $motivo = $_POST['motivo'] ?? '';
        $nueva_fecha = $_POST['nueva_fecha'] ?? null;
        $nueva_hora = $_POST['nueva_hora'] ?? null;
        $observaciones = $_POST['observaciones'] ?? '';
        $recetas = $_POST['recetas'] ?? '';

        switch ($accion) {
            case 'confirmar':
                $stmt = $conn->prepare("UPDATE citas SET estado = 'Confirmado', fecha_actualizacion = NOW() WHERE id = ?");
                $stmt->execute([$cita_id]);
                $_SESSION['mensaje'] = "Cita confirmada correctamente";
                break;
                
            case 'atender':
                $stmt = $conn->prepare("UPDATE citas 
                                      SET estado_atencion = 'Atendido', 
                                          estado = 'Completado',
                                          observaciones = ?,
                                          recetas = ?,
                                          motivo = ?,
                                          fecha_actualizacion = NOW()
                                      WHERE id = ?");
                $stmt->execute([$observaciones, $recetas, $motivo, $cita_id]);
                $_SESSION['mensaje'] = "Cita atendida correctamente";
                break;
                
            case 'postergar':
                if ($nueva_fecha && $nueva_hora) {
                    $stmt = $conn->prepare("UPDATE citas 
                                          SET fecha = ?, 
                                              hora = ?, 
                                              estado = 'Pendiente',
                                              estado_atencion = 'Postergado',
                                              motivo = ?,
                                              fecha_actualizacion = NOW()
                                          WHERE id = ?");
                    $stmt->execute([$nueva_fecha, $nueva_hora, $motivo, $cita_id]);
                    $_SESSION['mensaje'] = "Cita postergada correctamente";
                }
                break;
                
            case 'no_atender':
                $stmt = $conn->prepare("UPDATE citas 
                                      SET estado = 'Cancelado', 
                                          estado_atencion = 'No Atendido', 
                                          motivo = ?,
                                          fecha_actualizacion = NOW()
                                      WHERE id = ?");
                $stmt->execute([$motivo, $cita_id]);
                $_SESSION['mensaje'] = "Cita marcada como no atendida";
                break;
                
            case 'cancelar':
                $stmt = $conn->prepare("UPDATE citas 
                                      SET estado = 'Cancelado', 
                                          estado_atencion = 'Cancelado',
                                          fecha_actualizacion = NOW()
                                      WHERE id = ?");
                $stmt->execute([$cita_id]);
                $_SESSION['mensaje'] = "Cita cancelada correctamente";
                break;
                
            case 'restaurar':
                $stmt = $conn->prepare("UPDATE citas 
                                      SET estado = 'Pendiente', 
                                          estado_atencion = 'Por atender',
                                          fecha_actualizacion = NOW()
                                      WHERE id = ?");
                $stmt->execute([$cita_id]);
                $_SESSION['mensaje'] = "Cita restaurada correctamente";
                break;
                
            case 'eliminar':
                $stmt = $conn->prepare("UPDATE citas SET Estado_Sistema = 'Inactivo', fecha_actualizacion = NOW() WHERE id = ?");
                $stmt->execute([$cita_id]);
                
                // Registrar en auditoría
                $datos_originales = json_encode([
                    'id' => $cita_id,
                    'fecha_eliminacion' => date('Y-m-d H:i:s'),
                    'eliminado_por' => $usuario_actual->id
                ]);
                
                $auditoria_sql = "INSERT INTO auditoria_eliminaciones 
                                 (tabla_afectada, id_registro_afectado, usuario_eliminador_id, nombre_usuario_eliminador, datos_originales) 
                                 VALUES 
                                 ('citas', :id, :user_id, :user_name, :datos)";
                $auditoria_stmt = $conn->prepare($auditoria_sql);
                $auditoria_stmt->execute([
                    ':id' => $cita_id,
                    ':user_id' => $usuario_actual->id,
                    ':user_name' => $usuario_actual->nombre,
                    ':datos' => $datos_originales
                ]);
                
                $_SESSION['mensaje'] = "Cita marcada como inactiva correctamente";
                break;
        }

        header("Location: index.php");
        exit;
    }
}

// Configuración de paginación
$registros_por_pagina = 10;
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$offset = ($pagina - 1) * $registros_por_pagina;

// Construir consultas base con filtro por odontólogo si es necesario
$filtro_odontologo = ($usuario_actual->rol_type === 'Odontologo') ? "AND c.usuario_id = {$usuario_actual->id}" : "";

// Consulta para citas activas (Pendientes y Confirmadas)
$total_stmt = $conn->query("SELECT COUNT(*) FROM citas c WHERE c.estado IN ('Pendiente', 'Confirmado') AND c.Estado_Sistema = 'Activo' $filtro_odontologo");
$total_citas = $total_stmt->fetchColumn();
$total_paginas = ceil($total_citas / $registros_por_pagina);

$stmt = $conn->prepare("
    SELECT c.*, p.nombre AS paciente_nombre, p.cedula, p.telefono, p.sexo, 
           u.nombre AS doctor_nombre, u.email AS doctor_email,
           c.tratamiento AS tratamiento_nombre
    FROM citas c
    JOIN paciente p ON c.paciente_id = p.id
    JOIN usuario u ON c.usuario_id = u.id
    WHERE c.estado IN ('Pendiente', 'Confirmado')
    AND c.Estado_Sistema = 'Activo'
    $filtro_odontologo
    ORDER BY c.fecha, c.hora
    LIMIT $registros_por_pagina OFFSET $offset
");
$stmt->execute();
$citas_activas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Consulta para citas canceladas
$canceladas_stmt = $conn->query("
    SELECT c.*, p.nombre AS paciente_nombre, u.nombre AS doctor_nombre
    FROM citas c
    JOIN paciente p ON c.paciente_id = p.id
    JOIN usuario u ON c.usuario_id = u.id
    WHERE c.estado = 'Cancelado'
    AND c.Estado_Sistema = 'Activo'
    $filtro_odontologo
    ORDER BY c.fecha DESC
");
$citas_canceladas = $canceladas_stmt->fetchAll(PDO::FETCH_ASSOC);

// Consulta para citas atendidas
$atendidas_stmt = $conn->query("
    SELECT c.*, p.nombre AS paciente_nombre, p.telefono, p.sexo,
           u.nombre AS doctor_nombre, u.email AS doctor_email,
           c.tratamiento AS tratamiento_nombre
    FROM citas c
    JOIN paciente p ON c.paciente_id = p.id
    JOIN usuario u ON c.usuario_id = u.id
    WHERE c.estado_atencion = 'Atendido'
    AND c.Estado_Sistema = 'Activo'
    $filtro_odontologo
    ORDER BY c.fecha DESC
");
$citas_atendidas = $atendidas_stmt->fetchAll(PDO::FETCH_ASSOC);

// Consulta para citas no atendidas
$no_atendidas_stmt = $conn->query("
    SELECT c.*, p.nombre AS paciente_nombre, p.telefono, p.sexo,
           u.nombre AS doctor_nombre, u.email AS doctor_email,
           c.tratamiento AS tratamiento_nombre
    FROM citas c
    JOIN paciente p ON c.paciente_id = p.id
    JOIN usuario u ON c.usuario_id = u.id
    WHERE c.estado_atencion = 'No Atendido'
    AND c.Estado_Sistema = 'Activo'
    $filtro_odontologo
    ORDER BY c.fecha DESC
");
$citas_no_atendidas = $no_atendidas_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --color-pendiente: #ffc107;
            --color-confirmado: #28a745;
            --color-cancelado: #dc3545;
            --color-completado: #17a2b8;
            --color-postergado: #fd7e14;
            --color-noatendido: #6c757d;
            --color-atendido: #6610f2;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pendiente { background-color: var(--color-pendiente); color: #000; }
        .status-confirmado { background-color: var(--color-confirmado); color: #fff; }
        .status-cancelado { background-color: var(--color-cancelado); color: #fff; }
        .status-completado { background-color: var(--color-completado); color: #fff; }
        .status-postergado { background-color: var(--color-postergado); color: #fff; }
        .status-noatendido { background-color: var(--color-noatendido); color: #fff; }
        .status-atendido { background-color: var(--color-atendido); color: #fff; }
        
        .card-cita {
            border-left: 4px solid var(--color-pendiente);
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }
        .card-cita:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .card-cita.confirmado { border-left-color: var(--color-confirmado); }
        .card-cita.atendido { border-left-color: var(--color-atendido); }
        .card-cita.cancelado { border-left-color: var(--color-cancelado); }
        .card-cita.postergado { border-left-color: var(--color-postergado); }
        .card-cita.noatendido { border-left-color: var(--color-noatendido); }
        
        .info-section {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 8px;
            background-color: #f8f9fa;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .info-section h5 {
            border-bottom: 1px solid #dee2e6;
            padding-bottom: 8px;
            margin-bottom: 15px;
            color: #495057;
            font-weight: 600;
        }
        
        .action-buttons .btn { 
            margin-right: 5px;
            margin-bottom: 5px;
            min-width: 100px;
        }
        
        .opcion-elegida {
            background-color: #e7f5ff;
            border-left: 4px solid #228be6;
            padding: 15px;
            margin-bottom: 15px;
            border-radius: 8px;
        }
        .opcion-elegida h6 {
            color: #228be6;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .receta-box {
            background-color: #f8f9fa;
            border-left: 4px solid #20c997;
            padding: 15px;
            margin-top: 10px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            border-radius: 6px;
        }
        
        .mensaje-alerta {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            animation: fadeInOut 3s ease-in-out;
        }
        
        @keyframes fadeInOut {
            0% { opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { opacity: 0; }
        }
        
        .historial-section {
            margin-top: 40px;
            padding: 25px;
            background-color: #f8f9fa;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        }
        
        .historial-header {
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        
        .accordion-button:not(.collapsed) {
            background-color: #e7f5ff;
            color: #0c63e4;
        }
        
        .tabla-citas th {
            background-color: #f1f3f5;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        
        .tabla-citas td {
            vertical-align: middle;
        }
        
        .badge-tratamiento {
            background-color: #e64980;
            color: white;
        }
        
        .filtros-container {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .filtro-title {
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
        }
        
        .btn-action {
            min-width: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body class="bg-light">
<?php if (isset($_SESSION['mensaje'])): ?>
    <div class="mensaje-alerta alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?= $_SESSION['mensaje'] ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['mensaje']); ?>
<?php endif; ?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="mb-0">
            <i class="fas fa-calendar-check me-2"></i>Gestión de Citas
        </h2>
        <?php if ($usuario_actual->rol_type === 'Odontologo'): ?>
            <span class="badge bg-primary">
                <i class="fas fa-user-md me-1"></i> Odontólogo: <?= htmlspecialchars($usuario_actual->nombre) ?>
            </span>
        <?php endif; ?>
    </div>

    <!-- Pestañas para diferentes estados de citas -->
    <ul class="nav nav-tabs mb-4" id="citasTab" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="activas-tab" data-bs-toggle="tab" data-bs-target="#activas" type="button" role="tab">
                <i class="fas fa-list me-1"></i> Activas <span class="badge bg-primary ms-1"><?= count($citas_activas) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="canceladas-tab" data-bs-toggle="tab" data-bs-target="#canceladas" type="button" role="tab">
                <i class="fas fa-ban me-1"></i> Canceladas <span class="badge bg-danger ms-1"><?= count($citas_canceladas) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="atendidas-tab" data-bs-toggle="tab" data-bs-target="#atendidas" type="button" role="tab">
                <i class="fas fa-check-circle me-1"></i> Atendidas <span class="badge bg-success ms-1"><?= count($citas_atendidas) ?></span>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="no-atendidas-tab" data-bs-toggle="tab" data-bs-target="#no-atendidas" type="button" role="tab">
                <i class="fas fa-times-circle me-1"></i> No Atendidas <span class="badge bg-secondary ms-1"><?= count($citas_no_atendidas) ?></span>
            </button>
        </li>
    </ul>

    <div class="tab-content" id="citasTabContent">
        <!-- Pestaña de Citas Activas -->
        <div class="tab-pane fade show active" id="activas" role="tabpanel">
            <?php if (empty($citas_activas)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No hay citas activas registradas.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover tabla-citas">
                        <thead>
                        <tr>
                            <th>Paciente</th>
                            <th>Doctor</th>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Estado</th>
                            <th>Tratamiento</th>
                            <th>Acciones</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($citas_activas as $cita): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="me-2">
                                            <i class="fas fa-user-circle fa-lg" style="color: #6c757d;"></i>
                                        </div>
                                        <div>
                                            <strong><?= htmlspecialchars($cita['paciente_nombre']) ?></strong>
                                            <div class="text-muted small"><?= htmlspecialchars($cita['cedula']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($cita['doctor_nombre']) ?></td>
                                <td><?= date('d/m/Y', strtotime($cita['fecha'])) ?></td>
                                <td><?= date('H:i', strtotime($cita['hora'])) ?></td>
                                <td>
                                    <span class="status-badge <?= 'status-' . strtolower($cita['estado']) ?>">
                                        <?= $cita['estado'] ?>
                                    </span>
                                    <?php if ($cita['estado_atencion'] !== 'Por atender'): ?>
                                        <br>
                                        <span class="status-badge <?= 'status-' . strtolower(str_replace(' ', '', $cita['estado_atencion'])) ?> mt-1">
                                            <?= $cita['estado_atencion'] ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($cita['tratamiento_nombre']): ?>
                                        <span class="badge badge-tratamiento"><?= htmlspecialchars($cita['tratamiento_nombre']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">No especificado</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap action-buttons">
                                        <?php if ($permisos_acciones['auth_editar']): ?>
                                            <?php if ($cita['estado'] === 'Pendiente'): ?>
                                                <button type="button" class="btn btn-sm btn-success btn-action" data-bs-toggle="modal" data-bs-target="#confirmarModal<?= $cita['id'] ?>">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                                
                                                <div class="modal fade" id="confirmarModal<?= $cita['id'] ?>" tabindex="-1" aria-labelledby="confirmarModalLabel" aria-hidden="true">
                                                    <div class="modal-dialog">
                                                        <div class="modal-content">
                                                            <div class="modal-header bg-success text-white">
                                                                <h5 class="modal-title" id="confirmarModalLabel">Confirmar Cita</h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <p>¿Confirmar la cita de <strong><?= htmlspecialchars($cita['paciente_nombre']) ?></strong> para el <strong><?= date('d/m/Y', strtotime($cita['fecha'])) ?></strong> a las <strong><?= date('H:i', strtotime($cita['hora'])) ?></strong>?</p>
                                                                <p class="text-muted">Tratamiento: <?= $cita['tratamiento_nombre'] ? htmlspecialchars($cita['tratamiento_nombre']) : 'No especificado' ?></p>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                                <form method="POST">
                                                                    <input type="hidden" name="cita_id" value="<?= $cita['id'] ?>">
                                                                    <button type="submit" name="accion" value="confirmar" class="btn btn-success">
                                                                        <i class="fas fa-check me-1"></i> Confirmar
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <?php if ($cita['estado'] === 'Confirmado' && $cita['estado_atencion'] === 'Por atender'): ?>
                                                <button type="button" class="btn btn-sm btn-primary btn-action" data-bs-toggle="modal" data-bs-target="#gestionModal<?= $cita['id'] ?>">
                                                    <i class="fas fa-user-md"></i>
                                                </button>
                                                
                                                <div class="modal fade" id="gestionModal<?= $cita['id'] ?>" tabindex="-1" aria-labelledby="gestionModalLabel" aria-hidden="true">
                                                    <div class="modal-dialog modal-lg">
                                                        <div class="modal-content">
                                                            <div class="modal-header bg-primary text-white">
                                                                <h5 class="modal-title" id="gestionModalLabel">Gestión de Cita</h5>
                                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                            </div>
                                                            <div class="modal-body">
                                                                <div class="row">
                                                                    <div class="col-md-4 info-section">
                                                                        <h5><i class="fas fa-user me-2"></i>Datos del Paciente</h5>
                                                                        <p><strong>Nombre:</strong> <?= htmlspecialchars($cita['paciente_nombre']) ?></p>
                                                                        <p><strong>Cédula:</strong> <?= htmlspecialchars($cita['cedula']) ?></p>
                                                                        <p><strong>Teléfono:</strong> <?= htmlspecialchars($cita['telefono']) ?></p>
                                                                        <p><strong>Sexo:</strong> <?= htmlspecialchars($cita['sexo']) ?></p>
                                                                    </div>
                                                                    
                                                                    <div class="col-md-4 info-section">
                                                                        <h5><i class="fas fa-user-md me-2"></i>Datos del Médico</h5>
                                                                        <p><strong>Nombre:</strong> <?= htmlspecialchars($cita['doctor_nombre']) ?></p>
                                                                        <p><strong>Correo:</strong> <?= htmlspecialchars($cita['doctor_email']) ?></p>
                                                                    </div>
                                                                    
                                                                    <div class="col-md-4 info-section">
                                                                        <h5><i class="fas fa-calendar-check me-2"></i>Detalles de la Cita</h5>
                                                                        <p><strong>Fecha:</strong> <?= date('d/m/Y', strtotime($cita['fecha'])) ?></p>
                                                                        <p><strong>Hora:</strong> <?= date('H:i', strtotime($cita['hora'])) ?></p>
                                                                        <p><strong>Estado:</strong> <?= $cita['estado'] ?></p>
                                                                        <p><strong>Tratamiento:</strong> <?= $cita['tratamiento_nombre'] ? htmlspecialchars($cita['tratamiento_nombre']) : 'No especificado' ?></p>
                                                                    </div>
                                                                </div>
                                                                
                                                                <div class="mt-4">
                                                                    <h5><i class="fas fa-tasks me-2"></i>Acción a realizar</h5>
                                                                    
                                                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                                                        <?php if ($permisos_acciones['auth_editar']): ?>
                                                                            <button type="button" class="btn btn-success" onclick="mostrarAtender(<?= $cita['id'] ?>)">
                                                                                <i class="fas fa-check-circle me-1"></i> Atender
                                                                            </button>
                                                                            
                                                                            <button type="button" class="btn btn-warning" onclick="mostrarPostergar(<?= $cita['id'] ?>)">
                                                                                <i class="fas fa-clock me-1"></i> Postergar
                                                                            </button>
                                                                            
                                                                            <button type="button" class="btn btn-danger" onclick="mostrarNoAtender(<?= $cita['id'] ?>)">
                                                                                <i class="fas fa-times-circle me-1"></i> No Atender
                                                                            </button>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    
                                                                    <div id="postergarSection<?= $cita['id'] ?>" class="opcion-elegida d-none">
                                                                        <h6><i class="fas fa-clock me-1"></i>Postergar Cita</h6>
                                                                        <p class="text-muted">Cambiar la fecha y hora de esta cita</p>
                                                                        <form method="POST">
                                                                            <input type="hidden" name="cita_id" value="<?= $cita['id'] ?>">
                                                                            <input type="hidden" name="accion" value="postergar">
                                                                            
                                                                            <div class="mb-3">
                                                                                <label class="form-label">Nueva Fecha</label>
                                                                                <input type="date" class="form-control" name="nueva_fecha" required min="<?= date('Y-m-d') ?>">
                                                                            </div>
                                                                            <div class="mb-3">
                                                                                <label class="form-label">Nueva Hora</label>
                                                                                <input type="time" class="form-control" name="nueva_hora" required>
                                                                            </div>
                                                                            <div class="mb-3">
                                                                                <label class="form-label">Motivo</label>
                                                                                <textarea class="form-control" name="motivo" rows="2" required placeholder="Indique el motivo de la postergación"></textarea>
                                                                            </div>
                                                                            
                                                                            <div class="d-flex justify-content-between">
                                                                                <button type="button" class="btn btn-secondary" onclick="ocultarPostergar(<?= $cita['id'] ?>)">
                                                                                    <i class="fas fa-undo me-1"></i> Cancelar
                                                                                </button>
                                                                                <button type="submit" class="btn btn-warning">
                                                                                    <i class="fas fa-check me-1"></i> Confirmar postergación
                                                                                </button>
                                                                            </div>
                                                                        </form>
                                                                    </div>

                                                                    <div id="atenderSection<?= $cita['id'] ?>" class="opcion-elegida d-none">
                                                                        <h6><i class="fas fa-check-circle me-1"></i>Atender Cita</h6>
                                                                        <p class="text-muted">Registrar observaciones y receta médica para esta cita</p>
                                                                        <form method="POST">
                                                                            <input type="hidden" name="cita_id" value="<?= $cita['id'] ?>">
                                                                            <input type="hidden" name="accion" value="atender">
                                                                            
                                                                            <div class="mb-3">
                                                                                <label class="form-label">Observaciones</label>
                                                                                <textarea class="form-control" name="observaciones" rows="3" required placeholder="Registre las observaciones de la consulta"></textarea>
                                                                            </div>
                                                                            <div class="mb-3">
                                                                                <label class="form-label">Receta médica</label>
                                                                                <textarea class="form-control" name="recetas" rows="4" placeholder="Indique los medicamentos recetados (opcional)"></textarea>
                                                                            </div>
                                                                            <input type="hidden" name="motivo" value="Cita atendida">
                                                                            
                                                                            <div class="d-flex justify-content-between">
                                                                                <button type="button" class="btn btn-secondary" onclick="ocultarAtender(<?= $cita['id'] ?>)">
                                                                                    <i class="fas fa-undo me-1"></i> Cancelar
                                                                                </button>
                                                                                <button type="submit" class="btn btn-success">
                                                                                    <i class="fas fa-check me-1"></i> Confirmar atención
                                                                                </button>
                                                                            </div>
                                                                        </form>
                                                                    </div>
                                                                    
                                                                    <div id="noAtenderSection<?= $cita['id'] ?>" class="opcion-elegida d-none">
                                                                        <h6><i class="fas fa-times-circle me-1"></i>No Atender Cita</h6>
                                                                        <p class="text-muted">Cancelar esta cita sin atender</p>
                                                                        <form method="POST">
                                                                            <input type="hidden" name="cita_id" value="<?= $cita['id'] ?>">
                                                                            <input type="hidden" name="accion" value="no_atender">
                                                                            
                                                                            <div class="mb-3">
                                                                                <label class="form-label">Motivo</label>
                                                                                <textarea class="form-control" name="motivo" rows="3" required placeholder="Indique el motivo por el cual no se atendió la cita"></textarea>
                                                                            </div>
                                                                            
                                                                            <div class="d-flex justify-content-between">
                                                                                <button type="button" class="btn btn-secondary" onclick="ocultarNoAtender(<?= $cita['id'] ?>)">
                                                                                    <i class="fas fa-undo me-1"></i> Cancelar
                                                                                </button>
                                                                                <button type="submit" class="btn btn-danger">
                                                                                    <i class="fas fa-check me-1"></i> Confirmar no atención
                                                                                </button>
                                                                            </div>
                                                                        </form>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                                                    <i class="fas fa-times me-1"></i> Cerrar
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        
                                        <?php if ($permisos_acciones['auth_eliminar']): ?>
                                            <button type="button" class="btn btn-sm btn-danger btn-action" data-bs-toggle="modal" data-bs-target="#cancelarModal<?= $cita['id'] ?>">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                            
                                            <div class="modal fade" id="cancelarModal<?= $cita['id'] ?>" tabindex="-1" aria-labelledby="cancelarModalLabel" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-danger text-white">
                                                            <h5 class="modal-title" id="cancelarModalLabel">Cancelar Cita</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>¿Cancelar la cita de <strong><?= htmlspecialchars($cita['paciente_nombre']) ?></strong> para el <strong><?= date('d/m/Y', strtotime($cita['fecha'])) ?></strong> a las <strong><?= date('H:i', strtotime($cita['hora'])) ?></strong>?</p>
                                                            <p class="text-muted">Tratamiento: <?= $cita['tratamiento_nombre'] ? htmlspecialchars($cita['tratamiento_nombre']) : 'No especificado' ?></p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No</button>
                                                            <form method="POST">
                                                                <input type="hidden" name="cita_id" value="<?= $cita['id'] ?>">
                                                                <button type="submit" name="accion" value="cancelar" class="btn btn-danger">
                                                                    <i class="fas fa-check me-1"></i> Sí, Cancelar
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación -->
                <?php if ($total_paginas > 1): ?>
                    <nav aria-label="Paginación de citas">
                        <ul class="pagination justify-content-center">
                            <?php if ($pagina > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=<?= $pagina - 1 ?>" aria-label="Anterior">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
                                <li class="page-item <?= $pagina === $i ? 'active' : '' ?>">
                                    <a class="page-link" href="?pagina=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <?php if ($pagina < $total_paginas): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?pagina=<?= $pagina + 1 ?>" aria-label="Siguiente">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Pestaña de Citas Canceladas -->
        <div class="tab-pane fade" id="canceladas" role="tabpanel">
            <?php if (empty($citas_canceladas)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No hay citas canceladas registradas.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover tabla-citas">
                        <thead>
                        <tr>
                            <th>Paciente</th>
                            <th>Doctor</th>
                            <th>Fecha</th>
                            <th>Hora</th>
                            <th>Estado</th>
                            <th>Acciones</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($citas_canceladas as $cita): ?>
                            <tr>
                                <td><?= htmlspecialchars($cita['paciente_nombre']) ?></td>
                                <td><?= htmlspecialchars($cita['doctor_nombre']) ?></td>
                                <td><?= date('d/m/Y', strtotime($cita['fecha'])) ?></td>
                                <td><?= date('H:i', strtotime($cita['hora'])) ?></td>
                                <td>
                                    <span class="status-badge status-cancelado"><?= $cita['estado'] ?></span>
                                    <br>
                                    <span class="status-badge status-cancelado mt-1"><?= $cita['estado_atencion'] ?></span>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap action-buttons">
                                        <?php if ($permisos_acciones['auth_crear']): ?>
                                            <button type="button" class="btn btn-sm btn-warning btn-action" data-bs-toggle="modal" data-bs-target="#restaurarModal<?= $cita['id'] ?>">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                            
                                            <div class="modal fade" id="restaurarModal<?= $cita['id'] ?>" tabindex="-1" aria-labelledby="restaurarModalLabel" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-warning text-dark">
                                                            <h5 class="modal-title" id="restaurarModalLabel">Restaurar Cita</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>¿Restaurar la cita cancelada de <strong><?= htmlspecialchars($cita['paciente_nombre']) ?></strong> para el <strong><?= date('d/m/Y', strtotime($cita['fecha'])) ?></strong> a las <strong><?= date('H:i', strtotime($cita['hora'])) ?></strong>?</p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No</button>
                                                            <form method="POST">
                                                                <input type="hidden" name="cita_id" value="<?= $cita['id'] ?>">
                                                                <button type="submit" name="accion" value="restaurar" class="btn btn-warning">
                                                                    <i class="fas fa-check me-1"></i> Sí, Restaurar
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($permisos_acciones['auth_eliminar']): ?>
                                            <button type="button" class="btn btn-sm btn-dark btn-action" data-bs-toggle="modal" data-bs-target="#eliminarModal<?= $cita['id'] ?>">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                            
                                            <div class="modal fade" id="eliminarModal<?= $cita['id'] ?>" tabindex="-1" aria-labelledby="eliminarModalLabel" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-dark text-white">
                                                            <h5 class="modal-title" id="eliminarModalLabel">Eliminar Cita</h5>
                                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p>¿Eliminar permanentemente la cita de <strong><?= htmlspecialchars($cita['paciente_nombre']) ?></strong> para el <strong><?= date('d/m/Y', strtotime($cita['fecha'])) ?></strong> a las <strong><?= date('H:i', strtotime($cita['hora'])) ?></strong>?</p>
                                                            <p class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i> Esta acción no se puede deshacer.</p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                                            <form method="POST">
                                                                <input type="hidden" name="cita_id" value="<?= $cita['id'] ?>">
                                                                <button type="submit" name="accion" value="eliminar" class="btn btn-danger">
                                                                    <i class="fas fa-check me-1"></i> Sí, Eliminar
                                                                </button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pestaña de Citas Atendidas -->
        <div class="tab-pane fade" id="atendidas" role="tabpanel">
            <?php if (empty($citas_atendidas)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No hay citas atendidas registradas.
                </div>
            <?php else: ?>
                <div class="accordion" id="accordionAtendidas">
                    <?php foreach ($citas_atendidas as $cita): ?>
                        <div class="accordion-item mb-3 border-0">
                            <div class="accordion-header" id="headingAtendida<?= $cita['id'] ?>">
                                <button class="accordion-button collapsed shadow-none rounded" type="button" data-bs-toggle="collapse" 
                                        data-bs-target="#collapseAtendida<?= $cita['id'] ?>" 
                                        aria-expanded="false" aria-controls="collapseAtendida<?= $cita['id'] ?>">
                                    <div class="d-flex justify-content-between w-100 align-items-center">
                                        <div>
                                            <span class="fw-bold"><?= htmlspecialchars($cita['paciente_nombre']) ?></span>
                                            <span class="text-muted ms-2"><?= date('d/m/Y', strtotime($cita['fecha'])) ?> <?= date('H:i', strtotime($cita['hora'])) ?></span>
                                        </div>
                                        <span class="status-badge status-atendido">Atendido</span>
                                    </div>
                                </button>
                            </div>
                            <div id="collapseAtendida<?= $cita['id'] ?>" class="accordion-collapse collapse" 
                                 aria-labelledby="headingAtendida<?= $cita['id'] ?>" 
                                 data-bs-parent="#accordionAtendidas">
                                <div class="accordion-body pt-0">
                                    <div class="row">
                                        <div class="col-md-4 info-section">
                                            <h5><i class="fas fa-user-md me-2"></i> Datos del Médico</h5>
                                            <p><strong>Nombre:</strong> <?= htmlspecialchars($cita['doctor_nombre']) ?></p>
                                            <p><strong>Correo:</strong> <?= htmlspecialchars($cita['doctor_email']) ?></p>
                                        </div>
                                        
                                        <div class="col-md-4 info-section">
                                            <h5><i class="fas fa-user me-2"></i> Datos del Paciente</h5>
                                            <p><strong>Nombre:</strong> <?= htmlspecialchars($cita['paciente_nombre']) ?></p>
                                            <p><strong>Teléfono:</strong> <?= htmlspecialchars($cita['telefono']) ?></p>
                                            <p><strong>Sexo:</strong> <?= htmlspecialchars($cita['sexo']) ?></p>
                                        </div>
                                        
                                        <div class="col-md-4 info-section">
                                            <h5><i class="fas fa-calendar-check me-2"></i> Información de la Cita</h5>
                                            <p><strong>Fecha:</strong> <?= date('d/m/Y', strtotime($cita['fecha'])) ?></p>
                                            <p><strong>Hora:</strong> <?= date('H:i', strtotime($cita['hora'])) ?></p>
                                            <p><strong>Estado:</strong> <?= $cita['estado'] ?></p>
                                            <p><strong>Tratamiento:</strong> <?= $cita['tratamiento_nombre'] ? htmlspecialchars($cita['tratamiento_nombre']) : 'No especificado' ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="row mt-3">
                                        <div class="col-md-12 info-section">
                                            <h5><i class="fas fa-clipboard-check me-2"></i> Detalles de la Atención</h5>
                                            <?php if (!empty($cita['motivo'])): ?>
                                                <p><strong>Motivo:</strong></p>
                                                <p><?= nl2br(htmlspecialchars($cita['motivo'])) ?></p>
                                            <?php endif; ?>
                                            
                                            <p><strong>Observaciones:</strong></p>
                                            <p><?= nl2br(htmlspecialchars($cita['observaciones'])) ?></p>
                                            
                                            <?php if (!empty($cita['recetas'])): ?>
                                                <p><strong>Receta médica:</strong></p>
                                                <div class="receta-box">
                                                    <?= nl2br(htmlspecialchars($cita['recetas'])) ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <p class="text-muted mt-3">
                                                <small>
                                                    <i class="fas fa-clock me-1"></i> Fecha de atención: 
                                                    <?= date('d/m/Y H:i', strtotime($cita['fecha_actualizacion'])) ?>
                                                </small>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pestaña de Citas No Atendidas -->
        <div class="tab-pane fade" id="no-atendidas" role="tabpanel">
            <?php if (empty($citas_no_atendidas)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> No hay citas no atendidas registradas.
                </div>
            <?php else: ?>
                <div class="accordion" id="accordionNoAtendidas">
                    <?php foreach ($citas_no_atendidas as $cita): ?>
                        <div class="accordion-item mb-3 border-0">
                            <div class="accordion-header" id="headingNoAtendida<?= $cita['id'] ?>">
                                <button class="accordion-button collapsed shadow-none rounded" type="button" data-bs-toggle="collapse" 
                                        data-bs-target="#collapseNoAtendida<?= $cita['id'] ?>" 
                                        aria-expanded="false" aria-controls="collapseNoAtendida<?= $cita['id'] ?>">
                                    <div class="d-flex justify-content-between w-100 align-items-center">
                                        <div>
                                            <span class="fw-bold"><?= htmlspecialchars($cita['paciente_nombre']) ?></span>
                                            <span class="text-muted ms-2"><?= date('d/m/Y', strtotime($cita['fecha'])) ?> <?= date('H:i', strtotime($cita['hora'])) ?></span>
                                        </div>
                                        <span class="status-badge status-noatendido">No Atendido</span>
                                    </div>
                                </button>
                            </div>
                            <div id="collapseNoAtendida<?= $cita['id'] ?>" class="accordion-collapse collapse" 
                                 aria-labelledby="headingNoAtendida<?= $cita['id'] ?>" 
                                 data-bs-parent="#accordionNoAtendidas">
                                <div class="accordion-body pt-0">
                                    <div class="row">
                                        <div class="col-md-4 info-section">
                                            <h5><i class="fas fa-user-md me-2"></i> Datos del Médico</h5>
                                            <p><strong>Nombre:</strong> <?= htmlspecialchars($cita['doctor_nombre']) ?></p>
                                            <p><strong>Correo:</strong> <?= htmlspecialchars($cita['doctor_email']) ?></p>
                                        </div>
                                        
                                        <div class="col-md-4 info-section">
                                            <h5><i class="fas fa-user me-2"></i> Datos del Paciente</h5>
                                            <p><strong>Nombre:</strong> <?= htmlspecialchars($cita['paciente_nombre']) ?></p>
                                            <p><strong>Teléfono:</strong> <?= htmlspecialchars($cita['telefono']) ?></p>
                                            <p><strong>Sexo:</strong> <?= htmlspecialchars($cita['sexo']) ?></p>
                                        </div>
                                        
                                        <div class="col-md-4 info-section">
                                            <h5><i class="fas fa-calendar-check me-2"></i> Información de la Cita</h5>
                                            <p><strong>Fecha:</strong> <?= date('d/m/Y', strtotime($cita['fecha'])) ?></p>
                                            <p><strong>Hora:</strong> <?= date('H:i', strtotime($cita['hora'])) ?></p>
                                            <p><strong>Estado:</strong> <?= $cita['estado'] ?></p>
                                            <p><strong>Tratamiento:</strong> <?= $cita['tratamiento_nombre'] ? htmlspecialchars($cita['tratamiento_nombre']) : 'No especificado' ?></p>
                                        </div>
                                    </div>
                                    
                                    <div class="row mt-3">
                                        <div class="col-md-12 info-section">
                                            <h5><i class="fas fa-exclamation-circle me-2"></i> Motivo de no atención</h5>
                                            <p><?= nl2br(htmlspecialchars($cita['motivo'])) ?></p>
                                            
                                            <p class="text-muted mt-3">
                                                <small>
                                                    <i class="fas fa-clock me-1"></i> Fecha de cancelación: 
                                                    <?= date('d/m/Y H:i', strtotime($cita['fecha_actualizacion'])) ?>
                                                </small>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js"></script>

<script>
// Funciones para mostrar/ocultar secciones de gestión de citas
function mostrarPostergar(citaId) {
    document.getElementById(`atenderSection${citaId}`).classList.add('d-none');
    document.getElementById(`noAtenderSection${citaId}`).classList.add('d-none');
    document.getElementById(`postergarSection${citaId}`).classList.remove('d-none');
}

function ocultarPostergar(citaId) {
    document.getElementById(`postergarSection${citaId}`).classList.add('d-none');
}

function mostrarAtender(citaId) {
    document.getElementById(`postergarSection${citaId}`).classList.add('d-none');
    document.getElementById(`noAtenderSection${citaId}`).classList.add('d-none');
    document.getElementById(`atenderSection${citaId}`).classList.remove('d-none');
}

function ocultarAtender(citaId) {
    document.getElementById(`atenderSection${citaId}`).classList.add('d-none');
}

function mostrarNoAtender(citaId) {
    document.getElementById(`atenderSection${citaId}`).classList.add('d-none');
    document.getElementById(`postergarSection${citaId}`).classList.add('d-none');
    document.getElementById(`noAtenderSection${citaId}`).classList.remove('d-none');
}

function ocultarNoAtender(citaId) {
    document.getElementById(`noAtenderSection${citaId}`).classList.add('d-none');
}

// Ocultar mensaje de alerta después de 3 segundos
setTimeout(() => {
    const alert = document.querySelector('.mensaje-alerta');
    if (alert) {
        alert.style.display = 'none';
    }
}, 3000);

// Inicializar tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
});
</script>
</body>
</html>

<?php
ob_end_flush(); // Send the output buffer
?>
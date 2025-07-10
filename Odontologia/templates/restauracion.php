<?php
// Inicio de sesión y configuración de la conexión a la base de datos
session_start();

// Configuración de la conexión a la base de datos
require_once __DIR__ . '/../config/Database.php';
include './navbar.php';

// Verificación de usuario y permisos
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

// Obtener información del usuario desde la sesión o base de datos
$usuario_id = $_SESSION['usuario']['id'] ?? null;
$nombre_usuario = $_SESSION['usuario']['nombre'] ?? 'Usuario';
$rol_usuario = $_SESSION['usuario']['rol_type'] ?? null;

// Procesamiento del formulario de restauración
$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restaurar'])) {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    $tabla = filter_input(INPUT_POST, 'tabla', FILTER_SANITIZE_STRING);
    
    if ($id && $tabla) {
        // Verificación de permisos para odontólogos
        if ($rol_usuario == 'O') {
            $stmt = $conn->prepare("SELECT usuario_eliminador_id FROM auditoria_eliminaciones 
                                  WHERE id_registro_afectado = ? AND tabla_afectada = ?");
            $stmt->execute([$id, $tabla]);
            $registro = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$registro || $registro['usuario_eliminador_id'] != $usuario_id) {
                $mensaje = 'No tienes permiso para restaurar este registro';
                $tipo_mensaje = 'danger';
            }
        }
        
        if (empty($mensaje)) {
            try {
                $conn->beginTransaction();
                
                // Restaurar el registro
                $sql = "UPDATE $tabla SET Estado_Sistema = 'Activo' WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$id]);
                
                // Registrar la restauración
                $sql = "INSERT INTO auditoria_restauraciones 
                        (tabla_afectada, id_registro_afectado, usuario_restaurador_id, nombre_usuario_restaurador, fecha_restauracion)
                        VALUES (?, ?, ?, ?, NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->execute([$tabla, $id, $usuario_id, $nombre_usuario]);
                
                $conn->commit();
                
                $mensaje = 'Registro restaurado con éxito';
                $tipo_mensaje = 'success';
            } catch (PDOException $e) {
                $conn->rollBack();
                $mensaje = 'Error al restaurar: ' . $e->getMessage();
                $tipo_mensaje = 'danger';
            }
        }
    } else {
        $mensaje = 'Datos incompletos para la restauración';
        $tipo_mensaje = 'danger';
    }
}

// Obtener parámetros para el filtrado
$tabla_filtro = filter_input(INPUT_GET, 'tabla', FILTER_SANITIZE_STRING) ?? 'paciente';
$tablas_permitidas = ['paciente', 'usuario', 'historia_medica', 'odontograma', 'citas'];
if (!in_array($tabla_filtro, $tablas_permitidas)) {
    $tabla_filtro = 'paciente';
}

// Filtros adicionales
$filtro_usuario = filter_input(INPUT_GET, 'usuario', FILTER_VALIDATE_INT);
$filtro_desde = filter_input(INPUT_GET, 'desde', FILTER_SANITIZE_STRING);
$filtro_hasta = filter_input(INPUT_GET, 'hasta', FILTER_SANITIZE_STRING);
$filtro_busqueda = filter_input(INPUT_GET, 'busqueda', FILTER_SANITIZE_STRING);

// Consulta base para obtener registros eliminados
$query = "SELECT ae.*, u.nombre as nombre_eliminador 
          FROM auditoria_eliminaciones ae
          JOIN usuario u ON ae.usuario_eliminador_id = u.id
          WHERE ae.tabla_afectada = ?";

$params = [$tabla_filtro];

// Aplicar filtros adicionales
if ($filtro_usuario) {
    $query .= " AND ae.usuario_eliminador_id = ?";
    $params[] = $filtro_usuario;
}

if ($filtro_desde) {
    $query .= " AND ae.fecha_eliminacion >= ?";
    $params[] = $filtro_desde;
}

if ($filtro_hasta) {
    $query .= " AND ae.fecha_eliminacion <= ?";
    $params[] = $filtro_hasta . ' 23:59:59';
}

if ($filtro_busqueda) {
    $query .= " AND ae.datos_originales LIKE ?";
    $params[] = '%' . $filtro_busqueda . '%';
}

// Restricción para odontólogos
if ($rol_usuario == 'O') {
    $query .= " AND ae.usuario_eliminador_id = ?";
    $params[] = $usuario_id;
}

// Consulta para obtener usuarios (para el filtro)
$stmt_usuarios = $conn->query("SELECT id, nombre FROM usuario ORDER BY nombre");
$usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);

// Ejecutar consulta principal
$stmt = $conn->prepare($query);
$stmt->execute($params);
$registros_eliminados = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Función para formatear JSON de manera legible
function format_json($json_string) {
    $json = json_decode($json_string);
    return json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

// Función para obtener el nombre del rol
function getNombreRol($rol_type) {
    $roles = [
        'A' => 'Administrador',
        'O' => 'Odontólogo',
        'S' => 'Secretaría',
        'U' => 'Usuario'
    ];
    return $roles[$rol_type] ?? 'Sin rol definido';
}

// Función para obtener el icono del rol
function getIconoRol($rol_type) {
    $iconos = [
        'A' => 'fa-user-shield',
        'O' => 'fa-user-md',
        'S' => 'fa-user-secret',
        'U' => 'fa-user'
    ];
    return $iconos[$rol_type] ?? 'fa-user';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Restauración</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --color-pendiente: #ffc107;
            --color-confirmado: #28a745;
            --color-cancelado: #dc3545;
            --color-completado: #17a2b8;
            --color-postergado: #fd7e14;
            --color-noatendido: #6c757d;
            --color-atendido: #6610f2;
            --color-primary: #0d6efd;
            --color-secondary: #6c757d;
            --color-success: #198754;
            --color-danger: #dc3545;
            --color-warning: #ffc107;
            --color-info: #0dcaf0;
            --color-light: #f8f9fa;
            --color-dark: #212529;
        }
        
        body { 
            padding-top: 20px; 
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            max-width: 1400px;
        }
        
        /* Estilos para tarjetas */
        .card {
            margin-bottom: 20px; 
            border: none;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.05);
            transition: all 0.3s ease;
        }
        
        .card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid rgba(0,0,0,0.1);
            padding: 15px 20px;
            font-weight: 600;
            color: #495057;
            border-radius: 10px 10px 0 0 !important;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Estilos para badges */
        .badge {
            font-weight: 500;
            letter-spacing: 0.5px;
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
        
        /* Estilos para tablas */
        .tabla-citas {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        .tabla-citas th {
            background-color: #f1f3f5;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
            padding: 12px 15px;
            border: none;
        }
        
        .tabla-citas td {
            vertical-align: middle;
            padding: 12px 15px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .tabla-citas tr:last-child td {
            border-bottom: none;
        }
        
        .tabla-citas tr:hover td {
            background-color: #f8f9fa;
        }
        
        /* Estilos para botones */
        .btn {
            font-weight: 500;
            letter-spacing: 0.5px;
            border-radius: 6px;
            padding: 8px 16px;
            transition: all 0.2s;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 13px;
        }
        
        .btn-action {
            min-width: 40px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px;
        }
        
        .tabla-btn { 
            min-width: 120px;
            margin: 2px;
            border-radius: 20px;
            font-weight: 500;
        }
        
        .tabla-activa { 
            font-weight: bold;
            background-color: var(--color-primary);
            color: white !important;
        }
        
        /* Estilos para secciones de información */
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
        
        /* Estilos para JSON */
        .json-data {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            white-space: pre-wrap;
            border-left: 4px solid #20c997;
        }
        
        /* Estilos para filtros */
        .filtros-container {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .filtro-title {
            font-weight: 600;
            color: #495057;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .filtro-group {
            margin-bottom: 15px;
        }
        
        /* Estilos para mensajes */
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
        
        /* Estilos para paginación */
        .pagination .page-item.active .page-link {
            background-color: var(--color-primary);
            border-color: var(--color-primary);
        }
        
        .pagination .page-link {
            color: var(--color-primary);
        }
        
        /* Estilos para acordeón */
        .accordion-button:not(.collapsed) {
            background-color: #e7f5ff;
            color: #0c63e4;
        }
        
        /* Estilos para badges especiales */
        .badge-tratamiento {
            background-color: #e64980;
            color: white;
        }
        
        /* Estilos para la cabecera */
        .page-header {
            border-bottom: 2px solid #e9ecef;
            padding-bottom: 15px;
            margin-bottom: 30px;
        }
    </style>
</head>
<body class="bg-light">
    <div class="container">
        <!-- Mensajes de retroalimentación -->
        <?php if ($mensaje): ?>
            <div class="mensaje-alerta alert alert-<?= $tipo_mensaje ?> alert-dismissible fade show" role="alert">
                <i class="fas <?= $tipo_mensaje == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?> me-2"></i>
                <?= htmlspecialchars($mensaje) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="page-header">
            <h2 class="mb-0">
                <i class="fas fa-trash-restore me-2"></i>Sistema de Restauración
            </h2>
            <p class="text-muted">Gestión de registros eliminados en el sistema</p>
        </div>
        
        <!-- Filtros por tabla -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>Filtrar por tipo de registro
                </h5>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap">
                    <?php foreach ($tablas_permitidas as $tabla): ?>
                        <a href="?tabla=<?= $tabla ?>" 
                           class="btn btn-outline-primary tabla-btn <?= $tabla_filtro == $tabla ? 'tabla-activa' : '' ?>">
                            <i class="fas <?= 
                                $tabla == 'paciente' ? 'fa-user' : 
                                ($tabla == 'usuario' ? 'fa-users' : 
                                ($tabla == 'historia_medica' ? 'fa-file-medical' : 
                                ($tabla == 'odontograma' ? 'fa-tooth' : 'fa-calendar'))) ?> 
                            me-2"></i>
                            <?= ucfirst(str_replace('_', ' ', $tabla)) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Filtros avanzados -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-search me-2"></i>Filtros avanzados
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <input type="hidden" name="tabla" value="<?= $tabla_filtro ?>">
                    
                    <div class="col-md-3">
                        <label class="form-label">Usuario que eliminó</label>
                        <select name="usuario" class="form-select">
                            <option value="">Todos los usuarios</option>
                            <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?= $usuario['id'] ?>" <?= $filtro_usuario == $usuario['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($usuario['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Fecha desde</label>
                        <input type="date" name="desde" class="form-control" value="<?= $filtro_desde ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Fecha hasta</label>
                        <input type="date" name="hasta" class="form-control" value="<?= $filtro_hasta ?>">
                    </div>
                    
                    <div class="col-md-3">
                        <label class="form-label">Buscar en datos</label>
                        <div class="input-group">
                            <input type="text" name="busqueda" class="form-control" placeholder="Texto a buscar" value="<?= $filtro_busqueda ?>">
                            <button class="btn btn-primary" type="submit">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-filter me-1"></i> Aplicar filtros
                        </button>
                        <a href="?tabla=<?= $tabla_filtro ?>" class="btn btn-outline-secondary">
                            <i class="fas fa-undo me-1"></i> Limpiar filtros
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Listado de registros eliminados -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0">
                        <i class="fas fa-trash me-2"></i>
                        Registros eliminados: <?= ucfirst(str_replace('_', ' ', $tabla_filtro)) ?>
                    </h5>
                    <?php if ($filtro_usuario || $filtro_desde || $filtro_hasta || $filtro_busqueda): ?>
                        <small class="text-muted">Filtros aplicados</small>
                    <?php endif; ?>
                </div>
                <span class="badge bg-primary rounded-pill">
                    <i class="fas fa-database me-1"></i><?= count($registros_eliminados) ?> registros
                </span>
            </div>
            
            <div class="card-body">
                <?php if (empty($registros_eliminados)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>No hay registros eliminados para mostrar con los filtros actuales.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table tabla-citas">
                            <thead>
                                <tr>
                                    <th><i class="fas fa-id-card me-2"></i>ID</th>
                                    <th><i class="fas fa-user-minus me-2"></i>Eliminado por</th>
                                    <th><i class="fas fa-calendar-times me-2"></i>Fecha eliminación</th>
                                    <th><i class="fas fa-info-circle me-2"></i>Datos originales</th>
                                    <th class="text-end"><i class="fas fa-cogs me-2"></i>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($registros_eliminados as $registro): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-secondary">#<?= htmlspecialchars($registro['id_registro_afectado']) ?></span>
                                        </td>
                                        <td>
                                            <div class="d-flex align-items-center">
                                                <div class="me-2">
                                                    <i class="fas fa-user-circle fa-lg" style="color: #6c757d;"></i>
                                                </div>
                                                <div>
                                                    <strong><?= htmlspecialchars($registro['nombre_eliminador']) ?></strong>
                                                    <div class="text-muted small">
                                                        <?= date('d/m/Y H:i', strtotime($registro['fecha_eliminacion'])) ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?= date('d/m/Y', strtotime($registro['fecha_eliminacion'])) ?>
                                            <div class="text-muted small">
                                                <?= date('H:i', strtotime($registro['fecha_eliminacion'])) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-info" type="button" data-bs-toggle="collapse" 
                                                    data-bs-target="#data-<?= $registro['id'] ?>" 
                                                    aria-expanded="false" aria-controls="data-<?= $registro['id'] ?>">
                                                <i class="fas fa-eye me-1"></i>Ver datos
                                            </button>
                                            <div id="data-<?= $registro['id'] ?>" class="collapse mt-2">
                                                <div class="json-data">
                                                    <pre><?= format_json($registro['datos_originales']) ?></pre>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" class="d-inline-block">
                                                <input type="hidden" name="id" value="<?= $registro['id_registro_afectado'] ?>">
                                                <input type="hidden" name="tabla" value="<?= $registro['tabla_afectada'] ?>">
                                                <button type="submit" name="restaurar" class="btn btn-sm btn-success"
                                                        onclick="return confirm('¿Restaurar este registro? Esta acción no se puede deshacer.')"
                                                        title="Restaurar registro">
                                                    <i class="fas fa-trash-restore me-1"></i>Restaurar
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Activar tooltips de Bootstrap
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[title]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            // Ocultar mensaje de alerta después de 3 segundos
            setTimeout(() => {
                const alert = document.querySelector('.mensaje-alerta');
                if (alert) {
                    alert.style.display = 'none';
                }
            }, 3000);
            
            // Manejar la visualización de datos JSON
            document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(button => {
                button.addEventListener('click', function() {
                    const target = this.getAttribute('data-bs-target');
                    const icon = this.querySelector('i');
                    
                    if (document.querySelector(target).classList.contains('show')) {
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    } else {
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    }
                });
            });
        });
    </script>
</body>
</html>
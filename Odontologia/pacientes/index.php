<?php
$page_title = "Pacientes";
session_start();

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Usuario.php';
require_once __DIR__ . '/../includes/Paciente.php';
require_once __DIR__ . '/../includes/HistoriaMedica.php';
require_once __DIR__ . '/../includes/error_handler.php';

if (!isset($_SESSION['usuario']) || !is_array($_SESSION['usuario']) || !isset($_SESSION['usuario']['id'], $_SESSION['usuario']['rol_type'])) {
    $_SESSION['error'] = "La sesión ha expirado o es inválida. Por favor, inicia sesión nuevamente.";
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$usuario_actual = (object) $_SESSION['usuario'];
$database = new Database();
$db = $database->getConnection();

$errorHandler = new ErrorHandler($db);
$errorHandler->registerHandlers();

try {
    $stmt_acciones = $db->prepare("SELECT * FROM auth_acciones WHERE rol_type = :rol_type");
    $stmt_acciones->bindParam(':rol_type', $usuario_actual->rol_type, PDO::PARAM_STR);
    $stmt_acciones->execute();
    $permisos_acciones = $stmt_acciones->fetch(PDO::FETCH_ASSOC);
    
    $stmt_secciones = $db->prepare("SELECT * FROM auth_secciones WHERE rol_type = :rol_type");
    $stmt_secciones->bindParam(':rol_type', $usuario_actual->rol_type, PDO::PARAM_STR);
    $stmt_secciones->execute();
    $permisos_secciones = $stmt_secciones->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorHandler->logError('ERROR', "Error al obtener permisos: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $_SESSION['error'] = "Error al cargar los permisos del usuario";
    header("Location: /Odontologia/templates/error.php");
    exit;
}

if (!$permisos_secciones || !$permisos_secciones['auth_paciente']) {
    $errorHandler->logError('WARNING', "Intento de acceso no autorizado a pacientes por usuario ID: {$usuario_actual->id}");
    header("Location: /Odontologia/templates/unauthorized.php");
    exit;
}

$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$search = isset($_GET['search']) ? trim(strip_tags($_GET['search'])) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

$limit = 10;
$offset = ($page - 1) * $limit;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorHandler->logError('WARNING', "Intento de CSRF detectado por usuario ID: {$usuario_actual->id}");
        $_SESSION['error'] = "Token de seguridad inválido";
        header("Location: index.php?page=$page");
        exit;
    }

    if (!isset($_POST['action']) || !in_array($_POST['action'], ['crear', 'editar', 'eliminar', 'asignar'])) {
        $errorHandler->logError('WARNING', "Acción no válida recibida: " . ($_POST['action'] ?? 'null'));
        $_SESSION['error'] = "Acción no válida";
        header("Location: index.php?page=$page");
        exit;
    }

    $accionPermitida = false;
    $accion = $_POST['action'];
    
    switch($accion) {
        case 'crear':
            $accionPermitida = $permisos_acciones && $permisos_acciones['auth_crear'];
            break;
        case 'editar':
            $accionPermitida = $permisos_acciones && $permisos_acciones['auth_editar'];
            if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
                $errorHandler->logError('WARNING', "ID inválido para edición");
                $_SESSION['error'] = "ID de paciente inválido";
                header("Location: index.php?page=$page");
                exit;
            }
            break;
        case 'eliminar':
            $accionPermitida = $permisos_acciones && $permisos_acciones['auth_eliminar'];
            if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
                $errorHandler->logError('WARNING', "ID inválido para eliminación");
                $_SESSION['error'] = "ID de paciente inválido";
                header("Location: index.php?page=$page");
                exit;
            }
            break;
        case 'asignar':
            $accionPermitida = $permisos_acciones && $permisos_acciones['auth_editar'];
            if (!isset($_POST['paciente_id']) || !is_numeric($_POST['paciente_id'])) {
                $errorHandler->logError('WARNING', "ID de paciente inválido para asignación");
                $_SESSION['error'] = "ID de paciente inválido";
                header("Location: index.php?page=$page");
                exit;
            }
            break;
    }
    
    if (!$accionPermitida) {
        $errorHandler->logError('WARNING', "Intento de acción no autorizada ($accion) por usuario ID: {$usuario_actual->id}");
        $_SESSION['error'] = "No tienes permiso para realizar esta acción";
        header("Location: index.php?page=$page");
        exit;
    }

    if ($accion === 'crear' || $accion === 'editar') {
        $cedula = isset($_POST['cedula']) ? trim(strip_tags($_POST['cedula'])) : '';
        $telefono = isset($_POST['telefono']) ? trim(strip_tags($_POST['telefono'])) : '';
        $nombre = isset($_POST['nombre']) ? trim(strip_tags($_POST['nombre'])) : '';
        $edad = filter_input(INPUT_POST, 'edad', FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 120
            ]
        ]);
        $sexo = isset($_POST['sexo']) && in_array($_POST['sexo'], ['M', 'F']) ? $_POST['sexo'] : null;
        $estado_civil = isset($_POST['estado_civil']) && in_array($_POST['estado_civil'], ['0', '1']) ? (int)$_POST['estado_civil'] : null;
        $ocupacion = isset($_POST['ocupacion']) ? trim(strip_tags($_POST['ocupacion'])) : '';
        
        $campos_requeridos = [
            'cedula' => $cedula,
            'nombre' => $nombre,
            'edad' => $edad,
            'telefono' => $telefono,
            'sexo' => $sexo,
            'estado_civil' => $estado_civil,
            'ocupacion' => $ocupacion
        ];
        
        foreach ($campos_requeridos as $campo => $valor) {
            if (empty($valor)) {
                $errorHandler->logError('WARNING', "Campo requerido faltante: $campo");
                $_SESSION['error'] = "El campo " . ucfirst(str_replace('_', ' ', $campo)) . " es requerido";
                header("Location: index.php?page=$page");
                exit;
            }
        }
        
        if (!preg_match('/^[0-9]{6,8}$/', $cedula)) {
            $errorHandler->logError('WARNING', "Validación fallida: Cédula inválida ($cedula)");
            $_SESSION['error'] = "La cédula debe tener entre 6 y 8 dígitos numéricos";
            header("Location: index.php?page=$page");
            exit;
        }
        
        if (!preg_match('/^[0-9]{10,11}$/', $telefono)) {
            $errorHandler->logError('WARNING', "Validación fallida: Teléfono inválido ($telefono)");
            $_SESSION['error'] = "El teléfono debe tener entre 10 y 11 dígitos numéricos";
            header("Location: index.php?page=$page");
            exit;
        }

        if (!preg_match('/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]{2,100}$/', $nombre)) {
            $errorHandler->logError('WARNING', "Validación fallida: Nombre inválido ($nombre)");
            $_SESSION['error'] = "El nombre solo puede contener letras y espacios (2-100 caracteres)";
            header("Location: index.php?page=$page");
            exit;
        }

        if (strlen($ocupacion) > 100) {
            $errorHandler->logError('WARNING', "Validación fallida: Ocupación demasiado larga");
            $_SESSION['error'] = "La ocupación no puede exceder los 100 caracteres";
            header("Location: index.php?page=$page");
            exit;
        }
    }

    if ($accion === 'crear') {
        try {
            $stmt_cedula = $db->prepare("SELECT id FROM paciente WHERE cedula = :cedula AND Estado_Sistema = 'Activo'");
            $stmt_cedula->bindParam(':cedula', $cedula, PDO::PARAM_STR);
            $stmt_cedula->execute();
            
            if ($stmt_cedula->fetch()) {
                $errorHandler->logError('WARNING', "Intento de crear paciente con cédula existente: $cedula");
                $_SESSION['error'] = "Ya existe un paciente con esta cédula";
                header("Location: index.php?page=$page");
                exit;
            }

            $paciente = new Paciente();
            $paciente->setCedula($cedula);
            $paciente->setNombre($nombre);
            $paciente->setEdad($edad);
            $paciente->setTelefono($telefono);
            $paciente->setSexo($sexo);
            $paciente->setEstadoCivil($estado_civil);
            $paciente->setOcupacion($ocupacion);
            
            if ($paciente->savep($db)) {
                $errorHandler->logError('INFO', "Paciente creado: {$nombre} (ID: {$paciente->getId()}, Cédula: {$cedula})");
                $_SESSION['success'] = "Paciente " . htmlspecialchars($nombre) . " creado exitosamente";
            } else {
                $errorHandler->logError('ERROR', "Error al crear paciente: {$nombre}");
                $_SESSION['error'] = "Error al crear el paciente";
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al crear paciente: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al crear el paciente";
        }
    }

    if ($accion === 'editar') {
        $id = (int)$_POST['id'];
        try {
            $paciente = Paciente::find($db, $id);
            if (!$paciente) {
                $errorHandler->logError('WARNING', "Intento de editar paciente no encontrado ID: $id");
                $_SESSION['error'] = "Paciente no encontrado";
                header("Location: index.php?page=$page");
                exit;
            }

            if ($paciente->getCedula() !== $cedula) {
                $stmt_cedula = $db->prepare("SELECT id FROM paciente WHERE cedula = :cedula AND id != :id AND Estado_Sistema = 'Activo'");
                $stmt_cedula->bindParam(':cedula', $cedula, PDO::PARAM_STR);
                $stmt_cedula->bindParam(':id', $id, PDO::PARAM_INT);
                $stmt_cedula->execute();
                
                if ($stmt_cedula->fetch()) {
                    $errorHandler->logError('WARNING', "Intento de cambiar cédula a una existente: $cedula (Paciente ID: $id)");
                    $_SESSION['error'] = "Ya existe otro paciente con esta cédula";
                    header("Location: index.php?page=$page");
                    exit;
                }
            }

            $paciente->setCedula($cedula);
            $paciente->setNombre($nombre);
            $paciente->setEdad($edad);
            $paciente->setTelefono($telefono);
            $paciente->setSexo($sexo);
            $paciente->setEstadoCivil($estado_civil);
            $paciente->setOcupacion($ocupacion);
            
            if ($paciente->savep($db)) {
                $errorHandler->logError('INFO', "Paciente actualizado: {$nombre} (ID: {$paciente->getId()}, Cédula: {$cedula})");
                $_SESSION['success'] = "Paciente " . htmlspecialchars($nombre) . " actualizado exitosamente";
            } else {
                $errorHandler->logError('ERROR', "Error al actualizar paciente: {$nombre} (ID: {$paciente->getId()})");
                $_SESSION['error'] = "Error al actualizar el paciente";
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al actualizar paciente: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al actualizar el paciente";
        }
    }

    if ($accion === 'eliminar') {
        $id = (int)$_POST['id'];
        try {
            $paciente = Paciente::find($db, $id);
            if (!$paciente) {
                $errorHandler->logError('WARNING', "Intento de eliminar paciente no encontrado ID: $id");
                $_SESSION['error'] = "Paciente no encontrado";
                header("Location: index.php?page=$page");
                exit;
            }

            $nombre_paciente = $paciente->getNombre();
            
            $stmt_historias = $db->prepare("SELECT COUNT(*) FROM historia_medica WHERE paciente_id = :id AND Estado_Sistema = 'Activo'");
            $stmt_historias->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt_historias->execute();
            $tiene_historias = $stmt_historias->fetchColumn() > 0;

            if ($tiene_historias) {
                $errorHandler->logError('WARNING', "Intento de eliminar paciente con historias médicas ID: $id");
                $_SESSION['error'] = "No se puede eliminar el paciente porque tiene historias médicas asociadas";
                header("Location: index.php?page=$page");
                exit;
            }

            if ($paciente->delete($db, $id)) {
                $datos_originales = json_encode([
                    'cedula' => $paciente->getCedula(),
                    'nombre' => $paciente->getNombre(),
                    'edad' => $paciente->getEdad(),
                    'telefono' => $paciente->getTelefono(),
                    'sexo' => $paciente->getSexo(),
                    'estado_civil' => $paciente->getEstadoCivil(),
                    'ocupacion' => $paciente->getOcupacion()
                ], JSON_UNESCAPED_UNICODE);
                
                try {
                    $auditoria_sql = "INSERT INTO auditoria_eliminaciones 
                                    (tabla_afectada, id_registro_afectado, usuario_eliminador_id, nombre_usuario_eliminador, datos_originales) 
                                    VALUES 
                                    ('Paciente', :id, :user_id, :user_name, :datos)";
                    $auditoria_stmt = $db->prepare($auditoria_sql);
                    $auditoria_stmt->execute([
                        ':id' => $id,
                        ':user_id' => $usuario_actual->id,
                        ':user_name' => $usuario_actual->nombre,
                        ':datos' => $datos_originales
                    ]);
                } catch (Exception $e) {
                    $errorHandler->logError('ERROR', "Error al registrar auditoría de eliminación: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
                }
                
                $errorHandler->logError('INFO', "Paciente eliminado: {$nombre_paciente} (ID: {$id})");
                $_SESSION['success'] = "Paciente " . htmlspecialchars($nombre_paciente) . "eliminado exitosamente";
            } else {
                $errorHandler->logError('ERROR', "Error al eliminar paciente: {$nombre_paciente} (ID: {$id})");
                $_SESSION['error'] = "Error al eliminar el paciente";
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al eliminar paciente: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al eliminar el paciente";
        }
    }

    if ($accion === 'asignar') {
        $paciente_id = (int)$_POST['paciente_id'];
        $usuario_id = isset($_POST['usuario_id']) && $_POST['usuario_id'] !== '' ? (int)$_POST['usuario_id'] : null;
        
        try {
            $paciente = Paciente::find($db, $paciente_id);
            if (!$paciente) {
                $errorHandler->logError('WARNING', "Intento de asignar usuario a paciente no encontrado ID: $paciente_id");
                $_SESSION['error'] = "Paciente no encontrado";
                header("Location: index.php?page=$page");
                exit;
            }
            
            // Primero desasignar el paciente actual de cualquier usuario
            $db->prepare("UPDATE usuario SET paciente_id = NULL WHERE paciente_id = :paciente_id")
               ->execute([':paciente_id' => $paciente_id]);
            
            if ($usuario_id !== null) {
                // Verificar que el usuario no tenga otro paciente asignado
                $stmt_existente = $db->prepare("SELECT id FROM usuario WHERE id = :id AND paciente_id IS NOT NULL");
                $stmt_existente->bindParam(':id', $usuario_id, PDO::PARAM_INT);
                $stmt_existente->execute();
                
                if ($stmt_existente->fetch()) {
                    $errorHandler->logError('WARNING', "Intento de asignar paciente a usuario que ya tiene uno asignado ID: $usuario_id");
                    $_SESSION['error'] = "Este usuario ya tiene un paciente asignado";
                    header("Location: index.php?page=$page");
                    exit;
                }
                
                // Asignar el paciente al usuario
                $stmt_asignar = $db->prepare("UPDATE usuario SET paciente_id = :paciente_id WHERE id = :usuario_id");
                $stmt_asignar->bindParam(':paciente_id', $paciente_id, PDO::PARAM_INT);
                $stmt_asignar->bindParam(':usuario_id', $usuario_id, PDO::PARAM_INT);
                $stmt_asignar->execute();
                
                $errorHandler->logError('INFO', "Paciente ID: $paciente_id asignado a usuario ID: $usuario_id");
                $_SESSION['success'] = "Paciente asignado correctamente";
            } else {
                $errorHandler->logError('INFO', "Paciente ID: $paciente_id desasignado de cualquier usuario");
                $_SESSION['success'] = "Paciente desasignado correctamente";
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al asignar paciente: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al asignar el paciente";
        }
    }

    $redirect_url = "index.php?page=$page" . ($search ? "&search=" . urlencode($search) : "") . ($filter !== 'all' ? "&filter=$filter" : "");
    header("Location: $redirect_url");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

try {
    $search_param = "%$search%";
    
    $count_sql = "SELECT COUNT(*) FROM paciente p WHERE p.Estado_Sistema = 'Activo'";
    $sql = "SELECT p.*, u.id as usuario_asignado_id, u.nombre as usuario_asignado_nombre 
            FROM paciente p 
            LEFT JOIN usuario u ON p.id = u.paciente_id 
            WHERE p.Estado_Sistema = 'Activo'";
    
    if ($search !== '') {
        $count_sql .= " AND (p.nombre LIKE :search OR p.cedula LIKE :search)";
        $sql .= " AND (p.nombre LIKE :search OR p.cedula LIKE :search)";
    }
    
    if ($filter === 'assigned') {
        $count_sql .= " AND EXISTS (SELECT 1 FROM usuario WHERE paciente_id = p.id)";
        $sql .= " AND u.paciente_id IS NOT NULL";
    } elseif ($filter === 'unassigned') {
        $count_sql .= " AND NOT EXISTS (SELECT 1 FROM usuario WHERE paciente_id = p.id)";
        $sql .= " AND u.paciente_id IS NULL";
    }
    
    $sql .= " ORDER BY p.nombre LIMIT :limit OFFSET :offset";
    
    $count_stmt = $db->prepare($count_sql);
    if ($search !== '') {
        $count_stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
    }
    $count_stmt->execute();
    $total = $count_stmt->fetchColumn();
    $total_pages = ceil($total / $limit);
    
    $stmt = $db->prepare($sql);
    if ($search !== '') {
        $stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $pacientes_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pacientes = [];
    
    foreach ($pacientes_data as $data) {
        $paciente = new Paciente();
        $paciente->setId($data['id']);
        $paciente->setCedula($data['cedula']);
        $paciente->setNombre($data['nombre']);
        $paciente->setEdad($data['edad']);
        $paciente->setTelefono($data['telefono']);
        $paciente->setSexo($data['sexo']);
        $paciente->setEstadoCivil($data['estado_civil']);
        $paciente->setOcupacion($data['ocupacion']);
        
        $paciente->usuario_asignado_id = $data['usuario_asignado_id'] ?? null;
        $paciente->usuario_asignado_nombre = $data['usuario_asignado_nombre'] ?? null;
        
        $pacientes[] = $paciente;
    }
} catch (Exception $e) {
    $errorHandler->logError('ERROR', "Error al obtener pacientes: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $pacientes = [];
    $total = 0;
    $total_pages = 1;
    $_SESSION['error'] = "Error al cargar la lista de pacientes";
}

include '../templates/navbar.php';
?>

<style>
.is-invalid {
    border-color: #dc3545 !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
    animation: shake 0.5s ease-in-out;
}

.invalid-feedback {
    display: none;
    width: 100%;
    margin-top: 0.25rem;
    font-size: 0.875em;
    color: #dc3545;
}

.is-invalid ~ .invalid-feedback {
    display: block;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    20%, 60% { transform: translateX(-5px); }
    40%, 80% { transform: translateX(5px); }
}

.alert-success strong {
    font-weight: bold;
    color: #0f5132;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border-width: 0;
}

.table-responsive {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.pagination .page-item.active .page-link {
    background-color: #0d6efd;
    border-color: #0d6efd;
}

.modal {
    backdrop-filter: blur(5px);
    
}

.form-control:valid {
    border-color: #198754;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.form-control:invalid {
    border-color: #dc3545;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.badge {
    font-size: 0.85em;
    padding: 0.35em 0.65em;
    font-weight: 500;
}

.dropdown-menu {
    max-height: 300px;
    overflow-y: auto;
}

.table th {
    white-space: nowrap;
    vertical-align: middle;
}

.table td {
    vertical-align: middle;
}

.btn-group .btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

.modal-asignar .form-select {
    width: 100%;
}
</style>

<div class="container">
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
            <?= htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
            <?= htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-4 mt-3">
        <h1>Listado de Pacientes</h1>
        <?php if ($permisos_acciones && $permisos_acciones['auth_crear']): ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCrear">
                <i class="bi bi-plus-circle"></i> Nuevo Paciente
            </button>
        <?php endif; ?>
    </div>

    <div class="row mb-4">
        <div class="col-md-8">
            <form method="GET" class="mb-3">
                <div class="input-group">
                    <input type="text" name="search" class="form-control" placeholder="Buscar por nombre o cédula..." 
                           value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" 
                           aria-label="Buscar pacientes" 
                           maxlength="100">
                    <button class="btn btn-outline-primary" type="submit">Buscar</button>
                    <?php if ($search || $filter !== 'all'): ?>
                        <a href="index.php" class="btn btn-outline-secondary">Limpiar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <div class="col-md-4">
            <div class="dropdown">
                <button class="btn btn-outline-secondary dropdown-toggle w-100" type="button" id="filterDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                    <?php 
                        echo match($filter) {
                            'assigned' => 'Pacientes Asignados',
                            'unassigned' => 'Pacientes No Asignados',
                            default => 'Todos los Pacientes'
                        };
                    ?>
                </button>
                <ul class="dropdown-menu w-100" aria-labelledby="filterDropdown">
                    <li><a class="dropdown-item <?= $filter === 'all' ? 'active' : '' ?>" href="?filter=all<?= $search ? '&search='.urlencode($search) : '' ?>">Todos los Pacientes</a></li>
                    <li><a class="dropdown-item <?= $filter === 'assigned' ? 'active' : '' ?>" href="?filter=assigned<?= $search ? '&search='.urlencode($search) : '' ?>">Pacientes Asignados</a></li>
                    <li><a class="dropdown-item <?= $filter === 'unassigned' ? 'active' : '' ?>" href="?filter=unassigned<?= $search ? '&search='.urlencode($search) : '' ?>">Pacientes No Asignados</a></li>
                </ul>
            </div>
        </div>
    </div>

    <?php if (!empty($pacientes)): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table-striped">
                    <tr>
                        <?php if ($usuario_actual->rol_type !== 'U'): ?>
                            <th scope="col">Cédula</th>
                        <?php endif; ?>
                        <th scope="col">Nombre</th>
                        <th scope="col">Edad</th>
                        <?php if ($usuario_actual->rol_type !== 'U'): ?>
                            <th scope="col">Teléfono</th>
                        <?php endif; ?>
                        <th scope="col">Sexo</th>
                        <th scope="col">Estado Civil</th>
                        <?php if ($usuario_actual->rol_type !== 'U'): ?>
                            <th scope="col">Ocupación</th>
                            <th scope="col">Asignado a</th>
                        <?php endif; ?>
                        <?php if ($permisos_acciones && ($permisos_acciones['auth_ver'] || $permisos_acciones['auth_editar'] || $permisos_acciones['auth_eliminar'])): ?>
                            <th scope="col">Acciones</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pacientes as $paciente): ?>
                        <tr>
                            <?php if ($usuario_actual->rol_type !== 'U'): ?>
                                <td><?= htmlspecialchars($paciente->getCedula(), ENT_QUOTES, 'UTF-8') ?></td>
                            <?php endif; ?>
                            <td><?= htmlspecialchars($paciente->getNombre(), ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($paciente->getEdad(), ENT_QUOTES, 'UTF-8') ?></td>
                            <?php if ($usuario_actual->rol_type !== 'U'): ?>
                                <td><?= htmlspecialchars($paciente->getTelefono(), ENT_QUOTES, 'UTF-8') ?></td>
                            <?php endif; ?>
                            <td><?= $paciente->getSexo() === 'M' ? 'Masculino' : 'Femenino' ?></td>
                            <td><?= $paciente->getEstadoCivil() ? 'Casado/a' : 'Soltero/a' ?></td>
                            <?php if ($usuario_actual->rol_type !== 'U'): ?>
                                <td><?= htmlspecialchars($paciente->getOcupacion(), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($paciente->usuario_asignado_id): ?>
                                        <span class="badge bg-success">Asignado a <?= htmlspecialchars($paciente->usuario_asignado_nombre, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">No asignado</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <?php if ($permisos_acciones && ($permisos_acciones['auth_ver'] || $permisos_acciones['auth_editar'] || $permisos_acciones['auth_eliminar'])): ?>
                                <td>
                                    <div class="btn-group" role="group" aria-label="Acciones del paciente">
                                        <?php if ($permisos_acciones['auth_ver'] && $usuario_actual->rol_type !== 'U'): ?>
                                            <button class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#modalVer<?= $paciente->getId() ?>">
                                                <i class="bi bi-eye"></i> Ver
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($permisos_acciones['auth_editar']): ?>
                                            <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalEditar<?= $paciente->getId() ?>">
                                                <i class="bi bi-pencil"></i> Editar
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($permisos_acciones['auth_eliminar']): ?>
                                            <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalEliminar<?= $paciente->getId() ?>">
                                                <i class="bi bi-trash"></i> Eliminar
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($permisos_acciones['auth_editar'] && $usuario_actual->rol_type !== 'U'): ?>
                                            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAsignar<?= $paciente->getId() ?>">
                                                <i class="bi bi-person-plus"></i> Asignar
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <nav aria-label="Paginación de pacientes">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter !== 'all' ? '&filter=' . $filter : '' ?>" aria-label="Anterior">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter !== 'all' ? '&filter=' . $filter : '' ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter !== 'all' ? '&filter=' . $filter : '' ?>" aria-label="Siguiente">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
    <?php else: ?>
        <div class="alert alert-info">No hay pacientes registrados<?= $search ? ' que coincidan con la búsqueda' : '' ?>.</div>
    <?php endif; ?>
</div>

<?php if ($permisos_acciones && $permisos_acciones['auth_crear']): ?>
<div class="modal fade" id="modalCrear" tabindex="-1" aria-labelledby="modalCrearLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content animate__animated animate__fadeInDown">
      <form method="POST" action="" novalidate>
        <input type="hidden" name="action" value="crear">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="modalCrearLabel">Nuevo Paciente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="cedulaCrear" class="form-label">Cédula</label>
            <input type="text" name="cedula" id="cedulaCrear" class="form-control" 
                   maxlength="8" 
                   pattern="[0-9]{6,8}" 
                   title="La cédula debe tener entre 6 y 8 dígitos numéricos" 
                   required>
            <div class="invalid-feedback">La cédula debe tener entre 6 y 8 dígitos numéricos</div>
          </div>
          <div class="mb-3">
            <label for="nombreCrear" class="form-label">Nombre</label>
            <input type="text" name="nombre" id="nombreCrear" class="form-control" 
                   pattern="[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]{2,100}" 
                   title="Solo se permiten letras y espacios (2-100 caracteres)" 
                   required>
            <div class="invalid-feedback">Solo se permiten letras y espacios (2-100 caracteres)</div>
          </div>
          <div class="mb-3">
            <label for="edadCrear" class="form-label">Edad</label>
            <input type="number" name="edad" id="edadCrear" class="form-control" 
                   min="1" max="120" 
                   title="La edad debe estar entre 1 y 120 años" 
                   required>
            <div class="invalid-feedback">La edad debe estar entre 1 y 120 años</div>
          </div>
          <div class="mb-3">
            <label for="telefonoCrear" class="form-label">Teléfono</label>
            <input type="text" name="telefono" id="telefonoCrear" class="form-control" 
                   maxlength="11" 
                   pattern="[0-9]{10,11}" 
                   title="El teléfono debe tener entre 10 y 11 dígitos numéricos" 
                   required>
            <div class="invalid-feedback">El teléfono debe tener entre 10 y 11 dígitos</div>
          </div>
          <div class="mb-3">
            <label for="ocupacionCrear" class="form-label">Ocupación</label>
            <input type="text" name="ocupacion" id="ocupacionCrear" class="form-control" 
                   maxlength="100" 
                   required>
            <div class="invalid-feedback">La ocupación es requerida (máximo 100 caracteres)</div>
          </div>
          <div class="mb-3">
            <label for="sexoCrear" class="form-label">Sexo</label>
            <select name="sexo" id="sexoCrear" class="form-select" required>
              <option value="" selected disabled>Seleccione...</option>
              <option value="M">Masculino</option>
              <option value="F">Femenino</option>
            </select>
            <div class="invalid-feedback">Por favor seleccione un sexo</div>
          </div>
          <div class="mb-3">
            <label for="estadoCivilCrear" class="form-label">Estado Civil</label>
            <select name="estado_civil" id="estadoCivilCrear" class="form-select" required>
              <option value="" selected disabled>Seleccione...</option>
              <option value="1">Casado/a</option>
              <option value="0">Soltero/a</option>
            </select>
            <div class="invalid-feedback">Por favor seleccione un estado civil</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success">Crear Paciente</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php foreach ($pacientes as $paciente): ?>
<?php if ($permisos_acciones && $permisos_acciones['auth_ver'] && $usuario_actual->rol_type !== 'U'): ?>
<div class="modal fade" id="modalVer<?= $paciente->getId() ?>" tabindex="-1" aria-labelledby="modalVerLabel<?= $paciente->getId() ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content animate__animated animate__fadeInDown">
      <div class="modal-header bg-info text-white">
        <h5 class="modal-title" id="modalVerLabel<?= $paciente->getId() ?>">Detalles del Paciente</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <dl class="row">
          <dt class="col-sm-4">Cédula:</dt>
          <dd class="col-sm-8"><?= htmlspecialchars($paciente->getCedula(), ENT_QUOTES, 'UTF-8') ?></dd>
          
          <dt class="col-sm-4">Nombre:</dt>
          <dd class="col-sm-8"><?= htmlspecialchars($paciente->getNombre(), ENT_QUOTES, 'UTF-8') ?></dd>
          
          <dt class="col-sm-4">Edad:</dt>
          <dd class="col-sm-8"><?= htmlspecialchars($paciente->getEdad(), ENT_QUOTES, 'UTF-8') ?></dd>
          
          <dt class="col-sm-4">Teléfono:</dt>
          <dd class="col-sm-8"><?= htmlspecialchars($paciente->getTelefono(), ENT_QUOTES, 'UTF-8') ?></dd>
          
          <dt class="col-sm-4">Sexo:</dt>
          <dd class="col-sm-8"><?= $paciente->getSexo() === 'M' ? 'Masculino' : 'Femenino' ?></dd>
          
          <dt class="col-sm-4">Estado Civil:</dt>
          <dd class="col-sm-8"><?= $paciente->getEstadoCivil() ? 'Casado/a' : 'Soltero/a' ?></dd>
          
          <dt class="col-sm-4">Ocupación:</dt>
          <dd class="col-sm-8"><?= htmlspecialchars($paciente->getOcupacion(), ENT_QUOTES, 'UTF-8') ?></dd>
          
          <dt class="col-sm-4">Asignado a:</dt>
          <dd class="col-sm-8"><?= $paciente->usuario_asignado_nombre ? htmlspecialchars($paciente->usuario_asignado_nombre, ENT_QUOTES, 'UTF-8') : 'Ningún usuario' ?></dd>
        </dl>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($permisos_acciones && $permisos_acciones['auth_editar']): ?>
<div class="modal fade" id="modalEditar<?= $paciente->getId() ?>" tabindex="-1" aria-labelledby="modalEditarLabel<?= $paciente->getId() ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content animate__animated animate__fadeInDown">
      <form action="" method="POST" novalidate>
        <input type="hidden" name="action" value="editar">
        <input type="hidden" name="id" value="<?= $paciente->getId() ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title" id="modalEditarLabel<?= $paciente->getId() ?>">Editar Paciente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="cedulaEditar<?= $paciente->getId() ?>" class="form-label">Cédula</label>
            <input type="text" name="cedula" id="cedulaEditar<?= $paciente->getId() ?>" class="form-control" 
                   value="<?= htmlspecialchars($paciente->getCedula(), ENT_QUOTES, 'UTF-8') ?>" 
                   maxlength="8" 
                   pattern="[0-9]{6,8}" 
                   title="La cédula debe tener entre 6 y 8 dígitos numéricos" 
                   required>
            <div class="invalid-feedback">La cédula debe tener entre 6 y 8 dígitos numéricos</div>
          </div>
          <div class="mb-3">
            <label for="nombreEditar<?= $paciente->getId() ?>" class="form-label">Nombre</label>
            <input type="text" name="nombre" id="nombreEditar<?= $paciente->getId() ?>" class="form-control" 
                   value="<?= htmlspecialchars($paciente->getNombre(), ENT_QUOTES, 'UTF-8') ?>" 
                   pattern="[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]{2,100}" 
                   title="Solo se permiten letras y espacios (2-100 caracteres)" 
                   required>
            <div class="invalid-feedback">Solo se permiten letras y espacios (2-100 caracteres)</div>
          </div>
          <div class="mb-3">
            <label for="edadEditar<?= $paciente->getId() ?>" class="form-label">Edad</label>
            <input type="number" name="edad" id="edadEditar<?= $paciente->getId() ?>" class="form-control" 
                   value="<?= $paciente->getEdad() ?>" 
                   min="1" max="120" 
                   title="La edad debe estar entre 1 y 120 años" 
                   required>
            <div class="invalid-feedback">La edad debe estar entre 1 y 120 años</div>
          </div>
          <div class="mb-3">
            <label for="telefonoEditar<?= $paciente->getId() ?>" class="form-label">Teléfono</label>
            <input type="text" name="telefono" id="telefonoEditar<?= $paciente->getId() ?>" class="form-control" 
                   value="<?= htmlspecialchars($paciente->getTelefono(), ENT_QUOTES, 'UTF-8') ?>" 
                   maxlength="11" 
                   pattern="[0-9]{10,11}" 
                   title="El teléfono debe tener entre 10 y 11 dígitos numéricos" 
                   required>
            <div class="invalid-feedback">El teléfono debe tener entre 10 y 11 dígitos</div>
          </div>
          <div class="mb-3">
            <label for="sexoEditar<?= $paciente->getId() ?>" class="form-label">Sexo</label>
            <select name="sexo" id="sexoEditar<?= $paciente->getId() ?>" class="form-select" required>
              <option value="M" <?= $paciente->getSexo() === 'M' ? 'selected' : '' ?>>Masculino</option>
              <option value="F" <?= $paciente->getSexo() === 'F' ? 'selected' : '' ?>>Femenino</option>
            </select>
            <div class="invalid-feedback">Por favor seleccione un sexo</div>
          </div>
          <div class="mb-3">
            <label for="estadoCivilEditar<?= $paciente->getId() ?>" class="form-label">Estado Civil</label>
            <select name="estado_civil" id="estadoCivilEditar<?= $paciente->getId() ?>" class="form-select" required>
              <option value="1" <?= $paciente->getEstadoCivil() ? 'selected' : '' ?>>Casado/a</option>
              <option value="0" <?= !$paciente->getEstadoCivil() ? 'selected' : '' ?>>Soltero/a</option>
            </select>
            <div class="invalid-feedback">Por favor seleccione un estado civil</div>
          </div>
          <div class="mb-3">
            <label for="ocupacionEditar<?= $paciente->getId() ?>" class="form-label">Ocupación</label>
            <input type="text" name="ocupacion" id="ocupacionEditar<?= $paciente->getId() ?>" class="form-control" 
                   value="<?= htmlspecialchars($paciente->getOcupacion(), ENT_QUOTES, 'UTF-8') ?>" 
                   maxlength="100" 
                   required>
            <div class="invalid-feedback">La ocupación es requerida (máximo 100 caracteres)</div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar Cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($permisos_acciones && $permisos_acciones['auth_eliminar']): ?>
<div class="modal fade" id="modalEliminar<?= $paciente->getId() ?>" tabindex="-1" aria-labelledby="modalEliminarLabel<?= $paciente->getId() ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content animate__animated animate__zoomIn">
      <form action="" method="POST">
        <input type="hidden" name="action" value="eliminar">
        <input type="hidden" name="id" value="<?= $paciente->getId() ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title" id="modalEliminarLabel<?= $paciente->getId() ?>">Confirmar Eliminación</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p>¿Estás seguro de que deseas eliminar al paciente <strong><?= htmlspecialchars($paciente->getNombre(), ENT_QUOTES, 'UTF-8') ?></strong>?</p>
          <p class="text-danger"><strong>Esta acción no se puede deshacer.</strong></p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-danger">Eliminar</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($permisos_acciones && $permisos_acciones['auth_editar'] && $usuario_actual->rol_type !== 'U'): ?>
<div class="modal fade" id="modalAsignar<?= $paciente->getId() ?>" tabindex="-1" aria-labelledby="modalAsignarLabel<?= $paciente->getId() ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="">
        <input type="hidden" name="action" value="asignar">
        <input type="hidden" name="paciente_id" value="<?= $paciente->getId() ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="modalAsignarLabel<?= $paciente->getId() ?>">Asignar Paciente</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="usuarioAsignar<?= $paciente->getId() ?>" class="form-label">Seleccionar Usuario</label>
            <select name="usuario_id" id="usuarioAsignar<?= $paciente->getId() ?>" class="form-select">
              <option value="">-- Ninguno (desasignar) --</option>
              <?php
                try {
                    // Consulta modificada para excluir usuarios con pacientes asignados, excepto el actual
                    $sql = "SELECT u.id, u.nombre 
                            FROM usuario u 
                            WHERE u.Estado_Sistema = 'Activo' 
                            AND (u.paciente_id IS NULL OR u.paciente_id = :paciente_id)
                            ORDER BY u.nombre";
                    
                    $stmt_usuarios = $db->prepare($sql);
                    $paciente_id = $paciente->getId();
                    $stmt_usuarios->bindParam(':paciente_id', $paciente_id, PDO::PARAM_INT);
                    $stmt_usuarios->execute();
                    $usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($usuarios as $usuario) {
                        $selected = ($paciente->usuario_asignado_id == $usuario['id']) ? 'selected' : '';
                        echo "<option value='{$usuario['id']}' $selected>{$usuario['nombre']}</option>";
                    }
                } catch (Exception $e) {
                    $errorHandler->logError('ERROR', "Error al cargar usuarios: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
                }
              ?>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary">Guardar Asignación</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const setupValidation = (form) => {
        const cedulaInput = form.querySelector('input[name="cedula"]');
        if (cedulaInput) {
            cedulaInput.addEventListener('blur', function() {
                validarCedula(this);
            });
            cedulaInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }

        const nombreInput = form.querySelector('input[name="nombre"]');
        if (nombreInput) {
            nombreInput.addEventListener('blur', function() {
                validarNombre(this);
            });
            nombreInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }

        const edadInput = form.querySelector('input[name="edad"]');
        if (edadInput){
            edadInput.addEventListener('blur', function() {
                validarEdad(this);
            });
            edadInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }

        const telefonoInput = form.querySelector('input[name="telefono"]');
        if (telefonoInput) {
            telefonoInput.addEventListener('blur', function() {
                validarTelefono(this);
            });
            telefonoInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }

        const ocupacionInput = form.querySelector('input[name="ocupacion"]');
        if (ocupacionInput) {
            ocupacionInput.addEventListener('blur', function() {
                validarOcupacion(this);
            });
            ocupacionInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }

        form.querySelectorAll('select[required]').forEach(select => {
            select.addEventListener('change', function() {
                if (this.value) {
                    this.classList.remove('is-invalid');
                }
            });
        });
    };

    document.querySelectorAll('form').forEach(form => {
        if (!form.querySelector('input[name="action"][value="eliminar"]')) {
            setupValidation(form);
            
            form.addEventListener('submit', function(e) {
                let isValid = true;
                
                const campos = [
                    {name: 'cedula', validator: validarCedula},
                    {name: 'nombre', validator: validarNombre},
                    {name: 'edad', validator: validarEdad},
                    {name: 'telefono', validator: validarTelefono},
                    {name: 'ocupacion', validator: validarOcupacion}
                ];
                
                campos.forEach(campo => {
                    const input = form.querySelector(`input[name="${campo.name}"]`);
                    if (input) {
                        campo.validator(input);
                        if (input.classList.contains('is-invalid')) {
                            isValid = false;
                        }
                    }
                });
                
                form.querySelectorAll('select[required]').forEach(select => {
                    if (!select.value) {
                        select.classList.add('is-invalid');
                        isValid = false;
                    }
                });
                
                if (!isValid) {
                    e.preventDefault();
                    const firstInvalid = form.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                        firstInvalid.style.animation = 'none';
                        setTimeout(() => {
                            firstInvalid.style.animation = 'shake 0.5s ease-in-out';
                        }, 10);
                    }
                    
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'alert alert-danger alert-dismissible fade show mt-3';
                    alertDiv.setAttribute('role', 'alert');
                    alertDiv.innerHTML = `
                        Error: Por favor complete todos los campos requeridos correctamente.
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                    `;
                    
                    const existingAlert = form.querySelector('.alert');
                    if (existingAlert) {
                        existingAlert.replaceWith(alertDiv);
                    } else {
                        form.prepend(alertDiv);
                    }
                }
            });
        }
    });

    document.querySelectorAll('[data-bs-toggle="modal"]').forEach(button => {
        button.addEventListener('click', function() {
            const modalId = this.getAttribute('data-bs-target');
            const modal = document.querySelector(modalId);
            if (modal) {
                modal.addEventListener('shown.bs.modal', function() {
                    const firstInput = this.querySelector('input, select, textarea');
                    if (firstInput) firstInput.focus();
                });
            }
        });
    });

    document.querySelectorAll('[data-bs-target^="#modalAsignar"]').forEach(button => {
        button.addEventListener('click', function() {
            const modalId = this.getAttribute('data-bs-target');
            const modal = document.querySelector(modalId);
            
            modal.addEventListener('shown.bs.modal', function() {
                const select = this.querySelector('select');
                if (select) {
                    if (typeof $.fn.select2 !== 'undefined') {
                        $(select).select2({
                            dropdownParent: $(this),
                            width: '100%',
                            placeholder: 'Seleccionar usuario'
                        });
                    } else {
                        select.focus();
                    }
                }
            });
        });
    });

    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('hidden.bs.modal', function() {
            if (typeof $.fn.select2 !== 'undefined') {
                $(this).find('select').select2('destroy');
            }
        });
    });
});

function validarCedula(input) {
    const cedula = input.value.trim();
    const feedback = input.nextElementSibling;
    
    if (!cedula) {
        input.classList.add('is-invalid');
        feedback.textContent = 'La cédula es requerida';
        return;
    }
    
    if (!/^[0-9]{6,8}$/.test(cedula)) {
        input.classList.add('is-invalid');
        feedback.textContent = 'La cédula debe tener entre 6 y 8 dígitos numéricos';
    } else {
        input.classList.remove('is-invalid');
        feedback.textContent = '';
    }
}

function validarNombre(input) {
    const nombre = input.value.trim();
    const feedback = input.nextElementSibling;
    
    if (!nombre) {
        input.classList.add('is-invalid');
        feedback.textContent = 'El nombre es requerido';
        return;
    }
    
    if (!/^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]{2,100}$/.test(nombre)) {
        input.classList.add('is-invalid');
        feedback.textContent = 'Solo se permiten letras y espacios (2-100 caracteres)';
    } else {
        input.classList.remove('is-invalid');
        feedback.textContent = '';
    }
}

function validarEdad(input) {
    const edad = parseInt(input.value);
    const feedback = input.nextElementSibling;
    
    if (isNaN(edad)) {
        input.classList.add('is-invalid');
        feedback.textContent = 'Por favor ingrese un número válido';
        return;
    }
    
    if (edad < 1 || edad > 120) {
        input.classList.add('is-invalid');
        feedback.textContent = 'La edad debe estar entre 1 y 120 años';
    } else {
        input.classList.remove('is-invalid');
        feedback.textContent = '';
    }
}

function validarTelefono(input) {
    const telefono = input.value.trim();
    const feedback = input.nextElementSibling;
    
    if (!telefono) {
        input.classList.add('is-invalid');
        feedback.textContent = 'El teléfono es requerido';
        return;
    }
    
    if (!/^[0-9]{10,11}$/.test(telefono)) {
        input.classList.add('is-invalid');
        feedback.textContent = 'El teléfono debe tener entre 10 y 11 dígitos';
    } else {
        input.classList.remove('is-invalid');
        feedback.textContent = '';
    }
}

function validarOcupacion(input) {
    const ocupacion = input.value.trim();
    const feedback = input.nextElementSibling;
    
    if (!ocupacion) {
        input.classList.add('is-invalid');
        feedback.textContent = 'La ocupación es requerida';
        return;
    }
    
    if (ocupacion.length > 100) {
        input.classList.add('is-invalid');
        feedback.textContent = 'La ocupación no puede exceder los 100 caracteres';
    } else {
        input.classList.remove('is-invalid');
        feedback.textContent = '';
    }
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
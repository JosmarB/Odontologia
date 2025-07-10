<?php
$page_title = "Gestión de Stock";
session_start();

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Usuario.php';
require_once __DIR__ . '/../includes/error_handler.php';

// Validar sesión
if (!isset($_SESSION['usuario']) || !is_array($_SESSION['usuario']) || !isset($_SESSION['usuario']['id'], $_SESSION['usuario']['rol_type'])) {
    $_SESSION['error'] = "La sesión ha expirado o es inválida. Por favor, inicia sesión nuevamente.";
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$usuario_actual = (object) $_SESSION['usuario'];
$database = new Database();
$db = $database->getConnection();

// Registrar manejador de errores
$errorHandler = new ErrorHandler($db);
$errorHandler->registerHandlers();

// Verificar permisos - Solo odontólogos y administradores pueden acceder
if (!in_array($usuario_actual->rol_type, ['A', 'O'])) {
    $errorHandler->logError('WARNING', "Intento de acceso no autorizado a stock por usuario ID: {$usuario_actual->id}");
    header("Location: /Odontologia/templates/unauthorized.php");
    exit;
}

// Validar y limpiar parámetros GET
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$search = isset($_GET['search']) ? trim(strip_tags($_GET['search'])) : '';

// Configuración de paginación
$limit = 10;
$offset = ($page - 1) * $limit;

// Manejo de POST para todas las acciones con validación CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorHandler->logError('WARNING', "Intento de CSRF detectado por usuario ID: {$usuario_actual->id}");
        $_SESSION['error'] = "Token de seguridad inválido";
        header("Location: stock.php?page=$page");
        exit;
    }

    // Validar acción
    if (!isset($_POST['action']) || !in_array($_POST['action'], ['crear', 'editar', 'eliminar'])) {
        $errorHandler->logError('WARNING', "Acción no válida recibida: " . ($_POST['action'] ?? 'null'));
        $_SESSION['error'] = "Acción no válida";
        header("Location: stock.php?page=$page");
        exit;
    }

    $accion = $_POST['action'];
    
    // Validar permisos para cada acción
    $accionPermitida = false;
    
    switch($accion) {
        case 'crear':
            $accionPermitida = true; // Todos los odontólogos pueden crear
            break;
        case 'editar':
            $accionPermitida = true; // Todos los odontólogos pueden editar
            // Validar ID para edición
            if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
                $errorHandler->logError('WARNING', "ID inválido para edición");
                $_SESSION['error'] = "ID de material inválido";
                header("Location: stock.php?page=$page");
                exit;
            }
            break;
        case 'eliminar':
            $accionPermitida = ($usuario_actual->rol_type === 'A'); // Solo administradores pueden eliminar
            // Validar ID para eliminación
            if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
                $errorHandler->logError('WARNING', "ID inválido para eliminación");
                $_SESSION['error'] = "ID de material inválido";
                header("Location: stock.php?page=$page");
                exit;
            }
            break;
    }
    
    if (!$accionPermitida) {
        $errorHandler->logError('WARNING', "Intento de acción no autorizada ($accion) por usuario ID: {$usuario_actual->id}");
        $_SESSION['error'] = "No tienes permiso para realizar esta acción";
        header("Location: stock.php?page=$page");
        exit;
    }

    if ($fecha_vencimiento && $fecha_vencimiento < new DateTime('today')) {
        $errorHandler->logError('WARNING', "Validación fallida: Fecha de vencimiento en el pasado");
        $_SESSION['error'] = "La fecha de vencimiento no puede ser anterior a hoy";
        header("Location: stock.php?page=$page");
        exit;
    }

    // Validaciones para crear/editar
    if ($accion === 'crear' || $accion === 'editar') {
        // Validar y sanitizar campos
        $nombre = isset($_POST['nombre']) ? trim(strip_tags($_POST['nombre'])) : '';
        $marca = isset($_POST['marca']) ? trim(strip_tags($_POST['marca'])) : '';
        $cantidad = filter_input(INPUT_POST, 'cantidad', FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 0
            ]
        ]);
        $fecha_vencimiento = isset($_POST['fecha_vencimiento']) && !empty($_POST['fecha_vencimiento']) ? 
            DateTime::createFromFormat('Y-m-d', $_POST['fecha_vencimiento']) : null;
        
        // Validar campos requeridos
        $campos_requeridos = [
            'nombre' => $nombre,
            'cantidad' => $cantidad
        ];
        
        foreach ($campos_requeridos as $campo => $valor) {
            if (empty($valor)) {
                $errorHandler->logError('WARNING', "Campo requerido faltante: $campo");
                $_SESSION['error'] = "El campo " . ucfirst(str_replace('_', ' ', $campo)) . " es requerido";
                header("Location: stock.php?page=$page");
                exit;
            }
        }
        
        // Validaciones específicas
        if (strlen($nombre) > 100) {
            $errorHandler->logError('WARNING', "Validación fallida: Nombre demasiado largo");
            $_SESSION['error'] = "El nombre no puede exceder los 100 caracteres";
            header("Location: stock.php?page=$page");
            exit;
        }

        if (strlen($marca) > 100) {
            $errorHandler->logError('WARNING', "Validación fallida: Marca demasiado larga");
            $_SESSION['error'] = "La marca no puede exceder los 100 caracteres";
            header("Location: stock.php?page=$page");
            exit;
        }
    }

    // Procesar acción de creación
    if ($accion === 'crear') {
        try {
            $sql = "INSERT INTO stock_materiales 
                    (nombre, marca, cantidad, fecha_vencimiento, usuario_id) 
                    VALUES 
                    (:nombre, :marca, :cantidad, :fecha_vencimiento, :usuario_id)";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':nombre' => $nombre,
                ':marca' => $marca ?: null,
                ':cantidad' => $cantidad,
                ':fecha_vencimiento' => $fecha_vencimiento ? $fecha_vencimiento->format('Y-m-d') : null,
                ':usuario_id' => $usuario_actual->id
            ]);
            
            $errorHandler->logError('INFO', "Material creado: {$nombre} (ID: {$db->lastInsertId()}, Cantidad: {$cantidad})");
            $_SESSION['success'] = "Material " . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . " creado exitosamente";
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al crear material: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al crear el material";
        }
    }

    // Procesar acción de edición
    if ($accion === 'editar') {
        $id = (int)$_POST['id'];
        try {
            // Verificar si el material existe
            $stmt_check = $db->prepare("SELECT id FROM stock_materiales WHERE id = :id AND Estado_Sistema = 'Activo'");
            $stmt_check->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt_check->execute();
            
            if (!$stmt_check->fetch()) {
                $errorHandler->logError('WARNING', "Intento de editar material no encontrado ID: $id");
                $_SESSION['error'] = "Material no encontrado";
                header("Location: stock.php?page=$page");
                exit;
            }

            $sql = "UPDATE stock_materiales SET 
                    nombre = :nombre,
                    marca = :marca,
                    cantidad = :cantidad,
                    fecha_vencimiento = :fecha_vencimiento,
                    usuario_id = :usuario_id
                    WHERE id = :id";
            
            $stmt = $db->prepare($sql);
            $stmt->execute([
                ':nombre' => $nombre,
                ':marca' => $marca ?: null,
                ':cantidad' => $cantidad,
                ':fecha_vencimiento' => $fecha_vencimiento ? $fecha_vencimiento->format('Y-m-d') : null,
                ':usuario_id' => $usuario_actual->id,
                ':id' => $id
            ]);
            
            $errorHandler->logError('INFO', "Material actualizado: {$nombre} (ID: {$id}, Cantidad: {$cantidad})");
            $_SESSION['success'] = "Material " . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . " actualizado exitosamente";
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al actualizar material: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al actualizar el material";
        }
    }

    // Procesar acción de eliminación
    if ($accion === 'eliminar') {
        $id = (int)$_POST['id'];
        try {
            // Verificar si el material existe
            $stmt_check = $db->prepare("SELECT id, nombre FROM stock_materiales WHERE id = :id AND Estado_Sistema = 'Activo'");
            $stmt_check->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt_check->execute();
            $material = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$material) {
                $errorHandler->logError('WARNING', "Intento de eliminar material no encontrado ID: $id");
                $_SESSION['error'] = "Material no encontrado";
                header("Location: stock.php?page=$page");
                exit;
            }

            $nombre_material = $material['nombre'];
            
            // Marcar como inactivo en lugar de eliminar físicamente
            $sql = "UPDATE stock_materiales SET Estado_Sistema = 'Inactivo' WHERE id = :id";
            $stmt = $db->prepare($sql);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                // Registrar en auditoría
                $datos_originales = json_encode([
                    'nombre' => $nombre_material,
                    'eliminado_por' => $usuario_actual->id
                ], JSON_UNESCAPED_UNICODE);
                
                try {
                    $auditoria_sql = "INSERT INTO auditoria_eliminaciones 
                                    (tabla_afectada, id_registro_afectado, usuario_eliminador_id, nombre_usuario_eliminador, datos_originales) 
                                    VALUES 
                                    ('Stock_Materiales', :id, :user_id, :user_name, :datos)";
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
                
                $errorHandler->logError('INFO', "Material eliminado: {$nombre_material} (ID: {$id})");
                $_SESSION['success'] = "Material " . htmlspecialchars($nombre_material, ENT_QUOTES, 'UTF-8') . " eliminado exitosamente";
            } else {
                $errorHandler->logError('ERROR', "Error al eliminar material: {$nombre_material} (ID: {$id})");
                $_SESSION['error'] = "Error al eliminar el material";
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al eliminar material: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al eliminar el material";
        }
    }

    $redirect_url = "stock.php?page=$page" . ($search ? "&search=" . urlencode($search) : "");
    header("Location: $redirect_url");
    exit;
}

// Generar token CSRF para el formulario si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Obtener lista de materiales con manejo de errores
try {
    $search_param = "%$search%";
    
    // Conteo total - SOLO materiales activos
    $count_sql = "SELECT COUNT(*) FROM stock_materiales WHERE Estado_Sistema = 'Activo'";
    if ($search !== '') {
        $count_sql .= " AND (nombre LIKE :search OR marca LIKE :search)";
    }
    $count_stmt = $db->prepare($count_sql);
    if ($search !== '') {
        $count_stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
    }
    $count_stmt->execute();
    $total = $count_stmt->fetchColumn();
    $total_pages = ceil($total / $limit);

    // Obtener materiales - SOLO activos
    $sql = "SELECT s.*, u.nombre as usuario_nombre 
            FROM stock_materiales s 
            JOIN usuario u ON s.usuario_id = u.id 
            WHERE s.Estado_Sistema = 'Activo'";
    
    if ($search !== '') {
        $sql .= " AND (s.nombre LIKE :search OR s.marca LIKE :search)";
    }
    
    $sql .= " ORDER BY s.nombre LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($sql);
    
    if ($search !== '') {
        $stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
    }
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $materiales = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorHandler->logError('ERROR', "Error al obtener materiales: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $materiales = [];
    $total = 0;
    $total_pages = 1;
    $_SESSION['error'] = "Error al cargar la lista de materiales";
}

include '../templates/navbar.php';
?>

<style>
/* Estilos similares a los de pacientes */
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

/* Estilo para materiales próximos a vencer */
.text-warning {
    color: #ffc107 !important;
    font-weight: bold;
}

/* Estilo para materiales vencidos */
.text-danger {
    color: #dc3545 !important;
    font-weight: bold;
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
        <h1>Gestión de Stock</h1>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCrear">
            <i class="bi bi-plus-circle"></i> Nuevo Material
        </button>
    </div>

    <form method="GET" class="mb-4">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Buscar por nombre o marca..." 
                   value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" 
                   aria-label="Buscar materiales" 
                   maxlength="100">
            <button class="btn btn-outline-primary" type="submit">Buscar</button>
            <?php if ($search): ?>
                <a href="stock.php" class="btn btn-outline-secondary">Limpiar</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if (!empty($materiales)): ?>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="table table-striped">
                    <tr>
                        <th scope="col">Nombre</th>
                        <th scope="col">Marca</th>
                        <th scope="col">Cantidad</th>
                        <th scope="col">Vencimiento</th>
                        <th scope="col">Registrado por</th>
                        <th scope="col">Última actualización</th>
                        <th scope="col">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($materiales as $material): 
                        // Determinar clase CSS para vencimiento
                        $vencimiento_class = '';
                        if ($material['fecha_vencimiento']) {
                            $hoy = new DateTime();
                            $vencimiento = new DateTime($material['fecha_vencimiento']);
                            $diferencia = $hoy->diff($vencimiento);
                            
                            if ($vencimiento < $hoy) {
                                $vencimiento_class = 'text-danger';
                            } elseif ($diferencia->days <= 30) {
                                $vencimiento_class = 'text-warning';
                            }
                        }
                    ?>
                        <tr>
                            <td><?= htmlspecialchars($material['nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($material['marca'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($material['cantidad'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="<?= $vencimiento_class ?>">
                                <?= $material['fecha_vencimiento'] ? date('d/m/Y', strtotime($material['fecha_vencimiento'])) : 'N/A' ?>
                            </td>
                            <td><?= htmlspecialchars($material['usuario_nombre'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= date('d/m/Y H:i', strtotime($material['fecha_actualizacion'])) ?></td>
                            <td>
                                <div class="btn-group" role="group" aria-label="Acciones del material">
                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalEditar<?= $material['id'] ?>">
                                        <i class="bi bi-pencil"></i> Editar
                                    </button>
                                    <?php if ($usuario_actual->rol_type === 'A'): ?>
                                        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#modalEliminar<?= $material['id'] ?>">
                                            <i class="bi bi-trash"></i> Eliminar
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <nav aria-label="Paginación de materiales">
            <ul class="pagination justify-content-center">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page - 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>" aria-label="Anterior">
                        <span aria-hidden="true">&laquo;</span>
                    </a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?page=<?= $page + 1 ?><?= $search ? '&search=' . urlencode($search) : '' ?>" aria-label="Siguiente">
                        <span aria-hidden="true">&raquo;</span>
                    </a>
                </li>
            </ul>
        </nav>
    <?php else: ?>
        <div class="alert alert-info">No hay materiales registrados<?= $search ? ' que coincidan con la búsqueda' : '' ?>.</div>
    <?php endif; ?>
</div>

<!-- Modal CREAR -->
<div class="modal fade" id="modalCrear" tabindex="-1" aria-labelledby="modalCrearLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content animate__animated animate__fadeInDown">
      <form method="POST" action="" novalidate>
        <input type="hidden" name="action" value="crear">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="modalCrearLabel">Nuevo Material</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="nombreCrear" class="form-label">Nombre*</label>
            <input type="text" name="nombre" id="nombreCrear" class="form-control" 
                   maxlength="100" 
                   required>
            <div class="invalid-feedback">El nombre es requerido (máximo 100 caracteres)</div>
          </div>
          <div class="mb-3">
            <label for="marcaCrear" class="form-label">Marca</label>
            <input type="text" name="marca" id="marcaCrear" class="form-control" 
                   maxlength="100">
            <div class="invalid-feedback">La marca no puede exceder los 100 caracteres</div>
          </div>
          <div class="mb-3">
            <label for="cantidadCrear" class="form-label">Cantidad*</label>
            <input type="number" name="cantidad" id="cantidadCrear" class="form-control" 
                   min="0" 
                   required>
            <div class="invalid-feedback">La cantidad es requerida (número positivo)</div>
          </div>
          <div class="mb-3">
            <label for="fechaVencimientoCrear" class="form-label">Fecha de Vencimiento (opcional)</label>
            <input type="date" name="fecha_vencimiento" id="fechaVencimientoCrear" class="form-control">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success">Guardar Material</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modales para cada material -->
<?php foreach ($materiales as $material): ?>
<!-- Modal EDITAR -->
<div class="modal fade" id="modalEditar<?= $material['id'] ?>" tabindex="-1" aria-labelledby="modalEditarLabel<?= $material['id'] ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content animate__animated animate__fadeInDown">
      <form action="" method="POST" novalidate>
        <input type="hidden" name="action" value="editar">
        <input type="hidden" name="id" value="<?= $material['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title" id="modalEditarLabel<?= $material['id'] ?>">Editar Material</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label for="nombreEditar<?= $material['id'] ?>" class="form-label">Nombre*</label>
            <input type="text" name="nombre" id="nombreEditar<?= $material['id'] ?>" class="form-control" 
                   value="<?= htmlspecialchars($material['nombre'], ENT_QUOTES, 'UTF-8') ?>" 
                   maxlength="100" 
                   required>
            <div class="invalid-feedback">El nombre es requerido (máximo 100 caracteres)</div>
          </div>
          <div class="mb-3">
            <label for="marcaEditar<?= $material['id'] ?>" class="form-label">Marca</label>
            <input type="text" name="marca" id="marcaEditar<?= $material['id'] ?>" class="form-control" 
                   value="<?= htmlspecialchars($material['marca'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                   maxlength="100">
            <div class="invalid-feedback">La marca no puede exceder los 100 caracteres</div>
          </div>
          <div class="mb-3">
            <label for="cantidadEditar<?= $material['id'] ?>" class="form-label">Cantidad*</label>
            <input type="number" name="cantidad" id="cantidadEditar<?= $material['id'] ?>" class="form-control" 
                   value="<?= htmlspecialchars($material['cantidad'], ENT_QUOTES, 'UTF-8') ?>" 
                   min="0" 
                   required>
            <div class="invalid-feedback">La cantidad es requerida (número positivo)</div>
          </div>
          <div class="mb-3">
            <label for="fechaVencimientoEditar<?= $material['id'] ?>" class="form-label">Fecha de Vencimiento (opcional)</label>
            <input type="date" name="fecha_vencimiento" id="fechaVencimientoEditar<?= $material['id'] ?>" class="form-control" 
                   value="<?= $material['fecha_vencimiento'] ? htmlspecialchars($material['fecha_vencimiento'], ENT_QUOTES, 'UTF-8') : '' ?>">
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

<!-- Modal ELIMINAR -->
<?php if ($usuario_actual->rol_type === 'A'): ?>
<div class="modal fade" id="modalEliminar<?= $material['id'] ?>" tabindex="-1" aria-labelledby="modalEliminarLabel<?= $material['id'] ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content animate__animated animate__zoomIn">
      <form action="" method="POST">
        <input type="hidden" name="action" value="eliminar">
        <input type="hidden" name="id" value="<?= $material['id'] ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title" id="modalEliminarLabel<?= $material['id'] ?>">Confirmar Eliminación</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <p>¿Estás seguro de que deseas eliminar el material <strong><?= htmlspecialchars($material['nombre'], ENT_QUOTES, 'UTF-8') ?></strong>?</p>
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
<?php endforeach; ?>

<script>
// Configurar validaciones al cargar la página
document.addEventListener('DOMContentLoaded', function() {
    // Configurar eventos para validación en tiempo real
    const setupValidation = (form) => {
        // Nombre
        const nombreInput = form.querySelector('input[name="nombre"]');
        if (nombreInput) {
            nombreInput.addEventListener('blur', function() {
                validarNombre(this);
            });
            nombreInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }

        // Marca
        const marcaInput = form.querySelector('input[name="marca"]');
        if (marcaInput) {
            marcaInput.addEventListener('blur', function() {
                validarMarca(this);
            });
            marcaInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }

        // Cantidad
        const cantidadInput = form.querySelector('input[name="cantidad"]');
        if (cantidadInput) {
            cantidadInput.addEventListener('blur', function() {
                validarCantidad(this);
            });
            cantidadInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }
    };

    // Configurar validación para todos los formularios (excepto eliminar)
    document.querySelectorAll('form').forEach(form => {
        if (!form.querySelector('input[name="action"][value="eliminar"]')) {
            setupValidation(form);
            
            form.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Validar campos
                const campos = [
                    {name: 'nombre', validator: validarNombre},
                    {name: 'marca', validator: validarMarca},
                    {name: 'cantidad', validator: validarCantidad}
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
                
                if (!isValid) {
                    e.preventDefault();
                    const firstInvalid = form.querySelector('.is-invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                        // Agregar animación para llamar la atención
                        firstInvalid.style.animation = 'none';
                        setTimeout(() => {
                            firstInvalid.style.animation = 'shake 0.5s ease-in-out';
                        }, 10);
                    }
                    
                    // Mostrar alerta general
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

    // Configurar eventos para modales
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
});

// Funciones de validación
function validarNombre(input) {
    const nombre = input.value.trim();
    const feedback = input.nextElementSibling;
    
    if (!nombre) {
        input.classList.add('is-invalid');
        feedback.textContent = 'El nombre es requerido';
        return;
    }
    
    if (nombre.length > 100) {
        input.classList.add('is-invalid');
        feedback.textContent = 'El nombre no puede exceder los 100 caracteres';
    } else {
        input.classList.remove('is-invalid');
        feedback.textContent = '';
    }
}

function validarMarca(input) {
    const marca = input.value.trim();
    const feedback = input.nextElementSibling;
    
    if (marca.length > 100) {
        input.classList.add('is-invalid');
        feedback.textContent = 'La marca no puede exceder los 100 caracteres';
    } else {
        input.classList.remove('is-invalid');
        feedback.textContent = '';
    }
}

function validarCantidad(input) {
    const cantidad = parseInt(input.value);
    const feedback = input.nextElementSibling;
    
    if (isNaN(cantidad)) {
        input.classList.add('is-invalid');
        feedback.textContent = 'Por favor ingrese un número válido';
        return;
    }
    
    if (cantidad < 0) {
        input.classList.add('is-invalid');
        feedback.textContent = 'La cantidad no puede ser negativa';
    } else {
        input.classList.remove('is-invalid');
        feedback.textContent = '';
    }
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
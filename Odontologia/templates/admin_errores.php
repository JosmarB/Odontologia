<?php
$page_title = "Administración de Errores y Consultas SQL";
session_start();

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Usuario.php';
require_once __DIR__ . '/../includes/error_handler.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$usuario_actual = (object) $_SESSION['usuario'];
$database = new Database();
$db = $database->getConnection();
$errorHandler = new ErrorHandler($db);

if ($usuario_actual->rol_type !== 'A') {
    header("Location: /Odontologia/templates/unauthorized.php");
    exit;
}

// Configuración de paginación
$items_por_pagina = $_GET['items'] ?? 10;
if (!in_array($items_por_pagina, [5, 10, 100])) {
    $items_por_pagina = 10;
}

// Manejar acciones
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$resultado_consulta = null;
$error_consulta = null;

// Procesar borrado de errores antiguos
if ($action === 'clear_old_errors' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $filas_afectadas = $errorHandler->clearOldErrors(7);
        $_SESSION['mensaje'] = "Se eliminaron $filas_afectadas errores antiguos (más de 1 semana).";
        header("Location: admin_errores.php?".http_build_query([
            'items' => $items_por_pagina,
            'nivel' => $_GET['nivel'] ?? null,
            'busqueda' => $_GET['busqueda'] ?? null,
            'pagina' => $_GET['pagina'] ?? 1
        ]));
        exit;
    } catch (Exception $e) {
        $errorHandler->logError('ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $_SESSION['error'] = "Error al eliminar errores antiguos: " . $e->getMessage();
    }
}

// Procesar borrado múltiple
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_selected'])) {
    if (!empty($_POST['selected_errors'])) {
        try {
            $selected_errors = json_decode($_POST['selected_errors'], true);
            $placeholders = implode(',', array_fill(0, count($selected_errors), '?'));
            $stmt = $db->prepare("DELETE FROM errores_sistema WHERE id IN ($placeholders)");
            $stmt->execute($selected_errors);
            $filas_afectadas = $stmt->rowCount();
            
            $_SESSION['mensaje'] = "Se eliminaron $filas_afectadas errores seleccionados.";
            header("Location: admin_errores.php?".http_build_query([
                'items' => $_POST['items_por_pagina'] ?? $items_por_pagina,
                'nivel' => $_POST['nivel_filtro'] ?? null,
                'busqueda' => $_POST['busqueda_filtro'] ?? null,
                'pagina' => $_POST['pagina_actual'] ?? 1
            ]));
            exit;
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al eliminar errores seleccionados: " . $e->getMessage();
        }
    } else {
        $_SESSION['error'] = "No se seleccionaron errores para eliminar.";
    }
}

// Procesar consulta SQL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['consulta_sql'])) {
    $consulta = trim($_POST['consulta_sql']);
    
    $start_time = microtime(true);
    
    try {
        $stmt = $db->prepare($consulta);
        $stmt->execute();
        
        $tiempo_ejecucion = round((microtime(true) - $start_time) * 1000, 2);
        $filas_afectadas = $stmt->rowCount();
        
        $audit_stmt = $db->prepare("
            INSERT INTO auditoria_consultas_sql 
            (usuario_id, consulta, tiempo_ejecucion, filas_afectadas) 
            VALUES (?, ?, ?, ?)
        ");
        $audit_stmt->execute([$usuario_actual->id, $consulta, $tiempo_ejecucion, $filas_afectadas]);
        
        if (stripos($consulta, 'SELECT') === 0) {
            $resultado_consulta = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $resultado_consulta = ["message" => "Consulta ejecutada con éxito. Filas afectadas: $filas_afectadas"];
        }
    } catch (Exception $e) {
        $error_consulta = $e->getMessage();
        
        $audit_stmt = $db->prepare("
            INSERT INTO auditoria_consultas_sql 
            (usuario_id, consulta, tiempo_ejecucion, error) 
            VALUES (?, ?, ?, ?)
        ");
        $audit_stmt->execute([
            $usuario_actual->id, 
            $consulta, 
            round((microtime(true) - $start_time) * 1000, 2),
            $error_consulta
        ]);
    }
}

// Obtener parámetros de filtrado
$nivel_filtro = $_GET['nivel'] ?? null;
$busqueda = $_GET['busqueda'] ?? null;
$pagina_actual = $_GET['pagina'] ?? 1;

// Obtener total de errores
$total_errores = 0;
try {
    $query = "SELECT COUNT(*) as total FROM errores_sistema WHERE 1=1";
    $params = [];
    
    if ($nivel_filtro) {
        $query .= " AND nivel = :nivel";
        $params[':nivel'] = $nivel_filtro;
    }
    
    if ($busqueda) {
        $query .= " AND (mensaje LIKE :search OR archivo LIKE :search_file)";
        $params[':search'] = "%$busqueda%";
        $params[':search_file'] = "%$busqueda%";
    }
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $total_errores = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $errorHandler->logError('ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
}

$total_paginas = ceil($total_errores / $items_por_pagina);

// Configuración de paginación mejorada (grupos de 6 páginas)
$grupo_paginas = 6;
$mitad_grupo = floor($grupo_paginas / 2);
$pagina_inicio = max(1, $pagina_actual - $mitad_grupo);
$pagina_fin = min($pagina_inicio + $grupo_paginas - 1, $total_paginas);

// Ajustar si estamos cerca del final
if ($pagina_fin - $pagina_inicio < $grupo_paginas - 1) {
    $pagina_inicio = max(1, $pagina_fin - $grupo_paginas + 1);
}

// Obtener errores paginados
$errores = [];
try {
    $query = "SELECT e.*, u.nombre as usuario_nombre 
              FROM errores_sistema e
              LEFT JOIN usuario u ON e.usuario_id = u.id
              WHERE 1=1";
    $params = [];
    
    if ($nivel_filtro) {
        $query .= " AND e.nivel = :nivel";
        $params[':nivel'] = $nivel_filtro;
    }
    
    if ($busqueda) {
        $query .= " AND (e.mensaje LIKE :search OR e.archivo LIKE :search_file)";
        $params[':search'] = "%$busqueda%";
        $params[':search_file'] = "%$busqueda%";
    }
    
    $query .= " ORDER BY e.fecha DESC LIMIT :limit OFFSET :offset";
    $params[':limit'] = (int)$items_por_pagina;
    $params[':offset'] = (int)(($pagina_actual - 1) * $items_por_pagina);
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $paramType = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $stmt->bindValue($key, $value, $paramType);
    }
    $stmt->execute();
    $errores = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorHandler->logError('ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
}

// Obtener consultas recientes
$consultas_recientes = [];
try {
    $stmt = $db->query("
        SELECT a.*, u.nombre as usuario_nombre 
        FROM auditoria_consultas_sql a
        JOIN usuario u ON a.usuario_id = u.id
        ORDER BY a.fecha DESC 
        LIMIT 20
    ");
    $consultas_recientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorHandler->logError('ERROR', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
}

$consultas_comunes = [
    'Pacientes activos' => "SELECT * FROM paciente WHERE Estado_Sistema = 'Activo'",
    'Citas pendientes' => "SELECT c.*, p.nombre as paciente_nombre, u.nombre as doctor_nombre 
                          FROM citas c 
                          JOIN paciente p ON c.paciente_id = p.id 
                          JOIN usuario u ON c.usuario_id = u.id 
                          WHERE c.estado = 'Pendiente' AND c.Estado_Sistema = 'Activo'",
    'Historial médico reciente' => "SELECT h.*, p.nombre as paciente_nombre, u.nombre as doctor_nombre FROM historia_medica h 
                                   JOIN paciente p ON h.paciente_id = p.id 
                                   JOIN usuario u ON h.examinador_id = u.id 
                                   ORDER BY h.fecha_creacion DESC LIMIT 10",
    'Errores del sistema ' => "SELECT * FROM errores_sistema WHERE nivel = 'ERROR' ORDER BY fecha DESC LIMIT 10"
];

include '../templates/navbar.php';
?>

<div class="container-fluid mt-4">
    <h2 class="mb-4">Administración del Sistema</h2>
    
    <?php if (isset($_SESSION['mensaje'])): ?>
        <div class="alert alert-success"><?= $_SESSION['mensaje'] ?></div>
        <?php unset($_SESSION['mensaje']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <ul class="nav nav-tabs" id="adminTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="errores-tab" data-bs-toggle="tab" data-bs-target="#errores" type="button" role="tab" aria-controls="errores" aria-selected="true">Errores del Sistema</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="consultas-tab" data-bs-toggle="tab" data-bs-target="#consultas" type="button" role="tab" aria-controls="consultas" aria-selected="false">Consultas SQL</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="auditoria-tab" data-bs-toggle="tab" data-bs-target="#auditoria" type="button" role="tab" aria-controls="auditoria" aria-selected="false">Auditoría de Consultas</button>
        </li>
    </ul>
    
    <div class="tab-content p-3 border border-top-0 rounded-bottom" id="adminTabsContent">
        <!-- Pestaña de Errores -->
        <div class="tab-pane fade show active" id="errores" role="tabpanel" aria-labelledby="errores-tab">
            <form method="get" id="filterForm">
                <div class="d-flex justify-content-between mb-3">
                    <h4>Registro de Errores</h4>
                    <div>
                        <div class="input-group">
                            <select name="items" class="form-select" onchange="this.form.submit()">
                                <option value="5" <?= $items_por_pagina == 5 ? 'selected' : '' ?>>5 por página</option>
                                <option value="10" <?= $items_por_pagina == 10 ? 'selected' : '' ?>>10 por página</option>
                                <option value="100" <?= $items_por_pagina == 100 ? 'selected' : '' ?>>100 por página</option>
                            </select>
                            <select name="nivel" class="form-select" onchange="this.form.submit()">
                                <option value="">Todos los niveles</option>
                                <option value="DEBUG" <?= $nivel_filtro === 'DEBUG' ? 'selected' : '' ?>>DEBUG</option>
                                <option value="INFO" <?= $nivel_filtro === 'INFO' ? 'selected' : '' ?>>INFO</option>
                                <option value="WARNING" <?= $nivel_filtro === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                                <option value="ERROR" <?= $nivel_filtro === 'ERROR' ? 'selected' : '' ?>>ERROR</option>
                                <option value="CRITICAL" <?= $nivel_filtro === 'CRITICAL' ? 'selected' : '' ?>>CRITICAL</option>
                            </select>
                            <input type="text" name="busqueda" class="form-control" placeholder="Buscar..." value="<?= htmlspecialchars($busqueda ?? '') ?>">
                            <button type="submit" class="btn btn-primary">Filtrar</button>
                            <a href="admin_errores.php" class="btn btn-secondary">Limpiar</a>
                        </div>
                    </div>
                </div>
            </form>
            
            <!-- Formulario oculto para el borrado múltiple -->
            <form method="post" id="deleteForm">
                <input type="hidden" name="items_por_pagina" value="<?= $items_por_pagina ?>">
                <input type="hidden" name="nivel_filtro" value="<?= $nivel_filtro ?>">
                <input type="hidden" name="busqueda_filtro" value="<?= htmlspecialchars($busqueda ?? '') ?>">
                <input type="hidden" name="pagina_actual" value="<?= $pagina_actual ?>">
                <input type="hidden" name="delete_selected" value="1">
                <input type="hidden" name="selected_errors" id="selectedErrorsInput">
                
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th width="30px"><input type="checkbox" id="selectAll"></th>
                                <th>Fecha</th>
                                <th>Nivel</th>
                                <th>Usuario</th>
                                <th>Mensaje</th>
                                <th>Archivo</th>
                                <th>Línea</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($errores as $error): ?>
                            <tr class="<?= $error['nivel'] === 'CRITICAL' ? 'table-danger' : ($error['nivel'] === 'ERROR' ? 'table-warning' : '') ?>">
                                <td><input type="checkbox" name="selected_errors" class="error-checkbox" value="<?= $error['id'] ?>"></td>
                                <td><?= date('d/m/Y H:i', strtotime($error['fecha'])) ?></td>
                                <td><span class="badge bg-<?= 
                                    $error['nivel'] === 'CRITICAL' ? 'danger' : 
                                    ($error['nivel'] === 'ERROR' ? 'warning' : 
                                    ($error['nivel'] === 'WARNING' ? 'info' : 
                                    ($error['nivel'] === 'INFO' ? 'primary' : 'secondary'))) 
                                ?>"><?= $error['nivel'] ?></span></td>
                                <td><?= htmlspecialchars($error['usuario_nombre'] ?? 'Sistema') ?></td>
                                <td><?= htmlspecialchars(substr($error['mensaje'], 0, 100)) . (strlen($error['mensaje']) > 100 ? '...' : '') ?></td>
                                <td><?= $error['archivo'] ? htmlspecialchars(basename($error['archivo'])) : '' ?></td>
                                <td><?= $error['linea'] ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info show-details" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#errorDetailModal" 
                                            data-error='<?= htmlspecialchars(json_encode($error), ENT_QUOTES) ?>'>
                                        Detalles
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Paginación mejorada -->
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <!-- Primera página -->
                        <li class="page-item <?= $pagina_actual == 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query([
                                'items' => $items_por_pagina,
                                'nivel' => $nivel_filtro,
                                'busqueda' => $busqueda,
                                'pagina' => 1
                            ]) ?>" aria-label="Primera">
                                <span aria-hidden="true">&laquo;&laquo;</span>
                            </a>
                        </li>
                        
                        <!-- Página anterior -->
                        <li class="page-item <?= $pagina_actual == 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query([
                                'items' => $items_por_pagina,
                                'nivel' => $nivel_filtro,
                                'busqueda' => $busqueda,
                                'pagina' => $pagina_actual - 1
                            ]) ?>" aria-label="Anterior">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>

                        <!-- Números de página -->
                        <?php for ($i = $pagina_inicio; $i <= $pagina_fin; $i++): ?>
                            <li class="page-item <?= $i == $pagina_actual ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query([
                                    'items' => $items_por_pagina,
                                    'nivel' => $nivel_filtro,
                                    'busqueda' => $busqueda,
                                    'pagina' => $i
                                ]) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>

                        <!-- Página siguiente -->
                        <li class="page-item <?= $pagina_actual == $total_paginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query([
                                'items' => $items_por_pagina,
                                'nivel' => $nivel_filtro,
                                'busqueda' => $busqueda,
                                'pagina' => $pagina_actual + 1
                            ]) ?>" aria-label="Siguiente">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                        
                        <!-- Última página -->
                        <li class="page-item <?= $pagina_actual == $total_paginas ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query([
                                'items' => $items_por_pagina,
                                'nivel' => $nivel_filtro,
                                'busqueda' => $busqueda,
                                'pagina' => $total_paginas
                            ]) ?>" aria-label="Última">
                                <span aria-hidden="true">&raquo;&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
                
                <div class="d-flex justify-content-between mt-3">
                    <div>
                        <button type="button" id="deleteSelectedBtn" class="btn btn-danger" onclick="confirmDelete()">
                            Eliminar seleccionados
                        </button>
                        <button type="button" class="btn btn-danger" onclick="if(confirm('¿Eliminar errores antiguos (más de 1 semana)?')) location.href='admin_errores.php?action=clear_old_errors&<?= http_build_query([
                            'items' => $items_por_pagina,
                            'nivel' => $nivel_filtro,
                            'busqueda' => $busqueda,
                            'pagina' => $pagina_actual
                        ]) ?>'">
                            Limpiar errores antiguos
                        </button>
                    </div>
                    <div>
                        <span class="badge bg-danger">CRITICAL</span>
                        <span class="badge bg-warning">ERROR</span>
                        <span class="badge bg-info">WARNING</span>
                        <span class="badge bg-primary">INFO</span>
                        <span class="badge bg-secondary">DEBUG</span>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Pestaña de Consultas SQL -->
        <div class="tab-pane fade" id="consultas" role="tabpanel" aria-labelledby="consultas-tab">
            <h4 class="mb-3">Consultas SQL</h4>
            
            <div class="alert alert-warning">
                <strong>Advertencia:</strong> Esta herramienta permite ejecutar consultas SQL directamente en la base de datos. 
                Úsela con precaución ya que puede afectar la integridad de los datos.
            </div>
            
            <form method="post" class="mb-4">
                <input type="hidden" name="items" value="<?= $items_por_pagina ?>">
                <input type="hidden" name="nivel" value="<?= $nivel_filtro ?>">
                <input type="hidden" name="busqueda" value="<?= htmlspecialchars($busqueda ?? '') ?>">
                <input type="hidden" name="pagina" value="<?= $pagina_actual ?>">
                
                <div class="mb-3">
                    <label for="consulta_sql" class="form-label">Consulta SQL:</label>
                    <textarea class="form-control font-monospace" id="consulta_sql" name="consulta_sql" rows="5" 
                              placeholder="Ej: SELECT * FROM paciente WHERE Estado_Sistema = 'Activo'"><?= isset($_POST['consulta_sql']) ? htmlspecialchars($_POST['consulta_sql']) : '' ?></textarea>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Consultas comunes:</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($consultas_comunes as $nombre => $consulta): ?>
                            <button type="button" class="btn btn-outline-secondary btn-sm" 
                                    onclick="document.getElementById('consulta_sql').value = '<?= addslashes($consulta) ?>'">
                                <?= htmlspecialchars($nombre) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Ejecutar Consulta</button>
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('consulta_sql').value = ''">Limpiar</button>
            </form>
            
            <?php if ($error_consulta): ?>
                <div class="alert alert-danger">
                    <h5>Error en la consulta:</h5>
                    <pre class="mb-0"><?= htmlspecialchars($error_consulta) ?></pre>
                </div>
            <?php endif; ?>
            
            <?php if ($resultado_consulta): ?>
                <div class="mt-4">
                    <h5>Resultados:</h5>
                    
                    <?php if (is_array($resultado_consulta) && count($resultado_consulta) > 0): ?>
                        <div class="table-responsive">
                            <table class="table table-striped table-bordered">
                                <thead>
                                    <tr>
                                        <?php foreach (array_keys($resultado_consulta[0]) as $columna): ?>
                                            <th><?= htmlspecialchars($columna) ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($resultado_consulta as $fila): ?>
                                        <tr>
                                            <?php foreach ($fila as $valor): ?>
                                                <td><?= is_array($valor) || is_object($valor) ? htmlspecialchars(json_encode($valor)) : htmlspecialchars($valor) ?></td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif (is_array($resultado_consulta)): ?>
                        <div class="alert alert-info">La consulta no devolvió resultados.</div>
                    <?php else: ?>
                        <div class="alert alert-success">
                            <pre class="mb-0"><?= htmlspecialchars(print_r($resultado_consulta, true)) ?></pre>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Pestaña de Auditoría de Consultas -->
        <div class="tab-pane fade" id="auditoria" role="tabpanel" aria-labelledby="auditoria-tab">
            <h4 class="mb-3">Auditoría de Consultas SQL</h4>
            
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Fecha</th>
                            <th>Usuario</th>
                            <th>Consulta</th>
                            <th>Tiempo (ms)</th>
                            <th>Filas</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($consultas_recientes as $consulta): ?>
                        <tr class="<?= $consulta['error'] ? 'table-danger' : '' ?>">
                            <td><?= date('d/m/Y H:i', strtotime($consulta['fecha'])) ?></td>
                            <td><?= htmlspecialchars($consulta['usuario_nombre']) ?></td>
                            <td>
                                <span class="d-inline-block text-truncate" style="max-width: 200px;">
                                    <?= htmlspecialchars($consulta['consulta']) ?>
                                </span>
                            </td>
                            <td><?= $consulta['tiempo_ejecucion'] ?></td>
                            <td><?= $consulta['filas_afectadas'] ?? '-' ?></td>
                            <td>
                                <?php if ($consulta['error']): ?>
                                    <span class="badge bg-danger">Error</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Éxito</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal para detalles de error -->
<div class="modal fade" id="errorDetailModal" tabindex="-1" aria-labelledby="errorDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="errorDetailModalLabel">Detalles del Error</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong>Fecha:</strong> <span id="error-fecha"></span>
                    </div>
                    <div class="col-md-4">
                        <strong>Nivel:</strong> <span id="error-nivel" class="badge"></span>
                    </div>
                    <div class="col-md-4">
                        <strong>Usuario:</strong> <span id="error-usuario"></span>
                    </div>
                </div>
                
                <div class="mb-3">
                    <strong>Mensaje:</strong>
                    <div class="p-2 bg-light rounded" id="error-mensaje"></div>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Archivo:</strong> <span id="error-archivo"></span>
                    </div>
                    <div class="col-md-6">
                        <strong>Línea:</strong> <span id="error-linea"></span>
                    </div>
                </div>
                
                <div class="mb-3">
                    <strong>Traza:</strong>
                    <pre class="p-2 bg-light rounded" id="error-traza"></pre>
                </div>
                
                <div class="mb-3">
                    <strong>Datos adicionales:</strong>
                    <pre class="p-2 bg-light rounded" id="error-datos"></pre>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <strong>IP:</strong> <span id="error-ip"></span>
                    </div>
                    <div class="col-md-6">
                        <strong>User Agent:</strong> <span id="error-useragent"></span>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Seleccionar/deseleccionar todos los checkboxes
    document.getElementById('selectAll').addEventListener('change', function() {
        var checkboxes = document.querySelectorAll('.error-checkbox');
        checkboxes.forEach(function(checkbox) {
            checkbox.checked = this.checked;
        }.bind(this));
    });
    
    // Verificar si todos los checkboxes están seleccionados
    var checkboxes = document.querySelectorAll('.error-checkbox');
    checkboxes.forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            var allChecked = true;
            checkboxes.forEach(function(cb) {
                if (!cb.checked) allChecked = false;
            });
            document.getElementById('selectAll').checked = allChecked;
        });
    });
    
    // Manejar el modal de detalles de error
    document.querySelectorAll('.show-details').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            var errorData = JSON.parse(this.getAttribute('data-error'));
            
            // Formatear fecha
            document.getElementById('error-fecha').textContent = new Date(errorData.fecha).toLocaleString();
            
            // Mostrar nivel con badge de color
            var nivelBadge = document.getElementById('error-nivel');
            nivelBadge.textContent = errorData.nivel;
            nivelBadge.className = 'badge bg-' + 
                (errorData.nivel === 'CRITICAL' ? 'danger' : 
                 errorData.nivel === 'ERROR' ? 'warning' : 
                 errorData.nivel === 'WARNING' ? 'info' : 
                 errorData.nivel === 'INFO' ? 'primary' : 'secondary');
            
            // Mostrar el resto de los datos
            document.getElementById('error-usuario').textContent = errorData.usuario_nombre || 'Sistema';
            document.getElementById('error-mensaje').textContent = errorData.mensaje;
            document.getElementById('error-archivo').textContent = errorData.archivo || 'N/A';
            document.getElementById('error-linea').textContent = errorData.linea || 'N/A';
            document.getElementById('error-traza').textContent = errorData.traza || 'No disponible';
            document.getElementById('error-datos').textContent = errorData.datos_adicionales ? 
                (typeof errorData.datos_adicionales === 'string' ? errorData.datos_adicionales : 
                JSON.stringify(errorData.datos_adicionales, null, 2)) : 'No disponible';
            document.getElementById('error-ip').textContent = errorData.ip || 'N/A';
            document.getElementById('error-useragent').textContent = errorData.user_agent || 'N/A';
        });
    });
    
    // Activar el tab desde la URL
    if (window.location.hash) {
        var tabTrigger = new bootstrap.Tab(document.querySelector(window.location.hash + '-tab'));
        tabTrigger.show();
    }
    
    // Actualizar URL cuando se cambia de tab
    var tabEls = document.querySelectorAll('button[data-bs-toggle="tab"]');
    tabEls.forEach(function(tabEl) {
        tabEl.addEventListener('click', function(event) {
            history.pushState(null, null, '#' + event.target.getAttribute('data-bs-target').substring(1));
        });
    });
    
    // Prevenir el envío del formulario cuando se hace clic en el botón de detalles
    document.getElementById('deleteForm').addEventListener('submit', function(e) {
        if (e.submitter && e.submitter.classList.contains('show-details')) {
            e.preventDefault();
            return false;
        }
    });
});

function confirmDelete() {
    var selectedErrors = [];
    document.querySelectorAll('.error-checkbox:checked').forEach(function(checkbox) {
        selectedErrors.push(checkbox.value);
    });
    
    if (selectedErrors.length === 0) {
        alert('Por favor seleccione al menos un error para eliminar');
        return;
    }
    
    if (confirm('¿Está seguro que desea eliminar los errores seleccionados?')) {
        document.getElementById('selectedErrorsInput').value = JSON.stringify(selectedErrors);
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php
require_once __DIR__ . '/../templates/footer.php';
?>
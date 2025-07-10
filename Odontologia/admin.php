<?php
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
include __DIR__ . '/templates/navbar.php';

if (!isset($_SESSION['usuario'])) {
    ob_end_clean();
    header("Location: login.php");
    exit;
}

$usuario = $_SESSION['usuario'];

if ($usuario['rol_type'] !== 'A') {
    ob_end_clean();
    header("Location: acceso_denegado.php");
    exit;
}

$por_pagina = 10;
$pagina = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
if ($pagina < 1) $pagina = 1;
$offset = ($pagina - 1) * $por_pagina;

$filtro_rol = isset($_GET['rol']) ? $_GET['rol'] : '';
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$filtro_busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

$query = "SELECT SQL_CALC_FOUND_ROWS id, email, nombre, rol_type, Estado_Sistema FROM usuario WHERE 1=1";
$params = [];

if ($filtro_rol) {
    $query .= " AND rol_type = ?";
    $params[] = $filtro_rol;
}

if ($filtro_estado) {
    $query .= " AND Estado_Sistema = ?";
    $params[] = $filtro_estado;
}

if ($filtro_busqueda) {
    $query .= " AND (nombre LIKE ? OR email LIKE ?)";
    $params[] = "%$filtro_busqueda%";
    $params[] = "%$filtro_busqueda%";
}

$query .= " ORDER BY nombre ASC LIMIT $por_pagina OFFSET $offset";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_usuarios = $conn->query("SELECT FOUND_ROWS()")->fetchColumn();
$total_paginas = ceil($total_usuarios / $por_pagina);

$roles = $conn->query("SELECT type FROM rol")->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_usuarios'])) {
    foreach ($_POST['rol'] as $userId => $nuevoRol) {
        $stmt = $conn->prepare("UPDATE usuario SET rol_type = ? WHERE id = ?");
        $stmt->execute([$nuevoRol, $userId]);
    }
    
    if (isset($_POST['estado'])) {
        foreach ($_POST['estado'] as $userId => $nuevoEstado) {
            $stmt = $conn->prepare("UPDATE usuario SET Estado_Sistema = ? WHERE id = ?");
            $stmt->execute([$nuevoEstado, $userId]);
        }
    }
    
    ob_end_clean();
    header("Location: admin.php?".http_build_query([
        'rol' => $filtro_rol,
        'estado' => $filtro_estado,
        'busqueda' => $filtro_busqueda,
        'pagina' => $pagina
    ]));
    exit;
}

$acciones = $conn->query("SELECT * FROM auth_acciones ORDER BY rol_type")->fetchAll(PDO::FETCH_ASSOC);
$secciones = $conn->query("SELECT * FROM auth_secciones ORDER BY rol_type")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_permisos'])) {
    $allAcciones = $conn->query("SELECT id FROM auth_acciones")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($allAcciones as $id) {
        $crear = isset($_POST['acciones'][$id]['crear']) ? 1 : 0;
        $ver = isset($_POST['acciones'][$id]['ver']) ? 1 : 0;
        $editar = isset($_POST['acciones'][$id]['editar']) ? 1 : 0;
        $eliminar = isset($_POST['acciones'][$id]['eliminar']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE auth_acciones SET auth_crear=?, auth_ver=?, auth_editar=?, auth_eliminar=? WHERE id=?");
        $stmt->execute([$crear, $ver, $editar, $eliminar, $id]);
    }

    $allSecciones = $conn->query("SELECT id FROM auth_secciones")->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($allSecciones as $id) {
        $paciente = isset($_POST['secciones'][$id]['paciente']) ? 1 : 0;
        $citas = isset($_POST['secciones'][$id]['citas']) ? 1 : 0;
        $atender_citas = isset($_POST['secciones'][$id]['atender_citas']) ? 1 : 0;
        $odontograma = isset($_POST['secciones'][$id]['odontograma']) ? 1 : 0;
        $historia_medica = isset($_POST['secciones'][$id]['historia_medica']) ? 1 : 0;
        $horarios = isset($_POST['secciones'][$id]['horarios']) ? 1 : 0;
        $inventario = isset($_POST['secciones'][$id]['inventario']) ? 1 : 0;
        
        $stmt = $conn->prepare("UPDATE auth_secciones SET auth_paciente=?, auth_citas=?, auth_atender_citas=?, auth_odontograma=?, auth_historia_medica=?, auth_horarios=?, auth_inventario=? WHERE id=?");
        $stmt->execute([$paciente, $citas, $atender_citas, $odontograma, $historia_medica, $horarios, $inventario, $id]);
    }

    ob_end_clean();
    header("Location: admin.php?".http_build_query([
        'rol' => $filtro_rol,
        'estado' => $filtro_estado,
        'busqueda' => $filtro_busqueda,
        'pagina' => $pagina
    ]));
    exit;
}

ob_end_flush();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrador de Roles y Permisos</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .card { margin-bottom: 30px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); }
        .table-responsive { overflow-x: auto; }
        .form-check-input { transform: scale(1.5); margin: 0 auto; }
        .btn-submit { width: 100%; padding: 10px; font-weight: bold; }
        .badge-inactivo { background-color: #6c757d; transition: box-shadow 0.3s ease-in-out; }
        .badge-inactivo:hover { box-shadow: 0 0 10px #6c757d; }
        .badge-activo { background-color: #198754; transition: box-shadow 0.3s ease-in-out; }
        .badge-activo:hover { box-shadow: 0 0 10px #198754; }
        .pagination .page-item.active .page-link { background-color: #0d6efd; border-color: #0d6efd; }
        .badge-admin { background-color: #dc3545; transition: box-shadow 0.3s ease-in-out; }
        .badge-admin:hover { box-shadow: 0 0 10px #dc3545; }
        .badge-dentista { background-color: #0dcaf0; transition: box-shadow 0.3s ease-in-out; }
        .badge-dentista:hover { box-shadow: 0 0 10px #0dcaf0; }
        .badge-secretaria { background-color: #ffc107; color: #000; transition: box-shadow 0.3s ease-in-out; }
        .badge-secretaria:hover { box-shadow: 0 0 10px #ffc107; }
        .badge-paciente { background-color: #20c997; transition: box-shadow 0.3s ease-in-out; }
        .badge-paciente:hover { box-shadow: 0 0 10px #20c997; }
        .search-box { position: relative; }
        .search-box .clear-search { 
            position: absolute; right: 10px; top: 50%; 
            transform: translateY(-50%); cursor: pointer; color: #6c757d; 
        }
        .search-box .clear-search:hover { color: #dc3545; }
    </style>
</head>
<body>
<div class="container py-5">
        <div class="row mb-4">
            <div class="col-12">
                <h1 class="text-center mb-4"><i class="bi bi-shield-lock"></i> Panel de Administración</h1>
            </div>
        </div>
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h3 class="h5 mb-0"><i class="bi bi-funnel"></i> Filtros</h3>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="rol" class="form-label">Rol</label>
                                <select name="rol" id="rol" class="form-select">
                                    <option value="">Todos los roles</option>
                                    <?php foreach ($roles as $r): ?>
                                        <option value="<?= $r ?>" <?= $filtro_rol === $r ? 'selected' : '' ?>>
                                            <?= match($r) {
                                                'A' => 'Administrador',
                                                'O' => 'Odontologo',
                                                'S' => 'Secretaría',
                                                'U' => 'Paciente',
                                                default => $r
                                            } ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label for="estado" class="form-label">Estado</label>
                                <select name="estado" id="estado" class="form-select">
                                    <option value="">Todos los estados</option>
                                    <option value="Activo" <?= $filtro_estado === 'Activo' ? 'selected' : '' ?>>Activo</option>
                                    <option value="Inactivo" <?= $filtro_estado === 'Inactivo' ? 'selected' : '' ?>>Inactivo</option>
                                </select>
                            </div>
                            <div class="col-md-4 search-box">
                                <label for="busqueda" class="form-label">Buscar</label>
                                <div class="position-relative">
                                    <input type="text" name="busqueda" id="busqueda" class="form-control" 
                                           placeholder="Nombre o email..." value="<?= htmlspecialchars($filtro_busqueda) ?>">
                                    <?php if ($filtro_busqueda): ?>
                                        <span class="clear-search" onclick="document.getElementById('busqueda').value='';this.parentNode.parentNode.parentNode.submit();">
                                            <i class="bi bi-x-circle"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-filter"></i> Filtrar
                                </button>
                                <a href="restauracion.php" class="btn btn-outline-secondary ms-2">
                                    <i class="bi bi-arrow-counterclockwise"></i> Limpiar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h3 class="h5 mb-0"><i class="bi bi-people-fill"></i> Administrar Usuarios</h3>
                        <span class="badge bg-light text-dark">
                            Mostrando <?= count($usuarios) ?> de <?= $total_usuarios ?> usuarios
                        </span>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Usuario</th>
                                            <th>Correo</th>
                                            <th>Estado</th>
                                            <th>Rol Actual</th>
                                            <th> Rol</th>
                                            <th>Cambiar Estado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($usuarios)): ?>
                                            <tr>
                                                <td colspan="6" class="text-center py-4">
                                                    <i class="bi bi-exclamation-circle fs-1 text-muted"></i>
                                                    <p class="mt-2">No se encontraron usuarios con los filtros aplicados</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($usuarios as $u): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($u['nombre']) ?></td>
                                                <td><?= htmlspecialchars($u['email']) ?></td>
                                                <td>
                                                    <span class="badge <?= $u['Estado_Sistema'] === 'Activo' ? 'badge-activo' : 'badge-inactivo' ?>">
                                                        <?= $u['Estado_Sistema'] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge <?= match($u['rol_type']) {
                                                        'A' => 'badge-admin',
                                                        'O' => 'badge-dentista',
                                                        'S' => 'badge-secretaria',
                                                        'U' => 'badge-paciente',
                                                        default => 'bg-secondary'
                                                    } ?>">
                                                        <?= match($u['rol_type']) {
                                                            'A' => 'Administrador',
                                                            'O' => 'Odontologo',
                                                            'S' => 'Secretaría',
                                                            'U' => 'Usuario',
                                                            default => $u['rol_type']
                                                        } ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <select name="rol[<?= $u['id'] ?>]" class="form-select">
                                                        <?php foreach ($roles as $r): ?>
                                                            <option value="<?= $r ?>" <?= $u['rol_type'] === $r ? 'selected' : '' ?>>
                                                                <?= match($r) {
                                                                    'A' => 'Administrador',
                                                                    'O' => 'Odontologo',
                                                                    'S' => 'Secretaría',
                                                                    'U' => 'Paciente',
                                                                    default => $r
                                                                } ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                                <td>
                                                    <select name="estado[<?= $u['id'] ?>]" class="form-select">
                                                        <option value="Activo" <?= $u['Estado_Sistema'] === 'Activo' ? 'selected' : '' ?>>Activo</option>
                                                        <option value="Inactivo" <?= $u['Estado_Sistema'] === 'Inactivo' ? 'selected' : '' ?>>Inactivo</option>
                                                    </select>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>

                            <?php if ($total_paginas > 1): ?>
                            <nav aria-label="Page navigation" class="mt-4">
                                <ul class="pagination justify-content-center">
                                    <?php if ($pagina > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => 1])) ?>" aria-label="Primera">
                                                <span aria-hidden="true"><i class="bi bi-chevron-double-left"></i></span>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina - 1])) ?>" aria-label="Anterior">
                                                <span aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
                                            </a>
                                        </li>
                                    <?php endif; ?>

                                    <?php 
                                    $inicio = max(1, $pagina - 2);
                                    $fin = min($total_paginas, $pagina + 2);
                                    
                                    if ($inicio > 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    
                                    for ($i = $inicio; $i <= $fin; $i++): ?>
                                        <li class="page-item <?= $i === $pagina ? 'active' : '' ?>">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; 
                                    
                                    if ($fin < $total_paginas) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    ?>

                                    <?php if ($pagina < $total_paginas): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina + 1])) ?>" aria-label="Siguiente">
                                                <span aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['pagina' => $total_paginas])) ?>" aria-label="Última">
                                                <span aria-hidden="true"><i class="bi bi-chevron-double-right"></i></span>
                                            </a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                            <?php endif; ?>

                            <?php if (!empty($usuarios)): ?>
                            <button type="submit" name="actualizar_usuarios" class="btn btn-primary btn-submit mt-3">
                                <i class="bi bi-save"></i> Guardar Cambios
                            </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h3 class="h5 mb-0"><i class="bi bi-key-fill"></i> Permisos por Acción</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Rol</th>
                                            <th>Crear</th>
                                            <th>Ver</th>
                                            <th>Editar</th>
                                            <th>Eliminar</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($acciones as $a): ?>
                                        <tr>
                                            <td>
                                                <span class="badge <?= match($a['rol_type']) {
                                                    'A' => 'badge-admin',
                                                    'O' => 'badge-dentista',
                                                    'S' => 'badge-secretaria',
                                                    'U' => 'badge-paciente',
                                                    default => 'bg-secondary'
                                                } ?>">
                                                    <?= match($a['rol_type']) {
                                                        'A' => 'Administrador',
                                                        'O' => 'Odontologo',
                                                        'S' => 'Secretaría',
                                                        'U' => 'Paciente',
                                                        default => $a['rol_type']
                                                    } ?>
                                                </span>
                                            </td>
                                            <?php foreach (['crear', 'ver', 'editar', 'eliminar'] as $perm): ?>
                                                <td class="text-center">
                                                    <div class="form-check d-inline-block">
                                                        <input class="form-check-input" type="checkbox" name="acciones[<?= $a['id'] ?>][<?= $perm ?>]" <?= $a["auth_$perm"] ? 'checked' : '' ?>>
                                                    </div>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <h4 class="mt-5 mb-3"><i class="bi bi-collection"></i> Permisos por Sección</h4>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Rol</th>
                                            <th>Paciente</th>
                                            <th>Agendar Citas</th>
                                            <th>Atender Citas</th>
                                            <th>Odontograma</th>
                                            <th>Historia Médica</th>
                                            <th>Horarios</th>
                                            <th>Inventario</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($secciones as $s): ?>
                                        <tr>
                                            <td>
                                                <span class="badge <?= match($s['rol_type']) {
                                                    'A' => 'badge-admin',
                                                    'O' => 'badge-dentista',
                                                    'S' => 'badge-secretaria',
                                                    'U' => 'badge-paciente',
                                                    default => 'bg-secondary'
                                                } ?>">
                                                    <?= match($s['rol_type']) {
                                                        'A' => 'Administrador',
                                                        'O' => 'Odontologo',
                                                        'S' => 'Secretaría',
                                                        'U' => 'Paciente',
                                                        default => $s['rol_type']
                                                    } ?>
                                                </span>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input class="form-check-input" type="checkbox" name="secciones[<?= $s['id'] ?>][paciente]" <?= $s["auth_paciente"] ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input class="form-check-input" type="checkbox" name="secciones[<?= $s['id'] ?>][citas]" <?= $s["auth_citas"] ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input class="form-check-input" type="checkbox" name="secciones[<?= $s['id'] ?>][atender_citas]" <?= $s["auth_atender_citas"] ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input class="form-check-input" type="checkbox" name="secciones[<?= $s['id'] ?>][odontograma]" <?= $s["auth_odontograma"] ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input class="form-check-input" type="checkbox" name="secciones[<?= $s['id'] ?>][historia_medica]" <?= $s["auth_historia_medica"] ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input class="form-check-input" type="checkbox" name="secciones[<?= $s['id'] ?>][horarios]" <?= $s["auth_horarios"] ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                            <td class="text-center">
                                                <div class="form-check d-inline-block">
                                                    <input class="form-check-input" type="checkbox" name="secciones[<?= $s['id'] ?>][inventario]" <?= $s["auth_inventario"] ? 'checked' : '' ?>>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <button type="submit" name="actualizar_permisos" class="btn btn-success btn-submit mt-3">
                                <i class="bi bi-save"></i> Actualizar Permisos
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.querySelectorAll('.clear-search').forEach(btn => {
            btn.addEventListener('click', function() {
                this.parentNode.querySelector('input').value = '';
                this.parentNode.parentNode.submit();
            });
        });
    </script>
</body>
</html>
<?php
$page_title = "Gestión de Odontogramas";

require_once __DIR__ . '/odontograma_controller.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Usuario.php';
require_once __DIR__ . '/../includes/error_handler.php';
include '../templates/navbar.php';

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

if (!in_array($usuario_actual->rol_type, ['A', 'O', 'S'])) {
    $errorHandler->logError('WARNING', "Intento de acceso no autorizado a odontogramas por usuario ID: {$usuario_actual->id}");
    header("Location: /Odontologia/templates/unauthorized.php");
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pacientes = [];
try {
    $stmt = $db->prepare("SELECT id, nombre, cedula FROM paciente WHERE Estado_Sistema = 'Activo' ORDER BY nombre");
    $stmt->execute();
    $pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorHandler->logError('ERROR', "Error al obtener pacientes: " . $e->getMessage());
    $_SESSION['error'] = "Error al cargar la lista de pacientes";
}

$paciente_id = $_GET['paciente_id'] ?? null;
$paciente = null;
$historia_medica = null;
$anamnesis = null;
$odontogramas = [];
$odontograma_cargado = null;
$dientes_cargados = [];
$citas_disponibles = [];

if ($paciente_id) {
    try {
        $stmt = $db->prepare("SELECT * FROM paciente WHERE id = ? AND Estado_Sistema = 'Activo'");
        $stmt->execute([$paciente_id]);
        $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($paciente) {
            $stmt = $db->prepare("
                SELECT h.*, u.nombre as examinador_nombre 
                FROM historia_medica h
                LEFT JOIN usuario u ON h.examinador_id = u.id
                WHERE h.paciente_id = ? AND h.Estado_Sistema = 'Activo'
                ORDER BY h.fecha_creacion DESC LIMIT 1
            ");
            $stmt->execute([$paciente_id]);
            $historia_medica = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($historia_medica) {
                $stmt = $db->prepare("SELECT * FROM anamnesis WHERE historia_medica_id = ?");
                $stmt->execute([$historia_medica['id']]);
                $anamnesis = $stmt->fetch(PDO::FETCH_ASSOC);

                try {
                    $stmt = $db->prepare("
                        SELECT o.*, 
                               (SELECT nombre FROM usuario WHERE id = (
                                   SELECT examinador_id FROM historia_medica WHERE id = o.historia_medica_id
                               )) as examinador_nombre
                        FROM odontograma o
                        WHERE o.historia_medica_id = ? AND o.Estado_Sistema = 'Activo'
                        ORDER BY o.fecha_creacion DESC
                    ");
                    $stmt->execute([$historia_medica['id']]);
                    $odontogramas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } catch (Exception $e) {
                    $errorHandler->logError('ERROR', "Error al obtener odontogramas: " . $e->getMessage());
                    $_SESSION['error'] = "Error al cargar los odontogramas";
                    $odontogramas = [];
                }

                $stmt = $db->prepare("
                    SELECT c.id, c.fecha, c.hora, u.nombre as odontologo_nombre
                    FROM citas c
                    JOIN usuario u ON c.usuario_id = u.id
                    WHERE c.paciente_id = ? AND c.estado = 'completada'
                    AND c.id NOT IN (SELECT cita_id FROM odontograma WHERE cita_id IS NOT NULL)
                    ORDER BY c.fecha DESC, c.hora DESC
                ");
                $stmt->execute([$paciente_id]);
                $citas_disponibles = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $odontograma_id = $_GET['odontograma_id'] ?? null;
                if ($odontograma_id) {
                    $controller = new OdontogramaController();
                    $result = $controller->obtenerOdontograma($odontograma_id);
                    
                    if ($result['success']) {
                        $odontograma_cargado = $result['odontograma'];
                        $dientes_cargados = $result['dientes'];
                        
                        foreach ($dientes_cargados as &$diente) {
                            $diente['ausente'] = (bool)$diente['ausente'];
                            $diente['fractura'] = (bool)$diente['fractura'];
                            $diente['caries'] = (bool)$diente['caries'];
                            $diente['corona'] = (bool)$diente['corona'];
                            $diente['puente'] = isset($diente['protesis_fija']) ? (bool)$diente['protesis_fija'] : false;
                            $diente['implante'] = (bool)$diente['implante'];
                        }
                    } else {
                        $_SESSION['error'] = $result['error'] ?? "Error al cargar el odontograma";
                    }
                }
            }
        } else {
            $_SESSION['error'] = "Paciente no encontrado";
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }
    } catch (Exception $e) {
        $errorHandler->logError('ERROR', "Error al cargar datos del paciente: " . $e->getMessage());
        $_SESSION['error'] = "Error al cargar los datos del paciente";
    }
}

$paciente_data = [];
if ($paciente && is_array($paciente)) {
    $paciente_data = [
        'nombre' => htmlspecialchars($paciente['nombre'] ?? '', ENT_QUOTES, 'UTF-8'),
        'cedula' => htmlspecialchars($paciente['cedula'] ?? '', ENT_QUOTES, 'UTF-8'),
        'edad' => htmlspecialchars($paciente['edad'] ?? '', ENT_QUOTES, 'UTF-8'),
        'sexo' => htmlspecialchars($paciente['sexo'] ?? '', ENT_QUOTES, 'UTF-8'),
        'telefono' => htmlspecialchars($paciente['telefono'] ?? '', ENT_QUOTES, 'UTF-8')
    ];
}

$historia_data = [];
if ($historia_medica && is_array($historia_medica)) {
    $historia_data = [
        'fecha_creacion' => isset($historia_medica['fecha_creacion']) ? date('d/m/Y H:i', strtotime($historia_medica['fecha_creacion'])) : '',
        'examinador_nombre' => htmlspecialchars($historia_medica['examinador_nombre'] ?? 'No especificado', ENT_QUOTES, 'UTF-8')
    ];
}

$condiciones = [];
if ($anamnesis && is_array($anamnesis)) {
    if ($anamnesis['diabetes']) $condiciones[] = '<span class="badge bg-danger diagnostico-badge">Diabetes</span>';
    if ($anamnesis['hipertension']) $condiciones[] = '<span class="badge bg-warning text-dark diagnostico-badge">Hipertensión</span>';
    if ($anamnesis['alergias']) $condiciones[] = '<span class="badge bg-info diagnostico-badge">Alergias</span>';
    if ($anamnesis['hemorragias']) $condiciones[] = '<span class="badge bg-danger diagnostico-badge">Hemorragias</span>';
    if ($anamnesis['hepatitis']) $condiciones[] = '<span class="badge bg-warning text-dark diagnostico-badge">Hepatitis</span>';
}

$odontogramas_mostrar = [];
foreach ($odontogramas as $odo) {
    if (!is_array($odo)) continue;
    
    $odontogramas_mostrar[] = [
        'id' => $odo['id'],
        'fecha_creacion' => isset($odo['fecha_creacion']) ? date('d/m/Y H:i', strtotime($odo['fecha_creacion'])) : '',
        'observaciones' => !empty($odo['observaciones']) ? htmlspecialchars($odo['observaciones'], ENT_QUOTES, 'UTF-8') : 'Sin observaciones',
        'cita_id' => $odo['cita_id'] ?? null,
    ];
}

function getSectionClass($diente_data, $seccion) {
    if (!$diente_data || !isset($diente_data['secciones'])) return '';
    
    $secciones = is_array($diente_data['secciones']) ? 
        $diente_data['secciones'] : 
        json_decode($diente_data['secciones'], true);
    
    if (!$secciones || !isset($secciones[$seccion])) return '';
    
    return 'click-' . $secciones[$seccion];
}

function getCenterClass($diente_data) {
    if (!$diente_data) return '';
    
    $classes = [];
    if ($diente_data['ausente']) $classes[] = 'tooth-absent';
    if ($diente_data['fractura']) $classes[] = 'click-red';
    if ($diente_data['caries']) $classes[] = 'click-blue';
    if ($diente_data['corona']) $classes[] = 'click-yellow';
    if ($diente_data['puente']) $classes[] = 'puente-diente';
    if ($diente_data['implante']) $classes[] = 'click-green';
    
    // Añadir clases de sección central si existen
    if (isset($diente_data['secciones']['centro'])) {
        $classes[] = 'click-' . $diente_data['secciones']['centro'];
    }
    
    return implode(' ', $classes);
}

function generateCuadranteHtml($cuadrante, $start, $end, $dientes_cargados, $suffix = '') {
    $html = '';
    $step = $start <= $end ? 1 : -1;
    
    for ($i = $start; $step > 0 ? $i <= $end : $i >= $end; $i += $step) {
        $diente_id = $cuadrante . $i;
        $diente_data = array_filter($dientes_cargados, function($d) use ($diente_id) {
            return $d['numero_diente'] === $diente_id;
        });
        $diente_data = !empty($diente_data) ? array_values($diente_data)[0] : null;
        
        $html .= '
        <div class="diente">
            <span class="tooth-label">'.$diente_id.'</span>
            <div class="cuadro top '.getSectionClass($diente_data, 'superior').'" id="t'.$diente_id.$suffix.'"></div>
            <div class="cuadro left '.getSectionClass($diente_data, 'izquierda').'" id="l'.$diente_id.$suffix.'"></div>
            <div class="cuadro bottom '.getSectionClass($diente_data, 'inferior').'" id="b'.$diente_id.$suffix.'"></div>
            <div class="cuadro right '.getSectionClass($diente_data, 'derecha').'" id="r'.$diente_id.$suffix.'"></div>
            <div class="center '.getCenterClass($diente_data).'" id="c'.$diente_id.$suffix.'"></div>
        </div>';
    }
    
    return $html;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .diente {
            position: relative;
            display: inline-block;
            margin: 10px;
            width: 60px;
            height: 80px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .diente:hover {
            transform: scale(1.05);
        }
        .cuadro {
            background-color: #FFFFFF;
            border: 1px solid #7F7F7F;
            position: absolute;
            width: 25px;
            height: 15px;
            transition: all 0.3s ease;
            box-sizing: border-box;
        }
        .top {
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            border-radius: 80px 80px 0 0;
        }
        .left {
            top: 50%;
            left: 0;
            transform: translateY(-50%) rotate(-90deg);
            transform-origin: left center;
            border-radius: 80px 80px 0 0;
        }
        .right {
            top: 50%;
            right: 0;
            transform: translateY(-50%) rotate(90deg);
            transform-origin: right center;
            border-radius: 80px 80px 0 0;
        }
        .bottom {
            bottom: 0;
            left: 50%;
            transform: translateX(-50%) rotate(180deg);
            border-radius: 80px 80px 0 0;
        }
        .center {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: #F3F3F3;
            border: 1px solid #7F7F7F;
            z-index: 5;
        }
        .click-red {
            background-color: #ff6b6b !important;
            border-color: #d63031 !important;
        }
        .click-blue {
            background-color: #74b9ff !important;
            border-color: #0984e3 !important;
        }
        .click-yellow {
            background-color: #fdcb6e !important;
            border: 1px solid #e17055 !important;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.2);
        }
        .click-yellow.center {
            background: radial-gradient(circle, #fdcb6e 0%, #e17055 100%) !important;
        }
        .click-green {
            background-color: #55efc4 !important;
            border-color: #00b894 !important;
        }
        .click-black {
            background-color: #2d3436 !important;
            border-color: #000 !important;
        }
        .click-gray {
            background-color: #b2bec3 !important;
            border-color: #636e72 !important;
        }
        .tooth-absent {
            background-color: #b2bec3 !important;
            opacity: 0.7;
        }
        .puente-diente {
            border: 2px solid #2d3436 !important;
            position: relative;
            background-color: #f8f9fa !important;
        }
        .puente-diente:after {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 15px;
            height: 15px;
            border-radius: 50%;
            background-color: rgba(45, 52, 54, 0.3);
        }
        .puente-conexion {
            position: absolute;
            height: 4px;
            background-color: #2d3436;
            z-index: 10;
            transform-origin: left center;
            border-radius: 2px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
            pointer-events: none;
        }
        .tooth-label {
            position: absolute;
            font-size: 12px;
            font-weight: bold;
            top: -20px;
            left: 50%;
            transform: translateX(-50%);
            width: 100%;
            text-align: center;
        }
        .cuadrante-title {
            background-color: #f1f1f1;
            padding: 5px 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            text-align: center;
            font-weight: bold;
        }
        .patient-info, .medical-info {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .patient-info {
            background-color: #f8f9fa;
            border-left: 4px solid #0d6efd;
        }
        .medical-info {
            background-color: #e9f7ef;
            border-left: 4px solid #198754;
        }
        .diagnostico-tooltip {
            position: absolute;
            background-color: #333;
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            z-index: 100;
            display: none;
            min-width: 120px;
            text-align: center;
            pointer-events: none;
        }
        .diagnostico-badge {
            font-size: 0.8em;
            margin-right: 3px;
            margin-bottom: 3px;
        }
        .action-btn.active {
            transform: scale(1.05);
            box-shadow: 0 0 5px rgba(0,0,0,0.2);
        }
        .diente-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mt-3" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i>
                <?= is_string($_SESSION['error']) ? htmlspecialchars($_SESSION['error'], ENT_QUOTES, 'UTF-8') : 'Error desconocido' ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show mt-3" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?= is_string($_SESSION['success']) ? htmlspecialchars($_SESSION['success'], ENT_QUOTES, 'UTF-8') : 'Operación exitosa' ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
            </div>
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <h3 class="card-title mb-0">
                    <i class="fas fa-tooth me-2"></i>Gestión de Odontogramas
                </h3>
            </div>
            
            <div class="card-body">
                <form method="get" class="mb-4">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-8">
                            <label for="paciente_id" class="form-label fw-bold">Seleccionar Paciente*</label>
                            <select class="form-select" id="paciente_id" name="paciente_id" required>
                                <option value="">-- Seleccione un paciente --</option>
                                <?php foreach ($pacientes as $p): ?>
                                    <?php if (!is_array($p)) continue; ?>
                                    <option value="<?= (int)$p['id'] ?>" <?= $p['id'] == $paciente_id ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($p['nombre'] . ' (C.I. ' . ($p['cedula'] ?? '') . ')', ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-2"></i>Cargar Paciente
                            </button>
                        </div>
                    </div>
                </form>
                
                <?php if (!empty($paciente_data)): ?>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="patient-info">
                                <h4><i class="fas fa-user me-2"></i>Datos del Paciente</h4>
                                <div class="row">
                                    <div class="col-md-6">
                                        <p><strong><i class="fas fa-signature me-2"></i>Nombre:</strong> <?= $paciente_data['nombre'] ?></p>
                                        <p><strong><i class="fas fa-id-card me-2"></i>Cédula:</strong> <?= $paciente_data['cedula'] ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p><strong><i class="fas fa-calendar-alt me-2"></i>Edad:</strong> <?= $paciente_data['edad'] ?> años</p>
                                        <p><strong><i class="fas fa-venus-mars me-2"></i>Sexo:</strong> <?= $paciente_data['sexo'] ?></p>
                                    </div>
                                </div>
                                <p><strong><i class="fas fa-phone me-2"></i>Teléfono:</strong> <?= $paciente_data['telefono'] ?></p>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="medical-info">
                                <h4><i class="fas fa-file-medical me-2"></i>Historia Médica</h4>
                                <?php if (!empty($historia_data)): ?>
                                    <p><strong><i class="fas fa-calendar-day me-2"></i>Fecha creación:</strong> <?= $historia_data['fecha_creacion'] ?></p>
                                    <p><strong><i class="fas fa-user-md me-2"></i>Examinador:</strong> <?= $historia_data['examinador_nombre'] ?></p>
                                    
                                    <?php if (!empty($condiciones)): ?>
                                        <p class="mb-1"><strong><i class="fas fa-clipboard-list me-2"></i>Condiciones relevantes:</strong></p>
                                        <div class="d-flex flex-wrap">
                                            <?= implode(" ", $condiciones) ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-secondary diagnostico-badge">Ninguna relevante</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>No hay historia médica registrada</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h4><i class="fas fa-teeth me-2"></i>Odontogramas</h4>
                        <?php if (!empty($historia_data)): ?>
                            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCrearOdontograma">
                                <i class="fas fa-plus-circle me-2"></i>Nuevo Odontograma
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($odontogramas_mostrar)): ?>
                        <div class="table-responsive mb-4">
                            <table class="table table-striped table-hover align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th><i class="fas fa-calendar-alt me-2"></i>Fecha Creación</th>
                                        <th><i class="fas fa-comment me-2"></i>Observaciones</th>
                                        <th><i class="fas fa-calendar-check me-2"></i>Cita Asociada</th>
                                        <th><i class="fas fa-cogs me-2"></i>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($odontogramas_mostrar as $odo): ?>
                                        <tr>
                                            <td><?= $odo['fecha_creacion'] ?></td>
                                            <td><?= $odo['observaciones'] ?></td>
                                            <td>
                                                <?php if ($odo['cita_id']): ?>
                                                    <?php 
                                                    try {
                                                        $stmt = $db->prepare("SELECT c.fecha, c.hora, u.nombre as odontologo 
                                                                            FROM citas c
                                                                            JOIN usuario u ON c.usuario_id = u.id
                                                                            WHERE c.id = ?");
                                                        $stmt->execute([$odo['cita_id']]);
                                                        $cita = $stmt->fetch(PDO::FETCH_ASSOC);
                                                        if ($cita) {
                                                            echo date('d/m/Y H:i', strtotime($cita['fecha'] . ' ' . $cita['hora']));
                                                            echo '<br><small class="text-muted">' . htmlspecialchars($cita['odontologo'], ENT_QUOTES, 'UTF-8') . '</small>';
                                                        } else {
                                                            echo '<span class="text-danger">Cita no encontrada</span>';
                                                        }
                                                    } catch (Exception $e) {
                                                        echo '<span class="text-danger">Error al cargar cita</span>';
                                                    }
                                                    ?>
                                                <?php else: ?>
                                                    No asociado
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="?paciente_id=<?= $paciente_id ?>&odontograma_id=<?= $odo['id'] ?>" 
                                                       class="btn btn-primary" title="Ver odontograma">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button class="btn btn-warning" data-bs-toggle="modal" 
                                                            data-bs-target="#modalEditarOdontograma<?= $odo['id'] ?>" title="Editar">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <?php if ($usuario_actual->rol_type === 'A'): ?>
                                                        <button class="btn btn-danger" data-bs-toggle="modal" 
                                                                data-bs-target="#modalEliminarOdontograma<?= $odo['id'] ?>" title="Eliminar">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="btn btn-info" onclick="generarReporte(<?= $odo['id'] ?>, '<?= $paciente_data['nombre'] ?>')" title="Generar PDF">
                                                        <i class="fas fa-file-pdf"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>No hay odontogramas registrados para este paciente.
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($odontograma_cargado): ?>
                        <div class="card mb-4 shadow-sm">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="fas fa-tooth me-2"></i>
                                    Odontograma del <?= date('d/m/Y H:i', strtotime($odontograma_cargado['fecha_creacion'])) ?>
                                    <?php if (!empty($odontograma_cargado['observaciones'])): ?>
                                        - <?= htmlspecialchars($odontograma_cargado['observaciones'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div id="odontograma-container">
                                    <div class="text-center mb-5">
                                        <h5 class="cuadrante-title">Cuadrante Superior Derecho</h5>
                                        <div class="diente-container" id="cuadrante-1">
                                            <?= generateCuadranteHtml('1', 8, 1, $dientes_cargados) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mb-5">
                                        <h5 class="cuadrante-title">Cuadrante Superior Izquierdo</h5>
                                        <div class="diente-container" id="cuadrante-2">
                                            <?= generateCuadranteHtml('2', 1, 8, $dientes_cargados) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mb-5">
                                        <h5 class="cuadrante-title">Cuadrante Inferior Izquierdo</h5>
                                        <div class="diente-container" id="cuadrante-3">
                                            <?= generateCuadranteHtml('3', 1, 8, $dientes_cargados) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="text-center mb-5">
                                        <h5 class="cuadrante-title">Cuadrante Inferior Derecho</h5>
                                        <div class="diente-container" id="cuadrante-4">
                                            <?= generateCuadranteHtml('4', 8, 1, $dientes_cargados) ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalCrearOdontograma" tabindex="-1" aria-labelledby="modalCrearOdontogramaLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" id="formCrearOdontograma">
                    <input type="hidden" name="action" value="crear">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="paciente_id" value="<?= $paciente_id ?>">
                    <input type="hidden" name="historia_medica_id" value="<?= $historia_medica['id'] ?? '' ?>">
                    
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="modalCrearOdontogramaLabel">
                            <i class="fas fa-plus-circle me-2"></i>Nuevo Odontograma
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="cita_id" class="form-label fw-bold">
                                    <i class="fas fa-calendar-check me-2"></i>Asociar a cita
                                </label>
                                <select class="form-select" id="cita_id" name="cita_id">
                                    <option value="">-- No asociar a cita --</option>
                                    <?php foreach ($citas_disponibles as $cita): ?>
                                        <option value="<?= $cita['id'] ?>">
                                            <?= date('d/m/Y H:i', strtotime($cita['fecha'] . ' ' . $cita['hora'])) ?> - 
                                            <?= htmlspecialchars($cita['odontologo_nombre'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="observacionesCrear" class="form-label fw-bold">
                                    <i class="fas fa-comment me-2"></i>Observaciones
                                </label>
                                <textarea class="form-control" id="observacionesCrear" name="observaciones" rows="2" placeholder="Ingrese observaciones relevantes..."></textarea>
                            </div>
                        </div>
                        
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <h5 class="mb-0">
                                    <i class="fas fa-tools me-2"></i>Controles del Odontograma
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="d-flex flex-wrap justify-content-center">
                                    <button type="button" class="btn btn-outline-danger btn-sm m-1 action-btn" data-action="fractura" title="Fractura/Carie">
                                        <i class="fas fa-crack me-1"></i> Fractura/Carie
                                    </button>
                                    <button type="button" class="btn btn-outline-primary btn-sm m-1 action-btn" data-action="restauracion" title="Obturación">
                                        <i class="fas fa-plug me-1"></i> Obturación
                                    </button>
                                    <button type="button" class="btn btn-outline-warning btn-sm m-1 action-btn" data-action="corona" title="Corona">
                                        <i class="fas fa-crown me-1"></i> Corona
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm m-1 action-btn" data-action="ausente" title="Ausente">
                                        <i class="fas fa-tooth me-1"></i> Ausente
                                    </button>
                                    <button type="button" class="btn btn-outline-info btn-sm m-1 action-btn" data-action="puente" title="Puente">
                                        <i class="fas fa-bridge me-1"></i> Puente
                                    </button>
                                    <button type="button" class="btn btn-outline-success btn-sm m-1 action-btn" data-action="implante" title="Implante">
                                        <i class="fas fa-teeth me-1"></i> Implante
                                    </button>
                                    <button type="button" class="btn btn-outline-dark btn-sm m-1 action-btn" data-action="gray" title="Gray">
                                        <i class="fas fa-square me-1"></i> Gray
                                    </button>
                                    <button type="button" class="btn btn-outline-dark btn-sm m-1 action-btn" data-action="borrar" title="Borrar">
                                        <i class="fas fa-eraser me-1"></i> Borrar
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div id="odontograma-crear-container">
                            <div class="text-center mb-5">
                                <h5 class="cuadrante-title">Cuadrante Superior Derecho</h5>
                                <div class="diente-container" id="cuadrante-1-crear">
                                    <?= generateCuadranteHtml('1', 8, 1, [], '-crear') ?>
                                </div>
                            </div>
                            
                            <div class="text-center mb-5">
                                <h5 class="cuadrante-title">Cuadrante Superior Izquierdo</h5>
                                <div class="diente-container" id="cuadrante-2-crear">
                                    <?= generateCuadranteHtml('2', 1, 8, [], '-crear') ?>
                                </div>
                            </div>
                            
                            <div class="text-center mb-5">
                                <h5 class="cuadrante-title">Cuadrante Inferior Izquierdo</h5>
                                <div class="diente-container" id="cuadrante-3-crear">
                                    <?= generateCuadranteHtml('3', 1, 8, [], '-crear') ?>
                                </div>
                            </div>
                            
                            <div class="text-center mb-5">
                                <h5 class="cuadrante-title">Cuadrante Inferior Derecho</h5>
                                <div class="diente-container" id="cuadrante-4-crear">
                                    <?= generateCuadranteHtml('4', 8, 1, [], '-crear') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-2"></i>Cancelar
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Guardar Odontograma
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($odontogramas_mostrar as $odo): ?>
        <div class="modal fade" id="modalEditarOdontograma<?= $odo['id'] ?>" tabindex="-1" aria-labelledby="modalEditarOdontogramaLabel<?= $odo['id'] ?>" aria-hidden="true">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <form method="POST" id="formEditarOdontograma<?= $odo['id'] ?>">
                        <input type="hidden" name="action" value="editar">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="paciente_id" value="<?= $paciente_id ?>">
                        <input type="hidden" name="historia_medica_id" value="<?= $historia_medica['id'] ?? '' ?>">
                        <input type="hidden" name="odontograma_id" value="<?= $odo['id'] ?>">
                        
                        <div class="modal-header bg-warning text-dark">
                            <h5 class="modal-title" id="modalEditarOdontogramaLabel<?= $odo['id'] ?>">
                                <i class="fas fa-edit me-2"></i>Editar Odontograma
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label for="cita_id_edit<?= $odo['id'] ?>" class="form-label fw-bold">
                                        <i class="fas fa-calendar-check me-2"></i>Asociar a cita
                                    </label>
                                    <select class="form-select" id="cita_id_edit<?= $odo['id'] ?>" name="cita_id">
                                        <option value="">-- No asociar a cita --</option>
                                        <?php foreach ($citas_disponibles as $cita): ?>
                                            <option value="<?= $cita['id'] ?>" <?= $cita['id'] == $odo['cita_id'] ? 'selected' : '' ?>>
                                                <?= date('d/m/Y H:i', strtotime($cita['fecha'] . ' ' . $cita['hora'])) ?> - 
                                                <?= htmlspecialchars($cita['odontologo_nombre'], ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label for="observacionesEditar<?= $odo['id'] ?>" class="form-label fw-bold">
                                        <i class="fas fa-comment me-2"></i>Observaciones
                                    </label>
                                    <textarea class="form-control" id="observacionesEditar<?= $odo['id'] ?>" name="observaciones" rows="2"><?= htmlspecialchars($odo['observaciones'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                            </div>
                            
                            <div class="card mb-4">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0">
                                        <i class="fas fa-tools me-2"></i>Controles del Odontograma
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex flex-wrap justify-content-center">
                                        <button type="button" class="btn btn-outline-danger btn-sm m-1 action-btn" data-action="fractura" title="Fractura/Carie">
                                            <i class="fas fa-crack me-1"></i> Fractura/Carie
                                        </button>
                                        <button type="button" class="btn btn-outline-primary btn-sm m-1 action-btn" data-action="restauracion" title="Obturación">
                                            <i class="fas fa-plug me-1"></i> Obturación
                                        </button>
                                        <button type="button" class="btn btn-outline-warning btn-sm m-1 action-btn" data-action="corona" title="Corona">
                                            <i class="fas fa-crown me-1"></i> Corona
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm m-1 action-btn" data-action="ausente" title="Ausente">
                                            <i class="fas fa-tooth me-1"></i> Ausente
                                        </button>
                                        <button type="button" class="btn btn-outline-info btn-sm m-1 action-btn" data-action="puente" title="Puente">
                                            <i class="fas fa-bridge me-1"></i> Puente
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm m-1 action-btn" data-action="implante" title="Implante">
                                            <i class="fas fa-teeth me-1"></i> Implante
                                        </button>
                                        <button type="button" class="btn btn-outline-dark btn-sm m-1 action-btn" data-action="gray" title="Gray">
                                            <i class="fas fa-square me-1"></i> Gray
                                        </button>
                                        <button type="button" class="btn btn-outline-dark btn-sm m-1 action-btn" data-action="borrar" title="Borrar">
                                            <i class="fas fa-eraser me-1"></i> Borrar
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <div id="odontograma-editar-container-<?= $odo['id'] ?>">
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                <i class="fas fa-times me-2"></i>Cancelar
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Guardar Cambios
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($usuario_actual->rol_type === 'A'): ?>
            <div class="modal fade" id="modalEliminarOdontograma<?= $odo['id'] ?>" tabindex="-1" aria-labelledby="modalEliminarOdontogramaLabel<?= $odo['id'] ?>" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <form method="POST">
                            <input type="hidden" name="action" value="eliminar">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="odontograma_id" value="<?= $odo['id'] ?>">
                            <input type="hidden" name="paciente_id" value="<?= $paciente_id ?>">
                            <input type="hidden" name="historia_medica_id" value="<?= $historia_medica['id'] ?? '' ?>">
                            
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title" id="modalEliminarOdontogramaLabel<?= $odo['id'] ?>">
                                    <i class="fas fa-trash-alt me-2"></i>Eliminar Odontograma
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                            </div>
                            <div class="modal-body">
                                <p>¿Está seguro de que desea eliminar este odontograma? Esta acción no se puede deshacer.</p>
                                <p><strong>Fecha de creación:</strong> <?= date('d/m/Y H:i', strtotime($odo['fecha_creacion'])) ?></p>
                                <p><strong>Observaciones:</strong> <?= htmlspecialchars($odo['observaciones'] ?? 'Ninguna', ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="fas fa-times me-2"></i>Cancelar
                                </button>
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-trash-alt me-2"></i>Eliminar Odontograma
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
    $(document).ready(function() {
        const { jsPDF } = window.jspdf;
        var currentAction = null;
        var selectedTeeth = [];
        var bridgeConnections = [];
        
        $('.action-btn').click(function() {
            currentAction = $(this).data('action');
            $('.action-btn').removeClass('active');
            $(this).addClass('active');
            
            if (currentAction !== 'puente') {
                selectedTeeth = [];
                $('.puente-conexion').remove();
                $('.puente-diente').removeClass('puente-diente');
            }
        });
        
        $('#odontograma-crear-container .cuadro, #odontograma-crear-container .center').click(function() {
            handleToothClick($(this));
        });
        
        function handleToothClick($element) {
            if (!currentAction) {
                showAlert('Por favor seleccione una acción primero', 'warning');
                return;
            }
            
            var toothId = $element.attr('id').replace('-crear', '');
            var sectionType = '';
            
            if ($element.hasClass('top')) sectionType = 'superior';
            else if ($element.hasClass('bottom')) sectionType = 'inferior';
            else if ($element.hasClass('left')) sectionType = 'izquierda';
            else if ($element.hasClass('right')) sectionType = 'derecha';
            else if ($element.hasClass('center')) sectionType = 'centro';
            
            switch(currentAction) {
                case 'fractura':
                    toggleClass($element, 'click-red', ['click-blue', 'click-yellow', 'click-green', 'click-black', 'click-gray']);
                    break;
                    
                case 'restauracion':
                    toggleClass($element, 'click-blue', ['click-red', 'click-yellow', 'click-green', 'click-black', 'click-gray']);
                    break;
                    
                case 'corona':
                    if (sectionType === 'centro') {
                        $element.addClass('click-yellow')
                               .removeClass('click-red click-blue click-green click-black click-gray');
                        $element.siblings('.cuadro').addClass('click-yellow')
                               .removeClass('click-red click-blue click-green click-black click-gray');
                    }
                    break;
                    
                case 'ausente':
                    if (sectionType === 'centro') {
                        $element.toggleClass('tooth-absent');
                        if ($element.hasClass('tooth-absent')) {
                            $element.removeClass('click-red click-blue click-yellow click-green click-black click-gray');
                        }
                    }
                    break;
                    
                case 'puente':
                    if (sectionType === 'centro') {
                        $element.toggleClass('puente-diente');
                        
                        if ($element.hasClass('puente-diente')) {
                            selectedTeeth.push(toothId.substring(1));
                            
                            if (selectedTeeth.length >= 2) {
                                drawBridge(selectedTeeth[0], selectedTeeth[1]);
                                selectedTeeth = [];
                            }
                        } else {
                            selectedTeeth = selectedTeeth.filter(item => item !== toothId.substring(1));
                            removeBridgeConnections(toothId.substring(1));
                            removeConnectedBridge(toothId.substring(1));
                        }
                    }
                    break;
                    
                case 'implante':
                    toggleClass($element, 'click-green', ['click-red', 'click-blue', 'click-yellow', 'click-black', 'click-gray']);
                    break;
                    
                case 'gray':
                    toggleClass($element, 'click-gray', ['click-red', 'click-blue', 'click-yellow', 'click-green', 'click-black']);
                    break;
                    
                case 'borrar':
                    if (sectionType === 'centro') {
                        $element.removeClass('click-red click-blue click-yellow click-green click-black click-gray tooth-absent puente-diente');
                        removeBridgeConnections(toothId.substring(1));
                    } else {
                        $element.removeClass('click-red click-blue click-yellow click-green click-black click-gray');
                    }
                    break;
            }
        }
        
        function toggleClass(element, addClass, removeClasses) {
            $(element).toggleClass(addClass);
            removeClasses.forEach(cls => {
                $(element).removeClass(cls);
            });
        }
        
        function showAlert(message, type = 'info') {
            var alertClass = 'alert-' + type;
            var icon = '';
            
            switch(type) {
                case 'warning': icon = '<i class="fas fa-exclamation-triangle me-2"></i>'; break;
                case 'error': icon = '<i class="fas fa-times-circle me-2"></i>'; break;
                case 'success': icon = '<i class="fas fa-check-circle me-2"></i>'; break;
                default: icon = '<i class="fas fa-info-circle me-2"></i>';
            }
            
            var alertHtml = `
                <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                    ${icon}${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                </div>
            `;
            
            $('.container').prepend(alertHtml);
            
            setTimeout(() => {
                $('.alert').alert('close');
            }, 3000);
        }
        
        function drawBridge(tooth1, tooth2) {
            var $tooth1 = $('#c' + tooth1 + '-crear');
            var $tooth2 = $('#c' + tooth2 + '-crear');
            
            if ($tooth1.length && $tooth2.length) {
                var pos1 = $tooth1.offset();
                var pos2 = $tooth2.offset();
                
                var containerOffset = $('#odontograma-crear-container').offset();
                var x1 = pos1.left - containerOffset.left + $tooth1.width() / 2;
                var y1 = pos1.top - containerOffset.top + $tooth1.height() / 2;
                var x2 = pos2.left - containerOffset.left + $tooth2.width() / 2;
                var y2 = pos2.top - containerOffset.top + $tooth2.height() / 2;
                
                var distance = Math.sqrt(Math.pow(x2 - x1, 2) + Math.pow(y2 - y1, 2));
                var angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
                
                var $bridge = $('<div class="puente-conexion" data-teeth="' + tooth1 + '-' + tooth2 + '"></div>');
                $bridge.css({
                    'width': distance + 'px',
                    'left': x1 + 'px',
                    'top': y1 + 'px',
                    'transform': 'rotate(' + angle + 'deg)'
                });
                
                $('#odontograma-crear-container').append($bridge);
                bridgeConnections.push({tooth1: tooth1, tooth2: tooth2, element: $bridge});
            }
        }
        
        function removeBridgeConnections(toothNumber) {
            var connectionsToRemove = [];
            
            bridgeConnections.forEach((conn, index) => {
                if (conn.tooth1 === toothNumber || conn.tooth2 === toothNumber) {
                    conn.element.remove();
                    connectionsToRemove.push(index);
                }
            });
            
            connectionsToRemove.reverse().forEach(index => {
                bridgeConnections.splice(index, 1);
            });
        }
        
        function removeConnectedBridge(toothNumber) {
            bridgeConnections.forEach(conn => {
                if (conn.tooth1 === toothNumber) {
                    $('#c' + conn.tooth2 + '-crear').removeClass('puente-diente');
                } else if (conn.tooth2 === toothNumber) {
                    $('#c' + conn.tooth1 + '-crear').removeClass('puente-diente');
                }
            });
        }
        
        function applyOdontogramaStyles(dientes) {
            dientes.forEach(diente => {
                const toothId = diente.numero_diente;
                const $toothCenter = $('#c' + toothId);
                
                if (diente.ausente) $toothCenter.addClass('tooth-absent');
                if (diente.fractura) $toothCenter.addClass('click-red');
                if (diente.caries) $toothCenter.addClass('click-blue');
                if (diente.corona) $toothCenter.addClass('click-yellow');
                if (diente.puente) $toothCenter.addClass('puente-diente');
                if (diente.implante) $toothCenter.addClass('click-green');
                
                if (diente.secciones) {
                    const secciones = typeof diente.secciones === 'string' ? 
                        JSON.parse(diente.secciones) : diente.secciones;
                    
                    Object.entries(secciones).forEach(([seccion, color]) => {
                        let prefix = '';
                        switch(seccion) {
                            case 'superior': prefix = 't'; break;
                            case 'inferior': prefix = 'b'; break;
                            case 'izquierda': prefix = 'l'; break;
                            case 'derecha': prefix = 'r'; break;
                            case 'centro': prefix = 'c'; break;
                        }
                        
                        if (prefix) {
                            $('#' + prefix + toothId).addClass('click-' + color);
                        }
                    });
                }
            });
            
            drawExistingBridges(dientes);
        }

        function drawExistingBridges(dientes) {
            const bridgeTeeth = dientes.filter(d => d.puente).map(d => d.numero_diente);
            
            $('.puente-conexion').remove();
            
            for (let i = 0; i < bridgeTeeth.length - 1; i++) {
                for (let j = i + 1; j < bridgeTeeth.length; j++) {
                    drawExistingBridge(bridgeTeeth[i], bridgeTeeth[j]);
                }
            }
        }

        function drawExistingBridge(tooth1, tooth2) {
            var $tooth1 = $('#c' + tooth1);
            var $tooth2 = $('#c' + tooth2);
            
            if ($tooth1.length && $tooth2.length) {
                var pos1 = $tooth1.offset();
                var pos2 = $tooth2.offset();
                
                var containerOffset = $('#odontograma-container').offset();
                var x1 = pos1.left - containerOffset.left + $tooth1.width() / 2;
                var y1 = pos1.top - containerOffset.top + $tooth1.height() / 2;
                var x2 = pos2.left - containerOffset.left + $tooth2.width() / 2;
                var y2 = pos2.top - containerOffset.top + $tooth2.height() / 2;
                
                var distance = Math.sqrt(Math.pow(x2 - x1, 2) + Math.pow(y2 - y1, 2));
                var angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
                
                var $bridge = $('<div class="puente-conexion" data-teeth="' + tooth1 + '-' + tooth2 + '"></div>');
                $bridge.css({
                    'width': distance + 'px',
                    'left': x1 + 'px',
                    'top': y1 + 'px',
                    'transform': 'rotate(' + angle + 'deg)'
                });
                
                $('#odontograma-container').append($bridge);
            }
        }
        
        <?php if ($odontograma_cargado && !empty($dientes_cargados)): ?>
            applyOdontogramaStyles(<?= json_encode($dientes_cargados) ?>);
        <?php endif; ?>

        $('#formCrearOdontograma').submit(function(event) {
            event.preventDefault();
            
            var dientesData = {};
            $('#odontograma-crear-container .diente').each(function() {
                var toothId = $(this).find('.center').attr('id').replace('c', '').replace('-crear', '');
                var toothData = {
                    ausente: $(this).find('.center').hasClass('tooth-absent') ? 1 : 0,
                    fractura: 0,
                    caries: 0,
                    corona: 0,
                    puente: $(this).find('.center').hasClass('puente-diente') ? 1 : 0,
                    implante: 0,
                    secciones: {}
                };
                
                $(this).find('.cuadro, .center').each(function() {
                    var sectionId = $(this).attr('id').replace('-crear', '');
                    var sectionType = sectionId.charAt(0);
                    var sectionName = getSectionName(sectionType);
                    
                    if ($(this).hasClass('click-red')) {
                        toothData.secciones[sectionName] = 'red';
                        if (sectionType === 'c') toothData.fractura = 1;
                    } else if ($(this).hasClass('click-blue')) {
                        toothData.secciones[sectionName] = 'blue';
                        if (sectionType === 'c') toothData.caries = 1;
                    } else if ($(this).hasClass('click-yellow')) {
                        toothData.secciones[sectionName] = 'yellow';
                        if (sectionType === 'c') toothData.corona = 1;
                    } else if ($(this).hasClass('click-green')) {
                        toothData.secciones[sectionName] = 'green';
                        if (sectionType === 'c') toothData.implante = 1;
                    } else if ($(this).hasClass('click-black')) {
                        toothData.secciones[sectionName] = 'black';
                    } else if ($(this).hasClass('click-gray')) {
                        toothData.secciones[sectionName] = 'gray';
                    }
                });
                
                dientesData[toothId] = toothData;
            });
            
            $('#modalCrearOdontograma .modal-footer button[type="submit"]').html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...');
            
            $.ajax({
                url: 'odontograma_controller.php',
                type: 'POST',
                data: $(this).serialize() + '&dientes_json=' + encodeURIComponent(JSON.stringify(dientesData)),
                dataType: 'json',
                success: function(response) {
                    if (response && response.success) {
                        showAlert('Odontograma creado exitosamente', 'success');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showAlert(response.error || 'Error desconocido al guardar', 'error');
                        $('#modalCrearOdontograma .modal-footer button[type="submit"]').html('<i class="fas fa-save me-2"></i>Guardar Odontograma');
                    }
                },
                error: function(xhr, status, error) {
                    try {
                        var errResponse = JSON.parse(xhr.responseText);
                        showAlert(errResponse.error || 'Error al procesar la respuesta', 'error');
                    } catch (e) {
                        showAlert('Error en el servidor: ' + xhr.statusText, 'error');
                    }
                    $('#modalCrearOdontograma .modal-footer button[type="submit"]').html('<i class="fas fa-save me-2"></i>Guardar Odontograma');
                }
            });
        });

        $('form[id^="formEditarOdontograma"]').submit(function(event) {
            event.preventDefault();
            var formId = $(this).attr('id');
            var odontogramaId = formId.replace('formEditarOdontograma', '');
            
            var dientesData = {};
            $('#odontograma-editar-container-' + odontogramaId + ' .diente').each(function() {
                var toothId = $(this).find('.center').attr('id').replace('c', '').replace('-editar', '');
                var toothData = {
                    ausente: $(this).find('.center').hasClass('tooth-absent') ? 1 : 0,
                    fractura: 0,
                    caries: 0,
                    corona: 0,
                    puente: $(this).find('.center').hasClass('puente-diente') ? 1 : 0,
                    implante: 0,
                    secciones: {}
                };
                
                $(this).find('.cuadro, .center').each(function() {
                    var sectionId = $(this).attr('id').replace('-editar', '');
                    var sectionType = sectionId.charAt(0);
                    var sectionName = getSectionName(sectionType);
                    
                    if ($(this).hasClass('click-red')) {
                        toothData.secciones[sectionName] = 'red';
                        if (sectionType === 'c') toothData.fractura = 1;
                    } else if ($(this).hasClass('click-blue')) {
                        toothData.secciones[sectionName] = 'blue';
                        if (sectionType === 'c') toothData.caries = 1;
                    } else if ($(this).hasClass('click-yellow')) {
                        toothData.secciones[sectionName] = 'yellow';
                        if (sectionType === 'c') toothData.corona = 1;
                    } else if ($(this).hasClass('click-green')) {
                        toothData.secciones[sectionName] = 'green';
                        if (sectionType === 'c') toothData.implante = 1;
                    } else if ($(this).hasClass('click-black')) {
                        toothData.secciones[sectionName] = 'black';
                    } else if ($(this).hasClass('click-gray')) {
                        toothData.secciones[sectionName] = 'gray';
                    }
                });
                
                dientesData[toothId] = toothData;
            });
            
            $('#modalEditarOdontograma' + odontogramaId + ' .modal-footer button[type="submit"]').html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...');
            
            $.ajax({
                url: 'odontograma_controller.php',
                type: 'POST',
                data: $(this).serialize() + '&dientes_json=' + encodeURIComponent(JSON.stringify(dientesData)),
                dataType: 'json',
                success: function(response) {
                    if (response && response.success) {
                        showAlert('Odontograma actualizado exitosamente', 'success');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showAlert(response.error || 'Error desconocido al guardar', 'error');
                        $('#modalEditarOdontograma' + odontogramaId + ' .modal-footer button[type="submit"]').html('<i class="fas fa-save me-2"></i>Guardar Cambios');
                    }
                },
                error: function(xhr, status, error) {
                    try {
                        var errResponse = JSON.parse(xhr.responseText);
                        showAlert(errResponse.error || 'Error al procesar la respuesta', 'error');
                    } catch (e) {
                        showAlert('Error en el servidor: ' + xhr.statusText, 'error');
                    }
                    $('#modalEditarOdontograma' + odontogramaId + ' .modal-footer button[type="submit"]').html('<i class="fas fa-save me-2"></i>Guardar Cambios');
                }
            });
        });

        $('div[id^="modalEditarOdontograma"]').on('show.bs.modal', function(event) {
            var modalId = $(this).attr('id');
            var odontogramaId = modalId.replace('modalEditarOdontograma', '');
            var $modalBody = $(this).find('#odontograma-editar-container-' + odontogramaId);
            
            $modalBody.html('<div class="text-center py-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Cargando...</span></div></div>');
            
            $.ajax({
                url: 'odontograma_controller.php',
                type: 'POST',
                data: {
                    action: 'obtener',
                    odontograma_id: odontogramaId,
                    csrf_token: '<?= $_SESSION['csrf_token'] ?>'
                },
                dataType: 'json',
                success: function(response) {
                    if (response && response.success) {
                        var html = `
                            <div class="text-center mb-5">
                                <h5 class="cuadrante-title">Cuadrante Superior Derecho</h5>
                                <div class="diente-container">
                                    ${generateTeethHtml(response.dientes, '1', 8, 1, '-editar')}
                                </div>
                            </div>
                            <div class="text-center mb-5">
                                <h5 class="cuadrante-title">Cuadrante Superior Izquierdo</h5>
                                <div class="diente-container">
                                    ${generateTeethHtml(response.dientes, '2', 1, 8, '-editar')}
                                </div>
                            </div>
                            <div class="text-center mb-5">
                                <h5 class="cuadrante-title">Cuadrante Inferior Izquierdo</h5>
                                <div class="diente-container">
                                    ${generateTeethHtml(response.dientes, '3', 1, 8, '-editar')}
                                </div>
                            </div>
                            <div class="text-center mb-5">
                                <h5 class="cuadrante-title">Cuadrante Inferior Derecho</h5>
                                <div class="diente-container">
                                    ${generateTeethHtml(response.dientes, '4', 8, 1, '-editar')}
                                </div>
                            </div>
                        `;
                        
                        $modalBody.html(html);
                        
                        $('#odontograma-editar-container-' + odontogramaId + ' .cuadro, #odontograma-editar-container-' + odontogramaId + ' .center').click(function() {
                            handleToothEditClick($(this));
                        });
                        
                        if (response.dientes && Array.isArray(response.dientes)) {
                            response.dientes.forEach(diente => {
                                var toothId = diente.numero_diente;
                                
                                if (diente.ausente) {
                                    $('#c' + toothId + '-editar').addClass('tooth-absent');
                                }
                                
                                if (diente.fractura) {
                                    $('#c' + toothId + '-editar').addClass('click-red');
                                }
                                
                                if (diente.caries) {
                                    $('#c' + toothId + '-editar').addClass('click-blue');
                                }
                                
                                if (diente.corona) {
                                    $('#c' + toothId + '-editar').addClass('click-yellow');
                                    $('#t' + toothId + '-editar, #b' + toothId + '-editar, #l' + toothId + '-editar, #r' + toothId + '-editar').addClass('click-yellow');
                                }
                                
                                if (diente.protesis_fija) {
                                    $('#c' + toothId + '-editar').addClass('puente-diente');
                                }
                                
                                if (diente.implante) {
                                    $('#c' + toothId + '-editar').addClass('click-green');
                                }
                                
                                if (diente.secciones && typeof diente.secciones === 'object') {
                                    Object.entries(diente.secciones).forEach(([seccion, color]) => {
                                        var prefix = '';
                                        switch(seccion) {
                                            case 'superior': prefix = 't'; break;
                                            case 'inferior': prefix = 'b'; break;
                                            case 'izquierda': prefix = 'l'; break;
                                            case 'derecha': prefix = 'r'; break;
                                            case 'centro': prefix = 'c'; break;
                                        }
                                        
                                        if (prefix) {
                                            $('#' + prefix + toothId + '-editar').addClass('click-' + color);
                                        }
                                    });
                                }
                            });
                        }
                        
                        drawExistingBridges(response.dientes);
                        
                    } else {
                        $modalBody.html('<div class="alert alert-danger">' + (response.error || 'Error al cargar el odontograma') + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    $modalBody.html('<div class="alert alert-danger">Error al cargar el odontograma: ' + error + '</div>');
                }
            });
        });

        function generateTeethHtml(dientes, cuadrante, start, end, suffix) {
            var html = '';
            var step = start <= end ? 1 : -1;
            
            for (var i = start; step > 0 ? i <= end : i >= end; i += step) {
                var toothId = cuadrante + i;
                var toothData = dientes.find(d => d.numero_diente === toothId) || {};
                
                html += `
                    <div class="diente">
                        <span class="tooth-label">${toothId}</span>
                        <div class="cuadro top" id="t${toothId}${suffix}"></div>
                        <div class="cuadro left" id="l${toothId}${suffix}"></div>
                        <div class="cuadro bottom" id="b${toothId}${suffix}"></div>
                        <div class="cuadro right" id="r${toothId}${suffix}"></div>
                        <div class="center" id="c${toothId}${suffix}"></div>
                    </div>
                `;
            }
            
            return html;
        }

        function getSectionName(sectionType) {
            switch(sectionType) {
                case 't': return 'superior';
                case 'b': return 'inferior';
                case 'l': return 'izquierda';
                case 'r': return 'derecha';
                case 'c': return 'centro';
                default: return '';
            }
        }

        function handleToothEditClick($element) {
            if (!currentAction) {
                showAlert('Por favor seleccione una acción primero', 'warning');
                return;
            }
            
            var toothId = $element.attr('id').replace('-editar', '');
            var sectionType = '';
            
            if ($element.hasClass('top')) sectionType = 'superior';
            else if ($element.hasClass('bottom')) sectionType = 'inferior';
            else if ($element.hasClass('left')) sectionType = 'izquierda';
            else if ($element.hasClass('right')) sectionType = 'derecha';
            else if ($element.hasClass('center')) sectionType = 'centro';
            
            switch(currentAction) {
                case 'fractura':
                    toggleClass($element, 'click-red', ['click-blue', 'click-yellow', 'click-green', 'click-black', 'click-gray']);
                    break;
                    
                case 'restauracion':
                    toggleClass($element, 'click-blue', ['click-red', 'click-yellow', 'click-green', 'click-black', 'click-gray']);
                    break;
                    
                case 'corona':
                    if (sectionType === 'centro') {
                        $element.addClass('click-yellow')
                               .removeClass('click-red click-blue click-green click-black click-gray');
                        $element.siblings('.cuadro').addClass('click-yellow')
                               .removeClass('click-red click-blue click-green click-black click-gray');
                    }
                    break;
                    
                case 'ausente':
                    if (sectionType === 'centro') {
                        $element.toggleClass('tooth-absent');
                        if ($element.hasClass('tooth-absent')) {
                            $element.removeClass('click-red click-blue click-yellow click-green click-black click-gray');
                        }
                    }
                    break;
                    
                case 'puente':
                    if (sectionType === 'centro') {
                        $element.toggleClass('puente-diente');
                        
                        if ($element.hasClass('puente-diente')) {
                            selectedTeeth.push(toothId.substring(1));
                            
                            if (selectedTeeth.length >= 2) {
                                drawEditBridge(selectedTeeth[0], selectedTeeth[1]);
                                selectedTeeth = [];
                            }
                        } else {
                            selectedTeeth = selectedTeeth.filter(item => item !== toothId.substring(1));
                            removeEditBridgeConnections(toothId.substring(1));
                            removeConnectedEditBridge(toothId.substring(1));
                        }
                    }
                    break;
                    
                case 'implante':
                    toggleClass($element, 'click-green', ['click-red', 'click-blue', 'click-yellow', 'click-black', 'click-gray']);
                    break;
                    
                case 'gray':
                    toggleClass($element, 'click-gray', ['click-red', 'click-blue', 'click-yellow', 'click-green', 'click-black']);
                    break;
                    
                case 'borrar':
                    if (sectionType === 'centro') {
                        $element.removeClass('click-red click-blue click-yellow click-green click-black click-gray tooth-absent puente-diente');
                        removeEditBridgeConnections(toothId.substring(1));
                    } else {
                        $element.removeClass('click-red click-blue click-yellow click-green click-black click-gray');
                    }
                    break;
            }
        }

        function drawEditBridge(tooth1, tooth2) {
            var $tooth1 = $('#c' + tooth1 + '-editar');
            var $tooth2 = $('#c' + tooth2 + '-editar');
            
            if ($tooth1.length && $tooth2.length) {
                var pos1 = $tooth1.offset();
                var pos2 = $tooth2.offset();
                
                var containerOffset = $('#odontograma-editar-container').offset();
                var x1 = pos1.left - containerOffset.left + $tooth1.width() / 2;
                var y1 = pos1.top - containerOffset.top + $tooth1.height() / 2;
                var x2 = pos2.left - containerOffset.left + $tooth2.width() / 2;
                var y2 = pos2.top - containerOffset.top + $tooth2.height() / 2;
                
                var distance = Math.sqrt(Math.pow(x2 - x1, 2) + Math.pow(y2 - y1, 2));
                var angle = Math.atan2(y2 - y1, x2 - x1) * 180 / Math.PI;
                
                var $bridge = $('<div class="puente-conexion" data-teeth="' + tooth1 + '-' + tooth2 + '"></div>');
                $bridge.css({
                    'width': distance + 'px',
                    'left': x1 + 'px',
                    'top': y1 + 'px',
                    'transform': 'rotate(' + angle + 'deg)'
                });
                
                $('#odontograma-editar-container').append($bridge);
                bridgeConnections.push({tooth1: tooth1, tooth2: tooth2, element: $bridge});
            }
        }

        function removeEditBridgeConnections(toothNumber) {
            var connectionsToRemove = [];
            
            bridgeConnections.forEach((conn, index) => {
                if (conn.tooth1 === toothNumber || conn.tooth2 === toothNumber) {
                    conn.element.remove();
                    connectionsToRemove.push(index);
                }
            });
            
            connectionsToRemove.reverse().forEach(index => {
                bridgeConnections.splice(index, 1);
            });
        }

        function removeConnectedEditBridge(toothNumber) {
            bridgeConnections.forEach(conn => {
                if (conn.tooth1 === toothNumber) {
                    $('#c' + conn.tooth2 + '-editar').removeClass('puente-diente');
                } else if (conn.tooth2 === toothNumber) {
                    $('#c' + conn.tooth1 + '-editar').removeClass('puente-diente');
                }
            });
        }

        function generarReporte(odontogramaId, pacienteNombre) {
            $('#btnGenerarPdf' + odontogramaId).html('<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Generando...');
            
            const doc = new jsPDF();
            
            doc.setFontSize(18);
            doc.text('Reporte de Odontograma', 105, 20, { align: 'center' });
            
            doc.setFontSize(12);
            doc.text(`Paciente: ${pacienteNombre}`, 15, 30);
            doc.text(`Fecha: ${new Date().toLocaleDateString()}`, 15, 40);
            
            html2canvas(document.getElementById('odontograma-container')).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                doc.addImage(imgData, 'PNG', 15, 50, 180, 120);
                
                doc.save(`odontograma_${pacienteNombre}_${odontogramaId}.pdf`);
                
                $('#btnGenerarPdf' + odontogramaId).html('<i class="fas fa-file-pdf me-2"></i>Generar PDF');
            });
        }
    });
    </script>
</body>
</html>
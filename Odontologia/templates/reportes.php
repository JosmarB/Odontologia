<?php
require_once '../config/database.php'; // Archivo con la conexión a la base de datos
session_start();

// Verificar autenticación y permisos
if (!isset($_SESSION['usuario_id']) || !$_SESSION['auth_ver']) {
    header('Location: login.php');
    exit;
}

// Obtener el tipo de usuario
$rol_type = $_SESSION['rol_type'] ?? 'U';

// Funciones para generar reportes
function obtenerPacientes($filtros = []) {
    global $conn;
    $sql = "SELECT * FROM paciente WHERE 1=1";
    $params = [];
    
    if (!empty($filtros['nombre'])) {
        $sql .= " AND nombre LIKE ?";
        $params[] = '%'.$filtros['nombre'].'%';
    }
    
    if (!empty($filtros['cedula'])) {
        $sql .= " AND cedula = ?";
        $params[] = $filtros['cedula'];
    }
    
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerHistoriaMedica($paciente_id) {
    global $conn;
    $sql = "SELECT hm.*, u.nombre as examinador 
            FROM historia_medica hm
            JOIN usuario u ON hm.examinador_id = u.id
            WHERE hm.paciente_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$paciente_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerAnamnesis($historia_id) {
    global $conn;
    $sql = "SELECT * FROM anamnesis WHERE historia_medica_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$historia_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function obtenerAntecedentesFamiliares($anamnesis_id) {
    global $conn;
    $sql = "SELECT * FROM antecedentesfamiliares WHERE anamnesis_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$anamnesis_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerOdontogramas($historia_id) {
    global $conn;
    $sql = "SELECT * FROM odontograma WHERE historia_medica_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$historia_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obtenerCitasPaciente($paciente_id) {
    global $conn;
    $sql = "SELECT c.*, u.nombre as doctor 
            FROM citas c
            JOIN usuario u ON c.usuario_id = u.id
            WHERE c.paciente_id = ?
            ORDER BY c.fecha DESC, c.hora DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$paciente_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Procesar formulario de reportes
$reporte = [];
$filtros = [];
$opciones = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_reporte = $_POST['tipo_reporte'] ?? 'general';
    $filtros = $_POST['filtros'] ?? [];
    $opciones = $_POST['opciones'] ?? [];
    
    if ($tipo_reporte === 'pacientes') {
        $reporte['pacientes'] = obtenerPacientes($filtros);
        
        if (!empty($opciones['historia_medica'])) {
            foreach ($reporte['pacientes'] as &$paciente) {
                $paciente['historias_medicas'] = obtenerHistoriaMedica($paciente['id']);
                
                if (!empty($opciones['anamnesis'])) {
                    foreach ($paciente['historias_medicas'] as &$historia) {
                        $historia['anamnesis'] = obtenerAnamnesis($historia['id']);
                        
                        if (!empty($opciones['antecedentes'])) {
                            $historia['anamnesis']['antecedentes'] = 
                                obtenerAntecedentesFamiliares($historia['anamnesis']['id']);
                        }
                    }
                }
                
                if (!empty($opciones['odontogramas'])) {
                    foreach ($paciente['historias_medicas'] as &$historia) {
                        $historia['odontogramas'] = obtenerOdontogramas($historia['id']);
                    }
                }
                
                if (!empty($opciones['citas'])) {
                    $paciente['citas'] = obtenerCitasPaciente($paciente['id']);
                }
            }
        }
    }
    
    // Generar PDF si se solicita
    if (isset($_POST['generar_pdf'])) {
        require_once 'tcpdf/tcpdf.php';
        
        // Crear nuevo documento PDF
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Configurar documento
        $pdf->SetCreator('Sistema Odontológico');
        $pdf->SetAuthor('Consultorio Odontológico');
        $pdf->SetTitle('Reporte de Pacientes');
        $pdf->SetSubject('Reporte generado automáticamente');
        
        // Agregar página
        $pdf->AddPage();
        
        // Contenido del PDF
        $html = '<h1>Reporte de Pacientes</h1>';
        $html .= '<p>Fecha: '.date('d/m/Y H:i:s').'</p>';
        
        if (!empty($reporte['pacientes'])) {
            foreach ($reporte['pacientes'] as $paciente) {
                $html .= '<h2>'.$paciente['nombre'].' (C.I. '.$paciente['cedula'].')</h2>';
                $html .= '<p>Edad: '.$paciente['edad'].' - Teléfono: '.$paciente['telefono'].'</p>';
                
                if (!empty($paciente['historias_medicas'])) {
                    $html .= '<h3>Historia Médica</h3>';
                    foreach ($paciente['historias_medicas'] as $historia) {
                        $html .= '<p><strong>Fecha:</strong> '.$historia['fecha_creacion'].' - <strong>Examinador:</strong> '.$historia['examinador'].'</p>';
                        
                        if (!empty($historia['anamnesis'])) {
                            $html .= '<h4>Anamnesis</h4>';
                            $html .= '<ul>';
                            $html .= '<li>Diabetes: '.($historia['anamnesis']['diabetes'] ? 'Sí' : 'No').'</li>';
                            $html .= '<li>Hipertensión: '.($historia['anamnesis']['hipertension'] ? 'Sí' : 'No').'</li>';
                            // Agregar más campos según sea necesario
                            $html .= '</ul>';
                            
                            if (!empty($historia['anamnesis']['antecedentes'])) {
                                $html .= '<h4>Antecedentes Familiares</h4>';
                                foreach ($historia['anamnesis']['antecedentes'] as $antecedente) {
                                    $html .= '<p>'.($antecedente['tipo'] === 'P' ? 'Paternos' : ($antecedente['tipo'] === 'M' ? 'Maternos' : 'Otros')).': '.$antecedente['descripcion'].'</p>';
                                }
                            }
                        }
                        
                        if (!empty($historia['odontogramas'])) {
                            $html .= '<h4>Odontogramas</h4>';
                            foreach ($historia['odontogramas'] as $odontograma) {
                                $html .= '<p>Fecha: '.$odontograma['fecha_creacion'].'</p>';
                                if (!empty($odontograma['observaciones'])) {
                                    $html .= '<p>Observaciones: '.$odontograma['observaciones'].'</p>';
                                }
                            }
                        }
                    }
                }
                
                if (!empty($paciente['citas'])) {
                    $html .= '<h3>Citas</h3>';
                    $html .= '<table border="1" cellpadding="4">';
                    $html .= '<tr><th>Fecha</th><th>Hora</th><th>Doctor</th><th>Estado</th><th>Motivo</th></tr>';
                    foreach ($paciente['citas'] as $cita) {
                        $html .= '<tr>';
                        $html .= '<td>'.$cita['fecha'].'</td>';
                        $html .= '<td>'.$cita['hora'].'</td>';
                        $html .= '<td>'.$cita['doctor'].'</td>';
                        $html .= '<td>'.$cita['estado'].'</td>';
                        $html .= '<td>'.$cita['motivo'].'</td>';
                        $html .= '</tr>';
                    }
                    $html .= '</table>';
                }
                
                $html .= '<hr>';
            }
        } else {
            $html .= '<p>No se encontraron pacientes con los filtros aplicados.</p>';
        }
        
        // Escribir contenido HTML
        $pdf->writeHTML($html, true, false, true, false, '');
        
        // Salida del PDF
        $pdf->Output('reporte_pacientes_'.date('Ymd_His').'.pdf', 'D');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema Odontológico - Reportes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .reporte-options {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        .opciones-avanzadas {
            margin-top: 15px;
            padding: 15px;
            background-color: #e9ecef;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1 class="mb-4">Generador de Reportes</h1>
        
        <div class="row">
            <div class="col-md-4">
                <div class="reporte-options">
                    <form method="post" id="reporteForm">
                        <div class="mb-3">
                            <label for="tipo_reporte" class="form-label">Tipo de Reporte</label>
                            <select class="form-select" id="tipo_reporte" name="tipo_reporte">
                                <option value="general" <?= ($_POST['tipo_reporte'] ?? '') === 'general' ? 'selected' : '' ?>>General</option>
                                <option value="pacientes" <?= ($_POST['tipo_reporte'] ?? '') === 'pacientes' ? 'selected' : '' ?>>Pacientes</option>
                                <?php if ($rol_type === 'A' || $rol_type === 'O'): ?>
                                <option value="doctores" <?= ($_POST['tipo_reporte'] ?? '') === 'doctores' ? 'selected' : '' ?>>Doctores</option>
                                <option value="citas" <?= ($_POST['tipo_reporte'] ?? '') === 'citas' ? 'selected' : '' ?>>Citas</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <!-- Filtros generales -->
                        <div class="mb-3">
                            <label class="form-label">Filtros</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="filtro_activos" name="filtros[activos]" <?= isset($_POST['filtros']['activos']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="filtro_activos">Solo activos</label>
                            </div>
                        </div>
                        
                        <!-- Filtros específicos para pacientes -->
                        <div id="filtrosPacientes" style="display: <?= ($_POST['tipo_reporte'] ?? '') === 'pacientes' ? 'block' : 'none' ?>;">
                            <div class="mb-3">
                                <label for="nombre_paciente" class="form-label">Nombre</label>
                                <input type="text" class="form-control" id="nombre_paciente" name="filtros[nombre]" value="<?= $_POST['filtros']['nombre'] ?? '' ?>">
                            </div>
                            <div class="mb-3">
                                <label for="cedula_paciente" class="form-label">Cédula</label>
                                <input type="text" class="form-control" id="cedula_paciente" name="filtros[cedula]" value="<?= $_POST['filtros']['cedula'] ?? '' ?>">
                            </div>
                        </div>
                        
                        <!-- Opciones avanzadas -->
                        <div class="opciones-avanzadas">
                            <h5>Opciones Avanzadas</h5>
                            
                            <div id="opcionesPacientes" style="display: <?= ($_POST['tipo_reporte'] ?? '') === 'pacientes' ? 'block' : 'none' ?>;">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="incluir_historia" name="opciones[historia_medica]" <?= isset($_POST['opciones']['historia_medica']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="incluir_historia">Incluir Historia Médica</label>
                                </div>
                                
                                <div class="ms-4 mt-2" id="subOpcionesHistoria" style="display: <?= isset($_POST['opciones']['historia_medica']) ? 'block' : 'none' ?>;">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="incluir_anamnesis" name="opciones[anamnesis]" <?= isset($_POST['opciones']['anamnesis']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="incluir_anamnesis">Incluir Anamnesis</label>
                                    </div>
                                    
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="incluir_antecedentes" name="opciones[antecedentes]" <?= isset($_POST['opciones']['antecedentes']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="incluir_antecedentes">Incluir Antecedentes</label>
                                    </div>
                                    
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="incluir_odontogramas" name="opciones[odontogramas]" <?= isset($_POST['opciones']['odontogramas']) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="incluir_odontogramas">Incluir Odontogramas</label>
                                    </div>
                                </div>
                                
                                <div class="form-check mt-2">
                                    <input class="form-check-input" type="checkbox" id="incluir_citas" name="opciones[citas]" <?= isset($_POST['opciones']['citas']) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="incluir_citas">Incluir Citas</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">Generar Reporte</button>
                            <button type="submit" name="generar_pdf" class="btn btn-success">Exportar a PDF</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header">
                        Resultados del Reporte
                    </div>
                    <div class="card-body">
                        <?php if (!empty($reporte)): ?>
                            <?php if ($tipo_reporte === 'pacientes' && !empty($reporte['pacientes'])): ?>
                                <h3>Reporte de Pacientes</h3>
                                <p>Total: <?= count($reporte['pacientes']) ?></p>
                                
                                <?php foreach ($reporte['pacientes'] as $paciente): ?>
                                    <div class="card mb-3">
                                        <div class="card-header">
                                            <?= $paciente['nombre'] ?> (C.I. <?= $paciente['cedula'] ?>)
                                        </div>
                                        <div class="card-body">
                                            <p>Edad: <?= $paciente['edad'] ?> - Teléfono: <?= $paciente['telefono'] ?></p>
                                            
                                            <?php if (!empty($paciente['historias_medicas']) && !empty($opciones['historia_medica'])): ?>
                                                <h5>Historia Médica</h5>
                                                <?php foreach ($paciente['historias_medicas'] as $historia): ?>
                                                    <div class="card mb-2">
                                                        <div class="card-header bg-light">
                                                            Fecha: <?= $historia['fecha_creacion'] ?> - Examinador: <?= $historia['examinador'] ?>
                                                        </div>
                                                        <div class="card-body">
                                                            <?php if (!empty($historia['anamnesis'])): ?>
                                                                <h6>Anamnesis</h6>
                                                                <ul>
                                                                    <li>Diabetes: <?= $historia['anamnesis']['diabetes'] ? 'Sí' : 'No' ?></li>
                                                                    <li>Hipertensión: <?= $historia['anamnesis']['hipertension'] ? 'Sí' : 'No' ?></li>
                                                                    <!-- Más campos de anamnesis -->
                                                                </ul>
                                                                
                                                                <?php if (!empty($historia['anamnesis']['antecedentes'])): ?>
                                                                    <h6>Antecedentes Familiares</h6>
                                                                    <?php foreach ($historia['anamnesis']['antecedentes'] as $antecedente): ?>
                                                                        <p><?= $antecedente['tipo'] === 'P' ? 'Paternos' : ($antecedente['tipo'] === 'M' ? 'Maternos' : 'Otros') ?>: <?= $antecedente['descripcion'] ?></p>
                                                                    <?php endforeach; ?>
                                                                <?php endif; ?>
                                                            <?php endif; ?>
                                                            
                                                            <?php if (!empty($historia['odontogramas'])): ?>
                                                                <h6>Odontogramas</h6>
                                                                <?php foreach ($historia['odontogramas'] as $odontograma): ?>
                                                                    <p>Fecha: <?= $odontograma['fecha_creacion'] ?></p>
                                                                    <?php if (!empty($odontograma['observaciones'])): ?>
                                                                        <p>Observaciones: <?= $odontograma['observaciones'] ?></p>
                                                                    <?php endif; ?>
                                                                <?php endforeach; ?>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            
                                            <?php if (!empty($paciente['citas']) && !empty($opciones['citas'])): ?>
                                                <h5>Citas</h5>
                                                <table class="table table-bordered">
                                                    <thead>
                                                        <tr>
                                                            <th>Fecha</th>
                                                            <th>Hora</th>
                                                            <th>Doctor</th>
                                                            <th>Estado</th>
                                                            <th>Motivo</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($paciente['citas'] as $cita): ?>
                                                            <tr>
                                                                <td><?= $cita['fecha'] ?></td>
                                                                <td><?= $cita['hora'] ?></td>
                                                                <td><?= $cita['doctor'] ?></td>
                                                                <td><?= $cita['estado'] ?></td>
                                                                <td><?= $cita['motivo'] ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <p>No se encontraron resultados con los filtros aplicados.</p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>Seleccione las opciones y genere un reporte.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const tipoReporte = document.getElementById('tipo_reporte');
            
            // Mostrar/ocultar filtros según tipo de reporte
            tipoReporte.addEventListener('change', function() {
                document.getElementById('filtrosPacientes').style.display = 
                    this.value === 'pacientes' ? 'block' : 'none';
                document.getElementById('opcionesPacientes').style.display = 
                    this.value === 'pacientes' ? 'block' : 'none';
            });
            
            // Mostrar/ocultar subopciones de historia médica
            const incluirHistoria = document.getElementById('incluir_historia');
            if (incluirHistoria) {
                incluirHistoria.addEventListener('change', function() {
                    document.getElementById('subOpcionesHistoria').style.display = 
                        this.checked ? 'block' : 'none';
                });
            }
        });
    </script>
</body>
</html>
<?php
session_start();
$page_title = "Historias Médicas";

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Usuario.php';
require_once __DIR__ . '/../includes/Paciente.php';
require_once __DIR__ . '/../includes/HistoriaMedica.php';
require_once __DIR__ . '/../includes/error_handler.php';

// Verificar autenticación y estructura de la sesión
if (!isset($_SESSION['usuario']) || !is_array($_SESSION['usuario']) || !isset($_SESSION['usuario']['id'], $_SESSION['usuario']['rol_type'])) {
    $_SESSION['error'] = "La sesión ha expirado o es inválida. Por favor, inicia sesión nuevamente.";
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$usuario_actual = (object) $_SESSION['usuario'];
$database = new Database();
$conn = $database->getConnection();

// Instanciar y registrar manejador de errores
$errorHandler = new ErrorHandler($conn);
$errorHandler->registerHandlers();
// ==============================================
// PROCESAMIENTO DE EXPORTACIÓN A PDF (USANDO TCPDF)
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exportar_pdf'])) {
    try {
        // Validar token CSRF
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Token de seguridad inválido');
        }

        // Obtener ID de la historia médica
        $historia_id = (int)$_POST['historia_id'];
        if ($historia_id <= 0) {
            throw new Exception('ID de historia médica inválido');
        }

        // Obtener datos de la historia médica
        $stmt = $conn->prepare("
            SELECT hm.*, p.nombre AS nombre_paciente, p.cedula AS cedula_paciente, 
                   p.sexo AS sexo, p.telefono, p.estado_civil, p.ocupacion,
                   u.nombre AS nombre_examinador
            FROM Historia_Medica hm
            JOIN Paciente p ON hm.paciente_id = p.id
            JOIN Usuario u ON hm.examinador_id = u.id
            WHERE hm.id = :id AND hm.Estado_Sistema = 'Activo'
        ");
        $stmt->execute(['id' => $historia_id]);
        $historia = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$historia) {
            throw new Exception('Historia médica no encontrada');
        }

        // Obtener anamnesis
        $stmtAna = $conn->prepare("SELECT * FROM Anamnesis WHERE historia_medica_id = :id");
        $stmtAna->execute(['id' => $historia_id]);
        $anamnesis = $stmtAna->fetch(PDO::FETCH_ASSOC);

        // Obtener antecedentes familiares
        $antecedentes = [];
        if ($anamnesis) {
            $stmtAnt = $conn->prepare("SELECT * FROM AntecedentesFamiliares WHERE anamnesis_id = :id");
            $stmtAnt->execute(['id' => $anamnesis['id']]);
            $antecedentes = $stmtAnt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Obtener hábitos
        $habitos = [];
        if ($anamnesis) {
            $stmtHab = $conn->prepare("SELECT * FROM Habitos WHERE anamnesis_id = :id");
            $stmtHab->execute(['id' => $anamnesis['id']]);
            $habitos = $stmtHab->fetchAll(PDO::FETCH_ASSOC);
        }

        // Incluir TCPDF
        require_once(__DIR__ . '\..\assets\TCPDF-main\tcpdf.php');

        // Crear nuevo documento PDF
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Configurar documento
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('Sistema Odontológico');
        $pdf->SetTitle('Historia Médica - ' . $historia['nombre_paciente']);
        $pdf->SetSubject('Historia Médica Odontológica');
        $pdf->SetKeywords('Historia, Médica, Odontología');

        // Margenes
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetHeaderMargin(10);
        $pdf->SetFooterMargin(10);

        // Saltos de página automáticos
        $pdf->SetAutoPageBreak(TRUE, 15);

        // Agregar página
        $pdf->AddPage();

        // Contenido del PDF
        $html = '
        <style>
            h1 { color: #333; font-size: 18px; text-align: center; border-bottom: 1px solid #333; padding-bottom: 5px; }
            h2 { color: #555; font-size: 14px; margin-top: 15px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            th { background-color: #f2f2f2; font-weight: bold; padding: 5px; border: 1px solid #ddd; }
            td { padding: 5px; border: 1px solid #ddd; }
            .section { margin-bottom: 15px; }
            .signature-line { border-top: 1px solid #000; width: 200px; margin-top: 50px; }
        </style>

        <h1>Historia Médica Odontológica</h1>
        
        <div class="section">
            <h2>Datos Básicos del Paciente</h2>
            <table>
                <tr>
                    <th>Nombre</th>
                    <td>' . htmlspecialchars($historia['nombre_paciente']) . '</td>
                </tr>
                <tr>
                    <th>Cédula</th>
                    <td>' . htmlspecialchars($historia['cedula_paciente']) . '</td>
                </tr>
                <tr>
                    <th>Género</th>
                    <td>' . htmlspecialchars($historia['sexo']) . '</td>
                </tr>
                <tr>
                    <th>Estado Civil</th>
                    <td>' . htmlspecialchars($historia['estado_civil']) . '</td>
                </tr>
                <tr>
                    <th>Ocupación</th>
                    <td>' . htmlspecialchars($historia['ocupacion']) . '</td>
                </tr>
                <tr>
                    <th>Teléfono</th>
                    <td>' . htmlspecialchars($historia['telefono']) . '</td>
                </tr>
            </table>
        </div>
        
        <div class="section">
            <h2>Datos de la Historia Médica</h2>
            <table>
                <tr>
                    <th>Examinador</th>
                    <td>' . htmlspecialchars($historia['nombre_examinador']) . '</td>
                </tr>
                <tr>
                    <th>Fecha de Creación</th>
                    <td>' . date('d/m/Y H:i', strtotime($historia['fecha_creacion'])) . '</td>
                </tr>
            </table>';

        // Anamnesis
        if ($anamnesis) {
            $html .= '
            <h2>Anamnesis</h2>
            <table>
                <tr>
                    <th>Condición</th>
                    <th>Presenta</th>
                </tr>';
            
            $campos_anamnesis = [
                'diabetes', 'tbc', 'hipertension', 'artritis', 'alergias', 
                'neuralgias', 'hemorragias', 'hepatitis', 'sinusitis', 
                'trastorno_mentales', 'enfermedades_eruptivas', 'enfermedades_renales', 'parotiditis'
            ];
            
            foreach ($campos_anamnesis as $campo) {
                $html .= '
                <tr>
                    <td>' . ucfirst(str_replace('_', ' ', $campo)) . '</td>
                    <td>' . ($anamnesis[$campo] ? 'Sí' : 'No') . '</td>
                </tr>';
            }
            
            $html .= '</table>';
        }
        
        // Antecedentes familiares
        if (!empty($antecedentes)) {
            $html .= '
            <h2>Antecedentes Familiares</h2>
            <table>
                <tr>
                    <th>Tipo</th>
                    <th>Descripción</th>
                </tr>';
            
            foreach ($antecedentes as $ant) {
                $tipo = $ant['tipo'] === 'P' ? 'Paterno' : ($ant['tipo'] === 'M' ? 'Materno' : 'Otro');
                $html .= '
                <tr>
                    <td>' . $tipo . '</td>
                    <td>' . nl2br(htmlspecialchars($ant['descripcion'])) . '</td>
                </tr>';
            }
            
            $html .= '</table>';
        }
        
        // Hábitos
        if (!empty($habitos)) {
            $html .= '
            <h2>Hábitos</h2>
            <table>
                <tr>
                    <th>Descripción</th>
                </tr>';
            
            foreach ($habitos as $hab) {
                $html .= '
                <tr>
                    <td>' . htmlspecialchars($hab['descripcion']) . '</td>
                </tr>';
            }
            
            $html .= '</table>';
        }
        
        $html .= '
        </div>
        
        <div class="section">
            <h2>CONSENTIMIENTO INFORMADO</h2>
            <p><strong>A)</strong> Doy fe de que los datos suministrados son verídicos, se me ha explicado el diagnóstico, la naturaleza de la enfermedad que padezco y su evolución natural, las alternativas de tratamiento, los beneficios y sus consecuencias, los riesgos y las posibles complicaciones que de cada tratamiento se puedan derivar, tomando en cuenta lo anterior decido y consiento el tratamiento a realizar.</p>
            
            <p><strong>B)</strong> Entiendo que mi inasistencia a las citas, el incumplimiento de los hábitos de higiene oral y demás indicaciones de cuidado dadas por mi odontólogo pueden comprometer el éxito final del tratamiento.</p>
            
            <p><strong>C)</strong> Autorizo la toma de fotografías intra y extra bucales durante la consulta odontológica para fines de estudio, planificación de tratamiento y redes sociales.</p>
        </div>
        
        <div class="section">
            <p>Firma del paciente:</p>
            <div class="signature-line"></div>
        </div>';

        // Escribir el contenido HTML en el PDF
        $pdf->writeHTML($html, true, false, true, false, '');

        // Salida del PDF
        $pdf->Output('historia_medica_' . $historia_id . '.pdf', 'I');
        exit;

    } catch (Exception $e) {
        $errorHandler->logError('ERROR', "Error al exportar PDF: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $_SESSION['error_message'] = 'Error al generar el PDF: ' . $e->getMessage();
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
}
// Obtener permisos del usuario actual con manejo de errores
try {
    $stmt_acciones = $conn->prepare("SELECT * FROM auth_acciones WHERE rol_type = :rol_type");
    $stmt_acciones->bindParam(':rol_type', $usuario_actual->rol_type, PDO::PARAM_STR);
    $stmt_acciones->execute();
    $permisos_acciones = $stmt_acciones->fetch(PDO::FETCH_ASSOC);
    
    $stmt_secciones = $conn->prepare("SELECT * FROM auth_secciones WHERE rol_type = :rol_type");
    $stmt_secciones->bindParam(':rol_type', $usuario_actual->rol_type, PDO::PARAM_STR);
    $stmt_secciones->execute();
    $permisos_secciones = $stmt_secciones->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorHandler->logError('ERROR', "Error al obtener permisos: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $_SESSION['error_message'] = "Error al cargar los permisos del usuario";
    header("Location: /Odontologia/templates/error.php");
    exit;
}

// Verificar permisos para acceder a esta sección (historia_medica)
if (!$permisos_secciones || !$permisos_secciones['auth_historia_medica']) {
    $errorHandler->logError('WARNING', "Intento de acceso no autorizado a historias médicas por usuario ID: {$usuario_actual->id}");
    header("Location: /Odontologia/templates/unauthorized.php");
    exit;
}

// Generar token CSRF si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==============================================
// PROCESAMIENTO DEL FORMULARIO DE CREACIÓN
// ==============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crear_historia'])) {
    // Validar token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorHandler->logError('WARNING', "Intento de CSRF detectado en creación de historia médica por usuario ID: {$usuario_actual->id}");
        $_SESSION['error_message'] = 'Token de seguridad inválido';
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }

    // Verificar permiso para crear
    if (!$permisos_acciones || !$permisos_acciones['auth_crear']) {
        $errorHandler->logError('WARNING', "Intento de creación no autorizado de historia médica por usuario ID: {$usuario_actual->id}");
        $_SESSION['error_message'] = 'No tienes permiso para crear historias médicas';
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }

    try {
        $conn->beginTransaction();
        
        // Validar datos requeridos
        if (empty($_POST['paciente_id']) || !is_numeric($_POST['paciente_id'])) {
            throw new Exception("Debe seleccionar un paciente válido");
        }
        
        $paciente_id = (int)$_POST['paciente_id'];
        
        // Verificar que el paciente existe y está activo
        $stmt_paciente = $conn->prepare("SELECT id FROM Paciente WHERE id = :id AND Estado_Sistema = 'Activo'");
        $stmt_paciente->bindParam(':id', $paciente_id, PDO::PARAM_INT);
        $stmt_paciente->execute();
        
        if (!$stmt_paciente->fetch()) {
            throw new Exception("El paciente seleccionado no existe o está inactivo");
        }
        
        // Validar que no exista ya una historia médica activa para este paciente
        $stmt_historia = $conn->prepare("SELECT id FROM Historia_Medica 
        WHERE paciente_id = :paciente_id 
        AND examinador_id = :examinador_id 
        AND Estado_Sistema = 'Activo'");
        $stmt_historia->bindParam(':paciente_id', $paciente_id, PDO::PARAM_INT);
        $stmt_historia->bindParam(':examinador_id', $usuario_actual->id, PDO::PARAM_INT);
        $stmt_historia->execute();
        
        if ($stmt_historia->fetch()) {
            throw new Exception("Ya existe una historia médica activa para este paciente");
        }
        
        // 1. Crear la historia médica
        $historia = new HistoriaMedica();
        $historia->setPacienteId($paciente_id);
        $historia->setExaminadorId($usuario_actual->id);
        
        // Guardar la historia médica
        if (!$historia->save($conn)) {
            throw new Exception("Error al crear el registro principal de historia médica");
        }
        
        $historia_id = $historia->getId();
        
        // 2. Crear la anamnesis
        $anamnesis_id = null;
        if (isset($_POST['anamnesis']) && is_array($_POST['anamnesis'])) {
            $stmt = $conn->prepare("INSERT INTO Anamnesis (
                historia_medica_id, diabetes, tbc, hipertension, artritis, alergias, 
                neuralgias, hemorragias, hepatitis, sinusitis, trastorno_mentales, 
                enfermedades_eruptivas, enfermedades_renales, parotiditis
            ) VALUES (
                :historia_medica_id, :diabetes, :tbc, :hipertension, :artritis, :alergias, 
                :neuralgias, :hemorragias, :hepatitis, :sinusitis, :trastorno_mentales, 
                :enfermedades_eruptivas, :enfermedades_renales, :parotiditis
            )");
            
            $campos_validos = [
                'diabetes', 'tbc', 'hipertension', 'artritis', 'alergias', 
                'neuralgias', 'hemorragias', 'hepatitis', 'sinusitis', 
                'trastorno_mentales', 'enfermedades_eruptivas', 'enfermedades_renales', 'parotiditis'
            ];
            
            $valores = ['historia_medica_id' => $historia_id];
            foreach ($campos_validos as $campo) {
                $valores[$campo] = isset($_POST['anamnesis'][$campo]) ? 1 : 0;
            }
            
            if (!$stmt->execute($valores)) {
                throw new Exception("Error al crear el registro de anamnesis");
            }
            
            $anamnesis_id = $conn->lastInsertId();
        }
        
        // 3. Crear antecedentes familiares
        if (isset($_POST['antecedentes']) && is_array($_POST['antecedentes']) && $anamnesis_id) {
            $stmt = $conn->prepare("INSERT INTO AntecedentesFamiliares (anamnesis_id, tipo, descripcion) VALUES (:anamnesis_id, :tipo, :descripcion)");
            
            foreach ($_POST['antecedentes'] as $antecedente) {
                if (!empty($antecedente['descripcion']) && !empty($antecedente['tipo']) && in_array($antecedente['tipo'], ['P', 'M', 'O'])) {
                    $descripcion = trim(strip_tags($antecedente['descripcion']));
                    if (strlen($descripcion) > 500) {
                        throw new Exception("La descripción del antecedente no puede exceder los 500 caracteres");
                    }
                    if (!empty($descripcion)) {
                        $stmt->execute([
                            'anamnesis_id' => $anamnesis_id,
                            'tipo' => $antecedente['tipo'],
                            'descripcion' => $descripcion
                        ]);
                    }
                }
            }
        }
        
        // 4. Crear hábitos
        if (isset($_POST['habitos']) && is_array($_POST['habitos']) && $anamnesis_id) {
            $stmt = $conn->prepare("INSERT INTO Habitos (anamnesis_id, descripcion) VALUES (:anamnesis_id, :descripcion)");
            
            foreach ($_POST['habitos'] as $habito) {
                if (!empty($habito['descripcion'])) {
                    $descripcion = trim(strip_tags($habito['descripcion']));
                    if (strlen($descripcion) > 500) {
                        throw new Exception("La descripción del hábito no puede exceder los 500 caracteres");
                    }
                    if (!empty($descripcion)) {
                        $stmt->execute([
                            'anamnesis_id' => $anamnesis_id,
                            'descripcion' => $descripcion
                        ]);
                    }
                }
            }
        }
        
        $conn->commit();
        $errorHandler->logError('INFO', "Historia médica creada exitosamente (ID: $historia_id) por usuario ID: {$usuario_actual->id}");
        $_SESSION['success_message'] = 'Historia médica creada correctamente';
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
        
    } catch (Exception $e) {
        $conn->rollBack();
        $errorHandler->logError('ERROR', "Error al crear historia médica: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $_SESSION['error_message'] = 'Error al crear la historia médica: '.$e->getMessage();
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
}

// ==============================================
// CONFIGURACIÓN DE PAGINACIÓN Y BÚSQUEDA
// ==============================================
$limit = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;
$search = isset($_GET['search']) ? trim(strip_tags($_GET['search'])) : '';
$search_param = "%$search%";

// Consulta para contar registros
try {
    $count_sql = "SELECT COUNT(*) FROM Historia_Medica hm
                  JOIN Paciente p ON hm.paciente_id = p.id
                  JOIN Usuario u ON hm.examinador_id = u.id
                  WHERE hm.Estado_Sistema = 'Activo'";

    if ($usuario_actual->rol_type === 'O') {
        $count_sql .= " AND hm.examinador_id = :examinador_id";
    }

    if ($search !== '') {
        $count_sql .= ($usuario_actual->rol_type === 'O' ? " AND" : " WHERE") . " (p.nombre LIKE :search OR p.cedula LIKE :search)";
    }

    $count_stmt = $conn->prepare($count_sql);
    
    if ($usuario_actual->rol_type === 'O') {
        $count_stmt->bindValue(':examinador_id', $usuario_actual->id, PDO::PARAM_INT);
    }
    
    if ($search !== '') {
        $count_stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
    }
    
    $count_stmt->execute();
    $total = $count_stmt->fetchColumn();
    $total_pages = ceil($total / $limit);
} catch (Exception $e) {
    $errorHandler->logError('ERROR', "Error al contar historias médicas: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $total = 0;
    $total_pages = 1;
    $_SESSION['error_message'] = "Error al cargar el conteo de historias médicas";
}

// Consulta principal para obtener las historias
try {
    $sql = "SELECT hm.id, hm.fecha_creacion, p.nombre AS nombre_paciente, 
                   p.cedula AS cedula_paciente, u.nombre AS nombre_examinador
            FROM Historia_Medica hm
            JOIN Paciente p ON hm.paciente_id = p.id
            JOIN Usuario u ON hm.examinador_id = u.id
            WHERE hm.Estado_Sistema = 'Activo'";

    // Si es odontólogo, solo ver sus propias historias
    if ($usuario_actual->rol_type === 'O') {
        $sql .= " AND hm.examinador_id = :examinador_id";
    }

    if ($search !== '') {
        $sql .= ($usuario_actual->rol_type === 'O' ? " AND" : " WHERE") . " (p.nombre LIKE :search OR p.cedula LIKE :search)";
    }

    $sql .= " ORDER BY hm.fecha_creacion DESC LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    
    if ($usuario_actual->rol_type === 'O') {
        $stmt->bindValue(':examinador_id', $usuario_actual->id, PDO::PARAM_INT);
    }
    
    if ($search !== '') {
        $stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
    }
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $historias_medicas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorHandler->logError('ERROR', "Error al obtener historias médicas: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $historias_medicas = [];
    $_SESSION['error_message'] = "Error al cargar la lista de historias médicas";
}

// Obtener lista de pacientes para el modal de creación
try {
    $stmtPacientes = $conn->query("SELECT id, nombre, cedula FROM Paciente WHERE Estado_Sistema = 'Activo' ORDER BY nombre");
    $pacientes = $stmtPacientes->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errorHandler->logError('ERROR', "Error al obtener lista de pacientes: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $pacientes = [];
}

include '../templates/navbar.php';

// Mostrar mensajes de éxito/error
if (isset($_SESSION['success_message'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">
            '.htmlspecialchars($_SESSION['success_message'], ENT_QUOTES, 'UTF-8').'
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">
            '.htmlspecialchars($_SESSION['error_message'], ENT_QUOTES, 'UTF-8').'
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
          </div>';
    unset($_SESSION['error_message']);
}
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

.antecedente-item, .habito-item {
    background-color: #f8f9fa;
    margin-bottom: 1rem;
    padding: 1rem;
    border-radius: 0.25rem;
    border: 1px solid #dee2e6;
}

.form-switch .form-check-input {
    width: 3em;
    height: 1.5em;
}

.badge {
    font-size: 0.85em;
}

.modal-content {
    border-radius: 0.5rem;
}

.modal-header {
    border-bottom: none;
    padding-bottom: 0;
}

.modal-footer {
    border-top: none;
    padding-top: 0;
}

.btn-add-item {
    margin-top: 1rem;
}

.btn-add-item:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.firma-container {
    margin-top: 20px;
    padding: 15px;
    border: 1px dashed #ccc;
    border-radius: 5px;
    background-color: #f9f9f9;
}

.firma-preview {
    max-width: 100%;
    max-height: 150px;
    margin-top: 10px;
    display: none;
}

.alert-info {
    background-color: #d1ecf1;
    border-color: #bee5eb;
    color: #0c5460;
}
</style>

<div class="container-fluid mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1><i class="bi bi-file-medical"></i> Listado de Historias Médicas</h1>
        <?php if ($permisos_acciones && $permisos_acciones['auth_crear']): ?>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalSeleccionarPaciente">
                <i class="bi bi-plus-circle"></i> Nueva Historia Médica
            </button>
        <?php endif; ?>
    </div>

    <!-- Barra de búsqueda -->
    <form method="GET" class="mb-4">
        <div class="input-group">
            <input type="text" name="search" class="form-control" placeholder="Buscar por nombre o cédula del paciente..." value="<?= htmlspecialchars($search) ?>">
            <button class="btn btn-outline-primary" type="submit">
                <i class="bi bi-search"></i> Buscar
            </button>
            <?php if ($search !== ''): ?>
                <a href="?" class="btn btn-outline-secondary">
                    <i class="bi bi-x-circle"></i> Limpiar
                </a>
            <?php endif; ?>
        </div>
    </form>

    <!-- Tabla de historias médicas -->
    <div class="table-responsive">
        <table class="table table-striped table-hover align-middle">
            <thead class="table table-striped">
                <tr>
                    <th>Paciente</th>
                    <th>Cédula</th>
                    <th>Examinador</th>
                    <th>Fecha Creación</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($historias_medicas as $historia): ?>
                <?php
                    // Consultar datos adicionales para los modales
                    $stmtAna = $conn->prepare("SELECT * FROM Anamnesis WHERE historia_medica_id = :id");
                    $stmtAna->execute(['id' => $historia['id']]);
                    $anamnesis = $stmtAna->fetch(PDO::FETCH_ASSOC);

                    $antecedentes = [];
                    $habitos = [];
                    if ($anamnesis) {
                        $stmtAnt = $conn->prepare("SELECT * FROM AntecedentesFamiliares WHERE anamnesis_id = :id");
                        $stmtAnt->execute(['id' => $anamnesis['id']]);
                        $antecedentes = $stmtAnt->fetchAll(PDO::FETCH_ASSOC);

                        $stmtHab = $conn->prepare("SELECT * FROM Habitos WHERE anamnesis_id = :id");
                        $stmtHab->execute(['id' => $anamnesis['id']]);
                        $habitos = $stmtHab->fetchAll(PDO::FETCH_ASSOC);
                    }
                ?>
                <tr>
                    <td><?= htmlspecialchars($historia['nombre_paciente']) ?></td>
                    <td><?= htmlspecialchars($historia['cedula_paciente']) ?></td>
                    <td><?= htmlspecialchars($historia['nombre_examinador']) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($historia['fecha_creacion'])) ?></td>
                    <td class="text-end">
                        <div class="btn-group" role="group">
                            <?php if ($permisos_acciones && $permisos_acciones['auth_ver'] && 
                                     ($usuario_actual->rol_type === 'A' || 
                                      ($usuario_actual->rol_type === 'O' && $historia['nombre_examinador'] === $usuario_actual->nombre))): ?>
                                <button type="button" class="btn btn-info btn-sm" data-bs-toggle="modal" data-bs-target="#modalHistoria<?= $historia['id'] ?>">
                                    <i class="bi bi-eye"></i> Ver
                                </button>
                            <?php endif; ?>

                            <?php if ($permisos_acciones && $permisos_acciones['auth_editar'] && 
                                     ($usuario_actual->rol_type === 'A' || 
                                      ($usuario_actual->rol_type === 'O' && $historia['nombre_examinador'] === $usuario_actual->nombre))): ?>
                                <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#modalEditar<?= $historia['id'] ?>">
                                    <i class="bi bi-pencil"></i> Editar
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($permisos_acciones && $permisos_acciones['auth_eliminar'] && 
                                     ($usuario_actual->rol_type === 'A' || 
                                      ($usuario_actual->rol_type === 'O' && $historia['nombre_examinador'] === $usuario_actual->nombre))): ?>
                                <form method="POST" action="eliminar_historia.php" style="display:inline;">
                                    <input type="hidden" name="id" value="<?= $historia['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Estás seguro de marcar esta historia médica como inactiva?');">
                                        <i class="bi bi-trash"></i> Eliminar
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <!-- Botón para exportar a PDF -->
                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalExportarPDF<?= $historia['id'] ?>">
                                <i class="bi bi-file-earmark-pdf"></i> PDF
                            </button>
                        </div>
                    </td>
                </tr>

                <!-- Modal para Visualizar Historia -->
                <div class="modal fade" id="modalHistoria<?= $historia['id'] ?>" tabindex="-1" aria-labelledby="modalLabel<?= $historia['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header bg-primary text-white">
                                <h5 class="modal-title" id="modalLabel<?= $historia['id'] ?>">
                                    <i class="bi bi-file-medical"></i> Historia Médica #<?= $historia['id'] ?>
                                </h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="card mb-4">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="bi bi-person-badge"></i> Datos del Paciente</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <p><strong>Nombre:</strong> <?= htmlspecialchars($historia['nombre_paciente']) ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Cédula:</strong> <?= htmlspecialchars($historia['cedula_paciente']) ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="card mb-4">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="bi bi-person-square"></i> Datos del Examinador</h6>
                                    </div>
                                    <div class="card-body">
                                        <p><strong>Nombre:</strong> <?= htmlspecialchars($historia['nombre_examinador']) ?></p>
                                    </div>
                                </div>

                                <div class="card mb-4">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="bi bi-calendar3"></i> Fecha de Creación</h6>
                                    </div>
                                    <div class="card-body">
                                        <p><?= date('d/m/Y H:i', strtotime($historia['fecha_creacion'])) ?></p>
                                    </div>
                                </div>

                                <?php if ($anamnesis): ?>
                                <div class="card mb-4">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="bi bi-heart-pulse"></i> Anamnesis</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <?php foreach ($anamnesis as $key => $value): ?>
                                                <?php if (!in_array($key, ['id', 'historia_medica_id'])): ?>
                                                    <div class="col-md-6 mb-2">
                                                        <span class="badge bg-<?= $value ? 'success' : 'secondary' ?> me-2">
                                                            <?= $value ? 'Sí' : 'No' ?>
                                                        </span>
                                                        <?= ucfirst(str_replace('_', ' ', $key)) ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <div class="card mb-4">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="bi bi-people-fill"></i> Antecedentes Familiares</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($antecedentes)): ?>
                                            <ul class="list-group">
                                                <?php foreach ($antecedentes as $ant): ?>
                                                    <li class="list-group-item">
                                                        <strong><?= $ant['tipo'] === 'P' ? 'Paterno' : ($ant['tipo'] === 'M' ? 'Materno' : 'Otro') ?>:</strong>
                                                        <p class="mb-0"><?= nl2br(htmlspecialchars($ant['descripcion'])) ?></p>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="text-muted mb-0">No hay antecedentes registrados.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="card">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0"><i class="bi bi-emoji-smile"></i> Hábitos</h6>
                                    </div>
                                    <div class="card-body">
                                        <?php if (!empty($habitos)): ?>
                                            <ul class="list-group">
                                                <?php foreach ($habitos as $hab): ?>
                                                    <li class="list-group-item"><?= htmlspecialchars($hab['descripcion']) ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        <?php else: ?>
                                            <p class="text-muted mb-0">No hay hábitos registrados.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                    <i class="bi bi-x-circle"></i> Cerrar
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Modal para Editar Historia -->
                <?php if ($permisos_acciones && $permisos_acciones['auth_editar'] && 
                         ($usuario_actual->rol_type === 'A' || 
                          ($usuario_actual->rol_type === 'O' && $historia['nombre_examinador'] === $usuario_actual->nombre))): ?>
                <div class="modal fade" id="modalEditar<?= $historia['id'] ?>" tabindex="-1" aria-labelledby="modalEditarLabel<?= $historia['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <form method="POST" action="actualizar_historia.php">
                                <input type="hidden" name="historia_id" value="<?= $historia['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title" id="modalEditarLabel<?= $historia['id'] ?>">
                                        <i class="bi bi-pencil-square"></i> Editar Historia #<?= $historia['id'] ?>
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <?php if ($anamnesis): ?>
                                    <input type="hidden" name="anamnesis_id" value="<?= $anamnesis['id'] ?>">
                                    <div class="card mb-4">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0"><i class="bi bi-heart-pulse"></i> Anamnesis</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <?php 
                                                $campos_anamnesis = [
                                                    'diabetes', 'tbc', 'hipertension', 'artritis', 'alergias', 
                                                    'neuralgias', 'hemorragias', 'hepatitis', 'sinusitis', 
                                                    'trastorno_mentales', 'enfermedades_eruptivas', 'enfermedades_renales', 'parotiditis'
                                                ];
                                                foreach ($campos_anamnesis as $campo): ?>
                                                    <div class="col-md-6 mb-3">
                                                        <div class="form-check form-switch">
                                                            <input class="form-check-input" type="checkbox" 
                                                                   id="<?= $campo.'_'.$historia['id'] ?>" 
                                                                   name="anamnesis[<?= $campo ?>]" 
                                                                   value="1" <?= $anamnesis[$campo] ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="<?= $campo.'_'.$historia['id'] ?>">
                                                                <?= ucfirst(str_replace('_', ' ', $campo)) ?>
                                                            </label>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="card mb-4">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0"><i class="bi bi-people-fill"></i> Antecedentes Familiares</h6>
                                        </div>
                                        <div class="card-body">
                                            <div id="antecedentes-container-<?= $historia['id'] ?>">
                                                <?php if (!empty($antecedentes)): ?>
                                                    <?php foreach ($antecedentes as $index => $ant): ?>
                                                        <div class="antecedente-item mb-3 p-3 border rounded">
                                                            <input type="hidden" name="antecedentes[<?= $index ?>][id]" value="<?= $ant['id'] ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Tipo:</label>
                                                                <select class="form-select" name="antecedentes[<?= $index ?>][tipo]" required>
                                                                    <option value="P" <?= $ant['tipo'] === 'P' ? 'selected' : '' ?>>Paterno</option>
                                                                    <option value="M" <?= $ant['tipo'] === 'M' ? 'selected' : '' ?>>Materno</option>
                                                                    <option value="O" <?= $ant['tipo'] === 'O' ? 'selected' : '' ?>>Otro</option>
                                                                </select>
                                                                <div class="invalid-feedback">Por favor seleccione un tipo</div>
                                                            </div>
                                                            <div class="mb-3">
                                                                <label class="form-label">Descripción:</label>
                                                                <textarea class="form-control" name="antecedentes[<?= $index ?>][descripcion]" rows="3" required maxlength="500"><?= htmlspecialchars($ant['descripcion']) ?></textarea>
                                                                <div class="invalid-feedback">La descripción es requerida (máximo 500 caracteres)</div>
                                                            </div>
                                                            <button type="button" class="btn btn-sm btn-danger remove-antecedente">
                                                                <i class="bi bi-trash"></i> Eliminar
                                                            </button>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="text-muted">No hay antecedentes registrados.</p>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-success mt-2 btn-add-item" onclick="addAntecedente(<?= $historia['id'] ?>)" <?= count($antecedentes) >= 3 ? 'disabled' : '' ?>>
                                                <i class="bi bi-plus-circle"></i> Agregar Antecedente
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="card">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0"><i class="bi bi-emoji-smile"></i> Hábitos</h6>
                                        </div>
                                        <div class="card-body">
                                            <div id="habitos-container-<?= $historia['id'] ?>">
                                                <?php if (!empty($habitos)): ?>
                                                    <?php foreach ($habitos as $index => $hab): ?>
                                                        <div class="habito-item mb-3 p-3 border rounded">
                                                            <input type="hidden" name="habitos[<?= $index ?>][id]" value="<?= $hab['id'] ?>">
                                                            <div class="mb-3">
                                                                <label class="form-label">Descripción:</label>
                                                                <textarea class="form-control" name="habitos[<?= $index ?>][descripcion]" rows="2" required maxlength="500"><?= htmlspecialchars($hab['descripcion']) ?></textarea>
                                                                <div class="invalid-feedback">La descripción es requerida (máximo 500 caracteres)</div>
                                                            </div>
                                                            <button type="button" class="btn btn-sm btn-danger remove-habito">
                                                                <i class="bi bi-trash"></i> Eliminar
                                                            </button>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <p class="text-muted">No hay hábitos registrados.</p>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-success mt-2 btn-add-item" onclick="addHabito(<?= $historia['id'] ?>)" <?= count($habitos) >= 3 ? 'disabled' : '' ?>>
                                                <i class="bi bi-plus-circle"></i> Agregar Hábito
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                        <i class="bi bi-x-circle"></i> Cancelar
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save"></i> Guardar Cambios
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Modal para Exportar a PDF -->
                <div class="modal fade" id="modalExportarPDF<?= $historia['id'] ?>" tabindex="-1" aria-labelledby="modalExportarLabel<?= $historia['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST">
                                <input type="hidden" name="historia_id" value="<?= $historia['id'] ?>">
                                <input type="hidden" name="exportar_pdf" value="1">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title" id="modalExportarLabel<?= $historia['id'] ?>">
                                        <i class="bi bi-file-earmark-pdf"></i> Exportar a PDF
                                    </h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <p>Se generará un PDF con la historia médica de <strong><?= htmlspecialchars($historia['nombre_paciente']) ?></strong>.</p>
                                        <p>El PDF incluirá:</p>
                                        <ul>
                                            <li>Datos básicos del paciente</li>
                                            <li>Datos de la historia médica</li>
                                            <li>Consentimiento informado</li>
                                        </ul>
                                    </div>
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle"></i> El PDF se generará online usando un servicio externo.
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                                        <i class="bi bi-x-circle"></i> Cancelar
                                    </button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-file-earmark-pdf"></i> Generar PDF
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Paginación -->
    <?php if ($total > 0): ?>
        <nav aria-label="Paginación">
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
        <div class="alert alert-info">No hay historias médicas registradas<?= $search ? ' que coincidan con la búsqueda' : '' ?>.</div>
    <?php endif; ?>
</div>

<!-- Modal para Seleccionar Paciente -->
<?php if ($permisos_acciones && $permisos_acciones['auth_crear']): ?>
<div class="modal fade" id="modalSeleccionarPaciente" tabindex="-1" aria-labelledby="modalSeleccionarLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="modalSeleccionarLabel">
            <i class="bi bi-person-plus"></i> Seleccionar Paciente
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label for="selectPaciente" class="form-label">Paciente:</label>
          <select id="selectPaciente" class="form-select" required>
            <option value="">-- Selecciona un paciente --</option>
            <?php foreach ($pacientes as $paciente): ?>
              <option value="<?= $paciente['id'] ?>">
                <?= htmlspecialchars($paciente['nombre']) ?> - <?= htmlspecialchars($paciente['cedula']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="invalid-feedback">Por favor seleccione un paciente</div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="bi bi-x-circle"></i> Cancelar
        </button>
        <button type="button" class="btn btn-primary" onclick="iniciarCreacionHistoria()">
            <i class="bi bi-arrow-right"></i> Continuar
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Modal para Crear Historia Médica -->
<div class="modal fade" id="modalCrearHistoria" tabindex="-1" aria-labelledby="modalCrearLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form id="formCrearHistoria" method="POST">
        <input type="hidden" id="pacienteIdHidden" name="paciente_id">
        <input type="hidden" name="crear_historia" value="1">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="modalCrearLabel">
              <i class="bi bi-file-earmark-plus"></i> Nueva Historia Médica
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" style="max-height: 500px; overflow-y: auto;">
          <!-- Datos del Paciente -->
          <div class="card mb-4">
            <div class="card-header bg-light">
              <h6 class="mb-0"><i class="bi bi-person-badge"></i> Datos del Paciente</h6>
            </div>
            <div class="card-body">
              <div class="row">
                <div class="col-md-6">
                  <p><strong>Nombre:</strong> <span id="nombrePaciente"></span></p>
                </div>
                <div class="col-md-6">
                  <p><strong>Cédula:</strong> <span id="cedulaPaciente"></span></p>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Sección de Anamnesis -->
          <div class="card mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-heart-pulse"></i> Anamnesis</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php 
                    $campos_anamnesis = [
                        'diabetes', 'tbc', 'hipertension', 'artritis', 'alergias', 
                        'neuralgias', 'hemorragias', 'hepatitis', 'sinusitis', 
                        'trastorno_mentales', 'enfermedades_eruptivas', 'enfermedades_renales', 'parotiditis'
                    ];
                    foreach ($campos_anamnesis as $campo): ?>
                        <div class="col-md-6 mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" 
                                       id="new_<?= $campo ?>" 
                                       name="anamnesis[<?= $campo ?>]" 
                                       value="1">
                                <label class="form-check-label" for="new_<?= $campo ?>">
                                    <?= ucfirst(str_replace('_', ' ', $campo)) ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
          </div>
          
          <!-- Sección de Antecedentes Familiares -->
          <div class="card mb-4">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-people-fill"></i> Antecedentes Familiares</h6>
            </div>
            <div class="card-body">
                <div id="antecedentes-container-new">
                    <p class="text-muted">No hay antecedentes registrados.</p>
                </div>
                <button type="button" class="btn btn-sm btn-success mt-2 btn-add-item" onclick="addAntecedente('new')">
                    <i class="bi bi-plus-circle"></i> Agregar Antecedente
                </button>
            </div>
          </div>
          
          <!-- Sección de Hábitos -->
          <div class="card">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="bi bi-emoji-smile"></i> Hábitos</h6>
            </div>
            <div class="card-body">
                <div id="habitos-container-new">
                    <p class="text-muted">No hay hábitos registrados.</p>
                </div>
                <button type="button" class="btn btn-sm btn-success mt-2 btn-add-item" onclick="addHabito('new')">
                    <i class="bi bi-plus-circle"></i> Agregar Hábito
                </button>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="bi bi-x-circle"></i> Cancelar
          </button>
          <button type="submit" class="btn btn-success">
              <i class="bi bi-save"></i> Crear Historia
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
// Variables globales para llevar el conteo
const MAX_ANTECEDENTES = 3;
const MAX_HABITOS = 3;

// Función para iniciar la creación de historia médica
function iniciarCreacionHistoria() {
    const selectPaciente = document.getElementById('selectPaciente');
    const pacienteId = selectPaciente.value;
    
    if (!pacienteId) {
        selectPaciente.classList.add('is-invalid');
        return;
    }
    
    selectPaciente.classList.remove('is-invalid');
    
    // Obtener datos del paciente seleccionado
    const pacienteNombre = selectPaciente.options[selectPaciente.selectedIndex].text.split(' - ')[0];
    const pacienteCedula = selectPaciente.options[selectPaciente.selectedIndex].text.split(' - ')[1];
    
    // Llenar datos en el modal de creación
    document.getElementById('pacienteIdHidden').value = pacienteId;
    document.getElementById('nombrePaciente').textContent = pacienteNombre;
    document.getElementById('cedulaPaciente').textContent = pacienteCedula;
    
    // Resetear contenedores
    document.getElementById('antecedentes-container-new').innerHTML = '<p class="text-muted">No hay antecedentes registrados.</p>';
    document.getElementById('habitos-container-new').innerHTML = '<p class="text-muted">No hay hábitos registrados.</p>';
    
    // Habilitar botones
    document.querySelectorAll('#modalCrearHistoria .btn-add-item').forEach(btn => {
        btn.disabled = false;
    });
    
    // Cerrar modal de selección y abrir modal de creación
    const modalSeleccionar = bootstrap.Modal.getInstance(document.getElementById('modalSeleccionarPaciente'));
    modalSeleccionar.hide();
    
    const modalCrear = new bootstrap.Modal(document.getElementById('modalCrearHistoria'));
    modalCrear.show();
}

// Función para agregar antecedente familiar
function addAntecedente(suffix) {
    const container = document.getElementById(`antecedentes-container-${suffix}`);
    const count = container.querySelectorAll('.antecedente-item').length;
    
    // Verificar límite
    if (count >= MAX_ANTECEDENTES) {
        alert(`No se pueden agregar más de ${MAX_ANTECEDENTES} antecedentes`);
        return;
    }
    
    const html = `
        <div class="antecedente-item mb-3 p-3 border rounded">
            <div class="mb-3">
                <label class="form-label">Tipo:</label>
                <select class="form-select" name="antecedentes[${count}][tipo]" required>
                    <option value="">-- Seleccione --</option>
                    <option value="P">Paterno</option>
                    <option value="M">Materno</option>
                    <option value="O">Otro</option>
                </select>
                <div class="invalid-feedback">Por favor seleccione un tipo</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Descripción:</label>
                <textarea class="form-control" name="antecedentes[${count}][descripcion]" rows="3" required maxlength="500"></textarea>
                <div class="invalid-feedback">La descripción es requerida (máximo 500 caracteres)</div>
            </div>
            <button type="button" class="btn btn-sm btn-danger remove-antecedente">
                <i class="bi bi-trash"></i> Eliminar
            </button>
        </div>
    `;
    
    if (container.querySelector('p.text-muted')) {
        container.innerHTML = html;
    } else {
        container.insertAdjacentHTML('beforeend', html);
    }
    
    // Actualizar estado del botón
    updateAddButtons(suffix);
}

// Función para agregar hábito
function addHabito(suffix) {
    const container = document.getElementById(`habitos-container-${suffix}`);
    const count = container.querySelectorAll('.habito-item').length;
    
    // Verificar límite
    if (count >= MAX_HABITOS) {
        alert(`No se pueden agregar más de ${MAX_HABITOS} hábitos`);
        return;
    }
    
    const html = `
        <div class="habito-item mb-3 p-3 border rounded">
            <div class="mb-3">
                <label class="form-label">Descripción:</label>
                <textarea class="form-control" name="habitos[${count}][descripcion]" rows="2" required maxlength="500"></textarea>
                <div class="invalid-feedback">La descripción es requerida (máximo 500 caracteres)</div>
            </div>
            <button type="button" class="btn btn-sm btn-danger remove-habito">
                <i class="bi bi-trash"></i> Eliminar
            </button>
        </div>
    `;
    
    if (container.querySelector('p.text-muted')) {
        container.innerHTML = html;
    } else {
        container.insertAdjacentHTML('beforeend', html);
    }
    
    // Actualizar estado del botón
    updateAddButtons(suffix);
}

// Función para actualizar el estado de los botones de agregar
function updateAddButtons(suffix) {
    const antecedentesCount = document.querySelectorAll(`#antecedentes-container-${suffix} .antecedente-item`).length;
    const habitosCount = document.querySelectorAll(`#habitos-container-${suffix} .habito-item`).length;
    
    // Buscar todos los botones de agregar en este contexto (puede haber varios en diferentes modales)
    const addAntecedenteBtns = document.querySelectorAll(`button[onclick="addAntecedente('${suffix}')"]`);
    const addHabitoBtns = document.querySelectorAll(`button[onclick="addHabito('${suffix}')"]`);
    
    // Actualizar estado de los botones de antecedentes
    addAntecedenteBtns.forEach(btn => {
        btn.disabled = antecedentesCount >= MAX_ANTECEDENTES;
    });
    
    // Actualizar estado de los botones de hábitos
    addHabitoBtns.forEach(btn => {
        btn.disabled = habitosCount >= MAX_HABITOS;
    });
}

// Eventos al cargar el DOM
document.addEventListener('DOMContentLoaded', function() {
    // Manejo de eliminación de antecedentes y hábitos
    document.addEventListener('click', function(e) {
        // Eliminar antecedente
        if (e.target && e.target.classList.contains('remove-antecedente')) {
            const container = e.target.closest('.card-body').querySelector('div[id^="antecedentes-container-"]');
            const suffix = container.id.split('-').pop();
            e.target.closest('.antecedente-item').remove();
            
            if (container && container.querySelectorAll('.antecedente-item').length === 0) {
                container.innerHTML = '<p class="text-muted">No hay antecedentes registrados.</p>';
            }
            
            // Actualizar estado del botón
            updateAddButtons(suffix);
        }
        
        // Eliminar hábito
        if (e.target && e.target.classList.contains('remove-habito')) {
            const container = e.target.closest('.card-body').querySelector('div[id^="habitos-container-"]');
            const suffix = container.id.split('-').pop();
            e.target.closest('.habito-item').remove();
            
            if (container && container.querySelectorAll('.habito-item').length === 0) {
                container.innerHTML = '<p class="text-muted">No hay hábitos registrados.</p>';
            }
            
            // Actualizar estado del botón
            updateAddButtons(suffix);
        }
    });

    // Validación del formulario de creación
    document.getElementById('formCrearHistoria')?.addEventListener('submit', function(e) {
        let isValid = true;
        
        // Validar antecedentes
        const antecedentesItems = this.querySelectorAll('.antecedente-item');
        antecedentesItems.forEach(item => {
            const tipo = item.querySelector('select');
            const descripcion = item.querySelector('textarea');
            
            if (!tipo.value || !descripcion.value.trim()) {
                isValid = false;
                if (!tipo.value) {
                    tipo.classList.add('is-invalid');
                }
                if (!descripcion.value.trim()) {
                    descripcion.classList.add('is-invalid');
                }
            }
        });
        
        // Validar hábitos
        const habitosItems = this.querySelectorAll('.habito-item');
        habitosItems.forEach(item => {
            const descripcion = item.querySelector('textarea');
            
            if (!descripcion.value.trim()) {
                isValid = false;
                descripcion.classList.add('is-invalid');
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            
            // Mostrar alerta general
            const alertDiv = document.createElement('div');
            alertDiv.className = 'alert alert-danger alert-dismissible fade show mb-4';
            alertDiv.setAttribute('role', 'alert');
            alertDiv.innerHTML = `
                <strong>Error:</strong> Por favor complete todos los campos requeridos correctamente.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
            `;
            
            const existingAlert = this.querySelector('.alert');
            if (existingAlert) {
                existingAlert.replaceWith(alertDiv);
            } else {
                this.prepend(alertDiv);
            }
            
            // Desplazarse al primer error
            const firstInvalid = this.querySelector('.is-invalid');
            if (firstInvalid) {
                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstInvalid.focus();
                
                // Agregar animación para llamar la atención
                firstInvalid.style.animation = 'none';
                setTimeout(() => {
                    firstInvalid.style.animation = 'shake 0.5s ease-in-out';
                }, 10);
            }
        }
    });

    // Remover clases de invalid al cambiar
    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('is-invalid')) {
            e.target.classList.remove('is-invalid');
        }
    });
    
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('is-invalid')) {
            e.target.classList.remove('is-invalid');
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
</script>

<?php include '../templates/footer.php'; ?>
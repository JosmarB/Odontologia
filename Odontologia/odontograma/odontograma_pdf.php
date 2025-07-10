<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/odontograma_controller.php';

// Verificar parámetros
if (!isset($_GET['odontograma_id']) || !isset($_GET['paciente_id'])) {
    die('Parámetros inválidos');
}

$odontograma_id = $_GET['odontograma_id'];
$paciente_id = $_GET['paciente_id'];

// Obtener datos
$database = new Database();
$db = $database->getConnection();

// 1. Obtener datos del paciente
$stmt = $db->prepare("SELECT * FROM paciente WHERE id = ?");
$stmt->execute([$paciente_id]);
$paciente = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$paciente) {
    die('Paciente no encontrado');
}

// 2. Obtener historia médica
$stmt = $db->prepare("SELECT h.*, u.nombre as examinador_nombre 
                     FROM historia_medica h
                     LEFT JOIN usuario u ON h.examinador_id = u.id
                     WHERE h.paciente_id = ?
                     ORDER BY h.fecha_creacion DESC LIMIT 1");
$stmt->execute([$paciente_id]);
$historia_medica = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. Obtener anamnesis
$anamnesis = [];
if ($historia_medica) {
    $stmt = $db->prepare("SELECT * FROM anamnesis WHERE historia_medica_id = ?");
    $stmt->execute([$historia_medica['id']]);
    $anamnesis = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 4. Obtener odontograma y dientes
$controller = new OdontogramaController();
$result = $controller->obtenerOdontograma($odontograma_id);

if (!$result['success']) {
    die('Error al obtener odontograma: ' . $result['error']);
}

$odontograma = $result['odontograma'];
$dientes = $result['dientes'];

// Formatear datos de dientes
foreach ($dientes as &$diente) {
    $diente['ausente'] = (bool)$diente['ausente'];
    $diente['fractura'] = (bool)$diente['fractura'];
    $diente['caries'] = (bool)$diente['caries'];
    $diente['corona'] = (bool)$diente['corona'];
    $diente['puente'] = isset($diente['protesis_fija']) ? (bool)$diente['protesis_fija'] : false;
    $diente['implante'] = (bool)$diente['implante'];
}

// Generar HTML para el PDF
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Odontograma - <?= htmlspecialchars($paciente['nombre']) ?></title>
    <style>
        body { font-family: Arial, sans-serif; }
        .header { text-align: center; margin-bottom: 20px; }
        .patient-info { margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; }
        .medical-info { margin-bottom: 20px; border: 1px solid #ddd; padding: 15px; }
        .odontograma-container { margin-top: 30px; }
        .diente {
            position: relative;
            display: inline-block;
            margin: 10px;
            width: 60px;
            height: 80px;
        }
        .cuadro {
            background-color: #FFFFFF;
            border: 1px solid #7F7F7F;
            position: absolute;
            width: 25px;
            height: 15px;
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
        .click-red { background-color: #ff6b6b !important; border-color: #d63031 !important; }
        .click-blue { background-color: #74b9ff !important; border-color: #0984e3 !important; }
        .click-yellow { background-color: #fdcb6e !important; border: 1px solid #e17055 !important; }
        .click-yellow.center { background: radial-gradient(circle, #fdcb6e 0%, #e17055 100%) !important; }
        .click-green { background-color: #55efc4 !important; border-color: #00b894 !important; }
        .click-black { background-color: #2d3436 !important; border-color: #000 !important; }
        .tooth-absent { background-color: #b2bec3 !important; opacity: 0.7; }
        .puente-diente { border: 2px solid #2d3436 !important; position: relative; background-color: #f8f9fa !important; }
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
        .diente-container { display: flex; flex-wrap: wrap; justify-content: center; }
        .footer { margin-top: 30px; text-align: right; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Reporte de Odontograma</h1>
        <p>Generado el <?= date('d/m/Y H:i') ?></p>
    </div>

    <div class="patient-info">
        <h2>Datos del Paciente</h2>
        <p><strong>Nombre:</strong> <?= htmlspecialchars($paciente['nombre']) ?></p>
        <p><strong>Cédula:</strong> <?= htmlspecialchars($paciente['cedula']) ?></p>
        <p><strong>Edad:</strong> <?= htmlspecialchars($paciente['edad']) ?> años</p>
        <p><strong>Sexo:</strong> <?= htmlspecialchars($paciente['sexo']) ?></p>
        <p><strong>Teléfono:</strong> <?= htmlspecialchars($paciente['telefono']) ?></p>
    </div>

    <?php if ($historia_medica): ?>
    <div class="medical-info">
        <h2>Historia Médica</h2>
        <p><strong>Fecha de creación:</strong> <?= date('d/m/Y H:i', strtotime($historia_medica['fecha_creacion'])) ?></p>
        <p><strong>Examinador:</strong> <?= htmlspecialchars($historia_medica['examinador_nombre']) ?></p>
        
        <?php if ($anamnesis): ?>
        <h3>Condiciones Relevantes</h3>
        <ul>
            <?php if ($anamnesis['diabetes']): ?><li>Diabetes</li><?php endif; ?>
            <?php if ($anamnesis['hipertension']): ?><li>Hipertensión</li><?php endif; ?>
            <?php if ($anamnesis['alergias']): ?><li>Alergias</li><?php endif; ?>
            <?php if ($anamnesis['hemorragias']): ?><li>Hemorragias</li><?php endif; ?>
            <?php if ($anamnesis['hepatitis']): ?><li>Hepatitis</li><?php endif; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="odontograma-container">
        <h2>Odontograma</h2>
        <p><strong>Fecha:</strong> <?= date('d/m/Y H:i', strtotime($odontograma['fecha_creacion'])) ?></p>
        <?php if (!empty($odontograma['observaciones'])): ?>
        <p><strong>Observaciones:</strong> <?= htmlspecialchars($odontograma['observaciones']) ?></p>
        <?php endif; ?>
        
        <!-- Cuadrante Superior Derecho -->
        <div class="text-center">
            <h5 class="cuadrante-title">Cuadrante Superior Derecho</h5>
            <div class="diente-container">
                <?= generateCuadranteHtml('1', 8, 1, $dientes, '-pdf') ?>
            </div>
        </div>
        
        <!-- Cuadrante Superior Izquierdo -->
        <div class="text-center">
            <h5 class="cuadrante-title">Cuadrante Superior Izquierdo</h5>
            <div class="diente-container">
                <?= generateCuadranteHtml('2', 1, 8, $dientes, '-pdf') ?>
            </div>
        </div>
        
        <!-- Cuadrante Inferior Izquierdo -->
        <div class="text-center">
            <h5 class="cuadrante-title">Cuadrante Inferior Izquierdo</h5>
            <div class="diente-container">
                <?= generateCuadranteHtml('3', 1, 8, $dientes, '-pdf') ?>
            </div>
        </div>
        
        <!-- Cuadrante Inferior Derecho -->
        <div class="text-center">
            <h5 class="cuadrante-title">Cuadrante Inferior Derecho</h5>
            <div class="diente-container">
                <?= generateCuadranteHtml('4', 8, 1, $dientes, '-pdf') ?>
            </div>
        </div>
    </div>

    <div class="footer">
        <p>Sistema Odontológico - <?= date('Y') ?></p>
    </div>
</body>
</html>
<?php
$html = ob_get_clean();

// Incluir librerías para generar PDF
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Generar nombre del archivo
$filename = 'odontograma_' . str_replace(' ', '_', $paciente['nombre']) . '_' . date('Ymd_His') . '.pdf';

// Descargar el PDF
$dompdf->stream($filename, ['Attachment' => true]);
<?php
require_once __DIR__ . '/../includes/Database.php';

class OdontogramaController {
    private $db;
    
    public function __construct() {
        $this->db = (new Database())->getConnection();
    }
    
    public function crearOdontograma($data) {
        try {
            $this->validateOdontogramaData($data);
            $this->db->beginTransaction();
            
            // Insertar odontograma principal
            $stmt = $this->db->prepare("INSERT INTO odontograma 
                (historia_medica_id, cita_id, observaciones, fecha_creacion, creado_por) 
                VALUES (?, ?, ?, NOW(), ?)");
            $stmt->execute([
                $data['historia_medica_id'],
                !empty($data['cita_id']) ? $data['cita_id'] : null,
                $data['observaciones'] ?? null,
                $data['creado_por'] ?? null
            ]);
            $odontograma_id = $this->db->lastInsertId();
            
            // Procesar dientes con el mismo formato que en editar
            $this->processTeethData($odontograma_id, $data['dientes_json'] ?? '{}');
            
            $this->db->commit();
            return ['success' => true, 'odontograma_id' => $odontograma_id];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error al crear odontograma: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function editarOdontograma($data) {
        try {
            $this->validateOdontogramaData($data, true);
            $this->db->beginTransaction();
            
            // Actualizar odontograma principal
            $stmt = $this->db->prepare("UPDATE odontograma SET 
                cita_id = ?, 
                observaciones = ?,
                fecha_actualizacion = NOW()
                WHERE id = ?");
            $stmt->execute([
                !empty($data['cita_id']) ? $data['cita_id'] : null,
                $data['observaciones'] ?? null,
                $data['odontograma_id']
            ]);
            
            // Eliminar datos antiguos de dientes (con eliminación lógica)
            $this->deactivatePreviousTeeth($data['odontograma_id']);
            
            // Procesar nuevos datos de dientes (mismo formato que en crear)
            $this->processTeethData($data['odontograma_id'], $data['dientes_json'] ?? '{}');
            
            $this->db->commit();
            return ['success' => true, 'odontograma_id' => $data['odontograma_id']];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error al editar odontograma: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public function eliminarOdontograma($odontograma_id) {
        try {
            if (empty($odontograma_id)) {
                throw new Exception("ID de odontograma no especificado");
            }
            
            $this->db->beginTransaction();
            
            // Eliminación lógica del odontograma
            $stmt = $this->db->prepare("UPDATE odontograma SET Estado_Sistema = 'Inactivo' WHERE id = ?");
            $stmt->execute([$odontograma_id]);
            
            // Eliminación lógica de los dientes asociados
            $stmt = $this->db->prepare("UPDATE diente SET Estado_Sistema = 'Inactivo' WHERE odontograma_id = ?");
            $stmt->execute([$odontograma_id]);
            
            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error al eliminar odontograma: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function obtenerOdontograma($id) {
        try {
            if (empty($id)) {
                throw new Exception("ID de odontograma no especificado");
            }
            
            $this->db->beginTransaction();
            
            // Obtener datos principales del odontograma
            $stmt = $this->db->prepare("
                SELECT o.*, u.nombre as creado_por_nombre,
                       (SELECT nombre FROM usuario WHERE id = (
                           SELECT examinador_id FROM historia_medica WHERE id = o.historia_medica_id
                       )) as examinador_nombre
                FROM odontograma o
                LEFT JOIN usuario u ON o.creado_por = u.id
                WHERE o.id = ? AND o.Estado_Sistema = 'Activo'
            ");
            $stmt->execute([$id]);
            $odontograma = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$odontograma) {
                throw new Exception('Odontograma no encontrado o inactivo');
            }
            
            // Obtener datos de los dientes
            $dientes = $this->getTeethData($id);
            
            $this->db->commit();
            
            return [
                'success' => true,
                'odontograma' => $odontograma,
                'dientes' => $dientes
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Error al obtener odontograma: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Métodos auxiliares privados
    
    private function validateOdontogramaData($data, $isEdit = false) {
        if ($isEdit && empty($data['odontograma_id'])) {
            throw new Exception("ID de odontograma no especificado");
        }
        
        if (empty($data['historia_medica_id'])) {
            throw new Exception("Historia médica no especificada");
        }
        
        // Validar que la historia médica existe
        $stmt = $this->db->prepare("SELECT id FROM historia_medica WHERE id = ? AND Estado_Sistema = 'Activo'");
        $stmt->execute([$data['historia_medica_id']]);
        if (!$stmt->fetch()) {
            throw new Exception("Historia médica no encontrada o inactiva");
        }
        
        // Si hay cita_id, validar que existe
        if (!empty($data['cita_id'])) {
            $stmt = $this->db->prepare("SELECT id FROM citas WHERE id = ? AND estado = 'completada'");
            $stmt->execute([$data['cita_id']]);
            if (!$stmt->fetch()) {
                throw new Exception("Cita no encontrada o no completada");
            }
        }
    }
    
    private function processTeethData($odontograma_id, $dientes_json) {
        $dientes = json_decode($dientes_json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Error al decodificar datos de dientes: " . json_last_error_msg());
        }

        foreach ($dientes as $numero_diente => $diente_data) {
            // Validar número de diente (formato FDI: 11-18, 21-28, 31-38, 41-48)
            if (!preg_match('/^[1-4][1-8]$/', $numero_diente)) {
                throw new Exception("Número de diente inválido: " . $numero_diente);
            }
            
            // Insertar diente
            $stmt = $this->db->prepare("INSERT INTO diente 
                (odontograma_id, numero_diente, Estado_Sistema) 
                VALUES (?, ?, 'Activo')");
            $stmt->execute([$odontograma_id, $numero_diente]);
            $diente_id = $this->db->lastInsertId();
            
            // Insertar estado dental
            $stmt = $this->db->prepare("INSERT INTO estado_dental 
                (diente_id, ausente, fractura, caries, corona, protesis_fija, implante) 
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $diente_id,
                $diente_data['ausente'] ?? 0,
                $diente_data['fractura'] ?? 0,
                $diente_data['caries'] ?? 0,
                $diente_data['corona'] ?? 0,
                $diente_data['puente'] ?? 0,
                $diente_data['implante'] ?? 0
            ]);
            
            // Insertar secciones del diente
            if (!empty($diente_data['secciones'])) {
                $this->insertToothSections($diente_id, $diente_data['secciones']);
            }
        }
    }
    
    private function insertToothSections($diente_id, $secciones) {
        foreach ($secciones as $seccion => $color) {
            // Validar sección y color
            $validSections = ['superior', 'inferior', 'izquierda', 'derecha', 'centro'];
            $validColors = ['red', 'blue', 'yellow', 'green', 'black', 'gray'];
            
            if (!in_array($seccion, $validSections)) {
                throw new Exception("Sección de diente inválida: " . $seccion);
            }
            
            if (!in_array($color, $validColors)) {
                throw new Exception("Color de sección inválido: " . $color);
            }
            
            $stmt = $this->db->prepare("INSERT INTO secciondiente 
                (diente_id, seccion, color) VALUES (?, ?, ?)");
            $stmt->execute([$diente_id, $seccion, $color]);
        }
    }
    
    private function deactivatePreviousTeeth($odontograma_id) {
        // Marcar dientes existentes como inactivos
        $stmt = $this->db->prepare("UPDATE diente SET Estado_Sistema = 'Inactivo' WHERE odontograma_id = ?");
        $stmt->execute([$odontograma_id]);
    }
    
    private function getTeethData($odontograma_id) {
        // Obtener datos de dientes
        $stmt = $this->db->prepare("
            SELECT d.id, d.numero_diente, 
                   ed.ausente, ed.fractura, ed.caries, ed.corona, 
                   ed.protesis_fija as puente, ed.implante
            FROM diente d
            LEFT JOIN estado_dental ed ON d.id = ed.diente_id
            WHERE d.odontograma_id = ? AND d.Estado_Sistema = 'Activo'
        ");
        $stmt->execute([$odontograma_id]);
        $dientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($dientes as &$diente) {
            // Convertir valores booleanos
            $diente['ausente'] = (bool)$diente['ausente'];
            $diente['fractura'] = (bool)$diente['fractura'];
            $diente['caries'] = (bool)$diente['caries'];
            $diente['corona'] = (bool)$diente['corona'];
            $diente['puente'] = (bool)$diente['puente'];
            $diente['implante'] = (bool)$diente['implante'];
            
            // Obtener secciones
            $stmt = $this->db->prepare("
                SELECT seccion, color 
                FROM secciondiente 
                WHERE diente_id = ?
            ");
            $stmt->execute([$diente['id']]);
            $secciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $diente['secciones'] = [];
            foreach ($secciones as $seccion) {
                $diente['secciones'][$seccion['seccion']] = $seccion['color'];
            }
        }
        
        return $dientes;
    }
}

// Manejo de solicitudes
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Verificar CSRF token
    if (empty($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
        exit;
    }
    
    $controller = new OdontogramaController();
    $response = [];
    
    try {
        switch ($_POST['action']) {
            case 'crear':
                $response = $controller->crearOdontograma([
                    'historia_medica_id' => $_POST['historia_medica_id'],
                    'cita_id' => $_POST['cita_id'] ?? null,
                    'observaciones' => $_POST['observaciones'] ?? null,
                    'dientes_json' => $_POST['dientes_json'] ?? '{}',
                    'creado_por' => $_SESSION['usuario']['id'] ?? null
                ]);
                break;
                
            case 'editar':
                $response = $controller->editarOdontograma([
                    'odontograma_id' => $_POST['odontograma_id'],
                    'historia_medica_id' => $_POST['historia_medica_id'],
                    'cita_id' => $_POST['cita_id'] ?? null,
                    'observaciones' => $_POST['observaciones'] ?? null,
                    'dientes_json' => $_POST['dientes_json'] ?? '{}'
                ]);
                break;
                
            case 'eliminar':
                $response = $controller->eliminarOdontograma($_POST['odontograma_id']);
                break;
                
            case 'obtener':
                $response = $controller->obtenerOdontograma($_POST['odontograma_id']);
                break;
                
            default:
                $response = ['success' => false, 'error' => 'Acción no válida'];
        }
    } catch (Exception $e) {
        $response = ['success' => false, 'error' => $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}
<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$usuario_actual = (object) $_SESSION['usuario'];
$database = new Database();
$conn = $database->getConnection();

// Verificar permisos
$permisos_acciones = $conn->query("SELECT * FROM auth_acciones WHERE rol_type = '{$usuario_actual->rol_type}'")->fetch(PDO::FETCH_ASSOC);

if (!$permisos_acciones || !$permisos_acciones['auth_eliminar']) {
    $_SESSION['error_message'] = 'No tienes permiso para realizar esta acción';
    header("Location: historias_medicas.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'])) {
    $id = $_POST['id'];
    
    try {
        // Verificar que la historia pertenece al odontólogo (si es necesario)
        if ($usuario_actual->rol_type === 'O') {
            $stmt = $conn->prepare("SELECT examinador_id FROM Historia_Medica WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $historia = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$historia || $historia['examinador_id'] != $usuario_actual->id) {
                throw new Exception("No tienes permiso para modificar esta historia médica");
            }
        }
        
        // Marcar como inactivo en lugar de eliminar
        $stmt = $conn->prepare("UPDATE Historia_Medica SET Estado_Sistema = 'Inactivo' WHERE id = :id");
        $stmt->execute([':id' => $id]);
        
        // Registrar en auditoría
        $datos_originales = json_encode([
            'id' => $id,
            'fecha_eliminacion' => date('Y-m-d H:i:s'),
            'eliminado_por' => $usuario_actual->id
        ]);
        
        $auditoria_sql = "INSERT INTO auditoria_eliminaciones 
                         (tabla_afectada, id_registro_afectado, usuario_eliminador_id, nombre_usuario_eliminador, datos_originales) 
                         VALUES 
                         ('Historia_Medica', :id, :user_id, :user_name, :datos)";
        $auditoria_stmt = $conn->prepare($auditoria_sql);
        $auditoria_stmt->execute([
            ':id' => $id,
            ':user_id' => $usuario_actual->id,
            ':user_name' => $usuario_actual->nombre,
            ':datos' => $datos_originales
        ]);
        
        $_SESSION['success_message'] = 'Historia médica marcada como inactiva correctamente';
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error al marcar la historia médica como inactiva: ' . $e->getMessage();
    }
}

header("Location: view.php");
exit;
?>
<?php
$page_title = "Horarios Odontólogos";
session_start();
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Usuario.php';
require_once __DIR__ . '/../includes/HorarioDoctor.php';
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

// Obtener permisos
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

// Verificar permisos para acceder a esta sección
if (!$permisos_secciones || !$permisos_secciones['auth_horarios']) {
    $errorHandler->logError('WARNING', "Intento de acceso no autorizado a horarios por usuario ID: {$usuario_actual->id}");
    header("Location: /Odontologia/templates/unauthorized.php");
    exit;
}

// Obtener lista de odontólogos (para secretarias/admin)
$odontologos = [];
if ($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S') {
    try {
        $stmt = $db->prepare("SELECT id, nombre FROM usuario WHERE rol_type = 'O' AND Estado_Sistema = 'Activo' ORDER BY nombre");
        $stmt->execute();
        $odontologos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $errorHandler->logError('ERROR', "Error al obtener odontólogos: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    }
}

// Determinar el odontólogo cuyos horarios se están viendo/editan
$odontologo_id = $usuario_actual->id;
$odontologo_seleccionado = null;

if (($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S') && isset($_GET['odontologo_id']) && is_numeric($_GET['odontologo_id'])) {
    $odontologo_id = (int)$_GET['odontologo_id'];
    
    // Obtener información del odontólogo seleccionado
    try {
        $stmt = $db->prepare("SELECT id, nombre FROM usuario WHERE id = :id AND rol_type = 'O'");
        $stmt->bindParam(':id', $odontologo_id, PDO::PARAM_INT);
        $stmt->execute();
        $odontologo_seleccionado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$odontologo_seleccionado) {
            $_SESSION['error'] = "Odontólogo no encontrado";
            header("Location: horarios.php");
            exit;
        }
    } catch (Exception $e) {
        $errorHandler->logError('ERROR', "Error al obtener odontólogo: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
        $_SESSION['error'] = "Error al cargar información del odontólogo";
        header("Location: horarios.php");
        exit;
    }
}

// Obtener horarios del odontólogo seleccionado
$horarios = [];
$horarios_por_dia = []; // Para organizar horarios por día
$tiene_horarios = false;

try {
    $stmt = $db->prepare("SELECT * FROM horarios_doctor WHERE usuario_id = :usuario_id ORDER BY dia_semana, hora_inicio");
    $stmt->bindParam(':usuario_id', $odontologo_id, PDO::PARAM_INT);
    $stmt->execute();
    $horarios_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($horarios_data as $data) {
        $tiene_horarios = true;
        $horario = new HorarioDoctor();
        $horario->setId($data['id']);
        $horario->setUsuarioId($data['usuario_id']);
        $horario->setDiaSemana($data['dia_semana']);
        $horario->setHoraInicio($data['hora_inicio']);
        $horario->setHoraFin($data['hora_fin']);
        $horario->setActivo($data['activo']);
        $horarios[] = $horario;
        
        // Organizar por día
        if (!isset($horarios_por_dia[$data['dia_semana']])) {
            $horarios_por_dia[$data['dia_semana']] = [];
        }
        $horarios_por_dia[$data['dia_semana']][] = $horario;
    }
} catch (Exception $e) {
    $errorHandler->logError('ERROR', "Error al obtener horarios: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
    $_SESSION['error'] = "Error al cargar los horarios";
}

// Manejo de POST para todas las acciones con validación CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar token CSRF
    if (!isset($_POST['csrf_token'])) {
        $errorHandler->logError('WARNING', "Token CSRF faltante en solicitud POST");
        $_SESSION['error'] = "Token de seguridad inválido";
        header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
        exit;
    }
    
    if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errorHandler->logError('WARNING', "Intento de CSRF detectado por usuario ID: {$usuario_actual->id}");
        $_SESSION['error'] = "Token de seguridad inválido";
        header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
        exit;
    }

    // Validar acción
    $acciones_permitidas = ['crear', 'editar', 'eliminar', 'toggle_activo', 'crear_semana'];
    if (!isset($_POST['action']) || !in_array($_POST['action'], $acciones_permitidas)) {
        $errorHandler->logError('WARNING', "Acción no válida recibida: " . ($_POST['action'] ?? 'null'));
        $_SESSION['error'] = "Acción no válida";
        header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
        exit;
    }

    // Validar permisos para cada acción
    $accionPermitida = false;
    $accion = $_POST['action'];
    
    switch($accion) {
        case 'crear':
        case 'crear_semana':
            $accionPermitida = $permisos_acciones && $permisos_acciones['auth_crear'];
            break;
        case 'editar':
            $accionPermitida = $permisos_acciones && $permisos_acciones['auth_editar'];
            break;
        case 'eliminar':
            $accionPermitida = $permisos_acciones && $permisos_acciones['auth_eliminar'];
            break;
        case 'toggle_activo':
            $accionPermitida = $permisos_acciones && $permisos_acciones['auth_editar'];
            break;
    }
    
    if (!$accionPermitida) {
        $errorHandler->logError('WARNING', "Intento de acción no autorizada ($accion) por usuario ID: {$usuario_actual->id}");
        $_SESSION['error'] = "No tienes permiso para realizar esta acción";
        header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
        exit;
    }

    // Validar que el usuario puede modificar este horario (secretarias pueden modificar cualquier horario)
    $usuario_puede_modificar = ($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S');
    
    if (!$usuario_puede_modificar) {
        // Para odontólogos, solo pueden modificar sus propios horarios
        if (isset($_POST['usuario_id'])) {
            $usuario_puede_modificar = ($_POST['usuario_id'] == $usuario_actual->id);
        } else {
            $usuario_puede_modificar = ($odontologo_id == $usuario_actual->id);
        }
    }

    if (!$usuario_puede_modificar) {
        $errorHandler->logError('WARNING', "Intento de modificar horario no permitido por usuario ID: {$usuario_actual->id}");
        $_SESSION['error'] = "No tienes permiso para modificar este horario";
        header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
        exit;
    }

    // Procesar acción de creación masiva de horarios (para odontólogos)
    if ($accion === 'crear_semana') {
        try {
            // Verificar si ya tiene horarios registrados
            if ($tiene_horarios) {
                $_SESSION['error'] = "Ya tienes horarios registrados. Solo puedes editar los existentes.";
                header("Location: horarios.php");
                exit;
            }
            
            // Validar campos
            $dias_horarios = [];
            $errores = [];
            
            for ($dia = 1; $dia <= 7; $dia++) {
                $hora_inicio = $_POST["hora_inicio_$dia"] ?? '';
                $hora_fin = $_POST["hora_fin_$dia"] ?? '';
                $activo = isset($_POST["activo_$dia"]) ? 1 : 0;
                
                // Solo validar si está activo
                if ($activo) {
                    if (empty($hora_inicio)) {
                        $errores[] = "Falta hora de inicio para el día $dia";
                        continue;
                    }
                    
                    if (empty($hora_fin)) {
                        $errores[] = "Falta hora de fin para el día $dia";
                        continue;
                    }
                    
                    if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora_inicio) || 
                        !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora_fin)) {
                        $errores[] = "Formato de hora inválido para el día $dia (use HH:MM)";
                        continue;
                    }
                    
                    $hora_inicio_dt = DateTime::createFromFormat('H:i', $hora_inicio);
                    $hora_fin_dt = DateTime::createFromFormat('H:i', $hora_fin);
                    
                    if ($hora_fin_dt <= $hora_inicio_dt) {
                        $errores[] = "La hora de fin debe ser posterior a la hora de inicio para el día $dia";
                        continue;
                    }
                    
                    $dias_horarios[$dia] = [
                        'hora_inicio' => $hora_inicio,
                        'hora_fin' => $hora_fin,
                        'activo' => $activo
                    ];
                }
            }
            
            if (!empty($errores)) {
                $_SESSION['error'] = implode("<br>", $errores);
                header("Location: horarios.php");
                exit;
            }
            
            if (empty($dias_horarios)) {
                $_SESSION['error'] = "Debe registrar al menos un día de horario";
                header("Location: horarios.php");
                exit;
            }
            
            // Insertar todos los horarios
            $db->beginTransaction();
            
            try {
                foreach ($dias_horarios as $dia => $horario_data) {
                    $horario = new HorarioDoctor();
                    $horario->setUsuarioId($usuario_actual->id);
                    $horario->setDiaSemana($dia);
                    $horario->setHoraInicio($horario_data['hora_inicio']);
                    $horario->setHoraFin($horario_data['hora_fin']);
                    $horario->setActivo($horario_data['activo']);
                    
                    if (!$horario->save($db)) {
                        throw new Exception("Error al guardar horario para el día $dia");
                    }
                }
                
                $db->commit();
                $_SESSION['success'] = "Horario semanal creado exitosamente";
                $errorHandler->logError('INFO', "Horario semanal creado para usuario ID: {$usuario_actual->id}");
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al crear horario semanal: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al crear el horario semanal";
        }
        
        header("Location: horarios.php");
        exit;
    }

    // Procesar acción de creación individual (para admin/secretarias)
    if ($accion === 'crear') {
        try {
            // Validar y sanitizar campos
            $odontologo_id_crear = isset($_POST['odontologo_id']) ? (int)$_POST['odontologo_id'] : $odontologo_id;
            $dia_semana = isset($_POST['dia_semana']) ? (int)$_POST['dia_semana'] : 0;
            $hora_inicio = isset($_POST['hora_inicio']) ? $_POST['hora_inicio'] : '';
            $hora_fin = isset($_POST['hora_fin']) ? $_POST['hora_fin'] : '';
            
            // Validaciones
            if ($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S') {
                if (empty($odontologo_id_crear)) {
                    $_SESSION['error'] = "Debe seleccionar un odontólogo";
                    header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                    exit;
                }
            }
            
            if ($dia_semana < 1 || $dia_semana > 7) {
                $_SESSION['error'] = "Día de la semana inválido";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora_inicio) || 
                !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora_fin)) {
                $_SESSION['error'] = "Formato de hora inválido (use HH:MM)";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            // Convertir a objetos DateTime para comparar
            $hora_inicio_dt = DateTime::createFromFormat('H:i', $hora_inicio);
            $hora_fin_dt = DateTime::createFromFormat('H:i', $hora_fin);
            
            if ($hora_fin_dt <= $hora_inicio_dt) {
                $_SESSION['error'] = "La hora de fin debe ser posterior a la hora de inicio";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            // Verificar superposición de horarios
            $stmt = $db->prepare("SELECT id FROM horarios_doctor 
                                 WHERE usuario_id = :usuario_id 
                                 AND dia_semana = :dia_semana 
                                 AND (
                                     (:hora_inicio BETWEEN hora_inicio AND hora_fin)
                                     OR (:hora_fin BETWEEN hora_inicio AND hora_fin)
                                     OR (hora_inicio BETWEEN :hora_inicio AND :hora_fin)
                                 )");
            $stmt->bindParam(':usuario_id', $odontologo_id_crear, PDO::PARAM_INT);
            $stmt->bindParam(':dia_semana', $dia_semana, PDO::PARAM_INT);
            $stmt->bindParam(':hora_inicio', $hora_inicio);
            $stmt->bindParam(':hora_fin', $hora_fin);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                $_SESSION['error'] = "El nuevo horario se superpone con uno existente";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            $horario = new HorarioDoctor();
            $horario->setUsuarioId($odontologo_id_crear);
            $horario->setDiaSemana($dia_semana);
            $horario->setHoraInicio($hora_inicio);
            $horario->setHoraFin($hora_fin);
            $horario->setActivo(1); // Por defecto activo
            
            if ($horario->save($db)) {
                $_SESSION['success'] = "Horario creado exitosamente";
                $errorHandler->logError('INFO', "Horario creado para usuario ID: {$odontologo_id_crear}");
            } else {
                $_SESSION['error'] = "Error al crear el horario";
                $errorHandler->logError('ERROR', "Error al crear horario para usuario ID: {$odontologo_id_crear}");
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al crear horario: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al crear el horario";
        }
        
        header("Location: horarios.php" . (($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S') && isset($odontologo_id_crear) ? "?odontologo_id=$odontologo_id_crear" : ""));
        exit;
    }

    // Procesar acción de edición
    if ($accion === 'editar') {
        try {
            if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
                $_SESSION['error'] = "ID de horario inválido";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            $id = (int)$_POST['id'];
            $dia_semana = isset($_POST['dia_semana']) ? (int)$_POST['dia_semana'] : 0;
            $hora_inicio = isset($_POST['hora_inicio']) ? $_POST['hora_inicio'] : '';
            $hora_fin = isset($_POST['hora_fin']) ? $_POST['hora_fin'] : '';
            
            // Validaciones
            if ($dia_semana < 1 || $dia_semana > 7) {
                $_SESSION['error'] = "Día de la semana inválido";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora_inicio) || 
                !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora_fin)) {
                $_SESSION['error'] = "Formato de hora inválido (use HH:MM)";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            // Convertir a objetos DateTime para comparar
            $hora_inicio_dt = DateTime::createFromFormat('H:i', $hora_inicio);
            $hora_fin_dt = DateTime::createFromFormat('H:i', $hora_fin);
            
            if ($hora_fin_dt <= $hora_inicio_dt) {
                $_SESSION['error'] = "La hora de fin debe ser posterior a la hora de inicio";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            // Verificar superposición de horarios (excluyendo el actual)
            $stmt = $db->prepare("SELECT id FROM horarios_doctor 
                                 WHERE usuario_id = :usuario_id 
                                 AND dia_semana = :dia_semana 
                                 AND id != :id
                                 AND (
                                     (:hora_inicio BETWEEN hora_inicio AND hora_fin)
                                     OR (:hora_fin BETWEEN hora_inicio AND hora_fin)
                                     OR (hora_inicio BETWEEN :hora_inicio AND :hora_fin)
                                 )");
            $stmt->bindParam(':usuario_id', $odontologo_id, PDO::PARAM_INT);
            $stmt->bindParam(':dia_semana', $dia_semana, PDO::PARAM_INT);
            $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt->bindParam(':hora_inicio', $hora_inicio);
            $stmt->bindParam(':hora_fin', $hora_fin);
            $stmt->execute();
            
            if ($stmt->fetch()) {
                $_SESSION['error'] = "El horario editado se superpone con uno existente";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            $horario = HorarioDoctor::find($db, $id);
            if (!$horario) {
                $_SESSION['error'] = "Horario no encontrado";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            $horario->setDiaSemana($dia_semana);
            $horario->setHoraInicio($hora_inicio);
            $horario->setHoraFin($hora_fin);
            
            if ($horario->save($db)) {
                $_SESSION['success'] = "Horario actualizado exitosamente";
                $errorHandler->logError('INFO', "Horario actualizado ID: {$id}");
            } else {
                $_SESSION['error'] = "Error al actualizar el horario";
                $errorHandler->logError('ERROR', "Error al actualizar horario ID: {$id}");
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al actualizar horario: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al actualizar el horario";
        }
    }

    // Procesar acción de eliminación
    if ($accion === 'eliminar') {
        try {
            if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
                $_SESSION['error'] = "ID de horario inválido";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            $id = (int)$_POST['id'];
            
            $horario = HorarioDoctor::find($db, $id);
            if (!$horario) {
                $_SESSION['error'] = "Horario no encontrado";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            // Verificar si hay citas asociadas a este horario
            $stmt = $db->prepare("SELECT COUNT(*) FROM citas 
                                 WHERE usuario_id = :usuario_id 
                                 AND fecha >= CURDATE() 
                                 AND estado IN ('Pendiente', 'Confirmado')
                                 AND TIME(hora) BETWEEN :hora_inicio AND :hora_fin
                                 AND DAYOFWEEK(fecha) = :dia_semana + 1"); // MySQL usa 1=Domingo, 2=Lunes...
            $stmt->bindParam(':usuario_id', $odontologo_id, PDO::PARAM_INT);
            $stmt->bindValue(':hora_inicio', $horario->getHoraInicio());
            $stmt->bindValue(':hora_fin', $horario->getHoraFin());
            $stmt->bindValue(':dia_semana', $horario->getDiaSemana(), PDO::PARAM_INT);
            $stmt->execute();
            $citas_count = $stmt->fetchColumn();
            
            if ($citas_count > 0) {
                $_SESSION['error'] = "No se puede eliminar el horario porque tiene citas futuras programadas";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            if ($horario->delete($db)) {
                $_SESSION['success'] = "Horario eliminado exitosamente";
                $errorHandler->logError('INFO', "Horario eliminado ID: {$id}");
            } else {
                $_SESSION['error'] = "Error al eliminar el horario";
                $errorHandler->logError('ERROR', "Error al eliminar horario ID: {$id}");
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al eliminar horario: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al eliminar el horario";
        }
    }

    // Procesar acción de toggle activo/inactivo
    if ($accion === 'toggle_activo') {
        try {
            if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
                $_SESSION['error'] = "ID de horario inválido";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            $id = (int)$_POST['id'];
            
            $horario = HorarioDoctor::find($db, $id);
            if (!$horario) {
                $_SESSION['error'] = "Horario no encontrado";
                header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
                exit;
            }
            
            $nuevo_estado = $horario->getActivo() ? 0 : 1;
            $horario->setActivo($nuevo_estado);
            
            if ($horario->save($db)) {
                $_SESSION['success'] = "Estado del horario actualizado";
                $errorHandler->logError('INFO', "Estado de horario cambiado ID: {$id}, nuevo estado: {$nuevo_estado}");
            } else {
                $_SESSION['error'] = "Error al cambiar el estado del horario";
                $errorHandler->logError('ERROR', "Error al cambiar estado de horario ID: {$id}");
            }
        } catch (Exception $e) {
            $errorHandler->logError('ERROR', "Error al cambiar estado de horario: " . $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString());
            $_SESSION['error'] = "Error al cambiar el estado del horario";
        }
    }

    header("Location: horarios.php" . ($odontologo_id !== $usuario_actual->id ? "?odontologo_id=$odontologo_id" : ""));
    exit;
}

// Generar token CSRF para el formulario si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
include '../templates/navbar.php';
?>
<style>
/* Estilos mejorados para la visualización de horarios */
.badge-active {
    background-color: #28a745;
    color: white;
}

.badge-inactive {
    background-color: #6c757d;
    color: white;
}

.table-horarios {
    font-size: 0.9rem;
}

.table-horarios th {
    background-color: #f8f9fa;
    font-weight: 600;
}

.table-horarios td, .table-horarios th {
    vertical-align: middle;
    padding: 0.75rem;
}

.schedule-container {
    background-color: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.schedule-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 1px solid #dee2e6;
}

.schedule-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: #343a40;
}

.schedule-day-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    margin-bottom: 15px;
    overflow: hidden;
}

.schedule-day-header {
    background-color: #e9ecef;
    padding: 10px 15px;
    font-weight: 600;
    color: #495057;
    border-bottom: 1px solid #dee2e6;
}

.schedule-day-body {
    padding: 15px;
}

.schedule-time-slot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px dashed #dee2e6;
}

.schedule-time-slot:last-child {
    border-bottom: none;
}

.schedule-time {
    font-weight: 500;
}

.schedule-actions {
    display: flex;
    gap: 8px;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
}

/* Estilos para el selector de odontólogo */
.select-odontologo {
    max-width: 300px;
}

/* Estilos para los modales */
.modal-content {
    border: none;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.15);
}

.modal-header {
    border-bottom: none;
    padding-bottom: 0;
}

.modal-footer {
    border-top: none;
    padding-top: 0;
}

/* Animaciones */
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.animate-fade {
    animation: fadeIn 0.3s ease-in-out;
}

/* Responsividad */
@media (max-width: 768px) {
    .schedule-time-slot {
        flex-direction: column;
        align-items: flex-start;
        gap: 8px;
    }
    
    .schedule-actions {
        width: 100%;
        justify-content: flex-end;
    }
}
</style>
<div class="container schedule-container animate-fade">
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

    <div class="schedule-header">
        <h1 class="schedule-title">
            <?php if ($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S'): ?>
                Horarios de <?= $odontologo_seleccionado ? htmlspecialchars($odontologo_seleccionado['nombre'], ENT_QUOTES, 'UTF-8') : 'Odontólogos' ?>
            <?php else: ?>
                Mi Horario de Trabajo
            <?php endif; ?>
        </h1>
        
        <?php if ($permisos_acciones && $permisos_acciones['auth_crear']): ?>
            <?php if ($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S'): ?>
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalCrear">
                    <i class="bi bi-plus-circle"></i> Nuevo Horario
                </button>
            <?php elseif (!$tiene_horarios): ?>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearSemanal">
                    <i class="bi bi-calendar-week"></i> Registrar Horario Semanal
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Selector de odontólogo (solo para admin/secretarias) -->
    <?php if (($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S') && !empty($odontologos)): ?>
        <div class="mb-4">
            <form method="GET" class="row g-3 align-items-center">
                <div class="col-auto">
                    <label for="odontologoSelect" class="col-form-label">Odontólogo:</label>
                </div>
                <div class="col-auto">
                    <select id="odontologoSelect" name="odontologo_id" class="form-select select-odontologo">
                        <option value="">Seleccione un odontólogo</option>
                        <?php foreach ($odontologos as $odontologo): ?>
                            <option value="<?= $odontologo['id'] ?>" <?= $odontologo['id'] == $odontologo_id ? 'selected' : '' ?>>
                                <?= htmlspecialchars($odontologo['nombre'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary">Ver Horarios</button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($tiene_horarios): ?>
        <!-- Vista mejorada para administradores/secretarias -->
        <?php if ($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S'): ?>
            <div class="row">
                <?php 
                $dias_semana = [
                    1 => 'Lunes',
                    2 => 'Martes',
                    3 => 'Miércoles',
                    4 => 'Jueves',
                    5 => 'Viernes',
                    6 => 'Sábado',
                    7 => 'Domingo'
                ];
                
                foreach ($dias_semana as $dia_num => $dia_nombre): ?>
                    <div class="col-md-6 col-lg-4 mb-3">
                        <div class="schedule-day-card">
                            <div class="schedule-day-header">
                                <?= $dia_nombre ?>
                            </div>
                            <div class="schedule-day-body">
                                <?php if (isset($horarios_por_dia[$dia_num])): ?>
                                    <?php foreach ($horarios_por_dia[$dia_num] as $horario): ?>
                                        <div class="schedule-time-slot">
                                            <div class="schedule-time">
                                                <?= htmlspecialchars($horario->getHoraInicio(), ENT_QUOTES, 'UTF-8') ?> - 
                                                <?= htmlspecialchars($horario->getHoraFin(), ENT_QUOTES, 'UTF-8') ?>
                                                <span class="badge rounded-pill ms-2 <?= $horario->getActivo() ? 'badge-active' : 'badge-inactive' ?>">
                                                    <?= $horario->getActivo() ? 'Activo' : 'Inactivo' ?>
                                                </span>
                                            </div>
                                            
                                            <?php if ($permisos_acciones && ($permisos_acciones['auth_editar'] || $permisos_acciones['auth_eliminar'])): ?>
                                                <div class="schedule-actions">
                                                    <?php if ($permisos_acciones['auth_editar']): ?>
                                                        <button class="btn btn-sm btn-outline-warning" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#modalEditar<?= $horario->getId() ?>">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <form method="POST" action="" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_activo">
                                                        <input type="hidden" name="id" value="<?= $horario->getId() ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <button type="submit" class="btn btn-sm <?= $horario->getActivo() ? 'btn-outline-secondary' : 'btn-outline-success' ?>">
                                                            <i class="bi bi-power"></i>
                                                        </button>
                                                    </form>
                                                    
                                                    <?php if ($permisos_acciones['auth_eliminar']): ?>
                                                        <button class="btn btn-sm btn-outline-danger" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#modalEliminar<?= $horario->getId() ?>">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted mb-0">No hay horarios registrados</p>
                                <?php endif; ?>
                                
                                <?php if ($permisos_acciones && $permisos_acciones['auth_crear']): ?>
                                    <button class="btn btn-sm btn-outline-success w-100 mt-2" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalCrear"
                                            data-dia="<?= $dia_num ?>">
                                        <i class="bi bi-plus"></i> Agregar horario
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Vista para odontólogos (organizada por días) -->
            <div class="row">
                <?php foreach ($dias_semana as $dia_num => $dia_nombre): ?>
                    <div class="col-md-6 mb-3">
                        <div class="schedule-day">
                            <div class="schedule-day-header">
                                <?= $dia_nombre ?>
                            </div>
                            <div class="schedule-day-body">
                                <?php if (isset($horarios_por_dia[$dia_num])): ?>
                                    <?php foreach ($horarios_por_dia[$dia_num] as $horario): ?>
                                        <div class="schedule-time">
                                            <span>
                                                <?= htmlspecialchars($horario->getHoraInicio(), ENT_QUOTES, 'UTF-8') ?> - 
                                                <?= htmlspecialchars($horario->getHoraFin(), ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                            <span class="badge rounded-pill <?= $horario->getActivo() ? 'badge-active' : 'badge-inactive' ?>">
                                                <?= $horario->getActivo() ? 'Activo' : 'Inactivo' ?>
                                            </span>
                                            
                                            <?php if ($permisos_acciones && $permisos_acciones['auth_editar']): ?>
                                                <button class="btn btn-sm btn-outline-warning ms-auto" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#modalEditar<?= $horario->getId() ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted">No hay horarios registrados para este día</p>
                                <?php endif; ?>
                                
                                <?php if ($permisos_acciones && $permisos_acciones['auth_crear']): ?>
                                    <button class="btn btn-sm btn-outline-success mt-2" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#modalCrear"
                                            data-dia="<?= $dia_num ?>">
                                        <i class="bi bi-plus"></i> Agregar horario
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php elseif ($usuario_actual->rol_type === 'O'): ?>
        <!-- Formulario para creación masiva de horarios (solo para odontólogos sin horarios) -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">Registrar Mi Horario Semanal</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" class="week-schedule-form">
                    <input type="hidden" name="action" value="crear_semana">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                    
                    <?php
                    $dias_semana = [
                        1 => 'Lunes',
                        2 => 'Martes',
                        3 => 'Miércoles',
                        4 => 'Jueves',
                        5 => 'Viernes',
                        6 => 'Sábado',
                        7 => 'Domingo'
                    ];
                    
                    foreach ($dias_semana as $dia_num => $dia_nombre): ?>
                        <div class="form-check">
                            <input class="form-check-input day-checkbox" type="checkbox" id="activo_<?= $dia_num ?>" 
                                   name="activo_<?= $dia_num ?>" value="1" checked>
                            <label class="form-check-label" for="activo_<?= $dia_num ?>">
                                <strong><?= $dia_nombre ?></strong>
                            </label>
                        </div>
                        
                        <div class="time-inputs">
                            <div class="form-group">
                                <label for="hora_inicio_<?= $dia_num ?>">Hora de inicio</label>
                                <input type="time" class="form-control input-hora" 
                                       id="hora_inicio_<?= $dia_num ?>" name="hora_inicio_<?= $dia_num ?>" 
                                       value="08:00" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="hora_fin_<?= $dia_num ?>">Hora de fin</label>
                                <input type="time" class="form-control input-hora" 
                                       id="hora_fin_<?= $dia_num ?>" name="hora_fin_<?= $dia_num ?>" 
                                       value="17:00" required>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Guardar Horario Semanal</button>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">No hay horarios registrados<?= ($odontologo_id !== $usuario_actual->id) ? ' para este odontólogo' : '' ?>.</div>
    <?php endif; ?>
</div>

<!-- Modal CREAR -->
<?php if ($permisos_acciones && $permisos_acciones['auth_crear']): ?>
<div class="modal fade" id="modalCrear" tabindex="-1" aria-labelledby="modalCrearLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content animate__animated animate__fadeInDown">
      <form method="POST" action="" novalidate>
        <input type="hidden" name="action" value="crear">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        
        <div class="modal-header bg-success text-white">
          <h5 class="modal-title" id="modalCrearLabel">Nuevo Horario</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        
        <div class="modal-body">
          <?php if ($usuario_actual->rol_type === 'A' || $usuario_actual->rol_type === 'S'): ?>
          <div class="mb-3">
            <label for="odontologoCrear" class="form-label">Odontólogo</label>
            <select name="odontologo_id" id="odontologoCrear" class="form-select" required>
              <option value="" selected disabled>Seleccione un odontólogo</option>
              <?php foreach ($odontologos as $odontologo): ?>
                <option value="<?= $odontologo['id'] ?>"><?= htmlspecialchars($odontologo['nombre'], ENT_QUOTES, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback">Por favor seleccione un odontólogo</div>
          </div>
          <?php else: ?>
            <input type="hidden" name="odontologo_id" value="<?= $usuario_actual->id ?>">
          <?php endif; ?>
          
          <div class="mb-3">
            <label for="diaSemanaCrear" class="form-label">Día de la semana</label>
            <select name="dia_semana" id="diaSemanaCrear" class="form-select" required>
              <option value="" selected disabled>Seleccione un día</option>
              <option value="1">Lunes</option>
              <option value="2">Martes</option>
              <option value="3">Miércoles</option>
              <option value="4">Jueves</option>
              <option value="5">Viernes</option>
              <option value="6">Sábado</option>
              <option value="7">Domingo</option>
            </select>
            <div class="invalid-feedback">Por favor seleccione un día</div>
          </div>
          
          <div class="row">
            <div class="col-md-6 mb-3">
              <label for="horaInicioCrear" class="form-label">Hora de inicio</label>
              <input type="time" name="hora_inicio" id="horaInicioCrear" class="form-control input-hora" required>
              <div class="invalid-feedback">Por favor ingrese una hora válida</div>
            </div>
            
            <div class="col-md-6 mb-3">
              <label for="horaFinCrear" class="form-label">Hora de fin</label>
              <input type="time" name="hora_fin" id="horaFinCrear" class="form-control input-hora" required>
              <div class="invalid-feedback">Por favor ingrese una hora válida</div>
            </div>
          </div>
        </div>
        
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success">Crear Horario</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modales para cada horario -->
<?php foreach ($horarios as $horario): ?>
<!-- Modal EDITAR -->
<?php if ($permisos_acciones && $permisos_acciones['auth_editar']): ?>
<div class="modal fade" id="modalEditar<?= $horario->getId() ?>" tabindex="-1" aria-labelledby="modalEditarLabel<?= $horario->getId() ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content animate__animated animate__fadeInDown">
      <form method="POST" action="" novalidate>
        <input type="hidden" name="action" value="editar">
        <input type="hidden" name="id" value="<?= $horario->getId() ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="usuario_id" value="<?= $horario->getUsuarioId() ?>">
        
        <div class="modal-header bg-warning text-dark">
          <h5 class="modal-title" id="modalEditarLabel<?= $horario->getId() ?>">Editar Horario</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        
        <div class="modal-body">
          <div class="mb-3">
            <label for="diaSemanaEditar<?= $horario->getId() ?>" class="form-label">Día de la semana</label>
            <select name="dia_semana" id="diaSemanaEditar<?= $horario->getId() ?>" class="form-select" required>
              <?php 
                $dias = [
                    1 => 'Lunes',
                    2 => 'Martes',
                    3 => 'Miércoles',
                    4 => 'Jueves',
                    5 => 'Viernes',
                    6 => 'Sábado',
                    7 => 'Domingo'
                ];
                
                foreach ($dias as $valor => $nombre): 
              ?>
                <option value="<?= $valor ?>" <?= $valor == $horario->getDiaSemana() ? 'selected' : '' ?>>
                  <?= $nombre ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback">Por favor seleccione un día</div>
          </div>
          
          <div class="row">
            <div class="col-md-6 mb-3">
              <label for="horaInicioEditar<?= $horario->getId() ?>" class="form-label">Hora de inicio</label>
              <input type="time" name="hora_inicio" id="horaInicioEditar<?= $horario->getId() ?>" 
                     class="form-control input-hora" 
                     value="<?= htmlspecialchars($horario->getHoraInicio(), ENT_QUOTES, 'UTF-8') ?>" 
                     required>
              <div class="invalid-feedback">Por favor ingrese una hora válida</div>
            </div>
            
            <div class="col-md-6 mb-3">
              <label for="horaFinEditar<?= $horario->getId() ?>" class="form-label">Hora de fin</label>
              <input type="time" name="hora_fin" id="horaFinEditar<?= $horario->getId() ?>" 
                     class="form-control input-hora" 
                     value="<?= htmlspecialchars($horario->getHoraFin(), ENT_QUOTES, 'UTF-8') ?>" 
                     required>
              <div class="invalid-feedback">Por favor ingrese una hora válida</div>
            </div>
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

<!-- Modal ELIMINAR -->
<?php if ($permisos_acciones && $permisos_acciones['auth_eliminar']): ?>
<div class="modal fade" id="modalEliminar<?= $horario->getId() ?>" tabindex="-1" aria-labelledby="modalEliminarLabel<?= $horario->getId() ?>" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content animate__animated animate__zoomIn">
      <form method="POST" action="">
        <input type="hidden" name="action" value="eliminar">
        <input type="hidden" name="id" value="<?= $horario->getId() ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
        
        <div class="modal-header bg-danger text-white">
          <h5 class="modal-title" id="modalEliminarLabel<?= $horario->getId() ?>">Confirmar Eliminación</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        
        <div class="modal-body">
          <p>¿Estás seguro de que deseas eliminar este horario?</p>
          <p><strong>Día:</strong> <?= $dias[$horario->getDiaSemana()] ?? 'Desconocido' ?></p>
          <p><strong>Horario:</strong> <?= htmlspecialchars($horario->getHoraInicio(), ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($horario->getHoraFin(), ENT_QUOTES, 'UTF-8') ?></p>
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
        // Día de la semana
        const diaSemanaSelect = form.querySelector('select[name="dia_semana"]');
        if (diaSemanaSelect) {
            diaSemanaSelect.addEventListener('change', function() {
                if (this.value) {
                    this.classList.remove('is-invalid');
                }
            });
        }

        // Hora inicio
        const horaInicioInput = form.querySelector('input[name="hora_inicio"]');
        if (horaInicioInput) {
            horaInicioInput.addEventListener('blur', function() {
                validarHora(this);
            });
            horaInicioInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }

        // Hora fin
        const horaFinInput = form.querySelector('input[name="hora_fin"]');
        if (horaFinInput) {
            horaFinInput.addEventListener('blur', function() {
                validarHora(this);
                if (horaInicioInput) {
                    validarRangoHorario(horaInicioInput, this);
                }
            });
            horaFinInput.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
        }
    };

    // Configurar validación para todos los formularios (excepto eliminar)
    document.querySelectorAll('form').forEach(form => {
        if (!form.querySelector('input[name="action"][value="eliminar"]') && 
            !form.querySelector('input[name="action"][value="toggle_activo"]')) {
            setupValidation(form);
            
            form.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Validar selects requeridos
                form.querySelectorAll('select[required]').forEach(select => {
                    if (!select.value) {
                        select.classList.add('is-invalid');
                        isValid = false;
                    }
                });
                
                // Validar campos de hora
                const horaInicio = form.querySelector('input[name="hora_inicio"]');
                const horaFin = form.querySelector('input[name="hora_fin"]');
                
                if (horaInicio && !validarHora(horaInicio)) {
                    isValid = false;
                }
                
                if (horaFin && !validarHora(horaFin)) {
                    isValid = false;
                }
                
                if (horaInicio && horaFin && !validarRangoHorario(horaInicio, horaFin)) {
                    isValid = false;
                }
                
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

    // Configurar eventos para el modal de creación
    const modalCrear = document.getElementById('modalCrear');
    if (modalCrear) {
        modalCrear.addEventListener('show.bs.modal', function(event) {
            const button = event.relatedTarget; // Botón que disparó el modal
            const dia = button.getAttribute('data-dia');
            
            if (dia) {
                const select = modalCrear.querySelector('#diaSemanaCrear');
                if (select) {
                    select.value = dia;
                }
            }
        });
    }
    
    // Configurar eventos para el formulario de horario semanal
    const weekForm = document.querySelector('.week-schedule-form');
    if (weekForm) {
        // Habilitar/deshabilitar inputs de hora según checkbox
        weekForm.querySelectorAll('.day-checkbox').forEach(checkbox => {
            const dia = checkbox.id.split('_')[1];
            const horaInicio = weekForm.querySelector(`#hora_inicio_${dia}`);
            const horaFin = weekForm.querySelector(`#hora_fin_${dia}`);
            
            checkbox.addEventListener('change', function() {
                horaInicio.disabled = !this.checked;
                horaFin.disabled = !this.checked;
                
                if (!this.checked) {
                    horaInicio.removeAttribute('required');
                    horaFin.removeAttribute('required');
                } else {
                    horaInicio.setAttribute('required', 'required');
                    horaFin.setAttribute('required', 'required');
                }
            });
        });
        
        // Validación personalizada para el formulario semanal
        weekForm.addEventListener('submit', function(e) {
            let hasAtLeastOneDay = false;
            let isValid = true;
            
            for (let dia = 1; dia <= 7; dia++) {
                const checkbox = this.querySelector(`#activo_${dia}`);
                const horaInicio = this.querySelector(`#hora_inicio_${dia}`);
                const horaFin = this.querySelector(`#hora_fin_${dia}`);
                
                if (checkbox.checked) {
                    hasAtLeastOneDay = true;
                    
                    // Validar horas
                    if (!validarHora(horaInicio)) {
                        isValid = false;
                    }
                    
                    if (!validarHora(horaFin)) {
                        isValid = false;
                    }
                    
                    if (horaInicio.value && horaFin.value && !validarRangoHorario(horaInicio, horaFin)) {
                        isValid = false;
                    }
                }
            }
            
            if (!hasAtLeastOneDay) {
                e.preventDefault();
                const alertDiv = document.createElement('div');
                alertDiv.className = 'alert alert-danger alert-dismissible fade show mt-3';
                alertDiv.setAttribute('role', 'alert');
                alertDiv.innerHTML = `
                    Error: Debe seleccionar al menos un día para registrar horarios.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Cerrar"></button>
                `;
                
                const existingAlert = weekForm.querySelector('.alert');
                if (existingAlert) {
                    existingAlert.replaceWith(alertDiv);
                } else {
                    weekForm.prepend(alertDiv);
                }
                
                return false;
            }
            
            if (!isValid) {
                e.preventDefault();
                return false;
            }
            
            return true;
        });
    }
});

// Funciones de validación
function validarHora(input) {
    const hora = input.value.trim();
    const feedback = input.nextElementSibling;
    
    if (!hora && input.required) {
        input.classList.add('is-invalid');
        if (feedback) feedback.textContent = 'La hora es requerida';
        return false;
    }
    
    if (hora && !/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(hora)) {
        input.classList.add('is-invalid');
        if (feedback) feedback.textContent = 'Formato de hora inválido (use HH:MM)';
        return false;
    }
    
    input.classList.remove('is-invalid');
    if (feedback) feedback.textContent = '';
    return true;
}

function validarRangoHorario(horaInicio, horaFin) {
    const horaInicioVal = horaInicio.value;
    const horaFinVal = horaFin.value;
    
    if (!horaInicioVal || !horaFinVal) return true;
    
    const horaInicioDt = new Date(`2000-01-01T${horaInicioVal}`);
    const horaFinDt = new Date(`2000-01-01T${horaFinVal}`);
    
    if (horaFinDt <= horaInicioDt) {
        horaFin.classList.add('is-invalid');
        if (horaFin.nextElementSibling) {
            horaFin.nextElementSibling.textContent = 'La hora de fin debe ser posterior a la hora de inicio';
        }
        return false;
    }
    
    horaFin.classList.remove('is-invalid');
    if (horaFin.nextElementSibling) {
        horaFin.nextElementSibling.textContent = '';
    }
    return true;
}
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
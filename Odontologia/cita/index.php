<?php
$page_title = "Citas";
session_start();

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Usuario.php';
require_once __DIR__ . '/../includes/Paciente.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$usuario_actual = (object) $_SESSION['usuario'];
$database = new Database();
$db = $database->getConnection();

function registrarLog($db, $usuario_id, $nivel, $mensaje, $archivo = null, $linea = null, $traza = null, $datos_adicionales = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Desconocida';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido';
    
    $query = "INSERT INTO errores_sistema (usuario_id, nivel, mensaje, archivo, linea, traza, datos_adicionales, ip, user_agent)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($query);
    $stmt->execute([
        $usuario_id,
        $nivel,
        $mensaje,
        $archivo,
        $linea,
        $traza,
        $datos_adicionales,
        $ip,
        $user_agent
    ]);
}

try {
    cancelarCitasPasadas($db);
} catch (Exception $e) {
    registrarLog($db, $usuario_actual->id, 'ERROR', 'Error al cancelar citas pasadas: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
}

try {
    $permisos_acciones = $db->query("SELECT * FROM auth_acciones WHERE rol_type = '{$usuario_actual->rol_type}'")->fetch(PDO::FETCH_ASSOC);
    $permisos_secciones = $db->query("SELECT * FROM auth_secciones WHERE rol_type = '{$usuario_actual->rol_type}'")->fetch(PDO::FETCH_ASSOC);
    
    if (!$permisos_secciones || !$permisos_secciones['auth_citas']) {
        registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de acceso no autorizado a citas', __FILE__, __LINE__);
        header("Location: /Odontologia/templates/unauthorized.php");
        exit;
    }
} catch (Exception $e) {
    registrarLog($db, $usuario_actual->id, 'CRITICAL', 'Error al obtener permisos: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
    die('Error crítico al obtener permisos. Por favor contacte al administrador.');
}

if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');
    
    try {
        if (isset($_GET['action'])) {
            switch ($_GET['action']) {
                case 'get_pacientes':
                    echo json_encode(getPacientes($db, $usuario_actual));
                    break;
                case 'get_doctores':
                    echo json_encode(getDoctores($db, $usuario_actual));
                    break;
                case 'get_citas':
                    $start = $_GET['start'] ?? date('Y-m-d');
                    $end = $_GET['end'] ?? date('Y-m-d', strtotime('+1 month'));
                    echo json_encode(getCitas($db, $start, $end, $usuario_actual));
                    break;
                case 'get_horarios_disponibles':
                    $fecha = $_GET['fecha'];
                    echo json_encode(getHorariosDisponibles($db, $fecha));
                    break;
                case 'get_usuarios_disponibles':
                    echo json_encode(getUsuariosDisponibles($db));
                    break;
            }
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (isset($data['action'])) {
                if (!hasPermission($usuario_actual->rol_type, $data['action'], $permisos_acciones)) {
                    registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de acción no autorizada: ' . $data['action'], __FILE__, __LINE__);
                    echo json_encode(['success' => false, 'message' => 'No tienes permisos para esta acción']);
                    exit;
                }
                
                switch ($data['action']) {
                    case 'create_cita':
                        echo json_encode(createCita($db, $data, $usuario_actual));
                        break;
                    case 'update_cita':
                        echo json_encode(updateCita($db, $data, $usuario_actual));
                        break;
                    case 'delete_cita':
                        echo json_encode(deleteCita($db, $data['id'], $usuario_actual));
                        break;
                    case 'toggle_cita_status':
                        echo json_encode(toggleCitaStatus($db, $data['id'], $usuario_actual));
                        break;
                    case 'asignar_paciente_usuario':
                        echo json_encode(asignarPacienteAUsuario($db, $data['paciente_id'], $data['usuario_id'], $usuario_actual));
                        break;
                    default:
                        registrarLog($db, $usuario_actual->id, 'WARNING', 'Acción no válida solicitada: ' . $data['action'], __FILE__, __LINE__);
                        echo json_encode(['success' => false, 'message' => 'Acción no válida']);
                }
            }
        }
    } catch (Exception $e) {
        registrarLog($db, $usuario_actual->id, 'ERROR', 'Error en solicitud AJAX: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit;
}

function cancelarCitasPasadas($db) {
    $hoy = date('Y-m-d');
    $query = "UPDATE citas SET estado = 'Cancelado', estado_atencion = 'Cancelado' 
              WHERE fecha < ? AND estado = 'Pendiente' AND Estado_Sistema = 'Activo'";
    $stmt = $db->prepare($query);
    $stmt->execute([$hoy]);
    
    if ($stmt->rowCount() > 0) {
        registrarLog($db, null, 'INFO', 'Citas pasadas canceladas automáticamente: ' . $stmt->rowCount() . ' citas afectadas');
    }
}

function hasPermission($rol, $action, $permisos_acciones) {
    if (!$permisos_acciones) return false;
    
    switch($action) {
        case 'create_cita': return $permisos_acciones['auth_crear'];
        case 'update_cita': return $permisos_acciones['auth_editar'];
        case 'delete_cita': return $permisos_acciones['auth_eliminar'];
        case 'toggle_cita_status': return $permisos_acciones['auth_eliminar'];
        case 'asignar_paciente_usuario': return $permisos_acciones['auth_editar'];
        default: return false;
    }
}

function getPacientes($db, $usuario_actual) {
    try {
        $query = "SELECT p.id, p.nombre, p.cedula, p.usuario_asignado_id 
                  FROM paciente p
                  WHERE p.usuario_asignado_id = ? AND p.Estado_Sistema = 'Activo' AND p.es_representante = 0";
        $stmt = $db->prepare($query);
        $stmt->execute([$usuario_actual->id]);
        $pacientes_asignados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($pacientes_asignados)) {
            return $pacientes_asignados;
        }
        
        if ($usuario_actual->rol_type === 'U') {
            return [];
        } else {
            $query = "SELECT p.id, p.nombre, p.cedula, p.usuario_asignado_id 
                      FROM paciente p
                      WHERE (p.usuario_asignado_id IS NULL OR p.usuario_asignado_id = ?) 
                      AND p.es_representante = 0 AND p.Estado_Sistema = 'Activo'";
            $stmt = $db->prepare($query);
            $stmt->execute([$usuario_actual->id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        registrarLog($db, $usuario_actual->id, 'ERROR', 'Error al obtener pacientes: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        return [];
    }
}

function getUsuariosDisponibles($db) {
    try {
        $query = "SELECT u.id, u.nombre, u.email 
                  FROM usuario u
                  WHERE u.Estado_Sistema = 'Activo' AND u.rol_type = 'U'
                  ORDER BY u.nombre";
        $stmt = $db->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        registrarLog($db, null, 'ERROR', 'Error al obtener usuarios disponibles: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        return [];
    }
}

function asignarPacienteAUsuario($db, $paciente_id, $usuario_id, $usuario_actual) {
    try {
        if ($usuario_actual->rol_type !== 'O' && $usuario_actual->rol_type !== 'A' && $usuario_actual->rol_type !== 'S') {
            throw new Exception('No tienes permisos para asignar pacientes');
        }

        $query = "SELECT usuario_asignado_id FROM paciente WHERE id = ? AND Estado_Sistema = 'Activo'";
        $stmt = $db->prepare($query);
        $stmt->execute([$paciente_id]);
        $paciente = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$paciente) {
            throw new Exception('Paciente no encontrado');
        }

        if ($paciente['usuario_asignado_id'] && $paciente['usuario_asignado_id'] != $usuario_id) {
            throw new Exception('Este paciente ya está asignado a otro usuario');
        }

        $query = "SELECT id FROM usuario WHERE id = ? AND rol_type = 'U' AND Estado_Sistema = 'Activo'";
        $stmt = $db->prepare($query);
        $stmt->execute([$usuario_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$usuario) {
            throw new Exception('Usuario no válido para asignación');
        }

        $query = "UPDATE paciente SET usuario_asignado_id = ?, usuario_asignado_nombre = (SELECT nombre FROM usuario WHERE id = ?) WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$usuario_id, $usuario_id, $paciente_id]);

        registrarLog($db, $usuario_actual->id, 'INFO', "Paciente $paciente_id asignado al usuario $usuario_id");

        return ['success' => true, 'message' => 'Paciente asignado correctamente'];
    } catch (Exception $e) {
        registrarLog($db, $usuario_actual->id, 'ERROR', 'Error al asignar paciente: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getDoctores($db, $usuario_actual) {
    try {
        if ($usuario_actual->rol_type === 'U') {
            return [];
        }
        
        if ($usuario_actual->rol_type === 'O') {
            $query = "SELECT id, nombre FROM usuario WHERE id = ? AND Estado_Sistema = 'Activo'";
            $stmt = $db->prepare($query);
            $stmt->execute([$usuario_actual->id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $query = "SELECT u.id, u.nombre 
                  FROM usuario u
                  JOIN rol r ON u.rol_type = r.type
                  WHERE u.is_active = 1 AND r.type = 'O' AND u.Estado_Sistema = 'Activo'";
        $stmt = $db->query($query);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        registrarLog($db, $usuario_actual->id, 'ERROR', 'Error al obtener doctores: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        return [];
    }
}

function getCitas($db, $start, $end, $usuario_actual) {
    try {
        $query = "SELECT c.id, c.fecha, c.hora, c.duracion, c.estado, c.nota, c.tratamiento,
                         c.estado_atencion, c.motivo, c.observaciones, c.recetas,
                         p.id as paciente_id, p.nombre as paciente_nombre, p.usuario_asignado_id,
                         u.id as usuario_id, u.nombre as doctor_nombre,
                         c.telefono_paciente, c.Estado_Sistema as estado_sistema
                  FROM citas c
                  LEFT JOIN paciente p ON c.paciente_id = p.id
                  JOIN usuario u ON c.usuario_id = u.id
                  WHERE c.fecha BETWEEN ? AND ? AND c.Estado_Sistema = 'Activo'";
        
        $params = [$start, $end];
        
        if ($usuario_actual->rol_type === 'U') {
            $query .= " AND (p.usuario_asignado_id = ? OR (c.paciente_id IS NULL AND c.usuario_id = ?))";
            $params[] = $usuario_actual->id;
            $params[] = $usuario_actual->id;
        } elseif ($usuario_actual->rol_type === 'O') {
            $query .= " AND (c.usuario_id = ? OR p.usuario_asignado_id = ?)";
            $params[] = $usuario_actual->id;
            $params[] = $usuario_actual->id;
        }
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        registrarLog($db, $usuario_actual->id, 'ERROR', 'Error al obtener citas: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        return [];
    }
}

function getHorariosDisponibles($db, $fecha) {
    try {
        $dia_semana = date('N', strtotime($fecha));
        
        $query = "SELECT u.id as doctor_id, u.nombre as doctor_nombre, 
                  h.hora_inicio, h.hora_fin, h.dia_semana
                  FROM horarios_doctor h
                  JOIN usuario u ON h.usuario_id = u.id
                  WHERE h.dia_semana = ? AND h.activo = 1 AND u.Estado_Sistema = 'Activo'
                  ORDER BY u.nombre";
        $stmt = $db->prepare($query);
        $stmt->execute([$dia_semana]);
        $horarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $query = "SELECT usuario_id, hora, duracion 
                  FROM citas 
                  WHERE fecha = ? AND Estado_Sistema = 'Activo'";
        $stmt = $db->prepare($query);
        $stmt->execute([$fecha]);
        $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($horarios as $horario) {
            $doctor_id = $horario['doctor_id'];
            $doctor_nombre = $horario['doctor_nombre'];
            $hora_inicio = $horario['hora_inicio'];
            $hora_fin = $horario['hora_fin'];
            
            $citas_doctor = array_filter($citas, function($cita) use ($doctor_id) {
                return $cita['usuario_id'] == $doctor_id;
            });
            
            $bloques_disponibles = calcularBloquesDisponibles($hora_inicio, $hora_fin, $citas_doctor);
            
            foreach ($bloques_disponibles as $bloque) {
                $result[] = [
                    'doctor_id' => $doctor_id,
                    'doctor_nombre' => $doctor_nombre,
                    'hora_inicio' => $bloque['inicio'],
                    'hora_fin' => $bloque['fin'],
                    'disponible' => $bloque['disponible']
                ];
            }
        }
        
        return $result;
    } catch (Exception $e) {
        registrarLog($db, null, 'ERROR', 'Error al obtener horarios: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        return [];
    }
}

function calcularBloquesDisponibles($hora_inicio, $hora_fin, $citas_doctor) {
    $intervalo = 15;
    $bloques = [];
    
    $inicio_min = convertirHoraAMinutos($hora_inicio);
    $fin_min = convertirHoraAMinutos($hora_fin);
    
    $disponibilidad = array_fill($inicio_min, $fin_min - $inicio_min, true);
    
    foreach ($citas_doctor as $cita) {
        $cita_inicio = convertirHoraAMinutos($cita['hora']);
        $cita_fin = $cita_inicio + $cita['duracion'];
        
        for ($i = $cita_inicio; $i < $cita_fin; $i++) {
            if (isset($disponibilidad[$i])) {
                $disponibilidad[$i] = false;
            }
        }
    }
    
    $bloque_actual = null;
    foreach ($disponibilidad as $minuto => $disponible) {
        $hora_actual = convertirMinutosAHora($minuto);
        
        if ($disponible) {
            if ($bloque_actual === null) {
                $bloque_actual = [
                    'inicio' => $hora_actual,
                    'fin' => $hora_actual,
                    'disponible' => true
                ];
            } else {
                $bloque_actual['fin'] = $hora_actual;
            }
        } else {
            if ($bloque_actual !== null) {
                $bloques[] = $bloque_actual;
                $bloque_actual = null;
            }
        }
    }
    
    if ($bloque_actual !== null) {
        $bloques[] = $bloque_actual;
    }
    
    return $bloques;
}

function convertirHoraAMinutos($hora) {
    list($h, $m) = explode(':', $hora);
    return $h * 60 + $m;
}

function convertirMinutosAHora($minutos) {
    $h = floor($minutos / 60);
    $m = $minutos % 60;
    return sprintf('%02d:%02d', $h, $m);
}

function createCita($db, $data, $usuario_actual) {
    try {
        $fechaCita = new DateTime($data['fecha']);
        $hoy = new DateTime();
        $hoy->setTime(0, 0, 0, 0);
        
        if ($fechaCita < $hoy) {
            registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de crear cita en fecha pasada: ' . $data['fecha']);
            throw new Exception('No se pueden agendar citas en fechas pasadas');
        }
        
        $hora = DateTime::createFromFormat('H:i', $data['hora']);
        $horaMaxima = DateTime::createFromFormat('H:i', '17:00');
        
        if ($hora > $horaMaxima) {
            registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de crear cita después de las 5 PM: ' . $data['hora']);
            throw new Exception('No se pueden agendar citas después de las 5 PM');
        }
        
        if ($fechaCita == $hoy) {
            $horaActual = new DateTime();
            $horaCita = DateTime::createFromFormat('H:i', $data['hora']);
            
            if ($horaCita < $horaActual) {
                registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de crear cita en hora pasada para hoy: ' . $data['hora']);
                throw new Exception('No se pueden agendar citas en horas pasadas para el día de hoy');
            }
        }
        
        if ($usuario_actual->rol_type === 'U') {
            $query = "SELECT id FROM paciente WHERE usuario_asignado_id = ? AND Estado_Sistema = 'Activo' LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->execute([$usuario_actual->id]);
            $paciente_asignado = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($paciente_asignado) {
                $data['paciente_id'] = $paciente_asignado['id'];
            } else if (isset($data['paciente_id']) && !empty($data['paciente_id'])) {
                throw new Exception('No tienes permisos para asignar pacientes');
            }
            
            $odontologos_disponibles = [];
            $query = "SELECT u.id, u.nombre 
                      FROM usuario u
                      JOIN rol r ON u.rol_type = r.type
                      WHERE u.is_active = 1 AND r.type = 'O' AND u.Estado_Sistema = 'Activo'";
            $odontologos = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($odontologos as $odontologo) {
                if (verificarDisponibilidad($db, $odontologo['id'], $data['fecha'], $data['hora'], $data['duracion'])) {
                    $odontologos_disponibles[] = $odontologo;
                }
            }
            
            if (empty($odontologos_disponibles)) {
                throw new Exception('No hay odontólogos disponibles para el horario seleccionado');
            }
            
            if (count($odontologos_disponibles) > 1) {
                return [
                    'success' => false,
                    'multiple_doctors' => true,
                    'doctors' => $odontologos_disponibles,
                    'message' => 'Hay varios odontólogos disponibles para este horario'
                ];
            }
            
            $data['usuario_id'] = $odontologos_disponibles[0]['id'];
        }
        
        if (in_array($usuario_actual->rol_type, ['O', 'S', 'A']) && isset($data['paciente_id']) && !empty($data['paciente_id'])) {
            $query = "SELECT usuario_asignado_id FROM paciente WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$data['paciente_id']]);
            $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($paciente && $paciente['usuario_asignado_id'] && $paciente['usuario_asignado_id'] != $usuario_actual->id) {
                throw new Exception('Este paciente ya está asignado a otro usuario');
            }
            
            $query = "UPDATE paciente SET usuario_asignado_id = ?, usuario_asignado_nombre = (SELECT nombre FROM usuario WHERE id = ?) WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$usuario_actual->id, $usuario_actual->id, $data['paciente_id']]);
        }
        
        if (!verificarDisponibilidad($db, $data['usuario_id'], $data['fecha'], $data['hora'], $data['duracion'])) {
            registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de crear cita sin disponibilidad: ' . json_encode($data));
            throw new Exception('El odontólogo ya tiene una cita programada en ese horario');
        }
        
        if ($usuario_actual->rol_type === 'O') {
            $data['usuario_id'] = $usuario_actual->id;
        }
        
        $query = "INSERT INTO citas (
                    paciente_id, 
                    telefono_paciente, 
                    usuario_id, 
                    tratamiento, 
                    fecha, 
                    hora, 
                    duracion, 
                    estado, 
                    nota, 
                    estado_atencion, 
                    motivo, 
                    observaciones, 
                    recetas, 
                    Estado_Sistema
                  ) VALUES (
                    :paciente_id, 
                    :telefono, 
                    :usuario_id, 
                    :tratamiento, 
                    :fecha, 
                    :hora, 
                    :duracion, 
                    :estado, 
                    :nota, 
                    'Por atender', 
                    NULL, 
                    NULL, 
                    NULL, 
                    'Activo'
                  )";
        
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':paciente_id' => $data['paciente_id'] ?? null,
            ':telefono' => $data['telefono'] ?? null,
            ':usuario_id' => $data['usuario_id'],
            ':tratamiento' => $data['tratamiento'] ?? null,
            ':fecha' => $data['fecha'],
            ':hora' => $data['hora'],
            ':duracion' => $data['duracion'],
            ':estado' => $data['estado'] ?? 'Pendiente',
            ':nota' => $data['nota'] ?? null
        ]);
        
        $citaId = $db->lastInsertId();
        
        $query = "SELECT c.id, c.fecha, c.hora, c.duracion, c.estado, c.nota, c.tratamiento,
                         c.estado_atencion, c.motivo, c.observaciones, c.recetas,
                         p.id as paciente_id, p.nombre as paciente_nombre, p.usuario_asignado_id,
                         u.id as usuario_id, u.nombre as doctor_nombre,
                         c.telefono_paciente, c.Estado_Sistema as estado_sistema
                  FROM citas c
                  LEFT JOIN paciente p ON c.paciente_id = p.id
                  JOIN usuario u ON c.usuario_id = u.id
                  WHERE c.id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$citaId]);
        $cita = $stmt->fetch(PDO::FETCH_ASSOC);
        
        registrarLog($db, $usuario_actual->id, 'INFO', 'Cita creada: ID ' . $citaId . ($cita['paciente_nombre'] ? ' para paciente ' . $cita['paciente_nombre'] : ' sin paciente asignado'));
        
        return ['success' => true, 'message' => 'Cita creada con éxito', 'cita' => $cita];
    } catch (Exception $e) {
        registrarLog($db, $usuario_actual->id, 'ERROR', 'Error al crear cita: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString(), json_encode($data));
        throw $e;
    }
}

function updateCita($db, $data, $usuario_actual) {
    try {
        $fechaCita = new DateTime($data['fecha']);
        $hoy = new DateTime();
        $hoy->setTime(0, 0, 0, 0);
        
        if ($fechaCita < $hoy) {
            registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de actualizar cita a fecha pasada: ' . $data['fecha']);
            throw new Exception('No se pueden mover citas a fechas pasadas');
        }
        
        if (isset($data['hora'])) {
            $hora = DateTime::createFromFormat('H:i', $data['hora']);
            $horaMaxima = DateTime::createFromFormat('H:i', '17:00');
            
            if ($hora > $horaMaxima) {
                registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de actualizar cita después de las 5 PM: ' . $data['hora']);
                throw new Exception('No se pueden agendar citas después de las 5 PM');
            }
            
            if ($fechaCita == $hoy) {
                $horaActual = new DateTime();
                $horaCita = DateTime::createFromFormat('H:i', $data['hora']);
                
                if ($horaCita < $horaActual) {
                    registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de actualizar cita a hora pasada para hoy: ' . $data['hora']);
                    throw new Exception('No se pueden mover citas a horas pasadas para el día de hoy');
                }
            }
        }
        
        if (!verificarDisponibilidad($db, $data['usuario_id'], $data['fecha'], $data['hora'], $data['duracion'], $data['id'])) {
            registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de actualizar cita sin disponibilidad: ' . json_encode($data));
            throw new Exception('El odontólogo ya tiene una cita programada en ese horario');
        }
        
        if ($usuario_actual->rol_type === 'O') {
            $query = "SELECT usuario_id FROM citas WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$data['id']]);
            $cita = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cita || $cita['usuario_id'] != $usuario_actual->id) {
                registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de modificar cita no propia: ' . $data['id']);
                throw new Exception('No tienes permiso para modificar esta cita');
            }
            
            $data['usuario_id'] = $usuario_actual->id;
        }
        
        if (isset($data['paciente_id']) && $usuario_actual->rol_type === 'O') {
            $query = "SELECT usuario_asignado_id FROM paciente WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$data['paciente_id']]);
            $paciente = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($paciente && $paciente['usuario_asignado_id'] && $paciente['usuario_asignado_id'] != $usuario_actual->id) {
                throw new Exception('Este paciente ya está asignado a otro usuario');
            }
        }
        
        $query = "UPDATE citas SET 
                    paciente_id = ?, 
                    usuario_id = ?, 
                    tratamiento = ?, 
                    fecha = ?, 
                    hora = ?, 
                    duracion = ?, 
                    estado = ?, 
                    nota = ?,
                    estado_atencion = ?,
                    motivo = ?,
                    observaciones = ?,
                    recetas = ?
                  WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([
            $data['paciente_id'] ?? null,
            $data['usuario_id'], 
            $data['tratamiento'] ?: null, 
            $data['fecha'], 
            $data['hora'], 
            $data['duracion'], 
            $data['estado'], 
            $data['nota'],
            $data['estado_atencion'] ?? 'Por atender',
            $data['motivo'] ?? null,
            $data['observaciones'] ?? null,
            $data['recetas'] ?? null,
            $data['id']
        ]);
        
        registrarLog($db, $usuario_actual->id, 'INFO', 'Cita actualizada: ID ' . $data['id']);
        
        return ['success' => true, 'message' => 'Cita actualizada con éxito'];
    } catch (Exception $e) {
        registrarLog($db, $usuario_actual->id, 'ERROR', 'Error al actualizar cita: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString(), json_encode($data));
        throw $e;
    }
}

function deleteCita($db, $id, $usuario_actual) {
    try {
        if ($usuario_actual->rol_type === 'O') {
            $query = "SELECT usuario_id FROM citas WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$id]);
            $cita = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$cita || $cita['usuario_id'] != $usuario_actual->id) {
                registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de eliminar cita no propia: ' . $id);
                throw new Exception('No tienes permiso para eliminar esta cita');
            }
        }
        
        $query = "UPDATE citas SET Estado_Sistema = 'Inactivo' WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$id]);
        
        $query = "INSERT INTO auditoria_eliminaciones (tabla_afectada, id_registro_afectado, usuario_eliminador_id, nombre_usuario_eliminador, datos_originales)
                  SELECT 'citas', ?, ?, ?, JSON_OBJECT('id', id, 'paciente_id', paciente_id, 'usuario_id', usuario_id, 'fecha', fecha, 'hora', hora, 'estado', estado, 'estado_atencion', estado_atencion, 'motivo', motivo, 'observaciones', observaciones, 'recetas', recetas)
                  FROM citas WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$id, $usuario_actual->id, $usuario_actual->nombre, $id]);
        
        registrarLog($db, $usuario_actual->id, 'INFO', 'Cita marcada como inactiva: ID ' . $id);
        
        return ['success' => true, 'message' => 'Cita marcada como inactiva con éxito'];
    } catch (Exception $e) {
        registrarLog($db, $usuario_actual->id, 'ERROR', 'Error al eliminar cita: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        throw $e;
    }
}

function toggleCitaStatus($db, $id, $usuario_actual) {
    try {
        if ($usuario_actual->rol_type !== 'A' && $usuario_actual->rol_type !== 'S') {
            registrarLog($db, $usuario_actual->id, 'WARNING', 'Intento de cambiar estado de cita sin permisos: ' . $id);
            throw new Exception('No tienes permiso para cambiar el estado de esta cita');
        }
        
        $query = "SELECT Estado_Sistema FROM citas WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            throw new Exception('Cita no encontrada');
        }
        
        $nuevoEstado = $result['Estado_Sistema'] === 'Activo' ? 'Inactivo' : 'Activo';
        
        $query = "UPDATE citas SET Estado_Sistema = ? WHERE id = ?";
        $stmt = $db->prepare($query);
        $stmt->execute([$nuevoEstado, $id]);
        
        if ($nuevoEstado === 'Inactivo') {
            $query = "INSERT INTO auditoria_eliminaciones (tabla_afectada, id_registro_afectado, usuario_eliminador_id, nombre_usuario_eliminador, datos_originales)
                      SELECT 'citas', ?, ?, ?, JSON_OBJECT('id', id, 'paciente_id', paciente_id, 'usuario_id', usuario_id, 'fecha', fecha, 'hora', hora, 'estado', estado, 'estado_atencion', estado_atencion, 'motivo', motivo, 'observaciones', observaciones, 'recetas', recetas)
                      FROM citas WHERE id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$id, $usuario_actual->id, $usuario_actual->nombre, $id]);
        }
        
        registrarLog($db, $usuario_actual->id, 'INFO', 'Estado de cita cambiado: ID ' . $id . ' a ' . $nuevoEstado);
        
        return ['success' => true, 'message' => 'Estado de cita actualizado', 'nuevoEstado' => $nuevoEstado];
    } catch (Exception $e) {
        registrarLog($db, $usuario_actual->id, 'ERROR', 'Error al cambiar estado de cita: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        throw $e;
    }
}

function verificarHorarioLaboral($db, $odontologo_id, $fecha, $hora, $duracion) {
    $dia_semana = date('N', strtotime($fecha));
    $horaFin = date('H:i:s', strtotime("$hora + $duracion minutes"));
    
    $query = "SELECT COUNT(*) as count 
              FROM horarios_doctor 
              WHERE usuario_id = ? AND dia_semana = ? AND 
                    hora_inicio <= ? AND hora_fin >= ? AND activo = 1";
    $stmt = $db->prepare($query);
    $stmt->execute([$odontologo_id, $dia_semana, $hora, $horaFin]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'] > 0;
}

function verificarDisponibilidad($db, $usuario_id, $fecha, $hora, $duracion, $excluir_id = null) {
    try {
        if (!verificarHorarioLaboral($db, $usuario_id, $fecha, $hora, $duracion)) {
            return false;
        }
        
        $horaFin = date('H:i:s', strtotime("$hora + $duracion minutes"));
        
        $query = "SELECT COUNT(*) as count FROM citas 
                  WHERE usuario_id = ? AND fecha = ? AND Estado_Sistema = 'Activo'
                  AND (
                      (hora BETWEEN ? AND ?) 
                      OR (? BETWEEN hora AND ADDTIME(hora, SEC_TO_TIME(duracion * 60)))
                  )";
        
        if ($excluir_id) {
            $query .= " AND id != ?";
        }
        
        $stmt = $db->prepare($query);
        $params = [$usuario_id, $fecha, $hora, $horaFin, $hora];
        
        if ($excluir_id) {
            $params[] = $excluir_id;
        }
        
        $stmt->execute($params);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['count'] == 0;
    } catch (Exception $e) {
        registrarLog($db, null, 'ERROR', 'Error al verificar disponibilidad: ' . $e->getMessage(), __FILE__, __LINE__, $e->getTraceAsString());
        return false;
    }
}

require_once __DIR__ . '/../templates/navbar.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Calendario de Citas Odontológicas</title>
  <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
  <style>
    :root {
      --primary-color: #4f6df5;
      --success-color: #28a745;
      --warning-color: #ffc107;
      --danger-color: #dc3545;
      --info-color: #17a2b8;
      --dark-color: #343a40;
      --light-color: #f8f9fa;
      --available-color: #d4edda;
      --busy-color: #f8d7da;
      --doctor-schedule-color: #e2e3e5;
    }
    
    body {
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      background-color: #f5f7fa;
      color: #333;
    }
    .calendar-container {
      max-width: 1200px;
      margin: 30px auto;
      padding: 20px;
      background: white;
      border-radius: 12px;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
    }
    
    h2 {
      color: var(--primary-color);
      font-weight: 600;
      margin-bottom: 30px;
      text-align: center;
    }
    
    #calendar {
      background: white;
      border-radius: 10px;
      padding: 20px;
      box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
    }
    
    .fc-toolbar-title {
      font-weight: 600;
      color: var(--dark-color);
    }
    
    .fc-button {
      background-color: var(--primary-color) !important;
      border: none !important;
      border-radius: 6px !important;
      transition: all 0.3s;
    }
    
    .fc-button:hover {
      opacity: 0.9;
      transform: translateY(-1px);
    }
    
    .fc-event {
      border: none !important;
      border-radius: 6px;
      padding: 5px 8px;
      font-size: 0.85em;
      box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
      transition: all 0.2s;
    }
    
    .fc-event:hover {
      box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
      transform: translateY(-1px);
    }
    
    .badge-pill {
      padding: 5px 10px;
      font-size: 0.75em;
      font-weight: 600;
      border-radius: 50px;
      margin-right: 5px;
    }
    
    .badge-primary {
      background-color: var(--primary-color);
    }
    
    .badge-success {
      background-color: var(--success-color);
    }
    
    .badge-warning {
      background-color: var(--warning-color);
      color: #000;
    }
    
    .badge-danger {
      background-color: var(--danger-color);
    }
    
    .badge-info {
      background-color: var(--info-color);
    }
    
    .badge-dark {
      background-color: var(--dark-color);
    }
    
    .badge-light {
      background-color: var(--light-color);
      color: #333;
    }
    
    .modal-content {
      border: none;
      border-radius: 12px;
      overflow: hidden;
    }
    
    .modal-header {
      background-color: var(--primary-color);
      color: white;
    }
    
    .modal-title {
      font-weight: 600;
    }
    
    .nav-tabs .nav-link {
      color: #555;
      font-weight: 500;
      border: none;
      padding: 12px 20px;
    }
    
    .nav-tabs .nav-link.active {
      color: var(--primary-color);
      border-bottom: 3px solid var(--primary-color);
      background: transparent;
    }
    
    .form-control {
      border-radius: 8px;
      padding: 10px 15px;
      border: 1px solid #ddd;
    }
    
    .form-control:focus {
      border-color: var(--primary-color);
      box-shadow: 0 0 0 0.2rem rgba(79, 109, 245, 0.25);
    }
    
    .btn {
      border-radius: 8px;
      padding: 8px 20px;
      font-weight: 500;
      transition: all 0.3s;
    }
    
    .btn-primary {
      background-color: var(--primary-color);
      border-color: var(--primary-color);
    }
    
    .btn-primary:hover {
      background-color: #3a5bef;
      transform: translateY(-1px);
    }
    
    .loading-overlay {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      display: flex;
      justify-content: center;
      align-items: center;
      z-index: 9999;
    }
    
    .spinner-border {
      width: 3rem;
      height: 3rem;
      color: white;
    }
    
    .no-paciente {
      border-left: 4px solid #ff6b6b;
      background-color: #fff5f5;
    }
    
    .fc-daygrid-day.fc-day-available {
      background-color: var(--available-color);
    }
    
    .fc-daygrid-day.fc-day-busy {
      background-color: var(--busy-color);
    }
    
    .fc-daygrid-day.fc-day-doctor-schedule {
      background-color: var(--doctor-schedule-color);
    }
    
    .fc-daygrid-day.fc-day-today {
      background-color: rgba(255, 220, 40, 0.15);
    }
    
    .fc-daygrid-day-number {
      font-weight: bold;
      color: var(--dark-color);
    }
    
    .fc-timegrid-slots table {
      background-color: #f8f9fa;
    }
    
    .fc-timegrid-slot {
      height: 40px;
    }
    
    .fc-timegrid-slot.fc-timegrid-slot-label {
      font-weight: bold;
      background-color: #e9ecef;
    }
    
    .fc-timegrid-event {
      border-radius: 6px;
      padding: 2px 5px;
      font-size: 0.85em;
      box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    
    .fc-timegrid-event:hover {
      box-shadow: 0 2px 5px rgba(0,0,0,0.2);
      transform: translateY(-1px);
    }
    
    .doctor-schedule {
      border-left: 4px solid var(--primary-color);
      background-color: rgba(79, 109, 245, 0.05);
      margin-bottom: 10px;
      padding: 10px;
      border-radius: 6px;
    }
    
    .doctor-schedule h5 {
      color: var(--primary-color);
      margin-bottom: 10px;
    }
    
    .schedule-block {
      display: inline-block;
      padding: 5px 10px;
      margin-right: 5px;
      margin-bottom: 5px;
      border-radius: 4px;
      font-size: 0.85em;
    }
    
    .schedule-available {
      background-color: rgba(40, 167, 69, 0.1);
      border: 1px solid rgba(40, 167, 69, 0.3);
      color: #28a745;
    }
    
    .schedule-busy {
      background-color: rgba(220, 53, 69, 0.1);
      border: 1px solid rgba(220, 53, 69, 0.3);
      color: #dc3545;
      text-decoration: line-through;
    }
    
    .table-horarios th {
      background-color: var(--primary-color);
      color: white;
    }
    
    .table-horarios td {
      vertical-align: middle;
    }
    
    .list-group-item {
      cursor: pointer;
      transition: all 0.2s;
    }
    
    .list-group-item:hover {
      background-color: #f8f9fa;
      transform: translateX(5px);
    }
    
    .asignar-usuario-container {
      margin-top: 15px;
      padding: 15px;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      background-color: #f8f9fa;
    }
    
    .asignar-usuario-container h6 {
      color: var(--primary-color);
      margin-bottom: 10px;
    }
    
    @media (max-width: 768px) {
      .container {
        padding: 10px;
      }
      
      #calendar {
        padding: 10px;
      }
      
      .modal-dialog {
        margin: 0.5rem auto;
      }
    }
  </style>
</head>
<body>
  <div class="calendar-container">
    <h2 class="text-center mb-4">Calendario de Citas Odontológicas</h2>
    <div id="calendar"></div>
  </div>

  <div class="modal fade" id="simpleCitaModal" tabindex="-1" aria-labelledby="simpleCitaModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="simpleCitaModalLabel">Solicitar Cita</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body simple-cita-form">
          <form id="simpleCitaForm">
            <div class="form-group">
              <label for="fecha_simple">Fecha seleccionada</label>
              <input type="date" class="form-control" id="fecha_simple" readonly>
            </div>
            <div class="form-group">
              <label for="telefono">Teléfono * (10-11 dígitos)</label>
              <input type="tel" class="form-control" id="telefono" pattern="[0-9]{10,11}" maxlength="11" required>
            </div>
            <div class="form-group">
              <label for="hora_simple">Hora deseada *</label>
              <input type="time" class="form-control" id="hora_simple" min="08:00" max="17:00" required>
            </div>
            <div class="form-group">
              <label for="nota_simple">Motivo de la consulta</label>
              <textarea class="form-control" id="nota_simple" rows="3"></textarea>
            </div>
            <div class="alert alert-info">
              <i class="fas fa-info-circle"></i> Esta cita se creará sin paciente asignado. 
              El odontólogo podrá asignarle un paciente posteriormente.
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-primary" id="guardarSimpleCita">Solicitar Cita</button>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="citaModal" tabindex="-1" aria-labelledby="citaModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white">
          <h5 class="modal-title" id="citaModalLabel">Nueva Cita Odontológica</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?php if ($permisos_acciones['auth_ver']): ?>
          <form id="citaForm">
            <ul class="nav nav-tabs" id="citaTabs" role="tablist">
              <li class="nav-item" role="presentation">
                <button class="nav-link active" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab">Información General</button>
              </li>
              <li class="nav-item" role="presentation">
                <button class="nav-link" id="atencion-tab" data-bs-toggle="tab" data-bs-target="#atencion" type="button" role="tab">Atención</button>
              </li>
            </ul>
            <div class="tab-content" id="citaTabContent">
              <div class="tab-pane fade show active" id="general" role="tabpanel">
                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label for="paciente">Paciente</label>
                      <select class="form-control" id="paciente">
                        <option value="">Seleccione un paciente</option>
                      </select>
                    </div>
                    <div class="form-group">
                      <label for="tratamiento">Tratamiento</label>
                      <input type="text" class="form-control" id="tratamiento" placeholder="Descripción del tratamiento">
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group doctor-selection" style="<?= $usuario_actual->rol_type === 'U' ? 'display:none;' : '' ?>">
                      <label for="doctor">Odontólogo *</label>
                      <select class="form-control" id="doctor" required <?= $usuario_actual->rol_type === 'O' ? 'disabled' : '' ?>>
                        <option value="">Seleccione un odontólogo</option>
                      </select>
                      <?php if ($usuario_actual->rol_type === 'O'): ?>
                        <input type="hidden" id="doctor_hidden" value="<?= $usuario_actual->id ?>">
                      <?php endif; ?>
                    </div>
                    <div class="form-group">
                      <label for="fecha">Fecha *</label>
                      <input type="date" class="form-control" id="fecha" required>
                    </div>
                  </div>
                </div>
                <div class="row">
                  <div class="col-md-6">
                    <div class="form-group">
                      <label for="hora">Hora * (Horario hasta las 17:00)</label>
                      <input type="time" class="form-control" id="hora" min="08:00" max="17:00" required>
                    </div>
                  </div>
                  <div class="col-md-6">
                    <div class="form-group">
                      <label for="duracion">Duración (minutos) *</label>
                      <input type="number" class="form-control" id="duracion" value="30" min="15" step="15" required>
                    </div>
                  </div>
                </div>
                <div class="form-group">
                  <label for="estado">Estado</label>
                  <select class="form-control" id="estado">
                    <option value="Pendiente">Pendiente</option>
                    <option value="Confirmado">Confirmado</option>
                    <option value="Cancelado">Cancelado</option>
                    <option value="Completado">Completado</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="nota">Notas</label>
                  <textarea class="form-control" id="nota" rows="3"></textarea>
                </div>
                <div id="estadoSistemaContainer" class="form-group" style="display: none;">
                  <label>Estado en el sistema:</label>
                  <span id="estadoSistema" class="badge badge-pill badge-dark">Inactivo</span>
                </div>
                
                <div id="asignarPacienteUsuarioContainer" class="asignar-usuario-container" style="display: none;">
                  <h6>Asignar Paciente a Usuario</h6>
                  <div class="row">
                    <div class="col-md-6">
                      <div class="form-group">
                        <label for="selectUsuarioAsignar">Usuario</label>
                        <select class="form-control" id="selectUsuarioAsignar">
                          <option value="">Seleccione un usuario</option>
                        </select>
                      </div>
                    </div>
                    <div class="col-md-6">
                      <div class="form-group" style="margin-top: 32px;">
                        <button type="button" class="btn btn-info" id="btnAsignarPacienteUsuario">
                          <i class="fas fa-user-plus"></i> Asignar Paciente
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="tab-pane fade" id="atencion" role="tabpanel">
                <div class="form-group">
                  <label for="estado_atencion">Estado de atención</label>
                  <select class="form-control" id="estado_atencion">
                    <option value="Por atender">Por atender</option>
                    <option value="Atendido">Atendido</option>
                    <option value="Cancelado">Cancelado</option>
                    <option value="Postergado">Postergado</option>
                  </select>
                </div>
                <div class="form-group">
                  <label for="motivo">Motivo</label>
                  <textarea class="form-control" id="motivo" rows="2"></textarea>
                </div>
                <div class="form-group">
                  <label for="observaciones">Observaciones</label>
                  <textarea class="form-control" id="observaciones" rows="3"></textarea>
                </div>
                <div class="form-group">
                  <label for="recetas">Recetas</label>
                  <textarea class="form-control" id="recetas" rows="3"></textarea>
                </div>
              </div>
            </div>
            <input type="hidden" id="cita_id">
          </form>
          <?php else: ?>
          <p class="text-danger">No tienes permisos para ver esta información.</p>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-info me-auto" id="asignarPacienteBtn" style="display:none;">
            <i class="fas fa-user-plus"></i> Asignar Paciente
          </button>
          <?php if ($permisos_acciones['auth_eliminar']): ?>
          <button type="button" class="btn btn-danger" id="toggleCitaStatus" style="display:none;">Desactivar</button>
          <?php endif; ?>
          <?php if ($permisos_acciones['auth_editar'] || $permisos_acciones['auth_crear']): ?>
          <button type="button" class="btn btn-primary" id="guardarCita">Guardar Cita</button>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="modal fade" id="asignarPacienteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-info text-white">
          <h5 class="modal-title">Asignar Paciente a Cita</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="form-group">
            <label>Seleccione un paciente:</label>
            <select class="form-control" id="selectPacienteAsignar">
              <option value="">Seleccione...</option>
            </select>
          </div>
          <div class="alert alert-info mt-3">
            <i class="fas fa-info-circle"></i> Esta cita actualmente no tiene paciente asignado.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="button" class="btn btn-info" id="confirmarAsignacion">
            <i class="fas fa-save"></i> Asignar Paciente
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="loading-overlay" style="display: none;">
    <div class="spinner-border text-light spinner" role="status">
      <span class="visually-hidden">Cargando...</span>
    </div>
  </div>

  <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
  <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/es.min.js'></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

  <script>
    window.hasAuthVerPermission = <?= json_encode($permisos_acciones['auth_ver'] ?? false) ?>;
    window.currentUserRol = '<?= $usuario_actual->rol_type ?>';
    window.currentUserId = '<?= $usuario_actual->id ?>';

    document.addEventListener('DOMContentLoaded', function () {
      const calendarEl = document.getElementById('calendar');
      const citaModal = new bootstrap.Modal(document.getElementById('citaModal'));
      const simpleCitaModal = new bootstrap.Modal(document.getElementById('simpleCitaModal'));
      const asignarPacienteModal = new bootstrap.Modal(document.getElementById('asignarPacienteModal'));
      const loadingOverlay = document.querySelector('.loading-overlay');
      let selectedDate = null;
      let currentEvent = null;
      let currentPacienteId = null;

      function showLoading() {
        loadingOverlay.style.display = 'flex';
      }
      
      function hideLoading() {
        loadingOverlay.style.display = 'none';
      }

      const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: 'dayGridMonth',
        locale: 'es',
        headerToolbar: {
          left: 'prev,next today',
          center: 'title',
          right: 'dayGridMonth,timeGridWeek,timeGridDay'
        },
        selectable: true,
        editable: window.currentUserRol === 'A' || window.currentUserRol === 'S',
        eventDurationEditable: window.currentUserRol === 'A' || window.currentUserRol === 'S',
        dateClick: function(info) {
          if (window.currentUserRol === 'U') {
            selectedDate = info.dateStr;
            $('#fecha_simple').val(selectedDate);
            $('#hora_simple').val('09:00');
            simpleCitaModal.show();
          } else if (window.currentUserRol === 'A' || window.currentUserRol === 'S' || window.currentUserRol === 'O') {
            resetForm();
            selectedDate = info.dateStr;
            $('#fecha').val(selectedDate);
            $('#hora').val('09:00');
            $('#estado').val('Pendiente');
            $('#estado_atencion').val('Por atender');
            
            if (window.currentUserRol === 'O') {
              $('#doctor').val(window.currentUserId);
            }
            
            $('#atencion-tab').addClass('d-none');
            $('#general-tab').tab('show');
            
            citaModal.show();
          } else {
            alert('No tienes permiso para crear citas');
          }
        },
        eventClick: function(info) {
          if (window.currentUserRol === 'U') {
            const extendedProps = info.event.extendedProps;
            alert(`Cita:\nFecha: ${info.event.start.toLocaleDateString()}\nHora: ${info.event.start.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}\nEstado: ${extendedProps.estado}\nOdontólogo: Dr. ${extendedProps.doctor_nombre}\nTeléfono: ${extendedProps.telefono_paciente}\nMotivo: ${extendedProps.nota || 'No especificado'}`);
            return;
          }

          if (window.currentUserRol !== 'A' && window.currentUserRol !== 'S' && 
              (window.currentUserRol !== 'O' || info.event.extendedProps.usuario_id != window.currentUserId)) {
            alert('No tienes permiso para editar esta cita');
            return;
          }
          
          currentEvent = info.event;
          loadEventData(info.event);
          
          if (window.currentUserRol === 'O' && info.event.extendedProps.sin_paciente) {
            $('#asignarPacienteBtn').show();
          } else {
            $('#asignarPacienteBtn').hide();
          }
          
          $('#atencion-tab').removeClass('d-none');
          
          citaModal.show();
        },
        eventDrop: function(info) {
          if (window.currentUserRol !== 'A' && window.currentUserRol !== 'S') {
            calendar.refetchEvents();
            alert('No tienes permiso para mover citas');
            return;
          }
          
          showLoading();
          
          updateEventInDatabase(info.event)
              .catch(error => {
                  console.error('Error al mover cita:', error);
                  calendar.refetchEvents();
                  alert('Error al mover la cita: ' + (error.message || 'Por favor intente nuevamente'));
              })
              .finally(() => hideLoading());
        },
        eventResize: function(info) {
          if (window.currentUserRol !== 'A' && window.currentUserRol !== 'S') {
            calendar.refetchEvents();
            alert('No tienes permiso para cambiar la duración de citas');
            return;
          }
          
          showLoading();
          
          updateEventInDatabase(info.event)
              .catch(error => {
                  console.error('Error al redimensionar cita:', error);
                  calendar.refetchEvents();
                  alert('Error al cambiar la duración de la cita: ' + (error.message || 'Por favor intente nuevamente'));
              })
              .finally(() => hideLoading());
        },
        events: function(fetchInfo, successCallback, failureCallback) {
          showLoading();
          $.ajax({
            url: 'index.php?action=get_citas',
            method: 'GET',
            data: {
              start: fetchInfo.start.toISOString().split('T')[0],
              end: fetchInfo.end.toISOString().split('T')[0]
            },
            dataType: 'json',
            success: function(citas) {
              const events = citas.map(cita => createCalendarEvent(cita));
              successCallback(events);
              hideLoading();
            },
            error: function() {
              hideLoading();
              failureCallback(new Error('Error al cargar citas'));
            }
          });
        },
        dayCellClassNames: function(arg) {
          const classes = [];
          const hoy = new Date();
          hoy.setHours(0, 0, 0, 0);
          
          if (arg.date.toISOString().split('T')[0] === hoy.toISOString().split('T')[0]) {
            classes.push('fc-day-today');
          }
          
          if (window.currentUserRol === 'U' && arg.date >= hoy) {
            const fechaStr = arg.date.toISOString().split('T')[0];
            const hasAvailability = checkDayAvailability(fechaStr);
            if (hasAvailability) {
              classes.push('fc-day-available');
            } else {
              classes.push('fc-day-busy');
            }
          }
          
          return classes;
        },
        eventContent: function(arg) {
          if (!window.hasAuthVerPermission) {
              return { html: '<div class="text-muted small">No tienes permisos para ver este evento</div>' };
          }
          
          const extendedProps = arg.event.extendedProps;
          const sinPaciente = extendedProps.sin_paciente;
          const estado = extendedProps.estado || 'Pendiente';
          const estadoClass = estado === 'Confirmado' ? 'badge-success' : 
                            estado === 'Cancelado' ? 'badge-danger' : 
                            estado === 'Completado' ? 'badge-info' : 'badge-warning';
          const doctorNombre = extendedProps.doctor_nombre || 'Sin doctor asignado';
          const estadoAtencion = extendedProps.estado_atencion || 'Por atender';
          const estadoAtencionClass = estadoAtencion === 'Atendido' ? 'badge-success' : 
                                     estadoAtencion === 'Cancelado' ? 'badge-danger' : 
                                     estadoAtencion === 'Postergado' ? 'badge-warning' : 'badge-info';
          const estadoSistema = extendedProps.estado_sistema || 'Activo';

          let content = `
            <div class="d-flex flex-column p-1">
              <div class="d-flex justify-content-between align-items-center">
                <strong>${doctorNombre}</strong>
                <span class="badge badge-pill ${estadoClass}">${estado}</span>
              </div>
              ${sinPaciente ? 
                '<span class="badge badge-pill badge-danger mt-1">🟠 Sin paciente</span>' : 
                '<span class="badge badge-pill badge-success mt-1">✅ paciente</span>'}
          `;
          
          if (window.currentUserRol !== 'U') {
            content += `
              <small class="mt-1">${extendedProps.tratamiento || 'Consulta general'}</small>
              <div class="d-flex mt-1">
                <span class="badge badge-pill badge-light">${arg.event.start.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</span>
                <span class="badge badge-pill ${estadoAtencionClass} ml-1">${estadoAtencion}</span>
              </div>
              ${estadoSistema === 'Inactivo' ? '<span class="badge badge-pill badge-dark mt-1">Inactivo</span>' : ''}
            `;
          }
          
          content += `</div>`;
          
          return { html: content };
        },
        loading: function(isLoading) {
          if (isLoading) {
            showLoading();
          } else {
            hideLoading();
          }
        }
      });

      calendar.render();

      loadInitialData();

      $('#guardarSimpleCita').click(function() {
        if (!validateSimpleForm()) return;

        const citaData = {
          action: 'create_cita',
          fecha: $('#fecha_simple').val(),
          hora: $('#hora_simple').val(),
          duracion: 30,
          telefono: $('#telefono').val(),
          nota: $('#nota_simple').val(),
          estado: 'Pendiente'
        };

        saveSimpleCitaToDatabase(citaData);
      });

      $('#guardarCita').click(async function() {
        if (!validateForm()) return;

        const citaData = {
          action: $('#cita_id').val() ? 'update_cita' : 'create_cita',
          id: $('#cita_id').val() || null,
          paciente_id: $('#paciente').val() || null,
          usuario_id: window.currentUserRol === 'U' ? null : ($('#doctor').val() || window.currentUserId),
          tratamiento: $('#tratamiento').val() || null,
          fecha: $('#fecha').val(),
          hora: $('#hora').val(),
          duracion: $('#duracion').val(),
          estado: $('#estado').val(),
          nota: $('#nota').val(),
          estado_atencion: $('#estado_atencion').val(),
          motivo: $('#motivo').val() || null,
          observaciones: $('#observaciones').val() || null,
          recetas: $('#recetas').val() || null
        };

        if (window.currentUserRol === 'U') {
          try {
            const response = await saveCitaToDatabase(citaData);
            
            if (response.multiple_doctors) {
              const selectedDoctorId = await mostrarSeleccionOdontologos(response.doctors);
              citaData.usuario_id = selectedDoctorId;
              await saveCitaToDatabase(citaData);
            }
          } catch (error) {
            alert(error.message || 'Error al guardar la cita');
          }
        } else {
          saveCitaToDatabase(citaData);
        }
      });

      $('#toggleCitaStatus').click(function() {
        const estadoActual = currentEvent.extendedProps.estado_sistema || 'Activo';
        const nuevoEstado = estadoActual === 'Activo' ? 'Inactivo' : 'Activo';
        
        if (confirm(`¿Está seguro que desea ${nuevoEstado === 'Inactivo' ? 'desactivar' : 'reactivar'} esta cita?`)) {
          toggleCitaStatus(currentEvent.id, nuevoEstado);
        }
      });

      $('#asignarPacienteBtn').click(function() {
        loadPacientesParaAsignar();
        asignarPacienteModal.show();
      });

      $('#confirmarAsignacion').click(function() {
        const pacienteId = $('#selectPacienteAsignar').val();
        if (!pacienteId) {
          alert('Por favor seleccione un paciente');
          return;
        }
        
        asignarPacienteACita(currentEvent.id, pacienteId);
        asignarPacienteModal.hide();
      });

      $('#btnAsignarPacienteUsuario').click(function() {
        const usuarioId = $('#selectUsuarioAsignar').val();
        if (!usuarioId) {
          alert('Por favor seleccione un usuario');
          return;
        }
        
        if (!currentPacienteId) {
          alert('No hay paciente seleccionado para asignar');
          return;
        }
        
        asignarPacienteAUsuario(currentPacienteId, usuarioId);
      });

      $('#fecha').change(function() {
        if (window.currentUserRol === 'U' && $(this).val()) {
          visualizarHorariosDoctores($(this).val());
        }
      });

      $('#paciente').change(function() {
        const pacienteId = $(this).val();
        currentPacienteId = pacienteId;
        
        if (window.currentUserRol === 'O' && pacienteId) {
          $('#asignarPacienteUsuarioContainer').show();
          cargarUsuariosParaAsignacion();
        } else {
          $('#asignarPacienteUsuarioContainer').hide();
        }
      });

      function cargarUsuariosParaAsignacion() {
        showLoading();
        $('#selectUsuarioAsignar').empty().append('<option value="">Seleccione un usuario</option>');
        
        $.ajax({
          url: 'index.php?action=get_usuarios_disponibles',
          method: 'GET',
          dataType: 'json',
          success: function(usuarios) {
            usuarios.forEach(usuario => {
              $('#selectUsuarioAsignar').append(`<option value="${usuario.id}">${usuario.nombre} (${usuario.email})</option>`);
            });
            hideLoading();
          },
          error: function() {
            hideLoading();
            alert('Error al cargar usuarios disponibles');
          }
        });
      }

      function asignarPacienteAUsuario(pacienteId, usuarioId) {
        showLoading();
        
        $.ajax({
          url: 'index.php',
          method: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({
            action: 'asignar_paciente_usuario',
            paciente_id: pacienteId,
            usuario_id: usuarioId
          }),
          dataType: 'json',
          success: function(response) {
            if (response.success) {
              alert('Paciente asignado correctamente al usuario');
              loadInitialData();
            } else {
              alert('Error al asignar paciente: ' + (response.message || 'Intente nuevamente'));
            }
            hideLoading();
          },
          error: function() {
            hideLoading();
            alert('Error de conexión al asignar paciente');
          }
        });
      }

      async function mostrarSeleccionOdontologos(odontologos) {
        return new Promise((resolve) => {
          const modal = document.createElement('div');
          modal.className = 'modal fade';
          modal.id = 'odontologoModal';
          modal.innerHTML = `
            <div class="modal-dialog">
              <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                  <h5 class="modal-title">Seleccione un odontólogo</h5>
                  <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                  <p>Hay varios odontólogos disponibles para este horario:</p>
                  <div class="list-group">
                    ${odontologos.map(odontologo => `
                      <button type="button" class="list-group-item list-group-item-action" 
                          data-id="${odontologo.id}">
                          Dr. ${odontologo.nombre}
                      </button>
                    `).join('')}
                  </div>
                </div>
              </div>
            </div>
          `;
          
          document.body.appendChild(modal);
          const modalInstance = new bootstrap.Modal(modal);
          modalInstance.show();
          
          modal.querySelectorAll('.list-group-item').forEach(btn => {
            btn.addEventListener('click', () => {
              const selectedId = btn.dataset.id;
              modalInstance.hide();
              setTimeout(() => modal.remove(), 300);
              resolve(selectedId);
            });
          });
        });
      }

      function toggleCitaStatus(citaId, nuevoEstado) {
        showLoading();
        
        $.ajax({
          url: 'index.php',
          method: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({ 
            action: 'toggle_cita_status',
            id: citaId
          }),
          dataType: 'json',
          success: function(response) {
            if (response.success) {
              calendar.refetchEvents();
              citaModal.hide();
              alert(response.message || 'Estado de cita actualizado');
            } else {
              alert(response.message || 'Error al cambiar el estado de la cita');
            }
            hideLoading();
          },
          error: function() {
            alert('Error de conexión al cambiar el estado de la cita');
            hideLoading();
          }
        });
      }

      function loadPacientesParaAsignar() {
        showLoading();
        $('#selectPacienteAsignar').empty().append('<option value="">Seleccione...</option>');
        
        $.ajax({
          url: 'index.php?action=get_pacientes',
          method: 'GET',
          dataType: 'json',
          success: function(pacientes) {
            pacientes.forEach(paciente => {
              $('#selectPacienteAsignar').append(`<option value="${paciente.id}">${paciente.nombre} (C.I. ${paciente.cedula})</option>`);
            });
            hideLoading();
          },
          error: function() {
            hideLoading();
            alert('Error al cargar pacientes');
          }
        });
      }

      function asignarPacienteACita(citaId, pacienteId) {
        showLoading();
        
        $.ajax({
          url: 'index.php',
          method: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({
            action: 'update_cita',
            id: citaId,
            paciente_id: pacienteId
          }),
          dataType: 'json',
          success: function(response) {
            if (response.success) {
              calendar.refetchEvents();
              citaModal.hide();
              alert('Paciente asignado correctamente');
            } else {
              alert('Error al asignar paciente: ' + (response.message || 'Intente nuevamente'));
            }
            hideLoading();
          },
          error: function() {
            hideLoading();
            alert('Error de conexión al asignar paciente');
          }
        });
      }

      function visualizarHorariosDoctores(fecha) {
        showLoading();
        
        $.ajax({
          url: 'index.php?action=get_horarios_disponibles',
          method: 'GET',
          data: { fecha: fecha },
          dataType: 'json',
          success: function(horarios) {
            hideLoading();
            
            const modal = document.createElement('div');
            modal.className = 'modal fade';
            modal.id = 'horariosModal';
            modal.innerHTML = `
              <div class="modal-dialog modal-lg">
                <div class="modal-content">
                  <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Horarios disponibles - ${fecha}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="table-responsive">
                      <table class="table table-bordered table-horarios">
                        <thead class="thead-dark">
                          <tr>
                            <th>Odontólogo</th>
                            <th>Horario</th>
                            <th>Disponibilidad</th>
                          </tr>
                        </thead>
                        <tbody>
                          ${horarios.map(horario => `
                            <tr>
                              <td>Dr. ${horario.doctor_nombre}</td>
                              <td>${horario.hora_inicio} - ${horario.hora_fin}</td>
                              <td>
                                <span class="badge ${horario.disponible ? 'bg-success' : 'bg-danger'}">
                                  ${horario.disponible ? 'Disponible' : 'Ocupado'}
                                </span>
                              </td>
                            </tr>
                          `).join('')}
                        </tbody>
                      </table>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                  </div>
                </div>
              </div>
            `;
            
            document.body.appendChild(modal);
            const modalInstance = new bootstrap.Modal(modal);
            modalInstance.show();
            
            modal.addEventListener('hidden.bs.modal', () => {
              modal.remove();
            });
          },
          error: function() {
            hideLoading();
            alert('Error al cargar horarios');
          }
        });
      }

      function saveSimpleCitaToDatabase(citaData) {
        showLoading();
        
        $.ajax({
          url: 'index.php',
          method: 'POST',
          contentType: 'application/json',
          data: JSON.stringify(citaData),
          dataType: 'json',
          success: function(response) {
            if (response.success) {
              const newEvent = createCalendarEvent(response.cita);
              calendar.addEvent(newEvent);
              
              if (calendar.view.type === 'dayGridMonth') {
                calendar.changeView('timeGridDay', citaData.fecha);
              }
              
              simpleCitaModal.hide();
              alert(response.message || 'Cita solicitada con éxito');
            } else {
              alert(response.message || 'Error al solicitar la cita');
            }
            hideLoading();
          },
          error: function(xhr, status, error) {
            let errorMessage = 'Error de conexión al solicitar la cita';
            try {
              const response = JSON.parse(xhr.responseText);
              if (response.message) {
                errorMessage = response.message;
              }
            } catch (e) {}
            
            alert(errorMessage);
            hideLoading();
          }
        });
      }

      function validateSimpleForm() {
        if (!$('#telefono').val()) {
          alert('Por favor ingrese su teléfono');
          return false;
        }

        if (!$('#telefono').val().match(/^[0-9]{10,11}$/)) {
          alert('El teléfono debe tener 10 u 11 dígitos');
          return false;
        }

        if (!$('#hora_simple').val()) {
          alert('Por favor seleccione una hora');
          return false;
        }

        const horaSeleccionada = $('#hora_simple').val();
        const [horas, minutos] = horaSeleccionada.split(':').map(Number);
        
        if (horas >= 17) {
          alert('No se pueden agendar citas después de las 5 PM');
          return false;
        }

        return true;
      }

      function loadInitialData() {
        showLoading();
        
        $.ajax({
          url: 'index.php?action=get_pacientes',
          method: 'GET',
          dataType: 'json',
          success: function(pacientes) {
            const select = $('#paciente');
            select.empty().append('<option value="">Seleccione un paciente</option>');
            
            if (pacientes.length > 0 && (window.currentUserRol === 'U' || window.currentUserRol === 'O')) {
              select.append(`<option value="${pacientes[0].id}" selected>${pacientes[0].nombre} (C.I. ${pacientes[0].cedula})</option>`);
              currentPacienteId = pacientes[0].id;
              
              if (window.currentUserRol === 'O') {
                $('#asignarPacienteUsuarioContainer').show();
                cargarUsuariosParaAsignacion();
              }
            } else {
              pacientes.forEach(paciente => {
                select.append(`<option value="${paciente.id}">${paciente.nombre} (C.I. ${paciente.cedula})</option>`);
              });
            }
            
            loadDoctores();
          },
          error: function() {
            console.error('Error al cargar pacientes');
            hideLoading();
          }
        });
      }

      function loadDoctores() {
        $.ajax({
          url: 'index.php?action=get_doctores',
          method: 'GET',
          dataType: 'json',
          success: function(doctores) {
            const select = $('#doctor');
            select.empty().append('<option value="">Seleccione un odontólogo</option>');
            doctores.forEach(doctor => {
              select.append(`<option value="${doctor.id}">Dr. ${doctor.nombre}</option>`);
            });
            
            if (window.currentUserRol === 'O' && doctores.length > 0) {
              select.val(window.currentUserId);
            }
            hideLoading();
          },
          error: function() {
            console.error('Error al cargar doctores');
            hideLoading();
          }
        });
      }

      function loadEventData(event) {
        resetForm();
        $('#citaModalLabel').text('Editar Cita');
        
        if (window.currentUserRol === 'A' || window.currentUserRol === 'S') {
          $('#toggleCitaStatus').show();
          const estadoActual = event.extendedProps.estado_sistema || 'Activo';
          $('#toggleCitaStatus').text(estadoActual === 'Activo' ? 'Desactivar' : 'Activar');
          
          $('#estadoSistemaContainer').show();
          $('#estadoSistema').text(estadoActual)
            .removeClass('badge-success badge-dark')
            .addClass(estadoActual === 'Activo' ? 'badge-success' : 'badge-dark');
        } else {
          $('#toggleCitaStatus').hide();
          $('#estadoSistemaContainer').hide();
        }
        
        const extendedProps = event.extendedProps;
        $('#cita_id').val(event.id);
        $('#paciente').val(extendedProps.paciente_id || '');
        currentPacienteId = extendedProps.paciente_id || null;
        $('#doctor').val(extendedProps.usuario_id);
        $('#tratamiento').val(extendedProps.tratamiento || '');
        
        const startDate = event.start;
        $('#fecha').val(startDate.toISOString().split('T')[0]);
        
        const hours = String(startDate.getHours()).padStart(2, '0');
        const minutes = String(startDate.getMinutes()).padStart(2, '0');
        $('#hora').val(`${hours}:${minutes}`);
        
        const durationMs = event.end - event.start;
        const durationMins = Math.round(durationMs / 60000);
        $('#duracion').val(durationMins);
        
        $('#estado').val(extendedProps.estado || 'Pendiente');
        $('#nota').val(extendedProps.nota || '');
        $('#estado_atencion').val(extendedProps.estado_atencion || 'Por atender');
        $('#motivo').val(extendedProps.motivo || '');
        $('#observaciones').val(extendedProps.observaciones || '');
        $('#recetas').val(extendedProps.recetas || '');
        
        if (window.currentUserRol === 'O' && currentPacienteId) {
          $('#asignarPacienteUsuarioContainer').show();
          cargarUsuariosParaAsignacion();
        } else {
          $('#asignarPacienteUsuarioContainer').hide();
        }
      }

      function saveCitaToDatabase(citaData) {
        showLoading();
        
        return new Promise((resolve, reject) => {
          $.ajax({
            url: 'index.php',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(citaData),
            dataType: 'json',
            success: function(response) {
              if (response.success) {
                if (citaData.action === 'create_cita') {
                  const newEvent = createCalendarEvent(response.cita);
                  calendar.addEvent(newEvent);
                  
                  if (calendar.view.type === 'dayGridMonth') {
                    calendar.changeView('timeGridDay', citaData.fecha);
                  }
                } else {
                  calendar.refetchEvents();
                }
                
                citaModal.hide();
                alert(response.message || 'Operación realizada con éxito');
                resolve(response);
              } else {
                alert(response.message || 'Error al realizar la operación');
                reject(new Error(response.message || 'Error al realizar la operación'));
              }
              hideLoading();
            },
            error: function(xhr, status, error) {
              let errorMessage = 'Error de conexión al guardar la cita';
              try {
                const response = JSON.parse(xhr.responseText);
                if (response.message) {
                  errorMessage = response.message;
                }
              } catch (e) {}
              
              alert(errorMessage);
              hideLoading();
              reject(new Error(errorMessage));
            }
          });
        });
      }

      function updateEventInDatabase(event) {
        return new Promise((resolve, reject) => {
            showLoading();
            
            const extendedProps = event.extendedProps;
            const startDate = event.start;
            const endDate = event.end;
            const durationMs = endDate - startDate;
            const durationMins = Math.round(durationMs / 60000);

            const hoy = new Date();
            hoy.setHours(0, 0, 0, 0);
            const fechaCita = new Date(startDate);
            fechaCita.setHours(0, 0, 0, 0);
            
            if (fechaCita < hoy) {
                reject(new Error('No se pueden mover citas a fechas pasadas'));
                return;
            }
            
            if (fechaCita.getTime() === hoy.getTime()) {
                const horaActual = new Date();
                const horaCita = new Date(startDate);
                
                if (horaCita < horaActual) {
                    reject(new Error('No se pueden mover citas a horas pasadas para el día de hoy'));
                    return;
                }
            }

            const citaData = {
                action: 'update_cita',
                id: event.id,
                paciente_id: extendedProps.paciente_id,
                usuario_id: extendedProps.usuario_id,
                tratamiento: extendedProps.tratamiento || null,
                fecha: startDate.toISOString().split('T')[0],
                hora: `${String(startDate.getHours()).padStart(2, '0')}:${String(startDate.getMinutes()).padStart(2, '0')}`,
                duracion: durationMins,
                estado: extendedProps.estado || 'Pendiente',
                nota: extendedProps.nota || '',
                estado_atencion: extendedProps.estado_atencion || 'Por atender',
                motivo: extendedProps.motivo || null,
                observaciones: extendedProps.observaciones || null,
                recetas: extendedProps.recetas || null
            };

            $.ajax({
                url: 'index.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(citaData),
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        resolve(response);
                    } else {
                        reject(new Error(response.message || 'Error al actualizar la cita'));
                    }
                    hideLoading();
                },
                error: function(xhr, status, error) {
                    let errorMessage = 'Error de conexión al actualizar la cita';
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.message) {
                            errorMessage = response.message;
                        }
                    } catch (e) {}
                    reject(new Error(errorMessage));
                    hideLoading();
                }
            });
        });
      }

      function calculateEndTime(fecha, hora, duracion) {
        const horaCompleta = hora.includes(':') ? hora : hora + ':00';
        const [hours, minutes, seconds] = horaCompleta.split(':').map(Number);
        
        const startDate = new Date(`${fecha}T${horaCompleta}`);
        if (isNaN(startDate.getTime())) {
          console.error('Fecha u hora inválida:', fecha, horaCompleta);
          return null;
        }
        
        const endDate = new Date(startDate.getTime() + duracion * 60000);
       
        return endDate.toISOString();
      }

      function getEventColor(estado, estadoSistema = 'Activo', estadoAtencion = 'Por atender') {
        if (estadoSistema === 'Inactivo') {
          return '#6c757d';
        }
        
        if (estadoAtencion !== 'Por atender') {
          switch(estadoAtencion) {
            case 'Atendido': return '#20c997';
            case 'Cancelado': return '#dc3545';
            case 'Postergado': return '#fd7e14';
          }
        }
        
        switch(estado) {
          case 'Confirmado': return '#28a745';
          case 'Cancelado': return '#dc3545';
          case 'Completado': return '#17a2b8';
          default: return '#ffc107';
        }
      }

      function validateForm() {
        if (window.currentUserRol !== 'U' && !$('#doctor').val()) {
          alert('Por favor seleccione un odontólogo');
          return false;
        }
        if (!$('#fecha').val()) {
          alert('Por favor ingrese una fecha');
          return false;
        }
        if (!$('#hora').val()) {
          alert('Por favor ingrese una hora');
          return false;
        }
        if (!$('#duracion').val() || $('#duracion').val() < 15) {
          alert('La duración mínima es de 15 minutos');
          return false;
        }
        
        const fechaSeleccionada = new Date($('#fecha').val());
        const hoy = new Date();
        hoy.setHours(0, 0, 0, 0);
        
        if (fechaSeleccionada < hoy) {
          alert('No se pueden agendar citas en fechas pasadas');
          return false;
        }
        
        if (fechaSeleccionada.getTime() === hoy.getTime()) {
          const horaActual = new Date();
          const horaSeleccionada = new Date();
          const [horas, minutos] = $('#hora').val().split(':').map(Number);
          horaSeleccionada.setHours(horas, minutos, 0, 0);
          
          if (horaSeleccionada < horaActual) {
            alert('No se pueden agendar citas en horas pasadas para el día de hoy');
            return false;
          }
        }
        
        const horaSeleccionada = $('#hora').val();
        const [horas, minutos] = horaSeleccionada.split(':').map(Number);
        
        if (horas >= 17) {
          alert('No se pueden agendar citas después de las 5 PM');
          return false;
        }
        
        return true;
      }

      function resetForm() {
        $('#citaForm')[0].reset();
        $('#cita_id').val('');
        currentPacienteId = null;
        $('#citaModalLabel').text('Nueva Cita');
        $('#toggleCitaStatus').hide();
        $('#asignarPacienteBtn').hide();
        $('#estadoSistemaContainer').hide();
        $('#asignarPacienteUsuarioContainer').hide();
        $('#hora').attr('min', '08:00').attr('max', '17:00');
        $('#duracion').val('30');
        $('#estado').val('Pendiente');
        $('#estado_atencion').val('Por atender');
        $('#motivo').val('');
        $('#observaciones').val('');
        $('#recetas').val('');
        $('#atencion-tab').addClass('d-none');
      }

      function createCalendarEvent(cita) {
        const hora = cita.hora.includes(':') ? cita.hora : cita.hora + ':00';
        const sinPaciente = !cita.paciente_id;
        
        return {
          id: cita.id,
          title: window.currentUserRol === 'U' ? 
            `Cita (${cita.estado}) - Dr. ${cita.doctor_nombre}` : 
            `${sinPaciente ? 'Cita sin paciente' : cita.paciente_nombre} (${cita.doctor_nombre})${cita.tratamiento ? '\n' + cita.tratamiento : ''}`,
          start: `${cita.fecha}T${hora}`,
          end: calculateEndTime(cita.fecha, hora, cita.duracion),
          extendedProps: {
            paciente_id: cita.paciente_id,
            usuario_id: cita.usuario_id,
            tratamiento: cita.tratamiento,
            estado: cita.estado,
            nota: cita.nota,
            estado_atencion: cita.estado_atencion,
            motivo: cita.motivo,
            observaciones: cita.observaciones,
            recetas: cita.recetas,
            doctor_nombre: cita.doctor_nombre,
            estado_sistema: cita.estado_sistema || 'Activo',
            telefono_paciente: cita.telefono_paciente,
            sin_paciente: sinPaciente
          },
          backgroundColor: getEventColor(cita.estado, cita.estado_sistema, cita.estado_atencion),
          borderColor: getEventColor(cita.estado, cita.estado_sistema, cita.estado_atencion),
          classNames: sinPaciente ? ['no-paciente'] : []
        };
      }

      function checkDayAvailability(fecha) {
        let hasAvailability = false;
        
        $.ajax({
          url: 'index.php?action=get_horarios_disponibles',
          method: 'GET',
          async: false,
          data: { fecha: fecha },
          dataType: 'json',
          success: function(horarios) {
            hasAvailability = horarios.some(h => h.disponible);
          },
          error: function() {
            console.error('Error al verificar disponibilidad para el día');
          }
        });
        
        return hasAvailability;
      }
    });
  </script>
</body>
</html>
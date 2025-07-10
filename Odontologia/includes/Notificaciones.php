<?php
class Notificaciones {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    public function verificarStock() {
        // Materiales vencidos
        $sql_vencidos = "SELECT id, nombre FROM stock_materiales 
                        WHERE fecha_vencimiento < CURDATE() 
                        AND Estado_Sistema = 'Activo'";
        $stmt_vencidos = $this->db->prepare($sql_vencidos);
        $stmt_vencidos->execute();
        $vencidos = $stmt_vencidos->fetchAll(PDO::FETCH_ASSOC);
        
        // Materiales próximos a vencer (30 días o menos)
        $sql_proximos = "SELECT id, nombre FROM stock_materiales 
                        WHERE fecha_vencimiento BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                        AND Estado_Sistema = 'Activo'";
        $stmt_proximos = $this->db->prepare($sql_proximos);
        $stmt_proximos->execute();
        $proximos = $stmt_proximos->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener usuarios que deben recibir notificaciones (A, O)
        $sql_usuarios = "SELECT id FROM usuario WHERE rol_type IN ('A', 'O') AND Estado_Sistema = 'Activo'";
        $stmt_usuarios = $this->db->prepare($sql_usuarios);
        $stmt_usuarios->execute();
        $usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Generar notificaciones
        foreach ($usuarios as $usuario_id) {
            // Notificar materiales vencidos
            foreach ($vencidos as $material) {
                $this->crearNotificacion(
                    $usuario_id,
                    'stock_vencido',
                    "El material '{$material['nombre']}' ha vencido",
                    $material['id']
                );
            }
            
            // Notificar materiales próximos a vencer
            foreach ($proximos as $material) {
                $this->crearNotificacion(
                    $usuario_id,
                    'stock_proximo_vencer',
                    "El material '{$material['nombre']}' está próximo a vencer",
                    $material['id']
                );
            }
        }
    }
    
    public function verificarCitasProximas() {
        // Citas en los próximos 60 minutos
        $sql = "SELECT c.id, p.nombre as paciente_nombre, u.nombre as doctor_nombre, c.fecha, c.hora
                FROM citas c
                JOIN paciente p ON c.paciente_id = p.id
                JOIN usuario u ON c.usuario_id = u.id
                WHERE c.estado = 'Confirmado'
                AND c.estado_atencion = 'Por atender'
                AND CONCAT(c.fecha, ' ', c.hora) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 60 MINUTE)
                AND c.Estado_Sistema = 'Activo'";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener usuarios que deben recibir notificaciones (A, O, S)
        $sql_usuarios = "SELECT id FROM usuario WHERE rol_type IN ('A', 'O', 'S') AND Estado_Sistema = 'Activo'";
        $stmt_usuarios = $this->db->prepare($sql_usuarios);
        $stmt_usuarios->execute();
        $usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_COLUMN, 0);
        
        // Generar notificaciones
        foreach ($usuarios as $usuario_id) {
            foreach ($citas as $cita) {
                $hora_cita = date('H:i', strtotime($cita['hora']));
                $this->crearNotificacion(
                    $usuario_id,
                    'cita_proxima',
                    "Cita próxima con {$cita['paciente_nombre']} a las {$hora_cita} (Dr. {$cita['doctor_nombre']})",
                    $cita['id']
                );
            }
        }
    }
    
    private function crearNotificacion($usuario_id, $tipo, $mensaje, $relacion_id = null) {
        // Verificar si ya existe una notificación similar no leída
        $sql_check = "SELECT id FROM notificaciones 
                     WHERE usuario_id = :usuario_id 
                     AND tipo = :tipo 
                     AND relacion_id = :relacion_id 
                     AND leida = 0
                     AND Estado_Sistema = 'Activo'";
        
        $stmt_check = $this->db->prepare($sql_check);
        $stmt_check->execute([
            ':usuario_id' => $usuario_id,
            ':tipo' => $tipo,
            ':relacion_id' => $relacion_id
        ]);
        
        if ($stmt_check->fetch()) {
            return; // Ya existe una notificación similar no leída
        }
        
        // Crear nueva notificación
        $sql = "INSERT INTO notificaciones 
               (usuario_id, tipo, mensaje, relacion_id) 
               VALUES 
               (:usuario_id, :tipo, :mensaje, :relacion_id)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':usuario_id' => $usuario_id,
            ':tipo' => $tipo,
            ':mensaje' => $mensaje,
            ':relacion_id' => $relacion_id
        ]);
    }
    
    public function obtenerNotificaciones($usuario_id, $no_leidas = true) {
        $sql = "SELECT * FROM notificaciones 
               WHERE usuario_id = :usuario_id 
               AND Estado_Sistema = 'Activo'";
        
        if ($no_leidas) {
            $sql .= " AND leida = 0";
        }
        
        $sql .= " ORDER BY fecha_creacion DESC LIMIT 10";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':usuario_id' => $usuario_id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function marcarComoLeida($notificacion_id, $usuario_id) {
        $sql = "UPDATE notificaciones 
               SET leida = 1, fecha_lectura = NOW() 
               WHERE id = :id AND usuario_id = :usuario_id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':id' => $notificacion_id,
            ':usuario_id' => $usuario_id
        ]);
    }
    
    public function marcarTodasComoLeidas($usuario_id) {
        $sql = "UPDATE notificaciones 
               SET leida = 1, fecha_lectura = NOW() 
               WHERE usuario_id = :usuario_id AND leida = 0";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':usuario_id' => $usuario_id]);
    }
}
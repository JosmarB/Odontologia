<?php
class HistoriaMedica {
    private $id;
    private $paciente_id;
    private $examinador_id;
    private $fecha_creacion;

    // Getters y Setters
    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; }
    public function getPacienteId() { return $this->paciente_id; }
    public function setPacienteId($paciente_id) { $this->paciente_id = $paciente_id; }
    public function getExaminadorId() { return $this->examinador_id; }
    public function setExaminadorId($examinador_id) { $this->examinador_id = $examinador_id; }
    public function getFechaCreacion() { return $this->fecha_creacion; }
    public function setFechaCreacion($fecha_creacion) { $this->fecha_creacion = $fecha_creacion; }

    public static function all($db) {
        try {
            $stmt = $db->prepare("
                SELECT hm.*, 
                       u.nombre as examinador_nombre, 
                       p.nombre as paciente_nombre,
                       p.cedula as paciente_cedula
                FROM Historia_Medica hm
                JOIN Usuario u ON hm.examinador_id = u.id
                JOIN Paciente p ON hm.paciente_id = p.id
                ORDER BY hm.fecha_creacion DESC
            ");
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en HistoriaMedica::all(): " . $e->getMessage());
            return [];
        }
    }

    public static function findByPaciente($db, $paciente_id) {
        try {
            $stmt = $db->prepare("
                SELECT hm.*, 
                       u.nombre as examinador_nombre, 
                       p.nombre as paciente_nombre,
                       p.cedula as paciente_cedula
                FROM Historia_Medica hm
                JOIN Usuario u ON hm.examinador_id = u.id
                JOIN Paciente p ON hm.paciente_id = p.id
                WHERE hm.paciente_id = ?
                ORDER BY hm.fecha_creacion DESC
            ");
            $stmt->execute([$paciente_id]);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Error en HistoriaMedica::findByPaciente(): " . $e->getMessage());
            return [];
        }
    }

    public static function find($db, $id) {
        try {
            $stmt = $db->prepare("
                SELECT hm.*, 
                       u.nombre as examinador_nombre, 
                       p.nombre as paciente_nombre,
                       p.cedula as paciente_cedula,
                       p.edad as paciente_edad,
                       p.telefono as paciente_telefono
                FROM Historia_Medica hm
                JOIN Usuario u ON hm.examinador_id = u.id
                JOIN Paciente p ON hm.paciente_id = p.id
                WHERE hm.id = ?
                LIMIT 1
            ");
            $stmt->execute([$id]);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Error en HistoriaMedica::find(): " . $e->getMessage());
            return null;
        }
    }

    public function save($db) {
        try {
            if ($this->id) {
                // Actualización
                $stmt = $db->prepare("
                    UPDATE Historia_Medica 
                    SET paciente_id = ?, examinador_id = ?
                    WHERE id = ?
                ");
                return $stmt->execute([
                    $this->paciente_id,
                    $this->examinador_id,
                    $this->id
                ]);
            } else {
                // Creación
                $stmt = $db->prepare("
                    INSERT INTO Historia_Medica 
                    (paciente_id, examinador_id) 
                    VALUES (?, ?)
                ");
                $result = $stmt->execute([
                    $this->paciente_id,
                    $this->examinador_id
                ]);
                
                if ($result) {
                    $this->id = $db->lastInsertId();
                }
                return $result;
            }
        } catch (PDOException $e) {
            error_log("Error en HistoriaMedica::save(): " . $e->getMessage());
            return false;
        }
    }
}
?>
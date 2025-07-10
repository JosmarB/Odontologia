<?php
class HorarioDoctor {
    private $id;
    private $usuario_id;
    private $dia_semana;
    private $hora_inicio;
    private $hora_fin;
    private $activo;

    // Getters
    public function getId() {
        return $this->id;
    }

    public function getUsuarioId() {
        return $this->usuario_id;
    }

    public function getDiaSemana() {
        return $this->dia_semana;
    }

    public function getHoraInicio() {
        return $this->hora_inicio;
    }

    public function getHoraFin() {
        return $this->hora_fin;
    }

    public function getActivo() {
        return $this->activo;
    }

    // Setters
    public function setId($id) {
        $this->id = $id;
        return $this;
    }

    public function setUsuarioId($usuario_id) {
        $this->usuario_id = $usuario_id;
        return $this;
    }

    public function setDiaSemana($dia_semana) {
        $this->dia_semana = $dia_semana;
        return $this;
    }

    public function setHoraInicio($hora_inicio) {
        $this->hora_inicio = $hora_inicio;
        return $this;
    }

    public function setHoraFin($hora_fin) {
        $this->hora_fin = $hora_fin;
        return $this;
    }

    public function setActivo($activo) {
        $this->activo = $activo;
        return $this;
    }

    /**
     * Guarda un horario en la base de datos (crea o actualiza)
     * @param PDO $db Conexión a la base de datos
     * @return bool True si la operación fue exitosa, false en caso contrario
     */
    public function save($db) {
        try {
            if ($this->id) {
                // Actualización
                $stmt = $db->prepare("UPDATE horarios_doctor SET 
                                    dia_semana = :dia_semana,
                                    hora_inicio = :hora_inicio,
                                    hora_fin = :hora_fin,
                                    activo = :activo
                                    WHERE id = :id");
                $stmt->bindParam(':id', $this->id);
            } else {
                // Inserción
                $stmt = $db->prepare("INSERT INTO horarios_doctor 
                                    (usuario_id, dia_semana, hora_inicio, hora_fin, activo)
                                    VALUES 
                                    (:usuario_id, :dia_semana, :hora_inicio, :hora_fin, :activo)");
                $stmt->bindParam(':usuario_id', $this->usuario_id);
            }

            $stmt->bindParam(':dia_semana', $this->dia_semana);
            $stmt->bindParam(':hora_inicio', $this->hora_inicio);
            $stmt->bindParam(':hora_fin', $this->hora_fin);
            $stmt->bindParam(':activo', $this->activo, PDO::PARAM_INT);

            $result = $stmt->execute();

            if (!$this->id) {
                $this->id = $db->lastInsertId();
            }

            return $result;
        } catch (PDOException $e) {
            error_log("Error al guardar horario: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Elimina un horario de la base de datos
     * @param PDO $db Conexión a la base de datos
     * @return bool True si la operación fue exitosa, false en caso contrario
     */
    public function delete($db) {
        try {
            $stmt = $db->prepare("DELETE FROM horarios_doctor WHERE id = :id");
            $stmt->bindParam(':id', $this->id);
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Error al eliminar horario: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Busca un horario por su ID
     * @param PDO $db Conexión a la base de datos
     * @param int $id ID del horario a buscar
     * @return HorarioDoctor|null El objeto HorarioDoctor encontrado o null si no existe
     */
    public static function find($db, $id) {
        try {
            $stmt = $db->prepare("SELECT * FROM horarios_doctor WHERE id = :id");
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $horario = new HorarioDoctor();
                $horario->setId($row['id'])
                       ->setUsuarioId($row['usuario_id'])
                       ->setDiaSemana($row['dia_semana'])
                       ->setHoraInicio($row['hora_inicio'])
                       ->setHoraFin($row['hora_fin'])
                       ->setActivo($row['activo']);
                return $horario;
            }
            return null;
        } catch (PDOException $e) {
            error_log("Error al buscar horario: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Obtiene todos los horarios de un odontólogo específico
     * @param PDO $db Conexión a la base de datos
     * @param int $usuario_id ID del odontólogo
     * @return array Lista de objetos HorarioDoctor
     */
    public static function findByUsuario($db, $usuario_id) {
        try {
            $stmt = $db->prepare("SELECT * FROM horarios_doctor 
                                 WHERE usuario_id = :usuario_id 
                                 ORDER BY dia_semana, hora_inicio");
            $stmt->bindParam(':usuario_id', $usuario_id);
            $stmt->execute();

            $horarios = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $horario = new HorarioDoctor();
                $horario->setId($row['id'])
                       ->setUsuarioId($row['usuario_id'])
                       ->setDiaSemana($row['dia_semana'])
                       ->setHoraInicio($row['hora_inicio'])
                       ->setHoraFin($row['hora_fin'])
                       ->setActivo($row['activo']);
                $horarios[] = $horario;
            }
            return $horarios;
        } catch (PDOException $e) {
            error_log("Error al buscar horarios por usuario: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Verifica si un odontólogo tiene disponibilidad en una fecha y hora específica
     * @param PDO $db Conexión a la base de datos
     * @param int $usuario_id ID del odontólogo
     * @param string $fecha Fecha en formato YYYY-MM-DD
     * @param string $hora Hora en formato HH:MM
     * @return bool True si está disponible, false si no
     */
    public static function verificarDisponibilidad($db, $usuario_id, $fecha, $hora) {
        try {
            // Obtener día de la semana (1=Lunes, 7=Domingo)
            $dia_semana = date('N', strtotime($fecha));

            $stmt = $db->prepare("SELECT COUNT(*) FROM horarios_doctor 
                                 WHERE usuario_id = :usuario_id 
                                 AND dia_semana = :dia_semana
                                 AND activo = 1
                                 AND :hora BETWEEN hora_inicio AND hora_fin");
            $stmt->bindParam(':usuario_id', $usuario_id);
            $stmt->bindParam(':dia_semana', $dia_semana);
            $stmt->bindParam(':hora', $hora);
            $stmt->execute();

            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Error al verificar disponibilidad: " . $e->getMessage());
            return false;
        }
    }
}
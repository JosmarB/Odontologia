<?php
class Anamnesis {
    private $id;
    private $historia_medica_id;
    private $motivo_consulta;
    private $enfermedad_actual;
    private $antecedentesFamiliares = [];
    private $habitos = [];
    private $antecedentes_personales;
    private $revision_organos;

    // Getters y Setters básicos
    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; }
    
    public function getHistoriaMedicaId() { return $this->historia_medica_id; }
    public function setHistoriaMedicaId($historia_medica_id) { $this->historia_medica_id = $historia_medica_id; }
    
    public function getMotivoConsulta() { return $this->motivo_consulta; }
    public function setMotivoConsulta($motivo_consulta) { $this->motivo_consulta = $motivo_consulta; }
    
    public function getEnfermedadActual() { return $this->enfermedad_actual; }
    public function setEnfermedadActual($enfermedad_actual) { $this->enfermedad_actual = $enfermedad_actual; }
    
    public function getAntecedentesPersonales() { return $this->antecedentes_personales; }
    public function setAntecedentesPersonales($antecedentes_personales) { $this->antecedentes_personales = $antecedentes_personales; }
    
    public function getRevisionOrganos() { return $this->revision_organos; }
    public function setRevisionOrganos($revision_organos) { $this->revision_organos = $revision_organos; }

    // Métodos para antecedentes familiares
    public function getAntecedentesFamiliares() {
        return $this->antecedentesFamiliares;
    }

    public function setAntecedentesFamiliares(array $antecedentes) {
        $this->antecedentesFamiliares = $antecedentes;
    }

    // Métodos para hábitos
    public function getHabitos() {
        return $this->habitos;
    }

    public function setHabitos(array $habitos) {
        $this->habitos = $habitos;
    }

    /**
     * Buscar anamnesis por ID de historia médica
     */
    public static function findByHistoria($db, $historia_medica_id) {
        $stmt = $db->prepare("
            SELECT * FROM anamnesis 
            WHERE historia_medica_id = ?
            LIMIT 1
        ");
        $stmt->execute([$historia_medica_id]);
        $row = $stmt->fetch(PDO::FETCH_OBJ);
        
        if (!$row) return null;
        
        $anamnesis = new Anamnesis();
        $anamnesis->setId($row->id);
        $anamnesis->setHistoriaMedicaId($row->historia_medica_id);
        $anamnesis->setMotivoConsulta($row->motivo_consulta);
        $anamnesis->setEnfermedadActual($row->enfermedad_actual);
        $anamnesis->setAntecedentesPersonales($row->antecedentes_personales);
        $anamnesis->setRevisionOrganos($row->revision_organos);
        
        return $anamnesis;
    }

    /**
     * Guardar una anamnesis (crear o actualizar)
     */
    public function save($db) {
        try {
            if ($this->id) {
                // Actualización
                $stmt = $db->prepare("
                    UPDATE anamnesis 
                    SET motivo_consulta = ?, enfermedad_actual = ?, 
                        antecedentes_personales = ?, revision_organos = ?
                    WHERE id = ?
                ");
                $result = $stmt->execute([
                    $this->motivo_consulta,
                    $this->enfermedad_actual,
                    $this->antecedentes_personales,
                    $this->revision_organos,
                    $this->id
                ]);
            } else {
                // Creación
                $stmt = $db->prepare("
                    INSERT INTO anamnesis 
                    (historia_medica_id, motivo_consulta, enfermedad_actual, 
                     antecedentes_personales, revision_organos) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $result = $stmt->execute([
                    $this->historia_medica_id,
                    $this->motivo_consulta,
                    $this->enfermedad_actual,
                    $this->antecedentes_personales,
                    $this->revision_organos
                ]);
                
                if ($result) {
                    $this->id = $db->lastInsertId();
                }
            }
            
            return $result;
        } catch (PDOException $e) {
            throw new Exception("Error al guardar anamnesis: " . $e->getMessage());
        }
    }

    /**
     * Eliminar una anamnesis
     */
    public static function delete($db, $id) {
        try {
            // Primero eliminamos los registros relacionados
            AntecedentesFamiliares::deleteByAnamnesis($db, $id);
            Habitos::deleteByAnamnesis($db, $id);
            
            // Luego eliminamos la anamnesis
            $stmt = $db->prepare("DELETE FROM anamnesis WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            throw new Exception("Error al eliminar anamnesis: " . $e->getMessage());
        }
    }
}
?>
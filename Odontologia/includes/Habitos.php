<?php
class Habitos {
    private $id;
    private $anamnesis_id;
    private $descripcion;

    // Getters y Setters
    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; }
    
    public function getAnamnesisId() { return $this->anamnesis_id; }
    public function setAnamnesisId($anamnesis_id) { $this->anamnesis_id = $anamnesis_id; }
    
    public function getDescripcion() { return $this->descripcion; }
    public function setDescripcion($descripcion) { 
        $this->descripcion = trim($descripcion); 
    }

    /**
     * Guardar un hábito (crear o actualizar)
     */
    public function save($db) {
        try {
            if ($this->id) {
                // Actualización
                $stmt = $db->prepare("
                    UPDATE habitos 
                    SET descripcion = ?
                    WHERE id = ?
                ");
                $result = $stmt->execute([
                    $this->descripcion,
                    $this->id
                ]);
            } else {
                // Creación
                $stmt = $db->prepare("
                    INSERT INTO habitos 
                    (anamnesis_id, descripcion) 
                    VALUES (?, ?)
                ");
                $result = $stmt->execute([
                    $this->anamnesis_id,
                    $this->descripcion
                ]);
                
                if ($result) {
                    $this->id = $db->lastInsertId();
                }
            }
            
            return $result;
        } catch (PDOException $e) {
            throw new Exception("Error al guardar hábito: " . $e->getMessage());
        }
    }

    /**
     * Buscar hábitos por ID de anamnesis
     */
    public static function findByAnamnesis($db, $anamnesis_id) {
        $stmt = $db->prepare("
            SELECT * FROM habitos 
            WHERE anamnesis_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$anamnesis_id]);
        
        $habitos = [];
        while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
            $habito = new Habitos();
            $habito->setId($row->id);
            $habito->setAnamnesisId($row->anamnesis_id);
            $habito->setDescripcion($row->descripcion);
            
            $habitos[] = $habito;
        }
        
        return $habitos;
    }

    /**
     * Eliminar un hábito
     */
    public static function delete($db, $id) {
        try {
            $stmt = $db->prepare("DELETE FROM habitos WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            throw new Exception("Error al eliminar hábito: " . $e->getMessage());
        }
    }

    /**
     * Eliminar todos los hábitos de una anamnesis
     */
    public static function deleteByAnamnesis($db, $anamnesis_id) {
        try {
            $stmt = $db->prepare("DELETE FROM habitos WHERE anamnesis_id = ?");
            return $stmt->execute([$anamnesis_id]);
        } catch (PDOException $e) {
            throw new Exception("Error al eliminar hábitos: " . $e->getMessage());
        }
    }
}
?>
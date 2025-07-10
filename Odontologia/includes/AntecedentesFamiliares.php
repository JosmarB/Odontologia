<?php
class AntecedentesFamiliares {
    private $id;
    private $anamnesis_id;
    private $tipo;
    private $descripcion;

    const TIPOS = [
        'P' => 'Paternos',
        'M' => 'Maternos', 
        'A' => 'Ambos'
    ];

    // Getters y Setters
    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; }
    
    public function getAnamnesisId() { return $this->anamnesis_id; }
    public function setAnamnesisId($anamnesis_id) { $this->anamnesis_id = $anamnesis_id; }
    
    public function getTipo() { return $this->tipo; }
    public function setTipo($tipo) { 
        if (!array_key_exists($tipo, self::TIPOS)) {
            throw new Exception("Tipo de antecedente no válido");
        }
        $this->tipo = $tipo; 
    }
    
    public function getDescripcion() { return $this->descripcion; }
    public function setDescripcion($descripcion) { 
        $this->descripcion = trim($descripcion); 
    }

    /**
     * Obtener el texto descriptivo del tipo
     */
    public function getTipoTexto() {
        return self::TIPOS[$this->tipo] ?? 'Desconocido';
    }

    /**
     * Guardar un antecedente familiar (crear o actualizar)
     */
    public function save($db) {
        try {
            if ($this->id) {
                // Actualización
                $stmt = $db->prepare("
                    UPDATE antecedentesfamiliares 
                    SET tipo = ?, descripcion = ?
                    WHERE id = ?
                ");
                $result = $stmt->execute([
                    $this->tipo,
                    $this->descripcion,
                    $this->id
                ]);
            } else {
                // Creación
                $stmt = $db->prepare("
                    INSERT INTO antecedentesfamiliares 
                    (anamnesis_id, tipo, descripcion) 
                    VALUES (?, ?, ?)
                ");
                $result = $stmt->execute([
                    $this->anamnesis_id,
                    $this->tipo,
                    $this->descripcion
                ]);
                
                if ($result) {
                    $this->id = $db->lastInsertId();
                }
            }
            
            return $result;
        } catch (PDOException $e) {
            throw new Exception("Error al guardar antecedente familiar: " . $e->getMessage());
        }
    }

    /**
     * Buscar antecedentes por ID de anamnesis
     */
    public static function findByAnamnesis($db, $anamnesis_id) {
        $stmt = $db->prepare("
            SELECT * FROM antecedentesfamiliares 
            WHERE anamnesis_id = ?
            ORDER BY tipo ASC, id ASC
        ");
        $stmt->execute([$anamnesis_id]);
        
        $antecedentes = [];
        while ($row = $stmt->fetch(PDO::FETCH_OBJ)) {
            $antecedente = new AntecedentesFamiliares();
            $antecedente->setId($row->id);
            $antecedente->setAnamnesisId($row->anamnesis_id);
            $antecedente->setTipo($row->tipo);
            $antecedente->setDescripcion($row->descripcion);
            
            $antecedentes[] = $antecedente;
        }
        
        return $antecedentes;
    }

    /**
     * Eliminar un antecedente familiar
     */
    public static function delete($db, $id) {
        try {
            $stmt = $db->prepare("DELETE FROM antecedentesfamiliares WHERE id = ?");
            return $stmt->execute([$id]);
        } catch (PDOException $e) {
            throw new Exception("Error al eliminar antecedente familiar: " . $e->getMessage());
        }
    }

    /**
     * Eliminar todos los antecedentes de una anamnesis
     */
    public static function deleteByAnamnesis($db, $anamnesis_id) {
        try {
            $stmt = $db->prepare("DELETE FROM antecedentesfamiliares WHERE anamnesis_id = ?");
            return $stmt->execute([$anamnesis_id]);
        } catch (PDOException $e) {
            throw new Exception("Error al eliminar antecedentes: " . $e->getMessage());
        }
    }

    /**
     * Obtener todos los tipos disponibles
     */
    public static function getTipos() {
        return self::TIPOS;
    }
}
?>
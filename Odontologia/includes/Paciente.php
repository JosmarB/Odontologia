<?php
class Paciente {
    private $id;
    private $cedula;
    private $es_representante;
    private $nombre;
    private $edad;
    private $telefono;
    private $sexo;
    private $estado_civil;
    private $ocupacion;
    private $representante_id;
    private $representante;
   public $usuario_asignado_id;
    public $usuario_asignado_nombre;
    
    const SEXO_CHOICES = [
        'M' => 'Masculino',
        'F' => 'Femenino',
        'O' => 'Otro'
    ];
    
    const ESTADO_CIVIL_CHOICES = [
        true => 'Casado/a',
        false => 'Soltero/a'
    ];

    public static function searchPaginated($db, $search_param, $limit, $offset) {
        $query = "SELECT * FROM Paciente WHERE nombre LIKE :search OR cedula LIKE :search LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':search', $search_param, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }

    public static function allPaginated($db, $es_paciente, $limit, $offset) {
        $query = "SELECT * FROM Paciente WHERE es_paciente = :es_paciente LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':es_paciente', $es_paciente, PDO::PARAM_BOOL);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_CLASS, self::class);
    }

    public function getEstadoCivilNombre() {
        return self::ESTADO_CIVIL_CHOICES[(bool)$this->estado_civil] ?? 'Desconocido';
    }

    public function getId() { return $this->id; }
    public function setId($id) { $this->id = $id; }
    public function getCedula() { return $this->cedula; }
    public function setCedula($cedula) { $this->cedula = $cedula; }
    public function getEsRepresentante() { return $this->es_representante; }
    public function setEsRepresentante($es_representante) { $this->es_representante = $es_representante; }
    public function getNombre() { return $this->nombre; }
    public function setNombre($nombre) { $this->nombre = $nombre; }
    public function getEdad() { return $this->edad; }
    public function setEdad($edad) { $this->edad = $edad; }
    public function getTelefono() { return $this->telefono; }
    public function setTelefono($telefono) { $this->telefono = $telefono; }
    public function getSexo() { return $this->sexo; }
    public function setSexo($sexo) { 
        if (!array_key_exists($sexo, self::SEXO_CHOICES)) {
            throw new Exception("Sexo no válido");
        }
        $this->sexo = $sexo; 
    }
    public function getEstadoCivil() { return $this->estado_civil; }
    public function setEstadoCivil($estado_civil) { $this->estado_civil = $estado_civil; }
    public function getOcupacion() { return $this->ocupacion; }
    public function setOcupacion($ocupacion) { $this->ocupacion = $ocupacion; }
    public function getRepresentanteId() { return $this->representante_id; }
    public function setRepresentanteId($representante_id) { $this->representante_id = $representante_id; }
    public function getRepresentante() { return $this->representante; }
    public function setRepresentante($representante) { $this->representante = $representante; }
    public function getUsuarioAsignadoId() { return $this->usuario_asignado_id; }
    public function setUsuarioAsignadoId($usuario_asignado_id) { $this->usuario_asignado_id = $usuario_asignado_id; }
    public function getUsuarioAsignadoNombre() { return $this->usuario_asignado_nombre; }
    public function setUsuarioAsignadoNombre($usuario_asignado_nombre) { $this->usuario_asignado_nombre = $usuario_asignado_nombre; }

    public function __toString() {
        return $this->nombre;
    }

    public function save($db) {
        if ($this->id) {
            $stmt = $db->prepare("UPDATE Paciente SET cedula=?, es_representante=?, nombre=?, edad=?, telefono=?, sexo=?, estado_civil=?, ocupacion=?, representante_id=?, usuario_asignado_id=? WHERE id=?");
            return $stmt->execute([
                $this->cedula,
                $this->es_representante,
                $this->nombre,
                $this->edad,
                $this->telefono,
                $this->sexo,
                $this->estado_civil,
                $this->ocupacion,
                $this->representante_id,
                $this->usuario_asignado_id,
                $this->id
            ]);
        } else {
            $stmt = $db->prepare("INSERT INTO Paciente (cedula, es_representante, nombre, edad, telefono, sexo, estado_civil, ocupacion, representante_id, usuario_asignado_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([
                $this->cedula,
                $this->es_representante,
                $this->nombre,
                $this->edad,
                $this->telefono,
                $this->sexo,
                $this->estado_civil,
                $this->ocupacion,
                $this->representante_id,
                $this->usuario_asignado_id
            ]);
            if ($result) $this->id = $db->lastInsertId();
            return $result;
        }
    }

    public static function find($db, $id) {
        $stmt = $db->prepare("SELECT * FROM Paciente WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) return null;

        $paciente = new Paciente();
        $paciente->setId($row->id);
        $paciente->setCedula($row->cedula);
        $paciente->setEsRepresentante($row->es_representante);
        $paciente->setNombre($row->nombre);
        $paciente->setEdad($row->edad);
        $paciente->setTelefono($row->telefono);
        $paciente->setSexo($row->sexo);
        $paciente->setEstadoCivil($row->estado_civil);
        $paciente->setOcupacion($row->ocupacion);
        $paciente->setRepresentanteId($row->representante_id);
        $paciente->setUsuarioAsignadoId($row->usuario_asignado_id);
        $paciente->setUsuarioAsignadoNombre($row->usuario_asignado_nombre);

        if ($row->representante_id) {
            $representante = self::find($db, $row->representante_id);
            $paciente->setRepresentante($representante);
        }

        return $paciente;
    }

    public static function all($db, $es_representante = null) {
        $sql = "SELECT * FROM Paciente";
        $params = [];
        
        if ($es_representante !== null) {
            $sql .= " WHERE es_representante = ?";
            $params[] = $es_representante;
        }
        
        $sql .= " ORDER BY nombre ASC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        $pacientes = [];
        while ($row = $stmt->fetch()) {
            $paciente = new Paciente();
            $paciente->setId($row->id);
            $paciente->setCedula($row->cedula);
            $paciente->setEsRepresentante($row->es_representante);
            $paciente->setNombre($row->nombre);
            $paciente->setEdad($row->edad);
            $paciente->setTelefono($row->telefono);
            $paciente->setSexo($row->sexo);
            $paciente->setEstadoCivil($row->estado_civil);
            $paciente->setOcupacion($row->ocupacion);
            $paciente->setRepresentanteId($row->representante_id);
            $paciente->setUsuarioAsignadoId($row->usuario_asignado_id);
            $paciente->setUsuarioAsignadoNombre($row->usuario_asignado_nombre);
            
            $pacientes[] = $paciente;
        }
        
        return $pacientes;
    }

    public function delete($db, $id) {
        $sql = "UPDATE Paciente SET Estado_Sistema = 'Inactivo' WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        return $stmt->execute();
    }
    
    public function savep($db) {
        if ($this->id) {
            $stmt = $db->prepare("UPDATE Paciente SET cedula=?, nombre=?, edad=?, telefono=?, sexo=?, estado_civil=?, ocupacion=? WHERE id=?");
            return $stmt->execute([
                $this->cedula,
                $this->nombre,
                $this->edad,
                $this->telefono,
                $this->sexo,
                $this->estado_civil,
                $this->ocupacion ?? '',
                $this->id
            ]);
        } else {
            $stmt = $db->prepare("INSERT INTO Paciente (cedula, nombre, edad, telefono, sexo, estado_civil, ocupacion) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([
                $this->cedula,
                $this->nombre,
                $this->edad,
                $this->telefono,
                $this->sexo,
                $this->estado_civil,
                $this->ocupacion ?? ''
            ]);
            if ($result) {
                $this->id = $db->lastInsertId();
            }
            return $result;
        }
    }
}
?>
<?php

class Usuario {
    private $id;
    private $email;
    private $nombre;
    private $password;
    private $rol_type;

    public function __construct($id = null, $email = null, $nombre = null, $password = null, $rol_type = null) {
        $this->id = $id;
        $this->email = $email;
        $this->nombre = $nombre;
        $this->password = $password;
        $this->rol_type = $rol_type;
    }

    public function getId() {
        return $this->id;
    }
    public function setId($id) {
        $this->id = $id;
    }

    public function getEmail() {
        return $this->email;
    }
    public function setEmail($email) {
        $this->email = $email;
    }

    public function getNombre() {
        return $this->nombre;
    }
    public function setNombre($nombre) {
        $this->nombre = $nombre;
    }

    public function getPassword() {
        return $this->password;
    }
    public function setPassword($password) {
        $this->password = $password;
    }

    public function getRolType() {
        return $this->rol_type;
    }
    public function setRolType($rol_type) {
        $this->rol_type = $rol_type;
    }

    public function save($db) {
        $stmt = $db->prepare("UPDATE usuario SET 
            email = ?, nombre = ?, password = ?, rol_type = ?
            WHERE id = ?");
        return $stmt->execute([
            $this->email,
            $this->nombre,
            $this->password,
            $this->rol_type,
            $this->id
        ]);
    }

    public static function findByEmail($db, $email) {
        $stmt = $db->prepare("SELECT * FROM usuario WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
    
}

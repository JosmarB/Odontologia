<?php
class Rol {
    private $id;
    private $es_admin;

    public function __construct($es_admin = false) {
        $this->es_admin = $es_admin;
    }

    public function getId() { return $this->id; }
    public function getEsAdmin() { return $this->es_admin; }
    public function setEsAdmin($es_admin) { $this->es_admin = $es_admin; }

    public static function getAdminRole($db) {
        $stmt = $db->prepare("SELECT * FROM rol WHERE es_admin = TRUE LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_OBJ);
    }

    public static function getUserRole($db) {
        $stmt = $db->prepare("SELECT * FROM rol WHERE es_admin = FALSE LIMIT 1");
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_OBJ);
    }
}
?>
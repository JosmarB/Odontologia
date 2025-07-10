<?php
require_once __DIR__ . '/Usuario.php';
require_once __DIR__ . '/Database.php';

class UsuarioManager {
    
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function emailExists($email) {
        $stmt = $this->db->prepare("SELECT id FROM usuario WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetchColumn() !== false;
    }

    public function createUser($email, $nombre, $password = null) {
        if (empty($email)) {
            throw new Exception('El usuario debe tener un correo electrónico.');
        }

        $usuario = new Usuario();
        $usuario->setEmail(strtolower(trim($email)));
        $usuario->setNombre(trim($nombre));

        if ($password) {
            $usuario->setPassword(password_hash($password, PASSWORD_DEFAULT));
        }

        // Asignar rol por defecto como 'U' (usuario común)
        $usuario->setRolType('U');

        $stmt = $this->db->prepare("INSERT INTO usuario (email, nombre, password, rol_type) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $usuario->getEmail(),
            $usuario->getNombre(),
            $usuario->getPassword(),
            $usuario->getRolType()
        ]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Error al crear el usuario.');
        }

        $usuario->setId($this->db->lastInsertId());
        return $usuario;
    }

    public function createSuperUser($email, $nombre, $password) {
        $usuario = new Usuario();
        $usuario->setEmail(strtolower(trim($email)));
        $usuario->setNombre(trim($nombre));
        $usuario->setPassword(password_hash($password, PASSWORD_DEFAULT));
        $usuario->setRolType('A'); // Superusuario tiene rol tipo 'A'

        $stmt = $this->db->prepare("INSERT INTO usuario (email, nombre, password, rol_type) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $usuario->getEmail(),
            $usuario->getNombre(),
            $usuario->getPassword(),
            $usuario->getRolType()
        ]);

        if ($stmt->rowCount() === 0) {
            throw new Exception('Error al crear el superusuario.');
        }

        $usuario->setId($this->db->lastInsertId());
        return $usuario;
    }

    public function authenticate($email, $password) {
        $query = "SELECT * FROM usuario WHERE email = ?";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(1, $email);
        $stmt->execute();
        
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            // Verificar si el usuario está activo
            if ($user['Estado_Sistema'] !== 'Activo') {
                throw new Exception("Tu cuenta está inactiva. Por favor, contacta al administrador.");
            }
            
            // Crear y retornar el objeto Usuario con los campos básicos
            $usuario = new Usuario();
            $usuario->setId($user['id']);
            $usuario->setEmail($user['email']);
            $usuario->setNombre($user['nombre']);
            $usuario->setPassword($user['password']);
            $usuario->setRolType($user['rol_type']);
            
            return $usuario;
        }
        
        return false;
    }

    /**
     * Registra el acceso de un usuario en el sistema
     * 
     * @param int $usuarioId ID del usuario
     * @return bool True si se registró correctamente
     */
    public function registrarAcceso($usuarioId) {
        try {
            // Actualizar último acceso en la tabla usuario
            $stmt = $this->db->prepare("UPDATE usuario SET ultimo_acceso = NOW() WHERE id = ?");
            $stmt->execute([$usuarioId]);
            
            // Registrar en tabla de logs de acceso (si existe)
            if ($this->db->query("SHOW TABLES LIKE 'accesos_log'")->rowCount() > 0) {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Desconocido';
                
                $stmtLog = $this->db->prepare(
                    "INSERT INTO accesos_log (usuario_id, fecha_acceso, ip, user_agent) 
                     VALUES (?, NOW(), ?, ?)"
                );
                $stmtLog->execute([$usuarioId, $ip, $userAgent]);
            }
            
            return true;
        } catch (PDOException $e) {
            // En entorno de producción, deberías registrar este error en logs
            error_log("Error al registrar acceso: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene los datos básicos de un usuario por su ID
     * 
     * @param int $id ID del usuario
     * @return Usuario|null Objeto Usuario o null si no se encuentra
     */
    public function getUserById($id) {
        $stmt = $this->db->prepare("SELECT * FROM usuario WHERE id = ?");
        $stmt->execute([$id]);
        
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $usuario = new Usuario();
            $usuario->setId($user['id']);
            $usuario->setEmail($user['email']);
            $usuario->setNombre($user['nombre']);
            $usuario->setRolType($user['rol_type']);
            return $usuario;
        }
        
        return null;
    }
}
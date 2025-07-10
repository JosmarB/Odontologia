<?php
$host = 'localhost';
$dbname = 'odontologia'; 
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Crear usuario administrador base si no existe
    $stmt = $conn->prepare("SELECT id FROM usuario WHERE email = :email");
    $stmt->execute([':email' => 'jemcosmeticandnatural@gmail.com']);

    if ($stmt->rowCount() === 0) {
        $passwordHash = password_hash("admin123", PASSWORD_DEFAULT);

        $insert = $conn->prepare("
            INSERT INTO usuario (email, nombre, password, rol_type)
            VALUES (:email, :nombre, :password, :rol_type)
        ");
        $insert->execute([
            ':email'     => 'jemcosmeticandnatural@gmail.com',
            ':nombre'    => 'Administrador Base',
            ':password'  => $passwordHash,
            ':rol_type'  => 'A' // ✅ Nuevo sistema de roles: A = Administrador
        ]);
    }

} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>

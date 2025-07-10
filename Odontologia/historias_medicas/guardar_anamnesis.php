<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';

$database = new Database();
$conn = $database->getConnection();

if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $historia_id = $_POST['historia_id'];

    // Insertar en Anamnesis
    $query = "INSERT INTO Anamnesis (historia_medica_id, diabetes, tbc, hipertension) VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->execute([
        $historia_id,
        isset($_POST['diabetes']) ? 1 : 0,
        isset($_POST['tbc']) ? 1 : 0,
        isset($_POST['hipertension']) ? 1 : 0
    ]);

    $anamnesis_id = $conn->lastInsertId();

    // Insertar en Hábitos
    if (!empty(trim($_POST['habito']))) {
        $query = "INSERT INTO Habitos (anamnesis_id, descripcion) VALUES (?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->execute([$anamnesis_id, trim($_POST['habito'])]);
    }

    // Insertar en AntecedentesFamiliares
    if (!empty(trim($_POST['descripcion']))) {
        $query = "INSERT INTO AntecedentesFamiliares (anamnesis_id, tipo, descripcion) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->execute([
            $anamnesis_id,
            $_POST['tipo'] ?? 'P', // valor por defecto si no llega
            trim($_POST['descripcion'])
        ]);
    }

    header("Location: ../historias_medicas/view.php");
    exit;
}
?>

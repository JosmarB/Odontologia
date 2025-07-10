<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';
$_SESSION['crear_historia_ok'] = true;

$database = new Database();
$conn = $database->getConnection();

if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$usuario_actual = (object) $_SESSION['usuario'];
$usuario_id = $usuario_actual->id;

$query = "SELECT * FROM Paciente";
$stmt = $conn->query($query);
$pacientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $paciente_id = $_POST['paciente_id'];

    $query = "INSERT INTO Historia_Medica (paciente_id, examinador_id) VALUES (?, ?)";
    $stmt = $conn->prepare($query);

    if ($stmt->execute([$paciente_id, $usuario_id])) {
        $historia_medica_id = $conn->lastInsertId();

        // Marcar permiso para acceder a anamnesis
        $_SESSION['crear_historia_ok'] = true;

        header("Location: llenar_anamnesis.php?historia_id=" . $historia_medica_id);
        exit;
    } else {
        $_SESSION['error_message'] = "Error al crear historia médica.";
        header('Location: index.php');
        exit;
    }
}
?>

<?php 
$page_title = "Crear Historia Médica"; 
include '../templates/navbar.php';
include '../templates/header.php'; 
?>

<div class="container mt-5">
    <h1>Crear Nueva Historia Médica</h1>

    <form method="POST">
        <div class="mb-3">
            <label for="paciente_id" class="form-label">Selecciona un Paciente:</label>
            <select name="paciente_id" class="form-select" required>
                <option value="">-- Selecciona --</option>
                <?php foreach ($pacientes as $paciente): ?>
                    <option value="<?= $paciente['id']; ?>">
                        <?= htmlspecialchars($paciente['nombre']) . " - " . htmlspecialchars($paciente['cedula']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Crear Historia Médica</button>
    </form>
</div>

<?php include '../templates/footer.php'; ?>

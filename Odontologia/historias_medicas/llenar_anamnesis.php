<?php
session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/Database.php';

// Verificar login
if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$usuario_actual = (object) $_SESSION['usuario'];

// Verificar ID recibido y acceso válido
if (!isset($_GET['historia_id']) || !isset($_SESSION['crear_historia_ok'])) {
    header("Location: ../historias_medicas/view.php?permiso_denegado=1");
    exit;
}

$historia_id = intval($_GET['historia_id']);
unset($_SESSION['crear_historia_ok']); // evitar acceso directo posterior

$database = new Database();
$conn = $database->getConnection();
?>

<?php 
$page_title = "Llenar Anamnesis";
include '../templates/navbar.php'; 
include '../templates/header.php';
?>

<div class="container mt-5">
    <h1>Llenar Anamnesis, Hábitos y Antecedentes</h1>

    <form method="POST" action="guardar_anamnesis.php">
        <input type="hidden" name="historia_id" value="<?= $historia_id ?>">

        <h4 class="mt-4">Anamnesis</h4>
        <?php
        $condiciones = [
            'diabetes' => 'Diabetes',
            'tbc' => 'Tuberculosis',
            'hipertension' => 'Hipertensión',
            'artritis' => 'Artritis',
            'alergias' => 'Alergias',
            'neuralgias' => 'Neuralgias',
            'hemorragias' => 'Hemorragias',
            'hepatitis' => 'Hepatitis',
            'sinusitis' => 'Sinusitis',
            'trastorno_mentales' => 'Trastornos Mentales',
            'enfermedades_eruptivas' => 'Enfermedades Eruptivas',
            'enfermedades_renales' => 'Enfermedades Renales',
            'parotiditis' => 'Parotiditis'
        ];

        foreach ($condiciones as $campo => $label): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="<?= $campo ?>" id="<?= $campo ?>">
                <label class="form-check-label" for="<?= $campo ?>"><?= $label ?></label>
            </div>
        <?php endforeach; ?>

        <h4 class="mt-4">Hábitos</h4>
        <textarea name="habito" class="form-control mb-3" rows="2" placeholder="Descripción del hábito..."></textarea>

        <h4>Antecedentes Familiares</h4>
        <div class="mb-2">
            <label for="tipo" class="form-label">Tipo:</label>
            <select name="tipo" class="form-select">
                <option value="P">Paterno</option>
                <option value="M">Materno</option>
                <option value="A">Ambos</option>
            </select>
        </div>
        <textarea name="descripcion" class="form-control" rows="3" placeholder="Descripción de antecedentes..."></textarea>

        <button type="submit" class="btn btn-success mt-4">Guardar</button>
    </form>
</div>

<?php include '../templates/footer.php'; ?>

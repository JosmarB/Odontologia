<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/Database.php';

if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$database = new Database();
$conn = $database->getConnection();

try {
    $conn->beginTransaction();
    
    // Validar datos requeridos
    if (!isset($_POST['historia_id']) || !isset($_POST['anamnesis_id'])) {
        throw new Exception("Datos incompletos");
    }

    $historia_id = $_POST['historia_id'];
    $anamnesis_id = $_POST['anamnesis_id'];

    // 1. Actualizar anamnesis
    $campos_anamnesis = [
        'diabetes', 'tbc', 'hipertension', 'artritis', 'alergias', 
        'neuralgias', 'hemorragias', 'hepatitis', 'sinusitis', 
        'trastorno_mentales', 'enfermedades_eruptivas', 'enfermedades_renales', 'parotiditis'
    ];

    $datos_anamnesis = [];
    foreach ($campos_anamnesis as $campo) {
        $datos_anamnesis[$campo] = isset($_POST['anamnesis'][$campo]) ? 1 : 0;
    }

    $update_anamnesis = "UPDATE Anamnesis SET 
        diabetes = :diabetes, tbc = :tbc, hipertension = :hipertension, 
        artritis = :artritis, alergias = :alergias, neuralgias = :neuralgias, 
        hemorragias = :hemorragias, hepatitis = :hepatitis, sinusitis = :sinusitis, 
        trastorno_mentales = :trastorno_mentales, enfermedades_eruptivas = :enfermedades_eruptivas, 
        enfermedades_renales = :enfermedades_renales, parotiditis = :parotiditis 
        WHERE id = :id";

    $stmt_anamnesis = $conn->prepare($update_anamnesis);
    $datos_anamnesis['id'] = $anamnesis_id;
    $stmt_anamnesis->execute($datos_anamnesis);

    // 2. Manejar antecedentes familiares
    if (isset($_POST['antecedentes'])) {
        foreach ($_POST['antecedentes'] as $antecedente) {
            if (isset($antecedente['id']) && $antecedente['id'] != '') {
                // Actualizar antecedente existente
                $stmt_af = $conn->prepare("UPDATE AntecedentesFamiliares 
                                         SET tipo = :tipo, descripcion = :descripcion 
                                         WHERE id = :id AND anamnesis_id = :anamnesis_id");
                $stmt_af->execute([
                    ':tipo' => $antecedente['tipo'],
                    ':descripcion' => $antecedente['descripcion'],
                    ':id' => $antecedente['id'],
                    ':anamnesis_id' => $anamnesis_id
                ]);
            } else {
                // Insertar nuevo antecedente
                $stmt_af = $conn->prepare("INSERT INTO AntecedentesFamiliares 
                                         (anamnesis_id, tipo, descripcion) 
                                         VALUES (:anamnesis_id, :tipo, :descripcion)");
                $stmt_af->execute([
                    ':anamnesis_id' => $anamnesis_id,
                    ':tipo' => $antecedente['tipo'],
                    ':descripcion' => $antecedente['descripcion']
                ]);
            }
        }
    }

    // 3. Manejar hábitos
    if (isset($_POST['habitos'])) {
        foreach ($_POST['habitos'] as $habito) {
            if (isset($habito['id']) && $habito['id'] != '') {
                // Actualizar hábito existente
                $stmt_hab = $conn->prepare("UPDATE Habitos 
                                          SET descripcion = :descripcion 
                                          WHERE id = :id AND anamnesis_id = :anamnesis_id");
                $stmt_hab->execute([
                    ':descripcion' => $habito['descripcion'],
                    ':id' => $habito['id'],
                    ':anamnesis_id' => $anamnesis_id
                ]);
            } else {
                // Insertar nuevo hábito
                $stmt_hab = $conn->prepare("INSERT INTO Habitos 
                                          (anamnesis_id, descripcion) 
                                          VALUES (:anamnesis_id, :descripcion)");
                $stmt_hab->execute([
                    ':anamnesis_id' => $anamnesis_id,
                    ':descripcion' => $habito['descripcion']
                ]);
            }
        }
    }

    $conn->commit();
    
    // Establecer mensaje de éxito y redireccionar
    $_SESSION['success_message'] = 'Historia médica actualizada correctamente';
    header("Location: ../historias_medicas/view.php");
    exit;

} catch (PDOException $e) {
    $conn->rollBack();
    error_log("Error al actualizar historia médica: " . $e->getMessage());
    $_SESSION['error_message'] = 'Error en el servidor al actualizar la historia médica';
    header("Location: ../historias_medicas/view.php");
    exit;
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['error_message'] = $e->getMessage();
    header("Location: ../historias_medicas/view.php");
    exit;
}
?>
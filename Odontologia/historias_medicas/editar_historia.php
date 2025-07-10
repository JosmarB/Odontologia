<?php
// editar_historia.php

require_once '../config/database.php';

// Obtener el ID de la historia
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('ID de historia médica no proporcionado.');
}

$id = $_GET['id'];

// Consultar datos de Anamnesis
$stmt = $conn->prepare("SELECT * FROM Anamnesis WHERE historia_medica_id = ?");
$stmt->execute([$id]);
$anamnesis = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$anamnesis) {
    die('No se encontró la historia médica.');
}

// Consultar antecedentes familiares
$stmt_af = $conn->prepare("SELECT * FROM AntecedentesFamiliares WHERE anamnesis_id = ?");
$stmt_af->execute([$anamnesis['id']]);
$antecedentes = $stmt_af->fetchAll(PDO::FETCH_ASSOC);

// Consultar hábitos
$stmt_habitos = $conn->prepare("SELECT * FROM Habitos WHERE anamnesis_id = ?");
$stmt_habitos->execute([$anamnesis['id']]);
$habitos = $stmt_habitos->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Editar Historia Médica</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container mt-5">
    <div class="card shadow-lg p-4">
        <h2 class="mb-4 text-center">Editar Historia Médica</h2>
        <form action="actualizar_historia.php" method="POST">
            <input type="hidden" name="historia_id" value="<?php echo htmlspecialchars($id); ?>">
            <input type="hidden" name="anamnesis_id" value="<?php echo htmlspecialchars($anamnesis['id']); ?>">

            <div class="row mb-3">
                <div class="col-md-12">
                    <h4>Anamnesis</h4>
                    <div class="row">
                        <?php 
                        $campos = [
                            'diabetes', 'tbc', 'hipertension', 'artritis', 'alergias', 'neuralgias',
                            'hemorragias', 'hepatitis', 'sinusitis', 'trastorno_mentales',
                            'enfermedades_eruptivas', 'enfermedades_renales', 'parotiditis'
                        ];
                        foreach ($campos as $campo) { ?>
                            <div class="col-md-4 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="<?php echo $campo; ?>" id="<?php echo $campo; ?>" <?php echo ($anamnesis[$campo] ? 'checked' : ''); ?>>
                                    <label class="form-check-label" for="<?php echo $campo; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $campo)); ?>
                                    </label>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <hr>

            <div class="row mb-3">
                <div class="col-md-12">
                    <h4>Antecedentes Familiares</h4>
                    <?php foreach ($antecedentes as $index => $af) { ?>
                        <div class="mb-3">
                            <label for="tipo_<?php echo $index; ?>" class="form-label">Tipo:</label>
                            <select name="antecedentes[<?php echo $index; ?>][tipo]" id="tipo_<?php echo $index; ?>" class="form-select" required>
                                <option value="P" <?php echo ($af['tipo'] == 'P') ? 'selected' : ''; ?>>Paterno</option>
                                <option value="M" <?php echo ($af['tipo'] == 'M') ? 'selected' : ''; ?>>Materno</option>
                                <option value="A" <?php echo ($af['tipo'] == 'A') ? 'selected' : ''; ?>>Ambos</option>
                            </select>
                            <label for="descripcion_<?php echo $index; ?>" class="form-label mt-2">Descripción:</label>
                            <textarea name="antecedentes[<?php echo $index; ?>][descripcion]" id="descripcion_<?php echo $index; ?>" class="form-control" rows="2" required><?php echo htmlspecialchars($af['descripcion']); ?></textarea>
                            <input type="hidden" name="antecedentes[<?php echo $index; ?>][id]" value="<?php echo $af['id']; ?>">
                        </div>
                    <?php } ?>
                </div>
            </div>

            <hr>

            <div class="row mb-4">
                <div class="col-md-12">
                    <h4>Hábitos</h4>
                    <?php foreach ($habitos as $index => $habito) { ?>
                        <div class="mb-3">
                            <label for="habito_<?php echo $index; ?>" class="form-label">Descripción:</label>
                            <textarea name="habitos[<?php echo $index; ?>][descripcion]" id="habito_<?php echo $index; ?>" class="form-control" rows="2" required><?php echo htmlspecialchars($habito['descripcion']); ?></textarea>
                            <input type="hidden" name="habitos[<?php echo $index; ?>][id]" value="<?php echo $habito['id']; ?>">
                        </div>
                    <?php } ?>
                </div>
            </div>

            <div class="text-center">
                <button type="submit" class="btn btn-primary">Guardar Cambios</button>
                <a href="listar_historias.php" class="btn btn-secondary">Cancelar</a>
            </div>

        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

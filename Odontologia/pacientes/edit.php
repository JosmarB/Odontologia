<?php
$page_title = "Editar Paciente";
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../includes/Paciente.php';
require_once __DIR__ . '/../templates/navbar.php'; // o tu archivo de autenticación
if (!$usuario_actual || !$usuario_actual->is_admin) {
    header("Location: ./index.php?permiso_denegado=1");
    exit;
}

$database = new Database();
$db = $database->getConnection();

$id = $_GET['id'] ?? null;
if (!$id) {
    header("Location: index.php");
    exit();
}

$paciente = Paciente::find($db, $id);
if (!$paciente) {
    header("Location: index.php");
    exit();
}

$representantes = Paciente::all($db, true); // Solo representantes

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paciente->setCedula($_POST['cedula']);
    $paciente->setNombre($_POST['nombre']);
    $paciente->setEdad($_POST['edad']);
    $paciente->setTelefono($_POST['telefono']);
    $paciente->setSexo($_POST['sexo']);
    $paciente->setEstadoCivil(isset($_POST['estado_civil']));
    $paciente->setOcupacion($_POST['ocupacion']);
    $paciente->setRepresentanteId($_POST['representante_id'] ?: null);
    
    if ($paciente->save($db)) {
        $_SESSION['success_message'] = "Paciente actualizado exitosamente";
        header("Location: view.php?id=" . $paciente->getId());
        exit();
    } else {
        $error = "Error al actualizar el paciente";
    }
}
?>

<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Editar Paciente</h4>
            </div>
            <div class="card-body">
                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= $error ?></div>
                <?php endif; ?>
                
                <form method="post" class="needs-validation" novalidate>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="cedula" class="form-label required-field">Cédula</label>
                                <input type="text" class="form-control" id="cedula" name="cedula" 
                                    value="<?= htmlspecialchars($paciente->getCedula()) ?>" required>
                                <div class="invalid-feedback">
                                    Por favor ingrese la cédula
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="nombre" class="form-label required-field">Nombre Completo</label>
                                <input type="text" class="form-control" id="nombre" name="nombre" 
                                    value="<?= htmlspecialchars($paciente->getNombre()) ?>" required>
                                <div class="invalid-feedback">
                                    Por favor ingrese el nombre
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="edad" class="form-label required-field">Edad</label>
                                <input type="number" class="form-control" id="edad" name="edad" 
                                    value="<?= $paciente->getEdad() ?>" min="0" required>
                                <div class="invalid-feedback">
                                    Por favor ingrese una edad válida
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="telefono" class="form-label required-field">Teléfono</label>
                                <input type="text" class="form-control" id="telefono" name="telefono" 
                                    value="<?= htmlspecialchars($paciente->getTelefono()) ?>" required>
                                <div class="invalid-feedback">
                                    Por favor ingrese el teléfono
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="sexo" class="form-label required-field">Sexo</label>
                                <select class="form-select" id="sexo" name="sexo" required>
                                    <option value="">Seleccione...</option>
                                    <option value="M" <?= $paciente->getSexo() === 'M' ? 'selected' : '' ?>>Masculino</option>
                                    <option value="F" <?= $paciente->getSexo() === 'F' ? 'selected' : '' ?>>Femenino</option>
                                    <option value="O" <?= $paciente->getSexo() === 'O' ? 'selected' : '' ?>>Otro</option>
                                </select>
                                <div class="invalid-feedback">
                                    Por favor seleccione el sexo
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="estado_civil" name="estado_civil"
                                        <?= $paciente->getEstadoCivil() ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="estado_civil">Casado/a</label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="ocupacion" class="form-label required-field">Ocupación</label>
                                <input type="text" class="form-control" id="ocupacion" name="ocupacion" 
                                    value="<?= htmlspecialchars($paciente->getOcupacion()) ?>" required>
                                <div class="invalid-feedback">
                                    Por favor ingrese la ocupación
                                </div>
                            </div>
                            <div class="mb-3" id="representante-group" 
                                style="display: <?= $paciente->getEdad() < 18 ? 'block' : 'none' ?>;">
                                <label for="representante_id" class="form-label">Representante</label>
                                <select class="form-select" id="representante_id" name="representante_id">
                                    <option value="">Seleccione un representante...</option>
                                    <?php foreach ($representantes as $rep): ?>
                                    <option value="<?= $rep->getId() ?>" 
                                        <?= $paciente->getRepresentanteId() == $rep->getId() ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($rep->getNombre()) ?> (<?= $rep->getCedula() ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary">Actualizar</button>
                        <a href="view.php?id=<?= $paciente->getId() ?>" class="btn btn-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Mostrar/ocultar representante según edad
document.getElementById('edad').addEventListener('change', function() {
    const representanteGroup = document.getElementById('representante-group');
    representanteGroup.style.display = this.value < 18 ? 'block' : 'none';
});
</script>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
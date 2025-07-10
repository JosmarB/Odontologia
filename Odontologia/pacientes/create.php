<?php
$page_title = "Nuevo Paciente";
require_once __DIR__ . '/../templates/header.php';
require_once __DIR__ . '/../includes/Paciente.php';
require_once __DIR__ . '/../templates/navbar.php'; // o tu archivo de autenticación
if (!$usuario_actual || !$usuario_actual->is_admin) {
    header("Location: ./index.php?permiso_denegado=1");
    exit;
}
$database = new Database();
$db = $database->getConnection();

$representantes = Paciente::all($db, true); 

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paciente = new Paciente();
    $paciente->setCedula($_POST['cedula']);
    $paciente->setEsRepresentante(false);
    $paciente->setNombre($_POST['nombre']);
    $paciente->setEdad($_POST['edad']);
    $paciente->setTelefono($_POST['telefono']);
    $paciente->setSexo($_POST['sexo']);
    $paciente->setEstadoCivil(isset($_POST['estado_civil']));
    $paciente->setOcupacion($_POST['ocupacion']);
    $paciente->setRepresentanteId($_POST['representante_id'] ?: null);
    
    if ($paciente->save($db)) {
        $_SESSION['success_message'] = "Paciente creado exitosamente";
        header("Location: index.php");
        exit();
    } else {
        $error = "Error al guardar el paciente";
    }
}
?>

<div class="row">
    <div class="col-lg-12">
        <div class="card">
            <div class="card-header">
                <h4 class="card-title">Registrar Nuevo Paciente</h4>
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
                                <input type="text" class="form-control" id="cedula" name="cedula" required>
                                <div class="invalid-feedback">
                                    Por favor ingrese la cédula
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="nombre" class="form-label required-field">Nombre Completo</label>
                                <input type="text" class="form-control" id="nombre" name="nombre" required>
                                <div class="invalid-feedback">
                                    Por favor ingrese el nombre
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="edad" class="form-label required-field">Edad</label>
                                <input type="number" class="form-control" id="edad" name="edad" min="0" required>
                                <div class="invalid-feedback">
                                    Por favor ingrese una edad válida
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="telefono" class="form-label required-field">Teléfono</label>
                                <input type="text" class="form-control" id="telefono" name="telefono" required>
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
                                    <option value="M">Masculino</option>
                                    <option value="F">Femenino</option>
                                    <option value="O">Otro</option>
                                </select>
                                <div class="invalid-feedback">
                                    Por favor seleccione el sexo
                                </div>
                            </div>
                            <div class="mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="estado_civil" name="estado_civil">
                                    <label class="form-check-label" for="estado_civil">Casado/a</label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="ocupacion" class="form-label required-field">Ocupación</label>
                                <input type="text" class="form-control" id="ocupacion" name="ocupacion" required>
                                <div class="invalid-feedback">
                                    Por favor ingrese la ocupación
                                </div>
                            </div>
                            <div class="mb-3" id="representante-group" style="display: none;">
                                <label for="representante_id" class="form-label">Representante</label>
                                <select class="form-select" id="representante_id" name="representante_id">
                                    <option value="">Seleccione un representante...</option>
                                    <?php foreach ($representantes as $rep): ?>
                                    <option value="<?= $rep->getId() ?>">
                                        <?= htmlspecialchars($rep->getNombre()) ?> (<?= $rep->getCedula() ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                        <button type="submit" class="btn btn-primary">Guardar</button>
                        <a href="index.php" class="btn btn-secondary">Cancelar</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
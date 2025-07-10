<?php
session_start();
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Usuario.php';

$database = new Database();
$conn = $database->getConnection();

if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$query = "
SELECT h.id AS historia_id, p.nombre, p.cedula, p.edad, p.telefono
FROM Historia_Medica h
JOIN Paciente p ON h.paciente_id = p.id
WHERE p.nombre LIKE :search OR p.cedula LIKE :search
ORDER BY p.nombre ASC
";

$stmt = $conn->prepare($query);
$stmt->execute(['search' => "%$search%"]);
$historias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = "Historias Médicas";
include '../templates/header.php';
?>

<div class="container mt-4">
    <h1>Listado de Historias Médicas</h1>

    <form method="GET" class="mb-3">
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por nombre o cédula" class="form-control">
    </form>

    <table class="table table-bordered">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Cédula</th>
                <th>Edad</th>
                <th>Teléfono</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($historias as $historia): ?>
            <tr>
                <td><?= htmlspecialchars($historia['nombre']) ?></td>
                <td><?= htmlspecialchars($historia['cedula']) ?></td>
                <td><?= htmlspecialchars($historia['edad']) ?></td>
                <td><?= htmlspecialchars($historia['telefono']) ?></td>
                <td>
                    <button class="btn btn-info btn-sm" onclick="verHistoria(<?= $historia['historia_id'] ?>)">Ver</button>
                    <a href="editar_historia.php?id=<?= $historia['historia_id'] ?>" class="btn btn-warning btn-sm">Editar</a>
                    <a href="eliminar_historia.php?id=<?= $historia['historia_id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('¿Seguro que deseas eliminar?');">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Modal exclusivo del index -->
<div class="modal fade" id="verHistoriaModal" tabindex="-1" aria-labelledby="verHistoriaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="verHistoriaModalLabel">Detalles de la Historia Médica</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body" id="contenidoHistoria">
        <p class="text-center text-muted">Cargando...</p>
      </div>
    </div>
  </div>
</div>

<script>
function verHistoria(id) {
    const modal = new bootstrap.Modal(document.getElementById('verHistoriaModal'));
    document.getElementById('contenidoHistoria').innerHTML = '<p class="text-center text-muted">Cargando...</p>';

    fetch('cargar_historia_ajax.php?id=' + id)
        .then(response => response.text())
        .then(html => {
            document.getElementById('contenidoHistoria').innerHTML = html;
            modal.show();
        })
        .catch(err => {
            document.getElementById('contenidoHistoria').innerHTML = '<p class="text-danger">Error al cargar la historia.</p>';
            console.error(err);
        });
}
</script>

<?php include '../templates/footer.php'; ?>

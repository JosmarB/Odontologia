<?php
require_once '../config/database.php';

if (isset($_GET['id'])) {
    $historia_id = $_GET['id'];

    $query = "
        SELECT 
            h.id AS historia_id,
            p.nombre AS paciente_nombre,
            p.cedula AS paciente_cedula,
            p.edad AS paciente_edad,
            p.telefono AS paciente_telefono,
            p.sexo AS paciente_sexo,
            p.estado_civil AS paciente_estado_civil,
            p.ocupacion AS paciente_ocupacion,
            u.nombre AS examinador_nombre,
            h.fecha_creacion
        FROM Historia_Medica h
        INNER JOIN Paciente p ON h.paciente_id = p.id
        INNER JOIN Usuario u ON h.examinador_id = u.id
        WHERE h.id = :id
    ";

    $stmt = $conn->prepare($query);
    $stmt->execute(['id' => $historia_id]);
    $historia = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$historia) {
        echo "<div class='alert alert-danger'>Historia médica no encontrada.</div>";
        exit;
    }

    $queryAnamnesis = "SELECT * FROM Anamnesis WHERE historia_medica_id = :id";
    $stmtAnamnesis = $conn->prepare($queryAnamnesis);
    $stmtAnamnesis->execute(['id' => $historia_id]);
    $anamnesis = $stmtAnamnesis->fetch(PDO::FETCH_ASSOC);

    if ($anamnesis) {
        $anamnesis_id = $anamnesis['id'];

        $queryAntecedentes = "SELECT * FROM AntecedentesFamiliares WHERE anamnesis_id = :anamnesis_id";
        $stmtAntecedentes = $conn->prepare($queryAntecedentes);
        $stmtAntecedentes->execute(['anamnesis_id' => $anamnesis_id]);
        $antecedentes = $stmtAntecedentes->fetchAll(PDO::FETCH_ASSOC);

        $queryHabitos = "SELECT * FROM Habitos WHERE anamnesis_id = :anamnesis_id";
        $stmtHabitos = $conn->prepare($queryHabitos);
        $stmtHabitos->execute(['anamnesis_id' => $anamnesis_id]);
        $habitos = $stmtHabitos->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<style>
/* Estilos generales */
.modal-content {
    border-radius: 16px;
    background: #f0f2f5;
    box-shadow: 0 12px 30px rgba(0,0,0,0.2);
    animation: zoomIn 0.4s ease;
    overflow: hidden;
}

@keyframes zoomIn {
    from { transform: scale(0.9); opacity: 0; }
    to { transform: scale(1); opacity: 1; }
}

.custom-modal-header {
    background: linear-gradient(135deg, #4f46e5, #6366f1);
    color: white;
    padding: 1.5rem;
    text-align: center;
}

.custom-modal-header h5 {
    font-size: 1.8rem;
    margin: 0;
}

.custom-modal-body {
    padding: 2rem;
    max-height: 80vh;
    overflow-y: auto;
}

/* Cards internas */
.card-section {
    background: white;
    border-radius: 12px;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    transition: all 0.3s;
}

.card-section:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
}

/* Títulos bonitos */
.section-title {
    font-size: 1.4rem;
    font-weight: 600;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
}

.section-title i {
    margin-right: 0.6rem;
    color: #6366f1;
}

/* Footer */
.custom-modal-footer {
    background: #f9fafb;
    padding: 1rem 2rem;
    text-align: right;
}

.btn {
    border-radius: 8px;
    padding: 0.6rem 1.4rem;
    font-weight: bold;
    transition: 0.3s;
}

.btn-secondary {
    background: #6b7280;
    color: white;
}

.btn-secondary:hover {
    background: #4b5563;
}

/* Scroll */
.custom-modal-body::-webkit-scrollbar {
    width: 8px;
}
.custom-modal-body::-webkit-scrollbar-thumb {
    background: #cbd5e0;
    border-radius: 4px;
}
</style>

<div class="modal-content">
    <div class="custom-modal-header">
        <h5 class="modal-title">Historia Médica Odontológica</h5>
    </div>

    <div class="custom-modal-body">

        <!-- Paciente -->
        <div class="card-section">
            <div class="section-title"><i class="bi bi-person-badge"></i>Datos del Paciente</div>
            <div class="row">
                <div class="col-md-6"><strong>Nombre:</strong> <?= htmlspecialchars($historia['paciente_nombre']) ?></div>
                <div class="col-md-6"><strong>Cédula:</strong> <?= htmlspecialchars($historia['paciente_cedula']) ?></div>
                <div class="col-md-4"><strong>Edad:</strong> <?= htmlspecialchars($historia['paciente_edad']) ?> años</div>
                <div class="col-md-4"><strong>Teléfono:</strong> <?= htmlspecialchars($historia['paciente_telefono']) ?></div>
                <div class="col-md-4"><strong>Sexo:</strong> <?= $historia['paciente_sexo'] === 'M' ? 'Masculino' : 'Femenino' ?></div>
                <div class="col-md-6"><strong>Estado Civil:</strong> <?= $historia['paciente_estado_civil'] ? 'Casado(a)' : 'Soltero(a)' ?></div>
                <div class="col-md-6"><strong>Ocupación:</strong> <?= htmlspecialchars($historia['paciente_ocupacion']) ?></div>
            </div>
        </div>

        <!-- Anamnesis -->
        <?php if ($anamnesis): ?>
        <div class="card-section">
            <div class="section-title"><i class="bi bi-heart-pulse"></i>Anamnesis</div>
            <div class="row">
                <?php foreach ($anamnesis as $key => $value): ?>
                    <?php if (!in_array($key, ['id', 'historia_medica_id'])): ?>
                        <div class="col-md-6">
                            <strong><?= ucfirst(str_replace('_', ' ', $key)) ?>:</strong> <?= $value ? 'Sí' : 'No' ?>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Antecedentes -->
        <div class="card-section">
            <div class="section-title"><i class="bi bi-people-fill"></i>Antecedentes Familiares</div>
            <?php if (!empty($antecedentes)): ?>
            <ul class="list-group">
                <?php foreach ($antecedentes as $antecedente): ?>
                    <li class="list-group-item">
                        <strong><?= $antecedente['tipo'] === 'P' ? 'Paterno' : 'Materno' ?>:</strong> 
                        <?= nl2br(htmlspecialchars($antecedente['descripcion'])) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="text-muted">No hay antecedentes registrados.</p>
            <?php endif; ?>
        </div>

        <!-- Hábitos -->
        <div class="card-section">
            <div class="section-title"><i class="bi bi-emoji-smile"></i>Hábitos</div>
            <?php if (!empty($habitos)): ?>
            <ul class="list-group">
                <?php foreach ($habitos as $habito): ?>
                    <li class="list-group-item"><?= htmlspecialchars($habito['descripcion']) ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="text-muted">No hay hábitos registrados.</p>
            <?php endif; ?>
        </div>

        <!-- Examinador -->
        <div class="card-section">
            <div class="section-title"><i class="bi bi-person-workspace"></i>Datos del Examinador</div>
            <div class="row">
                <div class="col-md-6"><strong>Nombre:</strong> <?= htmlspecialchars($historia['examinador_nombre']) ?></div>
                <div class="col-md-6"><strong>Fecha de Creación:</strong> <?= date('d/m/Y H:i', strtotime($historia['fecha_creacion'])) ?></div>
            </div>
        </div>

    </div>

    <div class="custom-modal-footer">
        <button type="button" class="btn btn-secondary" onclick="cerrarModal()" href="../historias_medicas/view.php">Cerrar</button>
    </div>
</div>

<script>
function cerrarModal() {
    const modalBackdrop = document.querySelector('.modal-backdrop');
    if (modalBackdrop) modalBackdrop.remove();
    const modal = document.querySelector('.modal.show');
    if (modal) {
        modal.classList.remove('show');
        modal.style.display = 'none';
        document.body.classList.remove('modal-open');
    }
}
</script>

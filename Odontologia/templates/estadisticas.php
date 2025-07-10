<?php
session_start();
require_once __DIR__ . '/../config/Database.php';
include './navbar.php';

// Verificación de usuario y permisos
if (!isset($_SESSION['usuario'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['usuario']['rol_type'] !== 'A' 
&& $_SESSION['usuario']['rol_type'] !== 'O' 
&& $_SESSION['usuario']['rol_type'] !== 'S') {
    header("Location: /Odontologia/templates/unauthorized.php");
    exit;
}

// Conexión a la base de datos

// Obtener lista de doctores (odontólogos) solo para admin y secretaria
if ($_SESSION['usuario']['rol_type'] === 'A' || $_SESSION['usuario']['rol_type'] === 'S') {
    $doctores = $conn->query("SELECT id, nombre FROM usuario WHERE rol_type = 'O'")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $doctores = [];
}

// Parámetros de filtro
$filtro_doctor = $_GET['doctor'] ?? '';
$filtro_mes = $_GET['mes'] ?? '';

// Si es odontólogo, forzar el filtro de su propio ID
if ($_SESSION['usuario']['rol_type'] === 'O') {
    $filtro_doctor = $_SESSION['usuario']['id'];
}

// Obtener datos estadísticos generales (solo para admin y secretaria)
function getGeneralStats($conn, $rol_type) {
    if ($rol_type !== 'A' && $rol_type !== 'S') {
        return null;
    }

    $stats = [];
    
    $stats['pacientes'] = $conn->query("
        SELECT 
            COUNT(*) as total,
            SUM(edad BETWEEN 0 AND 18) as menores,
            SUM(edad BETWEEN 19 AND 65) as adultos,
            SUM(edad > 65) as mayores,
            SUM(sexo = 'M') as masculino,
            SUM(sexo = 'F') as femenino,
            SUM(sexo = 'O') as otro_genero
        FROM paciente
    ")->fetch(PDO::FETCH_ASSOC);

    $stats['citas_mes'] = $conn->query("
        SELECT 
            DATE_FORMAT(fecha, '%Y-%m') as mes,
            COUNT(*) as total,
            SUM(estado = 'Completado') as completadas,
            SUM(estado = 'Cancelado') as canceladas,
            SUM(estado = 'Pendiente') as pendientes
        FROM citas
        GROUP BY mes
        ORDER BY mes DESC
        LIMIT 12
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats['procedimientos'] = $conn->query("
        SELECT tratamiento as procedimiento, COUNT(*) as cantidad
        FROM citas
        WHERE tratamiento IS NOT NULL AND tratamiento != ''
        GROUP BY tratamiento
        ORDER BY cantidad DESC
        LIMIT 5
    ")->fetchAll(PDO::FETCH_ASSOC);

    $stats['enfermedades'] = $conn->query("
        SELECT 
            SUM(diabetes) as diabetes,
            SUM(hipertension) as hipertension,
            SUM(alergias) as alergias,
            SUM(hepatitis) as hepatitis,
            SUM(enfermedades_renales) as enfermedades_renales
        FROM anamnesis
    ")->fetch(PDO::FETCH_ASSOC);

    return $stats;
}

// Obtener estadísticas por doctor
function getDoctorStats($conn, $doctor_id = '', $rol_type = '') {
    $where = $doctor_id ? "AND u.id = $doctor_id" : '';
    
    // Si es odontólogo, solo puede ver sus propias estadísticas
    if ($rol_type === 'O' && empty($doctor_id)) {
        return [];
    }

    return $conn->query("
        SELECT 
            u.id as doctor_id,
            u.nombre as doctor,
            COUNT(c.id) as total_citas,
            SUM(c.estado = 'Completado') as completadas,
            SUM(c.estado = 'Cancelado') as canceladas,
            SUM(c.estado = 'Pendiente') as pendientes,
            AVG(TIMESTAMPDIFF(MINUTE, c.hora, ADDTIME(c.hora, SEC_TO_TIME(c.duracion*60)))) as duracion_promedio,
            COUNT(DISTINCT c.paciente_id) as pacientes_unicos
        FROM usuario u
        LEFT JOIN citas c ON u.id = c.usuario_id
        WHERE u.rol_type = 'O' $where
        GROUP BY u.id
        ORDER BY total_citas DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$generalStats = getGeneralStats($conn, $_SESSION['usuario']['rol_type']);
$doctorStats = getDoctorStats($conn, $filtro_doctor, $_SESSION['usuario']['rol_type']);
$selectedDoctor = $filtro_doctor ? array_filter($doctorStats, fn($d) => $d['doctor_id'] == $filtro_doctor)[0] : null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Estadísticas del Consultorio</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        .chart-container {
            position: relative;
            height: 300px;
            margin-bottom: 2rem;
        }
        .stat-card {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s;
            height: 100%;
        }
        .stat-card:hover {
            transform: translateY(-5px);
        }
        .stat-icon {
            font-size: 2rem;
            opacity: 0.7;
        }
        .filter-section {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .nav-pills .nav-link.active {
            background-color: #0d6efd;
        }
        .tab-content {
            padding: 20px 0;
        }
        .doctor-card {
            border-left: 5px solid #0d6efd;
        }
        .badge-stat {
            font-size: 1rem;
        }
    </style>
</head>
<body>
    <div class="container py-5">
        <div class="row mb-4">
            <div class="col-12">
                <br>
                <h1 class="text-center mb-4"><i class="bi bi-bar-chart-line"></i> Panel de Estadísticas</h1>
                
                <!-- Pestañas -->
                <ul class="nav nav-pills mb-4" id="myTab" role="tablist">
                    <?php if ($_SESSION['usuario']['rol_type'] === 'A' || $_SESSION['usuario']['rol_type'] === 'S'): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link <?= ($_SESSION['usuario']['rol_type'] !== 'O') ? 'active' : '' ?>" id="general-tab" data-bs-toggle="pill" data-bs-target="#general" type="button" role="tab">
                                <i class="bi bi-house-door"></i> Estadísticas Generales
                            </button>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?= ($_SESSION['usuario']['rol_type'] === 'O') ? 'active' : '' ?>" id="doctors-tab" data-bs-toggle="pill" data-bs-target="#doctors" type="button" role="tab">
                            <i class="bi bi-people"></i> <?= ($_SESSION['usuario']['rol_type'] === 'O') ? 'Mis Estadísticas' : 'Por Doctor' ?>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Contenido de las pestañas -->
        <div class="tab-content" id="myTabContent">
            <!-- Pestaña de Estadísticas Generales (solo para admin y secretaria) -->
            <?php if ($_SESSION['usuario']['rol_type'] === 'A' || $_SESSION['usuario']['rol_type'] === 'S'): ?>
                <div class="tab-pane fade <?= ($_SESSION['usuario']['rol_type'] !== 'O') ? 'show active' : '' ?>" id="general" role="tabpanel">
                    <div class="row" id="generalContent">
                        <div class="col-12 mb-4">
                            <div class="card shadow">
                                <div class="card-header bg-primary text-white">
                                    <h3 class="h5 mb-0"><i class="bi bi-clipboard-data"></i> Resumen General</h3>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <div class="stat-card p-3 bg-primary bg-opacity-10">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <h5 class="mb-0">Total Pacientes</h5>
                                                        <h2 class="mb-0"><?= $generalStats['pacientes']['total'] ?></h2>
                                                    </div>
                                                    <i class="bi bi-people stat-icon text-primary"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="stat-card p-3 bg-success bg-opacity-10">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <h5 class="mb-0">Citas Totales</h5>
                                                        <h2 class="mb-0"><?= array_sum(array_column($generalStats['citas_mes'], 'total')) ?></h2>
                                                    </div>
                                                    <i class="bi bi-calendar-check stat-icon text-success"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="stat-card p-3 bg-warning bg-opacity-10">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <h5 class="mb-0">Citas Completadas</h5>
                                                        <h2 class="mb-0"><?= array_sum(array_column($generalStats['citas_mes'], 'completadas')) ?></h2>
                                                    </div>
                                                    <i class="bi bi-check-circle stat-icon text-warning"></i>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="stat-card p-3 bg-danger bg-opacity-10">
                                                <div class="d-flex justify-content-between">
                                                    <div>
                                                        <h5 class="mb-0">Citas Canceladas</h5>
                                                        <h2 class="mb-0"><?= array_sum(array_column($generalStats['citas_mes'], 'canceladas')) ?></h2>
                                                    </div>
                                                    <i class="bi bi-x-circle stat-icon text-danger"></i>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header bg-info text-white">
                                    <h3 class="h5 mb-0"><i class="bi bi-calendar3"></i> Citas por Mes</h3>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="citasMesChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header bg-info text-white">
                                    <h3 class="h5 mb-0"><i class="bi bi-people"></i> Distribución de Pacientes</h3>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="chart-container">
                                                <canvas id="pacientesEdadChart"></canvas>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="chart-container">
                                                <canvas id="pacientesGeneroChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header bg-info text-white">
                                    <h3 class="h5 mb-0"><i class="bi bi-clipboard-pulse"></i> Procedimientos Comunes</h3>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="procedimientosChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-4">
                            <div class="card shadow h-100">
                                <div class="card-header bg-info text-white">
                                    <h3 class="h5 mb-0"><i class="bi bi-heart-pulse"></i> Enfermedades Comunes</h3>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container">
                                        <canvas id="enfermedadesChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Pestaña de Estadísticas por Doctor -->
            <div class="tab-pane fade <?= ($_SESSION['usuario']['rol_type'] === 'O') ? 'show active' : '' ?>" id="doctors" role="tabpanel">
                <div class="row" id="doctorsContent">
                    <?php if ($_SESSION['usuario']['rol_type'] === 'O' || ($filtro_doctor && $selectedDoctor)): ?>
                        <!-- Vista detallada del doctor (o del odontólogo logueado) -->
                        <div class="col-12 mb-4">
                            <div class="card shadow doctor-card">
                                <div class="card-header bg-primary text-white">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <h3 class="h5 mb-0"><i class="bi bi-person-badge"></i> Dr. <?= htmlspecialchars($selectedDoctor['doctor'] ?? $_SESSION['usuario']['nombre']) ?></h3>
                                        <?php if ($_SESSION['usuario']['rol_type'] !== 'O'): ?>
                                            <a href="estadisticas.php?tab=doctors" class="btn btn-sm btn-light">
                                                <i class="bi bi-arrow-left"></i> Volver a todos
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-3 mb-3">
                                            <div class="stat-card p-3 bg-primary bg-opacity-10">
                                                <h5 class="mb-1">Total Citas</h5>
                                                <h2 class="mb-0"><?= $selectedDoctor['total_citas'] ?></h2>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="stat-card p-3 bg-success bg-opacity-10">
                                                <h5 class="mb-1">Completadas</h5>
                                                <h2 class="mb-0"><?= $selectedDoctor['completadas'] ?></h2>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="stat-card p-3 bg-warning bg-opacity-10">
                                                <h5 class="mb-1">Pendientes</h5>
                                                <h2 class="mb-0"><?= $selectedDoctor['pendientes'] ?></h2>
                                            </div>
                                        </div>
                                        <div class="col-md-3 mb-3">
                                            <div class="stat-card p-3 bg-danger bg-opacity-10">
                                                <h5 class="mb-1">Canceladas</h5>
                                                <h2 class="mb-0"><?= $selectedDoctor['canceladas'] ?></h2>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-3">
                                        <div class="col-md-6">
                                            <div class="card shadow-sm h-100">
                                                <div class="card-body">
                                                    <h5 class="card-title"><i class="bi bi-graph-up"></i> Rendimiento</h5>
                                                    <ul class="list-group list-group-flush">
                                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                                            Duración promedio de cita
                                                            <span class="badge bg-primary rounded-pill badge-stat">
                                                                <?= round($selectedDoctor['duracion_promedio'], 1) ?> min
                                                            </span>
                                                        </li>
                                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                                            Pacientes únicos atendidos
                                                            <span class="badge bg-primary rounded-pill badge-stat">
                                                                <?= $selectedDoctor['pacientes_unicos'] ?>
                                                            </span>
                                                        </li>
                                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                                            Tasa de completación
                                                            <span class="badge bg-primary rounded-pill badge-stat">
                                                                <?= $selectedDoctor['total_citas'] > 0 ? round(($selectedDoctor['completadas'] / $selectedDoctor['total_citas']) * 100, 1) : 0 ?>%
                                                            </span>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card shadow-sm h-100">
                                                <div class="card-body">
                                                    <h5 class="card-title"><i class="bi bi-pie-chart"></i> Distribución de Citas</h5>
                                                    <div class="chart-container">
                                                        <canvas id="doctorCitasChart"></canvas>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Listado de todos los doctores (solo para admin y secretaria) -->
                        <div class="col-12 mb-4">
                            <div class="card shadow">
                                <div class="card-header bg-primary text-white">
                                    <h3 class="h5 mb-0"><i class="bi bi-people-fill"></i> Estadísticas por Doctor</h3>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-striped table-hover">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Doctor</th>
                                                    <th>Total Citas</th>
                                                    <th>Completadas</th>
                                                    <th>Pendientes</th>
                                                    <th>Canceladas</th>
                                                    <th>Pacientes Únicos</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($doctorStats as $doctor): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($doctor['doctor']) ?></td>
                                                        <td><?= $doctor['total_citas'] ?></td>
                                                        <td><?= $doctor['completadas'] ?></td>
                                                        <td><?= $doctor['pendientes'] ?></td>
                                                        <td><?= $doctor['canceladas'] ?></td>
                                                        <td><?= $doctor['pacientes_unicos'] ?></td>
                                                        <td>
                                                            <a href="?doctor=<?= $doctor['doctor_id'] ?>&tab=doctors" class="btn btn-sm btn-outline-primary">
                                                                <i class="bi bi-zoom-in"></i> Detalles
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-12">
                            <div class="card shadow">
                                <div class="card-header bg-primary text-white">
                                    <h3 class="h5 mb-0"><i class="bi bi-bar-chart"></i> Comparativa entre Doctores</h3>
                                </div>
                                <div class="card-body">
                                    <div class="chart-container" style="height: 400px;">
                                        <canvas id="comparativaDoctoresChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Datos para los gráficos generales (solo si es admin o secretaria)
        const generalData = {
            citasMes: {
                labels: <?= ($generalStats) ? json_encode(array_map(function($mes) {
                    return DateTime::createFromFormat('Y-m-d', $mes . '-01')->format('F Y');
                }, array_column($generalStats['citas_mes'], 'mes'))) : '[]' ?>,
                completadas: <?= ($generalStats) ? json_encode(array_column($generalStats['citas_mes'], 'completadas')) : '[]' ?>,
                canceladas: <?= ($generalStats) ? json_encode(array_column($generalStats['citas_mes'], 'canceladas')) : '[]' ?>,
                pendientes: <?= ($generalStats) ? json_encode(array_column($generalStats['citas_mes'], 'pendientes')) : '[]' ?>
            },
            pacientes: {
                edad: {
                    labels: ['Menores (0-18)', 'Adultos (19-65)', 'Mayores (65+)'],
                    data: [
                        <?= ($generalStats) ? $generalStats['pacientes']['menores'] : 0 ?>,
                        <?= ($generalStats) ? $generalStats['pacientes']['adultos'] : 0 ?>,
                        <?= ($generalStats) ? $generalStats['pacientes']['mayores'] : 0 ?>
                    ]
                },
                genero: {
                    labels: ['Masculino', 'Femenino', 'Otro'],
                    data: [
                        <?= ($generalStats) ? $generalStats['pacientes']['masculino'] : 0 ?>,
                        <?= ($generalStats) ? $generalStats['pacientes']['femenino'] : 0 ?>,
                        <?= ($generalStats) ? $generalStats['pacientes']['otro_genero'] : 0 ?>
                    ]
                }
            },
            procedimientos: {
                labels: <?= ($generalStats) ? json_encode(array_column($generalStats['procedimientos'], 'procedimiento')) : '[]' ?>,
                data: <?= ($generalStats) ? json_encode(array_column($generalStats['procedimientos'], 'cantidad')) : '[]' ?>
            },
            enfermedades: {
                labels: ['Diabetes', 'Hipertensión', 'Alergias', 'Hepatitis', 'Enf. Renales'],
                data: [
                    <?= ($generalStats) ? $generalStats['enfermedades']['diabetes'] : 0 ?>,
                    <?= ($generalStats) ? $generalStats['enfermedades']['hipertension'] : 0 ?>,
                    <?= ($generalStats) ? $generalStats['enfermedades']['alergias'] : 0 ?>,
                    <?= ($generalStats) ? $generalStats['enfermedades']['hepatitis'] : 0 ?>,
                    <?= ($generalStats) ? $generalStats['enfermedades']['enfermedades_renales'] : 0 ?>
                ]
            }
        };

        // Datos para los gráficos de doctores
        const doctorsData = {
            comparativa: {
                labels: <?= json_encode(array_column($doctorStats, 'doctor')) ?>,
                total: <?= json_encode(array_column($doctorStats, 'total_citas')) ?>,
                completadas: <?= json_encode(array_column($doctorStats, 'completadas')) ?>,
                canceladas: <?= json_encode(array_column($doctorStats, 'canceladas')) ?>
            },
            doctor: <?= ($filtro_doctor && $selectedDoctor) ? json_encode([
                'labels' => ['Completadas', 'Pendientes', 'Canceladas'],
                'data' => [
                    $selectedDoctor['completadas'],
                    $selectedDoctor['pendientes'],
                    $selectedDoctor['canceladas']
                ]
            ]) : 'null' ?>
        };

        // Gráfico de citas por mes (solo para admin y secretaria)
        if (document.getElementById('citasMesChart') && generalData.citasMes.labels.length > 0) {
            new Chart(
                document.getElementById('citasMesChart'),
                {
                    type: 'bar',
                    data: {
                        labels: generalData.citasMes.labels,
                        datasets: [
                            {
                                label: 'Completadas',
                                data: generalData.citasMes.completadas,
                                backgroundColor: 'rgba(40, 167, 69, 0.7)',
                                borderColor: 'rgba(40, 167, 69, 1)',
                                borderWidth: 1
                            },
                            {
                                label: 'Pendientes',
                                data: generalData.citasMes.pendientes,
                                backgroundColor: 'rgba(255, 193, 7, 0.7)',
                                borderColor: 'rgba(255, 193, 7, 1)',
                                borderWidth: 1
                            },
                            {
                                label: 'Canceladas',
                                data: generalData.citasMes.canceladas,
                                backgroundColor: 'rgba(220, 53, 69, 0.7)',
                                borderColor: 'rgba(220, 53, 69, 1)',
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Número de Citas'
                                }
                            },
                            x: {
                                title: {
                                    display: true,
                                    text: 'Mes'
                                }
                            }
                        }
                    }
                }
            );
        }

        // Gráfico de distribución de pacientes por edad (solo para admin y secretaria)
        if (document.getElementById('pacientesEdadChart') && <?= ($generalStats) ? 'true' : 'false' ?>) {
            new Chart(
                document.getElementById('pacientesEdadChart'),
                {
                    type: 'doughnut',
                    data: {
                        labels: generalData.pacientes.edad.labels,
                        datasets: [{
                            data: generalData.pacientes.edad.data,
                            backgroundColor: [
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(255, 159, 64, 0.7)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            title: {
                                display: true,
                                text: 'Por Edad'
                            }
                        }
                    }
                }
            );
        }

        // Gráfico de distribución de pacientes por género (solo para admin y secretaria)
        if (document.getElementById('pacientesGeneroChart') && <?= ($generalStats) ? 'true' : 'false' ?>) {
            new Chart(
                document.getElementById('pacientesGeneroChart'),
                {
                    type: 'pie',
                    data: {
                        labels: generalData.pacientes.genero.labels,
                        datasets: [{
                            data: generalData.pacientes.genero.data,
                            backgroundColor: [
                                'rgba(54, 162, 235, 0.7)',
                                'rgba(255, 99, 132, 0.7)',
                                'rgba(153, 102, 255, 0.7)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            title: {
                                display: true,
                                text: 'Por Género'
                            }
                        }
                    }
                }
            );
        }

        // Gráfico de procedimientos comunes (solo para admin y secretaria)
        if (document.getElementById('procedimientosChart') && generalData.procedimientos.labels.length > 0) {
            new Chart(
                document.getElementById('procedimientosChart'),
                {
                    type: 'bar',
                    data: {
                        labels: generalData.procedimientos.labels,
                        datasets: [{
                            label: 'Veces realizado',
                            data: generalData.procedimientos.data,
                            backgroundColor: 'rgba(108, 117, 125, 0.7)',
                            borderColor: 'rgba(108, 117, 125, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        scales: {
                            x: {
                                beginAtZero: true
                            }
                        }
                    }
                }
            );
        }

        // Gráfico de enfermedades comunes (solo para admin y secretaria)
        if (document.getElementById('enfermedadesChart') && <?= ($generalStats) ? 'true' : 'false' ?>) {
            new Chart(
                document.getElementById('enfermedadesChart'),
                {
                    type: 'radar',
                    data: {
                        labels: generalData.enfermedades.labels,
                        datasets: [{
                            label: 'Casos registrados',
                            data: generalData.enfermedades.data,
                            backgroundColor: 'rgba(13, 110, 253, 0.2)',
                            borderColor: 'rgba(13, 110, 253, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            r: {
                                beginAtZero: true
                            }
                        }
                    }
                }
            );
        }

        // Gráfico de comparativa entre doctores (solo para admin y secretaria)
        if (document.getElementById('comparativaDoctoresChart') && doctorsData.comparativa.labels.length > 0) {
            new Chart(
                document.getElementById('comparativaDoctoresChart'),
                {
                    type: 'bar',
                    data: {
                        labels: doctorsData.comparativa.labels,
                        datasets: [
                            {
                                label: 'Total Citas',
                                data: doctorsData.comparativa.total,
                                backgroundColor: 'rgba(13, 110, 253, 0.7)'
                            },
                            {
                                label: 'Citas Completadas',
                                data: doctorsData.comparativa.completadas,
                                backgroundColor: 'rgba(25, 135, 84, 0.7)'
                            },
                            {
                                label: 'Citas Canceladas',
                                data: doctorsData.comparativa.canceladas,
                                backgroundColor: 'rgba(220, 53, 69, 0.7)'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: {
                                beginAtZero: true
                            }
                        }
                    }
                }
            );
        }

        // Gráfico de citas por doctor (detalle)
        if (document.getElementById('doctorCitasChart') && doctorsData.doctor) {
            new Chart(
                document.getElementById('doctorCitasChart'),
                {
                    type: 'doughnut',
                    data: {
                        labels: doctorsData.doctor.labels,
                        datasets: [{
                            data: doctorsData.doctor.data,
                            backgroundColor: [
                                'rgba(25, 135, 84, 0.7)',
                                'rgba(255, 193, 7, 0.7)',
                                'rgba(220, 53, 69, 0.7)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true
                    }
                }
            );
        }

        // Exportar a PDF
        function exportToPDF(section) {
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'pt', 'a4');
            const content = document.getElementById(section + 'Content');
            
            doc.setFontSize(18);
            doc.text('Reporte de Estadísticas - ' + (section === 'general' ? 'Generales' : 'Por Doctor'), 40, 40);
            
            doc.setFontSize(12);
            let filtros = 'Filtros aplicados: ';
            filtros += document.getElementById('doctor') ? 'Doctor: ' + document.getElementById('doctor').options[document.getElementById('doctor').selectedIndex].text + ' ' : '';
            filtros += document.getElementById('mes') ? 'Mes: ' + document.getElementById('mes').value : 'Todos los meses';
            doc.text(filtros, 40, 70);
            
            const fecha = new Date().toLocaleString();
            doc.text(`Generado el: ${fecha}`, 40, 90);
            
            html2canvas(content, {
                scale: 2,
                logging: true,
                useCORS: true
            }).then(canvas => {
                const imgData = canvas.toDataURL('image/png');
                const imgWidth = doc.internal.pageSize.getWidth() - 80;
                const pageHeight = doc.internal.pageSize.getHeight();
                const imgHeight = canvas.height * imgWidth / canvas.width;
                let heightLeft = imgHeight;
                let position = 120;

                doc.addImage(imgData, 'PNG', 40, position, imgWidth, imgHeight);
                heightLeft -= pageHeight;

                while (heightLeft >= 0) {
                    position = heightLeft - imgHeight;
                    doc.addPage();
                    doc.addImage(imgData, 'PNG', 40, position, imgWidth, imgHeight);
                    heightLeft -= pageHeight;
                }

                doc.save('estadisticas_' + section + '.pdf');
            });
        }

        document.querySelectorAll('.export-pdf').forEach(btn => {
            btn.addEventListener('click', function() {
                const section = this.getAttribute('data-section');
                exportToPDF(section);
            });
        });
    });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
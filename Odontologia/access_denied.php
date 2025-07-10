<?php
require_once __DIR__ . '/config.php';

include __DIR__ . '/templates/header.php';
include __DIR__ . '/templates/navbar.php';
?>

<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h4><i class="fas fa-exclamation-triangle"></i> Acceso Denegado</h4>
                </div>
                <div class="card-body">
                    <div class="text-center mb-4">
                        <i class="fas fa-lock fa-5x text-danger"></i>
                    </div>
                    <h5 class="card-title text-center">No tienes permiso para acceder a esta función</h5>
                    <p class="card-text text-center">Por favor, contacta al administrador si necesitas acceder a esta sección.</p>
                    
                    <div class="d-flex justify-content-center mt-4">
                        <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary">
                            <i class="fas fa-home"></i> Volver al Inicio
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/templates/footer.php';
?>
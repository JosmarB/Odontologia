<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Verificar sesión ANTES de cualquier output
if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

// Configurar reporte de errores
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Inicializar variables
$mensaje = '';
$error = '';
$usuarioId = $_SESSION['usuario']['id'];

// Obtener datos del usuario
$stmt = $conn->prepare("SELECT * FROM usuario WHERE id = ?");
$stmt->execute([$usuarioId]);
$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

// Procesamiento del formulario antes de cualquier output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre'] ?? $usuario['nombre']);
    $email = trim($_POST['email'] ?? $usuario['email']);
    $password_actual = $_POST['password_actual'] ?? '';
    $password_nueva = $_POST['password_nueva'] ?? '';
    $password_confirmar = $_POST['password_confirmar'] ?? '';
    $nuevoPasswordHash = null;

    // Validar email único
    $stmt = $conn->prepare("SELECT id FROM usuario WHERE email = ? AND id != ?");
    $stmt->execute([$email, $usuarioId]);
    if ($stmt->fetch()) {
        $error = "Este correo ya está en uso por otro usuario.";
    }

    // Validar contraseña si se está cambiando
    if (!$error && $password_actual && $password_nueva) {
        if (!password_verify($password_actual, $usuario['password'])) {
            $error = "La contraseña actual no es correcta.";
        } elseif ($password_actual === $password_nueva) {
            $error = "La nueva contraseña no puede ser igual a la actual.";
        } elseif ($password_nueva !== $password_confirmar) {
            $error = "La nueva contraseña y la confirmación no coinciden.";
        } elseif (strlen($password_nueva) < 8) {
            $error = "La contraseña debe tener al menos 8 caracteres.";
        } else {
            $nuevoPasswordHash = password_hash($password_nueva, PASSWORD_DEFAULT);
        }
    }

    // Manejar carga de imagen
    $directorioImagenes = __DIR__ . '/imagenes_usuarios/';
    
    // Crear directorio si no existe
    if (!file_exists($directorioImagenes)) {
        if (!mkdir($directorioImagenes, 0775, true)) {
            $error = "No se pudo crear el directorio de imágenes.";
        }
    }

    $fotoPerfil = $usuario['foto'] ?? 'default.webp';

    if (!$error && (isset($_FILES['imagen']) || isset($_POST['editedImage']))) {
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $allowedMimes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];
        
        // Manejar imagen editada (prioridad)
        if (isset($_POST['editedImage']) && !empty($_POST['editedImage'])) {
            $imageData = $_POST['editedImage'];
            
            // Extraer la parte base64 de la cadena
            if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
                $imageData = substr($imageData, strpos($imageData, ',') + 1);
                $type = strtolower($type[1]); // jpg, png, gif
                
                if (!in_array($type, array_keys($allowedMimes))) {
                    $error = "Formato de imagen no permitido.";
                } else {
                    $imageData = base64_decode($imageData);
                    
                    if ($imageData === false) {
                        $error = "Error al decodificar la imagen.";
                    } else {
                        // Eliminar imágenes anteriores
                        foreach ($allowedMimes as $ext => $mime) {
                            $otroArchivo = $directorioImagenes . "perfil_$usuarioId.$ext";
                            if (file_exists($otroArchivo)) {
                                unlink($otroArchivo);
                            }
                        }
                        
                        // Guardar nueva imagen
                        $nombreImagen = "perfil_$usuarioId.$type";
                        $rutaImagen = $directorioImagenes . $nombreImagen;
                        
                        if (file_put_contents($rutaImagen, $imageData)) {
                            $fotoPerfil = $nombreImagen;
                        } else {
                            $error = "Error al guardar la imagen editada.";
                        }
                    }
                }
            } else {
                $error = "Formato de imagen no válido.";
            }
        }
        // Manejar subida de imagen normal
        elseif (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $tmpName = $_FILES['imagen']['tmp_name'];
            $mime = $fileInfo->file($tmpName);
            $ext = array_search($mime, $allowedMimes, true);
            
            if ($ext === false) {
                $error = "Formato de imagen no permitido. Use JPG, PNG, GIF o WEBP.";
            } else {
                // Eliminar imágenes anteriores
                foreach ($allowedMimes as $ext => $mime) {
                    $otroArchivo = $directorioImagenes . "perfil_$usuarioId.$ext";
                    if (file_exists($otroArchivo)) {
                        unlink($otroArchivo);
                    }
                }
                
                // Mover nueva imagen
                $nombreImagen = "perfil_$usuarioId.$ext";
                $rutaImagen = $directorioImagenes . $nombreImagen;
                
                if (move_uploaded_file($tmpName, $rutaImagen)) {
                    $fotoPerfil = $nombreImagen;
                } else {
                    $error = "Error al mover la imagen subida.";
                }
            }
        }
        
        // Actualizar en la base de datos si no hay errores
        if (!$error && isset($nombreImagen)) {
            $stmt = $conn->prepare("UPDATE usuario SET foto = ? WHERE id = ?");
            if (!$stmt->execute([$nombreImagen, $usuarioId])) {
                $error = "Error al guardar la imagen en la base de datos.";
            }
        }
    }

    // Actualizar datos si no hay errores
    if (!$error) {
        $sql = "UPDATE usuario SET nombre = ?, email = ?";
        $params = [$nombre, $email];

        if ($nuevoPasswordHash) {
            $sql .= ", password = ?";
            $params[] = $nuevoPasswordHash;
        }

        // Actualizar foto solo si se subió una nueva
        if (isset($nombreImagen)) {
            $sql .= ", foto = ?";
            $params[] = $nombreImagen;
        }

        $sql .= " WHERE id = ?";
        $params[] = $usuarioId;

        $stmt = $conn->prepare($sql);
        if ($stmt->execute($params)) {
            $_SESSION['usuario']['nombre'] = $nombre;
            $_SESSION['usuario']['email'] = $email;
            if (isset($nombreImagen)) {
                $_SESSION['usuario']['foto'] = $nombreImagen;
            }
            $mensaje = "Datos actualizados correctamente.";
        } else {
            $error = "Error al guardar los datos.";
        }
    }
}

// Obtener foto de perfil actual
$directorioImagenes = __DIR__ . '/imagenes_usuarios/';
$fotoPerfil = $usuario['foto'] ?? 'default.webp';

// Verificar si la imagen existe, si no usar default
if ($fotoPerfil !== 'default.webp') {
    $rutaImagen = $directorioImagenes . $fotoPerfil;
    if (!file_exists($rutaImagen)) {
        $fotoPerfil = 'default.webp';
    }
}

// Incluir navbar después de todo el procesamiento
include './templates/navbar.php';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mi Perfil - Odontología</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Añadir Cropper.js -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4e66f8;
            --secondary-color: #6c757d;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --border-radius: 12px;
            --box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background-color: #f5f7ff;
            color: #333;
        }
        
        .profile-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .profile-header {
            text-align: center;
            margin-bottom: 2rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 1.5rem;
        }
        
        .profile-title {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 1.5rem;
        }
        
        .profile-img-container {
            position: relative;
            display: inline-block;
            margin-bottom: 1rem;
        }
        
        .profile-img {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 5px solid white;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
        }
        
        .profile-img:hover {
            transform: scale(1.05);
        }
        
        .profile-upload-btn {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: var(--primary-color);
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }
        
        .profile-upload-btn:hover {
            background: #3a56f5;
            transform: scale(1.1);
        }
        
        .profile-info-list {
            list-style: none;
            padding: 0;
        }
        
        .profile-info-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
        }
        
        .profile-info-item:hover {
            background-color: #f9f9f9;
        }
        
        .profile-info-label {
            font-weight: 500;
            color: var(--dark-color);
        }
        
        .profile-info-value {
            color: var(--secondary-color);
        }
        
        .profile-edit-btn {
            color: var(--primary-color);
            background: none;
            border: none;
            font-size: 1.1rem;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .profile-edit-btn:hover {
            transform: scale(1.2);
            color: #3a56f5;
        }
        
        .alert {
            border-radius: var(--border-radius);
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .alert-success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-color);
            border-left: 4px solid var(--success-color);
        }
        
        .alert-danger {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--danger-color);
            border-left: 4px solid var(--danger-color);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background-color: #3a56f5;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(78, 102, 248, 0.3);
        }
        
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--box-shadow);
        }
        
        .modal-header {
            border-bottom: none;
            padding-bottom: 0;
        }
        
        .modal-title {
            font-weight: 600;
        }
        
        .form-control {
            border-radius: var(--border-radius);
            padding: 0.75rem 1rem;
            border: 1px solid #e0e0e0;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(78, 102, 248, 0.25);
        }
        
        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--secondary-color);
        }
        
        .password-input-container {
            position: relative;
            margin-bottom: 1rem;
        }
        
        /* Estilos para el editor de imágenes */
        .image-editor-modal .modal-dialog {
            max-width: 800px;
        }
        
        .image-editor-container {
            display: flex;
            flex-direction: column;
            height: 500px;
        }
        
        .image-preview-container {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #f5f5f5;
            margin-bottom: 1rem;
            overflow: hidden;
            position: relative;
        }
        
        #imagePreview {
            max-width: 100%;
            max-height: 100%;
        }
        
        .editor-tools {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .editor-tool-btn {
            background-color: var(--primary-color);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .editor-tool-btn:hover {
            background-color: #3a56f5;
        }
        
        .editor-tool-btn i {
            margin-right: 5px;
        }
        
        .slider-container {
            width: 100%;
            margin-bottom: 1rem;
        }
        
        .slider-container label {
            display: block;
            margin-bottom: 0.5rem;
        }
        
        .editor-actions {
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        
        @media (max-width: 768px) {
            .profile-container {
                padding: 1.5rem;
                margin: 1rem;
            }
            
            .profile-img {
                width: 120px;
                height: 120px;
            }
            
            .image-editor-container {
                height: 400px;
            }
            
            .editor-tools {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="profile-container">
        <div class="profile-header">
            <h2 class="profile-title">Mi Perfil</h2>
            
            <?php if ($mensaje): ?>
                <div class="alert alert-success"><?= htmlspecialchars($mensaje) ?></div>
            <?php elseif ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            
            <div class="profile-img-container">
                <img src="/Odontologia/imagenes_usuarios/<?= htmlspecialchars($fotoPerfil) ?>" 
                     class="profile-img" 
                     alt="Foto de perfil"
                     onerror="this.src='/Odontologia/imagenes_usuarios/default.webp'">
                
                <label for="imagen" class="profile-upload-btn" title="Cambiar foto">
                    <i class="fas fa-camera"></i>
                </label>
            </div>
            
            <h4 class="mt-3"><?= htmlspecialchars($usuario['nombre']) ?></h4>
            <p class="text-muted"><?= htmlspecialchars($usuario['email']) ?></p>
        </div>
        
        <ul class="profile-info-list">
            <li class="profile-info-item">
                <div>
                    <span class="profile-info-label">Nombre completo</span>
                    <div class="profile-info-value"><?= htmlspecialchars($usuario['nombre']) ?></div>
                </div>
                <button class="profile-edit-btn" data-bs-toggle="modal" data-bs-target="#modalEditarNombre">
                    <i class="fas fa-pen"></i>
                </button>
            </li>
            
            <li class="profile-info-item">
                <div>
                    <span class="profile-info-label">Correo electrónico</span>
                    <div class="profile-info-value"><?= htmlspecialchars($usuario['email']) ?></div>
                </div>
                <button class="profile-edit-btn" data-bs-toggle="modal" data-bs-target="#modalEditarCorreo">
                    <i class="fas fa-pen"></i>
                </button>
            </li>
            
            <li class="profile-info-item">
                <div>
                    <span class="profile-info-label">Contraseña</span>
                    <div class="profile-info-value">••••••••</div>
                </div>
                <button class="profile-edit-btn" data-bs-toggle="modal" data-bs-target="#modalEditarPassword">
                    <i class="fas fa-pen"></i>
                </button>
            </li>
        </ul>
    </div>
</div>

<!-- Modal Editar Nombre -->
<div class="modal fade" id="modalEditarNombre" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Editar nombre</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nombre" class="form-label">Nuevo nombre</label>
                        <input type="text" class="form-control" id="nombre" name="nombre" 
                               value="<?= htmlspecialchars($usuario['nombre']) ?>" required>
                    </div>
                    <input type="hidden" name="email" value="<?= htmlspecialchars($usuario['email']) ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Correo -->
<div class="modal fade" id="modalEditarCorreo" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Editar correo electrónico</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="email" class="form-label">Nuevo correo electrónico</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?= htmlspecialchars($usuario['email']) ?>" required>
                    </div>
                    <input type="hidden" name="nombre" value="<?= htmlspecialchars($usuario['nombre']) ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editar Contraseña -->
<div class="modal fade" id="modalEditarPassword" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">Cambiar contraseña</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="password-input-container">
                        <label for="password_actual" class="form-label">Contraseña actual</label>
                        <input type="password" class="form-control" id="password_actual" name="password_actual" required>
                        <i class="fas fa-eye password-toggle" onclick="togglePassword('password_actual')"></i>
                    </div>
                    
                    <div class="password-input-container">
                        <label for="password_nueva" class="form-label">Nueva contraseña</label>
                        <input type="password" class="form-control" id="password_nueva" name="password_nueva" required>
                        <i class="fas fa-eye password-toggle" onclick="togglePassword('password_nueva')"></i>
                        <small class="text-muted">Mínimo 8 caracteres</small>
                    </div>
                    
                    <div class="password-input-container">
                        <label for="password_confirmar" class="form-label">Confirmar nueva contraseña</label>
                        <input type="password" class="form-control" id="password_confirmar" name="password_confirmar" required>
                        <i class="fas fa-eye password-toggle" onclick="togglePassword('password_confirmar')"></i>
                    </div>
                    
                    <input type="hidden" name="nombre" value="<?= htmlspecialchars($usuario['nombre']) ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($usuario['email']) ?>">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Actualizar contraseña</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Editor de Imágenes -->
<div class="modal fade image-editor-modal" id="modalEditorImagen" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar imagen de perfil</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="image-editor-container">
                    <div class="image-preview-container">
                        <img id="imagePreview" src="" alt="Vista previa">
                    </div>
                    
                    <div class="editor-tools">
                        <button type="button" class="editor-tool-btn" id="rotateLeft">
                            <i class="fas fa-undo"></i> Rotar izquierda
                        </button>
                        <button type="button" class="editor-tool-btn" id="rotateRight">
                            <i class="fas fa-redo"></i> Rotar derecha
                        </button>
                        <button type="button" class="editor-tool-btn" id="flipHorizontal">
                            <i class="fas fa-arrows-alt-h"></i> Voltear horizontal
                        </button>
                        <button type="button" class="editor-tool-btn" id="flipVertical">
                            <i class="fas fa-arrows-alt-v"></i> Voltear vertical
                        </button>
                        <button type="button" class="editor-tool-btn" id="resetImage">
                            <i class="fas fa-sync-alt"></i> Restablecer
                        </button>
                    </div>
                    
        
                </div>
            </div>
            <div class="modal-footer editor-actions">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="saveImage">Guardar cambios</button>
            </div>
        </div>
    </div>
</div>

<!-- Formulario oculto para imagen -->
<form method="POST" enctype="multipart/form-data" id="imageForm" style="display: none;">
    <input type="file" id="imagen" name="imagen" accept="image/*">
    <input type="hidden" id="editedImage" name="editedImage">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Añadir Cropper.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
<script>
    // Variables globales para el editor
    let cropper;
    let originalImage;
    let currentRotation = 0;
    let isFlippedHorizontal = false;
    let isFlippedVertical = false;
    let currentBrightness = 100;
    let currentContrast = 100;
    
    // Función para mostrar/ocultar contraseña
    function togglePassword(id) {
        const input = document.getElementById(id);
        const icon = input.nextElementSibling;
        
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    
    // Mostrar modal si hay errores
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($error && isset($_POST['password_actual'])): ?>
            const modalPassword = new bootstrap.Modal(document.getElementById('modalEditarPassword'));
            modalPassword.show();
        <?php elseif ($error && isset($_POST['email'])): ?>
            const modalEmail = new bootstrap.Modal(document.getElementById('modalEditarCorreo'));
            modalEmail.show();
        <?php elseif ($error && isset($_POST['nombre'])): ?>
            const modalNombre = new bootstrap.Modal(document.getElementById('modalEditarNombre'));
            modalNombre.show();
        <?php endif; ?>
        
        // Configurar el botón de carga de imagen
        document.querySelector('.profile-upload-btn').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('imagen').click();
        });
        
        // Cuando se selecciona una imagen
        document.getElementById('imagen').addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const file = this.files[0];
                const reader = new FileReader();
                
                reader.onload = function(event) {
                    originalImage = event.target.result;
                    
                    // Mostrar el editor de imágenes
                    const editorModal = new bootstrap.Modal(document.getElementById('modalEditorImagen'));
                    const preview = document.getElementById('imagePreview');
                    
                    preview.src = originalImage;
                    editorModal.show();
                    
                    // Inicializar Cropper.js cuando el modal esté completamente visible
                    document.getElementById('modalEditorImagen').addEventListener('shown.bs.modal', function() {
                        if (cropper) {
                            cropper.destroy();
                        }
                        
                        cropper = new Cropper(preview, {
                            aspectRatio: 1,
                            viewMode: 1,
                            autoCropArea: 0.8,
                            responsive: true,
                            guides: false
                        });
                    }, {once: true});
                };
                
                reader.readAsDataURL(file);
            }
        });
        
        // Configurar botones del editor
        document.getElementById('rotateLeft').addEventListener('click', function() {
            currentRotation -= 90;
            cropper.rotate(-90);
        });
        
        document.getElementById('rotateRight').addEventListener('click', function() {
            currentRotation += 90;
            cropper.rotate(90);
        });
        
        document.getElementById('flipHorizontal').addEventListener('click', function() {
            isFlippedHorizontal = !isFlippedHorizontal;
            const scaleX = isFlippedHorizontal ? -1 : 1;
            const scaleY = isFlippedVertical ? -1 : 1;
            cropper.scale(scaleX, scaleY);
        });
        
        document.getElementById('flipVertical').addEventListener('click', function() {
            isFlippedVertical = !isFlippedVertical;
            const scaleX = isFlippedHorizontal ? -1 : 1;
            const scaleY = isFlippedVertical ? -1 : 1;
            cropper.scale(scaleX, scaleY);
        });
        

        // Configurar sliders

        // Función para aplicar filtros

        
        // Guardar imagen editada
        document.getElementById('saveImage').addEventListener('click', function() {
            // Obtener el canvas recortado
            const canvas = cropper.getCroppedCanvas({
                width: 500,
                height: 500,
                minWidth: 256,
                minHeight: 256,
                maxWidth: 1024,
                maxHeight: 1024,
                fillColor: '#fff',
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });
            
            // Aplicar filtros al canvas
            const ctx = canvas.getContext('2d');
            ctx.filter = `brightness(${currentBrightness}%) contrast(${currentContrast}%)`;
            ctx.drawImage(canvas, 0, 0);
            
            // Convertir a blob y luego a base64
            canvas.toBlob(function(blob) {
                const reader = new FileReader();
                reader.onload = function() {
                    // Guardar la imagen editada en el campo oculto
                    document.getElementById('editedImage').value = this.result;
                    
                    // Enviar el formulario
                    document.getElementById('imageForm').submit();
                };
                reader.readAsDataURL(blob);
            }, 'image/jpeg', 0.9);
        });
    });
</script>
</body>
</html>
<?php
define('APP_NAME', 'Sistema Odontológico');
define('APP_VERSION', '1.0.0');
define('BASE_URL', 'http://localhost/Odontologia');
define('ROOT_PATH', dirname(__DIR__));

session_set_cookie_params([
    'lifetime' => 86400,
    'path' => '/',
    'domain' => '',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

date_default_timezone_set('America/Caracas');

if ($_SERVER['SERVER_NAME'] === 'localhost') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

spl_autoload_register(function ($class_name) {
    $directories = [
        __DIR__ . '/includes/',
        __DIR__ . '/includes/models/',
        __DIR__ . '/includes/controllers/'
    ];
    
    foreach ($directories as $directory) {
        $file = $directory . $class_name . '.php';
        if (file_exists($file)) {
            require_once $file;
            return;
        }
    }
    
    error_log("Clase no encontrada: $class_name");
});

define('DB_HOST', 'localhost');
define('DB_NAME', 'odontologia');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

function getDBConnection() {
    static $db = null;
    
    if ($db === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $db = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
        } catch (PDOException $e) {
            error_log("Error de conexión a BD: " . $e->getMessage());
            if ($_SERVER['SERVER_NAME'] === 'localhost') {
                die("Error de conexión a la base de datos: " . $e->getMessage());
            } else {
                die("Error en el sistema. Por favor intente más tarde.");
            }
        }
    }
    
    return $db;
}

$public_pages = ['login.php', 'register.php', 'reset-password.php'];
$current_page = basename($_SERVER['PHP_SELF']);

if (!in_array($current_page, $public_pages) ){
    if (!isset($_SESSION['usuario'])) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header("Location: " . BASE_URL . "/templates/login.php");
        exit();
    }
}

function checkPermission($requiredAdmin = false, $allowedUserId = null) {
    if (!isset($_SESSION['usuario'])) {
        header("Location: " . BASE_URL . "/templates/login.php");
        exit();
    }
    
    $user = $_SESSION['usuario'];
    
    if ($requiredAdmin && !method_exists($user, 'isAdmin') ? $user->isAdmin() : false) {
        if ($allowedUserId !== null && $user->getId() == $allowedUserId) {
            return true;
        }
        header("Location: " . BASE_URL . "/access_denied.php");
        exit();
    }
    
    return true;
}

function loadTemplate($templateName, $data = []) {
    $templatePath = ROOT_PATH . '/templates/' . $templateName . '.php';
    
    if (file_exists($templatePath)) {
        extract($data);
        require $templatePath;
    } else {
        error_log("Template no encontrado: $templateName");
        if ($_SERVER['SERVER_NAME'] === 'localhost') {
            die("Template no encontrado: $templateName");
        } else {
            die("Error en el sistema. Por favor intente más tarde.");
        }
    }
}

function url($path = '') {
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect($path) {
    header("Location: " . url($path));
    exit();
}
?>
<?php
session_start();

if (!isset($_SESSION['usuario'])) {
    header("Location: /Odontologia/templates/login.php");
    exit;
}

$usuario_actual = (object) $_SESSION['usuario'];

function esAdmin() {
    return isset($_SESSION['usuario']) && $_SESSION['usuario']['es_admin'] == 1;
}

function verificarAdmin() {
    if (!esAdmin()) {
        $_SESSION['error'] = "Acceso denegado: Se requieren privilegios de administrador";
        header('Location: /Odontologia/error.php');
        exit;
    }
}

function usuarioAutenticado() {
    if (!isset($_SESSION['usuario'])) {
        header('Location: /Odontologia/templates/login.php');
        exit;
    }
}
?>
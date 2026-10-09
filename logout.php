<?php
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

$reason = $_GET['reason'] ?? '';

// Ejecutar el deslogueo y auditoría nativo del sistema
authLogout();

// Si la desconexión vino empujada por el cliente JS, avisar al volver al login
if ($reason === 'inactivity') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    flash('warning', 'Sesión cerrada automáticamente por inactividad.');
} else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    flash('success', 'Sesión cerrada correctamente.');
}

redirect('login.php');
exit; // Asegura que PHP no procese ninguna línea fantasma posterior
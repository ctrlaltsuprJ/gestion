<?php
/**
 * Endpoint AJAX: reprograma una entrada del calendario a otro mes
 * (usado por el drag & drop de mantenimiento.php?action=calendario).
 * Responde JSON: {success:true} o {success:false, message:"..."}
 */
require_once 'includes/auth.php';
require_once 'includes/mantenimiento_calendario_functions.php';

header('Content-Type: application/json; charset=UTF-8');

$auth = authCheck();
if (!in_array($auth['rol'], [ROL_ADMIN, ROL_SUPER])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

$id  = (int)($_POST['id'] ?? 0);
$mes = (int)($_POST['mes'] ?? 0);

if (!$id || !$mes) {
    echo json_encode(['success' => false, 'message' => 'Datos incompletos.']);
    exit;
}

$prog = getProgramacionById($id);
if (!$prog) {
    echo json_encode(['success' => false, 'message' => 'Programación no encontrada.']);
    exit;
}

$ok = moverProgramacionMes($id, $mes);
if ($ok && function_exists('auditLog')) {
    auditLog('MOVER_PROG_MANT', 'programacion_mantenimiento', $id, 'Reprogramado a ' . getNombreMes($mes));
}

echo json_encode(['success' => $ok]);

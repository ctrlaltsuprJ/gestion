<?php
/**
 * api/notificaciones.php
 * ─────────────────────
 * Endpoint ligero para polling de notificaciones del navbar.
  */
require_once __DIR__.'/../includes/auth.php';

// Solo usuarios autenticados
$auth = authCheck();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

try {
    $data = [
        'ok'   => true,
        'rol'  => $auth['rol'] ?? '',
        'ts'   => time(),
    ];

    // ── Contadores según rol ──────────────────────────────────────────────

    if (isAdmin()) {
        // Cambios del supervisor pendientes de aprobar (campana amarilla)
        $data['pendingCambios'] = pendingCount();

        // Tickets de usuario sin asignar a supervisor (campana roja tickets)
        $data['pendingTicketsUsuario'] = pendingAdminTicketApprovalCount();

        // Cambios de tickets pendientes de aprobar (nuevo: del flujo supervisor→admin)
        $data['pendingTicketCambios'] = (int)getDB()->query(
            "SELECT COUNT(*)
             FROM cambios_pendientes
             WHERE modulo = 'tickets'
               AND estado = 'PENDIENTE'"
        )->fetchColumn();
    }

    if (isSuper()) {
        $suid = suserIdActual();

        // Tickets asignados al supervisor activos
        $data['pendingTicketsSupervisor'] = pendingSupervisorCount();

        // Cambios del supervisor: ¿hubo resolución nueva desde la última consulta?
        // Devuelve los cambios APROBADOS o RECHAZADOS que el supervisor aún no ha visto
        // (los marcamos como "vistos" en $_SESSION cuando el supervisor abre el ticket)
        $s = getDB()->prepare(
            "SELECT cp.id, cp.estado, cp.registro_id, cp.admin_nota,
                    a.nombre_completo admin_nombre,
                    cp.fecha_resolucion
             FROM cambios_pendientes cp
             LEFT JOIN system_users a ON a.id = cp.admin_id
             WHERE cp.suser_id    = ?
               AND cp.modulo      = 'tickets'
               AND cp.estado      IN ('APROBADO','RECHAZADO')
               AND cp.fecha_resolucion >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY cp.fecha_resolucion DESC
             LIMIT 10"
        );
        $s->execute([$suid]);
        $cambiosRecientes = $s->fetchAll();

        // Filtrar solo los no vistos (no están en $_SESSION)
        $noVistos = array_values(array_filter($cambiosRecientes, function($cp) {
            return empty($_SESSION['notif_cp_'.$cp['id']]);
        }));

        $data['cambiosResueltos']      = $noVistos;
        $data['cambiosResueltosCount'] = count($noVistos);
    }

    if (isNormal()) {
        $suid = suserIdActual();

        // Tickets del usuario normal: resueltos recientemente no vistos
        $s = getDB()->prepare(
            "SELECT t.id, t.folio_ticket, t.estado, t.fecha_cierre
             FROM tickets t
             WHERE t.solicitante_suser = ?
               AND t.estado = 'RESUELTO'
               AND t.fecha_cierre >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY t.fecha_cierre DESC
             LIMIT 5"
        );
        $s->execute([$suid]);
        $ticketsResueltos = $s->fetchAll();

        $noVistos = array_values(array_filter($ticketsResueltos, function($t) {
            return empty($_SESSION['notif_resuelto_'.$t['id']]);
        }));

        $data['ticketsResueltos']      = $noVistos;
        $data['ticketsResueltosCount'] = count($noVistos);
    }

    echo json_encode($data, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Error interno']);
}

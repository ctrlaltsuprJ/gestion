<?php
require_once 'includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'next_folio_equipo':
        echo json_encode(['folio' => nextFolioEquipo()]);
        break;

    case 'next_folio_ticket':
        echo json_encode(['folio' => nextFolioTicket()]);
        break;

    case 'equipos_by_area':
        $areaId = (int)($_GET['area_id'] ?? 0);
        $equipos = $areaId ? getEquipos(area: $areaId) : getEquipos();
        echo json_encode($equipos);
        break;

    case 'usuarios_by_area':
        $areaId = (int)($_GET['area_id'] ?? 0);
        $db = getDB();
        if ($areaId) {
            $s = $db->prepare("SELECT id, CONCAT(nombre,' ',IFNULL(apellidos,'')) AS nombre_completo FROM usuarios WHERE area_id=? AND activo=1 ORDER BY nombre");
            $s->execute([$areaId]);
        } else {
            $s = $db->query("SELECT id, CONCAT(nombre,' ',IFNULL(apellidos,'')) AS nombre_completo FROM usuarios WHERE activo=1 ORDER BY nombre");
        }
        echo json_encode($s->fetchAll());
        break;

    case 'equipo_info':
        $eqId = (int)($_GET['equipo_id'] ?? 0);
        $eq = $eqId ? getEquipoById($eqId) : null;
        echo json_encode($eq ?: new stdClass());
        break;

    case 'dashboard_stats':
        echo json_encode(getDashboardStats());
        break;

    case 'search_ip':
        $ip = trim($_GET['ip'] ?? '');
        if (!$ip) { echo json_encode([]); break; }
        $s = getDB()->prepare("SELECT e.*, t.nombre tipo_nombre FROM equipos e LEFT JOIN tipos_equipo t ON t.id=e.tipo_id WHERE e.ip LIKE ? AND e.activo=1 LIMIT 10");
        $s->execute(['%'.$ip.'%']);
        echo json_encode($s->fetchAll());
        break;

    case 'search_mac':
        $mac = trim($_GET['mac'] ?? '');
        if (!$mac) { echo json_encode([]); break; }
        $s = getDB()->prepare("SELECT e.*, t.nombre tipo_nombre FROM equipos e LEFT JOIN tipos_equipo t ON t.id=e.tipo_id WHERE e.mac_address LIKE ? AND e.activo=1 LIMIT 10");
        $s->execute(['%'.$mac.'%']);
        echo json_encode($s->fetchAll());
        break;

    case 'check_equipo_dup':
        if (isNormal()) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Sin permiso']);
            exit;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Usa POST']);
            exit;
        }
        $folio = trim((string) ($_POST['folio'] ?? ''));
        $ipSeg = trim((string) ($_POST['ip_segmento'] ?? ''));
        $ipHost = trim((string) ($_POST['ip_host'] ?? ''));
        $ip = trim((string) ($_POST['ip'] ?? ''));
        if ($ipSeg !== '' && $ipHost !== '') {
            $ip = $ipSeg . '.' . $ipHost;
        }
        $excludeId = (int) ($_POST['exclude_id'] ?? 0) ?: null;
        $confFolio = equipoConflictoPorFolio($folio, $excludeId);
        $confIp = equipoConflictoPorIp($ip, $excludeId);
        $sug = null;
        if ($confIp) {
            $prefix = $ipSeg;
            if ($prefix === '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $bits = explode('.', $ip);
                $prefix = $bits[0] . '.' . $bits[1] . '.' . $bits[2];
            }
            if ($prefix !== '') {
                $sug = sugerirIpLibreEnRed($prefix);
            }
        }
        echo json_encode([
            'ok' => true,
            'folio_ocupado' => $confFolio,
            'ip_ocupado' => $confIp,
            'sugerencia_ip' => $sug,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Acción no válida']);
}

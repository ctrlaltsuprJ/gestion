<?php
// ============================================================
//  includes/auth.php
//  Autenticación, sesión y control de acceso por rol
// ============================================================
require_once __DIR__.'/db.php';
require_once __DIR__.'/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// ── Roles ────────────────────────────────────────────────
define('ROL_ADMIN',  'ADMINISTRADOR');
define('ROL_SUPER',  'SUPERVISOR_TECNICO');
define('ROL_USER',   'USUARIO');
define('ROL_CURSO',  'CURSO');

// ── Control Estricto de Inactividad (Servidor) ───────────
if (!empty($_SESSION['auth'])) {
    $tiempo_max_inactivo = 900; // 15 minutos en segundos
    
    if (isset($_SESSION['ultima_actividad'])) {
        $vida_sesion = time() - $_SESSION['ultima_actividad'];
        if ($vida_sesion > $tiempo_max_inactivo) {
            // Registrar auditoría antes de limpiar los datos
            auditLog('SESSION_EXPIRED', 'auth', $_SESSION['auth']['id'] ?? null, 'Sesión expirada por inactividad');
            
            // Destrucción total y segura de la sesión
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy();
            
            // Reabrir sesión limpia para inyectar mensaje flash de aviso
            if (session_status() === PHP_SESSION_NONE) session_start();
            flash('warning', 'Tu sesión ha expirado por inactividad.');
            redirect('login.php');
            exit;
        }
    }
    $_SESSION['ultima_actividad'] = time(); // Actualizar marca de actividad
}

// ════════════════════════════════════════════════════════
//  SESIÓN
// ════════════════════════════════════════════════════════
function authLogin(string $username, string $password): array|false {
    $db = getDB();
    $s  = $db->prepare("SELECT * FROM system_users WHERE username=? AND activo=1 LIMIT 1");
    $s->execute([trim($username)]);
    $su = $s->fetch();
    if (!$su) return false;
    if (!password_verify($password, $su['password_hash'])) return false;
    // Actualizar último acceso
    $db->prepare("UPDATE system_users SET ultimo_acceso=NOW() WHERE id=?")->execute([$su['id']]);
    // Guardar sesión
    $_SESSION['auth'] = [
        'id'             => $su['id'],
        'username'       => $su['username'],
        'nombre'         => $su['nombre_completo'],
        'rol'            => $su['rol'],
        'usuario_id'     => $su['usuario_id'],
        'email'          => $su['email'],
        'ultimo_acceso_prev' => $su['ultimo_acceso'] ?? null,
    ];
    $_SESSION['ultima_actividad'] = time(); // Inicializar estampa de tiempo
    auditLog('LOGIN', 'auth', $su['id'], 'Inicio de sesión exitoso');
    return $_SESSION['auth'];
}

function authLogout(): void {
    if (!empty($_SESSION['auth'])) {
        auditLog('LOGOUT', 'auth', $_SESSION['auth']['id'] ?? null, 'Cierre de sesión');
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_unset();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function authCheck(): array {
    if (empty($_SESSION['auth'])) {
        redirect('login.php');
        exit; // PREVENCIÓN: Evita ejecución de código subsecuente
    }
    return $_SESSION['auth'];
}

function authUser(): ?array {
    return $_SESSION['auth'] ?? null;
}

function isAdmin():  bool { return ($_SESSION['auth']['rol'] ?? '') === ROL_ADMIN; }
function isSuper():  bool { return ($_SESSION['auth']['rol'] ?? '') === ROL_SUPER; }
function isNormal(): bool { return ($_SESSION['auth']['rol'] ?? '') === ROL_USER; }
function rolActual(): string { return $_SESSION['auth']['rol'] ?? ''; }
function suserIdActual(): int { return (int)($_SESSION['auth']['id'] ?? 0); }
function usuarioIdActual(): ?int { return ($_SESSION['auth']['usuario_id']) ? (int)$_SESSION['auth']['usuario_id'] : null; }

// Requiere rol específico o redirige
function requireRol(string ...$roles): void {
    $auth = authCheck();
    if (!in_array($auth['rol'], $roles)) {
        flash('danger', 'No tienes permiso para acceder a esa sección.');
        redirect('index.php');
        exit; // Asegurar detención del script
    }
}

// ════════════════════════════════════════════════════════
//  PERMISOS POR MÓDULO
// ════════════════════════════════════════════════════════
function canDo(string $accion): bool {
    $rol = rolActual();
    $permisos = [
        ROL_ADMIN => [
            'ver_dashboard','ver_equipos','crear_equipo','editar_equipo','eliminar_equipo',
            'ver_usuarios','crear_usuario','editar_usuario','eliminar_usuario',
            'ver_inventario','crear_inventario','editar_inventario','eliminar_inventario',
            'ver_tickets','crear_ticket','editar_ticket','eliminar_ticket',
            'asignar_ticket',           
            'ver_mantenimiento','crear_mantenimiento','editar_mantenimiento','eliminar_mantenimiento',
            'aprobar_cambios',          
            'ver_auditoria',
            'ver_areas','crear_area','editar_area','eliminar_area',
            'ver_system_users','crear_system_user','editar_system_user','eliminar_system_user',
            'exportar',
        ],
        ROL_SUPER => [
            'ver_dashboard',
            'ver_equipos','editar_equipo',           
            'ver_usuarios',
            'ver_inventario','editar_inventario',
            'ver_tickets','editar_ticket',            
            'crear_mantenimiento','ver_mantenimiento','editar_mantenimiento',
            'exportar',
        ],
        ROL_USER  => [
            'ver_mis_equipos',          
            'ver_tickets_propios',
            'crear_ticket_propio',      
        ],
    ];
    return in_array($accion, $permisos[$rol] ?? []);
}

function puede(string $accion): bool { return canDo($accion); }

// ════════════════════════════════════════════════════════
//  AUDITORÍA
// ════════════════════════════════════════════════════════
function auditLog(string $accion, string $modulo='', ?int $registroId=null, string $desc=''): void {
    try {
        $suid = (int)($_SESSION['auth']['id'] ?? 0) ?: null;
        $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
        getDB()->prepare(
            "INSERT INTO auditoria(suser_id,accion,modulo,registro_id,descripcion,ip_address) VALUES(?,?,?,?,?,?)"
        )->execute([$suid, $accion, $modulo, $registroId, $desc, $ip]);
    } catch (\Throwable) { /* silenciar para no romper flujo */ }
}

// ════════════════════════════════════════════════════════
//  CAMBIOS PENDIENTES (Supervisor → Admin)
// ════════════════════════════════════════════════════════
function guardarCambioPendiente(string $modulo, int $registroId, string $accion, array $datos, string $desc=''): int {
    $suid = suserIdActual();
    getDB()->prepare(
        "INSERT INTO cambios_pendientes(suser_id,modulo,registro_id,accion,datos_json,descripcion)VALUES(?,?,?,?,?,?)"
    )->execute([$suid, $modulo, $registroId, $accion, json_encode($datos, JSON_UNESCAPED_UNICODE), $desc]);
    return (int)getDB()->lastInsertId();
}

function getCambiosPendientes(string $estado='PENDIENTE'): array {
    $s = getDB()->prepare(
        "SELECT cp.*, su.nombre_completo supervisor_nombre, a.nombre_completo admin_nombre
         FROM cambios_pendientes cp
         LEFT JOIN system_users su ON su.id=cp.suser_id
         LEFT JOIN system_users a  ON a.id=cp.admin_id
         WHERE cp.estado=?
         ORDER BY cp.created_at DESC"
    );
    $s->execute([$estado]);
    return $s->fetchAll();
}

function getCambioPendienteById(int $id): array|false {
    $s = getDB()->prepare("SELECT cp.*, su.nombre_completo supervisor_nombre FROM cambios_pendientes cp LEFT JOIN system_users su ON su.id=cp.suser_id WHERE cp.id=?");
    $s->execute([$id]); return $s->fetch();
}

function resolverCambio(int $id, string $decision, string $nota=''): void {
    $cp = getCambioPendienteById($id);
    if (!$cp || $cp['estado'] !== 'PENDIENTE') return;

    $adminId = suserIdActual();
    getDB()->prepare(
        "UPDATE cambios_pendientes SET estado=?,admin_id=?,admin_nota=?,fecha_resolucion=NOW() WHERE id=?"
    )->execute([$decision, $adminId, $nota, $id]);

    if ($decision === 'APROBADO') {
        $datos = json_decode($cp['datos_json'], true);
        aplicarCambioAprobado($cp['modulo'], (int)$cp['registro_id'], $cp['accion'], $datos);
    }

    auditLog('CAMBIO_'.$decision, $cp['modulo'], $cp['registro_id'],
             "Cambio #$id ".strtolower($decision).($nota ? ' — Nota: '.$nota : ''));

    $_SESSION['notif_cambio'] = [
        'decision'   => $decision,
        'cp_id'      => $id,
        'modulo'     => $cp['modulo'],
        'registro_id'=> $cp['registro_id'],
        'nota'       => $nota,
        'supervisor' => $cp['supervisor_nombre'] ?? '',
    ];
}

function getCambioPendienteByTicket(int $ticketId): array|false {
    $s = getDB()->prepare(
        "SELECT cp.*, su.nombre_completo supervisor_nombre
         FROM cambios_pendientes cp
         LEFT JOIN system_users su ON su.id = cp.suser_id
         WHERE cp.modulo = 'tickets' AND cp.registro_id = ? AND cp.estado = 'PENDIENTE'
         ORDER BY cp.created_at DESC LIMIT 1"
    );
    $s->execute([$ticketId]);
    return $s->fetch() ?: false;
}

function getUltimoCambioResueltoByTicket(int $ticketId): array|false {
    $s = getDB()->prepare(
        "SELECT cp.*, su.nombre_completo supervisor_nombre, a.nombre_completo admin_nombre
         FROM cambios_pendientes cp
         LEFT JOIN system_users su ON su.id = cp.suser_id
         LEFT JOIN system_users a  ON a.id  = cp.admin_id
         WHERE cp.modulo = 'tickets' AND cp.registro_id = ? AND cp.estado IN ('APROBADO','RECHAZADO')
         ORDER BY cp.fecha_resolucion DESC LIMIT 1"
    );
    $s->execute([$ticketId]);
    return $s->fetch() ?: false;
}

function aplicarCambioAprobado(string $modulo, int $id, string $accion, array $datos): void {
    switch ($modulo) {
        case 'tickets': saveTicket($datos, $id); break;
        case 'equipos': saveEquipo($datos, $id); break;
        case 'inventario': saveInventario($datos, $id); break;
        case 'mantenimientos':
            $checks = $datos['_checks'] ?? [];
            unset($datos['_checks']);
            saveMantenimiento($datos, $checks, $id); break;
    }
}

// ════════════════════════════════════════════════════════
//  SYSTEM USERS (CRUD)
// ════════════════════════════════════════════════════════
function getSystemUsers(): array {
    return getDB()->query(
        "SELECT su.*, u.nombre usuario_vinculado_nombre
         FROM system_users su
         LEFT JOIN usuarios u ON u.id=su.usuario_id
         ORDER BY FIELD(su.rol,'ADMINISTRADOR','SUPERVISOR_TECNICO','USUARIO'), su.nombre_completo"
    )->fetchAll();
}

function getSystemUserById(int $id): array|false {
    $s = getDB()->prepare("SELECT * FROM system_users WHERE id=?");
    $s->execute([$id]); return $s->fetch();
}

function getSupervisores(): array {
    return getDB()->query(
        "SELECT id, nombre_completo, username FROM system_users WHERE rol='SUPERVISOR_TECNICO' AND activo=1 ORDER BY nombre_completo"
    )->fetchAll();
}

function saveSystemUser(array $d, ?int $id=null): int {
    $db = getDB();
    $nombre   = trim($d['nombre_completo'] ?? '');
    $username = trim($d['username'] ?? '');
    $email    = trim($d['email'] ?? '');
    $rol      = $d['rol'] ?? ROL_USER;
    $usuarioId= ($d['usuario_id'] ?? null) ?: null;
    $activo   = (int)($d['activo'] ?? 1);

    if ($id) {
        $sql = "UPDATE system_users SET nombre_completo=?,username=?,email=?,rol=?,usuario_id=?,activo=?";
        $params = [$nombre, $username, $email, $rol, $usuarioId, $activo];
        if (!empty($d['password']) && strlen(trim($d['password'])) >= 6) {
            $sql .= ',password_hash=?';
            $params[] = password_hash(trim($d['password']), PASSWORD_BCRYPT, ['cost'=>12]);
        }
        $sql .= ' WHERE id=?';
        $params[] = $id;
        $db->prepare($sql)->execute($params);
        return $id;
    }
    $hash = password_hash(trim($d['password'] ?? 'changeme123'), PASSWORD_BCRYPT, ['cost'=>12]);
    $db->prepare(
        "INSERT INTO system_users(nombre_completo,username,email,rol,usuario_id,activo,password_hash)VALUES(?,?,?,?,?,?,?)"
    )->execute([$nombre, $username, $email, $rol, $usuarioId, $activo, $hash]);
    return (int)$db->lastInsertId();
}

function deleteSystemUser(int $id): void {
    getDB()->prepare("UPDATE system_users SET activo=0 WHERE id=?")->execute([$id]);
}

// ════════════════════════════════════════════════════════
//  EQUIPOS DEL USUARIO NORMAL
// ════════════════════════════════════════════════════════
function getMisEquipos(): array {
    $usuarioId = usuarioIdActual();
    if (!$usuarioId) return [];     
    $s = getDB()->prepare(  
        "SELECT e.*,t.nombre tipo_nombre,t.icono tipo_icono,a.nombre area_nombre
         FROM equipos e
         LEFT JOIN tipos_equipo t ON t.id=e.tipo_id
         LEFT JOIN areas a ON a.id=e.area_id
         WHERE e.usuario_id=? AND e.activo=1
         ORDER BY e.folio"
    );
    $s->execute([$usuarioId]);
    return $s->fetchAll();
}

function getMisTickets(): array {
    $suid = suserIdActual();
    $s = getDB()->prepare(
        "SELECT t.*,e.folio equipo_folio,e.marca equipo_marca,e.modelo equipo_modelo
         FROM tickets t
         LEFT JOIN equipos e ON e.id=t.equipo_id
         WHERE t.solicitante_suser=?
         ORDER BY FIELD(t.prioridad,'URGENTE','ALTA','MEDIA','BAJA'), t.fecha_apertura DESC"
    );
    $s->execute([$suid]);
    return $s->fetchAll();
}

function puedeSolicitarTicketsJerarquia(?int $suserId=null): bool {
    $suserId = $suserId ?: suserIdActual();
    if (!$suserId) return false;
    $su = getSystemUserById($suserId);
    if (!$su || ($su['rol'] ?? '') !== ROL_USER || empty($su['usuario_id'])) return false;

    $s = getDB()->prepare("SELECT p.nivel FROM usuarios u LEFT JOIN cat_puestos p ON p.id=u.puesto_id WHERE u.id=? LIMIT 1");
    $s->execute([(int)$su['usuario_id']]);
    $nivel = strtoupper((string)($s->fetchColumn() ?: ''));
    return in_array($nivel, ['DIRECTIVO','SUBDIRECTIVO','JEFATURA'], true);
}

function getUsuariosJerarquiaSolicitante(?int $suserId=null): array {
    $suserId = $suserId ?: suserIdActual();
    if (!$suserId || !puedeSolicitarTicketsJerarquia($suserId)) return [];
    $su = getSystemUserById($suserId);
    $usuarioLiderId = (int)($su['usuario_id'] ?? 0);
    if (!$usuarioLiderId) return [];

    $db = getDB();
    $s = $db->prepare("SELECT area_id FROM usuarios WHERE id=? LIMIT 1");
    $s->execute([$usuarioLiderId]);
    $areaId = (int)($s->fetchColumn() ?: 0);
    if (!$areaId) return [];

    $s = $db->prepare(
        "SELECT u.*, a.nombre area_nombre, p.nombre puesto_nombre, p.nivel puesto_nivel
         FROM usuarios u
         LEFT JOIN areas a ON a.id=u.area_id
         LEFT JOIN cat_puestos p ON p.id=u.puesto_id
         WHERE u.activo=1 AND u.area_id=?
         ORDER BY u.nombre, u.apellidos"
    );
    $s->execute([$areaId]);
    return $s->fetchAll();
}

function getEquiposJerarquiaSolicitante(?int $suserId=null): array {
    $usuarios = getUsuariosJerarquiaSolicitante($suserId);
    if (empty($usuarios)) return [];
    $ids = array_map(fn($u)=>(int)$u['id'], $usuarios);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $s = getDB()->prepare(
        "SELECT e.*, t.nombre tipo_nombre, a.nombre area_nombre
         FROM equipos e
         LEFT JOIN tipos_equipo t ON t.id=e.tipo_id
         LEFT JOIN areas a ON a.id=e.area_id
         WHERE e.activo=1 AND e.usuario_id IN ($placeholders)
         ORDER BY e.folio"
    );
    $s->execute($ids);
    return $s->fetchAll();
}

function getMisTicketsComoSupervisor(): array {
    $suid  = suserIdActual();
    $su    = getSystemUserById($suid);
    $nombre = $su['nombre_completo'] ?? '';
    $s = getDB()->prepare(
        "SELECT t.*, e.folio equipo_folio, e.marca equipo_marca, e.modelo equipo_modelo,
                CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario_nombre
         FROM tickets t
         LEFT JOIN equipos e ON e.id=t.equipo_id
         LEFT JOIN usuarios u ON u.id=t.usuario_id
         WHERE t.asignado_a_suser=? OR (t.asignado_a_suser IS NULL AND t.tecnico=?)
         ORDER BY FIELD(t.prioridad,'URGENTE','ALTA','MEDIA','BAJA'), t.fecha_apertura DESC"
    );
    $s->execute([$suid, $nombre]);
    return $s->fetchAll();
}

// ════════════════════════════════════════════════════════
//  HELPERS UI
// ════════════════════════════════════════════════════════
function rolBadge(string $rol): string {
    return match($rol) {
        ROL_ADMIN => '<span class="badge" style="background:#1a3a5c;">ADMINISTRADOR</span>',
        ROL_SUPER => '<span class="badge bg-warning text-dark">SUPERVISOR TI</span>',
        ROL_USER  => '<span class="badge bg-secondary">USUARIO</span>',
        default   => '<span class="badge bg-light text-dark">'.h($rol).'</span>',
    };
}

function pendingCount(): int {
    if (!isAdmin()) return 0;
    return (int)getDB()->query("SELECT COUNT(*) FROM cambios_pendientes WHERE estado='PENDIENTE'")->fetchColumn();
}

function pendingSupervisorCount(): int {
    if (!isSuper()) return 0;
    $s = getDB()->prepare("SELECT COUNT(*) FROM tickets WHERE asignado_a_suser=? AND estado IN ('ABIERTO','EN_PROCESO')");
    $s->execute([suserIdActual()]);
    return (int)$s->fetchColumn();
}

function pendingSupervisorNewCount(): int {
    if (!isSuper()) return 0;
    $ultimoAcceso = $_SESSION['auth']['ultimo_acceso_prev'] ?? null;
    if (!$ultimoAcceso) return pendingSupervisorCount();
    $s = getDB()->prepare("SELECT COUNT(*) FROM tickets WHERE asignado_a_suser=? AND estado IN ('ABIERTO','EN_PROCESO') AND fecha_apertura >= ?");
    $s->execute([suserIdActual(), $ultimoAcceso]);
    return (int)$s->fetchColumn();
}

function pendingAdminTicketApprovalCount(): int {
    if (!isAdmin()) return 0;
    $s = getDB()->prepare("SELECT COUNT(*) FROM tickets WHERE estado='ABIERTO' AND asignado_a_suser IS NULL AND solicitante_suser IS NOT NULL");
    $s->execute();
    return (int)$s->fetchColumn();
}
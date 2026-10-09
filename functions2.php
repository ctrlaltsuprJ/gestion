<?php
require_once __DIR__.'/db.php';

// ── Generic ───────────────────────────────────────────────
function h(mixed $v): string { return htmlspecialchars((string)($v??''), ENT_QUOTES,'UTF-8'); }
function redirect(string $u): never { header("Location: $u"); exit; }
function jsonOut(mixed $d, int $c=200): never { http_response_code($c); header('Content-Type:application/json;charset=utf-8'); echo json_encode($d,JSON_UNESCAPED_UNICODE); exit; }

// ── Flash messages (session) ──────────────────────────────
function flash(string $type, string $msg): void { $_SESSION['flash']=['type'=>$type,'msg'=>$msg]; }
function getFlash(): ?array { $f=$_SESSION['flash']??null; unset($_SESSION['flash']); return $f; }

// ── Folio auto ────────────────────────────────────────────
function nextFolioEquipo(): string {
    $n = getDB()->query("SELECT COUNT(*)+1 AS n FROM equipos")->fetchColumn();
    return 'CECC-'.str_pad($n,4,'0',STR_PAD_LEFT);
}
function nextFolioTicket(): string {
    $db = getDB();
    $year = date('Y');
    $s = $db->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(folio_ticket,'-',-1) AS UNSIGNED)),0)+1
         FROM tickets
         WHERE folio_ticket LIKE ?"
    );
    $s->execute(["TKT-$year-%"]);
    $n = (int)$s->fetchColumn();
    return 'TKT-'.$year.'-'.str_pad((string)$n,4,'0',STR_PAD_LEFT);
}

// ════════════════════════════════════════════════════════
//  ÁREAS
// ════════════════════════════════════════════════════════
function getAreas(): array { return getDB()->query("SELECT * FROM areas WHERE activo=1 ORDER BY nombre")->fetchAll(); }
function getAreaById(int $id) { $s=getDB()->prepare("SELECT * FROM areas WHERE id=?"); $s->execute([$id]); return $s->fetch(); }
function saveArea(array $d, ?int $id=null): void {
    $db=getDB();
    if($id){ $db->prepare("UPDATE areas SET nombre=?,descripcion=? WHERE id=?")->execute([$d['nombre'],$d['descripcion'],$id]); }
    else    { $db->prepare("INSERT INTO areas(nombre,descripcion)VALUES(?,?)")->execute([$d['nombre'],$d['descripcion']]); }
}
function deleteArea(int $id): void { getDB()->prepare("DELETE FROM areas WHERE id=?")->execute([$id]); }

// ════════════════════════════════════════════════════════
//  USUARIOS
// ════════════════════════════════════════════════════════
function getUsuarios(string $q=''): array {
    $db=getDB(); $like='%'.$q.'%';
    try {
        $sql = "SELECT u.*, a.nombre area_nombre, p.nombre puesto_nombre, p.nivel puesto_nivel
                FROM usuarios u
                LEFT JOIN areas a ON a.id=u.area_id
                LEFT JOIN cat_puestos p ON p.id=u.puesto_id
                WHERE u.activo=1";
        if($q){
            $sql .= " AND (u.nombre LIKE ? OR u.apellidos LIKE ? OR u.email LIKE ?
                           OR u.usuario_windows LIKE ? OR u.extension_ip LIKE ?
                           OR u.cubículo LIKE ? OR u.oficina LIKE ?)";
            $s=$db->prepare($sql." ORDER BY u.nombre");
            $s->execute([$like,$like,$like,$like,$like,$like,$like]);
        } else {
            $s=$db->query($sql." ORDER BY a.nombre, u.nombre");
        }
        return $s->fetchAll();
    } catch (\Throwable) {
        // Fallback pre-migración
        if($q){
            $s=$db->prepare("SELECT u.*,a.nombre area_nombre FROM usuarios u LEFT JOIN areas a ON a.id=u.area_id WHERE u.activo=1 AND (u.nombre LIKE ? OR u.apellidos LIKE ? OR u.email LIKE ? OR u.usuario_windows LIKE ?) ORDER BY u.nombre");
            $s->execute([$like,$like,$like,$like]);
        } else {
            $s=$db->query("SELECT u.*,a.nombre area_nombre FROM usuarios u LEFT JOIN areas a ON a.id=u.area_id WHERE u.activo=1 ORDER BY u.nombre");
        }
        return $s->fetchAll();
    }
}

function getUsuarioById(int $id) {
    try {
        $s=getDB()->prepare(
            "SELECT u.*, a.nombre area_nombre, p.nombre puesto_nombre, p.nivel puesto_nivel
             FROM usuarios u
             LEFT JOIN areas a ON a.id=u.area_id
             LEFT JOIN cat_puestos p ON p.id=u.puesto_id
             WHERE u.id=?"
        );
        $s->execute([$id]); return $s->fetch();
    } catch (\Throwable) {
        $s=getDB()->prepare("SELECT u.*, a.nombre area_nombre FROM usuarios u LEFT JOIN areas a ON a.id=u.area_id WHERE u.id=?");
        $s->execute([$id]); return $s->fetch();
    }
}

function normalizeWindowsToken(string $text): string {
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $map = [
        'á'=>'a','à'=>'a','ä'=>'a','â'=>'a',
        'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
        'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
        'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
        'ñ'=>'n',
    ];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/', '', $text) ?? '';
    return $text;
}

function buildUsuarioWindowsBase(string $nombre, string $apellidos): string {
    $nombres = preg_split('/\s+/', trim($nombre)) ?: [];
    $aps = preg_split('/\s+/', trim($apellidos)) ?: [];
    $primerNombre = $nombres[0] ?? '';
    $primerApellido = $aps[0] ?? '';
    $segundoApellido = $aps[1] ?? '';
    if ($primerNombre === '' || $primerApellido === '') return '';

    $l1Nombre = normalizeWindowsToken(mb_substr($primerNombre, 0, 1, 'UTF-8'));
    $primerApellidoN = normalizeWindowsToken($primerApellido);
    $l1SegundoApellido = $segundoApellido !== ''
        ? normalizeWindowsToken(mb_substr($segundoApellido, 0, 1, 'UTF-8'))
        : '';
    return $l1Nombre.$primerApellidoN.$l1SegundoApellido;
}

function usuarioWindowsDisponible(string $usuarioWindows, ?int $excludeId=null): bool {
    if ($usuarioWindows === '') return false;
    $db = getDB();
    if ($excludeId) {
        $s = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario_windows=? AND id<>?");
        $s->execute([$usuarioWindows, $excludeId]);
    } else {
        $s = $db->prepare("SELECT COUNT(*) FROM usuarios WHERE usuario_windows=?");
        $s->execute([$usuarioWindows]);
    }
    return (int)$s->fetchColumn() === 0;
}

function sugerirUsuarioWindows(string $nombre, string $apellidos, ?int $excludeId=null): array {
    $base = buildUsuarioWindowsBase($nombre, $apellidos);
    if ($base === '') {
        return ['base'=>'','suggested'=>'','available'=>false,'alternatives'=>[]];
    }
    if (usuarioWindowsDisponible($base, $excludeId)) {
        return ['base'=>$base,'suggested'=>$base,'available'=>true,'alternatives'=>[]];
    }

    $alternatives = [];
    $suggested = '';
    for ($i = 1; $i <= 20; $i++) {
        $candidate = $base.$i;
        if (usuarioWindowsDisponible($candidate, $excludeId)) {
            $suggested = $candidate;
            $alternatives[] = $candidate;
            break;
        }
        if (count($alternatives) < 5) $alternatives[] = $candidate;
    }
    return [
        'base' => $base,
        'suggested' => $suggested,
        'available' => $suggested !== '',
        'alternatives' => $alternatives,
    ];
}

function buildContrasenaRef(string $apellidos, string $biometricoId): string {
    $apellidos = trim($apellidos);
    $biometricoId = preg_replace('/\D+/', '', trim($biometricoId));
    if ($apellidos === '' || $biometricoId === '') return '';

    $partes = preg_split('/\s+/', $apellidos) ?: [];
    $primerApellido = $partes[0] ?? '';
    $segundoApellido = $partes[1] ?? '';
    if ($primerApellido === '') return '';

    $l1 = strtoupper(substr($primerApellido, 0, 1));
    $l2 = $segundoApellido !== '' ? strtolower(substr($segundoApellido, 0, 1)) : 'x';
    return $l1.$l2.'13'.$biometricoId;
}

function saveUsuario(array $d, ?int $id=null): int {
    $db=getDB();
    if (empty($d['usuario_windows']) && !empty($d['nombre']) && !empty($d['apellidos'])) {
        $sug = sugerirUsuarioWindows((string)$d['nombre'], (string)$d['apellidos'], $id);
        if (!empty($sug['suggested'])) $d['usuario_windows'] = $sug['suggested'];
    }
    if (!empty($d['apellidos']) && !empty($d['biometrico_id'])) {
        $generada = buildContrasenaRef((string)$d['apellidos'], (string)$d['biometrico_id']);
        if ($generada !== '') $d['contrasena_ref'] = $generada;
    }
    try {
        $f=['area_id','puesto_id','nombre','apellidos','email','telefono','puesto',
            'usuario_windows','contrasena_ref','cubículo','oficina','extension_ip','telefono_directo','genero'];
        $v=array_map(fn($k)=>($d[$k]??null)?trim($d[$k]):null, $f);
        if($id){
            $set=implode(',',array_map(fn($k)=>"$k=?",$f));
            $db->prepare("UPDATE usuarios SET $set WHERE id=?")->execute([...$v,$id]);
            return $id;
        }
        $db->prepare("INSERT INTO usuarios(".implode(',',$f).")VALUES(".implode(',',array_fill(0,count($f),'?')).")")->execute($v);
        return (int)$db->lastInsertId();
    } catch (\Throwable) {
        // Fallback sin columnas nuevas (pre-migración usuarios)
        $f=['area_id','nombre','apellidos','email','telefono','puesto','usuario_windows','contrasena_ref','genero'];
        $v=array_map(fn($k)=>($d[$k]??null)?trim($d[$k]):null, $f);
        if($id){
            $set=implode(',',array_map(fn($k)=>"$k=?",$f));
            $db->prepare("UPDATE usuarios SET $set WHERE id=?")->execute([...$v,$id]);
            return $id;
        }
        $db->prepare("INSERT INTO usuarios(".implode(',',$f).")VALUES(".implode(',',array_fill(0,count($f),'?')).")")->execute($v);
        return (int)$db->lastInsertId();
    }
}

function deleteUsuario(int $id): void { getDB()->prepare("UPDATE usuarios SET activo=0 WHERE id=?")->execute([$id]); }

// ════════════════════════════════════════════════════════
//  CATÁLOGO DE PUESTOS
// ════════════════════════════════════════════════════════
function getPuestos(): array {
    try {
        return getDB()->query(
            "SELECT * FROM cat_puestos WHERE activo=1
             ORDER BY FIELD(nivel,'DIRECTIVO','SUBDIRECTIVO','JEFATURA','COORDINACION','OPERATIVO','APOYO'), nombre"
        )->fetchAll();
    } catch (\Throwable) { return []; }
}
function getPuestoById(int $id) {
    $s=getDB()->prepare("SELECT * FROM cat_puestos WHERE id=?");
    $s->execute([$id]); return $s->fetch();
}
function savePuesto(array $d, ?int $id=null): int {
    $db=getDB();
    if($id){
        $db->prepare("UPDATE cat_puestos SET nombre=?,descripcion=?,nivel=? WHERE id=?")
           ->execute([trim($d['nombre']),trim($d['descripcion']??''),$d['nivel'],$id]);
        return $id;
    }
    $db->prepare("INSERT INTO cat_puestos(nombre,descripcion,nivel)VALUES(?,?,?)")
       ->execute([trim($d['nombre']),trim($d['descripcion']??''),$d['nivel']??'OPERATIVO']);
    return (int)$db->lastInsertId();
}
function deletePuesto(int $id): void {
    getDB()->prepare("UPDATE cat_puestos SET activo=0 WHERE id=?")->execute([$id]);
}

// ════════════════════════════════════════════════════════
//  DIRECTORIO TELEFÓNICO IP
// ════════════════════════════════════════════════════════
function getDirectorio(string $q='', int $areaId=0): array {
    try {
        $db=getDB(); $where=['d.activo=1']; $params=[];
        if($areaId){ $where[]='d.area_id=?'; $params[]=$areaId; }
        if($q){
            $like='%'.$q.'%';
            $where[]="(d.extension LIKE ? OR d.nombre_completo LIKE ? OR d.puesto_cargo LIKE ?
                       OR d.telefono_directo LIKE ? OR d.ubicacion LIKE ? OR a.nombre LIKE ?)";
            array_push($params,$like,$like,$like,$like,$like,$like);
        }
        $w=implode(' AND ',$where);
        $s=$db->prepare(
            "SELECT d.*, a.nombre area_nombre, u.nombre usuario_nombre, u.apellidos usuario_apellidos
             FROM directorio d
             JOIN areas a ON a.id=d.area_id
             LEFT JOIN usuarios u ON u.id=d.usuario_id
             WHERE $w ORDER BY a.nombre, d.orden, d.id"
        );
        $s->execute($params); return $s->fetchAll();
    } catch (\Throwable) { return []; }
}

function getDirectorioById(int $id) {
    $s=getDB()->prepare("SELECT d.*, a.nombre area_nombre FROM directorio d JOIN areas a ON a.id=d.area_id WHERE d.id=?");
    $s->execute([$id]); return $s->fetch();
}

function getDirectorioAgrupado(string $q='', int $areaId=0): array {
    $rows = getDirectorio($q, $areaId);
    $grouped = [];
    foreach($rows as $r){
        $grouped[$r['area_nombre']][] = $r;
    }
    return $grouped;
}

function saveDirectorio(array $d, ?int $id=null): int {
    $db=getDB();
    $f=['area_id','usuario_id','extension','nombre_completo','puesto_cargo',
        'telefono_directo','ubicacion','notas','color_area','orden'];
    $v=array_map(fn($k)=>($d[$k]??null)?trim($d[$k]):null,$f);
    if($id){
        $set=implode(',',array_map(fn($k)=>"$k=?",$f));
        $db->prepare("UPDATE directorio SET $set WHERE id=?")->execute([...$v,$id]);
        return $id;
    }
    $cols=implode(',',$f); $ph=implode(',',array_fill(0,count($f),'?'));
    $db->prepare("INSERT INTO directorio($cols)VALUES($ph)")->execute($v);
    return (int)$db->lastInsertId();
}

function deleteDirectorio(int $id): void {
    getDB()->prepare("UPDATE directorio SET activo=0 WHERE id=?")->execute([$id]);
}

// ════════════════════════════════════════════════════════
//  TIPOS EQUIPO
// ════════════════════════════════════════════════════════
function getTiposEquipo(): array { return getDB()->query("SELECT * FROM tipos_equipo ORDER BY nombre")->fetchAll(); }

// ════════════════════════════════════════════════════════
//  EQUIPOS
// ════════════════════════════════════════════════════════

/** Indica si la tabla equipos tiene columnas de ficha técnica (migración v7). */
function equiposTieneColumnasTecnicas(): bool {
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $st = getDB()->query("SHOW COLUMNS FROM equipos LIKE 'sistema_operativo'");
        $ok = (bool) $st->fetch();
    } catch (\Throwable) {
        $ok = false;
    }
    return $ok;
}

function getEquipos(string $q='', string $estado='', int $tipo=0, int $area=0, string $ipSegmento='', string $ipOrden=''): array {
    $db=getDB(); $where=['e.activo=1']; $params=[];
    if($q){ $like='%'.$q.'%'; $where[]="(e.folio LIKE ? OR e.marca LIKE ? OR e.modelo LIKE ? OR e.serie LIKE ? OR e.ip LIKE ? OR e.mac_address LIKE ? OR u.nombre LIKE ?)"; $params=array_merge($params,array_fill(0,7,$like)); }
    if($estado){ $where[]="e.estado=?"; $params[]=$estado; }
    if($tipo)  { $where[]="e.tipo_id=?"; $params[]=$tipo; }
    if($area)  { $where[]="e.area_id=?"; $params[]=$area; }
    if($ipSegmento){ $where[]="e.ip LIKE ?"; $params[]=$ipSegmento.'.%'; }
    $w=implode(' AND ',$where);
    $orden = strtoupper($ipOrden) === 'ASC' ? 'ASC' : 'DESC';
    $s=$db->prepare("SELECT e.*,t.nombre tipo_nombre,t.icono tipo_icono,a.nombre area_nombre,CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario_nombre, (SELECT COUNT(*) FROM mantenimientos m WHERE m.equipo_id=e.id) total_mantenimientos FROM equipos e LEFT JOIN tipos_equipo t ON t.id=e.tipo_id LEFT JOIN areas a ON a.id=e.area_id LEFT JOIN usuarios u ON u.id=e.usuario_id WHERE $w ORDER BY COALESCE(INET_ATON(e.ip), 4294967295) $orden, e.ip $orden, e.folio ASC");
    $s->execute($params); return $s->fetchAll();
}
function getEquipoById(int $id) {
    $s=getDB()->prepare("SELECT e.*,t.nombre tipo_nombre,a.nombre area_nombre,CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario_nombre FROM equipos e LEFT JOIN tipos_equipo t ON t.id=e.tipo_id LEFT JOIN areas a ON a.id=e.area_id LEFT JOIN usuarios u ON u.id=e.usuario_id WHERE e.id=?");
    $s->execute([$id]); return $s->fetch();
}

/** Datos para el modal de ficha técnica (lista / mis equipos). */
function equipoPayloadFichaModal(array $e): array {
    $inet = $e['tipo_internet'] ?? 'SIN_ACCESO';
    $inetLbl = ['SIN_ACCESO' => 'Sin acceso', 'RESTRINGIDO' => 'Restringido', 'COMPLETO' => 'Completo'][$inet] ?? $inet;
    $tieneInet = !empty($e['acceso_internet']);
    return [
        'id' => (int) ($e['id'] ?? 0),
        'folio' => $e['folio'] ?? '',
        'tipo_nombre' => $e['tipo_nombre'] ?? '',
        'tipo_icono' => $e['tipo_icono'] ?? 'bi-pc-display',
        'marca' => $e['marca'] ?? '',
        'modelo' => $e['modelo'] ?? '',
        'serie' => $e['serie'] ?? '',
        'estado' => $e['estado'] ?? '',
        'ip' => $e['ip'] ?? '',
        'mac_address' => $e['mac_address'] ?? '',
        'nodo' => $e['nodo'] ?? '',
        'conexion_medio' => $e['conexion_medio'] ?? '',
        'ubicacion' => $e['ubicacion'] ?? '',
        'area_nombre' => $e['area_nombre'] ?? '',
        'usuario_nombre' => trim((string) ($e['usuario_nombre'] ?? '')),
        'fecha_adquisicion' => $e['fecha_adquisicion'] ?? '',
        'descripcion' => $e['descripcion'] ?? '',
        'acceso_internet' => $tieneInet,
        'tipo_internet' => $inet,
        'tipo_internet_label' => $inetLbl,
        'ip_publica' => $e['ip_publica'] ?? '',
        'sistema_operativo' => $e['sistema_operativo'] ?? '',
        'procesador' => $e['procesador'] ?? '',
        'ram' => $e['ram'] ?? '',
        'almacenamiento' => $e['almacenamiento'] ?? '',
        'pantalla' => $e['pantalla'] ?? '',
        'tarjeta_video' => $e['tarjeta_video'] ?? '',
        'total_mantenimientos' => (int) ($e['total_mantenimientos'] ?? 0),
    ];
}
function saveEquipo(array $d, ?int $id=null): int {
    $db=getDB();
    // Reconstruir IP completa desde segmento + host
    if (!empty($d['ip_segmento']) && !empty($d['ip_host'])) {
        $d['ip'] = trim($d['ip_segmento']).'.'.trim($d['ip_host']);
    }
    // Checkbox acceso_internet: viene como "1" si marcado, ausente si no
    // Normalizar explícitamente a 1 o 0
    $d['acceso_internet'] = isset($d['acceso_internet']) && $d['acceso_internet'] ? 1 : 0;
    // Si no tiene acceso, forzar tipo SIN_ACCESO
    if (!$d['acceso_internet']) {
        $d['tipo_internet'] = 'SIN_ACCESO';
        $d['ip_publica']    = null;
    }

    try {
        $f = [
            'tipo_id','area_id','usuario_id','folio','marca','modelo','serie','descripcion',
        ];
        if (equiposTieneColumnasTecnicas()) {
            $f = array_merge($f, [
                'sistema_operativo','procesador','ram','almacenamiento','pantalla','tarjeta_video',
            ]);
        }
        $f = array_merge($f, [
            'ip','ip_segmento','segmento_id',
            'acceso_internet','tipo_internet','ip_publica',
            'mac_address','nodo','conexion_medio','ubicacion',
            'estado','fecha_adquisicion','motivo_baja','fecha_baja',
        ]);
        // Mapeo especial: acceso_internet es int, los demás string|null
        $v = array_map(function($k) use ($d) {
            if (!isset($d[$k]) || $d[$k] === '') return null;
            if ($k === 'acceso_internet') return (int)$d[$k];
            return trim((string)$d[$k]);
        }, $f);

        if ($id) {
            $set = implode(',', array_map(fn($k)=>"$k=?", $f));
            $db->prepare("UPDATE equipos SET $set WHERE id=?")->execute([...$v, $id]);
            return $id;
        }
        $db->prepare(
            "INSERT INTO equipos(".implode(',',$f).")VALUES(".implode(',',array_fill(0,count($f),'?')).")"
        )->execute($v);
        return (int)$db->lastInsertId();

    } catch (\Throwable) {
        // Fallback: columnas base sin v5/v6 (pre-migración)
        $f = ['tipo_id','area_id','usuario_id','folio','marca','modelo','serie','descripcion',
              'ip','mac_address','nodo','conexion_medio','ubicacion','estado','fecha_adquisicion','motivo_baja','fecha_baja'];
        $v = array_map(fn($k)=>($d[$k]??null)!==null&&$d[$k]!==''?trim((string)$d[$k]):null, $f);
        if ($id) {
            $set = implode(',', array_map(fn($k)=>"$k=?", $f));
            $db->prepare("UPDATE equipos SET $set WHERE id=?")->execute([...$v, $id]);
            return $id;
        }
        $db->prepare(
            "INSERT INTO equipos(".implode(',',$f).")VALUES(".implode(',',array_fill(0,count($f),'?')).")"
        )->execute($v);
        return (int)$db->lastInsertId();
    }
}
function deleteEquipo(int $id): void { getDB()->prepare("UPDATE equipos SET activo=0 WHERE id=?")->execute([$id]); }
function getResumenEquipos(): array {
    return getDB()->query("SELECT t.nombre tipo, COUNT(e.id) total, SUM(e.estado='ACTIVO') activos, SUM(e.estado='MANTENIMIENTO') mantenimiento, SUM(e.estado='BAJA') bajas FROM equipos e JOIN tipos_equipo t ON t.id=e.tipo_id WHERE e.activo=1 GROUP BY t.id ORDER BY total DESC")->fetchAll();
}

// ── Segmentos IP ──────────────────────────────────────────
function getSegmentosIP(): array {
    try {
        return getDB()->query("SELECT * FROM segmentos_ip WHERE activo=1 ORDER BY nombre")->fetchAll();
    } catch (\Throwable) { return []; }
}
function getSegmentoIPById(int $id) {
    $s = getDB()->prepare("SELECT * FROM segmentos_ip WHERE id=?");
    $s->execute([$id]); return $s->fetch();
}
function saveSegmentoIP(array $d, ?int $id=null): int {
    $db = getDB();
    $f  = ['nombre','red','mascara','gateway','dns1','dns2','vlan','descripcion','color'];
    $v  = array_map(fn($k)=>($d[$k]??null)?trim($d[$k]):null, $f);
    if ($id) {
        $set = implode(',', array_map(fn($k)=>"$k=?", $f));
        $db->prepare("UPDATE segmentos_ip SET $set WHERE id=?")->execute([...$v, $id]);
        return $id;
    }
    $db->prepare("INSERT INTO segmentos_ip(".implode(',',$f).")VALUES(".implode(',',array_fill(0,count($f),'?')).")")->execute($v);
    return (int)$db->lastInsertId();
}
function deleteSegmentoIP(int $id): void {
    getDB()->prepare("UPDATE segmentos_ip SET activo=0 WHERE id=?")->execute([$id]);
}

function getIpLibres(): array {
    try {
        $db = getDB();
        $segmentos = getSegmentosIP();
        $resultado = [];
        foreach ($segmentos as $seg) {
            $red  = $seg['red'];
            $used = $db->prepare(
                "SELECT COUNT(DISTINCT ip) FROM equipos
                 WHERE activo=1 AND ip IS NOT NULL AND ip != ''
                 AND (ip LIKE ? OR ip_segmento=?)"
            );
            $used->execute([$red.'.%', $red]);
            $usados  = (int)$used->fetchColumn();
            $resultado[] = [
                'id'     => $seg['id'],
                'nombre' => $seg['nombre'],
                'red'    => $red,
                'color'  => $seg['color'],
                'usadas' => $usados,
                'libres' => max(0, 253 - $usados),
                'total'  => 254,
            ];
        }
        return $resultado;
    } catch (\Throwable) { return []; }
}

function getIpLibresTotal(): int {
    try {
        $seg = getIpLibres();
        return $seg ? array_sum(array_column($seg, 'libres')) : 0;
    } catch (\Throwable) { return 0; }
}

function getIpsUsadasPorSegmento(string $red): array {
    try {
        $s = getDB()->prepare(
            "SELECT e.ip, e.folio, e.marca, e.modelo,
                    CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario
             FROM equipos e
             LEFT JOIN usuarios u ON u.id=e.usuario_id
             WHERE e.activo=1 AND e.ip IS NOT NULL AND e.ip!=''
               AND (e.ip LIKE ? OR e.ip_segmento=?)
             ORDER BY INET_ATON(e.ip)"
        );
        $s->execute([$red.'.%', $red]);
        return $s->fetchAll();
    } catch (\Throwable) {
        // Fallback: query without ip_segmento column (pre-migration)
        try {
            $s = getDB()->prepare(
                "SELECT e.ip, e.folio, e.marca, e.modelo,
                        CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario
                 FROM equipos e
                 LEFT JOIN usuarios u ON u.id=e.usuario_id
                 WHERE e.activo=1 AND e.ip IS NOT NULL AND e.ip!=''
                   AND e.ip LIKE ?
                 ORDER BY e.ip"
            );
            $s->execute([$red.'.%']);
            return $s->fetchAll();
        } catch (\Throwable) { return []; }
    }
}

function getIpsLibresPorSegmento(string $red): array {
    $red = trim(rtrim($red, '.'));
    if ($red === '') return [];
    
    $parts = explode('.', $red);
    if (count($parts) !== 3) return [];
    
    foreach ($parts as $p) {
        if (!ctype_digit((string) $p) || (int) $p < 0 || (int) $p > 255) {
            return [];
        }
    }
    
    $usadas = getIpsUsadasPorSegmento($red);
    $usedSet = [];
    foreach ($usadas as $row) {
        $full = trim((string) ($row['ip'] ?? ''));
        $bits = explode('.', $full);
        if (count($bits) === 4 && ctype_digit($bits[3])) {
            $usedSet[(int) $bits[3]] = true;
        }
    }
    
    $libres = [];
    for ($i = 2; $i <= 254; $i++) {
        if (!isset($usedSet[$i])) {
            $libres[] = [
                'ip' => $red . '.' . $i,
                'octeto' => $i
            ];
        }
    }
    
    return $libres;
}

/**
 * Otro equipo activo con el mismo folio (excluye id al editar).
 * @return array{id:int,folio:string,marca:?string,modelo:?string}|null
 */
function equipoConflictoPorFolio(string $folio, ?int $excludeEquipoId = null): ?array {
    $folio = trim($folio);
    if ($folio === '') {
        return null;
    }
    $db = getDB();
    $sql = 'SELECT id, folio, marca, modelo FROM equipos WHERE activo=1 AND folio=?';
    $params = [$folio];
    if ($excludeEquipoId) {
        $sql .= ' AND id<>?';
        $params[] = $excludeEquipoId;
    }
    $st = $db->prepare($sql . ' LIMIT 1');
    $st->execute($params);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Otro equipo activo con la misma IP (solo IPv4 válida).
 * @return array{id:int,folio:string,marca:?string,modelo:?string}|null
 */
function equipoConflictoPorIp(string $ip, ?int $excludeEquipoId = null): ?array {
    $ip = trim($ip);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return null;
    }
    $db = getDB();
    $sql = "SELECT id, folio, marca, modelo FROM equipos WHERE activo=1 AND ip IS NOT NULL AND ip<>'' AND ip=?";
    $params = [$ip];
    if ($excludeEquipoId) {
        $sql .= ' AND id<>?';
        $params[] = $excludeEquipoId;
    }
    $st = $db->prepare($sql . ' LIMIT 1');
    $st->execute($params);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * Primera IPv4 libre en una red /24 (prefijo "a.b.c"), solo equipos activos.
 * Prioriza 100–199, evita .0, .1 y .255.
 */
function sugerirIpLibreEnRed(string $red): ?string {
    $red = trim(rtrim($red, '.'));
    if ($red === '') {
        return null;
    }
    $parts = explode('.', $red);
    if (count($parts) !== 3) {
        return null;
    }
    foreach ($parts as $p) {
        if (!ctype_digit((string) $p) || (int) $p < 0 || (int) $p > 255) {
            return null;
        }
    }
    $usadas = getIpsUsadasPorSegmento($red);
    $usedOct = [];
    foreach ($usadas as $row) {
        $full = trim((string) ($row['ip'] ?? ''));
        $bits = explode('.', $full);
        if (count($bits) === 4 && ctype_digit($bits[3])) {
            $usedOct[(int) $bits[3]] = true;
        }
    }
    $orden = array_merge(range(100, 199), range(10, 99), range(200, 254), range(2, 9));
    foreach ($orden as $h) {
        if ($h === 1 || $h === 255) {
            continue;
        }
        if (!isset($usedOct[$h])) {
            return $red . '.' . $h;
        }
    }
    return null;
}

// ════════════════════════════════════════════════════════
//  BITÁCORA DE INCIDENTES
// ════════════════════════════════════════════════════════
function nextFolioBitacora(): string {
    try {
        $n = getDB()->query("SELECT COUNT(*)+1 FROM bitacora")->fetchColumn();
        return 'INC-'.date('Y').'-'.str_pad((int)$n, 4, '0', STR_PAD_LEFT);
    } catch (\Throwable) {
        return 'INC-'.date('Y').'-'.str_pad(1, 4, '0', STR_PAD_LEFT);
    }
}

function getBitacora(string $q='', string $tipo='', string $gravedad='', string $estado='', int $limit=0): array {
    try {
        $db = getDB(); $where = ['b.activo=1']; $params = [];
        if ($q) {
            $like = '%'.$q.'%';
            $where[] = "(b.folio LIKE ? OR b.titulo LIKE ? OR b.descripcion LIKE ? OR b.reportado_por LIKE ? OR b.tecnico_atiende LIKE ? OR b.personal_responsable LIKE ?)";
            $params  = array_merge($params, array_fill(0, 6, $like));
        }
        if ($tipo)     { $where[] = "b.tipo=?";     $params[] = $tipo; }
        if ($gravedad) { $where[] = "b.gravedad=?"; $params[] = $gravedad; }
        if ($estado)   { $where[] = "b.estado=?";   $params[] = $estado; }
        $w   = implode(' AND ', $where);
        $lim = $limit ? "LIMIT $limit" : '';
        $s   = $db->prepare(
            "SELECT b.*, su.nombre_completo registrado_por_nombre
             FROM bitacora b
             LEFT JOIN system_users su ON su.id=b.suser_id
             WHERE $w
             ORDER BY FIELD(b.gravedad,'CRITICO','GRAVE','MODERADO','MENOR','INFORMATIVO'),
                      b.fecha_inicio DESC $lim"
        );
        $s->execute($params);
        return $s->fetchAll();
    } catch (\Throwable) { return []; }
}

function getBitacoraById(int $id): array|false {
    $s = getDB()->prepare(
        "SELECT b.*, su.nombre_completo registrado_por_nombre
         FROM bitacora b
         LEFT JOIN system_users su ON su.id=b.suser_id
         WHERE b.id=?"
    );
    $s->execute([$id]);
    return $s->fetch();
}

function getEquiposAfectados(int $bitacoraId): array {
    $s = getDB()->prepare(
        "SELECT be.*, e.folio, e.marca, e.modelo, t.nombre tipo_nombre
         FROM bitacora_equipos be
         JOIN equipos e ON e.id=be.equipo_id
         LEFT JOIN tipos_equipo t ON t.id=e.tipo_id
         WHERE be.bitacora_id=?"
    );
    $s->execute([$bitacoraId]);
    return $s->fetchAll();
}

function saveBitacora(array $d, array $equiposAfectados=[], ?int $id=null): int {
    $db = getDB();
    $f  = ['tipo','gravedad','estado','titulo','descripcion','causa_probable',
           'acciones_tomadas','recomendaciones','sistemas_afectados',
           'sin_grabacion','tiempo_sin_grabacion','equipos_dañados',
           'afecta_site','afecta_camaras','afecta_red','afecta_servidores','perdida_datos',
           'reportado_por','personal_responsable','tipo_personal','tecnico_atiende','suser_id',
           'fecha_inicio','fecha_fin','evidencia_fotos','num_fotos','notas_adicionales'];
    // Calcular duración
    if (!empty($d['fecha_inicio']) && !empty($d['fecha_fin'])) {
        $diff = strtotime($d['fecha_fin']) - strtotime($d['fecha_inicio']);
        $d['duracion_minutos'] = max(0, (int)round($diff/60));
    }
    $v = array_map(fn($k) => isset($d[$k]) && $d[$k]!=='' ? trim((string)$d[$k]) : null, $f);

    if ($id) {
        $set = implode(',', array_map(fn($k)=>"$k=?", $f));
        // Recalcular duración
        if (!empty($d['fecha_inicio']) && !empty($d['fecha_fin'])) {
            $db->prepare("UPDATE bitacora SET $set,duracion_minutos=? WHERE id=?")->execute([...$v, $d['duracion_minutos']??null, $id]);
        } else {
            $db->prepare("UPDATE bitacora SET $set WHERE id=?")->execute([...$v, $id]);
        }
    } else {
        $folio = nextFolioBitacora();
        $cols  = 'folio,'.implode(',', $f).(!empty($d['duracion_minutos'])?',duracion_minutos':'');
        $ph    = '?,'.implode(',', array_fill(0,count($f),'?')).(!empty($d['duracion_minutos'])?',?':'');
        $vals  = [$folio, ...$v, ...(!empty($d['duracion_minutos'])?[$d['duracion_minutos']]:[])];
        $db->prepare("INSERT INTO bitacora($cols)VALUES($ph)")->execute($vals);
        $id = (int)$db->lastInsertId();
    }

    // Guardar equipos afectados
    if (!empty($equiposAfectados)) {
        $db->prepare("DELETE FROM bitacora_equipos WHERE bitacora_id=?")->execute([$id]);
        foreach ($equiposAfectados as $ea) {
            if (empty($ea['equipo_id'])) continue;
            $db->prepare(
                "INSERT INTO bitacora_equipos(bitacora_id,equipo_id,daño_nivel,descripcion)VALUES(?,?,?,?)"
            )->execute([$id, $ea['equipo_id'], $ea['daño_nivel']??'SIN_DAÑO', $ea['descripcion']??'']);
        }
    }
    return $id;
}

function deleteBitacora(int $id): void {
    getDB()->prepare("UPDATE bitacora SET activo=0 WHERE id=?")->execute([$id]);
}

function getEstadisticasBitacora(): array {
    try {
        $db = getDB();
        return [
            'total'       => $db->query("SELECT COUNT(*) FROM bitacora WHERE activo=1")->fetchColumn(),
            'abiertos'    => $db->query("SELECT COUNT(*) FROM bitacora WHERE activo=1 AND estado IN('ABIERTO','EN_SEGUIMIENTO')")->fetchColumn(),
            'criticos'    => $db->query("SELECT COUNT(*) FROM bitacora WHERE activo=1 AND gravedad IN('CRITICO','GRAVE')")->fetchColumn(),
            'mes_actual'  => $db->query("SELECT COUNT(*) FROM bitacora WHERE activo=1 AND MONTH(fecha_inicio)=MONTH(NOW()) AND YEAR(fecha_inicio)=YEAR(NOW())")->fetchColumn(),
            'por_tipo'    => $db->query("SELECT tipo, COUNT(*) total FROM bitacora WHERE activo=1 GROUP BY tipo ORDER BY total DESC")->fetchAll(),
            'por_gravedad'=> $db->query("SELECT gravedad, COUNT(*) total FROM bitacora WHERE activo=1 GROUP BY gravedad")->fetchAll(),
        ];
    } catch (\Throwable) {
        return ['total'=>0,'abiertos'=>0,'criticos'=>0,'mes_actual'=>0,'por_tipo'=>[],'por_gravedad'=>[]];
    }
}

// ════════════════════════════════════════════════════════
//  INVENTARIO
// ════════════════════════════════════════════════════════
// ════════════════════════════════════════════════════════
//  ASIGNACIONES DE EQUIPO (historial de reemplazos)
// ════════════════════════════════════════════════════════
function getAsignaciones(int $usuarioId=0, int $equipoId=0): array {
    try {
        $db = getDB();
        $where = ['ea.activo=1']; $params = [];
        if ($usuarioId) { $where[] = 'ea.usuario_id=?'; $params[] = $usuarioId; }
        if ($equipoId)  { $where[] = 'ea.equipo_id=?';  $params[] = $equipoId; }
        $w = implode(' AND ', $where);
        $s = $db->prepare(
            "SELECT ea.*,
                    e.folio   equipo_folio,  e.marca equipo_marca, e.modelo equipo_modelo,
                    e.ip      equipo_ip,     e.acceso_internet, e.tipo_internet equipo_tipo_internet,
                    CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario_nombre
             FROM equipo_asignaciones ea
             JOIN equipos  e ON e.id = ea.equipo_id
             JOIN usuarios u ON u.id = ea.usuario_id
             WHERE $w
             ORDER BY ea.es_activa DESC, ea.fecha_inicio DESC"
        );
        $s->execute($params);
        return $s->fetchAll();
    } catch (\Throwable) { return []; }
}

/*function registrarAsignacion(array $d): int|false {
    try {
        $db = getDB();
        $equipoId        = (int)($d['equipo_id']        ?? 0);
        $usuarioId       = (int)($d['usuario_id']        ?? 0);
        $equipoAntId     = (int)($d['equipo_anterior_id']?? 0) ?: null;
        if (!$equipoId || !$usuarioId) return false;

        // Snapshot del folio anterior
        $folioAnterior = null;
        if ($equipoAntId) {
            $eq = getDB()->prepare("SELECT folio FROM equipos WHERE id=?");
            $eq->execute([$equipoAntId]);
            $folioAnterior = $eq->fetchColumn() ?: null;
        }

        // Cerrar asignaciones activas previas del usuario sobre este equipo (si es definitivo)
        $tipo = $d['tipo'] ?? 'ASIGNACION';
        if (in_array($tipo, ['REEMPLAZO_DEFINITIVO','DEVOLUCION','REASIGNACION'])) {
            $db->prepare(
                "UPDATE equipo_asignaciones SET es_activa=0, fecha_fin=? WHERE usuario_id=? AND es_activa=1"
            )->execute([date('Y-m-d'), $usuarioId]);
        }

        // Insertar nueva asignación
        $db->prepare(
            "INSERT INTO equipo_asignaciones
                (equipo_id, usuario_id, tipo, motivo, descripcion,
                 equipo_anterior_id, folio_anterior,
                 fecha_inicio, fecha_fin, es_activa,
                 registrado_por, observaciones)
             VALUES (?,?,?,?,?,?,?,?,?,1,?,?)"
        )->execute([
            $equipoId,
            $usuarioId,
            $tipo,
            $d['motivo']       ?? 'PRIMERA_ASIGNACION',
            $d['descripcion']  ?? '',
            $equipoAntId,
            $folioAnterior,
            $d['fecha_inicio'] ?? date('Y-m-d'),
            ($d['fecha_fin']   ?? '') ?: null,
            $d['registrado_por'] ?? '',
            $d['observaciones']  ?? '',
        ]);
        $newId = (int)$db->lastInsertId();

        // Si es asignación/reemplazo definitivo, actualizar usuario_id en equipos
        if (in_array($tipo, ['ASIGNACION','REEMPLAZO_DEFINITIVO','REASIGNACION'])) {
            $db->prepare("UPDATE equipos SET usuario_id=? WHERE id=?")->execute([$usuarioId, $equipoId]);
        }
        // Si es devolución, liberar el equipo
        if ($tipo === 'DEVOLUCION') {
            $db->prepare("UPDATE equipos SET usuario_id=NULL WHERE id=?")->execute([$equipoId]);
        }

        return $newId;
    } catch (\Throwable $e) {
        error_log('registrarAsignacion error: '.$e->getMessage());
        return false;
    }
}*/

function getInventario(string $q='', int $equipo=0, int $usuario=0, string $estado=''): array {
    $db=getDB(); $where=['i.activo=1']; $params=[];
    if($q){ $like='%'.$q.'%'; $where[]="(i.tipo LIKE ? OR i.marca LIKE ? OR i.modelo LIKE ? OR i.serie LIKE ? OR u.nombre LIKE ? OR u.apellidos LIKE ?)"; $params=array_merge($params,array_fill(0,6,$like)); }
    if($equipo) { $where[]="i.equipo_id=?"; $params[]=$equipo; }
    if($usuario){ $where[]="i.usuario_id=?"; $params[]=$usuario; }
    if($estado){ $where[]="i.estado=?"; $params[]=$estado; }
    $w=implode(' AND ',$where);
    $s=$db->prepare("SELECT i.*,e.folio equipo_folio,e.marca equipo_marca,CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario_nombre,a.nombre area_nombre FROM inventario i LEFT JOIN equipos e ON e.id=i.equipo_id LEFT JOIN usuarios u ON u.id=i.usuario_id LEFT JOIN areas a ON a.id=i.area_id WHERE $w ORDER BY i.tipo,i.marca");
    $s->execute($params); return $s->fetchAll();
}
function getInventarioById(int $id) { $s=getDB()->prepare("SELECT * FROM inventario WHERE id=?"); $s->execute([$id]); return $s->fetch(); }
function saveInventario(array $d, ?int $id=null): int {
    $db=getDB();
    $f=['equipo_id','usuario_id','area_id','tipo','marca','modelo','serie','cantidad','estado','motivo_baja','fecha_baja','notas'];
    $v=array_map(fn($k)=>($d[$k]??null)?trim($d[$k]):null,$f);
    if($id){ $set=implode(',',array_map(fn($k)=>"$k=?",$f)); $db->prepare("UPDATE inventario SET $set WHERE id=?")->execute([...$v,$id]); return $id; }
    $db->prepare("INSERT INTO inventario(".implode(',',$f).")VALUES(".implode(',',array_fill(0,count($f),'?')).")")->execute($v);
    return (int)$db->lastInsertId();
}
function deleteInventario(int $id): void { getDB()->prepare("UPDATE inventario SET activo=0 WHERE id=?")->execute([$id]); }

// ════════════════════════════════════════════════════════
//  TICKETS
// ════════════════════════════════════════════════════════
function getTickets(string $q='', string $estado='', string $prioridad=''): array {
    $db=getDB(); $where=['1=1']; $params=[];
    if($q){ $like='%'.$q.'%'; $where[]="(t.folio_ticket LIKE ? OR e.folio LIKE ? OR e.marca LIKE ? OR CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) LIKE ? OR t.descripcion LIKE ? OR t.tecnico LIKE ?)"; $params=array_merge($params,array_fill(0,6,$like)); }
    if($estado)   { $where[]="t.estado=?";    $params[]=$estado; }
    if($prioridad){ $where[]="t.prioridad=?"; $params[]=$prioridad; }
    $w=implode(' AND ',$where);
    $s=$db->prepare(
        "SELECT t.*,
                e.folio equipo_folio, e.marca equipo_marca, e.modelo equipo_modelo,
                CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario_nombre,
                su_asig.nombre_completo supervisor_asignado_nombre
         FROM tickets t
         LEFT JOIN equipos e ON e.id=t.equipo_id
         LEFT JOIN usuarios u ON u.id=t.usuario_id
         LEFT JOIN system_users su_asig ON su_asig.id=t.asignado_a_suser
         WHERE $w
         ORDER BY FIELD(t.prioridad,'URGENTE','ALTA','MEDIA','BAJA'), t.fecha_apertura DESC"
    );
    $s->execute($params); return $s->fetchAll();
}
function getTicketById(int $id) {
    $s=getDB()->prepare(
        "SELECT t.*,
                e.folio equipo_folio, e.marca equipo_marca, e.modelo equipo_modelo,
                CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario_nombre,
                su_asig.nombre_completo supervisor_asignado_nombre,
                su_sol.nombre_completo  solicitante_nombre
         FROM tickets t
         LEFT JOIN equipos e ON e.id=t.equipo_id
         LEFT JOIN usuarios u ON u.id=t.usuario_id
         LEFT JOIN system_users su_asig ON su_asig.id=t.asignado_a_suser
         LEFT JOIN system_users su_sol  ON su_sol.id=t.solicitante_suser
         WHERE t.id=?"
    );
    $s->execute([$id]); return $s->fetch();
}
function saveTicket(array $d, ?int $id=null): int {
    $db=getDB();
    if ($id) {
        // UPDATE: campos editables — NO sobreescribir asignación ni solicitante
        $f = ['equipo_id','usuario_id','tipo_servicio','prioridad','estado',
              'descripcion','tecnico','fecha_cierre'];
        $v = array_map(fn($k)=>($d[$k]??null)?trim($d[$k]):null, $f);
        $set = implode(',', array_map(fn($k)=>"$k=?", $f));
        $db->prepare("UPDATE tickets SET $set WHERE id=?")->execute([...$v, $id]);
        return $id;
    }
    // INSERT nuevo ticket — incluir solicitante si viene en los datos
    $f = ['equipo_id','usuario_id','tipo_servicio','prioridad','estado',
          'descripcion','tecnico','solicitante_suser','fecha_apertura'];
    $d['fecha_apertura'] = $d['fecha_apertura'] ?? date('Y-m-d H:i:s');
    $v = array_map(fn($k)=>($d[$k]??null)?trim($d[$k]):null, $f);
    $sql = "INSERT INTO tickets(folio_ticket,".implode(',',$f).")VALUES(?,".implode(',',array_fill(0,count($f),'?')).")";
    for ($i = 0; $i < 3; $i++) {
        $folio = nextFolioTicket();
        try {
            $db->prepare($sql)->execute([$folio, ...$v]);
            return (int)$db->lastInsertId();
        } catch (\PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'folio_ticket') !== false && strpos($msg, '1062') !== false) {
                continue;
            }
            throw $e;
        }
    }
    throw new \RuntimeException('No fue posible generar un folio único para el ticket.');
}
function deleteTicket(int $id): void { getDB()->prepare("DELETE FROM tickets WHERE id=?")->execute([$id]); }
function getEstadisticasTickets(): array {
    return getDB()->query("SELECT estado,COUNT(*) total FROM tickets GROUP BY estado")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// ════════════════════════════════════════════════════════
//  MANTENIMIENTOS
// ════════════════════════════════════════════════════════
function getMantenimientos(int $equipo=0): array {
    $db=getDB(); $w=$equipo?"WHERE m.equipo_id=$equipo":"";
    return $db->query("SELECT m.*,e.folio equipo_folio,e.marca,e.modelo,CONCAT(u.nombre,' ',IFNULL(u.apellidos,'')) usuario_nombre FROM mantenimientos m LEFT JOIN equipos e ON e.id=m.equipo_id LEFT JOIN usuarios u ON u.id=m.usuario_id $w ORDER BY m.fecha DESC")->fetchAll();
}
function getMantenimientoById(int $id) {
    $s=getDB()->prepare("SELECT m.*,e.folio equipo_folio,e.marca,e.modelo FROM mantenimientos m LEFT JOIN equipos e ON e.id=m.equipo_id WHERE m.id=?");
    $s->execute([$id]); return $s->fetch();
}
function saveMantenimiento(array $d, array $checks, ?int $id=null): int {
    $db=getDB();
    $f=['ticket_id','equipo_id','usuario_id','tecnico','tipo','fecha','observaciones'];
    $v=array_map(fn($k)=>($d[$k]??null)?trim($d[$k]):null,$f);
    if($id){
        $set=implode(',',array_map(fn($k)=>"$k=?",$f));
        $db->prepare("UPDATE mantenimientos SET $set WHERE id=?")->execute([...$v,$id]);
        $db->prepare("DELETE FROM mantenimiento_checks WHERE mantenimiento_id=?")->execute([$id]);
    } else {
        $db->prepare("INSERT INTO mantenimientos(".implode(',',$f).")VALUES(".implode(',',array_fill(0,count($f),'?')).")")->execute($v);
        $id=(int)$db->lastInsertId();
    }
    foreach($checks as $c){
        $db->prepare("INSERT INTO mantenimiento_checks(mantenimiento_id,categoria,item,resultado,notas)VALUES(?,?,?,?,?)")->execute([$id,$c['categoria'],$c['item'],$c['resultado'],$c['notas']??'']);
    }
    return $id;
}
function getChecks(int $mId): array {
    $s=getDB()->prepare("SELECT * FROM mantenimiento_checks WHERE mantenimiento_id=? ORDER BY categoria,id");
    $s->execute([$mId]); return $s->fetchAll();
}

// ────────────────────────────────────────────────────────
//  Template de checklist de mantenimiento
// ────────────────────────────────────────────────────────
function getChecklistTemplate(): array {
    return [
        'LIMPIEZA FÍSICA' => [
            'Limpieza exterior del equipo (carcasa/chasis)',
            'Limpieza de teclado y mouse',
            'Limpieza de monitor/pantalla',
            'Limpieza interna (CPU/ventiladores/slots)',
            'Limpieza de lector óptico/DVD',
            'Estado físico del cable de corriente',
            'Estado físico del cable de red/datos',
            'Revisión de puertos USB y conectores',
            'Revisión de estado del hardware (golpes/daños físicos)',
            'Ventiladores en buen estado y funcionando',
            'Memoria RAM en buen estado (sin corrosión)',
            'Disco duro/SSD sin daños físicos',
        ],
        'LIMPIEZA LÓGICA' => [
            'Actualización de Windows / SO',
            'Drivers actualizados',
            'Punto de recuperación creado',
            'Eliminación de programas innecesarios',
            'CCleaner / limpieza de temporales',
            'PDF/Acrobat Reader',
            'Programa de optimización ejecutado',
            'Espacio en disco suficiente (>15%)',
            'Windows activo/licenciado',
            'Office 2013/365 activo',
            'Lafayette instalado y configurado',
            'Servicios Desactivados',
            'DigiScan',
            'AAMY/TMW/NOMACHINE instalado y configurado',
            'Bitlocker/cifrado estado revisado',
        ],
        'HARDWARE / PERIFÉRICOS' => [
            'Monitor encendiendo correctamente',
            'Teclado funcionando',
            'CPU interno funcionando',
            'Ventiladores correcto funcionamiento',
            'USB/FDD no bloqueado',
            'Cables en buen estado',
            'Memoria RAM detectada correctamente',
            'Periféricos detectados por el SO',
            'Red/Ethernet funcionando',
            'Sonido funcionando',
            'Cámara web funcionando (si aplica)',
        ],
        'SEGURIDAD / RED' => [
            'IP asignada correctamente según inventario',
            'MAC registrada en base de datos',
            'Pertenece al nodo/switch correcto',
            'Firewall activo',
            'Acceso remoto verificado',
            'Usuario de Windows configurado correctamente',
            'Contraseña de usuario vigente',
            'Restricciones de USB configuradas',
        ],
    ];
}

// ════════════════════════════════════════════════════════
//  ESTADÍSTICAS DASHBOARD
// ════════════════════════════════════════════════════════
function getDashboardStats(): array {
    $db=getDB();
    try { $bitStats = getEstadisticasBitacora(); } catch(\Throwable) { $bitStats=['abiertos'=>0,'mes_actual'=>0]; }
    try { $ipLibres = getIpLibresTotal(); } catch(\Throwable) { $ipLibres = 0; }
    try { $segmentos = getIpLibres(); } catch(\Throwable) { $segmentos = []; }
    try { $resumen = getResumenEquipos(); } catch(\Throwable) { $resumen = []; }
    try { $tktStats = getEstadisticasTickets(); } catch(\Throwable) { $tktStats = []; }
    return [
        'equipos_total'       => $db->query("SELECT COUNT(*) FROM equipos WHERE activo=1")->fetchColumn(),
        'equipos_activos'     => $db->query("SELECT COUNT(*) FROM equipos WHERE activo=1 AND estado='ACTIVO'")->fetchColumn(),
        'equipos_mant'        => $db->query("SELECT COUNT(*) FROM equipos WHERE activo=1 AND estado='MANTENIMIENTO'")->fetchColumn(),
        'usuarios_total'      => $db->query("SELECT COUNT(*) FROM usuarios WHERE activo=1")->fetchColumn(),
        'tickets_abiertos'    => $db->query("SELECT COUNT(*) FROM tickets WHERE estado='ABIERTO'")->fetchColumn(),
        'tickets_proceso'     => $db->query("SELECT COUNT(*) FROM tickets WHERE estado='EN_PROCESO'")->fetchColumn(),
        'inventario_total'    => $db->query("SELECT COALESCE(SUM(cantidad),0) FROM inventario WHERE activo=1")->fetchColumn(),
        'mantenimientos_mes'  => $db->query("SELECT COUNT(*) FROM mantenimientos WHERE MONTH(fecha)=MONTH(NOW()) AND YEAR(fecha)=YEAR(NOW())")->fetchColumn(),
        'ip_libres'           => $ipLibres,
        'segmentos_ip'        => $segmentos,
        'resumen_equipos'     => $resumen,
        'tickets_stats'       => $tktStats,
        'incidentes_abiertos' => $bitStats['abiertos'],
        'incidentes_mes'      => $bitStats['mes_actual'],
    ];
}
function registrarAsignacion(array $data): int|array|false {
    $db = getDB();

    $equipoId      = (int)($data['equipo_id'] ?? 0);
    $usuarioId     = (int)($data['usuario_id'] ?? 0);
    $tipo          = $data['tipo'] ?? 'ASIGNACION';
    $motivo        = $data['motivo'] ?? 'OTRO';
    $descripcion   = trim($data['descripcion'] ?? '');
    $observaciones = trim($data['observaciones'] ?? '');
    $fechaInicio   = $data['fecha_inicio'] ?? date('Y-m-d');
    $fechaFin      = !empty($data['fecha_fin']) ? $data['fecha_fin'] : null;
    $registradoPor = $data['registrado_por'] ?? 'Sistema';
    $eqAnteriorId  = (int)($data['equipo_anterior_id'] ?? 0) ?: null;

    if (!$equipoId || !$usuarioId) return false;

    // Verificar si el equipo está ACTIVO y asignado a otro usuario
    $s = $db->prepare("SELECT id, estado, usuario_id, folio, marca, modelo FROM equipos WHERE id=?");
    $s->execute([$equipoId]);
    $equipo = $s->fetch();
    
    if ($equipo && $equipo['estado'] === 'ACTIVO' && $equipo['usuario_id'] && $equipo['usuario_id'] != $usuarioId) {
        return ['error' => 'activo', 'equipo' => $equipo];
    }

    // Obtener folio del equipo anterior si aplica
    $folioAnterior = null;
    if ($eqAnteriorId) {
        $s = $db->prepare("SELECT folio FROM equipos WHERE id=?");
        $s->execute([$eqAnteriorId]);
        $folioAnterior = $s->fetchColumn() ?: null;
    }

    // Cerrar asignaciones activas previas del equipo
    $db->prepare(
        "UPDATE equipo_asignaciones SET es_activa=0, fecha_fin=? WHERE equipo_id=? AND es_activa=1"
    )->execute([$fechaInicio, $equipoId]);

    // Insertar nueva asignación
    $stmt = $db->prepare("
        INSERT INTO equipo_asignaciones
            (equipo_id, usuario_id, tipo, motivo, descripcion, equipo_anterior_id,
             folio_anterior, fecha_inicio, fecha_fin, es_activa, registrado_por, observaciones)
        VALUES (?,?,?,?,?,?,?,?,?,1,?,?)
    ");
    $stmt->execute([
        $equipoId, $usuarioId, $tipo, $motivo, $descripcion,
        $eqAnteriorId, $folioAnterior, $fechaInicio, $fechaFin,
        $registradoPor, $observaciones
    ]);

    $newId = (int)$db->lastInsertId();

    // Actualizar usuario_id en la tabla equipos
    $db->prepare("UPDATE equipos SET usuario_id=? WHERE id=?")->execute([$usuarioId, $equipoId]);

    // Si hay equipo anterior, marcarlo como INACTIVO
    if ($eqAnteriorId) {
        $db->prepare("UPDATE equipos SET estado='INACTIVO', usuario_id=NULL WHERE id=?")->execute([$eqAnteriorId]);
    }

    return $newId;
}
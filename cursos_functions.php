<?php
// ═══════════════════════════════════════════════════════════════
//  FUNCIONES MÓDULO CURSOS — agregar al final de includes/functions.php
// ═══════════════════════════════════════════════════════════════

// ── Cursos ───────────────────────────────────────────────────────────────────

function getCursos(string $q = '', string $estado = ''): array {
    $db   = getDB();
    $sql  = "SELECT c.*,
                    su.nombre_completo creado_por_nombre,
                    COUNT(DISTINCT cp.id) total_participantes
             FROM cursos c
             LEFT JOIN system_users su ON su.id = c.creado_por
             LEFT JOIN curso_participantes cp ON cp.curso_id = c.id
             WHERE c.activo = 1";
    $params = [];
    if ($q)      { $sql .= " AND c.nombre LIKE ?"; $params[] = "%$q%"; }
    if ($estado) { $sql .= " AND c.estado = ?";    $params[] = $estado; }
    $sql .= " GROUP BY c.id ORDER BY c.fecha_inicio DESC";
    $s = $db->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

function getCursoById(int $id): array|false {
    $s = getDB()->prepare(
        "SELECT c.*, su.nombre_completo creado_por_nombre
         FROM cursos c
         LEFT JOIN system_users su ON su.id = c.creado_por
         WHERE c.id = ? AND c.activo = 1"
    );
    $s->execute([$id]);
    return $s->fetch();
}

function saveCurso(array $d, ?int $id = null): int {
    $db = getDB();
    $fields = ['nombre','descripcion','plataforma','plataforma_url',
               'fecha_inicio','fecha_fin','estado'];
    $vals = [];
    foreach ($fields as $f) {
        $v = isset($d[$f]) && $d[$f] !== '' ? trim($d[$f]) : null;
        $vals[] = $v;
    }
    if ($id) {
        $set = implode(',', array_map(fn($f) => "$f=?", $fields));
        $db->prepare("UPDATE cursos SET $set WHERE id=?")->execute([...$vals, $id]);
        return $id;
    }
    $vals[] = (int)($_SESSION['auth']['id'] ?? 0) ?: null;
    $cols = implode(',', $fields) . ',creado_por';
    $ph   = implode(',', array_fill(0, count($fields) + 1, '?'));
    $db->prepare("INSERT INTO cursos ($cols) VALUES ($ph)")->execute($vals);
    return (int)$db->lastInsertId();
}

function deleteCurso(int $id): void {
    getDB()->prepare("UPDATE cursos SET activo=0 WHERE id=?")->execute([$id]);
}

// ── Participantes ────────────────────────────────────────────────────────────

function getParticipantesByCurso(int $cursoId): array {
    $s = getDB()->prepare(
        "SELECT cp.*, u.nombre, u.apellidos, u.usuario_windows,
                u.puesto, u.area_id, a.nombre area_nombre,
                u.email, u.foto
         FROM curso_participantes cp
         JOIN usuarios u ON u.id = cp.usuario_id
         LEFT JOIN areas a ON a.id = u.area_id
         WHERE cp.curso_id = ?
         ORDER BY cp.estado, u.nombre"
    );
    $s->execute([$cursoId]);
    return $s->fetchAll();
}

function getCursosDeUsuario(int $usuarioId): array {
    $s = getDB()->prepare(
        "SELECT c.*, cp.estado part_estado, cp.fecha_acceso
         FROM curso_participantes cp
         JOIN cursos c ON c.id = cp.curso_id
         WHERE cp.usuario_id = ?
           AND c.activo = 1
           AND cp.estado != 'BAJA'
         ORDER BY c.fecha_inicio DESC"
    );
    $s->execute([$usuarioId]);
    return $s->fetchAll();
}

function agregarParticipante(int $cursoId, int $usuarioId): void {
    $db = getDB();
    // Evitar duplicado
    $s = $db->prepare("SELECT id FROM curso_participantes WHERE curso_id=? AND usuario_id=?");
    $s->execute([$cursoId, $usuarioId]);
    if ($s->fetch()) return;
    $db->prepare(
        "INSERT INTO curso_participantes (curso_id, usuario_id, estado) VALUES (?,?,'PENDIENTE')"
    )->execute([$cursoId, $usuarioId]);
}

function quitarParticipante(int $cursoId, int $usuarioId): void {
    getDB()->prepare(
        "UPDATE curso_participantes SET estado='BAJA' WHERE curso_id=? AND usuario_id=?"
    )->execute([$cursoId, $usuarioId]);
}

function actualizarEstadoParticipante(int $cursoId, int $usuarioId, string $estado): void {
    getDB()->prepare(
        "UPDATE curso_participantes SET estado=? WHERE curso_id=? AND usuario_id=?"
    )->execute([$estado, $cursoId, $usuarioId]);
}

// ── Materiales ───────────────────────────────────────────────────────────────

function getMaterialesByCurso(int $cursoId): array {
    $s = getDB()->prepare(
        "SELECT * FROM curso_materiales
         WHERE curso_id = ? AND activo = 1
         ORDER BY orden, id"
    );
    $s->execute([$cursoId]);
    return $s->fetchAll();
}

function saveMaterial(array $d, ?int $id = null): int {
    $db     = getDB();
    $fields = ['curso_id','tipo','titulo','descripcion','url',
               'archivo_path','archivo_nombre','orden'];
    $vals   = array_map(fn($f) => isset($d[$f]) && $d[$f] !== '' ? trim($d[$f]) : null, $fields);
    if ($id) {
        $set = implode(',', array_map(fn($f) => "$f=?", $fields));
        $db->prepare("UPDATE curso_materiales SET $set WHERE id=?")->execute([...$vals, $id]);
        return $id;
    }
    $cols = implode(',', $fields);
    $ph   = implode(',', array_fill(0, count($fields), '?'));
    $db->prepare("INSERT INTO curso_materiales ($cols) VALUES ($ph)")->execute($vals);
    return (int)$db->lastInsertId();
}

function deleteMaterial(int $id): void {
    getDB()->prepare("UPDATE curso_materiales SET activo=0 WHERE id=?")->execute([$id]);
}

// ── Sesiones de usuarios (login independiente) ───────────────────────────────

function loginCursoUsuario(string $usuarioWindows, string $password): array|false {
    $s = getDB()->prepare(
        "SELECT u.*, a.nombre area_nombre
         FROM usuarios u
         LEFT JOIN areas a ON a.id = u.area_id
         WHERE u.usuario_windows = ?
           AND u.activo = 1
         LIMIT 1"
    );
    $s->execute([trim($usuarioWindows)]);
    $u = $s->fetch();
    if (!$u) return false;

    // contrasena_ref puede ser hash bcrypt o texto plano (migración)
    $ref = $u['contrasena_ref'] ?? '';
    $ok  = (strlen($ref) > 40 && str_starts_with($ref, '$'))
        ? password_verify($password, $ref)
        : ($password === $ref);
    if (!$ok) return false;

    // Crear token de sesión
    $token = bin2hex(random_bytes(64));
    $exp   = date('Y-m-d H:i:s', strtotime('+8 hours'));
    getDB()->prepare(
        "INSERT INTO curso_sesiones (usuario_id, token, ip, expires_at)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE token=VALUES(token), expires_at=VALUES(expires_at), ip=VALUES(ip)"
    )->execute([$u['id'], $token, $_SERVER['REMOTE_ADDR'] ?? '', $exp]);

    return ['token' => $token, 'usuario' => $u];
}

function getCursoSesion(string $token): array|false {
    if (!$token) return false;
    $s = getDB()->prepare(
        "SELECT cs.*, u.id uid, u.nombre, u.apellidos, u.usuario_windows,
                u.puesto, u.email, u.foto, u.activo u_activo,
                a.nombre area_nombre
         FROM curso_sesiones cs
         JOIN usuarios u ON u.id = cs.usuario_id
         LEFT JOIN areas a ON a.id = u.area_id
         WHERE cs.token = ?
           AND cs.expires_at > NOW()
           AND u.activo = 1"
    );
    $s->execute([$token]);
    return $s->fetch();
}

function logoutCursoUsuario(string $token): void {
    getDB()->prepare("DELETE FROM curso_sesiones WHERE token=?")->execute([$token]);
}

// ── Helpers ──────────────────────────────────────────────────────────────────

function cursoPlataformaBadge(string $plataforma): string {
    $map = [
        'ZOOM'  => ['#2D8CFF', 'bi-camera-video-fill', 'Zoom'],
        'TEAMS' => ['#6264A7', 'bi-microsoft-teams',   'Teams'],
        'MEET'  => ['#00897B', 'bi-google',            'Meet'],
        'OTRO'  => ['#6c757d', 'bi-globe',             'Otro'],
    ];
    [$bg, $ico, $lbl] = $map[$plataforma] ?? $map['OTRO'];
    return "<span class=\"badge\" style=\"background:$bg;\"><i class=\"bi $ico me-1\"></i>$lbl</span>";
}

function cursoEstadoBadge(string $estado): string {
    $map = [
        'BORRADOR'   => 'bg-secondary',
        'ACTIVO'     => 'bg-success',
        'FINALIZADO' => 'bg-primary',
        'CANCELADO'  => 'bg-danger',
    ];
    $cls = $map[$estado] ?? 'bg-secondary';
    return "<span class=\"badge $cls\">".h(str_replace('_',' ',$estado))."</span>";
}

function participanteEstadoBadge(string $estado): string {
    $map = [
        'PENDIENTE'  => 'bg-warning text-dark',
        'EN_CURSO'   => 'bg-info text-dark',
        'COMPLETADO' => 'bg-success',
        'BAJA'       => 'bg-secondary',
    ];
    return "<span class=\"badge ".($map[$estado] ?? 'bg-secondary')."\">".h($estado)."</span>";
}

// Subir archivo de material al servidor
function subirArchivoMaterial(array $file, int $cursoId): array|false {
    $dir = __DIR__.'/../uploads/cursos/'.$cursoId.'/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) return false;

    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed  = ['pdf','doc','docx','xls','xlsx','ppt','pptx','txt','zip','png','jpg','jpeg'];
    if (!in_array($ext, $allowed)) return false;
    if ($file['size'] > 20 * 1024 * 1024) return false; // 20 MB máx

    $fname    = uniqid('mat_', true).'.'.$ext;
    $destino  = $dir.$fname;
    if (!move_uploaded_file($file['tmp_name'], $destino)) return false;

    return [
        'path' => 'uploads/cursos/'.$cursoId.'/'.$fname,
        'name' => $file['name'],
    ];
}

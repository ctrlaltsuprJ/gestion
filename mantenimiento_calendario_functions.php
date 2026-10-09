<?php
/**
 * Funciones del Calendario de Programación de Mantenimientos
 * Organiza la programación por Área y Edificio, en 2 periodos al año:
 *   P1 = Enero a Junio, P2 = Julio a Diciembre
 * No usa FK reales hacia areas/equipos/mantenimientos/nodos_edificios
 * (relación lógica vía IDs) para evitar conflictos de tipo entre tablas.
 * Usa getDB() para la conexión, igual que el resto del sistema.
 */

// ---------- HELPERS DE PERIODO ----------

function getPeriodoDeMes(int $mes): string {
    return $mes <= 6 ? 'P1' : 'P2';
}

function getMesesDePeriodo(string $periodo): array {
    return $periodo === 'P1' ? [1,2,3,4,5,6] : [7,8,9,10,11,12];
}

function getNombreMes(int $mes): string {
    $meses = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
              7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
    return $meses[$mes] ?? (string)$mes;
}

function getNombrePeriodo(string $periodo): string {
    return $periodo === 'P1' ? 'Enero — Junio' : 'Julio — Diciembre';
}

// ---------- PROGRAMACIÓN ----------

function getProgramacionMantenimiento(int $anio, string $periodo, int $areaId = 0, int $edificioId = 0): array {
    $pdo = getDB();
    $meses = getMesesDePeriodo($periodo);
    $placeholders = implode(',', array_fill(0, count($meses), '?'));

    $where = ['p.activo=1', 'p.anio=?', "p.mes IN ($placeholders)"];
    $params = array_merge([$anio], $meses);

    if ($areaId)     { $where[] = 'p.area_id=?';     $params[] = $areaId; }
    if ($edificioId) { $where[] = 'p.edificio_id=?'; $params[] = $edificioId; }

    $w = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT p.*, a.nombre AS area_nombre, e.folio AS equipo_folio
        FROM programacion_mantenimiento p
        LEFT JOIN areas a ON a.id = p.area_id
        LEFT JOIN equipos e ON e.id = p.equipo_id
        WHERE $w
        ORDER BY p.area_id, p.mes, p.fecha_programada
    ");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getProgramacionById(int $id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT p.*, a.nombre AS area_nombre, e.folio AS equipo_folio
        FROM programacion_mantenimiento p
        LEFT JOIN areas a ON a.id = p.area_id
        LEFT JOIN equipos e ON e.id = p.equipo_id
        WHERE p.id=?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function saveProgramacionMantenimiento(array $d, ?int $id = null): int {
    $pdo = getDB();
    $anio        = (int)($d['anio'] ?? date('Y'));
    $mes         = (int)($d['mes'] ?? 1);
    $areaId      = (int)($d['area_id'] ?? 0) ?: null;
    $edificioId  = (int)($d['edificio_id'] ?? 0) ?: null;
    $equipoId    = (int)($d['equipo_id'] ?? 0) ?: null;
    $tipo        = $d['tipo'] ?? 'PREVENTIVO';
    $descripcion = trim($d['descripcion'] ?? '');
    $equiposRev  = trim($d['equipos_revisar'] ?? '') ?: null;
    $refacciones = trim($d['refacciones'] ?? '') ?: null;
    $responsable = trim($d['responsable'] ?? '') ?: null;
    $fechaProg   = $d['fecha_programada'] ?: null;
    $estado      = $d['estado'] ?? 'PROGRAMADO';
    $notas       = trim($d['notas'] ?? '') ?: null;

    if ($id) {
        $stmt = $pdo->prepare("
            UPDATE programacion_mantenimiento
            SET anio=?, mes=?, area_id=?, edificio_id=?, equipo_id=?, tipo=?, descripcion=?,
                equipos_revisar=?, refacciones=?, responsable=?, fecha_programada=?, estado=?, notas=?
            WHERE id=?
        ");
        $stmt->execute([$anio,$mes,$areaId,$edificioId,$equipoId,$tipo,$descripcion,$equiposRev,$refacciones,$responsable,$fechaProg,$estado,$notas,$id]);
        return $id;
    }
    $stmt = $pdo->prepare("
        INSERT INTO programacion_mantenimiento
            (anio, mes, area_id, edificio_id, equipo_id, tipo, descripcion, equipos_revisar, refacciones, responsable, fecha_programada, estado, notas)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([$anio,$mes,$areaId,$edificioId,$equipoId,$tipo,$descripcion,$equiposRev,$refacciones,$responsable,$fechaProg,$estado,$notas]);
    return (int)$pdo->lastInsertId();
}

/**
 * Reprograma una entrada a otro mes (usado por el drag & drop del calendario).
 * Solo permite mover dentro del mismo periodo/año mostrado en pantalla;
 * el mes recibido ya viene validado desde la cuadrícula visible.
 */
function moverProgramacionMes(int $id, int $nuevoMes): bool {
    if ($nuevoMes < 1 || $nuevoMes > 12) return false;
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE programacion_mantenimiento SET mes=? WHERE id=? AND activo=1");
    return $stmt->execute([$nuevoMes, $id]);
}

function deleteProgramacionMantenimiento(int $id): void {
    $pdo = getDB();
    $pdo->prepare("UPDATE programacion_mantenimiento SET activo=0 WHERE id=?")->execute([$id]);
}

/**
 * Marca un ítem programado como realizado. Si se liga a una orden de
 * mantenimiento real (mantenimientos.id), se guarda esa referencia.
 */
function marcarProgramacionRealizada(int $id, ?int $mantenimientoId = null): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        UPDATE programacion_mantenimiento
        SET estado='REALIZADO', fecha_realizada=CURDATE(), mantenimiento_id=COALESCE(?, mantenimiento_id)
        WHERE id=?
    ");
    $stmt->execute([$mantenimientoId, $id]);
}

function reabrirProgramacion(int $id): void {
    $pdo = getDB();
    $pdo->prepare("UPDATE programacion_mantenimiento SET estado='PROGRAMADO', fecha_realizada=NULL WHERE id=?")->execute([$id]);
}

function getAniosConProgramacion(): array {
    $pdo = getDB();
    $anios = $pdo->query("SELECT DISTINCT anio FROM programacion_mantenimiento WHERE activo=1 ORDER BY anio DESC")->fetchAll(PDO::FETCH_COLUMN);
    $actual = (int)date('Y');
    if (!in_array($actual, $anios)) array_unshift($anios, $actual);
    return $anios;
}

/**
 * Resumen numérico de un periodo (para las tarjetas KPI y para saber si
 * algo quedó atrasado: programado, con fecha ya vencida, sin marcar realizado).
 */
function getResumenPeriodo(int $anio, string $periodo, int $areaId = 0, int $edificioId = 0): array {
    $items = getProgramacionMantenimiento($anio, $periodo, $areaId, $edificioId);
    $hoy = date('Y-m-d');
    $resumen = ['total'=>0,'programado'=>0,'realizado'=>0,'reprogramado'=>0,'cancelado'=>0,'atrasado'=>0];
    foreach ($items as $it) {
        $resumen['total']++;
        $estKey = strtolower($it['estado']);
        if (isset($resumen[$estKey])) $resumen[$estKey]++;
        if ($it['estado'] === 'PROGRAMADO' && !empty($it['fecha_programada']) && $it['fecha_programada'] < $hoy) {
            $resumen['atrasado']++;
        }
    }
    return $resumen;
}

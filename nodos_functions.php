<?php
/**
 * Funciones del módulo de Nodos de Red
 * Jerarquía: Edificio -> Planta -> Ubicación -> Nodo(s)
 * Usa getDB() para la conexión, igual que el resto del sistema (ver functions.php).
 */

// ---------- EDIFICIOS ----------

function getNodosEdificios($soloActivos = true) {
    $pdo = getDB();
    $sql = "SELECT * FROM nodos_edificios" . ($soloActivos ? " WHERE activo=1" : "") . " ORDER BY orden, id";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function getEdificioById($id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM nodos_edificios WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function saveEdificio($data, $id = null) {
    $pdo = getDB();
    $nombre = trim($data['nombre'] ?? '');
    $orden  = (int)($data['orden'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE nodos_edificios SET nombre=?, orden=? WHERE id=?");
        $stmt->execute([$nombre, $orden, $id]);
        return $id;
    }
    $stmt = $pdo->prepare("INSERT INTO nodos_edificios (nombre, orden) VALUES (?,?)");
    $stmt->execute([$nombre, $orden]);
    return $pdo->lastInsertId();
}

function deleteEdificio($id) {
    $pdo = getDB();
    // Cascada manual (desactiva plantas, ubicaciones y nodos que dependen de este edificio)
    $stmt = $pdo->prepare("SELECT id FROM nodos_plantas WHERE edificio_id=? AND activo=1");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
        deleteNodosPlanta($p['id']);
    }
    $stmt = $pdo->prepare("UPDATE nodos_edificios SET activo=0 WHERE id=?");
    $stmt->execute([$id]);
}

function getEstadisticasEdificios() {
    $pdo = getDB();
    $sql = "
        SELECT e.id, e.nombre,
          COUNT(n.id)               AS total,
          SUM(n.estado='ocupado')   AS ocupados,
          SUM(n.estado='libre')     AS libres,
          SUM(n.estado='dañado')    AS danados,
          SUM(n.estado='reservado') AS reservados
        FROM nodos_edificios e
        LEFT JOIN nodos_plantas p ON p.edificio_id=e.id AND p.activo=1
        LEFT JOIN nodos_ubicaciones u ON u.planta_id=p.id AND u.activo=1
        LEFT JOIN nodos n ON n.ubicacion_id=u.id AND n.activo=1
        WHERE e.activo=1
        GROUP BY e.id, e.nombre
        ORDER BY e.orden, e.id
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

// ---------- PLANTAS ----------

function getNodosPlantas($edificioId = null, $soloActivas = true) {
    $pdo = getDB();
    $sql = "SELECT * FROM nodos_plantas WHERE 1=1";
    $params = [];
    if ($soloActivas) $sql .= " AND activo=1";
    if ($edificioId)  { $sql .= " AND edificio_id=?"; $params[] = $edificioId; }
    $sql .= " ORDER BY orden, id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getNodosPlantaById($id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT p.*, e.nombre AS edificio_nombre
        FROM nodos_plantas p
        JOIN nodos_edificios e ON e.id = p.edificio_id
        WHERE p.id=?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function saveNodosPlanta($data, $id = null) {
    $pdo = getDB();
    $edificioId = (int)($data['edificio_id'] ?? 0);
    $nombre = trim($data['nombre'] ?? '');
    $orden  = (int)($data['orden'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE nodos_plantas SET edificio_id=?, nombre=?, orden=? WHERE id=?");
        $stmt->execute([$edificioId, $nombre, $orden, $id]);
        return $id;
    }
    $stmt = $pdo->prepare("INSERT INTO nodos_plantas (edificio_id, nombre, orden) VALUES (?,?,?)");
    $stmt->execute([$edificioId, $nombre, $orden]);
    return $pdo->lastInsertId();
}

function deleteNodosPlanta($id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id FROM nodos_ubicaciones WHERE planta_id=? AND activo=1");
    $stmt->execute([$id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        deleteUbicacion($u['id']);
    }
    $stmt = $pdo->prepare("UPDATE nodos_plantas SET activo=0 WHERE id=?");
    $stmt->execute([$id]);
}

// ---------- UBICACIONES ----------

function getUbicacionesPorPlanta($plantaId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT u.*,
          (SELECT COUNT(*) FROM nodos n WHERE n.ubicacion_id=u.id AND n.activo=1) AS total_nodos,
          (SELECT COUNT(*) FROM nodos n WHERE n.ubicacion_id=u.id AND n.activo=1 AND n.estado='ocupado')  AS nodos_ocupados,
          (SELECT COUNT(*) FROM nodos n WHERE n.ubicacion_id=u.id AND n.activo=1 AND n.estado='libre')    AS nodos_libres,
          (SELECT COUNT(*) FROM nodos n WHERE n.ubicacion_id=u.id AND n.activo=1 AND n.estado='dañado')   AS nodos_danados,
          (SELECT COUNT(*) FROM nodos n WHERE n.ubicacion_id=u.id AND n.activo=1 AND n.estado='reservado') AS nodos_reservados
        FROM nodos_ubicaciones u
        WHERE u.planta_id=? AND u.activo=1
        ORDER BY u.orden, u.id
    ");
    $stmt->execute([$plantaId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getUbicacionById($id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM nodos_ubicaciones WHERE id=?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function saveUbicacion($data, $id = null) {
    $pdo = getDB();
    $planta = (int)($data['planta_id'] ?? 0);
    $nombre = trim($data['nombre'] ?? '');
    $switch = trim($data['switch'] ?? '') ?: null;
    $orden  = (int)($data['orden'] ?? 0);
    if ($id) {
        $stmt = $pdo->prepare("UPDATE nodos_ubicaciones SET planta_id=?, nombre=?, switch=?, orden=? WHERE id=?");
        $stmt->execute([$planta, $nombre, $switch, $orden, $id]);
        return $id;
    }
    $stmt = $pdo->prepare("INSERT INTO nodos_ubicaciones (planta_id, nombre, switch, orden) VALUES (?,?,?,?)");
    $stmt->execute([$planta, $nombre, $switch, $orden]);
    return $pdo->lastInsertId();
}

function deleteUbicacion($id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE nodos_ubicaciones SET activo=0 WHERE id=?");
    $stmt->execute([$id]);
    $stmt = $pdo->prepare("UPDATE nodos SET activo=0 WHERE ubicacion_id=?");
    $stmt->execute([$id]);
}

// ---------- NODOS ----------

function getNodosPorUbicacion($ubicacionId) {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM nodos WHERE ubicacion_id=? AND activo=1 ORDER BY orden, numero_nodo");
    $stmt->execute([$ubicacionId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getNodoById($id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT n.*, u.nombre AS ubicacion_nombre, u.planta_id, p.nombre AS planta_nombre, p.edificio_id, e.nombre AS edificio_nombre
        FROM nodos n
        JOIN nodos_ubicaciones u ON u.id = n.ubicacion_id
        JOIN nodos_plantas p ON p.id = u.planta_id
        JOIN nodos_edificios e ON e.id = p.edificio_id
        WHERE n.id=?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function saveNodo($data, $id = null) {
    $pdo = getDB();
    $ubicacionId = (int)($data['ubicacion_id'] ?? 0);
    $numero      = trim($data['numero_nodo'] ?? '');
    $estado      = $data['estado'] ?? 'libre';
    $color       = trim($data['color'] ?? '') ?: null;
    $ip          = trim($data['ip_asociada'] ?? '') ?: null;
    $folio       = trim($data['folio_equipo'] ?? '') ?: null;
    $usuario     = trim($data['usuario'] ?? '') ?: null;
    $obs         = trim($data['observaciones'] ?? '') ?: null;

    if ($id) {
        $stmt = $pdo->prepare("UPDATE nodos SET ubicacion_id=?, numero_nodo=?, estado=?, color=?, ip_asociada=?, folio_equipo=?, usuario=?, observaciones=? WHERE id=?");
        $stmt->execute([$ubicacionId, $numero, $estado, $color, $ip, $folio, $usuario, $obs, $id]);
        return $id;
    }
    $stmt = $pdo->prepare("INSERT INTO nodos (ubicacion_id, numero_nodo, estado, color, ip_asociada, folio_equipo, usuario, observaciones) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$ubicacionId, $numero, $estado, $color, $ip, $folio, $usuario, $obs]);
    return $pdo->lastInsertId();
}

function deleteNodo($id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE nodos SET activo=0 WHERE id=?");
    $stmt->execute([$id]);
}

/**
 * Alta rápida de varios nodos para una ubicación en un solo paso.
 * $texto acepta números separados por coma, espacio o salto de línea.
 */
function saveNodosBulk($ubicacionId, $texto, $estado = 'libre', $color = null) {
    $pdo = getDB();
    $numeros = preg_split('/[\s,;]+/', trim($texto), -1, PREG_SPLIT_NO_EMPTY);
    $stmt = $pdo->prepare("
        INSERT INTO nodos (ubicacion_id, numero_nodo, estado, color)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE activo=1, ubicacion_id=VALUES(ubicacion_id)
    ");
    $creados = 0;
    foreach ($numeros as $n) {
        $stmt->execute([$ubicacionId, $n, $estado, $color]);
        $creados++;
    }
    return $creados;
}

// ---------- ESTADÍSTICAS ----------

function getEstadisticasNodos($edificioId = null) {
    $pdo = getDB();
    $sql = "
        SELECT p.id, p.nombre,
          COUNT(n.id)                AS total,
          SUM(n.estado='ocupado')    AS ocupados,
          SUM(n.estado='libre')      AS libres,
          SUM(n.estado='dañado')     AS danados,
          SUM(n.estado='reservado')  AS reservados
        FROM nodos_plantas p
        LEFT JOIN nodos_ubicaciones u ON u.planta_id=p.id AND u.activo=1
        LEFT JOIN nodos n ON n.ubicacion_id=u.id AND n.activo=1
        WHERE p.activo=1
    ";
    $params = [];
    if ($edificioId) { $sql .= " AND p.edificio_id=?"; $params[] = $edificioId; }
    $sql .= " GROUP BY p.id, p.nombre ORDER BY p.orden, p.id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buscarNodo($termino) {
    $pdo = getDB();
    $like = '%' . $termino . '%';
    $stmt = $pdo->prepare("
        SELECT n.*, u.nombre AS ubicacion_nombre, u.switch, p.nombre AS planta_nombre, e.nombre AS edificio_nombre
        FROM nodos n
        JOIN nodos_ubicaciones u ON u.id = n.ubicacion_id
        JOIN nodos_plantas p ON p.id = u.planta_id
        JOIN nodos_edificios e ON e.id = p.edificio_id
        WHERE n.activo=1 AND (n.numero_nodo LIKE ? OR n.usuario LIKE ? OR n.folio_equipo LIKE ? OR u.nombre LIKE ?)
        ORDER BY e.orden, p.orden, u.orden, n.numero_nodo
    ");
    $stmt->execute([$like, $like, $like, $like]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

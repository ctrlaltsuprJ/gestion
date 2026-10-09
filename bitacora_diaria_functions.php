<?php
/**
 * Funciones del módulo de Bitácora Diaria
 * (blog interno de sucesos de equipos, firmado por system_users, con etiquetas libres)
 * Usa getDB() para la conexión, igual que el resto del sistema.
 */

// ---------- CATÁLOGO DE TIPOS DE ETIQUETA ----------

function getTiposEtiquetaDiaria(): array {
    return [
        'FOLIO_EQUIPO' => 'Folio de Equipo',
        'IP'           => 'Dirección IP',
        'USUARIO'      => 'Nombre de Usuario',
        'AREA'         => 'Área',
        'OTRO'         => 'Otro',
    ];
}

// ---------- ENTRADAS ----------

/**
 * $filtros soportados:
 *  q              -> texto libre (busca en titulo y contenido)
 *  tipo_etiqueta   -> filtra por tipo de etiqueta (FOLIO_EQUIPO, IP, USUARIO, AREA, OTRO)
 *  valor_etiqueta  -> texto de la etiqueta (LIKE)
 *  fecha_desde / fecha_hasta -> rango de fechas (YYYY-MM-DD)
 *  suser_id        -> autor específico
 */
function getBitacoraDiaria(array $filtros = []): array {
    $pdo = getDB();
    $where  = ['bd.activo=1'];
    $params = [];

    if (!empty($filtros['q'])) {
        $like = '%'.$filtros['q'].'%';
        $where[] = "(bd.titulo LIKE ? OR bd.contenido LIKE ?)";
        $params[] = $like; $params[] = $like;
    }
    if (!empty($filtros['fecha_desde'])) { $where[] = "bd.fecha >= ?"; $params[] = $filtros['fecha_desde']; }
    if (!empty($filtros['fecha_hasta'])) { $where[] = "bd.fecha <= ?"; $params[] = $filtros['fecha_hasta']; }
    if (!empty($filtros['suser_id']))    { $where[] = "bd.suser_id = ?"; $params[] = (int)$filtros['suser_id']; }

    if (!empty($filtros['tipo_etiqueta']) || !empty($filtros['valor_etiqueta'])) {
        $sub = "SELECT 1 FROM bitacora_diaria_etiquetas e WHERE e.bitacora_diaria_id=bd.id";
        if (!empty($filtros['tipo_etiqueta']))  { $sub .= " AND e.tipo=?";       $params[] = $filtros['tipo_etiqueta']; }
        if (!empty($filtros['valor_etiqueta'])) { $sub .= " AND e.valor LIKE ?"; $params[] = '%'.$filtros['valor_etiqueta'].'%'; }
        $where[] = "EXISTS ($sub)";
    }

    $w = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT bd.*, su.nombre_completo AS autor_nombre
        FROM bitacora_diaria bd
        LEFT JOIN system_users su ON su.id = bd.suser_id
        WHERE $w
        ORDER BY bd.fecha DESC, bd.id DESC
    ");
    $stmt->execute($params);
    $entradas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($entradas as &$e) {
        $e['etiquetas'] = getEtiquetasBitacoraDiaria($e['id']);
    }
    return $entradas;
}

function getBitacoraDiariaById(int $id) {
    $pdo = getDB();
    $stmt = $pdo->prepare("
        SELECT bd.*, su.nombre_completo AS autor_nombre
        FROM bitacora_diaria bd
        LEFT JOIN system_users su ON su.id = bd.suser_id
        WHERE bd.id=?
    ");
    $stmt->execute([$id]);
    $entrada = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($entrada) $entrada['etiquetas'] = getEtiquetasBitacoraDiaria($id);
    return $entrada ?: null;
}

function getEtiquetasBitacoraDiaria(int $bitacoraId): array {
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT * FROM bitacora_diaria_etiquetas WHERE bitacora_diaria_id=? ORDER BY tipo, valor");
    $stmt->execute([$bitacoraId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Convierte el texto libre del formulario (una etiqueta por línea, formato
 * "TIPO: valor", ej. "FOLIO: PC-045") en un arreglo de ['tipo'=>,'valor'=>].
 * Si una línea no trae "tipo:", se guarda como tipo OTRO.
 */
function parseEtiquetasDiaria(string $texto): array {
    $tipos = array_keys(getTiposEtiquetaDiaria());
    $alias = [
        'FOLIO' => 'FOLIO_EQUIPO', 'FOLIO_EQUIPO' => 'FOLIO_EQUIPO', 'EQUIPO' => 'FOLIO_EQUIPO',
        'IP' => 'IP',
        'USUARIO' => 'USUARIO', 'USER' => 'USUARIO',
        'AREA' => 'AREA', 'ÁREA' => 'AREA',
        'OTRO' => 'OTRO',
    ];
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($texto)) as $linea) {
        $linea = trim($linea);
        if ($linea === '') continue;
        if (strpos($linea, ':') !== false) {
            [$tipoTxt, $valor] = array_map('trim', explode(':', $linea, 2));
            $tipoKey = $alias[strtoupper($tipoTxt)] ?? 'OTRO';
            $valor = $valor !== '' ? $valor : $tipoTxt;
        } else {
            $tipoKey = 'OTRO';
            $valor = $linea;
        }
        if ($valor !== '') $out[] = ['tipo' => $tipoKey, 'valor' => $valor];
    }
    return $out;
}

/**
 * Crea o actualiza una entrada de bitácora diaria.
 * $etiquetasTexto es el textarea crudo con una etiqueta por línea.
 * Al crear, el autor siempre es el usuario en sesión (no se puede suplantar).
 */
function saveBitacoraDiaria(array $data, string $etiquetasTexto = '', ?int $id = null): int {
    $pdo = getDB();
    $fecha     = $data['fecha'] ?: date('Y-m-d');
    $titulo    = trim($data['titulo'] ?? '');
    $contenido = trim($data['contenido'] ?? '');

    if ($id) {
        $stmt = $pdo->prepare("UPDATE bitacora_diaria SET fecha=?, titulo=?, contenido=? WHERE id=?");
        $stmt->execute([$fecha, $titulo, $contenido, $id]);
    } else {
        $suserId = suserIdActual();
        $stmt = $pdo->prepare("INSERT INTO bitacora_diaria (suser_id, fecha, titulo, contenido) VALUES (?,?,?,?)");
        $stmt->execute([$suserId, $fecha, $titulo, $contenido]);
        $id = (int)$pdo->lastInsertId();
    }

    // Reemplaza las etiquetas por las nuevas
    $pdo->prepare("DELETE FROM bitacora_diaria_etiquetas WHERE bitacora_diaria_id=?")->execute([$id]);
    $etiquetas = parseEtiquetasDiaria($etiquetasTexto);
    if ($etiquetas) {
        $ins = $pdo->prepare("INSERT INTO bitacora_diaria_etiquetas (bitacora_diaria_id, tipo, valor) VALUES (?,?,?)");
        foreach ($etiquetas as $et) {
            $ins->execute([$id, $et['tipo'], $et['valor']]);
        }
    }
    return $id;
}

function deleteBitacoraDiaria(int $id): void {
    $pdo = getDB();
    $stmt = $pdo->prepare("UPDATE bitacora_diaria SET activo=0 WHERE id=?");
    $stmt->execute([$id]);
}

/**
 * Convierte el arreglo de etiquetas de una entrada de vuelta a texto
 * "TIPO: valor" por línea, para precargar el textarea al editar.
 */
function etiquetasATexto(array $etiquetas): string {
    $labels = getTiposEtiquetaDiaria();
    $lineas = [];
    foreach ($etiquetas as $e) {
        $lineas[] = ($labels[$e['tipo']] ?? $e['tipo']) . ': ' . $e['valor'];
    }
    return implode("\n", $lineas);
}

// ---------- AUTOCOMPLETADO / SUGERENCIAS ----------

function getValoresEtiquetaSugeridos(?string $tipo = null, int $limite = 30): array {
    $pdo = getDB();
    $sql = "SELECT DISTINCT valor FROM bitacora_diaria_etiquetas";
    $params = [];
    if ($tipo) { $sql .= " WHERE tipo=?"; $params[] = $tipo; }
    $sql .= " ORDER BY valor LIMIT " . (int)$limite;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getAutoresBitacoraDiaria(): array {
    $pdo = getDB();
    return $pdo->query("
        SELECT DISTINCT su.id, su.nombre_completo
        FROM bitacora_diaria bd
        JOIN system_users su ON su.id = bd.suser_id
        WHERE bd.activo=1
        ORDER BY su.nombre_completo
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ---------- ESTADÍSTICAS ----------

function getEstadisticasBitacoraDiaria(): array {
    $pdo = getDB();
    return [
        'total'      => (int)$pdo->query("SELECT COUNT(*) FROM bitacora_diaria WHERE activo=1")->fetchColumn(),
        'mes_actual' => (int)$pdo->query("SELECT COUNT(*) FROM bitacora_diaria WHERE activo=1 AND MONTH(fecha)=MONTH(NOW()) AND YEAR(fecha)=YEAR(NOW())")->fetchColumn(),
        'hoy'        => (int)$pdo->query("SELECT COUNT(*) FROM bitacora_diaria WHERE activo=1 AND fecha=CURDATE()")->fetchColumn(),
    ];
}

<?php
// 1. Corregir la ruta al archivo que inicializa la sesión y la BD.
// Si estás dentro de la carpeta 'exports/', debes subir un nivel para encontrar 'includes.php' o 'functions.php'
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

// 2. Verificar permisos
requireRol(ROL_ADMIN, ROL_SUPER);

// 3. Limpieza de parámetros
$q = trim($_GET['q'] ?? '');
$areaFil = (int)($_GET['area'] ?? 0);
$generoFil = trim($_GET['genero'] ?? '');
$orden = (string)($_GET['orden'] ?? 'area_nombre');

try {
    // Verificar que la función getDB() exista y responda
    if (!function_exists('getDB')) {
        throw new Exception("La función getDB() no está disponible. Verifica las inclusiones.");
    }
    
    $db = getDB(); 
    $like = '%' . $q . '%';

    // Orden de listado: mismo que en usuarios.php
    $usuariosListOrdenSql = [
        'area_nombre' => 'a.nombre ASC, u.nombre ASC, u.apellidos ASC',
        'jerarquia_global' => "(CASE TRIM(COALESCE(p.nivel,''))
            WHEN 'DIRECTIVO' THEN 1 WHEN 'SUBDIRECTIVO' THEN 2 WHEN 'JEFATURA' THEN 3
            WHEN 'COORDINACION' THEN 4 WHEN 'OPERATIVO' THEN 5 WHEN 'APOYO' THEN 6 ELSE 7 END),
            a.nombre ASC, u.nombre ASC, u.apellidos ASC",
        'jerarquia_area' => "a.nombre ASC, (CASE TRIM(COALESCE(p.nivel,''))
            WHEN 'DIRECTIVO' THEN 1 WHEN 'SUBDIRECTIVO' THEN 2 WHEN 'JEFATURA' THEN 3
            WHEN 'COORDINACION' THEN 4 WHEN 'OPERATIVO' THEN 5 WHEN 'APOYO' THEN 6 ELSE 7 END),
            u.nombre ASC, u.apellidos ASC",
        'area_cubiculo' => 'a.nombre ASC, u.`cubículo` ASC, u.oficina ASC, u.nombre ASC, u.apellidos ASC',
        'cubiculo_area' => 'u.`cubículo` ASC, a.nombre ASC, u.oficina ASC, u.nombre ASC, u.apellidos ASC',
        'nombre' => 'u.nombre ASC, u.apellidos ASC',
        'puesto_nombre' => 'p.nombre ASC, u.nombre ASC, u.apellidos ASC',
    ];
    if (!isset($usuariosListOrdenSql[$orden])) {
        $orden = 'area_nombre';
    }

    $sql = "SELECT u.*, a.nombre as area_nombre, p.nombre as puesto_nombre, p.nivel as puesto_nivel,
            (SELECT COUNT(*) FROM equipos e WHERE e.usuario_id=u.id AND e.activo=1) n_equipos
            FROM usuarios u
            LEFT JOIN areas a ON a.id = u.area_id
            LEFT JOIN cat_puestos p ON p.id = u.puesto_id
            WHERE u.activo = 1";

    $params = [];

    if ($q) {
        $sql .= " AND (u.nombre LIKE ? OR u.apellidos LIKE ?
                     OR TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellidos,''))) LIKE ?
                     OR u.email LIKE ? OR u.usuario_windows LIKE ?
                     OR u.extension_ip LIKE ? OR u.cubículo LIKE ? OR u.oficina LIKE ? OR u.puesto LIKE ?)";
        $params = array_fill(0, 9, $like);
    }

    if ($areaFil) {
        $sql .= " AND u.area_id = ?";
        $params[] = $areaFil;
    }

    if ($generoFil === 'X') {
        $sql .= " AND (u.genero IS NULL OR u.genero='')";
    } elseif ($generoFil !== '') {
        $sql .= " AND u.genero = ?";
        $params[] = $generoFil;
    }

    $sql .= ' ORDER BY ' . $usuariosListOrdenSql[$orden];
    $st = $db->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // ── SI TODO VA BIEN, ENVIAMOS CABECERAS DE DESCARGA ──
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="usuarios_' . date('Ymd_His') . '.xls"');
    header('Cache-Control: max-age=0');

    echo "\xEF\xBB\xBF"; // BOM .0000000000UTF-8 para Excel en español

    $out = fopen('php://output', 'w');

    // Cabeceras de columnas
    fputcsv($out, [
        'ID', 'Nombre(s)', 'Apellidos', 'Usuario Windows', 'Ext. IP',
        'Cubículo / Oficina', 'Teléfono Directo', 'Email', 'Área / Departamento', 'Puesto Catálogo', 'Nivel Puesto', 'Género', 'Equipos'
    ], ';');

    foreach ($rows as $r) {
        $genero = match($r['genero'] ?? '') {
            'H' => 'Hombre',
            'M' => 'Mujer',
            default => 'Sin especificar',
        };

        $ubicacion = array_filter([$r['cubículo'] ?? '', $r['oficina'] ?? '']);

        fputcsv($out, [
            $r['id'],
            $r['nombre'],
            $r['apellidos'] ?? '',
            $r['usuario_windows'] ?? '',
            $r['extension_ip'] ?? '',
            implode(' · ', $ubicacion) ?: '',
            $r['telefono_directo'] ?? $r['telefono'] ?? '',
            $r['email'] ?? '',
            $r['area_nombre'] ?? 'Sin Área',
            $r['puesto_nombre'] ?? $r['puesto'] ?? '',
            $r['puesto_nivel'] ?? '',
            $genero,
            $r['n_equipos'] ?? 0
        ], ';');
    }

    fclose($out); 
    exit;

} catch (Throwable $e) {
    // Si algo truena, evitamos el crash de red y pintamos el error en pantalla para saber qué pasa
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(500);
    echo "<h3>Error interno en la exportación:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    exit;
}
<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireRol(ROL_ADMIN, ROL_SUPER);

$q = trim($_GET['q'] ?? '');
$areaFil = (int)($_GET['area'] ?? 0);
$generoFil = trim($_GET['genero'] ?? '');
$orden = (string)($_GET['orden'] ?? 'area_nombre');

try {
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

    // ── CABECERAS PARA EXCEL ──
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="usuarios_' . date('Ymd_His') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');

    // ── INICIO DEL DOCUMENTO HTML ──
    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta charset="UTF-8">
        <!-- Definimos los estilos que Excel interpretará -->
        <style>
            table { border-collapse: collapse; font-family: Arial, sans-serif; }
            th { 
                background-color: #1a3a5c; 
                color: #ffffff; 
                font-weight: bold; 
                border: 1px solid #cccccc; 
                padding: 8px; 
                text-align: center; 
                vertical-align: middle;
            }
            td { 
                border: 1px solid #cccccc; 
                padding: 6px; 
                vertical-align: middle;
            }
            .text-center { text-align: center; }
            .bg-light { background-color: #f8f9fa; }
        </style>
    </head>
    <body>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nombre(s)</th>
                    <th>Apellidos</th>
                    <th>Usuario Windows</th>
                    <th>Ext. IP</th>
                    <th>Cubículo / Oficina</th>
                    <th>Teléfono Directo</th>
                    <th>Email</th>
                    <th>Área / Departamento</th>
                    <th>Puesto Catálogo</th>
                    <th>Nivel Puesto</th>
                    <th>Género</th>
                    <th>Equipos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $index => $r): 
                    $genero = match($r['genero'] ?? '') {
                        'H' => 'Hombre',
                        'M' => 'Mujer',
                        default => 'Sin especificar',
                    };
                    $ubicacion = array_filter([$r['cubículo'] ?? '', $r['oficina'] ?? '']);
                    // Alternar color de filas para mejor lectura
                    $rowClass = ($index % 2 == 0) ? '' : 'bg-light';
                ?>
                <tr class="<?= $rowClass ?>">
                    <td class="text-center"><?= $r['id'] ?></td>
                    <td><?= htmlspecialchars($r['nombre']) ?></td>
                    <td><?= htmlspecialchars($r['apellidos'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['usuario_windows'] ?? '') ?></td>
                    <td class="text-center"><?= htmlspecialchars($r['extension_ip'] ?? '') ?></td>
                    <td><?= htmlspecialchars(implode(' · ', $ubicacion) ?: '') ?></td>
                    <td><?= htmlspecialchars($r['telefono_directo'] ?? $r['telefono'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['email'] ?? '') ?></td>
                    <td><?= htmlspecialchars($r['area_nombre'] ?? 'Sin Área') ?></td>
                    <td><?= htmlspecialchars($r['puesto_nombre'] ?? $r['puesto'] ?? '') ?></td>
                    <td class="text-center"><?= htmlspecialchars($r['puesto_nivel'] ?? '') ?></td>
                    <td class="text-center"><?= $genero ?></td>
                    <td class="text-center"><?= $r['n_equipos'] ?? 0 ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;

} catch (Throwable $e) {
    header('Content-Type: text/html; charset=UTF-8');
    http_response_code(500);
    echo "<h3>Error interno en la exportación:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    exit;
}
?>
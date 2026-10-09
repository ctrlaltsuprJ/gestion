<?php
/**
 * Exporta el listado de Nodos a Excel.
 * ?edificio=ID  -> exporta todas las plantas de ese edificio
 * ?planta=ID    -> exporta solo esa planta
 * (sin parámetros) -> exporta todo
 */
require_once __DIR__.'/../includes/auth.php';
requireRol(ROL_ADMIN, ROL_SUPER);
require_once __DIR__.'/../includes/nodos_functions.php';

$edificioId = (int)($_GET['edificio'] ?? 0);
$plantaId   = (int)($_GET['planta'] ?? 0);

if ($plantaId) {
    $plantas = array_filter([getNodosPlantaById($plantaId)]);
} elseif ($edificioId) {
    $plantas = getNodosPlantas($edificioId);
} else {
    $plantas = getNodosPlantas();
}

$estadoLabel = ['libre' => 'Libre', 'ocupado' => 'Ocupado', 'dañado' => 'Dañado', 'reservado' => 'Reservado'];

$sufijo = $plantaId ? 'planta_'.$plantaId : ($edificioId ? 'edificio_'.$edificioId : 'todas');
$filename = 'nodos_' . $sufijo . '_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// BOM para que Excel respete acentos/ñ
echo "\xEF\xBB\xBF";
?>
<table border="1" cellpadding="4" cellspacing="0">
<thead>
<tr style="background:#1a3a5c;color:#ffffff;font-weight:bold;">
<th>Edificio</th>
<th>Planta</th>
<th>Ubicación</th>
<th>Switch</th>
<th>Nodo</th>
<th>Estado</th>
<th>IP Asociada</th>
<th>Usuario</th>
<th>Folio Equipo</th>
<th>Observaciones</th>
</tr>
</thead>
<tbody>
<?php foreach ($plantas as $p):
    $edif = getEdificioById($p['edificio_id']);
    $ubicaciones = getUbicacionesPorPlanta($p['id']);
    foreach ($ubicaciones as $u):
        $nodos = getNodosPorUbicacion($u['id']);
        if (empty($nodos)): ?>
<tr>
  <td><?= htmlspecialchars($edif['nombre'] ?? '') ?></td>
  <td><?= htmlspecialchars($p['nombre']) ?></td>
  <td><?= htmlspecialchars($u['nombre']) ?></td>
  <td><?= htmlspecialchars($u['switch'] ?? '') ?></td>
  <td colspan="6" style="color:#999999;">Sin nodos registrados</td>
</tr>
<?php   else:
            foreach ($nodos as $n): ?>
<tr>
  <td><?= htmlspecialchars($edif['nombre'] ?? '') ?></td>
  <td><?= htmlspecialchars($p['nombre']) ?></td>
  <td><?= htmlspecialchars($u['nombre']) ?></td>
  <td><?= htmlspecialchars($u['switch'] ?? '') ?></td>
  <td><?= htmlspecialchars($n['numero_nodo']) ?></td>
  <td><?= htmlspecialchars($estadoLabel[$n['estado']] ?? $n['estado']) ?></td>
  <td><?= htmlspecialchars($n['ip_asociada'] ?? '') ?></td>
  <td><?= htmlspecialchars($n['usuario'] ?? '') ?></td>
  <td><?= htmlspecialchars($n['folio_equipo'] ?? '') ?></td>
  <td><?= htmlspecialchars($n['observaciones'] ?? '') ?></td>
</tr>
<?php   endforeach;
        endif;
    endforeach;
endforeach; ?>
</tbody>
</table>

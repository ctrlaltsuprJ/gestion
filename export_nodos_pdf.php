<?php
/**
 * Vista imprimible de Nodos (para "Guardar como PDF" desde el navegador).
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
    $tituloReporte = 'Planta: ' . ($plantas ? reset($plantas)['nombre'] : '');
} elseif ($edificioId) {
    $plantas = getNodosPlantas($edificioId);
    $edifInfo = getEdificioById($edificioId);
    $tituloReporte = 'Edificio: ' . ($edifInfo['nombre'] ?? '');
} else {
    $plantas = getNodosPlantas();
    $tituloReporte = 'Todos los edificios';
}

$estadoLabel = ['libre' => 'Libre', 'ocupado' => 'Ocupado', 'dañado' => 'Dañado', 'reservado' => 'Reservado'];

// Agrupar plantas por edificio para el encabezado del reporte
$porEdificio = [];
foreach ($plantas as $p) {
    $eid = $p['edificio_id'];
    if (!isset($porEdificio[$eid])) {
        $porEdificio[$eid] = ['edificio' => getEdificioById($eid), 'plantas' => []];
    }
    $porEdificio[$eid]['plantas'][] = $p;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Reporte de Nodos — <?= htmlspecialchars($tituloReporte) ?></title>
<style>
  body{font-family: Arial, Helvetica, sans-serif; font-size:12px; color:#222; margin:24px;}
  h1{font-size:19px;color:#1a3a5c;margin:0 0 2px;}
  .subtitulo{font-size:13px;color:#444;margin-bottom:2px;}
  .fecha{color:#777;font-size:11px;margin-bottom:18px;}
  h2{font-size:15px;color:#ffffff;background:#1a3a5c;padding:7px 10px;margin-top:28px;border-radius:3px;}
  h3.planta{font-size:13px;color:#1a3a5c;background:#eef2f6;padding:5px 8px;margin:14px 0 4px;border-left:4px solid #1a3a5c;}
  h4.ubic{font-size:12px;color:#1a3a5c;margin:10px 0 4px;border-bottom:1px solid #ddd;padding-bottom:2px;}
  table{width:100%;border-collapse:collapse;margin-bottom:8px;page-break-inside:avoid;}
  th,td{border:1px solid #ccc;padding:4px 6px;text-align:left;font-size:11px;}
  th{background:#eef2f6;color:#1a3a5c;}
  .badge{display:inline-block;padding:2px 7px;border-radius:3px;color:#fff;font-size:10px;}
  .libre{background:#198754;}
  .ocupado{background:#dc3545;}
  .reservado{background:#ffc107;color:#222;}
  .dañado{background:#212529;}
  .btn-print{margin-bottom:18px;padding:9px 18px;background:#1a3a5c;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;}
  @media print { .no-print{display:none;} body{margin:0;} h2, h3.planta{page-break-after:avoid;} }
</style>
</head>
<body>

<button class="btn-print no-print" onclick="window.print()">
  🖨 Imprimir / Guardar como PDF
</button>

<h1>Reporte de Nodos de Red</h1>
<div class="subtitulo"><?= htmlspecialchars($tituloReporte) ?></div>
<div class="fecha">Generado el <?= date('d/m/Y H:i') ?></div>

<?php foreach ($porEdificio as $grupo):
    $edif = $grupo['edificio'];
?>
<h2><i>🏢</i> <?= htmlspecialchars($edif['nombre'] ?? 'Edificio') ?></h2>

<?php foreach ($grupo['plantas'] as $p): $ubicaciones = getUbicacionesPorPlanta($p['id']); ?>
<h3 class="planta"><?= htmlspecialchars($p['nombre']) ?></h3>

<?php if (empty($ubicaciones)): ?>
<p style="color:#888;">Sin ubicaciones registradas.</p>
<?php endif; ?>

<?php foreach ($ubicaciones as $u): $nodos = getNodosPorUbicacion($u['id']); ?>
<h4 class="ubic"><?= htmlspecialchars($u['nombre']) ?><?php if (!empty($u['switch'])): ?> — SW: <?= htmlspecialchars($u['switch']) ?><?php endif; ?></h4>
<table>
<thead>
<tr><th>Nodo</th><th>Estado</th><th>IP</th><th>Usuario</th><th>Folio</th><th>Observaciones</th></tr>
</thead>
<tbody>
<?php if (empty($nodos)): ?>
<tr><td colspan="6" style="color:#999;">Sin nodos registrados</td></tr>
<?php else: foreach ($nodos as $n): ?>
<tr>
  <td><strong><?= htmlspecialchars($n['numero_nodo']) ?></strong></td>
  <td><span class="badge <?= htmlspecialchars($n['estado']) ?>"><?= htmlspecialchars($estadoLabel[$n['estado']] ?? $n['estado']) ?></span></td>
  <td><?= htmlspecialchars($n['ip_asociada'] ?? '—') ?></td>
  <td><?= htmlspecialchars($n['usuario'] ?? '—') ?></td>
  <td><?= htmlspecialchars($n['folio_equipo'] ?? '—') ?></td>
  <td><?= htmlspecialchars($n['observaciones'] ?? '—') ?></td>
</tr>
<?php endforeach; endif; ?>
</tbody>
</table>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endforeach; ?>

</body>
</html>

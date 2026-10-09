<?php
/**
 * Vista imprimible del Calendario de Programación de Mantenimientos.
 * Parámetros: anio, periodo (P1|P2), area (opcional), edificio (opcional)
 */
require_once __DIR__.'/../includes/auth.php';
requireRol(ROL_ADMIN, ROL_SUPER);
require_once __DIR__.'/../includes/mantenimiento_calendario_functions.php';

$edificiosDisponibles = [];
if (file_exists(__DIR__.'/../includes/nodos_functions.php')) {
    require_once __DIR__.'/../includes/nodos_functions.php';
    if (function_exists('getNodosEdificios')) $edificiosDisponibles = getNodosEdificios();
}

$anio    = (int)($_GET['anio'] ?? date('Y'));
$periodo = ($_GET['periodo'] ?? 'P1') === 'P2' ? 'P2' : 'P1';
$areaId     = (int)($_GET['area'] ?? 0);
$edificioId = (int)($_GET['edificio'] ?? 0);

$items   = getProgramacionMantenimiento($anio, $periodo, $areaId, $edificioId);
$resumen = getResumenPeriodo($anio, $periodo, $areaId, $edificioId);
$meses   = getMesesDePeriodo($periodo);
$hoy     = date('Y-m-d');

$grid = [];
foreach ($items as $it) {
    $areaKey = $it['area_id'] ?: 0;
    $grid[$areaKey]['nombre'] = $it['area_nombre'] ?: 'Sin área asignada';
    $grid[$areaKey]['meses'][$it['mes']][] = $it;
}
ksort($grid);

$edifById = [];
foreach ($edificiosDisponibles as $e) $edifById[$e['id']] = $e['nombre'];

$areaLabel = 0;
if ($areaId) {
    foreach ($items as $it) { if ($it['area_id']==$areaId) { $areaLabel = $it['area_nombre']; break; } }
}
$edificioLabel = $edificioId && isset($edifById[$edificioId]) ? $edifById[$edificioId] : '';

$estadoColor = [
    'PROGRAMADO'   => '#0d6efd',
    'REALIZADO'    => '#198754',
    'REPROGRAMADO' => '#ffc107',
    'CANCELADO'    => '#6c757d',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Calendario de Mantenimiento — <?= getNombrePeriodo($periodo) ?> <?= $anio ?></title>
<style>
  body{font-family: Arial, Helvetica, sans-serif; font-size:11px; color:#222; margin:20px;}
  h1{font-size:18px;color:#1a3a5c;margin:0 0 2px;}
  .subtitulo{font-size:13px;color:#444;margin-bottom:2px;}
  .fecha{color:#777;font-size:10.5px;margin-bottom:14px;}
  table{width:100%;border-collapse:collapse;margin-bottom:12px;}
  th,td{border:1px solid #ccc;padding:4px;vertical-align:top;font-size:10px;}
  th{background:#1a3a5c;color:#fff;text-align:center;}
  .kpis{display:flex;gap:10px;margin-bottom:14px;}
  .kpi{border:1px solid #ddd;border-radius:4px;padding:6px 10px;text-align:center;flex:1;}
  .kpi .num{font-size:16px;font-weight:bold;}
  .item{background:#f4f6f8;border-radius:3px;padding:3px 4px;margin-bottom:3px;}
  .badge{display:inline-block;padding:1px 6px;border-radius:3px;color:#fff;font-size:9px;}
  .btn-print{margin-bottom:16px;padding:8px 16px;background:#1a3a5c;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;}
  @media print { .no-print{display:none;} body{margin:0;} table{page-break-inside:auto;} tr{page-break-inside:avoid;} }
</style>
</head>
<body>

<button class="btn-print no-print" onclick="window.print()">🖨 Imprimir / Guardar como PDF</button>

<h1>Calendario Organizacional de Mantenimientos</h1>
<div class="subtitulo">
  <?= htmlspecialchars(getNombrePeriodo($periodo)) ?> <?= $anio ?>
  <?php if ($areaLabel): ?> — Área: <?= htmlspecialchars($areaLabel) ?><?php endif; ?>
  <?php if ($edificioLabel): ?> — Edificio: <?= htmlspecialchars($edificioLabel) ?><?php endif; ?>
</div>
<div class="fecha">Generado el <?= date('d/m/Y H:i') ?></div>

<div class="kpis">
  <div class="kpi"><div class="num"><?= $resumen['total'] ?></div>Total</div>
  <div class="kpi"><div class="num" style="color:#0d6efd;"><?= $resumen['programado'] ?></div>Programado</div>
  <div class="kpi"><div class="num" style="color:#198754;"><?= $resumen['realizado'] ?></div>Realizado</div>
  <div class="kpi"><div class="num" style="color:#ffc107;"><?= $resumen['reprogramado'] ?></div>Reprogramado</div>
  <div class="kpi"><div class="num" style="color:#6c757d;"><?= $resumen['cancelado'] ?></div>Cancelado</div>
  <div class="kpi"><div class="num" style="color:#dc3545;"><?= $resumen['atrasado'] ?></div>Atrasado</div>
</div>

<table>
<thead>
<tr>
  <th style="min-width:100px;">Área</th>
  <?php foreach ($meses as $mNum): ?>
  <th><?= getNombreMes($mNum) ?></th>
  <?php endforeach; ?>
</tr>
</thead>
<tbody>
<?php if (empty($grid)): ?>
<tr><td colspan="<?= count($meses)+1 ?>" style="text-align:center;color:#888;">Sin programación registrada.</td></tr>
<?php endif; ?>
<?php foreach ($grid as $fila): ?>
<tr>
  <td><strong><?= htmlspecialchars($fila['nombre']) ?></strong></td>
  <?php foreach ($meses as $mNum): ?>
  <td>
    <?php foreach (($fila['meses'][$mNum] ?? []) as $it):
        $atrasado = $it['estado']==='PROGRAMADO' && !empty($it['fecha_programada']) && $it['fecha_programada'] < $hoy;
        $color = $atrasado ? '#dc3545' : ($estadoColor[$it['estado']] ?? '#6c757d');
    ?>
    <div class="item">
      <span class="badge" style="background:<?= $color ?>;"><?= $atrasado ? 'ATRASADO' : htmlspecialchars(str_replace('_',' ',$it['estado'])) ?></span>
      <div><?= htmlspecialchars($it['descripcion']) ?></div>
      <?php if ($it['equipo_folio']): ?><div>Equipo: <?= htmlspecialchars($it['equipo_folio']) ?></div><?php endif; ?>
      <?php if (!empty($it['edificio_id']) && isset($edifById[$it['edificio_id']])): ?><div>Edif: <?= htmlspecialchars($edifById[$it['edificio_id']]) ?></div><?php endif; ?>
      <?php if ($it['responsable']): ?><div>Resp: <?= htmlspecialchars($it['responsable']) ?></div><?php endif; ?>
      <?php if ($it['fecha_programada']): ?><div><?= date('d/m/Y', strtotime($it['fecha_programada'])) ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </td>
  <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</body>
</html>

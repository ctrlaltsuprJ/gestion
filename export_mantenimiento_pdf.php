<?php
// export_mantenimiento_pdf.php — Orden de mantenimiento imprimible
require_once dirname(__DIR__).'/includes/auth.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) { die('ID requerido'); }
$m      = getMantenimientoById($id);
$checks = getChecks($id);
if (!$m) die('Mantenimiento no encontrado');

$checksByCat = [];
foreach ($checks as $c) $checksByCat[$c['categoria']][] = $c;

$iconMap = [
    'OK'                => ['✓','#198754'],
    'REQUIERE_ATENCION' => ['⚠','#fd7e14'],
    'MAL'               => ['✗','#dc3545'],
    'NO_APLICA'         => ['—','#aaa'],
];

// Resumen de resultados
$totOk  = count(array_filter($checks, fn($c)=>$c['resultado']==='OK'));
$totMal = count(array_filter($checks, fn($c)=>$c['resultado']==='MAL'));
$totRA  = count(array_filter($checks, fn($c)=>$c['resultado']==='REQUIERE_ATENCION'));
$totNA  = count(array_filter($checks, fn($c)=>$c['resultado']==='NO_APLICA'));
$total  = count($checks);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Orden de Mantenimiento #<?= $id ?></title>
<style>
  * { box-sizing:border-box; font-family:Arial,Helvetica,sans-serif; }
  body { margin:0; font-size:8.5pt; color:#1a1a1a; }

  /* ── Cabecera del documento ── */
  .doc-header { display:flex; justify-content:space-between; align-items:center;
                border-bottom:3px solid #1a3a5c; padding-bottom:10px; margin-bottom:12px; }
  .doc-title h1 { margin:0; font-size:14pt; color:#1a3a5c; }
  .doc-title p  { margin:2px 0 0; font-size:8pt; color:#666; }
  .doc-meta { text-align:right; font-size:7.5pt; color:#555; }
  .doc-meta strong { font-size:9pt; color:#1a3a5c; }

  /* ── Sección de datos generales ── */
  .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid #ccc;
               border-radius:4px; overflow:hidden; margin-bottom:12px; }
  .info-cell { padding:5px 10px; border-bottom:1px solid #eee; }
  .info-cell:nth-child(odd) { background:#f5f8ff; }
  .info-cell label { display:block; font-size:7pt; color:#888; text-transform:uppercase; letter-spacing:.4px; margin-bottom:1px; }
  .info-cell span  { font-size:8.5pt; font-weight:600; }

  /* ── Resumen KPI ── */
  .kpi-row { display:flex; gap:8px; margin-bottom:12px; }
  .kpi { flex:1; border-radius:5px; padding:7px 10px; text-align:center; color:#fff; }
  .kpi .kpi-n { font-size:16pt; font-weight:700; line-height:1; }
  .kpi .kpi-l { font-size:7pt; text-transform:uppercase; letter-spacing:.4px; margin-top:2px; }
  .kpi-ok   { background:#198754; }
  .kpi-mal  { background:#dc3545; }
  .kpi-warn { background:#fd7e14; }
  .kpi-na   { background:#6c757d; }

  /* ── Checklist ── */
  .cat-title { background:#1a3a5c; color:#fff; font-size:8pt; font-weight:700;
               letter-spacing:.5px; text-transform:uppercase; padding:5px 10px;
               margin:10px 0 0; border-radius:3px 3px 0 0; }
  .check-table { width:100%; border-collapse:collapse; margin-bottom:2px; }
  .check-table td { padding:3px 8px; border-bottom:1px solid #f0f0f0; font-size:8pt; vertical-align:middle; }
  .check-table tr:nth-child(even) td { background:#fafafa; }
  .check-table .res { width:120px; font-weight:600; white-space:nowrap; }
  .check-table .nota { color:#666; font-size:7.5pt; font-style:italic; }
  .check-num { width:22px; color:#aaa; font-size:7pt; }

  /* ── Observaciones ── */
  .obs-box { border:1px solid #ddd; border-radius:4px; padding:8px 12px;
             background:#fffde7; font-size:8.5pt; margin-bottom:14px; }
  .obs-box label { font-weight:700; font-size:8pt; color:#555; display:block; margin-bottom:4px; }

  /* ── Firmas ── */
  .firmas { display:flex; gap:40px; margin-top:30px; padding-top:10px; }
  .firma  { flex:1; border-top:2px solid #1a3a5c; padding-top:6px; text-align:center; }
  .firma strong { font-size:8pt; display:block; }
  .firma span   { font-size:7.5pt; color:#666; }

  /* ── Utilidades ── */
  .no-print { display:block; background:#fff3cd; border:1px solid #ffc107;
              padding:8px 14px; text-align:center; margin-bottom:14px; border-radius:4px; }
  .no-print button { background:#1a3a5c; color:#fff; border:none; padding:5px 16px;
                     border-radius:4px; cursor:pointer; margin-left:10px; font-size:8pt; }
  .badge-tipo { background:#0d6efd; color:#fff; border-radius:3px; padding:2px 8px; font-size:8pt; }
  .badge-prev { background:#0dcaf0; color:#000; }
  .badge-corr { background:#fd7e14; color:#fff; }

  @page { margin:14mm 13mm; }
  @media print { .no-print { display:none !important; } }
</style>
</head>
<body>

<div class="no-print">
  <strong>🖨 Orden de Mantenimiento #<?= $id ?></strong> &nbsp;|&nbsp;
  Para guardar como PDF usa Ctrl+P → "Guardar como PDF"
  <button onclick="window.print()">🖨 Imprimir / PDF</button>
  <a href="../mantenimiento.php?action=view&id=<?= $id ?>" style="margin-left:8px;color:#1a3a5c;font-size:8pt;">← Volver</a>
</div>

<!-- ENCABEZADO -->
<div class="doc-header">
  <div class="doc-title">
    <h1>🖥 Orden de Mantenimiento</h1>
    <p>Sistema de Gestión de Equipos TI</p>
  </div>
  <div class="doc-meta">
    <strong>Folio #<?= $id ?></strong><br>
    Fecha: <?= h($m['fecha']) ?><br>
    Tipo:
    <span class="badge-tipo badge-<?= strtolower($m['tipo']) ==='preventivo'?'prev':'corr' ?>">
      <?= h($m['tipo']) ?>
    </span><br>
    Generado: <?= date('d/m/Y H:i') ?>
  </div>
</div>

<!-- DATOS GENERALES -->
<div class="info-grid">
  <div class="info-cell"><label>Equipo</label><span><?= h($m['equipo_folio'].' — '.$m['marca'].' '.($m['modelo']??'')) ?></span></div>
  <div class="info-cell"><label>Técnico Responsable</label><span><?= h($m['tecnico'] ?? '—') ?></span></div>
  <div class="info-cell"><label>Usuario del Equipo</label><span><?= h($m['usuario_nombre'] ?? '—') ?></span></div>
  <div class="info-cell"><label>Fecha de Mantenimiento</label><span><?= h($m['fecha']) ?></span></div>
  <div class="info-cell"><label>Ticket Relacionado</label><span><?= $m['ticket_id'] ? '#'.$m['ticket_id'] : 'Sin ticket' ?></span></div>
  <div class="info-cell"><label>Tipo de Mantenimiento</label><span><?= h($m['tipo']) ?></span></div>
</div>

<!-- OBSERVACIONES GENERALES -->
<?php if ($m['observaciones']): ?>
<div class="obs-box">
  <label>📝 Observaciones Generales:</label>
  <?= h($m['observaciones']) ?>
</div>
<?php endif; ?>

<!-- KPIs -->
<div class="kpi-row">
  <div class="kpi kpi-ok"><div class="kpi-n"><?= $totOk ?></div><div class="kpi-l">✓ OK</div></div>
  <div class="kpi kpi-warn"><div class="kpi-n"><?= $totRA ?></div><div class="kpi-l">⚠ Req. Atención</div></div>
  <div class="kpi kpi-mal"><div class="kpi-n"><?= $totMal ?></div><div class="kpi-l">✗ Mal</div></div>
  <div class="kpi kpi-na"><div class="kpi-n"><?= $totNA ?></div><div class="kpi-l">— N/A</div></div>
  <div class="kpi" style="background:#1a3a5c;"><div class="kpi-n"><?= $total ?></div><div class="kpi-l">Total Puntos</div></div>
</div>

<!-- CHECKLIST POR CATEGORÍA -->
<?php foreach ($checksByCat as $cat => $items): ?>
<div class="cat-title"><?= h($cat) ?></div>
<table class="check-table">
  <tbody>
  <?php foreach ($items as $i => $c):
    [$ico,$col] = $iconMap[$c['resultado']] ?? ['—','#aaa'];
  ?>
  <tr>
    <td class="check-num"><?= $i+1 ?></td>
    <td><?= h($c['item']) ?></td>
    <td class="res" style="color:<?= $col ?>;"><?= $ico ?> <?= h(str_replace('_',' ',$c['resultado'])) ?></td>
    <td class="nota"><?= h($c['notas'] ?? '') ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php endforeach; ?>

<!-- FIRMAS -->
<div class="firmas">
  <div class="firma">
    <strong>TÉCNICO RESPONSABLE</strong>
    <br><br><br>
    <span><?= h($m['tecnico'] ?? '________________________________') ?></span>
  </div>
  <div class="firma">
    <strong>USUARIO / RESPONSABLE DEL EQUIPO</strong>
    <br><br><br>
    <span><?= h($m['usuario_nombre'] ?? '________________________________') ?></span>
  </div>
  <div class="firma">
    <strong>JEFE INMEDIATO / AUTORIZA</strong>
    <br><br><br>
    <span>________________________________</span>
  </div>
</div>

</body>
</html>

<?php
// export_equipos_pdf.php — PDF / Print-ready page for equipos
require_once dirname(__DIR__).'/includes/auth.php';
$q=(string)($_GET['q']??''); $id=(int)($_GET['id']??0);
if($id){ $eq=getEquipoById($id); $equipos=$eq?[$eq]:[]; }
else { $equipos=getEquipos($q); }
?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
<title>Reporte Equipos TI</title>
<style>
body{font-family:Arial,sans-serif;font-size:8pt;margin:0;}
h2{color:#1a3a5c;margin:0 0 4px;}
.sub{color:#888;font-size:7pt;margin-bottom:10px;}
table{width:100%;border-collapse:collapse;page-break-inside:auto;}
th{background:#1a3a5c;color:#fff;padding:4px 6px;font-size:7pt;text-transform:uppercase;letter-spacing:.3px;}
td{padding:3px 6px;font-size:7.5pt;border-bottom:1px solid #eee;vertical-align:top;}
tr:nth-child(even) td{background:#f5f8ff;}
code{font-size:7pt;background:#eee;padding:1px 4px;border-radius:2px;}
.badge{border-radius:3px;padding:1px 5px;font-size:7pt;color:#fff;}
.ACTIVO{background:#198754;}.MANTENIMIENTO{background:#fd7e14;}.BAJA{background:#dc3545;}.INACTIVO{background:#6c757d;}
@page{margin:12mm;}
@media print{.no-print{display:none;}}
</style></head><body>
<div class="no-print" style="text-align:center;background:#fff3cd;padding:10px;margin-bottom:10px;">
  <strong>Para guardar como PDF:</strong> Ctrl+P → "Guardar como PDF"
  &nbsp;<button onclick="window.print()" style="background:#1a3a5c;color:#fff;border:none;padding:5px 14px;border-radius:4px;cursor:pointer;">🖨 Imprimir</button>
</div>
<h2>📋 Reporte de Equipos TI</h2>
<div class="sub">Generado: <?=date('d/m/Y H:i')?> — Total: <?=count($equipos)?> equipo(s)</div>
<table><thead><tr><th>Folio</th><th>Tipo</th><th>Marca/Modelo</th><th>Serie</th><th>IP</th><th>MAC</th><th>Nodo</th><th>Área</th><th>Usuario</th><th>Estado</th><th>Ubicación</th></tr></thead>
<tbody>
<?php foreach($equipos as $e): ?>
<tr>
  <td><strong><?=h($e['folio'])?></strong></td><td><?=h($e['tipo_nombre']??'')?></td>
  <td><?=h($e['marca'])?><br><span style="color:#888;font-size:7pt;"><?=h($e['modelo']??'')?></span></td>
  <td><code><?=h($e['serie']??'')?></code></td><td><code><?=h($e['ip']??'')?></code></td>
  <td><code><?=h($e['mac_address']??'')?></code></td><td><?=h($e['nodo']??'')?></td>
  <td><?=h($e['area_nombre']??'')?></td><td><?=h(trim($e['usuario_nombre']??''))?></td>
  <td><span class="badge <?=h($e['estado'])?>"><?=h($e['estado'])?></span></td>
  <td><?=h($e['ubicacion']??'')?></td>
</tr>
<?php endforeach; ?>
</tbody></table></body></html>

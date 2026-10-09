<?php
require_once dirname(__DIR__).'/includes/auth.php';
$q      = trim($_GET['q'] ?? '');
$areaId = (int)($_GET['area'] ?? 0);
$agrupado = getDirectorioAgrupado($q, $areaId);
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Directorio Telefónico IP</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;font-size:8pt;margin:0;color:#111;}
h1{color:#c0392b;font-size:13pt;margin:0 0 3px;}
.sub{font-size:7pt;color:#888;margin-bottom:10px;}
.area-block{margin-bottom:12px;page-break-inside:avoid;}
.area-title{font-weight:bold;font-size:8pt;padding:5px 8px;letter-spacing:.4px;text-transform:uppercase;color:#fff;}
table{width:100%;border-collapse:collapse;}
td{padding:3px 7px;font-size:7.5pt;border-bottom:1px solid #f0f0f0;vertical-align:top;}
.ext{background:#c0392b;color:#fff;font-weight:bold;padding:1px 6px;border-radius:3px;font-size:7pt;}
.nombre{font-weight:bold;}
.puesto{color:#555;font-size:7pt;}
.tel{color:#1a6ea8;font-size:7pt;}
.ubic{color:#888;font-size:6.5pt;font-style:italic;}
.grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.no-print{background:#fff3cd;border:1px solid #ffc107;padding:8px 14px;margin-bottom:10px;border-radius:4px;text-align:center;}
.no-print button{background:#c0392b;color:#fff;border:none;padding:4px 14px;border-radius:3px;cursor:pointer;}
@page{margin:12mm;}
@media print{.no-print{display:none!important;}.grid{display:block;columns:3;column-gap:10px;}.area-block{break-inside:avoid;}}
</style>
</head>
<body>
<div class="no-print">
  <strong>Directorio Telefónico IP</strong> &nbsp;|&nbsp;
  Para guardar como PDF: Ctrl+P → "Guardar como PDF"
  <button onclick="window.print()">🖨 Imprimir / PDF</button>
  <a href="../directorio.php" style="margin-left:10px;color:#c0392b;font-size:8pt;">← Volver</a>
</div>
<h1>📞 Directorio Telefónico IP</h1>
<div class="sub">Generado: <?=date('d/m/Y H:i')?><?=$q?" &bull; Búsqueda: <strong>".htmlspecialchars($q)."</strong>":''?></div>
<div class="grid">
<?php foreach($agrupado as $area=>$entradas):
  $color=$entradas[0]['color_area']??'#c0392b';
?>
<div class="area-block">
  <div class="area-title" style="background:<?=htmlspecialchars($color)?>;"><?=htmlspecialchars(strtoupper($area))?></div>
  <table><tbody>
  <?php foreach($entradas as $e): ?>
  <tr>
    <td style="width:46px;"><?php if($e['extension']): ?><span class="ext"><?=htmlspecialchars($e['extension'])?></span><?php endif; ?></td>
    <td>
      <?php if($e['nombre_completo']): ?><div class="nombre"><?=htmlspecialchars($e['nombre_completo'])?></div><?php endif; ?>
      <?php if($e['puesto_cargo']): ?><div class="puesto"><?=htmlspecialchars($e['puesto_cargo'])?></div><?php endif; ?>
      <?php if($e['telefono_directo']): ?><div class="tel"><?=htmlspecialchars($e['telefono_directo'])?></div><?php endif; ?>
      <?php if($e['ubicacion']): ?><div class="ubic"><?=htmlspecialchars($e['ubicacion'])?></div><?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php endforeach; ?>
</div>
</body></html>

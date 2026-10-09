<?php
require_once dirname(__DIR__).'/includes/auth.php';
$segmentoId = (int)($_GET['id'] ?? 0);
$tipo = $_GET['tipo'] ?? 'todos'; // 'usadas', 'libres', 'todos'

if ($segmentoId) {
    $segmento = getSegmentoIPById($segmentoId);
    if (!$segmento) {
        header('Location: ../segmentos_ip.php');
        exit;
    }
    $segmentos = [$segmento];
} else {
    $segmentos = getSegmentosIP();
}
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Segmentos de Red IP</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;font-size:9pt;margin:0;color:#111;}
h1{color:#1a3a5c;font-size:14pt;margin:0 0 5px;}
.sub{font-size:8pt;color:#888;margin-bottom:15px;}
.seg-block{margin-bottom:15px;page-break-inside:avoid;}
.seg-header{font-weight:bold;font-size:9pt;padding:6px 10px;letter-spacing:.4px;text-transform:uppercase;color:#fff;margin-bottom:8px;}
table{width:100%;border-collapse:collapse;}
th{background:#f5f5f5;padding:4px 8px;font-size:8pt;font-weight:bold;text-align:left;border-bottom:2px solid #ddd;}
td{padding:4px 8px;font-size:8pt;border-bottom:1px solid #eee;vertical-align:middle;}
.ip-asignada{color:#1a3a5c;font-weight:bold;}
.ip-libre{color:#28a745;font-weight:bold;}
.badge-asignada{background:#dc3545;color:#fff;padding:2px 6px;border-radius:3px;font-size:7pt;}
.badge-libre{background:#28a745;color:#fff;padding:2px 6px;border-radius:3px;font-size:7pt;}
.kpi-box{display:inline-block;padding:8px 15px;margin-right:10px;border:1px solid #ddd;border-radius:4px;text-align:center;}
.kpi-num{font-size:14pt;font-weight:bold;}
.kpi-label{font-size:7pt;color:#666;text-transform:uppercase;}
.no-print{background:#fff3cd;border:1px solid #ffc107;padding:10px 15px;margin-bottom:15px;border-radius:4px;text-align:center;}
.no-print button{background:#1a3a5c;color:#fff;border:none;padding:6px 16px;border-radius:4px;cursor:pointer;margin:0 5px;}
.no-print a{color:#1a3a5c;font-size:8pt;}
@page{margin:15mm;}
@media print{.no-print{display:none!important;}.seg-block{break-inside:avoid;}}
</style>
</head>
<body>
<div class="no-print">
  <strong>Segmentos de Red IP</strong> &nbsp;|&nbsp;
  Para guardar como PDF: Ctrl+P → "Guardar como PDF"
  <button onclick="window.print()">🖨 Imprimir / PDF</button>
  <a href="../segmentos_ip.php" style="margin-left:10px;">← Volver</a>
</div>
<h1>🌐 Segmentos de Red IP</h1>
<div class="sub">Generado: <?=date('d/m/Y H:i')?></div>

<?php foreach($segmentos as $seg):
  $red = $seg['red'];
  $nombre = $seg['nombre'];
  $color = $seg['color'];
  $ipsUsadas = getIpsUsadasPorSegmento($red);
  $ipsLibres = getIpsLibresPorSegmento($red);
  $totalUsadas = count($ipsUsadas);
  $totalLibres = count($ipsLibres);
  $pctUsado = $totalUsadas > 0 ? round(($totalUsadas/254)*100,1) : 0;
?>
<div class="seg-block">
  <div class="seg-header" style="background:<?=htmlspecialchars($color)?>;">
    <?=htmlspecialchars($nombre)?> — <?=htmlspecialchars($red)?>.0/24
  </div>
  
  <div style="margin-bottom:10px;">
    <div class="kpi-box">
      <div class="kpi-num" style="color:#dc3545;"><?=$totalUsadas?></div>
      <div class="kpi-label">Usadas</div>
    </div>
    <div class="kpi-box">
      <div class="kpi-num" style="color:#28a745;"><?=$totalLibres?></div>
      <div class="kpi-label">Libres</div>
    </div>
    <div class="kpi-box">
      <div class="kpi-num">254</div>
      <div class="kpi-label">Total</div>
    </div>
    <div class="kpi-box">
      <div class="kpi-num"><?=$pctUsado?>%</div>
      <div class="kpi-label">Utilizado</div>
    </div>
  </div>
  
  <?php if (($tipo === 'todos' || $tipo === 'usadas') && !empty($ipsUsadas)): ?>
  <h3 style="font-size:9pt;margin:10px 0 5px;color:#1a3a5c;">IPs Asignadas (<?=$totalUsadas?>)</h3>
  <table>
    <thead>
      <tr><th>IP</th><th>Estado</th><th>Folio</th><th>Marca/Modelo</th><th>Usuario</th></tr>
    </thead>
    <tbody>
    <?php foreach($ipsUsadas as $ip): ?>
    <tr>
      <td class="ip-asignada"><?=htmlspecialchars($ip['ip'])?></td>
      <td><span class="badge-asignada">ASIGNADA</span></td>
      <td><?=htmlspecialchars($ip['folio']??'')?></td>
      <td><?=htmlspecialchars($ip['marca'].' '.($ip['modelo']??''))?></td>
      <td><?=htmlspecialchars(trim($ip['usuario']??''))?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  
  <?php if (($tipo === 'todos' || $tipo === 'libres') && !empty($ipsLibres)): ?>
  <h3 style="font-size:9pt;margin:15px 0 5px;color:#28a745;">IPs Libres (<?=$totalLibres?>)</h3>
  <table>
    <thead>
      <tr><th>IP</th><th>Estado</th></tr>
    </thead>
    <tbody>
    <?php foreach($ipsLibres as $ip): ?>
    <tr>
      <td class="ip-libre"><?=htmlspecialchars($ip['ip'])?></td>
      <td><span class="badge-libre">LIBRE</span></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
  
  <?php if (empty($ipsUsadas) && empty($ipsLibres)): ?>
  <p style="color:#888;font-style:italic;">No hay IPs registradas en este segmento</p>
  <?php endif; ?>
</div>
<?php endforeach; ?>

</body></html>

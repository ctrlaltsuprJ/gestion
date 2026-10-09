<?php
require_once dirname(__DIR__).'/includes/auth.php';
$id  = (int)($_GET['id'] ?? 0);
if (!$id) {
    // Reporte general
    $incs = getBitacora($_GET['q']??'', $_GET['tipo']??'');
    $modo = 'lista';
} else {
    $inc    = getBitacoraById($id);
    $eqAfec = getEquiposAfectados($id);
    $modo   = 'detalle';
}
$TIPOS = ['CORTE_LUZ'=>'⚡ Corte de Luz','FALLA_RED'=>'🌐 Falla de Red','FALLA_SERVIDOR'=>'🖥 Falla Servidor','FALLA_CAMARA'=>'📷 Falla Cámara','DAÑO_EQUIPO'=>'🔧 Daño en Equipo','INTRUSION'=>'🔒 Intrusión','DESASTRE_NATURAL'=>'🌩 Desastre Natural','FALLA_SITE'=>'🏢 Falla SITE','VANDALISMO'=>'⚠ Vandalismo','ERROR_PERSONAL'=>'👤 Error Personal','MANTENIMIENTO_EXTERNO'=>'🔩 Mant. Externo','OTRO'=>'📋 Otro'];
$GRAV_COLOR = ['CRITICO'=>'#dc3545','GRAVE'=>'#fd7e14','MODERADO'=>'#ffc107','MENOR'=>'#198754','INFORMATIVO'=>'#6c757d'];
?><!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Bitácora de Incidentes</title>
<style>
body{font-family:Arial,Helvetica,sans-serif;font-size:8.5pt;color:#111;margin:0;}
h1{color:#1a3a5c;font-size:13pt;margin:0 0 3px;}
.sub{font-size:7pt;color:#888;margin-bottom:10px;}
.no-print{background:#fff3cd;border:1px solid #ffc107;padding:8px;text-align:center;margin-bottom:10px;border-radius:4px;}
.no-print button{background:#1a3a5c;color:#fff;border:none;padding:4px 14px;border-radius:3px;cursor:pointer;}
table{width:100%;border-collapse:collapse;}
th{background:#1a3a5c;color:#fff;padding:4px 7px;font-size:7.5pt;text-align:left;}
td{padding:3px 7px;font-size:8pt;border-bottom:1px solid #eee;vertical-align:top;}
tr:nth-child(even) td{background:#f9f9f9;}
.folio{font-weight:bold;color:#1a3a5c;font-family:monospace;}
.grav-badge{border-radius:3px;padding:1px 6px;color:#fff;font-size:7pt;font-weight:bold;}
.imp-yes{color:#dc3545;font-weight:bold;}
.imp-no{color:#aaa;}
.section{margin-bottom:14px;}
.section-title{background:#1a3a5c;color:#fff;padding:4px 8px;font-weight:bold;font-size:8pt;margin-bottom:6px;}
.detail-row{display:flex;gap:10px;margin-bottom:4px;}
.detail-label{font-weight:bold;color:#555;min-width:140px;font-size:7.5pt;}
.detail-val{font-size:8pt;}
@page{margin:14mm;}
@media print{.no-print{display:none!important;}}
</style></head>
<body>
<div class="no-print">
  Bitácora de Incidentes &nbsp;|&nbsp; Ctrl+P para imprimir/PDF
  <button onclick="window.print()">🖨 Imprimir</button>
  <a href="../bitacora.php" style="margin-left:10px;color:#1a3a5c;font-size:8pt;">← Volver</a>
</div>

<?php if ($modo === 'lista'): ?>
<h1>📋 Bitácora de Incidentes</h1>
<div class="sub">Generado: <?=date('d/m/Y H:i')?> — <?=count($incs)?> incidente(s)</div>
<table>
  <thead><tr><th>Folio</th><th>Tipo</th><th>Gravedad</th><th>Título</th><th>Inicio</th><th>Estado</th><th>SITE</th><th>Cámaras</th><th>Red</th><th>Técnico</th></tr></thead>
  <tbody>
  <?php foreach($incs as $inc):
    $gc = $GRAV_COLOR[$inc['gravedad']]??'#6c757d';
  ?>
  <tr>
    <td class="folio"><?=htmlspecialchars($inc['folio']??'')?></td>
    <td style="font-size:7pt;"><?=htmlspecialchars($TIPOS[$inc['tipo']]??$inc['tipo'])?></td>
    <td><span class="grav-badge" style="background:<?=$gc?>"><?=htmlspecialchars($inc['gravedad'])?></span></td>
    <td><?=htmlspecialchars($inc['titulo']??'')?></td>
    <td style="white-space:nowrap;"><?=htmlspecialchars(substr($inc['fecha_inicio']??'',0,16))?></td>
    <td><?=htmlspecialchars(str_replace('_',' ',$inc['estado']??''))?></td>
    <td class="<?=$inc['afecta_site']?'imp-yes':'imp-no'?>"><?=$inc['afecta_site']?'SÍ':'—'?></td>
    <td class="<?=$inc['afecta_camaras']?'imp-yes':'imp-no'?>"><?=$inc['afecta_camaras']?'SÍ':'—'?></td>
    <td class="<?=$inc['afecta_red']?'imp-yes':'imp-no'?>"><?=$inc['afecta_red']?'SÍ':'—'?></td>
    <td style="font-size:7pt;"><?=htmlspecialchars(mb_substr($inc['tecnico_atiende']??'',0,25))?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php else: // Detalle de un incidente
  $gc = $GRAV_COLOR[$inc['gravedad']??'']??'#6c757d';
?>
<h1>📋 Incidente: <?=htmlspecialchars($inc['folio']??'')?></h1>
<div class="sub">Generado: <?=date('d/m/Y H:i')?></div>

<div class="section">
  <div class="section-title">Información General</div>
  <div class="detail-row"><div class="detail-label">Folio:</div><div class="detail-val" style="font-family:monospace;font-weight:bold;"><?=htmlspecialchars($inc['folio']??'')?></div></div>
  <div class="detail-row"><div class="detail-label">Título:</div><div class="detail-val"><strong><?=htmlspecialchars($inc['titulo']??'')?></strong></div></div>
  <div class="detail-row"><div class="detail-label">Tipo:</div><div class="detail-val"><?=htmlspecialchars($TIPOS[$inc['tipo']??'']??'')?></div></div>
  <div class="detail-row"><div class="detail-label">Gravedad:</div><div class="detail-val"><span class="grav-badge" style="background:<?=$gc?>"><?=htmlspecialchars($inc['gravedad']??'')?></span></div></div>
  <div class="detail-row"><div class="detail-label">Estado:</div><div class="detail-val"><?=htmlspecialchars(str_replace('_',' ',$inc['estado']??''))?></div></div>
  <div class="detail-row"><div class="detail-label">Fecha Inicio:</div><div class="detail-val"><?=htmlspecialchars(substr($inc['fecha_inicio']??'',0,16))?></div></div>
  <?php if($inc['fecha_fin']): ?><div class="detail-row"><div class="detail-label">Fecha Fin:</div><div class="detail-val"><?=htmlspecialchars(substr($inc['fecha_fin'],0,16))?></div></div><?php endif; ?>
  <?php if($inc['duracion_minutos']): ?><div class="detail-row"><div class="detail-label">Duración:</div><div class="detail-val"><?=htmlspecialchars($inc['duracion_minutos'])?> minutos</div></div><?php endif; ?>
</div>

<div class="section">
  <div class="section-title">Descripción</div>
  <?php if($inc['descripcion']): ?><p style="margin:0 0 6px;"><?=nl2br(htmlspecialchars($inc['descripcion']))?></p><?php endif; ?>
  <?php if($inc['causa_probable']): ?><p style="margin:0 0 6px;"><strong>Causa probable:</strong> <?=nl2br(htmlspecialchars($inc['causa_probable']))?></p><?php endif; ?>
  <?php if($inc['acciones_tomadas']): ?><p style="margin:0 0 6px;"><strong>Acciones tomadas:</strong> <?=nl2br(htmlspecialchars($inc['acciones_tomadas']))?></p><?php endif; ?>
  <?php if($inc['recomendaciones']): ?><p style="margin:0;"><strong>Recomendaciones:</strong> <?=nl2br(htmlspecialchars($inc['recomendaciones']))?></p><?php endif; ?>
</div>

<div class="section">
  <div class="section-title">Impacto</div>
  <?php $impRows=[['afecta_site','SITE/Servidores'],['afecta_camaras','Cámaras'],['sin_grabacion','Sin Grabación'],['afecta_red','Red/Conectividad'],['perdida_datos','Pérdida de Datos']];?>
  <table><tbody>
  <?php foreach($impRows as [$k,$l]): ?><tr><td style="width:160px;font-weight:bold;"><?=$l?></td><td class="<?=$inc[$k]?'imp-yes':'imp-no'?>"><?=$inc[$k]?'SÍ AFECTÓ':'No afectó'?></td></tr><?php endforeach; ?>
  <?php if($inc['tiempo_sin_grabacion']): ?><tr><td style="font-weight:bold;">Tiempo sin grabación:</td><td><?=htmlspecialchars($inc['tiempo_sin_grabacion'])?></td></tr><?php endif; ?>
  </tbody></table>
</div>

<?php if(!empty($eqAfec)): ?>
<div class="section">
  <div class="section-title">Equipos Afectados</div>
  <table><thead><tr><th>Folio</th><th>Equipo</th><th>Nivel de Daño</th><th>Detalle</th></tr></thead><tbody>
  <?php foreach($eqAfec as $ea): ?><tr><td><?=htmlspecialchars($ea['folio']??'')?></td><td><?=htmlspecialchars($ea['marca'].' '.($ea['modelo']??''))?></td><td><?=htmlspecialchars(str_replace('_',' ',$ea['daño_nivel']??''))?></td><td><?=htmlspecialchars($ea['descripcion']??'')?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>

<div class="section">
  <div class="section-title">Responsables</div>
  <?php foreach([['Reportado por',$inc['reportado_por']??''],['Técnico TI',$inc['tecnico_atiende']??''],['Personal involucrado',$inc['personal_responsable']??''],['Tipo personal',str_replace('_',' ',$inc['tipo_personal']??'')]] as [$k,$v]): if(!$v) continue; ?>
  <div class="detail-row"><div class="detail-label"><?=$k?>:</div><div class="detail-val"><?=htmlspecialchars($v)?></div></div>
  <?php endforeach; ?>
</div>

<!-- Firmas -->
<div style="display:flex;gap:50px;margin-top:40px;">
  <div style="flex:1;border-top:1px solid #333;padding-top:6px;text-align:center;"><strong style="font-size:8pt;">TÉCNICO RESPONSABLE</strong><br><span style="font-size:7.5pt;color:#555;"><?=htmlspecialchars($inc['tecnico_atiende']??'________________________')?></span></div>
  <div style="flex:1;border-top:1px solid #333;padding-top:6px;text-align:center;"><strong style="font-size:8pt;">REGISTRADO POR</strong><br><span style="font-size:7.5pt;color:#555;"><?=htmlspecialchars($inc['registrado_por_nombre']??'________________________')?></span></div>
  <div style="flex:1;border-top:1px solid #333;padding-top:6px;text-align:center;"><strong style="font-size:8pt;">AUTORIZA</strong><br><span style="font-size:7.5pt;color:#555;">________________________</span></div>
</div>
<?php endif; ?>
</body></html>

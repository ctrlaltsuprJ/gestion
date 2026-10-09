<?php
require_once dirname(__DIR__).'/includes/auth.php';
$tickets=getTickets();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment;filename="tickets_ti_'.date('Ymd_His').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out,['Folio Ticket','Equipo','Marca/Modelo','Usuario','Tipo Servicio','Prioridad','Estado','Técnico','Descripción','Apertura','Cierre']);
foreach($tickets as $t){ fputcsv($out,[$t['folio_ticket'],$t['equipo_folio'],$t['equipo_marca'].' '.($t['equipo_modelo']??''),trim($t['usuario_nombre']??''),$t['tipo_servicio'],$t['prioridad'],$t['estado'],$t['tecnico'],$t['descripcion'],substr($t['fecha_apertura']??'',0,16),substr($t['fecha_cierre']??'',0,16)]); }
fclose($out); exit;

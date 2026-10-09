<?php // export_inventario_xls.php
require_once dirname(__DIR__).'/includes/auth.php';
$inv=getInventario();
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment;filename="inventario_ti_'.date('Ymd_His').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out,['ID','Tipo','Marca','Modelo','Serie','Cantidad','Estado','Equipo Asignado','Usuario','Área','Notas']);
foreach($inv as $i){ fputcsv($out,[$i['id'],$i['tipo'],$i['marca'],$i['modelo'],$i['serie'],$i['cantidad'],$i['estado'],$i['equipo_folio']??'',trim($i['usuario_nombre']??''),$i['area_nombre']??'',$i['notas']]); }
fclose($out); exit;

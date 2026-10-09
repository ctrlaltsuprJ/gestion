<?php
// export_equipos_xls.php
require_once dirname(__DIR__).'/includes/auth.php';
$q=(string)($_GET['q']??'');
$equipos=getEquipos($q);
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment;filename="equipos_ti_'.date('Ymd_His').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','w');
fputcsv($out,['Folio','Tipo','Marca','Modelo','Serie','IP','MAC Address','Nodo','Conexión','Área','Usuario','Ubicación','Estado','F.Adquisición','Descripción']);
foreach($equipos as $e){ fputcsv($out,[$e['folio'],$e['tipo_nombre'],$e['marca'],$e['modelo'],$e['serie'],$e['ip'],$e['mac_address'],$e['nodo'],$e['conexion_medio'],$e['area_nombre'],trim($e['usuario_nombre']??''),$e['ubicacion'],$e['estado'],$e['fecha_adquisicion'],$e['descripcion']]); }
fclose($out); exit;

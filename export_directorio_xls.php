<?php
require_once dirname(__DIR__).'/includes/auth.php';
$q      = trim($_GET['q'] ?? '');
$areaId = (int)($_GET['area'] ?? 0);
$rows   = getDirectorio($q, $areaId);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment;filename="directorio_ip_'.date('Ymd_His').'.csv"');
echo "\xEF\xBB\xBF"; // BOM para Excel

$out = fopen('php://output','w');
fputcsv($out,['Área','Extensión IP','Nombre Completo','Puesto / Cargo','Teléfono Directo','Ubicación','Notas']);
foreach($rows as $r){
    fputcsv($out,[
        $r['area_nombre'],
        $r['extension'],
        $r['nombre_completo']??'',
        $r['puesto_cargo']??'',
        $r['telefono_directo']??'',
        $r['ubicacion']??'',
        $r['notas']??'',
    ]);
}
fclose($out); exit;

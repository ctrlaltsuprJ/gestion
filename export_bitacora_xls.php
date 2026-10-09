<?php
require_once dirname(__DIR__).'/includes/auth.php';
$incs = getBitacora(trim($_GET['q']??''), $_GET['tipo']??'', $_GET['gravedad']??'', $_GET['estado']??'');
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment;filename="bitacora_incidentes_'.date('Ymd_His').'.csv"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output','w');
fputcsv($out,['Folio','Tipo','Gravedad','Estado','Título','Descripción','Causa Probable',
              'Acciones Tomadas','Recomendaciones','Fecha Inicio','Fecha Fin','Duración (min)',
              'Afecta SITE','Afecta Cámaras','Sin Grabación','Tiempo Sin Grabación',
              'Afecta Red','Pérdida Datos','Reportado Por','Técnico TI','Personal Involucrado',
              'Sistemas Afectados','Equipos Dañados']);
foreach($incs as $i){
    fputcsv($out,[
        $i['folio'],$i['tipo'],$i['gravedad'],$i['estado'],$i['titulo'],
        $i['descripcion'],$i['causa_probable']??'',$i['acciones_tomadas']??'',
        $i['recomendaciones']??'',
        substr($i['fecha_inicio']??'',0,16), substr($i['fecha_fin']??'',0,16),
        $i['duracion_minutos']??'',
        $i['afecta_site']?'SÍ':'NO', $i['afecta_camaras']?'SÍ':'NO',
        $i['sin_grabacion']?'SÍ':'NO', $i['tiempo_sin_grabacion']??'',
        $i['afecta_red']?'SÍ':'NO', $i['perdida_datos']?'SÍ':'NO',
        $i['reportado_por']??'',$i['tecnico_atiende']??'',$i['personal_responsable']??'',
        $i['sistemas_afectados']??'',$i['equipos_dañados']??'',
    ]);
}
fclose($out); exit;

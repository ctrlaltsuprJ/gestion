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

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment;filename="segmentos_ip_'.date('Ymd_His').'.csv"');
echo "\xEF\xBB\xBF"; // BOM para Excel

$out = fopen('php://output','w');
fputcsv($out,['Segmento','Red','IP','Estado','Folio Equipo','Marca','Modelo','Usuario']);

foreach($segmentos as $seg){
    $red = $seg['red'];
    $nombre = $seg['nombre'];
    
    if ($tipo === 'todos' || $tipo === 'usadas') {
        $ipsUsadas = getIpsUsadasPorSegmento($red);
        foreach($ipsUsadas as $ip){
            fputcsv($out,[
                $nombre,
                $red.'.0/24',
                $ip['ip'],
                'ASIGNADA',
                $ip['folio']??'',
                $ip['marca']??'',
                $ip['modelo']??'',
                trim($ip['usuario']??'')
            ]);
        }
    }
    
    if ($tipo === 'todos' || $tipo === 'libres') {
        $ipsLibres = getIpsLibresPorSegmento($red);
        foreach($ipsLibres as $ip){
            fputcsv($out,[
                $nombre,
                $red.'.0/24',
                $ip['ip'],
                'LIBRE',
                '',
                '',
                '',
                ''
            ]);
        }
    }
}
fclose($out); exit;

<?php
/**
 * Exporta la Bitácora Diaria a Excel (HTML servido con cabeceras xls, sin dependencias).
 * Acepta los mismos filtros que bitacora_diaria.php (q, tipo_etiqueta, valor_etiqueta,
 * fecha_desde, fecha_hasta, suser_id), o ?id=ID para una sola entrada.
 */
require_once __DIR__.'/../includes/auth.php';
requireRol(ROL_ADMIN, ROL_SUPER);
require_once __DIR__.'/../includes/bitacora_diaria_functions.php';

$id = (int)($_GET['id'] ?? 0);
$tiposEtiqueta = getTiposEtiquetaDiaria();

if ($id) {
    $entradas = array_filter([getBitacoraDiariaById($id)]);
} else {
    $filtros = [
        'q'              => trim($_GET['q'] ?? ''),
        'tipo_etiqueta'  => $_GET['tipo_etiqueta'] ?? '',
        'valor_etiqueta' => trim($_GET['valor_etiqueta'] ?? ''),
        'fecha_desde'    => $_GET['fecha_desde'] ?? '',
        'fecha_hasta'    => $_GET['fecha_hasta'] ?? '',
        'suser_id'       => $_GET['suser_id'] ?? '',
    ];
    $entradas = getBitacoraDiaria($filtros);
}

$filename = 'bitacora_diaria_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

echo "\xEF\xBB\xBF"; // BOM para acentos/ñ
?>
<table border="1" cellpadding="4" cellspacing="0">
<thead>
<tr style="background:#1a3a5c;color:#ffffff;font-weight:bold;">
<th>Fecha</th>
<th>Autor</th>
<th>Título</th>
<th>Contenido</th>
<th>Etiquetas</th>
</tr>
</thead>
<tbody>
<?php foreach ($entradas as $e):
    $tags = [];
    foreach ($e['etiquetas'] as $et) {
        $tags[] = ($tiposEtiqueta[$et['tipo']] ?? $et['tipo']) . ': ' . $et['valor'];
    }
?>
<tr>
  <td><?= htmlspecialchars(date('d/m/Y', strtotime($e['fecha']))) ?></td>
  <td><?= htmlspecialchars($e['autor_nombre'] ?? '') ?></td>
  <td><?= htmlspecialchars($e['titulo']) ?></td>
  <td><?= nl2br(htmlspecialchars($e['contenido'])) ?></td>
  <td><?= htmlspecialchars(implode(' | ', $tags)) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

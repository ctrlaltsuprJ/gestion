<?php
/**
 * Vista imprimible de la Bitácora Diaria (para "Guardar como PDF").
 * ?id=ID              -> exporta una sola entrada completa
 * ?q=&tipo_etiqueta=&valor_etiqueta=&fecha_desde=&fecha_hasta=&suser_id= -> exporta el listado filtrado
 */
require_once __DIR__.'/../includes/auth.php';
requireRol(ROL_ADMIN, ROL_SUPER);
require_once __DIR__.'/../includes/bitacora_diaria_functions.php';

$id = (int)($_GET['id'] ?? 0);
$tiposEtiqueta = getTiposEtiquetaDiaria();

if ($id) {
    $entradas = array_filter([getBitacoraDiariaById($id)]);
    $titulo = 'Entrada individual';
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
    $titulo = array_filter($filtros) ? 'Resultados filtrados' : 'Todas las entradas';
}

$colorTipo = [
    'FOLIO_EQUIPO' => '#0d6efd',
    'IP'           => '#0dcaf0',
    'USUARIO'      => '#198754',
    'AREA'         => '#ffc107',
    'OTRO'         => '#6c757d',
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Bitácora Diaria — <?= htmlspecialchars($titulo) ?></title>
<style>
  body{font-family: Arial, Helvetica, sans-serif; font-size:12px; color:#222; margin:24px;}
  h1{font-size:19px;color:#1a3a5c;margin:0 0 2px;}
  .subtitulo{font-size:13px;color:#444;margin-bottom:2px;}
  .fecha{color:#777;font-size:11px;margin-bottom:18px;}
  .entrada{border:1px solid #ddd;border-radius:4px;padding:12px 14px;margin-bottom:16px;page-break-inside:avoid;}
  .entrada h2{font-size:14px;color:#1a3a5c;margin:0 0 4px;}
  .meta{color:#777;font-size:10.5px;margin-bottom:8px;}
  .contenido{white-space:pre-wrap;font-size:11.5px;margin-bottom:8px;}
  .tag{display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:10px;margin:0 4px 4px 0;}
  .btn-print{margin-bottom:18px;padding:9px 18px;background:#1a3a5c;color:#fff;border:none;border-radius:4px;cursor:pointer;font-size:13px;}
  @media print { .no-print{display:none;} body{margin:0;} }
</style>
</head>
<body>

<button class="btn-print no-print" onclick="window.print()">🖨 Imprimir / Guardar como PDF</button>

<h1>Bitácora Diaria de Equipos</h1>
<div class="subtitulo"><?= htmlspecialchars($titulo) ?> — <?= count($entradas) ?> entrada(s)</div>
<div class="fecha">Generado el <?= date('d/m/Y H:i') ?></div>

<?php if (empty($entradas)): ?>
<p style="color:#888;">No hay entradas que coincidan.</p>
<?php endif; ?>

<?php foreach ($entradas as $e): ?>
<div class="entrada">
  <h2><?= htmlspecialchars($e['titulo']) ?></h2>
  <div class="meta">
    <?= date('d/m/Y', strtotime($e['fecha'])) ?> · Firmado por <strong><?= htmlspecialchars($e['autor_nombre'] ?? 'Desconocido') ?></strong>
    <?php if (!empty($e['updated_at'])): ?> · editado <?= date('d/m/Y H:i', strtotime($e['updated_at'])) ?><?php endif; ?>
  </div>
  <div class="contenido"><?= htmlspecialchars($e['contenido']) ?></div>
  <?php if (!empty($e['etiquetas'])): ?>
  <div>
    <?php foreach ($e['etiquetas'] as $et): ?>
    <span class="tag" style="background:<?= $colorTipo[$et['tipo']] ?? '#6c757d' ?>;"><?= htmlspecialchars($tiposEtiqueta[$et['tipo']] ?? $et['tipo']) ?>: <?= htmlspecialchars($et['valor']) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

</body>
</html>

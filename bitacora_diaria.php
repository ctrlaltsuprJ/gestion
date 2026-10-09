<?php
require_once 'includes/auth.php';
require_once 'includes/bitacora_diaria_functions.php';
requireRol(ROL_ADMIN, ROL_SUPER);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRol(ROL_ADMIN); // solo administrador puede escribir en la bitácora diaria
    $pid = (int)($_POST['id'] ?? 0) ?: null;
    $bitId = saveBitacoraDiaria($_POST, $_POST['etiquetas'] ?? '', $pid);
    flash('success', $pid ? 'Entrada actualizada.' : 'Entrada creada.');
    redirect('bitacora_diaria.php?action=view&id=' . $bitId);
}

if ($action === 'delete' && $id) {
    requireRol(ROL_ADMIN);
    deleteBitacoraDiaria($id);
    flash('warning', 'Entrada eliminada.');
    redirect('bitacora_diaria.php');
}

$pageTitle = 'Bitácora Diaria';
include 'includes/header.php';

$tiposEtiqueta = getTiposEtiquetaDiaria();
$autores       = getAutoresBitacoraDiaria();
$stats         = getEstadisticasBitacoraDiaria();

// --- Filtros de búsqueda (GET) ---
$filtros = [
    'q'              => trim($_GET['q'] ?? ''),
    'tipo_etiqueta'  => $_GET['tipo_etiqueta'] ?? '',
    'valor_etiqueta' => trim($_GET['valor_etiqueta'] ?? ''),
    'fecha_desde'    => $_GET['fecha_desde'] ?? '',
    'fecha_hasta'    => $_GET['fecha_hasta'] ?? '',
    'suser_id'       => $_GET['suser_id'] ?? '',
];
$hayFiltros = array_filter($filtros) !== [];

$entradaEdit = null;
if ($action === 'edit' && $id) $entradaEdit = getBitacoraDiariaById($id);
$isEdit = !empty($entradaEdit);

$entradaView = null;
if ($action === 'view' && $id) $entradaView = getBitacoraDiariaById($id);

$entradas = in_array($action, ['list','buscar']) ? getBitacoraDiaria($filtros) : [];

// Construye la query string de los filtros actuales (para pasarla a los exports)
$qsFiltros = http_build_query(array_filter($filtros));

function excerptTexto(string $texto, int $len = 220): string {
    $plano = trim(preg_replace('/\s+/', ' ', $texto));
    return mb_strlen($plano) > $len ? mb_substr($plano, 0, $len) . '…' : $plano;
}

$colorTipo = [
    'FOLIO_EQUIPO' => 'bg-primary',
    'IP'           => 'bg-info text-dark',
    'USUARIO'      => 'bg-success',
    'AREA'         => 'bg-warning text-dark',
    'OTRO'         => 'bg-secondary',
];
?>
<div class="container-fluid px-3 py-3">

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-journal-richtext me-2"></i>Bitácora Diaria de Equipos</h5>
  <div class="d-flex gap-2 flex-wrap">
    <a href="exports/export_bitacora_diaria_xls.php?<?=$qsFiltros?>" class="btn btn-sm btn-outline-success" title="Exportar a Excel">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
    <a href="exports/export_bitacora_diaria_pdf.php?<?=$qsFiltros?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Exportar a PDF">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </a>
    <?php if (isAdmin()): ?>
    <a href="bitacora_diaria.php?action=new" class="btn btn-sm" style="background:#1a3a5c;color:#fff;">
      <i class="bi bi-plus me-1"></i>Nueva Entrada
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card h-100" style="border-top:4px solid #1a3a5c;">
      <div class="card-body py-2 text-center">
        <div class="fw-bold fs-4"><?=$stats['total']?></div>
        <div class="text-muted small">Entradas registradas</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100" style="border-top:4px solid #198754;">
      <div class="card-body py-2 text-center">
        <div class="fw-bold fs-4"><?=$stats['mes_actual']?></div>
        <div class="text-muted small">Este mes</div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card h-100" style="border-top:4px solid #ffc107;">
      <div class="card-body py-2 text-center">
        <div class="fw-bold fs-4"><?=$stats['hoy']?></div>
        <div class="text-muted small">Hoy</div>
      </div>
    </div>
  </div>
</div>

<?php if (in_array($action, ['list','buscar'])): ?>
<!-- Filtros de búsqueda -->
<div class="card mb-3">
  <div class="card-body">
    <form method="GET" action="bitacora_diaria.php" class="row g-2 align-items-end">
      <input type="hidden" name="action" value="buscar">
      <div class="col-md-3">
        <label class="form-label small mb-1">Buscar en texto</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Título o contenido..." value="<?=h($filtros['q'])?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Tipo de etiqueta</label>
        <select name="tipo_etiqueta" class="form-select form-select-sm">
          <option value="">Todas</option>
          <?php foreach ($tiposEtiqueta as $val=>$lab): ?>
          <option value="<?=$val?>" <?=$filtros['tipo_etiqueta']==$val?'selected':''?>><?=$lab?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Valor de etiqueta</label>
        <input type="text" name="valor_etiqueta" class="form-control form-control-sm" placeholder="Ej: PC-045, 10.20.1.5..." value="<?=h($filtros['valor_etiqueta'])?>">
      </div>
      <div class="col-md-2">
        <label class="form-label small mb-1">Autor</label>
        <select name="suser_id" class="form-select form-select-sm">
          <option value="">Todos</option>
          <?php foreach ($autores as $a): ?>
          <option value="<?=$a['id']?>" <?=$filtros['suser_id']==$a['id']?'selected':''?>><?=h($a['nombre_completo'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label small mb-1">Desde</label>
        <input type="date" name="fecha_desde" class="form-control form-control-sm" value="<?=h($filtros['fecha_desde'])?>">
      </div>
      <div class="col-md-1">
        <label class="form-label small mb-1">Hasta</label>
        <input type="date" name="fecha_hasta" class="form-control form-control-sm" value="<?=h($filtros['fecha_hasta'])?>">
      </div>
      <div class="col-md-1 d-flex gap-1">
        <button class="btn btn-sm btn-outline-secondary flex-fill" title="Buscar"><i class="bi bi-search"></i></button>
        <?php if ($hayFiltros): ?>
        <a href="bitacora_diaria.php" class="btn btn-sm btn-outline-danger" title="Limpiar filtros"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Listado de entradas -->
<?php foreach ($entradas as $e): ?>
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <div class="text-muted small mb-1">
          <i class="bi bi-calendar3 me-1"></i><?=date('d/m/Y', strtotime($e['fecha']))?>
          <span class="mx-1">·</span>
          <i class="bi bi-person-badge me-1"></i><?=h($e['autor_nombre'] ?? 'Desconocido')?>
        </div>
        <h6 class="mb-1"><a href="bitacora_diaria.php?action=view&id=<?=$e['id']?>" class="text-decoration-none text-dark"><?=h($e['titulo'])?></a></h6>
        <p class="text-muted small mb-2"><?=h(excerptTexto($e['contenido']))?></p>
        <?php if (!empty($e['etiquetas'])): ?>
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ($e['etiquetas'] as $et): ?>
          <span class="badge <?=$colorTipo[$et['tipo']] ?? 'bg-secondary'?>"><?=h($et['valor'])?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="d-flex gap-1">
        <a href="bitacora_diaria.php?action=view&id=<?=$e['id']?>" class="btn btn-xs btn-outline-secondary" title="Ver completa"><i class="bi bi-eye"></i></a>
        <?php if (isAdmin()): ?>
        <a href="bitacora_diaria.php?action=edit&id=<?=$e['id']?>" class="btn btn-xs btn-outline-primary" title="Editar"><i class="bi bi-pencil"></i></a>
        <button onclick="confirmDelete('bitacora_diaria.php?action=delete&id=<?=$e['id']?>','la entrada &quot;<?=addslashes($e['titulo'])?>&quot;')" class="btn btn-xs btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if (empty($entradas)): ?>
<div class="text-center text-muted py-5">
  <i class="bi bi-journal-x fs-1 d-block mb-2"></i>
  <?=$hayFiltros ? 'No hay entradas que coincidan con esos filtros.' : 'Aún no hay entradas registradas.'?>
</div>
<?php endif; ?>

<?php elseif ($action === 'view' && $entradaView): ?>
<!-- Ver entrada completa -->
<div class="row justify-content-center"><div class="col-md-8">
<div class="card">
  <div class="card-header-ti d-flex justify-content-between align-items-center">
    <span><i class="bi bi-journal-text me-2"></i>Entrada de Bitácora</span>
    <div class="d-flex gap-1">
      <?php if (isAdmin()): ?>
      <a href="bitacora_diaria.php?action=edit&id=<?=$entradaView['id']?>" class="btn btn-xs btn-outline-primary"><i class="bi bi-pencil"></i> Editar</a>
      <button onclick="confirmDelete('bitacora_diaria.php?action=delete&id=<?=$entradaView['id']?>','la entrada &quot;<?=addslashes($entradaView['titulo'])?>&quot;')" class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i> Eliminar</button>
      <?php endif; ?>
      <a href="exports/export_bitacora_diaria_pdf.php?id=<?=$entradaView['id']?>" target="_blank" class="btn btn-xs btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> PDF</a>
    </div>
  </div>
  <div class="card-body">
    <div class="text-muted small mb-2">
      <i class="bi bi-calendar3 me-1"></i><?=date('d/m/Y', strtotime($entradaView['fecha']))?>
      <span class="mx-1">·</span>
      <i class="bi bi-person-badge me-1"></i>Firmado por <strong><?=h($entradaView['autor_nombre'] ?? 'Desconocido')?></strong>
      <?php if ($entradaView['updated_at']): ?>
      <span class="mx-1">·</span><span class="fst-italic">editado <?=date('d/m/Y H:i', strtotime($entradaView['updated_at']))?></span>
      <?php endif; ?>
    </div>
    <h4 class="mb-3"><?=h($entradaView['titulo'])?></h4>
    <div style="white-space:pre-wrap;"><?=h($entradaView['contenido'])?></div>
    <?php if (!empty($entradaView['etiquetas'])): ?>
    <hr>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($entradaView['etiquetas'] as $et): ?>
      <span class="badge <?=$colorTipo[$et['tipo']] ?? 'bg-secondary'?>"><?=h($tiposEtiqueta[$et['tipo']] ?? $et['tipo'])?>: <?=h($et['valor'])?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="mt-3">
      <a href="bitacora_diaria.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Volver al listado</a>
    </div>
  </div>
</div>
</div></div>

<?php elseif (in_array($action, ['new','edit'])): ?>
<!-- Form Nueva/Editar Entrada -->
<div class="row justify-content-center"><div class="col-md-8">
<div class="card">
  <div class="card-header-ti"><?=$isEdit?'Editar':'Nueva'?> Entrada de Bitácora</div>
  <div class="card-body">
    <form method="POST" action="bitacora_diaria.php">
      <?php if ($isEdit): ?><input type="hidden" name="id" value="<?=$entradaEdit['id']?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Fecha *</label>
          <input type="date" name="fecha" class="form-control" value="<?=h($entradaEdit['fecha'] ?? date('Y-m-d'))?>" required>
        </div>
        <div class="col-md-8">
          <label class="form-label">Firma</label>
          <input type="text" class="form-control" value="<?=h($entradaEdit['autor_nombre'] ?? authUser()['nombre'])?>" disabled>
        </div>
        <div class="col-12">
          <label class="form-label">Título / Resumen *</label>
          <input type="text" name="titulo" class="form-control" value="<?=h($entradaEdit['titulo'] ?? '')?>" required placeholder="Ej: Cambio de equipo en Contabilidad">
        </div>
        <div class="col-12">
          <label class="form-label">Contenido *</label>
          <textarea name="contenido" class="form-control" rows="8" required placeholder="Describe el suceso: qué pasó, a qué equipo, quién lo reportó, acciones tomadas, si el equipo causó baja y por qué, etc."><?=h($entradaEdit['contenido'] ?? '')?></textarea>
        </div>
        <div class="col-12">
          <hr class="my-1">
          <label class="form-label"><i class="bi bi-tags me-1"></i>Etiquetas (opcional)</label>
          <textarea name="etiquetas" class="form-control" rows="4" placeholder="Una por línea, formato Tipo: valor. Ejemplo:&#10;FOLIO: PC-045&#10;IP: 10.20.1.5&#10;USUARIO: Juan Pérez&#10;AREA: Contabilidad"><?=h(etiquetasATexto($entradaEdit['etiquetas'] ?? []))?></textarea>
          <div class="form-text">
            Tipos sugeridos: <?=implode(', ', getTiposEtiquetaDiaria())?>. Si no pones el tipo, se guarda como "Otro".
            Estas etiquetas permiten luego buscar rápido por folio de equipo, IP, usuario o área.
          </div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
        <a href="bitacora_diaria.php<?=$isEdit?('?action=view&id='.$entradaEdit['id']):''?>" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div></div>
<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

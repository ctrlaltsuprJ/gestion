<?php
require_once 'includes/auth.php';
require_once 'includes/nodos_functions.php';
requireRol(ROL_ADMIN, ROL_SUPER);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRol(ROL_ADMIN);
    $formType = $_POST['form_type'] ?? '';

    if ($formType === 'edificio') {
        $pid = (int)($_POST['id'] ?? 0) ?: null;
        $edificioId = saveEdificio($_POST, $pid);
        flash('success', $pid ? 'Edificio actualizado.' : 'Edificio creado.');
        redirect('nodos.php?edificio=' . $edificioId);
    }

    if ($formType === 'planta') {
        $pid = (int)($_POST['id'] ?? 0) ?: null;
        $plantaId = saveNodosPlanta($_POST, $pid);
        flash('success', $pid ? 'Planta actualizada.' : 'Planta creada.');
        redirect('nodos.php?action=planta&id=' . $plantaId);
    }

    if ($formType === 'ubicacion') {
        $pid = (int)($_POST['id'] ?? 0) ?: null;
        $ubicacionId = saveUbicacion($_POST, $pid);
        if (!empty($_POST['nodos_bulk'])) {
            saveNodosBulk($ubicacionId, $_POST['nodos_bulk'], $_POST['estado_bulk'] ?? 'libre', $_POST['color'] ?: null);
        }
        flash('success', $pid ? 'Ubicación actualizada.' : 'Ubicación creada.');
        redirect('nodos.php?action=planta&id=' . (int)$_POST['planta_id']);
    }

    if ($formType === 'nodo') {
        $pid = (int)($_POST['id'] ?? 0) ?: null;
        saveNodo($_POST, $pid);
        flash('success', $pid ? 'Nodo actualizado.' : 'Nodo creado.');
        redirect('nodos.php?action=planta&id=' . (int)$_POST['planta_id_redirect']);
    }
}

if ($action === 'delete_edificio' && $id) {
    requireRol(ROL_ADMIN);
    deleteEdificio($id);
    flash('warning', 'Edificio desactivado.');
    redirect('nodos.php');
}
if ($action === 'delete_planta' && $id) {
    requireRol(ROL_ADMIN);
    $planta = getNodosPlantaById($id);
    deleteNodosPlanta($id);
    flash('warning', 'Planta desactivada.');
    redirect('nodos.php?edificio=' . ($planta['edificio_id'] ?? ''));
}
if ($action === 'delete_ubicacion' && $id) {
    requireRol(ROL_ADMIN);
    $ubic = getUbicacionById($id);
    deleteUbicacion($id);
    flash('warning', 'Ubicación desactivada.');
    redirect('nodos.php?action=planta&id=' . ($ubic['planta_id'] ?? ''));
}
if ($action === 'delete_nodo' && $id) {
    requireRol(ROL_ADMIN);
    $nodo = getNodoById($id);
    deleteNodo($id);
    flash('warning', 'Nodo desactivado.');
    redirect('nodos.php?action=planta&id=' . ($nodo['planta_id'] ?? ''));
}

$pageTitle = 'Nodos de Red';
include 'includes/header.php';

$edificios = getNodosEdificios();

// --- Resolver edificio y planta actuales ---
$plantaActual  = null;
$edificioActual = null;

if ($action === 'planta' && $id) {
    $plantaActual = getNodosPlantaById($id);
    if (!$plantaActual) { flash('error', 'Planta no encontrada.'); redirect('nodos.php'); }
    $edificioActual = getEdificioById($plantaActual['edificio_id']);
} else {
    $edificioId = (int)($_GET['edificio'] ?? 0);
    $edificioActual = $edificioId ? getEdificioById($edificioId) : ($edificios[0] ?? null);
    if ($edificioActual) {
        $plantasDelEdificio = getNodosPlantas($edificioActual['id']);
        $plantaActual = $plantasDelEdificio[0] ?? null;
    }
}

$plantas     = $edificioActual ? getNodosPlantas($edificioActual['id']) : [];
$ubicaciones = $plantaActual ? getUbicacionesPorPlanta($plantaActual['id']) : [];
$statsEdificios = getEstadisticasEdificios();
$statsPlantas   = $edificioActual ? getEstadisticasNodos($edificioActual['id']) : [];

$edifEdit = null;
if ($action === 'edificio_edit' && $id) $edifEdit = getEdificioById($id);
$isEditEdif = !empty($edifEdit);

$plantaEdit = null;
if ($action === 'planta_edit' && $id) $plantaEdit = getNodosPlantaById($id);
$isEditPlanta = !empty($plantaEdit);

$ubicEdit = null;
if ($action === 'ubicacion_edit' && $id) $ubicEdit = getUbicacionById($id);
$isEditUbic = !empty($ubicEdit);

$nodoEdit = null;
if ($action === 'nodo_edit' && $id) $nodoEdit = getNodoById($id);
$isEditNodo = !empty($nodoEdit);

$ubicacionNuevoNodo = null;
if ($action === 'nodo_new' && $id) $ubicacionNuevoNodo = getUbicacionById($id);

$resultadosBusqueda = null;
if ($action === 'buscar' && !empty($_GET['q'])) {
    $resultadosBusqueda = buscarNodo(trim($_GET['q']));
}

$estadoBadge = [
    'libre'     => 'bg-success',
    'ocupado'   => 'bg-danger',
    'dañado'    => 'bg-dark',
    'reservado' => 'bg-warning text-dark',
];
$estadoLabel = [
    'libre'     => 'Libre',
    'ocupado'   => 'Ocupado',
    'dañado'    => 'Dañado',
    'reservado' => 'Reservado',
];
?>
<div class="container-fluid px-3 py-3">

<!-- Selector de Edificios -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <ul class="nav nav-pills gap-1">
    <?php foreach ($edificios as $e):
      $s = null;
      foreach ($statsEdificios as $se) if ($se['id']==$e['id']) { $s = $se; break; }
    ?>
    <li class="nav-item">
      <a class="nav-link <?=($edificioActual && $edificioActual['id']==$e['id'])?'active':''?>"
         href="nodos.php?edificio=<?=$e['id']?>"
         style="<?=($edificioActual && $edificioActual['id']==$e['id'])?'background:#1a3a5c;':''?>">
        <i class="bi bi-building me-1"></i><?=h($e['nombre'])?>
        <?php if ($s): ?><span class="badge bg-light text-dark ms-1"><?=(int)$s['total']?></span><?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
    <?php if (isAdmin()): ?>
    <li class="nav-item">
      <a class="nav-link text-muted" href="nodos.php?action=edificio_new"><i class="bi bi-plus-circle me-1"></i>Nuevo Edificio</a>
    </li>
    <?php endif; ?>
  </ul>
  <?php if ($edificioActual && isAdmin()): ?>
  <div class="d-flex gap-1">
    <a href="nodos.php?action=edificio_edit&id=<?=$edificioActual['id']?>" class="btn btn-xs btn-outline-primary" title="Editar edificio"><i class="bi bi-pencil"></i></a>
    <button onclick="confirmDelete('nodos.php?action=delete_edificio&id=<?=$edificioActual['id']?>','edificio <?=addslashes($edificioActual['nombre'])?>')" class="btn btn-xs btn-outline-danger" title="Desactivar edificio"><i class="bi bi-trash"></i></button>
  </div>
  <?php endif; ?>
</div>

<?php if ($edificioActual): ?>
<!-- Reporte del edificio completo -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Reporte de Nodos — <?=h($edificioActual['nombre'])?></h5>
  <div class="d-flex gap-2 flex-wrap">
    <a href="exports/export_nodos_xls.php?edificio=<?=$edificioActual['id']?>" class="btn btn-sm btn-outline-success" title="Exportar edificio a Excel">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel (edificio)
    </a>
    <a href="exports/export_nodos_pdf.php?edificio=<?=$edificioActual['id']?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Exportar edificio a PDF">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF (edificio)
    </a>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary" title="Imprimir vista actual">
      <i class="bi bi-printer me-1"></i>Imprimir
    </button>
  </div>
</div>
<?php endif; ?>

<!-- KPIs por planta del edificio actual -->
<div class="row g-3 mb-3">
<?php foreach ($statsPlantas as $s):
  $pct = $s['total'] > 0 ? round($s['ocupados']/$s['total']*100,1) : 0;
?>
<div class="col-md-6 col-lg-3">
  <a href="nodos.php?action=planta&id=<?=$s['id']?>" class="text-decoration-none text-reset">
  <div class="card h-100" style="border-top:4px solid #1a3a5c;">
    <div class="card-body pb-2">
      <div class="fw-bold small mb-2"><?=h($s['nombre'])?></div>
      <div class="row g-1 text-center mb-2">
        <div class="col-3">
          <div class="fw-bold text-danger"><?=(int)$s['ocupados']?></div>
          <div class="text-muted" style="font-size:.6rem;">OCUP.</div>
        </div>
        <div class="col-3">
          <div class="fw-bold text-success"><?=(int)$s['libres']?></div>
          <div class="text-muted" style="font-size:.6rem;">LIBRES</div>
        </div>
        <div class="col-3">
          <div class="fw-bold text-warning"><?=(int)$s['reservados']?></div>
          <div class="text-muted" style="font-size:.6rem;">RESERV.</div>
        </div>
        <div class="col-3">
          <div class="fw-bold"><?=(int)$s['total']?></div>
          <div class="text-muted" style="font-size:.6rem;">TOTAL</div>
        </div>
      </div>
      <div class="progress mb-1" style="height:6px;">
        <div class="progress-bar bg-danger" style="width:<?=$pct?>%;"></div>
      </div>
      <div class="text-muted text-end" style="font-size:.65rem;"><?=$pct?>% ocupado</div>
    </div>
  </div>
  </a>
</div>
<?php endforeach; ?>
<?php if ($edificioActual && empty($statsPlantas)): ?>
<div class="col-12"><div class="alert alert-info mb-0">Este edificio aún no tiene plantas registradas. Usa "Nueva Planta" para comenzar.</div></div>
<?php endif; ?>
<?php if (empty($edificios)): ?>
<div class="col-12"><div class="alert alert-info mb-0">Aún no hay edificios registrados. Crea el primero con "Nuevo Edificio".</div></div>
<?php endif; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div></div>
  <div class="d-flex gap-2 flex-wrap">
    <form class="d-flex" method="GET" action="nodos.php">
      <input type="hidden" name="action" value="buscar">
      <input type="text" name="q" class="form-control form-control-sm" placeholder="Buscar nodo, ubicación, usuario..." value="<?=h($_GET['q'] ?? '')?>" style="min-width:220px;">
      <button class="btn btn-sm btn-outline-secondary ms-1"><i class="bi bi-search"></i></button>
    </form>
    <a href="exports/export_nodos_xls.php<?=$plantaActual?('?planta='.$plantaActual['id']):''?>" class="btn btn-sm btn-outline-success" title="Exportar esta planta a Excel">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel (planta)
    </a>
    <a href="exports/export_nodos_pdf.php<?=$plantaActual?('?planta='.$plantaActual['id']):''?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Exportar esta planta a PDF">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF (planta)
    </a>
    <?php if (isAdmin() && $edificioActual): ?>
    <a href="nodos.php?action=planta_new&edificio=<?=$edificioActual['id']?>" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-plus me-1"></i>Nueva Planta
    </a>
    <?php endif; ?>
    <?php if (isAdmin() && $plantaActual): ?>
    <a href="nodos.php?action=ubicacion_new&planta=<?=$plantaActual['id']?>" class="btn btn-sm" style="background:#1a3a5c;color:#fff;">
      <i class="bi bi-plus me-1"></i>Nueva Ubicación
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($resultadosBusqueda !== null): ?>
<!-- Resultados de búsqueda -->
<div class="card mb-4">
  <div class="card-header-ti d-flex justify-content-between align-items-center">
    <span><i class="bi bi-search me-2"></i>Resultados para "<?=h($_GET['q'])?>"</span>
    <a href="nodos.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
  </div>
  <div class="card-body p-0">
    <table class="table table-ti mb-0">
      <thead><tr><th>Nodo</th><th>Edificio</th><th>Planta</th><th>Ubicación</th><th>SW</th><th>Estado</th><th>Usuario</th><th>IP</th><th>Folio</th></tr></thead>
      <tbody>
      <?php foreach ($resultadosBusqueda as $r): ?>
      <tr>
        <td><code class="fw-bold" style="color:<?=h($r['color'] ?: '#1a3a5c')?>"><?=h($r['numero_nodo'])?></code></td>
        <td class="small"><?=h($r['edificio_nombre'])?></td>
        <td class="small"><?=h($r['planta_nombre'])?></td>
        <td class="small"><?=h($r['ubicacion_nombre'])?></td>
        <td><span class="badge bg-secondary small"><?=h($r['switch'] ?? '—')?></span></td>
        <td><span class="badge <?=$estadoBadge[$r['estado']] ?? 'bg-secondary'?>"><?=$estadoLabel[$r['estado']] ?? $r['estado']?></span></td>
        <td class="small"><?=h($r['usuario'] ?? '—')?></td>
        <td><code class="small"><?=h($r['ip_asociada'] ?? '—')?></code></td>
        <td class="small"><?=h($r['folio_equipo'] ?? '—')?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($resultadosBusqueda)): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin resultados</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif (in_array($action, ['list','planta']) && $plantaActual): ?>

<!-- Tabs de plantas del edificio actual -->
<ul class="nav nav-tabs mb-3">
  <?php foreach ($plantas as $p): ?>
  <li class="nav-item">
    <a class="nav-link <?=$p['id']==$plantaActual['id']?'active':''?>" href="nodos.php?action=planta&id=<?=$p['id']?>"><?=h($p['nombre'])?></a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if (isAdmin()): ?>
<div class="d-flex justify-content-end gap-1 mb-2">
  <a href="nodos.php?action=planta_edit&id=<?=$plantaActual['id']?>" class="btn btn-xs btn-outline-primary" title="Editar planta"><i class="bi bi-pencil"></i> Editar planta</a>
  <button onclick="confirmDelete('nodos.php?action=delete_planta&id=<?=$plantaActual['id']?>','<?=addslashes($plantaActual['nombre'])?>')" class="btn btn-xs btn-outline-danger" title="Desactivar planta"><i class="bi bi-trash"></i></button>
</div>
<?php endif; ?>

<!-- Ubicaciones de la planta -->
<div class="row g-3">
<?php foreach ($ubicaciones as $u): $nodos = getNodosPorUbicacion($u['id']); ?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100">
    <div class="card-header-ti d-flex justify-content-between align-items-center">
      <span class="fw-bold small"><?=h($u['nombre'])?></span>
      <div class="d-flex align-items-center gap-1">
        <?php if ($u['switch']): ?><span class="badge bg-secondary small"><?=h($u['switch'])?></span><?php endif; ?>
        <?php if (isAdmin()): ?>
        <a href="nodos.php?action=ubicacion_edit&id=<?=$u['id']?>" class="btn btn-xs btn-outline-primary" title="Editar ubicación"><i class="bi bi-pencil"></i></a>
        <button onclick="confirmDelete('nodos.php?action=delete_ubicacion&id=<?=$u['id']?>','<?=addslashes($u['nombre'])?>')" class="btn btn-xs btn-outline-danger" title="Desactivar"><i class="bi bi-trash"></i></button>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <div class="d-flex flex-wrap gap-1 mb-2">
        <?php foreach ($nodos as $n):
          $bg = $n['color'] ?: null;
          $cls = $estadoBadge[$n['estado']] ?? 'bg-secondary';
        ?>
        <a href="nodos.php?action=nodo_edit&id=<?=$n['id']?>"
           class="badge <?= $bg ? '' : $cls ?> text-decoration-none"
           style="<?= $bg ? 'background:'.h($bg).';color:#fff;' : '' ?> cursor:pointer;"
           title="<?=$estadoLabel[$n['estado']] ?? $n['estado']?><?=$n['usuario']?' — '.h($n['usuario']):''?>">
          <?=h($n['numero_nodo'])?>
        </a>
        <?php endforeach; ?>
        <?php if (empty($nodos)): ?><span class="text-muted small">Sin nodos registrados</span><?php endif; ?>
      </div>
      <?php if (isAdmin()): ?>
      <a href="nodos.php?action=nodo_new&id=<?=$u['id']?>" class="btn btn-xs btn-outline-secondary"><i class="bi bi-plus"></i> Agregar nodo</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php if (empty($ubicaciones)): ?>
<div class="col-12"><div class="text-center text-muted py-4">Sin ubicaciones en esta planta. Usa "Nueva Ubicación" para comenzar.</div></div>
<?php endif; ?>
</div>

<?php elseif ($action === 'edificio_new' || $action === 'edificio_edit'): ?>
<!-- Form Edificio -->
<div class="row justify-content-center"><div class="col-md-5">
<div class="card">
  <div class="card-header-ti"><?=$isEditEdif?'Editar':'Nuevo'?> Edificio</div>
  <div class="card-body">
    <form method="POST" action="nodos.php">
      <input type="hidden" name="form_type" value="edificio">
      <?php if ($isEditEdif): ?><input type="hidden" name="id" value="<?=$edifEdit['id']?>"><?php endif; ?>
      <div class="mb-3"><label class="form-label">Nombre del Edificio *</label>
        <input type="text" name="nombre" class="form-control" value="<?=h($edifEdit['nombre'] ?? '')?>" required placeholder="Ej: C3.1, C3.2, Anexo Norte..."></div>
      <div class="mb-3"><label class="form-label">Orden</label>
        <input type="number" name="orden" class="form-control" value="<?=h($edifEdit['orden'] ?? 0)?>"></div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
        <a href="nodos.php" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div></div></div>

<?php elseif ($action === 'planta_new' || $action === 'planta_edit'): ?>
<!-- Form Planta -->
<?php $edificioPreset = (int)($_GET['edificio'] ?? ($plantaEdit['edificio_id'] ?? 0)); ?>
<div class="row justify-content-center"><div class="col-md-6">
<div class="card">
  <div class="card-header-ti"><?=$isEditPlanta?'Editar':'Nueva'?> Planta</div>
  <div class="card-body">
    <form method="POST" action="nodos.php">
      <input type="hidden" name="form_type" value="planta">
      <?php if ($isEditPlanta): ?><input type="hidden" name="id" value="<?=$plantaEdit['id']?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Edificio *</label>
          <select name="edificio_id" class="form-select" required>
            <?php foreach ($edificios as $e): ?>
            <option value="<?=$e['id']?>" <?=$edificioPreset==$e['id']?'selected':''?>><?=h($e['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">Orden</label>
          <input type="number" name="orden" class="form-control" value="<?=h($plantaEdit['orden'] ?? 0)?>"></div>
        <div class="col-12"><label class="form-label">Nombre de la Planta *</label>
          <input type="text" name="nombre" class="form-control" value="<?=h($plantaEdit['nombre'] ?? '')?>" required placeholder="Ej: 1.1 - PLANTA BAJA"></div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
        <a href="nodos.php<?=$edificioPreset?('?edificio='.$edificioPreset):''?>" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div></div></div>

<?php elseif (in_array($action, ['ubicacion_new','ubicacion_edit'])): ?>
<!-- Form Ubicación (con alta rápida de nodos) -->
<?php $plantaPreset = (int)($_GET['planta'] ?? ($ubicEdit['planta_id'] ?? 0)); ?>
<div class="row justify-content-center"><div class="col-md-7">
<div class="card">
  <div class="card-header-ti"><?=$isEditUbic?'Editar':'Nueva'?> Ubicación</div>
  <div class="card-body">
    <form method="POST" action="nodos.php">
      <input type="hidden" name="form_type" value="ubicacion">
      <?php if ($isEditUbic): ?><input type="hidden" name="id" value="<?=$ubicEdit['id']?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Planta *</label>
          <select name="planta_id" class="form-select" required>
            <?php foreach ($plantas as $p): ?>
            <option value="<?=$p['id']?>" <?=$plantaPreset==$p['id']?'selected':''?>><?=h($p['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">Switch (SW)</label>
          <input type="text" name="switch" class="form-control" value="<?=h($ubicEdit['switch'] ?? '')?>" placeholder="Ej: SW-PB-01"></div>
        <div class="col-12"><label class="form-label">Nombre de la Ubicación *</label>
          <input type="text" name="nombre" class="form-control" value="<?=h($ubicEdit['nombre'] ?? '')?>" required placeholder="Ej: RECEPCIÓN, CUB. 3, CONSULTORIO 1..."></div>

        <?php if (!$isEditUbic): ?>
        <div class="col-12"><hr class="my-1"><div class="text-muted small mb-1"><i class="bi bi-lightning-charge me-1"></i>Alta rápida de nodos (opcional)</div></div>
        <div class="col-12"><label class="form-label">Números de nodo</label>
          <textarea name="nodos_bulk" class="form-control" rows="2" placeholder="Ej: 118, 117, 116, 115"></textarea>
          <div class="form-text">Sepáralos por coma, espacio o salto de línea. Se crearán todos con el mismo estado y color.</div>
        </div>
        <div class="col-md-6"><label class="form-label">Estado inicial</label>
          <select name="estado_bulk" class="form-select">
            <option value="libre">Libre</option>
            <option value="ocupado">Ocupado</option>
            <option value="reservado">Reservado</option>
            <option value="dañado">Dañado</option>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">Color de resaltado</label>
          <input type="color" name="color" class="form-control form-control-color w-100" value="#ffffff"></div>
        <?php endif; ?>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
        <a href="nodos.php<?=$plantaPreset?('?action=planta&id='.$plantaPreset):''?>" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div></div></div>

<?php elseif (in_array($action, ['nodo_new','nodo_edit'])): ?>
<!-- Form Nodo individual -->
<?php
$ubicacionActual = $isEditNodo ? getUbicacionById($nodoEdit['ubicacion_id']) : $ubicacionNuevoNodo;
$plantaRedirect  = $ubicacionActual['planta_id'] ?? 0;
?>
<div class="row justify-content-center"><div class="col-md-6">
<div class="card">
  <div class="card-header-ti"><?=$isEditNodo?'Editar':'Nuevo'?> Nodo <?php if($ubicacionActual): ?><span class="text-muted small">— <?=h($ubicacionActual['nombre'])?></span><?php endif; ?></div>
  <div class="card-body">
    <form method="POST" action="nodos.php">
      <input type="hidden" name="form_type" value="nodo">
      <input type="hidden" name="planta_id_redirect" value="<?=$plantaRedirect?>">
      <?php if ($isEditNodo): ?><input type="hidden" name="id" value="<?=$nodoEdit['id']?>"><?php endif; ?>
      <input type="hidden" name="ubicacion_id" value="<?=$ubicacionActual['id'] ?? ''?>">
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Número de Nodo *</label>
          <input type="text" name="numero_nodo" class="form-control" value="<?=h($nodoEdit['numero_nodo'] ?? '')?>" required></div>
        <div class="col-md-6"><label class="form-label">Estado</label>
          <select name="estado" class="form-select">
            <?php foreach ($estadoLabel as $val=>$lab): ?>
            <option value="<?=$val?>" <?=($nodoEdit['estado'] ?? 'libre')==$val?'selected':''?>><?=$lab?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">IP Asociada</label>
          <input type="text" name="ip_asociada" class="form-control" value="<?=h($nodoEdit['ip_asociada'] ?? '')?>" placeholder="Ej: 10.27.41.20"></div>
        <div class="col-md-6"><label class="form-label">Folio de Equipo</label>
          <input type="text" name="folio_equipo" class="form-control" value="<?=h($nodoEdit['folio_equipo'] ?? '')?>"></div>
        <div class="col-md-8"><label class="form-label">Usuario</label>
          <input type="text" name="usuario" class="form-control" value="<?=h($nodoEdit['usuario'] ?? '')?>"></div>
        <div class="col-md-4"><label class="form-label">Color</label>
          <input type="color" name="color" class="form-control form-control-color w-100" value="<?=h($nodoEdit['color'] ?? '#ffffff')?>"></div>
        <div class="col-12"><label class="form-label">Observaciones</label>
          <textarea name="observaciones" class="form-control" rows="2"><?=h($nodoEdit['observaciones'] ?? '')?></textarea></div>
      </div>
      <div class="d-flex justify-content-between mt-3">
        <div class="d-flex gap-2">
          <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
          <a href="nodos.php?action=planta&id=<?=$plantaRedirect?>" class="btn btn-outline-secondary">Cancelar</a>
        </div>
        <?php if ($isEditNodo && isAdmin()): ?>
        <button type="button" onclick="confirmDelete('nodos.php?action=delete_nodo&id=<?=$nodoEdit['id']?>','nodo <?=h($nodoEdit['numero_nodo'])?>')" class="btn btn-outline-danger"><i class="bi bi-trash me-1"></i>Desactivar</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div></div></div>
<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

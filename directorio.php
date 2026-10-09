<?php
require_once 'includes/auth.php';

$action = $_GET['action'] ?? 'view';
$id     = (int)($_GET['id'] ?? 0);
$buscar = trim($_GET['buscar'] ?? $_GET['q'] ?? '');

// ── POST: guardar entrada del directorio ──────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    requireRol(ROL_ADMIN);
    $pid   = (int)($_POST['id'] ?? 0) ?: null;
    $saved = saveDirectorio($_POST, $pid);
    auditLog($pid ? 'EDITAR_DIRECTORIO' : 'CREAR_DIRECTORIO', 'directorio', $saved);
    flash('success', $pid ? 'Entrada actualizada.' : 'Entrada creada.');
    redirect('directorio.php');
}

if ($action === 'delete' && $id) {
    requireRol(ROL_ADMIN);
    deleteDirectorio($id);
    auditLog('ELIMINAR_DIRECTORIO', 'directorio', $id);
    flash('warning', 'Entrada eliminada.');
    redirect('directorio.php');
}

$pageTitle = 'Directorio Telefónico IP';
include 'includes/header.php';

$areas    = getAreas();
$usuarios = getUsuarios();
$areaFil  = (int)($_GET['area'] ?? 0);

// Datos del formulario
$entry = $action === 'edit' && $id ? getDirectorioById($id) : [];
$isEdit = !empty($entry);

// Exportar PDF
if ($action === 'pdf') { redirect('exports/export_directorio_pdf.php?q='.urlencode($buscar).'&area='.$areaFil); }
?>
<style>
/* Tarjeta de área en el directorio */
.dir-area-card { background:#fff; border:1px solid #dee2e6; border-radius:8px; overflow:hidden; box-shadow:0 1px 5px rgba(0,0,0,.07); margin-bottom:20px; page-break-inside:avoid; }
.dir-area-card.dragging { opacity:.65; transform:scale(.995); }
.dir-area-header { padding:8px 14px; font-weight:700; font-size:.78rem; letter-spacing:.5px; text-transform:uppercase; color:#fff; display:flex; align-items:center; justify-content:space-between; }
.dir-area-header .admin-btns { opacity:0; transition:.15s; display:flex; gap:4px; }
.dir-area-card:hover .admin-btns { opacity:1; }
.drag-handle { cursor:grab; user-select:none; opacity:.85; }
.drag-handle:active { cursor:grabbing; }
.dir-table { font-size:.8rem; width:100%; border-collapse:collapse; }
.dir-table td { padding:5px 10px; border-bottom:1px solid #f5f5f5; vertical-align:middle; }
.dir-table tr:last-child td { border-bottom:0; }
.dir-table tr:hover td { background:#fff8f8; }
.ext-badge { background:#000080; color:#fff; font-weight:700; border-radius:4px; padding:2px 8px; font-size:.75rem; }
.nombre-dir { font-weight:600; color:#1a2540; }
.puesto-dir { color:#666; font-size:.73rem; }
.tel-dir    { color:#1a6ea8; font-size:.75rem; font-weight:600; }
.ubic-dir   { color:#888; font-size:.72rem; font-style:italic; }
#grid-dir { display:grid; grid-template-columns:repeat(auto-fill,minmax(330px,1fr)); gap:16px; }
@media print {
  .no-print,.btn,.navbar,.app-footer { display:none !important; }
  body { background:#fff; }
  #grid-dir { display:block; columns:3; column-gap:12px; }
  .dir-area-card { break-inside:avoid; margin-bottom:10px; box-shadow:none; border:1px solid #ccc; }
  .dir-area-header { print-color-adjust:exact; -webkit-print-color-adjust:exact; }
}
</style>

<div class="container-fluid px-3 py-3">

<?php if (in_array($action, ['view', 'list'])): ?>
<!-- ══ VISTA DIRECTORIO ══ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-telephone-fill me-2"></i>Directorio Telefónico IP</h5>
  <div class="d-flex gap-2 flex-wrap no-print">
    <button type="button" id="btn-orden-libre" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-arrows-move me-1"></i>Orden libre
    </button>
    <button type="button" id="btn-reset-orden" class="btn btn-sm btn-outline-secondary" style="display:none;">
      <i class="bi bi-arrow-counterclockwise me-1"></i>Restablecer
    </button>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-printer me-1"></i>Imprimir
    </button>
    <a href="exports/export_directorio_pdf.php?q=<?=urlencode($buscar)?>&area=<?=$areaFil?>" target="_blank"
       class="btn btn-sm btn-outline-danger">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </a>
    <a href="exports/export_directorio_xls.php?q=<?=urlencode($buscar)?>&area=<?=$areaFil?>"
       class="btn btn-sm btn-outline-success">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
    <?php if (isAdmin()): ?>
    <a href="directorio.php?action=new" class="btn btn-sm" style="background:#000080;color:#fff;">
      <i class="bi bi-plus me-1"></i>Nueva Entrada
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-3 no-print"><div class="card-body py-2">
  <form id="form-search" method="GET" action="directorio.php" class="row g-2 align-items-end">
    <div class="col-sm-4">
      <div class="input-group input-group-sm">
        <span class="input-group-text"><i class="bi bi-search"></i></span>
        <input type="search" name="q" id="input-search" class="form-control"
               placeholder="Nombre, extensión, puesto, teléfono…" value="<?= h($buscar) ?>">
      </div>
    </div>
    <div class="col-sm-3">
      <select name="area" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— Todas las áreas —</option>
        <?php foreach ($areas as $a): ?>
        <option value="<?= $a['id'] ?>" <?= $areaFil==(int)$a['id']?'selected':'' ?>><?= h($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-primary">Buscar</button>
      <?php if ($buscar||$areaFil): ?><a href="directorio.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a><?php endif; ?>
    </div>
    <?php if ($buscar): ?>
    <div class="col-12 mt-1">
      <small class="text-muted"><?= count(getDirectorio($buscar, $areaFil)) ?> resultado(s) para «<strong><?= h($buscar) ?></strong>»</small>
    </div>
    <?php endif; ?>
  </form>
</div></div>

<!-- Grid de áreas -->
<?php
$agrupado = getDirectorioAgrupado($buscar, $areaFil);
$ordenJerarquico = [
    'DIRECCIÓN GENERAL',
    'SUBDIRECCIÓN DE CONTROL Y SEGUIMIENTO',
    'SUBDIRECCIÓN DE EVALUACIÓN',
    'ENLACE ADMINISTRATIVO',
    'ENLACE JURÍDICO',
    'DEPARTAMENTO DE MEDICINA Y TOXICOLOGÍA',
    'DEPARTAMENTO DE PROGRAMACIÓN Y SEGUIMIENTO INTERINSTITUCIONAL',
    'DEPARTAMENTO DE PROTECCIÓN DE LA INFORMACIÓN',
    'DEPARTAMENTO DE INTEGRACIÓN DE RESULTADOS Y CERTIFICACIÓN',
    'DEPARTAMENTO DE INVESTIGACIÓN SOCIOECONÓMICA',
    'DEPARTAMENTO DE POLIGRAFÍA',
    'DEPARTAMENTO DE PSICOLOGÍA',
    'VALIDACION DOCUMENTAL',
    'ANTECEDENTES',
    'VIGILANCIA',
    'ETC.'
];
$ordenMapa = [];
foreach ($ordenJerarquico as $idx => $nombreArea) {
    $ordenMapa[mb_strtoupper(trim($nombreArea))] = $idx;
}
uksort($agrupado, function ($a, $b) use ($ordenMapa) {
    $ka = mb_strtoupper(trim((string)$a));
    $kb = mb_strtoupper(trim((string)$b));
    $ia = $ordenMapa[$ka] ?? PHP_INT_MAX;
    $ib = $ordenMapa[$kb] ?? PHP_INT_MAX;
    if ($ia !== $ib) return $ia <=> $ib;
    return strnatcasecmp((string)$a, (string)$b);
});
$hl = function(string $txt, string $q): string {
    if (!$q) return h($txt);
    return preg_replace('/('.preg_quote(h($q),'/').')/i','<mark>$1</mark>', h($txt));
};
?>

<?php if (empty($agrupado)): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-telephone-x fs-1 d-block mb-2 opacity-25"></i>
  <h6>Sin resultados<?= $buscar ? ' para «'.h($buscar).'»' : '' ?></h6>
</div>
<?php else: ?>

<div id="grid-dir">
<?php foreach ($agrupado as $areaNombre => $entradas):
  // Color del área (tomado de la primera entrada)
  $colorArea = $entradas[0]['color_area'] ?? '#000080';
  // ID del área para el link de edición
  $areaId = $entradas[0]['area_id'] ?? 0;
  $areaKey = mb_strtoupper(trim((string)$areaNombre));
?>
<div class="dir-area-card" data-area-key="<?= h($areaKey) ?>">
  <div class="dir-area-header" style="background:<?= h($colorArea) ?>">
    <span class="d-inline-flex align-items-center gap-2">
      <i class="bi bi-grip-vertical drag-handle" title="Arrastrar para ordenar"></i>
      <?= h(strtoupper($areaNombre)) ?>
    </span>
    <?php if (isAdmin()): ?>
    <div class="admin-btns">
      <a href="directorio.php?action=new&area_id=<?= $areaId ?>"
         class="btn btn-xs btn-light py-0 px-1" title="Agregar extensión">
        <i class="bi bi-plus" style="font-size:.65rem;"></i>
      </a>
    </div>
    <?php endif; ?>
  </div>
  <table class="dir-table">
    <tbody>
    <?php foreach ($entradas as $e): ?>
    <tr>
      <td style="width:52px;">
        <?php if ($e['extension']): ?>
        <span class="ext-badge"><?= $hl($e['extension'], $buscar) ?></span>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($e['nombre_completo']): ?>
        <div class="nombre-dir"><?= $hl($e['nombre_completo'], $buscar) ?></div>
        <?php endif; ?>
        <?php if ($e['puesto_cargo']): ?>
        <div class="puesto-dir"><?= $hl($e['puesto_cargo'], $buscar) ?></div>
        <?php endif; ?>
        <?php if ($e['telefono_directo']): ?>
        <div class="tel-dir"><i class="bi bi-telephone me-1" style="font-size:.65rem;"></i><?= $hl($e['telefono_directo'], $buscar) ?></div>
        <?php endif; ?>
        <?php if ($e['ubicacion']): ?>
        <div class="ubic-dir"><i class="bi bi-geo-alt me-1" style="font-size:.65rem;"></i><?= $hl($e['ubicacion'], $buscar) ?></div>
        <?php endif; ?>
      </td>
      <?php if (isAdmin()): ?>
      <td style="width:52px;text-align:right;" class="no-print">
        <a href="directorio.php?action=edit&id=<?= $e['id'] ?>"
           class="btn btn-xs btn-outline-secondary me-1" title="Editar">
          <i class="bi bi-pencil" style="font-size:.6rem;"></i>
        </a>
        <button onclick="confirmDelete('directorio.php?action=delete&id=<?= $e['id'] ?>','Ext. <?= addslashes($e['extension']) ?>')"
                class="btn btn-xs btn-outline-danger" title="Eliminar">
          <i class="bi bi-trash" style="font-size:.6rem;"></i>
        </button>
      </td>
      <?php endif; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>
</div><!-- /grid-dir -->

<div class="text-muted small mt-3 no-print">
  <?= count($agrupado) ?> área(s) · <?= array_sum(array_map('count', $agrupado)) ?> extensiones
</div>
<?php endif; ?>

<?php elseif (in_array($action, ['new', 'edit'])): ?>
<!-- ══ FORM NUEVA/EDITAR ENTRADA ══ -->
<?php requireRol(ROL_ADMIN); ?>

<div class="row justify-content-center">
<div class="col-xl-7">
<div class="card">
  <div class="card-header-ti" style="background:#000080;">
    <i class="bi bi-telephone<?= $isEdit ? '' : '-plus' ?> me-2"></i>
    <?= $isEdit ? 'Editar Entrada — Ext. '.h($entry['extension']??'') : 'Nueva Entrada en Directorio' ?>
  </div>
  <div class="card-body">
    <form method="POST" action="directorio.php">
      <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $entry['id'] ?>"><?php endif; ?>

      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Área <span class="text-danger">*</span></label>
          <select name="area_id" class="form-select" required>
            <option value="">— Seleccionar —</option>
            <?php foreach ($areas as $a):
              $preArea = (int)($_GET['area_id'] ?? $entry['area_id'] ?? 0);
            ?>
            <option value="<?= $a['id'] ?>" <?= $preArea==(int)$a['id']?'selected':'' ?>>
              <?= h($a['nombre']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Extensión IP <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text" style="background:#000080;border-color:#000080;"><i class="bi bi-telephone-fill text-white"></i></span>
            <input type="text" name="extension" class="form-control" required
                   value="<?= h($entry['extension']??'') ?>" placeholder="19050" maxlength="20">
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Orden</label>
          <input type="number" name="orden" class="form-control" value="<?= h($entry['orden']??0) ?>" min="0">
        </div>
        <div class="col-md-6">
          <label class="form-label">Nombre Completo</label>
          <input type="text" name="nombre_completo" class="form-control"
                 value="<?= h($entry['nombre_completo']??'') ?>" placeholder="LIC. NOMBRE APELLIDO">
        </div>
        <div class="col-md-6">
          <label class="form-label">Puesto / Cargo</label>
          <input type="text" name="puesto_cargo" class="form-control"
                 value="<?= h($entry['puesto_cargo']??'') ?>" placeholder="ENCARGADA DPI">
        </div>
        <div class="col-md-6">
          <label class="form-label">Teléfono Directo</label>
          <input type="text" name="telefono_directo" class="form-control"
                 value="<?= h($entry['telefono_directo']??'') ?>" placeholder="2288423661">
        </div>
        <div class="col-md-6">
          <label class="form-label">Ubicación (cubículo / sala)</label>
          <input type="text" name="ubicacion" class="form-control"
                 value="<?= h($entry['ubicacion']??'') ?>" placeholder="CUB 3 ENTORNO">
        </div>
        <div class="col-md-6">
          <label class="form-label">Vincular con Usuario del Sistema</label>
          <select name="usuario_id" class="form-select">
            <option value="">— Sin vincular —</option>
            <?php foreach ($usuarios as $u): ?>
            <option value="<?= $u['id'] ?>" <?= (int)($entry['usuario_id']??0)==(int)$u['id']?'selected':'' ?>>
              <?= h($u['nombre'].' '.($u['apellidos']??'')) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Color del encabezado</label>
          <input type="color" name="color_area" class="form-control form-control-color w-100"
                 value="<?= h($entry['color_area']??'#000080') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Notas</label>
          <textarea name="notas" class="form-control" rows="2"><?= h($entry['notas']??'') ?></textarea>
        </div>
      </div>

      <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn" style="background:#000080;color:#fff;">
          <i class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Guardar Cambios' : 'Crear Entrada' ?>
        </button>
        <a href="directorio.php" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div>
</div>

<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>
<script>
(() => {
  const grid = document.getElementById('grid-dir');
  if (!grid) return;

  const storageKey = 'directorio_area_order_v1';
  const btnOrdenLibre = document.getElementById('btn-orden-libre');
  const btnResetOrden = document.getElementById('btn-reset-orden');

  const getCards = () => Array.from(grid.querySelectorAll('.dir-area-card[data-area-key]'));
  const getOrder = () => getCards().map(el => el.dataset.areaKey);

  function applyOrder(order) {
    if (!Array.isArray(order) || !order.length) return;
    const map = new Map(getCards().map(el => [el.dataset.areaKey, el]));
    order.forEach(key => {
      const el = map.get(key);
      if (el) grid.appendChild(el);
    });
    getCards().forEach(el => {
      if (!order.includes(el.dataset.areaKey)) grid.appendChild(el);
    });
  }

  function loadOrder() {
    try {
      const raw = localStorage.getItem(storageKey);
      if (!raw) return;
      applyOrder(JSON.parse(raw));
      btnResetOrden.style.display = '';
      btnOrdenLibre.classList.add('btn-primary');
      btnOrdenLibre.classList.remove('btn-outline-primary');
      btnOrdenLibre.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Orden libre activo';
    } catch (e) {}
  }

  function saveOrder() {
    try {
      localStorage.setItem(storageKey, JSON.stringify(getOrder()));
      btnResetOrden.style.display = '';
    } catch (e) {}
  }

  function enableDrag() {
    let dragged = null;
    getCards().forEach(card => {
      card.setAttribute('draggable', 'true');
      card.addEventListener('dragstart', () => {
        dragged = card;
        card.classList.add('dragging');
      });
      card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        dragged = null;
        saveOrder();
      });
      card.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (!dragged || dragged === card) return;
        const rect = card.getBoundingClientRect();
        const after = (e.clientY - rect.top) > (rect.height / 2);
        grid.insertBefore(dragged, after ? card.nextSibling : card);
      });
    });
  }

  btnOrdenLibre?.addEventListener('click', () => {
    enableDrag();
    btnOrdenLibre.classList.add('btn-primary');
    btnOrdenLibre.classList.remove('btn-outline-primary');
    btnOrdenLibre.innerHTML = '<i class="bi bi-check2-circle me-1"></i>Orden libre activo';
  });

  btnResetOrden?.addEventListener('click', () => {
    localStorage.removeItem(storageKey);
    window.location.reload();
  });

  loadOrder();
})();
</script>

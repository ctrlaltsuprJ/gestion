<?php
require_once 'includes/auth.php';
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
if (($_SERVER['REQUEST_METHOD'] ?? '')==='POST'){ $pid=(int)($_POST['id']??0)?:null; $saved=saveInventario($_POST,$pid); flash('success',$pid?'Ítem actualizado.':'Ítem agregado.'); redirect('inventario.php'); }
if ($action==='delete'&&$id){ deleteInventario($id); flash('warning','Ítem eliminado.'); redirect('inventario.php'); }
$pageTitle='Inventario'; include 'includes/header.php';
$equipos=getEquipos(); $usuarios=getUsuarios(); $areas=getAreas();
usort($usuarios, function($a, $b) {
    return strcmp($b['nombre'].' '.($b['apellidos']??''), $a['nombre'].' '.($a['apellidos']??''));
});
$q=trim($_GET['q']??'');
$tiposEq=getTiposEquipo();
?>
<div class="container-fluid px-3 py-4">
<?php if($action==='list'): ?>
<div class="page-header-ti d-flex justify-content-between align-items-center flex-wrap gap-3">
  <h5 class="mb-0"><i class="bi bi-boxes me-2"></i>Inventario de Periféricos y Accesorios</h5>
 <!-- <div class="d-flex gap-2">
    <button onclick="window.print()" class="btn btn-ti-primary no-print"><i class="bi bi-printer me-1"></i>Imprimir</button>
    <a href="exports/export_inventario_xls.php" class="btn btn-ti-primary no-print"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <a href="inventario.php?action=new" class="btn btn-ti-accent no-print"><i class="bi bi-plus me-1"></i>Agregar/Baja</a>---> 
  </div>
</div>
<!-- Filtros Simplificados -->
<div class="filter-card">
  <div class="card-body">
    <form id="form-search" method="GET" class="row g-3 align-items-center">
      <div class="col-md-8">
        <label class="form-label text-muted small mb-1 no-print">Búsqueda General</label>
        <input type="search" name="q" id="input-search" class="form-control" placeholder="Buscar en inventario y equipos (tipo, marca, serie, folio, IP, usuario…)" value="<?=h($q)?>">
      </div>
      <div class="col-md-4 d-flex gap-2 align-items-end">
        <button type="submit" class="btn btn-primary no-print flex-grow-1">
          <i class="bi bi-search me-1"></i>Buscar
        </button>
        <?php if($q): ?>
          <a href="inventario.php" class="btn btn-outline-secondary no-print">
            <i class="bi bi-x"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <div class="card-footer bg-transparent border-0 pt-0 pb-3 px-4">
    <div class="d-flex gap-2 no-print">
      <button onclick="window.print()" class="btn btn-ti-primary btn-sm">
        <i class="bi bi-printer me-1"></i>Imprimir
      </button>
      <a href="exports/export_inventario_xls.php" class="btn btn-ti-primary btn-sm">
        <i class="bi bi-file-earmark-excel me-1"></i>Excel
      </a>
      <a href="inventario.php?action=new" class="btn btn-ti-accent btn-sm">
        <i class="bi bi-plus me-1"></i>Agregar/Baja
      </a>
    </div>
  </div>
</div>

<?php
$inv=getInventario($q);
$equiposTabla = getEquipos($q);
$bodegaTabla = array_filter($inv, fn($item) => empty($item['equipo_id']) && empty($item['usuario_id']));

// Bajas de inventario
$bajasInventario = array_filter($inv, fn($item) => ($item['estado'] ?? '') === 'BAJA');
// Bajas de equipos
$bajasEquipos = array_filter($equiposTabla, fn($item) => ($item['estado'] ?? '') === 'BAJA');

// Unificar bajas con tipo de origen
$bajasTabla = [];
foreach ($bajasInventario as $item) {
    $item['tipo_origen'] = 'INVENTARIO';
    $bajasTabla[] = $item;
}
foreach ($bajasEquipos as $item) {
    $item['tipo_origen'] = 'EQUIPO';
    $bajasTabla[] = $item;
}
?>

<!-- Tabs de Resultados -->
<ul class="nav nav-tabs mb-3" id="resultTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="inventario-tab" data-bs-toggle="tab" data-bs-target="#inventario-content" type="button" role="tab">
      <i class="bi bi-box-seam me-2"></i>Inventario de Ítems <span class="badge bg-secondary ms-1"><?=count($inv)?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="equipos-tab" data-bs-toggle="tab" data-bs-target="#equipos-content" type="button" role="tab">
      <i class="bi bi-pc-display me-2"></i>Equipos Registrados <span class="badge bg-secondary ms-1"><?=count($equiposTabla)?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="bodegas-tab" data-bs-toggle="tab" data-bs-target="#bodega-content" type="button" role="tab">
      <i class="bi-building-fill me-2"></i>Bodega <span class="badge bg-secondary ms-1"><?=count($bodegaTabla)?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="bajas-tab" data-bs-toggle="tab" data-bs-target="#bajas-content" type="button" role="tab">
      <i class="bi-dash-circle me-2"></i>Bajas <span class="badge bg-secondary ms-1"><?=count($bajasTabla)?></span>
    </button>
  </li>
</ul>

<div class="tab-content" id="resultTabsContent">
  <!-- Tab Inventario -->
  <div class="tab-pane fade show active" id="inventario-content" role="tabpanel">
    <div class="table-card-ti">
      <div class="card-body p-0">
        <div class="table-responsive">
<table class="table table-ti mb-0">
<thead><tr><th>#</th><th>Tipo</th><th>Marca / Modelo</th><th>Folio</th><th>Equipo Asignado</th><th>Usuario</th><th>Área</th><th>Cant.</th><th>Estado</th><th>Notas</th><th class="no-print">Acc.</th></tr></thead>
<tbody>
<?php foreach($inv as $i):
  $cls=['BUENO'=>'badge-bueno','REGULAR'=>'badge-regular','MALO'=>'badge-malo','BAJA'=>'badge-baja'][$i['estado']]??'bg-secondary';
?>
<tr>
  <td><?=$i['id']?></td>
  <td><strong><?=h($i['tipo'])?></strong></td>
  <td><?=h($i['marca'].' '.($i['modelo']??''))?></td>
  <td><code class="small"><?=h($i['serie']??'')?></code></td>
  <td><code class="small"><?=h($i['equipo_folio']??'—')?></code></td>
  <td class="small"><?=h(trim($i['usuario_nombre']??''))?></td>
  <td class="small"><?=h($i['area_nombre']??'')?></td>
  <td class="text-center"><?=$i['cantidad']?></td>
  <td><span class="badge <?=$cls?>"><?=h($i['estado'])?></span></td>
  <td class="small text-muted"><?=h(mb_substr($i['notas']??'',0,40))?></td>
  <td class="no-print">
    <a href="inventario.php?action=edit&id=<?=$i['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
    <button onclick="confirmDelete('inventario.php?action=delete&id=<?=$i['id']?>','<?=addslashes($i['tipo'])?>')" class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i></button>
  </td>
</tr>
<?php endforeach; if(empty($inv)): ?><tr><td colspan="11" class="text-center text-muted py-4">Sin ítems en inventario</td></tr><?php endif; ?>
</tbody></table></div></div></div>
  </div>

  <!-- Tab Equipos -->
  <div class="tab-pane fade" id="equipos-content" role="tabpanel">
    <div class="table-card-ti">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-pc-display me-2"></i>Equipos Registrados</strong>
        <a href="equipos.php" class="btn btn-sm btn-outline-primary no-print">
          <i class="bi bi-box-arrow-up-right me-1"></i>Ver módulo
        </a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
<table class="table table-ti mb-0">
<thead><tr><th>Folio</th><th>Tipo</th><th>Marca / Modelo</th><th>IP</th><th>Nodo</th><th>Área</th><th>Usuario</th><th>Estado</th><th class="no-print">Acc.</th></tr></thead>
<tbody>
<?php foreach($equiposTabla as $e):
  $eqCls=['ACTIVO'=>'badge-activo','MANTENIMIENTO'=>'badge-mantenimiento','BAJA'=>'badge-baja','INACTIVO'=>'badge-inactivo'][$e['estado']]??'bg-secondary';
?>
<tr>
  <td><code class="small"><?=h($e['folio']??'')?></code></td>
  <td><?=h($e['tipo_nombre']??'')?></td>
  <td><?=h(($e['marca']??'').' '.($e['modelo']??''))?></td>
  <td><code class="small"><?=h($e['ip']??'')?></code></td>
  <td class="small"><?=h($e['nodo']??'')?></td>
  <td class="small"><?=h($e['area_nombre']??'')?></td>
  <td class="small"><?=h(trim($e['usuario_nombre']??''))?></td>
  <td><span class="badge <?=$eqCls?>"><?=h($e['estado']??'')?></span></td>
  <td class="no-print">
    <a href="equipos.php?action=view&id=<?=$e['id']?>" class="btn btn-xs btn-outline-primary" title="Ver equipo">
      <i class="bi bi-eye"></i>
    </a>
  </td>
</tr>
<?php endforeach; if(empty($equiposTabla)): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin equipos registrados</td></tr><?php endif; ?>
</tbody></table></div></div></div>
  </div>

  <!-- Tab Bajas -->
  <div class="tab-pane fade" id="bajas-content" role="tabpanel">
    <div class="table-card-ti">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-box-seam me-2"></i>Reporte General de Bajas</strong>
        <div class="d-flex gap-2 no-print">
          <button onclick="window.print()" class="btn btn-sm btn-ti-primary">
            <i class="bi bi-printer me-1"></i>Imprimir
          </button>
          <a href="exports/export_bajas_xls.php" class="btn btn-sm btn-ti-primary">
            <i class="bi bi-file-earmark-excel me-1"></i>Excel
          </a>
          <span class="badge bg-secondary align-self-center"><?=count($bajasTabla)?></span>
        </div>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
<table class="table table-ti mb-0">
<thead><tr><th>Origen</th><th>ID</th><th>Tipo/Folio</th><th>Marca / Modelo</th><th>Folio</th><th>Usuario</th><th>Área</th><th>Fecha Baja</th><th>Motivo Baja</th><th>Notas</th><th class="no-print">Acc.</th></tr></thead>
<tbody>
<?php foreach($bajasTabla as $i):
  $cls=['BUENO'=>'badge-bueno','REGULAR'=>'badge-regular','MALO'=>'badge-malo','BAJA'=>'badge-baja'][$i['estado']]??'bg-secondary';
  $esEquipo = ($i['tipo_origen'] ?? '') === 'EQUIPO';
?>
<tr>
  <td><span class="badge <?= $esEquipo ? 'bg-info text-dark' : 'bg-secondary' ?>"><?=h($i['tipo_origen'] ?? 'INVENTARIO')?></span></td>
  <td><?=$i['id']?></td>
  <td><strong><?=h($esEquipo ? ($i['folio'] ?? '') : ($i['tipo'] ?? ''))?></strong></td>
  <td><?=h($i['marca'].' '.($i['modelo']??''))?></td>
  <td><code class="small"><?=h($esEquipo ? ($i['ip'] ?? '') : ($i['serie'] ?? ''))?></code></td>
  <td class="small"><?=h(trim($i['usuario_nombre']??''))?></td>
  <td class="small"><?=h($i['area_nombre']??'')?></td>
  <td class="small"><?=h($i['fecha_baja']??'')?></td>
  <td class="small text-muted"><?=h($i['motivo_baja']??'')?></td>
  <td class="small text-muted"><?=h(mb_substr($i['notas']??'',0,40))?></td>
  <td class="no-print">
    <?php if ($esEquipo): ?>
      <a href="equipos.php?action=view&id=<?=$i['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-eye"></i></a>
    <?php else: ?>
      <a href="inventario.php?action=edit&id=<?=$i['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; if(empty($bajasTabla)): ?><tr><td colspan="10" class="text-center text-muted py-4">Sin bajas registradas</td></tr><?php endif; ?>
</tbody></table></div></div></div>
  </div>
</div>

<?php elseif(in_array($action,['new','edit'])): ?>
<?php $inv=$action==='edit'&&$id?getInventarioById($id):[]; $isEdit=!empty($inv); $eqPre=(int)($_GET['equipo_id']??$inv['equipo_id']??0); ?>
<div class="row justify-content-center"><div class="col-xl-8"><div class="form-card-ti">
  <div class="card-header-ti"><i class="bi bi-<?=$isEdit?'pencil':'plus-circle'?> me-2"></i><?=$isEdit?'Editar':'Agregar'?> Ítem de Inventario</div>
    <div class="card-body">
        <div class="alert alert-ti-warning d-flex align-items-center" role="alert">
  <i class="bi bi-exclamation-triangle-fill me-3"></i>
  <div>
    <strong>Nota importante:</strong> Para dar de baja un elemento, debes seleccionar el estado <b>"BAJA"</b> y se activarán los campos de motivo y fecha de baja.
  </div>
</div>
    <form method="POST" action="inventario.php">
      <?php if($isEdit): ?><input type="hidden" name="id" value="<?=$inv['id']?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label">Tipo *</label>
          <input type="text" name="tipo" class="form-control" list="tipos-inv" value="<?=h($inv['tipo']??'')?>" required placeholder="MONITOR, TECLADO, MOUSE…">
          <datalist id="tipos-inv"><?php foreach(['MONITOR','TECLADO','MOUSE','HEADSET','WEBCAM','UPS/NO-BREAK','IMPRESORA','ESCÁNER','HUB/SWITCH','LECTOR CÓDIGO','DIADEMA','MEMORIA USB','DISCO EXTERNO'] as $tp): ?><option><?=$tp?></option><?php endforeach; ?></datalist>
        </div>
        <div class="col-md-6"><label class="form-label">Marca</label><input type="text" name="marca" class="form-control" value="<?=h($inv['marca']??'')?>"></div>
        <div class="col-md-6"><label class="form-label">Modelo</label><input type="text" name="modelo" class="form-control" value="<?=h($inv['modelo']??'')?>"></div>
        <div class="col-md-6"><label class="form-label">No. Inventario</label><input type="text" name="serie" class="form-control" value="<?=h($inv['serie']??'')?>"></div>
        <div class="col-md-6"><label class="form-label">Equipo Asociado</label><select name="equipo_id" class="form-select"><option value="">— Ninguno —</option><?php foreach($equipos as $e): ?><option value="<?=$e['id']?>" <?=(int)($inv['equipo_id']??$eqPre)==(int)$e['id']?'selected':''?>><?=h($e['folio'].' — '.$e['marca'].' '.($e['modelo']??''))?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label">Usuario Asignado</label><select name="usuario_id" class="form-select"><option value="">— Ninguno —</option><?php foreach($usuarios as $u): ?><option value="<?=$u['id']?>" <?=(int)($inv['usuario_id']??0)==(int)$u['id']?'selected':''?>><?=h($u['nombre'].' '.($u['apellidos']??''))?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Área</label><select name="area_id" class="form-select"><option value="">— Ninguna —</option><?php foreach($areas as $a): ?><option value="<?=$a['id']?>" <?=(int)($inv['area_id']??0)==(int)$a['id']?'selected':''?>><?=h($a['nombre'])?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Cantidad</label><input type="number" name="cantidad" class="form-control" min="1" value="<?=h($inv['cantidad']??1)?>"></div>
        <div class="col-md-4"><label class="form-label">Estado</label><select name="estado" id="select-estado" class="form-select"><?php foreach(['BUENO','REGULAR','MALO','BAJA'] as $es): ?><option <?=($inv['estado']??'BUENO')===$es?'selected':''?>><?=$es?></option><?php endforeach; ?></select></div>
        <div class="col-md-4" id="row-motivo-baja" style="display:none;"><label class="form-label">Motivo de Baja</label><input type="text" name="motivo_baja" class="form-control" value="<?= h($inv['motivo_baja']??'')?>"></div>
        <div class="col-md-4" id="row-fecha-baja" style="display:none;"><label class="form-label">Fecha de Baja</label><input type="date" name="fecha_baja" class="form-control" value="<?= h($inv['fecha_baja']??'')?>"></div>
        <div class="col-12"><label class="form-label">Notas</label><textarea name="notas" class="form-control" rows="2"><?=h($inv['notas']??'')?></textarea></div>
      </div>
      <div class="d-flex gap-2 mt-4"><button type="submit" class="btn btn-ti-save"><i class="bi bi-check-lg me-1"></i>Guardar</button><a href="inventario.php" class="btn btn-outline-secondary">Cancelar</a></div>
    </form>
  </div>
</div></div></div>
<?php endif; ?>
  <!-- Tab bodega -->
  <div class="tab-pane fade" id="bodega-content" role="tabpanel">
    <div class="table-card-ti">
      <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-box-seam me-2"></i>Bodega</strong>
        <a href="bodega.php" class="btn btn-sm btn-outline-primary no-print">
          <i class="bi bi-box-arrow-up-right me-1"></i>Ver módulo
        </a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
<table class="table table-ti mb-0">
<thead><tr><th>Folio</th><th>Tipo</th><th>Marca / Modelo</th><th>IP</th><th>Nodo</th><th>Área</th><th>Usuario</th><th>Estado</th><th class="no-print">Acc.</th></tr></thead>
<tbody>
</tbody>
</table>
        </div>
      </div>
    </div>
  </div>
        </div>

<script>
function toggleMotivoBaja() {
  const selectEstado  = document.getElementById('select-estado');
  const rowMotivoBaja = document.getElementById('row-motivo-baja');
  const rowFechaBaja  = document.getElementById('row-fecha-baja');
  if (!selectEstado || !rowMotivoBaja) return;
  const show = selectEstado.value === 'BAJA';
  rowMotivoBaja.style.display = show ? '' : 'none';
  if (rowFechaBaja) rowFechaBaja.style.display = show ? '' : 'none';
  // Si se quita la baja, limpiar los campos para no guardar datos huérfanos
  if (!show) {
    rowMotivoBaja.querySelector('input')?.value && (rowMotivoBaja.querySelector('input').value = '');
    rowFechaBaja?.querySelector('input') && (rowFechaBaja.querySelector('input').value = '');
  }
}
document.getElementById('select-estado')?.addEventListener('change', toggleMotivoBaja);
document.addEventListener('DOMContentLoaded', toggleMotivoBaja);
</script>

</div><?php include 'includes/footer.php'; ?>
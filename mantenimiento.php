<?php
require_once 'includes/auth.php';
require_once 'includes/mantenimiento_calendario_functions.php';

// El módulo de Edificios (Nodos) es opcional; si no está instalado, el
// calendario simplemente no ofrece el filtro por edificio.
$edificiosDisponibles = [];
if (file_exists(__DIR__.'/includes/nodos_functions.php')) {
    require_once 'includes/nodos_functions.php';
    if (function_exists('getNodosEdificios')) $edificiosDisponibles = getNodosEdificios();
}

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_mantenimiento'])) {
    $pid = (int)($_POST['id'] ?? 0) ?: null;
    // Build checks array from POST
    $checks = [];
    $template = getChecklistTemplate();
    foreach ($template as $cat => $items) {
        foreach ($items as $idx => $item) {
            $key = 'check_'.md5($cat.'_'.$idx);
            $checks[] = [
                'categoria' => $cat,
                'item'      => $item,
                'resultado' => $_POST[$key.'_resultado'] ?? 'NO_APLICA',
                'notas'     => $_POST[$key.'_notas'] ?? '',
            ];
        }
    }

    // Supervisor: guarda como cambio pendiente si edita existente
    if (isSuper() && $pid) {
        $datosConChecks = array_merge($_POST, ['_checks' => $checks]);
        $cpId = guardarCambioPendiente('mantenimientos', $pid, 'UPDATE', $datosConChecks, 'Edición mantenimiento #'.$pid.' por supervisor');
        auditLog('EDITAR_MANT_PENDIENTE','mantenimientos',$pid,'Cambio pendiente #'.$cpId);
        flash('warning', 'Cambios enviados para aprobación del Administrador (#'.$cpId.').');
        redirect('mantenimiento.php?action=view&id='.$pid);
    }

    // Admin o supervisor creando nuevo
    $_POST['realizado_por_suser'] = suserIdActual();
    $saved = saveMantenimiento($_POST, $checks, $pid);
    auditLog($pid?'EDITAR_MANT':'CREAR_MANT','mantenimientos',$saved);
    flash('success', 'Mantenimiento guardado correctamente.');
    redirect('mantenimiento.php?action=view&id='.$saved);
}

if ($action === 'delete' && $id) {
    requireRol(ROL_ADMIN);
    getDB()->prepare("DELETE FROM mantenimientos WHERE id=?")->execute([$id]);
    auditLog('ELIMINAR_MANT', 'mantenimientos', $id);
    flash('warning', 'Mantenimiento eliminado.');
    redirect('mantenimiento.php');
}

// ══ Calendario de Programación ══
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_type'] ?? '') === 'programacion') {
    requireRol(ROL_ADMIN, ROL_SUPER);
    $pid = (int)($_POST['id'] ?? 0) ?: null;
    $progId = saveProgramacionMantenimiento($_POST, $pid);
    auditLog($pid ? 'EDITAR_PROG_MANT' : 'CREAR_PROG_MANT', 'programacion_mantenimiento', $progId);
    flash('success', $pid ? 'Programación actualizada.' : 'Programación creada.');
    $anioRedirect = (int)$_POST['anio'];
    $periodoRedirect = getPeriodoDeMes((int)$_POST['mes']);
    redirect('mantenimiento.php?action=calendario&anio=' . $anioRedirect . '&periodo=' . $periodoRedirect);
}

if ($action === 'calendario_delete' && $id) {
    requireRol(ROL_ADMIN);
    $prog = getProgramacionById($id);
    deleteProgramacionMantenimiento($id);
    auditLog('ELIMINAR_PROG_MANT', 'programacion_mantenimiento', $id);
    flash('warning', 'Programación eliminada.');
    redirect('mantenimiento.php?action=calendario&anio=' . ($prog['anio'] ?? date('Y')) . '&periodo=' . getPeriodoDeMes($prog['mes'] ?? 1));
}

if ($action === 'calendario_marcar' && $id) {
    requireRol(ROL_ADMIN, ROL_SUPER);
    $prog = getProgramacionById($id);
    marcarProgramacionRealizada($id, (int)($_GET['mantenimiento_id'] ?? 0) ?: null);
    auditLog('MARCAR_REALIZADO_PROG_MANT', 'programacion_mantenimiento', $id);
    flash('success', 'Marcado como realizado.');
    redirect('mantenimiento.php?action=calendario&anio=' . ($prog['anio'] ?? date('Y')) . '&periodo=' . getPeriodoDeMes($prog['mes'] ?? 1));
}

if ($action === 'calendario_reabrir' && $id) {
    requireRol(ROL_ADMIN, ROL_SUPER);
    $prog = getProgramacionById($id);
    reabrirProgramacion($id);
    auditLog('REABRIR_PROG_MANT', 'programacion_mantenimiento', $id);
    flash('warning', 'Programación reabierta.');
    redirect('mantenimiento.php?action=calendario&anio=' . ($prog['anio'] ?? date('Y')) . '&periodo=' . getPeriodoDeMes($prog['mes'] ?? 1));
}

$pageTitle = 'Mantenimiento';
include 'includes/header.php';

$equipos  = getEquipos();
$usuarios = getUsuarios();
$tickets  = getTickets();
?>
<div class="container-fluid px-3 py-3">

<style>
@media print {
  .app-navbar, .no-print, footer { display: none !important; }
  body { background: #fff !important; }
  .table-ti, table { font-size: 10px; }
}
</style>

<ul class="nav nav-pills mb-3 no-print">
  <li class="nav-item">
    <a class="nav-link <?=in_array($action,['list','new','edit','view'])?'active':''?>" href="mantenimiento.php" style="<?=in_array($action,['list','new','edit','view'])?'background:#1a3a5c;':''?>">
      <i class="bi bi-list-check me-1"></i>Órdenes de Mantenimiento
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?=str_starts_with($action,'calendario')?'active':''?>" href="mantenimiento.php?action=calendario" style="<?=str_starts_with($action,'calendario')?'background:#1a3a5c;':''?>">
      <i class="bi bi-calendar3-week me-1"></i>Calendario de Programación
    </a>
  </li>
</ul>

<?php if ($action === 'list'): ?>
<!-- ══ LISTA ══ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Mantenimientos</h5>
  <div class="d-flex gap-2">
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print"><i class="bi bi-printer me-1"></i>Imprimir</button>
    <a href="mantenimiento.php?action=new" class="btn btn-sm" style="background:#1a3a5c;color:#fff;"><i class="bi bi-plus me-1"></i>Nuevo Mantenimiento</a>
  </div>
</div>
<?php $mants = getMantenimientos(); ?>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-ti mb-0" id="tabla-mantenimientos">
      <thead>
        <tr><th>#</th><th>Equipo</th><th>Fecha</th><th>Tipo</th><th>Técnico</th><th>Observaciones</th><th class="no-print">Acciones</th></tr>
        <tr class="no-print">
          <th><input type="text" class="form-control form-control-sm filtro-columna-mant" data-col="0" placeholder="#"></th>
          <th><input type="text" class="form-control form-control-sm filtro-columna-mant" data-col="1" placeholder="Equipo"></th>
          <th><input type="text" class="form-control form-control-sm filtro-columna-mant" data-col="2" placeholder="Fecha"></th>
          <th><input type="text" class="form-control form-control-sm filtro-columna-mant" data-col="3" placeholder="Tipo"></th>
          <th><input type="text" class="form-control form-control-sm filtro-columna-mant" data-col="4" placeholder="Técnico"></th>
          <th><input type="text" class="form-control form-control-sm filtro-columna-mant" data-col="5" placeholder="Observaciones"></th>
          <th class="text-center no-print">
            <button type="button" id="btn-limpiar-filtros-mant" class="btn btn-xs btn-outline-secondary" title="Limpiar filtros">
              <i class="bi bi-eraser"></i>
            </button>
          </th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($mants as $m): ?>
      <tr>
        <td><?=$m['id']?></td>
        <td><strong><?=h($m['equipo_folio'])?></strong><br><span class="text-muted small"><?=h($m['marca'].' '.($m['modelo']??''))?></span></td>
        <td><?=h($m['fecha'])?></td>
        <td><span class="badge bg-info text-dark"><?=h($m['tipo'])?></span></td>
        <td class="small"><?=h($m['tecnico']??'')?></td>
        <td class="small text-muted"><?=h(mb_substr($m['observaciones']??'',0,60))?></td>
        <td class="no-print">
          <a href="mantenimiento.php?action=view&id=<?=$m['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-eye"></i></a>
          <a href="mantenimiento.php?action=edit&id=<?=$m['id']?>" class="btn btn-xs btn-outline-secondary me-1"><i class="bi bi-pencil"></i></a>
          <button onclick="confirmDelete('mantenimiento.php?action=delete&id=<?=$m['id']?>','Mantenimiento #<?=$m['id']?>')" class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if(empty($mants)): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin registros de mantenimiento</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
(() => {
  const tabla = document.getElementById('tabla-mantenimientos');
  if (!tabla) return;

  const filas = Array.from(tabla.querySelectorAll('tbody tr')).filter((tr) => tr.children.length > 1);
  const inputs = Array.from(tabla.querySelectorAll('.filtro-columna-mant'));
  const btnLimpiar = document.getElementById('btn-limpiar-filtros-mant');

  const norm = (v) => (v || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();

  function aplicarFiltros() {
    const filtros = inputs
      .map((input) => ({ col: parseInt(input.dataset.col || '-1', 10), val: norm(input.value) }))
      .filter((f) => f.col >= 0 && f.val !== '');

    filas.forEach((fila) => {
      const ok = filtros.every((f) => norm(fila.children[f.col]?.textContent || '').includes(f.val));
      fila.style.display = ok ? '' : 'none';
    });
  }

  inputs.forEach((input) => input.addEventListener('input', aplicarFiltros));
  btnLimpiar?.addEventListener('click', () => {
    inputs.forEach((input) => { input.value = ''; });
    aplicarFiltros();
  });
})();
</script>

<?php elseif (in_array($action,['new','edit'])): ?>
<!-- ══ FORM MANTENIMIENTO ══ -->
<?php
$m    = $action==='edit' && $id ? getMantenimientoById($id) : [];
$savedChecks = $id && $action==='edit' ? getChecks($id) : [];
$savedCheckMap = [];
foreach ($savedChecks as $sc) {
    $savedCheckMap[$sc['categoria'].'|'.$sc['item']] = $sc;
}
$equipoIdPre = (int)($_GET['equipo_id'] ?? $m['equipo_id'] ?? 0);
$isEdit = !empty($m);
// Cargar datos del equipo preseleccionado para el checklist
$equipoParaChecklist = $equipoIdPre ? getEquipoById($equipoIdPre) : null;
$usuarioIdPre = (int)($m['usuario_id'] ?? 0);
if ($usuarioIdPre <= 0 && !empty($equipoParaChecklist['usuario_id'])) {
    $usuarioIdPre = (int)$equipoParaChecklist['usuario_id'];
}
$tecnicos = getSupervisores();
$tecnicoActual = trim((string)($m['tecnico'] ?? ''));
$template = getChecklistTemplate();
?>
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <h5 class="mb-0"><i class="bi bi-clipboard-plus me-2"></i><?=$isEdit?'Editar':'Nueva'?> Orden de Mantenimiento</h5>
  <?php if (isSuper() && $isEdit): ?>
  <span class="sup-pending"><i class="bi bi-hourglass me-1"></i>Tus cambios requerirán aprobación del Administrador</span>
  <?php endif; ?>
</div>
<!--formulario de Nueva orden de Mantenimiento-->
<form method="POST" action="mantenimiento.php">
<?php if($isEdit): ?><input type="hidden" name="id" value="<?=$m['id']?>"><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-4">
    <div class="card">
      <div class="card-header-ti"><i class="bi bi-info-circle me-2"></i>Datos Generales</div>
      <div class="card-body">
        <div class="mb-2">
          <label class="form-label">Equipo *</label>
          <select name="equipo_id" id="sel-equipo-mant" class="form-select" required
                  onchange="window.location.href='mantenimiento.php?action=<?=$isEdit?'edit&id='.$id:'new'?>&equipo_id='+this.value;">
            <option value="">— Seleccionar equipo —</option>
            <?php foreach($equipos as $e):
              $inetLbl = !empty($e['acceso_internet'])
                ? (['SIN_ACCESO'=>' ⛔','RESTRINGIDO'=>' 🔒','COMPLETO'=>' 🌐'][$e['tipo_internet']]??'')
                : '';
            ?>
            <option value="<?=$e['id']?>"
                    data-ip="<?=h($e['ip']??'')?>"
                    data-internet="<?=h($e['acceso_internet']??0)?>"
                    data-tipo-internet="<?=h($e['tipo_internet']??'SIN_ACCESO')?>"
                    <?=(int)($m['equipo_id']??$equipoIdPre)==(int)$e['id']?'selected':''?>>
              <?=h($e['folio'].' — '.$e['marca'].' '.($e['modelo']??'')).$inetLbl?>
            </option>
            <?php endforeach; ?>
          </select>
          <?php if ($equipoParaChecklist): ?>
          <div class="mt-1 p-2 rounded small" style="background:#f0f4ff;border:1px solid #c7d8f8;">
            <?php
            $tiCls2=['SIN_ACCESO'=>'secondary','RESTRINGIDO'=>'warning text-dark','COMPLETO'=>'success'][$equipoParaChecklist['tipo_internet']??'SIN_ACCESO']??'secondary';
            $tiIco2=['SIN_ACCESO'=>'bi-wifi-off','RESTRINGIDO'=>'bi-lock-fill','COMPLETO'=>'bi-globe'][$equipoParaChecklist['tipo_internet']??'SIN_ACCESO']??'bi-wifi-off';
            $tiLbl2=['SIN_ACCESO'=>'Sin acceso','RESTRINGIDO'=>'Restringido','COMPLETO'=>'Completo'][$equipoParaChecklist['tipo_internet']??'SIN_ACCESO']??'—';
            ?>
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <code class="text-primary"><?=h($equipoParaChecklist['ip']??'—')?></code>
              <span class="badge bg-<?=$tiCls2?>" style="font-size:.65rem;">
                <i class="bi <?=$tiIco2?> me-1"></i><?=$tiLbl2?>
              </span>
              <?php if(!empty($equipoParaChecklist['acceso_internet'])): ?>
              <small class="text-success"><i class="bi bi-check-circle me-1"></i>Internet configurado</small>
              <?php else: ?>
              <small class="text-muted"><i class="bi bi-x-circle me-1"></i>Sin internet registrado</small>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <div class="mb-2">
          <label class="form-label">Usuario del Equipo</label>
          <select name="usuario_id" class="form-select">
            <option value="">— Seleccionar —</option>
            <?php foreach($usuarios as $u): ?><option value="<?=$u['id']?>" <?=$usuarioIdPre===(int)$u['id']?'selected':''?>><?=h($u['nombre'].' '.($u['apellidos']??''))?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Ticket Relacionado</label>
          <select name="ticket_id" class="form-select">
            <option value="">— Ninguno —</option>
            <?php foreach($tickets as $t): ?><option value="<?=$t['id']?>" <?=(int)($m['ticket_id']??0)==(int)$t['id']?'selected':''?>><?=h($t['folio_ticket'].' — '.$t['descripcion'])?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Tipo</label>
          <select name="tipo" class="form-select">
            <?php foreach(['PREVENTIVO','CORRECTIVO'] as $tp): ?><option <?=($m['tipo']??'PREVENTIVO')===$tp?'selected':''?>><?=$tp?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Fecha *</label>
          <input type="date" name="fecha" class="form-control" value="<?=h($m['fecha']??date('Y-m-d'))?>" required>
        </div>
        <div class="mb-2">
          <label class="form-label">Técnico Responsable</label>
          <select name="tecnico" class="form-select">
            <option value="">— Seleccionar técnico —</option>
            <?php foreach($tecnicos as $tec): ?>
            <option value="<?=h($tec['nombre_completo'])?>" <?=$tecnicoActual===trim((string)$tec['nombre_completo'])?'selected':''?>>
              <?=h($tec['nombre_completo'])?>
            </option>
            <?php endforeach; ?>
            <?php
              $tecnicoExiste = false;
              foreach ($tecnicos as $tec) {
                  if ($tecnicoActual !== '' && $tecnicoActual === trim((string)$tec['nombre_completo'])) {
                      $tecnicoExiste = true;
                      break;
                  }
              }
            ?>
            <?php if ($tecnicoActual !== '' && !$tecnicoExiste): ?>
            <option value="<?=h($tecnicoActual)?>" selected><?=h($tecnicoActual)?> (no activo)</option>
            <?php endif; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label">Observaciones Generales</label>
          <textarea name="observaciones" class="form-control" rows="3"><?=h($m['observaciones']??'')?></textarea>
        </div>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <div class="card">
      <div class="card-header-ti d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-list-check me-2"></i>Checklist de Mantenimiento</span>
        <div class="d-flex gap-2 no-print">
          <button type="button" id="btn-mark-all-ok-global" class="btn btn-sm btn-success py-0">✓ Todo OK</button>
          <button type="button" id="btn-mark-all-na" class="btn btn-sm btn-outline-light py-0">N/A Todo</button>
        </div>
      </div>
      <div class="card-body p-2" style="max-height:600px;overflow-y:auto;">
        <?php foreach ($template as $cat => $items): ?>
        <div class="check-categoria d-flex justify-content-between align-items-center mb-1">
          <span><?=h($cat)?></span>
          <button type="button" class="btn btn-xs btn-light py-0 no-print btn-mark-all-ok" data-cat="<?=h($cat)?>">✓ Marcar todos OK</button>
        </div>
        <?php foreach ($items as $idx => $item):
          $key = 'check_'.md5($cat.'_'.$idx);
          $saved = $savedCheckMap[$cat.'|'.$item] ?? null;
          $resSaved = $saved['resultado'] ?? 'NO_APLICA';
          $notesSaved = $saved['notas'] ?? '';
          // Resaltar ítems relacionados con internet
          $isInternetItem = (str_contains($item,'internet') || str_contains($item,'Internet')
                          || str_contains($item,'ping') || str_contains($item,'navegación')
                          || str_contains($item,'Navegación'));
          $rowStyle = $isInternetItem ? 'background:#fff8e1;border-left:3px solid #ffc107;padding-left:6px;border-radius:3px;' : '';
        ?>
        <div class="check-row mb-1" style="<?=$rowStyle?>">
          <div class="item-label">
            <?php if($isInternetItem): ?><i class="bi bi-globe text-warning me-1" title="Ítem de internet"></i><?php endif; ?>
            <?=h($item)?>
          </div>
          <div class="check-result">
            <select name="<?=$key?>_resultado" class="form-select form-select-sm check-sel" data-cat="<?=h($cat)?>">
              <?php foreach(['OK'=>'✓ OK','REQUIERE_ATENCION'=>'⚠ Requiere Atención','MAL'=>'✗ Mal','NO_APLICA'=>'— N/A'] as $rv=>$rl): ?>
              <option value="<?=$rv?>" <?=$resSaved===$rv?'selected':''?>><?=$rl?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="check-notes">
            <input type="text" name="<?=$key?>_notas" class="form-control form-control-sm" placeholder="Nota…" value="<?=h($notesSaved)?>">
          </div>
        </div>
        <?php endforeach; ?>
        <hr class="my-2">
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="d-flex gap-2 no-print">
  <button type="submit" name="guardar_mantenimiento" value="1" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar Mantenimiento</button>
  <a href="mantenimiento.php" class="btn btn-outline-secondary">Cancelar</a>
</div>
</form>

<?php elseif ($action === 'view' && $id): ?>
<!-- ══ DETALLE MANTENIMIENTO ══ -->
<?php
$m = getMantenimientoById($id);
if (!$m) { flash('danger','No encontrado.'); redirect('mantenimiento.php'); }
$checks = getChecks($id);
$checksByCat = [];
foreach ($checks as $c) { $checksByCat[$c['categoria']][] = $c; }
$clsTipo = $m['tipo']==='PREVENTIVO' ? 'bg-info text-dark' : 'bg-warning text-dark';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-1"><i class="bi bi-clipboard-check me-2"></i>Orden de Mantenimiento #<?=$m['id']?></h5>
    <span class="badge <?=$clsTipo?>"><?=h($m['tipo'])?></span>
    <span class="badge bg-secondary ms-1"><?=h($m['fecha'])?></span>
  </div>
  <div class="d-flex gap-2 no-print">
    <a href="mantenimiento.php?action=edit&id=<?=$id?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Editar</a>
    <a href="exports/export_mantenimiento_pdf.php?id=<?=$id?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Imprimir</button>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-4">
    <div class="card">
      <div class="card-header-ti"><i class="bi bi-info-circle me-2"></i>Datos Generales</div>
      <div class="card-body">
        <?php $info=[['Equipo',$m['equipo_folio'].' — '.h($m['marca'].' '.($m['modelo']??''))],['Técnico',$m['tecnico']],['Fecha',$m['fecha']],['Tipo',$m['tipo']],['Usuario',$m['usuario_nombre']]]; ?>
        <table class="table table-sm mb-2">
          <?php foreach($info as [$k,$v]): if(!$v) continue; ?><tr><th class="small text-muted"><?=$k?></th><td class="small"><?=$v?></td></tr><?php endforeach; ?>
        </table>
        <?php if($m['observaciones']): ?>
        <div class="alert alert-light py-2 small"><strong>Observaciones:</strong><br><?=h($m['observaciones'])?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-8">
    <?php foreach ($checksByCat as $cat => $items): ?>
    <div class="card mb-3">
      <div class="check-categoria" style="border-radius:8px 8px 0 0;"><?=h($cat)?></div>
      <div class="card-body p-0">
        <table class="table table-ti mb-0">
          <thead><tr><th>Elemento</th><th style="width:160px">Resultado</th><th>Notas</th></tr></thead>
          <tbody>
          <?php foreach($items as $c):
            $rclass=['OK'=>'text-success','MAL'=>'text-danger','REQUIERE_ATENCION'=>'text-warning','NO_APLICA'=>'text-muted'][$c['resultado']]??'text-muted';
            $ricon=['OK'=>'bi-check-circle-fill','MAL'=>'bi-x-circle-fill','REQUIERE_ATENCION'=>'bi-exclamation-triangle-fill','NO_APLICA'=>'bi-dash-circle'][$c['resultado']]??'bi-dash';
          ?>
          <tr>
            <td class="small"><?=h($c['item'])?></td>
            <td><span class="<?=$rclass?>"><i class="bi <?=$ricon?> me-1"></i><?=h(str_replace('_',' ',$c['resultado']))?></span></td>
            <td class="small text-muted"><?=h($c['notas']??'')?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- Firma -->
<div class="row g-3 mt-2 no-print-hidden">
  <div class="col-md-4 offset-md-2 text-center">
    <div style="border-top:2px solid #333;padding-top:8px;margin-top:40px;">
      <strong class="small">TÉCNICO RESPONSABLE</strong><br>
      <span class="text-muted small"><?=h($m['tecnico']??'___________________________')?></span>
    </div>
  </div>
  <div class="col-md-4 text-center">
    <div style="border-top:2px solid #333;padding-top:8px;margin-top:40px;">
      <strong class="small">USUARIO / RESPONSABLE DEL EQUIPO</strong><br>
      <span class="text-muted small"><?=h($m['usuario_nombre']??'___________________________')?></span>
    </div>
  </div>
</div>

<?php elseif ($action === 'calendario'): ?>
<?php
$areas = getAreas();
$anio    = (int)($_GET['anio'] ?? date('Y'));
$periodo = ($_GET['periodo'] ?? getPeriodoDeMes((int)date('n'))) === 'P2' ? 'P2' : 'P1';
$areaFiltro     = (int)($_GET['area'] ?? 0);
$edificioFiltro = (int)($_GET['edificio'] ?? 0);

$items   = getProgramacionMantenimiento($anio, $periodo, $areaFiltro, $edificioFiltro);
$resumen = getResumenPeriodo($anio, $periodo, $areaFiltro, $edificioFiltro);
$meses   = getMesesDePeriodo($periodo);
$hoy     = date('Y-m-d');

// Agrupa por área -> mes -> [items]
$grid = [];
foreach ($items as $it) {
    $areaKey = $it['area_id'] ?: 0;
    $areaNom = $it['area_nombre'] ?: 'Sin área asignada';
    $grid[$areaKey]['nombre'] = $areaNom;
    $grid[$areaKey]['meses'][$it['mes']][] = $it;
}
ksort($grid);

$estadoBadge = [
    'PROGRAMADO'   => 'bg-primary',
    'REALIZADO'    => 'bg-success',
    'REPROGRAMADO' => 'bg-warning text-dark',
    'CANCELADO'    => 'bg-secondary',
];
$edifById = [];
foreach ($edificiosDisponibles as $e) $edifById[$e['id']] = $e['nombre'];

$qsBase = http_build_query(['action'=>'calendario','anio'=>$anio,'periodo'=>$periodo,'area'=>$areaFiltro,'edificio'=>$edificioFiltro]);
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
  <h5 class="mb-0"><i class="bi bi-calendar3-week me-2"></i>Calendario de Programación — <?=getNombrePeriodo($periodo)?> <?=$anio?></h5>
  <div class="d-flex gap-2 flex-wrap">
    <a href="exports/export_calendario_mantenimiento_pdf.php?anio=<?=$anio?>&periodo=<?=$periodo?>&area=<?=$areaFiltro?>&edificio=<?=$edificioFiltro?>" target="_blank" class="btn btn-sm btn-outline-danger">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </a>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Imprimir</button>
    <?php if (puede('crear_mantenimiento') || isAdmin() || isSuper()): ?>
    <a href="mantenimiento.php?action=calendario_new&anio=<?=$anio?>&periodo=<?=$periodo?>" class="btn btn-sm" style="background:#1a3a5c;color:#fff;">
      <i class="bi bi-plus me-1"></i>Nueva Programación
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-3 no-print">
  <div class="card-body">
    <form method="GET" action="mantenimiento.php" class="row g-2 align-items-end">
      <input type="hidden" name="action" value="calendario">
      <div class="col-md-2">
        <label class="form-label small mb-1">Año</label>
        <input type="number" name="anio" class="form-control form-control-sm" value="<?=$anio?>">
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">Periodo</label>
        <div class="btn-group w-100" role="group">
          <a href="mantenimiento.php?<?=http_build_query(['action'=>'calendario','anio'=>$anio,'periodo'=>'P1','area'=>$areaFiltro,'edificio'=>$edificioFiltro])?>"
             class="btn btn-sm <?=$periodo==='P1'?'btn-primary':'btn-outline-secondary'?>">Ene—Jun</a>
          <a href="mantenimiento.php?<?=http_build_query(['action'=>'calendario','anio'=>$anio,'periodo'=>'P2','area'=>$areaFiltro,'edificio'=>$edificioFiltro])?>"
             class="btn btn-sm <?=$periodo==='P2'?'btn-primary':'btn-outline-secondary'?>">Jul—Dic</a>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label small mb-1">Área</label>
        <select name="area" class="form-select form-select-sm">
          <option value="0">Todas</option>
          <?php foreach ($areas as $a): ?>
          <option value="<?=$a['id']?>" <?=$areaFiltro==$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (!empty($edificiosDisponibles)): ?>
      <div class="col-md-3">
        <label class="form-label small mb-1">Edificio</label>
        <select name="edificio" class="form-select form-select-sm">
          <option value="0">Todos</option>
          <?php foreach ($edificiosDisponibles as $e): ?>
          <option value="<?=$e['id']?>" <?=$edificioFiltro==$e['id']?'selected':''?>><?=h($e['nombre'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-1">
        <button class="btn btn-sm btn-outline-secondary w-100"><i class="bi bi-search"></i></button>
      </div>
    </form>
  </div>
</div>

<!-- KPIs / Filtros rápidos de estado -->
<div class="row g-2 mb-3 no-print" id="kpiFiltros">
  <div class="col-6 col-md-2"><div class="card text-center py-2 kpi-filtro active" data-filtro="" role="button"><div class="fw-bold fs-5"><?=$resumen['total']?></div><div class="small text-muted">Total</div></div></div>
  <div class="col-6 col-md-2"><div class="card text-center py-2 kpi-filtro" data-filtro="PROGRAMADO" role="button"><div class="fw-bold fs-5 text-primary"><?=$resumen['programado']?></div><div class="small text-muted">Programado</div></div></div>
  <div class="col-6 col-md-2"><div class="card text-center py-2 kpi-filtro" data-filtro="REALIZADO" role="button"><div class="fw-bold fs-5 text-success"><?=$resumen['realizado']?></div><div class="small text-muted">Realizado</div></div></div>
  <div class="col-6 col-md-2"><div class="card text-center py-2 kpi-filtro" data-filtro="REPROGRAMADO" role="button"><div class="fw-bold fs-5 text-warning"><?=$resumen['reprogramado']?></div><div class="small text-muted">Reprogramado</div></div></div>
  <div class="col-6 col-md-2"><div class="card text-center py-2 kpi-filtro" data-filtro="CANCELADO" role="button"><div class="fw-bold fs-5 text-secondary"><?=$resumen['cancelado']?></div><div class="small text-muted">Cancelado</div></div></div>
  <div class="col-6 col-md-2"><div class="card text-center py-2 kpi-filtro" data-filtro="ATRASADO" role="button"><div class="fw-bold fs-5 text-danger"><?=$resumen['atrasado']?></div><div class="small text-muted">Atrasado</div></div></div>
</div>
<div class="form-text mb-2 no-print"><i class="bi bi-info-circle me-1"></i>Haz clic en una tarjeta de arriba para filtrar. Arrastra una programación a otro mes para reprogramarla.</div>

<!-- Grid del calendario -->
<div class="table-responsive">
<table class="table table-bordered table-ti align-middle mb-0" style="background:#fff;">
  <thead>
    <tr class="text-center">
      <th style="min-width:160px;">Área</th>
      <?php foreach ($meses as $mNum): ?>
      <th><?=getNombreMes($mNum)?></th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($grid)): ?>
    <tr><td colspan="<?=count($meses)+1?>" class="text-center text-muted py-4">Sin programación registrada para este periodo/filtros.</td></tr>
  <?php endif; ?>
  <?php foreach ($grid as $areaKey => $fila): ?>
    <tr>
      <td class="fw-bold small"><?=h($fila['nombre'])?></td>
      <?php foreach ($meses as $mNum): ?>
      <td class="drop-zone" ondragover="onDragOver(event)" ondragleave="onDragLeave(event)" ondrop="onDrop(event, <?=$mNum?>)" style="vertical-align:top;min-width:130px;">
        <div class="drop-zone-inner" style="min-height:50px;">
        <?php foreach (($fila['meses'][$mNum] ?? []) as $it):
          $atrasado = $it['estado']==='PROGRAMADO' && !empty($it['fecha_programada']) && $it['fecha_programada'] < $hoy;
          $badge = $atrasado ? 'bg-danger' : ($estadoBadge[$it['estado']] ?? 'bg-secondary');
          $estadoDato = $atrasado ? 'ATRASADO' : $it['estado'];
          $jsonItem = htmlspecialchars(json_encode([
              'titulo'      => $it['descripcion'],
              'tipo'        => $it['tipo'],
              'estado'      => $estadoDato,
              'area'        => $fila['nombre'],
              'edificio'    => (!empty($it['edificio_id']) && isset($edifById[$it['edificio_id']])) ? $edifById[$it['edificio_id']] : '',
              'equipo'      => $it['equipo_folio'] ?? '',
              'responsable' => $it['responsable'] ?? '',
              'fecha_programada' => $it['fecha_programada'] ?? '',
              'fecha_realizada'  => $it['fecha_realizada'] ?? '',
              'equipos_revisar'  => $it['equipos_revisar'] ?? '',
              'refacciones'      => $it['refacciones'] ?? '',
              'notas'            => $it['notas'] ?? '',
              'edit_url'   => 'mantenimiento.php?action=calendario_edit&id='.$it['id'],
              'marcar_url' => 'mantenimiento.php?action=calendario_marcar&id='.$it['id'],
              'reabrir_url'=> 'mantenimiento.php?action=calendario_reabrir&id='.$it['id'],
              'realizado'  => $it['estado']==='REALIZADO',
          ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        ?>
        <div class="mant-card mb-1 p-1 rounded" draggable="true"
             ondragstart="onDragStart(event, <?=$it['id']?>)"
             data-id="<?=$it['id']?>" data-estado="<?=h($estadoDato)?>"
             data-json='<?=$jsonItem?>'
             onclick="abrirModalDetalle(this)"
             style="background:#f4f6f8;font-size:.72rem;cursor:grab;">
          <div class="d-flex justify-content-between align-items-start gap-1">
            <span class="badge <?=$badge?>" style="font-size:.62rem;"><?=$atrasado?'ATRASADO':str_replace('_',' ',$it['estado'])?></span>
            <div class="d-flex gap-1 no-print">
              <a href="mantenimiento.php?action=calendario_edit&id=<?=$it['id']?>" onclick="event.stopPropagation()" data-bs-toggle="tooltip" title="Editar"><i class="bi bi-pencil text-primary"></i></a>
              <?php if ($it['estado']!=='REALIZADO'): ?>
              <a href="mantenimiento.php?action=calendario_marcar&id=<?=$it['id']?>" onclick="event.stopPropagation(); return confirm('¿Marcar esta programación como realizada?');" data-bs-toggle="tooltip" title="Marcar realizado"><i class="bi bi-check-circle text-success"></i></a>
              <?php else: ?>
              <a href="mantenimiento.php?action=calendario_reabrir&id=<?=$it['id']?>" onclick="event.stopPropagation(); return confirm('¿Reabrir esta programación?');" data-bs-toggle="tooltip" title="Reabrir"><i class="bi bi-arrow-counterclockwise text-warning"></i></a>
              <?php endif; ?>
              <?php if (isAdmin()): ?>
              <button type="button" onclick="event.stopPropagation(); confirmDelete('mantenimiento.php?action=calendario_delete&id=<?=$it['id']?>','esta programación')" style="background:none;border:none;padding:0;" data-bs-toggle="tooltip" title="Eliminar"><i class="bi bi-trash text-danger"></i></button>
              <?php endif; ?>
            </div>
          </div>
          <div class="fw-bold mt-1"><?=h($it['descripcion'])?></div>
          <?php if ($it['equipo_folio']): ?><div class="text-muted"><i class="bi bi-pc-display"></i> <?=h($it['equipo_folio'])?></div><?php endif; ?>
          <?php if (!empty($it['edificio_id']) && isset($edifById[$it['edificio_id']])): ?><div class="text-muted"><i class="bi bi-building"></i> <?=h($edifById[$it['edificio_id']])?></div><?php endif; ?>
          <?php if ($it['responsable']): ?><div class="text-muted" data-bs-toggle="tooltip" title="Técnico responsable"><i class="bi bi-person"></i> <?=h($it['responsable'])?></div><?php endif; ?>
          <?php if ($it['fecha_programada']): ?><div class="text-muted"><?=date('d/m', strtotime($it['fecha_programada']))?></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
      </td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<!-- Modal de detalle -->
<div class="modal fade" id="modalDetalleProgramacion" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:#1a3a5c;color:#fff;">
        <h5 class="modal-title" id="mdTitulo">Detalle de Programación</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex flex-wrap gap-2 mb-3">
          <span class="badge bg-secondary" id="mdEstado"></span>
          <span class="badge bg-info text-dark" id="mdTipo"></span>
        </div>
        <div class="row g-2 small mb-3">
          <div class="col-md-6"><strong>Área:</strong> <span id="mdArea"></span></div>
          <div class="col-md-6"><strong>Edificio:</strong> <span id="mdEdificio">—</span></div>
          <div class="col-md-6"><strong>Equipo:</strong> <span id="mdEquipo">—</span></div>
          <div class="col-md-6"><strong>Responsable:</strong> <span id="mdResponsable">—</span></div>
          <div class="col-md-6"><strong>Fecha programada:</strong> <span id="mdFechaProg">—</span></div>
          <div class="col-md-6"><strong>Fecha realizada:</strong> <span id="mdFechaReal">—</span></div>
        </div>
        <hr>
        <h6><i class="bi bi-list-check me-1"></i>Equipos a revisar</h6>
        <div id="mdEquiposRevisar" class="mb-3 small" style="white-space:pre-wrap;">—</div>
        <h6><i class="bi bi-tools me-1"></i>Refacciones necesarias</h6>
        <div id="mdRefacciones" class="mb-3 small" style="white-space:pre-wrap;">—</div>
        <h6><i class="bi bi-journal-text me-1"></i>Observaciones técnicas</h6>
        <div id="mdNotas" class="small" style="white-space:pre-wrap;">—</div>
      </div>
      <div class="modal-footer">
        <a href="#" id="mdBtnEditar" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Editar</a>
        <a href="#" id="mdBtnMarcar" class="btn btn-sm btn-outline-success"><i class="bi bi-check-circle me-1"></i>Marcar realizado</a>
        <a href="#" id="mdBtnReabrir" class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Reabrir</a>
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
      </div>
    </div>
  </div>
</div>

<style>
  .kpi-filtro { cursor:pointer; transition:.15s; border:2px solid transparent; }
  .kpi-filtro:hover { background:#f4f6f8; }
  .kpi-filtro.active { border-color:#1a3a5c; }
  .drop-zone.drop-hover { background:#eaf1f8 !important; outline:2px dashed #1a3a5c; }
  .mant-card:active { cursor:grabbing; }
</style>

<script>
// ---- Drag & Drop ----
let dragProgId = null;
function onDragStart(ev, id) {
  dragProgId = id;
  ev.dataTransfer.setData('text/plain', String(id));
  ev.dataTransfer.effectAllowed = 'move';
}
function onDragOver(ev) { ev.preventDefault(); ev.currentTarget.classList.add('drop-hover'); }
function onDragLeave(ev) { ev.currentTarget.classList.remove('drop-hover'); }
async function onDrop(ev, mes) {
  ev.preventDefault();
  const zone = ev.currentTarget;
  zone.classList.remove('drop-hover');
  const id = ev.dataTransfer.getData('text/plain') || dragProgId;
  if (!id) return;
  const card = document.querySelector('.mant-card[data-id="' + id + '"]');
  if (!card) return;
  try {
    const resp = await fetch('ajax_mover_programacion.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'id=' + encodeURIComponent(id) + '&mes=' + encodeURIComponent(mes)
    });
    const data = await resp.json();
    if (data.success) {
      zone.querySelector('.drop-zone-inner').appendChild(card);
    } else {
      alert(data.message || 'No se pudo reprogramar. Intenta de nuevo.');
    }
  } catch (e) {
    alert('Error de conexión al reprogramar.');
  }
}

// ---- Filtros rápidos por estado (KPIs) ----
document.querySelectorAll('.kpi-filtro').forEach(function (kpi) {
  kpi.addEventListener('click', function () {
    document.querySelectorAll('.kpi-filtro').forEach(k => k.classList.remove('active'));
    kpi.classList.add('active');
    const filtro = kpi.dataset.filtro;
    document.querySelectorAll('.mant-card').forEach(function (card) {
      card.style.display = (!filtro || card.dataset.estado === filtro) ? '' : 'none';
    });
  });
});

// ---- Modal de detalle ----
function abrirModalDetalle(el) {
  const d = JSON.parse(el.dataset.json);
  document.getElementById('mdTitulo').textContent = d.titulo;
  document.getElementById('mdEstado').textContent = d.estado;
  document.getElementById('mdTipo').textContent = d.tipo;
  document.getElementById('mdArea').textContent = d.area || '—';
  document.getElementById('mdEdificio').textContent = d.edificio || '—';
  document.getElementById('mdEquipo').textContent = d.equipo || '—';
  document.getElementById('mdResponsable').textContent = d.responsable || '—';
  document.getElementById('mdFechaProg').textContent = d.fecha_programada || '—';
  document.getElementById('mdFechaReal').textContent = d.fecha_realizada || '—';
  document.getElementById('mdEquiposRevisar').textContent = d.equipos_revisar || 'Sin especificar';
  document.getElementById('mdRefacciones').textContent = d.refacciones || 'Sin especificar';
  document.getElementById('mdNotas').textContent = d.notas || 'Sin observaciones';

  const btnEditar = document.getElementById('mdBtnEditar');
  const btnMarcar = document.getElementById('mdBtnMarcar');
  const btnReabrir = document.getElementById('mdBtnReabrir');
  btnEditar.href = d.edit_url;
  btnMarcar.href = d.marcar_url;
  btnReabrir.href = d.reabrir_url;
  btnMarcar.classList.toggle('d-none', d.realizado);
  btnReabrir.classList.toggle('d-none', !d.realizado);
  btnMarcar.onclick = function () { return confirm('¿Marcar esta programación como realizada?'); };
  btnReabrir.onclick = function () { return confirm('¿Reabrir esta programación?'); };

  new bootstrap.Modal(document.getElementById('modalDetalleProgramacion')).show();
}

// ---- Tooltips ----
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
    new bootstrap.Tooltip(el);
  });
});
</script>

<?php elseif (in_array($action, ['calendario_new','calendario_edit'])): ?>
<?php
$progEdit = ($action === 'calendario_edit' && $id) ? getProgramacionById($id) : null;
$isEditProg = !empty($progEdit);
$areas = getAreas();
$anioPreset    = (int)($_GET['anio'] ?? ($progEdit['anio'] ?? date('Y')));
$periodoPreset = $_GET['periodo'] ?? ($progEdit ? getPeriodoDeMes($progEdit['mes']) : getPeriodoDeMes((int)date('n')));
?>
<div class="row justify-content-center"><div class="col-md-7">
<div class="card">
  <div class="card-header-ti"><?=$isEditProg?'Editar':'Nueva'?> Programación de Mantenimiento</div>
  <div class="card-body">
    <form method="POST" action="mantenimiento.php">
      <input type="hidden" name="form_type" value="programacion">
      <?php if ($isEditProg): ?><input type="hidden" name="id" value="<?=$progEdit['id']?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label">Año *</label>
          <input type="number" name="anio" class="form-control" value="<?=h($progEdit['anio'] ?? $anioPreset)?>" required></div>
        <div class="col-md-5"><label class="form-label">Mes *</label>
          <select name="mes" class="form-select" required>
            <?php for ($mNum=1;$mNum<=12;$mNum++): ?>
            <option value="<?=$mNum?>" <?=($progEdit['mes'] ?? 0)==$mNum?'selected':''?>><?=getNombreMes($mNum)?></option>
            <?php endfor; ?>
          </select>
        </div>
        <div class="col-md-4"><label class="form-label">Tipo</label>
          <select name="tipo" class="form-select">
            <option value="PREVENTIVO" <?=($progEdit['tipo'] ?? 'PREVENTIVO')==='PREVENTIVO'?'selected':''?>>Preventivo</option>
            <option value="CORRECTIVO" <?=($progEdit['tipo'] ?? '')==='CORRECTIVO'?'selected':''?>>Correctivo</option>
          </select>
        </div>
        <div class="col-md-6"><label class="form-label">Área</label>
          <select name="area_id" class="form-select">
            <option value="0">— Sin especificar —</option>
            <?php foreach ($areas as $a): ?>
            <option value="<?=$a['id']?>" <?=($progEdit['area_id'] ?? 0)==$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if (!empty($edificiosDisponibles)): ?>
        <div class="col-md-6"><label class="form-label">Edificio</label>
          <select name="edificio_id" class="form-select">
            <option value="0">— Sin especificar —</option>
            <?php foreach ($edificiosDisponibles as $e): ?>
            <option value="<?=$e['id']?>" <?=($progEdit['edificio_id'] ?? 0)==$e['id']?'selected':''?>><?=h($e['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div class="col-12"><label class="form-label">Descripción *</label>
          <input type="text" name="descripcion" class="form-control" value="<?=h($progEdit['descripcion'] ?? '')?>" required placeholder="Ej: Mantenimiento preventivo de equipos de cómputo — Contabilidad"></div>
        <div class="col-md-6"><label class="form-label">Equipos a revisar</label>
          <textarea name="equipos_revisar" class="form-control" rows="3" placeholder="Uno por línea, ej:&#10;PC-045 - Recepción&#10;PC-046 - Contabilidad"><?=h($progEdit['equipos_revisar'] ?? '')?></textarea></div>
        <div class="col-md-6"><label class="form-label">Refacciones necesarias</label>
          <textarea name="refacciones" class="form-control" rows="3" placeholder="Uno por línea, ej:&#10;Memoria RAM 8GB&#10;Ventilador CPU"><?=h($progEdit['refacciones'] ?? '')?></textarea></div>
        <div class="col-md-6"><label class="form-label">Responsable</label>
          <input type="text" name="responsable" class="form-control" value="<?=h($progEdit['responsable'] ?? '')?>"></div>
        <div class="col-md-6"><label class="form-label">Fecha Programada</label>
          <input type="date" name="fecha_programada" class="form-control" value="<?=h($progEdit['fecha_programada'] ?? '')?>"></div>
        <div class="col-md-6"><label class="form-label">Estado</label>
          <select name="estado" class="form-select">
            <?php foreach (['PROGRAMADO'=>'Programado','REALIZADO'=>'Realizado','REPROGRAMADO'=>'Reprogramado','CANCELADO'=>'Cancelado'] as $val=>$lab): ?>
            <option value="<?=$val?>" <?=($progEdit['estado'] ?? 'PROGRAMADO')==$val?'selected':''?>><?=$lab?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><label class="form-label">Observaciones Técnicas</label>
          <textarea name="notas" class="form-control" rows="2"><?=h($progEdit['notas'] ?? '')?></textarea></div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
        <a href="mantenimiento.php?action=calendario&anio=<?=$anioPreset?>&periodo=<?=$periodoPreset?>" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div></div></div>

<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

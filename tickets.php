<?php
require_once 'includes/auth.php';

$action  = $_GET['action'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── APROBAR / RECHAZAR cambio del Supervisor (solo Admin) ──────────────────
if (in_array($action, ['aprobar_cambio','rechazar_cambio']) && $id && $requestMethod === 'POST') {
    requireRol(ROL_ADMIN);
    $cpId     = (int)($_POST['cp_id'] ?? 0);
    $nota     = trim($_POST['admin_nota'] ?? '');
    $decision = $action === 'aprobar_cambio' ? 'APROBADO' : 'RECHAZADO';

    $cp = getCambioPendienteById($cpId);
    if (!$cp || (int)$cp['registro_id'] !== $id || $cp['estado'] !== 'PENDIENTE') {
        flash('danger','Cambio pendiente no válido o ya procesado.');
        redirect('tickets.php?action=view&id='.$id);
    }

    resolverCambio($cpId, $decision, $nota);

    if ($decision === 'APROBADO') {
        redirect('tickets.php?action=view&id='.$id.'&swal_aprobado='.$cpId);
    } else {
        redirect('tickets.php?action=view&id='.$id.'&swal_rechazado='.$cpId);
    }
}

// ── ASIGNAR (solo admin) — debe ir ANTES del POST genérico ──
if ($action === 'asignar' && $id && $requestMethod === 'POST') {
    requireRol(ROL_ADMIN);

    $superId   = (int)($_POST['supervisor_id'] ?? 0);
    $superInfo = $superId ? getSystemUserById($superId) : null;

    // Validar que exista y tenga rol de SUPERVISOR_TECNICO
    if (!$superInfo || ($superInfo['rol'] ?? '') !== ROL_SUPER) {
        flash('danger', 'Supervisor no válido o no encontrado.');
        redirect('tickets.php?action=view&id='.$id);
    }

    // Validar que el ticket existe y está en estado asignable
    $ticketAsig = getTicketById($id);
    if (!$ticketAsig) {
        flash('danger', 'Ticket no encontrado.');
        redirect('tickets.php');
    }
    if (!in_array($ticketAsig['estado'], ['ABIERTO', 'EN_PROCESO'])) {
        flash('warning', 'Solo se pueden asignar tickets en estado ABIERTO o EN PROCESO.');
        redirect('tickets.php?action=view&id='.$id);
    }

    $db = getDB();
    // Si había asignación previa a otro supervisor, registrarlo en auditoría
    $anteriorSuid = (int)($ticketAsig['asignado_a_suser'] ?? 0);
    $esReasignacion = $anteriorSuid > 0 && $anteriorSuid !== $superId;

    $db->prepare(
        "UPDATE tickets
           SET asignado_a_suser = ?,
               asignado_por     = ?,
               tecnico          = ?,
               estado           = 'EN_PROCESO'
         WHERE id = ?"
    )->execute([$superId, suserIdActual(), $superInfo['nombre_completo'], $id]);

    $desc = $esReasignacion
        ? 'Reasignado a: '.$superInfo['nombre_completo'].' (anterior: '.($ticketAsig['supervisor_asignado_nombre'] ?? 'N/A').')'
        : 'Asignado a: '.$superInfo['nombre_completo'];

    auditLog('ASIGNAR_TICKET', 'tickets', $id, $desc);
    flash('success', ($esReasignacion ? 'Ticket reasignado' : 'Ticket asignado').' a '.$superInfo['nombre_completo'].'.');
    redirect('tickets.php?action=view&id='.$id);
}

// ── POST: crear / editar ticket ───────────────────────────
if ($requestMethod === 'POST') {
    $pid = (int)($_POST['id']??0) ?: null;
    $crearOtro = !$pid && (int)($_POST['crear_otro_ticket'] ?? 0) === 1;

    // Usuario normal: solo puede crear solicitudes propias
    if (isNormal()) {
        if ($pid) { flash('danger','Sin permiso para editar tickets.'); redirect('tickets.php'); }
        $puedeJerarquia = puedeSolicitarTicketsJerarquia();
        if ($puedeJerarquia) {
            $usuariosPermitidos = getUsuariosJerarquiaSolicitante();
            $equiposPermitidos = getEquiposJerarquiaSolicitante();
            $usuarioIds = array_map(fn($u)=>(int)$u['id'], $usuariosPermitidos);
            $equipoIds = array_map(fn($e)=>(int)$e['id'], $equiposPermitidos);

            $usuarioSel = (int)($_POST['usuario_id'] ?? 0);
            $equipoSel  = (int)($_POST['equipo_id'] ?? 0);
            $eqMap = [];
            foreach ($equiposPermitidos as $e) $eqMap[(int)$e['id']] = (int)($e['usuario_id'] ?? 0);
            if ($usuarioSel === 0 && $equipoSel > 0 && isset($eqMap[$equipoSel])) {
                $usuarioSel = (int)$eqMap[$equipoSel];
            }
            if (!$usuarioSel || !$equipoSel || !in_array($usuarioSel, $usuarioIds, true) || !in_array($equipoSel, $equipoIds, true)) {
                flash('danger','Solo puedes solicitar tickets para usuarios y equipos de tu jerarquía.');
                redirect('tickets.php?action=new');
            }
            if (($eqMap[$equipoSel] ?? 0) !== $usuarioSel) {
                flash('danger','El equipo seleccionado no pertenece al usuario seleccionado.');
                redirect('tickets.php?action=new');
            }
            $_POST['usuario_id'] = $usuarioSel;
        } else {
            $_POST['usuario_id'] = usuarioIdActual();
        }
        // Toda solicitud de usuario entra primero a bandeja de Administrador.
        // El Admin es quien asigna después al Supervisor Técnico.
        $_POST['estado']            = 'ABIERTO';
        $_POST['solicitante_suser'] = suserIdActual();
        $_POST['tecnico']           = null;
        $_POST['fecha_cierre']      = null;
        $saved = saveTicket($_POST, null);
        auditLog('CREAR_TICKET','tickets',$saved,'Solicitud enviada a bandeja de Administrador para asignación de Supervisor Técnico');
        flash('success', 'Solicitud enviada al Administrador TI para designación del Supervisor Técnico.');
        if ($crearOtro) redirect('tickets.php?action=new');
        redirect('tickets.php');
    }

    // Supervisor: envía cambio para aprobación del Admin
    if (isSuper() && $pid) {
        $ticketActual = getTicketById($pid);
        if (!$ticketActual) {
            flash('danger','Ticket no encontrado.');
            redirect('tickets.php');
        }
        // Bloquear si ticket no es suyo
        if (!empty($ticketActual['asignado_por']) && (int)$ticketActual['asignado_a_suser'] !== suserIdActual()) {
            flash('warning', 'Solo puedes actualizar tickets que te estén asignados.');
            redirect('tickets.php?action=view&id='.$pid.'&swal_bloqueo_estado=1');
        }
        // ── REGLA PRINCIPAL: bloquear reenvío si ya hay un cambio PENDIENTE ──
        $cpExistente = getCambioPendienteByTicket($pid);
        if ($cpExistente) {
            flash('warning', 'Ya enviaste un cambio para aprobación (#'.$cpExistente['id'].'). Espera la respuesta del Administrador antes de enviar otro.');
            redirect('tickets.php?action=view&id='.$pid.'&swal_pendiente=1');
        }
        $cpId = guardarCambioPendiente('tickets', $pid, 'UPDATE', $_POST, 'Actualización ticket #'.$pid.' por supervisor');
        auditLog('EDITAR_TICKET_PENDIENTE','tickets',$pid,'Cambio pendiente #'.$cpId);
        redirect('tickets.php?action=view&id='.$pid.'&swal_enviado='.$cpId);
    }

    // Supervisor: no puede crear tickets nuevos
    if (isSuper() && !$pid) {
        flash('danger','Solo el Administrador puede crear tickets nuevos.');
        redirect('tickets.php');
    }

    // Admin: puede editar cualquier ticket en cualquier estado (incluyendo RESUELTO)
    // Auto-fecha cierre si pasa a RESUELTO sin fecha
    if (($_POST['estado'] ?? '') === 'RESUELTO' && empty($_POST['fecha_cierre']))
        $_POST['fecha_cierre'] = date('Y-m-d H:i:s');
    $saved = saveTicket($_POST, $pid);
    $auditDesc = $pid
        ? 'Editado por Admin (estado: '.($_POST['estado']??'').')'
        : 'Creado por Admin';
    auditLog($pid ? 'EDITAR_TICKET' : 'CREAR_TICKET', 'tickets', $saved, $auditDesc);
    flash('success', $pid ? 'Ticket actualizado.' : 'Ticket creado.');
    if (!$pid && $crearOtro) {
        redirect('tickets.php?action=new');
    }
    if (!$pid) {
        redirect('tickets.php');
    }
    redirect('tickets.php?action=view&id='.$saved);
}

if ($action==='delete' && $id) {
    requireRol(ROL_ADMIN);
    deleteTicket($id);
    auditLog('ELIMINAR_TICKET','tickets',$id);
    flash('warning','Ticket eliminado.');
    redirect('tickets.php');
}

$pageTitle = 'Tickets / Soporte';
include 'includes/header.php';

$equipos    = getEquipos();
$usuarios   = getUsuarios();
$supervisores = getSupervisores();
$q          = trim($_GET['q']??'');
$estado     = $_GET['estado']??'';
$prioridad  = $_GET['prioridad']??'';
$soloPendientesUsuario = isAdmin() && (int)($_GET['pendiente_usuario'] ?? 0) === 1;
$equipoFiltroId = (int)($_GET['equipo_id'] ?? 0);
$viewTab    = $_GET['view'] ?? 'tickets'; // tickets | aprobaciones

// Obtener aprobaciones pendientes solo para admin
$aprobacionesPendientes = isAdmin() ? getCambiosPendientes('PENDIENTE') : [];
?>
<div class="container-fluid px-3 py-3">

<?php if ($action==='list'): ?>
<!-- ══ LISTA ══ -->

<?php
// Obtener tickets según rol
if (isNormal())    $tickets = getMisTickets();
elseif (isSuper()) $tickets = getMisTicketsComoSupervisor();
else               $tickets = getTickets($q, $estado, $prioridad);

if ($soloPendientesUsuario && isAdmin()) {
  $tickets = array_values(array_filter(
    $tickets,
    fn($t) => ($t['estado'] ?? '') === 'ABIERTO'
      && empty($t['asignado_a_suser'])
      && !empty($t['solicitante_suser'])
  ));
}

if (isNormal()) {
  $ticketsTodosUsuario = $tickets;
}

if (isNormal() && $equipoFiltroId > 0) {
  $tickets = array_values(array_filter($tickets, fn($t) => (int)($t['equipo_id'] ?? 0) === $equipoFiltroId));
}
?>

<div class="page-header-ti d-flex justify-content-between align-items-center flex-wrap gap-3">
  <h5 class="mb-0">
    <i class="bi bi-ticket me-2"></i>
    <?= isNormal() ? 'Mis Solicitudes de Soporte' : (isSuper() ? 'Tickets Asignados' : 'Tickets de Soporte') ?>
  </h5>
  <div class="d-flex gap-2">
    <?php if (puede('exportar')): ?>
    <a href="exports/export_tickets_xls.php" class="btn btn-ti-primary btn-sm no-print"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <?php endif; ?>
    <?php if (puede('crear_ticket') || puede('crear_ticket_propio')): ?>
    <a href="tickets.php?action=new" class="btn btn-ti-accent btn-sm">
      <i class="bi bi-plus me-1"></i><?= isNormal() ? 'Nueva Solicitud' : 'Nuevo Ticket' ?>
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if (isAdmin()): ?>
<!-- Tabs para alternar vistas (solo admin) -->
<ul class="nav nav-tabs mb-3" id="viewTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $viewTab === 'tickets' ? 'active' : '' ?>" onclick="location.href='tickets.php?action=list&view=tickets'" type="button" role="tab">
      <i class="bi bi-ticket me-2"></i>Tickets <span class="badge bg-secondary ms-1"><?=count($tickets)?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link <?= $viewTab === 'aprobaciones' ? 'active' : '' ?>" onclick="location.href='tickets.php?action=list&view=aprobaciones'" type="button" role="tab">
      <i class="bi bi-check-circle me-2"></i>Aprobaciones Pendientes <span class="badge bg-danger ms-1"><?=count($aprobacionesPendientes)?></span>
    </button>
  </li>
</ul>
<?php endif; ?>

<?php if (!isNormal()): // Admin y supervisor ven filtros ?>
<div class="filter-card">
  <div class="card-body">
    <form id="form-search" method="GET" class="row g-3 align-items-end">
      <input type="hidden" name="action" value="list">
      <div class="col-sm-3">
        <label class="form-label text-muted small mb-1 no-print">Búsqueda</label>
        <input type="search" name="q" id="input-search" class="form-control" placeholder="Folio, equipo, técnico…" value="<?=h($q)?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label text-muted small mb-1 no-print">Estado</label>
        <select name="estado" class="form-select" onchange="this.form.submit()">
          <option value="">— Estado —</option>
          <?php foreach(['ABIERTO','EN_PROCESO','RESUELTO','CANCELADO'] as $e): ?><option <?=$estado===$e?'selected':''?>><?=$e?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label text-muted small mb-1 no-print">Prioridad</label>
        <select name="prioridad" class="form-select" onchange="this.form.submit()">
          <option value="">— Prioridad —</option>
          <?php foreach(['URGENTE','ALTA','MEDIA','BAJA'] as $p): ?><option <?=$prioridad===$p?'selected':''?>><?=$p?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (isAdmin()): ?>
      <div class="col-sm-3">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="filtro-pendiente-usuario" name="pendiente_usuario" value="1" <?=$soloPendientesUsuario?'checked':''?> onchange="this.form.submit()">
          <label class="form-check-label small" for="filtro-pendiente-usuario">
            Solo solicitudes de usuario pendientes
          </label>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary no-print" style="padding: 10px 16px;">
          <i class="bi bi-search"></i>
        </button>
        <?php if($q||$estado||$prioridad||$soloPendientesUsuario): ?>
        <a href="tickets.php" class="btn btn-outline-secondary no-print" style="padding: 10px 16px;">
          <i class="bi bi-x"></i>
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if (isAdmin() && $viewTab === 'aprobaciones'): ?>
<!-- Vista de Aprobaciones Pendientes (solo admin) -->
<div class="table-card-ti">
  <div class="card-header-ti">
    <i class="bi bi-check-circle me-2"></i>Aprobaciones Pendientes
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
<table class="table table-ti mb-0">
<thead><tr><th>#</th><th>Ticket</th><th>Supervisor</th><th>Campo</th><th>Valor Anterior</th><th>Valor Nuevo</th><th>Fecha</th><th>Acciones</th></tr></thead>
<tbody>
<?php foreach($aprobacionesPendientes as $ap): ?>
<tr>
  <td><?=$ap['id']?></td>
  <td><a href="tickets.php?action=view&id=<?=$ap['registro_id']?>" class="text-primary">#<?=$ap['registro_id']?></a></td>
  <td class="small"><?=h($ap['supervisor_nombre']??'')?></td>
  <td class="small"><strong><?=h($ap['campo']??'')?></strong></td>
  <td class="small text-muted"><?=h($ap['valor_anterior']??'')?></td>
  <td class="small text-primary"><?=h($ap['valor_nuevo']??'')?></td>
  <td class="small"><?=h(substr($ap['created_at']??'',0,16))?></td>
  <td class="no-print">
    <a href="tickets.php?action=aprobar_cambio&id=<?=$ap['registro_id']?>&cp=<?=$ap['id']?>" class="btn btn-xs btn-success me-1" title="Aprobar"><i class="bi bi-check"></i></a>
    <a href="tickets.php?action=rechazar_cambio&id=<?=$ap['registro_id']?>&cp=<?=$ap['id']?>" class="btn btn-xs btn-danger" title="Rechazar"><i class="bi bi-x"></i></a>
  </td>
</tr>
<?php endforeach; if(empty($aprobacionesPendientes)): ?><tr><td colspan="7" class="text-center text-muted py-4">Sin aprobaciones pendientes</td></tr><?php endif; ?>
</tbody></table></div></div></div>
<?php else: ?>

<?php
$misEquiposAsignados = [];
if (isNormal()) {
  foreach (($ticketsTodosUsuario ?? []) as $tk) {
    $eqId = (int)($tk['equipo_id'] ?? 0);
    if (!$eqId) continue;
    if (!isset($misEquiposAsignados[$eqId])) {
      $misEquiposAsignados[$eqId] = [
        'equipo_id' => $eqId,
        'equipo_folio' => $tk['equipo_folio'] ?? '',
        'equipo_marca' => $tk['equipo_marca'] ?? '',
        'total' => 0,
        'abierto' => 0,
        'en_proceso' => 0,
        'resuelto' => 0,
        'cancelado' => 0,
      ];
    }
    $misEquiposAsignados[$eqId]['total']++;
    if (($tk['estado'] ?? '') === 'ABIERTO') $misEquiposAsignados[$eqId]['abierto']++;
    if (($tk['estado'] ?? '') === 'EN_PROCESO') $misEquiposAsignados[$eqId]['en_proceso']++;
    if (($tk['estado'] ?? '') === 'RESUELTO') $misEquiposAsignados[$eqId]['resuelto']++;
    if (($tk['estado'] ?? '') === 'CANCELADO') $misEquiposAsignados[$eqId]['cancelado']++;
  }
}
?>

<?php if (isNormal() && empty($tickets)): ?>
<div class="card mb-3">
  <div class="card-header-ti d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="bi bi-pc-display me-2"></i>Mis equipos asignados</span>
    <?php if ($equipoFiltroId > 0): ?>
      <a href="tickets.php" class="btn btn-sm btn-outline-light py-0">Ver todo mi historial</a>
    <?php endif; ?>
  </div>
  <div class="card-body py-2">
    <div class="row g-2">
      <?php foreach ($misEquiposAsignados as $eq): ?>
      <div class="col-12 col-md-6 col-xl-4">
        <div class="border rounded p-2 h-100">
          <div class="fw-semibold small mb-2"><?=h(trim(($eq['equipo_folio'] ?? '').' '.($eq['equipo_marca'] ?? '')))?></div>
          <div class="d-flex flex-wrap gap-1 mb-2">
            <span class="badge bg-secondary">Total: <?=$eq['total']?></span>
            <span class="badge badge-abierto">Abiertos: <?=$eq['abierto']?></span>
            <span class="badge badge-en_proceso">En proceso: <?=$eq['en_proceso']?></span>
            <span class="badge badge-resuelto">Resueltos: <?=$eq['resuelto']?></span>
          </div>
          <a href="tickets.php?equipo_id=<?=$eq['equipo_id']?>" class="btn btn-xs btn-outline-primary">
            <i class="bi bi-clock-history me-1"></i>Historial y estatus
          </a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (isNormal() && $equipoFiltroId > 0): ?>
<div class="alert alert-light border d-flex justify-content-between align-items-center py-2 mb-3">
  <span class="small text-muted">
    Mostrando historial del equipo seleccionado.
  </span>
  <a href="tickets.php" class="btn btn-sm btn-outline-secondary">Quitar filtro</a>
</div>
<?php endif; ?>

<?php if (isNormal() && empty($tickets)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="bi bi-ticket fs-1 d-block mb-2 opacity-25"></i>
  No tienes solicitudes activas.
  <br><a href="tickets.php?action=new" class="btn btn-ti-accent btn-sm mt-3"><i class="bi bi-plus me-1"></i>Nueva Solicitud</a>
</div></div>
<?php else: ?>
<div class="table-card-ti">
  <div class="card-body p-0">
    <div class="table-responsive">
<table class="table table-ti mb-0">
  <thead><tr>
    <th>Folio</th><th>Equipo</th>
    <?php if (!isNormal()): ?><th>Usuario</th><?php endif; ?>
    <th>Tipo</th><th>Descripción</th>
    <?php if (!isSuper()): ?><th>Técnico / Asignado</th><?php endif; ?>
    <th>Prioridad</th><th>Estado</th><th>Apertura</th>
    <th class="no-print">Acciones</th>
  </tr></thead>
  <tbody>
  <?php foreach($tickets as $t):
    $prioC=['URGENTE'=>'badge-urgente','ALTA'=>'badge-alta','MEDIA'=>'badge-media','BAJA'=>'badge-baja-p'][$t['prioridad']]??'bg-secondary';
    $estC =['ABIERTO'=>'badge-abierto','EN_PROCESO'=>'badge-en_proceso','RESUELTO'=>'badge-resuelto','CANCELADO'=>'badge-cancelado'][$t['estado']]??'bg-secondary';
    // Resaltar en rojo si es ABIERTO y no tiene supervisor asignado
    $sinAsignar = ($t['estado']==='ABIERTO') && empty($t['asignado_a_suser']);
    /*$resuelto = ($t['estado']=== 'RESUELTO') && empty($t['solucion']);*/
  ?>
  <tr style="cursor:pointer<?=$sinAsignar?' border-left:3px solid #dc3545;':''?>"
      onclick="location.href='tickets.php?action=view&id=<?=$t['id']?>'"
      <?=$sinAsignar?'class="table-danger"':''?>>
    <td><code class="text-primary"><?=h($t['folio_ticket'])?></code></td>
    <td class="small"><?=h($t['equipo_folio'].' '.($t['equipo_marca']??''))?></td>
    <?php if (!isNormal()): ?><td class="small"><?=h(trim($t['usuario_nombre']??''))?></td><?php endif; ?>
    <td class="small"><?=h(str_replace('_',' ',$t['tipo_servicio']))?></td>
    <td class="small text-muted"><?=h(mb_substr($t['descripcion']??'',0,50))?></td>
    <?php if (!isSuper()): ?>
    <td class="small">
      <?php if ($t['supervisor_asignado_nombre']??false): ?>
        <span class="badge bg-info text-dark" style="font-size:.68rem;">
          <i class="bi bi-person-check me-1"></i><?=h($t['supervisor_asignado_nombre'])?>
        </span>
      <?php elseif ($t['tecnico']??false): ?>
        <?=h($t['tecnico'])?>
      <?php else: ?>
        <?php if (isAdmin() && $t['estado']==='ABIERTO'): ?>
        <span class="badge bg-danger" style="font-size:.65rem;" title="Sin asignar">
          <i class="bi bi-exclamation-triangle me-1"></i>Sin asignar
        </span>
        <?php else: ?>
        <span class="text-muted small">—</span>
        <?php endif; ?>
      <?php endif; ?>
      <?php endif; ?>
    </td>
    <td><span class="badge <?=$prioC?>"><?=h($t['prioridad'])?></span></td>
    <td><span class="badge <?=$estC?>"><?=h(str_replace('_',' ',$t['estado']))?></span></td>
    <td class="small"><?=h(substr($t['fecha_apertura']??'',0,10))?></td>
    <td class="no-print" onclick="event.stopPropagation()">
      <a href="tickets.php?action=view&id=<?=$t['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-eye"></i></a>
      <?php
        $estadoFinal = in_array($t['estado'], ['RESUELTO', 'CANCELADO']);
      ?>
      <?php if (isAdmin()): ?>
        <?php if ($estadoFinal): ?>
        <a href="tickets.php?action=edit&id=<?=$t['id']?>"
           class="btn btn-xs btn-outline-secondary me-1"
           title="Editar ticket <?=strtolower($t['estado'])?> (solo Admin)">
          <i class="bi bi-pencil-square"></i>
        </a>
        <?php else: ?>
        <a href="tickets.php?action=edit&id=<?=$t['id']?>"
           class="btn btn-xs btn-outline-secondary me-1"
           title="Editar ticket">
          <i class="bi bi-pencil"></i>
        </a>
        <?php endif; ?>
      <?php elseif (isSuper() && !$estadoFinal): ?>
        <a href="tickets.php?action=edit&id=<?=$t['id']?>"
           class="btn btn-xs btn-outline-secondary me-1"
           title="Editar ticket">
          <i class="bi bi-pencil"></i>
        </a>
      <?php endif; ?>
      <?php if (isAdmin() && in_array($t['estado'],['ABIERTO','EN_PROCESO'])): ?>
      <a href="tickets.php?action=view&id=<?=$t['id']?>#asignar" class="btn btn-xs btn-outline-warning me-1" title="Asignar supervisor"><i class="bi bi-person-check"></i></a>
      <?php endif; ?>
      <?php if (puede('eliminar_ticket')): ?>
      <button onclick="confirmDelete('tickets.php?action=delete&id=<?=$t['id']?>','<?=addslashes($t['folio_ticket'])?>')" class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i></button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if(empty($tickets)): ?><tr><td colspan="10" class="text-center text-muted py-4">Sin tickets</td></tr><?php endif; ?>
  </tbody></table>
</div></div></div>
<?php endif; ?>
<?php endif; ?>

<?php elseif(in_array($action,['new','edit'])): ?>
<!-- ══ FORM ══ -->
<?php
$t = $action==='edit' && $id ? getTicketById($id) : [];
$isEdit = !empty($t);
$equipoIdPre = (int)($_GET['equipo_id']??$t['equipo_id']??0);

// Usuario normal: solo puede crear, no editar
if (isNormal() && $isEdit) { flash('danger','Sin permiso.'); redirect('tickets.php'); }
// Supervisor no puede crear nuevos tickets, solo editar los asignados
if (isSuper() && !$isEdit) { flash('danger','Solo el Administrador crea tickets.'); redirect('tickets.php'); }
// ── Reglas de edición por rol ─────────────────────────────────────────────
// ADMIN   : puede editar cualquier ticket en cualquier estado (ABIERTO, EN_PROCESO, RESUELTO, CANCELADO)
// SUPER   : solo sus tickets asignados y únicamente si están en ABIERTO o EN_PROCESO
// NORMAL  : sin acceso a edición (ya bloqueado arriba)
if (isSuper() && $isEdit) {
    if ((int)($t['asignado_a_suser'] ?? 0) !== suserIdActual()) {
        flash('danger','Solo puedes editar tickets que te estén asignados.');
        redirect('tickets.php');
    }
    if (in_array($t['estado'] ?? '', ['RESUELTO', 'CANCELADO'])) {
        flash('warning','Los tickets RESUELTOS o CANCELADOS solo pueden ser editados por el Administrador.');
        redirect('tickets.php?action=view&id='.$id);
    }
}
// Admin puede editar cualquier ticket en cualquier estado

// Para usuario normal: solo sus equipos
$usuariosJerarquia = [];
if (isNormal()) {
    if (puedeSolicitarTicketsJerarquia()) {
        $usuariosJerarquia = getUsuariosJerarquiaSolicitante();
        $equipos = getEquiposJerarquiaSolicitante();
    } else { $equipos = []; }
    if (empty($equipos)) {
        $usuarioId = usuarioIdActual();
        if ($usuarioId) {
            $s = getDB()->prepare("SELECT e.*,t.nombre tipo_nombre FROM equipos e LEFT JOIN tipos_equipo t ON t.id=e.tipo_id WHERE e.usuario_id=? AND e.activo=1");
            $s->execute([$usuarioId]);
            $equipos = $s->fetchAll();
        }
    }
}
?>
<div class="row justify-content-center">
<div class="col-xl-8">
  <div class="form-card-ti">
    <div class="card-header-ti">
      <i class="bi bi-ticket me-2"></i>
      <?php if (isNormal()): ?>
        Nueva Solicitud de Soporte
      <?php elseif ($isEdit): ?>
        Editar Ticket — <?=h($t['folio_ticket'])?>
        <?php if (isSuper()): ?>
        <span class="badge bg-warning text-dark ms-2 small">Los cambios requieren aprobación del Administrador</span>
        <?php endif; ?>
      <?php else: ?>
        Nuevo Ticket
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="POST" action="tickets.php">
      <?php if (!isNormal() || (isNormal() && !empty($usuariosJerarquia))): ?>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Usuario Solicitante</label>
              <select name="usuario_id" id="usuario_solicitante" class="form-select">
                <option value="">— Seleccionar —</option>
                <?php foreach((isNormal() ? $usuariosJerarquia : $usuarios) as $u): ?>
                <option value="<?=$u['id']?>" <?=(int)($t['usuario_id']??0)==(int)$u['id']?'selected':''?>><?=h($u['nombre'].' '.($u['apellidos']??''))?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <?php endif; ?>
        <?php if($isEdit): ?><input type="hidden" name="id" value="<?=$t['id']?>"><?php endif; ?>
        <input type="hidden" name="crear_otro_ticket" id="crear_otro_ticket" value="0">
        <div class="row g-3">
          <div class="col-md-<?= isNormal() ? '12' : '6' ?>">
            <label class="form-label">Equipo *</label>
            <select name="equipo_id" class="form-select" required>
              <option value="">— Seleccionar equipo —</option>
              <?php foreach($equipos as $e): ?>
              <option value="<?=$e['id']?>" <?=(int)($t['equipo_id']??$equipoIdPre)==(int)$e['id']?'selected':''?>>
                <?=h($e['folio'].' — '.($e['tipo_nombre']??'').' '.($e['marca']??'').' '.($e['modelo']??''))?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-md-<?= isNormal()?'12':'4' ?>">
            <label class="form-label">Tipo de Servicio</label>
            <select name="tipo_servicio" class="form-select" <?= isNormal()?'':'readonly' ?>>
              <?php foreach(['MANTENIMIENTO_PREVENTIVO','MANTENIMIENTO_CORRECTIVO','SOPORTE','INSTALACION','OTRO'] as $ts): ?>
              <option value="<?=$ts?>" <?=($t['tipo_servicio']??'SOPORTE')===$ts?'selected':''?>><?=h(str_replace('_',' ',$ts))?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Prioridad</label>
            <select name="prioridad" class="form-select">
              <?php foreach(['URGENTE','ALTA','MEDIA','BAJA'] as $p): ?><option value="<?=$p?>" <?=($t['prioridad']??'MEDIA')===$p?'selected':''?>><?=$p?></option><?php endforeach; ?>
            </select>
          </div>

          <?php if (isAdmin()): ?>
          <div class="col-md-4">
            <label class="form-label">Estado</label>
            <select name="estado" class="form-select">
              <?php foreach(['ABIERTO','EN_PROCESO','RESUELTO','CANCELADO'] as $es): ?><option value="<?=$es?>" <?=($t['estado']??'ABIERTO')===$es?'selected':''?>><?=h(str_replace('_',' ',$es))?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Técnico Asignado</label>
            <input type="text" name="tecnico" class="form-control" value="<?=h($t['tecnico']??'')?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Fecha Cierre</label>
            <input type="datetime-local" name="fecha_cierre" class="form-control" value="<?=h($t['fecha_cierre']??'')?>">
          </div>
          <?php elseif(isSuper()): ?>
          <div class="col-md-4">
            <label class="form-label">Estado <span class="text-muted small">(sujeto a aprobación)</span></label>
            <select name="estado" class="form-select">
              <?php foreach(['EN_PROCESO','RESUELTO'] as $es): ?><option value="<?=$es?>" <?=($t['estado']??'EN_PROCESO')===$es?'selected':''?>><?=h(str_replace('_',' ',$es))?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <div class="col-12">
            <label class="form-label">
              <?= isNormal() ? 'Descripción del problema *' : 'Descripción del Problema *' ?>
            </label>
            <textarea name="descripcion" class="form-control" rows="4" required><?=h($t['descripcion']??'')?></textarea>
          </div>
        </div>
        <?php if (isNormal()): ?>
        <div class="alert alert-ti-warning d-flex align-items-center mt-3" role="alert">
          <i class="bi bi-info-circle me-3"></i>
          <div>
            <?php if (!empty($usuariosJerarquia)): ?>
            Como nivel jerárquico, puedes solicitar tickets para usuarios/equipos de tu área. La solicitud será revisada por el Administrador TI.
            <?php else: ?>
            Tu solicitud será revisada por el Administrador TI, quien la asignará al Supervisor Técnico correspondiente.
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <div class="d-flex gap-2 mt-4">
          <button type="submit" class="btn btn-ti-save">
            <i class="bi bi-check-lg me-1"></i>
            <?= isNormal() ? 'Enviar Solicitud' : ($isEdit ? 'Guardar' : 'Crear Ticket') ?>
          </button>
          <a href="tickets.php" class="btn btn-outline-secondary">Cancelar</a>
        </div>
      </form>
    </div>
  </div>
</div></div>
<?php if (isNormal() && !empty($usuariosJerarquia)): ?>
<script>
(function(){
  const selUsuario = document.getElementById('usuario_solicitante');
  const selEquipo = document.querySelector('select[name="equipo_id"]');
  if (!selUsuario || !selEquipo) return;
  const mapEquipoUsuario = {
    <?php foreach ($equipos as $e): ?>
    "<?=$e['id']?>": "<?= (int)($e['usuario_id'] ?? 0) ?>",
    <?php endforeach; ?>
  };
  function filtrarEquiposPorUsuario() {
    const uid = selUsuario.value || '';
    Array.from(selEquipo.options).forEach((opt, idx) => {
      if (idx === 0) return;
      const euid = mapEquipoUsuario[opt.value] || '';
      opt.hidden = uid !== '' && euid !== uid;
    });
    if (selEquipo.value && (mapEquipoUsuario[selEquipo.value] || '') !== uid) {
      selEquipo.value = '';
    }
  }
  selEquipo.addEventListener('change', () => {
    const eq = selEquipo.value || '';
    const uid = eq ? (mapEquipoUsuario[eq] || '') : '';
    if (uid) selUsuario.value = uid;
    filtrarEquiposPorUsuario();
  });
  selUsuario.addEventListener('change', filtrarEquiposPorUsuario);
  filtrarEquiposPorUsuario();
})();
</script>
<?php endif; ?>
<?php if (!$isEdit): ?>
<script>
(function(){
  const formTicket = document.querySelector('form[action="tickets.php"]');
  const crearOtroTicket = document.getElementById('crear_otro_ticket');
  if (!formTicket || !crearOtroTicket) return;
  formTicket.addEventListener('submit', async function (e) {
    e.preventDefault();
    const result = await Swal.fire({
      icon: 'question',
      title: 'Enviar ticket',
      text: '¿Qué deseas hacer después de enviar el ticket?',
      showCancelButton: true,
      showDenyButton: true,
      confirmButtonText: 'Enviar y crear otro',
      denyButtonText: 'Enviar y volver al inicio',
      cancelButtonText: 'Cancelar',
      confirmButtonColor: '#1a3a5c',
      denyButtonColor: '#0d6efd'
    });
    if (result.isConfirmed) {
      crearOtroTicket.value = '1';
      formTicket.submit();
    } else if (result.isDenied) {
      crearOtroTicket.value = '0';
      formTicket.submit();
    }
  });
})();
</script>
<?php endif; ?>

<?php elseif($action==='view' && $id): ?>
<!-- ══ VISTA ══ -->
<?php
$t = getTicketById($id);
if(!$t){ flash('danger','No encontrado.'); redirect('tickets.php'); }
// Usuario normal: solo sus propios tickets
if (isNormal() && (int)$t['solicitante_suser'] !== suserIdActual()) {
    flash('danger','Sin permiso para ver este ticket.'); redirect('tickets.php');
}
$estadoBloqueadoSupervisor = isSuper() && !empty($t['asignado_por']) && (int)$t['asignado_a_suser'] !== suserIdActual();

// ── Cambio pendiente / resuelto para este ticket ─────────────────────────
$cambioPendiente      = getCambioPendienteByTicket($id);
$ultimoCambioResuelto = getUltimoCambioResueltoByTicket($id);

// El supervisor no puede reenviar si ya hay un pendiente o el último fue APROBADO
$superBloqueadoEnvio = isSuper() && (
    $cambioPendiente !== false
    || ($ultimoCambioResuelto && $ultimoCambioResuelto['estado'] === 'APROBADO')
);

// ── Parámetros GET para SweetAlert ───────────────────────────────────────
$swalPendiente        = isSuper()  && (int)($_GET['swal_pendiente']  ?? 0) === 1;
$swalEnviado          = (int)($_GET['swal_enviado']   ?? 0);
$swalAprobado         = (int)($_GET['swal_aprobado']  ?? 0);
$swalRechazado        = (int)($_GET['swal_rechazado'] ?? 0);
$mostrarSwalBloqueoEstado = isSuper() && (int)($_GET['swal_bloqueo_estado'] ?? 0) === 1;

// Notificación de resolución procesada (viene de resolverCambio vía $_SESSION)
$notifCambio = $_SESSION['notif_cambio'] ?? null;
unset($_SESSION['notif_cambio']);

$prioC=['URGENTE'=>'badge-urgente','ALTA'=>'badge-alta','MEDIA'=>'badge-media','BAJA'=>'badge-baja-p'][$t['prioridad']]??'bg-secondary';
$estC =['ABIERTO'=>'badge-abierto','EN_PROCESO'=>'badge-en_proceso','RESUELTO'=>'badge-resuelto','CANCELADO'=>'badge-cancelado'][$t['estado']]??'bg-secondary';
$ticketResuelto  = ($t['estado'] ?? '') === 'RESUELTO';
$ticketCancelado = ($t['estado'] ?? '') === 'CANCELADO';
$ticketEstadoFinalVista = $ticketResuelto || $ticketCancelado;
?>
<div class="page-header-ti d-flex justify-content-between align-items-center flex-wrap gap-3">
  <div>
    <h5 class="mb-1"><code><?=h($t['folio_ticket'])?></code></h5>
    <span class="badge <?=$prioC?> me-1"><?=h($t['prioridad'])?></span>
    <span class="badge <?=$estC?>"><?=h(str_replace('_',' ',$t['estado']))?></span>
    <?php if (empty($t['asignado_a_suser']) && $t['estado']==='ABIERTO'): ?>
    <span class="badge bg-danger ms-1"><i class="bi bi-exclamation-triangle me-1"></i>Sin supervisor asignado</span>
    <?php elseif ($t['supervisor_asignado_nombre']??false): ?>
    <span class="badge bg-info text-dark ms-1"><i class="bi bi-person-check me-1"></i><?=h($t['supervisor_asignado_nombre'])?></span>
    <?php endif; ?>
  </div>
  <div class="d-flex gap-2 no-print flex-wrap">
    <?php
      $ticketEstadoFinal = in_array($t['estado'] ?? '', ['RESUELTO', 'CANCELADO']);
    ?>
    <?php if (isAdmin()): ?>
      <?php if ($ticketEstadoFinal): ?>
      <a href="tickets.php?action=edit&id=<?=$id?>"
         class="btn btn-sm btn-outline-secondary"
         title="Editar ticket <?=strtolower($t['estado'])?> (solo Admin)">
        <i class="bi bi-pencil-square me-1"></i>Editar
      </a>
      <?php else: ?>
      <a href="tickets.php?action=edit&id=<?=$id?>"
         class="btn btn-sm btn-outline-primary">
        <i class="bi bi-pencil me-1"></i>Editar
      </a>
      <?php endif; ?>
    <?php elseif (isSuper() && !$ticketEstadoFinal): ?>
    <a href="tickets.php?action=edit&id=<?=$id?>"
       class="btn btn-sm btn-outline-primary">
      <i class="bi bi-pencil me-1"></i>Editar
    </a>
    <?php endif; ?>
    <?php if (isAdmin() && in_array($t['estado'],['ABIERTO','EN_PROCESO'])): ?>
    <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#modalAsignar" id="btn-asignar">
      <i class="bi bi-person-check me-1"></i>
      <?= empty($t['asignado_a_suser']) ? 'Asignar a Supervisor' : 'Reasignar Supervisor' ?>
    </button>
    <?php endif; ?>
    <?php if (isAdmin() || isSuper()): ?>
    <?php if ($ticketResuelto): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="No disponible para tickets resueltos">
      <i class="bi bi-tools me-1"></i>Generar Mantenimiento
    </button>
    <?php else: ?>
    <a href="mantenimiento.php?action=new&equipo_id=<?=$t['equipo_id']?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-tools me-1"></i>Generar Mantenimiento</a>
    <?php endif; ?>
    <?php endif; ?>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Imprimir</button>
  </div>
</div>

<!-- Alerta si no está asignado -->
<?php if (isAdmin() && empty($t['asignado_a_suser']) && $t['estado']==='ABIERTO'): ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3 no-print">
  <i class="bi bi-exclamation-triangle-fill fs-5"></i>
  <div>
    <strong>Ticket sin supervisor asignado.</strong>
    <a href="#" class="ms-2 alert-link" data-bs-toggle="modal" data-bs-target="#modalAsignar">
      Asignar ahora →
    </a>
  </div>
</div>
<?php endif; ?>
<?php if ($estadoBloqueadoSupervisor): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3 no-print">
  <i class="bi bi-shield-lock-fill fs-5"></i>
  <div>
    <strong>Estado bloqueado para Supervisor.</strong>
    Este ticket ya fue validado por el Administrador; cualquier cambio de estado debe realizarlo el Administrador.
  </div>
</div>
<?php endif; ?>
<?php if (isNormal() && !empty($t['asignado_a_suser'])): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-3 no-print">
  <i class="bi bi-bell-fill fs-5"></i>
  <div>
    Tu solicitud ya fue validada por el Administrador y asignada al Supervisor Técnico
    <strong><?=h($t['supervisor_asignado_nombre'] ?? 'correspondiente')?></strong>.
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-md-6">
    <div class="card">
      <div class="card-header-ti"><i class="bi bi-info-circle me-2"></i>Detalles del Ticket</div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <?php
          $info=[
            ['Folio',              '<code>'.$t['folio_ticket'].'</code>'],
            ['Equipo',             h($t['equipo_folio'].' — '.($t['equipo_marca']??'').' '.($t['equipo_modelo']??''))],
            ['Usuario',            h(trim($t['usuario_nombre']??''))],
            ['Tipo',               h(str_replace('_',' ',$t['tipo_servicio']))],
            ['Prioridad',          '<span class="badge '.$prioC.'">'.h($t['prioridad']).'</span>'],
            ['Estado',             '<span class="badge '.$estC.'">'.h(str_replace('_',' ',$t['estado'])).'</span>'],
            ['Técnico / Asignado', $t['supervisor_asignado_nombre']
                ? '<span class="badge bg-info text-dark"><i class="bi bi-person-check me-1"></i>'.h($t['supervisor_asignado_nombre']).'</span>'
                : (h($t['tecnico']??'') ?: '<span class="text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>Sin asignar</span>')],
            ['Asignado por',       h($t['asignado_por'] ? (getSystemUserById((int)$t['asignado_por'])['nombre_completo']??'') : '')],
            ['Solicitado por',     h($t['solicitante_nombre']??'')],
            ['Apertura',           h(substr($t['fecha_apertura']??'',0,16))],
            ['Cierre',             h(substr($t['fecha_cierre']??'',0,16))],
          ];
          foreach($info as [$k,$v]): if(!$v||$v==='') continue; ?>
          <tr><th class="small text-muted ps-0" style="width:38%"><?=$k?></th><td class="small"><?=$v?></td></tr>
          <?php endforeach; ?>
        </table>
        <hr>
        <div class="small"><strong>Descripción:</strong><p class="text-muted mt-1 mb-0"><?=nl2br(h($t['descripcion']??''))?></p></div>
      </div>
    </div>
  </div>

<?php if (isSuper() && !$estadoBloqueadoSupervisor && !$ticketEstadoFinalVista): ?>
  <div class="col-md-6">
    <div class="card">
      <div class="card-header-ti d-flex justify-content-between align-items-center flex-wrap gap-1">
        <span><i class="bi bi-tools me-2"></i>Actualizar Estado</span>
        <span class="badge bg-warning text-dark small">Requiere aprobación del Administrador</span>
      </div>
      <div class="card-body">

        <?php if ($cambioPendiente): ?>
        <!-- ── Estado: cambio enviado, esperando al Admin ── -->
        <div class="alert alert-warning mb-3">
          <div class="d-flex align-items-center gap-2 mb-2">
            <div class="spinner-border spinner-border-sm text-warning" role="status"></div>
            <strong>Cambio enviado — pendiente de aprobación</strong>
          </div>
          <div class="small text-muted">
            Enviado el <?=h(substr($cambioPendiente['created_at'],0,16))?><br>
            <span class="badge bg-secondary mt-1">Ref. #<?=$cambioPendiente['id']?></span>
          </div>
          <hr class="my-2">
          <div class="small">
            <strong>Estado solicitado:</strong>
            <?php
              $datosEnviados = json_decode($cambioPendiente['datos_json'], true);
              $estadoSol = $datosEnviados['estado'] ?? '—';
            ?>
            <span class="badge <?=['EN_PROCESO'=>'badge-en_proceso','RESUELTO'=>'badge-resuelto'][$estadoSol]??'bg-secondary'?>">
              <?=h(str_replace('_',' ',$estadoSol))?>
            </span>
          </div>
          <div class="mt-2 small text-muted fst-italic">
            <i class="bi bi-lock me-1"></i>
            No puedes enviar otro cambio hasta que el Administrador resuelva este.
          </div>
        </div>

        <?php elseif ($ultimoCambioResuelto && $ultimoCambioResuelto['estado'] === 'APROBADO'): ?>
        <!-- ── Estado: aprobado — solo lectura ── -->
        <div class="alert alert-success mb-0">
          <i class="bi bi-check-circle-fill me-2"></i>
          <strong>Tu último cambio fue APROBADO</strong>
          <?php if ($ultimoCambioResuelto['admin_nota']): ?>
          <p class="small mb-0 mt-1 text-muted"><?=nl2br(h($ultimoCambioResuelto['admin_nota']))?></p>
          <?php endif; ?>
          <div class="small text-muted mt-1">
            Aprobado por <?=h($ultimoCambioResuelto['admin_nombre']??'Admin')?> el
            <?=h(substr($ultimoCambioResuelto['fecha_resolucion'],0,16))?>
          </div>
        </div>

        <?php elseif ($ultimoCambioResuelto && $ultimoCambioResuelto['estado'] === 'RECHAZADO'): ?>
        <!-- ── Estado: rechazado — puede reenviar ── -->
        <div class="alert alert-danger py-2 small mb-3">
          <i class="bi bi-x-circle-fill me-1"></i>
          <strong>Tu último cambio fue RECHAZADO</strong>
          <?php if ($ultimoCambioResuelto['admin_nota']): ?>
          <p class="mb-0 mt-1 fst-italic">"<?=h($ultimoCambioResuelto['admin_nota'])?>"</p>
          <?php endif; ?>
        </div>
        <!-- Mostrar formulario para reenviar -->
        <div class="alert alert-info py-2 small mb-3">
          <i class="bi bi-arrow-repeat me-1"></i>
          Puedes corregir y reenviar el cambio para nueva aprobación.
        </div>
        <?php include_once(__DIR__.'/includes/_form_supervisor_estado.php'); // ver abajo — inline: ?>
        <form method="POST" action="tickets.php" id="formActualizarEstado" onsubmit="confirmarEnvio(event)">
          <input type="hidden" name="id"            value="<?=$id?>">
          <input type="hidden" name="equipo_id"     value="<?=$t['equipo_id']?>">
          <input type="hidden" name="usuario_id"    value="<?=$t['usuario_id']?>">
          <input type="hidden" name="tipo_servicio" value="<?=$t['tipo_servicio']?>">
          <input type="hidden" name="prioridad"     value="<?=$t['prioridad']?>">
          <input type="hidden" name="descripcion"   value="<?=h($t['descripcion']??'')?>">
          <div class="mb-3">
            <label class="form-label fw-semibold">Nuevo Estado</label>
            <select name="estado" class="form-select">
              <?php foreach(['EN_PROCESO','RESUELTO'] as $es): ?>
              <option value="<?=$es?>" <?=($ultimoCambioResuelto['datos_json']??null) && (json_decode($ultimoCambioResuelto['datos_json'],true)['estado']??'')===$es?'selected':''?>>
                <?=h(str_replace('_',' ',$es))?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Observaciones del Técnico <span class="text-danger">*</span></label>
            <textarea name="tecnico_notas" id="tecnico_notas" class="form-control" rows="3"
                      placeholder="Describe las acciones realizadas…" required></textarea>
          </div>
          <button type="submit" class="btn btn-warning w-100">
            <i class="bi bi-arrow-repeat me-1"></i>Reenviar para Aprobación
          </button>
        </form>

        <?php else: ?>
        <!-- ── Estado: sin cambios previos — primer envío ── -->
        <div class="alert alert-warning py-2 small mb-3">
          <i class="bi bi-info-circle me-1"></i>
          Tus cambios serán enviados al Administrador para aprobación antes de aplicarse.
        </div>
        <form method="POST" action="tickets.php" id="formActualizarEstado" onsubmit="confirmarEnvio(event)">
          <input type="hidden" name="id"            value="<?=$id?>">
          <input type="hidden" name="equipo_id"     value="<?=$t['equipo_id']?>">
          <input type="hidden" name="usuario_id"    value="<?=$t['usuario_id']?>">
          <input type="hidden" name="tipo_servicio" value="<?=$t['tipo_servicio']?>">
          <input type="hidden" name="prioridad"     value="<?=$t['prioridad']?>">
          <input type="hidden" name="descripcion"   value="<?=h($t['descripcion']??'')?>">
          <div class="mb-3">
            <label class="form-label fw-semibold">Nuevo Estado</label>
            <select name="estado" class="form-select">
              <?php foreach(['EN_PROCESO','RESUELTO'] as $es): ?>
              <option value="<?=$es?>" <?=$t['estado']===$es?'selected':''?>>
                <?=h(str_replace('_',' ',$es))?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Observaciones del Técnico <span class="text-danger">*</span></label>
            <textarea name="tecnico_notas" id="tecnico_notas" class="form-control" rows="3"
                      placeholder="Describe las acciones realizadas…" required></textarea>
          </div>
          <button type="submit" class="btn btn-warning w-100">
            <i class="bi bi-send me-1"></i>Enviar para Aprobación
          </button>
        </form>
        <?php endif; ?>

      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (isAdmin() && $cambioPendiente): ?>
<!-- ── Panel del Admin: aprobar o rechazar cambio del Supervisor ── -->
  <div class="col-md-6">
    <div class="card border-warning">
      <div class="card-header-ti d-flex justify-content-between align-items-center"
           style="background:linear-gradient(135deg,#856404,#b8860b);color:#fff;">
        <span><i class="bi bi-bell-fill me-2"></i>Cambio Pendiente de Aprobación</span>
        <span class="badge bg-light text-dark">Ref. #<?=$cambioPendiente['id']?></span>
      </div>
      <div class="card-body">
        <div class="mb-3 small">
          <strong>Supervisor:</strong> <?=h($cambioPendiente['supervisor_nombre']??'')?>
          <span class="text-muted ms-2"><?=h(substr($cambioPendiente['created_at'],0,16))?></span>
        </div>
        <?php
          $datosCP    = json_decode($cambioPendiente['datos_json'], true);
          $estCPSol   = $datosCP['estado']        ?? '—';
          $notasCPSol = $datosCP['tecnico_notas'] ?? '';
          $badgeCPSol = ['EN_PROCESO'=>'badge-en_proceso','RESUELTO'=>'badge-resuelto'][$estCPSol] ?? 'bg-secondary';
        ?>
        <table class="table table-sm mb-3" style="font-size:.82rem;">
          <tr>
            <th class="text-muted ps-0" style="width:38%">Estado actual</th>
            <td><span class="badge <?=$estC?>"><?=h(str_replace('_',' ',$t['estado']))?></span></td>
          </tr>
          <tr>
            <th class="text-muted ps-0">Estado solicitado</th>
            <td><span class="badge <?=$badgeCPSol?>"><?=h(str_replace('_',' ',$estCPSol))?></span></td>
          </tr>
          <?php if ($notasCPSol): ?>
          <tr>
            <th class="text-muted ps-0 align-top">Observaciones</th>
            <td class="fst-italic">"<?=nl2br(h($notasCPSol))?>"</td>
          </tr>
          <?php endif; ?>
        </table>

        <!-- Aprobar -->
        <form method="POST" action="tickets.php?action=aprobar_cambio&id=<?=$id?>" class="mb-2">
          <input type="hidden" name="cp_id" value="<?=$cambioPendiente['id']?>">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Nota (opcional)</label>
            <textarea name="admin_nota" class="form-control form-control-sm" rows="2"
                      placeholder="Comentario para el supervisor…"></textarea>
          </div>
          <button type="submit" class="btn btn-success w-100"
                  onclick="return confirm('¿Aprobar este cambio? El estado del ticket se actualizará.')">
            <i class="bi bi-check-circle me-1"></i>Aprobar Cambio
          </button>
        </form>

        <!-- Rechazar -->
        <form method="POST" action="tickets.php?action=rechazar_cambio&id=<?=$id?>">
          <input type="hidden" name="cp_id" value="<?=$cambioPendiente['id']?>">
          <div class="mb-2">
            <label class="form-label small fw-semibold">Motivo del rechazo <span class="text-danger">*</span></label>
            <textarea name="admin_nota" class="form-control form-control-sm" rows="2"
                      placeholder="Explica por qué rechazas el cambio…" required></textarea>
          </div>
          <button type="submit" class="btn btn-outline-danger w-100">
            <i class="bi bi-x-circle me-1"></i>Rechazar Cambio
          </button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

</div><!-- /row g-3 -->


<!-- Modal asignar (solo admin) -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="modalAsignar" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header modal-header-ti">
        <h6 class="modal-title"><i class="bi bi-person-check me-2"></i>Asignar Ticket a Supervisor Técnico</h6>
        <button class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="tickets.php?action=asignar&id=<?=$id?>">
        <div class="modal-body">
          <p class="text-muted small">Selecciona el Supervisor Técnico al que deseas asignar este ticket.</p>
          <div class="mb-3">
            <label class="form-label fw-semibold">Supervisor Técnico *</label>
            <select name="supervisor_id" class="form-select" required>
              <option value="">— Seleccionar supervisor —</option>
              <?php foreach ($supervisores as $sup): ?>
              <option value="<?=$sup['id']?>" <?=(int)($t['asignado_a_suser']??0)==(int)$sup['id']?'selected':''?>>
                <?=h($sup['nombre_completo'])?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="alert alert-warning py-2 small">
            <i class="bi bi-info-circle me-1"></i>
            El ticket cambiará a estado <strong>EN PROCESO</strong> y se notificará al supervisor seleccionado.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-warning"><i class="bi bi-person-check me-1"></i>Asignar Ticket</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<!-- ══ NOTIFICACIONES SWEETALERT ══════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', () => {

  // ── 1. Bloqueo de estado (ticket asignado a otro supervisor) ────────────
  <?php if ($mostrarSwalBloqueoEstado): ?>
  Swal.fire({
    icon: 'warning',
    title: 'Acceso bloqueado',
    html: 'Este ticket fue validado por el Administrador y no está asignado a tu perfil.<br>' +
          '<span class="small text-muted">Solo el Administrador puede modificarlo.</span>',
    confirmButtonText: 'Entendido',
    confirmButtonColor: '#dc3545'
  });
  <?php endif; ?>

  // ── 2. Ya hay un cambio pendiente (supervisor intenta reenviar) ──────────
  <?php if ($swalPendiente): ?>
  Swal.fire({
    icon: 'info',
    title: 'Cambio en espera',
    html: 'Ya tienes un cambio enviado esperando aprobación del Administrador.<br>' +
          '<span class="small text-muted">Debes esperar la respuesta antes de enviar otro.</span>',
    confirmButtonText: 'Entendido',
    confirmButtonColor: '#0d6efd'
  });
  <?php endif; ?>

  // ── 3. Cambio enviado exitosamente ───────────────────────────────────────
  <?php if ($swalEnviado > 0): ?>
  Swal.fire({
    icon: 'success',
    title: '¡Cambio enviado!',
    html: 'Tu actualización fue enviada al Administrador para aprobación.<br>' +
          '<span class="badge bg-secondary mt-2">Ref. #<?=$swalEnviado?></span><br>' +
          '<span class="small text-muted">Recibirás una notificación cuando sea procesada.</span>',
    confirmButtonText: 'Listo',
    confirmButtonColor: '#198754'
  });
  <?php endif; ?>

  // ── 4. Cambio APROBADO (Admin acaba de aprobar) ──────────────────────────
  <?php if ($swalAprobado > 0): ?>
  Swal.fire({
    icon: 'success',
    title: '✅ Cambio Aprobado',
    html: 'El cambio <strong>#<?=$swalAprobado?></strong> fue <strong class="text-success">APROBADO</strong>.<br>' +
          'El estado del ticket ha sido actualizado.<br>' +
          <?php
            // Obtener datos del cambio para mostrar al admin
            $cpAprobado = getCambioPendienteById($swalAprobado);
            $notaAprobado = $cpAprobado ? ($cpAprobado['admin_nota'] ?? '') : '';
            $supAprobado  = $cpAprobado ? ($cpAprobado['supervisor_nombre'] ?? '') : '';
          ?>
          '<?= $supAprobado ? '<span class="small text-muted">Supervisor: '.h($supAprobado).'</span>' : '' ?>',
    confirmButtonText: 'Perfecto',
    confirmButtonColor: '#198754'
  });
  <?php endif; ?>

  // ── 5. Cambio RECHAZADO (Admin acaba de rechazar) ────────────────────────
  <?php if ($swalRechazado > 0): ?>
  <?php
    $cpRechazado   = getCambioPendienteById($swalRechazado);
    $notaRechazado = $cpRechazado ? ($cpRechazado['admin_nota'] ?? '') : '';
    $supRechazado  = $cpRechazado ? ($cpRechazado['supervisor_nombre'] ?? '') : '';
  ?>
  Swal.fire({
    icon: 'error',
    title: '❌ Cambio Rechazado',
    html: 'El cambio <strong>#<?=$swalRechazado?></strong> fue <strong class="text-danger">RECHAZADO</strong>.<br>' +
          <?php if ($notaRechazado): ?>
          '<div class="alert alert-light mt-2 text-start small"><strong>Motivo:</strong> <?=h($notaRechazado)?></div>' +
          <?php endif; ?>
          '<?= $supRechazado ? '<span class="small text-muted">Supervisor notificado: '.h($supRechazado).'</span>' : '' ?>',
    confirmButtonText: 'Entendido',
    confirmButtonColor: '#dc3545'
  });
  <?php endif; ?>

  // ── 6. Notificación al Supervisor cuando accede al ticket post-aprobación ──
  <?php if (isSuper() && $ultimoCambioResuelto): ?>
  <?php
    $notifEstado = $ultimoCambioResuelto['estado'] ?? '';
    $notifNota   = $ultimoCambioResuelto['admin_nota'] ?? '';
    $notifAdmin  = $ultimoCambioResuelto['admin_nombre'] ?? 'el Administrador';
    $notifFecha  = substr($ultimoCambioResuelto['fecha_resolucion'] ?? '', 0, 16);
    // Solo mostrar si el supervisor NO ha visto esta notificación aún
    $notifKey    = 'notif_cp_'.$ultimoCambioResuelto['id'];
    $yaVisto     = !empty($_SESSION[$notifKey]);
    if (!$yaVisto) {
        $_SESSION[$notifKey] = 1;
    }
  ?>
  <?php if (!$yaVisto && $notifEstado === 'APROBADO'): ?>
  Swal.fire({
    icon: 'success',
    title: '✅ ¡Tu cambio fue aprobado!',
    html: 'El Administrador <strong><?=h($notifAdmin)?></strong> aprobó tu actualización.<br>' +
          '<span class="small text-muted">El estado del ticket ha sido actualizado el <?=h($notifFecha)?>.</span>' +
          <?php if ($notifNota): ?>
          '<div class="alert alert-light mt-2 text-start small"><strong>Comentario:</strong> <?=h($notifNota)?></div>' +
          <?php endif; ?>
          '',
    confirmButtonText: '¡Genial!',
    confirmButtonColor: '#198754'
  });
  <?php elseif (!$yaVisto && $notifEstado === 'RECHAZADO'): ?>
  Swal.fire({
    icon: 'warning',
    title: '⚠️ Tu cambio fue rechazado',
    html: 'El Administrador <strong><?=h($notifAdmin)?></strong> rechazó tu actualización.<br>' +
          <?php if ($notifNota): ?>
          '<div class="alert alert-danger mt-2 text-start small"><strong>Motivo:</strong> <?=h($notifNota)?></div>' +
          <?php endif; ?>
          '<span class="small text-muted">Puedes corregir y reenviar el cambio.</span>',
    confirmButtonText: 'Entendido',
    confirmButtonColor: '#ffc107',
    confirmButtonText: 'Corregir y reenviar'
  });
  <?php endif; ?>
  <?php endif; ?>

  // ── 7. Notificación al Usuario cuando el ticket fue RESUELTO ─────────────
  <?php if (isNormal() && $ticketResuelto): ?>
  <?php
    $notifResKey = 'notif_resuelto_'.$id;
    $yaVistoRes  = !empty($_SESSION[$notifResKey]);
    if (!$yaVistoRes) { $_SESSION[$notifResKey] = 1; }
  ?>
  <?php if (!$yaVistoRes): ?>
  Swal.fire({
    icon: 'success',
    title: '✅ Tu ticket fue resuelto',
    html: 'El ticket <strong><?=h($t['folio_ticket']??'')?></strong> ha sido marcado como <strong class="text-success">RESUELTO</strong>.<br>' +
          '<span class="small text-muted">Si el problema persiste, puedes abrir un nuevo ticket.</span>',
    confirmButtonText: 'Entendido',
    confirmButtonColor: '#198754'
  });
  <?php endif; ?>
  <?php endif; ?>

});

// ── confirmarEnvio para el Supervisor ────────────────────────────────────────
<?php if (isSuper()): ?>
function confirmarEnvio(event) {
  event.preventDefault();
  const notas = document.getElementById('tecnico_notas')?.value.trim() ?? '';
  if (!notas) {
    Swal.fire({
      icon: 'warning',
      title: 'Campo requerido',
      text: 'Escribe las observaciones del técnico antes de enviar.',
      confirmButtonColor: '#ffc107'
    });
    return false;
  }
  const estado = document.querySelector('#formActualizarEstado select[name="estado"]')?.value ?? '';
  Swal.fire({
    title: '¿Confirmar envío?',
    html: `<div class="text-start small">
      <p class="mb-1"><strong>Estado solicitado:</strong>
        <span class="badge ${estado === 'RESUELTO' ? 'bg-success' : 'bg-info text-dark'}">${estado.replace('_',' ')}</span>
      </p>
      <p class="mb-0 text-muted">El Administrador recibirá este cambio para aprobación.</p>
    </div>`,
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#ffc107',
    cancelButtonColor:  '#6c757d',
    confirmButtonText:  '<i class="bi bi-send me-1"></i> Enviar',
    cancelButtonText:   'Cancelar'
  }).then(result => {
    if (result.isConfirmed) {
      document.getElementById('formActualizarEstado').submit();
    }
  });
}
<?php endif; ?>
</script>

<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

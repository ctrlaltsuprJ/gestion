<?php
require_once 'includes/auth.php';
requireRol(ROL_ADMIN);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── POST: Resolver cambio ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id) {
    $decision = $_POST['decision'] ?? '';
    $nota     = trim($_POST['nota'] ?? '');
    if (in_array($decision, ['APROBADO', 'RECHAZADO'])) {
        resolverCambio($id, $decision, $nota);
        flash('success', 'Cambio '.(strtolower($decision)).' correctamente.');
    }
    redirect('aprobaciones.php');
}

$pageTitle = 'Aprobaciones Pendientes';
include 'includes/header.php';

$pendientes = getCambiosPendientes('PENDIENTE');
$historial  = array_slice(getCambiosPendientes('APROBADO'), 0, 20);
$rechazados = array_slice(getCambiosPendientes('RECHAZADO'), 0, 10);
?>
<div class="container-fluid px-3 py-3">

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0">
    <i class="bi bi-check2-square me-2"></i>Aprobaciones de Cambios
    <?php if (count($pendientes)): ?>
    <span class="badge bg-danger ms-2"><?= count($pendientes) ?> pendiente(s)</span>
    <?php endif; ?>
  </h5>
</div>

<?php if (empty($pendientes)): ?>
<div class="card mb-3">
  <div class="card-body text-center py-5 text-success">
    <i class="bi bi-check-circle-fill fs-1 d-block mb-2"></i>
    <strong>Sin cambios pendientes.</strong><br>
    <span class="text-muted small">Todos los cambios han sido revisados.</span>
  </div>
</div>
<?php else: ?>

<div class="alert alert-warning py-2 small mb-3">
  <i class="bi bi-exclamation-triangle me-1"></i>
  Los siguientes cambios fueron realizados por Supervisores Técnicos y <strong>requieren tu aprobación</strong> para aplicarse al sistema.
</div>

<?php foreach ($pendientes as $cp):
  $datos = json_decode($cp['datos_json'], true);
  $modulo = strtoupper($cp['modulo']);
?>
<div class="card mb-3 cambio-card">
  <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2 bg-light">
    <div>
      <span class="badge bg-warning text-dark me-2">PENDIENTE</span>
      <strong>#<?= $cp['id'] ?></strong>
      <span class="text-muted small ms-2"><?= $modulo ?> — <?= h($cp['accion']) ?> — Registro #<?= $cp['registro_id'] ?></span>
    </div>
    <div class="text-muted small">
      <i class="bi bi-person me-1"></i><?= h($cp['supervisor_nombre']) ?>
      &nbsp;·&nbsp;
      <i class="bi bi-clock me-1"></i><?= h(substr($cp['created_at'],0,16)) ?>
    </div>
  </div>
  <div class="card-body">
    <?php if ($cp['descripcion']): ?>
    <p class="small text-muted mb-2"><i class="bi bi-chat-left-text me-1"></i><?= h($cp['descripcion']) ?></p>
    <?php endif; ?>

    <!-- Mostrar datos del cambio -->
    <div class="row g-2 mb-3">
      <div class="col-12">
        <div class="bg-light rounded p-2">
          <div class="small fw-bold text-secondary mb-1">Datos propuestos:</div>
          <div class="row g-1">
            <?php
            $skip = ['_checks','id','created_at','updated_at'];
            foreach ($datos as $k => $v):
              if (in_array($k, $skip) || is_array($v) || is_null($v) || $v==='') continue;
            ?>
            <div class="col-md-4 col-6">
              <span class="text-muted small"><?= h($k) ?>:</span>
              <span class="small fw-semibold"> <?= h(mb_substr((string)$v, 0, 60)) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Botones de acción -->
    <div class="row g-2 align-items-end">
      <div class="col-md-6">
        <form method="POST" action="aprobaciones.php?id=<?= $cp['id'] ?>"
              id="form-aprobar-<?= $cp['id'] ?>">
          <input type="hidden" name="decision" value="APROBADO">
          <input type="hidden" name="nota" value="">
          <button type="button" class="btn btn-sm btn-success w-100"
                  onclick="confirmAprobar('form-aprobar-<?= $cp['id'] ?>')">
            <i class="bi bi-check-lg me-1"></i>Aprobar y Aplicar
          </button>
        </form>
      </div>
      <div class="col-md-6">
        <form method="POST" action="aprobaciones.php?id=<?= $cp['id'] ?>"
              id="form-rechazar-<?= $cp['id'] ?>">
          <input type="hidden" name="decision" value="RECHAZADO">
          <input type="hidden" name="nota" value="">
          <button type="button" class="btn btn-sm btn-danger w-100"
                  onclick="confirmRechazar('form-rechazar-<?= $cp['id'] ?>')">
            <i class="bi bi-x-lg me-1"></i>Rechazar
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Historial -->
<ul class="nav nav-tabs mt-4 mb-3" id="histTabs">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabAprobados">✓ Aprobados (<?= count($historial) ?>)</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabRechazados">✗ Rechazados (<?= count($rechazados) ?>)</a></li>
</ul>
<div class="tab-content">
  <div class="tab-pane fade show active" id="tabAprobados">
    <?php if (empty($historial)): ?><p class="text-muted small p-2">Sin historial.</p>
    <?php else: ?>
    <div class="card"><div class="card-body p-0">
    <table class="table table-ti mb-0 table-sm">
      <thead><tr><th>#</th><th>Módulo</th><th>Registro</th><th>Supervisor</th><th>Admin</th><th>Fecha</th><th>Nota</th></tr></thead>
      <tbody>
      <?php foreach ($historial as $h2): ?>
      <tr>
        <td><?=$h2['id']?></td>
        <td><span class="badge bg-success"><?=h($h2['modulo'])?></span></td>
        <td>#<?=$h2['registro_id']?></td>
        <td class="small"><?=h($h2['supervisor_nombre']??'')?></td>
        <td class="small"><?=h($h2['admin_nombre']??'')?></td>
        <td class="small"><?=h(substr($h2['fecha_resolucion']??'',0,16))?></td>
        <td class="small text-muted"><?=h(mb_substr($h2['admin_nota']??'',0,50))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div></div>
    <?php endif; ?>
  </div>
  <div class="tab-pane fade" id="tabRechazados">
    <?php if (empty($rechazados)): ?><p class="text-muted small p-2">Sin rechazos.</p>
    <?php else: ?>
    <div class="card"><div class="card-body p-0">
    <table class="table table-ti mb-0 table-sm">
      <thead><tr><th>#</th><th>Módulo</th><th>Supervisor</th><th>Motivo</th><th>Fecha</th></tr></thead>
      <tbody>
      <?php foreach ($rechazados as $r): ?>
      <tr>
        <td><?=$r['id']?></td>
        <td><span class="badge bg-danger"><?=h($r['modulo'])?></span></td>
        <td class="small"><?=h($r['supervisor_nombre']??'')?></td>
        <td class="small text-muted"><?=h(mb_substr($r['admin_nota']??'Sin motivo',0,80))?></td>
        <td class="small"><?=h(substr($r['fecha_resolucion']??'',0,16))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div></div>
    <?php endif; ?>
  </div>
</div>

</div>
<?php include 'includes/footer.php'; ?>

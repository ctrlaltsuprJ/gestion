<?php
require_once 'includes/auth.php';
requireRol(ROL_ADMIN);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)($_POST['id']??0) ?: null;
    $saved = saveSystemUser($_POST, $pid);
    auditLog($pid?'EDITAR_SUSER':'CREAR_SUSER','system_users',$saved,$_POST['username']??'');
    flash('success', $pid ? 'Cuenta actualizada.' : 'Cuenta creada.');
    redirect('system_users.php');
}
if ($action === 'delete' && $id) {
    if ($id === suserIdActual()) { flash('danger','No puedes desactivar tu propia cuenta.'); redirect('system_users.php'); }
    deleteSystemUser($id);
    auditLog('DESACTIVAR_SUSER','system_users',$id);
    flash('warning', 'Cuenta desactivada.');
    redirect('system_users.php');
}

$pageTitle = 'Cuentas del Sistema';
include 'includes/header.php';
$sUsers  = getSystemUsers();
$usuarios = getUsuarios();
?>
<div class="container-fluid px-3 py-3">

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="bi bi-person-badge me-2"></i>Cuentas de Acceso al Sistema</h5>
  <a href="system_users.php?action=new" class="btn btn-sm" style="background:#1a3a5c;color:#fff;">
    <i class="bi bi-person-plus me-1"></i>Nueva Cuenta
  </a>
</div>

<div class="alert alert-info py-2 small">
  <i class="bi bi-shield-lock me-1"></i>
  <strong>Niveles de acceso:</strong>
  <span class="badge ms-2" style="background:#1a3a5c;">ADMINISTRADOR</span> control total &nbsp;|&nbsp;
  <span class="badge bg-warning text-dark">SUPERVISOR TI</span> ver/editar con aprobación &nbsp;|&nbsp;
  <span class="badge bg-secondary">USUARIO</span> solo sus equipos y solicitudes
</div>

<div class="card"><div class="card-body p-0">
<table class="table table-ti mb-0">
  <thead><tr><th>#</th><th>Usuario</th><th>Nombre</th><th>Email</th><th>Rol</th><th>Usuario Vinculado</th><th>Último Acceso</th><th>Estado</th><th>Acciones</th></tr></thead>
  <tbody>
  <?php foreach ($sUsers as $su): ?>
  <tr class="<?= !$su['activo'] ? 'text-muted' : '' ?>">
    <td><?=$su['id']?></td>
    <td><code><?=h($su['username'])?></code></td>
    <td><strong><?=h($su['nombre_completo'])?></strong></td>
    <td class="small"><?=h($su['email']??'')?></td>
    <td><?= rolBadge($su['rol']) ?></td>
    <td class="small"><?=h($su['usuario_vinculado_nombre']??'—')?></td>
    <td class="small"><?=h(substr($su['ultimo_acceso']??'Nunca',0,16))?></td>
    <td>
      <?php if ($su['activo']): ?>
      <span class="badge bg-success">Activo</span>
      <?php else: ?>
      <span class="badge bg-secondary">Inactivo</span>
      <?php endif; ?>
    </td>
    <td>
      <a href="system_users.php?action=edit&id=<?=$su['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
      <?php if ($su['id'] !== suserIdActual()): ?>
      <button onclick="confirmDelete('system_users.php?action=delete&id=<?=$su['id']?>','<?=addslashes($su['username'])?>')" class="btn btn-xs btn-outline-danger"><i class="bi bi-person-x"></i></button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if(empty($sUsers)): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin cuentas</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div>

<?php else: // form nuevo/editar ?>
<?php $su = $action==='edit'&&$id ? getSystemUserById($id) : []; $isEdit=!empty($su); ?>
<div class="row justify-content-center"><div class="col-xl-7">
<div class="card">
  <div class="card-header-ti"><i class="bi bi-person-badge me-2"></i><?=$isEdit?'Editar':'Nueva'?> Cuenta del Sistema</div>
  <div class="card-body">
    <form method="POST" action="system_users.php">
      <?php if($isEdit): ?><input type="hidden" name="id" value="<?=$su['id']?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Nombre Completo *</label>
          <input type="text" name="nombre_completo" class="form-control" value="<?=h($su['nombre_completo']??'')?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Usuario (login) *</label>
          <input type="text" name="username" class="form-control" value="<?=h($su['username']??'')?>" required autocomplete="off">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?=h($su['email']??'')?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">
            Contraseña <?= $isEdit ? '<span class="text-muted small">(dejar vacío para no cambiar)</span>' : '*' ?>
          </label>
          <input type="password" name="password" class="form-control" autocomplete="new-password"
                 <?= !$isEdit ? 'required minlength="6"' : '' ?> placeholder="Mínimo 6 caracteres">
        </div>
        <div class="col-md-6">
          <label class="form-label">Rol del Sistema *</label>
          <select name="rol" class="form-select" id="sel-rol" onchange="toggleUserVinc()">
            <?php foreach ([ROL_ADMIN=>'Administrador', ROL_SUPER=>'Supervisor Técnico', ROL_USER=>'Usuario Normal'] as $rv=>$rl): ?>
            <option value="<?=$rv?>" <?=($su['rol']??ROL_USER)===$rv?'selected':''?>><?=$rl?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6" id="wrap-usuario-vinc">
          <label class="form-label">
            Usuario Vinculado
            <span class="text-muted small">(para Rol Usuario Normal)</span>
          </label>
          <select name="usuario_id" class="form-select">
            <option value="">— Sin vincular —</option>
            <?php foreach ($usuarios as $u): ?>
            <option value="<?=$u['id']?>" <?=(int)($su['usuario_id']??0)==(int)$u['id']?'selected':''?>>
              <?=h($u['nombre'].' '.($u['apellidos']??'').' — '.($u['area_nombre']??''))?>
            </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Vincula esta cuenta con un usuario del directorio para mostrar sus equipos.</div>
        </div>
        <?php if ($isEdit): ?>
        <div class="col-md-3">
          <label class="form-label">Estado</label>
          <select name="activo" class="form-select">
            <option value="1" <?=($su['activo']??1)?'selected':''?>>Activo</option>
            <option value="0" <?=!($su['activo']??1)?'selected':''?>>Inactivo</option>
          </select>
        </div>
        <?php endif; ?>
      </div>

      <!-- Info de permisos -->
      <div class="mt-3">
        <div id="info-admin" class="alert alert-primary py-2 small" style="display:none">
          <i class="bi bi-shield-fill-check me-1"></i>
          <strong>Administrador:</strong> Acceso total. Puede crear/editar/eliminar todo, asignar tickets y aprobar cambios del supervisor.
        </div>
        <div id="info-super" class="alert alert-warning py-2 small" style="display:none">
          <i class="bi bi-tools me-1"></i>
          <strong>Supervisor Técnico:</strong> Puede ver y editar equipos, tickets e inventario. Sus cambios quedan pendientes de aprobación del Administrador.
        </div>
        <div id="info-user" class="alert alert-secondary py-2 small" style="display:none">
          <i class="bi bi-person me-1"></i>
          <strong>Usuario Normal:</strong> Solo puede ver sus equipos asignados y enviar solicitudes de soporte al Administrador.
        </div>
      </div>

      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
        <a href="system_users.php" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div></div>

<script>
function toggleUserVinc() {
  const rol = document.getElementById('sel-rol').value;
  document.getElementById('wrap-usuario-vinc').style.display = (rol === '<?= ROL_USER ?>') ? '' : 'none';
  document.getElementById('info-admin').style.display = (rol === '<?= ROL_ADMIN ?>') ? '' : 'none';
  document.getElementById('info-super').style.display = (rol === '<?= ROL_SUPER ?>') ? '' : 'none';
  document.getElementById('info-user').style.display  = (rol === '<?= ROL_USER ?>') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleUserVinc);
</script>
<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

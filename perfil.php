<?php
require_once 'includes/auth.php';
$auth = authCheck();

$pageTitle = 'Mi Perfil';
$error = '';
$ok    = '';
$areas = getAreas();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass    = trim($_POST['password']    ?? '');
    $passNew = trim($_POST['password_new']?? '');
    $passConf= trim($_POST['password_confirm']?? '');
    $nombre  = trim($_POST['nombre_completo']?? '');
    $email   = trim($_POST['email']?? '');
    $areaId  = (int)($_POST['area_id'] ?? 0);

    $su = getSystemUserById($auth['id']);
    if (!$su) { flash('danger','Error.'); redirect('perfil.php'); }

    if ($pass && $passNew) {
        if (!password_verify($pass, $su['password_hash'])) {
            $error = 'La contraseña actual es incorrecta.';
        } elseif (strlen($passNew) < 6) {
            $error = 'La nueva contraseña debe tener al menos 6 caracteres.';
        } elseif ($passNew !== $passConf) {
            $error = 'Las contraseñas nuevas no coinciden.';
        } else {
            $hash = password_hash($passNew, PASSWORD_BCRYPT, ['cost'=>12]);
            getDB()->prepare("UPDATE system_users SET password_hash=?, nombre_completo=?, email=? WHERE id=?")->execute([$hash, $nombre, $email, $auth['id']]);
            if (isAdmin() && !empty($su['usuario_id'])) {
                getDB()->prepare("UPDATE usuarios SET area_id=? WHERE id=?")->execute([$areaId ?: null, (int)$su['usuario_id']]);
            }
            $_SESSION['auth']['nombre'] = $nombre;
            auditLog('CAMBIO_CONTRASENA','perfil',$auth['id'],'Contraseña actualizada');
            $ok = 'Perfil y contraseña actualizados correctamente.';
        }
    } else {
        getDB()->prepare("UPDATE system_users SET nombre_completo=?, email=? WHERE id=?")->execute([$nombre, $email, $auth['id']]);
        if (isAdmin() && !empty($su['usuario_id'])) {
            getDB()->prepare("UPDATE usuarios SET area_id=? WHERE id=?")->execute([$areaId ?: null, (int)$su['usuario_id']]);
        }
        $_SESSION['auth']['nombre'] = $nombre;
        auditLog('EDITAR_PERFIL','perfil',$auth['id']);
        $ok = 'Perfil actualizado correctamente.';
    }
}

$su = getSystemUserById($auth['id']);
$areaActualId = 0;
if (!empty($su['usuario_id'])) {
    $usuarioPerfil = getUsuarioById((int)$su['usuario_id']);
    $areaActualId = (int)($usuarioPerfil['area_id'] ?? 0);
}
include 'includes/header.php';
?>
<div class="container py-4" style="max-width:560px;">
  <h5 class="mb-3"><i class="bi bi-person-circle me-2"></i>Mi Perfil</h5>

  <?php if ($error): ?>
  <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
  <?php endif; ?>
  <?php if ($ok): ?>
  <div class="alert alert-success py-2 small"><?= h($ok) ?></div>
  <?php endif; ?>

  <div class="card mb-3">
    <div class="card-header-ti d-flex justify-content-between align-items-center">
      <span><i class="bi bi-person me-2"></i>Información de Cuenta</span>
      <?= rolBadge($auth['rol']) ?>
    </div>
    <div class="card-body">
      <form method="POST" action="perfil.php">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Nombre Completo</label>
            <input type="text" name="nombre_completo" class="form-control"
                   value="<?= h($su['nombre_completo']??'') ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Usuario (login)</label>
            <input type="text" class="form-control" value="<?= h($su['username']??'') ?>" disabled>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= h($su['email']??'') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Área</label>
            <select name="area_id" class="form-select" <?= isAdmin() ? '' : 'disabled' ?>>
              <option value="">— Sin área —</option>
              <?php foreach ($areas as $a): ?>
              <option value="<?=$a['id']?>" <?=$areaActualId===(int)$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!isAdmin()): ?>
            <div class="form-text">Solo el Administrador puede editar el área seleccionada.</div>
            <?php endif; ?>
          </div>
          <?php if (isAdmin()): ?>
          <div class="col-12"><hr class="my-1"><p class="text-muted small mb-2">Dejar en blanco para no cambiar contraseña</p></div>
          <div class="col-md-4">
            <label class="form-label">Contraseña Actual</label>
            <input type="password" name="password" class="form-control" autocomplete="current-password">
          </div>
          <div class="col-md-4">
            <label class="form-label">Nueva Contraseña</label>
            <input type="password" name="password_new" class="form-control" autocomplete="new-password" minlength="6">
          </div>
          <div class="col-md-4">
            <label class="form-label">Confirmar Nueva</label>
            <input type="password" name="password_confirm" class="form-control" autocomplete="new-password">
          </div>
        </div>
        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;">
            <i class="bi bi-check-lg me-1"></i>Guardar Cambios
          </button>
          <a href="index.php" class="btn btn-outline-secondary">Cancelar</a>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if (isAdmin()): ?>
  <div class="card">
    <div class="card-header-ti"><i class="bi bi-clock-history me-2"></i>Actividad Reciente</div>
    <div class="card-body p-0">
          <?php
      $s = getDB()->prepare("SELECT * FROM auditoria WHERE suser_id=? ORDER BY created_at DESC LIMIT 10");
      $s->execute([$auth['id']]);
      $logs = $s->fetchAll();
      ?>
      <table class="table table-ti table-sm mb-0">
        <thead><tr><th>Fecha</th><th>Acción</th><th>Módulo</th><th>Descripción</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $l): ?>
        <tr>
          <td class="small text-nowrap"><?=h(substr($l['created_at'],0,16))?></td>
          <td class="small fw-semibold"><?=h($l['accion'])?></td>
          <td><span class="badge bg-light text-dark border" style="font-size:.65rem;"><?=h($l['modulo']??'')?></span></td>
          <td class="small text-muted"><?=h(mb_substr($l['descripcion']??'',0,50))?></td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($logs)): ?><tr><td colspan="4" class="text-center text-muted py-2 small">Sin actividad registrada</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

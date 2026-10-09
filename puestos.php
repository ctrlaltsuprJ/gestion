<?php
require_once 'includes/auth.php';
requireRol(ROL_ADMIN);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid   = (int)($_POST['id'] ?? 0) ?: null;
    $saved = savePuesto($_POST, $pid);
    flash('success', $pid ? 'Puesto actualizado.' : 'Puesto creado.');
    redirect('puestos.php');
}
if ($action === 'delete' && $id) {
    deletePuesto($id);
    flash('warning', 'Puesto desactivado.');
    redirect('puestos.php');
}

$pageTitle = 'Catálogo de Puestos';
include 'includes/header.php';
$puestos = getDB()->query(
    "SELECT p.*, COUNT(u.id) n_usuarios
     FROM cat_puestos p
     LEFT JOIN usuarios u ON u.puesto_id=p.id AND u.activo=1
     WHERE p.activo=1
     GROUP BY p.id
     ORDER BY FIELD(p.nivel,'DIRECTIVO','SUBDIRECTIVO','JEFATURA','COORDINACION','OPERATIVO','APOYO'), p.nombre"
)->fetchAll();

$nivelBadge = [
    'DIRECTIVO'    => 'bg-danger',
    'SUBDIRECTIVO' => 'bg-warning text-dark',
    'JEFATURA'     => 'bg-primary',
    'COORDINACION' => 'bg-info text-dark',
    'OPERATIVO'    => 'bg-success',
    'APOYO'        => 'bg-secondary',
];
?>
<div class="container-fluid px-3 py-3">

<?php if ($action === 'list'): ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-briefcase me-2"></i>Catálogo de Puestos</h5>
  <div class="d-flex gap-2">
    <a href="usuarios.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Volver a Usuarios</a>
    <a href="puestos.php?action=new" class="btn btn-sm" style="background:#1a3a5c;color:#fff;">
      <i class="bi bi-plus me-1"></i>Nuevo Puesto
    </a>
  </div>
</div>

<div class="card">
<div class="card-body p-0">
<table class="table table-ti mb-0">
  <thead><tr><th>#</th><th>Nombre del Puesto</th><th>Nivel</th><th>Descripción</th><th class="text-center">Usuarios</th><th class="no-print">Acciones</th></tr></thead>
  <tbody>
  <?php foreach ($puestos as $p): ?>
  <tr>
    <td><?= $p['id'] ?></td>
    <td><strong><?= h($p['nombre']) ?></strong></td>
    <td><span class="badge <?= $nivelBadge[$p['nivel']] ?? 'bg-secondary' ?>"><?= h($p['nivel']) ?></span></td>
    <td class="small text-muted"><?= h(mb_substr($p['descripcion']??'',0,60)) ?></td>
    <td class="text-center"><span class="badge bg-primary"><?= $p['n_usuarios'] ?></span></td>
    <td class="no-print">
      <a href="puestos.php?action=edit&id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
      <?php if ((int)$p['n_usuarios'] === 0): ?>
      <button onclick="confirmDelete('puestos.php?action=delete&id=<?= $p['id'] ?>','<?= addslashes($p['nombre']) ?>')"
              class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i></button>
      <?php else: ?>
      <button class="btn btn-xs btn-outline-secondary" disabled title="Tiene usuarios asignados"><i class="bi bi-lock"></i></button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (empty($puestos)): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin puestos registrados</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div>

<?php else: ?>
<!-- Form -->
<?php $p = $action === 'edit' && $id ? getPuestoById($id) : []; $isEdit = !empty($p); ?>
<div class="row justify-content-center"><div class="col-md-6">
<div class="card">
  <div class="card-header-ti"><i class="bi bi-briefcase me-2"></i><?= $isEdit ? 'Editar' : 'Nuevo' ?> Puesto</div>
  <div class="card-body">
    <form method="POST" action="puestos.php">
      <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $p['id'] ?>"><?php endif; ?>
      <div class="mb-3">
        <label class="form-label">Nombre del Puesto <span class="text-danger">*</span></label>
        <input type="text" name="nombre" class="form-control" value="<?= h($p['nombre']??'') ?>" required
               placeholder="Ej. ENCARGADO/A DE DEPARTAMENTO">
      </div>
      <div class="mb-3">
        <label class="form-label">Nivel Jerárquico</label>
        <select name="nivel" class="form-select">
          <?php foreach (['DIRECTIVO'=>'🔴 Directivo','SUBDIRECTIVO'=>'🟠 Subdirectivo','JEFATURA'=>'🔵 Jefatura',
                          'COORDINACION'=>'🟢 Coordinación','OPERATIVO'=>'⚪ Operativo','APOYO'=>'⚫ Apoyo'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= ($p['nivel']??'OPERATIVO')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Descripción</label>
        <textarea name="descripcion" class="form-control" rows="2"><?= h($p['descripcion']??'') ?></textarea>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
        <a href="puestos.php" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div>
</div></div>
<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

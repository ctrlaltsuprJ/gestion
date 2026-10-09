<?php
require_once 'includes/auth.php';
$action=$_GET['action']??'list'; $id=(int)($_GET['id']??0);
if($_SERVER['REQUEST_METHOD']==='POST'){ $pid=(int)($_POST['id']??0)?:null; saveArea($_POST,$pid); flash('success',$pid?'Área actualizada.':'Área creada.'); redirect('areas.php'); }
if($action==='delete'&&$id){ deleteArea($id); flash('warning','Área eliminada.'); redirect('areas.php'); }
$pageTitle='Áreas'; include 'includes/header.php'; $areas=getAreas();
?>
<div class="container-fluid px-3 py-3">
<?php if($action==='list'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Áreas / Departamentos</h5>
  <a href="areas.php?action=new" class="btn btn-sm" style="background:#1a3a5c;color:#fff;"><i class="bi bi-plus me-1"></i>Nueva Área</a>
</div>
<div class="card"><div class="card-body p-0"><table class="table table-ti mb-0">
<thead><tr><th>#</th><th>Nombre</th><th>Descripción</th><th>Equipos</th><th>Usuarios</th><th class="no-print">Acciones</th></tr></thead>
<tbody>
<?php foreach($areas as $a):
  $ne=getDB()->prepare("SELECT COUNT(*) FROM equipos WHERE area_id=? AND activo=1"); $ne->execute([$a['id']]); $ne=$ne->fetchColumn();
  $nu=getDB()->prepare("SELECT COUNT(*) FROM usuarios WHERE area_id=? AND activo=1"); $nu->execute([$a['id']]); $nu=$nu->fetchColumn();
?>
<tr><td><?=$a['id']?></td><td><strong><?=h($a['nombre'])?></strong></td><td class="small text-muted"><?=h($a['descripcion']??'')?></td><td><span class="badge bg-primary"><?=$ne?></span></td><td><span class="badge bg-secondary"><?=$nu?></span></td>
<td class="no-print">
  <a href="areas.php?action=edit&id=<?=$a['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
  <button onclick="confirmDelete('areas.php?action=delete&id=<?=$a['id']?>','<?=addslashes($a['nombre'])?>')" class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i></button>
</td></tr>
<?php endforeach; if(empty($areas)): ?><tr><td colspan="6" class="text-center text-muted py-4">Sin áreas</td></tr><?php endif; ?>
</tbody></table></div></div>

<?php else: ?>
<?php $a=$action==='edit'&&$id?getAreaById($id):[]; $isEdit=!empty($a); ?>
<div class="row justify-content-center"><div class="col-md-6"><div class="card">
  <div class="card-header-ti"><?=$isEdit?'Editar':'Nueva'?> Área</div>
  <div class="card-body">
    <form method="POST" action="areas.php">
      <?php if($isEdit): ?><input type="hidden" name="id" value="<?=$a['id']?>"><?php endif; ?>
      <div class="mb-3"><label class="form-label">Nombre *</label><input type="text" name="nombre" class="form-control" value="<?=h($a['nombre']??'')?>" required></div>
      <div class="mb-3"><label class="form-label">Descripción</label><textarea name="descripcion" class="form-control" rows="2"><?=h($a['descripcion']??'')?></textarea></div>
      <div class="d-flex gap-2"><button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button><a href="areas.php" class="btn btn-outline-secondary">Cancelar</a></div>
    </form>
  </div>
</div></div></div>
<?php endif; ?>
</div><?php include 'includes/footer.php'; ?>

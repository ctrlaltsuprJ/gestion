<?php
// auditoria.php
require_once 'includes/auth.php';
requireRol(ROL_ADMIN);

$pageTitle = 'Auditoría del Sistema';
include 'includes/header.php';

$db   = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$pp   = 50;
$offset = ($page - 1) * $pp;
$q   = trim($_GET['q'] ?? '');
$mod = $_GET['modulo'] ?? '';

$where = ['1=1']; $params = [];
if ($q) {
    $where[] = "(a.accion LIKE ? OR a.descripcion LIKE ? OR su.username LIKE ? OR su.nombre_completo LIKE ?)";
    $like = '%'.$q.'%';
    array_push($params, $like, $like, $like, $like);
}
if ($mod) { $where[] = "a.modulo=?"; $params[] = $mod; }
$w = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM auditoria a LEFT JOIN system_users su ON su.id=a.suser_id WHERE $w");
$total->execute($params); $total = (int)$total->fetchColumn();

$s = $db->prepare("SELECT a.*, su.username, su.nombre_completo, su.rol FROM auditoria a LEFT JOIN system_users su ON su.id=a.suser_id WHERE $w ORDER BY a.created_at DESC LIMIT $pp OFFSET $offset");
$s->execute($params);
$logs = $s->fetchAll();

$totalPages = ceil($total / $pp);

// Módulos únicos para filtro
$modulos = $db->query("SELECT DISTINCT modulo FROM auditoria WHERE modulo IS NOT NULL AND modulo!='' ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="container-fluid px-3 py-3">
<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Auditoría del Sistema</h5>
  <span class="text-muted small"><?= number_format($total) ?> registros</span>
</div>

<!-- Filtros -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" id="form-search" class="row g-2 align-items-end">
    <div class="col-sm-4">
      <input type="search" name="q" id="input-search" class="form-control form-control-sm"
             placeholder="Usuario, acción, descripción…" value="<?=h($q)?>">
    </div>
    <div class="col-sm-2">
      <select name="modulo" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— Módulo —</option>
        <?php foreach ($modulos as $m): ?><option <?=$mod===$m?'selected':''?>><?=h($m)?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
      <?php if ($q || $mod): ?><a href="auditoria.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a><?php endif; ?>
    </div>
  </form>
</div></div>

<div class="card"><div class="card-body p-0">
<div class="table-responsive">
<table class="table table-ti table-sm mb-0">
  <thead><tr>
    <th>#</th><th>Fecha/Hora</th><th>Usuario</th><th>Rol</th>
    <th>Acción</th><th>Módulo</th><th>Reg. ID</th><th>Descripción</th><th>IP</th>
  </tr></thead>
  <tbody>
  <?php foreach ($logs as $l):
    $rolCls = [ROL_ADMIN=>'badge-admin',ROL_SUPER=>'badge bg-warning text-dark',ROL_USER=>'badge bg-secondary'][$l['rol']??'']??'badge bg-light text-dark';
    $accionColor = str_contains($l['accion'],'DELETE')||str_contains($l['accion'],'ELIMINAR') ? 'text-danger' :
                  (str_contains($l['accion'],'CREATE')||str_contains($l['accion'],'CREAR') ? 'text-success' :
                  (str_contains($l['accion'],'LOGIN') ? 'text-primary' : ''));
  ?>
  <tr>
    <td class="text-muted small"><?=$l['id']?></td>
    <td class="small text-nowrap"><?=h(substr($l['created_at'],0,16))?></td>
    <td><code class="small"><?=h($l['username']??'Sistema')?></code><br><span class="text-muted" style="font-size:.7rem;"><?=h($l['nombre_completo']??'')?></span></td>
    <td><?php if($l['rol']): ?><span class="badge <?=$rolCls?>" style="font-size:.65rem;"><?=h(str_replace('_',' ',$l['rol']))?></span><?php endif; ?></td>
    <td class="small fw-semibold <?=$accionColor?>"><?=h($l['accion'])?></td>
    <td><span class="badge bg-light text-dark border" style="font-size:.65rem;"><?=h($l['modulo']??'')?></span></td>
    <td class="small text-muted"><?=$l['registro_id']?:'—'?></td>
    <td class="small text-muted"><?=h(mb_substr($l['descripcion']??'',0,70))?></td>
    <td class="small text-muted"><?=h($l['ip_address']??'')?></td>
  </tr>
  <?php endforeach; ?>
  <?php if(empty($logs)): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin registros</td></tr><?php endif; ?>
  </tbody>
</table>
</div>
</div></div>

<!-- Paginación -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
  <ul class="pagination pagination-sm justify-content-center">
    <?php for ($i=1; $i<=$totalPages; $i++): ?>
    <li class="page-item <?=$i===$page?'active':''?>">
      <a class="page-link" href="?page=<?=$i?>&q=<?=urlencode($q)?>&modulo=<?=urlencode($mod)?>"><?=$i?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

</div>
<?php include 'includes/footer.php'; ?>

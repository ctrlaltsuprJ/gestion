<?php
require_once 'includes/auth.php';
requireRol(ROL_ADMIN, ROL_SUPER);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireRol(ROL_ADMIN);
    $pid = (int)($_POST['id'] ?? 0) ?: null;
    saveSegmentoIP($_POST, $pid);
    flash('success', $pid ? 'Segmento actualizado.' : 'Segmento creado.');
    redirect('segmentos_ip.php');
}
if ($action === 'delete' && $id) {
    requireRol(ROL_ADMIN);
    deleteSegmentoIP($id);
    flash('warning', 'Segmento desactivado.');
    redirect('segmentos_ip.php');
}

$pageTitle = 'Segmentos de Red IP';
include 'includes/header.php';
$segmentos = getSegmentosIP();
$seg       = $action==='edit'&&$id ? getSegmentoIPById($id) : [];
$isEdit    = !empty($seg);

// Manejo para acción de ver IPs de un segmento específico
$segmentoIps = null;
if ($action === 'ips' && $id) {
    $segmentoIps = getSegmentoIPById($id);
    if (!$segmentoIps) {
        flash('error', 'Segmento no encontrado.');
        redirect('segmentos_ip.php');
    }
}
?>
<div class="container-fluid px-3 py-3">

<!-- KPIs por segmento -->
<?php $ipStats = getIpLibres(); ?>
<div class="row g-3 mb-3">
<?php foreach ($ipStats as $s):
  $pctUsado = $s['total'] > 0 ? round($s['usadas']/$s['total']*100,1) : 0;
?>
<div class="col-md-4 col-lg-3">
  <div class="card h-100" style="border-top:4px solid <?=h($s['color'])?>;">
    <div class="card-body pb-2">
      <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
          <div class="fw-bold small"><?=h($s['nombre'])?></div>
          <code class="text-muted" style="font-size:.75rem;"><?=h($s['red'])?>.0/24</code>
        </div>
        <span class="badge" style="background:<?=h($s['color'])?>"><?=h($s['red'])?></span>
      </div>
      <div class="row g-1 text-center mb-2">
        <div class="col-4">
          <div class="fw-bold text-danger"><?=$s['usadas']?></div>
          <div class="text-muted" style="font-size:.65rem;">USADAS</div>
        </div>
        <div class="col-4">
          <div class="fw-bold text-success"><?=$s['libres']?></div>
          <div class="text-muted" style="font-size:.65rem;">LIBRES</div>
        </div>
        <div class="col-4">
          <div class="fw-bold"><?=$s['total']?></div>
          <div class="text-muted" style="font-size:.65rem;">TOTAL</div>
        </div>
      </div>
      <div class="progress mb-1" style="height:6px;">
        <div class="progress-bar" style="width:<?=$pctUsado?>%;background:<?=h($s['color'])?>"></div>
      </div>
      <div class="text-muted text-end" style="font-size:.65rem;"><?=$pctUsado?>% utilizado</div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>Segmentos de Red IP</h5>
  <div class="d-flex gap-2 flex-wrap">
    <a href="exports/export_segmentos_ip_xls.php" class="btn btn-sm btn-outline-success" title="Exportar a Excel">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
    <a href="exports/export_segmentos_ip_pdf.php" target="_blank" class="btn btn-sm btn-outline-danger" title="Exportar a PDF">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </a>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary" title="Imprimir">
      <i class="bi bi-printer me-1"></i>Imprimir
    </button>
    <?php if(isAdmin()): ?>
    <a href="segmentos_ip.php?action=new" class="btn btn-sm" style="background:#1a3a5c;color:#fff;">
      <i class="bi bi-plus me-1"></i>Nuevo Segmento
    </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($action === 'list'): ?>
<!-- Lista de segmentos -->
<div class="card mb-4">
<div class="card-body p-0">
<table class="table table-ti mb-0">
  <thead><tr><th>Color</th><th>Nombre</th><th>Red</th><th>Máscara</th><th>Gateway</th><th>VLAN</th><th>IPs Usadas</th><th>Libres</th><th class="no-print">Acc.</th></tr></thead>
  <tbody>
  <?php foreach ($segmentos as $s):
    $sIps = array_filter($ipStats, fn($x)=>$x['id']==$s['id']);
    $sIps = reset($sIps) ?: ['usadas'=>0,'libres'=>254];
  ?>
  <tr>
    <td><span style="width:18px;height:18px;background:<?=h($s['color'])?>;border-radius:3px;display:inline-block;border:1px solid #ccc;"></span></td>
    <td><strong><?=h($s['nombre'])?></strong><?php if($s['descripcion']): ?><br><span class="text-muted small"><?=h(mb_substr($s['descripcion'],0,50))?></span><?php endif; ?></td>
    <td><code><?=h($s['red'])?>.0/24</code></td>
    <td class="small"><?=h($s['mascara']??'')?></td>
    <td><code class="small"><?=h($s['gateway']??'—')?></code></td>
    <td><span class="badge bg-secondary small"><?=h($s['vlan']??'—')?></span></td>
    <td><span class="badge bg-danger"><?=$sIps['usadas']?></span></td>
    <td><span class="badge bg-success"><?=$sIps['libres']?></span></td>
    <td class="no-print">
      <a href="segmentos_ip.php?action=ips&id=<?=$s['id']?>" class="btn btn-xs btn-outline-info me-1" title="Ver IPs"><i class="bi bi-list-ol"></i></a>
      <?php if(isAdmin()): ?>
      <a href="segmentos_ip.php?action=edit&id=<?=$s['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
      <button onclick="confirmDelete('segmentos_ip.php?action=delete&id=<?=$s['id']?>','<?=addslashes($s['nombre'])?>')" class="btn btn-xs btn-outline-danger"><i class="bi bi-trash"></i></button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if(empty($segmentos)): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin segmentos registrados</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div>

<!-- Mapa de IPs por segmento -->
<?php foreach ($segmentos as $s):
  $ipsUsadas = getIpsUsadasPorSegmento($s['red']);
  if (empty($ipsUsadas)) continue;
  $collapseIdUsadas = 'collapse-usadas-'.$s['id'];
?>
<div class="card mb-3">
  <div class="card-header-ti d-flex justify-content-between align-items-center" style="background:<?=h($s['color'])?>;cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#<?=$collapseIdUsadas?>" aria-expanded="false" aria-controls="<?=$collapseIdUsadas?>">
    <span><i class="bi bi-diagram-3 me-2"></i>IPs Asignadas — <?=h($s['nombre'])?> (<?=h($s['red'])?>.*)</span>
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-light text-dark"><?=count($ipsUsadas)?> asignadas</span>
      <i class="bi bi-chevron-down"></i>
    </div>
  </div>
  <div class="collapse" id="<?=$collapseIdUsadas?>">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-ti table-sm mb-0">
      <thead><tr><th>IP</th><th>Folio Equipo</th><th>Marca / Modelo</th><th>Usuario</th></tr></thead>
      <tbody>
      <?php foreach($ipsUsadas as $ip): ?>
      <tr>
        <td><code class="fw-bold" style="color:<?=h($s['color'])?>"><?=h($ip['ip'])?></code></td>
        <td><code class="text-primary small"><?=h($ip['folio'])?></code></td>
        <td class="small"><?=h($ip['marca'].' '.($ip['modelo']??''))?></td>
        <td class="small"><?=h(trim($ip['usuario']??''))?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  </div>
</div>
<?php endforeach; ?>

<!-- IPs Libres por segmento -->
<?php foreach ($segmentos as $s):
  $ipsLibres = getIpsLibresPorSegmento($s['red']);
  if (empty($ipsLibres)) continue;
  $collapseIdLibres = 'collapse-libres-'.$s['id'];
?>
<div class="card mb-3">
  <div class="card-header-ti d-flex justify-content-between align-items-center" style="background:<?=h($s['color'])?>;cursor:pointer;" data-bs-toggle="collapse" data-bs-target="#<?=$collapseIdLibres?>" aria-expanded="false" aria-controls="<?=$collapseIdLibres?>">
    <span><i class="bi bi-check-circle me-2"></i>IPs Libres — <?=h($s['nombre'])?> (<?=h($s['red'])?>.*)</span>
    <div class="d-flex align-items-center gap-2">
      <span class="badge bg-success text-white"><?=count($ipsLibres)?> disponibles</span>
      <i class="bi bi-chevron-down"></i>
    </div>
  </div>
  <div class="collapse" id="<?=$collapseIdLibres?>">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-ti table-sm mb-0">
      <thead><tr><th>IP</th><th>Estado</th></tr></thead>
      <tbody>
      <?php foreach($ipsLibres as $ip): ?>
      <tr>
        <td><code class="fw-bold text-success"><?=h($ip['ip'])?></code></td>
        <td><span class="badge bg-success">Disponible</span></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  </div>
</div>
<?php endforeach; ?>

<?php elseif ($action === 'ips' && $segmentoIps): ?>
<!-- Vista de IPs del segmento -->
<div class="row mb-3">
  <div class="col-12">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <a href="segmentos_ip.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Volver a Segmentos
      </a>
      <div class="d-flex gap-2 flex-wrap">
        <a href="exports/export_segmentos_ip_xls.php?id=<?=$segmentoIps['id']?>" class="btn btn-sm btn-outline-success" title="Exportar a Excel">
          <i class="bi bi-file-earmark-excel me-1"></i>Excel
        </a>
        <a href="exports/export_segmentos_ip_pdf.php?id=<?=$segmentoIps['id']?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Exportar a PDF">
          <i class="bi bi-file-earmark-pdf me-1"></i>PDF
        </a>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary" title="Imprimir">
          <i class="bi bi-printer me-1"></i>Imprimir
        </button>
      </div>
    </div>
    <div class="card">
      <div class="card-header-ti d-flex justify-content-between align-items-center" style="background:<?=h($segmentoIps['color'])?>;">
        <span><i class="bi bi-diagram-3 me-2"></i>Detalle de IPs — <?=h($segmentoIps['nombre'])?> (<?=h($segmentoIps['red'])?>.0/24)</span>
      </div>
      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-3">
            <div class="text-center p-3 border rounded">
              <div class="h3 mb-1 text-danger"><?=count(getIpsUsadasPorSegmento($segmentoIps['red']))?></div>
              <div class="text-muted small">USADAS</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center p-3 border rounded">
              <div class="h3 mb-1 text-success"><?=count(getIpsLibresPorSegmento($segmentoIps['red']))?></div>
              <div class="text-muted small">LIBRES</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center p-3 border rounded">
              <div class="h3 mb-1">254</div>
              <div class="text-muted small">TOTAL</div>
            </div>
          </div>
          <div class="col-md-3">
            <div class="text-center p-3 border rounded">
              <div class="h3 mb-1"><?=round((count(getIpsUsadasPorSegmento($segmentoIps['red']))/254)*100,1)?>%</div>
              <div class="text-muted small">UTILIZADO</div>
            </div>
          </div>
        </div>
        
        <!-- Tabs para cambiar entre Usadas y Libres -->
        <ul class="nav nav-tabs mb-3" id="ipsTabs" role="tablist">
          <li class="nav-item">
            <button class="nav-link active" id="usadas-tab" data-bs-toggle="tab" data-bs-target="#usadas" type="button">
              <i class="bi bi-diagram-3 me-1"></i>IPs Asignadas
            </button>
          </li>
          <li class="nav-item">
            <button class="nav-link" id="libres-tab" data-bs-toggle="tab" data-bs-target="#libres" type="button">
              <i class="bi bi-check-circle me-1"></i>IPs Libres
            </button>
          </li>
        </ul>
        
        <div class="tab-content">
          <!-- IPs Usadas -->
          <div class="tab-pane fade show active" id="usadas">
            <?php $ipsUsadas = getIpsUsadasPorSegmento($segmentoIps['red']); ?>
            <?php if(empty($ipsUsadas)): ?>
              <div class="text-center text-muted py-4">No hay IPs asignadas en este segmento</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-ti table-sm mb-0">
              <thead><tr><th>IP</th><th>Folio Equipo</th><th>Marca / Modelo</th><th>Usuario</th></tr></thead>
              <tbody>
              <?php foreach($ipsUsadas as $ip): ?>
              <tr>
                <td><code class="fw-bold" style="color:<?=h($segmentoIps['color'])?>"><?=h($ip['ip'])?></code></td>
                <td><code class="text-primary small"><?=h($ip['folio'])?></code></td>
                <td class="small"><?=h($ip['marca'].' '.($ip['modelo']??''))?></td>
                <td class="small"><?=h(trim($ip['usuario']??''))?></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <?php endif; ?>
          </div>
          
          <!-- IPs Libres -->
          <div class="tab-pane fade" id="libres">
            <?php $ipsLibres = getIpsLibresPorSegmento($segmentoIps['red']); ?>
            <?php if(empty($ipsLibres)): ?>
              <div class="text-center text-muted py-4">No hay IPs libres en este segmento</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-ti table-sm mb-0">
              <thead><tr><th>IP</th><th>Estado</th></tr></thead>
              <tbody>
              <?php foreach($ipsLibres as $ip): ?>
              <tr>
                <td><code class="fw-bold text-success"><?=h($ip['ip'])?></code></td>
                <td><span class="badge bg-success">Disponible</span></td>
              </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- Vista de Nodos -->


<?php elseif (in_array($action,['new','edit'])): ?>
<!-- Form -->
<div class="row justify-content-center"><div class="col-md-6">
<div class="card">
  <div class="card-header-ti"><?=$isEdit?'Editar':'Nuevo'?> Segmento IP</div>
  <div class="card-body">
    <form method="POST" action="segmentos_ip.php">
      <?php if($isEdit): ?><input type="hidden" name="id" value="<?=$seg['id']?>"><?php endif; ?>
      <div class="row g-3">
        <div class="col-12"><label class="form-label">Nombre del Segmento *</label>
          <input type="text" name="nombre" class="form-control" value="<?=h($seg['nombre']??'')?>" required placeholder="Ej: Segmento Oficinas"></div>
        <div class="col-md-6"><label class="form-label">Red (primeros 3 octetos) *</label>
          <div class="input-group">
            <input type="text" name="red" class="form-control" value="<?=h($seg['red']??'')?>" required placeholder="10.27.41" pattern="\d+\.\d+\.\d+">
            <span class="input-group-text text-muted">.0/24</span>
          </div>
          <div class="form-text">Ej: 10.27.41 ó 10.27.45</div>
        </div>
        <div class="col-md-6"><label class="form-label">Máscara de Subred</label>
          <input type="text" name="mascara" class="form-control" value="<?=h($seg['mascara']??'255.255.255.0')?>"></div>
        <div class="col-md-6"><label class="form-label">Gateway</label>
          <input type="text" name="gateway" class="form-control" value="<?=h($seg['gateway']??'')?>" placeholder="Ej: 10.27.41.1"></div>
        <div class="col-md-3"><label class="form-label">DNS Primario</label>
          <input type="text" name="dns1" class="form-control" value="<?=h($seg['dns1']??'')?>"></div>
        <div class="col-md-3"><label class="form-label">DNS Secundario</label>
          <input type="text" name="dns2" class="form-control" value="<?=h($seg['dns2']??'')?>"></div>
        <div class="col-md-4"><label class="form-label">VLAN</label>
          <input type="text" name="vlan" class="form-control" value="<?=h($seg['vlan']??'')?>" placeholder="Ej: VLAN 41"></div>
        <div class="col-md-4"><label class="form-label">Color identificador</label>
          <input type="color" name="color" class="form-control form-control-color w-100" value="<?=h($seg['color']??'#1a3a5c')?>"></div>
        <div class="col-12"><label class="form-label">Descripción</label>
          <textarea name="descripcion" class="form-control" rows="2"><?=h($seg['descripcion']??'')?></textarea></div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i class="bi bi-check-lg me-1"></i>Guardar</button>
        <a href="segmentos_ip.php" class="btn btn-outline-secondary">Cancelar</a>
      </div>
    </form>
  </div>
</div></div></div>
<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

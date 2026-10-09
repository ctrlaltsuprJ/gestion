<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'includes/auth.php';
requireRol(ROL_ADMIN, ROL_SUPER);

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

if ($action === 'suggest_windows') {
    $nombre = trim($_GET['nombre'] ?? '');
    $apellidos = trim($_GET['apellidos'] ?? '');
    $excludeId = (int)($_GET['exclude_id'] ?? 0) ?: null;
    jsonOut(sugerirUsuarioWindows($nombre, $apellidos, $excludeId));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_GET['accion'])) {
    $pid   = (int)($_POST['id'] ?? 0) ?: null;
    $crearOtro = !$pid && (int)($_POST['crear_otro'] ?? 0) === 1;
    $saved = saveUsuario($_POST, $pid);
    auditLog($pid ? 'EDITAR_USUARIO' : 'CREAR_USUARIO', 'usuarios', $saved);
    flash('success', $pid ? 'Usuario actualizado.' : 'Usuario creado.');
    if ($crearOtro) {
        redirect('usuarios.php?action=new');
    }
    redirect('usuarios.php?action=view&id='.$saved);
}
if ($action === 'delete' && $id) {
    requireRol(ROL_ADMIN);
    deleteUsuario($id);
    auditLog('DESACTIVAR_USUARIO','usuarios',$id);
    flash('warning','Usuario desactivado.');
    redirect('usuarios.php');
}

// Manejar POST de asignación/reemplazo de equipo
if (isset($_GET['accion']) && $_GET['accion']==='guardar_asignacion' && $_SERVER['REQUEST_METHOD']==='POST') {
    $usuarioId = (int)($_GET['id'] ?? 0);
    if (!$usuarioId) {
        flash('danger','ID de usuario no válido.');
        redirect('usuarios.php');
    }
    $auth = authCheck();
    $_POST['usuario_id']     = $usuarioId;
    $_POST['registrado_por'] = $auth['nombre'] ?? 'Sistema';
    $saved = registrarAsignacion($_POST);
    if (is_int($saved) && $saved > 0) {
        auditLog('ASIGNACION_EQUIPO','usuarios',$usuarioId,'Tipo: '.($_POST['tipo']??'').' — Motivo: '.($_POST['motivo']??''));
        flash('success','Asignación/reemplazo registrado correctamente. El equipo anterior pasó a INACTIVO.');
    } elseif (is_array($saved) && ($saved['error'] ?? '') === 'activo') {
        $_SESSION['asig_error'] = $saved;
        flash('warning','El equipo seleccionado ya está ACTIVO y asignado. Elige un equipo INACTIVO o registra uno nuevo.');
    } else {
        flash('danger','Error al registrar la asignación. Verifica los datos.');
    }
    redirect("usuarios.php?action=view&id=$usuarioId");
}

$pageTitle = 'Usuarios';
include 'includes/header.php';

$areas   = getAreas();
$puestos = getPuestos();
$q        = trim($_GET['q'] ?? '');
$areaFil  = (int)($_GET['area'] ?? 0);
$generoFil = trim($_GET['genero'] ?? '');

/** Orden de listado: solo claves permitidas → fragmento SQL ORDER BY */
$usuariosListOrdenSql = [
    'area_nombre' => 'a.nombre ASC, u.nombre ASC, u.apellidos ASC',
    'jerarquia_global' => "(CASE TRIM(COALESCE(p.nivel,''))
        WHEN 'DIRECTIVO' THEN 1 WHEN 'SUBDIRECTIVO' THEN 2 WHEN 'JEFATURA' THEN 3
        WHEN 'COORDINACION' THEN 4 WHEN 'OPERATIVO' THEN 5 WHEN 'APOYO' THEN 6 ELSE 7 END),
        a.nombre ASC, u.nombre ASC, u.apellidos ASC",
    'jerarquia_area' => "a.nombre ASC, (CASE TRIM(COALESCE(p.nivel,''))
        WHEN 'DIRECTIVO' THEN 1 WHEN 'SUBDIRECTIVO' THEN 2 WHEN 'JEFATURA' THEN 3
        WHEN 'COORDINACION' THEN 4 WHEN 'OPERATIVO' THEN 5 WHEN 'APOYO' THEN 6 ELSE 7 END),
        u.nombre ASC, u.apellidos ASC",
    'area_cubiculo' => 'a.nombre ASC, u.`cubículo` ASC, u.oficina ASC, u.nombre ASC, u.apellidos ASC',
    'cubiculo_area' => 'u.`cubículo` ASC, a.nombre ASC, u.oficina ASC, u.nombre ASC, u.apellidos ASC',
    'nombre' => 'u.nombre ASC, u.apellidos ASC',
    'puesto_nombre' => 'p.nombre ASC, u.nombre ASC, u.apellidos ASC',
];
$orden = (string)($_GET['orden'] ?? 'area_nombre');
if (!isset($usuariosListOrdenSql[$orden])) {
    $orden = 'area_nombre';
}
?>
<style>
.avatar-circle-lg { width:58px;height:58px;border-radius:50%;background:rgba(255,255,255,.22);
  display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1.3rem;
  color:#fff;border:3px solid rgba(255,255,255,.4);flex-shrink:0;letter-spacing:-1px; }
.section-divider { border-bottom:2px solid #e9ecef;margin-bottom:4px;padding-bottom:4px; }
.section-label { font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#1a3a5c; }
.ext-ip-badge { background:#c0392b;color:#fff;font-weight:700;border-radius:5px;padding:3px 10px;font-size:.82rem; }
td:hover .windows-credentials { display: inline !important; }
</style>

<div class="container-fluid px-3 py-3">

<?php if ($action === 'list'): ?>
<!-- ══ LISTA ══ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-people me-2"></i>Usuarios del Sistema</h5>
  <div class="d-flex gap-2">
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary no-print">
      <i class="bi bi-printer me-1"></i>Imprimir
    </button>
   <a href="exports/export_usuarios_xls.php?q=<?=urlencode($q)?>&area=<?=$areaFil?>&genero=<?=urlencode($generoFil)?>&orden=<?=urlencode($orden)?>" class="btn btn-sm btn-outline-success no-print">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
    <a href="directorio.php" class="btn btn-sm btn-outline-info no-print">
      <i class="bi bi-telephone-fill me-1"></i>Directorio IP
    </a>
    <?php if (puede('crear_usuario')): ?>
    <a href="usuarios.php?action=new" class="btn btn-sm no-print" style="background:#1a3a5c;color:#fff;">
      <i class="bi bi-person-plus me-1"></i>Nuevo Usuario
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-3"><div class="card-body py-2">
  <form id="form-search" method="GET" action="usuarios.php" class="row g-2 align-items-end">
    <div class="col-sm-4">
      <input type="search" name="q" id="input-search" class="form-control form-control-sm"
             placeholder="Nombre, ext. IP, email, cubículo…" value="<?= h($q) ?>">
    </div>
    <div class="col-sm-3">
      <select name="area" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrar por área">
        <option value="">— Todas las áreas —</option>
        <?php foreach ($areas as $a): ?>
        <option value="<?= $a['id'] ?>" <?= $areaFil==(int)$a['id']?'selected':'' ?>><?= h($a['nombre']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-auto">
      <select name="genero" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filtrar por género">
        <option value="" <?= $generoFil===''?'selected':'' ?>>— Género —</option>
        <option value="H" <?= $generoFil==='H'?'selected':'' ?>>♂ Hombre</option>
        <option value="M" <?= $generoFil==='M'?'selected':'' ?>>♀ Mujer</option>
        <option value="X" <?= $generoFil==='X'?'selected':'' ?>>— Sin especificar</option>
      </select>
    </div>
    <div class="col-sm-3">
      <select name="orden" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Ordenar listado" title="Criterio de ordenación de la tabla">
        <option value="area_nombre" <?= $orden==='area_nombre'?'selected':'' ?>>Área, luego nombre</option>
        <option value="jerarquia_global" <?= $orden==='jerarquia_global'?'selected':'' ?>>Jerarquía (todo el listado)</option>
        <option value="jerarquia_area" <?= $orden==='jerarquia_area'?'selected':'' ?>>Por área: jerarquía y nombre</option>
        <option value="area_cubiculo" <?= $orden==='area_cubiculo'?'selected':'' ?>>Área → cubículo / oficina</option>
        <option value="cubiculo_area" <?= $orden==='cubiculo_area'?'selected':'' ?>>Cubículo → área</option>
        <option value="puesto_nombre" <?= $orden==='puesto_nombre'?'selected':'' ?>>Puesto (catálogo), luego nombre</option>
        <option value="nombre" <?= $orden==='nombre'?'selected':'' ?>>Nombre (A‑Z)</option>
      </select>
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-primary" aria-label="Buscar"><i class="bi bi-search"></i></button>
      <?php if ($q||$areaFil||$generoFil||$orden!=='area_nombre'): ?><a href="usuarios.php" class="btn btn-sm btn-outline-secondary" title="Quitar filtros y orden personalizado"><i class="bi bi-x"></i></a><?php endif; ?>
    </div>
  </form>
</div></div>

<?php
$db = getDB(); $like='%'.$q.'%';
$sql = "SELECT u.*, a.nombre area_nombre, p.nombre puesto_nombre, p.nivel puesto_nivel,
        (SELECT COUNT(*) FROM equipos e WHERE e.usuario_id=u.id AND e.activo=1) n_equipos
        FROM usuarios u
        LEFT JOIN areas a ON a.id=u.area_id
        LEFT JOIN cat_puestos p ON p.id=u.puesto_id
        WHERE u.activo=1";
$params=[];
if($q){
    $sql.=" AND (u.nombre LIKE ? OR u.apellidos LIKE ?
                 OR TRIM(CONCAT(COALESCE(u.nombre,''),' ',COALESCE(u.apellidos,''))) LIKE ?
                 OR u.email LIKE ? OR u.usuario_windows LIKE ?
                 OR u.extension_ip LIKE ? OR u.cubículo LIKE ? OR u.oficina LIKE ? OR u.puesto LIKE ?)";
    $params=array_fill(0,9,$like);
}
if($areaFil){$sql.=" AND u.area_id=?";$params[]=$areaFil;}
if($generoFil==='X'){$sql.=" AND (u.genero IS NULL OR u.genero='')"; }
elseif($generoFil!==''){$sql.=" AND u.genero=?";$params[]=$generoFil;}
$sql.=' ORDER BY '.$usuariosListOrdenSql[$orden];
$st=$db->prepare($sql);$st->execute($params);$users=$st->fetchAll();
?>

<div class="card">
<div class="card-body p-0">
<div class="table-responsive">
<table class="table table-ti mb-0">
  <thead><tr>
    <th>Nombre</th><th>Puesto</th><th>Área</th>
    <th>Ext. IP</th><th>Cubículo / Oficina</th>
    <th>Tel. Directo</th><th>Email</th>
    <th class="text-center">Equipos</th><th class="no-print">Acc.</th>
  </tr></thead>
  <tbody>
  <?php foreach ($users as $u):
    $nb=['DIRECTIVO'=>'bg-danger','SUBDIRECTIVO'=>'bg-warning text-dark','JEFATURA'=>'bg-primary',
         'COORDINACION'=>'bg-info text-dark','OPERATIVO'=>'bg-success','APOYO'=>'bg-secondary'][$u['puesto_nivel']??'']??'bg-secondary';
  ?>
  <tr style="cursor:pointer" onclick="location.href='usuarios.php?action=view&id=<?=$u['id']?>'">
    <td>
      <strong><?=h($u['nombre'].' '.($u['apellidos']??''))?></strong>
      <?php if($u['usuario_windows']): ?>
        <button type="button" class="btn btn-xs btn-link p-0 me-1 text-muted" onclick="event.stopPropagation(); this.nextElementSibling.classList.toggle('d-none')" title="Mostrar/Ocultar credenciales">
          <i class="bi bi-eye"></i>
        </button>
        <span class="d-none windows-credentials" style="font-size:.65rem;">
          <code class="text-muted"><?=h($u['usuario_windows'])?></code><?php if($u['contrasena_ref']): ?> - <span class="text-muted"><?=h($u['contrasena_ref'])?></span><?php endif; ?>
        </span>
      <?php endif; ?>
      </td>
    <td>
      <?php if($u['puesto_nombre']): ?><span class="badge <?=$nb?>" style="font-size:.65rem;"><?=h($u['puesto_nombre'])?></span>
      <?php elseif($u['puesto']): ?><span class="small text-muted"><?=h($u['puesto'])?></span><?php endif; ?>
    </td>
    <td class="small"><?=h($u['area_nombre']??'')?></td>
    <td><?php if($u['extension_ip']): ?><span class="ext-ip-badge"><?=h($u['extension_ip'])?></span><?php else: ?><span class="text-muted small">—</span><?php endif; ?></td>
    <td class="small">
      <?php $ubi=array_filter([$u['cubículo']?'<i class="bi bi-grid-1x2 me-1 text-muted"></i>'.h($u['cubículo']):'', $u['oficina']?'<i class="bi bi-building me-1 text-muted"></i>'.h($u['oficina']):'']); echo implode(' · ',$ubi)?:'<span class="text-muted">—</span>'; ?>
    </td>
    <td class="small text-primary"><?=h($u['telefono_directo']??$u['telefono']??'')?></td>
    <td class="small"><?=h($u['email']??'')?></td>
    <td class="text-center"><span class="badge bg-primary"><?=$u['n_equipos']?></span></td>
    <td class="no-print" onclick="event.stopPropagation()">
      <a href="usuarios.php?action=edit&id=<?=$u['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="bi bi-pencil"></i></a>
      <?php if(puede('eliminar_usuario')): ?>
      <button onclick="confirmDelete('usuarios.php?action=delete&id=<?=$u['id']?>','<?=addslashes($u['nombre'])?>')" class="btn btn-xs btn-outline-danger"><i class="bi bi-person-x"></i></button>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if(empty($users)): ?><tr><td colspan="9" class="text-center text-muted py-4">Sin usuarios registrados</td></tr><?php endif; ?>
  </tbody>
</table>
</div></div></div>
<div class="text-muted small mt-2 px-1"><?=count($users)?> usuario(s)</div>

<?php elseif(in_array($action,['new','edit'])): ?>
<!-- ══ FORM ══ -->
<?php $u=$action==='edit'&&$id?getUsuarioById($id):[]; $isEdit=!empty($u); ?>
<div class="row justify-content-center">
<div class="col-xl-9">
<div class="card">
  <div class="card-header-ti">
    <i class="bi bi-person<?=$isEdit?'-gear':'-plus'?> me-2"></i>
    <?=$isEdit?'Editar Usuario — '.h($u['nombre'].' '.($u['apellidos']??'')):'Nuevo Usuario'?>
  </div>
  <div class="card-body">
    <form method="POST" action="usuarios.php" id="form-usuario">
      <?php if($isEdit): ?><input type="hidden" name="id" value="<?=$u['id']?>"><?php endif; ?>
      <input type="hidden" name="crear_otro" id="crear_otro" value="0">

      <!-- Datos personales -->
      <p class="section-label"><i class="bi bi-person me-1"></i>Datos Personales</p>
      <div class="row g-3 mb-4">
        <div class="col-md-5"><label class="form-label">Nombre(s) <span class="text-danger">*</span></label>
          <input type="text" name="nombre" class="form-control" value="<?=h($u['nombre']??'')?>" required></div>
        <div class="col-md-5"><label class="form-label">Apellidos</label>
          <input type="text" name="apellidos" class="form-control" value="<?=h($u['apellidos']??'')?>"></div>
        <div class="col-md-4"><label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?=h($u['email']??'')?>"></div>
        <div class="col-md-4"><label class="form-label">Teléfono</label>
          <input type="text" name="telefono" class="form-control" value="<?=h($u['telefono']??'')?>"></div>
        <div class="col-md-4"><label class="form-label">Teléfono Directo</label>
          <input type="text" name="telefono_directo" class="form-control" value="<?=h($u['telefono_directo']??'')?>" placeholder="Ej. 2288423661"></div>
        <div class="col-md-2">
          <label class="form-label"><i class="bi bi-person-fill me-1"></i>Género</label>
          <select name="genero" class="form-select">
            <option value="" <?=($u['genero']??'')==''?'selected':''?>>— Sin especificar —</option>
            <option value="H" <?=($u['genero']??'')==='H'?'selected':''?>>
              <i class="bi bi-gender-male"></i> Hombre
            </option>
            <option value="M" <?=($u['genero']??'')==='M'?'selected':''?>>
              <i class="bi bi-gender-female"></i> Mujer
            </option>
          </select>
        </div>
      </div>

      <!-- Puesto y ubicación -->
      <p class="section-label"><i class="bi bi-building me-1"></i>Puesto y Ubicación</p>
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <label class="form-label">Área / Departamento</label>
          <select name="area_id" class="form-select">
            <option value="">— Sin área —</option>
            <?php foreach($areas as $a): ?>
            <option value="<?=$a['id']?>" <?=(int)($u['area_id']??0)==(int)$a['id']?'selected':''?>><?=h($a['nombre'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Puesto (Catálogo)
            <a href="puestos.php" target="_blank" class="btn btn-xs btn-outline-secondary ms-1" title="Gestionar catálogo"><i class="bi bi-gear"></i></a>
          </label>
          <select name="puesto_id" class="form-select">
            <option value="">— Sin puesto del catálogo —</option>
            <?php
            $nivelIcons=['DIRECTIVO'=>'🔴 ','SUBDIRECTIVO'=>'🟠 ','JEFATURA'=>'🔵 ','COORDINACION'=>'🟢 ','OPERATIVO'=>'⚪ ','APOYO'=>'⚫ '];
            foreach($puestos as $p): ?>
            <option value="<?=$p['id']?>" <?=(int)($u['puesto_id']??0)==(int)$p['id']?'selected':''?>>
              <?=($nivelIcons[$p['nivel']]??'')?><?=h($p['nombre'])?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Puesto (Texto libre)</label>
          <input type="text" name="puesto" class="form-control" value="<?=h($u['puesto']??'')?>" placeholder="Ej. ENCARGADA DPI">
        </div>
        <div class="col-md-3">
          <label class="form-label"><i class="bi bi-grid-1x2 me-1"></i>Cubículo</label>
          <input type="text" name="cubículo" class="form-control" value="<?=h($u['cubículo']??'')?>" placeholder="Ej. CUB 3 ENTORNO">
        </div>
        <div class="col-md-3">
          <label class="form-label"><i class="bi bi-building me-1"></i>Oficina</label>
          <input type="text" name="oficina" class="form-control" value="<?=h($u['oficina']??'')?>" placeholder="Ej. OFNA. RECURSOS HUMANOS">
        </div>
      </div>

      <!-- Extensión IP -->
      <p class="section-label"><i class="bi bi-telephone me-1"></i>Extensión Telefónica IP</p>
      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <label class="form-label"><span class="ext-ip-badge">IP</span>&nbsp;Extensión IP</label>
          <div class="input-group">
            <span class="input-group-text" style="background:#c0392b;border-color:#c0392b;"><i class="bi bi-telephone-fill text-white"></i></span>
            <input type="text" name="extension_ip" class="form-control" value="<?=h($u['extension_ip']??'')?>" placeholder="Ej. 19050" maxlength="20">
          </div>
          <div class="form-text">Número de extensión del teléfono IP</div>
        </div>
        <div class="col-md-8">
          <label class="form-label">Nota</label>
          <input type="text" class="form-control" readonly
                 value="La extensión se vinculará automáticamente al Directorio Telefónico IP"
                 style="background:#f8f9fa;color:#6c757d;">
        </div>
      </div>

      <!-- Acceso al sistema -->
      <p class="section-label"><i class="bi bi-shield-lock me-1"></i>Acceso al Sistema</p>
      <div class="row g-3 mb-3">
        <div class="col-md-4">
          <label class="form-label">Usuario Windows</label>
          <div class="input-group">
            <span class="input-group-text"><i class="bi bi-windows"></i></span>
            <input type="text" name="usuario_windows" id="usuario_windows" class="form-control" value="<?=h($u['usuario_windows']??'')?>" placeholder="Ej. hsantillano">
          </div>
          <div class="form-text" id="usuario_windows_hint">Formato: inicial nombre + apellido + inicial segundo apellido (minúsculas).</div>
        </div>
        <div class="col-md-3">
          <label class="form-label">ID Biométrico (Admin)</label>
          <input type="text" name="biometrico_id" id="biometrico_id" class="form-control" inputmode="numeric" pattern="[0-9]*" placeholder="Ej. 1001">
          <div class="form-text">Normalmente 10(1-100), luego 11(1-100), etc.</div>
        </div>
        <div class="col-md-5">
          <label class="form-label">Contraseña (referencia)</label>
          <input type="text" name="contrasena_ref" id="contrasena_ref" class="form-control" value="<?=h($u['contrasena_ref']??'')?>" placeholder="⚠ No usar en producción">
        </div>
      </div>

      <div class="d-flex gap-2 mt-4">
        <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;">
          <i class="bi bi-check-lg me-1"></i><?=$isEdit?'Guardar Cambios':'Crear Usuario'?>
        </button>
        <a href="<?=$isEdit?'usuarios.php?action=view&id='.$id:'usuarios.php'?>" class="btn btn-outline-secondary">Cancelar</a>
        <?php if($isEdit&&$u['extension_ip']): ?>
        <a href="directorio.php?buscar=<?=urlencode($u['extension_ip']??'')?>" class="btn btn-outline-info ms-auto no-print">
          <i class="bi bi-telephone-fill me-1"></i>Ver en Directorio IP
        </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div></div></div>
<script>
(function () {
  const nombre = document.querySelector('input[name="nombre"]');
  const apellidos = document.querySelector('input[name="apellidos"]');
  const usuarioWindows = document.getElementById('usuario_windows');
  const hintUsuarioWindows = document.getElementById('usuario_windows_hint');
  const biometrico = document.getElementById('biometrico_id');
  const contrasenaRef = document.getElementById('contrasena_ref');
  if (!apellidos || !biometrico || !contrasenaRef || !nombre || !usuarioWindows || !hintUsuarioWindows) return;
  let usuarioWindowsTocado = usuarioWindows.value.trim() !== '';

  async function sugerirUsuarioWindows() {
    const n = (nombre.value || '').trim();
    const a = (apellidos.value || '').trim();
    if (!n || !a) {
      hintUsuarioWindows.textContent = 'Formato: inicial nombre + apellido + inicial segundo apellido (minúsculas).';
      return;
    }
    const params = new URLSearchParams({
      action: 'suggest_windows',
      nombre: n,
      apellidos: a,
      exclude_id: '<?= (int)($u['id'] ?? 0) ?>'
    });
    try {
      const resp = await fetch(`usuarios.php?${params.toString()}`);
      const data = await resp.json();
      if (!usuarioWindowsTocado && data?.suggested) usuarioWindows.value = data.suggested;

      if (data?.base && data?.base === data?.suggested) {
        hintUsuarioWindows.textContent = `Disponible: ${data.base}`;
      } else if (data?.suggested) {
        hintUsuarioWindows.textContent = `Ya existe ${data.base}. Sugerencia disponible: ${data.suggested}`;
      } else {
        hintUsuarioWindows.textContent = `No hay sugerencia inmediata disponible para ${data?.base || 'ese patrón'}.`;
      }
    } catch (_) {
      hintUsuarioWindows.textContent = 'No se pudo validar disponibilidad de usuario Windows.';
    }
  }

  function generarContrasenaRef() {
    const ap = (apellidos.value || '').trim();
    const bio = (biometrico.value || '').replace(/\D+/g, '');
    if (!ap || !bio) return;

    const partes = ap.split(/\s+/).filter(Boolean);
    const p1 = partes[0] || '';
    const p2 = partes[1] || '';
    if (!p1) return;

    const l1 = p1.charAt(0).toUpperCase();
    const l2 = p2 ? p2.charAt(0).toLowerCase() : 'x';
    contrasenaRef.value = `${l1}${l2}13${bio}`;
  }

  usuarioWindows.addEventListener('input', () => { usuarioWindowsTocado = true; });
  nombre.addEventListener('input', sugerirUsuarioWindows);
  apellidos.addEventListener('input', () => {
    sugerirUsuarioWindows();
    generarContrasenaRef();
  });
  sugerirUsuarioWindows();
  biometrico.addEventListener('input', generarContrasenaRef);

  const formUsuario = document.getElementById('form-usuario');
  const crearOtro = document.getElementById('crear_otro');
  const esEdicion = <?= $isEdit ? 'true' : 'false' ?>;
  if (formUsuario && crearOtro && !esEdicion) {
    formUsuario.addEventListener('submit', async function (e) {
      e.preventDefault();
      const result = await Swal.fire({
        icon: 'question',
        title: 'Guardar usuario',
        text: '¿Deseas guardar este usuario y agregar otro?',
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: 'Guardar y agregar otro',
        denyButtonText: 'Guardar y ver usuario',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#1a3a5c',
        denyButtonColor: '#0d6efd'
      });
      if (result.isConfirmed) {
        crearOtro.value = '1';
        formUsuario.submit();
      } else if (result.isDenied) {
        crearOtro.value = '0';
        formUsuario.submit();
      }
    });
  }
})();
</script>

<?php elseif($action==='view'&&$id): ?>
<!-- ══ DETALLE ══ -->
<?php
$u=getUsuarioById($id);
if(!$u){flash('danger','No encontrado.');redirect('usuarios.php');}
$equiposU    = array_filter(getEquipos(),fn($e)=>$e['usuario_id']==$id);
$invU        = getInventario(q:"", equipo:0, usuario:$id);
$ticketsU    = array_filter(getTickets(),fn($t)=>$t['usuario_id']==$id);
$asignaciones= getAsignaciones(usuarioId:$id, equipoId:0);
$todosEquipos= getEquipos(); // para el selector del modal

// Equipos INACTIVOS sin usuario asignado (para sugerencias)
$equiposInactivos = array_values(array_filter($todosEquipos, fn($e) =>
    $e['estado'] === 'INACTIVO' && empty($e['usuario_id'])
));

// Error de asignación previa (si viene de redirect)
$asigError = $_SESSION['asig_error'] ?? null;
unset($_SESSION['asig_error']);
$nb=['DIRECTIVO'=>'bg-danger','SUBDIRECTIVO'=>'bg-warning text-dark','JEFATURA'=>'bg-primary',
     'COORDINACION'=>'bg-info text-dark','OPERATIVO'=>'bg-success','APOYO'=>'bg-secondary'][$u['puesto_nivel']??'']??'bg-secondary';
?>

<!-- Header perfil -->
<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3 p-3 rounded"
     style="background:linear-gradient(135deg,#1a3a5c,#2563a8);color:#fff;">
  <div class="d-flex align-items-center gap-3">
    <div class="avatar-circle-lg"><?=strtoupper(substr($u['nombre'],0,1).substr($u['apellidos']??'',0,1))?></div>
    <div>
      <h4 class="mb-1"><?=h($u['nombre'].' '.($u['apellidos']??''))?></h4>
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <?php if($u['puesto_nombre']): ?><span class="badge <?=$nb?>"><?=h($u['puesto_nombre'])?></span><?php elseif($u['puesto']): ?><span class="badge bg-secondary"><?=h($u['puesto'])?></span><?php endif; ?>
        <?php if($u['area_nombre']): ?><span class="badge bg-light text-dark"><?=h($u['area_nombre'])?></span><?php endif; ?>
        <?php if($u['extension_ip']): ?><span class="badge" style="background:#e8ff47;color:#000;font-weight:700;"><i class="bi bi-telephone-fill me-1"></i>Ext. <?=h($u['extension_ip'])?></span><?php endif; ?>
        <?php if($u['cubículo']||$u['oficina']): ?><span class="badge bg-light text-dark"><i class="bi bi-geo-alt me-1"></i><?=h($u['cubículo']?:$u['oficina'])?></span><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <a href="usuarios.php?action=edit&id=<?=$id?>" class="btn btn-sm btn-light"><i class="bi bi-pencil me-1"></i>Editar</a>
    <button onclick="window.print()" class="btn btn-sm btn-outline-light"><i class="bi bi-printer me-1"></i>Imprimir</button>
    <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#modalReemplazo">
      <i class="bi bi-arrow-left-right me-1"></i>Asignar / Reemplazar Equipo
    </button>
    <a href="tickets.php?action=new" class="btn btn-sm btn-danger"><i class="bi bi-ticket me-1"></i>Nuevo Ticket</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-4">
    <!-- Datos personales -->
    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-person me-2"></i>Información Personal</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <?php
          $generoLabel = match($u['genero'] ?? '') {
            'H' => '<i class="bi bi-gender-male text-primary me-1"></i>Hombre',
            'M' => '<i class="bi bi-gender-female text-danger me-1"></i>Mujer',
            default => null,
          };
          $rows=[
            ['Nombre',h($u['nombre'].' '.($u['apellidos']??''))],
            ['Género', $generoLabel],
            ['Puesto (catálogo)',$u['puesto_nombre']?'<span class="badge '.$nb.'">'.h($u['puesto_nombre']).'</span>':null],
            ['Puesto',h($u['puesto']??'')],
            ['Área',h($u['area_nombre']??'')],
            ['Cubículo',$u['cubículo']?'<i class="bi bi-grid-1x2 me-1 text-muted"></i>'.h($u['cubículo']):null],
            ['Oficina',$u['oficina']?'<i class="bi bi-building me-1 text-muted"></i>'.h($u['oficina']):null],
          ];
          foreach($rows as [$k,$v]): if(!$v) continue; ?>
          <tr><th class="small text-muted ps-3" style="width:40%"><?=$k?></th><td class="small pe-3"><?=$v?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
    <!-- Contacto / Extensión IP -->
    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-telephone me-2"></i>Contacto y Extensión IP</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <?php $cr=[
            ['Extensión IP',$u['extension_ip']?'<span class="ext-ip-badge">'.h($u['extension_ip']).'</span>':null],
            ['Tel. Directo',$u['telefono_directo']?'<a href="tel:'.h($u['telefono_directo']).'">'.h($u['telefono_directo']).'</a>':null],
            ['Tel. Alternativo',$u['telefono']?h($u['telefono']):null],
            ['Email',$u['email']?'<a href="mailto:'.h($u['email']).'">'.h($u['email']).'</a>':null],
          ];
          foreach($cr as [$k,$v]): if(!$v) continue; ?>
          <tr><th class="small text-muted ps-3" style="width:42%"><?=$k?></th><td class="small pe-3"><?=$v?></td></tr>
          <?php endforeach; ?>
        </table>
        <?php if($u['extension_ip']): ?>
        <div class="px-3 pb-2 mt-1">
          <a href="directorio.php?buscar=<?=urlencode($u['extension_ip']??'')?>" class="btn btn-sm btn-outline-primary w-100 no-print">
            <i class="bi bi-telephone-fill me-1"></i>Ver en Directorio IP
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <!-- Acceso -->
    <div class="card">
      <div class="card-header-ti"><i class="bi bi-shield-lock me-2"></i>Acceso al Sistema</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tr><th class="small text-muted ps-3">Usuario Windows</th><td class="small pe-3"><code><?=h($u['usuario_windows']??'—')?></code></td></tr>
           <tr><th class="small text-muted ps-3"> Contraseña</th><td class="small pe-3"><code><?=h($u['contrasena_ref']??'-') ?></code></td></tr>
          <?php if(isAdmin()):
            $db=getDB();
            $sc=$db->prepare("SELECT username,rol FROM system_users WHERE usuario_id=? LIMIT 1");
            $sc->execute([$id]); $sc=$sc->fetch(); ?>
          <tr><th class="small text-muted ps-3">Cuenta Sistema</th><td class="small pe-3"><?=$sc?'<code>'.h($sc['username']).'</code> '.rolBadge($sc['rol']):'<span class="text-muted">Sin cuenta</span>'?></td></tr>
          <?php endif; ?>
          </table>
      </div>
    </div>
  </div>

  <div class="col-md-8">
    <!-- Equipos -->
    <div class="card mb-3">
      <div class="card-header-ti d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pc-display me-2"></i>Equipos Asignados (<?=count($equiposU)?>)</span>
        <?php if(puede('crear_equipo')): ?>
        <a href="equipos.php?action=new" class="btn btn-sm btn-outline-light py-0 no-print"><i class="bi bi-plus me-1"></i>Asignar</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-ti mb-0">
          <thead><tr><th>Folio</th><th>Tipo</th><th>Marca / Modelo</th><th>IP</th><th>Internet</th><th>Estado</th></tr></thead>
          <tbody>
          <?php foreach($equiposU as $e):
            $cls=['ACTIVO'=>'badge-activo','MANTENIMIENTO'=>'badge-mantenimiento','BAJA'=>'badge-baja','INACTIVO'=>'badge-inactivo'][$e['estado']]??'bg-secondary';
            $iCls=['SIN_ACCESO'=>'secondary','RESTRINGIDO'=>'warning text-dark','COMPLETO'=>'success'][$e['tipo_internet']??'SIN_ACCESO']??'secondary';
            $iIco=['SIN_ACCESO'=>'bi-wifi-off','RESTRINGIDO'=>'bi-lock-fill','COMPLETO'=>'bi-globe'][$e['tipo_internet']??'SIN_ACCESO']??'bi-wifi-off';
            $iLbl=['SIN_ACCESO'=>'Sin Acceso','RESTRINGIDO'=>'Rest.','COMPLETO'=>'Completo'][$e['tipo_internet']??'SIN_ACCESO']??'—';
          ?>
          <tr style="cursor:pointer" onclick="location.href='equipos.php?action=view&id=<?=$e['id']?>'">
            <td><code class="text-primary"><?=h($e['folio'])?></code></td>
            <td class="small"><i class="bi <?=h($e['tipo_icono']??'bi-pc-display')?> me-1"></i><?=h($e['tipo_nombre']??'')?></td>
            <td><strong><?=h($e['marca'])?></strong> <span class="text-muted small"><?=h($e['modelo']??'')?></span></td>
            <td><code class="small"><?=h($e['ip']??'')?></code></td>
            <td>
              <?php if(!empty($e['acceso_internet'])): ?>
              <span class="badge bg-<?=$iCls?>" style="font-size:.65rem;"><i class="bi <?=$iIco?> me-1"></i><?=$iLbl?></span>
              <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td><span class="badge <?=$cls?>"><?=h($e['estado'])?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($equiposU)): ?><tr><td colspan="6" class="text-center text-muted py-3 small">Sin equipos asignados</td></tr><?php endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- Periféricos -->
    <?php if(!empty($invU)): ?>
    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-boxes me-2"></i>Periféricos / Inventario (<?=count($invU)?>)</div>
      <div class="card-body p-0">
        <table class="table table-ti mb-0">
          <thead><tr><th>Tipo</th><th>Marca/Modelo</th><th>Serie</th><th>Equipo</th><th>Estado</th></tr></thead>
          <tbody>
          <?php foreach($invU as $i): $ic=['BUENO'=>'badge-bueno','REGULAR'=>'badge-regular','MALO'=>'badge-malo','BAJA'=>'badge-baja'][$i['estado']]??'bg-secondary'; ?>
          <tr><td><?=h($i['tipo'])?></td><td><?=h($i['marca'].' '.($i['modelo']??''))?></td><td><code class="small"><?=h($i['serie']??'')?></code></td><td><code class="small"><?=h($i['equipo_folio']??'—')?></code></td><td><span class="badge <?=$ic?>"><?=h($i['estado'])?></span></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <!-- Tickets -->
    <div class="card mb-3">
      <div class="card-header-ti d-flex justify-content-between align-items-center">
        <span><i class="bi bi-ticket me-2"></i>Tickets (<?=count($ticketsU)?>)</span>
        <a href="tickets.php?action=new" class="btn btn-sm btn-outline-light py-0 no-print"><i class="bi bi-plus me-1"></i>Nuevo</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-ti mb-0">
          <thead><tr><th>Folio</th><th>Tipo</th><th>Descripción</th><th>Prioridad</th><th>Estado</th><th>Fecha</th></tr></thead>
          <tbody>
          <?php foreach(array_slice($ticketsU,0,10) as $t):
            $pc=['URGENTE'=>'badge-urgente','ALTA'=>'badge-alta','MEDIA'=>'badge-media','BAJA'=>'badge-baja-p'][$t['prioridad']]??'bg-secondary';
            $ec=['ABIERTO'=>'badge-abierto','EN_PROCESO'=>'badge-en_proceso','RESUELTO'=>'badge-resuelto','CANCELADO'=>'badge-cancelado'][$t['estado']]??'bg-secondary';
          ?>
          <tr style="cursor:pointer" onclick="location.href='tickets.php?action=view&id=<?=$t['id']?>'">
            <td><code class="text-primary small"><?=h($t['folio_ticket'])?></code></td>
            <td class="small"><?=h(str_replace('_',' ',$t['tipo_servicio']))?></td>
            <td class="small text-muted"><?=h(mb_substr($t['descripcion']??'',0,45))?></td>
            <td><span class="badge <?=$pc?>"><?=h($t['prioridad'])?></span></td>
            <td><span class="badge <?=$ec?>"><?=h(str_replace('_',' ',$t['estado']))?></span></td>
            <td class="small"><?=h(substr($t['fecha_apertura']??'',0,10))?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($ticketsU)): ?><tr><td colspan="6" class="text-center text-muted py-3 small">Sin tickets</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Historial de asignaciones y reemplazos -->
    <div class="card mt-3">
      <div class="card-header-ti d-flex justify-content-between align-items-center">
        <span><i class="bi bi-arrow-left-right me-2"></i>Historial de Equipos / Reemplazos (<?=count($asignaciones)?>)</span>
        <button type="button" class="btn btn-sm btn-outline-light py-0 no-print"
                data-bs-toggle="modal" data-bs-target="#modalReemplazo">
          <i class="bi bi-plus me-1"></i>Registrar
        </button>
      </div>
      <div class="card-body p-0">
        <?php if(empty($asignaciones)): ?>
        <div class="text-center text-muted py-4 small">
          <i class="bi bi-arrow-left-right d-block fs-3 mb-2 opacity-25"></i>
          Sin historial de asignaciones.
          <br><button type="button" class="btn btn-sm btn-outline-primary mt-2 no-print"
                      data-bs-toggle="modal" data-bs-target="#modalReemplazo">
            <i class="bi bi-plus me-1"></i>Registrar primera asignación
          </button>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-ti mb-0">
          <thead><tr>
            <th>Fecha</th><th>Equipo</th><th>IP</th><th>Tipo</th><th>Motivo</th>
            <th>Equipo Anterior</th><th>Fin</th><th>Estado</th><th>Técnico</th>
          </tr></thead>
          <tbody>
          <?php foreach($asignaciones as $a):
            $tipoCls=['ASIGNACION'=>'bg-primary','REEMPLAZO_TEMPORAL'=>'bg-warning text-dark',
                      'REEMPLAZO_DEFINITIVO'=>'bg-danger','DEVOLUCION'=>'bg-secondary',
                      'REASIGNACION'=>'bg-info text-dark'][$a['tipo']]??'bg-secondary';
            $tipoIco=['ASIGNACION'=>'bi-person-check','REEMPLAZO_TEMPORAL'=>'bi-arrow-repeat',
                      'REEMPLAZO_DEFINITIVO'=>'bi-arrow-left-right','DEVOLUCION'=>'bi-box-arrow-left',
                      'REASIGNACION'=>'bi-people-fill'][$a['tipo']]??'bi-question';
            $inetLbl=['SIN_ACCESO'=>'','RESTRINGIDO'=>'🔒','COMPLETO'=>'🌐'][$a['equipo_tipo_internet']??'']??'';
          ?>
          <tr class="<?=$a['es_activa']?'table-active':''?>">
            <td class="small text-nowrap"><?=h($a['fecha_inicio'])?></td>
            <td>
              <a href="equipos.php?action=view&id=<?=$a['equipo_id']?>" class="text-decoration-none">
                <code class="text-primary"><?=h($a['equipo_folio']??'')?></code>
              </a><br>
              <span class="text-muted small"><?=h($a['equipo_marca']??'').' '.($a['equipo_modelo']??'')?></span>
            </td>
            <td><code class="small"><?=h($a['equipo_ip']??'')?></code> <?=$inetLbl?></td>
            <td>
              <span class="badge <?=$tipoCls?>" style="font-size:.65rem;">
                <i class="bi <?=$tipoIco?> me-1"></i><?=h(str_replace('_',' ',$a['tipo']))?>
              </span>
            </td>
            <td class="small"><?=h(str_replace('_',' ',$a['motivo']??''))?></td>
            <td>
              <?php if($a['folio_anterior']): ?>
              <code class="small text-muted"><?=h($a['folio_anterior'])?></code>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="small text-muted"><?=h($a['fecha_fin']??'Vigente')?></td>
            <td>
              <?php if($a['es_activa']): ?>
              <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Activa</span>
              <?php else: ?>
              <span class="badge bg-light text-muted border">Cerrada</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?=h(mb_substr($a['registrado_por']??'',0,20))?></td>
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

<!-- ══ MODAL: Asignar / Reemplazar Equipo ══ -->
<div class="modal fade" id="modalReemplazo" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" action="usuarios.php?action=view&id=<?=$id?>&accion=guardar_asignacion">
        <div class="modal-header" style="background:#1a3a5c;color:#fff;">
          <h5 class="modal-title"><i class="bi bi-arrow-left-right me-2"></i>Asignar / Reemplazar Equipo</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">

            <!-- Tipo de movimiento -->
            <div class="col-12">
              <label class="form-label fw-semibold">Tipo de Movimiento <span class="text-danger">*</span></label>
              <div class="d-flex flex-wrap gap-2" id="tipo-btns">
                <?php foreach([
                  ['ASIGNACION',           'bg-primary',         'bi-person-check',      'Asignación',          'Primera asignación o asignación regular'],
                  ['REEMPLAZO_TEMPORAL',   'bg-warning text-dark','bi-arrow-repeat',      'Reemplazo Temporal',  'Equipo temporal mientras se repara el original'],
                  ['REEMPLAZO_DEFINITIVO', 'bg-danger',          'bi-arrow-left-right',  'Reemplazo Definitivo','El equipo anterior sale de servicio'],
                  ['DEVOLUCION',           'bg-secondary',       'bi-box-arrow-left',    'Devolución',          'El usuario devuelve un equipo'],
                  ['REASIGNACION',         'bg-info text-dark',  'bi-people-fill',       'Reasignación',        'Se cambia de área o departamento'],
                ] as [$val,$cls,$ico,$lbl,$desc]): ?>
                <div class="tipo-btn-wrap">
                  <input type="radio" name="tipo" id="tipo-<?=$val?>" value="<?=$val?>"
                         class="tipo-radio d-none" <?=$val==='ASIGNACION'?'checked':''?>>
                  <label for="tipo-<?=$val?>" class="btn btn-sm btn-outline-secondary tipo-btn-label"
                         data-tipo="<?=$val?>" title="<?=$desc?>">
                    <i class="bi <?=$ico?> me-1"></i><?=$lbl?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Nuevo equipo -->
            <div class="col-md-6" id="row-nuevo-equipo">
              <label class="form-label fw-semibold">Equipo a Asignar <span class="text-danger">*</span></label>
              <select name="equipo_id" class="form-select" id="sel-equipo-nuevo" required>
                <option value="">— Seleccionar equipo —</option>
                <?php foreach($todosEquipos as $eq):
                  $inetBadge = !empty($eq['acceso_internet'])
                    ? (['SIN_ACCESO'=>'','RESTRINGIDO'=>'🔒 ','COMPLETO'=>'🌐 '][$eq['tipo_internet']]??'')
                    : '';
                  $enUso = $eq['usuario_nombre'] && trim($eq['usuario_nombre']) ? ' (asignado a '.trim($eq['usuario_nombre']).')' : '';
                  $estadoEq = $eq['estado'] ?? 'ACTIVO';
                  // Resaltar visualmente: inactivos primero, luego activos sin usuario
                  $disabled = ($estadoEq === 'BAJA') ? 'disabled' : '';
                ?>
                <option value="<?=$eq['id']?>"
                        data-ip="<?=h($eq['ip']??'')?>"
                        data-folio="<?=h($eq['folio']??'')?>"
                        data-internet="<?=h($eq['tipo_internet']??'SIN_ACCESO')?>"
                        data-acceso="<?=$eq['acceso_internet']??0?>"
                        data-estado="<?=h($estadoEq)?>"
                        data-usuario-id="<?=(int)($eq['usuario_id']??0)?>"
                        <?=$eq['usuario_id']==$id?'selected':''?>
                        <?=$disabled?>>
                  [<?=h($estadoEq)?>] <?=$inetBadge?><?=h($eq['folio'].' — '.$eq['marca'].' '.($eq['modelo']??''))?>
                  <?php if($eq['ip']): ?>[<?=h($eq['ip'])?>]<?php endif; ?>
                  <?=$enUso?>
                </option>
                <?php endforeach; ?>
              </select>
              <!-- Panel: lista de equipos INACTIVOS disponibles -->
              <div id="panel-inactivos" class="mt-2 d-none">
                <div class="alert alert-warning py-2 mb-2 small">
                  <i class="bi bi-exclamation-triangle-fill me-1"></i>
                  <strong>El equipo seleccionado ya está ACTIVO</strong> y asignado a otro usuario.
                  Solo puedes asignar equipos en estado <strong>INACTIVO</strong>. Elige uno de la lista o
                  <a href="equipos.php?action=new" target="_blank" class="alert-link">registra un equipo nuevo</a>.
                </div>
                <?php if (!empty($equiposInactivos)): ?>
                <div class="small fw-semibold text-muted mb-1">Equipos INACTIVOS disponibles:</div>
                <div class="table-responsive" style="max-height:160px;overflow-y:auto;">
                  <table class="table table-sm table-bordered mb-0" style="font-size:.75rem;">
                    <thead class="table-secondary"><tr>
                      <th>Folio</th><th>Marca / Modelo</th><th>IP</th><th>Nodo</th><th>Usar</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach($equiposInactivos as $ei): ?>
                    <tr>
                      <td><code><?=h($ei['folio'])?></code></td>
                      <td><?=h($ei['marca'].' '.($ei['modelo']??''))?></td>
                      <td><code class="small"><?=h($ei['ip']??'—')?></code></td>
                      <td class="small"><?=h($ei['nodo']??'—')?></td>
                      <td>
                        <button type="button" class="btn btn-xs btn-outline-primary btn-usar-inactivo"
                                data-id="<?=$ei['id']?>"
                                data-folio="<?=h($ei['folio'])?>">
                          <i class="bi bi-check-lg"></i> Seleccionar
                        </button>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <?php else: ?>
                <div class="text-muted small text-center py-2">
                  <i class="bi bi-inbox d-block fs-4 mb-1 opacity-25"></i>
                  No hay equipos INACTIVOS disponibles.
                  <a href="equipos.php?action=new" target="_blank" class="d-block mt-1">
                    <i class="bi bi-plus-circle me-1"></i>Registrar nuevo equipo
                  </a>
                </div>
                <?php endif; ?>
              </div>
              <div id="preview-equipo-nuevo" class="mt-2 p-2 rounded small d-none"
                   style="background:#f0f4ff;border:1px solid #c7d8f8;">
                <div class="d-flex gap-3 align-items-center flex-wrap">
                  <span><i class="bi bi-hdd-network me-1 text-primary"></i>IP: <code id="prev-ip">—</code></span>
                  <span id="prev-internet-badge"></span>
                </div>
              </div>
            </div>

            <!-- Equipo anterior (para reemplazos) -->
            <div class="col-md-6" id="row-equipo-anterior">
              <label class="form-label fw-semibold">Equipo que se Reemplaza / Retira</label>
              <select name="equipo_anterior_id" class="form-select" id="sel-equipo-anterior">
                <option value="">— Ninguno / No aplica —</option>
                <?php foreach($equiposU as $eq): ?>
                <option value="<?=$eq['id']?>" selected>
                  <?=h($eq['folio'].' — '.$eq['marca'].' '.($eq['modelo']??''))?>
                  <?php if($eq['ip']): ?>[<?=h($eq['ip'])?>]<?php endif; ?>
                </option>
                <?php endforeach; ?>
                <?php foreach($todosEquipos as $eq):
                  if($eq['usuario_id']==$id) continue; ?>
                <option value="<?=$eq['id']?>">
                  <?=h($eq['folio'].' — '.$eq['marca'].' '.($eq['modelo']??''))?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Motivo -->
            <div class="col-md-4">
              <label class="form-label fw-semibold">Motivo <span class="text-danger">*</span></label>
              <select name="motivo" class="form-select" required>
                <?php foreach([
                  'PRIMERA_ASIGNACION'  => 'Primera Asignación',
                  'ACCIDENTE'           => 'Accidente / Daño accidental',
                  'FALLA_HARDWARE'      => 'Falla de Hardware',
                  'ROBO'                => 'Robo',
                  'EXTRAVIO'            => 'Extravío',
                  'OBSOLESCENCIA'       => 'Obsolescencia / Actualización',
                  'UPGRADE'             => 'Upgrade / Mejora',
                  'REASIGNACION'        => 'Reasignación de área',
                  'PRESTAMO_TEMPORAL'   => 'Préstamo Temporal',
                  'MANTENIMIENTO'       => 'Mantenimiento del equipo',
                  'OTRO'                => 'Otro',
                ] as $mv => $ml): ?>
                <option value="<?=$mv?>"><?=$ml?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Fechas -->
            <div class="col-md-4">
              <label class="form-label fw-semibold">Fecha de Inicio <span class="text-danger">*</span></label>
              <input type="date" name="fecha_inicio" class="form-control"
                     value="<?=date('Y-m-d')?>" required>
            </div>
            <div class="col-md-4" id="row-fecha-fin">
              <label class="form-label fw-semibold">Fecha de Fin <span class="text-muted">(si aplica)</span></label>
              <input type="date" name="fecha_fin" class="form-control">
              <div class="form-text">Solo para reemplazos temporales</div>
            </div>

            <!-- Descripción -->
            <div class="col-12">
              <label class="form-label fw-semibold">Descripción del Motivo</label>
              <textarea name="descripcion" class="form-control" rows="2"
                        placeholder="Ej: El equipo EQ-0012 sufrió daño en la pantalla por caída accidental. Se asigna equipo de reserva mientras se repara…"></textarea>
            </div>

            <!-- Observaciones técnico -->
            <div class="col-12">
              <label class="form-label fw-semibold">Observaciones del Técnico</label>
              <textarea name="observaciones" class="form-control" rows="2"
                        placeholder="Notas adicionales del técnico TI…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"
        onclick="return validarModalReemplazo()">
  <i class="bi bi-check-lg me-1"></i>Registrar Movimiento
</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// ── Tipo de movimiento: resaltar botón seleccionado ──
document.querySelectorAll('.tipo-btn-label').forEach(lbl => {
  lbl.addEventListener('click', () => {
    document.querySelectorAll('.tipo-btn-label').forEach(l => {
      l.classList.remove('active','btn-primary','btn-warning','btn-danger','btn-secondary','btn-info');
      l.classList.add('btn-outline-secondary');
    });
    lbl.classList.remove('btn-outline-secondary');
    const tipo = lbl.dataset.tipo;
    const clsMap = {
      ASIGNACION:'btn-primary', REEMPLAZO_TEMPORAL:'btn-warning',
      REEMPLAZO_DEFINITIVO:'btn-danger', DEVOLUCION:'btn-secondary', REASIGNACION:'btn-info'
    };
    lbl.classList.add(clsMap[tipo]||'btn-primary');
    // Mostrar/ocultar fecha fin
    const rowFin = document.getElementById('row-fecha-fin');
    rowFin.style.opacity = tipo==='REEMPLAZO_TEMPORAL' ? '1' : '.5';
    // Preseleccionar motivo
    const motivoMap = {
      ASIGNACION:'PRIMERA_ASIGNACION', REEMPLAZO_TEMPORAL:'ACCIDENTE',
      REEMPLAZO_DEFINITIVO:'FALLA_HARDWARE', DEVOLUCION:'OTRO', REASIGNACION:'REASIGNACION'
    };
    const selMot = document.querySelector('[name="motivo"]');
    if (selMot && motivoMap[tipo]) selMot.value = motivoMap[tipo];
  });
});
// Activar el primero por defecto
document.querySelector('.tipo-btn-label')?.click();

// ── ID del usuario actual para comparar ──
const USUARIO_ID_ACTUAL = <?= (int)$id ?>;

// ── Validación de estado al cambiar equipo ──
function validarEstadoEquipo(select) {
  const opt    = select.options[select.selectedIndex];
  const estado = opt?.dataset?.estado ?? '';
  const uidEq  = parseInt(opt?.dataset?.usuarioId ?? '0', 10);
  const panel  = document.getElementById('panel-inactivos');
  const btnSubmit = document.querySelector('#modalReemplazo button[type="submit"]');

  if (!opt.value) {
    panel.classList.add('d-none');
    if (btnSubmit) btnSubmit.disabled = false;
    return;
  }

  // Equipo ACTIVO asignado a OTRO usuario → bloquear y mostrar panel
  if (estado === 'ACTIVO' && uidEq !== 0 && uidEq !== USUARIO_ID_ACTUAL) {
    panel.classList.remove('d-none');
    if (btnSubmit) btnSubmit.disabled = true;
    // Advertencia SweetAlert
    Swal.fire({
      icon: 'warning',
      title: 'Equipo no disponible',
      html: `El equipo <strong>${opt.dataset.folio}</strong> está <span class="badge bg-success">ACTIVO</span>
             y asignado a otro usuario.<br><br>
             <span class="text-muted small">Selecciona un equipo <strong>INACTIVO</strong> de la lista
             o registra uno nuevo.</span>`,
      confirmButtonText: 'Entendido',
      confirmButtonColor: '#1a3a5c',
    });
  } else {
    panel.classList.add('d-none');
    if (btnSubmit) btnSubmit.disabled = false;
  }
}

// ── Preview IP + validación al cambiar selección ──
document.getElementById('sel-equipo-nuevo')?.addEventListener('change', function() {
  // Preview IP/internet
  const opt  = this.options[this.selectedIndex];
  const ip   = opt?.dataset?.ip   || '';
  const acc  = opt?.dataset?.acceso === '1';
  const inet = opt?.dataset?.internet || 'SIN_ACCESO';
  const prev = document.getElementById('preview-equipo-nuevo');
  if (!opt.value) { prev.classList.add('d-none'); return; }
  prev.classList.remove('d-none');
  document.getElementById('prev-ip').textContent = ip || '—';
  const inetMap = {
    SIN_ACCESO : ['secondary','bi-wifi-off','Sin Internet'],
    RESTRINGIDO: ['warning',  'bi-lock-fill','Restringido'],
    COMPLETO   : ['success',  'bi-globe',   'Acceso Completo'],
  };
  const [cls,ico,lbl] = inetMap[inet] || inetMap.SIN_ACCESO;
  const badge = document.getElementById('prev-internet-badge');
  badge.innerHTML = acc
    ? `<span class="badge bg-${cls}"><i class="bi ${ico} me-1"></i>${lbl}</span>`
    : '<span class="badge bg-secondary"><i class="bi bi-wifi-off me-1"></i>Sin Internet</span>';

  // Validación de estado
  validarEstadoEquipo(this);
});

// ── Botones "Seleccionar" de equipos inactivos ──
document.querySelectorAll('.btn-usar-inactivo').forEach(btn => {
  btn.addEventListener('click', () => {
    const sel = document.getElementById('sel-equipo-nuevo');
    sel.value = btn.dataset.id;
    sel.dispatchEvent(new Event('change'));
    // Scroll al select
    sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    sel.focus();
  });
});

// ── Disparar al abrir el modal ──
document.getElementById('modalReemplazo')?.addEventListener('shown.bs.modal', () => {
  const sel = document.getElementById('sel-equipo-nuevo');
  sel?.dispatchEvent(new Event('change'));
  <?php if ($asigError): ?>
  // Reabrir con alerta del error de sesión
  Swal.fire({
    icon: 'warning',
    title: 'Equipo no disponible',
    html: `El equipo <strong><?= h($asigError['equipo']['folio'] ?? '') ?></strong>
           está <span class="badge bg-success">ACTIVO</span> y asignado a otro usuario.<br>
           Selecciona un equipo <strong>INACTIVO</strong> de la lista o registra uno nuevo.`,
    confirmButtonText: 'Ver lista de inactivos',
    confirmButtonColor: '#1a3a5c',
  });
  document.getElementById('panel-inactivos')?.classList.remove('d-none');
  const btnSubmit = document.querySelector('#modalReemplazo button[type="submit"]');
  if (btnSubmit) btnSubmit.disabled = true;
  <?php endif; ?>
});

// ── Validar antes de submit ──
function validarModalReemplazo() {
  const sel    = document.getElementById('sel-equipo-nuevo');
  const opt    = sel.options[sel.selectedIndex];
  const estado = opt?.dataset?.estado ?? '';
  const uidEq  = parseInt(opt?.dataset?.usuarioId ?? '0', 10);

  if (!sel.value) {
    Swal.fire({ icon:'warning', title:'Equipo requerido',
      text:'Selecciona el equipo a asignar.', confirmButtonColor:'#1a3a5c' });
    return false;
  }
  // Doble-check JS por si el usuario burló el disabled
  if (estado === 'ACTIVO' && uidEq !== 0 && uidEq !== USUARIO_ID_ACTUAL) {
    Swal.fire({ icon:'error', title:'Equipo no disponible',
      html:'Solo puedes asignar equipos en estado <strong>INACTIVO</strong>.',
      confirmButtonColor:'#dc3545' });
    return false;
  }
  return true;
  }
</script>
<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

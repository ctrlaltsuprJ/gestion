<?php
/**
 * cursos.php — Gestión de Cursos (Admin / Supervisor)
 */
require_once 'includes/auth.php';
require_once 'includes/cursos_functions.php';

$auth = authCheck();
requireRol(ROL_ADMIN); // Solo administradores gestionan cursos

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── POST: Guardar curso ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== 'material' && $action !== 'participantes') {
    $pid   = (int)($_POST['id'] ?? 0) ?: null;
    $saved = saveCurso($_POST, $pid);
    auditLog($pid ? 'EDITAR_CURSO' : 'CREAR_CURSO', 'cursos', $saved, $_POST['nombre'] ?? '');
    flash('success', $pid ? 'Curso actualizado.' : 'Curso creado correctamente.');
    redirect('cursos.php?action=view&id='.$saved);
}

// ── POST: Guardar material ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'material') {
    $cursoId = (int)($_POST['curso_id'] ?? 0);
    $matId   = (int)($_POST['mat_id'] ?? 0) ?: null;
    $data    = $_POST;

    // Subir archivo si viene uno
    if (!empty($_FILES['archivo']['name']) && $_FILES['archivo']['error'] === 0) {
        $resultado = subirArchivoMaterial($_FILES['archivo'], $cursoId);
        if ($resultado) {
            $data['archivo_path']   = $resultado['path'];
            $data['archivo_nombre'] = $resultado['name'];
            $data['tipo'] = 'ARCHIVO';
        } else {
            flash('danger', 'Error al subir el archivo. Verifica el tipo y tamaño (máx 20 MB).');
            redirect("cursos.php?action=view&id=$cursoId");
        }
    }

    saveMaterial($data, $matId);
    auditLog('MATERIAL_CURSO', 'cursos', $cursoId, $data['titulo'] ?? '');
    flash('success', $matId ? 'Material actualizado.' : 'Material agregado.');
    redirect("cursos.php?action=view&id=$cursoId");
}

// ── POST: Gestionar participantes ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'participantes') {
    $cursoId = (int)($_POST['curso_id'] ?? 0);
    $uids    = array_map('intval', (array)($_POST['usuario_ids'] ?? []));
    // Agregar nuevos participantes
    foreach ($uids as $uid) {
        if ($uid > 0) agregarParticipante($cursoId, $uid);
    }
    flash('success', count($uids).' participante(s) agregado(s).');
    redirect("cursos.php?action=view&id=$cursoId");
}

// ── GET: Acciones rápidas ────────────────────────────────────────────────────
if ($action === 'delete' && $id) {
    deleteCurso($id);
    auditLog('ELIMINAR_CURSO', 'cursos', $id);
    flash('warning', 'Curso eliminado.');
    redirect('cursos.php');
}
if ($action === 'del_material' && $id) {
    $cursoId = (int)($_GET['curso'] ?? 0);
    deleteMaterial($id);
    flash('warning', 'Material eliminado.');
    redirect("cursos.php?action=view&id=$cursoId");
}
if ($action === 'quitar_participante' && $id) {
    $cursoId = (int)($_GET['curso'] ?? 0);
    $uid     = (int)($_GET['uid'] ?? 0);
    quitarParticipante($cursoId, $uid);
    flash('warning', 'Participante removido del curso.');
    redirect("cursos.php?action=view&id=$cursoId");
}
if ($action === 'estado_participante' && $id) {
    $cursoId  = (int)($_GET['curso'] ?? 0);
    $uid      = (int)($_GET['uid']   ?? 0);
    $estNuevo = $_GET['estado'] ?? '';
    if (in_array($estNuevo, ['PENDIENTE','EN_CURSO','COMPLETADO'])) {
        actualizarEstadoParticipante($cursoId, $uid, $estNuevo);
        flash('success', 'Estado actualizado.');
    }
    redirect("cursos.php?action=view&id=$cursoId");
}

$pageTitle = 'Cursos';
include 'includes/header.php';

$q       = trim($_GET['q']      ?? '');
$estFil  = trim($_GET['estado'] ?? '');
?>
<div class="container-fluid px-3 py-3">

<?php if ($action === 'list'): ?>
<!-- ══ LISTA DE CURSOS ═══════════════════════════════════════════════════════ -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-mortarboard-fill me-2"></i>Gestión de Cursos</h5>
  <a href="cursos.php?action=new" class="btn btn-sm no-print"
     style="background:#1a3a5c;color:#fff;">
    <i class="bi bi-plus me-1"></i>Nuevo Curso
  </a>
</div>

<!-- Filtros -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <input type="search" name="q" class="form-control form-control-sm"
               placeholder="Buscar curso…" value="<?= h($q) ?>">
      </div>
      <div class="col-sm-3">
        <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Todos los estados —</option>
          <?php foreach(['BORRADOR','ACTIVO','FINALIZADO','CANCELADO'] as $e): ?>
          <option value="<?= $e ?>" <?= $estFil===$e?'selected':'' ?>><?= $e ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
        <?php if ($q || $estFil): ?>
        <a href="cursos.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php $cursos = getCursos($q, $estFil); ?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-ti table-hover mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Nombre del Curso</th>
            <th>Plataforma</th>
            <th>Estado</th>
            <th>Fecha Inicio</th>
            <th>Fecha Fin</th>
            <th class="text-center">Participantes</th>
            <th>Creado por</th>
            <th class="no-print">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($cursos as $c): ?>
          <tr onclick="window.location='cursos.php?action=view&id=<?= $c['id'] ?>'"
              style="cursor:pointer;">
            <td><?= $c['id'] ?></td>
            <td>
              <strong><?= h($c['nombre']) ?></strong>
              <?php if ($c['descripcion']): ?>
              <div class="small text-muted text-truncate" style="max-width:220px;">
                <?= h($c['descripcion']) ?>
              </div>
              <?php endif; ?>
            </td>
            <td><?= cursoPlataformaBadge($c['plataforma']) ?></td>
            <td><?= cursoEstadoBadge($c['estado']) ?></td>
            <td class="small text-nowrap">
              <?= $c['fecha_inicio'] ? date('d/m/Y H:i', strtotime($c['fecha_inicio'])) : '—' ?>
            </td>
            <td class="small text-nowrap">
              <?= $c['fecha_fin'] ? date('d/m/Y H:i', strtotime($c['fecha_fin'])) : '—' ?>
            </td>
            <td class="text-center">
              <span class="badge bg-primary rounded-pill"><?= (int)$c['total_participantes'] ?></span>
            </td>
            <td class="small"><?= h($c['creado_por_nombre'] ?? '—') ?></td>
            <td class="no-print" onclick="event.stopPropagation()">
              <a href="cursos.php?action=view&id=<?= $c['id'] ?>"
                 class="btn btn-xs btn-outline-primary me-1" title="Ver detalle">
                <i class="bi bi-eye"></i>
              </a>
              <a href="cursos.php?action=edit&id=<?= $c['id'] ?>"
                 class="btn btn-xs btn-outline-secondary me-1" title="Editar">
                <i class="bi bi-pencil"></i>
              </a>
              <button onclick="confirmDelete('cursos.php?action=delete&id=<?= $c['id'] ?>','<?= addslashes($c['nombre']) ?>')"
                      class="btn btn-xs btn-outline-danger" title="Eliminar">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($cursos)): ?>
          <tr>
            <td colspan="9" class="text-center text-muted py-4">
              <i class="bi bi-mortarboard d-block fs-3 opacity-25 mb-1"></i>
              No hay cursos registrados.
            </td>
          </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php elseif (in_array($action, ['new','edit'])): ?>
<!-- ══ FORMULARIO NUEVO / EDITAR CURSO ═══════════════════════════════════════ -->
<?php $c = ($action === 'edit' && $id) ? getCursoById($id) : []; $isEdit = !empty($c); ?>
<div class="row justify-content-center">
  <div class="col-xl-8">
    <div class="card">
      <div class="card-header-ti">
        <i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-circle' ?> me-2"></i>
        <?= $isEdit ? 'Editar Curso' : 'Nuevo Curso' ?>
      </div>
      <div class="card-body">
        <form method="POST" action="cursos.php">
          <?php if ($isEdit): ?>
          <input type="hidden" name="id" value="<?= $c['id'] ?>">
          <?php endif; ?>
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Nombre del Curso *</label>
              <input type="text" name="nombre" class="form-control"
                     value="<?= h($c['nombre'] ?? '') ?>" required
                     placeholder="Ej. Taller de Ciberseguridad 2025">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Descripción</label>
              <textarea name="descripcion" class="form-control" rows="3"
                        placeholder="Objetivo y contenido del curso…"><?= h($c['descripcion'] ?? '') ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Plataforma</label>
              <select name="plataforma" id="sel-plataforma" class="form-select"
                      onchange="toggleUrlPlataforma()">
                <?php foreach (['ZOOM','TEAMS','MEET','OTRO'] as $p): ?>
                <option value="<?= $p ?>" <?= ($c['plataforma'] ?? 'ZOOM') === $p ? 'selected' : '' ?>>
                  <?= $p ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8" id="row-url-plataforma">
              <label class="form-label fw-semibold">
                Enlace de la sesión <span class="text-muted fw-normal">(Zoom/Teams/Meet)</span>
              </label>
              <input type="url" name="plataforma_url" class="form-control"
                     value="<?= h($c['plataforma_url'] ?? '') ?>"
                     placeholder="https://zoom.us/j/…">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Estado</label>
              <select name="estado" class="form-select">
                <?php foreach (['BORRADOR','ACTIVO','FINALIZADO','CANCELADO'] as $e): ?>
                <option value="<?= $e ?>" <?= ($c['estado'] ?? 'BORRADOR') === $e ? 'selected' : '' ?>>
                  <?= $e ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Fecha y Hora de Inicio</label>
              <input type="datetime-local" name="fecha_inicio" class="form-control"
                     value="<?= h(str_replace(' ', 'T', $c['fecha_inicio'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Fecha y Hora de Fin</label>
              <input type="datetime-local" name="fecha_fin" class="form-control"
                     value="<?= h(str_replace(' ', 'T', $c['fecha_fin'] ?? '')) ?>">
            </div>
          </div>
          <div class="d-flex gap-2 mt-4">
            <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;">
              <i class="bi bi-check-lg me-1"></i>Guardar
            </button>
            <a href="cursos.php<?= $isEdit ? '?action=view&id='.$c['id'] : '' ?>"
               class="btn btn-outline-secondary">Cancelar</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php elseif ($action === 'view' && $id): ?>
<!-- ══ VISTA DETALLE DEL CURSO ═══════════════════════════════════════════════ -->
<?php
$c            = getCursoById($id);
if (!$c) { flash('danger','Curso no encontrado.'); redirect('cursos.php'); }
$participantes = getParticipantesByCurso($id);
$materiales    = getMaterialesByCurso($id);
$todosUsuarios = getUsuarios();
// IDs ya inscritos
$idsInscritos  = array_column(
    array_filter($participantes, fn($p) => $p['estado'] !== 'BAJA'),
    'usuario_id'
);
// Usuarios disponibles para agregar
$disponibles   = array_filter($todosUsuarios, fn($u) => !in_array($u['id'], $idsInscritos));

$platCv = strtolower($c['plataforma']);
$platMapV = ['zoom'=>['#2D8CFF','bi-camera-video-fill','Zoom'],
             'teams'=>['#6264A7','bi-microsoft-teams','Teams'],
             'meet'=>['#00897B','bi-google','Meet']];
[$pbgV,$picoV,$plblV] = $platMapV[$platCv] ?? ['#6c757d','bi-globe','Otro'];
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <a href="cursos.php" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left me-1"></i>Volver
  </a>
  <div class="d-flex gap-2">
    <a href="cursos.php?action=edit&id=<?= $id ?>"
       class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil me-1"></i>Editar
    </a>
    <a href="cursos_tablero.php" target="_blank" rel="noopener"
       class="btn btn-sm btn-outline-info" title="Ver como participante">
      <i class="bi bi-eye me-1"></i>Vista Participante
    </a>
  </div>
</div>

<!-- Header del curso -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body p-4">
    <div class="d-flex flex-wrap gap-3 align-items-start justify-content-between">
      <div>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge" style="background:<?= $pbgV ?>;">
            <i class="bi <?= $picoV ?> me-1"></i><?= $plblV ?>
          </span>
          <?= cursoEstadoBadge($c['estado']) ?>
        </div>
        <h3 class="fw-bold mb-1"><?= h($c['nombre']) ?></h3>
        <?php if ($c['descripcion']): ?>
        <p class="text-muted mb-0"><?= nl2br(h($c['descripcion'])) ?></p>
        <?php endif; ?>
      </div>
      <?php if ($c['plataforma_url']): ?>
      <a href="<?= h($c['plataforma_url']) ?>" target="_blank" rel="noopener"
         class="btn btn-primary">
        <i class="bi bi-camera-video-fill me-2"></i>Abrir en <?= $plblV ?>
      </a>
      <?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-3 mt-3 pt-3 border-top small text-muted">
      <?php if ($c['fecha_inicio']): ?>
      <span><i class="bi bi-calendar-event me-1"></i>
        Inicio: <strong class="text-dark"><?= date('d/m/Y H:i', strtotime($c['fecha_inicio'])) ?></strong>
      </span>
      <?php endif; ?>
      <?php if ($c['fecha_fin']): ?>
      <span><i class="bi bi-calendar-check me-1"></i>
        Fin: <strong class="text-dark"><?= date('d/m/Y H:i', strtotime($c['fecha_fin'])) ?></strong>
      </span>
      <?php endif; ?>
      <span><i class="bi bi-people me-1"></i>
        <strong class="text-dark"><?= count($participantes) ?></strong> participantes
      </span>
    </div>
  </div>
</div>

<div class="row g-4">

  <!-- ── Participantes ───────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header-ti d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people-fill me-2"></i>Participantes</span>
        <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal"
                data-bs-target="#modalAgregarParticipante">
          <i class="bi bi-person-plus me-1"></i>Agregar
        </button>
      </div>
      <div class="card-body p-0">
        <?php if (empty($participantes)): ?>
        <div class="text-center text-muted py-4 small">
          <i class="bi bi-people d-block fs-3 opacity-25 mb-1"></i>
          Sin participantes aún.
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush" style="max-height:420px;overflow-y:auto;">
          <?php foreach ($participantes as $p):
            if ($p['estado'] === 'BAJA') continue;
            $partBadgeCls3 = ['PENDIENTE'=>'bg-warning text-dark','EN_CURSO'=>'bg-info text-dark','COMPLETADO'=>'bg-success'][$p['estado']] ?? 'bg-secondary';
          ?>
          <div class="list-group-item py-2 px-3">
            <div class="d-flex justify-content-between align-items-center gap-2">
              <div class="d-flex align-items-center gap-2 min-w-0">
                <div class="flex-shrink-0 rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                     style="width:32px;height:32px;font-size:.8rem;background:linear-gradient(135deg,#1a3a5c,#2563eb);">
                  <?= strtoupper(substr($p['nombre'], 0, 1)) ?>
                </div>
                <div class="min-w-0">
                  <div class="fw-semibold small text-truncate">
                    <?= h($p['nombre'].' '.($p['apellidos'] ?? '')) ?>
                  </div>
                  <div class="text-muted" style="font-size:.72rem;">
                    <?= h($p['usuario_windows'] ?? '') ?>
                    <?= $p['area_nombre'] ? '· '.h($p['area_nombre']) : '' ?>
                  </div>
                </div>
              </div>
              <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <span class="badge <?= $partBadgeCls3 ?>" style="font-size:.65rem;">
                  <?= h($p['estado']) ?>
                </span>
                <div class="dropdown">
                  <button class="btn btn-xs btn-outline-secondary dropdown-toggle"
                          data-bs-toggle="dropdown">
                    <i class="bi bi-three-dots"></i>
                  </button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <?php foreach (['PENDIENTE','EN_CURSO','COMPLETADO'] as $est): ?>
                    <?php if ($est !== $p['estado']): ?>
                    <li>
                      <a class="dropdown-item small" href="cursos.php?action=estado_participante&id=<?= $id ?>&curso=<?= $id ?>&uid=<?= $p['usuario_id'] ?>&estado=<?= $est ?>">
                        Marcar <?= $est ?>
                      </a>
                    </li>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                      <a class="dropdown-item small text-danger" href="cursos.php?action=quitar_participante&id=<?= $id ?>&curso=<?= $id ?>&uid=<?= $p['usuario_id'] ?>"
                         onclick="return confirm('¿Quitar a este participante del curso?')">
                        <i class="bi bi-person-dash me-1"></i>Quitar del curso
                      </a>
                    </li>
                  </ul>
                </div>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Materiales / Tablero ────────────────────────────────── -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header-ti d-flex justify-content-between align-items-center">
        <span><i class="bi bi-grid-3x3-gap-fill me-2"></i>Tablero de Materiales</span>
        <button class="btn btn-sm btn-outline-light" data-bs-toggle="modal"
                data-bs-target="#modalAgregarMaterial">
          <i class="bi bi-plus me-1"></i>Agregar
        </button>
      </div>
      <div class="card-body p-0">
        <?php if (empty($materiales)): ?>
        <div class="text-center text-muted py-4 small">
          <i class="bi bi-folder2 d-block fs-3 opacity-25 mb-1"></i>
          Sin materiales. Agrega enlaces o archivos.
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($materiales as $m):
            $isEnlace = $m['tipo'] === 'ENLACE';
            $ext2 = strtolower(pathinfo($m['archivo_nombre'] ?? '', PATHINFO_EXTENSION));
            $fIcons = ['pdf'=>'bi-file-earmark-pdf text-danger','doc'=>'bi-file-earmark-word text-primary',
                       'docx'=>'bi-file-earmark-word text-primary','xls'=>'bi-file-earmark-excel text-success',
                       'xlsx'=>'bi-file-earmark-excel text-success','ppt'=>'bi-file-earmark-ppt text-warning',
                       'pptx'=>'bi-file-earmark-ppt text-warning','zip'=>'bi-file-earmark-zip text-secondary'];
            $fIco2 = $fIcons[$ext2] ?? 'bi-file-earmark text-muted';
          ?>
          <div class="list-group-item py-2 px-3">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="d-flex align-items-start gap-2 min-w-0">
                <i class="bi <?= $isEnlace ? 'bi-link-45deg text-primary' : $fIco2 ?> fs-5 mt-1 flex-shrink-0"></i>
                <div class="min-w-0">
                  <div class="fw-semibold small"><?= h($m['titulo']) ?></div>
                  <?php if ($m['descripcion']): ?>
                  <div class="text-muted" style="font-size:.72rem;"><?= h($m['descripcion']) ?></div>
                  <?php endif; ?>
                  <div class="mt-1">
                    <?php if ($isEnlace && $m['url']): ?>
                    <a href="<?= h($m['url']) ?>" target="_blank" rel="noopener"
                       class="btn btn-xs btn-outline-primary">
                      <i class="bi bi-box-arrow-up-right me-1"></i>Abrir
                    </a>
                    <?php elseif ($m['archivo_path']): ?>
                    <a href="<?= h($m['archivo_path']) ?>" target="_blank"
                       class="btn btn-xs btn-outline-success">
                      <i class="bi bi-download me-1"></i>Descargar
                    </a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <div class="d-flex align-items-center gap-1 flex-shrink-0">
                <span class="badge <?= $isEnlace ? 'bg-primary' : 'bg-success' ?>" style="font-size:.65rem;">
                  <?= $m['tipo'] ?>
                </span>
                <a href="cursos.php?action=del_material&id=<?= $m['id'] ?>&curso=<?= $id ?>"
                   class="btn btn-xs btn-outline-danger"
                   onclick="return confirm('¿Eliminar este material?')">
                  <i class="bi bi-trash"></i>
                </a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /row -->

<!-- ── Modal: Agregar Participantes ──────────────────────────────────────── -->
<div class="modal fade" id="modalAgregarParticipante" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Agregar Participantes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="cursos.php?action=participantes">
        <input type="hidden" name="curso_id" value="<?= $id ?>">
        <div class="modal-body">
          <div class="mb-2">
            <input type="search" id="buscar-usuarios-modal" class="form-control form-control-sm"
                   placeholder="Buscar por nombre, usuario, área…" oninput="filtrarUsuariosModal(this.value)">
          </div>
          <div style="max-height:350px;overflow-y:auto;">
            <table class="table table-sm table-hover" id="tbl-usuarios-modal">
              <thead class="table-dark sticky-top">
                <tr>
                  <th style="width:36px;"><input type="checkbox" id="chk-all" onclick="toggleAllUsers(this)"></th>
                  <th>Nombre</th>
                  <th>Usuario Windows</th>
                  <th>Área</th>
                  <th>Puesto</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($disponibles as $u): ?>
                <tr>
                  <td>
                    <input type="checkbox" name="usuario_ids[]" value="<?= $u['id'] ?>"
                           class="chk-usuario">
                  </td>
                  <td class="small fw-semibold">
                    <?= h($u['nombre'].' '.($u['apellidos'] ?? '')) ?>
                  </td>
                  <td><code class="small"><?= h($u['usuario_windows'] ?? '—') ?></code></td>
                  <td class="small"><?= h($u['area_nombre'] ?? '—') ?></td>
                  <td class="small"><?= h($u['puesto'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($disponibles)): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-3">
                    Todos los usuarios ya están inscritos.
                  </td>
                </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <span class="text-muted small me-auto">
            <span id="cnt-seleccionados">0</span> seleccionado(s)
          </span>
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;">
            <i class="bi bi-person-check me-1"></i>Agregar al Curso
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Modal: Agregar Material ────────────────────────────────────────────── -->
<div class="modal fade" id="modalAgregarMaterial" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Agregar Material</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="cursos.php?action=material" enctype="multipart/form-data">
        <input type="hidden" name="curso_id" value="<?= $id ?>">
        <div class="modal-body">
          <!-- Tipo de material -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Tipo de Material</label>
            <div class="btn-group w-100" role="group">
              <input type="radio" class="btn-check" name="tipo" id="tipo-enlace"
                     value="ENLACE" checked onchange="toggleTipoMaterial()">
              <label class="btn btn-outline-primary" for="tipo-enlace">
                <i class="bi bi-link-45deg me-1"></i>Enlace
              </label>
              <input type="radio" class="btn-check" name="tipo" id="tipo-archivo"
                     value="ARCHIVO" onchange="toggleTipoMaterial()">
              <label class="btn btn-outline-success" for="tipo-archivo">
                <i class="bi bi-file-earmark-arrow-up me-1"></i>Archivo
              </label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Título *</label>
            <input type="text" name="titulo" class="form-control" required
                   placeholder="Ej. Manual de usuario, Presentación sesión 1…">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Descripción</label>
            <input type="text" name="descripcion" class="form-control"
                   placeholder="Breve descripción del material…">
          </div>
          <!-- URL (solo para enlace) -->
          <div class="mb-3" id="div-url">
            <label class="form-label fw-semibold">URL del Enlace *</label>
            <input type="url" name="url" class="form-control"
                   placeholder="https://…">
          </div>
          <!-- Archivo (solo para tipo archivo) -->
          <div class="mb-3 d-none" id="div-archivo">
            <label class="form-label fw-semibold">Archivo *</label>
            <input type="file" name="archivo" class="form-control"
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.zip,.png,.jpg,.jpeg">
            <div class="form-text">Máx 20 MB. Formatos: PDF, Word, Excel, PowerPoint, ZIP, imágenes.</div>
          </div>
          <div class="mb-0">
            <label class="form-label fw-semibold">Orden</label>
            <input type="number" name="orden" class="form-control form-control-sm"
                   min="0" value="0" style="width:100px;">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-success">
            <i class="bi bi-check-lg me-1"></i>Guardar Material
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php endif; ?>

</div><!-- /container -->

<script>
// ── Toggle URL vs Archivo en modal material ──────────────────────────────────
function toggleTipoMaterial() {
  const esArchivo = document.getElementById('tipo-archivo')?.checked;
  document.getElementById('div-url').classList.toggle('d-none', esArchivo);
  document.getElementById('div-archivo').classList.toggle('d-none', !esArchivo);
  document.querySelector('[name="url"]').required    = !esArchivo;
  document.querySelector('[name="archivo"]').required = esArchivo;
}

// ── Toggle URL plataforma ────────────────────────────────────────────────────
function toggleUrlPlataforma() {
  const v = document.getElementById('sel-plataforma')?.value;
  document.getElementById('row-url-plataforma').style.opacity = v === 'OTRO' ? '.5' : '1';
}
document.addEventListener('DOMContentLoaded', toggleUrlPlataforma);

// ── Filtro de usuarios en modal ──────────────────────────────────────────────
function filtrarUsuariosModal(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#tbl-usuarios-modal tbody tr').forEach(tr => {
    tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

// ── Seleccionar/deseleccionar todos ─────────────────────────────────────────
function toggleAllUsers(chk) {
  document.querySelectorAll('.chk-usuario').forEach(c => {
    if (c.closest('tr').style.display !== 'none') c.checked = chk.checked;
  });
  actualizarContador();
}

// ── Contador de seleccionados ────────────────────────────────────────────────
document.querySelectorAll('.chk-usuario')?.forEach(c =>
  c.addEventListener('change', actualizarContador)
);
function actualizarContador() {
  const n = document.querySelectorAll('.chk-usuario:checked').length;
  document.getElementById('cnt-seleccionados').textContent = n;
}
</script>

<?php include 'includes/footer.php'; ?>

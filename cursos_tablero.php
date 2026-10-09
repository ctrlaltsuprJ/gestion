<?php
/**
 * cursos_tablero.php
 * Tablero personal del usuario participante.
 * Autenticación independiente via cookie curso_token.
 */
require_once 'includes/auth.php';
require_once 'includes/cursos_functions.php';

// ── Autenticación independiente ──────────────────────────────────────────────
$token   = $_COOKIE['curso_token'] ?? '';
$sesion  = $token ? getCursoSesion($token) : false;
if (!$sesion) {
    redirect('usuario_login.php');
}
$usuarioId = (int)$sesion['uid'];
$usuario   = $sesion;

// ── Logout ───────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'logout') {
    logoutCursoUsuario($token);
    setcookie('curso_token', '', ['expires' => time() - 3600, 'path' => '/']);
    redirect('usuario_login.php');
}

// ── Marcar acceso al curso ───────────────────────────────────────────────────
$cursoActivo = null;
if (!empty($_GET['curso'])) {
    $cid = (int)$_GET['curso'];
    $db  = getDB();
    // Verificar que el usuario está inscrito
    $s = $db->prepare(
        "SELECT cp.*, c.nombre curso_nombre FROM curso_participantes cp
         JOIN cursos c ON c.id=cp.curso_id
         WHERE cp.curso_id=? AND cp.usuario_id=? AND cp.estado != 'BAJA'"
    );
    $s->execute([$cid, $usuarioId]);
    $inscripcion = $s->fetch();
    if ($inscripcion) {
        if (!$inscripcion['fecha_acceso']) {
            $db->prepare(
                "UPDATE curso_participantes SET fecha_acceso=NOW(), estado='EN_CURSO'
                 WHERE curso_id=? AND usuario_id=? AND estado='PENDIENTE'"
            )->execute([$cid, $usuarioId]);
        }
        $cursoActivo = getCursoById($cid);
        $cursoActivo['materiales']    = getMaterialesByCurso($cid);
        $cursoActivo['part_estado']   = $inscripcion['estado'];
    }
}

// ── Lista de cursos del usuario ──────────────────────────────────────────────
$misCursos = getCursosDeUsuario($usuarioId);
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Mis Cursos — <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    body { background:#f0f4f8; font-family:'Inter',sans-serif; }
    .tablero-navbar { background:linear-gradient(135deg,#0f2342,#1a3a5c); }
    .curso-card { border:0; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.08); transition:transform .15s,box-shadow .15s; cursor:pointer; }
    .curso-card:hover { transform:translateY(-3px); box-shadow:0 6px 24px rgba(0,0,0,.13); }
    .curso-card.activo { border:2px solid #2563eb !important; }
    .plat-zoom  { background:#2D8CFF; }
    .plat-teams { background:#6264A7; }
    .plat-meet  { background:#00897B; }
    .plat-otro  { background:#6c757d; }
    .plat-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .55rem; border-radius:20px; color:#fff; font-size:.72rem; font-weight:600; }
    .material-card { border-radius:10px; border:1px solid #e2e8f0; transition:box-shadow .15s; }
    .material-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.1); }
    .material-enlace { border-left:4px solid #2563eb; }
    .material-archivo { border-left:4px solid #10b981; }
    .estado-badge { font-size:.7rem; padding:.25rem .55rem; border-radius:20px; font-weight:600; }
    .avatar-circle-lg { width:44px; height:44px; border-radius:50%; background:linear-gradient(135deg,#1a3a5c,#2563eb); display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:1.1rem; flex-shrink:0; }
    .empty-state { text-align:center; padding:3rem 1rem; }
    .empty-state i { font-size:3rem; opacity:.2; display:block; margin-bottom:.75rem; }
    .btn-unirse { background:linear-gradient(135deg,#1a3a5c,#2563eb); color:#fff; border:0; font-weight:600; }
    .btn-unirse:hover { background:linear-gradient(135deg,#15304d,#1d4ed8); color:#fff; }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="tablero-navbar navbar navbar-dark py-2 px-3 sticky-top">
  <div class="container-fluid">
    <span class="navbar-brand fw-bold mb-0 d-flex align-items-center gap-2">
      <a href="cursos_tablero.php" class="text-white text-decoration-none d-flex align-items-center gap-2">
        <i class="bi bi-mortarboard-fill fs-5"></i>
        <span>Mis Cursos</span>
      </a>
      <span class="text-white-50">|</span>
      <a href="tickets.php" class="text-white text-decoration-none d-flex align-items-center gap-2">
        <i class="bi bi-ticket-fill fs-5"></i>
        <span>Mis Tickets</span>
      </a>
      <span class="text-white-50">|</span>
      <a href="perfil.php" class="text-white text-decoration-none d-flex align-items-center gap-2">
        <i class="bi bi-person-fill fs-5"></i>
        <span>Mi Perfil</span>
      </a>
      <span class="text-white-50 fw-normal small d-none d-md-inline">— <?= h(APP_NAME) ?></span>
    </span>
    <div class="d-flex align-items-center gap-2">
      <div class="d-flex align-items-center gap-2">
        <div class="avatar-circle-lg" style="width:32px;height:32px;font-size:.85rem;">
          <?= strtoupper(substr($usuario['nombre'], 0, 1)) ?>
        </div>
        <span class="text-white small d-none d-md-inline">
          <?= h($usuario['nombre'].' '.($usuario['apellidos'] ?? '')) ?>
        </span>
      </div>
      <a href="cursos_tablero.php?action=logout"
         class="btn btn-sm btn-outline-light"
         onclick="event.preventDefault(); Swal.fire({title:'¿Cerrar sesión?',text:'¿Estás seguro de que deseas salir?',icon:'question',showCancelButton:true,confirmButtonText:'Sí, salir',cancelButtonText:'Cancelar',confirmButtonColor:'#1a3a5c'}).then((result)=>{if(result.isConfirmed){window.location.href='cursos_tablero.php?action=logout'}}); return false;">
        <i class="bi bi-box-arrow-right me-1"></i>
        <span class="d-none d-md-inline">Salir</span>
      </a>
    </div>
  </div>
</nav>

<!-----------------------Contenido------------------------------------------>

<div class="container-fluid px-3 py-4" style="max-width:1200px;">

  <?php if ($cursoActivo): ?>
  <!-- ══ VISTA DE CURSO ACTIVO ══════════════════════════════════════════════ -->
  <div class="mb-3">
    <a href="cursos_tablero.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-arrow-left me-1"></i>Mis cursos
    </a>
  </div>

  <!-- Encabezado del curso -->
  <div class="card border-0 shadow-sm mb-4" style="border-radius:14px;">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap gap-3 align-items-start justify-content-between">
        <div>
          <?php
            $platC = strtolower($cursoActivo['plataforma']);
            $platMap = ['zoom'=>['#2D8CFF','bi-camera-video-fill','Zoom'],
                        'teams'=>['#6264A7','bi-microsoft-teams','Teams'],
                        'meet'=>['#00897B','bi-google','Meet']];
            [$pbg,$pico,$plbl] = $platMap[$platC] ?? ['#6c757d','bi-globe','Otro'];
          ?>
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="plat-badge plat-<?= $platC ?>"
                  style="background:<?= $pbg ?>">
              <i class="bi <?= $pico ?>"></i><?= $plbl ?>
            </span>
            <?= cursoEstadoBadge($cursoActivo['estado']) ?>
            <?php
              $partBadgeMap = ['PENDIENTE'=>'bg-warning text-dark','EN_CURSO'=>'bg-info text-dark','COMPLETADO'=>'bg-success'];
              $partCls = $partBadgeMap[$cursoActivo['part_estado']] ?? 'bg-secondary';
            ?>
            <span class="badge <?= $partCls ?> estado-badge">
              <?= h($cursoActivo['part_estado']) ?>
            </span>
          </div>
          <h3 class="fw-bold mb-1"><?= h($cursoActivo['nombre']) ?></h3>
          <?php if ($cursoActivo['descripcion']): ?>
          <p class="text-muted mb-0"><?= nl2br(h($cursoActivo['descripcion'])) ?></p>
          <?php endif; ?>
        </div>
        <?php if ($cursoActivo['plataforma_url'] && $cursoActivo['estado'] === 'ACTIVO'): ?>
        <a href="<?= h($cursoActivo['plataforma_url']) ?>" target="_blank" rel="noopener"
           class="btn btn-unirse btn-lg px-4">
          <i class="bi bi-camera-video-fill me-2"></i>Unirme a la sesión
        </a>
        <?php endif; ?>
      </div>

      <!-- Fechas -->
      <?php if ($cursoActivo['fecha_inicio'] || $cursoActivo['fecha_fin']): ?>
      <div class="d-flex flex-wrap gap-3 mt-3 pt-3 border-top small text-muted">
        <?php if ($cursoActivo['fecha_inicio']): ?>
        <span><i class="bi bi-calendar-event me-1"></i>
          Inicio: <strong class="text-dark">
            <?= date('d/m/Y H:i', strtotime($cursoActivo['fecha_inicio'])) ?>
          </strong>
        </span>
        <?php endif; ?>
        <?php if ($cursoActivo['fecha_fin']): ?>
        <span><i class="bi bi-calendar-check me-1"></i>
          Fin: <strong class="text-dark">
            <?= date('d/m/Y H:i', strtotime($cursoActivo['fecha_fin'])) ?>
          </strong>
        </span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Tablero de materiales -->
  <h5 class="fw-bold mb-3">
    <i class="bi bi-grid-3x3-gap-fill me-2 text-primary"></i>Tablero de Materiales
  </h5>

  <?php if (empty($cursoActivo['materiales'])): ?>
  <div class="empty-state">
    <i class="bi bi-folder2-open"></i>
    <p class="text-muted">El administrador aún no ha publicado materiales para este curso.</p>
  </div>
  <?php else: ?>
  <div class="row g-3">
    <?php foreach ($cursoActivo['materiales'] as $mat): ?>
    <div class="col-md-6 col-xl-4">
      <div class="material-card p-3 h-100 material-<?= strtolower($mat['tipo']) ?>">
        <div class="d-flex gap-3 align-items-start">
          <div class="flex-shrink-0 mt-1">
            <?php if ($mat['tipo'] === 'ENLACE'): ?>
            <div class="rounded-2 d-flex align-items-center justify-content-center"
                 style="width:40px;height:40px;background:#eff6ff;">
              <i class="bi bi-link-45deg text-primary fs-5"></i>
            </div>
            <?php else: ?>
            <?php
              $ext = strtolower(pathinfo($mat['archivo_nombre'] ?? '', PATHINFO_EXTENSION));
              $fileIcons = ['pdf'=>'bi-file-earmark-pdf text-danger',
                            'doc'=>'bi-file-earmark-word text-primary',
                            'docx'=>'bi-file-earmark-word text-primary',
                            'xls'=>'bi-file-earmark-excel text-success',
                            'xlsx'=>'bi-file-earmark-excel text-success',
                            'ppt'=>'bi-file-earmark-ppt text-warning',
                            'pptx'=>'bi-file-earmark-ppt text-warning',
                            'zip'=>'bi-file-earmark-zip text-secondary'];
              $fileIco = $fileIcons[$ext] ?? 'bi-file-earmark text-muted';
            ?>
            <div class="rounded-2 d-flex align-items-center justify-content-center"
                 style="width:40px;height:40px;background:#f0fdf4;">
              <i class="bi <?= $fileIco ?> fs-5"></i>
            </div>
            <?php endif; ?>
          </div>
          <div class="flex-grow-1 min-w-0">
            <div class="fw-semibold text-truncate mb-1"><?= h($mat['titulo']) ?></div>
            <?php if ($mat['descripcion']): ?>
            <div class="small text-muted mb-2"><?= h($mat['descripcion']) ?></div>
            <?php endif; ?>
            <?php if ($mat['tipo'] === 'ENLACE' && $mat['url']): ?>
            <a href="<?= h($mat['url']) ?>" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-primary">
              <i class="bi bi-box-arrow-up-right me-1"></i>Abrir enlace
            </a>
            <?php elseif ($mat['tipo'] === 'ARCHIVO' && $mat['archivo_path']): ?>
            <a href="<?= h($mat['archivo_path']) ?>" download="<?= h($mat['archivo_nombre'] ?? 'archivo') ?>"
               class="btn btn-sm btn-outline-success">
              <i class="bi bi-download me-1"></i>Descargar
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <!-- ══ LISTA DE MIS CURSOS ════════════════════════════════════════════════ -->

  <!-- Saludo -->
  <div class="d-flex align-items-center gap-3 mb-4">
    <div class="avatar-circle-lg">
      <?= strtoupper(substr($usuario['nombre'], 0, 1)) ?>
    </div>
    <div>
      <h5 class="mb-0 fw-bold">
        Hola, <?= h($usuario['nombre']) ?>
      </h5>
      <div class="small text-muted">
        <?= h($usuario['puesto'] ?? '') ?>
        <?= $usuario['area_nombre'] ? '· '.h($usuario['area_nombre']) : '' ?>
      </div>
    </div>
  </div>

  <?php if (empty($misCursos)): ?>
  <div class="empty-state">
    <i class="bi bi-mortarboard"></i>
    <h6>Sin cursos asignados</h6>
    <p class="text-muted small">
      Aún no tienes cursos asignados.<br>
      Contacta al administrador si crees que es un error.
    </p>
  </div>
  <?php else: ?>

  <h6 class="fw-bold text-muted text-uppercase small mb-3 letter-spacing-1">
    Mis Cursos Asignados
  </h6>
  <div class="row g-3">
    <?php foreach ($misCursos as $c):
      $platC2 = strtolower($c['plataforma']);
      [$pbg2,$pico2,$plbl2] = $platMap[$platC2] ?? ['#6c757d','bi-globe','Otro'];
      $partBadgeCls2 = $partBadgeMap[$c['part_estado']] ?? 'bg-secondary';
    ?>
    <div class="col-md-6 col-xl-4">
      <a href="cursos_tablero.php?curso=<?= $c['id'] ?>" class="text-decoration-none">
        <div class="card curso-card p-3 h-100 <?= $c['estado']==='ACTIVO'?'activo':'' ?>">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <span class="plat-badge" style="background:<?= $pbg2 ?>">
              <i class="bi <?= $pico2 ?>"></i><?= $plbl2 ?>
            </span>
            <?= cursoEstadoBadge($c['estado']) ?>
          </div>
          <h6 class="fw-bold text-dark mb-1"><?= h($c['nombre']) ?></h6>
          <?php if ($c['descripcion']): ?>
          <p class="small text-muted mb-2" style="overflow:hidden;display:-webkit-box;display:box;-webkit-line-clamp:2;line-clamp:2;-webkit-box-orient:vertical;box-orient:vertical;">
            <?= h($c['descripcion']) ?>
          </p>
          <?php endif; ?>
          <div class="mt-auto pt-2 border-top d-flex justify-content-between align-items-center">
            <span class="badge <?= $partBadgeCls2 ?> estado-badge">
              <?= h($c['part_estado']) ?>
            </span>
            <?php if ($c['fecha_inicio']): ?>
            <span class="small text-muted">
              <i class="bi bi-calendar3 me-1"></i>
              <?= date('d/m/Y', strtotime($c['fecha_inicio'])) ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

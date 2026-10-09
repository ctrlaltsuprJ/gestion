<?php
require_once 'includes/auth.php';

$action = $_GET['action'] ?? 'list';
$id = (int) ($_GET['id'] ?? 0);
$msg = '';

// ── POST ──────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  if (isNormal()) {
    flash('danger', 'Sin permiso.');
    redirect('equipos.php');
  }

  $pid = (int) ($_POST['id'] ?? 0) ?: null;
  $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

  if (isSuper() && $pid) {
    // Supervisor: guardar como cambio pendiente de aprobación
    $cpId = guardarCambioPendiente('equipos', $pid, 'UPDATE', $_POST, 'Edición de equipo ' . $_POST['folio']);
    auditLog('EDITAR_EQUIPO_PENDIENTE', 'equipos', $pid, 'Cambio pendiente #' . $cpId);
    if ($isAjax) {
      header('Content-Type: application/json');
      echo json_encode(['success' => true, 'id' => $pid, 'folio' => $_POST['folio'] ?? '']);
      exit;
    }
    flash('warning', 'Cambios enviados para aprobación del Administrador (Cambio #' . $cpId . ').');
    redirect('equipos.php?action=view&id=' . $pid);
  }

  // Admin: guardar directo
  try {
    $saved = saveEquipo($_POST, $pid);
  } catch (\PDOException $e) {
    if (!$pid && str_contains($e->getMessage(), 'Duplicate')) {
      if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'El folio (u otro dato único) ya existe. Revisa folio e IP.']);
        exit;
      }
      flash('danger', 'No se pudo crear el equipo: el folio (u otro dato único) ya existe. Revisa folio e IP.');
      redirect('equipos.php?action=new');
    }
    throw $e;
  }
  auditLog($pid ? 'EDITAR_EQUIPO' : 'CREAR_EQUIPO', 'equipos', $saved, 'Folio: ' . ($_POST['folio'] ?? ''));
  
  if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'id' => $saved, 'folio' => $_POST['folio'] ?? '']);
    exit;
  }
  
  flash('success', $pid ? 'Equipo actualizado.' : 'Equipo creado: ' . $_POST['folio'] . '.');
  redirect('equipos.php?action=view&id=' . $saved);
}

// ── DELETE ────────────────────────────────────────────────
if ($action === 'delete' && $id) {
  requireRol(ROL_ADMIN);
  deleteEquipo($id);
  auditLog('ELIMINAR_EQUIPO', 'equipos', $id);
  flash('warning', 'Equipo dado de baja.');
  redirect('equipos.php');
}

// ── DATA ──────────────────────────────────────────────────
$q = trim($_GET['q'] ?? '');
$estado = $_GET['estado'] ?? '';
$tipo = (int) ($_GET['tipo'] ?? 0);
$area = (int) ($_GET['area'] ?? 0);
$headerFilters = [];
for ($i = 0; $i <= 9; $i++) {
  $headerFilters[$i] = trim((string)($_GET['hf' . $i] ?? ''));
}

$pageTitle = 'Equipos';
include 'includes/header.php';

$areas = getAreas();
$tipos = getTiposEquipo();
$usuarios = getUsuarios();

// ── USUARIO NORMAL: solo ve sus equipos ──────────────────
if (isNormal()):
  $usuarioId = usuarioIdActual();
  $misEquipos = [];
  if ($usuarioId) {
    $s = getDB()->prepare("SELECT e.*,t.nombre tipo_nombre,t.icono tipo_icono,a.nombre area_nombre FROM equipos e LEFT JOIN tipos_equipo t ON t.id=e.tipo_id LEFT JOIN areas a ON a.id=e.area_id WHERE e.usuario_id=? AND e.activo=1 ORDER BY e.folio");
    $s->execute([$usuarioId]);
    $misEquipos = $s->fetchAll();
  }
  ?>
  <div class="container-fluid px-3 py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="mb-0"><i class="bi bi-pc-display me-2"></i>Mis Equipos Asignados</h5>
      <a href="tickets.php?action=new" class="btn btn-sm btn-danger">
        <i class="bi bi-ticket me-1"></i>Solicitar Soporte
      </a>
    </div>

    <?php if (empty($misEquipos)): ?>
      <div class="card">
        <div class="card-body text-center py-5 text-muted">
          <i class="bi bi-pc-display fs-1 d-block mb-2 opacity-25"></i>
          No tienes equipos asignados. Contacta al Administrador TI.
        </div>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($misEquipos as $e):
          $cls = ['ACTIVO' => 'badge-activo', 'MANTENIMIENTO' => 'badge-mantenimiento', 'BAJA' => 'badge-baja', 'INACTIVO' => 'badge-inactivo'][$e['estado']] ?? 'bg-secondary';
          ?>
          <div class="col-md-6 col-xl-4">
            <div class="my-equipo-card card h-100">
              <div class="my-equipo-header d-flex justify-content-between align-items-start">
                <div>
                  <i class="bi <?= h($e['tipo_icono'] ?? 'bi-pc-display') ?> fs-4 mb-1 d-block"></i>
                  <strong><?= h($e['tipo_nombre'] ?? '') ?></strong>
                </div>
                <span class="badge <?= $cls ?>"><?= h($e['estado']) ?></span>
              </div>
              <div class="card-body">
                <h6 class="mb-1"><?= h($e['folio']) ?> — <?= h($e['marca'] . ' ' . ($e['modelo'] ?? '')) ?></h6>
                <table class="table table-sm mb-0">
                  <?php foreach ([['Área', $e['area_nombre']], ['IP', '<code>' . h($e['ip'] ?? '') . '</code>'], ['Ubicación', $e['ubicacion']], ['Serie', $e['serie']]] as [$k, $v]):
                    if (!$v)
                      continue; ?>
                    <tr>
                      <th class="small text-muted" style="width:40%"><?= $k ?></th>
                      <td class="small"><?= $v ?></td>
                    </tr>
                  <?php endforeach; ?>
                </table>
              </div>
              <div class="card-footer bg-transparent d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-outline-info"
                  data-eq="<?= htmlspecialchars(json_encode(equipoPayloadFichaModal($e), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                  onclick="abrirModalFichaEquipo(this)" title="Ficha técnica">
                  <i class="bi bi-cpu me-1"></i>Ficha técnica
                </button>
                <a href="equipos.php?action=view&id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill">
                  <i class="bi bi-eye me-1"></i>Ver Detalles
                </a>
                <a href="tickets.php?action=new&equipo_id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-danger flex-fill">
                  <i class="bi bi-ticket me-1"></i>Solicitar
                </a>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="modal fade" id="modalFichaEquipo" tabindex="-1" aria-labelledby="modalFichaEquipoLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header text-white" style="background:#1a3a5c;">
          <h5 class="modal-title" id="modalFichaEquipoLabel"><i class="bi bi-pc-display me-2"></i>Ficha del equipo</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body" id="modalFichaEquipoBody"></div>
        <div class="modal-footer">
          <a href="#" class="btn btn-sm btn-primary" id="modalFichaEquipoLinkVer"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir vista completa</a>
          <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
        </div>
      </div>
    </div>
  </div>
  <script>
    function abrirModalFichaEquipo(btn) {
      const eq = JSON.parse(btn.getAttribute('data-eq'));
      const esc = (s) => {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
      };
      const val = (s) => {
        const t = (s == null ? '' : String(s)).trim();
        return t ? esc(t) : '<span class="text-muted">—</span>';
      };
      const badgeEstado = {
        'ACTIVO': 'badge-activo',
        'MANTENIMIENTO': 'badge-mantenimiento',
        'BAJA': 'badge-baja',
        'INACTIVO': 'badge-inactivo'
      };
      const clsEst = badgeEstado[eq.estado] || 'bg-secondary';
      const inetCls = {
        'SIN_ACCESO': 'secondary',
        'RESTRINGIDO': 'warning text-dark',
        'COMPLETO': 'success'
      };
      const inetIco = {
        'SIN_ACCESO': 'bi-wifi-off',
        'RESTRINGIDO': 'bi-lock-fill',
        'COMPLETO': 'bi-globe'
      };
      const inetHtml = eq.acceso_internet
        ? `<span class="badge bg-${inetCls[eq.tipo_internet] || 'secondary'}"><i class="bi ${inetIco[eq.tipo_internet] || 'bi-question'} me-1"></i>${esc(eq.tipo_internet_label || '')}</span>${eq.ip_publica ? ' <code class="small ms-1">' + esc(eq.ip_publica) + '</code>' : ''}`
        : '<span class="badge bg-secondary"><i class="bi bi-wifi-off me-1"></i>Sin internet</span>';
      const mant = (eq.total_mantenimientos || 0) > 0
        ? `<span class="badge bg-success">Sí (${eq.total_mantenimientos})</span>`
        : '<span class="badge bg-secondary">No</span>';
      const fila = (lab, html) => `<tr><th class="text-muted small ps-0" style="width:38%">${esc(lab)}</th><td>${html}</td></tr>`;
      const tbl = (title, icon, rows) => `
        <div class="mb-3">
          <div class="fw-semibold small text-uppercase mb-2 pb-1 border-bottom" style="color:#1a3a5c;letter-spacing:.4px;">
            <i class="bi ${icon} me-2"></i>${esc(title)}
          </div>
          <table class="table table-sm mb-0"><tbody>${rows.join('')}</tbody></table>
        </div>`;
      const mm = [eq.marca, eq.modelo].map(x => (x || '').trim()).filter(Boolean).join(' · ');
      document.getElementById('modalFichaEquipoBody').innerHTML = `
        <div class="d-flex align-items-start gap-3 mb-3 pb-2 border-bottom">
          <i class="bi ${esc(eq.tipo_icono || 'bi-pc-display')} fs-1 text-primary opacity-75"></i>
          <div>
            <div class="fs-5 fw-bold">${val(eq.folio)}</div>
            <div class="text-muted">${val(eq.tipo_nombre)} · ${mm ? esc(mm) : '<span class="text-muted">—</span>'}</div>
            <span class="badge ${clsEst} mt-1">${esc(eq.estado || '—')}</span>
          </div>
        </div>
        ${tbl('Identificación', 'bi-tag', [
          fila('Folio', val(eq.folio)),
          fila('Tipo', val(eq.tipo_nombre)),
          fila('Marca / modelo', mm ? esc(mm) : '<span class="text-muted">—</span>'),
          fila('No. de serie', val(eq.serie)),
          fila('F. adquisición', val(eq.fecha_adquisicion)),
        ])}
        ${tbl('Características técnicas', 'bi-cpu', [
          fila('Sistema operativo', val(eq.sistema_operativo)),
          fila('Procesador', val(eq.procesador)),
          fila('Memoria RAM', val(eq.ram)),
          fila('Almacenamiento', val(eq.almacenamiento)),
          fila('Pantalla / monitor', val(eq.pantalla)),
          fila('Tarjeta de video', val(eq.tarjeta_video)),
        ])}
        ${tbl('Red y conectividad', 'bi-hdd-network', [
          fila('Dirección IP', eq.ip ? `<code>${esc(eq.ip)}</code>` : '<span class="text-muted">—</span>'),
          fila('MAC', eq.mac_address ? `<code class="small">${esc(eq.mac_address)}</code>` : '<span class="text-muted">—</span>'),
          fila('Nodo', val(eq.nodo)),
          fila('Conexión', val(eq.conexion_medio)),
          fila('Internet', inetHtml),
        ])}
        ${tbl('Ubicación y asignación', 'bi-geo-alt', [
          fila('Área', val(eq.area_nombre)),
          fila('Usuario asignado', val(eq.usuario_nombre)),
          fila('Ubicación física', val(eq.ubicacion)),
          fila('Historial mantenimiento', mant),
        ])}
        ${(eq.descripcion && String(eq.descripcion).trim()) ? `
        <div class="mb-0">
          <div class="fw-semibold small text-uppercase mb-2 pb-1 border-bottom" style="color:#1a3a5c;letter-spacing:.4px;">
            <i class="bi bi-card-text me-2"></i>Descripción / notas
          </div>
          <div class="small bg-light rounded p-2 border">${esc(eq.descripcion)}</div>
        </div>` : ''}`;
      document.getElementById('modalFichaEquipoLabel').innerHTML = '<i class="bi bi-pc-display me-2"></i>' + esc(eq.folio || 'Equipo');
      const link = document.getElementById('modalFichaEquipoLinkVer');
      if (link && eq.id) {
        link.href = 'equipos.php?action=view&id=' + encodeURIComponent(eq.id);
        link.style.display = '';
      } else if (link) link.style.display = 'none';
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFichaEquipo')).show();
    }
  </script>
  <?php include 'includes/footer.php';
  return;
endif; // end isNormal
?>
<div class="container-fluid px-3 py-3">

  <?php if ($action === 'list'): ?>
    <!-- ══ LISTA ══ -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h5 class="mb-0"><i class="bi bi-pc-display me-2"></i>Equipos</h5>
      <div class="d-flex gap-2">
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i
            class="bi bi-printer me-1"></i>Imprimir</button>
        <a href="exports/export_equipos_pdf.php<?= $q ? "?q=" . urlencode($q) : '' ?>" target="_blank"
          class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        <a href="exports/export_equipos_xls.php<?= $q ? "?q=" . urlencode($q) : '' ?>" class="btn btn-sm btn-outline-success"><i
            class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        <?php if (puede('crear_equipo')): ?>
          <a href="equipos.php?action=new" class="btn btn-sm" style="background:#1a3a5c;color:#fff;"><i
              class="bi bi-plus me-1"></i>Nuevo Equipo</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Filtros -->
    <div class="card mb-3">
      <div class="card-body py-2">
        <form id="form-search" method="GET" action="equipos.php" class="row g-2 align-items-end">
          <div class="col-sm-3">
            <input type="search" name="q" id="input-search" class="form-control form-control-sm"
              placeholder="Folio, IP, MAC, marca, usuario…" value="<?= h($q) ?>">
          </div>
          <div class="col-sm-2">
            <select name="tipo" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="">— Tipo —</option>
              <?php foreach ($tipos as $t): ?>
                <option value="<?= $t['id'] ?>" <?= $tipo == (int) $t['id'] ? 'selected' : '' ?>><?= h($t['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-2">
            <select name="area" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="">— Área —</option>
              <?php foreach ($areas as $a): ?>
                <option value="<?= $a['id'] ?>" <?= $area == (int) $a['id'] ? 'selected' : '' ?>><?= h($a['nombre']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-2">
            <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="">— Estado —</option>
              <?php foreach (['ACTIVO', 'INACTIVO', 'MANTENIMIENTO', 'BAJA'] as $e): ?>
                <option <?= $estado === $e ? 'selected' : '' ?>><?= $e ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-2">
           <input list="opciones_segmento" type="text" name="ip_segmento" class="form-control form-control-sm" placeholder="Segmento IP (ej: 10.27.41)" value="<?= h($_GET['ip_segmento'] ?? '') ?>">
                  <datalist id="opciones_segmento">
                <option value="10.27.41"></option>
                <option value="10.27.45"></option>
            </datalist>
          </div>
          <div class="col-sm-2">
            <select name="ip_orden" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="">— Orden IP —</option>
              <option value="ASC" <?= ($_GET['ip_orden'] ?? '') === 'ASC' ? 'selected' : '' ?>>Ascendente</option>
              <option value="DESC" <?= ($_GET['ip_orden'] ?? '') === 'DESC' ? 'selected' : '' ?>>Descendente</option>
            </select>
          </div>
          <div class="col-auto">
            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-search"></i></button>
            <?php if ($q || $estado || $tipo || $area || ($_GET['ip_segmento'] ?? '') || ($_GET['ip_orden'] ?? '')): ?><a href="equipos.php" class="btn btn-sm btn-outline-secondary"><i
                  class="bi bi-x"></i></a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <?php $equipos = getEquipos($q, $estado, $tipo, $area, $_GET['ip_segmento'] ?? '', $_GET['ip_orden'] ?? ''); ?>
    <div class="card">
      <div class="card-body py-2">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><i class="bi bi-search"></i></span>
          <input type="text" id="buscador-rapido" class="form-control" placeholder="Buscar rápido en la tabla (folio, IP, marca, usuario, área...)">
          <button class="btn btn-outline-secondary" type="button" id="btn-limpiar-buscador-rapido" title="Limpiar búsqueda">
            <i class="bi bi-x"></i>
          </button>
        </div>
      </div>
      <div class="card-body p-0 pt-2">
        <div class="table-responsive">
          <table class="table table-ti mb-0" id="tabla-equipos">
            <thead>
              <tr>
                <th>Folio</th>
                <th>Tipo</th>
                <th>Marca/Modelo</th>
                <th>IP</th>
                <th>Internet</th>
                <th>Nodo</th>
                <th>Área</th>
                <th>Usuario</th>
                <th>Estado</th>
                <th>Mantenimiento</th>
                <th class="no-print">Acciones</th>
              </tr>
              <tr class="no-print">
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="0" placeholder="Filtrar folio" value="<?= h($headerFilters[0] ?? '') ?>"></th>
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="1" placeholder="Filtrar tipo" value="<?= h($headerFilters[1] ?? '') ?>"></th>
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="2" placeholder="Filtrar marca/modelo" value="<?= h($headerFilters[2] ?? '') ?>"></th>
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="3" placeholder="Filtrar IP" value="<?= h($headerFilters[3] ?? '') ?>"></th>
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="4" placeholder="Filtrar internet" value="<?= h($headerFilters[4] ?? '') ?>"></th>
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="5" placeholder="Filtrar nodo" value="<?= h($headerFilters[5] ?? '') ?>"></th>
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="6" placeholder="Filtrar área" value="<?= h($headerFilters[6] ?? '') ?>"></th>
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="7" placeholder="Filtrar usuario" value="<?= h($headerFilters[7] ?? '') ?>"></th>
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="8" placeholder="Filtrar estado" value="<?= h($headerFilters[8] ?? '') ?>"></th>
                <th><input type="text" class="form-control form-control-sm filtro-columna" data-col="9" placeholder="Filtrar mant." value="<?= h($headerFilters[9] ?? '') ?>"></th>
                <th class="no-print text-center">
                  <button type="button" class="btn btn-xs btn-outline-secondary" id="btn-limpiar-filtros-equipos" title="Limpiar filtros">
                    <i class="bi bi-eraser"></i>
                  </button>
                </th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($equipos as $e):
                $cls = ['ACTIVO' => 'badge-activo', 'MANTENIMIENTO' => 'badge-mantenimiento', 'BAJA' => 'badge-baja', 'INACTIVO' => 'badge-inactivo'][$e['estado']] ?? 'bg-secondary';
                $inetCls = ['SIN_ACCESO' => 'secondary', 'RESTRINGIDO' => 'warning text-dark', 'COMPLETO' => 'success'][$e['tipo_internet'] ?? 'SIN_ACCESO'] ?? 'secondary';
                $inetIco = ['SIN_ACCESO' => 'bi-wifi-off', 'RESTRINGIDO' => 'bi-lock-fill', 'COMPLETO' => 'bi-globe'][$e['tipo_internet'] ?? 'SIN_ACCESO'] ?? 'bi-wifi-off';
                $inetLbl = ['SIN_ACCESO' => 'Sin Acceso', 'RESTRINGIDO' => 'Rest.', 'COMPLETO' => 'Completo'][$e['tipo_internet'] ?? 'SIN_ACCESO'] ?? '—';
                ?>
                <tr style="cursor:pointer" onclick="location.href='equipos.php?action=view&id=<?= $e['id'] ?>'">
                  <td><code class="text-primary"><?= h($e['folio']) ?></code></td>
                  <td><i class="bi <?= h($e['tipo_icono'] ?? 'bi-pc-display') ?> me-1"></i><?= h($e['tipo_nombre'] ?? '') ?></td>
                  <td><strong><?= h($e['marca']) ?></strong><br><span class="text-muted small"><?= h($e['modelo'] ?? '') ?></span>
                  </td>
                  <td><code><?= h($e['ip'] ?? '') ?></code></td>
                  <td>
                    <?php if (!empty($e['acceso_internet'])): ?>
                      <span class="badge bg-<?= $inetCls ?>" style="font-size:.68rem;"><i
                          class="bi <?= $inetIco ?> me-1"></i><?= $inetLbl ?></span>
                    <?php else: ?>
                      <span class="text-muted small">—</span>
                    <?php endif; ?>
                  </td>
                  <td class="small"><?= h($e['nodo'] ?? '') ?></td>
                  <td class="small"><?= h($e['area_nombre'] ?? '') ?></td>
                  <td class="small"><?= h(trim($e['usuario_nombre'] ?? '')) ?></td>
                  <td><span class="badge <?= $cls ?>"><?= h($e['estado']) ?></span></td>
                  <td class="small">
                    <?php if ((int)($e['total_mantenimientos'] ?? 0) > 0): ?>
                      <span class="badge bg-success">SI (<?= (int)$e['total_mantenimientos'] ?>)</span>
                    <?php else: ?>
                      <span class="badge bg-secondary">NO</span>
                    <?php endif; ?>
                  </td>
                  <td class="no-print" onclick="event.stopPropagation()">
                    <button type="button" class="btn btn-xs btn-outline-info me-1" title="Ficha técnica"
                      data-eq="<?= htmlspecialchars(json_encode(equipoPayloadFichaModal($e), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                      onclick="abrirModalFichaEquipo(this)"><i class="bi bi-cpu"></i></button>
                    <a href="equipos.php?action=edit&id=<?= $e['id'] ?>" class="btn btn-xs btn-outline-primary me-1"
                      title="Editar"><i class="bi bi-pencil"></i></a>
                    <?php if (puede('crear_mantenimiento')): ?>
                      <a href="mantenimiento.php?action=new&equipo_id=<?= $e['id'] ?>"
                        class="btn btn-xs btn-outline-warning me-1" title="Mantenimiento"><i class="bi bi-tools"></i></a>
                    <?php endif; ?>
                    <?php if (puede('eliminar_equipo')): ?>
                      <button
                        onclick="confirmDelete('equipos.php?action=delete&id=<?= $e['id'] ?>','<?= addslashes($e['folio']) ?>')"
                        class="btn btn-xs btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($equipos)): ?>
                <tr>
                  <td colspan="11" class="text-center text-muted py-4">Sin equipos registrados</td>
                </tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <script>
      (() => {
        const tabla = document.getElementById('tabla-equipos');
        if (!tabla) return;

        const tbody = tabla.querySelector('tbody');
        const filas = Array.from(tbody.querySelectorAll('tr')).filter((tr) => tr.children.length > 1);
        const inputs = Array.from(tabla.querySelectorAll('.filtro-columna'));
        const btnLimpiar = document.getElementById('btn-limpiar-filtros-equipos');
        const buscadorRapido = document.getElementById('buscador-rapido');
        const btnLimpiarBuscadorRapido = document.getElementById('btn-limpiar-buscador-rapido');
        const urlActual = new URL(window.location.href);

        function normalizar(valor) {
          return (valor || '')
            .toString()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .trim();
        }

        function syncUrl() {
          inputs.forEach((input) => {
            const col = parseInt(input.dataset.col || '-1', 10);
            if (col < 0) return;
            const key = `hf${col}`;
            const valor = input.value.trim();
            if (valor) {
              urlActual.searchParams.set(key, valor);
            } else {
              urlActual.searchParams.delete(key);
            }
          });
          window.history.replaceState({}, '', urlActual);
        }

        function aplicarFiltros() {
          const filtros = inputs.map((input) => ({
            col: parseInt(input.dataset.col || '-1', 10),
            valor: normalizar(input.value)
          })).filter((f) => f.col >= 0 && f.valor !== '');

          const busquedaRapida = normalizar(buscadorRapido?.value || '');

          filas.forEach((fila) => {
            const coincideColumnas = filtros.every((f) => {
              const celda = fila.children[f.col];
              return normalizar(celda ? celda.textContent : '').includes(f.valor);
            });

            let coincideBusquedaRapida = true;
            if (busquedaRapida) {
              const textoFila = Array.from(fila.children).map(c => normalizar(c.textContent)).join(' ');
              coincideBusquedaRapida = textoFila.includes(busquedaRapida);
            }

            fila.style.display = (coincideColumnas && coincideBusquedaRapida) ? '' : 'none';
          });
          syncUrl();
        }

        inputs.forEach((input) => input.addEventListener('input', aplicarFiltros));
        btnLimpiar?.addEventListener('click', () => {
          inputs.forEach((input) => {
            input.value = '';
          });
          aplicarFiltros();
        });

        buscadorRapido?.addEventListener('input', aplicarFiltros);
        btnLimpiarBuscadorRapido?.addEventListener('click', () => {
          if (buscadorRapido) buscadorRapido.value = '';
          aplicarFiltros();
        });

        // Aplicar estado inicial (incluye filtros restaurados por URL)
        aplicarFiltros();
      })();
    </script>

    <div class="modal fade" id="modalFichaEquipo" tabindex="-1" aria-labelledby="modalFichaEquipoLabel" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header text-white" style="background:#1a3a5c;">
            <h5 class="modal-title" id="modalFichaEquipoLabel"><i class="bi bi-pc-display me-2"></i>Ficha del equipo</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
          </div>
          <div class="modal-body" id="modalFichaEquipoBody"></div>
          <div class="modal-footer">
            <a href="#" class="btn btn-sm btn-primary" id="modalFichaEquipoLinkVer"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir vista completa</a>
            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cerrar</button>
          </div>
        </div>
      </div>
    </div>
    <script>
      function abrirModalFichaEquipo(btn) {
        const eq = JSON.parse(btn.getAttribute('data-eq'));
        const esc = (s) => {
          const d = document.createElement('div');
          d.textContent = s == null ? '' : String(s);
          return d.innerHTML;
        };
        const val = (s) => {
          const t = (s == null ? '' : String(s)).trim();
          return t ? esc(t) : '<span class="text-muted">—</span>';
        };
        const badgeEstado = {
          'ACTIVO': 'badge-activo',
          'MANTENIMIENTO': 'badge-mantenimiento',
          'BAJA': 'badge-baja',
          'INACTIVO': 'badge-inactivo'
        };
        const clsEst = badgeEstado[eq.estado] || 'bg-secondary';
        const inetCls = {
          'SIN_ACCESO': 'secondary',
          'RESTRINGIDO': 'warning text-dark',
          'COMPLETO': 'success'
        };
        const inetIco = {
          'SIN_ACCESO': 'bi-wifi-off',
          'RESTRINGIDO': 'bi-lock-fill',
          'COMPLETO': 'bi-globe'
        };
        const inetHtml = eq.acceso_internet
          ? `<span class="badge bg-${inetCls[eq.tipo_internet] || 'secondary'}"><i class="bi ${inetIco[eq.tipo_internet] || 'bi-question'} me-1"></i>${esc(eq.tipo_internet_label || '')}</span>${eq.ip_publica ? ' <code class="small ms-1">' + esc(eq.ip_publica) + '</code>' : ''}`
          : '<span class="badge bg-secondary"><i class="bi bi-wifi-off me-1"></i>Sin internet</span>';
        const mant = (eq.total_mantenimientos || 0) > 0
          ? `<span class="badge bg-success">Sí (${eq.total_mantenimientos})</span>`
          : '<span class="badge bg-secondary">No</span>';
        const fila = (lab, html) => `<tr><th class="text-muted small ps-0" style="width:38%">${esc(lab)}</th><td>${html}</td></tr>`;
        const tbl = (title, icon, rows) => `
          <div class="mb-3">
            <div class="fw-semibold small text-uppercase mb-2 pb-1 border-bottom" style="color:#1a3a5c;letter-spacing:.4px;">
              <i class="bi ${icon} me-2"></i>${esc(title)}
            </div>
            <table class="table table-sm mb-0"><tbody>${rows.join('')}</tbody></table>
          </div>`;
        const mm = [eq.marca, eq.modelo].map(x => (x || '').trim()).filter(Boolean).join(' · ');
        document.getElementById('modalFichaEquipoBody').innerHTML = `
          <div class="d-flex align-items-start gap-3 mb-3 pb-2 border-bottom">
            <i class="bi ${esc(eq.tipo_icono || 'bi-pc-display')} fs-1 text-primary opacity-75"></i>
            <div>
              <div class="fs-5 fw-bold">${val(eq.folio)}</div>
              <div class="text-muted">${val(eq.tipo_nombre)} · ${mm ? esc(mm) : '<span class="text-muted">—</span>'}</div>
              <span class="badge ${clsEst} mt-1">${esc(eq.estado || '—')}</span>
            </div>
          </div>
          ${tbl('Identificación', 'bi-tag', [
            fila('Folio', val(eq.folio)),
            fila('Tipo', val(eq.tipo_nombre)),
            fila('Marca / modelo', mm ? esc(mm) : '<span class="text-muted">—</span>'),
            fila('No. de serie', val(eq.serie)),
            fila('F. adquisición', val(eq.fecha_adquisicion)),
          ])}
          ${tbl('Características técnicas', 'bi-cpu', [
            fila('Sistema operativo', val(eq.sistema_operativo)),
            fila('Procesador', val(eq.procesador)),
            fila('Memoria RAM', val(eq.ram)),
            fila('Almacenamiento', val(eq.almacenamiento)),
            fila('Pantalla / monitor', val(eq.pantalla)),
            fila('Tarjeta de video', val(eq.tarjeta_video)),
          ])}
          ${tbl('Red y conectividad', 'bi-hdd-network', [
            fila('Dirección IP', eq.ip ? `<code>${esc(eq.ip)}</code>` : '<span class="text-muted">—</span>'),
            fila('MAC', eq.mac_address ? `<code class="small">${esc(eq.mac_address)}</code>` : '<span class="text-muted">—</span>'),
            fila('Nodo', val(eq.nodo)),
            fila('Conexión', val(eq.conexion_medio)),
            fila('Internet', inetHtml),
          ])}
          ${tbl('Ubicación y asignación', 'bi-geo-alt', [
            fila('Área', val(eq.area_nombre)),
            fila('Usuario asignado', val(eq.usuario_nombre)),
            fila('Ubicación física', val(eq.ubicacion)),
            fila('Historial mantenimiento', mant),
          ])}
          ${(eq.descripcion && String(eq.descripcion).trim()) ? `
          <div class="mb-0">
            <div class="fw-semibold small text-uppercase mb-2 pb-1 border-bottom" style="color:#1a3a5c;letter-spacing:.4px;">
              <i class="bi bi-card-text me-2"></i>Descripción / notas
            </div>
            <div class="small bg-light rounded p-2 border">${esc(eq.descripcion)}</div>
          </div>` : ''}`;
        document.getElementById('modalFichaEquipoLabel').innerHTML = '<i class="bi bi-pc-display me-2"></i>' + esc(eq.folio || 'Equipo');
        const link = document.getElementById('modalFichaEquipoLinkVer');
        if (link && eq.id) {
          link.href = 'equipos.php?action=view&id=' + encodeURIComponent(eq.id);
          link.style.display = '';
        } else if (link) link.style.display = 'none';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalFichaEquipo')).show();
      }
    </script>

  <?php elseif (in_array($action, ['new', 'edit'])): ?>
    <!-- ══ FORMULARIO ══ -->
    <?php
    $eq = $action === 'edit' && $id ? getEquipoById($id) : [];
    $isEdit = !empty($eq);
    ?>
    <script src="assets/sweetalert2@11"></script>
    <div class="row justify-content-center">
      <div class="col-xl-9">
        <div class="card">
          <div class="card-header-ti">
            <i class="bi bi-<?= $isEdit ? 'pencil' : 'plus-circle' ?> me-2"></i>
            <?= $isEdit ? 'Editar Equipo — ' . h($eq['folio']) : 'Nuevo Equipo' ?>
          </div>
          <div class="card-body">
            <form method="POST" action="equipos.php" id="form-equipo" data-es-nuevo="<?= $isEdit ? '0' : '1' ?>">
              <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $eq['id'] ?>"><?php endif; ?>
              <div class="row g-3">
                <div class="col-md-3">
                  <label class="form-label">Folio *</label>
                  <div class="input-group">
                    <input type="text" name="folio" id="folio" class="form-control"
                      value="<?= h($eq['folio'] ?? nextFolioEquipo()) ?>" required>
                    <button type="button" id="btn-gen-folio" class="btn btn-outline-secondary" title="Regenerar"><i
                        class="bi bi-arrow-repeat"></i></button>
                  </div>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Tipo de Equipo</label>
                  <select name="tipo_id" class="form-select">
                    <option value="">— Seleccionar —</option>
                    <?php foreach ($tipos as $t): ?>
                      <option value="<?= $t['id'] ?>" <?= (int) ($eq['tipo_id'] ?? 0) == (int) $t['id'] ? 'selected' : '' ?>>
                        <?= h($t['nombre']) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Estado</label>
                  <select name="estado" class="form-select" id="select-estado">
                    <?php foreach (['ACTIVO', 'INACTIVO', 'MANTENIMIENTO', 'BAJA'] as $e2): ?>
                      <option <?= ($eq['estado'] ?? 'ACTIVO') === $e2 ? 'selected' : '' ?>><?= $e2 ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Fecha Adquisición</label>
                  <input type="date" name="fecha_adquisicion" class="form-control"
                    value="<?= h($eq['fecha_adquisicion'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">Marca *</label>
                  <input type="text" name="marca" class="form-control" value="<?= h($eq['marca'] ?? '') ?>" required>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Modelo</label>
                  <input type="text" name="modelo" class="form-control" value="<?= h($eq['modelo'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                  <label class="form-label">No. Serie</label>
                  <input type="text" name="serie" class="form-control" value="<?= h($eq['serie'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Segmento de Red</label>
                  <select name="segmento_id" class="form-select" id="sel-segmento" onchange="actualizarPrefijo()">
                    <option value="">— Sin segmento —</option>
                    <?php
                    $segmentos = getSegmentosIP();
                    foreach ($segmentos as $seg):
                      ?>
                      <option value="<?= $seg['id'] ?>" data-red="<?= h($seg['red']) ?>" data-color="<?= h($seg['color']) ?>"
                        <?= (int) ($eq['segmento_id'] ?? 0) == (int) $seg['id'] ? 'selected' : '' ?>>
                        <?= h($seg['nombre']) ?> (<?= h($seg['red']) ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label class="form-label">Dirección IP</label>
                  <div class="input-group">
                    <span class="input-group-text" id="prefijo-ip"
                      style="font-size:.8rem;min-width:90px;background:#f8f9fa;">
                      <?= h($eq['ip_segmento'] ?? ($eq['ip'] ? substr($eq['ip'], 0, strrpos($eq['ip'], '.')) : '—')) ?>
                    </span>
                     <!-- <input type="text" name="ip_host" id="ip-host" class="form-control"
                        value="<?= h($eq['ip'] ? substr($eq['ip'], strrpos($eq['ip'], '.') + 1) : '') ?>" placeholder="host"
                        maxlength="3" oninput="construirIP()" style="max-width:80px;">-->
                        <input type="text" name="ip_host" id="ip-host" class="form-control"
                          value="<?= h(!empty($eq['ip']) ? substr($eq['ip'], strrpos($eq['ip'], '.') + 1) : '') ?>" 
                                      placeholder="host" maxlength="3" oninput="construirIP()" style="max-width:80px;">
                    <input type="hidden" name="ip" id="ip-completa" value="<?= h($eq['ip'] ?? '') ?>">
                    <input type="hidden" name="ip_segmento" id="ip-segmento-val" value="<?= h($eq['ip_segmento'] ?? '') ?>">
                  </div>
                  <div class="form-text" id="ip-preview">
                    <?php if ($eq['ip'] ?? false): ?><span
                        class="text-primary fw-semibold"><?= h($eq['ip']) ?></span><?php endif; ?>
                  </div>
                </div>
                <div class="col-md-3">
                  <label class="form-label">MAC Address</label>
                  <input type="text" name="mac_address" class="form-control" value="<?= h($eq['mac_address'] ?? '') ?>"
                    placeholder="AA:BB:CC:DD:EE:FF">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Nodo</label>
                  <input type="text" name="nodo" class="form-control" value="<?= h($eq['nodo'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                  <label class="form-label">Conexión por Medio de</label>
                  <select name="conexion_medio" class="form-select">
                    <?php foreach (['CABLE', 'WIFI', 'FIBRA', 'SERIAL', 'USB'] as $cm): ?>
                      <option <?= ($eq['conexion_medio'] ?? '') === $cm ? 'selected' : '' ?>><?= $cm ?></option><?php endforeach; ?>
                  </select>
                </div>
                <!--Motivo de baja-->
                 <div class="col-md-6" id="row-motivo-baja" style="display:none;"> 
                  <label class="form-label">Motivo de baja</label>
                  <input type="text" name="motivo_baja" class="form-control" value="<?= h($eq['motivo_baja'] ?? '') ?>">  
                </div>
                <!-- Fecha de baja -->
                <div class="col-md-3" id="row-fecha-baja" style="display:none;">
                  <label class="form-label">Fecha de baja</label>
                  <input type="date" name="fecha_baja" class="form-control" value="<?= h($eq['fecha_baja'] ?? '') ?>">
                </div>
                <!-- ── Acceso a Internet ── -->
                <div class="col-12">
                  <div class="p-3 rounded" style="background:#f8f9fa;border:1px solid #dee2e6;">
                    <div class="fw-semibold small text-uppercase mb-2" style="color:#1a3a5c;letter-spacing:.5px;">
                      <i class="bi bi-globe me-2"></i>Acceso a Internet
                    </div>
                    <div class="row g-3">
                      <div class="col-md-3">
                        <div class="form-check form-switch pt-1">
                          <input class="form-check-input" type="checkbox" role="switch" name="acceso_internet"
                            id="chk-internet" value="1"
                            <?= !empty($eq['acceso_internet']) && $eq['acceso_internet'] ? 'checked' : '' ?>
                            onchange="toggleInternet()">
                          <label class="form-check-label fw-semibold" for="chk-internet">
                            Tiene acceso a internet
                          </label>
                        </div>
                        <div class="form-text">Determinado por la IP asignada</div>
                      </div>
                      <div class="col-md-3" id="row-tipo-internet"
                        style="<?= empty($eq['acceso_internet']) ? 'opacity:.45;pointer-events:none' : '' ?>">
                        <label class="form-label">Tipo de Acceso</label>
                        <select name="tipo_internet" class="form-select" id="sel-tipo-internet">
                          <?php foreach ([
                            'SIN_ACCESO' => ['⛔', 'SIN ACCESO', 'secondary'],
                            'RESTRINGIDO' => ['🔒', 'RESTRINGIDO', 'warning'],
                            'COMPLETO' => ['✅', 'COMPLETO', 'success'],
                          ] as $v => [$ico, $lbl, $cls]): ?>
                            <option value="<?= $v ?>" <?= ($eq['tipo_internet'] ?? 'SIN_ACCESO') === $v ? 'selected' : '' ?>>
                              <?= $ico ?>     <?= $lbl ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>
                      <div class="col-md-3" id="row-ip-publica"
                        style="<?= empty($eq['acceso_internet']) ? 'opacity:.45;pointer-events:none' : '' ?>">
                        <label class="form-label">IP Pública (opcional)</label>
                        <input type="text" name="ip_publica" class="form-control" value="<?= h($eq['ip_publica'] ?? '') ?>"
                          placeholder="Ej. 189.204.x.x">
                      </div>
                      <div class="col-md-3 d-flex align-items-end">
                        <div id="preview-internet" class="w-100">
                          <?php
                          $ti = $eq['tipo_internet'] ?? 'SIN_ACCESO';
                          $tiCls = ['SIN_ACCESO' => 'secondary', 'RESTRINGIDO' => 'warning text-dark', 'COMPLETO' => 'success'][$ti] ?? 'secondary';
                          $tiIco = ['SIN_ACCESO' => 'bi-wifi-off', 'RESTRINGIDO' => 'bi-lock', 'COMPLETO' => 'bi-globe'][$ti] ?? 'bi-question';
                          ?>
                          <div class="badge bg-<?= $tiCls ?> w-100 py-2" style="font-size:.8rem;" id="badge-internet">
                            <i class="bi <?= $tiIco ?> me-1"></i>
                            <?= !empty($eq['acceso_internet'])
                              ? str_replace('_', ' ', $ti)
                              : 'SIN INTERNET' ?>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Área</label>
                  <select name="area_id" class="form-select">
                    <option value="">— Sin área —</option>
                    <?php foreach ($areas as $a): ?>
                      <option value="<?= $a['id'] ?>" <?= (int) ($eq['area_id'] ?? 0) == (int) $a['id'] ? 'selected' : '' ?>>
                        <?= h($a['nombre']) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Usuario Asignado</label>
                  <select name="usuario_id" class="form-select">
                    <option value="">— Sin usuario —</option>
                    <?php 
                    usort($usuarios, function($a, $b) {
                        $nombreA = trim(($a['nombre'] ?? '') . ' ' . ($a['apellidos'] ?? ''));
                        $nombreB = trim(($b['nombre'] ?? '') . ' ' . ($b['apellidos'] ?? ''));
                        return strcasecmp($nombreA, $nombreB);
                    });
                    foreach ($usuarios as $u): ?>
                      <option value="<?= $u['id'] ?>" <?= (int) ($eq['usuario_id'] ?? 0) == (int) $u['id'] ? 'selected' : '' ?>>
                        <?= h($u['nombre'] . ' ' . ($u['apellidos'] ?? '')) ?></option><?php endforeach; ?>
                  </select>
                </div>
                <div class="col-md-4">
                  <label class="form-label">Ubicación Física</label>
                  <input type="text" name="ubicacion" class="form-control" value="<?= h($eq['ubicacion'] ?? '') ?>">
                </div>
                <div class="col-12">
                  <label class="form-label">Descripción / Notas</label>
                  <textarea name="descripcion" class="form-control" rows="2"><?= h($eq['descripcion'] ?? '') ?></textarea>
                </div>
                <?php if (equiposTieneColumnasTecnicas()): ?>
                <div class="col-12">
                  <div class="p-3 rounded" style="background:#f8f9fa;border:1px solid #dee2e6;">
                    <div class="fw-semibold small text-uppercase mb-3" style="color:#1a3a5c;letter-spacing:.5px;">
                      <i class="bi bi-cpu me-2"></i>Características técnicas (computadora / servidor)
                    </div>
                    <div class="row g-3">
                      <div class="col-md-6 col-lg-4">
                        <label class="form-label">Sistema operativo</label>
                        <select class="form-select" name="sistema_operativo">
                            <option value="">Seleccione</option>
                            <option value="Windows 11 Pro" <?= (h($eq['sistema_operativo'] ?? '') === 'Windows 11 Pro') ? 'selected' : '' ?>>Windows 11 Pro</option>
                            <option value="Windows 10 Pro" <?= (h($eq['sistema_operativo'] ?? '') === 'Windows 10 Pro') ? 'selected' : '' ?>>Windows 10 Pro</option>
                            <option value="Windows Server" <?= (h($eq['sistema_operativo'] ?? '') === 'Windows Server') ? 'selected' : '' ?>>Windows Server</option>
                            <option value="Otro" <?= (h($eq['sistema_operativo'] ?? '') === 'Otro') ? 'selected' : '' ?>>Otro</option>
                          </select>
                          </div>
                      <div class="col-md-6 col-lg-4">
                        <label class="form-label">Procesador (CPU)</label>
                        <input type="text" name="procesador" class="form-control"
                          value="<?= h($eq['procesador'] ?? '') ?>" placeholder="Ej. Intel Core i5-12500">
                      </div>
                      <div class="col-md-6 col-lg-4">
                        <label class="form-label">Memoria RAM</label>
                        <input type="text" name="ram" class="form-control"
                          value="<?= h($eq['ram'] ?? '') ?>" placeholder="Ej. 16 GB DDR4">
                      </div>
                      <div class="col-md-6 col-lg-4">
                        <label class="form-label">Almacenamiento</label>
                        <input type="text" name="almacenamiento" class="form-control"
                          value="<?= h($eq['almacenamiento'] ?? '') ?>" placeholder="Ej. SSD 512 GB NVMe">
                      </div>
                      <div class="col-md-6 col-lg-4">
                        <label class="form-label">Pantalla / monitor</label>
                        <input type="text" name="pantalla" class="form-control"
                          value="<?= h($eq['pantalla'] ?? '') ?>" placeholder="Ej. 24&quot; Full HD">
                      </div>
                      <div class="col-md-6 col-lg-4">
                        <label class="form-label">Tarjeta de video (GPU)</label>
                        <input type="text" name="tarjeta_video" class="form-control"
                          value="<?= h($eq['tarjeta_video'] ?? '') ?>" placeholder="Ej. Integrada Intel UHD 770">
                      </div>
                    </div>
                  </div>
                </div>
                <?php endif; ?>
              </div>
              <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn" style="background:#1a3a5c;color:#fff;"><i
                    class="bi bi-check-lg me-1"></i><?= $isEdit ? 'Guardar Cambios' : 'Crear Equipo' ?></button>
                <a href="<?= $isEdit ? 'equipos.php?action=view&id=' . $id : 'equipos.php' ?>"
                  class="btn btn-outline-secondary">Cancelar</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    <script>
      function toggleMotivoBaja() {
        const selectEstado = document.getElementById('select-estado');
        const rowMotivoBaja = document.getElementById('row-motivo-baja');
        const rowFechaBaja = document.getElementById('row-fecha-baja');
        if (!selectEstado || !rowMotivoBaja) return;
        const show = selectEstado.value === 'BAJA';
        rowMotivoBaja.style.display = show ? '' : 'none';
        if (rowFechaBaja) rowFechaBaja.style.display = show ? '' : 'none';
      }
      document.getElementById('select-estado')?.addEventListener('change', toggleMotivoBaja);
      document.addEventListener('DOMContentLoaded', toggleMotivoBaja);

      function toggleInternet() {
        const chk = document.getElementById('chk-internet');
        const rowTi = document.getElementById('row-tipo-internet');
        const rowIp = document.getElementById('row-ip-publica');
        const badge = document.getElementById('badge-internet');
        const on = chk.checked;
        rowTi.style.opacity = on ? '1' : '.45';
        rowTi.style.pointerEvents = on ? '' : 'none';
        rowIp.style.opacity = on ? '1' : '.45';
        rowIp.style.pointerEvents = on ? '' : 'none';
        actualizarBadgeInternet();
      }
      function actualizarBadgeInternet() {
        const chk = document.getElementById('chk-internet');
        const sel = document.getElementById('sel-tipo-internet');
        const badge = document.getElementById('badge-internet');
        if (!badge) return;
        const on = chk.checked;
        const tipo = sel.value;
        const mapCls = { SIN_ACCESO: 'secondary', RESTRINGIDO: 'warning text-dark', COMPLETO: 'success' };
        const mapIco = { SIN_ACCESO: 'bi-wifi-off', RESTRINGIDO: 'bi-lock', COMPLETO: 'bi-globe' };
        const mapLbl = { SIN_ACCESO: 'SIN ACCESO', RESTRINGIDO: 'RESTRINGIDO', COMPLETO: 'COMPLETO' };
        const cls = on ? (mapCls[tipo] || 'secondary') : 'secondary';
        const ico = on ? (mapIco[tipo] || 'bi-wifi-off') : 'bi-wifi-off';
        const lbl = on ? (mapLbl[tipo] || tipo) : 'SIN INTERNET';
        badge.className = `badge bg-${cls} w-100 py-2`;
        badge.style.fontSize = '.8rem';
        badge.innerHTML = `<i class="bi ${ico} me-1"></i>${lbl}`;
      }
      document.getElementById('sel-tipo-internet')?.addEventListener('change', actualizarBadgeInternet);

      function actualizarPrefijo() {
        const sel = document.getElementById('sel-segmento');
        const opt = sel.options[sel.selectedIndex];
        const red = opt?.dataset?.red || '';
        const color = opt?.dataset?.color || '#666';
        const span = document.getElementById('prefijo-ip');
        span.textContent = red || '—';
        span.style.color = color;
        span.style.fontWeight = '700';
        document.getElementById('ip-segmento-val').value = red;
        construirIP();
      }
      function construirIP() {
        const red = document.getElementById('ip-segmento-val').value;
        const host = document.getElementById('ip-host').value.trim();
        const full = (red && host) ? red + '.' + host : (red ? red + '.' : host);
        document.getElementById('ip-completa').value = full;
        const prev = document.getElementById('ip-preview');
        if (full && full.includes('.') && host) {
          prev.innerHTML = '<span class="text-primary fw-semibold">' + full + '</span><span class="text-muted small ms-2">IP resultante</span>';
        } else {
          prev.innerHTML = '<span class="text-muted small">Ingresa el host (último octeto)</span>';
        }
      }
      document.addEventListener('DOMContentLoaded', () => {
        actualizarPrefijo();
        // Si hay IP manual sin segmento (edición de datos legacy)
        const ipComp = document.getElementById('ip-completa');
        if (ipComp.value && !document.getElementById('ip-host').value) {
          const parts = ipComp.value.split('.');
          if (parts.length === 4) {
            document.getElementById('ip-host').value = parts[3];
            document.getElementById('ip-segmento-val').value = parts.slice(0, 3).join('.');
            document.getElementById('prefijo-ip').textContent = parts.slice(0, 3).join('.');
          }
        }
      });

      <?php if (!$isEdit): ?>
      (function () {
        const form = document.getElementById('form-equipo');
        if (!form || form.dataset.esNuevo !== '1') return;
        const esc = (s) => {
          const d = document.createElement('div');
          d.textContent = s == null ? '' : String(s);
          return d.innerHTML;
        };
        form.addEventListener('submit', async function (ev) {
          ev.preventDefault();
          const fd = new FormData(form);
          fd.set('ip', document.getElementById('ip-completa').value.trim());
          fd.set('ip_segmento', document.getElementById('ip-segmento-val').value.trim());
          fd.set('ip_host', document.getElementById('ip-host').value.trim());
          fd.set('folio', document.getElementById('folio').value.trim());
          try {
            const res = await fetch('api.php?action=check_equipo_dup', {
              method: 'POST',
              body: fd,
              credentials: 'same-origin',
            });
            let data;
            try {
              data = await res.json();
            } catch (e2) {
              Swal.fire({
                icon: 'error',
                title: 'Respuesta inválida',
                text: 'El servidor no devolvió JSON válido.',
                confirmButtonColor: '#1a3a5c',
              });
              return;
            }
            if (!res.ok || !data.ok) {
              Swal.fire({
                icon: 'error',
                title: 'Sin verificación',
                text: data.error || 'No se pudo comprobar duplicados.',
                confirmButtonColor: '#1a3a5c',
              });
              return;
            }
            const f = data.folio_ocupado;
            const ipC = data.ip_ocupado;
            if (!f && !ipC) {
              // No duplicates, submit via AJAX
              const submitData = new FormData(form);
              submitData.set('ip', document.getElementById('ip-completa').value.trim());
              submitData.set('ip_segmento', document.getElementById('ip-segmento-val').value.trim());
              submitData.set('ip_host', document.getElementById('ip-host').value.trim());
              submitData.set('folio', document.getElementById('folio').value.trim());

              try {
                const submitRes = await fetch('equipos.php', {
                  method: 'POST',
                  body: submitData,
                  credentials: 'same-origin',
                  headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                  }
                });
                if (submitRes.redirected) {
                  // Server redirected, follow it
                  window.location.href = submitRes.url;
                  return;
                }
                const submitDataResult = await submitRes.json();
                if (submitDataResult.success) {
                  const equipoId = submitDataResult.id;
                  const folio = submitDataResult.folio;
                  Swal.fire({
                    icon: 'success',
                    title: 'Equipo creado exitosamente',
                    text: `Folio: ${folio}`,
                    showCancelButton: true,
                    confirmButtonText: '<i class="bi bi-eye me-1"></i>Ver registro',
                    cancelButtonText: '<i class="bi bi-plus-circle me-1"></i>Agregar otro',
                    confirmButtonColor: '#1a3a5c',
                    cancelButtonColor: '#198754',
                    reverseButtons: true,
                  }).then((result) => {
                    if (result.isConfirmed) {
                      window.location.href = `equipos.php?action=view&id=${equipoId}`;
                    } else {
                      // Reset form and generate new folio
                      form.reset();
                      fetch('api.php?action=next_folio_equipo', { credentials: 'same-origin' })
                        .then(r => r.json())
                        .then(d => {
                          if (d.folio) {
                            document.getElementById('folio').value = d.folio;
                          }
                        });
                    }
                  });
                } else {
                  Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: submitDataResult.error || 'No se pudo crear el equipo',
                    confirmButtonColor: '#1a3a5c',
                  });
                }
              } catch (submitError) {
                // Fallback to normal submit if AJAX fails
                form.submit();
              }
              return;
            }
            let html = '';
            if (f) {
              const mm = [f.marca, f.modelo].filter(Boolean).join(' ');
              html += '<p class="text-start mb-2">El <strong>folio</strong> ya está asignado al equipo <code>' + esc(f.folio) + '</code>'
                + (mm ? ' (' + esc(mm) + ')' : '') + '.</p>';
              html += '<p class="text-start small text-muted mb-0">También puedes usar el botón <i class="bi bi-arrow-repeat"></i> junto al folio para generar otro.</p>';
            }
            if (ipC) {
              const mm = [ipC.marca, ipC.modelo].filter(Boolean).join(' ');
              html += '<p class="text-start mb-2">La <strong>dirección IP</strong> ya la usa el equipo <code>' + esc(ipC.folio) + '</code>'
                + (mm ? ' (' + esc(mm) + ')' : '') + '.</p>';
              if (data.sugerencia_ip) {
                html += '<p class="text-start small mb-0">Hay una IP libre sugerida en el mismo segmento (botón verde).</p>';
              }
            }
            const sug = data.sugerencia_ip;
            const soloFolio = f && !ipC;
            const r = await Swal.fire({
              icon: 'warning',
              title: 'Datos en conflicto',
              html,
              confirmButtonText: 'Corregir',
              confirmButtonColor: '#1a3a5c',
              showDenyButton: !!(sug || soloFolio),
              denyButtonText: sug ? ('Usar IP sugerida: ' + sug) : (soloFolio ? 'Generar nuevo folio' : ''),
              denyButtonColor: '#198754',
            });
            if (r.isDenied && sug) {
              const parts = sug.split('.');
              if (parts.length === 4) {
                document.getElementById('ip-host').value = parts[3];
                construirIP();
              }
            } else if (r.isDenied && soloFolio) {
              try {
                const fr = await fetch('api.php?action=next_folio_equipo', { credentials: 'same-origin' });
                const d = await fr.json();
                if (d.folio) {
                  document.getElementById('folio').value = d.folio;
                  if (typeof SwalToast !== 'undefined') {
                    SwalToast.fire({ icon: 'success', title: 'Folio generado: ' + d.folio });
                  } else {
                    Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Folio: ' + d.folio, showConfirmButton: false, timer: 2800 });
                  }
                }
              } catch (e3) { /* silencio: el usuario puede reintentar */ }
            }
          } catch (e) {
            Swal.fire({
              icon: 'error',
              title: 'Error de red',
              text: 'No se pudo verificar si el folio o la IP ya están en uso.',
              confirmButtonColor: '#1a3a5c',
            });
          }
        });
      })();
      <?php endif; ?>
    </script>

  <?php elseif ($action === 'view' && $id): ?>
    <!-- ══ DETALLE ══ -->
    <?php
    $eq = getEquipoById($id);
    $inv = getInventario(equipo: $id);
    $mants = getMantenimientos(equipo: $id);
    $tks = getTickets(); // filter below
    $ticketsEq = array_filter($tks, fn($t) => $t['equipo_id'] == $id);
    if (!$eq) {
      flash('danger', 'Equipo no encontrado.');
      redirect('equipos.php');
    }
    $cls = ['ACTIVO' => 'badge-activo', 'MANTENIMIENTO' => 'badge-mantenimiento', 'BAJA' => 'badge-baja', 'INACTIVO' => 'badge-inactivo'][$eq['estado']] ?? 'bg-secondary';
    ?>
    <div class="equipo-header d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <h4 class="mb-1"><i class="bi bi-pc-display me-2"></i><?= h($eq['folio']) ?> —
          <?= h($eq['marca'] . ' ' . ($eq['modelo'] ?? '')) ?></h4>
        <span class="badge <?= $cls ?> me-2"><?= h($eq['estado']) ?></span>
        <span class="badge bg-secondary"><?= h($eq['tipo_nombre'] ?? '') ?></span>
      </div>
      <div class="d-flex gap-2 flex-wrap no-print">
        <a href="equipos.php?action=edit&id=<?= $id ?>" class="btn btn-sm btn-light"><i
            class="bi bi-pencil me-1"></i>Editar</a>
        <a href="mantenimiento.php?action=new&equipo_id=<?= $id ?>" class="btn btn-sm btn-warning"><i
            class="bi bi-tools me-1"></i>Mantenimiento</a>
        <a href="tickets.php?action=new&equipo_id=<?= $id ?>" class="btn btn-sm btn-danger"><i
            class="bi bi-ticket me-1"></i>Ticket</a>
        <button onclick="window.print()" class="btn btn-sm btn-outline-light"><i
            class="bi bi-printer me-1"></i>Imprimir</button>
        <a href="exports/export_equipos_pdf.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-light"><i
            class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
      </div>
    </div>

    <div class="row g-3">
      <!-- Datos principales -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header-ti"><i class="bi bi-info-circle me-2"></i>Información del Equipo</div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <?php
              // Preparar badge de internet
              $tiCls = ['SIN_ACCESO' => 'secondary', 'RESTRINGIDO' => 'warning text-dark', 'COMPLETO' => 'success'][$eq['tipo_internet'] ?? 'SIN_ACCESO'] ?? 'secondary';
              $tiIco = ['SIN_ACCESO' => 'bi-wifi-off', 'RESTRINGIDO' => 'bi-lock-fill', 'COMPLETO' => 'bi-globe'][$eq['tipo_internet'] ?? 'SIN_ACCESO'] ?? 'bi-wifi-off';
              $tiLbl = ['SIN_ACCESO' => 'Sin Acceso', 'RESTRINGIDO' => 'Restringido', 'COMPLETO' => 'Completo'][$eq['tipo_internet'] ?? 'SIN_ACCESO'] ?? 'Sin Acceso';
              $internetBadge = !empty($eq['acceso_internet'])
                ? '<span class="badge bg-' . $tiCls . '"><i class="bi ' . $tiIco . ' me-1"></i>' . $tiLbl . '</span>'
                : '<span class="badge bg-secondary"><i class="bi bi-wifi-off me-1"></i>Sin Internet</span>';
              $rows = [
                ['Folio', h($eq['folio'])],
                ['Tipo', h($eq['tipo_nombre'] ?? '')],
                ['Marca / Modelo', h($eq['marca'] . ' ' . ($eq['modelo'] ?? ''))],
                ['No. Serie', '<code>' . h($eq['serie'] ?? '') . '</code>'],
                //['Dirección IP',   '<code class="fw-bold">'.h($eq['ip']??'').'</code>'.($eq['ip_segmento']??' <span class="text-muted small">('.$eq['ip_segmento'].'.*)</span>')],
                ['Dirección IP', '<code>' . h($eq['ip'] ?? '') . '</code>'],
                ['Internet', $internetBadge . ($eq['ip_publica'] ? ' <code class="small text-muted ms-1">' . h($eq['ip_publica']) . '</code>' : '')],
                ['MAC Address', '<code>' . h($eq['mac_address'] ?? '') . '</code>'],
                ['Nodo', h($eq['nodo'] ?? '')],
                ['Conexión', h($eq['conexion_medio'] ?? '')],
                ['Ubicación', h($eq['ubicacion'] ?? '')],
                ['Área', h($eq['area_nombre'] ?? '')],
                ['Usuario', h(trim($eq['usuario_nombre'] ?? ''))],
                ['Estado', '<span class="badge ' . $cls . '">' . h($eq['estado']) . '</span>'],
                ['F. Adquisición', h($eq['fecha_adquisicion'] ?? '')],
              ];
              // Agregar motivo y fecha de baja si el estado es BAJA
              if (($eq['estado'] ?? '') === 'BAJA') {
                $rows[] = ['Motivo de Baja', h($eq['motivo_baja'] ?? '')];
                $rows[] = ['Fecha de Baja', h($eq['fecha_baja'] ?? '')];
              }
              if (equiposTieneColumnasTecnicas()) {
                foreach ([
                  ['Sistema operativo', h($eq['sistema_operativo'] ?? '')],
                  ['Procesador', h($eq['procesador'] ?? '')],
                  ['Memoria RAM', h($eq['ram'] ?? '')],
                  ['Almacenamiento', h($eq['almacenamiento'] ?? '')],
                  ['Pantalla / monitor', h($eq['pantalla'] ?? '')],
                  ['Tarjeta de video', h($eq['tarjeta_video'] ?? '')],
                ] as $specRow) {
                  $rows[] = $specRow;
                }
              }
              foreach ($rows as [$k, $v]):
                if (!$v || $v === '<code></code>')
                  continue; ?>
                <tr>
                  <th class="text-muted small ps-3" style="width:38%"><?= $k ?></th>
                  <td class="pe-3"><?= $v ?></td>
                </tr>
              <?php endforeach; ?>
            </table>
          </div>
        </div>
      </div>
      <!-- Inventario periféricos -->
      <div class="col-md-6">
        <div class="card h-100">
          <div class="card-header-ti d-flex justify-content-between">
            <span><i class="bi bi-boxes me-2"></i>Periféricos / Inventario</span>
            <a href="inventario.php?action=new&equipo_id=<?= $id ?>" class="btn btn-sm btn-outline-light py-0 no-print">+
              Agregar</a>
          </div>
          <div class="card-body p-0">
            <table class="table table-ti mb-0">
              <thead>
                <tr>
                  <th>Tipo</th>
                  <th>Marca/Modelo</th>
                  <th>Serie</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($inv as $i):
                  $ic = ['BUENO' => 'badge-bueno', 'REGULAR' => 'badge-regular', 'MALO' => 'badge-malo', 'BAJA' => 'badge-baja'][$i['estado']] ?? 'bg-secondary';
                  ?>
                  <tr>
                    <td><?= h($i['tipo']) ?></td>
                    <td><?= h($i['marca'] . ' ' . ($i['modelo'] ?? '')) ?></td>
                    <td><code class="small"><?= h($i['serie'] ?? '') ?></code></td>
                    <td><span class="badge <?= $ic ?>"><?= h($i['estado']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($inv)): ?>
                  <tr>
                    <td colspan="4" class="text-center text-muted py-2 small">Sin periféricos</td>
                  </tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <!-- Historial mantenimientos -->
      <div class="col-md-6">
        <div class="card">
          <div class="card-header-ti d-flex justify-content-between">
            <span><i class="bi bi-clipboard-check me-2"></i>Mantenimientos</span>
            <a href="mantenimiento.php?action=new&equipo_id=<?= $id ?>" class="btn btn-sm btn-outline-light py-0 no-print">+
              Nuevo</a>
          </div>
          <div class="card-body p-0">
            <table class="table table-ti mb-0">
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Tipo</th>
                  <th>Técnico</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($mants as $m): ?>
                  <tr>
                    <td><?= h($m['fecha']) ?></td>
                    <td><span class="badge bg-info text-dark"><?= h($m['tipo']) ?></span></td>
                    <td class="small"><?= h($m['tecnico'] ?? '') ?></td>
                    <td class="no-print"><a href="mantenimiento.php?action=view&id=<?= $m['id'] ?>"
                        class="btn btn-xs btn-outline-primary"><i class="bi bi-eye"></i></a></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($mants)): ?>
                  <tr>
                    <td colspan="4" class="text-center text-muted py-2 small">Sin mantenimientos</td>
                  </tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <!-- Tickets del equipo -->
      <div class="col-md-6">
        <div class="card">
          <div class="card-header-ti d-flex justify-content-between">
            <span><i class="bi bi-ticket me-2"></i>Tickets</span>
            <a href="tickets.php?action=new&equipo_id=<?= $id ?>" class="btn btn-sm btn-outline-light py-0 no-print">+
              Nuevo</a>
          </div>
          <div class="card-body p-0">
            <table class="table table-ti mb-0">
              <thead>
                <tr>
                  <th>Folio</th>
                  <th>Tipo</th>
                  <th>Prioridad</th>
                  <th>Estado</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($ticketsEq as $t):
                  $prioC = ['URGENTE' => 'badge-urgente', 'ALTA' => 'badge-alta', 'MEDIA' => 'badge-media', 'BAJA' => 'badge-baja-p'][$t['prioridad']] ?? 'bg-secondary';
                  $estC = ['ABIERTO' => 'badge-abierto', 'EN_PROCESO' => 'badge-en_proceso', 'RESUELTO' => 'badge-resuelto', 'CANCELADO' => 'badge-cancelado'][$t['estado']] ?? 'bg-secondary';
                  ?>
                  <tr>
                    <td><code><?= h($t['folio_ticket']) ?></code></td>
                    <td class="small"><?= h(str_replace('_', ' ', $t['tipo_servicio'])) ?></td>
                    <td><span class="badge <?= $prioC ?>"><?= h($t['prioridad']) ?></span></td>
                    <td><span class="badge <?= $estC ?>"><?= h(str_replace('_', ' ', $t['estado'])) ?></span></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($ticketsEq)): ?>
                  <tr>
                    <td colspan="4" class="text-center text-muted py-2 small">Sin tickets</td>
                  </tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Historial de asignaciones del equipo -->
      <?php $asignEquipo = getAsignaciones(equipoId: $id);
      if (!empty($asignEquipo)): ?>
        <div class="col-12">
          <div class="card">
            <div class="card-header-ti"><i class="bi bi-arrow-left-right me-2"></i>Historial de Asignaciones del Equipo
            </div>
            <div class="card-body p-0">
              <table class="table table-ti mb-0">
                <thead>
                  <tr>
                    <th>Fecha</th>
                    <th>Usuario</th>
                    <th>Tipo</th>
                    <th>Motivo</th>
                    <th>Equipo Anterior</th>
                    <th>Estado</th>
                    <th>Descripción</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($asignEquipo as $a):
                    $tipoBadge = ['ASIGNACION' => 'bg-primary', 'REEMPLAZO_TEMPORAL' => 'bg-warning text-dark', 'REEMPLAZO_DEFINITIVO' => 'bg-danger', 'DEVOLUCION' => 'bg-secondary', 'REASIGNACION' => 'bg-info text-dark'][$a['tipo']] ?? 'bg-secondary';
                    ?>
                    <tr>
                      <td class="small text-nowrap">
                        <?= h($a['fecha_inicio']) ?>      <?= $a['fecha_fin'] ? ' → ' . h($a['fecha_fin']) : ''; ?></td>
                      <td class="small fw-semibold"><?= h($a['usuario_nombre']) ?></td>
                      <td><span class="badge <?= $tipoBadge ?>"
                          style="font-size:.65rem;"><?= h(str_replace('_', ' ', $a['tipo'])) ?></span></td>
                      <td class="small"><?= h(str_replace('_', ' ', $a['motivo'])) ?></td>
                      <td><code class="small text-muted"><?= h($a['folio_anterior'] ?? '—') ?></code></td>
                      <td>
                        <?= $a['es_activa'] ? '<span class="badge bg-success">Activa</span>' : '<span class="badge bg-secondary">Cerrada</span>' ?>
                      </td>
                      <td class="small text-muted"><?= h(mb_substr($a['descripcion'] ?? '', 0, 50)) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>

  <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>
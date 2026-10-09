<?php
// 1. Configuración de seguridad y errores (Cambiar a 0 en producción)
const ESP_PRODUCCION = false; 

if (!ESP_PRODUCCION) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
    error_reporting(0);
}

// 2. Autenticación y Control de Accesos
require_once 'includes/auth.php';
$pageTitle = 'Dashboard';

// Control estricto de flujo: Detener la ejecución con 'exit' tras redirigir
if (isNormal()) {
    redirect('equipos.php');
    exit; 
}

include 'includes/header.php';

// ── VISTA DE SUPERVISOR TÉCNICO ──────────────────────────────────
if (isSuper()):
  $misTickets = getMisTicketsComoSupervisor();
  $pendingAssigned = (int)pendingSupervisorCount();
  $pendingAssignedNew = (int)pendingSupervisorNewCount();
  $abiertos = array_filter($misTickets, fn($t) => $t['estado'] === 'ABIERTO');
  $enProceso = array_filter($misTickets, fn($t) => $t['estado'] === 'EN_PROCESO');
  $misMants = getMantenimientos();
  
  $pendingChgsStmt = getDB()->prepare("SELECT COUNT(*) FROM cambios_pendientes WHERE suser_id=? AND estado='PENDIENTE'");
  $pendingChgsStmt->execute([suserIdActual()]);
  $pendingChgs = (int) $pendingChgsStmt->fetchColumn();
  ?>
  <div class="container-fluid px-3 py-3">

    <div class="d-flex align-items-center gap-3 mb-3">
      <div class="avatar-circle" style="width:44px;height:44px;font-size:1.2rem;background:#1a3a5c; display:flex; align-items:center; justify-content:center; color:#fff; border-radius:50%;">
        <?= strtoupper(substr($auth['nombre'], 0, 1)) ?>
      </div>
      <div>
        <h5 class="mb-0">Bienvenido, <?= h($auth['nombre']) ?></h5>
        <span class="badge bg-warning text-dark">SUPERVISOR TÉCNICO</span>
      </div>
    </div>

    <div class="row g-3 mb-3">
      <div class="col-6 col-md-3">
        <div class="stat-card bg-ti-red h-100">
          <div class="stat-val"><?= count($abiertos) ?></div>
          <div class="stat-lbl">Tickets Abiertos</div>
          <i class="bi bi-ticket stat-icon"></i>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card bg-ti-orange h-100">
          <div class="stat-val"><?= count($enProceso) ?></div>
          <div class="stat-lbl">En Proceso</div>
          <i class="bi bi-tools stat-icon"></i>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card bg-ti-blue h-100">
          <div class="stat-val"><?= count($misMants) ?></div>
          <div class="stat-lbl">Mantenimientos</div>
          <i class="bi bi-clipboard-check stat-icon"></i>
        </div>
      </div>
      <div class="col-6 col-md-3">
        <div class="stat-card bg-ti-purple h-100">
          <div class="stat-val"><?= $pendingChgs ?></div>
          <div class="stat-lbl">Cambios Pendientes</div>
          <i class="bi bi-hourglass stat-icon"></i>
        </div>
      </div>
    </div>

    <?php if ($pendingChgs > 0): ?>
      <div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-hourglass-split fs-5"></i>
        <div>Tienes <strong><?= $pendingChgs ?></strong> cambio(s) en espera de aprobación del Administrador.</div>
      </div>
    <?php endif; ?>

    <?php if ($pendingAssigned > 0): ?>
      <div class="alert alert-info d-flex align-items-center gap-2 mb-3">
        <i class="bi bi-bell-fill fs-5"></i>
        <div>
          Tienes <strong><?= $pendingAssigned ?></strong> ticket(s) asignado(s) por atender.
          <a href="tickets.php" class="alert-link ms-1">Revisar ahora</a>
        </div>
      </div>
    <?php endif; ?>

    <div class="card mb-3">
      <div class="card-header-ti d-flex justify-content-between align-items-center">
        <span><i class="bi bi-ticket me-2"></i>Mis Tickets Asignados</span>
        <a href="tickets.php" class="btn btn-sm btn-outline-light py-0">Ver todos</a>
      </div>
      <div class="card-body p-0">
        <table class="table table-ti mb-0">
          <thead>
            <tr>
              <th>Folio</th>
              <th>Equipo</th>
              <th>Descripción</th>
              <th>Prioridad</th>
              <th>Estado</th>
              <th>Apertura</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php 
            $hasTickets = false;
            foreach (array_slice($misTickets, 0, 10) as $t):
              if ($t['estado'] === 'RESUELTO' || $t['estado'] === 'CANCELADO') continue;
              $hasTickets = true;
              $prioC = ['URGENTE' => 'badge-urgente', 'ALTA' => 'badge-alta', 'MEDIA' => 'badge-media', 'BAJA' => 'badge-baja-p'][$t['prioridad']] ?? 'bg-secondary';
              $estC = ['ABIERTO' => 'badge-abierto', 'EN_PROCESO' => 'badge-en_proceso'][$t['estado']] ?? 'bg-secondary';
              ?>
              <tr>
                <td><code class="text-primary"><?= h($t['folio_ticket']) ?></code></td>
                <td class="small"><?= h($t['equipo_folio'] . ' ' . ($t['equipo_marca'] ?? '')) ?></td>
                <td class="small text-muted"><?= h(mb_substr($t['descripcion'] ?? '', 0, 50)) ?>...</td>
                <td><span class="badge <?= $prioC ?>"><?= h($t['prioridad']) ?></span></td>
                <td><span class="badge <?= $estC ?>"><?= h(str_replace('_', ' ', $t['estado'])) ?></span></td>
                <td class="small"><?= h(substr($t['fecha_apertura'] ?? '', 0, 10)) ?></td>
                <td>
                  <a href="tickets.php?action=view&id=<?= base64_encode($t['id']) ?>" class="btn btn-xs btn-outline-primary"><i class="bi bi-eye"></i></a>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$hasTickets): ?>
              <tr>
                <td colspan="7" class="text-center text-muted py-3">Sin tickets activos asignados</td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-check-circle me-2"></i>Tickets Resueltos Recientemente</div>
      <div class="card-body d-flex flex-wrap gap-2 py-3">
        <?php 
        $hasResolved = false;
        foreach (array_slice($misTickets, 0, 15) as $t):
          if ($t['estado'] === 'RESUELTO'):
            $hasResolved = true;
            ?>
            <a href="tickets.php?action=view&id=<?= base64_encode($t['id']) ?>" class="btn btn-xs btn-outline-success">
              <i class="bi bi-check2"></i> <?= h($t['folio_ticket']) ?>
            </a>
          <?php endif;
        endforeach; 
        if (!$hasResolved): ?>
          <span class="text-muted small">No hay tickets resueltos en la lista reciente.</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-lightning me-2"></i>Acciones Rápidas</div>
      <div class="card-body d-flex flex-wrap gap-2 py-3">
        <a href="mantenimiento.php?action=new" class="btn btn-outline-warning btn-sm"><i class="bi bi-clipboard-plus me-1"></i>Nuevo Mantenimiento</a>
        <a href="equipos.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-pc-display me-1"></i>Ver Equipos</a>
        <a href="inventario.php" class="btn btn-outline-success btn-sm"><i class="bi bi-boxes me-1"></i>Ver Inventario</a>
        <a href="exports/export_equipos_pdf.php" target="_blank" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-file-earmark-pdf me-1"></i>Reporte PDF</a>
      </div>
    </div>
  </div>

  <?php if ($pendingAssignedNew > 0): ?>
    <script src="assets/sweetalert2@11"></script>
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        Swal.fire({
          icon: 'info',
          title: 'Tienes nuevas asignaciones',
          text: 'El Administrador te asignó <?= (int)$pendingAssignedNew ?> ticket(s) para atender.',
          confirmButtonText: 'Ver tickets',
          showCancelButton: true,
          cancelButtonText: 'Después'
        }).then((result) => {
          if (result.isConfirmed) window.location.href = 'tickets.php';
        });
      });
    </script>
  <?php endif; ?>
  <?php 
  include 'includes/footer.php';
  return; // Termina la ejecución para supervisores de forma segura
endif; 


// ── VISTA DE ADMINISTRADOR ───────────────────────────────────────
$s = getDashboardStats();
$ticketsR = array_slice(getTickets(), 0, 8);
$pendingAdminTicketApproval = (int)pendingAdminTicketApprovalCount();
?>
<div class="container-fluid px-3 py-3">

  <div class="row g-3 mb-3">
    <div class="col-6 col-md-3 col-xl-2">
      <div class="stat-card bg-ti-blue h-100">
        <div class="stat-val"><?= (int)$s['equipos_total'] ?></div>
        <div class="stat-lbl">Equipos Total</div>
        <i class="bi bi-pc-display stat-icon"></i>
      </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="stat-card bg-ti-green h-100">
        <div class="stat-val"><?= (int)$s['equipos_activos'] ?></div>
        <div class="stat-lbl">Equipos Activos</div>
        <i class="bi bi-check-circle stat-icon"></i>
      </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="stat-card bg-ti-orange h-100">
        <div class="stat-val"><?= (int)$s['equipos_mant'] ?></div>
        <div class="stat-lbl">En Mantenimiento</div>
        <i class="bi bi-tools stat-icon"></i>
      </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="stat-card bg-ti-purple h-100">
        <div class="stat-val"><?= (int)$s['usuarios_total'] ?></div>
        <div class="stat-lbl">Usuarios</div>
        <i class="bi bi-people stat-icon"></i>
      </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="stat-card bg-ti-red h-100">
        <div class="stat-val"><?= (int)($s['tickets_stats']['ABIERTO'] ?? 0) + (int)($s['tickets_stats']['EN_PROCESO'] ?? 0) ?></div>
        <div class="stat-lbl">Tickets Activos</div>
        <i class="bi bi-ticket stat-icon"></i>
      </div>
    </div>
    <div class="col-6 col-md-3 col-xl-2">
      <div class="stat-card bg-ti-teal h-100">
        <div class="stat-val"><?= (int)$s['ip_libres'] ?></div>
        <div class="stat-lbl">IPs Libres (Total)</div>
        <i class="bi bi-diagram-3 stat-icon"></i>
      </div>
    </div>
  </div>

  <?php if ($pendingAdminTicketApproval > 0): ?>
  <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-ticket-detailed-fill fs-5"></i>
    <div>
      Tienes <strong><?= $pendingAdminTicketApproval ?></strong> solicitud(es) de ticket pendientes por atender/asignar.
      <a href="tickets.php?estado=ABIERTO&pendiente_usuario=1" class="alert-link ms-1">Ir a tickets</a>
    </div>
  </div>
  <?php endif; ?>

  <div class="row g-3">
    <div class="col-md-6 col-xl-4">
      <div class="card h-100">
        <div class="card-header-ti d-flex justify-content-between align-items-center">
          <span><i class="bi bi-bar-chart me-2"></i>Equipos por Tipo</span>
          <a href="equipos.php" class="btn btn-sm btn-outline-light py-0">Ver todos</a>
        </div>
        <div class="card-body p-0">
          <table class="table table-ti mb-0">
            <thead>
              <tr>
                <th>Tipo</th>
                <th class="text-center">Total</th>
                <th class="text-center">Activos</th>
                <th class="text-center">Bajas</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach (($s['resumen_equipos'] ?? []) as $r): ?>
                <tr>
                  <td><strong><?= h($r['tipo']) ?></strong></td>
                  <td class="text-center"><span class="badge bg-primary"><?= (int)$r['total'] ?></span></td>
                  <td class="text-center"><span class="badge bg-success"><?= (int)$r['activos'] ?></span></td>
                  <td class="text-center"><span class="badge bg-danger"><?= (int)$r['bajas'] ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="col-md-6 col-xl-4">
      <div class="card h-100">
        <div class="card-header-ti d-flex justify-content-between align-items-center">
          <span><i class="bi bi-diagram-3 me-2"></i>Segmentos IP</span>
          <a href="segmentos_ip.php" class="btn btn-sm btn-outline-light py-0">Ver mapa</a>
        </div>
        <div class="card-body">
          <?php foreach (($s['segmentos_ip'] ?? []) as $seg): ?>
            <div class="mb-3">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <span class="small fw-semibold"><?= h($seg['nombre']) ?></span>
                <code class="small" style="color:<?= h($seg['color']) ?>;"><?= h($seg['red']) ?>.*</code>
              </div>
              <div class="d-flex gap-2 align-items-center mb-1">
                <div class="progress flex-fill" style="height:7px;">
                  <?php $pct = $seg['total'] > 0 ? round($seg['usadas'] / $seg['total'] * 100, 0) : 0; ?>
                  <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= h($seg['color']) ?>;"></div>
                </div>
                <span class="text-muted" style="font-size:.68rem;min-width:30px;"><?= $pct ?>%</span>
              </div>
              <div class="d-flex gap-3">
                <span class="small text-danger"><strong><?= (int)$seg['usadas'] ?></strong> usadas</span>
                <span class="small text-success"><strong><?= (int)$seg['libres'] ?></strong> libres</span>
                <span class="small text-muted">/ <?= (int)$seg['total'] ?></span>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if (empty($s['segmentos_ip'])): ?>
            <div class="text-muted small text-center py-3">Sin segmentos configurados</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
         
    <div class="col-md-6 col-xl-4">
      <div class="card mb-3">
        <div class="card-header-ti"><i class="bi bi-ticket me-2"></i>Resumen General de Tickets</div>
        <div class="card-body py-2">
          <?php
          $tst = $s['tickets_stats'] ?? [];
          $states = ['ABIERTO' => ['danger', 'Abiertos'], 'EN_PROCESO' => ['warning', 'En Proceso'], 'RESUELTO' => ['success', 'Resueltos'], 'CANCELADO' => ['secondary', 'Cancelados']];
          foreach ($states as $k => [$cls, $lbl]): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="small"><?= $lbl ?></span>
              <span class="badge bg-<?= $cls ?> text-<?= $cls === 'warning' ? 'dark' : 'white' ?>"><?= (int)($tst[$k] ?? 0) ?></span>
            </div>
          <?php endforeach; ?>
          <hr class="my-2">
          <a href="tickets.php?action=new" class="btn btn-sm w-100" style="background:#1a3a5c;color:#fff;">
            <i class="bi bi-plus me-1"></i>Nuevo Ticket
          </a>
        </div>
      </div>

      <div class="card">
        <div class="card-header-ti d-flex justify-content-between align-items-center">
          <span><i class="bi bi-journal-text me-2"></i>Resumen de Bitácora</span>
          <a href="bitacora.php" class="btn btn-sm btn-outline-light py-0">Ver</a>
        </div>
        <div class="card-body py-2">
          <?php
          $bStats = getEstadisticasBitacora();
          $bStates = [
            ['Activos', $bStats['abiertos'] ?? 0, 'danger'],
            ['Este mes', $bStats['mes_actual'] ?? 0, 'warning'],
            ['Críticos', $bStats['criticos'] ?? 0, 'dark'],
            ['Total', $bStats['total'] ?? 0, 'secondary'],
          ];
          foreach ($bStates as [$lbl, $val, $cls]): ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="small"><?= $lbl ?></span>
              <span class="badge bg-<?= $cls ?>"><?= (int)$val ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="col-12 col-xl-12 mt-2">
      <div class="card">
        <div class="card-header-ti d-flex justify-content-between align-items-center">
          <span><i class="bi bi-clock-history me-2"></i>Tickets Recientes del Sistema</span>
          <a href="tickets.php" class="btn btn-sm btn-outline-light py-0">Ver todos</a>
        </div>
        <div class="card-body p-0">
          <table class="table table-ti mb-0">
            <thead>
              <tr>
                <th>Folio</th>
                <th>Equipo</th>
                <th>Prioridad</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ticketsR as $t):
                $prioClass = ['URGENTE' => 'badge-urgente', 'ALTA' => 'badge-alta', 'MEDIA' => 'badge-media', 'BAJA' => 'badge-baja-p'][$t['prioridad']] ?? 'bg-secondary';
                $estClass = ['ABIERTO' => 'badge-abierto', 'EN_PROCESO' => 'badge-en_proceso', 'RESUELTO' => 'badge-resuelto', 'CANCELADO' => 'badge-cancelado'][$t['estado']] ?? 'bg-secondary';
                ?>
                <tr style="cursor:pointer" onclick="location.href='tickets.php?action=view&id=<?= $t['id'] ?>'">
                  <td><code class="small"><?= h($t['folio_ticket']) ?></code></td>
                  <td class="small"><?= h($t['equipo_folio'] . ' ' . ($t['equipo_marca'] ?? '')) ?></td>
                  <td><span class="badge <?= $prioClass ?>"><?= h($t['prioridad']) ?></span></td>
                  <td><span class="badge <?= $estClass ?>"><?= h(str_replace('_', ' ', $t['estado'])) ?></span></td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($ticketsR)): ?>
                <tr>
                  <td colspan="4" class="text-center text-muted py-3">Sin movimientos de tickets recientes</td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-12">
      <div class="card">
        <div class="card-header-ti"><i class="bi bi-lightning me-2"></i>Acceso Rápido General</div>
        <div class="card-body d-flex flex-wrap gap-2 py-3">
          <a href="equipos.php?action=new" class="btn btn-outline-primary btn-sm"><i class="bi bi-plus me-1"></i>Nuevo Equipo</a>
          <a href="usuarios.php?action=new" class="btn btn-outline-purple btn-sm" style="border-color:#6f42c1;color:#6f42c1;"><i class="bi bi-person-plus me-1"></i>Nuevo Usuario</a>
          <a href="inventario.php?action=new" class="btn btn-outline-success btn-sm"><i class="bi bi-plus me-1"></i>Nuevo Inventario</a>
          <a href="reportes.php" class="btn btn-outline-info btn-sm"><i class="bi bi-bar-chart-line me-1"></i>Ver Reportes</a>
          <a href="bitacora.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-journal-text me-1"></i>Bitácora</a>
          <a href="segmentos_ip.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-diagram-3 me-1"></i>Segmentos IP</a>
          
          <a href="exports/export_equipos_pdf.php" target="_blank" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-file-earmark-pdf me-1"></i>Reporte PDF</a>
          <a href="exports/export_equipos_xls.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Reporte Excel</a>
        </div>
      </div>
    </div>
  </div>

</div>

<?php include 'includes/footer.php'; ?>
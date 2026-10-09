<?php
require_once __DIR__.'/auth.php';
$auth        = authCheck();
$flash       = getFlash();
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$pendingCnt  = pendingCount(); // cambios pendientes para admin
$pendingSupervisorCnt = pendingSupervisorCount();
$pendingAdminTicketApprovalCnt = pendingAdminTicketApprovalCount();
$pendingAdminTicketApprovalCount = pendingAdminTicketApprovalCount();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($pageTitle ?? 'Gestión TI') ?> — <?= APP_NAME ?></title>
<link href="assets/bst/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/bst/icons/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-dark navbar-expand-lg app-navbar sticky-top">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold" href="index.php">
      <i class="bi bi-hdd-network-fill me-2"></i><?= APP_NAME ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navMain">
      <ul class="navbar-nav me-auto">

        <?php if (isAdmin() || isSuper()): ?>
        <!-- Dashboard: solo Admin y Supervisor Técnico -->
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==='index'?'active':'' ?>" href="index.php">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
          </a>
        </li>
        <?php endif; ?>
   

        <?php if (puede('ver_equipos') || puede('ver_mis_equipos')): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==='equipos'?'active':'' ?>" href="equipos.php">
            <i class="bi bi-pc-display me-1"></i><?= isNormal()?'Mis Equipos':'Equipos' ?>
          </a>
        </li>
        <?php endif; ?>

        <?php if (puede('ver_usuarios')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage,['usuarios','directorio','puestos'])?'active':'' ?>"
             href="#" data-bs-toggle="dropdown">
            <i class="bi bi-people me-1"></i>Usuarios
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="usuarios.php"><i class="bi bi-people me-2"></i>Lista de Usuarios</a></li>
            <li><a class="dropdown-item" href="directorio.php"><i class="bi bi-telephone me-2"></i>Directorio Telefónico IP</a></li>
            <?php if (isAdmin()): ?>
            <li><a class="dropdown-item" href="puestos.php"><i class="bi bi-briefcase me-2"></i>Catálogo de Puestos</a></li>
            <?php endif; ?>
            <?php if (puede('crear_usuario')): ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="usuarios.php?action=new"><i class="bi bi-person-plus me-2"></i>Nuevo Usuario</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>

        <?php if (puede('ver_inventario')): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==='inventario'?'active':'' ?>" href="inventario.php">
            <i class="bi bi-boxes me-1"></i>Inventario
          </a>
        </li>
        <?php endif; ?>

        <!-- Soporte -->
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage,['tickets','mantenimiento','bitacora'])?'active':'' ?>"
             href="#" data-bs-toggle="dropdown">
            <i class="bi bi-tools me-1"></i>Soporte
          </a>
          <ul class="dropdown-menu">
            <li>
              <a class="dropdown-item" href="tickets.php">
                <i class="bi bi-ticket me-2"></i>
                <?= isNormal() ? 'Mis Solicitudes' : 'Tickets' ?>
              </a>
            </li>
            <?php if (puede('ver_mantenimiento')): ?>
            <li>
              <a class="dropdown-item" href="mantenimiento.php">
                <i class="bi bi-clipboard-check me-2"></i>Mantenimiento
              </a>
            </li>
            <li>
              <a class="dropdown-item" href="bitacora.php">
                <i class="bi bi-journal-text me-2 text-danger"></i>Bitácora de Incidentes
              </a>
            </li>
            <?php endif; ?>
          </ul>
        </li>

  <!-- Reportes (admin y supervisor) -->
        <?php if (puede('exportar')): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage,['reportes','busqueda'])?'active':'' ?>"
             href="#" data-bs-toggle="dropdown">
            <i class="bi bi-graph-up me-1"></i>Reportes
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="reportes.php"><i class="bi bi-bar-chart-line me-2"></i>Estadísticas</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="exports/export_equipos_pdf.php" target="_blank"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>Equipos PDF</a></li>
            <li><a class="dropdown-item" href="exports/export_equipos_xls.php"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Equipos Excel</a></li>
            <li><a class="dropdown-item" href="exports/export_inventario_xls.php"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Inventario Excel</a></li>
            <li><a class="dropdown-item" href="exports/export_tickets_xls.php"><i class="bi bi-file-earmark-excel me-2 text-success"></i>Tickets Excel</a></li>
          </ul>
        </li>
        <?php endif; ?>
        <!-- Cursos -->
        <?php if (isAdmin() || isSuper()): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentPage==='cursos'?'active':'' ?>" href="cursos.php">
            <i class="bi bi-mortarboard-fill me-1"></i>Cursos
          </a>
        </li>
        <?php endif; ?>

        <!-- Solo admin: gestión del sistema -->
        <?php if (isAdmin()): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($currentPage,['areas','system_users','aprobaciones','auditoria','segmentos_ip'])?'active':'' ?>"
             href="#" data-bs-toggle="dropdown">
            <i class="bi bi-gear me-1"></i>Admin
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="areas.php"><i class="bi bi-diagram-3 me-2"></i>Áreas</a></li>
            <li><a class="dropdown-item" href="segmentos_ip.php"><i class="bi bi-diagram-2 me-2 text-primary"></i>Segmentos IP</a></li>
            <li><a class="dropdown-item" href="system_users.php"><i class="bi bi-person-badge me-2"></i>Cuentas del Sistema</a></li>
            <li><a class="dropdown-item" href="nodos.php"><i class="bi bi-ethernet me-2"></i>Nodos</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item d-flex align-items-center justify-content-between" href="aprobaciones.php">
                <span><i class="bi bi-check2-square me-2"></i>Aprobaciones</span>
                <?php if ($pendingCnt > 0): ?>
                <span class="badge bg-danger rounded-pill"><?= $pendingCnt ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li><a class="dropdown-item" href="auditoria.php"><i class="bi bi-journal-text me-2"></i>Auditoría</a></li>
          </ul>
        </li>
        <?php endif; ?>

      </ul>

      <!-- Búsqueda global (admin y supervisor) -->
      <?php if (!isNormal()): ?>
      <form class="d-flex me-2" action="busqueda.php" method="GET">
        <div class="input-group input-group-sm">
          <input type="search" name="q" class="form-control form-control-sm"
                 placeholder="Buscar…" style="min-width:160px;background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.2);color:#fff;"
                 autocomplete="off">
          <button class="btn btn-outline-light btn-sm" type="submit">
            <i class="bi bi-search"></i>
          </button>
        </div>
      </form>
      <?php endif; ?>

      <!-- Usuario logueado -->
      <ul class="navbar-nav">
        <?php if ($pendingCnt > 0 && isAdmin()): ?>
        <li class="nav-item me-2 d-flex align-items-center">
          <a href="aprobaciones.php" class="btn btn-sm btn-warning position-relative" title="Cambios pendientes de aprobación">
            <i class="bi bi-bell-fill"></i>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $pendingCnt ?></span>
          </a>
        </li>
        <?php endif; ?>
        <?php if ($pendingAdminTicketApprovalCnt > 0 && isAdmin()): ?>
        <li class="nav-item me-2 d-flex align-items-center">
          <a href="tickets.php?estado=ABIERTO&pendiente_usuario=1" class="btn btn-sm btn-danger position-relative" title="Solicitudes de usuario pendientes de atención">
            <i class="bi bi-ticket-perforated-fill"></i>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-light text-danger" style="font-size:.6rem;"><?= $pendingAdminTicketApprovalCnt ?></span>
          </a>
        </li>
        <?php endif; ?>
        <?php if ($pendingSupervisorCnt > 0 && isSuper()): ?>
        <li class="nav-item me-2 d-flex align-items-center">
          <a href="tickets.php" class="btn btn-sm btn-warning position-relative" title="Tickets asignados por atender">
            <i class="bi bi-bell-fill"></i>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $pendingSupervisorCnt ?></span>
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
            <span class="avatar-circle"><?= strtoupper(substr($auth['nombre'],0,1)) ?></span>
            <span class="d-none d-lg-inline small"><?= h($auth['nombre']) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li class="dropdown-header">
              <div class="fw-bold"><?= h($auth['nombre']) ?></div>
              <div><?= rolBadge($auth['rol']) ?></div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="perfil.php"><i class="bi bi-person-circle me-2"></i>Mi Perfil</a></li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item text-danger" href="#"
                 onclick="confirmLogout(); return false;">
                <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
              </a>
            </li>
          </ul>
        </li>
      </ul>
      </div>
  </div>
  </div>
</nav>


<!-- FLASH: renderizado por SweetAlert2 en footer.php -->

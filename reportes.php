<?php
require_once 'includes/auth.php';
requireRol(ROL_ADMIN, ROL_SUPER);
$pageTitle = 'Reportes y Estadísticas';
include 'includes/header.php';

$db = getDB();

// ── 1. PROCESAMIENTO DE FILTROS ──────────────────────────────────────────
$anioActual      = date('Y');
$filtroAnio      = isset($_GET['anio']) && is_numeric($_GET['anio']) ? (int)$_GET['anio'] : $anioActual;
$filtroPeriodo   = isset($_GET['periodo']) && is_numeric($_GET['periodo']) ? (int)$_GET['periodo'] : null; // 1 o 2
$filtroMes       = isset($_GET['mes']) && is_numeric($_GET['mes']) ? (int)$_GET['mes'] : null;
$filtroTipoId    = isset($_GET['tipo_id']) && is_numeric($_GET['tipo_id']) ? (int)$_GET['tipo_id'] : null;
$filtroTipoMant  = isset($_GET['tipo_mantenimiento']) && in_array($_GET['tipo_mantenimiento'], ['PREVENTIVO', 'CORRECTIVO']) ? $_GET['tipo_mantenimiento'] : null;

// Construcción de condiciones de fecha base
$fechaInicio = "$filtroAnio-01-01 00:00:00";
$fechaFin    = "$filtroAnio-12-31 23:59:59";

if ($filtroPeriodo === 1) {
    $fechaInicio = "$filtroAnio-01-01 00:00:00";
    $fechaFin    = "$filtroAnio-06-30 23:59:59";
} elseif ($filtroPeriodo === 2) {
    $fechaInicio = "$filtroAnio-07-01 00:00:00";
    $fechaFin    = "$filtroAnio-12-31 23:59:59";
}

if ($filtroMes) {
    // Si se selecciona un mes específico, sobreescribe el rango al mes entero
    $mesFormateado = str_pad($filtroMes, 2, '0', STR_PAD_LEFT);
    $fechaInicio   = "$filtroAnio-$mesFormateado-01 00:00:00";
    $fechaFin      = date("Y-m-t 23:59:59", strtotime($fechaInicio));
}

// Catálogos para los selects del buscador
$tiposEquipoLista = $db->query("SELECT id, nombre FROM tipos_equipo ORDER BY nombre ASC")->fetchAll();
$aniosDisponibles = [$anioActual, $anioActual - 1, $anioActual - 2];

// ── 2. DATOS PARA GRÁFICAS Y TABLAS (CON FILTROS APLICADOS) ──────────────

// Equipos por tipo (Filtro base de tipo de equipo si aplica)
$condicionEquipos = "e.activo=1";
$paramsEquipos = [];
if ($filtroTipoId) {
    $condicionEquipos .= " AND e.tipo_id = ?";
    $paramsEquipos[] = $filtroTipoId;
}
$stmt = $db->prepare("SELECT t.nombre, COUNT(e.id) total, SUM(e.estado='ACTIVO') activos,
     SUM(e.estado='MANTENIMIENTO') mant, SUM(e.estado='BAJA') bajas
     FROM equipos e JOIN tipos_equipo t ON t.id=e.tipo_id
     WHERE $condicionEquipos GROUP BY t.id ORDER BY total DESC");
$stmt->execute($paramsEquipos);
$equiposPorTipo = $stmt->fetchAll();

// Equipos por área
$stmt = $db->prepare("SELECT a.nombre, COUNT(e.id) total
     FROM equipos e JOIN areas a ON a.id=e.area_id
     WHERE $condicionEquipos GROUP BY a.id ORDER BY total DESC LIMIT 10");
$stmt->execute($paramsEquipos);
$equiposPorArea = $stmt->fetchAll();

// Tickets por período/mes y filtros adicionales
$condicionTickets = "t.fecha_apertura BETWEEN ? AND ?";
$paramsTickets = [$fechaInicio, $fechaFin];
if ($filtroTipoId) {
    $condicionTickets .= " AND e.tipo_id = ?";
    $paramsTickets[] = $filtroTipoId;
}
$ticketsPorMes = $db->prepare("SELECT DATE_FORMAT(t.fecha_apertura,'%Y-%m') mes,
            COUNT(*) total, SUM(t.estado='RESUELTO') resueltos, SUM(t.estado='ABIERTO') abiertos
     FROM tickets t
     LEFT JOIN equipos e ON e.id = t.equipo_id
     WHERE $condicionTickets GROUP BY mes ORDER BY mes ASC");
$ticketsPorMes->execute($paramsTickets);
$ticketsPorMes = $ticketsPorMes->fetchAll();

// Mantenimientos por período/mes y filtros adicionales
$condicionMants = "m.fecha BETWEEN ? AND ?";
$paramsMants = [$fechaInicio, $fechaFin];
if ($filtroTipoId) {
    $condicionMants .= " AND e.tipo_id = ?";
    $paramsMants[] = $filtroTipoId;
}
if ($filtroTipoMant) {
    $condicionMants .= " AND m.tipo = ?";
    $paramsMants[] = $filtroTipoMant;
}

$mantsPorMes = $db->prepare("SELECT DATE_FORMAT(m.fecha,'%Y-%m') mes, COUNT(*) total,
            SUM(m.tipo='PREVENTIVO') preventivos, SUM(m.tipo='CORRECTIVO') correctivos
     FROM mantenimientos m
     LEFT JOIN equipos e ON e.id = m.equipo_id
     WHERE $condicionMants GROUP BY mes ORDER BY mes ASC");
$mantsPorMes->execute($paramsMants);
$mantsPorMes = $mantsPorMes->fetchAll();

// Mantenimientos por tipo de equipo
$mantsPorTipoEquipo = $db->prepare("SELECT COALESCE(t.nombre,'SIN TIPO') tipo_equipo, COUNT(m.id) total
     FROM mantenimientos m
     LEFT JOIN equipos e ON e.id = m.equipo_id
     LEFT JOIN tipos_equipo t ON t.id = e.tipo_id
     WHERE $condicionMants GROUP BY tipo_equipo ORDER BY total DESC, tipo_equipo ASC");
$mantsPorTipoEquipo->execute($paramsMants);
$mantsPorTipoEquipo = $mantsPorTipoEquipo->fetchAll();

// Mantenimientos por área
$mantsPorArea = $db->prepare("SELECT COALESCE(a.nombre,'SIN ÁREA') area_nombre,
       COUNT(m.id) total_mantenimientos,
       COALESCE(eq.total_equipos, 0) total_equipos
     FROM mantenimientos m
     LEFT JOIN equipos e ON e.id = m.equipo_id
     LEFT JOIN areas a ON a.id = e.area_id
     LEFT JOIN (
       SELECT a.id area_id, COUNT(e.id) total_equipos
       FROM equipos e
       JOIN areas a ON a.id = e.area_id
       WHERE e.activo = 1
       GROUP BY a.id
     ) eq ON eq.area_id = a.id
     WHERE $condicionMants GROUP BY area_nombre, eq.total_equipos ORDER BY total_mantenimientos DESC, area_nombre ASC");
$mantsPorArea->execute($paramsMants);
$mantsPorArea = $mantsPorArea->fetchAll();

// Bitácora por periodo
$condicionBitacora = "fecha_inicio BETWEEN ? AND ?";
$bitacoraByMes = $db->prepare("SELECT DATE_FORMAT(fecha_inicio,'%Y-%m') mes, COUNT(*) total,
            SUM(gravedad='CRITICO') criticos, SUM(gravedad='GRAVE') graves
     FROM bitacora WHERE activo=1 AND $condicionBitacora GROUP BY mes ORDER BY mes ASC");
$bitacoraByMes->execute([$fechaInicio, $fechaFin]);
$bitacoraByMes = $bitacoraByMes->fetchAll();

$bitacoraByTipo = $db->query("SELECT tipo, COUNT(*) total FROM bitacora WHERE activo=1 GROUP BY tipo ORDER BY total DESC")->fetchAll();
$ipSegmentos = getIpLibres();
$ticketsEstado = $db->query("SELECT estado, COUNT(*) total FROM tickets GROUP BY estado")->fetchAll();
$invEstado = $db->query("SELECT estado, SUM(cantidad) total FROM inventario WHERE activo=1 GROUP BY estado")->fetchAll();

// Bajas unificadas filtradas por el periodo de la baja
$bajasUnificadas = $db->prepare("SELECT 'EQUIPO' AS origen, e.folio AS identificador, COALESCE(t.nombre, 'Sin Tipo') AS tipo_article,
        e.marca, e.modelo, COALESCE(a.nombre, 'Sin asignar') AS area, e.fecha_baja, e.motivo_baja
     FROM equipos e
     LEFT JOIN tipos_equipo t ON t.id = e.tipo_id
     LEFT JOIN areas a ON a.id = e.area_id
     WHERE e.estado = 'BAJA' AND e.activo = 1 AND e.fecha_baja BETWEEN ? AND ?
     UNION ALL
     SELECT 'INVENTARIO' AS origen, COALESCE(i.serie, 'S/N') AS identificador, i.tipo AS tipo_article,
        i.marca, i.modelo, COALESCE(a.nombre, 'Sin asignar') AS area, i.fecha_baja, i.motivo_baja
     FROM inventario i
     LEFT JOIN areas a ON a.id = i.area_id
     WHERE i.estado = 'BAJA' AND i.activo = 1 AND i.fecha_baja BETWEEN ? AND ?
     ORDER BY fecha_baja DESC");
$bajasUnificadas->execute([$fechaInicio, $fechaFin, $fechaInicio, $fechaFin]);
$bajasUnificadas = $bajasUnificadas->fetchAll();

$topEquipos = $db->query("SELECT e.folio, e.marca, e.modelo, COUNT(t.id) total_tickets
     FROM tickets t JOIN equipos e ON e.id=t.equipo_id GROUP BY e.id ORDER BY total_tickets DESC LIMIT 5")->fetchAll();
$ticketsPrio = $db->query("SELECT prioridad, COUNT(*) total FROM tickets GROUP BY prioridad ORDER BY FIELD(prioridad,'URGENTE','ALTA','MEDIA','BAJA')")->fetchAll();

// Totales Generales adaptados al conteo global activo
$stats = [
    'equipos'      => $db->query("SELECT COUNT(*) FROM equipos WHERE activo=1")->fetchColumn(),
    'usuarios'     => $db->query("SELECT COUNT(*) FROM usuarios WHERE activo=1")->fetchColumn(),
    'tickets'      => $db->query("SELECT COUNT(*) FROM tickets")->fetchColumn(),
    'resueltos'    => $db->query("SELECT COUNT(*) FROM tickets WHERE estado='RESUELTO'")->fetchColumn(),
    'mants'        => $db->query("SELECT COUNT(*) FROM mantenimientos")->fetchColumn(),
    'inventario'   => $db->query("SELECT COALESCE(SUM(cantidad),0) FROM inventario WHERE activo=1")->fetchColumn(),
];
$stats['tasa_resolucion'] = $stats['tickets'] > 0 ? round(($stats['resueltos'] / $stats['tickets']) * 100, 1) : 0;

// ── 3. PREPARACIÓN DE SERIALIZACIÓN JSON PARA CHART.JS ──────────────────
$jEquiposTipo  = json_encode(array_column($equiposPorTipo,'nombre'));
$jEquiposTot   = json_encode(array_map('intval', array_column($equiposPorTipo,'total')));
$jEquiposAct   = json_encode(array_map('intval', array_column($equiposPorTipo,'activos')));
$jAreaNom      = json_encode(array_column($equiposPorArea,'nombre'));
$jAreaTot      = json_encode(array_map('intval', array_column($equiposPorArea,'total')));

$jMeses        = json_encode(array_column($ticketsPorMes,'mes'));
$jTktTot       = json_encode(array_map('intval', array_column($ticketsPorMes,'total')));
$jTktRes       = json_encode(array_map('intval', array_column($ticketsPorMes,'resueltos')));

$jMantMes      = json_encode(array_column($mantsPorMes,'mes'));
$jMantPrev     = json_encode(array_map('intval', array_column($mantsPorMes,'preventivos')));
$jMantCorr     = json_encode(array_map('intval', array_column($mantsPorMes,'correctivos')));

$resumenMantMes = array_map(static function(array $r): array {
    $partes = explode('-', (string)$r['mes']);
    $anio = $partes[0] ?? '';
    $mesNum = (int)($partes[1] ?? 0);
    $meses = [
      1=>'Ene', 2=>'Feb', 3=>'Mar', 4=>'Abr', 5=>'May', 6=>'Jun',
      7=>'Jul', 8=>'Ago', 9=>'Sep', 10=>'Oct', 11=>'Nov', 12=>'Dic'
    ];
    return [
      'mes' => ($meses[$mesNum] ?? $r['mes']) . ($anio !== '' ? ' ' . $anio : ''),
      'total' => (int)($r['total'] ?? 0),
      'preventivos' => (int)($r['preventivos'] ?? 0),
      'correctivos' => (int)($r['correctivos'] ?? 0),
    ];
}, $mantsPorMes);

$totalMantenimientosMes = array_sum(array_column($resumenMantMes, 'total'));
$totalPreventivosMes = array_sum(array_column($resumenMantMes, 'preventivos'));
$totalCorrectivosMes = array_sum(array_column($resumenMantMes, 'correctivos'));

$jTktEstLbl    = json_encode(array_column($ticketsEstado,'estado'));
$jTktEstTot    = json_encode(array_map('intval', array_column($ticketsEstado,'total')));
$jInvLbl       = json_encode(array_column($invEstado,'estado'));
$jInvTot       = json_encode(array_map('intval', array_column($invEstado,'total')));
$jPrioLbl      = json_encode(array_column($ticketsPrio,'prioridad'));
$jPrioTot      = json_encode(array_map('intval', array_column($ticketsPrio,'total')));

$jBitMes       = json_encode(array_column($bitacoraByMes,'mes'));
$jBitTot       = json_encode(array_map('intval', array_column($bitacoraByMes,'total')));
$jBitCrit      = json_encode(array_map('intval', array_column($bitacoraByMes,'criticos')));
$jBitGrav      = json_encode(array_map('intval', array_column($bitacoraByMes,'graves')));
$jBitTipoLbl   = json_encode(array_map(fn($r)=>str_replace('_',' ',$r['tipo']), $bitacoraByTipo));
$jBitTipoTot   = json_encode(array_map('intval', array_column($bitacoraByTipo,'total')));
?>

<div class="container-fluid px-4 py-3">

  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 pb-2 border-bottom">
    <h4 class="mb-0 text-dark fw-bold"><i class="bi bi-bar-chart-line-fill text-primary me-2"></i>Reportes y Estadísticas</h4>
    <div class="d-flex gap-2">
      <a href="exports/export_equipos_pdf.php?<?= http_build_query($_GET) ?>" target="_blank" class="btn btn-sm btn-outline-danger shadow-sm no-print">
        <i class="bi bi-file-earmark-pdf me-1"></i>PDF Equipos
      </a>
      <a href="exports/export_equipos_xls.php?<?= http_build_query($_GET) ?>" class="btn btn-sm btn-outline-success shadow-sm no-print">
        <i class="bi bi-file-earmark-excel me-1"></i>Excel Equipos
      </a>
      <button onclick="window.print()" class="btn btn-sm btn-outline-secondary shadow-sm no-print">
        <i class="bi bi-printer me-1"></i>Imprimir Panel
      </button>
    </div>
  </div>

  <div class="card mb-4 border-0 shadow-sm no-print bg-white rounded-3">
    <div class="card-body py-3">
      <form method="GET" action="" id="form-filtros" class="row g-2 align-items-end">
        
        <div class="col-6 col-sm-4 col-md-2">
          <label class="form-label small fw-bold text-secondary mb-1">Año</label>
          <select name="anio" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()">
              <?php foreach($aniosDisponibles as $a): ?>
                <option value="<?= $a ?>" <?= $filtroAnio === $a ? 'selected' : '' ?>><?= $a ?></option>
              <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-sm-4 col-md-2">
          <label class="form-label small fw-bold text-secondary mb-1">Periodo Semestral</label>
          <select name="periodo" id="select-periodo" class="form-select form-select-sm shadow-sm" onchange="actualizarFiltroMes(); this.form.submit();">
            <option value="">Año Completo</option>
            <option value="1" <?= $filtroPeriodo === 1 ? 'selected' : '' ?>>1er Periodo (Ene - Jun)</option>
            <option value="2" <?= $filtroPeriodo === 2 ? 'selected' : '' ?>>2do Periodo (Jul - Dic)</option>
          </select>
        </div>

        <div class="col-6 col-sm-4 col-md-2">
          <label class="form-label small fw-bold text-secondary mb-1">Mes (Opcional)</label>
          <select name="mes" id="select-mes" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()">
            <option value="">Todos los meses</option>
            <?php 
            $mesesNombre = [
                1=>'Enero', 2=>'Febrero', 3=>'Marzo', 4=>'Abril', 5=>'Mayo', 6=>'Junio',
                7=>'Julio', 8=>'Agosto', 9=>'Septiembre', 10=>'Octubre', 11=>'Noviembre', 12=>'Diciembre'
            ];
            foreach($mesesNombre as $num => $nombre): 
            ?>
              <option value="<?= $num ?>" data-periodo="<?= $num <= 6 ? 1 : 2 ?>" <?= $filtroMes === $num ? 'selected' : '' ?>><?= $nombre ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-sm-6 col-md-3">
          <label class="form-label small fw-bold text-secondary mb-1">Tipo de Equipo</label>
          <select name="tipo_id" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()">
            <option value="">Todos los tipos</option>
            <?php foreach($tiposEquipoLista as $tipo): ?>
              <option value="<?= $tipo['id'] ?>" <?= $filtroTipoId === (int)$tipo['id'] ? 'selected' : '' ?>><?= h($tipo['nombre']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-12 col-sm-6 col-md-2">
          <label class="form-label small fw-bold text-secondary mb-1">Tipo Mantenimiento</label>
          <select name="tipo_mantenimiento" class="form-select form-select-sm shadow-sm" onchange="this.form.submit()">
            <option value="">Ambos Tipos</option>
            <option value="PREVENTIVO" <?= $filtroTipoMant === 'PREVENTIVO' ? 'selected' : '' ?>>Preventivos</option>
            <option value="CORRECTIVO" <?= $filtroTipoMant === 'CORRECTIVO' ? 'selected' : '' ?>>Correctivos</option>
          </select>
        </div>

        <div class="col-12 col-md-1 d-grid">
          <a href="reportes.php" class="btn btn-sm btn-secondary shadow-sm text-nowrap" title="Limpiar Filtros">
            <i class="bi bi-trash"></i> Limpiar
          </a>
        </div>
      </form>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <?php $kpis = [
      ['Equipos Registrados',  $stats['equipos'],           'bg-ti-blue',   'bi-pc-display'],
      ['Usuarios',             $stats['usuarios'],           'bg-ti-purple', 'bi-people'],
      ['Total Tickets',        $stats['tickets'],            'bg-ti-red',    'bi-ticket'],
      ['Tasa de Resolución',   $stats['tasa_resolucion'].'%','bg-ti-green',  'bi-check-circle'],
      ['Mantenimientos',       $stats['mants'],              'bg-ti-orange', 'bi-clipboard-check'],
      ['Inventario',           $stats['inventario'],         'bg-ti-teal',   'bi-boxes'],
    ]; foreach ($kpis as [$lbl,$val,$cls,$ico]): ?>
    <div class="col-6 col-md-4 col-xl-2">
      <div class="stat-card <?= $cls ?> h-100 shadow-sm rounded-3 p-3 position-relative overflow-hidden">
        <div class="stat-val fs-3 fw-bold"><?= $val ?></div>
        <div class="stat-lbl small text-uppercase tracking-wider opacity-75"><?= $lbl ?></div>
        <i class="bi <?= $ico ?> stat-icon position-absolute end-0 bottom-0 m-2 opacity-25"></i>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-lg-6">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span class="fw-bold text-dark"><i class="bi bi-clipboard-check text-primary me-2"></i>Mantenimientos por Periodo</span>
          <div class="d-flex gap-1 no-print">
            <a href="mantenimiento.php" class="btn btn-xs btn-outline-primary" title="Ver detalle">
              <i class="bi bi-eye"></i>
            </a>
            <button class="btn btn-xs btn-outline-secondary" onclick="imprimirMant()" title="Imprimir">
              <i class="bi bi-printer"></i>
            </button>
            <button class="btn btn-xs btn-outline-danger" onclick="exportarPDFMant()" title="Exportar PDF">
              <i class="bi bi-file-earmark-pdf"></i>
            </button>
            <button class="btn btn-xs btn-outline-success" onclick="exportarExcelMant()" title="Exportar Excel">
              <i class="bi bi-file-earmark-excel"></i>
            </button>
          </div>
        </div>
        
        <div id="area-mant-impresion">
          <div class="card-body pt-0">
            <div class="print-header-mant d-none">
              <h4 class="mb-1">Reporte &mdash; Mantenimientos Registrados</h4>
              <p class="text-muted small mb-3">Filtrado activo &nbsp;|&nbsp; Generado: <?= date('d/m/Y H:i') ?></p>
            </div>

            <div class="chart-container mb-3 position-relative" style="height:220px;">
              <canvas id="chartMantMes"></canvas>
            </div>

            <div class="mt-3 pt-2 border-top">
              <h6 class="mb-2 fw-bold text-secondary">Resumen de Métricas</h6>
              <div class="row g-2 mb-3 bg-light p-2 rounded">
                <div class="col-4 border-end">
                  <div class="small text-muted">Total en Rango</div>
                  <div class="fw-bold fs-5 text-dark"><?= $totalMantenimientosMes ?></div>
                </div>
                <div class="col-4 border-end ps-3">
                  <div class="small text-muted mb-1">Preventivos</div>
                  <span class="badge bg-info text-dark font-monospace px-2 py-1"><?= $totalPreventivosMes ?></span>
                </div>
                <div class="col-4 ps-3">
                  <div class="small text-muted mb-1">Correctivos</div>
                  <span class="badge bg-warning text-dark font-monospace px-2 py-1"><?= $totalCorrectivosMes ?></span>
                </div>
              </div>

              <div class="small fw-bold text-muted mb-2"><i class="bi bi-list-nested me-1"></i>Desglose Mensual</div>
              <div class="table-responsive max-h-200 mb-3 border rounded">
                <table class="table table-sm table-hover align-middle mb-0" id="tbl-mant-mes" style="font-size:.8rem;">
                  <thead class="table-light sticky-top">
                    <tr>
                      <th>Mes</th>
                      <th class="text-center">Total</th>
                      <th class="text-center">Preventivos</th>
                      <th class="text-center">Correctivos</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($resumenMantMes as $r): ?>
                    <tr>
                      <td class="fw-semibold"><?= h($r['mes']) ?></td>
                      <td class="text-center fw-bold text-primary"><?= $r['total'] ?></td>
                      <td class="text-center"><span class="badge bg-info-soft text-info"><?= $r['preventivos'] ?></span></td>
                      <td class="text-center"><span class="badge bg-warning-soft text-warning"><?= $r['correctivos'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($resumenMantMes)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-2">Sin mantenimientos en este periodo</td></tr>
                    <?php endif; ?>
                  </tbody>
                  <tfoot class="table-light sticky-bottom border-top fw-bold">
                    <tr>
                      <td>TOTAL</td>
                      <td class="text-center text-primary"><?= $totalMantenimientosMes ?></td>
                      <td class="text-center text-info"><?= $totalPreventivosMes ?></td>
                      <td class="text-center text-warning"><?= $totalCorrectivosMes ?></td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <div class="small fw-bold text-muted mb-2"><i class="bi bi-pc-display-horizontal me-1"></i>Por tipo de equipo</div>
              <div class="table-responsive max-h-150 border rounded">
                <table class="table table-sm table-hover align-middle mb-0" id="tbl-mant-tipo" style="font-size:.8rem;">
                  <thead class="table-light sticky-top">
                    <tr>
                      <th>Tipo de Equipo</th>
                      <th class="text-center">Mantenimientos</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($mantsPorTipoEquipo as $r): ?>
                    <tr>
                      <td class="fw-semibold"><?= h($r['tipo_equipo']) ?></td>
                      <td class="text-center fw-bold text-secondary"><?= (int)$r['total'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($mantsPorTipoEquipo)): ?>
                    <tr><td colspan="2" class="text-center text-muted py-2">Sin datos</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <div class="small fw-bold text-muted mb-2 mt-3"><i class="bi bi-building me-1"></i>Por área</div>
              <div class="table-responsive border rounded">
                <table class="table table-sm table-hover align-middle mb-0" id="tbl-mant-area" style="font-size:.8rem;">
                  <thead class="table-light sticky-top">
                    <tr>
                      <th>Área</th>
                      <th class="text-center">Equipos</th>
                      <th class="text-center">Mantenimientos</th>
                      <th class="text-center">% Avance</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($mantsPorArea as $r):
                      $porcentaje = $r['total_equipos'] > 0 ? round(($r['total_mantenimientos'] / $r['total_equipos']) * 100, 1) : 0;
                      $badgeClass = $porcentaje >= 80 ? 'bg-success' : ($porcentaje >= 50 ? 'bg-warning' : 'bg-danger');
                    ?>
                    <tr>
                      <td class="fw-semibold"><?= h($r['area_nombre']) ?></td>
                      <td class="text-center fw-bold text-primary"><?= (int)$r['total_equipos'] ?></td>
                      <td class="text-center fw-bold text-secondary"><?= (int)$r['total_mantenimientos'] ?></td>
                      <td class="text-center">
                        <span class="badge <?= $badgeClass ?>"><?= $porcentaje ?>%</span>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($mantsPorArea)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-2">Sin datos</td></tr>
                    <?php endif; ?>
                  </tbody>
                  <?php if (!empty($mantsPorArea)):
                    $totalEquiposGlobal = array_sum(array_column($mantsPorArea, 'total_equipos'));
                    $totalMantenimientosGlobal = array_sum(array_column($mantsPorArea, 'total_mantenimientos'));
                    $porcentajeGlobal = $totalEquiposGlobal > 0 ? round(($totalMantenimientosGlobal / $totalEquiposGlobal) * 100, 1) : 0;
                    $badgeGlobalClass = $porcentajeGlobal >= 80 ? 'bg-success' : ($porcentajeGlobal >= 50 ? 'bg-warning' : 'bg-danger');
                  ?>
                  <tfoot class="table-light fw-bold">
                    <tr>
                      <td class="text-primary">TOTAL GLOBAL</td>
                      <td class="text-center text-primary"><?= $totalEquiposGlobal ?></td>
                      <td class="text-center text-secondary"><?= $totalMantenimientosGlobal ?></td>
                      <td class="text-center">
                        <span class="badge <?= $badgeGlobalClass ?>"><?= $porcentajeGlobal ?>%</span>
                      </td>
                    </tr>
                  </tfoot>
                  <?php endif; ?>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark"><i class="bi bi-bar-chart text-primary me-2"></i>Equipos por Tipo</div>
        <div class="card-body pt-0 d-flex align-items-center"><canvas id="chartTipos" height="220"></canvas></div>
      </div>
    </div>
    <div class="col-md-6 col-lg-3">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark"><i class="bi bi-building text-primary me-2"></i>Equipos por Área (Top 10)</div>
        <div class="card-body pt-0 d-flex align-items-center"><canvas id="chartAreas" height="220"></canvas></div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <div class="col-md-5">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark"><i class="bi bi-boxes text-primary me-2"></i>Inventario por Estado</div>
        <div class="card-body pt-0 d-flex align-items-center justify-content-center">
          <canvas id="chartInvEstado" style="max-height:220px;"></canvas>
        </div>
      </div>
    </div>
    <div class="col-md-7">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark d-flex justify-content-between align-items-center">
          <span><i class="bi bi-table text-primary me-2"></i>Resumen de Equipos por Tipo</span>
        </div>
        <div class="card-body p-0 table-responsive max-h-350 border-top">
          <table class="table table-hover align-middle mb-0 style-table">
            <thead class="table-light sticky-top">
              <tr>
                <th>Tipo</th>
                <th class="text-center">Total</th>
                <th class="text-center">Activos</th>
                <th class="text-center">En Mant.</th>
                <th class="text-center">Bajas</th>
                <th class="text-center" style="width: 100px;">% Distribución</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($equiposPorTipo as $r):
              $pct = $stats['equipos'] > 0 ? round($r['total']/$stats['equipos']*100,1) : 0;
            ?>
            <tr>
              <td><strong><?= h($r['nombre']) ?></strong></td>
              <td class="text-center"><span class="badge bg-primary px-2 py-1"><?= $r['total'] ?></span></td>
              <td class="text-center"><span class="badge bg-success px-2 py-1"><?= $r['activos'] ?></span></td>
              <td class="text-center"><span class="badge bg-warning text-dark px-2 py-1"><?= $r['mant'] ?></span></td>
              <td class="text-center"><span class="badge bg-danger px-2 py-1"><?= $r['bajas'] ?></span></td>
              <td class="text-center">
                <div class="progress mb-1" style="height:6px; min-width:70px;">
                  <div class="progress-bar" style="width:<?= $pct ?>%; background:#1a3a5c;"></div>
                </div>
                <small class="text-muted fw-bold"><?= $pct ?>%</small>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
    
  <div class="row g-3 mb-4">
    <div class="col-md-4">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark"><i class="bi bi-pie-chart text-primary me-2"></i>Estado de Tickets</div>
        <div class="card-body pt-0 d-flex align-items-center justify-content-center">
          <canvas id="chartTktEstado" style="max-height:220px;"></canvas>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark"><i class="bi bi-exclamation-triangle text-primary me-2"></i>Tickets por Prioridad</div>
        <div class="card-body pt-0 d-flex align-items-center justify-content-center">
          <canvas id="chartPrioridad" style="max-height:220px;"></canvas>
        </div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark"><i class="bi bi-trophy text-primary me-2"></i>Top Equipos con más Tickets</div>
        <div class="card-body p-0 border-top">
          <div class="table-responsive mb-0">
            <table class="table table-hover align-middle mb-0 style-table">
              <thead class="table-light">
                <tr>
                  <th style="width:50px;">#</th>
                  <th>Folio</th>
                  <th>Equipo</th>
                  <th class="text-center">Tickets</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($topEquipos as $i => $e): ?>
              <tr>
                <td><span class="badge bg-<?= ['danger','warning','info','secondary','secondary'][$i] ?> rounded-circle p-2 d-inline-flex align-items-center justify-content-center" style="width:24px; height:24px;"><?= $i+1 ?></span></td>
                <td><code class="fw-bold"><?= h($e['folio']) ?></code></td>
                <td class="small fw-semibold"><?= h($e['marca'].' '.($e['modelo']??'')) ?></td>
                <td class="text-center"><span class="badge bg-light text-primary border border-primary px-2 py-1 fw-bold"><?= $e['total_tickets'] ?></span></td>
              </tr>
              <?php endforeach; ?>
              <?php if(empty($topEquipos)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">Sin datos</td></tr>
              <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div> <div class="row g-3 mb-4">
    <div class="col-12">
      <div class="card border-0 shadow-sm rounded-3 bg-white">
        <div class="card-header border-0 bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
          <span class="fw-bold text-dark">
            <i class="bi bi-archive-fill text-danger me-2"></i>
            Catálogo de Bajas en el Periodo
            <span class="badge bg-danger-soft text-danger ms-2 fw-bold font-monospace" style="font-size:.75rem;">
              <?= count($bajasUnificadas) ?> registro<?= count($bajasUnificadas) !== 1 ? 's' : '' ?>
            </span>
          </span>
          <div class="d-flex gap-2 no-print align-items-center flex-wrap">
            <select id="filtro-origen-bajas" class="form-select form-select-sm shadow-sm" style="width:auto;" onchange="filtrarTablasBajas()">
              <option value="">Todos los Orígenes</option>
              <option value="EQUIPO">Equipos</option>
              <option value="INVENTARIO">Inventario</option>
            </select>
            <div class="input-group input-group-sm shadow-sm" style="width:220px;">
              <span class="input-group-text bg-light text-secondary border-end-0"><i class="bi bi-search"></i></span>
              <input type="search" id="buscar-bajas" class="form-control form-control-sm border-start-0" placeholder="Buscar bajas…" oninput="filtrarTablasBajas()">
            </div>
            <button class="btn btn-sm btn-outline-secondary shadow-sm" onclick="imprimirBajas()" title="Imprimir"><i class="bi bi-printer"></i></button>
            <button class="btn btn-sm btn-outline-danger shadow-sm" onclick="exportarPDFBajas()" title="Exportar PDF"><i class="bi bi-file-earmark-pdf"></i></button>
            <button class="btn btn-sm btn-outline-success shadow-sm" onclick="exportarExcelBajas()" title="Exportar Excel"><i class="bi bi-file-earmark-excel"></i></button>
          </div>
        </div>
   
        <div id="area-bajas-impresion" class="border-top">
          <div class="card-body p-0">
            <div class="print-header-bajas d-none px-4 pt-3 pb-2 border-bottom">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <h4 class="mb-1 fw-bold">Catálogo de Bajas</h4>
                  <p class="text-muted small mb-0">Rango Seleccionado &nbsp;|&nbsp; <?= count($bajasUnificadas) ?> registros &nbsp;|&nbsp; Generado: <?= date('d/m/Y H:i') ?></p>
                </div>
                <div class="text-end small text-muted"><?= defined('APP_NAME') ? APP_NAME : 'Sistemas TI' ?></div>
              </div>
            </div>
   
            <div class="table-responsive" id="contenedor-tabla-bajas" style="max-height:400px; overflow-y:auto;">
              <table class="table table-hover table-sm align-middle mb-0" id="tbl-bajas" style="font-size:.84rem;">
                <thead class="table-light sticky-top">
                  <tr>
                    <th style="width:110px;">Origen</th>
                    <th style="width:140px;">Folio / Serie</th>
                    <th style="width:120px;">Tipo</th>
                    <th>Artículo</th>
                    <th>Última Área</th>
                    <th style="width:120px;">Fecha Baja</th>
                    <th>Razón de Baja</th>
                  </tr>
                </thead>
                <tbody id="tbody-bajas">
                <?php foreach ($bajasUnificadas as $baja): ?>
                  <tr data-origen="<?= h($baja['origen']) ?>">
                    <td>
                      <?php if($baja['origen'] === 'EQUIPO'): ?>
                        <span class="badge bg-primary-soft text-primary"><i class="bi bi-pc-display me-1"></i>Equipo</span>
                      <?php else: ?>
                        <span class="badge bg-teal-soft text-teal"><i class="bi bi-box-seam me-1"></i>Inventario</span>
                      <?php endif; ?>
                    </td>
                    <td><code class="small fw-bold"><?= h($baja['identificador']) ?></code></td>
                    <td><span class="badge bg-secondary-soft text-secondary"><?= h($baja['tipo_article']) ?></span></td>
                    <td>
                      <span class="fw-bold text-dark"><?= h($baja['marca']) ?></span>
                      <span class="text-muted ms-1 small"><?= h($baja['modelo']) ?></span>
                    </td>
                    <td class="small fw-semibold text-muted"><i class="bi bi-geo-alt text-danger me-1"></i><?= h($baja['area']) ?></td>
                    <td class="small text-nowrap font-monospace text-secondary">
                      <?php if($baja['fecha_baja']): ?>
                        <i class="bi bi-calendar-x text-danger me-1"></i><?= date('d/m/Y', strtotime($baja['fecha_baja'])) ?>
                      <?php else: ?>
                        <span class="text-muted fst-italic">—</span>
                      <?php endif; ?>
                    </td>
                    <td class="small text-secondary"><?= h($baja['motivo_baja']) ?></td>
                  </tr>
                <?php endforeach; ?>
                <?php if(empty($bajasUnificadas)): ?>
                  <tr id="row-sin-bajas"><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-inbox d-block fs-3 opacity-25 mb-1"></i>No hay registros de baja en el periodo indicado.</td></tr>
                <?php endif; ?>
                <tr id="row-sin-resultados" style="display:none;"><td colspan="7" class="text-center text-muted py-3"><i class="bi bi-search me-1"></i>Sin resultados para esa búsqueda.</td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-md-5">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark d-flex justify-content-between align-items-center">
          <span><i class="bi bi-journal-text text-primary me-2"></i>Incidentes en Periodo</span>
          <a href="bitacora.php" class="btn btn-xs btn-outline-primary no-print">Ver Bitácora</a>
        </div>
        <div class="card-body pt-0 d-flex align-items-center"><canvas id="chartBitacoraMes" height="200"></canvas></div>
      </div>
    </div>
    <div class="col-md-4">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark"><i class="bi bi-exclamation-triangle text-primary me-2"></i>Incidentes por Tipo</div>
        <div class="card-body pt-0 d-flex align-items-center justify-content-center">
          <canvas id="chartBitacoraTop" style="max-height:240px;"></canvas>
        </div>
      </div>
    </div>
    <div class="col-md-3">
      <div class="card h-100 shadow-sm border-0 rounded-3">
        <div class="card-header bg-white border-0 py-3 fw-bold text-dark d-flex justify-content-between align-items-center">
          <span><i class="bi bi-diagram-3 text-primary me-2"></i>Segmentos IP</span>
          <a href="segmentos_ip.php" class="btn btn-xs btn-outline-primary no-print">Ver mapa</a>
        </div>
        <div class="card-body pt-0">
          <?php foreach ($ipSegmentos as $seg): ?>
          <div class="mb-3 p-2 border rounded bg-light-soft">
            <div class="d-flex justify-content-between mb-1">
              <span class="small fw-bold text-dark"><?= h($seg['nombre']) ?></span>
              <code class="small px-1 rounded bg-white border" style="color:<?= h($seg['color']) ?>;"><?= h($seg['red']) ?>.*</code>
            </div>
            <?php $pctSeg = $seg['total'] > 0 ? round($seg['usadas'] / $seg['total'] * 100, 0) : 0; ?>
            <div class="progress mb-1 shadow-inner" style="height:8px;">
              <div class="progress-bar" style="width:<?= $pctSeg ?>%; background:<?= h($seg['color']) ?>;"></div>
            </div>
            <div class="d-flex justify-content-between" style="font-size: .75rem;">
              <span class="fw-semibold text-danger"><?= $seg['usadas'] ?> usadas</span>
              <span class="fw-semibold text-success"><?= $seg['libres'] ?> libres</span>
              <span class="text-muted fw-bold"><?= $pctSeg ?>%</span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

</div>
 
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<script>
// Deshabilitar o habilitar meses dinámicamente según el período seleccionado
function actualizarFiltroMes() {
    const periodo = document.getElementById('select-periodo').value;
    const selectMes = document.getElementById('select-mes');
    const opciones = selectMes.querySelectorAll('option[data-periodo]');
    
    if (!periodo) {
        opciones.forEach(opt => opt.disabled = false);
        return;
    }
    
    opciones.forEach(opt => {
        if (opt.dataset.periodo !== periodo) {
            opt.disabled = true;
            if (opt.selected) selectMes.value = ""; // Resetea si estaba en un mes inválido
        } else {
            opt.disabled = false;
        }
    });
}

// Ejecutar al cargar la página para mantener consistencia de UI
document.addEventListener("DOMContentLoaded", actualizarFiltroMes);

const C = {
  primary:'#1a3a5c', accent:'#0d6efd', success:'#198754', danger:'#dc3545',
  warning:'#ffc107', info:'#0dcaf0', purple:'#6f42c1', teal:'#20c997',
  orange:'#fd7e14', gray:'#6c757d',
};
Chart.defaults.font.family = "'Inter', system-ui, -apple-system, sans-serif";
Chart.defaults.font.size   = 11;
Chart.defaults.color       = '#555';

const palette = [C.primary,C.accent,C.success,C.danger,C.warning,C.purple,C.teal,C.orange,C.gray,'#e83e8c'];

// Renderizadores de Gráficos de Chart.js
new Chart(document.getElementById('chartTipos'), {
  type: 'bar',
  data: {
    labels: <?= $jEquiposTipo ?>,
    datasets: [
      { label:'Total',   data: <?= $jEquiposTot ?>, backgroundColor: C.primary + 'cc', borderRadius:4 },
      { label:'Activos', data: <?= $jEquiposAct ?>, backgroundColor: C.success + 'cc', borderRadius:4 },
    ]
  },
  options: { responsive:true, maintainAspectRatio: false, plugins:{ legend:{ position:'bottom' } }, scales:{ y:{ beginAtZero:true } } }
});

new Chart(document.getElementById('chartAreas'), {
  type: 'bar',
  data: {
    labels: <?= $jAreaNom ?>,
    datasets: [{ label:'Equipos', data: <?= $jAreaTot ?>, backgroundColor: palette, borderRadius:4 }]
  },
  options: { indexAxis: 'y', responsive:true, maintainAspectRatio: false, plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true } } }
});

new Chart(document.getElementById('chartMantMes'), {
  type: 'bar',
  data: {
    labels: <?= $jMantMes ?>,
    datasets: [
      { label:'Preventivo',  data: <?= $jMantPrev ?>, backgroundColor: C.info+'cc',    borderRadius:4 },
      { label:'Correctivo',  data: <?= $jMantCorr ?>, backgroundColor: C.warning+'cc', borderRadius:4 },
    ]
  },
  options: { responsive:true, maintainAspectRatio: false, plugins:{ legend:{ position:'bottom' } }, scales:{ x:{ stacked:true }, y:{ stacked:true, beginAtZero:true } } }
});

new Chart(document.getElementById('chartTktEstado'), {
  type: 'doughnut',
  data: { labels: <?= $jTktEstLbl ?>, datasets:[{ data: <?= $jTktEstTot ?>, backgroundColor:[C.danger,C.warning,C.success,C.gray], borderWidth:2 }] },
  options:{ responsive:true, maintainAspectRatio: false, plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12 } } } }
});

new Chart(document.getElementById('chartInvEstado'), {
  type: 'doughnut',
  data: { labels: <?= $jInvLbl ?>, datasets:[{ data: <?= $jInvTot ?>, backgroundColor:[C.success,C.warning,C.danger,C.gray], borderWidth:2 }] },
  options:{ responsive:true, maintainAspectRatio: false, plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12 } } } }
});

new Chart(document.getElementById('chartPrioridad'), {
  type: 'polarArea',
  data: { labels: <?= $jPrioLbl ?>, datasets:[{ data: <?= $jPrioTot ?>, backgroundColor:[C.danger+'cc',C.warning+'cc',C.info+'cc',C.gray+'cc'], borderWidth:1 }] },
  options:{ responsive:true, maintainAspectRatio: false, plugins:{ legend:{ position:'bottom', labels:{ boxWidth:12 } } } }
});

new Chart(document.getElementById('chartBitacoraMes'), {
  type: 'bar',
  data: {
    labels: <?= $jBitMes ?>,
    datasets: [
      { label:'Total',    data: <?= $jBitTot ?>,  backgroundColor: C.primary+'cc', borderRadius:4 },
      { label:'Críticos', data: <?= $jBitCrit ?>, backgroundColor: C.danger+'cc',  borderRadius:4 },
      { label:'Graves',   data: <?= $jBitGrav ?>, backgroundColor: C.orange+'cc',  borderRadius:4 },
    ]
  },
  options:{ responsive:true, maintainAspectRatio: false, plugins:{ legend:{ position:'bottom' } }, scales:{ x:{stacked:true}, y:{stacked:true, beginAtZero:true} } }
});

new Chart(document.getElementById('chartBitacoraTop'), {
  type: 'bar',
  data: { labels: <?= $jBitTipoLbl ?>, datasets:[{ label:'Incidentes', data: <?= $jBitTipoTot ?>, backgroundColor: palette, borderRadius:4 }] },
  options:{ indexAxis:'y', responsive:true, maintainAspectRatio: false, plugins:{ legend:{ display:false } }, scales:{ x:{ beginAtZero:true } } }
});
</script>

<style>
/* ── MEJORAS ESTÉTICAS MODERNAS ────────────────────────────────────────── */
.btn-xs {
  padding: .25rem .4rem;
  font-size: .75rem;
  border-radius: .25rem;
}
.max-h-150 { max-height: 150px; overflow-y: auto; }
.max-h-200 { max-height: 200px; overflow-y: auto; }
.max-h-350 { max-height: 350px; overflow-y: auto; }

/* Estilizado fino de scrollbars */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: rgba(0,0,0,0.03); }
::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: rgba(0,0,0,0.3); }

/* Badges con color suavizado (Bootstrap Moderno) */
.bg-primary-soft { background-color: rgba(26,58,92,0.1) !important; color: #1a3a5c !important; }
.bg-success-soft { background-color: rgba(25,135,84,0.1) !important; color: #198754 !important; }
.bg-danger-soft  { background-color: rgba(220,53,69,0.1) !important; color: #dc3545 !important; }
.bg-warning-soft { background-color: rgba(255,193,7,0.15) !important; color: #b68600 !important; }
.bg-info-soft    { background-color: rgba(13,202,240,0.1) !important; color: #0aa2c0 !important; }
.bg-secondary-soft{ background-color: rgba(108,117,125,0.1) !important; color: #6c757d !important; }
.bg-teal-soft    { background-color: rgba(32,201,151,0.1) !important; color: #20c997 !important; }
.bg-light-soft   { background-color: #f8f9fa; border: 1px solid #e9ecef; }

/* Estilos para tablas */
.style-table { font-size: 0.88rem; }
.style-table thead th { font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.5px; }

/* Paleta de Tarjetas KPI */
.bg-ti-blue   { background: linear-gradient(135deg, #1a3a5c, #2a5a8c); color: #fff; }
.bg-ti-purple { background: linear-gradient(135deg, #6f42c1, #8a5cd6); color: #fff; }
.bg-ti-red    { background: linear-gradient(135deg, #dc3545, #ee5363); color: #fff; }
.bg-ti-green  { background: linear-gradient(135deg, #198754, #24b26b); color: #fff; }
.bg-ti-orange { background: linear-gradient(135deg, #fd7e14, #ff9944); color: #fff; }
.bg-ti-teal   { background: linear-gradient(135deg, #20c997, #36e2b0); color: #fff; }

.stat-card { transition: transform 0.2s; border: none; }
.stat-card:hover { transform: translateY(-3px); }

/* ── REGLAS DE IMPRESIÓN CORREGIDAS ─────────────────────────────────── */
@media print {
  /* Al activar la clase, ocultamos por completo el contenedor principal */
  body.print-active .container-fluid { 
    display: none !important; 
  }
  
  /* Forzar que las tablas muestren sus colores de badge al imprimir */
  th, td, span, json, .badge {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  
  /* Quitar límites de scroll para que las tablas se expandan de forma natural en el papel */
  .max-h-200, .max-h-150, .table-responsive {
    max-height: none !important;
    overflow: visible !important;
  }
}

/* Contenedor temporal de impresión fuera de la vista web */
#print-window-area {
  display: none;
}
@media print {
  #print-window-area {
    display: block !important;
    width: 100% !important;
    padding: 10px;
    background: #fff !important;
  }
}
</style>

<script>
function imprimirMant() {
  // 1. Mostrar el encabezado de impresión temporalmente
  const header = document.querySelector('.print-header-mant');
  if (header) header.classList.remove('d-none');
  
  // 2. Crear o limpiar el área de impresión dedicada
  let printArea = document.getElementById('print-window-area');
  if (!printArea) {
    printArea = document.createElement('div');
    printArea.id = 'print-window-area';
    document.body.appendChild(printArea);
  }
  printArea.innerHTML = ''; // Limpiar residuos anteriores

  // 3. Clonar el nodo estructural del área de mantenimientos
  const origen = document.getElementById('area-mant-impresion');
  const clon = origen.cloneNode(true);

  // 4. TRUCO DE MAGIA: Copiar el contenido del Canvas original al Canvas clonado
  const canvasOriginales = origen.querySelectorAll('canvas');
  const canvasClonados = clon.querySelectorAll('canvas');
  
  canvasOriginales.forEach((canvasOrig, index) => {
    if (canvasClonados[index]) {
      const ctxClon = canvasClonados[index].getContext('2d');
      // Copia los píxeles exactos del gráfico actual al nuevo elemento
      canvasClonados[index].width = canvasOrig.width;
      canvasClonados[index].height = canvasOrig.height;
      ctxClon.drawImage(canvasOrig, 0, 0);
    }
  });

  // 5. Inyectar el clon con los gráficos vivos en el área de impresión
  printArea.appendChild(clon);
  
  // 6. Activar clases de control y lanzar la ventana de impresión
  document.body.classList.add('print-active');
  
  // Pequeño delay de 50ms para asegurar que el navegador asimile los píxeles del canvas
  setTimeout(() => {
    window.print();
    
    // 7. Limpieza post-impresión
    document.body.classList.remove('print-active');
    printArea.innerHTML = '';
    if (header) header.classList.add('d-none');
  }, 50);
}

async function exportarPDFMant() {
  const { jsPDF } = window.jspdf;
  const header = document.querySelector('.print-header-mant');
  header.classList.remove('d-none');
  const btns = document.querySelectorAll('#area-mant-impresion .no-print');
  btns.forEach(b => b.classList.add('d-none'));
  const area = document.getElementById('area-mant-impresion');

  try {
    const canvas = await html2canvas(area, { scale: 2, useCORS: true, backgroundColor: '#ffffff', logging: false });
    const imgData  = canvas.toDataURL('image/png');
    const pdf      = new jsPDF('p', 'mm', 'a4');
    const pageW    = pdf.internal.pageSize.getWidth();
    const margin   = 10;
    const imgW     = pageW - margin * 2;
    const imgH     = (canvas.height * imgW) / canvas.width;
    pdf.addImage(imgData, 'PNG', margin, margin, imgW, imgH);
    pdf.save('mantenimientos_filtrados_' + new Date().toISOString().slice(0,10) + '.pdf');
  } finally {
    header.classList.add('d-none');
    btns.forEach(b => b.classList.remove('d-none'));
  }
}

function exportarExcelMant() {
  const wb = XLSX.utils.book_new();
  const tblMes = document.getElementById('tbl-mant-mes');
  if (tblMes) {
    const cloneMes = tblMes.cloneNode(true);
    cloneMes.querySelectorAll('.badge').forEach(b => b.replaceWith(document.createTextNode(b.textContent)));
    const wsMes = XLSX.utils.table_to_sheet(cloneMes, { raw: false });
    wsMes['!cols'] = [{ wch: 16 }, { wch: 10 }, { wch: 14 }, { wch: 14 }];
    XLSX.utils.book_append_sheet(wb, wsMes, 'Mensual');
  }
  XLSX.writeFile(wb, 'mantenimientos_periodo_' + new Date().toISOString().slice(0,10) + '.xlsx');
}

function filtrarTablasBajas() {
  const q      = (document.getElementById('buscar-bajas')?.value ?? '').toLowerCase().trim();
  const origen = document.getElementById('filtro-origen-bajas')?.value ?? '';
  const filas  = document.querySelectorAll('#tbody-bajas tr[data-origen]');
  const rowSR  = document.getElementById('row-sin-resultados');
  let visibles = 0;
 
  filas.forEach(tr => {
    const textoFila  = tr.textContent.toLowerCase();
    const origenFila = tr.dataset.origen ?? '';
    tr.style.display = ((!q || textoFila.includes(q)) && (!origen || origenFila === origen)) ? '' : 'none';
    if (tr.style.display === '') visibles++;
  });
  if (rowSR) rowSR.style.display = visibles === 0 ? '' : 'none';
}

function imprimirBajas() {
  // 1. Mostrar el encabezado de bajas para la impresión
  document.querySelector('.print-header-bajas').classList.remove('d-none');
  
  // 2. Obtener el contenido limpio de bajas
  const contenidoHTML = document.getElementById('area-bajas-impresion').innerHTML;
  
  // 3. Asegurar el contenedor temporal
  let printArea = document.getElementById('print-window-area');
  if (!printArea) {
    printArea = document.createElement('div');
    printArea.id = 'print-window-area';
    document.body.appendChild(printArea);
  }
  printArea.innerHTML = contenidoHTML;
  
  // 4. Ejecutar proceso de impresión
  document.body.classList.add('print-active');
  window.print();
  
  // 5. Restaurar interfaz
  document.body.classList.remove('print-active');
  printArea.innerHTML = '';
  document.querySelector('.print-header-bajas').classList.add('d-none');
}

async function exportarPDFBajas() {
  const { jsPDF } = window.jspdf;
  const header = document.querySelector('.print-header-bajas');
  header.classList.remove('d-none');
  const contenedor = document.getElementById('contenedor-tabla-bajas');
  const prevMax = contenedor.style.maxHeight;
  contenedor.style.maxHeight = 'none';
  
  try {
    const canvas = await html2canvas(document.getElementById('area-bajas-impresion'), { scale: 1.5, useCORS: true, backgroundColor: '#ffffff' });
    const pdf = new jsPDF('l', 'mm', 'letter');
    const imgW = pdf.internal.pageSize.getWidth() - 20;
    const imgH = (canvas.height * imgW) / canvas.width;
    pdf.addImage(canvas.toDataURL('image/jpeg', 0.85), 'JPEG', 10, 10, imgW, imgH);
    pdf.save('catalogo_bajas_periodo.pdf');
  } finally {
    header.classList.add('d-none');
    contenedor.style.maxHeight = prevMax;
  }
}

function exportarExcelBajas() {
  const wb = XLSX.utils.book_new();
  const tbl = document.getElementById('tbl-bajas');
  const clone = tbl.cloneNode(true);
  clone.querySelectorAll('.badge').forEach(b => b.replaceWith(document.createTextNode(b.textContent.trim())));
  clone.querySelectorAll('i.bi').forEach(i => i.remove());
  const ws = XLSX.utils.table_to_sheet(clone, { raw: false });
  XLSX.utils.book_append_sheet(wb, ws, 'Bajas');
  XLSX.writeFile(wb, 'bajas_periodo.xlsx');
}
</script>

<?php include 'includes/footer.php'; ?>
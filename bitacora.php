<?php
require_once 'includes/auth.php';

$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);

// ── POST: guardar incidente ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pid = (int)($_POST['id'] ?? 0) ?: null;
    $_POST['suser_id'] = suserIdActual();

    // Construir array de equipos afectados desde POST
    $equiposAfectados = [];
    $eqIds  = $_POST['eq_id']    ?? [];
    $eqDano = $_POST['eq_daño']  ?? [];
    $eqDesc = $_POST['eq_desc']  ?? [];
    foreach ($eqIds as $i => $eqId) {
        if (!$eqId) continue;
        $equiposAfectados[] = [
            'equipo_id'   => (int)$eqId,
            'daño_nivel'  => $eqDano[$i] ?? 'SIN_DAÑO',
            'descripcion' => $eqDesc[$i] ?? '',
        ];
    }

    $saved = saveBitacora($_POST, $equiposAfectados, $pid);
    auditLog($pid ? 'EDITAR_BITACORA' : 'CREAR_BITACORA', 'bitacora', $saved, $_POST['titulo']??'');
    flash('success', $pid ? 'Incidente actualizado.' : 'Incidente registrado correctamente.');
    redirect('bitacora.php?action=view&id='.$saved);
}

if ($action === 'delete' && $id) {
    requireRol(ROL_ADMIN);
    deleteBitacora($id);
    auditLog('ELIMINAR_BITACORA','bitacora',$id);
    flash('warning', 'Incidente eliminado del registro.');
    redirect('bitacora.php');
}

$pageTitle = 'Bitácora de Incidentes';
include 'includes/header.php';

$equiposList = getEquipos();
$q           = trim($_GET['q'] ?? '');
$tipoFil     = $_GET['tipo']     ?? '';
$gravedadFil = $_GET['gravedad'] ?? '';
$estadoFil   = $_GET['estado']   ?? '';

// Catálogos
$TIPOS = [
    'CORTE_LUZ'           => ['⚡ Corte de Luz / Energía',       'bg-warning text-dark', 'bi-lightning-charge-fill'],
    'FALLA_RED'           => ['🌐 Falla de Red / Conectividad',   'bg-info text-dark',    'bi-wifi-off'],
    'FALLA_SERVIDOR'      => ['🖥 Falla de Servidor',             'bg-danger',            'bi-server'],
    'FALLA_CAMARA'        => ['📷 Falla de Cámara / Grabación',   'bg-secondary',         'bi-camera-video-off'],
    'DAÑO_EQUIPO'         => ['🔧 Daño en Equipo',                'bg-orange text-white', 'bi-tools'],
    'INTRUSION'           => ['🔒 Intrusión / Acceso No Autorizado','bg-danger',           'bi-shield-exclamation'],
    'DESASTRE_NATURAL'    => ['🌩 Desastre Natural / Fenómeno',   'bg-primary',           'bi-cloud-lightning-rain'],
    'FALLA_SITE'          => ['🏢 Falla en SITE / Infraestructura','bg-danger',            'bi-building-x'],
    'VANDALISMO'          => ['⚠ Vandalismo / Sabotaje',          'bg-danger',            'bi-exclamation-triangle'],
    'ERROR_PERSONAL'      => ['👤 Error de Personal',             'bg-secondary',         'bi-person-x'],
    'MANTENIMIENTO_EXTERNO'=>['🔩 Mantenimiento Externo',         'bg-info text-dark',    'bi-wrench'],
    'OTRO'                => ['📋 Otro',                          'bg-secondary',         'bi-question-circle'],
];
$GRAVEDADES = [
    'INFORMATIVO' => ['bg-secondary',           '💬 Informativo'],
    'MENOR'       => ['bg-success',             '🟢 Menor'],
    'MODERADO'    => ['bg-warning text-dark',   '🟡 Moderado'],
    'GRAVE'       => ['bg-orange text-white',   '🟠 Grave'],
    'CRITICO'     => ['bg-danger',              '🔴 Crítico'],
];
$ESTADOS = [
    'ABIERTO'        => ['badge-abierto',     '🔴 Abierto'],
    'EN_SEGUIMIENTO' => ['badge-en_proceso',  '🟡 En Seguimiento'],
    'RESUELTO'       => ['badge-resuelto',    '🟢 Resuelto'],
    'CERRADO'        => ['badge-cancelado',   '⚫ Cerrado'],
];
?>
<style>
.inc-timeline { position:relative; padding-left:32px; }
.inc-timeline::before { content:''; position:absolute; left:10px; top:0; bottom:0; width:2px; background:#dee2e6; }
.inc-dot { position:absolute; left:-22px; width:20px; height:20px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.65rem; border:2px solid #fff; box-shadow:0 0 0 2px #dee2e6; }
.inc-card { border-left:4px solid #dee2e6; border-radius:0 8px 8px 0; background:#fff; padding:14px 16px; margin-bottom:12px; box-shadow:0 1px 4px rgba(0,0,0,.07); transition:.15s; }
.inc-card:hover { box-shadow:0 3px 12px rgba(0,0,0,.12); transform:translateX(2px); }
.inc-card.critico  { border-left-color:#dc3545; }
.inc-card.grave    { border-left-color:#fd7e14; }
.inc-card.moderado { border-left-color:#ffc107; }
.inc-card.menor    { border-left-color:#198754; }
.inc-card.informativo { border-left-color:#6c757d; }
.impact-badge { font-size:.68rem; padding:2px 7px; border-radius:3px; margin:1px; display:inline-block; }
.impact-yes { background:#fdecea; color:#c0392b; border:1px solid #f5c6cb; }
.impact-no  { background:#f0f0f0; color:#888;    border:1px solid #ddd; }
.bg-orange  { background:#fd7e14 !important; }
.form-section { border-left:3px solid #1a3a5c; padding-left:12px; margin-bottom:20px; }
.form-section-title { font-size:.78rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; color:#1a3a5c; margin-bottom:10px; }
@media print {
  .no-print,.btn,.navbar,.app-footer { display:none!important; }
  .inc-card { box-shadow:none!important; border:1px solid #ccc; }
}
</style>

<div class="container-fluid px-3 py-3">

<?php if ($action === 'list'): ?>
<!-- ══ LISTA ══ -->
<?php
$incidentes = getBitacora($q, $tipoFil, $gravedadFil, $estadoFil);
$stats      = getEstadisticasBitacora();
?>

<!-- KPIs -->
<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="stat-card bg-ti-blue h-100">
      <div class="stat-val"><?= $stats['total'] ?></div>
      <div class="stat-lbl">Total Incidentes</div>
      <i class="bi bi-journal-text stat-icon"></i>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card bg-ti-red h-100">
      <div class="stat-val"><?= $stats['abiertos'] ?></div>
      <div class="stat-lbl">Activos / En Seguimiento</div>
      <i class="bi bi-exclamation-circle stat-icon"></i>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card bg-ti-orange h-100">
      <div class="stat-val"><?= $stats['criticos'] ?></div>
      <div class="stat-lbl">Críticos / Graves</div>
      <i class="bi bi-shield-exclamation stat-icon"></i>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card bg-ti-green h-100">
      <div class="stat-val"><?= $stats['mes_actual'] ?></div>
      <div class="stat-lbl">Este Mes</div>
      <i class="bi bi-calendar-event stat-icon"></i>
    </div>
  </div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Bitácora de Incidentes</h5>
  <div class="d-flex gap-2 flex-wrap no-print">
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-printer me-1"></i>Imprimir
    </button>
    <a href="exports/export_bitacora_xls.php?q=<?=urlencode($q)?>&tipo=<?=urlencode($tipoFil)?>"
       class="btn btn-sm btn-outline-success">
      <i class="bi bi-file-earmark-excel me-1"></i>Excel
    </a>
    <a href="bitacora.php?action=new" class="btn btn-sm" style="background:#1a3a5c;color:#fff;">
      <i class="bi bi-plus me-1"></i>Registrar Incidente
    </a>
  </div>
</div>

<!-- Filtros -->
<div class="card mb-3 no-print"><div class="card-body py-2">
  <form id="form-search" method="GET" action="bitacora.php" class="row g-2 align-items-end">
    <div class="col-sm-3">
      <input type="search" name="q" id="input-search" class="form-control form-control-sm"
             placeholder="Folio, título, responsable…" value="<?=h($q)?>">
    </div>
    <div class="col-sm-2">
      <select name="tipo" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— Tipo —</option>
        <?php foreach ($TIPOS as $k=>[$lbl]): ?>
        <option value="<?=$k?>" <?=$tipoFil===$k?'selected':''?>><?=h($lbl)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2">
      <select name="gravedad" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— Gravedad —</option>
        <?php foreach ($GRAVEDADES as $k=>[$cls,$lbl]): ?>
        <option value="<?=$k?>" <?=$gravedadFil===$k?'selected':''?>><?=h($lbl)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2">
      <select name="estado" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">— Estado —</option>
        <?php foreach ($ESTADOS as $k=>[$cls,$lbl]): ?>
        <option value="<?=$k?>" <?=$estadoFil===$k?'selected':''?>><?=h($lbl)?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
      <?php if($q||$tipoFil||$gravedadFil||$estadoFil): ?>
      <a href="bitacora.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i></a>
      <?php endif; ?>
    </div>
  </form>
</div></div>

<!-- Timeline de incidentes -->
<?php if (empty($incidentes)): ?>
<div class="card"><div class="card-body text-center py-5 text-muted">
  <i class="bi bi-journal-check fs-1 d-block mb-2 opacity-25"></i>
  <h6>Sin incidentes registrados</h6>
  <a href="bitacora.php?action=new" class="btn btn-sm mt-2" style="background:#1a3a5c;color:#fff;">
    <i class="bi bi-plus me-1"></i>Registrar primer incidente
  </a>
</div></div>
<?php else: ?>
<div class="inc-timeline">
<?php foreach ($incidentes as $inc):
  $gravLower = strtolower($inc['gravedad']);
  $gravInfo  = $GRAVEDADES[$inc['gravedad']] ?? ['bg-secondary','—'];
  $tipoInfo  = $TIPOS[$inc['tipo']]          ?? ['bg-secondary bi-question-circle','—','bi-question-circle'];
  $estInfo   = $ESTADOS[$inc['estado']]      ?? ['bg-secondary','—'];
  $dotColor  = ['CRITICO'=>'#dc3545','GRAVE'=>'#fd7e14','MODERADO'=>'#ffc107','MENOR'=>'#198754','INFORMATIVO'=>'#6c757d'][$inc['gravedad']]??'#6c757d';
?>
<div class="position-relative">
  <div class="inc-dot" style="background:<?=$dotColor?>;top:18px;">
    <i class="bi <?=$tipoInfo[2]?> text-white" style="font-size:.55rem;"></i>
  </div>
  <div class="inc-card <?=$gravLower?>" style="cursor:pointer"
       onclick="location.href='bitacora.php?action=view&id=<?=$inc['id']?>'">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
      <div>
        <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
          <code class="text-muted small"><?=h($inc['folio'])?></code>
          <span class="badge <?=$gravInfo[0]?>" style="font-size:.68rem;"><?=h($inc['gravedad'])?></span>
          <span class="badge <?=$estInfo[0]?>" style="font-size:.68rem;"><?=h(str_replace('_',' ',$inc['estado']))?></span>
          <span class="badge bg-light text-dark border" style="font-size:.65rem;"><?=h(str_replace('_',' ',$inc['tipo']))?></span>
        </div>
        <h6 class="mb-1"><?=h($inc['titulo'])?></h6>
        <p class="text-muted small mb-1"><?=h(mb_substr($inc['descripcion'],0,120)).(mb_strlen($inc['descripcion'])>120?'…':'')?></p>
      </div>
      <div class="text-end text-muted small flex-shrink-0">
        <div><i class="bi bi-calendar me-1"></i><?=h(substr($inc['fecha_inicio'],0,16))?></div>
        <?php if($inc['duracion_minutos']): ?>
        <div><i class="bi bi-clock me-1"></i><?=h($inc['duracion_minutos'])?> min</div>
        <?php endif; ?>
        <?php if($inc['tecnico_atiende']): ?>
        <div><i class="bi bi-person-gear me-1"></i><?=h(mb_substr($inc['tecnico_atiende'],0,25))?></div>
        <?php endif; ?>
      </div>
    </div>
    <!-- Indicadores de impacto -->
    <div class="mt-1">
      <?php
      $impactos = [
        [$inc['afecta_site'],    'SITE/Servidores',  'bi-server'],
        [$inc['afecta_camaras'], 'Cámaras/Grabación','bi-camera-video'],
        [$inc['afecta_red'],     'Red/Conectividad', 'bi-wifi'],
        [$inc['sin_grabacion'],  'Sin Grabación',    'bi-camera-video-off'],
        [$inc['perdida_datos'],  'Riesgo de Datos',  'bi-database-exclamation'],
      ];
      foreach ($impactos as [$val,$lbl,$ico]):
        if ($val): ?>
      <span class="impact-badge impact-yes"><i class="bi <?=$ico?> me-1"></i><?=h($lbl)?></span>
      <?php endif; endforeach; ?>
    </div>
    <!-- Acciones -->
    <div class="mt-2 d-flex gap-2 no-print" onclick="event.stopPropagation()">
      <a href="bitacora.php?action=view&id=<?=$inc['id']?>" class="btn btn-xs btn-outline-primary">
        <i class="bi bi-eye me-1"></i>Ver detalle
      </a>
      <?php if (puede('editar_ticket')||isAdmin()||isSuper()): ?>
      <a href="bitacora.php?action=edit&id=<?=$inc['id']?>" class="btn btn-xs btn-outline-secondary">
        <i class="bi bi-pencil me-1"></i>Editar
      </a>
      <?php endif; ?>
      <?php if (puede('eliminar_ticket')||isAdmin()): ?>
      <button onclick="confirmDelete('bitacora.php?action=delete&id=<?=$inc['id']?>','<?=addslashes($inc['titulo'])?>')"
              class="btn btn-xs btn-outline-danger">
        <i class="bi bi-trash"></i>
      </button>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<div class="text-muted small mt-2 px-1"><?=count($incidentes)?> incidente(s)</div>
<?php endif; ?>


<?php elseif (in_array($action,['new','edit'])): ?>
<!-- ══ FORM ══ -->
<?php
$inc    = $action==='edit' && $id ? getBitacoraById($id) : [];
$isEdit = !empty($inc);
$eqAfec = $isEdit ? getEquiposAfectados($id) : [];
?>
<div class="d-flex align-items-center gap-3 mb-3">
  <h5 class="mb-0">
    <i class="bi bi-journal-plus me-2"></i>
    <?=$isEdit?'Editar Incidente — '.h($inc['folio']):'Registrar Nuevo Incidente'?>
  </h5>
</div>

<form method="POST" action="bitacora.php">
<?php if($isEdit): ?><input type="hidden" name="id" value="<?=$inc['id']?>"><?php endif; ?>
<div class="row g-3">

  <!-- Col izquierda: datos principales -->
  <div class="col-md-8">

    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-info-circle me-2"></i>Información General</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Título del Incidente <span class="text-danger">*</span></label>
            <input type="text" name="titulo" class="form-control form-control-lg"
                   value="<?=h($inc['titulo']??'')?>" required
                   placeholder="Ej: Corte de luz general — Edificio principal">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Tipo de Incidente *</label>
            <select name="tipo" class="form-select" required>
              <?php foreach($TIPOS as $k=>[$lbl]): ?>
              <option value="<?=$k?>" <?=($inc['tipo']??'OTRO')===$k?'selected':''?>><?=h($lbl)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Gravedad *</label>
            <select name="gravedad" class="form-select" required id="sel-gravedad">
              <?php foreach($GRAVEDADES as $k=>[$cls,$lbl]): ?>
              <option value="<?=$k?>" <?=($inc['gravedad']??'MENOR')===$k?'selected':''?>><?=h($lbl)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Estado</label>
            <select name="estado" class="form-select">
              <?php foreach($ESTADOS as $k=>[$cls,$lbl]): ?>
              <option value="<?=$k?>" <?=($inc['estado']??'ABIERTO')===$k?'selected':''?>><?=h($lbl)?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Fecha y Hora de Inicio *</label>
            <input type="datetime-local" name="fecha_inicio" class="form-control"
                   value="<?=h(str_replace(' ','T',substr($inc['fecha_inicio']??date('Y-m-d H:i:s'),0,16)))?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Fecha y Hora de Fin</label>
            <input type="datetime-local" name="fecha_fin" class="form-control"
                   value="<?=h($inc['fecha_fin']?str_replace(' ','T',substr($inc['fecha_fin'],0,16)):'')?>">
            <div class="form-text">Dejar vacío si el incidente sigue activo</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-card-text me-2"></i>Descripción Detallada</div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Descripción del Incidente *</label>
            <textarea name="descripcion" class="form-control" rows="3" required
                      placeholder="Describe qué ocurrió, cuándo, cómo se detectó…"><?=h($inc['descripcion']??'')?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Causa Probable</label>
            <textarea name="causa_probable" class="form-control" rows="2"
                      placeholder="Ej: Falla en suministro de CFE por mantenimiento en la colonia…"><?=h($inc['causa_probable']??'')?></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Acciones Tomadas</label>
            <textarea name="acciones_tomadas" class="form-control" rows="3"
                      placeholder="Qué se hizo para resolver el incidente…"><?=h($inc['acciones_tomadas']??'')?></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Recomendaciones</label>
            <textarea name="recomendaciones" class="form-control" rows="3"
                      placeholder="Para evitar que vuelva a ocurrir…"><?=h($inc['recomendaciones']??'')?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notas Adicionales</label>
            <textarea name="notas_adicionales" class="form-control" rows="2"><?=h($inc['notas_adicionales']??'')?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- Equipos afectados -->
    <div class="card">
      <div class="card-header-ti d-flex justify-content-between align-items-center">
        <span><i class="bi bi-pc-display me-2"></i>Equipos Afectados</span>
        <button type="button" class="btn btn-sm btn-outline-light py-0" id="btn-add-eq">
          <i class="bi bi-plus me-1"></i>Agregar Equipo
        </button>
      </div>
      <div class="card-body">
        <div id="eq-container">
        <?php
        $existentes = $eqAfec ?: [['equipo_id'=>'','daño_nivel'=>'SIN_DAÑO','descripcion'=>'']];
        foreach ($existentes as $idx => $ea): ?>
        <div class="row g-2 align-items-center mb-2 eq-row">
          <div class="col-md-5">
            <select name="eq_id[]" class="form-select form-select-sm">
              <option value="">— Seleccionar equipo —</option>
              <?php foreach($equiposList as $e): ?>
              <option value="<?=$e['id']?>" <?=(int)($ea['equipo_id']??0)==(int)$e['id']?'selected':''?>>
                <?=h($e['folio'].' — '.$e['marca'].' '.($e['modelo']??'').' ['.$e['ip'].']')?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <select name="eq_daño[]" class="form-select form-select-sm">
              <?php foreach(['SIN_DAÑO'=>'Sin daño','DAÑO_MENOR'=>'Daño menor','DAÑO_MAYOR'=>'Daño mayor','INOPERANTE'=>'Inoperante','REEMPLAZADO'=>'Reemplazado'] as $dv=>$dl): ?>
              <option value="<?=$dv?>" <?=($ea['daño_nivel']??'')===$dv?'selected':''?>><?=$dl?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <input type="text" name="eq_desc[]" class="form-control form-control-sm"
                   placeholder="Detalle del daño…" value="<?=h($ea['descripcion']??'')?>">
          </div>
          <div class="col-md-1">
            <button type="button" class="btn btn-xs btn-outline-danger btn-rm-eq"><i class="bi bi-trash"></i></button>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Col derecha: impacto y responsables -->
  <div class="col-md-4">

    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-exclamation-triangle me-2"></i>Impacto del Incidente</div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Sistemas Afectados</label>
          <textarea name="sistemas_afectados" class="form-control form-control-sm" rows="2"
                    placeholder="Ej: Servidor principal, NVR cámaras, Red switches…"><?=h($inc['sistemas_afectados']??'')?></textarea>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">Equipos Dañados (texto libre)</label>
          <textarea name="equipos_dañados" class="form-control form-control-sm" rows="2"
                    placeholder="Ej: PC-042 monitor quemado, Switch SITE…"><?=h($inc['equipos_dañados']??'')?></textarea>
        </div>
        <!-- Checkboxes de impacto -->
        <label class="form-label fw-semibold">Áreas de Impacto</label>
        <div class="d-flex flex-column gap-2">
          <?php
          $checks = [
            ['afecta_site',      'bi-server',              'Afectó SITE / Cuarto de servidores'],
            ['afecta_camaras',   'bi-camera-video',        'Afectó cámaras de vigilancia'],
            ['sin_grabacion',    'bi-camera-video-off',    'Sin grabación de video'],
            ['afecta_red',       'bi-wifi-off',            'Afectó red / conectividad'],
            ['afecta_servidores','bi-hdd-network',         'Afectó servidores'],
            ['perdida_datos',    'bi-database-exclamation','Riesgo / pérdida de datos'],
          ];
          foreach ($checks as [$name,$ico,$label]): ?>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch"
                   name="<?=$name?>" value="1" id="chk-<?=$name?>"
                   <?=!empty($inc[$name])&&$inc[$name]?'checked':''?>>
            <label class="form-check-label small" for="chk-<?=$name?>">
              <i class="bi <?=$ico?> me-1 text-muted"></i><?=$label?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="mt-2" id="row-sin-grabacion" style="<?=empty($inc['sin_grabacion'])?'display:none':''?>">
          <label class="form-label small fw-semibold">Tiempo sin grabación</label>
          <input type="text" name="tiempo_sin_grabacion" class="form-control form-control-sm"
                 value="<?=h($inc['tiempo_sin_grabacion']??'')?>"
                 placeholder="Ej: 2 horas — 14:00 a 16:00">
        </div>
        <div class="mt-2">
          <label class="form-label small fw-semibold">Evidencia fotográfica</label>
          <div class="d-flex gap-3 align-items-center">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" name="evidencia_fotos" value="1"
                     <?=!empty($inc['evidencia_fotos'])?'checked':''?>>
              <label class="form-check-label small">Hay fotos / evidencia</label>
            </div>
            <input type="number" name="num_fotos" class="form-control form-control-sm" min="0" max="99"
                   value="<?=h($inc['num_fotos']??0)?>" style="max-width:70px;" placeholder="# fotos">
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header-ti"><i class="bi bi-people me-2"></i>Responsables</div>
      <div class="card-body">
        <div class="mb-2">
          <label class="form-label small fw-semibold">Reportado por</label>
          <input type="text" name="reportado_por" class="form-control form-control-sm"
                 value="<?=h($inc['reportado_por']??'')?>"
                 placeholder="Nombre de quien reportó">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold">Técnico que atiende</label>
          <input type="text" name="tecnico_atiende" class="form-control form-control-sm"
                 value="<?=h($inc['tecnico_atiende']??'')?>"
                 placeholder="Técnico TI responsable">
        </div>
        <div class="mb-2">
          <label class="form-label small fw-semibold">Personal involucrado / responsable</label>
          <textarea name="personal_responsable" class="form-control form-control-sm" rows="2"
                    placeholder="Personal interno o externo relacionado con el incidente…"><?=h($inc['personal_responsable']??'')?></textarea>
        </div>
        <div class="mb-0">
          <label class="form-label small fw-semibold">Tipo de personal</label>
          <select name="tipo_personal" class="form-select form-select-sm">
            <option value="INTERNO"     <?=($inc['tipo_personal']??'INTERNO')==='INTERNO'?'selected':''?>>👤 Personal Interno</option>
            <option value="EXTERNO"     <?=($inc['tipo_personal']??'')==='EXTERNO'?'selected':''?>>🏢 Personal Externo</option>
            <option value="DESCONOCIDO" <?=($inc['tipo_personal']??'')==='DESCONOCIDO'?'selected':''?>>❓ Desconocido</option>
          </select>
        </div>
      </div>
    </div>
  </div>

</div><!-- /row -->

<div class="d-flex gap-2 mt-3">
  <button type="submit" class="btn btn-lg" style="background:#1a3a5c;color:#fff;">
    <i class="bi bi-check-lg me-1"></i><?=$isEdit?'Guardar Cambios':'Registrar Incidente'?>
  </button>
  <a href="bitacora.php" class="btn btn-lg btn-outline-secondary">Cancelar</a>
</div>
</form>

<script>
// Toggle tiempo sin grabación
document.getElementById('chk-sin_grabacion')?.addEventListener('change', function(){
  document.getElementById('row-sin-grabacion').style.display = this.checked ? '' : 'none';
});
// Agregar fila de equipo afectado
const eqTpl = `<?php
$tplOpts = '<option value="">— Seleccionar equipo —</option>';
foreach ($equiposList as $e) {
    $tplOpts .= '<option value="'.h($e['id']).'">'.h($e['folio'].' — '.$e['marca'].' '.($e['modelo']??'').' ['.$e['ip'].']').'</option>';
}
$danoOpts = '';
foreach (['SIN_DAÑO'=>'Sin daño','DAÑO_MENOR'=>'Daño menor','DAÑO_MAYOR'=>'Daño mayor','INOPERANTE'=>'Inoperante','REEMPLAZADO'=>'Reemplazado'] as $dv=>$dl) {
    $danoOpts .= '<option value="'.$dv.'">'.$dl.'</option>';
}
echo addslashes('<div class="row g-2 align-items-center mb-2 eq-row"><div class="col-md-5"><select name="eq_id[]" class="form-select form-select-sm">'.$tplOpts.'</select></div><div class="col-md-3"><select name="eq_daño[]" class="form-select form-select-sm">'.$danoOpts.'</select></div><div class="col-md-3"><input type="text" name="eq_desc[]" class="form-control form-control-sm" placeholder="Detalle del daño…"></div><div class="col-md-1"><button type="button" class="btn btn-xs btn-outline-danger btn-rm-eq"><i class="bi bi-trash"></i></button></div></div>');
?>`;
document.getElementById('btn-add-eq').addEventListener('click', () => {
  const div = document.createElement('div');
  div.innerHTML = eqTpl;
  document.getElementById('eq-container').appendChild(div.firstChild);
  bindRemove();
});
function bindRemove() {
  document.querySelectorAll('.btn-rm-eq').forEach(btn => {
    btn.onclick = () => btn.closest('.eq-row').remove();
  });
}
bindRemove();
</script>


<?php elseif ($action==='view' && $id): ?>
<!-- ══ DETALLE ══ -->
<?php
$inc    = getBitacoraById($id);
if (!$inc){ flash('danger','Incidente no encontrado.'); redirect('bitacora.php'); }
$eqAfec = getEquiposAfectados($id);
$gravInfo = $GRAVEDADES[$inc['gravedad']] ?? ['bg-secondary','—'];
$tipoInfo = $TIPOS[$inc['tipo']]          ?? ['—','bg-secondary','bi-question-circle'];
$estInfo  = $ESTADOS[$inc['estado']]      ?? ['bg-secondary','—'];
?>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-3 p-3 rounded"
     style="background:linear-gradient(135deg,#1a3a5c,#2563a8);color:#fff;">
  <div>
    <div class="d-flex gap-2 align-items-center flex-wrap mb-1">
      <code style="color:#e8ff47;font-size:.9rem;"><?=h($inc['folio'])?></code>
      <span class="badge <?=$gravInfo[0]?>"><?=h($inc['gravedad'])?></span>
      <span class="badge <?=$estInfo[0]?>"><?=h(str_replace('_',' ',$inc['estado']))?></span>
      <span class="badge bg-light text-dark"><?=h(str_replace('_',' ',$inc['tipo']))?></span>
    </div>
    <h4 class="mb-1"><?=h($inc['titulo'])?></h4>
    <div class="small opacity-75">
      <i class="bi bi-calendar me-1"></i><?=h(substr($inc['fecha_inicio'],0,16))?> &nbsp;
      <?php if($inc['fecha_fin']): ?><i class="bi bi-calendar-check me-1"></i><?=h(substr($inc['fecha_fin'],0,16))?><?php endif; ?>
      <?php if($inc['duracion_minutos']): ?>&nbsp;· <i class="bi bi-clock me-1"></i><?=h($inc['duracion_minutos'])?> min<?php endif; ?>
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <a href="bitacora.php?action=edit&id=<?=$id?>" class="btn btn-sm btn-light">
      <i class="bi bi-pencil me-1"></i>Editar
    </a>
    <a href="exports/export_bitacora_pdf.php?id=<?=$id?>" target="_blank" class="btn btn-sm btn-outline-light">
      <i class="bi bi-file-earmark-pdf me-1"></i>PDF
    </a>
    <button onclick="window.print()" class="btn btn-sm btn-outline-light">
      <i class="bi bi-printer me-1"></i>Imprimir
    </button>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-8">
    <!-- Descripción -->
    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-card-text me-2"></i>Descripción del Incidente</div>
      <div class="card-body">
        <?php if($inc['descripcion']): ?>
        <div class="mb-3"><strong class="small text-muted d-block mb-1">DESCRIPCIÓN</strong><?=nl2br(h($inc['descripcion']))?></div>
        <?php endif; ?>
        <?php if($inc['causa_probable']): ?>
        <div class="mb-3"><strong class="small text-muted d-block mb-1">CAUSA PROBABLE</strong><?=nl2br(h($inc['causa_probable']))?></div>
        <?php endif; ?>
        <?php if($inc['acciones_tomadas']): ?>
        <div class="mb-3"><strong class="small text-muted d-block mb-1">ACCIONES TOMADAS</strong><?=nl2br(h($inc['acciones_tomadas']))?></div>
        <?php endif; ?>
        <?php if($inc['recomendaciones']): ?>
        <div class="mb-0"><strong class="small text-muted d-block mb-1">RECOMENDACIONES</strong><?=nl2br(h($inc['recomendaciones']))?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Equipos afectados -->
    <?php if (!empty($eqAfec)): ?>
    <div class="card">
      <div class="card-header-ti"><i class="bi bi-pc-display me-2"></i>Equipos Afectados (<?=count($eqAfec)?>)</div>
      <div class="card-body p-0">
        <table class="table table-ti mb-0">
          <thead><tr><th>Equipo</th><th>Tipo</th><th>Nivel de Daño</th><th>Detalle</th></tr></thead>
          <tbody>
          <?php foreach($eqAfec as $ea):
            $danoColor=['SIN_DAÑO'=>'bg-success','DAÑO_MENOR'=>'bg-warning text-dark','DAÑO_MAYOR'=>'bg-orange text-white','INOPERANTE'=>'bg-danger','REEMPLAZADO'=>'bg-secondary'][$ea['daño_nivel']]??'bg-secondary';
          ?>
          <tr>
            <td><code class="text-primary small"><?=h($ea['folio'])?></code><br><span class="text-muted small"><?=h($ea['marca'].' '.($ea['modelo']??''))?></span></td>
            <td class="small"><?=h($ea['tipo_nombre']??'')?></td>
            <td><span class="badge <?=$danoColor?>"><?=h(str_replace('_',' ',$ea['daño_nivel']))?></span></td>
            <td class="small text-muted"><?=h($ea['descripcion']??'')?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-md-4">
    <!-- Impacto -->
    <div class="card mb-3">
      <div class="card-header-ti"><i class="bi bi-exclamation-triangle me-2"></i>Impacto</div>
      <div class="card-body">
        <?php
        $impactos=[
          [$inc['afecta_site'],    'SITE / Cuarto de Servidores','bi-server'],
          [$inc['afecta_camaras'], 'Cámaras de Vigilancia',      'bi-camera-video'],
          [$inc['sin_grabacion'],  'Sin Grabación de Video',      'bi-camera-video-off'],
          [$inc['afecta_red'],     'Red / Conectividad',          'bi-wifi-off'],
          [$inc['afecta_servidores'],'Servidores',                'bi-hdd-network'],
          [$inc['perdida_datos'],  'Riesgo/Pérdida de Datos',     'bi-database-exclamation'],
        ];
        foreach($impactos as [$val,$lbl,$ico]):
        ?>
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi <?=$ico?> <?=$val?'text-danger':'text-muted'?>"></i>
          <span class="small <?=$val?'fw-semibold text-danger':'text-muted'?>"><?=$lbl?></span>
          <span class="ms-auto badge <?=$val?'bg-danger':'bg-light text-muted border'?>"><?=$val?'SÍ':'NO'?></span>
        </div>
        <?php endforeach; ?>
        <?php if($inc['tiempo_sin_grabacion']): ?>
        <div class="alert alert-warning py-1 px-2 small mt-2 mb-0">
          <i class="bi bi-camera-video-off me-1"></i>Sin grabación: <strong><?=h($inc['tiempo_sin_grabacion'])?></strong>
        </div>
        <?php endif; ?>
        <?php if($inc['sistemas_afectados']): ?>
        <hr><div class="small"><strong>Sistemas afectados:</strong><br><?=nl2br(h($inc['sistemas_afectados']))?></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Responsables -->
    <div class="card">
      <div class="card-header-ti"><i class="bi bi-people me-2"></i>Responsables</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <?php $rows=[
            ['Reportado por',h($inc['reportado_por']??'')],
            ['Técnico TI',h($inc['tecnico_atiende']??'')],
            ['Personal involucrado',h($inc['personal_responsable']??'')],
            ['Tipo de personal',h(str_replace('_',' ',$inc['tipo_personal']??''))],
            ['Registrado por',h($inc['registrado_por_nombre']??'')],
            ['Evidencia fotos',$inc['evidencia_fotos']?'Sí ('.$inc['num_fotos'].' foto(s))':'No'],
          ];
          foreach($rows as [$k,$v]): if(!$v||$v==='0') continue; ?>
          <tr><th class="small text-muted ps-3 py-2" style="width:45%"><?=$k?></th><td class="small py-2 pe-3"><?=$v?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>

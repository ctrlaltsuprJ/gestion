<!-- Botones de Acción (Imprimir y PDF) -->
<div class="d-flex justify-content-end mb-3 gap-2 action-buttons">
  <button class="btn btn-outline-secondary btn-sm" onclick="imprimirSeccion()">
    <i class="bi bi-printer me-1"></i> Imprimir
  </button>
  <button class="btn btn-danger btn-sm" onclick="exportarPDF()">
    <i class="bi bi-file-earmark-pdf me-1"></i> Exportar a PDF
  </button>
</div>

<!-- Contenedor que se va a imprimir/exportar -->
<div id="area-impresion">
  <!-- Fila 2: Tickets por mes + Mantenimientos por mes -->
  <div class="row g-3 mb-3">
    <div class="col-md-7">
      <div class="card h-100">
        <div class="card-header-ti"><i class="bi bi-graph-up me-2"></i>Tickets por Mes (últimos 6 meses)</div>
        <div class="card-body"><canvas id="chartTicketsMes" height="200"></canvas></div>
      </div>
    </div>
    <div class="col-md-5">
      <div class="card h-100">
        <div class="card-header-ti"><i class="bi bi-clipboard-check me-2"></i>Mantenimientos por Mes</div>
        <div class="card-body">
          <canvas id="chartMantMes" height="200"></canvas>

          <div class="mt-3 pt-2 border-top">
            <h6 class="mb-2">Resumen (últimos 6 meses)</h6>
            <div class="row g-2 mb-2">
              <div class="col-12 col-sm-4">
                <div class="small text-muted">Total mantenimientos</div>
                <div class="fw-bold"><?= $totalMantenimientosMes ?></div>
              </div>
              <div class="col-6 col-sm-4">
                <div class="small text-muted">Preventivos</div>
                <span class="badge bg-info text-dark"><?= $totalPreventivosMes ?></span>
              </div>
              <div class="col-6 col-sm-4">
                <div class="small text-muted">Correctivos</div>
                <span class="badge bg-warning text-dark"><?= $totalCorrectivosMes ?></span>
              </div>
            </div>

            <div class="small text-muted mb-1">Desglose mensual</div>
            <div class="d-flex flex-wrap gap-2 mb-2">
              <?php foreach ($resumenMantMes as $r): ?>
                <span class="badge bg-light text-dark border">
                  <?= h($r['mes']) ?>: <?= $r['total'] ?>
                </span>
              <?php endforeach; ?>
              <?php if (empty($resumenMantMes)): ?>
                <span class="text-muted small">Sin mantenimientos en el periodo</span>
              <?php endif; ?>
            </div>

            <div class="small text-muted mb-1">Por tipo de equipo</div>
            <div class="d-flex flex-wrap gap-2">
              <?php foreach ($mantsPorTipoEquipo as $r): ?>
                <span class="badge bg-primary-subtle text-primary-emphasis border">
                  <?= h($r['tipo_equipo']) ?>: <?= (int)$r['total'] ?>
                </span>
              <?php endforeach; ?>
              <?php if (empty($mantsPorTipoEquipo)): ?>
                <span class="text-muted small">Sin datos de tipo de equipo</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>


<!-- Librería externa para generar el PDF (html2pdf.js) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<script>
// 1. Función para Imprimir (Usa el asistente de impresión del navegador)
function imprimirSeccion() {
  const elemento = document.getElementById('area-impresion');
  
  // Clonamos temporalmente el contenido para no alterar la página real
  const ventanaImpresion = window.open('', '_blank');
  ventanaImpresion.document.write('<html><head><title>Reporte Mensual</title>');
  
  // Importamos Bootstrap y tus estilos para que la impresión se vea igual
  ventanaImpresion.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/styles.css">');
  ventanaImpresion.document.write('<style>.card { border: 1px solid #dee2e6; margin-bottom: 1rem; }</style>'); 
  ventanaImpresion.document.write('</head><body>');
  ventanaImpresion.document.write(elemento.innerHTML);
  ventanaImpresion.document.write('</body></html>');
  ventanaImpresion.document.close();
  
  // Esperamos un momento a que carguen los estilos antes de imprimir
  ventanaImpresion.setTimeout(function() {
    ventanaImpresion.print();
    ventanaImpresion.close();
  }, 500);
}

// 2. Función para Exportar a PDF (Convierte el HTML y Canvas a documento)
function exportarPDF() {
  const elemento = document.getElementById('area-impresion');
  
  // Configuración del PDF
  const opciones = {
    margin:       10,
    filename:     'Reporte_Tickets_Mantenimiento.pdf',
    image:        { type: 'jpeg', quality: 0.98 },
    html2canvas:  { scale: 2, useCORS: true }, // Escala 2 mejora la resolución de los gráficos
    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'landscape' } // Landscape (horizontal) va mejor para filas anchas
  };

  // Ejecutar la conversión
  html2pdf().set(opciones).from(elemento).save();
}
</script>
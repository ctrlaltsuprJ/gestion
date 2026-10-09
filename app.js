// ============================================================
//  app.js — Sistema de Gestión TI
//  SweetAlert2 para todas las interacciones de usuario
// ============================================================

// ── Paleta del sistema ────────────────────────────────────
const TI = {
  primary : '#1a3a5c',
  accent  : '#0d6efd',
  danger  : '#dc3545',
  warning : '#ffc107',
  success : '#198754',
  info    : '#0dcaf0',
};

// ── Configuración base de SweetAlert2 ────────────────────
const SwalBase = Swal.mixin({
  customClass: {
    confirmButton : 'btn btn-sm px-4',
    cancelButton  : 'btn btn-sm btn-outline-secondary px-4 ms-2',
    popup         : 'swal-ti-popup',
    title         : 'swal-ti-title',
  },
  buttonsStyling: false,
});

const SwalToast = Swal.mixin({
  toast            : true,
  position         : 'top-end',
  showConfirmButton: false,
  timer            : 3500,
  timerProgressBar : true,
  customClass      : { popup: 'swal-toast-ti' },
  didOpen(toast) {
    toast.addEventListener('mouseenter', Swal.stopTimer);
    toast.addEventListener('mouseleave', Swal.resumeTimer);
  },
});

// ── Eliminar (con confirmación de doble paso) ─────────────
function confirmDelete(url, name) {
  SwalBase.fire({
    icon             : 'warning',
    title            : '¿Eliminar registro?',
    html             : `<p class="mb-1">Estás por eliminar:</p><strong class="text-danger">${name}</strong><p class="text-muted small mt-2 mb-0">Esta acción <u>no se puede deshacer</u>.</p>`,
    showCancelButton : true,
    confirmButtonText: '<i class="bi bi-trash me-1"></i>Sí, eliminar',
    cancelButtonText : 'Cancelar',
    confirmButtonColor: TI.danger,
    focusCancel      : true,
    reverseButtons   : true,
  }).then(r => {
    if (r.isConfirmed) {
      // Spinner mientras redirige
      Swal.fire({
        title            : 'Eliminando…',
        allowOutsideClick: false,
        didOpen          : () => Swal.showLoading(),
      });
      window.location.href = url;
    }
  });
}

// ── Cerrar sesión ─────────────────────────────────────────
function confirmLogout() {
  SwalBase.fire({
    icon             : 'question',
    title            : '¿Cerrar sesión?',
    text             : 'Tu sesión actual se cerrará.',
    showCancelButton : true,
    confirmButtonText: '<i class="bi bi-box-arrow-right me-1"></i>Cerrar sesión',
    cancelButtonText : 'Cancelar',
    confirmButtonColor: TI.primary,
    reverseButtons   : true,
  }).then(r => {
    if (r.isConfirmed) window.location.href = 'logout.php';
  });
  return false;
}

// ── Aprobar cambio ────────────────────────────────────────
function confirmAprobar(formId) {
  SwalBase.fire({
    icon             : 'question',
    title            : '¿Aprobar y aplicar cambio?',
    html             : '<p class="text-muted small mb-0">El cambio se aplicará inmediatamente al sistema.</p>',
    input            : 'text',
    inputLabel       : 'Nota de aprobación (opcional)',
    inputPlaceholder : 'Ej: Revisado y conforme…',
    showCancelButton : true,
    confirmButtonText: '<i class="bi bi-check-lg me-1"></i>Aprobar',
    cancelButtonText : 'Cancelar',
    confirmButtonColor: TI.success,
    reverseButtons   : true,
  }).then(r => {
    if (r.isConfirmed) {
      const form = document.getElementById(formId);
      const nota = form.querySelector('[name="nota"]');
      if (nota) nota.value = r.value || '';
      Swal.fire({ title: 'Aplicando cambio…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
      form.submit();
    }
  });
}

// ── Rechazar cambio ───────────────────────────────────────
function confirmRechazar(formId) {
  SwalBase.fire({
    icon             : 'warning',
    title            : '¿Rechazar cambio?',
    html             : '<p class="text-muted small mb-0">El cambio <strong>no</strong> se aplicará.</p>',
    input            : 'textarea',
    inputLabel       : 'Motivo del rechazo (recomendado)',
    inputPlaceholder : 'Describe el motivo…',
    inputAttributes  : { rows: 3 },
    showCancelButton : true,
    confirmButtonText: '<i class="bi bi-x-lg me-1"></i>Rechazar',
    cancelButtonText : 'Cancelar',
    confirmButtonColor: TI.danger,
    reverseButtons   : true,
  }).then(r => {
    if (r.isConfirmed) {
      const form = document.getElementById(formId);
      const nota = form.querySelector('[name="nota"]');
      if (nota) nota.value = r.value || '';
      form.submit();
    }
  });
}

// ── Asignar ticket (desde botón en lista) ─────────────────
function swlInfo(title, msg) {
  SwalToast.fire({ icon: 'info', title: msg || title });
}
function swlSuccess(msg) {
  SwalToast.fire({ icon: 'success', title: msg });
}
function swlWarning(msg) {
  SwalToast.fire({ icon: 'warning', title: msg });
}
function swlError(msg) {
  SwalToast.fire({ icon: 'error', title: msg });
}

// ── Confirmar envío de formularios críticos ───────────────
function confirmForm(formId, title, text, btnText = 'Confirmar', icon = 'question') {
  SwalBase.fire({
    icon,
    title,
    html             : text ? `<p class="text-muted small mb-0">${text}</p>` : '',
    showCancelButton : true,
    confirmButtonText: btnText,
    cancelButtonText : 'Cancelar',
    confirmButtonColor: TI.primary,
    reverseButtons   : true,
  }).then(r => {
    if (r.isConfirmed) {
      Swal.fire({ title: 'Guardando…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
      document.getElementById(formId)?.submit();
    }
  });
}

// ── DOMContentLoaded ──────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {

  // Búsqueda con debounce
  const si = document.getElementById('input-search');
  if (si) {
    let t;
    si.addEventListener('input', () => {
      clearTimeout(t);
      t = setTimeout(() => document.getElementById('form-search')?.submit(), 450);
    });
  }

  // Auto-folio en formulario de equipo
  document.getElementById('btn-gen-folio')?.addEventListener('click', () => {
    fetch('api.php?action=next_folio_equipo')
      .then(r => r.json())
      .then(d => {
        document.getElementById('folio').value = d.folio;
        SwalToast.fire({ icon: 'success', title: 'Folio generado: ' + d.folio });
      })
      .catch(() => swlError('No se pudo generar el folio'));
  });

  // Checklist: marcar todos OK por categoría
  document.querySelectorAll('.btn-mark-all-ok').forEach(btn => {
    btn.addEventListener('click', () => {
      const cat = btn.dataset.cat;
      const sels = document.querySelectorAll(`.check-sel[data-cat="${cat}"]`);
      sels.forEach(s => s.value = 'OK');
      SwalToast.fire({ icon: 'success', title: `${sels.length} ítems marcados como OK` });
    });
  });

  document.getElementById('btn-mark-all-ok-global')?.addEventListener('click', () => {
    const all = document.querySelectorAll('.check-sel');
    all.forEach(s => s.value = 'OK');
    SwalToast.fire({ icon: 'success', title: `${all.length} ítems marcados como OK` });
  });

  document.getElementById('btn-mark-all-na')?.addEventListener('click', () => {
    const all = document.querySelectorAll('.check-sel');
    all.forEach(s => s.value = 'NO_APLICA');
    SwalToast.fire({ icon: 'info', title: `${all.length} ítems marcados como N/A` });
  });

  // Tooltips Bootstrap
  document.querySelectorAll('[data-bs-toggle="tooltip"]')
    .forEach(el => new bootstrap.Tooltip(el));

  // Filas clickables: feedback visual
  document.querySelectorAll('tr[onclick]').forEach(tr => tr.classList.add('clickable'));

  // Confirmar submit de formularios con data-confirm
  document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', e => {
      e.preventDefault();
      const msg   = form.dataset.confirm;
      const title = form.dataset.confirmTitle || '¿Confirmar acción?';
      const btn   = form.dataset.confirmBtn   || 'Confirmar';
      SwalBase.fire({
        icon             : 'question',
        title,
        html             : `<p class="text-muted small mb-0">${msg}</p>`,
        showCancelButton : true,
        confirmButtonText: btn,
        cancelButtonText : 'Cancelar',
        confirmButtonColor: TI.primary,
        reverseButtons   : true,
      }).then(r => { if (r.isConfirmed) form.submit(); });
    });
  });
});

// ── Badge de estado (helper) ──────────────────────────────
function estadoBadge(estado) {
  const map = {
    'ACTIVO':'badge-activo','INACTIVO':'badge-inactivo',
    'MANTENIMIENTO':'badge-mantenimiento','BAJA':'badge-baja',
    'ABIERTO':'badge-abierto','EN_PROCESO':'badge-en_proceso',
    'RESUELTO':'badge-resuelto','CANCELADO':'badge-cancelado',
    'BUENO':'badge-bueno','REGULAR':'badge-regular','MALO':'badge-malo',
    'URGENTE':'badge-urgente','ALTA':'badge-alta',
    'MEDIA':'badge-media','BAJA':'badge-baja-p',
  };
  return map[estado] || 'bg-secondary';
}

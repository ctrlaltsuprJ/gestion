<footer class="app-footer mt-auto py-2 text-center text-muted small">
  <?= APP_NAME ?> v<?= APP_VERSION ?> &mdash; <?= date('Y') ?>
</footer>

<script src="assets/bst/js/bootstrap.bundle.min.js"></script>
<script src="assets/sweetalert2@11"></script>
<script src="assets/js/app.js"></script>

<?php if (!empty($_SESSION['auth'])): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    // 15 minutos en milisegundos (15 * 60 * 1000)
    const INACTIVITY_LIMIT = 900000; 
    let inactivityTimer;

    const executeAutoLogout = () => {
        Swal.fire({
            icon: 'warning',
            title: 'Sesión Expirada',
            text: 'Tu sesión se cerrará automáticamente por motivos de seguridad.',
            showConfirmButton: false,
            timer: 10000,
            timerProgressBar: true,
            willClose: () => {
                window.location.href = 'logout.php?reason=inactivity';
            }
        });
        
        // Respaldo de contingencia por si el DOM congela SweetAlert
        setTimeout(() => {
            window.location.href = 'logout.php?reason=inactivity';
        }, 3200);
    };

    const resetInactivityTimer = () => {
        clearTimeout(inactivityTimer);
        inactivityTimer = setTimeout(executeAutoLogout, INACTIVITY_LIMIT);
    };

    // Escuchas reactivas de actividad real en la interfaz
    const activityEvents = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];
    
    activityEvents.forEach(eventName => {
        document.addEventListener(eventName, resetInactivityTimer, { passive: true });
    });

    // Inicializar el reloj de arena al cargar la página
    resetInactivityTimer();
});
</script>
<?php endif; ?>

<?php if (!empty($flash)): ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const map = {
    success : { icon: 'success', color: '#198754' },
    danger  : { icon: 'error',   color: '#dc3545' },
    warning : { icon: 'warning', color: '#ffc107' },
    info    : { icon: 'info',    color: '#0dcaf0' },
  };
  const t = map['<?= h($flash['type']) ?>'] ?? { icon: 'info', color: '#0dcaf0' };
  Swal.fire({
    toast            : true,
    position         : 'top-end',
    icon             : t.icon,
    title            : <?= json_encode($flash['msg']) ?>,
    showConfirmButton: false,
    timer            : 3800,
    timerProgressBar : true,
    customClass      : { popup: 'swal-toast-ti' },
    didOpen: (toast) => {
      toast.addEventListener('mouseenter', Swal.stopTimer);
      toast.addEventListener('mouseleave', Swal.resumeTimer);
    }
  });
});
</script>
<?php endif; ?>

</body>
</html>
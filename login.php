<?php
// login.php
require_once 'includes/db.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Ya logueado → Redirigir al dashboard y detener ejecución de inmediato
if (!empty($_SESSION['auth'])) {
    redirect('index.php');
    exit;
}

$error = '';
$showSupportAlert = false;
$loginFailCount = (int) ($_SESSION['login_fail_count'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';

  if (!$username || !$password) {
    $error = 'Ingresa usuario y contraseña.';
  } else {
    $auth = authLogin($username, $password);
    if ($auth) {
      $_SESSION['login_fail_count'] = 0;
      redirect('index.php');
      exit; // Asegurar detención tras login exitoso
    } else {
      $loginFailCount++;
      $_SESSION['login_fail_count'] = $loginFailCount;
      
      // Se cambia a >= 3 para evitar que se salten la advertencia en reintentos posteriores
      $showSupportAlert = ($loginFailCount >= 3); 
      $error = 'Usuario o contraseña incorrectos.';
      auditLog('LOGIN_FAILED', 'auth', null, "Intento fallido para: $username");
    }
  }
}

// URL base dinámica o controlada para evitar dependencias absolutas harcodeadas
$baseUrl = 'http://localhost/gestion/'; 
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Iniciar Sesión — Gestión DPI</title>
  <link href="assets/bst/css/bootstrap.min.css" rel="stylesheet">
  <link href="assets/bst/icons/font/bootstrap-icons.min.css" rel="stylesheet">
  <!--<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">-->
<link href="assets/font/family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="icon" type="image/png" href="<?= h($baseUrl) ?>assets/img/death.ico">
  <script src="assets/sweetalert2@11"></script>

  <style>
    * { font-family: 'Inter', sans-serif; }
    body {
      background: linear-gradient(135deg, #0f1e2d 0%, #1a3a5c 50%, #0d6efd22 100%);
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .login-wrap { width: 100%; max-width: 420px; }
    .login-card {
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 20px 60px rgba(0, 0, 0, .35);
      overflow: hidden;
    }
    .login-header {
      background: linear-gradient(135deg, #1a3a5c, #2563a8);
      padding: 32px 32px 24px;
      text-align: center;
      color: #fff;
    }
    .login-header .icon-wrap {
      width: 68px;
      height: 68px;
      background: rgba(255, 255, 255, .15);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 14px;
      font-size: 2rem;
      border: 2px solid rgba(255, 255, 255, .25);
    }
    .login-header h4 { margin: 0; font-weight: 700; font-size: 1.2rem; letter-spacing: .3px; }
    .login-header p { margin: 4px 0 0; opacity: .75; font-size: .82rem; }
    .login-body { padding: 28px 32px 32px; }
    .form-label { font-size: .82rem; font-weight: 600; color: #444; }
    .form-control { border-radius: 8px; font-size: .9rem; padding: 10px 14px; }
    .form-control:focus {
      border-color: #0d6efd;
      box-shadow: 0 0 0 3px rgba(13, 110, 253, .12);
    }
    .input-group-text { border-radius: 0 8px 8px 0; background: #f8f9fa; cursor: pointer; }
    .btn-login {
      background: linear-gradient(135deg, #1a3a5c, #2563a8);
      color: #fff; border: none; border-radius: 8px; padding: 11px;
      font-weight: 600; font-size: .9rem; width: 100%; transition: .2s;
    }
    .btn-login:hover {
      opacity: .9; transform: translateY(-1px);
      box-shadow: 0 4px 16px rgba(13, 110, 253, .35);
    }
    .footer-txt { text-align: center; margin-top: 20px; color: rgba(0, 0, 0, .4); font-size: .75rem; }
    .swal-toast-ti {
      border-radius: 8px !important;
      font-family: 'Inter', sans-serif !important;
      font-size: .84rem !important;
      padding: 10px 16px !important;
      box-shadow: 0 4px 20px rgba(0, 0, 0, .18) !important;
      min-width: 240px;
    }
    .swal-toast-ti .swal2-title { font-size: .84rem !important; font-weight: 600 !important; margin: 0 !important; }
  </style>
</head>

<body>
  <div class="login-wrap">
    <div class="login-card">
      
      <div class="login-header">
        <div class="icon-wrap"><i class="bi bi-hdd-network-fill"></i></div>
        <h4>Sistema de Gestión DPI</h4>
        <p>Inicia sesión para continuar</p>
      </div>

      <div class="login-body">
        <?php if ($error): ?>
          <div class="alert alert-danger py-2 small d-flex align-items-center gap-2" id="login-error-box">
            <i class="bi bi-exclamation-triangle-fill"></i><?= h($error) ?>
          </div>
          <script>
            document.addEventListener('DOMContentLoaded', () => {
              // Ocultar caja nativa para usar SweetAlert Toast elegante
              document.getElementById('login-error-box').style.display = 'none';
              Swal.fire({
                toast: true, position: 'top-end', icon: 'error',
                title: <?= json_encode($error) ?>,
                showConfirmButton: false, timer: 4500, timerProgressBar: true,
                customClass: { popup: 'swal-toast-ti' },
              });

              <?php if ($showSupportAlert): ?>
                Swal.fire({
                  icon: 'warning',
                  title: 'Múltiples intentos fallidos',
                  text: 'Si olvidaste tus credenciales o tu cuenta está bloqueada, por favor contacta al Administrador de Soporte Técnico.',
                  confirmButtonText: 'Entendido',
                  confirmButtonColor: '#1a3a5c'
                });
              <?php endif; ?>
            });
          </script>
        <?php endif; ?>

        <?php if ($f = getFlash()): ?>
          <script>
            document.addEventListener('DOMContentLoaded', () => {
              const iconMap = { success: 'success', danger: 'error', warning: 'warning', info: 'info' };
              Swal.fire({
                toast: true, position: 'top-end',
                icon: iconMap[<?= json_encode($f['type']) ?>] ?? 'info',
                title: <?= json_encode($f['msg']) ?>,
                showConfirmButton: false, timer: 4000, timerProgressBar: true,
                customClass: { popup: 'swal-toast-ti' },
              });
            });
          </script>
        <?php endif; ?>

        <form method="POST" action="login.php" autocomplete="off">
          <div class="mb-3">
            <label class="form-label"><i class="bi bi-person me-1"></i>Usuario</label>
            <input type="text" name="username" class="form-control" placeholder="Ingresa tu usuario"
              value="<?= h($_POST['username'] ?? '') ?>" autofocus required autocomplete="username" maxlength="50">
          </div>
          <div class="mb-4">
            <label class="form-label"><i class="bi bi-lock me-1"></i>Contraseña</label>
            <div class="input-group">
              <input type="password" name="password" id="inp-pass" class="form-control" placeholder="••••••••" required
                autocomplete="current-password" maxlength="72">
              <span class="input-group-text" onclick="togglePass()">
                <i class="bi bi-eye" id="pass-icon"></i>
              </span>
            </div>
          </div>
          <button type="submit" class="btn-login">
            <i class="bi bi-box-arrow-in-right me-2"></i>Iniciar Sesión
          </button>
        </form>

        <div class="footer-txt">Sistema de Gestión DPI v5.0 &mdash; <?= date('Y') ?></div>
      </div> </div> </div> <script>
    function togglePass() {
      const inp = document.getElementById('inp-pass');
      const ico = document.getElementById('pass-icon');
      if (inp.type === 'password') {
        inp.type = 'text'; 
        ico.className = 'bi bi-eye-slash';
      } else {
        inp.type = 'password'; 
        ico.className = 'bi bi-eye';
      }
    }
  </script>
</body>
</html>
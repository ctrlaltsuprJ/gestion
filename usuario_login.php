<?php
/**Login independiente para usuarios que acceden a sus cursos*/
require_once 'includes/auth.php';
require_once 'includes/cursos_functions.php';

// Si ya tiene sesión de curso válida → redirigir al tablero
$tokenExistente = $_COOKIE['curso_token'] ?? '';
if ($tokenExistente && getCursoSesion($tokenExistente)) {
    redirect('cursos_tablero.php');
}

$pageTitle = 'Acceso a Cursos';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuarioWindows = trim($_POST['usuario_windows'] ?? '');
    $password       = $_POST['password'] ?? '';

    if (!$usuarioWindows || !$password) {
        $error = 'Ingresa tu usuario y contraseña.';
    } else {
        $resultado = loginCursoUsuario($usuarioWindows, $password);
        if ($resultado) {
            // Cookie segura por 8 horas
            setcookie('curso_token', $resultado['token'], [
                'expires'  => time() + 8 * 3600,
                'path'     => '/',
                'secure'   => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            redirect('cursos_tablero.php');
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Acceso a Usuarios C3 — <?= APP_NAME ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link href="assets/css/app.css" rel="stylesheet">
  <style>
    body { background: linear-gradient(135deg, #0f2342 0%, #1a3a5c 60%, #1e4976 100%); min-height:100vh; }
    .login-card { border-radius:16px; box-shadow:0 20px 60px rgba(0,0,0,.35); border:0; }
    .login-logo { width:64px; height:64px; background:linear-gradient(135deg,#1a3a5c,#2563eb); border-radius:16px; display:flex; align-items:center; justify-content:center; margin:0 auto 1rem; }
    .form-control:focus { border-color:#2563eb; box-shadow:0 0 0 .2rem rgba(37,99,235,.25); }
    .btn-login { background:linear-gradient(135deg,#1a3a5c,#2563eb); border:0; font-weight:600; }
    .btn-login:hover { background:linear-gradient(135deg,#15304d,#1d4ed8); }
    .plataforma-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .6rem; border-radius:20px; font-size:.75rem; font-weight:500; }
  </style>
</head>
<body class="d-flex align-items-center justify-content-center p-3">
  <div style="width:100%;max-width:420px;">

    <div class="card login-card">
      <div class="card-body p-4 p-md-5">

        <!-- Logo -->
        <div class="login-logo">
          <i class="bi bi-mortarboard-fill text-white fs-3"></i>
        </div>
        <h4 class="text-center fw-bold mb-1">Ingresa</h4>
        <p class="text-center text-muted small mb-4"><?= h(APP_NAME) ?></p>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small">
          <i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="usuario_login.php" autocomplete="off">
          <div class="mb-3">
            <label class="form-label fw-semibold small">Usuario Windows</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-person"></i></span>
              <input type="text" name="usuario_windows" class="form-control"
                     placeholder="tu.usuario" value="<?= h($_POST['usuario_windows'] ?? '') ?>"
                     autocomplete="username" required autofocus>
            </div>
          </div>
          <div class="mb-4">
            <label class="form-label fw-semibold small">Contraseña</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-lock"></i></span>
              <input type="password" name="password" id="pwd" class="form-control"
                     placeholder="••••••••" autocomplete="current-password" required>
              <button type="button" class="btn btn-outline-secondary"
                      onclick="const i=document.getElementById('pwd');i.type=i.type==='password'?'text':'password'">
                <i class="bi bi-eye"></i>
              </button>
            </div>
          </div>
          <button type="submit" class="btn btn-login btn-primary w-100 py-2 text-white">
            <i class="bi bi-box-arrow-in-right me-2"></i>Ingresar a  cursos
          </button>
        </form>

        <hr class="my-4">
        <p class="text-center small text-muted mb-0">
          <i class="bi bi-info-circle me-1"></i>
          Usa el mismo usuario y contraseña con que accedes a tu equipo de trabajo.
        </p>

      </div>
    </div>

    <p class="text-center mt-3 small" style="color:rgba(255,255,255,.5);">
      ¿Eres administrador DPI?
      <a href="index.php" style="color:rgba(255,255,255,.7);">Acceder al panel principal</a>
    </p>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

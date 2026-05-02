<?php
session_start();

// Si ya está logueado, redirigir al dashboard
if (isset($_SESSION['usuario_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email && $password) {
        // ── Conexión a la BD ──────────────────────────────────────────────
        $host   = 'localhost';
        $db     = 'kleta';
        $user   = 'root';
        $pass   = '';
        $charset = 'utf8mb4';

        try {
            $pdo = new PDO(
                "mysql:host=$host;dbname=$db;charset=$charset",
                $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // MD5 para coincidir con INSERT de prueba; en producción usar password_hash
            $stmt = $pdo->prepare(
                "SELECT id, nombre FROM usuarios WHERE email = ? AND password = MD5(?)"
            );
            $stmt->execute([$email, $password]);
            $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($usuario) {
                $_SESSION['usuario_id']     = $usuario['id'];
                $_SESSION['usuario_nombre'] = $usuario['nombre'];
                header('Location: dashboard.php');
                exit;
            } else {
                $error = 'Correo o contraseña incorrectos.';
            }
        } catch (PDOException $e) {
            $error = 'Error de conexión: ' . $e->getMessage();
        }
    } else {
        $error = 'Por favor completa todos los campos.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KLETA — Iniciar Sesión</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --bg:        #0d0d0d;
    --surface:   #161616;
    --border:    #2a2a2a;
    --accent:    #f0b429;
    --accent2:   #e85d04;
    --text:      #f5f5f0;
    --muted:     #888880;
    --danger:    #ef4444;
    --radius:    14px;
  }

  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }

  /* ── Fondo decorativo ── */
  body::before {
    content: '';
    position: fixed; inset: 0;
    background:
      radial-gradient(ellipse 60% 50% at 80% 20%, rgba(240,180,41,.12) 0%, transparent 60%),
      radial-gradient(ellipse 40% 60% at 10% 80%, rgba(232,93,4,.10) 0%, transparent 55%);
    pointer-events: none;
  }

  .grid-bg {
    position: fixed; inset: 0; pointer-events: none;
    background-image:
      linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size: 48px 48px;
  }

  /* ── Card ── */
  .card {
    position: relative; z-index: 2;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 52px 48px 44px;
    width: min(460px, 95vw);
    box-shadow: 0 32px 80px rgba(0,0,0,.6);
    animation: slideUp .55s cubic-bezier(.22,1,.36,1) both;
  }

  @keyframes slideUp {
    from { opacity:0; transform:translateY(32px); }
    to   { opacity:1; transform:translateY(0); }
  }

  /* ── Logo / marca ── */
  .brand {
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 36px;
  }
  .brand-icon {
    width: 52px; height: 52px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 14px;
    display: grid; place-items: center;
    font-size: 22px;
  }
  .brand-text h1 {
    font-family: 'Syne', sans-serif;
    font-weight: 800; font-size: 28px;
    letter-spacing: -.5px; line-height:1;
  }
  .brand-text p {
    font-size: 12px; color: var(--muted);
    margin-top: 3px; letter-spacing: .5px;
    text-transform: uppercase;
  }

  /* ── Encabezado form ── */
  .form-header { margin-bottom: 32px; }
  .form-header h2 {
    font-family: 'Syne', sans-serif;
    font-weight: 700; font-size: 22px;
  }
  .form-header p { font-size: 14px; color: var(--muted); margin-top: 5px; }

  /* ── Campos ── */
  .field { margin-bottom: 20px; }
  label {
    display: block;
    font-size: 12px; font-weight: 500;
    color: var(--muted);
    letter-spacing: .6px;
    text-transform: uppercase;
    margin-bottom: 8px;
  }
  input[type="email"],
  input[type="password"] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    font-size: 15px;
    padding: 13px 16px;
    outline: none;
    transition: border-color .2s, box-shadow .2s;
  }
  input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(240,180,41,.15);
  }
  input::placeholder { color: var(--muted); }

  /* ── Error ── */
  .alert-error {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    color: #fca5a5;
    border-radius: 10px;
    padding: 12px 16px;
    font-size: 13.5px;
    margin-bottom: 22px;
    display: flex; align-items: center; gap: 10px;
  }

  /* ── Botón ── */
  .btn-primary {
    width: 100%; padding: 14px;
    background: linear-gradient(135deg, var(--accent) 0%, var(--accent2) 100%);
    color: #1a0a00;
    font-family: 'Syne', sans-serif;
    font-weight: 700; font-size: 15px;
    letter-spacing: .3px;
    border: none; border-radius: var(--radius);
    cursor: pointer;
    transition: opacity .2s, transform .15s;
    margin-top: 6px;
  }
  .btn-primary:hover  { opacity: .9; transform: translateY(-1px); }
  .btn-primary:active { transform: translateY(0); }

  /* ── Footer card ── */
  .card-footer {
    margin-top: 28px; padding-top: 24px;
    border-top: 1px solid var(--border);
    text-align: center;
    font-size: 12px; color: var(--muted);
  }
  .card-footer span { color: var(--accent); font-weight: 500; }

  /* ── Decoración lateral ── */
  .deco {
    position: fixed; right: -80px; top: 50%;
    transform: translateY(-50%);
    font-family: 'Syne', sans-serif;
    font-size: 220px; font-weight: 800;
    color: rgba(255,255,255,.015);
    letter-spacing: -10px;
    pointer-events: none; user-select: none;
    white-space: nowrap;
  }
</style>
</head>
<body>
<div class="grid-bg"></div>
<div class="deco">KLETA</div>

<div class="card">

  <div class="brand">
    <div class="brand-icon">🍽️</div>
    <div class="brand-text">
      <h1>KLETA</h1>
      <p>Sistema de ventas</p>
    </div>
  </div>

  <div class="form-header">
    <h2>Bienvenido de vuelta</h2>
    <p>Ingresa tus credenciales para continuar</p>
  </div>

  <?php if ($error): ?>
  <div class="alert-error">
    <span>⚠️</span> <?= htmlspecialchars($error) ?>
  </div>
  <?php endif; ?>

  <form method="POST" action="">
    <div class="field">
      <label for="email">Correo electrónico</label>
      <input type="email" id="email" name="email"
             placeholder="admin@kleta.com"
             value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
             required autofocus>
    </div>
    <div class="field">
      <label for="password">Contraseña</label>
      <input type="password" id="password" name="password"
             placeholder="••••••••" required>
    </div>
    <button type="submit" class="btn-primary">Iniciar sesión →</button>
  </form>

  <div class="card-footer">
    Credenciales de prueba: <span>admin@kleta.com</span> / <span>admin123</span>
  </div>
</div>
</body>
</html>

<?php
session_start();

// ── Auth guard ──────────────────────────────────────────────
if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

// ── Conexión ─────────────────────────────────────────────────
$host    = 'localhost';
$db      = 'kleta';
$usuario = 'root';
$pass    = '';
$charset = 'utf8mb4';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$db;charset=$charset",
        $usuario, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('<p style="color:red;font-family:sans-serif;padding:2rem">Error BD: '
        . htmlspecialchars($e->getMessage()) . '</p>');
}

// ── Logout ───────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// ================================================================
//  CONSULTAS PRINCIPALES
// ================================================================

// 1. Tarjetas KPI
$kpis = $pdo->query("
    SELECT
        (SELECT COUNT(*) FROM clientes)                         AS total_clientes,
        (SELECT COALESCE(SUM(cantidad * precio_unit),0)
           FROM consumos WHERE fecha = CURDATE())               AS ventas_hoy,
        (SELECT COALESCE(SUM(monto),0)
           FROM pagos   WHERE fecha_pago = CURDATE())           AS cobros_hoy,
        (SELECT COUNT(*) FROM platos WHERE disponible = 1)     AS platos_activos
")->fetch(PDO::FETCH_ASSOC);

// 2. Saldo pendiente por cliente
$saldos = $pdo->query("
    SELECT
        c.id,
        c.nombre,
        c.tipo_pago,
        COALESCE(SUM(cn.cantidad * cn.precio_unit), 0) AS total_consumido,
        COALESCE(SUM(pg.monto), 0)                     AS total_pagado,
        COALESCE(SUM(cn.cantidad * cn.precio_unit), 0)
            - COALESCE(SUM(pg.monto), 0)               AS saldo
    FROM clientes c
    LEFT JOIN consumos cn ON cn.cliente_id = c.id
    LEFT JOIN pagos    pg ON pg.cliente_id  = c.id
    GROUP BY c.id, c.nombre, c.tipo_pago
    ORDER BY saldo DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 3. Consumos del día con detalle
$consumos_hoy = $pdo->query("
    SELECT
        c.nombre  AS cliente,
        p.nombre  AS plato,
        cn.cantidad,
        cn.precio_unit,
        (cn.cantidad * cn.precio_unit) AS subtotal,
        cn.notas
    FROM consumos cn
    JOIN clientes c ON c.id = cn.cliente_id
    JOIN platos   p ON p.id = cn.plato_id
    WHERE cn.fecha = CURDATE()
    ORDER BY cn.creado_en DESC
")->fetchAll(PDO::FETCH_ASSOC);

// 4. Últimos pagos (10 más recientes)
$ultimos_pagos = $pdo->query("
    SELECT pg.fecha_pago, c.nombre AS cliente,
           pg.monto, pg.tipo_comprobante, pg.observacion
    FROM pagos pg
    JOIN clientes c ON c.id = pg.cliente_id
    ORDER BY pg.creado_en DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// 5. Platos más vendidos
$top_platos = $pdo->query("
    SELECT p.nombre,
           SUM(cn.cantidad)                AS total_unidades,
           SUM(cn.cantidad * cn.precio_unit) AS ingresos
    FROM consumos cn
    JOIN platos p ON p.id = cn.plato_id
    GROUP BY p.id, p.nombre
    ORDER BY total_unidades DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// 6. Ventas últimos 7 días (para mini gráfico)
$ventas7 = $pdo->query("
    SELECT DATE_FORMAT(fecha,'%d/%m') AS dia,
           SUM(cantidad * precio_unit) AS total
    FROM consumos
    WHERE fecha >= CURDATE() - INTERVAL 6 DAY
    GROUP BY fecha
    ORDER BY fecha ASC
")->fetchAll(PDO::FETCH_ASSOC);

$nombre_admin = $_SESSION['usuario_nombre'] ?? 'Admin';
$hoy = date('d/m/Y');

// helpers
function soles($n){ return 'S/ ' . number_format($n, 2); }
function badge_pago($t){
    $map = ['diario'=>'badge-blue','semanal'=>'badge-yellow','mensual'=>'badge-green'];
    return '<span class="badge '.($map[$t]??'badge-blue').'">'.ucfirst($t).'</span>';
}
function badge_comp($t){
    $map = ['boleta'=>'badge-green','factura'=>'badge-yellow','ninguno'=>'badge-gray'];
    return '<span class="badge '.($map[$t]??'badge-gray').'">'.ucfirst($t).'</span>';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KLETA — Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
/* ── Reset & variables ───────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }

:root {
  --bg:       #0c0c0c;
  --surface:  #141414;
  --surface2: #1c1c1c;
  --border:   #252525;
  --accent:   #f0b429;
  --accent2:  #e85d04;
  --green:    #22c55e;
  --blue:     #38bdf8;
  --red:      #ef4444;
  --text:     #f0ede8;
  --muted:    #7a7870;
  --sidebar-w: 240px;
  --radius:   12px;
}

body {
  font-family: 'DM Sans', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
}

/* ── Scrollbar ─────────────────────────────────────────────── */
::-webkit-scrollbar { width:6px; }
::-webkit-scrollbar-track { background: var(--bg); }
::-webkit-scrollbar-thumb { background: var(--border); border-radius:3px; }

/* ═══════════════════════════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════════════════════════ */
.sidebar {
  width: var(--sidebar-w);
  min-height: 100vh;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  position: fixed; left:0; top:0; bottom:0;
  z-index:100;
  padding: 28px 0 24px;
}

.sidebar-logo {
  display: flex; align-items:center; gap:12px;
  padding: 0 20px 28px;
  border-bottom: 1px solid var(--border);
  margin-bottom:16px;
}
.logo-icon {
  width:42px; height:42px;
  background: linear-gradient(135deg,var(--accent),var(--accent2));
  border-radius:10px;
  display:grid; place-items:center;
  font-size:18px; flex-shrink:0;
}
.logo-text h2 {
  font-family:'Syne',sans-serif;
  font-weight:800; font-size:20px;
  letter-spacing:-.5px; line-height:1;
}
.logo-text small { font-size:11px; color:var(--muted); }

.nav-group { padding: 0 12px; }
.nav-label {
  font-size:10px; letter-spacing:1.2px;
  text-transform:uppercase; color:var(--muted);
  padding: 8px 10px 4px;
  font-weight:500;
}
.nav-item {
  display:flex; align-items:center; gap:11px;
  padding:10px 12px; border-radius:10px;
  font-size:14px; color:var(--muted);
  cursor:pointer; text-decoration:none;
  transition:all .2s; margin-bottom:2px;
}
.nav-item:hover  { background:var(--surface2); color:var(--text); }
.nav-item.active { background:rgba(240,180,41,.12); color:var(--accent); }
.nav-item .icon  { font-size:17px; width:22px; text-align:center; }

.sidebar-footer {
  margin-top:auto; padding:16px 20px 0;
  border-top:1px solid var(--border);
}
.user-chip {
  display:flex; align-items:center; gap:10px;
}
.user-avatar {
  width:34px; height:34px;
  background:linear-gradient(135deg,var(--accent),var(--accent2));
  border-radius:50%; display:grid; place-items:center;
  font-family:'Syne',sans-serif; font-weight:700; font-size:13px;
  color:#1a0a00; flex-shrink:0;
}
.user-info { flex:1; min-width:0; }
.user-info span {
  display:block; font-size:13px; font-weight:500;
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.user-info small { font-size:11px; color:var(--muted); }
.logout-btn {
  display:block; margin-top:14px;
  background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.25);
  color:#fca5a5; border-radius:8px;
  padding:9px 12px; text-align:center;
  font-size:13px; font-weight:500;
  text-decoration:none; transition:background .2s;
}
.logout-btn:hover { background:rgba(239,68,68,.2); }

/* ═══════════════════════════════════════════════════════════
   MAIN CONTENT
═══════════════════════════════════════════════════════════ */
.main {
  margin-left: var(--sidebar-w);
  flex:1; padding:32px 36px;
  min-height:100vh;
  animation: fadeIn .4s ease both;
}
@keyframes fadeIn { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:none} }

/* ── Topbar ── */
.topbar {
  display:flex; align-items:center; justify-content:space-between;
  margin-bottom:32px;
}
.page-title h1 {
  font-family:'Syne',sans-serif;
  font-weight:800; font-size:26px; line-height:1;
}
.page-title p { font-size:13px; color:var(--muted); margin-top:4px; }
.topbar-meta { display:flex; align-items:center; gap:16px; }
.date-badge {
  background:var(--surface); border:1px solid var(--border);
  border-radius:8px; padding:8px 14px;
  font-size:13px; color:var(--muted);
  display:flex; align-items:center; gap:7px;
}

/* ── Sección tabs ── */
.section { display:none; }
.section.active { display:block; }

/* ── KPI grid ── */
.kpi-grid {
  display:grid;
  grid-template-columns:repeat(4, 1fr);
  gap:16px; margin-bottom:28px;
}
.kpi-card {
  background:var(--surface); border:1px solid var(--border);
  border-radius: var(--radius);
  padding:22px 24px;
  position:relative; overflow:hidden;
  transition:border-color .2s, transform .2s;
}
.kpi-card:hover { border-color:#3a3a3a; transform:translateY(-2px); }
.kpi-card::before {
  content:''; position:absolute;
  inset:0; opacity:.06;
  background:var(--kpi-color,var(--accent));
}
.kpi-icon {
  font-size:22px; margin-bottom:14px;
  display:inline-flex; align-items:center; justify-content:center;
  width:44px; height:44px;
  background:rgba(255,255,255,.05); border-radius:10px;
}
.kpi-value {
  font-family:'Syne',sans-serif;
  font-weight:700; font-size:26px;
  color:var(--kpi-color,var(--accent));
  line-height:1; margin-bottom:4px;
}
.kpi-label { font-size:13px; color:var(--muted); }

/* ── Grid 2 cols ── */
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:28px; }
.grid-3 { display:grid; grid-template-columns:2fr 1fr; gap:20px; margin-bottom:28px; }

/* ── Panel ── */
.panel {
  background:var(--surface); border:1px solid var(--border);
  border-radius:var(--radius); overflow:hidden;
}
.panel-header {
  padding:18px 22px 14px;
  border-bottom:1px solid var(--border);
  display:flex; align-items:center; justify-content:space-between;
}
.panel-header h3 {
  font-family:'Syne',sans-serif;
  font-weight:700; font-size:15px;
}
.panel-header span { font-size:12px; color:var(--muted); }
.panel-body { padding:16px 22px; }

/* ── Tabla ── */
.table-wrap { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:13.5px; }
thead th {
  text-align:left; font-size:11px;
  letter-spacing:.8px; text-transform:uppercase;
  color:var(--muted); padding:0 14px 10px;
  font-weight:500;
}
tbody tr { border-top:1px solid var(--border); }
tbody tr:hover { background:rgba(255,255,255,.02); }
td { padding:11px 14px; vertical-align:middle; }
.text-right { text-align:right; }
.text-num { font-family:'Syne',sans-serif; font-weight:600; font-size:14px; }
.saldo-pos { color:var(--red); }
.saldo-ok  { color:var(--green); }

/* ── Badges ── */
.badge {
  display:inline-block; padding:3px 9px; border-radius:99px;
  font-size:11px; font-weight:500;
}
.badge-green  { background:rgba(34,197,94,.12);  color:#4ade80; border:1px solid rgba(34,197,94,.2); }
.badge-yellow { background:rgba(240,180,41,.12); color:#fbbf24; border:1px solid rgba(240,180,41,.2); }
.badge-blue   { background:rgba(56,189,248,.12); color:#38bdf8; border:1px solid rgba(56,189,248,.2); }
.badge-gray   { background:rgba(120,120,120,.12);color:#a1a1aa; border:1px solid rgba(120,120,120,.2);}

/* ── Mini gráfico barras ── */
.bar-chart { display:flex; align-items:flex-end; gap:6px; height:80px; padding:8px 0; }
.bar-col { flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; }
.bar {
  width:100%; background:linear-gradient(to top, var(--accent2), var(--accent));
  border-radius:4px 4px 0 0; min-height:4px;
  transition:height .4s ease;
}
.bar-label { font-size:10px; color:var(--muted); }

/* ── Rank list ── */
.rank-list { list-style:none; }
.rank-item {
  display:flex; align-items:center; gap:12px;
  padding:10px 0; border-bottom:1px solid var(--border);
}
.rank-item:last-child { border-bottom:none; }
.rank-num {
  width:22px; height:22px;
  background:rgba(255,255,255,.05); border-radius:6px;
  display:grid; place-items:center;
  font-family:'Syne',sans-serif; font-size:11px; font-weight:700;
  color:var(--muted); flex-shrink:0;
}
.rank-num.gold   { background:rgba(240,180,41,.2); color:var(--accent); }
.rank-num.silver { background:rgba(200,200,200,.12); color:#d4d4d8; }
.rank-num.bronze { background:rgba(180,100,60,.15); color:#c2855a; }
.rank-info { flex:1; }
.rank-info strong { font-size:13.5px; display:block; }
.rank-info small  { font-size:11.5px; color:var(--muted); }
.rank-val { font-family:'Syne',sans-serif; font-weight:700; font-size:14px; color:var(--accent); }

/* ── Estado vacío ── */
.empty { text-align:center; padding:36px 20px; color:var(--muted); }
.empty span { font-size:32px; display:block; margin-bottom:10px; }

/* ── Tab nav ── */
.tabs { display:flex; gap:4px; margin-bottom:28px; background:var(--surface); border:1px solid var(--border); border-radius:12px; padding:5px; width:fit-content; }
.tab-btn {
  padding:9px 18px; border-radius:8px; border:none;
  background:transparent; color:var(--muted);
  font-family:'DM Sans',sans-serif; font-size:14px;
  cursor:pointer; transition:all .2s;
  display:flex; align-items:center; gap:7px;
}
.tab-btn:hover { color:var(--text); }
.tab-btn.active { background:var(--surface2); color:var(--text); box-shadow:0 1px 4px rgba(0,0,0,.4); }

/* ── Totales row ── */
.total-row td { font-weight:600; color:var(--accent); border-top:2px solid var(--border) !important; }

/* ── Responsive básico ── */
@media(max-width:1100px){ .kpi-grid{ grid-template-columns:repeat(2,1fr); } }
@media(max-width:860px) { .grid-2,.grid-3{ grid-template-columns:1fr; } }
</style>
</head>
<body>

<!-- ══════════════════════════════════════════
     SIDEBAR
══════════════════════════════════════════ -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">🍽️</div>
    <div class="logo-text">
      <h2>KLETA</h2>
      <small>Panel de ventas</small>
    </div>
  </div>

  <nav class="nav-group">
    <div class="nav-label">Principal</div>
    <a class="nav-item active" onclick="showTab('resumen')" href="#">
      <span class="icon">📊</span> Resumen
    </a>
    <a class="nav-item" onclick="showTab('consumos')" href="#">
      <span class="icon">🍜</span> Consumos hoy
    </a>
    <a class="nav-item" onclick="showTab('clientes')" href="#">
      <span class="icon">👥</span> Clientes
    </a>
    <a class="nav-item" onclick="showTab('pagos')" href="#">
      <span class="icon">💳</span> Pagos
    </a>
    <a class="nav-item" onclick="showTab('menu')" href="#">
      <span class="icon">📋</span> Menú / Platos
    </a>
  </nav>

  <div class="sidebar-footer">
    <div class="user-chip">
      <div class="user-avatar"><?= strtoupper(substr($nombre_admin,0,1)) ?></div>
      <div class="user-info">
        <span><?= htmlspecialchars($nombre_admin) ?></span>
        <small>Administrador</small>
      </div>
    </div>
    <a href="?logout=1" class="logout-btn">⬡ &nbsp;Cerrar sesión</a>
  </div>
</aside>

<!-- ══════════════════════════════════════════
     MAIN
══════════════════════════════════════════ -->
<main class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="page-title">
      <h1 id="page-title-text">Resumen general</h1>
      <p id="page-title-sub">Vista consolidada del negocio</p>
    </div>
    <div class="topbar-meta">
      <div class="date-badge">📅 &nbsp;<?= $hoy ?></div>
    </div>
  </div>

  <!-- ══════════ SECCIÓN: RESUMEN ══════════ -->
  <section class="section active" id="tab-resumen">

    <!-- KPIs -->
    <div class="kpi-grid">
      <div class="kpi-card" style="--kpi-color:var(--accent)">
        <div class="kpi-icon">💰</div>
        <div class="kpi-value"><?= soles($kpis['ventas_hoy']) ?></div>
        <div class="kpi-label">Consumos de hoy</div>
      </div>
      <div class="kpi-card" style="--kpi-color:var(--green)">
        <div class="kpi-icon">✅</div>
        <div class="kpi-value"><?= soles($kpis['cobros_hoy']) ?></div>
        <div class="kpi-label">Cobros de hoy</div>
      </div>
      <div class="kpi-card" style="--kpi-color:var(--blue)">
        <div class="kpi-icon">👥</div>
        <div class="kpi-value"><?= $kpis['total_clientes'] ?></div>
        <div class="kpi-label">Clientes registrados</div>
      </div>
      <div class="kpi-card" style="--kpi-color:#a78bfa">
        <div class="kpi-icon">🍽️</div>
        <div class="kpi-value"><?= $kpis['platos_activos'] ?></div>
        <div class="kpi-label">Platos disponibles</div>
      </div>
    </div>

    <div class="grid-3">
      <!-- Ventas 7 días -->
      <div class="panel">
        <div class="panel-header">
          <h3>Ventas — últimos 7 días</h3>
          <span>por consumos</span>
        </div>
        <div class="panel-body">
          <?php if ($ventas7): ?>
          <?php
            $max = max(array_column($ventas7,'total')) ?: 1;
          ?>
          <div class="bar-chart">
            <?php foreach($ventas7 as $v): ?>
            <div class="bar-col">
              <div class="bar" style="height:<?= round(($v['total']/$max)*100) ?>%"
                   title="<?= soles($v['total']) ?>"></div>
              <div class="bar-label"><?= $v['dia'] ?></div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="empty"><span>📉</span>Sin datos esta semana</div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Top platos -->
      <div class="panel">
        <div class="panel-header">
          <h3>Top platos</h3>
          <span>todos los tiempos</span>
        </div>
        <div class="panel-body">
          <?php if($top_platos): ?>
          <ul class="rank-list">
          <?php $ranks=['gold','silver','bronze'];
                foreach($top_platos as $i=>$p): ?>
            <li class="rank-item">
              <div class="rank-num <?= $ranks[$i] ?? '' ?>"><?= $i+1 ?></div>
              <div class="rank-info">
                <strong><?= htmlspecialchars($p['nombre']) ?></strong>
                <small><?= $p['total_unidades'] ?> unidades vendidas</small>
              </div>
              <div class="rank-val"><?= soles($p['ingresos']) ?></div>
            </li>
          <?php endforeach; ?>
          </ul>
          <?php else: ?>
          <div class="empty"><span>🍽️</span>Sin ventas aún</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Saldos -->
    <div class="panel">
      <div class="panel-header">
        <h3>Saldos por cobrar</h3>
        <span>total consumido vs. pagado</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Cliente</th>
              <th>Tipo de pago</th>
              <th class="text-right">Consumido</th>
              <th class="text-right">Pagado</th>
              <th class="text-right">Saldo</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($saldos as $i=>$s): ?>
            <tr>
              <td style="color:var(--muted)"><?= $i+1 ?></td>
              <td><strong><?= htmlspecialchars($s['nombre']) ?></strong></td>
              <td><?= badge_pago($s['tipo_pago']) ?></td>
              <td class="text-right text-num"><?= soles($s['total_consumido']) ?></td>
              <td class="text-right text-num"><?= soles($s['total_pagado']) ?></td>
              <td class="text-right text-num <?= $s['saldo']>0?'saldo-pos':'saldo-ok' ?>">
                <?= soles($s['saldo']) ?>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$saldos): ?>
            <tr><td colspan="6"><div class="empty"><span>👥</span>Sin clientes</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </section>

  <!-- ══════════ SECCIÓN: CONSUMOS HOY ══════════ -->
  <section class="section" id="tab-consumos">
    <div class="panel">
      <div class="panel-header">
        <h3>Consumos del día — <?= $hoy ?></h3>
        <span><?= count($consumos_hoy) ?> registro(s)</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Cliente</th>
              <th>Plato</th>
              <th class="text-right">Cant.</th>
              <th class="text-right">Precio unit.</th>
              <th class="text-right">Subtotal</th>
              <th>Notas</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $total_dia = 0;
            foreach($consumos_hoy as $c):
              $total_dia += $c['subtotal'];
          ?>
            <tr>
              <td><?= htmlspecialchars($c['cliente']) ?></td>
              <td><?= htmlspecialchars($c['plato']) ?></td>
              <td class="text-right"><?= $c['cantidad'] ?></td>
              <td class="text-right"><?= soles($c['precio_unit']) ?></td>
              <td class="text-right text-num"><?= soles($c['subtotal']) ?></td>
              <td style="color:var(--muted);font-size:13px"><?= htmlspecialchars($c['notas'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$consumos_hoy): ?>
            <tr><td colspan="6"><div class="empty"><span>🍜</span>Sin consumos hoy</div></td></tr>
          <?php else: ?>
            <tr class="total-row">
              <td colspan="4" style="text-align:right;font-family:'Syne',sans-serif">TOTAL DEL DÍA</td>
              <td class="text-right text-num"><?= soles($total_dia) ?></td>
              <td></td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ══════════ SECCIÓN: CLIENTES ══════════ -->
  <section class="section" id="tab-clientes">
    <div class="panel">
      <div class="panel-header">
        <h3>Clientes registrados</h3>
        <span><?= count($saldos) ?> en total</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Nombre</th>
              <th>Teléfono</th>
              <th>Dirección</th>
              <th>Tipo pago</th>
              <th class="text-right">Saldo pendiente</th>
            </tr>
          </thead>
          <tbody>
          <?php
            $clientes_full = $pdo->query("
              SELECT c.*, 
                COALESCE(SUM(cn.cantidad*cn.precio_unit),0)
                  - COALESCE((SELECT SUM(p2.monto) FROM pagos p2 WHERE p2.cliente_id=c.id),0) AS saldo
              FROM clientes c
              LEFT JOIN consumos cn ON cn.cliente_id=c.id
              GROUP BY c.id ORDER BY c.nombre
            ")->fetchAll(PDO::FETCH_ASSOC);
            foreach($clientes_full as $i=>$c):
          ?>
            <tr>
              <td style="color:var(--muted)"><?= $c['id'] ?></td>
              <td><strong><?= htmlspecialchars($c['nombre']) ?></strong></td>
              <td><?= htmlspecialchars($c['telefono'] ?? '—') ?></td>
              <td style="color:var(--muted);font-size:13px"><?= htmlspecialchars($c['direccion'] ?? '—') ?></td>
              <td><?= badge_pago($c['tipo_pago']) ?></td>
              <td class="text-right text-num <?= $c['saldo']>0?'saldo-pos':'saldo-ok' ?>"><?= soles($c['saldo']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$clientes_full): ?>
            <tr><td colspan="6"><div class="empty"><span>👥</span>Sin clientes</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ══════════ SECCIÓN: PAGOS ══════════ -->
  <section class="section" id="tab-pagos">
    <div class="panel">
      <div class="panel-header">
        <h3>Últimos pagos recibidos</h3>
        <span>10 más recientes</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Cliente</th>
              <th class="text-right">Monto</th>
              <th>Comprobante</th>
              <th>Observación</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($ultimos_pagos as $p): ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($p['fecha_pago'])) ?></td>
              <td><?= htmlspecialchars($p['cliente']) ?></td>
              <td class="text-right text-num" style="color:var(--green)"><?= soles($p['monto']) ?></td>
              <td><?= badge_comp($p['tipo_comprobante']) ?></td>
              <td style="color:var(--muted);font-size:13px"><?= htmlspecialchars($p['observacion'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$ultimos_pagos): ?>
            <tr><td colspan="5"><div class="empty"><span>💳</span>Sin pagos registrados</div></td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- ══════════ SECCIÓN: MENÚ ══════════ -->
  <section class="section" id="tab-menu">
    <?php
      $platos = $pdo->query("SELECT * FROM platos ORDER BY disponible DESC, nombre")->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <div class="panel">
      <div class="panel-header">
        <h3>Carta / Menú</h3>
        <span><?= count($platos) ?> plato(s)</span>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th>
              <th>Nombre</th>
              <th>Descripción</th>
              <th class="text-right">Precio</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($platos as $p): ?>
            <tr>
              <td style="color:var(--muted)"><?= $p['id'] ?></td>
              <td><strong><?= htmlspecialchars($p['nombre']) ?></strong></td>
              <td style="color:var(--muted);font-size:13px"><?= htmlspecialchars($p['descripcion'] ?? '—') ?></td>
              <td class="text-right text-num"><?= soles($p['precio']) ?></td>
              <td>
                <?php if($p['disponible']): ?>
                  <span class="badge badge-green">✓ Disponible</span>
                <?php else: ?>
                  <span class="badge badge-gray">✗ No disponible</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

</main>

<script>
const tabConfig = {
  resumen:  { title:'Resumen general',       sub:'Vista consolidada del negocio' },
  consumos: { title:'Consumos del día',       sub:'Todo lo que se sirvió hoy' },
  clientes: { title:'Gestión de clientes',    sub:'Saldos y datos de cada comensal' },
  pagos:    { title:'Historial de pagos',     sub:'Últimos cobros realizados' },
  menu:     { title:'Carta / Menú',           sub:'Platos disponibles y precios' },
};

function showTab(id) {
  document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  const cfg = tabConfig[id];
  document.getElementById('page-title-text').textContent = cfg.title;
  document.getElementById('page-title-sub').textContent  = cfg.sub;
  event.currentTarget.classList.add('active');
}
</script>
</body>
</html>

<?php
/**
 * backoffice/index.php — ASR Backoffice Dashboard
 * Provides statistics, transcription logs, email logs and debug info.
 */

session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../logger.php';

// ── AJAX / API endpoints ─────────────────────────────────────────────────────
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    if (empty($_SESSION['backoffice_auth'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autenticado']);
        exit;
    }

    if ($_GET['api'] === 'whisper-check') {
        $ch = curl_init(WHISPER_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => WHISPER_HEALTH_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => WHISPER_HEALTH_TIMEOUT,
            CURLOPT_NOBODY         => true,
        ]);
        $start   = microtime(true);
        curl_exec($ch);
        $ms      = round((microtime(true) - $start) * 1000);
        $error   = curl_error($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo json_encode([
            'online'       => empty($error),
            'http_code'    => $code,
            'response_ms'  => $ms,
            'error'        => $error ?: null,
            'url'          => WHISPER_API_URL,
        ]);
        exit;
    }
    echo json_encode(['error' => 'Endpoint desconhecido']);
    exit;
}

// ── AUTH ─────────────────────────────────────────────────────────────────────
$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        $user = trim($_POST['username'] ?? '');
        $pass = $_POST['password'] ?? '';
        if ($user === BACKOFFICE_USER && password_verify($pass, BACKOFFICE_PASS_HASH)) {
            session_regenerate_id(true);
            $_SESSION['backoffice_auth'] = true;
            $_SESSION['backoffice_user'] = $user;
            header('Location: index.php');
            exit;
        }
        $loginError = 'Utilizador ou palavra-passe incorretos.';
    }

    if ($action === 'logout') {
        session_unset();
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

$isAuth = !empty($_SESSION['backoffice_auth']);

// ── DATA FETCH (only when authenticated) ─────────────────────────────────────
$stats        = [];
$transcriptions = [];
$emails       = [];
$page         = $_GET['page'] ?? 'dashboard';
$perPage      = 25;
$tPage        = max(1, (int)($_GET['tp'] ?? 1));
$ePage        = max(1, (int)($_GET['ep'] ?? 1));

if ($isAuth) {
    try {
        $pdo = backoffice_get_db();

        // ── Dashboard stats ───────────────────────────────────────────────────
        $stats['t_total']    = (int)$pdo->query("SELECT COUNT(*) FROM transcriptions")->fetchColumn();
        $stats['t_success']  = (int)$pdo->query("SELECT COUNT(*) FROM transcriptions WHERE success=1")->fetchColumn();
        $stats['t_today']    = (int)$pdo->query("SELECT COUNT(*) FROM transcriptions WHERE date(created_at)=date('now')")->fetchColumn();
        $stats['t_week']     = (int)$pdo->query("SELECT COUNT(*) FROM transcriptions WHERE created_at >= datetime('now','-7 days')")->fetchColumn();
        $stats['t_month']    = (int)$pdo->query("SELECT COUNT(*) FROM transcriptions WHERE created_at >= datetime('now','-30 days')")->fetchColumn();
        $stats['t_avg_dur']  = round((float)$pdo->query("SELECT AVG(duration_seconds) FROM transcriptions WHERE success=1 AND duration_seconds IS NOT NULL")->fetchColumn(), 1);
        $stats['t_total_mb'] = round((float)$pdo->query("SELECT SUM(file_size) FROM transcriptions WHERE file_size IS NOT NULL")->fetchColumn() / 1048576, 1);

        $stats['e_total']   = (int)$pdo->query("SELECT COUNT(*) FROM emails")->fetchColumn();
        $stats['e_success'] = (int)$pdo->query("SELECT COUNT(*) FROM emails WHERE success=1")->fetchColumn();
        $stats['e_today']   = (int)$pdo->query("SELECT COUNT(*) FROM emails WHERE date(created_at)=date('now')")->fetchColumn();
        $stats['e_week']    = (int)$pdo->query("SELECT COUNT(*) FROM emails WHERE created_at >= datetime('now','-7 days')")->fetchColumn();

        // Task breakdown
        $taskRows = $pdo->query("SELECT task, COUNT(*) as cnt FROM transcriptions GROUP BY task ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
        $stats['task_breakdown'] = $taskRows;

        // Language breakdown (top 5)
        $langRows = $pdo->query("SELECT COALESCE(language,'(auto)') as lang, COUNT(*) as cnt FROM transcriptions GROUP BY language ORDER BY cnt DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
        $stats['lang_breakdown'] = $langRows;

        // Daily activity – last 14 days
        $activityRows = $pdo->query("
            SELECT date(created_at) as day, COUNT(*) as cnt
            FROM transcriptions
            WHERE created_at >= datetime('now','-14 days')
            GROUP BY date(created_at)
            ORDER BY day ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        $stats['activity'] = $activityRows;

        // ── Transcriptions list ───────────────────────────────────────────────
        if ($page === 'transcriptions') {
            $tTotal  = (int)$pdo->query("SELECT COUNT(*) FROM transcriptions")->fetchColumn();
            $tOffset = ($tPage - 1) * $perPage;
            $tPages  = max(1, (int)ceil($tTotal / $perPage));
            $stmt = $pdo->prepare("SELECT * FROM transcriptions ORDER BY id DESC LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $tOffset, PDO::PARAM_INT);
            $stmt->execute();
            $transcriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stats['t_list_total'] = $tTotal;
            $stats['t_list_pages'] = $tPages;
        }

        // ── Emails list ───────────────────────────────────────────────────────
        if ($page === 'emails') {
            $eTotal  = (int)$pdo->query("SELECT COUNT(*) FROM emails")->fetchColumn();
            $eOffset = ($ePage - 1) * $perPage;
            $ePages  = max(1, (int)ceil($eTotal / $perPage));
            $stmt = $pdo->prepare("SELECT * FROM emails ORDER BY id DESC LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $eOffset, PDO::PARAM_INT);
            $stmt->execute();
            $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stats['e_list_total'] = $eTotal;
            $stats['e_list_pages'] = $ePages;
        }

    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
}

// ── HELPERS ──────────────────────────────────────────────────────────────────
function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function fmt_bytes(int $bytes): string {
    if ($bytes < 1024)       return "$bytes B";
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}
function rate(int $success, int $total): string {
    if ($total === 0) return '—';
    return round($success / $total * 100, 1) . '%';
}
function self_url(array $extra = []): string {
    $params = array_merge(['page' => $_GET['page'] ?? 'dashboard'], $extra);
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8" />
<title>Backoffice — Whisper ASR</title>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<style>
/* ── Reset & Base ── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 15px; }
body { font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; background: #f3f4f6; color: #111827; min-height: 100vh; }
a { color: inherit; text-decoration: none; }

/* ── Login ── */
.login-wrap { display: flex; align-items: center; justify-content: center; min-height: 100vh; }
.login-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.10); padding: 2.5rem 2rem; width: 340px; }
.login-card h1 { font-size: 1.2rem; font-weight: 700; margin-bottom: 1.5rem; text-align: center; color: #1e40af; }
.login-card label { display: block; font-size: .85rem; font-weight: 600; margin-bottom: .3rem; color: #374151; }
.login-card input { width: 100%; padding: .55rem .75rem; border: 1px solid #d1d5db; border-radius: 7px; font-size: .95rem; margin-bottom: 1rem; }
.login-card input:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
.login-card button { width: 100%; padding: .6rem; background: #1d4ed8; color: #fff; border: none; border-radius: 7px; font-size: 1rem; font-weight: 600; cursor: pointer; }
.login-card button:hover { background: #1e40af; }
.login-error { background: #fee2e2; color: #b91c1c; border-radius: 6px; padding: .5rem .75rem; font-size: .85rem; margin-bottom: 1rem; }

/* ── Layout ── */
.layout { display: flex; min-height: 100vh; }
.sidebar { width: 220px; background: #1e293b; color: #e2e8f0; display: flex; flex-direction: column; flex-shrink: 0; }
.sidebar-brand { padding: 1.25rem 1rem .75rem; border-bottom: 1px solid rgba(255,255,255,.08); }
.sidebar-brand .brand-title { font-size: 1rem; font-weight: 700; color: #fff; }
.sidebar-brand .brand-sub { font-size: .72rem; color: #94a3b8; margin-top: 2px; }
.sidebar nav { flex: 1; padding: .5rem 0; }
.nav-link { display: flex; align-items: center; gap: .6rem; padding: .55rem 1rem; font-size: .9rem; color: #cbd5e1; border-left: 3px solid transparent; transition: background .15s, color .15s; }
.nav-link:hover { background: rgba(255,255,255,.06); color: #fff; }
.nav-link.active { background: rgba(59,130,246,.15); color: #93c5fd; border-left-color: #3b82f6; }
.nav-icon { font-size: 1.05rem; width: 1.3rem; text-align: center; }
.sidebar-footer { padding: .75rem 1rem; border-top: 1px solid rgba(255,255,255,.08); }
.sidebar-footer form button { background: none; border: none; color: #94a3b8; font-size: .82rem; cursor: pointer; display: flex; align-items: center; gap: .4rem; padding: 0; }
.sidebar-footer form button:hover { color: #f1f5f9; }

/* ── Main ── */
.main { flex: 1; overflow-x: hidden; display: flex; flex-direction: column; }
.topbar { background: #fff; border-bottom: 1px solid #e5e7eb; padding: .75rem 1.5rem; display: flex; align-items: center; justify-content: space-between; }
.topbar h1 { font-size: 1.1rem; font-weight: 700; color: #111827; }
.topbar .user-badge { font-size: .8rem; background: #eff6ff; color: #1d4ed8; padding: .25rem .6rem; border-radius: 20px; font-weight: 600; }
.content-area { padding: 1.5rem; flex: 1; }

/* ── Cards ── */
.cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.card { background: #fff; border-radius: 10px; padding: 1.1rem 1.25rem; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.card-label { font-size: .75rem; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; }
.card-value { font-size: 1.9rem; font-weight: 700; color: #111827; margin-top: .15rem; line-height: 1; }
.card-sub { font-size: .78rem; color: #9ca3af; margin-top: .3rem; }
.card.blue .card-value  { color: #1d4ed8; }
.card.green .card-value { color: #15803d; }
.card.red .card-value   { color: #dc2626; }
.card.amber .card-value { color: #d97706; }

/* ── Section ── */
.section { background: #fff; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,.06); margin-bottom: 1.5rem; overflow: hidden; }
.section-header { padding: .85rem 1.25rem; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between; }
.section-title { font-size: .95rem; font-weight: 700; color: #111827; }
.section-body { padding: 1.25rem; }

/* ── Tables ── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: .82rem; }
thead tr { background: #f9fafb; }
th { text-align: left; padding: .55rem .75rem; font-size: .75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }
td { padding: .5rem .75rem; border-top: 1px solid #f3f4f6; color: #374151; vertical-align: top; }
tr:hover td { background: #f9fafb; }
.badge { display: inline-block; padding: .15rem .5rem; border-radius: 20px; font-size: .72rem; font-weight: 700; }
.badge-ok  { background: #dcfce7; color: #15803d; }
.badge-err { background: #fee2e2; color: #b91c1c; }
.text-mono { font-family: monospace; font-size: .78rem; color: #6b7280; }
.text-trunc { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
td.err-msg { color: #b91c1c; font-size: .78rem; max-width: 220px; word-break: break-word; }

/* ── Pagination ── */
.pagination { display: flex; gap: .4rem; flex-wrap: wrap; padding: .75rem 1.25rem; border-top: 1px solid #f3f4f6; }
.pagination a, .pagination span { padding: .3rem .6rem; border-radius: 6px; font-size: .82rem; font-weight: 600; }
.pagination a { background: #f3f4f6; color: #374151; }
.pagination a:hover { background: #e5e7eb; }
.pagination span.current { background: #1d4ed8; color: #fff; }
.pagination span.dots { color: #9ca3af; }

/* ── Bar charts ── */
.bar-chart { display: flex; flex-direction: column; gap: .5rem; }
.bar-row { display: flex; align-items: center; gap: .6rem; font-size: .82rem; }
.bar-label { width: 80px; text-align: right; color: #6b7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bar-bg { flex: 1; background: #f3f4f6; border-radius: 4px; height: 16px; overflow: hidden; }
.bar-fill { height: 100%; background: #3b82f6; border-radius: 4px; transition: width .4s; }
.bar-count { width: 36px; text-align: right; color: #374151; font-weight: 600; }

/* ── Activity sparkline ── */
.sparkline { display: flex; align-items: flex-end; gap: 3px; height: 48px; }
.spark-bar { flex: 1; background: #bfdbfe; border-radius: 3px 3px 0 0; min-width: 4px; transition: background .2s; position: relative; }
.spark-bar:hover { background: #3b82f6; }
.spark-bar[title]:hover::after { content: attr(title); position: absolute; bottom: calc(100% + 4px); left: 50%; transform: translateX(-50%); background: #1e293b; color: #fff; font-size: .7rem; white-space: nowrap; padding: 2px 6px; border-radius: 4px; pointer-events: none; }
.sparkline-wrap { display: flex; flex-direction: column; gap: .4rem; }
.sparkline-labels { display: flex; justify-content: space-between; font-size: .68rem; color: #9ca3af; }

/* ── Debug ── */
.debug-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
@media (max-width: 700px) { .debug-grid { grid-template-columns: 1fr; } }
.info-list { list-style: none; }
.info-list li { display: flex; justify-content: space-between; padding: .35rem 0; border-bottom: 1px solid #f3f4f6; font-size: .83rem; }
.info-list li:last-child { border-bottom: none; }
.info-key { color: #6b7280; }
.info-val { color: #111827; font-weight: 600; text-align: right; }
.check-btn { padding: .4rem .9rem; background: #1d4ed8; color: #fff; border: none; border-radius: 7px; font-size: .85rem; font-weight: 600; cursor: pointer; }
.check-btn:hover { background: #1e40af; }
.check-btn:disabled { opacity: .5; cursor: not-allowed; }
.check-result { margin-top: .75rem; padding: .6rem .85rem; border-radius: 7px; font-size: .85rem; }
.check-ok  { background: #dcfce7; color: #15803d; }
.check-err { background: #fee2e2; color: #b91c1c; }
.ext-list { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .5rem; }
.ext-pill { padding: .15rem .45rem; border-radius: 20px; font-size: .72rem; font-weight: 600; }
.ext-yes { background: #dcfce7; color: #15803d; }
.ext-no  { background: #fee2e2; color: #b91c1c; }

/* ── Empty state ── */
.empty { text-align: center; color: #9ca3af; padding: 2.5rem 1rem; font-size: .9rem; }
</style>
</head>
<body>

<?php if (!$isAuth): ?>
<!-- ── LOGIN ──────────────────────────────────────────────────────────── -->
<div class="login-wrap">
  <div class="login-card">
    <h1>🔐 Backoffice ASR</h1>
    <?php if ($loginError): ?>
      <div class="login-error"><?= h($loginError) ?></div>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login" />
      <label for="username">Utilizador</label>
      <input type="text" id="username" name="username" autocomplete="username" required autofocus />
      <label for="password">Palavra-passe</label>
      <input type="password" id="password" name="password" autocomplete="current-password" required />
      <button type="submit">Entrar</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── APP ────────────────────────────────────────────────────────────── -->
<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <div class="brand-title">📊 Backoffice</div>
      <div class="brand-sub">Whisper ASR · GNR Aveiro</div>
    </div>
    <nav>
      <a class="nav-link <?= $page === 'dashboard'      ? 'active' : '' ?>" href="<?= self_url(['page'=>'dashboard'])      ?>"><span class="nav-icon">🏠</span> Dashboard</a>
      <a class="nav-link <?= $page === 'transcriptions' ? 'active' : '' ?>" href="<?= self_url(['page'=>'transcriptions'])  ?>"><span class="nav-icon">🎙️</span> Transcrições</a>
      <a class="nav-link <?= $page === 'emails'         ? 'active' : '' ?>" href="<?= self_url(['page'=>'emails'])          ?>"><span class="nav-icon">📧</span> Emails</a>
      <a class="nav-link <?= $page === 'debug'          ? 'active' : '' ?>" href="<?= self_url(['page'=>'debug'])           ?>"><span class="nav-icon">🔧</span> Debug</a>
    </nav>
    <div class="sidebar-footer">
      <form method="post">
        <input type="hidden" name="action" value="logout" />
        <button type="submit">⬅ Sair (<?= h($_SESSION['backoffice_user'] ?? '') ?>)</button>
      </form>
    </div>
  </aside>

  <!-- Main -->
  <div class="main">
    <div class="topbar">
      <h1>
        <?php $titles = ['dashboard'=>'Dashboard','transcriptions'=>'Transcrições','emails'=>'Emails','debug'=>'Debug']; ?>
        <?= h($titles[$page] ?? 'Backoffice') ?>
      </h1>
      <span class="user-badge">👤 <?= h($_SESSION['backoffice_user'] ?? '') ?></span>
    </div>
    <div class="content-area">

<?php if (isset($dbError)): ?>
  <div style="background:#fee2e2;color:#b91c1c;padding:.75rem 1rem;border-radius:8px;margin-bottom:1rem;">
    ⚠️ Erro na base de dados: <?= h($dbError) ?>
  </div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php if ($page === 'dashboard'): ?>
<!-- ── DASHBOARD ──────────────────────────────────────────────────────── -->

<div class="cards">
  <div class="card blue">
    <div class="card-label">Transcrições Totais</div>
    <div class="card-value"><?= $stats['t_total'] ?? 0 ?></div>
    <div class="card-sub">Taxa de sucesso: <?= rate($stats['t_success'] ?? 0, $stats['t_total'] ?? 0) ?></div>
  </div>
  <div class="card">
    <div class="card-label">Hoje</div>
    <div class="card-value"><?= $stats['t_today'] ?? 0 ?></div>
    <div class="card-sub">Transcrições</div>
  </div>
  <div class="card">
    <div class="card-label">Últimos 7 dias</div>
    <div class="card-value"><?= $stats['t_week'] ?? 0 ?></div>
    <div class="card-sub">Transcrições</div>
  </div>
  <div class="card">
    <div class="card-label">Últimos 30 dias</div>
    <div class="card-value"><?= $stats['t_month'] ?? 0 ?></div>
    <div class="card-sub">Transcrições</div>
  </div>
  <div class="card green">
    <div class="card-label">Emails Enviados</div>
    <div class="card-value"><?= $stats['e_total'] ?? 0 ?></div>
    <div class="card-sub">Taxa de sucesso: <?= rate($stats['e_success'] ?? 0, $stats['e_total'] ?? 0) ?></div>
  </div>
  <div class="card">
    <div class="card-label">Duração Média</div>
    <div class="card-value"><?= ($stats['t_avg_dur'] ?? 0) > 0 ? $stats['t_avg_dur'] . 's' : '—' ?></div>
    <div class="card-sub">Por transcrição</div>
  </div>
  <div class="card">
    <div class="card-label">Total Processado</div>
    <div class="card-value"><?= $stats['t_total_mb'] ?? 0 ?></div>
    <div class="card-sub">MB de áudio/vídeo</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:1.5rem;">

  <!-- Actividade últimos 14 dias -->
  <div class="section">
    <div class="section-header"><span class="section-title">📈 Actividade (últimos 14 dias)</span></div>
    <div class="section-body">
      <?php
        $actMap = [];
        foreach ($stats['activity'] ?? [] as $r) $actMap[$r['day']] = (int)$r['cnt'];
        $maxAct = max(1, ...(array_values($actMap) ?: [1]));
        $days14 = [];
        for ($i = 13; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $days14[$d] = $actMap[$d] ?? 0;
        }
      ?>
      <div class="sparkline-wrap">
        <div class="sparkline">
          <?php foreach ($days14 as $day => $cnt): ?>
            <div class="spark-bar" style="height:<?= max(4, round($cnt / $maxAct * 100)) ?>%" title="<?= h($day) ?>: <?= $cnt ?>"></div>
          <?php endforeach; ?>
        </div>
        <div class="sparkline-labels">
          <span><?= date('d/m', strtotime('-13 days')) ?></span>
          <span>hoje</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Breakdown tarefa + língua -->
  <div class="section">
    <div class="section-header"><span class="section-title">📋 Tarefas &amp; Línguas</span></div>
    <div class="section-body">
      <?php if (!empty($stats['task_breakdown'])): ?>
        <div style="margin-bottom:.75rem;">
          <div style="font-size:.75rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem;">Tarefas</div>
          <div class="bar-chart">
            <?php
              $tMax = max(1, ...array_column($stats['task_breakdown'], 'cnt'));
              foreach ($stats['task_breakdown'] as $r):
                $label = $r['task'] === 'translate' ? 'Traduzir' : 'Transcrever';
                $pct = round($r['cnt'] / $tMax * 100);
            ?>
            <div class="bar-row">
              <div class="bar-label"><?= h($label) ?></div>
              <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
              <div class="bar-count"><?= $r['cnt'] ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if (!empty($stats['lang_breakdown'])): ?>
        <div>
          <div style="font-size:.75rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.5rem;">Línguas (top 5)</div>
          <div class="bar-chart">
            <?php
              $lMax = max(1, ...array_column($stats['lang_breakdown'], 'cnt'));
              foreach ($stats['lang_breakdown'] as $r):
                $pct = round($r['cnt'] / $lMax * 100);
            ?>
            <div class="bar-row">
              <div class="bar-label"><?= h($r['lang']) ?></div>
              <div class="bar-bg"><div class="bar-fill" style="width:<?= $pct ?>%;background:#10b981"></div></div>
              <div class="bar-count"><?= $r['cnt'] ?></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if (empty($stats['task_breakdown']) && empty($stats['lang_breakdown'])): ?>
        <div class="empty">Sem dados</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($page === 'transcriptions'): ?>
<!-- ── TRANSCRIPTIONS ─────────────────────────────────────────────────── -->

<div class="section">
  <div class="section-header">
    <span class="section-title">🎙️ Registo de Transcrições (<?= $stats['t_list_total'] ?? 0 ?> total)</span>
  </div>
  <div class="table-wrap">
    <?php if (empty($transcriptions)): ?>
      <div class="empty">Sem transcrições registadas.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Data / Hora</th>
          <th>Ficheiro</th>
          <th>Tamanho</th>
          <th>Tarefa</th>
          <th>Língua</th>
          <th>Duração</th>
          <th>Chars</th>
          <th>Estado</th>
          <th>IP</th>
          <th>Erro</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transcriptions as $r): ?>
        <tr>
          <td class="text-mono"><?= $r['id'] ?></td>
          <td class="text-mono" style="white-space:nowrap;"><?= h(str_replace('T',' ',$r['created_at'])) ?></td>
          <td class="text-trunc" title="<?= h($r['filename']) ?>"><?= h($r['filename']) ?></td>
          <td class="text-mono"><?= $r['file_size'] ? fmt_bytes((int)$r['file_size']) : '—' ?></td>
          <td><?= h($r['task'] ?? '—') ?></td>
          <td><?= h($r['language'] ?? '(auto)') ?></td>
          <td class="text-mono"><?= $r['duration_seconds'] !== null ? $r['duration_seconds'] . 's' : '—' ?></td>
          <td class="text-mono"><?= $r['text_length'] ?? '—' ?></td>
          <td><span class="badge <?= $r['success'] ? 'badge-ok' : 'badge-err' ?>"><?= $r['success'] ? 'OK' : 'Erro' ?></span></td>
          <td class="text-mono"><?= h($r['ip_address'] ?? '—') ?></td>
          <td class="err-msg"><?= h($r['error_message'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php
    $tPages = $stats['t_list_pages'] ?? 1;
    if ($tPages > 1):
  ?>
  <div class="pagination">
    <?php for ($p = 1; $p <= $tPages; $p++): ?>
      <?php if ($p == $tPage): ?>
        <span class="current"><?= $p ?></span>
      <?php elseif ($p == 1 || $p == $tPages || abs($p - $tPage) <= 2): ?>
        <a href="<?= self_url(['tp'=>$p]) ?>"><?= $p ?></a>
      <?php elseif (abs($p - $tPage) == 3): ?>
        <span class="dots">…</span>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($page === 'emails'): ?>
<!-- ── EMAILS ─────────────────────────────────────────────────────────── -->

<div class="section">
  <div class="section-header">
    <span class="section-title">📧 Registo de Emails (<?= $stats['e_list_total'] ?? 0 ?> total)</span>
  </div>
  <div class="table-wrap">
    <?php if (empty($emails)): ?>
      <div class="empty">Sem emails registados.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Data / Hora</th>
          <th>Destinatário</th>
          <th>Ficheiros</th>
          <th>Método</th>
          <th>Estado</th>
          <th>IP</th>
          <th>Erro</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($emails as $r): ?>
        <tr>
          <td class="text-mono"><?= $r['id'] ?></td>
          <td class="text-mono" style="white-space:nowrap;"><?= h(str_replace('T',' ',$r['created_at'])) ?></td>
          <td><?= h($r['recipient']) ?></td>
          <td class="text-mono" style="text-align:center;"><?= (int)$r['files_count'] ?></td>
          <td><?= h($r['method'] ?? '—') ?></td>
          <td><span class="badge <?= $r['success'] ? 'badge-ok' : 'badge-err' ?>"><?= $r['success'] ? 'OK' : 'Erro' ?></span></td>
          <td class="text-mono"><?= h($r['ip_address'] ?? '—') ?></td>
          <td class="err-msg"><?= h($r['error_message'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
  <?php
    $ePages = $stats['e_list_pages'] ?? 1;
    if ($ePages > 1):
  ?>
  <div class="pagination">
    <?php for ($p = 1; $p <= $ePages; $p++): ?>
      <?php if ($p == $ePage): ?>
        <span class="current"><?= $p ?></span>
      <?php elseif ($p == 1 || $p == $ePages || abs($p - $ePage) <= 2): ?>
        <a href="<?= self_url(['ep'=>$p]) ?>"><?= $p ?></a>
      <?php elseif (abs($p - $ePage) == 3): ?>
        <span class="dots">…</span>
      <?php endif; ?>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ════════════════════════════════════════════════════════════════════════ -->
<?php elseif ($page === 'debug'): ?>
<!-- ── DEBUG ──────────────────────────────────────────────────────────── -->

<div class="debug-grid">

  <!-- Whisper API Connection -->
  <div class="section">
    <div class="section-header"><span class="section-title">🌐 Ligação Whisper API</span></div>
    <div class="section-body">
      <ul class="info-list">
        <li><span class="info-key">URL</span><span class="info-val"><?= h(WHISPER_API_URL) ?></span></li>
        <li><span class="info-key">Timeout</span><span class="info-val"><?= WHISPER_HEALTH_TIMEOUT ?>s</span></li>
      </ul>
      <div style="margin-top:.85rem;">
        <button class="check-btn" id="checkBtn" onclick="testWhisper()">🔄 Testar Ligação</button>
        <div id="checkResult"></div>
      </div>
    </div>
  </div>

  <!-- PHP Info -->
  <div class="section">
    <div class="section-header"><span class="section-title">🐘 Ambiente PHP</span></div>
    <div class="section-body">
      <ul class="info-list">
        <li><span class="info-key">PHP Version</span><span class="info-val"><?= h(PHP_VERSION) ?></span></li>
        <li><span class="info-key">OS</span><span class="info-val"><?= h(PHP_OS_FAMILY) ?></span></li>
        <li><span class="info-key">SAPI</span><span class="info-val"><?= h(PHP_SAPI) ?></span></li>
        <li><span class="info-key">Max Upload</span><span class="info-val"><?= h(ini_get('upload_max_filesize')) ?></span></li>
        <li><span class="info-key">Post Max</span><span class="info-val"><?= h(ini_get('post_max_size')) ?></span></li>
        <li><span class="info-key">Max Exec Time</span><span class="info-val"><?= h(ini_get('max_execution_time')) ?>s</span></li>
        <li><span class="info-key">Memory Limit</span><span class="info-val"><?= h(ini_get('memory_limit')) ?></span></li>
      </ul>
    </div>
  </div>

  <!-- Required extensions -->
  <div class="section">
    <div class="section-header"><span class="section-title">🔌 Extensões PHP</span></div>
    <div class="section-body">
      <div class="ext-list">
        <?php foreach (['curl','pdo','pdo_sqlite','sqlite3','mbstring','json','openssl','fileinfo'] as $ext): ?>
          <span class="ext-pill <?= extension_loaded($ext) ? 'ext-yes' : 'ext-no' ?>">
            <?= extension_loaded($ext) ? '✓' : '✗' ?> <?= h($ext) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- cURL info -->
  <div class="section">
    <div class="section-header"><span class="section-title">🔗 cURL</span></div>
    <div class="section-body">
      <?php if (function_exists('curl_version')): ?>
        <?php $cv = curl_version(); ?>
        <ul class="info-list">
          <li><span class="info-key">cURL</span><span class="info-val"><?= h($cv['version']) ?></span></li>
          <li><span class="info-key">SSL</span><span class="info-val"><?= h($cv['ssl_version'] ?? '—') ?></span></li>
          <li><span class="info-key">libz</span><span class="info-val"><?= h($cv['libz_version'] ?? '—') ?></span></li>
          <li><span class="info-key">Protocolos</span><span class="info-val" style="font-size:.72rem;"><?= h(implode(', ', $cv['protocols'] ?? [])) ?></span></li>
        </ul>
      <?php else: ?>
        <div class="empty">cURL não disponível</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Database info -->
  <div class="section">
    <div class="section-header"><span class="section-title">🗄️ Base de Dados (SQLite)</span></div>
    <div class="section-body">
      <?php
        $dbFile = BACKOFFICE_DB_PATH;
        $dbExists = file_exists($dbFile);
        $dbSize = $dbExists ? filesize($dbFile) : 0;
      ?>
      <ul class="info-list">
        <li><span class="info-key">SQLite</span><span class="info-val"><?= class_exists('SQLite3') ? h(SQLite3::version()['versionString']) : '—' ?></span></li>
        <li><span class="info-key">Ficheiro DB</span><span class="info-val text-mono" style="font-size:.72rem;"><?= h(basename($dbFile)) ?></span></li>
        <li><span class="info-key">Tamanho DB</span><span class="info-val"><?= $dbExists ? fmt_bytes($dbSize) : 'não criada' ?></span></li>
        <li><span class="info-key">Transcrições</span><span class="info-val"><?= $stats['t_total'] ?? 0 ?></span></li>
        <li><span class="info-key">Emails</span><span class="info-val"><?= $stats['e_total'] ?? 0 ?></span></li>
      </ul>
    </div>
  </div>

  <!-- Recent errors -->
  <div class="section">
    <div class="section-header"><span class="section-title">⚠️ Erros Recentes (24h)</span></div>
    <div class="section-body">
      <?php
        try {
            $recentErrors = backoffice_get_db()->query("
                SELECT 'Transcrição' as type, created_at, filename as ref, error_message
                FROM transcriptions WHERE success=0 AND created_at >= datetime('now','-24 hours')
                UNION ALL
                SELECT 'Email' as type, created_at, recipient as ref, error_message
                FROM emails WHERE success=0 AND created_at >= datetime('now','-24 hours')
                ORDER BY created_at DESC LIMIT 10
            ")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { $recentErrors = []; }
      ?>
      <?php if (empty($recentErrors)): ?>
        <div class="empty">✅ Sem erros nas últimas 24 horas</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>Tipo</th><th>Hora</th><th>Ref.</th><th>Erro</th></tr></thead>
            <tbody>
              <?php foreach ($recentErrors as $e): ?>
              <tr>
                <td><?= h($e['type']) ?></td>
                <td class="text-mono"><?= h(substr($e['created_at'],11,8)) ?></td>
                <td class="text-trunc" title="<?= h($e['ref']) ?>"><?= h($e['ref']) ?></td>
                <td class="err-msg"><?= h($e['error_message'] ?? '') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /debug-grid -->

<script>
async function testWhisper() {
  const btn = document.getElementById('checkBtn');
  const res = document.getElementById('checkResult');
  btn.disabled = true;
  btn.textContent = '⏳ A testar…';
  res.className = 'check-result';
  res.textContent = '';
  try {
    const r = await fetch('?api=whisper-check');
    const d = await r.json();
    if (d.online) {
      res.className = 'check-result check-ok';
      res.textContent = `✅ Online · HTTP ${d.http_code} · ${d.response_ms}ms`;
    } else {
      res.className = 'check-result check-err';
      res.textContent = `❌ Offline · ${d.error || 'sem resposta'}`;
    }
  } catch(e) {
    res.className = 'check-result check-err';
    res.textContent = `❌ Erro: ${e.message}`;
  }
  btn.disabled = false;
  btn.textContent = '🔄 Testar Ligação';
}
</script>
<?php endif; ?>

    </div><!-- /content-area -->
  </div><!-- /main -->
</div><!-- /layout -->
<?php endif; ?>
</body>
</html>

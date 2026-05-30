<?php
// ════════════════════════════════════════════════════════════════════
//  T—BANEN · ADMIN
//  Lightweight publishing tool for station data and images.
// ════════════════════════════════════════════════════════════════════

define('TBANEN_ADMIN', true);
require_once __DIR__ . '/config.php';

session_name('tbanen_admin');
session_start();

// ── CSRF token ────────────────────────────────────────────────────
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function csrf_token() { return $_SESSION['csrf']; }
function verify_csrf() {
    $t = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $t)) {
        http_response_code(403); die('Ugyldig forespørsel.');
    }
}

// ── Auth helpers ──────────────────────────────────────────────────
function authed() { return !empty($_SESSION['authed']); }
function require_auth() {
    if (!authed()) { header('Location: ?'); exit; }
}

// ── Data helpers ──────────────────────────────────────────────────
function load_data(): array {
    return json_decode(file_get_contents(DATA_FILE), true);
}

function save_data(array $data): bool {
    if (!is_dir(DATA_BACKUPS)) mkdir(DATA_BACKUPS, 0755, true);
    copy(DATA_FILE, DATA_BACKUPS . 'stations_' . date('Ymd_His') . '.json');
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return file_put_contents(DATA_FILE, $json) !== false;
}

function find_station(array $data, string $id): ?array {
    foreach ($data['stations'] as $s) {
        if ($s['id'] === $id) return $s;
    }
    return null;
}

function update_station(array &$data, string $id, array $updates): void {
    foreach ($data['stations'] as &$s) {
        if ($s['id'] === $id) { foreach ($updates as $k => $v) $s[$k] = $v; return; }
    }
}

// ── Image processing ──────────────────────────────────────────────
function process_upload(string $tmp, string $station_id): array {
    // Validate MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp);
    $exts  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($exts[$mime])) return ['error' => 'Kun JPG, PNG og WebP er tillatt.'];

    $ext = $exts[$mime];
    $id  = preg_replace('/[^a-z0-9_-]/', '', $station_id);
    $ts  = time();

    foreach ([IMG_ORIGINALS, IMG_STATIONS, IMG_THUMBS] as $dir) {
        if (!is_dir($dir)) mkdir($dir, 0755, true);
    }

    // Back up existing station image before replacing
    $existing = IMG_STATIONS . $id . '.jpg';
    if (file_exists($existing)) {
        copy($existing, IMG_ORIGINALS . $id . '_backup_' . $ts . '.jpg');
    }

    // Store original
    $orig = IMG_ORIGINALS . $id . '_original_' . $ts . '.' . $ext;
    if (!move_uploaded_file($tmp, $orig)) return ['error' => 'Kunne ikke lagre filen.'];

    // Load into GD
    $src = match($mime) {
        'image/jpeg' => imagecreatefromjpeg($orig),
        'image/png'  => imagecreatefrompng($orig),
        'image/webp' => imagecreatefromwebp($orig),
    };
    if (!$src) return ['error' => 'Bildet kunne ikke leses.'];

    $sw = imagesx($src);
    $sh = imagesy($src);

    // Large version — max 1920px wide
    $maxW = 1920;
    $lw = min($sw, $maxW);
    $lh = (int)round($sh * $lw / $sw);
    $large = imagecreatetruecolor($lw, $lh);
    // White background (for PNG transparency → JPG conversion)
    imagefill($large, 0, 0, imagecolorallocate($large, 255, 255, 255));
    imagecopyresampled($large, $src, 0, 0, 0, 0, $lw, $lh, $sw, $sh);
    imagejpeg($large, IMG_STATIONS . $id . '.jpg', 88);
    if (function_exists('imagewebp')) {
        imagewebp($large, IMG_STATIONS . $id . '.webp', 82);
    }

    // Thumbnail — 400px wide for admin preview
    $tw = 400;
    $th = (int)round($sh * $tw / $sw);
    $thumb = imagecreatetruecolor($tw, $th);
    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 8, 8, 8));
    imagecopyresampled($thumb, $src, 0, 0, 0, 0, $tw, $th, $sw, $sh);
    imagejpeg($thumb, IMG_THUMBS . $id . '.jpg', 78);

    imagedestroy($src);
    imagedestroy($large);
    imagedestroy($thumb);

    return ['filename' => $id . '.jpg', 'ts' => $ts];
}

// ── Request routing ───────────────────────────────────────────────
$action = $_REQUEST['action'] ?? '';
$notice = '';
$error  = '';

// Resolve flash messages from redirect
if (!empty($_GET['saved']))    $notice = 'Endringer lagret.';
if (!empty($_GET['uploaded']))  $notice = 'Bilde lastet opp.';
if (!empty($_GET['err']))       $error  = htmlspecialchars(urldecode($_GET['err']));

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'login') {
        $pw = $_POST['password'] ?? '';
        if (password_verify($pw, ADMIN_PASSWORD_HASH)) {
            $_SESSION['authed'] = true;
            session_regenerate_id(true);
            header('Location: ?'); exit;
        }
        $error = 'Feil passord.';

    } elseif ($action === 'save' && authed()) {
        verify_csrf();
        $id   = $_POST['sid'] ?? '';
        $data = load_data();
        $s    = find_station($data, $id);
        if ($s) {
            $pub = !empty($_POST['published']);
            update_station($data, $id, [
                'name'      => trim($_POST['name'] ?? $s['name']),
                'year'      => $_POST['year'] !== '' ? (int)$_POST['year'] : null,
                'elevation' => $_POST['elevation'] !== '' ? (int)$_POST['elevation'] : null,
                'image'     => $pub ? ($s['image'] ?: null) : null,
            ]);
            save_data($data);
        }
        header('Location: ?station=' . urlencode($id) . '&saved=1'); exit;

    } elseif ($action === 'upload' && authed()) {
        verify_csrf();
        $id = $_POST['sid'] ?? '';
        if (empty($_FILES['image']['tmp_name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            header('Location: ?station=' . urlencode($id) . '&err=' . urlencode('Ingen fil valgt.')); exit;
        }
        $result = process_upload($_FILES['image']['tmp_name'], $id);
        if (isset($result['error'])) {
            header('Location: ?station=' . urlencode($id) . '&err=' . urlencode($result['error'])); exit;
        }
        $data = load_data();
        update_station($data, $id, ['image' => $result['filename']]);
        save_data($data);
        header('Location: ?station=' . urlencode($id) . '&uploaded=1'); exit;

    } elseif ($action === 'unpublish' && authed()) {
        verify_csrf();
        $id   = $_POST['sid'] ?? '';
        $data = load_data();
        update_station($data, $id, ['image' => null]);
        save_data($data);
        header('Location: ?station=' . urlencode($id) . '&saved=1'); exit;
    }
}

if ($action === 'logout') {
    session_destroy();
    header('Location: ?'); exit;
}

// ── View data ─────────────────────────────────────────────────────
$view_station_id = $_GET['station'] ?? '';
$view_station    = null;
$all_data        = null;

if (authed()) {
    $all_data = load_data();
    if ($view_station_id) {
        $view_station = find_station($all_data, $view_station_id);
        if (!$view_station) { header('Location: ?'); exit; }
    }
}

// ── Line colors ───────────────────────────────────────────────────
$line_colors = [1 => '#5B9BD5', 2 => '#E8751A', 3 => '#8B5EA7', 4 => '#C8102E', 5 => '#3D9A5C'];

// ════════════════════════════════════════════════════════════════════
//  HTML OUTPUT
// ════════════════════════════════════════════════════════════════════
?>
<!DOCTYPE html>
<html lang="no">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>T—BANEN · ADMIN</title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --bg:       #f4f2ee;
      --surface:  #eceae5;
      --border:   #d8d4cc;
      --muted:    #b0aba0;
      --dim:      #888;
      --secondary:#555;
      --primary:  #1a1814;
      --white:    #0a0806;
      --mono: 'IBM Plex Mono', 'Courier New', monospace;
      --sans: 'IBM Plex Sans', 'Helvetica Neue', Arial, sans-serif;
      --published: #2a7a46;
      --unpublished: #aaa;
    }

    html, body {
      height: 100%; background: var(--bg); color: var(--primary);
      font-family: var(--mono); font-size: 14px;
      -webkit-font-smoothing: antialiased;
    }

    a { color: var(--primary); text-decoration: none; }
    a:hover { color: var(--white); }

    /* ── Layout ── */
    .header {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 24px;
      border-bottom: 1px solid var(--border);
      position: sticky; top: 0; background: var(--bg); z-index: 10;
    }
    .header-id {
      font-size: 12px; letter-spacing: 0.18em; text-transform: uppercase;
      color: var(--white);
    }
    .header-id span { color: var(--dim); margin-left: 8px; }
    .header-nav { display: flex; gap: 20px; align-items: center; }
    .header-nav a { font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--dim); }
    .header-nav a:hover { color: var(--primary); }

    .main { padding: 0; }

    /* ── Login ── */
    .login-wrap {
      min-height: 100vh; display: flex; align-items: center; justify-content: center;
    }
    .login-box {
      width: 320px;
      border: 1px solid var(--border);
      padding: 32px;
    }
    .login-title {
      font-size: 12px; letter-spacing: 0.22em; text-transform: uppercase;
      color: var(--secondary); margin-bottom: 28px;
    }
    .field { display: flex; flex-direction: column; gap: 8px; }
    .field label { font-size: 11px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--dim); }
    .field input[type=password], .field input[type=text], .field input[type=number], select {
      background: var(--bg); border: 1px solid var(--border);
      color: var(--primary); font-family: var(--mono); font-size: 14px;
      padding: 9px 12px; width: 100%;
      outline: none;
      transition: border-color 0.12s;
    }
    .field input:focus, select:focus { border-color: var(--secondary); }

    /* ── Buttons ── */
    .btn {
      font-family: var(--mono); font-size: 12px; letter-spacing: 0.12em;
      text-transform: uppercase; cursor: pointer; border: none;
      padding: 10px 20px; transition: background 0.12s, color 0.12s;
    }
    .btn-primary { background: var(--primary); color: var(--bg); }
    .btn-primary:hover { background: var(--white); }
    .btn-ghost {
      background: transparent; border: 1px solid var(--border);
      color: var(--secondary);
    }
    .btn-ghost:hover { border-color: var(--secondary); color: var(--primary); }
    .btn-danger { background: transparent; border: 1px solid #c8a; color: #864; }
    .btn-danger:hover { border-color: #864; color: #642; }
    .btn-full { width: 100%; margin-top: 16px; }

    /* ── Notice / Error ── */
    .notice, .error-msg {
      font-size: 12px; letter-spacing: 0.10em; text-transform: uppercase;
      padding: 10px 24px;
      border-bottom: 1px solid var(--border);
    }
    .notice    { background: #e8f4ec; color: var(--published); border-left: 2px solid var(--published); }
    .error-msg { background: #f4ece8; color: #844; border-left: 2px solid #c88; }

    /* ── Station list ── */
    .list-header {
      display: grid;
      grid-template-columns: 280px 120px 100px 80px 1fr;
      gap: 0;
      padding: 10px 24px;
      border-bottom: 1px solid var(--border);
      font-size: 11px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--dim);
    }
    .station-row {
      display: grid;
      grid-template-columns: 280px 120px 100px 80px 1fr;
      gap: 0;
      padding: 0 24px;
      border-bottom: 1px solid var(--border);
      align-items: center;
      min-height: 52px;
      transition: background 0.08s;
    }
    .station-row:hover { background: var(--surface); }
    .station-row:last-child { border-bottom: none; }
    .station-name {
      font-size: 14px; color: var(--primary);
      padding: 12px 0;
    }
    .station-name a { color: var(--primary); }
    .station-name a:hover { color: var(--white); }
    .station-id { font-size: 11px; color: var(--dim); margin-top: 2px; }

    .status-pill {
      display: inline-block; font-size: 11px; letter-spacing: 0.10em;
      text-transform: uppercase; padding: 2px 7px;
    }
    .status-pub   { color: var(--published); }
    .status-unpub { color: var(--unpublished); }

    .thumb-cell { padding: 6px 0; }
    .thumb-cell img {
      width: 64px; height: 40px; object-fit: cover;
      display: block; opacity: 0.7;
    }
    .thumb-cell .no-img {
      width: 64px; height: 40px;
      background: var(--surface); border: 1px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 8px; color: var(--dim);
    }

    .line-pips { display: flex; gap: 4px; }
    .line-pip {
      width: 8px; height: 8px; border-radius: 50%;
    }

    .row-action {
      text-align: right; font-size: 12px; letter-spacing: 0.10em;
      text-transform: uppercase; color: var(--dim);
    }
    .row-action a { color: var(--dim); }
    .row-action a:hover { color: var(--primary); }

    /* ── Stats bar ── */
    .stats-bar {
      display: flex; gap: 32px; padding: 14px 24px;
      border-bottom: 1px solid var(--border);
      font-size: 12px; color: var(--dim);
    }
    .stats-bar strong { color: var(--primary); margin-right: 4px; }

    /* ── Filter bar ── */
    .filter-bar {
      display: flex; gap: 16px; padding: 12px 24px;
      border-bottom: 1px solid var(--border);
      align-items: center;
    }
    .filter-bar a {
      font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase;
      color: var(--dim); padding: 3px 0; border-bottom: 1px solid transparent;
    }
    .filter-bar a:hover, .filter-bar a.active { color: var(--primary); border-bottom-color: var(--primary); }

    /* ── Station edit ── */
    .edit-wrap {
      display: grid; grid-template-columns: 1fr 380px;
      min-height: calc(100vh - 49px);
    }
    .edit-main { padding: 28px 32px; border-right: 1px solid var(--border); }
    .edit-sidebar { padding: 28px 24px; }

    .edit-breadcrumb {
      font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase;
      color: var(--dim); margin-bottom: 24px;
    }
    .edit-breadcrumb a { color: var(--dim); }
    .edit-breadcrumb a:hover { color: var(--primary); }
    .edit-breadcrumb span { margin: 0 8px; }

    .edit-title { font-size: 18px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--white); margin-bottom: 6px; }
    .edit-subtitle { font-size: 12px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--dim); margin-bottom: 32px; }

    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px 24px; margin-bottom: 28px; }
    .form-row { grid-column: 1 / -1; }

    .section-label {
      font-size: 11px; letter-spacing: 0.14em; text-transform: uppercase;
      color: var(--dim); padding-bottom: 10px; margin-bottom: 16px;
      border-bottom: 1px solid var(--border);
    }

    .publish-row {
      display: flex; align-items: center; gap: 12px; margin-bottom: 24px;
    }
    .toggle-wrap { display: flex; align-items: center; gap: 10px; cursor: pointer; }
    .toggle-wrap input[type=checkbox] { width: 14px; height: 14px; accent-color: var(--published); }
    .toggle-label { font-size: 13px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--secondary); }

    .form-actions { display: flex; gap: 12px; align-items: center; margin-top: 8px; }

    /* ── Image panel ── */
    .img-current {
      margin-bottom: 20px;
      background: var(--surface);
      border: 1px solid var(--border);
      overflow: hidden;
      position: relative;
    }
    .img-current img {
      width: 100%; aspect-ratio: 4/3; object-fit: cover;
      display: block; opacity: 0.85;
    }
    .img-placeholder {
      width: 100%; aspect-ratio: 4/3;
      display: flex; align-items: center; justify-content: center;
      font-size: 9px; letter-spacing: 0.14em; text-transform: uppercase; color: var(--muted);
    }
    .img-meta {
      padding: 8px 10px; font-size: 12px; color: var(--dim);
      letter-spacing: 0.06em; background: var(--surface);
      border: 1px solid var(--border); border-top: none;
      margin-bottom: 20px;
    }

    .upload-zone {
      border: 1px dashed var(--border); padding: 24px 16px;
      text-align: center; margin-bottom: 16px;
      transition: border-color 0.15s;
    }
    .upload-zone:hover { border-color: var(--secondary); }
    .upload-zone input[type=file] {
      display: none;
    }
    .upload-zone label {
      display: block; cursor: pointer;
      font-size: 12px; letter-spacing: 0.12em; text-transform: uppercase;
      color: var(--dim);
    }
    .upload-zone label:hover { color: var(--primary); }
    .upload-filename { margin-top: 8px; font-size: 12px; color: var(--secondary); min-height: 16px; }

    .sidebar-section-label {
      font-size: 11px; letter-spacing: 0.14em; text-transform: uppercase;
      color: var(--dim); padding-bottom: 10px; margin-bottom: 16px;
      border-bottom: 1px solid var(--border);
    }

    .img-history { font-size: 12px; color: var(--dim); letter-spacing: 0.06em; }
    .img-history li { padding: 4px 0; border-bottom: 1px solid var(--border); list-style: none; }
    .img-history li:last-child { border-bottom: none; }

    /* ── Responsive ── */
    @media (max-width: 900px) {
      .edit-wrap { grid-template-columns: 1fr; }
      .edit-sidebar { border-top: 1px solid var(--border); }
      .list-header, .station-row { grid-template-columns: 1fr 120px 60px; }
      .list-header > *:nth-child(3),
      .list-header > *:nth-child(4),
      .station-row > *:nth-child(3),
      .station-row > *:nth-child(4) { display: none; }
    }
  </style>
</head>
<body>

<?php if (!authed()): ?>
<!-- ═══════════════════════════ LOGIN ═══════════════════════════ -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-title">T—BANEN · Admin</div>
    <?php if ($error): ?><div class="error-msg" style="margin-bottom:20px;padding:8px 0;border:none;border-left:none;"><?= $error ?></div><?php endif ?>
    <form method="post" action="?action=login">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <div class="field" style="margin-bottom:20px;">
        <label>Passord</label>
        <input type="password" name="password" autofocus autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-primary btn-full">Logg inn</button>
    </form>
  </div>
</div>

<?php elseif ($view_station): ?>
<!-- ═══════════════════════════ STATION EDIT ═══════════════════════════ -->
<?php
$s = $view_station;
$img_url = $s['image'] ? IMG_BASE_URL . 'stations/' . $s['image'] . '?v=' . (file_exists(IMG_STATIONS . $s['image']) ? filemtime(IMG_STATIONS . $s['image']) : 0) : null;
$thumb_url = file_exists(IMG_THUMBS . $s['id'] . '.jpg') ? IMG_BASE_URL . 'thumbs/' . $s['id'] . '.jpg?v=' . filemtime(IMG_THUMBS . $s['id'] . '.jpg') : $img_url;
$published = !empty($s['image']);

// List backup files for this station
$backups = [];
foreach (glob(IMG_ORIGINALS . $s['id'] . '_*.{jpg,png,webp}', GLOB_BRACE) as $f) {
    $backups[] = basename($f);
}
usort($backups, fn($a,$b) => strcmp($b,$a)); // newest first
?>
<div class="header">
  <div class="header-id">T—BANEN <span>Admin</span></div>
  <nav class="header-nav">
    <a href="?">← Alle stasjoner</a>
    <a href="?action=logout">Logg ut</a>
  </nav>
</div>

<?php if ($notice): ?><div class="notice"><?= $notice ?></div><?php endif ?>
<?php if ($error):  ?><div class="error-msg"><?= $error ?></div><?php endif ?>

<div class="edit-wrap">
  <div class="edit-main">
    <div class="edit-breadcrumb">
      <a href="?">Stasjoner</a><span>/</span><?= htmlspecialchars($s['name']) ?>
    </div>
    <div class="edit-title"><?= htmlspecialchars($s['name']) ?></div>
    <div class="edit-subtitle">
      <?= htmlspecialchars($s['id']) ?>
      &nbsp;·&nbsp;
      <?php if ($published): ?>
        <span style="color:var(--published)">Publisert</span>
      <?php else: ?>
        <span style="color:var(--dim)">Ikke publisert</span>
      <?php endif ?>
    </div>

    <form method="post" action="?action=save">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="sid" value="<?= htmlspecialchars($s['id']) ?>">

      <div class="section-label">Metadata</div>
      <div class="form-grid">
        <div class="field">
          <label>Stasjonsnavn</label>
          <input type="text" name="name" value="<?= htmlspecialchars($s['name']) ?>">
        </div>
        <div class="field">
          <label>År åpnet</label>
          <input type="number" name="year" value="<?= $s['year'] ?? '' ?>" placeholder="—" min="1894" max="2030">
        </div>
        <div class="field">
          <label>Høyde (moh)</label>
          <input type="number" name="elevation" value="<?= $s['elevation'] ?? '' ?>" placeholder="—">
        </div>
        <div class="field">
          <label>Linjer</label>
          <div style="display:flex;gap:6px;align-items:center;padding-top:8px;">
            <?php foreach ($s['lines'] ?? [] as $l): ?>
              <span class="line-pip" style="background:<?= $line_colors[$l] ?? '#555' ?>;width:10px;height:10px;border-radius:50%;"></span>
              <span style="font-size:9px;color:var(--dim);margin-right:4px;">L<?= $l ?></span>
            <?php endforeach ?>
          </div>
        </div>
      </div>

      <div class="section-label">Status</div>
      <div class="publish-row">
        <label class="toggle-wrap">
          <input type="checkbox" name="published" value="1" <?= $published ? 'checked' : '' ?>>
          <span class="toggle-label">Publisert på kiim.net/tbanen</span>
        </label>
        <span style="font-size:9px;color:var(--dim);margin-left:8px;">
          <?= $published ? 'Krever bilde for å publiseres.' : 'Avpublisert.' ?>
        </span>
      </div>

      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Lagre endringer</button>
        <a href="?" class="btn btn-ghost">Avbryt</a>
      </div>
    </form>
  </div>

  <div class="edit-sidebar">
    <div class="sidebar-section-label">Bilde</div>

    <!-- Current image preview -->
    <?php if ($thumb_url): ?>
      <div class="img-current">
        <img src="<?= htmlspecialchars($thumb_url) ?>" alt="<?= htmlspecialchars($s['name']) ?>">
      </div>
      <div class="img-meta">
        <?= htmlspecialchars($s['image']) ?>
        <?php $f = IMG_STATIONS . $s['image']; if (file_exists($f)): ?>
          &nbsp;·&nbsp;<?= round(filesize($f) / 1024) ?> KB
          &nbsp;·&nbsp;<?= date('d.m.Y', filemtime($f)) ?>
        <?php endif ?>
      </div>
    <?php else: ?>
      <div class="img-current"><div class="img-placeholder">Ingen bilde</div></div>
      <div class="img-meta" style="color:var(--muted)">Ingen bilde lastet opp</div>
    <?php endif ?>

    <!-- Upload form -->
    <form method="post" action="?action=upload" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="sid" value="<?= htmlspecialchars($s['id']) ?>">

      <div class="upload-zone" id="upload-zone">
        <label for="img-file">
          <?= $published ? 'Erstatt bilde' : 'Last opp bilde' ?>
          <br><span style="color:var(--dim);font-size:8px;margin-top:4px;display:block;">JPG · PNG · WebP</span>
        </label>
        <input type="file" name="image" id="img-file" accept="image/jpeg,image/png,image/webp">
        <div class="upload-filename" id="upload-fname"></div>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;">Last opp</button>
    </form>

    <!-- Image history / backups -->
    <?php if ($backups): ?>
    <div style="margin-top:24px;">
      <div class="sidebar-section-label">Arkiv</div>
      <ul class="img-history">
        <?php foreach (array_slice($backups, 0, 6) as $b): ?>
          <li><?= htmlspecialchars($b) ?></li>
        <?php endforeach ?>
        <?php if (count($backups) > 6): ?>
          <li style="color:var(--dim)">+ <?= count($backups) - 6 ?> til</li>
        <?php endif ?>
      </ul>
    </div>
    <?php endif ?>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════ STATION LIST ═══════════════════════════ -->
<?php
$stations = $all_data['stations'];
$total = count($stations);
$published_count = count(array_filter($stations, fn($s) => !empty($s['image'])));

$filter = $_GET['filter'] ?? 'all';
$filtered = match($filter) {
    'published'   => array_filter($stations, fn($s) => !empty($s['image'])),
    'unpublished' => array_filter($stations, fn($s) => empty($s['image'])),
    default       => $stations,
};
?>
<div class="header">
  <div class="header-id">T—BANEN <span>Admin</span></div>
  <nav class="header-nav">
    <a href="?action=logout">Logg ut</a>
  </nav>
</div>

<?php if ($notice): ?><div class="notice"><?= $notice ?></div><?php endif ?>
<?php if ($error):  ?><div class="error-msg"><?= $error ?></div><?php endif ?>

<div class="stats-bar">
  <div><strong><?= $published_count ?></strong> publisert</div>
  <div><strong><?= $total - $published_count ?></strong> ikke publisert</div>
  <div><strong><?= $total ?></strong> totalt</div>
  <div style="margin-left:auto;color:var(--dim);">
    <?= round($published_count / $total * 100) ?>% dokumentert
  </div>
</div>

<div class="filter-bar">
  <a href="?" class="<?= $filter === 'all' ? 'active' : '' ?>">Alle (<?= $total ?>)</a>
  <a href="?filter=published" class="<?= $filter === 'published' ? 'active' : '' ?>">Publisert (<?= $published_count ?>)</a>
  <a href="?filter=unpublished" class="<?= $filter === 'unpublished' ? 'active' : '' ?>">Mangler bilde (<?= $total - $published_count ?>)</a>
</div>

<div class="list-header">
  <div>Stasjon</div>
  <div>Status</div>
  <div>Linjer</div>
  <div>Bilde</div>
  <div></div>
</div>

<?php foreach ($filtered as $s):
  $pub = !empty($s['image']);
  $thumb = IMG_THUMBS . $s['id'] . '.jpg';
  $has_thumb = file_exists($thumb);
?>
<div class="station-row">
  <div class="station-name">
    <a href="?station=<?= urlencode($s['id']) ?>"><?= htmlspecialchars($s['name']) ?></a>
    <div class="station-id"><?= htmlspecialchars($s['id']) ?></div>
  </div>
  <div>
    <?php if ($pub): ?>
      <span class="status-pub">● Publisert</span>
    <?php else: ?>
      <span class="status-unpub">○ Mangler</span>
    <?php endif ?>
  </div>
  <div>
    <div class="line-pips">
      <?php foreach ($s['lines'] ?? [] as $l): ?>
        <span class="line-pip" style="background:<?= $line_colors[$l] ?? '#555' ?>;" title="Linje <?= $l ?>"></span>
      <?php endforeach ?>
    </div>
  </div>
  <div class="thumb-cell">
    <?php if ($has_thumb): ?>
      <img src="<?= IMG_BASE_URL ?>thumbs/<?= $s['id'] ?>.jpg?v=<?= filemtime($thumb) ?>" alt="">
    <?php elseif ($pub && $s['image'] && file_exists(IMG_STATIONS . $s['image'])): ?>
      <img src="<?= IMG_BASE_URL ?>stations/<?= $s['image'] ?>?v=<?= filemtime(IMG_STATIONS . $s['image']) ?>" alt="" style="width:64px;height:40px;object-fit:cover;">
    <?php else: ?>
      <div class="no-img">—</div>
    <?php endif ?>
  </div>
  <div class="row-action">
    <a href="?station=<?= urlencode($s['id']) ?>">Rediger →</a>
  </div>
</div>
<?php endforeach ?>

<?php endif ?>

<script>
  // Show selected filename in upload zone
  const fileInput = document.getElementById('img-file');
  if (fileInput) {
    fileInput.addEventListener('change', () => {
      const fname = document.getElementById('upload-fname');
      if (fname) fname.textContent = fileInput.files[0]?.name ?? '';
    });
  }

  // Drag-and-drop on upload zone
  const zone = document.getElementById('upload-zone');
  if (zone && fileInput) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.style.borderColor = '#555'; });
    zone.addEventListener('dragleave', () => { zone.style.borderColor = ''; });
    zone.addEventListener('drop', e => {
      e.preventDefault(); zone.style.borderColor = '';
      const f = e.dataTransfer.files[0];
      if (f) {
        const dt = new DataTransfer(); dt.items.add(f);
        fileInput.files = dt.files;
        const fname = document.getElementById('upload-fname');
        if (fname) fname.textContent = f.name;
      }
    });
  }
</script>

</body>
</html>

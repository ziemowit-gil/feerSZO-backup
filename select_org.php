<?php
/**
 * Publiczna strona wyboru organizacji (SaaS).
 * Nie wymaga logowania — tylko lista aktywnych tenantów z master.db.
 */
$master_db = __DIR__ . '/saas-tenent/x/master.db';
$orgs = [];
if (is_file($master_db)) {
    try {
        $mpdo = new PDO('sqlite:' . $master_db);
        $mpdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $mpdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $orgs = $mpdo->query(
            "SELECT org_name, slug, krs FROM tenants
             WHERE is_active=1 AND db_ready=1
             ORDER BY org_name COLLATE NOCASE"
        )->fetchAll();
    } catch (\Throwable $e) {}
}

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$docRoot = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
$appDir  = rtrim(str_replace('\\', '/', realpath(__DIR__)), '/');
$base    = ($docRoot && str_starts_with($appDir, $docRoot)) ? substr($appDir, strlen($docRoot)) : '';
$app_url = rtrim($scheme . '://' . $host . $base, '/');

$has_feer = is_file(__DIR__ . '/umowy.db');

function org_initials(string $name): string {
    $words = array_filter(preg_split('/\s+/', $name), fn($w) => mb_strlen($w) > 2);
    $init  = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), $words));
    return mb_substr($init, 0, 2) ?: mb_strtoupper(mb_substr($name, 0, 2));
}

function org_color(string $name): string {
    $palette = ['#2563eb','#7c3aed','#0891b2','#059669','#be185d','#d97706','#0f766e'];
    return $palette[abs(crc32($name)) % count($palette)];
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Wybierz organizację — System Wspomagania Zarządzania Organizacją i Wolontariatem</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
html, body { height: 100%; margin: 0; padding: 0; }

/* ── Layout split ─────────────────────────────── */
.page-split {
  display: flex;
  min-height: 100vh;
}

/* ── Lewa strona ──────────────────────────────── */
.panel-left {
  width: 360px;
  flex-shrink: 0;
  background: linear-gradient(160deg, #0f2044 0%, #1e3a6e 55%, #1d4ed8 100%);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  padding: 3rem 2.5rem;
  position: relative;
  overflow: hidden;
}
.panel-left::before {
  content: '';
  position: absolute;
  width: 360px; height: 360px;
  border-radius: 50%;
  border: 60px solid rgba(255,255,255,.04);
  bottom: -100px; right: -120px;
  pointer-events: none;
}
.panel-left::after {
  content: '';
  position: absolute;
  width: 180px; height: 180px;
  border-radius: 50%;
  border: 36px solid rgba(255,255,255,.05);
  top: -50px; left: -50px;
  pointer-events: none;
}

.brand { position: relative; }
.brand-icon-wrap {
  width: 56px; height: 56px;
  border-radius: 14px;
  background: rgba(255,255,255,.12);
  display: flex; align-items: center; justify-content: center;
  font-size: 1.7rem; color: #fff;
  margin-bottom: 1.4rem;
}
.brand-system {
  font-size: .68rem; font-weight: 700; letter-spacing: .1em;
  text-transform: uppercase; color: rgba(255,255,255,.45);
  margin-bottom: .6rem;
}
.brand-name {
  font-size: 1.45rem; font-weight: 700; color: #fff;
  line-height: 1.25; margin-bottom: .3rem;
}
.brand-name span { color: #93c5fd; }

.brand-features {
  margin-top: 2.5rem;
  list-style: none;
  padding: 0; margin-left: 0;
  display: flex; flex-direction: column; gap: .75rem;
}
.brand-features li {
  display: flex; align-items: flex-start; gap: .6rem;
  color: rgba(255,255,255,.6); font-size: .82rem; line-height: 1.4;
}
.brand-features li i {
  color: #93c5fd; font-size: .95rem; flex-shrink: 0; margin-top: .05rem;
}

.panel-left-footer {
  position: relative;
  color: rgba(255,255,255,.3);
  font-size: .73rem;
}
.panel-left-footer a {
  color: rgba(255,255,255,.5);
  text-decoration: none;
  display: inline-flex; align-items: center; gap: .3rem;
}
.panel-left-footer a:hover { color: rgba(255,255,255,.8); }

/* ── Prawa strona ─────────────────────────────── */
.panel-right {
  flex: 1;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.panel-right-inner {
  flex: 1;
  padding: 3rem 2.5rem;
  max-width: 820px;
  width: 100%;
}

.pick-heading {
  font-size: 1.3rem; font-weight: 700; color: #0f172a;
  margin-bottom: .25rem;
}
.pick-sub {
  font-size: .85rem; color: #64748b; margin-bottom: 1.75rem;
}

/* Szukajka */
.search-box {
  position: relative; margin-bottom: 1.75rem;
}
.search-box input {
  width: 100%;
  padding: .65rem 1rem .65rem 2.6rem;
  border: 1.5px solid #e2e8f0;
  border-radius: .6rem;
  font-size: .95rem;
  background: #fff;
  color: #1e293b;
  outline: none;
  transition: border-color .15s, box-shadow .15s;
}
.search-box input:focus {
  border-color: #2563eb;
  box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
.search-box i {
  position: absolute; left: .85rem; top: 50%;
  transform: translateY(-50%);
  color: #94a3b8; font-size: 1rem; pointer-events: none;
}

/* Org grid */
.org-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: .85rem;
}

.org-card {
  background: #fff;
  border: 1.5px solid #e8edf3;
  border-radius: 12px;
  padding: 1.1rem 1.2rem;
  cursor: pointer;
  text-decoration: none;
  color: inherit;
  display: flex; align-items: center; gap: .9rem;
  transition: border-color .15s, box-shadow .15s, transform .15s;
}
.org-card:hover {
  border-color: #93c5fd;
  box-shadow: 0 4px 16px rgba(37,99,235,.12);
  transform: translateY(-2px);
  color: inherit;
}
.org-card .org-avatar {
  width: 42px; height: 42px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: .9rem; font-weight: 700; color: #fff;
  flex-shrink: 0;
}
.org-card .org-info .org-name {
  font-size: .88rem; font-weight: 600; line-height: 1.3;
  color: #1e293b; margin-bottom: .15rem;
}
.org-card .org-info .org-slug {
  font-size: .72rem; color: #94a3b8; font-family: monospace;
}

/* Hidden by search */
.org-card[data-hidden="1"] { display: none; }

.empty-state {
  text-align: center;
  padding: 3rem 1rem;
  color: #94a3b8;
  display: none;
}
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: .75rem; }

.orgs-count {
  font-size: .75rem; color: #94a3b8;
  margin-top: 1.25rem;
  text-align: right;
}

/* ── Mobile ───────────────────────────────────── */
@media (max-width: 768px) {
  .page-split { flex-direction: column; }
  .panel-left {
    width: 100%; padding: 1.5rem;
    flex-direction: row; align-items: center; gap: 1rem;
    min-height: auto;
  }
  .panel-left::before, .panel-left::after { display: none; }
  .brand { display: flex; align-items: center; gap: .75rem; flex: 1; }
  .brand-icon-wrap { width: 40px; height: 40px; border-radius: 10px; font-size: 1.2rem; margin-bottom: 0; }
  .brand-system { display: none; }
  .brand-name { font-size: 1rem; }
  .brand-features, .panel-left-footer { display: none; }
  .panel-right-inner { padding: 1.5rem; }
  .org-grid { grid-template-columns: 1fr 1fr; gap: .6rem; }
}
@media (max-width: 420px) {
  .org-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="page-split">

  <!-- ══ Lewa strona ════════════════════════════════════════════════════════ -->
  <div class="panel-left">
    <div class="brand">
      <div class="brand-icon-wrap">
        <i class="bi bi-building-heart"></i>
      </div>
      <div class="brand-system">Platforma NGO</div>
      <div class="brand-name">System Wspomagania Zarządzania<br><span>Organizacją i Wolontariatem</span></div>

      <ul class="brand-features">
        <li><i class="bi bi-file-earmark-text"></i> Rejestr umów wszystkich typów</li>
        <li><i class="bi bi-heart"></i> Zarządzanie wolontariatem</li>
        <li><i class="bi bi-people"></i> Baza współpracowników</li>
        <li><i class="bi bi-check2-square"></i> Obieg dokumentów i akceptacje</li>
        <li><i class="bi bi-kanban"></i> Zadania i projekty</li>
      </ul>
    </div>

    <div class="panel-left-footer">
      <?php if ($has_feer): ?>
      <a href="<?= htmlspecialchars($app_url . '/auth/login.php') ?>" class="mb-2 d-inline-block">
        <i class="bi bi-box-arrow-in-right"></i> Logowanie bezpośrednie (FEER)
      </a><br>
      <?php endif; ?>
      &copy; <?= date('Y') ?> &nbsp;·&nbsp; System Wspomagania Zarządzania Organizacją
    </div>
  </div>

  <!-- ══ Prawa strona ════════════════════════════════════════════════════════ -->
  <div class="panel-right">
  <div class="panel-right-inner">

    <div class="pick-heading">Wybierz organizację</div>
    <div class="pick-sub">Zaloguj się na platformę swojej organizacji.</div>

    <?php if (empty($orgs)): ?>
    <!-- Brak tenantów -->
    <div style="text-align:center;padding:3rem 1rem;color:#94a3b8">
      <i class="bi bi-building-slash" style="font-size:3rem;display:block;margin-bottom:.75rem"></i>
      <p class="mb-3">Brak aktywnych organizacji w systemie.</p>
      <?php if ($has_feer): ?>
      <a href="<?= htmlspecialchars($app_url . '/auth/login.php') ?>"
         class="btn btn-outline-primary btn-sm">
        <i class="bi bi-box-arrow-in-right me-1"></i>Zaloguj się bezpośrednio
      </a>
      <?php endif; ?>
    </div>

    <?php else: ?>

    <!-- Szukajka (JS, brak przeładowania strony) -->
    <div class="search-box">
      <i class="bi bi-search"></i>
      <input type="search" id="orgSearch"
             placeholder="Szukaj organizacji…"
             autocomplete="off" autofocus
             oninput="filterOrgs(this.value)">
    </div>

    <!-- Grid org -->
    <div class="org-grid" id="orgGrid">
      <?php foreach ($orgs as $org):
        $slug    = $org['slug'] ?: $org['krs'];
        $url     = $app_url . '/org/' . urlencode($slug) . '/auth/login.php';
        $initials = org_initials($org['org_name']);
        $color    = org_color($org['org_name']);
      ?>
      <a href="<?= htmlspecialchars($url) ?>"
         class="org-card"
         data-name="<?= htmlspecialchars(mb_strtolower($org['org_name'])) ?>">
        <div class="org-avatar" style="background:<?= $color ?>">
          <?= htmlspecialchars($initials) ?>
        </div>
        <div class="org-info">
          <div class="org-name"><?= htmlspecialchars($org['org_name']) ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <div class="empty-state" id="emptyState">
      <i class="bi bi-search"></i>
      Brak wyników dla podanej frazy.
    </div>

    <div class="orgs-count" id="orgsCount">
    </div>

    <?php if ($has_feer): ?>
    <div style="margin-top:2rem;padding-top:1.5rem;border-top:1px solid #e2e8f0;text-align:center">
      <span style="font-size:.78rem;color:#94a3b8">
        <a href="<?= htmlspecialchars($app_url . '/auth/login.php') ?>"
           style="color:#64748b;text-decoration:none">
          <i class="bi bi-box-arrow-in-right me-1"></i>FEER
        </a>
      </span>
    </div>
    <?php endif; ?>

    <?php endif; ?>

  </div><!-- /panel-right-inner -->
  </div><!-- /panel-right -->

</div><!-- /page-split -->

<script>
function filterOrgs(q) {
    q = q.trim().toLowerCase();
    var cards  = document.querySelectorAll('#orgGrid .org-card');
    var visible = 0;
    cards.forEach(function(c) {
        var match = !q || c.dataset.name.includes(q);
        c.dataset.hidden = match ? '0' : '1';
        c.style.display  = match ? '' : 'none';
        if (match) visible++;
    });
    var empty = document.getElementById('emptyState');
    var count = document.getElementById('orgsCount');
    if (empty) empty.style.display = (visible === 0 && q) ? 'block' : 'none';
    if (count) count.textContent = visible + ' ' + (visible === 1 ? 'organizacja' : visible < 5 ? 'organizacje' : 'organizacji') + (q ? ' — wyniki dla: ' + q : '');
}
</script>
</body>
</html>

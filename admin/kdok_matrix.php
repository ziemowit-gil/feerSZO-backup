<?php
require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ksiegowosc.php';
require_login();
if (!is_admin()) { http_response_code(403); die('Brak uprawnień.'); }
kdok_migrate();

// ── Obsługa pobrania HTML ────────────────────────────────────────────────────
$download_html = isset($_GET['dl']) && $_GET['dl'] === 'html';

// ── Pobierz dane ─────────────────────────────────────────────────────────────

// Wszyscy aktywni użytkownicy
$all_users = db_all(
    "SELECT id, name, email, role, is_admin, kdok_ikaks_hash, kdok_ikaks_set_at
     FROM users WHERE is_active = 1 ORDER BY name"
);

// Mapa ról KDOK: user_id => [role, ...]
$all_kdok_roles_rows = db_all("SELECT user_id, role FROM kdok_user_roles");
$kdok_roles_map = [];
foreach ($all_kdok_roles_rows as $r) {
    $kdok_roles_map[$r['user_id']][] = $r['role'];
}

// Certyfikaty: user_id => row (aktywny)
$certs_rows = db_all(
    "SELECT * FROM kdok_certificates WHERE is_active = 1 ORDER BY id DESC"
);
$certs_map = [];
foreach ($certs_rows as $c) {
    // Bierzemy pierwszy (najnowszy) aktywny certyfikat dla każdego user_id
    if (!isset($certs_map[$c['user_id']])) {
        $certs_map[$c['user_id']] = $c;
    }
}

// Ostatnia akcja w KDOK per user_id
$last_actions_rows = kdok_all(
    "SELECT user_id, MAX(created_at) AS last_action_at
     FROM kdok_history
     WHERE user_id IS NOT NULL
     GROUP BY user_id"
);
$last_action_map = [];
foreach ($last_actions_rows as $row) {
    $last_action_map[$row['user_id']] = $row['last_action_at'];
}

// Filtruj: tylko admini LUB użytkownicy z co najmniej jedną rolą KDOK
$users = [];
foreach ($all_users as $u) {
    $uid = (int)$u['id'];
    $has_kdok_role = !empty($kdok_roles_map[$uid]);
    $is_admin_user = ($u['role'] === 'admin') || !empty($u['is_admin']);
    if ($has_kdok_role || $is_admin_user) {
        $u['_kdok_roles']    = $kdok_roles_map[$uid] ?? [];
        $u['_cert']          = $certs_map[$uid] ?? null;
        $u['_last_action']   = $last_action_map[$uid] ?? null;
        $users[] = $u;
    }
}

$now = time();

// ── Funkcje pomocnicze lokalne ────────────────────────────────────────────────

function role_check(array $kdok_roles, string $role): string {
    return in_array($role, $kdok_roles, true) ? 'yes' : 'no';
}

function cert_badge_html(?array $cert, int $now): string {
    if (!$cert) {
        return '<span class="badge bg-secondary">—</span>';
    }
    $cn      = h($cert['subject_cn'] ?: '—');
    $valid_to = $cert['valid_to'] ?? '';
    $ts      = $valid_to ? strtotime($valid_to) : 0;
    $expired = $ts && $ts < $now;
    $date_str = $ts ? date('d.m.Y', $ts) : '—';
    $cls     = $expired ? 'bg-danger' : 'bg-success';
    $label   = $expired ? 'Wygasł ' . $date_str : 'do ' . $date_str;
    return '<strong>' . $cn . '</strong><br>'
        . '<span class="badge ' . $cls . ' mt-1">' . h($label) . '</span>';
}

// ── Buduj tabelę HTML (wspólna dla widoku i pobierania) ───────────────────────

ob_start();
?>
<table class="table table-bordered table-hover align-middle small" id="kdok-matrix-table">
  <thead class="table-light">
    <tr>
      <th>Uzytkownik</th>
      <th class="text-center">Rola upload</th>
      <th class="text-center">Rola meryt</th>
      <th class="text-center">Rola formal</th>
      <th class="text-center">Rola zatwierdza</th>
      <th class="text-center">IKAKS</th>
      <th>Certyfikat X.509</th>
      <th class="text-center">Ostatnia akcja w KDOK</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($users as $u):
    $uid       = (int)$u['id'];
    $roles     = $u['_kdok_roles'];
    $cert      = $u['_cert'];
    $last_act  = $u['_last_action'];
    $is_adm    = ($u['role'] === 'admin') || !empty($u['is_admin']);

    // IKAKS
    $has_ika   = !empty($u['kdok_ikaks_hash']);
    $ika_date  = $u['kdok_ikaks_set_at'] ?? null;

    // Cert
    $cert_ts   = ($cert && $cert['valid_to']) ? strtotime($cert['valid_to']) : 0;
    $cert_exp  = $cert_ts && $cert_ts < $now;
  ?>
  <tr>
    <td>
      <strong><?= h($u['name']) ?></strong><br>
      <small class="text-muted"><?= h($u['email']) ?></small>
      <?php if ($is_adm): ?>
        <br><span class="badge bg-dark mt-1">admin</span>
      <?php endif; ?>
    </td>

    <!-- Rola upload -->
    <td class="text-center">
      <?php if ($is_adm || in_array('upload', $roles, true)): ?>
        <i class="bi bi-check-circle-fill text-success fs-5"
           title="<?= $is_adm ? 'Admin — wszystkie role' : 'upload' ?>"></i>
      <?php else: ?>
        <span class="text-muted">—</span>
      <?php endif; ?>
    </td>

    <!-- Rola meryt -->
    <td class="text-center">
      <?php if ($is_adm || in_array('meryt', $roles, true)): ?>
        <i class="bi bi-check-circle-fill text-success fs-5"
           title="<?= $is_adm ? 'Admin — wszystkie role' : 'meryt' ?>"></i>
      <?php else: ?>
        <span class="text-muted">—</span>
      <?php endif; ?>
    </td>

    <!-- Rola formal -->
    <td class="text-center">
      <?php if ($is_adm || in_array('formal', $roles, true)): ?>
        <i class="bi bi-check-circle-fill text-success fs-5"
           title="<?= $is_adm ? 'Admin — wszystkie role' : 'formal' ?>"></i>
      <?php else: ?>
        <span class="text-muted">—</span>
      <?php endif; ?>
    </td>

    <!-- Rola zatwierdza -->
    <td class="text-center">
      <?php if ($is_adm || in_array('zatwierdza', $roles, true)): ?>
        <i class="bi bi-check-circle-fill text-success fs-5"
           title="<?= $is_adm ? 'Admin — wszystkie role' : 'zatwierdza' ?>"></i>
      <?php else: ?>
        <span class="text-muted">—</span>
      <?php endif; ?>
    </td>

    <!-- IKAKS -->
    <td class="text-center">
      <?php if ($has_ika): ?>
        <span class="text-success fw-bold" title="Ustawiono: <?= h($ika_date) ?>">&#10003;</span>
        <?php if ($ika_date): ?>
          <br><small class="text-muted"><?= date_pl($ika_date) ?></small>
        <?php endif; ?>
      <?php else: ?>
        <span class="text-muted">—</span>
      <?php endif; ?>
    </td>

    <!-- Certyfikat X.509 -->
    <td>
      <?php if ($cert): ?>
        <strong><?= h($cert['subject_cn'] ?: '—') ?></strong><br>
        <?php if ($cert_ts): ?>
          <span class="badge <?= $cert_exp ? 'bg-danger' : 'bg-success' ?> mt-1">
            <?= $cert_exp ? 'Wygasł ' : 'do ' ?><?= date_pl($cert['valid_to']) ?>
          </span>
        <?php endif; ?>
      <?php else: ?>
        <span class="text-muted fst-italic">Brak certyfikatu</span>
      <?php endif; ?>
    </td>

    <!-- Ostatnia akcja w KDOK -->
    <td class="text-center">
      <?php if ($last_act): ?>
        <span title="<?= h($last_act) ?>"><?= date_pl($last_act) ?></span>
      <?php else: ?>
        <span class="text-muted">—</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (empty($users)): ?>
  <tr>
    <td colspan="8" class="text-center text-muted py-4">
      Brak uzytkownikow z rolami KDOK ani adminow.
    </td>
  </tr>
  <?php endif; ?>
  </tbody>
</table>
<?php
$table_html = ob_get_clean();

// ── Pobieranie jako HTML ──────────────────────────────────────────────────────
if ($download_html) {
    $filename = 'kdok_matrix_' . date('Ymd_His') . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo '<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Macierz uprawnie&#324; &mdash; EOD Dokument&oacute;w Ksi&#281;gowych</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<style>
  body { padding: 1.5rem; font-size: .875rem; }
  @media print { .no-print { display:none!important; } }
</style>
</head>
<body>
<h4 class="mb-1">Macierz uprawnie&#324; &mdash; EOD Dokument&oacute;w Ksi&#281;gowych</h4>
<p class="text-muted small mb-3">Wygenerowano: ' . date('d.m.Y H:i:s') . '</p>
<div class="table-responsive">' . $table_html . '</div>
</body>
</html>';
    exit;
}

// ── Widok strony ─────────────────────────────────────────────────────────────
$PAGE_TITLE = 'Macierz uprawnień — EOD Dokumentów Księgowych';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
@media print {
  .no-print, .navbar, footer, .sidebar { display: none !important; }
  body { padding: 0; margin: 0; }
  .card { border: none !important; box-shadow: none !important; }
  h4 { font-size: 1.1rem; }
}
</style>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap no-print">
  <a href="<?= APP_URL ?>/admin/" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-arrow-left"></i> Panel admina
  </a>
  <h4 class="mb-0 me-auto">
    <i class="bi bi-grid-3x3-gap-fill"></i>
    Macierz uprawnień &mdash; EOD Dokumentów Księgowych
  </h4>
  <button type="button" class="btn btn-sm btn-outline-secondary no-print"
          onclick="window.print()">
    <i class="bi bi-printer"></i> Drukuj
  </button>
  <a href="?dl=html" class="btn btn-sm btn-outline-primary no-print">
    <i class="bi bi-download"></i> Pobierz HTML
  </a>
</div>

<div class="alert alert-info small no-print">
  <i class="bi bi-info-circle-fill"></i>
  Tabela pokazuje uzytkownikow, ktorzy maja co najmniej jedna role KDOK lub sa administratorami.
  Kolumna <strong>Admin</strong> wyswietla sie jako etykieta przy nazwie uzytkownika.
  <a href="<?= APP_URL ?>/admin/ksiegowosc_roles.php" class="alert-link">Zarzadzaj rolami &rarr;</a>
  &nbsp;|&nbsp;
  <a href="<?= APP_URL ?>/admin/kdok_certs.php" class="alert-link">Zarzadzaj certyfikatami i IKAKS &rarr;</a>
</div>

<?= flash_html() ?>

<div class="table-responsive">
  <?= $table_html ?>
</div>

<div class="mt-2 small text-muted no-print">
  Wygenerowano: <?= date('d.m.Y H:i:s') ?> &middot; Uzytkownikow w macierzy: <?= count($users) ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

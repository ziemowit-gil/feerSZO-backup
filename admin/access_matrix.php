<?php
/**
 * admin/access_matrix.php — Macierz uprawnień (do wydruku).
 *
 * Skonsolidowany, tylko-do-odczytu widok wszystkich ról × modułów
 * (CRM wydzielony od pozostałych modułów), lista kont per rola
 * oraz doradcze rekomendacje połączeń VPN dla panelu administratora.
 *
 * Tryby:
 *   (domyślny)  — widok w panelu admina (header.php) z przyciskiem „Drukuj".
 *   ?print=1    — czysty HTML bez layoutu aplikacji; auto-wydruk przez przeglądarkę
 *                 (wzorzec jak w contracts/print.php).
 *
 * Edycja uprawnień pozostaje w admin/roles.php — ta strona tylko prezentuje/drukuje.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

require_role('admin');

$PAGE_TITLE = 'Macierz uprawnień';
$is_print   = isset($_GET['print']);

// ── Dane ────────────────────────────────────────────────────────────────────
$roles = roles_all();

// Mapa uprawnień per rola
$perms_by_role = [];
foreach ($roles as $r) {
    $perms_by_role[$r['name']] = role_permissions($r['name']);
}

// Konta per rola (imienny audyt przypisań)
$users_by_role = [];
foreach (db_all("SELECT id, name, email, role, is_active FROM users ORDER BY role, name") as $u) {
    $users_by_role[$u['role']][] = $u;
}

// Podział modułów: główny system vs CRM (wydzielony zgodnie z wymaganiem)
$crm_modules  = [];
$main_modules = [];
foreach (PERMISSION_MODULES as $mod => $label) {
    if (str_starts_with($mod, 'crm')) $crm_modules[$mod] = $label;
    else                              $main_modules[$mod] = $label;
}

// Status ograniczenia IP admina (jednolinijkowy kontekst dla rekomendacji VPN)
$ip_restrict_on = false;
try {
    $v = db_one("SELECT value FROM settings WHERE key_ = 'admin_ip_restrict'");
    $ip_restrict_on = $v && $v['value'] === '1';
} catch (\Throwable $e) {}

// Dane nagłówka (organizacja)
$org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
$gen_at   = date('Y-m-d H:i');
$gen_by   = current_user()['name'] ?? '';

// ── Renderery treści (wspólne dla widoku i wydruku) ──────────────────────────

/** Komórka uprawnień: R / Z / U z wypełnieniem wg posiadanych praw. */
function am_cell(array $mp): string {
    $flag = function (bool $on, string $letter, string $title) {
        $cls = $on ? 'am-on' : 'am-off';
        return '<span class="am-flag ' . $cls . '" title="' . h($title) . '">' . $letter . '</span>';
    };
    return '<span class="am-flags">'
         . $flag(!empty($mp['can_read']),   'O', 'Odczyt')
         . $flag(!empty($mp['can_write']),  'Z', 'Zapis')
         . $flag(!empty($mp['can_delete']), 'U', 'Usuwanie')
         . '</span>';
}

/** Jedna sekcja macierzy (grupa modułów) jako tabela: moduły w wierszach, role w kolumnach. */
function am_matrix_table(array $modules, array $roles, array $perms_by_role): string {
    if (!$modules) return '';
    ob_start(); ?>
    <table class="am-matrix">
      <thead>
        <tr>
          <th class="am-modcol">Moduł</th>
          <?php foreach ($roles as $r): ?>
          <th class="am-rolecol">
            <?= h($r['display_name']) ?>
            <span class="am-rolecode"><?= h($r['name']) ?></span>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($modules as $mod => $label): ?>
        <tr>
          <td class="am-modcol">
            <?= h($label) ?> <span class="am-modcode"><?= h($mod) ?></span>
          </td>
          <?php foreach ($roles as $r):
              $mp = $perms_by_role[$r['name']][$mod] ?? [];
          ?>
          <td class="am-cell"><?= am_cell($mp) ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
    return ob_get_clean();
}

/** Pełna treść strony (legenda, macierze, konta, VPN). */
function am_render_body(array $ctx): void {
    extract($ctx); // $roles, $perms_by_role, $users_by_role, $main_modules, $crm_modules, $ip_restrict_on
    ?>
    <p class="am-legend">
      <strong>Legenda:</strong>
      <span class="am-flag am-on">O</span> odczyt &nbsp;
      <span class="am-flag am-on">Z</span> zapis &nbsp;
      <span class="am-flag am-on">U</span> usuwanie &nbsp;
      &mdash; pole wypełnione = uprawnienie przyznane, wyszarzone = brak.
      Administrator ma zawsze pełny dostęp do wszystkich modułów.
    </p>

    <h2 class="am-h2">1. Moduły systemu głównego</h2>
    <?= am_matrix_table($main_modules, $roles, $perms_by_role) ?>

    <h2 class="am-h2">2. Moduły CRM</h2>
    <?= am_matrix_table($crm_modules, $roles, $perms_by_role) ?>

    <h2 class="am-h2">3. Konta przypisane do ról</h2>
    <table class="am-accounts">
      <thead>
        <tr><th>Rola</th><th>Konta (imię i nazwisko · e-mail)</th></tr>
      </thead>
      <tbody>
        <?php foreach ($roles as $r):
            $list = $users_by_role[$r['name']] ?? [];
        ?>
        <tr>
          <td class="am-acc-role">
            <strong><?= h($r['display_name']) ?></strong>
            <span class="am-modcode"><?= h($r['name']) ?></span><br>
            <span class="am-acc-count"><?= count($list) ?> kont</span>
          </td>
          <td>
            <?php if (!$list): ?>
              <span class="am-muted">— brak przypisanych kont —</span>
            <?php else: ?>
              <ul class="am-acc-list">
                <?php foreach ($list as $u): ?>
                <li>
                  <?= h($u['name']) ?> <span class="am-muted">· <?= h($u['email']) ?></span>
                  <?php if (!$u['is_active']): ?><span class="am-inactive">(nieaktywne)</span><?php endif; ?>
                </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php
        // Konta z rolą nieujętą w tabeli roles (np. starsze dane)
        $known = array_column($roles, null, 'name');
        foreach ($users_by_role as $rname => $list):
            if (isset($known[$rname])) continue;
        ?>
        <tr>
          <td class="am-acc-role">
            <strong>Nieznana rola</strong>
            <span class="am-modcode"><?= h($rname) ?></span><br>
            <span class="am-acc-count"><?= count($list) ?> kont</span>
          </td>
          <td>
            <ul class="am-acc-list">
              <?php foreach ($list as $u): ?>
              <li><?= h($u['name']) ?> <span class="am-muted">· <?= h($u['email']) ?></span></li>
              <?php endforeach; ?>
            </ul>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h2 class="am-h2">4. Rekomendacje połączeń VPN</h2>
    <div class="am-vpn">
      <p class="am-vpn-status">
        Ograniczenie dostępu do panelu administratora wg adresu IP:
        <?php if ($ip_restrict_on): ?>
          <strong class="am-vpn-on">WŁĄCZONE</strong>
        <?php else: ?>
          <strong class="am-vpn-off">WYŁĄCZONE</strong> — zalecane włączenie (poniżej).
        <?php endif; ?>
        Konfiguracja: <em>Administracja → Ustawienia organizacji</em> (admin/org_settings.php),
        pola <code>admin_ip_restrict</code> i <code>admin_ip_whitelist</code>.
      </p>
      <ul class="am-vpn-list">
        <li><strong>Dostęp przez VPN dla ról administracyjnych.</strong> Konta z rolą
            <em>Administrator</em> powinny łączyć się z panelem wyłącznie przez firmowy VPN
            lub stałe łącze biura — nigdy z dowolnej, zmiennej sieci publicznej.</li>
        <li><strong>Whitelista CIDR zamiast pojedynczych IP.</strong> Na białej liście wpisuj
            zakresy puli VPN, np. <code>10.8.0.0/24</code> (OpenVPN), <code>10.10.0.0/16</code>
            (WireGuard) lub stałe IP biura <code>203.0.113.10/32</code>. Każdy wpis w osobnej linii;
            obsługiwany jest też zapis <code>192.168.1.*</code>.</li>
        <li><strong>Statyczny adres wyjściowy VPN.</strong> Bramka VPN musi mieć stałe IP
            publiczne — tylko wtedy whitelista ma sens. Adresy dynamiczne (DHCP od operatora)
            uniemożliwiają stabilne ograniczenie.</li>
        <li><strong>IPv6.</strong> Jeśli VPN przydziela IPv6, dodaj również zakres IPv6
            (np. <code>2001:db8:abcd::/48</code>) — strażnik IP obsługuje oba protokoły.</li>
        <li><strong>Konto serwisowe.</strong> <code>serwis@local</code> jest celowo wyłączone
            z ograniczenia IP (dostęp awaryjny) — chroń jego hasło szczególnie mocno.</li>
        <li><strong>Role nie-administracyjne</strong> (edytor, widz, użytkownik CRM) nie są objęte
            strażnikiem IP. Jeśli mają pracować zdalnie z danymi wrażliwymi, rozważ objęcie
            całej aplikacji dostępem wyłącznie przez VPN na poziomie serwera/zapory.</li>
      </ul>
    </div>
    <?php
}

$ctx = compact('roles', 'perms_by_role', 'users_by_role', 'main_modules', 'crm_modules', 'ip_restrict_on');

// Wspólne style macierzy (działają w panelu i w wydruku)
$am_styles = <<<CSS
.am-meta{color:#666;font-size:.85rem;margin:.25rem 0 1rem}
.am-legend{font-size:.85rem;background:#f8f9fa;border:1px solid #e3e6ea;border-radius:6px;padding:.6rem .8rem;margin:.5rem 0 1.25rem}
.am-h2{font-size:1.05rem;margin:1.5rem 0 .5rem;border-bottom:2px solid #dee2e6;padding-bottom:.25rem}
.am-matrix,.am-accounts{width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:.5rem}
.am-matrix th,.am-matrix td,.am-accounts th,.am-accounts td{border:1px solid #d0d4d9;padding:.35rem .5rem;vertical-align:top}
.am-matrix thead th,.am-accounts thead th{background:#eef1f4;text-align:left;font-weight:600}
.am-matrix .am-rolecol{text-align:center}
.am-matrix .am-cell{text-align:center;white-space:nowrap}
.am-modcol{width:32%}
.am-rolecode,.am-modcode{display:block;font-family:monospace;font-size:.72rem;color:#888;font-weight:400}
.am-modcode{display:inline;margin-left:.25rem}
.am-flags{display:inline-flex;gap:3px}
.am-flag{display:inline-block;width:18px;height:18px;line-height:18px;text-align:center;border-radius:3px;font-size:.7rem;font-weight:700}
.am-flag.am-on{background:#198754;color:#fff}
.am-flag.am-off{background:#e9ecef;color:#bcc1c7}
.am-accounts .am-acc-role{width:24%}
.am-acc-count{font-size:.72rem;color:#888}
.am-acc-list{margin:0;padding-left:1.1rem}
.am-acc-list li{margin-bottom:.15rem}
.am-muted{color:#888}
.am-inactive{color:#b02a37;font-size:.75rem;margin-left:.25rem}
.am-vpn{font-size:.85rem}
.am-vpn-status{background:#f8f9fa;border:1px solid #e3e6ea;border-radius:6px;padding:.6rem .8rem}
.am-vpn-on{color:#198754}
.am-vpn-off{color:#b02a37}
.am-vpn-list li{margin-bottom:.5rem}
.am-vpn code,.am-vpn-status code{background:#f1f3f5;padding:.05rem .3rem;border-radius:3px;font-size:.8em}
CSS;

// ── Tryb WYDRUK: czysty HTML bez layoutu aplikacji ───────────────────────────
if ($is_print) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Macierz uprawnień — <?= h($org_name) ?></title>
<style>
  @page { size: A4 landscape; margin: 12mm; }
  *{box-sizing:border-box}
  body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1a1a1a;margin:0;padding:18px}
  .am-print-hd{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:3px solid #1a1a1a;padding-bottom:.5rem;margin-bottom:.5rem}
  .am-print-hd h1{font-size:1.3rem;margin:0}
  .am-print-actions{margin:.5rem 0 1rem}
  .am-print-actions button{padding:.4rem .9rem;font-size:.9rem;cursor:pointer}
  <?= $am_styles ?>

  @media print {
    .am-print-actions{display:none}
    body{padding:0}
    .am-h2{page-break-after:avoid}
    table{page-break-inside:auto}
    tr{page-break-inside:avoid}
  }
</style>
</head>
<body>
  <div class="am-print-hd">
    <h1>Macierz uprawnień</h1>
    <div><?= h($org_name) ?></div>
  </div>
  <div class="am-meta">
    Wygenerowano: <?= h($gen_at) ?><?php if ($gen_by): ?> · przez <?= h($gen_by) ?><?php endif; ?>
  </div>
  <div class="am-print-actions">
    <button onclick="window.print()">Drukuj / Zapisz PDF</button>
  </div>
  <?php am_render_body($ctx); ?>
  <script>
    // Auto-wywołanie okna wydruku po załadowaniu (jak w pozostałych wydrukach).
    window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });
  </script>
</body>
</html>
    <?php
    exit;
}

// ── Tryb WIDOK: w panelu administratora ──────────────────────────────────────
include dirname(__DIR__) . '/includes/header.php';
?>
<style><?= $am_styles ?></style>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-grid-3x3-gap text-primary"></i> Macierz uprawnień</h4>
  <div class="d-flex gap-2">
    <a href="roles.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-pencil-square"></i> Edytuj uprawnienia
    </a>
    <a href="ezd_access_matrix.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-archive-fill" style="color:#b45309"></i> Macierz EZD
    </a>
    <a href="access_matrix.php?print=1" target="_blank" rel="noopener" class="btn btn-sm btn-primary">
      <i class="bi bi-printer"></i> Drukuj / PDF
    </a>
  </div>
</div>
<p class="am-meta">Skonsolidowany, tylko-do-odczytu przegląd wszystkich ról i ich uprawnień. Edycja w sekcji <a href="roles.php">Role i uprawnienia</a>.</p>

<div class="card shadow-sm">
  <div class="card-body">
    <?php am_render_body($ctx); ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

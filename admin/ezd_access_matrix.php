<?php
/**
 * admin/ezd_access_matrix.php — Macierz uprawnień „Wirtualnego biurka" (EZD).
 *
 * Dedykowany, tylko-do-odczytu widok modelu dostępu modułu EZD, który jest
 * bogatszy niż zwykłe R/Z/U na poziomie roli: obok uprawnień ról działają
 * relacje do konkretnej koszulki (właściciel/referent, współdzielenie odczyt/edycja)
 * oraz reguły domykania koszulek (edycja zamkniętej = tylko admin).
 *
 * Zawiera też status ograniczenia „dostęp tylko przez VPN" dla /ezd/
 * (settings: ezd_vpn_only / ezd_vpn_allowlist), konfigurowany w
 * admin/org_settings.php (zakładka Bezpieczeństwo).
 *
 * Tryby:
 *   (domyślny)  — widok w panelu admina (header.php) z przyciskiem „Drukuj".
 *   ?print=1    — czysty HTML bez layoutu; auto-wydruk (wzorzec jak access_matrix.php).
 *
 * Źródło reguł: includes/ezd.php (ezd_sprawa_access, ezd_sprawa_can_manage_share,
 * ezd_sprawy_all, gates is_admin/can_write('ezd')/can_read('ezd')).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/permissions.php';

require_role('admin');
if (!defined('TZ_ADMIN_CHROME')) {
    header('Location: ' . APP_URL . '/tozsamosc/ezd_macierz.php' . ($_SERVER['QUERY_STRING'] ? '?' . $_SERVER['QUERY_STRING'] : ''));
    exit;
}
$SELF_URL   = TZ_ADMIN_URL;

$PAGE_TITLE = 'Macierz uprawnień EZD';
$is_print   = isset($_GET['print']);

// ── Dane: role i ich uprawnienia do modułu 'ezd' ─────────────────────────────
$roles = roles_all();
$ezd_role_perms = [];
foreach ($roles as $r) {
    $mp = role_permissions($r['name'])['ezd'] ?? [];
    $ezd_role_perms[$r['name']] = [
        'read'   => !empty($mp['can_read']),
        'write'  => !empty($mp['can_write']),
        'delete' => !empty($mp['can_delete']),
        'ezd_only' => !empty($r['ezd_only']),
    ];
}

// Konta z dostępem do EZD (rola z odczytem/zapisem 'ezd' lub admin)
$ezd_accounts = [];
foreach (db_all("SELECT id, name, email, role, is_active FROM users ORDER BY role, name") as $u) {
    $rp = $ezd_role_perms[$u['role']] ?? null;
    $has = ($u['role'] === 'admin') || ($rp && ($rp['read'] || $rp['write']));
    if ($has) $ezd_accounts[$u['role']][] = $u;
}

// ── Macierz czynności EZD × aktorzy ──────────────────────────────────────────
// Kolumny = relacja/rola wobec koszulki. Wartości: 'y' tak, 'n' nie, 'ro' tylko odczyt.
$actors = [
    'admin' => 'Administrator',
    'write' => 'Rola z zapisem EZD',
    'read'  => 'Rola z odczytem EZD',
    'owner' => 'Właściciel / referent koszulki',
    'sw'    => 'Współdzielenie: edycja',
    'sr'    => 'Współdzielenie: odczyt',
];

// y=pełny dostęp/tak, ro=tylko odczyt, n=brak. Ostatnia kolumna: przypis.
$caps = [
    ['Odczyt listy i treści koszulki',            ['admin'=>'y','write'=>'y','read'=>'y','owner'=>'y','sw'=>'y','sr'=>'y'], ''],
    ['Utworzenie nowej koszulki',                 ['admin'=>'y','write'=>'y','read'=>'y','owner'=>'y','sw'=>'y','sr'=>'y'], 'Może każdy zalogowany użytkownik z dostępem do modułu; twórca staje się właścicielem.'],
    ['Edycja treści koszulki i pism',             ['admin'=>'y','write'=>'y','read'=>'n','owner'=>'y','sw'=>'y','sr'=>'n'], ''],
    ['Dodawanie / pobieranie załączników',        ['admin'=>'y','write'=>'y','read'=>'ro','owner'=>'y','sw'=>'y','sr'=>'ro'], 'Odczyt dostępny wszędzie tam, gdzie jest dostęp do treści koszulki.'],
    ['Dekretacja / przekazanie sprawy',           ['admin'=>'y','write'=>'y','read'=>'n','owner'=>'y','sw'=>'y','sr'=>'n'], 'Wymaga dostępu z prawem zapisu do koszulki.'],
    ['Zmiana etapu / zamknięcie koszulki',        ['admin'=>'y','write'=>'y','read'=>'n','owner'=>'y','sw'=>'y','sr'=>'n'], ''],
    ['Edycja / ponowne otwarcie ZAMKNIĘTEJ',      ['admin'=>'y','write'=>'n','read'=>'n','owner'=>'n','sw'=>'n','sr'=>'n'], 'Koszulka domknięta jest zablokowana dla wszystkich poza administratorem.'],
    ['Zarządzanie współdzieleniem koszulki',      ['admin'=>'y','write'=>'y','read'=>'n','owner'=>'y','sw'=>'n','sr'=>'n'], 'Właściciel/twórca oraz rola z zapisem EZD.'],
    ['Rejestr korespondencji (RPW)',              ['admin'=>'y','write'=>'y','read'=>'y','owner'=>'y','sw'=>'y','sr'=>'y'], 'Wpis do RPW wymaga jedynie dostępu do modułu.'],
    ['Zarządzanie JRWA (segregatory)',            ['admin'=>'y','write'=>'n','read'=>'n','owner'=>'n','sw'=>'n','sr'=>'n'], 'Wyłącznie administrator.'],
    ['Zarządzanie teczkami (segregatorami)',      ['admin'=>'y','write'=>'n','read'=>'n','owner'=>'n','sw'=>'n','sr'=>'n'], 'Wyłącznie administrator.'],
];

// ── Status VPN ───────────────────────────────────────────────────────────────
$vpn_on   = org_setting('ezd_vpn_only') === '1';
$vpn_list = array_values(array_filter(array_map('trim', explode("\n", org_setting('ezd_vpn_allowlist'))), fn($l) => $l !== '' && !str_starts_with($l, '#')));

$org_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Organizacja');
$gen_at   = date('Y-m-d H:i');
$gen_by   = current_user()['name'] ?? '';

// ── Renderery ────────────────────────────────────────────────────────────────
function eam_flag(bool $on, string $letter, string $title): string {
    $cls = $on ? 'am-on' : 'am-off';
    return '<span class="am-flag ' . $cls . '" title="' . h($title) . '">' . $letter . '</span>';
}

function eam_cap_cell(string $v): string {
    if ($v === 'y')  return '<span class="eam-mark eam-yes" title="Pełny dostęp">✓</span>';
    if ($v === 'ro') return '<span class="eam-mark eam-ro" title="Tylko odczyt">O</span>';
    return '<span class="eam-mark eam-no" title="Brak">–</span>';
}

function eam_render_body(array $ctx): void {
    extract($ctx); // $roles, $ezd_role_perms, $ezd_accounts, $actors, $caps, $vpn_on, $vpn_list
    ?>
    <p class="am-legend">
      <strong>Model dostępu EZD.</strong> Poza uprawnieniami ról (poniżej) o dostępie do konkretnej
      koszulki decydują też relacje: <em>właściciel/referent</em>, <em>twórca</em> oraz
      <em>współdzielenie</em> (odczyt lub edycja). Administrator ma zawsze pełny dostęp; koszulka
      <strong>zamknięta</strong> jest edytowalna wyłącznie przez administratora.
    </p>

    <h2 class="am-h2">1. Uprawnienia ról do modułu „Wirtualne biurko"</h2>
    <table class="am-matrix">
      <thead>
        <tr>
          <th class="am-modcol">Rola</th>
          <th class="am-rolecol">Odczyt</th>
          <th class="am-rolecol">Zapis</th>
          <th class="am-rolecol">Usuwanie</th>
          <th class="am-rolecol">Zakres</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($roles as $r):
            $p = $ezd_role_perms[$r['name']];
            $is_admin_role = $r['name'] === 'admin';
        ?>
        <tr>
          <td class="am-modcol">
            <?= h($r['display_name']) ?> <span class="am-modcode"><?= h($r['name']) ?></span>
          </td>
          <td class="am-cell"><?= eam_flag($is_admin_role || $p['read'],  'O', 'Odczyt') ?></td>
          <td class="am-cell"><?= eam_flag($is_admin_role || $p['write'], 'Z', 'Zapis') ?></td>
          <td class="am-cell"><?= eam_flag($is_admin_role || $p['delete'],'U', 'Usuwanie') ?></td>
          <td class="am-cell">
            <?php if ($is_admin_role): ?><span class="eam-scope">wszystko</span>
            <?php elseif ($p['ezd_only']): ?><span class="eam-scope eam-scope-only">tylko EZD</span>
            <?php elseif ($p['read'] || $p['write']): ?><span class="eam-scope">EZD + system</span>
            <?php else: ?><span class="am-muted">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="am-note">Administrator ma pełny dostęp niezależnie od zapisanej konfiguracji roli. Edycja praw ról: <a href="<?= APP_URL ?>/tozsamosc/role.php">Role i uprawnienia</a>.</p>

    <h2 class="am-h2">2. Macierz czynności EZD × relacja do koszulki</h2>
    <table class="am-matrix">
      <thead>
        <tr>
          <th class="am-modcol">Czynność</th>
          <?php foreach ($actors as $lbl): ?>
          <th class="am-rolecol"><?= h($lbl) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($caps as $row):
            [$name, $vals, $note] = $row;
        ?>
        <tr>
          <td class="am-modcol">
            <?= h($name) ?>
            <?php if ($note): ?><span class="eam-cap-note"><?= h($note) ?></span><?php endif; ?>
          </td>
          <?php foreach ($actors as $k => $lbl): ?>
          <td class="am-cell"><?= eam_cap_cell($vals[$k] ?? 'n') ?></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="am-legend">
      <strong>Legenda:</strong>
      <span class="eam-mark eam-yes">✓</span> pełny dostęp &nbsp;
      <span class="eam-mark eam-ro">O</span> tylko odczyt &nbsp;
      <span class="eam-mark eam-no">–</span> brak. &nbsp;
      „Rola z zapisem/odczytem EZD" = konto z prawem <code>can_write</code>/<code>can_read</code> na module (np. edytor, użytkownik EZD).
    </p>

    <h2 class="am-h2">3. Dostęp tylko przez VPN</h2>
    <div class="am-vpn">
      <p class="am-vpn-status">
        Ograniczenie dostępu do modułu EZD wg adresu IP (VPN):
        <?php if ($vpn_on): ?>
          <strong class="am-vpn-on">WŁĄCZONE</strong>
        <?php else: ?>
          <strong class="am-vpn-off">WYŁĄCZONE</strong>
        <?php endif; ?>
        Konfiguracja: <em>Administracja → Ustawienia organizacji → Bezpieczeństwo</em>
        (<code>ezd_vpn_only</code>, <code>ezd_vpn_allowlist</code>).
      </p>
      <?php if ($vpn_on): ?>
      <p class="am-note">Dozwolona pula (<?= count($vpn_list) ?>):
        <?php foreach ($vpn_list as $c): ?><code><?= h($c) ?></code> <?php endforeach; ?>
      </p>
      <?php endif; ?>
      <ul class="am-vpn-list">
        <li><strong>Zakres blokady.</strong> Obejmuje wszystkie podstrony <code>/ezd/</code> — koszulki, pisma,
            rejestr korespondencji (RPW) oraz pobieranie/serwowanie plików — niezależnie od roli.</li>
        <li><strong>Break-glass.</strong> Konto <code>serwis@local</code> jest zawsze wykluczone z ograniczenia.</li>
        <li><strong>Konfiguracja spoza modułu.</strong> Administrator zmienia ustawienia z panelu głównego,
            więc blokada EZD nie zamyka mu drogi do jej wyłączenia.</li>
        <li><strong>Whitelista CIDR.</strong> Wpisuj zakresy puli VPN (np. <code>10.8.0.0/24</code>),
            a nie pojedyncze zmienne adresy; obsługiwane są też wildcard i IPv6.</li>
      </ul>
    </div>

    <h2 class="am-h2">4. Konta z dostępem do EZD</h2>
    <table class="am-accounts">
      <thead><tr><th>Rola</th><th>Konta (imię i nazwisko · e-mail)</th></tr></thead>
      <tbody>
        <?php foreach ($roles as $r):
            $list = $ezd_accounts[$r['name']] ?? [];
            if (!$list && !($r['name']==='admin')) continue;
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
                <li><?= h($u['name']) ?> <span class="am-muted">· <?= h($u['email']) ?></span>
                  <?php if (!$u['is_active']): ?><span class="am-inactive">(nieaktywne)</span><?php endif; ?>
                </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php
}

$ctx = compact('roles', 'ezd_role_perms', 'ezd_accounts', 'actors', 'caps', 'vpn_on', 'vpn_list');

$am_styles = <<<CSS
.am-meta{color:#666;font-size:.85rem;margin:.25rem 0 1rem}
.am-legend{font-size:.85rem;background:#f8f9fa;border:1px solid #e3e6ea;border-radius:6px;padding:.6rem .8rem;margin:.5rem 0 1.25rem}
.am-note{font-size:.8rem;color:#666;margin:.25rem 0 1rem}
.am-h2{font-size:1.05rem;margin:1.5rem 0 .5rem;border-bottom:2px solid #dee2e6;padding-bottom:.25rem}
.am-matrix,.am-accounts{width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:.5rem}
.am-matrix th,.am-matrix td,.am-accounts th,.am-accounts td{border:1px solid #d0d4d9;padding:.35rem .5rem;vertical-align:top}
.am-matrix thead th,.am-accounts thead th{background:#eef1f4;text-align:left;font-weight:600}
.am-matrix .am-rolecol{text-align:center}
.am-matrix .am-cell{text-align:center;white-space:nowrap}
.am-modcol{width:26%}
.am-rolecode,.am-modcode{display:block;font-family:monospace;font-size:.72rem;color:#888;font-weight:400}
.am-modcode{display:inline;margin-left:.25rem}
.am-flag{display:inline-block;width:18px;height:18px;line-height:18px;text-align:center;border-radius:3px;font-size:.7rem;font-weight:700}
.am-flag.am-on{background:#198754;color:#fff}
.am-flag.am-off{background:#e9ecef;color:#bcc1c7}
.eam-mark{display:inline-block;width:20px;height:20px;line-height:20px;text-align:center;border-radius:3px;font-size:.78rem;font-weight:700}
.eam-yes{background:#198754;color:#fff}
.eam-ro{background:#fff3cd;color:#8a6d1a;border:1px solid #ffe69c}
.eam-no{background:#f1f3f5;color:#bcc1c7}
.eam-scope{font-size:.72rem;color:#555}
.eam-scope-only{color:#b45309;font-weight:600}
.eam-cap-note{display:block;font-size:.72rem;color:#888;font-weight:400;margin-top:.15rem}
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
.am-vpn code,.am-vpn-status code,.am-note code{background:#f1f3f5;padding:.05rem .3rem;border-radius:3px;font-size:.8em}
CSS;

// ── Tryb WYDRUK ──────────────────────────────────────────────────────────────
if ($is_print) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<title>Macierz uprawnień EZD — <?= h($org_name) ?></title>
<style>
  @page { size: A4 landscape; margin: 12mm; }
  *{box-sizing:border-box}
  body{font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1a1a1a;margin:0;padding:18px}
  .am-print-hd{display:flex;justify-content:space-between;align-items:flex-end;border-bottom:3px solid #b45309;padding-bottom:.5rem;margin-bottom:.5rem}
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
    <h1>Macierz uprawnień — Wirtualne biurko (EZD)</h1>
    <div><?= h($org_name) ?></div>
  </div>
  <div class="am-meta">
    Wygenerowano: <?= h($gen_at) ?><?php if ($gen_by): ?> · przez <?= h($gen_by) ?><?php endif; ?>
  </div>
  <div class="am-print-actions">
    <button onclick="window.print()">Drukuj / Zapisz PDF</button>
  </div>
  <?php eam_render_body($ctx); ?>
  <script>
    window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 350); });
  </script>
</body>
</html>
    <?php
    exit;
}

// ── Tryb WIDOK ───────────────────────────────────────────────────────────────
$_tz_page_title = 'Macierz uprawnień EZD';
$PAGE_TITLE = $_tz_page_title;
$TZ_ACTIVE  = 'administracja';
include dirname(__DIR__) . '/tozsamosc/_head.php';
?>
<style>
.tz-wrap{max-width:1200px}
<?= $am_styles ?>
</style>

<div class="tz-h" style="margin-bottom:1rem">
  <h1><i class="bi bi-archive-fill" style="color:#b45309" aria-hidden="true"></i> Macierz uprawnień EZD</h1>
  <div class="d-flex gap-2 ms-auto flex-wrap">
    <a href="<?= APP_URL ?>/admin/access_matrix.php" class="tz-btn tz-btn--ghost btn-sm">
      <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i> Macierz ogólna
    </a>
    <a href="<?= APP_URL ?>/admin/org_settings.php?tab=security" class="tz-btn tz-btn--ghost btn-sm">
      <i class="bi bi-shield-lock" aria-hidden="true"></i> Ustawienia VPN
    </a>
    <a href="<?= h($SELF_URL) ?>?print=1" target="_blank" rel="noopener" class="tz-btn btn-sm">
      <i class="bi bi-printer" aria-hidden="true"></i> Drukuj / PDF
    </a>
  </div>
</div>
<p style="font-size:.84rem;color:var(--tz-muted);margin-bottom:1.2rem">Model dostępu modułu „Wirtualne biurko" — role, relacje do koszulki oraz status dostępu tylko przez VPN. Widok tylko do odczytu.</p>

<div class="tz-card" style="padding:0;overflow:hidden">
  <div class="card-body">
    <?php eam_render_body($ctx); ?>
  </div>
</div>

<?php include dirname(__DIR__) . '/tozsamosc/_foot.php'; ?>

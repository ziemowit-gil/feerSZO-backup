<?php
/**
 * Podgląd danych przed zapisem nowego porozumienia wolontariackiego.
 * GET  → wyświetla dane z sesji do weryfikacji, formularz z data-cpc="1"
 * POST → (po weryfikacji CPC przez interceptor w header.php) zapisuje do DB
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/cpc.php';
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';

require_role('admin', 'editor');
require_module_enabled('contract_wolontariat', 'Umowy wolontariackie');
cpc_migrate();

$TYPE  = 'wolontariat';
$TABLE = 'umowy_wolontariat';
define('_TERMINAL_STATUSES', ['zakończona', 'rozwiązana', 'anulowana']);

// ── Funkcja tworzenia konta portalu wolontariusza ────────────────────────────
function _review_provision_account(string $email, string $name, string $numer, ?string $m365_pass = null, ?string $m365_login = null): ?string {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    if (db_one("SELECT id FROM users WHERE email = ?", [$email])) return null;

    $plain = $m365_pass ?? substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(18))), 0, 12);
    $hash  = password_hash($plain, PASSWORD_BCRYPT);
    db_insert('users', [
        'name'       => $name ?: $email,
        'email'      => $email,
        'password'   => $hash,
        'role'       => 'viewer',
        'is_active'  => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $org  = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $body = '<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px">
<p>Witaj, <strong>' . htmlspecialchars($name) . '</strong>!</p>
<p>W związku z zawarciem porozumienia wolontariackiego <strong>' . htmlspecialchars($numer) . '</strong>
utworzono dla Ciebie konto w portalu organizacji.</p>
<p>Login: <strong>' . htmlspecialchars($email) . '</strong><br>
Hasło: <strong style="font-family:monospace;font-size:1.1em">' . htmlspecialchars($plain) . '</strong></p>
<p><a href="' . APP_URL . '/auth/login.php">Zaloguj się →</a></p>
</body></html>';
    approval_send_email($email, "Twoje konto w portalu wolontariusza — {$org}", $body);
    return $plain;
}

// ── Token i szkic z sesji ─────────────────────────────────────────────────────
$token = trim($_GET['token'] ?? $_POST['_token'] ?? '');
auth_start();
$draft = $token && isset($_SESSION['wolontariat_draft'][$token])
    ? $_SESSION['wolontariat_draft'][$token]
    : null;

if (!$draft) {
    flash_set('warning', 'Sesja podglądu wygasła lub token jest nieprawidłowy. Wypełnij formularz ponownie.');
    header('Location: add.php');
    exit;
}

// Stary szkic (>30 min) → unieważnij
if ((time() - ($draft['ts'] ?? 0)) > 1800) {
    unset($_SESSION['wolontariat_draft'][$token]);
    flash_set('warning', 'Sesja podglądu wygasła (30 min). Wypełnij formularz ponownie.');
    header('Location: add.php');
    exit;
}

$data             = $draft['data'];
$m365_create_mode = $draft['m365_create_mode'] ?? 'none';
$identity_type    = $draft['identity_type']    ?? 'pesel';
$uzasadnienie     = $draft['uzasadnienie']     ?? '';
$status_original  = $draft['status_original']  ?? '';
$_is_admin        = (current_user()['role'] ?? '') === 'admin';
$m365_enabled_local = (new M365Graph())->is_configured();

// ── POST: zapis po weryfikacji CPC ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    try {

    // Przypisz nr rejestru (odkładamy do momentu zapisu)
    assign_nr_rejestru($data);

    $id = db_insert($TABLE, $data);
    log_contract_action($TYPE, $id, current_user()['id'], 'create', 'Dodano: ' . ($data['numer_umowy'] ?? ''));

    // Uzasadnienie dla statusów terminalnych
    if (in_array($data['status'] ?? '', _TERMINAL_STATUSES, true) && !empty(trim($uzasadnienie))) {
        log_contract_action($TYPE, $id, current_user()['id'], 'note',
            'Uzasadnienie statusu „' . $data['status'] . '": ' . trim($uzasadnienie));
    }

    // ── M365 auto-tworzenie ──────────────────────────────────────────────────
    $m365_pass_created  = null;
    $m365_login_created = null;

    if ($m365_create_mode === 'auto' && $m365_enabled_local) {
        try {
            $graph              = new M365Graph();
            $m365_login_created = $graph->unique_login($data['imie_nazwisko'] ?? '');
            $m365_pass_created  = M365Graph::generate_password();
            $enabled            = m365_should_be_active($data);
            $m365user           = $graph->create_user($m365_login_created, $data['imie_nazwisko'] ?? '', $m365_pass_created, $enabled);

            $sku = m365_setting('m365_license_sku_id');
            $lic = 0;
            if ($sku) { $graph->assign_license($m365user['id'], $sku); $lic = 1; }

            db_update($TABLE, [
                'm365_konto'               => 1,
                'm365_login'               => $m365_login_created,
                'm365_user_id'             => $m365user['id'],
                'm365_konto_aktywne'       => $enabled ? 1 : 0,
                'm365_data_utworzenia'     => date('Y-m-d H:i:s'),
                'm365_licencja_przypisana' => $lic,
            ], $id);

            $_SESSION['m365_new_login'] = $m365_login_created;
            $_SESSION['m365_new_pass']  = $m365_pass_created;
            $_SESSION['m365_sent']      = false;

            log_contract_action($TYPE, $id, current_user()['id'], 'note', 'Automatycznie utworzono konto M365: ' . $m365_login_created);
        } catch (\Throwable $e) {
            flash_set('warning', 'Konto M365 nie zostało utworzone: ' . $e->getMessage());
        }
    }

    // ── Konto portalu wolontariusza ──────────────────────────────────────────
    if (!empty($data['email'])) {
        $plain = _review_provision_account(
            $data['email'],
            $data['imie_nazwisko'] ?? '',
            $data['numer_umowy']   ?? '',
            $m365_pass_created,
            $m365_login_created
        );
        if ($plain !== null) {
            $_SESSION['new_portal_account'] = [
                'email'    => $data['email'],
                'password' => $plain,
                'name'     => $data['imie_nazwisko'] ?? '',
            ];
            log_contract_action($TYPE, $id, current_user()['id'], 'note', 'Utworzono konto portalu i wysłano hasło na ' . $data['email']);
        }
    }

    // ── Konto rodzica/opiekuna ───────────────────────────────────────────────
    if (!empty($data['rodzic_email']) && !empty($data['niepelnoletni'])) {
        $rodzic_plain = _review_provision_account(
            $data['rodzic_email'],
            $data['rodzic_imie_nazwisko'] ?? '',
            $data['numer_umowy'] ?? ''
        );
        if ($rodzic_plain !== null) {
            log_contract_action($TYPE, $id, current_user()['id'], 'note', 'Utworzono konto rodzica/opiekuna: ' . $data['rodzic_email']);
        }
    }

    // Auto-uzupełnienie daty urodzenia z PESEL
    if (!empty($data['pesel']) && empty($data['data_urodzenia'])) {
        $bd = pesel_to_birthdate($data['pesel']);
        if ($bd) db_update($TABLE, ['data_urodzenia' => $bd], $id);
    }

    // Przekazanie do akceptacji
    if ($status_original === 'projekt') {
        submit_for_approval($TYPE, $id, (int)current_user()['id'], $data['numer_umowy'] ?? '');
        flash_set('success', 'Porozumienie wolontariackie zostało dodane i przekazane do akceptacji.');
    } else {
        flash_set('success', 'Porozumienie wolontariackie zostało dodane.');
    }

    unset($_SESSION['wolontariat_draft'][$token]);
    header('Location: ' . APP_URL . '/contracts/' . $TYPE . '/view.php?id=' . $id);
    exit;

    } catch (\Throwable $e) {
        http_response_code(500);
        $PAGE_TITLE = 'Błąd zapisu';
        include dirname(dirname(__DIR__)) . '/includes/header.php';
        echo '<div class="container mt-4"><div class="alert alert-danger"><strong>Błąd zapisu porozumienia:</strong><br>'
            . '<code>' . h($e->getMessage()) . '</code>'
            . '<br><small class="text-muted">' . h($e->getFile() . ':' . $e->getLine()) . '</small>'
            . '</div>'
            . '<a href="add.php" class="btn btn-secondary">Wróć do formularza</a></div>';
        include dirname(dirname(__DIR__)) . '/includes/footer.php';
        exit;
    }
}

// ── GET: podgląd danych ───────────────────────────────────────────────────────
$_is_pesel = $identity_type !== 'foreigner';

// Formatowanie PESEL z grupowaniem
function _pesel_groups(string $p): string {
    if (strlen($p) !== 11) return $p;
    return $p[0].$p[1] . ' ' . $p[2].$p[3] . ' ' . $p[4].$p[5]
         . '   ' . $p[6].$p[7] . ' ' . $p[8].$p[9] . ' ' . $p[10];
}

function _fmt_date(?string $v): string {
    if (!$v) return '—';
    $m = [];
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ? "{$m[3]}.{$m[2]}.{$m[1]}" : $v;
}

// Buduj CPC meta (na serwerze — czyste, bez JS)
$_cpc_id_row = $_is_pesel
    ? ['Identyfikacja', 'PESEL: ' . ($data['pesel'] ?? '—')]
    : ['Identyfikacja', ucfirst($data['id_document_type'] ?? 'dokument') . ': ' . ($data['id_document_number'] ?? '—')];

$_period = _fmt_date($data['data_rozpoczecia'] ?? '')
         . ' – '
         . (!empty($data['bezterminowa']) ? '∞ bezterminowa' : _fmt_date($data['data_zakonczenia'] ?? ''));

$_cpc_meta = json_encode(['rows' => [
    ['Typ operacji',   'Rejestracja nowej umowy wolontariatu'],
    ['Wolontariusz',   $data['imie_nazwisko']  ?? '—'],
    $_cpc_id_row,
    ['Numer umowy',    $data['numer_umowy']    ?? '—'],
    ['Opiekun',        $data['opiekun']        ?? '—'],
    ['Okres',          $_period],
]]);

$PAGE_TITLE = 'Sprawdzanie danych — ' . h($data['numer_umowy'] ?? 'porozumienie');
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-2">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="list.php">Wolontariat</a></li>
    <li class="breadcrumb-item"><a href="add.php">Nowe porozumienie</a></li>
    <li class="breadcrumb-item active">Sprawdzanie danych</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="rounded-circle bg-warning bg-opacity-15 d-flex align-items-center justify-content-center flex-shrink-0"
       style="width:48px;height:48px">
    <i class="bi bi-clipboard2-check-fill text-warning fs-4"></i>
  </div>
  <div>
    <h4 class="mb-0">Sprawdź dane przed zapisem</h4>
    <div class="text-muted small">Zweryfikuj poniższe informacje — po potwierdzeniu zostaną zapisane do bazy</div>
  </div>
</div>

<div class="alert alert-warning d-flex gap-2 py-2 mb-4">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <span>Sprawdź <strong>dokładnie</strong> numer <?= $_is_pesel ? 'PESEL' : 'dokumentu tożsamości' ?> oraz okres umowy. Błędy wymagają korekty dokumentu.</span>
</div>

<div class="row g-4">

  <!-- Lewa kolumna: dane wolontariusza + umowy -->
  <div class="col-lg-8">

    <!-- Identyfikacja -->
    <div class="card shadow-sm mb-3 border-warning">
      <div class="card-body text-center py-4">
        <?php if ($_is_pesel): ?>
          <div class="text-muted small text-uppercase fw-semibold mb-1 tracking-wide">PESEL</div>
          <div class="fw-bold font-monospace <?= empty($data['pesel']) ? 'text-danger' : 'text-dark' ?>"
               style="font-size:2.2rem;letter-spacing:.18em">
            <?= $data['pesel'] ? h(_pesel_groups($data['pesel'])) : '— brak PESEL —' ?>
          </div>
          <div class="text-muted small mt-1">
            <?= $data['pesel'] ? 'Sprawdź każdą cyfrę.' : '<span class="text-danger">PESEL nie został wpisany!</span>' ?>
          </div>
        <?php else: ?>
          <div class="text-muted small text-uppercase fw-semibold mb-1">
            <?= h(ucfirst($data['id_document_type'] ?? 'Dokument tożsamości')) ?>
          </div>
          <div class="fw-bold font-monospace text-dark" style="font-size:1.8rem;letter-spacing:.1em">
            <?= h($data['id_document_number'] ?? '—') ?>
          </div>
          <div class="text-muted small mt-1">Sprawdź serię i numer dokumentu.</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Wolontariusz -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold py-2">
        <i class="bi bi-person me-2 text-primary"></i>Wolontariusz
      </div>
      <div class="card-body p-0">
        <table class="table table-sm table-bordered mb-0" style="font-size:.875rem">
          <tbody>
            <tr><th class="table-light ps-3" style="width:36%">Imię i nazwisko</th>
                <td class="fw-semibold ps-3"><?= h($data['imie_nazwisko'] ?? '—') ?></td></tr>
            <tr><th class="table-light ps-3">Data urodzenia</th>
                <td class="ps-3"><?= h(_fmt_date($data['data_urodzenia'] ?? '')) ?></td></tr>
            <tr><th class="table-light ps-3">E-mail</th>
                <td class="ps-3"><?= h($data['email'] ?? '—') ?></td></tr>
            <tr><th class="table-light ps-3">Telefon</th>
                <td class="ps-3"><?= h($data['telefon'] ?? '—') ?></td></tr>
            <tr><th class="table-light ps-3">Adres</th>
                <td class="ps-3">
                  <?php
                  // Adres ze strukturalnych pól
                  $a_s = trim($data['addr_street'] ?? '');
                  $a_h = trim($data['addr_house']  ?? '');
                  $a_f = trim($data['addr_flat']   ?? '');
                  $a_p = trim($data['addr_postal'] ?? '');
                  $a_c = trim($data['addr_city']   ?? '');
                  $l1 = trim("$a_s $a_h" . ($a_f ? "/$a_f" : ''));
                  $l2 = trim("$a_p $a_c");
                  $adres_str = implode(', ', array_filter([$l1, $l2])) ?: ($data['adres'] ?? '—');
                  echo h($adres_str);
                  ?>
                </td></tr>
            <?php if (!empty($data['niepelnoletni'])): ?>
            <tr><th class="table-light ps-3">Opiekun prawny</th>
                <td class="ps-3"><?= h($data['rodzic_imie_nazwisko'] ?? '—') ?>
                  <?php if ($data['rodzic_email'] ?? ''): ?>
                    <span class="text-muted small ms-1"><?= h($data['rodzic_email']) ?></span>
                  <?php endif; ?>
                </td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Umowa -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold py-2">
        <i class="bi bi-file-earmark-text me-2 text-primary"></i>Porozumienie
      </div>
      <div class="card-body p-0">
        <table class="table table-sm table-bordered mb-0" style="font-size:.875rem">
          <tbody>
            <tr><th class="table-light ps-3" style="width:36%">Numer umowy</th>
                <td class="font-monospace fw-semibold ps-3"><?= h($data['numer_umowy'] ?? '—') ?></td></tr>
            <tr><th class="table-light ps-3">Status</th>
                <td class="ps-3"><?= h($data['status'] ?? '—') ?></td></tr>
            <tr><th class="table-light ps-3">Data zawarcia</th>
                <td class="ps-3"><?= h(_fmt_date($data['data_zawarcia'] ?? '')) ?></td></tr>
            <tr><th class="table-light ps-3">Okres</th>
                <td class="ps-3 fw-semibold"><?= h($_period) ?></td></tr>
            <tr><th class="table-light ps-3">Opiekun (edytor)</th>
                <td class="ps-3"><?= h($data['opiekun'] ?? '—')
                    . (!empty($data['guardian_initials']) ? ' <span class="badge bg-primary-subtle text-primary border border-primary-subtle ms-1">' . h($data['guardian_initials']) . '</span>' : '') ?></td></tr>
            <?php if ($data['projekt_program'] ?? ''): ?>
            <tr><th class="table-light ps-3">Projekt / program</th>
                <td class="ps-3"><?= h($data['projekt_program']) ?></td></tr>
            <?php endif; ?>
            <?php if ($data['miejsce_wolontariatu'] ?? ''): ?>
            <tr><th class="table-light ps-3">Miejsce wolontariatu</th>
                <td class="ps-3"><?= h($data['miejsce_wolontariatu']) ?></td></tr>
            <?php endif; ?>
            <?php if ($data['plik_umowy'] ?? ''): ?>
            <tr><th class="table-light ps-3">Plik porozumienia</th>
                <td class="ps-3"><i class="bi bi-file-earmark-pdf text-danger me-1"></i><?= h(basename($data['plik_umowy'])) ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div><!-- /col-lg-8 -->

  <!-- Prawa kolumna: akcje -->
  <div class="col-lg-4">
    <div class="card shadow-sm border-success sticky-top" style="top:80px">
      <div class="card-header bg-success-subtle border-success fw-semibold py-2">
        <i class="bi bi-shield-check me-2 text-success"></i>Autoryzacja zapisu
      </div>
      <div class="card-body">
        <p class="small text-muted mb-3">
          Jeśli dane są poprawne, kliknij „Zapisz". Zostaniesz poproszony o potwierdzenie kodem CPC.
        </p>

        <form method="post"
              data-cpc="1"
              data-cpc-meta='<?= h($_cpc_meta) ?>'
              data-cpc-saving="Zapisuję porozumienie wolontariackie…">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="_token" value="<?= h($token) ?>">

          <div class="d-grid gap-2">
            <button type="submit" class="btn btn-success btn-lg">
              <i class="bi bi-check-lg me-1"></i>Dane poprawne — Zapisz
            </button>
            <a href="add.php" class="btn btn-outline-secondary">
              <i class="bi bi-arrow-left me-1"></i>Wróć i popraw
            </a>
          </div>
        </form>
      </div>
      <div class="card-footer text-muted small py-2">
        <i class="bi bi-clock me-1"></i>Sesja ważna 30 min
      </div>
    </div>
  </div>

</div><!-- /row -->

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

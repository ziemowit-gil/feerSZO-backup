<?php
/**
 * karty30/admin/m365.php — Microsoft 365 dla K30.
 *
 * Osobny tenant M365 (odrębne dane niż tenant głównej org).
 * Funkcje:
 *   - Konfiguracja połączenia (tenant_id, client_id, client_secret, domena)
 *   - Tworzenie kont M365 dla beneficjentów K30
 *     * Login: imię.nazwisko@domena (deduplikacja)
 *     * ID numeryczny: losowy z zakresu 1010–9000 (zapisany jako employeeId)
 *     * Hasło proste, losowe (słowo + cyfry)
 *     * Przypisanie licencji z listy dostępnych w tenancie
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();
if (!is_admin()) { flash_set('danger','Tylko administrator.'); header('Location: ../index.php'); exit; }

$PAGE_TITLE = 'M365 — Karty 30';

// ── Ustawienia K30-M365 (osobna przestrzeń kluczy) ───────────────────────────
function k30_m365_setting(string $key, string $default = ''): string {
    try {
        $r = db_one("SELECT value FROM settings WHERE key_=?", ['k30_m365_'.$key]);
        return $r['value'] ?? $default;
    } catch (\Throwable $e) { return $default; }
}
function k30_m365_save(string $key, string $value): void {
    $fk = 'k30_m365_'.$key;
    try {
        db()->prepare("INSERT INTO settings(key_,value) VALUES(?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
           ->execute([$fk, $value]);
    } catch (\Throwable $e) {
        $ex = db_one("SELECT key_ FROM settings WHERE key_=?", [$fk]);
        if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value,$fk]);
        else     db_insert('settings', ['key_'=>$fk,'value'=>$value]);
    }
}

/**
 * Tworzy instancję M365Graph dla K30.
 *
 * Tryb "własny" (use_own=1): oddzielne dane tenant/client/secret/domena z k30_m365_* settings.
 * Tryb "główny" (use_own=0, domyślny): używa produkcyjnych ustawień M365 głównej organizacji
 *   (m365_tenant_id, m365_graph_client_id, m365_graph_client_secret, m365_domain),
 *   nadpisując tylko domenę jeśli ustawiona k30_m365_domain.
 */
function k30_m365(): M365Graph {
    $use_own = (bool)k30_m365_setting('use_own_tenant');

    if ($use_own) {
        return new M365Graph([
            'tenant_id'     => k30_m365_setting('tenant_id'),
            'client_id'     => k30_m365_setting('client_id'),
            'client_secret' => k30_m365_setting('client_secret'),
            'domain'        => k30_m365_setting('domain'),
        ]);
    }

    // Używaj głównych ustawień produkcyjnych M365
    $domain_override = k30_m365_setting('domain');
    return new M365Graph([
        'tenant_id'     => m365_setting('m365_tenant_id'),
        'client_id'     => m365_setting('m365_graph_client_id'),
        'client_secret' => m365_setting('m365_graph_client_secret'),
        'domain'        => $domain_override ?: m365_setting('m365_domain'),
    ]);
}

/**
 * Generuje proste, losowe hasło: Słowo + liczba 2-cyfrowa + znak specjalny.
 * Format np. Kot47! — łatwe do zapamiętania, spełnia wymogi M365.
 */
function k30_simple_password(): string {
    $words = ['Kot','Pies','Dom','Las','Gaz','Rok','Nos','Byk','Lis','Dąb',
              'Mak','Sad','Rak','Tur','Gęś','Żuk','Jaw','Kos','Szcz','Żal'];
    $word  = $words[random_int(0, count($words)-1)];
    $num   = random_int(10, 99);
    $spec  = ['!','@','#','%'][random_int(0,3)];
    return $word . $num . $spec;
}

/**
 * Generuje wolny losowy ID z zakresu 1010–9000.
 * Zapisany jako employeeId w M365 i k30_clients.m365_employee_id.
 */
function k30_random_employee_id(): int {
    $used = [];
    try {
        $rows = db_all("SELECT m365_employee_id FROM k30_clients WHERE m365_employee_id IS NOT NULL");
        $used = array_column($rows, 'm365_employee_id');
    } catch (\Throwable $e) {}

    $max_attempts = 500;
    for ($i = 0; $i < $max_attempts; $i++) {
        $id = random_int(1010, 9000);
        if (!in_array((string)$id, $used)) return $id;
    }
    throw new RuntimeException('Wyczerpano zakres ID 1010–9000.');
}

// Migracja kolumn k30_clients dla M365
try { db()->exec("ALTER TABLE k30_clients ADD COLUMN m365_user_id       TEXT"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE k30_clients ADD COLUMN m365_login         TEXT"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE k30_clients ADD COLUMN m365_employee_id   TEXT"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE k30_clients ADD COLUMN m365_license_sku   TEXT"); } catch (\Throwable $e) {}
try { db()->exec("ALTER TABLE k30_clients ADD COLUMN m365_provisioned_at DATETIME"); } catch (\Throwable $e) {}

/**
 * Tłumaczy surowy błąd Azure AD / Graph na czytelny komunikat PL
 * z podpowiedzią co zrobić.
 */
function k30_m365_friendly_error(string $raw): string {
    // Wyciągnij JSON z wiadomości jeśli jest
    $json = null;
    if (preg_match('/\{.*\}/s', $raw, $m)) {
        $json = json_decode($m[0], true);
    }
    $code = $json['error_codes'][0] ?? $json['error'] ?? '';
    $desc = $json['error_description'] ?? $raw;

    $hints = [
        700016 => 'Aplikacja (Client ID) nie istnieje w podanym tenancie. Sprawdź:
            <ul class="mb-0 mt-1">
              <li>Czy <strong>Tenant ID</strong> odpowiada katalogowi, w którym zarejestrowałeś aplikację w Azure Portal?</li>
              <li>Otwórz <a href="https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/Overview" target="_blank">Azure AD → Przegląd</a> i skopiuj <strong>Identyfikator katalogu (dzierżawcy)</strong>.</li>
              <li>Upewnij się że używasz Tenant ID katalogu K30, nie głównego tenanta organizacji.</li>
            </ul>',
        700011 => 'Brak zgody administratora. W Azure Portal uruchom <em>Grant admin consent</em> dla tej aplikacji.',
        70011  => 'Nieprawidłowy scope. Aplikacja powinna używać <code>https://graph.microsoft.com/.default</code>.',
        'unauthorized_client' => 'Klient nie jest autoryzowany. Sprawdź Tenant ID i upewnij się że app registration jest w tym samym katalogu.',
        'invalid_client' => 'Nieprawidłowy Client Secret — być może wygasł. Wygeneruj nowy secret w Azure Portal.',
        'invalid_request' => 'Nieprawidłowe żądanie. Sprawdź Client ID i Tenant ID.',
    ];

    $hint = $hints[(string)$code] ?? null;

    if ($hint) {
        return "<strong>Błąd Azure AD [{$code}]:</strong> " . $hint;
    }

    // Skróć surowy komunikat jeśli brak dopasowania
    $short = strip_tags($desc);
    $short = preg_replace('/Trace ID:.*$/s', '', $short);
    return '<strong>Błąd M365:</strong> ' . h(trim($short));
}

// ── POST ──────────────────────────────────────────────────────────────────────
$action = '';
$result = null;
$graph  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Zapis konfiguracji + domyślna licencja
    if ($action === 'save_config') {
        k30_m365_save('use_own_tenant', isset($_POST['use_own_tenant']) ? '1' : '0');
        k30_m365_save('tenant_id',     trim($_POST['tenant_id']     ?? ''));
        k30_m365_save('client_id',     trim($_POST['client_id']     ?? ''));
        k30_m365_save('domain',        trim($_POST['domain']        ?? ''));
        if (trim($_POST['default_sku'] ?? '') !== '') {
            k30_m365_save('default_sku', trim($_POST['default_sku']));
        }
        if (trim($_POST['client_secret'] ?? '') !== '') {
            k30_m365_save('client_secret', trim($_POST['client_secret']));
        }
        flash_set('success', 'Konfiguracja K30-M365 zapisana.');
        header('Location: m365.php'); exit;
    }

    // Test połączenia
    if ($action === 'test') {
        try {
            $graph  = k30_m365();
            $result    = $graph->test_connection();
            $org_label = $result['org_name'] ?? '?';
            $doms      = implode(', ', array_slice($result['domains'] ?? [], 0, 3));
            flash_set('success', 'Połączenie z M365 K30 działa. Organizacja: ' . h($org_label) . ($doms ? ' · ' . h($doms) : ''));
        } catch (\Throwable $e) {
            flash_set('danger', k30_m365_friendly_error($e->getMessage()));
        }
        header('Location: m365.php#test'); exit;
    }

    // Utwórz konto M365 dla beneficjenta
    if ($action === 'provision') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $sku_id    = trim($_POST['sku_id'] ?? '') ?: k30_m365_setting('default_sku');
        $client    = $client_id ? db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]) : null;

        if (!$client) { flash_set('danger','Wybierz beneficjenta.'); header('Location: m365.php#provision'); exit; }
        if (!$sku_id) { flash_set('danger','Wybierz licencję lub ustaw domyślną w konfiguracji.'); header('Location: m365.php#provision'); exit; }

        try {
            $g        = k30_m365();
            $domain   = k30_m365_setting('domain');
            $emp_id   = k30_random_employee_id();
            // Login = ID cyfrowe @ domena (np. 4271@beneficjenci.org.pl)
            $login    = $emp_id . '@' . $domain;
            $password = k30_simple_password();
            $user_resp = $g->create_user_with_employee_id($login, $client['name'], $password, (string)$emp_id);
            $user_id   = $user_resp['id'] ?? '';
            if (!$user_id) throw new RuntimeException('Brak ID nowego użytkownika w odpowiedzi API.');

            // Przypisz licencję
            $g->assign_license($user_id, $sku_id);

            // Zapisz w bazie
            db()->prepare(
                "UPDATE k30_clients SET
                    m365_user_id=?, m365_login=?, m365_employee_id=?,
                    m365_license_sku=?, m365_provisioned_at=datetime('now')
                 WHERE id=?"
            )->execute([$user_id, $login, (string)$emp_id, $sku_id, $client_id]);

            flash_set('success',
                "Konto M365 utworzone!\n" .
                "Login: {$login} | ID: {$emp_id} | Hasło: {$password}\n" .
                "Zapisz hasło — nie będzie widoczne ponownie!"
            );
            // Zapisz hasło tymczasowo w sesji do wyświetlenia
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['k30_m365_last_pass'] = [
                'login'    => $login,
                'password' => $password,
                'emp_id'   => $emp_id,
                'name'     => $client['name'],
                'ts'       => time(),
            ];
        } catch (\Throwable $e) {
            flash_set('danger', k30_m365_friendly_error($e->getMessage()));
        }
        header('Location: m365.php#provision'); exit;
    }

    // Zbiorcze tworzenie kont M365 dla wielu beneficjentów naraz
    if ($action === 'provision_bulk') {
        $ids      = array_values(array_unique(array_map('intval', (array)($_POST['client_ids'] ?? []))));
        $sku_id   = trim($_POST['sku_id'] ?? '') ?: k30_m365_setting('default_sku');
        $password = trim($_POST['bulk_password'] ?? '') ?: k30_simple_password();
        if (!$ids)    { flash_set('danger','Zaznacz co najmniej jednego beneficjenta.'); header('Location: m365.php#provision'); exit; }
        if (!$sku_id) { flash_set('danger','Wybierz licencję lub ustaw domyślną w konfiguracji.'); header('Location: m365.php#provision'); exit; }

        try {
            $g      = k30_m365();
            $g->test_connection(); // wyłap błąd tenanta przed pętlą
            $domain = k30_m365_setting('domain');
        } catch (\Throwable $e) {
            flash_set('danger', k30_m365_friendly_error($e->getMessage()));
            header('Location: m365.php#provision'); exit;
        }

        $created = []; $errors_bulk = [];
        foreach ($ids as $cid) {
            $client = db_one("SELECT * FROM k30_clients WHERE id=?", [$cid]);
            if (!$client) { continue; }
            if (!empty($client['m365_user_id'])) { $errors_bulk[] = $client['name'] . ' — ma już konto, pominięto'; continue; }
            try {
                $emp_id  = k30_random_employee_id();
                $login   = $emp_id . '@' . $domain;
                $user    = $g->create_user_with_employee_id($login, $client['name'], $password, (string)$emp_id);
                $user_id = $user['id'] ?? '';
                if (!$user_id) throw new RuntimeException('Brak ID nowego użytkownika w odpowiedzi API.');
                $g->assign_license($user_id, $sku_id);
                db()->prepare(
                    "UPDATE k30_clients SET
                        m365_user_id=?, m365_login=?, m365_employee_id=?,
                        m365_license_sku=?, m365_provisioned_at=datetime('now')
                     WHERE id=?"
                )->execute([$user_id, $login, (string)$emp_id, $sku_id, $cid]);
                $created[] = ['name' => $client['name'], 'login' => $login, 'emp_id' => $emp_id];
            } catch (\Throwable $ei) {
                $errors_bulk[] = $client['name'] . ' — ' . k30_m365_friendly_error($ei->getMessage());
            }
        }

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['k30_m365_bulk_result'] = [
            'created'  => $created,
            'errors'   => $errors_bulk,
            'password' => $password,
            'ts'       => time(),
        ];
        flash_set('success', 'Zbiorczo utworzono kont: ' . count($created) . ' dla beneficjentów.');
        header('Location: m365.php#provision'); exit;
    }

    // Resetuj hasło
    if ($action === 'reset_password') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $client    = $client_id ? db_one("SELECT * FROM k30_clients WHERE id=?", [$client_id]) : null;
        if (!$client || !$client['m365_user_id']) { flash_set('danger','Brak konta M365 dla tego beneficjenta.'); header('Location: m365.php'); exit; }
        try {
            $g        = k30_m365();
            $password = k30_simple_password();
            $g->set_password($client['m365_user_id'], $password);
            if (session_status() !== PHP_SESSION_ACTIVE) session_start();
            $_SESSION['k30_m365_last_pass'] = [
                'login'    => $client['m365_login'],
                'password' => $password,
                'emp_id'   => $client['m365_employee_id'] ?? '',
                'name'     => $client['name'],
                'ts'       => time(),
            ];
            flash_set('success', 'Hasło zresetowane dla ' . $client['name']);
        } catch (\Throwable $e) {
            flash_set('danger', k30_m365_friendly_error($e->getMessage()));
        }
        header('Location: m365.php#provision'); exit;
    }

    // Masowe tworzenie kont — prefix{N}
    if ($action === 'bulk_create') {
        $prefix   = preg_replace('/[^a-z0-9._-]/i', '', trim($_POST['bulk_prefix'] ?? ''));
        $count    = max(1, min(50, (int)($_POST['bulk_count'] ?? 1)));
        $start    = max(1, (int)($_POST['bulk_start'] ?? 1));
        $sku_id   = trim($_POST['sku_id'] ?? '') ?: k30_m365_setting('default_sku');
        $password = trim($_POST['bulk_password'] ?? '') ?: k30_simple_password();
        $domain   = k30_m365_setting('domain');

        if (!$prefix) { flash_set('danger','Podaj prefix loginu.'); header('Location: m365.php#bulk'); exit; }

        $created = []; $errors_bulk = [];
        try {
            $g = k30_m365();
            // Sprawdź połączenie przed pętlą — wyłap błąd tenanta od razu
            $g->test_connection();
        } catch (\Throwable $e) {
            flash_set('danger', k30_m365_friendly_error($e->getMessage()));
            header('Location: m365.php#bulk'); exit;
        }

        for ($i = $start; $i < $start + $count; $i++) {
            $login   = strtolower($prefix) . $i . '@' . $domain;
            $display = $prefix . $i;
            try {
                if ($g->login_exists($login)) {
                    $errors_bulk[] = "{$login} — już istnieje, pominięto";
                    continue;
                }
                $emp_id  = k30_random_employee_id();
                $user    = $g->create_user_with_employee_id($login, $display, $password, (string)$emp_id);
                $user_id = $user['id'] ?? '';
                if ($sku_id && $user_id) {
                    try { $g->assign_license($user_id, $sku_id); } catch (\Throwable $el) {}
                }
                $created[] = ['login' => $login, 'emp_id' => $emp_id];
            } catch (\Throwable $ei) {
                $errors_bulk[] = "{$login} — " . k30_m365_friendly_error($ei->getMessage());
            }
        }

        if (session_status() !== PHP_SESSION_ACTIVE) session_start();
        $_SESSION['k30_m365_bulk_result'] = [
            'created'  => $created,
            'errors'   => $errors_bulk,
            'password' => $password,
            'ts'       => time(),
        ];
        flash_set('success', 'Masowe tworzenie: ' . count($created) . ' kont utworzonych.');
        header('Location: m365.php#bulk'); exit;
    }
}

// Wczytaj config i dane
$cfg_use_own = (bool)k30_m365_setting('use_own_tenant');
$cfg_tenant  = k30_m365_setting('tenant_id');
$cfg_client  = k30_m365_setting('client_id');
$cfg_domain  = k30_m365_setting('domain');
$cfg_has_sec = !empty(k30_m365_setting('client_secret'));

// W trybie "główny" — sprawdź czy główna M365 jest skonfigurowana
$main_m365_ok = !empty(m365_setting('m365_tenant_id'))
             && !empty(m365_setting('m365_graph_client_id'))
             && !empty(m365_setting('m365_graph_client_secret'));

$is_conf = $cfg_use_own
    ? (!empty($cfg_tenant) && !empty($cfg_client) && $cfg_has_sec)
    : $main_m365_ok;

// Licencje (jeśli skonfigurowane)
$skus = [];
if ($is_conf) {
    try { $skus = k30_m365()->get_subscribed_skus(); } catch (\Throwable $e) {}
}

$cfg_default_sku = k30_m365_setting('default_sku');

// Czytelne nazwy licencji M365 (skuPartNumber → label PL)
function k30_sku_label(string $part): string {
    static $map = [
        'AAD_PREMIUM'                           => 'Azure AD Premium P1',
        'AAD_PREMIUM_P2'                        => 'Azure AD Premium P2',
        'DEVELOPERPACK'                         => 'Microsoft 365 Developer',
        'ENTERPRISEPACK'                        => 'Office 365 E3',
        'ENTERPRISEPREMIUM'                     => 'Office 365 E5',
        'EXCHANGESTANDARD'                      => 'Exchange Online (Plan 1)',
        'EXCHANGEENTERPRISE'                    => 'Exchange Online (Plan 2)',
        'FLOW_FREE'                             => 'Power Automate Free',
        'MICROSOFT_BUSINESS_CENTER'             => 'Microsoft Business Center',
        'MICROSOFT_REMOTE_DESKTOP'              => 'Remote Desktop',
        'Microsoft_Teams_Audio_Conferencing'    => 'Teams — Audiokonferencje',
        'Microsoft_Teams_Exploratory'           => 'Teams Exploratory',
        'O365_BUSINESS'                         => 'Microsoft 365 Apps for Business',
        'O365_BUSINESS_ESSENTIALS'              => 'Microsoft 365 Business Basic',
        'O365_BUSINESS_PREMIUM'                 => 'Microsoft 365 Business Standard',
        'OFFICESUBSCRIPTION'                    => 'Microsoft 365 Apps',
        'POWER_BI_STANDARD'                     => 'Power BI (bezpłatny)',
        'POWER_BI_PRO'                          => 'Power BI Pro',
        'PROJECTESSENTIALS'                     => 'Project Online Essentials',
        'PROJECTPREMIUM'                        => 'Project Plan 5',
        'SMB_BUSINESS'                          => 'Microsoft 365 Apps for Business',
        'SMB_BUSINESS_ESSENTIALS'               => 'Microsoft 365 Business Basic',
        'SMB_BUSINESS_PREMIUM'                  => 'Microsoft 365 Business Standard',
        'SPB'                                   => 'Microsoft 365 Business Premium',
        'SPZA_IW'                               => 'App Connect IW',
        'STANDARDPACK'                          => 'Office 365 E1',
        'TEAMS_EXPLORATORY'                     => 'Teams Exploratory',
        'VISIOCLIENT'                           => 'Visio Plan 2',
        'WINDOWS_STORE'                         => 'Windows Store for Business',
        'MCOPSTN1'                              => 'Teams Calling Plan (krajowy)',
        'MCOPSTN2'                              => 'Teams Calling Plan (między.)',
    ];
    // Usuń prefix TENANT_ID: jeśli istnieje
    $clean = preg_replace('/^[A-Z0-9]+:/', '', $part);
    return $map[$clean] ?? $map[$part] ?? $clean;
}

// Klienci z / bez konta M365
$clients_with    = db_all("SELECT * FROM k30_clients WHERE m365_user_id IS NOT NULL AND m365_user_id!='' ORDER BY name");
$clients_without = db_all("SELECT * FROM k30_clients WHERE (m365_user_id IS NULL OR m365_user_id='') ORDER BY name");

// Ostatnio wygenerowane hasło (z sesji, ważne 5 min)
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$last_pass = null;
if (!empty($_SESSION['k30_m365_last_pass']) && (time() - ($_SESSION['k30_m365_last_pass']['ts']??0)) < 300) {
    $last_pass = $_SESSION['k30_m365_last_pass'];
}
$bulk_result = null;
if (!empty($_SESSION['k30_m365_bulk_result']) && (time() - ($_SESSION['k30_m365_bulk_result']['ts']??0)) < 600) {
    $bulk_result = $_SESSION['k30_m365_bulk_result'];
    unset($_SESSION['k30_m365_bulk_result']);
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item active">Microsoft 365</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold">
    <i class="bi bi-microsoft text-primary me-2"></i>Microsoft 365 — Karty 30
  </h4>
  <span class="badge <?= $is_conf ? 'bg-success' : 'bg-warning text-dark' ?>">
    <?= $is_conf ? 'Skonfigurowane' : 'Wymaga konfiguracji' ?>
  </span>
</div>

<?= flash_html() ?>

<!-- Ostatnio wygenerowane hasło -->
<?php if ($last_pass): ?>
<div class="alert alert-warning border-warning d-flex gap-3 align-items-start mb-4 shadow-sm">
  <i class="bi bi-key-fill flex-shrink-0 fs-4" style="color:#b45309"></i>
  <div class="flex-grow-1">
    <div class="fw-bold mb-1">⚠ Dane dostępowe — ZAPISZ TERAZ, nie będą widoczne ponownie!</div>
    <table class="table table-sm table-bordered mb-0" style="max-width:400px;font-size:.9rem;background:#fff">
      <tr><th>Beneficjent</th><td><?= h($last_pass['name']) ?></td></tr>
      <tr><th>Login (UPN)</th><td class="font-monospace"><?= h($last_pass['login']) ?></td></tr>
      <tr><th>Hasło</th><td class="font-monospace fw-bold"><?= h($last_pass['password']) ?></td></tr>
      <?php if ($last_pass['emp_id']): ?>
      <tr><th>ID konta</th><td class="font-monospace"><?= h($last_pass['emp_id']) ?></td></tr>
      <?php endif; ?>
    </table>
  </div>
  <button type="button" class="btn-close flex-shrink-0"
          onclick="this.closest('.alert').remove(); fetch('m365.php?clear_pass=1')" aria-label="Zamknij"></button>
</div>
<?php
// Wyczyść z sesji po wyświetleniu (lazy — czyści przy zamknięciu lub po 5 min)
if (isset($_GET['clear_pass'])) { unset($_SESSION['k30_m365_last_pass']); exit; }
endif; ?>

<div class="row g-4">

  <!-- Konfiguracja -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold">
        <i class="bi bi-gear me-2"></i>Konfiguracja M365 — Karty 30
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"    value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action"  value="save_config">

          <!-- Tryb połączenia -->
          <div class="mb-3 p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0">
            <div class="fw-semibold mb-2" style="font-size:.85rem">Źródło połączenia M365</div>
            <div class="form-check mb-1">
              <input class="form-check-input" type="radio" name="use_own_tenant" id="m_main"
                     value="0" <?= !$cfg_use_own ? 'checked' : '' ?>
                     onchange="toggleTenantMode(false)">
              <label class="form-check-label" for="m_main">
                <strong>Produkcyjny M365 organizacji</strong>
                <div class="text-muted small">Używa konfiguracji z Administracja → Microsoft 365 (ten sam App, inna domena K30)</div>
                <?php if ($main_m365_ok): ?>
                <div class="text-success small"><i class="bi bi-check-circle me-1"></i>Skonfigurowany: <?= h(m365_setting('m365_domain') ?: '—') ?></div>
                <?php else: ?>
                <div class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Brak konfiguracji głównego M365</div>
                <?php endif; ?>
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="use_own_tenant" id="m_own"
                     value="1" <?= $cfg_use_own ? 'checked' : '' ?>
                     onchange="toggleTenantMode(true)">
              <label class="form-check-label" for="m_own">
                <strong>Osobny tenant K30</strong>
                <div class="text-muted small">Oddzielna App Registration w innym katalogu Azure AD</div>
              </label>
            </div>
          </div>

          <!-- Pola własnego tenanta (ukryte w trybie głównym) -->
          <div id="own_tenant_fields" style="display:<?= $cfg_use_own ? '' : 'none' ?>">
          <div class="mb-3">
            <label class="form-label fw-semibold">Tenant ID <span class="text-danger">*</span></label>
            <input type="text" class="form-control font-monospace" name="tenant_id"
                   value="<?= h($cfg_tenant) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Client ID (App Registration) <span class="text-danger">*</span></label>
            <input type="text" class="form-control font-monospace" name="client_id"
                   value="<?= h($cfg_client) ?>" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Client Secret <span class="text-danger">*</span></label>
            <input type="password" class="form-control" name="client_secret"
                   placeholder="<?= $cfg_has_sec ? '(zapisany — zostaw puste by nie zmieniać)' : 'Wklej secret…' ?>">
          </div>
          </div><!-- /own_tenant_fields -->

          <div class="mb-3">
            <label class="form-label fw-semibold">Domena K30 (loginy kont)</label>
            <input type="text" class="form-control font-monospace" name="domain"
                   value="<?= h($cfg_domain) ?>" placeholder="np. beneficjenci.org.pl">
            <div class="form-text">Login = ID@<strong><?= h($cfg_domain ?: 'twoja-domena.pl') ?></strong> (np. 4271@<?= h($cfg_domain ?: 'domena.pl') ?>)</div>
          </div>

          <!-- Domyślna licencja -->
          <div class="mb-3">
            <label class="form-label fw-semibold">
              <i class="bi bi-award me-1 text-warning"></i>Domyślna licencja
            </label>
            <?php if ($skus): ?>
            <select class="form-select" name="default_sku">
              <option value="">— brak domyślnej (wybieraj przy każdym koncie) —</option>
              <?php foreach ($skus as $sku): ?>
              <?php
                $avail = ($sku['prepaidUnits']['enabled'] ?? 0) - ($sku['consumedUnits'] ?? 0);
                $pname = k30_sku_label($sku['skuPartNumber'] ?? '');
              ?>
              <option value="<?= h($sku['skuId']) ?>"
                      <?= $cfg_default_sku === $sku['skuId'] ? 'selected' : '' ?>
                      <?= $avail <= 0 ? 'disabled' : '' ?>>
                <?= h($pname) ?>
                <?php if ($avail > 0): ?>(<?= $avail ?> wolnych)<?php else: ?>[BRAK]<?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Używana automatycznie przy tworzeniu kont — można nadpisać przy każdym koncie.</div>
            <?php elseif ($is_conf): ?>
            <div class="input-group">
              <input type="text" class="form-control font-monospace" name="default_sku"
                     value="<?= h($cfg_default_sku) ?>" placeholder="SKU ID licencji">
              <span class="input-group-text text-muted small">Nie udało się pobrać listy</span>
            </div>
            <?php else: ?>
            <div class="text-muted small">Dostępne po skonfigurowaniu połączenia.</div>
            <?php endif; ?>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">Zapisz konfigurację</button>
            <?php if ($is_conf): ?>
            <button type="submit" form="test_form" class="btn btn-outline-secondary">
              <i class="bi bi-wifi me-1"></i>Testuj połączenie
            </button>
            <?php endif; ?>
          </div>
        </form>
        <form id="test_form" method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="test">
        </form>
      </div>
    </div>

    <!-- Diagnostyka -->
    <?php if ($is_conf): ?>
    <div class="card border-0 shadow-sm mt-3" style="border-left:3px solid #f59e0b!important">
      <div class="card-body py-2 px-3" style="font-size:.82rem">
        <div class="fw-semibold mb-2" style="color:#92400e">
          <i class="bi bi-exclamation-triangle me-1"></i>Najczęstszy błąd: zły Tenant ID
        </div>
        <p class="text-muted mb-2">
          Błąd <strong>AADSTS700016</strong> oznacza że aplikacja jest zarejestrowana w <strong>innym</strong> katalogu Azure niż podany Tenant ID.
        </p>
        <div class="d-flex gap-2 flex-wrap">
          <a href="https://portal.azure.com/#blade/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/Overview"
             target="_blank" class="btn btn-xs btn-sm btn-outline-warning py-0 px-2">
            <i class="bi bi-microsoft me-1"></i>Azure → Identyfikator katalogu
          </a>
          <a href="https://portal.azure.com/#blade/Microsoft_AAD_IAM/StartboardApplicationsMenuBlade/AllApps"
             target="_blank" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2">
            <i class="bi bi-app-indicator me-1"></i>Azure → App Registrations
          </a>
        </div>
        <?php if ($cfg_tenant): ?>
        <div class="mt-2 font-monospace" style="font-size:.78rem;color:#64748b">
          Aktualny Tenant ID: <span class="text-dark"><?= h($cfg_tenant) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Wymagane uprawnienia Graph -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-body" style="font-size:.83rem">
        <div class="fw-semibold mb-2"><i class="bi bi-shield-check me-1 text-primary"></i>Wymagane uprawnienia App Registration</div>
        <ul class="mb-0 ps-3 text-muted">
          <li><code>User.ReadWrite.All</code></li>
          <li><code>Directory.ReadWrite.All</code></li>
          <li><code>Organization.Read.All</code></li>
        </ul>
        <div class="mt-2 text-muted">Typ: <strong>Application permissions</strong> (nie delegated)</div>
      </div>
    </div>

    <!-- Adresy Redirect URI / Callback -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-header fw-semibold py-2" style="font-size:.85rem">
        <i class="bi bi-link-45deg me-1 text-primary"></i>Redirect URI / Callback — Azure AD
      </div>
      <div class="card-body" style="font-size:.82rem">
        <p class="text-muted mb-2">
          W <strong>Azure Portal → App registrations → Authentication → Add a platform → Web</strong>
          dodaj poniższe adresy:
        </p>

        <div class="fw-semibold mb-1" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b">
          Redirect URIs (Web)
        </div>
        <?php
          $base = rtrim(APP_URL, '/');
          $callbacks = [
              $base . '/auth/microsoft.php'              => 'Logowanie Microsoft — system główny',
              $base . '/auth/saas_login.php'             => 'SSO z panelu SaaS (token)',
              $base . '/auth/saas_crm_login.php'         => 'SSO → CRM z panelu SaaS',
          ];
        ?>
        <div class="mb-3">
          <?php foreach ($callbacks as $url => $label): ?>
          <div class="d-flex align-items-center gap-2 mb-1">
            <code class="flex-grow-1 px-2 py-1 rounded"
                  style="background:#0f172a;color:#93c5fd;font-size:.79rem;display:block;overflow-x:auto">
              <?= h($url) ?>
            </code>
            <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2 flex-shrink-0"
                    onclick="navigator.clipboard.writeText('<?= h(addslashes($url)) ?>').then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='⎘',1500)})"
                    title="Kopiuj">⎘</button>
          </div>
          <div class="text-muted mb-2" style="font-size:.72rem;padding-left:.25rem"><?= h($label) ?></div>
          <?php endforeach; ?>
        </div>

        <div class="fw-semibold mb-1" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b">
          Front-channel Logout URL (opcjonalnie)
        </div>
        <div class="d-flex align-items-center gap-2 mb-3">
          <code class="flex-grow-1 px-2 py-1 rounded"
                style="background:#0f172a;color:#93c5fd;font-size:.79rem;display:block;overflow-x:auto">
            <?= h($base . '/auth/logout.php') ?>
          </code>
          <button type="button" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2 flex-shrink-0"
                  onclick="navigator.clipboard.writeText('<?= h(addslashes($base.'/auth/logout.php')) ?>').then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='⎘',1500)})"
                  title="Kopiuj">⎘</button>
        </div>

        <div class="alert alert-info py-2 mb-0" style="font-size:.79rem">
          <i class="bi bi-info-circle me-1"></i>
          Ten moduł K30-M365 używa <strong>Client Credentials</strong> (bez logowania użytkownika),
          więc Redirect URI nie są wymagane dla samego API Graph.<br>
          Dodaj je jeśli aplikacja Azure jest wspólna z OAuth logowaniem do systemu głównego.
        </div>
      </div>
    </div>
  </div>

  <!-- Tworzenie kont -->
  <div class="col-lg-7" id="provision">
    <?php if (!$is_conf): ?>
    <div class="alert alert-warning">
      <i class="bi bi-exclamation-triangle me-2"></i>
      Najpierw skonfiguruj połączenie z Microsoft 365.
    </div>
    <?php else: ?>

    <!-- Nowe konto -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header fw-semibold">
        <i class="bi bi-person-plus me-2 text-success"></i>Utwórz konto M365 dla beneficjenta
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="provision">
          <div class="row g-3 mb-3">
            <div class="col-sm-7">
              <label class="form-label fw-semibold">Beneficjent <span class="text-danger">*</span></label>
              <select class="form-select" name="client_id" required>
                <option value="">— wybierz —</option>
                <?php foreach ($clients_without as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!$clients_without): ?>
              <div class="form-text text-success"><i class="bi bi-check-circle me-1"></i>Wszyscy beneficjenci mają już konta M365.</div>
              <?php endif; ?>
            </div>
            <div class="col-sm-5">
              <label class="form-label fw-semibold">
                Licencja
                <?php if (!$cfg_default_sku): ?><span class="text-danger">*</span><?php endif; ?>
              </label>
              <?php if ($skus): ?>
              <select class="form-select" name="sku_id" <?= !$cfg_default_sku ? 'required' : '' ?>>
                <?php if ($cfg_default_sku): ?>
                <option value="">— użyj domyślnej —</option>
                <?php else: ?>
                <option value="">— wybierz licencję —</option>
                <?php endif; ?>
                <?php foreach ($skus as $sku): ?>
                <?php
                  $avail  = ($sku['prepaidUnits']['enabled'] ?? 0) - ($sku['consumedUnits'] ?? 0);
                  $total  = (int)($sku['prepaidUnits']['enabled'] ?? 0);
                  $used   = (int)($sku['consumedUnits'] ?? 0);
                  $pname  = k30_sku_label($sku['skuPartNumber'] ?? '');
                  $pct    = $total > 0 ? round($used/$total*100) : 0;
                  $is_def = $cfg_default_sku === $sku['skuId'];
                ?>
                <option value="<?= h($sku['skuId']) ?>"
                        <?= $avail <= 0 ? 'disabled' : '' ?>
                        <?= $is_def ? 'selected' : '' ?>>
                  <?= $is_def ? '★ ' : '' ?><?= h($pname) ?>
                  — <?= $avail ?>/<?= $total ?> wolnych<?= $is_def?' (domyślna)':'' ?>
                </option>
                <?php endforeach; ?>
              </select>
              <?php if ($cfg_default_sku): ?>
              <div class="form-text">Domyślna zostanie użyta gdy nic nie wybrano.</div>
              <?php endif; ?>
              <?php else: ?>
              <input type="text" class="form-control font-monospace" name="sku_id"
                     value="<?= h($cfg_default_sku) ?>" placeholder="SKU ID licencji">
              <div class="form-text text-warning">Nie udało się pobrać listy — używam wartości domyślnej lub wpisz ręcznie.</div>
              <?php endif; ?>
            </div>
          </div>
          <div class="alert alert-info small py-2 mb-3">
            <i class="bi bi-info-circle me-1"></i>
            System automatycznie wygeneruje:
            login (imie.nazwisko@<?= h($cfg_domain) ?>),
            losowe ID z zakresu 1010–9000 oraz proste hasło.
          </div>
          <button type="submit" class="btn btn-success" <?= !$clients_without?'disabled':'' ?>>
            <i class="bi bi-person-plus me-1"></i>Utwórz konto M365
          </button>
        </form>

        <?php if ($clients_without): ?>
        <hr class="my-3">
        <!-- Zbiorcze tworzenie kont M365 dla beneficjentów -->
        <form method="post">
          <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_action" value="provision_bulk">
          <label class="form-label fw-semibold mb-1"><i class="bi bi-people me-1"></i>Utwórz zbiorczo (wielu beneficjentów)</label>
          <p class="form-text mt-0 mb-2">Zaznacz beneficjentów — dla każdego powstanie konto M365 (login = ID@<?= h($cfg_domain) ?>) z tą samą licencją i jednym hasłem startowym.</p>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" id="m365_bulk_all"
                   onclick="var v=this.checked;document.querySelectorAll('.m365-bulk-cb').forEach(function(c){c.checked=v});">
            <label class="form-check-label small fw-semibold" for="m365_bulk_all">Zaznacz wszystkich (<?= count($clients_without) ?>)</label>
          </div>
          <div class="border rounded p-2 mb-2" style="max-height:220px;overflow:auto">
            <?php foreach ($clients_without as $c): ?>
            <div class="form-check">
              <input class="form-check-input m365-bulk-cb" type="checkbox" name="client_ids[]" value="<?= (int)$c['id'] ?>" id="mbc<?= (int)$c['id'] ?>">
              <label class="form-check-label small" for="mbc<?= (int)$c['id'] ?>"><?= h($c['name']) ?></label>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-sm-7">
              <label class="form-label small">Licencja <?php if (!$cfg_default_sku): ?><span class="text-danger">*</span><?php endif; ?></label>
              <?php if ($skus): ?>
              <select class="form-select form-select-sm" name="sku_id" <?= !$cfg_default_sku ? 'required' : '' ?>>
                <option value=""><?= $cfg_default_sku ? '— użyj domyślnej —' : '— wybierz licencję —' ?></option>
                <?php foreach ($skus as $sku):
                  $avail = ($sku['prepaidUnits']['enabled'] ?? 0) - ($sku['consumedUnits'] ?? 0);
                  $pname = k30_sku_label($sku['skuPartNumber'] ?? '');
                  $is_def = $cfg_default_sku === $sku['skuId']; ?>
                <option value="<?= h($sku['skuId']) ?>" <?= $avail <= 0 ? 'disabled' : '' ?> <?= $is_def ? 'selected' : '' ?>>
                  <?= $is_def ? '★ ' : '' ?><?= h($pname) ?> — <?= $avail ?> wolnych
                </option>
                <?php endforeach; ?>
              </select>
              <?php else: ?>
              <input type="text" class="form-control form-control-sm font-monospace" name="sku_id" value="<?= h($cfg_default_sku) ?>" placeholder="SKU ID licencji">
              <?php endif; ?>
            </div>
            <div class="col-sm-5">
              <label class="form-label small">Hasło startowe <span class="text-muted">(opcjonalnie)</span></label>
              <input type="text" class="form-control form-control-sm font-monospace" name="bulk_password" placeholder="(wygeneruj)">
            </div>
          </div>
          <button type="submit" class="btn btn-outline-success">
            <i class="bi bi-people me-1"></i>Utwórz zaznaczonym
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Lista istniejących kont -->
    <?php if ($clients_with): ?>
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center">
        <i class="bi bi-people me-2 text-primary"></i>Beneficjenci z kontem M365
        <span class="badge bg-secondary ms-2"><?= count($clients_with) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.83rem">
          <thead class="table-light">
            <tr><th>Beneficjent</th><th>Login M365</th><th>ID</th><th>Licencja</th><th class="text-end">Akcje</th></tr>
          </thead>
          <tbody>
            <?php foreach ($clients_with as $c): ?>
            <tr>
              <td class="fw-semibold"><?= h($c['name']) ?></td>
              <td class="font-monospace small"><?= h($c['m365_login'] ?? '') ?></td>
              <td class="font-monospace"><?= h($c['m365_employee_id'] ?? '') ?></td>
              <td class="text-muted small" style="max-width:120px;overflow:hidden;text-overflow:ellipsis">
                <?= h(substr($c['m365_license_sku'] ?? '', 0, 20)) ?>
              </td>
              <td class="text-end">
                <form method="post" class="d-inline"
                      onsubmit="return confirm('Zresetować hasło dla <?= h(addslashes($c['name'])) ?>?')">
                  <input type="hidden" name="_csrf"      value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_action"    value="reset_password">
                  <input type="hidden" name="client_id"  value="<?= (int)$c['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-sm btn-outline-warning py-0 px-2" title="Resetuj hasło">
                    <i class="bi bi-key"></i>
                  </button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div>

<?php if ($is_conf): ?>
<!-- Masowe tworzenie kont prefix{N} -->
<div class="card border-0 shadow-sm mt-4" id="bulk">
  <div class="card-header fw-semibold">
    <i class="bi bi-people-fill me-2 text-primary"></i>Masowe tworzenie kont — <code>prefix{N}</code>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Utwórz serię kont M365 o loginach <code>prefix1@<?= h($cfg_domain) ?></code>,
      <code>prefix2@<?= h($cfg_domain) ?></code> itd. z jednakowym prostym hasłem.
      Przydatne do przygotowania stanowisk szkoleniowych.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf"   value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_action" value="bulk_create">
      <div class="row g-3 mb-3">
        <div class="col-sm-3">
          <label class="form-label fw-semibold">Prefix <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="text" class="form-control font-monospace" name="bulk_prefix"
                   id="bp" placeholder="kursant" maxlength="20"
                   oninput="updatePreview()">
          </div>
        </div>
        <div class="col-sm-2">
          <label class="form-label fw-semibold">Od (N)</label>
          <input type="number" class="form-control" name="bulk_start" id="bs" value="1" min="1" max="9999" oninput="updatePreview()">
        </div>
        <div class="col-sm-2">
          <label class="form-label fw-semibold">Liczba kont</label>
          <input type="number" class="form-control" name="bulk_count" id="bc" value="5" min="1" max="50" oninput="updatePreview()">
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold">Hasło (puste = losowe)</label>
          <input type="text" class="form-control font-monospace" name="bulk_password"
                 placeholder="np. Komputer1" maxlength="20">
        </div>
        <div class="col-sm-2">
          <label class="form-label fw-semibold">Licencja</label>
          <?php if ($skus): ?>
          <select class="form-select" name="sku_id">
            <option value="">— domyślna<?= $cfg_default_sku ? ' (★)' : ' / bez' ?> —</option>
            <?php foreach ($skus as $sku): ?>
            <?php
              $avail  = ($sku['prepaidUnits']['enabled']??0) - ($sku['consumedUnits']??0);
              $pname  = k30_sku_label($sku['skuPartNumber'] ?? '');
              $is_def = $cfg_default_sku === $sku['skuId'];
            ?>
            <option value="<?= h($sku['skuId']) ?>"
                    <?= $avail <= 0 ? 'disabled' : '' ?>
                    <?= $is_def ? 'selected' : '' ?>>
              <?= $is_def ? '★ ' : '' ?><?= h($pname) ?> (<?= $avail ?>)
            </option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
          <input type="text" class="form-control font-monospace" name="sku_id"
                 value="<?= h($cfg_default_sku) ?>" placeholder="SKU ID">
          <?php endif; ?>
        </div>
      </div>
      <div class="alert alert-light border py-2 mb-3 small font-monospace" id="bulk_preview">
        Przykład: kursant1@<?= h($cfg_domain) ?>, kursant2@<?= h($cfg_domain) ?>, …
      </div>
      <button type="submit" class="btn btn-primary"
              onclick="return confirm('Utworzyć ' + document.getElementById('bc').value + ' kont M365?')">
        <i class="bi bi-people-fill me-1"></i>Utwórz konta seryjnie
      </button>
    </form>
  </div>
</div>

<!-- Wynik masowego tworzenia -->
<?php if ($bulk_result): ?>
<div class="card border-0 shadow-sm mt-3" style="border-top:3px solid #f59e0b">
  <div class="card-header bg-warning-subtle d-flex align-items-center gap-2">
    <i class="bi bi-key-fill text-warning"></i>
    <span class="fw-semibold">
      Wynik masowego tworzenia — <?= count($bulk_result['created']) ?> kont
    </span>
    <span class="text-muted small ms-1">
      hasło: <code class="text-danger fw-bold"><?= h($bulk_result['password']) ?></code>
    </span>
    <?php if ($bulk_result['created']): $pbase = APP_URL . '/karty30/admin/m365_bulk_print.php?ts=' . $bulk_result['ts']; ?>
    <div class="ms-auto btn-group">
      <button type="button" class="btn btn-sm btn-outline-secondary"
              onclick="window.open('<?= $pbase ?>&mode=table', '_blank')">
        <i class="bi bi-printer me-1"></i>Drukuj tabelkę
      </button>
      <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="visually-hidden">Więcej opcji wydruku</span>
      </button>
      <ul class="dropdown-menu dropdown-menu-end">
        <li><h6 class="dropdown-header">Karteczki do pocięcia</h6></li>
        <li><a class="dropdown-item" href="#" onclick="window.open('<?= $pbase ?>&mode=cards&per=2','_blank');return false;"><i class="bi bi-scissors me-2"></i>2 na stronę</a></li>
        <li><a class="dropdown-item" href="#" onclick="window.open('<?= $pbase ?>&mode=cards&per=3','_blank');return false;"><i class="bi bi-scissors me-2"></i>3 na stronę</a></li>
        <li><a class="dropdown-item" href="#" onclick="window.open('<?= $pbase ?>&mode=cards&per=4','_blank');return false;"><i class="bi bi-scissors me-2"></i>4 na stronę</a></li>
      </ul>
    </div>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($bulk_result['created']): ?>
    <table class="table table-sm table-bordered align-middle mb-3" style="font-size:.85rem;max-width:600px">
      <thead class="table-light">
        <tr>
          <th style="width:40px">#</th>
          <th>Beneficjent</th>
          <th>Login (UPN)</th>
          <th style="width:90px">ID</th>
          <th>Hasło</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bulk_result['created'] as $i => $c): ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><?= h($c['name'] ?? '—') ?></td>
          <td class="font-monospace"><?= h($c['login']) ?></td>
          <td class="font-monospace text-muted"><?= h($c['emp_id']) ?></td>
          <td class="font-monospace fw-bold text-danger"><?= h($bulk_result['password']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php if ($bulk_result['errors']): ?>
    <div class="mb-1 fw-semibold text-danger small"><i class="bi bi-exclamation-circle me-1"></i>Błędy (<?= count($bulk_result['errors']) ?>):</div>
    <ul class="small text-danger mb-0">
      <?php foreach ($bulk_result['errors'] as $err): ?>
      <li><?= h($err) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
<?php
// Zapisz do sesji dla strony wydruku (10 min)
if ($bulk_result) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['k30_m365_bulk_print_' . $bulk_result['ts']] = $bulk_result;
}
?>
<?php endif; ?>

<script>
function toggleTenantMode(useOwn) {
  var f = document.getElementById('own_tenant_fields');
  if (f) f.style.display = useOwn ? '' : 'none';
}
function updatePreview() {
  var prefix = document.getElementById('bp').value || 'kursant';
  var start  = parseInt(document.getElementById('bs').value) || 1;
  var count  = parseInt(document.getElementById('bc').value) || 5;
  var domain = '<?= h($cfg_domain) ?>';
  var examples = [];
  for (var i=start; i<start+Math.min(count,3); i++) examples.push(prefix+i+'@'+domain);
  if (count > 3) examples.push('…');
  document.getElementById('bulk_preview').textContent = 'Loginy: ' + examples.join(', ');
}
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>

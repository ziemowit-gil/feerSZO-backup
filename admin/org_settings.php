<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/krs.php';

require_role('admin');
$PAGE_TITLE = 'Dane organizacji';

// ── Migracja org_representatives ────────────────────────────────────────────
function _org_reps_migrate(): void {
    static $done = false;
    if ($done) return; $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS org_representatives (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        title      TEXT NOT NULL DEFAULT '',
        is_active  INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}
_org_reps_migrate();

function format_iban_pl(string $nrb): string {
    $full = 'PL' . $nrb; // 28 znaków → 7 grup po 4
    return implode(' ', str_split($full, 4));
}



$branding_keys = ['org_krs','org_miejscowosc','org_nip','org_regon','org_adres','org_name','sidebar_color','volunteer_color','org_logo',
                  'notify_from_name','notify_from_email',
                  'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_email','smtp_encryption',
                  'smtp2_host','smtp2_port','smtp2_user','smtp2_pass','smtp2_from_email','smtp2_encryption',
                  'm365_send_from_email','ksiegowy_email',
                  'admin_ip_restrict','admin_ip_whitelist',
                  'ezd_vpn_only','ezd_vpn_allowlist'];
$saved = [];
foreach ($branding_keys as $k) {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
    $saved[$k] = $r['value'] ?? '';
}
if (!$saved['sidebar_color']) $saved['sidebar_color'] = '#1e293b';
if (!$saved['volunteer_color']) $saved['volunteer_color'] = '#2563eb';
$rachunki = json_decode(org_setting('org_rachunki_bankowe') ?: '[]', true) ?: [];

// Ensure logo directory exists
$_logo_dir = dirname(__DIR__) . '/assets/logo';
if (!is_dir($_logo_dir)) mkdir($_logo_dir, 0755, true);

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if (isset($_POST['fetch_krs'])) {
        $krs_nr = preg_replace('/\D/', '', $_POST['org_krs'] ?? '');
        if (!$krs_nr) {
            $error = 'Podaj numer KRS organizacji.';
        } else {
            $data = krs_lookup($krs_nr);
            if (isset($data['error'])) {
                $error = $data['error'];
            } else {
                $adres = $data['adres'] ?? '';
                $miejscowosc = '';
                if (preg_match('/\d{2}-\d{3}\s+(.+)$/', $adres, $m)) {
                    $miejscowosc = trim($m[1]);
                } elseif (preg_match('/,\s*([^,]+)$/', $adres, $m)) {
                    $miejscowosc = trim($m[1]);
                }
                $update = [
                    'org_krs'         => $data['krs'],
                    'org_miejscowosc' => $miejscowosc,
                    'org_nip'         => $data['nip'] ?? '',
                    'org_regon'       => $data['regon'] ?? '',
                    'org_adres'       => $adres,
                ];
                foreach ($update as $k => $v) {
                    $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
                    if ($exists) {
                        db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
                    } else {
                        db_insert('settings', ['key_' => $k, 'value' => $v]);
                    }
                    $saved[$k] = $v;
                }
                flash_set('success', 'Dane organizacji zaciągnięte z KRS: ' . ($data['nazwa'] ?? ''));
                header('Location: ' . APP_URL . '/admin/org_settings.php?tab=rejestrowe'); exit;
            }
        }
    } elseif (isset($_POST['save_manual'])) {
        $fields = ['org_name','org_krs','org_miejscowosc','org_nip','org_regon','org_adres'];
        $stmt = db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON CONFLICT(key_) DO UPDATE SET value = excluded.value");
        foreach ($fields as $k) {
            $v = trim($_POST[$k] ?? '');
            $stmt->execute([$k, $v]);
            $saved[$k] = $v;
        }
        flash_set('success', 'Dane organizacji zostały zapisane.');
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=rejestrowe'); exit;

    } elseif (isset($_POST['save_branding'])) {
        $color = trim($_POST['sidebar_color'] ?? '#1e293b');
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $color)) $color = '#1e293b';

        $stmt = db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON CONFLICT(key_) DO UPDATE SET value = excluded.value");
        $stmt->execute(['sidebar_color', $color]);
        $saved['sidebar_color'] = $color;

        $vol_color = trim($_POST['volunteer_color'] ?? '#2563eb');
        if (!preg_match('/^#[0-9a-fA-F]{3,6}$/', $vol_color)) $vol_color = '#2563eb';
        $stmt->execute(['volunteer_color', $vol_color]);
        $saved['volunteer_color'] = $vol_color;

        if (!empty($_FILES['org_logo']['tmp_name']) && $_FILES['org_logo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['org_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['png','jpg','jpeg','gif','svg','webp'])) {
                if ($saved['org_logo'] && file_exists($_logo_dir . '/' . $saved['org_logo'])) {
                    @unlink($_logo_dir . '/' . $saved['org_logo']);
                }
                $fname = 'logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['org_logo']['tmp_name'], $_logo_dir . '/' . $fname)) {
                    $stmt->execute(['org_logo', $fname]);
                    $saved['org_logo'] = $fname;
                }
            } else {
                $error = 'Dozwolone formaty logo: PNG, JPG, GIF, SVG, WebP.';
            }
        }

        if (isset($_POST['remove_logo']) && $saved['org_logo']) {
            @unlink($_logo_dir . '/' . $saved['org_logo']);
            $stmt->execute(['org_logo', '']);
            $saved['org_logo'] = '';
        }

        if (!$error) {
            flash_set('success', 'Ustawienia brandingu zapisane.');
            header('Location: ' . APP_URL . '/admin/org_settings.php?tab=branding'); exit;
        }
    } elseif (isset($_POST['save_mail'])) {
        $mail_keys = ['notify_from_name','notify_from_email',
                      'smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from_email','smtp_encryption',
                      'smtp2_host','smtp2_port','smtp2_user','smtp2_pass','smtp2_from_email','smtp2_encryption',
                      'm365_send_from_email','ksiegowy_email'];
        $stmt = db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON CONFLICT(key_) DO UPDATE SET value = excluded.value");
        foreach ($mail_keys as $k) {
            $v = trim($_POST[$k] ?? '');
            $stmt->execute([$k, $v]);
        }
        flash_set('success', 'Ustawienia poczty zapisane.');
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=mail'); exit;
    } elseif (isset($_POST['test_mail'])) {
        require_once dirname(__DIR__) . '/includes/mail_queue.php';
        $to = trim($_POST['test_to'] ?? current_user()['email'] ?? '');
        if ($to) {
            $mid = mail_queue_add($to, '', 'Test wysyłki — ' . (defined('ORG_NAME') ? ORG_NAME : 'System'),
                '<p>To jest testowa wiadomość wysłana z systemu zarządzania umowami.</p>',
                'To jest testowa wiadomość wysłana z systemu zarządzania umowami.');
            $res = mail_queue_process(1);
            if ($res['sent']) flash_set('success', "Testowy e-mail wysłany na {$to}.");
            else flash_set('error', 'Wysyłka nie powiodła się — sprawdź logi serwera.');
        }
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=mail'); exit;
    } elseif (isset($_POST['save_representative'])) {
        _org_reps_migrate();
        $name  = trim($_POST['rep_name']  ?? '');
        $title = trim($_POST['rep_title'] ?? '');
        if ($name) {
            db()->prepare("INSERT INTO org_representatives (name, title) VALUES (?,?)")->execute([$name, $title]);
            flash_set('success', 'Dodano: ' . $name);
        }
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=rejestrowe'); exit;
    } elseif (isset($_POST['delete_representative'])) {
        _org_reps_migrate();
        $rid = (int)($_POST['rep_id'] ?? 0);
        if ($rid) db()->prepare("DELETE FROM org_representatives WHERE id=?")->execute([$rid]);
        flash_set('success', 'Usunięto.');
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=rejestrowe'); exit;
    } elseif (isset($_POST['save_portal'])) {
        $keys = ['allow_standalone_vol_accounts'];
        foreach ($keys as $k) {
            $val = isset($_POST[$k]) ? '1' : '0';
            try {
                db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?) ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
                    ->execute([$k, $val]);
            } catch (\Throwable $e) {
                try {
                    $ex = db_one("SELECT key_ FROM settings WHERE key_=?", [$k]);
                    if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $k]);
                    else     db_insert('settings', ['key_' => $k, 'value' => $val]);
                } catch (\Throwable $e2) {}
            }
        }
        flash_set('success', 'Ustawienia portalu zapisane.');
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=portal'); exit;

    } elseif (isset($_POST['save_security'])) {
        $restrict  = isset($_POST['admin_ip_restrict']) ? '1' : '0';
        $whitelist = trim($_POST['admin_ip_whitelist'] ?? '');

        // Walidacja — sprawdź każdy niepusty wpis
        $lines  = array_filter(array_map('trim', explode("\n", $whitelist)));
        $bad    = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) continue;
            $entry = $line;
            if (str_ends_with($entry, '.*')) {
                $base  = rtrim($entry, '.*');
                $parts = explode('.', $base);
                while (count($parts) < 4) $parts[] = '0';
                $entry = implode('.', $parts) . '/' . (count(explode('.', $base)) * 8);
            }
            if (strpos($entry, '/') !== false) {
                [$subnet] = explode('/', $entry, 2);
                if (!filter_var($subnet, FILTER_VALIDATE_IP)) $bad[] = $line;
            } else {
                if (!filter_var($entry, FILTER_VALIDATE_IP)) $bad[] = $line;
            }
        }

        if ($bad) {
            flash_set('error', 'Nieprawidłowe wpisy: ' . implode(', ', array_map('htmlspecialchars', $bad)));
        } else {
            if ($restrict === '1' && empty($lines)) {
                flash_set('error', 'Włącz ograniczenie IP dopiero po dodaniu co najmniej jednego adresu — inaczej zablokujesz dostęp wszystkim adminom.');
            } else {
                $stmt = db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON CONFLICT(key_) DO UPDATE SET value = excluded.value");
                $stmt->execute(['admin_ip_restrict',  $restrict]);
                $stmt->execute(['admin_ip_whitelist', $whitelist]);
                $saved['admin_ip_restrict']  = $restrict;
                $saved['admin_ip_whitelist'] = $whitelist;
                flash_set('success', 'Ustawienia bezpieczeństwa zapisane.');
            }
        }
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=security'); exit;

    } elseif (isset($_POST['save_ezd_vpn'])) {
        $vpn_only  = isset($_POST['ezd_vpn_only']) ? '1' : '0';
        $allowlist = trim($_POST['ezd_vpn_allowlist'] ?? '');

        // Walidacja — ten sam format co whitelista admina
        $lines = array_filter(array_map('trim', explode("\n", $allowlist)));
        $bad   = [];
        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) continue;
            $entry = $line;
            if (str_ends_with($entry, '.*')) {
                $base  = rtrim($entry, '.*');
                $parts = explode('.', $base);
                while (count($parts) < 4) $parts[] = '0';
                $entry = implode('.', $parts) . '/' . (count(explode('.', $base)) * 8);
            }
            if (strpos($entry, '/') !== false) {
                [$subnet] = explode('/', $entry, 2);
                if (!filter_var($subnet, FILTER_VALIDATE_IP)) $bad[] = $line;
            } else {
                if (!filter_var($entry, FILTER_VALIDATE_IP)) $bad[] = $line;
            }
        }

        if ($bad) {
            flash_set('error', 'Nieprawidłowe wpisy: ' . implode(', ', array_map('htmlspecialchars', $bad)));
        } elseif ($vpn_only === '1' && empty($lines)) {
            flash_set('error', 'Włącz dostęp tylko przez VPN dopiero po dodaniu co najmniej jednego adresu — inaczej zablokujesz Wirtualne biurko wszystkim użytkownikom (poza serwis@local).');
        } else {
            $stmt = db()->prepare("INSERT INTO settings (key_, value) VALUES (?, ?) ON CONFLICT(key_) DO UPDATE SET value = excluded.value");
            $stmt->execute(['ezd_vpn_only',      $vpn_only]);
            $stmt->execute(['ezd_vpn_allowlist', $allowlist]);
            $saved['ezd_vpn_only']      = $vpn_only;
            $saved['ezd_vpn_allowlist'] = $allowlist;
            flash_set('success', 'Ustawienia VPN dla Wirtualnego biurka zapisane.');
        }
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=security'); exit;
    } elseif (isset($_POST['add_rachunek'])) {
        $raw_nrb = strtoupper(preg_replace('/[\s\-]/', '', trim($_POST['rachunek_nrb'] ?? '')));
        if (str_starts_with($raw_nrb, 'PL')) $raw_nrb = substr($raw_nrb, 2);
        $raw_nrb = preg_replace('/\D/', '', $raw_nrb);
        if (strlen($raw_nrb) !== 26) {
            flash_set('error', 'Nieprawidłowy numer rachunku — wymagane 26 cyfr (NRB) lub IBAN z prefiksem PL.');
            header('Location: ' . APP_URL . '/admin/org_settings.php?tab=rachunki'); exit;
        }
        $rachunki_cur = json_decode(org_setting('org_rachunki_bankowe') ?: '[]', true) ?: [];
        foreach ($rachunki_cur as $ex) {
            if ($ex['nrb'] === $raw_nrb) {
                flash_set('error', 'Ten numer rachunku już istnieje na liście.');
                header('Location: ' . APP_URL . '/admin/org_settings.php?tab=rachunki'); exit;
            }
        }
        $rachunki_cur[] = [
            'nrb'    => $raw_nrb,
            'waluta' => mb_substr(trim($_POST['rachunek_waluta'] ?? 'PLN'), 0, 10),
            'nazwa'  => mb_substr(trim($_POST['rachunek_nazwa'] ?? ''), 0, 140),
            'adres'  => mb_substr(trim($_POST['rachunek_adres'] ?? ''), 0, 140),
            'bank'   => mb_substr(trim($_POST['rachunek_bank']  ?? ''), 0, 100),
            'opis'   => mb_substr(trim($_POST['rachunek_opis']  ?? ''), 0, 100),
        ];
        org_setting_set('org_rachunki_bankowe', json_encode($rachunki_cur, JSON_UNESCAPED_UNICODE));
        flash_set('success', 'Dodano rachunek ' . format_iban_pl($raw_nrb) . '.');
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=rachunki'); exit;

    } elseif (isset($_POST['delete_rachunek'])) {
        $del_nrb = trim($_POST['del_nrb'] ?? '');
        $rachunki_cur = json_decode(org_setting('org_rachunki_bankowe') ?: '[]', true) ?: [];
        $rachunki_cur = array_values(array_filter($rachunki_cur, fn($r) => $r['nrb'] !== $del_nrb));
        org_setting_set('org_rachunki_bankowe', json_encode($rachunki_cur, JSON_UNESCAPED_UNICODE));
        flash_set('success', 'Rachunek usunięty.');
        header('Location: ' . APP_URL . '/admin/org_settings.php?tab=rachunki'); exit;
    }
}

// Załaduj listę reprezentantów
_org_reps_migrate();
$representatives = db()->query("SELECT * FROM org_representatives ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

include dirname(__DIR__) . '/includes/header.php';
?>

<h4 class="mb-3"><i class="bi bi-building text-primary"></i> Dane organizacji i branding</h4>
<?= flash_html() ?>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= h($error) ?></div>
<?php endif; ?>

<!-- ── Zakładki ──────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3" id="orgTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-btn-rejestrowe" data-bs-toggle="tab" data-bs-target="#tab-rejestrowe"
            type="button" role="tab">
      <i class="bi bi-card-list me-1"></i>Dane Rejestrowe
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-btn-branding" data-bs-toggle="tab" data-bs-target="#tab-branding"
            type="button" role="tab">
      <i class="bi bi-palette2 me-1"></i>Branding
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-btn-portal" data-bs-toggle="tab" data-bs-target="#tab-portal"
            type="button" role="tab">
      <i class="bi bi-people me-1"></i>Portal
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-btn-mail" data-bs-toggle="tab" data-bs-target="#tab-mail"
            type="button" role="tab">
      <i class="bi bi-envelope-at me-1"></i>Poczta e-mail
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-btn-security" data-bs-toggle="tab" data-bs-target="#tab-security"
            type="button" role="tab">
      <i class="bi bi-shield-lock me-1"></i>Bezpieczeństwo
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="tab-btn-rachunki" data-bs-toggle="tab" data-bs-target="#tab-rachunki"
            type="button" role="tab">
      <i class="bi bi-bank me-1"></i>Rachunki bankowe
      <?php if ($rachunki): ?>
      <span class="badge bg-secondary ms-1"><?= count($rachunki) ?></span>
      <?php endif; ?>
    </button>
  </li>
</ul>

<div class="tab-content" id="orgTabsContent">

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: Dane Rejestrowe
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-rejestrowe" role="tabpanel">

  <!-- Zaciągnij z KRS -->
  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-cloud-download"></i> Zaciągnij dane z KRS</div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      Podaj numer KRS organizacji, aby automatycznie pobrać dane (nazwa, NIP, REGON, adres siedziby, miejscowość).
      Dane będą używane m.in. w nagłówku pism jako <em>„Miejscowość, dnia …"</em>.
    </p>
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="row g-2 align-items-end">
        <div class="col-sm-7">
          <label class="form-label fw-semibold">Numer KRS organizacji</label>
          <input type="text" name="org_krs" class="form-control font-monospace"
                 value="<?= h($saved['org_krs']) ?>" placeholder="np. 0000123456" maxlength="10">
        </div>
        <div class="col-sm-5">
          <button type="submit" name="fetch_krs" class="btn btn-primary w-100">
            <i class="bi bi-cloud-download"></i> Pobierz z KRS
          </button>
        </div>
      </div>
    </form>
    <?php if ($saved['org_krs']): ?>
    <div class="alert alert-success mt-3 mb-0 py-2 small">
      <i class="bi bi-check-circle"></i>
      Dane pobrane — KRS <strong><?= h($saved['org_krs']) ?></strong>
      <?php if ($saved['org_miejscowosc']): ?>
      · Miejscowość: <strong><?= h($saved['org_miejscowosc']) ?></strong>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  </div>

  <!-- Dane ręczne -->
  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-pencil"></i> Dane szczegółowe (edycja ręczna)</div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold">Pełna nazwa organizacji</label>
          <input type="text" name="org_name" class="form-control"
                 value="<?= h($saved['org_name']) ?>" placeholder="np. Fundacja im. Jana Kowalskiego">
        </div>
        <div class="col-md-6">
          <label class="form-label">Numer KRS</label>
          <input type="text" name="org_krs" class="form-control font-monospace"
                 value="<?= h($saved['org_krs']) ?>" placeholder="0000000000">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Miejscowość siedziby <span class="text-muted small">(używana w nagłówku pism)</span></label>
          <input type="text" name="org_miejscowosc" class="form-control"
                 value="<?= h($saved['org_miejscowosc']) ?>" placeholder="np. Warszawa">
        </div>
        <div class="col-md-6">
          <label class="form-label">NIP</label>
          <input type="text" name="org_nip" class="form-control font-monospace"
                 value="<?= h($saved['org_nip']) ?>" placeholder="0000000000">
        </div>
        <div class="col-md-6">
          <label class="form-label">REGON</label>
          <input type="text" name="org_regon" class="form-control font-monospace"
                 value="<?= h($saved['org_regon']) ?>" placeholder="">
        </div>
        <div class="col-12">
          <label class="form-label">Adres siedziby</label>
          <input type="text" name="org_adres" class="form-control"
                 value="<?= h($saved['org_adres']) ?>" placeholder="ul. Przykładowa 1, 00-001 Warszawa">
        </div>
      </div>
      <div class="mt-3">
        <button type="submit" name="save_manual" class="btn btn-outline-primary">
          <i class="bi bi-floppy"></i> Zapisz ręcznie
        </button>
      </div>
    </form>
  </div>
  </div>

  <!-- Podgląd nagłówka pisma -->
  <?php if ($saved['org_miejscowosc']): ?>
  <div class="card shadow-sm border-info mb-3">
  <div class="card-header fw-semibold text-info-emphasis"><i class="bi bi-eye"></i> Podgląd nagłówka pisma</div>
  <div class="card-body" style="font-family:'Times New Roman',serif;font-size:12pt">
    <div style="text-align:right">
      <?= h($saved['org_miejscowosc']) ?>, dnia <?= date_pl(date('Y-m-d')) ?>
    </div>
  </div>
  </div>
  <?php endif; ?>

  <!-- Osoby do reprezentacji -->
  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-people text-primary me-1"></i> Osoby do reprezentacji</div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      <i class="bi bi-info-circle me-1"></i>Używane jako podpisujący na umowach i dokumentach.
    </p>

    <!-- Formularz dodawania -->
    <form method="post" class="mb-3">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="row g-2 align-items-end">
        <div class="col-sm-5">
          <label class="form-label small fw-semibold">Imię i nazwisko</label>
          <input type="text" name="rep_name" class="form-control form-control-sm"
                 placeholder="np. Jan Kowalski" required>
        </div>
        <div class="col-sm-4">
          <label class="form-label small fw-semibold">Stanowisko / funkcja</label>
          <input type="text" name="rep_title" class="form-control form-control-sm"
                 placeholder="np. Prezes Zarządu">
        </div>
        <div class="col-sm-3">
          <button type="submit" name="save_representative" class="btn btn-sm btn-primary w-100">
            <i class="bi bi-plus-lg me-1"></i>Dodaj
          </button>
        </div>
      </div>
    </form>

    <!-- Lista reprezentantów -->
    <?php if ($representatives): ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Imię i nazwisko</th>
            <th>Stanowisko</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($representatives as $rep): ?>
          <tr>
            <td class="fw-semibold"><?= h($rep['name']) ?></td>
            <td class="text-muted"><?= h($rep['title']) ?></td>
            <td class="text-end">
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Usunąć <?= h(addslashes($rep['name'])) ?>?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="rep_id" value="<?= (int)$rep['id'] ?>">
                <button type="submit" name="delete_representative"
                        class="btn btn-sm btn-outline-danger">
                  <i class="bi bi-trash3"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="text-muted small"><i class="bi bi-person-x me-1"></i>Brak dodanych osób.</div>
    <?php endif; ?>
  </div>
  </div>

</div><!-- /tab-rejestrowe -->

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: Branding
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-branding" role="tabpanel">

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-palette2 text-primary me-1"></i> Wygląd i branding</div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="row g-4">

        <!-- Prawa kolumna: kolory + podgląd paska -->
        <div class="col-lg-7 order-lg-2">

          <!-- Kolor sidebara -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Kolor bocznego paska</label>
            <div class="d-flex align-items-center gap-3 flex-wrap">
              <input type="color" name="sidebar_color" id="sb_color_input"
                     value="<?= h($saved['sidebar_color']) ?>"
                     style="width:48px;height:38px;padding:2px;border-radius:8px;border:1px solid #dee2e6;cursor:pointer">
              <div class="d-flex flex-wrap gap-1">
                <?php foreach ([
                  '#1e293b' => 'Granatowy (domyślny)',
                  '#0f172a' => 'Czarny',
                  '#1a3a5c' => 'Granatowy',
                  '#1e3a2f' => 'Ciemnozielony',
                  '#2d1b4e' => 'Fioletowy',
                  '#7c2d12' => 'Bordowy',
                  '#374151' => 'Szary',
                ] as $hex => $label): ?>
                <button type="button" class="btn btn-sm p-0 border sb-preset"
                        data-color="<?= $hex ?>" title="<?= $label ?>"
                        style="width:28px;height:28px;background:<?= $hex ?>;border-radius:6px !important">
                </button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="form-text">Wybierz gotowy kolor lub użyj pickera. Zalecane ciemne odcienie.</div>
          </div>

          <!-- Kolor panelu wolontariusza -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Kolor panelu wolontariusza</label>
            <div class="d-flex align-items-center gap-3 flex-wrap">
              <input type="color" name="volunteer_color" id="vol_color_input"
                     value="<?= h($saved['volunteer_color']) ?>"
                     style="width:48px;height:38px;padding:2px;border-radius:8px;border:1px solid #dee2e6;cursor:pointer">
              <div class="d-flex flex-wrap gap-1">
                <?php foreach ([
                  '#2563eb' => 'Niebieski (domyślny)',
                  '#0f766e' => 'Turkusowy',
                  '#7C3AED' => 'Fioletowy',
                  '#dc2626' => 'Czerwony',
                  '#16a34a' => 'Zielony',
                  '#d97706' => 'Pomarańczowy',
                  '#374151' => 'Szary',
                ] as $hex => $label): ?>
                <button type="button" class="btn btn-sm p-0 border vol-preset"
                        data-color="<?= $hex ?>" title="<?= $label ?>"
                        style="width:28px;height:28px;background:<?= $hex ?>;border-radius:6px !important">
                </button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="form-text">Kolor akcentu w panelu wolontariusza (topbar, aktywne linki).</div>
          </div>

          <!-- Podgląd sidebara -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Podgląd paska</label>
            <?php
              $pv_dark  = _sb_luminance($saved['sidebar_color']) < 0.35;
              $pv_text  = $pv_dark ? '#fff'     : '#111827';
              $pv_muted = $pv_dark ? '#94a3b8'  : '#374151';
              $pv_icon  = $pv_dark ? 'rgba(255,220,0,.9)' : '#2563eb';
              $pv_border= $pv_dark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)';
            ?>
            <div id="sb-preview" style="background:<?= h($saved['sidebar_color']) ?>;border-radius:10px;padding:12px 14px;width:210px;transition:background .3s">
              <div data-sb-border style="color:<?= $pv_text ?>;font-weight:700;font-size:.9rem;display:flex;align-items:center;gap:8px;border-bottom:1px solid <?= $pv_border ?>;padding-bottom:8px;margin-bottom:8px" data-sb-text="text">
                <?php if ($saved['org_logo'] && file_exists($_logo_dir . '/' . $saved['org_logo'])): ?>
                <img src="<?= APP_URL ?>/assets/logo/<?= h($saved['org_logo']) ?>" style="height:22px;width:auto;border-radius:3px">
                <?php else: ?>
                <i class="bi bi-file-earmark-text-fill" style="color:<?= $pv_icon ?>;font-size:1.1rem" data-sb-text="icon"></i>
                <?php endif; ?>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.85rem"><?= h(ORG_NAME) ?></span>
              </div>
              <div style="color:<?= $pv_muted ?>;font-size:.7rem;padding:.3rem .4rem;border-radius:5px" data-sb-text="muted">
                <i class="bi bi-person-circle me-1"></i>Mój panel
              </div>
              <div style="color:<?= $pv_muted ?>;font-size:.7rem;padding:.3rem .4rem;border-radius:5px" data-sb-text="muted">
                <i class="bi bi-microsoft me-1" style="color:#00a4ef"></i>Microsoft 365
              </div>
              <div style="background:#2563eb;color:#fff;font-size:.7rem;padding:.3rem .6rem;border-radius:5px;margin-top:2px">
                <i class="bi bi-building me-1"></i>Aktywna strona
              </div>
            </div>
          </div>

        </div><!-- /prawa kolumna -->

        <!-- Lewa kolumna: logo -->
        <div class="col-lg-5 order-lg-1">
          <div class="mb-3">
            <label class="form-label fw-semibold">Logo organizacji</label>
            <?php if ($saved['org_logo'] && file_exists($_logo_dir . '/' . $saved['org_logo'])): ?>
            <div class="d-flex align-items-center gap-3 mb-2">
              <div style="background:<?= h($saved['sidebar_color']) ?>;padding:10px 14px;border-radius:10px;display:inline-block">
                <img src="<?= APP_URL ?>/assets/logo/<?= h($saved['org_logo']) ?>" alt="Logo"
                     style="height:40px;width:auto;max-width:120px;object-fit:contain" id="logo-preview-current">
              </div>
              <div>
                <div class="small text-muted mb-1"><?= h($saved['org_logo']) ?></div>
                <button type="submit" name="remove_logo" value="1"
                        class="btn btn-sm btn-outline-danger"
                        onclick="return confirm('Usunąć logo?')">
                  <i class="bi bi-trash3"></i> Usuń logo
                </button>
              </div>
            </div>
            <?php endif; ?>
            <input type="file" name="org_logo" class="form-control form-control-sm" id="logo_file_input"
                   accept=".png,.jpg,.jpeg,.gif,.svg,.webp">
            <div class="form-text">PNG, SVG, JPG · maks. 2 MB · Zalecany format: przezroczysty PNG lub SVG</div>
            <!-- Podgląd nowego logo przed uplodem -->
            <div id="logo-new-preview" class="mt-2" style="display:none">
              <div style="background:<?= h($saved['sidebar_color']) ?>;padding:10px 14px;border-radius:10px;display:inline-block" id="logo-preview-bg">
                <img id="logo-preview-img" src="" alt="Podgląd"
                     style="height:40px;width:auto;max-width:120px;object-fit:contain">
              </div>
              <div class="small text-muted mt-1">Podgląd przed zapisem</div>
            </div>
          </div>
        </div><!-- /lewa kolumna -->

      </div><!-- /row -->

      <button type="submit" name="save_branding" class="btn btn-primary mt-2">
        <i class="bi bi-floppy me-1"></i>Zapisz branding
      </button>
    </form>
  </div>
  </div>

</div><!-- /tab-branding -->

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: Poczta e-mail
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-mail" role="tabpanel">

  <div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold"><i class="bi bi-envelope-at text-primary me-1"></i> Wysyłka poczty e-mail</div>
  <div class="card-body">

    <p class="text-muted small mb-3">
      Priorytet wysyłki: <strong>M365 Graph API</strong> → <strong>SMTP (główny)</strong> → <strong>SMTP (backup)</strong> → PHP <code>mail()</code>.
      Każda metoda jest próbowana po kolei; następna uruchamia się tylko gdy poprzednia zawiedzie.
    </p>

    <form method="post">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <!-- Nadawca domyślny -->
      <h6 class="fw-semibold mb-2 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">Domyślny nadawca</h6>
      <div class="row g-3 mb-4">
        <div class="col-md-5">
          <label class="form-label small fw-semibold">Nazwa nadawcy</label>
          <input type="text" name="notify_from_name" class="form-control form-control-sm"
                 value="<?= h($saved['notify_from_name'] ?? '') ?>" placeholder="np. Fundacja XYZ">
        </div>
        <div class="col-md-7">
          <label class="form-label small fw-semibold">Adres e-mail nadawcy</label>
          <input type="email" name="notify_from_email" class="form-control form-control-sm"
                 value="<?= h($saved['notify_from_email'] ?? '') ?>" placeholder="no-reply@fundacja.pl">
          <div class="form-text">Używany gdy nie ma M365 ani SMTP lub jako Reply-To.</div>
        </div>
      </div>

      <!-- M365 -->
      <h6 class="fw-semibold mb-2 text-muted d-flex align-items-center gap-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">
        <i class="bi bi-microsoft text-primary"></i> Microsoft 365 Graph API
      </h6>
      <div class="row g-3 mb-4">
        <div class="col-md-8">
          <label class="form-label small fw-semibold">Adres skrzynki nadawczej (send_from_email)</label>
          <input type="email" name="m365_send_from_email" class="form-control form-control-sm"
                 value="<?= h($saved['m365_send_from_email'] ?? '') ?>"
                 placeholder="np. noreply@fundacja.onmicrosoft.com">
          <div class="form-text">
            Skrzynka musi mieć uprawnienie <code>Mail.Send</code> w aplikacji Azure AD.
            Tenant ID / Client ID / Secret konfiguruj w
            <a href="<?= APP_URL ?>/admin/m365_settings.php">Ustawieniach Microsoft 365</a>.
          </div>
        </div>
        <?php
          $m365_ok = !empty($saved['m365_send_from_email'])
              && !empty(db_one("SELECT value FROM settings WHERE key_='m365_graph_client_id'")['value'] ?? '');
        ?>
        <div class="col-md-4 d-flex align-items-end">
          <?php if ($m365_ok): ?>
          <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 px-3 py-2">
            <i class="bi bi-check-circle me-1"></i>Gotowe
          </span>
          <?php else: ?>
          <span class="badge bg-warning bg-opacity-15 text-warning border border-warning border-opacity-25 px-3 py-2">
            <i class="bi bi-exclamation-circle me-1"></i>Nie skonfigurowane
          </span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Księgowość -->
      <h6 class="fw-semibold mb-2 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">
        <i class="bi bi-cash-coin me-1"></i>Księgowość
      </h6>
      <div class="row g-3 mb-4">
        <div class="col-md-8">
          <label class="form-label small fw-semibold">Adres e-mail księgowego</label>
          <input type="email" name="ksiegowy_email" class="form-control form-control-sm"
                 value="<?= h($saved['ksiegowy_email'] ?? '') ?>" placeholder="ksiegowosc@biuro.pl">
          <div class="form-text">
            Adres, na który trafia blok danych do rachunku z procesu „Umowa do rozliczenia”
            (przycisk <em>Wyślij do księgowego</em>). Puste = tylko kopiuj/PDF.
          </div>
        </div>
      </div>

      <!-- SMTP główny -->
      <h6 class="fw-semibold mb-2 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">
        <i class="bi bi-hdd-network me-1"></i>SMTP — serwer główny
        <?php if ($saved['smtp_host'] ?? ''): ?>
          <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 ms-2 px-2 py-1" style="font-size:.7rem;text-transform:none;letter-spacing:0">
            <i class="bi bi-check-circle me-1"></i><?= h($saved['smtp_host']) ?>:<?= h($saved['smtp_port'] ?? 587) ?>
          </span>
        <?php endif; ?>
      </h6>
      <div class="row g-3 mb-4">
        <div class="col-md-5">
          <label class="form-label small fw-semibold">Serwer SMTP</label>
          <input type="text" name="smtp_host" class="form-control form-control-sm"
                 value="<?= h($saved['smtp_host'] ?? '') ?>" placeholder="smtp.example.com">
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-semibold">Port</label>
          <input type="number" name="smtp_port" class="form-control form-control-sm"
                 value="<?= h($saved['smtp_port'] ?? '587') ?>" placeholder="587">
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Szyfrowanie</label>
          <select name="smtp_encryption" class="form-select form-select-sm">
            <?php foreach (['tls'=>'STARTTLS (port 587)','ssl'=>'SSL (port 465)','none'=>'Brak'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($saved['smtp_encryption']??'tls')===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label small fw-semibold">Login SMTP</label>
          <input type="text" name="smtp_user" class="form-control form-control-sm" autocomplete="off"
                 value="<?= h($saved['smtp_user'] ?? '') ?>" placeholder="user@example.com">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Hasło SMTP</label>
          <input type="password" name="smtp_pass" class="form-control form-control-sm" autocomplete="new-password"
                 value="<?= h($saved['smtp_pass'] ?? '') ?>" placeholder="••••••••">
        </div>
        <div class="col-md-7">
          <label class="form-label small fw-semibold">Adres e-mail nadawcy SMTP</label>
          <input type="email" name="smtp_from_email" class="form-control form-control-sm"
                 value="<?= h($saved['smtp_from_email'] ?? '') ?>" placeholder="no-reply@fundacja.pl">
          <div class="form-text">Pozostaw puste, aby użyć domyślnego nadawcy powyżej.</div>
        </div>
      </div>

      <!-- SMTP backup -->
      <h6 class="fw-semibold mb-2 text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em">
        <i class="bi bi-hdd-network-fill me-1"></i>SMTP — serwer backup
        <span class="badge bg-secondary bg-opacity-10 text-secondary border ms-2 px-2 py-1" style="font-size:.7rem;text-transform:none;letter-spacing:0">
          używany gdy główny zawiedzie
        </span>
        <?php if ($saved['smtp2_host'] ?? ''): ?>
          <span class="badge bg-success bg-opacity-15 text-success border border-success border-opacity-25 ms-1 px-2 py-1" style="font-size:.7rem;text-transform:none;letter-spacing:0">
            <i class="bi bi-check-circle me-1"></i><?= h($saved['smtp2_host']) ?>:<?= h($saved['smtp2_port'] ?? 587) ?>
          </span>
        <?php endif; ?>
      </h6>
      <div class="row g-3 mb-4">
        <div class="col-md-5">
          <label class="form-label small fw-semibold">Serwer SMTP (backup)</label>
          <input type="text" name="smtp2_host" class="form-control form-control-sm"
                 value="<?= h($saved['smtp2_host'] ?? '') ?>" placeholder="smtp2.example.com">
        </div>
        <div class="col-md-2">
          <label class="form-label small fw-semibold">Port</label>
          <input type="number" name="smtp2_port" class="form-control form-control-sm"
                 value="<?= h($saved['smtp2_port'] ?? '587') ?>" placeholder="587">
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Szyfrowanie</label>
          <select name="smtp2_encryption" class="form-select form-select-sm">
            <?php foreach (['tls'=>'STARTTLS (port 587)','ssl'=>'SSL (port 465)','none'=>'Brak'] as $v=>$l): ?>
            <option value="<?= $v ?>" <?= ($saved['smtp2_encryption']??'tls')===$v?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label class="form-label small fw-semibold">Login SMTP (backup)</label>
          <input type="text" name="smtp2_user" class="form-control form-control-sm" autocomplete="off"
                 value="<?= h($saved['smtp2_user'] ?? '') ?>" placeholder="user@backup.com">
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Hasło SMTP (backup)</label>
          <input type="password" name="smtp2_pass" class="form-control form-control-sm" autocomplete="new-password"
                 value="<?= h($saved['smtp2_pass'] ?? '') ?>" placeholder="••••••••">
        </div>
        <div class="col-md-7">
          <label class="form-label small fw-semibold">Adres e-mail nadawcy (backup)</label>
          <input type="email" name="smtp2_from_email" class="form-control form-control-sm"
                 value="<?= h($saved['smtp2_from_email'] ?? '') ?>" placeholder="no-reply@backup.pl">
          <div class="form-text">Pozostaw puste, aby użyć adresu z SMTP głównego lub domyślnego nadawcy.</div>
        </div>
      </div>

      <div class="d-flex gap-2 flex-wrap">
        <button type="submit" name="save_mail" class="btn btn-primary btn-sm">
          <i class="bi bi-check-lg me-1"></i>Zapisz ustawienia poczty
        </button>
      </div>
    </form>

    <!-- Test -->
    <hr class="my-3">
    <form method="post" class="d-flex align-items-end gap-2 flex-wrap">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div>
        <label class="form-label small fw-semibold mb-1">Testowy e-mail — wyślij na adres:</label>
        <input type="email" name="test_to" class="form-control form-control-sm" style="min-width:220px"
               value="<?= h(current_user()['email'] ?? '') ?>" required>
      </div>
      <button type="submit" name="test_mail" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-send me-1"></i>Wyślij test
      </button>
    </form>

  </div>
  </div>

</div><!-- /tab-mail -->

<!-- ══ Portal ══════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-portal" role="tabpanel">

  <div class="card shadow-sm mb-4" style="max-width:680px">
    <div class="card-header fw-semibold">
      <i class="bi bi-people text-primary me-1"></i> Konta wolontariuszy bez umowy
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <div class="d-flex align-items-start gap-3 p-3 border rounded mb-3">
          <div class="form-check form-switch mt-1 flex-shrink-0">
            <input class="form-check-input" type="checkbox" role="switch"
                   id="allow_standalone_vol_accounts" name="allow_standalone_vol_accounts"
                   <?= (org_setting('allow_standalone_vol_accounts') === '1') ? 'checked' : '' ?>>
          </div>
          <div>
            <label class="fw-semibold d-block mb-1" for="allow_standalone_vol_accounts"
                   style="font-size:.9rem;cursor:pointer">
              Zezwól na tworzenie kont wolontariuszy bez umowy
            </label>
            <div class="text-muted" style="font-size:.8rem;line-height:1.5">
              Gdy włączone, administatror może tworzyć konta z rolą <strong>wolontariusza</strong>
              bez powiązanej umowy. Konto daje dostęp do portalu (zadania, wiadomości, panel)
              — identyczny z kontem tworzonym przez porozumienie wolontariackie.<br>
              Konta te są widoczne w sekcji
              <a href="<?= APP_URL ?>/admin/volunteer_accounts.php">Konta bez umowy</a>.
            </div>
          </div>
        </div>

        <button type="submit" name="save_portal" class="btn btn-primary btn-sm">
          <i class="bi bi-floppy me-1"></i>Zapisz ustawienia portalu
        </button>
      </form>
    </div>
  </div>

  <?php if (org_setting('allow_standalone_vol_accounts') === '1'): ?>
  <div class="alert alert-success d-flex align-items-center gap-2" style="max-width:680px">
    <i class="bi bi-check-circle-fill"></i>
    Funkcja aktywna.
    <a href="<?= APP_URL ?>/admin/volunteer_accounts.php" class="ms-2 btn btn-sm btn-outline-success">
      <i class="bi bi-person-plus me-1"></i>Zarządzaj kontami bez umowy
    </a>
  </div>
  <?php endif; ?>

</div><!-- /tab-portal -->

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: Bezpieczeństwo — ograniczenie IP dla panelu admina
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-security" role="tabpanel">
  <div class="row g-4">
    <div class="col-lg-7">

      <div class="card">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-shield-lock text-primary"></i>
          <strong>Ograniczenie dostępu do panelu admina wg adresu IP</strong>
        </div>
        <div class="card-body">

          <form method="post">
            <?= csrf_field() ?>

            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" name="admin_ip_restrict" id="admin_ip_restrict"
                     value="1" <?= $saved['admin_ip_restrict'] === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="admin_ip_restrict">
                Włącz ograniczenie — zezwalaj tylko z poniższych adresów IP
              </label>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Dozwolone adresy IP</label>
              <textarea name="admin_ip_whitelist" id="admin_ip_whitelist" class="form-control font-monospace"
                        rows="8" placeholder="Jeden wpis na linię, np.&#10;192.168.1.10&#10;192.168.1.0/24&#10;10.0.0.*&#10;2001:db8::/32"><?= h($saved['admin_ip_whitelist']) ?></textarea>
              <div class="form-text">
                Obsługiwane formaty: pojedynczy adres (<code>192.168.1.10</code>), notacja CIDR (<code>192.168.1.0/24</code>),
                wildcard (<code>192.168.1.*</code>). Linie zaczynające się od <code>#</code> to komentarze.
              </div>
            </div>

            <div class="alert alert-info d-flex align-items-start gap-2 py-2">
              <i class="bi bi-info-circle-fill mt-1 flex-shrink-0"></i>
              <div>
                Konto <strong>serwis@local</strong> jest <strong>zawsze</strong> wykluczone z tego ograniczenia,
                niezależnie od adresu IP — służy jako awaryjny dostęp serwisowy.
              </div>
            </div>

            <?php if ($saved['admin_ip_restrict'] === '1'): ?>
            <div class="alert alert-warning d-flex align-items-start gap-2 py-2">
              <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
              <div>
                Ograniczenie jest <strong>aktywne</strong>. Przed zapisem upewnij się, że Twój bieżący adres IP
                (<code><?= h($_SERVER['REMOTE_ADDR'] ?? '?') ?></code>) znajduje się na liście.
              </div>
            </div>
            <?php endif; ?>

            <div class="d-flex align-items-center gap-3">
              <button type="submit" name="save_security" class="btn btn-primary">
                <i class="bi bi-floppy me-1"></i>Zapisz ustawienia bezpieczeństwa
              </button>
              <span class="text-muted small">
                Twój bieżący adres IP: <code><?= h($_SERVER['REMOTE_ADDR'] ?? '?') ?></code>
              </span>
            </div>

          </form>
        </div>
      </div>

      <div class="card mt-4">
        <div class="card-header d-flex align-items-center gap-2" style="border-top:3px solid #b45309">
          <i class="bi bi-archive-fill" style="color:#b45309"></i>
          <strong>Wirtualne biurko (EZD) — dostęp tylko przez VPN</strong>
        </div>
        <div class="card-body">

          <form method="post">
            <?= csrf_field() ?>

            <div class="form-check form-switch mb-3">
              <input class="form-check-input" type="checkbox" name="ezd_vpn_only" id="ezd_vpn_only"
                     value="1" <?= ($saved['ezd_vpn_only'] ?? '') === '1' ? 'checked' : '' ?>>
              <label class="form-check-label" for="ezd_vpn_only">
                Zezwalaj na dostęp do modułu <strong>Wirtualne biurko</strong> tylko z poniższych adresów IP (VPN)
              </label>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Dozwolona pula adresów (VPN / biuro)</label>
              <textarea name="ezd_vpn_allowlist" id="ezd_vpn_allowlist" class="form-control font-monospace"
                        rows="6" placeholder="Jeden wpis na linię, np.&#10;10.8.0.0/24&#10;10.10.0.0/16&#10;203.0.113.10/32&#10;2001:db8::/32"><?= h($saved['ezd_vpn_allowlist'] ?? '') ?></textarea>
              <div class="form-text">
                Ograniczenie działa na <em>wszystkich</em> podstronach <code>/ezd/</code> (koszulki, pisma, RPW, pobieranie plików).
                Obsługiwane formaty: pojedynczy adres, CIDR (<code>10.8.0.0/24</code>), wildcard (<code>10.8.0.*</code>), IPv4/IPv6.
                Linie od <code>#</code> to komentarze.
              </div>
            </div>

            <div class="alert alert-info d-flex align-items-start gap-2 py-2">
              <i class="bi bi-info-circle-fill mt-1 flex-shrink-0"></i>
              <div>
                Konto <strong>serwis@local</strong> jest zawsze wykluczone (dostęp awaryjny). Administrator
                może zmieniać te ustawienia z panelu głównego niezależnie od tej blokady.
                Podgląd ról i czynności: <a href="ezd_access_matrix.php">Macierz uprawnień EZD</a>.
              </div>
            </div>

            <?php if (($saved['ezd_vpn_only'] ?? '') === '1'): ?>
            <div class="alert alert-warning d-flex align-items-start gap-2 py-2">
              <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
              <div>
                Ograniczenie jest <strong>aktywne</strong>. Jeśli pracujesz zdalnie, upewnij się, że Twój adres
                (<code><?= h($_SERVER['REMOTE_ADDR'] ?? '?') ?></code>) mieści się w dozwolonej puli VPN.
              </div>
            </div>
            <?php endif; ?>

            <button type="submit" name="save_ezd_vpn" class="btn btn-primary">
              <i class="bi bi-floppy me-1"></i>Zapisz ustawienia VPN dla EZD
            </button>

          </form>
        </div>
      </div>

    </div><!-- /col -->
  </div><!-- /row -->
</div><!-- /tab-security -->

<!-- ══════════════════════════════════════════════════════════════════════════
     TAB: Rachunki bankowe organizacji
     ══════════════════════════════════════════════════════════════════════════ -->
<div class="tab-pane fade" id="tab-rachunki" role="tabpanel">

  <div class="card shadow-sm mb-3">
  <div class="card-header fw-semibold"><i class="bi bi-bank text-primary me-1"></i> Numery rachunków organizacji</div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      <i class="bi bi-info-circle me-1"></i>
      Dane rachunków używane m.in. w eksporcie PLI do banku oraz w szablonach pism.
      Podaj numer w formacie IBAN (<code>PL</code> + 26 cyfr) lub sam 26-cyfrowy NRB — system normalizuje automatycznie.
      Pola <em>Nazwa właściciela</em> i <em>Adres</em> zastępują domyślne dane organizacji w poleceniach przelewu.
    </p>

    <!-- Formularz dodawania rachunku -->
    <form method="post" class="mb-4" id="form-add-rachunek">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

      <div class="row g-3 mb-2">
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Numer rachunku (IBAN / NRB) <span class="text-danger">*</span></label>
          <input type="text" name="rachunek_nrb" id="rachunek_nrb_input"
                 class="form-control form-control-sm font-monospace"
                 placeholder="PL12 3456 7890 1234 5678 9012 3456"
                 maxlength="40" autocomplete="off" required>
          <div class="form-text" id="rachunek_nrb_hint"></div>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Waluta</label>
          <select name="rachunek_waluta" class="form-select form-select-sm">
            <?php foreach (['PLN','EUR','USD','GBP','CHF','CZK'] as $cur): ?>
            <option value="<?= $cur ?>"><?= $cur ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">Nazwa banku</label>
          <input type="text" name="rachunek_bank" class="form-control form-control-sm"
                 placeholder="np. PKO Bank Polski SA" maxlength="100">
        </div>
      </div>

      <div class="row g-3 mb-2">
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Nazwa właściciela rachunku</label>
          <input type="text" name="rachunek_nazwa" class="form-control form-control-sm"
                 placeholder="Domyślnie: pełna nazwa organizacji" maxlength="140">
          <div class="form-text">Zastępuje nazwę organizacji w poleceniu przelewu (pole nadawcy).</div>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Adres właściciela</label>
          <input type="text" name="rachunek_adres" class="form-control form-control-sm"
                 placeholder="Domyślnie: adres siedziby organizacji" maxlength="140">
        </div>
      </div>

      <div class="row g-3 align-items-end">
        <div class="col-md-8">
          <label class="form-label small fw-semibold">Opis / przeznaczenie rachunku</label>
          <input type="text" name="rachunek_opis" class="form-control form-control-sm"
                 placeholder="np. Rachunek bieżący PLN, subkonto projektowe" maxlength="100">
        </div>
        <div class="col-md-4">
          <button type="submit" name="add_rachunek" class="btn btn-sm btn-primary w-100">
            <i class="bi bi-plus-lg me-1"></i>Dodaj rachunek
          </button>
        </div>
      </div>
    </form>

    <!-- Lista rachunków -->
    <?php if ($rachunki): ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Numer IBAN</th>
            <th>Waluta</th>
            <th>Właściciel / bank</th>
            <th>Opis</th>
            <th class="text-end"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rachunki as $r): ?>
          <tr>
            <td class="font-monospace fw-semibold" style="font-size:.82rem;letter-spacing:.04em">
              <?= h(format_iban_pl($r['nrb'])) ?>
            </td>
            <td><span class="badge bg-secondary bg-opacity-75"><?= h($r['waluta'] ?: 'PLN') ?></span></td>
            <td class="text-muted small">
              <?php if ($r['nazwa'] ?? ''): ?>
              <div><?= h($r['nazwa']) ?></div>
              <?php endif; ?>
              <?php if ($r['bank'] ?? ''): ?>
              <div class="text-muted"><?= h($r['bank']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-muted small"><?= h($r['opis'] ?? '') ?></td>
            <td class="text-end">
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Usunąć rachunek <?= h(addslashes(format_iban_pl($r['nrb']))) ?>?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="del_nrb" value="<?= h($r['nrb']) ?>">
                <button type="submit" name="delete_rachunek" class="btn btn-sm btn-outline-danger">
                  <i class="bi bi-trash3"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <div class="text-muted small"><i class="bi bi-bank me-1"></i>Brak zdefiniowanych rachunków — dodaj pierwszy rachunek powyżej.</div>
    <?php endif; ?>

  </div>
  </div>

</div><!-- /tab-rachunki -->

</div><!-- /tab-content -->

<script>
// ── Oblicz luminancję hex ────────────────────────────────────────────────────
function hexLuminance(hex) {
    hex = hex.replace('#','');
    if (hex.length === 3) hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2];
    const lin = c => { c /= 255; return c <= .03928 ? c/12.92 : Math.pow((c+.055)/1.055, 2.4); };
    return .2126*lin(parseInt(hex.slice(0,2),16)) +
           .7152*lin(parseInt(hex.slice(2,4),16)) +
           .0722*lin(parseInt(hex.slice(4,6),16));
}

// ── Live kolor sidebara ──────────────────────────────────────────────────────
const colorInput = document.getElementById('sb_color_input');
const sbPreview  = document.getElementById('sb-preview');
const previewBg  = document.getElementById('logo-preview-bg');
const previewBgCurrent = document.querySelector('[id^="logo-preview-current"]')?.closest('[style*="background"]');

function applyColor(hex) {
    colorInput.value = hex;
    const dark = hexLuminance(hex) < 0.35;

    const tokens = dark ? {
        text: '#fff', muted: '#94a3b8', label: '#475569', icon: 'rgba(255,220,0,.9)'
    } : {
        text: '#111827', muted: '#374151', label: '#6b7280', icon: '#2563eb'
    };

    if (sbPreview) {
        sbPreview.style.background = hex;
        sbPreview.querySelectorAll('[data-sb-text]').forEach(el => {
            el.style.color = tokens[el.dataset.sbText] || tokens.text;
        });
        const border = sbPreview.querySelector('[data-sb-border]');
        if (border) border.style.borderBottomColor = dark ? 'rgba(255,255,255,.1)' : 'rgba(0,0,0,.1)';
    }

    if (previewBg) previewBg.style.background = hex;
    if (previewBgCurrent) previewBgCurrent.style.background = hex;
}

colorInput?.addEventListener('input', () => applyColor(colorInput.value));
document.querySelectorAll('.sb-preset').forEach(btn =>
    btn.addEventListener('click', () => applyColor(btn.dataset.color))
);

// Volunteer color presets
const volColorInput = document.getElementById('vol_color_input');
document.querySelectorAll('.vol-preset').forEach(btn =>
    btn.addEventListener('click', () => { if(volColorInput) volColorInput.value = btn.dataset.color; })
);

// ── Podgląd nowego logo ──────────────────────────────────────────────────────
document.getElementById('logo_file_input')?.addEventListener('change', function() {
    const file = this.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('logo-preview-img').src = e.target.result;
        document.getElementById('logo-new-preview').style.display = 'block';
    };
    reader.readAsDataURL(file);
});

// ── Normalizator NRB / IBAN (live hint) ─────────────────────────────────────
document.getElementById('rachunek_nrb_input')?.addEventListener('input', function() {
    var raw = this.value.toUpperCase().replace(/[^0-9A-Z]/g, '');
    if (raw.startsWith('PL')) raw = raw.slice(2);
    raw = raw.replace(/\D/g, '');
    var hint = document.getElementById('rachunek_nrb_hint');
    if (!hint) return;
    if (!raw) { hint.textContent = ''; return; }
    if (raw.length < 26) {
        hint.innerHTML = '<span class="text-muted">Brakuje ' + (26 - raw.length) + ' cyfr.</span>';
    } else if (raw.length > 26) {
        hint.innerHTML = '<span class="text-danger">Za dużo cyfr (' + raw.length + '/26).</span>';
    } else {
        var iban = 'PL' + raw;
        var fmt  = iban.match(/.{1,4}/g).join(' ');
        hint.innerHTML = '<i class="bi bi-check-circle text-success me-1"></i>IBAN: <code>' + fmt + '</code>';
    }
});

// ── Aktywacja zakładki przez URL / localStorage ──────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var tab = new URLSearchParams(location.search).get('tab') || localStorage.getItem('org_settings_tab') || 'rejestrowe';
    var btn = document.querySelector('[data-bs-target="#tab-' + tab + '"]');
    if (btn) new bootstrap.Tab(btn).show();
    document.querySelectorAll('#orgTabs [data-bs-toggle="tab"]').forEach(function(b) {
        b.addEventListener('shown.bs.tab', function(e) {
            var id = e.target.dataset.bsTarget.replace('#tab-', '');
            localStorage.setItem('org_settings_tab', id);
        });
    });
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

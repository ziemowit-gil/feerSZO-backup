<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/persons.php';
require_once dirname(dirname(__DIR__)) . '/includes/address.php';
require_once dirname(dirname(__DIR__)) . '/includes/cpc.php';
require_once dirname(dirname(__DIR__)) . '/includes/person_picker.php';

require_role('admin', 'editor');
require_module_enabled('contract_wolontariat', 'Umowy wolontariackie');
require_once dirname(dirname(__DIR__)) . '/includes/approval.php';
require_once dirname(dirname(__DIR__)) . '/includes/m365.php';
$PAGE_TITLE = 'Nowe porozumienie wolontariackie';
cpc_migrate(); // ensure new columns exist
try { db()->exec("ALTER TABLE umowy_wolontariat ADD COLUMN template_id INTEGER NULL"); } catch (\Throwable $e) {}

// Wymagaj weryfikacji IKA przed otwarciem formularza (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    ika_require(APP_URL . '/contracts/wolontariat/add.php');
}

// ── Lista edytorów do wyboru opiekuna ────────────────────────────────────────
$_editors = db_all(
    "SELECT id,
            CASE WHEN first_name != '' AND last_name != '' THEN first_name || ' ' || last_name ELSE name END AS display_name,
            first_name, last_name, name
     FROM users
     WHERE role IN ('editor','admin') AND is_active = 1
     ORDER BY display_name"
);

$m365_enabled = (new M365Graph())->is_configured();

// Obszary zadań do sugestii przy wyborze grupy M365
$_task_workspaces = [];
try {
    if (module_enabled('tasks_enabled')) {
        $_task_workspaces = db_all(
            "SELECT id, name FROM task_workspaces WHERE is_active=1 ORDER BY name"
        );
    }
} catch (\Throwable $e) {}

// Konfiguracja wymagalności pól
$_add_field_keys = ['imie_nazwisko','email','pesel','data_urodzenia','telefon','adres',
                    'numer_umowy','data_zawarcia','data_rozpoczecia','data_zakonczenia',
                    'opiekun','miejsce_wolontariatu','przedmiot_porozumienia','projekt_program',
                    'm365_security_group_id','plik_umowy'];
$_add_field_cfg = [];
foreach ($_add_field_keys as $_fk) {
    $_add_field_cfg[$_fk] = org_setting('form_field_wolontariat_' . $_fk) ?: 'recommended';
}

// ── Automatyczne tworzenie konta portalu wolontariusza ────────────────────────
function _wolontariat_provision_account(
    string  $email,
    string  $name,
    string  $numer,
    ?string $plain_pass    = null,
    ?string $m365_login    = null,
    bool    $is_technical  = false,   // umowa przed 01.06 (migracja z Trello)
    string  $portal_scope  = ''       // '' = pełny, 'tasks_only', 'crm_only'
): ?string {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return null;
    if (db_one("SELECT id FROM users WHERE email = ?", [$email])) return null;

    // Tworzymy konto z losowym hasłem — użytkownik ustawia własne przez link
    $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
    db_insert('users', [
        'name'       => $name ?: $email,
        'email'      => $email,
        'password'   => $hash,
        'role'       => 'viewer',
        'is_active'  => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    $new_uid   = (int)db()->lastInsertId();
    $setup_tok = auth_generate_setup_token($new_uid);
    $setup_url = APP_URL . '/auth/set_password.php?token=' . $setup_tok;

    $org       = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
    $panel_url = APP_URL . '/panel/index.php';

    // ── Blok logowania (wspólny dla wszystkich wariantów) ──────────────────────
    $login_block = <<<HTML
<table style="background:#f8f9fa;border-radius:8px;padding:16px;width:100%;margin:16px 0;border-collapse:collapse">
  <tr>
    <td style="padding:5px 14px;color:#6c757d;width:130px;font-size:.9em">Adres e-mail</td>
    <td style="padding:5px 14px"><strong>{$email}</strong></td>
  </tr>
</table>
<div style="margin:18px 0;text-align:center">
  <a href="{$setup_url}"
     style="background:#16a34a;color:#fff;padding:13px 32px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:700;font-size:1em">
    🔑 Ustaw swoje hasło →
  </a>
  <p style="margin:10px 0 0;font-size:.8em;color:#6c757d">Link jest jednorazowy. Po kliknięciu zostaniesz zalogowany/a automatycznie.</p>
</div>
HTML;

    // ── Blok M365 (jeśli dotyczy) ──────────────────────────────────────────────
    $m365_block = '';
    if ($m365_login) {
        $m365_block = <<<HTML
<div style="background:#f0f7ff;border:1px solid #b6d4fe;border-radius:8px;padding:16px;margin:16px 0">
  <div style="font-weight:700;color:#0d6efd;margin-bottom:8px;font-size:.95em">
    🖥️ Konto Microsoft 365
  </div>
  <table style="border-collapse:collapse;width:100%">
    <tr>
      <td style="padding:4px 12px;color:#6c757d;width:140px;font-size:.88em">Login M365</td>
      <td style="padding:4px 12px"><strong style="font-family:monospace">{$m365_login}</strong></td>
    </tr>
  </table>
  <p style="margin:10px 0 0;font-size:.83em;color:#555">
    Przy pierwszym logowaniu do Microsoft 365 zostaniesz poproszony/a o ustawienie hasła.
    Zaloguj się na: <a href="https://portal.office.com" style="color:#0d6efd">portal.office.com</a>
  </p>
</div>
HTML;
    }

    // ── Stopka (wspólna) ───────────────────────────────────────────────────────
    $footer = <<<HTML
<p style="color:#6c757d;font-size:.82em;margin-top:28px;padding-top:14px;border-top:1px solid #dee2e6">
  Jeśli nie spodziewałeś/aś się tej wiadomości lub to pomyłka, możesz zignorować tego maila.<br>
  <a href="{$panel_url}" style="color:#0d6efd">{$panel_url}</a>
</p>
HTML;

    // ══════════════════════════════════════════════════════════════════════════
    //  WARIANT 1: Umowa przed 01.06.2026 — migracja z poprzedniej platformy
    // ══════════════════════════════════════════════════════════════════════════
    if ($is_technical) {
        $subject = "Twoje konto w nowym systemie zarządzania — {$org}";
        $body    = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#7c3aed,#5b21b6);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">🚀 Nowa platforma organizacji — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>
    Informujemy, że <strong>{$org}</strong> przeszła na nową platformę do zarządzania
    wolontariatem i zadaniami organizacji. Zastępuje ona dotychczas używanego <strong>Trello</strong>
    i inne narzędzia — teraz całe zarządzanie zadaniami, dokumentami i komunikacją
    znajdziesz w jednym miejscu.
  </p>
  <div style="background:#f5f3ff;border-left:4px solid #7c3aed;border-radius:4px;padding:14px 18px;margin:18px 0;font-size:.92em">
    <strong>Twoje konto zostało przeniesione do nowej platformy.</strong><br>
    Poniżej znajdziesz dane logowania — możesz je zmienić po pierwszym wejściu.
  </div>
  {$login_block}
  {$m365_block}
  <div style="margin:22px 0;text-align:center">
    <a href="{$login_url}" style="background:#7c3aed;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Zaloguj się do platformy →
    </a>
  </div>
  <p style="font-size:.9em">Po zalogowaniu znajdziesz:</p>
  <ul style="font-size:.9em;padding-left:18px">
    <li>Swoje zadania i tablice (zastępuje Trello)</li>
    <li>Dokumenty i korespondencję organizacji</li>
    <li>Podgląd swojej umowy wolontariackiej</li>
    <li>Komunikację z opiekunem</li>
  </ul>
  {$footer}
</div>
</body></html>
HTML;

    // ══════════════════════════════════════════════════════════════════════════
    //  WARIANT 2: Tylko zadania (tasks_only)
    // ══════════════════════════════════════════════════════════════════════════
    } elseif ($portal_scope === 'tasks_only') {
        $subject = "Dostęp do tablicy zadań — {$org}";
        $body    = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#0ea5e9,#0369a1);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">📋 Tablica zadań — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>
    W ramach współpracy wolontariackiej (<strong>{$numer}</strong>) otrzymujesz dostęp
    do <strong>tablicy zadań organizacji</strong> — naszego systemu do zarządzania zadaniami,
    który zastąpił Trello.
  </p>
  <div style="background:#f0f9ff;border-left:4px solid #0ea5e9;border-radius:4px;padding:14px 18px;margin:18px 0;font-size:.92em">
    Twoje konto daje dostęp wyłącznie do modułu <strong>Zadania</strong>.
  </div>
  {$login_block}
  {$m365_block}
  <div style="margin:22px 0;text-align:center">
    <a href="{$login_url}" style="background:#0ea5e9;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Zaloguj się →
    </a>
  </div>
  <p style="font-size:.9em">Po zalogowaniu zobaczysz:</p>
  <ul style="font-size:.9em;padding-left:18px">
    <li>Przypisane do Ciebie zadania</li>
    <li>Tablice projektów, do których jesteś dodany/a</li>
    <li>Możliwość komentowania i aktualizacji statusu zadań</li>
  </ul>
  {$footer}
</div>
</body></html>
HTML;

    // ══════════════════════════════════════════════════════════════════════════
    //  WARIANT 3: Tylko CRM (crm_only)
    // ══════════════════════════════════════════════════════════════════════════
    } elseif ($portal_scope === 'crm_only') {
        $subject = "Dostęp do systemu CRM — {$org}";
        $body    = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#16a34a,#15803d);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">👥 System CRM — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>
    W ramach porozumienia wolontariackiego (<strong>{$numer}</strong>) otrzymujesz dostęp
    do systemu CRM organizacji — narzędzia do zarządzania kontaktami i sprawami.
  </p>
  <div style="background:#f0fdf4;border-left:4px solid #16a34a;border-radius:4px;padding:14px 18px;margin:18px 0;font-size:.92em">
    Twoje konto daje dostęp wyłącznie do modułu <strong>CRM</strong>.
  </div>
  {$login_block}
  {$m365_block}
  <div style="margin:22px 0;text-align:center">
    <a href="{$login_url}" style="background:#16a34a;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Zaloguj się →
    </a>
  </div>
  <p style="font-size:.9em">Po zalogowaniu znajdziesz:</p>
  <ul style="font-size:.9em;padding-left:18px">
    <li>Kontakty i dane beneficjentów</li>
    <li>Sprawy i historię działań</li>
    <li>Notatki i komunikację z podopiecznymi</li>
  </ul>
  {$footer}
</div>
</body></html>
HTML;

    // ══════════════════════════════════════════════════════════════════════════
    //  WARIANT 4: Pełny dostęp (domyślny)
    // ══════════════════════════════════════════════════════════════════════════
    } else {
        $subject = "Twoje konto w portalu wolontariusza — {$org}";
        $body    = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#1e40af,#1d6ef9);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">🤝 Portal Wolontariusza — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Witaj, <strong>{$name}</strong>!</p>
  <p>
    W związku z zawarciem porozumienia wolontariackiego <strong>{$numer}</strong>
    zostało utworzone dla Ciebie konto w portalu organizacji.
  </p>
  {$login_block}
  {$m365_block}
  <div style="margin:22px 0;text-align:center">
    <a href="{$login_url}" style="background:#1d6ef9;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Zaloguj się do portalu →
    </a>
  </div>
  <p style="font-size:.9em">Po zalogowaniu w sekcji <strong>Mój panel</strong> znajdziesz:</p>
  <ul style="font-size:.9em;padding-left:18px">
    <li>Podgląd swoich umów i ich statusów</li>
    <li>Swoje zadania i tablice projektów</li>
    <li>Możliwość złożenia wniosku o zaświadczenie</li>
    <li>Korespondencję powiązaną z Twoimi umowami</li>
    <li>Wniosek o rozwiązanie umowy</li>
  </ul>
  {$footer}
</div>
</body></html>
HTML;
    }

    approval_send_email($email, $subject, $body);
    return $setup_url;
}

// ── Powiadomienia Canva ───────────────────────────────────────────────────────
function _wolontariat_notify_canva(array $data, int $contract_id, string $org): void {
    $name       = trim($data['imie_nazwisko'] ?? '');
    $email      = trim($data['email'] ?? '');
    $m365_login = trim($data['m365_login'] ?? '');
    $numer      = $data['numer_umowy'] ?? '';
    $login_url  = APP_URL . '/auth/login.php';
    $canva_url  = 'https://www.canva.com';

    // ── 1. E-mail do admina / opiekuna — prośba o ręczne zaproszenie ──────────
    try {
        $admin_emails = db_all(
            "SELECT email, name FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 3"
        );
        // Dodaj opiekuna umowy jeśli jest
        if (!empty($data['guardian_editor_id'])) {
            $opiekun = db_one("SELECT email, name FROM users WHERE id=?", [(int)$data['guardian_editor_id']]);
            if ($opiekun) $admin_emails[] = $opiekun;
        }
        $admin_emails = array_unique(array_column($admin_emails, null, 'email'));

        $canva_invite_url = 'https://www.canva.com/brand/invite';
        $admin_body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#7c3aed,#a855f7);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">🎨 Canva — nowe zaproszenie do wysłania</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:24px;border-radius:0 0 10px 10px">
  <p>Wolontariusz wymaga dostępu do przestrzeni <strong>Canva Pro</strong> organizacji {$org}.</p>
  <table style="background:#f8f9fa;border-radius:8px;padding:14px;width:100%;border-collapse:collapse;margin:14px 0">
    <tr><td style="padding:4px 12px;color:#6c757d;width:130px;font-size:.9em">Imię i nazwisko</td><td style="padding:4px 12px"><strong>{$name}</strong></td></tr>
    <tr><td style="padding:4px 12px;color:#6c757d;font-size:.9em">E-mail</td><td style="padding:4px 12px">{$email}</td></tr>
    <tr><td style="padding:4px 12px;color:#6c757d;font-size:.9em">Login M365</td><td style="padding:4px 12px"><strong style="font-family:monospace">{$m365_login}</strong></td></tr>
    <tr><td style="padding:4px 12px;color:#6c757d;font-size:.9em">Numer umowy</td><td style="padding:4px 12px">{$numer}</td></tr>
  </table>
  <div style="background:#fdf4ff;border-left:4px solid #a855f7;border-radius:4px;padding:14px;margin:14px 0;font-size:.9em">
    <strong>Akcja wymagana:</strong> Zaloguj się do Canva jako administrator i wyślij zaproszenie na adres
    <strong>{$email}</strong> (lub login M365: <code>{$m365_login}</code>).
  </div>
  <div style="margin:18px 0;text-align:center">
    <a href="{$canva_invite_url}" style="background:#7c3aed;color:#fff;padding:11px 24px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Otwórz panel zaproszeń Canva →
    </a>
  </div>
  <p style="font-size:.82em;color:#6c757d;margin-top:20px;border-top:1px solid #dee2e6;padding-top:12px">
    Po wysłaniu zaproszenia oznacz je jako wysłane w systemie:
    <a href="{$_url}" style="color:#7c3aed">{$_url}</a>
  </p>
</div>
</body></html>
HTML;

        $_url = APP_URL . "/contracts/wolontariat/view.php?id={$contract_id}#tab-m365-anchor";
        $admin_body = str_replace('{$_url}', $_url, $admin_body);

        foreach ($admin_emails as $ae) {
            approval_send_email(
                $ae['email'],
                "Canva — wyślij zaproszenie: {$name} ({$org})",
                $admin_body
            );
        }

        // Zapisz datę powiadomienia admina
        db_update('umowy_wolontariat', ['canva_email_sent_at' => date('Y-m-d H:i:s')], $contract_id);

    } catch (\Throwable $e) {
        // Nie przerywaj zapisu umowy jeśli mail się nie uda
    }

    // ── 2. E-mail do wolontariusza — instrukcja jak zalogować się do Canva ────
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    try {
        $login_method = $m365_login
            ? "swoim kontem Microsoft 365 (<strong>{$m365_login}</strong>)"
            : "adresem e-mail (<strong>{$email}</strong>)";

        $vol_body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#7c3aed,#a855f7);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">🎨 Dostęp do Canva — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$name}</strong>!</p>
  <p>
    W ramach współpracy wolontariackiej w <strong>{$org}</strong> otrzymujesz dostęp
    do wspólnej przestrzeni <strong>Canva Pro</strong> organizacji.
  </p>
  <div style="background:#fdf4ff;border-left:4px solid #a855f7;border-radius:4px;padding:14px 18px;margin:18px 0;font-size:.92em">
    <strong>Co to oznacza?</strong><br>
    Będziesz mieć dostęp do brandingu, szablonów i zasobów graficznych organizacji.
    Możesz tworzyć i edytować materiały korzystając z gotowych szablonów.
  </div>
  <p style="font-size:.92em">Wkrótce otrzymasz e-mail z zaproszeniem od Canva. Aby dołączyć:</p>
  <ol style="font-size:.9em;padding-left:18px">
    <li>Otwórz e-mail z zaproszeniem od Canva (nadawca: <code>noreply@canva.com</code>)</li>
    <li>Kliknij przycisk <strong>„Dołącz do zespołu"</strong></li>
    <li>Zaloguj się {$login_method}</li>
    <li>Gotowe — masz dostęp do przestrzeni {$org}</li>
  </ol>
  <div style="margin:22px 0;text-align:center">
    <a href="{$canva_url}" style="background:#7c3aed;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Przejdź do Canva →
    </a>
  </div>
  <p style="font-size:.82em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
    Jeśli masz pytania dotyczące dostępu do Canva, skontaktuj się ze swoim opiekunem.<br>
    <strong>{$org}</strong>
  </p>
</div>
</body></html>
HTML;

        approval_send_email(
            $email,
            "Dostęp do Canva Pro — {$org} (oczekuj zaproszenia)",
            $vol_body
        );

    } catch (\Throwable $e) {}
}

$TYPE  = 'wolontariat';
$TABLE = 'umowy_wolontariat';
$errors = [];
$row = ['numer_umowy' => next_contract_number($TYPE), 'status' => 'projekt'];
// Numer jest nadawany dynamicznie — dla technicznej będzie puste (nadane przy zapisie)

// Tryb migracji historycznej — włączony przez new.php?migracja=1
$migracja_mode = ($_SERVER['REQUEST_METHOD'] === 'GET') && !empty($_GET['migracja']);
if ($migracja_mode) {
    $row['wspolpraca_przed_2026'] = 1;
    $PAGE_TITLE = 'Migracja — porozumienie wolontariackie';
}

$_is_admin = (current_user()['role'] ?? '') === 'admin';

// Statusy terminalne — wymagają roli admin + uzasadnienia
$_terminal_statuses = ['zakończona', 'rozwiązana', 'anulowana'];

// Prefill z kwestionariusza onboardingowego (ustawiany przez admin/onboarding_view.php)
auth_start();
if (!empty($_SESSION['ob_prefill']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = array_merge($row, $_SESSION['ob_prefill']);
    unset($_SESSION['ob_prefill']);
    $prefill_msg = !empty($_SESSION['standalone_migration_uid'])
        ? 'Dane uzupełnione z konta wolontariusza bez umowy. Wypełnij pozostałe pola i zapisz, aby przekształcić konto na pełnoprawne porozumienie.'
        : 'Dane zostały wstępnie uzupełnione z kwestionariusza wolontariusza. Sprawdź i uzupełnij brakujące pola.';
    flash_set('info', $prefill_msg);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $row = $_POST;
    unset($row['_csrf']);

    // ── Tryb "Współpraca przed 01.06.2026" — umowa techniczna ───────────────
    $is_technical = !empty($row['wspolpraca_przed_2026']);
    if ($is_technical) {
        // Umowa techniczna — brak numeru, brak rejestru, brak obiegu
        $row['numer_umowy']    = '';   // celowo brak numeru — nie trafia do RU
        $row['nr_rejestru']    = '';   // celowo brak numeru rejestru
        $row['nr_roboczy']     = '';
        $row['nr_system']      = '';
        if (empty($row['status']))         $row['status']         = 'podpisana';
        if (empty($row['data_zawarcia']))  $row['data_zawarcia']  = '2026-06-01';
        if (empty($row['data_rozpoczecia'])) $row['data_rozpoczecia'] = '2026-06-01';
        $row['bezterminowa']        = 1;
        $row['is_technical']        = 1;
        $_POST['bezterminowa']      = '1'; // wymuś checkbox przy przetwarzaniu pól boolowych
        $row['forma_podpisania'] = $row['forma_podpisania'] ?? 'elektroniczna';
        $row['przedmiot_porozumienia'] = $row['przedmiot_porozumienia'] ?: 'Współpraca wolontariacka przed 01.06.2026 — wpis historyczny';
    }

    // Dla technicznej numer jest celowo pusty — nie waliduj
    if (!$is_technical && empty($row['numer_umowy'])) $errors[] = 'Numer umowy jest wymagany.';
    if (empty($row['status']))      $errors[] = 'Status jest wymagany.';

    // Statusy terminalne — tylko admin + wymagane uzasadnienie (pomiń dla technicznej)
    if (!$is_technical && in_array($row['status'] ?? '', $_terminal_statuses, true)) {
        if (!$_is_admin) {
            $errors[] = 'Tylko administrator może dodać umowę ze statusem „' . $row['status'] . '".';
        } elseif (empty(trim($row['uzasadnienie_statusu'] ?? ''))) {
            $errors[] = 'Dodanie umowy ze statusem „' . $row['status'] . '" wymaga uzasadnienia.';
        }
    }

    if (!$errors) {
        $m365_create_mode = $_POST['m365_create_mode'] ?? 'none';
        $identity_type    = $_POST['identity_type']    ?? 'pesel';

        // Pola checkboxowe
        foreach (['niepelnoletni', 'bezterminowa', 'ubezpieczenie_nnw', 'ubezpieczenie_oc', 'szkolenie_bhp', 'zwrot_kosztow', 'z_webngo'] as $f) {
            $row[$f] = isset($_POST[$f]) ? 1 : 0;
        }
        $row['wspolpraca_przed_2026'] = $is_technical ? 1 : 0;
        $row['is_technical']          = $is_technical ? 1 : 0;
        if ($row['z_webngo'] === 0) {
            $row['webngo_id']          = null;
            $row['webngo_numer_umowy'] = null;
        }
        $row['m365_konto']                = in_array($m365_create_mode, ['auto', 'manual'], true) ? 1 : 0;
        $row['m365_security_group_id']    = trim($_POST['m365_security_group_id']   ?? '') ?: null;
        $row['m365_security_group_name']  = trim($_POST['m365_security_group_name'] ?? '') ?: null;

        foreach (['godzin_tygodniowo', 'godzin_przepracowanych', 'limit_zwrotu_kosztow'] as $f) {
            if (isset($row[$f]) && $row[$f] === '') $row[$f] = null;
        }
        // template_id: zamień pusty string na null, zachowaj jako int gdy wybrano
        $row['template_id'] = !empty($row['template_id']) ? (int)$row['template_id'] : null;

        // Upload plików → finalna lokalizacja (przed redirectem)
        $plik_umowy = handle_upload('plik_umowy',         $TYPE);
        $plik_potw  = handle_upload('plik_potwierdzenia', $TYPE);
        $zgoda_op   = handle_upload('zgoda_opiekuna',     $TYPE);
        if ($plik_umowy) $row['plik_umowy']         = $plik_umowy;
        if ($plik_potw)  $row['plik_potwierdzenia'] = $plik_potw;
        if ($zgoda_op)   $row['zgoda_opiekuna']      = $zgoda_op;

        $row['created_by'] = current_user()['id'];
        $row['created_at'] = date('Y-m-d H:i:s');
        $row['updated_at'] = date('Y-m-d H:i:s');

        $allowed = ['numer_umowy', 'status', 'imie_nazwisko', 'pesel', 'adres', 'telefon', 'email',
            'data_urodzenia', 'niepelnoletni', 'zgoda_opiekuna', 'rodzic_imie_nazwisko', 'rodzic_email', 'rodzic_telefon',
            'przedmiot_porozumienia',
            'miejsce_wolontariatu', 'data_zawarcia', 'data_rozpoczecia', 'data_zakonczenia',
            'bezterminowa', 'godzin_tygodniowo', 'godzin_przepracowanych', 'ubezpieczenie_nnw',
            'numer_polisy_nnw', 'ubezpieczenie_oc', 'szkolenie_bhp', 'data_szkolenia_bhp',
            'zwrot_kosztow', 'zwrot_kosztow_opis', 'limit_zwrotu_kosztow', 'opiekun', 'projekt_program',
            'forma_podpisania', 'platforma_el', 'id_dokumentu_el', 'plik_potwierdzenia',
            'epodpis_dostawca', 'epodpis_nr_certyfikatu', 'epodpis_data_waznosci',
            'docusign_signer_email', 'docusign_signer_name',
            'plik_umowy', 'uwagi', 'created_by', 'created_at', 'updated_at',
            'm365_konto', 'm365_login', 'm365_user_id', 'm365_konto_aktywne', 'm365_data_utworzenia', 'm365_licencja_przypisana',
            'nr_roboczy', 'nr_system', 'nr_rejestru',
            'adres_odbiorca', 'adres_linia1', 'adres_linia2', 'adres_kod_pocztowy', 'adres_miasto', 'adres_kraj',
            'addr_street', 'addr_house', 'addr_flat', 'addr_postal', 'addr_city', 'addr_country',
            'z_webngo', 'webngo_id', 'webngo_numer_umowy', 'person_id', 'org_unit_id',
            'wspolpraca_przed_2026', 'is_technical', 'canva_access',
            'action_id', 'grant_id',
            'guardian_editor_id', 'guardian_initials',
            'id_document_type', 'id_document_number', 'no_pesel_reason',
            'm365_security_group_id', 'm365_security_group_name',
            'portal_scope',
            'template_id'];

        $data = array_intersect_key($row, array_flip($allowed));

        // Normalizacja telefonu
        if (!empty($data['telefon'])) {
            $t = preg_replace('/\D/', '', $data['telefon']);
            if (strlen($t) === 9) $t = '48' . $t;
            $data['telefon'] = $t;
        }

        // Guardian initials
        if (!empty($data['guardian_editor_id'])) {
            $geid = (int)$data['guardian_editor_id'];
            $ge   = db_one(
                "SELECT first_name, last_name, name,
                        CASE WHEN first_name != '' AND last_name != ''
                             THEN first_name || ' ' || last_name
                             ELSE name END AS display_name
                 FROM users WHERE id=?",
                [$geid]
            );
            if ($ge) {
                $data['guardian_initials'] = guardian_initials(
                    $ge['first_name'] ?? '',
                    $ge['last_name']  ?? '',
                    $ge['name']       ?? ''
                );
                if (empty($data['opiekun'])) {
                    $data['opiekun'] = $ge['display_name'];
                }
            }
        }

        // Wyczyść dane tożsamości
        if ($identity_type === 'foreigner') {
            $data['pesel'] = null;
        } else {
            $data['id_document_type']   = null;
            $data['id_document_number'] = null;
            $data['no_pesel_reason']    = null;
        }

        // ── Zapisz bezpośrednio do bazy ───────────────────────────────────────
        // Techniczna: brak numeru rejestru, brak obiegu akceptacji
        if (!$is_technical) {
            assign_nr_rejestru($data);
        } else {
            $data['nr_rejestru'] = '';
            $data['nr_roboczy']  = '';
            $data['nr_system']   = '';
            $data['numer_umowy'] = ''; // brak numeru RU
        }
        $id = db_insert($TABLE, $data);
        $log_note = 'Dodano: ' . ($data['numer_umowy'] ?? '');
        if (!empty($data['is_technical'])) {
            $log_note .= ' [Umowa techniczna — współpraca przed 01.06.2026]';
        }
        log_contract_action($TYPE, $id, current_user()['id'], 'create', $log_note);
        if (isset($_POST['nie_mam_drukarki'])) {
            require_once __DIR__ . '/../includes/pdf_queue.php';
            pdf_queue_add($TYPE, $id, $data['numer_umowy'] ?? '', $data['imie_nazwisko'] ?? '', current_user()['id']);
        }
        try { require_once dirname(dirname(__DIR__)) . '/includes/webhooks.php'; webhook_fire('contract.created', ['id'=>$id,'type'=>'wolontariat','numer'=>$data['numer_umowy']??'','email'=>$data['email']??'']); } catch(\Throwable $e) {}

        // Auto-dodaj wolontariusza do CRM
        try {
            require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
            crm_migrate();
            CrmManager::autoAddVolunteer($data, (int)(current_user()['id'] ?? 0));
        } catch (\Throwable $e) {
            error_log('[crm_auto] ' . $e->getMessage());
        }

        // Migracja ze standalone — wyczyść flagę is_standalone_volunteer
        auth_start();
        if (!empty($_SESSION['standalone_migration_uid'])) {
            $mig_uid = (int)$_SESSION['standalone_migration_uid'];
            unset($_SESSION['standalone_migration_uid']);
            try {
                db()->prepare("UPDATE users SET is_standalone_volunteer=0 WHERE id=?")
                    ->execute([$mig_uid]);
            } catch (\Throwable $e) {}
            flash_set('success', 'Umowa wolontariacka zapisana. Konto wolontariusza zostało przekształcone — nie jest już oznaczone jako "bez umowy".');
        }

        // Uzasadnienie statusu terminalnego
        if (in_array($data['status'] ?? '', $_terminal_statuses, true) && !empty(trim($row['uzasadnienie_statusu'] ?? ''))) {
            log_contract_action($TYPE, $id, current_user()['id'], 'note',
                'Uzasadnienie statusu „' . $data['status'] . '": ' . trim($row['uzasadnienie_statusu']));
        }

        // ── M365 auto-tworzenie ──────────────────────────────────────────────
        $m365_pass_created  = null;
        $m365_login_created = null;
        if ($m365_create_mode === 'auto' && $m365_enabled) {
            try {
                $graph              = new M365Graph();
                $m365_login_created = $graph->unique_login($data['imie_nazwisko'] ?? '');
                $m365_pass_created  = M365Graph::generate_password();
                $enabled_m365       = m365_should_be_active($data);
                $m365user           = $graph->create_user(
                    $m365_login_created,
                    $data['imie_nazwisko'] ?? '',
                    $m365_pass_created,
                    $enabled_m365
                );

                // ── Zapisz do DB natychmiast po created_user (przed assign_license) ──
                db_update($TABLE, [
                    'm365_konto'               => 1,
                    'm365_login'               => $m365_login_created,
                    'm365_user_id'             => $m365user['id'],
                    'm365_konto_aktywne'       => $enabled_m365 ? 1 : 0,
                    'm365_data_utworzenia'     => date('Y-m-d H:i:s'),
                    'm365_licencja_przypisana' => 0,
                ], $id);
                $_SESSION['m365_new_login'] = $m365_login_created;
                $_SESSION['m365_new_pass']  = $m365_pass_created;
                $_SESSION['m365_sent']      = false;
                log_contract_action($TYPE, $id, current_user()['id'], 'note', 'Automatycznie utworzono konto M365: ' . $m365_login_created);

                // ── Przypisz licencję osobno (błąd nie cofa utworzenia konta) ─────
                $sku = m365_setting('m365_license_sku_id');
                if ($sku) {
                    try {
                        $graph->assign_license($m365user['id'], $sku);
                        db_update($TABLE, ['m365_licencja_przypisana' => 1], $id);
                    } catch (\Throwable $eLic) {
                        flash_set('warning', 'Konto M365 utworzone, ale nie przypisano licencji: ' . $eLic->getMessage());
                    }
                }
            } catch (\Throwable $e) {
                flash_set('warning', 'Konto M365 nie zostało utworzone: ' . $e->getMessage());
                $m365_pass_created  = null;
                $m365_login_created = null;
            }
        }

        // ── Konto portalu wolontariusza ──────────────────────────────────────
        if (!empty($data['email'])) {
            $setup_result = _wolontariat_provision_account(
                $data['email'],
                $data['imie_nazwisko'] ?? '',
                $data['numer_umowy']   ?? '',
                null,                          // hasło ustawia użytkownik przez link
                $m365_login_created,
                $is_technical,
                ($data['portal_scope'] ?? '')
            );
            if ($setup_result !== null) {
                $_SESSION['new_portal_account'] = [
                    'email'          => $data['email'],
                    'setup_link_sent'=> true,
                    'name'           => $data['imie_nazwisko'] ?? '',
                ];
                log_contract_action($TYPE, $id, current_user()['id'], 'note', 'Utworzono konto portalu i wysłano link aktywacyjny na ' . $data['email']);
            }
            // Ustaw portal_scope na koncie użytkownika (nowym lub istniejącym)
            $portal_scope_val = ($data['portal_scope'] ?? '') ?: null;
            try {
                db()->prepare("UPDATE users SET portal_scope=? WHERE email=?")
                    ->execute([$portal_scope_val, $data['email']]);
            } catch (\Throwable $e) {}
        }

        // ── Konto rodzica/opiekuna ───────────────────────────────────────────
        if (!empty($data['rodzic_email']) && !empty($data['niepelnoletni'])) {
            $rodzic_result = _wolontariat_provision_account(
                $data['rodzic_email'],
                $data['rodzic_imie_nazwisko'] ?? '',
                $data['numer_umowy'] ?? ''
            );
            if ($rodzic_result !== null) {
                log_contract_action($TYPE, $id, current_user()['id'], 'note', 'Utworzono konto rodzica/opiekuna i wysłano link aktywacyjny: ' . $data['rodzic_email']);
            }
        }

        // Auto-uzupełnienie daty urodzenia z PESEL
        if (!empty($data['pesel']) && empty($data['data_urodzenia'])) {
            $bd = pesel_to_birthdate($data['pesel']);
            if ($bd) db_update($TABLE, ['data_urodzenia' => $bd], $id);
        }

        // Generuj kod odzyskiwania (8 cyfr) dla umów technicznych i tych sprzed 01.06.2026
        $recovery_plain = null;
        $zawarcia = $data['data_zawarcia'] ?? '';
        if ($is_technical || ($zawarcia !== '' && $zawarcia <= '2026-06-01')) {
            $recovery_plain = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            db_update($TABLE, ['recovery_code_hash' => password_hash($recovery_plain, PASSWORD_BCRYPT)], $id);
            $_SESSION['recovery_code_plain_' . $id] = $recovery_plain;
        }

        // ── Dostęp do Canva ──────────────────────────────────────────────────
        $canva_access = isset($_POST['canva_access']) ? 1 : 0;
        if ($canva_access) {
            db_update($TABLE, ['canva_access' => 1], $id);
            _wolontariat_notify_canva($data, $id, $org ?? (defined('ORG_NAME') ? ORG_NAME : 'Organizacja'));
        }

        // Przypisanie do obszaru zadań (jeśli wybrano przy grupie M365)
        $suggested_workspace_id = intval($_POST['suggested_workspace_id'] ?? 0);
        if ($suggested_workspace_id > 0 && !empty($data['email'])) {
            try {
                $wvol_user = db_one("SELECT id FROM users WHERE LOWER(email)=LOWER(?)", [$data['email']]);
                if ($wvol_user) {
                    db()->prepare(
                        "INSERT OR IGNORE INTO task_workspace_members
                         (workspace_id, user_id, role, added_by, added_at)
                         VALUES (?, ?, 'member', ?, datetime('now','localtime'))"
                    )->execute([$suggested_workspace_id, (int)$wvol_user['id'], (int)current_user()['id']]);
                    log_contract_action($TYPE, $id, current_user()['id'], 'note',
                        'Wolontariusz dodany do obszaru zadań ID=' . $suggested_workspace_id);
                }
            } catch (\Throwable $e) {}
        }

        // Techniczna: bez obiegu, bez numeru RU — tylko wpis i dostęp
        if ($is_technical) {
            flash_set('success', 'Wpis historycznej współpracy wolontariackiej zarejestrowany.');
        } elseif (($row['status'] ?? '') === 'projekt') {
            submit_for_approval($TYPE, $id, (int)current_user()['id'], $data['numer_umowy'] ?? '');
            flash_set('success', 'Porozumienie wolontariackie zostało dodane i przekazane do akceptacji.');
        } else {
            flash_set('success', 'Porozumienie wolontariackie zostało dodane.');
        }
        // ── Zachęta do uzupełnienia profilu w katalogu ────────────────────────
        if (!empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL) && !$is_technical) {
            try {
                $vol_email  = $data['email'];
                $vol_name   = $data['imie_nazwisko'] ?? '';
                $org        = defined('ORG_NAME') ? ORG_NAME : 'Organizacja';
                $profile_url = APP_URL . '/directory/profile_edit.php';
                $dir_url     = APP_URL . '/directory/';
                $body = <<<HTML
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#4338CA,#6366F1);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">📋 Uzupełnij swój profil w katalogu — {$org}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Cześć, <strong>{$vol_name}</strong>!</p>
  <p>Twoja umowa wolontariacka jest już w systemie. Teraz zapraszamy Cię do wypełnienia
     <strong>profilu w katalogu współpracowników</strong> organizacji {$org}.</p>
  <div style="background:#EEF2FF;border-left:4px solid #6366F1;border-radius:4px;padding:14px 18px;margin:16px 0;font-size:.9em">
    <strong>Co zyskasz uzupełniając profil?</strong>
    <ul style="margin:.5rem 0 0;padding-left:1.25rem;line-height:1.7">
      <li>Inni współpracownicy łatwiej Cię znajdą</li>
      <li>Możesz pokazać swoje umiejętności i zainteresowania</li>
      <li>Twoje zdjęcie i kontakt pojawią się w katalogu organizacji</li>
      <li>Ułatwiasz koordynatorom przypisywanie zadań</li>
    </ul>
  </div>
  <p style="font-size:.9em">To zajmie dosłownie 2 minuty — wystarczy imię, krótki opis i zdjęcie:</p>
  <div style="margin:20px 0;text-align:center">
    <a href="{$profile_url}" style="background:#6366F1;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600;margin-right:8px">
      Uzupełnij profil →
    </a>
    <a href="{$dir_url}" style="background:#f8f9fa;color:#374151;padding:12px 20px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:500;border:1px solid #dee2e6">
      Zobacz katalog
    </a>
  </div>
  <p style="font-size:.82em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
    Możesz pominąć ten krok — profil możesz uzupełnić w dowolnym momencie po zalogowaniu się do portalu.
  </p>
</div></body></html>
HTML;
                require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
                mail_queue_add($vol_email, $vol_name, "Uzupełnij swój profil w katalogu — {$org}", $body);
            } catch (\Throwable $e) {}
        }

        $show = $recovery_plain !== null ? '&show_recovery=1' : '';
        header('Location: ' . APP_URL . "/contracts/{$TYPE}/view.php?id={$id}&onboard=1{$show}");
        exit;
    }
}

$current_user = current_user();
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
?>

<style>
/* ══ Wizard — Horizontal Stepper ════════════════════════════════════════════ */
.wiz-hsteps {
  display: flex; align-items: center;
  background: #fff; border: 1px solid #E5E7EB;
  border-radius: 14px; padding: .65rem 1.1rem;
  box-shadow: 0 1px 4px rgba(0,0,0,.06);
  margin-bottom: 1.5rem; overflow-x: auto;
  gap: 0;
}
.wiz-hstep {
  display: flex; flex-direction: column; align-items: center; gap: .22rem;
  flex: 1; min-width: 44px; max-width: 110px;
  cursor: pointer; background: none; border: none;
  padding: .35rem .4rem; border-radius: 10px;
  transition: background .12s;
}
.wiz-hstep:hover:not(.active) { background: #F5F8FF; }
.wiz-hstep-num {
  width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .75rem; font-weight: 700;
  background: #E5E7EB; color: #6B7280;
  transition: all .15s; flex-shrink: 0;
}
.wiz-hstep.active .wiz-hstep-num {
  background: #1E6DFF; color: #fff;
  box-shadow: 0 0 0 4px rgba(30,109,255,.18);
}
.wiz-hstep.done .wiz-hstep-num {
  background: #16A34A; color: #fff;
}
.wiz-hstep-label {
  font-size: .67rem; font-weight: 500; color: #9CA3AF;
  text-align: center; line-height: 1.25;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 90px;
  display: none;
}
.wiz-hstep.active .wiz-hstep-label { color: #1E6DFF; font-weight: 700; }
.wiz-hstep.done  .wiz-hstep-label  { color: #16A34A; }
.wiz-hstep-sep {
  flex: 1; height: 2px; background: #E5E7EB; border-radius: 1px;
  min-width: 12px; max-width: 40px; flex-shrink: 0;
  transition: background .2s;
}
.wiz-hstep-sep.done { background: #16A34A; }
@media (min-width: 500px) {
  .wiz-hstep-label { display: block; }
}

/* Sekcja wizarda */
.wiz-section { display: none; }
.wiz-section.active { display: block; }

/* Karty kroku */
.wiz-card {
  background: #fff; border: 1px solid #E5E7EB;
  border-radius: 14px; margin-bottom: 1.25rem;
  box-shadow: 0 2px 8px rgba(0,0,0,.05);
  overflow: hidden;
}
.wiz-card-header {
  display: flex; align-items: center; gap: .7rem;
  padding: .9rem 1.3rem .75rem;
  border-bottom: 1px solid #F3F4F6;
  background: #FAFBFC;
}
.wiz-card-icon {
  width: 34px; height: 34px; border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}
.wiz-card-title { font-weight: 700; font-size: .93rem; color: #111827; }
.wiz-card-subtitle { font-size: .76rem; color: #9CA3AF; margin-top: .05rem; }
.wiz-card-body { padding: 1.1rem 1.3rem; }

/* Nawigacja kroków */
.wiz-nav-btns {
  display: flex; justify-content: space-between; align-items: center;
  padding: 1rem 0 .25rem; margin-top: .75rem;
  border-top: 1px solid #F3F4F6;
}
.wiz-nav-btns .btn {
  min-width: 110px; font-weight: 600;
}
.wiz-nav-btns .btn-primary {
  background: #1E6DFF; border-color: #1E6DFF;
}
.wiz-nav-btns .btn-primary:hover { background: #155EE0; border-color: #155EE0; }

/* Live preview sidebar */
.wiz-preview {
  background: #fff; border: 1px solid #E5E7EB;
  border-radius: 14px; overflow: hidden;
  box-shadow: 0 2px 8px rgba(0,0,0,.05);
  position: sticky; top: 80px;
}
.wiz-preview-header {
  background: linear-gradient(135deg, #0F2EA8 0%, #1E6DFF 100%);
  color: #fff; padding: .85rem 1.1rem;
  font-size: .82rem; font-weight: 600;
  display: flex; align-items: center; gap: .5rem;
}
.wiz-preview-body { padding: .75rem 1rem; }
.wiz-preview-row {
  display: flex; justify-content: space-between; align-items: baseline;
  padding: .32rem 0; border-bottom: 1px solid #F3F4F6;
  font-size: .8rem;
}
.wiz-preview-row:last-child { border-bottom: none; }
.wiz-preview-label { color: #9CA3AF; flex-shrink: 0; }
.wiz-preview-val   { color: #111827; font-weight: 600; text-align: right; max-width: 58%; word-break: break-word; }
</style>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb mb-0" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="list.php"><i class="bi bi-heart me-1"></i>Wolontariat</a></li>
    <li class="breadcrumb-item"><a href="new.php">Nowe porozumienie</a></li>
    <li class="breadcrumb-item active"><?= $migracja_mode ? 'Migracja' : 'Pełny formularz' ?></li>
  </ol>
</nav>

<?php if ($migracja_mode): ?>
<!-- Baner migracji historycznej -->
<div class="alert d-flex align-items-start gap-3 mb-4 py-3 px-4"
     style="background:#faf5ff;border:1.5px solid #c4b5fd;border-radius:12px">
  <i class="bi bi-clock-history flex-shrink-0 mt-1" style="color:#7c3aed;font-size:1.4rem"></i>
  <div>
    <div class="fw-bold mb-1" style="color:#6d28d9">Tryb migracji historycznej</div>
    <div class="small text-muted">
      Daty i numer umowy zostaną uzupełnione automatycznie.
      Podaj minimum <strong>imię i nazwisko</strong> oraz <strong>e-mail</strong> wolontariusza.
      Możesz też wgrać skan pierwotnej umowy.
    </div>
  </div>
  <a href="new.php" class="ms-auto btn btn-sm btn-outline-secondary flex-shrink-0" style="font-size:.78rem">
    <i class="bi bi-arrow-left me-1"></i>Zmień tryb
  </a>
</div>
<?php endif; ?>

<!-- Nagłówek -->
<div class="d-flex align-items-center gap-3 mb-4">
  <div class="d-flex align-items-center justify-content-center flex-shrink-0"
       style="width:48px;height:48px;background:<?= $migracja_mode ? '#f3f0ff' : '#EFF4FF' ?>;border-radius:12px">
    <i class="bi <?= $migracja_mode ? 'bi-clock-history' : 'bi-file-earmark-person' ?> fs-4"
       style="color:<?= $migracja_mode ? '#7c3aed' : '#1E6DFF' ?>"></i>
  </div>
  <div>
    <h4 class="mb-0 fw-bold"><?= $migracja_mode ? 'Migracja — porozumienie wolontariackie' : 'Nowe porozumienie wolontariackie' ?></h4>
    <div class="text-muted small"><?= $migracja_mode ? 'Rejestracja historycznej współpracy przed 01.06.2026' : 'Wypełnij dane w kolejnych krokach i zapisz umowę' ?></div>
  </div>
  <?php if (!$migracja_mode): ?>
  <div class="ms-auto d-none d-md-block">
    <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2">
      <i class="bi bi-hash me-1"></i><?= h($row['numer_umowy']) ?>
    </span>
  </div>
  <?php else: ?>
  <div class="ms-auto d-none d-md-block">
    <span class="badge px-3 py-2" style="background:#ede9fe;color:#7c3aed;border:1px solid #c4b5fd">
      <i class="bi bi-gear-fill me-1"></i>Umowa techniczna (auto)
    </span>
  </div>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible d-flex gap-2 mb-3">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <div>
    <div class="fw-semibold mb-1">Proszę poprawić błędy:</div>
    <ul class="mb-0 ps-3"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
  </div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="wolontariatForm" novalidate>
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<!-- ── Współpraca historyczna ─────────────────────────────────────────────── -->
<?php $is_tech_checked = !empty($row['wspolpraca_przed_2026']); ?>
<div class="card border-0 shadow-sm mb-4"
     style="border-left:4px solid #8b5cf6!important;background:<?= $is_tech_checked?'#faf5ff':'#fff' ?>">
  <div class="card-body py-3 px-4">
    <div class="form-check form-switch d-flex align-items-center gap-3">
      <input class="form-check-input flex-shrink-0" type="checkbox" role="switch"
             id="wspolpraca_przed_2026" name="wspolpraca_przed_2026" value="1"
             <?= $is_tech_checked ? 'checked' : '' ?>
             onchange="toggleTechnical(this.checked)"
             style="width:2.5em;height:1.3em">
      <label class="form-check-label" for="wspolpraca_przed_2026" style="cursor:pointer">
        <span class="fw-bold" style="color:#7c3aed;font-size:1rem">
          <i class="bi bi-clock-history me-1"></i>Współpraca przed 01.06.2026
        </span>
        <div class="text-muted small mt-1">
          Rejestracja historycznej współpracy wolontariackiej bez podpisywania nowej umowy.
          System automatycznie utworzy <strong>umowę techniczną</strong> i nada dostęp
          do panelu wolontariusza.
        </div>
      </label>
      <?php if ($is_tech_checked): ?>
      <span class="badge ms-auto flex-shrink-0"
            style="background:#7c3aed;font-size:.78rem;padding:.4em .8em">
        <i class="bi bi-gear-fill me-1"></i>Umowa techniczna
      </span>
      <?php endif; ?>
    </div>
    <div id="technical_info" style="display:<?= $is_tech_checked?'':'none' ?>">
      <hr class="my-2">
      <div class="small text-muted d-flex gap-4 flex-wrap">
        <span><i class="bi bi-check-circle text-success me-1"></i>Data umowy: 01.06.2026 (auto)</span>
        <span><i class="bi bi-check-circle text-success me-1"></i>Status: Podpisana (auto)</span>
        <span><i class="bi bi-check-circle text-success me-1"></i>Bez terminu (auto)</span>
        <span><i class="bi bi-check-circle text-success me-1"></i>Pola wymagane wypełniane automatycznie</span>
        <span><i class="bi bi-info-circle text-primary me-1"></i>Podaj przynajmniej: Imię i nazwisko + E-mail</span>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">

<div class="col-xl-8">

<!-- ── Poziomy pasek kroków ──────────────────────────────────────────────── -->
<nav class="wiz-hsteps" id="wizSteps" role="tablist" aria-label="Kroki formularza">
  <button type="button" class="wiz-hstep active" id="step-btn-1" role="tab" aria-selected="true" onclick="goToStep(1)">
    <span class="wiz-hstep-num" id="step-num-1">1</span>
    <span class="wiz-hstep-label">Umowa</span>
  </button>
  <div class="wiz-hstep-sep" id="step-sep-1"></div>
  <button type="button" class="wiz-hstep" id="step-btn-2" role="tab" aria-selected="false" onclick="goToStep(2)">
    <span class="wiz-hstep-num" id="step-num-2">2</span>
    <span class="wiz-hstep-label">Wolontariusz</span>
  </button>
  <div class="wiz-hstep-sep" id="step-sep-2"></div>
  <button type="button" class="wiz-hstep" id="step-btn-3" role="tab" aria-selected="false" onclick="goToStep(3)">
    <span class="wiz-hstep-num" id="step-num-3">3</span>
    <span class="wiz-hstep-label">Szczegóły</span>
  </button>
  <div class="wiz-hstep-sep" id="step-sep-3"></div>
  <button type="button" class="wiz-hstep" id="step-btn-4" role="tab" aria-selected="false" onclick="goToStep(4)">
    <span class="wiz-hstep-num" id="step-num-4">4</span>
    <span class="wiz-hstep-label">BHP i ubezp.</span>
  </button>
  <div class="wiz-hstep-sep" id="step-sep-4"></div>
  <button type="button" class="wiz-hstep" id="step-btn-5" role="tab" aria-selected="false" onclick="goToStep(5)">
    <span class="wiz-hstep-num" id="step-num-5">5</span>
    <span class="wiz-hstep-label">Dostępy IT</span>
  </button>
  <div class="wiz-hstep-sep" id="step-sep-5"></div>
  <button type="button" class="wiz-hstep" id="step-btn-6" role="tab" aria-selected="false" onclick="goToStep(6)">
    <span class="wiz-hstep-num" id="step-num-6">6</span>
    <span class="wiz-hstep-label">Grupy IT</span>
  </button>
</nav>

<!-- ════════════════════════════════════════════════════════════════
     KROK 1: UMOWA
     ════════════════════════════════════════════════════════════════ -->
<div class="wiz-section active" id="wiz-step-1">

  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#EEF4FF;color:#2563EB"><i class="bi bi-file-text-fill"></i></div>
      <div>
        <div class="wiz-card-title">Podstawowe dane umowy</div>
        <div class="wiz-card-subtitle">Numer, status, opiekun i daty</div>
      </div>
    </div>
    <div class="wiz-card-body">

      <div class="row g-3">
        <div class="col-sm-5">
          <label class="form-label fw-semibold">Numer umowy <span class="text-danger">*</span></label>
          <input name="numer_umowy" class="form-control fw-bold font-monospace"
                 value="<?= h($row['numer_umowy'] ?? '') ?>" required>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
          <select name="status" id="status_select" class="form-select" required>
            <optgroup label="Aktywne">
              <?php foreach (['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji'] as $k=>$v):
                $sel = ($row['status']??'')===$k?'selected':''; ?>
              <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php if ($_is_admin): ?>
            <optgroup label="Terminalne (tylko admin)">
              <?php foreach (['zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'] as $k=>$v):
                $sel = ($row['status']??'')===$k?'selected':''; ?>
              <option value="<?= h($k) ?>" <?= $sel ?>><?= h($v) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endif; ?>
          </select>
          <div class="form-text"><i class="bi bi-info-circle me-1"></i>„Projekt" → przekazanie do akceptacji</div>
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold">Komórka org.</label>
          <select name="org_unit_id" class="form-select">
            <option value="">— wybierz —</option>
            <?php try {
              $units = db_all("SELECT id, name FROM org_units WHERE status='active' ORDER BY sort_order, name");
              foreach ($units as $u):
                $sel = ($row['org_unit_id']??'')==$u['id']?'selected':''; ?>
            <option value="<?= h($u['id']) ?>" <?= $sel ?>><?= h($u['name']) ?></option>
            <?php endforeach; } catch(\Throwable $e) {} ?>
          </select>
        </div>
      </div>

      <?php if ($_is_admin): ?>
      <div id="uzasadnienie_section" class="mt-3"
           style="display:<?= in_array($row['status']??'',$_terminal_statuses,true)?'':'none' ?>">
        <div class="alert alert-warning d-flex gap-2 py-2 mb-2">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
          <div class="small">Status terminalny — wymagane uzasadnienie zapisywane w historii.</div>
        </div>
        <label class="form-label fw-semibold small">Uzasadnienie <span class="text-danger">*</span>
          <span class="text-muted fw-normal" id="uzasadnienie_status_label"></span>
        </label>
        <textarea name="uzasadnienie_statusu" id="uzasadnienie_statusu" class="form-control form-control-sm" rows="2"
                  placeholder="Podaj powód…"><?= h($row['uzasadnienie_statusu'] ?? '') ?></textarea>
      </div>
      <?php endif; ?>

      <div class="row g-3 mt-0">
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Opiekun wolontariusza</label>
          <select name="guardian_editor_id" id="guardian_editor_id" class="form-select">
            <option value="">— nie przypisano —</option>
            <?php foreach ($_editors as $_ed):
              $sel = ($row['guardian_editor_id'] ?? '') == $_ed['id'] ? 'selected' : ''; ?>
            <option value="<?= h($_ed['id']) ?>"
                    data-name="<?= h($_ed['display_name']) ?>"
                    data-initials="<?= h(guardian_initials($_ed['first_name']??'', $_ed['last_name']??'', $_ed['name']??'')) ?>"
                    <?= $sel ?>><?= h($_ed['display_name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" id="guardian_initials_hint">Inicjały trafiają do nr rejestru i IKA.</div>
          <input type="hidden" name="opiekun"          id="opiekun_hidden"       value="<?= h($row['opiekun'] ?? '') ?>">
          <input type="hidden" name="guardian_initials" id="guardian_initials_inp" value="<?= h($row['guardian_initials'] ?? '') ?>">
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Projekt / program</label>
          <input name="projekt_program" class="form-control"
                 value="<?= h($row['projekt_program'] ?? '') ?>" placeholder="np. Projekt A 2025">
        </div>
      </div>

      <div class="row g-3 mt-0">
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Data zawarcia <span class="text-danger">*</span></label>
          <div class="input-group">
            <input name="data_zawarcia" type="date" class="form-control"
                   value="<?= h($row['data_zawarcia'] ?? '') ?>" required>
            <button type="button" class="btn btn-outline-secondary btn-sm px-2"
                    onclick="_setDate('data_zawarcia', 0)" title="Dziś">
              <i class="bi bi-calendar-check"></i>
            </button>
          </div>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Data rozpoczęcia <span class="text-danger">*</span></label>
          <div class="input-group">
            <input name="data_rozpoczecia" type="date" class="form-control"
                   value="<?= h($row['data_rozpoczecia'] ?? '') ?>" required>
            <button type="button" class="btn btn-outline-secondary btn-sm px-2"
                    onclick="_setDate('data_rozpoczecia', 0)" title="Dziś">
              <i class="bi bi-calendar-check"></i>
            </button>
          </div>
        </div>
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Data zakończenia</label>
          <div class="input-group">
            <input name="data_zakonczenia" type="date" class="form-control" id="data_zakonczenia"
                   value="<?= h($row['data_zakonczenia'] ?? '') ?>">
            <button type="button" class="btn btn-outline-secondary btn-sm px-2"
                    onclick="_setDate('data_zakonczenia', 0)">Dziś</button>
            <button type="button" class="btn btn-outline-secondary btn-sm px-2"
                    onclick="_setDate('data_zakonczenia', 14)">+14</button>
          </div>
          <div class="form-check mt-1">
            <input class="form-check-input" type="checkbox" name="bezterminowa" id="bezterminowa" value="1"
                   <?= !empty($row['bezterminowa'])?'checked':'' ?>>
            <label class="form-check-label small" for="bezterminowa">Bezterminowa</label>
          </div>
        </div>
      </div>

    </div>
  </div>

  <div class="wiz-nav-btns">
    <span></span>
    <button type="button" class="btn btn-primary" onclick="goToStep(2)">
      Dalej: Wolontariusz <i class="bi bi-arrow-right ms-1"></i>
    </button>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     KROK 2: WOLONTARIUSZ
     ════════════════════════════════════════════════════════════════ -->
<div class="wiz-section" id="wiz-step-2">

  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#EFF4FF;color:#1E6DFF"><i class="bi bi-person-fill"></i></div>
      <div>
        <div class="wiz-card-title">Dane osobowe wolontariusza</div>
        <div class="wiz-card-subtitle">Tożsamość, adres, kontakt</div>
      </div>
    </div>
    <div class="wiz-card-body">

      <!-- Person picker -->
      <div class="mb-3 pb-3 border-bottom">
        <?= person_picker($row, [
          'id'    => 'pp',
          'label' => 'Wyszukaj istniejącą osobę',
          'fill'  => [
            'imie_nazwisko'  => 'f_imie_nazwisko',
            'pesel'          => 'f_pesel',
            'email'          => 'f_email',
            'telefon'        => 'f_telefon',
            'data_urodzenia' => 'f_data_urodzenia',
          ],
          'addr_widget'  => 'mainAddrWidget',
          'on_select_js' => 'var p=document.getElementById("f_pesel");if(p&&p.value)p.dispatchEvent(new Event("input",{bubbles:true}));if(typeof updateSummary==="function")updateSummary();',
        ]) ?>
      </div>

      <div class="row g-3">
        <div class="col-12">
          <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
          <input name="imie_nazwisko" id="f_imie_nazwisko" class="form-control"
                 value="<?= h($row['imie_nazwisko'] ?? '') ?>" placeholder="Jan Kowalski" required>
        </div>

        <!-- Tożsamość -->
        <div class="col-12">
          <label class="form-label fw-semibold mb-2">Identyfikacja tożsamości</label>
          <div class="d-flex gap-3 p-3 rounded" style="background:#F9FAFB;border:1px solid #E5E7EB">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="identity_type" id="id_pesel"
                     value="pesel" <?= ($row['id_document_type'] ?? '') ? '' : 'checked' ?>>
              <label class="form-check-label fw-semibold" for="id_pesel">
                <i class="bi bi-person-vcard me-1 text-primary"></i>PESEL
              </label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="identity_type" id="id_foreigner"
                     value="foreigner" <?= ($row['id_document_type'] ?? '') ? 'checked' : '' ?>>
              <label class="form-check-label fw-semibold" for="id_foreigner">
                <i class="bi bi-globe2 me-1 text-info"></i>Obcokrajowiec / brak PESEL
              </label>
            </div>
          </div>
        </div>

        <!-- PESEL path -->
        <div id="section_pesel" class="col-sm-4 <?= ($row['id_document_type'] ?? '') ? 'd-none' : '' ?>">
          <label class="form-label fw-semibold">
            PESEL
            <span id="pesel_gender_badge" class="badge ms-1" style="display:none;font-size:.65rem"></span>
          </label>
          <input name="pesel" id="f_pesel" class="form-control font-monospace" maxlength="11"
                 pattern="\d{11}" value="<?= h($row['pesel'] ?? '') ?>" autocomplete="off"
                 placeholder="00000000000"
                 <?= ($row['id_document_type'] ?? '') ? '' : 'required' ?>>
          <div id="pesel_error" class="invalid-feedback">Nieprawidłowa suma kontrolna.</div>
          <div id="pesel_age_info" class="form-text mt-1"></div>
        </div>
        <div id="section_pesel_dob" class="col-sm-3 <?= ($row['id_document_type'] ?? '') ? 'd-none' : '' ?>">
          <label class="form-label fw-semibold">
            Data urodzenia
            <span class="text-muted fw-normal small ms-1" id="peselDateHint" style="display:none">
              <i class="bi bi-magic"></i>
            </span>
          </label>
          <input name="data_urodzenia" id="f_data_urodzenia" type="date" class="form-control"
                 value="<?= h($row['data_urodzenia'] ?? '') ?>">
        </div>

        <!-- Foreigner path -->
        <div id="section_foreigner" class="col-12 <?= ($row['id_document_type'] ?? '') ? '' : 'd-none' ?>">
          <div class="row g-3">
            <div class="col-sm-3">
              <label class="form-label fw-semibold">Typ dokumentu</label>
              <select name="id_document_type" id="f_id_doc_type" class="form-select">
                <option value="">— wybierz —</option>
                <option value="passport" <?= ($row['id_document_type']??'')==='passport'?'selected':'' ?>>Paszport</option>
                <option value="id_card"  <?= ($row['id_document_type']??'')==='id_card' ?'selected':'' ?>>Dowód</option>
              </select>
            </div>
            <div class="col-sm-3">
              <label class="form-label fw-semibold">Numer dokumentu</label>
              <input name="id_document_number" id="f_id_doc_number" class="form-control font-monospace"
                     value="<?= h($row['id_document_number'] ?? '') ?>" placeholder="AB 123456">
            </div>
            <div class="col-sm-4">
              <label class="form-label fw-semibold">Przyczyna braku PESEL</label>
              <select name="no_pesel_reason" class="form-select">
                <option value="">— wybierz —</option>
                <option value="ukr_status"    <?= ($row['no_pesel_reason']??'')==='ukr_status'   ?'selected':'' ?>>Oczekiwanie na UKR</option>
                <option value="no_registry"   <?= ($row['no_pesel_reason']??'')==='no_registry'  ?'selected':'' ?>>Brak nr ewidencyjnego</option>
                <option value="other_legal"   <?= ($row['no_pesel_reason']??'')==='other_legal'  ?'selected':'' ?>>Inny powód legalnego pobytu</option>
              </select>
            </div>
            <div class="col-sm-3">
              <label class="form-label fw-semibold">Data urodzenia</label>
              <input name="data_urodzenia" id="f_data_urodzenia_f" type="date" class="form-control"
                     value="<?= h($row['data_urodzenia'] ?? '') ?>">
            </div>
          </div>
        </div>

        <!-- Adres -->
        <div class="col-12">
          <label class="form-label fw-semibold"><i class="bi bi-house me-1 text-secondary"></i>Adres zamieszkania</label>
          <?= address_widget($row, ['copy_button' => true, 'widget_id' => 'mainAddrWidget']) ?>
        </div>

        <div class="col-sm-4">
          <label class="form-label fw-semibold">Telefon</label>
          <div class="input-group">
            <span class="input-group-text text-muted fw-semibold">+48</span>
            <input name="telefon" id="f_telefon" class="form-control" type="tel"
                   placeholder="123 456 789" value="<?= h($row['telefon'] ?? '') ?>">
          </div>
        </div>
        <div class="col-sm-5">
          <label class="form-label fw-semibold">E-mail <span class="fw-normal text-muted small">(login do panelu)</span></label>
          <input name="email" id="f_email" class="form-control" type="email"
                 value="<?= h($row['email'] ?? '') ?>" placeholder="jan@email.com">
        </div>
        <div class="col-sm-3 d-flex align-items-end">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="niepelnoletni" id="niepelnoletni"
                   value="1" <?= !empty($row['niepelnoletni'])?'checked':'' ?>>
            <label class="form-check-label fw-semibold" for="niepelnoletni">
              <i class="bi bi-person-exclamation text-warning me-1"></i>Niepełnoletni/a
            </label>
          </div>
        </div>
      </div>

      <!-- Dane rodzica (niepełnoletni) -->
      <div id="rodzic_section" class="mt-3 pt-3 border-top"
           style="display:<?= !empty($row['niepelnoletni'])?'':'none' ?>">
        <div class="d-flex align-items-center gap-2 mb-3">
          <span class="badge bg-warning text-dark"><i class="bi bi-person-hearts me-1"></i>Rodzic / opiekun prawny</span>
        </div>
        <div class="row g-3">
          <div class="col-sm-4">
            <label class="form-label fw-semibold small">Imię i nazwisko rodzica</label>
            <input name="rodzic_imie_nazwisko" class="form-control"
                   value="<?= h($row['rodzic_imie_nazwisko'] ?? '') ?>" placeholder="Anna Kowalska">
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold small">E-mail rodzica</label>
            <input name="rodzic_email" class="form-control" type="email"
                   value="<?= h($row['rodzic_email'] ?? '') ?>" placeholder="rodzic@email.com">
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold small">Telefon rodzica</label>
            <div class="input-group">
              <span class="input-group-text fw-semibold">+48</span>
              <input name="rodzic_telefon" class="form-control" type="tel"
                     value="<?= h($row['rodzic_telefon'] ?? '') ?>" placeholder="123 456 789">
            </div>
          </div>
          <div class="col-sm-4">
            <label class="form-label fw-semibold small">Zgoda opiekuna (plik)</label>
            <input name="zgoda_opiekuna" type="file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
          </div>
        </div>
      </div>

    </div>
  </div>

  <div class="wiz-nav-btns">
    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(1)">
      <i class="bi bi-arrow-left me-1"></i>Wstecz
    </button>
    <button type="button" class="btn btn-primary" onclick="goToStep(3)">
      Dalej: Szczegóły <i class="bi bi-arrow-right ms-1"></i>
    </button>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     KROK 3: SZCZEGÓŁY WOLONTARIATU
     ════════════════════════════════════════════════════════════════ -->
<div class="wiz-section" id="wiz-step-3">

  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#FEF3E2;color:#D97706"><i class="bi bi-geo-alt-fill"></i></div>
      <div>
        <div class="wiz-card-title">Szczegóły wolontariatu</div>
        <div class="wiz-card-subtitle">Przedmiot, miejsce, czas, zwroty kosztów</div>
      </div>
    </div>
    <div class="wiz-card-body">

      <div class="mb-3">
        <label class="form-label fw-semibold">Przedmiot porozumienia</label>
        <textarea name="przedmiot_porozumienia" class="form-control" rows="3"
                  placeholder="Opis zakresu działania wolontariusza…"><?= h($row['przedmiot_porozumienia'] ?? '') ?></textarea>
      </div>

      <div class="row g-3">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Miejsce wolontariatu</label>
          <input name="miejsce_wolontariatu" class="form-control"
                 value="<?= h($row['miejsce_wolontariatu'] ?? '') ?>" placeholder="Siedziba organizacji / zdalnie">
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold">Godz./tydzień</label>
          <div class="input-group">
            <input name="godzin_tygodniowo" type="number" step="0.5" min="0" class="form-control"
                   value="<?= h($row['godzin_tygodniowo'] ?? '') ?>" placeholder="8">
            <span class="input-group-text text-muted">h</span>
          </div>
        </div>
        <div class="col-sm-3">
          <label class="form-label fw-semibold">Godz. przepracowane</label>
          <div class="input-group">
            <input name="godzin_przepracowanych" type="number" step="0.5" min="0" class="form-control"
                   value="<?= h($row['godzin_przepracowanych'] ?? '') ?>" placeholder="0">
            <span class="input-group-text text-muted">h</span>
          </div>
        </div>
      </div>

      <!-- Zwrot kosztów -->
      <div class="mt-3 pt-3 border-top">
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" name="zwrot_kosztow" id="zwrot_kosztow"
                 value="1" <?= !empty($row['zwrot_kosztow'])?'checked':'' ?>>
          <label class="form-check-label fw-semibold" for="zwrot_kosztow">
            <i class="bi bi-receipt-cutoff me-1 text-success"></i>Zwrot kosztów wolontariatu
          </label>
        </div>
        <div id="zwrot_kosztow_opis_field" class="row g-3" style="display:<?= !empty($row['zwrot_kosztow'])?'':'none' ?>">
          <div class="col-sm-8">
            <label class="form-label small fw-semibold">Opis kosztów</label>
            <input name="zwrot_kosztow_opis" class="form-control"
                   placeholder="np. dojazd, posiłki, materiały…"
                   value="<?= h($row['zwrot_kosztow_opis'] ?? '') ?>">
          </div>
          <div class="col-sm-4">
            <label class="form-label small fw-semibold">Limit zwrotu</label>
            <div class="input-group">
              <input name="limit_zwrotu_kosztow" type="number" step="0.01" min="0"
                     class="form-control" placeholder="np. 500"
                     value="<?= h($row['limit_zwrotu_kosztow'] ?? '') ?>">
              <span class="input-group-text text-muted">zł</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Działanie i grant -->
      <div class="row g-3 mt-1">
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Działanie</label>
          <?php try { $__actions = db_all("SELECT id, nazwa FROM actions WHERE status NOT IN ('anulowane','zakończone') ORDER BY nazwa"); }
                catch (\Throwable $e) { $__actions = []; } ?>
          <select name="action_id" id="action_id_select" class="form-select ts-action">
            <option value="">— brak —</option>
            <?php foreach ($__actions as $a):
              $sel = ($row['action_id']??'')==$a['id']?'selected':''; ?>
            <option value="<?= h($a['id']) ?>" data-nazwa="<?= h($a['nazwa']) ?>" <?= $sel ?>><?= h($a['nazwa']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-6">
          <label class="form-label fw-semibold">Grant / dotacja</label>
          <?php try { $__grants = db_all("SELECT id, nazwa, donator FROM grants WHERE status IN ('przyznany','w realizacji','rozliczany') ORDER BY nazwa"); }
                catch (\Throwable $e) { $__grants = []; } ?>
          <select name="grant_id" id="grant_id_select" class="form-select ts-grant">
            <option value="">— brak —</option>
            <?php foreach ($__grants as $g):
              $sel = ($row['grant_id']??'')==$g['id']?'selected':''; ?>
            <option value="<?= h($g['id']) ?>" <?= $sel ?>><?= h($g['nazwa']) ?> (<?= h($g['donator']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label fw-semibold">Uwagi wewnętrzne</label>
          <textarea name="uwagi" class="form-control" rows="2"
                    placeholder="Notatki, szczególne ustalenia…"><?= h($row['uwagi'] ?? '') ?></textarea>
        </div>
      </div>

    </div>
  </div>

  <div class="wiz-nav-btns">
    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(2)">
      <i class="bi bi-arrow-left me-1"></i>Wstecz
    </button>
    <button type="button" class="btn btn-primary" onclick="goToStep(4)">
      Dalej: Bezpieczeństwo <i class="bi bi-arrow-right ms-1"></i>
    </button>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     KROK 4: BHP I UBEZPIECZENIA
     ════════════════════════════════════════════════════════════════ -->
<div class="wiz-section" id="wiz-step-4">

  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#F3E8F9;color:#7C3AED"><i class="bi bi-shield-check-fill"></i></div>
      <div>
        <div class="wiz-card-title">BHP i ubezpieczenia</div>
        <div class="wiz-card-subtitle">Szkolenie BHP, NNW, OC</div>
      </div>
    </div>
    <div class="wiz-card-body">

      <div class="row g-3">
        <div class="col-sm-4">
          <div class="p-3 border rounded" style="background:#F9FAFB">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" name="szkolenie_bhp" id="szkolenie_bhp"
                     value="1" <?= !empty($row['szkolenie_bhp'])?'checked':'' ?>>
              <label class="form-check-label fw-semibold" for="szkolenie_bhp">
                <i class="bi bi-person-gear me-1 text-warning"></i>Szkolenie BHP
              </label>
            </div>
            <label class="form-label small text-muted">Data szkolenia</label>
            <input name="data_szkolenia_bhp" type="date" class="form-control form-control-sm"
                   id="data_szkolenia_bhp" value="<?= h($row['data_szkolenia_bhp'] ?? '') ?>"
                   <?= empty($row['szkolenie_bhp'])?'disabled':'' ?>>
          </div>
        </div>
        <div class="col-sm-4">
          <div class="p-3 border rounded" style="background:#F9FAFB">
            <div class="form-check form-switch mb-2">
              <input class="form-check-input" type="checkbox" name="ubezpieczenie_nnw" id="ubezpieczenie_nnw"
                     value="1" <?= !empty($row['ubezpieczenie_nnw'])?'checked':'' ?>>
              <label class="form-check-label fw-semibold" for="ubezpieczenie_nnw">
                <i class="bi bi-shield-fill-check me-1 text-success"></i>Ubezpieczenie NNW
              </label>
            </div>
            <label class="form-label small text-muted">Numer polisy</label>
            <input name="numer_polisy_nnw" class="form-control form-control-sm" id="numer_polisy_nnw"
                   value="<?= h($row['numer_polisy_nnw'] ?? '') ?>" placeholder="Nr polisy"
                   <?= empty($row['ubezpieczenie_nnw'])?'disabled':'' ?>>
          </div>
        </div>
        <div class="col-sm-4">
          <div class="p-3 border rounded" style="background:#F9FAFB">
            <div class="form-check form-switch mt-3">
              <input class="form-check-input" type="checkbox" name="ubezpieczenie_oc" id="ubezpieczenie_oc"
                     value="1" <?= !empty($row['ubezpieczenie_oc'])?'checked':'' ?>>
              <label class="form-check-label fw-semibold" for="ubezpieczenie_oc">
                <i class="bi bi-shield-fill me-1 text-info"></i>Ubezpieczenie OC
              </label>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>

  <div class="wiz-nav-btns">
    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(3)">
      <i class="bi bi-arrow-left me-1"></i>Wstecz
    </button>
    <button type="button" class="btn btn-primary" onclick="goToStep(5)">
      Dalej: Podpis i pliki <i class="bi bi-arrow-right ms-1"></i>
    </button>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     KROK 5: PODPISANIE I PLIKI
     ════════════════════════════════════════════════════════════════ -->
<div class="wiz-section" id="wiz-step-5">

  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#EEF4FF;color:#0176D3"><i class="bi bi-pen-fill"></i></div>
      <div>
        <div class="wiz-card-title">Forma podpisania i pliki</div>
        <div class="wiz-card-subtitle">Sposób zawarcia umowy, upload skanów</div>
      </div>
    </div>
    <div class="wiz-card-body">

      <div class="row g-3">
        <div class="col-sm-4">
          <label class="form-label fw-semibold">Forma</label>
          <select name="forma_podpisania" class="form-select" id="forma_podpisania">
            <option value="">— nie wybrano —</option>
            <?php foreach ([
              'papierowa'             => 'Papierowa',
              'elektroniczna'         => 'Elektroniczna (DocuSign / Autenti)',
              'epodpis_kwalifikowany' => 'ePodpis kwalifikowany (X.509)',
            ] as $_fv => $_fl):
              $sel = ($row['forma_podpisania']??'')===$_fv ? 'selected' : ''; ?>
            <option value="<?= $_fv ?>" <?= $sel ?>><?= $_fl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Elektroniczna (DocuSign / Autenti) -->
      <div id="el_fields" class="mt-3"
           style="display:<?= ($row['forma_podpisania']??'')==='elektroniczna'?'':'none' ?>">
        <div class="alert alert-primary py-2 mb-0 small d-flex align-items-start gap-2">
          <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
          <div>
            <strong>Podpis niekwalifikowany (DocuSign / Autenti)</strong><br>
            Po zapisaniu umowy wyślij dokument do podpisu bezpośrednio z widoku umowy —
            sekcja <em>Podpisanie</em> → przyciski <strong>DocuSign</strong> lub <strong>Autenti</strong>.
          </div>
        </div>
      </div>

      <!-- ePodpis kwalifikowany -->
      <div id="epodpis_fields" class="mt-3" style="display:<?= ($row['forma_podpisania']??'')==='epodpis_kwalifikowany'?'':'none' ?>">
        <div class="card border-0 shadow-sm"
             style="border-left:4px solid #16a34a!important;background:#f0fdf4">
          <div class="card-body py-3 px-4">

            <div class="d-flex align-items-center gap-2 mb-3">
              <i class="bi bi-shield-lock-fill text-success fs-5"></i>
              <span class="fw-bold" style="color:#16a34a">Podpis kwalifikowany (X.509 / eIDAS)</span>
            </div>
            <div class="small text-muted mb-3">
              Pobierz wzór DOCX, podpisz kwalifikowanym podpisem elektronicznym (Certum, SimplySign, mSzafir itp.),
              następnie wgraj podpisany plik. Jeśli umowa była podpisana poza systemem — wgraj plik bezpośrednio.
            </div>

            <div class="row g-3">
              <div class="col-sm-4">
                <label class="form-label small fw-semibold">Dostawca (TSP)</label>
                <input name="epodpis_dostawca" class="form-control form-control-sm"
                       placeholder="Certum, SimplySign, mSzafir…"
                       value="<?= h($row['epodpis_dostawca'] ?? '') ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold">Nr seryjny certyfikatu</label>
                <input name="epodpis_nr_certyfikatu" class="form-control form-control-sm font-monospace"
                       placeholder="np. 1A:2B:3C:…"
                       value="<?= h($row['epodpis_nr_certyfikatu'] ?? '') ?>">
              </div>
              <div class="col-sm-4">
                <label class="form-label small fw-semibold">Ważność certyfikatu</label>
                <input name="epodpis_data_waznosci" type="date" class="form-control form-control-sm"
                       value="<?= h($row['epodpis_data_waznosci'] ?? '') ?>">
              </div>
            </div>

            <div class="row g-3 mt-1">
              <div class="col-12">
                <label class="form-label small fw-semibold">
                  <i class="bi bi-cloud-upload me-1"></i>Wgraj podpisany dokument
                  <span class="text-muted fw-normal">(opcjonalne przy tworzeniu — możesz dodać później)</span>
                </label>
                <input name="plik_potwierdzenia" type="file" class="form-control form-control-sm"
                       accept=".pdf,.docx,.xades,.p7m">
                <div class="form-text">
                  PDF lub DOCX z osadzonym podpisem kwalifikowanym (PAdES / XAdES / CAdES).
                </div>
              </div>
            </div>

          </div>
        </div>
      </div>

      <!-- Wzorzec umowy -->
      <?php
      require_once dirname(dirname(__DIR__)) . '/includes/contract_template_engine.php';
      cte_migrate();
      $_add_tpls = cte_list('wolontariat');
      ?>
      <div class="mt-3 pt-3 border-top">
        <label class="form-label fw-semibold">
          <i class="bi bi-file-earmark-text me-1"></i>Wzorzec umowy
          <span class="text-muted fw-normal small">(opcjonalne)</span>
        </label>
        <?php if ($_add_tpls): ?>
        <select name="template_id" class="form-select">
          <option value="">— bez wzorca —</option>
          <?php foreach ($_add_tpls as $_atpl): ?>
          <option value="<?= (int)$_atpl['id'] ?>"
                  <?= ((int)($row['template_id'] ?? 0) === (int)$_atpl['id']) ? 'selected' : '' ?>>
            <?= h($_atpl['name']) ?>
            <?php if ($_atpl['type'] !== 'universal'): ?>
              <span class="text-muted">(<?= h($_atpl['type']) ?>)</span>
            <?php endif; ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Wzorzec zostanie powiązany z umową — łatwy dostęp do podglądu i DOCX z widoku.</div>
        <?php else: ?>
        <div class="alert alert-light border py-2 mb-0 small">
          <i class="bi bi-info-circle me-1 text-muted"></i>Brak zdefiniowanych wzorców dla tego typu umowy.
          <?php if (is_admin()): ?>
          <a href="<?= APP_URL ?>/admin/template_editor.php?new=1&type=wolontariat" target="_blank">
            Utwórz wzorzec →
          </a>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Upload pliku umowy -->
      <div class="mt-3 pt-3 border-top">
        <label class="form-label fw-semibold">
          <i class="bi bi-paperclip me-1"></i>Plik porozumienia (skan / oryginał)
          <?php $__add_plik_level = $_add_field_cfg['plik_umowy'] ?? 'recommended'; ?>
          <?php if ($__add_plik_level === 'required'): ?>
            <span class="badge bg-danger ms-1">wymagany</span>
          <?php elseif ($__add_plik_level === 'recommended'): ?>
            <span class="badge bg-warning text-dark ms-1">zalecany</span>
          <?php else: ?>
            <span class="text-muted fw-normal small">(opcjonalny)</span>
          <?php endif; ?>
        </label>
        <input name="plik_umowy" type="file" class="form-control" accept=".pdf,.docx">
        <div class="form-text">
          PDF lub DOCX, maks. 20 MB<?php if ($__add_plik_level !== 'required'): ?> — możesz dodać później z widoku umowy<?php endif; ?>
        </div>
      </div>

    </div>
  </div>

  <!-- Microsoft 365 -->
  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#EEF4FF;color:#0078D4"><i class="bi bi-microsoft"></i></div>
      <div>
        <div class="wiz-card-title">Konto Microsoft 365</div>
        <div class="wiz-card-subtitle">Utwórz lub powiąż konto M365 wolontariusza</div>
      </div>
    </div>
    <div class="wiz-card-body">
      <?php if ($m365_enabled): ?>
      <div class="d-flex flex-column gap-2">
        <div class="form-check">
          <input class="form-check-input" type="radio" name="m365_create_mode" id="m365_mode_auto" value="auto" checked>
          <label class="form-check-label fw-semibold" for="m365_mode_auto">
            <i class="bi bi-magic text-primary me-1"></i>Utwórz konto M365 automatycznie
          </label>
          <div class="form-text ms-4">Jedno hasło do portalu i Microsoft 365</div>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="m365_create_mode" id="m365_mode_manual" value="manual">
          <label class="form-check-label" for="m365_mode_manual">Konto M365 już istnieje — podaj dane</label>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="m365_create_mode" id="m365_mode_none" value="none">
          <label class="form-check-label text-muted" for="m365_mode_none">Bez konta M365</label>
        </div>
      </div>
      <?php else: ?>
      <div class="text-muted small mb-2">
        <i class="bi bi-info-circle me-1"></i>M365 nie jest skonfigurowane.
        <a href="<?= APP_URL ?>/admin/m365.php" class="small">Ustawienia</a>
      </div>
      <div class="form-check mb-1">
        <input class="form-check-input" type="radio" name="m365_create_mode" id="m365_mode_manual" value="manual">
        <label class="form-check-label" for="m365_mode_manual">Konto M365 istnieje — wpisz dane</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="m365_create_mode" id="m365_mode_none" value="none" checked>
        <label class="form-check-label text-muted" for="m365_mode_none">Bez konta M365</label>
      </div>
      <?php endif; ?>

      <div id="m365_manual_fields" class="mt-3 row g-2" style="display:none">
        <div class="col-sm-6">
          <label class="form-label small fw-semibold">Login M365</label>
          <input name="m365_login" class="form-control form-control-sm font-monospace"
                 value="<?= h($row['m365_login'] ?? '') ?>" placeholder="imie.nazwisko@domena.pl">
        </div>
        <div class="col-sm-6">
          <label class="form-label small fw-semibold">User ID (Azure AD)</label>
          <input name="m365_user_id" class="form-control form-control-sm font-monospace"
                 value="<?= h($row['m365_user_id'] ?? '') ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- Zakres dostępu portalu -->
  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#F0FDF4;color:#16A34A"><i class="bi bi-shield-check"></i></div>
      <div>
        <div class="wiz-card-title">Zakres dostępu do portalu</div>
        <div class="wiz-card-subtitle">Wybierz co wolontariusz zobaczy po zalogowaniu</div>
      </div>
    </div>
    <div class="wiz-card-body">
      <div class="d-flex flex-column gap-2">
        <div class="form-check">
          <input class="form-check-input" type="radio" name="portal_scope" id="scope_full" value=""
                 <?= empty($row['portal_scope']) ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="scope_full">
            <i class="bi bi-grid-3x3-gap-fill text-primary me-1"></i>Pełny dostęp do portalu
          </label>
          <div class="form-text ms-4">Wolontariusz widzi wszystkie dostępne moduły (umowy, zadania, komunikaty, katalog itp.)</div>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="portal_scope" id="scope_tasks" value="tasks_only"
                 <?= ($row['portal_scope'] ?? '') === 'tasks_only' ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="scope_tasks">
            <i class="bi bi-check2-square text-success me-1"></i>Tylko moduł Zadania — tryb prosty
          </label>
          <div class="form-text ms-4">Po zalogowaniu wolontariusz trafia bezpośrednio do listy swoich zadań. Inne moduły są ukryte.</div>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="portal_scope" id="scope_crm" value="crm_only"
                 <?= ($row['portal_scope'] ?? '') === 'crm_only' ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="scope_crm">
            <i class="bi bi-diagram-2-fill text-info me-1"></i>Tylko moduł CRM
          </label>
          <div class="form-text ms-4">Po zalogowaniu wolontariusz trafia bezpośrednio do CRM. Inne moduły są ukryte.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- Security Group M365 -->
  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#EEF4FF;color:#0078D4"><i class="bi bi-people-fill"></i></div>
      <div>
        <div class="wiz-card-title">Security Group M365 <span class="text-danger">*</span></div>
        <div class="wiz-card-subtitle">Wolontariusz zostanie dodany do wybranej grupy bezpieczeństwa</div>
      </div>
    </div>
    <div class="wiz-card-body">
      <div class="row g-3">
        <div class="col-sm-8">
          <label class="form-label fw-semibold">Wybierz grupę <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text bg-white"><i class="bi bi-people-fill text-primary"></i></span>
            <select name="m365_security_group_id" id="add_sg_id" class="form-select" required>
              <option value="">— ładowanie grup… —</option>
            </select>
            <button type="button" class="btn btn-outline-secondary" id="add_sg_refresh" title="Odśwież listę grup">
              <i class="bi bi-arrow-clockwise"></i>
            </button>
          </div>
          <input type="hidden" name="m365_security_group_name" id="add_sg_name">
          <div id="add_sg_status" class="form-text mt-1"></div>
        </div>

        <?php if (!empty($_task_workspaces)): ?>
        <!-- Sugestia obszaru zadań -->
        <div id="sg_workspace_suggest" style="display:none;margin-top:.85rem">
          <div class="alert alert-info d-flex gap-2 py-2 mb-0" style="font-size:.85rem">
            <i class="bi bi-kanban-fill flex-shrink-0 mt-1" style="color:#0284c7"></i>
            <div style="flex:1">
              <div class="fw-semibold mb-1">Przypisz też do obszaru w module Zadania</div>
              <select name="suggested_workspace_id" id="sg_workspace_id" class="form-select form-select-sm">
                <option value="">— pomiń (nie przypisuj) —</option>
                <?php foreach ($_task_workspaces as $ws): ?>
                <option value="<?= h($ws['id']) ?>"><?= h($ws['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text mt-1">Wolontariusz zostanie dodany jako <strong>member</strong> wybranego obszaru.</div>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div>
    </div>
  </div>

  <!-- Dostęp do Canva -->
  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#FFF0F3;color:#8B3CF7">
        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
          <path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.293 14.707a1 1 0 0 1-1.414 0L12 13.414l-2.879 2.879a1 1 0 0 1-1.414-1.414L10.586 12 7.707 9.121a1 1 0 0 1 1.414-1.414L12 10.586l2.879-2.879a1 1 0 0 1 1.414 1.414L13.414 12l2.879 2.879a1 1 0 0 1 0 1.414z"/>
        </svg>
      </div>
      <div>
        <div class="wiz-card-title">Dostęp do Canva</div>
        <div class="wiz-card-subtitle">Zaproszenie do przestrzeni Canva Pro organizacji</div>
      </div>
    </div>
    <div class="wiz-card-body">
      <?php
      $m365_configured = $m365_enabled;
      $has_m365_login  = !empty($row['m365_login']);
      ?>

      <?php if (!$m365_configured): ?>
      <div class="alert alert-secondary py-2 small mb-0">
        <i class="bi bi-info-circle me-1"></i>
        Integracja M365 musi być skonfigurowana, aby nadawać dostęp do Canva.
      </div>
      <?php else: ?>
      <div class="form-check d-flex align-items-start gap-2">
        <input class="form-check-input mt-1 flex-shrink-0" type="checkbox"
               name="canva_access" id="canva_access" value="1"
               <?= !empty($row['canva_access']) ? 'checked' : '' ?>
               onchange="toggleCanvaInfo(this.checked)">
        <label class="form-check-label" for="canva_access" style="cursor:pointer">
          <span class="fw-semibold">Nadaj dostęp do Canva Pro organizacji</span>
          <div class="form-text mt-1">
            System wyśle powiadomienie do administratora Canva oraz e-mail do wolontariusza
            z instrukcją jak zalogować się przez Microsoft 365.
          </div>
        </label>
      </div>

      <div id="canva_info_block" style="display:<?= !empty($row['canva_access']) ? '' : 'none' ?>;margin-top:.85rem">
        <div class="alert alert-warning d-flex gap-2 py-2 mb-2" style="font-size:.83rem">
          <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
          <div>
            <strong>Canva Pro — zaproszenie ręczne przez admina.</strong><br>
            Canva API nie obsługuje automatycznych zaproszeń na planie Pro.
            System wyśle notyfikację do admina, który wykona zaproszenie w panelu Canva.
          </div>
        </div>
        <div class="alert alert-info d-flex gap-2 py-2 mb-0" id="canva_m365_check" style="font-size:.83rem;display:none!important">
          <i class="bi bi-microsoft flex-shrink-0 mt-1"></i>
          <div>Wolontariusz musi mieć konto M365 — logowanie do Canva odbywa się przez Microsoft 365.</div>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="wiz-nav-btns">
    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(4)">
      <i class="bi bi-arrow-left me-1"></i>Wstecz
    </button>
    <button type="button" class="btn btn-primary" onclick="goToStep(6)">
      Dalej: Dodatkowe <i class="bi bi-arrow-right ms-1"></i>
    </button>
  </div>
</div>

<!-- ════════════════════════════════════════════════════════════════
     KROK 6: DODATKOWE (adresy, numery, webNGO)
     ════════════════════════════════════════════════════════════════ -->
<div class="wiz-section" id="wiz-step-6">

  <!-- Adres korespondencyjny -->
  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#F9FAFB;color:#6B7280"><i class="bi bi-mailbox2-flag"></i></div>
      <div>
        <div class="wiz-card-title">Adres korespondencyjny <span class="badge bg-secondary-subtle text-secondary ms-2" style="font-size:.68rem">opcjonalny</span></div>
        <div class="wiz-card-subtitle">Do wysyłki Postivo (jeśli inny niż zamieszkania)</div>
      </div>
    </div>
    <div class="wiz-card-body">
      <div class="mb-3">
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="_copyToCorrespondence()">
          <i class="bi bi-arrow-down-circle me-1"></i>Uzupełnij z adresu zamieszkania
        </button>
      </div>
      <div class="row g-3">
        <div class="col-sm-8">
          <label class="form-label small">Imię i nazwisko / odbiorca</label>
          <input name="adres_odbiorca" id="adres_odbiorca" class="form-control form-control-sm"
                 value="<?= h($row['adres_odbiorca'] ?? '') ?>" placeholder="Jan Kowalski">
        </div>
        <div class="col-sm-4">
          <label class="form-label small">Kraj</label>
          <select name="adres_kraj" class="form-select form-select-sm">
            <?php foreach (['PL'=>'PL — Polska','DE'=>'DE — Niemcy','GB'=>'GB — Wielka Brytania','UA'=>'UA — Ukraina','FR'=>'FR — Francja'] as $k=>$l):
              $sel = ($row['adres_kraj']??'PL')===$k?'selected':''; ?>
            <option value="<?= $k ?>" <?= $sel ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-8">
          <label class="form-label small">Adres (linia 1)</label>
          <input name="adres_linia1" class="form-control form-control-sm"
                 value="<?= h($row['adres_linia1'] ?? '') ?>" placeholder="ul. Przykładowa 1/2">
        </div>
        <div class="col-sm-4">
          <label class="form-label small">Linia 2</label>
          <input name="adres_linia2" class="form-control form-control-sm"
                 value="<?= h($row['adres_linia2'] ?? '') ?>" placeholder="m. 5">
        </div>
        <div class="col-sm-4">
          <label class="form-label small">Kod pocztowy</label>
          <input name="adres_kod_pocztowy" class="form-control form-control-sm"
                 value="<?= h($row['adres_kod_pocztowy'] ?? '') ?>" placeholder="00-001" maxlength="10">
        </div>
        <div class="col-sm-8">
          <label class="form-label small">Miasto</label>
          <input name="adres_miasto" class="form-control form-control-sm"
                 value="<?= h($row['adres_miasto'] ?? '') ?>" placeholder="Warszawa">
        </div>
      </div>
    </div>
  </div>

  <!-- Numery referencyjne -->
  <div class="wiz-card">
    <div class="wiz-card-header">
      <div class="wiz-card-icon" style="background:#F9FAFB;color:#6B7280"><i class="bi bi-hash"></i></div>
      <div>
        <div class="wiz-card-title">Numery referencyjne i webNGO <span class="badge bg-secondary-subtle text-secondary ms-2" style="font-size:.68rem">opcjonalne</span></div>
      </div>
    </div>
    <div class="wiz-card-body">
      <div class="row g-3 mb-3">
        <div class="col-sm-4">
          <label class="form-label small fw-semibold">Nr roboczy</label>
          <input name="nr_roboczy" class="form-control form-control-sm"
                 value="<?= h($row['nr_roboczy']??'') ?>" placeholder="PR-2026-001">
        </div>
        <div class="col-sm-4">
          <label class="form-label small fw-semibold">Nr ogólny (stary system)</label>
          <input name="nr_system" class="form-control form-control-sm" value="<?= h($row['nr_system']??'') ?>">
        </div>
        <div class="col-sm-4">
          <label class="form-label small fw-semibold">Nr rejestru</label>
          <input name="nr_rejestru" class="form-control form-control-sm font-monospace"
                 value="<?= h($row['nr_rejestru']??'') ?>"
                 placeholder="<?= h(suggest_nr_rejestru($row['opiekun']??'')) ?>">
          <div class="form-text" style="font-size:.72rem">Puste = nadany automatycznie.</div>
        </div>
      </div>

      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="z_webngo" id="z_webngo" value="1"
               <?= !empty($row['z_webngo'])?'checked':'' ?>>
        <label class="form-check-label fw-semibold" for="z_webngo">
          <i class="bi bi-box-arrow-in-down me-1 text-primary"></i>Umowa przeniesiona z webNGO
        </label>
      </div>
      <div class="row g-3" id="webngo_fields"
           style="display:<?= !empty($row['z_webngo'])?'':'none' ?>">
        <div class="col-sm-6">
          <label class="form-label small fw-semibold">ID webNGO</label>
          <input name="webngo_id" id="webngo_id" class="form-control form-control-sm font-monospace"
                 value="<?= h($row['webngo_id'] ?? '') ?>" placeholder="4829">
        </div>
        <div class="col-sm-6">
          <label class="form-label small fw-semibold">Numer umowy z webNGO</label>
          <input name="webngo_numer_umowy" id="webngo_numer_umowy" class="form-control form-control-sm"
                 value="<?= h($row['webngo_numer_umowy'] ?? '') ?>" placeholder="JST/WOL/2025/08">
        </div>
      </div>
    </div>
  </div>

  <div class="form-check mb-2 mt-3">
    <input class="form-check-input" type="checkbox" name="nie_mam_drukarki" id="nie_mam_drukarki" value="1">
    <label class="form-check-label text-muted small" for="nie_mam_drukarki">
      <i class="bi bi-printer"></i> Nie mam drukarki — zapisz jako PDF do wydruku
    </label>
  </div>
  <div class="wiz-nav-btns">
    <button type="button" class="btn btn-outline-secondary" onclick="goToStep(5)">
      <i class="bi bi-arrow-left me-1"></i>Wstecz
    </button>
    <button type="submit" id="btnSave" class="btn btn-success btn-lg px-4">
      <i class="bi bi-check-lg me-1"></i>Zapisz porozumienie
    </button>
  </div>
</div>

</div><!-- /col-xl-8 -->

<!-- ══ SIDEBAR: Live preview + szybki zapis ══════════════════════════════════ -->
<div class="col-xl-4">
  <div class="wiz-preview">
    <div class="wiz-preview-header">
      <i class="bi bi-card-list"></i>Podgląd umowy
    </div>
    <div class="wiz-preview-body">
      <div class="wiz-preview-row"><span class="wiz-preview-label">Numer</span><span class="wiz-preview-val" id="sum-num"><?= h($row['numer_umowy']) ?></span></div>
      <div class="wiz-preview-row"><span class="wiz-preview-label">Wolontariusz</span><span class="wiz-preview-val" id="sum-name">—</span></div>
      <div class="wiz-preview-row"><span class="wiz-preview-label">Status</span><span class="wiz-preview-val" id="sum-status">—</span></div>
      <div class="wiz-preview-row"><span class="wiz-preview-label">Data zawarcia</span><span class="wiz-preview-val" id="sum-data-zawarcia">—</span></div>
      <div class="wiz-preview-row"><span class="wiz-preview-label">Okres</span><span class="wiz-preview-val" id="sum-okres">—</span></div>
      <div class="wiz-preview-row"><span class="wiz-preview-label">PESEL</span><span class="wiz-preview-val font-monospace" id="sum-pesel">—</span></div>
      <div class="wiz-preview-row"><span class="wiz-preview-label">E-mail</span><span class="wiz-preview-val" id="sum-email">—</span></div>
      <div class="wiz-preview-row"><span class="wiz-preview-label">Wiek</span><span class="wiz-preview-val" id="sum-wiek">—</span></div>
      <div class="wiz-preview-row"><span class="wiz-preview-label">Miejsce</span><span class="wiz-preview-val" id="sum-miejsce">—</span></div>
      <div class="wiz-preview-row"><span class="wiz-preview-label">Opiekun</span><span class="wiz-preview-val" id="sum-opiekun">—</span></div>
    </div>
    <div class="p-3 border-top">
      <div class="d-grid gap-2">
        <button type="submit" class="btn btn-success fw-semibold">
          <i class="bi bi-check-lg me-1"></i>Zapisz porozumienie
        </button>
        <a href="list.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-x me-1"></i>Anuluj
        </a>
      </div>
    </div>
  </div>

  <!-- Postęp wypełnienia -->
  <div class="card border-0 shadow-sm mt-3" style="border-radius:12px">
    <div class="card-body py-3">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <span style="font-size:.8rem;font-weight:600;color:#374151">Postęp formularza</span>
        <span style="font-size:.8rem;color:#6B7280" id="progress-label">0%</span>
      </div>
      <div style="height:6px;background:#F3F4F6;border-radius:3px">
        <div id="progress-bar" style="height:6px;border-radius:3px;background:#1E6DFF;width:0%;transition:width .3s"></div>
      </div>
      <div class="d-flex justify-content-between mt-2">
        <?php for ($i = 1; $i <= 6; $i++): ?>
        <div style="font-size:.65rem;color:#9CA3AF;text-align:center">
          <div id="prog-step-<?= $i ?>" style="width:8px;height:8px;border-radius:50%;background:#E5E7EB;margin:0 auto 2px"></div>
          <?= $i ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>
  </div>
</div><!-- /col-xl-4 -->

</div><!-- /row -->
</form>

<link href="https://cdn.jsdelivr.net/npm/tom-select@2/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2/dist/js/tom-select.complete.min.js"></script>

<script>
// ── Tom Select ────────────────────────────────────────────────────────────────
['ts-action','ts-grant'].forEach(function(cls) {
  var el = document.querySelector('.' + cls);
  if (!el) return;
  var ts = new TomSelect(el, { allowEmptyOption: true });
  if (cls === 'ts-action') {
    ts.on('change', function(val) {
      var pp = document.querySelector('[name="projekt_program"]');
      if (!pp || pp.value.trim()) return;
      var opt = document.querySelector('[name="action_id"] option[value="' + val + '"]');
      var nazwa = opt ? (opt.dataset.nazwa || opt.textContent.trim()) : '';
      if (nazwa) {
        pp.value = nazwa;
        pp.classList.add('autofilled');
        setTimeout(function() { pp.classList.remove('autofilled'); }, 800);
        updateSummary();
      }
    });
  }
});

// ── Wizard navigation ─────────────────────────────────────────────────────────
var _currentStep = 1;
var _totalSteps  = 6;
var _visitedSteps = new Set([1]);

// ── Konfiguracja wymagalności pól (z PHP) ────────────────────────────────────
var ADD_FIELD_CFG = <?= json_encode($_add_field_cfg) ?>;

var ADD_STEP_FIELDS = {
  1: [
    {name:'numer_umowy',  label:'Numer umowy'},
    {name:'status',       label:'Status'},
  ],
  2: [
    {name:'imie_nazwisko', label:'Imię i nazwisko'},
    {name:'email',         label:'E-mail'},
    {name:'pesel',         label:'PESEL'},
    {name:'data_urodzenia',label:'Data urodzenia'},
    {name:'telefon',       label:'Telefon'},
    {name:'adres',         label:'Adres'},
  ],
  3: [
    {name:'data_zawarcia',          label:'Data zawarcia'},
    {name:'data_rozpoczecia',       label:'Data rozpoczęcia'},
    {name:'data_zakonczenia',       label:'Data zakończenia', customCheck: function() {
      var bzt = document.querySelector('[name="bezterminowa"]');
      var tech = document.getElementById('wspolpraca_przed_2026');
      if ((bzt && bzt.checked) || (tech && tech.checked)) return true;
      var el = document.querySelector('[name="data_zakonczenia"]');
      return !!(el && el.value.trim());
    }},
    {name:'opiekun',                label:'Opiekun'},
    {name:'miejsce_wolontariatu',   label:'Miejsce wolontariatu'},
    {name:'przedmiot_porozumienia', label:'Przedmiot porozumienia'},
    {name:'projekt_program',        label:'Projekt / program'},
  ],
  5: [
    {name:'m365_security_group_id', label:'Security Group M365', customCheck: function() {
      return !!document.getElementById('add_sg_id')?.value;
    }},
    {name:'plik_umowy', label:'Plik porozumienia', customCheck: function() {
      var el = document.querySelector('[name="plik_umowy"]');
      return el && el.files && el.files.length > 0;
    }},
  ],
};

function addShowToast(required, recommended) {
  var existing = document.getElementById('add-warn-toast');
  if (existing) existing.remove();
  if (!required.length && !recommended.length) return;

  var hasRequired = required.length > 0;
  var html = '';
  required.forEach(function(m) {
    html += '<div class="d-flex align-items-start gap-2 py-1">'
      + '<i class="bi bi-exclamation-circle-fill text-danger flex-shrink-0 mt-1" style="font-size:.85rem"></i>'
      + '<span><strong>' + m.label + '</strong> — pole wymagane</span></div>';
  });
  recommended.forEach(function(m) {
    html += '<div class="d-flex align-items-start gap-2 py-1">'
      + '<i class="bi bi-exclamation-triangle-fill text-warning flex-shrink-0 mt-1" style="font-size:.85rem"></i>'
      + '<span>' + m.label + ' — zalecane do uzupełnienia</span></div>';
  });

  var toast = document.createElement('div');
  toast.id = 'add-warn-toast';
  toast.style.cssText = 'position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);'
    + 'z-index:9999;background:#fff;border:1px solid ' + (hasRequired ? '#fca5a5' : '#fde68a') + ';border-radius:.75rem;'
    + 'box-shadow:0 8px 32px rgba(0,0,0,.18);padding:1rem 1.25rem;min-width:320px;max-width:480px;font-size:.85rem;';

  var footer = hasRequired
    ? '<span class="text-danger fw-semibold" style="font-size:.78rem"><i class="bi bi-lock-fill me-1"></i>Uzupełnij wymagane pola aby przejść dalej</span>'
    : '<span class="text-muted" style="font-size:.78rem">Możesz kontynuować — dane uzupełnij później</span>';

  toast.innerHTML = '<div class="d-flex justify-content-between align-items-center mb-2">'
    + '<strong style="font-size:.88rem"><i class="bi bi-clipboard-check me-1"></i>'
    + (hasRequired ? 'Uzupełnij wymagane pola' : 'Warto uzupełnić') + '</strong>'
    + '<button type="button" onclick="document.getElementById(\'add-warn-toast\').remove()" class="btn-close btn-close-sm" style="font-size:.65rem"></button>'
    + '</div>' + html
    + '<div class="mt-2 pt-2 border-top">' + footer + '</div>';

  document.body.appendChild(toast);
  if (!hasRequired) setTimeout(function() { if (toast.parentNode) toast.remove(); }, 6000);
}

// Pola wymagane tylko dla umów zawartych od 01.06.2026.
var ADD_REQUIRED_FROM = '2026-06-01';
var ADD_CONDITIONAL_FIELDS = {
  'data_zakonczenia': true, 'opiekun': true, 'miejsce_wolontariatu': true,
  'przedmiot_porozumienia': true, 'm365_security_group_id': true
};

function addCheckStep(step) {
  var fields = ADD_STEP_FIELDS[step] || [];
  var required = [], recommended = [];
  // Gdy osoba wybrana z rejestru (person_id ustawione), dane osobowe (step 2)
  // są powiązane przez ID — nie blokuj za puste pola z niekompletnych rekordów.
  var personSelected = !!(document.getElementById('pp_hidden') || {}).value;
  if (step === 2 && personSelected) {
    return {required: [], recommended: [], blocked: false};
  }
  // Dla umów przed 01.06.2026 pola warunkowe traktuj jako opcjonalne.
  var dzEl = document.querySelector('[name="data_zawarcia"]');
  var beforeCutoff = dzEl && dzEl.value && dzEl.value < ADD_REQUIRED_FROM;
  fields.forEach(function(f) {
    var level = ADD_FIELD_CFG[f.name] || 'optional';
    if (level === 'optional') return;
    if (level === 'required' && beforeCutoff && ADD_CONDITIONAL_FIELDS[f.name]) level = 'optional';
    if (level === 'optional') return;
    var empty = f.customCheck ? !f.customCheck() : (function() {
      var el = document.querySelector('[name="' + f.name + '"]');
      if (!el) return false;
      if (el.tagName === 'SELECT') return !el.value;
      return !el.value.trim();
    })();
    if (!empty) return;
    if (level === 'required') required.push(f);
    else recommended.push(f);
  });
  return {required: required, recommended: recommended, blocked: required.length > 0};
}

function goToStep(n) {
  if (n < 1 || n > _totalSteps) return;
  if (n > _currentStep) {
    var chk = addCheckStep(_currentStep);
    if (chk.required.length || chk.recommended.length) {
      addShowToast(chk.required, chk.recommended);
      if (chk.blocked) return; // blokuj gdy są wymagane pola
    }
  }

  document.getElementById('wiz-step-' + _currentStep).classList.remove('active');
  document.getElementById('step-btn-' + _currentStep).classList.remove('active');

  _visitedSteps.add(n);
  _currentStep = n;

  document.getElementById('wiz-step-' + n).classList.add('active');
  document.getElementById('step-btn-' + n).classList.add('active');

  // Update done state + separators
  for (var i = 1; i <= _totalSteps; i++) {
    var btn = document.getElementById('step-btn-' + i);
    var num = document.getElementById('step-num-' + i);
    var sep = document.getElementById('step-sep-' + i);
    if (i < n && _visitedSteps.has(i)) {
      btn.classList.add('done');
      btn.classList.remove('active');
      btn.setAttribute('aria-selected', 'false');
      num.innerHTML = '<i class="bi bi-check-lg" style="font-size:.8rem"></i>';
      if (sep) sep.classList.add('done');
    } else if (i === n) {
      btn.classList.remove('done');
      btn.setAttribute('aria-selected', 'true');
      num.textContent = i;
      if (sep) sep.classList.remove('done');
    } else {
      btn.classList.remove('done');
      btn.setAttribute('aria-selected', 'false');
      if (sep) sep.classList.remove('done');
    }
  }

  // Progress bar
  var pct = Math.round((n - 1) / (_totalSteps - 1) * 100);
  document.getElementById('progress-bar').style.width = pct + '%';
  document.getElementById('progress-label').textContent = pct + '%';
  for (var j = 1; j <= _totalSteps; j++) {
    var dot = document.getElementById('prog-step-' + j);
    if (dot) dot.style.background = j <= n ? '#1E6DFF' : '#E5E7EB';
  }

  window.scrollTo({ top: 0, behavior: 'smooth' });
}

// ── Live summary ──────────────────────────────────────────────────────────────
function _fv(name) {
  var el = document.querySelector('[name="' + name + '"]');
  if (!el) return '';
  if (el.tagName === 'SELECT') return (el.options[el.selectedIndex] || {}).text || '';
  return el.value.trim();
}
function ageFromDate(d) {
  if (!d) return null;
  var b = new Date(d), now = new Date();
  var age = now.getFullYear() - b.getFullYear();
  if (now < new Date(now.getFullYear(), b.getMonth(), b.getDate())) age--;
  return age;
}
function updateSummary() {
  var bezterm = document.getElementById('bezterminowa') && document.getElementById('bezterminowa').checked;
  document.getElementById('sum-num').textContent       = _fv('numer_umowy') || '—';
  document.getElementById('sum-name').textContent      = _fv('imie_nazwisko') || '—';
  document.getElementById('sum-status').textContent    = _fv('status') || '—';
  document.getElementById('sum-pesel').textContent     = _fv('pesel') || '—';
  document.getElementById('sum-email').textContent     = _fv('email') || '—';
  document.getElementById('sum-data-zawarcia').textContent = _fv('data_zawarcia') || '—';
  document.getElementById('sum-okres').textContent     =
    (_fv('data_rozpoczecia') || '?') + ' → ' + (bezterm ? '∞' : (_fv('data_zakonczenia') || '?'));
  document.getElementById('sum-miejsce').textContent   = _fv('miejsce_wolontariatu') || '—';
  document.getElementById('sum-opiekun').textContent   = document.getElementById('opiekun_hidden')?.value || _fv('opiekun') || '—';
  var wiek = ageFromDate(_fv('data_urodzenia'));
  document.getElementById('sum-wiek').textContent = wiek !== null ? wiek + ' lat' : '—';
}
document.querySelectorAll('input,select,textarea').forEach(function(el) {
  el.addEventListener('input', updateSummary);
  el.addEventListener('change', updateSummary);
});
updateSummary();

// ── Warunkowe pola ────────────────────────────────────────────────────────────
// Status terminalny
(function() {
  var statusSel  = document.getElementById('status_select');
  var uzSection  = document.getElementById('uzasadnienie_section');
  var uzTextarea = document.getElementById('uzasadnienie_statusu');
  var uzLabel    = document.getElementById('uzasadnienie_status_label');
  var terminal   = <?= json_encode($_terminal_statuses) ?>;
  if (!statusSel || !uzSection) return;
  function upd() {
    var v = statusSel.value;
    var is = terminal.includes(v);
    uzSection.style.display = is ? '' : 'none';
    if (uzTextarea) uzTextarea.required = is;
    if (uzLabel) uzLabel.textContent = is ? '(status: ' + v + ')' : '';
  }
  statusSel.addEventListener('change', upd); upd();
})();

document.getElementById('forma_podpisania')?.addEventListener('change', function() {
  var v = this.value;
  document.getElementById('el_fields').style.display      = v === 'elektroniczna'         ? '' : 'none';
  document.getElementById('epodpis_fields').style.display = v === 'epodpis_kwalifikowany' ? '' : 'none';
});
document.getElementById('niepelnoletni')?.addEventListener('change', function() {
  document.getElementById('rodzic_section').style.display = this.checked ? '' : 'none';
});
document.getElementById('bezterminowa')?.addEventListener('change', function() {
  var dz = document.getElementById('data_zakonczenia');
  if (dz) dz.disabled = this.checked;
  updateSummary();
});
document.getElementById('zwrot_kosztow')?.addEventListener('change', function() {
  document.getElementById('zwrot_kosztow_opis_field').style.display = this.checked ? '' : 'none';
});
document.getElementById('szkolenie_bhp')?.addEventListener('change', function() {
  document.getElementById('data_szkolenia_bhp').disabled = !this.checked;
});
document.getElementById('ubezpieczenie_nnw')?.addEventListener('change', function() {
  document.getElementById('numer_polisy_nnw').disabled = !this.checked;
});
document.getElementById('z_webngo')?.addEventListener('change', function() {
  document.getElementById('webngo_fields').style.display = this.checked ? '' : 'none';
  if (!this.checked) {
    document.getElementById('webngo_id').value = '';
    document.getElementById('webngo_numer_umowy').value = '';
  }
});

// M365 tryb
(function() {
  var radios = document.querySelectorAll('[name="m365_create_mode"]');
  var manual = document.getElementById('m365_manual_fields');
  function upd() {
    var v = document.querySelector('[name="m365_create_mode"]:checked')?.value;
    if (manual) manual.style.display = v === 'manual' ? '' : 'none';
  }
  radios.forEach(function(r) { r.addEventListener('change', upd); }); upd();
})();

// ── Identity toggle ───────────────────────────────────────────────────────────
(function() {
  var radios    = document.querySelectorAll('input[name="identity_type"]');
  var secPesel  = document.getElementById('section_pesel');
  var secPeselD = document.getElementById('section_pesel_dob');
  var secFor    = document.getElementById('section_foreigner');
  function toggle(val) {
    var isp = val === 'pesel';
    [secPesel, secPeselD].forEach(function(el) { if (el) el.classList.toggle('d-none', !isp); });
    if (secFor) secFor.classList.toggle('d-none', isp);
    var peselInp = document.getElementById('f_pesel');
    if (peselInp) peselInp.required = isp;
  }
  radios.forEach(function(r) { r.addEventListener('change', function() { toggle(this.value); }); });
  var chk = document.querySelector('input[name="identity_type"]:checked');
  if (chk) toggle(chk.value);
})();

// ── PESEL walidacja ───────────────────────────────────────────────────────────
(function() {
  var WEIGHTS = [1,3,7,9,1,3,7,9,1,3];
  function peselOK(p) {
    if (p.length !== 11) return false;
    var digits = p.split('').map(Number), sum = 0;
    for (var i=0;i<10;i++) sum += digits[i]*WEIGHTS[i];
    return (10-(sum%10))%10 === digits[10];
  }
  function peselToDate(p) {
    if (p.length < 6) return '';
    var y=parseInt(p.substr(0,2),10),m=parseInt(p.substr(2,2),10),d=parseInt(p.substr(4,2),10);
    if(m>=81){y+=1800;m-=80;}else if(m>=61){y+=2200;m-=60;}else if(m>=41){y+=2100;m-=40;}else if(m>=21){y+=2000;m-=20;}else{y+=1900;}
    if(m<1||m>12||d<1||d>31)return '';
    return y+'-'+String(m).padStart(2,'0')+'-'+String(d).padStart(2,'0');
  }
  function ageFromBD(bd) {
    if (!bd) return null;
    var b=new Date(bd), now=new Date();
    var age=now.getFullYear()-b.getFullYear();
    if(now<new Date(now.getFullYear(),b.getMonth(),b.getDate()))age--;
    return age;
  }
  var inp  = document.getElementById('f_pesel');
  var err  = document.getElementById('pesel_error');
  var ageEl= document.getElementById('pesel_age_info');
  var dob  = document.getElementById('f_data_urodzenia');
  var hint = document.getElementById('peselDateHint');
  var badge= document.getElementById('pesel_gender_badge');
  var minor= document.getElementById('niepelnoletni');
  var rSec = document.getElementById('rodzic_section');
  if (!inp) return;
  inp.addEventListener('input', function() {
    var p = this.value.replace(/\D/g,'').substr(0,11);
    this.value = p;
    inp.classList.remove('is-valid','is-invalid');
    if (ageEl) ageEl.textContent = '';
    if (badge) badge.style.display = 'none';
    if (p.length === 11) {
      if (peselOK(p)) {
        inp.classList.add('is-valid');
        var bd = peselToDate(p);
        var age = ageFromBD(bd);
        var isMinor = age !== null && age < 18;
        if (ageEl && age !== null) {
          ageEl.innerHTML = '<span class="badge '+(isMinor?'bg-warning text-dark':'bg-success')+'">'
            +age+' lat — '+(isMinor?'Nieletni/a':'Pełnoletni/a')+'</span>';
        }
        if (bd && dob && !dob.value) {
          dob.value = bd;
          if (hint) hint.style.display = '';
        }
        var g = parseInt(p[9],10) % 2 === 0 ? 'K' : 'M';
        if (badge) {
          badge.textContent = g === 'K' ? '♀ Kobieta' : '♂ Mężczyzna';
          badge.className = 'badge ms-1 '+(g==='K'?'bg-pink text-white':'bg-info text-white');
          badge.style.display = '';
        }
        if (minor) {
          minor.checked = isMinor;
          if (rSec) rSec.style.display = isMinor ? '' : 'none';
        }
      } else {
        inp.classList.add('is-invalid');
      }
    }
    updateSummary();
  });
})();

// ── Guardian initials ─────────────────────────────────────────────────────────
(function() {
  var sel  = document.getElementById('guardian_editor_id');
  var hint = document.getElementById('guardian_initials_hint');
  var inp  = document.getElementById('guardian_initials_inp');
  var ohid = document.getElementById('opiekun_hidden');
  if (!sel) return;
  sel.addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var initials = opt ? (opt.dataset.initials || '') : '';
    var name     = opt ? (opt.dataset.name     || '') : '';
    if (hint) {
      hint.innerHTML = initials
        ? '<span class="badge bg-primary-subtle text-primary border px-2"><i class="bi bi-person-badge me-1"></i>Inicjały: <strong class="font-monospace">'+initials+'</strong></span>'
        : '<span class="text-muted small">Inicjały trafiają do nr rejestru i autoryzacji CPC.</span>';
    }
    if (inp)  inp.value  = initials;
    if (ohid) ohid.value = name;
    updateSummary();
  });
  sel.dispatchEvent(new Event('change'));
})();

// ── Szybkie ustawianie dat ────────────────────────────────────────────────────
window._setDate = function(name, offset) {
  var d = new Date(); d.setDate(d.getDate() + (offset||0));
  var iso = d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0');
  document.querySelectorAll('[name="'+name+'"]').forEach(function(i){i.value=iso;});
  updateSummary();
};

// ── Kopiuj adres zamieszkania → korespondencyjny ──────────────────────────────
window._copyToCorrespondence = function() {
  var w = document.getElementById('mainAddrWidget');
  if (!w) return;
  function gv(n){var el=w.querySelector('[name="'+n+'"]');return el?el.value.trim():'';}
  function sv(n,v){var el=document.querySelector('input[name="'+n+'"],select[name="'+n+'"]');if(el)el.value=v;}
  var st=gv('addr_street'),ho=gv('addr_house'),fl=gv('addr_flat');
  sv('adres_odbiorca', document.getElementById('f_imie_nazwisko')?.value||'');
  sv('adres_linia1', [st, ho+(fl?'/'+fl:'')].filter(Boolean).join(' '));
  sv('adres_linia2', '');
  sv('adres_kod_pocztowy', gv('addr_postal'));
  sv('adres_miasto',  gv('addr_city'));
  sv('adres_kraj',    gv('addr_country')||'PL');
};

// ── Umowa techniczna — ukryj/pokaż pola wymagane ──────────────────────────
function toggleTechnical(on) {
  // Karta informacyjna
  var info = document.getElementById('technical_info');
  var card = document.getElementById('wspolpraca_przed_2026').closest('.card');
  if (info) info.style.display = on ? '' : 'none';
  if (card) card.style.background = on ? '#faf5ff' : '#fff';

  // Pola wymagane — usuń/przywróć required gdy techniczna
  var techOptional = [
    'numer_umowy','status','data_zawarcia','data_rozpoczecia',
    'data_zakonczenia','forma_podpisania','przedmiot_porozumienia'
  ];
  techOptional.forEach(function(name) {
    var el = document.querySelector('[name="' + name + '"]');
    if (!el) return;
    if (on) {
      el.removeAttribute('required');
      el.dataset.wasRequired = '1';
    } else if (el.dataset.wasRequired) {
      el.setAttribute('required','');
    }
  });

  // Krok 1 — pola daty/numeru mniej widoczne
  var step1 = document.getElementById('wiz-step-1');
  if (step1) step1.style.opacity = on ? '.6' : '';

  // Auto-uzupełnij numer jeśli pusty
  if (on) {
    var nr = document.querySelector('[name="numer_umowy"]');
    if (nr && !nr.value) nr.value = '(auto)';
    var st = document.querySelector('[name="status"]');
    if (st) { for (var i=0;i<st.options.length;i++) if (st.options[i].value==='podpisana') { st.selectedIndex=i; break; } }
    var dtw = document.querySelector('[name="data_zawarcia"]');
    if (dtw && !dtw.value) dtw.value = '2026-06-01';
    var dtr = document.querySelector('[name="data_rozpoczecia"]');
    if (dtr && !dtr.value) dtr.value = '2026-06-01';
    var bzt = document.querySelector('[name="bezterminowa"]');
    if (bzt) bzt.checked = true;
  }
}

// Inicjalizacja przy załadowaniu (odtwórz stan po błędzie walidacji + tryb migracji)
document.addEventListener('DOMContentLoaded', function() {
  var cb = document.getElementById('wspolpraca_przed_2026');
  if (cb && cb.checked) toggleTechnical(true);

  // Tryb migracji (?migracja=1) — auto-przejdź do kroku 2 (Wolontariusz)
  // bo krok 1 (numer, status, daty) jest wypełniany automatycznie
  if (new URLSearchParams(window.location.search).get('migracja') === '1') {
    setTimeout(function() { goToStep(2); }, 80);
  }
});

// ── Security Groups M365 (krok 5) ────────────────────────────────────────────
(function() {
  var sel     = document.getElementById('add_sg_id');
  var hidden  = document.getElementById('add_sg_name');
  var status  = document.getElementById('add_sg_status');
  var refresh = document.getElementById('add_sg_refresh');
  if (!sel) return;

  var loaded = false;

  function loadGroups() {
    sel.innerHTML = '<option value="">— ładowanie… —</option>';
    sel.disabled = true;
    if (status) { status.textContent = ''; status.className = 'form-text mt-1'; }

    fetch('<?= APP_URL ?>/contracts/wolontariat/api_groups.php')
      .then(function(r) { return r.json(); })
      .then(function(data) {
        sel.disabled = false;
        loaded = true;
        if (data.error) {
          sel.innerHTML = '<option value="">— błąd ładowania grup —</option>';
          if (status) { status.textContent = '⚠ ' + data.error; status.className = 'form-text text-danger mt-1'; }
          return;
        }
        if (!data.length) {
          sel.innerHTML = '<option value="">— brak Security Groups w M365 —</option>';
          if (status) { status.textContent = 'Nie znaleziono grup. Sprawdź konfigurację M365.'; status.className = 'form-text text-warning mt-1'; }
          return;
        }
        var prev = sel.dataset.value || '';
        sel.innerHTML = '<option value="">— wybierz grupę —</option>';
        data.forEach(function(g) {
          var opt = document.createElement('option');
          opt.value = g.id;
          opt.textContent = g.displayName;
          opt.dataset.name = g.displayName;
          if (g.id === prev) opt.selected = true;
          sel.appendChild(opt);
        });
        if (status) { status.textContent = 'Załadowano ' + data.length + ' grup.'; status.className = 'form-text text-success mt-1'; }
      })
      .catch(function() {
        sel.disabled = false;
        sel.innerHTML = '<option value="">— błąd połączenia —</option>';
        if (status) { status.textContent = '⚠ Nie można pobrać grup z M365.'; status.className = 'form-text text-danger mt-1'; }
      });
  }

  sel.addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    if (hidden) hidden.value = opt ? (opt.dataset.name || opt.textContent.trim()) : '';
    // Pokaż sugestię obszaru zadań gdy wybrano grupę
    var suggest = document.getElementById('sg_workspace_suggest');
    if (suggest) suggest.style.display = this.value ? '' : 'none';
  });

  if (refresh) refresh.addEventListener('click', loadGroups);

  // Ładuj automatycznie przy wejściu na krok 5
  var origGoToStep = window.goToStep;
  window.goToStep = function(n) {
    origGoToStep(n);
    if (n === 5 && !loaded) loadGroups();
  };

})();

// Canva — pokaż/ukryj blok info
function toggleCanvaInfo(on) {
  var block = document.getElementById('canva_info_block');
  if (block) block.style.display = on ? '' : 'none';
}

// ── Walidacja submit — sprawdź wymagane we wszystkich krokach ─────────────────
(function() {
  var form = document.querySelector('form[method="post"]');
  if (!form) return;
  form.addEventListener('submit', function(e) {
    var allRequired = [];
    [1, 2, 3, 4, 5, 6].forEach(function(s) {
      var chk = addCheckStep(s);
      allRequired = allRequired.concat(chk.required);
    });
    if (allRequired.length) {
      e.preventDefault();
      addShowToast(allRequired, []);
      // Przejdź do kroku z pierwszym wymaganym polem
      var firstField = allRequired[0];
      var stepWithField = null;
      Object.keys(ADD_STEP_FIELDS).forEach(function(s) {
        ADD_STEP_FIELDS[s].forEach(function(f) {
          if (f.name === firstField.name && !stepWithField) stepWithField = parseInt(s);
        });
      });
      if (stepWithField) goToStep(stepWithField);
    }
  });
})();
</script>

<style>
@keyframes fillPulse{0%{box-shadow:0 0 0 0 rgba(25,135,84,.45)}70%{box-shadow:0 0 0 8px rgba(25,135,84,0)}100%{box-shadow:none}}
.autofilled{animation:fillPulse .8s ease}
</style>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

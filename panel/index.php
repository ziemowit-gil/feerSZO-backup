<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/amendments.php';
require_once dirname(__DIR__) . '/includes/certificates.php';
require_once dirname(__DIR__) . '/includes/termination.php';
require_once dirname(__DIR__) . '/includes/letters.php';
require_once dirname(__DIR__) . '/includes/messages.php';
require_once dirname(__DIR__) . '/includes/applications.php';

require_login();
_applications_init();
$PAGE_TITLE = 'Mój panel';
$user = current_user();

$_db_user = db_one("SELECT microsoft_id, phone_number FROM users WHERE id = ?", [$user['id']]);
$user['microsoft_id'] = $_db_user['microsoft_id'] ?? '';
$user['phone_number']  = $_db_user['phone_number']  ?? '';

// ── Czy SMS logowanie jest włączone? ──────────────────────────────────────────
$_sms_login_available = false;
try {
    require_once dirname(__DIR__) . '/includes/sms.php';
    if (sms_is_enabled()) {
        $r = db_one("SELECT value FROM settings WHERE key_=?", ['login_method_sms']);
        $_sms_login_available = $r ? (bool)$r['value'] : false;
    }
} catch (\Throwable $e) {}

// ── Umowy użytkownika ─────────────────────────────────────────────────────────
function panel_contracts(array $user): array {
    $email = $user['email'] ?? '';
    $ms_id = $user['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return [];
    $results = [];
    $tables = [
        ['zlecenie',    ['m365_user_id', 'm365_login'],                          'data_zakonczenia'],
        ['wolontariat', ['m365_user_id', 'm365_login', 'email', 'rodzic_email'], 'data_zakonczenia'],
        ['dzielo',      ['m365_user_id', 'm365_login'],                          'termin_oddania'],
        ['praca',       ['email_login'],                                          'data_zakonczenia'],
    ];
    foreach ($tables as [$type, $fields, $end_col]) {
        $conds = []; $params = [];
        foreach ($fields as $f) {
            if ($f === 'm365_user_id' && !$ms_id) continue;
            if (in_array($f, ['email', 'm365_login', 'rodzic_email']) && !$email) continue;
            $conds[]  = "{$f} = ?";
            $params[] = ($f === 'm365_user_id') ? $ms_id : $email;
        }
        if (!$conds) continue;
        $extra = ($type === 'wolontariat')
            ? ', imie_nazwisko AS _child_name, email AS _child_email, m365_login AS _child_m365, m365_user_id AS _child_ms_id, rodzic_email AS _rodzic_email'
            : '';
        $rows = db_all(
            "SELECT id, '{$type}' AS contract_type, numer_umowy, status, data_zawarcia, {$end_col} AS data_zakonczenia{$extra}
             FROM umowy_{$type} WHERE (" . implode(' OR ', $conds) . ")",
            $params
        );
        foreach ($rows as &$r) {
            $r['_is_guardian'] = false;
            if ($type === 'wolontariat' && $email) {
                $is_child  = ($email === ($r['_child_email'] ?? '')) || ($email === ($r['_child_m365'] ?? ''))
                          || ($ms_id && $ms_id === ($r['_child_ms_id'] ?? ''));
                $is_parent = ($email === ($r['_rodzic_email'] ?? ''));
                if ($is_parent && !$is_child) $r['_is_guardian'] = true;
            }
        }
        unset($r);
        $results = array_merge($results, $rows);
    }
    return $results;
}

// Konto standalone volunteer — przekieruj do zadań (brak umów do pokazania)
try {
    $__sv_check = db_one("SELECT is_standalone_volunteer FROM users WHERE id=?", [(int)$user['id']]);
    if (!empty($__sv_check['is_standalone_volunteer'])) {
        header('Location: ' . APP_URL . '/panel/standalone.php'); exit;
    }
} catch (\Throwable $e) {}

$contracts = panel_contracts($user);

// ── Odrzucenie zachęty SMS logowania (trwałe, zapis w DB) ────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_dismiss_sms_nudge'])) {
    // Wyspa React zamyka baner przez fetch (?_ajax=1) → odpowiedź JSON, bez reloadu.
    $_sms_ajax = !empty($_POST['_ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    if ($_sms_ajax) {
        if (($_POST['_csrf'] ?? '') !== csrf_token()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'csrf'], JSON_UNESCAPED_UNICODE); exit;
        }
    } else {
        csrf_check();
    }
    try { db()->exec("ALTER TABLE users ADD COLUMN sms_nudge_dismissed TINYINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { db()->prepare("UPDATE users SET sms_nudge_dismissed=1 WHERE id=?")->execute([(int)$user['id']]); } catch (\Throwable $e) {}
    if ($_sms_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE); exit;
    }
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}

// ── Wybór aktywnej umowy ──────────────────────────────────────────────────────
auth_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_select_contract'])) {
    csrf_check();
    $sel_type = preg_replace('/[^a-z]/', '', $_POST['contract_type'] ?? '');
    $sel_id   = (int)($_POST['contract_id'] ?? 0);
    $valid = array_filter($contracts, fn($c) => $c['contract_type'] === $sel_type && (int)$c['id'] === $sel_id);
    if ($valid) {
        $ch = reset($valid);
        $_SESSION['panel_contract'] = ['type' => $ch['contract_type'], 'id' => (int)$ch['id'], 'numer' => $ch['numer_umowy']];
    }
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}

$_active_contract = null;
if (!empty($_SESSION['panel_contract'])) {
    $sc    = $_SESSION['panel_contract'];
    $match = array_filter($contracts, fn($c) => $c['contract_type'] === $sc['type'] && (int)$c['id'] === (int)$sc['id']);
    if ($match) $_active_contract = reset($match);
}
if (!$_active_contract && $contracts) {
    $_active_contract = $contracts[0];
    $_SESSION['panel_contract'] = ['type' => $_active_contract['contract_type'], 'id' => (int)$_active_contract['id'], 'numer' => $_active_contract['numer_umowy']];
}

$_active_row  = null;
$_is_guardian = (bool)($_active_contract['_is_guardian'] ?? false);
if ($_active_contract) {
    $_active_table = table_for_type($_active_contract['contract_type']);
    $_active_row   = db_one("SELECT * FROM {$_active_table} WHERE id = ?", [(int)$_active_contract['id']]);
    // Odśwież wyliczone godziny z zadań dla aktywnej umowy wolontariatu
    if ($_active_row && $_active_contract['contract_type'] === 'wolontariat') {
        require_once dirname(__DIR__) . '/includes/volunteer_hours.php';
        volunteer_recompute_hours((int)$_active_contract['id'], $_active_row);
        $_active_row = db_one("SELECT * FROM {$_active_table} WHERE id = ?", [(int)$_active_contract['id']]);
    }
}

// ── Prośba o dostęp do Canva — przez system Zatwierdzeń ──────────────────────
// Wolontariusz podaje dane konta Canva, które sam założył (gdy admin nie wpisał).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_save_canva_account'])) {
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_once dirname(__DIR__) . '/includes/canva.php';
    if (isset($_POST['_csrf'])) csrf_check();
    if (!canva_module_enabled()) {
        flash_set('error', 'Funkcjonalności Canva są obecnie wyłączone.');
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }
    // Tryb „user" — wolontariusz bez umowy zapisuje login swojego konta Canva.
    if (($_POST['canva_scope'] ?? '') === 'user') {
        require_once dirname(__DIR__) . '/includes/canva.php';
        $uid = (int)($_POST['canva_user_id'] ?? 0);
        if ($uid && $uid === (int)$user['id']) {
            $a = canva_user_access_get($uid);
            if (($a['konto_zrodlo'] ?? '') === 'admin') {
                flash_set('error', 'Dane konta Canva ustawił administrator — w razie potrzeby skontaktuj się z nim.');
            } else {
                canva_user_set_account($uid, trim($_POST['canva_login'] ?? ''), (string)($a['haslo'] ?? ''), 'wolontariusz');
                flash_set('success', 'Zapisano login Twojego konta Canva.');
            }
        }
        header('Location: ' . $_SERVER['REQUEST_URI']); exit;
    }
    $cid = (int)($_POST['canva_contract_id'] ?? ($_active_contract['id'] ?? 0));
    if ($cid && $_active_contract && (int)$_active_contract['id'] === $cid && $_active_contract['contract_type'] === 'wolontariat') {
        $cur = db_one("SELECT canva_konto_zrodlo FROM umowy_wolontariat WHERE id=?", [$cid]);
        if (($cur['canva_konto_zrodlo'] ?? '') === 'admin') {
            flash_set('error', 'Dane konta Canva ustawił administrator — w razie potrzeby skontaktuj się z nim.');
        } else {
            $login = trim($_POST['canva_login'] ?? '');
            db()->prepare("UPDATE umowy_wolontariat SET canva_login=?, canva_konto_zrodlo='wolontariusz', canva_konto_at=datetime('now','localtime') WHERE id=?")
                ->execute([$login, $cid]);
            flash_set('success', $login !== '' ? 'Zapisano login Twojego konta Canva.' : 'Wyczyszczono dane konta Canva.');
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_request_canva'])) {
    require_once dirname(__DIR__) . '/includes/functions.php';
    // Wyspa React składa prośbę przez fetch (data-* + ?_ajax=1) → odpowiedź JSON.
    $_canva_ajax = !empty($_POST['_ajax']) || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
    $_canva_done = function (string $status, string $msg) use ($_canva_ajax) {
        if ($_canva_ajax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => $status === 'success', 'status' => $status, 'message' => $msg],
                JSON_UNESCAPED_UNICODE);
            exit;
        }
        flash_set($status, $msg);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    };
    // CSRF: dla AJAX zwróć błąd JSON zamiast die() z czystym tekstem.
    if ($_canva_ajax) {
        if (($_POST['_csrf'] ?? '') !== (csrf_token())) {
            $_canva_done('error', 'Błąd CSRF. Odśwież stronę i spróbuj ponownie.');
        }
    } elseif (isset($_POST['_csrf'])) {
        csrf_check();
    }
    require_once dirname(__DIR__) . '/includes/canva.php';
    if (!canva_module_enabled()) {
        $_canva_done('error', 'Funkcjonalności Canva są obecnie wyłączone.');
    }
    $contract_id = (int)($_POST['canva_contract_id'] ?? ($_active_contract['id'] ?? 0));
    if ($contract_id && $_active_contract && $_active_contract['contract_type'] === 'wolontariat') {
        $row_c = db_one("SELECT id, imie_nazwisko, numer_umowy, canva_access, canva_access_requested_at FROM umowy_wolontariat WHERE id=?", [$contract_id]);
        if ($row_c && !$row_c['canva_access'] && !$row_c['canva_access_requested_at']) {
            // Zapisz datę prośby
            try {
                db()->prepare("UPDATE umowy_wolontariat SET canva_access_requested_at=datetime('now','localtime') WHERE id=?")
                    ->execute([$contract_id]);
            } catch (\Throwable $e) {}
            // Złóż wniosek w systemie Zatwierdzeń (type=canva_request)
            try {
                require_once dirname(__DIR__) . '/includes/approval.php';
                require_once dirname(__DIR__) . '/includes/notifications.php';
                submit_for_approval('canva_request', $contract_id, (int)$user['id'],
                    '🎨 Canva: ' . ($row_c['imie_nazwisko'] ?? $row_c['numer_umowy'] ?? ''));
                // Powiadom adminów przez system powiadomień
                $admins = db_all("SELECT id FROM users WHERE role='admin' AND is_active=1");
                $view_url = APP_URL . '/contracts/approvals/index.php';
                foreach ($admins as $adm) {
                    notif_create(
                        (int)$adm['id'],
                        'approval',
                        '🎨 Prośba o Canva: ' . ($row_c['imie_nazwisko'] ?? ''),
                        'Wolontariusz prosi o dostęp do przestrzeni Canva Pro. Przejdź do Zatwierdzeń, aby podjąć decyzję.',
                        $view_url
                    );
                }
            } catch (\Throwable $e) {}
            $_canva_done('success', 'Prośba o Canva złożona! Pojawi się w Zatwierdzeniach — administrator wkrótce podejmie decyzję.');
        } elseif ($row_c && ($row_c['canva_access'] || $row_c['canva_access_requested_at'])) {
            $_canva_done('info', 'Prośba o Canva jest już złożona lub masz już dostęp.');
        }
    }
    $_canva_done('error', 'Nie udało się złożyć prośby o Canva.');
}

// ── Liczniki ──────────────────────────────────────────────────────────────────
$my_letters_count = 0;
foreach ($contracts as $_c) {
    $my_letters_count += count(get_contract_letters($_c['contract_type'], $_c['id']));
}
$my_certs    = get_user_certificate_requests($user['id']);
$my_certs_pending = count(array_filter($my_certs, fn($r) => $r['status'] === 'oczekuje'));

$my_terms    = get_user_termination_requests($user['id']);
$my_terms_pending = count(array_filter($my_terms, fn($r) => $r['status'] === 'oczekuje'));

// ── Zwroty kosztów — sprawdź czy użytkownik ma uprawnione umowy ───────────────
require_once dirname(__DIR__) . '/includes/zwroty_kosztow.php';
$_active_zwroty = false;
$_zwroty_pending = 0;
(function() use ($user, &$_active_zwroty, &$_zwroty_pending) {
    $email = $user['email'] ?? '';
    $ms_id = $user['microsoft_id'] ?? '';
    if (!$email && !$ms_id) return;
    $conds = []; $params = [];
    foreach (['m365_user_id' => $ms_id, 'm365_login' => $email, 'email' => $email] as $col => $val) {
        if (!$val) continue;
        $conds[] = "{$col} = ?"; $params[] = $val;
    }
    if (!$conds) return;
    $active = "'aktywna','w_realizacji','zatwierdzony','zatwierdzona','aktywny','obowiazuje'";
    $has = db_one(
        "SELECT COUNT(*) AS cnt FROM umowy_wolontariat
         WHERE zwrot_kosztow = 1 AND status IN ({$active})
         AND (" . implode(' OR ', $conds) . ")",
        $params
    );
    $_active_zwroty = (int)($has['cnt'] ?? 0) > 0;
    if ($_active_zwroty) {
        $uid = (int)($user['id'] ?? 0);
        $pending = db_one(
            "SELECT COUNT(*) AS cnt FROM zwroty_kosztow
             WHERE wnioskodawca_id = ? AND status IN ('oczekuje','weryfikacja')",
            [$uid]
        );
        $_zwroty_pending = (int)($pending['cnt'] ?? 0);
    }
})();

$msg_unread  = $_active_contract ? msg_unread_thread('contract', (int)$_active_contract['id'], 'user') : 0;

$my_apps     = db_all(
    "SELECT a.*, t.label AS type_label, t.icon AS type_icon
     FROM user_applications a LEFT JOIN application_types t ON t.id = a.type_id
     WHERE a.user_id = ? ORDER BY a.created_at DESC LIMIT 5",
    [$user['id']]
);
$my_apps_new = count(array_filter($my_apps, fn($a) => $a['status'] === 'nowy'));
$my_apps_answered = array_filter($my_apps, fn($a) => $a['odpowiedz'] && $a['status'] !== 'nowy');

// Postęp trwania umowy
$contract_progress = null;
if ($_active_row && !empty($_active_row['data_zawarcia']) && !empty($_active_contract['data_zakonczenia'])) {
    $start = strtotime($_active_row['data_zawarcia']);
    $end   = strtotime($_active_contract['data_zakonczenia']);
    $now   = time();
    $total = $end - $start;
    if ($total > 0) {
        $contract_progress = [
            'pct'      => min(100, max(0, round(($now - $start) / $total * 100))),
            'days_left' => max(0, (int)(($end - $now) / 86400)),
            'ended'    => $now > $end,
        ];
    }
}

$type_icons = [
    'zlecenie'    => 'bi-person-workspace',
    'wolontariat' => 'bi-heart',
    'dzielo'      => 'bi-brush',
    'praca'       => 'bi-briefcase',
];

// Inicjały użytkownika
$initials = implode('', array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(explode(' ', $user['name']), 0, 2)));

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [$user['id']]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<?php if ($_is_volunteer_only): ?>
<?php include __DIR__ . '/includes/pv_home.php'; /* nowy widok: styl Tozsamosc, 2 zakladki */ ?>
<?php else: ?>
<?php /* ═══ STARY WIDOK DLA EDYTORÓW / ADMINÓW / k30_consultants ══════════ */ ?>
<style>
.panel-avatar { width:56px;height:56px;border-radius:50%;background:linear-gradient(135deg,var(--bs-primary),#6610f2);color:#fff;font-size:1.3rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.action-tile { display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:.4rem;padding:1.25rem .75rem;border-radius:.75rem;border:1px solid rgba(0,0,0,.07);text-decoration:none;color:inherit;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);transition:transform .15s,box-shadow .15s,background .15s;position:relative }
.action-tile:hover { transform:translateY(-3px);box-shadow:0 6px 18px rgba(0,0,0,.12);background:var(--bs-primary);color:#fff!important }
.action-tile:hover .tile-icon,.action-tile:hover .tile-label,.action-tile:hover .tile-count { color:#fff!important }
.tile-icon{font-size:1.8rem}.tile-count{font-size:1.4rem;font-weight:700;line-height:1}.tile-label{font-size:.75rem;color:#6c757d}.tile-badge{position:absolute;top:.4rem;right:.5rem}
.action-tile-duo{display:flex;flex-direction:column;border-radius:.75rem;border:1px solid rgba(0,0,0,.07);background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.06);overflow:hidden;height:100%}
.action-tile-half{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:.2rem;padding:.7rem .5rem;flex:1;text-decoration:none;color:inherit;position:relative;transition:background .15s,color .15s}
.action-tile-half .tile-icon{font-size:1.4rem}.action-tile-half .tile-count{font-size:1.1rem;font-weight:700}.action-tile-half .tile-label{font-size:.7rem;color:#6c757d}
.action-tile-half:hover{background:#eff6ff;color:#2563eb!important}
.action-tile-duo-sep{height:1px;background:rgba(0,0,0,.07);flex-shrink:0}
.data-label{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#6c757d;margin-bottom:.1rem}
.data-value{font-size:.92rem;font-weight:500}.pesel-masked{font-family:monospace;letter-spacing:.1em}
</style>
<?php
$_first_name = explode(' ', trim($user['name']))[0];
$_is_sys_admin = ($user['role'] ?? '') === 'admin';

// Śmieszne hasła dla admina
$_admin_quips = [
    "Znowu tu jesteś? Nie masz nic lepszego do roboty? 😄",
    "Panel wolontariusza? Serio? Masz przecież cały admin. 🤭",
    "Hej Szefie! Ten panel nie jest dla Ciebie… ale skoro już tu jesteś. 👋",
    "Witaj, człowieku od wszystkiego. Ten panel jest dla innych, ale rozumiem ciekawość. 🔍",
    "Admin w panelu wolontariusza? To jak lekarz w kolejce do siebie. 😅",
    "Sprawdzasz jak wygląda panel od środka? Szpiegowanie własnej aplikacji — klasyk. 🕵️",
    "Tu nic ciekawego dla admina. Ale zadania poniżej — jak najbardziej Twoje! 📋",
];
$_quip = $_admin_quips[abs(crc32($user['name'])) % count($_admin_quips)];
?>

<?php if ($_is_sys_admin): ?>
<!-- ── Admin easter egg ──────────────────────────────────────────────────── -->
<div style="background:linear-gradient(135deg,#1e293b 0%,#312e81 100%);
            border-radius:.85rem;padding:1.25rem 1.4rem;margin-bottom:1.25rem;
            display:flex;align-items:center;gap:1rem;position:relative;overflow:hidden">
  <!-- Dekoracja bg -->
  <div style="position:absolute;top:-30px;right:-30px;width:120px;height:120px;
              border-radius:50%;background:rgba(255,255,255,.04);pointer-events:none"></div>

  <!-- Awatar z efektem crown -->
  <div style="position:relative;flex-shrink:0">
    <div class="panel-avatar"
         style="background:linear-gradient(135deg,#f59e0b,#dc2626);
                font-size:1.2rem;width:52px;height:52px">
      <?= h($initials) ?>
    </div>
    <span style="position:absolute;top:-8px;left:50%;transform:translateX(-50%);
                 font-size:1.1rem" role="img" aria-label="korona">👑</span>
  </div>

  <div style="flex:1;min-width:0">
    <div style="font-size:1rem;font-weight:800;color:#fff;margin-bottom:.15rem">
      Witaj, <?= h($_first_name) ?>!
      <span style="font-size:.75rem;font-weight:400;opacity:.6;margin-left:.4rem">
        Administrator systemu
      </span>
    </div>
    <div style="font-size:.84rem;color:rgba(255,255,255,.75);line-height:1.45">
      <?= h($_quip) ?>
    </div>
    <div style="margin-top:.6rem;display:flex;gap:.5rem;flex-wrap:wrap">
      <a href="<?= APP_URL ?>/admin/index.php"
         style="font-size:.75rem;font-weight:600;padding:.22rem .65rem;border-radius:2rem;
                background:rgba(255,255,255,.15);color:#fff;text-decoration:none;
                border:1px solid rgba(255,255,255,.25);transition:background .12s"
         onmouseover="this.style.background='rgba(255,255,255,.25)'"
         onmouseout="this.style.background='rgba(255,255,255,.15)'">
        <i class="bi bi-gear me-1"></i>Panel admina
      </a>
      <a href="<?= APP_URL ?>/tasks/dashboard.php"
         style="font-size:.75rem;font-weight:600;padding:.22rem .65rem;border-radius:2rem;
                background:rgba(16,185,129,.25);color:#6ee7b7;text-decoration:none;
                border:1px solid rgba(16,185,129,.35);transition:background .12s"
         onmouseover="this.style.background='rgba(16,185,129,.4)'"
         onmouseout="this.style.background='rgba(16,185,129,.25)'">
        <i class="bi bi-table me-1"></i>Moduł Zadania
      </a>
      <span style="font-size:.72rem;color:rgba(255,255,255,.4);align-self:center">
        <?= h($user['email']) ?>
      </span>
    </div>
  </div>
</div>
<?php else: ?>
<!-- ── Standardowy nagłówek editora ──────────────────────────────────────── -->
<div class="d-flex align-items-center gap-3 mb-4">
  <div class="panel-avatar"><?= h($initials) ?></div>
  <div class="flex-grow-1">
    <h4 class="mb-0 fw-bold">Witaj, <?= h($_first_name) ?>!</h4>
    <div class="text-muted small"><?= h($user['email']) ?></div>
  </div>
  <?php if (count($contracts) > 1): ?>
  <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#contractPickerModal">
    <i class="bi bi-arrow-left-right me-1"></i>Zmień umowę
  </button>
  <?php endif; ?>
</div>
<?php endif; ?>
<?= flash_html() ?>
<?php if ($contracts): ?>
<?php if ($_active_row): ?>
<div class="card shadow-sm mb-4 <?= $_is_guardian ? 'border-warning border-opacity-50' : '' ?>">
  <div class="card-header bg-white d-flex align-items-center gap-2 py-2 px-3 border-bottom">
    <i class="bi <?= $type_icons[$_active_contract['contract_type']] ?? 'bi-file-text' ?> text-primary"></i>
    <span class="fw-semibold small"><?= h(CONTRACT_TYPES[$_active_contract['contract_type']] ?? '') ?> &nbsp;·&nbsp; <?= h($_active_row['numer_umowy'] ?? '') ?></span>
    <?= status_badge($_active_contract['status']) ?>
    <?php if ($_is_guardian): ?><span class="badge bg-warning text-dark ms-1"><i class="bi bi-person-hearts me-1"></i>Opiekun</span><?php endif; ?>
    <a href="<?= APP_URL ?>/contracts/<?= $_active_contract['contract_type'] ?>/view.php?id=<?= $_active_contract['id'] ?>" class="btn btn-outline-secondary btn-sm ms-auto" style="font-size:.75rem;padding:.15rem .5rem"><i class="bi bi-eye me-1"></i>Pełne dane</a>
  </div>
  <div class="card-body py-3">
    <div class="row g-3 mb-3">
      <?php $pesel_val=$_active_row['pesel']??null; if ($pesel_val): $p_masked=substr($pesel_val,0,2).'·····'.substr($pesel_val,7); ?>
      <div class="col-6 col-md-4 col-lg-2"><div class="data-label">PESEL</div><div class="data-value d-flex align-items-center gap-1"><span class="pesel-masked small" id="peselVal" data-masked="<?= h($p_masked) ?>" data-full="<?= h($pesel_val) ?>"><?= h($p_masked) ?></span><button type="button" class="btn btn-link btn-sm p-0 text-muted" onclick="togglePesel()"><i class="bi bi-eye" id="peselIcon"></i></button></div></div>
      <?php endif; ?>
      <?php if ($_active_row['data_zawarcia']??null): ?><div class="col-6 col-md-4 col-lg-2"><div class="data-label">Zawarcia</div><div class="data-value"><?= date('d.m.Y',strtotime($_active_row['data_zawarcia'])) ?></div></div><?php endif; ?>
      <?php if ($_active_contract['data_zakonczenia']??null): ?><div class="col-6 col-md-4 col-lg-2"><div class="data-label">Zakończenia</div><div class="data-value <?= ($contract_progress['ended']??false)?'text-danger':'' ?>"><?= date('d.m.Y',strtotime($_active_contract['data_zakonczenia'])) ?></div></div><?php endif; ?>
      <?php if ($_active_row['adres']??null): ?><div class="col-12 col-md-6 col-lg-3"><div class="data-label">Adres</div><div class="data-value small"><?= h($_active_row['adres']) ?></div></div><?php endif; ?>
    </div>
    <?php if ($contract_progress): ?>
    <div class="mt-1"><div class="d-flex justify-content-between mb-1"><span class="small text-muted"><?= $contract_progress['ended'] ? '<span class="text-danger fw-semibold">Zakończona</span>' : 'Pozostało <strong>'.$contract_progress['days_left'].'</strong> dni' ?></span><span class="small text-muted"><?= $contract_progress['pct'] ?>%</span></div><div class="progress" style="height:6px"><div class="progress-bar <?= $contract_progress['ended']?'bg-secondary':($contract_progress['pct']>85?'bg-warning':'bg-primary') ?>" style="width:<?= $contract_progress['pct'] ?>%"></div></div></div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-4 col-lg-2"><div class="action-tile-duo"><a href="<?= APP_URL ?>/panel/letters.php" class="action-tile-half"><i class="bi bi-envelope-paper tile-icon text-primary"></i><div class="tile-count text-dark"><?= $my_letters_count ?></div><div class="tile-label">Pisma</div></a><div class="action-tile-duo-sep"></div><a href="<?= APP_URL ?>/panel/apply.php" class="action-tile-half"><?php if ($my_apps_new): ?><span class="tile-badge badge rounded-pill bg-primary" style="font-size:.6rem"><?= $my_apps_new ?></span><?php endif; ?><i class="bi bi-send tile-icon <?= $my_apps_new?'text-primary':'text-secondary' ?>"></i><div class="tile-count text-dark"><?= count($my_apps) ?></div><div class="tile-label">Wnioski</div></a></div></div>
  <div class="col-6 col-sm-4 col-lg-2"><a href="<?= APP_URL ?>/panel/messages.php" class="action-tile h-100"><?php if ($msg_unread): ?><span class="tile-badge badge rounded-pill bg-danger"><?= $msg_unread ?></span><?php endif; ?><i class="bi bi-chat-left-text tile-icon <?= $msg_unread?'text-primary':'text-secondary' ?>"></i><div class="tile-count text-dark"><?= $msg_unread ?></div><div class="tile-label">Wiadomości</div></a></div>
  <div class="col-6 col-sm-4 col-lg-2"><a href="<?= APP_URL ?>/panel/certificates.php" class="action-tile h-100"><?php if ($my_certs_pending): ?><span class="tile-badge badge rounded-pill bg-warning text-dark"><?= $my_certs_pending ?></span><?php endif; ?><i class="bi bi-award tile-icon text-success"></i><div class="tile-count text-dark"><?= count($my_certs) ?></div><div class="tile-label">Zaświadczenia</div></a></div>
  <div class="col-6 col-sm-4 col-lg-2"><a href="<?= APP_URL ?>/panel/terminations.php" class="action-tile h-100"><?php if ($my_terms_pending): ?><span class="tile-badge badge rounded-pill bg-warning text-dark"><?= $my_terms_pending ?></span><?php endif; ?><i class="bi bi-file-earmark-x tile-icon text-danger"></i><div class="tile-count text-dark"><?= count($my_terms) ?></div><div class="tile-label">Rozwiązanie</div></a></div>
</div>
<?php endif; // $contracts (stary widok) ?>
<?php endif; // $_is_volunteer_only — koniec bloku else ?>

<!-- ── Zadania (gdy nie pokazano wyżej, np. widok edytora/admina) ──────────── -->
<?php include __DIR__ . '/includes/pv_tasks_section.php'; ?>

<!-- ── Moje wydarzenia ────────────────────────────────────────────────────── -->
<?php if (module_enabled('events_enabled')):
    require_once dirname(__DIR__) . '/includes/events.php';
    $uid_ev = (int)$user['id'];
    $_my_events = db_all(
        "SELECT e.id, e.slug, e.title, e.type, e.status, e.start_at, e.end_at,
                e.venue, e.meeting_url,
                r.ticket_code, r.status AS reg_status, r.checked_in_at
         FROM ev_registrations r
         JOIN ev_events e ON e.id = r.event_id
         WHERE r.email = ? AND r.status != 'cancelled'
         ORDER BY e.start_at DESC
         LIMIT 6",
        [$user['email'] ?? '']
    );
    $_managed_events = db_all(
        "SELECT e.id, e.title, e.type, e.status, e.start_at,
                (SELECT COUNT(*) FROM ev_registrations rr WHERE rr.event_id=e.id AND rr.status='confirmed') AS reg_count
         FROM ev_roles er
         JOIN ev_events e ON e.id = er.event_id
         WHERE er.user_id = ? AND e.status IN ('published','draft')
         ORDER BY e.start_at DESC
         LIMIT 4",
        [$uid_ev]
    );
    if ($_my_events || $_managed_events):
?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white border-bottom d-flex align-items-center gap-2 py-2 px-3">
    <i class="bi bi-calendar-event-fill" style="color:#7c3aed" aria-hidden="true"></i>
    <h2 class="h6 fw-bold mb-0 flex-grow-1">Moje wydarzenia</h2>
    <a href="<?= APP_URL ?>/events/index.php" class="btn btn-sm btn-outline-secondary py-0">
      <i class="bi bi-arrow-right me-1"></i>Wszystkie
    </a>
  </div>
  <div class="card-body p-3">
    <?php if ($_managed_events): ?>
    <p class="text-muted small fw-semibold mb-2 text-uppercase" style="font-size:.68rem;letter-spacing:.07em">Zarządzam</p>
    <div class="row g-2 mb-3">
      <?php foreach ($_managed_events as $mev): ?>
      <div class="col-12 col-sm-6">
        <a href="<?= APP_URL ?>/events/manage.php?id=<?= $mev['id'] ?>"
           class="d-flex align-items-center gap-2 p-2 rounded border text-decoration-none"
           style="background:#faf5ff;border-color:#ede9fe!important">
          <span style="width:32px;height:32px;border-radius:8px;background:#7c3aed;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="bi bi-<?= $mev['type']==='webinar' ? 'camera-video' : 'geo-alt' ?> text-white" style="font-size:.85rem"></i>
          </span>
          <div style="min-width:0">
            <div class="fw-semibold text-dark text-truncate" style="font-size:.85rem"><?= h($mev['title']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= $mev['start_at'] ? date('d.m.Y', strtotime($mev['start_at'])) : '—' ?>
              · <span class="badge" style="font-size:.65rem;background:<?= $mev['status']==='published'?'#16a34a':($mev['status']==='draft'?'#64748b':'#dc2626') ?>"><?= $mev['reg_count'] ?> os.</span>
            </div>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($_my_events): ?>
    <p class="text-muted small fw-semibold mb-2 text-uppercase" style="font-size:.68rem;letter-spacing:.07em">Moje rejestracje</p>
    <div class="list-group list-group-flush" style="border-radius:8px;overflow:hidden;border:1px solid #e2e8f0">
      <?php foreach ($_my_events as $ev):
        $is_upcoming = $ev['start_at'] && strtotime($ev['start_at']) > time();
        $checked_in  = !empty($ev['checked_in_at']);
      ?>
      <div class="list-group-item list-group-item-action px-3 py-2 d-flex align-items-center gap-3">
        <span style="width:28px;height:28px;border-radius:6px;background:<?= $is_upcoming?'#7c3aed':'#94a3b8' ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="bi bi-<?= $ev['type']==='webinar'?'camera-video':'geo-alt' ?> text-white" style="font-size:.75rem"></i>
        </span>
        <div class="flex-grow-1" style="min-width:0">
          <div class="fw-semibold text-dark text-truncate" style="font-size:.85rem"><?= h($ev['title']) ?></div>
          <div class="text-muted" style="font-size:.75rem">
            <?= $ev['start_at'] ? date('d.m.Y H:i', strtotime($ev['start_at'])) : '—' ?>
            <?php if ($ev['type']==='stationary' && $ev['venue']): ?>· <?= h($ev['venue']) ?><?php endif; ?>
          </div>
        </div>
        <div class="text-end flex-shrink-0">
          <?php if ($checked_in): ?>
            <span class="badge bg-success" style="font-size:.7rem"><i class="bi bi-check2-circle me-1"></i>Check-in</span>
          <?php elseif ($is_upcoming): ?>
            <code class="text-purple fw-bold" style="font-size:.78rem;color:#7c3aed"><?= h($ev['ticket_code']) ?></code>
          <?php else: ?>
            <span class="badge bg-secondary" style="font-size:.7rem">Zakończone</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; /* _my_events || _managed_events */ ?>
<?php endif; /* events_enabled */ ?>

<!-- ── Rezerwacje zasobów ─────────────────────────────────────────────────── -->
<?php
try {
    require_once dirname(__DIR__) . '/includes/resources.php';
    resources_migrate();
    $my_reservations = res_reservations_for_user((int)$user['id']);
    $my_res_active = array_filter($my_reservations, fn($r) => !in_array($r['status'], ['odmowa','anulowana']));
    $my_res_pending = array_filter($my_reservations, fn($r) => in_array($r['status'], ['zlozony','pending_admin','pending_dysponent','oczekuje_dokumenty']));
    $res_enabled = true;
} catch (\Throwable $e) { $res_enabled = false; }
?>
<?php if ($res_enabled): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body">
    <div class="d-flex align-items-center mb-3">
      <h6 class="mb-0 fw-bold"><i class="bi bi-calendar-check-fill text-primary me-2"></i>Rezerwacje zasobów</h6>
      <div class="ms-auto d-flex gap-2">
        <?php if ($my_res_pending): ?>
        <span class="badge bg-warning text-dark"><?= count($my_res_pending) ?> oczekuje</span>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/resources/" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-plus-lg me-1"></i>Zarezerwuj zasób
        </a>
        <a href="<?= APP_URL ?>/resources/my.php" class="btn btn-sm btn-outline-secondary">
          Moje rezerwacje
        </a>
      </div>
    </div>
    <?php if ($my_res_active): ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" style="font-size:.84rem">
        <thead class="table-light">
          <tr><th>Zasób</th><th>Termin</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($my_res_active, 0, 5) as $rr): ?>
          <tr>
            <td>
              <i class="bi <?= h($rr['cat_icon']??'bi-box') ?>" style="color:<?= h($rr['cat_color']??'#666') ?>"></i>
              <?= h($rr['res_name']) ?>
            </td>
            <td class="text-nowrap"><?= h($rr['date_from']) ?><?= $rr['date_to']!==$rr['date_from']?' – '.h($rr['date_to']):'' ?></td>
            <td><?= res_status_badge($rr['status']) ?></td>
            <td><a href="<?= APP_URL ?>/resources/view.php?id=<?= (int)$rr['id'] ?>" class="btn btn-xs btn-sm btn-outline-secondary py-0 px-2"><i class="bi bi-eye"></i></a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
    <p class="text-muted small mb-0">Brak aktywnych rezerwacji. <a href="<?= APP_URL ?>/resources/">Przeglądaj dostępne zasoby</a>.</p>
    <?php endif; ?>
  </div>
</div>
<style>.res-status-badge{display:inline-block;padding:.18em .5em;border-radius:6px;font-size:.73rem;font-weight:600;}</style>
<?php endif; ?>

<!-- ── Modal wyboru umowy ─────────────────────────────────────────────────── -->
<?php if (count($contracts) > 1): ?>
<div class="modal fade" id="contractPickerModal"
     tabindex="-1" role="dialog"
     aria-labelledby="contractPickerModalTitle"
     aria-modal="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h2 class="modal-title fw-bold h5" id="contractPickerModalTitle">
          <i class="bi bi-briefcase me-2" aria-hidden="true"></i>Wybierz umowę
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij okno wyboru umowy"></button>
      </div>
      <div class="modal-body pt-2">
        <?php foreach ($contracts as $c):
          $is_active = $_active_contract
              && $c['contract_type'] === $_active_contract['contract_type']
              && (int)$c['id'] === (int)$_active_contract['id'];
        ?>
        <form method="post" class="mb-2">
          <input type="hidden" name="_csrf"            value="<?= csrf_token() ?>">
          <input type="hidden" name="_select_contract" value="1">
          <input type="hidden" name="contract_type"    value="<?= h($c['contract_type']) ?>">
          <input type="hidden" name="contract_id"      value="<?= (int)$c['id'] ?>">
          <button type="submit"
                  class="btn w-100 text-start <?= $is_active ? 'btn-primary' : 'btn-outline-secondary' ?> d-flex align-items-center gap-3">
            <i class="bi <?= $type_icons[$c['contract_type']] ?? 'bi-file-text' ?> fs-5 flex-shrink-0"></i>
            <div class="flex-grow-1">
              <div class="fw-semibold">
                <?= h($c['numer_umowy'] ?: 'Umowa #' . $c['id']) ?>
                <?php if (!empty($c['_is_guardian'])): ?>
                <span class="badge bg-warning text-dark fw-normal ms-1"><i class="bi bi-person-hearts"></i> dziecko</span>
                <?php endif; ?>
              </div>
              <div class="small opacity-75">
                <?= h(CONTRACT_TYPES[$c['contract_type']] ?? $c['contract_type']) ?>
                <?php if ($c['data_zawarcia']): ?>· <?= date('d.m.Y', strtotime($c['data_zawarcia'])) ?><?php endif; ?>
                <?php if ($c['data_zakonczenia']): ?>– <?= date('d.m.Y', strtotime($c['data_zakonczenia'])) ?><?php endif; ?>
              </div>
            </div>
            <?php if ($is_active): ?><i class="bi bi-check-circle-fill flex-shrink-0"></i><?php endif; ?>
          </button>
        </form>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function togglePesel() {
    var el  = document.getElementById('peselVal');
    var btn = document.getElementById('peselToggle');
    var ico = document.getElementById('peselIcon');
    if (!el) return;
    var showing = el.dataset.showing === '1';
    el.textContent      = showing ? el.dataset.masked : el.dataset.full;
    el.dataset.showing  = showing ? '0' : '1';
    ico.className       = showing ? 'bi bi-eye' : 'bi bi-eye-slash';
    btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
    btn.setAttribute('aria-label',   showing ? 'Pokaż pełny PESEL' : 'Ukryj PESEL');
}
// Canva: potwierdzenie bez onsubmit — ARIA-friendly dialog zastąpiony prostą akcją
(function() {
    var f = document.getElementById('canvaRequestForm');
    if (!f) return;
    f.addEventListener('submit', function(e) {
        if (!window.confirm('Wysłać prośbę o dostęp do Canva Pro?')) e.preventDefault();
    });
})();
</script>

<?php if ($_is_volunteer_only): ?>
<?php include __DIR__ . '/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
<?php endif; ?>

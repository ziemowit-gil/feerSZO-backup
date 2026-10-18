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
require_once dirname(__DIR__) . '/includes/zlecenie_rachunki.php';

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
        $_SESSION['panel_contract_chosen'] = 1; // wybór kontekstu załatwiony w tej sesji
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

// ── Rachunki do umów zlecenie: rachunek czekający na podpis / brak za ten miesiąc
$_rach_pending = [];   // rachunki udostępnione, jeszcze niepodpisane
$_rach_todo    = [];   // aktywne umowy bez rachunku za bieżący miesiąc
foreach ($contracts as $_c) {
    if (($_c['contract_type'] ?? '') !== 'zlecenie') continue;
    foreach (get_rachunki('zlecenie', (int)$_c['id']) as $_r) {
        if (!rachunek_is_signed($_r) && !empty($_r['notify_token'])) {
            $_rach_pending[] = ['rach' => $_r, 'umowa' => $_c];
        }
    }
    if (rachunek_banner_needed($_c)) $_rach_todo[] = $_c;
}

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

$_is_volunteer_only = panel_volunteer_shell($user); // powłoka panelu: wolontariusz albo ktokolwiek z umową (w podglądzie roli flaga k30 nie działa)
// Pulpit METRO dla każdego, kto ma umowę (np. pracownik/edytor z umową wolontariatu) — powłoka zostaje
// wg roli (panel współpracownika vs menu SZO); stary widok tylko dla kadry bez żadnej umowy.
$_metro_home = $_is_volunteer_only || !empty($contracts);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}

// ── Wybór kontekstu: więcej niż jedna umowa → najpierw ekran wyboru (raz na sesję albo ?wybierz=1) ──
if (count($contracts) > 1 && (empty($_SESSION['panel_contract_chosen']) || isset($_GET['wybierz']))) {
    include __DIR__ . '/includes/pv_contract_choose.php';
    include $_is_volunteer_only ? __DIR__ . '/includes/footer_panel.php' : dirname(__DIR__) . '/includes/footer.php';
    exit;
}
?>

<?php require_once dirname(__DIR__) . '/includes/banner_rewrite.php'; ?>

<?php if ($_rach_pending): ?>
<?php $_rp = $_rach_pending[0]; ?>
<!-- ══ BANER — rachunek czeka na podpis ═════════════════════════════════════ -->
<div class="alert d-flex flex-wrap align-items-center gap-3 mb-3"
     style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #93c5fd;border-left:5px solid #2563eb">
  <div class="flex-shrink-0 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;border-radius:14px;background:#2563eb;color:#fff;font-size:1.6rem">
    <i class="bi bi-receipt"></i>
  </div>
  <div class="flex-grow-1" style="min-width:230px">
    <div class="fw-bold" style="font-size:1.15rem">Masz rachunek do podpisania</div>
    <div class="small text-secondary">
      Umowa <?= h($_rp['umowa']['numer_umowy'] ?? '') ?><?= !empty($_rp['rach']['okres']) ? ' · ' . h($_rp['rach']['okres']) : '' ?>.
      Pobierz dokument, podpisz i odeślij — skan w ciągu <?= ZLEC_RACHUNEK_SKAN_DAYS ?> dni,
      oryginał w ciągu <?= ZLEC_RACHUNEK_ORYGINAL_DAYS ?> dni.
      <?php if (count($_rach_pending) > 1): ?>
      <strong>Rachunków oczekujących: <?= count($_rach_pending) ?>.</strong>
      <?php endif; ?>
    </div>
  </div>
  <a href="<?= APP_URL ?>/contracts/zlecenie/rachunek_pobierz.php?token=<?= urlencode($_rp['rach']['notify_token']) ?>"
     class="btn btn-primary btn-lg flex-shrink-0">
    <i class="bi bi-download me-1"></i>Otwórz rachunek
  </a>
</div>
<?php elseif ($_rach_todo): ?>
<!-- ══ BANER — brak rachunku za bieżący miesiąc ═════════════════════════════ -->
<div class="alert d-flex flex-wrap align-items-center gap-3 mb-3"
     style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1px solid #fcd34d;border-left:5px solid #f59e0b">
  <div class="flex-shrink-0 d-flex align-items-center justify-content-center"
       style="width:52px;height:52px;border-radius:14px;background:#f59e0b;color:#fff;font-size:1.6rem">
    <i class="bi bi-receipt-cutoff"></i>
  </div>
  <div class="flex-grow-1" style="min-width:230px">
    <div class="fw-bold" style="font-size:1.15rem">Wyślij rachunek za <?= h(rachunek_month_label()) ?></div>
    <div class="small text-secondary">
      Do Twojej aktywnej umowy zlecenie
      <?= count($_rach_todo) === 1 ? h($_rach_todo[0]['numer_umowy'] ?? '') : '(' . count($_rach_todo) . ' umowy)' ?>
      nie ma jeszcze rachunku za ten miesiąc. Podpisany rachunek prześlij na
      <strong><?= h(rachunek_skan_email()) ?></strong>, a oryginał dostarcz w ciągu
      <?= ZLEC_RACHUNEK_ORYGINAL_DAYS ?> dni<?= rachunek_org_address() ? ' na adres: ' . h(rachunek_org_address()) : '' ?>.
    </div>
  </div>
  <a href="mailto:<?= h(rachunek_skan_email()) ?>?subject=<?= rawurlencode('Rachunek — ' . ($_rach_todo[0]['numer_umowy'] ?? '') . ' — ' . rachunek_month_label()) ?>"
     class="btn btn-warning btn-lg flex-shrink-0 text-dark fw-semibold">
    <i class="bi bi-envelope me-1"></i>Wyślij rachunek
  </a>
</div>
<?php endif; ?>


<?php if ($_metro_home): ?>
<?php if (!$_is_volunteer_only) { require_once __DIR__ . '/includes/pv_ui.php'; pv_ui_styles(); /* tokeny tz-* pod powłoką SZO */ } ?>
<?php include __DIR__ . '/includes/pv_home.php'; /* pulpit METRO jak System Tożsamości: kafle + zakładki pivot */ ?>
<?php else: ?>
<?php /* ═══ KADRA BEZ UMOWY: „Mój panel” w stylu METRO (kafle + zadania + pełnomocnictwa + rezerwacje) ═══ */ ?>
<?php include __DIR__ . '/includes/pv_staff_home.php'; ?>
<?php endif; // $_metro_home — koniec bloku else (stary widok) ?>

<!-- ── Zadania (gdy nie pokazano wyżej, np. widok edytora/admina) ──────────── -->
<?php include __DIR__ . '/includes/pv_tasks_section.php'; ?>

<!-- ── Moje pełnomocnictwa (widoczne dla każdego pełnomocnika) ─────────────── -->
<?php if (empty($GLOBALS['_pv_pelnomocnictwa_done'])) include __DIR__ . '/includes/pv_pelnomocnictwa.php'; ?>

<!-- ── Sekcje wspólne (wydarzenia, rezerwacje) renderują pv_home.php i pv_staff_home.php w swoich sekcjach ── -->

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
                  class="<?= $is_active ? 'tz-btn' : 'tz-btn tz-btn--ghost' ?> w-100 text-start d-flex align-items-center gap-3">
            <i class="bi <?= $type_icons[$c['contract_type']] ?? 'bi-file-text' ?> fs-5 flex-shrink-0"></i>
            <div class="flex-grow-1">
              <div class="fw-semibold">
                <?= h($c['numer_umowy'] ?: 'Umowa #' . $c['id']) ?>
                <?php if (!empty($c['_is_guardian'])): ?>
                <span class="tz-badge ms-1"><i class="bi bi-person-hearts"></i> dziecko</span>
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

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
    csrf_check();
    try { db()->exec("ALTER TABLE users ADD COLUMN sms_nudge_dismissed TINYINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    try { db()->prepare("UPDATE users SET sms_nudge_dismissed=1 WHERE id=?")->execute([(int)$user['id']]); } catch (\Throwable $e) {}
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
<?php /* ═══ PANEL WOLONTARIUSZA — nowy layout + WCAG 2.1 AA ══════════════════ */ ?>
<?php
// RGB składowe koloru bez color-mix() — dla rgba() w CSS
$_vol_rgb = (function(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = str_repeat($hex[0],2).str_repeat($hex[1],2).str_repeat($hex[2],2);
    return hexdec(substr($hex,0,2)).','.hexdec(substr($hex,2,2)).','.hexdec(substr($hex,4,2));
})($_vol_color ?? '#1D4ED8');
// Ciemniejszy wariant koloru (70%) do gradientu hero
$_vol_dark = (function(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = str_repeat($hex[0],2).str_repeat($hex[1],2).str_repeat($hex[2],2);
    return sprintf('#%02x%02x%02x',
        (int)(hexdec(substr($hex,0,2))*.70),
        (int)(hexdec(substr($hex,2,2))*.70),
        (int)(hexdec(substr($hex,4,2))*.70));
})($_vol_color ?? '#1D4ED8');
?>
<style>
/* ══ Panel wolontariusza — WCAG 2.1 AA ══════════════════════════════════ */

/* Nagłówek strony */
.pv-page-header { display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.4rem; }
.pv-page-title  { font-size:1.2rem; font-weight:800; color:#111827; margin:0 0 .15rem; line-height:1.3; }
.pv-page-sub    { font-size:.84rem; color:#4B5563; margin:0; }
.pv-page-warmup { font-size:.87rem; font-weight:500; margin-top:.3rem; color:var(--vol-color); }

/* Karta umowy — hero (bez color-mix) */
.vol-contract-hero {
  background: linear-gradient(135deg, var(--vol-color) 0%, <?= h($_vol_dark) ?> 100%);
  border-radius: 14px; color: #fff; padding: 1.5rem;
  margin-bottom: 1rem; position: relative; overflow: hidden;
  box-shadow: 0 6px 20px rgba(<?= h($_vol_rgb) ?>,.30);
}
.vol-contract-hero::before {
  content:''; position:absolute; width:240px; height:240px; border-radius:50%;
  background:rgba(255,255,255,.06); bottom:-90px; right:-60px; pointer-events:none;
}
.vol-contract-hero-num    { font-size:.8rem; opacity:.85; font-weight:500; margin-bottom:.2rem; }
.vol-contract-hero-type   { font-size:1.1rem; font-weight:800; margin-bottom:.45rem; line-height:1.3; }
.vol-contract-hero-status { display:inline-flex; align-items:center; gap:.3rem; padding:.28rem .75rem; border-radius:2rem; background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.35); font-size:.8rem; font-weight:600; }
.vol-contract-hero-dates  { font-size:.8rem; opacity:.85; margin-top:.65rem; }

/* Progress bar */
.vol-progress-wrap { background:rgba(255,255,255,.22); border-radius:4px; height:6px; margin-top:.85rem; overflow:hidden; }
.vol-progress-fill { background:#fff; height:6px; border-radius:4px; }
.vol-progress-label { display:flex; justify-content:space-between; font-size:.76rem; opacity:.85; margin-top:.3rem; }

/* Dane umowy — definition grid */
.vol-data-grid {
  display:grid; grid-template-columns:repeat(auto-fill,minmax(135px,1fr)); gap:.65rem;
  margin-bottom:1rem; list-style:none; padding:0;
}
.vol-data-item { background:#fff; border-radius:10px; padding:.75rem .9rem; box-shadow:0 1px 5px rgba(0,0,0,.06); }
.vol-data-lbl  { font-size:.72rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#6B7280; margin-bottom:.2rem; display:block; }
.vol-data-val  { font-size:.88rem; font-weight:600; color:#111827; }
.vol-data-val.monospace { font-family:monospace; letter-spacing:.05em; }

/* Szybkie akcje — bez transform/onmouseenter */
.vol-actions {
  display:grid; grid-template-columns:repeat(auto-fill,minmax(148px,1fr));
  gap:.75rem; margin-bottom:1.25rem; list-style:none; padding:0;
}
.vol-action-btn {
  display:flex; flex-direction:column; align-items:flex-start;
  gap:.4rem; padding:1rem 1.05rem;
  background:#fff; border:1.5px solid #E5E7EB; border-radius:14px;
  text-decoration:none; color:#1E293B;
  box-shadow:0 1px 5px rgba(0,0,0,.05);
  transition:border-color .12s, box-shadow .12s;
  position:relative; min-height:88px;
}
.vol-action-btn:hover,
.vol-action-btn:focus-visible {
  border-color:var(--vol-color);
  box-shadow:0 4px 14px rgba(<?= h($_vol_rgb) ?>,.18);
  color:#1E293B; outline-offset:2px;
}
.vol-action-icon-wrap {
  width:36px; height:36px; border-radius:8px;
  display:flex; align-items:center; justify-content:center;
  background:rgba(<?= h($_vol_rgb) ?>,.10);
}
.vol-action-icon      { font-size:1.1rem; color:var(--vol-color); }
.vol-action-label     { font-size:.82rem; font-weight:700; line-height:1.25; color:#1E293B; }
.vol-action-count     { font-size:1.3rem; font-weight:900; line-height:1; color:var(--vol-color); }
.vol-action-sub       { font-size:.72rem; color:#6B7280; }
.vol-action-badge     { position:absolute; top:.5rem; right:.6rem; font-size:.65rem; }
@media(max-width:380px) { .vol-actions { grid-template-columns:repeat(2,1fr); } }

/* Szczegóły — karty */
.vol-detail-card { background:#fff; border-radius:12px; overflow:hidden; margin-bottom:1rem; box-shadow:0 1px 6px rgba(0,0,0,.06); }
.vol-detail-header { display:flex; align-items:center; gap:.4rem; padding:.75rem 1rem; border-bottom:1px solid #F3F4F6; font-size:.85rem; font-weight:700; color:#374151; }
.vol-detail-header i { color:var(--vol-color); }
.vol-detail-body { padding:.6rem 1rem; }
.vol-detail-row { display:flex; justify-content:space-between; align-items:baseline; gap:.5rem; padding:.38rem 0; border-bottom:1px solid #F9FAFB; font-size:.86rem; }
.vol-detail-row:last-child { border-bottom:none; }
.vol-detail-row-lbl { color:#6B7280; min-width:130px; flex-shrink:0; font-size:.8rem; }
.vol-detail-row-val { color:#111827; font-weight:500; text-align:right; }

/* Znaczniki tak/nie */
.vol-badge-yes { display:inline-flex; align-items:center; gap:.25rem; color:#15803D; font-weight:600; font-size:.82rem; }
.vol-badge-no  { display:inline-flex; align-items:center; gap:.25rem; color:#6B7280; font-size:.82rem; }

/* Panel aktywności */
.vol-activity { background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 1px 6px rgba(0,0,0,.05); }
.vol-activity-header { display:flex; align-items:center; justify-content:space-between; padding:.75rem 1rem; border-bottom:1px solid #F3F4F6; }
.vol-activity-title  { font-size:.83rem; font-weight:700; color:#374151; }
.vol-activity-row {
  display:flex; align-items:center; gap:.65rem;
  padding:.55rem 1rem; border-bottom:1px solid #F9FAFB; font-size:.83rem;
}
.vol-activity-row:last-child { border-bottom: none; }
.vol-activity-icon { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .8rem; flex-shrink: 0; }
.pv-stats-bar { display:flex; gap:.6rem; flex-wrap:wrap; margin-bottom:1.1rem; }
.pv-stat-pill {
  display:inline-flex; align-items:center; gap:.35rem;
  padding:.3rem .7rem; border-radius:2rem;
  background:#fff; border:1px solid #E5E7EB;
  font-size:.79rem; font-weight:500; color:#374151;
  box-shadow:0 1px 3px rgba(0,0,0,.05);
  text-decoration:none;
}
.pv-stat-pill:hover { border-color:var(--vol-color); color:var(--vol-color); }
.pv-stat-num { font-weight:700; color:var(--vol-color); }
</style>

<!-- Nagłówek -->
<?php
$_fname = trim(($_active_row['imie_nazwisko'] ?? $user['name'] ?? ''));
$_fname_first = explode(' ', $_fname)[0] ?? 'Wolontariuszu';
$_hour = (int)date('G');
$_greet = $_hour < 12 ? 'Dzień dobry' : ($_hour < 18 ? 'Witaj' : 'Dobry wieczór');
?>
<div class="pv-page-header d-flex align-items-start justify-content-between flex-wrap gap-2">
  <div>
    <h1 class="pv-page-title"><?= h($_greet) ?>, <?= h($_fname_first) ?> 👋</h1>
    <div class="pv-page-sub"><?= h(ORG_NAME) ?> · <?= date('d F Y') ?></div>
    <?php if (!empty($_active_contract) && in_array($_active_contract['status'] ?? '', ['podpisana','w realizacji'])): ?>
    <div class="pv-page-warmup">Cieszmy się, że jesteś z nami!<?php
      if (!empty($_active_row['data_zawarcia'])) {
        $_days_together = (int)floor((time() - strtotime($_active_row['data_zawarcia'])) / 86400);
        if ($_days_together > 0) echo ' Współpracujemy już <strong>'.$_days_together.'</strong> '.($_days_together === 1 ? 'dzień' : ($_days_together < 5 ? 'dni' : 'dni')).'.';
      }
    ?></div>
    <?php endif; ?>
  </div>
  <?php if (count($contracts) > 1): ?>
  <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#contractPickerModal"
          aria-haspopup="dialog">
    <i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Zmień umowę
  </button>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<!-- ── Baner: poczta organizacji ─────────────────────────────────────────── -->
<style>
.pvp-mail-baner {
  display:flex; align-items:center; gap:1rem;
  background:linear-gradient(135deg,#1e40af 0%,#2563eb 100%);
  border-radius:12px; padding:.9rem 1.25rem; margin-bottom:1rem;
  text-decoration:none; color:#fff;
  box-shadow:0 4px 16px rgba(30,64,175,.3);
  transition:opacity .15s;
}
.pvp-mail-baner:hover, .pvp-mail-baner:focus-visible {
  opacity:.9; color:#fff; outline-offset:2px;
}
.pvp-mail-baner-icon {
  width:40px; height:40px; border-radius:10px; flex-shrink:0;
  background:rgba(255,255,255,.16); display:flex; align-items:center;
  justify-content:center; font-size:1.2rem;
}
</style>
<a href="https://poczta.feer.org.pl" target="_blank" rel="noopener noreferrer"
   class="pvp-mail-baner"
   aria-label="Poczta organizacji — poczta.feer.org.pl (otwiera w nowej karcie)">
  <div class="pvp-mail-baner-icon" aria-hidden="true">
    <i class="bi bi-envelope-fill"></i>
  </div>
  <div style="flex:1;min-width:0">
    <div style="font-size:.72rem;font-weight:600;opacity:.78;letter-spacing:.07em;text-transform:uppercase;margin-bottom:.1rem">
      Szukasz poczty?
    </div>
    <div style="font-size:1rem;font-weight:800;letter-spacing:-.01em">
      poczta.feer.org.pl
    </div>
  </div>
  <i class="bi bi-arrow-right-circle-fill" style="font-size:1.4rem;opacity:.7;flex-shrink:0" aria-hidden="true"></i>
  <span class="visually-hidden">(otwiera w nowej karcie)</span>
</a>

<?php
// ── Zachęta do uzupełnienia profilu w katalogu ────────────────────────────────
// Pokazuje się gdy profil jest niepełny (brak bio lub zdjęcia) — raz na sesję
auth_start();
$_show_dir_invite = false;
if (!empty($_SESSION['_panel_dir_invited'])) {
    $_show_dir_invite = false; // już pokazano w tej sesji
} else {
    // Sprawdź czy profil jest wypełniony
    $_dir_profile = null;
    try {
        $_dir_profile = db_one("SELECT bio, avatar_file FROM user_profiles WHERE user_id=?", [(int)$user['id']]);
    } catch (\Throwable $e) {}
    $_profile_empty = empty($_dir_profile['bio']) && empty($_dir_profile['avatar_file']);

    if ($_profile_empty) {
        $_show_dir_invite = true;
        $_SESSION['_panel_dir_invited'] = true;
    }
}
?>

<?php if ($_show_dir_invite): ?>
<div class="dir-invite mb-4" role="complementary" aria-label="Zaproszenie do katalogu współpracowników">
  <div class="dir-invite-inner">
    <div class="dir-invite-icon" aria-hidden="true">
      <i class="bi bi-people-fill"></i>
    </div>
    <div class="dir-invite-content">
      <div class="dir-invite-title">Uzupełnij swój profil w katalogu 👤</div>
      <div class="dir-invite-sub">
        Twój profil w katalogu organizacji jest niepełny. Dodaj zdjęcie, krótki opis i umiejętności —
        inni współpracownicy łatwiej Cię znajdą, a koordynatorzy będą mogli lepiej dopasować zadania.
      </div>
    </div>
    <div class="dir-invite-actions">
      <a href="<?= APP_URL ?>/directory/profile_edit.php"
         class="dir-invite-btn"
         aria-label="Uzupełnij swój profil w katalogu">
        <i class="bi bi-person-badge me-1" aria-hidden="true"></i>
        Uzupełnij profil
      </a>
      <button type="button"
              class="dir-invite-dismiss"
              aria-label="Zamknij zaproszenie"
              onclick="this.closest('.dir-invite').style.display='none'">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>
  </div>
</div>

<style>
.dir-invite {
  border-radius:12px;
  background:linear-gradient(135deg,var(--vol-color) 0%,<?= h($_vol_dark) ?> 100%);
  overflow:hidden;
  box-shadow:0 4px 16px rgba(<?= h($_vol_rgb) ?>,.28);
  animation:pvDirSlide .35s ease;
}
@media(prefers-reduced-motion:reduce){.dir-invite{animation:none}}
@keyframes pvDirSlide{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}
.dir-invite-inner  { display:flex; align-items:center; gap:1rem; padding:1rem 1.25rem; flex-wrap:wrap; }
.dir-invite-icon   { width:44px; height:44px; border-radius:10px; background:rgba(255,255,255,.18); display:flex; align-items:center; justify-content:center; font-size:1.35rem; color:#fff; flex-shrink:0; }
.dir-invite-content{ flex:1; min-width:180px; }
.dir-invite-title  { font-weight:800; font-size:.94rem; color:#fff; margin-bottom:.15rem; }
.dir-invite-sub    { font-size:.8rem; color:rgba(255,255,255,.82); line-height:1.45; }
.dir-invite-actions{ display:flex; align-items:center; gap:.5rem; flex-shrink:0; }
.dir-invite-btn    { display:inline-flex; align-items:center; gap:.35rem; padding:.45rem 1rem; border-radius:7px; background:rgba(255,255,255,.95); color:var(--vol-color); font-size:.83rem; font-weight:700; text-decoration:none; white-space:nowrap; transition:background .15s; border:2px solid transparent; }
.dir-invite-btn:hover,.dir-invite-btn:focus-visible { background:#fff; color:var(--vol-color); outline-offset:2px; }
.dir-invite-dismiss{ background:rgba(255,255,255,.15); border:1.5px solid rgba(255,255,255,.3); border-radius:7px; color:rgba(255,255,255,.85); padding:.4rem .5rem; cursor:pointer; font-size:.9rem; line-height:1; transition:background .12s; }
.dir-invite-dismiss:hover,.dir-invite-dismiss:focus-visible{ background:rgba(255,255,255,.28); color:#fff; outline-offset:2px; }
@media(max-width:500px){ .dir-invite-inner{gap:.75rem} .dir-invite-btn{font-size:.8rem;padding:.4rem .8rem} }
</style>
<?php endif; ?>

<?php
// ── Zachęta do logowania SMS ──────────────────────────────────────────────────
// Warunki: SMS logowanie włączone + nie odrzucono wcześniej
// Dwa tryby: ma numer (zachęta do wypróbowania) | brak numeru (zachęta do dodania)
$_show_sms_nudge    = false;
$_nudge_has_phone   = false;
if ($_sms_login_available) {
    try { db()->exec("ALTER TABLE users ADD COLUMN sms_nudge_dismissed TINYINT NOT NULL DEFAULT 0"); } catch (\Throwable $e) {}
    $_nudge_dismissed = (int)(db_one("SELECT sms_nudge_dismissed FROM users WHERE id=?", [(int)$user['id']])['sms_nudge_dismissed'] ?? 0);
    if (!$_nudge_dismissed) {
        $_nudge_has_phone = !empty($user['phone_number']) || !empty($_active_row['telefon']);
        $_show_sms_nudge  = true; // pokaż w obu trybach (z/bez numeru)
    }
}
?>

<?php if ($_show_sms_nudge): ?>
<div class="pvp-sms-nudge mb-3" role="complementary" aria-label="Zachęta do logowania SMS">
  <div class="pvp-sms-nudge-inner">
    <div class="pvp-sms-nudge-icon" aria-hidden="true">
      <i class="bi bi-<?= $_nudge_has_phone ? 'phone-fill' : 'phone' ?>"></i>
    </div>
    <div class="pvp-sms-nudge-content">
      <?php if ($_nudge_has_phone): ?>
      <div class="pvp-sms-nudge-title">Zaloguj się szybciej kodem SMS 📱</div>
      <div class="pvp-sms-nudge-sub">
        Zamiast hasła wpisz numer telefonu i zaloguj się jednorazowym kodem SMS — szybciej i bez zapamiętywania haseł.
      </div>
      <?php else: ?>
      <div class="pvp-sms-nudge-title">Uprość logowanie — dodaj numer telefonu 📱</div>
      <div class="pvp-sms-nudge-sub">
        Podaj swój numer w profilu, a będziesz mógł logować się kodem SMS zamiast hasłem.
      </div>
      <?php endif; ?>
    </div>
    <div class="pvp-sms-nudge-actions">
      <?php if ($_nudge_has_phone): ?>
      <a href="<?= APP_URL ?>/auth/login.php?tab=sms"
         class="pvp-sms-nudge-btn"
         aria-label="Wypróbuj logowanie kodem SMS przy następnym logowaniu">
        <i class="bi bi-phone me-1" aria-hidden="true"></i>Spróbuj
      </a>
      <?php else: ?>
      <a href="<?= APP_URL ?>/directory/profile_edit.php"
         class="pvp-sms-nudge-btn"
         aria-label="Dodaj numer telefonu w profilu">
        <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj numer
      </a>
      <?php endif; ?>
      <form method="post" style="display:inline;margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="_dismiss_sms_nudge" value="1">
        <button type="submit"
                class="pvp-sms-nudge-dismiss"
                aria-label="Nie pokazuj ponownie">
          <i class="bi bi-x-lg" aria-hidden="true"></i>
        </button>
      </form>
    </div>
  </div>
</div>
<style>
.pvp-sms-nudge{
  border-radius:12px;
  background:linear-gradient(135deg,#065f46 0%,#059669 100%);
  overflow:hidden;
  box-shadow:0 4px 16px rgba(5,150,105,.25);
  animation:pvDirSlide .35s ease;
}
@media(prefers-reduced-motion:reduce){.pvp-sms-nudge{animation:none}}
.pvp-sms-nudge-inner  {display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;flex-wrap:wrap}
.pvp-sms-nudge-icon   {width:44px;height:44px;border-radius:10px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:1.35rem;color:#fff;flex-shrink:0}
.pvp-sms-nudge-content{flex:1;min-width:180px}
.pvp-sms-nudge-title  {font-weight:800;font-size:.94rem;color:#fff;margin-bottom:.15rem}
.pvp-sms-nudge-sub    {font-size:.8rem;color:rgba(255,255,255,.82);line-height:1.45}
.pvp-sms-nudge-actions{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.pvp-sms-nudge-btn    {display:inline-flex;align-items:center;gap:.35rem;padding:.45rem 1rem;border-radius:7px;background:rgba(255,255,255,.95);color:#059669;font-size:.83rem;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s;border:2px solid transparent}
.pvp-sms-nudge-btn:hover,.pvp-sms-nudge-btn:focus-visible{background:#fff;color:#059669;outline-offset:2px}
.pvp-sms-nudge-dismiss{background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.3);border-radius:7px;color:rgba(255,255,255,.85);padding:.4rem .5rem;cursor:pointer;font-size:.9rem;line-height:1;transition:background .12s}
.pvp-sms-nudge-dismiss:hover,.pvp-sms-nudge-dismiss:focus-visible{background:rgba(255,255,255,.28);color:#fff;outline-offset:2px}
@media(max-width:500px){.pvp-sms-nudge-inner{gap:.75rem}.pvp-sms-nudge-btn{font-size:.8rem;padding:.4rem .8rem}}
</style>
<?php endif; ?>

<?php if (!$contracts): ?>
<!-- Brak umów -->
<div class="vol-detail-card text-center py-5">
  <i class="bi bi-file-earmark-x" style="font-size:2.5rem;color:#D1D5DB;display:block;margin-bottom:.75rem" aria-hidden="true"></i>
  <p class="fw-semibold text-muted mb-1">Nie znaleziono umów powiązanych z Twoim kontem.</p>
  <p class="text-muted small">Skontaktuj się z administratorem, aby powiązać umowę z adresem <?= h($user['email']) ?>.</p>
</div>
<?php else: ?>

<!-- ═══ KARTA UMOWY — HERO ═══════════════════════════════════════════════════ -->
<?php if ($_active_row): ?>
<?php
$_ct_type = $_active_contract['contract_type'] ?? 'wolontariat';
$_ct_label = CONTRACT_TYPES[$_ct_type] ?? $_ct_type;
$_is_wolontariat = $_ct_type === 'wolontariat';

// Maska PESEL
$_pesel = $_active_row['pesel'] ?? '';
$_pesel_masked = $_pesel ? (substr($_pesel,0,2).'·····'.substr($_pesel,7)) : '';
?>
<section aria-labelledby="pvp-contract-heading">
<h2 id="pvp-contract-heading" class="visually-hidden">Twoja umowa</h2>
<div class="vol-contract-hero">
  <div class="vol-contract-hero-num">
    Twoje porozumienie wolontariackie
    <?php if ($_is_guardian): ?>
    <span style="margin-left:.5rem;background:rgba(251,191,36,.3);border:1px solid rgba(251,191,36,.5);border-radius:2rem;padding:.1rem .5rem;font-size:.75rem">
      <i class="bi bi-person-hearts" aria-hidden="true"></i> Opiekun
    </span>
    <?php endif; ?>
  </div>

  <?php if ($_active_row['imie_nazwisko'] ?? null): ?>
  <div class="vol-contract-hero-type"><?= h($_active_row['imie_nazwisko']) ?></div>
  <?php endif; ?>

  <?php
  $_status_labels_human = ['projekt'=>'W przygotowaniu','podpisana'=>'Aktywne 🌱','w realizacji'=>'Aktywne 🌱','zakończona'=>'Zakończone','rozwiązana'=>'Zakończone','anulowana'=>'Anulowane'];
  $_status_icons  = ['podpisana'=>'bi-check-circle','w realizacji'=>'bi-play-circle','zakończona'=>'bi-flag','rozwiązana'=>'bi-x-circle','anulowana'=>'bi-slash-circle','projekt'=>'bi-clock'];
  $_st = $_active_contract['status'] ?? '';
  ?>
  <div>
    <span class="vol-contract-hero-status">
      <i class="bi <?= $_status_icons[$_st] ?? 'bi-circle' ?>" aria-hidden="true"></i>
      <?= h($_status_labels_human[$_st] ?? ucfirst($_st)) ?>
    </span>
  </div>

  <?php if (($_active_row['data_zawarcia'] ?? null) || ($_active_contract['data_zakonczenia'] ?? null)): ?>
  <div class="vol-contract-hero-dates">
    <?php if ($_active_row['data_zawarcia'] ?? null): ?>
    <i class="bi bi-calendar3 me-1" aria-hidden="true"></i>
    Od <?= date('d.m.Y', strtotime($_active_row['data_zawarcia'])) ?>
    <?php endif; ?>
    <?php if ($_active_contract['data_zakonczenia'] ?? null): ?>
    &nbsp;→&nbsp; <?= date('d.m.Y', strtotime($_active_contract['data_zakonczenia'])) ?>
    <?php elseif (!empty($_active_row['bezterminowa'])): ?>
    &nbsp;→&nbsp; <i class="bi bi-infinity" aria-hidden="true"></i> bezterminowo
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($contract_progress): ?>
  <?php include __DIR__ . '/includes/pv_contract_countdown.php'; ?>
  <?php endif; ?>
</div>
</section>

<!-- Dane podstawowe — lista definicji -->
<ul class="vol-data-grid mb-3" aria-label="Twoje dane z umowy">
  <?php if ($_pesel): ?>
  <li class="vol-data-item">
    <span class="vol-data-lbl" id="pesel-lbl">PESEL</span>
    <div class="vol-data-val monospace d-flex align-items-center gap-1">
      <span id="peselVal"
            data-masked="<?= h($_pesel_masked) ?>"
            data-full="<?= h($_pesel) ?>"
            aria-labelledby="pesel-lbl"><?= h($_pesel_masked) ?></span>
      <button type="button"
              id="peselToggle"
              class="btn btn-link btn-sm p-0 ms-1"
              style="color:#6B7280;font-size:.85rem;line-height:1;text-decoration:none"
              aria-pressed="false"
              aria-label="Pokaż pełny PESEL"
              onclick="togglePesel()">
        <i class="bi bi-eye" id="peselIcon" aria-hidden="true"></i>
      </button>
    </div>
  </li>
  <?php endif; ?>

  <?php if ($_active_row['data_urodzenia'] ?? null): ?>
  <li class="vol-data-item">
    <span class="vol-data-lbl">Data urodzenia</span>
    <div class="vol-data-val"><?= date('d.m.Y', strtotime($_active_row['data_urodzenia'])) ?></div>
  </li>
  <?php endif; ?>

  <?php if ($_active_row['telefon'] ?? null): ?>
  <li class="vol-data-item">
    <span class="vol-data-lbl">Telefon</span>
    <div class="vol-data-val">
      <a href="tel:+<?= h($_active_row['telefon']) ?>" style="color:inherit;text-decoration:underline;text-decoration-color:transparent"
         onmouseover="this.style.textDecorationColor=''" onmouseout="this.style.textDecorationColor='transparent'">
        +<?= h($_active_row['telefon']) ?>
      </a>
    </div>
  </li>
  <?php endif; ?>

  <?php if ($_is_wolontariat && ($_active_row['miejsce_wolontariatu'] ?? null)): ?>
  <li class="vol-data-item">
    <span class="vol-data-lbl">Miejsce wolontariatu</span>
    <div class="vol-data-val"><?= h($_active_row['miejsce_wolontariatu']) ?></div>
  </li>
  <?php endif; ?>

  <?php if ($_is_wolontariat && ($_active_row['godzin_tygodniowo'] ?? null)): ?>
  <li class="vol-data-item">
    <span class="vol-data-lbl">Godz. / tydzień</span>
    <div class="vol-data-val"><?= h($_active_row['godzin_tygodniowo']) ?> h</div>
  </li>
  <?php endif; ?>

  <?php if ($_is_wolontariat && ($_active_row['opiekun'] ?? null)): ?>
  <li class="vol-data-item">
    <span class="vol-data-lbl">Opiekun</span>
    <div class="vol-data-val"><?= h($_active_row['opiekun']) ?></div>
  </li>
  <?php endif; ?>

  <?php if ($_active_row['adres'] ?? null): ?>
  <li class="vol-data-item" style="grid-column:span 2">
    <span class="vol-data-lbl">Adres</span>
    <div class="vol-data-val"><?= h($_active_row['adres']) ?></div>
  </li>
  <?php endif; ?>
</ul>

<!-- Szczegóły wolontariatu: BHP, ubezpieczenia, projekt -->
<?php if ($_is_wolontariat): ?>
<div class="row g-3 mb-3">
  <div class="col-md-6">
    <div class="vol-detail-card">
      <div class="vol-detail-header">
        <i class="bi bi-shield-check" aria-hidden="true"></i> BHP i ubezpieczenia
      </div>
      <div class="vol-detail-body">
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Szkolenie BHP</span>
          <?php if (!empty($_active_row['szkolenie_bhp'])): ?>
          <span class="vol-badge-yes"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Tak<?= ($_active_row['data_szkolenia_bhp'] ?? '') ? ' · '.date('d.m.Y', strtotime($_active_row['data_szkolenia_bhp'])) : '' ?></span>
          <?php else: ?><span class="vol-badge-no"><i class="bi bi-dash" aria-hidden="true"></i>Nie</span><?php endif; ?>
        </div>
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Ubezpieczenie NNW</span>
          <?php if (!empty($_active_row['ubezpieczenie_nnw'])): ?>
          <span class="vol-badge-yes"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Tak<?= ($_active_row['numer_polisy_nnw'] ?? '') ? ' · '.h($_active_row['numer_polisy_nnw']) : '' ?></span>
          <?php else: ?><span class="vol-badge-no"><i class="bi bi-dash" aria-hidden="true"></i>Nie</span><?php endif; ?>
        </div>
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Ubezpieczenie OC</span>
          <?php if (!empty($_active_row['ubezpieczenie_oc'])): ?>
          <span class="vol-badge-yes"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Tak</span>
          <?php else: ?><span class="vol-badge-no"><i class="bi bi-dash" aria-hidden="true"></i>Nie</span><?php endif; ?>
        </div>
        <?php if (!empty($_active_row['zwrot_kosztow'])): ?>
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Zwrot kosztów</span>
          <span class="vol-badge-yes">
            <i class="bi bi-receipt-cutoff" aria-hidden="true"></i>Tak
            <?= ($_active_row['limit_zwrotu_kosztow'] ?? '') ? ' · limit '.number_format((float)$_active_row['limit_zwrotu_kosztow'],2,',',' ').' zł' : '' ?>
          </span>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-md-6">
    <div class="vol-detail-card">
      <div class="vol-detail-header">
        <i class="bi bi-info-circle" aria-hidden="true"></i> Szczegóły porozumienia
      </div>
      <div class="vol-detail-body">
        <?php if ($_active_row['projekt_program'] ?? null): ?>
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Projekt / program</span>
          <span class="vol-detail-row-val"><?= h($_active_row['projekt_program']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($_active_row['forma_podpisania'] ?? null): ?>
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Forma podpisania</span>
          <span class="vol-detail-row-val"><?= h(ucfirst(str_replace('_',' ',$_active_row['forma_podpisania']))) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($_active_row['bezterminowa'])): ?>
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Czas trwania</span>
          <span class="vol-badge-yes"><i class="bi bi-infinity" aria-hidden="true"></i> Bezterminowa</span>
        </div>
        <?php endif; ?>
        <?php if ($_active_row['godzin_przepracowanych'] ?? null): ?>
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Godzin przepracowanych</span>
          <span class="vol-detail-row-val">
            <?= h(number_format((float)$_active_row['godzin_przepracowanych'], 2, ',', ' ')) ?> h
            <?php if ((float)($_active_row['godzin_z_zadan'] ?? 0) > 0): ?>
            <small class="text-muted" title="Z zarejestrowanego czasu zadań">
              (w tym <?= h(number_format((float)$_active_row['godzin_z_zadan'], 2, ',', ' ')) ?> h z zadań)
            </small>
            <?php endif; ?>
          </span>
        </div>
        <?php endif; ?>
        <?php if ($_active_row['m365_login'] ?? null): ?>
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Konto Microsoft 365</span>
          <span class="vol-badge-yes"><i class="bi bi-microsoft" aria-hidden="true"></i><?= h($_active_row['m365_login']) ?></span>
        </div>
        <?php endif; ?>
        <div class="vol-detail-row">
          <span class="vol-detail-row-lbl">Nr rejestru</span>
          <span class="vol-detail-row-val" style="font-family:monospace"><?= h($_active_row['nr_rejestru'] ?? '—') ?></span>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; // is_wolontariat ?>
<?php endif; // _active_row ?>

<?php
// ── Centrum akcji — dane dla wyspy React (panel/includes/pv_action_hub.php) ──
// Helpdesk IT — liczba otwartych zgłoszeń użytkownika
$_hd_my_open = 0;
try {
    $_hd_my_open = (int)(db_one(
        "SELECT COUNT(*) AS c FROM helpdesk_tickets
         WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')",
        [(int)$user['id']]
    )['c'] ?? 0);
} catch (\Throwable $e) {}

$_pv_actions = [];
$_pv_actions[] = [
    'href' => APP_URL . '/panel/messages.php', 'icon' => 'bi-chat-left-text',
    'label' => 'Napisz do nas', 'count' => $msg_unread ?: 0,
    'sub' => $msg_unread ? "$msg_unread nowych" : 'brak nowych',
    'badge' => $msg_unread, 'badgeClass' => 'danger',
];
$_pv_actions[] = [
    'href' => APP_URL . '/panel/apply.php', 'icon' => 'bi-send',
    'label' => 'Złóż wniosek', 'count' => count($my_apps),
    'sub' => $my_apps_new ? "$my_apps_new oczekuje" : 'złożone wnioski',
    'badge' => $my_apps_new, 'badgeClass' => 'danger',
];
if (module_enabled('certificates_enabled')) {
    $_pv_actions[] = [
        'href' => APP_URL . '/panel/certificates.php', 'icon' => 'bi-award',
        'iconColor' => '#D97706', 'iconBg' => '#FFF8E7',
        'label' => 'Zaświadczenia', 'count' => count($my_certs),
        'sub' => $my_certs_pending ? "$my_certs_pending w toku" : 'wszystkie gotowe',
        'badge' => $my_certs_pending, 'badgeClass' => 'warning',
    ];
}
if ($_active_zwroty) {
    $_pv_actions[] = [
        'href' => APP_URL . '/panel/zwroty.php', 'icon' => 'bi-receipt',
        'iconColor' => '#16A34A', 'iconBg' => '#F0FDF4',
        'label' => 'Rozlicz koszty', 'count' => $_zwroty_pending ?: 0,
        'sub' => $_zwroty_pending ? 'oczekuje zwrotu' : 'do złożenia',
        'badge' => $_zwroty_pending, 'badgeClass' => 'warning',
    ];
}
if (module_enabled('timesheets_enabled')) {
    $_pv_actions[] = [
        'href' => APP_URL . '/panel/timesheets.php', 'icon' => 'bi-clock-history',
        'iconColor' => '#7C3AED', 'iconBg' => '#F5F3FF',
        'label' => 'Ewidencja godzin', 'sub' => 'arkusze czasu',
    ];
}
if (module_enabled('letters_enabled') && $my_letters_count) {
    $_pv_actions[] = [
        'href' => APP_URL . '/panel/letters.php', 'icon' => 'bi-archive',
        'iconColor' => '#0176D3', 'iconBg' => '#EFF6FF',
        'label' => 'Pisma', 'count' => $my_letters_count, 'sub' => 'korespondencja',
    ];
}
if (module_enabled('terminations_enabled') && count($my_terms)) {
    $_pv_actions[] = [
        'href' => APP_URL . '/panel/terminations.php', 'icon' => 'bi-file-earmark-x',
        'iconColor' => '#DC2626', 'iconBg' => '#FEF2F2',
        'label' => 'Zakończ współpracę', 'count' => count($my_terms),
        'sub' => 'wnioski złożone', 'badge' => $my_terms_pending, 'badgeClass' => 'warning',
    ];
}
$_pv_actions[] = [
    'href' => APP_URL . '/panel/helpdesk.php', 'icon' => 'bi-headset',
    'iconColor' => 'var(--vol-color)', 'iconBg' => '#F0F4FF',
    'label' => 'Helpdesk IT', 'count' => $_hd_my_open ?: 0,
    'sub' => $_hd_my_open ? "{$_hd_my_open} otwartych" : 'zgłoś problem',
    'badge' => $_hd_my_open, 'badgeClass' => 'danger',
];
?>
<!-- ═══ SZYBKIE AKCJE — duże karty ══════════════════════════════════════════ -->
<div class="pv-stats-bar" role="list" aria-label="Podsumowanie">
  <?php if ($msg_unread): ?>
  <a href="<?= APP_URL ?>/panel/messages.php" class="pv-stat-pill" role="listitem"
     aria-label="<?= $msg_unread ?> nieprzeczytanych wiadomości">
    <i class="bi bi-chat-left-text" aria-hidden="true" style="color:var(--vol-color)"></i>
    <span class="pv-stat-num"><?= $msg_unread ?></span>
    <span>nowych</span>
  </a>
  <?php endif; ?>
  <?php if (count($my_apps) > 0): ?>
  <span class="pv-stat-pill" role="listitem">
    <i class="bi bi-send" aria-hidden="true" style="color:#7C3AED"></i>
    <span class="pv-stat-num"><?= count($my_apps) ?></span>
    <span><?= count($my_apps) === 1 ? 'wniosek' : 'wniosków' ?></span>
  </span>
  <?php endif; ?>
  <?php if (count($my_certs) > 0): ?>
  <span class="pv-stat-pill" role="listitem">
    <i class="bi bi-award" aria-hidden="true" style="color:#D97706"></i>
    <span class="pv-stat-num"><?= count($my_certs) ?></span>
    <span><?= count($my_certs) === 1 ? 'zaświadczenie' : 'zaświadczeń' ?></span>
  </span>
  <?php endif; ?>
</div>
<?php include __DIR__ . "/includes/pv_action_hub.php"; ?>

<!-- ── Canva Pro — prośba o dostęp ──────────────────────────────────────────── -->
<?php
$_canva_contract_id = (int)(($_active_contract['id'] ?? 0));
$_canva_row = null;
if ($_canva_contract_id && ($_active_contract['contract_type'] ?? '') === 'wolontariat') {
    try {
        $_canva_row = db_one(
            "SELECT canva_access, canva_invited_at, canva_access_requested_at FROM umowy_wolontariat WHERE id=?",
            [$_canva_contract_id]
        );
    } catch (\Throwable $e) {}
}
$_canva_access     = !empty($_canva_row['canva_access']);
$_canva_invited    = !empty($_canva_row['canva_invited_at']);
$_canva_requested  = !empty($_canva_row['canva_access_requested_at']);
// Formularz pokazujemy tylko dla aktywnej umowy wolontariatu.
if (!$_canva_invited && !$_canva_requested
    && !($_canva_contract_id && ($_active_contract['contract_type'] ?? '') === 'wolontariat')) {
    $_canva_contract_id = 0;
}
include __DIR__ . '/includes/pv_canva_card.php';
?>

<!-- ═══ PANEL AKTYWNOŚCI ════════════════════════════════════════════════════ -->
<?php if ($my_apps): ?>
<?php
$_pv_apps = array_map(fn($a) => [
    'tytul' => $a['tytul'], 'type_label' => $a['type_label'] ?? '', 'type_icon' => $a['type_icon'] ?? 'bi-file-text',
    'status' => $a['status'], 'created_at' => $a['created_at'], 'odpowiedz' => $a['odpowiedz'] ?? '',
], $my_apps);
include __DIR__ . '/includes/pv_apps_activity.php';
?>
<?php elseif ($contracts): ?>
<!-- CTA jeśli brak wniosków -->
<div style="background:#fff;border:2px dashed #E5E7EB;border-radius:12px;text-align:center;padding:2rem 1rem">
  <i class="bi bi-send" style="font-size:2rem;color:var(--vol-color);opacity:.5;display:block;margin-bottom:.75rem" aria-hidden="true"></i>
  <p class="fw-semibold mb-1">Masz pytanie lub prośbę?</p>
  <p class="text-muted small mb-3" id="pvp-cta-desc">Złóż wniosek lub wyślij pismo bezpośrednio do organizacji.</p>
  <a href="<?= APP_URL ?>/panel/apply.php"
     class="btn"
     style="background:var(--vol-color);color:#fff;border-radius:8px;font-weight:600"
     aria-describedby="pvp-cta-desc">
    <i class="bi bi-send me-2" aria-hidden="true"></i>Wyślij pismo / złóż wniosek
  </a>
</div>
<?php endif; ?>

<?php endif; // $contracts ?>

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

<!-- ── Zadania wolontariusza ──────────────────────────────────────────────── -->
<?php
$_tasks_panel_enabled = false;
try {
    $_tm = db_one("SELECT value FROM settings WHERE key_='tasks_enabled'");
    $_tasks_panel_enabled = ($_tm['value'] ?? '1') !== '0';
} catch (\Throwable $e) {}

if ($_tasks_panel_enabled):
    require_once dirname(__DIR__) . '/includes/tasks.php';
    require_once dirname(__DIR__) . '/includes/messages.php';

    $uid_panel = (int)$user['id'];

    // Moje zadania — przypisane, nieukończone
    $_my_tasks = db_all(
        "SELECT t.id, t.title, t.priority, t.due_date,
                tl.name AS list_name, tl.color AS list_color,
                tw.name AS ws_name, tw.color AS ws_color
         FROM tasks t
         JOIN task_assignments ta ON ta.task_id = t.id
         JOIN task_lists tl ON tl.id = t.list_id
         JOIN task_workspaces tw ON tw.id = t.workspace_id
         WHERE ta.user_id = ? AND t.completed_at IS NULL AND t.deleted_at IS NULL
         ORDER BY t.priority DESC, t.due_date ASC NULLS LAST
         LIMIT 10",
        [$uid_panel]
    );

    // Dostępne zadania — BEZ wymogu workspace membership (wolontariusz może wziąć każde dostępne)
    $_open_tasks_total = (int)(db_one(
        "SELECT COUNT(*) AS c FROM tasks t
         JOIN task_lists tl ON tl.id = t.list_id
         JOIN task_workspaces tw ON tw.id = t.workspace_id AND tw.is_active = 1
         WHERE t.deleted_at IS NULL AND t.completed_at IS NULL
           AND tl.is_done_state = 0
           AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) = 0",
        []
    )['c'] ?? 0);
    $_open_tasks = db_all(
        "SELECT t.id, t.title, t.priority, t.due_date,
                tl.name AS list_name, tw.name AS ws_name, tw.color AS ws_color
         FROM tasks t
         JOIN task_lists tl ON tl.id = t.list_id
         JOIN task_workspaces tw ON tw.id = t.workspace_id AND tw.is_active = 1
         WHERE t.deleted_at IS NULL AND t.completed_at IS NULL
           AND tl.is_done_state = 0
           AND (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) = 0
         ORDER BY t.priority DESC, t.due_date ASC NULLS LAST
         LIMIT 5",
        []
    );

    // Nieprzeczytane wiadomości zadaniowe
    $_task_inbox_unread = task_msg_unread($uid_panel);

    $pri_colors = [4=>'#dc2626',3=>'#f59e0b',2=>'#3b82f6',1=>'#94a3b8'];
    $csrf_panel = csrf_token();
?>
<?php
// ── Dane dla wyspy React (panel/includes/pv_tasks_panel.php) ─────────────────
$_pv_tasks_mine = array_map(fn($t) => [
    'id' => (int)$t['id'], 'title' => $t['title'], 'priority' => (int)$t['priority'],
    'due_date' => $t['due_date'], 'ws_name' => $t['ws_name'], 'list_name' => $t['list_name'],
    'ws_color' => $t['ws_color'],
], $_my_tasks);
$_pv_tasks_open = array_map(fn($t) => [
    'id' => (int)$t['id'], 'title' => $t['title'], 'priority' => (int)$t['priority'],
    'due_date' => $t['due_date'], 'ws_name' => $t['ws_name'], 'ws_color' => $t['ws_color'],
], $_open_tasks);
include __DIR__ . '/includes/pv_tasks_panel.php';
?>
<?php endif; /* tasks_panel_enabled */ ?>

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

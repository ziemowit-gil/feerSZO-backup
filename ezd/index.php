<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';
require_once dirname(__DIR__) . '/includes/pelnomocnictwa.php';
require_once dirname(__DIR__) . '/includes/ezd_client.php';
if (file_exists(dirname(__DIR__) . '/includes/notifications.php')) {
    require_once dirname(__DIR__) . '/includes/notifications.php';
}

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$PAGE_TITLE = 'Wirtualne biurko';
$stats      = ezd_stats();
$rpw_stats  = ezd_rpw_stats();
$arch_stats = ezd_arch_stats();
$user_id    = (int)current_user()['id'];
$user       = current_user();

// Ostatnie koszulki
$recent_sprawy = ezd_sprawy_all(['status' => ''], $user_id);
$recent_sprawy = array_slice(array_filter($recent_sprawy, fn($s) => $s['status'] !== 'closed'), 0, 8);

// Moje przekazania (oczekujące)
$my_dekr = db_all(
    "SELECT d.*, s.znak_sprawy, s.title AS sprawa_title, z.name AS zlec_name
     FROM ezd_dekretacje d
     JOIN ezd_sprawy s ON s.id=d.sprawa_id
     LEFT JOIN users z ON z.id=d.zlecajacy_id
     WHERE d.wykonawca_id=? AND d.status='oczekuje'
     ORDER BY d.deadline ASC, d.created_at DESC LIMIT 10",
    [$user_id]
);

// Moje dokumenty do podpisu + czekające na potwierdzenie
$my_sign_pending  = ezd_sign_requests_pending_for_user($user_id);
$my_sign_awaiting = ezd_sign_requests_awaiting_confirm($user_id);

// Moje koszulki
$my_sprawy = db_all(
    "SELECT s.*, t.symbol AS teczka_symbol,
            (s.owner_id!=? AND s.created_by!=?) AS is_shared
     FROM ezd_sprawy s
     JOIN ezd_teczki t ON t.id=s.teczka_id
     WHERE s.status!='closed' AND (s.owner_id=? OR s.created_by=?
           OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))
     ORDER BY s.deadline ASC LIMIT 8",
    [$user_id, $user_id, $user_id, $user_id, $user_id]
);

// Moje pisma
$my_pisma = db_all(
    "SELECT p.*, s.znak_sprawy FROM ezd_pisma p
     JOIN ezd_sprawy s ON s.id=p.sprawa_id
     WHERE p.owner_id=? ORDER BY p.updated_at DESC LIMIT 8",
    [$user_id]
);

// Nowe pisma w jednostce (wszystkie przychodzące ≤ 14 dni LUB status=nowe)
$nowe_pisma_jedn = [];
try {
    $nowe_pisma_jedn = db_all(
        "SELECT p.*, s.znak_sprawy, s.title AS sprawa_title
         FROM ezd_pisma p
         JOIN ezd_sprawy s ON s.id=p.sprawa_id
         WHERE p.kierunek='przychodzace'
           AND (p.status='nowe' OR p.created_at >= date('now','-14 days'))
         ORDER BY p.created_at DESC LIMIT 12"
    );
} catch (\Throwable $e) { $nowe_pisma_jedn = []; }
$nowe_pisma_jedn_cnt = count($nowe_pisma_jedn);

$my_sprawy_cnt = (int)(db_one(
    "SELECT COUNT(*) c FROM ezd_sprawy s WHERE s.status!='closed' AND (s.owner_id=? OR s.created_by=?
     OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))",
    [$user_id, $user_id, $user_id]
)['c'] ?? 0);
$my_pisma_cnt = (int)(db_one("SELECT COUNT(*) c FROM ezd_pisma WHERE owner_id=?", [$user_id])['c'] ?? 0);

// ── Poczta EZD na pulpicie ───────────────────────────────────────────────────
// Ten sam serwis co Inbox Ogólny (ezd/poczta/index.php), żeby pulpit nie liczył
// wiadomości po swojemu i nie rozjechał się z modułem poczty.
$ezd_mail        = [];
$ezd_mail_unread = 0;
$ezd_mail_on     = module_enabled('poczta_enabled');
if ($ezd_mail_on) {
    try {
        require_once dirname(__DIR__) . '/includes/ezd_mail.php';
        $_svc = new EzdMailService();
        // Najpierw nieprzeczytane — na pulpicie liczy się to, co czeka na reakcję.
        $ezd_mail = $_svc->getInbox(['status' => 'unread', 'per_page' => 10])['rows'] ?? [];
        $ezd_mail_unread = count($ezd_mail);
        if (!$ezd_mail) {
            $ezd_mail = $_svc->getInbox(['status' => 'active', 'per_page' => 10])['rows'] ?? [];
        }
    } catch (\Throwable $e) {
        // Brak konfiguracji skrzynek nie może wywalić pulpitu.
        $ezd_mail = []; $ezd_mail_unread = 0;
    }
}

// Ostatnia aktywność
$activity = db_all(
    "SELECT l.*, u.name AS user_name, s.znak_sprawy
     FROM ezd_log l
     LEFT JOIN users u ON u.id=l.user_id
     LEFT JOIN ezd_sprawy s ON s.id=l.sprawa_id
     ORDER BY l.created_at DESC LIMIT 10"
);

// Komu przekazana
$dekr_map = [];
try {
    $open_dekr = db_all(
        "SELECT d.sprawa_id, d.wykonawca_id, u.name AS wykonawca_name
         FROM ezd_dekretacje d JOIN users u ON u.id=d.wykonawca_id
         WHERE d.status='oczekuje' AND d.sprawa_id IS NOT NULL
         ORDER BY d.created_at ASC"
    );
    $workload = [];
    foreach ($open_dekr as $d) $workload[(int)$d['wykonawca_id']][(int)$d['sprawa_id']] = true;
    foreach ($open_dekr as $d) {
        $dekr_map[(int)$d['sprawa_id']] = [
            'name'  => $d['wykonawca_name'],
            'count' => count($workload[(int)$d['wykonawca_id']]),
        ];
    }
} catch (\Throwable $e) {}

// Donut: DO OBSŁUGI breakdown
$dekr_total   = count($my_dekr);
$dekr_po_term = count(array_filter($my_dekr, fn($d) => !empty($d['deadline']) && $d['deadline'] < date('Y-m-d')));
$dekr_dzis    = count(array_filter($my_dekr, fn($d) => !empty($d['deadline']) && $d['deadline'] === date('Y-m-d')));
$dekr_inne    = max(0, $dekr_total - $dekr_po_term - $dekr_dzis);

// Donut: KOSZULKI breakdown
$my_sprawy_po_term_cnt = (int)(db_one(
    "SELECT COUNT(*) c FROM ezd_sprawy s WHERE s.status!='closed' AND s.deadline IS NOT NULL AND s.deadline < date('now') AND COALESCE(s.ciagla,0)=0 AND (s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))",
    [$user_id, $user_id, $user_id]
)['c'] ?? 0);
$my_sprawy_ciagle_cnt = (int)(db_one(
    "SELECT COUNT(*) c FROM ezd_sprawy s WHERE s.status!='closed' AND COALESCE(s.ciagla,0)=1 AND (s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))",
    [$user_id, $user_id, $user_id]
)['c'] ?? 0);
$my_sprawy_w_toku_cnt = max(0, $my_sprawy_cnt - $my_sprawy_po_term_cnt - $my_sprawy_ciagle_cnt);

// Komunikaty (ważne)
$komunikaty = [];
if (function_exists('ann_list_for_user')) {
    try { $komunikaty = array_slice(ann_list_for_user($user_id, $user['role'] ?? '', ''), 0, 5); } catch(\Throwable $e) {}
}

// Zastępstwa (rejestr pełnomocnictw — moduł samodzielny, poza EZD)
$cur_name = $user['name'] ?? '';
$zastepuje = []; $zastepuje_mnie = [];
try {
    if ($cur_name && module_enabled('pelnomocnictwa_enabled')) {
        $wazne          = pelnomocnictwa_all(['status' => 'wazne']);
        $zastepuje      = array_slice(array_values(array_filter($wazne, fn($r) => $r['mocodawca']   === $cur_name)), 0, 5);
        // Pełnomocnictwa udzielone bieżącemu użytkownikowi — po powiązaniu konta LUB nazwisku.
        $zastepuje_mnie = function_exists('pelnomocnictwa_for_user')
            ? array_slice(pelnomocnictwa_for_user($user_id, $cur_name, true), 0, 5)
            : array_slice(array_values(array_filter($wazne, fn($r) => $r['pelnomocnik'] === $cur_name)), 0, 5);
    }
} catch(\Throwable $e) {}

// Przydatne linki
$przydatne_linki = json_decode(org_setting('ezd_przydatne_linki') ?: '[]', true) ?: [];

// Książka nadawcza (RPW-W)
$rpwy_stats = ['do_nadania'=>0,'oczek_zpo'=>0,'zwroty'=>0,'dzis'=>0,'rok'=>0,'koszt_rok'=>0.0];
try { if (function_exists('ezd_rpwy_stats')) $rpwy_stats = ezd_rpwy_stats(); } catch(\Throwable $e) {}

// eDoręczenia
$ede_ade   = org_setting('ezd_ede_ade')   ?: 'AE:PL-70366-85524-UJBAB-23';
$ede_email = org_setting('ezd_ede_email') ?: 'fundacja@feer.org.pl';
$ede_phone = org_setting('ezd_ede_phone') ?: '601 350 487';

// ═══════════════════════ MODEL PULPITU (sekcje: Do działania / W toku / Zakończone / Profil i podpis) ═══════════════
require_once dirname(__DIR__) . '/modules/ezd_sign_profile/logic/ezdSignProfile.php';

// Podpisy: liczniki wg statusu (wnioski, w których uczestniczę) + zakończone do sekcji „Zakończone”
$sign_cnt = ['oczekuje' => 0, 'podpisane' => 0, 'potwierdzone' => 0, 'odrzucone' => 0, 'anulowane' => 0];
try {
    foreach (db_all("SELECT status, COUNT(*) c FROM ezd_sign_requests WHERE requested_to=? OR requested_by=? GROUP BY status", [$user_id, $user_id]) as $r) {
        $sign_cnt[(string)$r['status']] = (int)$r['c'];
    }
} catch (\Throwable $e) {}
$sign_done = [];
try {
    $sign_done = db_all(
        "SELECT r.*, z.original_name AS zal_name, s.znak_sprawy, s.title AS sprawa_title, ub.name AS requested_by_name, ut.name AS requested_to_name
         FROM ezd_sign_requests r
         LEFT JOIN ezd_zalaczniki z ON z.id=r.zal_id
         LEFT JOIN ezd_sprawy s ON s.id=r.sprawa_id
         LEFT JOIN users ub ON ub.id=r.requested_by
         LEFT JOIN users ut ON ut.id=r.requested_to
         WHERE (r.requested_to=? OR r.requested_by=?) AND r.status IN ('potwierdzone','odrzucone','anulowane')
         ORDER BY COALESCE(r.confirmed_at, r.signed_at, r.requested_at) DESC LIMIT 8", [$user_id, $user_id]);
} catch (\Throwable $e) {}

// Zakończone koszulki, w których uczestniczę
$closed_sprawy = db_all(
    "SELECT s.*, t.symbol AS teczka_symbol FROM ezd_sprawy s JOIN ezd_teczki t ON t.id=s.teczka_id
     WHERE s.status='closed' AND (s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))
     ORDER BY COALESCE(s.closed_at, s.updated_at) DESC LIMIT 8", [$user_id, $user_id, $user_id]);
$closed_cnt = (int)(db_one(
    "SELECT COUNT(*) c FROM ezd_sprawy s WHERE s.status='closed' AND (s.owner_id=? OR s.created_by=? OR EXISTS (SELECT 1 FROM ezd_sprawa_users su WHERE su.sprawa_id=s.id AND su.user_id=?))",
    [$user_id, $user_id, $user_id])['c'] ?? 0);

// Stepper (etapy obiegu wg workflow JRWA) + ostatni PDF do szybkiego podglądu — dla moich koszulek
$case_steps = []; $case_pdf = [];
foreach ($my_sprawy as $s) {
    $steps = ezd_sprawa_workflow($s);
    $keys  = array_column($steps, 'key');
    $cur   = array_search($s['etap'] ?: ($keys[0] ?? ''), $keys, true);
    $case_steps[(int)$s['id']] = ['steps' => $steps, 'cur' => $cur === false ? -1 : $cur];
    $z = db_one("SELECT id, original_name FROM ezd_zalaczniki WHERE sprawa_id=? AND (mime_type='application/pdf' OR lower(original_name) LIKE '%.pdf') ORDER BY id DESC LIMIT 1", [(int)$s['id']]);
    if ($z) $case_pdf[(int)$s['id']] = $z;
}

// „Do działania”: jedna lista z przekazań (dekretacji), wniosków o mój podpis i podpisanych dokumentów do mojego potwierdzenia
$DYSP_KIND = ['do_podpisu' => 'sign', 'do_akcept' => 'accept', 'do_wiadom' => 'info', 'do_zalat' => 'do', 'do_realizacji' => 'do'];
$KIND_META = [
    'sign'    => ['label' => 'Do podpisu',       'cls' => 'ezx-badge--amber',   'icon' => 'bi-pen'],
    'accept'  => ['label' => 'Do akceptacji',    'cls' => 'ezx-badge--violet',  'icon' => 'bi-check2-square'],
    'confirm' => ['label' => 'Do potwierdzenia', 'cls' => 'ezx-badge--emerald', 'icon' => 'bi-patch-check'],
    'info'    => ['label' => 'Do wglądu',        'cls' => 'ezx-badge--sky',     'icon' => 'bi-eye'],
    'do'      => ['label' => 'Do załatwienia',   'cls' => 'ezx-badge--blue',    'icon' => 'bi-list-task'],
];
$today = date('Y-m-d');
$actions = [];
foreach ($my_dekr as $d) {
    $kind = $DYSP_KIND[$d['dyspozycja']] ?? 'do';
    $actions[] = ['type' => 'dekr', 'kind' => $kind, 'urgent' => !empty($d['deadline']) && $d['deadline'] < $today,
                  'today' => !empty($d['deadline']) && $d['deadline'] === $today,
                  'title' => (string)$d['sprawa_title'], 'znak' => (string)$d['znak_sprawy'], 'from' => (string)($d['zlec_name'] ?? ''),
                  'note' => (string)($d['tresc'] ?? ''), 'deadline' => (string)($d['deadline'] ?? ''), 'when' => (string)$d['created_at'],
                  'href' => APP_URL . '/ezd/sprawy/view.php?id=' . (int)$d['sprawa_id'] . '#dekretacje', 'dekr_id' => (int)$d['id'],
                  'sprawa_id' => (int)$d['sprawa_id'], 'zal_id' => (int)($case_pdf[(int)$d['sprawa_id']]['id'] ?? 0),
                  'zal_name' => (string)($case_pdf[(int)$d['sprawa_id']]['original_name'] ?? ''), 'dysp' => (string)(EZD_DYSPOZYCJE[$d['dyspozycja']] ?? $d['dyspozycja'])];
}
foreach ($my_sign_pending as $sr) {
    $actions[] = ['type' => 'sign', 'kind' => 'sign', 'urgent' => false, 'today' => false,
                  'title' => (string)$sr['zal_name'], 'znak' => (string)($sr['znak_sprawy'] ?? ''), 'from' => (string)($sr['requested_by_name'] ?? ''),
                  'note' => (string)($sr['notes'] ?? ''), 'deadline' => '', 'when' => (string)$sr['requested_at'],
                  'href' => APP_URL . '/ezd/podpis/view.php?id=' . (int)$sr['id'], 'req_id' => (int)$sr['id'], 'zal_id' => (int)$sr['zal_id'],
                  'zal_name' => (string)$sr['zal_name'], 'sprawa_id' => (int)$sr['sprawa_id'], 'dysp' => 'Prośba o podpis'];
}
foreach ($my_sign_awaiting as $sr) {
    $actions[] = ['type' => 'confirm', 'kind' => 'confirm', 'urgent' => false, 'today' => false,
                  'title' => (string)$sr['zal_name'], 'znak' => (string)($sr['znak_sprawy'] ?? ''), 'from' => (string)($sr['requested_to_name'] ?? ''),
                  'note' => '', 'deadline' => '', 'when' => (string)($sr['signed_at'] ?? $sr['requested_at']),
                  'href' => APP_URL . '/ezd/podpis/view.php?id=' . (int)$sr['id'], 'req_id' => (int)$sr['id'], 'zal_id' => (int)($sr['signed_zal_id'] ?: $sr['zal_id']),
                  'zal_name' => (string)$sr['zal_name'], 'sprawa_id' => (int)$sr['sprawa_id'], 'dysp' => 'Podpisany — potwierdź odbiór'];
}
usort($actions, function ($a, $b) {
    if ($a['urgent'] !== $b['urgent']) return $a['urgent'] ? -1 : 1;
    $da = $a['deadline'] ?: '9999-99-99'; $db = $b['deadline'] ?: '9999-99-99';
    if ($da !== $db) return strcmp($da, $db);
    return strcmp($b['when'], $a['when']);
});
$act_cnt = ['all' => count($actions), 'urgent' => 0, 'sign' => 0, 'accept' => 0, 'confirm' => 0, 'info' => 0, 'do' => 0];
foreach ($actions as $a) { $act_cnt[$a['kind']]++; if ($a['urgent']) $act_cnt['urgent']++; }

// Profil i podpis
$sign_profile = EzdSignProfile::current($user_id);
$sign_stats   = EzdSignProfile::stats($user_id);
$rsign_port   = (int)(org_setting('ezd_rsign_port') ?: 7778);
$role_row     = null;
try { $role_row = db_one("SELECT display_name FROM roles WHERE name=?", [(string)($user['role'] ?? '')]); } catch (\Throwable $e) {}
$role_label   = (string)($role_row['display_name'] ?? ucfirst((string)($user['role'] ?? '')));
$ezd_access   = is_admin() ? 'Administrator' : (can_write('ezd') ? 'Operator — pełna edycja' : 'Tylko odczyt');
$user_init    = mb_strtoupper(mb_substr((string)($user['name'] ?? 'U'), 0, 1));
if (preg_match('/\s(\S)/u', (string)($user['name'] ?? ''), $__m)) $user_init .= mb_strtoupper($__m[1]);
$hour  = (int)date('G');
$greet = $hour < 5 ? 'Dobrej nocy' : ($hour < 12 ? 'Dzień dobry' : ($hour < 18 ? 'Dzień dobry' : 'Dobry wieczór'));
$first_name = explode(' ', trim((string)($user['name'] ?? '')))[0] ?? '';
$DNI = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
$MIES = [1 => 'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca', 'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia'];
$today_label = $DNI[(int)date('w')] . ', ' . (int)date('j') . ' ' . $MIES[(int)date('n')] . ' ' . date('Y');

$SIGN_BADGE = [
    'oczekuje'     => ['Do podpisu',  'ezx-badge--amber'],
    'podpisane'    => ['Podpisany',   'ezx-badge--emerald'],
    'potwierdzone' => ['Podpisany ✓', 'ezx-badge--emerald'],
    'odrzucone'    => ['Odrzucony',   'ezx-badge--rose'],
    'anulowane'    => ['Anulowany',   'ezx-badge--slate'],
];
$qa = [
    ['href' => APP_URL . '/ezd/sprawy/add.php',          'icon' => 'bi-folder-plus',       'tone' => 'blue',    'lbl' => 'Nowa koszulka'],
    ['href' => APP_URL . '/ezd/pisma/add.php',           'icon' => 'bi-envelope-plus',     'tone' => 'cyan',    'lbl' => 'Nowe pismo'],
    ['href' => APP_URL . '/ezd/pisma/generator.php',     'icon' => 'bi-file-earmark-word', 'tone' => 'indigo',  'lbl' => 'Nowy dokument'],
    ['href' => APP_URL . '/ezd/rpw/index.php',           'icon' => 'bi-inbox-fill',        'tone' => 'amber',   'lbl' => 'Dziennik (RPW)', 'badge' => (int)$rpw_stats['koszulka']],
    ['href' => APP_URL . '/ezd/rpwy/index.php',          'icon' => 'bi-send',              'tone' => 'blue',    'lbl' => 'Książka nadawcza', 'badge' => (int)$rpwy_stats['do_nadania']],
    ['modal' => '#ezdEDoreczeniaModal',                   'icon' => 'bi-envelope-paper',    'tone' => 'sky',     'lbl' => 'eDoręczenia'],
    ['href' => APP_URL . '/ezd/zaswiadczenia/index.php', 'icon' => 'bi-award',             'tone' => 'violet',  'lbl' => 'Zaświadczenia'],
    ['href' => APP_URL . '/ezd/terminarz.php',           'icon' => 'bi-calendar-check',    'tone' => 'teal',    'lbl' => 'Terminarz'],
    ['href' => APP_URL . '/ezd/podpis/index.php',        'icon' => 'bi-pen',               'tone' => 'amber',   'lbl' => 'Do podpisu', 'badge' => count($my_sign_pending)],
];
// Rejestr numerów i kodów QR plików — tylko administrator i zarząd (ezd/rejestrDokumentow.php sprawdza to samo)
if (is_admin() || (function_exists('is_zarzad') && is_zarzad())) {
    $qa[] = ['href' => APP_URL . '/ezd/rejestrDokumentow.php', 'icon' => 'bi-qr-code', 'tone' => 'slate', 'lbl' => 'Rejestr dokumentów'];
}
$alpine_cfg = [
    'serve'  => APP_URL . '/ezd/serve.php?id=',
    'sign'   => APP_URL . '/ezd/sign.php',
    'podpis' => APP_URL . '/ezd/podpis/view.php?id=',
    'port'   => $rsign_port,
    'sections' => ['dzialanie', 'wtoku', 'zakonczone', 'profil'],
];

include dirname(__DIR__) . '/includes/header.php';
?>

<style type="text/tailwindcss">
/* ── Pulpit EZD: komponenty kaflowe (Tailwind bez preflightu — przy tw-border zawsze tw-border-solid) ── */
.ezx-card     { @apply tw-bg-white tw-rounded-2xl tw-border tw-border-solid tw-border-slate-200 tw-shadow-sm tw-min-w-0 tw-max-w-full tw-overflow-hidden; }
.ezx-card-hd  { @apply tw-flex tw-items-center tw-gap-2 tw-px-4 tw-py-3 tw-border-b tw-border-solid tw-border-slate-100; }
.ezx-h2       { @apply tw-m-0 tw-text-[.9rem] tw-font-semibold tw-text-slate-900 tw-tracking-tight; }
.ezx-eyebrow  { @apply tw-text-[.64rem] tw-font-bold tw-uppercase tw-tracking-[.1em] tw-text-slate-500; }
.ezx-muted    { @apply tw-text-xs tw-text-slate-500; }
.ezx-more     { @apply tw-text-xs tw-font-semibold tw-text-blue-700 tw-no-underline hover:tw-underline; }
.ezx-btn      { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-1.5 tw-rounded-lg tw-bg-blue-600 tw-text-white tw-px-3 tw-py-1.5 tw-text-xs tw-font-semibold tw-no-underline tw-border-0 tw-cursor-pointer tw-transition tw-shadow-sm hover:tw-bg-blue-700 hover:tw-text-white focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-offset-2 focus-visible:tw-outline-amber-400 disabled:tw-opacity-50 disabled:tw-cursor-not-allowed; }
.ezx-btn--ghost  { @apply tw-bg-white tw-text-slate-700 tw-border tw-border-solid tw-border-slate-300 tw-shadow-none hover:tw-bg-slate-50 hover:tw-text-slate-900 hover:tw-border-slate-400; }
.ezx-btn--amber  { @apply tw-bg-amber-500 hover:tw-bg-amber-600; }
.ezx-btn--emerald{ @apply tw-bg-emerald-600 hover:tw-bg-emerald-700; }
.ezx-btn--danger { @apply tw-bg-white tw-text-rose-700 tw-border tw-border-solid tw-border-rose-200 tw-shadow-none hover:tw-bg-rose-50 hover:tw-text-rose-800; }
.ezx-badge    { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-full tw-px-2 tw-py-0.5 tw-text-[.66rem] tw-font-semibold tw-border tw-border-solid tw-whitespace-nowrap tw-leading-tight; }
.ezx-badge--amber  { @apply tw-bg-amber-50 tw-text-amber-700 tw-border-amber-200; }
.ezx-badge--violet { @apply tw-bg-violet-50 tw-text-violet-700 tw-border-violet-200; }
.ezx-badge--emerald{ @apply tw-bg-emerald-50 tw-text-emerald-700 tw-border-emerald-200; }
.ezx-badge--sky    { @apply tw-bg-sky-50 tw-text-sky-700 tw-border-sky-200; }
.ezx-badge--blue   { @apply tw-bg-blue-50 tw-text-blue-700 tw-border-blue-200; }
.ezx-badge--rose   { @apply tw-bg-rose-50 tw-text-rose-700 tw-border-rose-200; }
.ezx-badge--slate  { @apply tw-bg-slate-100 tw-text-slate-600 tw-border-slate-200; }
.ezx-badge--urgent { @apply tw-bg-rose-600 tw-text-white tw-border-rose-600; }
.ezx-kpi      { @apply tw-flex tw-flex-col tw-gap-1 tw-rounded-2xl tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-p-3.5 tw-text-left tw-cursor-pointer tw-transition tw-shadow-sm hover:tw-border-blue-300 hover:tw-shadow-md focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-amber-400; }
.ezx-kpi-num  { @apply tw-text-[1.6rem] tw-font-bold tw-leading-none tw-text-slate-900 tw-tracking-tight; }
.ezx-tab      { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-lg tw-px-3 tw-py-2 tw-text-[.8rem] tw-font-medium tw-text-slate-600 tw-bg-transparent tw-border-0 tw-cursor-pointer tw-whitespace-nowrap tw-transition hover:tw-bg-slate-100 hover:tw-text-slate-900 focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-amber-400; }
.ezx-tab[aria-selected="true"] { @apply tw-bg-slate-900 tw-text-white tw-shadow-sm hover:tw-bg-slate-900 hover:tw-text-white; }
.ezx-chip     { @apply tw-inline-flex tw-items-center tw-gap-1 tw-rounded-full tw-px-2.5 tw-py-1 tw-text-[.72rem] tw-font-medium tw-text-slate-600 tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-cursor-pointer tw-transition hover:tw-border-slate-400 hover:tw-text-slate-900; }
.ezx-chip[aria-pressed="true"] { @apply tw-bg-blue-600 tw-text-white tw-border-blue-600; }
.ezx-subtab   { @apply tw-inline-flex tw-items-center tw-gap-1.5 tw-px-3 tw-py-2 tw-text-[.78rem] tw-font-semibold tw-text-slate-500 tw-bg-transparent tw-border-0 tw-border-b-2 tw-border-solid tw-border-transparent tw-cursor-pointer tw-whitespace-nowrap tw--mb-px hover:tw-text-slate-900; }
.ezx-subtab[aria-selected="true"] { @apply tw-text-blue-700 tw-border-blue-600; }
.ezx-item     { @apply tw-flex tw-items-start tw-gap-3 tw-px-4 tw-py-3 tw-border-b tw-border-solid tw-border-slate-100 last:tw-border-b-0 tw-transition hover:tw-bg-slate-50; }
.ezx-item--urgent { @apply tw-bg-rose-50/40 hover:tw-bg-rose-50; }
.ezx-ico      { @apply tw-w-9 tw-h-9 tw-rounded-xl tw-flex tw-items-center tw-justify-center tw-text-base tw-shrink-0; }
.ezx-title    { @apply tw-text-[.85rem] tw-font-semibold tw-text-slate-900 tw-leading-snug tw-no-underline hover:tw-text-blue-700; }
.ezx-znak     { @apply tw-font-mono tw-text-[.68rem] tw-text-slate-500; }
.ezx-qa       { @apply tw-flex tw-flex-col tw-items-center tw-justify-center tw-gap-1.5 tw-rounded-xl tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-py-2.5 tw-px-1 tw-no-underline tw-text-slate-700 tw-cursor-pointer tw-transition hover:tw-border-blue-300 hover:tw-shadow-md hover:tw-text-blue-700 focus-visible:tw-outline focus-visible:tw-outline-[3px] focus-visible:tw-outline-amber-400; width: 100%; min-width: 0; }
.ezx-qa-ico   { @apply tw-relative tw-w-10 tw-h-10 tw-rounded-xl tw-flex tw-items-center tw-justify-center tw-text-lg; }
.ezx-qa-lbl   { @apply tw-text-[.64rem] tw-font-semibold tw-leading-tight tw-text-center tw-max-w-full tw-break-words; }
.ezx-qa-badge { @apply tw-absolute tw--top-1 tw--right-1 tw-min-w-[17px] tw-h-[17px] tw-px-1 tw-rounded-full tw-bg-rose-600 tw-text-white tw-text-[.55rem] tw-font-bold tw-flex tw-items-center tw-justify-center tw-border-2 tw-border-solid tw-border-white; }
/* Stepper obiegu koszulki (kafel) */
.ezx-steps    { @apply tw-flex tw-items-center tw-gap-1 tw-mt-2; }
.ezx-step     { @apply tw-flex-1 tw-h-1.5 tw-rounded-full tw-bg-slate-200; }
.ezx-step--done    { @apply tw-bg-emerald-500; }
.ezx-step--current { @apply tw-bg-blue-600; }
/* Panel podglądu + okno podpisu */
.ezx-overlay  { @apply tw-fixed tw-inset-0 tw-bg-slate-900/50 tw-z-[1040]; backdrop-filter: blur(2px); }
.ezx-drawer   { @apply tw-fixed tw-top-0 tw-right-0 tw-h-full tw-w-full md:tw-w-[720px] tw-bg-white tw-z-[1041] tw-flex tw-flex-col tw-shadow-2xl; }
.ezx-modal    { @apply tw-fixed tw-inset-0 tw-z-[1041] tw-flex tw-items-center tw-justify-center tw-p-4; }
.ezx-modal-box{ @apply tw-bg-white tw-rounded-2xl tw-shadow-2xl tw-w-full tw-max-w-lg tw-overflow-hidden; }
.ezx-kv       { @apply tw-rounded-xl tw-bg-slate-50 tw-border tw-border-solid tw-border-slate-100 tw-px-3 tw-py-2; }
.ezx-kv dt    { @apply tw-text-[.62rem] tw-font-semibold tw-uppercase tw-tracking-[.06em] tw-text-slate-500 tw-m-0; }
.ezx-kv dd    { @apply tw-m-0 tw-mt-0.5 tw-text-[.84rem] tw-font-semibold tw-text-slate-900 tw-break-words; }
.ezx-table th { @apply tw-text-[.64rem] tw-font-bold tw-uppercase tw-tracking-[.08em] tw-text-slate-500 tw-px-4 tw-py-2 tw-text-left tw-border-b tw-border-solid tw-border-slate-200 tw-bg-slate-50; }
.ezx-table td { @apply tw-px-4 tw-py-2.5 tw-text-[.8rem] tw-text-slate-700 tw-border-b tw-border-solid tw-border-slate-100 tw-align-middle; }
.ezx-table tr:last-child td { @apply tw-border-b-0; }
.ezx-table tr:hover td { @apply tw-bg-slate-50; }
/* Kalendarz (JS) */
.ezdd-cal-nav{display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem}
.ezdd-cal-nav button{background:none;border:1px solid #e2e8f0;border-radius:8px;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#64748b;font-size:.85rem}
.ezdd-cal-nav button:hover{background:#f1f5f9}
.ezdd-cal-month{font-weight:700;font-size:.85rem;color:#1e293b}
.ezdd-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.ezdd-cal-dow{text-align:center;font-size:.6rem;font-weight:700;text-transform:uppercase;color:#94a3b8;padding:.2rem 0}
.ezdd-cal-day{text-align:center;font-size:.75rem;padding:.3rem .1rem;border-radius:6px;color:#374151;cursor:default}
.ezdd-cal-today{background:#2563eb;color:#fff!important;font-weight:700;border-radius:50%;aspect-ratio:1;display:flex;align-items:center;justify-content:center;margin:auto}
</style>

<div x-data="ezdDash(<?= h(json_encode($alpine_cfg, JSON_UNESCAPED_SLASHES)) ?>)" x-cloak>

<!-- ═══════════ NAGŁÓWEK: powitanie + wyszukiwarka + akcje główne ═══════════ -->
<div class="tw-flex tw-flex-wrap tw-items-end tw-justify-between tw-gap-3 tw-mb-4">
  <div>
    <div class="ezx-eyebrow tw-mb-1"><?= h($today_label) ?></div>
    <h1 class="tw-m-0 tw-text-[1.4rem] tw-font-bold tw-tracking-tight tw-text-slate-900"><?= h($greet) ?><?= $first_name !== '' ? ', ' . h($first_name) : '' ?> 👋</h1>
    <p class="tw-m-0 tw-mt-0.5 tw-text-sm tw-text-slate-500">
      <?php if ($act_cnt['all']): ?>Czeka na Ciebie <strong class="tw-text-slate-800"><?= $act_cnt['all'] ?></strong> <?= $act_cnt['all'] === 1 ? 'sprawa do działania' : ($act_cnt['all'] < 5 ? 'sprawy do działania' : 'spraw do działania') ?><?= $act_cnt['urgent'] ? ', w tym <strong class="tw-text-rose-600">' . $act_cnt['urgent'] . ' po terminie</strong>' : '' ?>.<?php else: ?>Nic nie czeka na Twoje działanie — dobra robota.<?php endif; ?>
    </p>
  </div>
  <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
    <?php if (can_edit()): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/add.php" class="ezx-btn tw-py-2 tw-px-3.5 tw-text-sm"><i class="bi bi-folder-plus" aria-hidden="true"></i>Nowa koszulka</a>
    <a href="<?= APP_URL ?>/ezd/pisma/add.php" class="ezx-btn ezx-btn--ghost tw-py-2 tw-px-3.5 tw-text-sm"><i class="bi bi-envelope-plus" aria-hidden="true"></i>Nowe pismo</a>
    <?php endif; ?>
  </div>
</div>

<form method="get" action="<?= APP_URL ?>/ezd/szukaj.php" class="tw-mb-4" role="search">
  <label for="ezx-q" class="tw-sr-only">Szukaj w EZD</label>
  <div class="tw-flex tw-items-center tw-gap-2 tw-rounded-2xl tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-shadow-sm tw-pl-4 tw-pr-2 tw-py-1.5 focus-within:tw-border-blue-400 focus-within:tw-ring-4 focus-within:tw-ring-blue-100">
    <i class="bi bi-search tw-text-slate-400" aria-hidden="true"></i>
    <input id="ezx-q" type="search" name="q" required minlength="2" autocomplete="off" class="tw-flex-1 tw-border-0 tw-bg-transparent tw-py-1.5 tw-text-[.95rem] tw-text-slate-900 tw-outline-none tw-min-w-0"
           placeholder="Szukaj po znaku koszulki, numerze dokumentu lub tytule…">
    <button type="submit" class="ezx-btn tw-py-2 tw-px-4 tw-text-sm">Szukaj</button>
  </div>
</form>

<!-- ═══════════ KPI ═══════════ -->
<div class="mt-grid tw-mb-6" role="list" aria-label="Szybkie statystyki">
  <button type="button" class="mt-tile" style="--c:#c25100" role="listitem" @click="go('dzialanie'); filter = 'all'">
    <i class="bi bi-lightning-charge ic" aria-hidden="true"></i>
    <span>
      <span class="val"><?= $act_cnt['all'] ?></span>
      <span class="lbl">Do działania</span>
      <span class="sub"><?= $act_cnt['urgent'] ? '<span class="tw-text-rose-600 tw-font-semibold">' . $act_cnt['urgent'] . ' po terminie</span>' : 'wszystko w terminie' ?></span>
    </span>
  </button>
  <button type="button" class="mt-tile" style="--c:#a20025" role="listitem" @click="go('dzialanie'); filter = 'sign'">
    <i class="bi bi-pen ic" aria-hidden="true"></i>
    <span>
      <span class="val"><?= count($my_sign_pending) ?></span>
      <span class="lbl">Do podpisu</span>
      <span class="sub">dokumentów czeka na Twój podpis</span>
    </span>
  </button>
  <button type="button" class="mt-tile" style="--c:#007a00" role="listitem" @click="go('dzialanie'); filter = 'confirm'">
    <i class="bi bi-patch-check ic" aria-hidden="true"></i>
    <span>
      <span class="val"><?= count($my_sign_awaiting) ?></span>
      <span class="lbl">Do potwierdzenia</span>
      <span class="sub">podpisanych wraca do Ciebie</span>
    </span>
  </button>
  <button type="button" class="mt-tile" style="--c:#0f6fa8" role="listitem" @click="go('wtoku'); sub = 'cases'">
    <i class="bi bi-folder2-open ic" aria-hidden="true"></i>
    <span>
      <span class="val"><?= $my_sprawy_cnt ?></span>
      <span class="lbl">Moje koszulki</span>
      <span class="sub"><?= $my_sprawy_po_term_cnt ? '<span class="tw-text-rose-600 tw-font-semibold">' . $my_sprawy_po_term_cnt . ' po terminie</span> · ' : '' ?><?= $my_sprawy_ciagle_cnt ?> ciągłe</span>
    </span>
  </button>
  <button type="button" class="mt-tile" style="--c:#00807e" role="listitem" @click="go('wtoku'); sub = 'new'">
    <i class="bi bi-envelope-arrow-down ic" aria-hidden="true"></i>
    <span>
      <span class="val"><?= $nowe_pisma_jedn_cnt ?></span>
      <span class="lbl">Nowe pisma</span>
      <span class="sub">przychodzące z 14 dni</span>
    </span>
  </button>
  <button type="button" class="mt-tile" style="--c:#5f5f5f" role="listitem" @click="go('zakonczone')">
    <i class="bi bi-check2-circle ic" aria-hidden="true"></i>
    <span>
      <span class="val"><?= $closed_cnt + $sign_cnt['potwierdzone'] ?></span>
      <span class="lbl">Zakończone</span>
      <span class="sub"><?= $sign_cnt['potwierdzone'] ?> podpisanych<?= $sign_cnt['odrzucone'] ? ' · <span class="tw-text-rose-600 tw-font-semibold">' . $sign_cnt['odrzucone'] . ' odrzuc.</span>' : '' ?></span>
    </span>
  </button>
</div>

<div class="tw-grid tw-grid-cols-1 xl:tw-grid-cols-12 tw-gap-4">

  <!-- ═══════════ LEWA KOLUMNA: sekcje ═══════════ -->
  <div class="xl:tw-col-span-8 tw-min-w-0">

    <div class="tw-inline-flex tw-flex-wrap tw-gap-0.5 tw-p-1 tw-rounded-xl tw-bg-white tw-border tw-border-solid tw-border-slate-200 tw-shadow-sm tw-mb-3 tw-max-w-full" role="tablist" aria-label="Sekcje pulpitu">
      <button type="button" role="tab" id="tab-dzialanie" class="ezx-tab" :aria-selected="section === 'dzialanie' ? 'true' : 'false'" :tabindex="section === 'dzialanie' ? 0 : -1" aria-controls="sec-dzialanie" x-ref="tab-dzialanie" @click="go('dzialanie')" @keydown="key($event, 0)">
        <i class="bi bi-lightning-charge" aria-hidden="true"></i>Do działania
        <?php if ($act_cnt['all']): ?><span class="tw-ml-1 tw-rounded-full tw-bg-amber-500 tw-text-white tw-text-[.62rem] tw-font-bold tw-px-1.5 tw-py-0.5 tw-leading-none"><?= $act_cnt['all'] ?></span><?php endif; ?>
      </button>
      <button type="button" role="tab" id="tab-wtoku" class="ezx-tab" :aria-selected="section === 'wtoku' ? 'true' : 'false'" :tabindex="section === 'wtoku' ? 0 : -1" aria-controls="sec-wtoku" x-ref="tab-wtoku" @click="go('wtoku')" @keydown="key($event, 1)">
        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>W toku
      </button>
      <button type="button" role="tab" id="tab-zakonczone" class="ezx-tab" :aria-selected="section === 'zakonczone' ? 'true' : 'false'" :tabindex="section === 'zakonczone' ? 0 : -1" aria-controls="sec-zakonczone" x-ref="tab-zakonczone" @click="go('zakonczone')" @keydown="key($event, 2)">
        <i class="bi bi-check2-circle" aria-hidden="true"></i>Zakończone · Podpisane
      </button>
      <button type="button" role="tab" id="tab-profil" class="ezx-tab" :aria-selected="section === 'profil' ? 'true' : 'false'" :tabindex="section === 'profil' ? 0 : -1" aria-controls="sec-profil" x-ref="tab-profil" @click="go('profil')" @keydown="key($event, 3)">
        <i class="bi bi-person-badge" aria-hidden="true"></i>Profil i podpis
      </button>
    </div>

    <!-- ──────── DO DZIAŁANIA ──────── -->
    <section id="sec-dzialanie" role="tabpanel" aria-labelledby="tab-dzialanie" tabindex="-1" x-show="section === 'dzialanie'" x-transition.opacity.duration.150ms>
      <div class="ezx-card">
        <div class="ezx-card-hd tw-flex-wrap">
          <i class="bi bi-lightning-charge tw-text-amber-500" aria-hidden="true"></i>
          <h2 class="ezx-h2">Wymaga Twojego działania</h2>
          <div class="tw-ml-auto tw-flex tw-flex-wrap tw-gap-1" role="group" aria-label="Filtr">
            <?php $chips = [['all', 'Wszystkie', $act_cnt['all']], ['urgent', 'Pilne', $act_cnt['urgent']], ['sign', 'Do podpisu', $act_cnt['sign']], ['accept', 'Do akceptacji', $act_cnt['accept']], ['confirm', 'Do potwierdzenia', $act_cnt['confirm']], ['info', 'Do wglądu', $act_cnt['info']], ['do', 'Do załatwienia', $act_cnt['do']]];
            foreach ($chips as [$fk, $fl, $fc]): ?>
            <button type="button" class="ezx-chip" :aria-pressed="filter === '<?= $fk ?>' ? 'true' : 'false'" @click="filter = '<?= $fk ?>'"><?= $fl ?> <span class="tw-opacity-70"><?= $fc ?></span></button>
            <?php endforeach; ?>
          </div>
        </div>
        <?php if (!$actions): ?>
        <div class="tw-text-center tw-py-10 tw-px-4">
          <i class="bi bi-cup-hot tw-text-4xl tw-text-emerald-400 tw-block tw-mb-2" aria-hidden="true"></i>
          <div class="tw-font-semibold tw-text-slate-800">Brak spraw do działania</div>
          <div class="ezx-muted">Przekazania, prośby o podpis i podpisane dokumenty do potwierdzenia pojawią się tutaj.</div>
        </div>
        <?php else: ?>
        <div>
          <?php foreach ($actions as $a): $km = $KIND_META[$a['kind']]; ?>
          <article class="ezx-item<?= $a['urgent'] ? ' ezx-item--urgent' : '' ?>" x-show="filter === 'all' || filter === '<?= $a['kind'] ?>' || (filter === 'urgent' && <?= $a['urgent'] ? 'true' : 'false' ?>)">
            <span class="ezx-ico <?= $a['type'] === 'confirm' ? 'tw-bg-emerald-50 tw-text-emerald-700' : ($a['kind'] === 'sign' ? 'tw-bg-amber-50 tw-text-amber-700' : 'tw-bg-blue-50 tw-text-blue-700') ?>" aria-hidden="true">
              <i class="bi <?= $a['type'] === 'dekr' ? 'bi-folder2-open' : 'bi-file-earmark-pdf' ?>"></i>
            </span>
            <div class="tw-flex-1 tw-min-w-0">
              <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-1.5 tw-mb-0.5">
                <span class="ezx-badge <?= $km['cls'] ?>"><i class="bi <?= $km['icon'] ?>" aria-hidden="true"></i><?= h($km['label']) ?></span>
                <?php if ($a['urgent']): ?><span class="ezx-badge ezx-badge--urgent"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>Pilne — po terminie</span>
                <?php elseif ($a['today']): ?><span class="ezx-badge ezx-badge--amber"><i class="bi bi-clock" aria-hidden="true"></i>Na dziś</span><?php endif; ?>
                <?php if ($a['type'] === 'dekr' && $a['dysp'] !== $km['label']): ?><span class="ezx-muted"><?= h($a['dysp']) ?></span><?php endif; ?>
              </div>
              <a href="<?= h($a['href']) ?>" class="ezx-title tw-block tw-truncate"><?= h($a['title']) ?></a>
              <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-x-2 tw-gap-y-0.5 tw-mt-0.5 ezx-muted">
                <?php if ($a['znak'] !== ''): ?><span class="ezx-znak"><?= h($a['znak']) ?></span><?php endif; ?>
                <?php if ($a['from'] !== ''): ?><span><i class="bi bi-person tw-mr-0.5" aria-hidden="true"></i><?= $a['type'] === 'confirm' ? 'podpisał' : 'od' ?>: <?= h($a['from']) ?></span><?php endif; ?>
                <?php if ($a['deadline'] !== ''): ?><span class="<?= $a['urgent'] ? 'tw-text-rose-600 tw-font-semibold' : '' ?>"><i class="bi bi-calendar-event tw-mr-0.5" aria-hidden="true"></i>termin <?= date('d.m.Y', strtotime($a['deadline'])) ?></span><?php endif; ?>
                <?php if ($a['note'] !== ''): ?><span class="tw-truncate tw-max-w-[320px]" title="<?= h($a['note']) ?>">„<?= h(mb_strimwidth($a['note'], 0, 90, '…')) ?>”</span><?php endif; ?>
              </div>
            </div>
            <div class="tw-flex tw-items-center tw-gap-1.5 tw-shrink-0">
              <?php if ($a['zal_id']): ?>
              <button type="button" class="ezx-btn ezx-btn--ghost" @click="openPreview(<?= h(json_encode(['zal' => $a['zal_id'], 'name' => $a['zal_name'] ?: $a['title'], 'znak' => $a['znak'], 'ctx' => $a['type'], 'req' => $a['req_id'] ?? 0, 'from' => $a['from'], 'href' => $a['href'], 'title' => $a['title']], JSON_UNESCAPED_SLASHES)) ?>)" title="Szybki podgląd dokumentu"><i class="bi bi-eye" aria-hidden="true"></i><span class="tw-hidden sm:tw-inline">Podgląd</span></button>
              <?php endif; ?>
              <?php if ($a['type'] === 'sign'): ?>
              <button type="button" class="ezx-btn ezx-btn--amber" @click="startSign(<?= h(json_encode(['zal' => $a['zal_id'], 'name' => $a['zal_name'], 'znak' => $a['znak'], 'req' => $a['req_id'], 'from' => $a['from']], JSON_UNESCAPED_SLASHES)) ?>)"><i class="bi bi-pen-fill" aria-hidden="true"></i>Podpisz</button>
              <?php elseif ($a['type'] === 'confirm'): ?>
              <form method="post" action="<?= APP_URL ?>/ezd/podpis/view.php?id=<?= (int)$a['req_id'] ?>" class="tw-m-0">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="confirm">
                <button type="submit" class="ezx-btn ezx-btn--emerald"><i class="bi bi-check-lg" aria-hidden="true"></i>Potwierdź odbiór</button>
              </form>
              <?php else: ?>
              <form method="post" action="<?= APP_URL ?>/ezd/dekretacja/complete.php" class="tw-m-0">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$a['dekr_id'] ?>">
                <button type="submit" class="ezx-btn ezx-btn--ghost" title="Oznacz jako wykonane"><i class="bi bi-check-lg tw-text-emerald-600" aria-hidden="true"></i><span class="tw-hidden sm:tw-inline">Wykonane</span></button>
              </form>
              <a href="<?= h($a['href']) ?>" class="ezx-btn" title="Otwórz koszulkę"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i></a>
              <?php endif; ?>
            </div>
          </article>
          <?php endforeach; ?>
        </div>
        <div class="tw-px-4 tw-py-2.5 tw-border-t tw-border-solid tw-border-slate-100 tw-flex tw-flex-wrap tw-gap-4">
          <a href="<?= APP_URL ?>/ezd/zadania/index.php" class="ezx-more">Wszystkie zadania →</a>
          <a href="<?= APP_URL ?>/ezd/podpis/index.php" class="ezx-more">Wszystkie wnioski o podpis →</a>
        </div>
        <?php endif; ?>
      </div>
    </section>

    <!-- ──────── W TOKU ──────── -->
    <section id="sec-wtoku" role="tabpanel" aria-labelledby="tab-wtoku" tabindex="-1" x-show="section === 'wtoku'" x-transition.opacity.duration.150ms>
      <div class="ezx-card tw-mb-4">
        <div class="tw-flex tw-overflow-x-auto tw-px-2 tw-border-b tw-border-solid tw-border-slate-100" role="tablist" aria-label="W toku">
          <button type="button" class="ezx-subtab" role="tab" :aria-selected="sub === 'cases' ? 'true' : 'false'" @click="sub = 'cases'"><i class="bi bi-folder2-open" aria-hidden="true"></i>Moje koszulki <span class="ezx-badge ezx-badge--blue"><?= $my_sprawy_cnt ?></span></button>
          <button type="button" class="ezx-subtab" role="tab" :aria-selected="sub === 'docs' ? 'true' : 'false'" @click="sub = 'docs'"><i class="bi bi-envelope" aria-hidden="true"></i>Moje dokumenty <span class="ezx-badge ezx-badge--slate"><?= $my_pisma_cnt ?></span></button>
          <button type="button" class="ezx-subtab" role="tab" :aria-selected="sub === 'new' ? 'true' : 'false'" @click="sub = 'new'"><i class="bi bi-envelope-arrow-down" aria-hidden="true"></i>Nowe pisma <?php if ($nowe_pisma_jedn_cnt): ?><span class="ezx-badge ezx-badge--sky"><?= $nowe_pisma_jedn_cnt ?></span><?php endif; ?></button>
          <?php if ($ezd_mail_on): ?>
          <button type="button" class="ezx-subtab" role="tab" :aria-selected="sub === 'mail' ? 'true' : 'false'" @click="sub = 'mail'"><i class="bi bi-envelope-fill" aria-hidden="true"></i>Poczta <?php if ($ezd_mail_unread): ?><span class="ezx-badge ezx-badge--amber"><?= (int)$ezd_mail_unread ?></span><?php endif; ?></button>
          <?php endif; ?>
        </div>

        <!-- Moje koszulki: kafle ze stepperem -->
        <div x-show="sub === 'cases'" role="tabpanel">
          <?php if ($my_sprawy): ?>
          <div class="tw-grid tw-grid-cols-1 md:tw-grid-cols-2 tw-gap-3 tw-p-4">
            <?php foreach ($my_sprawy as $s): $cs = $case_steps[(int)$s['id']]; $over = !empty($s['deadline']) && $s['deadline'] < $today && empty($s['ciagla']);
                  $etap_meta = ezd_etap_meta($s['etap'] ?: 'wszczeta'); $pdf = $case_pdf[(int)$s['id']] ?? null; ?>
            <article class="tw-flex tw-flex-col tw-gap-2 tw-rounded-2xl tw-border tw-border-solid <?= $over ? 'tw-border-rose-200 tw-bg-rose-50/30' : 'tw-border-slate-200 tw-bg-white' ?> tw-p-3.5 tw-transition hover:tw-shadow-md hover:tw-border-blue-300">
              <div class="tw-flex tw-items-start tw-justify-between tw-gap-2">
                <span class="ezx-znak"><?= h($s['znak_sprawy']) ?></span>
                <div class="tw-flex tw-items-center tw-gap-1">
                  <?php if (!empty($s['is_shared'])): ?><span class="ezx-badge ezx-badge--sky" title="Współdzielona ze mną"><i class="bi bi-people" aria-hidden="true"></i>Wsp.</span><?php endif; ?>
                  <span class="ezx-badge <?= $s['etap'] === 'akceptacja' ? 'ezx-badge--violet' : ($s['etap'] === 'podpis' ? 'ezx-badge--amber' : 'ezx-badge--blue') ?>"><i class="bi <?= h($etap_meta['icon'] ?? 'bi-record-circle') ?>" aria-hidden="true"></i><?= $s['etap'] === 'akceptacja' ? 'W akceptacji' : h($etap_meta['label']) ?></span>
                </div>
              </div>
              <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$s['id'] ?>" class="ezx-title tw-block"><?= h($s['title']) ?></a>
              <div class="ezx-muted tw-flex tw-flex-wrap tw-gap-x-2">
                <span><i class="bi bi-calendar3 tw-mr-0.5" aria-hidden="true"></i>wpływ <?= date('d.m.Y', strtotime($s['created_at'])) ?></span>
                <?php if (!empty($s['ciagla'])): ?><span class="tw-text-sky-600"><i class="bi bi-infinity" aria-hidden="true"></i> ciągła</span>
                <?php elseif (!empty($s['deadline'])): ?><span class="<?= $over ? 'tw-text-rose-600 tw-font-semibold' : '' ?>"><i class="bi bi-calendar-event tw-mr-0.5" aria-hidden="true"></i>termin <?= date_pl($s['deadline']) ?><?= $over ? ' · Pilne' : '' ?></span><?php endif; ?>
                <?php if (isset($dekr_map[(int)$s['id']])): ?><span><i class="bi bi-person-fill tw-mr-0.5 tw-text-amber-500" aria-hidden="true"></i>u: <?= h(mb_substr($dekr_map[(int)$s['id']]['name'], 0, 22)) ?></span><?php endif; ?>
              </div>
              <?php if ($cs['steps']): ?>
              <div class="ezx-steps" role="img" aria-label="Postęp obiegu: etap <?= $cs['cur'] + 1 ?> z <?= count($cs['steps']) ?> — <?= h($etap_meta['label']) ?>">
                <?php foreach ($cs['steps'] as $i => $st): ?><span class="ezx-step <?= $i < $cs['cur'] ? 'ezx-step--done' : ($i === $cs['cur'] ? 'ezx-step--current' : '') ?>" title="<?= h($st['label']) ?>"></span><?php endforeach; ?>
              </div>
              <div class="ezx-muted tw-flex tw-justify-between"><span><?= h($cs['steps'][0]['label'] ?? '') ?></span><span>etap <?= max(0, $cs['cur']) + 1 ?>/<?= count($cs['steps']) ?></span><span><?= h(end($cs['steps'])['label'] ?? '') ?></span></div>
              <?php endif; ?>
              <div class="tw-flex tw-items-center tw-gap-1.5 tw-mt-1">
                <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$s['id'] ?>" class="ezx-btn ezx-btn--ghost"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i>Otwórz</a>
                <?php if ($pdf): ?><button type="button" class="ezx-btn ezx-btn--ghost" @click="openPreview(<?= h(json_encode(['zal' => (int)$pdf['id'], 'name' => $pdf['original_name'], 'znak' => $s['znak_sprawy'], 'ctx' => 'case', 'req' => 0, 'from' => '', 'href' => APP_URL . '/ezd/sprawy/view.php?id=' . (int)$s['id'], 'title' => $s['title']], JSON_UNESCAPED_SLASHES)) ?>)"><i class="bi bi-eye" aria-hidden="true"></i>Podgląd PDF</button><?php endif; ?>
              </div>
            </article>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <div class="tw-text-center tw-py-8 ezx-muted">Nie masz przypisanych aktywnych koszulek</div>
          <?php endif; ?>
          <div class="tw-px-4 tw-py-2.5 tw-border-t tw-border-solid tw-border-slate-100"><a href="<?= APP_URL ?>/ezd/sprawy/index.php?mine=1" class="ezx-more">Wszystkie moje koszulki →</a></div>
        </div>

        <!-- Moje dokumenty (pisma, których jestem referentem) -->
        <div x-show="sub === 'docs'" role="tabpanel" x-cloak>
          <?php if ($my_pisma): foreach ($my_pisma as $p): $k = EZD_KIERUNKI[$p['kierunek']] ?? ['icon' => 'bi-envelope', 'class' => 'secondary', 'label' => $p['kierunek']]; ?>
          <div class="ezx-item">
            <span class="ezx-ico tw-bg-slate-100 tw-text-slate-600" aria-hidden="true"><i class="bi <?= $k['icon'] ?>"></i></span>
            <div class="tw-flex-1 tw-min-w-0">
              <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= (int)$p['id'] ?>" class="ezx-title tw-block tw-truncate"><?= h($p['title']) ?></a>
              <div class="ezx-muted"><span class="ezx-znak"><?= h($p['sygnatura']) ?></span> · <?= h($k['label']) ?> · koszulka <span class="ezx-znak"><?= h($p['znak_sprawy']) ?></span></div>
            </div>
            <span class="ezx-badge <?= $p['status'] === 'nowe' ? 'ezx-badge--sky' : 'ezx-badge--slate' ?>"><?= $p['status'] === 'nowe' ? 'Nowy' : h($p['status']) ?></span>
          </div>
          <?php endforeach; else: ?>
          <div class="tw-text-center tw-py-8 ezx-muted">Nie jesteś referentem żadnego pisma</div>
          <?php endif; ?>
        </div>

        <!-- Nowe pisma w jednostce -->
        <div x-show="sub === 'new'" role="tabpanel" x-cloak>
          <?php if ($nowe_pisma_jedn): foreach ($nowe_pisma_jedn as $p):
            $med = ['papier' => 'Papier', 'email' => 'E-mail', 'epuap' => 'eDoręczenia', 'faks' => 'Faks', 'inne' => ''][$p['rodzaj_medium'] ?? ''] ?? ''; ?>
          <div class="ezx-item">
            <span class="ezx-ico tw-bg-sky-50 tw-text-sky-700" aria-hidden="true"><i class="bi bi-envelope-arrow-down"></i></span>
            <div class="tw-flex-1 tw-min-w-0">
              <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= (int)$p['id'] ?>" class="ezx-title tw-block tw-truncate"><?= h($p['title']) ?></a>
              <div class="ezx-muted"><span class="ezx-znak"><?= h($p['sygnatura'] ?: '—') ?></span> · <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$p['sprawa_id'] ?>" class="tw-text-slate-500 tw-no-underline hover:tw-underline"><?= h(mb_substr($p['sprawa_title'] ?? '', 0, 40)) ?></a> · <?= date('d.m.Y', strtotime($p['created_at'])) ?></div>
            </div>
            <?php if ($med): ?><span class="ezx-badge ezx-badge--slate"><?= $med ?></span><?php endif; ?>
            <span class="ezx-badge <?= $p['status'] === 'nowe' ? 'ezx-badge--sky' : 'ezx-badge--slate' ?>"><?= $p['status'] === 'nowe' ? 'Nowy' : h($p['status']) ?></span>
          </div>
          <?php endforeach; else: ?>
          <div class="tw-text-center tw-py-8 ezx-muted"><i class="bi bi-envelope-check tw-text-3xl tw-text-sky-300 tw-block tw-mb-1" aria-hidden="true"></i>Brak nowych pism przychodzących z ostatnich 14 dni</div>
          <?php endif; ?>
          <div class="tw-px-4 tw-py-2.5 tw-border-t tw-border-solid tw-border-slate-100"><a href="<?= APP_URL ?>/ezd/pisma/add.php" class="ezx-more">Zarejestruj pismo →</a></div>
        </div>

        <?php if ($ezd_mail_on): ?>
        <!-- Poczta EZD — nieprzeczytane z Inboxu Ogólnego, a gdy pusto, ostatnie wiadomości -->
        <div x-show="sub === 'mail'" role="tabpanel" x-cloak>
          <?php if ($ezd_mail): foreach ($ezd_mail as $mm):
            $unread = empty($mm['is_read']); $has_case = !empty($mm['ezd_sprawa_id']);
            $from = trim((string)($mm['from_name'] ?? '')) ?: (string)($mm['from_email'] ?? '');
            $link = APP_URL . '/ezd/poczta/thread.php?comm_id=' . (int)$mm['id']; ?>
          <div class="ezx-item">
            <span class="ezx-ico <?= $unread ? 'tw-bg-amber-50 tw-text-amber-600' : 'tw-bg-slate-100 tw-text-slate-500' ?>" aria-hidden="true"><i class="bi <?= $unread ? 'bi-envelope-fill' : 'bi-envelope-open' ?>"></i></span>
            <div class="tw-flex-1 tw-min-w-0">
              <a href="<?= h($link) ?>" class="ezx-title tw-block tw-truncate <?= $unread ? 'tw-font-bold' : '' ?>"><?= h($mm['subject'] ?: '(bez tematu)') ?></a>
              <div class="ezx-muted tw-truncate"><?= h($from) ?><?php if (!empty($mm['mailbox_name'])): ?> · <span class="ezx-znak"><?= h($mm['mailbox_name']) ?></span><?php endif; ?> · <?= h(!empty($mm['sent_at']) ? date('d.m.Y', strtotime((string)$mm['sent_at'])) : '—') ?></div>
            </div>
            <?php if ($has_case): ?><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$mm['ezd_sprawa_id'] ?>" class="ezx-badge ezx-badge--blue tw-no-underline" title="<?= h($mm['sprawa_title'] ?? '') ?>"><i class="bi bi-folder2-open" aria-hidden="true"></i><?= h($mm['znak_sprawy'] ?: 'koszulka') ?></a>
            <?php else: ?><span class="ezx-badge ezx-badge--amber" title="Wiadomość nie jest przypisana do żadnej koszulki">bez koszulki</span><?php endif; ?>
            <?php if (!empty($mm['has_download'])): ?><i class="bi bi-paperclip tw-text-slate-400" title="Załącznik" aria-hidden="true"></i><?php endif; ?>
          </div>
          <?php endforeach; else: ?>
          <div class="tw-text-center tw-py-8 ezx-muted">Skrzynka pusta — brak wiadomości w Inboxie Ogólnym</div>
          <?php endif; ?>
          <div class="tw-px-4 tw-py-2.5 tw-border-t tw-border-solid tw-border-slate-100 tw-flex tw-gap-4"><a href="<?= APP_URL ?>/ezd/poczta/index.php" class="ezx-more">Poczta EZD →</a><a href="<?= APP_URL ?>/ezd/poczta/compose.php" class="ezx-more tw-text-slate-500">Napisz wiadomość</a></div>
        </div>
        <?php endif; ?>
      </div>

      <!-- Aktywne koszulki w jednostce — tabela -->
      <div class="ezx-card">
        <div class="ezx-card-hd">
          <i class="bi bi-table tw-text-blue-600" aria-hidden="true"></i>
          <h2 class="ezx-h2">Aktywne koszulki w jednostce</h2>
          <a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="ezx-more tw-ml-auto">Wszystkie →</a>
        </div>
        <div class="tw-overflow-x-auto">
          <table class="ezx-table tw-w-full tw-border-collapse">
            <thead><tr><th>Tytuł</th><th>Znak</th><th>Etap</th><th>Termin</th><th>Przekazana do</th></tr></thead>
            <tbody>
            <?php foreach ($recent_sprawy as $s): $dk = $dekr_map[(int)$s['id']] ?? null; $em = ezd_etap_meta($s['etap'] ?: 'wszczeta'); $ov = $s['deadline'] && $s['deadline'] < $today; ?>
            <tr>
              <td><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$s['id'] ?>" class="ezx-title"><?= h(mb_substr($s['title'], 0, 48)) ?><?= mb_strlen($s['title']) > 48 ? '…' : '' ?></a></td>
              <td><span class="ezx-znak"><?= h($s['znak_sprawy']) ?></span></td>
              <td><span class="ezx-badge <?= $s['etap'] === 'akceptacja' ? 'ezx-badge--violet' : 'ezx-badge--slate' ?>"><?= $s['etap'] === 'akceptacja' ? 'W akceptacji' : h($em['label']) ?></span></td>
              <td class="<?= $ov ? 'tw-text-rose-600 tw-font-semibold' : '' ?>"><?php if (!empty($s['ciagla'])): ?><i class="bi bi-infinity tw-text-sky-500" aria-hidden="true"></i><?php else: ?><?= $s['deadline'] ? date_pl($s['deadline']) : '—' ?><?= $ov ? ' <span class="ezx-badge ezx-badge--urgent">Pilne</span>' : '' ?><?php endif; ?></td>
              <td><?php if ($dk): ?><?= h(mb_substr($dk['name'], 0, 22)) ?> <span class="ezx-badge ezx-badge--amber" title="Liczba koszulek u tej osoby"><?= (int)$dk['count'] ?></span><?php else: ?><span class="ezx-muted">—</span><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$recent_sprawy): ?><tr><td colspan="5" class="tw-text-center ezx-muted tw-py-6">Brak aktywnych koszulek</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- ──────── ZAKOŃCZONE / PODPISANE ──────── -->
    <section id="sec-zakonczone" role="tabpanel" aria-labelledby="tab-zakonczone" tabindex="-1" x-show="section === 'zakonczone'" x-transition.opacity.duration.150ms>
      <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-2 tw-gap-4">
        <div class="ezx-card">
          <div class="ezx-card-hd"><i class="bi bi-pen tw-text-emerald-600" aria-hidden="true"></i><h2 class="ezx-h2">Podpisy — zakończone</h2><span class="tw-ml-auto ezx-muted"><?= $sign_cnt['potwierdzone'] ?> potwierdzonych · <?= $sign_cnt['odrzucone'] ?> odrzuconych</span></div>
          <?php if ($sign_done): foreach ($sign_done as $sr): [$sl, $sc] = $SIGN_BADGE[$sr['status']] ?? [$sr['status'], 'ezx-badge--slate']; $i_signed = (int)$sr['requested_to'] === $user_id; ?>
          <div class="ezx-item">
            <span class="ezx-ico <?= $sr['status'] === 'odrzucone' ? 'tw-bg-rose-50 tw-text-rose-600' : 'tw-bg-emerald-50 tw-text-emerald-700' ?>" aria-hidden="true"><i class="bi <?= $sr['status'] === 'odrzucone' ? 'bi-x-circle' : 'bi-patch-check-fill' ?>"></i></span>
            <div class="tw-flex-1 tw-min-w-0">
              <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-1.5"><span class="ezx-badge <?= $sc ?>"><?= h($sl) ?></span><span class="ezx-muted"><?= $i_signed ? 'podpisałeś(-aś) dla: ' . h($sr['requested_by_name'] ?? '—') : 'podpisał: ' . h($sr['requested_to_name'] ?? '—') ?></span></div>
              <a href="<?= APP_URL ?>/ezd/podpis/view.php?id=<?= (int)$sr['id'] ?>" class="ezx-title tw-block tw-truncate"><?= h($sr['zal_name'] ?? '—') ?></a>
              <div class="ezx-muted"><span class="ezx-znak"><?= h($sr['znak_sprawy'] ?? '') ?></span> · <?= h(date('d.m.Y', strtotime($sr['confirmed_at'] ?: ($sr['signed_at'] ?: $sr['requested_at'])))) ?><?php if ($sr['status'] === 'odrzucone' && !empty($sr['notes'])): ?> · „<?= h(mb_strimwidth($sr['notes'], 0, 70, '…')) ?>”<?php endif; ?></div>
            </div>
            <?php if (!empty($sr['signed_zal_id'])): ?><button type="button" class="ezx-btn ezx-btn--ghost" @click="openPreview(<?= h(json_encode(['zal' => (int)$sr['signed_zal_id'], 'name' => $sr['zal_name'], 'znak' => $sr['znak_sprawy'], 'ctx' => 'done', 'req' => (int)$sr['id'], 'from' => '', 'href' => APP_URL . '/ezd/podpis/view.php?id=' . (int)$sr['id'], 'title' => $sr['zal_name']], JSON_UNESCAPED_SLASHES)) ?>)"><i class="bi bi-eye" aria-hidden="true"></i></button><?php endif; ?>
          </div>
          <?php endforeach; else: ?>
          <div class="tw-text-center tw-py-8 ezx-muted">Brak zakończonych podpisów</div>
          <?php endif; ?>
          <div class="tw-px-4 tw-py-2.5 tw-border-t tw-border-solid tw-border-slate-100"><a href="<?= APP_URL ?>/ezd/podpis/index.php" class="ezx-more">Wnioski o podpis →</a></div>
        </div>
        <div class="ezx-card">
          <div class="ezx-card-hd"><i class="bi bi-archive tw-text-slate-500" aria-hidden="true"></i><h2 class="ezx-h2">Zamknięte koszulki</h2><span class="tw-ml-auto ezx-muted"><?= $closed_cnt ?> łącznie</span></div>
          <?php if ($closed_sprawy): foreach ($closed_sprawy as $s): ?>
          <div class="ezx-item">
            <span class="ezx-ico tw-bg-slate-100 tw-text-slate-500" aria-hidden="true"><i class="bi bi-folder-check"></i></span>
            <div class="tw-flex-1 tw-min-w-0">
              <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-1.5"><span class="ezx-badge ezx-badge--slate"><i class="bi bi-lock" aria-hidden="true"></i>Zamknięta</span><span class="ezx-znak"><?= h($s['znak_sprawy']) ?></span></div>
              <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$s['id'] ?>" class="ezx-title tw-block tw-truncate"><?= h($s['title']) ?></a>
              <div class="ezx-muted">zamknięto <?= !empty($s['closed_at']) ? date('d.m.Y', strtotime($s['closed_at'])) : '—' ?><?php if (!empty($s['close_reason'])): ?> · <?= h(mb_strimwidth($s['close_reason'], 0, 60, '…')) ?><?php endif; ?></div>
            </div>
          </div>
          <?php endforeach; else: ?>
          <div class="tw-text-center tw-py-8 ezx-muted">Brak zamkniętych koszulek z Twoim udziałem</div>
          <?php endif; ?>
          <div class="tw-px-4 tw-py-2.5 tw-border-t tw-border-solid tw-border-slate-100"><a href="<?= APP_URL ?>/ezd/sprawy/index.php?status=closed&mine=1" class="ezx-more">Archiwum moich koszulek →</a></div>
        </div>
      </div>
    </section>

    <!-- ──────── PROFIL I PODPIS ──────── -->
    <section id="sec-profil" role="tabpanel" aria-labelledby="tab-profil" tabindex="-1" x-show="section === 'profil'" x-transition.opacity.duration.150ms>
      <div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-2 tw-gap-4">
        <div class="ezx-card">
          <div class="ezx-card-hd"><i class="bi bi-person-badge tw-text-blue-600" aria-hidden="true"></i><h2 class="ezx-h2">Twój profil w EZD</h2></div>
          <div class="tw-p-4">
            <div class="tw-flex tw-items-center tw-gap-3 tw-mb-4">
              <span class="tw-w-12 tw-h-12 tw-rounded-2xl tw-bg-gradient-to-br tw-from-blue-600 tw-to-indigo-600 tw-text-white tw-flex tw-items-center tw-justify-center tw-font-bold tw-shadow-md" aria-hidden="true"><?= h($user_init) ?></span>
              <div class="tw-min-w-0"><div class="tw-font-semibold tw-text-slate-900 tw-truncate"><?= h($user['name'] ?? '') ?></div><div class="ezx-muted tw-truncate"><?= h($user['email'] ?? '') ?></div></div>
            </div>
            <dl class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-2 tw-m-0">
              <div class="ezx-kv"><dt>Rola w systemie</dt><dd><?= h($role_label) ?></dd></div>
              <div class="ezx-kv"><dt>Zakres w EZD</dt><dd><?= h($ezd_access) ?></dd></div>
              <div class="ezx-kv"><dt>Podpisy w EZD</dt><dd><?= (int)$sign_stats['count'] ?><?= $sign_stats['last_at'] ? ' <span class="ezx-muted tw-font-normal">· ostatni ' . h(date('d.m.Y', strtotime($sign_stats['last_at']))) . '</span>' : '' ?></dd></div>
              <div class="ezx-kv"><dt>Koszulki · pisma</dt><dd><?= $my_sprawy_cnt ?> otwartych · <?= $my_pisma_cnt ?> pism</dd></div>
            </dl>
            <div class="tw-flex tw-flex-wrap tw-gap-2 tw-mt-4">
              <a href="<?= APP_URL ?>/tozsamosc/index.php" class="ezx-btn ezx-btn--ghost"><i class="bi bi-person-vcard" aria-hidden="true"></i>System Tożsamości</a>
              <a href="<?= APP_URL ?>/ezd/podpis/index.php" class="ezx-btn ezx-btn--ghost"><i class="bi bi-pen" aria-hidden="true"></i>Moje wnioski o podpis</a>
              <?php if (is_admin()): ?><a href="<?= APP_URL ?>/admin/ezd_settings.php" class="ezx-btn ezx-btn--ghost"><i class="bi bi-gear" aria-hidden="true"></i>Ustawienia EZD</a><?php endif; ?>
            </div>
          </div>
        </div>

        <div class="ezx-card">
          <div class="ezx-card-hd">
            <i class="bi bi-shield-check tw-text-emerald-600" aria-hidden="true"></i>
            <h2 class="ezx-h2">Podpis kwalifikowany</h2>
            <?php if ($sign_profile): $st = $sign_profile['state']; ?>
            <span class="tw-ml-auto ezx-badge <?= $st === 'active' ? 'ezx-badge--emerald' : ($st === 'expiring' ? 'ezx-badge--amber' : ($st === 'expired' ? 'ezx-badge--rose' : 'ezx-badge--slate')) ?>">
              <i class="bi <?= $st === 'active' ? 'bi-check-circle-fill' : ($st === 'expired' ? 'bi-x-circle-fill' : 'bi-exclamation-circle-fill') ?>" aria-hidden="true"></i>
              <?= $st === 'active' ? 'Certyfikat aktywny' : ($st === 'expiring' ? 'Wygasa za ' . (int)$sign_profile['days_left'] . ' dni' : ($st === 'expired' ? 'Certyfikat wygasł' : 'Ważność nieznana')) ?>
            </span>
            <?php else: ?><span class="tw-ml-auto ezx-badge ezx-badge--slate">Brak zapisanego certyfikatu</span><?php endif; ?>
          </div>
          <div class="tw-p-4">
            <?php if ($sign_profile): ?>
            <dl class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-2 tw-m-0 tw-mb-3">
              <div class="ezx-kv sm:tw-col-span-2"><dt>Certyfikat (CN)</dt><dd><?= h($sign_profile['cn'] ?: '—') ?><?= $sign_profile['o'] !== '' ? ' <span class="ezx-muted tw-font-normal">· ' . h($sign_profile['o']) . '</span>' : '' ?></dd></div>
              <div class="ezx-kv"><dt>Wystawca</dt><dd><?= h($sign_profile['issuer'] ?: '—') ?></dd></div>
              <div class="ezx-kv"><dt>Ważny do</dt><dd class="<?= $sign_profile['state'] === 'expired' ? 'tw-text-rose-600' : '' ?>"><?= $sign_profile['valid_to'] !== '' ? h(date('d.m.Y', strtotime($sign_profile['valid_to']))) : '—' ?></dd></div>
              <div class="ezx-kv"><dt>Numer seryjny</dt><dd class="tw-font-mono tw-text-[.72rem] tw-font-medium tw-break-all"><?= h(str_starts_with($sign_profile['serial'], 'cn:') ? '—' : $sign_profile['serial']) ?></dd></div>
              <div class="ezx-kv"><dt>Użyty</dt><dd><?= (int)$sign_profile['uses'] ?>× · ostatnio <?= h(date('d.m.Y', strtotime($sign_profile['last_used_at']))) ?></dd></div>
            </dl>
            <?php if ($sign_profile['state'] === 'expiring' || $sign_profile['state'] === 'expired'): ?>
            <div class="tw-rounded-xl tw-bg-amber-50 tw-border tw-border-solid tw-border-amber-200 tw-text-amber-800 tw-px-3 tw-py-2 tw-text-xs tw-mb-3"><i class="bi bi-exclamation-triangle tw-mr-1" aria-hidden="true"></i>Odnów certyfikat u dostawcy (CenCert). Po pierwszym podpisie nowym certyfikatem dane tutaj zaktualizują się same.</div>
            <?php endif; ?>
            <?php else: ?>
            <p class="ezx-muted tw-mt-0 tw-mb-3">Dane certyfikatu pojawią się po pierwszym podpisie złożonym przez rSign Desktop — system zapisuje wtedy tylko publiczne informacje z podpisu (nazwa, wystawca, ważność). Żaden klucz ani PIN nie opuszcza Twojego komputera.</p>
            <?php endif; ?>
            <div class="tw-rounded-xl tw-border tw-border-solid tw-border-slate-200 tw-p-3 tw-flex tw-flex-wrap tw-items-center tw-gap-3">
              <span class="ezx-ico tw-bg-slate-100 tw-text-slate-600" aria-hidden="true"><i class="bi bi-pc-display"></i></span>
              <div class="tw-flex-1 tw-min-w-[180px]">
                <div class="tw-text-[.84rem] tw-font-semibold tw-text-slate-900">Aplikacja podpisująca (rSign Desktop / CenCert)</div>
                <div class="ezx-muted">Lokalny port <code><?= (int)$rsign_port ?></code> ·
                  <span x-show="rsign === 'idle'">stan nieznany — sprawdź połączenie</span>
                  <span x-show="rsign === 'checking'" x-cloak><i class="bi bi-arrow-repeat" aria-hidden="true"></i> sprawdzam…</span>
                  <span x-show="rsign === 'ok'" x-cloak class="tw-text-emerald-700 tw-font-semibold"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> aplikacja odpowiada — możesz podpisywać</span>
                  <span x-show="rsign === 'off'" x-cloak class="tw-text-rose-600 tw-font-semibold"><i class="bi bi-x-circle-fill" aria-hidden="true"></i> brak odpowiedzi — uruchom aplikację i podłącz kartę/token</span>
                </div>
              </div>
              <button type="button" class="ezx-btn ezx-btn--ghost" @click="checkRsign()" :disabled="rsign === 'checking'"><i class="bi bi-plug" aria-hidden="true"></i>Sprawdź połączenie</button>
            </div>
            <p class="ezx-muted tw-mt-3 tw-mb-0"><i class="bi bi-info-circle tw-mr-1" aria-hidden="true"></i>Podpis PAdES składasz na swoim komputerze: pulpit przekazuje dokument do aplikacji, Ty potwierdzasz PIN-em do karty lub tokenu, a podpisana wersja wraca do koszulki jako nowy plik i domyka wniosek o podpis.</p>
          </div>
        </div>
      </div>
    </section>
  </div>

  <!-- ═══════════ PRAWA KOLUMNA: widżety ═══════════ -->
  <div class="xl:tw-col-span-4 tw-min-w-0 tw-grid tw-grid-cols-1 tw-gap-4 tw-content-start">

    <div class="ezx-card">
      <div class="ezx-card-hd"><i class="bi bi-grid-3x3-gap tw-text-blue-600" aria-hidden="true"></i><h2 class="ezx-h2">Szybkie akcje</h2></div>
      <div class="tw-grid tw-grid-cols-3 tw-gap-2 tw-p-3">
        <?php foreach ($qa as $qi): ?>
        <?php if (!empty($qi['modal'])): ?><button type="button" class="ezx-qa" data-bs-toggle="modal" data-bs-target="<?= $qi['modal'] ?>"><?php else: ?><a href="<?= h($qi['href']) ?>" class="ezx-qa"><?php endif; ?>
          <span class="ezx-qa-ico tw-bg-<?= $qi['tone'] ?>-50 tw-text-<?= $qi['tone'] ?>-700"><i class="bi <?= $qi['icon'] ?>" aria-hidden="true"></i><?php if (!empty($qi['badge'])): ?><span class="ezx-qa-badge"><?= (int)$qi['badge'] ?></span><?php endif; ?></span>
          <span class="ezx-qa-lbl"><?= h($qi['lbl']) ?></span>
        <?= !empty($qi['modal']) ? '</button>' : '</a>' ?>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="ezx-card">
      <div class="ezx-card-hd"><i class="bi bi-activity tw-text-blue-600" aria-hidden="true"></i><h2 class="ezx-h2">Ostatnia aktywność</h2></div>
      <div class="tw-px-4 tw-py-3">
        <?php $dot_colors = ['sprawa_create' => '#22c55e', 'sprawa_update' => '#3b82f6', 'pismo_create' => '#06b6d4', 'upload' => '#94a3b8', 'dekretacja_create' => '#f59e0b', 'sign_rsign' => '#10b981', 'sign_uploaded' => '#10b981', 'sign_request' => '#f59e0b']; ?>
        <?php foreach ($activity as $a): ?>
        <div class="tw-flex tw-items-start tw-gap-2 tw-mb-2.5 tw-text-[.76rem]">
          <span class="tw-shrink-0 tw-mt-1.5 tw-w-2 tw-h-2 tw-rounded-full" style="background:<?= $dot_colors[$a['action']] ?? '#94a3b8' ?>" aria-hidden="true"></span>
          <div class="tw-flex-1 tw-min-w-0">
            <?php if ($a['znak_sprawy']): ?><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$a['sprawa_id'] ?>" class="ezx-znak tw-no-underline tw-font-semibold hover:tw-underline"><?= h($a['znak_sprawy']) ?></a><?php endif; ?>
            <div class="tw-text-slate-700 tw-break-words [overflow-wrap:anywhere] tw-line-clamp-2"><?= h($a['details'] ?: $a['action']) ?></div>
            <div class="ezx-muted"><?= h($a['user_name'] ?? '—') ?> · <?= date('d.m H:i', strtotime($a['created_at'])) ?></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$activity): ?><div class="tw-text-center ezx-muted tw-py-2">Brak aktywności</div><?php endif; ?>
      </div>
    </div>

    <div class="ezx-card">
      <div class="ezx-card-hd"><i class="bi bi-megaphone tw-text-rose-500" aria-hidden="true"></i><h2 class="ezx-h2">Ważne komunikaty</h2></div>
      <div class="tw-px-4 tw-py-3 tw-grid tw-grid-cols-1 tw-gap-2">
        <?php if ($komunikaty): foreach ($komunikaty as $ann): $pilny = !empty($ann['is_pinned']); ?>
        <div class="tw-rounded-xl tw-border-l-[3px] tw-border-solid <?= $pilny ? 'tw-border-rose-500 tw-bg-rose-50/60' : 'tw-border-blue-500 tw-bg-slate-50' ?> tw-px-3 tw-py-2">
          <div class="tw-text-[.8rem] tw-font-semibold tw-text-slate-900"><?= h(mb_substr($ann['title'], 0, 60)) ?><?= mb_strlen($ann['title']) > 60 ? '…' : '' ?></div>
          <div class="ezx-muted"><?php if ($pilny): ?><i class="bi bi-pin-angle-fill tw-text-rose-500 tw-mr-1" aria-hidden="true"></i><?php endif; ?><?= date('d.m.Y', strtotime($ann['created_at'])) ?><?php if (!empty($ann['expires_at'])): ?> · do <?= date('d.m', strtotime($ann['expires_at'])) ?><?php endif; ?><?php if (empty($ann['is_read_by_me'])): ?> <span class="ezx-badge ezx-badge--rose">Nowy</span><?php endif; ?></div>
        </div>
        <?php endforeach; else: ?>
        <div class="tw-text-center ezx-muted tw-py-2">Brak aktywnych komunikatów</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($zastepuje || $zastepuje_mnie): ?>
    <div class="ezx-card">
      <div class="ezx-card-hd"><i class="bi bi-people tw-text-teal-600" aria-hidden="true"></i><h2 class="ezx-h2">Zastępstwa</h2></div>
      <div class="tw-px-4 tw-py-3">
        <?php if ($zastepuje): ?><div class="ezx-eyebrow tw-mb-1">Ty zastępujesz</div>
        <?php foreach ($zastepuje as $z): ?><div class="tw-text-[.8rem] tw-py-1 tw-border-b tw-border-solid tw-border-slate-100"><i class="bi bi-person-fill-check tw-text-emerald-600 tw-mr-1" aria-hidden="true"></i><span class="tw-font-semibold"><?= h($z['mocodawca']) ?></span><?php if (!empty($z['zakres'])): ?><div class="ezx-muted"><?= h(mb_substr($z['zakres'], 0, 50)) ?></div><?php endif; ?></div><?php endforeach; ?>
        <?php endif; ?>
        <?php if ($zastepuje_mnie): ?><div class="ezx-eyebrow tw-mb-1<?= $zastepuje ? ' tw-mt-3' : '' ?>">Ciebie zastępują</div>
        <?php foreach ($zastepuje_mnie as $z): ?><div class="tw-text-[.8rem] tw-py-1 tw-border-b tw-border-solid tw-border-slate-100"><i class="bi bi-person-fill tw-text-sky-600 tw-mr-1" aria-hidden="true"></i><span class="tw-font-semibold"><?= h($z['pelnomocnik']) ?></span><?php if (!empty($z['zakres'])): ?><div class="ezx-muted"><?= h(mb_substr($z['zakres'], 0, 50)) ?></div><?php endif; ?></div><?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="ezx-card">
      <div class="ezx-card-hd"><i class="bi bi-calendar3 tw-text-blue-600" aria-hidden="true"></i><h2 class="ezx-h2">Kalendarz</h2><a href="<?= APP_URL ?>/ezd/terminarz.php" class="ezx-more tw-ml-auto">Terminarz →</a></div>
      <div class="tw-p-3" id="ezd-cal"></div>
    </div>

    <div class="ezx-card">
      <div class="ezx-card-hd"><i class="bi bi-envelope-paper tw-text-sky-600" aria-hidden="true"></i><h2 class="ezx-h2">eDoręczenia</h2></div>
      <div class="tw-px-4 tw-py-3">
        <div class="tw-flex tw-items-center tw-gap-2 tw-mb-1"><code class="tw-flex-1 tw-truncate tw-text-[.74rem] tw-text-slate-800"><?= h($ede_ade) ?></code><button type="button" class="ezx-btn ezx-btn--ghost ede-copy-btn" data-ede-value="<?= h($ede_ade) ?>" title="Kopiuj ADE"><i class="bi bi-clipboard" aria-hidden="true"></i></button></div>
        <div class="ezx-muted"><?= h($ede_email) ?> · <?= h($ede_phone) ?></div>
        <button type="button" class="ezx-btn ezx-btn--ghost tw-w-full tw-mt-2" data-bs-toggle="modal" data-bs-target="#ezdEDoreczeniaModal"><i class="bi bi-info-circle" aria-hidden="true"></i>Szczegóły eDoręczeń</button>
      </div>
    </div>

    <?php $ezdc_files = ezd_client_files();
          // Gdy instalatorów nie ma w uploads/ezd-client/, pokaż te z repozytorium (Git LFS, manifest SHA256SUMS.txt) — includes/ezd_client.php
          $ezdc_repo = (!$ezdc_files && function_exists('ezd_client_repo_files')) ? ezd_client_repo_files() : []; ?>
    <div class="ezx-card">
      <div class="ezx-card-hd"><i class="bi bi-laptop tw-text-indigo-600" aria-hidden="true"></i><h2 class="ezx-h2">EZD Client</h2></div>
      <div class="tw-px-4 tw-py-3">
        <p class="ezx-muted tw-mt-0 tw-mb-2"><strong class="tw-text-slate-800">Aplikacja na komputer</strong> (macOS i Windows): skanuje ze skanera sieciowego (AirScan/eSCL) i wgrywa jednym kliknięciem do koszulki, nowej koszulki albo dziennika podawczego. Logujesz się kontem Microsoft 365 — jednorazowo.</p>
        <?php if ($ezdc_files): ?>
        <div class="tw-grid tw-grid-cols-1 tw-gap-1.5">
          <?php foreach ($ezdc_files as $ext => $f): ?>
          <a class="ezx-btn <?= in_array($ext, ['dmg', 'exe'], true) ? '' : 'ezx-btn--ghost' ?> tw-justify-start" href="<?= APP_URL ?>/ezd/klient/pobierz.php?ext=<?= h($ext) ?>"><i class="bi bi-download" aria-hidden="true"></i><?= h($f['label']) ?> <span class="tw-opacity-75 tw-font-normal">· v<?= h($f['version']) ?>, <?= h(ezd_client_size_label($f['size'])) ?></span></a>
          <?php endforeach; ?>
        </div>
        <?php elseif ($ezdc_repo): ?>
        <div class="tw-grid tw-grid-cols-1 tw-gap-1.5">
          <?php foreach ($ezdc_repo as $ext => $f): ?>
          <a class="ezx-btn <?= in_array($ext, ['dmg', 'exe'], true) ? '' : 'ezx-btn--ghost' ?> tw-justify-start" href="<?= h($f['url']) ?>" rel="noopener"><i class="bi bi-download" aria-hidden="true"></i><?= h($f['label']) ?> <span class="tw-opacity-75 tw-font-normal">· v<?= h($f['version']) ?></span></a>
          <?php endforeach; ?>
        </div>
        <p class="ezx-muted tw-mt-2 tw-mb-0">Pliki pochodzą z repozytorium projektu. Sumy SHA-256:
          <?php foreach ($ezdc_repo as $ext => $f): ?><code class="user-select-all"><?= h(substr($f['sha'], 0, 12)) ?>…</code> (<?= h($ext) ?>) <?php endforeach; ?></p>
        <?php else: ?>
        <p class="ezx-muted tw-m-0">Instalatory pojawią się tutaj wkrótce.<?= is_admin() ? ' Wgraj pliki .dmg/.pkg/.exe/.msi do katalogu <code>uploads/ezd-client/</code>.' : '' ?></p>
        <?php endif; ?>
        <?php if (is_admin()): ?><p class="ezx-muted tw-mt-2 tw-mb-0">Klucz API dla aplikacji: <a href="<?= APP_URL ?>/admin/api_manage.php">Zarządzaj API</a> (ezd:read, ezd:upload, ezd:write; opcjonalnie crm:read, crm:write).</p><?php endif; ?>
      </div>
    </div>

    <?php if ($przydatne_linki): ?>
    <div class="ezx-card">
      <div class="ezx-card-hd"><i class="bi bi-link-45deg tw-text-slate-500" aria-hidden="true"></i><h2 class="ezx-h2">Przydatne linki</h2></div>
      <div class="tw-px-4 tw-py-2">
        <?php foreach ($przydatne_linki as $lnk): ?><div class="tw-py-1.5 tw-border-b tw-border-solid tw-border-slate-100 last:tw-border-b-0 tw-text-[.8rem]"><a href="<?= h($lnk['url'] ?? '#') ?>" target="_blank" rel="noopener" class="tw-text-blue-700 tw-no-underline hover:tw-underline"><i class="bi bi-box-arrow-up-right tw-mr-1 tw-text-slate-400" aria-hidden="true"></i><?= h($lnk['label'] ?? $lnk['url'] ?? '—') ?></a></div><?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════ PANEL PODGLĄDU DOKUMENTU ═══════════ -->
<template x-if="preview.open">
  <div>
    <div class="ezx-overlay" @click="closePreview()"></div>
    <aside class="ezx-drawer" role="dialog" aria-modal="true" aria-labelledby="ezx-preview-title" x-trap.noscroll="preview.open" @keydown.escape.window="closePreview()">
      <div class="tw-flex tw-items-center tw-gap-3 tw-px-4 tw-py-3 tw-border-b tw-border-solid tw-border-slate-200">
        <span class="ezx-ico tw-bg-rose-50 tw-text-rose-600" aria-hidden="true"><i class="bi bi-file-earmark-pdf"></i></span>
        <div class="tw-flex-1 tw-min-w-0">
          <h2 id="ezx-preview-title" class="tw-m-0 tw-text-[.9rem] tw-font-semibold tw-text-slate-900 tw-truncate" x-text="preview.name"></h2>
          <div class="ezx-muted tw-truncate"><span class="ezx-znak" x-text="preview.znak"></span><span x-show="preview.from"> · <span x-text="preview.ctx === 'confirm' ? 'podpisał: ' + preview.from : 'od: ' + preview.from"></span></span></div>
        </div>
        <a :href="preview.url" target="_blank" rel="noopener" class="ezx-btn ezx-btn--ghost" title="Otwórz w nowej karcie / pobierz"><i class="bi bi-download" aria-hidden="true"></i></a>
        <button type="button" class="ezx-btn ezx-btn--ghost" @click="closePreview()" aria-label="Zamknij podgląd"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
      </div>
      <div class="tw-flex-1 tw-bg-slate-100 tw-min-h-0">
        <iframe :src="preview.url" class="tw-w-full tw-h-full tw-border-0" title="Podgląd dokumentu PDF" loading="lazy"></iframe>
      </div>
      <div class="tw-px-4 tw-py-3 tw-border-t tw-border-solid tw-border-slate-200 tw-flex tw-flex-wrap tw-items-center tw-gap-2 tw-bg-white">
        <a :href="preview.href" class="ezx-btn ezx-btn--ghost"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i><span x-text="preview.ctx === 'sign' || preview.ctx === 'confirm' || preview.ctx === 'done' ? 'Otwórz wniosek' : 'Otwórz koszulkę'"></span></a>
        <template x-if="preview.ctx === 'sign'">
          <div class="tw-ml-auto tw-flex tw-flex-wrap tw-items-center tw-gap-2">
            <button type="button" class="ezx-btn ezx-btn--danger" @click="rejectOpen = !rejectOpen" :aria-expanded="rejectOpen ? 'true' : 'false'"><i class="bi bi-x-circle" aria-hidden="true"></i>Odrzuć</button>
            <button type="button" class="ezx-btn ezx-btn--amber" @click="startSign({zal: preview.zal, name: preview.name, znak: preview.znak, req: preview.req, from: preview.from})"><i class="bi bi-pen-fill" aria-hidden="true"></i>Podpisz dokument</button>
          </div>
        </template>
        <template x-if="preview.ctx === 'confirm'">
          <form method="post" :action="cfg.podpis + preview.req" class="tw-ml-auto tw-m-0">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="confirm">
            <button type="submit" class="ezx-btn ezx-btn--emerald"><i class="bi bi-check-lg" aria-hidden="true"></i>Potwierdź odbiór</button>
          </form>
        </template>
      </div>
      <form x-show="rejectOpen && preview.ctx === 'sign'" x-cloak method="post" :action="cfg.podpis + preview.req" class="tw-px-4 tw-py-3 tw-border-t tw-border-solid tw-border-rose-200 tw-bg-rose-50/60 tw-m-0">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="reject">
        <label for="ezx-reject-notes" class="tw-block tw-text-xs tw-font-semibold tw-text-rose-800 tw-mb-1">Powód odrzucenia (opcjonalny)</label>
        <div class="tw-flex tw-gap-2">
          <input id="ezx-reject-notes" type="text" name="reject_notes" class="form-control form-control-sm" placeholder="np. dokument wymaga zmian…" maxlength="500">
          <button type="submit" class="ezx-btn ezx-btn--danger tw-shrink-0" onclick="return confirm('Na pewno odrzucić prośbę o podpis?')">Odrzuć prośbę</button>
        </div>
      </form>
    </aside>
  </div>
</template>

<!-- ═══════════ OKNO: POTWIERDZENIE PODPISU (hash) → rSign Desktop ═══════════ -->
<template x-if="sign.open">
  <div>
    <div class="ezx-overlay" @click="sign.open = false"></div>
    <div class="ezx-modal" role="dialog" aria-modal="true" aria-labelledby="ezx-sign-title" x-trap.noscroll="sign.open" @keydown.escape.window="sign.open = false">
      <div class="ezx-modal-box">
        <div class="tw-flex tw-items-center tw-gap-2 tw-px-5 tw-py-3.5 tw-border-b tw-border-solid tw-border-slate-100">
          <span class="ezx-ico tw-bg-amber-50 tw-text-amber-700" aria-hidden="true"><i class="bi bi-pen-fill"></i></span>
          <h2 id="ezx-sign-title" class="ezx-h2 tw-text-base">Podpisz dokument</h2>
          <button type="button" class="tw-ml-auto ezx-btn ezx-btn--ghost" @click="sign.open = false" aria-label="Zamknij"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="tw-px-5 tw-py-4">
          <dl class="tw-grid tw-grid-cols-1 tw-gap-2 tw-m-0 tw-mb-3">
            <div class="ezx-kv"><dt>Dokument</dt><dd x-text="sign.name"></dd></div>
            <div class="tw-grid tw-grid-cols-2 tw-gap-2">
              <div class="ezx-kv"><dt>Koszulka</dt><dd class="tw-font-mono tw-text-[.78rem]" x-text="sign.znak || '—'"></dd></div>
              <div class="ezx-kv"><dt>Prosi o podpis</dt><dd x-text="sign.from || '—'"></dd></div>
            </div>
            <div class="ezx-kv">
              <dt>Skrót dokumentu (SHA-256)</dt>
              <dd class="tw-font-mono tw-text-[.7rem] tw-font-medium tw-break-all" aria-live="polite">
                <span x-show="sign.state === 'loading'"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> liczę skrót…</span>
                <span x-show="sign.state === 'ready'" x-text="sign.hash"></span>
                <span x-show="sign.state === 'error'" class="tw-text-rose-600" x-text="sign.error"></span>
              </dd>
            </div>
          </dl>
          <p class="ezx-muted tw-m-0" x-show="sign.state === 'ready' && sign.signed" x-cloak><i class="bi bi-info-circle tw-mr-1" aria-hidden="true"></i>Ten plik ma już podpis elektroniczny — dołożysz kolejny (kontrasygnata).</p>
          <div class="tw-rounded-xl tw-bg-slate-50 tw-border tw-border-solid tw-border-slate-200 tw-px-3 tw-py-2.5 tw-text-xs tw-text-slate-700 tw-mt-3">
            <div class="tw-font-semibold tw-text-slate-900 tw-mb-1"><i class="bi bi-shield-check tw-text-emerald-600 tw-mr-1" aria-hidden="true"></i>Podpis kwalifikowany (CenCert / rSign Desktop, PAdES)</div>
            <ol class="tw-m-0 tw-pl-4 tw-grid tw-gap-0.5">
              <li>Pulpit sprawdzi aplikację podpisującą na porcie <code x-text="cfg.port"></code> i przekaże jej dokument.</li>
              <li>Potwierdzisz podpis <strong>PIN-em</strong> do karty lub tokenu w oknie aplikacji.</li>
              <li>Podpisana wersja trafi do koszulki jako nowy plik i domknie wniosek o podpis.</li>
            </ol>
          </div>
        </div>
        <div class="tw-px-5 tw-py-3 tw-border-t tw-border-solid tw-border-slate-100 tw-flex tw-flex-wrap tw-items-center tw-justify-end tw-gap-2 tw-bg-slate-50/60">
          <button type="button" class="ezx-btn ezx-btn--ghost" @click="sign.open = false">Anuluj</button>
          <button type="button" class="ezx-btn ezx-btn--amber tw-py-2 tw-px-4 tw-text-sm" :disabled="sign.state !== 'ready'" @click="handoffToRsign()"><i class="bi bi-pen-fill" aria-hidden="true"></i>Podpisz w rSign Desktop</button>
        </div>
      </div>
    </div>
  </div>
</template>
<!-- przekaźnik do istniejącego okna rSign (includes/ezd_rsign.php nasłuchuje kliknięć w .ezd-rsign-btn) -->
<button type="button" class="ezd-rsign-btn tw-hidden" x-ref="rsignBtn" aria-hidden="true" tabindex="-1" data-zal-id="" data-zal-name=""></button>

</div><!-- /x-data ezdDash -->

<!-- Modal: eDoręczenia (Bootstrap) -->
<div class="modal fade" id="ezdEDoreczeniaModal" tabindex="-1" aria-labelledby="ezdEDoreczeniaModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h2 class="modal-title h6 mb-0" id="ezdEDoreczeniaModalLabel"><i class="bi bi-envelope-paper text-primary me-2" aria-hidden="true"></i>eDoręczenia</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="list-group mb-3">
          <?php foreach ([['Adres ADE', $ede_ade, true], ['E-mail', $ede_email, false], ['Telefon', $ede_phone, false]] as [$l, $v, $mono]): ?>
          <div class="list-group-item d-flex align-items-center gap-2">
            <div class="flex-grow-1">
              <div class="text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.03em"><?= $l ?></div>
              <div class="fw-semibold<?= $mono ? ' font-monospace' : '' ?>"><?= h($v) ?></div>
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary ede-copy-btn" data-ede-value="<?= h($v) ?>" title="Kopiuj"><i class="bi bi-clipboard"></i></button>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="button" class="btn btn-primary btn-sm" id="edeHintBtn" data-ede-all="Adres do doręczeń (ADE): <?= h($ede_ade) ?>&#10;E-mail: <?= h($ede_email) ?>&#10;Telefon: <?= h($ede_phone) ?>"><i class="bi bi-magic me-1"></i>Podpowiedz dane</button>
          <a href="https://app.edopost.pl/login" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Zaloguj do eDoPost</a>
          <span id="edeCopyStatus" class="align-self-center text-success small d-none"><i class="bi bi-check-circle me-1"></i>Skopiowano</span>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/ezd_rsign.php'; ?>

<script>
document.addEventListener('alpine:init', function () {
  Alpine.data('ezdDash', function (cfg) {
    return {
      cfg: cfg,
      section: 'dzialanie', filter: 'all', sub: 'cases', rsign: 'idle', rejectOpen: false,
      preview: { open: false, url: '', name: '', znak: '', ctx: '', req: 0, from: '', href: '', zal: 0 },
      sign: { open: false, state: 'loading', zal: 0, name: '', znak: '', req: 0, from: '', hash: '', signed: false, error: '' },
      init: function () {
        var h = (location.hash || '').slice(1);
        if (cfg.sections.indexOf(h) >= 0) this.section = h;
        var self = this;
        window.addEventListener('hashchange', function () { var h2 = (location.hash || '').slice(1); if (cfg.sections.indexOf(h2) >= 0) self.section = h2; });
      },
      go: function (s) {
        if (cfg.sections.indexOf(s) < 0) return;
        this.section = s;
        if (history.replaceState) history.replaceState(null, '', '#' + s);
      },
      key: function (e, i) {
        var n = cfg.sections.length, j = null;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') j = (i + 1) % n;
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') j = (i - 1 + n) % n;
        else if (e.key === 'Home') j = 0; else if (e.key === 'End') j = n - 1;
        if (j === null) return;
        e.preventDefault(); this.go(cfg.sections[j]);
        var b = this.$refs['tab-' + cfg.sections[j]]; if (b) b.focus();
      },
      openPreview: function (d) {
        this.rejectOpen = false;
        this.preview = { open: true, url: cfg.serve + d.zal, name: d.name || d.title || 'Dokument', znak: d.znak || '', ctx: d.ctx || '', req: d.req || 0, from: d.from || '', href: d.href || '#', zal: d.zal };
      },
      closePreview: function () { this.preview.open = false; this.rejectOpen = false; },
      startSign: function (d) {
        this.preview.open = false;
        this.sign = { open: true, state: 'loading', zal: d.zal, name: d.name || 'Dokument', znak: d.znak || '', req: d.req || 0, from: d.from || '', hash: '', signed: false, error: '' };
        var self = this;
        fetch(cfg.sign + '?zal_id=' + d.zal + '&info=1', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!self.sign.open || self.sign.zal !== d.zal) return;
            if (j.error) { self.sign.state = 'error'; self.sign.error = j.error; return; }
            self.sign.hash = j.sha256; self.sign.signed = !!j.signed; self.sign.name = j.name || self.sign.name; self.sign.state = 'ready';
          })
          .catch(function () { self.sign.state = 'error'; self.sign.error = 'Nie udało się pobrać skrótu dokumentu z serwera.'; });
      },
      handoffToRsign: function () {
        // Właściwy podpis składa istniejące okno rSign (includes/ezd_rsign.php): kroki, PIN w aplikacji, zapis podpisanej wersji.
        var b = this.$refs.rsignBtn; if (!b) return;
        b.dataset.zalId = String(this.sign.zal); b.dataset.zalName = this.sign.name;
        this.sign.open = false;
        this.$nextTick(function () { b.click(); });
      },
      checkRsign: function () {
        var self = this; this.rsign = 'checking';
        var ctrl = new AbortController(); var t = setTimeout(function () { ctrl.abort(); }, 3000);
        fetch('http://localhost:' + cfg.port + '/', { method: 'HEAD', mode: 'no-cors', signal: ctrl.signal })
          .then(function () { self.rsign = 'ok'; })
          .catch(function () { self.rsign = 'off'; })
          .finally(function () { clearTimeout(t); });
      }
    };
  });
});

(function () {
  // ── eDoręczenia: kopiowanie ──
  var statusEl = document.getElementById('edeCopyStatus');
  function copyText(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(flashOk, fallback); else fallback();
    function fallback() {
      var ta = document.createElement('textarea'); ta.value = text; ta.style.cssText = 'position:fixed;opacity:0';
      document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); flashOk(); } catch (e) {}
      document.body.removeChild(ta);
    }
    function flashOk() { if (!statusEl) return; statusEl.classList.remove('d-none'); clearTimeout(flashOk._t); flashOk._t = setTimeout(function () { statusEl.classList.add('d-none'); }, 2000); }
  }
  document.querySelectorAll('.ede-copy-btn').forEach(function (btn) { btn.addEventListener('click', function () { copyText(btn.getAttribute('data-ede-value') || ''); }); });
  var hintBtn = document.getElementById('edeHintBtn');
  if (hintBtn) hintBtn.addEventListener('click', function () { copyText((hintBtn.getAttribute('data-ede-all') || '').replace(/&#10;/g, '\n')); });

  // ── Kalendarz ──
  var months = ['Styczeń','Luty','Marzec','Kwiecień','Maj','Czerwiec','Lipiec','Sierpień','Wrzesień','Październik','Listopad','Grudzień'];
  var days = ['Pn','Wt','Śr','Cz','Pt','Sb','Nd'];
  var now = new Date(), year = now.getFullYear(), month = now.getMonth();
  function renderCal() {
    var cal = document.getElementById('ezd-cal'); if (!cal) return;
    var first = new Date(year, month, 1), last = new Date(year, month + 1, 0), startDow = (first.getDay() + 6) % 7;
    var h = '<div class="ezdd-cal-nav"><button type="button" id="cal-prev" aria-label="Poprzedni miesiąc"><i class="bi bi-chevron-left"></i></button><span class="ezdd-cal-month">' + months[month] + ' ' + year + '</span><button type="button" id="cal-next" aria-label="Następny miesiąc"><i class="bi bi-chevron-right"></i></button></div><div class="ezdd-cal-grid">';
    days.forEach(function (d) { h += '<div class="ezdd-cal-dow">' + d + '</div>'; });
    for (var i = 0; i < startDow; i++) h += '<div></div>';
    for (var d = 1; d <= last.getDate(); d++) {
      var isToday = (d === now.getDate() && month === now.getMonth() && year === now.getFullYear());
      h += '<div class="ezdd-cal-day' + (isToday ? ' ezdd-cal-today' : '') + '">' + d + '</div>';
    }
    cal.innerHTML = h + '</div>';
    document.getElementById('cal-prev').onclick = function () { month--; if (month < 0) { month = 11; year--; } renderCal(); };
    document.getElementById('cal-next').onclick = function () { month++; if (month > 11) { month = 0; year++; } renderCal(); };
  }
  renderCal();
})();
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

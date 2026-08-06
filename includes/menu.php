<?php
/**
 * includes/menu.php — jedno źródło prawdy dla głównej nawigacji SZO.
 *
 * Buduje tematyczne drzewo menu z uwzględnieniem uprawnień bieżącego użytkownika.
 * Konsumowane przez:
 *   • pasek nawigacji (navbar) w includes/header.php,
 *   • mobilny offcanvas,
 *   • paletę poleceń (Ctrl+K) — indeks wyszukiwania.
 *
 * Struktura węzła najwyższego poziomu:
 *   [
 *     'id'      => 'umowy',
 *     'label'   => 'Umowy',
 *     'icon'    => 'bi-file-text',
 *     'ezd'     => false,        // czerwony akcent (Wirtualne biurko)
 *     'end'     => false,        // dropdown wyrównany do prawej
 *     'badge'   => 3,            // liczbowy badge przy zakładce (0 = brak)
 *     'active'  => bool,
 *     'path'    => '/rodo/…',    // TYLKO dla zakładek-linków (bez rozwijania)
 *     'groups'  => [ ['label'=>?string,'badge'=>?int,'items'=>[item,…]] , … ],
 *   ]
 * Pozycja (item):
 *   ['label','path','icon','badge'=>int,'active'=>bool,'kw'=>'…','danger'=>bool]
 *
 * Ścieżki (`path`) są względne wobec APP_URL — konsumenci dokładają prefiks.
 */

if (!function_exists('current_user')) require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

/** Czy fragment REQUEST_URI pasuje (podświetlenie aktywnej pozycji). */
function _menu_hit(string $needle): bool {
    return $needle !== '' && str_contains($_SERVER['REQUEST_URI'] ?? '', $needle);
}

/** Zbuduj pozycję menu. $opts: match|badge|kw|danger. */
function _mi(string $label, string $path, string $icon, array $opts = []): array {
    $match = $opts['match'] ?? $path;
    return [
        'label'  => $label,
        'path'   => $path,
        'icon'   => $icon,
        'badge'  => (int)($opts['badge'] ?? 0),
        'kw'     => (string)($opts['kw'] ?? ''),
        'danger' => (bool)($opts['danger'] ?? false),
        'attr'   => (string)($opts['attr'] ?? ''),   // dodatkowe atrybuty na badge (np. data-msg-sb-badge)
        'active' => _menu_hit($match),
    ];
}

/**
 * Liczniki/badge — policzone raz na request. Odwzorowuje logikę z header.php,
 * każde zapytanie w try/catch, aby brak tabeli nie wywracał nagłówka.
 */
function _menu_counts(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = [
        'pending'=>0,'msg'=>0,'term'=>0,'cert'=>0,'ts'=>0,'ship'=>0,'has_shipping'=>false,
        'zwr'=>0,'rek'=>0,'ob'=>0,'res'=>0,'adm'=>0,'hd'=>0,'alias'=>0,'alias_op'=>false,
        'obieg'=>0,'vpn'=>0,'wsparcie_ou'=>0,'has_kdok'=>false,
    ];
    if (is_admin()) {
        try { require_once __DIR__ . '/amendments.php'; $c['pending'] = get_workflow_pending_count(); } catch (\Throwable $e) {}
    }
    try { $c['msg']  = msg_unread_admin(); } catch (\Throwable $e) {}
    try { require_once __DIR__ . '/termination.php';  $c['term'] = get_pending_terminations_count(); } catch (\Throwable $e) {}
    try { require_once __DIR__ . '/certificates.php'; $c['cert'] = get_pending_certificates_count(); } catch (\Throwable $e) {}
    try { require_once __DIR__ . '/timesheets.php';   $c['ts']   = ts_pending_count(); } catch (\Throwable $e) {}
    try {
        require_once __DIR__ . '/apaczka.php'; require_once __DIR__ . '/furgonetka.php';
        $c['ship'] = shipment_pending_count();
        $c['has_shipping'] = apaczka_setting('apaczka_enabled') !== '0' || furgonetka_enabled();
    } catch (\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM zwroty_kosztow WHERE status IN ('oczekuje','weryfikacja')"); $c['zwr'] = (int)($r['c'] ?? 0); } catch (\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM volunteer_applications WHERE status = 'new'"); $c['rek'] = (int)($r['c'] ?? 0); } catch (\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM onboarding_volunteers WHERE status='pending'"); $c['ob'] = (int)($r['c'] ?? 0); } catch (\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM resource_reservations WHERE status IN ('zlozony','pending_admin')"); $c['res'] = (int)($r['c'] ?? 0); } catch (\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM mail_queue WHERE status='failed'"); $c['adm'] += (int)($r['c'] ?? 0); } catch (\Throwable $e) {}
    try { $r = db_one("SELECT COUNT(*) AS c FROM user_applications WHERE status='nowy'"); $c['adm'] += (int)($r['c'] ?? 0); } catch (\Throwable $e) {}
    try {
        if (module_enabled('helpdesk_enabled') && ($u = current_user())) {
            if (is_admin() || !empty($u['helpdesk_operator']))
                $c['hd'] = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE status NOT IN ('zamknięte')")['c'] ?? 0);
            else
                $c['hd'] = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')", [(int)$u['id']])['c'] ?? 0);
        }
    } catch (\Throwable $e) {}
    $c['alias_op'] = is_admin() || !empty(current_user()['helpdesk_operator']);
    if ($c['alias_op']) {
        try { $c['alias'] = (int)(db_one("SELECT COUNT(*) AS c FROM email_alias_requests WHERE status IN ('oczekuje','błąd')")['c'] ?? 0); } catch (\Throwable $e) {}
    }
    try {
        if (module_enabled('obiegi_enabled') && ($u = current_user())) {
            require_once __DIR__ . '/obiegi.php';
            $c['obieg'] = obiegi_inbox_count($u);
        }
    } catch (\Throwable $e) {}
    try {
        if (is_admin() && module_enabled('vpn_enabled')) {
            require_once __DIR__ . '/vpn.php';
            $c['vpn'] = vpn_pending_count();
        }
    } catch (\Throwable $e) {}
    try {
        if (module_enabled('wsparcie_ou_enabled') && can_write('wsparcie_ou')) {
            require_once __DIR__ . '/wsparcie_ou.php';
            $c['wsparcie_ou'] = wsparcie_ou_pending_count();
        }
    } catch (\Throwable $e) {}
    try {
        require_once __DIR__ . '/ksiegowosc.php'; kdok_migrate();
        $c['has_kdok'] = kdok_has_role('upload') || kdok_has_role('meryt') || kdok_has_role('formal') || kdok_has_role('zatwierdza') || is_admin();
    } catch (\Throwable $e) {}
    return $c;
}

/** Ikony typów umów. */
function _menu_contract_icons(): array {
    return [
        'zlecenie'=>'bi-person-lines-fill','uslugi'=>'bi-briefcase','wolontariat'=>'bi-heart',
        'dzielo'=>'bi-palette','praca'=>'bi-building','powierzenie'=>'bi-bank','inne'=>'bi-file-text',
    ];
}

/** Odfiltruj puste grupy (bez pozycji) i węzły bez treści. */
function _menu_prune(array $nodes): array {
    $out = [];
    foreach ($nodes as $n) {
        if (!empty($n['path']) && empty($n['groups'])) { $out[] = $n; continue; } // zakładka-link
        $groups = [];
        foreach (($n['groups'] ?? []) as $g) {
            if (!empty($g['items'])) $groups[] = $g;
        }
        if (!$groups) continue;
        $n['groups'] = $groups;
        // aktywność zakładki = jawna lub dowolna aktywna pozycja
        if (empty($n['active'])) {
            foreach ($groups as $g) foreach ($g['items'] as $it) if (!empty($it['active'])) { $n['active'] = true; break 2; }
        }
        $out[] = $n;
    }
    return $out;
}

// ── Widok EZD-only (rola ezd_user) ───────────────────────────────────────────
function _menu_ezd(): array {
    if (!module_enabled('ezd_enabled')) return [];
    return [[
        'id'=>'ezd','label'=>'Wirtualne biurko','icon'=>'bi-building-gear','ezd'=>true,
        'active'=>_menu_hit('/ezd/'),'groups'=>[['label'=>null,'items'=>_menu_ezd_items()]],
    ]];
}
function _menu_ezd_items(): array {
    return [
        _mi('Pulpit EZD','/ezd/index.php','bi-building-gear',['match'=>'/ezd/index.php','kw'=>'ezd biurko']),
        _mi('Poczta EZD','/ezd/poczta/index.php','bi-envelope-fill',['match'=>'/ezd/poczta/','kw'=>'poczta email korespondencja inbox']),
        _mi('Dziennik podawczy','/ezd/rpw/index.php','bi-mailbox2',['match'=>'/ezd/rpw/','kw'=>'rpw korespondencja wpływ']),
        _mi('Koszulki','/ezd/sprawy/index.php','bi-folder2-open',['match'=>'/ezd/sprawy/','kw'=>'sprawa koszulka']),
        _mi('Segregatory aktowe','/ezd/teczki/index.php','bi-archive',['match'=>'/ezd/teczki/','kw'=>'teczka segregator akta']),
        _mi('Wykaz akt (JRWA)','/ezd/jrwa/index.php','bi-tags',['match'=>'/ezd/jrwa/','kw'=>'jrwa wykaz akt klasyfikacja']),
        _mi('Pełnomocnictwa','/ezd/pelnomocnictwa/index.php','bi-person-vcard',['match'=>'/ezd/pelnomocnictwa/']),
        _mi('Zaświadczenia','/ezd/zaswiadczenia/index.php','bi-award',['match'=>'/ezd/zaswiadczenia/']),
        _mi('Wolontariusze bez umowy','/ezd/wolontariusze/index.php','bi-heart',['match'=>'/ezd/wolontariusze/']),
        _mi('Terminarz','/ezd/terminarz.php','bi-calendar3',['match'=>'/ezd/terminarz','kw'=>'terminarz kalendarz terminy']),
        _mi('Do podpisu','/ezd/podpis/index.php','bi-pen',['match'=>'/ezd/podpis/','kw'=>'podpis dokumenty']),
    ];
}

// ── Widok wolontariusza / użytkownika (viewer) ───────────────────────────────
function _menu_viewer(): array {
    $items = [];
    $items[] = _mi('Moja umowa','/panel/index.php','bi-person-circle',['match'=>'/panel/index','kw'=>'panel umowa']);

    $unread_rules = 0;
    try { require_once __DIR__ . '/org_rules.php'; org_rules_migrate();
        $unread_rules = count(org_rules_unread((int)(current_user()['id'] ?? 0))); } catch (\Throwable $e) {}
    if (panel_visible('zasady'))
        $items[] = _mi('Zasady organizacji','/org_intro/index.php','bi-building-heart',['match'=>'/org_intro/','badge'=>$unread_rules,'kw'=>'zasady regulamin']);

    $items[] = _mi('Mój profil','/panel/profile_edit.php','bi-person-badge',['match'=>'/panel/profile_edit']);
    if (panel_visible('komunikaty'))
        $items[] = _mi('Komunikaty','/komunikaty/index.php','bi-megaphone',['match'=>'/komunikaty/']);
    if (panel_visible('katalog'))
        $items[] = _mi('Książka telefoniczna','/directory/','bi-person-lines-fill',['match'=>'/directory/','kw'=>'katalog telefon kontakty']);

    $pnl_unread = 0;
    if (!empty($_SESSION['panel_contract'])) {
        try { $pnl_unread = msg_unread_thread('contract', (int)$_SESSION['panel_contract']['id'], 'user'); } catch (\Throwable $e) {}
    }
    if (module_enabled('messages_enabled') && panel_visible('wiadomosci'))
        $items[] = _mi('Wiadomości','/panel/messages.php','bi-chat-left-text',['match'=>'/panel/messages','badge'=>$pnl_unread]);
    if (module_enabled('letters_enabled') && panel_visible('pisma'))
        $items[] = _mi('Moje pisma','/panel/letters.php','bi-archive',['match'=>'/panel/letters','kw'=>'pisma']);
    if (panel_visible('wnioski'))
        $items[] = _mi('Wyślij pismo / wniosek','/panel/apply.php','bi-send',['match'=>'/panel/apply','kw'=>'wniosek pismo']);
    if (module_enabled('certificates_enabled') && panel_visible('zaswiadczenia'))
        $items[] = _mi('Zaświadczenia','/panel/certificates.php','bi-award',['match'=>'/panel/certificates']);
    if (module_enabled('terminations_enabled') && panel_visible('rozwiazanie'))
        $items[] = _mi('Rozwiązanie umowy','/panel/terminations.php','bi-file-earmark-x',['match'=>'/panel/terminations']);
    if (module_enabled('timesheets_enabled') && panel_visible('godziny'))
        $items[] = _mi('Ewidencja godzin','/panel/timesheets.php','bi-clock-history',['match'=>'/panel/timesheets','kw'=>'godziny czas']);
    if (module_enabled('moodle_enabled') && panel_visible('kursy'))
        $items[] = _mi('Moje kursy','/panel/moodle.php','bi-mortarboard',['match'=>'/panel/moodle','kw'=>'kursy szkolenia moodle']);

    $account = [
        _mi('System Tożsamości','/tozsamosc/index.php','bi-person-vcard-fill',['match'=>'/tozsamosc/','kw'=>'tożsamość konto dostępy hasło']),
        _mi('Microsoft 365','/panel/m365.php','bi-microsoft',['match'=>'/panel/m365','kw'=>'m365 office']),
        _mi('Sesje i bezpieczeństwo','/panel/sessions.php','bi-shield-lock',['match'=>'/panel/sessions']),
        _mi('Ustawienia konta','/panel/password.php','bi-gear',['match'=>'/panel/password','kw'=>'hasło 2fa ustawienia']),
    ];

    $badge = $unread_rules + $pnl_unread;
    return [[
        'id'=>'panel','label'=>'Mój panel','icon'=>'bi-person-circle','badge'=>$badge,
        'active'=>_menu_hit('/panel/')||_menu_hit('/org_intro/')||_menu_hit('/komunikaty/')||_menu_hit('/directory/'),
        'groups'=>[
            ['label'=>null,'items'=>$items],
            ['label'=>'Konto','items'=>$account],
        ],
    ]];
}

// ── Widok edytora / admina ───────────────────────────────────────────────────
function _menu_editor(): array {
    $cnt  = _menu_counts();
    $cic  = _menu_contract_icons();
    $ct_label = fn($s) => CONTRACT_TYPES[$s] ?? ucfirst($s);
    $ct_icon  = fn($s) => $cic[$s] ?? 'bi-file-text';
    $nodes = [];

    // ══ UMOWY ══════════════════════════════════════════════════════════════════
    $um_groups = [
        ['Wolontariat',                ['wolontariat']],
        ['Zlecenie / Praca / Dzieło',  ['zlecenie','praca','dzielo']],
        ['Inne umowy',                 ['uslugi','powierzenie','inne']],
    ];
    $um_g = [];
    foreach ($um_groups as [$glabel, $slugs]) {
        $vis = array_values(array_filter($slugs, fn($s) => module_enabled('contract_' . $s)));
        if (!$vis) continue;
        $items = [];
        foreach ($vis as $s)
            $items[] = _mi($ct_label($s), "/contracts/$s/list.php", $ct_icon($s), ['match'=>"/contracts/$s/", 'kw'=>"umowa $s"]);
        $um_g[] = ['label'=>$glabel,'items'=>$items];
    }
    $obs = [ _mi('Rejestr umów (RU)','/contracts/rejestr.php','bi-journal-text',['match'=>'/contracts/rejestr','kw'=>'rejestr ru numer']) ];
    if (module_enabled('approvals_enabled'))
        $obs[] = _mi('Akceptacje','/contracts/approvals/index.php','bi-check2-square',['match'=>'/contracts/approvals/','badge'=>$cnt['pending'],'kw'=>'akceptacja workflow']);
    if (module_enabled('letters_enabled'))
        $obs[] = _mi('Pisma','/contracts/letters/index.php','bi-envelope-paper',['match'=>'/contracts/letters/','kw'=>'pisma korespondencja']);
    if (module_enabled('terminations_enabled'))
        $obs[] = _mi('Rozwiązania','/admin/terminations.php','bi-file-earmark-x',['match'=>'/admin/terminations','badge'=>$cnt['term'],'kw'=>'rozwiązanie umowy']);
    $um_g[] = ['label'=>'Obsługa umów','items'=>$obs];
    $nodes[] = [
        'id'=>'umowy','label'=>'Umowy','icon'=>'bi-file-text','badge'=>$cnt['pending']+$cnt['term'],
        'active'=>(_menu_hit('/contracts/') && !_menu_hit('/contracts/wolontariat') && !_menu_hit('/contracts/rekrutacja'))
                 || _menu_hit('/contracts/approvals') || _menu_hit('/contracts/letters') || _menu_hit('/contracts/rejestr') || _menu_hit('/admin/terminations'),
        'groups'=>$um_g,
    ];

    // ══ WOLONTARIAT ════════════════════════════════════════════════════════════
    $wol = [];
    if (module_enabled('contract_wolontariat'))
        $wol[] = _mi('Wolontariusze','/contracts/wolontariat/list.php','bi-heart',['match'=>'/contracts/wolontariat/','kw'=>'wolontariat porozumienie']);
    $wol[] = _mi('Zgłoszenia','/contracts/rekrutacja/index.php','bi-megaphone',['match'=>'/contracts/rekrutacja/','badge'=>$cnt['rek'],'kw'=>'rekrutacja kandydat']);
    if (module_enabled('onboarding_enabled'))
        $wol[] = _mi('Onboarding','/onboarding/index.php','bi-person-check',['match'=>'/onboarding/','badge'=>$cnt['ob']]);
    if (module_enabled('timesheets_enabled'))
        $wol[] = _mi('Ewidencja godzin','/admin/timesheets.php','bi-clock-history',['match'=>'/admin/timesheets','badge'=>$cnt['ts'],'kw'=>'godziny czas']);
    if (module_enabled('certificates_enabled'))
        $wol[] = _mi('Zaświadczenia','/admin/certificates.php','bi-award',['match'=>'/admin/certificates','badge'=>$cnt['cert'],'kw'=>'zaświadczenie zawol']);
    $nodes[] = [
        'id'=>'wolontariat','label'=>'Wolontariat','icon'=>'bi-heart','badge'=>$cnt['rek']+$cnt['ob']+$cnt['ts']+$cnt['cert'],
        'active'=>_menu_hit('/contracts/wolontariat/')||_menu_hit('/contracts/rekrutacja/')||_menu_hit('/onboarding/')||_menu_hit('/admin/timesheets')||_menu_hit('/admin/certificates'),
        'groups'=>[['label'=>null,'items'=>$wol]],
    ];

    // ══ FINANSE ════════════════════════════════════════════════════════════════
    $fin = [];
    if (menu_visible('grants'))
        $fin[] = _mi('Granty','/grants/index.php','bi-cash-coin',['match'=>'/grants/','kw'=>'grant dotacja']);
    if (menu_visible('actions'))
        $fin[] = _mi('Działania','/strategy/actions/index.php','bi-calendar-event',['match'=>'/strategy/actions/','kw'=>'działania projekt']);
    if (can_read('umowy') || is_admin())
        $fin[] = _mi('Strategia','/strategy/index.php','bi-bullseye',['match'=>'/strategy/index','kw'=>'strategia cele']);
    $fin[] = _mi('Zwroty kosztów','/contracts/zwroty/index.php','bi-receipt-cutoff',['match'=>'/contracts/zwroty/','badge'=>$cnt['zwr'],'kw'=>'zwrot koszty refundacja']);
    if ($cnt['has_kdok']) {
        $fin[] = _mi('EOD Dok. Księgowych','/ksiegowosc/index.php','bi-file-earmark-check',['match'=>'/ksiegowosc/index','kw'=>'księgowość faktury dokumenty']);
        if (kdok_has_role('zatwierdza'))
            $fin[] = _mi('Preliminarz Płatności','/ksiegowosc/preliminarz.php','bi-calendar-check',['match'=>'/ksiegowosc/preliminarz','kw'=>'preliminarz płatności przelew']);
    }
    $fin[] = _mi('Zasoby','/resources/','bi-box-seam',['match'=>'/resources/','badge'=>$cnt['res'],'kw'=>'zasoby rezerwacje sprzęt']);
    if ($cnt['has_shipping'])
        $fin[] = _mi('Przesyłki','/admin/shipments.php','bi-truck',['match'=>'/admin/shipments','badge'=>$cnt['ship'],'kw'=>'przesyłki kurier apaczka']);
    $nodes[] = [
        'id'=>'finanse','label'=>'Finanse','icon'=>'bi-cash-coin','badge'=>$cnt['zwr']+$cnt['res']+$cnt['ship'],
        'active'=>_menu_hit('/contracts/zwroty')||_menu_hit('/grants/')||_menu_hit('/strategy/')||_menu_hit('/ksiegowosc/')||_menu_hit('/resources/')||_menu_hit('/admin/shipments'),
        'groups'=>[['label'=>null,'items'=>$fin]],
    ];

    // ══ LUDZIE ═════════════════════════════════════════════════════════════════
    $ludzie = [ _mi('Osoby','/persons/index.php','bi-people',['match'=>'/persons/','kw'=>'osoby rejestr']) ];
    if (module_enabled('crm_enabled') && can_read('crm'))
        $ludzie[] = _mi('CRM','/crm/dashboard.php','bi-diagram-2',['match'=>'/crm/','kw'=>'crm kontakt klient']);
    $ludzie[] = _mi('Katalog osób','/directory/','bi-person-lines-fill',['match'=>'/directory/','kw'=>'katalog telefon kontakty']);
    if (module_enabled('org_enabled'))
        $ludzie[] = _mi('Struktura org.','/org/index.php','bi-diagram-3',['match'=>'/org/','kw'=>'struktura organizacja schemat']);
    if (module_enabled('byli_enabled'))
        $ludzie[] = _mi('Byłe osoby','/byli/index.php','bi-person-dash',['match'=>'/byli/','kw'=>'byli archiwum']);
    $nodes[] = [
        'id'=>'ludzie','label'=>'Ludzie','icon'=>'bi-people',
        'active'=>_menu_hit('/persons/')||_menu_hit('/crm/')||_menu_hit('/directory/')||_menu_hit('/org/')||_menu_hit('/byli/'),
        'groups'=>[['label'=>null,'items'=>$ludzie]],
    ];

    // ══ BIURO (obsługa + kancelaria + formularze) ══════════════════════════════
    $obs_g = [];
    if (module_enabled('obiegi_enabled'))
        $obs_g[] = _mi('Obiegi','/obiegi/index.php','bi-diagram-2',['match'=>'/obiegi/','badge'=>$cnt['obieg'],'kw'=>'obiegi bpm procesy']);
    if (module_enabled('tasks_enabled'))
        $obs_g[] = _mi('Zadania','/tasks/dashboard.php','bi-kanban',['match'=>'/tasks/','kw'=>'zadania tablica kanban']);
    if (module_enabled('helpdesk_enabled')) {
        $obs_g[] = _mi('Helpdesk','/helpdesk/index.php','bi-ticket-perforated',['match'=>'/helpdesk/','badge'=>$cnt['hd'],'kw'=>'helpdesk zgłoszenie it wsparcie']);
        if ($cnt['alias_op'])
            $obs_g[] = _mi('Aliasy e-mail','/admin/email_aliasy.php','bi-at',['match'=>'/admin/email_aliasy','badge'=>$cnt['alias'],'kw'=>'alias email']);
    }
    if (module_enabled('messages_enabled'))
        $obs_g[] = _mi('Wiadomości','/admin/messages.php','bi-chat-dots',['match'=>'/admin/messages','badge'=>$cnt['msg'],'kw'=>'wiadomości czat','attr'=>'data-msg-sb-badge']);
    if (module_enabled('events_enabled'))
        $obs_g[] = _mi('Wydarzenia','/events/dashboard.php','bi-calendar-event',['match'=>'/events/','kw'=>'wydarzenia kalendarz']);
    if (module_enabled('poczta_enabled'))
        $obs_g[] = _mi('Poczta','/poczta/dashboard.php','bi-envelope',['match'=>'/poczta/','kw'=>'poczta email']);

    $kanc = [];
    if (module_enabled('reports_enabled'))
        $kanc[] = _mi('Raporty','/reports/index.php','bi-bar-chart-line',['match'=>'/reports/','kw'=>'raporty statystyki']);
    $kanc[] = _mi('Korespondencja','/correspondence/index.php','bi-mailbox',['match'=>'/correspondence/','kw'=>'korespondencja listy']);
    $kanc[] = _mi('Procedury','/procedures/index.php','bi-list-task',['match'=>'/procedures/index','kw'=>'procedury instrukcje']);
    $kanc[] = _mi('Asystent AI (procedury)','/procedures/asystent.php','bi-robot',['match'=>'/procedures/asystent','kw'=>'asystent ai wyszukiwanie procedury dokumentacja chat pytania']);
    if (module_enabled('wsparcie_ou_enabled') && can_read('wsparcie_ou'))
        $kanc[] = _mi('Wsparcie zewnętrzne OU','/wsparcie_ou/index.php','bi-building-add',['match'=>'/wsparcie_ou/','badge'=>$cnt['wsparcie_ou'],'kw'=>'wsparcie ou podmioty zewnętrzne']);
    if (module_enabled('doc_signing_enabled'))
        $kanc[] = _mi('Podpisz dokument','/podpisy/index.php','bi-pen',['match'=>'/podpisy/','kw'=>'podpis dokument']);
    if (module_enabled('org_documents_enabled'))
        $kanc[] = _mi('Dokumenty organizacji','/admin/org_documents.php','bi-folder2-open',['match'=>'/admin/org_documents','kw'=>'dokumenty organizacji']);
    $kanc[] = _mi('Uchwały','/resolutions/index.php','bi-file-ruled',['match'=>'/resolutions/','kw'=>'uchwały zarząd']);

    $forms = [];
    if (module_enabled('dostepnosc_ngo_enabled') && !is_viewer()) {
        $forms[] = _mi('Dostępność NGO','/dostepnosc/admin.php','bi-universal-access',['match'=>'/dostepnosc/','kw'=>'dostępność ngo']);
        $forms[] = _mi('Zgłoszenia asysty','/asysta/admin.php','bi-universal-access-circle',['match'=>'/asysta/','kw'=>'asysta']);
    }
    if (module_enabled('projekty_enabled') && !is_viewer())
        $forms[] = _mi('Karty doradztwa ADNGO','/extforms/konsultacjeADNGO/admin.php','bi-clipboard2-pulse',['match'=>'/extforms/konsultacjeADNGO/','kw'=>'adngo doradztwo konsultacje']);

    $nodes[] = [
        'id'=>'biuro','label'=>'Biuro','icon'=>'bi-briefcase','badge'=>$cnt['obieg']+$cnt['hd']+$cnt['msg']+($cnt['alias_op']?$cnt['alias']:0),
        'end'=>true,
        'active'=>_menu_hit('/obiegi/')||_menu_hit('/tasks/')||_menu_hit('/helpdesk/')||_menu_hit('/admin/email_aliasy')||_menu_hit('/admin/messages')||_menu_hit('/events/')||_menu_hit('/poczta/')
                 ||_menu_hit('/reports/')||_menu_hit('/correspondence/')||_menu_hit('/procedures/')||_menu_hit('/wsparcie_ou/')||_menu_hit('/podpisy/')||_menu_hit('/admin/org_documents')||_menu_hit('/resolutions/')
                 ||_menu_hit('/dostepnosc/')||_menu_hit('/asysta/')||_menu_hit('/extforms/'),
        'groups'=>[
            ['label'=>'Obsługa','badge'=>$cnt['obieg']+$cnt['hd']+$cnt['msg'],'items'=>$obs_g],
            ['label'=>'Kancelaria i rejestry','items'=>$kanc],
            ['label'=>'Formularze zewnętrzne','items'=>$forms],
        ],
    ];

    // ══ IT ═════════════════════════════════════════════════════════════════════
    $it = [
        _mi('Dashboard IT','/it/index.php','bi-grid-1x2',['match'=>'/it/index','kw'=>'it dashboard']),
        _mi('Konta','/it/accounts.php','bi-person-badge',['match'=>'/it/accounts','kw'=>'konta it']),
        _mi('Hasła','/it/passwords.php','bi-key',['match'=>'/it/passwords','kw'=>'hasła sejf']),
        _mi('Upload do R2','/tools/r2_upload.php','bi-cloud-arrow-up',['match'=>'/tools/r2_upload','kw'=>'r2 upload pliki']),
    ];
    if (module_enabled('vpn_enabled'))
        $it[] = _mi('Dostęp VPN','/vpn/index.php','bi-shield-lock',['match'=>'/vpn/','kw'=>'vpn dostęp']);
    if (is_admin()) {
        $it[] = _mi('Serwisy IT','/it/services.php','bi-gear',['match'=>'/it/services','kw'=>'serwisy usługi it']);
        if (module_enabled('vpn_enabled'))
            $it[] = _mi('VPN — dostępy','/admin/vpn.php','bi-shield-check',['match'=>'/admin/vpn','badge'=>$cnt['vpn'],'kw'=>'vpn dostępy admin']);
        if (module_enabled('cloudflare_enabled'))
            $it[] = _mi('Cloudflare DNS','/admin/cloudflare_dns.php','bi-globe2',['match'=>'/admin/cloudflare_','kw'=>'cloudflare dns domeny rekordy']);
    }
    $nodes[] = [
        'id'=>'it','label'=>'IT','icon'=>'bi-hdd-network',
        'active'=>_menu_hit('/it/')||_menu_hit('/tools/')||_menu_hit('/vpn/')||_menu_hit('/admin/cloudflare_'),
        'groups'=>[['label'=>null,'items'=>$it]],
    ];

    // ══ WIRTUALNE BIURKO (EZD) ═════════════════════════════════════════════════
    if (module_enabled('ezd_enabled') && (can_read('ezd') || can_write('ezd'))) {
        $nodes[] = [
            'id'=>'ezd','label'=>'Wirtualne biurko','icon'=>'bi-building-gear','ezd'=>true,
            'active'=>_menu_hit('/ezd/'),
            'groups'=>[['label'=>null,'items'=>_menu_ezd_items()]],
        ];
    }

    // ══ RODO (zakładka-link) ═══════════════════════════════════════════════════
    $nodes[] = [
        'id'=>'rodo','label'=>'RODO','icon'=>'bi-shield-lock','path'=>'/rodo/index.php',
        'kw'=>'rodo dane osobowe upoważnienia','active'=>_menu_hit('/rodo/'),
    ];

    // ══ ADMIN ══════════════════════════════════════════════════════════════════
    if (is_admin()) {
        $adm_main = [ _mi('Panel admina','/admin/index.php','bi-shield-shaded',['match'=>'/admin/index','kw'=>'admin panel ustawienia']) ];
        $integ = [
            _mi('Microsoft 365','/admin/m365_settings.php','bi-microsoft',['match'=>'/admin/m365','kw'=>'m365 integracja graph']),
            _mi('SharePoint','/admin/sharepoint_settings.php','bi-cloud-upload',['match'=>'/admin/sharepoint','kw'=>'sharepoint']),
            _mi('Szkolenia (TidyCal)','/admin/tidycal_settings.php','bi-calendar2-check',['match'=>'/admin/tidycal','kw'=>'tidycal szkolenia']),
            _mi('Płatności / Stripe','/admin/stripe_settings.php','bi-credit-card',['match'=>'/admin/stripe_settings','kw'=>'stripe płatności']),
            _mi('Płatności / PayU','/admin/payu_settings.php','bi-wallet2',['match'=>'/admin/payu_settings','kw'=>'payu płatności']),
            _mi('Magazyn plików / ownCloud','/admin/owncloud_settings.php','bi-cloud-arrow-up',['match'=>'/admin/owncloud_settings','kw'=>'owncloud magazyn']),
            _mi('API i webhooki','/admin/api_manage.php','bi-key',['match'=>'/admin/api_manage','kw'=>'api webhooki klucze']),
        ];
        // aktywność (jak w header): /admin/ poza sekcjami przeniesionymi indziej
        $admin_active = _menu_hit('/admin/') && !_menu_hit('/admin/messages') && !_menu_hit('/admin/terminations')
            && !_menu_hit('/admin/certificates') && !_menu_hit('/admin/timesheets') && !_menu_hit('/admin/shipments') && !_menu_hit('/admin/onboarding');
        $nodes[] = [
            'id'=>'admin','label'=>'Admin','icon'=>'bi-shield-shaded','badge'=>$cnt['adm'],'end'=>true,
            'active'=>$admin_active,
            'groups'=>[
                ['label'=>null,'items'=>$adm_main],
                ['label'=>'Integracje','items'=>$integ],
            ],
        ];
    }

    return _menu_prune($nodes);
}

/**
 * Zbuduj drzewo menu dla bieżącego użytkownika.
 * Zwraca ['mode'=>'ezd|viewer|editor|guest','tree'=>[…]].
 */
function menu_build(): array {
    $u = current_user();
    if (!$u)                                                   return ['mode'=>'guest','tree'=>[]];
    if (function_exists('is_ezd_only') && is_ezd_only())       return ['mode'=>'ezd','tree'=>_menu_prune(_menu_ezd())];
    if (!can_edit())                                           return ['mode'=>'viewer','tree'=>_menu_prune(_menu_viewer())];
    return ['mode'=>'editor','tree'=>_menu_editor()];
}

/**
 * Spłaszcza drzewo do indeksu wyszukiwania palety poleceń.
 * Zwraca listę: ['label','sub','path','icon','badge','danger','kw'].
 */
function menu_search_index(array $tree): array {
    $out = [];
    foreach ($tree as $node) {
        // zakładka-link
        if (!empty($node['path']) && empty($node['groups'])) {
            $out[] = [
                'label'=>$node['label'],'sub'=>'','path'=>$node['path'],
                'icon'=>$node['icon'],'badge'=>0,'danger'=>false,'kw'=>$node['kw'] ?? '',
            ];
            continue;
        }
        foreach (($node['groups'] ?? []) as $g) {
            $crumb = $node['label'] . ($g['label'] ? ' · ' . $g['label'] : '');
            foreach ($g['items'] as $it) {
                $out[] = [
                    'label'=>$it['label'],'sub'=>$crumb,'path'=>$it['path'],
                    'icon'=>$it['icon'],'badge'=>(int)$it['badge'],'danger'=>!empty($it['danger']),
                    'kw'=>trim($crumb . ' ' . ($it['kw'] ?? '')),
                ];
            }
        }
    }
    return $out;
}

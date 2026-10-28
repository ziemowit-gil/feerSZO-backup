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
 *
 * ZASADA PODZIAŁU (od 25.08.2026): moduł z WŁASNĄ nawigacją ma w pasku SZO
 * DOKŁADNIE JEDNO wejście — do swojego pulpitu. Jego wnętrze opisuje jego własne
 * menu, a nie to. Powtórzone listy rozjeżdżały się kolejnością i zawartością,
 * więc uczyły, że „to samo bywa gdzie indziej".
 *
 * Dotyczy: CRM, Wirtualne biurko (EZD), Zadania, Poczta, Katalog, Strategia,
 * Wydarzenia, Panel współpracownika, Tożsamość, TI (karty30).
 *
 * Pozycje zdjęte z paska NIE znikają z systemu: trafiają do klucza `search`
 * węzła i są dalej wyszukiwalne w palecie Ctrl+K. Dodając nową pozycję do
 * modułu, dopisuj ją TAM, nie w pasku.
 */

if (!function_exists('current_user')) require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/asystent_ai.php';   // asai_enabled() — pozycja „Asystent AI" w menu

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
    // Cache Redis ~45 s na użytkownika i jego rolę (z nakładką kontekstu).
    // Grupa 'menu' jest unieważniana po każdym zapisie (POST…), więc po akcji
    // i przekierowaniu liczniki są od razu aktualne.
    if (function_exists('szo_cache_remember')) {
        $u   = function_exists('current_user') ? (current_user() ?: []) : [];
        $key = szo_cache_gkey('menu', 'counts:u' . (int)($u['id'] ?? 0) . ':' . md5(
            (string)($u['role'] ?? '') . '|' . (is_admin() ? 1 : 0) . '|' . (int)!empty($u['helpdesk_operator'])));
        $r = szo_cache_remember($key, 45, '_menu_counts_compute');
        if (is_array($r) && isset($r['pending'])) return $c = $r;
    }
    return $c = _menu_counts_compute();
}

/** Liczniki policzone na świeżo (bez cache). */
function _menu_counts_compute(): array {
    $c = [
        'pending'=>0,'msg'=>0,'term'=>0,'cert'=>0,'ts'=>0,'ship'=>0,'has_shipping'=>false,
        'zwr'=>0,'rek'=>0,'ob'=>0,'res'=>0,'adm'=>0,'hd'=>0,'alias'=>0,'alias_op'=>false,
        'obieg'=>0,'vpn'=>0,'wsparcie_ou'=>0,'has_kdok'=>false,'has_edok'=>false,
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
    try { $r = db_one("SELECT COUNT(*) AS c FROM user_applications WHERE status='nowy'"); $c['app'] = (int)($r['c'] ?? 0); $c['adm'] += $c['app']; } catch (\Throwable $e) {}
    try {
        if (module_enabled('helpdesk_enabled') && ($u = current_user())) {
            // Tryb „tylko frontend": brak operatorów w SZO → każdy widzi własne zgłoszenia.
            $hd_fe = org_setting('helpdesk_frontend_only') === '1';
            if (!$hd_fe && (is_admin() || !empty($u['helpdesk_operator'])))
                $c['hd'] = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE status NOT IN ('zamknięte')")['c'] ?? 0);
            else
                $c['hd'] = (int)(db_one("SELECT COUNT(*) AS c FROM helpdesk_tickets WHERE requester_id=? AND status NOT IN ('zamknięte','rozwiązane')", [(int)$u['id']])['c'] ?? 0);
        }
    } catch (\Throwable $e) {}
    $c['alias_op'] = (org_setting('helpdesk_frontend_only') !== '1') && (is_admin() || !empty(current_user()['helpdesk_operator']));
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
    try {
        require_once __DIR__ . '/edok.php'; edok_migrate();
        $c['has_edok'] = edok_has_any_role();
    } catch (\Throwable $e) {}
    return $c;
}

/** Ikony typów umów. */
function _menu_contract_icons(): array {
    return [
        'zlecenie'=>'bi-person-lines-fill','uslugi'=>'bi-briefcase','wolontariat'=>'bi-heart',
        'dzielo'=>'bi-palette','praca'=>'bi-building','powierzenie'=>'bi-bank','inne'=>'bi-file-text','szk'=>'bi-mortarboard',
    ];
}

/** Odfiltruj puste grupy (bez pozycji) i węzły bez treści. */
function _menu_prune(array $nodes): array {
    $out = [];
    /* Pozycja może siedzieć w kilku zakładkach (np. „Wolontariat” w Umowach i w Wolontariacie).
       Gdy któraś zakładka jest aktywna jawnie, nie podświetlamy dodatkowo innych po pozycjach. */
    $explicit = false;
    foreach ($nodes as $n) if (!empty($n['active'])) { $explicit = true; break; }
    foreach ($nodes as $n) {
        if (!empty($n['path']) && empty($n['groups'])) { $out[] = $n; continue; } // zakładka-link
        $groups = [];
        foreach (($n['groups'] ?? []) as $g) {
            if (!empty($g['items'])) $groups[] = $g;
        }
        if (!$groups) continue;
        $n['groups'] = $groups;
        // aktywność zakładki = jawna lub dowolna aktywna pozycja
        if (empty($n['active']) && !$explicit) {
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
    if (!function_exists('is_zarzad') && is_file(__DIR__ . '/byli.php')) require_once __DIR__ . '/byli.php';
    $items = [
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
    // Rejestr numerów i kodów QR plików (admin + zarząd) — ten sam, co w „Kancelaria i rejestry”
    if (is_admin() || (function_exists('is_zarzad') && is_zarzad()))
        $items[] = _mi('Rejestr dokumentów','/ezd/rejestrDokumentow.php','bi-qr-code',['match'=>'/ezd/rejestrDokumentow','kw'=>'rejestr dokumentów numery kody qr code128 identyfikacja plików załączniki eSpis']);
    return $items;
}

// ── Widok EZD-only zawężony do RPW (rola ezd_biuro) ──────────────────────────
// Bez menu — jedyna dostępna strona to Rejestr Przychodzących (RPW), na którą
// i tak trafiają automatycznie (require_login() w auth.php), więc nawigacja
// górna jest zbędna.
function _menu_ezd_rpw_only(): array {
    return [];
}

// ── Widok wolontariusza / użytkownika (viewer) ───────────────────────────────
/**
 * Pasek SZO dla wolontariusza.
 *
 * PODZIAŁ: panel współpracownika ma własną, pełną nawigację (panel/includes/
 * header_panel.php — dwadzieścia kilka pozycji). Powtarzanie jej tutaj dawało
 * dwa menu do tego samego, w innej kolejności i z innym zestawem — a pasek SZO
 * widać i tak tylko POZA panelem (Zasady organizacji, Komunikaty, Katalog).
 *
 * Zostaje więc: wejście do panelu + to, co leży poza nim. Wnętrze panelu
 * pozostaje wyszukiwalne w Ctrl+K (klucz `search`).
 */
function _menu_viewer(): array {
    $items = [
        _mi('Mój panel','/panel/index.php','bi-person-circle',
            ['match'=>'/panel/','kw'=>'panel umowa profil godziny zaświadczenia pisma wiadomości']),
    ];

    $unread_rules = 0;
    try { require_once __DIR__ . '/org_rules.php'; org_rules_migrate();
        $unread_rules = count(org_rules_unread((int)(current_user()['id'] ?? 0))); } catch (\Throwable $e) {}
    if (panel_visible('zasady'))
        $items[] = _mi('Zasady organizacji','/org_intro/index.php','bi-building-heart',['match'=>'/org_intro/','badge'=>$unread_rules,'kw'=>'zasady regulamin']);
    if (panel_visible('komunikaty'))
        $items[] = _mi('Komunikaty','/komunikaty/index.php','bi-megaphone',['match'=>'/komunikaty/']);
    if (panel_visible('katalog'))
        $items[] = _mi('Książka telefoniczna','/directory/','bi-person-lines-fill',['match'=>'/directory/','kw'=>'katalog telefon kontakty']);

    $account = [
        _mi('System Tożsamości','/tozsamosc/index.php','bi-person-vcard-fill',['match'=>'/tozsamosc/','kw'=>'tożsamość konto dostępy hasło']),
    ];

    // ── Wnętrze panelu: niewidoczne w pasku, wyszukiwalne w palecie ──────────
    $pnl_unread = 0;
    if (!empty($_SESSION['panel_contract'])) {
        try { $pnl_unread = msg_unread_thread('contract', (int)$_SESSION['panel_contract']['id'], 'user'); } catch (\Throwable $e) {}
    }

    $search = [
        _mi('Mój profil','/panel/profile_edit.php','bi-person-badge',['match'=>'/panel/profile_edit']),
    ];
    if (panel_visible('asystent') && function_exists('asai_enabled') && asai_enabled())
        $search[] = _mi('Asystent AI','/panel/asystent.php','bi-stars',['match'=>'/panel/asystent','kw'=>'asystent ai pomoc pytanie jak gdzie procedura moje sprawy']);
    if (module_enabled('messages_enabled') && panel_visible('wiadomosci'))
        $search[] = _mi('Wiadomości','/panel/messages.php','bi-chat-left-text',['match'=>'/panel/messages','badge'=>$pnl_unread]);
    if (module_enabled('letters_enabled') && panel_visible('pisma'))
        $search[] = _mi('Moje pisma','/panel/letters.php','bi-archive',['match'=>'/panel/letters','kw'=>'pisma']);
    if (panel_visible('wnioski'))
        $search[] = _mi('Wyślij pismo / wniosek','/panel/apply.php','bi-send',['match'=>'/panel/apply','kw'=>'wniosek pismo']);
    if (module_enabled('certificates_enabled') && panel_visible('zaswiadczenia'))
        $search[] = _mi('Zaświadczenia','/panel/certificates.php','bi-award',['match'=>'/panel/certificates']);
    if (module_enabled('terminations_enabled') && panel_visible('rozwiazanie'))
        $search[] = _mi('Rozwiązanie umowy','/panel/terminations.php','bi-file-earmark-x',['match'=>'/panel/terminations']);
    if (module_enabled('timesheets_enabled') && panel_visible('godziny'))
        $search[] = _mi('Ewidencja godzin','/panel/timesheets.php','bi-clock-history',['match'=>'/panel/timesheets','kw'=>'godziny czas']);
    if (module_enabled('smart_cards_enabled'))
        $search[] = _mi('Moja karta dostępu','/modules/smart_cards/my.php','bi-person-badge',['match'=>'/modules/smart_cards/my','kw'=>'karta dostępu identyfikator nfc wniosek biuro']);
    $search[] = _mi('Microsoft 365','/panel/m365.php','bi-microsoft',['match'=>'/panel/m365','kw'=>'m365 office']);
    $search[] = _mi('Sesje i bezpieczeństwo','/panel/sessions.php','bi-shield-lock',['match'=>'/panel/sessions']);
    $search[] = _mi('Ustawienia konta','/panel/password.php','bi-gear',['match'=>'/panel/password','kw'=>'hasło 2fa ustawienia']);

    $badge = $unread_rules + $pnl_unread;
    return [[
        'id'=>'panel','label'=>'Mój panel','icon'=>'bi-person-circle','badge'=>$badge,
        'active'=>_menu_hit('/panel/')||_menu_hit('/org_intro/')||_menu_hit('/komunikaty/')||_menu_hit('/directory/'),
        'groups'=>[
            ['label'=>null,'items'=>$items],
            ['label'=>'Konto','items'=>$account],
        ],
        'search'=>$search,
    ]];
}

/** Pozycje SZK spoza paska menu (zestawienia, promocje, ustawienia) — tylko w wyszukiwarce menu (Ctrl+K); na stronach SZK są też przyciski. */
function _menu_contract_more(array $cnt): array {
    $o = [];
    $add = function (string $label, string $path, string $icon, array $opts, string $desc) use (&$o) { $it = _mi($label, $path, $icon, $opts); $it['desc'] = $desc; $o[] = $it; };
    if (module_enabled('contract_szk')) {
        $add('SZK: zestawienia', '/contracts/szk/zestawienia.php', 'bi-bar-chart-steps', ['match'=>'/contracts/szk/zestawienia','kw'=>'szk zestawienia zmiany nadpłaty zapisy wypowiedzenia notatki płatnicy'], 'Zmiany, nadpłaty, zapisy, wypowiedzenia, notatki i płatnicy umów szkoleniowych.');
        $add('SZK: promocje', '/contracts/szk/promocje.php', 'bi-percent', ['match'=>'/contracts/szk/promocje','kw'=>'szk promocje rabaty'], 'Promocje stosowane w opłatach umów szkoleniowych.');
        if (is_admin()) $add('SZK: ustawienia', '/contracts/szk/ustawienia.php', 'bi-gear', ['match'=>'/contracts/szk/ustawienia','kw'=>'szk ustawienia model opłat nazwa pliku'], 'Domyślny model opłat, raty, termin płatności, nazwa pliku DOCX, automatyczne naliczanie.');
    }
    return $o;
}

// ── Widok edytora / admina ───────────────────────────────────────────────────
function _menu_editor(): array {
    $cnt  = _menu_counts();
    $cic  = _menu_contract_icons();
    $ct_label = fn($s) => CONTRACT_TYPES[$s] ?? ucfirst($s);
    $ct_icon  = fn($s) => $cic[$s] ?? 'bi-file-text';
    $nodes = [];

    // ══ UMOWY ══════════════════════════════════════════════════════════════════
    // Umowy — dwie kolumny wg strony umowy; obsługa dokumentów ma własną zakładkę „Dokumenty”.
    $um_osoby = []; $um_inne = [];
    foreach (['wolontariat' => 'o', 'zlecenie' => 'o', 'praca' => 'o', 'dzielo' => 'o', 'szk' => 'o', 'uslugi' => 'i', 'powierzenie' => 'i', 'inne' => 'i'] as $s => $col) {
        if (!module_enabled('contract_' . $s)) continue;
        $it = _mi($ct_label($s), "/contracts/$s/list.php", $ct_icon($s), ['match'=>"/contracts/$s/", 'kw'=>"umowa $s"]);
        if ($col === 'o') $um_osoby[] = $it; else $um_inne[] = $it;
    }
    $um_g = [];
    if ($um_osoby) $um_g[] = ['label'=>'Osoby fizyczne i szkolenia','items'=>$um_osoby];
    if ($um_inne)  $um_g[] = ['label'=>'Podmioty i inne','items'=>$um_inne];
    $doc_hit = _menu_hit('/contracts/approvals') || _menu_hit('/contracts/letters') || _menu_hit('/contracts/rejestr') || _menu_hit('/admin/terminations')
             || _menu_hit('/pelnomocnictwa/') || _menu_hit('/modules/skierowania_badania/') || _menu_hit('/zlecenie/obsluga')
             || _menu_hit('/admin/applications.php') || _menu_hit('/admin/application.php') || _menu_hit('/modules/app_workflow/') || _menu_hit('/generator/');
    $nodes[] = [
        'id'=>'umowy','label'=>'Umowy','icon'=>'bi-file-text',
        'active'=>_menu_hit('/contracts/') && !$doc_hit && !_menu_hit('/contracts/wolontariat') && !_menu_hit('/contracts/rekrutacja'),
        'groups'=>$um_g,
        'search'=>_menu_contract_more($cnt),   // zestawienia / promocje / ustawienia SZK — tylko w wyszukiwarce (Ctrl+K) i na stronach SZK
        'compact'=>false,
    ];

    // ══ DOKUMENTY ══════════════════════════════════════════════════════════════
    $dk_rej = [ _mi('Rejestr umów (RU)','/contracts/rejestr.php','bi-journal-text',['match'=>'/contracts/rejestr','kw'=>'rejestr ru numer']) ];
    if (module_enabled('pelnomocnictwa_enabled'))
        $dk_rej[] = _mi('Rejestr pełnomocnictw','/pelnomocnictwa/index.php','bi-person-badge',['match'=>'/pelnomocnictwa/','kw'=>'pełnomocnictwo upoważnienie mocodawca 013']);
    $dk_obieg = [];
    if (module_enabled('approvals_enabled'))
        $dk_obieg[] = _mi('Akceptacje','/contracts/approvals/index.php','bi-check2-square',['match'=>'/contracts/approvals/','badge'=>$cnt['pending'],'kw'=>'akceptacja workflow']);
    if (can_read('admin') || can_read('pisma')) {
        $dk_obieg[] = _mi('Pisma i wnioski','/admin/applications.php','bi-inbox',['match'=>'/admin/applications.php','badge'=>$cnt['app'] ?? 0,'kw'=>'wnioski pisma użytkowników zsw dok upz']);
        $dk_obieg[] = _mi('Workflow wniosków','/modules/app_workflow/router.php','bi-diagram-3',['match'=>'/modules/app_workflow/','kw'=>'workflow etapy wnioski obieg']);
    }
    if (module_enabled('terminations_enabled'))
        $dk_obieg[] = _mi('Rozwiązania','/admin/terminations.php','bi-file-earmark-x',['match'=>'/admin/terminations','badge'=>$cnt['term'],'kw'=>'rozwiązanie umowy']);
    if (module_enabled('letters_enabled'))
        $dk_obieg[] = _mi('Pisma do umów','/contracts/letters/index.php','bi-envelope-paper',['match'=>'/contracts/letters/','kw'=>'pisma korespondencja']);
    $dk_wzory = [ _mi('Generator dokumentów','/generator/index.php','bi-file-earmark-richtext',['match'=>'/generator/','kw'=>'generator dokumentów wzór umowa szablon pismo']) ];
    if (module_enabled('contract_wolontariat') || module_enabled('contract_zlecenie'))
        $dk_wzory[] = _mi('Skierowania na badania','/modules/skierowania_badania/router.php','bi-heart-pulse',['match'=>'/modules/skierowania_badania/','kw'=>'skierowanie badania lekarskie medycyna pracy wstępne okresowe kontrolne']);
    if (module_enabled('contract_zlecenie') && can_edit())
        $dk_wzory[] = _mi('Oświadczenia zleceniobiorców','/zlecenie/obsluga','bi-file-earmark-check',['match'=>'/zlecenie/obsluga','kw'=>'oświadczenie zus pit podatkowe ubezpieczenie rachunek bankowy nrb zlb zleceniobiorca kreator']);
    $dk_g = [['label'=>'Rejestry','items'=>$dk_rej]];
    if ($dk_obieg) $dk_g[] = ['label'=>'Obieg i wnioski','items'=>$dk_obieg];
    $dk_g[] = ['label'=>'Wzory i druki','items'=>$dk_wzory];
    $nodes[] = [
        'id'=>'dokumenty','label'=>'Dokumenty','icon'=>'bi-folder2-open','badge'=>$cnt['pending']+$cnt['term'],
        'active'=>$doc_hit, 'groups'=>$dk_g, 'compact'=>false,
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
    /* Strategia i Działania to jeden moduł z własnym paskiem — dwie pozycje obok
       siebie w menu SZO sugerowały dwa osobne miejsca. */
    $fin_search = [];
    if (can_read('umowy') || is_admin())
        $fin[] = _mi('Strategia','/strategy/index.php','bi-bullseye',['match'=>'/strategy/','kw'=>'strategia cele działania projekty']);
    if (menu_visible('actions'))
        $fin_search[] = _mi('Działania','/dzialania','bi-calendar-event',['match'=>'/dzialania','kw'=>'działania projekt']);
    $fin[] = _mi('Zwroty kosztów','/contracts/zwroty/index.php','bi-receipt-cutoff',['match'=>'/contracts/zwroty/','badge'=>$cnt['zwr'],'kw'=>'zwrot koszty refundacja']);
    if ($cnt['has_edok']) {
        $fin[] = _mi('EODoK — Dok. Księgowe','/edok/index.php','bi-journal-check',['match'=>'/edok/index','kw'=>'edok eodok akceptacja dekretacja kontrola merytoryczna formalna rachunkowa dokumenty księgowe']);
        if (is_admin() || edok_has_role('upload'))
            $fin[] = _mi('EODoK — Kolejka do opisu','/edok/queue.php','bi-inboxes',['match'=>'/edok/queue','kw'=>'kolejka do opisu zbiorczy upload wgraj pliki edok eodok']);
        if (is_admin() || edok_has_role('upload') || edok_has_role('ksiegowy'))
            $fin[] = _mi('EODoK — Skrzynka faktur','/edok/mailbox.php','bi-envelope-paper',['match'=>'/edok/mailbox','kw'=>'skrzynka faktur faktury e-mail mailbox odbiór dziennik edok eodok']);
        if (is_admin() || edok_has_role('zatwierdza') || (function_exists('kdok_has_role') && kdok_has_role('zatwierdza')))
            $fin[] = _mi('Preliminarz Płatności','/edok/preliminarz.php','bi-calendar-check',['match'=>'/edok/preliminarz','kw'=>'preliminarz płatności przelew']);
        if (is_admin() || edok_has_role('zatwierdza'))
            $fin[] = _mi('Zestawienie wypłat','/edok/wyplaty.php','bi-cash-stack',['match'=>'/edok/wyplaty','kw'=>'zestawienie wypłaty listy płac zlecenie eksport csv']);
        if (is_admin() || edok_has_role('zatwierdza'))
            $fin[] = _mi('Przelewy za rachunki','/edok/przelewy_rachunki.php','bi-bank',['match'=>'/edok/przelewy_rachunki','kw'=>'przelewy rachunki umowy zlecenie netto wypłata pko ipko plik bank']);
        if (is_admin() || edok_has_role('zatwierdza') || (function_exists('kdok_has_role') && kdok_has_role('zatwierdza')))
            $fin[] = _mi('Przelewy do US','/modules/us_przelewy/router.php','bi-bank2',['match'=>'/modules/us_przelewy/','kw'=>'urząd skarbowy us podatek pit-4r pit-8ar cit vat mikrorachunek przelew podatkowy zobowiązania podatkowe']);
    }
    if ($cnt['has_kdok'])
        $fin[] = _mi('EOD Dok. Księgowych (archiwum)','/ksiegowosc/index.php','bi-archive',['match'=>'/ksiegowosc/index','kw'=>'księgowość faktury dokumenty kdok archiwum']);
    $fin[] = _mi('Zasoby','/modules/srs/','bi-box-seam',['match'=>'/modules/srs/','badge'=>$cnt['res'],'kw'=>'zasoby rezerwacje sprzęt srs']);
    // Noclegi (modules/noclegi): role modułu + bramka „Dop. noclegi” na umowie (noclEligible)
    $_nocl_ok = false;
    try { require_once dirname(__DIR__) . '/modules/noclegi/logic/noclegi.php'; $_nocl_ok = noclEligible(); } catch (Throwable $e) {}
    if ($_nocl_ok)
        $fin[] = _mi('Noclegi','/modules/noclegi/router.php','bi-moon-stars',['match'=>'/modules/noclegi/','kw'=>'noclegi nocleg hotel zakwaterowanie rooming logistyk koordynator projektu dop. noclegi']);
    // Formularze rezerwacji sal operatorów (modules/ti_room_srs): administrator / zapis w SRS / uprawnienie „Może rezerwować”
    $_trs_book = is_admin() || can_write('srs');
    if (!$_trs_book) { try { $_trs_book = (bool)db_one("SELECT 1 FROM ti_room_srs_bookers WHERE user_id=?", [(int)(current_user()['id'] ?? 0)]); } catch (Throwable $e) {} }
    if ($_trs_book)
        $fin[] = _mi('Rezerwuj przestrzeń','/modules/ti_room_srs/router.php','bi-door-open',['match'=>'/modules/ti_room_srs/','kw'=>'rezerwacja sali przestrzeń formularz wniosek centrum obywatelskie reymonta c10 operator srs']);
    if (module_enabled('holograms_enabled') && in_array(current_user()['role'] ?? '', ['admin','editor'], true))
        $fin[] = _mi('Hologramy','/modules/holograms/index.php','bi-patch-check',['match'=>'/modules/holograms/','kw'=>'hologramy naklejki zabezpieczające plomby sprzęt dokumenty']);
    if (module_enabled('smart_cards_enabled') && in_array(current_user()['role'] ?? '', ['admin','editor'], true))
        $fin[] = _mi('Karty dostępu','/modules/smart_cards/index.php','bi-credit-card-2-front',['match'=>'/modules/smart_cards/','kw'=>'karty dostępu nfc identyfikatory strefy biuro wnioski programator']);
    if ($cnt['has_shipping'])
        $fin[] = _mi('Przesyłki','/admin/shipments.php','bi-truck',['match'=>'/admin/shipments','badge'=>$cnt['ship'],'kw'=>'przesyłki kurier apaczka']);
    $nodes[] = [
        'search'=>$fin_search,
        'id'=>'finanse','label'=>'Finanse','icon'=>'bi-cash-coin','badge'=>$cnt['zwr']+$cnt['res']+$cnt['ship'],
        'active'=>_menu_hit('/contracts/zwroty')||_menu_hit('/grants/')||_menu_hit('/strategy/')||_menu_hit('/ksiegowosc/')||_menu_hit('/edok/')||_menu_hit('/modules/us_przelewy/')||_menu_hit('/modules/srs/')||_menu_hit('/modules/noclegi/')||_menu_hit('/admin/shipments'),
        'groups'=>[['label'=>null,'items'=>$fin]],
    ];

    // ══ LUDZIE ═════════════════════════════════════════════════════════════════
    $ludzie = [ _mi('Osoby','/persons/index.php','bi-people',['match'=>'/persons/','kw'=>'osoby rejestr']) ];
    /* CRM prowadzi własny pasek z siedmioma kategoriami — Faktury i Darowizny są
       tam w „Finansach". Tu zostaje samo wejście do modułu; obie sekcje pozostają
       w Ctrl+K (klucz `search` na węźle „Ludzie"). */
    $ludzie_search = [];
    if (module_enabled('crm_enabled') && can_read('crm')) {
        $ludzie[] = _mi('CRM','/crm/dashboard.php','bi-diagram-2',['match'=>'/crm/','kw'=>'crm kontakt klient kontakty sprawy oferty']);
        $ludzie[] = _mi('Szybkie dzwonienie','/mobilna/','bi-telephone-outbound',['match'=>'/mobilna/','kw'=>'dzwon telefon mobilna dialer numer']);
    }
    if (module_enabled('invoices_enabled') && can_read('crm'))
        $ludzie_search[] = _mi('Faktury','/crm/invoices/index.php','bi-receipt',['match'=>'/crm/invoices/','kw'=>'faktura faktury vat fakturownia ksef rachunek']);
    if (module_enabled('donations_enabled') && can_read('crm'))
        $ludzie_search[] = _mi('Darowizny','/crm/donations/index.php','bi-gift',['match'=>'/crm/donations/','kw'=>'darowizna darowizny darczynca pit odliczenie potwierdzenie']);
    $ludzie[] = _mi('Katalog osób','/directory/','bi-person-lines-fill',['match'=>'/directory/','kw'=>'katalog telefon kontakty książka telefoniczna']);
    if (module_enabled('org_enabled'))
        $ludzie[] = _mi('Struktura org.','/org/index.php','bi-diagram-3',['match'=>'/org/','kw'=>'struktura organizacja schemat']);
    if (module_enabled('byli_enabled'))
        $ludzie[] = _mi('Byłe osoby','/byli/index.php','bi-person-dash',['match'=>'/byli/','kw'=>'byli archiwum']);
    if (can_edit())
        $ludzie[] = _mi('Zmiany danych współpracowników','/modules/collaborator_data/router.php?_route=/admin','bi-person-gear',['match'=>'/modules/collaborator_data/','kw'=>'zmiana danych adres rachunek bankowy telefon akceptacja dziennik współpracownik']);
    $nodes[] = [
        'id'=>'ludzie','label'=>'Ludzie','icon'=>'bi-people',
        'active'=>_menu_hit('/persons/')||_menu_hit('/crm/')||_menu_hit('/mobilna/')||_menu_hit('/directory/')||_menu_hit('/org/')||_menu_hit('/byli/')||_menu_hit('/modules/collaborator_data/admin'),
        'groups'=>[['label'=>null,'items'=>$ludzie]],
        'search'=>$ludzie_search,
    ];

    // ══ BIURO (obsługa + kancelaria + formularze) ══════════════════════════════
    $obs_g = [];
    if (module_enabled('obiegi_enabled'))
        $obs_g[] = _mi('Obiegi','/obiegi/index.php','bi-diagram-2',['match'=>'/obiegi/','badge'=>$cnt['obieg'],'kw'=>'obiegi bpm procesy']);
    /* Moduł Zadań ma własny pasek z obszarami i zakładką „Pliki" — druga pozycja
       w menu SZO prowadziła do zakładki, którą i tak widać po wejściu. */
    $biuro_search = [];
    if (module_enabled('tasks_enabled')) {
        $obs_g[] = _mi('Zadania','/tasks/dashboard.php','bi-kanban',['match'=>'/tasks/','kw'=>'zadania tablica kanban obszary listy']);
        $biuro_search[] = _mi('Pliki','/tasks/files.php','bi-folder2-open',['match'=>'/tasks/files','kw'=>'pliki projektowe workspace koszulki sharepoint']);
    }
    if (module_enabled('helpdesk_enabled')) {
        $obs_g[] = _mi('Helpdesk','/helpdesk/index.php','bi-ticket-perforated',['match'=>'/helpdesk/','badge'=>$cnt['hd'],'kw'=>'helpdesk zgłoszenie it wsparcie']);
        if ($cnt['alias_op'])
            $obs_g[] = _mi('Aliasy e-mail','/admin/email_aliasy.php','bi-at',['match'=>'/admin/email_aliasy','badge'=>$cnt['alias'],'kw'=>'alias email']);
        /* Helpdesk ma własną nawigację (lista, karta zgłoszenia, podmenu Administracja) — w pasku SZO jedno wejście,
           reszta wyszukiwalna w Ctrl+K. */
        $biuro_search[] = _mi('Nowe zgłoszenie IT','/helpdesk/new.php','bi-plus-circle',['match'=>'/helpdesk/new','kw'=>'helpdesk nowe zgłoszenie problem awaria wniosek it']);
        if ($cnt['alias_op']) {
            $biuro_search[] = _mi('Helpdesk — raporty','/modules/helpdeskReports/router.php','bi-graph-up',['match'=>'/modules/helpdeskReports/','kw'=>'helpdesk raporty statystyki sla csat czas pracy eksport csv']);
            $biuro_search[] = _mi('Helpdesk — gotowe odpowiedzi i makra','/helpdesk/admin_macros.php','bi-card-text',['match'=>'/helpdesk/admin_macros','kw'=>'helpdesk makra gotowe odpowiedzi przebiegi akcje']);
            $biuro_search[] = _mi('Helpdesk — moje powiadomienia','/modules/helpdeskNotify/router.php','bi-bell',['match'=>'/modules/helpdeskNotify/','kw'=>'helpdesk powiadomienia operatora urlop wstrzymaj e-mail']);
            if (is_admin()) {
                $biuro_search[] = _mi('Helpdesk — reguły automatyczne','/modules/helpdeskRules/router.php','bi-diagram-3',['match'=>'/modules/helpdeskRules/','kw'=>'helpdesk reguły automatyzacje wyzwalacze kroki']);
                $biuro_search[] = _mi('Helpdesk — statusy własne kategorii','/modules/helpdeskStatuses/router.php','bi-signpost-split',['match'=>'/modules/helpdeskStatuses/','kw'=>'helpdesk statusy własne kategorii podstatusy etapy wnioski am']);
                $biuro_search[] = _mi('Helpdesk — szablony e-mail','/helpdesk/admin_email_templates.php','bi-envelope-gear',['match'=>'/helpdesk/admin_email_templates','kw'=>'helpdesk szablony maili']);
                $biuro_search[] = _mi('Helpdesk — ustawienia i migracja numerów','/helpdesk/admin.php','bi-gear',['match'=>'/helpdesk/admin.php','kw'=>'helpdesk ustawienia skrzynki e-mail csat sla godziny numeracja migracja numerów zgłoszeń operatorzy']);
            }
        }
    }
    if (is_admin() || (current_user()['role'] ?? '') === 'editor')
        $obs_g[] = _mi('Portal Usług IT','/modules/it_portal/index.php','bi-life-preserver',['match'=>'/modules/it_portal/','kw'=>'portal usługi it instrukcje komunikaty awarie phishing baza wiedzy it.feer.org.pl']);
    if (module_enabled('rekrutacja_enabled')
        && (is_admin() || !empty(current_user()['rekrutacja_operator'])))
        $obs_g[] = _mi('Nabór','/rekrutacja/index.php','bi-person-plus',['match'=>'/rekrutacja/','kw'=>'nabór rekrutacja kandydaci cv wolontariusze pracownicy zgłoszenia']);
    if (module_enabled('messages_enabled'))
        $obs_g[] = _mi('Wiadomości','/admin/messages.php','bi-chat-dots',['match'=>'/admin/messages','badge'=>$cnt['msg'],'kw'=>'wiadomości czat','attr'=>'data-msg-sb-badge']);
    if (module_enabled('events_enabled'))
        $obs_g[] = _mi('Wydarzenia','/events/dashboard.php','bi-calendar-event',['match'=>'/events/','kw'=>'wydarzenia kalendarz']);
    if (module_enabled('poczta_enabled'))
        $obs_g[] = _mi('Poczta','/poczta/dashboard.php','bi-envelope',['match'=>'/poczta/','kw'=>'poczta email']);

    $kanc = [];
    if (module_enabled('reports_enabled'))
        $kanc[] = _mi('Raporty','/reports/index.php','bi-bar-chart-line',['match'=>'/reports/','kw'=>'raporty statystyki']);
    $kanc[] = _mi('Opłacalność działań','/tools/oplacalnosc.php','bi-calculator',['match'=>'/tools/oplacalnosc','kw'=>'opłacalność kalkulator roi zlecenie wyjazd']);
    $kanc[] = _mi('Korespondencja','/correspondence/index.php','bi-mailbox',['match'=>'/correspondence/','kw'=>'korespondencja listy']);
    $kanc[] = _mi('Stopka e-mail','/modules/stopka/index.php','bi-envelope-paper',['match'=>'/modules/stopka/','kw'=>'stopka e-mail podpis wiadomość signature outlook']);
    $kanc[] = _mi('Procedury','/procedures/index.php','bi-list-task',['match'=>'/procedures/index','kw'=>'procedury instrukcje']);
    $kanc[] = _mi('Asystent AI','/procedures/asystent.php','bi-stars',['match'=>'/procedures/asystent','kw'=>'asystent ai chatbot wyszukiwanie procedury dokumentacja funkcje systemu gdzie jak zrobić pytania pomoc']);
    if (module_enabled('wsparcie_ou_enabled') && can_read('wsparcie_ou'))
        $kanc[] = _mi('Wsparcie zewnętrzne OU','/wsparcie_ou/index.php','bi-building-add',['match'=>'/wsparcie_ou/','badge'=>$cnt['wsparcie_ou'],'kw'=>'wsparcie ou podmioty zewnętrzne']);
    if (module_enabled('doc_signing_enabled'))
        $kanc[] = _mi('Podpisz dokument','/podpisy/index.php','bi-pen',['match'=>'/podpisy/','kw'=>'podpis dokument']);
    if (module_enabled('org_documents_enabled'))
        $kanc[] = _mi('Dokumenty organizacji','/admin/org_documents.php','bi-folder2-open',['match'=>'/admin/org_documents','kw'=>'dokumenty organizacji']);
    $kanc[] = _mi('Uchwały','/resolutions/index.php','bi-file-ruled',['match'=>'/resolutions/','kw'=>'uchwały zarząd']);
    // Rejestr numerów i kodów QR plików wgranych do systemu (admin + zarząd)
    if (is_admin() || (function_exists('is_zarzad') ? is_zarzad() : in_array((int)(current_user()['id'] ?? 0), array_filter(array_map('intval', explode(',', (string)org_setting('zarzad_user_ids')))), true)))
        $kanc[] = _mi('Rejestr dokumentów','/ezd/rejestrDokumentow.php','bi-qr-code',['match'=>'/ezd/rejestrDokumentow','kw'=>'rejestr dokumentów numery kody qr code128 identyfikacja plików załączniki']);
    if (in_array(current_user()['role'] ?? '', ['admin','editor'], true))
        $kanc[] = _mi('Klauzule RODO','/modules/gdpr_clauses/index.php','bi-shield-lock',['match'=>'/modules/gdpr_clauses/','kw'=>'klauzule rodo gdpr klauzula informacyjna ochrona danych iod']);
    if (is_admin())
        $kanc[] = _mi('Obsługa RODO','/modules/rodo_register/router.php','bi-shield-check',['match'=>'/modules/rodo_register/','kw'=>'rodo gdpr rejestr czynności przetwarzania wnioski osób naruszenia retencja podmioty przetwarzające dpia iod art 30']);

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
        'active'=>_menu_hit('/obiegi/')||_menu_hit('/tasks/')||_menu_hit('/workspaces/')||_menu_hit('/helpdesk/')||_menu_hit('/admin/email_aliasy')||_menu_hit('/admin/messages')||_menu_hit('/events/')||_menu_hit('/poczta/')
                 ||_menu_hit('/reports/')||_menu_hit('/correspondence/')||_menu_hit('/procedures/')||_menu_hit('/wsparcie_ou/')||_menu_hit('/podpisy/')||_menu_hit('/admin/org_documents')||_menu_hit('/resolutions/')||_menu_hit('/modules/stopka/')||_menu_hit('/tools/oplacalnosc')
                 ||_menu_hit('/dostepnosc/')||_menu_hit('/asysta/')||_menu_hit('/extforms/'),
        'groups'=>[
            ['label'=>'Obsługa','badge'=>$cnt['obieg']+$cnt['hd']+$cnt['msg'],'items'=>$obs_g],
            ['label'=>'Kancelaria i rejestry','items'=>$kanc],
            ['label'=>'Formularze zewnętrzne','items'=>$forms],
        ],
        'search'=>$biuro_search,
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
    /* EZD ma własną, pełną nawigację (ezd/index.php i pasek modułu). Powtarzanie
       jej wnętrza w pasku SZO dawało dwa menu do tego samego i różniło się od
       tamtego kolejnością — czyli uczyło, że „to samo jest gdzie indziej".
       Zostaje jedno wejście; pozycje modułu żyją dalej w Ctrl+K (klucz `search`). */
    if (module_enabled('ezd_enabled') && (can_read('ezd') || can_write('ezd'))) {
        $nodes[] = [
            'id'=>'ezd','label'=>'Wirtualne biurko','icon'=>'bi-building-gear','ezd'=>true,
            'path'=>'/ezd/index.php','match'=>'/ezd/',
            'active'=>_menu_hit('/ezd/'),
            'kw'=>'ezd biurko kancelaria koszulki dziennik podawczy jrwa',
            'search'=>_menu_ezd_items(),
        ];
    }

    // ══ RODO (zakładka-link) ═══════════════════════════════════════════════════
    $nodes[] = [
        'id'=>'rodo','label'=>'RODO','icon'=>'bi-shield-lock','path'=>'/rodo/index.php',
        'kw'=>'rodo dane osobowe upoważnienia','active'=>_menu_hit('/rodo/'),
    ];

    // ══ ADMIN ══════════════════════════════════════════════════════════════════
    if (is_admin()) {
        $adm_main = [
            _mi('Panel admina','/admin/index.php','bi-shield-shaded',['match'=>'/admin/index','kw'=>'admin panel ustawienia']),
            _mi('Wzory dokumentów','/admin/contract_templates.php','bi-file-earmark-text',['match'=>'/admin/contract_templates','kw'=>'wzory dokumentów szablony umów']),
            _mi('Harmonogram CRON','/admin/cron_dispatcher.php','bi-calendar2-range',['match'=>'/admin/cron_dispatcher','kw'=>'cron dispatcher dyspozytor harmonogram agenci zadania automatyczne interwał']),
            _mi('Statusy umów','/modules/contract_status/router.php?_route=/statusy','bi-signpost-split',['match'=>'_route=/statusy','kw'=>'statusy umów rozpiska grupy przejścia aktywna zakończona słownik umowy_statusy']),
            _mi('Słownik Centralny','/modules/dictionary/router.php','bi-book-half',['match'=>'/modules/dictionary/','kw'=>'słownik słowniki centralny listy wyboru instytucje dotujące urzędy marszałkowskie niw kategorie import eksport']),
            _mi('Komunikaty popup','/modules/popups/index.php','bi-window-stack',['match'=>'/modules/popups/','kw'=>'popup komunikaty okna informacyjne powitalne mobywatel zapowiedź edytor']),
            _mi('Redis (cache)','/admin/redis.php','bi-lightning-charge',['match'=>'/admin/redis','kw'=>'redis cache pamięć podręczna przyspieszenie wydajność']),
            _mi('Kontrola IT','/modules/it_control/index.php','bi-clipboard2-pulse',['match'=>'/modules/it_control/','kw'=>'kontrola it audyt operacyjny status usług dysk backup certyfikat cron monitoring pulpit stanu']),
            _mi('Kontrola Dzienników','/modules/log_control/index.php','bi-journal-check',['match'=>'/modules/log_control/','kw'=>'kontrola dzienników logi błędy php anomalie bezpieczeństwo logowania brute force 2fa']),
            _mi('Seedery (dane testowe)','/seeder-test','bi-database-add',['match'=>'/seeder-test','kw'=>'seedery seeder dane testowe konta testowe qa sprzątanie cleanup rolametrotest demo']),
        ];
        $integ = [
            _mi('Microsoft 365','/admin/m365_settings.php','bi-microsoft',['match'=>'/admin/m365','kw'=>'m365 integracja graph']),
            _mi('Kody dostępowe M365','/admin/ms_edu_codes.php','bi-key-fill',['match'=>'/admin/ms_edu_codes','kw'=>'kody dostępowe m365 konta edu lccc']),
            _mi('Konta M365 Edu — hurtowo','/modules/ms_edu_bulk/index.php','bi-people-fill',['match'=>'/modules/ms_edu_bulk','kw'=>'konta m365 edu hurtowo generowanie uczniowie nauczyciele pdf login hasło']),
            _mi('Kreator kont M365 z listy (XLS)','/modules/ms_edu_bulk/kreator.php','bi-magic',['match'=>'/modules/ms_edu_bulk/kreator','kw'=>'kreator konta m365 xls excel import lista csv']),
            _mi('Konta EDU — zarządzanie','/modules/ms_edu_bulk/konta.php','bi-person-gear',['match'=>'/modules/ms_edu_bulk/konta','kw'=>'konta edu zarządzanie zawieszenie offboarding menedżer mfa rola entra']),
            _mi('SharePoint','/admin/sharepoint_settings.php','bi-cloud-upload',['match'=>'/admin/sharepoint','kw'=>'sharepoint']),
            _mi('Szkolenia (TidyCal)','/admin/tidycal_settings.php','bi-calendar2-check',['match'=>'/admin/tidycal','kw'=>'tidycal szkolenia']),
            _mi('Zadania / Nozbe','/admin/nozbe_settings.php','bi-check2-square',['match'=>'/admin/nozbe_settings','kw'=>'nozbe zadania integracja']),
            _mi('Faktury / Comarch Betterfly','/admin/comarch_settings.php','bi-receipt',['match'=>'/admin/comarch','kw'=>'comarch betterfly faktury api']),
            _mi('Płatności / Stripe','/admin/stripe_settings.php','bi-credit-card',['match'=>'/admin/stripe_settings','kw'=>'stripe płatności']),
            _mi('Płatności / PayU','/admin/payu_settings.php','bi-wallet2',['match'=>'/admin/payu_settings','kw'=>'payu płatności']),
            _mi('Płatności / Przelewy24','/admin/p24_settings.php','bi-wallet2',['match'=>'/admin/p24_settings','kw'=>'przelewy24 p24 płatności']),
            _mi('Portal płatności','/platnosci/admin.php','bi-credit-card-2-front',['match'=>'/platnosci/admin','kw'=>'portal płatności nrb rachunek wirtualny przelew koszyk']),
            _mi('Magazyn plików / ownCloud','/admin/owncloud_settings.php','bi-cloud-arrow-up',['match'=>'/admin/owncloud_settings','kw'=>'owncloud magazyn']),
            _mi('API i webhooki','/admin/api_manage.php','bi-key',['match'=>'/admin/api_manage','kw'=>'api webhooki klucze']),
            _mi('Dyżury kierownika / Zarządu (DK)','/modules/ti_duty/index.php','bi-person-badge',['match'=>'/modules/ti_duty/','kw'=>'dyżury dyżur kierownika zarządu dk grafik prezes dostępność']),
            _mi('Panel dydaktyka (TI)','/karty30/ti/dydaktyk/wylaczenia.php','bi-easel2',['match'=>'/karty30/ti/dydaktyk/wylaczenia','kw'=>'panel dydaktyka ti przerwa komunikat wyłączenia']),
            _mi('Wiad. od prowadzących','/admin/ti_admin_msgs.php','bi-envelope-exclamation',['match'=>'/admin/ti_admin_msgs','kw'=>'wiadomości prowadzący kierownictwo','badge'=>(function(){ if(!function_exists('ti_admin_msg_unread_count')){@require_once __DIR__.'/ti_messages.php';} return function_exists('ti_admin_msg_unread_count')?ti_admin_msg_unread_count():0; })()]),
        ];
        // aktywność (jak w header): /admin/ poza sekcjami przeniesionymi indziej
        $admin_active = _menu_hit('/seeder-test') || _menu_hit('/modules/dictionary/') || _menu_hit('/admin/') && !_menu_hit('/admin/messages') && !_menu_hit('/admin/terminations')
            && !_menu_hit('/admin/certificates') && !_menu_hit('/admin/timesheets') && !_menu_hit('/admin/shipments') && !_menu_hit('/admin/onboarding');
        // Zakładka-link do panelu admina — BEZ listy rozwijanej; wszystko jest na
        // pulpicie admin/index.php. Pozycje zostają tylko w wyszukiwarce (Ctrl+K).
        $nodes[] = [
            'id'=>'admin','label'=>'Admin','icon'=>'bi-shield-shaded','badge'=>$cnt['adm'],'end'=>true,
            'path'=>'/admin/index.php','kw'=>'admin panel ustawienia',
            'active'=>$admin_active,
            'search'=>_menu_admin_search(array_merge(array_slice($adm_main, 1), $integ)),
        ];
    }

    return _menu_prune($nodes);
}

/**
 * Pozycje panelu admina do Ctrl+K: cały rejestr pulpitu (modules/adminRegistry — jedno źródło z admin/index.php)
 * plus ręcznie dopisane pozycje spoza rejestru ($extra). Bez duplikatów ścieżek i bez kotwic (#…).
 */
function _menu_admin_search(array $extra): array {
    $out = []; $seen = [];
    try {
        require_once dirname(__DIR__) . '/modules/adminRegistry/lib/adminRegistry.php';
        foreach (adminRegistry() as $group => $g) {
            foreach ($g['items'] as $it) {
                $url = (string)$it['url'];
                if ($url === '' || $url[0] === '#' || isset($seen[$url])) continue;
                $seen[$url] = true;
                $out[] = _mi($it['label'], $url, $it['icon'], ['match'=>strtok($url, '?#'), 'kw'=>trim(($it['kw'] ?? '') . ' ' . $group), 'danger'=>!empty($it['danger'])]);
            }
        }
    } catch (\Throwable $e) { error_log('[menu] rejestr admina: ' . $e->getMessage()); }
    foreach ($extra as $it) if (!isset($seen[$it['path']])) { $seen[$it['path']] = true; $out[] = $it; }
    return $out;
}

/**
 * Zbuduj drzewo menu dla bieżącego użytkownika.
 * Zwraca ['mode'=>'ezd|viewer|editor|guest','tree'=>[…]].
 */
function menu_build(bool $compact = true): array {
    $u = current_user();
    if (!$u)                                                   return ['mode'=>'guest','tree'=>[]];
    if (function_exists('is_ezd_only') && is_ezd_only()) {
        $ezd_tree = (function_exists('is_ezd_rpw_only') && is_ezd_rpw_only()) ? _menu_ezd_rpw_only() : _menu_ezd();
        return ['mode'=>'ezd','tree'=>_menu_prune($ezd_tree)];
    }
    if (!can_edit())                                           return ['mode'=>'viewer','tree'=>_menu_prune(_menu_viewer())];
    $tree = _menu_editor();
    return ['mode'=>'editor','tree'=>$compact ? menu_compact($tree) : $tree];
}

/**
 * Odchudza zakładki rozwijane: w grupie zostaje kilka pierwszych pozycji (5; w zakładce z jedną grupą 7), reszta trafia do
 * wyszukiwarki menu (klucz `search`) i na stronę „Więcej” (/menu_more.php?tab=ID) — jedna pozycja „Więcej…” z sumą odznak.
 * Kolejność pozycji w _menu_editor() jest kolejnością ważności. Zakładka z `compact => false` (np. Umowy) zostaje bez zmian.
 */
function menu_compact(array $tree): array {
    foreach ($tree as $i => $node) {
        if (empty($node['groups']) || ($node['compact'] ?? true) === false) continue;
        $cap = count($node['groups']) === 1 ? 7 : 5;
        $hidden = [];
        foreach ($node['groups'] as $gi => $g) {
            if (count($g['items']) <= $cap + (count($node['groups']) === 1 ? 1 : 0)) continue;   // pojedyncza grupa: 8 pozycji jeszcze mieści się bez „Więcej”
            foreach (array_slice($g['items'], $cap) as $h) $hidden[] = $h;
            $node['groups'][$gi]['items'] = array_slice($g['items'], 0, $cap);
        }
        if (!$hidden) continue;
        $badge = 0;
        foreach ($hidden as $h) $badge += (int)($h['badge'] ?? 0);
        $last = count($node['groups']) - 1;
        $node['groups'][$last]['items'][] = _mi('Więcej (' . count($hidden) . ')…', '/menu_more.php?tab=' . rawurlencode((string)($node['id'] ?? '')), 'bi-three-dots', ['match'=>'/menu_more.php?tab=' . ($node['id'] ?? ''), 'badge'=>$badge, 'kw'=>'więcej wszystkie pozycje ' . $node['label']]);
        $node['search'] = array_merge($node['search'] ?? [], $hidden);
        $tree[$i] = $node;
    }
    return $tree;
}

/**
 * Spłaszcza drzewo do indeksu wyszukiwania palety poleceń.
 * Zwraca listę: ['label','sub','path','icon','badge','danger','kw'].
 *
 * Węzeł może mieć klucz `search` — pozycje NIEwidoczne w pasku, ale wyszukiwalne.
 * Tak trafiają tu wnętrza modułów, które mają własną nawigację: pasek SZO
 * pokazuje jedno wejście do modułu, a Ctrl+K nadal prowadzi wprost do „Dziennika
 * podawczego" czy „Faktur". Bez tego podział menu odebrałby ludziom skrót,
 * z którego korzystają codziennie.
 */
function menu_search_index(array $tree): array {
    $out = []; $seen = [];
    // Ta sama ścieżka bywa w kilku miejscach (zakładki, klucz `search`, rejestr admina) — w palecie zostaje pierwsze wystąpienie.
    $add = function (array $row) use (&$out, &$seen): void {
        $k = (string)$row['path'];
        if ($k !== '' && isset($seen[$k])) return;
        $seen[$k] = true;
        $out[] = $row;
    };
    foreach ($tree as $node) {
        // pozycje ukryte w pasku, ale wyszukiwalne
        foreach (($node['search'] ?? []) as $it) {
            $add([
                'label'=>$it['label'],'sub'=>$node['label'],'path'=>$it['path'],
                'icon'=>$it['icon'],'badge'=>(int)($it['badge'] ?? 0),'danger'=>false,
                'kw'=>trim($node['label'] . ' ' . ($it['kw'] ?? '')),
            ]);
        }
        // zakładka-link
        if (!empty($node['path']) && empty($node['groups'])) {
            $add([
                'label'=>$node['label'],'sub'=>'','path'=>$node['path'],
                'icon'=>$node['icon'],'badge'=>0,'danger'=>false,'kw'=>$node['kw'] ?? '',
            ]);
            continue;
        }
        foreach (($node['groups'] ?? []) as $g) {
            $crumb = $node['label'] . ($g['label'] ? ' · ' . $g['label'] : '');
            foreach ($g['items'] as $it) {
                $add([
                    'label'=>$it['label'],'sub'=>$crumb,'path'=>$it['path'],
                    'icon'=>$it['icon'],'badge'=>(int)$it['badge'],'danger'=>!empty($it['danger']),
                    'kw'=>trim($crumb . ' ' . ($it['kw'] ?? '')),
                ]);
            }
        }
    }
    return $out;
}

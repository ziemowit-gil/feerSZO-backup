<?php
/**
 * modules/ti_dyd_menu/logic/dydMenu.php — stałe menu boczne panelu dydaktyka (jedno źródło nawigacji).
 *
 * Jedno menu na KAŻDEJ stronie panelu (skórka ti-skin): sekcje prowadzącego (Mój panel, Kurs, Komunikacja, Zasoby)
 * oraz — dla kierownika, zastępcy i administratora (dyd_is_staff) — grupy kierownika ułożone wg rodzaju pracy,
 * od najczęściej używanych. Tryb Konsultacje (choose_mode.php) pokazuje sekcje konsultacji zamiast szkoleń.
 * Menu jest przyklejone do lewej krawędzi i zawsze widoczne (bez chowania); grupy to akordeon — otwarta ta z bieżącą stroną,
 * zwinięte pokazują sumę liczników — dzięki temu mieści się bez przewijania. Na telefonie wysuwane.
 *
 * Włączane w kursant/_layout_head.php (dydMenuBoot: klasa body + arkusz), rysowane w kursant/_layout_foot.php
 * (dydMenuRender) — w stopce, bo liczniki zakładek kursu ($_sb_ctabs w index.php) powstają po nagłówku strony.
 * _usos_bar.php i _kierownik_bar.php przy włączonym menu rysują już tylko okruszki.
 */

/** Czy menu jest włączone na tej stronie (ustawia dydMenuBoot). */
function dydMenuOn(): bool
{
    return !empty($GLOBALS['DYD_SIDE_MENU']);
}

/** Wołane z _layout_head.php: włącza menu dla zalogowanego w panelu dydaktyka w skórce ti-skin. Zwraca klasę dla <body>. */
function dydMenuBoot(array $topbar, string $bodyClass): string
{
    if (($topbar['brand'] ?? '') !== 'Panel dydaktyka' || !str_contains($bodyClass, 'ti-skin')) return '';
    if (!function_exists('dyd_current') || !dyd_current()) return '';
    $GLOBALS['DYD_SIDE_MENU']  = true;
    $GLOBALS['DYD_HEADER_NAV'] = true;   // rząd sekcji nie jest już potrzebny ani w pasku, ani nad treścią
    return 'dyd-side-on';
}

/**
 * Sekcje menu: [['key','label','sub'?(podpis),'items'=>[['tab','label','href','icon','n','v','blank','js'?]]], ...].
 * Kolejność = kolejność w menu. Grupy kierownika mają 'staff' => true (rysowane pod nagłówkiem „Kierownik”).
 */
function dydMenuSections(): array
{
    $g      = $GLOBALS;
    $staff  = function_exists('dyd_is_staff') && dyd_is_staff();
    $s      = function_exists('dyd_current') ? (dyd_current() ?? []) : [];
    $uid    = (int)($s['user_id'] ?? 0);
    $mode   = function_exists('dyd_mode') ? dyd_mode() : 'kursy';
    $c      = (int)($g['cur_course'] ?? 0);
    $gt     = fn(string $t) => 'index.php?tab=' . $t;
    $ct     = fn(string $t) => $c ? 'index.php?course=' . $c . '&tab=' . $t : 'index.php?tab=' . $t;
    $it     = fn(string $tab, string $label, string $href, string $icon, int $n = 0, string $v = 'secondary', bool $blank = false, string $js = '')
              => compact('tab', 'label', 'href', 'icon', 'n', 'v', 'blank', 'js');
    $nMsg   = (int)($g['dyd_msg_unread_total'] ?? 0);
    $nNot   = (int)($g['dyd_notices_unread'] ?? 0);
    $nAvail = isset($g['my_avail']) && is_array($g['my_avail']) ? count($g['my_avail']) : 0;
    $nProt  = isset($g['_my_pending_protocols']) && is_array($g['_my_pending_protocols']) ? count($g['_my_pending_protocols']) : 0;
    $nContr = isset($g['dyd_contracts']) && is_array($g['dyd_contracts'])
            ? count(array_filter($g['dyd_contracts'], fn($x) => in_array($x['status'] ?? '', ['podpisana', 'w realizacji'], true))) : 0;
    $canAn  = function_exists('dyd_can_analytics') && dyd_can_analytics();
    $isAdm  = function_exists('dyd_is_admin') && dyd_is_admin();
    $isKier = (function_exists('is_admin') && is_admin()) || (function_exists('ti_panel_role') && ti_panel_role($uid) === 'kierownik');
    $modes  = function_exists('dyd_modes_available') ? dyd_modes_available($s) : ['kursy'];
    $nLcMine = 0; $nLcQueue = 0;   // Kontrola Lekcji i Wpisów: moje zaległe / kolejka audytora
    try {
        require_once dirname(__DIR__, 3) . '/modules/ti_lesson_control/logic/lessonControl.php';
        $nLcMine = tiLcInstructorPendingCount($uid);
        if ($staff) $nLcQueue = tiLcAuditorQueueCount();
    } catch (\Throwable $e) {}

    $sec = [];

    if ($mode === 'konsultacje') {
        $sec[] = ['key' => 'konsultacje', 'label' => 'Konsultacje', 'items' => [
            $it('', 'Rezerwacje terminów',   'rezerwacje.php',               'calendar-check'),
            $it('', 'Kalendarz rezerwacji',  'rezerwacjeKalendarz.php',      'calendar3'),
            $it('', 'Szybka rezerwacja',     'rezerwacja.php?szybka=1',      'lightning'),
            $it('', 'Kolejka oczekujących',  'kolejka.php',                  'hourglass-split'),
            $it('', 'Karty konsultacji',     'konsultacje.php',              'clipboard2-check'),
            $it('', 'Nowa konsultacja',      'konsultacja.php?nowa=1',       'clipboard2-plus'),
            $it('', 'Klienci',               'konsultacjeKlienci.php',       'people'),
            $it('', 'Umowy PFRON',           'konsultacjePfron.php',         'building-fill-check'),
            $it('', 'Dokumenty i regulamin PFRON', 'konsultacjePfronDokumenty.php', 'file-earmark-text'),
            $it('', 'Wydruki i zestawienia', 'konsultacjeZestawienia.php',   'printer'),
            ...($staff ? [$it('', 'Ustawienia konsultacji', 'konsultacjeUstawienia.php', 'gear')] : []),
        ]];
        $sec[] = ['key' => 'komunikacja', 'label' => 'Komunikacja', 'items' => [
            $it('', 'E-mail / SMS do beneficjentów', 'konsultacjeKomunikacja.php', 'send'),
            $it('komunikaty', 'Komunikaty', $gt('komunikaty'), 'megaphone', $nNot, 'warning'),
        ]];
        $sec[] = ['key' => 'moje', 'label' => 'Moje', 'items' => [
            $it('', 'Mój certyfikat', 'moj_certyfikat.php', 'shield-check'),
            $it('', 'Moje godziny',   'godziny.php',        'hourglass-split'),
        ]];
    } else {
        $start = [
            $it('pulpit',          'Pulpit',            $gt('pulpit'),          'house'),
            $it('dostepnosc',      'Moja dostępność',   $gt('dostepnosc'),      'calendar-check', $nAvail),
            $it('',                'Moje protokoły',    'protokoly_moje.php',   'clipboard-check', $nProt, 'warning'),
            $it('',                'Moje wpisy',        'kontrolaWpisow.php',   'journal-check', $nLcMine, 'danger'),
            $it('',                'Protokoły zajęć',   'protokoly_zajec.php',  'journal-check'),
            $it('',                'Moje godziny',      'godziny.php',          'hourglass-split'),
            $it('frekwencja_grup', 'Frekwencja grup',   $gt('frekwencja_grup'), 'graph-up'),
            $it('wydruki',         'Wydruki',           $gt('wydruki'),         'printer'),
            $it('',                'Moje zgłoszenia',   'mojeZgloszenia.php',   'inbox'),
            $it('',                'Hospitacje',        'hospitacje.php',       'clipboard2-pulse'),
            $it('',                'Umowa',             'index.php?tab=formalnosci#umowa', 'file-earmark-text', $nContr, 'success'),
            $it('',                'Mój certyfikat',    'moj_certyfikat.php',   'shield-check'),
        ];
        if (function_exists('dyd_plan_cykliczny_enabled') && dyd_plan_cykliczny_enabled()) $start[] = $it('cykliczne', 'Plan cykliczny', $gt('cykliczne'), 'arrow-repeat');
        if ($canAn && !$staff) $start[] = $it('', 'Analityka TI', 'analityka.php', 'speedometer2');
        $start[] = $it('pomoc', 'Gdzie co jest', $gt('pomoc'), 'signpost-split');
        $start[] = $it('', 'Powiadomienia',   '#', 'bell',     0, 'secondary', false, 'window.dydShowFlashPref && window.dydShowFlashPref()');
        $start[] = $it('', 'Tour powitalny',  '#', 'signpost', 0, 'secondary', false, 'window.dydStartTour && window.dydStartTour()');
        $sec[] = ['key' => 'start', 'label' => 'Mój panel', 'items' => $start];

        // Kurs: zakładki bieżącej grupy z licznikami (index.php: $_sb_ctabs), poza index.php — same wejścia
        $kurs = [];
        $icons = ['lekcje' => 'calendar-week', 'uczestnicy' => 'people', 'plan' => 'calendar3', 'protokol' => 'card-checklist', 'zadania' => 'journal-check',
                  'materialy' => 'collection-play', 'nieobecnosci' => 'person-x', 'oceny' => 'journal-bookmark', 'program' => 'list-check', 'egzaminy' => 'patch-question'];
        if (!empty($g['_sb_ctabs']) && is_array($g['_sb_ctabs'])) {
            foreach ($g['_sb_ctabs'] as $k => [$ico, $lbl, $n, $v]) {
                if ($staff && $k === 'rozliczenia') continue;   // u kierownika w Finansach
                $kurs[] = $it((string)$k, (string)$lbl, (string)($g['_sb_chref'][$k] ?? $ct((string)$k)), (string)$ico, (int)$n, $v !== '' ? (string)$v : 'secondary');
            }
        } else {
            foreach (['lekcje' => 'Lekcje', 'uczestnicy' => 'Uczestnicy', 'plan' => 'Plan zajęć', 'protokol' => 'Protokoły', 'zadania' => 'Zadania', 'materialy' => 'Materiały',
                      'nieobecnosci' => 'Nieobecności', 'oceny' => 'Oceny', 'program' => 'Sylabus', 'egzaminy' => 'Testy i egzaminy'] as $k => $lbl) {
                $kurs[] = $it($k, $lbl, $ct($k), $icons[$k]);
            }
        }
        $kurs[] = $it('formalnosci', 'Formalności', $gt('formalnosci'), 'folder-check');
        $sec[] = ['key' => 'kurs', 'label' => 'Kurs', 'sub' => (string)($g['course']['name'] ?? ''), 'items' => $kurs];

        $sec[] = ['key' => 'komunikacja', 'label' => 'Komunikacja', 'items' => [
            $it('wiadomosci', 'Wiadomości', $gt('wiadomosci'), 'chat-dots', $nMsg, 'danger'),
            $it('komunikaty', 'Komunikaty', $gt('komunikaty'), 'megaphone', $nNot, 'warning'),
        ]];
        $zasoby = [
            $it('dysk', 'Mój dysk',              $gt('dysk'),               'hdd'),
            $it('zoom', 'Zajętość Zoom',         $gt('zoom'),               'camera-video'),
            $it('',     'Biblioteka materiałów', '../ext/index.php?as=dyd', 'collection'),
        ];
        if (in_array('konsultacje', $modes, true)) {
            $zasoby[] = $it('', 'Rezerwacje konsultacji', 'rezerwacje.php', 'calendar-check');
            $zasoby[] = $it('', 'Karty konsultacji',      'konsultacje.php', 'clipboard2-check');
        }
        $sec[] = ['key' => 'zasoby', 'label' => 'Zasoby', 'items' => $zasoby];
    }

    if (!$staff) return $sec;

    // ── Kierownik: grupy wg rodzaju pracy, od najczęściej używanych ──────────────
    $scPending = 0;
    try { require_once dirname(__DIR__, 3) . '/modules/ti_scheduler/logic/schedulerWorkflow.php'; $scPending = (int)sc_pending_count(); } catch (\Throwable $e) {}
    $sfPending = 0;
    try { $sfPending = (int)(db_one("SELECT COUNT(*) AS c FROM k30_ti_sfile WHERE status='pending' AND deleted_at IS NULL")['c'] ?? 0); } catch (\Throwable $e) {}
    $rozDebt = (int)($g['_sb_roz_debt'] ?? 0);

    $sec[] = ['key' => 'k_grupy', 'staff' => true, 'label' => 'Grupy i zajęcia', 'items' => [
        $it('grupy',        'Przegląd grup',           $gt('grupy'),          'people'),
        $it('kursy',        'Zarządzanie kursami',     $gt('kursy'),          'collection'),
        $it('',             'Szkolenia krótkie (SzK)', 'grupaUproszczona.php', 'lightning-charge'),
        $it('praca_wlasna', 'Praca własna',            $gt('praca_wlasna'),   'journal-text'),
        $it('',             'Linki do planów grup',    'planLinki.php',       'link-45deg'),
        $it('',             'Log operacji na grupach', 'log_grup.php',        'clock-history'),
    ]];
    $sec[] = ['key' => 'k_uczestnicy', 'staff' => true, 'label' => 'Uczestnicy', 'items' => [
        $it('', 'Ewidencja Uczestników', 'konta.php',             'person-badge'),
        $it('', 'Podgląd klientów',      'klienci.php',           'person-lines-fill'),
        $it('', 'Zapisy na zajęcia',     'rekrutacja.php',        'ticket-perforated'),
        $it('', 'Dokumenty kursantów',   'dokumentyKursanta.php', 'folder2-open', $sfPending, 'warning'),
        $it('', 'Licencje',              'licencjeKursantow.php', 'key'),
        $it('', 'Microsoft 365',         'microsoft365.php',      'microsoft'),
        $it('', 'Regulaminy',            'regulaminy.php',        'file-earmark-text'),
        $it('', 'Program poleceń',       'polecenia.php',         'gift'),
        $it('', 'Mail onboardingowy',    'kursanci_onboarding.php', 'envelope-paper'),
    ]];
    $sec[] = ['key' => 'k_finanse', 'staff' => true, 'label' => 'Finanse', 'items' => [
        $it('',            'Rozliczenia kursantów',    'billing.php',            'receipt'),
        $it('rozliczenia', 'Rozliczenia grupy',        $ct('rozliczenia'),       'receipt-cutoff', $rozDebt, 'danger'),
        $it('',            'Przypomnienia o wpłatach', 'przypomnieniaWplat.php', 'cash-coin'),
        $it('',            'Nadpłaty uczestników',     'overpayments.php',       'piggy-bank'),
        $it('',            'Cenniki i rabaty',         'pricing.php',            'tags'),
        $it('wypłaty',     'Wypłaty prowadzących',     $gt('wypłaty'),           'cash-stack'),
        $it('',            'Żetony SZO',               'zetony.php',             'ticket-detailed'),
    ]];
    $sec[] = ['key' => 'k_kontrola', 'staff' => true, 'label' => 'Kontrola i raporty', 'items' => array_values(array_filter([
        $it('', 'Zaległe protokoły',  'protokoly.php',         'exclamation-octagon'),
        $it('', 'Audyt dzienników',   'audyt_dziennikow.php',  'clipboard2-check'),
        $it('', 'Kontrola wpisów',    'kontrolaWpisow.php',    'journal-check', $nLcQueue, 'warning'),
        $it('', 'Hospitacje i wizytacje', 'hospitacje.php',    'clipboard2-pulse'),
        $it('', 'Wydruki i raporty',  'wydruki.php',           'printer'),
        $it('', 'Raport odwołanych',  'raport_odwolanych.php', 'calendar-x'),
        $canAn ? $it('', 'Analityka TI', 'analityka.php', 'speedometer2') : null,
    ]))];
    $sec[] = ['key' => 'k_plan', 'staff' => true, 'label' => 'Planowanie', 'items' => [
        $it('', 'Planista zajęć',         'planista.php',        'calendar3-range'),
        $it('', 'Zgłoszenia trenerów',    'zgloszenia.php',      'inbox', $scPending, 'warning'),
        $it('', 'Generator ciągów zajęć', 'generatorCiagow.php', 'magic'),
        $it('', 'Raporty planisty',       'raportyPlanisty.php', 'bar-chart-line'),
        $it('', 'Sale / lokalizacje',     'sale.php',            'geo-alt'),
        $it('', 'Okresy nauczania',       'okresy.php',          'calendar-range'),
        $it('', 'Dni wolne',              'dni_wolne.php',       'calendar-x'),
        $it('', 'Rodzaje zajęć',          'przedmioty.php',      'tags'),
    ]];
    $sec[] = ['key' => 'k_kadra', 'staff' => true, 'label' => 'Kadra', 'items' => array_values(array_filter([
        $it('', 'Dostępności prowadzących',    'dostepnosci.php',  'clock-history'),
        $it('', 'Urlopy prowadzących',         'urlopy.php',       'airplane'),
        $it('', 'Godziny prowadzących',        'godziny.php',      'hourglass-split'),
        $it('', 'Limity i kwalifikacje kadry', 'kadraLimity.php',  'person-gear'),
        $it('', 'Zespół i role',               'zespol.php',       'people-fill'),
        $isKier ? $it('', 'Certyfikaty prowadzących', 'certyfikaty.php', 'shield-check') : null,
        $it('', 'Role w panelu TI', rtrim(APP_URL, '/') . '/tozsamosc/ti_role.php', 'person-gear'),
    ]))];
    $sec[] = ['key' => 'k_komunikacja', 'staff' => true, 'label' => 'Komunikacja z kursantami', 'items' => [
        $it('komunikacja', 'SMS i mailing',   $gt('komunikacja'),   'send'),
        $it('',            'Szablony e-mail', 'szablonyEmail.php',  'envelope-paper'),
        $it('',            'Szablony SMS',    'szablonySms.php',    'chat-left-text'),
    ]];
    $sec[] = ['key' => 'k_system', 'staff' => true, 'label' => 'System', 'items' => array_values(array_filter([
        $it('', 'Wyłączenia panelu', 'wylaczenia.php', 'moon'),
        $it('', 'Testy',             'testy.php',      'bug'),
        $isAdm ? $it('', 'Konta testowe', 'kontaTestowe.php', 'person-badge') : null,
        $it('', 'Pełny moduł TI', rtrim(APP_URL, '/') . '/karty30/ti/index.php', 'box-arrow-up-right', 0, 'secondary', true),
    ]))];
    return $sec;
}

/** Czy pozycja wskazuje bieżący ekran: zakładka index.php albo ten sam plik z pasującymi parametrami. */
function dydMenuIsActive(array $i): bool
{
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($i['js'] !== '' || $i['href'] === '#') return false;
    if ($script === 'index.php') return $i['tab'] !== '' && $i['tab'] === (string)($GLOBALS['tab'] ?? '');
    $u = parse_url($i['href']);
    if (basename((string)($u['path'] ?? '')) !== $script || str_contains((string)($u['path'] ?? ''), '/')) return false;
    parse_str((string)($u['query'] ?? ''), $q);
    foreach ($q as $k => $v) if ((string)($_GET[$k] ?? '') !== (string)$v) return false;
    return true;
}

/** HTML menu (wołane w _layout_foot.php). Grupy jako akordeon: otwarta jedna (z bieżącą stroną), reszta jednym kliknięciem. */
function dydMenuRender(): string
{
    $secs = dydMenuSections();
    // Bieżąca pozycja (pierwsza pasująca) i grupa, która ją zawiera — ta grupa jest otwarta
    $activeKey = '';
    foreach ($secs as $si => $s) {
        foreach ($s['items'] as $ii => $i) {
            if ($activeKey === '' && dydMenuIsActive($i)) { $activeKey = $s['key']; $secs[$si]['items'][$ii]['active'] = true; }
        }
    }
    if ($activeKey === '') $activeKey = (string)($secs[0]['key'] ?? '');
    ob_start(); ?>
<nav class="dyd-side" id="dydSide" aria-label="Menu panelu dydaktyka">
  <div class="dyd-side-in">
  <?php $staffHead = false; foreach ($secs as $s):
    $open = $s['key'] === $activeKey;
    $sum  = array_sum(array_map(fn($i) => (int)$i['n'], $s['items']));
    $warn = $sum > 0 && array_filter($s['items'], fn($i) => $i['n'] > 0 && $i['v'] === 'danger');
    if (!empty($s['staff']) && !$staffHead): $staffHead = true; ?>
    <div class="dyd-side-band" aria-hidden="true">Kierownik</div>
  <?php endif; ?>
    <section class="dyd-side-sec<?= $open ? ' is-open' : '' ?>">
      <h2><button type="button" class="dyd-side-grp" id="dydSideB-<?= h($s['key']) ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="dydSideL-<?= h($s['key']) ?>">
        <span class="dyd-side-grp-l"><?= !empty($s['staff']) ? '<span class="visually-hidden">Kierownik: </span>' : '' ?><?= h($s['label']) ?></span>
        <?php if ($sum > 0): ?><span class="badge <?= $warn ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= $sum ?><span class="visually-hidden"> do uwagi</span></span><?php endif; ?>
        <i class="bi bi-chevron-down dyd-side-chev" aria-hidden="true"></i>
      </button></h2>
      <ul id="dydSideL-<?= h($s['key']) ?>"<?= $open ? '' : ' hidden' ?>>
      <?php if (($s['sub'] ?? '') !== ''): ?><li class="dyd-side-sub"><?= h($s['sub']) ?></li><?php endif; ?>
      <?php foreach ($s['items'] as $i): $act = !empty($i['active']); ?>
        <li><a href="<?= h($i['href']) ?>"<?= $act ? ' class="active" aria-current="page"' : '' ?><?= $i['blank'] ? ' target="_blank" rel="noopener"' : '' ?><?= $i['js'] !== '' ? ' role="button" onclick="' . h($i['js']) . '; return false;"' : '' ?>>
          <i class="bi bi-<?= h($i['icon']) ?>" aria-hidden="true"></i><span class="dyd-side-t"><?= h($i['label']) ?></span>
          <?php if ($i['n'] > 0): ?><span class="badge text-bg-<?= h($i['v']) ?>"><?= (int)$i['n'] ?></span><?php endif; ?>
          <?php if ($i['blank']): ?><i class="bi bi-box-arrow-up-right dyd-side-ext" aria-hidden="true"></i><span class="visually-hidden"> (otwiera się w nowej karcie)</span><?php endif; ?>
        </a></li>
      <?php endforeach; ?>
      </ul>
    </section>
  <?php endforeach; ?>
  </div>
</nav>
<script>
(function () {
  var nav = document.getElementById('dydSide'), hdr = document.querySelector('body > header'), btn = document.getElementById('dydSideToggle');
  if (!nav) return;
  // Menu zaczyna się pod paskiem górnym, a po jego zniknięciu przy przewijaniu — od górnej krawędzi ekranu
  var tick = false;
  function place() { tick = false; var b = hdr ? Math.max(0, hdr.getBoundingClientRect().bottom) : 0; document.documentElement.style.setProperty('--dyd-side-top', b + 'px'); }
  function req() { if (!tick) { tick = true; requestAnimationFrame(place); } }
  place(); window.addEventListener('scroll', req, { passive: true }); window.addEventListener('resize', req);
  // Akordeon: otwarta jedna grupa; otwarcie innej zamyka poprzednią (menu mieści się bez przewijania)
  nav.addEventListener('click', function (e) {
    var b = e.target.closest('.dyd-side-grp'); if (!b) return;
    var willOpen = b.getAttribute('aria-expanded') !== 'true';
    nav.querySelectorAll('.dyd-side-grp[aria-expanded="true"]').forEach(function (o) {
      if (o === b) return; o.setAttribute('aria-expanded', 'false'); document.getElementById(o.getAttribute('aria-controls')).hidden = true; o.closest('.dyd-side-sec').classList.remove('is-open');
    });
    b.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    document.getElementById(b.getAttribute('aria-controls')).hidden = !willOpen;
    b.closest('.dyd-side-sec').classList.toggle('is-open', willOpen);
  });
  // Telefon: menu wysuwane przyciskiem „Menu”; Esc zamyka, fokus wraca na przycisk
  function open(on) { document.documentElement.classList.toggle('dyd-side-open', on); if (btn) btn.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) { var f = nav.querySelector('a.active') || nav.querySelector('.dyd-side-grp'); if (f) f.focus(); } else if (btn) btn.focus(); }
  if (btn) btn.addEventListener('click', function () { open(!document.documentElement.classList.contains('dyd-side-open')); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && document.documentElement.classList.contains('dyd-side-open')) open(false); });
  var ov = document.getElementById('dydSideOverlay'); if (ov) ov.addEventListener('click', function () { open(false); });
})();
</script>
<?php
    return (string)ob_get_clean();
}

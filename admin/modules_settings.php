<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
$PAGE_TITLE = 'Moduły';

$module_groups = [
    'Moduły systemu' => [
        'crm_enabled'          => ['label' => 'CRM',                           'icon' => 'bi-people-fill',           'desc' => 'Zarządzanie kontaktami, sprawami CRM, notatkami, pismami i powiązaniami z EZD.',     'config' => 'crm_access.php'],
        'ezd_enabled'          => ['label' => 'Wirtualne biurko',                'icon' => 'bi-building-gear',         'desc' => 'Elektroniczne zarządzanie dokumentacją — Teczki, Sprawy, Pisma, Umowy, Dekretacje.'],
        'org_enabled'          => ['label' => 'Struktura Organizacyjna',       'icon' => 'bi-diagram-3',             'desc' => 'Hierarchia jednostek, stanowiska, przypisania osobowe, zastępstwa i historia zmian.'],
        'procedures_enabled'   => ['label' => 'Procedury wewnętrzne',          'icon' => 'bi-journal-bookmark-fill', 'desc' => 'Rejestr procedur z wersjonowaniem, powiązaniami i załącznikami.'],
        'doc_signing_enabled'  => ['label' => 'Podpisz dokument',              'icon' => 'bi-pen',                   'desc' => 'Wgrywanie dokumentów do podpisania własnym certyfikatem X.509 poza systemem, z kryptograficzną weryfikacją podpisu po wgraniu.'],
        'tasks_enabled'        => ['label' => 'Tablica zadań (Kanban)',        'icon' => 'bi-kanban',           'desc' => 'Zarządzanie zadaniami — widoczne dla wszystkich zalogowanych użytkowników.'],
        'messages_enabled'     => ['label' => 'System wiadomości',             'icon' => 'bi-chat-dots',        'desc' => 'Komunikacja między administratorami a wolontariuszami i wykonawcami.',          'config' => 'msg_settings.php'],
        'onboarding_enabled'   => ['label' => 'Zgłoszenia wolontariuszy',      'icon' => 'bi-person-plus',      'desc' => 'Formularz samodzielnego zgłoszenia i kreator onboardingu dla nowych wolontariuszy.', 'config' => 'onboarding_settings.php'],
        'reports_enabled'      => ['label' => 'Zestawienia i raporty',         'icon' => 'bi-bar-chart-line',   'desc' => 'Statystyki, wykresy i eksport danych umów.'],
        'approvals_enabled'    => ['label' => 'Obieg dokumentów (akceptacje)', 'icon' => 'bi-check2-square',    'desc' => 'Wnioski o zmiany umów, ścieżki akceptacji, aneksy.',                              'config' => 'approval_workflows.php'],
        'obiegi_enabled'       => ['label' => 'Obiegi (micro-BPM)',            'icon' => 'bi-diagram-2',        'desc' => 'Uniwersalne procesy z automatycznym przekazywaniem i zatwierdzaniem — definiowane kroki przypisane do ról.', 'config' => 'obiegi.php'],
        'terminations_enabled' => ['label' => 'Rozwiązania umów',              'icon' => 'bi-file-earmark-x',   'desc' => 'Wnioski o rozwiązanie umowy składane przez wolontariuszy i wykonawców.'],
        'dyspozycyjnosc_enabled' => ['label' => 'Dyspozycyjność i urlopy',      'icon' => 'bi-calendar-heart',   'desc' => 'Wolontariusz sam określa terminy dostępności i zgłasza urlopy; opiekun formalnie je zatwierdza.'],
        'certificates_enabled' => ['label' => 'Zaświadczenia',                 'icon' => 'bi-award',            'desc' => 'Generowanie i wydawanie zaświadczeń dla wolontariuszy i wykonawców.',             'config' => 'certificates.php'],
        'invoices_enabled'     => ['label' => 'Faktury',                       'icon' => 'bi-receipt',          'desc' => 'Rejestr faktur wystawianych z ofert CRM, rozliczeń TI i ręcznie. Dokument księgowy powstaje w fakturownia.pl albo KSeF; SZO trzyma kontekst, numer, PDF i status płatności. Faktury z TI mają własną serię TI/nr/mm/rok i załącznik z rozliczeniem środków.', 'config' => 'invoices_settings.php'],
        'letters_enabled'      => ['label' => 'Pisma',                         'icon' => 'bi-envelope-paper',   'desc' => 'Pisma i korespondencja generowana w kontekście umów.'],
        'moodle_enabled'          => ['label' => 'Moodle — e-learning',           'icon' => 'bi-mortarboard',        'desc' => 'Integracja z platformą Moodle — zapisy na kursy, synchronizacja użytkowników.',          'config' => 'moodle.php'],
        'correspondence_enabled'  => ['label' => 'Korespondencja',               'icon' => 'bi-mailbox2',           'desc' => 'Rejestr korespondencji przychodzącej i wychodzącej — nadawcy, odbiorcy, statusy, załączniki.'],
        'wsparcie_ou_enabled'     => ['label' => 'Wsparcie zewnętrzne OU',        'icon' => 'bi-building-add',       'desc' => 'Ewidencja godzin wsparcia świadczonego przez podmioty zewnętrzne (wyszukiwane po KRS), w rozbiciu na miesiące, z zatwierdzaniem/odrzucaniem.'],
        'resolutions_enabled'     => ['label' => 'Uchwały i Zarządzenia',        'icon' => 'bi-hammer',             'desc' => 'Rejestr uchwał, zarządzeń i decyzji z automatyczną numeracją, treścią i skanami.'],
        'events_enabled'          => ['label' => 'Moduł Wydarzeń',               'icon' => 'bi-calendar-event',     'desc' => 'Organizacja wydarzeń online (webinary) i stacjonarnych — rejestracja uczestników, bilety QR, check-in, integracja CRM.', 'config' => 'events_settings.php'],
        'poczta_enabled'          => ['label' => 'Moduł Poczty',                 'icon' => 'bi-envelope',           'desc' => 'Automatyczne skanowanie wybranych skrzynek Microsoft 365 w tle i wiązanie e-maili z osią czasu kontaktów CRM.', 'config' => 'poczta_settings.php'],
        'org_calendar_enabled'    => ['label' => 'Kalendarz organizacji (ICS)',  'icon' => 'bi-calendar3',          'desc' => 'Kalendarz organizacji pobierany z kanału ICS (Outlook 365, Google Calendar i inne) — widoczny w panelu wolontariusza jako lista wydarzeń.', 'config' => 'org_calendar.php'],
        'byli_enabled'            => ['label' => 'Rejestr byłych osób',           'icon' => 'bi-person-dash',        'desc' => 'Rejestr byłych współpracowników — imię, nazwisko, miasto, okres współpracy, powód odejścia i uwagi (z opcją zastrzeżenia danych dla zarządu).'],
        'dostepnosc_ngo_enabled'  => ['label' => 'Dostępność NGO',                 'icon' => 'bi-universal-access',        'desc' => 'Zgłoszenia asysty na wydarzeniach, specjalne potrzeby wolontariuszy (PJM, wózek, pętla indukcyjna, neuroróżnorodność, dostępność komunikacyjno-informacyjna).'],
        'projekty_enabled'        => ['label' => 'Dedykowane dla projektów',       'icon' => 'bi-folder-symlink',          'desc' => 'Narzędzia dedykowane konkretnym projektom: karty doradztwa ADNGO, formularze zewnętrzne per-projekt.'],
    ],
    'Integracje i bezpieczeństwo' => [
        'm365_enabled'         => ['label' => 'Microsoft 365 / Azure AD',     'icon' => 'bi-microsoft',        'desc' => 'Logowanie OAuth, provisioning kont M365, synchronizacja użytkowników i grup.',    'config' => 'm365_settings.php'],
        'sms_enabled'          => ['label' => 'Bramka SMS',                   'icon' => 'bi-phone',            'desc' => 'Wysyłka powiadomień SMS (kody fallback, przypomnienia, alerty).',                 'config' => 'sms_settings.php'],
        'ika_enabled'          => ['label' => 'Kody IKA (autoryzacja)',       'icon' => 'bi-shield-lock',      'desc' => 'Indywidualny Kod Autoryzacyjny — dwuetapowe potwierdzenie operacji krytycznych.', 'config' => 'manage_cpc.php'],
        'webauthn_enabled'     => ['label' => 'Klucze sprzętowe (WebAuthn)', 'icon' => 'bi-usb-plug',         'desc' => 'Uwierzytelnianie kluczami FIDO2/WebAuthn (YubiKey i inne tokeny fizyczne).',       'config' => 'login_settings.php'],
        'mobile_pin_enabled'   => ['label' => 'Logowanie PIN-em (dialer)',   'icon' => 'bi-telephone-outbound', 'desc' => 'Szybkie wejście do mobilnej aplikacji „Dzwoń" numerem konta (UID) i PIN-em, zamiast Microsoft 365. Sesja otwiera WYŁĄCZNIE dialer i nadal wymaga kodu IKA. Nieaktywne, dopóki użytkownik sam nie ustawi PIN-u w module Tożsamość; blokada pojedynczego konta — w karcie użytkownika.'],
        'vpn_enabled'          => ['label' => 'VPN — dostęp do sieci',       'icon' => 'bi-shield-lock',      'desc' => 'Wnioski o dostęp do VPN, zatwierdzanie i wydawanie konfiguracji, ewidencja dostępów. Blokadę „moduł tylko przez VPN" (np. EZD) włączasz w ustawieniach organizacji.', 'config' => 'vpn.php'],
        'cloudflare_enabled'   => ['label' => 'Cloudflare DNS',              'icon' => 'bi-globe2',           'desc' => 'Wizualne zarządzanie rekordami DNS (A/AAAA/CNAME/TXT/MX/NS/SRV/CAA) stref widocznych dla skonfigurowanego tokenu API Cloudflare.', 'config' => 'cloudflare_settings.php'],
        'apaczka_enabled'      => ['label' => 'Apaczka — przesyłki',         'icon' => 'bi-box-seam',         'desc' => 'Integracja z platformą Apaczka do zamawiania i śledzenia przesyłek kurierskich.',  'config' => 'apaczka_settings.php'],
        'furgonetka_enabled'   => ['label' => 'Furgonetka — przesyłki',      'icon' => 'bi-truck',            'desc' => 'Integracja z platformą Furgonetka do zamawiania przesyłek i etykiet.',             'config' => 'furgonetka_settings.php'],
        'ceidg_enabled'        => ['label' => 'CEIDG — weryfikacja firm',     'icon' => 'bi-building-check',   'desc' => 'Automatyczna weryfikacja danych wykonawców w rejestrze CEIDG (GUS).',              'config' => 'ceidg_settings.php'],
        'tidycal_enabled'      => ['label' => 'TidyCal — rezerwacja szkoleń', 'icon' => 'bi-calendar2-check',  'desc' => 'Rezerwacja terminów szkoleń z panelu i portalu przez kalendarz TidyCal (wolne terminy, potwierdzenia).', 'config' => 'tidycal_settings.php'],
    ],
    'Typy umów' => [
        'contract_wolontariat' => ['label' => 'Umowa wolontariacka',           'icon' => 'bi-heart',            'desc' => 'Porozumienia wolontariackie (ustawa o działalności pożytku publicznego i o wolontariacie).'],
        'contract_zlecenie'    => ['label' => 'Umowa zlecenie',                'icon' => 'bi-person-lines-fill','desc' => 'Umowy zlecenie z osobami fizycznymi — CIT/PIT, ZUS.'],
        'contract_uslugi'      => ['label' => 'Umowa o świadczenie usług',     'icon' => 'bi-briefcase',        'desc' => 'Umowy z firmami i przedsiębiorcami prowadzącymi działalność.'],
        'contract_dzielo'      => ['label' => 'Umowa o dzieło',                'icon' => 'bi-palette',          'desc' => 'Umowy o dzieło — jednorazowe projekty twórcze lub techniczne.'],
        'contract_praca'       => ['label' => 'Umowa o pracę',                 'icon' => 'bi-building',         'desc' => 'Umowy o pracę i dokumenty kadrowe (Kodeks pracy).'],
        'contract_powierzenie' => ['label' => 'Umowa powierzenia zadania publicznego', 'icon' => 'bi-bank',     'desc' => 'Umowy o powierzenie / wsparcie realizacji zadania publicznego z dotacją (ustawa o działalności pożytku publicznego, art. 16).'],
        'contract_inne'        => ['label' => 'Inna umowa',                    'icon' => 'bi-file-text',        'desc' => 'Niestandardowe umowy i dokumenty nieujęte w pozostałych typach.'],
    ],
];

// Wczytaj aktualne wartości
$cfg = [];
foreach ($module_groups as $group_modules) {
    foreach ($group_modules as $k => $_) {
        $r = db_one("SELECT value FROM settings WHERE key_=?", [$k]);
        $cfg[$k] = ($r['value'] ?? '1');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($module_groups as $group_modules) {
        foreach ($group_modules as $k => $_) {
            $v = isset($_POST[$k]) ? '1' : '0';
            $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$k]);
            if ($exists) {
                db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$v, $k]);
            } else {
                db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$k, $v]);
            }
            $cfg[$k] = $v;
        }
    }
    log_system_action((int)current_user()['id'], 'settings_save', 'Ustawienia modułów zapisane.');
    flash_set('success', 'Ustawienia modułów zapisane.');
    header('Location: modules_settings.php'); exit;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h4 class="mb-0"><i class="bi bi-toggles2 text-primary me-2"></i>Moduły</h4>
</div>
<?= flash_html() ?>

<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-4">
<?php
$_group_icons = [
    'Moduły systemu'               => 'bi-grid-3x3-gap',
    'Integracje i bezpieczeństwo'  => 'bi-plug',
    'Typy umów'                    => 'bi-file-earmark-text',
];
$_group_index = 0;
foreach ($module_groups as $group_name => $group_modules):
    $group_key     = preg_replace('/[^a-z0-9]/i', '_', strtolower($group_name));
    $group_icon    = $_group_icons[$group_name] ?? 'bi-grid-3x3-gap';
    $enabled_count = array_sum(array_map(fn($k) => $cfg[$k] === '1' ? 1 : 0, array_keys($group_modules)));
    $total_count   = count($group_modules);
    $_group_index++;
?>
<div class="col-lg-6">
  <div class="card shadow-sm h-100">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span class="fw-semibold">
        <i class="bi <?= $group_icon ?> me-1 text-primary"></i>
        <?= h($group_name) ?>
      </span>
      <span class="badge bg-<?= $enabled_count === $total_count ? 'success' : ($enabled_count === 0 ? 'secondary' : 'warning text-dark') ?>">
        <?= $enabled_count ?>/<?= $total_count ?> aktywnych
      </span>
    </div>
    <div class="card-body p-0">
      <?php foreach ($group_modules as $k => $m):
        $on = $cfg[$k] === '1';
      ?>
      <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom module-row<?= $on ? '' : ' module-row-off' ?>"
           data-module="<?= $k ?>">
        <div class="form-check form-switch mb-0 flex-shrink-0">
          <input class="form-check-input module-toggle" type="checkbox" role="switch"
                 name="<?= $k ?>" id="mod_<?= $k ?>" value="1"
                 <?= $on ? 'checked' : '' ?>
                 onchange="updateRow(this)">
        </div>
        <label class="flex-grow-1 mb-0" for="mod_<?= $k ?>" style="cursor:pointer">
          <div class="fw-semibold d-flex align-items-center gap-1" style="font-size:.88rem">
            <i class="bi <?= $m['icon'] ?> text-<?= $on ? 'primary' : 'secondary' ?> mod-icon"></i>
            <?= h($m['label']) ?>
            <?php if (!empty($m['config']) && $on): ?>
            <a href="<?= APP_URL ?>/admin/<?= h($m['config']) ?>"
               class="ms-1 text-muted" style="font-size:.7rem;font-weight:400"
               onclick="event.stopPropagation()">
              <i class="bi bi-gear-fill"></i> Konfiguruj
            </a>
            <?php endif; ?>
          </div>
          <div class="text-muted" style="font-size:.75rem;line-height:1.3"><?= h($m['desc']) ?></div>
        </label>
        <span class="badge bg-<?= $on ? 'success' : 'secondary' ?> flex-shrink-0 mod-badge" style="font-size:.68rem;min-width:58px;text-align:center">
          <?= $on ? 'Aktywny' : 'Wyłączony' ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="card-footer d-flex gap-2 py-2">
      <button type="button" class="btn btn-outline-secondary btn-sm"
              onclick="toggleGroup(<?= $_group_index - 1 ?>, true)">
        <i class="bi bi-check-all me-1"></i>Włącz wszystkie
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm"
              onclick="toggleGroup(<?= $_group_index - 1 ?>, false)">
        <i class="bi bi-dash-square me-1"></i>Wyłącz wszystkie
      </button>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<div class="mt-4">
  <button type="submit" class="btn btn-primary px-4">
    <i class="bi bi-floppy me-1"></i> Zapisz ustawienia
  </button>
  <span class="text-muted small ms-3">
    <i class="bi bi-info-circle"></i>
    Wyłączone moduły są ukryte w nawigacji. Bezpośredni dostęp przez URL jest blokowany.
  </span>
</div>
</form>

<style>
.module-row { transition: background .15s; }
.module-row-off { background: #f8fafc; }
.module-row-off .fw-semibold { color: #94a3b8 !important; }
.module-row-off .text-muted { opacity: .7; }
</style>
<script>
function updateRow(checkbox) {
    var row   = checkbox.closest('.module-row');
    var badge = row.querySelector('.mod-badge');
    var icon  = row.querySelector('.mod-icon');
    var on    = checkbox.checked;
    row.classList.toggle('module-row-off', !on);
    if (badge) {
        badge.textContent = on ? 'Aktywny' : 'Wyłączony';
        badge.className   = 'badge flex-shrink-0 mod-badge ' + (on ? 'bg-success' : 'bg-secondary');
        badge.style.fontSize   = '.68rem';
        badge.style.minWidth   = '58px';
        badge.style.textAlign  = 'center';
    }
    if (icon) {
        icon.className = icon.className.replace(/text-\w+/, 'text-' + (on ? 'primary' : 'secondary'));
    }
    // Update group counter
    var card  = checkbox.closest('.card');
    var rows  = card.querySelectorAll('.module-toggle');
    var total = rows.length;
    var enab  = Array.from(rows).filter(c => c.checked).length;
    var gbadge = card.querySelector('.card-header .badge');
    if (gbadge) {
        gbadge.textContent = enab + '/' + total + ' aktywnych';
        gbadge.className   = 'badge bg-' + (enab === total ? 'success' : (enab === 0 ? 'secondary' : 'warning text-dark'));
    }
}

function toggleGroup(idx, enable) {
    var cards = document.querySelectorAll('.col-lg-6');
    var card  = cards[idx];
    if (!card) return;
    card.querySelectorAll('.module-toggle').forEach(function(cb) {
        if (cb.checked !== enable) {
            cb.checked = enable;
            updateRow(cb);
        }
    });
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

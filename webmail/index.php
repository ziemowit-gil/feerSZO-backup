<?php
/**
 * webmail/index.php — strona wyboru klienta poczty (poczta.feer.org.pl).
 *
 * PUBLICZNA, bez logowania: rozjazd do dwóch webmaili TEJ SAMEJ skrzynki
 * Microsoft 365 — Outlook w przeglądarce (OWA) albo Roundcube. Nie ma tu
 * danych osobowych ani sesji użytkownika, więc nie ma czego bramkować.
 *
 * WYGLĄD: świadomie ta sama powłoka co ekrany wejścia do systemu
 * (includes/auth_screen.php — logowanie, rejestracja, odzyskiwanie dostępu):
 * tło w kolorze marki, pasek dostępności, białe logo, biała karta, te same
 * przyciski .ks-btn. Dla użytkownika to jeden ciąg „ekranów wejścia", a nie
 * osobna, obco wyglądająca strona.
 *
 * ROUTING (dwa adresy, jedna strona):
 *   • poczta.feer.org.pl        → .htaccess przepisuje host `poczta.` na /webmail/
 *                                 + router Traefika `feer-poczta` (docker-compose.prod.yml)
 *   • szo.feer.org.pl/poczta    → alias w .htaccess (goły katalog; moduł Poczta
 *                                 działa dalej pod /poczta/dashboard.php itd.)
 *
 * ADRESY webmaili: z ustawień organizacji (Admin → Poczta → Webmail), z twardymi
 * wartościami zapasowymi.
 *
 * Kontekst treści: od 1 września 2026 współpracownicy wybierają klienta sami
 * (wcześniej de facto tylko Outlook). Strona ma odpowiedzieć na jedno pytanie —
 * „w czym czytać pocztę i co tracę, wybierając lżejszy klient".
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$OWA_URL = rtrim(org_setting('poczta_owa_url')     ?: 'https://outlook.office.com/mail', '/') . '/';
$RC_URL  = rtrim(org_setting('poczta_webmail_url') ?: 'https://rc.feer.org.pl', '/');

/** Wiersze porównania: [cecha, Outlook, Roundcube, przypis] */
$ROWS = [
    ['Ta sama skrzynka i te same wiadomości',            'yes',  'yes',  ''],
    ['Logowanie kontem Microsoft (bez osobnego hasła)',  'yes',  'yes',  ''],
    ['Kalendarz i zapraszanie na spotkania',             'yes',  'no',   'Roundcube nie ma kalendarza'],
    ['Teams, czat, plan dnia',                           'yes',  'no',   ''],
    ['Książka adresowa organizacji',                     'yes',  'part', 'W Roundcube kopia kontaktów z Outlooka, odświeżana przy logowaniu'],
    ['Skrzynki współdzielone i dostęp w zastępstwie',     'yes',  'no',   'Np. fundacja@feer.org.pl — tylko w Outlooku'],
    ['Reguły sortowania i autoodpowiedź',                'yes',  'no',   'Ustawiasz raz w Outlooku — działają dla obu klientów'],
    ['Załączanie plików z OneDrive',                     'yes',  'yes',  ''],
    ['Załączanie plików z ownCloud (magazyn zespołowy)', 'no',   'yes',  ''],
    ['Aplikacja na telefon',                             'yes',  'part', 'Outlook: aplikacja iOS/Android. Roundcube: przez przeglądarkę'],
    ['Działa przy słabym łączu i na starszym sprzęcie',  'part', 'yes',  ''],
];

$MARKS = [
    'yes'  => ['✓', 'yes',  'tak'],
    'no'   => ['—', 'no',   'nie'],
    'part' => ['≈', 'part', 'częściowo'],
];

require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'     => 'Poczta — wybierz klienta',
    'tab'       => '',
    'bootstrap' => true,
    'width'     => 780,
    'narrow'    => false,
    'main_id'   => 'poczta-main',
]);
?>

<style>
/* Tabela porównania — jedyny element, którego powłoka ekranów wejścia nie ma.
   Trzyma się jej tokenów (--ks-*), żeby nie odklejała się wizualnie od karty. */
.pw-tbl-wrap{overflow-x:auto;border:1px solid #e5e7eb;border-radius:12px;margin:.25rem 0 0}
.pw-tbl{border-collapse:collapse;width:100%;min-width:520px;font-size:.88rem}
.pw-tbl th,.pw-tbl td{padding:.6rem .8rem;text-align:left;border-top:1px solid #e5e7eb;vertical-align:top}
.pw-tbl thead th{background:var(--c-bg,rgba(220,38,38,.07));color:var(--ks-ink);border-top:0;
  font-size:.82rem;white-space:nowrap}
.pw-tbl tbody th{font-weight:600;color:var(--ks-ink)}
.pw-tbl tbody th small{display:block;font-weight:400;color:var(--ks-muted);font-size:.78rem;
  line-height:1.4;margin-top:.1rem}
.pw-tbl td.v{width:6.5rem;text-align:center;font-weight:700}
.pw-yes{color:#15803d}
.pw-no{color:var(--ks-muted)}
.pw-part{color:#b45309}
.pw-opt{display:block;text-align:left}
/* Podpis pod nazwą klienta: na przycisku w kolorze marki szary tekst .ks-optsub
   z powłoki logowania jest nieczytelny — rozjaśniamy. */
.ks-btn--primary .ks-optsub{color:rgba(255,255,255,.85)}
.pw-opt .ks-optsub{margin-top:.15rem}
.pw-last{font-size:.82rem;color:var(--ks-muted);text-align:center;margin:.9rem 0 0;display:none}
.pw-last b{color:var(--ks-ink)}
.pw-diff-h{font-size:.95rem;font-weight:700;margin:0 0 .6rem}
</style>

<h1 class="ks-h1">Poczta organizacji</h1>
<p class="ks-lead">
  Wybierz, w czym czytasz służbową pocztę. Ta sama skrzynka i te same wiadomości —
  różni się widok i to, co program dodatkowo potrafi.
</p>

<div class="l-alert l-alert-info">
  <i class="bi bi-calendar-check" aria-hidden="true"></i>
  <span>
    <strong>Od 1 września 2026 wybór należy do Ciebie</strong> — a korzystać możesz
    <strong>już teraz</strong>, bez zgłaszania czegokolwiek. Wybór nie jest na zawsze:
    w każdej chwili wchodzisz z drugiego klienta na tę samą skrzynkę, możesz też używać
    obu równolegle. Żadna wiadomość przy tym nie ginie.
  </span>
</div>

<a href="<?= h($OWA_URL) ?>" class="ks-btn ks-btn--primary" data-pick="outlook" rel="noopener">
  <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 23 23" aria-hidden="true" focusable="false">
    <path fill="#f35325" d="M1 1h10v10H1z"/><path fill="#81bc06" d="M12 1h10v10H12z"/>
    <path fill="#05a6f0" d="M1 12h10v10H1z"/><path fill="#ffba08" d="M12 12h10v10H12z"/>
  </svg>
  <span class="pw-opt">Outlook w przeglądarce
    <span class="ks-optsub">pełny Microsoft 365 — poczta, kalendarz, Teams, skrzynki wspólne</span>
  </span>
</a>

<a href="<?= h($RC_URL) ?>" class="ks-btn ks-btn--ghost" data-pick="roundcube" rel="noopener">
  <i class="bi bi-envelope-open" aria-hidden="true"></i>
  <span class="pw-opt">Roundcube
    <span class="ks-optsub">lekki webmail — sama poczta, szybki na słabym łączu</span>
  </span>
</a>

<p class="pw-last" id="pw-last" role="status"></p>

<div class="ks-or">czym się różnią</div>

<div class="pw-tbl-wrap">
  <table class="pw-tbl">
    <caption class="sr">Porównanie: Outlook w przeglądarce a Roundcube</caption>
    <thead>
      <tr>
        <th scope="col">Co potrafi</th>
        <th scope="col">Outlook</th>
        <th scope="col">Roundcube</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($ROWS as [$feature, $owa, $rc, $note]):
        [$o_sym, $o_cls, $o_txt] = $MARKS[$owa];
        [$r_sym, $r_cls, $r_txt] = $MARKS[$rc];
    ?>
      <tr>
        <th scope="row"><?= h($feature) ?><?php if ($note): ?><small><?= h($note) ?></small><?php endif; ?></th>
        <td class="v"><span class="pw-<?= $o_cls ?>" aria-hidden="true"><?= $o_sym ?></span><span class="sr"><?= h($o_txt) ?></span></td>
        <td class="v"><span class="pw-<?= $r_cls ?>" aria-hidden="true"><?= $r_sym ?></span><span class="sr"><?= h($r_txt) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<hr class="ks-sep">

<p class="pw-diff-h">Nie wiesz, co wybrać?</p>
<p class="ks-note" style="text-align:left">
  <strong>Umawiasz spotkania, prowadzisz kalendarz albo obsługujesz skrzynkę wspólną</strong>
  (np. fundacja@feer.org.pl) → Outlook; Roundcube tego nie ma.<br>
  <strong>Chcesz tylko przeczytać i odpisać</strong>, często z telefonu lub słabego łącza
  → Roundcube; wystarczy i działa szybciej.<br>
  <strong>Nie masz preferencji</strong> → zacznij od Outlooka, a Roundcube zostaw jako zapas
  na wyjazd i wolny internet.
</p>

<p class="ks-hint">
  W obu klientach logujesz się kontem Microsoft organizacji — <strong>Roundcube nie zna
  i nie przechowuje Twojego hasła</strong>, przekazuje logowanie do Microsoft.
</p>

<?php
auth_screen_foot([
    'links' => [
        ['url' => APP_URL . '/panel/',              'label' => 'Panel wolontariusza',  'icon' => 'bi-house-door'],
        ['url' => APP_URL . '/panel/helpdesk.php',  'label' => 'Problem z pocztą?',    'icon' => 'bi-life-preserver'],
    ],
    // Podpowiedź „ostatnio wybierałeś" — progresywne ulepszenie. Świadomie BEZ
    // automatycznego przekierowania: komputery bywają współdzielone, a wybór ma
    // pozostać odwracalny jednym kliknięciem.
    'extra_js' => '<script>(function(){'
        . 'var K="feerMailClient",N={outlook:"Outlook w przeglądarce",roundcube:"Roundcube"},b=document.getElementById("pw-last");'
        . 'try{var p=localStorage.getItem(K);if(p&&N[p]&&b){b.innerHTML="Ostatnio wybierałeś: <b></b> — możesz to zmienić w każdej chwili.";'
        . 'b.querySelector("b").textContent=N[p];b.style.display="block";}}catch(e){}'
        . 'document.querySelectorAll("[data-pick]").forEach(function(a){a.addEventListener("click",function(){'
        . 'try{localStorage.setItem(K,a.dataset.pick);}catch(e){}});});'
        . '})();</script>',
]);

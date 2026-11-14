<?php
/**
 * webmail/index.php — strona wyboru: jak otworzyć pocztę (poczta.feer.org.pl).
 *
 * PUBLICZNA, bez logowania: rozjazd do TEJ SAMEJ skrzynki. Nie ma tu danych
 * osobowych ani sesji użytkownika, więc nie ma czego bramkować.
 *
 * Od 14.11.2026 poczta działa w dPoczcie (moduł modules/dhosting_migrator), więc
 * strona poleca dPocztę w przeglądarce, a jako drugą drogę program w telefonie
 * lub na komputerze (IMAP). Stara skrzynka w Microsoft 365 (Outlook) pojawia
 * się osobno, bez polecania i poza zestawieniem, tylko do dnia wyłączenia
 * Microsoft 365 (`sso_ms_off_date`). W tekstach nie podajemy nazwy dostawcy
 * serwera, tylko „dPoczta".
 *
 * UKŁAD DWUPANELOWY: lewy panel — wybór (przyciski), prawy — zestawienie cech
 * i kalendarz/kontakty z chmury. Na wąskim ekranie panele składają się w jedną
 * kolumnę (wybór na górze), bo na telefonie najpierw się klika, a dopiero potem
 * porównuje.
 *
 * WYGLĄD: powłoka ekranów wejścia do systemu (includes/auth_screen.php — te same
 * co logowanie/rejestracja), w trybie 'plain', żeby zmieścić dwa panele obok siebie.
 *
 * KATALOG: includes/webmail_clients.php (jedno źródło prawdy razem z panelem
 * współpracownika i modułem Poczta). Adres dPoczty z ustawień migratora poczty
 * (`dhosting_webmail_url`, domyślnie https://dpoczta.pl).
 *
 * ROUTING (trzy adresy, jedna strona — patrz .htaccess i router Traefika
 * `feer-poczta` w docker/docker-compose.prod.yml):
 *   • poczta.feer.org.pl     → host `poczta.` przepisywany na /webmail/
 *   • webmail.feer.org.pl    → to samo
 *   • szo.feer.org.pl/poczta → alias w .htaccess (goły katalog; moduł Poczta
 *                              działa dalej pod /poczta/dashboard.php itd.)
 * Linki do stron SZO budujemy przez webmail_szo_url(): pod hostem poczta. APP_URL
 * wskazywałby na ten host, a on jest przepisywany na /webmail/.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webmail_clients.php';

$CLIENTS  = webmail_clients();
$LEGACY   = webmail_clients_legacy();
$FEATURES = webmail_features();

$_dp_label  = webmail_dpoczta_label();
$_switch    = webmail_switch_ts();
$_off       = webmail_ms_off_ts();
$_now       = time();
$_switched  = $_switch !== null && $_switch <= $_now;
$_u_newpass = webmail_szo_url('/nowehaslo');
$_u_help    = webmail_szo_url('/panel/helpdesk.php');
$_u_dav     = webmail_szo_url('/kalendarz-kontakty');
$_u_dav_art = webmail_szo_url('/uslugi-it/uslugi/chmura-plikow/kontakty-i-kalendarz');
$_u_2fa     = webmail_szo_url('/uslugi-it/uslugi/moja-poczta/weryfikacja-dwuetapowa');
$_u_mail    = webmail_szo_url('/uslugi-it/uslugi/moja-poczta');

require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'     => 'Poczta organizacji',
    'side_title'=> 'Poczta',
    'side_sub'  => 'Otwórz pocztę w przeglądarce albo dodaj ją do programu.',
    'tab'       => '',
    'mode'      => 'plain',
    'bootstrap' => true,
    'width'     => 1160,
    'main_id'   => 'poczta-main',
]);
?>

<style>
/* ── Układ dwupanelowy ──────────────────────────────────────────────────────
   Panele to te same białe karty co na logowaniu (.pw-panel ≈ .ks-card), tylko
   węższy padding — mają zmieścić tabelę, nie formularz. */
.pw-h1{color:#1d1d1d;text-align:left;font-size:clamp(1.4rem,3.2vw,1.9rem);font-weight:800;
  letter-spacing:-.02em;margin:0 0 .4rem}
.pw-lead{color:#4b5563;text-align:left;font-size:.95rem;line-height:1.55;
  margin:0 0 1.4rem;max-width:70ch}

.pw-grid{display:grid;gap:1.1rem;align-items:start}
@media(min-width:960px){.pw-grid{grid-template-columns:minmax(330px,29rem) 1fr}}

/* PUŁAPKA: w trybie 'plain' powłoka ekranów wejścia zakłada, że treść leży na
   tle marki, więc ustawia biały kolor tekstu. Nasze panele są białe — trzeba
   jawnie wrócić do ciemnego atramentu, inaczej teksty są niewidoczne. */
.pw-panel{background:#fff;border-radius:var(--ks-radius);box-shadow:0 18px 44px rgba(0,0,0,.16);
  padding:1.5rem 1.35rem;color:var(--ks-ink)}
.pw-panel h2,.pw-panel h3,.pw-panel p,.pw-panel li,.pw-panel table,.pw-panel th,.pw-panel td,
.pw-panel .pw-opt,.pw-panel .pw-opt__name{color:var(--ks-ink)}
@media(min-width:576px){.pw-panel{padding:1.75rem 1.6rem}}
.pw-panel__h{display:flex;align-items:center;gap:.5rem;font-size:1.05rem;font-weight:800;
  letter-spacing:-.01em;margin:0 0 .3rem}
.pw-panel__h i{color:var(--ks)}
.pw-panel__sub{font-size:.85rem;color:var(--ks-muted)!important;margin:0 0 1.15rem;line-height:1.5}
/* Bursztynowa ramka fokusu z powłoki ginie na białym tle (kontrast < 3:1) — na panelach granatowa. */
.pw-panel :focus-visible{outline-color:#1d4ed8!important}
.pw-panel p a,.pw-panel li a{color:#1d4ed8!important;text-decoration:underline;text-underline-offset:2px}

/* ── Lewy panel: opcje ──────────────────────────────────────────────────── */
.pw-opt{display:block;border:1px solid var(--ks-line);border-radius:12px;padding:.9rem 1rem;
  text-decoration:none;color:var(--ks-ink);margin-bottom:.7rem;transition:border-color .13s,box-shadow .13s,transform .13s}
.pw-opt:hover,.pw-opt:focus-visible{border-color:var(--ks);box-shadow:0 8px 22px -12px rgba(0,0,0,.35);
  transform:translateY(-1px);color:var(--ks-ink)}
.pw-opt--rec{border-color:var(--ks);box-shadow:0 0 0 1px var(--ks)}
.pw-opt--old{border-style:dashed;background:#fafafa}
.pw-opt__top{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.pw-opt__ico{width:34px;height:34px;border-radius:9px;background:var(--c-bg,rgba(220,38,38,.07));
  color:var(--ks);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.05rem}
.pw-opt--old .pw-opt__ico{background:#f3f4f6;color:#4b5563}
.pw-opt__name{font-weight:700;font-size:1rem;line-height:1.25}
.pw-opt__tag{display:block;font-size:.83rem;color:var(--ks-muted)!important;margin:.35rem 0 0;line-height:1.45}
.pw-opt__hint{display:block;font-size:.81rem;color:var(--ks-muted)!important;margin:.45rem 0 0;line-height:1.5}
.pw-opt__go{display:flex;align-items:center;gap:.35rem;margin-top:.6rem;font-size:.88rem;
  font-weight:700;color:var(--ks)!important}
.pw-opt--old .pw-opt__go{color:#4b5563!important}
.pw-badge{font-size:.7rem;font-weight:700;padding:.12rem .5rem;border-radius:999px;text-transform:uppercase;
  letter-spacing:.03em}
.pw-badge--rec{background:#ecfdf5;color:#047857}
.pw-badge--old{background:#fff7ed;color:#9a3412}
.pw-legacy{margin-top:1.2rem;padding-top:1rem;border-top:1px solid #e5e7eb}
.pw-legacy__h{font-size:.92rem;font-weight:800;margin:0 0 .3rem}
.pw-legacy__p{font-size:.82rem;color:var(--ks-muted)!important;margin:0 0 .8rem;line-height:1.5}
.pw-last{font-size:.82rem;color:var(--ks-muted)!important;margin:1rem 0 0;display:none}
.pw-last b{color:var(--ks-ink)}

/* ── Prawy panel: zestawienie ───────────────────────────────────────────── */
.pw-tbl-wrap{overflow-x:auto;border:1px solid #e5e7eb;border-radius:12px}
.pw-tbl{border-collapse:collapse;width:100%;font-size:.86rem}
.pw-tbl th,.pw-tbl td{padding:.55rem .7rem;border-top:1px solid #e5e7eb;text-align:left;vertical-align:top}
.pw-tbl thead th{position:sticky;top:0;background:var(--c-bg,rgba(220,38,38,.07));border-top:0;
  font-size:.8rem;white-space:nowrap;text-align:center;color:var(--ks-ink)!important;font-weight:700}
.pw-tbl thead th:first-child{text-align:left}
.pw-tbl tbody th{font-weight:600;min-width:14rem}
.pw-tbl tbody th small{display:block;font-weight:400;color:var(--ks-muted)!important;font-size:.76rem;
  line-height:1.4;margin-top:.1rem}
.pw-tbl td{text-align:center;font-weight:700;white-space:nowrap}
.pw-tbl td small{display:block;font-weight:400;color:var(--ks-muted)!important;font-size:.74rem;line-height:1.35;
  white-space:normal;margin-top:.1rem;text-align:left}
.pw-yes{color:#15803d!important}
.pw-no{color:#6b7280!important}
.pw-part{color:#b45309!important}
.pw-legend{font-size:.78rem;color:var(--ks-muted)!important;margin:.7rem 0 0;display:flex;gap:1rem;flex-wrap:wrap}
.pw-sect{margin:1.35rem 0 0}
.pw-sect h3{font-size:.95rem;font-weight:800;margin:0 0 .35rem}
.pw-sect p{font-size:.86rem;line-height:1.6;margin:0 0 .5rem}
.pw-sect p:last-child{margin-bottom:0}

.pw-alert{display:flex;gap:.7rem;align-items:flex-start;background:#eff6ff;border:1px solid #bfdbfe;
  border-left:4px solid #1d4ed8;color:#1e40af;border-radius:12px;padding:.85rem 1rem;font-size:.88rem;
  line-height:1.55;margin:0 0 1.1rem}
.pw-alert i{font-size:1.1rem;flex-shrink:0;margin-top:.1rem}
.pw-alert strong{color:#1e3a8a}
.pw-alert a{color:#1e3a8a!important;text-decoration:underline;text-underline-offset:2px}
.pw-alert :focus-visible{outline-color:#1e3a8a!important}
.pw-alert__row{margin:0}
.pw-alert__row + .pw-alert__row{margin-top:.6rem;padding-top:.6rem;border-top:1px solid #bfdbfe}

/* ── Logowanie i bezpieczeństwo — pasek pełnej szerokości pod panelami ───── */
.pw-why{margin-top:1.1rem}
.pw-why__grid{display:grid;gap:.9rem 1.4rem;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin:0}
.pw-why__item{display:flex;gap:.65rem;align-items:flex-start}
.pw-why__ico{flex-shrink:0;width:30px;height:30px;border-radius:8px;background:var(--c-bg,rgba(220,38,38,.07));
  color:var(--ks);display:flex;align-items:center;justify-content:center;font-size:.95rem}
.pw-why__t{font-weight:700;font-size:.9rem;margin:0 0 .1rem}
.pw-why__d{font-size:.83rem;line-height:1.5;color:var(--ks-muted)!important;margin:0}
</style>

<h1 class="pw-h1">Poczta organizacji</h1>
<p class="pw-lead">
  Swoją skrzynkę @feer.org.pl otworzysz w przeglądarce albo w programie pocztowym w telefonie
  lub na komputerze. Wszędzie zobaczysz te same wiadomości.
</p>

<div class="pw-alert" role="note">
  <i class="bi bi-info-circle" aria-hidden="true"></i>
  <div>
    <p class="pw-alert__row">
      <?php if ($_switch !== null && $_switched): ?>
      <strong>Od <?= h(webmail_date_pl($_switch)) ?> poczta Fundacji działa w dPoczcie.</strong>
      Adres e-mail się nie zmienił.
      <?php elseif ($_switch !== null): ?>
      <strong><?= h(webmail_date_pl($_switch)) ?> poczta Fundacji przechodzi do dPoczty.</strong>
      Adres e-mail się nie zmieni.
      <?php else: ?>
      <strong>Poczta Fundacji działa w dPoczcie.</strong>
      <?php endif; ?>
      Logujesz się pełnym adresem e-mail i hasłem do poczty. To hasło jest inne niż hasło logowania
      jednokrotnego do SZO i chmury plików. Zmienisz je w SZO, na stronie
      <a href="<?= h($_u_newpass) ?>">Nowe hasło</a>.
    </p>
    <?php if ($LEGACY): ?>
    <p class="pw-alert__row">
      <strong><?= h(webmail_date_pl($_off)) ?> wyłączamy Microsoft 365</strong>, a razem z nim starą
      skrzynkę w Outlooku.<?php if ($_switched): ?> Nowe wiadomości przychodzą już tylko do dPoczty.<?php endif; ?>
    </p>
    <?php endif; ?>
  </div>
</div>

<div class="pw-grid">

  <!-- ── PANEL 1: wybór ──────────────────────────────────────────────────── -->
  <section class="pw-panel" aria-labelledby="pw-pick-h">
    <h2 class="pw-panel__h" id="pw-pick-h"><i class="bi bi-hand-index-thumb" aria-hidden="true"></i>Wybierz i wejdź</h2>
    <p class="pw-panel__sub">
      Możesz korzystać z obu sposobów naraz.
    </p>

    <?php foreach ($CLIENTS as $c): $rec = $c['badge'] === 'zalecany'; ?>
    <a href="<?= h($c['url']) ?>" rel="noopener" data-pick="<?= h($c['key']) ?>"
       class="pw-opt<?= $rec ? ' pw-opt--rec' : '' ?>">
      <span class="pw-opt__top">
        <span class="pw-opt__ico" aria-hidden="true"><i class="bi <?= h($c['icon']) ?>"></i></span>
        <span class="pw-opt__name"><?= h($c['label']) ?></span>
        <?php if ($rec): ?><span class="pw-badge pw-badge--rec">zalecany</span><?php endif; ?>
      </span>
      <span class="pw-opt__tag"><?= h($c['tagline']) ?></span>
      <span class="pw-opt__hint"><?= h($c['hint']) ?></span>
      <span class="pw-opt__go"><?= h($c['go']) ?>
        <i class="bi bi-arrow-right" aria-hidden="true"></i>
      </span>
    </a>
    <?php endforeach; ?>

    <?php if ($LEGACY): ?>
    <div class="pw-legacy">
      <h3 class="pw-legacy__h" id="pw-old-h">Stara skrzynka w Microsoft 365</h3>
      <p class="pw-legacy__p">
        Nie odpisuj z niej na nowe wiadomości. Twoja poczta jest już w dPoczcie.
      </p>
      <?php foreach ($LEGACY as $c): ?>
      <a href="<?= h($c['url']) ?>" rel="noopener" data-pick="<?= h($c['key']) ?>" class="pw-opt pw-opt--old">
        <span class="pw-opt__top">
          <span class="pw-opt__ico" aria-hidden="true"><i class="bi <?= h($c['icon']) ?>"></i></span>
          <span class="pw-opt__name"><?= h($c['label']) ?></span>
          <span class="pw-badge pw-badge--old">wyłączany <?= h(date('d.m.Y', (int)$c['until'])) ?></span>
        </span>
        <span class="pw-opt__tag"><?= h($c['tagline']) ?></span>
        <span class="pw-opt__hint"><?= h($c['hint']) ?></span>
        <span class="pw-opt__go"><?= h($c['go']) ?>
          <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <p class="pw-last" id="pw-last" role="status"></p>
  </section>

  <!-- ── PANEL 2: zestawienie ────────────────────────────────────────────── -->
  <section class="pw-panel" aria-labelledby="pw-cmp-h">
    <h2 class="pw-panel__h" id="pw-cmp-h"><i class="bi bi-table" aria-hidden="true"></i>Zestawienie</h2>
    <p class="pw-panel__sub">Co potrafi dPoczta, a co program pocztowy w telefonie lub na komputerze.</p>

    <div class="pw-tbl-wrap">
      <table class="pw-tbl">
        <caption class="sr">Porównanie sposobów otwierania poczty</caption>
        <thead>
          <tr>
            <th scope="col">Co potrafi</th>
            <?php foreach ($CLIENTS as $c): ?>
            <th scope="col"><?= h($c['short']) ?></th>
            <?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($FEATURES as $fkey => $f): ?>
          <tr>
            <th scope="row"><?= h($f['label']) ?><?php if ($f['note']): ?><small><?= h($f['note']) ?></small><?php endif; ?></th>
            <?php foreach ($CLIENTS as $c):
                [$sym, $cls, $txt] = webmail_mark($c['features'][$fkey] ?? 'no');
                $note = $c['notes'][$fkey] ?? '';
            ?>
            <td>
              <span class="pw-<?= $cls ?>" aria-hidden="true"><?= $sym ?></span>
              <span class="sr"><?= h($c['short']) ?>: <?= h($txt) ?><?= $note ? ', ' . h($note) : '' ?></span>
              <?php if ($note): ?><small aria-hidden="true"><?= h($note) ?></small><?php endif; ?>
            </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <p class="pw-legend" aria-hidden="true">
      <span><span class="pw-yes">✓</span> tak</span>
      <span><span class="pw-part">≈</span> częściowo</span>
      <span><span class="pw-no">—</span> nie</span>
    </p>

    <div class="pw-sect">
      <h3>Kalendarz i kontakty z chmury plików</h3>
      <p>
        Kalendarz w dPoczcie jest osobny od kalendarza w chmurze plików Fundacji. Wydarzenia nie
        przenoszą się same między nimi. Kalendarz z chmury możesz dodać do dPoczty przez
        „Dodaj kalendarz CalDAV”. Kontaktów z chmury dPoczta nie pobiera, więc podłączysz je
        w telefonie albo w programie na komputerze.
      </p>
      <p>
        Dane do wpisania przygotuje strona <a href="<?= h($_u_dav) ?>">Kalendarz i kontakty z chmury</a>
        w SZO. Krok po kroku opisuje to artykuł
        <a href="<?= h($_u_dav_art) ?>">Kalendarz i kontakty z chmury w poczcie, telefonie i programie</a>.
      </p>
    </div>

    <div class="pw-sect">
      <h3>Nie wiesz, co wybrać?</h3>
      <p>
        Zacznij od dPoczty. Działa od razu w każdej przeglądarce, także w telefonie.
      </p>
      <p>
        Jeśli wolisz pocztę w aplikacji w telefonie albo w Thunderbirdzie czy Outlooku na komputerze,
        dodaj skrzynkę jako konto IMAP według instrukcji. Program Outlook połączony z kontem Microsoft
        nie będzie działał z pocztą Fundacji. Pozostałe instrukcje znajdziesz w Portalu Usług IT,
        w części <a href="<?= h($_u_mail) ?>">Moja poczta</a>.
      </p>
    </div>
  </section>

</div>

<!-- ── LOGOWANIE I BEZPIECZEŃSTWO ────────────────────────────────────────── -->
<section class="pw-panel pw-why" aria-labelledby="pw-why-h">
  <h2 class="pw-panel__h" id="pw-why-h"><i class="bi bi-shield-check" aria-hidden="true"></i>Logowanie i bezpieczeństwo</h2>
  <p class="pw-panel__sub">Kilka zasad, które chronią Twoją skrzynkę.</p>

  <div class="pw-why__grid">

    <div class="pw-why__item">
      <span class="pw-why__ico" aria-hidden="true"><i class="bi bi-shield-exclamation"></i></span>
      <div>
        <h3 class="pw-why__t">Hasło wpisuj tylko w dPoczcie albo w swoim programie</h3>
        <p class="pw-why__d">Strona logowania poczty to <strong><?= h($_dp_label) ?></strong>. Jeśli link
          z wiadomości prowadzi gdzie indziej i prosi o hasło do poczty, to próba wyłudzenia. Nie wpisuj
          tam hasła i zgłoś to w <a href="<?= h($_u_help) ?>">Helpdesku IT</a>.</p>
      </div>
    </div>

    <div class="pw-why__item">
      <span class="pw-why__ico" aria-hidden="true"><i class="bi bi-key"></i></span>
      <div>
        <h3 class="pw-why__t">Hasło zmieniasz w SZO</h3>
        <p class="pw-why__d">Nie zmieniaj hasła w ustawieniach dPoczty. Zmień je w SZO, na stronie
          <a href="<?= h($_u_newpass) ?>">Nowe hasło</a>. Potem wpisz nowe hasło także w telefonie
          i w programach, w których masz pocztę.</p>
      </div>
    </div>

    <div class="pw-why__item">
      <span class="pw-why__ico" aria-hidden="true"><i class="bi bi-phone-vibrate"></i></span>
      <div>
        <h3 class="pw-why__t">Weryfikacja dwuetapowa</h3>
        <p class="pw-why__d">W dPoczcie włączysz dodatkowy kod z aplikacji w telefonie, podawany przy
          logowaniu. Chroni skrzynkę, nawet gdy ktoś pozna Twoje hasło. Zobacz instrukcję
          <a href="<?= h($_u_2fa) ?>">Weryfikacja dwuetapowa w poczcie</a>.</p>
      </div>
    </div>

    <div class="pw-why__item">
      <span class="pw-why__ico" aria-hidden="true"><i class="bi bi-bookmark-star"></i></span>
      <div>
        <h3 class="pw-why__t">Ten adres się nie zmienia</h3>
        <p class="pw-why__d"><strong><?= h(webmail_chooser_label()) ?></strong> to adres Fundacji, który
          zawsze prowadzi do aktualnej poczty. Zapisz go w zakładkach i podawaj nowym osobom.</p>
      </div>
    </div>

  </div>
</section>

<?php
auth_screen_foot([
    'links' => [
        ['url' => webmail_szo_url('/panel/'), 'label' => 'Panel współpracownika', 'icon' => 'bi-house-door'],
        ['url' => $_u_newpass,                'label' => 'Nowe hasło do poczty',  'icon' => 'bi-key'],
        ['url' => $_u_help,                   'label' => 'Problem z pocztą?',     'icon' => 'bi-life-preserver'],
    ],
    // Podpowiedź „ostatni wybór" — progresywne ulepszenie. Świadomie BEZ
    // automatycznego przekierowania: komputery bywają współdzielone, a wybór ma
    // pozostać odwracalny jednym kliknięciem.
    'extra_js' => '<script>(function(){'
        . 'var K="feerMailClient",b=document.getElementById("pw-last");'
        . 'var N={};document.querySelectorAll("[data-pick]").forEach(function(a){'
        . 'N[a.dataset.pick]=(a.querySelector(".pw-opt__name")||{}).textContent||a.dataset.pick;'
        . 'a.addEventListener("click",function(){try{localStorage.setItem(K,a.dataset.pick);}catch(e){}});});'
        . 'try{var p=localStorage.getItem(K);if(p&&N[p]&&b){'
        . 'b.innerHTML="Ostatni wybór na tym urządzeniu: <b></b>. Możesz go zmienić w każdej chwili.";'
        . 'b.querySelector("b").textContent=N[p];b.style.display="block";}}catch(e){}'
        . '})();</script>',
]);

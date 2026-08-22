<?php
/**
 * webmail/index.php — strona wyboru klienta poczty (poczta.feer.org.pl).
 *
 * PUBLICZNA, bez logowania: rozjazd do webmaili TEJ SAMEJ skrzynki. Nie ma tu
 * danych osobowych ani sesji użytkownika, więc nie ma czego bramkować.
 *
 * UKŁAD DWUPANELOWY: lewy panel — wybór klienta (przyciski), prawy — zestawienie
 * cech. Na wąskim ekranie panele składają się w jedną kolumnę (wybór na górze,
 * zestawienie pod nim), bo na telefonie najpierw się klika, a dopiero potem
 * porównuje.
 *
 * WYGLĄD: powłoka ekranów wejścia do systemu (includes/auth_screen.php — te same
 * co logowanie/rejestracja: tło marki, pasek dostępności, logo, białe karty),
 * w trybie 'plain', żeby zmieścić dwa panele obok siebie. Dla użytkownika to
 * jeden ciąg „ekranów wejścia", a nie osobna, obco wyglądająca strona.
 *
 * KLIENCI: z katalogu includes/webmail_clients.php (jedno źródło prawdy razem
 * z panelem wolontariusza i modułem Poczta). Klient bez ustawionego adresu
 * (Admin → Poczta → Webmail) NIE pojawia się tutaj — strona nigdy nie reklamuje
 * usługi, której nie wdrożono.
 *
 * ROUTING (trzy adresy, jedna strona — patrz .htaccess i router Traefika
 * `feer-poczta` w docker/docker-compose.prod.yml):
 *   • poczta.feer.org.pl     → host `poczta.` przepisywany na /webmail/
 *   • webmail.feer.org.pl    → to samo; dwie nazwy, bo jedni szukają „poczty",
 *                              a drudzy „webmaila" (oba wymagają rekordu A)
 *   • szo.feer.org.pl/poczta → alias w .htaccess (goły katalog; moduł Poczta
 *                              działa dalej pod /poczta/dashboard.php itd.)
 *
 * Kontekst treści: od 1 września 2026 współpracownicy wybierają klienta sami
 * (wcześniej de facto tylko Outlook).
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webmail_clients.php';

$CLIENTS  = webmail_clients();
$FEATURES = webmail_features();

require_once dirname(__DIR__) . '/includes/auth_screen.php';
auth_screen_head([
    'title'     => 'Poczta — wybierz klienta',
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
.pw-h1{color:#fff;text-align:center;font-size:clamp(1.4rem,3.2vw,1.9rem);font-weight:800;
  letter-spacing:-.02em;margin:0 0 .4rem}
.pw-lead{color:rgba(255,255,255,.88);text-align:center;font-size:.95rem;line-height:1.55;
  margin:0 auto 1.4rem;max-width:70ch}

.pw-grid{display:grid;gap:1.1rem;align-items:start}
@media(min-width:960px){.pw-grid{grid-template-columns:minmax(330px,29rem) 1fr}}

/* PUŁAPKA: w trybie 'plain' powłoka ekranów wejścia zakłada, że treść leży na
   tle marki, więc ustawia biały kolor tekstu. Nasze panele są białe — trzeba
   jawnie wrócić do ciemnego atramentu, inaczej teksty są niewidoczne. */
.pw-panel{background:#fff;border-radius:var(--ks-radius);box-shadow:0 18px 44px rgba(0,0,0,.16);
  padding:1.5rem 1.35rem;color:var(--ks-ink)}
.pw-panel h2,.pw-panel p,.pw-panel li,.pw-panel table,.pw-panel th,.pw-panel td,
.pw-panel .pw-opt,.pw-panel .pw-opt__name{color:var(--ks-ink)}
@media(min-width:576px){.pw-panel{padding:1.75rem 1.6rem}}
.pw-panel__h{display:flex;align-items:center;gap:.5rem;font-size:1.05rem;font-weight:800;
  letter-spacing:-.01em;margin:0 0 .3rem}
.pw-panel__h i{color:var(--ks)}
.pw-panel__sub{font-size:.85rem;color:var(--ks-muted)!important;margin:0 0 1.15rem;line-height:1.5}

/* ── Lewy panel: opcje ──────────────────────────────────────────────────── */
.pw-opt{display:block;border:1px solid var(--ks-line);border-radius:12px;padding:.9rem 1rem;
  text-decoration:none;color:var(--ks-ink);margin-bottom:.7rem;transition:border-color .13s,box-shadow .13s,transform .13s}
.pw-opt:hover,.pw-opt:focus-visible{border-color:var(--ks);box-shadow:0 8px 22px -12px rgba(0,0,0,.35);
  transform:translateY(-1px);color:var(--ks-ink)}
.pw-opt--rec{border-color:var(--ks);box-shadow:0 0 0 1px var(--ks)}
.pw-opt--old{opacity:.85}
.pw-opt__top{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.pw-opt__ico{width:34px;height:34px;border-radius:9px;background:var(--c-bg,rgba(220,38,38,.07));
  color:var(--ks);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.05rem}
.pw-opt__name{font-weight:700;font-size:1rem;line-height:1.25}
.pw-opt__tag{display:block;font-size:.83rem;color:var(--ks-muted)!important;margin:.35rem 0 0;line-height:1.45}
.pw-opt__hint{display:block;font-size:.81rem;color:var(--ks-muted)!important;margin:.45rem 0 0;line-height:1.5}
.pw-opt__go{display:flex;align-items:center;gap:.35rem;margin-top:.6rem;font-size:.88rem;
  font-weight:700;color:var(--ks)!important}
.pw-badge{font-size:.7rem;font-weight:700;padding:.12rem .5rem;border-radius:999px;text-transform:uppercase;
  letter-spacing:.03em}
.pw-badge--rec{background:#ecfdf5;color:#047857}
.pw-badge--old{background:#fff7ed;color:#c2410c}
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
.pw-no{color:#9ca3af!important}
.pw-part{color:#b45309!important}
.pw-legend{font-size:.78rem;color:var(--ks-muted)!important;margin:.7rem 0 0;display:flex;gap:1rem;flex-wrap:wrap}
.pw-advice{margin:1.25rem 0 0;font-size:.86rem;line-height:1.6;color:var(--ks-muted)!important}
.pw-advice strong{color:var(--ks-ink)}
.pw-advice li{margin:.3rem 0}
.pw-advice ul{margin:.4rem 0 0;padding-left:1.15rem}

.pw-alert{display:flex;gap:.7rem;align-items:flex-start;background:#eff6ff;border:1px solid #bfdbfe;
  border-left:4px solid #1d4ed8;color:#1e40af;border-radius:12px;padding:.85rem 1rem;font-size:.88rem;
  line-height:1.55;margin:0 0 1.1rem}
.pw-alert i{font-size:1.1rem;flex-shrink:0}
.pw-alert strong{color:#1e3a8a}
</style>

<h1 class="pw-h1">Poczta organizacji — wybierz klienta</h1>
<p class="pw-lead">
  Ta sama skrzynka i te same wiadomości. Różni się widok i to, co program dodatkowo potrafi.
</p>

<div class="pw-alert">
  <i class="bi bi-calendar-check" aria-hidden="true"></i>
  <span>
    <strong>Od 1 września 2026 wybór należy do Ciebie</strong> — a korzystać możesz
    <strong>już teraz</strong>, bez zgłaszania czegokolwiek. Wybór nie jest na zawsze:
    w każdej chwili wchodzisz z innego klienta na tę samą skrzynkę, możesz też używać
    kilku równolegle. Żadna wiadomość przy tym nie ginie.
  </span>
</div>

<div class="pw-grid">

  <!-- ── PANEL 1: wybór ──────────────────────────────────────────────────── -->
  <section class="pw-panel" aria-labelledby="pw-pick-h">
    <h2 class="pw-panel__h" id="pw-pick-h"><i class="bi bi-hand-index-thumb" aria-hidden="true"></i>Wybierz i wejdź</h2>
    <p class="pw-panel__sub">
      <?= count($CLIENTS) ?> <?= count($CLIENTS) === 1 ? 'klient' : (count($CLIENTS) < 5 ? 'klienty' : 'klientów') ?>
      do wyboru. Logujesz się swoim kontem — żaden z nich nie przechowuje Twojego hasła.
    </p>

    <?php foreach ($CLIENTS as $c):
        $rec = $c['badge'] === 'zalecany';
        $old = $c['badge'] === 'awaryjny';
    ?>
    <a href="<?= h($c['url']) ?>" rel="noopener" data-pick="<?= h($c['key']) ?>"
       class="pw-opt<?= $rec ? ' pw-opt--rec' : '' ?><?= $old ? ' pw-opt--old' : '' ?>">
      <span class="pw-opt__top">
        <span class="pw-opt__ico" aria-hidden="true"><i class="bi <?= h($c['icon']) ?>"></i></span>
        <span class="pw-opt__name"><?= h($c['label']) ?></span>
        <?php if ($rec): ?><span class="pw-badge pw-badge--rec">zalecany</span><?php endif; ?>
        <?php if ($old): ?><span class="pw-badge pw-badge--old">awaryjny</span><?php endif; ?>
      </span>
      <span class="pw-opt__tag"><?= h($c['tagline']) ?></span>
      <span class="pw-opt__hint"><?= h($c['hint']) ?></span>
      <span class="pw-opt__go">Otwórz <?= h($c['short']) ?>
        <i class="bi bi-arrow-right" aria-hidden="true"></i>
      </span>
    </a>
    <?php endforeach; ?>

    <p class="pw-last" id="pw-last" role="status"></p>
  </section>

  <!-- ── PANEL 2: zestawienie ────────────────────────────────────────────── -->
  <section class="pw-panel" aria-labelledby="pw-cmp-h">
    <h2 class="pw-panel__h" id="pw-cmp-h"><i class="bi bi-table" aria-hidden="true"></i>Zestawienie</h2>
    <p class="pw-panel__sub">Co który klient potrafi ze skrzynką Microsoft 365 Fundacji.</p>

    <div class="pw-tbl-wrap">
      <table class="pw-tbl">
        <caption class="sr">Porównanie klientów poczty</caption>
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
              <span class="sr"><?= h($c['short']) ?>: <?= h($txt) ?><?= $note ? ' — ' . h($note) : '' ?></span>
              <?php if ($note): ?><small><?= h($note) ?></small><?php endif; ?>
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

    <div class="pw-advice">
      <strong>Nie wiesz, co wybrać?</strong>
      <ul>
        <li>Umawiasz spotkania, prowadzisz kalendarz albo obsługujesz skrzynkę wspólną
            → <strong>Outlook</strong>; pozostali tego nie mają.</li>
        <li>Chcesz tylko przeczytać i odpisać, często z telefonu lub słabego łącza
            → <strong>Roundcube</strong> albo <strong>SnappyMail</strong>.</li>
        <li>Nie masz preferencji → zacznij od <strong>Outlooka</strong>, lekkiego klienta zostaw
            jako zapas na wyjazd i wolny internet.</li>
        <li>Reguły, podpis i autoodpowiedź ustawione w Outlooku działają na serwerze,
            więc obowiązują <strong>we wszystkich klientach</strong>.</li>
      </ul>
    </div>
  </section>

</div>

<?php
auth_screen_foot([
    'links' => [
        ['url' => APP_URL . '/panel/',             'label' => 'Panel wolontariusza', 'icon' => 'bi-house-door'],
        ['url' => APP_URL . '/panel/helpdesk.php', 'label' => 'Problem z pocztą?',   'icon' => 'bi-life-preserver'],
    ],
    // Podpowiedź „ostatnio wybierałeś" — progresywne ulepszenie. Świadomie BEZ
    // automatycznego przekierowania: komputery bywają współdzielone, a wybór ma
    // pozostać odwracalny jednym kliknięciem.
    'extra_js' => '<script>(function(){'
        . 'var K="feerMailClient",b=document.getElementById("pw-last");'
        . 'var N={};document.querySelectorAll("[data-pick]").forEach(function(a){'
        . 'N[a.dataset.pick]=(a.querySelector(".pw-opt__name")||{}).textContent||a.dataset.pick;'
        . 'a.addEventListener("click",function(){try{localStorage.setItem(K,a.dataset.pick);}catch(e){}});});'
        . 'try{var p=localStorage.getItem(K);if(p&&N[p]&&b){'
        . 'b.innerHTML="Ostatnio wybierałeś: <b></b> — możesz to zmienić w każdej chwili.";'
        . 'b.querySelector("b").textContent=N[p];b.style.display="block";}}catch(e){}'
        . '})();</script>',
]);

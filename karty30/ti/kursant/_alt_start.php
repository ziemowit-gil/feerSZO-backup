<?php
/**
 * karty30/ti/kursant/_alt_start.php — ekran startowy widoku alternatywnego: cały panel jako kafle.
 * Na górze 4 najważniejsze moduły, niżej wszystkie sekcje jako grupy kafli.
 * Wyszukiwarka przeszukuje wszystkie pozycje menu. Zmienne pochodzą z _alt_bar.php.
 */
$_st_glowne_klucze = ['lekcje', 'zadania', 'komunikaty', 'oceny'];

$_st_wszystkie = [];
$_st_glowne    = [];
$_st_sekcje    = [];
foreach ($_alt_sections as $_ss) {
    $_pozycje = [];
    foreach ($_ss['items'] as $_si) {
        if ($_si['href'] === '') continue;
        $_p = [
            'klucz'    => (string)$_si['tab'],
            'sekcja'   => (string)$_ss['label'],
            'etykieta' => (string)$_si['label'],
            'href'     => (string)$_si['href'],
            'n'        => (int)$_si['n'],
            'v'        => (string)$_si['v'],
        ];
        $_st_wszystkie[] = $_p;
        $_pozycje[] = $_p;
    }
    if ($_pozycje) $_st_sekcje[] = ['label' => (string)$_ss['label'], 'pozycje' => $_pozycje];
}
foreach ($_st_glowne_klucze as $_gk) {
    foreach ($_st_wszystkie as $_p) { if ($_p['klucz'] === $_gk) { $_st_glowne[] = $_p; break; } }
}
?>
<section class="kp-start container-xl px-3 py-4" aria-labelledby="kp-start-h">
  <h1 class="h4 fw-bold mb-3" id="kp-start-h">Panel kursanta</h1>

  <div class="mb-4">
    <label for="kp-szukaj" class="form-label fw-semibold">Szukaj w panelu</label>
    <input type="search" id="kp-szukaj" class="form-control form-control-lg" placeholder="np. oceny, lekcje, zapisy"
           autocomplete="off" aria-describedby="kp-szukaj-wynik">
    <div class="small text-body-secondary mt-1" id="kp-szukaj-wynik" aria-live="polite"></div>
  </div>

  <!-- Widok domyślny: najważniejsze i wszystkie sekcje -->
  <div id="kp-widok">
    <h2 class="h6 fw-bold text-uppercase mb-2">Najważniejsze</h2>
    <ul class="kp-kafle kp-kafle-glowne mb-4">
      <?php foreach ($_st_glowne as $_k): ?>
      <li>
        <a class="kp-kafel kp-kafel-glowny" href="<?= h($_k['href']) ?>">
          <span class="kp-kafel-sekcja"><?= h($_k['sekcja']) ?></span>
          <span class="kp-kafel-tytul"><?= h($_k['etykieta']) ?></span>
          <?php if ($_k['n'] > 0): ?><span class="badge text-bg-<?= h($_k['v']) ?>"><?= (int)$_k['n'] ?></span><?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>

    <?php foreach ($_st_sekcje as $_sec): ?>
    <section class="kp-sekcja mb-4" aria-labelledby="kp-sek-<?= h(mb_strtolower(preg_replace('/\s+/', '-', $_sec['label']))) ?>">
      <h2 class="h6 fw-bold text-uppercase mb-2" id="kp-sek-<?= h(mb_strtolower(preg_replace('/\s+/', '-', $_sec['label']))) ?>"><?= h($_sec['label']) ?></h2>
      <ul class="kp-kafle">
        <?php foreach ($_sec['pozycje'] as $_k): ?>
        <li>
          <a class="kp-kafel" href="<?= h($_k['href']) ?>">
            <span class="kp-kafel-tytul"><?= h($_k['etykieta']) ?></span>
            <?php if ($_k['n'] > 0): ?><span class="badge text-bg-<?= h($_k['v']) ?>"><?= (int)$_k['n'] ?></span><?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endforeach; ?>
  </div>

  <!-- Wyniki wyszukiwania: wszystkie pozycje z sekcją (ukryte, dopóki ktoś czegoś nie szuka) -->
  <ul class="kp-kafle d-none" id="kp-wyniki">
    <?php foreach ($_st_wszystkie as $_k): ?>
    <li class="kp-kafel-poz" data-tekst="<?= h(mb_strtolower($_k['sekcja'] . ' ' . $_k['etykieta'])) ?>">
      <a class="kp-kafel" href="<?= h($_k['href']) ?>">
        <span class="kp-kafel-sekcja"><?= h($_k['sekcja']) ?></span>
        <span class="kp-kafel-tytul"><?= h($_k['etykieta']) ?></span>
        <?php if ($_k['n'] > 0): ?><span class="badge text-bg-<?= h($_k['v']) ?>"><?= (int)$_k['n'] ?></span><?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <p class="d-none mt-3" id="kp-brak">Nic nie pasuje do wyszukiwania. Spróbuj innego słowa.</p>
</section>
<script>
(function () {
  var inp = document.getElementById('kp-szukaj');
  if (!inp) return;
  var widok = document.getElementById('kp-widok');
  var wyniki = document.getElementById('kp-wyniki');
  var pozycje = wyniki.querySelectorAll('.kp-kafel-poz');
  var info = document.getElementById('kp-szukaj-wynik');
  var brak = document.getElementById('kp-brak');
  inp.addEventListener('input', function () {
    var q = inp.value.trim().toLowerCase();
    if (q === '') {
      widok.classList.remove('d-none');
      wyniki.classList.add('d-none');
      brak.classList.add('d-none');
      info.textContent = '';
      return;
    }
    widok.classList.add('d-none');
    wyniki.classList.remove('d-none');
    var n = 0;
    pozycje.forEach(function (li) {
      var pasuje = li.getAttribute('data-tekst').indexOf(q) !== -1;
      li.classList.toggle('d-none', !pasuje);
      if (pasuje) n++;
    });
    info.textContent = 'Znaleziono: ' + n;
    brak.classList.toggle('d-none', n > 0);
  });
})();
</script>

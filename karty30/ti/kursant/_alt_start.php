<?php
/**
 * karty30/ti/kursant/_alt_start.php — ekran startowy widoku alternatywnego: kafle wszystkich sekcji
 * i wyszukiwarka. Zmienne (np. $_alt_sections) pochodzą z _alt_bar.php.
 */
$_st_kafle = [];
foreach ($_alt_sections as $_ss) {
    foreach ($_ss['items'] as $_si) {
        if ($_si['href'] === '') continue;
        $_st_kafle[] = [
            'sekcja'   => (string)$_ss['label'],
            'etykieta' => (string)$_si['label'],
            'href'     => (string)$_si['href'],
            'n'        => (int)$_si['n'],
            'v'        => (string)$_si['v'],
        ];
    }
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

  <ul class="kp-kafle" id="kp-kafle">
    <?php foreach ($_st_kafle as $_k): ?>
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
  var pozycje = document.querySelectorAll('#kp-kafle .kp-kafel-poz');
  var wynik = document.getElementById('kp-szukaj-wynik');
  var brak = document.getElementById('kp-brak');
  inp.addEventListener('input', function () {
    var q = inp.value.trim().toLowerCase();
    var n = 0;
    pozycje.forEach(function (li) {
      var pasuje = q === '' || li.getAttribute('data-tekst').indexOf(q) !== -1;
      li.classList.toggle('d-none', !pasuje);
      if (pasuje) n++;
    });
    wynik.textContent = q === '' ? '' : 'Znaleziono: ' + n;
    brak.classList.toggle('d-none', q === '' || n > 0);
  });
})();
</script>

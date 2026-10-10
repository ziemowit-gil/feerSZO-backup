<?php
/**
 * karty30/ti/kursant/_alt_start.php — ekran startowy widoku alternatywnego w stylu „Start”:
 * ciemna siatka kafli pogrupowanych (Na dziś → Najważniejsze → sekcje menu), wyszukiwarka, wybór typu menu.
 * Pozycje z „Najważniejsze” nie powtarzają się w sekcjach. Zmienne ($_alt_sections, $_ikony, $KUR_NAV)
 * pochodzą z _alt_bar.php; $homeworks_student i $today_lessons z index.php.
 */
require_once dirname(__DIR__, 3) . '/includes/auth.php'; // csrf_token() dla formularza typu menu

$_st_glowne_klucze = ['lekcje', 'zadania', 'komunikaty', 'oceny'];
// Kolory kafli (paleta Start): ciemny tekst #1d1d1d na każdym z nich ma kontrast ≥ 4,5:1
$_w8_kolory = ['#1ba1e2', '#a4c400', '#f0a30a', '#00aba9', '#fcd116', '#e671b8', '#60a917'];

/** Ikona kafla: po kluczu zakładki, a gdy go brak (linki zewnętrzne) — po etykiecie. */
$_st_ikona = function (array $p) use ($_ikony): string {
    if ($p['klucz'] !== '' && isset($_ikony[$p['klucz']])) return $_ikony[$p['klucz']];
    if (mb_stripos($p['etykieta'], 'Biblioteka') !== false) return 'book';
    if (mb_stripos($p['etykieta'], 'Menu panelu') !== false) return 'layout-text-sidebar';
    return 'circle';
};

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
        ];
        $_st_wszystkie[] = $_p;
        // Najważniejsze mają własną grupę — w sekcji nie powtarzamy
        if (!in_array($_p['klucz'], $_st_glowne_klucze, true)) $_pozycje[] = $_p;
    }
    if ($_pozycje) $_st_sekcje[] = ['label' => (string)$_ss['label'], 'pozycje' => $_pozycje];
}
foreach ($_st_glowne_klucze as $_gk) {
    foreach ($_st_wszystkie as $_p) { if ($_p['klucz'] === $_gk) { $_st_glowne[] = $_p; break; } }
}

// Na dziś: zajęcia, zadania nadchodzące i oddane
$_st_now = date('Y-m-d H:i:s');
$_st_nad = []; $_st_odd = [];
foreach (($homeworks_student ?? []) as $_h) {
    if (!empty($_h['sub_id'])) { $_st_odd[] = $_h; continue; }
    if (empty($_h['due_at']) || (string)$_h['due_at'] >= $_st_now) $_st_nad[] = $_h;
}
usort($_st_nad, fn($a, $b) => strcmp((string)($a['due_at'] ?? 'z'), (string)($b['due_at'] ?? 'z')));
usort($_st_odd, fn($a, $b) => strcmp((string)($b['sub_at'] ?? $b['due_at'] ?? ''), (string)($a['sub_at'] ?? $a['due_at'] ?? '')));
?>
<?php try { ?>
<section class="kp-start container-xl px-3 py-4" aria-labelledby="kp-start-h">
  <div class="d-flex flex-wrap align-items-end justify-content-between gap-3 mb-3">
    <h1 class="h4 fw-bold mb-0" id="kp-start-h">Panel kursanta</h1>
    <div class="kp-szukaj-box">
      <label for="kp-szukaj" class="visually-hidden">Szukaj w panelu</label>
      <input type="search" id="kp-szukaj" class="form-control" placeholder="Szukaj w panelu: oceny, lekcje, zapisy…"
             autocomplete="off" aria-describedby="kp-szukaj-wynik">
      <div class="small mt-1" id="kp-szukaj-wynik" aria-live="polite"></div>
    </div>
  </div>

  <div id="kp-widok" class="kp-w8">

    <!-- Na dziś -->
    <section class="kp-w8-grupa" aria-labelledby="kp-w8-g-dzis">
      <h2 class="kp-w8-naglowek" id="kp-w8-g-dzis">Na dziś</h2>
      <ul class="kp-w8-siatka">
        <?php if (!empty($today_lessons)): ?>
        <li class="kp-w8-kafel kp-w8-szeroki" style="--kolor:#fcd116">
          <a href="?tab=lekcje">
            <i class="bi bi-calendar-event kp-w8-ikona" aria-hidden="true"></i>
            <span class="kp-w8-tytul">Dziś masz zajęcia
              <?php foreach ($today_lessons as $_tl): ?>
              <span class="kp-w8-pod"><?= h($_tl['course_name']) ?><?= !empty($_tl['time_from']) ? ' · ' . h($_tl['time_from']) : '' ?></span>
              <?php endforeach; ?>
            </span>
          </a>
        </li>
        <?php endif; ?>
        <li class="kp-w8-kafel kp-w8-szeroki" style="--kolor:#f0a30a">
          <a href="?tab=zadania">
            <i class="bi bi-hourglass-split kp-w8-ikona" aria-hidden="true"></i>
            <span class="kp-w8-tytul">Zadania do oddania
              <?php if (!$_st_nad): ?><span class="kp-w8-pod">Brak — wszystko oddane</span><?php endif; ?>
              <?php foreach (array_slice($_st_nad, 0, 2) as $_h): ?>
              <span class="kp-w8-pod"><?= h($_h['title'] ?? 'Zadanie') ?><?= !empty($_h['due_at']) ? ' · do ' . h(substr((string)$_h['due_at'], 0, 10)) : '' ?></span>
              <?php endforeach; ?>
            </span>
            <?php if ($_st_nad): ?><span class="kp-w8-licznik"><?= count($_st_nad) ?></span><?php endif; ?>
          </a>
        </li>
        <li class="kp-w8-kafel kp-w8-szeroki" style="--kolor:#60a917">
          <a href="?tab=zadania">
            <i class="bi bi-check2-circle kp-w8-ikona" aria-hidden="true"></i>
            <span class="kp-w8-tytul">Zadania oddane
              <?php if (!$_st_odd): ?><span class="kp-w8-pod">Jeszcze nic nie oddano</span><?php endif; ?>
              <?php foreach (array_slice($_st_odd, 0, 2) as $_h): ?>
              <span class="kp-w8-pod"><?= h($_h['title'] ?? 'Zadanie') ?></span>
              <?php endforeach; ?>
            </span>
            <?php if ($_st_odd): ?><span class="kp-w8-licznik"><?= count($_st_odd) ?></span><?php endif; ?>
          </a>
        </li>
      </ul>
    </section>

    <!-- Najważniejsze -->
    <section class="kp-w8-grupa" aria-labelledby="kp-w8-g-glowne">
      <h2 class="kp-w8-naglowek" id="kp-w8-g-glowne">Najważniejsze</h2>
      <ul class="kp-w8-siatka">
        <?php foreach ($_st_glowne as $_i => $_k): ?>
        <li class="kp-w8-kafel kp-w8-szeroki" style="--kolor: <?= $_w8_kolory[$_i % count($_w8_kolory)] ?>">
          <a href="<?= h($_k['href']) ?>">
            <i class="bi bi-<?= h($_st_ikona($_k)) ?> kp-w8-ikona" aria-hidden="true"></i>
            <span class="kp-w8-tytul"><?= h($_k['etykieta']) ?></span>
            <?php if ($_k['n'] > 0): ?><span class="kp-w8-licznik"><?= (int)$_k['n'] ?></span><?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <!-- Sekcje menu -->
    <?php foreach ($_st_sekcje as $_si => $_sec): $_sid = 'kp-w8-g-' . $_si; $_kol = $_w8_kolory[($_si + 4) % count($_w8_kolory)]; ?>
    <section class="kp-w8-grupa" aria-labelledby="<?= $_sid ?>">
      <h2 class="kp-w8-naglowek" id="<?= $_sid ?>"><?= h($_sec['label']) ?></h2>
      <ul class="kp-w8-siatka">
        <?php foreach ($_sec['pozycje'] as $_k): ?>
        <li class="kp-w8-kafel" style="--kolor: <?= $_kol ?>">
          <a href="<?= h($_k['href']) ?>">
            <i class="bi bi-<?= h($_st_ikona($_k)) ?> kp-w8-ikona" aria-hidden="true"></i>
            <span class="kp-w8-tytul"><?= h($_k['etykieta']) ?></span>
            <?php if ($_k['n'] > 0): ?><span class="kp-w8-licznik"><?= (int)$_k['n'] ?></span><?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endforeach; ?>
  </div>

  <!-- Wyniki wyszukiwania (ukryte, dopóki ktoś czegoś nie szuka) — ta sama siatka kafli -->
  <div class="kp-w8 d-none" id="kp-wyniki">
    <ul class="kp-w8-siatka">
      <?php foreach ($_st_wszystkie as $_k): ?>
      <li class="kp-w8-kafel kp-kafel-poz" style="--kolor:#1ba1e2" data-tekst="<?= h(mb_strtolower($_k['sekcja'] . ' ' . $_k['etykieta'])) ?>">
        <a href="<?= h($_k['href']) ?>">
          <i class="bi bi-<?= h($_st_ikona($_k)) ?> kp-w8-ikona" aria-hidden="true"></i>
          <span class="kp-w8-tytul"><span class="kp-w8-pod"><?= h($_k['sekcja']) ?></span><?= h($_k['etykieta']) ?></span>
          <?php if ($_k['n'] > 0): ?><span class="kp-w8-licznik"><?= (int)$_k['n'] ?></span><?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="d-none text-white mt-3 mb-0" id="kp-brak">Nic nie pasuje do wyszukiwania. Spróbuj innego słowa.</p>
  </div>

  <!-- Typ menu: na dole, bez wychodzenia z panelu -->
  <form method="post" action="<?= h(APP_URL) ?>/tozsamosc/kursant_ustawienia.php" class="kp-typ-menu mt-3">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="wroc" value="panel">
    <fieldset class="d-flex flex-wrap align-items-center gap-3 m-0 p-0 border-0">
      <legend class="small fw-semibold m-0 w-auto float-none">Typ menu:</legend>
      <?php foreach (['zakladki' => 'Zakładki', 'rozwijane' => 'Rozwijana sekcja', 'dropdown' => 'Dropdown'] as $_v => $_l): ?>
      <div class="form-check mb-0">
        <input class="form-check-input" type="radio" name="nav" id="kp-menu-<?= h($_v) ?>" value="<?= h($_v) ?>" <?= ($KUR_NAV ?? 'zakladki') === $_v ? 'checked' : '' ?>>
        <label class="form-check-label" for="kp-menu-<?= h($_v) ?>"><?= h($_l) ?></label>
      </div>
      <?php endforeach; ?>
      <button type="submit" class="btn btn-sm btn-outline-dark">Zapisz</button>
    </fieldset>
  </form>
</section>
<?php } catch (\Throwable $e) { echo '<div class="alert alert-danger" role="alert">Nie udało się wyświetlić ekranu startowego: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>'; } ?>
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

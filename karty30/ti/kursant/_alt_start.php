<?php
/**
 * karty30/ti/kursant/_alt_start.php — ekran startowy widoku alternatywnego: cały panel jako kafle.
 * Na górze 4 najważniejsze moduły, niżej wszystkie sekcje jako grupy kafli.
 * Wyszukiwarka przeszukuje wszystkie pozycje menu. Zmienne pochodzą z _alt_bar.php.
 */
require_once dirname(__DIR__, 3) . '/includes/auth.php'; // csrf_token() dla formularza typu menu

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
<?php try { ?>
<section class="kp-start container-xl px-3 py-4" aria-labelledby="kp-start-h">
  <h1 class="h4 fw-bold mb-3" id="kp-start-h">Panel kursanta</h1>

  <div class="mb-4">
    <label for="kp-szukaj" class="form-label fw-semibold">Szukaj w panelu</label>
    <input type="search" id="kp-szukaj" class="form-control form-control-lg" placeholder="np. oceny, lekcje, zapisy"
           autocomplete="off" aria-describedby="kp-szukaj-wynik">
    <div class="small text-body-secondary mt-1" id="kp-szukaj-wynik" aria-live="polite"></div>
  </div>

  <!-- Wybór typu menu bez wychodzenia z panelu -->
  <form method="post" action="<?= h(APP_URL) ?>/tozsamosc/kursant_ustawienia.php" class="d-flex flex-wrap align-items-end gap-2 mb-4">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="wroc" value="panel">
    <fieldset class="d-flex flex-wrap gap-2 m-0 p-0 border-0">
      <legend class="small fw-semibold mb-1 w-100">Typ menu</legend>
      <?php foreach (['zakladki' => 'Zakładki', 'rozwijane' => 'Rozwijana sekcja', 'dropdown' => 'Dropdown'] as $_v => $_l): ?>
      <div class="form-check mb-0">
        <input class="form-check-input" type="radio" name="nav" id="kp-menu-<?= h($_v) ?>" value="<?= h($_v) ?>" <?= ($KUR_NAV ?? 'zakladki') === $_v ? 'checked' : '' ?>>
        <label class="form-check-label" for="kp-menu-<?= h($_v) ?>"><?= h($_l) ?></label>
      </div>
      <?php endforeach; ?>
    </fieldset>
    <button type="submit" class="btn btn-sm btn-outline-secondary">Zapisz typ menu</button>
  </form>

  <?php
    // Kafle stanu: dzisiejsze zajęcia, zadania nadchodzące i oddane
    $_st_now = date('Y-m-d H:i:s');
    $_st_nad = []; $_st_odd = [];
    foreach (($homeworks_student ?? []) as $_h) {
        if (!empty($_h['sub_id'])) { $_st_odd[] = $_h; continue; }
        if (empty($_h['due_at']) || (string)$_h['due_at'] >= $_st_now) $_st_nad[] = $_h;
    }
    usort($_st_nad, fn($a, $b) => strcmp((string)($a['due_at'] ?? 'z'), (string)($b['due_at'] ?? 'z')));
    usort($_st_odd, fn($a, $b) => strcmp((string)($b['sub_at'] ?? $b['due_at'] ?? ''), (string)($a['sub_at'] ?? $a['due_at'] ?? '')));
  ?>
  <div class="row g-3 mb-4">
    <?php if (!empty($today_lessons)): ?>
    <div class="col-12 col-md-4">
      <a class="kp-kafel kp-kafel-stan kp-kafel-dzis h-100" href="?tab=lekcje">
        <span class="kp-kafel-sekcja">Dziś masz zajęcia</span>
        <?php foreach ($today_lessons as $_tl): ?>
        <span class="kp-kafel-tytul"><?= h($_tl['course_name']) ?><?= !empty($_tl['time_from']) ? ' · ' . h($_tl['time_from']) : '' ?></span>
        <?php endforeach; ?>
      </a>
    </div>
    <?php endif; ?>

    <div class="col-12 col-md-4">
      <div class="kp-kafel kp-kafel-stan h-100">
        <span class="kp-kafel-sekcja">Zadania nadchodzące (<?= count($_st_nad) ?>)</span>
        <?php if (!$_st_nad): ?><span class="small">Brak zadań do oddania.</span><?php endif; ?>
        <?php foreach (array_slice($_st_nad, 0, 4) as $_h): ?>
        <a class="kp-kafel-tytul kp-link" href="?tab=zadania&amp;hw=<?= (int)$_h['id'] ?>"><?= h($_h['title'] ?? 'Zadanie') ?><?php if (!empty($_h['due_at'])): ?><span class="d-block small">termin: <time datetime="<?= h(str_replace(' ', 'T', substr((string)$_h['due_at'], 0, 16))) ?>"><?= h(substr((string)$_h['due_at'], 0, 16)) ?></time></span><?php endif; ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="col-12 col-md-4">
      <div class="kp-kafel kp-kafel-stan h-100">
        <span class="kp-kafel-sekcja">Zadania oddane (<?= count($_st_odd) ?>)</span>
        <?php if (!$_st_odd): ?><span class="small">Nie oddałeś jeszcze żadnego zadania.</span><?php endif; ?>
        <?php foreach (array_slice($_st_odd, 0, 4) as $_h): ?>
        <a class="kp-kafel-tytul kp-link" href="?tab=zadania&amp;hw=<?= (int)$_h['id'] ?>"><?= h($_h['title'] ?? 'Zadanie') ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <?php
    // Najważniejsze rzeczy związane z nauką — linki w jednym miejscu
    $_st_nauka_klucze = ['lekcje', 'zadania', 'oceny', 'plan', 'online', 'egzaminy'];
    $_st_nauka = [];
    foreach ($_st_nauka_klucze as $_nk) {
        foreach ($_st_wszystkie as $_p) { if ($_p['klucz'] === $_nk) { $_st_nauka[] = $_p; break; } }
    }
    foreach ($_st_wszystkie as $_p) { if ($_p['etykieta'] === 'Biblioteka materiałów') { $_st_nauka[] = $_p; break; } }
  ?>
  <?php if ($_st_nauka): ?>
  <nav class="kp-nauka mb-4" aria-labelledby="kp-nauka-h">
    <h2 class="h6 fw-bold text-uppercase mb-2" id="kp-nauka-h">Najważniejsze do nauki</h2>
    <ul class="kp-nauka-lista">
      <?php foreach ($_st_nauka as $_k): ?>
      <li><a href="<?= h($_k['href']) ?>"><i class="bi bi-chevron-right" aria-hidden="true"></i> <?= h($_k['etykieta']) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <?php endif; ?>

  <!-- Widok domyślny: najważniejsze i wszystkie sekcje -->
  <div id="kp-widok">
    <h2 class="h6 fw-bold text-uppercase mb-2">Najważniejsze</h2>
    <ul class="kp-kafle kp-kafle-glowne mb-4">
      <?php foreach ($_st_glowne as $_k): ?>
      <li>
        <a class="kp-kafel kp-kafel-glowny" href="<?= h($_k['href']) ?>">
          <span class="kp-kafel-sekcja"><?= h($_k['sekcja']) ?></span>
          <i class="bi bi-<?= h($_ikony[$_k['klucz']] ?? 'circle') ?> kp-kafel-ikona" aria-hidden="true"></i>
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
            <i class="bi bi-<?= h($_ikony[$_k['klucz']] ?? 'circle') ?> kp-kafel-ikona" aria-hidden="true"></i>
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
        <i class="bi bi-<?= h($_ikony[$_k['klucz']] ?? 'circle') ?> kp-kafel-ikona" aria-hidden="true"></i>
        <span class="kp-kafel-tytul"><?= h($_k['etykieta']) ?></span>
        <?php if ($_k['n'] > 0): ?><span class="badge text-bg-<?= h($_k['v']) ?>"><?= (int)$_k['n'] ?></span><?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>

  <p class="d-none mt-3" id="kp-brak">Nic nie pasuje do wyszukiwania. Spróbuj innego słowa.</p>
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

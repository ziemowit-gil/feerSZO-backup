<?php
/**
 * karty30/ti/kursant/_alt_start.php — ekran startowy widoku alternatywnego w stylu „Start”:
 * ciemna siatka kafli pogrupowanych (Na dziś → Najważniejsze → sekcje menu), wyszukiwarka, wybór typu menu.
 * Pozycje z „Najważniejsze” nie powtarzają się w sekcjach. Zmienne ($_alt_sections, $_ikony, $KUR_NAV)
 * pochodzą z _alt_bar.php; $homeworks_student i $today_lessons z index.php.
 */
require_once dirname(__DIR__, 3) . '/includes/auth.php'; // csrf_token() dla formularza typu menu

// „Moje lekcje” jest na samej górze (Na dziś), więc nie powtarza się w Najważniejsze ani w sekcjach
$_st_glowne_klucze = ['zadania', 'komunikaty', 'oceny'];
// Kolory kafli: ciemny tekst #1d1d1d na każdym ma kontrast ≥ 4,5:1.
// Najważniejsze — zawsze akcent METRO; grupy — jeden stały kolor na grupę (po etykiecie), reszta z palety.
$_w8_akcent = '#1ba1e2';
$_w8_grupy  = ['Mój panel' => '#00aba9', 'Szkolenia' => '#a4c400', 'Komunikacja' => '#f0a30a',
               'Dostępy' => '#e3c800', 'Sprawy' => '#fa6800', 'Konto' => '#cfd8dc'];
$_w8_kolory = ['#00aba9', '#a4c400', '#f0a30a', '#e3c800', '#fa6800', '#cfd8dc'];

/** Ikona kafla: po kluczu zakładki, a gdy go brak (linki zewnętrzne) — po etykiecie. */
$_st_ikona = function (array $p) use ($_ikony): string {
    if ($p['klucz'] !== '' && isset($_ikony[$p['klucz']])) return $_ikony[$p['klucz']];
    if (mb_stripos($p['etykieta'], 'Biblioteka') !== false) return 'book';
    if (mb_stripos($p['etykieta'], 'Menu panelu') !== false) return 'layout-text-sidebar';
    if (mb_stripos($p['etykieta'], 'Napisz') !== false) return 'chat-dots';
    if (mb_stripos($p['etykieta'], 'Odwołaj') !== false) return 'calendar2-x';
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

// Bez dubli w siatce: „Dane kursanta” i „Aktywność” są już w pasku nad panelem,
// a link z „Najważniejsze” nie powtarza się w sekcjach menu.
$_st_pasek_klucze = ['dane', 'aktywnosc', 'lekcje'];
$_st_uzyte = array_flip(array_column($_st_glowne, 'href'));
$_st_sekcje_bez_dubli = [];
foreach ($_st_sekcje as $_sec) {
    $_poz = [];
    foreach ($_sec['pozycje'] as $_k) {
        if (in_array($_k['klucz'], $_st_pasek_klucze, true) || isset($_st_uzyte[$_k['href']])) continue;
        $_st_uzyte[$_k['href']] = true;
        $_poz[] = $_k;
    }
    if ($_poz) $_st_sekcje_bez_dubli[] = ['label' => $_sec['label'], 'pozycje' => $_poz];
}
$_st_sekcje = $_st_sekcje_bez_dubli;

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
      <div class="kp-szukaj-pole">
        <i class="bi bi-search kp-szukaj-lupa" aria-hidden="true"></i>
        <input type="search" id="kp-szukaj" class="kp-szukaj-input" placeholder="Szukaj: oceny, lekcje, zapisy…"
               autocomplete="off" aria-describedby="kp-szukaj-wynik">
        <button type="button" class="kp-szukaj-strzalka" id="kp-szukaj-idz" aria-label="Przejdź do pierwszego wyniku">
          <i class="bi bi-arrow-right" aria-hidden="true"></i>
        </button>
      </div>
      <div class="small mt-1" id="kp-szukaj-wynik" aria-live="polite"></div>
    </div>
    <?php if (!empty($active_lesson['eff_link'])): ?>
    <a href="<?= h($active_lesson['eff_link']) ?>" target="_blank" rel="noopener"
       class="btn btn-success kp-dolacz-lekcje align-self-start"
       aria-label="Dołącz do lekcji: <?= h($active_lesson['course_name']) ?> (otwiera się w nowej karcie)">
      <i class="bi bi-camera-video me-1" aria-hidden="true"></i>Dołącz do lekcji
    </a>
    <?php endif; ?>
    <?php if (!empty($next_lesson) && ($next_lesson['lesson_method'] ?? '') === 'stacjonarna'): ?>
    <div class="kp-najblizsze small align-self-start" role="note">
      <strong>Najbliższe zajęcia stacjonarne:</strong>
      <time datetime="<?= h($next_lesson['lesson_date']) ?>"><?= h($next_lesson['lesson_date']) ?></time><?php if (!empty($next_lesson['time_from'])): ?>,
      <?= h($next_lesson['time_from']) ?><?= !empty($next_lesson['time_to']) ? '–' . h($next_lesson['time_to']) : '' ?><?php endif; ?>
      · <span><?= h(trim((string)($next_lesson['course_location'] ?? '')) !== '' ? $next_lesson['course_location'] : 'miejsce do ustalenia z prowadzącym') ?></span>
    </div>
    <?php endif; ?>
  </div>

  <div id="kp-widok" class="kp-w8">

    <!-- Na dziś -->
    <section class="kp-w8-grupa" aria-labelledby="kp-w8-g-dzis">
      <h2 class="kp-w8-naglowek" id="kp-w8-g-dzis">Na dziś</h2>
      <ul class="kp-w8-siatka">
        <!-- Moje lekcje: zawsze pierwszy kafel po zalogowaniu (dzisiejsze zajęcia pod tytułem) -->
        <li class="kp-w8-kafel" style="--kolor:#1ba1e2">
          <a href="?tab=lekcje">
            <i class="bi bi-calendar-check kp-w8-ikona" aria-hidden="true"></i>
            <span class="kp-w8-tytul">Moje lekcje
              <?php
          // Najbliższe zajęcia: pierwsza lekcja, która jeszcze się nie skończyła (data + godzina wg PHP, strefa Europe/Warsaw)
          $_st_next = null;
          try {
              $_st_next = db_one(
                  "SELECT s.lesson_date, s.time_from, s.time_to, s.topic, c.name AS course_name
                     FROM k30_ti_sessions s
                     JOIN k30_ti_courses c ON c.id = s.course_id
                     JOIN k30_ti_enrollments e ON e.course_id = c.id AND e.client_id = ? AND e.status = 'active'
                    WHERE (s.lesson_date > ? OR (s.lesson_date = ? AND COALESCE(NULLIF(s.time_to,''), NULLIF(s.time_from,''), '23:59') >= ?))
                      AND (s.status IS NULL OR s.status NOT IN ('cancelled','removed','reserved','remote_material'))
                      AND NOT EXISTS (SELECT 1 FROM k30_ti_attendance a WHERE a.session_id = s.id AND a.client_id = e.client_id AND COALESCE(a.cancelled,0) = 1)
                    ORDER BY s.lesson_date, s.time_from LIMIT 1",
                  [(int)$student['client_id'], date('Y-m-d'), date('Y-m-d'), date('H:i')]);
          } catch (\Throwable $e) { $_st_next = null; }
          $_st_next_ts = $_st_next ? strtotime($_st_next['lesson_date'] . ' ' . (trim((string)$_st_next['time_from']) !== '' ? $_st_next['time_from'] : '00:00')) : null;
          $_st_za = function (int $ts): string {
              $d = $ts - time();
              if ($d <= 0) return 'trwają teraz';
              $dni = intdiv($d, 86400); $godz = intdiv($d % 86400, 3600); $min = intdiv($d % 3600, 60);
              if ($dni > 0) return 'za ' . $dni . ' ' . ($dni === 1 ? 'dzień' : 'dni') . ($godz > 0 ? ' ' . $godz . ' godz.' : '');
              if ($godz > 0) return 'za ' . $godz . ' godz. ' . $min . ' min';
              return 'za ' . max(1, $min) . ' min';
          };
          $_st_dzien = function (string $d): string {
              $diff = (int)round((strtotime($d) - strtotime(date('Y-m-d'))) / 86400);
              if ($diff === 0) return 'dziś';
              if ($diff === 1) return 'jutro';
              $dn = ['niedziela', 'poniedziałek', 'wtorek', 'środa', 'czwartek', 'piątek', 'sobota'];
              return $dn[(int)date('w', strtotime($d))] . ' ' . date('d.m', strtotime($d));
          };
        ?>
        <li class="kp-w8-kafel kp-w8-szeroki kp-w8-nastepne" style="--kolor:#fcd116">
          <a href="?tab=lekcje">
            <i class="bi bi-alarm kp-w8-ikona" aria-hidden="true"></i>
            <span class="kp-w8-tytul">Najbliższe zajęcia
              <?php if ($_st_next): ?>
              <span class="kp-w8-licznik-czas" <?= $_st_next_ts ? 'data-start="' . (int)$_st_next_ts . '"' : '' ?>><?= h($_st_za((int)$_st_next_ts)) ?></span>
              <span class="kp-w8-pod"><?= h($_st_next['course_name']) ?><?= trim((string)$_st_next['topic']) !== '' ? ' — ' . h($_st_next['topic']) : '' ?></span>
              <span class="kp-w8-pod"><time datetime="<?= h($_st_next['lesson_date'] . (trim((string)$_st_next['time_from']) !== '' ? 'T' . $_st_next['time_from'] : '')) ?>"><?= h($_st_dzien($_st_next['lesson_date'])) ?><?= trim((string)$_st_next['time_from']) !== '' ? ', ' . h($_st_next['time_from']) : '' ?><?= trim((string)$_st_next['time_to']) !== '' ? '–' . h($_st_next['time_to']) : '' ?></time></span>
              <?php else: ?>
              <span class="kp-w8-pod">Brak zaplanowanych zajęć.</span>
              <?php endif; ?>
            </span>
          </a>
        </li>
            </span>
          </a>
        </li>
        <li class="kp-w8-kafel" style="--kolor:#f0a30a">
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
        <li class="kp-w8-kafel" style="--kolor:#60a917">
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
        <li class="kp-w8-kafel" style="--kolor: <?= $_w8_akcent ?>">
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
    <?php foreach ($_st_sekcje as $_si => $_sec): $_sid = 'kp-w8-g-' . $_si; $_kol = $_w8_grupy[$_sec['label']] ?? $_w8_kolory[$_si % count($_w8_kolory)]; ?>
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
  var el = document.querySelector('.kp-w8-licznik-czas[data-start]');
  if (!el) return;
  function za() {
    var d = Math.floor(parseInt(el.getAttribute('data-start'), 10) - Date.now() / 1000);
    if (d <= 0) return 'trwają teraz';
    var dni = Math.floor(d / 86400), g = Math.floor((d % 86400) / 3600), m = Math.floor((d % 3600) / 60);
    if (dni > 0) return 'za ' + dni + ' ' + (dni === 1 ? 'dzień' : 'dni') + (g > 0 ? ' ' + g + ' godz.' : '');
    if (g > 0) return 'za ' + g + ' godz. ' + m + ' min';
    return 'za ' + Math.max(1, m) + ' min';
  }
  setInterval(function () { el.textContent = za(); }, 60000);
})();
</script>
<script>
(function () {
  var inp = document.getElementById('kp-szukaj');
  if (!inp) return;
  var widok = document.getElementById('kp-widok');
  var wyniki = document.getElementById('kp-wyniki');
  var pozycje = wyniki.querySelectorAll('.kp-kafel-poz');
  var info = document.getElementById('kp-szukaj-wynik');
  var brak = document.getElementById('kp-brak');
  // Strzałka i Enter przenoszą fokus do pierwszego pasującego kafla
  var idz = document.getElementById('kp-szukaj-idz');
  var przejdz = function () {
    var pierwszy = wyniki.querySelector('.kp-kafel-poz:not(.d-none) a');
    if (pierwszy) pierwszy.focus();
  };
  idz.addEventListener('click', przejdz);
  inp.addEventListener('keydown', function (e) {
    if (e.key === 'Enter') { e.preventDefault(); przejdz(); }
  });
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

<?php
/**
 * karty30/ti/kursant/_alt_zadania.php — zakładka „Dydaktyka / eLearning”
 * w widoku alternatywnym.
 *
 * Widok klasyczny sypie kartami: sekcja na lekcję, w niej materiały i zadania,
 * wszystko rozwinięte. Przy kilkunastu lekcjach termin najbliższego zadania
 * trzeba wyłuskać wzrokiem ze środka strony. Tutaj jest odwrotnie: po lewej
 * lista wszystkich zadań uszeregowana według pilności (po terminie → do oddania
 * → wkrótce → oddane), po prawej szczegóły tego wybranego. Wybór idzie adresem
 * (`?tab=zadania&hw=ID`), więc działa bez JS, da się go dodać do zakładek
 * i wraca po odświeżeniu — żadnych modali (te gubią kontekst przy powiększeniu
 * i czytniku ekranu).
 *
 * Dostępność: zaznaczenie niesie `aria-current="page"`, pogrubienie i belka po
 * lewej — nie sam kolor; status jest SŁOWEM w plakietce, nie kolorem obwódki;
 * daty w <time datetime>; panel szczegółów ma tabindex="-1" i kotwicę, więc
 * klawiatura ląduje w treści, a nie na początku strony.
 *
 * Zmienne z index.php: $homeworks_student, $dyd_groups, $vlab_token.
 */

$now = date('Y-m-d H:i:s');

/** Stan zadania: [klucz, etykieta, wariant plakietki, waga sortowania]. */
$_hw_state = function (array $h) use ($now): array {
    $done   = !empty($h['sub_id']);
    $graded = ($h['sub_status'] ?? '') === 'graded';
    $av     = k30_ti_avail_status($h['open_at'] ?? null, $h['close_at'] ?? null, $now);
    if ($graded)                     return ['graded',   'Ocenione',    'success',   6];
    if ($done)                       return ['done',     'Oddane',      'secondary', 5];
    if ($av['state'] === 'upcoming') return ['upcoming', 'Wkrótce',     'warning',   3];
    if ($av['state'] === 'closed')   return ['closed',   'Zamknięte',   'danger',    4];
    if (!empty($h['due_at']) && $h['due_at'] < $now) return ['overdue', 'Po terminie', 'danger', 1];
    return ['todo', 'Do oddania', 'warning', 2];
};

/** Data w formacie „2026-08-27 14:00" → element <time> z pełnym atrybutem. */
$_hw_time = function (?string $ts): string {
    $ts = trim((string)$ts);
    if ($ts === '') return '';
    return '<time datetime="' . h(str_replace(' ', 'T', substr($ts, 0, 16))) . '">'
         . h(substr($ts, 0, 16)) . '</time>';
};

// ── Lista zadań: najpierw pilne, w grupie po terminie rosnąco ────────────────
$hw_rows = [];
foreach (($homeworks_student ?? []) as $h) {
    [$key, $label, $variant, $weight] = $_hw_state($h);
    $hw_rows[] = ['h' => $h, 'key' => $key, 'label' => $label, 'v' => $variant, 'w' => $weight];
}
usort($hw_rows, function ($a, $b) {
    if ($a['w'] !== $b['w']) return $a['w'] <=> $b['w'];
    $da = (string)($a['h']['due_at'] ?? ''); $db = (string)($b['h']['due_at'] ?? '');
    if ($da === '') return 1;                 // bez terminu na końcu grupy
    if ($db === '') return -1;
    return strcmp($da, $db);
});

$counts = ['overdue' => 0, 'todo' => 0, 'upcoming' => 0, 'closed' => 0, 'done' => 0, 'graded' => 0];
foreach ($hw_rows as $r) { $counts[$r['key']]++; }

// Wybrane zadanie: z adresu, a gdy brak/nie istnieje — pierwsze z listy (najpilniejsze)
$sel_id  = (int)($_GET['hw'] ?? 0);
$sel_row = null;
foreach ($hw_rows as $r) { if ((int)$r['h']['id'] === $sel_id) { $sel_row = $r; break; } }
if (!$sel_row && $hw_rows) $sel_row = $hw_rows[0];

// Materiały lekcji, przy której stoi wybrane zadanie
$sel_materials = [];
if ($sel_row) {
    $ssid = (int)($sel_row['h']['session_id'] ?? 0);
    foreach (($dyd_groups ?? []) as $g) {
        if ((int)$g['session_id'] === $ssid) { $sel_materials = $g['materials']; break; }
    }
}
?>

<h1 class="h5 fw-bold mb-1"><i class="bi bi-mortarboard text-primary me-1" aria-hidden="true"></i>Dydaktyka / eLearning</h1>
<p class="text-body-secondary small mb-3">
  Zadania od prowadzącego uszeregowane według pilności. Wybierz zadanie z listy — szczegóły,
  materiały i oddawanie pokażą się obok. Oceny znajdziesz w zakładce <a href="?tab=oceny">Oceny</a>.
</p>

<?php $hwf = $_SESSION['hw_flash'] ?? null; unset($_SESSION['hw_flash']); if ($hwf): ?>
<div class="alert alert-<?= $hwf[0] === 'ok' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
  <i class="bi bi-<?= $hwf[0] === 'ok' ? 'check-circle' : 'exclamation-triangle' ?> me-1" aria-hidden="true"></i><?= h($hwf[1]) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
</div>
<?php endif; ?>

<?php if (!$hw_rows && !($dyd_groups ?? [])): ?>
<div class="alert alert-info"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak materiałów i zadań od prowadzącego.</div>
<?php else: ?>

<?php /* Podsumowanie jest zdaniem, nie paskiem kolorów — czytnik ekranu przeczyta
         je tak samo jak wzrok, a liczby mówią wprost, ile czego zostało. */ ?>
<p class="mb-3">
  <strong>Twoje zadania:</strong>
  po terminie <strong><?= $counts['overdue'] ?></strong>,
  do oddania <strong><?= $counts['todo'] ?></strong>,
  wkrótce <strong><?= $counts['upcoming'] ?></strong>,
  oddane <strong><?= $counts['done'] ?></strong>,
  ocenione <strong><?= $counts['graded'] ?></strong>.
</p>

<div class="row g-3">

  <!-- ── Lewa kolumna: lista zadań ────────────────────────────────────────── -->
  <div class="col-12 col-lg-4">
    <nav aria-label="Lista zadań">
      <?php if (!$hw_rows): ?>
      <p class="text-body-secondary small">Nie masz jeszcze żadnych zadań — poniżej są same materiały.</p>
      <?php else: ?>
      <ul class="list-group alt-zad-list">
        <?php foreach ($hw_rows as $r): $h = $r['h'];
          $is_sel = $sel_row && (int)$h['id'] === (int)$sel_row['h']['id']; ?>
        <li class="list-group-item p-0">
          <a class="d-block px-2 py-2 text-decoration-none alt-zad-item<?= $is_sel ? ' alt-zad-item-sel' : '' ?>"
             href="?tab=zadania&amp;hw=<?= (int)$h['id'] ?>#zad-szczegoly"
             <?= $is_sel ? 'aria-current="page"' : '' ?>>
            <span class="d-flex align-items-start gap-2">
              <span aria-hidden="true" class="alt-zad-mark"><?= $is_sel ? '&rsaquo;' : '' ?></span>
              <span class="flex-grow-1 min-width-0">
                <span class="d-block <?= $is_sel ? 'fw-bold' : 'fw-semibold' ?>"><?= h($h['title']) ?></span>
                <span class="d-block small text-body-secondary">
                  <?= h($h['course_name']) ?>
                  <?php if (!empty($h['due_at'])): ?>
                    · termin: <?= $_hw_time($h['due_at']) ?>
                  <?php else: ?>
                    · bez terminu
                  <?php endif; ?>
                </span>
              </span>
              <span class="badge text-bg-<?= h($r['v']) ?> flex-shrink-0">
                <span class="visually-hidden">Status: </span><?= h($r['label']) ?>
              </span>
            </span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </nav>
  </div>

  <!-- ── Prawa kolumna: szczegóły wybranego zadania ───────────────────────── -->
  <div class="col-12 col-lg-8">
    <section id="zad-szczegoly" tabindex="-1" aria-labelledby="zad-szczegoly-h">
      <?php if (!$sel_row): ?>
      <h2 id="zad-szczegoly-h" class="h6 fw-bold">Szczegóły zadania</h2>
      <p class="text-body-secondary small">Nie masz zadań do wykonania.</p>
      <?php else: $h = $sel_row['h']; ?>
      <h2 id="zad-szczegoly-h" class="h6 fw-bold mb-2">
        Zadanie: <?= h($h['title']) ?>
        <span class="fw-normal text-body-secondary">— <?= h($sel_row['label']) ?></span>
      </h2>
      <?php
        // Karta zadania jest ta sama co w widoku klasycznym — tu tylko zawsze
        // rozwinięta, bo to jedyna treść tej kolumny (patrz $dyd_hw_force_open).
        $dyd_hw_force_open = true;
        include __DIR__ . '/_dyd_homework.php';
        $dyd_hw_force_open = false;
      ?>

      <?php if ($sel_materials): ?>
      <h3 class="h6 fw-bold mt-4 mb-2">Materiały do tej lekcji</h3>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <caption class="visually-hidden">Materiały przypisane do lekcji z wybranym zadaniem</caption>
          <thead>
            <tr>
              <th scope="col">Rodzaj</th>
              <th scope="col">Tytuł</th>
              <th scope="col">Dostępność</th>
              <th scope="col">Otwórz</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($sel_materials as $m):
              $mav = k30_ti_avail_status($m['open_at'] ?? null, $m['close_at'] ?? null);
              $mopen = $mav['state'] === 'open'; ?>
            <tr>
              <td><i class="bi bi-<?= h(k30_ti_material_type_icon($m['type'])) ?> me-1" aria-hidden="true"></i><?= h(k30_ti_material_type_label($m['type'])) ?></td>
              <th scope="row" class="fw-semibold"><?= h($m['title']) ?></th>
              <td><?= $mopen ? 'dostępny' : h($mav['label']) ?></td>
              <td class="text-nowrap">
                <?php if (!$mopen): ?>
                <span class="text-body-secondary">—</span>
                <?php else: ?>
                  <?php if (!empty($m['url'])): ?>
                  <a href="<?= h($m['url']) ?>" target="_blank" rel="noopener">Link<span class="visually-hidden"> do materiału <?= h($m['title']) ?> (nowa karta)</span></a>
                  <?php endif; ?>
                  <?php if (!empty($m['attach_path'])): ?>
                  <?= !empty($m['url']) ? ' · ' : '' ?>
                  <a href="material_file.php?id=<?= (int)$m['id'] ?>">Plik<span class="visually-hidden"> materiału <?= h($m['title']) ?></span></a>
                  <?php endif; ?>
                  <?php if (empty($m['url']) && empty($m['attach_path'])): ?>
                  <span class="text-body-secondary">—</span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
</div>

<?php
// ── Wszystkie materiały: jedna tabela zamiast kart rozsypanych po lekcjach ───
$all_mats = [];
foreach (($dyd_groups ?? []) as $g) {
    foreach ($g['materials'] as $m) {
        $all_mats[] = $m + ['_lesson' => $g['session_id'] ? (substr((string)$g['date'], 0, 10) . ($g['topic'] ? ' · ' . $g['topic'] : '')) : 'bez przypisanej lekcji'];
    }
}
if ($all_mats): ?>
<h2 class="h6 fw-bold mt-4 mb-2">Wszystkie materiały</h2>
<?php /* Widok klasyczny chowa materiały z lekcji starszych niż 14 dni, żeby
         ściana kart nie rosła. Tabela nie ma tego problemu, więc nic tu nie
         ukrywamy — kursant szukający czegoś sprzed miesiąca to znajdzie. */ ?>
<p class="text-body-secondary small mb-2">Wszystko, co udostępnił prowadzący — także ze starszych lekcji.</p>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Materiały ze wszystkich lekcji, od najnowszej</caption>
    <thead>
      <tr>
        <th scope="col">Lekcja</th>
        <th scope="col">Rodzaj</th>
        <th scope="col">Tytuł</th>
        <th scope="col">Dostępność</th>
        <th scope="col">Otwórz</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($all_mats as $m):
        $mav   = k30_ti_avail_status($m['open_at'] ?? null, $m['close_at'] ?? null);
        $mopen = $mav['state'] === 'open'; ?>
      <tr>
        <td class="text-nowrap"><?= h($m['_lesson']) ?></td>
        <td><i class="bi bi-<?= h(k30_ti_material_type_icon($m['type'])) ?> me-1" aria-hidden="true"></i><?= h(k30_ti_material_type_label($m['type'])) ?></td>
        <th scope="row" class="fw-semibold"><?= h($m['title']) ?></th>
        <td><?= $mopen ? 'dostępny' : h($mav['label']) ?></td>
        <td class="text-nowrap">
          <?php if (!$mopen): ?>
          <span class="text-body-secondary">—</span>
          <?php else: ?>
            <?php if (!empty($m['url'])): ?>
            <a href="<?= h($m['url']) ?>" target="_blank" rel="noopener">Link<span class="visually-hidden"> do materiału <?= h($m['title']) ?> (nowa karta)</span></a>
            <?php endif; ?>
            <?php if (!empty($m['attach_path'])): ?>
            <?= !empty($m['url']) ? ' · ' : '' ?>
            <a href="material_file.php?id=<?= (int)$m['id'] ?>">Plik<span class="visually-hidden"> materiału <?= h($m['title']) ?></span></a>
            <?php endif; ?>
            <?php if (empty($m['url']) && empty($m['attach_path'])): ?>
            <span class="text-body-secondary">—</span>
            <?php endif; ?>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php endif; ?>

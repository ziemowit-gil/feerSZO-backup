<?php
/**
 * modules/contract_status/view/map.php — rozpiska statusów umów ze słownika umowy_statusy.
 * Zmienne (contractStatusMap()): $rows, $types, $counts, $unknown, $dict.
 */
$PAGE_TITLE = 'Statusy umów — rozpiska';
include dirname(__DIR__, 3) . '/includes/header.php';

// Słownik Centralny — trasa w ?_route= (adres „router.php/…” serwer traktuje jak plik)
$dictUrl = APP_URL . '/modules/dictionary/router.php?_route=/d/' . CONTRACT_STATUS_DICT;
$groupInfo = [
    'draft'     => ['Robocza',     'Przed zawarciem umowy. Liczy się do „żywych” umów (CRM, usuwanie konta, przypomnienia o ewidencji), nie do aktywnych.'],
    'active'    => ['Aktywna',     'Umowa obowiązuje: liczniki aktywnych umów (pulpit, portal, raporty), zwroty kosztów, wypowiedzenie, rachunki zlecenia, dostęp prowadzącego.'],
    'blocked'   => ['Zablokowana', 'Wstrzymana przez system (np. brak ważnej zgody przedstawiciela ustawowego); wyjście tylko do aktywnej albo anulowanej.'],
    'closed'    => ['Zamknięta',   'Umowa wykonana lub wygasła. Wymaga rozliczenia (zaliczka, hologramy, saldo zlecenia); nie ma z niej wyjścia w aplikacji.'],
    'cancelled' => ['Anulowana / rozwiązana', 'Umowa przerwana. Jak zamknięta: wymaga rozliczenia, bez wyjścia — cofnąć może tylko narzędzie „wycofanie”.'],
];
$groupTransitions = ['draft' => ['active', 'cancelled', 'blocked'], 'active' => ['closed', 'cancelled', 'blocked'], 'blocked' => ['active', 'cancelled'], 'closed' => [], 'cancelled' => []];
$byValue = array_column($rows, null, 'value');
$labelOf = fn(string $v) => $byValue[$v]['label'] ?? contractStatusLabel($v);
$total   = fn(string $v) => array_sum(array_map(fn($c) => $c[$v] ?? 0, $counts));
?>
<style>
.csm-groups{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:.5rem}
.csm-group{border-top:4px solid #1f2937;background:#f8fafc;padding:.75rem .9rem}
.csm-group h3{font-size:1rem;font-weight:700;margin:0 0 .25rem}
.csm-group p{font-size:.82rem;color:#334155;margin:0 0 .5rem}
.csm-chip{display:inline-block;padding:.1rem .5rem;margin:0 .2rem .25rem 0;color:#fff;font-size:.8rem;font-weight:600}
.csm-chip.is-off{opacity:.55;text-decoration:line-through}
.csm-table th,.csm-table td{vertical-align:middle;font-size:.88rem}
.csm-table code{font-size:.8rem}
.csm-matrix td.na{color:#64748b}
.csm-matrix td.bad{background:#fef2f2;color:#991b1b;font-weight:700}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <h4 class="mb-0"><i class="bi bi-signpost-split text-primary" aria-hidden="true"></i> Statusy umów — rozpiska</h4>
  <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-primary" href="<?= h($dictUrl) ?>"><i class="bi bi-book-half" aria-hidden="true"></i> Edytuj w Słowniku Centralnym</a>
    <a class="btn btn-sm btn-outline-danger" href="<?= h(route('cst.index')) ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Wycofanie statusu umowy</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= APP_URL ?>/admin/index.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Administracja</a>
  </div>
</div>
<p class="text-body-secondary small mb-3">Jedno źródło statusów wszystkich typów umów: słownik systemowy <code><?= h(CONTRACT_STATUS_DICT) ?></code>.
  Etykiety, kolory, ikony, kolejność, grupy, zestawy w typach i przejścia zmieniasz w słowniku; wartości zapisane w umowach (kolumna „Wartość w danych”) są stałe.</p>
<?php if (!$dict): ?>
<div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
  Słownika <code><?= h(CONTRACT_STATUS_DICT) ?></code> jeszcze nie ma w bazie — system używa wartości domyślnych z kodu. Słownik zakłada migracja jednorazowa <code>2026-10_dictionary_umowy_statusy</code> przy najbliższym żądaniu.</div>
<?php endif; ?>
<?php if ($unknown): ?>
<div class="alert alert-danger" role="alert"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
  <strong>Wartości w umowach spoza słownika:</strong>
  <?php foreach ($unknown as $t => $vals) foreach ($vals as $v => $n): ?>
    <span class="d-inline-block me-3"><?= h($types[$t] ?? $t) ?>: <code><?= h($v === '' ? '(pusty)' : $v) ?></code> × <?= (int)$n ?></span>
  <?php endforeach; ?>
  <div class="small mt-1">Popraw je w umowach albo narzędziem „Wycofanie statusu umowy”.</div></div>
<?php endif; ?>

<h2 class="h5 mt-4">Grupy statusów</h2>
<p class="small text-body-secondary">Grupa decyduje o regułach przejść (obowiązują wszystkich, także administratora) i o tym, które umowy system uznaje za aktywne lub zakończone.</p>
<div class="csm-groups mb-4">
  <?php foreach ($groupInfo as $g => [$gl, $gd]): $in = array_filter($rows, fn($r) => $r['group'] === $g); ?>
  <section class="csm-group" aria-labelledby="csm-g-<?= $g ?>">
    <h3 id="csm-g-<?= $g ?>"><?= h($gl) ?> <span class="fw-normal text-body-secondary small">(<?= h($g) ?>)</span></h3>
    <p><?= h($gd) ?></p>
    <div>
      <?php foreach ($in as $r): ?><span class="csm-chip<?= $r['active'] ? '' : ' is-off' ?>" style="background:<?= h($r['color']) ?>"><?= h($r['label']) ?><?= $r['active'] ? '' : '<span class="visually-hidden"> (wyłączony)</span>' ?></span><?php endforeach; ?>
      <?php if (!$in): ?><span class="small text-body-secondary">brak statusów</span><?php endif; ?>
    </div>
    <p class="mt-2 mb-0"><strong>Dalej do grup:</strong> <?= $groupTransitions[$g] ? h(implode(', ', array_map(fn($x) => $groupInfo[$x][0], $groupTransitions[$g]))) : 'brak (stan końcowy)' ?></p>
  </section>
  <?php endforeach; ?>
</div>

<h2 class="h5">Statusy</h2>
<div class="table-responsive mb-4">
<table class="table table-sm table-hover csm-table">
  <caption class="small">Kolejność jak w słowniku i na listach wyboru. „Przejścia” — dokąd może przestawić umowę edytor; administrator może wybrać dowolny status typu.</caption>
  <thead class="table-light"><tr>
    <th scope="col">Status</th><th scope="col">Wartość w danych</th><th scope="col">Kod wpisu</th><th scope="col">Grupa</th>
    <th scope="col">Przejścia dla edytora</th><th scope="col">Uwagi</th><th scope="col" class="text-end">Umów</th><th scope="col"><span class="visually-hidden">Akcje</span></th>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><span class="csm-chip mb-0" style="background:<?= h($r['color']) ?>"><i class="bi <?= h($r['icon']) ?>" aria-hidden="true"></i> <?= h($r['label']) ?></span></th>
      <td><code><?= h($r['value']) ?></code></td>
      <td><code><?= h($r['entry']) ?></code></td>
      <td><?= h($groupInfo[$r['group']][0] ?? '— poza modelem') ?></td>
      <td><?= $r['next'] ? h(implode(', ', array_map($labelOf, $r['next']))) : '<span class="text-body-secondary">—</span>' ?></td>
      <td class="small">
        <?= $r['active'] ? '' : '<span class="badge text-bg-secondary">wyłączony w słowniku</span> ' ?>
        <?= $r['system'] ? '<span class="badge text-bg-dark">systemowy</span> nadaje go system, nie ma go na listach wyboru' : '' ?>
      </td>
      <td class="text-end"><?= (int)$total($r['value']) ?></td>
      <td><?php if ($r['entry_id']): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h($dictUrl . '/entries/' . $r['entry_id']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edytuj status <?= h($r['label']) ?></span></a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<h2 class="h5">Statusy w typach umów</h2>
<div class="table-responsive mb-4">
<table class="table table-sm table-bordered csm-table csm-matrix">
  <caption class="small">Liczba = umowy w tym statusie. „—” — status niedostępny w typie. Czerwone pole — umowy mają status, którego typ nie przewiduje.</caption>
  <thead class="table-light"><tr>
    <th scope="col">Status</th>
    <?php foreach ($types as $t => $tl): ?><th scope="col" class="text-center"><?= h($tl) ?></th><?php endforeach; ?>
  </tr></thead>
  <tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <th scope="row"><?= h($r['label']) ?></th>
      <?php foreach (array_keys($types) as $t):
        $n  = (int)($counts[$t][$r['value']] ?? 0);
        $in  = in_array($t, $r['types'], true);
        $cls = $in ? '' : ($n && !$r['system'] ? 'bad' : 'na'); // systemowe (aneks) nadaje system w każdym typie ?>
      <td class="text-center <?= $cls ?>">
        <?php if ($in): ?><i class="bi bi-check-lg text-success" aria-hidden="true"></i><span class="visually-hidden"><?= $n ? 'dostępny, liczba umów:' : 'dostępny, brak umów' ?></span> <?= $n ?: '' ?>
        <?php elseif ($n): ?><span class="visually-hidden"><?= $r['system'] ? 'nadany przez system, liczba umów:' : 'status spoza zestawu typu, liczba umów:' ?></span> <?= $n ?>
        <?php else: ?>—<span class="visually-hidden"> niedostępny</span><?php endif; ?>
      </td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
    <tr class="table-light">
      <th scope="row">Razem umów</th>
      <?php foreach (array_keys($types) as $t): ?><td class="text-center fw-semibold"><?= (int)array_sum($counts[$t] ?? []) ?></td><?php endforeach; ?>
    </tr>
  </tbody>
</table>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>

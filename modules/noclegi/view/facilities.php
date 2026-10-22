<?php
/** Baza obiektów noclegowych. Zmienne: $rows, $role. */
$PAGE_TITLE = 'Obiekty noclegowe'; $active = 'obiekty';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
?>
<h1>Obiekty i miejsca noclegowe</h1>
<p class="ncl-lead">Baza obiektów z pokojami, liczbą miejsc, dostępnością oraz skrytkami i kodami do drzwi. Typy obiektów i pokoi pochodzą ze Słownika Centralnego.</p>
<p><a class="btn btn-primary" href="<?= h(route('nocl.facility.new')) ?>">Dodaj obiekt</a></p>
<?php if (!$rows): ?><p class="ncl-muted">Brak obiektów — dodaj pierwszy.</p><?php else: ?>
<div class="ncl-scroll"><table class="ncl-table">
  <caption>Obiekty noclegowe</caption>
  <thead><tr><th scope="col">Obiekt</th><th scope="col">Typ</th><th scope="col">Miejscowość</th><th scope="col">Pokoje</th><th scope="col">Miejsca</th><th scope="col">Dostępność</th><th scope="col">Stan</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $f): ?>
    <tr>
      <td><a href="<?= h(route('nocl.facility', ['id' => $f['id']])) ?>"><?= h($f['name']) ?></a></td>
      <td><?= h(noclTypyObiektow()[$f['type']] ?? $f['type']) ?></td>
      <td><?= h($f['city']) ?></td><td><?= (int)$f['rooms_n'] ?></td><td><?= (int)$f['beds_n'] ?></td>
      <td><?= $f['accessible'] ? 'tak' : '—' ?></td>
      <td><?= $f['is_active'] ? 'aktywny' : '<span class="ncl-muted">wyłączony</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>

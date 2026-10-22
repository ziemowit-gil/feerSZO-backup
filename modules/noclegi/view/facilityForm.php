<?php
/** Obiekt: dane + pokoje. Zmienne: $f, $errors, $id, $rooms, $editRoom, $roomErrors, $role. */
$PAGE_TITLE = $id ? 'Obiekt: ' . ($f['name'] ?? '') : 'Nowy obiekt'; $active = 'obiekty';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
$_v = fn(string $k, $d = '') => $f[$k] ?? $d;
$_e = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="fe-' . h($k) . '">' . h($errors[$k]) . '</div>' : '';
$_a = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="fe-' . h($k) . '"' : '';
?>
<h1><?= $id ? h($f['name'] ?? 'Obiekt') : 'Nowy obiekt' ?></h1>
<p><a href="<?= h(route('nocl.facilities')) ?>">&larr; Wszystkie obiekty</a></p>
<form method="post" action="<?= h($id ? route('nocl.facility.update', ['id' => $id]) : route('nocl.facility.store')) ?>" novalidate>
  <?= Router::csrfField() ?>
  <fieldset><legend>Dane obiektu</legend>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label" for="name">Nazwa *</label><input class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>" id="name" name="name" required value="<?= h($_v('name')) ?>"<?= $_a('name') ?>><?= $_e('name') ?></div>
      <div class="col-md-3"><label class="form-label" for="type">Typ *</label><select class="form-select" id="type" name="type"><?php foreach (noclTypyObiektow() as $c => $l): ?><option value="<?= h($c) ?>" <?= $_v('type') === $c ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select><?= $_e('type') ?></div>
      <div class="col-md-3"><label class="form-label" for="city">Miejscowość</label><input class="form-control" id="city" name="city" value="<?= h($_v('city')) ?>"></div>
      <div class="col-md-6"><label class="form-label" for="address">Adres</label><input class="form-control" id="address" name="address" value="<?= h($_v('address')) ?>"></div>
      <div class="col-md-3"><label class="form-label" for="phone">Telefon</label><input class="form-control" type="tel" id="phone" name="phone" value="<?= h($_v('phone')) ?>"></div>
      <div class="col-md-3"><label class="form-label" for="email">E-mail</label><input class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>" type="email" id="email" name="email" value="<?= h($_v('email')) ?>"<?= $_a('email') ?>><?= $_e('email') ?></div>
      <div class="col-12"><label class="form-label" for="map_url">Link do mapy (https://…) — puste = wyszukiwanie po adresie</label><input class="form-control<?= isset($errors['map_url']) ? ' is-invalid' : '' ?>" id="map_url" name="map_url" value="<?= h($_v('map_url')) ?>"<?= $_a('map_url') ?>><?= $_e('map_url') ?></div>
    </div>
  </fieldset>
  <fieldset><legend>Godziny i instrukcja</legend>
    <div class="row g-3">
      <?php foreach (['checkin_from' => 'Zameldowanie od', 'checkin_to' => 'Zameldowanie do', 'checkout_until' => 'Wymeldowanie do'] as $k => $l): ?>
      <div class="col-sm-4"><label class="form-label" for="<?= $k ?>"><?= h($l) ?></label><input class="form-control<?= isset($errors[$k]) ? ' is-invalid' : '' ?>" type="time" id="<?= $k ?>" name="<?= $k ?>" value="<?= h($_v($k)) ?>"<?= $_a($k) ?>><?= $_e($k) ?></div>
      <?php endforeach; ?>
      <div class="col-12"><label class="form-label" for="access_info">Jak wejść (pokazywane w instrukcji zameldowania)</label><textarea class="form-control" id="access_info" name="access_info" rows="3"><?= h($_v('access_info')) ?></textarea></div>
      <div class="col-12"><label class="form-label" for="rules">Zasady pobytu</label><textarea class="form-control" id="rules" name="rules" rows="2"><?= h($_v('rules')) ?></textarea></div>
    </div>
  </fieldset>
  <fieldset><legend>Dostępność i uwagi</legend>
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="accessible" name="accessible" value="1" <?= !empty($f['accessible']) ? 'checked' : '' ?>><label class="form-check-label" for="accessible">Obiekt dostosowany dla osób z niepełnosprawnościami</label></div>
    <label class="form-label" for="accessibility_info">Opis dostępności (windy, podjazdy, szerokość drzwi…)</label><textarea class="form-control mb-3" id="accessibility_info" name="accessibility_info" rows="2"><?= h($_v('accessibility_info')) ?></textarea>
    <label class="form-label" for="notes">Notatki wewnętrzne (nie trafiają do instrukcji)</label><textarea class="form-control mb-3" id="notes" name="notes" rows="2"><?= h($_v('notes')) ?></textarea>
    <div class="form-check"><input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= !empty($f['is_active']) ? 'checked' : '' ?>><label class="form-check-label" for="is_active">Obiekt aktywny (można przydzielać pokoje)</label></div>
  </fieldset>
  <button class="btn btn-primary">Zapisz obiekt</button>
</form>

<?php if ($id): $ev = fn(string $k, $d = '') => $editRoom[$k] ?? $d; $re = fn(string $k) => isset($roomErrors[$k]) ? '<div class="invalid-feedback d-block" id="re-' . h($k) . '">' . h($roomErrors[$k]) . '</div>' : ''; ?>
<h2 id="pokoje">Pokoje</h2>
<?php if ($rooms): ?>
<div class="ncl-scroll"><table class="ncl-table">
  <caption>Pokoje obiektu</caption>
  <thead><tr><th scope="col">Pokój</th><th scope="col">Typ</th><th scope="col">Miejsca</th><th scope="col">Cena os./noc</th><th scope="col">Dostępny</th><th scope="col">Kod / skrytka</th><th scope="col">Stan</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody>
  <?php foreach ($rooms as $rm): ?><tr>
    <td><?= h($rm['name']) ?></td><td><?= h(noclTypyPokoi()[$rm['room_type']] ?? $rm['room_type']) ?></td><td><?= (int)$rm['beds'] ?></td>
    <td><?= h(noclMoneyFmt(noclMoney((string)$rm['price_per_night']))) ?></td><td><?= $rm['accessible'] ? 'tak' : '—' ?></td>
    <td><?= $rm['door_code'] !== '' || $rm['lockbox'] !== '' ? '<span class="ncl-code">' . h(trim($rm['door_code'] . ' ' . $rm['lockbox'])) . '</span>' : '—' ?></td>
    <td><?= $rm['is_active'] ? 'aktywny' : 'wyłączony' ?></td>
    <td><a href="<?= h(route('nocl.facility', ['id' => $id, 'pokoj' => $rm['id']]) . '#form-pokoj') ?>">Edytuj<span class="visually-hidden"> pokój <?= h($rm['name']) ?></span></a></td>
  </tr><?php endforeach; ?>
  </tbody>
</table></div>
<?php else: ?><p class="ncl-muted">Obiekt nie ma jeszcze pokoi.</p><?php endif; ?>
<form method="post" action="<?= h(route('nocl.room.save', ['id' => $id])) ?>" id="form-pokoj" class="ncl-card mt-3" novalidate>
  <?= Router::csrfField() ?><input type="hidden" name="room_id" value="<?= (int)($editRoom['id'] ?? 0) ?>">
  <h3 class="h5 mt-0"><?= $editRoom ? 'Edytuj pokój ' . h($ev('name')) : 'Dodaj pokój' ?></h3>
  <div class="row g-3">
    <div class="col-sm-3"><label class="form-label" for="rname">Numer / nazwa *</label><input class="form-control<?= isset($roomErrors['name']) ? ' is-invalid' : '' ?>" id="rname" name="name" required value="<?= h($ev('name')) ?>"><?= $re('name') ?></div>
    <div class="col-sm-3"><label class="form-label" for="room_type">Typ *</label><select class="form-select" id="room_type" name="room_type"><?php foreach (noclTypyPokoi() as $c => $l): ?><option value="<?= h($c) ?>" <?= $ev('room_type', 'dwuosobowy') === $c ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-2"><label class="form-label" for="beds">Miejsca</label><input class="form-control<?= isset($roomErrors['beds']) ? ' is-invalid' : '' ?>" type="number" min="1" max="30" id="beds" name="beds" value="<?= h($ev('beds')) ?>" aria-describedby="beds-h"><div class="form-text" id="beds-h">Puste = z typu.</div><?= $re('beds') ?></div>
    <div class="col-sm-4"><label class="form-label" for="price">Cena za osobę za noc (zł)</label><input class="form-control<?= isset($roomErrors['price_per_night']) ? ' is-invalid' : '' ?>" inputmode="decimal" id="price" name="price_per_night" value="<?= h($ev('price_per_night', '0')) ?>"><?= $re('price_per_night') ?></div>
    <div class="col-sm-4"><label class="form-label" for="door_code">Kod do drzwi</label><input class="form-control" id="door_code" name="door_code" autocomplete="off" value="<?= h($ev('door_code')) ?>"></div>
    <div class="col-sm-4"><label class="form-label" for="lockbox">Skrytka na klucz (nr / kod)</label><input class="form-control" id="lockbox" name="lockbox" autocomplete="off" value="<?= h($ev('lockbox')) ?>"></div>
    <div class="col-sm-4"><label class="form-label" for="rnotes">Uwagi</label><input class="form-control" id="rnotes" name="notes" value="<?= h($ev('notes')) ?>"></div>
    <div class="col-12">
      <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="r-acc" name="accessible" value="1" <?= $ev('accessible') ? 'checked' : '' ?>><label class="form-check-label" for="r-acc">Pokój dostępny (dla osób z niepełnosprawnościami)</label></div>
      <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="r-act" name="is_active" value="1" <?= !$editRoom || $ev('is_active') ? 'checked' : '' ?>><label class="form-check-label" for="r-act">Aktywny</label></div>
    </div>
  </div>
  <p class="form-text mt-2">Kody i skrytki widzi Logistyk oraz osoba z potwierdzoną rezerwacją — od <?= (int)noclRevealDays() ?> dni przed przyjazdem. Nie trafiają na listy dla obiektów.</p>
  <button class="btn btn-primary"><?= $editRoom ? 'Zapisz pokój' : 'Dodaj pokój' ?></button>
  <?php if ($editRoom): ?><a class="btn btn-outline-secondary" href="<?= h(route('nocl.facility', ['id' => $id])) ?>">Anuluj</a><?php endif; ?>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>

<?php
/** Karta naruszenia. Zmienne: $b. */
$PAGE_TITLE = 'Naruszenie ochrony danych'; $active = 'rodo.breaches';
include __DIR__ . '/_head.php';
$dt = fn($v) => $v ? str_replace(' ', 'T', substr((string)$v, 0, 16)) : '';
$ta = fn(string $k, string $l, int $rows = 3) => print('<div class="mb-3"><label class="form-label" for="b-' . $k . '">' . h($l) . '</label><textarea class="form-control" id="b-' . $k . '" name="' . $k . '" rows="' . $rows . '">' . h($b[$k] ?? '') . '</textarea></div>');
?>
<?php if (!empty($b['id'])): ?><div class="alert <?= !empty($b['uodo_po_terminie']) ? 'alert-danger' : 'alert-info' ?>">Termin zgłoszenia do UODO (72 h): <strong><?= h($b['uodo_do']) ?></strong>. Opis, skutki i dane wniosków są zapisane w bazie zaszyfrowane.</div><?php endif; ?>
<form method="post" action="<?= h(route('rodo.breach.save', ['id' => (int)$b['id']])) ?>" class="row g-3" style="max-width:56rem"><?= csrf_field() ?>
  <div class="col-md-4"><label class="form-label" for="b-w">Stwierdzono (data i godzina) *</label><input id="b-w" type="datetime-local" name="wykryto_at" class="form-control" value="<?= h($dt($b['wykryto_at'])) ?>" required></div>
  <div class="col-md-4"><label class="form-label" for="b-l">Liczba osób (szacunkowo)</label><input id="b-l" type="number" min="0" name="liczba_osob" class="form-control" value="<?= h($b['liczba_osob'] ?? '') ?>"></div>
  <div class="col-md-4"><label class="form-label" for="b-r">Ocena ryzyka</label><select id="b-r" name="ryzyko" class="form-select"><?php foreach (['nieoceniono' => 'nieoceniono', 'nieprawdopodobne' => 'ryzyko nieprawdopodobne', 'ryzyko' => 'ryzyko', 'wysokie' => 'wysokie ryzyko'] as $k => $l): ?><option value="<?= $k ?>" <?= ($b['ryzyko'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><?php $ta('opis', 'Opis naruszenia * (co, jak, kiedy, jak wykryto)', 4); $ta('kategorie_danych', 'Kategorie danych i osób, których dotyczy', 2); $ta('skutki', 'Możliwe skutki dla osób'); $ta('srodki_zaradcze', 'Podjęte i planowane środki zaradcze'); ?></div>
  <div class="col-md-4"><label class="form-label" for="b-u">Zgłoszono do UODO</label><input id="b-u" type="datetime-local" name="zgloszono_uodo_at" class="form-control" value="<?= h($dt($b['zgloszono_uodo_at'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label" for="b-o">Powiadomiono osoby (art. 34)</label><input id="b-o" type="datetime-local" name="powiadomiono_osoby_at" class="form-control" value="<?= h($dt($b['powiadomiono_osoby_at'] ?? '')) ?>"></div>
  <div class="col-md-4"><label class="form-label" for="b-s">Status</label><select id="b-s" name="status" class="form-select"><?php foreach (RODO_BREACH_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= ($b['status'] ?? '') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><?php $ta('uzasadnienie_braku_zgloszenia', 'Uzasadnienie braku zgłoszenia do UODO (jeśli dotyczy)', 2); ?><button class="btn btn-primary" type="submit">Zapisz</button> <a class="btn btn-link" href="<?= h(route('rodo.breaches')) ?>">Wróć</a></div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>

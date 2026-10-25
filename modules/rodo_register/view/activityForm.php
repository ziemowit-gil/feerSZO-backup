<?php
/** Edycja czynności przetwarzania. Zmienne: $a. */
$PAGE_TITLE = 'Czynność przetwarzania'; $active = 'rodo.activities';
include __DIR__ . '/_head.php';
$f = function (string $k, string $label, bool $area = false) use ($a) {
    $id = 'a-' . $k; $v = h($a[$k] ?? '');
    echo '<div class="mb-3"><label class="form-label" for="' . $id . '">' . h($label) . '</label>' .
        ($area ? '<textarea class="form-control" id="' . $id . '" name="' . $k . '" rows="2">' . $v . '</textarea>' : '<input class="form-control" id="' . $id . '" name="' . $k . '" value="' . $v . '">') . '</div>';
};
$sel = fn(string $k, string $label, array $opts) => print('<div class="mb-3"><label class="form-label" for="a-' . $k . '">' . h($label) . '</label><select class="form-select" id="a-' . $k . '" name="' . $k . '">' .
    implode('', array_map(fn($o) => '<option ' . (($a[$k] ?? '') === $o ? 'selected' : '') . '>' . h($o) . '</option>', $opts)) . '</select></div>');
?>
<form method="post" action="<?= h(route('rodo.activity.save', ['id' => (int)$a['id']])) ?>" class="row g-3"><?= csrf_field() ?>
  <div class="col-lg-8">
    <?php $f('zbior', 'Zbiór / czynność przetwarzania *'); $f('cel', 'Cel przetwarzania *', true); $f('podstawa', 'Podstawa prawna (art. 6 / 9 / 10)'); $f('kategorie_osob', 'Kategorie osób', true);
          $f('kategorie_danych', 'Kategorie danych', true); $f('odbiorcy', 'Odbiorcy danych', true); $f('transfer_poza_eog', 'Transfer poza EOG (kraj, podstawa)'); $f('retencja', 'Planowany termin usunięcia', true);
          $f('srodki', 'Środki bezpieczeństwa (opis ogólny)', true); ?>
  </div>
  <div class="col-lg-4">
    <?php $f('wlasciciel', 'Właściciel merytoryczny'); $f('moduly', 'Moduły / tabele w SZO', true); $sel('rola', 'Rola', ['administrator', 'procesor', 'wspoladministrator']); $sel('dpia', 'DPIA', ['nie_dotyczy', 'wymagane', 'wykonana']); $sel('status', 'Status', ['szkic', 'zatwierdzone']); ?>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="a-sz" name="szczegolne" value="1" <?= !empty($a['szczegolne']) ? 'checked' : '' ?>><label class="form-check-label" for="a-sz">Dane szczególnych kategorii (art. 9) lub o karalności (art. 10)</label></div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" id="a-pr" name="przeglad" value="1"><label class="form-check-label" for="a-pr">Oznacz jako przejrzane dzisiaj</label></div>
    <button class="btn btn-primary" type="submit">Zapisz</button> <a class="btn btn-link" href="<?= h(route('rodo.activities')) ?>">Wróć</a>
  </div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>

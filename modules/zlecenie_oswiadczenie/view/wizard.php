<?php
/**
 * modules/zlecenie_oswiadczenie/view/wizard.php — krok kreatora oświadczeń.
 * Wymaga: $n (krok), $a (odpowiedzi), $errors, $base, $osw, $contract; opcjonalnie $back, $flash.
 * Bez JavaScriptu działa w pełni (pola zależne są zawsze widoczne); skrypt tylko ukrywa nieaktualne pola i podpowiada bank.
 */
$title = 'Oświadczenia do umowy zlecenia';
$v = fn(string $k) => (string)($a[$k] ?? '');
$on = fn(string $k) => !empty($a[$k]) && $a[$k] !== '0';
$err = fn(string $k) => $errors[$k] ?? '';
$inv = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$desc = function (string $k, string $hint = '') use ($errors): string {
    $ids = [];
    if ($hint !== '') $ids[] = 'h-' . $k;
    if (isset($errors[$k])) $ids[] = 'e-' . $k;
    return $ids ? ' aria-describedby="' . implode(' ', $ids) . '"' : '';
};
/** Pole tekstowe z etykietą, podpowiedzią i błędem. */
$text = function (string $k, string $label, bool $req, string $hint = '', string $attrs = '') use ($v, $err, $inv, $desc): string {
    return '<div class="fld"><label for="f-' . $k . '">' . h($label) . ($req ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>'
        . ($hint !== '' ? '<span class="hint" id="h-' . $k . '">' . h($hint) . '</span>' : '')
        . '<input type="text" id="f-' . $k . '" name="' . $k . '" value="' . h($v($k)) . '"' . ($req ? ' required aria-required="true"' : '') . $inv($k) . $desc($k, $hint) . ' ' . $attrs . '>'
        . ($err($k) !== '' ? '<span class="err" id="e-' . $k . '">' . h($err($k)) . '</span>' : '') . '</div>';
};
$check = function (string $k, string $label, string $hint = '') use ($on, $err, $inv, $desc): string {
    return '<div class="fld"><label class="opt" for="f-' . $k . '"><input type="checkbox" id="f-' . $k . '" name="' . $k . '" value="1"' . ($on($k) ? ' checked' : '') . $inv($k) . $desc($k, $hint) . '><span>' . h($label) . '</span></label>'
        . ($hint !== '' ? '<span class="hint sub" id="h-' . $k . '">' . h($hint) . '</span>' : '')
        . ($err($k) !== '' ? '<span class="err sub" id="e-' . $k . '">' . h($err($k)) . '</span>' : '') . '</div>';
};
$radio = function (string $k, string $legend, array $opts, string $hint = '') use ($v, $err, $desc): string {
    $h = '<fieldset id="f-' . $k . '"' . $desc($k, $hint) . '><legend>' . h($legend) . ' <span class="req" aria-hidden="true">*</span></legend>'
       . ($hint !== '' ? '<span class="hint" id="h-' . $k . '">' . h($hint) . '</span>' : '');
    foreach ($opts as $val => $lab) {
        $h .= '<label class="opt"><input type="radio" name="' . $k . '" value="' . h($val) . '"' . ($v($k) === (string)$val ? ' checked' : '') . ' required><span>' . h($lab) . '</span></label>';
    }
    return $h . ($err($k) !== '' ? '<span class="err" id="e-' . $k . '">' . h($err($k)) . '</span>' : '') . '</fieldset>';
};
[$_slug, $_stepTitle] = OswForm::STEPS[$n];
?>
<?php include __DIR__ . '/_head.php'; ?>
<div class="card">
  <p class="muted small" style="margin:0">Umowa zlecenia<?= !empty($contract['numer_umowy']) ? ' ' . h($contract['numer_umowy']) : ' — po złożeniu oświadczeń Fundacja przygotuje umowę' ?></p>
  <h1>Oświadczenia zleceniobiorcy</h1>
  <p class="muted" style="margin:0">Wypełnij dane online — przygotujemy z nich wydruk oświadczeń. Krok <?= $n ?> z <?= count(OswForm::STEPS) ?>.</p>
  <nav aria-label="Postęp wypełniania">
    <ol class="steps">
      <?php foreach (OswForm::STEPS as $_i => [, $_t]): ?>
      <li class="<?= $_i < $n ? 'done' : '' ?>"<?= $_i === $n ? ' aria-current="step"' : '' ?>>
        <?php if ($_i < $n): ?><a href="<?= h($base . '/krok/' . $_i) ?>"><?= $_i ?>. <?= h($_t) ?><span class="visually-hidden" style="position:absolute;left:-999px"> (ukończony)</span></a>
        <?php else: ?><?= $_i ?>. <?= h($_t) ?><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </nav>
</div>

<?php if ($errors): ?>
<div class="note note-danger" role="alert" id="bledy" tabindex="-1">
  <strong>Popraw <?= count($errors) === 1 ? 'zaznaczone pole' : 'zaznaczone pola (' . count($errors) . ')' ?>:</strong>
  <ul style="margin:6px 0 0">
    <?php foreach ($errors as $_k => $_m): ?><li><a href="#f-<?= h($_k) ?>"><?= h($_m) ?></a></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<form method="post" action="<?= h($base . '/krok/' . $n) ?>" class="card" novalidate>
  <?= Router::csrfField() ?>
  <h2><?= h($_stepTitle) ?></h2>
  <p class="small muted">Pola oznaczone <span class="req">*</span> są wymagane.</p>

<?php if ($n === 1): ?>
  <?= $text('nazwisko_imiona', 'Nazwisko i imiona', true, 'Najpierw nazwisko, potem wszystkie imiona — jak w dowodzie osobistym.', 'autocomplete="name"') ?>
  <div class="grid">
    <div class="fld">
      <label for="f-data_urodzenia">Data urodzenia <span class="req" aria-hidden="true">*</span></label>
      <input type="date" id="f-data_urodzenia" name="data_urodzenia" value="<?= h($v('data_urodzenia')) ?>" max="<?= date('Y-m-d') ?>" required aria-required="true" autocomplete="bday"<?= $inv('data_urodzenia') . $desc('data_urodzenia') ?>>
      <?php if ($err('data_urodzenia')): ?><span class="err" id="e-data_urodzenia"><?= h($err('data_urodzenia')) ?></span><?php endif; ?>
    </div>
    <?= $text('obywatelstwo', 'Obywatelstwo', true, '', 'autocomplete="off"') ?>
  </div>
  <?= $text('pesel', 'PESEL', false, '11 cyfr. Sprawdzimy cyfrę kontrolną i zgodność z datą urodzenia.', 'inputmode="numeric" maxlength="11" autocomplete="off" data-dep-off="brak_pesel"') ?>
  <?= $check('brak_pesel', 'Nie mam numeru PESEL') ?>
  <div data-dep="brak_pesel"><?= $text('dokument', 'Rodzaj i numer dokumentu tożsamości', false, 'Np. paszport AB1234567 — wpiszemy go w miejscu PESEL.') ?></div>
  <?= $radio('plec', 'Formy w oświadczeniu', ['k' => 'żeńskie — np. „zatrudniona”, „ukończyłam”', 'm' => 'męskie — np. „zatrudniony”, „ukończyłem”', 'obie' => 'nie skreślaj — zostaw obie formy'],
             'Wzór ma formy „zatrudniona/ny”. Na wydruku skreślimy formę, która Ciebie nie dotyczy.') ?>
  <?= $text('kod_zawodu', 'Kod zawodu', false, 'Nieobowiązkowe: 6 cyfr według klasyfikacji zawodów i specjalności. Jeśli nie znasz kodu, zostaw puste — uzupełni Fundacja.', 'inputmode="numeric" maxlength="6"') ?>

<?php elseif ($n === 2): ?>
  <p class="muted">Adres zamieszkania dla celów podatkowych — tam, gdzie mieszkasz, a nie adres zameldowania, jeśli jest inny.</p>
  <div class="grid">
    <?= $text('adr_kraj', 'Kraj', true, '', 'autocomplete="country-name"') ?>
    <div class="fld">
      <label for="f-adr_wojewodztwo">Województwo <span class="req" aria-hidden="true">*</span></label>
      <input type="text" id="f-adr_wojewodztwo" name="adr_wojewodztwo" list="woj" value="<?= h($v('adr_wojewodztwo')) ?>" required aria-required="true" autocomplete="address-level1"<?= $inv('adr_wojewodztwo') . $desc('adr_wojewodztwo') ?>>
      <datalist id="woj"><?php foreach (OswForm::WOJEWODZTWA as $_w): ?><option value="<?= h($_w) ?>"><?php endforeach; ?></datalist>
      <?php if ($err('adr_wojewodztwo')): ?><span class="err" id="e-adr_wojewodztwo"><?= h($err('adr_wojewodztwo')) ?></span><?php endif; ?>
    </div>
  </div>
  <div class="grid">
    <?= $text('adr_miejscowosc', 'Miejscowość', true, '', 'autocomplete="address-level2"') ?>
    <?= $text('adr_kod', 'Kod pocztowy', true, 'Format 00-000.', 'inputmode="numeric" maxlength="10" autocomplete="postal-code"') ?>
  </div>
  <div class="grid3">
    <?= $text('adr_ulica', 'Ulica', false, 'Puste, jeśli miejscowość nie ma ulic.', 'autocomplete="address-line1"') ?>
    <?= $text('adr_nr_domu', 'Nr domu', true) ?>
    <?= $text('adr_nr_mieszkania', 'Nr mieszkania', false) ?>
  </div>
  <div class="grid">
    <?= $text('adr_powiat', 'Powiat', true) ?>
    <?= $text('adr_gmina', 'Gmina', true) ?>
  </div>
  <?= $text('urzad_skarbowy', 'Urząd skarbowy', true, 'Właściwy dla miejsca zamieszkania, np. „Kraków-Krowodrza”. Na wydruku: „Urząd Skarbowy w …”.') ?>

<?php elseif ($n === 3): ?>
  <p class="muted">Zaznacz wszystko, co Ciebie dotyczy. Na tej podstawie Fundacja zgłosi umowę do ZUS.</p>
  <?= $radio('zatrudniony', '1. Czy jesteś jednocześnie zatrudniona/zatrudniony na podstawie umowy o pracę, zlecenia lub równorzędnej (u innego pracodawcy)?',
             ['tak' => 'Jestem', 'nie' => 'Nie jestem']) ?>
  <div data-dep="zatrudniony=tak" class="sub">
    <?= $radio('wynagrodzenie_min', 'Moje wynagrodzenie w kwocie brutto (z tamtej umowy) wynosi:',
               ['co_najmniej' => 'a) co najmniej minimalne wynagrodzenie', 'mniej' => 'b) mniej niż minimalne wynagrodzenie'],
               'Minimalne wynagrodzenie w ' . date('Y') . ' r. — sprawdź aktualną kwotę albo zapytaj swojego pracodawcę.') ?>
  </div>
  <?= $check('inny_tytul', '2. Jestem już ubezpieczona/ubezpieczony (ubezpieczenie emerytalne i rentowe) z innych tytułów niż w pkt 1 (np. działalność gospodarcza, KRUS)') ?>
  <div data-dep="inny_tytul" class="sub"><?= $text('inny_tytul_opis', 'Podaj tytuł ubezpieczenia', false, 'Np. działalność gospodarcza, KRUS.') ?></div>
  <?= $check('emeryt', '3. Jestem emerytem lub rencistą') ?>
  <?= $check('student', '4. Jestem uczniem szkoły ponadpodstawowej lub studentem i nie ukończyłam/ukończyłem 26 lat', 'Do weryfikacji Fundacja może poprosić o legitymację.') ?>
  <?= $check('bez_tytulu', '5. Nie pracuję, nie jestem zarejestrowana/zarejestrowany jako osoba bezrobotna i nie jestem objęta/objęty ubezpieczeniem społecznym z żadnego tytułu') ?>

<?php elseif ($n === 4): ?>
  <?php $_exempt = OswForm::studentExempt($a); $_mand = OswForm::mandatory($a); ?>
  <div class="note note-info" role="note"><?= h(OswForm::zusHint($a)) ?> <span class="small">Ostateczne zgłoszenie do ZUS przygotuje Fundacja na podstawie oświadczenia.</span></div>
  <p>Zgodnie z powyższym oświadczeniem z tytułu wykonywania tej umowy:</p>
  <?php if ($_exempt): ?>
    <p class="muted">Nie możesz wybrać ubezpieczeń dobrowolnych — przejdź dalej.</p>
  <?php else: ?>
    <?php if ($_mand): ?>
      <p class="muted small">Ubezpieczenie emerytalne i rentowe jest w Twojej sytuacji obowiązkowe, dlatego pierwszej opcji („Chcę podlegać dobrowolnemu ubezpieczeniu emerytalnemu i rentowemu”) nie zaznaczasz.</p>
    <?php else: ?>
      <?= $check('dobr_emerytalne', 'Chcę podlegać dobrowolnemu ubezpieczeniu emerytalnemu i rentowemu', 'Składki naliczane od wynagrodzenia z tej umowy — zwiększają przyszłą emeryturę, ale zmniejszają wypłatę.') ?>
    <?php endif; ?>
    <?= $check('dobr_chorobowe', 'Chcę być objęta/objęty dobrowolnym ubezpieczeniem chorobowym', 'Daje prawo do zasiłku chorobowego. Możliwe tylko razem z ubezpieczeniem emerytalnym i rentowym z tej umowy.') ?>
  <?php endif; ?>

<?php elseif ($n === 5): ?>
  <p class="muted">Rachunek do wypłaty wynagrodzenia. Z tych danych przygotujemy „Oświadczenie o numerze rachunku bankowego” (dokument ZLB).</p>
  <?= $text('nrb', 'Numer rachunku', true, '26 cyfr (możesz wpisać ze spacjami). Rachunek zagraniczny wpisz w formacie IBAN, np. DE89…', 'inputmode="text" autocomplete="off" maxlength="42"') ?>
  <?= $text('bank_nazwa', 'Bank prowadzący rachunek', true, 'Podpowiemy nazwę po numerze rachunku — sprawdź ją.') ?>
  <?= $text('posiadacz', 'Imię i nazwisko posiadacza rachunku', true) ?>
  <?= $text('zlb_miejscowosc', 'Miejscowość złożenia oświadczenia', true) ?>
  <fieldset>
    <legend>Treść oświadczenia (ZLB)</legend>
    <?php foreach (OswiadczeniePdf::ZLB_TEXT as $_t): ?><p class="small" style="margin:0 0 8px"><?= h($_t) ?></p><?php endforeach; ?>
  </fieldset>
  <?= $check('zlb_potwierdzam', 'Potwierdzam powyższą treść oświadczenia o numerze rachunku bankowego') ?>

<?php elseif ($n === 6): ?>
  <p>Sprawdź dane. Po złożeniu przygotujemy dwa dokumenty do wydruku i podpisu.</p>
  <?php foreach (OswForm::summary($a) as $_s => $_rows): ?>
  <section aria-labelledby="sum-<?= $_s ?>" style="margin-bottom:16px">
    <h3 id="sum-<?= $_s ?>" style="font-size:1.02rem;margin:0 0 6px;display:flex;justify-content:space-between;gap:8px">
      <span><?= $_s ?>. <?= h(OswForm::STEPS[$_s][1]) ?></span>
      <a class="small" href="<?= h($base . '/krok/' . $_s) ?>">Zmień<span style="position:absolute;left:-999px"> — <?= h(OswForm::STEPS[$_s][1]) ?></span></a>
    </h3>
    <dl class="sum"><?php foreach ($_rows as [$_l, $_val]): ?><dt><?= h($_l) ?></dt><dd><?= h($_val !== '' ? $_val : '—') ?></dd><?php endforeach; ?></dl>
  </section>
  <?php endforeach; ?>
  <?= $check('oswiadczam', 'Oświadczam, że podane dane są zgodne z prawdą, a o każdej zmianie (np. zatrudnienia, statusu studenta, rachunku) niezwłocznie powiadomię Fundację.') ?>
<?php endif; ?>

  <div class="btns">
    <?php if ($n > 1): ?><button type="submit" name="wstecz" value="1" class="btn btn-sec" formnovalidate>← Wstecz</button><?php else: ?><span></span><?php endif; ?>
    <button type="submit" class="btn"><?= $n === 6 ? 'Złóż oświadczenia' : 'Zapisz i dalej →' ?></button>
  </div>
  <p class="small muted" style="margin:10px 0 0">Każdy krok zapisuje się od razu — możesz przerwać i wrócić później.</p>
</form>

<script>
(function () {
  // Pola zależne: data-dep="pole" (checkbox zaznaczony) albo data-dep="pole=wartość" (radio); bez JS wszystko widoczne.
  function val(name) {
    var els = document.getElementsByName(name), v = '';
    for (var i = 0; i < els.length; i++) {
      if (els[i].type === 'checkbox') v = els[i].checked ? '1' : '';
      else if (els[i].type === 'radio') { if (els[i].checked) v = els[i].value; }
      else v = els[i].value;
    }
    return v;
  }
  function sync() {
    document.querySelectorAll('[data-dep]').forEach(function (box) {
      var d = box.getAttribute('data-dep').split('='), show = d.length > 1 ? val(d[0]) === d[1] : val(d[0]) !== '';
      box.hidden = !show;
    });
    document.querySelectorAll('[data-dep-off]').forEach(function (inp) {
      var off = val(inp.getAttribute('data-dep-off')) !== '';
      inp.closest('.fld').hidden = off;
    });
  }
  document.addEventListener('change', sync);
  sync();
  var err = document.getElementById('bledy');
  if (err) err.focus();
  // Podpowiedź nazwy banku po numerze rozliczeniowym (cyfry 3–6 NRB)
  var nrb = document.getElementById('f-nrb'), bank = document.getElementById('f-bank_nazwa');
  if (nrb && bank) {
    var banks = <?= json_encode(OswForm::BANKI, JSON_UNESCAPED_UNICODE) ?>;
    nrb.addEventListener('input', function () {
      var d = nrb.value.replace(/^PL/i, '').replace(/\D/g, '');
      if (d.length >= 6 && banks[d.substr(2, 4)] && (bank.value === '' || bank.dataset.auto === '1')) { bank.value = banks[d.substr(2, 4)]; bank.dataset.auto = '1'; }
    });
    bank.addEventListener('input', function () { bank.dataset.auto = ''; });
  }
})();
</script>
<?php include __DIR__ . '/_foot.php'; ?>

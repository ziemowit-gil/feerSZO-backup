<?php
/** Rejestracja wniosku osoby. */
$PAGE_TITLE = 'Nowy wniosek — RODO'; $active = 'rodo.requests';
include __DIR__ . '/_head.php';
?>
<form method="post" action="<?= h(route('rodo.request.create')) ?>" class="row g-3" style="max-width:48rem"><?= csrf_field() ?>
  <div class="col-md-6"><label class="form-label" for="typ">Rodzaj wniosku *</label><select id="typ" name="typ" class="form-select"><?php foreach (RODO_REQUEST_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label" for="dw">Data wpłynięcia</label><input id="dw" type="date" name="data_wplywu" class="form-control" value="<?= date('Y-m-d') ?>"></div>
  <div class="col-md-3"><label class="form-label" for="kan">Kanał</label><select id="kan" name="kanal" class="form-select"><option value="email">e-mail</option><option value="poczta">poczta</option><option value="osobiscie">osobiście</option><option value="telefon">telefon</option></select></div>
  <div class="col-12"><label class="form-label" for="wn">Imię i nazwisko wnioskodawcy *</label><input id="wn" name="wnioskodawca" class="form-control" required></div>
  <div class="col-md-6"><label class="form-label" for="em">E-mail</label><input id="em" type="email" name="email" class="form-control" aria-describedby="idh"></div>
  <div class="col-md-6"><label class="form-label" for="pe">PESEL</label><input id="pe" name="pesel" class="form-control" inputmode="numeric" maxlength="14" autocomplete="off" aria-describedby="idh"></div>
  <div class="col-12 form-text" id="idh">Wystarczy e-mail albo PESEL — po nich system szuka danych osoby. Dane wniosku są zapisywane w bazie zaszyfrowane.</div>
  <div class="col-12"><label class="form-label" for="op">Treść wniosku</label><textarea id="op" name="opis" class="form-control" rows="4"></textarea></div>
  <div class="col-12"><button class="btn btn-primary" type="submit">Zarejestruj</button> <a class="btn btn-link" href="<?= h(route('rodo.requests')) ?>">Anuluj</a></div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>

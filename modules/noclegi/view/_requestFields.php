<?php
/**
 * Pola formularza zgłoszenia (współdzielone: moduł i panel dydaktyka).
 * Zmienne: $f (wartości), $errors, $budgets, $showOther (Logistyk może zgłosić osobę bez konta).
 */
$_v = fn(string $k, $d = '') => $f[$k] ?? $d;
$_err = function (string $k) use ($errors) { return isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="e-' . h($k) . '">' . h($errors[$k]) . '</div>' : ''; };
$_aria = fn(string $k) => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="e-' . h($k) . '"' : '';
$_prefs = array_filter(explode(',', (string)$_v('preferences', ''))) ?: (array)($f['preferences'] ?? []);
if (!empty($showOther)): ?>
<div class="form-check mb-3">
  <input class="form-check-input" type="checkbox" id="for_other" name="for_other" value="1" <?= !empty($f['for_other']) ? 'checked' : '' ?>>
  <label class="form-check-label" for="for_other">Zgłaszam inną osobę (np. wolontariusza bez konta w SZO) — zgłoszenie nie trafi na moje konto</label>
</div>
<?php endif; ?>
<fieldset>
  <legend>Osoba i cel</legend>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="person_name">Imię i nazwisko *</label>
      <input class="form-control<?= isset($errors['person_name']) ? ' is-invalid' : '' ?>" id="person_name" name="person_name" required maxlength="120" value="<?= h($_v('person_name')) ?>"<?= $_aria('person_name') ?>><?= $_err('person_name') ?></div>
    <div class="col-md-6"><label class="form-label" for="person_role">Rola osoby *</label>
      <select class="form-select" id="person_role" name="person_role" required<?= $_aria('person_role') ?>>
        <?php foreach (noclRoleOsoby() as $c => $l): ?><option value="<?= h($c) ?>" <?= $_v('person_role') === $c ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
      </select><?= $_err('person_role') ?></div>
    <div class="col-md-6"><label class="form-label" for="person_email">E-mail (do instrukcji zameldowania)</label>
      <input class="form-control<?= isset($errors['person_email']) ? ' is-invalid' : '' ?>" type="email" id="person_email" name="person_email" maxlength="160" value="<?= h($_v('person_email')) ?>"<?= $_aria('person_email') ?>><?= $_err('person_email') ?></div>
    <div class="col-md-6"><label class="form-label" for="person_phone">Telefon</label>
      <input class="form-control" type="tel" id="person_phone" name="person_phone" maxlength="40" value="<?= h($_v('person_phone')) ?>"></div>
    <div class="col-md-8"><label class="form-label" for="purpose">Cel pobytu (szkolenie, wydarzenie, projekt)</label>
      <input class="form-control" id="purpose" name="purpose" maxlength="200" value="<?= h($_v('purpose')) ?>"></div>
    <div class="col-md-4"><label class="form-label" for="city">Miejscowość</label>
      <input class="form-control" id="city" name="city" maxlength="80" value="<?= h($_v('city')) ?>"></div>
  </div>
</fieldset>
<fieldset>
  <legend>Termin</legend>
  <div class="row g-3">
    <div class="col-sm-6"><label class="form-label" for="date_from">Przyjazd (pierwsza noc) *</label>
      <input class="form-control<?= isset($errors['date_from']) ? ' is-invalid' : '' ?>" type="date" id="date_from" name="date_from" required value="<?= h($_v('date_from')) ?>"<?= $_aria('date_from') ?>><?= $_err('date_from') ?></div>
    <div class="col-sm-6"><label class="form-label" for="date_to">Wyjazd (wymeldowanie) *</label>
      <input class="form-control<?= isset($errors['date_to']) ? ' is-invalid' : '' ?>" type="date" id="date_to" name="date_to" required value="<?= h($_v('date_to')) ?>"<?= $_aria('date_to') ?>><?= $_err('date_to') ?></div>
  </div>
</fieldset>
<fieldset>
  <legend>Preferencje i dostępność</legend>
  <div class="row g-3">
    <div class="col-md-6"><label class="form-label" for="room_type_pref">Preferowany typ pokoju</label>
      <select class="form-select" id="room_type_pref" name="room_type_pref"><option value="">bez preferencji</option>
        <?php foreach (noclTypyPokoi() as $c => $l): ?><option value="<?= h($c) ?>" <?= $_v('room_type_pref') === $c ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label" for="roommate_pref">Chcę mieszkać z (opcjonalnie)</label>
      <input class="form-control" id="roommate_pref" name="roommate_pref" maxlength="160" value="<?= h($_v('roommate_pref')) ?>" aria-describedby="rm-h"><div class="form-text" id="rm-h">Imię i nazwisko — uwzględnimy, jeśli to możliwe.</div></div>
  </div>
  <div class="mt-3" role="group" aria-label="Preferencje">
    <?php foreach (noclPreferencje() as $c => $l): ?>
    <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="pf-<?= h($c) ?>" name="preferences[]" value="<?= h($c) ?>" <?= in_array($c, $_prefs, true) ? 'checked' : '' ?>><label class="form-check-label" for="pf-<?= h($c) ?>"><?= h($l) ?></label></div>
    <?php endforeach; ?>
  </div>
  <div class="form-check mt-3"><input class="form-check-input" type="checkbox" id="needs_accessible" name="needs_accessible" value="1" <?= !empty($f['needs_accessible']) ? 'checked' : '' ?> aria-describedby="na-h"><label class="form-check-label" for="needs_accessible">Potrzebuję pokoju dostępnego dla osób z niepełnosprawnościami</label>
    <div class="form-text" id="na-h">Przydzielimy wyłącznie pokój oznaczony jako dostępny.</div></div>
  <div class="mt-2"><label class="form-label" for="accessibility_notes">Opis potrzeb (opcjonalnie)</label>
    <textarea class="form-control" id="accessibility_notes" name="accessibility_notes" rows="2" maxlength="600" aria-describedby="an-h"><?= h($_v('accessibility_notes')) ?></textarea>
    <div class="form-text" id="an-h">Dane wrażliwe — widzą je tylko Ty, Koordynator Projektu i Logistyk. Nie trafiają na listy dla obiektów.</div></div>
</fieldset>
<?php if (!empty($budgets)): ?>
<fieldset>
  <legend>Koszty</legend>
  <label class="form-label" for="budget_id">Projekt / budżet (jeśli znany)</label>
  <select class="form-select" id="budget_id" name="budget_id"<?= $_aria('budget_id') ?>><option value="">nie wiem / do ustalenia</option>
    <?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$_v('budget_id') === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['code'] . ' — ' . $b['name']) ?></option><?php endforeach; ?>
  </select><?= $_err('budget_id') ?>
</fieldset>
<?php endif; ?>

<?php
/**
 * Sekcja „Sprzęt i licencje” karty kursanta. Zmienne: $clientId, $courseCtx, $actor, $equipment, $licenses, $licenseOptions,
 * $edit_equip (edytowany wiersz albo null), $ef (wartości formularza), $errors, $open.
 */
$_form_open = $edit_equip || $open === 'sprzet';
?>
<section id="sprzet" aria-labelledby="h-sprzet" class="card border-0 shadow-sm mb-4">
  <div class="card-header"><h2 class="h5 mb-0" id="h-sprzet"><i class="bi bi-laptop text-primary me-2" aria-hidden="true"></i>Sprzęt i licencje</h2></div>
  <div class="card-body">
    <h3 class="h6">Sprzęt kursanta</h3>
    <?php if (!$equipment): ?>
      <p class="text-body-secondary small">Brak zapisanego sprzętu.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle" style="font-size:.88rem">
        <caption class="visually-hidden">Sprzęt kursanta: rodzaj, model, własność, daty wypożyczenia i zwrotu, powiązana licencja</caption>
        <thead class="table-light"><tr>
          <th scope="col">Rodzaj</th><th scope="col">Model / numer seryjny</th><th scope="col">Własność</th>
          <th scope="col">Wypożyczono → zwrócono</th><th scope="col">Licencja</th><th scope="col" class="text-end">Akcje</th>
        </tr></thead>
        <tbody>
        <?php foreach ($equipment as $q): $returned = !empty($q['returned_at']); $label = (TiDydaktykaManager::EQUIPMENT_KINDS[$q['kind']] ?? $q['kind']) . ($q['model'] !== '' ? ' ' . $q['model'] : ''); ?>
          <tr>
            <th scope="row" class="fw-semibold"><?= h(TiDydaktykaManager::EQUIPMENT_KINDS[$q['kind']] ?? $q['kind']) ?>
              <?php if ($q['note'] !== ''): ?><div class="small fw-normal text-body-secondary"><?= h($q['note']) ?></div><?php endif; ?></th>
            <td><?= $q['model'] !== '' ? h($q['model']) : '—' ?><?= $q['serial'] !== '' ? '<div class="small text-body-secondary">nr ' . h($q['serial']) . '</div>' : '' ?></td>
            <td><?= h(TiDydaktykaManager::OWNERSHIP[$q['ownership']] ?? $q['ownership']) ?></td>
            <td class="text-nowrap"><?= h(dk_date($q['lent_at'])) ?> → <?= $returned ? h(dk_date($q['returned_at'])) : ($q['ownership'] === 'loan' ? dk_badge('bi-box-arrow-up-right', 'dk-warn', 'u kursanta') : '—') ?></td>
            <td><?= $q['license_name'] !== null ? h($q['license_name']) : '—' ?></td>
            <td class="text-end text-nowrap">
              <a class="btn btn-sm btn-outline-secondary" href="<?= h(dk_url($clientId, $courseCtx, ['equip' => $q['id']], '#sprzet-form')) ?>" aria-label="Edytuj sprzęt: <?= h($label) ?>"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edytuj</span></a>
              <?php if (!$returned): ?>
              <form method="post" class="d-inline" action="<?= h(dk_url($clientId, $courseCtx, [], '#sprzet')) ?>">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="return_equipment">
                <input type="hidden" name="client" value="<?= (int)$clientId ?>"><input type="hidden" name="equipment_id" value="<?= (int)$q['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-success" aria-label="Oznacz jako zwrócony: <?= h($label) ?>"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i><span class="visually-hidden">Zwrócony</span></button>
              </form>
              <?php endif; ?>
              <form method="post" class="d-inline" action="<?= h(dk_url($clientId, $courseCtx, [], '#sprzet')) ?>" onsubmit="return confirm('Usunąć ten wpis o sprzęcie?')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="delete_equipment">
                <input type="hidden" name="client" value="<?= (int)$clientId ?>"><input type="hidden" name="equipment_id" value="<?= (int)$q['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Usuń wpis o sprzęcie: <?= h($label) ?>"><i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Usuń</span></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <details id="sprzet-form" class="mb-4"<?= $_form_open ? ' open' : '' ?>>
      <summary class="fw-semibold text-primary" style="cursor:pointer"><?= $edit_equip ? 'Edytuj sprzęt' : 'Dodaj sprzęt' ?></summary>
      <form method="post" class="row g-2 mt-1" action="<?= h(dk_url($clientId, $courseCtx, [], '#sprzet')) ?>">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save_equipment">
        <input type="hidden" name="client" value="<?= (int)$clientId ?>">
        <?php if ($edit_equip): ?><input type="hidden" name="equipment_id" value="<?= (int)$edit_equip['id'] ?>"><?php endif; ?>
        <div class="col-md-3">
          <label class="form-label fw-semibold" for="eq-kind">Rodzaj <span class="text-danger" aria-hidden="true">*</span></label>
          <select class="form-select" id="eq-kind" name="kind" required>
            <option value="">— wybierz —</option>
            <?php foreach (TiDydaktykaManager::EQUIPMENT_KINDS as $k => $l): ?><option value="<?= h($k) ?>"<?= $ef['kind'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3"><label class="form-label" for="eq-model">Model</label><input type="text" class="form-control" id="eq-model" name="model" value="<?= h($ef['model']) ?>" maxlength="190"></div>
        <div class="col-md-3"><label class="form-label" for="eq-serial">Numer seryjny</label><input type="text" class="form-control" id="eq-serial" name="serial" value="<?= h($ef['serial']) ?>" maxlength="190"></div>
        <div class="col-md-3">
          <label class="form-label" for="eq-own">Własność</label>
          <select class="form-select" id="eq-own" name="ownership"><?php foreach (TiDydaktykaManager::OWNERSHIP as $k => $l): ?><option value="<?= h($k) ?>"<?= $ef['ownership'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-6 col-md-3"><label class="form-label" for="eq-lent">Wypożyczono</label><input type="date" class="form-control" id="eq-lent" name="lent_at" value="<?= h($ef['lent_at']) ?>"></div>
        <div class="col-6 col-md-3"><label class="form-label" for="eq-ret">Zwrócono</label><input type="date" class="form-control" id="eq-ret" name="returned_at" value="<?= h($ef['returned_at']) ?>"></div>
        <div class="col-md-3">
          <label class="form-label" for="eq-lic">Powiązana licencja</label>
          <select class="form-select" id="eq-lic" name="license_assignment_id">
            <option value="0">— brak —</option>
            <?php foreach ($licenseOptions as $lo): ?><option value="<?= (int)$lo['id'] ?>"<?= (int)$ef['license_assignment_id'] === (int)$lo['id'] ? ' selected' : '' ?>><?= h($lo['name']) ?><?= !empty($lo['expires_at']) ? ' (do ' . h(dk_date($lo['expires_at'])) . ')' : '' ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3"><label class="form-label" for="eq-note">Uwagi</label><input type="text" class="form-control" id="eq-note" name="note" value="<?= h($ef['note']) ?>" maxlength="500"></div>
        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1" aria-hidden="true"></i><?= $edit_equip ? 'Zapisz zmiany' : 'Dodaj sprzęt' ?></button>
          <?php if ($edit_equip): ?><a class="btn btn-outline-secondary" href="<?= h(dk_url($clientId, $courseCtx, [], '#sprzet')) ?>">Anuluj</a><?php endif; ?>
        </div>
      </form>
    </details>

    <h3 class="h6">Licencje na oprogramowanie</h3>
    <?php if (!$licenses): ?>
      <p class="text-body-secondary small mb-1">Kursant nie ma przypisanych licencji.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-1" style="font-size:.88rem">
        <caption class="visually-hidden">Licencje na oprogramowanie przypisane kursantowi: status, daty<?= !empty($actor['staff']) ? ' i forma rozliczenia' : '' ?></caption>
        <thead class="table-light"><tr>
          <th scope="col">Oprogramowanie</th><th scope="col">Status</th><th scope="col">Wydano</th><th scope="col">Ważna do</th>
          <?php if (!empty($actor['staff'])): ?><th scope="col">Rozliczenie</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($licenses as $l): ?>
          <tr>
            <th scope="row" class="fw-semibold"><?= h($l['license_name']) ?><?= $l['vendor'] !== '' ? '<div class="small fw-normal text-body-secondary">' . h($l['vendor']) . '</div>' : '' ?></th>
            <td><?= dk_lic_badge($l['status_key']) ?></td>
            <td><?= h(dk_date($l['issued'])) ?></td>
            <td><?= !empty($l['expires_at']) ? h(dk_date($l['expires_at'])) : 'bezterminowo' ?></td>
            <?php if (!empty($actor['staff'])): ?><td><?= $l['billing_form'] !== '' ? '<span class="badge dk-b dk-muted">' . h($l['billing_form']) . '</span>' : '<span class="text-body-secondary">brak formy</span>' ?></td><?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
    <p class="small mb-0">
      <?php if (!empty($actor['staff'])): ?>
        <a href="licencjeKursantow.php?client_id=<?= (int)$clientId ?>"><i class="bi bi-key me-1" aria-hidden="true"></i>Zarządzaj licencjami tego kursanta</a>
      <?php else: ?>
        <span class="text-body-secondary">Licencje przypisuje kierownik.</span>
      <?php endif; ?>
    </p>
  </div>
</section>

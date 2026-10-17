<?php
/**
 * modules/ti_duty/ui/dutyAdmin.php — DK: zarządzanie dyżurami kierownika / Zarządu (formularz + listy).
 * Używane przez stronę SZO modules/ti_duty/index.php. Publicznie: modules/ti_duty/public/index.php (/dyzury)
 * oraz zakładka DK w planie TI (planPubliczny.php?k=dk) — jedno źródło danych (k30_ti_duty).
 */

/**
 * Obsługa POST (duty_save / duty_delete / duty_public). Zwraca [typ flash, komunikat, czy wrócić do edycji (id)] albo null.
 */
function tiDutyAdminHandlePost(array $post, int $uid): ?array {
    $op = (string)($post['_op'] ?? '');
    if ($op === 'duty_public') {
        org_setting_set('ti_duty_public', !empty($post['on']) ? '1' : '0');
        return ['success', !empty($post['on']) ? 'Dyżury opublikowane (strona „Dyżury” i zakładka DK w planie TI).' : 'Dyżury ukryte publicznie.', 0];
    }
    if ($op === 'duty_delete') {
        tiDutyDelete((int)($post['id'] ?? 0));
        return ['warning', 'Dyżur usunięty.', 0];
    }
    if ($op === 'duty_save') {
        [$d, $err] = tiDutyValidate($post);
        $id = (int)($post['id'] ?? 0);
        if ($err !== '') return ['danger', $err, $id ?: -1];
        tiDutySave($d, $uid, $id ?: null);
        return ['success', $id ? 'Dyżur zapisany.' : 'Dyżur dodany.', 0];
    }
    return null;
}

/** Widok zarządzania. $selfUrl — adres tej strony (bez zapytania), $tokenHtml — ukryte pole CSRF. */
function tiDutyAdminRender(string $selfUrl, string $tokenHtml, string $publicUrl, string $planDkUrl): void {
  $duties = tiDutyAll(); $dutyPub = tiDutyPublic();
  $ed = isset($_GET['edit']) ? tiDutyGet((int)$_GET['edit']) : null;
  $ev = fn(string $k, $def = '') => h((string)($ed[$k] ?? $def)); ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3 p-2 border rounded bg-body-tertiary">
  <span class="small">Dyżury publicznie (strona „Dyżury” i zakładka DK w planie TI): <?= $dutyPub ? '<strong>opublikowane</strong>' : 'ukryte' ?></span>
  <form method="post" class="d-inline">
    <?= $tokenHtml ?><input type="hidden" name="_op" value="duty_public"><input type="hidden" name="on" value="<?= $dutyPub ? '' : '1' ?>">
    <button class="btn btn-sm <?= $dutyPub ? 'btn-outline-danger' : 'btn-success' ?>"><?= $dutyPub ? 'Ukryj publicznie' : 'Opublikuj' ?></button>
  </form>
  <?php if ($dutyPub): ?>
  <a class="btn btn-sm btn-outline-primary" href="<?= h($publicUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Strona „Dyżury”</a>
  <a class="btn btn-sm btn-outline-secondary" href="<?= h($planDkUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-calendar3 me-1" aria-hidden="true"></i>DK w planie TI</a>
  <?php endif; ?>
  <span class="small text-body-secondary">Publicznie: inicjały + funkcja + kontakt dyżurowy; pełne imię i nazwisko tylko w widoku pełnym (po haśle).</span>
</div>

<section class="pl-card is-on" id="dk-form" aria-labelledby="dk-form-h">
  <div class="pl-head"><h2 class="h6 mb-0 fw-bold" id="dk-form-h"><?= $ed ? 'Edycja dyżuru' : 'Dodaj dyżur' ?></h2>
    <?php if ($ed): ?><a class="small ms-auto" href="<?= h($selfUrl) ?>">anuluj edycję</a><?php endif; ?></div>
  <div class="pl-body">
    <form method="post" class="row g-2 align-items-end">
      <?= $tokenHtml ?><input type="hidden" name="_op" value="duty_save"><input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
      <fieldset class="col-12 mb-1"><legend class="form-label small mb-0">Rodzaj</legend>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="kind" id="dk-w" value="weekly" <?= ($ed['kind'] ?? 'weekly') === 'weekly' ? 'checked' : '' ?>><label class="form-check-label small" for="dk-w">stały (co tydzień)</label></div>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="kind" id="dk-d" value="date" <?= ($ed['kind'] ?? '') === 'date' ? 'checked' : '' ?>><label class="form-check-label small" for="dk-d">w konkretnym dniu (zastępuje stałe dyżury tego dnia)</label></div>
      </fieldset>
      <div class="col-6 col-md-2"><label class="form-label small mb-0" for="dk-dow">Dzień tygodnia (stały)</label>
        <select class="form-select form-select-sm" id="dk-dow" name="dow"><?php foreach (TI_DAYS_PL_FULL as $n => $lbl): ?><option value="<?= $n ?>" <?= (int)($ed['dow'] ?? 1) === $n ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0" for="dk-vf">obowiązuje od</label><input type="date" class="form-control form-control-sm" id="dk-vf" name="valid_from" value="<?= $ev('valid_from') ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0" for="dk-vt">do</label><input type="date" class="form-control form-control-sm" id="dk-vt" name="valid_to" value="<?= $ev('valid_to') ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0" for="dk-date">Data (konkretny dzień)</label><input type="date" class="form-control form-control-sm" id="dk-date" name="duty_date" value="<?= $ev('duty_date') ?>"></div>
      <div class="col-6 col-md-2 d-flex align-items-end"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="dk-cn" name="is_cancelled" value="1" <?= (int)($ed['is_cancelled'] ?? 0) ? 'checked' : '' ?>><label class="form-check-label small" for="dk-cn">brak dyżuru w tym dniu</label></div></div>
      <div class="w-100"></div>
      <div class="col-6 col-md-1"><label class="form-label small mb-0" for="dk-tf">od</label><input type="time" class="form-control form-control-sm" id="dk-tf" name="time_from" value="<?= $ev('time_from') ?>"></div>
      <div class="col-6 col-md-1"><label class="form-label small mb-0" for="dk-tt">do</label><input type="time" class="form-control form-control-sm" id="dk-tt" name="time_to" value="<?= $ev('time_to') ?>"></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="dk-pn">Osoba (imię i nazwisko)</label><input type="text" class="form-control form-control-sm" id="dk-pn" name="person_name" maxlength="120" value="<?= $ev('person_name') ?>"></div>
      <div class="col-md-2"><label class="form-label small mb-0" for="dk-rl">Funkcja</label><input type="text" class="form-control form-control-sm" id="dk-rl" name="role_label" maxlength="80" list="dk-roles" value="<?= $ev('role_label') ?>">
        <datalist id="dk-roles"><?php foreach (TI_DUTY_ROLES as $r): ?><option value="<?= h($r) ?>"><?php endforeach; ?></datalist></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="dk-pl">Miejsce / forma</label><input type="text" class="form-control form-control-sm" id="dk-pl" name="place" maxlength="200" value="<?= $ev('place') ?>" placeholder="np. biuro, pok. 12 / telefonicznie / Teams"></div>
      <div class="col-md-2"><label class="form-label small mb-0" for="dk-ph">Telefon dyżurowy</label><input type="tel" class="form-control form-control-sm" id="dk-ph" name="contact_phone" maxlength="40" value="<?= $ev('contact_phone') ?>"></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="dk-em">E-mail dyżurowy</label><input type="email" class="form-control form-control-sm" id="dk-em" name="contact_email" maxlength="120" value="<?= $ev('contact_email') ?>"></div>
      <div class="col-md-5"><label class="form-label small mb-0" for="dk-nt">Uwaga (opcjonalna)</label><textarea class="form-control form-control-sm" id="dk-nt" name="note" maxlength="300" rows="2"><?= $ev('note') ?></textarea>
        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 mt-1" data-list-for="dk-nt" title="Zamień zaznaczone wiersze na listę punktowaną (albo z powrotem)"><i class="bi bi-list-ul me-1" aria-hidden="true"></i>• Lista</button><span class="form-text small ms-2">wiersze zaczynające się od „-” pokażą się jako lista</span>
        <script>
        document.addEventListener('click', function (e) {
          var lb = e.target.closest('[data-list-for]'); if (!lb) return;
          var ta = document.getElementById(lb.getAttribute('data-list-for')); if (!ta) return;
          var v = ta.value, s = ta.selectionStart, en = ta.selectionEnd;
          var a = v.lastIndexOf('\n', s - 1) + 1, z = v.indexOf('\n', en); if (z < 0) z = v.length;
          var lines = v.slice(a, z).split('\n');
          var all = lines.every(function (l) { return /^\s*[-–•*]\s/.test(l) || l.trim() === ''; });
          lines = lines.map(function (l) { if (l.trim() === '') return l; return all ? l.replace(/^\s*[-–•*]\s?/, '') : '- ' + l.replace(/^\s*[-–•*]\s?/, ''); });
          var mid = lines.join('\n'); ta.value = v.slice(0, a) + mid + v.slice(z); ta.focus(); ta.setSelectionRange(a, a + mid.length);
        });
        </script></div>
      <div class="col-auto"><button class="btn btn-sm btn-primary"><?= $ed ? 'Zapisz' : 'Dodaj dyżur' ?></button></div>
    </form>
    <p class="small text-body-secondary mb-0 mt-2">Kontakt dyżurowy wpisuj służbowy (nie prywatny) — trafia na stronę publiczną.</p>
  </div>
</section>

<?php foreach (['weekly' => 'Dyżury stałe (co tydzień)', 'date' => 'Dyżury w konkretnych dniach'] as $kk => $kl): $rows = array_values(array_filter($duties, fn($r) => $r['kind'] === $kk)); ?>
<h2 class="h6 fw-bold mt-3"><?= h($kl) ?> (<?= count($rows) ?>)</h2>
<?php if (!$rows): ?><p class="small text-body-secondary">Brak.</p><?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead><tr><th scope="col"><?= $kk === 'weekly' ? 'Dzień' : 'Data' ?></th><th scope="col">Godziny</th><th scope="col">Dyżurny</th><th scope="col">Miejsce</th><th scope="col">Kontakt</th><th scope="col"><?= $kk === 'weekly' ? 'Obowiązuje' : 'Uwaga' ?></th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $past = $kk === 'date' ? (string)$r['duty_date'] < date('Y-m-d') : (!empty($r['valid_to']) && (string)$r['valid_to'] < date('Y-m-d')); ?>
  <tr<?= $past ? ' class="text-body-secondary"' : '' ?>>
    <td><?= $kk === 'weekly' ? h(TI_DAYS_PL_FULL[(int)$r['dow']] ?? '') : h(date('d.m.Y', strtotime((string)$r['duty_date'])) . ' (' . (TI_DAYS_PL_FULL[(int)date('N', strtotime((string)$r['duty_date']))] ?? '') . ')') ?></td>
    <td><?= (int)$r['is_cancelled'] ? '<span class="badge text-bg-danger">brak dyżuru</span>' : h($r['time_from'] . '–' . $r['time_to']) ?></td>
    <td><?= h($r['person_name']) ?><?= $r['role_label'] !== '' ? '<br><span class="small text-body-secondary">' . h($r['role_label']) . '</span>' : '' ?></td>
    <td><?= h($r['place']) ?></td>
    <td class="small"><?= h(implode(' · ', array_filter([(string)$r['contact_phone'], (string)$r['contact_email']]))) ?></td>
    <td class="small"><?= $kk === 'weekly' ? h(($r['valid_from'] ? 'od ' . date('d.m.Y', strtotime((string)$r['valid_from'])) . ' ' : '') . ($r['valid_to'] ? 'do ' . date('d.m.Y', strtotime((string)$r['valid_to'])) : '') ?: 'bez ograniczeń') : h($r['note']) ?><?= $past ? ' <span class="badge text-bg-secondary">minął</span>' : '' ?></td>
    <td class="text-nowrap">
      <a class="btn btn-sm btn-outline-secondary" href="<?= h($selfUrl) ?>?edit=<?= (int)$r['id'] ?>#dk-form">Edytuj<span class="visually-hidden"> dyżur <?= h($r['person_name']) ?></span></a>
      <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten dyżur?')">
        <?= $tokenHtml ?><input type="hidden" name="_op" value="duty_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-sm btn-outline-danger">Usuń<span class="visually-hidden"> dyżur <?= h($r['person_name']) ?></span></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php endforeach; ?>
<?php
}

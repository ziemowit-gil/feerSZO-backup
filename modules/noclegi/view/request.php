<?php
/**
 * Karta zgłoszenia noclegu. Zmienne: $r, $log, $ins, $isLog, $coord, $suggest, $rooms, $budgets, $canEdit, $sensitive,
 * $transitions, $budgetSummary, $role, $uid.
 */
$PAGE_TITLE = 'Zgłoszenie noclegu #' . (int)$r['id']; $active = $role === 'uzytkownik' ? 'index' : 'kolejka';
include __DIR__ . '/_head.php'; include __DIR__ . '/_nav.php';
$d = fn($x) => date('d.m.Y', strtotime($x));
$nights = noclNights($r['date_from'], $r['date_to']);
$tlabels = ['w_trakcie' => 'Przejmij / w trakcie', 'potwierdzone' => 'Potwierdź rezerwację', 'zrealizowane' => 'Oznacz jako zrealizowane', 'anulowane' => 'Anuluj zgłoszenie', 'wniosek' => 'Cofnij do wniosku'];
?>
<div class="dyn-head ncl-head">
  <div class="dyn-head__main">
    <?= dynEntity('Zgłoszenie noclegu', '#' . (int)$r['id']) ?>
    <h1 class="dyn-title"><?= h($r['person_name']) ?></h1>
    <p class="ncl-lead mb-0"><?= h(noclRoleOsoby()[$r['person_role']] ?? $r['person_role']) ?><?= $r['source'] === 'ti' ? ' · z modułu TI (Noclegi kadry)' : '' ?></p>
  </div>
  <?= dynHFields([
      ['Termin', $d($r['date_from']) . ' – ' . $d($r['date_to'])],
      ['Noce', (string)$nights],
      ['Status', ['html' => '<span class="badge ' . h(noclStatusBadge($r['status'])) . '">' . h(noclStatusLabel($r['status'])) . '</span>']],
  ]) ?>
</div>
<?php $_st = noclStatusy(); $_flow = array_intersect_key($_st, array_flip(['wniosek', 'w_trakcie', 'potwierdzone', 'zrealizowane'])); ?>
<?= dynStages($_flow, (string)$r['status'], ['off' => isset($_st['anulowane']) ? ['anulowane' => $_st['anulowane']] : [], 'label' => 'Etapy zgłoszenia']) ?>

<?php if ($transitions): ?>
<section class="ncl-card" aria-labelledby="h-akcje">
  <h2 id="h-akcje" class="mt-0">Zmiana statusu</h2>
  <div class="d-flex flex-wrap gap-2">
  <?php foreach ($transitions as $to):
      if ($to === 'potwierdzone' && !$isLog) continue;
      if (in_array($to, ['w_trakcie', 'zrealizowane', 'wniosek'], true) && !$isLog && !($to === 'w_trakcie' && $r['status'] === 'wniosek' && $coord)) continue;
      if ($to === 'anulowane' && !($isLog || $coord || (int)$r['user_id'] === $uid || (int)$r['created_by'] === $uid)) continue; ?>
    <form method="post" action="<?= h(route('nocl.status', ['id' => $r['id']])) ?>" <?= $to === 'anulowane' ? 'onsubmit="return confirm(\'Na pewno anulować to zgłoszenie?\');"' : '' ?>>
      <?= Router::csrfField() ?><input type="hidden" name="to" value="<?= h($to) ?>">
      <button class="btn <?= $to === 'anulowane' ? 'btn-outline-danger' : 'btn-primary' ?>" type="submit"><?= h($tlabels[$to] ?? noclStatusLabel($to)) ?></button>
    </form>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <section class="ncl-card" aria-labelledby="h-dane">
      <h2 id="h-dane" class="mt-0">Dane zgłoszenia</h2>
      <dl class="ncl-kv">
        <dt>Cel</dt><dd><?= h($r['purpose'] ?: '—') ?></dd>
        <dt>Miejscowość</dt><dd><?= h($r['city'] ?: '—') ?></dd>
        <dt>Kontakt</dt><dd><?= h(trim($r['person_email'] . ' ' . $r['person_phone']) ?: '—') ?></dd>
        <dt>Typ pokoju</dt><dd><?= h($r['room_type_pref'] ? (noclTypyPokoi()[$r['room_type_pref']] ?? $r['room_type_pref']) : 'bez preferencji') ?></dd>
        <dt>Preferencje</dt><dd><?php $pp = array_filter(explode(',', $r['preferences'])); echo $pp ? h(implode(', ', array_map(fn($c) => noclPreferencje()[$c] ?? $c, $pp))) : '—'; ?></dd>
        <dt>Współlokator</dt><dd><?= h($r['roommate_pref'] ?: '—') ?></dd>
        <dt>Pokój dostępny</dt><dd><?= $r['needs_accessible'] ? '<strong>wymagany</strong>' : 'nie' ?></dd>
        <?php if ($sensitive && $r['accessibility_notes'] !== ''): ?><dt>Opis potrzeb</dt><dd><?= nl2br(h($r['accessibility_notes'])) ?></dd><?php endif; ?>
        <dt>Budżet</dt><dd><?= $r['budget_code'] ? h($r['budget_code'] . ' — ' . $r['budget_name']) . ($r['budget_approved'] ? ' · <span class="badge text-bg-success">koszt zaakceptowany</span>' : ' · <span class="badge text-bg-warning">czeka na akceptację kosztu</span>') : '—' ?></dd>
        <dt>Koszt</dt><dd><?= (float)$r['cost_total'] > 0 ? h(noclMoneyFmt((float)$r['cost_total'])) : '—' ?></dd>
        <?php if ($r['logistics_note'] !== '' && ($isLog || $coord)): ?><dt>Notatka logistyka</dt><dd><?= nl2br(h($r['logistics_note'])) ?></dd><?php endif; ?>
        <?php if ($r['coord_note'] !== ''): ?><dt>Notatka koordynatora</dt><dd><?= nl2br(h($r['coord_note'])) ?></dd><?php endif; ?>
      </dl>
    </section>

    <?php if ($r['budget_id'] && ($isLog || $coord) && !in_array($r['status'], ['zrealizowane', 'anulowane'], true)): ?>
    <section class="ncl-card" aria-labelledby="h-akc">
      <h2 id="h-akc" class="mt-0">Akceptacja kosztu (<?= h($r['budget_code']) ?>)</h2>
      <?php if ($budgetSummary && $budgetSummary['limit'] > 0): $pct = min(100, (int)round(100 * $budgetSummary['committed'] / $budgetSummary['limit'])); ?>
        <p class="mb-1">Zobowiązania <?= h(noclMoneyFmt($budgetSummary['committed'])) ?> z limitu <?= h(noclMoneyFmt($budgetSummary['limit'])) ?></p>
        <div class="ncl-bar<?= $budgetSummary['committed'] > $budgetSummary['limit'] ? ' over' : '' ?>" role="img" aria-label="Wykorzystanie limitu budżetu: <?= $pct ?> procent"><i style="width:<?= $pct ?>%"></i></div>
      <?php endif; ?>
      <form method="post" action="<?= h(route('nocl.approve', ['id' => $r['id']])) ?>" class="mt-3 d-flex gap-2 flex-wrap">
        <?= Router::csrfField() ?>
        <?php if (!$r['budget_approved']): ?><button class="btn btn-primary" name="do" value="tak">Akceptuję koszt</button>
        <?php else: ?><button class="btn btn-outline-secondary" name="do" value="cofnij">Cofnij akceptację</button><?php endif; ?>
      </form>
    </section>
    <?php endif; ?>
  </div>

  <div class="col-lg-6">
    <section class="ncl-card" aria-labelledby="h-pokoj">
      <h2 id="h-pokoj" class="mt-0">Rooming</h2>
      <?php if ($r['room_id']): ?>
        <p><strong><?= h($r['facility_name']) ?></strong>, pokój <strong><?= h($r['room_name']) ?></strong></p>
        <?php if ($isLog && !in_array($r['status'], ['potwierdzone', 'zrealizowane'], true)): ?>
        <form method="post" action="<?= h(route('nocl.assign', ['id' => $r['id']])) ?>"><?= Router::csrfField() ?><input type="hidden" name="room_id" value="0"><button class="btn btn-outline-secondary btn-sm">Zdejmij przydział</button></form>
        <?php endif; ?>
      <?php else: ?><p class="ncl-muted">Pokój nie został jeszcze przydzielony.</p><?php endif; ?>
      <?php if ($isLog && !in_array($r['status'], ['zrealizowane', 'anulowane'], true)): ?>
        <?php if ($suggest): ?>
        <h3 class="h6 mt-3">Podpowiedzi</h3>
        <ul class="list-unstyled mb-3">
          <?php foreach ($suggest as $s): ?><li class="mb-2">
            <form method="post" action="<?= h(route('nocl.assign', ['id' => $r['id']])) ?>" class="d-flex gap-2 align-items-center flex-wrap">
              <?= Router::csrfField() ?><input type="hidden" name="room_id" value="<?= (int)$s['id'] ?>">
              <span><?= h($s['facility_name']) ?>, pok. <?= h($s['name']) ?> — wolne miejsca: <?= (int)$s['free'] ?>/<?= (int)$s['beds'] ?>, <?= h(noclMoneyFmt($s['price'])) ?>/os./noc<?= $s['accessible'] ? ', dostępny' : '' ?></span>
              <button class="btn btn-sm btn-primary">Przydziel</button>
            </form></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <form method="post" action="<?= h(route('nocl.assign', ['id' => $r['id']])) ?>" class="row g-2 align-items-end">
          <?= Router::csrfField() ?>
          <div class="col"><label class="form-label" for="room_id">Wskaż pokój ręcznie</label>
            <select class="form-select" id="room_id" name="room_id"><option value="">— wybierz —</option>
              <?php foreach ($rooms as $rm): ?><option value="<?= (int)$rm['id'] ?>" <?= (int)$r['room_id'] === (int)$rm['id'] ? 'selected' : '' ?>><?= h($rm['fname'] . ' — ' . $rm['name'] . ' (' . $rm['beds'] . ' m.)' . ($rm['accessible'] ? ' ♿' : '')) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-auto"><button class="btn btn-primary">Przydziel</button></div>
        </form>
      <?php endif; ?>
    </section>

    <?php if ($isLog): ?>
    <section class="ncl-card" aria-labelledby="h-bud">
      <h2 id="h-bud" class="mt-0">Budżet</h2>
      <form method="post" action="<?= h(route('nocl.setbudget', ['id' => $r['id']])) ?>" class="row g-2 align-items-end">
        <?= Router::csrfField() ?>
        <div class="col"><label class="form-label" for="b2">Projekt / budżet</label>
          <select class="form-select" id="b2" name="budget_id"><option value="">— brak —</option>
            <?php foreach ($budgets as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$r['budget_id'] === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['code'] . ' — ' . $b['name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-auto"><button class="btn btn-outline-primary">Zapisz</button></div>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>

<?php if ($ins): ?>
<section class="ncl-card" aria-labelledby="h-ins">
  <h2 id="h-ins" class="mt-0">Instrukcja zameldowania</h2>
  <dl class="ncl-kv">
    <dt>Adres</dt><dd><?= h($ins['address']) ?><?php if ($ins['map']): ?> — <a href="<?= h($ins['map']) ?>" target="_blank" rel="noopener">mapa (nowa karta)</a><?php endif; ?></dd>
    <dt>Zameldowanie</dt><dd><?= h($ins['checkin']) ?>, wymeldowanie do <?= h($ins['checkout']) ?></dd>
    <?php if ($ins['codes']): ?>
      <?php if ($ins['door_code'] !== ''): ?><dt>Kod do drzwi</dt><dd><span class="ncl-code"><?= h($ins['door_code']) ?></span></dd><?php endif; ?>
      <?php if ($ins['lockbox'] !== ''): ?><dt>Skrytka</dt><dd><span class="ncl-code"><?= h($ins['lockbox']) ?></span></dd><?php endif; ?>
    <?php else: ?><dt>Kod / skrytka</dt><dd class="ncl-muted">widoczne od <?= h($d($ins['reveal_from'])) ?></dd><?php endif; ?>
  </dl>
  <?php if ($isLog && $r['status'] === 'potwierdzone'): ?>
  <form method="post" action="<?= h(route('nocl.sendins', ['id' => $r['id']])) ?>" class="mt-3"><?= Router::csrfField() ?>
    <button class="btn btn-outline-primary btn-sm">Wyślij instrukcję z kodami e-mailem</button>
    <span class="ncl-muted small ms-2"><?= $r['reminder_at'] ? 'Ostatnio wysłano ' . h(date('d.m.Y H:i', strtotime($r['reminder_at']))) : 'Automatycznie wyślemy ją ' . (int)noclRevealDays() . ' dni przed przyjazdem.' ?></span></form>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($canEdit): ?>
<details class="ncl-card">
  <summary class="fw-semibold">Edytuj dane zgłoszenia</summary>
  <form method="post" action="<?= h(route('nocl.update', ['id' => $r['id']])) ?>" class="mt-3" novalidate>
    <?= Router::csrfField() ?>
    <?php $f = $r; $errors = []; $showOther = false; include __DIR__ . '/_requestFields.php'; ?>
    <?php if ($isLog): ?><div class="mb-3"><label class="form-label" for="logistics_note">Notatka logistyka</label><textarea class="form-control" id="logistics_note" name="logistics_note" rows="2"><?= h($r['logistics_note']) ?></textarea></div><?php endif; ?>
    <?php if ($isLog || $coord): ?><div class="mb-3"><label class="form-label" for="coord_note">Notatka koordynatora</label><textarea class="form-control" id="coord_note" name="coord_note" rows="2"><?= h($r['coord_note']) ?></textarea></div><?php endif; ?>
    <button class="btn btn-primary">Zapisz zmiany</button>
  </form>
</details>
<?php endif; ?>

<section aria-labelledby="h-hist">
  <h2 id="h-hist">Historia</h2>
  <ul class="list-unstyled small">
  <?php foreach ($log as $l): ?><li><span class="ncl-muted"><?= h(date('d.m.Y H:i', strtotime($l['created_at']))) ?></span> · <?= h($l['user_name'] ?: 'system') ?> · <strong><?= h($l['action']) ?></strong> — <?= h($l['detail']) ?></li><?php endforeach; ?>
  </ul>
</section>
<?php include __DIR__ . '/_foot.php'; ?>

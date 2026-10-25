<?php
/**
 * modules/helpdeskRules/view/index.php — lista i formularz reguł. Zmienne: $rules, $row (edytowana lub null).
 */
$PAGE_TITLE = 'Helpdesk — Reguły automatyczne';
include dirname(__DIR__, 3) . '/includes/header.php';
echo hd_admin_nav('rules');
$v = fn(string $k, $d = '') => $row[$k] ?? $d;
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$cats = hd_categories();
$summary = function (array $r) use ($cats): string {
    $c = [];
    if ($r['trigger_type'] === 'idle') $c[] = 'brak aktywności ' . (int)$r['cond_idle_days'] . ' dni w statusie ' . (HD_STATUSES[$r['cond_status']]['label'] ?? $r['cond_status']);
    if ($r['trigger_type'] === 'sla') $c[] = HD_RULE_SLA_STATES[$r['cond_sla']] ?? $r['cond_sla'];
    if (($r['cond_to_status'] ?? '') !== '') $c[] = 'na status ' . (HD_STATUSES[$r['cond_to_status']]['label'] ?? '');
    if ($r['cond_category'] !== '') $c[] = 'kategoria: ' . ($cats[$r['cond_category']] ?? $r['cond_category']);
    if ($r['cond_priority'] !== '') $c[] = 'priorytet: ' . (HD_PRIORITIES[$r['cond_priority']]['label'] ?? '');
    if ($r['cond_source'] !== '') $c[] = 'źródło: ' . $r['cond_source'];
    if ($r['cond_keyword'] !== '') $c[] = 'słowa: ' . $r['cond_keyword'];
    if (($r['cond_tag'] ?? '') !== '') $c[] = 'tag: ' . $r['cond_tag'];
    if (($r['cond_email'] ?? '') !== '') $c[] = 'e-mail zawiera: ' . $r['cond_email'];
    if (($r['cond_assigned'] ?? '') !== '') $c[] = $r['cond_assigned'] === 'none' ? 'nieprzypisane' : 'przypisane';
    if (($r['cond_hours'] ?? '') !== '') $c[] = $r['cond_hours'] === 'on' ? 'w godzinach pracy' : 'poza godzinami pracy';
    return implode(' · ', $c);
};
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2"></i>Reguły automatyczne</h4>
</div>
<?= flash_html() ?>
<p class="text-muted small">Reguła = <strong>wyzwalacz</strong> + <strong>warunki</strong> (wszystkie muszą być spełnione) → <strong>kroki</strong> (te same co w makrach, a także powiadomienie operatora i automatyczna odpowiedź do zgłaszającego). Reguły czasowe sprawdzają się co godzinę i działają raz na zgłoszenie. Zmiany robione przez regułę nie uruchamiają kolejnych reguł.</p>

<div class="row g-3">
<div class="col-xl-6">
  <div class="card"><div class="card-header fw-semibold">Reguły (<?= count($rules) ?>)</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.85rem">
    <thead><tr><th>#</th><th>Reguła</th><th>Kroki</th><th>Użycia</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rules as $r): $steps = hdRuleSteps($r); ?>
      <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
        <td><?= (int)$r['sort_order'] ?></td>
        <td><div class="fw-semibold"><?= h($r['name']) ?><?= !empty($r['stop_after']) ? ' <span class="badge bg-light text-dark border" title="Zatrzymuje kolejne reguły">stop</span>' : '' ?></div>
          <div class="text-muted" style="font-size:.76rem"><?= h(HD_RULE_TRIGGERS[$r['trigger_type']] ?? $r['trigger_type']) ?><?= ($sm = $summary($r)) !== '' ? ' · ' . h($sm) : '' ?></div></td>
        <td style="font-size:.78rem"><?= h(implode(', ', array_map('hdMacroStepLabel', $steps))) ?></td>
        <td><?= (int)$r['hits'] ?></td>
        <td class="text-nowrap text-end">
          <a class="btn btn-sm btn-outline-secondary py-0" href="<?= h(route('hdrules.index')) ?>&edit=<?= (int)$r['id'] ?>">Edytuj</a>
          <form method="post" action="<?= h(route('hdrules.toggle', ['id' => $r['id']])) ?>" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-sm btn-outline-secondary py-0"><?= $r['is_active'] ? 'Wyłącz' : 'Włącz' ?></button></form>
          <form method="post" action="<?= h(route('hdrules.delete', ['id' => $r['id']])) ?>" class="d-inline" onsubmit="return confirm('Usunąć regułę?')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-sm btn-outline-danger py-0">Usuń</button></form>
        </td>
      </tr>
    <?php endforeach; if (!$rules): ?><tr><td colspan="5" class="text-muted text-center py-4">Brak reguł.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>

<div class="col-xl-6">
  <div class="card"><div class="card-header fw-semibold"><?= $row ? 'Edycja reguły' : 'Nowa reguła' ?></div>
  <form method="post" action="<?= h(route('hdrules.save')) ?>" class="card-body" style="font-size:.85rem">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$v('id', 0) ?>">
    <div class="row g-2 mb-2">
      <div class="col-sm-6"><label class="form-label mb-0">Nazwa</label><input class="form-control form-control-sm" name="name" required maxlength="120" value="<?= h($v('name')) ?>"></div>
      <div class="col-sm-4"><label class="form-label mb-0">Wyzwalacz</label>
        <select name="trigger_type" id="hdrTrigger" class="form-select form-select-sm">
          <?php foreach (HD_RULE_TRIGGERS as $k => $l): ?><option value="<?= h($k) ?>"<?= $sel($v('trigger_type', 'create'), $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-2"><label class="form-label mb-0">Kolejność</label><input type="number" class="form-control form-control-sm" name="sort_order" value="<?= (int)$v('sort_order', 100) ?>"></div>
    </div>

    <div class="fw-semibold mt-3 mb-1">Warunki <span class="fw-normal text-muted">(wszystkie niepuste muszą być spełnione)</span></div>
    <div class="row g-2 mb-2">
      <div class="col-sm-6"><label class="form-label mb-0">Kategoria</label><select name="cond_category" class="form-select form-select-sm"><option value="">dowolna</option>
        <?php foreach ($cats as $k => $l): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_category'), $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-3"><label class="form-label mb-0">Priorytet</label><select name="cond_priority" class="form-select form-select-sm"><option value="">dowolny</option>
        <?php foreach (HD_PRIORITIES as $k => $p): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_priority'), $k) ?>><?= h($p['label']) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-3"><label class="form-label mb-0">Źródło</label><select name="cond_source" class="form-select form-select-sm"><option value="">dowolne</option>
        <?php foreach (['portal' => 'portal', 'email' => 'e-mail', 'panel_kursanta' => 'panel kursanta', 'bug_report' => 'zgłoszenie błędu'] as $k => $l): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_source'), $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-6"><label class="form-label mb-0">Słowa kluczowe (po przecinku, w temacie lub opisie)</label><input class="form-control form-control-sm" name="cond_keyword" value="<?= h($v('cond_keyword')) ?>" placeholder="vpn, teams"></div>
      <div class="col-sm-3"><label class="form-label mb-0">Tag</label><input class="form-control form-control-sm" name="cond_tag" value="<?= h($v('cond_tag')) ?>" placeholder="np. vip"></div>
      <div class="col-sm-3"><label class="form-label mb-0">E-mail zgłaszającego zawiera</label><input class="form-control form-control-sm" name="cond_email" value="<?= h($v('cond_email')) ?>" placeholder="@feer.org.pl"></div>
      <div class="col-sm-3"><label class="form-label mb-0">Przypisanie</label><select name="cond_assigned" class="form-select form-select-sm">
        <option value="">dowolne</option><option value="none"<?= $sel($v('cond_assigned'), 'none') ?>>nieprzypisane</option><option value="some"<?= $sel($v('cond_assigned'), 'some') ?>>przypisane</option></select></div>
      <div class="col-sm-3"><label class="form-label mb-0">Pora</label><select name="cond_hours" class="form-select form-select-sm">
        <option value="">dowolna</option><option value="on"<?= $sel($v('cond_hours'), 'on') ?>>w godzinach pracy</option><option value="off"<?= $sel($v('cond_hours'), 'off') ?>>poza godzinami pracy</option></select></div>
      <div class="col-sm-6" data-trg="status"><label class="form-label mb-0">Nowy status (dla zmiany statusu)</label><select name="cond_to_status" class="form-select form-select-sm"><option value="">dowolny</option>
        <?php foreach (HD_STATUSES as $k => $s): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_to_status'), $k) ?>><?= h($s['label']) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-4" data-trg="idle"><label class="form-label mb-0">Status zgłoszenia</label><select name="cond_status" class="form-select form-select-sm"><option value="">—</option>
        <?php foreach (HD_STATUSES as $k => $s): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_status'), $k) ?>><?= h($s['label']) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-2" data-trg="idle"><label class="form-label mb-0">Dni bez aktywności</label><input type="number" min="0" class="form-control form-control-sm" name="cond_idle_days" value="<?= (int)$v('cond_idle_days', 0) ?>"></div>
      <div class="col-sm-6" data-trg="sla"><label class="form-label mb-0">Stan SLA</label><select name="cond_sla" class="form-select form-select-sm"><option value="">—</option>
        <?php foreach (HD_RULE_SLA_STATES as $k => $l): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_sla'), $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    </div>

    <div class="fw-semibold mt-3 mb-1">Kroki <span class="fw-normal text-muted">(wykonują się po kolei)</span></div>
    <div class="p-2 mb-2" style="background:#faf9f8;border:1px solid #e1dfdd"><?= hdStepsBuilder(hdRuleSteps($row ?? []), 0) ?></div>

    <div class="d-flex flex-wrap gap-3 mb-3">
      <label class="form-check"><input class="form-check-input" type="checkbox" name="stop_after" value="1"<?= !empty($row['stop_after']) ? ' checked' : '' ?>> <span class="form-check-label">Zatrzymaj kolejne reguły po tej</span></label>
      <label class="form-check"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="hdrAct"<?= $v('is_active', 1) ? ' checked' : '' ?>> <span class="form-check-label">Aktywna</span></label>
    </div>
    <button class="btn btn-sm btn-primary">Zapisz</button>
    <?php if ($row): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(route('hdrules.index')) ?>">Anuluj</a><?php endif; ?>
  </form></div>
</div>
</div>
<script>
(function(){
  var t=document.getElementById('hdrTrigger'); if(!t) return;
  function sync(){ document.querySelectorAll('[data-trg]').forEach(function(el){ el.style.display = el.dataset.trg===t.value ? '' : 'none'; }); }
  t.addEventListener('change',sync); sync();
})();
</script>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>

<?php
/**
 * modules/helpdeskRules/view/index.php — lista i formularz reguł. Zmienne: $rules, $row (edytowana lub null), $operators.
 */
$PAGE_TITLE = 'Helpdesk — Reguły automatyczne';
include dirname(__DIR__, 3) . '/includes/header.php';
$v = fn(string $k, $d = '') => $row[$k] ?? $d;
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2"></i>Helpdesk — reguły automatyczne</h4>
  <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Lista zgłoszeń</a>
</div>
<?= flash_html() ?>
<p class="text-muted small">Reguła <strong>przy utworzeniu</strong> działa od razu po powstaniu zgłoszenia (formularz, panel, e-mail). Reguła <strong>czasowa</strong> sprawdza co godzinę zgłoszenia w danym statusie bez aktywności od N dni (np. rozwiązane → zamknięte). Reguły wykonują się wg kolejności; operator przypisany przez pierwszą regułę nie jest nadpisywany.</p>

<div class="row g-3">
<div class="col-lg-7">
  <div class="card"><div class="card-header fw-semibold">Reguły (<?= count($rules) ?>)</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.85rem">
    <thead><tr><th>#</th><th>Nazwa</th><th>Wyzwalacz</th><th>Akcja</th><th>Użycia</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rules as $r): ?>
      <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
        <td><?= (int)$r['sort_order'] ?></td>
        <td class="fw-semibold"><?= h($r['name']) ?></td>
        <td><?= $r['trigger_type'] === 'idle'
              ? 'Brak aktywności ' . (int)$r['cond_idle_days'] . ' dni · ' . h(HD_STATUSES[$r['cond_status']]['label'] ?? $r['cond_status'])
              : 'Nowe zgłoszenie' . implode('', array_filter([
                  $r['cond_category'] !== '' ? ' · ' . h(HD_CATEGORIES[$r['cond_category']] ?? '') : '',
                  $r['cond_priority'] !== '' ? ' · ' . h(HD_PRIORITIES[$r['cond_priority']]['label'] ?? '') : '',
                  $r['cond_source'] !== '' ? ' · źródło ' . h($r['cond_source']) : '',
                  $r['cond_keyword'] !== '' ? ' · „' . h($r['cond_keyword']) . '”' : ''])) ?></td>
        <td><?= h(implode(', ', array_filter([
              $r['assign_name'] ? 'przypisz: ' . $r['assign_name'] : '', $r['act_priority'] !== '' ? 'priorytet: ' . (HD_PRIORITIES[$r['act_priority']]['label'] ?? '') : '',
              $r['act_category'] !== '' ? 'kategoria: ' . (HD_CATEGORIES[$r['act_category']] ?? '') : '', $r['act_status'] !== '' ? 'status: ' . (HD_STATUSES[$r['act_status']]['label'] ?? '') : '',
              $r['act_note'] !== '' ? 'notatka' : '']))) ?></td>
        <td><?= (int)$r['hits'] ?></td>
        <td class="text-nowrap text-end">
          <a class="btn btn-sm btn-outline-secondary py-0" href="<?= h(route('hdrules.index')) ?>&edit=<?= (int)$r['id'] ?>">Edytuj</a>
          <form method="post" action="<?= h(route('hdrules.toggle', ['id' => $r['id']])) ?>" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-sm btn-outline-secondary py-0"><?= $r['is_active'] ? 'Wyłącz' : 'Włącz' ?></button></form>
          <form method="post" action="<?= h(route('hdrules.delete', ['id' => $r['id']])) ?>" class="d-inline" onsubmit="return confirm('Usunąć regułę?')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-sm btn-outline-danger py-0">Usuń</button></form>
        </td>
      </tr>
    <?php endforeach; if (!$rules): ?><tr><td colspan="6" class="text-muted text-center py-4">Brak reguł.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>

<div class="col-lg-5">
  <div class="card"><div class="card-header fw-semibold"><?= $row ? 'Edycja reguły' : 'Nowa reguła' ?></div>
  <form method="post" action="<?= h(route('hdrules.save')) ?>" class="card-body" style="font-size:.85rem">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$v('id', 0) ?>">
    <div class="mb-2"><label class="form-label mb-0">Nazwa</label><input class="form-control form-control-sm" name="name" required maxlength="120" value="<?= h($v('name')) ?>"></div>
    <div class="row g-2 mb-2">
      <div class="col-8"><label class="form-label mb-0">Wyzwalacz</label>
        <select name="trigger_type" class="form-select form-select-sm"><option value="create"<?= $sel($v('trigger_type', 'create'), 'create') ?>>Przy utworzeniu zgłoszenia</option><option value="idle"<?= $sel($v('trigger_type'), 'idle') ?>>Brak aktywności (czasowa)</option></select></div>
      <div class="col-4"><label class="form-label mb-0">Kolejność</label><input type="number" class="form-control form-control-sm" name="sort_order" value="<?= (int)$v('sort_order', 100) ?>"></div>
    </div>
    <div class="fw-semibold mt-3 mb-1">Warunki</div>
    <div class="row g-2 mb-2">
      <div class="col-6"><label class="form-label mb-0">Kategoria</label><select name="cond_category" class="form-select form-select-sm"><option value="">dowolna</option>
        <?php foreach (HD_CATEGORIES as $k => $l): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_category'), $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Priorytet</label><select name="cond_priority" class="form-select form-select-sm"><option value="">dowolny</option>
        <?php foreach (HD_PRIORITIES as $k => $p): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_priority'), $k) ?>><?= h($p['label']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Źródło</label><select name="cond_source" class="form-select form-select-sm"><option value="">dowolne</option>
        <?php foreach (['portal' => 'portal', 'email' => 'e-mail', 'panel_kursanta' => 'panel kursanta', 'bug_report' => 'zgłoszenie błędu'] as $k => $l): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_source'), $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Słowa kluczowe (po przecinku)</label><input class="form-control form-control-sm" name="cond_keyword" value="<?= h($v('cond_keyword')) ?>" placeholder="vpn, teams"></div>
      <div class="col-6"><label class="form-label mb-0">Status (czasowa)</label><select name="cond_status" class="form-select form-select-sm"><option value="">—</option>
        <?php foreach (HD_STATUSES as $k => $s): ?><option value="<?= h($k) ?>"<?= $sel($v('cond_status'), $k) ?>><?= h($s['label']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Dni bez aktywności</label><input type="number" min="0" class="form-control form-control-sm" name="cond_idle_days" value="<?= (int)$v('cond_idle_days', 0) ?>"></div>
    </div>
    <div class="fw-semibold mt-3 mb-1">Akcje</div>
    <div class="row g-2 mb-2">
      <div class="col-6"><label class="form-label mb-0">Przypisz operatora</label><select name="act_assign" class="form-select form-select-sm"><option value="">—</option>
        <?php foreach ($operators as $o): ?><option value="<?= (int)$o['id'] ?>"<?= $sel($v('act_assign'), $o['id']) ?>><?= h($o['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Ustaw priorytet</label><select name="act_priority" class="form-select form-select-sm"><option value="">—</option>
        <?php foreach (HD_PRIORITIES as $k => $p): ?><option value="<?= h($k) ?>"<?= $sel($v('act_priority'), $k) ?>><?= h($p['label']) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Zmień kategorię</label><select name="act_category" class="form-select form-select-sm"><option value="">—</option>
        <?php foreach (HD_CATEGORIES as $k => $l): ?><option value="<?= h($k) ?>"<?= $sel($v('act_category'), $k) ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
      <div class="col-6"><label class="form-label mb-0">Ustaw status (czasowa)</label><select name="act_status" class="form-select form-select-sm"><option value="">—</option>
        <?php foreach (HD_STATUSES as $k => $s): ?><option value="<?= h($k) ?>"<?= $sel($v('act_status'), $k) ?>><?= h($s['label']) ?></option><?php endforeach; ?></select></div>
      <div class="col-12"><label class="form-label mb-0">Notatka wewnętrzna</label><input class="form-control form-control-sm" name="act_note" maxlength="1000" value="<?= h($v('act_note')) ?>"></div>
    </div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="hdrAct"<?= $v('is_active', 1) ? ' checked' : '' ?>><label class="form-check-label" for="hdrAct">Aktywna</label></div>
    <button class="btn btn-sm btn-primary">Zapisz</button>
    <?php if ($row): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(route('hdrules.index')) ?>">Anuluj</a><?php endif; ?>
  </form></div>
</div>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>

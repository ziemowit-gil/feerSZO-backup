<?php
/**
 * modules/helpdeskStatuses/view/index.php — lista i formularz własnych statusów kategorii. Zmienne: $rows, $row (edytowany lub null).
 */
$PAGE_TITLE = 'Helpdesk — Statusy kategorii';
include dirname(__DIR__, 3) . '/includes/header.php';
echo hd_admin_nav('statuses');
$cats = hd_categories();
$v = fn(string $k, $d = '') => $row[$k] ?? $d;
?>
<h4 class="fw-bold mb-1" style="font-size:1.4rem"><i class="bi bi-signpost-split me-2" style="color:#0f6cbd"></i>Statusy własne kategorii</h4>
<p class="text-muted small" style="max-width:820px">Własny status doprecyzowuje etap zgłoszenia w wybranych kategoriach (np. wnioski AM: „Licencja przypisana”). Każdy mapuje się na <strong>status bazowy</strong> — to on napędza SLA, liczniki i filtry; wybór własnego statusu ustawia też status bazowy (z powiadomieniem zgłaszającego, jak przy zwykłej zmianie). Kolejność = kolejność etapów na karcie zgłoszenia. Wyłączony status znika z wyboru, ale zgłoszenia, które go mają, nadal go pokazują. Podkategorie dziedziczą statusy kategorii nadrzędnej.</p>
<?= flash_html() ?>
<div class="row g-3">
<div class="col-xl-7">
  <div class="card"><div class="card-header fw-semibold">Statusy (<?= count($rows) ?>)</div>
  <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:.85rem">
    <thead><tr><th>#</th><th>Status</th><th>Kategorie</th><th>Bazowy</th><th>Zgł.</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['active'] ? '' : 'text-muted' ?>">
        <td><?= (int)$r['sort'] ?></td>
        <td><span class="d-inline-block me-1" style="width:10px;height:10px;background:<?= h($r['color'] !== '' ? $r['color'] : '#8a8886') ?>"></span><strong><?= h($r['label']) ?></strong>
          <div class="text-muted font-monospace" style="font-size:.7rem"><?= h($r['code']) ?></div></td>
        <td><?php foreach ($r['categories'] as $c): ?><span class="badge bg-light text-dark border fw-normal"><?= h($cats[$c] ?? $c) ?></span> <?php endforeach; ?></td>
        <td><?= h(HD_STATUSES[$r['base']]['label'] ?? $r['base']) ?></td>
        <td><?= (int)$r['used'] ?></td>
        <td class="text-nowrap text-end">
          <a class="btn btn-sm btn-outline-secondary py-0" href="<?= h(route('hdstatuses.index')) ?>&edit=<?= (int)$r['id'] ?>">Edytuj</a>
          <form method="post" action="<?= h(route('hdstatuses.toggle', ['id' => $r['id']])) ?>" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <button class="btn btn-sm btn-outline-secondary py-0"><?= $r['active'] ? 'Wyłącz' : 'Włącz' ?></button></form>
        </td>
      </tr>
    <?php endforeach; if (!$rows): ?><tr><td colspan="6" class="text-muted text-center py-4">Brak statusów.</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>
<div class="col-xl-5">
  <div class="card"><div class="card-header fw-semibold"><?= $row ? 'Edycja statusu' : 'Nowy status' ?></div>
  <form method="post" action="<?= h(route('hdstatuses.save')) ?>" class="card-body" style="font-size:.85rem">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="id" value="<?= (int)$v('id', 0) ?>">
    <div class="mb-2"><label class="form-label mb-0" for="hdsLabel">Nazwa statusu</label><input id="hdsLabel" class="form-control form-control-sm" name="label" required maxlength="120" value="<?= h($v('label')) ?>" placeholder="np. Licencja przypisana"></div>
    <div class="row g-2 mb-2">
      <div class="col-sm-6"><label class="form-label mb-0" for="hdsBase">Status bazowy</label>
        <select id="hdsBase" name="base" class="form-select form-select-sm">
          <?php foreach (HD_STATUSES as $k => $s): ?><option value="<?= h($k) ?>"<?= $v('base', 'otwarte') === $k ? ' selected' : '' ?>><?= h($s['label']) ?></option><?php endforeach; ?></select></div>
      <div class="col-sm-3"><label class="form-label mb-0" for="hdsColor">Kolor</label><input id="hdsColor" type="color" class="form-control form-control-sm form-control-color w-100" name="color" value="<?= h($v('color', '#0f6cbd') ?: '#0f6cbd') ?>"></div>
      <div class="col-sm-3"><label class="form-label mb-0" for="hdsSort">Kolejność</label><input id="hdsSort" type="number" class="form-control form-control-sm" name="sort_order" value="<?= $row ? (int)$row['sort'] : '' ?>" placeholder="auto"></div>
    </div>
    <fieldset class="mb-3"><legend class="form-label mb-1" style="font-size:.85rem">Kategorie, w których status jest dostępny</legend>
      <div style="max-height:200px;overflow:auto;border:1px solid #e1dfdd;padding:.4rem .6rem">
        <?php foreach ($cats as $k => $l): ?>
        <label class="d-flex align-items-center gap-2"><input class="form-check-input mt-0" type="checkbox" name="categories[]" value="<?= h($k) ?>"<?= in_array($k, (array)$v('categories', []), true) ? ' checked' : '' ?>><?= h($l) ?></label>
        <?php endforeach; ?>
      </div></fieldset>
    <button class="btn btn-sm btn-primary">Zapisz</button>
    <?php if ($row): ?><a class="btn btn-sm btn-outline-secondary" href="<?= h(route('hdstatuses.index')) ?>">Anuluj</a><?php endif; ?>
  </form></div>
</div>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>

<?php
/**
 * modules/ti/view/nabor/panel/grupy.php — które grupy TI są w formularzu naboru i jak je opisać rodzicom.
 * Limit miejsc = max_students grupy (edytowany w karcie grupy), zajęte = aktywne zapisy.
 * Zmienne: $me, $courses (naborCourses()), $editId. Kontroler: NaborPanelController::courses / courseSave.
 */
$title = 'Nabór — grupy';
$kierLabel = 'Nabór do grup';
include __DIR__ . '/_head.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= h(route('nabor.index')) ?>">Nabór do grup</a></li><li class="breadcrumb-item active">Grupy w naborze</li></ol></nav>
<h4 class="mb-1"><i class="bi bi-collection text-primary"></i> Grupy w naborze</h4>
<p class="text-muted small mb-3">Otwórz grupy, do których rodzice mogą zgłaszać uczestników. Limit miejsc to „maks. uczestników” grupy; zajęte miejsca to aktywne zapisy. Opis widzą rodzice w formularzu.</p>
<?= flash_html() ?>

<div class="table-responsive"><table class="table table-sm align-middle">
  <thead><tr><th>Grupa</th><th>Prowadzący</th><th>Terminy</th><th>Miejsca</th><th>W naborze</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($courses as $c): $cid = (int)$c['id']; ?>
    <tr>
      <td><strong><?= h($c['title']) ?></strong><?php if ($c['title'] !== $c['name']): ?><div class="small text-muted"><?= h($c['name']) ?></div><?php endif; ?>
        <div class="small text-muted"><?= h(implode(' · ', array_filter([$c['category'], $c['age_group']]))) ?></div></td>
      <td class="small"><?= h((string)$c['instructor_name']) ?></td>
      <td class="small"><?= h($c['schedule']) ?><div class="text-muted"><?= h((string)$c['location']) ?></div></td>
      <td class="small"><?= (int)$c['taken'] ?><?= (int)$c['max_students'] ? ' / ' . (int)$c['max_students'] : '' ?>
        <?php if ((int)$c['queued']): ?><div class="text-muted"><?= (int)$c['queued'] ?> zgłoszeń w kolejce</div><?php endif; ?></td>
      <td><?= $c['is_open'] ? '<span class="badge text-bg-success">otwarta</span>' : '<span class="badge text-bg-secondary">nie</span>' ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('nabor.index', ['course' => $cid])) ?>" title="Zgłoszenia do grupy"><i class="bi bi-people"></i><span class="visually-hidden">Zgłoszenia do grupy</span></a>
        <a class="btn btn-sm btn-outline-primary" href="<?= h(route('nabor.courses', ['id' => $cid])) ?>#g<?= $cid ?>"><i class="bi bi-pencil"></i> Ustawienia</a>
      </td>
    </tr>
    <?php if ($editId === $cid): ?>
    <tr id="g<?= $cid ?>"><td colspan="6" class="bg-light">
      <form method="post" action="<?= h(route('nabor.courses.save', ['id' => $cid])) ?>" class="row g-2 py-2">
        <?= Router::csrfField() ?>
        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="o<?= $cid ?>" name="is_open" value="1" <?= $c['is_open'] ? 'checked' : '' ?>>
          <label class="form-check-label fw-bold" for="o<?= $cid ?>">Grupa widoczna w formularzu naboru</label></div></div>
        <div class="col-md-6"><label class="form-label small" for="pn<?= $cid ?>">Nazwa dla rodziców</label>
          <input id="pn<?= $cid ?>" class="form-control form-control-sm" name="public_name" value="<?= h($c['public_name']) ?>" placeholder="<?= h(trim((string)$c['display_name']) !== '' ? $c['display_name'] : $c['name']) ?>"></div>
        <div class="col-md-3"><label class="form-label small" for="ct<?= $cid ?>">Kategoria</label>
          <input id="ct<?= $cid ?>" class="form-control form-control-sm" name="category" value="<?= h($c['category']) ?>" placeholder="np. informatyka, robotyka"></div>
        <div class="col-md-3"><label class="form-label small" for="ag<?= $cid ?>">Wiek / klasy</label>
          <input id="ag<?= $cid ?>" class="form-control form-control-sm" name="age_group" value="<?= h($c['age_group']) ?>" placeholder="np. 10–14 lat"></div>
        <div class="col-md-6"><label class="form-label small" for="sc<?= $cid ?>">Terminy (opis)</label>
          <input id="sc<?= $cid ?>" class="form-control form-control-sm" name="schedule" value="<?= h($c['schedule_note']) ?>" placeholder="<?= h(naborCourseDefaultSchedule($c) ?: 'np. wtorki 15:00–16:30, od 4 listopada') ?>"></div>
        <div class="col-md-4"><label class="form-label small" for="pr<?= $cid ?>">Opłata (informacja)</label>
          <input id="pr<?= $cid ?>" class="form-control form-control-sm" name="price_info" value="<?= h($c['price_info']) ?>" placeholder="np. 120 zł / miesiąc"></div>
        <div class="col-md-2"><label class="form-label small" for="so<?= $cid ?>">Kolejność</label>
          <input id="so<?= $cid ?>" type="number" class="form-control form-control-sm" name="sort_order" value="<?= (int)$c['sort_order'] ?>"></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-primary">Zapisz</button><a class="btn btn-sm btn-outline-secondary" href="<?= h(route('nabor.courses')) ?>">Anuluj</a></div>
      </form>
    </td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$courses): ?><tr><td colspan="6" class="text-center text-muted py-4">Brak aktywnych grup TI.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php include __DIR__ . '/_foot.php'; ?>

<?php
/**
 * Sekcja „Postępy w modułach” karty kursanta — status opanowania punktów planu kursu (k30_ti_curriculum), osobno dla każdej grupy.
 * Zmienne: $clientId, $courseCtx, $courses, $modulesByCourse[course_id] = ['rows' => …, 'sum' => …].
 */
?>
<section id="moduly" aria-labelledby="h-moduly" class="card border-0 shadow-sm mb-4">
  <div class="card-header"><h2 class="h5 mb-0" id="h-moduly"><i class="bi bi-list-check text-primary me-2" aria-hidden="true"></i>Postępy w modułach</h2></div>
  <div class="card-body">
    <p class="small text-body-secondary">Moduły to punkty planu nauczania grupy. Status zapisuje się dla całej grupy naraz; zmieniony status dostaje dzisiejszą datę.</p>
    <?php if (!$courses): ?><p class="text-body-secondary mb-0">Kursant nie jest zapisany do żadnej z Twoich grup.</p><?php endif; ?>
    <?php foreach ($courses as $c):
      $cid = (int)$c['id']; $rows = $modulesByCourse[$cid]['rows']; $sum = $modulesByCourse[$cid]['sum']; $active = $c['enroll_status'] === 'active'; ?>
    <details id="moduly-<?= $cid ?>" class="mb-3 border rounded p-2"<?= $active ? ' open' : '' ?>>
      <summary class="fw-semibold" style="cursor:pointer">
        <?= h($c['name']) ?><?= $c['group_code'] !== '' ? ' <span class="text-body-secondary fw-normal">(' . h($c['group_code']) . ')</span>' : '' ?>
        <?= $active ? '' : dk_badge('bi-person-dash', 'dk-muted', 'wypisany') ?>
        <span class="ms-2 fw-normal small">Opanowane: <?= (int)$sum['mastered'] ?> z <?= (int)$sum['total'] ?> (<?= (int)$sum['pct'] ?>%)</span>
      </summary>
      <?php if (!$rows): ?>
        <p class="small text-body-secondary mt-2 mb-0">Ta grupa nie ma jeszcze planu nauczania. <a href="index.php?course=<?= $cid ?>&amp;tab=program">Dodaj punkty planu</a>.</p>
      <?php else: ?>
      <progress class="w-100 mt-2" value="<?= (int)$sum['mastered'] ?>" max="<?= max(1, (int)$sum['total']) ?>" aria-label="Opanowane moduły w grupie <?= h($c['name']) ?>"><?= (int)$sum['pct'] ?>%</progress>
      <form method="post" action="<?= h(dk_url($clientId, $courseCtx, [], '#moduly-' . $cid)) ?>">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save_modules">
        <input type="hidden" name="client" value="<?= (int)$clientId ?>"><input type="hidden" name="course_id" value="<?= $cid ?>">
        <div class="table-responsive">
          <table class="table table-sm align-middle" style="font-size:.88rem">
            <caption class="visually-hidden">Statusy modułów kursanta w grupie <?= h($c['name']) ?></caption>
            <thead class="table-light"><tr><th scope="col">Moduł</th><th scope="col" style="width:13rem">Status</th><th scope="col" class="text-nowrap">Ostatnia zmiana</th><th scope="col">Notatka</th></tr></thead>
            <?php $prevSec = null; $open_tb = false;
            foreach ($rows as $m):
              if ($prevSec !== $m['section']): if ($open_tb) echo '</tbody>'; $prevSec = $m['section']; $open_tb = true; ?>
            <tbody>
              <?php if ($m['section'] !== ''): ?><tr class="table-secondary"><th colspan="4" scope="rowgroup"><?= h($m['section']) ?></th></tr><?php endif; ?>
            <?php endif; ?>
              <tr>
                <th scope="row" class="fw-normal"><?= h($m['title']) ?><?= (int)$m['est_minutes'] > 0 ? ' <span class="small text-body-secondary">(' . (int)$m['est_minutes'] . ' min)</span>' : '' ?></th>
                <td>
                  <label class="visually-hidden" for="ms-<?= (int)$m['id'] ?>">Status modułu: <?= h($m['title']) ?></label>
                  <select class="form-select form-select-sm" id="ms-<?= (int)$m['id'] ?>" name="modules[<?= (int)$m['id'] ?>][status]">
                    <?php foreach (TiDydaktykaManager::MODULE_STATUSES as $k => $l): ?><option value="<?= h($k) ?>"<?= $m['status'] === $k ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
                  </select>
                </td>
                <td class="text-nowrap small"><?= h(dk_date($m['status_date'])) ?></td>
                <td>
                  <label class="visually-hidden" for="mn-<?= (int)$m['id'] ?>">Notatka do modułu: <?= h($m['title']) ?></label>
                  <input type="text" class="form-control form-control-sm" id="mn-<?= (int)$m['id'] ?>" name="modules[<?= (int)$m['id'] ?>][note]" value="<?= h($m['note']) ?>" maxlength="300">
                </td>
              </tr>
            <?php endforeach; if ($open_tb) echo '</tbody>'; ?>
          </table>
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz statusy</button>
      </form>
      <?php endif; ?>
    </details>
    <?php endforeach; ?>
  </div>
</section>

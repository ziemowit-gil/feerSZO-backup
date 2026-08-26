<?php /* ═══════════════════════ TAB: PROTOKOŁY OCEN (widok USOS) ═══════════════════════ */ ?>
<?php
/**
 * Protokół = oceny końcowe wszystkich uczestników kursu za okres nauczania,
 * wpisywane zbiorczo w jednej tabeli i zatwierdzane ze śladem (kto, kiedy).
 * Po zatwierdzeniu edycja jest zamknięta — odblokować może pracownik D3 / admin,
 * podając powód. Protokół jest też warunkiem zamknięcia okresu nauczania.
 *
 * Zmienne z index.php: $cur_course, $course, $uid, $me.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_periods.php';

$pr_list  = ti_protocols_for_course($cur_course);
$pr_id    = (int)($_GET['protocol'] ?? 0);
$pr       = $pr_id ? ti_protocol_get($pr_id) : null;
if ($pr && (int)$pr['course_id'] !== $cur_course) $pr = null;
if (!$pr && $pr_list) $pr = ti_protocol_get((int)$pr_list[0]['id']);

$pr_periods = ti_periods_all();
// Okresy, dla których protokół jeszcze nie istnieje
$pr_used = array_map(fn($r) => (int)($r['period_id'] ?? 0), $pr_list);
$pr_free = array_values(array_filter($pr_periods, fn($p) => !in_array((int)$p['id'], $pr_used, true)));

$pr_parts   = $pr ? ti_protocol_participants($cur_course) : [];
$pr_entries = $pr ? ti_protocol_entries((int)$pr['id']) : [];
$pr_avgs    = $pr ? ti_protocol_diary_averages($cur_course) : [];
$pr_stats   = $pr ? ti_protocol_stats((int)$pr['id'], $cur_course) : ['total'=>0,'filled'=>0,'pct'=>0];
$pr_locked  = $pr ? ti_protocol_is_locked($pr) : false;
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-card-checklist me-2" aria-hidden="true"></i>Protokoły ocen — <?= h($course['name']) ?></h1>
  <span class="badge bg-secondary"><?= count($pr_list) ?></span>
</div>

<div class="row g-3">
  <!-- Lista protokołów -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Protokoły kursu</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <caption class="visually-hidden">Protokoły ocen kursu <?= h($course['name']) ?> ze stanem zatwierdzenia</caption>
          <thead><tr><th scope="col">Okres</th><th scope="col">Stan</th></tr></thead>
          <tbody>
            <?php if (!$pr_list): ?>
            <tr><td colspan="2" class="text-center text-muted py-3">Brak protokołów — otwórz pierwszy poniżej.</td></tr>
            <?php endif; ?>
            <?php foreach ($pr_list as $row):
              $st  = TI_PROTOCOL_STATUSES[$row['status']] ?? ['label'=>$row['status'],'badge'=>'secondary'];
              $rst = ti_protocol_stats((int)$row['id'], $cur_course);
              $sel = $pr && (int)$pr['id'] === (int)$row['id'];
            ?>
            <tr<?= $sel ? ' class="table-active"' : '' ?>>
              <td class="small">
                <a href="index.php?course=<?= (int)$cur_course ?>&tab=protokol&protocol=<?= (int)$row['id'] ?>">
                  <?= h($row['period_name'] ?: 'bez okresu') ?>
                </a>
                <div class="text-body-secondary"><?= h(ti_protocol_fill_text($rst)) ?></div>
              </td>
              <td class="small text-nowrap"><span class="badge text-bg-<?= h($st['badge']) ?>"><?= h($st['label']) ?></span></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body usos-noprint">
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="protocol_create">
          <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
          <div class="flex-grow-1">
            <label class="form-label" for="pr-period">Nowy protokół za okres</label>
            <select class="form-select form-select-sm" id="pr-period" name="period_id">
              <option value="0">— bez okresu —</option>
              <?php foreach ($pr_free as $p): ?>
              <option value="<?= (int)$p['id'] ?>">
                <?= h($p['name']) ?> (<?= h(date('d.m.Y', strtotime($p['date_from']))) ?>–<?= h(date('d.m.Y', strtotime($p['date_to']))) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Otwórz protokół</button>
        </form>
        <div class="form-text">Na kurs i okres przypada jeden protokół.</div>
      </div>
    </div>
  </div>

  <!-- Wybrany protokół -->
  <div class="col-lg-8">
    <?php if (!$pr): ?>
    <div class="card"><div class="card-body text-body-secondary small">
      Wybierz protokół z listy albo otwórz nowy dla okresu nauczania.
    </div></div>
    <?php else: ?>
    <?php $st = TI_PROTOCOL_STATUSES[$pr['status']] ?? ['label'=>$pr['status'],'badge'=>'secondary']; ?>
    <div class="card">
      <div class="card-header d-flex align-items-center flex-wrap gap-2">
        <span><?= h($pr['title']) ?></span>
        <span class="badge text-bg-<?= h($st['badge']) ?>"><?= h($st['label']) ?></span>
        <span class="badge text-bg-light border text-dark"><?= h(ti_protocol_fill_text($pr_stats)) ?></span>
        <a href="protokol_pdf.php?id=<?= (int)$pr['id'] ?>" class="btn btn-sm btn-outline-secondary ms-auto usos-noprint">
          <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
        </a>
      </div>

      <?php if ($pr_locked): ?>
      <div class="card-body border-bottom py-2 small">
        <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>
        Zatwierdził: <strong><?= h($pr['approved_name'] ?: '—') ?></strong>,
        <?= h($pr['approved_at'] ? date('d.m.Y H:i', strtotime((string)$pr['approved_at'])) : '—') ?>.
        Ocen nie można zmieniać.
      </div>
      <?php endif; ?>

      <?php if (!empty($pr['unlocked_at'])): ?>
      <div class="card-body border-bottom py-2 small text-body-secondary">
        <i class="bi bi-unlock me-1" aria-hidden="true"></i>
        Odblokowany: <strong><?= h($pr['unlocked_name'] ?: '—') ?></strong>,
        <?= h(date('d.m.Y H:i', strtotime((string)$pr['unlocked_at']))) ?> — powód: <?= h($pr['unlock_reason']) ?>.
      </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
        <input type="hidden" name="protocol_id" value="<?= (int)$pr['id'] ?>">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 usos-sticky">
            <caption class="visually-hidden">Oceny końcowe uczestników w protokole <?= h($pr['title']) ?></caption>
            <thead><tr>
              <th scope="col" style="width:2.5rem">#</th>
              <th scope="col">Uczestnik</th>
              <th scope="col" style="width:9rem">Ocena końcowa</th>
              <th scope="col" style="width:7rem" class="text-nowrap">Śr. z dziennika</th>
              <th scope="col">Uwagi</th>
            </tr></thead>
            <tbody>
              <?php if (!$pr_parts): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Do tej grupy nikt nie jest zapisany.</td></tr>
              <?php endif; ?>
              <?php foreach ($pr_parts as $i => $p):
                $cid = (int)$p['client_id'];
                $e   = $pr_entries[$cid] ?? null;
              ?>
              <tr>
                <td class="small"><?= $i + 1 ?></td>
                <td class="small fw-semibold"><?= h($p['name']) ?></td>
                <td>
                  <?php if ($pr_locked): ?>
                    <strong><?= h($e['value_text'] ?? '—') ?></strong>
                  <?php else: ?>
                    <label class="visually-hidden" for="g-<?= $cid ?>">Ocena końcowa: <?= h($p['name']) ?></label>
                    <input type="text" class="form-control form-control-sm" id="g-<?= $cid ?>"
                           name="grade[<?= $cid ?>]" value="<?= h($e['value_text'] ?? '') ?>"
                           maxlength="3" inputmode="text" autocomplete="off" placeholder="np. 4+">
                  <?php endif; ?>
                </td>
                <td class="small text-nowrap"><?= isset($pr_avgs[$cid]) ? number_format($pr_avgs[$cid], 2, ',', '') : '<span class="text-muted">—</span>' ?></td>
                <td>
                  <?php if ($pr_locked): ?>
                    <span class="small"><?= h($e['note'] ?? '') ?></span>
                  <?php else: ?>
                    <label class="visually-hidden" for="n-<?= $cid ?>">Uwagi: <?= h($p['name']) ?></label>
                    <input type="text" class="form-control form-control-sm" id="n-<?= $cid ?>"
                           name="note[<?= $cid ?>]" value="<?= h($e['note'] ?? '') ?>" maxlength="255">
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="card-body d-flex flex-wrap gap-2 align-items-center usos-noprint">
          <?php if (!$pr_locked): ?>
          <button class="btn btn-primary btn-sm" name="_op" value="protocol_save">
            <i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz protokół
          </button>
          <button class="btn btn-success btn-sm" name="_op" value="protocol_approve"
                  onclick="return confirm('Zatwierdzić protokół? Po zatwierdzeniu nie będzie można zmieniać ocen — odblokowanie wymaga pracownika D3 lub administratora.')">
            <i class="bi bi-check2-square me-1" aria-hidden="true"></i>Zatwierdź protokół
          </button>
          <span class="form-text mb-0">
            Dozwolone wpisy: <strong>1–6</strong> (można z „+" lub „-"), albo
            <?= h(implode(', ', array_keys(TI_PROTOCOL_SPECIAL))) ?>. Puste pole = brak oceny.
          </span>
          <?php else: ?>
          <span class="form-text mb-0"><i class="bi bi-lock me-1" aria-hidden="true"></i>Protokół zamknięty do edycji.</span>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($pr_locked && dyd_is_staff()): ?>
      <div class="card-body border-top usos-noprint">
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end"
              onsubmit="return confirm('Odblokować zatwierdzony protokół? Powód zostanie zapisany.')">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="protocol_unlock">
          <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
          <input type="hidden" name="protocol_id" value="<?= (int)$pr['id'] ?>">
          <div class="flex-grow-1">
            <label class="form-label" for="pr-reason">Powód odblokowania <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control form-control-sm" id="pr-reason" name="reason" required maxlength="255"
                   placeholder="np. korekta oceny po odwołaniu uczestnika">
          </div>
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-unlock me-1" aria-hidden="true"></i>Odblokuj protokół</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <p class="small text-body-secondary mt-2">
      Ocena z protokołu jest oceną <strong>końcową</strong> i nie wchodzi do średniej ważonej
      e-dziennika — kolumna „Śr. z dziennika" pokazuje ją tylko pomocniczo.
      Zatwierdzony protokół jest warunkiem zamknięcia okresu nauczania.
    </p>
    <?php endif; ?>
  </div>
</div>

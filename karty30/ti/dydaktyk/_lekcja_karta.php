<?php
// ── Wejście do lekcji: ?lesson=<id> otwiera kartę lekcji nad wykazem ─────────
// Karta żyje w tej samej zakładce, więc działają te same operacje (obecność,
// edycja, kreator) i ta sama autoryzacja — strona lesson.php w module admina
// wymaga sesji SZO, której prowadzący w panelu nie ma.
$open_id  = (int)($_GET['lesson'] ?? 0);
$open_ses = null;
if ($open_id && dyd_owns_session($uid, $open_id)) {
    $open_ses = db_one(
        "SELECT s.*, c.name AS course_name, c.default_meeting_url, c.instructor_id AS course_instructor_id,
                s.docs_complete AS docs_manual, " . k30_ti_docs_complete_sql() . " AS docs_complete
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
          WHERE s.id = ?",
        [$open_id]
    );
}
if ($open_ses):
    $_o_st    = $STATUS[$open_ses['status']] ?? $STATUS['planned'];
    $_o_date  = strtotime((string)$open_ses['lesson_date']);
    $_o_att   = k30_ti_session_attendance($open_id);
    $_o_curr  = function_exists('k30_ti_session_curriculum_items') ? k30_ti_session_curriculum_items($open_id) : [];
    $_o_meet  = trim((string)($open_ses['meeting_url'] ?: ($open_ses['default_meeting_url'] ?? '')));
    $_o_mat   = trim((string)($open_ses['material_url'] ?? ''));
    $_o_past  = (string)$open_ses['lesson_date'] <= date('Y-m-d');
    $_o_min   = (int)($open_ses['duration_min'] ?? 0);
    $_o_method = ['stacjonarna'=>'stacjonarne','zdalna_zoom'=>'zdalne — Zoom','zdalna_inne'=>'zdalne — inne'][(string)($open_ses['lesson_method'] ?? '')] ?? '';
    $_o_active = array_values(array_filter($_o_att, fn($a) => empty($a['cancelled']) && empty($a['cancel_pending'])));
    $_o_instr_id = (int)($open_ses['instructor_id'] ?? 0);
    $_o_instr_name = '';
    if ($_o_instr_id && $_o_instr_id !== (int)($open_ses['course_instructor_id'] ?? 0)) {
        $_o_instr_name = (string)(db_one("SELECT name FROM users WHERE id=?", [$_o_instr_id])['name'] ?? '');
    }
?>
<div class="card">
  <div class="card-header d-flex align-items-center flex-wrap gap-2">
    <span>Lekcja <?= h(date('d.m.Y', $_o_date)) ?><?= $open_ses['time_from'] ? ', ' . h(substr((string)$open_ses['time_from'],0,5)) : '' ?>
      <?= $open_ses['time_to'] ? '–' . h(substr((string)$open_ses['time_to'],0,5)) : '' ?></span>
    <span class="badge" style="background:<?= h($_o_st['bg']) ?>;color:<?= h($_o_st['color']) ?>;border:1px solid <?= h($_o_st['color']) ?>44"><?= h($_o_st['label']) ?></span>
    <a href="<?= h(dyd_back($cur_course, 'lekcje')) ?>" class="btn btn-sm btn-outline-secondary ms-auto usos-noprint">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do wykazu
    </a>
  </div>
  <div class="card-body">
    <dl class="row small mb-3">
      <dt class="col-sm-3">Grupa</dt><dd class="col-sm-9"><?= h($open_ses['course_name']) ?></dd>
      <dt class="col-sm-3">Termin</dt>
      <dd class="col-sm-9">
        <?= h(date('d.m.Y', $_o_date)) ?> (<?= h($_days_pl[(int)date('w', $_o_date)]) ?>)<?php
        if ($open_ses['time_from']): ?>, <?= h(substr((string)$open_ses['time_from'],0,5)) ?><?php
          if ($open_ses['time_to']): ?>–<?= h(substr((string)$open_ses['time_to'],0,5)) ?><?php endif;
        endif; ?>
        <?= $_o_min > 0 ? ' · ' . $_o_min . ' min' : '' ?>
      </dd>
      <dt class="col-sm-3">Temat</dt>
      <dd class="col-sm-9"><?= trim((string)($open_ses['topic'] ?? '')) !== '' ? h($open_ses['topic']) : '<span class="text-muted">nie wpisano</span>' ?></dd>
      <?php if ($_o_method !== ''): ?>
      <dt class="col-sm-3">Forma</dt><dd class="col-sm-9"><?= h($_o_method) ?></dd>
      <?php endif; ?>
      <?php if ($_o_instr_name !== ''): ?>
      <dt class="col-sm-3">Prowadzący</dt>
      <dd class="col-sm-9"><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
        <i class="bi bi-person-workspace me-1" aria-hidden="true"></i>Zastępstwo: <?= h($_o_instr_name) ?>
      </span></dd>
      <?php endif; ?>
      <?php if (in_array($open_ses['status'], K30_TI_HELD_STATUSES, true)): ?>
      <dt class="col-sm-3">Dokumentacja</dt>
      <dd class="col-sm-9">
        <?php if (!empty($open_ses['docs_complete'])): ?>
        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><i class="bi bi-check-square-fill me-1" aria-hidden="true"></i>uzupełniona</span>
        <?php if (empty($open_ses['docs_manual'])): ?><span class="small text-body-secondary ms-1">(obecność i temat wpisane)</span><?php endif; ?>
        <?php else: ?>
        <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>niekompletna</span>
        <?php endif; ?>
      </dd>
      <?php endif; ?>
      <?php if (trim((string)($open_ses['notes'] ?? '')) !== ''): ?>
      <dt class="col-sm-3">Notatka dla kursantów</dt><dd class="col-sm-9"><?= nl2br(h($open_ses['notes'])) ?></dd>
      <?php endif; ?>
      <?php if (trim((string)($open_ses['instructor_notes'] ?? '')) !== ''): ?>
      <dt class="col-sm-3">Notatka prowadzącego</dt><dd class="col-sm-9"><?= nl2br(h($open_ses['instructor_notes'])) ?></dd>
      <?php endif; ?>
      <?php if ((string)$open_ses['status'] === 'cancelled' && trim((string)($open_ses['cancel_reason'] ?? '')) !== ''): ?>
      <dt class="col-sm-3">Powód odwołania</dt><dd class="col-sm-9"><?= h($open_ses['cancel_reason']) ?></dd>
      <?php endif; ?>
      <?php if ($_o_curr): ?>
      <dt class="col-sm-3">Punkty sylabusa</dt>
      <dd class="col-sm-9"><?= h(implode(' · ', array_map(fn($c) => (string)$c['title'], $_o_curr))) ?></dd>
      <?php endif; ?>
    </dl>

    <div class="d-flex flex-wrap gap-2 usos-noprint">
      <?php if ($_o_meet !== '' && (string)$open_ses['status'] !== 'cancelled'): ?>
      <a href="<?= h($_o_meet) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">
        <i class="bi bi-camera-video-fill me-1" aria-hidden="true"></i>Wejdź na spotkanie
      </a>
      <?php endif; ?>
      <?php if ($_o_mat !== ''): ?>
      <a href="<?= h($_o_mat) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info">
        <i class="bi bi-file-earmark-arrow-up me-1" aria-hidden="true"></i>Materiał
      </a>
      <?php endif; ?>
      <?php if ($_o_past && (string)$open_ses['status'] === 'planned'): ?>
      <button type="button" class="btn btn-sm btn-success" onclick="wizOpenExt(<?= (int)$open_id ?>)">
        <i class="bi bi-journal-text me-1" aria-hidden="true"></i>Uzupełnij obecność i temat
      </button>
      <?php endif; ?>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#edL<?= (int)$open_id ?>">
        <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj lekcję
      </button>
      <?php if (dyd_is_staff()): ?>
      <a href="lekcja_pdf.php?id=<?= (int)$open_id ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-printer me-1" aria-hidden="true"></i>Drukuj kartę lekcji
      </a>
      <?php endif; ?>
      <?php if (dyd_is_staff() && in_array($open_ses['status'], K30_TI_HELD_STATUSES, true)
                && (empty($open_ses['docs_complete']) || !empty($open_ses['docs_manual']))): // uzupełniona automatycznie (obecność + temat) — ręczna flaga nic nie zmienia ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="toggle_docs_complete">
        <input type="hidden" name="_tab" value="lekcje">
        <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
        <input type="hidden" name="session_id" value="<?= (int)$open_id ?>">
        <input type="hidden" name="lesson" value="<?= (int)$open_id ?>">
        <button type="submit" class="btn btn-sm <?= !empty($open_ses['docs_complete']) ? 'btn-outline-success' : 'btn-outline-danger' ?>">
          <i class="bi bi-<?= !empty($open_ses['docs_complete']) ? 'check-square-fill' : 'square' ?> me-1" aria-hidden="true"></i>
          <?= !empty($open_ses['docs_complete']) ? 'Dokumentacja uzupełniona' : 'Oznacz dokumentację jako uzupełnioną' ?>
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ((string)$open_ses['status'] !== 'remote_material' && $_o_active):
    // Lekcja jeszcze nieuzupełniona — domyślnie wszyscy obecni, odznacz wyjątki
    // (tak samo jak w kreatorze). Już odbytą lekcję pokazujemy z rzeczywistym
    // zapisanym stanem, żeby korekta niczego nie nadpisała po cichu.
    $_o_default_present = (string)$open_ses['status'] === 'planned';
  ?>
  <div class="card-header border-top d-flex align-items-center flex-wrap gap-2">
    <span>Obecność</span>
    <div class="d-flex gap-2 ms-auto usos-noprint">
      <button type="button" class="btn btn-outline-success btn-sm py-0" onclick="dydAttSetAll('#att-form-<?= (int)$open_id ?>',true)">
        <i class="bi bi-check-all me-1" aria-hidden="true"></i>Wszyscy
      </button>
      <button type="button" class="btn btn-outline-secondary btn-sm py-0" onclick="dydAttSetAll('#att-form-<?= (int)$open_id ?>',false)">
        <i class="bi bi-square me-1" aria-hidden="true"></i>Wyczyść
      </button>
    </div>
  </div>
  <form method="post" id="att-form-<?= (int)$open_id ?>">
    <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op"        value="save_attendance">
    <input type="hidden" name="session_id" value="<?= (int)$open_id ?>">
    <input type="hidden" name="course_id"  value="<?= (int)$cur_course ?>">
    <input type="hidden" name="_tab"       value="lekcje">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Lista obecności na lekcji <?= h(date('d.m.Y', $_o_date)) ?></caption>
        <thead><tr>
          <th scope="col" style="width:3rem">Obecny</th>
          <th scope="col">Uczestnik</th>
          <th scope="col" style="width:12rem">Uwagi</th>
        </tr></thead>
        <tbody>
          <?php foreach ($_o_active as $_a): $_a_present = $_o_default_present ? true : !empty($_a['attended']); ?>
          <tr>
            <td>
              <input type="checkbox" class="form-check-input dyd-att-cb" name="attended[]" value="<?= (int)$_a['client_id'] ?>"
                     <?= $_a_present ? 'checked' : '' ?>
                     aria-label="Obecność: <?= h($_a['client_name']) ?>">
            </td>
            <td class="small"><?= h($_a['client_name']) ?></td>
            <td class="small text-body-secondary">
              <?= !empty($_a['no_show']) ? 'nieobecność nieusprawiedliwiona' : '' ?>
              <?= trim((string)($_a['ind_notes'] ?? '')) !== '' ? h($_a['ind_notes']) : '' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-body d-flex flex-wrap gap-2 align-items-center usos-noprint">
      <button class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz obecność</button>
      <span class="form-text mb-0">
        <?= $_o_default_present ? 'Domyślnie wszyscy obecni — odznacz nieobecnych. ' : '' ?>Zapis obecności oznacza lekcję jako odbytą.
        <?php if (!$_o_past): ?><strong>Lekcji z przyszłości nie da się rozliczyć</strong> — zapis zostanie odrzucony.<?php endif; ?>
      </span>
    </div>
  </form>
  <script>
  function dydAttSetAll(formSel, val) {
    document.querySelectorAll(formSel + ' .dyd-att-cb').forEach(function(cb){ cb.checked = val; });
  }
  </script>
  <?php elseif ((string)$open_ses['status'] === 'remote_material'): ?>
  <div class="card-body border-top small text-body-secondary">
    Praca własna prowadzącego — bez listy obecności, liczona do rozliczenia.
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

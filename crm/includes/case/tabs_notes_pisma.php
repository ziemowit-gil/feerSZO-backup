<?php
/**
 * crm/includes/case/tabs_notes_pisma.php — Zakładki „Notatki" i „Pisma" w widoku sprawy.
 *
 * Wydzielone z crm/cases/view.php: plik miał 2279 wiersze i zmiana w jednej
 * sekcji wymagała przewijania przez wszystkie pozostałe.
 *
 * Włączany przez include w zakresie crm/cases/view.php — korzysta z jego
 * zmiennych ($case, $id, $can_write, ...). Nie wywołuj samodzielnie.
 */
if (!isset($case)) { http_response_code(400); exit; }
?>
  <!-- ── NOTATKI ─────────────────────────────────────────────────────────── -->
  <div class="tab-pane fade show active" id="notes" role="tabpanel" aria-labelledby="case-tab-notes-btn" tabindex="0">
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-chat-dots cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Notatki</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($notes) ?></span></div>
      </div>

      <?php if ($notes): ?>
      <?php foreach ($notes as $n):
        $un = trim(($n['first_name']??'').' '.($n['last_name']??'')) ?: ($n['user_name']??'?');
      ?>
      <div class="case-note">
        <div class="case-note-body"><?= h($n['body']) ?></div>
        <div class="case-note-meta d-flex justify-content-between">
          <span><i class="bi bi-person me-1"></i><?= h($un) ?> · <?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></span>
          <?php if ($can_write && ($n['created_by']==(current_user()['id']??0) || is_admin())): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć notatkę?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="delete_note">
            <input type="hidden" name="note_id" value="<?= (int)$n['id'] ?>">
            <button type="submit" class="btn btn-link btn-sm text-danger py-0 px-1" style="font-size:.72rem">
              <i class="bi bi-trash"></i> Usuń
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="text-muted text-center py-3" style="font-size:.85rem">
        <i class="bi bi-chat d-block mb-2 opacity-25" style="font-size:1.5rem"></i>
        Brak notatek — dodaj pierwszą poniżej.
      </div>
      <?php endif; ?>

      <?php if ($can_write): ?>
      <form method="post" class="mt-3 pt-3 border-top" id="note-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="add_note">
        <label class="form-label fw-semibold small">Dodaj notatkę</label>
        <textarea name="note_body" class="form-control form-control-sm mb-2" rows="3"
                  placeholder="Wpisz notatkę do sprawy… (Ctrl+Enter zapisuje)"
                  id="noteBody" required></textarea>
        <button type="submit" class="btn btn-warning btn-sm">
          <i class="bi bi-chat-dots me-1"></i>Dodaj notatkę
        </button>
      </form>
      <script>
      document.getElementById('noteBody')?.addEventListener('keydown', function(e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
          this.closest('form').submit();
        }
      });
      </script>
      <?php endif; ?>
    </div></div>
  </div>

  <!-- ── PISMA ─────────────────────────────────────────────────────────── -->
  <div class="tab-pane fade" id="pisma" role="tabpanel" aria-labelledby="case-tab-pisma-btn" tabindex="0">
    <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-envelope cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Pisma</h2>
        <div class="cv-shead__aside">
          <span class="cv-count"><?= count($letters) ?></span>
          <?php if ($can_write): ?>
          <button type="button" class="btn btn-warning btn-sm py-0 px-2"
                  data-bs-toggle="modal" data-bs-target="#modalPismo">
            <i class="bi bi-plus me-1" aria-hidden="true"></i>Nowe pismo
          </button>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($letters): ?>
      <?php foreach ($letters as $l):
        $lt  = LETTER_TYPES[$l['typ_pisma']] ?? LETTER_TYPES['inne'];
        $ld  = LETTER_DIRECTIONS[$l['kierunek']] ?? ['label'=>$l['kierunek'],'class'=>'secondary','icon'=>'bi-arrow-right'];
        $is_edoreczenia = ($l['sposob_doreczenia'] === 'edoreczenia');
        $has_nadania    = !empty($l['nr_nadania']) && in_array($l['sposob_doreczenia'], ['kurier','poczta']);
        $ldata = json_encode($l, JSON_HEX_APOS | JSON_HEX_QUOT);
      ?>
      <div style="background:#F8FAFF;border:1px solid #DBEAFE;border-radius:8px;padding:.65rem 1rem;margin-bottom:.5rem;font-size:.84rem">
        <div class="d-flex align-items-start gap-2">
          <div style="flex:1;min-width:0">
            <div class="fw-semibold" style="color:#1E3A5F"><?= h($l['tytul']) ?></div>
            <div class="d-flex flex-wrap gap-1 mt-1">
              <span class="badge bg-<?= $ld['class'] ?> bg-opacity-15 text-<?= $ld['class'] ?> border border-<?= $ld['class'] ?>" style="font-size:.7rem">
                <i class="bi <?= $ld['icon'] ?> me-1"></i><?= $ld['label'] ?>
              </span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.7rem">
                <i class="bi <?= $lt['icon'] ?> me-1"></i><?= $lt['label'] ?>
              </span>
              <?php if (!empty($l['pilnosc']) && $l['pilnosc'] !== 'zwykłe'): ?>
              <?php $pilnosc_cls = ['pilne'=>'warning','poufne'=>'danger','ściśle_tajne'=>'danger'][$l['pilnosc']] ?? 'secondary'; ?>
              <span class="badge bg-<?= $pilnosc_cls ?> bg-opacity-15 text-<?= $pilnosc_cls ?> border border-<?= $pilnosc_cls ?>" style="font-size:.7rem;text-transform:uppercase">
                <?= h($l['pilnosc']) ?>
              </span>
              <?php endif; ?>
              <?php if (!empty($l['sygnatura'])): ?>
              <code style="font-size:.65rem;color:#6B7280;background:#F3F4F6;padding:.1rem .35rem;border-radius:4px"><?= h($l['sygnatura']) ?></code>
              <?php endif; ?>
              <?php if ($is_edoreczenia): ?>
              <span class="badge" style="font-size:.7rem;background:#EEF2FF;color:#4338CA;border:1px solid #C7D2FE">
                <i class="bi bi-shield-check me-1"></i>eDoręczenia
              </span>
              <?php endif; ?>
            </div>
            <div style="font-size:.72rem;color:#5E6470;margin-top:.25rem;display:flex;flex-wrap:wrap;gap:0 .75rem">
              <?php if ($l['nadawca']): ?><span><?= h($l['nadawca']) ?> → <?= h($l['odbiorca'] ?? '—') ?></span><?php endif; ?>
              <?= $l['data_pisma'] ? '<span><i class="bi bi-calendar3 me-1"></i>' . date('d.m.Y', strtotime($l['data_pisma'])) . '</span>' : '' ?>
              <?php if (!empty($l['termin_odpowiedzi'])): ?>
              <span style="color:<?= $l['termin_odpowiedzi'] < date('Y-m-d') ? '#DC2626' : '#D97706' ?>">
                <i class="bi bi-alarm me-1"></i>do <?= date('d.m.Y', strtotime($l['termin_odpowiedzi'])) ?>
              </span>
              <?php endif; ?>
              <?php if (!empty($l['sposob_doreczenia'])): ?>
              <span><i class="bi bi-send me-1"></i><?= h($l['sposob_doreczenia']) ?></span>
              <?php endif; ?>
              <?= $l['created_by_name'] ? '<span>' . h($l['created_by_name']) . '</span>' : '' ?>
            </div>
            <?php if ($has_nadania): ?>
            <div style="margin-top:.35rem;display:inline-flex;align-items:center;gap:.4rem;background:#FFF7ED;border:1px solid #FED7AA;border-radius:6px;padding:.2rem .55rem;font-size:.73rem">
              <i class="bi bi-truck text-warning"></i>
              <span class="text-muted">Nr nadania:</span>
              <strong style="color:#92400E;font-family:monospace"><?= h($l['nr_nadania']) ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($is_edoreczenia && (!empty($l['adres_edoreczenia']) || !empty($l['edoreczenia_ref']))): ?>
            <div style="margin-top:.35rem;display:inline-flex;align-items:center;gap:.4rem;background:#EEF2FF;border:1px solid #C7D2FE;border-radius:6px;padding:.2rem .55rem;font-size:.73rem">
              <i class="bi bi-shield-check" style="color:#4338CA"></i>
              <?php if (!empty($l['adres_edoreczenia'])): ?>
              <span class="text-muted">ADE:</span>
              <code style="font-size:.7rem;color:#3730A3"><?= h($l['adres_edoreczenia']) ?></code>
              <?php endif; ?>
              <?php if (!empty($l['edoreczenia_ref'])): ?>
              <span class="text-muted ms-1">Ref:</span>
              <code style="font-size:.7rem;color:#3730A3"><?= h($l['edoreczenia_ref']) ?></code>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($l['tresc']): ?>
            <div style="font-size:.78rem;color:#374151;margin-top:.3rem;white-space:pre-wrap;max-height:3.5em;overflow:hidden"><?= h(mb_substr(strip_tags($l['tresc']),0,200)) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-1 flex-shrink-0 align-items-start mt-1">
            <?php if ($l['plik']): ?>
            <a href="<?= h(letter_file_url($l['plik'])) ?>" target="_blank" rel="noopener"
               class="btn btn-sm btn-outline-primary py-0 px-2"
               aria-label="Pobierz załącznik: <?= h($l['temat'] ?: $l['typ']) ?>">
              <i class="bi bi-download" aria-hidden="true"></i>
            </a>
            <?php endif; ?>
            <?php if ($can_write): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                    aria-label="Edytuj pismo: <?= h($l['temat'] ?: $l['typ']) ?>"
                    onclick="pismoEdit(<?= $ldata ?>)">
              <i class="bi bi-pencil" aria-hidden="true"></i>
            </button>
            <?php endif; ?>
            <?php if ($can_write && ($l['created_by']==$uid || is_admin())): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pismo?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete_pismo">
              <input type="hidden" name="letter_id" value="<?= (int)$l['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2"
                      aria-label="Usuń pismo: <?= h($l['temat'] ?: $l['typ']) ?>">
                <i class="bi bi-trash" aria-hidden="true"></i>
              </button>
            </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php else: ?>
      <div class="cv-empty">
        <i class="bi bi-envelope" aria-hidden="true"></i>
        Brak pism — użyj przycisku „Nowe pismo", aby dodać.
      </div>
      <?php endif; ?>
    </div></div>
  </div>

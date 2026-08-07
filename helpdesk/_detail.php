<?php
/**
 * helpdesk/_detail.php — wspólny fragment szczegółów zgłoszenia.
 *
 * Używany przez:
 *   - helpdesk/view.php (strona samodzielna)
 *   - helpdesk/index.php → tryb pane (?_pane=1) ładowany przez AJAX
 *
 * Wymaga w zasięgu: $ticket, $messages, $atts, $operators, $escalations, $vendor_cases, $is_op, $u, $uid.
 * W trybie pane ($GLOBALS['hd_pane_mode']) pomija flash i skrypty inline.
 */
$hd_pane       = !empty($GLOBALS['hd_pane_mode']);
$view_action   = APP_URL . '/helpdesk/view.php?id=' . (int)$ticket['id'];
$hd_editor_id  = 'hdQuill_' . (int)$ticket['id'];
$db_macros     = $is_op ? hd_macros_active() : [];

function _hd_det_initials(string $name): string {
    $words = array_values(array_filter(explode(' ', trim($name))));
    if (!$words) return '?';
    if (count($words) === 1) return mb_strtoupper(mb_substr($words[0], 0, 2));
    return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr($words[count($words)-1], 0, 1));
}
function _hd_det_avcolor(string $name): string {
    $colors = ['#6366f1','#8b5cf6','#ec4899','#f59e0b','#10b981','#3b82f6','#ef4444','#06b6d4','#84cc16','#f97316'];
    return $colors[abs(crc32($name)) % count($colors)];
}

$req_acct_name  = trim((string)($ticket['requester_account_name'] ?? ''));
$req_typed_name = trim((string)($ticket['requester_name'] ?? ''));
$hd_on_behalf   = ($ticket['requester_role'] ?? '') === 'admin'
    && $req_acct_name !== ''
    && mb_strtolower($req_acct_name) !== mb_strtolower($req_typed_name);
$merged_children = $is_op ? db_all("SELECT id, number FROM helpdesk_tickets WHERE merged_into=? ORDER BY id", [(int)$ticket['id']]) : [];
?>
<style>
/* ── Detail: layout specifics ─────────────────────────────── */
.hd-det-header {
  padding: .85rem 1rem .8rem;
  border-bottom: 1px solid var(--hd-bd-l);
  background: var(--hd-panel);
  margin: -.65rem -1rem .85rem;
}
@media (max-width: 767px) { .hd-det-header { margin: -.65rem -.8rem .75rem; } }

.hd-det-num {
  font-family: monospace; font-size: .72rem; font-weight: 700;
  color: var(--hd-accent); background: var(--hd-accent-l);
  padding: .12rem .45rem; border-radius: 5px; white-space: nowrap; letter-spacing: .03em;
}
.hd-det-title {
  font-size: 1rem; font-weight: 800; color: var(--hd-tx);
  line-height: 1.3; margin: .3rem 0 0;
}
.hd-det-meta {
  display: flex; flex-wrap: wrap; align-items: center;
  gap: .3rem .6rem; margin-top: .4rem;
  font-size: .77rem; color: var(--hd-tx2);
}
.hd-det-meta i { color: var(--hd-tx3); }

/* ── Sidebar karta ─────────────────────────────────────────── */
.hd-side-card {
  background: var(--hd-panel);
  border: 1px solid var(--hd-bd-l);
  border-radius: 10px;
  overflow: hidden;
  margin-bottom: .65rem;
  box-shadow: var(--hd-shadow);
}
.hd-side-card-head {
  display: flex; align-items: center; gap: .4rem;
  padding: .5rem .75rem;
  font-size: .8rem; font-weight: 700; color: var(--hd-tx);
  background: var(--hd-bg); border-bottom: 1px solid var(--hd-bd-l);
}
.hd-side-card-head i { color: var(--hd-accent); font-size: .9rem; }
.hd-side-card-body { padding: .65rem .75rem; font-size: .8rem; }
.hd-side-card-body .form-control, .hd-side-card-body .form-select { font-size: .8rem; }
.hd-side-dt { color: var(--hd-tx3); font-size: .72rem; white-space: nowrap; vertical-align: top; padding: 3px 8px 3px 0; }
.hd-side-dd { padding: 3px 0; }
.hd-side-stat-btn {
  display: flex; align-items: center; gap: .4rem;
  width: 100%; padding: .35rem .6rem; margin-bottom: .22rem;
  border-radius: 7px; border: 1px solid var(--hd-bd);
  background: var(--hd-panel); color: var(--hd-tx); cursor: pointer;
  font-size: .79rem; transition: all .1s; text-align: left;
}
.hd-side-stat-btn:hover { background: var(--hd-accent-l); border-color: var(--hd-accent-m); color: var(--hd-accent); }
.hd-side-stat-btn i { flex-shrink: 0; }

/* ── Makra (nowy styl: panel kart) ──────────────────────────── */
.hd-macro-panel {
  display: none; padding: .55rem 0 0;
}
.hd-macro-panel.open { display: block; animation: hdMacroIn .15s ease; }
@keyframes hdMacroIn { from { opacity:0; transform:translateY(-4px); } to { opacity:1; transform:none; } }
.hd-macro-grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(185px, 1fr)); gap: .45rem;
}
.hd-macro-card {
  border: 1px solid var(--hd-bd); border-radius: 9px; padding: .55rem .7rem;
  cursor: pointer; background: var(--hd-panel); transition: all .12s;
}
.hd-macro-card:hover { border-color: var(--hd-accent); background: var(--hd-accent-l); }
.hd-macro-card-title { font-size: .8rem; font-weight: 700; color: var(--hd-tx); margin-bottom: .2rem; }
.hd-macro-card-preview {
  font-size: .72rem; color: var(--hd-tx3); line-height: 1.35;
  overflow: hidden; display: -webkit-box;
  -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}
.hd-macro-toggle {
  display: inline-flex; align-items: center; gap: .3rem;
  padding: .28rem .65rem; border-radius: 7px; border: 1px solid var(--hd-bd);
  background: var(--hd-panel); color: var(--hd-tx2); cursor: pointer; font-size: .78rem;
  transition: all .1s;
}
.hd-macro-toggle:hover, .hd-macro-toggle.open { background: var(--hd-accent-l); border-color: var(--hd-accent-m); color: var(--hd-accent); }
</style>

<!-- ── Nagłówek zgłoszenia ─────────────────────────────────────────────────── -->
<div class="hd-det-header">
  <div class="d-flex align-items-start gap-2 flex-wrap">
    <?php if ($hd_pane): ?>
    <button type="button" class="btn btn-sm btn-outline-secondary d-lg-none flex-shrink-0" data-hd-back aria-label="Wróć do listy">
      <i class="bi bi-arrow-left"></i>
    </button>
    <?php else: ?>
    <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary flex-shrink-0" aria-label="Wróć">
      <i class="bi bi-arrow-left"></i>
    </a>
    <?php endif; ?>
    <div class="flex-grow-1 min-w-0">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
        <span class="hd-det-num"><?= h($ticket['number']) ?></span>
        <?= hd_status_badge($ticket['status']) ?>
        <?= hd_priority_badge($ticket['priority']) ?>
        <span class="badge bg-light text-secondary border" style="font-size:.7rem">
          <?= h(HD_CATEGORIES[$ticket['category']] ?? $ticket['category']) ?>
        </span>
      </div>
      <div class="hd-det-title"><?= h($ticket['title']) ?></div>
      <div class="hd-det-meta">
        <span><i class="bi bi-person me-1"></i><?= h($ticket['requester_name']) ?></span>
        <?php if ($hd_on_behalf): ?>
        <span class="badge bg-secondary-subtle text-secondary-emphasis border" style="font-size:.7rem"
              title="Dodane przez admin <?= h($req_acct_name) ?>">
          <i class="bi bi-person-badge me-1"></i>W imieniu
        </span>
        <?php endif; ?>
        <?php if ($ticket['requester_email']): ?>
        <span class="font-monospace" style="font-size:.74rem"><?= h($ticket['requester_email']) ?></span>
        <?php endif; ?>
        <span><i class="bi bi-calendar3 me-1"></i><?= date_pl(substr($ticket['created_at'], 0, 10)) ?></span>
        <?php if ($ticket['assigned_name']): ?>
        <span style="color:#10b981"><i class="bi bi-person-check me-1"></i><?= h($ticket['assigned_name']) ?></span>
        <?php else: ?>
        <span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Nieprzypisane</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (!$hd_pane) echo flash_html(); ?>

<?php if (!empty($ticket['merged_into'])):
  $mt = db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [(int)$ticket['merged_into']]); ?>
<div class="alert alert-secondary py-2 small d-flex align-items-center gap-2 mb-3">
  <i class="bi bi-union flex-shrink-0"></i>
  <div>Zgłoszenie połączone ze zgłoszeniem głównym
    <a href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$ticket['merged_into'] ?>" class="fw-bold"><?= h($mt['number'] ?? '') ?></a>.
    Dalsza korespondencja odbywa się tam.</div>
</div>
<?php endif; ?>

<?php if ($merged_children): ?>
<div class="alert alert-light border py-2 small d-flex align-items-start gap-2 mb-3">
  <i class="bi bi-diagram-3 mt-1 flex-shrink-0"></i>
  <div>Połączone z tym zgłoszeniem:
    <?php foreach ($merged_children as $c): ?>
    <a href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$c['id'] ?>" class="badge bg-light text-dark border text-decoration-none ms-1"><?= h($c['number']) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">

<!-- ── Kolumna główna ─────────────────────────────────────────────────────── -->
<div class="col-lg-8">

  <!-- Wiadomości -->
  <div class="mb-3">
    <?php
    $total        = count($messages);
    $recent_keep  = 3;
    $hidden_count = ($total > $recent_keep + 1) ? $total - $recent_keep : 0;
    ?>
    <?php if ($hidden_count): ?>
    <div class="text-center mb-3">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-hd-older style="font-size:.8rem">
        <i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze (<?= $hidden_count ?>)
      </button>
    </div>
    <?php endif; ?>

    <?php foreach ($messages as $i => $m):
      $is_req      = (int)$m['user_id'] === (int)$ticket['requester_id'];
      $is_sys      = empty($m['user_id']);
      $msg_class   = $m['is_internal'] ? 'hd-msg-intern' : ($is_req ? 'hd-msg-user' : 'hd-msg-op');
      $av_class    = $is_sys ? 'hd-msg-av-sys' : ($is_req ? 'hd-msg-av-req' : 'hd-msg-av-op');
      $msg_atts    = array_filter($atts, fn($a) => (int)$a['message_id'] === (int)$m['id']);
      $body_html   = (strpos($m['body'], '<') !== false) ? $m['body'] : nl2br(h($m['body']));
      $long        = mb_strlen(strip_tags($m['body'])) > 600 || substr_count($m['body'], "\n") > 10;
      $author_name = $m['user_name'] ?: 'System';
      $initials    = $is_sys ? '⚙' : _hd_det_initials($author_name);
      $avcolor     = _hd_det_avcolor($author_name);
      if ($hidden_count && $i === 0)             echo '<div data-hd-olderwrap class="d-none">';
      if ($hidden_count && $i === $hidden_count)  echo '</div>';
    ?>
    <div class="hd-msg-wrap">
      <div class="hd-msg-av <?= $av_class ?>"
           style="<?= $is_sys ? '' : 'background:'._hd_det_avcolor($author_name).';' ?>"
           aria-hidden="true"><?= $is_sys ? '<i class="bi bi-gear-fill" style="font-size:.7rem"></i>' : h($initials) ?></div>
      <div>
        <div class="d-flex align-items-baseline gap-2 mb-1 flex-wrap">
          <span style="font-size:.8rem;font-weight:700;color:var(--hd-tx)"><?= h($author_name) ?></span>
          <?php if ($m['is_internal']): ?>
          <span class="badge text-bg-warning" style="font-size:.65rem">Notatka wewnętrzna</span>
          <?php endif; ?>
          <span style="font-size:.71rem;color:var(--hd-tx3);margin-left:auto"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></span>
        </div>
        <div class="hd-msg <?= $msg_class ?>">
          <div class="hd-body<?= $long ? ' hd-clamp' : '' ?>"><?= $body_html ?></div>
          <?php if ($long): ?>
          <button type="button" class="btn btn-link btn-sm p-0 mt-1" style="font-size:.77rem" data-hd-more>Pokaż całość</button>
          <?php endif; ?>
          <?php if ($msg_atts): ?>
          <div class="mt-2 pt-2 border-top d-flex flex-wrap gap-1" style="border-color:rgba(0,0,0,.06)!important">
            <?php foreach ($msg_atts as $a): ?>
            <a href="<?= APP_URL ?>/helpdesk/attachment.php?id=<?= $a['id'] ?>"
               class="badge bg-light text-dark border text-decoration-none" style="font-size:.75rem">
              <i class="bi bi-paperclip me-1"></i><?= h($a['original_name']) ?>
            </a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <?php $init_atts = array_filter($atts, fn($a) => $a['message_id'] === null); ?>
    <?php if ($init_atts): ?>
    <div class="mb-3 mt-1 ps-4 ms-2">
      <div style="font-size:.73rem;color:var(--hd-tx3);margin-bottom:.3rem"><i class="bi bi-paperclip me-1"></i>Załączniki ze zgłoszenia:</div>
      <div class="d-flex flex-wrap gap-1">
        <?php foreach ($init_atts as $a): ?>
        <a href="<?= APP_URL ?>/helpdesk/attachment.php?id=<?= $a['id'] ?>"
           class="badge bg-light text-dark border text-decoration-none">
          <?= h($a['original_name']) ?><span class="text-muted ms-1">(<?= round($a['file_size'] / 1024) ?> KB)</span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Formularz odpowiedzi -->
  <?php if ($ticket['status'] !== 'zamknięte'): ?>
  <div class="hd-reply-box">
    <div class="hd-reply-hd">
      <i class="bi bi-reply-fill me-1" style="color:var(--hd-accent)"></i>Dodaj wiadomość
      <?php if ($is_op && $db_macros): ?>
      <button type="button" class="hd-macro-toggle ms-auto" aria-expanded="false" aria-controls="hd_macro_panel_<?= (int)$ticket['id'] ?>">
        <i class="bi bi-card-text"></i>Gotowe odpowiedzi
      </button>
      <?php endif; ?>
    </div>

    <?php if ($is_op && $db_macros): ?>
    <div class="hd-macro-panel px-3 py-2 border-bottom" id="hd_macro_panel_<?= (int)$ticket['id'] ?>" style="border-color:var(--hd-bd-l)!important">
      <div class="hd-macro-grid">
        <?php foreach ($db_macros as $mac):
          $preview_text = strip_tags($mac['body']);
          $preview_text = mb_strlen($preview_text) > 90 ? mb_substr($preview_text, 0, 87) . '…' : $preview_text;
        ?>
        <div class="hd-macro-card" role="button" tabindex="0"
             data-body="<?= h($mac['body']) ?>"
             aria-label="Wstaw: <?= h($mac['title']) ?>">
          <div class="hd-macro-card-title"><?= h($mac['title']) ?></div>
          <div class="hd-macro-card-preview"><?= h($preview_text) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="hd-reply-body">
      <form method="post" action="<?= h($view_action) ?>" enctype="multipart/form-data" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <!-- Ukryte pole z HTML z Quilla -->
        <input type="hidden" name="msg_body" id="<?= $hd_editor_id ?>_hidden">

        <div class="d-flex align-items-center justify-content-between mb-1">
          <span style="font-size:.76rem;color:var(--hd-tx3)">Treść <span class="text-danger">*</span></span>
          <button type="button" class="btn btn-sm btn-link p-0 text-secondary hd-fs-btn"
                  data-target="<?= $hd_editor_id ?>_wrap"
                  title="Pełny ekran" aria-label="Otwórz edytor na pełny ekran">
            <i class="bi bi-fullscreen" aria-hidden="true"></i>
          </button>
        </div>

        <div class="hd-quill-wrap mb-2" id="<?= $hd_editor_id ?>_wrap">
          <div id="<?= $hd_editor_id ?>" style="min-height:110px" aria-label="Treść odpowiedzi" aria-required="true"></div>
        </div>
        <div id="<?= $hd_editor_id ?>_err" class="invalid-feedback d-none mb-2">Treść nie może być pusta.</div>

        <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
          <?php if ($is_op): ?>
          <label class="d-flex align-items-center gap-1" style="font-size:.79rem;cursor:pointer;color:var(--hd-tx2)">
            <input class="form-check-input mt-0" type="checkbox" name="is_internal"
                   id="is_internal_<?= (int)$ticket['id'] ?>" value="1">
            <i class="bi bi-lock text-warning" style="font-size:.8rem"></i>Notatka wewnętrzna
          </label>
          <?php endif; ?>
          <label class="ms-auto" style="font-size:.79rem;color:var(--hd-tx2)">
            <input id="hd_att_<?= (int)$ticket['id'] ?>" name="msg_attachments[]" type="file"
                   class="visually-hidden" multiple
                   accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv">
            <span class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('hd_att_<?= (int)$ticket['id'] ?>').click()" style="font-size:.77rem">
              <i class="bi bi-paperclip me-1"></i>Załącznik
            </span>
          </label>
          <button type="submit" name="_add_msg" class="btn btn-sm btn-primary px-3">
            <i class="bi bi-send-fill me-1"></i>Wyślij
          </button>
        </div>

        <!-- Podgląd wybranych plików -->
        <div id="hd_att_preview_<?= (int)$ticket['id'] ?>" class="d-flex flex-wrap gap-1 mt-1" style="font-size:.75rem"></div>
      </form>
    </div>
  </div>
  <script>
  (function(){
    var inp = document.getElementById('hd_att_<?= (int)$ticket['id'] ?>');
    var pre = document.getElementById('hd_att_preview_<?= (int)$ticket['id'] ?>');
    if (inp && pre) inp.addEventListener('change', function(){
      pre.innerHTML = Array.from(inp.files).map(function(f){
        return '<span class="badge bg-light text-dark border"><i class="bi bi-paperclip me-1"></i>' + f.name + '</span>';
      }).join('');
    });
  })();
  </script>
  <?php else: ?>
  <div class="hd-side-card mb-3">
    <div class="hd-side-card-body d-flex align-items-center gap-2" style="color:var(--hd-tx2);font-size:.82rem">
      <i class="bi bi-lock" style="color:var(--hd-tx3)"></i>Zgłoszenie zamknięte — nie można dodawać wiadomości.
      <?php if ($is_op): ?>
      <form method="post" action="<?= h($view_action) ?>" class="ms-auto d-inline" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_set_status" value="1">
        <input type="hidden" name="status" value="otwarte">
        <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size:.78rem">Otwórz ponownie</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /col-8 -->

<!-- ── Sidebar ────────────────────────────────────────────────────────────── -->
<div class="col-lg-4">

  <!-- Podbicia — brak reakcji -->
  <div class="hd-side-card">
    <div class="hd-side-card-head"><i class="bi bi-megaphone" style="color:#f59e0b"></i>Podbicia</div>
    <div class="hd-side-card-body">
      <?php if ($escalations): ?>
      <ul class="list-unstyled mb-2">
        <?php foreach ($escalations as $e): ?>
        <li class="d-flex justify-content-between align-items-center py-1 border-bottom" style="border-color:var(--hd-bd-l)!important;font-size:.78rem">
          <div>
            <span class="font-monospace" style="font-size:.74rem"><?= h($e['number']) ?></span>
            <div style="color:var(--hd-tx3);font-size:.7rem"><?= date('d.m.Y H:i', strtotime($e['created_at'])) ?></div>
          </div>
          <a href="<?= APP_URL ?>/helpdesk/escalation_pdf.php?id=<?= (int)$e['id'] ?>" target="_blank"
             class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:.74rem">
            <i class="bi bi-file-earmark-pdf me-1"></i>PDF
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p style="font-size:.77rem;color:var(--hd-tx3)" class="mb-2">Brak podbić tego zgłoszenia.</p>
      <?php endif; ?>
      <?php if ($ticket['status'] !== 'zamknięte'): ?>
      <button class="w-100 hd-side-stat-btn" type="button"
              data-bs-toggle="modal" data-bs-target="#hdEscalateModal">
        <i class="bi bi-megaphone" style="color:#f59e0b"></i>Podbij — brak reakcji
      </button>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($is_op): ?>
  <!-- Status -->
  <div class="hd-side-card">
    <div class="hd-side-card-head"><i class="bi bi-arrow-repeat"></i>Status</div>
    <div class="hd-side-card-body">
      <div class="mb-2"><?= hd_status_badge($ticket['status']) ?></div>
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_set_status" value="1">
        <div class="mb-2">
          <?php foreach (HD_STATUSES as $k => $s):
            if ($k === $ticket['status']) continue;
            if ($k === 'przekazane_zewn' || $k === 'zastepcze') continue; ?>
          <button type="submit" name="status" value="<?= h($k) ?>" class="hd-side-stat-btn">
            <i class="bi <?= $s['icon'] ?> text-<?= $s['class'] ?>"></i><?= h($s['label']) ?>
          </button>
          <?php endforeach; ?>
        </div>
        <textarea name="status_note" class="form-control form-control-sm mb-2" rows="2"
                  placeholder="Notatka do zmiany (opcjonalnie, wewnętrzna)"
                  style="font-size:.78rem;resize:none"></textarea>
      </form>
      <button class="hd-side-stat-btn" type="button" data-bs-toggle="modal" data-bs-target="#hdExtModal">
        <i class="bi bi-box-arrow-up-right" style="color:var(--hd-tx2)"></i>
        <?= $ticket['status'] === 'przekazane_zewn' ? 'Aktualizuj przekazanie…' : 'Przekaż zewnętrznie…' ?>
      </button>
    </div>
  </div>

  <!-- Operator -->
  <div class="hd-side-card">
    <div class="hd-side-card-head"><i class="bi bi-person-check"></i>Operator</div>
    <div class="hd-side-card-body">
      <?php if ($ticket['assigned_to']): ?>
      <div class="mb-2">
        <span class="badge" style="background:var(--hd-accent-l);color:var(--hd-accent);font-size:.79rem;font-weight:600;padding:.3rem .6rem">
          <i class="bi bi-person-check me-1"></i><?= h($ticket['assigned_name']) ?>
        </span>
      </div>
      <?php else: ?>
      <div class="mb-2" style="font-size:.79rem;color:var(--hd-err)"><i class="bi bi-exclamation-circle me-1"></i>Brak przypisania</div>
      <?php endif; ?>
      <?php if ((int)($ticket['assigned_to'] ?? 0) !== $uid): ?>
      <form method="post" action="<?= h($view_action) ?>" class="mb-2" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button type="submit" name="_take" class="hd-side-stat-btn" style="background:var(--hd-accent-l);border-color:var(--hd-accent-m);color:var(--hd-accent)">
          <i class="bi bi-hand-index-thumb"></i>Przypisz do mnie
        </button>
      </form>
      <?php endif; ?>
      <?php if (is_admin() && $operators): ?>
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_assign" value="1">
        <div class="input-group input-group-sm">
          <select name="assign_to" class="form-select">
            <option value="">— bez przypisania —</option>
            <?php foreach ($operators as $op): ?>
            <option value="<?= $op['id'] ?>" <?= (int)($ticket['assigned_to'] ?? 0) === (int)$op['id'] ? 'selected' : '' ?>><?= h($op['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-secondary">Przypisz</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Łączenie -->
  <?php if (empty($ticket['merged_into'])): ?>
  <div class="hd-side-card">
    <div class="hd-side-card-head"><i class="bi bi-union"></i>Połącz zgłoszenie</div>
    <div class="hd-side-card-body">
      <p style="font-size:.76rem;color:var(--hd-tx3)" class="mb-2">Przenosi wiadomości i załączniki do zgłoszenia głównego, to zamknię. Nieodwracalne.</p>
      <form method="post" action="<?= h($view_action) ?>" data-hd-form
            data-hd-confirm="Połączyć <?= h($ticket['number']) ?> ze wskazanym? Tej operacji nie można cofnąć.">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_merge" value="1">
        <div class="input-group input-group-sm">
          <span class="input-group-text">#</span>
          <input type="text" name="merge_target" class="form-control" required placeholder="np. HD00042">
          <button class="btn btn-outline-danger">Połącz</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- SLA -->
  <?php $sla = hd_sla($ticket); if ($sla['response'] || $sla['resolution']): $pr_sla = HD_PRIORITIES[$ticket['priority']] ?? []; ?>
  <div class="hd-side-card">
    <div class="hd-side-card-head">
      <i class="bi bi-speedometer2"></i>SLA
      <span class="ms-auto" style="font-size:.72rem;color:var(--hd-tx3);font-weight:400"><?= h(HD_PRIORITIES[$ticket['priority']]['label'] ?? $ticket['priority']) ?></span>
    </div>
    <div class="hd-side-card-body">
      <?php if ($sla['response']): ?>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <?= hd_sla_badge($sla['response'], 'Reakcja') ?>
        <span style="font-size:.72rem;color:var(--hd-tx3)" class="text-nowrap ms-2">do <?= date('d.m H:i', $sla['response']['deadline']) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($sla['resolution']): ?>
      <div class="d-flex justify-content-between align-items-center">
        <?= hd_sla_badge($sla['resolution'], 'Rozwiązanie') ?>
        <span style="font-size:.72rem;color:var(--hd-tx3)" class="text-nowrap ms-2">do <?= date('d.m H:i', $sla['resolution']['deadline']) ?></span>
      </div>
      <?php endif; ?>
      <?php if (($sla['resolution']['state'] ?? '') === 'paused'): ?>
      <div style="font-size:.73rem;color:var(--hd-tx3);margin-top:.5rem"><i class="bi bi-info-circle me-1"></i>Zegar wstrzymany.</div>
      <?php endif; ?>
      <div style="font-size:.71rem;color:var(--hd-tx3);margin-top:.4rem">
        Cel: reakcja <?= hd_fmt_secs((int)($pr_sla['sla_response'] ?? 0) * 60) ?>, rozwiązanie <?= hd_fmt_secs((int)($pr_sla['sla_resolve'] ?? 0) * 60) ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; /* is_op */ ?>

  <!-- Firma zewnętrzna -->
  <?php if ($ticket['status'] === 'przekazane_zewn' || !empty($ticket['ext_vendor'])): ?>
  <div class="hd-side-card">
    <div class="hd-side-card-head" style="background:#f8fafc"><i class="bi bi-box-arrow-up-right" style="color:var(--hd-tx2)"></i>Firma zewnętrzna</div>
    <div class="hd-side-card-body">
      <div class="mb-1"><span style="color:var(--hd-tx3)">Firma:</span> <strong><?= h($ticket['ext_vendor'] ?: '—') ?></strong></div>
      <?php if (!empty($ticket['ext_ref'])): ?>
      <div class="mb-1"><span style="color:var(--hd-tx3)">Nr u firmy:</span> <span class="font-monospace" style="font-size:.78rem"><?= h($ticket['ext_ref']) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($ticket['ext_handed_at'])): ?>
      <div class="mb-1"><span style="color:var(--hd-tx3)">Przekazano:</span> <?= date('d.m.Y H:i', strtotime($ticket['ext_handed_at'])) ?></div>
      <?php endif; ?>
      <?php if (!empty($ticket['ext_reason'])): ?>
      <div class="mt-2 p-2 rounded" style="background:var(--hd-bg);font-size:.77rem;white-space:pre-wrap"><?= nl2br(h($ticket['ext_reason'])) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($is_op): ?>
  <div class="hd-side-card" style="border-color:#fca5a5">
    <div class="hd-side-card-head" style="background:#fef2f2;color:#991b1b"><i class="bi bi-exclamation-diamond-fill" style="color:#dc2626"></i>Firma nie odpowiada</div>
    <div class="hd-side-card-body">
      <?php if ($vendor_cases): ?>
      <ul class="list-unstyled mb-2">
        <?php foreach ($vendor_cases as $c): ?>
        <li class="d-flex justify-content-between align-items-center py-1 border-bottom" style="border-color:var(--hd-bd-l)!important;font-size:.78rem">
          <div>
            <span class="font-monospace" style="font-size:.74rem"><?= h($c['znak_sprawy']) ?></span>
            <div style="color:var(--hd-tx3);font-size:.7rem"><?= date('d.m.Y H:i', strtotime($c['created_at'])) ?></div>
          </div>
          <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$c['id'] ?>" target="_blank"
             class="btn btn-sm btn-outline-secondary py-0" style="font-size:.74rem">Sprawa</a>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <p style="font-size:.77rem;color:var(--hd-tx3)" class="mb-2">Brak założonych spraw.</p>
      <?php endif; ?>
      <button class="hd-side-stat-btn" style="border-color:#fca5a5;color:#dc2626" type="button"
              data-bs-toggle="modal" data-bs-target="#hdVendorCaseModal">
        <i class="bi bi-folder-plus"></i>Brak reakcji — załóż sprawę
      </button>
      <?php if (!in_array($ticket['status'], ['zastepcze', 'zamknięte'], true)): ?>
      <button class="hd-side-stat-btn mt-1" style="color:var(--hd-tx2)" type="button"
              data-bs-toggle="modal" data-bs-target="#hdVendorInsolventModal">
        <i class="bi bi-shield-exclamation"></i>Upadłość — rozwiązanie zastępcze
      </button>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- Udostępnianie -->
  <?php if ($is_op): $track = hd_track_url($ticket); ?>
  <div class="hd-side-card">
    <div class="hd-side-card-head"><i class="bi bi-share"></i>Udostępnianie</div>
    <div class="hd-side-card-body">
      <p style="font-size:.76rem;color:var(--hd-tx3)" class="mb-2">Publiczny podgląd bez logowania (ten link trafia do zgłaszającego w mailach).</p>
      <div class="input-group input-group-sm mb-3">
        <input type="text" class="form-control font-monospace" style="font-size:.7rem"
               value="<?= h($track) ?>" readonly data-hd-copyinput onclick="this.select()">
        <button class="btn btn-outline-secondary" type="button" data-hd-copy title="Kopiuj"><i class="bi bi-clipboard"></i></button>
      </div>
      <label style="font-size:.77rem;color:var(--hd-tx2)" class="mb-1 d-block">
        <i class="bi bi-person-plus me-1"></i>Wyślij link e-mailem
      </label>
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_share" value="1">
        <input type="email" name="share_email" class="form-control form-control-sm mb-1" placeholder="adres e-mail" required>
        <input type="text" name="share_name" class="form-control form-control-sm mb-1" placeholder="imię i nazwisko (opcjonalnie)">
        <textarea name="share_note" class="form-control form-control-sm mb-1" rows="2"
                  placeholder="wiadomość dla odbiorcy (opcjonalnie)"></textarea>
        <button type="submit" class="btn btn-sm btn-outline-primary w-100" style="font-size:.79rem">
          <i class="bi bi-send me-1"></i>Wyślij link
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Szczegóły -->
  <div class="hd-side-card">
    <div class="hd-side-card-head"><i class="bi bi-info-circle"></i>Szczegóły</div>
    <div class="hd-side-card-body p-0">
      <table class="w-100" style="border-collapse:collapse">
        <?php foreach ([
          ['Numer',        '<span class="font-monospace hd-det-num" style="font-size:.7rem">' . h($ticket['number']) . '</span>'],
          ['Kategoria',    h(HD_CATEGORIES[$ticket['category']] ?? $ticket['category'])],
          ['Priorytet',    hd_priority_badge($ticket['priority'])],
          ['Zgłaszający',  h($ticket['requester_name'])],
          ['E-mail',       $ticket['requester_email'] ? '<a href="mailto:'.h($ticket['requester_email']).'" style="font-size:.78rem">'.h($ticket['requester_email']).'</a>' : '—'],
          ['Telefon',      $ticket['requester_phone'] ? h($ticket['requester_phone']) : '—'],
          ['Zgłoszono',    date('d.m.Y H:i', strtotime($ticket['created_at']))],
          ['Aktual.',      date('d.m.Y H:i', strtotime($ticket['updated_at']))],
          ['Rozwiązano',   $ticket['resolved_at'] ? date('d.m.Y H:i', strtotime($ticket['resolved_at'])) : '—'],
        ] as [$lbl, $val]): ?>
        <tr>
          <td class="hd-side-dt ps-3"><?= $lbl ?></td>
          <td class="hd-side-dd pe-3" style="font-size:.79rem"><?= $val ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <?php if (is_admin()): ?>
  <div class="hd-side-card" style="border-color:#fca5a5">
    <div class="hd-side-card-body py-2">
      <form method="post" action="<?= h($view_action) ?>" data-hd-form data-hd-confirm="Usunąć zgłoszenie <?= h($ticket['number']) ?>?">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button type="submit" name="_delete" class="btn btn-sm btn-outline-danger w-100" style="font-size:.79rem">
          <i class="bi bi-trash3 me-1"></i>Usuń zgłoszenie
        </button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /col-4 -->
</div><!-- /row -->

<!-- Modal: przekazanie zewnętrzne -->
<?php if ($is_op): ?>
<div class="modal fade" id="hdExtModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:12px;overflow:hidden">
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_set_status" value="1">
        <input type="hidden" name="status" value="przekazane_zewn">
        <div class="modal-header py-2" style="background:#1e1b4b;color:#e0e7ff">
          <h5 class="modal-title fs-6 fw-bold"><i class="bi bi-box-arrow-up-right me-1"></i>Przekazanie zewnętrzne</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small mb-1 fw-semibold">Nazwa firmy <span class="text-danger">*</span></label>
            <input type="text" name="ext_vendor" class="form-control form-control-sm" value="<?= h($ticket['ext_vendor'] ?? '') ?>" required>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Nr zgłoszenia u firmy</label>
            <input type="text" name="ext_ref" class="form-control form-control-sm" value="<?= h($ticket['ext_ref'] ?? '') ?>" placeholder="opcjonalnie">
          </div>
          <div class="mb-3">
            <label class="form-label small mb-1">Powód / opis</label>
            <textarea name="ext_reason" class="form-control form-control-sm" rows="3" placeholder="dlaczego trafia do firmy zewnętrznej"><?= h($ticket['ext_reason'] ?? '') ?></textarea>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="share_with_vendor" value="1" id="hdShareVendor">
            <label class="form-check-label small" for="hdShareVendor"><i class="bi bi-person-plus me-1"></i>Udostępnij podgląd osobie w firmie (e-mail)</label>
          </div>
          <div data-hd-vendorshare class="d-none">
            <input type="email" name="share_email" class="form-control form-control-sm mb-1" placeholder="e-mail osoby w firmie">
            <input type="text" name="share_name" class="form-control form-control-sm" placeholder="imię i nazwisko (opcjonalnie)">
            <div class="form-text" style="font-size:.73rem">Link do podglądu bez logowania.</div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-box-arrow-up-right me-1"></i>Przekaż</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Modal: podbicie -->
<div class="modal fade" id="hdEscalateModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:12px;overflow:hidden">
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_escalate" value="1">
        <div class="modal-header py-2" style="background:#fef3c7">
          <h5 class="modal-title fs-6 fw-bold" style="color:#92400e"><i class="bi bi-megaphone me-1"></i>Podbij zgłoszenie</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Jeśli nie otrzymujesz odpowiedzi na zgłoszenie
            <strong><?= h($ticket['number']) ?></strong> — formalne podbicie nadaje osobny numer i priorytetowo powiadamia operatorów. Otrzymasz PDF z potwierdzeniem.</p>
          <div class="mb-2">
            <label class="form-label small mb-1">Opisz sytuację (opcjonalnie)</label>
            <textarea name="escalate_reason" class="form-control form-control-sm" rows="3"
                      placeholder="np. od kiedy czekasz, jak pilna jest sprawa"></textarea>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm" style="background:#f59e0b;color:#fff;border:none"><i class="bi bi-megaphone me-1"></i>Podbij</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($is_op): ?>
<!-- Modal: firma nie odpowiada -->
<div class="modal fade" id="hdVendorCaseModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:12px;overflow:hidden">
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_vendor_no_response" value="1">
        <div class="modal-header text-bg-danger py-2">
          <h5 class="modal-title fs-6 fw-bold"><i class="bi bi-exclamation-diamond-fill me-1"></i>Firma nie odpowiada</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Zostanie założona formalna sprawa w EZD Wirtualne biurko (teczka „IT"), z dekretacją „do załatwienia" i terminem 7 dni.</p>
          <div class="mb-2">
            <label class="form-label small mb-1 fw-semibold">Opis sytuacji <span class="text-danger">*</span></label>
            <textarea name="vendor_situation" class="form-control form-control-sm" rows="4" required
                      placeholder="od kiedy brak kontaktu, jakie próby podjęto, jak to wpływa na zgłoszenie"></textarea>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-folder-plus me-1"></i>Załóż sprawę</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: upadłość -->
<div class="modal fade" id="hdVendorInsolventModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:12px;overflow:hidden">
      <form method="post" action="<?= h($view_action) ?>" data-hd-form
            data-hd-confirm="Skierować do rozwiązania zastępczego? Zmieni to status i założy pilną sprawę w EZD.">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_vendor_insolvent" value="1">
        <div class="modal-header py-2" style="background:#1e1b4b;color:#e0e7ff">
          <h5 class="modal-title fs-6 fw-bold"><i class="bi bi-shield-exclamation me-1"></i>Rozwiązanie zastępcze</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small">Gdy firma upadła lub zlikwidowano działalność. Zmieni status na „Rozwiązanie zastępcze" i założy pilną sprawę w EZD (termin 3 dni).</p>
          <div class="mb-2">
            <label class="form-label small mb-1 fw-semibold">Opis sytuacji <span class="text-danger">*</span></label>
            <textarea name="vendor_insolvent_desc" class="form-control form-control-sm" rows="4" required
                      placeholder="informacja o upadłości/likwidacji, źródło, ustalenia dot. dalszego postępowania"></textarea>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-shield-exclamation me-1"></i>Skieruj zastępczo</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Overlay fullscreen edytora -->
<div class="hd-quill-fs-overlay" id="<?= $hd_editor_id ?>_fsOverlay"
     role="dialog" aria-modal="true" aria-label="Edytor pełnoekranowy">
  <div class="hd-quill-fs-box">
    <div class="hd-quill-fs-header">
      <span style="font-size:.9rem;font-weight:700;color:var(--hd-tx)"><i class="bi bi-pencil-square me-1" style="color:var(--hd-accent)"></i>Edytor odpowiedzi</span>
      <button type="button" class="btn btn-sm btn-outline-secondary hd-fs-close" data-overlay="<?= $hd_editor_id ?>_fsOverlay">
        <i class="bi bi-fullscreen-exit me-1"></i>Zamknij
      </button>
    </div>
    <div class="hd-quill-fs-body" id="<?= $hd_editor_id ?>_fsBody"></div>
  </div>
</div>

<?php if (!$hd_pane): ?>
<!-- Quill + zachowania dla strony samodzielnej -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
(function(){
  /* hdInitQuill / hdBindFullscreen mogą być już w index.php; jeśli nie — definiujemy inline */
  if (typeof window.hdInitQuill === 'undefined') {
    window.QUILL_TOOLBAR = [
      [{ header:[false,2,3] }],['bold','italic','underline','strike'],
      [{ list:'ordered'},{list:'bullet'}],['blockquote','link'],['clean']
    ];
    window.hdInitQuill = function(root) {
      if (typeof Quill === 'undefined') return;
      (root||document).querySelectorAll('.hd-quill-wrap').forEach(function(wrap){
        if (wrap._quill) return;
        var ed = wrap.querySelector('[id]'); if(!ed) return;
        var form = wrap.closest('form'); if(!form) return;
        var hi = form.querySelector('input[name="msg_body"]');
        var q = new Quill(ed,{theme:'snow',modules:{toolbar:QUILL_TOOLBAR},placeholder:'Wpisz odpowiedź…'});
        wrap._quill = q;
        var qlEd = wrap.querySelector('.ql-editor');
        if(qlEd){qlEd.setAttribute('aria-label','Treść odpowiedzi');qlEd.setAttribute('aria-multiline','true');}
        q.on('text-change',function(){if(q.getText().trim())wrap.classList.remove('is-invalid');});
        var sb = form.querySelector('button[name="_add_msg"]');
        if(sb) sb.addEventListener('click',function(e){
          if(q.getText().trim()===''){e.preventDefault();wrap.classList.add('is-invalid');q.focus();return;}
          if(hi) hi.value=q.root.innerHTML;
        });
      });
    };
  }
  if (typeof window.hdBindFullscreen === 'undefined') {
    window.hdFsClose = function(overlay){
      if(!overlay) return;
      var wrap=overlay._origWrap; if(!wrap){overlay.classList.remove('active');return;}
      var fsBody=overlay.querySelector('.hd-quill-fs-body'); if(!fsBody){overlay.classList.remove('active');return;}
      var tb=fsBody.querySelector('.ql-toolbar'),ct=fsBody.querySelector('.ql-container');
      if(tb)wrap.appendChild(tb);if(ct)wrap.appendChild(ct);
      overlay.classList.remove('active');
    };
    window.hdBindFullscreen = function(root){
      root=root||document;
      root.querySelectorAll('.hd-fs-btn').forEach(function(btn){
        if(btn._fsBound) return; btn._fsBound=true;
        btn.addEventListener('click',function(){
          var wrap=document.getElementById(btn.dataset.target); if(!wrap) return;
          var overlay=document.getElementById(btn.dataset.target.replace('_wrap','_fsOverlay')); if(!overlay) return;
          var fsBody=document.getElementById(btn.dataset.target.replace('_wrap','_fsBody')); if(!fsBody) return;
          var q=wrap._quill; if(!q) return;
          var tb=wrap.querySelector('.ql-toolbar'),ct=wrap.querySelector('.ql-container');
          if(tb)fsBody.appendChild(tb);if(ct)fsBody.appendChild(ct);
          overlay.classList.add('active');overlay._origWrap=wrap;
          setTimeout(function(){q.focus();},80);
        });
      });
      root.querySelectorAll('.hd-fs-close').forEach(function(btn){
        if(btn._fsBound) return; btn._fsBound=true;
        btn.addEventListener('click',function(){hdFsClose(document.getElementById(btn.dataset.overlay));});
      });
      if(!document._hdEscBound){
        document._hdEscBound=true;
        document.addEventListener('keydown',function(e){
          if(e.key!=='Escape') return;
          var a=document.querySelector('.hd-quill-fs-overlay.active');
          if(a){e.preventDefault();hdFsClose(a);}
        });
      }
    };
  }
  window.hdInitQuill(document);
  window.hdBindFullscreen(document);

  /* Zachowania dla strony samodzielnej (w konsoli wiąże je hdBindPane w index.php) */
  document.querySelectorAll('[data-hd-older]').forEach(function(b){
    b.addEventListener('click',function(){
      var w=document.querySelector('[data-hd-olderwrap]'); if(!w) return;
      var hid=w.classList.toggle('d-none');
      b.innerHTML=hid?'<i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze':'<i class="bi bi-chevron-up me-1"></i>Ukryj wcześniejsze';
    });
  });
  document.querySelectorAll('[data-hd-more]').forEach(function(b){
    b.addEventListener('click',function(){
      var body=b.previousElementSibling; if(!body) return;
      b.textContent=body.classList.toggle('hd-expanded')?'Zwiń':'Pokaż całość';
    });
  });
  document.querySelectorAll('.hd-macro-toggle').forEach(function(btn){
    btn.addEventListener('click',function(){
      var panel=btn.closest('.hd-reply-box').querySelector('.hd-macro-panel');
      if(!panel) return;
      var open=panel.classList.toggle('open');
      btn.classList.toggle('open',open);
      btn.querySelector('i').className='bi bi-'+(open?'chevron-up':'card-text');
    });
  });
  document.querySelectorAll('.hd-macro-card').forEach(function(card){
    card.addEventListener('click',function(){
      var body=card.dataset.body||'';
      var form=card.closest('form'); if(!form) return;
      var wrap=form.querySelector('.hd-quill-wrap');
      if(wrap&&wrap._quill){
        var q=wrap._quill;
        if(q.getText().trim()!==''&&!confirm('Zastąpić obecną treść?')) return;
        q.root.innerHTML=body.replace(/\n/g,'<br>');q.focus();
      }
    });
    card.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();card.click();}});
  });
  document.querySelectorAll('[data-hd-copy]').forEach(function(b){
    b.addEventListener('click',function(){
      var inp=b.closest('.input-group').querySelector('[data-hd-copyinput]'); if(!inp) return;
      if(navigator.clipboard) navigator.clipboard.writeText(inp.value);
      b.innerHTML='<i class="bi bi-check2"></i>';
    });
  });
  var vs=document.querySelector('#hdShareVendor');
  if(vs) vs.addEventListener('change',function(){
    var box=document.querySelector('[data-hd-vendorshare]');
    if(box) box.classList.toggle('d-none',!vs.checked);
  });
  document.querySelectorAll('form[data-hd-confirm]').forEach(function(f){
    f.addEventListener('submit',function(e){if(!confirm(f.dataset.hdConfirm))e.preventDefault();});
  });
})();
</script>
<?php endif; ?>

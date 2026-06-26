<?php
/**
 * helpdesk/_detail.php — wspólny fragment szczegółów zgłoszenia.
 *
 * Używany przez:
 *   - helpdesk/view.php (strona samodzielna — np. z linków w mailach)
 *   - helpdesk/index.php → tryb pane (?_pane=1) ładowany do konsoli przez AJAX
 *
 * Wymaga w zasięgu: $ticket, $messages, $atts, $operators, $is_op, $u, $uid.
 * W trybie pane ($GLOBALS['hd_pane_mode']) pomija flash i skrypty inline —
 * zachowania wiąże konsola (assets w index.php). Style: hd_ui_css() w <head>.
 */
$hd_pane = !empty($GLOBALS['hd_pane_mode']);
$view_action = APP_URL . '/helpdesk/view.php?id=' . (int)$ticket['id'];
?>
<!-- Nagłówek zgłoszenia -->
<div class="d-flex align-items-start gap-2 mb-3 flex-wrap">
  <?php if ($hd_pane): ?>
  <button type="button" class="btn btn-sm btn-outline-secondary mt-1 d-lg-none" data-hd-back aria-label="Wróć do listy">
    <i class="bi bi-arrow-left"></i>
  </button>
  <?php else: ?>
  <a href="<?= APP_URL ?>/helpdesk/index.php" class="btn btn-sm btn-outline-secondary mt-1">
    <i class="bi bi-arrow-left"></i>
  </a>
  <?php endif; ?>
  <div class="flex-grow-1">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <span class="font-monospace fw-bold text-muted"><?= h($ticket['number']) ?></span>
      <?= hd_status_badge($ticket['status']) ?>
      <?= hd_priority_badge($ticket['priority']) ?>
      <span class="badge bg-light text-dark border small"><?= h(HD_CATEGORIES[$ticket['category']] ?? $ticket['category']) ?></span>
    </div>
    <h4 class="mb-0 mt-1 fw-bold"><?= h($ticket['title']) ?></h4>
    <div class="text-muted small mt-1 d-flex flex-wrap align-items-center gap-2">
      <span>Zgłoszono przez <strong><?= h($ticket['requester_name']) ?></strong></span>
      <?php if ($ticket['requester_email']): ?>
      <span class="font-monospace" style="font-size:.78rem"><?= h($ticket['requester_email']) ?></span>
      <?php try { echo hd_email_verify_badge($ticket['requester_email']); } catch (\Throwable $e) {} ?>
      <?php endif; ?>
      <span>· <?= date_pl(substr($ticket['created_at'], 0, 10)) ?></span>
      <?= $ticket['assigned_name']
        ? '· <i class="bi bi-person-check text-success me-1"></i>Operator: <strong>' . h($ticket['assigned_name']) . '</strong>'
        : '· <span class="text-danger"><i class="bi bi-exclamation-circle me-1"></i>Nieprzypisane</span>' ?>
    </div>
  </div>
</div>

<?php if (!$hd_pane) echo flash_html(); ?>

<?php if (!empty($ticket['merged_into'])):
  $mt = db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [(int)$ticket['merged_into']]); ?>
<div class="alert alert-secondary py-2 small d-flex align-items-center gap-2">
  <i class="bi bi-union"></i>
  <div>To zgłoszenie zostało połączone ze zgłoszeniem
    <a href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$ticket['merged_into'] ?>"><strong><?= h($mt['number'] ?? '') ?></strong></a>.
    Dalsza korespondencja odbywa się w zgłoszeniu głównym.</div>
</div>
<?php endif; ?>

<?php
  $merged_children = $is_op ? db_all("SELECT id, number FROM helpdesk_tickets WHERE merged_into=? ORDER BY id", [(int)$ticket['id']]) : [];
?>
<?php if ($merged_children): ?>
<div class="alert alert-light border py-2 small d-flex align-items-start gap-2">
  <i class="bi bi-diagram-3 mt-1"></i>
  <div>Połączone z tym zgłoszeniem:
    <?php foreach ($merged_children as $c): ?>
    <a href="<?= APP_URL ?>/helpdesk/view.php?id=<?= (int)$c['id'] ?>" class="badge bg-light text-dark border text-decoration-none ms-1"><?= h($c['number']) ?></a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">

<!-- ── Kolumna główna — wiadomości ────────────────────────────────────────── -->
<div class="col-lg-8">

  <div class="mb-3">
    <?php
      $total        = count($messages);
      $recent_keep  = 3;
      $hidden_count = ($total > $recent_keep + 1) ? $total - $recent_keep : 0;
    ?>
    <?php if ($hidden_count): ?>
    <div class="text-center mb-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-hd-older>
        <i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze wiadomości (<?= $hidden_count ?>)
      </button>
    </div>
    <?php endif; ?>
    <?php foreach ($messages as $i => $m):
      $is_req      = (int)$m['user_id'] === (int)$ticket['requester_id'];
      $msg_class   = $m['is_internal'] ? 'hd-msg-intern' : ($is_req ? 'hd-msg-user' : 'hd-msg-op');
      $msg_atts    = array_filter($atts, fn($a) => (int)$a['message_id'] === (int)$m['id']);
      $body_html   = (strpos($m['body'], '<') !== false) ? $m['body'] : nl2br(h($m['body']));
      $long        = mb_strlen(strip_tags($m['body'])) > 600 || substr_count($m['body'], "\n") > 10;
      if ($hidden_count && $i === 0)             echo '<div data-hd-olderwrap class="d-none">';
      if ($hidden_count && $i === $hidden_count)  echo '</div>';
    ?>
    <div class="hd-msg <?= $msg_class ?>">
      <div class="d-flex justify-content-between align-items-start mb-1">
        <div>
          <strong><?= h($m['user_name'] ?: 'System') ?></strong>
          <?php if ($m['is_internal']): ?>
          <span class="badge bg-warning text-dark ms-1" style="font-size:.68rem">wewn.</span>
          <?php endif; ?>
        </div>
        <small class="text-muted"><?= date('d.m.Y H:i', strtotime($m['created_at'])) ?></small>
      </div>
      <div class="hd-body<?= $long ? ' hd-clamp' : '' ?>"><?= $body_html ?></div>
      <?php if ($long): ?>
      <button type="button" class="btn btn-link btn-sm p-0 mt-1" style="font-size:.8rem" data-hd-more>Pokaż całość</button>
      <?php endif; ?>
      <?php if ($msg_atts): ?>
      <div class="mt-2 d-flex flex-wrap gap-1">
        <?php foreach ($msg_atts as $a): ?>
        <a href="<?= APP_URL ?>/helpdesk/attachment.php?id=<?= $a['id'] ?>"
           class="badge bg-light text-dark border text-decoration-none" style="font-size:.78rem">
          <i class="bi bi-paperclip me-1"></i><?= h($a['original_name']) ?>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php $init_atts = array_filter($atts, fn($a) => $a['message_id'] === null); ?>
    <?php if ($init_atts): ?>
    <div class="mt-1 mb-2">
      <div class="text-muted small mb-1"><i class="bi bi-paperclip me-1"></i>Załączniki ze zgłoszenia:</div>
      <div class="d-flex flex-wrap gap-1">
        <?php foreach ($init_atts as $a): ?>
        <a href="<?= APP_URL ?>/helpdesk/attachment.php?id=<?= $a['id'] ?>"
           class="badge bg-light text-dark border text-decoration-none">
          <?= h($a['original_name']) ?>
          <span class="text-muted ms-1">(<?= round($a['file_size'] / 1024) ?> KB)</span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Formularz odpowiedzi -->
  <?php if ($ticket['status'] !== 'zamknięte'): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-reply me-1"></i>Dodaj wiadomość
    </div>
    <div class="card-body">
      <form method="post" action="<?= h($view_action) ?>" enctype="multipart/form-data" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <?php if ($is_op): $db_macros = hd_macros_active(); ?>
        <?php if ($db_macros): ?>
        <div class="dropdown mb-2">
          <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-card-text me-1"></i>Wstaw gotową odpowiedź
          </button>
          <ul class="dropdown-menu" style="font-size:.85rem;max-height:300px;overflow-y:auto">
            <?php foreach ($db_macros as $mac): ?>
            <li><button type="button" class="dropdown-item hd-tpl-btn" data-body="<?= h($mac['body']) ?>"><?= h($mac['title']) ?></button></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php $hd_editor_id = 'hdQuill_' . (int)$ticket['id']; ?>
        <!-- Ukryte pole z HTML z Quilla -->
        <input type="hidden" name="msg_body" id="<?= $hd_editor_id ?>_hidden">
        <!-- Quill editor -->
        <div class="hd-quill-wrap mb-2" id="<?= $hd_editor_id ?>_wrap">
          <div id="<?= $hd_editor_id ?>" aria-label="Treść odpowiedzi" aria-required="true"
               style="min-height:110px"></div>
        </div>
        <div id="<?= $hd_editor_id ?>_err" class="invalid-feedback d-none mb-2">
          Treść odpowiedzi nie może być pusta.
        </div>
        <?php if ($is_op): ?>
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="is_internal" id="is_internal_<?= (int)$ticket['id'] ?>" value="1">
          <label class="form-check-label small" for="is_internal_<?= (int)$ticket['id'] ?>">
            <i class="bi bi-lock me-1 text-warning"></i>Notatka wewnętrzna
            <span class="text-muted">(widoczna tylko dla operatorów)</span>
          </label>
        </div>
        <?php endif; ?>
        <div class="mb-2">
          <label for="hd_att_<?= (int)$ticket['id'] ?>" class="visually-hidden">Załączniki</label>
          <input id="hd_att_<?= (int)$ticket['id'] ?>" name="msg_attachments[]" type="file"
                 class="form-control form-control-sm" multiple
                 accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv">
        </div>
        <button type="submit" name="_add_msg" class="btn btn-primary btn-sm" id="<?= $hd_editor_id ?>_submit">
          <i class="bi bi-send me-1"></i>Wyślij
        </button>
      </form>
    </div>
  </div>
  <?php else: ?>
  <div class="alert alert-secondary py-2 small">
    <i class="bi bi-lock me-1"></i>Zgłoszenie zamknięte. Nie można dodawać wiadomości.
    <?php if ($is_op): ?>
    <form method="post" action="<?= h($view_action) ?>" class="d-inline ms-2" data-hd-form>
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_set_status" value="1">
      <input type="hidden" name="status" value="otwarte">
      <button type="submit" class="btn btn-sm btn-outline-primary py-0">Otwórz ponownie</button>
    </form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div><!-- /col-8 -->

<!-- ── Sidebar ────────────────────────────────────────────────────────────── -->
<div class="col-lg-4">

  <?php if ($is_op): ?>
  <!-- Status -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-arrow-repeat me-1"></i>Status zgłoszenia
    </div>
    <div class="card-body">
      <div class="mb-2"><?= hd_status_badge($ticket['status']) ?></div>
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_set_status" value="1">
        <div class="d-flex flex-column gap-1 mb-2">
          <?php foreach (HD_STATUSES as $k => $s):
            if ($k === $ticket['status']) continue;
            if ($k === 'przekazane_zewn') continue; ?>
          <button type="submit" name="status" value="<?= h($k) ?>"
                  class="btn btn-sm btn-outline-<?= $s['class'] ?> text-start py-1">
            <i class="bi <?= $s['icon'] ?> me-1"></i><?= h($s['label']) ?>
          </button>
          <?php endforeach; ?>
        </div>
        <textarea name="status_note" class="form-control form-control-sm mb-1" rows="2"
                  placeholder="Notatka do zmiany (opcjonalnie, wewnętrzna)"></textarea>
      </form>
      <button class="btn btn-sm btn-dark text-start py-1 w-100 mt-1" type="button"
              data-bs-toggle="modal" data-bs-target="#hdExtModal">
        <i class="bi bi-box-arrow-up-right me-1"></i><?= $ticket['status'] === 'przekazane_zewn' ? 'Aktualizuj przekazanie…' : 'Przekaż do firmy zewnętrznej…' ?>
      </button>
    </div>
  </div>

  <!-- Operator -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem">
      <i class="bi bi-person-check me-1"></i>Operator
    </div>
    <div class="card-body">
      <?php if ($ticket['assigned_to']): ?>
      <div class="mb-2"><span class="badge bg-success-subtle text-success border border-success-subtle">
        <i class="bi bi-person-check me-1"></i><?= h($ticket['assigned_name']) ?></span></div>
      <?php else: ?>
      <div class="mb-2 text-muted small"><i class="bi bi-exclamation-circle text-danger me-1"></i>Brak przypisania</div>
      <?php endif; ?>
      <?php if ((int)($ticket['assigned_to'] ?? 0) !== $uid): ?>
      <form method="post" action="<?= h($view_action) ?>" class="mb-2" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button type="submit" name="_take" class="btn btn-sm btn-primary w-100">
          <i class="bi bi-hand-index-thumb me-1"></i>Przypisz do mnie
        </button>
      </form>
      <?php endif; ?>
      <?php if (is_admin() && $operators): ?>
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_assign" value="1">
        <div class="input-group input-group-sm">
          <select name="assign_to" class="form-select form-select-sm">
            <option value="">— bez przypisania —</option>
            <?php foreach ($operators as $op): ?>
            <option value="<?= $op['id'] ?>" <?= (int)($ticket['assigned_to'] ?? 0) === (int)$op['id'] ? 'selected' : '' ?>><?= h($op['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline-secondary btn-sm">Przypisz</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Łączenie -->
  <?php if (empty($ticket['merged_into'])): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem"><i class="bi bi-union me-1"></i>Połącz zgłoszenie</div>
    <div class="card-body small">
      <p class="text-muted mb-2" style="font-size:.78rem">Łączy to zgłoszenie (duplikat) ze zgłoszeniem głównym — wiadomości i załączniki zostaną przeniesione, a to zamknięte. Nieodwracalne.</p>
      <form method="post" action="<?= h($view_action) ?>" data-hd-form data-hd-confirm="Połączyć <?= h($ticket['number']) ?> ze wskazanym zgłoszeniem? Tej operacji nie można cofnąć.">
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
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold d-flex justify-content-between align-items-center" style="font-size:.85rem">
      <span><i class="bi bi-speedometer2 me-1"></i>SLA</span>
      <span class="text-muted fw-normal" style="font-size:.78rem"><?= h(HD_PRIORITIES[$ticket['priority']]['label'] ?? $ticket['priority']) ?></span>
    </div>
    <div class="card-body small">
      <?php if ($sla['response']): ?>
      <div class="d-flex justify-content-between align-items-center mb-2">
        <?= hd_sla_badge($sla['response'], 'Reakcja') ?>
        <span class="text-muted text-nowrap ms-2" style="font-size:.74rem">do <?= date('d.m H:i', $sla['response']['deadline']) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($sla['resolution']): ?>
      <div class="d-flex justify-content-between align-items-center">
        <?= hd_sla_badge($sla['resolution'], 'Rozwiązanie') ?>
        <span class="text-muted text-nowrap ms-2" style="font-size:.74rem">do <?= date('d.m H:i', $sla['resolution']['deadline']) ?></span>
      </div>
      <?php endif; ?>
      <?php if (($sla['resolution']['state'] ?? '') === 'paused'): ?>
      <div class="text-muted mt-2" style="font-size:.74rem"><i class="bi bi-info-circle me-1"></i>Zegar rozwiązania wstrzymany (oczekiwanie / firma zewnętrzna / prace programistyczne).</div>
      <?php endif; ?>
      <div class="text-muted mt-2" style="font-size:.72rem">Cele: reakcja <?= hd_fmt_secs((int)($pr_sla['sla_response'] ?? 0) * 60) ?>, rozwiązanie <?= hd_fmt_secs((int)($pr_sla['sla_resolve'] ?? 0) * 60) ?>.</div>
    </div>
  </div>
  <?php endif; ?>
  <?php endif; /* is_op */ ?>

  <!-- Firma zewnętrzna -->
  <?php if ($ticket['status'] === 'przekazane_zewn' || !empty($ticket['ext_vendor'])): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold text-bg-dark" style="font-size:.85rem"><i class="bi bi-box-arrow-up-right me-1"></i>Firma zewnętrzna</div>
    <div class="card-body small">
      <div class="mb-1"><span class="text-muted">Firma:</span> <strong><?= h($ticket['ext_vendor'] ?: '—') ?></strong></div>
      <?php if (!empty($ticket['ext_ref'])): ?><div class="mb-1"><span class="text-muted">Nr u firmy:</span> <span class="font-monospace"><?= h($ticket['ext_ref']) ?></span></div><?php endif; ?>
      <?php if (!empty($ticket['ext_handed_at'])): ?><div class="mb-1"><span class="text-muted">Przekazano:</span> <?= date('d.m.Y H:i', strtotime($ticket['ext_handed_at'])) ?></div><?php endif; ?>
      <?php if (!empty($ticket['ext_reason'])): ?><div class="mt-2 p-2 rounded" style="background:#f8f9fa;white-space:pre-wrap"><?= nl2br(h($ticket['ext_reason'])) ?></div><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Udostępnianie -->
  <?php if ($is_op): $track = hd_track_url($ticket); ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem"><i class="bi bi-share me-1"></i>Udostępnianie podglądu</div>
    <div class="card-body small">
      <p class="text-muted mb-2" style="font-size:.78rem">Publiczny podgląd i odpowiedź bez logowania (ten sam link trafia w mailach do zgłaszającego).</p>
      <div class="input-group input-group-sm mb-3">
        <input type="text" class="form-control font-monospace" style="font-size:.72rem" value="<?= h($track) ?>" readonly data-hd-copyinput onclick="this.select()">
        <button class="btn btn-outline-secondary" type="button" data-hd-copy><i class="bi bi-clipboard"></i></button>
      </div>
      <label class="text-muted mb-1" style="font-size:.78rem"><i class="bi bi-person-plus me-1"></i>Udostępnij innej osobie (e-mailem)</label>
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_share" value="1">
        <input type="email" name="share_email" class="form-control form-control-sm mb-1" placeholder="adres e-mail" required>
        <input type="text" name="share_name" class="form-control form-control-sm mb-1" placeholder="imię i nazwisko (opcjonalnie)">
        <textarea name="share_note" class="form-control form-control-sm mb-1" rows="2" placeholder="wiadomość dla odbiorcy (opcjonalnie)"></textarea>
        <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-send me-1"></i>Wyślij link do podglądu</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Szczegóły -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2 fw-semibold" style="font-size:.85rem"><i class="bi bi-info-circle me-1"></i>Szczegóły</div>
    <div class="card-body small">
      <table class="w-100" style="border-collapse:collapse">
        <?php foreach ([
          ['Numer',      '<span class="font-monospace">' . h($ticket['number']) . '</span>'],
          ['Kategoria',  h(HD_CATEGORIES[$ticket['category']] ?? $ticket['category'])],
          ['Priorytet',  hd_priority_badge($ticket['priority'])],
          ['Zgłaszający',h($ticket['requester_name'])],
          ['E-mail',     $ticket['requester_email'] ? '<a href="mailto:'.h($ticket['requester_email']).'">' . h($ticket['requester_email']) . '</a>' : '—'],
          ['Telefon',    $ticket['requester_phone'] ? h($ticket['requester_phone']) : '—'],
          ['Zgłoszono',  date('d.m.Y H:i', strtotime($ticket['created_at']))],
          ['Zaktualizowano', date('d.m.Y H:i', strtotime($ticket['updated_at']))],
          ['Rozwiązano', $ticket['resolved_at'] ? date('d.m.Y H:i', strtotime($ticket['resolved_at'])) : '—'],
        ] as [$lbl, $val]): ?>
        <tr>
          <td style="padding:4px 8px 4px 0;color:#6c757d;white-space:nowrap;vertical-align:top"><?= $lbl ?></td>
          <td style="padding:4px 0"><?= $val ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <?php if (is_admin()): ?>
  <div class="card border-danger border-opacity-25 border mb-3">
    <div class="card-body py-2">
      <form method="post" action="<?= h($view_action) ?>" data-hd-form data-hd-confirm="Usunąć zgłoszenie <?= h($ticket['number']) ?>?">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button type="submit" name="_delete" class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash3 me-1"></i>Usuń zgłoszenie</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /col-4 -->
</div><!-- /row -->

<!-- Modal: przekazanie do firmy zewnętrznej -->
<?php if ($is_op): ?>
<div class="modal fade" id="hdExtModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post" action="<?= h($view_action) ?>" data-hd-form>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_set_status" value="1">
        <input type="hidden" name="status" value="przekazane_zewn">
        <div class="modal-header text-bg-dark py-2">
          <h5 class="modal-title fs-6"><i class="bi bi-box-arrow-up-right me-1"></i>Przekazanie do firmy zewnętrznej</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label small mb-1">Nazwa firmy zewnętrznej <span class="text-danger">*</span></label>
            <input type="text" name="ext_vendor" class="form-control form-control-sm" value="<?= h($ticket['ext_vendor'] ?? '') ?>" required>
          </div>
          <div class="mb-2">
            <label class="form-label small mb-1">Nr zgłoszenia u firmy</label>
            <input type="text" name="ext_ref" class="form-control form-control-sm" value="<?= h($ticket['ext_ref'] ?? '') ?>" placeholder="opcjonalnie">
          </div>
          <div class="mb-3">
            <label class="form-label small mb-1">Powód / opis przekazania</label>
            <textarea name="ext_reason" class="form-control form-control-sm" rows="3" placeholder="dlaczego zgłoszenie trafia do firmy zewnętrznej"><?= h($ticket['ext_reason'] ?? '') ?></textarea>
          </div>
          <hr class="my-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="share_with_vendor" value="1" id="hdShareVendor">
            <label class="form-check-label small" for="hdShareVendor"><i class="bi bi-person-plus me-1"></i>Udostępnij podgląd osobie w firmie (wyślij link e-mailem)</label>
          </div>
          <div data-hd-vendorshare class="d-none">
            <input type="email" name="share_email" class="form-control form-control-sm mb-1" placeholder="adres e-mail osoby w firmie">
            <input type="text" name="share_name" class="form-control form-control-sm" placeholder="imię i nazwisko (opcjonalnie)">
            <div class="form-text" style="font-size:.75rem">Odbiorca dostanie link do mikropanelu — podgląd i odpowiedzi bez logowania.</div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-dark"><i class="bi bi-box-arrow-up-right me-1"></i>Oznacz jako przekazane</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if (!$hd_pane): ?>
<!-- Zachowania dla strony samodzielnej (w konsoli wiąże je index.php) -->
<script>
(function(){
  function bind(root){
    root.querySelectorAll('[data-hd-older]').forEach(function(b){ b.addEventListener('click',function(){
      var w=root.querySelector('[data-hd-olderwrap]'); if(!w) return;
      var hid=w.classList.toggle('d-none');
      b.innerHTML = hid ? '<i class="bi bi-chevron-down me-1"></i>Pokaż wcześniejsze wiadomości' : '<i class="bi bi-chevron-up me-1"></i>Ukryj wcześniejsze wiadomości';
    });});
    root.querySelectorAll('[data-hd-more]').forEach(function(b){ b.addEventListener('click',function(){
      var body=b.previousElementSibling; if(!body) return;
      b.textContent = body.classList.toggle('hd-expanded') ? 'Zwiń' : 'Pokaż całość';
    });});
    root.querySelectorAll('.hd-tpl-btn').forEach(function(b){ b.addEventListener('click',function(){
      var form = b.closest('form');
      // Quill (jeśli załadowany)
      var wrap = form.querySelector('.hd-quill-wrap');
      if (wrap && wrap._quill) {
        var q = wrap._quill;
        if (q.getText().trim() !== '' && !confirm('Zastąpić obecną treść wybranym szablonem?')) return;
        q.root.innerHTML = b.dataset.body.replace(/\n/g,'<br>');
        q.focus();
        return;
      }
      var ta=form.querySelector('.hd-msg-body'); if(!ta) return;
      if(ta.value.trim()!=='' && !confirm('Zastąpić obecną treść wybranym szablonem?')) return;
      ta.value=b.dataset.body; ta.focus();
    });});
    root.querySelectorAll('[data-hd-copy]').forEach(function(b){ b.addEventListener('click',function(){
      var inp=b.closest('.input-group').querySelector('[data-hd-copyinput]'); if(!inp) return;
      if(navigator.clipboard) navigator.clipboard.writeText(inp.value); b.innerHTML='<i class="bi bi-check2"></i>';
    });});
    var vs=root.querySelector('#hdShareVendor');
    if(vs) vs.addEventListener('change',function(){ var box=root.querySelector('[data-hd-vendorshare]'); if(box) box.classList.toggle('d-none',!vs.checked); });
    root.querySelectorAll('form[data-hd-confirm]').forEach(function(f){ f.addEventListener('submit',function(e){ if(!confirm(f.dataset.hdConfirm)) e.preventDefault(); }); });
  }
  bind(document);
})();
</script>
<?php endif; ?>

<!-- Quill CSS (dla view.php samodzielnego — index.php ładuje swój egzemplarz) -->
<?php if (!$hd_pane): ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
// hdInitQuill zdefiniowane w index.php; dla view.php samodzielnego definiujemy inline
if (typeof hdInitQuill === 'undefined') {
  window.QUILL_TOOLBAR = [
    [{ 'header': [false, 2, 3] }],
    ['bold', 'italic', 'underline', 'strike'],
    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
    ['blockquote', 'link'],
    ['clean']
  ];
  window.hdInitQuill = function(root) {
    if (typeof Quill === 'undefined') return;
    (root || document).querySelectorAll('.hd-quill-wrap').forEach(function(wrap) {
      if (wrap._quill) return;
      var editorDiv = wrap.querySelector('[id]'); if (!editorDiv) return;
      var form = wrap.closest('form'); if (!form) return;
      var hiddenInput = form.querySelector('input[name="msg_body"]');
      var errDiv = wrap.nextElementSibling;
      if (errDiv && !errDiv.classList.contains('invalid-feedback')) errDiv = null;
      var q = new Quill(editorDiv, { theme:'snow', modules:{ toolbar: QUILL_TOOLBAR }, placeholder:'Wpisz odpowiedź…' });
      wrap._quill = q;
      var qlEditor = wrap.querySelector('.ql-editor');
      if (qlEditor) { qlEditor.setAttribute('aria-label','Treść odpowiedzi'); qlEditor.setAttribute('aria-multiline','true'); qlEditor.setAttribute('aria-required','true'); }
      q.on('text-change', function(){ if(q.getText().trim()!==''){wrap.classList.remove('is-invalid');if(errDiv)errDiv.classList.add('d-none');} });
      var submitBtn = form.querySelector('button[name="_add_msg"]');
      if (submitBtn) {
        submitBtn.addEventListener('click', function(e) {
          if (q.getText().trim()==='') { e.preventDefault(); wrap.classList.add('is-invalid'); if(errDiv)errDiv.classList.remove('d-none'); q.focus(); return; }
          if (hiddenInput) hiddenInput.value = q.root.innerHTML;
        });
      }
    });
  };
  hdInitQuill(document);
}
</script>
<?php endif; ?>

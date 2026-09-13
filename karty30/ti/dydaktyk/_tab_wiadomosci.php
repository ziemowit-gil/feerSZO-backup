<?php /* ═══════════════════════ TAB: WIADOMOŚCI ═══════════════════════ */
// Zbierz to_admin_id → label dla istniejących wątków adminów
$_admin_thread_map = [];
foreach ($dyd_admin_threads as $_at) {
    $_aid = ($_at['to_admin_id'] === null) ? 0 : (int)$_at['to_admin_id'];
    $_admin_thread_map[$_aid] = $_at;
}
$_has_admin_threads = !empty($dyd_admin_threads);
?>
<?php
$_kier_unread = (int)$dyd_admin_unseen;
$_kurs_unread = array_sum(array_column($dyd_msg_threads, 'unread'));
$_total_unread = $_kier_unread + $_kurs_unread;
$_has_active = $dyd_thread_is_admin || !empty($dyd_msg_student);
?>
<!-- ─── Tabela wątków ───────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent d-flex align-items-center gap-2">
    <span class="fw-semibold"><i class="bi bi-envelope me-2"></i>Wiadomości</span>
    <?php if ($_total_unread > 0): ?>
    <span class="badge text-bg-danger"><?= $_total_unread ?></span>
    <?php endif; ?>
    <div class="ms-auto">
      <button type="button" class="btn btn-primary btn-sm"
              data-bs-toggle="modal" data-bs-target="#dydMsgNew">
        <i class="bi bi-pencil-square me-1"></i>Nowa wiadomość
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle" style="font-size:.875rem">
      <thead class="table-light">
        <tr>
          <th scope="col" style="width:36px"></th>
          <th scope="col">Rozmówca</th>
          <th scope="col" style="width:120px">Ostatnia wiad.</th>
          <th scope="col" style="width:56px" class="text-center">Nowe</th>
        </tr>
      </thead>

      <!-- Kierownictwo -->
      <tbody>
        <tr class="table-secondary">
          <td colspan="4" class="py-1 px-3" style="font-size:.72rem">
            <i class="bi bi-building me-1" aria-hidden="true"></i>
            <span class="fw-semibold text-uppercase" style="letter-spacing:.06em">Kierownictwo</span>
            <?php if ($_kier_unread > 0): ?>
            <span class="badge text-bg-danger ms-1" style="font-size:.62rem"><?= $_kier_unread ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if (empty($dyd_admin_threads)): ?>
        <tr>
          <td colspan="4" class="text-center text-body-secondary py-3" style="font-size:.85rem">
            Brak wiadomości do kierownictwa
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($dyd_admin_threads as $_at):
          $_aid   = ($_at['to_admin_id'] === null) ? 0 : (int)$_at['to_admin_id'];
          $_label = ti_admin_thread_label($_aid, $dyd_admin_users);
          $_isAct = $dyd_thread_is_admin && $dyd_admin_active_id === $_aid;
          $_unseen= (int)$_at['unseen'];
          $_ts    = $_at['last_at'] ? date('d.m H:i', strtotime($_at['last_at'])) : '—';
          $_href  = 'index.php?course='.urlencode($cur_course).'&tab=wiadomosci&thread=admin&to_admin='.rawurlencode($_aid);
        ?>
        <tr class="dyd-thread-row<?= $_isAct ? ' table-primary' : '' ?>"
            style="cursor:pointer" onclick="location.href='<?= h($_href) ?>'">
          <td class="text-center px-2">
            <i class="bi bi-<?= $_aid === 0 ? 'people' : 'person-circle' ?> text-body-secondary" style="font-size:1.1rem" aria-hidden="true"></i>
          </td>
          <td>
            <div class="fw-semibold"><?= h($_label) ?></div>
            <div class="text-body-secondary" style="font-size:.75rem">
              <?= $_aid === -1 ? 'Kierownik Instytucji' : ($_aid === 0 ? 'Wszyscy administratorzy' : 'Administrator SZO') ?>
            </div>
          </td>
          <td class="text-body-secondary" style="font-size:.8rem"><?= h($_ts) ?></td>
          <td class="text-center">
            <?php if ($_unseen > 0): ?>
            <span class="badge text-bg-danger"><?= $_unseen ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>

      <!-- Kursanci -->
      <tbody>
        <tr class="table-secondary">
          <td colspan="4" class="py-1 px-3" style="font-size:.72rem">
            <i class="bi bi-people me-1" aria-hidden="true"></i>
            <span class="fw-semibold text-uppercase" style="letter-spacing:.06em">Kursanci</span>
            <?php if ($_kurs_unread > 0): ?>
            <span class="badge text-bg-danger ms-1" style="font-size:.62rem"><?= (int)$_kurs_unread ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php if (empty($dyd_msg_threads)): ?>
        <tr>
          <td colspan="4" class="text-center text-body-secondary py-3" style="font-size:.85rem">
            Brak wiadomości od kursantów
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($dyd_msg_threads as $th):
          $isActive = !$dyd_thread_is_admin && $dyd_msg_student_id === (int)$th['id'];
          $_href2   = 'index.php?course='.urlencode($cur_course).'&tab=wiadomosci&student='.rawurlencode((int)$th['id']);
        ?>
        <tr class="dyd-thread-row<?= $isActive ? ' table-primary' : '' ?>"
            style="cursor:pointer" onclick="location.href='<?= h($_href2) ?>'">
          <td class="text-center px-2">
            <i class="bi bi-person-circle text-body-secondary" style="font-size:1.1rem" aria-hidden="true"></i>
          </td>
          <td>
            <div class="fw-semibold"><?= h($th['name']) ?></div>
            <div class="text-body-secondary" style="font-size:.75rem"><?= h($th['login']) ?></div>
          </td>
          <td class="text-body-secondary" style="font-size:.8rem">
            <?= $th['last_at'] ? date('d.m H:i', strtotime($th['last_at'])) : '—' ?>
          </td>
          <td class="text-center">
            <?php if ($th['unread'] > 0): ?>
            <span class="badge text-bg-danger"><?= (int)$th['unread'] ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>

    </table>
  </div>
</div>

<!-- ─── Offcanvas: aktywny wątek ─────────────────────────────────────────── -->
<?php if ($_has_active): ?>
<div class="offcanvas offcanvas-end" tabindex="-1" id="dydThreadCanvas"
     aria-labelledby="dydThreadCanvasLbl" style="width:min(520px,100vw)">

  <!-- Nagłówek offcanvasa -->
  <div class="offcanvas-header border-bottom py-2 gap-2">
    <div class="flex-grow-1 min-width-0">
      <?php if ($dyd_thread_is_admin): ?>
      <h5 class="offcanvas-title mb-0 text-truncate" id="dydThreadCanvasLbl" style="font-size:.95rem">
        <i class="bi bi-building text-primary me-1"></i><?= h(ti_admin_thread_label($dyd_admin_active_id, $dyd_admin_users)) ?>
      </h5>
      <div class="text-body-secondary" style="font-size:.75rem">
        <?= $dyd_admin_active_id === -1 ? 'Kierownik Instytucji' : ($dyd_admin_active_id === 0 ? 'Wszyscy administratorzy' : 'Administrator SZO') ?>
      </div>
      <?php else: ?>
      <h5 class="offcanvas-title mb-0 text-truncate" id="dydThreadCanvasLbl" style="font-size:.95rem">
        <i class="bi bi-person-circle text-primary me-1"></i><?= h($dyd_msg_student['client_name']) ?>
        <?php if ($dyd_msg_is_blocked): ?>
        <span class="badge text-bg-danger ms-1" style="font-size:.62rem"><i class="bi bi-slash-circle me-1"></i>Zablokowany</span>
        <?php endif; ?>
      </h5>
      <div class="text-body-secondary" style="font-size:.75rem"><?= h($dyd_msg_student['login']) ?></div>
      <?php endif; ?>
    </div>

    <?php if ($dyd_msg_student): ?>
    <button class="btn btn-sm btn-outline-secondary py-0 px-2 flex-shrink-0"
            data-bs-toggle="collapse" data-bs-target="#dydMsgLog"
            aria-expanded="false" title="Dziennik zdarzeń kursanta">
      <i class="bi bi-journal-text"></i>
    </button>
    <form method="post" class="d-inline flex-shrink-0">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="<?= $dyd_msg_is_blocked ? 'dyd_msg_unblock' : 'dyd_msg_block' ?>">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <input type="hidden" name="student_id" value="<?= (int)$dyd_msg_student['id'] ?>">
      <button type="submit"
              class="btn btn-sm py-0 px-2 <?= $dyd_msg_is_blocked ? 'btn-outline-success' : 'btn-outline-danger' ?>"
              title="<?= $dyd_msg_is_blocked ? 'Odblokuj wiadomości' : 'Zablokuj wiadomości' ?>"
              onclick="return confirm('<?= $dyd_msg_is_blocked ? 'Odblokować wiadomości od tego kursanta?' : 'Zablokować wysyłanie wiadomości przez kursanta?' ?>')">
        <i class="bi bi-<?= $dyd_msg_is_blocked ? 'unlock' : 'slash-circle' ?>"></i>
      </button>
    </form>
    <?php endif; ?>

    <button type="button" class="btn-close flex-shrink-0" data-bs-dismiss="offcanvas" aria-label="Zamknij"></button>
  </div>

  <!-- Ciało offcanvasa: dziennik + wiadomości + formularz -->
  <div class="offcanvas-body p-0 d-flex flex-column" style="overflow:hidden">

    <?php if ($dyd_msg_student): ?>
    <!-- Dziennik zdarzeń (zwinięty) -->
    <div class="collapse" id="dydMsgLog">
      <div class="border-bottom px-3 py-2" style="max-height:200px;overflow-y:auto;font-size:.78rem">
        <?php if (empty($dyd_msg_log)): ?>
        <p class="text-muted mb-0 text-center py-2">Brak wpisów w dzienniku.</p>
        <?php else: ?>
        <table class="table table-sm table-borderless mb-0">
          <thead><tr class="text-muted"><th>Czas</th><th>Zdarzenie</th><th>Kto</th><th>Szczegóły</th><th>IP</th></tr></thead>
          <tbody>
          <?php
          $action_labels = [
              'login'               => 'Logowanie',
              'login_failed'        => 'Nieudane logowanie',
              'logout'              => 'Wylogowanie',
              'password_changed'    => 'Zmiana hasła',
              'alias_changed'       => 'Zmiana aliasu',
              'msg_sent'            => 'Wiadomość od kursanta',
              'msg_sent_by_staff'   => 'Odpowiedź prowadzącego',
              'msg_blocked'         => 'Zablokowano wiadomości',
              'msg_unblocked'       => 'Odblokowano wiadomości',
              'msg_blocked_attempt' => 'Próba wysyłki przy blokadzie',
              'msg_archived'        => 'Zarchiwizowano wiadomość',
          ];
          foreach ($dyd_msg_log as $le): ?>
          <tr>
            <td class="text-nowrap text-muted"><?= $le['created_at'] ? date('d.m H:i', strtotime($le['created_at'])) : '' ?></td>
            <td><?= h($action_labels[$le['action']] ?? $le['action']) ?></td>
            <td><?= $le['by_name'] ? h($le['by_name']) : '<span class="text-muted">kursant</span>' ?></td>
            <td class="text-muted"><?= h($le['detail']) ?></td>
            <td class="text-muted" style="font-size:.72rem">
              <?php $dev = !empty($le['user_agent']) ? ti_log_device_label($le['user_agent']) : ''; echo $dev ? h($dev) : ''; ?>
              <?php if (!empty($le['ip'])): ?><br><?= h($le['ip']) ?><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Lista wiadomości — scrollowalny środek -->
    <div class="flex-grow-1 px-3 py-3" id="dydCanvasMessages"
         style="overflow-y:auto;min-height:0">

      <?php if ($dyd_thread_is_admin): ?>

      <?php if (empty($dyd_admin_thread)): ?>
      <p class="text-muted small text-center mt-4">Brak wiadomości. Kliknij „Nowa wiadomość", żeby zacząć rozmowę.</p>
      <?php else: ?>
      <ol class="list-unstyled d-flex flex-column gap-3 mb-0" aria-label="Wiadomości do kierownictwa">
        <?php foreach ($dyd_admin_thread as $am):
          $amTs        = $am['created_at'] ? date('d.m.Y H:i', strtotime($am['created_at'])) : '';
          $amRepliedTs = $am['replied_at'] ? date('d.m.Y H:i', strtotime($am['replied_at'])) : '';
        ?>
        <li>
          <article>
            <header class="d-flex align-items-baseline gap-2 mb-1" style="font-size:.78rem">
              <span class="fw-semibold"><?= h($am['user_name']) ?></span>
              <span class="text-muted">prowadzący</span>
              <?php if ($am['subject']): ?><span class="text-muted">· <?= h($am['subject']) ?></span><?php endif; ?>
              <time class="text-muted ms-auto" datetime="<?= h($am['created_at'] ?? '') ?>"><?= h($amTs) ?></time>
            </header>
            <div class="border rounded-2 p-2 border-primary border-opacity-25 bg-primary bg-opacity-10"
                 style="font-size:.875rem"><?= ti_msg_render($am['body']) ?></div>
            <?= ti_msg_render_attachments((int)$am['id'], 'admin', 'msg_attachment.php') ?>
            <?php if ($am['reply_body']): ?>
            <div class="mt-2 border rounded-2 p-2 border-success border-opacity-25 bg-success bg-opacity-10"
                 style="font-size:.875rem;white-space:pre-wrap">
              <div class="d-flex align-items-baseline gap-2 mb-1" style="font-size:.78rem">
                <span class="fw-semibold"><?= h($am['reply_by'] ?: 'Kierownictwo') ?></span>
                <span class="text-muted">odpowiedź</span>
                <time class="text-muted ms-auto"><?= h($amRepliedTs) ?></time>
              </div>
              <?= ti_msg_render($am['reply_body']) ?>
            </div>
            <?php endif; ?>
          </article>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>

      <?php elseif ($dyd_msg_student): ?>

      <?php if (empty($dyd_msg_thread)): ?>
      <p class="text-muted small text-center mt-4">Brak wiadomości.</p>
      <?php else: ?>
      <ol class="list-unstyled d-flex flex-column gap-3 mb-0" aria-label="Wiadomości">
        <?php foreach ($dyd_msg_thread as $msg):
          $fromStaff  = ($msg['sender'] !== 'student');
          $ts         = $msg['created_at'] ? date('d.m.Y H:i', strtotime($msg['created_at'])) : '';
          $name       = $fromStaff ? ($msg['sender_name'] ?? 'Prowadzący') : $dyd_msg_student['client_name'];
          $isArchived = !empty($msg['is_archived']);
        ?>
        <li <?= $isArchived ? 'style="opacity:.45"' : '' ?>>
          <article>
            <header class="d-flex align-items-baseline gap-2 mb-1" style="font-size:.78rem">
              <span class="fw-semibold"><?= h($name) ?></span>
              <span class="text-muted"><?= $fromStaff ? 'prowadzący' : 'kursant' ?></span>
              <time class="text-muted ms-auto" datetime="<?= h($msg['created_at'] ?? '') ?>"><?= h($ts) ?></time>
              <?php if (!$isArchived): ?>
              <form method="post" class="d-inline ms-1">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
                <input type="hidden" name="_op" value="dyd_msg_archive">
                <input type="hidden" name="course_id" value="<?= $cur_course ?>">
                <input type="hidden" name="student_id" value="<?= (int)$dyd_msg_student['id'] ?>">
                <input type="hidden" name="msg_id" value="<?= (int)$msg['id'] ?>">
                <button type="submit" class="btn btn-link btn-sm p-0 text-muted" title="Archiwizuj"
                        onclick="return confirm('Zarchiwizować tę wiadomość?')">
                  <i class="bi bi-archive" style="font-size:.8rem"></i>
                </button>
              </form>
              <?php else: ?>
              <span class="badge bg-secondary ms-1" style="font-size:.65rem">arch.</span>
              <?php endif; ?>
            </header>
            <div class="border rounded-2 p-2 <?= $fromStaff ? 'border-primary border-opacity-25 bg-primary bg-opacity-10' : '' ?>"
                 style="font-size:.875rem"><?= ti_msg_render($msg['body']) ?></div>
            <?= ti_msg_render_attachments((int)$msg['id'], 'student', 'msg_attachment.php') ?>
          </article>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>

      <?php endif; ?>
    </div><!-- /messages -->

    <!-- Formularz odpowiedzi — przyklejony do dołu -->
    <div class="border-top p-3 flex-shrink-0">
      <?php if ($dyd_thread_is_admin): ?>
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="dyd_msg_admin_send">
        <input type="hidden" name="course_id" value="<?= $cur_course ?>">
        <input type="hidden" name="to_admin_id" value="<?= $dyd_admin_active_id ?>">
        <div class="d-flex gap-2">
          <textarea class="form-control form-control-sm" id="dydAdminReplyBody" name="body" rows="3"
                    placeholder="Napisz kolejną wiadomość…" required style="resize:none"></textarea>
          <button class="btn btn-primary btn-sm align-self-end" type="submit">
            <i class="bi bi-send"></i><span class="visually-hidden">Wyślij</span>
          </button>
        </div>
      </form>
      <?php elseif ($dyd_msg_student): ?>
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="dyd_msg_reply">
        <input type="hidden" name="course_id" value="<?= $cur_course ?>">
        <input type="hidden" name="student_id" value="<?= (int)$dyd_msg_student['id'] ?>">
        <label class="form-label small fw-semibold mb-1" for="dydReplyBody">Odpowiedź</label>
        <div class="d-flex gap-2">
          <textarea class="form-control form-control-sm" id="dydReplyBody" name="body" rows="3"
                    placeholder="Napisz odpowiedź…" required style="resize:none"></textarea>
          <button class="btn btn-primary btn-sm align-self-end" type="submit">
            <i class="bi bi-send"></i><span class="visually-hidden">Wyślij</span>
          </button>
        </div>
      </form>
      <?php endif; ?>
    </div>

  </div><!-- /offcanvas-body -->
</div><!-- /offcanvas -->
<script>
document.addEventListener('DOMContentLoaded', function () {
  var el = document.getElementById('dydThreadCanvas');
  if (!el) return;
  var oc = bootstrap.Offcanvas.getOrCreateInstance(el);
  oc.show();
  // Przewiń wiadomości na dół po otwarciu
  el.addEventListener('shown.bs.offcanvas', function () {
    var msgs = document.getElementById('dydCanvasMessages');
    if (msgs) msgs.scrollTop = msgs.scrollHeight;
  });
  // Przy zamknięciu usuń param wątku z URL (bez przeładowania)
  el.addEventListener('hidden.bs.offcanvas', function () {
    var u = new URL(window.location.href);
    u.searchParams.delete('student');
    u.searchParams.delete('thread');
    u.searchParams.delete('to_admin');
    history.replaceState(null, '', u.toString());
  });
  // Edytor odpowiedzi w offcanvasie — dopiero po pokazaniu (textarea była display:none)
  el.addEventListener('shown.bs.offcanvas', function () {
    dydInitMsgEditor('#dydAdminReplyBody, #dydReplyBody', 120);
  });
});
</script>
<?php endif; ?>

<!-- Edytor WYSIWYG treści wiadomości (lekki: bez obrazków/tabel) — ładowany raz,
     inicjalizowany dopiero po pokazaniu modala/offcanvasu (textarea display:none
     wcześniej łamie TinyMCE). tinymce.triggerSave() przed każdym submit zapisuje
     HTML z edytora z powrotem do textarea, żeby reszta JS (budowanie _op itd.)
     czytała aktualną treść. -->
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
function dydInitMsgEditor(selector, height) {
  if (!window.tinymce) return;
  tinymce.init({
    selector: selector,
    license_key: 'gpl',
    promotion: false,
    branding: false,
    menubar: false,
    toolbar: 'undo redo | bold italic underline | bullist numlist | link | removeformat',
    plugins: 'lists link',
    height: height || 200,
    entity_encoding: 'raw',
    setup: function (editor) { editor.on('change', function () { editor.save(); }); }
  });
}
document.addEventListener('submit', function () {
  if (window.tinymce) tinymce.triggerSave();
}, true);
document.addEventListener('DOMContentLoaded', function () {
  var newMsgModal = document.getElementById('dydMsgNew');
  if (newMsgModal) {
    newMsgModal.addEventListener('shown.bs.modal', function () {
      if (tinymce.get('dydMsgNewBody')) return; // już zainicjalizowany — nie od nowa
      dydInitMsgEditor('#dydMsgNewBody', 220);
    });
  }
});
</script>

<!-- Modal nowej wiadomości — dwie kolumny: adresat (drzewo) | treść -->
<div class="modal fade" id="dydMsgNew" tabindex="-1" aria-labelledby="dydMsgNewLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" id="dydMsgNewForm" enctype="multipart/form-data">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="course_id" value="<?= $cur_course ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="dydMsgNewLabel"><i class="bi bi-pencil-square me-2"></i>Nowa wiadomość</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <!-- ═══ Kolumna 1: adresat (drzewo) ═══ -->
            <div class="col-md-5">
              <label class="form-label fw-semibold" id="dydMsgRecipLbl">Adresat <span class="text-danger">*</span></label>
              <input type="hidden" id="dydMsgRecip" name="_recip">
              <div class="border rounded" id="dydMsgRecipTree" role="tree" aria-labelledby="dydMsgRecipLbl"
                   style="max-height:340px;overflow-y:auto">
                <?php if (!empty($dyd_msg_accounts)): ?>
                <details open class="dyd-recip-group">
                  <summary class="fw-semibold px-2 py-1"><i class="bi bi-people me-1" aria-hidden="true"></i>Kursanci</summary>
                  <div class="pb-1">
                    <?php foreach ($dyd_msg_accounts as $a): ?>
                    <button type="button" class="dydRecipItem btn btn-sm d-block w-100 text-start border-0 rounded-0"
                            data-value="s_<?= (int)$a['id'] ?>"><?= h($a['name']) ?> — <?= h($a['course_name']) ?></button>
                    <?php endforeach; ?>
                  </div>
                </details>
                <?php endif; ?>
                <details open class="dyd-recip-group">
                  <summary class="fw-semibold px-2 py-1"><i class="bi bi-building me-1" aria-hidden="true"></i>Kierownictwo</summary>
                  <div class="pb-1">
                    <button type="button" class="dydRecipItem btn btn-sm d-block w-100 text-start border-0 rounded-0"
                            data-value="a_-1"><?= h(TI_KIS_NAME) ?> — Kierownik Instytucji</button>
                    <button type="button" class="dydRecipItem btn btn-sm d-block w-100 text-start border-0 rounded-0"
                            data-value="a_0">Administratorzy (wszyscy)</button>
                    <?php foreach ($dyd_admin_users as $au): ?>
                    <button type="button" class="dydRecipItem btn btn-sm d-block w-100 text-start border-0 rounded-0"
                            data-value="a_<?= (int)$au['id'] ?>"><?= h($au['name']) ?></button>
                    <?php endforeach; ?>
                  </div>
                </details>
                <details open class="dyd-recip-group">
                  <summary class="fw-semibold px-2 py-1"><i class="bi bi-life-preserver me-1" aria-hidden="true"></i>Pomoc techniczna</summary>
                  <div class="pb-1">
                    <button type="button" class="dydRecipItem btn btn-sm d-block w-100 text-start border-0 rounded-0"
                            data-value="h_1">Helpdesk IT — zgłoś problem</button>
                  </div>
                </details>
              </div>
              <div class="small text-body-secondary mt-1" id="dydMsgRecipHint">Wybierz adresata z listy powyżej.</div>
            </div>

            <!-- ═══ Kolumna 2: temat + treść (WYSIWYG) + załączniki ═══ -->
            <div class="col-md-7">
              <!-- Pola specyficzne dla kursanta -->
              <div id="dydMsgStudentFields">
                <div class="mb-3">
                  <label class="form-label">Temat</label>
                  <input type="text" class="form-control" name="subject_s" placeholder="Temat wiadomości (opcjonalny)">
                </div>
              </div>
              <!-- Pola specyficzne dla admina -->
              <div id="dydMsgAdminFields" class="d-none">
                <div class="mb-3">
                  <label class="form-label">Temat</label>
                  <input type="text" class="form-control" name="subject_a" placeholder="Temat wiadomości (opcjonalny)">
                </div>
              </div>
              <!-- Pola specyficzne dla helpdesku -->
              <div id="dydMsgHelpdeskFields" class="d-none">
                <div class="mb-3">
                  <label class="form-label">Temat zgłoszenia</label>
                  <input type="text" class="form-control" name="subject_h" placeholder="np. Nie działa link do spotkania Zoom">
                </div>
                <p class="text-body-secondary small">Zgłoszenie trafi do Helpdesku IT, nie do kierownictwa placówki.</p>
              </div>
              <div class="mb-2">
                <label class="form-label fw-semibold" for="dydMsgNewBody">Treść <span class="text-danger">*</span></label>
                <textarea class="form-control" id="dydMsgNewBody" name="body" rows="6" required placeholder="Napisz wiadomość…"></textarea>
              </div>
              <div class="mb-2">
                <label class="form-label">Załączniki <span class="text-body-secondary fw-normal">(opcjonalnie)</span></label>
                <input type="file" class="form-control" name="attachments[]" multiple
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv">
                <div class="form-text">Maks. 10 MB / plik.</div>
              </div>
            </div>
          </div>
          <style>
            #dydMsgRecipTree summary { cursor: pointer; list-style: none; background: var(--bs-tertiary-bg); }
            #dydMsgRecipTree summary::-webkit-details-marker { display: none; }
            #dydMsgRecipTree summary::before { content: "\25B8"; display: inline-block; width: 1em; transition: transform .1s; }
            #dydMsgRecipTree details[open] > summary::before { transform: rotate(90deg); }
            #dydMsgRecipTree .dyd-recip-group + .dyd-recip-group { border-top: 1px solid var(--bs-border-color); }
            .dydRecipItem { padding-left: 2rem !important; font-size: .875rem; }
            .dydRecipItem:hover { background: var(--bs-tertiary-bg); }
            .dydRecipItem.active { background: var(--bs-primary); color: #fff; }
          </style>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Wyślij</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(function () {
  var modal = document.getElementById('dydMsgNew');
  var form  = document.getElementById('dydMsgNewForm');
  var sel   = document.getElementById('dydMsgRecip');
  var sflds = document.getElementById('dydMsgStudentFields');
  var aflds = document.getElementById('dydMsgAdminFields');
  var hflds = document.getElementById('dydMsgHelpdeskFields');
  var hint  = document.getElementById('dydMsgRecipHint');
  var items = Array.prototype.slice.call(document.querySelectorAll('.dydRecipItem'));

  function applyRecip() {
    var v = sel ? sel.value : '';
    var isAdmin    = v.startsWith('a_');
    var isHelpdesk = v.startsWith('h_');
    sflds.classList.toggle('d-none', isAdmin || isHelpdesk);
    aflds.classList.toggle('d-none', !isAdmin);
    hflds.classList.toggle('d-none', !isHelpdesk);
  }

  // Drzewo adresatów: klik na pozycję ustawia ukryte pole + podświetla wybór
  function selectRecip(btn) {
    items.forEach(function (b) { b.classList.remove('active'); });
    btn.classList.add('active');
    sel.value = btn.dataset.value;
    if (hint) { hint.textContent = 'Wybrano: ' + btn.textContent.trim(); hint.classList.remove('text-danger'); }
    applyRecip();
  }
  items.forEach(function (btn) {
    btn.addEventListener('click', function () { selectRecip(btn); });
  });
  applyRecip();

  // Przycisk "Nowa" w sekcji Kierownictwo/Kursanci — pre-select w drzewie
  if (modal) {
    modal.addEventListener('show.bs.modal', function (e) {
      var btn = e.relatedTarget;
      var wantPrefix = null;
      if (btn && btn.dataset.section === 'admin') wantPrefix = 'a_';
      else if (btn && btn.dataset.section === 'student') wantPrefix = 's_';
      if (wantPrefix) {
        var target = items.filter(function (b) { return b.dataset.value.indexOf(wantPrefix) === 0; })[0];
        if (target) selectRecip(target);
      }
    });
  }

  // Podmień _op i hidden field w zależności od rodzaju adresata
  if (form) {
    form.addEventListener('submit', function (ev) {
      var v = sel ? sel.value : '';
      if (!v) {
        ev.preventDefault();
        if (hint) { hint.textContent = 'Wybierz adresata z listy powyżej — to pole jest wymagane.'; hint.classList.add('text-danger'); }
        return;
      }
      var opInput    = form.querySelector('input[name="_op"]');
      var accInput   = form.querySelector('input[name="account_id"]');
      var adminInput = form.querySelector('input[name="to_admin_id"]');
      if (!opInput) { opInput = document.createElement('input'); opInput.type='hidden'; opInput.name='_op'; form.appendChild(opInput); }
      if (v.startsWith('s_')) {
        opInput.value = 'dyd_msg_send';
        if (!accInput) { accInput = document.createElement('input'); accInput.type='hidden'; accInput.name='account_id'; form.appendChild(accInput); }
        accInput.value = v.slice(2);
        // subject
        var subEl = form.querySelector('[name="subject_s"]');
        var subOut = form.querySelector('[name="subject"]');
        if (!subOut) { subOut = document.createElement('input'); subOut.type='hidden'; subOut.name='subject'; form.appendChild(subOut); }
        if (subEl) subOut.value = subEl.value;
        if (adminInput) adminInput.value = '';
      } else if (v.startsWith('h_')) {
        opInput.value = 'dyd_msg_helpdesk_send';
        var subElH = form.querySelector('[name="subject_h"]');
        var subOutH = form.querySelector('[name="subject"]');
        if (!subOutH) { subOutH = document.createElement('input'); subOutH.type='hidden'; subOutH.name='subject'; form.appendChild(subOutH); }
        if (subElH) subOutH.value = subElH.value;
        if (accInput) accInput.value = '';
        if (adminInput) adminInput.value = '';
      } else {
        opInput.value = 'dyd_msg_admin_send';
        if (!adminInput) { adminInput = document.createElement('input'); adminInput.type='hidden'; adminInput.name='to_admin_id'; form.appendChild(adminInput); }
        adminInput.value = v.slice(2); // '-1', '0' or '456'
        var subEl = form.querySelector('[name="subject_a"]');
        var subOut = form.querySelector('[name="subject"]');
        if (!subOut) { subOut = document.createElement('input'); subOut.type='hidden'; subOut.name='subject'; form.appendChild(subOut); }
        if (subEl) subOut.value = subEl.value;
        if (accInput) accInput.value = '';
      }
    });
  }
})();
</script>

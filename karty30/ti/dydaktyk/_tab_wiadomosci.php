<?php /* ═══════════════════════ TAB: WIADOMOŚCI ═══════════════════════ */
// Zbierz to_admin_id → label dla istniejących wątków adminów
$_admin_thread_map = [];
foreach ($dyd_admin_threads as $_at) {
    $_aid = ($_at['to_admin_id'] === null) ? 0 : (int)$_at['to_admin_id'];
    $_admin_thread_map[$_aid] = $_at;
}
$_has_admin_threads = !empty($dyd_admin_threads);
?>
<div class="row g-3">
  <!-- Lista wątków -->
  <div class="col-md-4">
    <div class="border rounded" style="max-height:65vh;overflow-y:auto">

      <!-- Sekcja: Kierownictwo (tylko jeśli istnieją wątki lub aktywny) -->
      <?php $_kier_unread = (int)$dyd_admin_unseen; ?>
      <div class="px-2 py-1 d-flex align-items-center gap-1 bg-body-tertiary border-bottom">
        <button class="btn btn-link btn-sm p-0 text-body fw-semibold d-flex align-items-center gap-1 flex-grow-1"
                style="font-size:.78rem;text-decoration:none"
                data-bs-toggle="collapse" data-bs-target="#dydMsgSect1"
                aria-expanded="<?= ($dyd_thread_is_admin || $_has_admin_threads) ? 'true' : 'false' ?>">
          <i class="bi bi-chevron-down" style="font-size:.65rem;transition:transform .2s" aria-hidden="true"></i>
          <i class="bi bi-building me-1" aria-hidden="true"></i>Kierownictwo
          <?php if ($_kier_unread > 0): ?>
          <span class="badge bg-danger ms-1" style="font-size:.65rem"><?= $_kier_unread ?></span>
          <?php endif; ?>
        </button>
        <button type="button" class="btn btn-sm btn-primary py-0 px-2" style="font-size:.75rem"
                data-bs-toggle="modal" data-bs-target="#dydMsgNew"
                data-section="admin" title="Nowa wiadomość do kierownictwa">
          <i class="bi bi-pencil-square"></i><span class="visually-hidden">Nowa</span>
        </button>
      </div>
      <div class="collapse <?= ($dyd_thread_is_admin || $_has_admin_threads) ? 'show' : '' ?>" id="dydMsgSect1">
        <?php if (empty($dyd_admin_threads)): ?>
        <div class="text-muted small py-3 text-center px-2" style="font-size:.8rem">
          <i class="bi bi-building opacity-25 d-block mb-1" style="font-size:1.3rem"></i>Brak wiadomości
        </div>
        <?php else: ?>
        <?php foreach ($dyd_admin_threads as $_at):
          $_aid    = ($_at['to_admin_id'] === null) ? 0 : (int)$_at['to_admin_id'];
          $_label  = ti_admin_thread_label($_aid, $dyd_admin_users);
          $_isAct  = $dyd_thread_is_admin && $dyd_admin_active_id === $_aid;
          $_unseen = (int)$_at['unseen'];
          $_ts     = $_at['last_at'] ? date('d.m H:i', strtotime($_at['last_at'])) : '';
        ?>
        <a href="index.php?course=<?= $cur_course ?>&tab=wiadomosci&thread=admin&to_admin=<?= $_aid ?>"
           class="list-group-item list-group-item-action py-2 px-3 border-0 <?= $_isAct ? 'active' : '' ?>">
          <div class="d-flex justify-content-between align-items-start">
            <span class="fw-semibold small">
              <i class="bi bi-<?= $_aid === 0 ? 'people' : 'person-circle' ?> me-1 opacity-50" aria-hidden="true"></i><?= h($_label) ?>
            </span>
            <?php if ($_unseen > 0): ?>
            <span class="badge bg-danger ms-1"><?= $_unseen ?></span>
            <?php endif; ?>
          </div>
          <?php if ($_ts): ?>
          <div class="small <?= $_isAct ? 'text-white-50' : 'text-muted' ?>" style="font-size:.75rem"><?= $_ts ?></div>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Sekcja: Kursanci -->
      <?php $_kurs_unread = array_sum(array_column($dyd_msg_threads, 'unread')); ?>
      <div class="px-2 py-1 d-flex align-items-center gap-1 bg-body-tertiary border-top border-bottom">
        <button class="btn btn-link btn-sm p-0 text-body fw-semibold d-flex align-items-center gap-1 flex-grow-1"
                style="font-size:.78rem;text-decoration:none"
                data-bs-toggle="collapse" data-bs-target="#dydMsgSect2"
                aria-expanded="true">
          <i class="bi bi-chevron-down" style="font-size:.65rem;transition:transform .2s" aria-hidden="true"></i>
          <i class="bi bi-people me-1" aria-hidden="true"></i>Kursanci
          <?php if ($_kurs_unread > 0): ?>
          <span class="badge bg-danger ms-1" style="font-size:.65rem"><?= (int)$_kurs_unread ?></span>
          <?php endif; ?>
        </button>
        <button type="button" class="btn btn-sm btn-primary py-0 px-2" style="font-size:.75rem"
                data-bs-toggle="modal" data-bs-target="#dydMsgNew"
                data-section="student" title="Nowa wiadomość do kursanta"
                <?= empty($dyd_msg_accounts) ? 'disabled' : '' ?>>
          <i class="bi bi-pencil-square"></i><span class="visually-hidden">Nowa</span>
        </button>
      </div>
      <div class="collapse show" id="dydMsgSect2">
        <?php if (empty($dyd_msg_threads)): ?>
        <div class="text-muted small py-3 text-center px-2" style="font-size:.8rem">
          <i class="bi bi-envelope opacity-25 d-block mb-1" style="font-size:1.3rem"></i>Brak wiadomości od kursantów
        </div>
        <?php else: ?>
        <?php foreach ($dyd_msg_threads as $th): $isActive = !$dyd_thread_is_admin && $dyd_msg_student_id === (int)$th['id']; ?>
        <a href="index.php?course=<?= $cur_course ?>&tab=wiadomosci&student=<?= (int)$th['id'] ?>"
           class="list-group-item list-group-item-action py-2 px-3 border-0 <?= $isActive ? 'active' : '' ?>">
          <div class="d-flex justify-content-between align-items-start">
            <span class="fw-semibold small"><?= h($th['name']) ?></span>
            <?php if ($th['unread'] > 0): ?>
            <span class="badge bg-danger ms-1"><?= (int)$th['unread'] ?></span>
            <?php endif; ?>
          </div>
          <div class="small <?= $isActive ? 'text-white-50' : 'text-muted' ?>" style="font-size:.75rem">
            <?= h($th['login']) ?> · <?= $th['last_at'] ? date('d.m H:i', strtotime($th['last_at'])) : '' ?>
          </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
  </div>

  <!-- Aktywny wątek -->
  <div class="col-md-8">
    <?php if ($dyd_thread_is_admin): ?>
    <!-- ── Wątek: Kierownictwo ─────────────────────────────────────────────── -->
    <div class="d-flex align-items-center gap-2 mb-3">
      <span class="fw-semibold">
        <i class="bi bi-building text-primary me-1"></i><?= h(ti_admin_thread_label($dyd_admin_active_id, $dyd_admin_users)) ?>
      </span>
      <span class="text-muted small"><?= $dyd_admin_active_id === -1 ? 'Kierownik Instytucji' : ($dyd_admin_active_id === 0 ? 'Wszyscy administratorzy' : 'Administrator SZO') ?></span>
    </div>
    <?php if (empty($dyd_admin_thread)): ?>
    <p class="text-muted small text-center mt-3">Brak wiadomości. Użyj przycisku „Nowa" po lewej, żeby zacząć rozmowę.</p>
    <?php else: ?>
    <ol class="list-unstyled d-flex flex-column gap-2 mb-3" aria-label="Wiadomości do kierownictwa">
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
               style="font-size:.875rem;white-space:pre-wrap"><?= h($am['body']) ?></div>
          <?php if ($am['reply_body']): ?>
          <div class="mt-2 border rounded-2 p-2 border-success border-opacity-25 bg-success bg-opacity-10"
               style="font-size:.875rem;white-space:pre-wrap">
            <div class="d-flex align-items-baseline gap-2 mb-1" style="font-size:.78rem">
              <span class="fw-semibold"><?= h($am['reply_by'] ?: 'Kierownictwo') ?></span>
              <span class="text-muted">odpowiedź</span>
              <time class="text-muted ms-auto"><?= h($amRepliedTs) ?></time>
            </div>
            <?= h($am['reply_body']) ?>
          </div>
          <?php endif; ?>
        </article>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>
    <!-- Formularz kolejnej wiadomości w tym wątku -->
    <form method="post" class="mt-2">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="dyd_msg_admin_send">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <input type="hidden" name="to_admin_id" value="<?= $dyd_admin_active_id ?>">
      <div class="d-flex gap-2">
        <textarea class="form-control form-control-sm" name="body" rows="3"
                  placeholder="Napisz kolejną wiadomość…" required style="resize:none"></textarea>
        <button class="btn btn-primary btn-sm align-self-end" type="submit">
          <i class="bi bi-send"></i><span class="visually-hidden">Wyślij</span>
        </button>
      </div>
    </form>

    <?php elseif ($dyd_msg_student): ?>

    <!-- Nagłówek: imię + akcje -->
    <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
      <span class="fw-semibold">
        <i class="bi bi-person-circle text-primary me-1"></i>
        <?= h($dyd_msg_student['client_name']) ?>
        <span class="text-muted fw-normal small">(<?= h($dyd_msg_student['login']) ?>)</span>
      </span>
      <?php if ($dyd_msg_is_blocked): ?>
      <span class="badge bg-danger ms-1"><i class="bi bi-slash-circle me-1"></i>Zablokowany</span>
      <?php endif; ?>
      <div class="ms-auto d-flex gap-1">
        <form method="post" class="d-inline">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="<?= $dyd_msg_is_blocked ? 'dyd_msg_unblock' : 'dyd_msg_block' ?>">
          <input type="hidden" name="course_id" value="<?= $cur_course ?>">
          <input type="hidden" name="student_id" value="<?= (int)$dyd_msg_student['id'] ?>">
          <button type="submit" class="btn btn-sm <?= $dyd_msg_is_blocked ? 'btn-outline-success' : 'btn-outline-danger' ?>"
                  onclick="return confirm('<?= $dyd_msg_is_blocked ? 'Odblokować wiadomości od tego kursanta?' : 'Zablokować wysyłanie wiadomości przez kursanta?' ?>')">
            <i class="bi bi-<?= $dyd_msg_is_blocked ? 'unlock' : 'slash-circle' ?> me-1"></i><?= $dyd_msg_is_blocked ? 'Odblokuj' : 'Zablokuj' ?>
          </button>
        </form>
        <button class="btn btn-sm btn-outline-secondary" type="button"
                data-bs-toggle="collapse" data-bs-target="#dydMsgLog" aria-expanded="false" aria-controls="dydMsgLog">
          <i class="bi bi-journal-text me-1"></i>Dziennik
        </button>
      </div>
    </div>

    <!-- Dziennik zdarzeń (zwinięty) -->
    <div class="collapse mb-3" id="dydMsgLog">
      <div class="border rounded-2 p-2" style="max-height:200px;overflow-y:auto;font-size:.78rem">
        <?php if (empty($dyd_msg_log)): ?>
        <p class="text-muted mb-0 text-center py-2">Brak wpisów w dzienniku.</p>
        <?php else: ?>
        <table class="table table-sm table-borderless mb-0">
          <thead><tr class="text-muted"><th>Czas</th><th>Zdarzenie</th><th>Kto</th><th>Szczegóły</th><th>Urządzenie / IP</th></tr></thead>
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
            <td class="text-muted" style="font-size:.75rem">
              <?php $dev = !empty($le['user_agent']) ? ti_log_device_label($le['user_agent']) : ''; echo $dev ? h($dev) : ''; ?>
              <?php if (!empty($le['ip'])): ?><br><span style="font-size:.7rem"><?= h($le['ip']) ?></span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Lista wiadomości -->
    <?php if (empty($dyd_msg_thread)): ?>
    <p class="text-muted small text-center mt-3">Brak wiadomości.</p>
    <?php else: ?>
    <ol class="list-unstyled d-flex flex-column gap-2 mb-3" aria-label="Wiadomości">
      <?php foreach ($dyd_msg_thread as $msg):
        $fromStaff = ($msg['sender'] !== 'student');
        $ts = $msg['created_at'] ? date('d.m.Y H:i', strtotime($msg['created_at'])) : '';
        $name = $fromStaff ? ($msg['sender_name'] ?? 'Prowadzący') : $dyd_msg_student['client_name'];
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
               style="font-size:.875rem;white-space:pre-wrap"><?= h($msg['body']) ?></div>
        </article>
      </li>
      <?php endforeach; ?>
    </ol>
    <?php endif; ?>

    <!-- Formularz odpowiedzi -->
    <form method="post">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="dyd_msg_reply">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <input type="hidden" name="student_id" value="<?= (int)$dyd_msg_student['id'] ?>">
      <label class="form-label small fw-semibold" for="dydReplyBody">Odpowiedź</label>
      <div class="d-flex gap-2">
        <textarea class="form-control form-control-sm" id="dydReplyBody" name="body" rows="3"
                  placeholder="Napisz odpowiedź…" required style="resize:none"></textarea>
        <button class="btn btn-primary btn-sm align-self-end" type="submit">
          <i class="bi bi-send"></i><span class="visually-hidden">Wyślij</span>
        </button>
      </div>
    </form>

    <?php else: ?>
    <div class="d-flex flex-column align-items-center justify-content-center h-100 text-muted" style="min-height:200px">
      <i class="bi bi-envelope-open" style="font-size:2.5rem;opacity:.3"></i>
      <p class="mt-2 small">Wybierz wątek z listy lub napisz nową wiadomość.</p>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal nowej wiadomości -->
<div class="modal fade" id="dydMsgNew" tabindex="-1" aria-labelledby="dydMsgNewLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" id="dydMsgNewForm">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="course_id" value="<?= $cur_course ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="dydMsgNewLabel"><i class="bi bi-pencil-square me-2"></i>Nowa wiadomość</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <!-- Rodzaj adresata -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Adresat <span class="text-danger">*</span></label>
            <select class="form-select" id="dydMsgRecip" name="_recip" required>
              <?php if (!empty($dyd_msg_accounts)): ?>
              <optgroup label="Kursanci">
                <?php foreach ($dyd_msg_accounts as $a): ?>
                <option value="s_<?= (int)$a['id'] ?>"><?= h($a['name']) ?> — <?= h($a['course_name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
              <?php endif; ?>
              <optgroup label="Kierownictwo">
                <option value="a_-1"><?= h(TI_KIS_NAME) ?> — Kierownik Instytucji</option>
                <option value="a_0">Administratorzy (wszyscy)</option>
                <?php foreach ($dyd_admin_users as $au): ?>
                <option value="a_<?= (int)$au['id'] ?>"><?= h($au['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            </select>
          </div>
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
          <div class="mb-2">
            <label class="form-label fw-semibold">Treść <span class="text-danger">*</span></label>
            <textarea class="form-control" name="body" rows="4" required placeholder="Napisz wiadomość…"></textarea>
          </div>
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

  function applyRecip() {
    var v = sel ? sel.value : '';
    var isAdmin = v.startsWith('a_');
    sflds.classList.toggle('d-none', isAdmin);
    aflds.classList.toggle('d-none', !isAdmin);
  }
  if (sel) sel.addEventListener('change', applyRecip);
  applyRecip();

  // Przycisk "Nowa" w sekcji Kierownictwo — pre-select admina
  if (modal) {
    modal.addEventListener('show.bs.modal', function (e) {
      var btn = e.relatedTarget;
      if (btn && btn.dataset.section === 'admin') {
        // wybierz pierwszą opcję z Kierownictwo (a_-1)
        if (sel) {
          for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value.startsWith('a_')) { sel.selectedIndex = i; break; }
          }
          applyRecip();
        }
      } else if (btn && btn.dataset.section === 'student') {
        if (sel) {
          for (var i = 0; i < sel.options.length; i++) {
            if (sel.options[i].value.startsWith('s_')) { sel.selectedIndex = i; break; }
          }
          applyRecip();
        }
      }
    });
  }

  // Podmień _op i hidden field w zależności od rodzaju adresata
  if (form) {
    form.addEventListener('submit', function () {
      var v = sel ? sel.value : '';
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

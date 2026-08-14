<?php /* ═══════════════════════ TAB: WIADOMOŚCI ═══════════════════════ */ ?>
<div class="row g-3">
  <!-- Lista wątków -->
  <div class="col-md-4">
    <div class="border rounded" style="max-height:65vh;overflow-y:auto">
      <!-- Sekcja 1: Kierownik Instytucji -->
      <div class="px-2 py-1 d-flex align-items-center gap-1 bg-body-tertiary border-bottom">
        <button class="btn btn-link btn-sm p-0 text-body fw-semibold d-flex align-items-center gap-1 flex-grow-1"
                style="font-size:.78rem;text-decoration:none"
                data-bs-toggle="collapse" data-bs-target="#dydMsgSect1"
                aria-expanded="<?= $dyd_thread_is_admin ? 'true' : 'false' ?>">
          <i class="bi bi-chevron-down" style="font-size:.65rem;transition:transform .2s" aria-hidden="true"></i>
          <i class="bi bi-building me-1" aria-hidden="true"></i>Kierownik instytucji
          <?php if ($dyd_admin_unseen > 0): ?>
          <span class="badge bg-danger ms-1" style="font-size:.65rem"><?= (int)$dyd_admin_unseen ?></span>
          <?php endif; ?>
        </button>
      </div>
      <div class="collapse <?= $dyd_thread_is_admin ? 'show' : '' ?>" id="dydMsgSect1">
        <a href="index.php?course=<?= $cur_course ?>&tab=wiadomosci&thread=admin"
           class="list-group-item list-group-item-action py-2 px-3 border-0 <?= $dyd_thread_is_admin ? 'active' : '' ?>">
          <div class="d-flex justify-content-between align-items-center">
            <span class="small fw-semibold">Kierownictwo SZO</span>
          </div>
        </a>
      </div>

      <!-- Sekcja 2: Kursanci -->
      <?php $_kurs_unread = array_sum(array_column($dyd_msg_threads, 'unread')); ?>
      <div class="px-2 py-1 d-flex align-items-center gap-1 bg-body-tertiary border-top border-bottom">
        <button class="btn btn-link btn-sm p-0 text-body fw-semibold d-flex align-items-center gap-1 flex-grow-1"
                style="font-size:.78rem;text-decoration:none"
                data-bs-toggle="collapse" data-bs-target="#dydMsgSect2"
                aria-expanded="<?= (!$dyd_thread_is_admin || !empty($dyd_msg_threads)) ? 'true' : 'false' ?>">
          <i class="bi bi-chevron-down" style="font-size:.65rem;transition:transform .2s" aria-hidden="true"></i>
          <i class="bi bi-people me-1" aria-hidden="true"></i>Kursanci
          <?php if ($_kurs_unread > 0): ?>
          <span class="badge bg-danger ms-1" style="font-size:.65rem"><?= (int)$_kurs_unread ?></span>
          <?php endif; ?>
        </button>
        <button type="button" class="btn btn-sm btn-primary py-0 px-2" style="font-size:.75rem"
                data-bs-toggle="modal" data-bs-target="#dydMsgNew"
                <?= empty($dyd_msg_accounts) ? 'disabled title="Brak kursantów w Twoich kursach"' : '' ?>>
          <i class="bi bi-pencil-square"></i><span class="visually-hidden">Nowa wiadomość</span>
        </button>
      </div>
      <div class="collapse <?= !$dyd_thread_is_admin ? 'show' : '' ?>" id="dydMsgSect2">
        <?php if (empty($dyd_msg_threads)): ?>
        <div class="text-muted small py-3 text-center px-2">
          <i class="bi bi-envelope opacity-50 d-block mb-1" style="font-size:1.5rem"></i>Brak wiadomości od kursantów
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
      <span class="fw-semibold"><i class="bi bi-building text-primary me-1"></i>Kierownictwo</span>
      <span class="text-muted small">Administratorzy SZO</span>
    </div>
    <?php if (empty($dyd_admin_thread)): ?>
    <p class="text-muted small text-center mt-3">Brak wiadomości. Możesz napisać do kierownictwa poniżej.</p>
    <?php else: ?>
    <ol class="list-unstyled d-flex flex-column gap-2 mb-3" aria-label="Wiadomości do kierownictwa">
      <?php foreach ($dyd_admin_thread as $am):
        $amFromMe = true;
        $amTs = $am['created_at'] ? date('d.m.Y H:i', strtotime($am['created_at'])) : '';
        $amRepliedTs = $am['replied_at'] ? date('d.m.Y H:i', strtotime($am['replied_at'])) : '';
      ?>
      <li>
        <article>
          <header class="d-flex align-items-baseline gap-2 mb-1" style="font-size:.78rem">
            <span class="fw-semibold"><?= h($am['user_name']) ?></span>
            <span class="text-muted">prowadzący</span>
            <time class="text-muted ms-auto" datetime="<?= h($am['created_at'] ?? '') ?>"><?= h($amTs) ?></time>
          </header>
          <div class="border rounded-2 p-2 border-primary border-opacity-25 bg-primary bg-opacity-10" style="font-size:.875rem;white-space:pre-wrap"><?= h($am['body']) ?></div>
          <?php if ($am['subject']): ?>
          <div class="text-muted" style="font-size:.75rem;margin-top:.25rem">Temat: <?= h($am['subject']) ?></div>
          <?php endif; ?>
          <?php if ($am['reply_body']): ?>
          <div class="mt-2 border rounded-2 p-2 border-success border-opacity-25 bg-success bg-opacity-10" style="font-size:.875rem;white-space:pre-wrap">
            <div class="d-flex align-items-baseline gap-2 mb-1" style="font-size:.78rem">
              <span class="fw-semibold"><?= h($am['reply_by'] ?: 'Kierownictwo') ?></span>
              <span class="text-muted">kierownictwo</span>
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
    <!-- Nowa wiadomość do kierownictwa -->
    <form method="post" class="mt-2">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="dyd_msg_admin_send">
      <input type="hidden" name="course_id" value="<?= $cur_course ?>">
      <div class="mb-2">
        <input type="text" class="form-control form-control-sm" name="subject" placeholder="Temat (opcjonalny)">
      </div>
      <div class="d-flex gap-2">
        <textarea class="form-control form-control-sm" name="body" rows="3"
                  placeholder="Napisz do kierownictwa…" required style="resize:none"></textarea>
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
      <p class="mt-2 small">Wybierz kursanta lub Kierownictwo z listy, albo napisz nową wiadomość.</p>
      <button type="button" class="btn btn-sm btn-outline-primary mt-1"
              data-bs-toggle="modal" data-bs-target="#dydMsgNew"
              <?= empty($dyd_msg_accounts) ? 'disabled' : '' ?>>
        <i class="bi bi-pencil-square me-1"></i>Nowa wiadomość
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Modal nowej wiadomości -->
<div class="modal fade" id="dydMsgNew" tabindex="-1" aria-labelledby="dydMsgNewLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="dyd_msg_send">
        <input type="hidden" name="course_id" value="<?= $cur_course ?>">
        <div class="modal-header">
          <h5 class="modal-title" id="dydMsgNewLabel"><i class="bi bi-pencil-square me-2"></i>Nowa wiadomość</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Kursant <span class="text-danger">*</span></label>
            <select class="form-select" name="account_id" required>
              <option value="">— Wybierz kursanta —</option>
              <?php foreach ($dyd_msg_accounts as $a): ?>
              <option value="<?= (int)$a['id'] ?>"><?= h($a['name']) ?> — <?= h($a['course_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Temat</label>
            <input type="text" class="form-control" name="subject" placeholder="Temat wiadomości (opcjonalny)">
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

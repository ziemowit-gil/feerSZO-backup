<?php
/**
 * crm/includes/case/aside.php — Prawa szpalta widoku sprawy: status, aktywność, współdzielenie, EZD.
 *
 * Wydzielone z crm/cases/view.php: plik miał 2279 wiersze i zmiana w jednej
 * sekcji wymagała przewijania przez wszystkie pozostałe.
 *
 * Włączany przez include w zakresie crm/cases/view.php — korzysta z jego
 * zmiennych ($case, $id, $can_write, ...). Nie wywołuj samodzielnie.
 */
if (!isset($case)) { http_response_code(400); exit; }
?>
<!-- ══ ASIDE: status, aktywność, współdzielenie, EZD ════════════════════════ -->
<div class="col-lg-4">

  <!-- Status -->
  <?php if ($can_write): ?>
  <div class="cv-panel" id="status"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-flag cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Zmień status</h2>
      </div>
      <form method="post" class="d-flex flex-column gap-2">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="set_status">
        <?php /* Zamknięcie i anulowanie wymagają powodu — inaczej z historii nie
                 wyczytasz, czy sprawa się udała, czy odpadła. */ ?>
        <select name="reason" class="form-select form-select-sm" aria-label="Powód (przy zamknięciu lub anulowaniu)">
          <option value="">— powód (wymagany przy zamknięciu) —</option>
          <?php foreach (crm_case_close_reasons() as $rk => $rl): ?>
          <option value="<?= h($rk) ?>"><?= h($rl) ?></option>
          <?php endforeach; ?>
        </select>
        <?php foreach ($status_cfg as $sv=>$sd): ?>
        <button type="submit" name="status" value="<?= $sv ?>"
                class="btn btn-sm text-start d-flex align-items-center gap-2 <?= $case['status']===$sv?'fw-bold':'' ?>"
                style="background:<?= $sd['bg'] ?>;color:<?= $sd['color'] ?>;border:1.5px solid <?= $case['status']===$sv?$sd['color']:'transparent' ?>">
          <i class="bi <?= $sd['icon'] ?>"></i><?= $sd['label'] ?>
          <?php if ($case['status']===$sv): ?><i class="bi bi-check-lg ms-auto"></i><?php endif; ?>
        </button>
        <?php endforeach; ?>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Aktywność -->
  <div class="cv-panel"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-clock-history cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Aktywność</h2>
      </div>
      <div style="font-size:.84rem;display:flex;flex-direction:column;gap:.4rem">
        <div class="d-flex justify-content-between">
          <span class="text-muted">Utworzona</span>
          <span><?= date('d.m.Y H:i', strtotime($case['created_at'])) ?></span>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted">Zmieniona</span>
          <span><?= date('d.m.Y H:i', strtotime($case['updated_at'])) ?></span>
        </div>
        <?php if ($case['closed_at']): ?>
        <div class="d-flex justify-content-between">
          <span class="cv-muted">Zamknięta</span>
          <span style="color:#B45309;font-weight:600"><?= date('d.m.Y H:i', strtotime($case['closed_at'])) ?></span>
        </div>
        <?php endif; ?>
        <hr class="my-1">
        <div class="d-flex justify-content-between">
          <span class="text-muted"><i class="bi bi-chat me-1"></i>Notatki</span>
          <strong><?= count($notes) ?></strong>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted"><i class="bi bi-paperclip me-1"></i>Pliki</span>
          <strong><?= count($files) ?></strong>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted"><i class="bi bi-envelope me-1"></i>Pisma</span>
          <strong><?= count($letters) ?></strong>
        </div>
      </div>
    </div>
  </div>

  <!-- Prowadzenie sprawy: kto, do kiedy, jaki typ i jak z SLA -->
  <?php
    $case_types_all = crm_case_types();
    $case_users_all = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name");
    $case_type_cur  = crm_case_type((int)($case['type_id'] ?? 0));
    $sla            = crm_case_sla($case);
    $due_ts         = !empty($case['due_date']) ? strtotime((string)$case['due_date']) : 0;
    $due_left       = $due_ts ? (int)floor(($due_ts - time()) / 86400) : null;
    $sla_style = static fn(string $st): string => match ($st) {
        'breach' => 'color:#B91C1C;font-weight:700',
        'soon'   => 'color:#B45309;font-weight:600',
        'met'    => 'color:#2E844A;font-weight:600',
        default  => 'color:#2E844A',
    };
  ?>
  <div class="cv-panel" id="prowadzenie"><div class="cv-panel__body">
    <div class="cv-shead">
      <i class="bi bi-person-workspace cv-shead__icon" aria-hidden="true"></i>
      <h2 class="cv-shead__title">Prowadzenie sprawy</h2>
    </div>

    <?php if ($sla['has']): ?>
    <div class="mb-2" style="font-size:.82rem">
      <?php if ($sla['response']): $r = $sla['response']; ?>
      <div class="d-flex justify-content-between">
        <span class="text-muted">SLA — pierwsza odpowiedź</span>
        <span style="<?= $sla_style($r['state']) ?>">
          <?= $r['met_at']
              ? ($r['state'] === 'met' ? 'dotrzymane' : 'przekroczone') . ' · ' . h(date('d.m.Y H:i', strtotime($r['met_at'])))
              : ($r['left_h'] < 0 ? 'po terminie o ' . abs($r['left_h']) . ' h' : 'zostało ' . $r['left_h'] . ' h') ?>
        </span>
      </div>
      <?php endif; ?>
      <?php if ($sla['close']): $c2 = $sla['close']; ?>
      <div class="d-flex justify-content-between">
        <span class="text-muted">SLA — zamknięcie</span>
        <span style="<?= $sla_style($c2['state']) ?>">
          <?= $c2['met_at']
              ? ($c2['state'] === 'met' ? 'dotrzymane' : 'przekroczone')
              : ($c2['left_h'] < 0 ? 'po terminie' : 'zostało ' . (int)round($c2['left_h'] / 24) . ' dni') ?>
        </span>
      </div>
      <?php endif; ?>
      <div class="text-muted" style="font-size:.72rem">Liczone od wpływu sprawy, wg typu „<?= h($case_type_cur['name'] ?? '') ?>".</div>
    </div>
    <?php endif; ?>

    <?php if ($can_write): ?>
    <form method="post" class="row g-2">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="set_meta">
      <?php if ($case_types_all): ?>
      <div class="col-12">
        <label class="form-label small fw-semibold mb-1" for="mtype">Typ</label>
        <select name="type_id" id="mtype" class="form-select form-select-sm">
          <option value="">— bez typu —</option>
          <?php foreach ($case_types_all as $ct): ?>
          <option value="<?= (int)$ct['id'] ?>" <?= (int)($case['type_id'] ?? 0) === (int)$ct['id'] ? 'selected' : '' ?>>
            <?= h($ct['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-12">
        <label class="form-label small fw-semibold mb-1" for="mowner">Prowadzi</label>
        <select name="owner_id" id="mowner" class="form-select form-select-sm">
          <option value="">— nieprzypisana —</option>
          <?php foreach ($case_users_all as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= (int)($case['owner_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
            <?= h($u['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label small fw-semibold mb-1" for="mdue">Termin</label>
        <input type="date" name="due_date" id="mdue" class="form-control form-control-sm"
               value="<?= h($case['due_date'] ?? '') ?>">
        <?php if ($due_left !== null && !in_array($case['status'], ['closed','cancelled'], true)): ?>
        <div style="font-size:.74rem;<?= $due_left < 0 ? 'color:#B91C1C;font-weight:600' : ($due_left <= 2 ? 'color:#B45309' : 'color:#9CA3AF') ?>">
          <?= $due_left < 0 ? 'Po terminie o ' . abs($due_left) . ' dni' : ($due_left === 0 ? 'Termin dziś' : 'Zostało ' . $due_left . ' dni') ?>
        </div>
        <?php endif; ?>
      </div>
      <div class="col-12">
        <button class="btn btn-crm-outline btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Zapisz prowadzenie</button>
      </div>
    </form>
    <?php else: ?>
    <div style="font-size:.84rem">
      Prowadzi: <strong><?= h(db_one("SELECT name FROM users WHERE id=?", [(int)($case['owner_id'] ?? 0)])['name'] ?? '—') ?></strong><br>
      Termin: <strong><?= $case['due_date'] ? h(date('d.m.Y', strtotime((string)$case['due_date']))) : '—' ?></strong>
    </div>
    <?php endif; ?>
  </div></div>

  <!-- Współdzielenie -->
  <?php if ($can_manage_shares || $shares): ?>
  <div class="cv-panel" id="share"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-people cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Współdzielenie</h2>
        <div class="cv-shead__aside"><span class="cv-count"><?= count($shares) ?></span></div>
      </div>

      <?php if ($shares): ?>
      <ul class="list-group list-group-flush mb-2">
        <?php foreach ($shares as $s):
          $sn = trim(($s['first_name']??'').' '.($s['last_name']??'')) ?: ($s['user_name']??'?');
          $is_edit = (int)$s['can_write'] === 1;
        ?>
        <li class="list-group-item px-0 py-2 d-flex align-items-center gap-2" style="font-size:.84rem">
          <i class="bi bi-person-circle text-secondary"></i>
          <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($sn) ?></span>
          <?php if ($can_manage_shares): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="share_update">
            <input type="hidden" name="share_user_id" value="<?= (int)$s['user_id'] ?>">
            <select name="share_level" class="form-select form-select-sm py-0" style="width:auto;font-size:.74rem"
                    onchange="this.form.submit()" aria-label="Poziom dostępu: <?= h($sn) ?>">
              <option value="read" <?= $is_edit?'':'selected' ?>>odczyt</option>
              <option value="edit" <?= $is_edit?'selected':'' ?>>edycja</option>
            </select>
          </form>
          <form method="post" class="d-inline" onsubmit="return confirm('Cofnąć współdzielenie?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="share_remove">
            <input type="hidden" name="share_user_id" value="<?= (int)$s['user_id'] ?>">
            <button type="submit" class="btn btn-link btn-sm text-danger py-0 px-1" aria-label="Cofnij dla: <?= h($sn) ?>">
              <i class="bi bi-x-lg"></i>
            </button>
          </form>
          <?php else: ?>
          <span class="badge <?= $is_edit?'bg-success-subtle text-success border border-success-subtle':'bg-secondary-subtle text-secondary border' ?>" style="font-size:.68rem">
            <?= $is_edit?'edycja':'odczyt' ?>
          </span>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <div class="text-muted mb-2" style="font-size:.8rem">Sprawa nie jest jeszcze nikomu udostępniona.</div>
      <?php endif; ?>

      <?php if ($can_manage_shares):
        $avail = array_filter($users_list, fn($u) =>
            (int)$u['id'] !== (int)($case['created_by'] ?? 0) && !in_array((int)$u['id'], $shared_uids, true));
      ?>
      <?php if ($avail): ?>
      <form method="post" class="border-top pt-2 mt-1">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="share_add">
        <div class="d-flex gap-1">
          <select name="share_user_id" class="form-select form-select-sm" required style="font-size:.78rem">
            <option value="">— udostępnij osobie —</option>
            <?php foreach ($avail as $u): ?>
            <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select name="share_level" class="form-select form-select-sm" style="width:auto;font-size:.78rem">
            <option value="read">odczyt</option>
            <option value="edit">edycja</option>
          </select>
          <button type="submit" class="btn btn-sm btn-primary px-2" aria-label="Udostępnij">
            <i class="bi bi-plus-lg"></i>
          </button>
        </div>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- EZD -->
  <?php if (module_enabled('ezd_enabled')): ?>
  <div class="cv-panel" id="ezd"><div class="cv-panel__body">
      <div class="cv-shead">
        <i class="bi bi-folder2-open cv-shead__icon" aria-hidden="true"></i>
        <h2 class="cv-shead__title">Powiązanie z EZD</h2>
      </div>

      <?php if ($ezd_sprawa): ?>
      <div style="background:#FFFBF0;border:1px solid #FDE68A;border-radius:8px;padding:.65rem .85rem;font-size:.82rem;margin-bottom:.75rem">
        <div class="fw-semibold">
          <code style="font-size:.75rem;color:#1d4ed8"><?= h($ezd_sprawa['znak_sprawy']) ?></code>
        </div>
        <div style="color:#374151"><?= h($ezd_sprawa['title']) ?></div>
        <div style="font-size:.72rem;color:#5E6470;margin-top:.2rem">
          Status: <?= h($ezd_sprawa['status'] ?? '—') ?>
          · <?= h($ezd_sprawa['teczka_symbol'] ?? '') ?>
        </div>
        <div class="d-flex gap-2 mt-2">
          <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$ezd_sprawa['id'] ?>"
             class="btn btn-sm btn-outline-warning py-0 px-2" style="font-size:.75rem">
            <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz w EZD
          </a>
          <?php if ($can_write): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Odpiąć powiązanie z EZD?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="unlink_ezd">
            <button type="submit" class="btn btn-sm btn-outline-danger py-0 px-2" style="font-size:.75rem">
              <i class="bi bi-x-lg me-1"></i>Odepnij
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php else: ?>
      <div class="text-muted mb-2" style="font-size:.8rem">Nie powiązano z żadną sprawą EZD.</div>
      <?php endif; ?>

      <?php if ($can_write && $ezd_sprawy_list): ?>
      <form method="post" class="<?= $ezd_sprawa ? 'border-top pt-2 mt-1' : '' ?>">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="link_ezd">
        <div class="d-flex gap-1">
          <select name="ezd_sprawa_id" class="form-select form-select-sm" required style="font-size:.78rem">
            <option value="">— wybierz sprawę EZD —</option>
            <?php foreach ($ezd_sprawy_list as $es): ?>
            <option value="<?= (int)$es['id'] ?>"
                    <?= ((int)($case['ezd_sprawa_id'] ?? 0) === (int)$es['id']) ? 'selected' : '' ?>>
              <?= h($es['znak_sprawy']) ?> — <?= h(mb_substr($es['title'],0,40)) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-warning px-2" style="font-size:.75rem">
            <i class="bi bi-link-45deg"></i>
          </button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Linki -->
  <div class="d-flex flex-column gap-2">
    <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= $case['ct_id'] ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-person me-1"></i>Otwórz kartę kontaktu
    </a>
    <a href="add.php?contact_id=<?= $case['ct_id'] ?>" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-plus me-1"></i>Nowa sprawa dla tego kontaktu
    </a>
    <a href="index.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-left me-1"></i>Wszystkie sprawy
    </a>
  </div>

</div><!-- /col-4 -->

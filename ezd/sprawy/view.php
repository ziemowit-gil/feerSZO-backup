<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
if (module_enabled('org_enabled')) {
    require_once dirname(dirname(__DIR__)) . '/includes/org.php';
    require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
    require_once dirname(dirname(__DIR__)) . '/includes/notification_service.php';
}

require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$id     = (int)($_GET['id'] ?? 0);
$sprawa = ezd_sprawa_get($id);
if (!$sprawa) { flash_set('error', 'Sprawa nie istnieje.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }

$PAGE_TITLE = $sprawa['znak_sprawy'] . ' — ' . $sprawa['title'];
$user_id    = (int)current_user()['id'];

$timeline    = ezd_timeline($id);
$dekretacje  = ezd_dekretacje_by_sprawa($id);
$zalaczniki  = ezd_zalaczniki_by($id);
$log_entries = ezd_log_by_sprawa($id, 30);
$users       = db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");

$is_closed = $sprawa['status'] === 'closed';
$can_act   = can_edit() && !$is_closed;

// Obsługa POST (upload + dekretacja + zmiana statusu sprawy)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'upload') {
        if (!can_edit()) { http_response_code(403); exit; }
        $err = ezd_upload('file', $id, $user_id);
        flash_set($err ? 'error' : 'success', $err ?: 'Plik dodany do repozytorium sprawy.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'del_file') {
        if (!can_edit()) { http_response_code(403); exit; }
        ezd_zal_delete((int)($_POST['zal_id'] ?? 0), $user_id);
        flash_set('success', 'Plik usunięty.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'dekretacja' && can_edit()) {
        try {
            $unit_id_d    = (int)($_POST['unit_id'] ?? 0) ?: null;
            $wykonawca_id = (int)($_POST['wykonawca_id'] ?? 0);

            // Jeśli dekretacja na jednostkę i brak wykonawcy — ustaw głowę jednostki
            if ($unit_id_d && !$wykonawca_id && module_enabled('org_enabled')) {
                $head = org_unit_head($unit_id_d);
                if ($head) $wykonawca_id = (int)$head['user_id'];
            }
            if (!$wykonawca_id) throw new \RuntimeException('Wybierz osobę lub jednostkę do dekretacji.');

            $dekr_id = ezd_dekretacja_create([
                'sprawa_id'    => $id,
                'pismo_id'     => null,
                'umowa_id'     => null,
                'wykonawca_id' => $wykonawca_id,
                'unit_id'      => $unit_id_d,
                'dyspozycja'   => $_POST['dyspozycja'] ?? 'do_zalat',
                'tresc'        => trim($_POST['tresc'] ?? ''),
                'deadline'     => $_POST['deadline'] ?: null,
            ], $user_id);

            // Wyślij powiadomienie e-mail
            if (module_enabled('org_enabled') && $dekr_id) {
                try {
                    if ($unit_id_d) {
                        NotificationService::onUnitAssignment($dekr_id, $unit_id_d);
                    } else {
                        NotificationService::onDekretacja($dekr_id);
                    }
                } catch (\Throwable $e) { /* powiadomienie nie blokuje akcji */ }
            }

            flash_set('success', 'Dekretacja zapisana.');
        } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#dekretacje'); exit;
    }

    if ($action === 'dekr_done') {
        ezd_dekretacja_complete((int)($_POST['dekr_id'] ?? 0), $user_id);
        flash_set('success', 'Zadanie oznaczone jako wykonane.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#dekretacje'); exit;
    }

    header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id); exit;
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<style>
/* ── Bento karty ─────────────────────────── */
.bc{background:#fff;border:1.5px solid #e8edf3;border-radius:12px;overflow:hidden;margin-bottom:1rem;}
.bc-h{padding:.55rem 1rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:#94a3b8;border-bottom:1px solid #f1f5f9;background:#fafbfc;display:flex;align-items:center;gap:.4rem;}
.bc-b{padding:.8rem 1rem;}

/* ── Timeline ────────────────────────────── */
.tl-wrap{position:relative;padding-left:2rem;}
.tl-wrap::before{content:'';position:absolute;left:.55rem;top:0;bottom:0;width:2px;background:#e2e8f0;border-radius:2px;}
.tl-item{position:relative;margin-bottom:1.1rem;}
.tl-dot{position:absolute;left:-1.45rem;top:.3rem;width:14px;height:14px;border-radius:50%;border:2.5px solid #fff;box-shadow:0 0 0 2px #2563eb;background:#2563eb;}
.tl-dot.pismo-in  {background:#0891b2;box-shadow:0 0 0 2px #0891b2;}
.tl-dot.pismo-out {background:#2563eb;box-shadow:0 0 0 2px #2563eb;}
.tl-dot.pismo-int {background:#94a3b8;box-shadow:0 0 0 2px #94a3b8;}
.tl-dot.umowa-card{background:#d97706;box-shadow:0 0 0 2px #d97706;}

.tl-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:.75rem 1rem;transition:border-color .15s,box-shadow .15s;text-decoration:none;color:inherit;display:block;}
.tl-card:hover{border-color:#93c5fd;box-shadow:0 2px 10px rgba(37,99,235,.1);}
.tl-card.umowa-card{border-left:3px solid #d97706;}
.tl-card.pismo-in  {border-left:3px solid #0891b2;}
.tl-card.pismo-out {border-left:3px solid #2563eb;}
.tl-syg{font-family:monospace;font-size:.73rem;color:#64748b;font-weight:600;}
.tl-title{font-weight:700;font-size:.88rem;color:#1e293b;margin:.1rem 0;}
.tl-meta{font-size:.72rem;color:#94a3b8;display:flex;flex-wrap:wrap;gap:.3rem .75rem;}

/* ── Dekretacja ──────────────────────────── */
.dekr-row{display:flex;align-items:flex-start;gap:.65rem;padding:.45rem 0;border-bottom:1px solid #f8fafc;font-size:.78rem;}
.dekr-row:last-child{border-bottom:none;}
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">Kancelaria</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $sprawa['teczka_id'] ?>"><?= h($sprawa['teczka_symbol']) ?></a></li>
    <li class="breadcrumb-item active"><?= h($sprawa['znak_sprawy']) ?></li>
  </ol>
</nav>

<?= flash_html() ?>

<?php if ($is_closed): ?>
<div class="alert alert-secondary d-flex align-items-center gap-2 py-2 mb-3" style="font-size:.84rem">
  <i class="bi bi-lock-fill"></i>
  <span>Sprawa jest <strong>zamknięta</strong>. Dodawanie dokumentów i edycja są zablokowane.
  <?php if(is_admin()): ?><a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>">Przywróć</a><?php endif; ?></span>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- ══ LEWA: Timeline ══════════════════════════════════════════════════════ -->
  <div class="col-lg-8">

    <!-- Nagłówek sprawy -->
    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3 flex-wrap">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
              <code class="bg-light px-2 py-0 rounded fw-bold" style="font-size:.82rem;color:#1d4ed8"><?= h($sprawa['znak_sprawy']) ?></code>
              <?= ezd_status_badge_sprawa($sprawa['status']) ?>
              <?= ezd_priority_badge($sprawa['priority']) ?>
              <?php if($sprawa['deadline']): ?>
              <span class="<?= $sprawa['deadline']<date('Y-m-d')&&!$is_closed?'text-danger fw-bold':'text-muted' ?>" style="font-size:.76rem">
                <i class="bi bi-calendar-event me-1"></i><?= date_pl($sprawa['deadline']) ?>
              </span>
              <?php endif; ?>
            </div>
            <h3 class="fw-bold mb-1" style="font-size:1.25rem;color:#0f172a"><?= h($sprawa['title']) ?></h3>
            <?php if($sprawa['description']): ?>
            <div class="text-muted" style="font-size:.83rem;line-height:1.5"><?= nl2br(h($sprawa['description'])) ?></div>
            <?php endif; ?>
          </div>
          <?php if(can_edit()): ?>
          <div class="d-flex gap-2 flex-wrap flex-shrink-0">
            <?php if($can_act): ?>
            <a href="<?= APP_URL ?>/ezd/pisma/add.php?sprawa_id=<?= $id ?>" class="btn btn-sm btn-outline-info">
              <i class="bi bi-envelope-plus me-1"></i>Pismo
            </a>
            <a href="<?= APP_URL ?>/ezd/umowy/add.php?sprawa_id=<?= $id ?>" class="btn btn-sm btn-outline-warning">
              <i class="bi bi-file-earmark-plus me-1"></i>Umowa
            </a>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-pencil"></i>
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Timeline dokumentów -->
    <h6 class="text-muted text-uppercase fw-bold mb-3" style="font-size:.7rem;letter-spacing:.1em">
      <i class="bi bi-activity me-1"></i>Dokumenty sprawy (<?= count($timeline) ?>)
    </h6>

    <?php if ($timeline): ?>
    <div class="tl-wrap">
      <?php foreach ($timeline as $item):
        $is_pismo = $item['_typ'] === 'pismo';
        $dot_class = $is_pismo ? 'pismo-' . match($item['sub_type']) { 'przychodzace'=>'in','wychodzace'=>'out',default=>'int' } : 'umowa-card';
        $card_class = $is_pismo ? 'pismo-' . match($item['sub_type']) { 'przychodzace'=>'in','wychodzace'=>'out',default=>'int' } : 'umowa-card';
        $view_url   = APP_URL . '/ezd/' . ($is_pismo ? 'pisma' : 'umowy') . '/view.php?id=' . $item['id'];
        $edit_url   = APP_URL . '/ezd/' . ($is_pismo ? 'pisma' : 'umowy') . '/edit.php?id=' . $item['id'];
      ?>
      <div class="tl-item">
        <div class="tl-dot <?= $dot_class ?>"></div>
        <a href="<?= h($view_url) ?>" class="tl-card <?= $card_class ?>">
          <div class="d-flex align-items-start gap-2">
            <div class="flex-grow-1">
              <div class="tl-syg d-flex align-items-center gap-2">
                <?php if($is_pismo): ?>
                <i class="bi <?= EZD_KIERUNKI[$item['sub_type']]['icon'] ?? 'bi-envelope' ?> text-<?= EZD_KIERUNKI[$item['sub_type']]['class'] ?? 'secondary' ?>"></i>
                <span><?= h(EZD_KIERUNKI[$item['sub_type']]['label'] ?? $item['sub_type']) ?></span>
                <?php else: ?>
                <i class="bi bi-file-earmark-text text-warning"></i>
                <span><?= h(EZD_UMOWA_TYPY[$item['sub_type']] ?? $item['sub_type']) ?></span>
                <?php endif; ?>
                <code class="ms-1" style="font-size:.68rem;color:#94a3b8"><?= h($item['sygnatura']) ?></code>
              </div>
              <div class="tl-title"><?= h($item['title']) ?></div>
              <div class="tl-meta">
                <span><i class="bi bi-clock me-1"></i><?= date('d.m.Y H:i', strtotime($item['created_at'])) ?></span>
                <?php
                $status_map_p = ['nowe'=>['Nowe','secondary'],'w_obiegu'=>['W obiegu','primary'],'zakonczone'=>['Zakończone','success']];
                $status_map_u = ['projekt'=>['Projekt','secondary'],'aktywna'=>['Aktywna','success'],'zakonczona'=>['Zakończona','primary'],'anulowana'=>['Anulowana','danger']];
                $sm = $is_pismo ? ($status_map_p[$item['status']] ?? [$item['status'],'secondary']) : ($status_map_u[$item['status']] ?? [$item['status'],'secondary']);
                ?>
                <span class="badge bg-<?= $sm[1] ?> bg-opacity-15 text-<?= $sm[1] ?> border border-<?= $sm[1] ?>" style="font-size:.63rem"><?= $sm[0] ?></span>
              </div>
            </div>
            <?php if($can_act): ?>
            <a href="<?= h($edit_url) ?>" class="btn btn-xs btn-outline-secondary btn-sm flex-shrink-0"
               onclick="event.preventDefault();event.stopPropagation();window.location=this.href" title="Edytuj">
              <i class="bi bi-pencil"></i>
            </a>
            <?php endif; ?>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="text-center py-4 text-muted" style="font-size:.85rem">
      <i class="bi bi-inbox" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
      Brak dokumentów w tej sprawie.
      <?php if($can_act): ?>
      <div class="d-flex justify-content-center gap-2 mt-2">
        <a href="<?= APP_URL ?>/ezd/pisma/add.php?sprawa_id=<?= $id ?>" class="btn btn-sm btn-outline-info"><i class="bi bi-envelope-plus me-1"></i>Dodaj pismo</a>
        <a href="<?= APP_URL ?>/ezd/umowy/add.php?sprawa_id=<?= $id ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-file-earmark-plus me-1"></i>Dodaj umowę</a>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Repozytorium plików sprawy -->
    <div class="mt-4" id="files">
      <h6 class="text-muted text-uppercase fw-bold mb-3" style="font-size:.7rem;letter-spacing:.1em">
        <i class="bi bi-folder2 me-1"></i>Repozytorium plików sprawy (<?= count($zalaczniki) ?>)
      </h6>
      <div class="card shadow-sm">
        <div class="card-body p-0">
          <?php foreach ($zalaczniki as $z): ?>
          <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom" style="font-size:.8rem">
            <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5 flex-shrink-0"></i>
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" class="text-decoration-none fw-semibold text-truncate d-block"><?= h($z['original_name']) ?></a>
              <div class="text-muted" style="font-size:.7rem"><?= ezd_filesize($z['file_size']) ?> · <?= h($z['uploader'] ?? '—') ?> · <?= date('d.m.Y H:i', strtotime($z['uploaded_at'])) ?>
                <?php if($z['wersja']>1): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">v<?= $z['wersja'] ?></span><?php endif; ?>
              </div>
            </div>
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>&dl=1" class="btn btn-xs btn-outline-secondary btn-sm"><i class="bi bi-download"></i></a>
            <?php if(can_edit()): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć plik?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="del_file">
              <input type="hidden" name="zal_id" value="<?= $z['id'] ?>">
              <button class="btn btn-xs btn-outline-danger btn-sm"><i class="bi bi-trash3"></i></button>
            </form>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php if(!$zalaczniki): ?>
          <div class="text-center text-muted py-3" style="font-size:.8rem">Brak plików w repozytorium sprawy</div>
          <?php endif; ?>
          <?php if(can_edit()): ?>
          <form method="post" enctype="multipart/form-data" class="p-3 border-top">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="upload">
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <input type="file" name="file" class="form-control form-control-sm" style="max-width:300px"
                     accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.pptx,.png,.jpg,.jpeg,.zip,.txt,.csv,.eml,.msg">
              <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-upload me-1"></i>Dodaj do sprawy</button>
              <small class="text-muted">Maks. 25 MB</small>
            </div>
          </form>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Audit log -->
    <div class="mt-4">
      <h6 class="text-muted text-uppercase fw-bold mb-2" style="font-size:.7rem;letter-spacing:.1em">
        <i class="bi bi-shield-check me-1"></i>Historia operacji
      </h6>
      <div class="card shadow-sm">
        <div class="card-body p-0" style="max-height:280px;overflow-y:auto">
          <table class="table table-sm mb-0" style="font-size:.76rem">
            <thead class="table-light sticky-top"><tr><th>Czas</th><th>Użytkownik</th><th>Operacja</th><th>Szczegóły</th></tr></thead>
            <tbody>
            <?php foreach ($log_entries as $l): ?>
            <tr>
              <td class="text-nowrap text-muted"><?= date('d.m.Y H:i', strtotime($l['created_at'])) ?></td>
              <td><?= h($l['user_name'] ?? '—') ?></td>
              <td><code style="font-size:.68rem"><?= h($l['action']) ?></code></td>
              <td><?= h(mb_substr($l['details'],0,80)) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$log_entries): ?><tr><td colspan="4" class="text-center text-muted py-2">Brak wpisów</td></tr><?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- /col-lg-8 -->

  <!-- ══ PRAWA: Bento ════════════════════════════════════════════════════════ -->
  <div class="col-lg-4">

    <!-- Metadane sprawy -->
    <div class="bc">
      <div class="bc-h"><i class="bi bi-info-circle"></i>Informacje o sprawie</div>
      <div class="bc-b">
        <dl class="row mb-0" style="font-size:.82rem;row-gap:.3rem">
          <dt class="col-5 text-muted fw-normal">Teczka</dt>
          <dd class="col-7 mb-0"><a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $sprawa['teczka_id'] ?>" class="text-decoration-none fw-semibold"><?= h($sprawa['teczka_symbol']) ?></a></dd>
          <dt class="col-5 text-muted fw-normal">Właściciel</dt>
          <dd class="col-7 mb-0"><?= $sprawa['owner_name'] ? h($sprawa['owner_name']) : '<span class="text-muted">—</span>' ?></dd>
          <dt class="col-5 text-muted fw-normal">Priorytet</dt>
          <dd class="col-7 mb-0"><?= ezd_priority_badge($sprawa['priority']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Status</dt>
          <dd class="col-7 mb-0"><?= ezd_status_badge_sprawa($sprawa['status']) ?></dd>
          <dt class="col-5 text-muted fw-normal">Termin</dt>
          <dd class="col-7 mb-0 <?= $sprawa['deadline']&&$sprawa['deadline']<date('Y-m-d')&&!$is_closed?'text-danger fw-bold':'' ?>"><?= $sprawa['deadline'] ? date_pl($sprawa['deadline']) : '—' ?></dd>
          <dt class="col-5 text-muted fw-normal">Otwarto</dt>
          <dd class="col-7 mb-0"><?= date_pl($sprawa['created_at']) ?></dd>
          <?php if($sprawa['closed_at']): ?>
          <dt class="col-5 text-muted fw-normal">Zamknięto</dt>
          <dd class="col-7 mb-0"><?= date_pl($sprawa['closed_at']) ?></dd>
          <?php endif; ?>
          <dt class="col-5 text-muted fw-normal">Pisma</dt>
          <dd class="col-7 mb-0"><?= count(array_filter($timeline, fn($t)=>$t['_typ']==='pismo')) ?></dd>
          <dt class="col-5 text-muted fw-normal">Umowy</dt>
          <dd class="col-7 mb-0"><?= count(array_filter($timeline, fn($t)=>$t['_typ']==='umowa')) ?></dd>
        </dl>
      </div>
    </div>

    <!-- Szybkie akcje -->
    <?php if(can_edit()): ?>
    <div class="bc">
      <div class="bc-h"><i class="bi bi-lightning-charge"></i>Akcje</div>
      <div class="bc-b d-flex flex-column gap-2">
        <?php if($can_act): ?>
        <a href="<?= APP_URL ?>/ezd/pisma/add.php?sprawa_id=<?= $id ?>" class="btn btn-sm btn-outline-info w-100 text-start">
          <i class="bi bi-envelope-arrow-down me-2"></i>Dodaj pismo przychodzące
        </a>
        <a href="<?= APP_URL ?>/ezd/pisma/add.php?sprawa_id=<?= $id ?>&kierunek=wychodzace" class="btn btn-sm btn-outline-primary w-100 text-start">
          <i class="bi bi-envelope-arrow-up me-2"></i>Dodaj pismo wychodzące
        </a>
        <a href="<?= APP_URL ?>/ezd/umowy/add.php?sprawa_id=<?= $id ?>" class="btn btn-sm btn-outline-warning w-100 text-start">
          <i class="bi bi-file-earmark-plus me-2"></i>Dodaj umowę / aneks
        </a>
        <?php endif; ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary w-100 text-start">
          <i class="bi bi-pencil me-2"></i>Edytuj metadane sprawy
        </a>
      </div>
    </div>
    <?php endif; ?>

    <!-- Dekretacja -->
    <div class="bc" id="dekretacje">
      <div class="bc-h">
        <i class="bi bi-person-lines-fill"></i>Dekretacja
        <?php $pend_d = count(array_filter($dekretacje,fn($d)=>$d['status']==='oczekuje')); ?>
        <?php if($pend_d): ?><span class="ms-auto badge bg-warning text-dark" style="font-size:.63rem"><?= $pend_d ?> oczekuje</span><?php endif; ?>
      </div>
      <div class="bc-b">
        <?php foreach($dekretacje as $d): ?>
        <div class="dekr-row">
          <div class="flex-grow-1">
            <div class="d-flex align-items-center gap-1 flex-wrap">
              <span class="badge bg-<?= $d['status']==='oczekuje'?'warning text-dark':'success' ?>" style="font-size:.62rem"><?= h(EZD_DYSPOZYCJE[$d['dyspozycja']] ?? $d['dyspozycja']) ?></span>
              <span class="fw-semibold"><?= h($d['wykonawca_name']??'—') ?></span>
            </div>
            <?php if($d['tresc']): ?><div class="text-muted" style="font-size:.72rem"><?= h(mb_substr($d['tresc'],0,60)) ?></div><?php endif; ?>
            <div class="text-muted" style="font-size:.7rem">
              od: <?= h($d['zlecajacy_name']??'—') ?>
              <?php if($d['deadline']): ?> · <span class="<?= $d['deadline']<date('Y-m-d')&&$d['status']==='oczekuje'?'text-danger fw-bold':'' ?>"><?= date_pl($d['deadline']) ?></span><?php endif; ?>
            </div>
          </div>
          <?php if($d['status']==='oczekuje' && can_edit()): ?>
          <form method="post" class="d-inline flex-shrink-0">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="dekr_done">
            <input type="hidden" name="dekr_id" value="<?= $d['id'] ?>">
            <button class="btn btn-xs btn-outline-success btn-sm" title="Wykonane"><i class="bi bi-check-lg"></i></button>
          </form>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if(!$dekretacje): ?><div class="text-muted text-center" style="font-size:.78rem">Brak dekretacji</div><?php endif; ?>

        <!-- Formularz nowej dekretacji -->
        <?php if($can_act): ?>
        <?php
          $org_units_list = [];
          if (module_enabled('org_enabled') && function_exists('org_units_all')) {
              $org_units_list = org_units_all('active');
          }
        ?>
        <div class="mt-3 pt-3 border-top">
          <div class="fw-semibold mb-2" style="font-size:.78rem">Nowa dekretacja:</div>
          <form method="post" class="d-flex flex-column gap-2" id="dekr-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="dekretacja">
            <?php if ($org_units_list): ?>
            <select name="unit_id" class="form-select form-select-sm" id="dekr-unit" onchange="dekrUnitChange(this)">
              <option value="">— lub wybierz jednostkę —</option>
              <?php foreach($org_units_list as $ou): ?>
              <option value="<?= $ou['id'] ?>"><?= h($ou['name']) ?> <small>(<?= h($ou['code']) ?>)</small></option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <select name="wykonawca_id" class="form-select form-select-sm" id="dekr-user">
              <option value="">— wybierz osobę —</option>
              <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option><?php endforeach; ?>
            </select>
            <select name="dyspozycja" class="form-select form-select-sm">
              <?php foreach(EZD_DYSPOZYCJE as $k=>$v): ?><option value="<?= $k ?>"><?= h($v) ?></option><?php endforeach; ?>
            </select>
            <input type="text" name="tresc" class="form-control form-control-sm" placeholder="Treść dyspozycji (opcjonalnie)">
            <input type="date" name="deadline" class="form-control form-control-sm" min="<?= date('Y-m-d') ?>">
            <button type="submit" class="btn btn-sm btn-warning"><i class="bi bi-send me-1"></i>Dekretuj</button>
          </form>
        </div>
        <?php if ($org_units_list): ?>
        <script>
        // Wczytaj kierownika jednostki przy wyborze z listy
        var dekrHeads = <?= json_encode(array_reduce($org_units_list, function($carry, $u) {
            $head = function_exists('org_unit_head') ? org_unit_head((int)$u['id']) : null;
            if ($head) $carry[(string)$u['id']] = (int)$head['user_id'];
            return $carry;
        }, [])) ?>;
        function dekrUnitChange(sel) {
            var uid = sel.value;
            var userSel = document.getElementById('dekr-user');
            if (uid && dekrHeads[uid]) {
                userSel.value = dekrHeads[uid];
            } else if (!uid) {
                // Nie resetuj — użytkownik może wybrać ręcznie
            }
        }
        </script>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /col-lg-4 -->
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

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
$podsprawy   = ezd_podsprawy_by_parent($id);
$dokumenty   = ezd_dokumenty_by_sprawa($id);
$notatki     = ezd_notatki_by_sprawa($id);
$grupy       = ezd_grupy_by_sprawa($id);

// Pliki repozytorium pogrupowane: grupa_id => [pliki], 0 => bez grupy
$grupy_map = [];
foreach ($grupy as $g) $grupy_map[(int)$g['id']] = [];
$bez_grupy = [];
foreach ($zalaczniki as $z) {
    $gid = (int)($z['grupa_id'] ?? 0);
    if ($gid && isset($grupy_map[$gid])) $grupy_map[$gid][] = $z;
    else $bez_grupy[] = $z;
}

$is_closed = $sprawa['status'] === 'closed';
$can_act   = can_edit() && !$is_closed;

// Obsługa POST (upload + dekretacja + zmiana statusu sprawy)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'upload') {
        if (!can_edit()) { http_response_code(403); exit; }
        $grupa_id = (int)($_POST['grupa_id'] ?? 0) ?: null;
        $err = ezd_upload('file', $id, $user_id, null, null, null, null, $grupa_id);
        flash_set($err ? 'error' : 'success', $err ?: 'Plik dodany do repozytorium sprawy.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'del_file') {
        if (!can_edit()) { http_response_code(403); exit; }
        ezd_zal_delete((int)($_POST['zal_id'] ?? 0), $user_id);
        flash_set('success', 'Plik usunięty.');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }

    if ($action === 'grupa_add' && can_edit()) {
        try { ezd_grupa_create($id, $_POST['nazwa'] ?? '', $user_id); flash_set('success','Grupa plików utworzona.'); }
        catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }
    if ($action === 'grupa_del' && can_edit()) {
        ezd_grupa_delete((int)($_POST['grupa_id'] ?? 0), $user_id);
        flash_set('success', 'Grupę usunięto (pliki pozostały bez grupy).');
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#files'); exit;
    }
    if ($action === 'zal_move' && can_edit()) {
        ezd_zal_set_grupa((int)($_POST['zal_id'] ?? 0), (int)($_POST['grupa_id'] ?? 0) ?: null, $user_id);
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

    if ($action === 'set_etap' && $can_act) {
        try {
            ezd_sprawa_set_etap($id, $_POST['etap'] ?? '', $user_id);
            flash_set('success', 'Zmieniono etap obiegu sprawy.');
        } catch (\Throwable $e) { flash_set('error', $e->getMessage()); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#workflow'); exit;
    }

    // ── Notatki ──────────────────────────────────────────────────────────────
    if ($action === 'note_add' && $can_act) {
        $tresc = trim($_POST['tresc'] ?? '');
        if ($tresc !== '') { ezd_notatka_create($id, $tresc, $user_id); flash_set('success', 'Notatka dodana.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }
    if ($action === 'note_edit' && can_edit()) {
        $n = ezd_notatka_get((int)($_POST['note_id'] ?? 0));
        if ($n && ($n['created_by'] == $user_id || is_admin())) {
            ezd_notatka_update((int)$n['id'], trim($_POST['tresc'] ?? ''), $user_id);
            flash_set('success', 'Notatka zaktualizowana.');
        } else { flash_set('error', 'Brak uprawnień do edycji notatki.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }
    if ($action === 'note_del' && can_edit()) {
        $n = ezd_notatka_get((int)($_POST['note_id'] ?? 0));
        if ($n && ($n['created_by'] == $user_id || is_admin())) {
            ezd_notatka_delete((int)$n['id'], $user_id);
            flash_set('success', 'Notatka usunięta.');
        } else { flash_set('error', 'Brak uprawnień do usunięcia notatki.'); }
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
    }
    if ($action === 'note_pin' && can_edit()) {
        ezd_notatka_toggle_pin((int)($_POST['note_id'] ?? 0), $user_id);
        header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $id . '#notatki'); exit;
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

/* ── Stepper workflow BPM ────────────────── */
.ezd-stepper{display:flex;align-items:flex-start;gap:.25rem;overflow-x:auto;padding:.25rem 0;}
.ezd-step{flex:1 1 0;min-width:64px;text-align:center;position:relative;}
.ezd-step::before{content:'';position:absolute;top:14px;left:-50%;width:100%;height:2px;background:#e2e8f0;z-index:0;}
.ezd-step:first-child::before{display:none;}
.ezd-step-dot{position:relative;z-index:1;width:30px;height:30px;border-radius:50%;margin:0 auto .35rem;display:flex;align-items:center;justify-content:center;background:#e2e8f0;color:#94a3b8;font-size:.85rem;border:2px solid #fff;box-shadow:0 0 0 1px #e2e8f0;}
.ezd-step-lbl{font-size:.66rem;color:#94a3b8;line-height:1.15;}
.ezd-step-done .ezd-step-dot{background:#22c55e;color:#fff;box-shadow:0 0 0 1px #22c55e;}
.ezd-step-done::before{background:#22c55e;}
.ezd-step-done .ezd-step-lbl{color:#16a34a;}
.ezd-step-current .ezd-step-dot{background:#2563eb;color:#fff;box-shadow:0 0 0 3px #bfdbfe;}
.ezd-step-current .ezd-step-lbl{color:#1d4ed8;font-weight:700;}
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
              <?php if(!empty($sprawa['ciagla'])): ?>
              <span class="badge bg-info bg-opacity-15 text-info border border-info" style="font-size:.7rem"><i class="bi bi-infinity me-1"></i>Sprawa ciągła</span>
              <?php elseif($sprawa['deadline']): ?>
              <span class="<?= $sprawa['deadline']<date('Y-m-d')&&!$is_closed?'text-danger fw-bold':'text-muted' ?>" style="font-size:.76rem">
                <i class="bi bi-calendar-event me-1"></i><?= date_pl($sprawa['deadline']) ?>
              </span>
              <?php endif; ?>
            </div>
            <h3 class="fw-bold mb-1" style="font-size:1.25rem;color:#0f172a"><?= h($sprawa['title']) ?></h3>
            <?php if($sprawa['parent_id']): ?>
            <div class="mb-1" style="font-size:.78rem">
              <span class="badge bg-info bg-opacity-15 text-info border border-info"><i class="bi bi-diagram-3 me-1"></i>Podsprawa</span>
              <span class="text-muted ms-1">sprawy <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa['parent_id'] ?>" class="font-monospace text-decoration-none"><?= h($sprawa['parent_znak']) ?></a></span>
            </div>
            <?php endif; ?>
            <?php if($sprawa['description']): ?>
            <div class="text-muted" style="font-size:.83rem;line-height:1.5"><?= nl2br(h($sprawa['description'])) ?></div>
            <?php endif; ?>
          </div>
          <div class="d-flex gap-2 flex-wrap flex-shrink-0">
            <a href="<?= APP_URL ?>/ezd/sprawy/metryka.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary" title="Metryka sprawy (KPA)">
              <i class="bi bi-clipboard-check me-1"></i>Metryka
            </a>
            <?php if($can_act): ?>
            <a href="<?= APP_URL ?>/ezd/pisma/add.php?sprawa_id=<?= $id ?>" class="btn btn-sm btn-outline-info">
              <i class="bi bi-envelope-plus me-1"></i>Pismo
            </a>
            <a href="<?= APP_URL ?>/ezd/umowy/add.php?sprawa_id=<?= $id ?>" class="btn btn-sm btn-outline-warning">
              <i class="bi bi-file-earmark-plus me-1"></i>Umowa
            </a>
            <?php endif; ?>
            <?php if(can_edit()): ?>
            <a href="<?= APP_URL ?>/ezd/sprawy/edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-pencil"></i>
            </a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Workflow BPM — etapy obiegu -->
    <?php
      $cur_etap = $sprawa['etap'] ?? 'wszczeta';
      $cur_ord  = ezd_etap_meta($cur_etap)['order'];
      $etap_keys = array_keys(EZD_ETAPY);
      $cur_idx  = array_search($cur_etap, $etap_keys, true);
      $next_etap = ($cur_idx !== false && isset($etap_keys[$cur_idx+1])) ? $etap_keys[$cur_idx+1] : null;
    ?>
    <div class="bc mb-4" id="workflow">
      <div class="bc-h"><i class="bi bi-diagram-2"></i>Obieg sprawy (workflow)</div>
      <div class="bc-b">
        <div class="ezd-stepper">
          <?php foreach (EZD_ETAPY as $ek => $em):
            $state = $em['order'] < $cur_ord ? 'done' : ($ek === $cur_etap ? 'current' : 'todo'); ?>
          <div class="ezd-step ezd-step-<?= $state ?>" title="<?= h($em['label']) ?>">
            <div class="ezd-step-dot"><i class="bi <?= $state==='done'?'bi-check-lg':$em['icon'] ?>"></i></div>
            <div class="ezd-step-lbl"><?= h($em['label']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if($can_act): ?>
        <div class="d-flex gap-2 flex-wrap align-items-center mt-3 pt-3 border-top">
          <?php if($next_etap): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="set_etap">
            <input type="hidden" name="etap" value="<?= $next_etap ?>">
            <button class="btn btn-sm btn-primary"><i class="bi <?= ezd_etap_meta($next_etap)['icon'] ?> me-1"></i>Dalej: <?= h(ezd_etap_meta($next_etap)['label']) ?> →</button>
          </form>
          <?php endif; ?>
          <form method="post" class="d-inline d-flex gap-1">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="set_etap">
            <select name="etap" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
              <option disabled selected>Przejdź do etapu…</option>
              <?php foreach(EZD_ETAPY as $ek=>$em): ?>
              <option value="<?= $ek ?>" <?= $ek===$cur_etap?'disabled':'' ?>><?= h($em['label']) ?></option>
              <?php endforeach; ?>
            </select>
          </form>
          <span class="text-muted ms-auto" style="font-size:.74rem">Aktualny etap: <?= ezd_etap_badge($cur_etap) ?></span>
        </div>
        <?php else: ?>
        <div class="mt-2 text-end"><?= ezd_etap_badge($cur_etap) ?></div>
        <?php endif; ?>
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

    <!-- Dokumenty wewnętrzne -->
    <div class="mt-4" id="dokumenty">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="text-muted text-uppercase fw-bold mb-0" style="font-size:.7rem;letter-spacing:.1em">
          <i class="bi bi-file-earmark-text me-1"></i>Dokumenty wewnętrzne (<?= count($dokumenty) ?>)
        </h6>
        <?php if($can_act): ?>
        <a href="<?= APP_URL ?>/ezd/dokumenty/add.php?sprawa_id=<?= $id ?>" class="btn btn-xs btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Dodaj dokument</a>
        <?php endif; ?>
      </div>
      <div class="card shadow-sm"><div class="card-body p-0">
        <?php foreach($dokumenty as $d): ?>
        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom" style="font-size:.82rem">
          <i class="bi bi-file-earmark-text text-primary fs-5 flex-shrink-0"></i>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/ezd/dokumenty/view.php?id=<?= $d['id'] ?>" class="text-decoration-none fw-semibold text-truncate d-block"><?= h($d['title']) ?></a>
            <div class="text-muted" style="font-size:.7rem">
              <code style="font-size:.66rem"><?= h($d['sygnatura']) ?></code> ·
              <?= h(EZD_DOK_RODZAJE[$d['rodzaj']] ?? $d['rodzaj']) ?> ·
              <?= h($d['owner_name'] ?? '—') ?>
              <?php if($d['plik_count']): ?> · <i class="bi bi-paperclip"></i><?= (int)$d['plik_count'] ?><?php endif; ?>
            </div>
          </div>
          <?= ezd_dok_status_badge($d['status']) ?>
        </div>
        <?php endforeach; ?>
        <?php if(!$dokumenty): ?>
        <div class="text-center text-muted py-3" style="font-size:.8rem">Brak dokumentów wewnętrznych</div>
        <?php endif; ?>
      </div></div>
    </div>

    <!-- Podsprawy -->
    <div class="mt-4" id="podsprawy">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="text-muted text-uppercase fw-bold mb-0" style="font-size:.7rem;letter-spacing:.1em">
          <i class="bi bi-diagram-3 me-1"></i>Podsprawy (<?= count($podsprawy) ?>)
        </h6>
        <?php if($can_act && !$sprawa['parent_id']): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/add.php?parent_id=<?= $id ?>" class="btn btn-xs btn-outline-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Dodaj podsprawę</a>
        <?php endif; ?>
      </div>
      <div class="card shadow-sm"><div class="card-body p-0">
        <?php foreach($podsprawy as $ps): ?>
        <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $ps['id'] ?>" class="d-flex align-items-center gap-2 px-3 py-2 border-bottom text-decoration-none text-reset" style="font-size:.82rem">
          <i class="bi bi-folder2 text-info flex-shrink-0"></i>
          <code class="flex-shrink-0" style="font-size:.72rem;color:#1d4ed8"><?= h($ps['znak_sprawy']) ?></code>
          <span class="flex-grow-1 text-truncate fw-semibold"><?= h($ps['title']) ?></span>
          <?= ezd_priority_badge($ps['priority']) ?>
          <?= ezd_status_badge_sprawa($ps['status']) ?>
        </a>
        <?php endforeach; ?>
        <?php if(!$podsprawy): ?>
        <div class="text-center text-muted py-3" style="font-size:.8rem">
          <?= $sprawa['parent_id'] ? 'Ta sprawa jest już podsprawą — zagnieżdżanie ograniczone do jednego poziomu.' : 'Brak podspraw' ?>
        </div>
        <?php endif; ?>
      </div></div>
    </div>

    <!-- Notatki -->
    <div class="mt-4" id="notatki">
      <h6 class="text-muted text-uppercase fw-bold mb-3" style="font-size:.7rem;letter-spacing:.1em">
        <i class="bi bi-sticky me-1"></i>Notatki (<?= count($notatki) ?>)
      </h6>
      <div class="card shadow-sm"><div class="card-body">
        <?php if($can_act): ?>
        <form method="post" class="mb-3">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="note_add">
          <div class="d-flex gap-2 align-items-start">
            <textarea name="tresc" class="form-control form-control-sm" rows="2" placeholder="Dodaj notatkę do sprawy…" required></textarea>
            <button class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-plus-lg me-1"></i>Dodaj</button>
          </div>
        </form>
        <?php endif; ?>
        <?php foreach($notatki as $n): $own = ($n['created_by']==$user_id || is_admin()); ?>
        <div class="border rounded-3 p-2 mb-2 <?= $n['pinned'] ? 'border-warning bg-warning bg-opacity-10' : '' ?>" style="font-size:.83rem">
          <div class="d-flex align-items-center gap-2 mb-1 text-muted" style="font-size:.7rem">
            <?php if($n['pinned']): ?><i class="bi bi-pin-angle-fill text-warning"></i><?php endif; ?>
            <span class="fw-semibold text-dark"><?= h($n['author'] ?? '—') ?></span>
            <span><?= date('d.m.Y H:i', strtotime($n['created_at'])) ?></span>
            <?php if($n['updated_at'] && $n['updated_at'] !== $n['created_at']): ?><span class="fst-italic">(edytowano)</span><?php endif; ?>
            <?php if(can_edit()): ?>
            <div class="ms-auto d-flex gap-1">
              <form method="post" class="d-inline"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="note_pin"><input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                <button class="btn btn-xs btn-link p-0 text-muted" title="<?= $n['pinned']?'Odepnij':'Przypnij' ?>"><i class="bi bi-pin-angle<?= $n['pinned']?'-fill text-warning':'' ?>"></i></button>
              </form>
              <?php if($own): ?>
              <button class="btn btn-xs btn-link p-0 text-muted" type="button" data-bs-toggle="collapse" data-bs-target="#note-edit-<?= $n['id'] ?>" title="Edytuj"><i class="bi bi-pencil"></i></button>
              <form method="post" class="d-inline" onsubmit="return confirm('Usunąć notatkę?')"><input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="note_del"><input type="hidden" name="note_id" value="<?= $n['id'] ?>">
                <button class="btn btn-xs btn-link p-0 text-danger" title="Usuń"><i class="bi bi-trash3"></i></button>
              </form>
              <?php endif; ?>
            </div>
            <?php endif; ?>
          </div>
          <div style="white-space:pre-wrap"><?= h($n['tresc']) ?></div>
          <?php if($own && can_edit()): ?>
          <div class="collapse mt-2" id="note-edit-<?= $n['id'] ?>">
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>"><input type="hidden" name="_action" value="note_edit"><input type="hidden" name="note_id" value="<?= $n['id'] ?>">
              <textarea name="tresc" class="form-control form-control-sm mb-2" rows="2" required><?= h($n['tresc']) ?></textarea>
              <button class="btn btn-xs btn-primary btn-sm">Zapisz</button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if(!$notatki): ?>
        <div class="text-center text-muted py-2" style="font-size:.8rem">Brak notatek</div>
        <?php endif; ?>
      </div></div>
    </div>

    <!-- Repozytorium plików sprawy -->
    <div class="mt-4" id="files">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <h6 class="text-muted text-uppercase fw-bold mb-0" style="font-size:.7rem;letter-spacing:.1em">
          <i class="bi bi-folder2 me-1"></i>Repozytorium plików sprawy (<?= count($zalaczniki) ?>)
        </h6>
        <?php if(can_edit()): ?>
        <button class="btn btn-xs btn-outline-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#new-grupa"><i class="bi bi-folder-plus me-1"></i>Nowa grupa</button>
        <?php endif; ?>
      </div>

      <?php if(can_edit()): ?>
      <div class="collapse mb-3" id="new-grupa">
        <form method="post" class="d-flex gap-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="grupa_add">
          <input type="text" name="nazwa" class="form-control form-control-sm" placeholder="Nazwa grupy, np. Faktury / Korespondencja / Załączniki do umowy" required>
          <button class="btn btn-sm btn-primary flex-shrink-0"><i class="bi bi-check-lg me-1"></i>Utwórz</button>
        </form>
      </div>
      <?php endif; ?>

      <?php
      // Funkcja renderująca wiersz pliku (z przenoszeniem między grupami)
      $renderZal = function(array $z) use ($grupy) {
          $opts = '';
          $opts .= '<option value="0"'.(empty($z['grupa_id'])?' selected':'').'>— bez grupy —</option>';
          foreach ($grupy as $g) {
              $opts .= '<option value="'.$g['id'].'"'.(((int)($z['grupa_id']??0))===(int)$g['id']?' selected':'').'>'.h($g['nazwa']).'</option>';
          }
          ?>
          <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom" style="font-size:.8rem">
            <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5 flex-shrink-0"></i>
            <div class="flex-grow-1 overflow-hidden">
              <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" class="text-decoration-none fw-semibold text-truncate d-block"><?= h($z['original_name']) ?></a>
              <div class="text-muted" style="font-size:.7rem"><?= ezd_filesize($z['file_size']) ?> · <?= h($z['uploader'] ?? '—') ?> · <?= date('d.m.Y H:i', strtotime($z['uploaded_at'])) ?>
                <?php if($z['wersja']>1): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.6rem">v<?= $z['wersja'] ?></span><?php endif; ?>
              </div>
            </div>
            <?php if(can_edit() && $grupy): ?>
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="zal_move">
              <input type="hidden" name="zal_id" value="<?= $z['id'] ?>">
              <select name="grupa_id" class="form-select form-select-sm" style="width:auto;font-size:.72rem" onchange="this.form.submit()" title="Przenieś do grupy"><?= $opts ?></select>
            </form>
            <?php endif; ?>
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
          <?php
      };
      ?>

      <div class="card shadow-sm">
        <div class="card-body p-0">
          <?php if(!$zalaczniki && !$grupy): ?>
          <div class="text-center text-muted py-3" style="font-size:.8rem">Brak plików w repozytorium sprawy</div>
          <?php endif; ?>

          <?php foreach ($grupy as $g): ?>
          <div class="px-3 py-2 bg-light d-flex align-items-center gap-2" style="font-size:.76rem;border-bottom:1px solid #f1f5f9">
            <i class="bi bi-folder-fill text-warning"></i>
            <span class="fw-bold"><?= h($g['nazwa']) ?></span>
            <span class="badge bg-secondary bg-opacity-15 text-secondary"><?= (int)$g['plik_count'] ?></span>
            <?php if(can_edit()): ?>
            <form method="post" class="d-inline ms-auto" onsubmit="return confirm('Usunąć grupę? Pliki pozostaną w repozytorium (bez grupy).')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="grupa_del">
              <input type="hidden" name="grupa_id" value="<?= $g['id'] ?>">
              <button class="btn btn-xs btn-link p-0 text-muted" title="Usuń grupę"><i class="bi bi-x-circle"></i></button>
            </form>
            <?php endif; ?>
          </div>
          <?php if($grupy_map[(int)$g['id']]): foreach($grupy_map[(int)$g['id']] as $z) $renderZal($z); else: ?>
          <div class="text-muted px-4 py-2" style="font-size:.74rem">Grupa pusta — przenieś tu pliki z listy poniżej.</div>
          <?php endif; ?>
          <?php endforeach; ?>

          <?php if($grupy && $bez_grupy): ?>
          <div class="px-3 py-2 bg-light" style="font-size:.76rem;border-bottom:1px solid #f1f5f9"><i class="bi bi-folder2 me-1 text-muted"></i><span class="fw-bold text-muted">Bez grupy</span></div>
          <?php endif; ?>
          <?php foreach ($bez_grupy as $z) $renderZal($z); ?>

          <?php if(can_edit()): ?>
          <form method="post" enctype="multipart/form-data" class="p-3 border-top">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="upload">
            <div class="d-flex gap-2 align-items-center flex-wrap">
              <input type="file" name="file" class="form-control form-control-sm" style="max-width:260px"
                     accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.pptx,.png,.jpg,.jpeg,.zip,.txt,.csv,.eml,.msg" required>
              <?php if($grupy): ?>
              <select name="grupa_id" class="form-select form-select-sm" style="max-width:200px">
                <option value="0">— bez grupy —</option>
                <?php foreach($grupy as $g): ?><option value="<?= $g['id'] ?>"><?= h($g['nazwa']) ?></option><?php endforeach; ?>
              </select>
              <?php endif; ?>
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
          <dt class="col-5 text-muted fw-normal">Etap obiegu</dt>
          <dd class="col-7 mb-0"><?= ezd_etap_badge($sprawa['etap'] ?? 'wszczeta') ?></dd>
          <dt class="col-5 text-muted fw-normal">Termin</dt>
          <dd class="col-7 mb-0 <?= !empty($sprawa['ciagla'])?'text-info':($sprawa['deadline']&&$sprawa['deadline']<date('Y-m-d')&&!$is_closed?'text-danger fw-bold':'') ?>">
            <?= !empty($sprawa['ciagla']) ? '<i class="bi bi-infinity me-1"></i>stale otwarta' : ($sprawa['deadline'] ? date_pl($sprawa['deadline']) : '—') ?>
          </dd>
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

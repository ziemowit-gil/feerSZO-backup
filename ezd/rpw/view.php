<?php
/**
 * Podgląd przesyłki z dziennika podawczego (RPW) + akcje kancelaryjne:
 *  - przekazanie referentowi (koszulka),
 *  - dekretacja do sprawy (istniejącej lub nowej) → tworzy pismo przychodzące,
 *  - wgranie/wymiana skanu, edycja danych, odrzucenie, usunięcie.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł kancelarii');

$id  = (int)($_GET['id'] ?? 0);
$rpw = ezd_rpw_get($id);
if (!$rpw) { flash_set('error','Przesyłka nie istnieje.'); header('Location:'.APP_URL.'/ezd/rpw/index.php'); exit; }

$user_id = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_edit()) { http_response_code(403); exit; }
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'scan') {
            $err = ezd_rpw_scan_upload($id, 'scan', $user_id);
            flash_set($err ? 'error' : 'success', $err ?: 'Skan zapisany.');
        } elseif ($action === 'edit') {
            ezd_rpw_update($id, [
                'data_wplywu' => $_POST['data_wplywu'] ?? '',
                'typ'         => $_POST['typ'] ?? 'list',
                'nadawca'     => $_POST['nadawca'] ?? '',
                'znak_obcy'   => $_POST['znak_obcy'] ?? '',
                'opis'        => $_POST['opis'] ?? '',
                'uwagi'       => $_POST['uwagi'] ?? '',
            ], $user_id);
            flash_set('success', 'Dane przesyłki zaktualizowane.');
        } elseif ($action === 'przekaz') {
            $wyk = (int)($_POST['przekazano_do'] ?? 0);
            if (!$wyk) throw new \RuntimeException('Wybierz referenta.');
            ezd_rpw_przekaz($id, $wyk, $user_id);
            flash_set('success', 'Przesyłkę przekazano referentowi.');
        } elseif ($action === 'odrzuc') {
            ezd_rpw_odrzuc($id, $user_id, trim($_POST['powod'] ?? ''));
            flash_set('success', 'Przesyłkę oznaczono jako odrzuconą.');
        } elseif ($action === 'delete') {
            ezd_rpw_delete($id, $user_id);
            flash_set('success', 'Przesyłka usunięta.');
            header('Location:'.APP_URL.'/ezd/rpw/index.php'); exit;
        } elseif ($action === 'assign') {
            $pid = ezd_rpw_assign($id, [
                'sprawa_id'    => (int)($_POST['sprawa_id'] ?? 0),
                'teczka_id'    => (int)($_POST['teczka_id'] ?? 0),
                'sprawa_title' => trim($_POST['sprawa_title'] ?? ''),
            ], $user_id);
            $sp = db_one("SELECT sprawa_id FROM ezd_pisma WHERE id=?", [$pid]);
            flash_set('success', 'Utworzono pismo w sprawie i przeniesiono skan do akt.');
            header('Location:'.APP_URL.'/ezd/sprawy/view.php?id='.($sp['sprawa_id'] ?? 0)); exit;
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location:'.APP_URL.'/ezd/rpw/view.php?id='.$id); exit;
}

$PAGE_TITLE = ezd_rpw_label($rpw);
$typ      = EZD_RPW_TYPY[$rpw['typ']] ?? ['label'=>$rpw['typ'],'icon'=>'bi-envelope'];
$locked   = $rpw['status'] === 'w_sprawie';
$users    = $locked ? [] : db_all("SELECT id,name FROM users WHERE is_active=1 ORDER BY name");
$teczki   = $locked ? [] : ezd_teczki_all('open');
$sprawy   = $locked ? [] : ezd_sprawy_all(['status'=>'']);
$sprawy   = array_filter($sprawy, fn($s)=>$s['status']!=='closed');

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">Kancelaria</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/rpw/index.php">Dziennik podawczy</a></li>
  <li class="breadcrumb-item active"><?= h(ezd_rpw_label($rpw)) ?></li>
</ol></nav>

<?= flash_html() ?>

<div class="row g-4">
  <!-- LEWA: dane + skan -->
  <div class="col-lg-7">
    <div class="card shadow-sm mb-4"><div class="card-body">
      <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
        <code class="bg-light px-2 py-0 rounded fw-bold" style="font-size:.9rem;color:#1d4ed8"><?= h(ezd_rpw_label($rpw)) ?></code>
        <?= ezd_rpw_status_badge($rpw['status']) ?>
        <span class="text-muted" style="font-size:.78rem"><i class="bi <?= $typ['icon'] ?> me-1"></i><?= h($typ['label']) ?></span>
      </div>
      <h4 class="fw-bold mb-3"><?= h($rpw['opis'] ?: '—') ?></h4>
      <dl class="row mb-0" style="font-size:.85rem;row-gap:.4rem">
        <dt class="col-4 col-sm-3 text-muted fw-normal">Data wpływu</dt>
        <dd class="col-8 col-sm-9 mb-0"><?= date_pl($rpw['data_wplywu']) ?></dd>
        <dt class="col-4 col-sm-3 text-muted fw-normal">Nadawca</dt>
        <dd class="col-8 col-sm-9 mb-0"><?= h($rpw['nadawca'] ?: '—') ?></dd>
        <?php if($rpw['znak_obcy']): ?>
        <dt class="col-4 col-sm-3 text-muted fw-normal">Znak nadawcy</dt>
        <dd class="col-8 col-sm-9 mb-0 font-monospace"><?= h($rpw['znak_obcy']) ?></dd>
        <?php endif; ?>
        <?php if($rpw['uwagi']): ?>
        <dt class="col-4 col-sm-3 text-muted fw-normal">Uwagi</dt>
        <dd class="col-8 col-sm-9 mb-0"><?= nl2br(h($rpw['uwagi'])) ?></dd>
        <?php endif; ?>
        <dt class="col-4 col-sm-3 text-muted fw-normal">Zarejestrował</dt>
        <dd class="col-8 col-sm-9 mb-0"><?= h($rpw['creator_name'] ?: '—') ?> · <?= date('d.m.Y H:i', strtotime($rpw['created_at'])) ?></dd>
      </dl>

      <?php if($locked && $rpw['znak_sprawy']): ?>
      <div class="alert alert-success d-flex align-items-center gap-2 mt-3 mb-0 py-2" style="font-size:.84rem">
        <i class="bi bi-check-circle-fill"></i>
        <span>Zarejestrowana w sprawie
          <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $rpw['sprawa_id'] ?>" class="fw-semibold font-monospace"><?= h($rpw['znak_sprawy']) ?></a>
          jako pismo <?= h($rpw['pismo_sygnatura'] ?? '') ?>.</span>
      </div>
      <?php endif; ?>
    </div></div>

    <!-- Skan -->
    <div class="card shadow-sm">
      <div class="card-header py-2"><span class="fw-semibold" style="font-size:.84rem"><i class="bi bi-paperclip me-1"></i>Skan przesyłki</span></div>
      <div class="card-body">
        <?php if($rpw['scan_file']): ?>
        <div class="d-flex align-items-center gap-2">
          <i class="bi <?= ezd_file_icon($rpw['scan_name']) ?> fs-4"></i>
          <div class="flex-grow-1">
            <a href="<?= APP_URL ?>/ezd/rpw/scan.php?id=<?= $id ?>" target="_blank" class="fw-semibold text-decoration-none"><?= h($rpw['scan_name']) ?></a>
            <div class="text-muted" style="font-size:.72rem"><?= ezd_filesize((int)$rpw['scan_size']) ?></div>
          </div>
          <a href="<?= APP_URL ?>/ezd/rpw/scan.php?id=<?= $id ?>&dl=1" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i></a>
        </div>
        <?php elseif($locked): ?>
        <div class="text-muted" style="font-size:.82rem">Skan przeniesiono do akt sprawy.</div>
        <?php else: ?>
        <div class="text-muted mb-2" style="font-size:.82rem">Brak skanu.</div>
        <?php endif; ?>

        <?php if(can_edit() && !$locked): ?>
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center flex-wrap mt-3 pt-3 border-top">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="scan">
          <input type="file" name="scan" class="form-control form-control-sm" style="max-width:280px" accept=".pdf,.doc,.docx,.xls,.xlsx,.odt,.ods,.pptx,.png,.jpg,.jpeg,.zip,.txt,.csv,.eml,.msg" required>
          <button class="btn btn-sm btn-outline-primary"><i class="bi bi-upload me-1"></i><?= $rpw['scan_file'] ? 'Wymień skan' : 'Dodaj skan' ?></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- PRAWA: akcje -->
  <div class="col-lg-5">
    <?php if(can_edit() && !$locked): ?>

    <!-- Dekretacja do sprawy -->
    <div class="card shadow-sm mb-3 border-success">
      <div class="card-header bg-success bg-opacity-10 py-2"><span class="fw-semibold text-success" style="font-size:.84rem"><i class="bi bi-folder-symlink me-1"></i>Dekretuj do sprawy</span></div>
      <div class="card-body">
        <ul class="nav nav-pills nav-fill mb-3" style="font-size:.78rem">
          <li class="nav-item"><a class="nav-link active" data-bs-toggle="pill" href="#tab-exist">Istniejąca sprawa</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="pill" href="#tab-new">Nowa sprawa</a></li>
        </ul>
        <div class="tab-content">
          <!-- istniejąca -->
          <div class="tab-pane fade show active" id="tab-exist">
            <?php if($sprawy): ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="assign">
              <select name="sprawa_id" class="form-select form-select-sm mb-2" required>
                <option value="">— wybierz sprawę —</option>
                <?php foreach($sprawy as $s): ?>
                <option value="<?= $s['id'] ?>"><?= h($s['znak_sprawy']) ?> — <?= h(mb_substr($s['title'],0,40)) ?></option>
                <?php endforeach; ?>
              </select>
              <button class="btn btn-success btn-sm w-100"><i class="bi bi-arrow-right-circle me-1"></i>Utwórz pismo w sprawie</button>
            </form>
            <?php else: ?>
            <div class="text-muted text-center py-2" style="font-size:.8rem">Brak otwartych spraw — użyj „Nowa sprawa".</div>
            <?php endif; ?>
          </div>
          <!-- nowa -->
          <div class="tab-pane fade" id="tab-new">
            <?php if($teczki): ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="assign">
              <label class="form-label mb-1" style="font-size:.74rem">Teczka (JRWA)</label>
              <select name="teczka_id" class="form-select form-select-sm mb-2" required>
                <option value="">— wybierz teczkę —</option>
                <?php foreach($teczki as $t): ?>
                <option value="<?= $t['id'] ?>"><?= h($t['symbol']) ?> — <?= h(mb_substr($t['title'],0,35)) ?> (<?= $t['rok'] ?>)</option>
                <?php endforeach; ?>
              </select>
              <label class="form-label mb-1" style="font-size:.74rem">Tytuł sprawy</label>
              <input type="text" name="sprawa_title" class="form-control form-control-sm mb-2" value="<?= h($rpw['opis']) ?>" placeholder="Tytuł nowej sprawy" required>
              <button class="btn btn-success btn-sm w-100"><i class="bi bi-folder-plus me-1"></i>Załóż sprawę i utwórz pismo</button>
            </form>
            <?php else: ?>
            <div class="text-muted text-center py-2" style="font-size:.8rem">Brak otwartych teczek. <a href="<?= APP_URL ?>/ezd/teczki/add.php">Załóż teczkę</a>.</div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Przekazanie / odrzucenie -->
    <div class="card shadow-sm mb-3"><div class="card-body">
      <div class="fw-semibold mb-2" style="font-size:.82rem"><i class="bi bi-person-up me-1"></i>Przekaż do referenta</div>
      <form method="post" class="d-flex gap-2 mb-3">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="przekaz">
        <select name="przekazano_do" class="form-select form-select-sm" required>
          <option value="">— osoba —</option>
          <?php foreach($users as $u): ?><option value="<?= $u['id'] ?>" <?= (int)$rpw['przekazano_do']===$u['id']?'selected':'' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-send"></i></button>
      </form>
      <?php if($rpw['przekazano_name']): ?>
      <div class="text-muted mb-3" style="font-size:.76rem">Obecnie w koszulce: <strong><?= h($rpw['przekazano_name']) ?></strong></div>
      <?php endif; ?>

      <details>
        <summary class="text-muted" style="font-size:.78rem;cursor:pointer">Odrzuć przesyłkę (pomyłka / nie nasza)</summary>
        <form method="post" class="mt-2">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="odrzuc">
          <input type="text" name="powod" class="form-control form-control-sm mb-2" placeholder="Powód (opcjonalnie)">
          <button class="btn btn-sm btn-outline-secondary w-100" onclick="return confirm('Oznaczyć przesyłkę jako odrzuconą?')">Odrzuć</button>
        </form>
      </details>
    </div></div>

    <!-- Edycja danych -->
    <div class="card shadow-sm mb-3">
      <div class="card-header py-2"><a class="text-decoration-none text-muted" data-bs-toggle="collapse" href="#edit-rpw" style="font-size:.82rem"><i class="bi bi-pencil me-1"></i>Edytuj dane przesyłki</a></div>
      <div class="collapse" id="edit-rpw"><div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="edit">
          <div class="mb-2"><label class="form-label mb-1" style="font-size:.74rem">Data wpływu</label>
            <input type="date" name="data_wplywu" class="form-control form-control-sm" value="<?= h($rpw['data_wplywu']) ?>" max="<?= date('Y-m-d') ?>"></div>
          <div class="mb-2"><label class="form-label mb-1" style="font-size:.74rem">Sposób doręczenia</label>
            <select name="typ" class="form-select form-select-sm"><?php foreach(EZD_RPW_TYPY as $tv=>$ti): ?><option value="<?= $tv ?>" <?= $rpw['typ']===$tv?'selected':'' ?>><?= h($ti['label']) ?></option><?php endforeach; ?></select></div>
          <div class="mb-2"><label class="form-label mb-1" style="font-size:.74rem">Nadawca</label>
            <input type="text" name="nadawca" class="form-control form-control-sm" value="<?= h($rpw['nadawca']) ?>"></div>
          <div class="mb-2"><label class="form-label mb-1" style="font-size:.74rem">Znak nadawcy</label>
            <input type="text" name="znak_obcy" class="form-control form-control-sm" value="<?= h($rpw['znak_obcy']) ?>"></div>
          <div class="mb-2"><label class="form-label mb-1" style="font-size:.74rem">Opis</label>
            <input type="text" name="opis" class="form-control form-control-sm" value="<?= h($rpw['opis']) ?>" required></div>
          <div class="mb-2"><label class="form-label mb-1" style="font-size:.74rem">Uwagi</label>
            <textarea name="uwagi" class="form-control form-control-sm" rows="2"><?= h($rpw['uwagi']) ?></textarea></div>
          <button class="btn btn-sm btn-primary w-100">Zapisz zmiany</button>
        </form>
      </div></div>
    </div>

    <?php if(is_admin()): ?>
    <form method="post" onsubmit="return confirm('Usunąć przesyłkę z dziennika? Operacji nie można cofnąć.')">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="delete">
      <button class="btn btn-sm btn-outline-danger w-100"><i class="bi bi-trash3 me-1"></i>Usuń przesyłkę</button>
    </form>
    <?php endif; ?>

    <?php else: ?>
    <div class="card shadow-sm"><div class="card-body text-center text-muted" style="font-size:.84rem">
      <i class="bi bi-lock-fill d-block mb-2" style="font-size:1.6rem;opacity:.4"></i>
      <?= $locked ? 'Przesyłka została zadekretowana do sprawy — dalsze działania prowadź na karcie sprawy.' : 'Brak uprawnień do działań kancelaryjnych.' ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

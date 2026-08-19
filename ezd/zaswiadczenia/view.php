<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/zaswiadczenia_ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$id      = (int)($_GET['id'] ?? 0);
$zas     = ezd_zas_get($id);
if (!$zas) { flash_set('error', 'Wniosek nie istnieje.'); header('Location:' . APP_URL . '/ezd/zaswiadczenia/index.php'); exit; }

$user_id   = (int)current_user()['id'];
$can_mgr   = ezd_is_manager() || can_edit();
$is_owner  = (int)$zas['created_by'] === $user_id;

if (!$can_mgr && !$is_owner) {
    flash_set('error', 'Brak dostępu do tego wniosku.');
    header('Location:' . APP_URL . '/ezd/zaswiadczenia/index.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_mgr) { http_response_code(403); exit; }
    $act = $_POST['_action'] ?? '';

    if ($act === 'wydaj') {
        $sprawa_id_override = null;
        $sprawa_input = trim($_POST['sprawa_picker'] ?? '');
        if ($sprawa_input !== '') {
            $found = db_one(
                "SELECT id FROM ezd_sprawy WHERE znak_sprawy=? OR CAST(id AS TEXT)=? LIMIT 1",
                [$sprawa_input, $sprawa_input]
            );
            if ($found) $sprawa_id_override = (int)$found['id'];
        }
        $tresc_override = trim($_POST['tresc_override'] ?? '');
        $include_qr = isset($_POST['include_qr']) ? true : false;
        $z_urzedu   = isset($_POST['z_urzedu']) ? 1 : 0;
        $res = ezd_zas_wydaj($id, $user_id, $sprawa_id_override, $tresc_override, $include_qr);
        if ($res['ok']) {
            db()->prepare("UPDATE ezd_zaswiadczenia_wlasne SET z_urzedu=? WHERE id=?")->execute([$z_urzedu, $id]);
        }
        flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Zaświadczenie ' . h($res['nr']) . ' wydane.' : $res['error']);
    }

    if ($act === 'wydaj_plik') {
        if (empty($_FILES['plik']['tmp_name']) || $_FILES['plik']['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'Nie wybrano pliku lub błąd przesyłania (max ' . ini_get('upload_max_filesize') . ').');
        } else {
            $upload_dir = UPLOAD_DIR . 'zaswiadczenia/';
            if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);
            $ext      = strtolower(pathinfo($_FILES['plik']['name'], PATHINFO_EXTENSION)) ?: 'bin';
            $plik_path = $upload_dir . 'zas_' . $id . '_' . time() . '.' . $ext;
            $plik_mime = $_FILES['plik']['type'] ?: 'application/octet-stream';
            if (move_uploaded_file($_FILES['plik']['tmp_name'], $plik_path)) {
                $sprawa_id_override = null;
                $sprawa_input = trim($_POST['sprawa_picker'] ?? '');
                if ($sprawa_input !== '') {
                    $found = db_one("SELECT id FROM ezd_sprawy WHERE znak_sprawy=? OR CAST(id AS TEXT)=? LIMIT 1", [$sprawa_input, $sprawa_input]);
                    if ($found) $sprawa_id_override = (int)$found['id'];
                }
                $z_urzedu = isset($_POST['z_urzedu']) ? 1 : 0;
                $res = ezd_zas_wydaj_plik($id, $user_id, $plik_path, $plik_mime, $sprawa_id_override);
                if ($res['ok']) {
                    db()->prepare("UPDATE ezd_zaswiadczenia_wlasne SET z_urzedu=? WHERE id=?")->execute([$z_urzedu, $id]);
                }
                flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Zaświadczenie ' . h($res['nr']) . ' wydane (plik własny).' : $res['error']);
            } else {
                flash_set('error', 'Błąd zapisu pliku na serwerze.');
            }
        }
    }
    if ($act === 'weryfikacja') {
        db()->prepare("UPDATE ezd_zaswiadczenia_wlasne SET status='weryfikacja',updated_at=datetime('now') WHERE id=? AND status='wniosek'")->execute([$id]);
        flash_set('success', 'Wniosek przyjęty do weryfikacji.');
    }
    if ($act === 'odrzuc') {
        $powod = trim($_POST['odrzucone_powod'] ?? '');
        ezd_zas_odrzuc($id, $powod, $user_id);
        flash_set('success', 'Wniosek odrzucony.');
    }
    if ($act === 'delete_zas') {
        if (!is_admin()) { flash_set('error', 'Tylko administrator może usuwać zaświadczenia.'); }
        else {
            ezd_zas_delete($id);
            flash_set('success', 'Wniosek usunięty.');
            header('Location:' . APP_URL . '/ezd/zaswiadczenia/index.php'); exit;
        }
    }
    if ($act === 'wydaj_recznie') {
        $sprawa_id_override = null;
        $sprawa_input = trim($_POST['sprawa_picker'] ?? '');
        if ($sprawa_input !== '') {
            $found = db_one(
                "SELECT id FROM ezd_sprawy WHERE znak_sprawy=? OR CAST(id AS TEXT)=? LIMIT 1",
                [$sprawa_input, $sprawa_input]
            );
            if ($found) $sprawa_id_override = (int)$found['id'];
        }
        $include_qr = isset($_POST['include_qr']);
        $z_urzedu   = isset($_POST['z_urzedu']) ? 1 : 0;
        $res = ezd_zas_wydaj($id, $user_id, $sprawa_id_override, '', $include_qr, true);
        if ($res['ok']) {
            db()->prepare("UPDATE ezd_zaswiadczenia_wlasne SET z_urzedu=? WHERE id=?")->execute([$z_urzedu, $id]);
        }
        flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Wydano ręcznie — numer: ' . h($res['nr']) : $res['error']);
    }
    if ($act === 'assign_qr') {
        $code = ezd_zas_assign_qr($id);
        flash_set($code ? 'success' : 'error', $code ? 'Kod QR przypisany. Pobierz PDF, aby zobaczyć kod.' : 'Nie można przypisać kodu QR (zaświadczenie niespełnia warunków).');
    }
    if ($act === 'edytuj_tresc' && $issued && $can_mgr) {
        $new_html = trim($_POST['tresc_html'] ?? '');
        if ($new_html !== '') {
            db()->prepare("UPDATE ezd_zaswiadczenia_wlasne SET tresc_html=?, updated_at=datetime('now') WHERE id=?")
                ->execute([$new_html, $id]);
            flash_set('success', 'Treść zaświadczenia zapisana.');
        }
    }
    if ($act === 'regeneruj_pdf' && $issued && empty($zas['plik_path'])) {
        $dane = $zas['dane'] ?? [];
        if (($zas['typ_kod'] ?? '') === 'oswiadczenie_student_wspolpraca') {
            $dane = ezd_zas_compute_derived_tokens_osw_st($dane);
        }
        $new_tresc = ezd_zas_render(
            $zas['szablon_tresc'],
            $dane,
            ['nr_zaswiadczenia' => $zas['nr_zaswiadczenia'] ?? '']
        );
        db()->prepare("UPDATE ezd_zaswiadczenia_wlasne SET tresc_html=?, updated_at=datetime('now') WHERE id=?")
            ->execute([$new_tresc, $id]);
        flash_set('success', 'Treść zaświadczenia została przerenderowana z aktualnego szablonu.');
    }
    header('Location:' . APP_URL . '/ezd/zaswiadczenia/view.php?id=' . $id); exit;
}

$st     = $zas['status'];
$issued = $st === 'wydane';
$open   = in_array($st, ['wniosek', 'weryfikacja'], true);

// Otwarte koszulki do datalist (ograniczone do JRWA typu jeśli podane)
$open_sprawy = [];
if ($can_mgr) {
    $jrwa_cond = $zas['jrwa_id']
        ? "AND t.jrwa_id=" . (int)$zas['jrwa_id']
        : '';
    $open_sprawy = db_all(
        "SELECT s.id, s.znak_sprawy, s.title
         FROM ezd_sprawy s
         JOIN ezd_teczki t ON t.id=s.teczka_id
         WHERE s.status='open' $jrwa_cond
         ORDER BY s.id DESC LIMIT 200"
    );
}

$PAGE_TITLE = 'Zaświadczenie — ' . ($zas['nr_zaswiadczenia'] ?: 'wniosek #' . $id);
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.zas-tresc{font-family:inherit;line-height:1.8;white-space:pre-wrap;border-left:3px solid #2563eb;padding:.75rem 1rem;background:#f0f9ff;border-radius:.25rem;}
</style>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/zaswiadczenia/index.php">Zaświadczenia</a></li>
  <li class="breadcrumb-item active"><?= $zas['nr_zaswiadczenia'] ? h($zas['nr_zaswiadczenia']) : 'Wniosek #'.$id ?></li>
</ol></nav>

<?= flash_html() ?>

<div class="row g-4">
  <!-- Lewa: szczegóły -->
  <div class="col-lg-8">
    <div class="card shadow-sm mb-3">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-award-fill text-primary"></i>
        <span class="fw-semibold" style="font-size:.9rem">
          <?php if($issued): ?><span class="font-monospace"><?= h($zas['nr_zaswiadczenia']) ?></span><?php else: ?>Wniosek o zaświadczenie<?php endif; ?>
        </span>
        <?= ezd_zas_status_badge($st) ?>
        <div class="ms-auto d-flex gap-2">
          <?php if($issued): ?>
          <a href="<?= APP_URL ?>/ezd/zaswiadczenia/pdf.php?id=<?= $id ?>" target="_blank"
             class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
          <a href="<?= APP_URL ?>/ezd/zaswiadczenia/pdf.php?id=<?= $id ?>&duplikat=1" target="_blank"
             class="btn btn-outline-secondary btn-sm" title="Drukuj duplikat z adnotacją daty wydruku"><i class="bi bi-files me-1"></i>Duplikat</a>
          <?php if($can_mgr && empty($zas['plik_path'])): ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Przerenderować treść z aktualnego szablonu? Nadpisze obecną treść zaświadczenia.')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="regeneruj_pdf">
            <button class="btn btn-outline-warning btn-sm" title="Regeneruj PDF z aktualnego szablonu"><i class="bi bi-arrow-clockwise me-1"></i>Regeneruj</button>
          </form>
          <?php endif; ?>
          <?php if($can_mgr && empty($zas['verify_code']) && empty($zas['plik_path'])): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="assign_qr">
            <button class="btn btn-outline-secondary btn-sm" title="Generuj kod QR weryfikacyjny"><i class="bi bi-qr-code"></i></button>
          </form>
          <?php elseif(!empty($zas['verify_code'])): ?>
          <a href="<?= APP_URL ?>/ezd/zaswiadczenia/verify.php?code=<?= rawurlencode($zas['verify_code']) ?>" target="_blank"
             class="btn btn-outline-secondary btn-sm" title="Strona weryfikacji QR"><i class="bi bi-qr-code-scan"></i></a>
          <?php endif; ?>
          <?php endif; ?>
          <?php if(is_admin()): ?>
          <form method="post" onsubmit="return confirm('Na pewno usunąć ten wniosek/zaświadczenie? Operacja jest nieodwracalna.')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="_action" value="delete_zas">
            <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash3"></i></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <dl class="row mb-0" style="font-size:.83rem">
          <dt class="col-sm-3 text-muted fw-normal">Typ</dt>
          <dd class="col-sm-9 fw-semibold"><?= h($zas['typ_nazwa']) ?></dd>
          <?php if(!empty($zas['z_urzedu'])): ?>
          <dt class="col-sm-3 text-muted fw-normal">Inicjatywa</dt>
          <dd class="col-sm-9"><span class="badge bg-secondary bg-opacity-15 text-secondary border"><i class="bi bi-building me-1"></i>Z urzędu (inicjatywa organizacji)</span></dd>
          <?php endif; ?>
          <dt class="col-sm-3 text-muted fw-normal">Wnioskodawca</dt>
          <dd class="col-sm-9"><?= h($zas['wnioskodawca_name']) ?></dd>
          <?php if($zas['wnioskodawca_email']): ?>
          <dt class="col-sm-3 text-muted fw-normal">E-mail</dt>
          <dd class="col-sm-9"><a href="mailto:<?= h($zas['wnioskodawca_email']) ?>"><?= h($zas['wnioskodawca_email']) ?></a></dd>
          <?php endif; ?>
          <dt class="col-sm-3 text-muted fw-normal">Złożono</dt>
          <dd class="col-sm-9"><?= date_pl(substr($zas['created_at'],0,10)) ?> <span class="text-muted">(<?= h($zas['created_by_name'] ?? '—') ?>)</span></dd>
          <?php if($zas['zatwierdzone_at']): ?>
          <dt class="col-sm-3 text-muted fw-normal"><?= $issued?'Wydano':'Weryfikacja' ?></dt>
          <dd class="col-sm-9"><?= date_pl(substr($zas['zatwierdzone_at'],0,10)) ?> <span class="text-muted">(<?= h($zas['zatw_name'] ?? '—') ?>)</span></dd>
          <?php endif; ?>
          <?php if(!empty($zas['wazne_do'])): ?>
          <?php $wdt=strtotime($zas['wazne_do']); $expired=$wdt&&$wdt<time(); ?>
          <dt class="col-sm-3 text-muted fw-normal">Ważne do</dt>
          <dd class="col-sm-9 <?= $expired?'text-danger fw-semibold':'' ?>">
            <?= function_exists('date_pl') ? date_pl($zas['wazne_do']) : date('d.m.Y',$wdt) ?>
            <?= $expired ? '<span class="badge bg-danger ms-1" style="font-size:.65rem">WYGASŁE</span>' : '' ?>
          </dd>
          <?php endif; ?>
          <?php if($zas['odrzucone_powod']): ?>
          <dt class="col-sm-3 text-muted fw-normal">Powód odrzucenia</dt>
          <dd class="col-sm-9 text-danger"><?= h($zas['odrzucone_powod']) ?></dd>
          <?php endif; ?>
          <?php if(!empty($zas['verify_code']) && $issued): ?>
          <dt class="col-sm-3 text-muted fw-normal">Kod QR</dt>
          <dd class="col-sm-9">
            <a href="<?= APP_URL ?>/ezd/zaswiadczenia/verify.php?code=<?= rawurlencode($zas['verify_code']) ?>" target="_blank" class="text-decoration-none">
              <i class="bi bi-qr-code me-1"></i>Link weryfikacyjny
            </a>
          </dd>
          <?php endif; ?>
          <?php if($zas['znak_sprawy']): ?>
          <dt class="col-sm-3 text-muted fw-normal">Koszulka EZD</dt>
          <dd class="col-sm-9"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $zas['sprawa_id'] ?>" class="font-monospace"><?= h($zas['znak_sprawy']) ?></a></dd>
          <?php endif; ?>
          <?php if($zas['pismo_syg']): ?>
          <dt class="col-sm-3 text-muted fw-normal">Pismo EZD</dt>
          <dd class="col-sm-9 font-monospace"><?= h($zas['pismo_syg']) ?></dd>
          <?php endif; ?>
        </dl>

        <?php if($zas['dane']): ?>
        <hr class="my-3">
        <div class="fw-semibold mb-2" style="font-size:.82rem">Dane wniosku</div>
        <dl class="row mb-0" style="font-size:.82rem">
          <?php foreach($zas['pola'] as $pole): $k=$pole['name']??''; ?>
          <?php if(isset($zas['dane'][$k])): ?>
          <dt class="col-sm-4 text-muted fw-normal"><?= h($pole['label']??$k) ?></dt>
          <dd class="col-sm-8"><?= h($zas['dane'][$k]) ?></dd>
          <?php endif; ?>
          <?php endforeach; ?>
        </dl>
        <?php endif; ?>
      </div>
    </div>

    <!-- Treść wydanego zaświadczenia -->
    <?php if($issued && $zas['tresc_html']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold d-flex justify-content-between align-items-center" style="font-size:.88rem">
        <span><i class="bi bi-file-text me-1 text-primary"></i>Treść zaświadczenia</span>
        <?php if($can_mgr): ?>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-edit-tresc"
                onclick="document.getElementById('tresc-view').classList.toggle('d-none');document.getElementById('tresc-edit').classList.toggle('d-none');this.textContent=this.textContent.trim()==='Edytuj'?'Anuluj':'Edytuj'">
          Edytuj
        </button>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <div id="tresc-view" class="zas-tresc"><?= $zas['tresc_html'] ?></div>
        <?php if($can_mgr): ?>
        <div id="tresc-edit" class="d-none">
          <form method="post">
            <input type="hidden" name="_action" value="edytuj_tresc">
            <textarea id="tinymce-tresc" name="tresc_html" style="width:100%;min-height:320px"><?= h($zas['tresc_html']) ?></textarea>
            <div class="mt-2 text-end">
              <button type="submit" class="btn btn-sm btn-primary">Zapisz treść</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Prawa: akcje -->
  <div class="col-lg-4">
    <?php if($can_mgr && $open): ?>
    <?php if($open_sprawy): ?>
    <datalist id="sprawy-list">
      <?php foreach($open_sprawy as $sp): ?>
      <option value="<?= h($sp['znak_sprawy']) ?>"><?= h(mb_substr($sp['title'],0,60)) ?></option>
      <?php endforeach; ?>
    </datalist>
    <?php endif; ?>

    <div class="card shadow-sm mb-3 border-primary" style="border-width:1.5px!important">
      <div class="card-header fw-semibold text-primary" style="font-size:.88rem"><i class="bi bi-person-check me-1"></i>Akcje weryfikatora</div>
      <div class="card-body d-flex flex-column gap-2">

        <?php if($st === 'wniosek' && $zas['wymaga_akceptacji']): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="weryfikacja">
          <button class="btn btn-outline-warning w-100 btn-sm"><i class="bi bi-search me-1"></i>Przyjmij do weryfikacji</button>
        </form>
        <?php endif; ?>

        <!-- Tryb wydania: z szablonu / plik własny / ręcznie -->
        <ul class="nav nav-pills" style="font-size:.77rem" id="wydaj-nav">
          <li class="nav-item">
            <button type="button" class="nav-link active py-1 px-2" data-bs-toggle="pill" data-bs-target="#wp-szablon">
              <i class="bi bi-file-earmark-text me-1"></i>Z szablonu
            </button>
          </li>
          <li class="nav-item">
            <button type="button" class="nav-link py-1 px-2" data-bs-toggle="pill" data-bs-target="#wp-plik">
              <i class="bi bi-upload me-1"></i>Plik własny
            </button>
          </li>
          <li class="nav-item">
            <button type="button" class="nav-link py-1 px-2" data-bs-toggle="pill" data-bs-target="#wp-recznie">
              <i class="bi bi-pencil me-1"></i>Ręcznie
            </button>
          </li>
        </ul>

        <div class="tab-content">
          <!-- Z szablonu -->
          <div class="tab-pane show active" id="wp-szablon">
            <form method="post" id="form-wydaj-szablon">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="wydaj">
              <?php if($zas['szablon_tresc']): ?>
              <div class="mb-2">
                <label class="form-label fw-semibold mb-1" style="font-size:.74rem">Treść zaświadczenia (możesz edytować)</label>
                <textarea name="tresc_override" id="tresc-override-editor" class="form-control form-control-sm" rows="6"><?= h(ezd_zas_render($zas['szablon_tresc'], $zas['dane'], ['nr_zaswiadczenia' => '[NUMER]'])) ?></textarea>
              </div>
              <?php endif; ?>
              <div class="mb-2">
                <label class="form-label fw-semibold mb-1" style="font-size:.74rem">
                  Koszulka EZD
                  <?php if(!$zas['sprawa_id']): ?><span class="text-warning ms-1" title="Brak przypisanej koszulki"><i class="bi bi-exclamation-triangle-fill"></i></span><?php endif; ?>
                </label>
                <?php if($zas['sprawa_id']): ?>
                <div class="d-flex align-items-center gap-2 mb-1">
                  <span class="badge bg-success bg-opacity-15 text-success font-monospace"><?= h($zas['znak_sprawy']) ?></span>
                  <span class="text-muted" style="font-size:.72rem">przypisana</span>
                </div>
                <input type="text" name="sprawa_picker" list="sprawy-list" class="form-control form-control-sm" placeholder="Inna koszulka (opcjonalnie)">
                <?php else: ?>
                <input type="text" name="sprawa_picker" list="sprawy-list" class="form-control form-control-sm" placeholder="Wpisz znak koszulki…">
                <div class="text-muted mt-1" style="font-size:.71rem"><i class="bi bi-info-circle me-1"></i>Możesz wydać bez koszulki.</div>
                <?php endif; ?>
              </div>
              <?php if($zas['qr_enabled']): ?>
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="include_qr" value="1" id="chk-qr-szablon" checked>
                <label class="form-check-label" for="chk-qr-szablon" style="font-size:.8rem"><i class="bi bi-qr-code me-1"></i>Dodaj kod QR weryfikacyjny do PDF</label>
              </div>
              <?php endif; ?>
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="z_urzedu" value="1" id="chk-zurzedu-szablon">
                <label class="form-check-label" for="chk-zurzedu-szablon" style="font-size:.8rem"><i class="bi bi-building me-1"></i>Wystawione z inicjatywy organizacji (z urzędu)</label>
              </div>
              <div class="d-flex gap-2">
                <a href="<?= APP_URL ?>/ezd/zaswiadczenia/preview.php?id=<?= $id ?>" target="_blank"
                   class="btn btn-outline-secondary btn-sm flex-grow-1" id="btn-preview-pdf">
                  <i class="bi bi-eye me-1"></i>Podgląd PDF
                </a>
                <button class="btn btn-success btn-sm flex-grow-1"><i class="bi bi-award-fill me-1"></i>Wydaj</button>
              </div>
            </form>
          </div>

          <!-- Plik własny -->
          <div class="tab-pane" id="wp-plik">
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="wydaj_plik">
              <div class="mb-2">
                <label class="form-label fw-semibold mb-1" style="font-size:.74rem">Plik zaświadczenia (PDF)</label>
                <input type="file" name="plik" class="form-control form-control-sm" accept=".pdf,application/pdf" required>
                <div class="text-muted mt-1" style="font-size:.71rem"><i class="bi bi-info-circle me-1"></i>Numer zostanie nadany automatycznie. Treść z szablonu nie jest generowana.</div>
              </div>
              <div class="mb-2">
                <label class="form-label fw-semibold mb-1" style="font-size:.74rem">Koszulka EZD
                  <?php if(!$zas['sprawa_id']): ?><span class="text-warning ms-1"><i class="bi bi-exclamation-triangle-fill"></i></span><?php endif; ?>
                </label>
                <?php if($zas['sprawa_id']): ?>
                <span class="badge bg-success bg-opacity-15 text-success font-monospace d-block mb-1"><?= h($zas['znak_sprawy']) ?></span>
                <input type="text" name="sprawa_picker" list="sprawy-list" class="form-control form-control-sm" placeholder="Inna koszulka (opcjonalnie)">
                <?php else: ?>
                <input type="text" name="sprawa_picker" list="sprawy-list" class="form-control form-control-sm" placeholder="Wpisz znak koszulki…">
                <?php endif; ?>
              </div>
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="z_urzedu" value="1" id="chk-zurzedu-plik">
                <label class="form-check-label" for="chk-zurzedu-plik" style="font-size:.8rem"><i class="bi bi-building me-1"></i>Wystawione z inicjatywy organizacji (z urzędu)</label>
              </div>
              <button class="btn btn-success w-100 btn-sm"><i class="bi bi-upload me-1"></i>Wydaj (z pliku)</button>
            </form>
          </div>

          <!-- Ręcznie -->
          <div class="tab-pane" id="wp-recznie">
            <form method="post">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="wydaj_recznie">
              <div class="alert alert-warning py-2 mb-2" style="font-size:.75rem">
                <i class="bi bi-exclamation-triangle me-1"></i>Nadaje numer bez generowania treści z szablonu. Zaświadczenie wystawione poza systemem.
              </div>
              <div class="mb-2">
                <label class="form-label fw-semibold mb-1" style="font-size:.74rem">Koszulka EZD
                  <?php if(!$zas['sprawa_id']): ?><span class="text-warning ms-1"><i class="bi bi-exclamation-triangle-fill"></i></span><?php endif; ?>
                </label>
                <?php if($zas['sprawa_id']): ?>
                <span class="badge bg-success bg-opacity-15 text-success font-monospace d-block mb-1"><?= h($zas['znak_sprawy']) ?></span>
                <input type="text" name="sprawa_picker" list="sprawy-list" class="form-control form-control-sm" placeholder="Inna koszulka (opcjonalnie)">
                <?php else: ?>
                <input type="text" name="sprawa_picker" list="sprawy-list" class="form-control form-control-sm" placeholder="Wpisz znak koszulki…">
                <?php endif; ?>
              </div>
              <?php if($zas['qr_enabled']): ?>
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="include_qr" value="1" id="chk-qr-recznie">
                <label class="form-check-label" for="chk-qr-recznie" style="font-size:.8rem"><i class="bi bi-qr-code me-1"></i>Dodaj kod QR weryfikacyjny</label>
              </div>
              <?php endif; ?>
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="z_urzedu" value="1" id="chk-zurzedu-recznie">
                <label class="form-check-label" for="chk-zurzedu-recznie" style="font-size:.8rem"><i class="bi bi-building me-1"></i>Wystawione z inicjatywy organizacji (z urzędu)</label>
              </div>
              <button class="btn btn-warning w-100 btn-sm"><i class="bi bi-pencil me-1"></i>Wystawiono ręcznie — nadaj numer</button>
            </form>
          </div>
        </div>

        <hr class="my-1">

        <form method="post" onsubmit="return document.getElementById('odrzuc-powod').value.trim()!==''||confirm('Odrzucić bez podania powodu?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="odrzuc">
          <div class="mb-2">
            <label class="form-label fw-semibold mb-1" style="font-size:.74rem">Powód odrzucenia (opcjonalny)</label>
            <textarea id="odrzuc-powod" name="odrzucone_powod" class="form-control form-control-sm" rows="2"></textarea>
          </div>
          <button class="btn btn-outline-danger w-100 btn-sm"><i class="bi bi-x-circle me-1"></i>Odrzuć wniosek</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- Podgląd szablonu (przed wydaniem) -->
    <?php if(!$issued && $can_mgr && $zas['szablon_tresc']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-eye me-1 text-muted"></i>Podgląd po podstawieniu</div>
      <div class="card-body p-3" style="font-size:.77rem;max-height:280px;overflow-y:auto">
        <div style="white-space:pre-wrap;line-height:1.7"><?= ezd_zas_render($zas['szablon_tresc'], $zas['dane'], ['nr_zaswiadczenia' => '(numer zostanie nadany)']) ?></div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Status history placeholder -->
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-clock-history me-1 text-muted"></i>Oś czasu</div>
      <div class="card-body p-3" style="font-size:.78rem">
        <div class="d-flex gap-2 mb-2">
          <div style="width:8px;height:8px;border-radius:50%;background:#2563eb;margin-top:.3rem;flex-shrink:0"></div>
          <div><span class="fw-semibold">Złożono wniosek</span><br><span class="text-muted"><?= date_pl(substr($zas['created_at'],0,10)) ?> — <?= h($zas['created_by_name']??'—') ?></span></div>
        </div>
        <?php if(in_array($st,['weryfikacja','wydane','odrzucone'],true)): ?>
        <div class="d-flex gap-2 mb-2">
          <div style="width:8px;height:8px;border-radius:50%;background:<?= $st==='odrzucone'?'#dc2626':'#d97706' ?>;margin-top:.3rem;flex-shrink:0"></div>
          <div>
            <span class="fw-semibold"><?= $st==='odrzucone'?'Odrzucono':($st==='wydane'?'Wydano':'Przyjęto do weryfikacji') ?></span>
            <?php if($zas['zatwierdzone_at']): ?><br><span class="text-muted"><?= date_pl(substr($zas['zatwierdzone_at'],0,10)) ?> — <?= h($zas['zatw_name']??'—') ?></span><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if($issued): ?>
        <div class="d-flex gap-2">
          <div style="width:8px;height:8px;border-radius:50%;background:#16a34a;margin-top:.3rem;flex-shrink:0"></div>
          <div><span class="fw-semibold text-success">Zaświadczenie wydane</span><br><span class="font-monospace text-primary"><?= h($zas['nr_zaswiadczenia']) ?></span></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if($can_mgr && $open && $zas['szablon_tresc']): ?>
<script src="https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js" referrerpolicy="origin"></script>
<script>
const _tinyCommon = {
  license_key: 'gpl', menubar: false, branding: false, promotion: false,
  entity_encoding: 'raw',
  content_style: 'body { font-family: system-ui, sans-serif; font-size: 13px; line-height: 1.55; padding: 4px 8px; }',
};
tinymce.init({..._tinyCommon, selector: '#tresc-override-editor', toolbar: false, statusbar: false, height: 220});
tinymce.init({..._tinyCommon, selector: '#tinymce-tresc',
  toolbar: 'bold italic underline | bullist numlist | removeformat',
  statusbar: false, height: 340,
});
document.getElementById('form-wydaj-szablon').addEventListener('submit', function() {
  if (typeof tinymce !== 'undefined') tinymce.triggerSave();
});
document.querySelectorAll('#tresc-edit form').forEach(function(f) {
  f.addEventListener('submit', function() {
    if (typeof tinymce !== 'undefined') tinymce.triggerSave();
  });
});
</script>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

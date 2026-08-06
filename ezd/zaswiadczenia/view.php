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
        $res = ezd_zas_wydaj($id, $user_id, $sprawa_id_override);
        flash_set($res['ok'] ? 'success' : 'error', $res['ok'] ? 'Zaświadczenie ' . h($res['nr']) . ' wydane.' : $res['error']);
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
          <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Drukuj</button>
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
          <?php if($zas['odrzucone_powod']): ?>
          <dt class="col-sm-3 text-muted fw-normal">Powód odrzucenia</dt>
          <dd class="col-sm-9 text-danger"><?= h($zas['odrzucone_powod']) ?></dd>
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
      <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-file-text me-1 text-primary"></i>Treść zaświadczenia</div>
      <div class="card-body">
        <div class="zas-tresc"><?= $zas['tresc_html'] ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Prawa: akcje -->
  <div class="col-lg-4">
    <?php if($can_mgr && $open): ?>
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

        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="wydaj">
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
            <input type="text" name="sprawa_picker" list="sprawy-list" class="form-control form-control-sm"
                   placeholder="Inna koszulka (opcjonalnie)">
            <?php else: ?>
            <input type="text" name="sprawa_picker" list="sprawy-list" class="form-control form-control-sm"
                   placeholder="Wpisz znak koszulki…" autofocus>
            <div class="text-muted mt-1" style="font-size:.71rem"><i class="bi bi-info-circle me-1"></i>Pismo zostanie wpisane do tej koszulki. Możesz wydać bez koszulki.</div>
            <?php endif; ?>
            <?php if($open_sprawy): ?>
            <datalist id="sprawy-list">
              <?php foreach($open_sprawy as $sp): ?>
              <option value="<?= h($sp['znak_sprawy']) ?>"><?= h(mb_substr($sp['title'],0,60)) ?></option>
              <?php endforeach; ?>
            </datalist>
            <?php endif; ?>
          </div>
          <button class="btn btn-success w-100 btn-sm"><i class="bi bi-award-fill me-1"></i>Wydaj zaświadczenie</button>
        </form>

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

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

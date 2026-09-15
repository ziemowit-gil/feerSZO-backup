<?php
/**
 * Wpis książki nadawczej — szczegóły + obsługa nadania, doręczenia i zwrotu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_rpwy.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$id = (int)($_GET['id'] ?? 0);
$r  = ezd_rpwy_get($id);
if (!$r) { flash_set('error','Wpis nie istnieje.'); header('Location:'.APP_URL.'/ezd/rpwy/index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_edit()) { flash_set('error','Brak uprawnień.'); header('Location:'.APP_URL.'/ezd/rpwy/view.php?id='.$id); exit; }
    $uid = (int)current_user()['id'];
    $act = $_POST['action'] ?? '';
    try {
        if ($act === 'nadaj') {
            ezd_rpwy_set_status($id, 'nadana', [
                'data_wysylki' => $_POST['data_wysylki'] ?? '',
                'nr_nadania'   => $_POST['nr_nadania']   ?? '',
                'koszt'        => $_POST['koszt']        ?? '',
            ], $uid);
            flash_set('success', 'Przesyłkę oznaczono jako nadaną.');
        } elseif ($act === 'doreczono') {
            ezd_rpwy_set_status($id, 'doreczona', ['data_doreczenia' => $_POST['data_doreczenia'] ?? ''], $uid);
            if (!empty($_FILES['epo']['tmp_name'])) {
                $err = ezd_rpwy_epo_upload($id, 'epo', $uid);
                if ($err) flash_set('error', 'Doręczenie zapisano, ale dowodu nie przyjęto: ' . $err);
            }
            flash_set('success', 'Zapisano potwierdzenie doręczenia.');
        } elseif ($act === 'fikcja') {
            ezd_rpwy_set_fikcja($id, $_POST['awizo_date'] ?? '', $uid);
            flash_set('success', 'Przyjęto doręczenie w trybie fikcji doręczenia.');
        } elseif ($act === 'termin_sprawa') {
            $set = ezd_rpwy_apply_termin_do_sprawy($id, $uid);
            flash_set($set ? 'success' : 'info', $set
                ? 'Termin koszulki ustawiono na ' . date_pl($set) . '.'
                : 'Nie zmieniono terminu koszulki — brak koszulki, brak terminu albo koszulka ma już wcześniejszy termin.');
        } elseif ($act === 'zwrot') {
            ezd_rpwy_set_status($id, 'zwrocona', ['zwrot_powod' => $_POST['zwrot_powod'] ?? ''], $uid);
            flash_set('success', 'Zapisano zwrot przesyłki.');
        } elseif ($act === 'anuluj') {
            ezd_rpwy_set_status($id, 'anulowana', [], $uid);
            flash_set('success', 'Wpis oznaczono jako anulowany.');
        } elseif ($act === 'epo') {
            $err = ezd_rpwy_epo_upload($id, 'epo', $uid);
            flash_set($err ? 'error' : 'success', $err ?: 'Dodano dowód doręczenia.');
        } elseif ($act === 'delete') {
            if (!can_delete('ezd')) throw new \RuntimeException('Brak uprawnień do usuwania.');
            ezd_rpwy_delete($id, $uid);
            flash_set('success', 'Wpis usunięty.');
            header('Location:'.APP_URL.'/ezd/rpwy/index.php'); exit;
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location:'.APP_URL.'/ezd/rpwy/view.php?id='.$id); exit;
}

$sp   = EZD_RPWY_SPOSOBY[$r['sposob']] ?? ['label'=>$r['sposob'],'icon'=>'bi-envelope','zpo'=>false];
$trm  = ezd_rpwy_termin($r);
$PAGE_TITLE = ezd_rpwy_label($r);

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.meta-dl dt{font-size:.7rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.1rem}
.meta-dl dd{font-size:.84rem;color:#1e293b;margin-bottom:.75rem}
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/rpwy/index.php">Książka nadawcza</a></li>
  <li class="breadcrumb-item active"><?= h(ezd_rpwy_label($r)) ?></li>
</ol></nav>

<?= flash_html() ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-1 fw-bold font-monospace text-primary"><?= h(ezd_rpwy_label($r)) ?></h4>
    <div><?= ezd_rpwy_status_badge($r['status']) ?>
      <span class="text-muted ms-2" style="font-size:.82rem"><i class="bi <?= $sp['icon'] ?> me-1"></i><?= h($sp['label']) ?></span>
    </div>
  </div>
  <?php if (can_edit() && $r['status'] !== 'anulowana'): ?>
  <a href="<?= APP_URL ?>/ezd/rpwy/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edytuj</a>
  <?php endif; ?>
</div>

<?php if ($trm): ?>
<div class="alert <?= $trm['po_terminie'] ? 'alert-danger' : 'alert-info' ?> py-2" style="font-size:.84rem">
  <i class="bi bi-clock-history me-1"></i>
  Termin <?= (int)$r['termin_dni'] ?> dni od <?= $trm['fikcja'] ? 'doręczenia przyjętego w trybie fikcji' : 'doręczenia' ?> upływa <strong><?= date_pl($trm['do']) ?></strong>
  — <?= $trm['po_terminie'] ? 'termin minął ' . abs($trm['dni_do_konca']) . ' dni temu' : 'pozostało ' . $trm['dni_do_konca'] . ' dni' ?>.
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-person-lines-fill me-1 text-primary"></i>Adresat i przesyłka</div>
      <div class="card-body">
        <dl class="meta-dl mb-0 row">
          <div class="col-sm-6">
            <dt>Odbiorca</dt><dd><?= h($r['odbiorca'] ?: '—') ?></dd>
            <dt>Adres</dt><dd><?= nl2br(h($r['adres'] ?: '—')) ?></dd>
            <?php if($r['ade']): ?><dt>Adres do e-Doręczeń (ADE)</dt><dd class="font-monospace"><?= h($r['ade']) ?></dd><?php endif; ?>
          </div>
          <div class="col-sm-6">
            <dt>Data nadania</dt><dd><?= date_pl($r['data_wysylki']) ?></dd>
            <dt>Numer nadania</dt><dd class="font-monospace"><?php
              if ($r['nr_nadania']) { echo h($r['nr_nadania']); }
              elseif (!empty($r['postivo_job_id'])) { echo '<span class="text-muted fst-italic">Oczekuje na nadanie w UP</span>'; }
              else { echo '—'; }
            ?></dd>
            <dt>Liczba przesyłek</dt><dd><?= (int)$r['liczba_szt'] ?></dd>
            <dt>Opłata</dt><dd><?= $r['koszt'] > 0 ? number_format((float)$r['koszt'], 2, ',', ' ') . ' zł' : '—' ?></dd>
          </div>
        </dl>
        <?php if($r['uwagi']): ?>
        <div class="border-top pt-2 mt-1" style="font-size:.84rem"><span class="text-muted">Uwagi:</span> <?= nl2br(h($r['uwagi'])) ?></div>
        <?php endif; ?>
        <?php if($r['zwrot_powod']): ?>
        <div class="alert alert-danger py-2 mb-0 mt-2" style="font-size:.82rem"><i class="bi bi-arrow-counterclockwise me-1"></i><strong>Zwrot:</strong> <?= h($r['zwrot_powod']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($r['postivo_job_id'])): ?>
    <!-- Szczegóły z Postivo.pl -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold d-flex align-items-center gap-2" style="font-size:.82rem">
        <i class="bi bi-send-fill text-primary"></i>Postivo.pl
        <button type="button" class="btn btn-sm btn-outline-primary ms-auto" data-bs-toggle="modal" data-bs-target="#postivoHistModal">
          <i class="bi bi-clock-history me-1"></i>Śledź historię
        </button>
      </div>
      <div class="card-body">
        <dl class="meta-dl mb-0 row">
          <div class="col-sm-6">
            <dt>Operator pocztowy</dt><dd><?= h($r['postivo_operator'] ?: '—') ?></dd>
            <dt>Typ przesyłki</dt><dd><?= h($r['postivo_service_name'] ?: '—') ?></dd>
            <dt>Liczba stron</dt><dd><?= (int)$r['postivo_pages'] ?: '—' ?></dd>
          </div>
          <div class="col-sm-6">
            <dt>Numer zlecenia (Postivo)</dt><dd class="font-monospace"><?= h($r['postivo_job_id']) ?></dd>
            <dt>Aktualny status</dt><dd><?= h($r['postivo_status_name'] ?: '—') ?></dd>
            <dt>Planowana data nadania</dt><dd><?= $r['postivo_dispatch_date'] ? date_pl($r['postivo_dispatch_date']) : '—' ?></dd>
          </div>
        </dl>
        <?php if($r['nadanie_file']): ?>
        <div class="border-top pt-2 mt-2">
          <a href="<?= APP_URL ?>/ezd/rpwy/nadanie.php?id=<?= $id ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-file-earmark-check me-1"></i>Poświadczenie nadania
            <span class="text-muted ms-1">(<?= number_format($r['nadanie_size']/1024, 0, ',', ' ') ?> KB)</span>
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Modal: historia statusów (na żywo z Postivo.pl) -->
    <div class="modal fade" id="postivoHistModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h2 class="modal-title h6"><i class="bi bi-clock-history me-2"></i>Historia statusów — Postivo.pl</h2>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body">
            <div id="ph-summary" class="mb-2" style="font-size:.85rem">
              <strong>Aktualny status:</strong> <span id="ph-status"><?= h($r['postivo_status_name'] ?: '—') ?></span>
            </div>
            <ul id="ph-events" class="list-unstyled mb-0" style="font-size:.8rem">
              <?php
              $events = array_reverse(json_decode((string)($r['postivo_events_json'] ?? '[]'), true) ?: []);
              foreach ($events as $ev):
              ?>
              <li class="mb-1"><span class="text-muted font-monospace" style="font-size:.74rem"><?= h($ev['date'] ?? '—') ?></span> — <?= h($ev['name'] ?? ($ev['code'] ?? '')) ?></li>
              <?php endforeach; ?>
              <?php if (!$events): ?><li class="text-muted">Brak historii — spróbuj odświeżyć.</li><?php endif; ?>
            </ul>
          </div>
          <div class="modal-footer">
            <span id="ph-err" class="text-danger small me-auto"></span>
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
            <button type="button" id="ph-refresh" class="btn btn-primary"><i class="bi bi-arrow-clockwise me-1"></i>Odśwież teraz</button>
          </div>
        </div>
      </div>
    </div>
    <script>
    (function () {
        var btn = document.getElementById('ph-refresh');
        if (!btn) return;
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = 'Odświeżam…';
            var err = document.getElementById('ph-err');
            err.textContent = '';
            var fd = new FormData();
            fd.append('_csrf', '<?= csrf_token() ?>');
            fd.append('id', <?= (int)$id ?>);
            fetch('<?= APP_URL ?>/ezd/rpwy/postivo_refresh.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function (data) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Odśwież teraz';
                    if (!data.ok) { err.textContent = data.error || 'Błąd odświeżania.'; return; }
                    document.getElementById('ph-status').textContent = data.status_label + (data.status_name ? ' (' + data.status_name + ')' : '');
                    var list = document.getElementById('ph-events');
                    list.innerHTML = data.events.length
                        ? data.events.map(ev => '<li class="mb-1"><span class="text-muted font-monospace" style="font-size:.74rem">' + (ev.date || '—') + '</span> — ' + (ev.name || ev.code || '') + '</li>').join('')
                        : '<li class="text-muted">Brak historii.</li>';
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Odśwież teraz';
                    err.textContent = 'Błąd sieci.';
                });
        });
    })();
    </script>
    <?php endif; ?>

    <!-- Dowód doręczenia -->
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-patch-check me-1 text-success"></i>Potwierdzenie odbioru</div>
      <div class="card-body">
        <?php if($r['data_doreczenia']): $fik = ($r['doreczenie_typ'] ?? '') === 'fikcja'; ?>
        <div class="mb-2" style="font-size:.86rem">
          <i class="bi <?= $fik ? 'bi-exclamation-circle text-warning' : 'bi-check2-circle text-success' ?> me-1"></i>
          <?= $fik ? 'Uznano za doręczone' : 'Doręczono' ?> <strong><?= date_pl($r['data_doreczenia']) ?></strong>
        </div>
        <?php if($fik): ?>
        <div class="alert alert-warning py-2 mb-2" style="font-size:.78rem">
          <strong>Fikcja doręczenia.</strong> Przesyłki nie odebrano w terminie
          <?= EZD_RPWY_AWIZO_DNI ?> dni od awizowania<?= $r['awizo_date'] ? ' (' . date_pl($r['awizo_date']) . ')' : '' ?>,
          więc doręczenie przyjęto na dzień upływu tego okresu.
        </div>
        <?php endif; ?>
        <?php elseif(!empty($sp['zpo']) || !empty($r['postivo_job_id'])): ?>
        <div class="text-muted mb-2" style="font-size:.82rem"><i class="bi bi-hourglass-split me-1"></i>Oczekuje na potwierdzenie odbioru<?= !empty($r['postivo_job_id']) ? ' — status śledzony automatycznie przez Postivo.pl' : '' ?>.</div>
        <?php else: ?>
        <div class="text-muted mb-2" style="font-size:.82rem">Ten sposób wysyłki nie przewiduje potwierdzenia odbioru.</div>
        <?php endif; ?>

        <?php if($r['epo_file']): ?>
        <a href="<?= APP_URL ?>/ezd/rpwy/epo.php?id=<?= $id ?>" target="_blank" rel="noopener" class="btn btn-outline-success btn-sm">
          <i class="bi bi-file-earmark-check me-1"></i><?= h($r['epo_name']) ?>
          <span class="text-muted ms-1">(<?= number_format($r['epo_size']/1024, 0, ',', ' ') ?> KB)</span>
        </a>
        <?php elseif(can_edit()): ?>
        <form method="post" enctype="multipart/form-data" class="row g-2 align-items-end">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="epo">
          <div class="col-sm-8">
            <label class="form-label mb-1" style="font-size:.74rem" for="epo-file">Skan ZPO / dowód z e-Doręczeń</label>
            <input type="file" name="epo" id="epo-file" class="form-control form-control-sm" accept=".pdf,.png,.jpg,.jpeg,.xml,.txt" required>
          </div>
          <div class="col-sm-4">
            <button class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-upload me-1"></i>Dołącz</button>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <!-- Powiązania -->
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-link-45deg me-1 text-primary"></i>Powiązania</div>
      <div class="card-body" style="font-size:.84rem">
        <?php if($r['pismo_id']): ?>
        <div class="mb-2">
          <div class="text-muted" style="font-size:.72rem">PISMO</div>
          <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= (int)$r['pismo_id'] ?>" class="font-monospace text-decoration-none fw-semibold"><?= h($r['pismo_sygnatura']) ?></a>
          <div class="text-muted" style="font-size:.78rem"><?= h($r['pismo_title']) ?></div>
        </div>
        <?php endif; ?>
        <?php if($r['sprawa_id']): ?>
        <div class="mb-2">
          <div class="text-muted" style="font-size:.72rem">KOSZULKA</div>
          <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$r['sprawa_id'] ?>" class="font-monospace text-decoration-none fw-semibold"><?= h($r['znak_sprawy']) ?></a>
          <div class="text-muted" style="font-size:.78rem"><?= h($r['sprawa_title']) ?></div>
        </div>
        <?php endif; ?>
        <?php if(!$r['pismo_id'] && !$r['sprawa_id']): ?>
        <div class="text-muted">Przesyłka nie jest powiązana z pismem w EZD.</div>
        <?php endif; ?>
        <div class="border-top pt-2 mt-2 text-muted" style="font-size:.74rem">
          Zarejestrował: <?= h($r['creator_name'] ?: '—') ?> · <?= date('d.m.Y H:i', strtotime($r['created_at'])) ?>
        </div>
      </div>
    </div>

    <!-- Akcje -->
    <?php if(can_edit() && $r['status'] !== 'anulowana'): ?>
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-lightning-charge me-1 text-warning"></i>Akcje</div>
      <div class="card-body d-grid gap-2">

        <?php if($r['status'] === 'przygotowana'): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#act-nadaj">
          <i class="bi bi-send me-1"></i>Oznacz jako nadaną
        </button>
        <div class="collapse" id="act-nadaj">
          <form method="post" class="border rounded-3 p-2">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="nadaj">
            <label class="form-label mb-1" style="font-size:.74rem" for="n-data">Data nadania</label>
            <input type="date" name="data_wysylki" id="n-data" class="form-control form-control-sm mb-2" value="<?= h($r['data_wysylki'] ?: date('Y-m-d')) ?>">
            <label class="form-label mb-1" style="font-size:.74rem" for="n-nr">Numer nadania</label>
            <input type="text" name="nr_nadania" id="n-nr" class="form-control form-control-sm mb-2 font-monospace" value="<?= h($r['nr_nadania']) ?>">
            <label class="form-label mb-1" style="font-size:.74rem" for="n-koszt">Opłata (zł)</label>
            <input type="text" name="koszt" id="n-koszt" class="form-control form-control-sm mb-2" value="<?= $r['koszt'] > 0 ? h(number_format((float)$r['koszt'], 2, ',', '')) : '' ?>" inputmode="decimal">
            <button class="btn btn-primary btn-sm w-100">Zapisz nadanie</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if(in_array($r['status'], ['nadana'], true)): ?>
        <button class="btn btn-success btn-sm" data-bs-toggle="collapse" data-bs-target="#act-dor">
          <i class="bi bi-check2-circle me-1"></i>Potwierdź doręczenie
        </button>
        <div class="collapse" id="act-dor">
          <form method="post" enctype="multipart/form-data" class="border rounded-3 p-2">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="doreczono">
            <label class="form-label mb-1" style="font-size:.74rem" for="d-data">Data doręczenia</label>
            <input type="date" name="data_doreczenia" id="d-data" class="form-control form-control-sm mb-2" value="<?= date('Y-m-d') ?>">
            <label class="form-label mb-1" style="font-size:.74rem" for="d-epo">Dowód (ZPO / e-Doręczenia)</label>
            <input type="file" name="epo" id="d-epo" class="form-control form-control-sm mb-2" accept=".pdf,.png,.jpg,.jpeg,.xml,.txt">
            <button class="btn btn-success btn-sm w-100">Zapisz doręczenie</button>
          </form>
        </div>

        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="collapse" data-bs-target="#act-zwrot">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Zarejestruj zwrot
        </button>
        <div class="collapse" id="act-zwrot">
          <form method="post" class="border rounded-3 p-2">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="zwrot">
            <label class="form-label mb-1" style="font-size:.74rem" for="z-powod">Powód zwrotu</label>
            <input type="text" name="zwrot_powod" id="z-powod" class="form-control form-control-sm mb-2" placeholder="np. nie podjęto w terminie, adresat nieznany">
            <button class="btn btn-outline-danger btn-sm w-100">Zapisz zwrot</button>
          </form>
        </div>
        <?php endif; ?>

        <?php if(!$r['data_doreczenia'] && !empty($sp['zpo']) && in_array($r['status'], ['nadana','zwrocona'], true)): ?>
        <button class="btn btn-outline-warning btn-sm" data-bs-toggle="collapse" data-bs-target="#act-fikcja">
          <i class="bi bi-exclamation-circle me-1"></i>Fikcja doręczenia
        </button>
        <div class="collapse" id="act-fikcja">
          <form method="post" class="border rounded-3 p-2">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="fikcja">
            <label class="form-label mb-1" style="font-size:.74rem" for="f-awizo">Data awizowania</label>
            <input type="date" name="awizo_date" id="f-awizo" class="form-control form-control-sm mb-1" max="<?= date('Y-m-d') ?>" required>
            <div class="text-muted mb-2" style="font-size:.72rem">
              Doręczenie zostanie przyjęte na dzień upływu <?= EZD_RPWY_AWIZO_DNI ?> dni od awizowania.
            </div>
            <button class="btn btn-outline-warning btn-sm w-100">Przyjmij doręczenie</button>
          </form>
        </div>

        <?php endif; ?>

        <?php if($trm && $r['sprawa_id']): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="termin_sprawa">
          <button class="btn btn-outline-primary btn-sm w-100">
            <i class="bi bi-calendar-check me-1"></i>Ustaw termin koszulki na <?= date_pl($trm['do']) ?>
          </button>
        </form>
        <?php endif; ?>

        <form method="post" onsubmit="return confirm('Oznaczyć wpis jako anulowany? Numer pozostanie w rejestrze.')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="anuluj">
          <button class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-x-circle me-1"></i>Anuluj wpis</button>
        </form>

        <?php if(can_delete('ezd') && $r['status'] === 'przygotowana'): ?>
        <form method="post" onsubmit="return confirm('Usunąć wpis bezpowrotnie?')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn btn-link btn-sm text-danger"><i class="bi bi-trash me-1"></i>Usuń wpis</button>
        </form>
        <?php endif; ?>

      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

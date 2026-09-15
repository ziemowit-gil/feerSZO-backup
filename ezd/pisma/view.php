<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_kopia.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_zal_menu.php';
require_once dirname(dirname(__DIR__)) . '/includes/postivo.php';
require_once dirname(dirname(__DIR__)) . '/includes/mail_queue.php';
require_login(); require_module_enabled('ezd_enabled','Moduł EZD Wirtualne biurko'); ezd_require_access();

$id           = (int)($_GET['id'] ?? 0);
$from_sprawa  = (int)($_GET['from_sprawa'] ?? 0);
$pismo        = ezd_pismo_get($id);
if (!$pismo) { flash_set('error','Pismo nie istnieje.'); header('Location:'.APP_URL.'/ezd/sprawy/index.php'); exit; }

$sprawa_id = (int)$pismo['sprawa_id'];
$_sprawa_pisma = ezd_sprawa_get($sprawa_id);
$_access = $_sprawa_pisma ? ezd_sprawa_access($_sprawa_pisma, (int)current_user()['id']) : null;
if (!$_access) { flash_set('error','Brak dostępu do tej sprawy.'); header('Location:'.APP_URL.'/ezd/index.php'); exit; }
$can_act = $_access === 'write';
$PAGE_TITLE = $pismo['sygnatura'];

// Integracja z korespondencją
$linked_corr = null;
$corr_enabled = module_enabled('correspondence_enabled');
if ($corr_enabled) {
    require_once dirname(dirname(__DIR__)) . '/includes/correspondence.php';
    $linked_corr = ezd_get_linked_corr($id);
}
$zal  = ezd_zalaczniki_by($sprawa_id, $id);
$user_id = (int)current_user()['id'];

// Tylko PDF-y z podpisem elektronicznym — wymagane do wysyłki mailem
$signed_pdfs = [];
foreach ($zal as $z) {
    if (strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION)) !== 'pdf') continue;
    $fp = UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/' . $z['filename'];
    if (is_file($fp) && ezd_signature_info($fp, $z['original_name'])['signed']) {
        $signed_pdfs[] = $z;
    }
}

// POST: upload, del_file, send_email_pismo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'upload' && $can_act) {
        $err = ezd_upload('file', $sprawa_id, $user_id, $id);
        flash_set($err ? 'error' : 'success', $err ?? 'Plik dodany.');
    }
    if ($action === 'del_file' && $can_act) {
        $zid = (int)($_POST['zid'] ?? 0);
        ezd_zal_delete($zid, $user_id);
        flash_set('success','Plik usunięty.');
    }
    if ($action === 'send_email_pismo' && $can_act && $signed_pdfs) {
        $recipient = trim($_POST['recipient_email'] ?? '');
        $subject   = trim($_POST['mail_subject']    ?? '');
        $body_raw  = trim($_POST['mail_body']       ?? '');
        $cc_list   = mail_parse_addr_list($_POST['cc_emails']  ?? '');
        $bcc_list  = mail_parse_addr_list($_POST['bcc_emails'] ?? '');
        $zids      = array_filter(array_map('intval', (array)($_POST['zal_ids'] ?? [])));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Podaj prawidłowy adres e-mail odbiorcy.');
        } elseif (!$zids) {
            flash_set('error', 'Wybierz co najmniej jeden plik do wysłania.');
        } else {
            // Buduj listę wyłącznie z wcześniej wykrytych podpisanych PDF-ów
            $attachments = [];
            foreach ($signed_pdfs as $z) {
                if (!in_array((int)$z['id'], $zids, true)) continue;
                $attachments[] = [
                    'path' => EZD_UPLOAD_SUBDIR . $sprawa_id . '/' . $z['filename'],
                    'name' => $z['original_name'],
                    'mime' => 'application/pdf',
                    'size' => (int)$z['file_size'],
                ];
            }
            if (!$attachments) {
                flash_set('error', 'Nie znaleziono podpisanych plików PDF do wysłania.');
            } else {
                $subj      = $subject ?: $pismo['sygnatura'] . ' — ' . $pismo['title'];
                $body_html = $body_raw ? nl2br(htmlspecialchars($body_raw, ENT_QUOTES, 'UTF-8')) : '';
                mail_queue_add($recipient, $recipient, $subj,
                    $body_html, $body_raw, 'ezd_pismo', $id, '', false, $attachments, '', $cc_list, $bcc_list);
                ezd_log(null, $sprawa_id, $id, null, $user_id, 'pismo_email_sent',
                    'Wysłano mailem do: ' . $recipient
                    . ($cc_list  ? '; DW: '  . implode(', ', $cc_list)  : '')
                    . ($bcc_list ? '; UDW: ' . implode(', ', $bcc_list) : '')
                    . '; pliki: ' . implode(', ', array_column($attachments, 'name')));
                $med_upd = $pismo['rodzaj_medium'] !== 'email' ? ",rodzaj_medium='email'" : '';
                if (!$pismo['data_wysylki']) {
                    db()->prepare("UPDATE ezd_pisma SET data_wysylki=date('now')$med_upd,updated_at=datetime('now') WHERE id=?")->execute([$id]);
                } elseif ($pismo['rodzaj_medium'] !== 'email') {
                    db()->prepare("UPDATE ezd_pisma SET rodzaj_medium='email',updated_at=datetime('now') WHERE id=?")->execute([$id]);
                }
                $cc_info = $cc_list ? ' (DW: ' . h(implode(', ', $cc_list)) . ')' : '';
                flash_set('success', 'Wiadomość e-mail wysłana na adres ' . h($recipient) . $cc_info . '.');
            }
        }
    }
    $fs = $from_sprawa ? '&from_sprawa=' . $from_sprawa : '';
    header('Location:'.APP_URL.'/ezd/pisma/view.php?id='.$id.$fs); exit;
}

$kier = EZD_KIERUNKI[$pismo['kierunek']] ?? ['label'=>$pismo['kierunek'],'icon'=>'bi-envelope','class'=>'secondary'];

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.meta-dl dt{font-size:.7rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.1rem}
.meta-dl dd{font-size:.84rem;color:#1e293b;margin-bottom:.75rem}
.zal-row{display:flex;align-items:center;gap:.6rem;padding:.5rem .75rem;border-bottom:1px solid #f1f5f9;font-size:.8rem}
.zal-row:last-child{border-bottom:none}
</style>

<?php if ($from_sprawa && $from_sprawa === $sprawa_id): ?>
<a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>#tab-pisma"
   class="btn btn-sm btn-outline-secondary mb-3"><i class="bi bi-arrow-left me-1"></i>Wróć do koszulki <?= h($pismo['znak_sprawy']) ?></a>
<?php endif; ?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($pismo['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item active"><?= h($pismo['sygnatura']) ?></li>
</ol></nav>

<?= flash_html() ?>

<div class="row g-4">
  <!-- Lewa: treść + załączniki -->
  <div class="col-lg-8">
    <!-- Nagłówek pisma -->
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
          <div>
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="badge bg-<?= $kier['class'] ?> bg-opacity-15 text-<?= $kier['class'] ?> border border-<?= $kier['class'] ?>">
                <i class="bi <?= $kier['icon'] ?> me-1"></i><?= h($kier['label']) ?>
              </span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary border font-monospace" style="font-size:.72rem"><?= h($pismo['sygnatura']) ?></span>
              <span class="badge bg-light text-dark border" style="font-size:.68rem"><?= h($pismo['status']) ?></span>
              <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.68rem"><i class="bi <?= EZD_MEDIA[$pismo['rodzaj_medium']]['icon'] ?? 'bi-question-circle' ?> me-1"></i><?= h(EZD_MEDIA[$pismo['rodzaj_medium']]['label'] ?? $pismo['rodzaj_medium']) ?></span>
            </div>
            <h5 class="fw-bold mb-0"><?= h($pismo['title']) ?></h5>
          </div>
          <div class="d-flex gap-2 flex-shrink-0 flex-wrap">
            <a href="<?= APP_URL ?>/ezd/pisma/wersje.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
              <i class="bi bi-clock-history me-1"></i>Historia
            </a>
            <?php ezd_kopia_menu_btn('pismo', $id, $pismo['sygnatura']); ?>
            <script>
            (function(){
              // Potwierdzenie przed wydrukiem pisma — scoped tylko do TEGO menu (nie ezd_kopia_menu_btn
              // w umowach/zaświadczeniach/dokumentach), z fazą capture, żeby zadziałać PRZED globalnym
              // listenerem modala podglądu PDF (includes/ezd_pdf_modal.php), który jest na bubble.
              var box = document.getElementById('kppismo<?= (int)$id ?>');
              if (!box) return;
              box.addEventListener('click', function(e){
                var link = e.target.closest('.ezd-pdf-btn');
                if (!link) return;
                if (!confirm('Czy na pewno chcesz wydrukować ten dokument?')) {
                  e.preventDefault(); e.stopPropagation(); e.stopImmediatePropagation();
                }
              }, true);
            })();
            </script>
            <?php if($pismo['tresc'] || $pismo['title']): ?>
            <a href="<?= APP_URL ?>/ezd/pisma/docx.php?id=<?= $id ?>"
               class="btn btn-outline-primary btn-sm" title="Pobierz pismo jako DOCX">
              <i class="bi bi-file-earmark-word me-1"></i>DOCX
            </a>
            <?php endif; ?>
            <?php if($can_act && $pismo['sprawa_status'] !== 'closed'): ?>
            <?php if($signed_pdfs): ?>
            <button type="button" class="btn btn-outline-success btn-sm"
                    data-bs-toggle="modal" data-bs-target="#pismoEmailModal">
              <i class="bi bi-send me-1"></i>Wyślij mailem
            </button>
            <?php endif; ?>
            <a href="<?= APP_URL ?>/ezd/pisma/edit.php?id=<?= $id ?><?= $from_sprawa ? '&from_sprawa='.$from_sprawa : '' ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Edytuj</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Treść / notatka -->
    <?php if($pismo['tresc']): ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-body-text me-1 text-primary"></i>Treść / notatka</div>
      <div class="card-body" style="font-size:.88rem;white-space:pre-wrap"><?= h($pismo['tresc']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Załączniki -->
    <div class="card shadow-sm">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold" style="font-size:.82rem"><i class="bi bi-paperclip me-1 text-primary"></i>Załączniki (<?= count($zal) ?>)</span>
      </div>
      <?php if($zal): ?>
      <div>
        <?php foreach($zal as $z): ?>
        <?php $zext = strtolower(pathinfo($z['original_name'], PATHINFO_EXTENSION)); ?>
        <div class="zal-row">
          <i class="bi <?= ezd_file_icon($z['original_name']) ?> fs-5"></i>
          <div class="flex-grow-1 overflow-hidden">
            <a href="<?= APP_URL ?>/ezd/serve.php?id=<?= $z['id'] ?>" target="_blank" class="text-decoration-none fw-semibold text-truncate d-block" style="font-size:.82rem"><?= h($z['original_name']) ?></a>
            <div class="text-muted" style="font-size:.7rem"><?= ezd_filesize($z['file_size']) ?> · v<?= $z['wersja'] ?> · <?= h($z['uploader']??'—') ?> · <?= date('d.m.Y H:i',strtotime($z['uploaded_at'])) ?></div>
          </div>
          <?php
            $_sigp = ['signed' => false];
            if (in_array($zext, EZD_SIG_EXTS, true)) {
                $_sigp = ezd_signature_info(UPLOAD_DIR . EZD_UPLOAD_SUBDIR . $sprawa_id . '/' . $z['filename'], $z['original_name']);
            }
            ezd_zal_menu($z, [
              'can_act'   => $can_act && $pismo['sprawa_status'] !== 'closed',
              'sprawa_id' => $sprawa_id,
              'sig'       => $_sigp,
              'del_field' => 'zid',
              'allow'     => ['rsign','email_preview','obiegi'],
            ]);
          ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if($can_act && $pismo['sprawa_status']!=='closed'): ?>
      <div class="card-footer">
        <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="upload">
          <input type="file" name="file" class="form-control form-control-sm" required>
          <button type="submit" class="btn btn-sm btn-outline-primary text-nowrap"><i class="bi bi-upload me-1"></i>Dodaj</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Prawa: metadane -->
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-info-circle me-1 text-primary"></i>Metadane</div>
      <div class="card-body">
        <dl class="meta-dl mb-0">
          <dt>Sprawa</dt>
          <dd><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>" class="font-monospace text-decoration-none"><?= h($pismo['znak_sprawy']) ?></a></dd>
          <?php if($pismo['nadawca']): ?>
          <dt>Nadawca</dt><dd><?= h($pismo['nadawca']) ?></dd>
          <?php endif; ?>
          <?php if($pismo['odbiorca']): ?>
          <dt>Odbiorca</dt><dd><?= h($pismo['odbiorca']) ?></dd>
          <?php endif; ?>
          <?php if($pismo['data_pisma']): ?>
          <dt>Data pisma</dt><dd><?= date_pl($pismo['data_pisma']) ?></dd>
          <?php endif; ?>
          <?php if($pismo['data_wplywu']): ?>
          <dt>Data wpływu</dt><dd><?= date_pl($pismo['data_wplywu']) ?></dd>
          <?php endif; ?>
          <?php if($pismo['data_wysylki']): ?>
          <dt>Data wysyłki</dt><dd><?= date_pl($pismo['data_wysylki']) ?></dd>
          <?php endif; ?>
          <dt>Referent</dt><dd><?= h($pismo['owner_name']??'—') ?></dd>
          <dt>Dodane przez</dt><dd><?= h($pismo['creator_name']??'—') ?></dd>
          <dt>Dodane</dt><dd><?= date('d.m.Y H:i',strtotime($pismo['created_at'])) ?></dd>
        </dl>
      </div>
    </div>

    <!-- Książka nadawcza (tylko pisma wychodzące) -->
    <?php if ($pismo['kierunek'] === 'wychodzace'):
      $rpwy = ezd_rpwy_for_pismo($id);
      $rpwy_sp = $rpwy ? (EZD_RPWY_SPOSOBY[$rpwy['sposob']] ?? ['label'=>$rpwy['sposob'],'icon'=>'bi-envelope']) : null; ?>
    <div class="card shadow-sm mb-3 <?= $rpwy ? 'border-primary' : '' ?>">
      <div class="card-header fw-semibold" style="font-size:.82rem">
        <i class="bi bi-send me-1 text-<?= $rpwy ? 'primary' : 'secondary' ?>"></i>Książka nadawcza
        <?php if ($rpwy): ?>
        <span class="badge bg-primary ms-1" style="font-size:.6rem"><?= h(ezd_rpwy_label($rpwy)) ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body" style="font-size:.82rem">
        <?php if ($rpwy): ?>
        <div class="mb-2">
          <?= ezd_rpwy_status_badge($rpwy['status']) ?>
          <span class="text-muted ms-1"><i class="bi <?= $rpwy_sp['icon'] ?> me-1"></i><?= h($rpwy_sp['label']) ?></span>
        </div>
        <div class="text-muted mb-1" style="font-size:.78rem">
          Nadano: <?= date_pl($rpwy['data_wysylki']) ?>
          <?php if($rpwy['nr_nadania']): ?><br>Nr nadania: <span class="font-monospace"><?= h($rpwy['nr_nadania']) ?></span><?php endif; ?>
          <?php if($rpwy['data_doreczenia']): ?><br><i class="bi bi-check2-circle text-success me-1"></i>Doręczono: <?= date_pl($rpwy['data_doreczenia']) ?><?php endif; ?>
        </div>
        <a href="<?= APP_URL ?>/ezd/rpwy/view.php?id=<?= (int)$rpwy['id'] ?>" class="btn btn-outline-primary btn-sm w-100 mt-1">
          <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz wpis
        </a>
        <?php else: ?>
        <div class="text-muted" style="font-size:.78rem">Pismo nie ma wpisu w książce nadawczej.</div>
        <?php if(can_edit()): ?>
        <a href="<?= APP_URL ?>/ezd/rpwy/add.php?pismo_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm w-100 mt-2">
          <i class="bi bi-plus me-1"></i>Zarejestruj wysyłkę
        </a>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Postivo.pl — wysyłka fizyczna (tylko wychodzące + Postivo włączone) -->
    <?php if ($pismo['kierunek'] === 'wychodzace' && postivo_setting('postivo_enabled') === '1' && (new PostivoClient())->is_configured()):
      $p_job_id = $pismo['postivo_job_id'] ?? '';
      $p_status = $pismo['postivo_status'] ?? '';
      $p_sent   = $pismo['postivo_sent_at'] ?? '';
      $p_sent_label = match($p_status) {
          'draft'      => ['Przygotowywane', 'secondary'],
          'processing' => ['W realizacji',   'info'],
          'sent'       => ['Wysłane',        'primary'],
          'delivered'  => ['Doręczone',      'success'],
          'failed'     => ['Błąd',           'danger'],
          'cancelled'  => ['Anulowane',      'secondary'],
          default      => [$p_status ?: '—', 'secondary'],
      };
      // PDF w załącznikach (do wysyłki)
      $pdf_zal_exists = (bool)db_one(
          "SELECT 1 FROM ezd_zalaczniki WHERE pismo_id=? AND mime_type='application/pdf'",
          [$id]
      );
      // Inne pisma tej samej sprawy, które da się dołączyć do TEJ SAMEJ przesyłki
      // (jedna koperta, jedno zlecenie Postivo) — jeszcze nienadane, z własnym PDF.
      $postivo_companions = db_all(
          "SELECT DISTINCT p.id, p.sygnatura, p.title FROM ezd_pisma p
             JOIN ezd_zalaczniki z ON z.pismo_id = p.id AND z.mime_type='application/pdf'
            WHERE p.sprawa_id = ? AND p.kierunek='wychodzace' AND p.id != ?
              AND (p.postivo_job_id IS NULL OR p.postivo_job_id = '')
         ORDER BY p.created_at DESC",
          [$pismo['sprawa_id'], $id]
      );
    ?>
    <div class="card shadow-sm mb-3 <?= $p_job_id ? 'border-primary' : '' ?>">
      <div class="card-header fw-semibold" style="font-size:.82rem">
        <i class="bi bi-mailbox me-1 text-<?= $p_job_id ? 'primary' : 'secondary' ?>"></i>Postivo.pl
        <?php if ($p_job_id): ?>
        <span class="badge bg-<?= $p_sent_label[1] ?> ms-1" style="font-size:.6rem"><?= h($p_sent_label[0]) ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body" style="font-size:.82rem">
        <?php if ($p_job_id): ?>
          <div class="mb-1 text-muted" style="font-size:.75rem">
            ID zlecenia: <span class="font-monospace"><?= h($p_job_id) ?></span>
          </div>
          <?php
            // To samo postivo_job_id na kilku pismach = jedna koperta, jedno zlecenie.
            $p_siblings = db_all(
                "SELECT id, sygnatura, title FROM ezd_pisma WHERE postivo_job_id=? AND id != ?",
                [$p_job_id, $id]
            );
          ?>
          <?php if ($p_siblings): ?>
          <div class="text-muted mb-2" style="font-size:.75rem">
            <i class="bi bi-envelope-paper me-1"></i>Wysłane razem z:
            <?= implode(', ', array_map(
                fn($sib) => '<a href="' . APP_URL . '/ezd/pisma/view.php?id=' . (int)$sib['id'] . '">'
                          . h($sib['sygnatura'] ?: ('#' . $sib['id'])) . '</a>',
                $p_siblings
            )) ?>
          </div>
          <?php endif; ?>
          <?php if ($pismo['postivo_adres'] || $pismo['postivo_miasto']): ?>
          <div class="text-muted mb-2" style="font-size:.75rem">
            <?= h($pismo['postivo_adres'] ?? '') ?><?= ($pismo['postivo_adres'] && $pismo['postivo_miasto']) ? ', ' : '' ?><?= h(($pismo['postivo_kod_pocztowy'] ?? '') . ' ' . ($pismo['postivo_miasto'] ?? '')) ?>
          </div>
          <?php endif; ?>
          <?php if ($p_sent): ?>
          <div class="text-muted mb-2" style="font-size:.75rem">
            Nadano: <?= date('d.m.Y H:i', strtotime($p_sent)) ?>
          </div>
          <?php endif; ?>
          <div class="d-flex gap-2 flex-wrap">
            <?php if ($can_act && $pismo['sprawa_status'] !== 'closed'): ?>
            <form method="post" action="<?= APP_URL ?>/ezd/pisma/postivo_action.php" class="m-0">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="pismo_id" value="<?= $id ?>">
              <input type="hidden" name="postivo_action" value="refresh_status">
              <button type="submit" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Odśwież
              </button>
            </form>
            <?php if (in_array($p_status, ['draft', 'processing', 'unknown'], true)): ?>
            <form method="post" action="<?= APP_URL ?>/ezd/pisma/postivo_action.php" class="m-0"
                  onsubmit="return confirm('Anulować zlecenie Postivo.pl?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="pismo_id" value="<?= $id ?>">
              <input type="hidden" name="postivo_action" value="cancel">
              <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-x-lg me-1"></i>Anuluj
              </button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (in_array($p_status, ['delivered', 'sent'], true)): ?>
            <a href="<?= APP_URL ?>/ezd/pisma/postivo_doc.php?id=<?= $id ?>&type=epo_pdf"
               class="btn btn-outline-success btn-sm" title="Pobierz Elektroniczne Potwierdzenie Odbioru (zwrotka)">
              <i class="bi bi-file-earmark-check me-1"></i>EPO (zwrotka)
            </a>
            <a href="<?= APP_URL ?>/ezd/pisma/postivo_doc.php?id=<?= $id ?>&type=dispatch_cert"
               class="btn btn-outline-secondary btn-sm" title="Certyfikat nadania">
              <i class="bi bi-file-earmark-text me-1"></i>Cert. nadania
            </a>
            <?php endif; ?>
          </div>
        <?php elseif ($can_act && $pismo['sprawa_status'] !== 'closed'): ?>
          <?php if (!$pdf_zal_exists): ?>
          <div class="alert alert-warning py-1 px-2 mb-2" style="font-size:.75rem">
            <i class="bi bi-exclamation-triangle me-1"></i>Brak PDF w załącznikach — wymagany do wysyłki.
          </div>
          <?php else: ?>
          <form method="post" action="<?= APP_URL ?>/ezd/pisma/postivo_action.php" class="mb-0" id="postivo-send-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="pismo_id" value="<?= $id ?>">
            <input type="hidden" name="postivo_action" value="send">
            <div class="mb-2 position-relative">
              <label class="form-label mb-1" style="font-size:.75rem;font-weight:600">Szukaj kontaktu CRM</label>
              <input type="text" id="postivo-crm-q" class="form-control form-control-sm"
                     placeholder="Wpisz imię, nazwisko lub firmę…" autocomplete="off">
              <ul id="postivo-crm-list" class="list-group shadow-sm position-absolute w-100"
                  style="z-index:9999;display:none;max-height:160px;overflow-y:auto;font-size:.78rem;top:100%"></ul>
            </div>
            <div class="mb-2">
              <label class="form-label mb-1" style="font-size:.75rem;font-weight:600">Odbiorca</label>
              <input type="text" name="recipient_name" id="postivo-name" class="form-control form-control-sm"
                     value="<?= h($pismo['odbiorca']) ?>" placeholder="Imię Nazwisko / Firma" required>
            </div>
            <div class="row g-1 mb-1">
              <div class="col-8">
                <input type="text" name="address_line1" id="postivo-street" class="form-control form-control-sm" placeholder="Ulica" required>
              </div>
              <div class="col-2">
                <input type="text" name="home_number" id="postivo-house" class="form-control form-control-sm" placeholder="Nr d.">
              </div>
              <div class="col-2">
                <input type="text" name="flat_number" id="postivo-flat" class="form-control form-control-sm" placeholder="m.">
              </div>
            </div>
            <div class="row g-1 mb-2">
              <div class="col-4">
                <input type="text" name="postcode" id="postivo-postal" class="form-control form-control-sm" placeholder="00-000"
                       pattern="\d{2}-\d{3}" required>
              </div>
              <div class="col-8">
                <input type="text" name="city" id="postivo-city" class="form-control form-control-sm" placeholder="Miasto" required>
              </div>
            </div>
            <div class="mb-1">
              <label class="form-label mb-1" style="font-size:.75rem;font-weight:600"
                     title="Pojawi się na stronie tytułowej listu">Co to jest ten dokument?</label>
              <input type="text" name="doc_title" class="form-control form-control-sm"
                     value="<?= h($pismo['title'] ?? '') ?>"
                     placeholder="np. Zaświadczenie o wolontariacie" required>
            </div>
            <div class="mb-2">
              <label class="form-label mb-1" style="font-size:.75rem;font-weight:600"
                     title="Pojawi się na stronie tytułowej jako wyjaśnienie dla odbiorcy">Dlaczego odbiorca otrzymuje list?</label>
              <input type="text" name="doc_reason" class="form-control form-control-sm"
                     placeholder="np. W związku z zakończeniem okresu wolontariatu…" required>
            </div>
            <?php if ($postivo_companions): ?>
            <div class="mb-2">
              <label class="form-label mb-1" style="font-size:.75rem;font-weight:600"
                     title="Oba pisma pojadą w jednej kopercie jako jedno zlecenie Postivo">
                Dołącz do tej samej przesyłki <span class="text-muted fw-normal">(opcjonalnie)</span>
              </label>
              <select name="companion_pismo_id" class="form-select form-select-sm">
                <option value="">— brak, wyślij osobno —</option>
                <?php foreach ($postivo_companions as $pc): ?>
                <option value="<?= (int)$pc['id'] ?>">
                  <?= h($pc['sygnatura'] ?: ('#' . $pc['id'])) ?> — <?= h(mb_substr((string)$pc['title'], 0, 60)) ?>
                </option>
                <?php endforeach; ?>
              </select>
              <div class="form-text" style="font-size:.7rem">
                Wybrane pismo trafi do tej samej koperty — jedno zlecenie, oba wpisy w rejestrze dostaną ten sam numer.
              </div>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary btn-sm w-100"
                    onclick="return confirm('Wysłać list przez Postivo.pl?')">
              <i class="bi bi-send me-1"></i>Wyślij listem
            </button>
          </form>
          <script>
          (function(){
            const q      = document.getElementById('postivo-crm-q');
            const list   = document.getElementById('postivo-crm-list');
            const f      = { name:   document.getElementById('postivo-name'),
                             street: document.getElementById('postivo-street'),
                             house:  document.getElementById('postivo-house'),
                             flat:   document.getElementById('postivo-flat'),
                             postal: document.getElementById('postivo-postal'),
                             city:   document.getElementById('postivo-city') };
            let timer;
            q.addEventListener('input', () => {
              clearTimeout(timer);
              if (q.value.length < 2) { list.style.display='none'; return; }
              timer = setTimeout(() => {
                fetch('<?= APP_URL ?>/ezd/pisma/postivo_crm_search.php?q=' + encodeURIComponent(q.value))
                  .then(r => r.json()).then(rows => {
                    list.innerHTML = '';
                    if (!rows.length) { list.style.display='none'; return; }
                    rows.forEach(r => {
                      const li = document.createElement('li');
                      li.className = 'list-group-item list-group-item-action py-1 px-2';
                      li.style.cursor = 'pointer';
                      li.textContent = r.label;
                      li.addEventListener('mousedown', e => {
                        e.preventDefault();
                        f.name.value   = r.name;
                        f.street.value = r.street;
                        f.house.value  = r.house;
                        f.flat.value   = r.flat;
                        f.postal.value = r.postal;
                        f.city.value   = r.city;
                        q.value = r.label;
                        list.style.display = 'none';
                      });
                      list.appendChild(li);
                    });
                    list.style.display = 'block';
                  }).catch(() => { list.style.display='none'; });
              }, 280);
            });
            q.addEventListener('blur', () => setTimeout(() => list.style.display='none', 150));
          })();
          </script>
          <?php endif; ?>
        <?php else: ?>
        <div class="text-muted" style="font-size:.78rem">Pismo nie zostało nadane przez Postivo.pl.</div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Powiązana korespondencja -->
    <?php if ($corr_enabled): ?>
    <div class="card shadow-sm <?= $linked_corr ? 'border-success' : '' ?>">
      <div class="card-header fw-semibold" style="font-size:.82rem">
        <i class="bi bi-mailbox2 me-1 text-<?= $linked_corr ? 'success' : 'secondary' ?>"></i>
        Rejestr korespondencji
        <?php if ($linked_corr): ?>
        <span class="badge bg-success ms-1" style="font-size:.6rem">Połączone</span>
        <?php endif; ?>
      </div>
      <div class="card-body" style="font-size:.82rem">
        <?php if ($linked_corr): ?>
        <?php [$dlabel, $dicon, $dcolor] = corr_direction_label($linked_corr['direction']);
              [$slabel, $scolor] = corr_status_label($linked_corr['status']); ?>
        <div class="d-flex align-items-start gap-2 mb-2">
          <i class="bi <?= $dicon ?> text-<?= $dcolor ?> mt-1 flex-shrink-0"></i>
          <div>
            <a href="<?= APP_URL ?>/correspondence/view.php?id=<?= $linked_corr['id'] ?>"
               class="fw-semibold text-decoration-none"><?= h($linked_corr['subject']) ?></a>
            <div class="text-muted" style="font-size:.75rem">
              <?= h($linked_corr['correspondent']) ?> · <?= h(date_pl($linked_corr['date'])) ?>
            </div>
            <span class="badge bg-<?= $scolor ?> bg-opacity-15 text-<?= $scolor ?>" style="font-size:.65rem"><?= $slabel ?></span>
          </div>
        </div>
        <a href="<?= APP_URL ?>/correspondence/view.php?id=<?= $linked_corr['id'] ?>"
           class="btn btn-outline-success btn-sm w-100">
          <i class="bi bi-box-arrow-up-right me-1"></i>Otwórz w Korespondencji
        </a>
        <?php else: ?>
        <div class="text-muted" style="font-size:.78rem">
          Brak powiązanego wpisu w rejestrze korespondencji.
        </div>
        <?php if(can_edit()): ?>
        <a href="<?= APP_URL ?>/correspondence/add.php?from_ezd=<?= $id ?>"
           class="btn btn-outline-secondary btn-sm w-100 mt-2">
          <i class="bi bi-plus me-1"></i>Utwórz w Korespondencji
        </a>
        <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/ezd_email_modal.php'; ?>

<?php if($can_act && $signed_pdfs && $pismo['sprawa_status'] !== 'closed'): ?>
<div class="modal fade" id="pismoEmailModal" tabindex="-1" aria-labelledby="pismoEmailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <form method="post" class="modal-content">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_action" value="send_email_pismo">
      <div class="modal-header">
        <h6 class="modal-title fw-semibold" id="pismoEmailModalLabel"><i class="bi bi-send me-1 text-success"></i>Wyślij pismo mailem</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.84rem">Adres e-mail odbiorcy <span class="text-danger">*</span></label>
          <input type="email" name="recipient_email" class="form-control form-control-sm" required placeholder="odbiorca@example.com"
                 value="<?= h($pismo['odbiorca'] && filter_var($pismo['odbiorca'], FILTER_VALIDATE_EMAIL) ? $pismo['odbiorca'] : '') ?>">
        </div>
        <div class="row g-2 mb-3">
          <div class="col-sm-6">
            <label class="form-label fw-semibold" style="font-size:.84rem" for="pem_cc">DW <span class="text-muted fw-normal">(Do Wiadomości)</span></label>
            <input type="text" id="pem_cc" name="cc_emails" class="form-control form-control-sm"
                   placeholder="kopia@example.com, inna@example.com"
                   aria-describedby="pem_cc_help">
            <div id="pem_cc_help" class="form-text">Kilka adresów: oddziel przecinkami.</div>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold" style="font-size:.84rem" for="pem_bcc">UDW <span class="text-muted fw-normal">(Ukryta kopia)</span></label>
            <input type="text" id="pem_bcc" name="bcc_emails" class="form-control form-control-sm"
                   placeholder="ukryta@example.com">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.84rem">Temat</label>
          <input type="text" name="mail_subject" class="form-control form-control-sm"
                 value="<?= h($pismo['sygnatura'] . ' — ' . $pismo['title']) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" style="font-size:.84rem">Treść wiadomości</label>
          <textarea name="mail_body" class="form-control form-control-sm" rows="7"
                    placeholder="Treść wiadomości e-mail (opcjonalna)…"></textarea>
        </div>
        <div class="mb-2">
          <label class="form-label fw-semibold" style="font-size:.84rem">Załączniki — podpisane elektronicznie PDF</label>
          <?php foreach($signed_pdfs as $z): ?>
          <div class="form-check mb-1">
            <input class="form-check-input" type="checkbox" name="zal_ids[]" value="<?= $z['id'] ?>" id="zml<?= $z['id'] ?>" checked>
            <label class="form-check-label d-flex align-items-center gap-2 flex-wrap" for="zml<?= $z['id'] ?>" style="font-size:.82rem">
              <i class="bi bi-file-earmark-pdf text-danger"></i>
              <span><?= h($z['original_name']) ?></span>
              <span class="text-muted" style="font-size:.72rem"><?= ezd_filesize($z['file_size']) ?></span>
              <span class="badge bg-success bg-opacity-15 text-success border border-success" style="font-size:.62rem"><i class="bi bi-pen-fill me-1"></i>Podpisany elektronicznie</span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="p-2 rounded border border-secondary border-opacity-25" style="font-size:.72rem;color:#64748b">
          <i class="bi bi-info-circle me-1"></i>Wiadomość zostanie wysłana z domyślnego adresu e-mail organizacji. Wybrany plik PDF zostanie dołączony jako załącznik.
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-send me-1"></i>Wyślij</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/ezd_rsign.php'; ?>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

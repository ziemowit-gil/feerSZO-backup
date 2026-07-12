<?php
/**
 * Pisma do wolontariuszy bez umowy (konta standalone).
 * Wysyłka zaszyfrowanego ZIP e-mailem + hasło SMS-em; rejestracja w EZD (JRWA WOL).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/letters.php';
require_once dirname(dirname(__DIR__)) . '/includes/sms.php';
require_once dirname(dirname(__DIR__)) . '/includes/secure_mail.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$user_id    = (int)current_user()['id'];
$sms_ready  = sms_is_enabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!can_edit()) { http_response_code(403); exit; }

    $vid          = (int)($_POST['volunteer_id'] ?? 0);
    $tytul        = trim($_POST['tytul'] ?? '');
    $tresc        = trim($_POST['tresc'] ?? '');
    $content_mode = $_POST['content_mode'] ?? 'text';
    $plik         = handle_letter_upload('plik');

    if (!$vid) {
        flash_set('danger', 'Wybierz wolontariusza.');
    } elseif (!$tytul) {
        flash_set('danger', 'Podaj tytuł pisma.');
    } elseif ($content_mode === 'file' && !$plik) {
        flash_set('danger', 'Wybierz plik (PDF/DOCX/JPG/PNG) lub przełącz na tryb treści.');
    } elseif ($content_mode === 'text' && !$tresc) {
        flash_set('danger', 'Wpisz treść pisma lub wgraj plik.');
    } else {
        $res = send_secure_letter_to_standalone_volunteer($vid, [
            'tytul' => $tytul,
            'tresc' => $content_mode === 'text' ? $tresc : '',
            'plik'  => $content_mode === 'file' ? $plik : null,
        ], $user_id);

        if (!empty($res['sent'])) {
            $sygn = $res['ezd_pismo_id'] ? ' Zarejestrowano w EZD (pismo #' . (int)$res['ezd_pismo_id'] . ').' : '';
            flash_set('success', 'Pismo wysłano na ' . h($res['to']) . '. Hasło do pliku przesłano SMS-em na ' . h($res['phone']) . '.' . $sygn);
        } else {
            $map = [
                'no_user'        => 'Nie znaleziono konta wolontariusza.',
                'not_standalone' => 'To konto nie jest wolontariuszem bez umowy.',
                'inactive'       => 'Konto wolontariusza jest nieaktywne.',
                'no_email'       => 'Brak poprawnego adresu e-mail (lub e-maila opiekuna dla małoletniego).',
                'sms_disabled'   => 'SMS jest wyłączony — skonfiguruj go w Administracja → Ustawienia SMS.',
                'no_phone'       => 'Brak numeru telefonu (lub telefonu opiekuna) — nie ma jak wysłać hasła SMS-em.',
                'file_missing'   => 'Nie znaleziono wgranego pliku.',
                'docx_failed'    => 'Nie udało się wygenerować dokumentu z treści.',
                'no_content'     => 'Brak treści i pliku pisma.',
                'zip_failed'     => 'Błąd szyfrowania ZIP (sprawdź obsługę AES w libzip).',
            ];
            $reason = $res['reason'] ?? '';
            $msg = $map[$reason] ?? ('Nie udało się wysłać pisma (' . h($reason) . ').');
            flash_set('danger', $msg);
        }
    }
    header('Location: ' . APP_URL . '/ezd/wolontariusze/index.php');
    exit;
}

// Lista kont standalone (do wyboru odbiorcy)
$volunteers = db_all(
    "SELECT id, name, first_name, last_name, email, phone_number, is_minor, guardian_email, guardian_phone, is_active
     FROM users
     WHERE is_standalone_volunteer = 1 AND is_active = 1
     ORDER BY last_name, first_name, name"
);

// Wysłane pisma (z rejestru EZD pod JRWA WOL)
$sent = db_all(
    "SELECT p.id, p.sygnatura, p.title, p.odbiorca, p.data_wysylki, p.sprawa_id, s.znak_sprawy
     FROM ezd_pisma p
     JOIN ezd_sprawy s ON s.id = p.sprawa_id
     JOIN ezd_teczki t ON t.id = s.teczka_id
     JOIN ezd_jrwa   j ON j.id = t.jrwa_id
     WHERE j.symbol = ? AND s.title LIKE 'Pisma do wolontariuszy bez umowy%'
     ORDER BY p.id DESC LIMIT 200",
    [ezd_vol_jrwa()]
);

$PAGE_TITLE = 'Pisma — wolontariusze bez umowy';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Wolontariusze bez umowy</li>
</ol></nav>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 fw-bold"><i class="bi bi-heart text-primary me-2"></i>Pisma — wolontariusze bez umowy</h4>
    <div class="text-muted" style="font-size:.8rem;margin-top:.15rem">Wysyłka zaszyfrowanego pisma (ZIP) e-mailem; hasło dostarczane SMS-em. Każde pismo trafia do rejestru EZD pod JRWA <span class="font-monospace fw-semibold"><?= h(ezd_vol_jrwa()) ?></span>.</div>
  </div>
</div>

<?= flash_html() ?>

<?php if (!$sms_ready): ?>
<div class="alert alert-warning py-2" style="font-size:.84rem"><i class="bi bi-exclamation-triangle me-1"></i>SMS jest wyłączony — wysyłka wymaga skonfigurowanego dostawcy SMS (hasło do pliku przesyłane jest SMS-em). Skonfiguruj w <a href="<?= APP_URL ?>/admin/sms_settings.php">Ustawieniach SMS</a>.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-send me-1 text-primary"></i>Wyślij pismo</div>
      <div class="card-body">
        <?php if (!$volunteers): ?>
          <div class="text-muted" style="font-size:.85rem">Brak aktywnych kont wolontariuszy bez umowy. Dodaj je w <a href="<?= APP_URL ?>/admin/volunteer_accounts.php">kontach wolontariuszy</a>.</div>
        <?php else: ?>
        <form method="post" enctype="multipart/form-data" <?= (can_edit() && $sms_ready) ? '' : '' ?>>
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

          <div class="mb-3">
            <label class="form-label small fw-bold">WOLONTARIUSZ</label>
            <select name="volunteer_id" class="form-select" required>
              <option value="">— wybierz —</option>
              <?php foreach ($volunteers as $v):
                $nm = trim(($v['first_name'] ?? '') . ' ' . ($v['last_name'] ?? '')) ?: ($v['name'] ?? '');
                $no_phone = empty($v['phone_number']) && !(!empty($v['is_minor']) && !empty($v['guardian_phone']));
              ?>
                <option value="<?= (int)$v['id'] ?>">
                  <?= h($nm) ?> — <?= h($v['email'] ?? '') ?><?= !empty($v['is_minor']) ? ' (małoletni)' : '' ?><?= $no_phone ? ' [brak tel.]' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text small">Małoletni: e-mail i SMS trafią do opiekuna.</div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-bold">TYTUŁ PISMA</label>
            <input type="text" name="tytul" class="form-control" value="<?= h($_POST['tytul'] ?? '') ?>" required>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-bold d-block">TRYB TREŚCI</label>
            <div class="btn-group w-100" role="group">
              <input type="radio" class="btn-check" name="content_mode" id="m_text" value="text" checked onchange="vbuTog()">
              <label class="btn btn-outline-primary" for="m_text"><i class="bi bi-textarea-t me-1"></i>Treść</label>
              <input type="radio" class="btn-check" name="content_mode" id="m_file" value="file" onchange="vbuTog()">
              <label class="btn btn-outline-primary" for="m_file"><i class="bi bi-file-earmark-arrow-up me-1"></i>Plik</label>
            </div>
          </div>

          <div class="mb-3" id="w_text">
            <textarea name="tresc" id="vbu_editor" class="form-control" rows="8"><?= h($_POST['tresc'] ?? '') ?></textarea>
          </div>

          <div class="mb-3 d-none" id="w_file">
            <input type="file" name="plik" class="form-control" accept=".pdf,.docx,.jpg,.jpeg,.png">
            <div class="form-text small">PDF, DOCX, JPG, PNG (maks. 30 MB).</div>
          </div>

          <button type="submit" class="btn btn-primary w-100" <?= ($sms_ready && can_edit()) ? '' : 'disabled' ?>>
            <i class="bi bi-shield-lock me-1"></i>Wyślij zaszyfrowane (hasło SMS)
          </button>
          <?php if (!can_edit()): ?><div class="form-text small text-danger mt-1">Brak uprawnień do wysyłki.</div><?php endif; ?>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.88rem"><i class="bi bi-clock-history me-1 text-primary"></i>Wysłane pisma (<?= count($sent) ?>)</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.82rem">
          <thead class="table-light">
            <tr><th>Znak EZD</th><th>Tytuł</th><th>Odbiorca</th><th class="text-nowrap">Wysłano</th></tr>
          </thead>
          <tbody>
          <?php foreach ($sent as $p): ?>
            <tr>
              <td class="text-nowrap">
                <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$p['sprawa_id'] ?>" class="font-monospace text-decoration-none" title="<?= h($p['znak_sprawy']) ?>"><?= h($p['sygnatura'] ?: $p['znak_sprawy']) ?></a>
              </td>
              <td><?= h(mb_substr($p['title'] ?: '—', 0, 50)) ?></td>
              <td><?= h(mb_substr($p['odbiorca'] ?: '—', 0, 40)) ?></td>
              <td class="text-nowrap"><?= $p['data_wysylki'] ? date_pl(substr($p['data_wysylki'],0,10)) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$sent): ?>
            <tr><td colspan="4" class="text-center text-muted py-5">
              <i class="bi bi-inbox" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
              Brak wysłanych pism.
            </td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="text-muted mt-2" style="font-size:.74rem"><i class="bi bi-info-circle me-1"></i>Plik jest szyfrowany (ZIP/AES-256), a hasło wysyłane SMS-em. Pismo i oryginał załącznika trafiają do sprawy ciągłej „Pisma do wolontariuszy bez umowy {rok}" w teczce JRWA <?= h(ezd_vol_jrwa()) ?>.</div>

<script src="https://cdn.ckeditor.com/ckeditor5/39.0.1/classic/ckeditor.js"></script>
<script>
let vbuEditor;
ClassicEditor.create(document.querySelector('#vbu_editor'), {
  toolbar: ['heading','|','bold','italic','link','bulletedList','numberedList','|','undo','redo'],
  language: 'pl'
}).then(e => vbuEditor = e).catch(()=>{});

function vbuTog() {
  const file = document.getElementById('m_file').checked;
  document.getElementById('w_text').classList.toggle('d-none', file);
  document.getElementById('w_file').classList.toggle('d-none', !file);
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

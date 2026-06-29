<?php
/**
 * asysta/respond.php — Odpowiedź przypisanej osoby na zgłoszenie asysty.
 *
 * Dostęp przez unikalny token z linku w e-mailu/SMS — bez logowania.
 * Przypisana osoba widzi szczegóły zgłoszenia i może:
 *   • Przyjąć  → status: volunteer_accepted
 *   • Odrzucić → status: volunteer_rejected (z opcjonalnym powodem)
 *
 * Token wygasa automatycznie gdy koordynator zmieni przypisaną osobę.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/sms.php';
require_once dirname(__DIR__) . '/includes/assistance.php';

asr_migrate();
auth_start();

$token = trim((string)($_GET['t'] ?? ''));
$req   = $token !== '' ? asr_get_by_token($token) : null;

$done        = false;   // po wykonaniu akcji
$done_action = '';      // 'accepted' | 'rejected'
$error       = '';

/* ── Obsługa POST: przyjęcie lub odrzucenie ───────────────────────────────── */
if ($req && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    // Zablokuj jeśli status już finalny (wolontariusz już odpowiedział).
    $final = ['volunteer_accepted', 'volunteer_rejected', 'done', 'rejected', 'rejected_external'];
    if (in_array($req['status'], $final, true)) {
        $error = 'To zgłoszenie zostało już rozpatrzone. Nie można zmienić odpowiedzi.';
    } elseif ($action === 'accept') {
        $reason = '';
        _asr_respond_save($req, 'volunteer_accepted', $reason);
        $done        = true;
        $done_action = 'accepted';
    } elseif ($action === 'reject') {
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
        _asr_respond_save($req, 'volunteer_rejected', $reason);
        $done        = true;
        $done_action = 'rejected';
    } else {
        $error = 'Nieznana akcja.';
    }

    // Odśwież rekord po zapisie.
    if ($done) $req = asr_get((int)$req['id']);
}

/* ── Zapis odpowiedzi (wewnętrzna funkcja pomocnicza) ─────────────────────── */
function _asr_respond_save(array $req, string $new_status, string $reason): void {
    $id = (int)$req['id'];
    db()->prepare(
        "UPDATE szo_assistance_requests SET status=?, updated_at=? WHERE id=?"
    )->execute([$new_status, date('Y-m-d H:i:s'), $id]);

    $note = $reason !== '' ? "Powód: {$reason}" : '';
    asr_log($id, $req['status'], $new_status, $note, null, (string)($req['assigned_name'] ?? ''));

    // Powiadom uczestnika.
    if (in_array($new_status, asr_participant_notify_statuses(), true)) {
        asr_notify_participant($id);
    }
}

/* ─────────────────────────────────────────────────────────────────────────── */
$statuses = asr_statuses();
$needs_all = asr_needs();
$org = defined('ORG_NAME') ? ORG_NAME : 'FEER';

// Czy status jest już finalny (odpowiedź już udzielona)?
$already_final = $req && in_array($req['status'], ['volunteer_accepted','volunteer_rejected','done','rejected','rejected_external'], true);
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Zgłoszenie asysty — odpowiedź — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background:#f1f5f9; }
  .rsp-card { max-width:640px; }
  a:focus-visible, button:focus-visible, input:focus-visible, textarea:focus-visible {
    outline:3px solid #1d4ed8; outline-offset:2px; box-shadow:none;
  }
  .skip-link {
    position:absolute; left:-999px; top:0; background:#1d4ed8; color:#fff;
    padding:.5rem .9rem; border-radius:0 0 .4rem 0; z-index:1000;
  }
  .skip-link:focus { left:0; }
</style>
</head>
<body>
<a href="#main" class="skip-link">Przejdź do treści</a>
<div class="container py-4 py-md-5">
<div class="rsp-card mx-auto">

  <header class="text-center mb-4">
    <i class="bi bi-universal-access-circle text-primary" style="font-size:2.4rem" aria-hidden="true"></i>
    <h1 class="h4 fw-bold mt-2 mb-1">Zgłoszenie asysty — Twoja odpowiedź</h1>
    <p class="text-secondary mb-0"><?= h($org) ?></p>
  </header>

  <main id="main">
  <?php if (!$req): ?>

    <div class="alert alert-warning" role="alert">
      <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>
      <strong>Link jest nieprawidłowy lub wygasł.</strong><br>
      Jeśli otrzymałeś/aś nowe zgłoszenie, skorzystaj z najnowszego linku
      z wiadomości e-mail lub SMS. Możliwe, że koordynator zmienił przypisanie.
    </div>

  <?php elseif ($error): ?>

    <div class="alert alert-danger" role="alert">
      <i class="bi bi-x-circle-fill me-2" aria-hidden="true"></i><?= h($error) ?>
    </div>
    <div class="text-center mt-3">
      <a href="<?= h(APP_URL) ?>/asysta/respond.php?t=<?= urlencode($token) ?>" class="btn btn-outline-secondary btn-sm">Wróć</a>
    </div>

  <?php elseif ($done): ?>

    <div class="card border-0 shadow-sm" role="status">
      <div class="card-body text-center p-4 p-md-5">
        <?php if ($done_action === 'accepted'): ?>
          <i class="bi bi-check-circle-fill text-success" style="font-size:3rem" aria-hidden="true"></i>
          <h2 class="h4 fw-bold mt-3">Zgłoszenie przyjęte</h2>
          <p class="text-secondary mb-0">
            Uczestnik zostanie powiadomiony, że asysta jest potwierdzona.<br>
            Dziękujemy za zaangażowanie!
          </p>
        <?php else: ?>
          <i class="bi bi-x-circle-fill text-danger" style="font-size:3rem" aria-hidden="true"></i>
          <h2 class="h4 fw-bold mt-3">Zgłoszenie odrzucone</h2>
          <p class="text-secondary mb-0">
            Uczestnik zostanie poinformowany. Koordynator może przypisać inną osobę.
          </p>
        <?php endif; ?>
        <div class="mt-4">
          <span class="badge fs-6 <?= h(asr_status_class($req['status'])) ?>">
            <?= h(asr_label($statuses, $req['status'])) ?>
          </span>
        </div>
      </div>
    </div>

  <?php else: ?>

    <?php if ($already_final): ?>
      <div class="alert alert-info" role="alert">
        <i class="bi bi-info-circle-fill me-2" aria-hidden="true"></i>
        To zgłoszenie ma już status <strong><?= h(asr_label($statuses, $req['status'])) ?></strong>.
        Poniżej podgląd szczegółów.
      </div>
    <?php endif; ?>

    <?php
    $no          = asr_number($req);
    $picked_needs = array_filter(array_map('trim', explode(',', (string)$req['needs'])));
    ?>

    <!-- Szczegóły zgłoszenia -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-light fw-bold">
        <i class="bi bi-clipboard2-pulse me-1" aria-hidden="true"></i>
        Zgłoszenie <?= h($no) ?>
      </div>
      <div class="card-body">
        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <div class="text-secondary small text-uppercase">Data zgłoszenia</div>
            <div><?= h(substr((string)$req['created_at'], 0, 16)) ?></div>
          </div>
          <div class="col-sm-6">
            <div class="text-secondary small text-uppercase">Status</div>
            <span class="badge <?= h(asr_status_class($req['status'])) ?>">
              <?= h(asr_label($statuses, $req['status'])) ?>
            </span>
          </div>
        </div>

        <hr>
        <h2 class="h6 fw-bold text-secondary text-uppercase mb-2">Uczestnik</h2>
        <dl class="row mb-0">
          <dt class="col-sm-4">Imię i nazwisko</dt>
          <dd class="col-sm-8"><?= h($req['participant_name']) ?></dd>
          <dt class="col-sm-4">E-mail</dt>
          <dd class="col-sm-8"><a href="mailto:<?= h($req['participant_email']) ?>"><?= h($req['participant_email']) ?></a></dd>
          <dt class="col-sm-4">Telefon</dt>
          <dd class="col-sm-8"><a href="tel:<?= h($req['participant_phone']) ?>"><?= h($req['participant_phone']) ?></a></dd>
          <?php if ((int)$req['is_guardian']): ?>
          <dt class="col-sm-4">Uwaga</dt>
          <dd class="col-sm-8">Zgłasza jako opiekun innej osoby</dd>
          <?php endif; ?>
        </dl>

        <?php if (!empty($req['event_name'])): ?>
        <hr>
        <h2 class="h6 fw-bold text-secondary text-uppercase mb-2">Wydarzenie</h2>
        <dl class="row mb-0">
          <dt class="col-sm-4">Nazwa</dt>
          <dd class="col-sm-8"><?= h($req['event_name']) ?></dd>
          <?php if (!empty($req['event_when_where'])): ?>
          <dt class="col-sm-4">Data i miejsce</dt>
          <dd class="col-sm-8"><?= h($req['event_when_where']) ?></dd>
          <?php endif; ?>
        </dl>
        <?php endif; ?>

        <hr>
        <h2 class="h6 fw-bold text-secondary text-uppercase mb-2">Zakres asysty i potrzeby</h2>
        <?php if ($picked_needs): ?>
          <ul class="mb-2">
            <?php foreach ($picked_needs as $k): ?>
              <li><?= h($needs_all[$k] ?? $k) ?>
                <?php if ($k === 'other' && trim((string)$req['needs_other']) !== ''): ?>
                  — <em><?= h($req['needs_other']) ?></em>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="text-secondary mb-2">— brak —</p>
        <?php endif; ?>
        <?php if (trim((string)$req['details']) !== ''): ?>
          <div class="mt-2">
            <div class="fw-semibold small">Dodatkowe uwagi od uczestnika:</div>
            <div><?= nl2br(h($req['details'])) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$already_final): ?>
    <!-- Formularz odpowiedzi -->
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-light fw-bold">
        <i class="bi bi-reply me-1" aria-hidden="true"></i>Twoja odpowiedź
      </div>
      <div class="card-body p-4">
        <p class="text-secondary mb-4">
          Przydzielono Ci realizację asysty dla tego uczestnika.
          Potwierdź, że możesz podjąć się zadania, lub odrzuć zgłoszenie
          — koordynator zostanie poinformowany.
        </p>

        <form method="post" action="<?= h(APP_URL) ?>/asysta/respond.php?t=<?= urlencode($token) ?>" novalidate
              id="rsp-form">
          <?= csrf_field() ?>

          <div class="mb-4" id="reject-reason-wrap" style="display:none">
            <label for="reason" class="form-label fw-semibold">
              Powód odrzucenia <span class="text-secondary fw-normal">(opcjonalnie)</span>
            </label>
            <textarea class="form-control" id="reason" name="reason" rows="3" maxlength="500"
                      aria-describedby="reason-help"></textarea>
            <div id="reason-help" class="form-text">Informacja trafi do koordynatora — nie jest przekazywana uczestnikowi.</div>
          </div>

          <div class="d-flex flex-wrap gap-3">
            <button type="button" class="btn btn-success btn-lg px-4" id="btn-accept"
                    aria-describedby="accept-desc">
              <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Przyjmuję zgłoszenie
            </button>
            <span id="accept-desc" class="visually-hidden">Potwierdza, że przyjmujesz realizację asysty dla tego uczestnika.</span>

            <button type="button" class="btn btn-outline-danger btn-lg px-4" id="btn-reject"
                    aria-describedby="reject-desc">
              <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Odrzucam zgłoszenie
            </button>
            <span id="reject-desc" class="visually-hidden">Otwiera pole powodu i wysyła odrzucenie do koordynatora.</span>
          </div>

          <!-- ukryty submit generowany przez JS -->
          <input type="hidden" name="_action" id="rsp-action" value="">
          <button type="submit" id="rsp-submit" class="d-none" aria-hidden="true">Wyślij</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

  <?php endif; ?>
  </main>

  <p class="text-center text-secondary small mt-4 mb-0">
    &copy; <?= date('Y') ?> <?= h($org) ?>
  </p>
</div>
</div>

<script>
(function () {
  var btnAccept = document.getElementById('btn-accept');
  var btnReject = document.getElementById('btn-reject');
  var reasonWrap = document.getElementById('reject-reason-wrap');
  var actionInput = document.getElementById('rsp-action');
  var form = document.getElementById('rsp-form');
  if (!btnAccept || !form) return;

  var rejectPending = false;

  btnAccept.addEventListener('click', function () {
    if (!confirm('Potwierdzasz przyjęcie zgłoszenia asysty?')) return;
    actionInput.value = 'accept';
    form.submit();
  });

  btnReject.addEventListener('click', function () {
    if (!rejectPending) {
      // Pierwszy klik: pokaż pole powodu i zmień przycisk na „Potwierdź odrzucenie".
      rejectPending = true;
      reasonWrap.style.display = '';
      reasonWrap.querySelector('textarea').focus();
      btnReject.textContent = 'Potwierdź odrzucenie';
      btnReject.classList.replace('btn-outline-danger', 'btn-danger');
      btnAccept.style.display = 'none';
    } else {
      // Drugi klik: wyślij.
      actionInput.value = 'reject';
      form.submit();
    }
  });
})();
</script>
</body>
</html>

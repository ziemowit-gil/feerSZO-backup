<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/db_clean.php';

require_role('admin');
$PAGE_TITLE = 'Wyczyść bazę danych';

$user    = current_user();
$results = null;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $confirm = trim($_POST['confirm_text'] ?? '');
    $full    = ($_POST['mode'] ?? '') === 'full';

    if ($confirm !== 'WYCZYŚĆ') {
        $error = 'Nieprawidłowe potwierdzenie. Wpisz dokładnie: WYCZYŚĆ';
    } else {
        try {
            $results = db_clean(db(), $full, (int)$user['id']);
            $label   = $full ? 'Pełne czyszczenie (wraz z użytkownikami)' : 'Czyszczenie danych umów i dokumentów';
            flash_set('success', "Baza wyczyszczona — tryb: $label.");
        } catch (\Throwable $e) {
            $error = 'Błąd podczas czyszczenia: ' . $e->getMessage();
        }
    }
}

$counts = db_clean_counts(db());
$data_total = 0;
foreach ($counts as $t => $n) {
    if ($t !== 'users' && $n !== null) $data_total += $n;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<h4 class="mb-1 text-danger"><i class="bi bi-trash3"></i> Wyczyść bazę danych</h4>
<p class="text-muted small mb-4">Nieodwracalne usunięcie danych z bazy. Struktura tabel i ustawienia zostają zachowane.</p>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= h($error) ?></div>
<?php endif; ?>

<?php if ($results !== null): ?>
<div class="card border-success mb-4">
  <div class="card-header bg-success text-white fw-semibold small">
    <i class="bi bi-check-circle me-1"></i>Wyniki czyszczenia
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0 small">
      <thead class="table-light"><tr><th>Tabela</th><th class="text-end">Usunięto</th><th>Status</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): if ($r['status'] === 'skip') continue; ?>
      <tr class="<?= $r['status'] === 'err' ? 'table-danger' : ($r['deleted'] > 0 ? '' : 'text-muted') ?>">
        <td class="font-monospace"><?= h($r['table']) ?></td>
        <td class="text-end"><?= $r['deleted'] ?></td>
        <td>
          <?php if ($r['status'] === 'err'): ?>
            <span class="text-danger"><i class="bi bi-x-circle"></i> <?= h($r['error'] ?? '') ?></span>
          <?php else: ?>
            <i class="bi bi-check text-success"></i>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- ── Stan bazy ───────────────────────────────────────────────── -->
<div class="col-lg-5">
<div class="card shadow-sm">
  <div class="card-header fw-semibold small"><i class="bi bi-database me-1"></i>Aktualny stan bazy</div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0 small">
      <thead class="table-light"><tr><th>Tabela</th><th class="text-end">Rekordów</th></tr></thead>
      <tbody>
      <?php
      $groups = [
          'Umowy'            => ['umowy_zlecenie','umowy_uslugi','umowy_wolontariat','umowy_dzielo','umowy_praca','umowy_inne'],
          'Obieg dokumentów' => ['contract_approvals','contract_amendments','contract_edit_requests','contract_termination_requests','certificate_requests','contract_supervisors','contract_letters','contract_audit_log'],
          'Wiadomości'       => ['messages'],
          'Onboarding'       => ['onboarding_volunteers','onboarding_messages'],
          'Zadania'          => ['task_workspaces','tasks','task_comments','task_history'],
          'Inne'             => ['sms_login_tokens','m365_standalone_accounts'],
          'Użytkownicy'      => ['users'],
      ];
      foreach ($groups as $grp => $tables):
          $grp_n = 0;
          foreach ($tables as $t) $grp_n += $counts[$t] ?? 0;
      ?>
      <tr class="table-secondary fw-semibold"><td colspan="2" class="px-3 py-1" style="font-size:.7rem;letter-spacing:.06em;text-transform:uppercase"><?= h($grp) ?></td></tr>
      <?php foreach ($tables as $t):
          $n = $counts[$t] ?? null;
          if ($n === null) continue;
      ?>
      <tr class="<?= $n > 0 ? '' : 'text-muted' ?>">
        <td class="ps-4 font-monospace" style="font-size:.78rem"><?= h($t) ?></td>
        <td class="text-end pe-3 fw-semibold <?= $n > 0 ? 'text-danger' : '' ?>"><?= $n ?></td>
      </tr>
      <?php endforeach; endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<!-- ── Formularze czyszczenia ─────────────────────────────────── -->
<div class="col-lg-7">

  <!-- Czyszczenie danych -->
  <div class="card shadow-sm border-warning mb-4">
    <div class="card-header fw-semibold small text-warning-emphasis bg-warning bg-opacity-10">
      <i class="bi bi-eraser me-1"></i>Wyczyść dane umów i dokumentów
    </div>
    <div class="card-body">
      <p class="small text-muted mb-3">
        Usuwa wszystkie umowy (6 typów), dokumenty obiegu, wiadomości, zgłoszenia onboardingowe,
        zadania, logi. <strong>Zachowuje użytkowników i ustawienia.</strong>
      </p>
      <div class="alert alert-warning py-2 small mb-3">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Do usunięcia: <strong><?= $data_total ?></strong> rekordów danych.
        Operacja jest <strong>nieodwracalna</strong>.
      </div>
      <form method="post" onsubmit="return checkConfirm(this)">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="mode"  value="data">
        <div class="mb-3">
          <label class="form-label small fw-semibold">
            Wpisz <code>WYCZYŚĆ</code> aby potwierdzić:
          </label>
          <input type="text" name="confirm_text" class="form-control form-control-sm font-monospace"
                 placeholder="WYCZYŚĆ" autocomplete="off" required>
        </div>
        <button type="submit" class="btn btn-warning btn-sm">
          <i class="bi bi-eraser me-1"></i>Wyczyść dane
        </button>
      </form>
    </div>
  </div>

  <!-- Pełne czyszczenie -->
  <div class="card shadow-sm border-danger">
    <div class="card-header fw-semibold small text-danger-emphasis bg-danger bg-opacity-10">
      <i class="bi bi-trash3 me-1"></i>Pełne czyszczenie (wraz z użytkownikami)
    </div>
    <div class="card-body">
      <p class="small text-muted mb-3">
        Usuwa wszystko powyżej, <strong>a dodatkowo wszystkich użytkowników</strong>
        poza Twoim kontem<?php if (defined('SAAS_SYSTEM_EMAIL') || true): ?> i kontem systemowym SaaS<?php endif; ?>.
        Po tej operacji będziesz jedynym użytkownikiem.
      </p>
      <div class="alert alert-danger py-2 small mb-3">
        <i class="bi bi-skull me-1"></i>
        Łącznie do usunięcia: <strong><?= $data_total + ($counts['users'] ?? 0) ?></strong> rekordów.
        Twoje konto (<em><?= h($user['email']) ?></em>) zostanie zachowane.
      </div>
      <form method="post" onsubmit="return checkConfirm(this)">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="mode"  value="full">
        <div class="mb-3">
          <label class="form-label small fw-semibold">
            Wpisz <code>WYCZYŚĆ</code> aby potwierdzić:
          </label>
          <input type="text" name="confirm_text" class="form-control form-control-sm font-monospace"
                 placeholder="WYCZYŚĆ" autocomplete="off" required>
        </div>
        <button type="submit" class="btn btn-danger btn-sm">
          <i class="bi bi-trash3 me-1"></i>Pełne czyszczenie
        </button>
      </form>
    </div>
  </div>

</div><!-- /col -->
</div><!-- /row -->

<script>
function checkConfirm(form) {
    const v = form.querySelector('[name="confirm_text"]').value.trim();
    if (v !== 'WYCZYŚĆ') {
        alert('Wpisz dokładnie: WYCZYŚĆ');
        return false;
    }
    const mode = form.querySelector('[name="mode"]').value;
    const msg  = mode === 'full'
        ? 'UWAGA: Usunie wszystkie dane i użytkowników. Tej operacji nie można cofnąć. Na pewno?'
        : 'Usunie wszystkie umowy i dokumenty. Tej operacji nie można cofnąć. Na pewno?';
    return confirm(msg);
}
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

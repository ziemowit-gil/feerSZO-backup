<?php
/**
 * karty30/admin/consultants.php — Zarządzanie doradcami TyfloKonsultacje.
 *
 * Administratorzy mogą tu przyznawać i odbierać uprawnienie
 * „Prowadzenie konsultacji Tyflo" (k30_consultant=1) dowolnemu
 * aktywnemu użytkownikowi systemu — w tym wolontariuszom.
 *
 * Dostępność WCAG 2.1 AA: pełne a11y dla osoby niewidomej.
 */

require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

// Tylko admin może zarządzać uprawnieniami doradców
if (!is_admin()) {
    flash_set('danger', 'Zarządzanie doradcami wymaga uprawnień administratora.');
    header('Location: ' . APP_URL . '/karty30/index.php');
    exit;
}

$PAGE_TITLE = 'Doradcy — Dydaktyka';
$errors = [];
$success_msg = '';

// ── POST: nadaj / odbierz uprawnienie ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action  = $_POST['_action'] ?? '';
    $user_id = (int)($_POST['user_id'] ?? 0);

    if ($user_id && in_array($action, ['grant', 'revoke'])) {
        $val  = $action === 'grant' ? 1 : 0;
        $user = db_one("SELECT id, name, first_name, last_name, role FROM users WHERE id=? AND is_active=1", [$user_id]);

        if (!$user) {
            $errors[] = 'Użytkownik nie istnieje lub jest nieaktywny.';
        } else {
            db()->prepare("UPDATE users SET k30_consultant=? WHERE id=?")
                ->execute([$val, $user_id]);
            $display = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?: $user['name'];
            $success_msg = $action === 'grant'
                ? "Uprawnienie doradcy przyznane: {$display}"
                : "Uprawnienie doradcy odebrane: {$display}";
            flash_set('success', $success_msg);
        }
    }
    header('Location: consultants.php');
    exit;
}

// ── Dane: aktywni doradcy i reszta użytkowników ──────────────────────────────
$role_labels = [
    'admin'    => 'Administrator',
    'editor'   => 'Edytor',
    'viewer'   => 'Widz / Wolontariusz',
    'crm_user' => 'Użytkownik CRM',
];

$consultants = db_all(
    "SELECT id,
            CASE WHEN first_name != '' AND last_name != ''
                 THEN first_name || ' ' || last_name ELSE name END AS display_name,
            email, role
     FROM users WHERE is_active=1 AND k30_consultant=1
     ORDER BY display_name"
);

$non_consultants = db_all(
    "SELECT id,
            CASE WHEN first_name != '' AND last_name != ''
                 THEN first_name || ' ' || last_name ELSE name END AS display_name,
            email, role
     FROM users WHERE is_active=1 AND (k30_consultant=0 OR k30_consultant IS NULL)
     ORDER BY display_name"
);

// Statystyki
$stats_schedules_by_user = db_all(
    "SELECT assigned_to, COUNT(*) AS cnt FROM k30_schedules
     WHERE status NOT IN ('cancelled','cancelled_by_feer','cancelled_by_client')
     AND assigned_to IS NOT NULL
     GROUP BY assigned_to"
);
$sched_map = [];
foreach ($stats_schedules_by_user as $s) $sched_map[(int)$s['assigned_to']] = (int)$s['cnt'];

$stats_consult_by_user = db_all(
    "SELECT consultant_id, COUNT(*) AS cnt FROM k30_consultations
     WHERE consultant_id IS NOT NULL GROUP BY consultant_id"
);
$cons_map = [];
foreach ($stats_consult_by_user as $s) $cons_map[(int)$s['consultant_id']] = (int)$s['cnt'];

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="Ścieżka nawigacji" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka</a></li>
    <li class="breadcrumb-item active" aria-current="page">Zarządzanie doradcami</li>
  </ol>
</nav>

<div class="k30-page-header">
  <div>
    <h1 class="k30-page-title">Doradcy konsultacji</h1>
    <p class="k30-page-subtitle">
      Zarządzaj uprawnieniem „Prowadzenie konsultacji Tyflo".
      Doradcą może być dowolny aktywny użytkownik — w tym wolontariusz.
    </p>
  </div>
</div>

<!-- Objaśnienie uprawnienia -->
<div class="k30-alert k30-alert-info mb-4" role="note">
  <i class="bi bi-info-circle-fill" aria-hidden="true" style="font-size:1.2rem;flex-shrink:0;margin-top:.1rem"></i>
  <div>
    <strong>Co daje uprawnienie doradcy?</strong>
    <ul class="mb-0 mt-1">
      <li>Użytkownik pojawia się na liście wyboru konsultanta przy tworzeniu terminu i konsultacji.</li>
      <li>Może prowadzić wizyty i zatwierdzać konsultacje.</li>
      <li>Uprawnienie jest niezależne od roli systemowej — wolontariusz (rola „Widz") może być doradcą.</li>
    </ul>
  </div>
</div>

<!-- ═══ AKTUALNI DORADCY ═══════════════════════════════════════════════════ -->
<section aria-labelledby="consultants-heading" class="mb-5">
  <h2 id="consultants-heading" style="font-size:1.15rem;font-weight:700;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:2px solid #E5E7EB">
    <i class="bi bi-person-check-fill me-2" aria-hidden="true" style="color:#2E844A"></i>
    Aktualni doradcy
    <span class="badge bg-success ms-2" aria-label="Liczba doradców: <?= count($consultants) ?>"><?= count($consultants) ?></span>
  </h2>

  <?php if ($consultants): ?>
  <div class="card shadow-sm">
    <table class="k30-table" aria-label="Lista doradców konsultacji">
      <thead>
        <tr>
          <th scope="col">Imię i nazwisko</th>
          <th scope="col">E-mail</th>
          <th scope="col">Rola systemowa</th>
          <th scope="col">Terminy</th>
          <th scope="col">Konsultacje</th>
          <th scope="col"><span class="visually-hidden">Akcje</span></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($consultants as $u): ?>
        <tr>
          <td>
            <span class="fw-semibold"><?= h($u['display_name']) ?></span>
            <?php if ($u['role'] === 'viewer'): ?>
            <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem"
                  title="Wolontariusz — widz systemowy">Wolontariusz</span>
            <?php endif; ?>
          </td>
          <td><a href="mailto:<?= h($u['email']) ?>"><?= h($u['email']) ?></a></td>
          <td><?= h($role_labels[$u['role']] ?? $u['role']) ?></td>
          <td><?= $sched_map[(int)$u['id']] ?? 0 ?></td>
          <td><?= $cons_map[(int)$u['id']] ?? 0 ?></td>
          <td class="text-end">
            <form method="post"
                  onsubmit="return confirm('Odebrać uprawnienie doradcy użytkownikowi <?= h(addslashes($u['display_name'])) ?>?')">
              <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
              <input type="hidden" name="_action"  value="revoke">
              <input type="hidden" name="user_id"  value="<?= (int)$u['id'] ?>">
              <button type="submit"
                      class="btn btn-sm btn-outline-danger"
                      aria-label="Odbierz uprawnienie doradcy użytkownikowi <?= h($u['display_name']) ?>">
                <i class="bi bi-person-dash" aria-hidden="true"></i>
                Odbierz uprawnienie
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="card shadow-sm">
    <div class="card-body text-center py-4">
      <i class="bi bi-person-x" aria-hidden="true" style="font-size:2rem;color:#D1D5DB;display:block;margin-bottom:.75rem"></i>
      <p class="text-muted mb-0">
        Brak doradców. Przyznaj uprawnienie poniżej — wybierz użytkownika z listy.
      </p>
    </div>
  </div>
  <?php endif; ?>
</section>

<!-- ═══ DODAJ DORADCĘ ══════════════════════════════════════════════════════ -->
<?php if ($non_consultants): ?>
<section aria-labelledby="grant-heading">
  <h2 id="grant-heading" style="font-size:1.15rem;font-weight:700;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:2px solid #E5E7EB">
    <i class="bi bi-person-plus-fill me-2" aria-hidden="true" style="color:#5B21B6"></i>
    Przyznaj uprawnienie doradcy
  </h2>

  <div class="card shadow-sm">
    <div class="card-body" style="max-width:560px">
      <p class="text-muted small mb-3" id="grant-desc">
        Wybierz użytkownika i kliknij „Przyznaj uprawnienie". Wolontariusze (rola: Widz)
        mogą być doradcami — ich rola systemowa nie zmieni się.
      </p>
      <form method="post" aria-label="Formularz przyznawania uprawnienia doradcy" aria-describedby="grant-desc">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="grant">

        <div class="mb-3">
          <label class="form-label" for="grant_user_id">
            Użytkownik <span aria-hidden="true" style="color:#DC2626"> *</span>
            <span class="visually-hidden">(wymagany)</span>
          </label>
          <select name="user_id" id="grant_user_id"
                  class="form-select"
                  required
                  aria-required="true"
                  aria-describedby="grant-user-hint">
            <option value="">— Wybierz użytkownika —</option>
            <?php
            // Grupuj wg roli dla łatwiejszej nawigacji klawiaturą
            $by_role = [];
            foreach ($non_consultants as $u) {
                $by_role[$u['role']][] = $u;
            }
            $role_order = ['admin','editor','viewer','crm_user'];
            foreach ($role_order as $r) {
                if (empty($by_role[$r])) continue;
                $rl = $role_labels[$r] ?? $r;
                echo "<optgroup label=\"{$rl}\">";
                foreach ($by_role[$r] as $u) {
                    $sched_cnt = $sched_map[(int)$u['id']] ?? 0;
                    $detail = $sched_cnt ? " — {$sched_cnt} terminów" : '';
                    echo '<option value="' . (int)$u['id'] . '">'
                        . h($u['display_name']) . h($detail)
                        . '</option>';
                }
                echo '</optgroup>';
            }
            ?>
          </select>
          <div id="grant-user-hint" class="form-hint">
            Lista zawiera tylko aktywnych użytkowników bez uprawnienia doradcy.
            Pogrupowana według roli systemowej.
          </div>
        </div>

        <button type="submit" class="btn btn-k30">
          <i class="bi bi-person-check me-2" aria-hidden="true"></i>Przyznaj uprawnienie doradcy
        </button>
      </form>
    </div>
  </div>
</section>
<?php else: ?>
<div class="k30-alert k30-alert-info" role="note">
  <i class="bi bi-check-circle-fill" aria-hidden="true" style="font-size:1.2rem;flex-shrink:0"></i>
  <span>Wszyscy aktywni użytkownicy systemu mają już uprawnienie doradcy.</span>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>

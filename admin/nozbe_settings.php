<?php
/**
 * admin/nozbe_settings.php — Konfiguracja integracji z Nozbe.
 *
 * Token API, domyślny projekt, test połączenia i podgląd ostatnio wysłanych
 * zadań. Kierunek wysyłki jest jednostronny (SZO → Nozbe) — patrz includes/nozbe.php.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/nozbe.php';

require_role('admin');
$PAGE_TITLE = 'Nozbe — integracja';

$test_result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'save') {
        org_setting_set('nozbe_enabled',    isset($_POST['nozbe_enabled']) ? '1' : '0');
        org_setting_set('nozbe_project_id', trim($_POST['nozbe_project_id'] ?? ''));
        // Puste pole tokenu = „zostaw jak było" — inaczej zapis ustawień kasowałby klucz
        $key = trim($_POST['nozbe_api_key'] ?? '');
        if ($key !== '' && strpos($key, '•') === false) org_setting_set('nozbe_api_key', $key);
        flash_set('success', 'Ustawienia Nozbe zostały zapisane.');
        header('Location: ' . APP_URL . '/admin/nozbe_settings.php');
        exit;
    }

    if ($action === 'test') {
        $t = nozbe_test();
        if ($t['ok']) {
            $projects = nozbe_projects(true);
            $test_result = ['ok' => true, 'count' => count($projects)];
        } else {
            $test_result = ['ok' => false, 'error' => $t['error']];
        }
    }

    if ($action === 'send_test') {
        $r = nozbe_create_task('Test integracji SZO ↔ Nozbe (' . date('d.m.Y H:i') . ')', [
            'comment'    => 'Zadanie utworzone z panelu administratora SZO w celu sprawdzenia integracji.',
            'local_type' => 'manual',
        ]);
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok']
            ? 'Zadanie testowe utworzone w Nozbe.'
            : 'Nie udało się utworzyć zadania: ' . $r['error']);
        header('Location: ' . APP_URL . '/admin/nozbe_settings.php');
        exit;
    }
}

$api_key  = nozbe_setting('nozbe_api_key');
$projects = nozbe_projects();
$cur_proj = nozbe_setting('nozbe_project_id');

try {
    $links = db_all("SELECT l.*, u.name AS user_name FROM nozbe_links l
                     LEFT JOIN users u ON u.id = l.created_by
                     ORDER BY l.id DESC LIMIT 20");
} catch (\Throwable $e) { $links = []; }

$LOCAL_LABELS = [
    'task'        => 'Zadanie SZO',
    'crm_message' => 'Wiadomość CRM',
    'crm_case'    => 'Sprawa CRM',
    'manual'      => 'Ręcznie',
];

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container py-3" style="max-width:900px">

  <h1 class="h4 fw-bold mb-1"><i class="bi bi-check2-square me-2 text-success"></i>Nozbe</h1>
  <p class="text-muted small mb-3">
    Wysyłanie rzeczy do zrobienia z SZO do Nozbe: zadania, wiadomości ze Skrzynki CRM i sprawy.
    Kierunek jest jednostronny — Nozbe nie odsyła statusów, więc zamknięcie zadania w Nozbe
    nie zmienia niczego w SZO.
  </p>

  <?php if ($test_result): ?>
  <div class="alert <?= $test_result['ok'] ? 'alert-success' : 'alert-danger' ?> py-2">
    <?= $test_result['ok']
        ? '<i class="bi bi-check-circle me-1"></i>Połączenie działa — pobrano projektów: ' . (int)$test_result['count'] . '.'
        : '<i class="bi bi-exclamation-triangle me-1"></i>' . h($test_result['error']) ?>
  </div>
  <?php endif; ?>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="save">

        <div class="form-check form-switch mb-3">
          <input type="checkbox" class="form-check-input" id="nozbe_enabled" name="nozbe_enabled" value="1"
                 <?= nozbe_setting('nozbe_enabled') === '1' ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="nozbe_enabled">Integracja włączona</label>
          <div class="form-text">Wyłączona ukrywa przyciski „Do Nozbe" w całym systemie.</div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold small" for="nozbe_api_key">Token API</label>
          <input type="text" class="form-control form-control-sm" id="nozbe_api_key" name="nozbe_api_key"
                 autocomplete="off" placeholder="<?= $api_key ? '•••••••• (zapisany — zostaw puste, aby nie zmieniać)' : 'wklej token z Nozbe' ?>">
          <div class="form-text">
            Nozbe → Ustawienia → API tokens → <em>Add new token</em>. Token globalny widzi wszystkie przestrzenie;
            token przestrzeni tylko jedną. Traktuj go jak hasło.
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label fw-semibold small" for="nozbe_project_id">Domyślny projekt</label>
          <?php if ($projects): ?>
          <select class="form-select form-select-sm" id="nozbe_project_id" name="nozbe_project_id">
            <option value="">— skrzynka Nozbe (bez projektu) —</option>
            <?php foreach ($projects as $p): ?>
            <option value="<?= h($p['id']) ?>" <?= $cur_proj === $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Lista pobiera się przy teście połączenia.</div>
          <?php else: ?>
          <input type="text" class="form-control form-control-sm" id="nozbe_project_id" name="nozbe_project_id"
                 value="<?= h($cur_proj) ?>" placeholder="identyfikator projektu (opcjonalnie)">
          <div class="form-text">Uruchom test połączenia, żeby pobrać listę projektów do wyboru.</div>
          <?php endif; ?>
        </div>

        <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz</button>
      </form>

      <hr>
      <div class="d-flex gap-2 flex-wrap">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="test">
          <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug me-1"></i>Testuj połączenie</button>
        </form>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_action" value="send_test">
          <button class="btn btn-outline-secondary btn-sm" <?= nozbe_configured() ? '' : 'disabled' ?>>
            <i class="bi bi-send me-1"></i>Wyślij zadanie testowe
          </button>
        </form>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-body">
      <h2 class="h6 fw-bold mb-2">Ostatnio wysłane do Nozbe</h2>
      <?php if (!$links): ?>
      <p class="text-muted small mb-0">Nic jeszcze nie poszło do Nozbe.</p>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
          <thead><tr>
            <th>Kiedy</th><th>Źródło</th><th>Nazwa</th><th>Kto</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($links as $l): ?>
            <tr>
              <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$l['created_at']))) ?></td>
              <td><?= h($LOCAL_LABELS[$l['local_type']] ?? $l['local_type']) ?></td>
              <td><?= h($l['name']) ?></td>
              <td><?= h($l['user_name'] ?? '—') ?></td>
              <td class="text-end">
                <a class="btn btn-outline-secondary btn-sm py-0 px-2" target="_blank" rel="noopener"
                   href="<?= h(nozbe_task_url((string)$l['nozbe_task_id'])) ?>">Otwórz w Nozbe</a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

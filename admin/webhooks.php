<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/webhooks.php';

require_role('admin');
webhooks_migrate();

$PAGE_TITLE = 'Webhooki';

$AVAILABLE_EVENTS = [
    'contract.created',
    'contract.updated',
    'contract.expired',
    'volunteer.added',
    'task.created',
    'user.registered',
];

$errors  = [];
$success = '';

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $name   = trim($_POST['name']   ?? '');
        $url    = trim($_POST['url']    ?? '');
        $secret = trim($_POST['secret'] ?? '');
        $evts   = array_values(array_intersect($_POST['events'] ?? [], $AVAILABLE_EVENTS));

        if ($name === '')  $errors[] = 'Nazwa jest wymagana.';
        if (!filter_var($url, FILTER_VALIDATE_URL)) $errors[] = 'Nieprawidłowy adres URL.';
        if (empty($evts)) $errors[] = 'Wybierz co najmniej jedno zdarzenie.';

        if (!$errors) {
            if ($secret === '') {
                $secret = bin2hex(random_bytes(20));
            }
            db_insert('webhook_endpoints', [
                'name'     => $name,
                'url'      => $url,
                'secret'   => $secret,
                'events'   => json_encode($evts),
                'is_active'=> 1,
            ]);
            $success = 'Endpoint webhook został dodany.';
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("DELETE FROM webhook_endpoints WHERE id = ?")->execute([$id]);
            db()->prepare("DELETE FROM webhook_log WHERE endpoint_id = ?")->execute([$id]);
            $success = 'Endpoint usunięty.';
        }

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare(
                "UPDATE webhook_endpoints SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END WHERE id = ?"
            )->execute([$id]);
            $success = 'Status endpointu zmieniony.';
        }

    } elseif ($action === 'test') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $ep = db_one("SELECT * FROM webhook_endpoints WHERE id = ?", [$id]);
            if ($ep) {
                // Temporarily force events to include webhook.test by firing directly
                $body = json_encode([
                    'event'     => 'webhook.test',
                    'data'      => ['message' => 'Test webhook z systemu FEER NGO', 'endpoint_id' => $id],
                    'timestamp' => time(),
                ], JSON_UNESCAPED_UNICODE);

                $headers = [
                    'Content-Type: application/json',
                    'X-Event: webhook.test',
                ];
                if (!empty($ep['secret'])) {
                    $headers[] = 'X-Webhook-Secret: ' . $ep['secret'];
                }
                $ctx = stream_context_create([
                    'http' => [
                        'method'        => 'POST',
                        'header'        => implode("\r\n", $headers),
                        'content'       => $body,
                        'timeout'       => 5,
                        'ignore_errors' => true,
                    ],
                ]);
                $resp      = @file_get_contents($ep['url'], false, $ctx);
                $resp_code    = 0;
                $_resp_headers = function_exists('http_get_last_response_headers')
                    ? (http_get_last_response_headers() ?? [])
                    : ($http_response_header ?? []);
                foreach ($_resp_headers as $_rh) {
                    if (preg_match('/HTTP\/\S+\s+(\d+)/', $_rh, $m)) {
                        $resp_code = (int)$m[1];
                        break;
                    }
                }
                $truncated = $resp === false ? '[connection failed]' : substr((string)$resp, 0, 500);
                db_insert('webhook_log', [
                    'endpoint_id'   => $id,
                    'event'         => 'webhook.test',
                    'payload'       => $body,
                    'response_code' => $resp_code,
                    'response_body' => $truncated,
                ]);
                db()->prepare(
                    "UPDATE webhook_endpoints SET last_triggered_at=datetime('now','localtime'), last_status=? WHERE id=?"
                )->execute([$resp_code, $id]);

                if ($resp_code >= 200 && $resp_code < 300) {
                    $success = 'Test wysłany pomyślnie. Odpowiedź: HTTP ' . $resp_code;
                } else {
                    $errors[] = 'Test zakończony. Odpowiedź serwera: HTTP ' . ($resp_code ?: 'brak') . '. Sprawdź logi.';
                }
            }
        }
    }

    if (!$errors && $success) {
        flash_set('success', $success);
        header('Location: ' . APP_URL . '/admin/webhooks.php');
        exit;
    }
}

$endpoints = db_all("SELECT * FROM webhook_endpoints ORDER BY id DESC");

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="d-flex align-items-center justify-content-center flex-shrink-0"
       style="width:48px;height:48px;background:#EFF4FF;border-radius:12px">
    <i class="bi bi-globe2 fs-4" style="color:#1E6DFF"></i>
  </div>
  <div>
    <h4 class="mb-0 fw-bold">Webhooki</h4>
    <div class="text-muted small">Wychodzące powiadomienia HTTP do zewnętrznych systemów</div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible d-flex gap-2 mb-3">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <div>
    <?php foreach ($errors as $e): ?>
      <div><?= h($e) ?></div>
    <?php endforeach; ?>
  </div>
  <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Lista endpointów ────────────────────────────────────────────────────── -->
<?php if ($endpoints): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white border-bottom py-3">
    <h6 class="mb-0 fw-bold"><i class="bi bi-list-ul me-2 text-primary"></i>Skonfigurowane endpointy</h6>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light small">
        <tr>
          <th>Nazwa</th>
          <th>URL</th>
          <th>Zdarzenia</th>
          <th>Ostatnie wywołanie</th>
          <th>Status</th>
          <th>Aktywny</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($endpoints as $ep):
          $ep_events = json_decode($ep['events'] ?? '[]', true) ?: [];
        ?>
        <tr>
          <td class="fw-semibold"><?= h($ep['name']) ?></td>
          <td>
            <code class="small" style="word-break:break-all"><?= h($ep['url']) ?></code>
            <?php if (!empty($ep['secret'])): ?>
            <div class="text-muted" style="font-size:.75rem">
              <i class="bi bi-key me-1"></i><code><?= h(substr($ep['secret'], 0, 8)) ?>…</code>
            </div>
            <?php endif; ?>
          </td>
          <td>
            <?php foreach ($ep_events as $ev): ?>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1 mb-1"
                  style="font-size:.72rem"><?= h($ev) ?></span>
            <?php endforeach; ?>
          </td>
          <td class="text-muted small"><?= $ep['last_triggered_at'] ? h($ep['last_triggered_at']) : '—' ?></td>
          <td>
            <?php if ($ep['last_status'] === null): ?>
              <span class="badge bg-secondary-subtle text-secondary border">brak</span>
            <?php elseif ($ep['last_status'] >= 200 && $ep['last_status'] < 300): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle">
                <i class="bi bi-check-circle me-1"></i><?= $ep['last_status'] ?>
              </span>
            <?php else: ?>
              <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                <i class="bi bi-x-circle me-1"></i><?= $ep['last_status'] ?: 'błąd' ?>
              </span>
            <?php endif; ?>
          </td>
          <td>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
              <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" role="switch"
                       <?= $ep['is_active'] ? 'checked' : '' ?>
                       onchange="this.closest('form').submit()"
                       style="width:2em;height:1em">
              </div>
            </form>
          </td>
          <td class="text-end">
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="test">
              <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-primary me-1"
                      title="Wyślij test">
                <i class="bi bi-send"></i>
              </button>
            </form>
            <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                    data-bs-toggle="collapse"
                    data-bs-target="#logs-<?= (int)$ep['id'] ?>"
                    title="Pokaż logi">
              <i class="bi bi-journal-text"></i>
            </button>
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Usunąć endpoint i wszystkie jego logi?')">
              <?= csrf_field() ?>
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$ep['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" title="Usuń">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <!-- Log viewer row -->
        <tr class="collapse" id="logs-<?= (int)$ep['id'] ?>">
          <td colspan="7" class="p-0">
            <?php
            $logs = db_all(
                "SELECT * FROM webhook_log WHERE endpoint_id = ? ORDER BY id DESC LIMIT 20",
                [(int)$ep['id']]
            );
            ?>
            <div class="p-3 bg-light border-top">
              <h6 class="fw-semibold mb-2 small">
                <i class="bi bi-journal-text me-1"></i>Ostatnie 20 wywołań — <?= h($ep['name']) ?>
              </h6>
              <?php if (!$logs): ?>
                <div class="text-muted small">Brak wpisów w logu.</div>
              <?php else: ?>
              <div class="table-responsive">
                <table class="table table-sm table-striped small mb-0">
                  <thead>
                    <tr>
                      <th>Data</th>
                      <th>Zdarzenie</th>
                      <th>HTTP</th>
                      <th>Odpowiedź</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                      <td class="text-nowrap"><?= h($log['triggered_at']) ?></td>
                      <td><code><?= h($log['event']) ?></code></td>
                      <td>
                        <?php if ($log['response_code'] >= 200 && $log['response_code'] < 300): ?>
                          <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $log['response_code'] ?></span>
                        <?php else: ?>
                          <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= $log['response_code'] ?: '0' ?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <code class="small" style="word-break:break-all"><?= h($log['response_body']) ?></code>
                      </td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="alert alert-info d-flex gap-2 mb-4">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>Brak skonfigurowanych endpointów webhook. Dodaj pierwszy poniżej.</div>
</div>
<?php endif; ?>

<!-- ── Formularz dodawania ─────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white border-bottom py-3">
    <h6 class="mb-0 fw-bold"><i class="bi bi-plus-circle me-2 text-success"></i>Dodaj nowy endpoint</h6>
  </div>
  <div class="card-body">
    <form method="post" id="addWebhookForm" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="_action" value="create">

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
          <input type="text" name="name" class="form-control"
                 placeholder="np. Zapier, Slack, CRM zewnętrzny" required
                 value="<?= h($_POST['name'] ?? '') ?>">
        </div>
        <div class="col-md-5">
          <label class="form-label fw-semibold">URL endpointu <span class="text-danger">*</span></label>
          <input type="url" name="url" class="form-control"
                 placeholder="https://hooks.zapier.com/hooks/catch/…" required
                 value="<?= h($_POST['url'] ?? '') ?>">
          <div class="form-text">Musi zaczynać się od https://</div>
        </div>
        <div class="col-md-3">
          <label class="form-label fw-semibold">
            Sekret
            <span class="text-muted fw-normal small">(opcjonalny)</span>
          </label>
          <input type="text" name="secret" class="form-control font-monospace"
                 placeholder="auto-generowany jeśli puste"
                 value="<?= h($_POST['secret'] ?? '') ?>">
          <div class="form-text">Wysyłany jako nagłówek <code>X-Webhook-Secret</code></div>
        </div>
      </div>

      <div class="mt-3">
        <label class="form-label fw-semibold">Zdarzenia <span class="text-danger">*</span></label>
        <div class="d-flex flex-wrap gap-3">
          <?php
          $posted_events = $_POST['events'] ?? [];
          $event_labels = [
              'contract.created'  => ['primary', 'Umowa dodana'],
              'contract.updated'  => ['info',    'Umowa zaktualizowana'],
              'contract.expired'  => ['warning', 'Umowa wygasła'],
              'volunteer.added'   => ['success', 'Wolontariusz dodany'],
              'task.created'      => ['secondary','Zadanie dodane'],
              'user.registered'   => ['dark',    'Użytkownik zarejestrowany'],
          ];
          foreach ($AVAILABLE_EVENTS as $ev):
            $checked  = in_array($ev, $posted_events, true) ? 'checked' : '';
            $color    = $event_labels[$ev][0] ?? 'secondary';
            $label    = $event_labels[$ev][1] ?? $ev;
          ?>
          <div class="form-check">
            <input class="form-check-input" type="checkbox"
                   name="events[]" value="<?= h($ev) ?>"
                   id="ev_<?= str_replace('.', '_', $ev) ?>"
                   <?= $checked ?>>
            <label class="form-check-label" for="ev_<?= str_replace('.', '_', $ev) ?>">
              <span class="badge bg-<?= $color ?>-subtle text-<?= $color ?> border border-<?= $color ?>-subtle">
                <?= h($ev) ?>
              </span>
              <span class="small ms-1"><?= h($label) ?></span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-success px-4">
          <i class="bi bi-plus-circle me-1"></i>Dodaj endpoint
        </button>
        <a href="<?= APP_URL ?>/admin/webhooks.php" class="btn btn-outline-secondary">Anuluj</a>
      </div>
    </form>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

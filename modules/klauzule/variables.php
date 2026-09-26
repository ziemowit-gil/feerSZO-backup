<?php
/**
 * modules/klauzule/variables.php — zmienne globalne klauzul RODO
 * (tabela global_variables). Jeden formularz na wszystkie wartości —
 * szybka zmiana adresu/nazwy/IOD obowiązuje natychmiast w każdej klauzuli.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/klauzule.php';

require_role('admin', 'editor');
$svc  = new GdprClauseService();
$self = APP_URL . '/modules/klauzule/variables.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';
    try {
        if ($action === 'save_all') {
            $existing = array_column($svc->listVariables(), null, 'key_');
            foreach ((array)($_POST['vars'] ?? []) as $key => $row) {
                $key = (string)$key;
                if (!isset($existing[$key])) continue;
                $svc->saveVariable($key, trim((string)($row['value'] ?? '')), trim((string)($row['label'] ?? '')),
                                   (int)$existing[$key]['sort_order']);
            }
            flash_set('success', 'Zmienne zapisane — klauzule pokazują już nowe wartości.');
        } elseif ($action === 'add') {
            $key = strtolower(trim((string)($_POST['key'] ?? '')));
            if (array_key_exists($key, $svc->variables())) {
                throw new InvalidArgumentException("Zmienna {{{$key}}} już istnieje.");
            }
            $svc->saveVariable($key, trim((string)($_POST['value'] ?? '')), trim((string)($_POST['label'] ?? '')));
            flash_set('success', "Dodano zmienną {{{$key}}}.");
        } elseif ($action === 'delete') {
            $key = (string)($_POST['key'] ?? '');
            $svc->deleteVariable($key);
            flash_set('warning', "Usunięto {{{$key}}}. Klauzule, które jej używają, pokażą w tym miejscu pusty tekst.");
        }
    } catch (InvalidArgumentException $e) {
        flash_set('danger', $e->getMessage() . ' Klucz: małe litery, cyfry i „_", zaczyna się od litery (np. dpo_phone).');
    }
    header('Location: ' . $self);
    exit;
}

$vars = $svc->listVariables();

// Gdzie używana jest dana zmienna — żeby przed usunięciem było widać skutki.
$usage = [];
foreach ($svc->listClauses() as $c) {
    $full = $svc->getById((int)$c['id']);
    preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/i', $full['content'] ?? '', $m);
    foreach (array_unique(array_map('strtolower', $m[1])) as $k) $usage[$k][] = $c['tytul'];
}

$PAGE_TITLE = 'Klauzule RODO — zmienne globalne';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-braces text-primary"></i> Zmienne globalne klauzul</h4>
  <a href="<?= APP_URL ?>/modules/klauzule/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Klauzule</a>
</div>

<?= flash_html() ?>

<form method="post" class="card shadow-sm mb-4">
  <?= csrf_field() ?>
  <input type="hidden" name="_action" value="save_all">
  <div class="table-responsive">
    <table class="table align-middle mb-0">
      <thead class="table-light">
        <tr><th style="width:18%">Tag</th><th style="width:25%">Opis</th><th>Wartość</th><th style="width:1%"></th></tr>
      </thead>
      <tbody>
      <?php foreach ($vars as $v): $k = $v['key_']; ?>
        <tr>
          <td>
            <code class="user-select-all">{{<?= h($k) ?>}}</code>
            <?php if (!empty($usage[$k])): ?>
              <div class="small text-muted" title="<?= h(implode(', ', $usage[$k])) ?>">w <?= count($usage[$k]) ?> klauz.</div>
            <?php endif; ?>
          </td>
          <td><input type="text" class="form-control form-control-sm" name="vars[<?= h($k) ?>][label]" value="<?= h($v['label']) ?>"></td>
          <td>
            <textarea class="form-control form-control-sm" rows="<?= str_contains($v['value'], "\n") ? 3 : 1 ?>"
                      name="vars[<?= h($k) ?>][value]" aria-label="Wartość {{<?= h($k) ?>}}"><?= h($v['value']) ?></textarea>
          </td>
          <td>
            <button type="submit" form="del-<?= h($k) ?>" class="btn btn-sm btn-outline-danger" title="Usuń zmienną"><i class="bi bi-trash"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer bg-white d-flex justify-content-between align-items-center">
    <span class="small text-muted">Wbudowane (bez definiowania): <code>{{updated_at}}</code> — data zmiany klauzuli, <code>{{today}}</code> — dzisiejsza data.</span>
    <button class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Zapisz wszystkie</button>
  </div>
</form>

<?php foreach ($vars as $v): $k = $v['key_']; ?>
<form method="post" id="del-<?= h($k) ?>" class="d-none"
      onsubmit="return confirm('Usunąć zmienną {{<?= h($k) ?>}}?<?= !empty($usage[$k]) ? ' Używa jej ' . count($usage[$k]) . ' klauzul(a) — pojawi się tam pusty tekst.' : '' ?>');">
  <?= csrf_field() ?>
  <input type="hidden" name="_action" value="delete">
  <input type="hidden" name="key" value="<?= h($k) ?>">
</form>
<?php endforeach; ?>

<form method="post" class="card shadow-sm">
  <?= csrf_field() ?>
  <input type="hidden" name="_action" value="add">
  <div class="card-header bg-white fw-semibold small"><i class="bi bi-plus-lg me-1"></i>Nowa zmienna</div>
  <div class="card-body row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label small mb-1">Klucz</label>
      <div class="input-group input-group-sm">
        <span class="input-group-text">{{</span>
        <input type="text" name="key" class="form-control" required pattern="[a-z][a-z0-9_]{0,63}" placeholder="dpo_phone">
        <span class="input-group-text">}}</span>
      </div>
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1">Opis</label>
      <input type="text" name="label" class="form-control form-control-sm" placeholder="Telefon do IOD">
    </div>
    <div class="col-md-4">
      <label class="form-label small mb-1">Wartość</label>
      <input type="text" name="value" class="form-control form-control-sm">
    </div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Dodaj</button></div>
  </div>
</form>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>

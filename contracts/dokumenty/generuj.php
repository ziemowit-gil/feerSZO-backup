<?php
/**
 * contracts/dokumenty/generuj.php
 * Krok 1 — generuje edytowalny dokument z wzorca dla wskazanej umowy
 * i przekierowuje do live edycji (edytuj.php).
 *
 * Gdy w treści wzorca użyto placeholderów, dla których dane źródłowej umowy
 * są puste (np. brak adresu, PESEL), zamiast generować dokument z dziurami
 * pokazuje krótki formularz z prośbą o ich uzupełnienie.
 *
 * POST params: template_id, contract_type, contract_id
 *              confirmed=1, override[{tag}]=... (drugi krok, po uzupełnieniu)
 */
if (!defined('APP_INSTALLED')) require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/contract_document_engine.php';

require_login();
if (!can_edit()) { http_response_code(403); exit('Brak uprawnień.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Metoda niedozwolona.'); }
csrf_check();

$template_id   = (int)($_POST['template_id'] ?? 0);
$contract_type = $_POST['contract_type'] ?? '';
$contract_id   = (int)($_POST['contract_id'] ?? 0);
$view_url      = APP_URL . '/contracts/' . $contract_type . '/view.php?id=' . $contract_id . '&tab=docs';

if (!$template_id || !$contract_id || !array_key_exists($contract_type, CGD_CONTRACT_TABLES)) {
    http_response_code(400); exit('Nieprawidłowe parametry.');
}

$tpl = cte_get($template_id);
if (!$tpl) {
    flash_set('error', 'Nie udało się wygenerować dokumentu — wzorzec nie istnieje lub jest nieaktywny.');
    header('Location: ' . $view_url); exit;
}

$row = cgd_source_row($contract_type, $contract_id);
$map = cte_build_map($contract_type, $row);

$overrides = [];
if (isset($_POST['confirmed'])) {
    foreach ((array)($_POST['override'] ?? []) as $tag => $val) {
        $overrides[$tag] = trim((string)$val);
    }
    $map = array_merge($map, $overrides);
}

$missing = cgd_missing_placeholders($tpl['body'], $map);

if ($missing) {
    $PAGE_TITLE = 'Uzupełnij dane — ' . $tpl['name'];
    include dirname(dirname(__DIR__)) . '/includes/header.php';
    ?>
    <div class="d-flex align-items-center gap-2 mb-3">
      <a href="<?= h($view_url) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
      <h5 class="mb-0 fw-bold">
        <i class="bi bi-exclamation-triangle text-warning me-1"></i>Uzupełnij brakujące dane
      </h5>
    </div>
    <p class="text-muted small">
      We wzorze „<strong><?= h($tpl['name']) ?></strong>" użyto zmiennych, dla których nie ma danych
      w tej umowie. Uzupełnij je poniżej — trafią tylko do tego dokumentu (nie zmieniają danych umowy).
    </p>
    <form method="post" class="card shadow-sm" style="max-width:640px">
      <div class="card-body">
        <input type="hidden" name="_csrf"         value="<?= csrf_token() ?>">
        <input type="hidden" name="template_id"   value="<?= $template_id ?>">
        <input type="hidden" name="contract_type" value="<?= h($contract_type) ?>">
        <input type="hidden" name="contract_id"   value="<?= $contract_id ?>">
        <input type="hidden" name="confirmed"     value="1">
        <?php foreach ($missing as $tag => $desc): $_fid = 'ov_' . preg_replace('/[^a-z0-9]/i', '', $tag); ?>
        <div class="mb-3">
          <label class="form-label small fw-semibold" for="<?= $_fid ?>">
            <?= h($desc) ?> <code class="text-muted"><?= h($tag) ?></code>
          </label>
          <div class="input-group input-group-sm">
            <input type="text" id="<?= $_fid ?>" name="override[<?= h($tag) ?>]" class="form-control"
                   value="<?= h($overrides[$tag] ?? '') ?>">
            <button type="button" class="btn btn-outline-secondary"
                    onclick="document.getElementById('<?= $_fid ?>').value=<?= json_encode(CGD_NO_DATA_LABEL) ?>">
              Brak danych w systemie
            </button>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="d-flex justify-content-end gap-2">
          <a href="<?= h($view_url) ?>" class="btn btn-sm btn-outline-secondary">Anuluj</a>
          <button type="submit" class="btn btn-sm btn-primary">
            <i class="bi bi-arrow-right me-1"></i>Dalej — wygeneruj dokument
          </button>
        </div>
      </div>
    </form>
    <?php
    include dirname(dirname(__DIR__)) . '/includes/footer.php';
    exit;
}

$doc_id = cgd_create($template_id, $contract_type, $contract_id, (int)current_user()['id'], $overrides);
if (!$doc_id) {
    flash_set('error', 'Nie udało się wygenerować dokumentu.');
    header('Location: ' . $view_url); exit;
}

header('Location: ' . APP_URL . '/contracts/dokumenty/edytuj.php?id=' . $doc_id);
exit;

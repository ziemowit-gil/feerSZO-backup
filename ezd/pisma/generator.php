<?php
/**
 * ezd/pisma/generator.php — generator pisma z szablonu.
 *
 * Działa w dwóch trybach:
 *   ?sprawa_id=X  → kontekst koszulki (tokeny sprawy dostępne)
 *   (brak)        → tryb standalone; tokeny sprawy puste
 *
 * Operacje POST:
 *   _op=preview   → podgląd wyrenderowanej treści
 *   _op=docx      → generuje i pobiera DOCX (forward do docx.php)
 *   _op=create    → tworzy pismo w DB i przekierowuje do view
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$uid       = (int)current_user()['id'];
$sprawa_id = (int)($_REQUEST['sprawa_id'] ?? 0);
$sprawa    = $sprawa_id ? ezd_sprawa_get($sprawa_id) : null;

if ($sprawa_id && (!$sprawa || !ezd_sprawa_access($sprawa, $uid))) {
    flash_set('error', 'Brak dostępu do koszulki.');
    header('Location: ' . APP_URL . '/ezd/index.php'); exit;
}

$szablony = ezd_szablony_all(true);
$errors   = [];

// ── POST ─────────────────────────────────────────────────────────────────────
$op         = '';
$szablon_id = 0;
$odbiorca   = '';
$znak_obcy  = '';
$preview_title = '';
$preview_tresc = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op         = $_POST['_op']        ?? '';
    $szablon_id = (int)($_POST['szablon_id'] ?? 0);
    $odbiorca   = trim($_POST['odbiorca']   ?? '');
    $znak_obcy  = trim($_POST['znak_obcy']  ?? '');

    $sz = $szablon_id ? ezd_szablon_get($szablon_id) : null;
    if (!$sz) $errors[] = 'Wybierz szablon.';

    if (!$errors) {
        $ctx           = ezd_szablon_context($sprawa, ['odbiorca' => $odbiorca, 'znak_obcy' => $znak_obcy]);
        $preview_title = ezd_szablon_render($sz['tytul_wzor'], $ctx) ?: $sz['nazwa'];
        $preview_tresc = ezd_szablon_render($sz['tresc_wzor'], $ctx);

        if ($op === 'docx') {
            // Przekaż do docx.php przez POST (hidden form submit) — robimy tu forward
            // Ustawiamy token w sesji i przekazujemy go do docx.php
            $token = bin2hex(random_bytes(16));
            $_SESSION['ezd_docx_gen_' . $token] = [
                'szablon_id' => $szablon_id,
                'sprawa_id'  => $sprawa_id,
                'odbiorca'   => $odbiorca,
                'znak_obcy'  => $znak_obcy,
                'expires'    => time() + 120,
            ];
            header('Location: ' . APP_URL . '/ezd/pisma/docx.php?_gen=' . $token);
            exit;
        }

        if ($op === 'create') {
            if ($sprawa_id && ezd_sprawa_access($sprawa, $uid) !== 'write') {
                $errors[] = 'Brak uprawnień do tworzenia pism w tej koszulce.';
            } elseif (!$sprawa_id) {
                $errors[] = 'Wybierz koszulkę, aby utworzyć pismo.';
            }
            if (!$errors) {
                $new_id = ezd_pismo_create([
                    'sprawa_id'     => $sprawa_id,
                    'kierunek'      => $sz['kierunek'] ?: 'wychodzace',
                    'title'         => $preview_title,
                    'tresc'         => $preview_tresc,
                    'odbiorca'      => $odbiorca,
                    'nadawca'       => (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '')),
                    'data_pisma'    => date('Y-m-d'),
                    'data_wysylki'  => '',
                    'status'        => 'nowe',
                    'owner_id'      => $uid,
                    'rodzaj_medium' => $sz['rodzaj_medium'] ?: 'papier',
                ], $uid);
                flash_set('success', 'Pismo utworzone z szablonu „' . $sz['nazwa'] . '".');
                header('Location: ' . APP_URL . '/ezd/pisma/view.php?id=' . $new_id
                    . ($sprawa_id ? '&from_sprawa=' . $sprawa_id : ''));
                exit;
            }
        }
        // _op=preview — kontynuuj i renderuj podgląd poniżej
    }
}

// ── Wybrany szablon z URL (GET prefill) ───────────────────────────────────────
if (!$szablon_id && ($gszid = (int)($_GET['szablon_id'] ?? 0))) $szablon_id = $gszid;

$PAGE_TITLE = 'Generator pisma' . ($sprawa ? ' — ' . $sprawa['znak_sprawy'] : '');
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <?php if ($sprawa): ?>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($sprawa['znak_sprawy']) ?></a></li>
  <?php endif; ?>
  <li class="breadcrumb-item active">Generator pisma</li>
</ol></nav>

<?= flash_html() ?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-word text-primary me-2"></i>Generator pisma</h4>
  <?php if ($sprawa): ?>
  <span class="badge bg-info bg-opacity-15 text-info border border-info" style="font-size:.8rem">
    <i class="bi bi-folder2 me-1"></i><?= h($sprawa['znak_sprawy']) ?> — <?= h(mb_substr($sprawa['title'], 0, 50)) ?>
  </span>
  <?php else: ?>
  <span class="badge bg-secondary bg-opacity-10 text-secondary border" style="font-size:.78rem">
    <i class="bi bi-file-earmark me-1"></i>Nowy dokument (bez koszulki)
  </span>
  <?php endif; ?>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>• ' . h($e) . '</div>'; ?></div>
<?php endif; ?>

<div class="row g-4">
  <!-- Formularz -->
  <div class="col-lg-5">
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.85rem">
        <i class="bi bi-ui-checks me-1 text-primary"></i>Parametry dokumentu
      </div>
      <form method="post" id="genForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="sprawa_id" value="<?= $sprawa_id ?>">
        <div class="card-body">

          <!-- Wybór szablonu -->
          <?php if (!$szablony): ?>
          <div class="alert alert-warning mb-3" style="font-size:.82rem">
            Brak aktywnych szablonów. <a href="<?= APP_URL ?>/admin/ezd_szablony.php">Dodaj szablon</a>.
          </div>
          <?php else: ?>
          <div class="mb-3">
            <label class="form-label fw-semibold">Szablon <span class="text-danger">*</span></label>
            <select name="szablon_id" id="szablon_id" class="form-select" required onchange="document.getElementById('genOp').value='preview';document.getElementById('genForm').requestSubmit()">
              <option value="">— wybierz szablon —</option>
              <?php foreach ($szablony as $s):
                $grp = EZD_SZABLON_KATEGORIE[$s['kategoria']] ?? $s['kategoria']; ?>
              <option value="<?= (int)$s['id'] ?>"
                      data-kat="<?= h($grp) ?>"
                      <?= (int)$s['id'] === $szablon_id ? 'selected' : '' ?>><?= h($s['nazwa']) ?>
                <?php if ($s['opis']): ?>(<?= h($s['opis']) ?>)<?php endif; ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>

          <!-- Adresat -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Adresat / Odbiorca
              <span class="text-muted fw-normal" style="font-size:.78rem">— token {{odbiorca}}</span>
            </label>
            <textarea name="odbiorca" class="form-control form-control-sm font-monospace" rows="4"
              style="font-size:.8rem"
              placeholder="Imię Nazwisko / nazwa firmy&#10;ul. Ulicowa 1&#10;00-000 Miejscowość"><?= h($odbiorca) ?></textarea>
          </div>

          <!-- Znak obcy -->
          <div class="mb-3">
            <label class="form-label fw-semibold">Znak pisma adresata
              <span class="text-muted fw-normal" style="font-size:.78rem">— token {{znak_obcy}}</span>
            </label>
            <input type="text" name="znak_obcy" class="form-control form-control-sm" value="<?= h($znak_obcy) ?>"
                   placeholder="np. ABC/123/2026">
          </div>

          <?php if (!$sprawa_id): ?>
          <!-- Tryb standalone — info -->
          <div class="alert alert-secondary py-2 mb-0" style="font-size:.78rem">
            <i class="bi bi-info-circle me-1"></i>Tokeny sprawy ({{znak_sprawy}}, {{referent}} itd.) będą puste,
            bo nie wybrano koszulki.
            <a href="<?= APP_URL ?>/ezd/sprawy/index.php" class="alert-link">Wybierz koszulkę</a>, jeśli potrzebujesz.
          </div>
          <?php endif; ?>
        </div>
        <div class="card-footer d-flex gap-2 flex-wrap">
          <input type="hidden" name="_op" id="genOp" value="preview">
          <button type="submit" class="btn btn-outline-primary btn-sm"
                  onclick="document.getElementById('genOp').value='preview'">
            <i class="bi bi-eye me-1"></i>Podgląd
          </button>
          <button type="submit" class="btn btn-success btn-sm"
                  onclick="document.getElementById('genOp').value='docx'"
                  <?= !$szablony ? 'disabled' : '' ?>>
            <i class="bi bi-file-earmark-word me-1"></i>Pobierz DOCX
          </button>
          <?php if ($sprawa): ?>
          <button type="submit" class="btn btn-info btn-sm text-white"
                  onclick="document.getElementById('genOp').value='create'"
                  <?= !$szablony ? 'disabled' : '' ?>>
            <i class="bi bi-plus-circle me-1"></i>Utwórz pismo w koszulce
          </button>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <!-- Tokeny pomocnicze -->
    <?php if ($szablony): ?>
    <details class="mt-3">
      <summary class="fw-semibold small text-primary" style="cursor:pointer">
        <i class="bi bi-braces me-1"></i>Dostępne tokeny szablonu
      </summary>
      <div class="mt-2 small card p-2">
        <?php foreach (EZD_SZABLON_TOKENY as $grupa => $toks): ?>
        <div class="mb-1"><span class="text-muted"><?= h($grupa) ?>:</span>
          <?php foreach ($toks as $tk => $desc): ?>
          <code class="me-1" title="<?= h($desc) ?>"
                style="background:#f1f5f9;padding:1px 4px;border-radius:3px">{{<?= $tk ?>}}</code>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </details>
    <?php endif; ?>
  </div>

  <!-- Podgląd -->
  <div class="col-lg-7">
    <?php if ($op === 'preview' && !$errors && $preview_title !== ''): ?>
    <div class="card shadow-sm border-success">
      <div class="card-header fw-semibold bg-success bg-opacity-10" style="font-size:.85rem">
        <i class="bi bi-eye me-1 text-success"></i>Podgląd wyrenderowanej treści
      </div>
      <div class="card-body">
        <div class="fw-bold text-center mb-3 text-uppercase" style="font-size:1rem;letter-spacing:.04em">
          <?= h($preview_title) ?>
        </div>
        <?php if ($preview_tresc): ?>
        <div class="border rounded p-3 bg-light" style="font-size:.84rem;white-space:pre-wrap;font-family:Calibri,serif;min-height:8rem"><?= h($preview_tresc) ?></div>
        <?php else: ?>
        <div class="text-muted text-center py-3" style="font-size:.84rem">Szablon nie ma wzoru treści.</div>
        <?php endif; ?>
      </div>
      <div class="card-footer d-flex gap-2">
        <form method="post">
          <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
          <input type="hidden" name="sprawa_id"  value="<?= $sprawa_id ?>">
          <input type="hidden" name="szablon_id" value="<?= $szablon_id ?>">
          <input type="hidden" name="odbiorca"   value="<?= h($odbiorca) ?>">
          <input type="hidden" name="znak_obcy"  value="<?= h($znak_obcy) ?>">
          <button type="submit" name="_op" value="docx" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-word me-1"></i>Pobierz DOCX
          </button>
        </form>
        <?php if ($sprawa): ?>
        <form method="post">
          <input type="hidden" name="_csrf"      value="<?= csrf_token() ?>">
          <input type="hidden" name="sprawa_id"  value="<?= $sprawa_id ?>">
          <input type="hidden" name="szablon_id" value="<?= $szablon_id ?>">
          <input type="hidden" name="odbiorca"   value="<?= h($odbiorca) ?>">
          <input type="hidden" name="znak_obcy"  value="<?= h($znak_obcy) ?>">
          <button type="submit" name="_op" value="create" class="btn btn-info btn-sm text-white">
            <i class="bi bi-plus-circle me-1"></i>Utwórz pismo w koszulce
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="card shadow-sm h-100 d-flex align-items-center justify-content-center"
         style="min-height:200px;border-style:dashed">
      <div class="text-center text-muted p-4">
        <i class="bi bi-file-earmark-word fs-1 d-block mb-2 text-primary opacity-25"></i>
        <div style="font-size:.84rem">Wybierz szablon i kliknij <strong>Podgląd</strong>,<br>aby zobaczyć wyrenderowaną treść.</div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

<?php
/**
 * tools/oplacalnosc.php — Kalkulator opłacalności działań (SZO FEER).
 *
 * Narzędzie dostępne po zalogowaniu (minimum: is_viewer).
 * Obsługuje POST (przeładowanie strony) oraz AJAX (GET/POST z _ajax=1 → JSON).
 *
 * Logika w: includes/oplacalnosc.php
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/oplacalnosc.php';

require_login();

$PAGE_TITLE = 'Kalkulator opłacalności działań';

/* ── Obsługa AJAX (live-calc z formularza JS) ───────────────────────────── */
$is_ajax = (($_GET['_ajax'] ?? '') === '1')
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($is_ajax && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p    = _opl_params_from_post();
    $errs = oplacalnosc_validate($p);
    if ($errs) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'errors' => $errs]);
        exit;
    }
    $r = oplacalnosc_oblicz($p);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'html' => _opl_render_result($r)]);
    exit;
}

/* ── POST — obliczenie po kliknięciu ────────────────────────────────────── */
$result = null;
$errors = [];
$params = _opl_params_defaults();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $params = _opl_params_from_post();
    $errors = oplacalnosc_validate($params);
    if (!$errors) {
        $result = oplacalnosc_oblicz($params);
    }
}

/* ── Pomocnicze ─────────────────────────────────────────────────────────── */

function _opl_params_defaults(): array {
    return [
        'tryb'         => 'online',
        'przychod'     => '',
        'czas_pracy'   => '',
        'stawka'       => '',
        'czas_dojazdu' => '',
        'bilety'       => '',
        'model_zus'    => 'zlecenie',
    ];
}

function _opl_params_from_post(): array {
    $clean = fn(string $k) => trim($_POST[$k] ?? '');
    return [
        'tryb'         => ($clean('tryb') === 'wyjazdowy') ? 'wyjazdowy' : 'online',
        'przychod'     => str_replace(',', '.', $clean('przychod')),
        'czas_pracy'   => str_replace(',', '.', $clean('czas_pracy')),
        'stawka'       => str_replace(',', '.', $clean('stawka')),
        'czas_dojazdu' => str_replace(',', '.', $clean('czas_dojazdu')),
        'bilety'       => str_replace(',', '.', $clean('bilety')),
        'model_zus'    => array_key_exists($clean('model_zus'), OPLACALNOSC_TAX_MODELS)
                          ? $clean('model_zus')
                          : 'zlecenie',
    ];
}

function _opl_field(string $label, float $amount, string $class = ''): string {
    $cls = $class ? " {$class}" : '';
    return '<tr><td class="text-muted">' . htmlspecialchars($label) . '</td>'
         . '<td class="text-end fw-semibold' . $cls . '">' . money($amount) . '</td></tr>';
}

/* ══ HTML ════════════════════════════════════════════════════════════════════ */
include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-xl py-4">
  <div class="page-header d-print-none mb-4">
    <div class="row align-items-center">
      <div class="col">
        <h2 class="page-title"><?= htmlspecialchars($PAGE_TITLE) ?></h2>
        <p class="text-muted mt-1 mb-0">
          Ocena opłacalności działań zewnętrznych na podstawie trybu realizacji,
          czasu i oferowanego przychodu.
        </p>
      </div>
    </div>
  </div>

  <div class="row g-4">
    <!-- ── Formularz ─────────────────────────────────────────────── -->
    <div class="col-lg-5">
      <div class="card shadow-sm h-100">
        <div class="card-header">
          <h3 class="card-title mb-0">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18"
                 class="me-2 text-primary" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 11h.01M12 11h.01
                       M15 11h.01M4 19h16a2 2 0 002-2V7a2 2 0 00-2-2H4
                       a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
            Parametry zlecenia
          </h3>
        </div>

        <div class="card-body">
          <?php if ($errors): ?>
            <div class="alert alert-danger alert-dismissible" role="alert">
              <ul class="mb-0 ps-3">
                <?php foreach ($errors as $e): ?>
                  <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
              </ul>
              <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
          <?php endif; ?>

          <form method="post" id="oplForm" autocomplete="off">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

            <!-- Tryb -->
            <div class="mb-4">
              <label class="form-label fw-semibold">Tryb realizacji</label>
              <div class="btn-group w-100" role="group">
                <input type="radio" class="btn-check" name="tryb" id="tryb_online"
                       value="online" <?= $params['tryb'] === 'online' ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="tryb_online">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                       class="me-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5
                             13V7a2 2 0 012-2h10a2 2 0 012 2v6"/>
                  </svg>
                  Online
                </label>
                <input type="radio" class="btn-check" name="tryb" id="tryb_wyjazd"
                       value="wyjazdowy" <?= $params['tryb'] === 'wyjazdowy' ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="tryb_wyjazd">
                  <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                       class="me-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4
                             4m-4-4l4-4"/>
                  </svg>
                  Wyjazdowy
                </label>
              </div>
            </div>

            <!-- Wspólne -->
            <div class="mb-3">
              <label for="przychod" class="form-label">Oferowany przychód (PLN brutto)</label>
              <div class="input-group">
                <input type="number" id="przychod" name="przychod" class="form-control"
                       min="0" step="0.01" placeholder="np. 2000"
                       value="<?= htmlspecialchars($params['przychod']) ?>" required>
                <span class="input-group-text">zł</span>
              </div>
            </div>

            <div class="mb-3">
              <label for="czas_pracy" class="form-label">Czas pracy (godziny)</label>
              <div class="input-group">
                <input type="number" id="czas_pracy" name="czas_pracy" class="form-control"
                       min="0" step="0.25" placeholder="np. 4"
                       value="<?= htmlspecialchars($params['czas_pracy']) ?>" required>
                <span class="input-group-text">h</span>
              </div>
              <div class="form-text opl-online-hint">
                Stawka online: <strong><?= money(OPLACALNOSC_ONLINE_RATE) ?>/h</strong>
                + platforma <?= money(OPLACALNOSC_ONLINE_PLATFORM) ?> (stała)
              </div>
            </div>

            <!-- Sekcja tylko wyjazdowa -->
            <div id="oplWyjazdFields" class="<?= $params['tryb'] === 'wyjazdowy' ? '' : 'd-none' ?>">
              <hr class="my-3">
              <p class="small text-muted mb-2 fw-semibold text-uppercase ls-wide">
                Parametry wyjazdu
              </p>

              <div class="mb-3">
                <label for="stawka" class="form-label">Stawka robocza (zł/h)</label>
                <div class="input-group">
                  <input type="number" id="stawka" name="stawka" class="form-control"
                         min="0" step="0.5" placeholder="np. 80"
                         value="<?= htmlspecialchars($params['stawka']) ?>">
                  <span class="input-group-text">zł/h</span>
                </div>
              </div>

              <div class="mb-3">
                <label for="czas_dojazdu" class="form-label">Czas dojazdu (godziny)</label>
                <div class="input-group">
                  <input type="number" id="czas_dojazdu" name="czas_dojazdu" class="form-control"
                         min="0" step="0.25" placeholder="np. 1.5"
                         value="<?= htmlspecialchars($params['czas_dojazdu']) ?>">
                  <span class="input-group-text">h</span>
                </div>
                <div class="form-text">
                  Wycena czasu dojazdu: <?= money(OPLACALNOSC_TRAVEL_TIME_RATE) ?>/h
                  + baza wyjazdu: <?= money(OPLACALNOSC_TRAVEL_BASE) ?>
                </div>
              </div>

              <div class="mb-3">
                <label for="bilety" class="form-label">Koszty biletów / transportu</label>
                <div class="input-group">
                  <input type="number" id="bilety" name="bilety" class="form-control"
                         min="0" step="0.01" placeholder="0"
                         value="<?= htmlspecialchars($params['bilety']) ?>">
                  <span class="input-group-text">zł</span>
                </div>
              </div>
            </div>

            <hr class="my-3">
            <!-- Model obciążeń -->
            <div class="mb-4">
              <label for="model_zus" class="form-label fw-semibold">
                Model obciążeń publicznoprawnych
              </label>
              <select id="model_zus" name="model_zus" class="form-select">
                <?php foreach (OPLACALNOSC_TAX_MODELS as $k => $m): ?>
                  <option value="<?= $k ?>" <?= $params['model_zus'] === $k ? 'selected' : '' ?>>
                    <?= htmlspecialchars($m['label']) ?> — <?= htmlspecialchars($m['note']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <button type="submit" class="btn btn-primary w-100" id="oplSubmit">
              <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16"
                   class="me-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round"
                      d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2
                         0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2
                         2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0
                         0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2
                         a2 2 0 01-2-2z"/>
              </svg>
              Oblicz opłacalność
            </button>
          </form>
        </div>
      </div><!-- /card -->
    </div><!-- /col formularz -->

    <!-- ── Wyniki ──────────────────────────────────────────────────── -->
    <div class="col-lg-7" id="oplResultCol">
      <?php if ($result): ?>
        <?= _opl_render_result($result) ?>
      <?php else: ?>
        <div class="card shadow-sm h-100 d-flex align-items-center justify-content-center
                    text-muted" style="min-height:320px" id="oplPlaceholder">
          <div class="text-center p-4">
            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48"
                 class="mb-3 opacity-50" fill="none" viewBox="0 0 24 24"
                 stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4
                       4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"/>
            </svg>
            <p class="mb-0">Wypełnij formularz i kliknij <strong>Oblicz</strong>,<br>
               aby zobaczyć raport opłacalności.</p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div><!-- /row -->
</div><!-- /container -->

<?php
/* ═══════════════════════════════════════════════════════════════════════════
   Renderer fragmentu HTML wyników — używany też przy AJAX (JSON → JS inject)
   ═══════════════════════════════════════════════════════════════════════════ */
function _opl_render_result(array $r): string {
    [$status_cls, $status_icon] = match($r['status']) {
        'OPŁACALNE'     => ['success', '✓'],
        'DO NEGOCJACJI' => ['warning', '~'],
        default         => ['danger',  '✗'],
    };

    $tryb_label = $r['tryb'] === 'wyjazdowy' ? 'Wyjazdowy' : 'Online';
    $w          = $r['worker'];  // perspektywa pracownika

    $breakdown_labels = [
        'baza'      => 'Koszt bazowy wyjazdu',
        'dojazd'    => 'Wycena czasu dojazdu',
        'bilety'    => 'Bilety / transport',
        'praca'     => 'Czas pracy (robocizna)',
        'platforma' => 'Opłata za platformę',
    ];
    $breakdown_rows = '';
    foreach ($r['breakdown'] as $key => $val) {
        if ($val == 0 && $key !== 'bilety') continue;
        $lbl = $breakdown_labels[$key] ?? $key;
        $breakdown_rows .= '<tr><td class="text-muted ps-4 small">↳ ' . htmlspecialchars($lbl) . '</td>'
            . '<td class="text-end small">' . money($val) . '</td></tr>';
    }

    // Paski ROI: fundacja i pracownik
    $roi_org_pct    = max(0, min(100, abs($r['roi_org'])));
    $roi_org_cls    = $status_cls === 'success' ? 'bg-success' : ($status_cls === 'warning' ? 'bg-warning' : 'bg-danger');
    $roi_w_pct      = max(0, min(100, $r['roi_worker']));
    $roi_w_cls      = $r['roi_worker'] >= 70 ? 'bg-success' : ($r['roi_worker'] >= 50 ? 'bg-warning' : 'bg-danger');

    ob_start(); ?>
    <div class="card shadow-sm" id="oplResultCard">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h3 class="card-title mb-0">Raport opłacalności</h3>
        <div class="d-flex align-items-center gap-2">
          <span class="badge bg-<?= $status_cls ?> fs-6 px-3 py-2">
            <?= $status_icon ?> <?= htmlspecialchars($r['status']) ?>
          </span>
          <button class="btn btn-sm btn-ghost-secondary d-print-none" onclick="window.print()" title="Drukuj raport">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round"
                    d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2
                       0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4
                       a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
            </svg>
          </button>
        </div>
      </div>

      <div class="card-body">
        <!-- Meta -->
        <div class="row g-3 mb-3">
          <div class="col-6">
            <div class="subheader mb-1">Tryb realizacji</div>
            <div class="fw-bold"><?= htmlspecialchars($tryb_label) ?></div>
          </div>
          <div class="col-6">
            <div class="subheader mb-1">Model obciążeń</div>
            <div class="fw-bold"><?= htmlspecialchars($r['model_zus_label']) ?></div>
            <div class="small text-muted"><?= htmlspecialchars($r['model_zus_note']) ?></div>
          </div>
        </div>

        <!-- ══ Tabela kosztów (perspektywa fundacji) ══ -->
        <table class="table table-sm table-borderless mb-0">
          <tbody>
            <tr class="table-light">
              <td class="fw-semibold">Przychód oferowany</td>
              <td class="text-end fw-bold fs-5"><?= money($r['przychod']) ?></td>
            </tr>
            <?= _opl_field('Koszt operacyjny bazowy', $r['koszt_bazowy']) ?>
            <?= $breakdown_rows ?>
            <?= _opl_field('Rezerwa kosztowa (10 %)', $r['nadwyzka_kosztowa'], 'text-warning') ?>
            <tr class="border-top">
              <td class="fw-semibold">Koszt całkowity</td>
              <td class="text-end fw-bold text-danger"><?= money($r['koszt_calkowity']) ?></td>
            </tr>
            <tr class="table-light">
              <td class="text-muted">Nadwyżka przed obciążeniami</td>
              <td class="text-end <?= $r['surplus'] >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= money($r['surplus']) ?>
              </td>
            </tr>
            <?php if ($r['zus_org'] > 0): ?>
              <?= _opl_field('Składki ZUS fundacji (szacunek)', $r['zus_org'], 'text-muted') ?>
            <?php endif; ?>
            <?= _opl_field('Podatek dochodowy (szacunek)', $r['podatek_org'], 'text-muted') ?>
            <?= _opl_field('Łączne obciążenia ZUS + US', $r['obciazenia_org'], 'text-danger') ?>
            <tr class="border-top border-2">
              <td class="fw-bold fs-5">Zysk Netto Fundacji</td>
              <td class="text-end fw-bold fs-4 <?= $r['zysk_netto'] >= 0 ? 'text-success' : 'text-danger' ?>">
                <?= money($r['zysk_netto']) ?>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- ══ Dwa paski ROI ══ -->
        <div class="row g-3 mt-3">
          <!-- ROI fundacji -->
          <div class="col-6">
            <div class="p-3 rounded border h-100" style="background:var(--tblr-bg-surface-secondary,#f8f9fa)">
              <div class="subheader mb-1">ROI Organizacji</div>
              <div class="d-flex justify-content-between align-items-baseline mb-1">
                <span class="small text-muted">Zysk / Koszt całkowity</span>
                <strong class="text-<?= $status_cls ?> fs-5">
                  <?= number_format($r['roi_org'], 1, ',', ' ') ?> %
                </strong>
              </div>
              <div class="progress" style="height:8px">
                <div class="progress-bar <?= $roi_org_cls ?>"
                     style="width:<?= $roi_org_pct ?>%"
                     role="progressbar"></div>
              </div>
              <div class="small text-muted mt-1">
                Próg: <?= OPLACALNOSC_ROI_OK_THRESHOLD ?> %
              </div>
            </div>
          </div>

          <!-- ROI pracownika -->
          <div class="col-6">
            <div class="p-3 rounded border h-100" style="background:var(--tblr-bg-surface-secondary,#f8f9fa)">
              <div class="subheader mb-1">ROI Zleceniobiorcy</div>
              <div class="d-flex justify-content-between align-items-baseline mb-1">
                <span class="small text-muted">Netto / Brutto wynagrodzenia</span>
                <strong class="text-<?= $roi_w_cls === 'bg-success' ? 'success' : ($roi_w_cls === 'bg-warning' ? 'warning' : 'danger') ?> fs-5">
                  <?= number_format($r['roi_worker'], 1, ',', ' ') ?> %
                </strong>
              </div>
              <div class="progress" style="height:8px">
                <div class="progress-bar <?= $roi_w_cls ?>"
                     style="width:<?= $roi_w_pct ?>%"
                     role="progressbar"></div>
              </div>
              <div class="small text-muted mt-1">
                <?= money($w['gross']) ?> brutto → <?= money($w['net']) ?> netto
              </div>
            </div>
          </div>
        </div>

        <!-- ══ Szczegóły wynagrodzenia pracownika ══ -->
        <details class="mt-3">
          <summary class="subheader cursor-pointer user-select-none" style="list-style:none">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="none"
                 viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" class="me-1">
              <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
            </svg>
            Rozkład wynagrodzenia zleceniobiorcy
          </summary>
          <table class="table table-sm table-borderless mt-2 mb-0">
            <tbody>
              <tr class="table-light">
                <td class="fw-semibold">Wynagrodzenie brutto</td>
                <td class="text-end fw-bold"><?= money($w['gross']) ?></td>
              </tr>
              <?php if ($w['social'] > 0): ?>
                <tr>
                  <td class="text-muted ps-4 small">↳ Składki społeczne ZUS (pracownik)</td>
                  <td class="text-end small text-danger">&minus; <?= money($w['social']) ?></td>
                </tr>
              <?php endif; ?>
              <?php if ($w['health'] > 0): ?>
                <tr>
                  <td class="text-muted ps-4 small">↳ Składka zdrowotna NFZ</td>
                  <td class="text-end small text-danger">&minus; <?= money($w['health']) ?></td>
                </tr>
              <?php endif; ?>
              <tr>
                <td class="text-muted ps-4 small">↳ Podatek dochodowy (PIT)</td>
                <td class="text-end small text-danger">&minus; <?= money($w['pit']) ?></td>
              </tr>
              <tr class="border-top border-2">
                <td class="fw-bold">Do wypłaty (netto)</td>
                <td class="text-end fw-bold text-success"><?= money($w['net']) ?></td>
              </tr>
            </tbody>
          </table>
        </details>

        <!-- ══ Rekomendacja ══ -->
        <div class="alert alert-<?= $status_cls ?> mt-3 mb-0" role="alert">
          <?php if ($r['status'] === 'OPŁACALNE'): ?>
            <strong>Działanie jest opłacalne.</strong>
            ROI organizacji <?= number_format($r['roi_org'], 1, ',', ' ') ?> % przekracza próg
            <?= OPLACALNOSC_ROI_OK_THRESHOLD ?> %. Zleceniobiorca zachowuje
            <?= number_format($r['roi_worker'], 1, ',', ' ') ?> % stawki brutto.
          <?php elseif ($r['status'] === 'DO NEGOCJACJI'): ?>
            <strong>Na granicy opłacalności.</strong>
            Zysk fundacji <?= money($r['zysk_netto']) ?> nie gwarantuje ROI
            <?= OPLACALNOSC_ROI_OK_THRESHOLD ?> %.
            <?= $r['tryb'] === 'wyjazdowy' ? 'Rozważ tryb online lub negocjuj wyższy przychód.' : 'Wynegocjuj wyższy przychód lub skróć czas pracy.' ?>
          <?php else: ?>
            <strong>Działanie jest stratne.</strong>
            Koszty przewyższają przychód o <?= money(abs($r['zysk_netto'])) ?>.
            <?= $r['tryb'] === 'wyjazdowy' ? 'Przeanalizuj tryb online lub wynegocjuj wyższe wynagrodzenie.' : 'Wynegocjuj wyższy przychód lub ogranicz zaangażowanie.' ?>
          <?php endif; ?>
        </div>
      </div><!-- /card-body -->

      <div class="card-footer text-muted small d-print-none">
        Obliczenia szacunkowe. Ostateczne obciążenia zależą od sytuacji podatkowej i tytułu ubezpieczenia.
      </div>
    </div><!-- /card result -->
    <?php
    return ob_get_clean();
}
?>

<!-- ── JavaScript ──────────────────────────────────────────────────────── -->
<script>
(function () {
  'use strict';

  /* Przełącznik trybu — pokaż/ukryj pola wyjazdowe */
  const form     = document.getElementById('oplForm');
  const fields   = document.getElementById('oplWyjazdFields');
  const hint     = document.querySelector('.opl-online-hint');
  const radios   = form.querySelectorAll('input[name="tryb"]');

  function syncMode() {
    const isWyjazd = form.tryb.value === 'wyjazdowy';
    fields.classList.toggle('d-none', !isWyjazd);
    if (hint) hint.classList.toggle('d-none', isWyjazd);

    /* Wymagalność */
    ['stawka', 'czas_dojazdu'].forEach(function (n) {
      const el = form.elements[n];
      if (el) el.required = isWyjazd;
    });
  }

  radios.forEach(function (r) { r.addEventListener('change', syncMode); });
  syncMode();

  /* Live-calc — debounced AJAX */
  const resultCol  = document.getElementById('oplResultCol');
  const placeholder = document.getElementById('oplPlaceholder');
  let debounceTimer = null;
  const DEBOUNCE_MS = 600;

  function isFormUsable() {
    const przychod   = parseFloat(form.przychod.value);
    const czas_pracy = parseFloat(form.czas_pracy.value);
    if (isNaN(przychod) || przychod < 0) return false;
    if (isNaN(czas_pracy) || czas_pracy <= 0) return false;
    if (form.tryb.value === 'wyjazdowy') {
      const stawka = parseFloat(form.stawka.value);
      if (isNaN(stawka) || stawka <= 0) return false;
    }
    return true;
  }

  function liveCalc() {
    if (!isFormUsable()) return;

    const fd = new FormData(form);
    fd.append('_csrf', form.querySelector('[name="_csrf"]').value);

    fetch('?_ajax=1', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data.ok) return;
      if (placeholder) placeholder.remove();
      resultCol.innerHTML = data.html || resultCol.innerHTML;
    })
    .catch(function () { /* ciche niepowodzenie — formularz POST jako fallback */ });
  }

  form.addEventListener('input', function () {
    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(liveCalc, DEBOUNCE_MS);
  });
  form.querySelectorAll('select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(liveCalc, DEBOUNCE_MS);
    });
  });
})();
</script>

<style>
@media print {
  .page-header, #oplForm, nav, .sidebar, footer, .d-print-none { display: none !important; }
  #oplResultCard { box-shadow: none !important; border: 1px solid #dee2e6; }
}
</style>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

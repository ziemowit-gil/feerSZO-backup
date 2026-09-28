<?php
/**
 * karty30/ti/dydaktyk/testy.php — zakładka „Testy” kierownika: przyciski
 * uruchamiające testy i kontrole CLI modułu TI bez dostępu do terminala.
 *
 * Bezpieczeństwo: uruchamiane są WYŁĄCZNIE pozycje z białej listy $TESTS
 * (stały skrypt + stałe argumenty); jedyne parametry od użytkownika (miesiąc,
 * nr rozliczenia) są walidowane wzorcem. Wszystkie pozycje są tylko do odczytu
 * albo działają w transakcji, którą same wycofują (selftest/preview/--dry-run).
 * Test trzyma blokadę zapisu SQLite przez kilka sekund — inne zapisy czekają.
 *
 * Skrypty biegną W PROCESIE strony (includes/cli_inproc.php → szo_cli_run),
 * a nie przez proc_open + binarkę PHP CLI — na hostingu z open_basedir
 * (MyDevil) strona nie widzi /usr/local/bin/php, a proc_open bywa wyłączone.
 * Dzięki temu test działa na tej samej bazie (i tenancie) co panel.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/cli_inproc.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }   // ekran kierownika
$dyd_name = (string)($me['name'] ?? '');
karty30_migrate();

$ROOT = dirname(__DIR__, 3);

/** [etykieta, opis, skrypt w cli/, stałe argumenty, parametr opcjonalny, ikona] */
$TESTS = [
    'price_selftest' => ['Zmiany cen — autotest', 'Zmiana ceny od środka miesiąca zmienia stawkę tylko lekcji od tej daty; pozycje FV per stawka. Transakcja wycofywana.',
                         'ti_price_changes.php', ['selftest'], null, 'patch-check'],
    'price_table'    => ['Zmiany cen — tabela', 'Wszystkie zmiany cen wprowadzone w systemie (także anulowane).',
                         'ti_price_changes.php', ['table', '--all'], null, 'table'],
    'invoice_check'  => ['Faktury — kontrola rozliczeń', 'Kwota zapisana, należność, przeliczenie z obecności i faktura dla każdego rozliczenia. Flagi rozbieżności. Tylko odczyt.',
                         'ti_invoices.php', ['check'], 'month', 'clipboard-data'],
    'invoice_selftest' => ['Faktury — autotest', 'Suma faktury = należność z rabatem, ryczałt jako usługa, wykrycie nieaktualnego rozliczenia, PDF. Transakcja wycofywana.',
                         'ti_invoices.php', ['selftest'], null, 'receipt'],
    'invoice_preview' => ['Faktury — podgląd dla kursanta', 'Pozycje faktury, jaka powstałaby z rozliczenia (bez zapisu). PDF: przycisk „Podgląd FV” w Rozliczeniach kursantów.',
                         'ti_invoices.php', ['preview'], 'billing', 'eye'],
    'protocols_selftest' => ['Protokoły — autotest', 'Zatwierdzenie otwiera następny miesiąc (bez duplikatów po odblokowaniu), „Zamknij i archiwizuj” blokuje protokoły i zapisy, „Przywróć” zdejmuje blokadę. Transakcja wycofywana, bez EZD.',
                         'ti_protocols.php', ['selftest'], null, 'journal-check'],
    'ezd_selftest'   => ['EZD — protokoły jako koszulki', 'Zatwierdzony protokół → koszulka w klasie JRWA 384 z pismem i PDF; ponowne zatwierdzenie → ta sama koszulka. Bez SharePoint, pliki testowe usuwane, transakcja wycofywana.',
                         'ti_protocols.php', ['ezd-selftest'], null, 'archive'],
    'protocols_next' => ['Protokoły — następny miesiąc (podgląd)', 'Które zatwierdzone protokoły miesięczne nie mają jeszcze otwartego następnego miesiąca. --dry-run, nic nie zapisuje.',
                         'ti_protocols_next_month.php', ['--dry-run'], null, 'calendar-plus'],
];

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $key = (string)($_POST['test'] ?? '');
    if (!isset($TESTS[$key])) { flash_set('danger', 'Nieznany test.'); header('Location: testy.php'); exit; }
    [$label, , $script, $args, $param] = $TESTS[$key];

    $ok_param = true;
    if ($param === 'month' && ($m = trim((string)($_POST['month'] ?? ''))) !== '') {
        if (preg_match('/^\d{4}-\d{2}$/', $m)) $args[] = '--month=' . $m; else $ok_param = false;
    }
    if ($param === 'billing') {
        $bid = (int)($_POST['billing'] ?? 0);
        if ($bid > 0) {
            $args[] = '--billing=' . $bid;
            $args[] = '--out=' . sys_get_temp_dir() . '/szo_testy_preview_' . $bid . '.pdf';
        } else { $ok_param = false; }
    }
    if (!$ok_param) {
        $result = ['label' => $label, 'code' => -1, 'out' => 'Niepoprawny parametr.', 'ms' => 0, 'cmd' => ''];
    } else {
        $r = szo_cli_run($ROOT . '/cli/' . $script, $args);
        $result = ['label' => $label, 'code' => $r['code'], 'out' => $r['out'], 'ms' => $r['ms'],
                   'cmd' => 'php cli/' . $script . ' ' . implode(' ', array_map(fn($a) => str_starts_with($a, '--out=') ? '--out=…' : $a, $args))];
        error_log('[testy.php] ' . $dyd_name . ' uruchomił: ' . $result['cmd'] . ' → kod ' . $r['code']);
        foreach ($args as $a) if (str_starts_with($a, '--out=')) @unlink(substr($a, 6));   // PDF podglądu — tylko tekst na ekranie
    }
    $result['key'] = $key;
}

$KP_TITLE  = 'Testy — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'testy.php'; $KIER_LABEL = 'Testy';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
<?= flash_html() ?>

<div class="d-flex align-items-center mb-2 gap-2">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-bug me-2 text-primary" aria-hidden="true"></i>Testy</h1>
</div>
<p class="text-body-secondary small mb-3">
  Kontrole i autotesty modułu TI uruchamiane jednym kliknięciem — te same skrypty co w terminalu (<code>cli/</code>), wykonywane bezpośrednio przez stronę (bez PHP CLI na serwerze).
  Wszystkie tylko czytają dane albo działają w transakcji, którą same wycofują — nic nie zostaje w bazie
  i nie wychodzą żadne e-maile. Test trwa kilka sekund; w tym czasie inne zapisy w systemie czekają.
</p>

<?php if ($result): ?>
<section class="card border-0 shadow-sm mb-3" aria-live="polite" aria-labelledby="testy-wynik">
  <div class="card-header bg-white d-flex align-items-center gap-2 flex-wrap">
    <?php $ok = $result['code'] === 0; ?>
    <span class="badge <?= $ok ? 'text-bg-success' : ($result['code'] === 2 ? 'text-bg-warning' : 'text-bg-danger') ?>">
      <?= $ok ? 'OK' : ($result['code'] === 2 ? 'brak danych' : ($result['code'] === -1 ? 'nie uruchomiono' : 'BŁĄD (kod ' . (int)$result['code'] . ')')) ?>
    </span>
    <h2 class="h6 mb-0 fw-semibold" id="testy-wynik"><?= h($result['label']) ?></h2>
    <?php if ($result['cmd']): ?><code class="small ms-2"><?= h($result['cmd']) ?></code><?php endif; ?>
    <span class="ms-auto small text-body-secondary"><?= number_format($result['ms'] / 1000, 1, ',', '') ?> s</span>
  </div>
  <div class="card-body p-0">
    <pre class="mb-0 p-3 small" style="max-height:60vh;overflow:auto;white-space:pre;background:#0f172a;color:#e2e8f0"><?= h(trim($result['out']) ?: '(brak wyjścia)') ?></pre>
  </div>
</section>
<?php endif; ?>

<div class="row g-3">
<?php foreach ($TESTS as $key => [$label, $desc, $script, $args, $param, $icon]): ?>
  <div class="col-md-6 col-xl-4">
    <form method="post" class="card border-0 shadow-sm h-100<?= ($result['key'] ?? '') === $key ? ' border border-primary' : '' ?>">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="test" value="<?= h($key) ?>">
      <div class="card-body d-flex flex-column">
        <div class="fw-semibold mb-1"><i class="bi bi-<?= h($icon) ?> me-1 text-primary" aria-hidden="true"></i><?= h($label) ?></div>
        <div class="small text-body-secondary mb-2 flex-grow-1"><?= h($desc) ?></div>
        <div class="small mb-2"><code>php cli/<?= h($script) ?> <?= h(implode(' ', $args)) ?></code></div>
        <div class="d-flex gap-2 align-items-end">
          <?php if ($param === 'month'): ?>
          <div>
            <label class="form-label small mb-0" for="t-<?= h($key) ?>-m">Miesiąc (opcjonalnie)</label>
            <input type="month" id="t-<?= h($key) ?>-m" name="month" class="form-control form-control-sm">
          </div>
          <?php elseif ($param === 'billing'): ?>
          <div>
            <label class="form-label small mb-0" for="t-<?= h($key) ?>-b">Nr rozliczenia</label>
            <input type="number" id="t-<?= h($key) ?>-b" name="billing" min="1" required class="form-control form-control-sm" style="width:8rem">
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-sm btn-primary ms-auto">
            <i class="bi bi-play-fill me-1" aria-hidden="true"></i>Uruchom
          </button>
        </div>
      </div>
    </form>
  </div>
<?php endforeach; ?>
</div>

</div>
</main>

<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>

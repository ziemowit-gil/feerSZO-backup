<?php
/**
 * karty30/ti/dydaktyk/testy.php — zakładka „Testy” kierownika: przyciski
 * uruchamiające testy i kontrole CLI modułu TI bez dostępu do terminala.
 *
 * Bezpieczeństwo: uruchamiane są WYŁĄCZNIE pozycje z białej listy $TESTS
 * (stały skrypt + stałe argumenty); jedyne parametry od użytkownika (miesiąc,
 * nr rozliczenia) są walidowane wzorcem i przekazywane jako osobne argv przez
 * proc_open(array) — bez powłoki. Wszystkie pozycje są tylko do odczytu albo
 * działają w transakcji, którą same wycofują (selftest/preview/--dry-run).
 * Test trzyma blokadę zapisu SQLite przez kilka sekund — inne zapisy w tym
 * czasie czekają.
 *
 * Proces potomny dostaje TENANT_SLUG z bieżącego żądania, więc testuje tę
 * samą bazę co panel. Binarka PHP CLI: org_setting('php_cli_binary') albo
 * autodetekcja (PHP_BINARY z FPM wskazuje php-fpm, nie CLI).
 */
require_once __DIR__ . '/auth.php';

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
    'protocols_next' => ['Protokoły — następny miesiąc (podgląd)', 'Które zatwierdzone protokoły miesięczne nie mają jeszcze otwartego następnego miesiąca. --dry-run, nic nie zapisuje.',
                         'ti_protocols_next_month.php', ['--dry-run'], null, 'journal-check'],
];

/** Ścieżka do PHP CLI albo '' gdy nie znaleziono. */
function testy_php_cli(): string {
    $cands = array_filter([
        trim((string)org_setting('php_cli_binary')),
        PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' ? PHP_BINARY : '',
        PHP_BINDIR . '/php',
        '/usr/local/bin/php', '/usr/bin/php', '/opt/homebrew/bin/php',
    ]);
    foreach ($cands as $c) {
        if (is_file($c) && is_executable($c) && !str_contains(basename($c), 'fpm')) return $c;
    }
    return '';
}

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
    $php = testy_php_cli();
    if (!$ok_param) {
        $result = ['label' => $label, 'code' => -1, 'out' => 'Niepoprawny parametr.', 'ms' => 0, 'cmd' => ''];
    } elseif ($php === '') {
        $result = ['label' => $label, 'code' => -1, 'ms' => 0, 'cmd' => '',
                   'out' => 'Nie znaleziono PHP CLI na serwerze. Ustaw ścieżkę w ustawieniu organizacji „php_cli_binary” (np. /usr/local/bin/php).'];
    } else {
        $argv = array_merge([$php, $ROOT . '/cli/' . $script], $args);
        $env  = ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];
        if (defined('TENANT_SLUG') && TENANT_SLUG !== '') $env['TENANT_SLUG'] = TENANT_SLUG;
        $t0   = microtime(true);
        $proc = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $ROOT, $env);
        $out  = ''; $code = -1;
        if (is_resource($proc)) {
            stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
            $deadline = $t0 + 120;
            while (true) {
                $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
                $st = proc_get_status($proc);
                if (!$st['running']) { $code = (int)$st['exitcode']; break; }
                if (microtime(true) > $deadline) { proc_terminate($proc); $out .= "\n[przerwano po 120 s]"; break; }
                usleep(100000);
            }
            $out .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $c2 = proc_close($proc);
            if ($code === -1 && $c2 !== -1) $code = $c2;
        } else {
            $out = 'Nie udało się uruchomić procesu.';
        }
        $result = ['label' => $label, 'code' => $code, 'out' => $out, 'ms' => (int)round((microtime(true) - $t0) * 1000),
                   'cmd' => 'php cli/' . $script . ' ' . implode(' ', array_map(fn($a) => str_starts_with($a, '--out=') ? '--out=…' : $a, $args))];
        error_log('[testy.php] ' . $dyd_name . ' uruchomił: ' . $result['cmd'] . ' → kod ' . $code);
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
  Kontrole i autotesty modułu TI uruchamiane jednym kliknięciem (te same co w terminalu, <code>cli/</code>).
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

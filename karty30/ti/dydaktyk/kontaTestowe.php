<?php
/**
 * karty30/ti/dydaktyk/kontaTestowe.php — przykładowe konta testowe do konsultacji i szkoleń krótkich (tylko administrator).
 * Edytowalny telefon osób testowych (domyślnie 535 231 923), „Załóż / odśwież” (nowe hasła, opcjonalnie SMS),
 * tabela kont, PDF z loginami i hasłami (hasła tylko w sesji, 30 min), dezaktywacja. Logika: modules/ti_test_accounts.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_test_accounts/logic/testAccounts.php';
$me = dyd_require();
$uid = (int)$me['user_id'];
if (!dyd_is_admin()) { flash_set('danger', 'Konta testowe zakłada administrator.'); header('Location: index.php'); exit; }
karty30_migrate();

if (isset($_GET['pdf'])) {
    $d = $_SESSION['ti_test_creds'] ?? null;
    if (!$d || time() - (int)$d['ts'] > 1800) { http_response_code(410); exit('Hasła nie są już dostępne — kliknij „Załóż / odśwież konta testowe” ponownie.'); }
    $pdf = TiTestAccounts::pdf($d['rows']);
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="konta-testowe.pdf"'); header('Cache-Control: no-store, private');
    echo $pdf; exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'phone') {
        $ok = TiTestAccounts::setPhone((string)($_POST['phone'] ?? ''));
        flash_set($ok ? 'success' : 'danger', $ok ? 'Numer telefonu kont testowych zapisany (także u istniejących osób testowych).' : 'Numer telefonu jest nieprawidłowy.');
    } elseif ($op === 'seed') {
        try {
            $rows = TiTestAccounts::seed($uid, !empty($_POST['sms']));
            $_SESSION['ti_test_creds'] = ['rows' => $rows, 'ts' => time()];
            flash_set('success', 'Konta testowe gotowe: ' . count($rows) . '. Nadano nowe hasła — pobierz PDF z danymi logowania.');
        } catch (\Throwable $e) { error_log('kontaTestowe: ' . $e->getMessage()); flash_set('danger', 'Nie udało się założyć kont testowych: ' . $e->getMessage()); }
    } elseif ($op === 'deactivate') {
        flash_set('success', 'Dezaktywowano konta testowe: ' . TiTestAccounts::deactivate() . '. Dane (rezerwacje, karty, szkolenia) zostają.');
        unset($_SESSION['ti_test_creds']);
    }
    header('Location: kontaTestowe.php'); exit;
}
$creds = (($_SESSION['ti_test_creds']['ts'] ?? 0) > time() - 1800) ? $_SESSION['ti_test_creds']['rows'] : [];
$byLogin = array_column($creds, null, 'login');
$list = TiTestAccounts::list();
$modes = [0 => 'wg szkolenia SzK', 1 => 'tylko terminy', 2 => 'terminy + płatności', 3 => 'płatności uproszczone', 4 => 'beneficjent konsultacji'];

$KP_TITLE = 'Konta testowe — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $KIER_CUR = 'kontaTestowe.php'; $KIER_LABEL = 'Konta testowe'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
<h1 class="h4 fw-bold mb-1"><i class="bi bi-person-badge text-primary me-2" aria-hidden="true"></i>Konta testowe — konsultacje i szkolenia krótkie</h1>
<p class="small text-body-secondary">3 beneficjentów konsultacji (konta „k1234”, różne etapy kart, jedna osoba z wymogiem dokumentu) i 2 szkolenia krótkie SzK z uczestnikami. „Odśwież” nie dubluje danych — nadaje tylko nowe hasła.</p>
<?= flash_html() ?>
<div class="row g-3 mb-3">
  <div class="col-md-5"><form method="post" class="card border-0 shadow-sm h-100"><div class="card-body">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="phone">
    <label class="form-label fw-semibold" for="tt-ph">Telefon wszystkich osób testowych</label>
    <div class="input-group"><input class="form-control" id="tt-ph" name="phone" type="tel" value="<?= h(TiTestAccounts::phone()) ?>" required><button class="btn btn-outline-primary">Zapisz</button></div>
    <div class="form-text">Na ten numer idą SMS-y z danymi logowania i powiadomienia osób testowych.</div>
  </div></form></div>
  <div class="col-md-7"><form method="post" class="card border-0 shadow-sm h-100"><div class="card-body">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="seed">
    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="sms" value="1" id="tt-sms"><label class="form-check-label" for="tt-sms">Wyślij też loginy i hasła SMS-em na numer testowy</label></div>
    <button class="btn btn-primary"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Załóż / odśwież konta testowe</button>
    <?php if ($creds): ?><a class="btn btn-outline-secondary ms-1" href="kontaTestowe.php?pdf=1" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF z danymi logowania</a><?php endif; ?>
  </div></form></div>
</div>

<div class="table-responsive"><table class="table table-sm table-striped align-middle">
  <caption class="visually-hidden">Konta testowe</caption>
  <thead class="table-light"><tr><th scope="col">Osoba</th><th scope="col">Telefon</th><th scope="col">Login</th><th scope="col">Hasło</th><th scope="col">Panel</th><th scope="col">Rodzaj / co sprawdzić</th><th scope="col">Konto</th></tr></thead>
  <tbody>
  <?php if (!$list): ?><tr><td colspan="7" class="text-center text-body-secondary py-3">Nie ma jeszcze kont testowych — kliknij „Załóż / odśwież”.</td></tr><?php endif; ?>
  <?php foreach ($list as $a): $c = $byLogin[$a['login'] ?? ''] ?? null; ?>
  <tr>
    <th scope="row" class="fw-normal"><?= h($a['name']) ?></th>
    <td class="small"><?= h($a['phone']) ?></td>
    <td class="font-monospace"><?= h($a['login'] ?? '—') ?></td>
    <td class="font-monospace"><?= $c ? h($c['password']) : '<span class="small text-body-secondary">—</span>' ?></td>
    <td class="small"><?= $a['login'] ? h($modes[(int)$a['simple_mode']] ?? '') : '' ?></td>
    <td class="small"><?= $c ? h($c['kind'] . ' — ' . $c['hint']) . ($c['sms'] ? ' · SMS: ' . h($c['sms']) : '') : '' ?></td>
    <td><?= $a['login'] ? ((int)$a['is_active'] ? '<span class="badge text-bg-success">aktywne</span>' : '<span class="badge text-bg-secondary">wyłączone</span>') : '' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php if (!$creds && $list): ?><p class="small text-body-secondary">Hasła pokazujemy tylko przez 30 minut po „Załóż / odśwież”.</p><?php endif; ?>
<?php if ($list): ?>
<form method="post" onsubmit="return confirm('Dezaktywować wszystkie konta testowe? Dane zostają, można je potem odświeżyć.')">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="deactivate">
  <button class="btn btn-sm btn-outline-danger">Dezaktywuj konta testowe</button>
</form>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>

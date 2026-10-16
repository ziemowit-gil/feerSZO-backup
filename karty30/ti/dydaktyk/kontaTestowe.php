<?php
/**
 * karty30/ti/dydaktyk/kontaTestowe.php — przykładowe konta testowe do konsultacji i szkoleń krótkich (tylko administrator).
 * Edytowalny telefon osób testowych (domyślnie 535 231 923), „Załóż / odśwież” (nowe hasła, opcjonalnie SMS),
 * tabela kont, PDF z loginami i hasłami (hasła tylko w sesji, 30 min), dezaktywacja. Logika: modules/ti_test_accounts.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_test_accounts/logic/testAccounts.php';
require_once dirname(__DIR__, 3) . '/modules/ti_test_accounts/logic/testProtocols.php';
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
    } elseif ($op === 'purge_protocols') {
        if (trim((string)($_POST['confirm_word'] ?? '')) !== 'USUŃ') {
            flash_set('danger', 'Aby usunąć protokoły, wpisz w polu słowo USUŃ.');
        } else {
            try {
                $r = TiTestProtocols::purge($uid, (string)($me['name'] ?? ''));
                flash_set('success', 'Usunięto protokoły grup TEST/DEMO: grupy ' . $r['courses'] . ', protokoły ' . $r['protocols'] . ', wpisy ' . $r['entries'] . '.');
            } catch (\Throwable $e) { error_log('kontaTestowe purge_protocols: ' . $e->getMessage()); flash_set('danger', 'Nie udało się usunąć protokołów (nic nie zmieniono): ' . $e->getMessage()); }
        }
    } elseif ($op === 'purge') {
        if (trim((string)($_POST['confirm_word'] ?? '')) !== 'USUŃ') {
            flash_set('danger', 'Aby usunąć konta testowe, wpisz w polu słowo USUŃ.');
        } else {
            try {
                $r = TiTestAccounts::purge();
                flash_set('success', 'Usunięto konta testowe: osoby ' . $r['clients'] . ', konta ' . $r['accounts'] . ', szkolenia SzK ' . $r['courses']
                    . ', powiązane wpisy ' . $r['rows'] . '. „Załóż / odśwież” założy je od nowa.');
            } catch (\Throwable $e) { error_log('kontaTestowe purge: ' . $e->getMessage()); flash_set('danger', 'Nie udało się usunąć kont testowych (nic nie zmieniono): ' . $e->getMessage()); }
        }
        unset($_SESSION['ti_test_creds']);
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
<div class="d-flex flex-wrap align-items-start gap-3">
<form method="post" onsubmit="return confirm('Dezaktywować wszystkie konta testowe? Dane zostają, można je potem odświeżyć.')">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="deactivate">
  <button class="btn btn-sm btn-outline-danger">Dezaktywuj konta testowe</button>
</form>
<details>
  <summary class="btn btn-sm btn-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń konta testowe</summary>
  <form method="post" class="border border-danger rounded p-2 mt-2" style="max-width:460px"
        onsubmit="return confirm('Usunąć NIEODWRACALNIE wszystkie osoby i konta testowe razem z rezerwacjami, kartami konsultacji i testowymi szkoleniami SzK?')">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="purge">
    <p class="small mb-2">Usuwa na stałe <?= count($list) ?> osób testowych, ich konta panelu, rezerwacje, karty konsultacji, rozliczenia, wiadomości, dokumenty
      i testowe szkolenia SzK (te, w których nie ma nikogo spoza kont testowych). Inne dane nie są zmieniane.</p>
    <label class="form-label small mb-1" for="tt-confirm">Wpisz <strong>USUŃ</strong>, aby potwierdzić</label>
    <div class="d-flex gap-2"><input class="form-control form-control-sm" id="tt-confirm" name="confirm_word" autocomplete="off" required style="max-width:140px">
      <button class="btn btn-sm btn-danger">Usuń na stałe</button></div>
  </form>
</details>
</div>
<?php endif; ?>

<?php $protCands = TiTestProtocols::candidates(); ?>
<section class="mt-4" aria-labelledby="tt-prot-h">
  <h2 class="h6 fw-bold" id="tt-prot-h"><i class="bi bi-file-earmark-x me-1" aria-hidden="true"></i>Protokoły grup testowych i demonstracyjnych</h2>
  <p class="small text-body-secondary mb-2">Grupy z „TEST” albo „DEMO” (wielkimi literami) w nazwie. Usuwamy tylko protokoły miesięczne z ich wpisami —
    grupy, lekcje i rozliczenia zostają; dokumenty już zarejestrowane w EZD nie są ruszane.</p>
  <?php if (!$protCands): ?>
  <p class="small">Brak protokołów w grupach TEST/DEMO.</p>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle" style="max-width:720px">
    <caption class="visually-hidden">Grupy TEST/DEMO z protokołami</caption>
    <thead><tr><th scope="col">Grupa</th><th scope="col" class="text-end">Protokoły</th><th scope="col" class="text-end">w tym zatwierdzone</th></tr></thead>
    <tbody>
    <?php foreach ($protCands as $pc): ?>
      <tr><td><?= h($pc['name']) ?></td><td class="text-end"><?= (int)$pc['protocols'] ?></td><td class="text-end"><?= (int)$pc['approved'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <form method="post" class="d-flex flex-wrap align-items-end gap-2"
        onsubmit="return confirm('Usunąć NIEODWRACALNIE <?= array_sum(array_column($protCands, 'protocols')) ?> protokołów z <?= count($protCands) ?> grup TEST/DEMO?')">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="purge_protocols">
    <div><label class="form-label small mb-1" for="tt-prot-confirm">Wpisz <strong>USUŃ</strong>, aby potwierdzić</label>
      <input class="form-control form-control-sm" id="tt-prot-confirm" name="confirm_word" autocomplete="off" required style="max-width:140px"></div>
    <button class="btn btn-sm btn-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń protokoły (<?= array_sum(array_column($protCands, 'protocols')) ?>)</button>
  </form>
  <?php endif; ?>
</section>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>

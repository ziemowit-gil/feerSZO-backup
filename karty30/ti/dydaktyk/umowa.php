<?php
/**
 * karty30/ti/dydaktyk/umowa.php — „Umowa”: umowy zlecenie prowadzącego i e-ewidencja godzin do przeglądania.
 *
 * Tylko odczyt. Prowadzący widzi własne umowy z zaznaczonym „Dydaktyka — udostępnij dane do modułu”
 * (powiązane kontem, e-mailem albo imieniem i nazwiskiem —
 * te same zasady co przy e-ewidencji z protokołów, includes/ti_protocols.php). Dla wybranej umowy:
 * miesiące z liczbą godzin, a dla wybranego miesiąca — wpisy ewidencji (data, godziny, opis, skąd)
 * i odnośniki do PDF e-ewidencji / e-listy obecności z podpisanych protokołów.
 *   ?u=<id umowy>  ?m=RRRR-MM
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/ti_protocols.php';
require_once dirname(__DIR__, 3) . '/includes/zlecenie_rachunki.php';   // kolumna dydaktyka_udostepnij

$me  = dyd_require();
$uid = (int)$me['user_id'];
karty30_migrate();
ti_protocol_ew_migrate();

$usr = db_one("SELECT email, name FROM users WHERE id = ?", [$uid]) ?: ['email' => '', 'name' => ''];
$umowy = [];
try {
    $umowy = db_all(
        "SELECT * FROM umowy_zlecenie
          WHERE status NOT IN ('projekt','anulowana','odrzucona')
            AND COALESCE(dydaktyka_udostepnij, 0) = 1   -- „Dydaktyka — udostępnij dane do modułu”
            AND (ti_instructor_user_id = ?
                 OR (TRIM(COALESCE(?, '')) <> '' AND lower(email) = lower(?))
                 OR (TRIM(COALESCE(?, '')) <> '' AND lower(TRIM(imie_nazwisko)) = lower(TRIM(?))))
          ORDER BY COALESCE(data_rozpoczecia, '') DESC, id DESC",
        [$uid, (string)$usr['email'], (string)$usr['email'], (string)$usr['name'], (string)$usr['name']]
    );
} catch (\Throwable $e) {
    $umowy = [];   // brak modułu umów
}

$nr  = fn(array $u) => trim((string)($u['numer_umowy'] ?: ($u['nr_rejestru'] ?? '') ?: ($u['nr_roboczy'] ?? ''))) ?: ('#' . $u['id']);
$d   = fn($v) => $v ? date('d.m.Y', strtotime((string)$v)) : '';
$hf  = fn($x) => rtrim(rtrim(number_format((float)$x, 2, ',', ' '), '0'), ',');

$sel = null;
foreach ($umowy as $u) if ((int)$u['id'] === (int)($_GET['u'] ?? 0)) $sel = $u;
$sel ??= $umowy[0] ?? null;

$miesiace = []; $wpisy = []; $mies = '';
if ($sel) {
    $miesiace = db_all(
        "SELECT substr(data, 1, 7) AS ym, COUNT(*) AS n, SUM(godziny) AS godz
           FROM zlecenie_ewidencja_wpisy WHERE contract_id = ? GROUP BY substr(data, 1, 7) ORDER BY ym DESC",
        [(int)$sel['id']]
    );
    $mies = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? (string)$_GET['m'] : (string)($miesiace[0]['ym'] ?? '');
    if ($mies !== '') {
        $wpisy = db_all(
            "SELECT * FROM zlecenie_ewidencja_wpisy WHERE contract_id = ? AND substr(data, 1, 7) = ? ORDER BY data, id",
            [(int)$sel['id'], $mies]
        );
    }
}
// Podpisane protokoły z godzinami przepisanymi do tej umowy w wybranym miesiącu → PDF e-ewidencji / e-listy obecności.
$prot = ($sel && $mies !== '') ? db_all(
    "SELECT p.course_id, p.year_month, p.ew_godziny, p.ew_zgoda_at, c.name AS course_name
       FROM k30_ti_protocols p JOIN k30_ti_courses c ON c.id = p.course_id
      WHERE p.ew_umowa_id = ? AND p.year_month = ? AND p.ew_zgoda_at IS NOT NULL ORDER BY c.name",
    [(int)$sel['id'], $mies]
) : [];

$zrodlo = fn(string $z) => ['karty30' => 'protokół zajęć TI', 'reczny' => 'wpis ręczny', 'import' => 'import'][$z] ?? ($z !== '' ? $z : '—');

$KP_TITLE  = 'Umowa — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .um-table td.num, .um-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
  .um-table tr.um-total td { font-weight: 700; border-top: 2px solid #495057; }
</style>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
  <h1 class="h4 fw-bold mb-1"><i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>Umowa</h1>
  <p class="text-body-secondary mb-3">Twoje umowy zlecenie i e-ewidencja godzin — do przeglądania. Godziny trafiają tu po podpisaniu protokołu
    i Twojej zgodzie na przepisanie (pełne godziny — każda rozpoczęta liczy się jako pełna).</p>

  <?php if (!$umowy): ?>
    <div class="alert alert-secondary" role="status">
      Nie znaleźliśmy w systemie Twojej umowy zlecenie udostępnionej dydaktyce. Jeśli powinna być, poproś o zaznaczenie w umowie
      pola <strong>„Dydaktyka — udostępnij dane do modułu”</strong> (i wskazanie Twojego konta jako prowadzącego w e-dzienniku).
      Dokumenty do umowy z protokołów i tak możesz wygenerować w <a href="protokoly_moje.php">Protokołach</a> — pola umowy mają wtedy „----”.
    </div>
  <?php else: ?>

  <?php if (count($umowy) > 1): ?>
  <nav aria-label="Wybierz umowę" class="mb-3">
    <ul class="nav nav-pills gap-1">
      <?php foreach ($umowy as $u): $on = (int)$u['id'] === (int)$sel['id']; ?>
      <li class="nav-item"><a class="nav-link<?= $on ? ' active' : '' ?>" href="umowa.php?u=<?= (int)$u['id'] ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        Umowa nr <?= h($nr($u)) ?></a></li>
      <?php endforeach; ?>
    </ul>
  </nav>
  <?php endif; ?>

  <section class="card mb-3" aria-labelledby="umDane">
    <div class="card-body">
      <h2 class="h6 fw-semibold mb-2" id="umDane">Umowa zlecenie nr <?= h($nr($sel)) ?></h2>
      <dl class="row small mb-0">
        <dt class="col-sm-3 fw-normal text-body-secondary">Zleceniobiorca</dt><dd class="col-sm-9"><?= h((string)$sel['imie_nazwisko']) ?></dd>
        <?php if (!empty($sel['data_zawarcia'])): ?>
        <dt class="col-sm-3 fw-normal text-body-secondary">Data zawarcia</dt><dd class="col-sm-9"><?= h($d($sel['data_zawarcia'])) ?></dd>
        <?php endif; ?>
        <dt class="col-sm-3 fw-normal text-body-secondary">Okres</dt>
        <dd class="col-sm-9"><?= h(trim(($sel['data_rozpoczecia'] ? 'od ' . $d($sel['data_rozpoczecia']) : '')
              . (!empty($sel['bezterminowa']) ? ' — bezterminowo' : ($sel['data_zakonczenia'] ? ' do ' . $d($sel['data_zakonczenia']) : '')))) ?: '—' ?></dd>
        <?php if (!empty($sel['przedmiot_zlecenia'])): ?>
        <dt class="col-sm-3 fw-normal text-body-secondary">Przedmiot</dt><dd class="col-sm-9"><?= h((string)$sel['przedmiot_zlecenia']) ?></dd>
        <?php endif; ?>
        <dt class="col-sm-3 fw-normal text-body-secondary">Status</dt><dd class="col-sm-9"><?= h((string)$sel['status']) ?></dd>
      </dl>
    </div>
  </section>

  <div class="row g-3">
    <div class="col-lg-4">
      <section class="card h-100" aria-labelledby="umMies">
        <div class="card-body">
          <h2 class="h6 fw-semibold mb-2" id="umMies">E-ewidencja — miesiące</h2>
          <?php if (!$miesiace): ?>
            <p class="small text-body-secondary mb-0">Brak wpisów w ewidencji tej umowy.</p>
          <?php else: ?>
          <table class="table table-sm um-table mb-0">
            <caption class="visually-hidden">Liczba godzin w ewidencji umowy w poszczególnych miesiącach</caption>
            <thead><tr><th scope="col">Miesiąc</th><th scope="col" class="num">Wpisy</th><th scope="col" class="num">Godziny</th></tr></thead>
            <tbody>
            <?php $tot = 0; foreach ($miesiace as $m): $tot += (float)$m['godz']; $on = $m['ym'] === $mies; ?>
              <tr<?= $on ? ' class="table-primary"' : '' ?>>
                <td><a href="umowa.php?u=<?= (int)$sel['id'] ?>&amp;m=<?= h($m['ym']) ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= h(ti_pdf_month_label($m['ym'])) ?></a></td>
                <td class="num"><?= (int)$m['n'] ?></td><td class="num"><?= h($hf($m['godz'])) ?></td>
              </tr>
            <?php endforeach; ?>
              <tr class="um-total"><td>Razem</td><td></td><td class="num"><?= h($hf($tot)) ?></td></tr>
            </tbody>
          </table>
          <?php endif; ?>
        </div>
      </section>
    </div>

    <div class="col-lg-8">
      <section class="card h-100" aria-labelledby="umWpisy">
        <div class="card-body">
          <h2 class="h6 fw-semibold mb-2" id="umWpisy">E-ewidencja<?= $mies !== '' ? ' — ' . h(ti_pdf_month_label($mies)) : '' ?></h2>
          <?php if (!$wpisy): ?>
            <p class="small text-body-secondary mb-0">Wybierz miesiąc z listy.</p>
          <?php else: ?>
          <table class="table table-sm table-striped um-table">
            <caption class="visually-hidden">Wpisy ewidencji godzin umowy w miesiącu</caption>
            <thead><tr><th scope="col">Data</th><th scope="col" class="num">Godziny</th><th scope="col">Opis</th><th scope="col">Skąd</th></tr></thead>
            <tbody>
            <?php $sm = 0; foreach ($wpisy as $w): $sm += (float)$w['godziny']; ?>
              <tr><td><?= h($d($w['data'])) ?></td><td class="num"><?= h($hf($w['godziny'])) ?></td>
                  <td><?= h((string)$w['opis']) ?></td><td class="small"><?= h($zrodlo((string)$w['zrodlo'])) ?></td></tr>
            <?php endforeach; ?>
              <tr class="um-total"><td>Razem</td><td class="num"><?= h($hf($sm)) ?></td><td colspan="2"></td></tr>
            </tbody>
          </table>
          <?php endif; ?>

          <?php if ($prot): ?>
          <h3 class="h6 fw-semibold mt-3 mb-2">Dokumenty z podpisanych protokołów</h3>
          <ul class="list-unstyled mb-0">
            <?php foreach ($prot as $p): $q = 'course=' . (int)$p['course_id'] . '&amp;ym=' . h(urlencode($p['year_month'])); ?>
            <li class="mb-2">
              <strong><?= h((string)$p['course_name']) ?></strong> — <?= h($hf($p['ew_godziny'])) ?> h
              <span class="small text-body-secondary">(zgoda <?= h(date('d.m.Y', strtotime((string)$p['ew_zgoda_at'] . ' UTC'))) ?>)</span><br>
              <a class="btn btn-sm btn-outline-secondary mt-1" href="protokol_pdf.php?ewidencja=1&amp;<?= $q ?>" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>E-ewidencja (PDF)<span class="visually-hidden"> — <?= h((string)$p['course_name']) ?>, nowa karta</span></a>
              <a class="btn btn-sm btn-outline-secondary mt-1" href="protokol_pdf.php?obecnosc=1&amp;<?= $q ?>" target="_blank" rel="noopener">
                <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Lista obecności (PDF)<span class="visually-hidden"> — <?= h((string)$p['course_name']) ?>, nowa karta</span></a>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>
  <?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>

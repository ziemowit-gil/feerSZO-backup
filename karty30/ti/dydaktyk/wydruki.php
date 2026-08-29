<?php
/**
 * karty30/ti/dydaktyk/wydruki.php — Katalog wydruków panelu kierownika.
 *
 * Dwie sekcje:
 *  1) Katalog — wszystkie typy wydruków dostępne kierownikowi, każdy z mini-
 *     formularzem wyboru parametrów (grupa/kursant/miesiąc), otwiera wynik
 *     w nowej karcie.
 *  2) Historia — log faktycznie wygenerowanych dokumentów (k30_ti_print_log),
 *     zasilany przez poszczególne endpointy (hours_pdf.php, billing_pdf.php…).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
karty30_migrate();

$courses = k30_ti_courses(true);
$clients = db_all(
    "SELECT DISTINCT cl.id, cl.name FROM k30_clients cl
     JOIN k30_ti_enrollments e ON e.client_id=cl.id AND e.status='active'
     ORDER BY cl.name COLLATE NOCASE"
);
$instructors = db_all(
    "SELECT DISTINCT u.id, u.name FROM k30_ti_courses c
     JOIN users u ON u.id=c.instructor_id
     WHERE c.is_active=1 ORDER BY u.name COLLATE NOCASE"
);

$months_pl = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
              7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$cur_m = (int)date('n'); $cur_y = (int)date('Y');

$log = ti_print_log_list(200);

$KP_TITLE  = 'Wydruki — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'wydruki.php'; $KIER_LABEL = 'Wydruki';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
  <h1 class="h4 fw-bold mb-3"><i class="bi bi-printer text-primary me-2" aria-hidden="true"></i>Wydruki</h1>

  <h2 class="h6 fw-semibold text-uppercase text-body-secondary mb-2">Katalog wydruków</h2>
  <div class="row g-3 mb-4">

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-clock-history me-2 text-primary" aria-hidden="true"></i>Rozpiska godzin kursanta (PDF)</h3>
        <form method="get" action="hours_pdf.php" target="_blank" class="row g-2 mt-1">
          <div class="col-12">
            <select name="client_id" class="form-select form-select-sm" required>
              <option value="">— wybierz kursanta —</option>
              <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <select name="month" class="form-select form-select-sm">
              <?php foreach ($months_pl as $mn => $ml): ?><option value="<?= $mn ?>" <?= $mn===$cur_m?'selected':'' ?>><?= h($ml) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <input type="number" name="year" class="form-control form-control-sm" value="<?= $cur_y ?>" min="2020" max="2100">
          </div>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Generuj</button></div>
        </form>
      </div></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-receipt me-2 text-primary" aria-hidden="true"></i>Zestawienie rozliczeń grupy (PDF)</h3>
        <form method="get" action="billing_pdf.php" target="_blank" class="row g-2 mt-1">
          <div class="col-12">
            <select name="course_id" class="form-select form-select-sm" required>
              <option value="">— wybierz grupę —</option>
              <?php foreach ($courses as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Generuj</button></div>
        </form>
      </div></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-receipt-cutoff me-2 text-primary" aria-hidden="true"></i>Zestawienie pozycji do FVAT (PDF)</h3>
        <form method="get" action="billing_fv_summary.php" target="_blank" class="row g-2 mt-1">
          <div class="col-12">
            <select name="course_id" class="form-select form-select-sm" required>
              <option value="">— wybierz grupę —</option>
              <?php foreach ($courses as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <select name="month" class="form-select form-select-sm">
              <?php foreach ($months_pl as $mn => $ml): ?><option value="<?= $mn ?>" <?= $mn===$cur_m?'selected':'' ?>><?= h($ml) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <input type="number" name="year" class="form-control form-control-sm" value="<?= $cur_y ?>" min="2020" max="2100">
          </div>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Generuj</button></div>
        </form>
      </div></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-clipboard-check me-2 text-primary" aria-hidden="true"></i>Lista obecności grupy (PDF)</h3>
        <form method="get" action="attendance_pdf.php" target="_blank" class="row g-2 mt-1">
          <div class="col-12">
            <select name="course_id" class="form-select form-select-sm" required>
              <option value="">— wybierz grupę —</option>
              <?php foreach ($courses as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Generuj</button></div>
        </form>
      </div></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-filetype-csv me-2 text-primary" aria-hidden="true"></i>Eksport CSV frekwencji</h3>
        <form method="get" action="attendance_csv.php" target="_blank" class="row g-2 mt-1">
          <div class="col-6">
            <input type="month" name="month" class="form-control form-control-sm" value="<?= sprintf('%04d-%02d', $cur_y, $cur_m) ?>">
          </div>
          <div class="col-6">
            <select name="course_id" class="form-select form-select-sm">
              <option value="0">— wszystkie grupy —</option>
              <?php foreach ($courses as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Generuj</button></div>
        </form>
      </div></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-calendar-week me-2 text-primary" aria-hidden="true"></i>Plan zajęć prowadzącego</h3>
        <form method="get" action="plan_print.php" target="_blank" class="row g-2 mt-1">
          <div class="col-12">
            <select name="instructor_id" class="form-select form-select-sm" required>
              <option value="">— wybierz prowadzącego —</option>
              <?php foreach ($instructors as $ins): ?><option value="<?= (int)$ins['id'] ?>"><?= h($ins['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <select name="weeks" class="form-select form-select-sm">
              <?php foreach ([4,8,12,16,26] as $w): ?><option value="<?= $w ?>" <?= $w===8?'selected':'' ?>><?= $w ?> tygodni</option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-12"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pokaż</button></div>
        </form>
      </div></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-journal-text me-2 text-primary" aria-hidden="true"></i>Protokół zajęć (PDF)</h3>
        <p class="small text-body-secondary mb-0">Protokoły są przypisane do konkretnych lekcji — wydruk dostępny
        z widoku grupy: <a href="index.php?tab=lekcje">Zajęcia → zakładka Protokół</a>.</p>
      </div></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-file-earmark-text me-2 text-primary" aria-hidden="true"></i>Karta pojedynczej lekcji (PDF)</h3>
        <p class="small text-body-secondary mb-0">Termin, temat, obecność i notatki jednej lekcji — wydruk dostępny
        przy konkretnej lekcji: <a href="index.php?tab=lekcje">Zajęcia → „Wejdź” lub dropdown „Wydruki” w wierszu lekcji</a>.</p>
      </div></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-calendar-x me-2 text-primary" aria-hidden="true"></i>Wykaz dni wolnych (PDF)</h3>
        <form method="get" action="dni_wolne_pdf.php" target="_blank" class="row g-2 mt-1">
          <div class="col-8">
            <input type="number" name="year" class="form-control form-control-sm" value="<?= (int)date('Y') ?>" min="2020" max="2035">
          </div>
          <div class="col-4"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Drukuj</button></div>
        </form>
      </div></div>
    </div>

    <div class="col-12 col-md-6">
      <div class="card h-100"><div class="card-body">
        <h3 class="h6 fw-semibold"><i class="bi bi-file-earmark-spreadsheet me-2 text-primary" aria-hidden="true"></i>Wzór sylabusu (CSV)</h3>
        <div class="d-flex gap-2 mt-1">
          <a class="btn btn-sm btn-outline-primary" href="syllabus_wzor.php" target="_blank">Pusty wzór</a>
          <a class="btn btn-sm btn-outline-primary" href="syllabus_wzor.php?przyklad=1" target="_blank">Przykład (Python)</a>
        </div>
      </div></div>
    </div>

  </div>

  <h2 class="h6 fw-semibold text-uppercase text-body-secondary mb-2">Historia wygenerowanych wydruków</h2>
  <div class="card border-0 shadow-sm">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Historia wygenerowanych dokumentów</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Data</th>
            <th scope="col">Dokument</th>
            <th scope="col">Grupa</th>
            <th scope="col">Kursant</th>
            <th scope="col">Kto</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$log): ?>
          <tr><td colspan="5" class="text-center text-body-secondary py-4">Brak wygenerowanych wydruków.</td></tr>
          <?php endif; ?>
          <?php foreach ($log as $l): ?>
          <tr>
            <td class="text-nowrap small"><?= h(date('d.m.Y H:i', strtotime($l['created_at']))) ?></td>
            <td><?= h($l['label'] !== '' ? $l['label'] : $l['type']) ?></td>
            <td class="small text-body-secondary"><?= h($l['course_name'] ?? '') ?: '—' ?></td>
            <td class="small text-body-secondary"><?= h($l['client_name'] ?? '') ?: '—' ?></td>
            <td class="small text-body-secondary"><?= h($l['generated_by_name']) ?: '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>

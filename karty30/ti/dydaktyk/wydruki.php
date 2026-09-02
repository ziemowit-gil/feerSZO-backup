<?php
/**
 * karty30/ti/dydaktyk/wydruki.php — Wydruki i raporty panelu kierownika.
 *
 * Jedno miejsce dla wszystkiego, co kierownik drukuje/eksportuje/przegląda:
 *  1) Katalog wydruków — mini-formularze wyboru parametrów (grupa/kursant/
 *     miesiąc), otwiera wynik w nowej karcie; + linki do zestawień, które
 *     żyją (z powodu własnych filtrów) w innych modułach (Zapisy, Dostępności).
 *  2) Lekcje z niepewnym terminem / możliwą zmianą — zestawienie po date_flag
 *     (K30_TI_DATE_FLAGS), wszystkie kursy naraz.
 *  3) Raporty — frekwencja/rozliczenia (per uczestnik/grupa/prowadzący) i
 *     sprawozdanie do WUP (przeniesione tu z osobnej strony raporty.php,
 *     która teraz przekierowuje na tę stronę).
 *  4) Historia — log faktycznie wygenerowanych dokumentów (k30_ti_print_log),
 *     zasilany przez poszczególne endpointy (hours_pdf.php, billing_pdf.php…).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_print_log.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$dyd_name = (string)($me['name'] ?? '');
karty30_migrate();

$courses     = k30_ti_courses(true);
$courses_all = k30_ti_courses(false);
$clients = db_all(
    "SELECT DISTINCT cl.id, cl.name FROM k30_clients cl
     JOIN k30_ti_enrollments e ON e.client_id=cl.id AND e.status='active'
     ORDER BY cl.name COLLATE NOCASE"
);
$instructors    = db_all(
    "SELECT DISTINCT u.id, u.name FROM k30_ti_courses c
     JOIN users u ON u.id=c.instructor_id
     WHERE c.is_active=1 ORDER BY u.name COLLATE NOCASE"
);
$ti_instructors = k30_ti_instructors();

$months_pl = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
              7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$cur_m = (int)date('n'); $cur_y = (int)date('Y');

$log = ti_print_log_list(200);

// Lekcje z adnotacją niepewności terminu (date_flag) — wszystkie kursy, od dziś wzwyż
$uncertain_lessons = db_all(
    "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.topic, s.date_flag,
            c.id AS course_id, c.name AS course_name, u.name AS instructor_name
       FROM k30_ti_sessions s
       JOIN k30_ti_courses c ON c.id = s.course_id
       LEFT JOIN users u ON u.id = c.instructor_id
      WHERE s.date_flag IN ('tentative','change_possible') AND s.lesson_date >= ?
      ORDER BY s.lesson_date, s.time_from",
    [date('Y-m-d')]
);

// Blok raportu (miesięczny + roczny) z filtrem — wydruki w karty30/ti/ (przeniesione z raporty.php)
$report_block = function (string $title, string $icon, string $desc, string $monthly, string $annual, string $filter_name, string $filter_all, array $filter_opts) {
    ?>
    <div class="col-lg-4">
      <div class="border rounded h-100 p-3">
        <div class="fw-semibold mb-1"><i class="bi bi-<?= h($icon) ?> me-1 text-primary" aria-hidden="true"></i><?= h($title) ?></div>
        <div class="text-body-secondary mb-2" style="font-size:.8rem"><?= h($desc) ?></div>
        <form method="get" action="../<?= h($monthly) ?>" target="_blank" rel="noopener" class="mb-2">
          <label class="form-label small mb-1"><?= h($filter_all === 'Wszystkie kursy' ? 'Kurs' : 'Prowadzący') ?></label>
          <select name="<?= h($filter_name) ?>" class="form-select form-select-sm mb-2">
            <option value="0"><?= h($filter_all) ?></option>
            <?php foreach ($filter_opts as $o): ?>
            <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="input-group input-group-sm">
            <input type="month" name="m" value="<?= h(date('Y-m')) ?>" class="form-control" aria-label="Miesiąc raportu">
            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Miesięczny</button>
          </div>
        </form>
        <form method="get" action="../<?= h($annual) ?>" target="_blank" rel="noopener">
          <select name="<?= h($filter_name) ?>" class="form-select form-select-sm mb-2" aria-label="Filtr raportu rocznego">
            <option value="0"><?= h($filter_all) ?></option>
            <?php foreach ($filter_opts as $o): ?>
            <option value="<?= (int)$o['id'] ?>"><?= h($o['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="input-group input-group-sm">
            <input type="number" name="y" value="<?= (int)date('Y') ?>" min="2000" max="2100" class="form-control" aria-label="Rok raportu">
            <button type="submit" class="btn btn-outline-danger"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Roczny</button>
          </div>
        </form>
      </div>
    </div>
    <?php
};

$KP_TITLE  = 'Wydruki i raporty — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">

<?php $KIER_CUR = 'wydruki.php'; $KIER_LABEL = 'Wydruki i raporty';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">
  <h1 class="h4 fw-bold mb-3"><i class="bi bi-printer text-primary me-2" aria-hidden="true"></i>Wydruki i raporty</h1>
  <?= function_exists('flash_html') ? flash_html() : '' ?>

  <h2 class="h6 fw-semibold text-uppercase text-body-secondary mb-2">Katalog wydruków</h2>
  <div class="card border-0 shadow-sm mb-4">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Katalog wydruków i eksportów panelu kierownika — parametry i pobieranie</caption>
        <thead class="table-light">
          <tr>
            <th scope="col" style="width:26%">Wydruk</th>
            <th scope="col">Parametry i pobieranie</th>
          </tr>
        </thead>
        <tbody>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-clock-history me-1 text-primary" aria-hidden="true"></i>Rozpiska godzin kursanta</div>
            <div class="small text-body-secondary">PDF</div>
          </td>
          <td>
            <form method="get" action="hours_pdf.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="client_id" class="form-select form-select-sm w-auto" style="min-width:14rem" required>
                <option value="">— wybierz kursanta —</option>
                <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
              </select>
              <select name="month" class="form-select form-select-sm w-auto">
                <?php foreach ($months_pl as $mn => $ml): ?><option value="<?= $mn ?>" <?= $mn===$cur_m?'selected':'' ?>><?= h($ml) ?></option><?php endforeach; ?>
              </select>
              <input type="number" name="year" class="form-control form-control-sm" style="width:6.5rem" value="<?= $cur_y ?>" min="2020" max="2100">
              <button class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Generuj</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-receipt me-1 text-primary" aria-hidden="true"></i>Zestawienie rozliczeń grupy</div>
            <div class="small text-body-secondary">PDF</div>
          </td>
          <td>
            <form method="get" action="billing_pdf.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="course_id" class="form-select form-select-sm w-auto" style="min-width:14rem" required>
                <option value="">— wybierz grupę —</option>
                <?php foreach ($courses as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Generuj</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-receipt-cutoff me-1 text-primary" aria-hidden="true"></i>Zestawienie pozycji do FVAT</div>
            <div class="small text-body-secondary">PDF</div>
          </td>
          <td>
            <form method="get" action="billing_fv_summary.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="course_id" class="form-select form-select-sm w-auto" style="min-width:14rem" required>
                <option value="">— wybierz grupę —</option>
                <?php foreach ($courses as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
              </select>
              <select name="month" class="form-select form-select-sm w-auto">
                <?php foreach ($months_pl as $mn => $ml): ?><option value="<?= $mn ?>" <?= $mn===$cur_m?'selected':'' ?>><?= h($ml) ?></option><?php endforeach; ?>
              </select>
              <input type="number" name="year" class="form-control form-control-sm" style="width:6.5rem" value="<?= $cur_y ?>" min="2020" max="2100">
              <button class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Generuj</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-clipboard-check me-1 text-primary" aria-hidden="true"></i>Lista obecności grupy</div>
            <div class="small text-body-secondary">PDF</div>
          </td>
          <td>
            <form method="get" action="attendance_pdf.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="course_id" class="form-select form-select-sm w-auto" style="min-width:14rem" required>
                <option value="">— wybierz grupę —</option>
                <?php foreach ($courses as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-primary"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Generuj</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-filetype-csv me-1 text-primary" aria-hidden="true"></i>Eksport CSV frekwencji</div>
          </td>
          <td>
            <form method="get" action="attendance_csv.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <input type="month" name="month" class="form-control form-control-sm w-auto" value="<?= sprintf('%04d-%02d', $cur_y, $cur_m) ?>">
              <select name="course_id" class="form-select form-select-sm w-auto" style="min-width:14rem">
                <option value="0">— wszystkie grupy —</option>
                <?php foreach ($courses as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-primary"><i class="bi bi-filetype-csv me-1" aria-hidden="true"></i>Generuj</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-calendar-week me-1 text-primary" aria-hidden="true"></i>Plan zajęć prowadzącego</div>
          </td>
          <td>
            <form method="get" action="plan_print.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="instructor_id" class="form-select form-select-sm w-auto" style="min-width:14rem" required>
                <option value="">— wybierz prowadzącego —</option>
                <?php foreach ($instructors as $ins): ?><option value="<?= (int)$ins['id'] ?>"><?= h($ins['name']) ?></option><?php endforeach; ?>
              </select>
              <select name="weeks" class="form-select form-select-sm w-auto">
                <?php foreach ([4,8,12,16,26] as $w): ?><option value="<?= $w ?>" <?= $w===8?'selected':'' ?>><?= $w ?> tygodni</option><?php endforeach; ?>
                <option value="0">Ogólny (bez limitu tygodni)</option>
              </select>
              <button class="btn btn-sm btn-outline-primary" formaction="plan_print.php"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pokaż</button>
              <button class="btn btn-sm btn-primary" formaction="plan_pdf.php"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</button>
              <button class="btn btn-sm btn-primary" formaction="plan_docx.php"><i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i>DOCX</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-grid-3x3 me-1 text-primary" aria-hidden="true"></i>Plan zajęć prowadzącego - siatka</div>
            <div class="small text-body-secondary">Wszystkie kursy prowadzącego naraz (własne + zastępstwa).</div>
          </td>
          <td>
            <form method="get" action="plan_librus_instructor.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="instructor_id" class="form-select form-select-sm w-auto" style="min-width:14rem" required>
                <option value="">— wybierz prowadzącego —</option>
                <?php foreach ($instructors as $ins): ?><option value="<?= (int)$ins['id'] ?>"><?= h($ins['name']) ?></option><?php endforeach; ?>
              </select>
              <select name="weeks" class="form-select form-select-sm w-auto">
                <?php foreach ([4,8,12,16,26,52] as $w): ?><option value="<?= $w ?>" <?= $w===12?'selected':'' ?>><?= $w ?> tygodni</option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary" formaction="plan_librus_instructor.php"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pokaż</button>
              <button class="btn btn-sm btn-primary" formaction="plan_librus_instructor_pdf.php"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-calendar2-check me-1 text-primary" aria-hidden="true"></i>Eksport iCal — prowadzący</div>
            <div class="small text-body-secondary">Plik .ics — wszystkie kursy prowadzącego (własne + zastępstwa).</div>
          </td>
          <td>
            <form method="get" action="ical_export.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="instructor_id" class="form-select form-select-sm w-auto" style="min-width:14rem" required>
                <option value="">— wybierz prowadzącego —</option>
                <?php foreach ($instructors as $ins): ?><option value="<?= (int)$ins['id'] ?>"><?= h($ins['name']) ?></option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-primary"><i class="bi bi-download me-1" aria-hidden="true"></i>Pobierz .ics</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-grid-3x3 me-1 text-primary" aria-hidden="true"></i>Plan zajęć grupy - siatka</div>
          </td>
          <td>
            <form method="get" action="plan_librus.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="course_id" class="form-select form-select-sm w-auto" style="min-width:14rem" required>
                <option value="">— wybierz grupę —</option>
                <?php foreach ($courses as $co): ?><option value="<?= (int)$co['id'] ?>"><?= h($co['name']) ?></option><?php endforeach; ?>
              </select>
              <select name="weeks" class="form-select form-select-sm w-auto">
                <?php foreach ([4,8,12,16,26,52] as $w): ?><option value="<?= $w ?>" <?= $w===12?'selected':'' ?>><?= $w ?> tygodni</option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary" formaction="plan_librus.php"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pokaż</button>
              <button class="btn btn-sm btn-primary" formaction="plan_librus_pdf.php"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-grid-3x3 me-1 text-primary" aria-hidden="true"></i>Plan zajęć kursanta - siatka</div>
            <div class="small text-body-secondary">Wszystkie aktywne grupy jednego kursanta naraz.</div>
          </td>
          <td>
            <form method="get" action="plan_librus_client.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="client_id" class="form-select form-select-sm w-auto" style="min-width:14rem" required>
                <option value="">— wybierz kursanta —</option>
                <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
              </select>
              <select name="weeks" class="form-select form-select-sm w-auto">
                <?php foreach ([4,8,12,16,26,52] as $w): ?><option value="<?= $w ?>" <?= $w===12?'selected':'' ?>><?= $w ?> tygodni</option><?php endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-primary" formaction="plan_librus_client.php"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pokaż</button>
              <button class="btn btn-sm btn-primary" formaction="plan_librus_client_pdf.php"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-grid-3x3 me-1 text-primary" aria-hidden="true"></i>Plan zajęć — cała instytucja, siatka</div>
            <div class="small text-body-secondary">Wszystkie aktywne grupy naraz — kolizje kilku grup w tym samym terminie to norma.</div>
          </td>
          <td>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-sm btn-outline-primary" href="plan_librus_all.php" target="_blank"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pokaż</a>
              <a class="btn btn-sm btn-primary" href="plan_librus_all_pdf.php" target="_blank"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
            </div>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-geo-alt me-1 text-primary" aria-hidden="true"></i>Harmonogram grup</div>
            <div class="small text-body-secondary">Dzień, godziny, lokalizacja — grupy stacjonarne, do druku lub jako Markdown.</div>
          </td>
          <td>
            <a class="btn btn-sm btn-primary" href="harmonogram_lokalizacje.php" target="_blank"><i class="bi bi-table me-1" aria-hidden="true"></i>Pokaż zestawienie</a>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-clipboard-check me-1 text-primary" aria-hidden="true"></i>Wykaz sal do rezerwacji</div>
            <div class="small text-body-secondary">Lista kontrolna dla koordynatora — terminy z salą, ze statusem zgłoszenia.</div>
          </td>
          <td>
            <form method="get" action="sale_rezerwacje.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <select name="range" class="form-select form-select-sm w-auto">
                <option value="week">Tydzień (bieżący)</option>
                <option value="month">Miesiąc (bieżący)</option>
                <option value="quarter">3 miesiące (od bieżącego)</option>
              </select>
              <button class="btn btn-sm btn-outline-primary" formaction="sale_rezerwacje.php"><i class="bi bi-eye me-1" aria-hidden="true"></i>Pokaż</button>
              <button class="btn btn-sm btn-primary" formaction="sale_rezerwacje_pdf.php"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-journal-text me-1 text-primary" aria-hidden="true"></i>Protokół zajęć</div>
            <div class="small text-body-secondary">PDF</div>
          </td>
          <td class="small text-body-secondary">
            Przypisany do konkretnych lekcji — wydruk z widoku grupy:
            <a href="index.php?tab=lekcje">Zajęcia → zakładka Protokół</a>.
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-file-earmark-text me-1 text-primary" aria-hidden="true"></i>Karta pojedynczej lekcji</div>
            <div class="small text-body-secondary">PDF</div>
          </td>
          <td class="small text-body-secondary">
            Termin, temat, obecność i notatki jednej lekcji — wydruk przy konkretnej lekcji:
            <a href="index.php?tab=lekcje">Zajęcia → „Wejdź” lub dropdown „Wydruki” w wierszu lekcji</a>.
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-calendar-x me-1 text-primary" aria-hidden="true"></i>Wykaz dni wolnych</div>
          </td>
          <td>
            <form method="get" action="dni_wolne_pdf.php" target="_blank" class="d-flex flex-wrap gap-2 align-items-center">
              <input type="number" name="year" class="form-control form-control-sm" style="width:6.5rem" value="<?= (int)date('Y') ?>" min="2020" max="2035">
              <button class="btn btn-sm btn-primary" formaction="dni_wolne_pdf.php"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</button>
              <button class="btn btn-sm btn-primary" formaction="dni_wolne_xlsx.php"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>XLSX</button>
              <button class="btn btn-sm btn-primary" formaction="dni_wolne_docx.php"><i class="bi bi-file-earmark-word me-1" aria-hidden="true"></i>DOCX</button>
            </form>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-file-earmark-spreadsheet me-1 text-primary" aria-hidden="true"></i>Wzór sylabusu</div>
            <div class="small text-body-secondary">CSV</div>
          </td>
          <td>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-sm btn-outline-primary" href="syllabus_wzor.php" target="_blank">Pusty wzór</a>
              <a class="btn btn-sm btn-outline-primary" href="syllabus_wzor.php?przyklad=1" target="_blank">Przykład (Python)</a>
            </div>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-ticket-perforated me-1 text-primary" aria-hidden="true"></i>Zestawienia zapisów na zajęcia</div>
            <div class="small text-body-secondary">Rezerwacje za żetony — per prowadzący, rodzaj zajęć, kursant lub pula.</div>
          </td>
          <td>
            <a class="btn btn-sm btn-primary" href="rekrutacja.php?tab=zestawienia"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz zestawienia zapisów</a>
          </td>
        </tr>

        <tr>
          <td>
            <div class="fw-semibold"><i class="bi bi-clock-history me-1 text-primary" aria-hidden="true"></i>Dostępności i grafiki prowadzących</div>
            <div class="small text-body-secondary">Okna dostępności, grafik tury, kalendarz naborów, plakat — z podglądu w tych ekranach.</div>
          </td>
          <td>
            <div class="d-flex flex-wrap gap-2">
              <a class="btn btn-sm btn-outline-primary" href="dostepnosci.php">Dostępności</a>
              <a class="btn btn-sm btn-outline-primary" href="rekrutacja.php?tab=grupy">Grafik tury (grupy)</a>
            </div>
          </td>
        </tr>

        </tbody>
      </table>
    </div>
  </div>

  <h2 class="h6 fw-semibold text-uppercase text-body-secondary mb-2">Lekcje z niepewnym terminem / możliwą zmianą</h2>
  <div class="card border-0 shadow-sm mb-4">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Nadchodzące lekcje oznaczone jako niepewne lub z możliwą zmianą terminu, wszystkie kursy</caption>
        <thead class="table-light">
          <tr>
            <th scope="col">Data</th>
            <th scope="col">Godziny</th>
            <th scope="col">Grupa</th>
            <th scope="col">Prowadzący</th>
            <th scope="col">Temat</th>
            <th scope="col">Adnotacja</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$uncertain_lessons): ?>
          <tr><td colspan="6" class="text-center text-body-secondary py-4">Brak nadchodzących lekcji z taką adnotacją.</td></tr>
          <?php endif; ?>
          <?php foreach ($uncertain_lessons as $ul): $dfl = K30_TI_DATE_FLAGS[(string)$ul['date_flag']] ?? null; ?>
          <tr>
            <td class="text-nowrap small"><?= h(date('d.m.Y', strtotime((string)$ul['lesson_date']))) ?></td>
            <td class="text-nowrap small"><?= h(substr((string)$ul['time_from'], 0, 5)) ?><?= $ul['time_to'] ? '–' . h(substr((string)$ul['time_to'], 0, 5)) : '' ?></td>
            <td class="small"><a href="index.php?course=<?= (int)$ul['course_id'] ?>&tab=plan"><?= h($ul['course_name']) ?></a></td>
            <td class="small text-body-secondary"><?= h($ul['instructor_name'] ?? '') ?: '—' ?></td>
            <td class="small"><?= trim((string)$ul['topic']) !== '' ? h($ul['topic']) : '<span class="text-muted">—</span>' ?></td>
            <td class="small"><?php if ($dfl): ?><span class="badge <?= h($dfl['class']) ?>"><?= h($dfl['badge']) ?> <?= h($dfl['label']) ?></span><?php endif; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <h2 class="h6 fw-semibold text-uppercase text-body-secondary mb-2">Raporty</h2>

  <div class="card border-0 shadow-sm mb-3 border-primary-subtle">
    <div class="card-body d-flex align-items-center gap-3 py-3 flex-wrap">
      <i class="bi bi-table fs-2 text-primary" aria-hidden="true"></i>
      <div class="flex-grow-1">
        <div class="fw-semibold">Kreator Raportów</div>
        <div class="text-body-secondary small">Zaległości · Nadpłaty · Frekwencja — tabele przestawne z eksportem CSV
          <span class="text-body-secondary">(moduł admina — wymaga logowania do SZO)</span></div>
      </div>
      <a href="../kreator_raportow.php" target="_blank" rel="noopener" class="btn btn-primary btn-sm flex-shrink-0">
        <i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz kreator
      </a>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3" id="raporty-ti">
    <div class="card-header bg-white d-flex align-items-center">
      <i class="bi bi-file-earmark-bar-graph me-2 text-primary" aria-hidden="true"></i>
      <span class="fw-semibold">Frekwencja i rozliczenia</span>
      <span class="ms-2 small text-body-secondary">PDF · wybierz okres i zakres</span>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <?php
          $report_block(
            'Per uczestnik', 'file-earmark-person',
            'Frekwencja we wszystkich kursach + rozliczenia (należności/wpłaty) i saldo konta — sekcja na kursanta.',
            'participant_monthly.php', 'participant_annual.php', 'course_id', 'Wszystkie kursy', $courses_all);
          $report_block(
            'Per grupa', 'collection',
            'Frekwencja uczestników w grupie + ich rozliczenia i saldo — sekcja na kurs. Śr. frekwencja grupy.',
            'group_monthly.php', 'group_annual.php', 'course_id', 'Wszystkie kursy', $courses_all);
          $report_block(
            'Per prowadzący', 'person-badge',
            'Frekwencja grup prowadzącego (lekcje odbyte/odwołane, śr. frekwencja) — bez kwot.',
            'instructor_monthly.php', 'instructor_annual.php', 'instructor_id', 'Wszyscy prowadzący', $ti_instructors);
        ?>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-4" id="raport-wup">
    <div class="card-header bg-white d-flex align-items-center">
      <i class="bi bi-bank me-2 text-primary" aria-hidden="true"></i>
      <span class="fw-semibold">Sprawozdanie do Wojewódzkiego Urzędu Pracy</span>
      <span class="ms-2 small text-body-secondary">ustawa o promocji zatrudnienia · podgląd → PDF</span>
    </div>
    <div class="card-body">
      <p class="text-body-secondary small mb-3">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
        Podgląd przed wydrukiem: zajęcia wg prowadzących i grup, wskaźniki (zatrudnieni, uczestnicy w tym z niepełnosprawnością,
        zajęcia online/stacjonarne, odwołania, frekwencja), pola narracyjne i wgranie podpisanego dokumentu.
        Dane RIS i kierownika ustawia administrator w module TI (<a href="../ris.php" target="_blank" rel="noopener">Dane do RIS
        <i class="bi bi-box-arrow-up-right" style="font-size:.65rem" aria-hidden="true"></i></a>).
      </p>
      <form id="wupPreviewForm" action="../wup_report.php" class="row g-2 align-items-end">
        <div class="col-auto">
          <label class="form-label small mb-1" for="wup-from">Od</label>
          <input type="date" id="wup-from" name="from" value="<?= h(date('Y-m-d', strtotime('first day of last month'))) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-auto">
          <label class="form-label small mb-1" for="wup-to">Do</label>
          <input type="date" id="wup-to" name="to" value="<?= h(date('Y-m-d', strtotime('last day of last month'))) ?>" class="form-control form-control-sm">
        </div>
        <div class="col-md-4">
          <label class="form-label small mb-1" for="wup-instr">Prowadzący</label>
          <select id="wup-instr" name="instructor_id" class="form-select form-select-sm">
            <option value="0">Wszyscy prowadzący</option>
            <?php foreach ($ti_instructors as $it): ?>
            <option value="<?= (int)$it['id'] ?>"><?= h($it['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-auto">
          <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-1" aria-hidden="true"></i>Otwórz podgląd</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Modal: podgląd sprawozdania WUP -->
  <div class="modal fade" id="wupModal" tabindex="-1" aria-labelledby="wupModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header py-2">
          <h2 class="modal-title h6 mb-0" id="wupModalLabel"><i class="bi bi-bank me-2 text-primary" aria-hidden="true"></i>Sprawozdanie do WUP — podgląd</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body" id="wupModalBody">
          <div class="text-center text-body-secondary py-5"><div class="spinner-border" role="status" aria-hidden="true"></div><div class="mt-2 small">Ładowanie podglądu…</div></div>
        </div>
      </div>
    </div>
  </div>
  <script>
  document.addEventListener('DOMContentLoaded', function () {
    var form  = document.getElementById('wupPreviewForm');
    var modalEl = document.getElementById('wupModal');
    if (!form || !modalEl || !window.bootstrap) return; // brak Bootstrapa → zwykły submit (fallback)
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var body  = document.getElementById('wupModalBody');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var qs = new URLSearchParams(new FormData(form)).toString();
      body.innerHTML = '<div class="text-center text-body-secondary py-5"><div class="spinner-border" role="status"></div><div class="mt-2 small">Ładowanie podglądu…</div></div>';
      modal.show();
      fetch('../wup_report.php?embed=1&' + qs, { credentials: 'same-origin' })
        .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
        .then(function (html) { body.innerHTML = html; })
        .catch(function (err) { body.innerHTML = '<div class="alert alert-danger m-3">Nie udało się wczytać podglądu: ' + err.message + '</div>'; });
    });
  });
  </script>
  <?php include __DIR__ . '/../_wup_pdf_preview.php'; ?>

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
<?php $PRINT_TITLE = 'Wydruki i raporty'; include __DIR__ . '/_print_page.php'; ?>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>

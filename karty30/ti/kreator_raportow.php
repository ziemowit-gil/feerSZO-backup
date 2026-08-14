<?php
/**
 * karty30/ti/kreator_raportow.php — Kreator raportów TI (tabele przestawne).
 * Raporty: Zaległości | Nadpłaty | Frekwencja (per kursant/grupa × miesiąc/kwartał/rok).
 * Eksport CSV dla każdego raportu.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_payments.php';

k30_require_access();
karty30_migrate();
ti_payments_migrate();
if (!(can_write('karty30') || is_admin())) { http_response_code(403); die('Brak uprawnień.'); }

// ── Filtry ────────────────────────────────────────────────────────────────────
$report    = in_array($_GET['report'] ?? '', ['zaleglosci','nadplaty','frekwencja']) ? $_GET['report'] : 'zaleglosci';
$preset    = $_GET['preset'] ?? 'school_year';
$course_id = (int)($_GET['course_id'] ?? 0);
$group_by  = in_array($_GET['group_by'] ?? '', ['month','quarter','year']) ? $_GET['group_by'] : 'month';
$export    = ($_GET['export'] ?? '') === 'csv';

// Okresy
$now_y = (int)date('Y');
$now_m = (int)date('n');
$sy_start = $now_m >= 9 ? $now_y : $now_y - 1; // rok szkolny od września

$presets = [
    'this_month'       => [sprintf('%d-%02d', $now_y, $now_m), sprintf('%d-%02d', $now_y, $now_m)],
    'this_year'        => [sprintf('%d-01', $now_y),            sprintf('%d-12', $now_y)],
    'school_year'      => [sprintf('%d-09', $sy_start),         sprintf('%d-08', $sy_start + 1)],
    'last_school_year' => [sprintf('%d-09', $sy_start - 1),     sprintf('%d-08', $sy_start)],
    'custom'           => [$_GET['date_from'] ?? date('Y-m'), $_GET['date_to'] ?? date('Y-m')],
];
$valid_presets = array_keys($presets);
if (!in_array($preset, $valid_presets, true)) $preset = 'school_year';
[$date_from_str, $date_to_str] = $presets[$preset];

// Normalizuj YYYY-MM do YYYY-MM-DD
$date_from = $date_from_str . '-01';
$date_to   = date('Y-m-t', strtotime($date_to_str . '-01')); // ostatni dzień miesiąca

// billing: from_key/to_key = YYYYMM int
[$fy, $fm] = explode('-', $date_from_str);
[$ty, $tm] = explode('-', $date_to_str);
$from_key = (int)$fy * 100 + (int)$fm;
$to_key   = (int)$ty * 100 + (int)$tm;

$courses        = k30_ti_courses(false);

// ── Kwartał: labels ───────────────────────────────────────────────────────────
function _quarter_label(string $rok, string $m): string {
    return 'Q' . ceil((int)$m / 3) . ' ' . $rok;
}
function _period_label(string $rok, string $m, string $group_by): string {
    if ($group_by === 'year') return $rok;
    if ($group_by === 'quarter') return _quarter_label($rok, $m);
    $mn = [1=>'Sty',2=>'Lut',3=>'Mar',4=>'Kwi',5=>'Maj',6=>'Cze',
            7=>'Lip',8=>'Sie',9=>'Wrz',10=>'Paź',11=>'Lis',12=>'Gru'];
    return ($mn[(int)$m] ?? $m) . ' ' . substr($rok, 2);
}

// ══════════════════════════════════════════════════════════════════════════════
// RAPORT: ZALEGŁOŚCI
// ══════════════════════════════════════════════════════════════════════════════
$zal_rows = []; $zal_total_due = 0; $zal_total_paid = 0; $zal_total_debt = 0;

if ($report === 'zaleglosci' || $export) {
    $zal_params = [$from_key, $to_key];
    $zal_where  = "b.status IN ('issued','paid') AND (b.year*100 + b.month) BETWEEN ? AND ?";
    if ($course_id > 0) { $zal_where .= " AND b.course_id=?"; $zal_params[] = $course_id; }

    $zal_raw = db_all(
        "SELECT cl.id AS client_id, cl.name AS klient, cl.email,
                COALESCE(c.name,'') AS kurs,
                b.course_id,
                ROUND(SUM(b.amount + COALESCE(b.adjustment,0)), 2) AS naleznosci,
                ROUND(SUM(COALESCE(b.paid_amount,0)), 2) AS zaplacono,
                ROUND(SUM(b.amount + COALESCE(b.adjustment,0) - COALESCE(b.paid_amount,0)), 2) AS zaleglost,
                MIN(CASE WHEN b.status='issued' AND b.due_date < date('now') THEN b.due_date END) AS overdue_from
         FROM k30_ti_billing b
         JOIN k30_clients cl ON cl.id=b.client_id
         LEFT JOIN k30_ti_courses c ON c.id=b.course_id AND b.course_id>0
         WHERE $zal_where
         GROUP BY cl.id, b.course_id
         HAVING zaleglost > 0.01
         ORDER BY klient COLLATE NOCASE, kurs",
        $zal_params
    );

    // Grupuj per klient (mogą być >1 wiersz per klient gdy multi-kurs)
    $zal_by_client = [];
    foreach ($zal_raw as $r) {
        $cid = (int)$r['client_id'];
        if (!isset($zal_by_client[$cid])) {
            $zal_by_client[$cid] = ['klient'=>$r['klient'],'email'=>$r['email'],'kursy'=>[],'naleznosci'=>0,'zaplacono'=>0,'zaleglost'=>0,'overdue_from'=>null];
        }
        $mg = &$zal_by_client[$cid];
        if ($r['kurs'] !== '') $mg['kursy'][] = $r['kurs'];
        $mg['naleznosci'] += (float)$r['naleznosci'];
        $mg['zaplacono']  += (float)$r['zaplacono'];
        $mg['zaleglost']  += (float)$r['zaleglost'];
        if ($r['overdue_from'] && (!$mg['overdue_from'] || $r['overdue_from'] < $mg['overdue_from'])) $mg['overdue_from'] = $r['overdue_from'];
        unset($mg);
    }
    // Sortuj po zaległości DESC
    uasort($zal_by_client, fn($a,$b) => $b['zaleglost'] <=> $a['zaleglost']);
    $zal_rows = array_values($zal_by_client);
    foreach ($zal_rows as $r) {
        $zal_total_due  += $r['naleznosci'];
        $zal_total_paid += $r['zaplacono'];
        $zal_total_debt += $r['zaleglost'];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// RAPORT: NADPŁATY
// ══════════════════════════════════════════════════════════════════════════════
$nad_rows = []; $nad_total_charge = 0; $nad_total_paid = 0; $nad_total_credit = 0;

if ($report === 'nadplaty' || $export) {
    // Nadpłata = globalne saldo (niezależne od okresu — FIFO alokacja jest globalna)
    $nad_raw = db_all(
        "SELECT cl.id AS client_id, cl.name AS klient, cl.email,
                ROUND(COALESCE(p.total_paid,0), 2) AS zaplacono,
                ROUND(COALESCE(ch.total_due,0), 2) AS naleznosci,
                ROUND(COALESCE(p.total_paid,0) - COALESCE(ch.total_due,0), 2) AS nadplata
         FROM k30_clients cl
         JOIN (SELECT client_id, SUM(amount) AS total_paid FROM k30_ti_payments GROUP BY client_id) p
              ON p.client_id=cl.id
         LEFT JOIN (SELECT client_id, SUM(amount+COALESCE(adjustment,0)) AS total_due
                    FROM k30_ti_billing WHERE status IN ('issued','paid') GROUP BY client_id) ch
              ON ch.client_id=cl.id
         WHERE COALESCE(p.total_paid,0) - COALESCE(ch.total_due,0) > 0.01
         ORDER BY nadplata DESC",
        []
    );
    $nad_rows = $nad_raw;
    foreach ($nad_rows as $r) {
        $nad_total_charge += (float)$r['naleznosci'];
        $nad_total_paid   += (float)$r['zaplacono'];
        $nad_total_credit += (float)$r['nadplata'];
    }
}

// ══════════════════════════════════════════════════════════════════════════════
// RAPORT: FREKWENCJA — tabela przestawna (wiersze=kursant, kolumny=okres)
// ══════════════════════════════════════════════════════════════════════════════
$freq_rows = []; $freq_periods = []; $freq_pivot = [];

if ($report === 'frekwencja') {
    $freq_params = [$date_from, $date_to];
    $freq_where  = "s.status IN ('held','individual_change','remote_material')
                    AND s.lesson_date BETWEEN ? AND ?";
    if ($course_id > 0) { $freq_where .= " AND s.course_id=?"; $freq_params[] = $course_id; }

    $freq_raw = db_all(
        "SELECT cl.id AS client_id, cl.name AS klient,
                c.id AS course_id, c.name AS kurs,
                strftime('%Y', s.lesson_date) AS rok,
                strftime('%m', s.lesson_date) AS miesiac,
                COUNT(DISTINCT s.id) AS sesje,
                ROUND(SUM(COALESCE(s.duration_min,60)) / 60.0, 2) AS godziny,
                SUM(CASE WHEN a.attended=1 THEN 1 ELSE 0 END) AS obecny,
                SUM(CASE WHEN COALESCE(a.attended,0)=0 AND COALESCE(a.cancelled,0)=0
                               AND COALESCE(a.cancel_pending,0)=0 AND COALESCE(a.no_show,0)=0
                         THEN 1 ELSE 0 END) AS nieobecny_n,
                SUM(CASE WHEN COALESCE(a.no_show,0)=1 THEN 1 ELSE 0 END) AS no_show
         FROM k30_ti_sessions s
         JOIN k30_ti_courses c ON c.id=s.course_id
         JOIN k30_ti_enrollments e ON e.course_id=s.course_id AND e.status IN ('active','inactive')
         JOIN k30_clients cl ON cl.id=e.client_id
         LEFT JOIN k30_ti_attendance a ON a.session_id=s.id AND a.client_id=cl.id
         WHERE $freq_where
         GROUP BY rok, miesiac, cl.id, c.id
         ORDER BY klient COLLATE NOCASE, kurs, rok, miesiac",
        $freq_params
    );

    // Zbierz unikalne okresy i klucze (kursant+kurs)
    $period_keys = [];
    foreach ($freq_raw as $r) {
        $pk = _period_label($r['rok'], $r['miesiac'], $group_by);
        $period_keys[$pk] = true;
    }
    $freq_periods = array_keys($period_keys);

    // Buduj pivot: [klient_id-course_id][period] = dane
    $pivot = [];
    foreach ($freq_raw as $r) {
        $key    = $r['client_id'] . '-' . $r['course_id'];
        $pk     = _period_label($r['rok'], $r['miesiac'], $group_by);
        if (!isset($pivot[$key])) {
            $pivot[$key] = ['klient'=>$r['klient'],'kurs'=>$r['kurs'],
                            'client_id'=>$r['client_id'],'course_id'=>$r['course_id'],'periods'=>[]];
        }
        if (!isset($pivot[$key]['periods'][$pk])) {
            $pivot[$key]['periods'][$pk] = ['sesje'=>0,'godziny'=>0,'obecny'=>0,'nieobecny_n'=>0,'no_show'=>0];
        }
        $p = &$pivot[$key]['periods'][$pk];
        $p['sesje']      += (int)$r['sesje'];
        $p['godziny']    += (float)$r['godziny'];
        $p['obecny']     += (int)$r['obecny'];
        $p['nieobecny_n']+= (int)$r['nieobecny_n'];
        $p['no_show']    += (int)$r['no_show'];
        unset($p);
    }
    $freq_pivot = array_values($pivot);
}

// ══════════════════════════════════════════════════════════════════════════════
// EKSPORT CSV
// ══════════════════════════════════════════════════════════════════════════════
if ($export && in_array($report, ['zaleglosci','nadplaty','frekwencja'], true)) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $report . '_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8 dla Excel
    $out = fopen('php://output', 'w');

    if ($report === 'frekwencja') {
        fputcsv($out, ['Kursant','Kurs','Okres','Sesje odbyte','Godziny','Obecny (lekcje)','Nieobecny','No-show','Frekwencja (%)'], ';');
        foreach ($freq_pivot as $row) {
            foreach ($row['periods'] as $pk => $p) {
                $total = $p['obecny'] + $p['nieobecny_n'] + $p['no_show'];
                $pct   = $total > 0 ? round($p['obecny'] / $total * 100, 1) : '';
                fputcsv($out, [
                    $row['klient'], $row['kurs'], $pk,
                    $p['sesje'], number_format($p['godziny'],2,',',''),
                    $p['obecny'], $p['nieobecny_n'], $p['no_show'],
                    $pct !== '' ? $pct . '%' : '—',
                ], ';');
            }
        }
        fclose($out);
        exit;
    }

    if ($report === 'zaleglosci') {
        fputcsv($out, ['Kursant','E-mail','Grupy','Należności (zł)','Zapłacono (zł)','Zaległość (zł)','Przeterminowane od'], ';');
        foreach ($zal_rows as $r) {
            fputcsv($out, [
                $r['klient'], $r['email'], implode(', ', $r['kursy']),
                number_format($r['naleznosci'],2,',',''),
                number_format($r['zaplacono'],2,',',''),
                number_format($r['zaleglost'],2,',',''),
                $r['overdue_from'] ?? '',
            ], ';');
        }
        fputcsv($out, ['SUMA','','',
            number_format($zal_total_due,2,',',''),
            number_format($zal_total_paid,2,',',''),
            number_format($zal_total_debt,2,',',''),''], ';');
    } else {
        fputcsv($out, ['Kursant','E-mail','Należności (zł)','Wpłacono (zł)','Nadpłata (zł)'], ';');
        foreach ($nad_rows as $r) {
            fputcsv($out, [
                $r['klient'], $r['email'],
                number_format((float)$r['naleznosci'],2,',',''),
                number_format((float)$r['zaplacono'],2,',',''),
                number_format((float)$r['nadplata'],2,',',''),
            ], ';');
        }
        fputcsv($out, ['SUMA','',
            number_format($nad_total_charge,2,',',''),
            number_format($nad_total_paid,2,',',''),
            number_format($nad_total_credit,2,',','')], ';');
    }
    fclose($out);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// HTML
// ══════════════════════════════════════════════════════════════════════════════
$PAGE_TITLE = 'Kreator Raportów — TI';
include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';

// Helper: URL z zamianą params
function _kr_url(array $extra): string {
    $p = array_merge($_GET, $extra);
    unset($p['export']);
    return '?' . http_build_query($p);
}
function _kr_csv_url(): string {
    return '?' . http_build_query(array_merge($_GET, ['export'=>'csv']));
}

$preset_labels = [
    'this_month'       => 'Ten miesiąc',
    'this_year'        => 'Ten rok',
    'school_year'      => 'Rok szkolny ' . $sy_start . '/' . ($sy_start+1),
    'last_school_year' => 'Rok szkolny ' . ($sy_start-1) . '/' . $sy_start,
    'custom'           => 'Własny zakres',
];
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.85rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="raporty.php">Raporty TI</a></li>
  <li class="breadcrumb-item active">Kreator Raportów</li>
</ol></nav>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h4 class="fw-bold mb-0"><i class="bi bi-table text-primary me-2"></i>Kreator Raportów</h4>
  <span class="badge bg-primary-subtle text-primary-emphasis">Tabela przestawna</span>
</div>

<?= flash_html() ?>

<!-- ── Panel filtrów ──────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body pb-2">
    <form method="get" class="row g-2 align-items-end">
      <input type="hidden" name="report" value="<?= h($report) ?>">

      <!-- Preset okresu -->
      <div class="col-12">
        <div class="fw-semibold small mb-2">Zakres okresu</div>
        <div class="d-flex flex-wrap gap-1 mb-2">
          <?php foreach ($preset_labels as $k => $lbl): ?>
          <a href="<?= h(_kr_url(['preset' => $k, 'report' => $report])) ?>"
             class="btn btn-sm <?= $preset === $k ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <?= h($lbl) ?>
          </a>
          <?php endforeach; ?>
        </div>
        <?php if ($preset === 'custom'): ?>
        <div class="d-flex gap-2 align-items-center">
          <label class="small text-body-secondary">Od</label>
          <input type="month" name="date_from" value="<?= h($date_from_str) ?>" class="form-control form-control-sm" style="max-width:150px">
          <label class="small text-body-secondary">Do</label>
          <input type="month" name="date_to"   value="<?= h($date_to_str) ?>"   class="form-control form-control-sm" style="max-width:150px">
          <button type="submit" class="btn btn-primary btn-sm">Zastosuj</button>
        </div>
        <?php endif; ?>
      </div>

      <!-- Kurs -->
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Grupa / kurs</label>
        <select name="course_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="0" <?= $course_id===0?'selected':'' ?>>Wszystkie grupy</option>
          <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $course_id===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if ($report === 'frekwencja'): ?>
      <!-- Grupowanie (tylko frekwencja) -->
      <div class="col-sm-auto">
        <label class="form-label small mb-1">Grupuj po</label>
        <select name="group_by" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="month"   <?= $group_by==='month'?'selected':'' ?>>Miesiąc</option>
          <option value="quarter" <?= $group_by==='quarter'?'selected':'' ?>>Kwartał</option>
          <option value="year"    <?= $group_by==='year'?'selected':'' ?>>Rok</option>
        </select>
      </div>
      <?php endif; ?>

    </form>

    <!-- Zakres i eksport w pasku -->
    <div class="d-flex align-items-center gap-3 mt-2 pt-2 border-top flex-wrap">
      <span class="small text-body-secondary">
        <i class="bi bi-calendar-range me-1"></i>
        <?= h(date('d.m.Y', strtotime($date_from))) ?> – <?= h(date('d.m.Y', strtotime($date_to))) ?>
      </span>
      <?php if (in_array($report, ['zaleglosci','nadplaty','frekwencja'], true)): ?>
      <a href="<?= h(_kr_csv_url()) ?>" class="btn btn-outline-success btn-sm ms-auto">
        <i class="bi bi-filetype-csv me-1"></i>Eksport CSV
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Tabs raportów ──────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $report==='zaleglosci'?'active':'' ?>"
       href="<?= h(_kr_url(['report'=>'zaleglosci'])) ?>">
      <i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>Zaległości
      <?php if ($report==='zaleglosci' && $zal_rows): ?>
      <span class="badge bg-danger ms-1"><?= count($zal_rows) ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $report==='nadplaty'?'active':'' ?>"
       href="<?= h(_kr_url(['report'=>'nadplaty'])) ?>">
      <i class="bi bi-piggy-bank-fill text-success me-1"></i>Nadpłaty
      <?php if ($report==='nadplaty' && $nad_rows): ?>
      <span class="badge bg-success ms-1"><?= count($nad_rows) ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $report==='frekwencja'?'active':'' ?>"
       href="<?= h(_kr_url(['report'=>'frekwencja'])) ?>">
      <i class="bi bi-bar-chart-fill text-primary me-1"></i>Frekwencja
    </a>
  </li>
</ul>

<?php /* ══ ZALEGŁOŚCI ══════════════════════════════════════════════════════ */ ?>
<?php if ($report === 'zaleglosci'): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-triangle-fill text-danger"></i>Zaległości w płatnościach
    <span class="ms-auto text-body-secondary fw-normal small">
      <?= count($zal_rows) ?> kursant<?= count($zal_rows) !== 1 ? 'ów' : '' ?> ·
      łącznie <strong class="text-danger"><?= number_format($zal_total_debt, 2, ',', ' ') ?> zł</strong>
    </span>
  </div>
  <?php if (!$zal_rows): ?>
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-check-circle fs-2 text-success d-block mb-2"></i>
    Brak zaległości w wybranym okresie.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0 table-hover">
      <caption class="visually-hidden">Zaległości kursantów</caption>
      <thead class="table-light">
        <tr>
          <th>Kursant</th>
          <th>Grupy</th>
          <th class="text-end">Należności</th>
          <th class="text-end">Zapłacono</th>
          <th class="text-end text-danger">Zaległość</th>
          <th>Przeterminowane od</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($zal_rows as $r):
          $overdue = !empty($r['overdue_from']);
        ?>
        <tr class="<?= $overdue ? 'table-danger' : '' ?>">
          <td>
            <div class="fw-semibold"><?= h($r['klient']) ?></div>
            <?php if ($r['email']): ?><div class="small text-body-secondary"><?= h($r['email']) ?></div><?php endif; ?>
          </td>
          <td class="small text-body-secondary"><?= $r['kursy'] ? h(implode(', ', $r['kursy'])) : '—' ?></td>
          <td class="text-end"><?= number_format($r['naleznosci'], 2, ',', ' ') ?> zł</td>
          <td class="text-end text-success"><?= number_format($r['zaplacono'], 2, ',', ' ') ?> zł</td>
          <td class="text-end fw-bold text-danger"><?= number_format($r['zaleglost'], 2, ',', ' ') ?> zł</td>
          <td>
            <?php if ($overdue): ?>
              <span class="badge bg-danger"><i class="bi bi-clock me-1"></i><?= h(date('d.m.Y', strtotime($r['overdue_from']))) ?></span>
            <?php else: ?>
              <span class="text-body-secondary small">Terminowe</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="2">SUMA (<?= count($zal_rows) ?> kursantów)</td>
          <td class="text-end"><?= number_format($zal_total_due, 2, ',', ' ') ?> zł</td>
          <td class="text-end text-success"><?= number_format($zal_total_paid, 2, ',', ' ') ?> zł</td>
          <td class="text-end text-danger"><?= number_format($zal_total_debt, 2, ',', ' ') ?> zł</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══ NADPŁATY ════════════════════════════════════════════════════════ */ ?>
<?php elseif ($report === 'nadplaty'): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-piggy-bank-fill text-success"></i>Nadpłaty kursantów
    <span class="ms-auto text-body-secondary fw-normal small">
      <?= count($nad_rows) ?> kursant<?= count($nad_rows) !== 1 ? 'ów' : '' ?> ·
      łącznie <strong class="text-success"><?= number_format($nad_total_credit, 2, ',', ' ') ?> zł</strong>
    </span>
  </div>
  <div class="card-body py-2 text-body-secondary small border-bottom">
    <i class="bi bi-info-circle me-1"></i>Nadpłata = globalne saldo kursanta (łączne wpłaty minus łączne należności).
    Kwota ta jest automatycznie zaliczana na poczet przyszłych zajęć.
  </div>
  <?php if (!$nad_rows): ?>
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-piggy-bank fs-2 d-block mb-2"></i>Brak nadpłat.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table align-middle mb-0 table-hover">
      <caption class="visually-hidden">Nadpłaty kursantów</caption>
      <thead class="table-light">
        <tr>
          <th>Kursant</th>
          <th class="text-end">Należności (ogółem)</th>
          <th class="text-end">Wpłacono (ogółem)</th>
          <th class="text-end text-success">Nadpłata</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($nad_rows as $r): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($r['klient']) ?></div>
            <?php if ($r['email']): ?><div class="small text-body-secondary"><?= h($r['email']) ?></div><?php endif; ?>
          </td>
          <td class="text-end"><?= number_format((float)$r['naleznosci'], 2, ',', ' ') ?> zł</td>
          <td class="text-end"><?= number_format((float)$r['zaplacono'], 2, ',', ' ') ?> zł</td>
          <td class="text-end fw-bold text-success"><?= number_format((float)$r['nadplata'], 2, ',', ' ') ?> zł</td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td>SUMA (<?= count($nad_rows) ?> kursantów)</td>
          <td class="text-end"><?= number_format($nad_total_charge, 2, ',', ' ') ?> zł</td>
          <td class="text-end"><?= number_format($nad_total_paid, 2, ',', ' ') ?> zł</td>
          <td class="text-end text-success"><?= number_format($nad_total_credit, 2, ',', ' ') ?> zł</td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ══ FREKWENCJA — tabela przestawna ════════════════════════════════ */ ?>
<?php elseif ($report === 'frekwencja'): ?>
<div class="card border-0 shadow-sm">
  <div class="card-header bg-transparent fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-bar-chart-fill text-primary"></i>Frekwencja
    <span class="text-body-secondary fw-normal small ms-2">
      pivot: kursant × <?= $group_by === 'month' ? 'miesiąc' : ($group_by === 'quarter' ? 'kwartał' : 'rok') ?>
    </span>
  </div>
  <?php if (!$freq_pivot): ?>
  <div class="card-body text-center text-body-secondary py-5">
    <i class="bi bi-calendar-x fs-2 d-block mb-2"></i>Brak zajęć w wybranym okresie.
  </div>
  <?php else: ?>
  <!-- Legenda -->
  <div class="card-body py-2 border-bottom small text-body-secondary d-flex gap-3 flex-wrap">
    <span><span class="badge bg-success">100%</span> Obecność = (Obecny / Sesje) × 100</span>
    <span><i class="bi bi-clock text-danger me-1"></i>Nieobecny = bez usprawiedliwienia</span>
    <span><i class="bi bi-arrow-up text-success me-1"></i>Δ = zmiana vs poprzedni okres</span>
  </div>
  <div class="table-responsive">
    <table class="table align-middle mb-0 table-hover" style="font-size:.84rem">
      <caption class="visually-hidden">Frekwencja kursantów — tabela przestawna</caption>
      <thead class="table-light">
        <tr>
          <th style="min-width:140px">Kursant</th>
          <th style="min-width:120px">Kurs/Grupa</th>
          <?php foreach ($freq_periods as $pk): ?>
          <th class="text-center" style="min-width:90px"><?= h($pk) ?></th>
          <?php endforeach; ?>
          <th class="text-center bg-light">Razem h</th>
          <th class="text-center bg-light">Avg %</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $pivot_totals = []; // period → [sesje, godziny, obecny_sum, total_sum]
        foreach ($freq_periods as $pk) $pivot_totals[$pk] = ['h'=>0,'obecny'=>0,'sesje'=>0];
        $gt_h = 0; $gt_obecny = 0; $gt_sesje = 0;

        foreach ($freq_pivot as $row):
            $prev_pct = null;
            $row_h = 0; $row_obecny = 0; $row_sesje = 0;
        ?>
        <tr>
          <td class="fw-semibold"><?= h($row['klient']) ?></td>
          <td class="text-body-secondary small"><?= h($row['kurs']) ?></td>
          <?php foreach ($freq_periods as $pk):
            $pd = $row['periods'][$pk] ?? null;
            $pct = ($pd && $pd['sesje'] > 0) ? round($pd['obecny'] / $pd['sesje'] * 100) : null;
            if ($pd) {
                $row_h      += $pd['godziny'];
                $row_obecny += $pd['obecny'];
                $row_sesje  += $pd['sesje'];
                $pivot_totals[$pk]['h']      += $pd['godziny'];
                $pivot_totals[$pk]['obecny'] += $pd['obecny'];
                $pivot_totals[$pk]['sesje']  += $pd['sesje'];
            }
            // Delta vs poprzedni okres
            $delta = null;
            if ($pct !== null && $prev_pct !== null) $delta = $pct - $prev_pct;
            $pct_col = $pct === null ? 'secondary' : ($pct >= 80 ? 'success' : ($pct >= 60 ? 'warning' : 'danger'));
            if ($pct !== null) $prev_pct = $pct;
          ?>
          <td class="text-center">
            <?php if ($pd): ?>
            <span class="badge bg-<?= $pct_col ?>-subtle text-<?= $pct_col ?>-emphasis px-2 py-1">
              <?= $pct ?>%
            </span>
            <div class="text-body-secondary mt-1" style="font-size:.72rem">
              <?= $pd['obecny'] ?>/<?= $pd['sesje'] ?> · <?= number_format($pd['godziny'],1,',','') ?>h
            </div>
            <?php if ($delta !== null): ?>
            <div class="<?= $delta > 0 ? 'text-success' : ($delta < 0 ? 'text-danger' : 'text-body-secondary') ?>" style="font-size:.7rem">
              <?= $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '=') ?> <?= abs($delta) ?>pp
            </div>
            <?php endif; ?>
            <?php else: ?>
            <span class="text-body-secondary">—</span>
            <?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td class="text-center bg-light fw-semibold"><?= number_format($row_h, 1, ',', '') ?>h</td>
          <td class="text-center bg-light">
            <?php if ($row_sesje > 0):
              $avg_pct = round($row_obecny / $row_sesje * 100);
              $avg_col = $avg_pct >= 80 ? 'success' : ($avg_pct >= 60 ? 'warning' : 'danger');
            ?>
            <span class="badge bg-<?= $avg_col ?>"><?= $avg_pct ?>%</span>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
        <?php
          $gt_h      += $row_h;
          $gt_obecny += $row_obecny;
          $gt_sesje  += $row_sesje;
        endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-bold">
        <tr>
          <td colspan="2">SUMA GRUP</td>
          <?php foreach ($freq_periods as $pk):
            $pt  = $pivot_totals[$pk];
            $pct = $pt['sesje'] > 0 ? round($pt['obecny'] / $pt['sesje'] * 100) : null;
          ?>
          <td class="text-center">
            <?php if ($pct !== null): ?><?= $pct ?>%
            <div class="text-body-secondary fw-normal" style="font-size:.72rem"><?= number_format($pt['h'],1,',','') ?>h</div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <?php endforeach; ?>
          <td class="text-center bg-light"><?= number_format($gt_h, 1, ',', '') ?>h</td>
          <td class="text-center bg-light">
            <?php if ($gt_sesje > 0):
              $gt_pct = round($gt_obecny / $gt_sesje * 100);
              $gc = $gt_pct >= 80 ? 'success' : ($gt_pct >= 60 ? 'warning' : 'danger');
            ?>
            <span class="badge bg-<?= $gc ?>"><?= $gt_pct ?>%</span>
            <?php endif; ?>
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>

<?php
/**
 * _tab_pulpit_kierownik.php — górna część Pulpitu w roli kierownika
 * (dyd_is_staff()). Dołączany z _tab_pulpit.php nad częścią wspólną.
 *
 * „Wymaga uwagi” — lista spraw instytucji z licznikiem i linkiem do ekranu,
 * na którym się je załatwia (bez kafli z dużymi liczbami — tabela jak reszta
 * skórki USOS). Pozycje z zerem są zwinięte do jednej linii „w porządku”.
 * Obok: prowadzący dziś nieobecni i skróty kierownika.
 *
 * Każde zapytanie w osobnym try — brak tabeli modułu (np. świeża instalacja)
 * nie może wywrócić pulpitu; taka pozycja po prostu się nie pokazuje.
 */
require_once dirname(__DIR__, 3) . '/includes/ti_protocols.php';
require_once dirname(__DIR__, 3) . '/includes/ti_leaves.php';

$_kp_try = static function (callable $f, $fallback = null) {
    try { return $f(); } catch (\Throwable $e) { return $fallback; }
};
$_kp_today = date('Y-m-d');

// ── Liczniki — w cache Redis na 2 min, gdy włączony (modules/redis_cache);
//    bez Redisa liczone przy każdym wejściu jak dotąd. Klucz per tenant (prefiks).
$_kp_data = szo_cache_remember('ti:pulpit_kierownik:v1', 120, function () use ($_kp_try, $_kp_today): array {
    // ── Liczniki ──────────────────────────────────────────────────────────────────
    $_kp_rows = [];   // [etykieta, liczba, opis, adres, ikona, wariant]

    $_kp_prot = $_kp_try(fn() => count(ti_protocols_overdue()) + count(ti_protocols_overdue_months()), null);
    if ($_kp_prot !== null) $_kp_rows[] = ['Zaległe protokoły', $_kp_prot,
        'okresy lub miesiące zakończone, protokół niezatwierdzony', 'protokoly.php', 'exclamation-octagon', 'danger'];

    $_kp_debt = $_kp_try(fn() => db_one(
        "SELECT COUNT(*) AS n, COALESCE(SUM(amount + COALESCE(adjustment,0) - COALESCE(paid_amount,0)),0) AS s
           FROM k30_ti_billing
          WHERE status = 'issued' AND due_date IS NOT NULL AND due_date < ?
            AND amount + COALESCE(adjustment,0) - COALESCE(paid_amount,0) > 0.005", [$_kp_today]), null);
    if ($_kp_debt !== null) $_kp_rows[] = ['Rozliczenia po terminie', (int)$_kp_debt['n'],
        (int)$_kp_debt['n'] ? 'do zapłaty łącznie ' . number_format((float)$_kp_debt['s'], 2, ',', ' ') . ' zł' : 'wszystkie opłacone w terminie',
        'billing.php', 'receipt', 'danger'];

    $_kp_defer = $_kp_try(fn() => (int)db_one("SELECT COUNT(*) AS n FROM k30_ti_payment_deferrals WHERE status = 'pending'")['n'], null);
    if ($_kp_defer !== null) $_kp_rows[] = ['Wnioski o odroczenie płatności', $_kp_defer,
        'czekają na decyzję', 'billing.php', 'hourglass-split', 'warning'];

    $_kp_unen = $_kp_try(fn() => (int)db_one("SELECT COUNT(*) AS n FROM k30_ti_unenroll_requests WHERE status = 'pending_admin'")['n'], null);
    if ($_kp_unen !== null) $_kp_rows[] = ['Wypisy z zajęć', $_kp_unen,
        'potwierdzone przez opiekuna, czekają na decyzję', 'klienci.php', 'person-dash', 'warning'];

    $_kp_gaps = $_kp_try(function () {
        require_once dirname(__DIR__, 3) . '/includes/ti_audit.php';
        $d = 0; $g = 0; $who = 0;
        foreach (ti_audit_gaps_by_instructor() as $x) {
            $d += (int)($x['docs_total'] ?? 0); $g += (int)($x['grades_total'] ?? 0);
            if (($x['docs_total'] ?? 0) || ($x['grades_total'] ?? 0)) $who++;
        }
        return ['docs' => $d, 'grades' => $g, 'who' => $who];
    }, null);
    if ($_kp_gaps !== null) $_kp_rows[] = ['Braki w dziennikach', $_kp_gaps['docs'] + $_kp_gaps['grades'],
        $_kp_gaps['who'] ? $_kp_gaps['docs'] . ' lekcji bez dokumentacji, ' . $_kp_gaps['grades'] . ' kursantów bez ocen — ' . $_kp_gaps['who'] . ' prowadz.' : 'dzienniki uzupełnione',
        'audyt_dziennikow.php', 'clipboard2-check', 'warning'];

    $_kp_overdue = $_kp_try(fn() => (int)db_one(
        "SELECT COUNT(*) AS n FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
          WHERE s.status = 'planned' AND s.lesson_date < ? AND c.is_active = 1
            AND c.status NOT IN ('cancelled','archived')", [$_kp_today])['n'], null);
    if ($_kp_overdue !== null) $_kp_rows[] = ['Niezamknięte lekcje z przeszłości', $_kp_overdue,
        'zaplanowane przed dziś, bez obecności ani statusu', 'index.php?tab=grupy', 'calendar-x', 'warning'];

    $_kp_noplan = $_kp_try(fn() => db_all(
        "SELECT c.id, c.name FROM k30_ti_courses c
          WHERE c.is_active = 1 AND c.status NOT IN ('cancelled','archived')
            AND NOT EXISTS (SELECT 1 FROM k30_ti_sessions s WHERE s.course_id = c.id
                             AND s.lesson_date >= ? AND s.status != 'cancelled')
          ORDER BY c.name COLLATE NOCASE", [$_kp_today]), null);
    if ($_kp_noplan !== null) $_kp_rows[] = ['Aktywne grupy bez zaplanowanych zajęć', count($_kp_noplan),
        $_kp_noplan ? implode(', ', array_map(fn($c) => (string)$c['name'], array_slice($_kp_noplan, 0, 3))) . (count($_kp_noplan) > 3 ? ' i ' . (count($_kp_noplan) - 3) . ' innych' : '') : 'każda grupa ma przyszłe terminy',
        'index.php?tab=kursy', 'calendar2-plus', 'secondary'];


    $_kp_leaves = $_kp_try(fn() => ti_leaves_current($_kp_today), []);
    $_kp_counts = $_kp_try(fn() => db_one(
        "SELECT (SELECT COUNT(*) FROM k30_ti_courses WHERE is_active = 1 AND status NOT IN ('cancelled','archived')) AS groups,
                (SELECT COUNT(DISTINCT e.client_id) FROM k30_ti_enrollments e JOIN k30_ti_courses c ON c.id = e.course_id
                  WHERE e.status = 'active' AND c.is_active = 1 AND c.status NOT IN ('cancelled','archived')) AS students,
                (SELECT COUNT(DISTINCT COALESCE(s.instructor_id, c.instructor_id)) FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id = s.course_id
                  WHERE s.lesson_date = ? AND s.status != 'cancelled') AS instr_today", [$_kp_today]), null);
    return ['rows' => $_kp_rows, 'leaves' => $_kp_leaves ?: [], 'counts' => $_kp_counts, 'at' => date('H:i')];
});
$_kp_rows   = $_kp_data['rows'];
$_kp_leaves = $_kp_data['leaves'];
$_kp_counts = $_kp_data['counts'];
$_kp_at     = $_kp_data['at'] ?? date('H:i');
$_kp_attn = array_values(array_filter($_kp_rows, fn($r) => (int)$r[1] > 0));
$_kp_ok   = array_values(array_filter($_kp_rows, fn($r) => (int)$r[1] === 0));
?>
<section class="dyd-kp mb-3" aria-labelledby="dyd-kp-h">
  <div class="d-flex align-items-baseline flex-wrap gap-2 mb-2">
    <h2 class="h6 fw-bold mb-0" id="dyd-kp-h"><i class="bi bi-shield-fill-check me-1" aria-hidden="true"></i>Instytucja — stan na <?= h(date('d.m.Y')) ?>, <?= h($_kp_at) ?></h2>
    <?php if ($_kp_counts): ?>
    <span class="small text-body-secondary">
      <?= (int)$_kp_counts['groups'] ?> aktywnych grup · <?= (int)$_kp_counts['students'] ?> kursantów ·
      dziś prowadzi <?= (int)$_kp_counts['instr_today'] ?> os.
    </span>
    <?php endif; ?>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card mb-0 h-100">
        <div class="card-header d-flex align-items-center gap-2">
          <i class="bi bi-flag text-danger" aria-hidden="true"></i>Wymaga uwagi
          <span class="badge <?= $_kp_attn ? 'text-bg-danger' : 'text-bg-success' ?> ms-auto"><?= count($_kp_attn) ?></span>
        </div>
        <?php if (!$_kp_attn): ?>
        <div class="card-body small"><i class="bi bi-check2-circle text-success me-1" aria-hidden="true"></i>Nic nie czeka na kierownika.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <caption class="visually-hidden">Sprawy instytucji czekające na kierownika</caption>
            <thead><tr><th scope="col">Sprawa</th><th scope="col" class="text-end" style="width:4.5rem">Liczba</th><th scope="col" style="width:7rem"><span class="visually-hidden">Akcja</span></th></tr></thead>
            <tbody>
            <?php foreach ($_kp_attn as [$_l, $_n, $_d, $_h, $_i, $_v]): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><i class="bi bi-<?= h($_i) ?> me-1 text-<?= h($_v) ?>" aria-hidden="true"></i><?= h($_l) ?></div>
                  <div class="small text-body-secondary"><?= h($_d) ?></div>
                </td>
                <td class="text-end"><span class="badge text-bg-<?= h($_v) ?>"><?= (int)$_n ?></span></td>
                <td class="text-end"><a href="<?= h($_h) ?>" class="btn btn-sm btn-outline-primary py-0">Przejdź<span class="visually-hidden">: <?= h($_l) ?></span></a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <?php if ($_kp_ok): ?>
        <div class="card-footer small text-body-secondary">
          <i class="bi bi-check2 text-success me-1" aria-hidden="true"></i>W porządku:
          <?= h(implode(' · ', array_map(fn($r) => $r[0], $_kp_ok))) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card mb-3">
        <div class="card-header"><i class="bi bi-airplane text-secondary me-1" aria-hidden="true"></i>Dziś nieobecni prowadzący</div>
        <?php if (!$_kp_leaves): ?>
        <div class="card-body small text-body-secondary">Wszyscy prowadzący są dziś dostępni.</div>
        <?php else: ?>
        <ul class="list-group list-group-flush small">
          <?php foreach ($_kp_leaves as $_lv): ?>
          <li class="list-group-item d-flex gap-2">
            <span class="fw-semibold"><?= h((string)$_lv['instructor_name']) ?></span>
            <span class="text-body-secondary ms-auto text-nowrap">do <?= h(date('d.m', strtotime((string)$_lv['date_to']))) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <div class="card-footer small"><a href="urlopy.php">Urlopy prowadzących</a></div>
        <?php endif; ?>
      </div>

      <div class="card mb-0">
        <div class="card-header"><i class="bi bi-lightning-charge text-warning me-1" aria-hidden="true"></i>Skróty kierownika</div>
        <div class="card-body d-flex flex-wrap gap-1">
          <?php foreach ([
              ['Przegląd grup', 'index.php?tab=grupy', 'people'], ['Rozliczenia kursantów', 'billing.php', 'receipt'],
              ['Wydruki i raporty', 'wydruki.php', 'printer'], ['Wypłaty', 'index.php?tab=wypłaty', 'cash-stack'],
              ['Zespół i role', 'zespol.php', 'person-gear'], ['Testy', 'testy.php', 'bug'],
          ] as [$_l, $_h, $_i]): ?>
          <a href="<?= h($_h) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-<?= h($_i) ?> me-1" aria-hidden="true"></i><?= h($_l) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

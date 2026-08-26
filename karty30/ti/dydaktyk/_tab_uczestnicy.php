<?php /* ═══════════════════════ TAB: UCZESTNICY — kartoteka grupy (widok USOS) ═══════════════════════ */ ?>
<?php
/**
 * Lista uczestników kursu w układzie rejestru: kontakt, frekwencja, oceny.
 * Odpowiednik listy studentów grupy w USOSweb — jedna gęsta tabela zamiast kart.
 *
 * Zmienne z index.php: $cur_course, $course, $uid.
 */
$u_parts = db_all(
    "SELECT e.client_id, e.status AS enroll_status, e.start_date, e.hourly_rate,
            cl.name, cl.email, cl.phone
       FROM k30_ti_enrollments e
       JOIN k30_clients cl ON cl.id = e.client_id
      WHERE e.course_id = ?
      ORDER BY (e.status != 'active'), cl.name COLLATE NOCASE",
    [$cur_course]
);

// Frekwencja: obecności / zajęcia, na których uczestnik był oczekiwany
// (odwołany udział i lekcje odwołane nie liczą się do podstawy).
$u_att = [];
foreach (db_all(
    "SELECT a.client_id,
            SUM(CASE WHEN a.attended = 1 THEN 1 ELSE 0 END)                    AS present,
            COUNT(*)                                                            AS expected,
            SUM(CASE WHEN COALESCE(a.no_show,0) = 1 THEN 1 ELSE 0 END)          AS no_show
       FROM k30_ti_attendance a
       JOIN k30_ti_sessions   s ON s.id = a.session_id
      WHERE s.course_id = ?
        AND s.status NOT IN ('cancelled','draft')
        AND s.lesson_date <= date('now')
        AND COALESCE(a.cancelled,0) = 0
      GROUP BY a.client_id",
    [$cur_course]
) as $r) {
    $u_att[(int)$r['client_id']] = $r;
}

// Oceny z e-dziennika — liczba i średnia ważona
$u_grades = [];
foreach (k30_ti_course_grades($cur_course) as $g) {
    $u_grades[(int)$g['client_id']][] = $g;
}

$u_active = count(array_filter($u_parts, fn($p) => $p['enroll_status'] === 'active'));
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-people me-2" aria-hidden="true"></i>Uczestnicy — <?= h($course['name']) ?></h1>
  <span class="badge bg-secondary"><?= (int)$u_active ?> aktywnych z <?= count($u_parts) ?></span>
  <div class="ms-auto d-flex gap-2 usos-noprint">
    <a href="index.php?course=<?= (int)$cur_course ?>&tab=protokol" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-card-checklist me-1" aria-hidden="true"></i>Protokoły ocen
    </a>
    <a href="index.php?course=<?= (int)$cur_course ?>&tab=oceny" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-journal-bookmark me-1" aria-hidden="true"></i>E-dziennik
    </a>
  </div>
</div>

<div class="card">
  <div class="card-header">Lista uczestników grupy</div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Uczestnicy kursu <?= h($course['name']) ?> z kontaktem, frekwencją i ocenami</caption>
      <thead><tr>
        <th scope="col" style="width:2.5rem">#</th>
        <th scope="col">Uczestnik</th>
        <th scope="col">Kontakt</th>
        <th scope="col" class="text-nowrap">Zapisany od</th>
        <th scope="col" class="text-nowrap">Frekwencja</th>
        <th scope="col" class="text-nowrap">Oceny</th>
        <th scope="col">Status</th>
      </tr></thead>
      <tbody>
        <?php if (!$u_parts): ?>
        <tr><td colspan="7" class="text-center text-muted py-3">Do tej grupy nikt nie jest zapisany.</td></tr>
        <?php endif; ?>
        <?php foreach ($u_parts as $i => $p):
          $cid  = (int)$p['client_id'];
          $att  = $u_att[$cid] ?? null;
          $exp  = (int)($att['expected'] ?? 0);
          $pres = (int)($att['present'] ?? 0);
          $pct  = $exp > 0 ? (int)round($pres * 100 / $exp) : null;
          $gr   = $u_grades[$cid] ?? [];
          $avg  = $gr ? k30_ti_grades_average($gr) : null;
          $inactive = $p['enroll_status'] !== 'active';
        ?>
        <tr<?= $inactive ? ' class="text-muted"' : '' ?>>
          <td class="small"><?= $i + 1 ?></td>
          <td class="fw-semibold"><?= h($p['name']) ?></td>
          <td class="small">
            <?php if (!empty($p['email'])): ?>
              <a href="mailto:<?= h($p['email']) ?>"><?= h($p['email']) ?></a>
            <?php endif; ?>
            <?php if (!empty($p['phone'])): ?>
              <?= !empty($p['email']) ? '<br>' : '' ?><a href="tel:<?= h(preg_replace('/\s+/', '', (string)$p['phone'])) ?>"><?= h($p['phone']) ?></a>
            <?php endif; ?>
            <?php if (empty($p['email']) && empty($p['phone'])): ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-nowrap small"><?= !empty($p['start_date']) ? h(date('d.m.Y', strtotime((string)$p['start_date']))) : '—' ?></td>
          <td class="text-nowrap small">
            <?php if ($pct === null): ?><span class="text-muted">brak zajęć</span>
            <?php else: ?>
              <span class="badge <?= $pct >= 75 ? 'text-bg-success' : ($pct >= 50 ? 'text-bg-warning' : 'text-bg-danger') ?>"><?= $pct ?>%</span>
              <span class="text-body-secondary"><?= $pres ?>/<?= $exp ?></span>
              <?php if ((int)($att['no_show'] ?? 0) > 0): ?>
                <span class="text-danger" title="Nieusprawiedliwione nieobecności">· nb <?= (int)$att['no_show'] ?></span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
          <td class="text-nowrap small">
            <?php if (!$gr): ?><span class="text-muted">—</span>
            <?php else: ?>
              <?= count($gr) ?> szt.<?= $avg !== null ? ' · śr. <strong>' . number_format($avg, 2, ',', '') . '</strong>' : '' ?>
            <?php endif; ?>
          </td>
          <td class="small">
            <?php if ($inactive): ?><span class="badge text-bg-secondary">wypisany</span>
            <?php else: ?><span class="badge text-bg-success">aktywny</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="small text-body-secondary mt-2">
  Frekwencja liczona z lekcji, które już się odbyły (bez odwołanych i szkiców);
  odwołany udział uczestnika nie wchodzi do podstawy. Średnia to średnia ważona
  z e-dziennika — ocena końcowa jest osobno, w protokole.
</p>

<?php
/**
 * karty30/ti/kursant/_alt_testy.php — wspólna zakładka „Testy” w widoku alternatywnym.
 *
 * Łączy dwa źródła w jedną listę równych kafli:
 *   • egzaminy Equi Exams (k30_ti_exams, rozwiązywanie w exam.php),
 *   • starsze testy (k30_ti_tests, rozwiązywanie w test.php).
 * Kolejność: w toku → do zrobienia → wkrótce → czeka na ocenę → z wynikiem → niedostępne.
 * Stan zawsze słownie w plakietce (nie samym kolorem).
 *
 * Zmienne z index.php: $courses, $student.
 */
$_tt_client = (int)$student['client_id'];
$_tt_rows   = [];

foreach (($courses ?? []) as $_tt_c) {
    $_tt_cid  = (int)$_tt_c['course_id'];
    $_tt_kurs = (string)$_tt_c['course_name'];

    // ── Egzaminy (Equi Exams) ──────────────────────────────────────────────
    foreach (ti_exams_list($_tt_cid, true) as $e) {
        $best   = ti_exam_best_attempt((int)$e['id'], $_tt_client);
        $open   = ti_exam_open_attempt((int)$e['id'], $_tt_client);
        $why    = null;
        $can    = ti_exam_can_start($e, $_tt_client, $why);
        $window = ti_exam_window_status($e);

        if ($open) {
            $st = ['w_toku', 'W toku', 1]; $href = 'exam.php?exam=' . (int)$e['id'] . '&solve=1'; $akcja = 'Kontynuuj';
        } elseif ($best && (int)$best['needs_review'] === 1) {
            $st = ['ocena', 'Czeka na ocenę', 4]; $href = 'exam.php?attempt=' . (int)$best['id']; $akcja = 'Zobacz pracę';
        } elseif ($best) {
            $pct = ti_exam_pct($best); $ok = ti_exam_passed($e, $best);
            $st = [$ok ? 'zal' : 'niezal', ($ok ? 'Zaliczony' : 'Niezaliczony') . ' · ' . $pct . '%', 5];
            $href = $can ? 'exam.php?exam=' . (int)$e['id'] : 'exam.php?attempt=' . (int)$best['id'];
            $akcja = $can ? 'Rozwiąż ponownie' : 'Zobacz wynik';
        } elseif ($can) {
            $st = ['do', 'Do zrobienia', 2]; $href = 'exam.php?exam=' . (int)$e['id']; $akcja = 'Rozwiąż';
        } elseif ($window === 'upcoming') {
            $st = ['wkrotce', 'Wkrótce', 3]; $href = 'exam.php?exam=' . (int)$e['id']; $akcja = 'Szczegóły';
        } else {
            $st = ['nied', 'Niedostępny', 6]; $href = ''; $akcja = (string)$why;
        }

        $termin = '';
        if ($window === 'upcoming' && !empty($e['open_at'])) $termin = 'od ' . date('d.m.Y H:i', strtotime((string)$e['open_at']));
        elseif (!empty($e['close_at']))                       $termin = 'do ' . date('d.m.Y H:i', strtotime((string)$e['close_at']));

        $mode = K30_TI_EXAM_MODES[(string)$e['mode']] ?? K30_TI_EXAM_MODES['exam'];
        $_tt_rows[] = [
            'st' => $st, 'href' => $href, 'akcja' => $akcja, 'tytul' => (string)$e['title'], 'kurs' => $_tt_kurs,
            'meta' => trim((string)$mode['label'] . ' · ' . (int)$e['n_questions'] . ' pytań'
                    . ((int)$e['time_limit_min'] > 0 ? ' · ' . (int)$e['time_limit_min'] . ' min' : '')),
            'termin' => $termin, 'ikona' => (string)$mode['icon'],
        ];
    }

    // ── Starsze testy (k30_ti_tests) ───────────────────────────────────────
    foreach (k30_ti_tests_list($_tt_cid, true) as $t) {
        $nq   = (int)$t['n_questions'];
        $best = k30_ti_test_best_attempt((int)$t['id'], $_tt_client);
        $open = db_one("SELECT id FROM k30_ti_test_attempts WHERE test_id=? AND client_id=? AND status='in_progress' ORDER BY id DESC LIMIT 1",
                       [(int)$t['id'], $_tt_client]);
        $href = $nq > 0 ? 'test.php?test=' . (int)$t['id'] : '';

        if ($nq === 0) {
            $st = ['wkrotce', 'Wkrótce', 3]; $akcja = 'Pytania w przygotowaniu';
        } elseif ($open) {
            $st = ['w_toku', 'W toku', 1]; $akcja = 'Kontynuuj';
        } elseif ($best && $best['status'] === 'submitted' && (int)$best['needs_review'] === 1) {
            $st = ['ocena', 'Czeka na ocenę', 4]; $akcja = 'Rozwiąż ponownie';
        } elseif ($best) {
            $mx = (float)$best['max_score']; $pct = $mx > 0 ? (int)round(100 * (float)$best['score'] / $mx) : 0;
            $ok = (int)$t['pass_pct'] === 0 || $pct >= (int)$t['pass_pct'];
            $st = [$ok ? 'zal' : 'niezal', ($ok ? 'Zaliczony' : 'Niezaliczony') . ' · ' . $pct . '%', 5]; $akcja = 'Rozwiąż ponownie';
        } else {
            $st = ['do', 'Do zrobienia', 2]; $akcja = 'Rozwiąż';
        }

        $_tt_rows[] = [
            'st' => $st, 'href' => $href, 'akcja' => $akcja, 'tytul' => (string)$t['title'], 'kurs' => $_tt_kurs,
            'meta' => 'Test · ' . $nq . ' pytań' . ((int)$t['time_limit_min'] > 0 ? ' · ' . (int)$t['time_limit_min'] . ' min' : ''),
            'termin' => '', 'ikona' => 'card-checklist',
        ];
    }
}

usort($_tt_rows, fn($a, $b) => [$a['st'][2], $a['tytul']] <=> [$b['st'][2], $b['tytul']]);
$_tt_licz = array_count_values(array_map(fn($r) => $r['st'][0], $_tt_rows));
?>
<h1 class="h5 fw-bold mb-1"><i class="bi bi-card-checklist text-primary me-2" aria-hidden="true"></i>Testy</h1>
<p class="text-body-secondary small mb-3">Wszystkie testy, kartkówki i egzaminy z Twoich zajęć w jednym miejscu — najpierw te w toku i do zrobienia.</p>

<?php if (($_GET['err'] ?? '') === 'unavailable'): ?>
<div class="alert alert-warning" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Wybrany test jest niedostępny albo został ukryty.</div>
<?php endif; ?>

<?php if (!$_tt_rows): ?>
<div class="alert alert-secondary" role="status">Nie ma teraz udostępnionych testów.</div>
<?php else: ?>
<p class="mb-3">
  <strong>Twoje testy:</strong>
  w toku <strong><?= (int)($_tt_licz['w_toku'] ?? 0) ?></strong>,
  do zrobienia <strong><?= (int)($_tt_licz['do'] ?? 0) ?></strong>,
  czeka na ocenę <strong><?= (int)($_tt_licz['ocena'] ?? 0) ?></strong>,
  zaliczone <strong><?= (int)($_tt_licz['zal'] ?? 0) ?></strong>.
</p>
<ul class="kp-testy-kafle" aria-label="Testy">
  <?php foreach ($_tt_rows as $r): ?>
  <li class="kp-test-kafel kp-test-<?= h($r['st'][0]) ?>">
    <?php if ($r['href'] !== ''): ?><a href="<?= h($r['href']) ?>"><?php else: ?><div class="kp-test-wew"><?php endif; ?>
      <span class="kp-test-status"><span class="visually-hidden">Stan: </span><?= h($r['st'][1]) ?></span>
      <span class="kp-test-tytul"><i class="bi bi-<?= h($r['ikona']) ?> me-1" aria-hidden="true"></i><?= h($r['tytul']) ?></span>
      <span class="kp-test-meta">
        <span class="d-block"><?= h($r['kurs']) ?></span>
        <span class="d-block"><?= h($r['meta']) ?><?= $r['termin'] !== '' ? ' · ' . h($r['termin']) : '' ?></span>
      </span>
      <span class="kp-test-akcja"><?= h($r['akcja']) ?><?php if ($r['href'] !== ''): ?> <i class="bi bi-arrow-right" aria-hidden="true"></i><?php endif; ?></span>
    <?php if ($r['href'] !== ''): ?></a><?php else: ?></div><?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
<?php endif; ?>
<?= function_exists('equi_exams_footer_html') ? equi_exams_footer_html() : '' ?>

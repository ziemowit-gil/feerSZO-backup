<?php
/**
 * includes/ti_zoom_calendar.php — kalendarz zajętości konta Zoom.
 *
 * Wspólne źródło danych i widok dla administracji (karty30/ti/zoom_calendar.php)
 * oraz panelu dydaktyka (zakładka „Zajętość Zoom”). Zajętość liczona tak samo,
 * jak w bramce ustawiania zajęć (ti_zoom_slot_check) — dwa źródła:
 *   • lekcje SZO korzystające z Zooma (stałe linki kursów to spotkania typu 3,
 *     bez godzin w Zoomie, więc API ich nie zna),
 *   • spotkania z ustalonym terminem na koncie hosta z API Zoom (także spoza SZO).
 *
 * Prowadzący widzi nazwy tylko własnych kursów — pozostała zajętość jest
 * pokazywana bez szczegółów (to informacja o wolnym/zajętym terminie).
 */

require_once __DIR__ . '/karty30.php';   // ti_lesson_uses_zoom(), ti_zoom_api_busy()
require_once __DIR__ . '/zoom.php';      // zoom_enabled()

/**
 * Zajętość konta hosta w zakresie dat, pogrupowana po dniach.
 *
 * @return array{ok:bool, error:string, days:array<string, array<int, array{start:string,end:string,title:string,src:string,course_id:int}>>}
 *   ok=false → API Zoom nie odpowiedziało (dane zawierają wtedy tylko lekcje SZO).
 */
function ti_zoom_busy_range(string $date_from, string $date_to): array {
    $days = [];
    if (!zoom_enabled()) {
        return ['ok' => false, 'error' => 'Integracja Zoom nie jest skonfigurowana.', 'days' => $days];
    }

    // 1) Lekcje SZO korzystające z Zooma
    try {
        $rows = db_all(
            "SELECT s.lesson_date, s.time_from, s.time_to, s.lesson_method, s.course_id,
                    c.name AS course_name, c.zoom_meeting_id
               FROM k30_ti_sessions s
               JOIN k30_ti_courses  c ON c.id = s.course_id
              WHERE s.lesson_date BETWEEN ? AND ?
                AND s.status NOT IN ('cancelled','draft')
                AND s.time_from != '' AND s.time_to != ''
              ORDER BY s.lesson_date, s.time_from",
            [$date_from, $date_to]
        );
    } catch (\Throwable $e) { $rows = []; }

    foreach ($rows as $r) {
        if (!ti_lesson_uses_zoom((int)$r['course_id'], (string)($r['lesson_method'] ?? ''), (string)($r['zoom_meeting_id'] ?? ''))) continue;
        $d = (string)$r['lesson_date'];
        $days[$d][] = [
            'start'     => $d . ' ' . (string)$r['time_from'],
            'end'       => $d . ' ' . (string)$r['time_to'],
            'title'     => (string)$r['course_name'],
            'src'       => 'szo',
            'course_id' => (int)$r['course_id'],
        ];
    }

    // 2) Spotkania z terminem na koncie hosta (API Zoom)
    $api = ti_zoom_api_busy();
    foreach ($api['slots'] as $s) {
        $d = substr((string)$s['start'], 0, 10);
        if ($d < $date_from || $d > $date_to) continue;
        $days[$d][] = [
            'start'     => (string)$s['start'],
            'end'       => (string)$s['end'],
            'title'     => (string)$s['topic'],
            'src'       => 'zoom',
            'course_id' => 0,
        ];
    }

    foreach ($days as $d => $slots) {
        usort($slots, fn($a, $b) => strcmp($a['start'], $b['start']));
        $days[$d] = $slots;
    }
    ksort($days);

    return ['ok' => (bool)$api['ok'], 'error' => (string)$api['error'], 'days' => $days];
}

/** Etykieta slotu z uwzględnieniem widoczności: obce kursy bez nazwy. */
function ti_zoom_slot_label(array $slot, ?array $visible_course_ids = null): string {
    if ($slot['src'] === 'szo') {
        $own = $visible_course_ids === null || in_array((int)$slot['course_id'], $visible_course_ids, true);
        return $own ? 'Lekcja: ' . $slot['title'] : 'Inne zajęcia zdalne';
    }
    return $visible_course_ids === null ? $slot['title'] : 'Spotkanie Zoom';
}

/** Godziny slotu, np. „10:00–11:30”. */
function ti_zoom_slot_hours(array $slot): string {
    $f = strtotime((string)$slot['start']);
    $t = strtotime((string)$slot['end']);
    return ($f ? date('H:i', $f) : '?') . '–' . ($t ? date('H:i', $t) : '?');
}

/**
 * Siatka miesiąca z zajętością.
 *
 * @param string     $month   'Y-m'
 * @param array      $days    z ti_zoom_busy_range()['days']
 * @param array|null $visible_course_ids  null = pokaż wszystkie nazwy (administracja)
 */
function ti_zoom_calendar_month_html(string $month, array $days, ?array $visible_course_ids = null): string {
    $first = strtotime($month . '-01');
    if (!$first) $first = strtotime(date('Y-m-01'));
    $ym       = date('Y-m', $first);
    $last_day = (int)date('t', $first);
    // Poniedziałek jako pierwszy dzień tygodnia
    $lead     = ((int)date('N', $first)) - 1;
    $cells    = $lead + $last_day;
    $rows     = (int)ceil($cells / 7);
    $today    = date('Y-m-d');
    $h        = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $out  = '<div class="table-responsive"><table class="table table-bordered align-top mb-0 ti-zoom-cal">';
    $out .= '<caption class="visually-hidden">Zajętość konta Zoom w miesiącu '
          . $h(date('m.Y', $first)) . ' — dni z terminami zajętymi i wolnymi</caption>';
    $out .= '<thead class="table-light"><tr>';
    foreach (['Pn','Wt','Śr','Cz','Pt','Sb','Nd'] as $dn) {
        $out .= '<th scope="col" class="text-center small">' . $h($dn) . '</th>';
    }
    $out .= '</tr></thead><tbody>';

    $day = 1;
    for ($r = 0; $r < $rows; $r++) {
        $out .= '<tr>';
        for ($c = 0; $c < 7; $c++) {
            $idx = $r * 7 + $c;
            if ($idx < $lead || $day > $last_day) {
                $out .= '<td class="bg-body-secondary" style="min-width:7rem"></td>';
                continue;
            }
            $date  = sprintf('%s-%02d', $ym, $day);
            $slots = $days[$date] ?? [];
            $is_today = $date === $today;

            $out .= '<td style="min-width:7rem;vertical-align:top"' . ($is_today ? ' class="table-warning"' : '') . '>';
            $out .= '<div class="d-flex align-items-center gap-1 mb-1">';
            $out .= '<span class="fw-semibold small">' . $day . '</span>';
            if ($slots) {
                $out .= '<span class="badge text-bg-danger ms-auto" style="font-size:.6rem">'
                      . count($slots) . '</span>';
            }
            $out .= '</div>';

            if (!$slots) {
                $out .= '<div class="small text-body-secondary">wolne</div>';
            } else {
                foreach (array_slice($slots, 0, 3) as $s) {
                    $label = ti_zoom_slot_label($s, $visible_course_ids);
                    $out .= '<div class="small text-truncate" title="' . $h(ti_zoom_slot_hours($s) . ' ' . $label) . '">'
                          . '<span class="badge ' . ($s['src'] === 'szo' ? 'text-bg-primary' : 'text-bg-dark')
                          . '" style="font-size:.6rem">' . $h(ti_zoom_slot_hours($s)) . '</span> '
                          . $h($label) . '</div>';
                }
                if (count($slots) > 3) {
                    $out .= '<div class="small text-body-secondary">+' . (count($slots) - 3) . ' więcej</div>';
                }
            }
            $out .= '</td>';
            $day++;
        }
        $out .= '</tr>';
    }
    $out .= '</tbody></table></div>';
    return $out;
}

/** Legenda kalendarza. */
function ti_zoom_calendar_legend_html(): string {
    return '<div class="d-flex gap-3 flex-wrap small text-body-secondary mt-2">'
        . '<span><span class="badge text-bg-primary">10:00–11:30</span> lekcja zaplanowana w SZO</span>'
        . '<span><span class="badge text-bg-dark">10:00–11:30</span> spotkanie z terminem w Zoomie (także spoza SZO)</span>'
        . '<span><span class="badge text-bg-warning">dzień</span> dzisiaj</span>'
        . '</div>';
}

/**
 * Wyjaśnienie „od czego to zależy” — dlaczego termin bywa zajęty i co z tym zrobić.
 * Ten sam tekst dla administracji i dla prowadzącego.
 */
function ti_zoom_explain_html(): string {
    return <<<HTML
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body">
    <h2 class="h6 fw-bold mb-2"><i class="bi bi-question-circle me-1" aria-hidden="true"></i>Od czego zależy zajętość Zoom?</h2>
    <p class="small mb-2">
      Wszystkie spotkania Zoom powstają na <strong>jednym koncie hosta</strong> fundacji,
      a jeden host nie prowadzi dwóch spotkań w tym samym czasie. Dlatego dwie
      nakładające się lekcje zdalne są technicznie niewykonalne — system nie pozwoli
      ustawić drugiej z nich.
    </p>
    <ul class="small mb-2">
      <li><strong>Lekcja liczy się jako zdalna</strong>, gdy ma metodę „Zdalna — Zoom”,
          albo gdy metody nie wybrano, a kurs ma stały link Zoom (lekcja dziedziczy ten link).</li>
      <li><strong>Zajętość z SZO</strong> to lekcje innych grup i kursantów o nakładających się godzinach
          (odwołane i szkice się nie liczą).</li>
      <li><strong>Zajętość z Zooma</strong> to spotkania z ustalonym terminem na koncie hosta —
          również te utworzone poza systemem.</li>
      <li>Stałe linki kursów są spotkaniami cyklicznymi <em>bez</em> terminu, więc same z siebie
          nie blokują niczego — blokują dopiero zaplanowane w SZO lekcje.</li>
    </ul>
    <p class="small mb-0">
      <strong>Co zrobić przy kolizji:</strong> wybierz inne godziny (kalendarz obok pokazuje wolne dni),
      przenieś lekcję na inny dzień albo ustaw metodę „Stacjonarna” lub „Zdalna — inne”
      (wtedy konto Zoom nie jest angażowane).
    </p>
  </div>
</div>
HTML;
}

<?php
/**
 * modules/ti_dyd_welcome/logic/dydWelcome.php — baner powitalny Pulpitu panelu dydaktyka.
 *
 * Grafika SVG (inline, bez plików) zmienia się z porą dnia: rano wschód słońca nad wzgórzami, w dzień słońce
 * i chmury, wieczorem zachód, w nocy księżyc i gwiazdy — w barwach skórki (granat #10335c, złoto #ffd863).
 * Grafika jest dekoracyjna (aria-hidden); tekst powitania stoi obok na jasnym tle (kontrast WCAG AA).
 * Delikatna animacja (dryf chmur, migotanie gwiazd) wyłącza się przy prefers-reduced-motion.
 *
 * Wołane z karty30/ti/dydaktyk/_tab_pulpit.php.
 */

/** Pora dnia dla godziny 0–23: morning | day | evening | night. */
function dydWelcomePart(int $hour): string
{
    return match (true) {
        $hour >= 5 && $hour < 10  => 'morning',
        $hour >= 10 && $hour < 17 => 'day',
        $hour >= 17 && $hour < 21 => 'evening',
        default                   => 'night',
    };
}

/** Powitanie po polsku — „Dobry wieczór” od 18:00. */
function dydWelcomeGreeting(int $hour): string
{
    return ($hour >= 18 || $hour < 5) ? 'Dobry wieczór' : 'Dzień dobry';
}

/** Ilustracja SVG dla pory dnia. */
function dydWelcomeSvg(string $part): string
{
    $sky = [
        'morning' => ['#ffe7b3', '#fff6e0'],
        'day'     => ['#bfe0ff', '#eaf5ff'],
        'evening' => ['#2b4c7e', '#ffb36b'],
        'night'   => ['#0a1f3b', '#1d3d6b'],
    ][$part];
    $hills = [
        'morning' => ['#9fb7d3', '#5f81a8', '#10335c'],
        'day'     => ['#a9c9a4', '#6f9f7a', '#10335c'],
        'evening' => ['#5a6f97', '#34507c', '#10335c'],
        'night'   => ['#1f3d66', '#163156', '#0b2442'],
    ][$part];
    $id = 'dw' . $part;
    $o  = '<svg class="dw-svg" viewBox="0 0 320 120" preserveAspectRatio="xMidYMid slice" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">'
        . '<defs><linearGradient id="' . $id . 's" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $sky[0] . '"/><stop offset="1" stop-color="' . $sky[1] . '"/></linearGradient>'
        . '<radialGradient id="' . $id . 'g"><stop offset="0" stop-color="#ffd863" stop-opacity=".55"/><stop offset="1" stop-color="#ffd863" stop-opacity="0"/></radialGradient></defs>'
        . '<rect width="320" height="120" fill="url(#' . $id . 's)"/>';
    if ($part === 'night') {
        foreach ([[30, 18, 1.2], [70, 34, .9], [112, 14, 1.4], [150, 40, .8], [196, 22, 1.1], [238, 12, .9], [282, 30, 1.3], [300, 52, .8], [52, 58, .8], [128, 60, .7]] as $i => [$x, $y, $r]) {
            $o .= '<circle class="dw-star" style="animation-delay:' . ($i * .37) . 's" cx="' . $x . '" cy="' . $y . '" r="' . $r . '" fill="#fff"/>';
        }
        $o .= '<circle cx="250" cy="36" r="16" fill="#fff4cf"/><circle cx="257" cy="31" r="14" fill="' . $sky[0] . '"/>';
    } elseif ($part === 'evening') {
        $o .= '<circle cx="230" cy="86" r="40" fill="url(#' . $id . 'g)"/><circle cx="230" cy="86" r="18" fill="#ffd863"/>';
    } elseif ($part === 'morning') {
        $o .= '<circle cx="80" cy="84" r="46" fill="url(#' . $id . 'g)"/><circle cx="80" cy="84" r="20" fill="#ffd863"/>';
    } else {
        $o .= '<circle cx="262" cy="30" r="40" fill="url(#' . $id . 'g)"/><circle cx="262" cy="30" r="15" fill="#ffd863"/>'
            . '<g class="dw-cloud" fill="#fff" opacity=".95"><ellipse cx="70" cy="30" rx="22" ry="8"/><ellipse cx="84" cy="25" rx="14" ry="9"/><ellipse cx="58" cy="27" rx="10" ry="7"/></g>'
            . '<g class="dw-cloud dw-cloud2" fill="#fff" opacity=".85"><ellipse cx="170" cy="46" rx="18" ry="6"/><ellipse cx="181" cy="42" rx="11" ry="7"/></g>';
    }
    $o .= '<path d="M0 92 C 50 70, 90 74, 140 88 S 230 70, 320 84 V120 H0Z" fill="' . $hills[0] . '"/>'
        . '<path d="M0 104 C 60 88, 120 96, 170 104 S 260 92, 320 100 V120 H0Z" fill="' . $hills[1] . '"/>'
        . '<path d="M0 114 C 80 106, 160 112, 220 110 S 290 106, 320 112 V120 H0Z" fill="' . $hills[2] . '"/>'
        . '</svg>';
    return $o;
}

/**
 * Baner powitalny: grafika + „Dzień dobry, Imię” + data + krótkie fakty.
 * @param array $facts lista [ikona bootstrap-icons, tekst, wariant: ''|'warn']
 */
function dydWelcomeHero(string $fullName, string $dateText, array $facts, ?int $hour = null): string
{
    $hour  = $hour ?? (int)date('G');
    $part  = dydWelcomePart($hour);
    $first = trim(explode(' ', trim($fullName))[0] ?? '');
    $greet = dydWelcomeGreeting($hour) . ($first !== '' ? ', ' . $first : '');
    ob_start(); ?>
<section class="dw-hero dw-<?= $part ?>" aria-labelledby="dw-h">
  <div class="dw-art"><?= dydWelcomeSvg($part) ?></div>
  <div class="dw-text">
    <p class="dw-date"><?= h($dateText) ?></p>
    <h1 class="dw-h" id="dw-h"><?= h($greet) ?></h1>
    <?php if ($facts): ?>
    <ul class="dw-facts">
      <?php foreach ($facts as [$ico, $txt, $v]): ?>
      <li class="<?= $v === 'warn' ? 'dw-warn' : '' ?>"><i class="bi bi-<?= h($ico) ?>" aria-hidden="true"></i><?= h($txt) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</section>
<style>
.dw-hero { display: flex; align-items: stretch; background: #fff; border: 1px solid #dde6f0; border-left: 4px solid #ffd863; margin-bottom: 1rem; overflow: hidden; }
.dw-art { flex: 0 0 min(42%, 26rem); min-height: 7.5rem; }
.dw-svg { display: block; width: 100%; height: 100%; }
.dw-text { flex: 1 1 auto; padding: .9rem 1.25rem; display: flex; flex-direction: column; justify-content: center; min-width: 0; }
.dw-date { margin: 0 0 .1rem; font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: #4a5566; font-weight: 600; }
.dw-h { margin: 0 0 .45rem; font-size: 1.45rem; font-weight: 700; color: #10335c; }
.dw-facts { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .35rem 1.1rem; font-size: .88rem; color: #1d2733; }
.dw-facts i { margin-right: .35rem; color: #10335c; }
.dw-facts .dw-warn { color: #8a3b00; font-weight: 600; }
.dw-facts .dw-warn i { color: #b45309; }
@keyframes dw-drift { from { transform: translateX(0); } to { transform: translateX(14px); } }
@keyframes dw-twinkle { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }
.dw-cloud { animation: dw-drift 9s ease-in-out infinite alternate; }
.dw-cloud2 { animation-duration: 13s; }
.dw-star { animation: dw-twinkle 3.2s ease-in-out infinite; }
@media (prefers-reduced-motion: reduce) { .dw-cloud, .dw-star { animation: none; } }
@media (max-width: 575.98px) {
  .dw-hero { flex-direction: column; }
  .dw-art { flex-basis: auto; height: 5.5rem; min-height: 0; }
  .dw-h { font-size: 1.2rem; }
}
</style>
<?php
    return (string)ob_get_clean();
}

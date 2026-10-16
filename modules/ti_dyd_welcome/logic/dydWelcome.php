<?php
/**
 * modules/ti_dyd_welcome/logic/dydWelcome.php — baner powitalny Pulpitu panelu dydaktyka.
 *
 * Ikona pory dnia (SVG inline, kafel 64 px) obok powitania: rano wschód, w dzień słońce, wieczorem zachód, w nocy
 * księżyc z gwiazdami — w barwach skórki (granat #10335c, złoto #ffd863). Ikona jest dekoracyjna (aria-hidden);
 * migotanie gwiazd wyłącza się przy prefers-reduced-motion.
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

/** Ikona pory dnia (SVG 48×48, kreska jak w Bootstrap Icons): wschód, słońce, zachód, księżyc z gwiazdami. */
function dydWelcomeSvg(string $part): string
{
    $o = '<svg class="dw-svg" viewBox="0 0 48 48" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">';
    $rays = fn(int $cx, int $cy, int $r1, int $r2, array $deg) => implode('', array_map(function ($d) use ($cx, $cy, $r1, $r2) {
        $a = deg2rad($d);
        return sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>', $cx + $r1 * cos($a), $cy - $r1 * sin($a), $cx + $r2 * cos($a), $cy - $r2 * sin($a));
    }, $deg));
    if ($part === 'day') {
        $o .= '<circle cx="24" cy="24" r="8" fill="#ffd863"/>' . $rays(24, 24, 12, 17, [0, 45, 90, 135, 180, 225, 270, 315]);
    } elseif ($part === 'morning' || $part === 'evening') {
        // półsłońce nad horyzontem; strzałka w górę (wschód) albo w dół (zachód)
        $o .= '<path d="M14 32 a10 10 0 0 1 20 0" fill="#ffd863"/>' . $rays(24, 32, 14, 19, [0, 40, 90, 140, 180])
            . '<line x1="6" y1="32" x2="42" y2="32"/><line x1="12" y1="39" x2="36" y2="39" stroke-width="2"/>'
            . ($part === 'morning' ? '<polyline points="20,9 24,5 28,9" stroke-width="2"/>' : '<polyline points="20,5 24,9 28,5" stroke-width="2"/>');
    } else {
        $o .= '<path d="M30 9a14 14 0 1 0 9 22A11 11 0 0 1 30 9z" fill="#ffd863" stroke="#ffd863"/>'
            . '<path class="dw-star" d="M13 10v4M11 12h4" stroke-width="1.8"/><path class="dw-star dw-star2" d="M8 22v3M6.5 23.5h3" stroke-width="1.6"/>';
    }
    return $o . '</svg>';
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
.dw-hero { display: flex; align-items: center; gap: 1rem; background: #fff; border: 1px solid #dde6f0; border-left: 4px solid #ffd863; margin-bottom: 1rem; padding: .75rem 1rem; }
.dw-art { flex: 0 0 auto; width: 4rem; height: 4rem; border-radius: 12px; display: grid; place-items: center; }
.dw-svg { width: 2.6rem; height: 2.6rem; display: block; }
.dw-morning .dw-art { background: #fff3d6; color: #b45309; }
.dw-day .dw-art     { background: #e6f1fc; color: #0b4a8f; }
.dw-evening .dw-art { background: #ffe6d5; color: #b4410f; }
.dw-night .dw-art   { background: #10335c; color: #ffd863; }
.dw-text { flex: 1 1 auto; min-width: 0; }
.dw-date { margin: 0 0 .05rem; font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: #4a5566; font-weight: 600; }
.dw-h { margin: 0 0 .35rem; font-size: 1.35rem; font-weight: 700; color: #10335c; }
.dw-facts { list-style: none; margin: 0; padding: 0; display: flex; flex-wrap: wrap; gap: .3rem 1.1rem; font-size: .88rem; color: #1d2733; }
.dw-facts i { margin-right: .35rem; color: #10335c; }
.dw-facts .dw-warn { color: #8a3b00; font-weight: 600; }
.dw-facts .dw-warn i { color: #b45309; }
@keyframes dw-twinkle { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }
.dw-star { animation: dw-twinkle 3.2s ease-in-out infinite; }
.dw-star2 { animation-delay: 1.4s; }
@media (prefers-reduced-motion: reduce) { .dw-star { animation: none; } }
@media (max-width: 575.98px) { .dw-art { width: 3.2rem; height: 3.2rem; } .dw-svg { width: 2.1rem; height: 2.1rem; } .dw-h { font-size: 1.15rem; } }
</style>
<?php
    return (string)ob_get_clean();
}

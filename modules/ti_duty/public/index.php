<?php
/**
 * modules/ti_duty/public/index.php — publiczna strona „Dyżury kierownika / Zarządu” (bez logowania).
 *
 * Wygląd jak plan zajęć TI (styl Optivum: lista z lewej, niebieski pasek, tabela tygodnia) — te same dane
 * co zakładka DK w planie (k30_ti_duty), publikacja: org_setting ti_duty_public (SZO → Dyżury).
 * Wejście: karty30/ti/dydaktyk/dyzury.php; krótko https://<ti|szo|kursant>.…/dyzury (.htaccess).  &t=<data> — tydzień.
 * Dyżurni inicjałami + funkcja; pełne dane w widoku pełnym (ciasteczko planu TIPLAN_FULL albo sesja panelu dydaktyka).
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/modules/ti_plan_public/logic/planPublicPortal.php';
require_once dirname(__DIR__, 3) . '/modules/ti_plan_public/ui/portalLayout.php';
require_once dirname(__DIR__, 2) . '/ti_duty/logic/dutyManager.php';
require_once dirname(__DIR__, 2) . '/ti_duty/ui/dutyView.php';

header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-cache, private');
tiDutyMigrate();
tiPlanPublicMigrate();

// Widok pełny (zalogowanie z planu TI — to samo podpisane ciasteczko, 90 dni)
$fullHash = tiPlanPublicFullHash();
if ($fullHash !== '' && preg_match('/^(\d{9,11})\.([a-f0-9]{64})$/', (string)($_COOKIE['TIPLAN_FULL'] ?? ''), $fm)
    && (int)$fm[1] > time() && hash_equals(hash_hmac('sha256', 'tiplan-full|' . (int)$fm[1], $fullHash), $fm[2])) {
    tiPlanPublicFull(true);
    header('Cache-Control: no-store, private');
}
// …albo sesja panelu dydaktyka (prowadzący / kierownik) — sprawdzana tylko, gdy jest jej ciasteczko
if (!tiPlanPublicFull() && PHP_SAPI !== 'cli' && (isset($_COOKIE['k30_dydaktyk']) || isset($_COOKIE['k30_ti_remember_dyd']))) {
    require_once dirname(__DIR__, 3) . '/karty30/ti/dydaktyk/auth.php';
    if (dyd_current() !== null) { tiPlanPublicFull(true); header('Cache-Control: no-store, private'); }
}

$self   = (string)($_SERVER['SCRIPT_NAME'] ?? '/modules/ti_duty/public/index.php');
$planSelf = '/karty30/ti/dydaktyk/planPubliczny.php';
$org    = ti_org_contact_info();
$base   = ['self' => $planSelf, 'org' => $org, 'full' => tiPlanPublicFull(), 'canLogin' => $fullHash !== '',
           // lista z lewej: tylko ta strona + przejście do planu zajęć
           'side' => ['dk' => [['Dyżur kierownika / Zarządu', tiPlanPortalUrl($self)], ['Plan zajęć TI', tiPlanPortalUrl($planSelf)]]]];

if (!tiDutyPublic() && !tiPlanPublicFull()) {
    http_response_code(404);
    tiPlanPortalOpen($base + ['title' => 'Dyżury', 'heading' => 'Dyżury kierownika / Zarządu']);
    echo '<p class="empty">Grafik dyżurów nie jest obecnie publikowany.</p>';
    tiPlanPortalClose($base);
    exit;
}

tiPlanPortalOpen($base + ['title' => 'Dyżury kierownika i Zarządu', 'heading' => 'Dyżur kierownika / Zarządu',
    'sub' => h('kiedy i gdzie można zastać kierownika lub członka Zarządu' . (trim((string)($org['name'] ?? '')) !== '' ? ' — ' . $org['name'] : '')),
    'current' => tiPlanPortalUrl($self)]);
tiDutyNowBox($self, []);
tiDutyRenderWeek($self, []);

// Najbliższe dyżury — lista na 4 tygodnie (czytelna także na telefonie i w czytniku ekranu)
$occ = tiDutyOccurrences(date('Y-m-d'), date('Y-m-d', strtotime('+27 days')));
if ($occ): ?>
  <h2 class="tiles-head">Najbliższe dyżury</h2>
  <div class="terms">
  <?php foreach ($occ as $d => $o): ?>
    <h3><?= h(TI_DAYS_PL_FULL[(int)date('N', strtotime($d))]) ?>, <?= h(date('d.m.Y', strtotime($d))) ?><?= $d === date('Y-m-d') ? ' (dziś)' : '' ?></h3>
    <?php if ($o['cancelled']): ?><div class="row"><div class="g">—</div><div class="lesson"><span class="st st-bad">brak dyżuru</span></div></div><?php endif; ?>
    <?php foreach ($o['items'] as $it): ?><div class="row"><div class="g"><?= h($it['time_from'] . '–' . $it['time_to']) ?></div><?= tiDutyItemHtml($it, !empty($o['special'])) ?></div><?php endforeach; ?>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
<p class="legend">Dyżurni oznaczeni inicjałami i funkcją; kontakt — telefon / e-mail dyżurowy. Zmiany w konkretnych dniach są oznaczone.</p>
<?php
tiPlanPortalClose($base);

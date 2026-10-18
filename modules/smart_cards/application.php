<?php
/**
 * modules/smart_cards/application.php — szczegóły wniosku o kartę (sekcje A–F jak na wydruku),
 * decyzja, wydana karta i ślad w audit_logs. ?photo=1 — zdjęcie z wniosku (obsługa albo
 * sam wnioskujący). Decyzje podejmuje się na liście wniosków (applications.php).
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/smart_cards.php';

require_login();
require_module_enabled('smart_cards_enabled', 'Moduł Karty dostępu');

$me      = current_user();
$isStaff = in_array($me['role'] ?? '', ['admin', 'editor'], true);
$svc     = new SmartCardService($me);
$id      = (int)($_GET['id'] ?? 0);
$app     = $svc->application($id);

// zdjęcie: obsługa albo właściciel wniosku
if (isset($_GET['photo'])) {
    if (!$app || (!$isStaff && (int)$app['user_id'] !== (int)$me['id'])) { http_response_code(404); exit; }
    if (!$isStaff) ika_require();
    $file = SmartCardService::photoFile($app);
    if (!$file) { http_response_code(404); exit; }
    header('Content-Type: ' . (str_ends_with($file, '.png') ? 'image/png' : 'image/jpeg'));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

require_role('admin', 'editor');
if (!$app) { flash_set('danger', 'Wniosek nie istnieje.'); header('Location: ' . APP_URL . '/modules/smart_cards/applications.php'); exit; }

$zoneNames = [];
foreach ($svc->zones() as $z) $zoneNames[(int)$z['id']] = $z;
$zoneLabel = fn(int $zid) => isset($zoneNames[$zid]) ? 'L' . (int)$zoneNames[$zid]['security_level'] . ' · ' . $zoneNames[$zid]['zone_name'] : 'strefa #' . $zid;
$issued  = $svc->issuedZoneIds($app);
$history = $svc->applicationHistory($id, (int)$app['card_id']);
$fmt = fn(?string $d, string $f = 'd.m.Y H:i') => $d ? date($f, strtotime($d)) : '—';
$dash = fn($v) => trim((string)$v) !== '' ? h((string)$v) : '<span style="color:var(--tz-muted)">—</span>';
$pdfUrl = APP_URL . '/modules/smart_cards/application_pdf.php?id=' . $id;

$PAGE_TITLE = 'Wniosek ' . $app['number'];
include dirname(__DIR__, 2) . '/includes/header.php';
include __DIR__ . '/partials/ui.php';
?>
<div class="scm tw-max-w-[1100px]">
<header class="dash-h">
  <div>
    <p class="tw-m-0"><a href="<?= h(APP_URL . '/modules/smart_cards/applications.php') ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wnioski o karty</a></p>
    <h1><span class="dash-h__ico" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span> Wniosek <?= h($app['number']) ?> <?= scard_status_badge($app['status']) ?></h1>
    <p><?= h(SCARD_APP_TYPES[$app['app_type']]['label'] ?? $app['app_type']) ?> · <?= h($app['user_name'] ?: '#' . $app['user_id']) ?> · złożony <?= h($fmt($app['created_at'])) ?></p>
  </div>
  <a class="h-btn h-btn--primary" href="<?= h($pdfUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Wydruk PDF</a>
</header>
<?php scard_tabs('applications', $svc->stats()['pending']); ?>
<?= flash_html() ?>

<div class="tw-grid tw-grid-cols-1 lg:tw-grid-cols-3 tw-gap-5">
  <div class="lg:tw-col-span-2 tw-space-y-5">
    <section class="tz-card" aria-labelledby="h-a">
      <div class="tz-card__hd"><h2 id="h-a"><i class="bi bi-person-vcard" aria-hidden="true"></i> A–B. Rodzaj wniosku i dane osoby</h2></div>
      <div class="tz-card__bd">
        <dl class="ad-dl">
          <dt>Rodzaj wniosku</dt><dd><?= h(SCARD_APP_TYPES[$app['app_type']]['label'] ?? $app['app_type']) ?></dd>
          <?php if ($app['replaced_card_id']): ?><dt>Dotychczasowa karta</dt><dd><a href="<?= h(APP_URL . '/modules/smart_cards/card.php?id=' . (int)$app['replaced_card_id']) ?>" class="mono"><?= h(scard_uid_display((string)$app['replaced_card_uid'])) ?></a> <?= $app['replaced_card_status'] ? scard_status_badge($app['replaced_card_status']) : '' ?></dd><?php endif; ?>
          <dt>Osoba</dt><dd><?= h($app['user_name'] ?: '#' . $app['user_id']) ?><span class="sub"><?= h($app['user_email']) ?></span></dd>
          <dt>Jednostka</dt><dd><?= $dash($app['org_unit']) ?></dd>
          <dt>Stanowisko</dt><dd><?= $dash($app['job_title']) ?></dd>
          <dt>Telefon</dt><dd><?= $dash($app['phone']) ?></dd>
          <dt>E-mail</dt><dd><?= $dash($app['email']) ?></dd>
          <dt>Złożył(a)</dt><dd><?= h($app['created_by_name'] ?: '—') ?><?= (int)$app['created_by'] === (int)$app['user_id'] ? ' <span class="sub tw-inline">(za siebie)</span>' : '' ?></dd>
        </dl>
      </div>
    </section>

    <section class="tz-card" aria-labelledby="h-c">
      <div class="tz-card__hd"><h2 id="h-c"><i class="bi bi-door-open" aria-hidden="true"></i> C–D. Zakres i uzasadnienie</h2></div>
      <div class="tz-card__bd">
        <dl class="ad-dl">
          <dt>Wzór karty</dt><dd><?= h($app['template_name'] ?: 'bez preferencji (domyślny)') ?></dd>
          <dt>Ważność</dt><dd><?= $app['valid_from'] ? 'od ' . h($fmt($app['valid_from'], 'd.m.Y')) . ' ' : '' ?><?= $app['valid_to'] ? 'do ' . h($fmt($app['valid_to'], 'd.m.Y')) : 'standardowa (' . SCARD_DEFAULT_VALIDITY_YEARS . ' lata)' ?></dd>
          <dt>Strefy</dt><dd><?php if ($app['zone_list']): ?><ul class="tw-m-0 tw-pl-4"><?php foreach ($app['zone_list'] as $z): ?><li><?= h($zoneLabel($z)) ?></li><?php endforeach; ?></ul><?php else: ?>według wzoru<?php endif; ?></dd>
          <dt>Uzasadnienie</dt><dd><?= nl2br(h($app['reason'])) ?></dd>
          <dt>Przełożony</dt><dd><?= $dash($app['supervisor_name']) ?></dd>
        </dl>
      </div>
    </section>

    <section class="tz-card" aria-labelledby="h-f">
      <div class="tz-card__hd"><h2 id="h-f"><i class="bi bi-check2-square" aria-hidden="true"></i> F. Oświadczenia</h2></div>
      <div class="tz-card__bd tw-space-y-2 tw-text-sm">
        <?php foreach (['responsibility' => 'consent_responsibility', 'rodo' => 'consent_rodo'] as $k => $col): ?>
        <p class="tw-m-0"><i class="bi <?= (int)$app[$col] ? 'bi-check-square-fill' : 'bi-square' ?>" style="color:<?= (int)$app[$col] ? '#047857' : '#94a3b8' ?>" aria-hidden="true"></i>
          <span class="tw-sr-only"><?= (int)$app[$col] ? 'Złożone:' : 'Niezłożone:' ?></span> <?= h(SCARD_CONSENTS[$k]) ?></p>
        <?php endforeach; ?>
        <p class="sub"><?= $app['consent_at'] ? 'Oświadczenia złożone ' . h($fmt($app['consent_at'])) : 'Wniosek złożony przed wprowadzeniem oświadczeń elektronicznych — wymagany podpis na wydruku.' ?></p>
      </div>
    </section>
  </div>

  <div class="tw-space-y-5">
    <section class="tz-card" aria-labelledby="h-dec">
      <div class="tz-card__hd"><h2 id="h-dec"><i class="bi bi-clipboard-check" aria-hidden="true"></i> G. Decyzja</h2></div>
      <div class="tz-card__bd tw-text-sm tw-space-y-2">
        <p class="tw-m-0"><?= scard_status_badge($app['status']) ?></p>
        <?php if ($app['status'] === 'pending'): ?>
          <p class="tw-m-0">Wniosek czeka na decyzję.</p>
          <a class="h-btn h-btn--ghost h-btn--sm" href="<?= h(APP_URL . '/modules/smart_cards/applications.php?status=pending') ?>">Rozpatrz na liście wniosków</a>
        <?php else: ?>
          <p class="tw-m-0"><strong><?= h($app['decided_by_name'] ?: '—') ?></strong><span class="sub"><?= h($fmt($app['decided_at'])) ?></span></p>
          <?php if ($app['decision_note']): ?><p class="tw-m-0"><strong><?= $app['status'] === 'rejected' ? 'Powód odrzucenia' : 'Notatka' ?>:</strong> <?= nl2br(h($app['decision_note'])) ?></p><?php endif; ?>
          <?php if ($app['card_id']): ?>
          <p class="tw-m-0"><strong>Wydana karta:</strong> <a class="mono" href="<?= h(APP_URL . '/modules/smart_cards/card.php?id=' . (int)$app['card_id']) ?>"><?= h(scard_uid_display((string)$app['card_uid'])) ?></a>
            <?= $app['card_status'] ? scard_status_badge($app['card_status']) : '' ?><span class="sub">ważna do <?= h($fmt($app['card_expires_at'], 'd.m.Y')) ?></span></p>
          <?php if ($issued): ?><p class="tw-m-0"><strong>Strefy:</strong> <?= h(implode(', ', array_map($zoneLabel, $issued))) ?></p><?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </section>

    <?php if ($app['photo_path']): ?>
    <section class="tz-card" aria-labelledby="h-ph">
      <div class="tz-card__hd"><h2 id="h-ph"><i class="bi bi-person-square" aria-hidden="true"></i> E. Zdjęcie</h2></div>
      <div class="tz-card__bd"><img src="<?= h(APP_URL . '/modules/smart_cards/application.php?id=' . $id . '&photo=1') ?>" alt="Zdjęcie posiadacza: <?= h($app['user_name']) ?>" class="tw-block tw-w-40 tw-rounded-lg tw-border" style="border-color:var(--tz-line)"></div>
    </section>
    <?php endif; ?>

    <section class="tz-card" aria-labelledby="h-hist">
      <div class="tz-card__hd"><h2 id="h-hist"><i class="bi bi-clock-history" aria-hidden="true"></i> Historia</h2></div>
      <div class="tz-card__bd">
        <?php if (!$history): ?><p class="tw-m-0 tw-text-sm" style="color:var(--tz-muted)">Brak wpisów w dzienniku.</p><?php else: ?>
        <ol class="tw-list-none tw-m-0 tw-p-0 tw-space-y-2 tw-text-sm">
          <?php foreach ($history as $l): ?>
          <li><strong><?= h(['smart_cards.application_submitted' => 'Złożenie wniosku', 'smart_cards.application_approved' => 'Zatwierdzenie', 'smart_cards.application_rejected' => 'Odrzucenie', 'smart_cards.card_issued' => 'Wydanie karty'][$l['action']] ?? $l['action']) ?></strong>
            <span class="sub"><?= h($fmt($l['created_at'])) ?> · <?= h($l['user_name'] ?: 'system') ?></span></li>
          <?php endforeach; ?>
        </ol>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>
</div>
<style>
.scm .ad-dl { display: grid; grid-template-columns: minmax(130px, 30%) 1fr; gap: .5rem 1rem; margin: 0; font-size: .875rem; }
.scm .ad-dl dt { font-weight: 600; color: var(--tz-muted); }
.scm .ad-dl dd { margin: 0; }
</style>
<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>

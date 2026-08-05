<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
require_login();

$PAGE_TITLE  = 'Moje upoważnienia RODO';
$user        = current_user();
$uid         = (int)$user['id'];

// Znajdź aktywne upoważnienia powiązane z umowami użytkownika
// (szukamy przez kontrakt powiązany z mailem lub M365)
$_db_user    = db_one("SELECT microsoft_id, email FROM users WHERE id=?", [$uid]);
$email       = $_db_user['email'] ?? $user['email'] ?? '';
$ms_id       = $_db_user['microsoft_id'] ?? '';

$authorizations = [];

// Szukaj upoważnień powiązanych z wolontariat umowami użytkownika
try {
    $contract_ids = [];
    if ($email) {
        $rows = db_all(
            "SELECT id FROM umowy_wolontariat WHERE email=? OR m365_login=?",
            [$email, $email]
        );
        foreach ($rows as $r) $contract_ids[] = (int)$r['id'];
    }
    if ($ms_id) {
        $rows = db_all("SELECT id FROM umowy_wolontariat WHERE m365_user_id=?", [$ms_id]);
        foreach ($rows as $r) {
            if (!in_array((int)$r['id'], $contract_ids)) $contract_ids[] = (int)$r['id'];
        }
    }
    if ($contract_ids) {
        $ph = implode(',', array_fill(0, count($contract_ids), '?'));
        $authorizations = db_all(
            "SELECT * FROM rodo_authorizations WHERE contract_type='wolontariat' AND contract_id IN ({$ph}) ORDER BY created_at DESC",
            $contract_ids
        );
    }
} catch (\Throwable $e) {}

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [$uid]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
require_once __DIR__ . '/includes/pv_ui.php';
?>

<div class="pv-wrap">

<?php pv_page_header('Moje upoważnienia RODO', [
    'icon' => 'bi-shield-lock',
    'sub'  => 'Zakres i status Twoich upoważnień do przetwarzania danych osobowych',
    'back' => ['url' => APP_URL . '/panel/', 'label' => 'Panel'],
]); ?>

<div class="pv-note" role="note" aria-label="Informacja o upoważnieniach RODO">
  <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
  <div>
    Upoważnienia uprawniają Cię do przetwarzania danych osobowych w ramach wolontariatu.
    Obowiązuje Cię <strong>poufność</strong> przetwarzanych danych — również po zakończeniu współpracy.
    W razie pytań skontaktuj się ze swoim opiekunem.
  </div>
</div>

<?php if (!$authorizations): ?>

<div class="pv-empty" role="status" aria-label="Brak upoważnień">
  <i class="bi bi-shield-check" aria-hidden="true"></i>
  <div class="pv-empty-title">Brak upoważnień powiązanych z Twoim kontem</div>
  <div class="pv-empty-sub">Jeśli uważasz, że powinno ono istnieć — skontaktuj się z administratorem.</div>
</div>

<?php else: ?>

<?php foreach ($authorizations as $a):
  $scope      = json_decode($a['scope_items'] ?? '[]', true) ?: [];
  $is_active  = $a['status'] === 'aktywne';
  $is_revoked = $a['status'] === 'cofnięte';
  $status_map = [
      'aktywne'  => ['tz-badge--ok',   'Aktywne'],
      'cofnięte' => ['tz-badge--wait', 'Odwołane'],
      'wygasłe'  => ['tz-badge--off',  'Wygasłe'],
  ];
  [$badge_cls, $badge_lbl] = $status_map[$a['status']] ?? ['tz-badge--off', $a['status']];
?>
<article class="tz-card<?= !$is_active ? ' opacity-75' : '' ?>"
         aria-label="Upoważnienie <?= h($a['number']) ?>">

  <div class="tz-card__hd">
    <i class="bi bi-shield-lock" aria-hidden="true"></i>
    <span class="font-monospace fw-bold" style="font-size:.82rem;color:var(--tz-muted)"><?= h($a['number']) ?></span>
    <span class="tz-badge <?= $badge_cls ?>" role="status"><?= h($badge_lbl) ?></span>
    <?php if (!$a['training_done'] && $is_active): ?>
    <span class="tz-badge tz-badge--wait">
      <i class="bi bi-exclamation-circle" aria-hidden="true"></i>Brak szkolenia RODO
    </span>
    <?php endif; ?>
    <a href="<?= h(APP_URL) ?>/rodo/print.php?id=<?= (int)$a['id'] ?>"
       target="_blank" rel="noopener noreferrer"
       class="tz-btn tz-btn--ghost ms-auto"
       style="font-size:.82rem;padding:.4rem .9rem;min-height:36px"
       aria-label="Pobierz lub wydrukuj upoważnienie <?= h($a['number']) ?>">
      <i class="bi bi-printer" aria-hidden="true"></i>Pobierz / wydrukuj
    </a>
  </div>

  <div class="tz-card__bd">

    <dl class="tz-dl" role="list" aria-label="Dane upoważnienia">
      <div role="listitem">
        <dt>Wydane przez</dt>
        <dd><?= h($a['org_name']) ?></dd>
      </div>
      <div role="listitem">
        <dt>Obowiązuje od</dt>
        <dd><?= $a['authorized_from'] ? h(date('d.m.Y', strtotime($a['authorized_from']))) : '—' ?></dd>
      </div>
      <div role="listitem">
        <dt>Obowiązuje do</dt>
        <dd><?= $a['authorized_until'] ? h(date('d.m.Y', strtotime($a['authorized_until']))) : 'do zakończenia porozumienia' ?></dd>
      </div>
    </dl>

    <?php if ($scope || $a['scope_custom']): ?>
    <div class="tz-section-h" id="scope-hd-<?= (int)$a['id'] ?>">Zakres upoważnienia (§&nbsp;2)</div>
    <ul aria-labelledby="scope-hd-<?= (int)$a['id'] ?>"
        style="font-size:.87rem;padding-left:1.3rem;margin-bottom:0;color:var(--tz-ink)">
      <?php foreach ($scope as $k): ?>
      <li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li>
      <?php endforeach; ?>
      <?php if ($a['scope_custom']): ?><li><?= h($a['scope_custom']) ?></li><?php endif; ?>
    </ul>
    <?php endif; ?>

    <?php if ($is_active): ?>
    <div class="pv-note mt-3 mb-0<?= $a['vol_signed_at'] ? '' : ' pv-note-warn' ?>"
         role="status">
      <?php if ($a['vol_signed_at']): ?>
      <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
      <div>Oświadczenie podpisałeś/aś <strong><?= h(date('d.m.Y', strtotime($a['vol_signed_at']))) ?></strong></div>
      <?php else: ?>
      <i class="bi bi-exclamation-circle-fill" aria-hidden="true"></i>
      <div>Oświadczenie RODO oczekuje na Twój podpis — skontaktuj się z administratorem.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($is_revoked): ?>
    <div class="pv-alert pv-alert-err mt-3" role="alert">
      <i class="bi bi-x-circle-fill" aria-hidden="true"></i>
      <div>To upoważnienie zostało odwołane. Nie możesz przetwarzać danych osobowych w ramach tej umowy.</div>
    </div>
    <?php endif; ?>

  </div>
</article>
<?php endforeach; ?>

<?php endif; ?>

</div><!-- /.pv-wrap -->

<?php if ($_is_volunteer_only): ?>
<?php include __DIR__ . '/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
<?php endif; ?>

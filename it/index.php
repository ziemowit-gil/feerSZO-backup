<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/it_helpers.php';
require_once dirname(__DIR__) . '/modules/panel_metro/lib/panelMetro.php';
it_migrate();

require_role('admin', 'editor');
$PAGE_TITLE = 'Dostępy i Infrastruktura IT';

// ── Statystyki ───────────────────────────────────────────────────────────────
$stats = [];
$services = db_all("SELECT * FROM it_services WHERE is_active=1 ORDER BY sort_order");
foreach ($services as $s) {
    $c_active   = (int)(db_one("SELECT COUNT(*) AS c FROM it_accounts WHERE service_id=? AND is_active=1",   [$s['id']])['c'] ?? 0);
    $c_inactive = (int)(db_one("SELECT COUNT(*) AS c FROM it_accounts WHERE service_id=? AND is_active=0", [$s['id']])['c'] ?? 0);
    $stats[$s['slug']] = ['service' => $s, 'active' => $c_active, 'inactive' => $c_inactive];
}

$recent_accounts = db_all(
    "SELECT a.*, s.name AS service_name, s.icon AS service_icon, s.color AS service_color, s.slug AS service_slug,
            u.name AS created_by_name
     FROM it_accounts a
     JOIN it_services s ON s.id = a.service_id
     LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.updated_at DESC LIMIT 12"
);

$recent_passwords = db_all(
    "SELECT p.*, s.name AS service_name, s.icon AS service_icon, s.color AS service_color,
            u.name AS issued_by_name
     FROM it_service_passwords p
     JOIN it_services s ON s.id = p.service_id
     LEFT JOIN users u ON u.id = p.issued_by
     ORDER BY p.issued_at DESC LIMIT 8"
);

$total_passwords = (int)(db_one("SELECT COUNT(*) AS c FROM it_service_passwords")['c'] ?? 0);

// Kolor kafla serwisu: własny kolor serwisu, gdy biały tekst ma na nim kontrast ≥ 4,5:1,
// inaczej kolor z palety Metro (przyciemnionej do WCAG AA) wg kolejności.
$it_tile_color = function (string $color, int $i): string {
    $pal = ['blue', 'teal', 'green', 'violet', 'indigo', 'magenta', 'red', 'dark'];
    return preg_match('/^#[0-9a-f]{3,6}$/i', $color) && panelMetroOnColor($color) === '#ffffff'
        ? $color : panelMetroColor($pal[$i % count($pal)]);
};
$total_active = array_sum(array_column($stats, 'active'));

include dirname(__DIR__) . '/includes/header.php';
panelMetroAssets(panelMetroColor('orange'));
?>
<style>
.pvm.itm{max-width:1320px}
.pvm .mt-grp{margin-bottom:1.75rem}
.pvm .mt-grp-h{margin:0 0 .5rem;font-size:1.05rem;font-weight:300;color:var(--pvm-muted);letter-spacing:.01em}
.pvm .itm-acts{display:flex;flex-wrap:wrap;gap:.5rem}
.pvm .itm-bar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;margin:0 0 .6rem}
.pvm .itm-bar .mt-h2{margin:0}
.pvm .itm-rows{list-style:none;margin:0;padding:0}
.pvm .itm-rows .mt-svc{margin-bottom:4px;align-items:center;padding:.7rem 1rem}
.pvm .itm-rows .mt-svc>.ic{color:var(--c);font-size:1.35rem}
.pvm .itm-rows h3 a{color:var(--pvm-ink);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600;text-decoration:underline;text-underline-offset:3px;word-break:break-all}
.pvm .itm-rows h3 a:hover{text-decoration-thickness:2px}
.pvm .itm-rows h3 .mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;word-break:break-all}
.pvm .itm-meta{display:flex;flex-wrap:wrap;gap:.2rem 1rem;margin:.2rem 0 0;font-size:.82rem;color:var(--pvm-muted)}
.pvm .itm-meta a{color:var(--pvm-muted);text-decoration:underline;text-underline-offset:2px}
.pvm .itm-meta a:hover{color:var(--pvm-ink)}
.pvm .itm-date{font-size:.8rem;color:var(--pvm-muted);white-space:nowrap;flex-shrink:0}
.pvm .itm-ok{color:var(--m-green);font-size:1.1rem}
@media(max-width:575px){.pvm .itm-rows .mt-svc{flex-wrap:wrap}.pvm .itm-date{width:100%;padding-left:3.25rem}}
:root[data-theme="hc"] .pvm .itm-rows .mt-svc>.ic{color:#000}
</style>
<div class="container-fluid py-4">
<div class="pvm itm">

  <div class="mt-head">
    <div>
      <div class="mt-kicker">Infrastruktura IT</div>
      <h1 class="mt-title">Dostępy i Infrastruktura IT</h1>
      <p class="mt-lead">Zarządzanie kontami, dostępami i hasłami serwisów</p>
    </div>
    <div class="itm-acts">
      <a href="<?= APP_URL ?>/it/accounts.php" class="mt-btn mt-btn--ghost"><i class="bi bi-list-ul" aria-hidden="true"></i> Wszystkie konta</a>
      <a href="<?= APP_URL ?>/it/passwords.php" class="mt-btn mt-btn--ghost"><i class="bi bi-key" aria-hidden="true"></i> Hasła</a>
      <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/it/services.php" class="mt-btn mt-btn--ghost"><i class="bi bi-gear" aria-hidden="true"></i> Serwisy</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="mt-pivot" data-pvm-pivot role="tablist" aria-label="Sekcje: Dostępy i Infrastruktura IT">
    <button type="button" role="tab" id="itm-tab-start" aria-controls="itm-p-start" data-tab="start">Start</button>
    <button type="button" role="tab" id="itm-tab-konta" aria-controls="itm-p-konta" data-tab="konta">Ostatnie konta</button>
    <button type="button" role="tab" id="itm-tab-hasla" aria-controls="itm-p-hasla" data-tab="hasla">Ostatnie hasła</button>
  </div>

  <!-- Start: kafle serwisów -->
  <section id="itm-p-start" data-pvm-panel="start" role="tabpanel" aria-labelledby="itm-tab-start" tabindex="-1">
    <section class="mt-grp" aria-labelledby="itm-h-serwisy">
      <h2 class="mt-grp-h" id="itm-h-serwisy">Konta w serwisach</h2>
      <?php if ($stats): ?>
      <nav class="mt-grid" aria-labelledby="itm-h-serwisy">
        <?php $i = 0; foreach ($stats as $slug => $s):
            panelMetroTile([
                'href'  => APP_URL . '/it/accounts.php?service=' . urlencode($slug),
                'icon'  => $s['service']['icon'] ?: 'bi-hdd-network',
                'label' => $s['service']['name'],
                'val'   => (string)$s['active'],
                'sub'   => 'aktywne' . ($s['inactive'] ? ' · ' . $s['inactive'] . ' nieaktywne' : ''),
                'color' => $it_tile_color((string)$s['service']['color'], $i++),
            ]);
        endforeach; ?>
      </nav>
      <?php else: ?>
      <div class="mt-empty" role="status">Brak aktywnych serwisów.</div>
      <?php endif; ?>
    </section>

    <section class="mt-grp" aria-labelledby="itm-h-narz">
      <h2 class="mt-grp-h" id="itm-h-narz">Przegląd</h2>
      <nav class="mt-grid" aria-labelledby="itm-h-narz">
        <?php
        panelMetroTile(['href' => APP_URL . '/it/accounts.php', 'icon' => 'bi-person-badge', 'label' => 'Wszystkie konta',
            'val' => (string)$total_active, 'sub' => 'aktywnych łącznie', 'color' => 'orange', 'size' => 'w']);
        panelMetroTile(['href' => APP_URL . '/it/passwords.php', 'icon' => 'bi-key-fill', 'label' => 'Wydane hasła',
            'val' => (string)$total_passwords, 'sub' => 'łącznie w historii', 'color' => 'dark']);
        panelMetroTile(['href' => '#konta', 'go' => 'konta', 'icon' => 'bi-clock-history', 'label' => 'Ostatnie konta',
            'val' => (string)count($recent_accounts), 'sub' => 'ostatnio zmienione', 'color' => 'teal']);
        panelMetroTile(['href' => '#hasla', 'go' => 'hasla', 'icon' => 'bi-shield-lock', 'label' => 'Ostatnie hasła',
            'val' => (string)count($recent_passwords), 'sub' => 'ostatnio wydane', 'color' => 'indigo']);
        if (is_admin()) {
            panelMetroTile(['href' => APP_URL . '/it/services.php', 'icon' => 'bi-gear', 'label' => 'Serwisy',
                'val' => (string)count($services), 'sub' => 'konfiguracja', 'color' => 'grey']);
        }
        ?>
      </nav>
    </section>
  </section>

  <!-- Ostatnie konta -->
  <section id="itm-p-konta" data-pvm-panel="konta" role="tabpanel" aria-labelledby="itm-tab-konta" tabindex="-1" hidden>
    <div class="itm-bar">
      <h2 class="mt-h2" id="itm-h-konta"><b>Ostatnie konta IT</b></h2>
      <a href="<?= APP_URL ?>/it/accounts.php" class="mt-btn mt-btn--ghost">Wszystkie konta <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <?php if ($recent_accounts): ?>
    <ul class="itm-rows" aria-labelledby="itm-h-konta">
      <?php foreach ($recent_accounts as $a): ?>
      <li class="mt-svc" style="--c:<?= h($a['service_color'] ?: '#5f5f5f') ?>">
        <i class="bi <?= h($a['service_icon'] ?: 'bi-hdd-network') ?> ic" aria-hidden="true"></i>
        <div class="mt-svc__bd">
          <h3>
            <a href="<?= APP_URL ?>/it/accounts.php?id=<?= (int)$a['id'] ?>"><?= h($a['login'] ?: $a['display_name'] ?: '—') ?></a>
            <?php if ($a['is_active']): ?>
            <span class="mt-badge mt-badge--ok">aktywne</span>
            <?php else: ?>
            <span class="mt-badge">nieaktywne</span>
            <?php endif; ?>
          </h3>
          <div class="itm-meta">
            <span><?= h($a['service_name']) ?></span>
            <?php if ($a['contract_type'] && $a['contract_id']): ?>
            <a href="<?= it_contract_url($a['contract_type'], (int)$a['contract_id']) ?>"><?= h(it_contract_label($a['contract_type'])) ?> — umowa <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
            <?php endif; ?>
          </div>
        </div>
        <span class="itm-date"><span class="visually-hidden">Aktualizacja: </span><?= date_pl(substr((string)$a['updated_at'], 0, 10)) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="mt-empty" role="status">Brak kont IT</div>
    <?php endif; ?>
  </section>

  <!-- Ostatnie hasła -->
  <section id="itm-p-hasla" data-pvm-panel="hasla" role="tabpanel" aria-labelledby="itm-tab-hasla" tabindex="-1" hidden>
    <div class="itm-bar">
      <h2 class="mt-h2" id="itm-h-hasla"><b>Ostatnie hasła</b></h2>
      <a href="<?= APP_URL ?>/it/passwords.php" class="mt-btn mt-btn--ghost">Wszystkie hasła <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
    <?php if ($recent_passwords): ?>
    <ul class="itm-rows" aria-labelledby="itm-h-hasla">
      <?php foreach ($recent_passwords as $p): ?>
      <li class="mt-svc" style="--c:<?= h($p['service_color'] ?: '#5f5f5f') ?>">
        <i class="bi <?= h($p['service_icon'] ?: 'bi-key') ?> ic" aria-hidden="true"></i>
        <div class="mt-svc__bd">
          <h3>
            <span class="mono"><?= h($p['login'] ?: '—') ?></span>
            <?php if ($p['is_superseded']): ?><span class="mt-badge">wygasłe</span><?php endif; ?>
            <?php if ($p['sent_to_email']): ?>
            <i class="bi bi-envelope-check itm-ok" title="Wysłano na: <?= h($p['sent_to_email']) ?>" aria-hidden="true"></i>
            <span class="visually-hidden">Wysłano na: <?= h($p['sent_to_email']) ?></span>
            <?php endif; ?>
          </h3>
          <div class="itm-meta">
            <span><?= h($p['service_name']) ?></span>
            <?php if ($p['issued_by_name']): ?><span>wydał(a): <?= h($p['issued_by_name']) ?></span><?php endif; ?>
          </div>
        </div>
        <span class="itm-date"><span class="visually-hidden">Wydano: </span><?= date_pl(substr((string)$p['issued_at'], 0, 10)) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div class="mt-empty" role="status">Brak historii haseł</div>
    <?php endif; ?>
  </section>

</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

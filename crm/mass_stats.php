<?php
/**
 * crm/mass_stats.php — Statystyki wysyłek masowych: skuteczność, otwarcia i kliknięcia (gdy włączone śledzenie).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crmMassStats.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_require('campaigns', 'read');
crm_mass_stats_schema();

$PAGE_TITLE = 'Statystyki wysyłek';
$rows    = crm_mass_stats_rows(100);
$monthly = crm_mass_stats_monthly(12);
$detail  = null;
if (isset($_GET['id'])) foreach ($rows as $r) if ((int)$r['id'] === (int)$_GET['id']) $detail = $r;

$tot_ok = array_sum(array_column($rows, 'sent_ok'));
$tot_fail = array_sum(array_column($rows, 'sent_fail'));
$tot_all = $tot_ok + $tot_fail;
$tot_tracked = array_sum(array_column($rows, 'tracked'));
$tot_open = array_sum(array_column($rows, 'opened'));
$pct = static fn(int|float $a, int|float $b): string => $b > 0 ? number_format(100 * $a / $b, 1, ',', '') . '%' : '—';
$max_m = max(1, ...array_map(fn($m) => (int)$m['ok'] + (int)$m['fail'], $monthly ?: [['ok' => 0, 'fail' => 0]]));

include __DIR__ . '/includes/header_crm.php';
?>
<style>
.ms2-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr));gap:.75rem;margin-bottom:1rem}
.ms2-kpi{background:#fff;border:1px solid #D1D5DB;border-top:4px solid #0F766E;padding:.7rem .9rem}
.ms2-kpi b{display:block;font-size:1.6rem;font-weight:600;color:#111827;line-height:1.2;font-variant-numeric:tabular-nums}
.ms2-kpi span{font-size:.78rem;color:#4B5563}
.ms2-card{background:#fff;border:1px solid #D1D5DB;margin-bottom:1rem}
.ms2-card>h2{margin:0;padding:.6rem 1rem;font-size:.95rem;font-weight:600;background:#F3F4F6;border-bottom:1px solid #D1D5DB;color:#111827}
.ms2-t{width:100%;border-collapse:collapse;font-size:.84rem}
.ms2-t th,.ms2-t td{padding:.5rem .75rem;border-bottom:1px solid #E5E7EB;text-align:left;vertical-align:middle}
.ms2-t thead th{background:#F9FAFB;font-size:.74rem;text-transform:uppercase;letter-spacing:.04em;color:#374151}
.ms2-t td.n{text-align:right;font-variant-numeric:tabular-nums}
.ms2-t tr:hover td{background:#F9FAFB}
.ms2-bar{display:flex;height:.9rem;background:#E5E7EB;min-width:8rem}
.ms2-bar i{display:block;height:100%}
.ms2-ok{background:#0F766E}.ms2-bad{background:#B91C1C}
.ms2-note{font-size:.76rem;color:#4B5563;padding:.6rem 1rem}
.ms2-ch{display:inline-block;padding:.05rem .45rem;font-size:.72rem;font-weight:700;background:#E6F4F1;color:#0F4C47}
.ms2-ch--sms{background:#FEF3C7;color:#78350F}
</style>

<div class="crm-page-header mb-3">
  <div>
    <h1 class="crm-page-title"><i class="bi bi-bar-chart-line" style="color:var(--crm-primary)" aria-hidden="true"></i> Statystyki wysyłek masowych</h1>
    <div class="crm-page-subtitle">Skuteczność dostarczenia oraz otwarcia i kliknięcia (dla wysyłek ze włączonym śledzeniem)</div>
  </div>
  <div class="crm-page-actions"><a href="<?= APP_URL ?>/crm/mass_send.php" class="btn btn-crm-outline btn-sm"><i class="bi bi-send me-1" aria-hidden="true"></i>Wysyłka masowa</a></div>
</div>

<?php if ($detail): $links = crm_mass_stats_links((int)$detail['id']); $openers = crm_mass_stats_openers((int)$detail['id']); ?>
<div class="ms2-card">
  <h2><a href="<?= APP_URL ?>/crm/mass_stats.php" aria-label="Wróć do listy">←</a> <?= h($detail['subject'] ?: ('Wysyłka ' . strtoupper($detail['channel']))) ?> · <?= h(date('d.m.Y H:i', strtotime($detail['created_at']))) ?></h2>
  <div class="ms2-kpis" style="padding:1rem 1rem 0">
    <div class="ms2-kpi"><b><?= (int)$detail['sent_ok'] ?></b><span>dostarczono (z <?= (int)$detail['recipients'] ?>)</span></div>
    <div class="ms2-kpi" style="border-top-color:#B91C1C"><b><?= (int)$detail['sent_fail'] ?></b><span>błędy</span></div>
    <?php if ($detail['tracked']): ?>
    <div class="ms2-kpi"><b><?= $pct($detail['opened'], $detail['tracked']) ?></b><span>otwarć unikalnych (<?= (int)$detail['opened'] ?>)</span></div>
    <div class="ms2-kpi"><b><?= $pct($detail['clicked'], $detail['tracked']) ?></b><span>kliknęło (<?= (int)$detail['clicked'] ?>, łącznie kliknięć <?= (int)$detail['clicks_total'] ?>)</span></div>
    <?php endif; ?>
  </div>
  <?php if (!$detail['tracked']): ?>
  <div class="ms2-note">Ta wysyłka nie miała włączonego śledzenia — są tylko dane o dostarczeniu. Śledzenie włączasz w kroku „Wyślij”.</div>
  <?php else: ?>
  <table class="ms2-t"><thead><tr><th>Link</th><th class="n">Kliknięcia</th></tr></thead><tbody>
    <?php foreach ($links as $lk): ?><tr><td style="word-break:break-all"><?= h($lk['url']) ?></td><td class="n"><?= (int)$lk['clicks'] ?></td></tr><?php endforeach; ?>
    <?php if (!$links): ?><tr><td colspan="2" class="ms2-note">W treści nie było linków.</td></tr><?php endif; ?>
  </tbody></table>
  <table class="ms2-t"><thead><tr><th>Kontakt</th><th>Pierwsze otwarcie</th><th class="n">Otwarć</th><th class="n">Kliknięć</th></tr></thead><tbody>
    <?php foreach ($openers as $o): ?>
    <tr><td><a href="<?= APP_URL ?>/crm/contact.php?id=<?= (int)$o['contact_id'] ?>"><?= h($o['imie_nazwisko']) ?></a> <span class="text-muted"><?= h($o['email']) ?></span></td>
        <td><?= $o['opened_at'] ? h(date('d.m.Y H:i', strtotime($o['opened_at']))) : '—' ?></td><td class="n"><?= (int)$o['open_count'] ?></td><td class="n"><?= (int)$o['click_count'] ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$openers): ?><tr><td colspan="4" class="ms2-note">Nikt jeszcze nie otworzył wiadomości.</td></tr><?php endif; ?>
  </tbody></table>
  <div class="ms2-note">Otwarcia liczone są po załadowaniu obrazka — klienci poczty, którzy blokują obrazy, nie zostaną uwzględnieni, a część programów otwiera wiadomość automatycznie. Traktuj liczby orientacyjnie.</div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="ms2-kpis">
  <div class="ms2-kpi"><b><?= count($rows) ?></b><span>wysyłek (ostatnie 100)</span></div>
  <div class="ms2-kpi"><b><?= (int)$tot_ok ?></b><span>dostarczonych</span></div>
  <div class="ms2-kpi" style="border-top-color:#B91C1C"><b><?= (int)$tot_fail ?></b><span>błędów</span></div>
  <div class="ms2-kpi"><b><?= $pct($tot_ok, $tot_all) ?></b><span>skuteczność dostarczenia</span></div>
  <div class="ms2-kpi"><b><?= $tot_tracked ? $pct($tot_open, $tot_tracked) : '—' ?></b><span>otwarć (wysyłki śledzone)</span></div>
</div>

<div class="ms2-card">
  <h2>Miesięcznie</h2>
  <table class="ms2-t"><thead><tr><th>Miesiąc</th><th class="n">Wysyłek</th><th class="n">E-mail</th><th class="n">SMS</th><th class="n">Błędy</th><th style="width:40%">Dostarczone / błędy</th></tr></thead><tbody>
  <?php foreach (array_reverse($monthly, true) as $ym => $m): $all = (int)$m['ok'] + (int)$m['fail']; ?>
    <tr><td><?= h($ym) ?></td><td class="n"><?= (int)$m['n'] ?></td><td class="n"><?= (int)$m['ok_email'] ?></td><td class="n"><?= (int)$m['ok_sms'] ?></td><td class="n"><?= (int)$m['fail'] ?></td>
      <td><div class="ms2-bar" role="img" aria-label="<?= (int)$m['ok'] ?> dostarczonych, <?= (int)$m['fail'] ?> błędów"><i class="ms2-ok" style="width:<?= round(100 * $m['ok'] / $max_m, 1) ?>%"></i><i class="ms2-bad" style="width:<?= round(100 * $m['fail'] / $max_m, 1) ?>%"></i></div></td></tr>
  <?php endforeach; ?>
  <?php if (!$monthly): ?><tr><td colspan="6" class="ms2-note">Brak wysyłek.</td></tr><?php endif; ?>
  </tbody></table>
</div>

<div class="ms2-card">
  <h2>Wysyłki</h2>
  <table class="ms2-t"><thead><tr><th>Data</th><th>Kanał</th><th>Temat / adresaci</th><th class="n">Odbiorców</th><th class="n">Błędy</th><th class="n">Otwarcia</th><th class="n">Kliknięcia</th><th><span class="visually-hidden">Szczegóły</span></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
      <td><span class="ms2-ch<?= $r['channel'] === 'sms' ? ' ms2-ch--sms' : '' ?>"><?= h(strtoupper($r['channel'])) ?></span></td>
      <td><strong><?= h($r['subject'] ?: '—') ?></strong><div class="text-muted" style="font-size:.76rem"><?= h(trim(($r['group_name'] ?: '') . ($r['tag_filter'] ? ' · tagi: ' . $r['tag_filter'] : ''), ' ·') ?: '—') ?> · <?= h($r['user_name'] ?: '') ?></div></td>
      <td class="n"><?= (int)$r['recipients'] ?></td>
      <td class="n"><?= (int)$r['sent_fail'] ?></td>
      <td class="n"><?= $r['tracked'] ? (int)$r['opened'] . ' <span class="text-muted">(' . $pct($r['opened'], $r['tracked']) . ')</span>' : '—' ?></td>
      <td class="n"><?= $r['tracked'] ? (int)$r['clicked'] . ' <span class="text-muted">(' . $pct($r['clicked'], $r['tracked']) . ')</span>' : '—' ?></td>
      <td><a class="btn btn-sm btn-outline-secondary" style="border-radius:0" href="?id=<?= (int)$r['id'] ?>">Szczegóły</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="8" class="ms2-note">Brak wysyłek masowych.</td></tr><?php endif; ?>
  </tbody></table>
</div>
<?php include __DIR__ . '/includes/footer_crm.php'; ?>

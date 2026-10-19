<?php
/**
 * modules/strategy_actions/view/report.php — realizacja celów statutowych §6 (Metro): kafle celów z liczbą działań,
 * tabela zbiorcza (statusy, finansowanie, wskaźniki, etapy) i lista działań w każdym celu. Do druku i do pliku.
 * Zmienne: $year (null = wszystkie lata), $years, $report (ActionsReport::goalsReport).
 */
$navActive = 'raport';
$rokParam = $year === null ? 'wszystkie' : (string)$year;
$fmt = fn(float $v) => rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
$total = ['count' => 0, 'active' => 0, 'done' => 0, 'kwota' => 0.0, 'plan' => 0.0, 'real' => 0.0];
foreach ($report as $g) foreach ($total as $k => $_) $total[$k] += $g[$k];
include __DIR__ . '/_head.php';
?>
<div class="dz-top">
  <div>
    <h1 class="dz-title">Realizacja celów statutowych §6</h1>
    <p class="dz-sub"><?= $year === null ? 'Wszystkie lata' : 'Rok ' . (int)$year ?> · działania, których okres przypada na wybrany rok · <?= (int)$total['count'] ?> działań</p>
  </div>
  <div class="d-flex gap-2 flex-wrap dz-noprint align-items-end">
    <form method="get" action="<?= h(ActionsService::url('raport')) ?>" class="d-flex gap-2 align-items-end">
      <div>
        <label class="dz-lbl" for="r-rok">Rok</label>
        <select id="r-rok" name="rok" class="dz-in" onchange="this.form.submit()">
          <?php foreach ($years as $y): ?><option value="<?= (int)$y ?>"<?= $year === $y ? ' selected' : '' ?>><?= (int)$y ?></option><?php endforeach; ?>
          <option value="wszystkie"<?= $year === null ? ' selected' : '' ?>>Wszystkie lata</option>
        </select>
      </div>
      <noscript><button type="submit" class="dz-btn">Pokaż</button></noscript>
    </form>
    <a class="dz-btn dz-btn--ghost" href="<?= h(ActionsService::url('raport/eksport', ['rok' => $rokParam, 'format' => 'xlsx'])) ?>"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i>Excel</a>
    <a class="dz-btn dz-btn--ghost" href="<?= h(ActionsService::url('raport/eksport', ['rok' => $rokParam, 'format' => 'csv'])) ?>"><i class="bi bi-filetype-csv" aria-hidden="true"></i>CSV</a>
    <button type="button" class="dz-btn dz-btn--ghost" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i>Drukuj</button>
  </div>
</div>

<div class="dz-tiles" aria-label="Liczba działań w celach §6">
  <?php foreach ($report as $k => $g): ?>
  <a class="dz-tile" href="#cel-<?= (int)$k ?>" style="--c:<?= $g['count'] ? 'var(--dz)' : '#8a8a8a' ?>;text-decoration:none">
    <span class="n"><?= (int)$g['count'] ?></span>
    <span><b><?= h($k ? '§6 pkt ' . $k : 'Bez celu') ?></b><br><small><?= h(preg_replace('/^\d+\.\s*/', '', $g['label'])) ?></small></span>
  </a>
  <?php endforeach; ?>
</div>

<h2 class="dz-head">Zestawienie</h2>
<div class="dz-scroll">
<table class="dz-table">
  <caption class="visually-hidden">Zestawienie działań według celów statutowych</caption>
  <thead><tr>
    <th scope="col">Cel statutowy</th><th scope="col" class="dz-num">Działania</th><th scope="col" class="dz-num">W toku</th>
    <th scope="col" class="dz-num">Zakończone</th><th scope="col" class="dz-num">Kwota z grantów</th>
    <th scope="col" class="dz-num">Wskaźniki plan / wykonanie</th><th scope="col" style="min-width:130px">Realizacja</th><th scope="col" class="dz-num">Etapy</th>
  </tr></thead>
  <tbody>
  <?php foreach ($report as $k => $g): ?>
  <tr>
    <th scope="row" style="font-weight:600;text-align:left"><a class="nm" href="#cel-<?= (int)$k ?>"><?= h($g['label']) ?></a></th>
    <td class="dz-num"><?= (int)$g['count'] ?></td>
    <td class="dz-num"><?= (int)$g['active'] ?></td>
    <td class="dz-num"><?= (int)$g['done'] ?></td>
    <td class="dz-num"><?= $g['kwota'] > 0 ? h(money($g['kwota'])) : '—' ?></td>
    <td class="dz-num"><?= $g['plan'] > 0 ? h($fmt($g['plan']) . ' / ' . $fmt($g['real'])) : '—' ?></td>
    <td><?php if ($g['pct'] !== null): ?><div class="d-flex align-items-center gap-2"><div class="dz-bar"><span style="width:<?= (int)$g['pct'] ?>%;--c:<?= $g['pct'] >= 100 ? '#008a00' : ($g['pct'] >= 50 ? '#00808a' : '#c25100') ?>"></span></div><small><?= (int)$g['pct'] ?>%</small></div><?php else: ?>—<?php endif; ?></td>
    <td class="dz-num"><?= $g['msTotal'] ? (int)$g['msDone'] . '/' . (int)$g['msTotal'] : '—' ?></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot><tr style="border-top:2px solid var(--dark)">
    <th scope="row" style="text-align:left">Razem</th>
    <td class="dz-num"><b><?= (int)$total['count'] ?></b></td>
    <td class="dz-num"><?= (int)$total['active'] ?></td>
    <td class="dz-num"><?= (int)$total['done'] ?></td>
    <td class="dz-num"><b><?= h(money($total['kwota'])) ?></b></td>
    <td class="dz-num"><?= $total['plan'] > 0 ? h($fmt($total['plan']) . ' / ' . $fmt($total['real'])) : '—' ?></td>
    <td colspan="2"></td>
  </tr></tfoot>
</table>
</div>
<p class="dz-muted small mt-1">Kwoty, wskaźniki i etapy bez działań anulowanych. Wskaźniki sumowane w obrębie celu — porównuj je w ramach jednego rodzaju miary.</p>

<?php foreach ($report as $k => $g): if (!$g['count']) continue; ?>
<section id="cel-<?= (int)$k ?>" aria-labelledby="cel-h-<?= (int)$k ?>">
  <h2 class="dz-head" id="cel-h-<?= (int)$k ?>"><?= h($g['label']) ?> <small><?= (int)$g['count'] ?> działań</small></h2>
  <div class="dz-scroll"><table class="dz-table">
    <thead><tr><th scope="col">Działanie</th><th scope="col">Status</th><th scope="col">Okres</th><th scope="col">Koordynator</th><th scope="col" class="dz-num">Kwota</th><th scope="col" class="dz-num">Wskaźniki</th></tr></thead>
    <tbody>
    <?php foreach ($g['actions'] as $a): ?>
    <tr>
      <td><a class="nm" href="<?= h(ActionsService::url((string)$a['id'])) ?>"><?= h($a['nazwa']) ?></a></td>
      <td><span class="dz-badge" style="--c:<?= h(ActionsService::statusColor($a['status'])) ?>"><?= h(ActionsService::statusLabel($a['status'])) ?></span></td>
      <td class="text-nowrap"><?= h($a['od'] ? date_pl($a['od']) . ($a['do'] && $a['do'] !== $a['od'] ? ' – ' . date_pl($a['do']) : '') : '—') ?></td>
      <td><?= h($a['koordynator'] ?: '—') ?></td>
      <td class="dz-num"><?= $a['kwota'] > 0 ? h(money($a['kwota'])) : ($a['wlasne'] ? 'własne' : '—') ?></td>
      <td class="dz-num"><?= $a['pct'] !== null ? (int)$a['pct'] . '%' : '—' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endforeach; ?>
<?php include __DIR__ . '/_foot.php'; ?>

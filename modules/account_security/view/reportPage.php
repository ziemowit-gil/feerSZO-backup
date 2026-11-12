<?php
/**
 * modules/account_security/view/reportPage.php — strona „Raport bezpieczeństwa kont” w chrome Systemu Tożsamości.
 * Zmienne z AccountSecurityReportController::page(): $f (filtry), $all (wiersze bez filtra problemu), $sum (liczniki),
 * $rows (wiersze po filtrze), $roles (rola => nazwa). WCAG: tabela z <caption> i th scope, stan opisany tekstem (nie tylko
 * kolorem), filtry z etykietami, kafle podsumowania to linki z pełną nazwą filtra.
 */
$PAGE_TITLE = 'Raport bezpieczeństwa kont';
$TZ_ACTIVE  = 'administracja';
include dirname(__DIR__, 3) . '/tozsamosc/_head.php';

$asrRoleName = [];
foreach ($roles as $r) $asrRoleName[(string)$r['name']] = (string)$r['display_name'];
$asrShown = array_slice($rows, 0, AccountSecurityReportController::DISPLAY_MAX);
$asrYes = fn(bool $ok, string $yes, string $no): string => '<span class="tz-badge ' . ($ok ? 'tz-badge--ok' : 'tz-badge--warn') . '">' . h($ok ? $yes : $no) . '</span>';
$asrPct = fn(int $n): string => $sum['total'] ? (string)round($n / $sum['total'] * 100) . '%' : '0%';
?>
<style>
.asr-tiles{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.75rem;margin:0 0 1rem}
.asr-tile{display:block;background:#fff;border-radius:var(--tz-r-lg);box-shadow:var(--tz-shadow);padding:.85rem 1rem;text-decoration:none;color:var(--tz-fg);border:2px solid transparent;min-height:44px}
.asr-tile:hover{box-shadow:var(--tz-shadow-8);color:var(--tz-fg)}
.asr-tile[aria-current="true"]{border-color:var(--tz)}
.asr-tile b{display:block;font-size:26px;line-height:32px;font-weight:600}
.asr-tile span{display:block;font-size:13px;color:var(--tz-fg2)}
.asr-tile.is-crit b{color:#a4262c}
.asr-filters{display:flex;flex-wrap:wrap;gap:.75rem 1rem;align-items:flex-end}
.asr-filters label{display:block;font-weight:600;font-size:13px;margin-bottom:.25rem;color:var(--tz-fg)}
.asr-filters .form-control,.asr-filters .form-select{min-height:36px;min-width:180px}
.asr-tablewrap{overflow-x:auto}
.asr-table{width:100%;border-collapse:collapse;font-size:13.5px}
.asr-table caption{caption-side:top;text-align:left;color:var(--tz-fg2);padding:0 0 .5rem}
.asr-table th,.asr-table td{padding:.55rem .6rem;border-bottom:1px solid var(--tz-line);vertical-align:top;text-align:left}
.asr-table thead th{font-weight:600;color:var(--tz-fg);background:#fafafa;white-space:nowrap}
.asr-table tbody tr.is-crit{background:#fdf3f4}
.asr-who b{display:block;font-weight:600}
.asr-who span{display:block;color:var(--tz-muted);font-size:12.5px;word-break:break-all}
.asr-issues{display:flex;flex-wrap:wrap;gap:.25rem}
.asr-num{font-variant-numeric:tabular-nums}
</style>

<div class="tz-h">
  <h1><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Raport bezpieczeństwa kont</h1>
  <p>Które konta nie mają drugiego składnika logowania, zweryfikowanego telefonu albo od dawna nikt z nich nie korzysta. Kliknij kafel, aby zawęzić listę.</p>
</div>

<?= flash_html() ?>

<nav class="asr-tiles" aria-label="Podsumowanie i szybkie filtry">
  <a class="asr-tile" href="<?= h(AccountSecurityReportController::url($f, false, ['issue' => ''])) ?>"<?= $f['issue'] === '' ? ' aria-current="true"' : '' ?>>
    <b class="asr-num"><?= (int)$sum['total'] ?></b><span>Wszystkie konta<?= $f['inactive'] ? '' : ' aktywne' ?></span></a>
  <?php foreach (AccountSecurityReport::ISSUES as $k => [$label, $desc]): ?>
  <a class="asr-tile<?= $k === 'priv_no_mfa' && $sum[$k] > 0 ? ' is-crit' : '' ?>" href="<?= h(AccountSecurityReportController::url($f, false, ['issue' => $k])) ?>"
     title="<?= h($desc) ?>"<?= $f['issue'] === $k ? ' aria-current="true"' : '' ?>>
    <b class="asr-num"><?= (int)$sum[$k] ?></b><span><?= h($label) ?> · <?= $asrPct((int)$sum[$k]) ?><span class="visually-hidden"> — <?= h($desc) ?></span></span></a>
  <?php endforeach; ?>
</nav>

<?php if ($sum['priv_no_mfa'] > 0): ?>
<div class="alert alert-danger d-flex gap-2 align-items-start" role="alert">
  <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
  <div><strong><?= (int)$sum['priv_no_mfa'] ?></strong> <?= $sum['priv_no_mfa'] === 1 ? 'konto uprzywilejowane (administrator lub operator) nie ma' : 'kont uprzywilejowanych (administrator lub operator) nie ma' ?>
    drugiego składnika logowania. Takie konto chronione samym hasłem to największe ryzyko — poproś te osoby o włączenie MFA w Systemie Tożsamości.</div>
</div>
<?php endif; ?>

<section class="tz-card" aria-labelledby="asr-f-h">
  <div class="tz-card__hd"><i class="bi bi-funnel" aria-hidden="true"></i><h2 id="asr-f-h" class="m-0" style="font-size:16px;font-weight:600">Filtry</h2></div>
  <div class="tz-card__bd">
    <form method="get" action="<?= h(APP_URL . AccountSecurityReportController::ENTRY) ?>" class="asr-filters">
      <div><label for="asr-q">Szukaj (imię, nazwisko, login)</label>
        <input type="search" class="form-control" id="asr-q" name="q" value="<?= h($f['q']) ?>" maxlength="100"></div>
      <div><label for="asr-role">Rola</label>
        <select class="form-select" id="asr-role" name="role">
          <option value="">— wszystkie —</option>
          <?php foreach ($roles as $r): ?><option value="<?= h($r['name']) ?>"<?= $f['role'] === $r['name'] ? ' selected' : '' ?>><?= h($r['display_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="asr-issue">Problem</label>
        <select class="form-select" id="asr-issue" name="issue">
          <option value="">— wszystkie konta —</option>
          <?php foreach (AccountSecurityReport::ISSUES as $k => [$label]): ?><option value="<?= h($k) ?>"<?= $f['issue'] === $k ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-check" style="min-height:36px;display:flex;align-items:center;gap:.4rem">
        <input class="form-check-input m-0" type="checkbox" id="asr-inactive" name="inactive" value="1"<?= $f['inactive'] ? ' checked' : '' ?>>
        <label class="form-check-label m-0" for="asr-inactive" style="font-weight:400">Pokaż też konta nieaktywne</label></div>
      <div class="d-flex gap-2">
        <button type="submit" class="tz-btn">Filtruj</button>
        <a class="tz-btn tz-btn--ghost" href="<?= h(APP_URL . AccountSecurityReportController::ENTRY) ?>">Wyczyść</a>
      </div>
    </form>
  </div>
</section>

<section class="tz-card" aria-labelledby="asr-t-h">
  <div class="tz-card__hd" style="justify-content:space-between;flex-wrap:wrap">
    <h2 id="asr-t-h" class="m-0" style="font-size:16px;font-weight:600"><i class="bi bi-people" aria-hidden="true"></i> Konta (<?= count($rows) ?>)</h2>
    <a class="tz-btn tz-btn--ghost tz-btn--sm" href="<?= h(AccountSecurityReportController::url($f, true)) ?>"><i class="bi bi-filetype-csv" aria-hidden="true"></i> Pobierz CSV<span class="visually-hidden"> — <?= count($rows) ?> kont z bieżącymi filtrami</span></a>
  </div>
  <div class="tz-card__bd">
    <?php if (!$rows): ?>
      <p class="m-0" style="color:var(--tz-muted)">Brak kont spełniających filtry.</p>
    <?php else: ?>
    <div class="asr-tablewrap">
      <table class="asr-table">
        <caption>Stan zabezpieczeń kont<?= $f['issue'] !== '' ? ' — filtr: ' . h(AccountSecurityReport::ISSUES[$f['issue']][0]) : '' ?>. Najpierw konta z problemem krytycznym. Czas: strefa polska.</caption>
        <thead><tr>
          <th scope="col">Osoba</th><th scope="col">MFA</th><th scope="col">Telefon</th><th scope="col">Hasło</th>
          <th scope="col">Microsoft 365</th><th scope="col">Ostatnie logowanie</th><th scope="col">Nieudane (30 dni)</th>
          <th scope="col">Sesje</th><th scope="col">Problemy</th><th scope="col"><span class="visually-hidden">Akcje</span></th>
        </tr></thead>
        <tbody>
          <?php foreach ($asrShown as $r): $crit = in_array('priv_no_mfa', $r['issues'], true); ?>
          <tr<?= $crit ? ' class="is-crit"' : '' ?>>
            <th scope="row" class="asr-who"><b><?= h($r['name'] !== '' ? $r['name'] : '(bez nazwy)') ?></b>
              <span><?= h($r['email']) ?> · <?= h($asrRoleName[$r['role']] ?? $r['role']) ?><?= $r['active'] ? '' : ' · nieaktywne' ?></span></th>
            <td><?= $r['mfa'] ? '<span class="tz-badge tz-badge--ok">' . h($r['mfa_label']) . '</span>' : '<span class="tz-badge ' . ($r['privileged'] ? 'tz-badge--err' : 'tz-badge--warn') . '">brak</span>' ?></td>
            <td><?= $asrYes($r['phone_ok'], 'zweryfikowany', 'brak') ?></td>
            <td><?= $r['has_pwd'] ? 'ustawione' : '<span style="color:var(--tz-muted)">brak</span>' ?></td>
            <td><?= $r['ms_linked'] ? 'powiązane' : '<span style="color:var(--tz-muted)">—</span>' ?></td>
            <td class="asr-num"><?= $r['last_login'] !== '' ? '<time datetime="' . h(AccountSecurityDevices::isoTime($r['last_login'])) . '">' . h(AccountSecurityDevices::localTime($r['last_login'])) . '</time>' : 'nigdy' ?></td>
            <td class="asr-num"><?= $r['fails_30'] >= AccountSecurityReport::FAILS_ALERT ? '<span class="tz-badge tz-badge--err">' . (int)$r['fails_30'] . '</span>' : (int)$r['fails_30'] ?></td>
            <td class="asr-num"><?= (int)$r['sessions_n'] ?></td>
            <td><div class="asr-issues"><?php foreach ($r['issues'] as $i): if ($i === 'no_mfa' && $crit) continue; ?>
              <span class="tz-badge <?= in_array($i, ['priv_no_mfa', 'fails'], true) ? 'tz-badge--err' : ($i === 'no_password' ? 'tz-badge--off' : 'tz-badge--warn') ?>"><?= h(AccountSecurityReport::ISSUES[$i][0]) ?></span>
              <?php endforeach; ?><?= !$r['issues'] ? '<span class="tz-badge tz-badge--ok">bez uwag</span>' : '' ?></div></td>
            <td><a class="tz-btn tz-btn--ghost tz-btn--sm" href="<?= h(APP_URL . '/tozsamosc/uzytkownicy.php?' . http_build_query(['q' => $r['email']])) ?>">Konto<span class="visually-hidden"> — <?= h($r['name'] ?: $r['email']) ?></span></a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($rows) > count($asrShown)): ?>
      <p class="m-0" style="margin-top:.75rem!important;color:var(--tz-fg2)">Pokazano <?= count($asrShown) ?> z <?= count($rows) ?> kont — zawęź filtry albo pobierz pełny CSV.</p>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
<?php include dirname(__DIR__, 3) . '/tozsamosc/_foot.php'; ?>

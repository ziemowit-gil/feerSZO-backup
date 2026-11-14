<?php
/**
 * modules/cloudDav/view/pimAdmin.php — administrator: kalendarze i kontakty z Microsoft 365 do chmury (GET /kalendarz-kontakty/m365).
 * Zmienne: $jobs, $counts, $flash, $enabled, $exportOk, $importOk, $diag, $org.
 */
$base = rtrim(APP_URL, '/') . '/kalendarz-kontakty/m365';
$fCls = ['success' => 'ok', 'danger' => 'error', 'warning' => 'warn'][$flash['type'] ?? ''] ?? 'info';
$dt = static fn(?string $d): string => $d ? date('d.m H:i', strtotime($d)) : '';
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Kalendarze i kontakty z Microsoft 365 · <?= h($org) ?></title>
<style>
:root { --cyan:#1ba1e2; --dark:#1d1d1d; --ok:#008a00; --warn:#c25100; --err:#a20025; --line:#d9d9d9; --mute:#595959; }
* { box-sizing: border-box; }
body { margin: 0; font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif; color: #1d1d1d; background: #f4f4f4; font-size: 15px; line-height: 1.5; }
a { color: #0f6fa8; }
.bar { background: var(--dark); color: #fff; padding: 0 20px; min-height: 52px; display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 3px solid var(--cyan); flex-wrap: wrap; }
.bar a { color: #fff; font-size: 14px; }
.wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 48px; }
.main { background: #fff; border: 1px solid var(--line); padding: 24px; }
h1 { font-size: 24px; font-weight: 300; margin: 0 0 8px; }
h2 { font-size: 17px; margin: 24px 0 8px; }
.msg { padding: 10px 14px; margin: 0 0 16px; border-left: 4px solid var(--cyan); background: #f7f7f7; }
.msg.ok { border-color: var(--ok); } .msg.warn { border-color: var(--warn); } .msg.error { border-color: var(--err); }
.btn { display: inline-block; padding: 7px 14px; border: 2px solid #0f6fa8; background: #0f6fa8; color: #fff; font: inherit; font-weight: 600; cursor: pointer; border-radius: 0; }
.btn.ghost { background: #fff; color: var(--dark); border-color: #8a8a8a; }
.btn.small { padding: 3px 8px; font-size: 13px; font-weight: 400; }
input[type=email] { padding: 7px 9px; border: 2px solid #8a8a8a; font: inherit; min-width: 280px; }
input:focus, button:focus-visible, a:focus-visible { outline: 3px solid var(--dark); outline-offset: 2px; }
.row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
.tbl { width: 100%; border-collapse: collapse; font-size: 14px; }
.tbl th, .tbl td { border-bottom: 1px solid #e6e6e6; padding: 6px 8px; text-align: left; vertical-align: top; }
.tbl th { background: #f2f2f2; }
.st-done { color: var(--ok); font-weight: 600; } .st-error { color: var(--err); font-weight: 600; } .st-waiting { color: var(--warn); font-weight: 600; }
.mute { color: var(--mute); font-size: 13px; }
.scroll { overflow-x: auto; }
</style>
</head>
<body>
<header class="bar"><b><?= h($org) ?> · Chmura plików</b><a href="<?= h(rtrim(APP_URL, '/') . '/ssoadmin') ?>">Logowanie jednokrotne i chmura</a></header>
<div class="wrap"><main class="main" id="tresc">
  <h1>Kalendarze i kontakty z Microsoft 365</h1>
  <?php if ($flash): ?><div class="msg <?= $fCls ?>" role="<?= $fCls === 'error' ? 'alert' : 'status' ?>"><?= h((string)$flash['msg']) ?></div><?php endif; ?>
  <p>Zadanie najpierw robi kopię kalendarzy i kontaktów z Microsoft 365 (zaszyfrowane pliki w SZO), potem importuje ją do chmury: kalendarze jako „Kalendarz z Outlooka” (i „Outlook: nazwa” dla pozostałych), kontakty do książki „Kontakty z Outlooka”. Uczestników spotkań przenosimy do opisu wydarzenia, żeby chmura nie rozesłała zaproszeń. Ponowny import nadpisuje te same wydarzenia i kontakty, niczego nie dubluje. Microsoft 365 wyłączamy 20 listopada 2026 r., więc kopię trzeba zrobić przed tym dniem.</p>
  <form method="post" action="<?= h($base . '/wlacz') ?>" class="msg <?= $enabled ? 'ok' : 'warn' ?>">
    <?= Router::csrfField() ?><input type="hidden" name="on" value="<?= $enabled ? '0' : '1' ?>">
    <?php if ($enabled): ?>Przenoszenie jest włączone: agent robi zadania co 5 minut, a użytkownicy widzą przycisk na stronie Kalendarz i kontakty.
    <?php else: ?>Przenoszenie jest wyłączone: agent nie robi zadań, a użytkownicy nie widzą przycisku. Zadania możesz dodawać już teraz. Próba na jednym koncie bez włączania: <code>php cli/cloudPimImport.php --upn adres --run --force</code>.
    <?php endif; ?>
    <button type="submit" class="btn small<?= $enabled ? ' ghost' : '' ?>"><?= $enabled ? 'Wyłącz' : 'Włącz' ?></button>
  </form>
  <ul>
    <li>Microsoft 365 (Calendars.Read, Contacts.Read): <?= $exportOk ? 'skonfigurowany' : '<strong>brak konfiguracji</strong>' ?></li>
    <li>Import do chmury na tym serwerze: <?= $importOk ? 'dostępny' : '<strong>niedostępny</strong>: ' . h($diag ?: 'brak konfiguracji chmury') ?></li>
    <li>Zadania: <?php $parts = []; foreach (PimImport::LABELS as $k => $l) if (!empty($counts[$k])) $parts[] = h($l) . ' ' . (int)$counts[$k]; echo $parts ? implode(', ', $parts) : 'brak'; ?></li>
  </ul>

  <h2>Zleć</h2>
  <div class="row">
    <form method="post" action="<?= h($base . '/zlec') ?>" onsubmit="return confirm('Dodać zadania dla wszystkich aktywnych kont Microsoft 365?');">
      <?= Router::csrfField() ?><input type="hidden" name="scope" value="all">
      <button type="submit" class="btn"<?= $exportOk ? '' : ' disabled' ?>>Wszystkie konta Microsoft 365</button>
    </form>
    <form method="post" action="<?= h($base . '/zlec') ?>" class="row">
      <?= Router::csrfField() ?>
      <label for="pimUpn">Jedno konto</label>
      <input type="email" id="pimUpn" name="upn" required placeholder="imie.nazwisko@feer.org.pl" autocomplete="off">
      <button type="submit" class="btn ghost"<?= $exportOk ? '' : ' disabled' ?>>Dodaj</button>
    </form>
  </div>
  <p class="mute">Agent CRON (cloud_pim) robi zadania co 5 minut. Od razu: <code>php cli/cloudPimImport.php --run</code>.</p>

  <h2>Zadania</h2>
  <?php if (!$jobs): ?><p>Nie ma jeszcze zadań.</p><?php else: ?>
  <div class="scroll"><table class="tbl">
    <caption class="mute" style="text-align:left">Kopia: kalendarze, wydarzenia, kontakty z Microsoft 365. Import: ile trafiło do chmury.</caption>
    <thead><tr><th scope="col">Konto Microsoft</th><th scope="col">Chmura</th><th scope="col">Stan</th><th scope="col">Kopia</th><th scope="col">Import</th><th scope="col">Uwagi</th><th scope="col">Działania</th></tr></thead>
    <tbody>
    <?php foreach ($jobs as $j): ?>
      <tr>
        <td><?= h($j['upn']) ?><div class="mute">#<?= (int)$j['id'] ?>, <?= h($dt($j['updated_at'])) ?></div></td>
        <td><?= h($j['cloud_uid'] ?: '') ?></td>
        <td class="st-<?= h($j['status']) ?>"><?= h(PimImport::LABELS[$j['status']] ?? $j['status']) ?><div class="mute"><?= $j['phase'] === 'export' ? 'kopia' : ($j['phase'] === 'import' ? 'import' : '') ?></div></td>
        <td><?= $j['exported_at'] ? (int)$j['calendars'] . ' kal., ' . (int)$j['events'] . ' wyd., ' . (int)$j['contacts'] . ' kont.<div class="mute">' . h($dt($j['exported_at'])) . '</div>' : '' ?></td>
        <td><?= $j['imported_at'] ? (int)$j['imported_events'] . ' wyd., ' . (int)$j['imported_contacts'] . ' kont.' . ((int)$j['failed'] ? ', ' . (int)$j['failed'] . ' nieudanych' : '') . '<div class="mute">' . h($dt($j['imported_at'])) . '</div>' : '' ?></td>
        <td><?= h(mb_substr((string)($j['last_error'] ?: ''), 0, 240)) ?></td>
        <td>
          <form method="post" action="<?= h($base . '/zadanie') ?>" class="row" style="gap:4px">
            <?= Router::csrfField() ?><input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
            <?php if (!in_array($j['status'], ['queued', 'running'], true)): ?><button class="btn small ghost" name="op" value="again">Kopia od nowa</button><?php endif; ?>
            <?php if ($j['exported_at'] && !in_array($j['status'], ['queued', 'running'], true)): ?><button class="btn small ghost" name="op" value="reimport">Importuj ponownie</button><?php endif; ?>
            <?php if (in_array($j['status'], PimImport::ACTIVE, true)): ?><button class="btn small ghost" name="op" value="cancel">Anuluj</button><?php endif; ?>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</main></div>
</body>
</html>

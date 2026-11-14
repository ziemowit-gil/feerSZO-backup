<?php
/**
 * modules/sso_logto/view/groupsSection.php — sekcja „Grupy w chmurze z SZO” (strona /ssoadmin/grupy, view/groups.php): opis rodzajów grup,
 * liczby, wynik synchronizacji, przyciski Podgląd / Synchronizuj, przycisk okna „Problemy z przypisaniami”. Ustawia $tmIssues dla
 * view/assignIssuesModal.php. Przeniesiona z /ssoadmin (polecenie 2026-11-14: zarządzanie grupami tylko dla Koordynatorów IT).
 */
?>
<?php $tmRes = SsoController::takeResult('zespoly'); $tmTeams = CloudTeamsSync::teams(); $tmWs = CloudTeamsSync::workspaces(); $tmAct = CloudTeamsSync::actions(); $tmUnits = CloudTeamsSync::units();
      $tmKind = ['zespół' => 'Zespół', 'obszar' => 'Obszar', 'działanie' => 'Działanie', 'jednostka' => 'Jednostka'];
      $tmCnt = fn(array $l) => count($l) . ' (aktywnych: ' . count(array_filter($l, fn($t) => $t['active'])) . ')';
      require_once dirname(__DIR__) . '/logic/CloudAssignIssues.php';
      $tmIssues = CloudAssignIssues::collect(); $tmIssuesN = CloudAssignIssues::total($tmIssues); $tmIgnored = CloudAssignIssues::ignoredList(); ?>
<section class="card mb-4" id="zespoly" aria-labelledby="ssoTeamsH">
  <div class="card-header"><h2 id="ssoTeamsH" class="h6 mb-0"><i class="bi bi-people" aria-hidden="true"></i> Grupy w chmurze z SZO: zespoły, obszary, działania, jednostki</h2></div>
  <div class="card-body small">
    <?php if ($tmRes !== null): $tmDry = !empty($tmRes['dry']); ?>
      <div class="alert alert-<?= $tmRes['error'] !== '' ? 'danger' : ($tmDry ? 'info' : 'success') ?>" role="status" tabindex="-1" id="ssoTeamsResult">
        <p class="fw-semibold mb-2"><?= $tmRes['error'] !== '' ? 'Nie udało się połączyć z chmurą.' : ($tmDry ? 'Podgląd, nic nie zmieniono.' : 'Gotowe.') ?>
          <?= h(CloudTeamsSync::summary($tmRes)) ?></p>
        <?php if ($tmRes['teams']): ?>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
          <caption class="visually-hidden">Wynik synchronizacji grup w chmurze: zespoły, obszary robocze, działania i jednostki organizacyjne</caption>
          <thead><tr><th scope="col">Skąd w SZO</th><th scope="col">Grupa w chmurze</th><th scope="col"><?= $tmDry ? 'Do dopisania' : 'Dopisani' ?></th><th scope="col"><?= $tmDry ? 'Do wypisania' : 'Wypisani' ?></th><th scope="col">Bez konta w chmurze</th></tr></thead>
          <tbody>
          <?php foreach ($tmRes['teams'] as $r): ?>
            <tr>
              <td><span class="text-body-secondary"><?= h($tmKind[$r['kind'] ?? 'zespół'] ?? '') ?>:</span> <?= h($r['name']) ?><?= !$r['active'] ? ' <span class="badge bg-secondary">wyłączony</span>' : '' ?></td>
              <td><code><?= h($r['gid']) ?></code><br><span class="text-body-secondary"><?= h($r['group']) ?><?= $r['renamed'] ? ', nazwa poprawiona' : '' ?></span>
                <?php if ($r['error'] !== ''): ?><br><span class="text-danger-emphasis"><?= h($r['error']) ?></span><?php endif; ?></td>
              <td><?= $r['added'] ? h(implode(', ', $r['added'])) : '—' ?></td>
              <td><?= $r['removed'] ? h(implode(', ', $r['removed'])) : '—' ?></td>
              <td><?= $r['without'] ? h(implode(', ', $r['without'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <p class="mb-2">Każdy rodzaj ma w chmurze własne grupy. W grupie są tylko osoby, które mają konto w chmurze.
      Dzięki temu udostępnisz plik albo folder całej grupie naraz.</p>
    <ul class="mb-2">
      <li>Zespół z Zadań: grupa <code><?= h(CloudTeamsSync::PREFIX) ?>{numer}</code> z nazwą zespołu, w niej członkowie zespołu. Zespołów: <?= h($tmCnt($tmTeams)) ?>.</li>
      <li>Obszar roboczy Zadań: grupa <code><?= h(CloudTeamsSync::WS_PREFIX) ?>{numer}</code> „Obszar: {nazwa}”, w niej osoby dopisane do obszaru i członkowie jego zespołów. Obszarów: <?= h($tmCnt($tmWs)) ?>.</li>
      <li>Działanie: grupa <code><?= h(CloudTeamsSync::ACTION_PREFIX) ?>{numer}</code> „Działanie: {nazwa}”, w niej koordynator i osoby z aktywnych umów wolontariackich przypisanych do działania. Działań: <?= h($tmCnt($tmAct)) ?>.</li>
      <li>Jednostka organizacyjna: grupa <code><?= h(CloudTeamsSync::UNIT_PREFIX) ?>{numer}</code> „Jednostka: {nazwa}”, w niej użytkownicy jednostki, jej kierownik i osoby z aktywnych umów z tą jednostką. Jednostek: <?= h($tmCnt($tmUnits)) ?>.</li>
    </ul>
    <p class="mb-2">Grupę zakładamy, gdy pierwsza osoba ma do niej trafić. Grupa wyłączonej albo usuniętej pozycji zostaje, ale bez członków, więc udostępnienia nie znikają.
      Działanie zakończone albo anulowane liczy się jak wyłączone. Inne grupy w chmurze zostają bez zmian.
      Synchronizacja chodzi sama co 2 minuty, a przycisk uruchamia ją od razu.</p>
    <?php if ($tmIssuesN > 0): ?>
      <p class="mb-2">Część osób nie trafia do grup przez braki w przypisaniach (konto w chmurze, jednostka albo działanie w umowie, koordynator).
        <button type="button" class="btn btn-warning btn-sm ms-1" data-bs-toggle="modal" data-bs-target="#ssoAssignModal">
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Problemy z przypisaniami: <?= (int)$tmIssuesN ?></button></p>
    <?php elseif ($tmIgnored): ?>
      <p class="mb-2">Nie ma problemów z przypisaniami do poprawienia.
        <button type="button" class="btn btn-outline-secondary btn-sm ms-1" data-bs-toggle="modal" data-bs-target="#ssoAssignModal">Zignorowane ostrzeżenia: <?= count($tmIgnored) ?></button></p>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2">
      <form method="post" action="<?= h(route('sso.teamsSync')) ?>" class="m-0"><?= Router::csrfField() ?><input type="hidden" name="do" value="preview">
        <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye" aria-hidden="true"></i> Podgląd</button></form>
      <form method="post" action="<?= h(route('sso.teamsSync')) ?>" class="m-0"><?= Router::csrfField() ?><input type="hidden" name="do" value="apply">
        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Synchronizuj grupy</button></form>
    </div>
  </div>
</section>
<?php if ($tmRes !== null): ?><script>document.getElementById('ssoTeamsResult')?.focus();</script><?php endif; ?>

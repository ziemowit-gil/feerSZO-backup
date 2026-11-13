<?php
/**
 * modules/sso_logto/view/_jobs.php — lista zadań kopiowania plików OneDrive → chmura (migrator i ustawienia SSO).
 * Zmienne: $jobsBack ('/migracjaadmin' | '/ssoadmin'); lista z CloudFilesCopy::jobs(), gdy nie podano $ssoJobs.
 */
require_once dirname(__DIR__) . '/logic/CloudFilesCopy.php';
$ssoJobs = $ssoJobs ?? CloudFilesCopy::jobs(50);
$ssoJobUrl = rtrim(APP_URL, '/') . ($jobsBack === '/migracjaadmin' ? '/migracjaadmin/pliki/zadanie' : '/ssoadmin/pliki');
$ssoMb = fn(int $b) => $b >= 1073741824 ? number_format($b / 1073741824, 1, ',', ' ') . ' GB' : number_format($b / 1048576, 1, ',', ' ') . ' MB';
?>
<?php if (!$ssoJobs): ?>
  <p class="mb-0 text-body-secondary">Brak zadań kopiowania plików.</p>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th scope="col">Konto w chmurze</th><th scope="col">Źródło (OneDrive)</th><th scope="col">Stan</th><th scope="col">Pliki</th><th scope="col">Dane</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
      <tbody>
      <?php foreach ($ssoJobs as $j): $active = in_array($j['status'], CloudFilesCopy::ACTIVE, true); ?>
        <tr>
          <td><code><?= h($j['cloud_uid']) ?></code><?php if ($j['email'] !== ''): ?><br><span class="text-body-secondary"><?= h($j['email']) ?></span><?php endif; ?></td>
          <td><?= h($j['source']) ?></td>
          <td>
            <?= h(CloudFilesCopy::LABELS[$j['status']] ?? $j['status']) ?><?= $active ? ' · ' . CloudFilesCopy::percent($j) . '%' : '' ?>
            <?php if ($j['status'] === 'waiting' && $j['next_run_at']): ?><br><span class="text-body-secondary">ponowienie <?= h(date('d.m H:i', strtotime((string)$j['next_run_at']))) ?></span><?php endif; ?>
            <?php if (!empty($j['last_error'])): ?><br><span class="text-danger-emphasis"><?= h(mb_substr((string)$j['last_error'], 0, 200)) ?></span><?php endif; ?>
          </td>
          <td><?= (int)$j['copied_files'] ?> z <?= (int)$j['total_files'] ?><?= (int)$j['skipped_files'] ? ', pominięte ' . (int)$j['skipped_files'] : '' ?><?= (int)$j['failed_files'] ? ', nieudane ' . (int)$j['failed_files'] : '' ?></td>
          <td><?= h($ssoMb((int)$j['bytes'])) ?></td>
          <td class="text-end text-nowrap">
            <?php if ($active): ?>
              <form method="post" action="<?= h($ssoJobUrl) ?>" class="d-inline"><input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="job" value="<?= (int)$j['id'] ?>"><input type="hidden" name="back" value="<?= h($jobsBack) ?>">
                <?php if ($j['status'] === 'waiting'): ?><button type="submit" name="do" value="soon" class="btn btn-link btn-sm p-0 me-2">Ponów teraz<span class="visually-hidden"> — <?= h($j['cloud_uid']) ?></span></button><?php endif; ?>
                <button type="submit" name="do" value="cancel" class="btn btn-link btn-sm p-0 text-danger" onclick="return confirm('Anulować kopiowanie plików? Skopiowane pliki zostają w chmurze.')">Anuluj<span class="visually-hidden"> — <?= h($j['cloud_uid']) ?></span></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php
/**
 * modules/backup_remote/view/card.php — karta „Kopia na serwer zdalny (SFTP / rsync)” w admin/backups.php:
 * stan wysyłek, przyciski „Wyślij teraz”, ustawienia, klucz SSH, klucz hosta i test połączenia.
 */
require_once dirname(__DIR__) . '/logic/backupRemoteActions.php';

$_brView  = backupRemoteViewState();
$_brSaved = backupRemoteConfig();
$_brCfg   = $_brView['form'] ?? $_brSaved;
$_brErr   = $_brView['errors'] ?? [];
$_brTest  = $_brView['test'] ?? null;
$_brSt    = backupRemoteStatus();
$_brTools = backupRemoteTools();
$_brPin   = backupRemotePinned($_brCfg);
$_brPub   = $_brCfg['auth'] === 'key' ? backupRemotePublicKey((string)$_brCfg['key_path']) : '';
$_brLast  = $_brSt['runs'][0] ?? null;
$_brLastOk = backupRemoteLastOkTs();
$_brHasPw = (string)$_brSaved['password_enc'] !== '';
$_brOpen  = !empty($_brView['open']) || !$_brSaved['enabled'] || $_brSaved['host'] === '';
$_brKeyDefault = backupRemoteKeyDefaultPath();
$_brKeyExists  = is_file($_brKeyDefault);

/** aria-describedby: komunikat błędu (jeśli jest) + opis pomocniczy. */
$_brDesc = function (string $f, string $help = '') use ($_brErr): string {
    $ids = [];
    if (isset($_brErr[$f])) $ids[] = 'br-' . $f . '-err';
    if ($help !== '') $ids[] = $help;
    $a = $ids ? ' aria-describedby="' . h(implode(' ', $ids)) . '"' : '';
    return $a . (isset($_brErr[$f]) ? ' aria-invalid="true"' : '');
};
$_brInv = fn(string $f): string => isset($_brErr[$f]) ? ' is-invalid' : '';
$_brMsg = fn(string $f): string => isset($_brErr[$f])
    ? '<div class="invalid-feedback d-block" id="br-' . h($f) . '-err">' . h($_brErr[$f]) . '</div>' : '';

if (!$_brSaved['enabled'])          { $_brBadge = ['secondary', 'Wyłączona']; }
elseif ($_brLast && !$_brLast['ok']) { $_brBadge = ['danger', 'Błąd ostatniej wysyłki']; }
elseif ($_brLastOk)                  { $_brBadge = ['success', 'Działa']; }
else                                 { $_brBadge = ['warning', 'Czeka na pierwszą wysyłkę']; }
$_brChan = ['incremental' => 'przyrostowa', 'full' => 'pełna'];
$_brTrig = ['cron' => 'CRON', 'panel' => 'panel'];
?>
<div class="card shadow-sm mb-3 border-<?= h($_brBadge[0]) ?>" id="backup-remote">
  <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h2 class="h6 fw-semibold mb-0"><i class="bi bi-hdd-network me-1 text-primary" aria-hidden="true"></i>Kopia na serwer zdalny (SFTP / rsync)</h2>
    <span class="badge text-bg-<?= h($_brBadge[0]) ?>"><?= h($_brBadge[1]) ?></span>
  </div>
  <div class="card-body small">
    <p class="mb-2">Drugi cel kopii poza serwerem, obok SharePointa i niezależnie od niego: kopie lokalne (baza, uploads, certs) trafiają też na Twój serwer
      przez SFTP albo rsync po SSH. Co godzinę wysyłane są nowe kopie lokalne, a <?= $_brSaved['full_every'] === 7 ? 'co 7 dni' : 'co noc' ?> — pełna kopia całego systemu.
      Na serwerze: <code><?= h($_brSaved['path'] ?: 'katalog') ?>/incremental/RRRR-MM/</code> i <code><?= h($_brSaved['path'] ?: 'katalog') ?>/full/RRRR-MM/</code>.</p>

    <?php if ($_brSaved['enabled'] && !backup_encryption_enabled()): ?>
    <div class="alert alert-warning py-2 mb-2" role="note"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
      <strong>Szyfrowanie kopii jest wyłączone</strong> — na serwer zdalny trafiają niezaszyfrowane dane osobowe. Włącz je w karcie „Szyfrowanie kopii (AES-256)”.</div>
    <?php endif; ?>

    <?php if ($_brSaved['enabled']): ?>
    <div class="row g-3 mb-2">
      <div class="col-6 col-lg-3">
        <div class="text-body-secondary">Ostatnia udana wysyłka</div>
        <div class="fw-semibold"><?= $_brLastOk ? h(date('d.m.Y H:i', $_brLastOk)) . ' <span class="text-body-secondary fw-normal">(' . round((time() - $_brLastOk) / 3600, 1) . ' h temu)</span>' : '—' ?></div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="text-body-secondary">Ostatnia pełna kopia</div>
        <div class="fw-semibold"><?= (int)$_brSt['last_full'] ? h(date('d.m.Y H:i', (int)$_brSt['last_full'])) : 'jeszcze nie było' ?></div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="text-body-secondary">Ostatni przebieg</div>
        <div class="fw-semibold">
          <?php if (!$_brLast): ?>—
          <?php elseif ($_brLast['ok']): ?><span class="text-success"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> OK</span>, <?= h(date('d.m.Y H:i', (int)$_brLast['ts'])) ?>
          <?php else: ?><span class="text-danger"><i class="bi bi-x-circle-fill" aria-hidden="true"></i> Błąd</span>, <?= h(date('d.m.Y H:i', (int)$_brLast['ts'])) ?><?php endif; ?>
        </div>
      </div>
      <div class="col-6 col-lg-3">
        <div class="text-body-secondary">Ostatnie sprawdzenie nowych kopii</div>
        <div class="fw-semibold"><?= (int)$_brSt['last_check'] ? h(date('d.m.Y H:i', (int)$_brSt['last_check'])) : '—' ?></div>
      </div>
    </div>
    <?php if ($_brLast && !$_brLast['ok']): ?>
    <div class="alert alert-danger py-2 mb-2" role="alert"><strong>Ostatnia wysyłka (<?= h($_brChan[$_brLast['channel']] ?? $_brLast['channel']) ?>) nie powiodła się:</strong> <?= h($_brLast['error'] ?? '') ?></div>
    <?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <form method="post" class="d-inline" onsubmit="return confirm('Wysłać teraz nowe kopie na serwer zdalny?<?= (int)$_brSt['last_full'] ? '' : ' To będzie pierwsza wysyłka — pełna kopia całego systemu, może potrwać.' ?>')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button class="btn btn-sm btn-primary" name="_action" value="remote_send"><i class="bi bi-cloud-upload me-1" aria-hidden="true"></i>Wyślij teraz</button>
      </form>
      <form method="post" class="d-inline" onsubmit="return confirm('Zbudować i wysłać teraz pełną kopię (baza, cały katalog uploads, certs)? Może potrwać kilka minut.')">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <button class="btn btn-sm btn-outline-primary" name="_action" value="remote_send_full"><i class="bi bi-boxes me-1" aria-hidden="true"></i>Wyślij pełną kopię teraz</button>
      </form>
    </div>
    <?php if ($_brSt['runs']): ?>
    <details class="mb-2">
      <summary class="text-body-secondary" style="cursor:pointer">Historia wysyłek (ostatnie <?= count($_brSt['runs']) ?>)</summary>
      <div class="table-responsive mt-2">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Historia wysyłek kopii na serwer zdalny</caption>
          <thead class="table-light"><tr><th scope="col">Koniec</th><th scope="col">Rodzaj</th><th scope="col">Wynik</th><th scope="col">Pliki</th><th scope="col">Rozmiar</th><th scope="col">Czas</th><th scope="col">Szczegóły</th></tr></thead>
          <tbody>
          <?php foreach ($_brSt['runs'] as $r): ?>
            <tr>
              <td class="text-nowrap"><?= h(date('d.m.Y H:i', (int)$r['ts'])) ?></td>
              <td><?= h(($_brChan[$r['channel']] ?? $r['channel']) . ' · ' . strtoupper((string)$r['method']) . ' · ' . ($_brTrig[$r['trigger']] ?? $r['trigger'])) ?></td>
              <td><?= $r['ok'] ? '<span class="badge text-bg-success">OK</span>' : '<span class="badge text-bg-danger">Błąd</span>' ?></td>
              <td><?= (int)$r['files'] ?></td>
              <td class="text-nowrap"><?= h(backupRemoteHumanSize((int)$r['bytes'])) ?></td>
              <td class="text-nowrap"><?= h(number_format((float)$r['duration'], 1, ',', '')) ?> s</td>
              <td class="text-body-secondary"><?= h($r['error'] ?? ($r['note'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($_brTest): ?>
    <div class="border rounded p-2 mb-2 border-<?= $_brTest['ok'] ? 'success' : 'danger' ?>" role="status" aria-live="polite">
      <div class="fw-semibold mb-1"><?= $_brTest['ok'] ? '<i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i> Test połączenia: OK' : '<i class="bi bi-x-circle-fill text-danger" aria-hidden="true"></i> Test połączenia: błąd' ?></div>
      <ol class="mb-0 ps-3">
        <?php foreach ($_brTest['steps'] as $s): ?>
        <li><span class="<?= $s['ok'] ? 'text-success' : 'text-danger' ?>"><?= $s['ok'] ? 'OK' : 'BŁĄD' ?></span> — <strong><?= h($s['label']) ?></strong><?= $s['detail'] !== '' ? ': ' . h($s['detail']) : '' ?></li>
        <?php endforeach; ?>
      </ol>
    </div>
    <?php elseif (!empty($_brSt['last_test'])): $lt = $_brSt['last_test']; ?>
    <p class="text-body-secondary mb-2">Ostatni test połączenia: <?= h(date('d.m.Y H:i', (int)$lt['ts'])) ?> — <?= !empty($lt['ok']) ? 'OK' : 'błąd: ' . h($lt['detail'] ?? '') ?></p>
    <?php endif; ?>

    <details<?= $_brOpen ? ' open' : '' ?>>
      <summary class="fw-semibold" style="cursor:pointer">Ustawienia serwera</summary>
      <form method="post" class="mt-2" novalidate>
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="br-enabled" name="br_enabled" value="1"<?= $_brCfg['enabled'] ? ' checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="br-enabled">Wysyłaj kopie na serwer zdalny</label>
        </div>

        <fieldset class="mb-3"<?= isset($_brErr['method']) ? ' aria-describedby="br-method-err"' : '' ?>>
          <legend class="form-label fs-6 mb-1">Metoda</legend>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="br_method" id="br-method-sftp" value="sftp"<?= $_brCfg['method'] === 'sftp' ? ' checked' : '' ?>>
            <label class="form-check-label" for="br-method-sftp">SFTP <span class="text-body-secondary">(zalecane, działa bez dodatkowych programów)</span></label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="br_method" id="br-method-rsync" value="rsync"<?= $_brCfg['method'] === 'rsync' ? ' checked' : '' ?>>
            <label class="form-check-label" for="br-method-rsync">rsync po SSH <span class="text-body-secondary">(<?= $_brTools['rsync'] ? 'rsync i ssh dostępne na tym serwerze' : 'niedostępne: brak programu rsync lub ssh na tym serwerze' ?>)</span></label>
          </div>
          <?= $_brMsg('method') ?>
        </fieldset>

        <div class="row g-2 mb-2">
          <div class="col-md-5">
            <label class="form-label mb-1" for="br-host">Adres serwera</label>
            <input type="text" class="form-control form-control-sm<?= $_brInv('host') ?>" id="br-host" name="br_host" value="<?= h($_brCfg['host']) ?>" placeholder="backup.example.org" autocomplete="off" spellcheck="false"<?= $_brDesc('host') ?>>
            <?= $_brMsg('host') ?>
          </div>
          <div class="col-md-2">
            <label class="form-label mb-1" for="br-port">Port</label>
            <input type="number" class="form-control form-control-sm<?= $_brInv('port') ?>" id="br-port" name="br_port" value="<?= (int)$_brCfg['port'] ?>" min="1" max="65535" inputmode="numeric"<?= $_brDesc('port') ?>>
            <?= $_brMsg('port') ?>
          </div>
          <div class="col-md-5">
            <label class="form-label mb-1" for="br-user">Użytkownik</label>
            <input type="text" class="form-control form-control-sm<?= $_brInv('user') ?>" id="br-user" name="br_user" value="<?= h($_brCfg['user']) ?>" autocomplete="off" spellcheck="false"<?= $_brDesc('user') ?>>
            <?= $_brMsg('user') ?>
          </div>
          <div class="col-12">
            <label class="form-label mb-1" for="br-path">Katalog docelowy na serwerze</label>
            <input type="text" class="form-control form-control-sm font-monospace<?= $_brInv('path') ?>" id="br-path" name="br_path" value="<?= h($_brCfg['path']) ?>" placeholder="kopie/feerszo" spellcheck="false"<?= $_brDesc('path', 'br-path-help') ?>>
            <div class="form-text" id="br-path-help">Ścieżka bez „/” na początku liczy się od katalogu domowego użytkownika. Katalog zostanie utworzony, jeśli go nie ma.</div>
            <?= $_brMsg('path') ?>
          </div>
        </div>

        <fieldset class="mb-2">
          <legend class="form-label fs-6 mb-1">Uwierzytelnianie</legend>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="br_auth" id="br-auth-key" value="key"<?= $_brCfg['auth'] === 'key' ? ' checked' : '' ?>>
            <label class="form-check-label" for="br-auth-key">Klucz SSH <span class="text-body-secondary">(zalecane)</span></label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="br_auth" id="br-auth-password" value="password"<?= $_brCfg['auth'] === 'password' ? ' checked' : '' ?>>
            <label class="form-check-label" for="br-auth-password">Hasło</label>
          </div>
        </fieldset>

        <div class="mb-2" data-br-auth="key"<?= $_brCfg['auth'] === 'key' ? '' : ' hidden' ?>>
          <label class="form-label mb-1" for="br-key">Ścieżka do klucza prywatnego na tym serwerze</label>
          <input type="text" class="form-control form-control-sm font-monospace<?= $_brInv('key_path') ?>" id="br-key" name="br_key_path" value="<?= h($_brCfg['key_path']) ?>" placeholder="<?= h($_brKeyDefault) ?>" spellcheck="false"<?= $_brDesc('key_path', 'br-key-help') ?>>
          <div class="form-text" id="br-key-help">Klucz bez hasła, czytelny dla użytkownika serwera WWW i CRON (uprawnienia 600). Możesz też wygenerować klucz przyciskiem poniżej — trafi do <code>certs/backup_remote/</code>.</div>
          <?= $_brMsg('key_path') ?>
          <?php if ($_brPub !== ''): ?>
          <label class="form-label mb-1 mt-2" for="br-pub">Klucz publiczny — dopisz go na serwerze do <code>~/.ssh/authorized_keys</code></label>
          <div class="input-group input-group-sm">
            <input type="text" class="form-control font-monospace" id="br-pub" value="<?= h($_brPub) ?>" readonly>
            <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('br-pub').value)"><i class="bi bi-clipboard" aria-hidden="true"></i> Kopiuj</button>
          </div>
          <?php endif; ?>
        </div>

        <div class="mb-2" data-br-auth="password"<?= $_brCfg['auth'] === 'password' ? '' : ' hidden' ?>>
          <label class="form-label mb-1" for="br-password">Hasło</label>
          <input type="password" class="form-control form-control-sm<?= $_brInv('password') ?>" id="br-password" name="br_password" value="" autocomplete="new-password" style="max-width:360px"
                 placeholder="<?= $_brHasPw ? 'zapisane — zostaw puste, aby nie zmieniać' : '' ?>"<?= $_brDesc('password', 'br-password-help') ?>>
          <div class="form-text" id="br-password-help">Zapisywane zaszyfrowane. Przy rsync wymaga OpenSSH 8.4 lub nowszego; bezpieczniejszy jest klucz.</div>
          <?= $_brMsg('password') ?>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-md-4">
            <label class="form-label mb-1" for="br-full">Pełna kopia na serwerze</label>
            <select class="form-select form-select-sm" id="br-full" name="br_full_every">
              <option value="1"<?= $_brCfg['full_every'] === 1 ? ' selected' : '' ?>>co noc</option>
              <option value="7"<?= $_brCfg['full_every'] === 7 ? ' selected' : '' ?>>co 7 dni (w nocy)</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label mb-1" for="br-ret">Usuwaj z serwera kopie starsze niż (dni)</label>
            <input type="number" class="form-control form-control-sm" id="br-ret" name="br_retention_days" value="<?= (int)$_brCfg['retention_days'] ?>" min="0" max="3650" inputmode="numeric" aria-describedby="br-ret-help">
            <div class="form-text" id="br-ret-help">0 = nie usuwaj. Zawsze zostają 3 najnowsze kopie każdego rodzaju.</div>
          </div>
        </div>

        <div class="mb-3">
          <div class="form-label mb-1">Klucz hosta serwera</div>
          <?php if ($_brPin): ?>
            <code><?= h(explode(' ', (string)$_brPin['key'])[0]) ?> <?= h(backupRemoteFingerprint((string)$_brPin['key'])) ?></code>
            <span class="text-body-secondary">— zapamiętany <?= h(date('d.m.Y H:i', (int)($_brPin['ts'] ?? 0))) ?>; połączenie z innym kluczem zostanie przerwane.</span>
          <?php else: ?>
            <span class="text-body-secondary">Jeszcze niezapamiętany — zostanie zapamiętany przy pierwszym połączeniu (test albo wysyłka). Porównaj odcisk z kluczem serwera (<code>ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub</code>).</span>
          <?php endif; ?>
        </div>

        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-sm btn-primary" name="_action" value="remote_save"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz</button>
          <button class="btn btn-sm btn-outline-primary" name="_action" value="remote_test"><i class="bi bi-plug me-1" aria-hidden="true"></i>Test połączenia</button>
          <button class="btn btn-sm btn-outline-secondary" name="_action" value="remote_genkey" formnovalidate
                  onclick="return confirm('<?= $_brKeyExists ? 'Wygenerować NOWY klucz SSH? Dotychczasowy przestanie działać na serwerze, dopóki nie dopiszesz nowego klucza publicznego. Niezapisane zmiany w formularzu przepadną.' : 'Wygenerować klucz SSH dla kopii zdalnej? Niezapisane zmiany w formularzu przepadną.' ?>')">
            <i class="bi bi-key me-1" aria-hidden="true"></i><?= $_brKeyExists ? 'Wygeneruj nowy klucz SSH' : 'Wygeneruj klucz SSH' ?></button>
          <?php if ($_brPin): ?>
          <button class="btn btn-sm btn-outline-danger" name="_action" value="remote_forget_hostkey" formnovalidate
                  onclick="return confirm('Zapomnieć klucz hosta? Użyj tylko, gdy serwer celowo wymieniono. Następne połączenie zapamięta nowy klucz.')"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Zapomnij klucz hosta</button>
          <?php endif; ?>
        </div>
        <p class="text-body-secondary mt-2 mb-0">„Test połączenia” sprawdza wartości z formularza bez zapisywania ich: łączy się, zapamiętuje klucz hosta, loguje i zapisuje plik testowy w katalogu docelowym.</p>
      </form>
    </details>
  </div>
</div>
<script>
document.querySelectorAll('input[name="br_auth"]').forEach(function (r) {
  r.addEventListener('change', function () {
    document.querySelectorAll('[data-br-auth]').forEach(function (box) { box.hidden = box.getAttribute('data-br-auth') !== r.value; });
  });
});
</script>

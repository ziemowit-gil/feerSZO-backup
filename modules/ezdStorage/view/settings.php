<?php
/**
 * modules/ezdStorage/view/settings.php — Kopie dokumentów EZD (FTP + Nextcloud), administrator.
 * Zmienne: $flash, $old, $ftp, $ftpReady, $ftpPaused, $ncReady, $ncUser, $ncRoot, $ncPaused, $cloudUrl, $cloudReady, $stats, $sp (EzdSpMigrator::stats), $spBase.
 */
$PAGE_TITLE = 'Kopie dokumentów EZD (FTP + Nextcloud)';
include dirname(__DIR__, 3) . '/includes/header.php';

$errors = (array)($old['errors'] ?? []);
$val = function (string $k, $default) use ($old) {
    if (is_array($old) && array_key_exists('values', $old)) {
        if (in_array($k, ['enabled', 'passive'], true)) return !empty($old['values'][$k]);
        return (string)($old['values'][$k] ?? '');
    }
    return $default;
};
$field = function (string $k) use ($errors): string {
    return isset($errors[$k]) ? ' is-invalid" aria-invalid="true" aria-describedby="ezs-' . $k . '-help ezs-' . $k . '-err' : '" aria-describedby="ezs-' . $k . '-help';
};
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="ezs-' . $k . '-err">' . h($errors[$k]) . '</div>' : '';
$labels = ['host' => 'Adres serwera', 'port' => 'Port', 'user' => 'Użytkownik', 'pass' => 'Hasło', 'dir' => 'Katalog na serwerze'];
$pill = fn(bool $ok, string $yes, string $no) => '<span class="ezs-b ' . ($ok ? 'ezs-b--ok' : 'ezs-b--off') . '">' . h($ok ? $yes : $no) . '</span>';
?>
<style>
  .ezs-wrap { max-width: 980px; }
  .ezs-wrap :focus-visible { outline: 3px solid #1d2939; outline-offset: 2px; }
  .ezs-wrap .btn { min-height: 44px; display: inline-flex; align-items: center; gap: .35rem; }
  .ezs-b { display: inline-block; padding: .15rem .55rem; border-radius: 999px; font-size: .8rem; font-weight: 600; }
  .ezs-b--ok  { background: #dcfae6; color: #085d3a; }
  .ezs-b--off { background: #f2f4f7; color: #344054; }
  .ezs-b--warn { background: #fef0c7; color: #7a2e0e; }
  .ezs-imp { border: 2px solid #b54708; background: #fffaeb; color: #1d2939; border-radius: .5rem; padding: .9rem 1rem; }
  .ezs-imp h2 { font-size: 1rem; font-weight: 700; color: #7a2e0e; margin: 0 0 .35rem; }
  .ezs-imp ul { margin: 0; padding-left: 1.2rem; }
  .ezs-num { font-size: 1.6rem; font-weight: 700; color: #101828; line-height: 1.1; }
  .ezs-wrap .form-text { color: #475467; }
</style>

<div class="ezs-wrap">
  <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-cloud-arrow-up text-primary me-2" aria-hidden="true"></i>Kopie dokumentów EZD (FTP + Nextcloud)</h1>
    <a href="<?= h(APP_URL) ?>/admin/ezd_settings.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-arrow-left" aria-hidden="true"></i>Ustawienia Wirtualnego biurka</a>
  </div>

  <?php if ($flash): ?>
    <div class="alert <?= $flash['ok'] ? 'alert-success' : 'alert-danger' ?>" role="<?= $flash['ok'] ? 'status' : 'alert' ?>"><?= h($flash['msg']) ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert alert-danger" role="alert">
      <p class="fw-semibold mb-1">Popraw pola formularza FTP:</p>
      <ul class="mb-0">
        <?php foreach ($errors as $k => $m): ?><li><a href="#ezs-<?= h($k) ?>"><?= h($labels[$k] ?? $k) ?></a>: <?= h($m) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="ezs-imp mb-4">
    <h2>Ważne</h2>
    <ul>
      <li>Oryginał każdego dokumentu zostaje na serwerze SZO. Po każdym zapisie (wgranie, nowa wersja, podpis, zaświadczenie, Spinacz, szablon, skan z dziennika podawczego) kopia idzie do chmury na konto <strong><?= h($ncUser ?: EzdStorage::NC_UID) ?></strong> i na serwer FTP. Zastępuje to dawną kopię na SharePoincie.</li>
      <li>Gdy FTP nie działa, kopia idzie tylko do chmury. Użytkownik nie widzi błędu, a zdarzenie trafia do dziennika (audit_logs: <code>ezd.store.*</code>). Zaległe kopie agent CRON dosyła co 10 minut. Robi to też z dokumentami sprzed włączenia kopii, od najnowszych.</li>
      <li>Układ folderów jest taki sam jak dawniej na SharePoincie (<code>cases/{JRWA}/{koszulka}/</code>). Pliki z SharePointa przenosi migracja niżej, ze ścieżkami bez zmian. Starsze wersje pliku o tej samej nazwie mają w nazwie dopisek <code>[id]</code>.</li>
      <li>Gdy na serwerze zabraknie pliku, pobieranie odtworzy go z kopii w chmurze albo z FTP.</li>
      <li>„Otwórz w Office Online” i „Konwertuj na PDF” nadal korzystają z SharePointa (Word Online). Przestaną działać po wyłączeniu Microsoft 365.</li>
    </ul>
  </div>

  <!-- Stan -->
  <section class="card shadow-sm mb-4" id="stan" aria-labelledby="ezs-h-stan">
    <div class="card-header"><h2 class="h6 fw-semibold mb-0" id="ezs-h-stan"><i class="bi bi-graph-up me-2 text-primary" aria-hidden="true"></i>Stan kopii</h2></div>
    <div class="card-body">
      <div class="row g-3 mb-3 text-center">
        <div class="col-6 col-md-3"><div class="ezs-num"><?= (int)$stats['total'] ?></div><div class="small">plików w EZD</div></div>
        <div class="col-6 col-md-3"><div class="ezs-num"><?= (int)$stats['nc'] ?></div><div class="small">z kopią w chmurze</div></div>
        <div class="col-6 col-md-3"><div class="ezs-num"><?= (int)$stats['ftp'] ?></div><div class="small">z kopią na FTP</div></div>
        <div class="col-6 col-md-3"><div class="ezs-num"><?= (int)$stats['nc_err'] + (int)$stats['ftp_err'] + (int)$stats['missing'] ?></div><div class="small">z błędem</div></div>
      </div>
      <form method="post" action="<?= h(route('ezdStore.sync')) ?>" class="d-flex flex-wrap gap-2 align-items-center">
        <?= Router::csrfField() ?>
        <button type="submit" class="btn btn-primary" <?= !$ncReady && !$ftpReady ? 'disabled aria-describedby="ezs-sync-help"' : '' ?>><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Wyślij zaległe teraz (do 50 plików)</button>
        <div class="form-check ms-1">
          <input class="form-check-input" type="checkbox" name="all" value="1" id="ezs-all">
          <label class="form-check-label" for="ezs-all">Także pliki po 5 nieudanych próbach</label>
        </div>
        <?php if (!$ncReady && !$ftpReady): ?><div class="form-text w-100" id="ezs-sync-help">Najpierw skonfiguruj chmurę albo FTP.</div><?php endif; ?>
      </form>
      <?php if ($stats['errors']): ?>
        <h3 class="h6 fw-semibold mt-4">Ostatnie błędy</h3>
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0">
            <caption class="visually-hidden">Ostatnie pliki bez kopii z powodu błędu</caption>
            <thead><tr><th scope="col">Plik</th><th scope="col">Chmura</th><th scope="col">FTP</th><th scope="col">Kiedy</th></tr></thead>
            <tbody>
            <?php foreach ($stats['errors'] as $e): ?>
              <tr>
                <td><a href="<?= h(APP_URL) ?>/ezd/sprawy/view.php?id=<?= (int)$e['sprawa_id'] ?>"><?= h($e['original_name'] ?: ('#' . $e['zal_id'])) ?></a><?= $e['local_missing'] ? ' <span class="ezs-b ezs-b--warn">brak na dysku</span>' : '' ?></td>
                <td class="small"><?= h($e['nc_error'] ?: '—') ?></td>
                <td class="small"><?= h($e['ftp_error'] ?: '—') ?></td>
                <td class="small text-nowrap"><?= h((string)$e['updated_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Migracja z SharePointa -->
  <section class="card shadow-sm mb-4" id="sharepoint" aria-labelledby="ezs-h-sp">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <h2 class="h6 fw-semibold mb-0" id="ezs-h-sp"><i class="bi bi-box-arrow-in-down me-2 text-primary" aria-hidden="true"></i>Migracja z SharePointa (ścieżki bez zmian)</h2>
      <span class="ms-auto"><?= !$sp['enabled'] ? '<span class="ezs-b ezs-b--off">wyłączona</span>'
        : ($sp['paused'] ? '<span class="ezs-b ezs-b--warn">SharePoint niedostępny — przerwa do ' . h(date('H:i', $sp['paused'])) . '</span>'
        : $pill($sp['configured'] && ($ncReady || $ftpReady), 'działa', 'czeka na konfigurację')) ?></span>
    </div>
    <div class="card-body">
      <p>Pliki EZD, które mają kopię na SharePoincie, trafiają do chmury i na FTP pod tę samą ścieżkę co w bibliotece:
        <code><?= h($spBase !== '' ? $spBase : '(korzeń biblioteki)') ?>/cases/…</code> → <code><?= h($ncRoot) ?>/cases/…</code>.
        Gdy pliku brakuje na serwerze, migracja odtworzy go z SharePointa. Gdy treść na serwerze jest inna (nowsza wersja w EZD),
        pod tą ścieżką ląduje wersja z serwera.</p>
      <div class="row g-3 mb-3 text-center">
        <div class="col-6 col-md-3"><div class="ezs-num"><?= (int)$sp['all'] ?></div><div class="small">plików z kopią na SharePoincie</div></div>
        <div class="col-6 col-md-3"><div class="ezs-num"><?= (int)$sp['moved'] ?></div><div class="small">przeniesionych</div></div>
        <div class="col-6 col-md-3"><div class="ezs-num"><?= (int)$sp['left'] ?></div><div class="small">czeka</div></div>
        <div class="col-6 col-md-3"><div class="ezs-num"><?= (int)$sp['missing'] + (int)$sp['errors'] ?></div><div class="small">brak na SharePoincie / błąd</div></div>
      </div>
      <?php if (!$sp['configured']): ?>
        <p class="mb-0">Microsoft 365 nie jest skonfigurowany — pliki idą zwykłą kopią z dysku serwera.</p>
      <?php else: ?>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <?php if ($sp['enabled']): ?>
            <form method="post" action="<?= h(route('ezdStore.sharepoint')) ?>" class="d-flex flex-wrap gap-2 align-items-center">
              <?= Router::csrfField() ?><input type="hidden" name="action" value="run">
              <button type="submit" class="btn btn-primary" <?= !$ncReady && !$ftpReady ? 'disabled' : '' ?>><i class="bi bi-arrow-down-circle" aria-hidden="true"></i>Przenieś porcję teraz (do 50 plików)</button>
              <div class="form-check ms-1">
                <input class="form-check-input" type="checkbox" name="all" value="1" id="ezs-sp-all">
                <label class="form-check-label" for="ezs-sp-all">Także pliki po 5 nieudanych próbach</label>
              </div>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= h(route('ezdStore.sharepoint')) ?>" <?= $sp['enabled'] ? 'onsubmit="return confirm(\'Wyłączyć migrację z SharePointa? Pliki pójdą zwykłą kopią z dysku serwera, ścieżki mogą się różnić od tych w bibliotece.\');"' : '' ?>>
            <?= Router::csrfField() ?><input type="hidden" name="action" value="<?= $sp['enabled'] ? 'off' : 'on' ?>">
            <button type="submit" class="btn <?= $sp['enabled'] ? 'btn-outline-secondary' : 'btn-primary' ?>"><i class="bi bi-power" aria-hidden="true"></i><?= $sp['enabled'] ? 'Wyłącz migrację' : 'Włącz migrację' ?></button>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- Chmura -->
  <section class="card shadow-sm mb-4" id="chmura" aria-labelledby="ezs-h-nc">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <h2 class="h6 fw-semibold mb-0" id="ezs-h-nc"><i class="bi bi-cloud me-2 text-primary" aria-hidden="true"></i>Chmura Nextcloud — konto <?= h($ncUser ?: EzdStorage::NC_UID) ?></h2>
      <span class="ms-auto"><?= $ncPaused ? '<span class="ezs-b ezs-b--warn">przerwa do ' . h(date('H:i', $ncPaused)) . '</span>' : $pill($ncReady, 'działa', 'nie skonfigurowane') ?></span>
    </div>
    <div class="card-body">
      <?php if (!$cloudReady): ?>
        <p class="mb-0">Chmura plików nie jest skonfigurowana. Najpierw wpisz jej adres i konto API w <a href="<?= h(APP_URL) ?>/ssoadmin#chmura">Logowaniu jednokrotnym i chmurze</a>.</p>
      <?php else: ?>
        <dl class="row mb-3">
          <dt class="col-sm-4">Adres chmury</dt><dd class="col-sm-8"><?= h($cloudUrl) ?></dd>
          <dt class="col-sm-4">Konto na kopie</dt><dd class="col-sm-8"><?= $ncReady ? h($ncUser) : 'brak — załóż je przyciskiem niżej' ?></dd>
          <dt class="col-sm-4">Folder kopii</dt><dd class="col-sm-8"><?= h($ncRoot) ?>/{symbol JRWA}/{koszulka}/</dd>
        </dl>
        <p class="small mb-3">Konto istnieje tylko w chmurze (bez konta w SZO). Zakłada je konto API SZO, hasło aplikacji jest zapisane w SZO zaszyfrowane i nigdzie się nie wyświetla.</p>
        <div class="d-flex flex-wrap gap-2">
          <?php if (!$ncReady): ?>
            <form method="post" action="<?= h(route('ezdStore.account')) ?>">
              <?= Router::csrfField() ?><input type="hidden" name="action" value="create">
              <button type="submit" class="btn btn-primary"><i class="bi bi-person-plus" aria-hidden="true"></i>Załóż konto <?= h(EzdStorage::NC_UID) ?> w chmurze</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?= h(route('ezdStore.test')) ?>">
              <?= Router::csrfField() ?><input type="hidden" name="target" value="nc">
              <button type="submit" class="btn btn-outline-primary"><i class="bi bi-plug" aria-hidden="true"></i>Sprawdź chmurę</button>
            </form>
            <form method="post" action="<?= h(route('ezdStore.account')) ?>">
              <?= Router::csrfField() ?><input type="hidden" name="action" value="create">
              <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-key" aria-hidden="true"></i>Nadaj nowe hasło aplikacji</button>
            </form>
            <form method="post" action="<?= h(route('ezdStore.account')) ?>" onsubmit="return confirm('Odłączyć konto chmury? SZO przestanie wysyłać kopie do chmury. Konto i pliki w chmurze zostaną.');">
              <?= Router::csrfField() ?><input type="hidden" name="action" value="forget">
              <button type="submit" class="btn btn-outline-danger"><i class="bi bi-x-circle" aria-hidden="true"></i>Odłącz konto</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- FTP -->
  <section class="card shadow-sm mb-4" id="ftp" aria-labelledby="ezs-h-ftp">
    <div class="card-header d-flex flex-wrap align-items-center gap-2">
      <h2 class="h6 fw-semibold mb-0" id="ezs-h-ftp"><i class="bi bi-hdd-network me-2 text-primary" aria-hidden="true"></i>Serwer FTP</h2>
      <span class="ms-auto"><?= $ftpPaused ? '<span class="ezs-b ezs-b--warn">niedostępny — przerwa do ' . h(date('H:i', $ftpPaused)) . '</span>' : $pill($ftpReady, 'włączony', 'wyłączony') ?></span>
    </div>
    <div class="card-body">
      <p>Bez FTP kopie idą tylko do chmury. Dane serwera dostaniesz od jego administratora. Hasło zapisujemy zaszyfrowane i nie wyświetlamy go.</p>
      <form method="post" action="<?= h(route('ezdStore.ftp')) ?>" novalidate>
        <?= Router::csrfField() ?>
        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="ezs-enabled" name="enabled" value="1" <?= $val('enabled', $ftp['enabled']) ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="ezs-enabled">Wysyłaj kopie na FTP</label>
        </div>
        <div class="row g-3">
          <div class="col-md-8">
            <label class="form-label fw-semibold" for="ezs-host">Adres serwera</label>
            <input type="text" class="form-control<?= $field('host') ?>" id="ezs-host" name="host" value="<?= h($val('host', $ftp['host'])) ?>" autocomplete="off" spellcheck="false" inputmode="url">
            <div class="form-text" id="ezs-host-help">Sama nazwa albo adres IP, np. ftp.example.pl — bez ftp:// i ścieżki.</div>
            <?= $err('host') ?>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold" for="ezs-port">Port</label>
            <input type="number" class="form-control<?= $field('port') ?>" id="ezs-port" name="port" min="1" max="65535" value="<?= h((string)$val('port', (string)$ftp['port'])) ?>">
            <div class="form-text" id="ezs-port-help">Zwykle 21 (FTPS niejawne: 990).</div>
            <?= $err('port') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" for="ezs-user">Użytkownik</label>
            <input type="text" class="form-control<?= $field('user') ?>" id="ezs-user" name="user" value="<?= h($val('user', $ftp['user'])) ?>" autocomplete="off" spellcheck="false">
            <div class="form-text" id="ezs-user-help">Konto FTP z prawem zapisu w katalogu niżej.</div>
            <?= $err('user') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" for="ezs-pass">Hasło</label>
            <input type="password" class="form-control<?= $field('pass') ?>" id="ezs-pass" name="pass" value="" autocomplete="new-password">
            <div class="form-text" id="ezs-pass-help"><?= $ftp['has_pass'] ? 'Hasło jest zapisane. Zostaw puste, aby go nie zmieniać.' : 'Hasła jeszcze nie zapisano.' ?></div>
            <?= $err('pass') ?>
            <?php if ($ftp['has_pass']): ?>
              <div class="form-check mt-1">
                <input class="form-check-input" type="checkbox" name="pass_clear" value="1" id="ezs-pass-clear">
                <label class="form-check-label" for="ezs-pass-clear">Usuń zapisane hasło</label>
              </div>
            <?php endif; ?>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" for="ezs-dir">Katalog na serwerze</label>
            <input type="text" class="form-control<?= $field('dir') ?>" id="ezs-dir" name="dir" value="<?= h($val('dir', $ftp['dir'])) ?>" spellcheck="false">
            <div class="form-text" id="ezs-dir-help">Z „/” na początku — ścieżka od korzenia serwera, bez „/” — od katalogu domowego. Brakujące katalogi powstaną same.</div>
            <?= $err('dir') ?>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold" for="ezs-tls">Szyfrowanie</label>
            <select class="form-select" id="ezs-tls" name="tls" aria-describedby="ezs-tls-help">
              <?php foreach (EzdFtp::TLS_MODES as $k => $lbl): ?>
                <option value="<?= h($k) ?>" <?= $val('tls', $ftp['tls']) === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" id="ezs-tls-help">Zalecane FTPS jawne. Zwykły FTP przesyła hasło i dokumenty bez szyfrowania.</div>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="passive" value="1" id="ezs-passive" <?= $val('passive', $ftp['passive']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="ezs-passive">Tryb pasywny (zalecany; wyłącz tylko na prośbę administratora serwera)</label>
            </div>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-3">
          <button type="submit" class="btn btn-primary"><i class="bi bi-save" aria-hidden="true"></i>Zapisz ustawienia FTP</button>
        </div>
      </form>
      <?php if ($ftp['host'] !== ''): ?>
        <form method="post" action="<?= h(route('ezdStore.test')) ?>" class="mt-2">
          <?= Router::csrfField() ?><input type="hidden" name="target" value="ftp">
          <button type="submit" class="btn btn-outline-primary"><i class="bi bi-plug" aria-hidden="true"></i>Sprawdź FTP (zapis, odczyt i usunięcie pliku testowego)</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</div>
<?php include dirname(__DIR__, 3) . '/includes/footer.php'; ?>

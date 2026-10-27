<?php
/**
 * @var bool $enabled @var bool $configured @var string $base @var int $defaultAccount
 * @var list<array> $accounts konta telefoniczne ze Spikon @var ?string $remoteError
 * @var list<array> $users @var array<int,int> $map user_id => phone_account_id
 * @var list<array> $books powiązane książki @var list<array> $groups grupy CRM bez książki
 */
$acctLabel = static function (array $a): string {
    $l = $a['number'] !== '' ? $a['number'] : ('konto #' . $a['id']);
    if ($a['description'] !== '' && $a['description'] !== 'Brak') $l .= ' — ' . $a['description'];
    if ($a['internal'] !== '' && $a['internal'] !== '-') $l .= ' (wew. ' . $a['internal'] . ')';
    return $l;
};
?>
<div class="d-flex align-items-center gap-3 mb-3">
  <div class="rounded-circle bg-primary bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0"><i class="bi bi-telephone-outbound-fill text-primary fs-4" aria-hidden="true"></i></div>
  <div>
    <h1 class="h4 mb-0">Telefonia Spikon</h1>
    <div class="text-muted small">Książki telefoniczne z grup CRM i wybieranie numeru z karty kontaktu. Dokumentacja API Spikon 1.3.1.</div>
  </div>
</div>
<?= flash_html() ?>

<section class="card shadow-sm mb-4" aria-labelledby="spk-h-conn">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="spk-h-conn">Połączenie z API</h2>
    <p class="small text-muted mb-3">Klucz API znajdziesz w panelu Spikon: <em>Ustawienia i dodatki → API - ustawienia</em> (domyślnie API jest wyłączone).
      Jeśli na białej liście adresów IP są wpisy, dopisz adres serwera SZO — inaczej Spikon odpowie błędem „IP address not whitelisted”.</p>
    <form method="post" action="<?= h(route('spikon.settings')) ?>" class="row g-3">
      <?= Router::csrfField() ?>
      <div class="col-12 col-lg-6">
        <label for="spk-key" class="form-label fw-semibold">Klucz API</label>
        <input type="password" id="spk-key" name="spikon_api_key" class="form-control" autocomplete="off" aria-describedby="spk-key-help"
               placeholder="<?= $configured ? '•••••••• (zapisany — wpisz, żeby zmienić)' : 'Wklej klucz API' ?>">
        <div id="spk-key-help" class="form-text">
          <?= $configured ? 'Klucz jest zapisany i nie jest wyświetlany.' : 'Klucz nie jest jeszcze zapisany.' ?>
          <?php if ($configured): ?>
            <span class="ms-2"><input type="checkbox" class="form-check-input" id="spk-clear" name="clear_key" value="1"> <label for="spk-clear">usuń zapisany klucz</label></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-12 col-lg-6">
        <label for="spk-base" class="form-label fw-semibold">Adres API <span class="fw-normal text-muted">(opcjonalnie)</span></label>
        <input type="text" id="spk-base" name="spikon_api_base" class="form-control" value="<?= h($base) ?>" placeholder="https://panel.spikon.pl" aria-describedby="spk-base-help">
        <div id="spk-base-help" class="form-text">Puste = https://panel.spikon.pl. Zmieniaj tylko do testów na atrapie bramki.</div>
      </div>
      <div class="col-12 col-lg-6">
        <label for="spk-def" class="form-label fw-semibold">Domyślne konto telefoniczne</label>
        <select id="spk-def" name="spikon_default_account_id" class="form-select" aria-describedby="spk-def-help">
          <option value="0">— brak (tylko konta przypisane osobom) —</option>
          <?php foreach ($accounts as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= $defaultAccount === (int)$a['id'] ? 'selected' : '' ?>><?= h($acctLabel($a)) ?></option>
          <?php endforeach; ?>
        </select>
        <div id="spk-def-help" class="form-text">Używane, gdy użytkownik nie ma własnego przypisania poniżej.</div>
      </div>
      <div class="col-12 col-lg-6 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="spk-on" name="spikon_enabled" value="1" <?= $enabled ? 'checked' : '' ?>>
          <label class="form-check-label fw-semibold" for="spk-on">Włącz telefonię Spikon w CRM</label>
          <div class="form-text">Pokazuje przycisk „Zadzwoń przez Spikon” na karcie kontaktu.</div>
        </div>
      </div>
      <div class="col-12 d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz</button>
      </div>
    </form>
    <?php if ($configured): ?>
    <form method="post" action="<?= h(route('spikon.test')) ?>" class="mt-2">
      <?= Router::csrfField() ?>
      <button type="submit" class="btn btn-outline-secondary btn-sm"><i class="bi bi-plug me-1" aria-hidden="true"></i>Sprawdź połączenie (saldo i konta)</button>
    </form>
    <?php endif; ?>
    <?php if ($remoteError): ?>
      <div class="alert alert-warning mt-3 mb-0 py-2" role="alert"><strong>Spikon nie odpowiada poprawnie:</strong> <?= h($remoteError) ?></div>
    <?php endif; ?>
  </div>
</section>

<?php if ($configured && !$remoteError): ?>
<section class="card shadow-sm mb-4" aria-labelledby="spk-h-acc">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="spk-h-acc">Konta telefoniczne użytkowników</h2>
    <p class="small text-muted">Spikon najpierw dzwoni na przypisane konto (telefon SIP albo numer wewnętrzny), a po odebraniu łączy z kontaktem.
      Osoba bez przypisania korzysta z konta domyślnego; bez żadnego konta przycisk zgłosi brak konta.</p>
    <form method="post" action="<?= h(route('spikon.accounts')) ?>">
      <?= Router::csrfField() ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead><tr><th scope="col">Użytkownik</th><th scope="col">Konto telefoniczne Spikon</th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): $uid = (int)$u['id']; ?>
            <tr>
              <th scope="row" class="fw-normal"><label for="spk-u-<?= $uid ?>"><?= h($u['name']) ?></label></th>
              <td>
                <select id="spk-u-<?= $uid ?>" name="account[<?= $uid ?>]" class="form-select form-select-sm">
                  <option value="0">— konto domyślne —</option>
                  <?php foreach ($accounts as $a): ?>
                    <option value="<?= (int)$a['id'] ?>" <?= ($map[$uid] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>><?= h($acctLabel($a)) ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz przypisania</button>
    </form>
  </div>
</section>
<?php endif; ?>

<section class="card shadow-sm mb-4" aria-labelledby="spk-h-books">
  <div class="card-body">
    <h2 class="h6 fw-bold" id="spk-h-books">Książki telefoniczne</h2>
    <p class="small text-muted">Książka powstaje z <strong>grupy CRM</strong>: trafia do niej główny numer telefonu każdego aktywnego kontaktu z grupy.
      Do Spikon wysyłane są tylko kontakty z grup, które tu powiążesz (imię i nazwisko, organizacja, numer). Kontakt usunięty z grupy albo ze zmienionym numerem
      jest aktualizowany przy następnej synchronizacji.</p>

    <?php if (!$books): ?>
      <p class="text-muted mb-3">Żadna grupa nie jest jeszcze powiązana z książką.</p>
    <?php else: ?>
    <div class="table-responsive mb-3">
      <table class="table align-middle">
        <thead><tr><th scope="col">Grupa CRM</th><th scope="col">Książka w Spikon</th><th scope="col">Wpisów</th><th scope="col">Ostatnia synchronizacja</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
        <tbody>
        <?php foreach ($books as $b): $bid = (int)$b['id']; ?>
          <tr>
            <th scope="row" class="fw-semibold"><?= h($b['group_name']) ?><?= $b['group_exists'] ? '' : ' <span class="badge bg-warning text-dark">grupa usunięta</span>' ?></th>
            <td><?= h($b['name']) ?></td>
            <td><?= (int)$b['entries'] ?></td>
            <td class="small">
              <?php if ($b['last_sync_at']): ?>
                <?= h($b['last_sync_at']) ?> — +<?= (int)$b['last_sync_added'] ?> / −<?= (int)$b['last_sync_removed'] ?>
                <?php if ($b['last_sync_error']): ?><div class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><span class="visually-hidden">Błąd:</span><?= h($b['last_sync_error']) ?></div><?php endif; ?>
              <?php else: ?><span class="text-muted">jeszcze nie</span><?php endif; ?>
            </td>
            <td class="text-end text-nowrap">
              <form method="post" action="<?= h(route('spikon.sync', ['id' => $bid])) ?>" class="d-inline">
                <?= Router::csrfField() ?>
                <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Synchronizuj<span class="visually-hidden"> książkę <?= h($b['name']) ?></span></button>
              </form>
              <form method="post" action="<?= h(route('spikon.unlink', ['id' => $bid])) ?>" class="d-inline"
                    onsubmit="return confirm('Odpiąć grupę od książki?' + (this.delete_remote.checked ? ' Książka zostanie skasowana także w Spikon.' : ''));">
                <?= Router::csrfField() ?>
                <span class="form-check form-check-inline ms-2 mb-0">
                  <input class="form-check-input" type="checkbox" name="delete_remote" value="1" id="spk-del-<?= $bid ?>">
                  <label class="form-check-label small" for="spk-del-<?= $bid ?>">skasuj też w Spikon</label>
                </span>
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Odepnij<span class="visually-hidden"> książkę <?= h($b['name']) ?></span></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if ($configured && !$remoteError): ?>
    <form method="post" action="<?= h(route('spikon.link')) ?>" class="row g-2 align-items-end">
      <?= Router::csrfField() ?>
      <div class="col-12 col-md-7 col-lg-6">
        <label for="spk-grp" class="form-label fw-semibold">Załóż książkę z grupy CRM</label>
        <select id="spk-grp" name="group_id" class="form-select" required <?= $groups ? '' : 'disabled' ?>>
          <option value="">— wybierz grupę —</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?> (z numerem: <?= (int)$g['with_phone'] ?> z <?= (int)$g['members'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-primary" <?= $groups ? '' : 'disabled' ?>><i class="bi bi-journal-plus me-1" aria-hidden="true"></i>Załóż i zsynchronizuj</button>
      </div>
      <?php if (!$groups): ?><div class="col-12 form-text">Wszystkie grupy CRM mają już książki (albo nie ma jeszcze żadnej grupy).</div><?php endif; ?>
    </form>
    <?php else: ?>
      <p class="small text-muted mb-0">Zapisz poprawny klucz API, żeby zakładać książki.</p>
    <?php endif; ?>
  </div>
</section>

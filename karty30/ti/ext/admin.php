<?php
/**
 * karty30/ti/ext/admin.php — wydawcy, kategorie, licencje i uprawnienia.
 *
 * Cztery rejestry w jednym miejscu, bo to jedna czynność: „ustaw, kto co może".
 * Rozbicie na cztery ekrany kończy się tym, że grant nadaje się bez licencji
 * albo licencję bez miejsc — a wtedy dostęp nie działa i nikt nie wie czemu.
 */
require_once __DIR__ . '/_boot.php';

$EXT_SUBJECT = ext_require_manager($EXT_SUBJECT);
$uid = (int)$EXT_SUBJECT['id'];
$tab = in_array($_GET['tab'] ?? '', ['wydawcy','kategorie','licencje','uprawnienia'], true)
     ? $_GET['tab'] : 'wydawcy';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? '';

    if ($op === 'module_settings') {
        org_setting_set('ext_enabled', !empty($_POST['ext_enabled']) ? '1' : '0');
        org_setting_set('ext_notify_email', trim((string)($_POST['ext_notify_email'] ?? '')));
        flash_set('success', 'Ustawienia modułu zapisane.');
        header('Location: admin.php?tab=wydawcy'); exit;
    }

    if ($op === 'publisher_save') {
        $rules = json_encode([
            'allow_download'   => !empty($_POST['allow_download']) ? 1 : 0,
            'allow_print'      => !empty($_POST['allow_print']) ? 1 : 0,
            'watermark_policy' => in_array($_POST['watermark_policy'] ?? '', ['none','footer','overlay','both'], true)
                                  ? $_POST['watermark_policy'] : 'both',
        ]);
        $row = [
            'name'          => trim((string)($_POST['name'] ?? '')),
            'contract_no'   => trim((string)($_POST['contract_no'] ?? '')),
            'contract_from' => ($_POST['contract_from'] ?? '') ?: null,
            'contract_to'   => ($_POST['contract_to'] ?? '') ?: null,
            'contact'       => trim((string)($_POST['contact'] ?? '')),
            'default_rules' => $rules,
            'is_active'     => !empty($_POST['is_active']) ? 1 : 0,
        ];
        $id = (int)($_POST['id'] ?? 0);
        if ($row['name'] === '') { flash_set('danger', 'Podaj nazwę wydawcy.'); }
        elseif ($id) { db_update('k30_ext_publishers', $row, $id); flash_set('success', 'Wydawca zaktualizowany.'); }
        else {
            $row['slug'] = substr(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($row['name'])), 0, 40)
                         . '-' . bin2hex(random_bytes(2));
            db_insert('k30_ext_publishers', $row);
            flash_set('success', 'Wydawca dodany.');
        }
        header('Location: admin.php?tab=wydawcy'); exit;
    }

    if ($op === 'category_save') {
        if (trim((string)($_POST['name'] ?? '')) === '') flash_set('danger', 'Podaj nazwę kategorii.');
        else { ext_category_save($_POST, (int)($_POST['id'] ?? 0) ?: null); flash_set('success', 'Kategoria zapisana.'); }
        header('Location: admin.php?tab=kategorie'); exit;
    }

    if ($op === 'license_save') {
        $row = [
            'publisher_id'  => (int)($_POST['publisher_id'] ?? 0),
            'kind'          => in_array($_POST['kind'] ?? '', ['institutional','group','individual','trial'], true)
                               ? $_POST['kind'] : 'institutional',
            'name'          => trim((string)($_POST['name'] ?? '')),
            'valid_from'    => (string)($_POST['valid_from'] ?? ''),
            'valid_to'      => (string)($_POST['valid_to'] ?? ''),
            'seats'         => ($_POST['seats'] ?? '') !== '' ? (int)$_POST['seats'] : null,
            'max_downloads' => ($_POST['max_downloads'] ?? '') !== '' ? (int)$_POST['max_downloads'] : null,
            'note'          => trim((string)($_POST['note'] ?? '')),
            'is_active'     => !empty($_POST['is_active']) ? 1 : 0,
        ];
        if (!$row['publisher_id'] || $row['name'] === '' || $row['valid_from'] === '' || $row['valid_to'] === '') {
            flash_set('danger', 'Wydawca, nazwa i obie daty są wymagane.');
        } elseif ($row['valid_to'] < $row['valid_from']) {
            flash_set('danger', 'Data „do" musi być późniejsza niż „od".');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id) { db_update('k30_ext_licenses', $row, $id); flash_set('success', 'Licencja zaktualizowana.'); }
            else     { db_insert('k30_ext_licenses', $row);      flash_set('success', 'Licencja dodana.'); }
        }
        header('Location: admin.php?tab=licencje'); exit;
    }

    if ($op === 'seat_add') {
        db_insert('k30_ext_license_seats', [
            'license_id'   => (int)($_POST['license_id'] ?? 0),
            'subject_type' => ($_POST['subject_type'] ?? 'user') === 'student' ? 'student' : 'user',
            'subject_id'   => (int)($_POST['subject_id'] ?? 0),
        ]);
        flash_set('success', 'Miejsce w licencji przydzielone.');
        header('Location: admin.php?tab=licencje'); exit;
    }

    if ($op === 'seat_release') {
        db_exec("UPDATE k30_ext_license_seats SET released_at=datetime('now') WHERE id=?", [(int)($_POST['id'] ?? 0)]);
        flash_set('success', 'Miejsce zwolnione.');
        header('Location: admin.php?tab=licencje'); exit;
    }

    if ($op === 'grant_save') {
        [$stype, $sid] = array_pad(explode(':', (string)($_POST['scope'] ?? '')), 2, '');
        $abilities = array_values(array_intersect(
            (array)($_POST['abilities'] ?? []), ['view','stream','download','print']
        ));
        if (!in_array($stype, ['category','publisher','title','edition','resource'], true) || !(int)$sid) {
            flash_set('danger', 'Wskaż, czego dotyczy uprawnienie.');
        } elseif (!$abilities) {
            flash_set('danger', 'Zaznacz przynajmniej jedną czynność.');
        } else {
            db_insert('k30_ext_grants', [
                'scope_type'   => $stype,
                'scope_id'     => (int)$sid,
                'subject_type' => (string)($_POST['subject_type'] ?? 'all'),
                'subject_id'   => (int)($_POST['subject_id'] ?? 0),
                'effect'       => ($_POST['effect'] ?? 'allow') === 'deny' ? 'deny' : 'allow',
                'abilities'    => implode(',', $abilities),
                'starts_at'    => ext_dt((string)($_POST['starts_at'] ?? '')),
                'ends_at'      => ext_dt((string)($_POST['ends_at'] ?? '')),
                'note'         => trim((string)($_POST['note'] ?? '')),
                'created_by'   => $uid,
            ]);
            flash_set('success', 'Uprawnienie zapisane.');
        }
        header('Location: admin.php?tab=uprawnienia'); exit;
    }

    if ($op === 'grant_delete') {
        db_exec("DELETE FROM k30_ext_grants WHERE id=?", [(int)($_POST['id'] ?? 0)]);
        flash_set('success', 'Uprawnienie usunięte.');
        header('Location: admin.php?tab=uprawnienia'); exit;
    }
}

$pubs = ext_publishers(false);
$cats = ext_categories();

$EXT_TITLE = 'Wydawcy i dostęp';
$EXT_TAB   = 'ustawienia';
include __DIR__ . '/_head.php';
?>

<h1 class="h4 fw-bold mb-3"><i class="bi bi-key text-primary me-2" aria-hidden="true"></i>Wydawcy i dostęp</h1>

<nav class="skin-subnav mb-3" aria-label="Rejestry modułu">
  <?php foreach (['wydawcy' => 'Wydawcy', 'kategorie' => 'Kategorie',
                  'licencje' => 'Licencje', 'uprawnienia' => 'Uprawnienia'] as $k => $lbl): ?>
  <a href="admin.php?tab=<?= h($k) ?>" <?= $tab === $k ? 'class="active" aria-current="page"' : '' ?>><?= h($lbl) ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'wydawcy'): $edit = ext_publisher_get((int)($_GET['edit'] ?? 0)); ?>
<?php /* Włącznik stoi przy wydawcach, bo to pierwszy ekran modułu — administrator
         widzi od razu, czy moduł w ogóle działa dla użytkowników. */ ?>
<form method="post" class="card mb-3">
  <div class="card-body d-flex flex-wrap align-items-end gap-3">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="module_settings">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="m-on" name="ext_enabled" value="1" <?= ext_enabled() ? 'checked' : '' ?>>
      <label class="form-check-label fw-semibold" for="m-on">Moduł włączony dla użytkowników</label>
      <div class="form-text">Po wyłączeniu katalog zostaje, ale żaden plik się nie otworzy.</div>
    </div>
    <div>
      <label class="form-label" for="m-mail">Adres do ostrzeżeń o licencjach</label>
      <input type="email" class="form-control" id="m-mail" name="ext_notify_email"
             value="<?= h(org_setting('ext_notify_email')) ?>" placeholder="sekretariat@…">
    </div>
    <button class="btn btn-primary btn-sm">Zapisz</button>
  </div>
</form>
<div class="table-responsive mb-3">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Wydawcy i domyślne reguły ich materiałów</caption>
    <thead><tr><th scope="col">Wydawca</th><th scope="col">Umowa</th><th scope="col">Domyślne reguły</th><th scope="col">Stan</th><th scope="col"></th></tr></thead>
    <tbody>
      <?php foreach ($pubs as $p): $r = ext_json_decode($p['default_rules']); ?>
      <tr>
        <th scope="row"><?= h($p['name']) ?></th>
        <td class="small"><?= h($p['contract_no']) ?><?php if ($p['contract_to']): ?><br><span class="text-body-secondary">do <?= h($p['contract_to']) ?></span><?php endif; ?></td>
        <td class="small">
          pobieranie: <?= !empty($r['allow_download']) ? 'tak' : 'nie' ?>,
          druk: <?= !empty($r['allow_print']) ? 'tak' : 'nie' ?>,
          znak wodny: <?= h($r['watermark_policy'] ?? 'both') ?>
        </td>
        <td><?= $p['is_active'] ? 'aktywny' : 'wyłączony' ?></td>
        <td class="text-end"><a href="admin.php?tab=wydawcy&amp;edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary">Edytuj</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$pubs): ?><tr><td colspan="5" class="text-center text-body-secondary py-3">Brak wydawców.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<form method="post" class="card">
  <div class="card-header"><?= $edit ? 'Edytuj wydawcę' : 'Nowy wydawca' ?></div>
  <div class="card-body row g-3">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="publisher_save">
    <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="col-12 col-md-4">
      <label class="form-label" for="p-name">Nazwa <span class="text-danger" aria-hidden="true">*</span></label>
      <input class="form-control" id="p-name" name="name" required value="<?= h($edit['name'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label" for="p-cno">Numer umowy</label>
      <input class="form-control" id="p-cno" name="contract_no" value="<?= h($edit['contract_no'] ?? '') ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label" for="p-cto">Umowa do</label>
      <input type="date" class="form-control" id="p-cto" name="contract_to" value="<?= h($edit['contract_to'] ?? '') ?>">
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label" for="p-contact">Kontakt</label>
      <input class="form-control" id="p-contact" name="contact" value="<?= h($edit['contact'] ?? '') ?>">
    </div>
    <?php $r = ext_json_decode($edit['default_rules'] ?? ''); ?>
    <div class="col-12 col-md-4">
      <label class="form-label" for="p-wm">Domyślny znak wodny</label>
      <select class="form-select" id="p-wm" name="watermark_policy">
        <?php foreach (['both' => 'Nadruk i stopka', 'overlay' => 'Sam nadruk',
                        'footer' => 'Sama stopka', 'none' => 'Bez znaku'] as $k => $l): ?>
        <option value="<?= h($k) ?>" <?= ($r['watermark_policy'] ?? 'both') === $k ? 'selected' : '' ?>><?= h($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-8 d-flex align-items-end gap-3 flex-wrap">
      <div class="form-check"><input class="form-check-input" type="checkbox" id="p-dl" name="allow_download" value="1" <?= !empty($r['allow_download']) ? 'checked' : '' ?>><label class="form-check-label" for="p-dl">Wolno pobierać</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="p-pr" name="allow_print" value="1" <?= !empty($r['allow_print']) ? 'checked' : '' ?>><label class="form-check-label" for="p-pr">Wolno drukować</label></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="p-act" name="is_active" value="1" <?= !$edit || $edit['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="p-act">Aktywny</label></div>
    </div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Zapisz wydawcę</button></div>
  </div>
</form>

<?php elseif ($tab === 'kategorie'): ?>
<div class="row g-3">
  <div class="col-12 col-lg-7">
    <ul class="list-group">
      <?php foreach ($cats as $c): ?>
      <li class="list-group-item"><?= str_repeat('· ', (int)$c['depth']) . h($c['name']) ?>
        <span class="text-body-secondary small ms-2"><?= h($c['path']) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$cats): ?><li class="list-group-item text-body-secondary">Brak kategorii.</li><?php endif; ?>
    </ul>
  </div>
  <div class="col-12 col-lg-5">
    <form method="post" class="card">
      <div class="card-header">Nowa kategoria</div>
      <div class="card-body">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="category_save">
        <div class="mb-2">
          <label class="form-label" for="c-name">Nazwa <span class="text-danger" aria-hidden="true">*</span></label>
          <input class="form-control" id="c-name" name="name" required>
        </div>
        <div class="mb-2">
          <label class="form-label" for="c-parent">Nadrzędna</label>
          <select class="form-select" id="c-parent" name="parent_id">
            <option value="0">— najwyższy poziom —</option>
            <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= str_repeat('· ', (int)$c['depth']) . h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Uprawnienie nadane na kategorii obejmuje wszystko poniżej.</div>
        </div>
        <button class="btn btn-primary btn-sm">Dodaj kategorię</button>
      </div>
    </form>
  </div>
</div>

<?php elseif ($tab === 'licencje'):
  $licenses = db_all("SELECT l.*, p.name AS publisher_name
                      FROM k30_ext_licenses l JOIN k30_ext_publishers p ON p.id=l.publisher_id
                      ORDER BY l.valid_to DESC");
?>
<div class="table-responsive mb-3">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Licencje wydawców wraz z miejscami</caption>
    <thead><tr><th scope="col">Licencja</th><th scope="col">Wydawca</th><th scope="col">Ważna</th><th scope="col">Miejsca</th><th scope="col">Limit pobrań</th><th scope="col">Stan</th></tr></thead>
    <tbody>
      <?php foreach ($licenses as $l):
        $seats = db_all("SELECT * FROM k30_ext_license_seats WHERE license_id=? AND released_at IS NULL", [(int)$l['id']]);
        $expiring = $l['valid_to'] < date('Y-m-d', strtotime('+30 days')); ?>
      <tr>
        <th scope="row"><?= h($l['name']) ?><br><span class="fw-normal text-body-secondary small"><?= h($l['kind']) ?></span></th>
        <td><?= h($l['publisher_name']) ?></td>
        <td class="text-nowrap small">
          <?= h($l['valid_from']) ?> → <?= h($l['valid_to']) ?>
          <?php if ($expiring): ?><br><span class="badge text-bg-warning">kończy się wkrótce</span><?php endif; ?>
        </td>
        <td class="small">
          <?= $l['seats'] === null ? 'bez limitu' : count($seats) . ' / ' . (int)$l['seats'] ?>
          <?php foreach ($seats as $s): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op" value="seat_release">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Zwolnij miejsce">
              <?= h($s['subject_type']) ?> #<?= (int)$s['subject_id'] ?> ×
            </button>
          </form>
          <?php endforeach; ?>
        </td>
        <td class="small"><?= $l['max_downloads'] === null ? '—' : (int)$l['max_downloads'] . '/dobę' ?></td>
        <td><?= $l['is_active'] ? 'aktywna' : 'wyłączona' ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$licenses): ?><tr><td colspan="6" class="text-center text-body-secondary py-3">Brak licencji.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-8">
    <form method="post" class="card">
      <div class="card-header">Nowa licencja</div>
      <div class="card-body row g-3">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="license_save">
        <div class="col-12 col-md-4">
          <label class="form-label" for="l-pub">Wydawca <span class="text-danger" aria-hidden="true">*</span></label>
          <select class="form-select" id="l-pub" name="publisher_id" required>
            <?php foreach ($pubs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label" for="l-name">Nazwa <span class="text-danger" aria-hidden="true">*</span></label>
          <input class="form-control" id="l-name" name="name" required placeholder="np. Umowa 2026/1">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label" for="l-kind">Rodzaj</label>
          <select class="form-select" id="l-kind" name="kind">
            <option value="institutional">instytucjonalna</option>
            <option value="group">grupowa</option>
            <option value="individual">indywidualna</option>
            <option value="trial">próbna</option>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="l-from">Od <span class="text-danger" aria-hidden="true">*</span></label>
          <input type="date" class="form-control" id="l-from" name="valid_from" required>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="l-to">Do <span class="text-danger" aria-hidden="true">*</span></label>
          <input type="date" class="form-control" id="l-to" name="valid_to" required>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="l-seats">Miejsca</label>
          <input type="number" class="form-control" id="l-seats" name="seats" min="0" placeholder="puste = bez limitu">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="l-dl">Pobrań na dobę</label>
          <input type="number" class="form-control" id="l-dl" name="max_downloads" min="0" placeholder="puste = bez limitu">
        </div>
        <div class="col-12">
          <div class="form-check"><input class="form-check-input" type="checkbox" id="l-act" name="is_active" value="1" checked><label class="form-check-label" for="l-act">Aktywna</label></div>
        </div>
        <div class="col-12"><button class="btn btn-primary btn-sm">Zapisz licencję</button></div>
      </div>
    </form>
  </div>
  <div class="col-12 col-lg-4">
    <form method="post" class="card">
      <div class="card-header">Przydziel miejsce</div>
      <div class="card-body">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="seat_add">
        <div class="mb-2">
          <label class="form-label" for="s-lic">Licencja</label>
          <select class="form-select" id="s-lic" name="license_id">
            <?php foreach ($licenses as $l): ?><option value="<?= (int)$l['id'] ?>"><?= h($l['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label" for="s-type">Kto</label>
          <select class="form-select" id="s-type" name="subject_type">
            <option value="user">pracownik / prowadzący (users.id)</option>
            <option value="student">kursant (konto panelu)</option>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label" for="s-id">Identyfikator</label>
          <input type="number" class="form-control" id="s-id" name="subject_id" min="1" required>
          <div class="form-text">Licencja bez limitu miejsc obejmuje wszystkich — wtedy nic tu nie trzeba.</div>
        </div>
        <button class="btn btn-primary btn-sm">Przydziel</button>
      </div>
    </form>
  </div>
</div>

<?php else:
  $grants = db_all("SELECT * FROM k30_ext_grants ORDER BY id DESC LIMIT 200");
  $titles = db_all("SELECT id, title FROM k30_ext_titles ORDER BY title COLLATE NOCASE");
?>
<div class="table-responsive mb-3">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Nadane uprawnienia do materiałów</caption>
    <thead><tr><th scope="col">Czego dotyczy</th><th scope="col">Dla kogo</th><th scope="col">Efekt</th><th scope="col">Czynności</th><th scope="col">Okres</th><th scope="col"></th></tr></thead>
    <tbody>
      <?php foreach ($grants as $g): ?>
      <tr>
        <th scope="row" class="small"><?= h($g['scope_type']) ?> #<?= (int)$g['scope_id'] ?></th>
        <td class="small"><?= h($g['subject_type']) ?><?= (int)$g['subject_id'] ? ' #' . (int)$g['subject_id'] : '' ?></td>
        <td><span class="badge text-bg-<?= $g['effect'] === 'deny' ? 'danger' : 'success' ?>"><?= $g['effect'] === 'deny' ? 'zakaz' : 'zezwolenie' ?></span></td>
        <td class="small"><?= h($g['abilities']) ?></td>
        <td class="small text-nowrap"><?= h((string)$g['starts_at']) ?: '—' ?> → <?= h((string)$g['ends_at']) ?: '—' ?></td>
        <td class="text-end">
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć to uprawnienie?')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op" value="grant_delete">
            <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2">Usuń</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$grants): ?><tr><td colspan="6" class="text-center text-body-secondary py-3">Brak uprawnień — dostęp opiera się wyłącznie na licencjach.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<form method="post" class="card">
  <div class="card-header">Nowe uprawnienie</div>
  <div class="card-body row g-3">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="_op" value="grant_save">
    <div class="col-12 col-md-5">
      <label class="form-label" for="g-scope">Czego dotyczy <span class="text-danger" aria-hidden="true">*</span></label>
      <select class="form-select" id="g-scope" name="scope" required>
        <optgroup label="Kategorie (obejmuje wszystko poniżej)">
          <?php foreach ($cats as $c): ?>
          <option value="category:<?= (int)$c['id'] ?>"><?= str_repeat('· ', (int)$c['depth']) . h($c['name']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="Wydawcy">
          <?php foreach ($pubs as $p): ?><option value="publisher:<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
        </optgroup>
        <optgroup label="Tytuły">
          <?php foreach ($titles as $t): ?><option value="title:<?= (int)$t['id'] ?>"><?= h($t['title']) ?></option><?php endforeach; ?>
        </optgroup>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label" for="g-subj">Dla kogo</label>
      <select class="form-select" id="g-subj" name="subject_type">
        <option value="all">wszyscy zalogowani</option>
        <option value="user">pracownik / prowadzący</option>
        <option value="student">kursant</option>
        <option value="course">grupa TI (kurs)</option>
      </select>
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label" for="g-sid">Identyfikator</label>
      <input type="number" class="form-control" id="g-sid" name="subject_id" min="0" value="0">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label" for="g-eff">Efekt</label>
      <select class="form-select" id="g-eff" name="effect">
        <option value="allow">zezwolenie</option>
        <option value="deny">zakaz (wygrywa ze wszystkim)</option>
      </select>
    </div>
    <div class="col-12 col-md-6">
      <fieldset>
        <legend class="form-label">Czynności <span class="text-danger" aria-hidden="true">*</span></legend>
        <?php foreach (['view' => 'podgląd w katalogu', 'stream' => 'czytanie', 'download' => 'pobieranie', 'print' => 'druk'] as $k => $l): ?>
        <div class="form-check form-check-inline">
          <input class="form-check-input" type="checkbox" id="g-<?= h($k) ?>" name="abilities[]" value="<?= h($k) ?>" <?= in_array($k, ['view','stream'], true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="g-<?= h($k) ?>"><?= h($l) ?></label>
        </div>
        <?php endforeach; ?>
      </fieldset>
      <div class="form-text">Zezwolenie nie przebija reguł wydawcy — jeśli zabrania druku, druku nie będzie.</div>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label" for="g-from">Od</label>
      <input type="datetime-local" class="form-control" id="g-from" name="starts_at">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label" for="g-to">Do</label>
      <input type="datetime-local" class="form-control" id="g-to" name="ends_at">
    </div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Zapisz uprawnienie</button></div>
  </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/_foot.php'; ?>

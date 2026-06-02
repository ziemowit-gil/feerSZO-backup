<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/amendments.php';

require_login();

$type = preg_replace('/[^a-z]/', '', $_GET['type'] ?? $_POST['type'] ?? '');
$id   = intval($_GET['id'] ?? $_POST['id'] ?? 0);
$back = APP_URL . "/contracts/{$type}/view.php?id={$id}";

if (!$type || !$id) { header('Location: ' . APP_URL); exit; }

$table = table_for_type($type);
$row   = db_one("SELECT * FROM {$table} WHERE id=?", [$id]);
if (!$row) { flash_set('danger', 'Nie znaleziono umowy.'); header('Location: ' . $back); exit; }

// Definicja pól możliwych do zmiany w aneksie
$field_configs = [
    'data_zakonczenia'         => ['label' => 'Data zakończenia',          'type' => 'date'],
    'termin_oddania'           => ['label' => 'Termin oddania',            'type' => 'date'],
    'data_rozpoczecia'         => ['label' => 'Data rozpoczęcia',          'type' => 'date'],
    'wynagrodzenie_brutto'     => ['label' => 'Wynagrodzenie brutto',      'type' => 'number'],
    'wartosc_dziela'           => ['label' => 'Wartość dzieła',            'type' => 'number'],
    'wynagrodzenie_zasadnicze' => ['label' => 'Wynagrodzenie zasadnicze',  'type' => 'number'],
    'stawka_kwota'             => ['label' => 'Stawka kwota',              'type' => 'number'],
    'liczba_godzin_planowana'  => ['label' => 'Liczba godzin planowana',   'type' => 'text'],
    'stanowisko'               => ['label' => 'Stanowisko',                'type' => 'text'],
    'wymiar_czasu_pracy'       => ['label' => 'Wymiar czasu pracy',        'type' => 'text'],
    'termin_platnosci'         => ['label' => 'Termin płatności',          'type' => 'text'],
    'przedmiot_zlecenia'       => ['label' => 'Przedmiot zlecenia',        'type' => 'textarea'],
    'przedmiot_uslugi'         => ['label' => 'Przedmiot usługi',          'type' => 'textarea'],
    'opis_dziela'              => ['label' => 'Opis dzieła',               'type' => 'textarea'],
    'przedmiot_umowy'          => ['label' => 'Przedmiot umowy',           'type' => 'textarea'],
    'zakres_wolontariatu'      => ['label' => 'Zakres wolontariatu',       'type' => 'textarea'],
    'uwagi'                    => ['label' => 'Uwagi / postanowienia',     'type' => 'textarea'],
    'status'                   => ['label' => 'Status umowy',              'type' => 'select',
        'options' => ['projekt'=>'Projekt','podpisana'=>'Podpisana',
                      'w realizacji'=>'W realizacji','zakończona'=>'Zakończona',
                      'rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana']],
];

$type_fields = [
    'zlecenie'    => ['data_zakonczenia','wynagrodzenie_brutto','stawka_kwota','liczba_godzin_planowana','termin_platnosci','przedmiot_zlecenia','numer_projektu','status','uwagi'],
    'wolontariat' => ['data_zakonczenia','zakres_wolontariatu','numer_projektu','status','uwagi'],
    'dzielo'      => ['termin_oddania','wartosc_dziela','opis_dziela','numer_projektu','status','uwagi'],
    'praca'       => ['data_zakonczenia','data_rozpoczecia','wynagrodzenie_zasadnicze','stanowisko','wymiar_czasu_pracy','status','uwagi'],
    'inne'        => ['data_zakonczenia','przedmiot_umowy','numer_projektu','status','uwagi'],
];

$fields = $type_fields[$type] ?? array_keys($field_configs);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $opis = trim($_POST['opis_zmian'] ?? '');
    if (!$opis) $errors[] = 'Opis / uzasadnienie zmian jest wymagane.';

    $plik = null;
    if (!empty($_FILES['plik_aneksu']['name'])) {
        $plik = handle_upload('plik_aneksu', 'amendments');
        if (!$plik) $errors[] = 'Błąd przesyłania pliku.';
    }

    // Zbuduj listę proponowanych zmian (tylko te, które się różnią)
    $proposed = [];
    foreach ($fields as $field) {
        $key = 'field_' . $field;
        if (!isset($_POST[$key]) || $_POST[$key] === '') continue; // puste = bez zmiany
        $newVal = trim($_POST[$key]);
        $oldVal = (string)($row[$field] ?? '');
        if ($newVal === $oldVal) continue;
        $cfg = $field_configs[$field] ?? ['label' => $field];
        $proposed[] = ['field' => $field, 'label' => $cfg['label'], 'old' => $oldVal, 'new' => $newVal];
    }

    if (!$errors) {
        $user   = current_user();
        $result = submit_amendment($type, $id, $user['id'], $row['numer_umowy'], $opis, $plik, $proposed);
        $n = count($proposed);
        $msg = "Aneks #{$result['numer']} złożony" . ($n ? " ({$n} zmian pól)" : '') . '.';
        if ($result['emails_sent'] > 0) $msg .= " Powiadomiono {$result['emails_sent']} administratora/ów.";
        flash_set('success', $msg);
        header('Location: ' . $back);
        exit;
    }
}

$PAGE_TITLE = 'Nowy aneks — ' . $row['numer_umowy'];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <a href="<?= $back ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
  <h5 class="mb-0">Aneks do umowy <strong><?= h($row['numer_umowy']) ?></strong>
    <?= status_badge($row['status']) ?>
  </h5>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width:820px">
<div class="card-header fw-semibold bg-white">
  <i class="bi bi-file-earmark-diff text-primary"></i> Nowy aneks — zmiana warunków umowy
</div>
<div class="card-body">
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="type" value="<?= h($type) ?>">
  <input type="hidden" name="id"   value="<?= $id ?>">

  <div class="mb-4">
    <label class="form-label fw-semibold">Uzasadnienie aneksu <span class="text-danger">*</span></label>
    <textarea name="opis_zmian" class="form-control" rows="3" required
      placeholder="Krótki opis powodów wprowadzanych zmian..."><?= h($_POST['opis_zmian'] ?? '') ?></textarea>
  </div>

  <p class="text-muted small">
    <i class="bi bi-info-circle"></i>
    Wypełnij <strong>tylko pola, które mają ulec zmianie</strong>. Puste pola zostaną pominięte.
    Po zaakceptowaniu przez administratora zmiany zostaną automatycznie zapisane w umowie.
  </p>

  <div class="table-responsive">
  <table class="table table-bordered align-middle">
    <thead class="table-light">
      <tr>
        <th style="width:30%">Pole</th>
        <th style="width:30%">Obecna wartość</th>
        <th style="width:40%">Nowa wartość <small class="text-muted fw-normal">(zostaw puste = bez zmiany)</small></th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($fields as $field):
        $cfg    = $field_configs[$field] ?? ['label' => $field, 'type' => 'text'];
        $curVal = $row[$field] ?? '';
        $postVal = $_POST['field_' . $field] ?? '';
    ?>
    <tr>
      <td class="fw-semibold small"><?= h($cfg['label']) ?></td>
      <td class="text-muted small"><?= nl2br(h((string)$curVal)) ?: '<em>—</em>' ?></td>
      <td>
        <?php if ($cfg['type'] === 'date'): ?>
          <input type="date" name="field_<?= $field ?>" class="form-control form-control-sm"
            value="<?= h($postVal) ?>">

        <?php elseif ($cfg['type'] === 'number'): ?>
          <input type="number" step="0.01" min="0" name="field_<?= $field ?>" class="form-control form-control-sm"
            value="<?= h($postVal) ?>" placeholder="np. 3500.00">

        <?php elseif ($cfg['type'] === 'textarea'): ?>
          <textarea name="field_<?= $field ?>" class="form-control form-control-sm" rows="2"><?= h($postVal) ?></textarea>

        <?php elseif ($cfg['type'] === 'select'): ?>
          <select name="field_<?= $field ?>" class="form-select form-select-sm">
            <option value="">— bez zmiany —</option>
            <?php foreach ($cfg['options'] as $k => $v): ?>
            <option value="<?= h($k) ?>" <?= $postVal === $k ? 'selected' : '' ?>><?= h($v) ?></option>
            <?php endforeach; ?>
          </select>

        <?php else: ?>
          <input type="text" name="field_<?= $field ?>" class="form-control form-control-sm"
            value="<?= h($postVal) ?>">
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Plik aneksu <span class="text-muted fw-normal">(opcjonalne, PDF/DOC)</span></label>
    <input type="file" name="plik_aneksu" class="form-control" accept=".pdf,.doc,.docx">
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i> Złóż wniosek o aneks</button>
    <a href="<?= $back ?>" class="btn btn-outline-secondary">Anuluj</a>
  </div>
</form>
</div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

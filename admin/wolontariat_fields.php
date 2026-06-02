<?php
/**
 * admin/wolontariat_fields.php
 * Konfiguracja wymagalności pól formularza wolontariatu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Pola formularza wolontariatu';

// Definicja pól z etykietami i krokami
$FIELDS = [
    // krok => [ klucz => etykieta ]
    1 => [
        'imie_nazwisko'   => 'Imię i nazwisko',
        'email'           => 'E-mail',
        'pesel'           => 'PESEL',
        'data_urodzenia'  => 'Data urodzenia',
        'telefon'         => 'Telefon',
        'adres'           => 'Adres zamieszkania',
    ],
    2 => [
        'numer_umowy'           => 'Numer umowy',
        'data_zawarcia'         => 'Data zawarcia',
        'data_rozpoczecia'      => 'Data rozpoczęcia',
        'data_zakonczenia'      => 'Data zakończenia',
        'opiekun'               => 'Opiekun wolontariusza',
        'miejsce_wolontariatu'  => 'Miejsce wolontariatu',
        'przedmiot_porozumienia'=> 'Przedmiot porozumienia',
        'projekt_program'       => 'Projekt / program',
    ],
    5 => [
        'm365_security_group_id' => 'Security Group M365',
    ],
];

$LEVELS = [
    'required'    => ['label' => 'Wymagane',     'badge' => 'danger',  'icon' => 'bi-asterisk'],
    'recommended' => ['label' => 'Zalecane',     'badge' => 'warning', 'icon' => 'bi-exclamation'],
    'optional'    => ['label' => 'Opcjonalne',   'badge' => 'secondary','icon' => 'bi-dash'],
];

// Zapis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($FIELDS as $step => $fields) {
        foreach ($fields as $key => $label) {
            $val = $_POST['field_' . $key] ?? 'optional';
            if (!array_key_exists($val, $LEVELS)) $val = 'optional';
            $skey = 'wolontariat_field_' . $key;
            $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$skey]);
            if ($exists) {
                db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $skey]);
            } else {
                db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$skey, $val]);
            }
        }
    }
    flash_set('success', 'Konfiguracja pól zapisana.');
    header('Location: ' . APP_URL . '/admin/wolontariat_fields.php');
    exit;
}

// Odczyt
$saved = [];
foreach ($FIELDS as $step => $fields) {
    foreach ($fields as $key => $label) {
        $saved[$key] = org_setting('wolontariat_field_' . $key) ?: 'recommended';
    }
}

$STEP_LABELS = [1 => 'Wolontariusz', 2 => 'Porozumienie', 5 => 'M365'];

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Pola formularza wolontariatu</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-4">
  <span class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center"
        style="width:48px;height:48px;flex-shrink:0">
    <i class="bi bi-ui-checks text-success" style="font-size:1.4rem"></i>
  </span>
  <div>
    <h4 class="mb-0">Pola formularza wolontariatu</h4>
    <div class="text-muted small">Ustaw które pola są wymagane, zalecane lub opcjonalne — formularz pokaże ostrzeżenia, ale nigdy nie zablokuje zapisu</div>
  </div>
</div>

<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<?php foreach ($FIELDS as $step => $fields): ?>
<div class="card mb-3 shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center gap-2 py-2">
    <span class="badge bg-primary rounded-pill"><?= $step ?></span>
    Krok <?= $step ?>: <?= h($STEP_LABELS[$step]) ?>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:55%">Pole</th>
          <?php foreach ($LEVELS as $lkey => $ldata): ?>
          <th class="text-center" style="width:15%">
            <span class="badge bg-<?= $ldata['badge'] ?> bg-opacity-75">
              <?= $ldata['label'] ?>
            </span>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fields as $key => $label): ?>
        <tr>
          <td class="ps-3 align-middle">
            <code class="text-muted small me-2"><?= h($key) ?></code><?= h($label) ?>
          </td>
          <?php foreach ($LEVELS as $lkey => $ldata): ?>
          <td class="text-center align-middle">
            <input type="radio" class="form-check-input"
                   name="field_<?= h($key) ?>" value="<?= $lkey ?>"
                   <?= ($saved[$key] === $lkey) ? 'checked' : '' ?>>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<div class="alert alert-info d-flex gap-2 mb-4">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div class="small">
    <strong>Wymagane</strong> — ostrzeżenie czerwone przy opuszczaniu kroku.<br>
    <strong>Zalecane</strong> — ostrzeżenie żółte — warto uzupełnić.<br>
    <strong>Opcjonalne</strong> — bez ostrzeżeń.
  </div>
</div>

<button type="submit" class="btn btn-primary px-4">
  <i class="bi bi-check-lg me-1"></i>Zapisz konfigurację
</button>
<a href="index.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

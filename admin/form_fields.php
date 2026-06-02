<?php
/**
 * admin/form_fields.php
 * Konfiguracja wymagalności pól we wszystkich formularzach systemu.
 * Klucze settings: form_field_{typ}_{pole} = required | recommended | optional
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Wymagane pola w formularzach';

// ── Definicja typów formularzy i ich pól ──────────────────────────────────────
// Aby dodać nowy typ: dopisz wpis do $FORM_TYPES.
$FORM_TYPES = [

    'wolontariat' => [
        'label' => 'Wolontariat',
        'icon'  => 'bi-heart',
        'color' => '#16a34a',
        'steps' => [
            'Wolontariusz' => [
                'imie_nazwisko'    => 'Imię i nazwisko',
                'email'            => 'E-mail',
                'pesel'            => 'PESEL',
                'data_urodzenia'   => 'Data urodzenia',
                'telefon'          => 'Telefon',
                'adres'            => 'Adres zamieszkania',
            ],
            'Porozumienie' => [
                'numer_umowy'            => 'Numer umowy',
                'data_zawarcia'          => 'Data zawarcia',
                'data_rozpoczecia'       => 'Data rozpoczęcia',
                'data_zakonczenia'       => 'Data zakończenia',
                'opiekun'                => 'Opiekun wolontariusza',
                'miejsce_wolontariatu'   => 'Miejsce wolontariatu',
                'przedmiot_porozumienia' => 'Przedmiot porozumienia',
                'projekt_program'        => 'Projekt / program',
            ],
            'Microsoft 365' => [
                'm365_security_group_id' => 'Security Group M365',
            ],
        ],
    ],

    // Przykład — odkomentuj i uzupełnij gdy będzie potrzebny
    // 'zlecenie' => [
    //     'label' => 'Umowa zlecenia',
    //     'icon'  => 'bi-person-lines-fill',
    //     'color' => '#2563eb',
    //     'steps' => [
    //         'Zleceniobiorca' => [
    //             'imie_nazwisko' => 'Imię i nazwisko',
    //             'pesel'         => 'PESEL',
    //             'email'         => 'E-mail',
    //         ],
    //         'Umowa' => [
    //             'numer_umowy'  => 'Numer umowy',
    //             'data_zawarcia'=> 'Data zawarcia',
    //             'wynagrodzenie'=> 'Wynagrodzenie brutto',
    //         ],
    //     ],
    // ],

];

$LEVELS = [
    'required'    => ['label' => 'Wymagane',  'badge' => 'danger',   'icon' => 'bi-asterisk'],
    'recommended' => ['label' => 'Zalecane',  'badge' => 'warning',  'icon' => 'bi-exclamation'],
    'optional'    => ['label' => 'Opcjonalne','badge' => 'secondary','icon' => 'bi-dash'],
];

// ── Migracja starych kluczy wolontariat_field_* → form_field_wolontariat_* ───
foreach (($FORM_TYPES['wolontariat']['steps'] ?? []) as $step => $fields) {
    foreach ($fields as $fkey => $flabel) {
        $old = db_one("SELECT value FROM settings WHERE key_=?", ['wolontariat_field_' . $fkey]);
        if ($old) {
            $new_key = 'form_field_wolontariat_' . $fkey;
            $exists  = db_one("SELECT 1 FROM settings WHERE key_=?", [$new_key]);
            if (!$exists) {
                db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$new_key, $old['value']]);
            }
            db()->prepare("DELETE FROM settings WHERE key_=?")->execute(['wolontariat_field_' . $fkey]);
        }
    }
}

// ── Zapis ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = $_POST['_type'] ?? '';
    if (array_key_exists($type, $FORM_TYPES)) {
        foreach ($FORM_TYPES[$type]['steps'] as $step => $fields) {
            foreach ($fields as $fkey => $flabel) {
                $val  = $_POST['field_' . $fkey] ?? 'optional';
                if (!array_key_exists($val, $LEVELS)) $val = 'optional';
                $skey = 'form_field_' . $type . '_' . $fkey;
                $exists = db_one("SELECT 1 FROM settings WHERE key_=?", [$skey]);
                if ($exists) {
                    db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $skey]);
                } else {
                    db()->prepare("INSERT INTO settings (key_,value) VALUES (?,?)")->execute([$skey, $val]);
                }
            }
        }
        flash_set('success', 'Konfiguracja pól zapisana.');
        header('Location: ' . APP_URL . '/admin/form_fields.php?type=' . urlencode($type));
        exit;
    }
}

// ── Odczyt ────────────────────────────────────────────────────────────────────
$activeType = $_GET['type'] ?? array_key_first($FORM_TYPES);
if (!array_key_exists($activeType, $FORM_TYPES)) $activeType = array_key_first($FORM_TYPES);

$saved = [];
foreach ($FORM_TYPES as $type => $tdata) {
    foreach ($tdata['steps'] as $step => $fields) {
        foreach ($fields as $fkey => $flabel) {
            $saved[$type][$fkey] = org_setting('form_field_' . $type . '_' . $fkey) ?: 'recommended';
        }
    }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Wymagane pola w formularzach</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-4">
  <span class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center"
        style="width:48px;height:48px;flex-shrink:0">
    <i class="bi bi-ui-checks text-primary" style="font-size:1.4rem"></i>
  </span>
  <div>
    <h4 class="mb-0">Wymagane pola w formularzach</h4>
    <div class="text-muted small">Dla każdego pola ustaw poziom — formularz pokaże ostrzeżenie, ale nigdy nie zablokuje zapisu</div>
  </div>
</div>

<!-- Zakładki typów -->
<ul class="nav nav-tabs mb-4">
  <?php foreach ($FORM_TYPES as $type => $tdata): ?>
  <li class="nav-item">
    <a class="nav-link d-flex align-items-center gap-2 <?= $type === $activeType ? 'active' : '' ?>"
       href="?type=<?= urlencode($type) ?>">
      <i class="bi <?= h($tdata['icon']) ?>" style="color:<?= h($tdata['color']) ?>"></i>
      <?= h($tdata['label']) ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<?php $tdata = $FORM_TYPES[$activeType]; ?>

<form method="post">
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
<input type="hidden" name="_type" value="<?= h($activeType) ?>">

<?php foreach ($tdata['steps'] as $stepLabel => $fields): ?>
<div class="card mb-3 shadow-sm">
  <div class="card-header fw-semibold d-flex align-items-center gap-2 py-2" style="background:#f8fafc">
    <i class="bi bi-layers" style="color:<?= h($tdata['color']) ?>"></i>
    <?= h($stepLabel) ?>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th class="ps-3" style="width:50%">Pole</th>
          <?php foreach ($LEVELS as $lkey => $ldata): ?>
          <th class="text-center" style="width:~16%">
            <span class="badge bg-<?= $ldata['badge'] ?>-subtle text-<?= $ldata['badge'] ?> border border-<?= $ldata['badge'] ?>-subtle">
              <i class="bi <?= $ldata['icon'] ?> me-1"></i><?= $ldata['label'] ?>
            </span>
          </th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($fields as $fkey => $flabel): ?>
        <tr>
          <td class="ps-3">
            <span class="fw-semibold"><?= h($flabel) ?></span>
            <code class="text-muted small ms-2"><?= h($fkey) ?></code>
          </td>
          <?php foreach ($LEVELS as $lkey => $ldata): ?>
          <td class="text-center">
            <input type="radio" class="form-check-input"
                   name="field_<?= h($fkey) ?>" value="<?= $lkey ?>"
                   <?= ($saved[$activeType][$fkey] ?? 'recommended') === $lkey ? 'checked' : '' ?>>
          </td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>

<div class="alert alert-info d-flex gap-2 mb-4 mt-2">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div class="small">
    <strong>Wymagane</strong> — ostrzeżenie czerwone przy przejściu do kolejnego kroku.<br>
    <strong>Zalecane</strong> — ostrzeżenie żółte — warto uzupełnić.<br>
    <strong>Opcjonalne</strong> — bez komunikatów.
  </div>
</div>

<button type="submit" class="btn btn-primary px-4">
  <i class="bi bi-check-lg me-1"></i>Zapisz konfigurację
</button>
<a href="index.php" class="btn btn-outline-secondary ms-2">Anuluj</a>
</form>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

<?php
/**
 * karty30/ti/kursant/onboarding.php — lista startowa dla nowego kursanta.
 * Stan każdego kroku liczony z bazy (dane kontaktowe, zgoda, zapis na grupę).
 * Statusy tekstowe (nie tylko kolorem) — WCAG 1.4.1.
 */
require_once __DIR__ . '/auth.php';

$s        = student_require();
$acc_id   = (int)($s['id'] ?? 0);
$client_id = (int)($s['client_id'] ?? 0);
karty30_migrate();

$klient = $client_id ? db_one("SELECT name, email, phone, consent FROM k30_clients WHERE id = ?", [$client_id]) : null;
$ma_mail  = trim((string)($klient['email'] ?? '')) !== '';
$ma_tel   = preg_replace('/\D/', '', (string)($klient['phone'] ?? '')) !== '';
$ma_zgode = !empty($klient['consent']);
$ma_grupe = $client_id && (bool)db_one(
    "SELECT 1 FROM k30_ti_enrollments WHERE client_id = ? AND status = 'active' LIMIT 1", [$client_id]);

// [etykieta, czy zrobione, opis, adres, etykieta linku]
$kroki = [
    ['Logowanie do panelu', true,
     'Jesteś zalogowany(a), więc konto działa.',
     'index.php', 'Przejdź do panelu'],
    ['Adres e-mail', $ma_mail,
     'Na e-mail przychodzą informacje o zajęciach i rozliczeniach. Zgłoś prowadzącemu, jeśli adres jest błędny.',
     'index.php', 'Sprawdź dane'],
    ['Numer telefonu', $ma_tel,
     'Na telefon przychodzą przypomnienia o zajęciach i kody SMS.',
     'index.php', 'Sprawdź dane'],
    ['Zgoda na przetwarzanie danych', $ma_zgode,
     'Bez zgody nie możemy prowadzić zapisów i rozliczeń.',
     'index.php', 'Sprawdź zgodę'],
    ['Zapis na grupę', $ma_grupe,
     'Po przypisaniu do grupy zobaczysz swój plan zajęć i rozliczenia.',
     'index.php', 'Zobacz grupy'],
];
$zrobione = count(array_filter($kroki, fn($k) => $k[1]));

// Grupy kursanta (aktywne zapisy): numer (group_code, a gdy go nie ma — numer kursu) i nazwa.
$moje_grupy = $client_id ? db_all(
    "SELECT c.id, c.name, c.group_code FROM k30_ti_enrollments e
       JOIN k30_ti_courses c ON c.id = e.course_id
      WHERE e.client_id = ? AND e.status = 'active'
      ORDER BY c.name",
    [$client_id]) : [];

$KP_TITLE      = 'Start w panelu kursanta';
$KP_TOPBAR     = ['brand' => 'Panel kursanta', 'icon' => 'pc-display', 'user' => (string)($klient['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<link rel="stylesheet" href="../assets/ti_skin.css">

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:760px">

<h1 class="h4 fw-bold mb-2"><i class="bi bi-person-check text-primary me-2" aria-hidden="true"></i>Start w panelu kursanta</h1>
<p class="small mb-3">Lista pokazuje, co jest już gotowe, a co zostało do zrobienia. Postęp: <strong><?= $zrobione ?> z <?= count($kroki) ?></strong> kroków.</p>

<ol class="list-unstyled mb-0" aria-label="Kroki startowe">
<?php foreach ($kroki as $i => [$etykieta, $ok, $opis, $adres, $link]): ?>
  <li class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex gap-3 align-items-start">
      <div class="flex-shrink-0 fw-bold text-body-secondary" aria-hidden="true"><?= $i + 1 ?>.</div>
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <h2 class="h6 fw-semibold mb-0"><?= h($etykieta) ?></h2>
          <?php if ($ok): ?>
          <span class="badge bg-success">zrobione</span>
          <?php else: ?>
          <span class="badge bg-warning text-dark">do zrobienia</span>
          <?php endif; ?>
        </div>
        <p class="small mb-2 mt-1"><?= h($opis) ?></p>
        <a href="<?= h($adres) ?>" class="btn btn-sm <?= $ok ? 'btn-outline-secondary' : 'btn-primary' ?>"><?= h($link) ?></a>
      </div>
    </div>
  </li>
<?php endforeach; ?>
</ol>

<?php if ($moje_grupy): ?>
<section class="card border-0 shadow-sm mb-3" aria-labelledby="grupy-h">
  <div class="card-header bg-transparent fw-semibold" id="grupy-h">Twoje grupy</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead><tr><th scope="col">Numer grupy</th><th scope="col">Nazwa</th></tr></thead>
    <tbody>
    <?php foreach ($moje_grupy as $g): ?>
      <tr>
        <td class="font-monospace small"><?= h($g['group_code'] !== '' ? $g['group_code'] : '#' . (int)$g['id']) ?></td>
        <td><?= h($g['name']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<p class="small mb-3"><strong>Dopisanie do grupy oznacza dołączenie do zajęć.</strong> Po dopisaniu zobaczysz plan i zajęcia tej grupy.</p>

<p class="small text-body-secondary mt-3">Pytania kieruj do prowadzącego swojej grupy.</p>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>

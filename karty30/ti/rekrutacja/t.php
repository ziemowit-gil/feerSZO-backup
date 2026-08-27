<?php
/**
 * karty30/ti/rekrutacja/t.php — Zapisy na zajęcia po tokenie z URL.
 *
 * Osobna, samodzielna podstrona (bez logowania, bez menu panelu) — link
 * z osobistym tokenem kursant dostaje e-mailem przy zapowiedzi tury.
 * Token (selektor.sekret) jest przyjmowany RAZ: wymieniamy go na krótką
 * sesję k30_rk i przekierowujemy 303 na czysty adres, żeby sekret nie
 * jeździł w Refererze, historii i logach kolejnych żądań.
 *
 * Ten sam silnik co w panelu kursanta (rk_book/rk_cancel) — różni się
 * wyłącznie źródłem tożsamości: client_id z sesji tokenowej.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_rekrutacja.php';

karty30_migrate();
ti_planner_ext_migrate();
ti_rk_migrate();

$org = defined('ORG_NAME') ? ORG_NAME : 'SZO';

/* ── Wejście z tokenem → sesja → czysty adres ──────────────────────────────── */
if (isset($_GET['t'])) {
    if (!rk_rate_ok('token', $_SERVER['REMOTE_ADDR'] ?? '')) {
        http_response_code(429);
        rk_t_page($org, 'Zbyt wiele prób',
            '<p>Z tego adresu wykonano zbyt wiele prób otwarcia linku. Odczekaj kilka minut i spróbuj ponownie.</p>');
        exit;
    }
    $tok = rk_token_resolve(trim((string)$_GET['t']));
    if (!$tok) {
        http_response_code(410);
        rk_t_page($org, 'Link wygasł lub jest nieprawidłowy',
            '<p>Ten link do zapisów nie działa — mógł wygasnąć po zamknięciu tury albo został unieważniony.</p>
             <p>Jeśli masz konto w panelu kursanta, zapisz się po zalogowaniu w zakładce
             <strong>Nauka → Zapisy na zajęcia</strong>. W innym wypadku skontaktuj się z sekretariatem,
             aby otrzymać nowy link.</p>');
        exit;
    }
    rk_session_open($tok);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'), true, 303);
    exit;
}

$ctx = rk_session_context();
if (!$ctx) {
    http_response_code(403);
    rk_t_page($org, 'Potrzebny link z zaproszeniem',
        '<p>Ta strona działa wyłącznie po otwarciu osobistego linku z wiadomości e-mail
         o starcie zapisów. Link jest przypisany do Ciebie i nie wymaga logowania.</p>
         <p>Masz konto w panelu kursanta? Zapisy znajdziesz też tam — zakładka
         <strong>Nauka → Zapisy na zajęcia</strong>.</p>');
    exit;
}

$client_id = $ctx['client_id'];
$client    = db_one("SELECT id, name FROM k30_clients WHERE id=?", [$client_id]);
if (!$client) { http_response_code(403); rk_t_page($org, 'Konto nieaktywne', '<p>Nie znaleziono kursanta dla tego linku.</p>'); exit; }

/* ── POST: rezerwacja / rezygnacja ─────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(rk_session_csrf(), (string)($_POST['_token'] ?? ''))) {
        http_response_code(403); exit('Nieprawidłowy token sesji.');
    }
    $op   = $_POST['_op'] ?? '';
    $back = 't.php';

    if ($op === 'rk_book' || $op === 'rk_book_series') {
        $back = 't.php?rk_round=' . (int)($_POST['rk_round'] ?? 0)
              . '&rk_instr=' . (int)($_POST['rk_instr'] ?? 0);
        try {
            $r = $op === 'rk_book_series'
                ? rk_book_series((int)($_POST['slot_id'] ?? 0), $client_id, 'token', $ctx['token_id'] ?: null)
                : rk_book((int)($_POST['slot_id'] ?? 0), $client_id, 'token', $ctx['token_id'] ?: null);
            if (($r['status'] ?? '') === 'pending_parent') {
                require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
                $first_id = (int)($r['booking_id']
                    ?? (rk_series_bookings((string)($r['series_key'] ?? ''), 'pending_parent')[0]['id'] ?? 0));
                if ($first_id) rk_parent_request_send($first_id);
                $_SESSION['rk_flash'] = ['ok', (isset($r['booked'])
                        ? 'Seria ' . (int)$r['booked'] . ' terminów wstępnie zarezerwowana. '
                        : 'Miejsce wstępnie zarezerwowane. ')
                    . 'Rodzic/opiekun dostał e-mail z linkiem do zatwierdzenia (i SMS) '
                    . '— rezerwacja stanie się ostateczna po jego zgodzie.'];
            } elseif (isset($r['booked'])) {
                $_SESSION['rk_flash'] = ['ok', 'Zarezerwowano serię: ' . (int)$r['booked']
                    . ' terminów (ten sam dzień i godzina do końca tury). Pobrane żetony: '
                    . (int)$r['tokens_spent'] . '.'];
            } else {
                $_SESSION['rk_flash'] = ['ok', 'Termin zarezerwowany. Pobrane żetony: ' . (int)$r['tokens_spent'] . '.'];
            }
            $back = 't.php';
        } catch (RkException $e) {
            $_SESSION['rk_flash'] = ['err', rk_error_message($e->getMessage())];
        }
    } elseif ($op === 'rk_cancel') {
        try {
            $r = rk_cancel((int)($_POST['booking_id'] ?? 0), $client_id, 'student');
            $_SESSION['rk_flash'] = ['ok', $r['refunded'] > 0
                ? 'Rezygnacja przyjęta. Zwrócone żetony: ' . (int)$r['refunded'] . '.'
                : 'Rezygnacja przyjęta. Żetony nie podlegały już zwrotowi.'];
        } catch (RkException $e) {
            $_SESSION['rk_flash'] = ['err', rk_error_message($e->getMessage())];
        }
    }
    header('Location: ' . $back, true, 303); exit;
}

/* ── Dane widoku (ta sama logika co zakładka w panelu) ─────────────────────── */
$rk_avail    = rk_client_available($client_id);
$rk_pools    = rk_client_pools($client_id);
$rk_rounds   = rk_rounds_for_client($client_id);
$rk_bookings = rk_bookings_for_client($client_id, 30);

// Tura z tokenu ma pierwszeństwo, chyba że kursant sam wybrał inną
$rk_round_id = (int)($_GET['rk_round'] ?? 0);
if (!$rk_round_id && $ctx['round_id']) {
    $r0 = rk_round_get($ctx['round_id']);
    if ($r0 && in_array($r0['status'], ['open','scheduled'], true)) $rk_round_id = (int)$r0['id'];
}
$rk_instr_id = (int)($_GET['rk_instr'] ?? 0);
$rk_round    = $rk_round_id ? rk_round_get($rk_round_id) : null;
if ($rk_round && !in_array($rk_round['status'], ['open','scheduled'], true)) $rk_round = null;

// Lista zawężona przypisaniami kierownika (prowadzący per grupa kursanta)
$rk_instructors = $rk_round ? rk_instructors_for_round((int)$rk_round['id'], $client_id) : [];
$rk_instr_name = '';
foreach ($rk_instructors as $ri) {
    if ((int)$ri['id'] === $rk_instr_id) { $rk_instr_name = (string)$ri['name']; break; }
}
// Terminy tylko prowadzącego z listy — adres spoza przypisań nie pokaże slotów
$rk_slots = ($rk_round && $rk_instr_id && $rk_instr_name !== '')
    ? rk_slots_for_instructor((int)$rk_round['id'], $rk_instr_id, ['only_free' => 1])
    : [];

$rk_flash = $_SESSION['rk_flash'] ?? null;
unset($_SESSION['rk_flash']);

$mode_label = fn(string $m) => match ($m) {
    'onsite' => 'stacjonarnie', 'hybrid' => 'hybrydowo', default => 'online',
};

/** Samodzielna strona komunikatu (bez sesji). */
function rk_t_page(string $org, string $title, string $body_html): void {
    ?>
<!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<main class="container py-5" style="max-width:640px">
  <div class="card border-0 shadow-sm">
    <div class="card-body p-4">
      <h1 class="h5 fw-bold" style="color:#c2410c"><?= h($title) ?></h1>
      <?= $body_html ?>
    </div>
  </div>
  <p class="text-body-secondary small mt-3 text-center"><?= h($org) ?> — zapisy na zajęcia</p>
</main>
</body>
</html>
    <?php
}
?>
<!DOCTYPE html>
<html lang="pl" data-bs-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Zapisy na zajęcia — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  .rk-brand { color:#c2410c }
  .btn-rk   { background:#c2410c; border-color:#c2410c; color:#fff }
  .btn-rk:hover { background:#9a3412; border-color:#9a3412; color:#fff }
</style>
</head>
<body class="bg-body-tertiary">
<main class="container py-4" style="max-width:860px">

<header class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <div>
    <h1 class="h4 fw-bold mb-0 rk-brand"><i class="bi bi-ticket-perforated me-2" aria-hidden="true"></i>Zapisy na zajęcia</h1>
    <div class="text-body-secondary small"><?= h($org) ?> · <?= h($client['name']) ?></div>
  </div>
  <span class="badge fs-6 ms-auto" style="background:#c2410c" title="Dostępne żetony">
    <i class="bi bi-coin me-1" aria-hidden="true"></i><?= $rk_avail ?> żet.<?php
    $rk_pln = rk_token_pln(); if ($rk_pln > 0): ?> <span class="opacity-75">≈ <?= number_format($rk_avail * $rk_pln, 2, ',', ' ') ?> zł</span><?php endif; ?>
  </span>
</header>

<?php if ($rk_flash): ?>
<div class="alert alert-<?= $rk_flash[0] === 'ok' ? 'success' : 'danger' ?> py-2" role="alert">
  <i class="bi bi-<?= $rk_flash[0] === 'ok' ? 'check-circle' : 'exclamation-triangle' ?> me-1" aria-hidden="true"></i><?= h($rk_flash[1]) ?>
</div>
<?php endif; ?>

<?php if ($rk_pools): ?>
<div class="d-flex gap-2 flex-wrap mb-3">
  <?php foreach ($rk_pools as $p): if ((int)$p['granted'] === 0) continue; ?>
  <span class="badge text-bg-light border" title="przyznane <?= (int)$p['granted'] ?>, wydane <?= (int)$p['spent'] ?>">
    <span style="color:<?= h($p['color'] ?: '#6366f1') ?>">●</span>
    <?= h($p['name']) ?>: <strong><?= (int)$p['available'] ?></strong>
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Wybór: tura → prowadzący → termin ─────────────────────────────────── -->
<?php if (!$rk_rounds): ?>
<div class="card border-0 shadow-sm mb-4"><div class="card-body text-body-secondary">
  <i class="bi bi-inbox me-1" aria-hidden="true"></i>Obecnie nie trwa żadna tura zapisów.
</div></div>

<?php elseif (!$rk_round): ?>
<h2 class="h6 fw-bold mb-2">Wybierz turę</h2>
<div class="d-flex flex-column gap-2 mb-4">
  <?php foreach ($rk_rounds as $r): $r_open = $r['status'] === 'open' && strtotime((string)$r['opens_at']) <= time(); ?>
  <div class="card border-0 shadow-sm"><div class="card-body py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <div>
      <div class="fw-semibold"><?= h($r['name']) ?></div>
      <div class="text-body-secondary" style="font-size:.8rem">
        <?= $r_open ? 'zapisy trwają' . ($r['closes_at'] ? ' do ' . h(rk_fmt_dt((string)$r['closes_at'])) : '')
                    : 'start: ' . h(rk_fmt_dt((string)$r['opens_at'])) ?>
        · wolnych terminów: <?= (int)$r['n_free'] ?>
      </div>
    </div>
    <div class="ms-auto">
      <?php if ($r_open): ?>
      <a class="btn btn-sm btn-rk" href="t.php?rk_round=<?= (int)$r['id'] ?>">Wybierz prowadzącego <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></a>
      <?php else: ?><span class="badge text-bg-secondary">wkrótce</span><?php endif; ?>
    </div>
  </div></div>
  <?php endforeach; ?>
</div>

<?php elseif (!$rk_instr_id): ?>
<nav aria-label="Ścieżka zapisów" class="mb-2" style="font-size:.85rem">
  <a href="t.php">Tury</a> &rsaquo; <strong><?= h($rk_round['name']) ?></strong>
</nav>
<h2 class="h6 fw-bold mb-2">Wybierz prowadzącego</h2>
<?php if (!$rk_instructors): ?>
<div class="text-body-secondary small mb-4">Żaden prowadzący nie wystawił jeszcze terminów w tej turze.</div>
<?php else: ?>
<div class="row g-2 mb-4">
  <?php foreach ($rk_instructors as $i): $free = (int)$i['slots_free']; ?>
  <div class="col-12 col-sm-6">
    <div class="card h-100 border-0 shadow-sm <?= $free ? '' : 'opacity-50' ?>"><div class="card-body py-2 px-3">
      <div class="fw-semibold"><i class="bi bi-person-circle me-1 rk-brand" aria-hidden="true"></i><?= h($i['name']) ?></div>
      <div class="text-body-secondary" style="font-size:.8rem">
        <?= $free ? "wolne terminy: $free · najbliższy " . h(rk_fmt_dt((string)$i['next_free_at'])) : 'brak wolnych terminów' ?>
      </div>
      <?php if ($free): ?>
      <a class="btn btn-sm btn-outline-secondary mt-2" href="t.php?rk_round=<?= (int)$rk_round['id'] ?>&rk_instr=<?= (int)$i['id'] ?>">Zobacz terminy</a>
      <?php endif; ?>
    </div></div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<nav aria-label="Ścieżka zapisów" class="mb-2" style="font-size:.85rem">
  <a href="t.php">Tury</a> &rsaquo;
  <a href="t.php?rk_round=<?= (int)$rk_round['id'] ?>"><?= h($rk_round['name']) ?></a> &rsaquo;
  <strong><?= h($rk_instr_name ?: 'Prowadzący') ?></strong>
</nav>
<h2 class="h6 fw-bold mb-2">Wolne terminy</h2>
<?php if (!$rk_slots): ?>
<div class="text-body-secondary small mb-4">Ten prowadzący nie ma teraz wolnych terminów.
  <a href="t.php?rk_round=<?= (int)$rk_round['id'] ?>">Wybierz innego</a>.</div>
<?php else: ?>
<div class="table-responsive mb-4">
  <table class="table table-sm align-middle bg-body rounded shadow-sm">
    <caption class="visually-hidden">Wolne terminy prowadzącego</caption>
    <thead><tr>
      <th scope="col">Termin</th><th scope="col">Forma</th><th scope="col">Temat</th>
      <th scope="col" class="text-end">Miejsca</th><th scope="col" class="text-end">Koszt</th>
      <th scope="col" class="text-end"><span class="visually-hidden">Akcja</span></th>
    </tr></thead>
    <tbody>
      <?php foreach ($rk_slots as $s): $can = $rk_avail >= (int)$s['token_cost']; ?>
      <tr>
        <td><strong><?= h(rk_fmt_dt((string)$s['starts_at'])) ?></strong>
            <span class="text-body-secondary">– <?= h(substr((string)$s['ends_at'], 11, 5)) ?></span></td>
        <td><?= h($mode_label((string)$s['mode'])) ?><?= $s['room_name'] ? ', ' . h($s['room_name']) : '' ?></td>
        <td><?= $s['subject_label'] ? h($s['subject_label']) : '<span class="text-body-secondary">konsultacja</span>' ?></td>
        <td class="text-end"><?= (int)$s['seats_free'] ?>/<?= (int)$s['capacity'] ?></td>
        <td class="text-end fw-semibold"><?= (int)$s['token_cost'] ?> żet.</td>
        <td class="text-end text-nowrap">
          <form method="post" class="d-inline"
                onsubmit="return confirm('Zapisać się na termin <?= h(rk_fmt_dt((string)$s['starts_at'])) ?> (koszt: <?= (int)$s['token_cost'] ?> żet.)?')">
            <input type="hidden" name="_token" value="<?= h(rk_session_csrf()) ?>">
            <input type="hidden" name="_op" value="rk_book">
            <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="rk_round" value="<?= (int)$rk_round['id'] ?>">
            <input type="hidden" name="rk_instr" value="<?= (int)$rk_instr_id ?>">
            <button class="btn btn-sm btn-rk" <?= $can ? '' : 'disabled title="Za mało żetonów"' ?>>
              <i class="bi bi-check2 me-1" aria-hidden="true"></i>Rezerwuję
            </button>
          </form>
          <form method="post" class="d-inline"
                onsubmit="return confirm('Zarezerwować ten termin CO TYDZIEŃ (ten sam dzień i godzina) na wszystkie zajęcia do końca tury? Żetony zostaną pobrane za komplet — przy braku pokrycia nic nie zostanie zarezerwowane.')">
            <input type="hidden" name="_token" value="<?= h(rk_session_csrf()) ?>">
            <input type="hidden" name="_op" value="rk_book_series">
            <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="rk_round" value="<?= (int)$rk_round['id'] ?>">
            <input type="hidden" name="rk_instr" value="<?= (int)$rk_instr_id ?>">
            <button class="btn btn-sm btn-outline-secondary"
                    title="Ten dzień tygodnia i godzina co tydzień, na wszystkie terminy do końca tury">
              <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Ustal zajęcia na cały okres
            </button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── Moje rezerwacje ───────────────────────────────────────────────────── -->
<h2 class="h6 fw-bold mb-2"><i class="bi bi-bookmark-check me-1" aria-hidden="true"></i>Moje rezerwacje</h2>
<?php if (!$rk_bookings): ?>
<div class="text-body-secondary small">Nie masz jeszcze żadnych rezerwacji.</div>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-sm align-middle bg-body rounded shadow-sm">
    <caption class="visually-hidden">Moje rezerwacje</caption>
    <thead><tr>
      <th scope="col">Termin</th><th scope="col">Prowadzący</th>
      <th scope="col" class="text-end">Żetony</th><th scope="col">Status</th>
      <th scope="col" class="text-end"><span class="visually-hidden">Akcja</span></th>
    </tr></thead>
    <tbody>
      <?php foreach ($rk_bookings as $b):
        $future     = strtotime((string)$b['starts_at']) > time();
        $hours_left = (strtotime((string)$b['starts_at']) - time()) / 3600;
        $full_ref   = $hours_left >= (int)$b['refund_hours'];
        [$st_label, $st_class] = match ((string)$b['status']) {
            'confirmed'         => $future ? ['zarezerwowane', 'primary'] : ['w toku', 'secondary'],
            'pending_parent'    => ['czeka na zgodę rodzica', 'warning'],
            'attended'          => ['odbyte', 'success'],
            'no_show'           => ['nieobecność', 'danger'],
            'cancelled_student' => ['zrezygnowano', 'secondary'],
            'cancelled_staff'   => ['odwołane przez ośrodek', 'warning'],
            'cancelled_parent'  => ['niezatwierdzone przez rodzica', 'secondary'],
            default             => [(string)$b['status'], 'secondary'],
        };
      ?>
      <tr>
        <td><strong><?= h(rk_fmt_dt((string)$b['starts_at'])) ?></strong>
          <div class="text-body-secondary" style="font-size:.78rem">
            <?= h($mode_label((string)$b['mode'])) ?><?= $b['subject_label'] ? ' · ' . h($b['subject_label']) : '' ?>
          </div></td>
        <td><?= h($b['instructor_name']) ?></td>
        <td class="text-end"><?= (int)$b['tokens_spent'] ?><?= (int)$b['tokens_refunded'] > 0
            ? ' <span class="text-success" style="font-size:.78rem">(zwrot ' . (int)$b['tokens_refunded'] . ')</span>' : '' ?></td>
        <td><span class="badge text-bg-<?= $st_class ?>"><?= h($st_label) ?></span></td>
        <td class="text-end">
          <?php if (in_array($b['status'], ['confirmed','pending_parent'], true) && $future): ?>
          <form method="post" class="d-inline"
                onsubmit="return confirm('<?= ($full_ref || $b['status'] === 'pending_parent')
                    ? 'Zrezygnować z terminu? Żetony wrócą w całości.'
                    : 'Uwaga: termin jest bliżej niż ' . (int)$b['refund_hours'] . ' h — żetony mogą nie zostać zwrócone. Zrezygnować?' ?>')">
            <input type="hidden" name="_token" value="<?= h(rk_session_csrf()) ?>">
            <input type="hidden" name="_op" value="rk_cancel">
            <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rezygnuję</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<p class="text-body-secondary small mt-4 text-center">
  Dostęp z osobistego linku — nie przekazuj go innym. Sesja wygasa po godzinie bezczynności.<br>
  Masz konto w panelu kursanta? Zapisy znajdziesz też w zakładce „Nauka → Zapisy na zajęcia”.
</p>

</main>
</body>
</html>

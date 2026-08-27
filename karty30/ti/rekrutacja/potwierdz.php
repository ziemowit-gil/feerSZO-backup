<?php
/**
 * karty30/ti/rekrutacja/potwierdz.php — zatwierdzenie rezerwacji małoletniego
 * przez rodzica/opiekuna.
 *
 * Rodzic dostaje e-mailem link z tokenem (scope parent_confirm, wskazuje jedną
 * rezerwację) i SMS-a informacyjnego. Tu widzi szczegóły terminu i decyduje:
 * zatwierdza (rezerwacja staje się ostateczna, kursant trafia do dziennika)
 * albo odrzuca (pełny zwrot żetonów, miejsce wraca do puli). Brak decyzji
 * w czasie rk_parent_confirm_hours → cron wygasza z pełnym zwrotem.
 *
 * Strona samodzielna, bez logowania. Token zostaje w adresie przez cały czas
 * decyzji (formularz POST-uje go z powrotem) — to celowe: rodzic nie ma tu
 * sesji, a token jest jednorazowego użytku (unieważniany po decyzji).
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

if (!rk_rate_ok('parent', $_SERVER['REMOTE_ADDR'] ?? '')) {
    http_response_code(429);
    rk_p_page($org, 'Zbyt wiele prób', '<p>Odczekaj kilka minut i spróbuj ponownie.</p>');
    exit;
}

$raw = trim((string)($_POST['t'] ?? $_GET['t'] ?? ''));
$ctx = $raw !== '' ? rk_parent_resolve($raw) : null;

if (!$ctx) {
    http_response_code(410);
    rk_p_page($org, 'Link wygasł lub jest nieprawidłowy',
        '<p>Ten link do zatwierdzenia nie działa — decyzja mogła już zapaść, rezerwacja
         wygasnąć, albo link został użyty wcześniej.</p>
         <p>Aktualny stan rezerwacji dziecko widzi w swoim panelu kursanta, w zakładce
         <strong>Nauka → Zapisy na zajęcia</strong>. W razie wątpliwości prosimy o kontakt
         z sekretariatem.</p>');
    exit;
}

$b       = $ctx['booking'];
$decided = null;

// Seria (stały dzień+godzina na semestr): decyzja rodzica obejmuje komplet
$series_key = (string)($b['series_key'] ?? '');
$series     = $series_key !== '' ? rk_series_bookings($series_key, 'pending_parent') : [];
$series_cnt = count($series);
$series_sum = array_sum(array_map(fn($x) => (int)$x['tokens_spent'], $series));

/* ── Decyzja rodzica ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $b['status'] === 'pending_parent') {
    $op = $_POST['_op'] ?? '';
    try {
        if ($op === 'approve') {
            $series_cnt > 1 ? rk_parent_confirm_series($series_key) : rk_parent_confirm((int)$b['id']);
            $decided = 'approved';
            // Dwustopniowe zatwierdzanie: wpis idzie teraz do prowadzącego
            if (rk_approval_enabled()) {
                $first = $series_cnt > 1
                    ? (rk_series_bookings($series_key, 'pending_instructor')[0] ?? null)
                    : ['id' => (int)$b['id']];
                if ($first) rk_approval_notify((int)$first['id'], 'instructor');
            }
        } elseif ($op === 'reject') {
            $series_cnt > 1
                ? rk_parent_reject_series($series_key, trim($_POST['reason'] ?? ''))
                : rk_parent_reject((int)$b['id'], trim($_POST['reason'] ?? ''));
            $decided = 'rejected';
        }
    } catch (RkException $e) {
        $decided = 'conflict';   // wyścig: rezerwacja zmieniła stan w międzyczasie
    }
    if ($decided) {
        $b = db_one(
            "SELECT b.*, s.starts_at, s.ends_at, s.subject_label, s.mode,
                    u.name AS instructor_name, cl.name AS client_name
               FROM k30_rk_bookings b
               JOIN k30_rk_slots s ON s.id = b.slot_id
               JOIN users u ON u.id = s.instructor_id
               JOIN k30_clients cl ON cl.id = b.client_id
              WHERE b.id = ?", [(int)$b['id']]) ?: $b;
    }
}

$mode_label = fn(string $m) => match ($m) {
    'onsite' => 'stacjonarnie', 'hybrid' => 'hybrydowo', default => 'online',
};

/** Samodzielna strona komunikatu. */
function rk_p_page(string $org, string $title, string $body_html): void {
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
  <div class="card border-0 shadow-sm"><div class="card-body p-4">
    <h1 class="h5 fw-bold" style="color:#c2410c"><?= h($title) ?></h1>
    <?= $body_html ?>
  </div></div>
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
<title>Zatwierdzenie rezerwacji — <?= h($org) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">
<main class="container py-5" style="max-width:640px">

<div class="card border-0 shadow-sm">
  <div class="card-body p-4">
    <h1 class="h5 fw-bold mb-3" style="color:#c2410c">
      <i class="bi bi-shield-check me-2" aria-hidden="true"></i>Zatwierdzenie rezerwacji
    </h1>

    <?php if ($decided === 'approved'): ?>
    <div class="alert alert-success" role="alert">
      <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
      <strong>Zgoda zapisana.</strong>
      <?php if (rk_approval_enabled()): ?>
      Wpis czeka jeszcze na zatwierdzenie prowadzącego i kierownika — po ich zgodzie
      rezerwacja stanie się ostateczna. Stan widać w panelu kursanta.
      <?php else: ?>
      Dziecko jest zapisane na zajęcia — szczegóły widzi w swoim panelu kursanta.
      <?php endif; ?>
    </div>

    <?php elseif ($decided === 'rejected'): ?>
    <div class="alert alert-secondary" role="alert">
      <i class="bi bi-x-circle me-1" aria-hidden="true"></i>
      <strong>Rezerwacja odrzucona.</strong> Miejsce wróciło do puli,
      a żetony zostały zwrócone w całości na konto dziecka.
    </div>

    <?php elseif ($decided === 'conflict' || $b['status'] !== 'pending_parent'): ?>
    <div class="alert alert-warning" role="alert">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
      Ta rezerwacja nie oczekuje już na decyzję
      <?php if ($b['status'] === 'confirmed'): ?> — została wcześniej zatwierdzona.
      <?php elseif (str_starts_with((string)$b['status'], 'cancelled')): ?> — została anulowana, żetony wróciły do dziecka.
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <dl class="row mb-0 small">
      <dt class="col-sm-4 text-body-secondary">Kursant</dt>
      <dd class="col-sm-8 fw-semibold"><?= h($b['client_name']) ?></dd>
      <dt class="col-sm-4 text-body-secondary">Termin</dt>
      <dd class="col-sm-8"><strong><?= h(rk_fmt_dt((string)$b['starts_at'])) ?></strong>
          – <?= h(substr((string)$b['ends_at'], 11, 5)) ?></dd>
      <dt class="col-sm-4 text-body-secondary">Prowadzący</dt>
      <dd class="col-sm-8"><?= h($b['instructor_name']) ?></dd>
      <dt class="col-sm-4 text-body-secondary">Forma</dt>
      <dd class="col-sm-8"><?= h($mode_label((string)$b['mode'])) ?><?= $b['subject_label'] ? ' · ' . h($b['subject_label']) : '' ?></dd>
      <dt class="col-sm-4 text-body-secondary">Koszt</dt>
      <dd class="col-sm-8"><?= $series_cnt > 1 ? $series_sum : (int)$b['tokens_spent'] ?> żet.
        <?php $pln = rk_token_pln(); $koszt = $series_cnt > 1 ? $series_sum : (int)$b['tokens_spent']; if ($pln > 0): ?>
        <span class="text-body-secondary">(≈ <?= number_format($koszt * $pln, 2, ',', ' ') ?> zł)</span>
        <?php endif; ?></dd>
    </dl>

    <?php if ($series_cnt > 1): ?>
    <div class="alert alert-info py-2 mt-3 mb-0" role="alert">
      <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>
      <strong>Rezerwacja cykliczna:</strong> ten sam dzień tygodnia i godzina,
      łącznie <?= $series_cnt ?> terminów do końca tury
      (<?= h(rk_fmt_dt((string)$series[0]['starts_at'])) ?> –
       <?= h(rk_fmt_dt((string)$series[$series_cnt-1]['starts_at'])) ?>).
      Decyzja poniżej obejmuje całą serię.
    </div>
    <?php endif; ?>

    <?php if (!$decided && $b['status'] === 'pending_parent'): ?>
    <hr>
    <p class="small text-body-secondary">
      Dziecko zapisało się na powyższy termin. Rezerwacja stanie się ostateczna po Państwa
      zgodzie; bez decyzji wygaśnie automatycznie po <?= rk_parent_confirm_hours() ?> godzinach
      od zapisu, a żetony wrócą na konto dziecka.
    </p>
    <div class="d-flex gap-2 flex-wrap">
      <form method="post" class="d-inline">
        <input type="hidden" name="t" value="<?= h($raw) ?>">
        <input type="hidden" name="_op" value="approve">
        <button class="btn text-white" style="background:#2E6A4F">
          <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zatwierdzam <?= $series_cnt > 1 ? 'całą serię (' . $series_cnt . ')' : 'rezerwację' ?>
        </button>
      </form>
      <form method="post" class="d-inline"
            onsubmit="return confirm('Odrzucić rezerwację? Miejsce wróci do puli, żetony zostaną zwrócone.')">
        <input type="hidden" name="t" value="<?= h($raw) ?>">
        <input type="hidden" name="_op" value="reject">
        <button class="btn btn-outline-danger">
          <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Odrzucam
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<p class="text-body-secondary small mt-3 text-center">
  <?= h($org) ?> — zapisy na zajęcia. Link jednorazowy, przypisany do tej rezerwacji.
</p>

</main>
</body>
</html>

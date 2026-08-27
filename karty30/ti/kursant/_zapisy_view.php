<?php
/**
 * karty30/ti/kursant/_zapisy_view.php — zakładka „Zapisy na zajęcia” (?tab=zapisy).
 *
 * Wspólna dla widoku klasycznego i alternatywnego (ti-skin) — treść ta sama,
 * skórka różni się nawigacją i CSS. Zmienne z index.php: $student, $account.
 *
 * Przepływ zapisu: tura → prowadzący (?rk_round=) → terminy (?rk_instr=) →
 * POST _op=rk_book. Rezygnacja: POST _op=rk_cancel (zwrot wg reguł tury).
 */

$rk_client_id = (int)$student['client_id'];

$rk_pools    = rk_client_pools($rk_client_id);
$rk_avail    = rk_client_available($rk_client_id);
$rk_rounds   = rk_rounds_for_client($rk_client_id);
$rk_bookings = rk_bookings_for_client($rk_client_id, 50);
$rk_txns     = rk_client_txns($rk_client_id, 30);

$rk_round_id = (int)($_GET['rk_round'] ?? 0);
$rk_instr_id = (int)($_GET['rk_instr'] ?? 0);
$rk_round    = $rk_round_id ? rk_round_get($rk_round_id) : null;
if ($rk_round && !in_array($rk_round['status'], ['open','scheduled'], true)) $rk_round = null;

// Lista zawężona przypisaniami kierownika (prowadzący per grupa kursanta)
$rk_instructors = $rk_round ? rk_instructors_for_round((int)$rk_round['id'], $rk_client_id) : [];
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

$rk_active = array_values(array_filter($rk_bookings,
    fn($b) => $b['status'] === 'confirmed' && strtotime((string)$b['starts_at']) > time()));

$rk_mode_label = fn(string $m) => match ($m) {
    'onsite' => 'stacjonarnie', 'hybrid' => 'hybrydowo', default => 'online',
};
?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-ticket-perforated text-primary me-1" aria-hidden="true"></i>Zapisy na zajęcia</h1>
  <span class="badge text-bg-primary fs-6 ms-auto" title="Suma dostępnych żetonów ze wszystkich ważnych pul">
    <i class="bi bi-coin me-1" aria-hidden="true"></i><?= $rk_avail ?> <?= $rk_avail === 1 ? 'żeton' : ($rk_avail >= 2 && $rk_avail <= 4 ? 'żetony' : 'żetonów') ?>
    <?php $rk_pln = rk_token_pln(); if ($rk_pln > 0): ?>
    <span class="opacity-75">≈ <?= number_format($rk_avail * $rk_pln, 2, ',', ' ') ?> zł</span>
    <?php endif; ?>
  </span>
</div>

<?php if ($rk_flash): ?>
<div class="alert alert-<?= $rk_flash[0] === 'ok' ? 'success' : 'danger' ?> py-2" role="alert">
  <i class="bi bi-<?= $rk_flash[0] === 'ok' ? 'check-circle' : 'exclamation-triangle' ?> me-1" aria-hidden="true"></i><?= h($rk_flash[1]) ?>
</div>
<?php endif; ?>

<p class="text-body-secondary small mb-2">
  Od tego roku zapisujesz się wybierając najpierw <strong>prowadzącego</strong>, a potem termin
  z jego kalendarza. Każda rezerwacja kosztuje żetony z Twojej puli; rezygnacja odpowiednio
  wcześnie zwraca je w całości.
</p>
<details class="small mb-3">
  <summary class="text-body-secondary" style="cursor:pointer">
    <i class="bi bi-question-circle me-1" aria-hidden="true"></i>Na czym polega rejestracja żetonowa?
  </summary>
  <div class="text-body-secondary mt-2 ps-3" style="max-width:46rem">
    Żetony to wewnętrzne „bilety na zajęcia” — nie są pieniędzmi i służą wyłącznie zapisom.
    Pulę żetonów na dany okres przydziela Ci ośrodek (saldo widzisz poniżej). Każde zajęcia
    mają cenę w żetonach zależną od czasu trwania; rezerwacja pobiera żetony od razu i tym
    samym gwarantuje Ci miejsce. Rezygnacja odpowiednio wcześnie zwraca żetony w całości —
    możesz nimi opłacić inny termin. Gdy pula się wyczerpie, kolejne rezerwacje nie będą
    możliwe do czasu doładowania przez ośrodek.
  </div>
</details>

<!-- ── Saldo pul ─────────────────────────────────────────────────────────── -->
<h2 class="h6 fw-bold mt-4 mb-2"><i class="bi bi-wallet2 me-1" aria-hidden="true"></i>Moje pule żetonów</h2>
<?php if (!$rk_pools): ?>
<div class="text-body-secondary small mb-3">Nie masz jeszcze przyznanych żetonów. Żetony przydziela kierownik ośrodka.</div>
<?php else: ?>
<div class="row g-2 mb-3">
  <?php foreach ($rk_pools as $p): ?>
  <div class="col-12 col-sm-6 col-lg-4">
    <div class="card h-100 border-0 shadow-sm" style="border-left:4px solid <?= h($p['color'] ?: '#6366f1') ?>!important">
      <div class="card-body py-2 px-3">
        <div class="d-flex align-items-baseline gap-2">
          <span class="fw-semibold"><?= h($p['name']) ?></span>
          <span class="ms-auto fs-5 fw-bold <?= (int)$p['available'] > 0 ? 'text-success' : 'text-body-secondary' ?>"><?= (int)$p['available'] ?></span>
        </div>
        <div class="text-body-secondary" style="font-size:.78rem">
          przyznane <?= (int)$p['granted'] ?> · wydane <?= (int)$p['spent'] ?>
          <?php if ((int)$p['held'] > 0): ?> · zablokowane <?= (int)$p['held'] ?><?php endif; ?>
          <?php if ($p['valid_to']): ?><br>ważne do <?= h($p['valid_to']) ?><?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Zapisy: tura → prowadzący → termin ────────────────────────────────── -->
<h2 class="h6 fw-bold mt-4 mb-2"><i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Zapisy na zajęcia</h2>

<?php if (!$rk_rounds): ?>
<div class="text-body-secondary small mb-3">
  <i class="bi bi-inbox me-1" aria-hidden="true"></i>Obecnie nie trwa żadna tura zapisów.
  O starcie kolejnej poinformujemy Cię e-mailem.
</div>
<?php else: ?>

<?php if (!$rk_round): ?>
<div class="d-flex flex-column gap-2 mb-3">
  <?php foreach ($rk_rounds as $r):
    $r_open = $r['status'] === 'open' && strtotime((string)$r['opens_at']) <= time();
  ?>
  <div class="card border-0 shadow-sm">
    <div class="card-body py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
      <div>
        <div class="fw-semibold"><?= h($r['name']) ?></div>
        <div class="text-body-secondary" style="font-size:.8rem">
          <?php if ($r_open): ?>
            zapisy trwają<?= $r['closes_at'] ? ' do ' . h(rk_fmt_dt((string)$r['closes_at'])) : '' ?>
          <?php else: ?>
            start zapisów: <?= h(rk_fmt_dt((string)$r['opens_at'])) ?>
          <?php endif; ?>
          · wolnych terminów: <?= (int)$r['n_free'] ?>
          <?php if ((int)$r['max_per_client'] > 0): ?> · limit: <?= (int)$r['n_mine'] ?>/<?= (int)$r['max_per_client'] ?><?php endif; ?>
        </div>
      </div>
      <div class="ms-auto">
        <?php if ($r_open): ?>
        <a class="btn btn-sm btn-primary" href="index.php?tab=zapisy&rk_round=<?= (int)$r['id'] ?>">
          Wybierz prowadzącego <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
        </a>
        <?php else: ?>
        <span class="badge text-bg-secondary">wkrótce</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php elseif (!$rk_instr_id): ?>
<nav aria-label="Ścieżka zapisów" class="mb-2" style="font-size:.85rem">
  <a href="index.php?tab=zapisy">Tury</a> &rsaquo; <strong><?= h($rk_round['name']) ?></strong>
</nav>
<?php if (!$rk_instructors): ?>
<div class="text-body-secondary small mb-3">Żaden prowadzący nie wystawił jeszcze terminów w tej turze.</div>
<?php else: ?>
<div class="row g-2 mb-3">
  <?php foreach ($rk_instructors as $i): $free = (int)$i['slots_free']; ?>
  <div class="col-12 col-sm-6 col-lg-4">
    <div class="card h-100 border-0 shadow-sm <?= $free ? '' : 'opacity-50' ?>">
      <div class="card-body py-2 px-3">
        <div class="fw-semibold"><i class="bi bi-person-circle me-1 text-primary" aria-hidden="true"></i><?= h($i['name']) ?></div>
        <div class="text-body-secondary" style="font-size:.8rem">
          <?php if ($free): ?>
            wolne terminy: <?= $free ?> · najbliższy <?= h(rk_fmt_dt((string)$i['next_free_at'])) ?>
            · od <?= (int)$i['min_cost'] ?> żet.
          <?php else: ?>
            brak wolnych terminów
          <?php endif; ?>
        </div>
        <?php if ($free): ?>
        <a class="btn btn-sm btn-outline-primary mt-2"
           href="index.php?tab=zapisy&rk_round=<?= (int)$rk_round['id'] ?>&rk_instr=<?= (int)$i['id'] ?>">
          Zobacz terminy
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php else: ?>
<nav aria-label="Ścieżka zapisów" class="mb-2" style="font-size:.85rem">
  <a href="index.php?tab=zapisy">Tury</a> &rsaquo;
  <a href="index.php?tab=zapisy&rk_round=<?= (int)$rk_round['id'] ?>"><?= h($rk_round['name']) ?></a> &rsaquo;
  <strong><?= h($rk_instr_name ?: 'Prowadzący') ?></strong>
</nav>
<?php if (!$rk_slots): ?>
<div class="text-body-secondary small mb-3">Ten prowadzący nie ma teraz wolnych terminów.
  <a href="index.php?tab=zapisy&rk_round=<?= (int)$rk_round['id'] ?>">Wybierz innego prowadzącego</a>.</div>
<?php else: ?>
<div class="alert alert-light border py-2 small mb-2" role="note">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
  <strong>„Rezerwuję”</strong> zapisuje na pojedyncze zajęcia.
  <strong>„Ustal zajęcia na cały okres”</strong> rezerwuje ten sam dzień tygodnia i godzinę
  co tydzień, do końca tury — <strong>wybrana data to data pierwszych zajęć</strong>.
</div>
<div class="table-responsive mb-3">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Wolne terminy prowadzącego <?= h($rk_instr_name) ?></caption>
    <thead>
      <tr>
        <th scope="col">Termin</th>
        <th scope="col">Forma</th>
        <th scope="col">Temat</th>
        <th scope="col" class="text-end">Miejsca</th>
        <th scope="col" class="text-end">Koszt</th>
        <th scope="col" class="text-end"><span class="visually-hidden">Akcja</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rk_slots as $s): $can = $rk_avail >= (int)$s['token_cost']; ?>
      <tr>
        <td>
          <strong><?= h(rk_fmt_dt((string)$s['starts_at'])) ?></strong>
          <span class="text-body-secondary">– <?= h(substr((string)$s['ends_at'], 11, 5)) ?></span>
        </td>
        <td><?= h($rk_mode_label((string)$s['mode'])) ?><?= $s['room_name'] ? ', ' . h($s['room_name']) : '' ?></td>
        <td><?= $s['subject_label'] ? h($s['subject_label']) : '<span class="text-body-secondary">konsultacja</span>' ?></td>
        <td class="text-end"><?= (int)$s['seats_free'] ?>/<?= (int)$s['capacity'] ?></td>
        <td class="text-end fw-semibold"><?= (int)$s['token_cost'] ?> żet.</td>
        <td class="text-end text-nowrap">
          <form method="post" class="d-inline"
                onsubmit="return confirm('Zapisać się na termin <?= h(rk_fmt_dt((string)$s['starts_at'])) ?> (koszt: <?= (int)$s['token_cost'] ?> żet.)?')">
            <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
            <input type="hidden" name="_op" value="rk_book">
            <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="rk_round" value="<?= (int)$rk_round['id'] ?>">
            <input type="hidden" name="rk_instr" value="<?= (int)$rk_instr_id ?>">
            <button class="btn btn-sm btn-primary" <?= $can ? '' : 'disabled title="Za mało żetonów"' ?>>
              <i class="bi bi-check2 me-1" aria-hidden="true"></i>Rezerwuję
            </button>
          </form>
          <form method="post" class="d-inline"
                onsubmit="return confirm('Zarezerwować ten termin CO TYDZIEŃ (ten sam dzień i godzina) na wszystkie zajęcia do końca tury? Żetony zostaną pobrane za komplet — przy braku pokrycia nic nie zostanie zarezerwowane.')">
            <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
            <input type="hidden" name="_op" value="rk_book_series">
            <input type="hidden" name="slot_id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="rk_round" value="<?= (int)$rk_round['id'] ?>">
            <input type="hidden" name="rk_instr" value="<?= (int)$rk_instr_id ?>">
            <button class="btn btn-sm btn-outline-primary"
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

<?php endif; ?>

<!-- ── Moje rezerwacje ───────────────────────────────────────────────────── -->
<h2 class="h6 fw-bold mt-4 mb-2"><i class="bi bi-bookmark-check me-1" aria-hidden="true"></i>Moje rezerwacje</h2>
<?php if (!$rk_bookings): ?>
<div class="text-body-secondary small mb-3">Nie masz jeszcze żadnych rezerwacji.</div>
<?php else: ?>
<div class="table-responsive mb-3">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Lista rezerwacji</caption>
    <thead>
      <tr>
        <th scope="col">Termin</th>
        <th scope="col">Prowadzący</th>
        <th scope="col">Tura</th>
        <th scope="col" class="text-end">Żetony</th>
        <th scope="col">Status</th>
        <th scope="col" class="text-end"><span class="visually-hidden">Akcja</span></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rk_bookings as $b):
        $future     = strtotime((string)$b['starts_at']) > time();
        $hours_left = (strtotime((string)$b['starts_at']) - time()) / 3600;
        $full_ref   = $hours_left >= (int)$b['refund_hours'];
        [$st_label, $st_class] = match ((string)$b['status']) {
            'confirmed'         => $future ? ['zarezerwowane', 'primary'] : ['odbyte?', 'secondary'],
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
        <td>
          <strong><?= h(rk_fmt_dt((string)$b['starts_at'])) ?></strong>
          <div class="text-body-secondary" style="font-size:.78rem">
            <?= h($rk_mode_label((string)$b['mode'])) ?><?= $b['room_name'] ? ', ' . h($b['room_name']) : '' ?>
            <?= $b['subject_label'] ? '· ' . h($b['subject_label']) : '' ?>
          </div>
        </td>
        <td><?= h($b['instructor_name']) ?></td>
        <td class="text-body-secondary" style="font-size:.85rem"><?= h($b['round_name']) ?></td>
        <td class="text-end">
          <?= (int)$b['tokens_spent'] ?>
          <?php if ((int)$b['tokens_refunded'] > 0): ?>
            <span class="text-success" style="font-size:.78rem">(zwrot <?= (int)$b['tokens_refunded'] ?>)</span>
          <?php endif; ?>
        </td>
        <td><span class="badge text-bg-<?= $st_class ?>"><?= h($st_label) ?></span></td>
        <td class="text-end">
          <?php if (in_array($b['status'], ['confirmed','pending_parent'], true) && $future): ?>
          <form method="post" class="d-inline"
                onsubmit="return confirm('<?= ($full_ref || $b['status'] === 'pending_parent')
                    ? 'Zrezygnować z terminu? Żetony wrócą w całości.'
                    : 'Uwaga: termin jest bliżej niż ' . (int)$b['refund_hours'] . ' h — żetony mogą nie zostać zwrócone. Zrezygnować?' ?>')">
            <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
            <input type="hidden" name="_op" value="rk_cancel">
            <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">
              <i class="bi bi-x-lg me-1" aria-hidden="true"></i>Rezygnuję
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ── Historia żetonów ──────────────────────────────────────────────────── -->
<h2 class="h6 fw-bold mt-4 mb-2"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Historia żetonów</h2>
<?php if (!$rk_txns): ?>
<div class="text-body-secondary small mb-3">Brak operacji na żetonach.</div>
<?php else: ?>
<div class="table-responsive mb-4">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Historia operacji na żetonach</caption>
    <thead>
      <tr>
        <th scope="col">Data</th>
        <th scope="col">Pula</th>
        <th scope="col">Operacja</th>
        <th scope="col" class="text-end">Żetony</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rk_txns as $t): $credit = $t['direction'] === 'credit'; ?>
      <tr>
        <td class="text-body-secondary" style="font-size:.85rem"><?= h(rk_fmt_dt((string)$t['created_at'])) ?></td>
        <td><span class="badge" style="background:<?= h($t['pool_color'] ?: '#6366f1') ?>"><?= h($t['pool_name']) ?></span></td>
        <td style="font-size:.9rem"><?= h(str_replace('_', ' ', (string)$t['reason'])) ?></td>
        <td class="text-end fw-semibold <?= $credit ? 'text-success' : 'text-danger' ?>">
          <?= $credit ? '+' : '−' ?><?= (int)$t['amount'] ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

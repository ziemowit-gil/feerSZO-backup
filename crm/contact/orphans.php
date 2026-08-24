<?php
/**
 * crm/contact/orphans.php — raport kartotek „nie wiadomo skąd".
 *
 * Baza CRM puchnie od rekordów, których nikt nie pamięta: wrzucone importem bez
 * opisu źródła, dociągnięte automatem przy jednorazowym zdarzeniu, założone
 * „na chwilę" i porzucone. Nie da się ich sensownie usunąć, dopóki nie widać,
 * CZYM one właściwie są — a przy tysiącu kontaktów nikt nie przejrzy ich ręcznie.
 *
 * Raport liczy SYGNAŁY osierocenia (każdy wart 1 punkt):
 *   • brak wskazanego źródła (source puste albo „import"),
 *   • brak autora wpisu (created_by — tak zapisują się rekordy z cronów i importów),
 *   • brak opiekuna,
 *   • nie należy do żadnej grupy,
 *   • nie ma żadnego tagu,
 *   • nigdy nie było korespondencji,
 *   • brak spraw, ofert, darowizn i notatek,
 *   • brak danych kontaktowych (ani e-maila, ani telefonu).
 *
 * Im więcej punktów, tym pewniej rekord jest śmieciem — ale decyzję zostawiamy
 * człowiekowi: raport nic nie kasuje sam z siebie. Dostępne działania to
 * przypisanie opiekuna (ktoś się tym zajmie) i archiwizacja (crm_active=0),
 * czyli usunięcie z widoku BEZ utraty danych i historii.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_perms.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('contacts', 'read');
crm_migrate();

$PAGE_TITLE = 'Kartoteki bez pochodzenia';
$can_write  = crm_can('contacts', 'write');
$uid        = (int)(current_user()['id'] ?? 0);

$min_score = max(2, min(8, (int)($_GET['min'] ?? 4)));
$per       = 50;
$page      = max(1, (int)($_GET['page'] ?? 1));

// ── Działania zbiorcze ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op  = (string)($_POST['_op'] ?? '');
    $ids = array_slice(array_unique(array_map('intval', (array)($_POST['pick'] ?? []))), 0, 500);
    $ids = array_values(array_filter($ids, static fn($i) => $i > 0 && crm_can_access_contact($i)));

    if (!$ids) {
        flash_set('warning', 'Nie zaznaczono żadnej kartoteki.');
    } elseif ($op === 'assign') {
        $owner = (int)($_POST['owner_id'] ?? 0) ?: $uid;
        $n = 0;
        foreach ($ids as $i) { CrmManager::updateContact($i, ['owner_id' => $owner]); $n++; }
        flash_set('success', "Przypisano opiekuna do {$n} kartotek — teraz ktoś je przejrzy.");
    } elseif ($op === 'archive') {
        $n = 0;
        foreach ($ids as $i) {
            try {
                db()->prepare("UPDATE crm_contacts SET crm_active=0, updated_at=? WHERE id=?")
                    ->execute([date('Y-m-d H:i:s'), $i]);
                // Ślad, żeby za pół roku dało się odtworzyć, czemu rekord zniknął z listy
                db_insert('crm_notes', [
                    'contact_id' => $i,
                    'body'       => 'Zarchiwizowano z raportu „kartoteki bez pochodzenia" — rekord bez źródła, '
                                  . 'powiązań i historii kontaktu.',
                    'created_by' => $uid ?: null,
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $n++;
            } catch (\Throwable $e) {}
        }
        flash_set('success', "Zarchiwizowano {$n} kartotek — dane zostają, znikają tylko z list.");
    }
    header('Location: ' . APP_URL . '/crm/contact/orphans.php?min=' . $min_score); exit;
}

// ── Zapytanie: sygnały liczone w SQL, żeby dało się po nich sortować ────────
$score_sql = "(
      (CASE WHEN COALESCE(c.source,'') IN ('', 'import', 'unknown') THEN 1 ELSE 0 END)
    + (CASE WHEN c.created_by IS NULL THEN 1 ELSE 0 END)
    + (CASE WHEN c.owner_id  IS NULL OR c.owner_id = 0 THEN 1 ELSE 0 END)
    + (CASE WHEN NOT EXISTS (SELECT 1 FROM crm_group_members g WHERE g.contact_id = c.id) THEN 1 ELSE 0 END)
    + (CASE WHEN NOT EXISTS (SELECT 1 FROM crm_tags t        WHERE t.contact_id = c.id) THEN 1 ELSE 0 END)
    + (CASE WHEN NOT EXISTS (SELECT 1 FROM crm_communications m WHERE m.contact_id = c.id) THEN 1 ELSE 0 END)
    + (CASE WHEN NOT EXISTS (SELECT 1 FROM crm_cases k        WHERE k.contact_id = c.id) THEN 1 ELSE 0 END)
    + (CASE WHEN NOT EXISTS (SELECT 1 FROM crm_notes n        WHERE n.contact_id = c.id) THEN 1 ELSE 0 END)
    + (CASE WHEN COALESCE(c.email,'') = '' AND COALESCE(c.telefon,'') = '' THEN 1 ELSE 0 END)
)";

$where  = "c.crm_active = 1";
$params = [];

try {
    $total = (int)(db_one("SELECT COUNT(*) AS n FROM crm_contacts c
                            WHERE {$where} AND {$score_sql} >= ?", [$min_score])['n'] ?? 0);
} catch (\Throwable $e) { $total = 0; }

$offset = ($page - 1) * $per;
try {
    $rows = db_all(
        "SELECT c.id, c.imie_nazwisko, c.type, c.status, c.email, c.telefon, c.source,
                c.created_at, c.created_by, c.owner_id,
                {$score_sql} AS score,
                (SELECT COUNT(*) FROM crm_group_members g WHERE g.contact_id = c.id)    AS n_groups,
                (SELECT COUNT(*) FROM crm_communications m WHERE m.contact_id = c.id)   AS n_msgs,
                (SELECT COUNT(*) FROM crm_cases k WHERE k.contact_id = c.id)            AS n_cases,
                (SELECT COUNT(*) FROM crm_notes n WHERE n.contact_id = c.id)            AS n_notes,
                u.name AS creator_name
           FROM crm_contacts c
      LEFT JOIN users u ON u.id = c.created_by
          WHERE {$where} AND {$score_sql} >= ?
       ORDER BY score DESC, c.created_at ASC
          LIMIT {$per} OFFSET {$offset}", [$min_score]
    );
} catch (\Throwable $e) { $rows = []; }

// Rozkład punktacji — pokazuje skalę problemu bez wchodzenia w listę
$dist = [];
try {
    foreach (db_all("SELECT {$score_sql} AS s, COUNT(*) AS n FROM crm_contacts c
                      WHERE {$where} GROUP BY s ORDER BY s DESC") as $d) {
        $dist[(int)$d['s']] = (int)$d['n'];
    }
} catch (\Throwable $e) {}

$users = [];
try { $users = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name"); } catch (\Throwable $e) {}

include dirname(__DIR__) . '/includes/header_crm.php';
?>
<style>
.or-card { background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1rem 1.15rem;margin-bottom:.9rem }
.or-tbl { width:100%;border-collapse:collapse;font-size:.84rem }
.or-tbl th { font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:#9CA3AF;text-align:left;
  padding:.45rem .5rem;border-bottom:1px solid #E5E7EB }
.or-tbl td { padding:.5rem;border-bottom:1px solid #F3F4F6;vertical-align:top }
.or-score { display:inline-block;min-width:1.6rem;text-align:center;font-weight:700;font-size:.74rem;
  border-radius:2rem;padding:.05rem .4rem }
.or-sig { font-size:.7rem;color:#9CA3AF }
.or-dist { display:flex;gap:.3rem;flex-wrap:wrap }
.or-dist a { font-size:.74rem;padding:.2rem .6rem;border-radius:2rem;border:1px solid #E5E7EB;
  text-decoration:none;color:#374151;background:#fff }
.or-dist a.is-on { border-color:var(--crm-primary);color:var(--crm-primary);font-weight:600 }
</style>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-question-diamond me-2" style="color:#B45309"></i>Kartoteki bez pochodzenia</h1>
  <span class="text-muted small">rekordy bez źródła, powiązań i historii — kandydaci do przeglądu</span>
  <a class="btn btn-crm-ghost btn-sm ms-auto" href="<?= APP_URL ?>/crm/index.php">
    <i class="bi bi-arrow-left me-1"></i>Kartoteka
  </a>
</div>

<div class="or-card">
  <p class="mb-2" style="font-size:.86rem">
    Każda kartoteka dostaje punkt za brakujący ślad pochodzenia: źródło, autora wpisu, opiekuna,
    grupę, tag, korespondencję, sprawę, notatkę i dane kontaktowe. <strong>Maksimum to 9</strong>.
    Wysoki wynik nie znaczy „usuń" — znaczy „nikt nie wie, po co to tu jest".
  </p>
  <div class="or-dist">
    <?php for ($sv = 8; $sv >= 2; $sv--): $n = (int)($dist[$sv] ?? 0); if (!$n && $sv !== $min_score) continue; ?>
    <a href="?min=<?= $sv ?>" class="<?= $min_score === $sv ? 'is-on' : '' ?>">
      od <?= $sv ?> pkt · <?= $n ?>
    </a>
    <?php endfor; ?>
  </div>
</div>

<?php if (!$rows): ?>
<div class="or-card text-muted small">
  Przy progu <?= $min_score ?> punktów nie ma żadnej kartoteki — to dobry znak.
  Obniż próg, jeśli chcesz obejrzeć rekordy z mniejszą liczbą braków.
</div>
<?php else: ?>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="or-card">
    <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
      <strong style="font-size:.9rem">Znaleziono: <?= (int)$total ?></strong>
      <span class="text-muted small">próg: <?= $min_score ?> pkt</span>

      <?php if ($can_write): ?>
      <div class="ms-auto d-flex align-items-center gap-2 flex-wrap">
        <select name="owner_id" class="form-select form-select-sm" style="max-width:200px" aria-label="Opiekun">
          <option value="<?= $uid ?>">— przypisz mnie —</option>
          <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-crm-outline btn-sm" name="_op" value="assign">
          <i class="bi bi-person-check me-1"></i>Przypisz opiekuna
        </button>
        <button class="btn btn-outline-danger btn-sm" name="_op" value="archive"
                onclick="return confirm('Zarchiwizować zaznaczone kartoteki? Dane i historia zostają — rekordy znikają tylko z list.')">
          <i class="bi bi-archive me-1"></i>Archiwizuj
        </button>
      </div>
      <?php endif; ?>
    </div>

    <div class="table-responsive">
      <table class="or-tbl">
        <thead>
          <tr>
            <?php if ($can_write): ?><th style="width:28px"><input type="checkbox" id="orAll" aria-label="Zaznacz wszystkie"></th><?php endif; ?>
            <th style="width:60px">Punkty</th>
            <th>Kartoteka</th>
            <th>Czego brakuje</th>
            <th style="width:130px">Dodano</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
            $sig = [];
            if (in_array((string)($r['source'] ?? ''), ['', 'import', 'unknown'], true)) $sig[] = 'źródło';
            if (empty($r['created_by']))  $sig[] = 'autor wpisu';
            if (empty($r['owner_id']))    $sig[] = 'opiekun';
            if (!(int)$r['n_groups'])     $sig[] = 'grupa';
            if (!(int)$r['n_msgs'])       $sig[] = 'korespondencja';
            if (!(int)$r['n_cases'])      $sig[] = 'sprawy';
            if (!(int)$r['n_notes'])      $sig[] = 'notatki';
            if (($r['email'] ?? '') === '' && ($r['telefon'] ?? '') === '') $sig[] = 'dane kontaktowe';
            $sc = (int)$r['score'];
            $col = $sc >= 7 ? ['#FEF2F2', '#B91C1C'] : ($sc >= 5 ? ['#FFF7ED', '#B45309'] : ['#F3F4F6', '#6B7280']);
          ?>
          <tr>
            <?php if ($can_write): ?>
            <td><input type="checkbox" class="or-pick" name="pick[]" value="<?= (int)$r['id'] ?>"
                       aria-label="Zaznacz <?= h($r['imie_nazwisko']) ?>"></td>
            <?php endif; ?>
            <td>
              <span class="or-score" style="background:<?= $col[0] ?>;color:<?= $col[1] ?>"><?= $sc ?>/9</span>
            </td>
            <td>
              <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$r['id'] ?>"
                 style="font-weight:600;text-decoration:none"><?= h($r['imie_nazwisko']) ?></a>
              <div class="or-sig">
                <?= h(CRM_CONTACT_TYPES[$r['type']]['label'] ?? $r['type']) ?>
                <?= $r['email'] ? ' · ' . h($r['email']) : '' ?>
                <?= $r['telefon'] ? ' · ' . h($r['telefon']) : '' ?>
                <?= !empty($r['source']) ? ' · źródło: ' . h($r['source']) : '' ?>
              </div>
            </td>
            <td class="or-sig"><?= h(implode(', ', $sig)) ?></td>
            <td class="or-sig">
              <?= h(date('d.m.Y', strtotime((string)$r['created_at']))) ?>
              <?php if (!empty($r['creator_name'])): ?><br><?= h($r['creator_name']) ?><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($total > $per): ?>
    <div class="d-flex justify-content-between align-items-center mt-2">
      <small class="text-muted">Strona <?= $page ?> z <?= (int)ceil($total / $per) ?></small>
      <div class="d-flex gap-1">
        <?php for ($p = 1, $pages = min(12, (int)ceil($total / $per)); $p <= $pages; $p++): ?>
        <a href="?min=<?= $min_score ?>&page=<?= $p ?>"
           class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</form>

<script>
document.getElementById('orAll')?.addEventListener('change', function () {
  document.querySelectorAll('.or-pick').forEach(function (c) { c.checked = this.checked; }, this);
});
</script>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>

<?php
/**
 * strategy/objectives/index.php — Lista wszystkich celów strategicznych.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/strategy.php';

require_login();
$PAGE_TITLE = 'Cele strategiczne';

$filter_status = $_GET['status'] ?? '';
$filter_sphere = (int)($_GET['sphere_id'] ?? 0);

$where  = ['1=1'];
$params = [];
if ($filter_status) { $where[] = "o.status=?"; $params[] = $filter_status; }
if ($filter_sphere) { $where[] = "o.sphere_id=?"; $params[] = $filter_sphere; }

try {
    $objectives = db_all(
        "SELECT o.*, s.nazwa AS sphere_nazwa, s.kolor AS sphere_kolor, s.kod AS sphere_kod,
                p.wartosc_realizowana, p.budzet_wydany, p.budzet_przypisany
         FROM strategy_objectives o
         LEFT JOIN public_benefit_spheres s ON s.id=o.sphere_id
         LEFT JOIN (
             SELECT * FROM strategy_progress WHERE id IN
             (SELECT MAX(id) FROM strategy_progress GROUP BY objective_id)
         ) p ON p.objective_id=o.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY o.status='aktywny' DESC, s.sort_order, o.waga DESC, o.id",
        $params
    );
} catch (\Throwable $e) { $objectives = []; }

$spheres = db_all("SELECT id, kod, nazwa FROM public_benefit_spheres WHERE is_active=1 ORDER BY sort_order");

include dirname(__DIR__) . '/includes/header_strategy.php';
?>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <h1 style="font-size:1.35rem;font-weight:800;margin:0">
    <i class="bi bi-bullseye me-2" style="color:var(--strat-accent)"></i>Cele strategiczne
  </h1>
  <?php if (can_edit() || is_admin()): ?>
  <a href="<?= APP_URL ?>/strategy/objectives/view.php?new=1" class="btn btn-sm ms-auto"
     style="background:var(--strat-accent);color:#fff;border:none">
    <i class="bi bi-plus-lg me-1"></i>Nowy cel
  </a>
  <?php endif; ?>
</div>

<!-- Filtry -->
<div class="card shadow-sm mb-4">
  <div class="card-body py-2">
    <form method="get" class="d-flex gap-2 flex-wrap align-items-end">
      <div>
        <label class="form-label fw-semibold small mb-1">Status</label>
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">Wszystkie</option>
          <?php foreach(['aktywny'=>'Aktywny','wstrzymany'=>'Wstrzymany','zakończony'=>'Zakończony'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $filter_status===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label fw-semibold small mb-1">Sfera</label>
        <select name="sphere_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="0">Wszystkie sfery</option>
          <?php foreach($spheres as $s): ?>
          <option value="<?= $s['id'] ?>" <?= $filter_sphere==(int)$s['id']?'selected':'' ?>>
            <?= h($s['kod']) ?> — <?= h($s['nazwa']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($filter_status || $filter_sphere): ?>
      <a href="<?= APP_URL ?>/strategy/objectives/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-x"></i> Reset
      </a>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if (!$objectives): ?>
<div class="card shadow-sm border-0 text-center py-5">
  <i class="bi bi-bullseye d-block mb-2" style="font-size:3rem;color:#cbd5e1"></i>
  <div class="fw-bold">Brak celów</div>
  <?php if (can_edit()): ?>
  <div class="mt-2">
    <a href="<?= APP_URL ?>/strategy/objectives/view.php?new=1" class="btn btn-primary btn-sm"
       style="background:var(--strat-accent);border-color:var(--strat-accent)">
      Dodaj pierwszy cel →
    </a>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="card shadow-sm">
<table class="table table-hover align-middle mb-0" style="font-size:.86rem">
  <thead class="table-light">
    <tr>
      <th>Cel</th>
      <th style="width:120px">Sfera</th>
      <th style="width:100px">Postęp</th>
      <th style="width:80px" class="text-center">Health</th>
      <th style="width:100px">Data do</th>
      <th style="width:60px" class="text-center">Pow.</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach($objectives as $o):
        $score  = strategy_health_score($o);
        $pct    = min(100,(float)($o['wartosc_realizowana']??0) > 0 && ($o['wartosc_docelowa']??0) > 0
            ? round($o['wartosc_realizowana']/$o['wartosc_docelowa']*100,1) : 0);
        $colors = ['green'=>'#16a34a','yellow'=>'#d97706','red'=>'#dc2626'];
    ?>
    <tr>
      <td>
        <a href="<?= APP_URL ?>/strategy/objectives/view.php?id=<?= $o['id'] ?>"
           class="fw-semibold text-decoration-none text-dark">
          <?= h($o['nazwa']) ?>
        </a>
        <?php if ($o['cel_miernika']): ?>
        <div class="text-muted" style="font-size:.72rem"><?= h(mb_substr($o['cel_miernika'],0,60)) ?></div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($o['sphere_kolor']): ?>
        <span class="badge" style="background:<?= h($o['sphere_kolor']) ?>;font-size:.68rem">
          <?= h($o['sphere_kod']) ?>
        </span>
        <span class="d-none d-md-inline text-muted small ms-1"><?= h(mb_substr($o['sphere_nazwa']??'',0,18)) ?></span>
        <?php else: ?>
        <span class="text-muted small">—</span>
        <?php endif; ?>
      </td>
      <td>
        <div class="progress" style="height:6px">
          <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $colors[$score] ?>"></div>
        </div>
        <div class="text-muted text-end" style="font-size:.7rem"><?= number_format($pct,1) ?>%</div>
      </td>
      <td class="text-center"><?= strategy_health_badge($score) ?></td>
      <td>
        <?php if ($o['data_do']):
            $d = (int)round((strtotime($o['data_do'])-time())/86400);
        ?>
        <span class="<?= $d<0?'text-danger':($d<=14?'text-warning':'text-muted') ?>" style="font-size:.8rem">
          <?= date_pl($o['data_do']) ?>
          <?php if ($d<=30): ?>
          <span class="badge <?= $d<0?'bg-danger':($d<=14?'bg-warning text-dark':'bg-secondary') ?>"
                style="font-size:.62rem"><?= $d<0?'po term.':"{$d}d" ?></span>
          <?php endif; ?>
        </span>
        <?php else: ?>
        <span class="text-muted small">—</span>
        <?php endif; ?>
      </td>
      <td class="text-center">
        <a href="<?= APP_URL ?>/strategy/objectives/view.php?id=<?= $o['id'] ?>"
           class="btn btn-sm btn-outline-secondary py-0 px-2">
          <i class="bi bi-eye"></i>
        </a>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<div class="text-muted small mt-2"><?= count($objectives) ?> celów</div>
<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer_strategy.php'; ?>

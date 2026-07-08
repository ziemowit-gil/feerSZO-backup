<?php
/**
 * Przerejestrowanie sprawy do Nowego JRWA — asystent AI (Claude).
 *
 * Użytkownik podaje opis sprawy + opcjonalnie stary znak i formę prowadzenia;
 * AI kwalifikuje sprawę do klasy JRWA i generuje trzy sekcje:
 *   1. Kwalifikacja i metadane SZO (kod, forma, adnotacje)
 *   2. Fizyczna aktualizacja dokumentacji (instrukcja stanowiskowa)
 *   3. Wpis do protokołu przerejestrowania (wiersz Markdown)
 * Zatwierdzony wynik trafia do rejestru ezd_przerejestrowania (protokół).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_ai.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
if (!can_edit()) { flash_set('error', 'Brak uprawnień.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit; }

$wynik  = null;   // wynik analizy AI (op=analyze)
$in     = ['opis' => '', 'stary_znak' => '', 'forma' => 'auto'];

// Kontekst istniejącej koszulki (?id=N lub z formularza) — prefill + faktyczne przeniesienie
$sprawa_id = (int)($_GET['id'] ?? $_POST['sprawa_id'] ?? 0);
$sprawa    = $sprawa_id ? ezd_sprawa_get($sprawa_id) : null;
if ($sprawa_id && !$sprawa) { flash_set('danger', 'Koszulka nie istnieje.'); }
if ($sprawa && !ezd_sprawa_access($sprawa, (int)current_user()['id'])) {
    flash_set('error', 'Brak dostępu do tej koszulki.'); header('Location: ' . APP_URL . '/ezd/sprawy/index.php'); exit;
}

// Prefill z danych sprawy przy wejściu GET
if ($sprawa && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $in['opis']       = trim($sprawa['title'] . "\n" . ($sprawa['description'] ?? ''));
    $in['stary_znak'] = (string)$sprawa['znak_sprawy'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $uid = (int)current_user()['id'];

    if ($op === 'analyze') {
        $in['opis']       = trim($_POST['opis'] ?? '');
        $in['stary_znak'] = trim($_POST['stary_znak'] ?? '');
        $in['forma']      = in_array($_POST['forma'] ?? '', ['papierowa', 'elektroniczna'], true) ? $_POST['forma'] : 'auto';
        if ($in['opis'] === '') {
            flash_set('danger', 'Opisz sprawę — na tej podstawie AI zaproponuje klasę JRWA.');
        } else {
            $r = ezd_ai_przerejestruj($in['opis'], $in['stary_znak'], $in['forma']);
            if ($r['ok']) $wynik = $r['data'];
            else          flash_set('danger', $r['error']);
        }
    }

    if ($op === 'save' || $op === 'apply') {
        $d = json_decode((string)($_POST['payload'] ?? ''), true);
        if (!is_array($d) || empty($d['nowy_znak']) || empty($d['kod_jrwa'])) {
            flash_set('danger', 'Brak danych do zapisania — wykonaj najpierw analizę.');
            header('Location: przerejestruj.php' . ($sprawa_id ? '?id=' . $sprawa_id : '')); exit;
        }

        $zastosowano = 0;
        $nowy_znak   = (string)$d['nowy_znak'];

        // op=apply → faktyczne przeniesienie istniejącej koszulki do nowej klasy JRWA
        if ($op === 'apply') {
            if (!$sprawa) {
                flash_set('danger', 'Faktyczne przeniesienie wymaga istniejącej koszulki.');
                header('Location: przerejestruj.php'); exit;
            }
            $ap = ezd_sprawa_reregister($sprawa_id, (string)$d['kod_jrwa'], $uid);
            if (!$ap['ok']) {
                flash_set('danger', $ap['error']);
                header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id); exit;
            }
            $zastosowano = 1;
            $nowy_znak   = $ap['new_znak']; // realny znak nadany przez system (nie przykład z AI)
            $d['stary_znak'] = $ap['old_znak'];
        }

        db_insert('ezd_przerejestrowania', [
            'sprawa_id'       => $sprawa_id ?: null,
            'stary_znak'      => (string)($d['stary_znak'] ?? ''),
            'nowy_znak'       => $nowy_znak,
            'kod_jrwa'        => (string)$d['kod_jrwa'],
            'forma'           => (string)($d['forma'] ?? ''),
            'opis'            => (string)($d['opis'] ?? ''),
            'adnotacja_stara' => (string)($d['adnotacja_stara'] ?? ''),
            'adnotacja_nowa'  => (string)($d['adnotacja_nowa'] ?? ''),
            'instrukcja'      => json_encode((array)($d['instrukcja'] ?? []), JSON_UNESCAPED_UNICODE),
            'zastosowano'     => $zastosowano,
            'created_by'      => $uid,
        ]);

        if ($op === 'apply') {
            flash_set('success', 'Koszulka przerejestrowana: ' . ($d['stary_znak'] ?: '—') . ' → ' . $nowy_znak
                . ($ap['created_teczka'] ? ' (utworzono nowy segregator).' : '.'));
            header('Location: ' . APP_URL . '/ezd/sprawy/view.php?id=' . $sprawa_id); exit;
        }
        flash_set('success', 'Wpis dodany do protokołu przerejestrowania.');
        header('Location: przerejestruj.php' . ($sprawa_id ? '?id=' . $sprawa_id : '')); exit;
    }

    if ($op === 'delete') {
        db()->prepare("DELETE FROM ezd_przerejestrowania WHERE id=?")->execute([(int)($_POST['id'] ?? 0)]);
        flash_set('success', 'Wpis usunięty z protokołu.');
        header('Location: przerejestruj.php'); exit;
    }
}

$protokol = db_all(
    "SELECT p.*, u.name AS user_name, s.id AS sprawa_exists
     FROM ezd_przerejestrowania p
     LEFT JOIN users u ON u.id=p.created_by
     LEFT JOIN ezd_sprawy s ON s.id=p.sprawa_id
     ORDER BY p.id"
);

$PAGE_TITLE = 'Przerejestrowanie do Nowego JRWA';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/index.php">Koszulki</a></li>
  <li class="breadcrumb-item active">Przerejestrowanie (AI)</li>
</ol></nav>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-stars text-primary me-2"></i>Przerejestrowanie sprawy do Nowego JRWA</h4>
  <?php if (!ezd_ai_enabled()): ?>
  <span class="badge bg-secondary">AI nieaktywne — brak klucza API</span>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<div style="max-width:960px">

  <?php if ($sprawa): ?>
  <div class="alert alert-primary d-flex align-items-center gap-2 py-2 mb-4" style="font-size:.9rem">
    <i class="bi bi-link-45deg fs-5"></i>
    <div>Przerejestrowujesz istniejącą koszulkę
      <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$sprawa['id'] ?>" class="fw-bold"><?= h($sprawa['znak_sprawy']) ?></a>.
      Po analizie możesz <strong>zastosować</strong> nową klasę — koszulka zostanie przeniesiona i otrzyma nowy znak.</div>
  </div>
  <?php endif; ?>

  <!-- ── Formularz sprawy ──────────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-1-circle me-2 text-primary"></i>Opisz sprawę</div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_op" value="analyze">
        <?php if ($sprawa_id): ?><input type="hidden" name="sprawa_id" value="<?= $sprawa_id ?>"><?php endif; ?>
        <div class="mb-3">
          <label class="form-label fw-semibold small">Opis sprawy <span class="text-danger">*</span></label>
          <textarea name="opis" class="form-control" rows="3" required
                    placeholder="Np. Jednodniowa zbiórka żywności z udziałem 12 wolontariuszy-uczniów, bez porozumień pisemnych — oświadczenia, listy obecności i zgody rodziców."><?= h($in['opis']) ?></textarea>
        </div>
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label fw-semibold small">Stary znak sprawy <span class="text-secondary fw-normal">(opcjonalnie)</span></label>
            <input type="text" name="stary_znak" class="form-control" value="<?= h($in['stary_znak']) ?>" placeholder="np. WOL.5.2024">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Forma prowadzenia</label>
            <select name="forma" class="form-select">
              <option value="auto" <?= $in['forma'] === 'auto' ? 'selected' : '' ?>>Wydedukuj z opisu</option>
              <option value="papierowa" <?= $in['forma'] === 'papierowa' ? 'selected' : '' ?>>Papierowa (tradycyjna)</option>
              <option value="elektroniczna" <?= $in['forma'] === 'elektroniczna' ? 'selected' : '' ?>>Elektroniczna (EZD)</option>
            </select>
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <button class="btn btn-primary w-100" <?= ezd_ai_enabled() ? '' : 'disabled' ?>>
              <i class="bi bi-stars me-1"></i>Analizuj (AI)
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <?php if ($wynik): ?>
  <!-- ── 1. Kwalifikacja i metadane SZO ───────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-diagram-3 me-2 text-primary"></i>1. Kwalifikacja i metadane SZO</div>
    <div class="card-body">
      <div class="row g-3 mb-2">
        <div class="col-md-3">
          <div class="small text-secondary text-uppercase" style="font-size:.68rem;letter-spacing:.06em">Nowy kod JRWA</div>
          <div class="fs-4 fw-bold text-primary"><?= h($wynik['kod_jrwa']) ?></div>
          <div class="small"><?= h($wynik['kod_nazwa']) ?><?= $wynik['kat_arch'] !== '' ? ' <span class="badge bg-light text-dark border">kat. ' . h($wynik['kat_arch']) . '</span>' : '' ?></div>
        </div>
        <div class="col-md-3">
          <div class="small text-secondary text-uppercase" style="font-size:.68rem;letter-spacing:.06em">Forma prowadzenia</div>
          <div class="fw-semibold mt-1">
            <?php if (str_starts_with($wynik['forma'], 'Papierowa')): ?>
              <i class="bi bi-journal-text me-1 text-warning"></i><?= h($wynik['forma']) ?>
            <?php else: ?>
              <i class="bi bi-laptop me-1 text-info"></i><?= h($wynik['forma']) ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="col-md-6">
          <div class="small text-secondary text-uppercase" style="font-size:.68rem;letter-spacing:.06em">Uzasadnienie</div>
          <div class="small mt-1"><?= h($wynik['uzasadnienie']) ?></div>
        </div>
      </div>
      <hr>
      <div class="mb-2"><span class="text-secondary small">Adnotacja dla starej sprawy:</span><br>
        <code class="user-select-all">„<?= h($wynik['adnotacja_stara']) ?>"</code></div>
      <div><span class="text-secondary small">Adnotacja dla nowej sprawy:</span><br>
        <code class="user-select-all">„<?= h($wynik['adnotacja_nowa']) ?>"</code></div>
    </div>
  </div>

  <!-- ── 2. Fizyczna aktualizacja dokumentacji ────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check me-2 text-primary"></i>2. Fizyczna aktualizacja dokumentacji — instrukcja stanowiskowa</div>
    <div class="card-body">
      <ol class="mb-0">
        <?php foreach ((array)$wynik['instrukcja'] as $krok): ?>
        <li class="mb-1"><?= h($krok) ?></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>

  <?php
    $payloadJson = json_encode([
        'stary_znak'      => $in['stary_znak'],
        'nowy_znak'       => $wynik['nowy_znak'],
        'kod_jrwa'        => $wynik['kod_jrwa'],
        'forma'           => $wynik['forma'],
        'opis'            => $in['opis'],
        'adnotacja_stara' => $wynik['adnotacja_stara'],
        'adnotacja_nowa'  => $wynik['adnotacja_nowa'],
        'instrukcja'      => $wynik['instrukcja'],
    ], JSON_UNESCAPED_UNICODE);
  ?>
  <!-- ── 3. Wpis do protokołu ─────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between gap-2 flex-wrap">
      <span><i class="bi bi-table me-2 text-primary"></i>3. Wpis do protokołu przerejestrowania</span>
      <div class="d-flex gap-2">
        <form method="post" class="mb-0">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="save">
          <?php if ($sprawa_id): ?><input type="hidden" name="sprawa_id" value="<?= $sprawa_id ?>"><?php endif; ?>
          <input type="hidden" name="payload" value="<?= h($payloadJson) ?>">
          <button class="btn btn-outline-success btn-sm"><i class="bi bi-journal-plus me-1"></i>Zapisz do protokołu</button>
        </form>
        <?php if ($sprawa): ?>
        <form method="post" class="mb-0"
              onsubmit="return confirm('Przenieść koszulkę <?= h($sprawa['znak_sprawy']) ?> do klasy JRWA <?= h($wynik['kod_jrwa']) ?>? Koszulka otrzyma NOWY znak sprawy. Operacja nieodwracalna.')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="apply">
          <input type="hidden" name="sprawa_id" value="<?= $sprawa_id ?>">
          <input type="hidden" name="payload" value="<?= h($payloadJson) ?>">
          <button class="btn btn-success btn-sm"><i class="bi bi-arrow-left-right me-1"></i>Zastosuj — przenieś koszulkę</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
    <?php if ($sprawa): ?>
    <div class="px-3 pt-2 small text-secondary"><i class="bi bi-info-circle me-1"></i>Przy „Zastosuj" nowy znak nada system wg schematu <code>SYMBOL.numer.rok</code> z docelowego segregatora (może różnić się od przykładu powyżej).</div>
    <?php endif; ?>
    <div class="card-body">
      <div class="table-responsive mb-2">
        <table class="table table-bordered table-sm mb-0">
          <thead class="table-light"><tr><th>Lp.</th><th>Stary znak sprawy</th><th>Nowy znak sprawy</th><th>Forma prowadzenia</th></tr></thead>
          <tbody><tr>
            <td><?= count($protokol) + 1 ?></td>
            <td><?= h($in['stary_znak'] !== '' ? $in['stary_znak'] : '—') ?></td>
            <td class="fw-semibold"><?= h($wynik['nowy_znak']) ?></td>
            <td><?= h($wynik['forma']) ?></td>
          </tr></tbody>
        </table>
      </div>
      <div class="small text-secondary">Markdown (do wklejenia w dokument):</div>
      <pre class="bg-light border rounded p-2 small mb-0 user-select-all" style="white-space:pre-wrap"><?= h(str_replace('{LP}', (string)(count($protokol) + 1), $wynik['protokol_md'])) ?></pre>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Protokół (rejestr) ───────────────────────────────────────────────── -->
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold d-flex align-items-center justify-content-between">
      <span><i class="bi bi-journal-bookmark me-2 text-secondary"></i>Protokół przerejestrowania <span class="badge bg-secondary ms-1"><?= count($protokol) ?></span></span>
      <?php if ($protokol): ?>
      <button class="btn btn-outline-secondary btn-sm" type="button" onclick="ezdCopyProtokol()"><i class="bi bi-clipboard me-1"></i>Kopiuj Markdown</button>
      <?php endif; ?>
    </div>
    <div class="card-body p-0">
      <?php if (!$protokol): ?>
      <div class="p-4 text-center text-secondary small">Protokół jest pusty — zapisz pierwszy wpis po analizie sprawy.</div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-hover table-sm mb-0 align-middle">
          <thead class="table-light"><tr>
            <th style="width:52px">Lp.</th><th>Stary znak sprawy</th><th>Nowy znak sprawy</th><th>Forma prowadzenia</th>
            <th>Status</th><th>Data</th><th>Kto</th><th style="width:44px"></th>
          </tr></thead>
          <tbody>
          <?php foreach ($protokol as $i => $p): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= h($p['stary_znak'] !== '' ? $p['stary_znak'] : '—') ?></td>
            <td class="fw-semibold">
              <?php if (!empty($p['sprawa_exists'])): ?>
                <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$p['sprawa_id'] ?>"><?= h($p['nowy_znak']) ?></a>
              <?php else: ?><?= h($p['nowy_znak']) ?><?php endif; ?>
            </td>
            <td class="small"><?= h($p['forma']) ?></td>
            <td>
              <?php if (!empty($p['zastosowano'])): ?>
                <span class="badge bg-success" title="Koszulka faktycznie przeniesiona"><i class="bi bi-check2"></i> zastosowano</span>
              <?php else: ?>
                <span class="badge bg-light text-secondary border">wpis</span>
              <?php endif; ?>
            </td>
            <td class="small text-secondary"><?= $p['created_at'] ? date('d.m.Y', strtotime($p['created_at'])) : '—' ?></td>
            <td class="small text-secondary"><?= h($p['user_name'] ?? '—') ?></td>
            <td>
              <form method="post" class="mb-0" onsubmit="return confirm('Usunąć wpis z protokołu?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_op" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger border-0" title="Usuń wpis"><i class="bi bi-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
function ezdCopyProtokol() {
  const rows = <?= json_encode(array_map(
      fn($i, $p) => '| ' . ($i + 1) . ' | ' . ($p['stary_znak'] !== '' ? $p['stary_znak'] : '—') . ' | ' . $p['nowy_znak'] . ' | ' . $p['forma'] . ' |',
      array_keys($protokol), $protokol
  ), JSON_UNESCAPED_UNICODE) ?>;
  const md = ['| Lp. | Stary znak sprawy | Nowy znak sprawy | Forma prowadzenia |',
              '|-----|-------------------|------------------|-------------------|', ...rows].join('\n');
  navigator.clipboard.writeText(md).then(() => alert('Protokół skopiowany (Markdown).'));
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

<?php
/**
 * karty30/ti/dydaktyk/wylaczenia.php — dostępność panelu dydaktyka i dziennika
 * ocen (strona kierownika).
 *
 * Dwie rzeczy w jednym miejscu, bo to jedna decyzja „czy dziś się pracuje":
 *   1. przełącznik panelu tu i teraz (org_setting dyd_panel_*),
 *   2. zaplanowane okna wyłączeń (k30_ti_blackouts) — włączają się i gasną same.
 *
 * Ekran mieszkał w administracji (admin/ti_settings.php); przerwy planuje
 * kierownik, więc stoi tam, gdzie on pracuje. Stary adres przekierowuje tutaj.
 * Wyłączenie nie dotyczy administracji ani pracowników D3 — oni widzą tylko
 * baner, więc kierownik nie zamknie sobie tą stroną drogi powrotnej.
 */
require_once __DIR__ . '/auth.php';

karty30_migrate();
ti_blackout_migrate();

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }   // ekran kierownika

$dyd_uid  = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? 'save_settings';

    if ($op === 'blackout_save') {
        try {
            ti_blackout_save([
                'title'          => $_POST['bl_title']   ?? '',
                'message'        => $_POST['bl_message'] ?? '',
                'starts_at'      => $_POST['bl_from']     ?? '',
                'ends_at'        => $_POST['bl_to']       ?? '',
                'block_dydaktyk' => !empty($_POST['bl_dydaktyk']),
                'block_dziennik' => !empty($_POST['bl_dziennik']),
                'is_active'      => !empty($_POST['bl_active']),
            ], (int)($_POST['bl_id'] ?? 0) ?: null, $dyd_uid);
            flash_set('success', 'Okres wyłączenia zapisany.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: wylaczenia.php#wylaczenia'); exit;
    }

    if ($op === 'blackout_delete') {
        ti_blackout_delete((int)($_POST['bl_id'] ?? 0));
        flash_set('success', 'Okres wyłączenia usunięty.');
        header('Location: wylaczenia.php#wylaczenia'); exit;
    }

    org_setting_set('dyd_panel_enabled', !empty($_POST['dyd_panel_enabled']) ? '1' : '0');
    org_setting_set('dyd_panel_message', trim($_POST['dyd_panel_message'] ?? ''));
    org_setting_set('dyd_panel_resume',  trim($_POST['dyd_panel_resume']  ?? ''));
    flash_set('success', 'Ustawienia panelu dydaktyka zapisane.');
    header('Location: wylaczenia.php'); exit;
}

$enabled = org_setting('dyd_panel_enabled');
$enabled = ($enabled === '' || $enabled === '1'); // domyślnie włączony
$message = org_setting('dyd_panel_message');
$resume  = org_setting('dyd_panel_resume');

$blackouts = ti_blackout_list();
$bl_edit   = ti_blackout_get((int)($_GET['bl_edit'] ?? 0));
$bl        = $bl_edit ?: ['id'=>0,'title'=>'','message'=>'','starts_at'=>'','ends_at'=>'','block_dydaktyk'=>1,'block_dziennik'=>0,'is_active'=>1];

// ── Stan „tu i teraz" — to samo, co liczą bramki panelu i dziennika ─────────
$now      = date('Y-m-d H:i:s');
$win_dyd  = ti_blackout_active('dydaktyk');
$win_dz   = ti_blackout_active('dziennik');
$next_dyd = ti_blackout_next('dydaktyk');
$next_dz  = ti_blackout_next('dziennik');

/** Okresy podzielone: trwające i przyszłe osobno od zakończonych (archiwum). */
$bl_open = $bl_past = [];
foreach ($blackouts as $b) {
    if ($b['ends_at'] < $now) $bl_past[] = $b; else $bl_open[] = $b;
}
// Bieżące chronologicznie — trwające na górze, dalej najbliższe zaplanowane.
usort($bl_open, fn($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

/** Krótki zapis daty okna: „1.07.2026, 8:00". */
function wyl_dt(string $v): string {
    $t = strtotime($v);
    return $t ? date('j.m.Y, G:i', $t) : '';
}

/**
 * Stan okna jako [klucz, etykieta, ikona]. Stan nie jest niesiony samym kolorem:
 * każda plakietka ma ikonę i słowo.
 */
function wyl_state(array $b, string $now): array {
    if (!$b['is_active'])                                  return ['off',  'Nieaktywne', 'pause-circle'];
    if ($b['ends_at'] < $now)                              return ['past', 'Zakończone', 'check2-circle'];
    if ($b['starts_at'] <= $now && $b['ends_at'] >= $now)  return ['run',  'Trwa',       'moon-fill'];
    return ['plan', 'Zaplanowane', 'calendar-event'];
}

/** Wiersz tabeli okresów — wspólny dla listy bieżącej i archiwum. */
function wyl_row(array $b, string $now): void {
    [$st, $st_label, $st_icon] = wyl_state($b, $now);
    $range = ti_blackout_range_text($b);
    ?>
    <tr class="wyl-row-<?= $st ?>">
      <td class="text-nowrap"><span class="wyl-pill wyl-pill-<?= $st ?>"><i class="bi bi-<?= $st_icon ?>" aria-hidden="true"></i><?= $st_label ?></span></td>
      <td class="text-nowrap">
        <?= h(wyl_dt($b['starts_at'])) ?>
        <span class="text-body-secondary" aria-hidden="true">→</span><span class="visually-hidden">do</span>
        <?= h(wyl_dt($b['ends_at'])) ?>
      </td>
      <td class="text-nowrap">
        <?php if ($b['block_dydaktyk']): ?><span class="wyl-scope"><i class="bi bi-easel2" aria-hidden="true"></i>Panel</span><?php endif; ?>
        <?php if ($b['block_dziennik']): ?><span class="wyl-scope"><i class="bi bi-journal-bookmark" aria-hidden="true"></i>Dziennik</span><?php endif; ?>
      </td>
      <td>
        <?php if (trim((string)$b['title']) !== ''): ?><div class="fw-bold"><?= h($b['title']) ?></div><?php endif; ?>
        <?php if (trim((string)$b['message']) !== ''): ?><?= h($b['message']) ?>
        <?php else: ?><span class="text-body-secondary fst-italic">komunikat domyślny</span><?php endif; ?>
      </td>
      <td class="text-end text-nowrap">
        <a href="?bl_edit=<?= (int)$b['id'] ?>#bl-form" class="btn btn-sm btn-outline-primary"
           aria-label="Edytuj okres <?= h($range) ?>"><i class="bi bi-pencil" aria-hidden="true"></i><span class="d-none d-lg-inline ms-1">Edytuj</span></a>
        <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten okres wyłączenia?')">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="blackout_delete">
          <input type="hidden" name="bl_id" value="<?= (int)$b['id'] ?>">
          <button class="btn btn-sm btn-outline-danger" aria-label="Usuń okres <?= h($range) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
        </form>
      </td>
    </tr>
    <?php
}

$KP_TITLE  = 'Dostępność panelu — Panel dydaktyka';
$KP_TOPBAR = [
    'brand'  => 'Panel dydaktyka',
    'icon'   => 'easel2',
    'user'   => $dyd_name,
    'logout' => 'logout.php',
];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  /* Ekran wyłączeń — kafle stanu i plakietki w języku skórki TI (płasko, ramki,
     pasek po lewej). Stan zawsze niesie słowo + ikona, kolor tylko wspiera.
     Kontrast (AA): #1d5c2e/#eaf4ec = 7,0:1, #8a1c1c/#fbecec = 7,5:1,
     #6b4a00/#fff4d6 = 7,6:1, #45516b/#f2f5f8 = 7,2:1. */
  .wyl-status { display:grid; gap:.6rem; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); margin-bottom:1rem; }
  .wyl-tile { border:1px solid var(--ti-grid); border-left-width:5px; background:#fff; padding:.55rem .75rem; display:flex; gap:.65rem; align-items:flex-start; }
  .wyl-tile > .bi { font-size:1.35rem; line-height:1; margin-top:.1rem; }
  .wyl-tile-name { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#45516b; font-weight:700; }
  .wyl-tile-state { font-size:.95rem; font-weight:700; margin:.05rem 0 .15rem; }
  .wyl-tile-note { font-size:.74rem; color:#33415c; }
  .wyl-tile.is-on  { border-left-color:#2e7d45; } .wyl-tile.is-on  > .bi, .wyl-tile.is-on  .wyl-tile-state { color:#1d5c2e; }
  .wyl-tile.is-off { border-left-color:#b02a2a; background:#fdf6f6; } .wyl-tile.is-off > .bi, .wyl-tile.is-off .wyl-tile-state { color:#8a1c1c; }
  .wyl-tile-next { margin-top:.3rem; padding-top:.3rem; border-top:1px dashed var(--ti-grid); font-size:.74rem; color:#6b4a00; }

  .wyl-pill { display:inline-flex; align-items:center; gap:.3rem; padding:.05rem .45rem; border:1px solid; font-size:.72rem; font-weight:700; border-radius:2px; }
  .wyl-pill-run  { color:#8a1c1c; background:#fbecec; border-color:#e3b3b3; }
  .wyl-pill-plan { color:#6b4a00; background:#fff4d6; border-color:#e6cf8f; }
  .wyl-pill-off  { color:#45516b; background:#f2f5f8; border-color:#c3ccd8; }
  .wyl-pill-past { color:#45516b; background:#fff;    border-color:#c3ccd8; }
  .wyl-scope { display:inline-flex; align-items:center; gap:.25rem; margin-right:.5rem; color:var(--ti-navy); }
  body.ti-skin .table tbody tr.wyl-row-run > * { background-color:#fdf6f6; }

  .wyl-help { font-size:.76rem; color:#33415c; background:#f7f9fb; border:1px solid #e2e6ea; padding:.45rem .6rem; margin-bottom:.6rem; }
  .wyl-sub { font-size:.72rem; text-transform:uppercase; letter-spacing:.05em; color:#45516b; font-weight:700; border-bottom:1px solid #e2e6ea; padding-bottom:.2rem; margin:.2rem 0 .5rem; }
  .wyl-switch { display:flex; align-items:flex-start; gap:.6rem; padding:.5rem .6rem; border:1px solid var(--ti-grid); background:#fafbfc; margin-bottom:.75rem; }
  .wyl-switch .form-check-input { width:2.6em; height:1.35em; margin:0; flex-shrink:0; cursor:pointer; }
  .wyl-switch label { font-weight:700; color:var(--ti-navy); cursor:pointer; }
  .wyl-check { border:1px solid var(--ti-grid); padding:.4rem .6rem .4rem 2.1rem; margin-bottom:.35rem; background:#fff; }
  .wyl-check .form-check-label { font-weight:700; color:var(--ti-navy); }
  .wyl-check small { display:block; font-weight:400; color:#45516b; }
  #bl-form.is-edit { outline:2px solid #c8a11a; outline-offset:-1px; }
  .wyl-archive summary { cursor:pointer; padding:.35rem .6rem; background:var(--ti-bar); border:1px solid var(--ti-bar-border); color:var(--ti-navy); font-weight:700; font-size:.82rem; }
  .wyl-archive[open] summary { border-bottom:none; }
  .wyl-archive summary:focus-visible { outline:2px solid var(--ti-blue); outline-offset:2px; }
</style>

<?php $KIER_CUR = 'wylaczenia.php'; $KIER_LABEL = 'Wyłączenia';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<h1 class="h4 fw-bold mb-1"><i class="bi bi-moon me-2 text-primary" aria-hidden="true"></i>Wyłączenia panelu i dziennika</h1>
<p class="text-body-secondary small mb-3">
  Decyzja „czy dziś się pracuje": wyłącz panel od ręki albo zaplanuj okres, który włączy się i zgaśnie sam.
  Administracja i pracownicy D3 zawsze mają dostęp — widzą tylko baner.
</p>

<?= flash_html() ?>

<!-- ── Stan teraz ─────────────────────────────────────────────────────────── -->
<section aria-labelledby="wyl-now-h">
  <h2 id="wyl-now-h" class="visually-hidden">Stan w tej chwili</h2>
  <div class="wyl-status">
    <?php
      if (!$enabled) {
          $p_on = false; $p_state = 'Wyłączony ręcznie';
          $p_note = $resume !== '' ? 'Planowane wznowienie: ' . wyl_dt(str_replace('T', ' ', $resume)) : 'Bez daty wznowienia — włącz przełącznikiem poniżej.';
      } elseif ($win_dyd) {
          $p_on = false; $p_state = 'Wyłączony — trwa okres';
          $p_note = 'Do ' . wyl_dt($win_dyd['ends_at']) . (trim((string)$win_dyd['title']) !== '' ? ' · ' . $win_dyd['title'] : '');
      } else {
          $p_on = true; $p_state = 'Dostępny';
          $p_note = 'Prowadzący pracują normalnie.';
      }
    ?>
    <div class="wyl-tile <?= $p_on ? 'is-on' : 'is-off' ?>">
      <i class="bi bi-<?= $p_on ? 'check-circle-fill' : 'slash-circle-fill' ?>" aria-hidden="true"></i>
      <div>
        <div class="wyl-tile-name">Panel dydaktyka</div>
        <div class="wyl-tile-state"><?= h($p_state) ?></div>
        <div class="wyl-tile-note"><?= h($p_note) ?></div>
        <?php if ($p_on && $next_dyd): ?>
          <div class="wyl-tile-next"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Najbliższa przerwa: <?= h(ti_blackout_range_text($next_dyd)) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="wyl-tile <?= $win_dz ? 'is-off' : 'is-on' ?>">
      <i class="bi bi-<?= $win_dz ? 'slash-circle-fill' : 'check-circle-fill' ?>" aria-hidden="true"></i>
      <div>
        <div class="wyl-tile-name">Dziennik ocen</div>
        <div class="wyl-tile-state"><?= $win_dz ? 'Wyłączony — trwa okres' : 'Dostępny' ?></div>
        <div class="wyl-tile-note">
          <?= $win_dz
              ? h('Do ' . wyl_dt($win_dz['ends_at']) . (trim((string)$win_dz['title']) !== '' ? ' · ' . $win_dz['title'] : ''))
              : 'Oceny wpisywane i widoczne dla kursantów oraz opiekunów.' ?>
        </div>
        <?php if (!$win_dz && $next_dz): ?>
          <div class="wyl-tile-next"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>Najbliższa przerwa: <?= h(ti_blackout_range_text($next_dz)) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ── Lista okresów ──────────────────────────────────────────────────────── -->
<section class="mb-4" aria-labelledby="wylaczenia">
  <div class="card">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="bi bi-calendar-x" aria-hidden="true"></i>
      <h2 class="h6 m-0" id="wylaczenia">Zaplanowane i trwające okresy</h2>
      <span class="badge text-bg-secondary ms-1"><?= count($bl_open) ?><span class="visually-hidden"> okresów</span></span>
    </div>
    <?php if (!$bl_open): ?>
      <div class="card-body text-body-secondary">
        <i class="bi bi-calendar2-check me-1" aria-hidden="true"></i>Nic nie jest zaplanowane — panel i dziennik działają bez przerw.
        Nowy okres dodasz w formularzu poniżej, <a href="#bl-form">„Zaplanuj okres wyłączenia"</a>.
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Trwające i przyszłe okresy wyłączenia panelu dydaktyka i dziennika ocen</caption>
          <thead><tr>
            <th scope="col">Stan</th>
            <th scope="col">Okres</th>
            <th scope="col">Co wyłącza</th>
            <th scope="col">Komentarz</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr></thead>
          <tbody>
            <?php foreach ($bl_open as $b) wyl_row($b, $now); ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($bl_past): ?>
  <details class="wyl-archive">
    <summary>Zakończone okresy (<?= count($bl_past) ?>)</summary>
    <div class="table-responsive border border-top-0" style="border-color:var(--ti-grid)!important">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Zakończone okresy wyłączenia</caption>
        <thead><tr>
          <th scope="col">Stan</th>
          <th scope="col">Okres</th>
          <th scope="col">Co wyłączał</th>
          <th scope="col">Komentarz</th>
          <th scope="col" class="text-end">Akcje</th>
        </tr></thead>
        <tbody>
          <?php foreach ($bl_past as $b) wyl_row($b, $now); ?>
        </tbody>
      </table>
    </div>
  </details>
  <?php endif; ?>
</section>

<div class="row g-3 align-items-start">

  <!-- ── Wyłączenie ręczne ──────────────────────────────────────────────── -->
  <div class="col-xl-5">
    <form method="post" action="" class="card mb-0" aria-labelledby="wyl-manual-h">
      <?= csrf_field() ?>
      <input type="hidden" name="_op" value="save_settings">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-toggle-on" aria-hidden="true"></i>
        <h2 id="wyl-manual-h" class="h6 m-0">Wyłączenie ręczne — od teraz</h2>
      </div>
      <div class="card-body">
        <div class="wyl-switch form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="dyd_panel_enabled" name="dyd_panel_enabled"
                 aria-describedby="dyd_panel_enabled_help"
                 <?= $enabled ? 'checked' : '' ?>>
          <div>
            <label class="form-check-label" for="dyd_panel_enabled">Panel dydaktyka jest włączony</label>
            <div id="dyd_panel_enabled_help" class="form-text mt-0">
              Odznacz i zapisz, aby prowadzący zobaczyli stronę przerwy z komunikatem poniżej.
              Działa do chwili, aż włączysz panel z powrotem.
            </div>
          </div>
        </div>

        <div class="wyl-sub">Strona przerwy</div>
        <div class="mb-2">
          <label for="dyd_panel_message" class="form-label">Komunikat dla prowadzących</label>
          <textarea class="form-control" id="dyd_panel_message" name="dyd_panel_message"
                    rows="3" maxlength="1000" aria-describedby="dyd_panel_message_help"
                    placeholder="np. Przerwa techniczna — panel dydaktyka jest tymczasowo niedostępny. Zapraszamy ponownie wkrótce."><?= h($message) ?></textarea>
          <div id="dyd_panel_message_help" class="form-text">Puste pole = komunikat domyślny.</div>
        </div>
        <div class="mb-3">
          <label for="dyd_panel_resume" class="form-label">Planowane wznowienie <span class="fw-normal text-body-secondary">(opcjonalnie)</span></label>
          <input type="datetime-local" class="form-control" id="dyd_panel_resume" name="dyd_panel_resume"
                 value="<?= h($resume) ?>" style="max-width:240px" aria-describedby="dyd_panel_resume_help">
          <div id="dyd_panel_resume_help" class="form-text">Tylko informacja na stronie przerwy — panel nie włączy się sam. Do tego służy okres zaplanowany.</div>
        </div>

        <button type="submit" class="btn btn-primary">
          <i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz ustawienia
        </button>
      </div>
    </form>
  </div>

  <!-- ── Nowy / edytowany okres ─────────────────────────────────────────── -->
  <div class="col-xl-7">
    <div class="card mb-0<?= $bl_edit ? ' is-edit' : '' ?>" id="bl-form">
      <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-<?= $bl_edit ? 'pencil' : 'calendar-plus' ?>" aria-hidden="true"></i>
        <h2 class="h6 m-0"><?= $bl_edit ? 'Edytuj okres wyłączenia' : 'Zaplanuj okres wyłączenia' ?></h2>
        <?php if ($bl_edit): ?><span class="ms-auto small fw-normal"><?= h(ti_blackout_range_text($bl_edit)) ?></span><?php endif; ?>
      </div>
      <div class="card-body">
        <p class="wyl-help mb-3">
          <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
          Okres włącza się i gaśnie sam, po podanych datach. Dotyczy prowadzących, kursantów i opiekunów;
          komentarz jest tym, co zobaczą zamiast panelu albo dziennika.
        </p>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="_op" value="blackout_save">
          <input type="hidden" name="bl_id" value="<?= (int)$bl['id'] ?>">

          <div class="wyl-sub">Kiedy</div>
          <div class="row g-2 mb-3">
            <div class="col-sm-6">
              <label class="form-label" for="bl_from">Od <span class="text-danger" aria-hidden="true">*</span></label>
              <input type="datetime-local" class="form-control" id="bl_from" name="bl_from" required
                     value="<?= h($bl['starts_at'] !== '' ? date('Y-m-d\TH:i', strtotime($bl['starts_at'])) : '') ?>">
            </div>
            <div class="col-sm-6">
              <label class="form-label" for="bl_to">Do <span class="text-danger" aria-hidden="true">*</span></label>
              <input type="datetime-local" class="form-control" id="bl_to" name="bl_to" required
                     value="<?= h($bl['ends_at'] !== '' ? date('Y-m-d\TH:i', strtotime($bl['ends_at'])) : '') ?>">
            </div>
          </div>

          <fieldset class="mb-3">
            <legend class="wyl-sub w-100 float-none">Co wyłączyć <span class="text-danger" aria-hidden="true">*</span><span class="visually-hidden">(wymagane, co najmniej jedno)</span></legend>
            <div class="form-check wyl-check">
              <input class="form-check-input" type="checkbox" id="bl_dydaktyk" name="bl_dydaktyk" value="1" <?= !empty($bl['block_dydaktyk']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="bl_dydaktyk">
                <i class="bi bi-easel2 me-1" aria-hidden="true"></i>Panel dydaktyka
                <small>Prowadzący widzą stronę przerwy z komentarzem.</small>
              </label>
            </div>
            <div class="form-check wyl-check">
              <input class="form-check-input" type="checkbox" id="bl_dziennik" name="bl_dziennik" value="1" <?= !empty($bl['block_dziennik']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="bl_dziennik">
                <i class="bi bi-journal-bookmark me-1" aria-hidden="true"></i>Dziennik ocen
                <small>Bez wpisywania ocen i bez wglądu dla kursantów oraz opiekunów.</small>
              </label>
            </div>
          </fieldset>

          <div class="wyl-sub">Opis</div>
          <div class="mb-2">
            <label class="form-label" for="bl_message">Komentarz dla użytkowników</label>
            <textarea class="form-control" id="bl_message" name="bl_message" rows="2" maxlength="1000"
                      aria-describedby="bl_message_help"
                      placeholder="np. Trwają przygotowania do nowego roku dydaktycznego"><?= h($bl['message']) ?></textarea>
            <div id="bl_message_help" class="form-text">Puste pole = komunikat domyślny.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="bl_title">Nazwa okresu <span class="fw-normal text-body-secondary">(wewnętrzna, opcjonalnie)</span></label>
            <input type="text" class="form-control" id="bl_title" name="bl_title" maxlength="200"
                   value="<?= h($bl['title']) ?>" placeholder="np. Przygotowanie roku 2026/2027">
          </div>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="bl_active" name="bl_active" value="1"
                   aria-describedby="bl_active_help" <?= !empty($bl['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label fw-bold" for="bl_active">Okres aktywny</label>
            <div id="bl_active_help" class="form-text mt-0">Odznacz, aby przygotować okres bez uruchamiania.</div>
          </div>

          <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-primary"><i class="bi bi-floppy me-1" aria-hidden="true"></i><?= $bl_edit ? 'Zapisz okres' : 'Dodaj okres' ?></button>
            <?php if ($bl_edit): ?><a href="wylaczenia.php#wylaczenia" class="btn btn-outline-secondary">Anuluj edycję</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>


</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>

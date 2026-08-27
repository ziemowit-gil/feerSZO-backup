<?php
/**
 * karty30/ti/ext/kreator.php — kreator przypisania materiału.
 *
 * Zastępuje formularz, w którym trzeba było wpisać z palca „subject_id". Nikt
 * nie pamięta, że kursant Kowalski ma konto numer 418, a pomyłka w cyfrze daje
 * dostęp obcej osobie — i to do materiału na licencji. Tutaj wybiera się po
 * nazwisku i nazwie grupy, a na końcu widać jednym zdaniem, co się właśnie stanie.
 *
 * Cztery kroki, wszystkie po stronie serwera (stan jedzie w polach ukrytych,
 * nie w sesji — odświeżenie strony niczego nie gubi, a wstecz działa):
 *   1. co udostępniamy  → tytuł, potem zakres (cały tytuł / wydanie / zasób)
 *   2. komu             → grupa TI, kursant, pracownik, rola albo wszyscy
 *   3. co wolno i kiedy → czynności, okres, opcjonalne przypięcie do lekcji
 *   4. podsumowanie     → zdanie o skutku + ostrzeżenia, dopiero potem zapis
 *
 * Prowadzący bez uprawnień D3 może przypisywać tylko do SWOICH grup i tylko
 * materiały otwarte albo objęte ważną licencją — te same zasady co przy
 * przypinaniu (ext_pin_add), bo skutek jest ten sam: ktoś dostaje treść.
 */
require_once __DIR__ . '/_boot.php';

$EXT_SUBJECT = ext_require_subject($EXT_SUBJECT);
if ($EXT_SUBJECT['type'] !== 'user') {
    http_response_code(403);
    exit('Materiały przypisują prowadzący i pracownicy.');
}

$uid    = (int)$EXT_SUBJECT['id'];
$manage = ext_can_manage($EXT_SUBJECT);
$step   = max(1, min(4, (int)($_POST['_step'] ?? $_GET['step'] ?? 1)));
$err    = [];

/** Stan kreatora — tylko to, co przyszło z poprzednich kroków. */
$st = [
    'title_id'    => (int)($_POST['title_id']    ?? $_GET['title'] ?? 0),
    'scope_type'  => (string)($_POST['scope_type'] ?? 'title'),
    'scope_id'    => (int)($_POST['scope_id']    ?? 0),
    'subj_type'   => (string)($_POST['subj_type'] ?? ''),
    'subj_id'     => (int)($_POST['subj_id']     ?? 0),
    'ab_download' => !empty($_POST['ab_download']) ? 1 : 0,
    'ab_print'    => !empty($_POST['ab_print'])    ? 1 : 0,
    'starts_at'   => (string)($_POST['starts_at'] ?? ''),
    'ends_at'     => (string)($_POST['ends_at']   ?? ''),
    'session_id'  => (int)($_POST['session_id']  ?? 0),
    'note'        => (string)($_POST['note']      ?? ''),
];

// Krok 2 wysyła po jednej kontrolce na decyzję, a każda niesie typ RAZEM z id
// („resource:418", „course:12"). Rozbite na osobne pola dawało stany, w których
// wybrana jest i grupa, i kursant — i nie wiadomo, co miało wygrać.
foreach ([['scope_pick', 'scope_type', 'scope_id'], ['subj_pick', 'subj_type', 'subj_id']] as [$in, $kt, $ki]) {
    $raw = (string)($_POST[$in] ?? '');
    if ($raw !== '' && preg_match('/^([a-z]+):(\d+)$/', $raw, $m)) {
        $st[$kt] = $m[1];
        $st[$ki] = (int)$m[2];
    }
}

/** Grupy, którymi ten użytkownik może dysponować. */
$my_courses = $manage
    ? (function_exists('k30_ti_courses') ? k30_ti_courses(false) : [])
    : (function_exists('dyd_courses') ? dyd_courses($uid) : []);
$my_course_ids = array_map(fn($c) => (int)$c['id'], $my_courses);

$title = $st['title_id'] ? ext_title_get($st['title_id']) : null;

// ── Walidacja kroków ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($step >= 2 && !$title) { $err[] = 'Wybierz materiał.'; $step = 1; }

    if ($step >= 3) {
        if ($st['subj_type'] === '') { $err[] = 'Wybierz, komu przypisujesz materiał.'; $step = 2; }
        if (in_array($st['subj_type'], ['course','student','user','role'], true) && !$st['subj_id']) {
            $err[] = 'Wskaż konkretną grupę, osobę albo rolę.'; $step = 2;
        }
        if ($st['subj_type'] === 'course' && !$manage && !in_array($st['subj_id'], $my_course_ids, true)) {
            $err[] = 'Możesz przypisywać materiały tylko do swoich grup.'; $step = 2;
        }
        if (!$manage && $st['subj_type'] !== 'course') {
            $err[] = 'Przypisanie pojedynczym osobom i rolom zostaje przy pracownikach D3.'; $step = 2;
        }
    }

    if ($step >= 4) {
        if ($st['starts_at'] !== '' && $st['ends_at'] !== '' && $st['ends_at'] < $st['starts_at']) {
            $err[] = 'Data „do" musi być późniejsza niż „od".'; $step = 3;
        }
    }
}

// ── Zapis ───────────────────────────────────────────────────────────────────
$saved = null;
if ($step === 4 && ($_POST['_op'] ?? '') === 'save' && !$err) {
    $scope_type = in_array($st['scope_type'], ['title','edition','resource'], true) ? $st['scope_type'] : 'title';
    $scope_id   = $scope_type === 'title' ? (int)$title['id'] : $st['scope_id'];

    // Prowadzący nie obchodzi licencji: dokładnie ta sama bramka co przy przypinaniu
    if (!$manage) {
        $open = in_array($title['access_level'], ['public','registered'], true);
        $lic  = db_one("SELECT id FROM k30_ext_licenses
                        WHERE publisher_id = CAST(? AS INTEGER) AND is_active=1
                          AND valid_from <= date('now') AND valid_to >= date('now')",
                       [(int)$title['publisher_id']]);
        if (!$open && !$lic) {
            $err[] = 'Ten tytuł wymaga licencji, której nie ma. Poproś kierownika o dostęp — '
                   . 'sam materiał możesz przypiąć do lekcji, ale uczestnicy go nie otworzą.';
        }
    }

    if (!$err) {
        $abilities = ['view', 'stream'];
        if ($st['ab_download']) $abilities[] = 'download';
        if ($st['ab_print'])    $abilities[] = 'print';

        $grant_id = db_insert('k30_ext_grants', [
            'scope_type'   => $scope_type,
            'scope_id'     => $scope_id,
            'subject_type' => $st['subj_type'],
            'subject_id'   => $st['subj_type'] === 'all' ? 0 : $st['subj_id'],
            'effect'       => 'allow',
            'abilities'    => implode(',', $abilities),
            'starts_at'    => ext_dt($st['starts_at']),
            'ends_at'      => ext_dt($st['ends_at']),
            'note'         => trim($st['note']) !== '' ? trim($st['note']) : 'kreator przypisania',
            'created_by'   => $uid,
        ]);

        // Przypięcie zakładamy BEZ własnego uprawnienia — dostęp daje już grant
        // powyżej, a dublowanie skończyłoby się tym, że odpięcie zostawia sierotę.
        $pinned = false;
        if ($st['session_id'] && $scope_type === 'resource') {
            $r = ext_pin_add($EXT_SUBJECT, $scope_id, $st['session_id'], $st['note'], false);
            $pinned = $r['ok'];
            if (!$r['ok']) $err[] = $r['msg'];
        }

        $saved = ['grant_id' => $grant_id, 'pinned' => $pinned];
        ext_log($EXT_SUBJECT, ['title' => $title], 'grant', 'granted', 'kreator');
    }
}

$EXT_TITLE = 'Kreator przypisania';
$EXT_TAB   = 'katalog';
include __DIR__ . '/_head.php';

/** Pole ukryte przenoszące stan między krokami. */
$hid = function (array $keys) use ($st) {
    foreach ($keys as $k) {
        echo '<input type="hidden" name="' . h($k) . '" value="' . h((string)$st[$k]) . '">' . "\n";
    }
};
?>

<h1 class="h4 fw-bold mb-1"><i class="bi bi-magic text-primary me-2" aria-hidden="true"></i>Komu przypisujemy materiał</h1>
<p class="text-body-secondary small mb-3">
  Cztery kroki. Na końcu zobaczysz jednym zdaniem, co się stanie — zapis dopiero po zatwierdzeniu.
</p>

<?php /* Wskaźnik kroków niesie stan słowem („krok 2 z 4"), nie samym kolorem. */ ?>
<nav class="skin-subnav mb-3" aria-label="Kroki kreatora">
  <?php foreach ([1 => 'Materiał', 2 => 'Komu', 3 => 'Zakres', 4 => 'Podsumowanie'] as $n => $lbl): ?>
  <span class="<?= $n === $step ? 'active' : '' ?>" <?= $n === $step ? 'aria-current="step"' : '' ?>
        style="padding:.22rem .6rem;font-size:.78rem">
    <?= $n ?>. <?= h($lbl) ?><?php if ($n === $step): ?><span class="visually-hidden"> — krok bieżący, <?= $n ?> z 4</span><?php endif; ?>
  </span>
  <?php endforeach; ?>
</nav>

<?php if ($err): ?>
<div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($err as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($saved): ?>
<div class="alert alert-success" role="status">
  <i class="bi bi-check-circle me-1" aria-hidden="true"></i>
  Uprawnienie zapisane<?= $saved['pinned'] ? ', materiał przypięty do lekcji' : '' ?>.
</div>
<p>
  <a href="title.php?id=<?= (int)$title['id'] ?>" class="btn btn-primary btn-sm">Wróć do materiału</a>
  <a href="kreator.php" class="btn btn-outline-secondary btn-sm">Przypisz kolejny</a>
  <a href="admin.php?tab=uprawnienia" class="btn btn-outline-secondary btn-sm">Zobacz wszystkie uprawnienia</a>
</p>
<?php include __DIR__ . '/_foot.php'; exit; endif; ?>

<?php // ══ KROK 1 — materiał ══════════════════════════════════════════════ ?>
<?php if ($step === 1):
  $q = trim((string)($_POST['q'] ?? $_GET['q'] ?? ''));
  $found = ext_titles_visible($EXT_SUBJECT, ['q' => $q], 30);
?>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_step" value="1">
  <div class="row g-2 align-items-end mb-3">
    <div class="col-12 col-md-6">
      <label class="form-label" for="k-q">Szukaj materiału</label>
      <input type="search" class="form-control" id="k-q" name="q" value="<?= h($q) ?>" placeholder="tytuł albo autor">
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary">Szukaj</button></div>
  </div>
</form>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_step" value="2">
  <fieldset class="mb-3">
    <legend class="h6 fw-bold">Wybierz materiał</legend>
    <?php if (!$found): ?>
    <p class="text-body-secondary small">Nic nie pasuje. Materiał trzeba najpierw <a href="upload.php">wgrać</a>.</p>
    <?php endif; ?>
    <div class="list-group">
      <?php foreach ($found as $t): ?>
      <label class="list-group-item d-flex gap-2 align-items-start">
        <input class="form-check-input flex-shrink-0 mt-1" type="radio" name="title_id" value="<?= (int)$t['id'] ?>"
               <?= $st['title_id'] === (int)$t['id'] ? 'checked' : '' ?> required>
        <span>
          <span class="fw-semibold d-block"><?= h($t['title']) ?></span>
          <span class="small text-body-secondary">
            <?= h($t['authors']) ?><?= $t['authors'] !== '' ? ' · ' : '' ?><?= h($t['publisher_name']) ?>
            · <?= h(['public'=>'otwarty','registered'=>'dla zalogowanych','licensed'=>'na licencji','restricted'=>'ograniczony'][$t['access_level']] ?? $t['access_level']) ?>
          </span>
        </span>
      </label>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <button class="btn btn-primary" <?= $found ? '' : 'disabled' ?>>Dalej: komu →</button>
</form>

<?php // ══ KROK 2 — komu ══════════════════════════════════════════════════ ?>
<?php elseif ($step === 2):
  $students = $manage ? db_all(
      "SELECT sa.id, c.name, sa.login
       FROM k30_ti_student_accounts sa
       JOIN k30_clients c ON c.id = sa.client_id
       WHERE sa.is_active = 1 ORDER BY c.name COLLATE NOCASE LIMIT 500") : [];
  $users = $manage ? db_all(
      "SELECT id, name, email, role FROM users WHERE is_active=1 ORDER BY name COLLATE NOCASE LIMIT 500") : [];
  $roles = $manage ? db_all("SELECT id, name FROM roles ORDER BY name") : [];
?>
<p class="mb-3">Materiał: <strong><?= h($title['title']) ?></strong></p>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_step" value="3">
  <?php $hid(['title_id']); ?>

  <fieldset class="card mb-3">
    <legend class="card-header h6 fw-bold mb-0">Zakres materiału</legend>
    <div class="card-body">
      <?php /* Jedna grupa pól wyboru: wartość niesie typ i identyfikator naraz,
               więc nie da się wybrać dwóch rzeczy naraz ani zgubić, o co chodziło. */ ?>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="scope_pick" id="sc-t"
               value="title:<?= (int)$title['id'] ?>" <?= $st['scope_type'] === 'title' ? 'checked' : '' ?> required>
        <label class="form-check-label" for="sc-t"><strong>Cały tytuł</strong> — obejmie też pliki dodane później</label>
      </div>
      <?php foreach (ext_editions((int)$title['id']) as $ed): ?>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="scope_pick" id="sc-e<?= (int)$ed['id'] ?>"
               value="edition:<?= (int)$ed['id'] ?>"
               <?= $st['scope_type'] === 'edition' && $st['scope_id'] === (int)$ed['id'] ? 'checked' : '' ?>>
        <label class="form-check-label" for="sc-e<?= (int)$ed['id'] ?>">
          Wydanie: <?= h($ed['name']) ?><?= $ed['year'] ? ' (' . (int)$ed['year'] . ')' : '' ?>
        </label>
      </div>
        <?php foreach (ext_resources((int)$ed['id']) as $r): ?>
        <div class="form-check ms-4">
          <input class="form-check-input" type="radio" name="scope_pick" id="sc-r<?= (int)$r['id'] ?>"
                 value="resource:<?= (int)$r['id'] ?>"
                 <?= $st['scope_type'] === 'resource' && $st['scope_id'] === (int)$r['id'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="sc-r<?= (int)$r['id'] ?>">
            Sam zasób: <?= h($r['name']) ?>
            <span class="text-body-secondary small"><?= $r['size_bytes'] ? '· ' . h(ext_human_size((int)$r['size_bytes'])) : '' ?></span>
          </label>
        </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
      <p class="form-text mb-0">Wybór zasobu jest najwęższy — daje dostęp do jednego pliku albo rozdziału.</p>
    </div>
  </fieldset>

  <fieldset class="card mb-3">
    <legend class="card-header h6 fw-bold mb-0">Komu</legend>
    <div class="card-body">
      <label class="form-label" for="k-subj">Odbiorca <span class="text-danger" aria-hidden="true">*</span></label>
      <select class="form-select" id="k-subj" name="subj_pick" required aria-describedby="k-subj-hint">
        <option value="">— wybierz —</option>
        <?php if ($my_courses): ?>
        <optgroup label="Grupy TI">
          <?php foreach ($my_courses as $c): ?>
          <option value="course:<?= (int)$c['id'] ?>"
                  <?= $st['subj_type'] === 'course' && $st['subj_id'] === (int)$c['id'] ? 'selected' : '' ?>>
            <?= h($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </optgroup>
        <?php endif; ?>
        <?php if ($manage): ?>
        <optgroup label="Kursanci">
          <?php foreach ($students as $s): ?>
          <option value="student:<?= (int)$s['id'] ?>"
                  <?= $st['subj_type'] === 'student' && $st['subj_id'] === (int)$s['id'] ? 'selected' : '' ?>>
            <?= h($s['name']) ?> (<?= h($s['login']) ?>)
          </option>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="Pracownicy i prowadzący">
          <?php foreach ($users as $u): ?>
          <option value="user:<?= (int)$u['id'] ?>"
                  <?= $st['subj_type'] === 'user' && $st['subj_id'] === (int)$u['id'] ? 'selected' : '' ?>>
            <?= h($u['name']) ?><?= $u['email'] ? ' · ' . h($u['email']) : '' ?>
          </option>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="Role w systemie">
          <?php foreach ($roles as $r): ?>
          <option value="role:<?= (int)$r['id'] ?>"
                  <?= $st['subj_type'] === 'role' && $st['subj_id'] === (int)$r['id'] ? 'selected' : '' ?>>
            wszyscy z rolą <?= h($r['name']) ?>
          </option>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="Cała organizacja">
          <option value="all:0" <?= $st['subj_type'] === 'all' ? 'selected' : '' ?>>
            wszyscy zalogowani — pracownicy i kursanci
          </option>
        </optgroup>
        <?php endif; ?>
      </select>
      <div class="form-text" id="k-subj-hint">
        Przy grupie dostęp idzie za zapisem: kto do niej dochodzi — dostaje, kto odchodzi — traci.
        <?php if (!$manage): ?><br>Przypisanie osobom, rolom i całej organizacji zostaje przy pracownikach D3.<?php endif; ?>
      </div>
    </div>
  </fieldset>
  <button class="btn btn-primary">Dalej: zakres uprawnień →</button>
  <a href="kreator.php" class="btn btn-outline-secondary">Wstecz</a>
</form>

<?php // ══ KROK 3 — co wolno ══════════════════════════════════════════════ ?>
<?php elseif ($step === 3):
  $rules_pub  = ext_publisher_get((int)$title['publisher_id']);
  $rules      = ext_effective_rules($rules_pub, $title);
  $sessions   = ($st['subj_type'] === 'course' && $st['scope_type'] === 'resource')
      ? db_all("SELECT id, lesson_date, time_from, topic FROM k30_ti_sessions
                WHERE course_id = CAST(? AS INTEGER) ORDER BY lesson_date DESC LIMIT 40", [$st['subj_id']])
      : [];
?>
<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_step" value="4">
  <?php $hid(['title_id','scope_type','scope_id','subj_type','subj_id']); ?>

  <fieldset class="card mb-3">
    <legend class="card-header h6 fw-bold mb-0">Co wolno</legend>
    <div class="card-body">
      <p class="small mb-2">Czytanie w przeglądarce jest zawsze — po to jest to przypisanie.</p>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="ab-dl" name="ab_download" value="1"
               <?= $st['ab_download'] ? 'checked' : '' ?> <?= empty($rules['allow_download']) ? 'disabled' : '' ?>>
        <label class="form-check-label" for="ab-dl">
          Pobieranie pliku
          <?php if (empty($rules['allow_download'])): ?>
          <span class="text-body-secondary">— wydawca tego zabrania, zaznaczenie nic nie da</span>
          <?php endif; ?>
        </label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="ab-pr" name="ab_print" value="1"
               <?= $st['ab_print'] ? 'checked' : '' ?> <?= empty($rules['allow_print']) ? 'disabled' : '' ?>>
        <label class="form-check-label" for="ab-pr">
          Druk
          <?php if (empty($rules['allow_print'])): ?>
          <span class="text-body-secondary">— wydawca tego zabrania</span>
          <?php endif; ?>
        </label>
      </div>
    </div>
  </fieldset>

  <fieldset class="card mb-3">
    <legend class="card-header h6 fw-bold mb-0">Na jak długo</legend>
    <div class="card-body row g-3">
      <div class="col-6 col-md-3">
        <label class="form-label" for="k-from">Od</label>
        <input type="datetime-local" class="form-control" id="k-from" name="starts_at" value="<?= h($st['starts_at']) ?>">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="k-to">Do</label>
        <input type="datetime-local" class="form-control" id="k-to" name="ends_at" value="<?= h($st['ends_at']) ?>">
        <div class="form-text">Puste = bezterminowo.</div>
      </div>
      <div class="col-12 col-md-6">
        <label class="form-label" for="k-note">Notatka</label>
        <input class="form-control" id="k-note" name="note" maxlength="200" value="<?= h($st['note']) ?>"
               placeholder="np. lektura do modułu 2">
      </div>
      <?php if ($sessions): ?>
      <div class="col-12">
        <label class="form-label" for="k-ses">Przypnij przy okazji do lekcji <span class="text-body-secondary">(opcjonalnie)</span></label>
        <select class="form-select" id="k-ses" name="session_id">
          <option value="0">— nie przypinaj —</option>
          <?php foreach ($sessions as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $st['session_id'] === (int)$s['id'] ? 'selected' : '' ?>>
            <?= h($s['lesson_date']) ?><?= $s['time_from'] ? ' ' . h(substr($s['time_from'], 0, 5)) : '' ?><?= $s['topic'] !== '' ? ' — ' . h($s['topic']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Materiał pokaże się uczestnikom przy tych zajęciach.</div>
      </div>
      <?php endif; ?>
    </div>
  </fieldset>

  <button class="btn btn-primary">Dalej: podsumowanie →</button>
</form>

<?php // ══ KROK 4 — podsumowanie ═════════════════════════════════════════ ?>
<?php else:
  $who = match ($st['subj_type']) {
      'course'  => 'Grupa ' . (string)(db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$st['subj_id']])['name'] ?? '?'),
      'student' => 'Kursant ' . (string)(db_one("SELECT c.name FROM k30_ti_student_accounts sa
                                                 JOIN k30_clients c ON c.id=sa.client_id WHERE sa.id=?",
                                                [$st['subj_id']])['name'] ?? '?'),
      'user'    => (string)(db_one("SELECT name FROM users WHERE id=?", [$st['subj_id']])['name'] ?? '?'),
      'role'    => 'Wszyscy z rolą ' . (string)(db_one("SELECT name FROM roles WHERE id=?", [$st['subj_id']])['name'] ?? '?'),
      default   => 'Wszyscy zalogowani',
  };
  $what = match ($st['scope_type']) {
      'resource' => 'zasób „' . (string)(ext_resource_get($st['scope_id'])['name'] ?? '?') . '"',
      'edition'  => 'wydanie „' . (string)(ext_edition_get($st['scope_id'])['name'] ?? '?') . '"',
      default    => 'cały tytuł',
  };
  // Rzeczowniki odsłowne zamiast czasowników: podmiotem bywa grupa, osoba albo
  // rola, a „będzie mógł" nie zgadza się z żadnym z nich za każdym razem.
  $acts = ['czytania'];
  if ($st['ab_download']) $acts[] = 'pobierania';
  if ($st['ab_print'])    $acts[] = 'drukowania';

  $lic = db_one("SELECT id, valid_to FROM k30_ext_licenses
                 WHERE publisher_id = CAST(? AS INTEGER) AND is_active=1
                   AND valid_from <= date('now') AND valid_to >= date('now')", [(int)$title['publisher_id']]);
?>
<div class="card mb-3">
  <div class="card-header h6 fw-bold mb-0">Co się stanie</div>
  <div class="card-body">
    <p class="mb-2" style="font-size:1rem">
      <strong><?= h($who) ?></strong> dostaje prawo <strong><?= h(implode(' i ', $acts)) ?></strong>:
      <?= h($what) ?> z pozycji <strong><?= h($title['title']) ?></strong><?php
        if ($st['starts_at'] !== '') echo ', od ' . h(str_replace('T', ' ', $st['starts_at']));
        if ($st['ends_at']   !== '') echo ', do ' . h(str_replace('T', ' ', $st['ends_at']));
        if ($st['starts_at'] === '' && $st['ends_at'] === '') echo ', bezterminowo';
      ?>.
    </p>

    <?php if ($title['access_level'] === 'licensed' && !$lic): ?>
    <div class="alert alert-warning mb-2" role="alert">
      <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
      Ten tytuł jest na licencji, a wydawca <strong><?= h($title['publisher_name']) ?></strong> nie ma
      dziś ważnej umowy w systemie. Uprawnienie zapiszemy, ale zanim ktokolwiek otworzy materiał,
      trzeba wprowadzić licencję.
    </div>
    <?php elseif ($lic): ?>
    <p class="small text-body-secondary mb-2">
      <i class="bi bi-shield-check me-1" aria-hidden="true"></i>
      Licencja wydawcy ważna do <?= h((string)$lic['valid_to']) ?>.
    </p>
    <?php endif; ?>

    <?php if ($st['subj_type'] === 'all'): ?>
    <div class="alert alert-warning mb-2" role="alert">
      <i class="bi bi-people me-1" aria-hidden="true"></i>
      To przypisanie obejmie <strong>wszystkich</strong> zalogowanych — pracowników i kursantów.
    </div>
    <?php endif; ?>

    <?php if ($title['rights_note'] !== ''): ?>
    <p class="small mb-0"><strong>Zasady z umowy:</strong> <?= h($title['rights_note']) ?></p>
    <?php endif; ?>
  </div>
</div>

<form method="post">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="_step" value="4">
  <input type="hidden" name="_op" value="save">
  <?php $hid(['title_id','scope_type','scope_id','subj_type','subj_id','ab_download','ab_print','starts_at','ends_at','session_id','note']); ?>
  <button class="btn btn-primary"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz uprawnienie</button>
  <a href="kreator.php?step=1" class="btn btn-outline-secondary">Zacznij od nowa</a>
</form>
<?php endif; ?>

<?php include __DIR__ . '/_foot.php'; ?>

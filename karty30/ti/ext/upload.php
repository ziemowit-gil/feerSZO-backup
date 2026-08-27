<?php
/**
 * karty30/ti/ext/upload.php — wgrywanie materiałów (pracownik).
 *
 * Książki mają 50–500 MB, a upload_max_filesize/post_max_size zwykle na to nie
 * pozwalają — i nie warto ich podnosić globalnie dla całego SZO. Przeglądarka
 * tnie plik na kawałki po 4 MB, serwer dokleja je po kolei do pliku tymczasowego.
 * Bez JS działa zwykły upload jednym żądaniem (dla mniejszych plików) — strona
 * ma być używalna także wtedy.
 *
 * Kolejność jest ważna: najpierw ląduje PLIK (zwraca skrót), potem METADANE
 * (tworzą tytuł, wydanie i zasób). Dzięki temu przerwane wgrywanie nie zostawia
 * pustych rekordów w katalogu.
 */
require_once __DIR__ . '/_boot.php';

$EXT_SUBJECT = ext_require_manager($EXT_SUBJECT);
$uid = (int)$EXT_SUBJECT['id'];

// ── Przyjęcie kawałka (AJAX) ────────────────────────────────────────────────
if (($_POST['op'] ?? '') === 'chunk') {
    csrf_check();
    $sid   = preg_replace('/[^a-f0-9]/', '', (string)($_POST['sid'] ?? ''));
    $idx   = (int)($_POST['idx'] ?? 0);
    $total = (int)($_POST['total'] ?? 0);

    if ($sid === '' || !isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        ext_json(['ok' => false, 'msg' => 'Nie udało się odebrać części pliku.'], 400);
    }

    $tmp = ext_tmp_path($sid);
    @mkdir(dirname($tmp), 0770, true);

    // Kawałki muszą przyjść po kolei — inaczej doklejenie zbuduje śmieci.
    // Rozmiar pliku tymczasowego mówi, którego kawałka oczekujemy.
    $have = is_file($tmp) ? (int)filesize($tmp) : 0;
    if ($idx * EXT_CHUNK_BYTES !== $have) {
        ext_json(['ok' => false, 'expect' => intdiv($have, EXT_CHUNK_BYTES),
                  'msg' => 'Części przyszły nie po kolei — zacznij wgrywanie od nowa.'], 409);
    }
    if ($have + $_FILES['chunk']['size'] > EXT_MAX_BYTES) {
        @unlink($tmp);
        ext_json(['ok' => false, 'msg' => 'Plik przekracza limit ' . round(EXT_MAX_BYTES / 1048576) . ' MB.'], 413);
    }

    $in  = fopen($_FILES['chunk']['tmp_name'], 'rb');
    $out = fopen($tmp, 'ab');
    if (!$in || !$out) ext_json(['ok' => false, 'msg' => 'Błąd zapisu części pliku.'], 500);
    stream_copy_to_stream($in, $out);          // bez wczytywania do pamięci
    fclose($in); fclose($out);

    if ($idx + 1 < $total) ext_json(['ok' => true, 'next' => $idx + 1]);

    $res = ext_store_upload($tmp, $uid);
    ext_json($res, $res['ok'] ? 200 : 422);
}

// ── Zapis metadanych ────────────────────────────────────────────────────────
$err = [];
if (($_POST['op'] ?? '') === 'save') {
    csrf_check();

    $checksum = preg_replace('/[^a-f0-9]/', '', (string)($_POST['checksum'] ?? ''));
    $mime     = (string)($_POST['mime'] ?? '');
    $size     = (int)($_POST['size'] ?? 0);
    $pages    = (int)($_POST['pages'] ?? 0) ?: null;
    $origName = (string)($_POST['orig_name'] ?? '');

    // Bez JS plik przychodzi zwykłą drogą — wtedy dopiero tu ląduje w magazynie
    if ($checksum === '' && isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
        $tmp = ext_tmp_path(bin2hex(random_bytes(8)));
        @mkdir(dirname($tmp), 0770, true);
        move_uploaded_file($_FILES['file']['tmp_name'], $tmp);
        $st = ext_store_upload($tmp, $uid);
        if (!$st['ok']) { $err[] = $st['msg']; }
        else {
            $checksum = $st['checksum']; $mime = $st['mime'];
            $size = (int)$st['size'];    $pages = $st['pages'];
            $origName = $origName !== '' ? $origName : (string)$_FILES['file']['name'];
        }
    }

    $publisher_id = (int)($_POST['publisher_id'] ?? 0);
    $new_pub      = trim((string)($_POST['new_publisher'] ?? ''));
    $title_id     = (int)($_POST['title_id'] ?? 0);
    $new_title    = trim((string)($_POST['new_title'] ?? ''));
    $edition_id   = (int)($_POST['edition_id'] ?? 0);
    $res_name     = trim((string)($_POST['res_name'] ?? ''));

    if ($checksum === '')                        $err[] = 'Najpierw wskaż plik do wgrania.';
    if (!$publisher_id && $new_pub === '')       $err[] = 'Wybierz wydawcę albo podaj nowego.';
    if (!$title_id && $new_title === '')         $err[] = 'Wybierz tytuł albo podaj nowy.';
    if ($res_name === '')                        $err[] = 'Podaj nazwę zasobu (np. „Całość" albo „Rozdział 1").';

    if (!$err) {
        if (!$publisher_id) {
            $slug = substr(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($new_pub)), 0, 40) . '-' . bin2hex(random_bytes(2));
            $publisher_id = db_insert('k30_ext_publishers', ['name' => $new_pub, 'slug' => $slug]);
        }
        if (!$title_id) {
            $title_id = db_insert('k30_ext_titles', [
                'publisher_id'     => $publisher_id,
                'title'            => $new_title,
                'authors'          => trim((string)($_POST['authors'] ?? '')),
                'description'      => trim((string)($_POST['description'] ?? '')),
                'access_level'     => in_array($_POST['access_level'] ?? '', ['public','registered','licensed','restricted'], true)
                                      ? $_POST['access_level'] : 'licensed',
                'allow_download'   => !empty($_POST['allow_download']) ? 1 : 0,
                'allow_print'      => !empty($_POST['allow_print']) ? 1 : 0,
                'watermark_policy' => in_array($_POST['watermark_policy'] ?? '', ['none','footer','overlay','both'], true)
                                      ? $_POST['watermark_policy'] : 'both',
                'rights_note'      => trim((string)($_POST['rights_note'] ?? '')),
                'created_by'       => $uid,
            ]);
            foreach ((array)($_POST['categories'] ?? []) as $cid) {
                try { db_exec("INSERT INTO k30_ext_category_title (category_id,title_id) VALUES (?,?)",
                              [(int)$cid, $title_id]); } catch (\Throwable $e) {}
            }
        }
        if (!$edition_id) {
            $edition_id = db_insert('k30_ext_editions', [
                'title_id'   => $title_id,
                'name'       => trim((string)($_POST['edition_name'] ?? '')) ?: 'wydanie podstawowe',
                'year'       => (int)($_POST['edition_year'] ?? 0) ?: null,
                'isbn'       => trim((string)($_POST['isbn'] ?? '')),
            ]);
        }

        $rid = db_insert('k30_ext_resources', [
            'edition_id' => $edition_id,
            'kind'       => 'file',
            'name'       => $res_name,
            'checksum'   => $checksum,
            'orig_name'  => $origName,
            'mime'       => $mime,
            'size_bytes' => $size,
            'pages'      => $pages,
            'position'   => (int)(db_one("SELECT COALESCE(MAX(position),0)+1 AS p FROM k30_ext_resources WHERE edition_id=?",
                                         [$edition_id])['p'] ?? 1),
            'created_by' => $uid,
        ]);

        ext_log($EXT_SUBJECT, ['resource' => ['id' => $rid], 'title' => ['id' => $title_id]],
                'upload', 'served', 'ok');
        flash_set('success', 'Materiał wgrany i dodany do katalogu.');
        header('Location: title.php?id=' . $title_id); exit;
    }
}

$pubs  = ext_publishers(false);
$cats  = ext_categories();
$preTitle = ext_title_get((int)($_GET['title'] ?? 0));

$EXT_TITLE = 'Wgraj materiał';
$EXT_TAB   = 'wgraj';
include __DIR__ . '/_head.php';
?>

<h1 class="h4 fw-bold mb-1"><i class="bi bi-upload text-primary me-2" aria-hidden="true"></i>Wgraj materiał</h1>
<p class="text-body-secondary small mb-3">
  Plik trafia do magazynu poza katalogiem publicznym i nigdy nie jest dostępny bezpośrednim adresem.
  Duże pliki wysyłane są w częściach — nie zamykaj karty w trakcie.
</p>

<?php if ($err): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($err as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" id="ext-form">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="op"    value="save">
  <input type="hidden" name="checksum"  id="f-checksum">
  <input type="hidden" name="mime"      id="f-mime">
  <input type="hidden" name="size"      id="f-size">
  <input type="hidden" name="pages"     id="f-pages">
  <input type="hidden" name="orig_name" id="f-orig">

  <div class="card mb-3">
    <div class="card-header">1. Plik</div>
    <div class="card-body">
      <div class="ext-drop" id="ext-drop">
        <label for="f-file" class="form-label fw-semibold">Wybierz plik albo przeciągnij go tutaj</label>
        <input type="file" class="form-control" id="f-file" name="file"
               accept=".pdf,.epub,.docx,application/pdf" aria-describedby="f-file-hint">
        <div class="form-text" id="f-file-hint">
          PDF, EPUB lub DOCX, do <?= round(EXT_MAX_BYTES / 1048576) ?> MB. Typ rozpoznajemy po zawartości pliku.
        </div>
      </div>
      <div class="progress mt-2" style="height:8px" role="progressbar"
           aria-label="Postęp wgrywania" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="ext-bar-wrap" hidden>
        <div class="progress-bar" id="ext-bar" style="width:0%"></div>
      </div>
      <p class="small mt-1 mb-0" id="ext-status" aria-live="polite"></p>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">2. Gdzie to należy</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12 col-md-6">
          <label class="form-label" for="f-pub">Wydawca</label>
          <select class="form-select" id="f-pub" name="publisher_id">
            <option value="0">— nowy wydawca —</option>
            <?php foreach ($pubs as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= $preTitle && (int)$preTitle['publisher_id'] === (int)$p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" class="form-control mt-2" name="new_publisher" placeholder="Nazwa nowego wydawcy"
                 aria-label="Nazwa nowego wydawcy">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label" for="f-title">Tytuł</label>
          <?php if ($preTitle): ?>
          <input type="hidden" name="title_id" value="<?= (int)$preTitle['id'] ?>">
          <p class="form-control-plaintext"><?= h($preTitle['title']) ?></p>
          <?php else: ?>
          <select class="form-select" id="f-title" name="title_id">
            <option value="0">— nowy tytuł —</option>
            <?php foreach (db_all("SELECT id, title FROM k30_ext_titles ORDER BY title COLLATE NOCASE") as $t): ?>
            <option value="<?= (int)$t['id'] ?>"><?= h($t['title']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="text" class="form-control mt-2" name="new_title" placeholder="Tytuł nowej pozycji"
                 aria-label="Tytuł nowej pozycji">
          <?php endif; ?>
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label" for="f-res">Nazwa zasobu <span class="text-danger" aria-hidden="true">*</span></label>
          <input type="text" class="form-control" id="f-res" name="res_name" required
                 value="<?= h((string)($_POST['res_name'] ?? 'Całość')) ?>" placeholder="np. Całość, Rozdział 1">
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="f-ed">Wydanie</label>
          <select class="form-select" id="f-ed" name="edition_id">
            <option value="0">— nowe —</option>
            <?php foreach ($preTitle ? ext_editions((int)$preTitle['id'], false) : [] as $e): ?>
            <option value="<?= (int)$e['id'] ?>"><?= h($e['name']) ?><?= $e['year'] ? ' (' . (int)$e['year'] . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-3">
          <label class="form-label" for="f-edyear">Rok wydania</label>
          <input type="number" class="form-control" id="f-edyear" name="edition_year" min="1900" max="2100">
        </div>
      </div>
    </div>
  </div>

  <?php if (!$preTitle): ?>
  <div class="card mb-3">
    <div class="card-header">3. Prawa — co wolno z tym materiałem</div>
    <div class="card-body">
      <p class="small text-body-secondary">
        Ustawienia dziedziczą wszystkie pliki tytułu. Niższe poziomy mogą je tylko zawęzić,
        więc bezpiecznie zacząć od najostrożniejszych.
      </p>
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <label class="form-label" for="f-level">Poziom dostępu</label>
          <select class="form-select" id="f-level" name="access_level">
            <option value="licensed" selected>Na licencji — wymaga umowy z wydawcą</option>
            <option value="registered">Dla zalogowanych</option>
            <option value="public">Otwarty</option>
            <option value="restricted">Ograniczony — tylko z imiennym uprawnieniem</option>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label" for="f-wm">Znak wodny</label>
          <select class="form-select" id="f-wm" name="watermark_policy">
            <option value="both" selected>Nadruk i stopka</option>
            <option value="overlay">Sam nadruk</option>
            <option value="footer">Sama stopka</option>
            <option value="none">Bez znaku wodnego</option>
          </select>
        </div>
        <div class="col-12 col-md-4">
          <fieldset>
            <legend class="form-label">Czynności</legend>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="f-dl" name="allow_download" value="1">
              <label class="form-check-label" for="f-dl">Wolno pobierać plik</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="f-pr" name="allow_print" value="1">
              <label class="form-check-label" for="f-pr">Wolno drukować</label>
            </div>
          </fieldset>
        </div>
        <div class="col-12">
          <label class="form-label" for="f-rights">Zasady z umowy <span class="text-body-secondary">(widoczne dla czytelnika)</span></label>
          <input type="text" class="form-control" id="f-rights" name="rights_note" maxlength="300"
                 placeholder="np. Dostęp wyłącznie dla uczestników kursu, bez prawa kopiowania">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label" for="f-authors">Autorzy</label>
          <input type="text" class="form-control" id="f-authors" name="authors" placeholder="Kowalski J., Nowak A.">
        </div>
        <div class="col-12 col-md-6">
          <label class="form-label" for="f-cats">Kategorie</label>
          <select class="form-select" id="f-cats" name="categories[]" multiple size="4">
            <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= str_repeat('· ', (int)$c['depth']) . h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12">
          <label class="form-label" for="f-desc">Opis</label>
          <textarea class="form-control" id="f-desc" name="description" rows="2"></textarea>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <button class="btn btn-primary" id="ext-submit"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz materiał</button>
  <a href="index.php" class="btn btn-outline-secondary">Anuluj</a>
</form>

<script>
// Wgrywanie w częściach. Bez JS formularz działa zwykłą drogą — dla plików,
// które mieszczą się w limitach PHP.
(function () {
  var CHUNK = <?= EXT_CHUNK_BYTES ?>;
  var form   = document.getElementById('ext-form');
  var input  = document.getElementById('f-file');
  var status = document.getElementById('ext-status');
  var bar    = document.getElementById('ext-bar');
  var wrap   = document.getElementById('ext-bar-wrap');
  var drop   = document.getElementById('ext-drop');
  var token  = <?= json_encode(csrf_token()) ?>;
  var done   = false;

  ['dragover', 'dragleave', 'drop'].forEach(function (ev) {
    drop.addEventListener(ev, function (e) {
      e.preventDefault();
      drop.classList.toggle('over', ev === 'dragover');
      if (ev === 'drop' && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; }
    });
  });

  function sid() {
    var a = new Uint8Array(8);
    crypto.getRandomValues(a);
    return Array.from(a, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
  }

  form.addEventListener('submit', function (e) {
    if (done || !input.files.length) return;      // metadane albo brak pliku — zwykła wysyłka
    e.preventDefault();

    var file = input.files[0];
    var id   = sid();
    var total = Math.max(1, Math.ceil(file.size / CHUNK));
    wrap.hidden = false;
    document.getElementById('ext-submit').disabled = true;

    (function send(i) {
      var fd = new FormData();
      fd.append('op', 'chunk');
      fd.append('_csrf', token);
      fd.append('sid', id);
      fd.append('idx', i);
      fd.append('total', total);
      fd.append('chunk', file.slice(i * CHUNK, (i + 1) * CHUNK));

      fetch('upload.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { throw new Error(d.msg || 'Błąd wgrywania.'); }
          var pct = Math.round(((i + 1) / total) * 100);
          bar.style.width = pct + '%';
          wrap.setAttribute('aria-valuenow', pct);
          status.textContent = 'Wysłano ' + pct + '%';

          if (typeof d.next === 'number') { send(d.next); return; }

          document.getElementById('f-checksum').value = d.checksum;
          document.getElementById('f-mime').value     = d.mime;
          document.getElementById('f-size').value     = d.size;
          document.getElementById('f-pages').value    = d.pages || '';
          document.getElementById('f-orig').value     = file.name;
          input.value = '';                        // plik jest już na serwerze
          status.textContent = d.dup
            ? 'Plik był już w magazynie — użyto istniejącej kopii. Zapisuję dane…'
            : 'Plik wgrany. Zapisuję dane…';
          done = true;
          form.submit();
        })
        .catch(function (err) {
          status.textContent = err.message;
          document.getElementById('ext-submit').disabled = false;
        });
    })(0);
  });
})();
</script>

<?php include __DIR__ . '/_foot.php'; ?>

<?php
/**
 * modules/it_portal/_blocks.php — edytor blokowy treści podstrony (artykuł / wstęp usługi).
 * Wejście: $blkName (nazwa pola POST z JSON-em bloków), $blkInit (tablica bloków).
 * Bloki: tekst, kreator krok po kroku (kroki + zrzuty ekranu), zrzut ekranu, wyróżnienie.
 * Zrzuty: przeciągnij, wybierz plik albo wklej Ctrl/⌘+V (upload.php). TinyMCE doładowuje _editor.php.
 */
$blkCfg = [
    'init'   => array_values($blkInit),
    'upload' => APP_URL . '/modules/it_portal/upload.php',
    'imgBase' => itPortalUrl('img/'),
    'csrf'   => csrf_token(),
    'callouts' => ITP_CALLOUTS,
];
?>
<input type="hidden" name="<?= h($blkName) ?>" id="blk-json" value="">
<div id="blk-root" class="blk" aria-label="Edytor treści">
  <noscript><p class="alert alert-warning">Edytor treści wymaga JavaScriptu.</p></noscript>
</div>
<style>
.blk-card { border: 1px solid #ced4da; background: #fff; margin-bottom: 14px; }
.blk-card > .blk-h { display: flex; gap: 6px; align-items: center; padding: 6px 10px; background: #f1f3f5; border-bottom: 1px solid #ced4da; }
.blk-card > .blk-h .blk-t { flex: 1; font-weight: 600; }
.blk-card > .blk-b { padding: 12px; }
.blk-step { border: 1px solid #dee2e6; border-left: 4px solid #0e6ea1; padding: 10px; margin-bottom: 10px; background: #fafbfc; }
.blk-step > .blk-h { display: flex; gap: 6px; align-items: center; margin-bottom: 8px; }
.blk-no { width: 28px; height: 28px; border-radius: 50%; background: #0a5f8b; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; flex: none; }
.blk-drop { border: 2px dashed #6c757d; padding: 12px; text-align: center; color: #495057; background: #fff; cursor: pointer; }
.blk-drop:focus, .blk-drop.is-over { border-color: #0a5f8b; background: #e6f2f9; outline: 3px solid #fbbf24; }
.blk-shots { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 8px; }
.blk-shot { width: 210px; border: 1px solid #ced4da; padding: 6px; background: #fff; }
.blk-shot img { width: 100%; height: 120px; object-fit: contain; background: #f1f3f5; display: block; margin-bottom: 4px; }
.blk-add { display: flex; flex-wrap: wrap; gap: 8px; padding: 10px; border: 1px dashed #adb5bd; }
.blk-msg { font-size: .85rem; color: #b02a37; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var CFG = <?= json_encode($blkCfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var root = document.getElementById('blk-root'), out = document.getElementById('blk-json');
  var blocks = CFG.init || [], binds = {}, acts = [], rid = 0, msg = '';

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function act(fn) { acts.push(fn); return acts.length - 1; }
  function inp(obj, key, cls, attrs) {
    return '<input class="form-control ' + (cls || '') + '" ' + (attrs || '') + ' value="' + esc(obj[key]) + '" data-in="' + act(function (el) { obj[key] = el.value; }) + '">';
  }
  function rich(obj, key, rows) {
    var id = 'blkr' + (++rid); binds[id] = [obj, key];
    return '<textarea class="blk-rich" id="' + id + '" rows="' + (rows || 6) + '">' + esc(obj[key]) + '</textarea>';
  }
  function btn(label, fn, cls, aria) {
    return '<button type="button" class="btn btn-sm ' + (cls || 'btn-outline-secondary') + '" data-act="' + act(fn) + '"' + (aria ? ' aria-label="' + esc(aria) + '" title="' + esc(aria) + '"' : '') + '>' + label + '</button>';
  }
  function move(arr, i, d) { var j = i + d; if (j < 0 || j >= arr.length) return; var t = arr[i]; arr[i] = arr[j]; arr[j] = t; render(); }
  function del(arr, i, what) { if (confirm('Usunąć ' + what + '?')) { arr.splice(i, 1); render(); } }
  function ctrls(arr, i, what) {
    return btn('↑', function () { move(arr, i, -1); }, '', 'Przesuń w górę: ' + what) + btn('↓', function () { move(arr, i, 1); }, '', 'Przesuń w dół: ' + what)
         + btn('<i class="bi bi-trash"></i>', function () { del(arr, i, what); }, 'btn-outline-danger', 'Usuń: ' + what);
  }

  function shots(list, hint) {
    var h = '<div class="blk-shots">';
    list.forEach(function (im, k) {
      h += '<div class="blk-shot"><img src="' + esc(CFG.imgBase + im.file) + '" alt="">'
        + inp(im, 'alt', 'form-control-sm mb-1', 'placeholder="Opis dla czytników ekranu" aria-label="Tekst alternatywny zrzutu"')
        + inp(im, 'caption', 'form-control-sm mb-1', 'placeholder="Podpis pod zrzutem" aria-label="Podpis zrzutu"')
        + btn('Usuń zrzut', function () { list.splice(k, 1); render(); }, 'btn-outline-danger w-100') + '</div>';
    });
    return h + '</div>';
  }
  function drop(list, multi) {
    return '<div class="blk-drop" tabindex="0" role="button" data-drop="' + act(function (files) { upload(files, function (im) { list.push(im); }); }) + '" data-multi="' + (multi ? 1 : 0) + '">'
      + '<i class="bi bi-image"></i> Miejsce na zrzut ekranu — przeciągnij plik, <u>kliknij, żeby wybrać</u>, albo zaznacz to pole i wklej <kbd>Ctrl</kbd>/<kbd>⌘</kbd>+<kbd>V</kbd></div>';
  }

  function stepHtml(w, s, i) {
    s.images = s.images || [];
    return '<div class="blk-step"><div class="blk-h"><span class="blk-no">' + (i + 1) + '</span>'
      + '<div class="flex-grow-1">' + inp(s, 'title', '', 'placeholder="Tytuł kroku, np. Kliknij Ustawienia" aria-label="Tytuł kroku ' + (i + 1) + '"') + '</div>'
      + ctrls(w.steps, i, 'krok ' + (i + 1)) + '</div>'
      + rich(s, 'html', 4) + '<div class="mt-2">' + shots(s.images) + drop(s.images, true) + '</div></div>';
  }
  function blockHtml(b, i) {
    var title = {text: 'Tekst', wizard: 'Kreator krok po kroku', image: 'Zrzut ekranu', callout: 'Wyróżnienie'}[b.type], body = '';
    if (b.type === 'text') body = rich(b, 'html', 8);
    else if (b.type === 'callout') {
      var opts = Object.keys(CFG.callouts).map(function (k) { return '<option value="' + k + '"' + (b.kind === k ? ' selected' : '') + '>' + esc(CFG.callouts[k]) + '</option>'; }).join('');
      body = '<label class="form-label small">Rodzaj</label><select class="form-select form-select-sm mb-2" style="max-width:200px" data-in="' + act(function (el) { b.kind = el.value; }) + '">' + opts + '</select>' + rich(b, 'html', 4);
    } else if (b.type === 'image') {
      var one = b.file ? [b] : [];
      body = (b.file ? shots(one) : '') + drop({push: function (im) { b.file = im.file; b.alt = b.alt || im.alt; }}, false);
    } else if (b.type === 'wizard') {
      b.steps = b.steps || [];
      body = '<label class="form-label small">Tytuł kreatora</label>' + inp(b, 'title', 'mb-2', 'placeholder="np. Jak zmienić hasło krok po kroku"')
        + '<label class="form-label small">Wstęp (opcjonalnie)</label>' + rich(b, 'intro', 3)
        + '<div class="fw-semibold mt-3 mb-2">Kroki</div>' + b.steps.map(function (s, k) { return stepHtml(b, s, k); }).join('')
        + '<div class="d-flex flex-wrap gap-2">' + btn('<i class="bi bi-plus-lg"></i> Dodaj krok', function () { b.steps.push({title: '', html: '', images: []}); render(); }, 'btn-outline-primary')
        + '<span class="btn btn-sm btn-outline-primary position-relative" role="button" tabindex="0" data-drop="' + act(function (files) { upload(files, function (im) { b.steps.push({title: '', html: '', images: [im]}); }); }) + '" data-multi="1"><i class="bi bi-images"></i> Dodaj kroki ze zrzutów (jeden krok na plik)</span></div>';
    }
    return '<div class="blk-card"><div class="blk-h"><span class="blk-t">' + (i + 1) + '. ' + title + '</span>' + ctrls(blocks, i, 'blok ' + title) + '</div><div class="blk-b">' + body + '</div></div>';
  }

  function syncRich() {
    Object.keys(binds).forEach(function (id) {
      var ed = window.tinymce && tinymce.get(id);
      if (ed) binds[id][0][binds[id][1]] = ed.getContent();
    });
  }
  function serialize() {
    syncRich();
    out.value = JSON.stringify(blocks.map(function (b) {
      var c = JSON.parse(JSON.stringify(b));
      return c;
    }));
  }

  function render() {
    syncRich();
    if (window.tinymce) tinymce.remove('.blk-rich');
    binds = {}; acts = []; rid = 0;
    var h = blocks.map(blockHtml).join('');
    h += '<div class="blk-add" role="group" aria-label="Dodaj blok">'
      + btn('<i class="bi bi-text-paragraph"></i> Tekst', function () { blocks.push({type: 'text', html: ''}); render(); }, 'btn-outline-primary')
      + btn('<i class="bi bi-list-ol"></i> Kreator krok po kroku', function () { blocks.push({type: 'wizard', title: '', intro: '', steps: [{title: '', html: '', images: []}]}); render(); }, 'btn-primary')
      + btn('<i class="bi bi-image"></i> Zrzut ekranu', function () { blocks.push({type: 'image', file: '', alt: '', caption: ''}); render(); }, 'btn-outline-primary')
      + btn('<i class="bi bi-info-circle"></i> Wyróżnienie', function () { blocks.push({type: 'callout', kind: 'info', html: ''}); render(); }, 'btn-outline-primary')
      + '</div><p class="blk-msg mt-2 mb-0" role="alert">' + esc(msg) + '</p>';
    root.innerHTML = h;
    if (window.tinymce && root.querySelector('.blk-rich')) {
      tinymce.init({
        selector: '#blk-root .blk-rich', license_key: 'gpl', promotion: false, branding: false, menubar: false,
        plugins: 'lists link table code autoresize', toolbar: 'undo redo | blocks | bold italic underline | bullist numlist | link table | removeformat code',
        block_formats: 'Akapit=p; Nagłówek=h2; Podtytuł=h3', min_height: 120, autoresize_bottom_margin: 10, entity_encoding: 'raw',
        link_default_protocol: 'https', paste_data_images: false,
        setup: function (ed) { ed.on('input change keyup', function () { var b = binds[ed.id]; if (b) b[0][b[1]] = ed.getContent(); }); }
      });
    }
  }

  function upload(files, add) {
    var list = Array.prototype.filter.call(files, function (f) { return /^image\//.test(f.type); });
    if (!list.length) { msg = 'To nie jest obraz (PNG, JPG, GIF lub WEBP).'; render(); return; }
    msg = 'Wgrywanie…'; var left = list.length, errs = [];
    list.forEach(function (f) {
      var fd = new FormData(); fd.append('file', f, f.name || 'zrzut.png'); fd.append('_csrf', CFG.csrf);
      fetch(CFG.upload, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
        if (j.file) { var im = {file: j.file, alt: '', caption: ''}; if (add.push) add.push(im); else add(im); } else errs.push(j.error || 'Błąd wgrywania.');
      }).catch(function () { errs.push('Błąd połączenia.'); }).then(function () {
        if (--left === 0) { msg = errs.join(' '); render(); }
      });
    });
  }

  function pick(el) {
    var f = document.createElement('input'); f.type = 'file'; f.accept = 'image/png,image/jpeg,image/gif,image/webp'; f.multiple = el.getAttribute('data-multi') === '1';
    f.onchange = function () { acts[+el.getAttribute('data-drop')](f.files); };
    f.click();
  }
  root.addEventListener('input', function (e) { var i = e.target.getAttribute && e.target.getAttribute('data-in'); if (i !== null && i !== undefined) acts[+i](e.target); });
  root.addEventListener('change', function (e) { var i = e.target.getAttribute && e.target.getAttribute('data-in'); if (i !== null && i !== undefined) acts[+i](e.target); });
  root.addEventListener('click', function (e) {
    var a = e.target.closest('[data-act]'); if (a) { acts[+a.getAttribute('data-act')](); return; }
    var d = e.target.closest('[data-drop]'); if (d) pick(d);
  });
  root.addEventListener('keydown', function (e) {
    var d = e.target.closest && e.target.closest('[data-drop]');
    if (d && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); pick(d); }
  });
  root.addEventListener('paste', function (e) {
    var d = e.target.closest && e.target.closest('[data-drop]'); if (!d || !e.clipboardData) return;
    var files = Array.prototype.filter.call(e.clipboardData.files || [], function (f) { return /^image\//.test(f.type); });
    if (files.length) { e.preventDefault(); acts[+d.getAttribute('data-drop')](files); }
  });
  ['dragover', 'dragleave', 'drop'].forEach(function (t) {
    root.addEventListener(t, function (e) {
      var d = e.target.closest && e.target.closest('[data-drop]'); if (!d) return;
      e.preventDefault();
      d.classList.toggle('is-over', t === 'dragover');
      if (t === 'drop' && e.dataTransfer) acts[+d.getAttribute('data-drop')](e.dataTransfer.files);
    });
  });
  document.addEventListener('submit', serialize, true);
  render();
});
</script>

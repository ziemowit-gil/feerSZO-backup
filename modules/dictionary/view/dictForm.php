<?php
/**
 * modules/dictionary/view/dictForm.php — nowy słownik / ustawienia słownika.
 * Zmienne: $dict (Dictionary|null), $old (wartości pól), $errors ([pole => komunikat]), $parents ([Dictionary, głębokość]), $isAdmin.
 */
$PAGE_TITLE = $dict ? 'Ustawienia: ' . $dict->name : 'Nowy słownik';
$crumbs = ['Administracja' => APP_URL . '/admin/index.php', 'Słownik Centralny' => route('dict.index')];
if ($dict) $crumbs[$dict->name] = route('dict.show', ['code' => $dict->code]);
$crumbs[$dict ? 'Ustawienia' : 'Nowy'] = null;
include __DIR__ . '/_head.php';
$v = fn(string $k) => (string)(is_bool($old[$k] ?? null) ? ($old[$k] ? '1' : '') : ($old[$k] ?? ''));
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="err-' . $k . '">' . h($errors[$k]) . '</div>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid" aria-describedby="err-' . $k : '';
$frozen = $dict && $dict->kind->codesFrozen();
?>
<?php if ($errors): ?><div class="alert alert-danger" role="alert">Popraw zaznaczone pola.</div><?php endif; ?>
<form method="post" action="<?= h($dict ? route('dict.update', ['code' => $dict->code]) : route('dict.store')) ?>" class="row g-3" style="max-width:52rem">
  <?= Router::csrfField() ?>
  <div class="col-md-7">
    <label class="form-label" for="f-name">Nazwa słownika</label>
    <input id="f-name" name="name" class="form-control<?= $inv('name') ?>" value="<?= h($v('name')) ?>" required maxlength="190">
    <?= $err('name') ?>
  </div>
  <div class="col-md-5">
    <label class="form-label" for="f-code">Kod (używany w kodzie i API)</label>
    <input id="f-code" name="code" class="form-control font-monospace<?= $inv('code') ?>" value="<?= h($v('code')) ?>" required maxlength="64"
           pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{0,63}" <?= $frozen ? 'readonly' : '' ?> placeholder="np. instytucje_dotujace">
    <?= $err('code') ?>
    <?php if ($frozen): ?><div class="form-text">Kodu słownika systemowego nie można zmienić.</div><?php elseif ($dict): ?><div class="form-text">Zmiana kodu rozłączy moduły, które czytają słownik po starym kodzie.</div><?php endif; ?>
  </div>
  <div class="col-md-5">
    <label class="form-label" for="f-kind">Rodzaj</label>
    <select id="f-kind" name="kind" class="form-select<?= $inv('kind') ?>">
      <?php foreach (DictionaryKind::options() as $k => $l): ?><option value="<?= h($k) ?>" <?= $v('kind') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
    </select>
    <?= $err('kind') ?>
    <div class="form-text">Systemowy = kody wpisów są w kodzie aplikacji (nie da się ich zmienić ani usunąć wpisu).</div>
  </div>
  <div class="col-md-5">
    <label class="form-label" for="f-parent">Kategoria nadrzędna</label>
    <select id="f-parent" name="parent_code" class="form-select<?= $inv('parent_code') ?>">
      <option value="">— brak (najwyższy poziom) —</option>
      <?php foreach ($parents as [$p, $depth]): if ($dict && $p->id === $dict->id) continue; ?>
        <option value="<?= h($p->code) ?>" <?= $v('parent_code') === $p->code ? 'selected' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth) . ($depth ? '└ ' : '') . h($p->name) ?></option>
      <?php endforeach; ?>
    </select>
    <?= $err('parent_code') ?>
  </div>
  <div class="col-md-2">
    <label class="form-label" for="f-sort">Kolejność</label>
    <input id="f-sort" name="sort_order" type="number" class="form-control" value="<?= h($v('sort_order') ?: '0') ?>">
  </div>
  <div class="col-12">
    <label class="form-label" for="f-desc">Opis (do czego służy, kto prowadzi)</label>
    <textarea id="f-desc" name="description" class="form-control" rows="3"><?= h($v('description')) ?></textarea>
  </div>
  <?php if ($isAdmin): ?>
  <div class="col-12 form-check ms-2">
    <input class="form-check-input" type="checkbox" id="f-prot" name="is_protected" value="1" <?= $v('is_protected') ? 'checked' : '' ?>>
    <label class="form-check-label" for="f-prot">Zabezpiecz przed edycją (zmiany tylko po zdjęciu zabezpieczenia przez administratora)</label>
  </div>
  <?php endif; ?>
  <div class="col-12 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> <?= $dict ? 'Zapisz' : 'Utwórz słownik' ?></button>
    <a class="btn btn-outline-secondary" href="<?= h($dict ? route('dict.show', ['code' => $dict->code]) : route('dict.index')) ?>">Anuluj</a>
  </div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>

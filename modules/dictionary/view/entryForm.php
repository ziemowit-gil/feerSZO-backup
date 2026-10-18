<?php
/**
 * modules/dictionary/view/entryForm.php — nowy wpis / edycja wpisu.
 * Zmienne: $dict, $entry (DictionaryEntry|null), $old, $errors, $parentChoices ([DictionaryEntry, głębokość]), $locales.
 */
$PAGE_TITLE = $entry ? 'Wpis: ' . $entry->label : 'Nowy wpis';
$crumbs = ['Administracja' => APP_URL . '/admin/index.php', 'Słownik Centralny' => route('dict.index'),
           $dict->name => route('dict.show', ['code' => $dict->code]), ($entry ? 'Edycja wpisu' : 'Nowy wpis') => null];
include __DIR__ . '/_head.php';
$v = function (string $k) use ($old): string {
    $x = $old[$k] ?? '';
    if (is_bool($x)) return $x ? '1' : '';
    if (is_array($x) || $x instanceof \stdClass) return (array)$x ? json_encode($x, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : '';
    return (string)$x;
};
$err = fn(string $k) => isset($errors[$k]) ? '<div class="invalid-feedback d-block" id="err-' . $k . '">' . h($errors[$k]) . '</div>' : '';
$inv = fn(string $k) => isset($errors[$k]) ? ' is-invalid" aria-describedby="err-' . $k : '';
$frozenCode = $entry && $dict->kind->codesFrozen();
?>
<?php if ($errors): ?><div class="alert alert-danger" role="alert">Popraw zaznaczone pola.</div><?php endif; ?>
<form method="post" action="<?= h($entry ? route('dict.entry.update', ['code' => $dict->code, 'id' => $entry->id]) : route('dict.entry.store', ['code' => $dict->code])) ?>" class="row g-3" style="max-width:56rem">
  <?= Router::csrfField() ?>
  <?php if ($entry): ?><input type="hidden" name="version" value="<?= h($v('version') ?: (string)$entry->version) ?>"><?php endif; ?>
  <div class="col-md-7">
    <label class="form-label" for="f-label">Etykieta (polski)</label>
    <input id="f-label" name="label" class="form-control<?= $inv('label') ?>" value="<?= h($v('label')) ?>" required maxlength="255" autofocus>
    <?= $err('label') ?>
  </div>
  <div class="col-md-5">
    <label class="form-label" for="f-code">Kod wpisu (wartość zapisywana w danych)</label>
    <input id="f-code" name="code" class="form-control font-monospace<?= $inv('code') ?>" value="<?= h($v('code')) ?>" required maxlength="64"
           pattern="[A-Za-z0-9][A-Za-z0-9_.\-]{0,63}" <?= $frozenCode ? 'readonly' : '' ?>>
    <?= $err('code') ?>
    <?php if ($frozenCode): ?><div class="form-text">Słownik systemowy — kodu nie można zmienić.</div><?php endif; ?>
  </div>
  <div class="col-md-6">
    <label class="form-label" for="f-parent">Wpis nadrzędny</label>
    <select id="f-parent" name="parent_code" class="form-select<?= $inv('parent_code') ?>">
      <option value="">— brak (najwyższy poziom) —</option>
      <?php foreach ($parentChoices as [$p, $depth]): ?>
        <option value="<?= h($p->code) ?>" <?= $v('parent_code') === $p->code ? 'selected' : '' ?>><?= str_repeat("\u{00A0}\u{00A0}\u{00A0}", $depth) . ($depth ? '└ ' : '') . h($p->label) ?></option>
      <?php endforeach; ?>
    </select>
    <?= $err('parent_code') ?>
  </div>
  <div class="col-md-2">
    <label class="form-label" for="f-sort">Kolejność</label>
    <input id="f-sort" name="sort_order" type="number" class="form-control" value="<?= h($v('sort_order') ?: '0') ?>">
  </div>
  <div class="col-md-4 d-flex align-items-end">
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" id="f-active" name="is_active" value="1" <?= $v('is_active') ? 'checked' : '' ?>>
      <label class="form-check-label" for="f-active">Aktywny (widoczny na listach wyboru)</label>
    </div>
  </div>
  <fieldset class="col-12">
    <legend class="form-label fs-6 mb-1">Okres obowiązywania <span class="text-body-secondary small">(puste = bez ograniczenia)</span></legend>
    <div class="row g-2">
      <div class="col-sm-4"><label class="form-label small" for="f-from">od</label>
        <input id="f-from" name="valid_from" type="date" class="form-control<?= $inv('valid_from') ?>" value="<?= h($v('valid_from')) ?>"><?= $err('valid_from') ?></div>
      <div class="col-sm-4"><label class="form-label small" for="f-to">do (włącznie)</label>
        <input id="f-to" name="valid_to" type="date" class="form-control<?= $inv('valid_to') ?>" value="<?= h($v('valid_to')) ?>"><?= $err('valid_to') ?></div>
    </div>
    <div class="form-text">Zamiast zmieniać znaczenie wpisu, zakończ jego okres i dodaj nowy wpis — stare dane zachowają dawną etykietę.</div>
  </fieldset>
  <?php if ($locales): ?>
  <fieldset class="col-12">
    <legend class="form-label fs-6 mb-1">Tłumaczenia etykiety</legend>
    <div class="row g-2">
      <?php foreach ($locales as $l): ?>
      <div class="col-sm-4"><label class="form-label small" for="f-l-<?= h($l) ?>"><?= h(strtoupper($l)) ?></label>
        <input id="f-l-<?= h($l) ?>" name="label_<?= h($l) ?>" class="form-control<?= $inv('label_' . $l) ?>" value="<?= h($v('label_' . $l)) ?>" maxlength="255" lang="<?= h($l) ?>"><?= $err('label_' . $l) ?></div>
      <?php endforeach; ?>
    </div>
  </fieldset>
  <?php endif; ?>
  <div class="col-12">
    <label class="form-label" for="f-desc">Opis / podpowiedź</label>
    <textarea id="f-desc" name="description" class="form-control" rows="2"><?= h($v('description')) ?></textarea>
  </div>
  <div class="col-12">
    <label class="form-label" for="f-attr">Atrybuty dodatkowe (obiekt JSON)</label>
    <textarea id="f-attr" name="attributes" class="form-control font-monospace<?= $inv('attributes') ?>" rows="6" spellcheck="false"
              placeholder='{"adres": "ul. Przykładowa 1, 00-001 Warszawa", "www": "https://…"}'><?= h($v('attributes')) ?></textarea>
    <?= $err('attributes') ?>
    <div class="form-text">Dowolne dodatkowe dane wpisu, np. adres, ikona, kolor, stawka — moduły czytają je przez <code>dictItems()</code>.</div>
  </div>
  <div class="col-12 d-flex flex-wrap gap-2">
    <button class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i> Zapisz</button>
    <?php if (!$entry): ?><button class="btn btn-outline-primary" name="add_next" value="1"><i class="bi bi-plus-lg" aria-hidden="true"></i> Zapisz i dodaj kolejny</button><?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= h(route('dict.show', ['code' => $dict->code])) ?>">Anuluj</a>
    <?php if ($entry): ?><span class="small text-body-secondary align-self-center ms-auto">Wersja <?= (int)$entry->version ?><?= $entry->updatedAt ? ' · zmieniono ' . h(date('d.m.Y H:i', strtotime($entry->updatedAt))) : '' ?></span><?php endif; ?>
  </div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>

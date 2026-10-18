<?php
/**
 * modules/collaborator_data/view/panel.php — „Zmiana danych współpracownika”: formularz (kontakt, adres, rozliczenia),
 * dziennik zmian osoby i wnioski SZD/SKK/UPO/DYD. Zmienne: $fields, $current, $person, $history, $types, $errors, $old.
 */
$PAGE_TITLE = 'Zmiana danych współpracownika';
$user = current_user();
$_shell = function_exists('panel_volunteer_shell') && panel_volunteer_shell($user);
$ROOT = dirname(__DIR__, 3);
if ($_shell) include $ROOT . '/panel/includes/header_panel.php';
else include $ROOT . '/includes/header.php';
require_once $ROOT . '/modules/dictionary/lib/dictionaryApi.php';
$val = fn(string $k) => (string)($old[$k] ?? $current[$k] ?? '');
$grouped = [];
foreach ($fields as $code => $f) $grouped[$f['group']][$code] = $f;
?>
<style>
.cdc{max-width:1200px;font-family:"Segoe UI",system-ui,sans-serif;--a:#0050ef;--l:#f2f2f2;--d:#1d1d1d;--m:#5f5f5f}
.cdc *{border-radius:0!important}
.cdc-k{font-size:.68rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#003bb0}
.cdc h1{font-size:2.1rem;font-weight:300;letter-spacing:-.02em;margin:.15rem 0 .3rem;color:var(--d)}
.cdc-lead{color:var(--m);font-size:.9rem;margin-bottom:1.25rem}
.cdc-cols{display:grid;gap:1.5rem;grid-template-columns:1fr}
@media(min-width:1100px){.cdc-cols{grid-template-columns:minmax(0,1fr) 380px}}
.cdc-sec{font-size:1.25rem;font-weight:300;color:var(--d);border-bottom:2px solid var(--d);padding-bottom:.4rem;margin:1.25rem 0 .9rem;display:flex;align-items:center;gap:.6rem}
.cdc-sec .bi{width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;background:var(--a);color:#fff;font-size:.95rem}
.cdc-grid{display:grid;gap:.75rem 1rem;grid-template-columns:repeat(auto-fill,minmax(210px,1fr))}
.cdc label{font-size:.8rem;font-weight:600;color:var(--d);margin-bottom:.2rem;display:block}
.cdc .form-control{border:2px solid #8a8a8a;box-shadow:none}
.cdc .form-control:focus{border-color:var(--a)}
.cdc .is-invalid{border-color:#a20025!important}
.cdc-err{font-size:.75rem;color:#a20025;margin-top:.2rem}
.cdc-note{font-size:.75rem;color:var(--m);margin-top:.2rem}
.cdc-lock{font-size:.66rem;font-weight:700;background:#c25100;color:#fff;padding:.08rem .35rem;margin-left:.35rem;letter-spacing:.04em}
.cdc-save{position:sticky;bottom:0;display:flex;justify-content:flex-end;gap:0;background:var(--d);margin-top:1.25rem}
.cdc-save button{border:0;background:var(--a);color:#fff;font-weight:600;height:48px;padding:0 1.5rem}
.cdc-save button:hover{background:#003bb0}
.cdc-tiles{display:grid;grid-template-columns:1fr 1fr;gap:.35rem}
.cdc-tile{display:flex;flex-direction:column;min-height:96px;padding:.7rem .8rem;color:#fff!important;text-decoration:none;background:var(--c)}
.cdc-tile:hover{filter:brightness(.9)}
.cdc-tile b{font-size:1.4rem;font-weight:300;line-height:1}
.cdc-tile span{margin-top:auto;font-size:.76rem;font-weight:600;line-height:1.25}
.cdc-log{list-style:none;margin:0;padding:0}
.cdc-log li{padding:.55rem .1rem;border-bottom:1px solid #e1e1e1;font-size:.82rem}
.cdc-log .st{display:inline-block;font-size:.62rem;font-weight:700;color:#fff;padding:.1rem .35rem;margin-left:.3rem}
.cdc-side{background:var(--l);padding:0 1rem 1rem}
</style>
<div class="cdc">
  <div class="cdc-k">Mój panel · dane współpracownika</div>
  <h1>Zmiana danych</h1>
  <p class="cdc-lead">Zaktualizuj swoje dane kontaktowe, adres i dane do rozliczeń. Każda zmiana trafia do dziennika (kto, kiedy, co).
    Każdy zapis tworzy wniosek SZD, który podpisujesz kodem z SMS-a — zmiany wchodzą po podpisaniu; zmiana rachunku bankowego czeka dodatkowo na akceptację.</p>
  <?= flash_html() ?>

  <div class="cdc-cols">
  <form method="post" action="<?= h(route('cdc.update')) ?>" novalidate>
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <?php foreach (COLLAB_GROUPS as $g => $gl): if (empty($grouped[$g])) continue; ?>
    <h2 class="cdc-sec"><i class="bi <?= ['kontakt' => 'bi-telephone', 'adres' => 'bi-house', 'rozliczenia' => 'bi-bank'][$g] ?? 'bi-dot' ?>" aria-hidden="true"></i><?= h($gl) ?></h2>
    <div class="cdc-grid">
      <?php foreach ($grouped[$g] as $code => $f): $id = 'cdc_' . $code; $err = $errors[$code] ?? null; ?>
      <div<?= $f['type'] === 'textarea' ? ' style="grid-column:1/-1"' : '' ?>>
        <label for="<?= $id ?>"><?= h($f['label']) ?><?php if ($f['approval']): ?><span class="cdc-lock" title="Zmiana wymaga akceptacji administratora">akceptacja</span><?php endif; ?></label>
        <?php if ($f['type'] === 'textarea'): ?>
        <textarea name="<?= $code ?>" id="<?= $id ?>" class="form-control<?= $err ? ' is-invalid' : '' ?>" rows="2"<?= $err ? ' aria-invalid="true" aria-describedby="' . $id . '_e"' : '' ?>><?= h($val($code)) ?></textarea>
        <?php else: ?>
        <input name="<?= $code ?>" id="<?= $id ?>" class="form-control<?= $err ? ' is-invalid' : '' ?>" value="<?= h($val($code)) ?>"
               type="<?= ['email' => 'email', 'phone' => 'tel'][$f['type']] ?? 'text' ?>"
               <?= $f['type'] === 'iban' ? 'inputmode="numeric" autocomplete="off" placeholder="PL00 0000 0000 0000 0000 0000 0000"' : '' ?>
               <?= $f['type'] === 'postal' ? 'inputmode="numeric" placeholder="00-000"' : '' ?>
               <?= $code === 'urzad_skarbowy' ? 'list="dl-' . $id . '"' : '' ?>
               <?= $err ? 'aria-invalid="true" aria-describedby="' . $id . '_e"' : '' ?>>
        <?= $code === 'urzad_skarbowy' ? dictDatalist('urzedy_skarbowe', $id) : '' ?>
        <?php endif; ?>
        <?php if ($err): ?><div class="cdc-err" id="<?= $id ?>_e"><?= h($err) ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$person): ?><p class="cdc-note mt-3"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Nie masz jeszcze karty w rejestrze Osób — założymy ją przy pierwszym zapisie adresu lub danych rozliczeniowych.</p><?php endif; ?>
    <div class="cdc-save"><button type="submit"><i class="bi bi-pen me-1" aria-hidden="true"></i>Zapisz i podpisz wniosek SZD</button></div>
  </form>

  <aside class="cdc-side" aria-label="Wnioski i dziennik zmian">
    <?php if ($types): ?>
    <h2 class="cdc-sec">Wnioski i upoważnienia</h2>
    <div class="cdc-tiles">
      <?php $pal = ['#0050ef', '#6a00ff', '#00807e', '#c25100', '#a20025', '#1d1d1d']; foreach ($types as $i => $t): ?>
      <a class="cdc-tile" style="--c:<?= $pal[$i % count($pal)] ?>" href="<?= h(APP_URL . '/panel/apply.php?type=' . (int)$t['id']) ?>">
        <b><?= h($t['code']) ?></b><span><?= h(preg_replace('/^[A-Z0-9-]+ · /u', '', (string)$t['label'])) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <h2 class="cdc-sec">Dziennik zmian</h2>
    <?php if (!$history): ?><p class="cdc-note">Brak zmian.</p><?php else: ?>
    <ul class="cdc-log">
      <?php foreach ($history as $c): $st = CollabChangeStatus::tryFrom((string)$c['status']) ?? CollabChangeStatus::Applied; ?>
      <li><b><?= h($fields[$c['field']]['label'] ?? $c['field']) ?></b><span class="st" style="background:<?= $st->color() ?>"><?= h($st->label()) ?></span><br>
        <span class="text-muted"><?= h(CollaboratorDataService::display((string)$c['field'], $c['old_value'])) ?> → </span><?= h(CollaboratorDataService::display((string)$c['field'], $c['new_value'])) ?><br>
        <small class="text-muted"><?= h(date('d.m.Y H:i', strtotime((string)$c['created_at']))) ?><?= $c['actor_name'] && (int)$c['changed_by'] !== (int)$c['user_id'] ? ' · ' . h($c['actor_name']) : '' ?><?= $c['decision_note'] ? ' · ' . h($c['decision_note']) : '' ?></small></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </aside>
  </div>
</div>
<?php
if ($_shell) include $ROOT . '/panel/includes/footer_panel.php';
else include $ROOT . '/includes/footer.php';

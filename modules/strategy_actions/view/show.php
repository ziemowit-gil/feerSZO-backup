<?php
/**
 * modules/strategy_actions/view/show.php — karta działania (Metro): szczegóły, harmonogram, finansowanie,
 * wskaźniki, etapy i historia, uczestnicy CRM. Zmienne: $a (ActionsService::find), $v (kształt ekranu z flagami),
 * $crmOn, $participants, $candidates, $canDelete, $journal, $users.
 */
$navActive = '';
$id    = (int)$a['id'];
$type  = ActionsService::typeInfo((string)$a['typ']);
$goals = ActionsService::goals();
$lines = fn(?string $t) => array_values(array_filter(array_map('trim', explode("\n", (string)$t))));
include __DIR__ . '/_head.php';
?>
<nav aria-label="Ścieżka" class="small mb-2 dz-noprint"><a href="<?= h(ActionsService::url()) ?>" class="text-decoration-none" style="color:var(--dz)">Działania</a> › <span class="dz-muted">#<?= $id ?></span></nav>
<div class="dz-top">
  <div>
    <h1 class="dz-title"><?= h($a['nazwa']) ?></h1>
    <p class="dz-sub d-flex gap-2 flex-wrap align-items-center mt-2">
      <span class="dz-badge" style="--c:<?= h(ActionsService::statusColor((string)$a['status'])) ?>"><?= h(ActionsService::statusLabel((string)$a['status'])) ?></span>
      <span><i class="bi <?= h($type['icon']) ?>" aria-hidden="true"></i> <?= h($type['label']) ?></span>
      <?php if ($a['wlasne_dzialanie']): ?><span class="dz-chip"><i class="bi bi-star-fill" aria-hidden="true"></i> działanie własne</span><?php endif; ?>
      <?php foreach (['noGrant' => 'Bez finansowania', 'overdue' => 'Po terminie', 'ending' => 'Kończy się ≤ 14 dni', 'behind' => 'Wskaźniki < 50%', 'msOverdue' => 'Zaległy etap', 'outsideGrant' => 'Poza okresem grantu'] as $fk => $fl): if (!empty($v['flags'][$fk])): ?>
      <span class="dz-flag <?= $fk ?>"><?= h($fl) ?></span>
      <?php endif; endforeach; ?>
    </p>
  </div>
  <div class="d-flex gap-2 flex-wrap dz-noprint">
    <a href="<?= h(ActionsService::url($id . '/edycja')) ?>" class="dz-btn"><i class="bi bi-pencil" aria-hidden="true"></i>Edytuj</a>
    <form method="post" action="<?= h(ActionsService::url($id . '/duplikuj')) ?>">
      <?= Router::csrfField() ?>
      <button type="submit" class="dz-btn dz-btn--ghost"><i class="bi bi-copy" aria-hidden="true"></i>Duplikuj</button>
    </form>
    <a href="<?= h(ActionsService::url()) ?>" class="dz-btn dz-btn--ghost"><i class="bi bi-arrow-left" aria-hidden="true"></i>Lista</a>
    <?php if ($canDelete): ?>
    <form method="post" action="<?= h(ActionsService::url($id . '/usun')) ?>" onsubmit="return confirm('Usunąć działanie razem z finansowaniem, wskaźnikami i listą uczestników? Tej operacji nie można cofnąć.')">
      <?= Router::csrfField() ?>
      <button type="submit" class="dz-btn dz-btn--danger"><i class="bi bi-trash" aria-hidden="true"></i>Usuń</button>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($v['flags']['noGrant'])): ?>
<div class="dz-alert dz-alert--danger" role="note">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <span>Działanie nie ma przypisanego grantu i nie jest oznaczone jako własne. <a href="<?= h(ActionsService::url($id . '/edycja')) ?>" style="color:#fff;font-weight:700">Uzupełnij finansowanie</a>.</span>
</div>
<?php endif; ?>

<?php if (!empty($v['flags']['outsideGrant'])): ?>
<div class="dz-alert dz-alert--warning" role="note">
  <i class="bi bi-calendar-x" aria-hidden="true"></i>
  <span>Termin działania wychodzi poza okres realizacji grantu: <?= h(implode(', ', $v['outsideOf'])) ?>. Koszty poza okresem grantu zwykle nie są kwalifikowalne — sprawdź daty.</span>
</div>
<?php endif; ?>

<div class="dz-grid">
  <div>
    <h2 class="dz-head">Szczegóły</h2>
    <dl class="dz-kv">
      <dt>Koordynator</dt><dd><?= $a['koordynator_name'] ? h($a['koordynator_name']) : '—' ?></dd>
      <dt>Forma</dt><dd><?= h(ActionsService::FORMS[$a['forma'] ?? ''] ?? ($a['forma'] ?: '—')) ?>
        <?php if (!empty($a['link_online'])): ?> · <a href="<?= h($a['link_online']) ?>" target="_blank" rel="noopener">link do spotkania</a><?php endif; ?></dd>
      <dt>Miejsce</dt><dd><?= $a['lokalizacja'] ? h($a['lokalizacja']) : '—' ?></dd>
      <dt>Cel statutowy §6</dt><dd><?= !empty($a['cel_statutowy']) && isset($goals[(int)$a['cel_statutowy']]) ? (int)$a['cel_statutowy'] . '. ' . h($goals[(int)$a['cel_statutowy']]) : '—' ?>
        <?php if (!empty($a['jrwa_symbol'])): ?><span class="dz-chip ms-1" title="Segregator EZD (JRWA) dla działania"><i class="bi bi-folder2" aria-hidden="true"></i> JRWA <?= h($a['jrwa_symbol']) ?></span><?php endif; ?></dd>
    </dl>
    <?php if (!empty($a['opis'])): ?>
    <h2 class="dz-head">Opis</h2>
    <p class="dz-prose"><?= h($a['opis']) ?></p>
    <?php endif; ?>

    <?php foreach ([['korzysci', $a['korzysci_tytul'] ?: 'Korzyści', 'bi-stars'], ['dla_kogo', 'Dla kogo', 'bi-people-fill']] as [$field, $title, $icon]):
      if (empty($a[$field])) continue; $ls = $lines($a[$field]); ?>
    <h2 class="dz-head"><?= h($title) ?></h2>
    <?php if (count($ls) > 1): ?>
    <ul class="mb-0"><?php foreach ($ls as $l): ?><li><?= h($l) ?></li><?php endforeach; ?></ul>
    <?php else: ?>
    <p class="dz-prose"><?= h($a[$field]) ?></p>
    <?php endif; ?>
    <?php endforeach; ?>

    <h2 class="dz-head">Finansowanie <small><?= $v['kwota'] > 0 ? h(money((float)$v['kwota'])) : '' ?></small></h2>
    <?php if ($a['grants']): ?>
    <div class="dz-scroll"><table class="dz-table">
      <thead><tr><th scope="col">Grant</th><th scope="col">Darczyńca</th><th scope="col" class="dz-num">Udział</th><th scope="col" class="dz-num">Kwota</th></tr></thead>
      <tbody>
      <?php foreach ($a['grants'] as $g): ?>
      <tr>
        <td><a class="nm" href="<?= APP_URL ?>/grants/view.php?id=<?= (int)$g['grant_id'] ?>"><?= h($g['nazwa']) ?></a>
          <?php $b = $budgets[(int)$g['grant_id']] ?? null; if ($b && $b['budget'] !== null): ?>
          <div class="small <?= $b['over'] ? 'fw-semibold' : 'dz-muted' ?>"<?= $b['over'] ? ' style="color:var(--red)"' : '' ?>>budżet <?= h(money($b['budget'], $b['waluta'])) ?> · rozdysponowano <?= h(money($b['allocated'], $b['waluta'])) ?> · <?= $b['over'] ? 'przekroczono o ' . h(money(-$b['remaining'], $b['waluta'])) : 'zostaje ' . h(money($b['remaining'], $b['waluta'])) ?></div>
          <?php endif; ?></td>
        <td><?= h($g['donator']) ?></td>
        <td class="dz-num"><?= $g['udzial_procent'] !== null ? h((string)(float)$g['udzial_procent']) . '%' : '—' ?></td>
        <td class="dz-num"><?= $g['kwota'] !== null ? h(money((float)$g['kwota'], $g['waluta'] ?: 'PLN')) : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php elseif ($a['wlasne_dzialanie']): ?>
    <div class="dz-block"><i class="bi bi-star-fill" aria-hidden="true"></i> Działanie własne — finansowane ze środków organizacji.</div>
    <?php else: ?>
    <div class="dz-empty">Brak przypisanych grantów.</div>
    <?php endif; ?>

    <h2 class="dz-head">Wskaźniki <small><?= count($a['indicators']) ?></small></h2>
    <?php if ($a['indicators']): ?>
    <div class="dz-scroll"><table class="dz-table">
      <thead><tr><th scope="col">Wskaźnik</th><th scope="col" class="dz-num">Plan</th><th scope="col" class="dz-num">Wykonanie</th><th scope="col" style="min-width:140px">Postęp</th></tr></thead>
      <tbody>
      <?php foreach ($a['indicators'] as $ind):
        $p = (float)($ind['wartosc_planowana'] ?? 0); $r = (float)($ind['wartosc_realizowana'] ?? 0);
        $pct = $p > 0 ? (int)min(100, round($r / $p * 100)) : 0; ?>
      <tr>
        <td><?= h($ind['nazwa']) ?></td>
        <td class="dz-num"><?= $ind['wartosc_planowana'] !== null ? h(rtrim(rtrim(number_format($p, 2, ',', ' '), '0'), ',')) : '—' ?></td>
        <td class="dz-num"><?= h(rtrim(rtrim(number_format($r, 2, ',', ' '), '0'), ',')) ?></td>
        <td><div class="d-flex align-items-center gap-2"><div class="dz-bar"><span style="width:<?= $pct ?>%;--c:<?= $pct >= 100 ? '#008a00' : ($pct >= 50 ? '#00808a' : '#c25100') ?>"></span></div><small><?= $pct ?>%</small></div></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php else: ?>
    <div class="dz-empty">Brak wskaźników. <a href="<?= h(ActionsService::url($id . '/edycja')) ?>">Dodaj wskaźniki</a>.</div>
    <?php endif; ?>
  </div>

  <aside>
    <h2 class="dz-head">Harmonogram</h2>
    <dl class="dz-kv">
      <dt>Rozpoczęcie</dt><dd><?= h(date_pl($a['data_od'])) ?></dd>
      <dt>Zakończenie</dt><dd><?= h(date_pl($a['data_do'])) ?></dd>
      <dt>Cykliczne</dt><dd><?= $a['cykliczne'] ? 'tak' . (!empty($a['czestotliwosc']) ? ' — ' . h(mb_strtolower(ActionsService::FREQS[$a['czestotliwosc']] ?? $a['czestotliwosc'])) : '') : 'nie' ?></dd>
    </dl>
    <p class="dz-muted small mt-2">Dodano <?= h(date_pl($a['created_at'])) ?> · zmieniono <?= h(date_pl($a['updated_at'])) ?></p>
    <div x-data="dzShow()">
      <?php $jTag = 'h2'; $jClass = 'dz-head'; include __DIR__ . '/_journal.php'; ?>
      <div class="dz-toast" role="status" aria-live="polite"><span x-show="msg" x-text="msg"></span></div>
    </div>
  </aside>
</div>

<?php if ($crmOn): ?>
<section id="uczestnicy" aria-labelledby="dz-crm-h">
  <h2 class="dz-head" id="dz-crm-h">Uczestnicy z CRM <small><?= count($participants) ?></small></h2>
  <div class="dz-grid">
    <div>
      <?php if ($participants): ?>
      <div class="dz-scroll"><table class="dz-table">
        <thead><tr><th scope="col">Kontakt</th><th scope="col">Rola</th><th scope="col">Dodano</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
        <tbody>
        <?php foreach ($participants as $p): ?>
        <tr>
          <td><a class="nm" href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$p['id'] ?>"><?= h($p['imie_nazwisko']) ?></a>
            <?php if (!empty($p['organizacja'])): ?><div class="dz-muted small"><?= h($p['organizacja']) ?></div><?php endif; ?></td>
          <td><span class="dz-chip"><?= h($p['rola']) ?></span><?php if (!empty($p['nota'])): ?><div class="dz-muted small"><?= h($p['nota']) ?></div><?php endif; ?></td>
          <td class="small"><?= h(date_pl($p['added_at'])) ?><?php if (!empty($p['added_by_name'])): ?><div class="dz-muted"><?= h($p['added_by_name']) ?></div><?php endif; ?></td>
          <td class="text-end">
            <form method="post" action="<?= h(ActionsService::url($id . '/uczestnicy/usun')) ?>" onsubmit="return confirm('Usunąć tę osobę z uczestników działania?')">
              <?= Router::csrfField() ?>
              <input type="hidden" name="contact_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="dz-btn dz-btn--danger dz-btn--sm" aria-label="Usuń z uczestników: <?= h($p['imie_nazwisko']) ?>"><i class="bi bi-person-dash" aria-hidden="true"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?>
      <div class="dz-empty">Brak uczestników. Dodaj kontakty z CRM w panelu obok.</div>
      <?php endif; ?>
    </div>
    <div class="dz-block">
      <?php if ($candidates): ?>
      <form method="post" action="<?= h(ActionsService::url($id . '/uczestnicy')) ?>" class="dz-form">
        <?= Router::csrfField() ?>
        <label class="dz-lbl" for="dz-crm-c">Kontakt z CRM</label>
        <select name="contact_id" id="dz-crm-c" class="dz-in mb-2" required>
          <option value="">— wybierz —</option>
          <?php foreach ($candidates as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= h($c['imie_nazwisko']) ?><?= !empty($c['organizacja']) ? ' (' . h($c['organizacja']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <label class="dz-lbl" for="dz-crm-r">Rola</label>
        <select name="rola" id="dz-crm-r" class="dz-in mb-2">
          <?php foreach (ActionsController::crmRoles() as $role): ?>
          <option value="<?= h($role) ?>"><?= h(mb_strtoupper(mb_substr($role, 0, 1)) . mb_substr($role, 1)) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="dz-lbl" for="dz-crm-n">Notatka (opcjonalnie)</label>
        <input type="text" name="nota" id="dz-crm-n" class="dz-in mb-3" maxlength="200" placeholder="np. prelegent główny">
        <button type="submit" class="dz-btn w-100 justify-content-center"><i class="bi bi-person-plus" aria-hidden="true"></i>Dodaj uczestnika</button>
      </form>
      <?php else: ?>
      <p class="mb-0 dz-muted">Wszystkie aktywne kontakty CRM są już uczestnikami.</p>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/crm/index.php" class="dz-btn dz-btn--ghost w-100 justify-content-center mt-2"><i class="bi bi-diagram-2" aria-hidden="true"></i>Otwórz CRM</a>
    </div>
  </div>
</section>
<?php endif; ?>
<?php include __DIR__ . '/_journalScript.php'; ?>
<script>
function dzShow() {
  return {
    ...dzJournalMixin(<?= json_encode(['base' => ActionsService::url(), 'csrf' => Router::csrfToken(), 'today' => date('Y-m-d'),
                                      'users' => array_map(fn($u) => ['id' => (int)$u['id'], 'name' => (string)$u['name']], $users)],
                                     JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>),
    j: <?= json_encode($journal, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,
    jid() { return <?= $id ?>; },
    msg: '',
    say(t) { this.msg = ''; this.$nextTick(() => { this.msg = t; }); clearTimeout(this._t); this._t = setTimeout(() => this.msg = '', 5000); },
  };
}
</script>
<?php include __DIR__ . '/_foot.php'; ?>

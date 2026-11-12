<?php
/**
 * modules/user_merge/view/mergeModal.php — okno porównania i scalania zduplikowanych kont (lista kont Tożsamości).
 *
 * Całe okno (wszystkie grupy) renderuje serwer w HTML strony — Tailwind CDN nie przetwarza treści wstrzykniętej później,
 * a dane do porównania są tanie (liczniki powiązań: jedno GROUP BY na kolumnę). Alpine (+ wtyczka focus z _head.php):
 * otwarcie z plakietki [data-um-open] albo z ?scal=<id>, pułapka fokusu (x-trap), Esc / Anuluj / tło zamykają,
 * fokus wraca na plakietkę. PESEL tylko zamaskowany (fcMask); do przeglądarki nie trafia żaden numer ani jego skrót —
 * zgodność PESEL-i par przekazujemy jako gotowe flagi (strong / conflict).
 *
 * Wejście: userMergeRenderModal($um, $openUid) — $um z userMergeGroups(), $openUid z ?scal= (0 = nie otwieraj).
 */

require_once dirname(__DIR__) . '/lib/userMergeDetect.php';
require_once dirname(__DIR__) . '/lib/userMergeRelations.php';
require_once dirname(__DIR__, 3) . '/modules/field_crypto/lib/fieldCrypto.php';

/** Maska telefonu do podsumowania: •••••• 201. */
function userMergeMaskPhone(string $p): string {
    $d = preg_replace('/\D+/', '', $p);
    return $d === '' ? '' : '••• ••• ' . substr($d, -3);
}

/** Telefon do powiadomień: phone_number, a gdy pusty — alt_phone_number. */
function userMergePhoneOf(array $u): string {
    $p = trim((string)($u['phone_number'] ?? ''));
    return $p !== '' ? $p : trim((string)($u['alt_phone_number'] ?? ''));
}

/** Proponowane konto główne: połączone z Microsoft → najwięcej powiązań → najstarsze. */
function userMergeSuggestPrimary(array $users, array $rel): int {
    $ids = array_keys($users);
    usort($ids, function ($a, $b) use ($users, $rel) {
        $ma = !empty($users[$a]['microsoft_id']); $mb = !empty($users[$b]['microsoft_id']);
        if ($ma !== $mb) return $ma ? -1 : 1;
        $ra = $rel[$a]['total'] ?? 0; $rb = $rel[$b]['total'] ?? 0;
        if ($ra !== $rb) return $rb <=> $ra;
        return strcmp((string)$users[$a]['created_at'], (string)$users[$b]['created_at']);
    });
    return (int)$ids[0];
}

function userMergeRenderModal(array $um, int $openUid = 0): void {
    if (!$um['groups']) return;
    $allIds = array_keys($um['byUser']);
    $ph     = implode(',', array_fill(0, count($allIds), '?'));
    $users  = [];
    foreach (db_all("SELECT * FROM users WHERE id IN ({$ph})", $allIds) as $r) $users[(int)$r['id']] = $r;
    $rel    = userMergeRelationCounts($allIds);
    $roles  = [];
    try { foreach (db_all("SELECT name, display_name FROM roles") as $r) $roles[$r['name']] = $r['display_name']; } catch (\Throwable $e) {}
    $meId   = (int)(current_user()['id'] ?? 0);
    $auto   = $openUid && isset($um['byUser'][$openUid]) ? $um['byUser'][$openUid] : '';
    $action = APP_URL . '/modules/user_merge/router.php?_route=/scal';
    ?>
<div x-data="userMerge(<?= h(json_encode(['groups' => array_keys($um['groups']), 'auto' => $auto])) ?>)" class="um-root">
  <div x-show="open" x-cloak class="tw-fixed tw-inset-0 tw-z-[1060] tw-flex tw-items-start tw-justify-center tw-overflow-y-auto tw-bg-black/50 tw-p-2 sm:tw-p-6" @click.self="close()">
    <div role="dialog" aria-modal="true" :aria-labelledby="'um-title-' + g" :aria-describedby="'um-desc-' + g"
         x-trap.noscroll.inert="open" @keydown.escape.prevent.stop="close()"
         class="tw-my-4 tw-w-full tw-max-w-6xl tw-rounded-lg tw-bg-white tw-text-[#242424] tw-shadow-2xl">
<?php foreach ($um['groups'] as $gid => $g):
    $members = array_values(array_filter($g['members'], fn($id) => isset($users[$id])));
    $n       = count($members);
    $gUsers  = array_intersect_key($users, array_flip($members));
    $primary = userMergeSuggestPrimary($gUsers, $rel);
    // Flagi par (bez numerów PESEL): ten sam PESEL / różne PESEL-e
    $strong = []; $conflict = [];
    foreach ($members as $a) foreach ($members as $b) {
        if ($a >= $b) continue;
        if (userMergePeselMatch($users[$a], $users[$b]))    { $strong[$a . '-' . $b] = true; }
        if (userMergePeselConflict($users[$a], $users[$b])) { $conflict[$a . '-' . $b] = true; }
    }
    $msIds   = array_filter(array_map(fn($id) => trim((string)($users[$id]['microsoft_id'] ?? '')), $members));
    $hasMs   = (bool)$msIds;
    $msClash = count(array_unique($msIds)) > 1;
    $phones  = [];
    foreach ($members as $id) { $p = userMergePhoneOf($users[$id]); if ($p !== '') $phones[preg_replace('/\D+/', '', $p)] = userMergeMaskPhone($p); }
    $emails  = [];
    foreach ($members as $id) { $e = trim((string)$users[$id]['email']); if ($e !== '') $emails[mb_strtolower($e)] = $e; }
    $contracts = [];
    foreach ($members as $id) $contracts[$id] = userMergeContractCounts($users[$id]);
    $cfg = ['members' => array_map('strval', $members), 'primary' => (string)$primary,
            'dups' => array_map('strval', array_values(array_diff($members, [$primary]))),
            'strong' => $strong, 'conflict' => $conflict, 'self' => in_array($meId, $members, true) ? (string)$meId : '',
            'hasMs' => $hasMs, 'needPhone' => !$phones];
    $rowsDef = [
        'ID'                 => fn($u) => '#' . (int)$u['id'],
        'Imię'               => fn($u) => (string)($u['first_name'] ?? ''),
        'Nazwisko'           => fn($u) => (string)($u['last_name'] ?? ''),
        'Nazwa wyświetlana'  => fn($u) => (string)$u['name'],
        'PESEL'              => fn($u) => fcMask(preg_replace('/\D+/', '', (string)($u['rpts_pesel'] ?? '')), 'digits'),
        'E-mail (login)'     => fn($u) => (string)$u['email'],
        'E-mail dodatkowy'   => fn($u) => (string)($u['alt_email'] ?? ''),
        'Telefon'            => fn($u) => (string)($u['phone_number'] ?? ''),
        'Telefon dodatkowy'  => fn($u) => (string)($u['alt_phone_number'] ?? ''),
        'Konto Microsoft 365'=> fn($u) => !empty($u['microsoft_id']) ? 'Połączone' . (!empty($u['m365_login']) ? ' (' . $u['m365_login'] . ')' : '') : 'Brak',
        'Rola'               => fn($u) => $roles[$u['role']] ?? (string)$u['role'],
        'Stan konta'         => fn($u) => !empty($u['is_active']) ? 'Aktywne' : 'Nieaktywne',
        'Utworzone'          => fn($u) => date_pl($u['created_at'] ?? null),
    ];
?>
      <section x-show="g === '<?= h($gid) ?>'" x-data="userMergeForm(<?= h(json_encode($cfg)) ?>)">
        <form method="post" action="<?= h($action) ?>" @submit="if (!canSubmit()) { $event.preventDefault(); return; } busy = true">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="members" value="<?= h(implode(',', $members)) ?>">
          <?php if ($n > 2): ?><input type="hidden" name="dups_explicit" value="1"><?php endif; ?>
          <div class="tw-flex tw-items-start tw-gap-3 tw-border-0 tw-border-b tw-border-solid tw-border-[#e0e0e0] tw-px-5 tw-py-4">
            <div class="tw-min-w-0 tw-flex-1">
              <h2 id="um-title-<?= h($gid) ?>" class="tw-m-0 tw-text-xl tw-font-semibold">
                <?= h(userMergeLabel($n)) ?> — możliwy duplikat konta
              </h2>
              <p id="um-desc-<?= h($gid) ?>" class="tw-m-0 tw-mt-1 tw-text-sm tw-text-[#424242]">
                <?= $g['strong']
                    ? 'Konta mają ten sam numer PESEL' . (in_array('name', $g['reasons'], true) ? ' lub to samo imię i nazwisko' : '') . '.'
                    : 'Konta mają to samo imię i nazwisko, ale nie ten sam PESEL — mogą należeć do różnych osób (imienników).' ?>
                Porównaj dane i wybierz konto główne.
              </p>
            </div>
            <button type="button" class="tz-btn tz-btn--subtle" @click="close()" aria-label="Zamknij okno porównania"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
          </div>

          <div class="tw-px-5 tw-py-4">
            <div class="tw-overflow-x-auto" role="region" aria-label="Porównanie kont" tabindex="0">
              <table class="tw-w-full tw-border-collapse tw-text-sm">
                <caption class="tw-sr-only">Porównanie danych kont — kolumna na konto; w nagłówku kolumny wybór konta głównego</caption>
                <thead>
                  <tr>
                    <th scope="col" class="tw-w-44 tw-border-0 tw-border-b tw-border-solid tw-border-[#d1d1d1] tw-p-2 tw-text-left tw-align-bottom">Pole</th>
                    <?php foreach ($members as $id): $u = $users[$id]; ?>
                    <th scope="col" class="tw-min-w-[13rem] tw-border-0 tw-border-b tw-border-solid tw-border-[#d1d1d1] tw-p-2 tw-text-left tw-align-bottom"
                        :class="primary === '<?= $id ?>' ? 'um-pri' : ''">
                      <div class="tw-font-semibold"><?= h($u['name']) ?> <span class="tw-font-normal tw-text-[#616161]">#<?= $id ?></span></div>
                      <label class="tw-mt-1 tw-flex tw-cursor-pointer tw-items-center tw-gap-2 tw-font-normal">
                        <input type="radio" name="primary" value="<?= $id ?>" x-model="primary" class="tw-h-4 tw-w-4">
                        Konto główne
                      </label>
                      <?php if ($n > 2): ?>
                      <label class="tw-mt-1 tw-flex tw-cursor-pointer tw-items-center tw-gap-2 tw-font-normal" x-show="primary !== '<?= $id ?>'">
                        <input type="checkbox" name="dups[]" value="<?= $id ?>" x-model="dups" :disabled="primary === '<?= $id ?>'" class="tw-h-4 tw-w-4">
                        Scal z kontem głównym
                      </label>
                      <?php endif; ?>
                      <div class="tw-mt-1 tw-text-xs tw-font-normal">
                        <span x-show="primary === '<?= $id ?>'" class="tz-badge tz-badge--info"><i class="bi bi-star-fill" aria-hidden="true"></i>Zostaje — konto główne</span>
                        <span x-show="primary !== '<?= $id ?>' && sel().includes('<?= $id ?>')" class="tz-badge tz-badge--warn"><i class="bi bi-archive" aria-hidden="true"></i>Zostanie zarchiwizowane</span>
                        <span x-show="primary !== '<?= $id ?>' && !sel().includes('<?= $id ?>')" class="tz-badge tz-badge--off">Bez zmian</span>
                      </div>
                    </th>
                    <?php endforeach; ?>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($rowsDef as $label => $fn):
                    $vals = array_map(fn($id) => $fn($users[$id]), $members);
                    $nonEmpty = array_filter($vals, fn($v) => $v !== '' && $v !== '—');
                    $differs = $label !== 'ID' && count(array_unique(array_map('mb_strtolower', $nonEmpty))) > 1; ?>
                  <tr>
                    <th scope="row" class="tw-border-0 tw-border-b tw-border-solid tw-border-[#e0e0e0] tw-p-2 tw-text-left tw-font-semibold tw-align-top">
                      <?= h($label) ?>
                      <?php if ($differs): ?><span class="tw-ml-1 tw-whitespace-nowrap tw-rounded tw-bg-[#fff9f5] tw-px-1 tw-text-xs tw-font-semibold tw-text-[#8a3707]">różne</span><?php endif; ?>
                    </th>
                    <?php foreach ($vals as $i => $v): ?>
                    <td class="tw-break-words tw-border-0 tw-border-b tw-border-solid tw-border-[#e0e0e0] tw-p-2 tw-align-top" :class="primary === '<?= $members[$i] ?>' ? 'um-pri' : ''">
                      <?= $v !== '' ? h($v) : '<span class="tw-text-[#616161]">—<span class="tw-sr-only"> brak</span></span>' ?>
                    </td>
                    <?php endforeach; ?>
                  </tr>
                  <?php endforeach; ?>
                  <tr>
                    <th scope="row" class="tw-border-0 tw-border-b tw-border-solid tw-border-[#e0e0e0] tw-p-2 tw-text-left tw-font-semibold tw-align-top">Umowy</th>
                    <?php foreach ($members as $id): $cc = $contracts[$id]; ?>
                    <td class="tw-border-0 tw-border-b tw-border-solid tw-border-[#e0e0e0] tw-p-2 tw-align-top" :class="primary === '<?= $id ?>' ? 'um-pri' : ''">
                      <?php if (!$cc): ?><span class="tw-text-[#616161]">Brak</span>
                      <?php else: ?><ul class="tw-m-0 tw-list-none tw-p-0"><?php foreach ($cc as $type => $cnt): ?><li><?= h(userMergeContractTypeLabel($type)) ?>: <strong><?= (int)$cnt ?></strong></li><?php endforeach; ?></ul><?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                  </tr>
                  <tr>
                    <th scope="row" class="tw-border-0 tw-border-b tw-border-solid tw-border-[#e0e0e0] tw-p-2 tw-text-left tw-font-semibold tw-align-top">Powiązane rekordy<br><span class="tw-text-xs tw-font-normal tw-text-[#616161]">zadania, EZD, CRM, TI, historia…</span></th>
                    <?php foreach ($members as $id): $r = $rel[$id] ?? ['total' => 0, 'tables' => 0, 'items' => []]; ?>
                    <td class="tw-border-0 tw-border-b tw-border-solid tw-border-[#e0e0e0] tw-p-2 tw-align-top" :class="primary === '<?= $id ?>' ? 'um-pri' : ''">
                      <?php if (!$r['total']): ?><span class="tw-text-[#616161]">Brak</span>
                      <?php else: ?>
                      <details>
                        <summary class="tw-cursor-pointer"><strong><?= (int)$r['total'] ?></strong> w <?= (int)$r['tables'] ?> <?= $r['tables'] === 1 ? 'tabeli' : 'tabelach' ?></summary>
                        <ul class="tw-m-0 tw-mt-1 tw-max-h-48 tw-list-none tw-overflow-y-auto tw-p-0 tw-text-xs">
                          <?php foreach ($r['items'] as $it): ?><li><?= h($it['label']) ?>: <strong><?= (int)$it['n'] ?></strong></li><?php endforeach; ?>
                        </ul>
                      </details>
                      <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="tw-mt-4 tw-rounded-lg tw-bg-[#faf9f8] tw-px-4 tw-py-3 tw-text-sm">
              <h3 class="tw-m-0 tw-mb-1 tw-text-base tw-font-semibold">Co się stanie po scaleniu</h3>
              <ul class="tw-m-0 tw-pl-5">
                <li>Umowy, zadania, dokumenty, historia i pozostałe powiązania przejdą na konto główne (umowy dostaną jego adres e-mail).</li>
                <li>Puste pola konta głównego uzupełnimy danymi z pozostałych kont — wypełnionych nie nadpisujemy.</li>
                <li>Pozostałe konta zostaną <strong>zarchiwizowane</strong> (nieaktywne, oznaczone jako scalone) — nic nie usuwamy.</li>
                <li>Powstanie nowe hasło tymczasowe (16 znaków); system wymusi jego zmianę przy pierwszym logowaniu.
                  <?php if ($phones): ?>Przyjdzie SMS-em na: <?= h(implode(', ', $phones)) ?>.<?php else: ?>Przyjdzie SMS-em na numer podany niżej.<?php endif; ?></li>
                <li>Potwierdzenie (bez hasła) wyślemy e-mailem na: <?= h(implode(', ', array_map(fn($e) => fcMask($e, 'email'), $emails))) ?>.</li>
                <?php if ($hasMs): ?><li>Logowanie kontem Microsoft zostanie zachowane na koncie głównym.</li><?php endif; ?>
              </ul>
            </div>

            <?php if (!$phones): $pid = 'um-phone-' . $gid; ?>
            <div class="tw-mt-3 tw-rounded-lg tw-border tw-border-solid tw-border-[#bc4b09] tw-bg-[#fff9f5] tw-px-4 tw-py-3 tw-text-sm">
              <p class="tw-m-0 tw-text-[#8a3707]"><i class="bi bi-telephone-plus" aria-hidden="true"></i>
                <strong>Żadne z kont nie ma numeru telefonu.</strong> Hasło tymczasowe wysyłamy SMS-em — dodaj numer tej osoby.
                Zapiszemy go na koncie głównym.</p>
              <label for="<?= h($pid) ?>" class="tw-mt-2 tw-block tw-font-semibold tw-text-[#242424]">Numer telefonu komórkowego <span class="tw-font-normal">(wymagany)</span></label>
              <input id="<?= h($pid) ?>" name="phone" type="tel" inputmode="tel" autocomplete="off" required maxlength="20"
                     x-model="phone" @blur="phoneTouched = true" placeholder="np. 600 100 200"
                     :aria-invalid="(phoneTouched && !phoneValid()) ? 'true' : 'false'"
                     :aria-describedby="'<?= h($pid) ?>-help' + ((phoneTouched && !phoneValid()) ? ' <?= h($pid) ?>-err' : '')"
                     aria-describedby="<?= h($pid) ?>-help"
                     class="form-control tw-mt-1 tw-max-w-xs">
              <p id="<?= h($pid) ?>-help" class="tw-m-0 tw-mt-1 tw-text-xs tw-text-[#424242]">9 cyfr albo numer z kierunkowym kraju, np. +48 600 100 200.</p>
              <p id="<?= h($pid) ?>-err" class="tw-m-0 tw-mt-1 tw-text-xs tw-font-semibold tw-text-[#b10e1c]" x-show="phoneTouched && !phoneValid()">
                <i class="bi bi-exclamation-circle" aria-hidden="true"></i> Wpisz poprawny numer: 9 cyfr albo z kierunkowym kraju.</p>
            </div>
            <?php endif; ?>
            <?php if ($msClash): ?>
            <p class="tw-m-0 tw-mt-3 tw-rounded-lg tw-border tw-border-solid tw-border-[#bc4b09] tw-bg-[#fff9f5] tw-px-4 tw-py-2 tw-text-sm tw-text-[#8a3707]">
              <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
              <strong>Konta są połączone z różnymi kontami Microsoft.</strong> Zachowamy konto Microsoft konta głównego; drugie konto
              Microsoft nie zostanie zmienione w Entra ID (zostaje przy archiwum).
            </p>
            <?php endif; ?>

            <fieldset class="tw-m-0 tw-mt-4 tw-border-0 tw-p-0">
              <legend class="tw-mb-2 tw-text-base tw-font-semibold">
                <?= $n === 2 ? 'Czy zmigrować i scalić te dwa konta w jedno?' : 'Czy zmigrować i scalić zaznaczone konta w jedno?' ?>
              </legend>
              <?php if ($hasMs): ?>
              <label class="tw-flex tw-cursor-pointer tw-items-start tw-gap-2 tw-text-sm">
                <input type="checkbox" name="sync_m365" value="1" x-model="sync" class="tw-mt-0.5 tw-h-4 tw-w-4" :disabled="!phoneOk()">
                <span>Ustaw to samo hasło tymczasowe także w Microsoft 365 (Entra ID) i wymuś jego zmianę przy pierwszym logowaniu.
                  <span class="tw-block tw-text-xs tw-text-[#616161]">Nie zadziała dla kont z rolą administratora Entra ani kont synchronizowanych z AD lokalnego — błąd pokażemy, scalenie zostanie.</span></span>
              </label>
              <?php endif; ?>
              <label class="tw-mt-2 tw-flex tw-cursor-pointer tw-items-start tw-gap-2 tw-text-sm" x-show="needsConfirm()">
                <input type="checkbox" name="confirm_same" value="1" x-model="confirm" class="tw-mt-0.5 tw-h-4 tw-w-4">
                <span><strong>Sprawdziłem, że to ta sama osoba</strong> — konta nie mają wspólnego numeru PESEL, więc mogą należeć do imienników.</span>
              </label>
            </fieldset>

            <p class="tw-m-0 tw-mt-3 tw-text-sm tw-font-semibold tw-text-[#b10e1c]" aria-live="polite" x-text="reason()"></p>
          </div>

          <div class="tw-flex tw-flex-wrap tw-justify-end tw-gap-2 tw-border-0 tw-border-t tw-border-solid tw-border-[#e0e0e0] tw-px-5 tw-py-3">
            <button type="button" class="tz-btn tz-btn--ghost" @click="close()">Anuluj</button>
            <button type="submit" class="tz-btn" :disabled="!canSubmit() || busy" :aria-disabled="(!canSubmit() || busy) ? 'true' : 'false'">
              <i class="bi bi-intersect" aria-hidden="true"></i><span x-text="busy ? 'Scalanie…' : 'Tak, zmigruj'">Tak, zmigruj</span>
            </button>
          </div>
        </form>
      </section>
<?php endforeach; ?>
    </div>
  </div>
</div>
<style>
  .um-root .um-pri{background:#ebf3fc}
  .um-root .tz-btn[disabled]{opacity:.55;cursor:not-allowed}
  .um-root input[type=radio],.um-root input[type=checkbox]{accent-color:var(--tz)}
  .um-root :focus-visible{outline:3px solid var(--tz-focus,#242424);outline-offset:2px}
</style>
<script>
document.addEventListener('alpine:init', function () {
  // Okno: która grupa jest otwarta, otwieranie z plakietek [data-um-open] i z ?scal=, powrót fokusu.
  Alpine.data('userMerge', function (cfg) {
    return {
      open: false, g: '', opener: null,
      init: function () {
        var self = this;
        document.addEventListener('click', function (e) {
          var a = e.target.closest ? e.target.closest('[data-um-open]') : null;
          if (!a || cfg.groups.indexOf(a.getAttribute('data-um-open')) < 0) return;
          e.preventDefault();
          self.show(a.getAttribute('data-um-open'), a);
        });
        if (cfg.auto) this.$nextTick(function () { self.show(cfg.auto, document.querySelector('[data-um-open="' + cfg.auto + '"]')); });
      },
      show: function (g, opener) { this.g = g; this.opener = opener || null; this.open = true; },
      close: function () {
        this.open = false;
        var o = this.opener;
        this.$nextTick(function () { if (o && document.body.contains(o)) o.focus(); });
      }
    };
  });
  // Formularz grupy: wybór konta głównego i scalanych, warunki przycisku „Tak, zmigruj”.
  Alpine.data('userMergeForm', function (cfg) {
    return {
      primary: cfg.primary, dups: cfg.dups.slice(), sync: true, confirm: false, busy: false, phone: '', phoneTouched: false,
      init: function () {
        var self = this;   // poprzednie konto główne wraca na listę scalanych
        this.$watch('primary', function (v, old) { if (old && old !== v && self.dups.indexOf(old) < 0) self.dups.push(old); });
      },
      pair: function (a, b) { return (+a < +b) ? a + '-' + b : b + '-' + a; },
      sel: function () {
        var self = this;
        return cfg.members.filter(function (id) {
          return id !== self.primary && (cfg.members.length === 2 || self.dups.indexOf(id) >= 0);
        });
      },
      needsConfirm: function () {
        var self = this;
        return this.sel().some(function (id) { return !cfg.strong[self.pair(self.primary, id)]; });
      },
      // Numer jak po stronie serwera (UserMergeService::normalizePhone): 9 cyfr albo 11–15 z kierunkowym (prefiks 00 pomijany).
      phoneValid: function () {
        var d = String(this.phone || '').replace(/\D+/g, '');
        if (d.indexOf('00') === 0) d = d.slice(2);
        return d.length === 9 || (d.length >= 11 && d.length <= 15);
      },
      phoneOk: function () { return !cfg.needPhone || this.phoneValid(); },
      reason: function () {
        var self = this, s = this.sel();
        if (!s.length) return 'Zaznacz co najmniej jedno konto do scalenia.';
        if (s.some(function (id) { return cfg.conflict[self.pair(self.primary, id)]; }))
          return 'Konto główne i scalane konto mają różne numery PESEL — to różne osoby. Scalenie jest zablokowane.';
        if (cfg.self && s.indexOf(cfg.self) >= 0) return 'Nie możesz zarchiwizować własnego konta — wybierz je jako konto główne.';
        if (!this.phoneOk()) return 'Podaj numer telefonu — wyślemy na niego hasło tymczasowe.';
        if (this.needsConfirm() && !this.confirm) return 'Potwierdź, że to ta sama osoba.';
        return '';
      },
      canSubmit: function () { return this.reason() === ''; }
    };
  });
});
</script>
<?php
}

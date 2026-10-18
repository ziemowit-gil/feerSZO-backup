<?php
/**
 * panel/includes/pv_contract_choose.php — wybór kontekstu (umowy) w Panelu współpracownika.
 *
 * Pokazywany przez panel/index.php zamiast pulpitu, gdy konto ma WIĘCEJ NIŻ JEDNĄ umowę,
 * a w tej sesji nie wybrano jeszcze żadnej ($_SESSION['panel_contract_chosen']) — albo
 * na żądanie (?wybierz=1). Każdy kafel to formularz POST z istniejącym handlerem
 * `_select_contract` w index.php (walidacja umowy względem panel_contracts()).
 * Szata METRO jak System Tożsamości (modules/panel_metro) — działa w obu powłokach.
 *
 * Wejście (z index.php): $contracts, $_active_contract, $user, $_vol_color (opcjonalnie).
 */
if (!defined('APP_URL')) { return; }
require_once dirname(__DIR__, 2) . '/modules/panel_metro/lib/panelMetro.php';
panelMetroAssets($_vol_color ?? '#0f6fa8');

$_cc_types  = ['wolontariat' => 'Wolontariat', 'zlecenie' => 'Umowa zlecenie', 'dzielo' => 'Umowa o dzieło', 'praca' => 'Umowa o pracę'];
$_cc_icons  = ['wolontariat' => 'bi-heart-fill', 'zlecenie' => 'bi-person-workspace', 'dzielo' => 'bi-brush', 'praca' => 'bi-briefcase-fill'];
$_cc_colors = ['wolontariat' => 'magenta', 'zlecenie' => 'blue', 'dzielo' => 'violet', 'praca' => 'indigo'];
$_cc_status = ['projekt' => 'w przygotowaniu', 'podpisana' => 'aktywna', 'w realizacji' => 'aktywna',
               'zakończona' => 'zakończona', 'rozwiązana' => 'rozwiązana', 'anulowana' => 'anulowana'];
$_cc_ended  = ['zakończona', 'rozwiązana', 'anulowana'];

// Najpierw umowy trwające, potem zakończone (kolejność w grupie bez zmian).
$_cc_list = $contracts;
usort($_cc_list, fn($a, $b) => (int)in_array($a['status'] ?? '', $_cc_ended, true) <=> (int)in_array($b['status'] ?? '', $_cc_ended, true));
$_cc_first = trim(explode(' ', trim((string)($user['name'] ?? '')))[0] ?? '');
?>
<style>
.pvm .cc-tile{width:100%;cursor:pointer;font:inherit}
.pvm .cc-form{margin:0;display:contents}
.pvm .cc-note{margin-top:1.75rem;font-size:.85rem;color:var(--pvm-muted);max-width:70ch}
</style>
<div class="pvm">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Panel współpracownika · wybór kontekstu</div>
      <h1 class="mt-title"><?= $_cc_first !== '' ? h($_cc_first) . ', w' : 'W' ?> której umowie chcesz pracować?</h1>
      <p class="mt-lead">Masz <?= count($contracts) ?> umowy powiązane z kontem. Pulpit, sprawy, wiadomości i dokumenty pokażą się dla wybranej umowy — zmienisz ją w każdej chwili przyciskiem „Zmień umowę”.</p>
    </div>
  </div>

  <h2 class="visually-hidden">Twoje umowy</h2>
  <div class="mt-grid" role="list">
    <?php foreach ($_cc_list as $c):
      $type   = (string)$c['contract_type'];
      $ended  = in_array($c['status'] ?? '', $_cc_ended, true);
      $last   = $_active_contract && $type === $_active_contract['contract_type'] && (int)$c['id'] === (int)$_active_contract['id'];
      $color  = panelMetroColor($ended ? 'grey' : ($_cc_colors[$type] ?? 'teal'));
      $label  = $_cc_types[$type] ?? (CONTRACT_TYPES[$type] ?? ucfirst($type));
      $nr     = $c['numer_umowy'] ?: ('Umowa #' . (int)$c['id']);
      $st     = $_cc_status[$c['status'] ?? ''] ?? (string)($c['status'] ?? '');
      $dates  = trim(($c['data_zawarcia'] ? 'od ' . date('d.m.Y', strtotime($c['data_zawarcia'])) : '')
              . ($c['data_zakonczenia'] ? ' do ' . date('d.m.Y', strtotime($c['data_zakonczenia'])) : ''));
      $who    = !empty($c['_is_guardian']) ? ('jako opiekun · ' . ($c['_child_name'] ?? 'dziecko')) : '';
      $aria   = $label . ' ' . $nr . ', ' . $st . ($dates ? ', ' . $dates : '') . ($who ? ', ' . $who : '') . ($last ? ' — ostatnio wybrana' : '');
    ?>
    <form method="post" action="<?= APP_URL ?>/panel/index.php" class="cc-form" role="listitem">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_select_contract" value="1">
      <input type="hidden" name="contract_type" value="<?= h($type) ?>">
      <input type="hidden" name="contract_id" value="<?= (int)$c['id'] ?>">
      <button type="submit" class="mt-tile mt-tile--w cc-tile" style="--c:<?= h($color) ?>" aria-label="<?= h($aria) ?>">
        <span class="tag" aria-hidden="true"><?= h($last ? 'ostatnio' : $st) ?></span>
        <i class="bi <?= h($_cc_icons[$type] ?? 'bi-file-earmark-text') ?> ic" aria-hidden="true"></i>
        <span aria-hidden="true">
          <span class="val" style="font-size:1.45rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($nr) ?></span>
          <span class="lbl"><?= h($label) ?><?= $who ? ' · ' . h($who) : '' ?></span>
          <span class="sub"><?= h($dates ?: $st) ?></span>
        </span>
      </button>
    </form>
    <?php endforeach; ?>
  </div>

  <p class="cc-note"><i class="bi bi-info-circle" aria-hidden="true"></i>
    Wybór obowiązuje do wylogowania. Konto, hasło i dostęp do Microsoft 365 są wspólne dla wszystkich umów — zarządzasz nimi w Systemie Tożsamości.</p>
</div>

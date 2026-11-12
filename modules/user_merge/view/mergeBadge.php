<?php
/**
 * modules/user_merge/view/mergeBadge.php — plakietki „Wykryto N osób” i podsumowanie duplikatów (lista kont Tożsamości).
 *
 * Plakietka jest linkiem do listy z filtrem duplikatów i ?scal=<id> (działa bez JS); skrypt modala porównawczego
 * przechwytuje kliknięcie (data-um-open) i otwiera okno w miejscu. Kolor nie jest jedynym nośnikiem informacji —
 * rodzaj dopasowania jest zawsze dopisany tekstem (WCAG 1.4.1). Kontrast tekstu ≥ 7:1.
 */

require_once dirname(__DIR__) . '/lib/userMergeDetect.php';

/** Adres listy kont otwierający porównanie grupy, do której należy konto. */
function userMergeOpenUrl(int $uid): string {
    $base = defined('TZ_USERS_URL') ? TZ_USERS_URL : APP_URL . '/tozsamosc/uzytkownicy.php';
    return $base . '?' . http_build_query(['duplikaty' => '1', 'scal' => $uid]);
}

/** Plakietka-przycisk przy koncie, które ma możliwy duplikat ('' gdy nie ma). */
function userMergeBadge(int $uid, ?array $um = null): string {
    $um ??= userMergeGroups();
    $gid = $um['byUser'][$uid] ?? null;
    if ($gid === null) return '';
    $g      = $um['groups'][$gid];
    $n      = count($g['members']);
    $strong = $g['strong'];
    $why    = $strong ? 'ten sam PESEL' : 'zgodne imię i nazwisko';
    $cls    = $strong
        ? 'tw-bg-red-50 tw-text-red-800 tw-border-red-700 hover:tw-bg-red-100 hover:tw-text-red-900'
        : 'tw-bg-amber-50 tw-text-amber-900 tw-border-amber-700 hover:tw-bg-amber-100 hover:tw-text-amber-950';
    $icon   = $strong ? 'bi-people-fill' : 'bi-people';
    return '<a href="' . h(userMergeOpenUrl($uid)) . '" data-um-open="' . h($gid) . '" aria-haspopup="dialog"'
         . ' class="um-badge tw-inline-flex tw-items-center tw-gap-1 tw-rounded tw-border tw-border-solid tw-px-2 tw-py-0.5'
         . ' tw-text-xs tw-font-semibold tw-leading-5 tw-no-underline tw-whitespace-nowrap ' . $cls . '">'
         . '<i class="bi ' . $icon . '" aria-hidden="true"></i>'
         . '<span>' . h(userMergeLabel($n)) . '</span>'
         . '<span class="tw-font-normal">· ' . h($why) . '</span></a>';
}

/** Oznaczenie konta już scalonego (archiwum). */
function userMergeMergedBadge(array $u): string {
    $into = (int)($u['merged_into_id'] ?? 0);
    if (!$into) return '';
    $when = trim((string)($u['merged_at'] ?? ''));
    return '<span class="tz-badge tz-badge--off" title="Konto zarchiwizowane przy scalaniu' . ($when !== '' ? ' (' . h($when) . ')' : '') . '">'
         . '<i class="bi bi-archive" aria-hidden="true"></i>Scalone z kontem #' . $into . '</span>';
}

/** Ramka z podsumowaniem nad listą kont ('' gdy duplikatów brak). */
function userMergeSummary(array $um, bool $filterOn, callable $url): string {
    $groups = $um['groups'];
    if (!$groups && !$filterOn) return '';
    $nGroups = count($groups);
    $nUsers  = count($um['byUser']);
    $nStrong = count(array_filter($groups, fn($g) => $g['strong']));
    $nWeak   = $nGroups - $nStrong;

    ob_start(); ?>
<section class="tw-mb-3 tw-flex tw-flex-wrap tw-items-center tw-gap-3 tw-rounded-lg tw-border tw-border-solid tw-border-amber-600 tw-bg-amber-50 tw-px-4 tw-py-3 tw-text-amber-950" aria-labelledby="um-sum-h">
  <i class="bi bi-people tw-text-xl" aria-hidden="true"></i>
  <div class="tw-min-w-0 tw-flex-1">
    <h2 id="um-sum-h" class="tw-m-0 tw-text-base tw-font-semibold">Możliwe duplikaty kont</h2>
    <?php if ($nGroups): ?>
    <p class="tw-m-0 tw-text-sm">
      Grup: <strong><?= $nGroups ?></strong> (kont: <?= $nUsers ?>)<?php
      $bits = [];
      if ($nStrong) $bits[] = $nStrong . ' z tym samym PESEL-em';
      if ($nWeak)   $bits[] = $nWeak . ' zgodnych tylko imieniem i nazwiskiem (sprawdź, czy to ta sama osoba)';
      echo $bits ? ' — ' . h(implode(', ', $bits)) : ''; ?>.
      Kliknij plakietkę „Wykryto…” przy osobie, aby porównać konta i je scalić.
    </p>
    <?php else: ?>
    <p class="tw-m-0 tw-text-sm">Nie wykryto duplikatów.</p>
    <?php endif; ?>
  </div>
  <?php if ($filterOn): ?>
  <a class="tz-btn tz-btn--ghost" href="<?= h($url(['duplikaty' => '', 'scal' => ''])) ?>">Pokaż wszystkie konta</a>
  <?php else: ?>
  <a class="tz-btn tz-btn--ghost" href="<?= h($url(['duplikaty' => '1'])) ?>">Pokaż tylko duplikaty</a>
  <?php endif; ?>
</section>
<?php
    return (string)ob_get_clean();
}

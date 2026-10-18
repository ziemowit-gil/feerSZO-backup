<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';

require_login();
directory_migrate();

$profile_id = (int)($_GET['id'] ?? 0);
if (!$profile_id) { header('Location: ' . APP_URL . '/directory/'); exit; }

$person = directory_get_profile($profile_id);
if (!$person) {
    http_response_code(404);
    $PAGE_TITLE = 'Nie znaleziono';
    include __DIR__ . '/includes/header_dir.php';
    echo '<div class="alert alert-warning" role="alert">Nie znaleziono użytkownika.</div>';
    include __DIR__ . '/includes/footer_dir.php';
    exit;
}

$cu         = current_user();
$is_own     = $cu && (int)$cu['id'] === $profile_id;
$can_edit   = $is_own || is_admin();
$display    = directory_display_name($person);
$field_defs = directory_get_field_defs();

$PAGE_TITLE = $display ?: 'Profil użytkownika';

require_once dirname(__DIR__) . '/modules/katalog_metro/lib/katalogMetro.php';
$editHref = APP_URL . '/directory/profile_edit.php' . (!$is_own ? '?id=' . $profile_id : '');
$hasPhone = $person['phone_public'] && $person['phone_display'];
$filled   = array_filter($person['field_values'] ?? [], fn($v) => trim((string)$v) !== '');

include __DIR__ . '/includes/header_dir.php';
katalogMetroAssets();
?>
<div class="pvm ktm">

  <div class="mt-head">
    <div>
      <div class="mt-kicker"><a href="<?= APP_URL ?>/directory/" style="color:inherit">Katalog współpracowników</a><?= $person['unit_name'] ? ' · ' . h($person['unit_name']) : '' ?></div>
      <h1 class="mt-title"><?= h($display) ?></h1>
      <?php if ($person['position_name']): ?><p class="mt-lead"><?= h($person['position_name']) ?></p><?php endif; ?>
    </div>
  </div>

<?php
// Akcje w kolumnie bocznej (jak „Najważniejsze narzędzia” na pulpicie Panelu)
$acts = [['href' => 'mailto:' . $person['email'], 'icon' => 'bi-envelope', 'label' => 'Napisz e-mail', 'sub' => $person['email'], 'c' => panelMetroColor('blue')]];
if ($hasPhone) $acts[] = ['href' => 'tel:' . preg_replace('/[^\d+]/', '', $person['phone_display']), 'icon' => 'bi-telephone', 'label' => 'Zadzwoń', 'sub' => $person['phone_display'], 'c' => panelMetroColor('green')];
if ($person['unit_name']) $acts[] = ['href' => APP_URL . '/directory/org_chart.php?unit=' . (int)$person['unit_id'] . '#jednostka', 'icon' => 'bi-diagram-3', 'label' => $person['unit_name'], 'sub' => 'jednostka · skład', 'c' => panelMetroColor('teal')];
if ($can_edit) $acts[] = ['href' => $editHref, 'icon' => 'bi-pencil-square', 'label' => $is_own ? 'Edytuj mój profil' : 'Edytuj profil', 'sub' => 'zdjęcie, opis, umiejętności', 'c' => panelMetroColor('orange')];
$acts[] = ['href' => APP_URL . '/directory/', 'icon' => 'bi-arrow-left', 'label' => 'Wszyscy', 'sub' => 'wróć do katalogu', 'c' => panelMetroColor('dark')];
$person['phone'] = $hasPhone ? $person['phone_display'] : '';
?>
  <div class="ktm-prof">
    <div class="ktm-prof-main">
      <div class="ktm-prof-top">
        <div class="mt-grid ktm-prof-card">
          <?php katalogPersonTile($person, ['size' => 'l', 'color' => katalogUnitColor((int)($person['unit_id'] ?? $profile_id)),
              'href' => 'mailto:' . $person['email'], 'sub' => $person['email'], 'aria' => $display . ' — napisz e-mail: ' . $person['email'],
              'tag' => $person['is_head'] ? 'kierownik' : '']); ?>
        </div>
        <section class="mt-group" aria-labelledby="section-contact">
          <h2 class="mt-h2" id="section-contact">Dane</h2>
          <dl class="mt-kv">
            <div><dt>E-mail</dt><dd><a href="mailto:<?= h($person['email']) ?>"><?= h($person['email']) ?></a></dd></div>
            <?php if ($hasPhone): ?><div><dt>Telefon</dt><dd><a href="tel:<?= h($person['phone_display']) ?>"><?= h($person['phone_display']) ?></a></dd></div><?php endif; ?>
            <?php if ($person['unit_name']): ?><div><dt>Jednostka</dt><dd><?= h($person['unit_name']) ?></dd></div><?php endif; ?>
            <?php if ($person['position_name']): ?><div><dt>Stanowisko</dt><dd><?= h($person['position_name']) ?></dd></div><?php endif; ?>
            <?php foreach ($field_defs as $fd):
                $val = $person['field_values'][(int)$fd['id']] ?? '';
                if ($val === '') continue; ?>
            <div><dt><?= h($fd['label']) ?></dt><dd<?= $fd['field_type'] === 'textarea' ? ' style="white-space:pre-wrap"' : '' ?>>
              <?php if ($fd['field_type'] === 'url'): ?><a href="<?= h($val) ?>" target="_blank" rel="noopener noreferrer"><?= h($val) ?><span class="visually-hidden"> (otwiera się w nowej karcie)</span></a>
              <?php else: ?><?= h($val) ?><?php endif; ?>
            </dd></div>
            <?php endforeach; ?>
          </dl>
        </section>
      </div>

  <?php if (!empty($person['bio'])): ?>
  <section class="mt-group" aria-labelledby="section-bio">
    <h2 class="mt-h2" id="section-bio">O mnie</h2>
    <p style="white-space:pre-wrap;font-weight:300;font-size:1.02rem;line-height:1.6;max-width:75ch"><?= h($person['bio']) ?></p>
  </section>
  <?php endif; ?>

  <?php if (!empty($person['skills_array'])): ?>
  <section class="mt-group" aria-labelledby="section-skills">
    <h2 class="mt-h2" id="section-skills">Umiejętności</h2>
    <ul class="ktm-tags">
      <?php foreach ($person['skills_array'] as $skill): ?><li><?= h($skill) ?></li><?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <?php if (empty($person['bio']) && empty($person['skills_array']) && !$filled): ?>
  <div class="ktm-empty" role="status">
    Profil nie został jeszcze uzupełniony.
    <?php if ($can_edit): ?><p class="mt-3 mb-0"><a class="ktm-chip" href="<?= h($editHref) ?>"><i class="bi bi-pencil" aria-hidden="true"></i>Uzupełnij profil</a></p><?php endif; ?>
  </div>
  <?php endif; ?>
    </div>

    <aside class="ktm-side" aria-labelledby="ktm-side-h">
      <h2 id="ktm-side-h">Akcje</h2>
      <ul>
        <?php foreach ($acts as $a): ?>
        <li><a href="<?= h($a['href']) ?>">
          <span class="sq" style="--c:<?= h($a['c']) ?>" aria-hidden="true"><i class="bi <?= h($a['icon']) ?>"></i></span>
          <span style="min-width:0"><b><?= h($a['label']) ?></b><small><?= h($a['sub']) ?></small></span>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </aside>
  </div>
</div>

<?php include __DIR__ . '/includes/footer_dir.php'; ?>

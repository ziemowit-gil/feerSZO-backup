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

include __DIR__ . '/includes/header_dir.php';
?>

<?php
// Przygotuj avatar HTML z poprawnym alt/aria
$_avatar_hero = directory_avatar_html($person, 80);
if (strpos($_avatar_hero, '<img') !== false) {
    $_avatar_hero = preg_replace('/<img\b([^>]*?)(?:\s+alt="[^"]*")?([^>]*?)>/', '<img$1 alt="' . h($display) . '"$2>', $_avatar_hero);
} elseif (strpos($_avatar_hero, '<span') !== false) {
    $_avatar_hero = preg_replace('/<span\b/', '<span aria-label="' . h($display) . '"', $_avatar_hero, 1);
}
?>

<h1 class="visually-hidden"><?= h($display) ?></h1>

<!-- ── Hero banner ───────────────────────────────────────────────────── -->
<div class="dir-profile-hero" role="banner">
  <div class="dir-profile-hero-avatar">
    <?= $_avatar_hero ?>
  </div>
  <div class="dir-profile-hero-body">
    <div class="dir-profile-hero-name"><?= h($display) ?></div>
    <?php if ($person['position_name']): ?>
    <div class="dir-profile-hero-pos">
      <i class="bi bi-briefcase me-1" aria-hidden="true"></i><?= h($person['position_name']) ?>
    </div>
    <?php endif; ?>
    <?php if ($person['unit_name']): ?>
    <div class="dir-profile-hero-unit">
      <i class="bi bi-building me-1" aria-hidden="true"></i><?= h($person['unit_name']) ?>
    </div>
    <?php endif; ?>
    <div class="dir-profile-hero-actions">
      <a href="<?= APP_URL ?>/directory/"
         class="btn"
         aria-label="Wróć do katalogu">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Katalog
      </a>
      <a href="mailto:<?= h($person['email']) ?>"
         class="btn"
         aria-label="Wyślij e-mail do <?= h($display) ?>: <?= h($person['email']) ?>">
        <i class="bi bi-envelope me-1" aria-hidden="true"></i><?= h($person['email']) ?>
      </a>
      <?php if ($person['phone_public'] && $person['phone_display']): ?>
      <a href="tel:<?= h($person['phone_display']) ?>"
         class="btn"
         aria-label="Zadzwoń: <?= h($person['phone_display']) ?>">
        <i class="bi bi-telephone me-1" aria-hidden="true"></i><?= h($person['phone_display']) ?>
      </a>
      <?php endif; ?>
      <?php if ($can_edit): ?>
      <a href="<?= APP_URL ?>/directory/profile_edit.php<?= !$is_own ? '?id=' . $profile_id : '' ?>"
         class="btn btn-edit"
         aria-label="Edytuj profil <?= h($display) ?>">
        <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj profil
      </a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── Treść profilu ─────────────────────────────────────────────────── -->
<div style="max-width:780px">

  <?php if (!empty($person['bio'])): ?>
  <section class="dir-info-card" aria-labelledby="section-bio">
    <h2 id="section-bio" class="h6">
      <i class="bi bi-person-vcard me-2" aria-hidden="true" style="color:var(--dir-primary)"></i>O mnie
    </h2>
    <p class="mb-0" style="white-space:pre-wrap;font-size:.9rem;line-height:1.65;color:var(--dir-text)"><?= h($person['bio']) ?></p>
  </section>
  <?php endif; ?>

  <?php if (!empty($person['skills_array'])): ?>
  <section class="dir-info-card" aria-labelledby="section-skills">
    <h2 id="section-skills" class="h6">
      <i class="bi bi-stars me-2" aria-hidden="true" style="color:#F59E0B"></i>Umiejętności
    </h2>
    <ul class="d-flex flex-wrap gap-2 list-unstyled mb-0" aria-label="Umiejętności">
      <?php foreach ($person['skills_array'] as $skill): ?>
      <li class="dir-skill-tag"><?= h($skill) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>
  <?php endif; ?>

  <!-- Dane kontaktowe -->
  <section class="dir-info-card" aria-labelledby="section-contact">
    <h2 id="section-contact" class="h6">
      <i class="bi bi-info-circle me-2" aria-hidden="true" style="color:var(--dir-accent)"></i>Dane kontaktowe
    </h2>
    <dl style="font-size:.88rem" class="mb-0">
      <div class="row mb-1">
        <dt class="col-sm-4 fw-normal text-muted">E-mail</dt>
        <dd class="col-sm-8">
          <a href="mailto:<?= h($person['email']) ?>"
             style="color:var(--dir-primary)"><?= h($person['email']) ?></a>
        </dd>
      </div>
      <?php if ($person['phone_public'] && $person['phone_display']): ?>
      <div class="row mb-1">
        <dt class="col-sm-4 fw-normal text-muted">Telefon</dt>
        <dd class="col-sm-8">
          <a href="tel:<?= h($person['phone_display']) ?>" class="text-success"><?= h($person['phone_display']) ?></a>
        </dd>
      </div>
      <?php endif; ?>
      <?php if ($person['unit_name']): ?>
      <div class="row mb-1">
        <dt class="col-sm-4 fw-normal text-muted">Jednostka</dt>
        <dd class="col-sm-8"><?= h($person['unit_name']) ?></dd>
      </div>
      <?php endif; ?>
      <?php if ($person['position_name']): ?>
      <div class="row mb-1">
        <dt class="col-sm-4 fw-normal text-muted">Stanowisko</dt>
        <dd class="col-sm-8"><?= h($person['position_name']) ?></dd>
      </div>
      <?php endif; ?>
    </dl>
  </section>

  <!-- Pola własne admina -->
  <?php foreach ($field_defs as $fd):
      $val = $person['field_values'][(int)$fd['id']] ?? '';
      if ($val === '') continue;
      $section_id = 'section-field-' . (int)$fd['id'];
  ?>
  <section class="dir-info-card" aria-labelledby="<?= h($section_id) ?>">
    <h2 id="<?= h($section_id) ?>" class="h6">
      <i class="bi bi-card-text me-2 text-secondary" aria-hidden="true"></i><?= h($fd['label']) ?>
    </h2>
    <?php if ($fd['field_type'] === 'url'): ?>
      <a href="<?= h($val) ?>" target="_blank" rel="noopener" style="color:var(--dir-primary)"><?= h($val) ?></a>
    <?php elseif ($fd['field_type'] === 'textarea'): ?>
      <p class="mb-0" style="white-space:pre-wrap;font-size:.88rem"><?= h($val) ?></p>
    <?php else: ?>
      <p class="mb-0" style="font-size:.88rem"><?= h($val) ?></p>
    <?php endif; ?>
  </section>
  <?php endforeach; ?>

  <?php if (empty($person['bio']) && empty($person['skills_array']) && !array_filter($person['field_values'])): ?>
  <div class="dir-info-card text-center py-4 text-muted" role="status">
    <i class="bi bi-person-dash d-block mb-2" aria-hidden="true" style="font-size:2rem;opacity:.3"></i>
    <p class="mb-1">Profil nie został jeszcze uzupełniony.</p>
    <?php if ($can_edit): ?>
    <a href="<?= APP_URL ?>/directory/profile_edit.php<?= !$is_own ? '?id=' . $profile_id : '' ?>"
       class="btn btn-sm mt-1"
       style="background:var(--dir-primary);color:#fff">
      <i class="bi bi-pencil me-1" aria-hidden="true"></i>Uzupełnij profil
    </a>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/includes/footer_dir.php'; ?>

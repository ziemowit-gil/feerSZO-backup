<?php
/**
 * Publiczny formularz zgłoszeniowy — bez logowania, bez menu.
 */
declare(strict_types=1);
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/rekrutacja.php';
require_once dirname(dirname(__DIR__)) . '/includes/rekrutacja_offers.php'; // klasa VolunteerModuleManager

$rm     = new VolunteerModuleManager();
$id     = (int)($_GET['id'] ?? 0);
$offer  = $id ? $rm->getOffer($id) : null;
$is_open = $offer && $offer['status'] === 'active';

$errors    = [];
$submitted = false;
$new_app_id= null;

$vals = [
    'candidate_name'  => '',
    'candidate_email' => '',
    'candidate_phone' => '',
    'avail_from'      => '',
    'avail_to'        => '',
    'simplified'      => 0,
    'rodo_accepted'   => 0,
    'form_data'       => [],
];

// Pre-fill from session if logged in
$_cu = current_user();
if ($_cu && !$_POST) {
    $vals['candidate_name']  = $_cu['name']  ?? '';
    $vals['candidate_email'] = $_cu['email'] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_open) {
    csrf_check();

    $vals['candidate_name']  = trim($_POST['candidate_name']  ?? '');
    $vals['candidate_email'] = trim($_POST['candidate_email'] ?? '');
    $vals['candidate_phone'] = trim($_POST['candidate_phone'] ?? '');
    $vals['avail_from']      = trim($_POST['avail_from']      ?? '');
    $vals['avail_to']        = trim($_POST['avail_to']        ?? '');
    $vals['simplified']      = isset($_POST['simplified']) ? 1 : 0;
    $vals['rodo_accepted']   = isset($_POST['rodo_accepted']) ? 1 : 0;

    // Custom fields — optional unless cf['required'] == 1
    $form_data = [];
    foreach (($offer['custom_fields'] ?? []) as $cf) {
        $key   = 'cf_' . md5($cf['label']);
        $value = trim($_POST[$key] ?? '');
        if (!empty($cf['required']) && $value === '') {
            $errors[] = 'Pole „' . h($cf['label']) . '" jest wymagane.';
        }
        $form_data[$cf['label']] = $value;
    }
    $vals['form_data'] = $form_data;

    // Validation
    if (!$vals['candidate_name'])  $errors[] = 'Imię i nazwisko jest wymagane.';
    if (!$vals['candidate_email']) $errors[] = 'Adres e-mail jest wymagany.';
    elseif (!filter_var($vals['candidate_email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'Podaj prawidłowy adres e-mail.';
    if (!$vals['rodo_accepted'])   $errors[] = 'Akceptacja klauzuli informacyjnej jest wymagana.';

    if (!$errors) {
        try {
            $result    = $rm->createApplication($id, [
                'candidate_name'                  => $vals['candidate_name'],
                'candidate_email'                 => $vals['candidate_email'],
                'candidate_phone'                 => $vals['candidate_phone'],
                'avail_from'                      => $vals['avail_from'] ?: null,
                'avail_to'                        => $vals['avail_to']   ?: null,
                'rodo_accepted'                   => 1,
                'form_data'                       => $vals['form_data'],
                'is_simplified_communication_required' => $vals['simplified'],
            ]);
            $submitted  = true;
            $new_app_id = $result['id'];
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// RODO text: from offer, or fallback
$rodo_text = trim($offer['rodo_text'] ?? '');
if ($rodo_text === '') {
    $rodo_text = 'Administratorem Twoich danych osobowych jest '
        . ORG_NAME
        . '. Dane przetwarzane są wyłącznie w celu przeprowadzenia procesu rekrutacji wolontariuszy'
        . ' na podstawie Twojej zgody (art. 6 ust. 1 lit. a RODO). Masz prawo dostępu do danych,'
        . ' ich sprostowania, usunięcia, ograniczenia przetwarzania i wniesienia sprzeciwu.'
        . ' Dane nie będą przekazywane poza EOG. Kontakt: możliwy przez organizację.';
}

$page_title = $offer ? h($offer['title']) . ' — ' . h(ORG_NAME) : h(ORG_NAME) . ' — Rekrutacja';
$org_logo   = org_setting('org_logo');
$logo_url   = $org_logo ? APP_URL . '/assets/logo/' . h($org_logo) : null;
?>
<!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $page_title ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
  body { background: #f1f5f9; min-height: 100vh; }
  .pub-topbar {
    background: #1e293b; color: #e2e8f0; padding: .65rem 1.25rem;
    display: flex; align-items: center; gap: .75rem;
    font-size: .9rem; position: sticky; top: 0; z-index: 100;
  }
  .pub-topbar a { color: #94a3b8; text-decoration: none; }
  .pub-topbar a:hover { color: #e2e8f0; }
  .pub-topbar .brand { color: #fff; font-weight: 700; }
  .pub-wrap { max-width: 680px; margin: 0 auto; padding: 1.5rem 1rem 3rem; }
  .offer-hero {
    background: linear-gradient(135deg, #0f2044, #1d4ed8);
    color: #fff; border-radius: 1rem; padding: 1.5rem 1.75rem; margin-bottom: 1.5rem;
  }
  .offer-hero .tag { font-size: .68rem; opacity: .6; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; }
  .offer-hero .title { font-size: 1.25rem; font-weight: 700; margin: .25rem 0 .2rem; }
  .offer-hero .meta { opacity: .7; font-size: .82rem; }
  .section-title { font-size: .72rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: #64748b; margin-bottom: .75rem; }
  .rodo-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .5rem; padding: .9rem 1rem; font-size: .82rem; color: #475569; line-height: 1.5; }
  .required-star { color: #dc3545; }
  .field-hint { font-size: .78rem; color: #94a3b8; }
</style>
</head>
<body>

<!-- Topbar -->
<div class="pub-topbar">
  <?php if ($logo_url): ?>
  <img src="<?= $logo_url ?>" alt="" style="height:24px;width:auto;object-fit:contain">
  <?php endif; ?>
  <span class="brand"><?= h(ORG_NAME) ?></span>
  <span class="ms-auto">
    <a href="public.php"><i class="bi bi-arrow-left me-1"></i>Wszystkie ogłoszenia</a>
  </span>
</div>

<div class="pub-wrap">

<?php if (!$offer): ?>
<!-- ── Brak ogłoszenia ────────────────────────────────────────────── -->
<div class="alert alert-warning mt-4">
  <i class="bi bi-exclamation-triangle-fill me-2"></i>
  Ogłoszenie nie zostało znalezione.
  <a href="public.php" class="alert-link ms-2">Zobacz wszystkie ogłoszenia →</a>
</div>

<?php elseif ($submitted): ?>
<!-- ── Potwierdzenie ──────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mt-3">
  <div class="card-body text-center py-5 px-4">
    <div class="mb-3"><i class="bi bi-check-circle-fill text-success" style="font-size:3rem"></i></div>
    <h4 class="fw-bold mb-2">Zgłoszenie przyjęte!</h4>
    <p class="text-muted mb-1">
      Dziękujemy, <strong><?= h($vals['candidate_name']) ?></strong>!<br>
      Twoje zgłoszenie na stanowisko <strong><?= h($offer['title']) ?></strong> zostało zarejestrowane.
    </p>
    <p class="text-muted small mb-4">
      Skontaktujemy się z Tobą na adres <strong><?= h($vals['candidate_email']) ?></strong>.
    </p>
    <div class="text-muted small">Nr zgłoszenia: <span class="font-monospace fw-semibold">#<?= $new_app_id ?></span></div>
  </div>
</div>

<?php else: ?>

<!-- ── Hero ogłoszenia ──────────────────────────────────────────────── -->
<div class="offer-hero">
  <div class="tag"><i class="bi bi-megaphone-fill me-1"></i>Wolontariat</div>
  <div class="title"><?= h($offer['title']) ?></div>
  <div class="meta">
    <?= h(ORG_NAME) ?>
    <?php if ($offer['avail_from'] || $offer['avail_to']): ?>
    · <i class="bi bi-calendar3 me-1"></i>
    <?= $offer['avail_from'] ? date_pl($offer['avail_from']) : '?' ?>
    <?php if ($offer['avail_to']): ?> — <?= date_pl($offer['avail_to']) ?><?php endif; ?>
    <?php endif; ?>
    <?php if ($offer['max_candidates']): ?>
    · <i class="bi bi-people me-1"></i>limit: <?= (int)$offer['max_candidates'] ?> os.
    <?php endif; ?>
  </div>
</div>

<?php if (!$is_open): ?>
<!-- Zamknięte -->
<div class="alert alert-secondary d-flex align-items-center gap-2">
  <i class="bi bi-archive-fill fs-5"></i>
  <div><strong>Rekrutacja zakończona.</strong> To ogłoszenie nie przyjmuje już zgłoszeń.</div>
</div>
<?php if ($offer['content']): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body"><div style="white-space:pre-wrap;line-height:1.7"><?= h($offer['content']) ?></div></div>
</div>
<?php endif; ?>

<?php else: /* is_open */ ?>

<!-- Opis stanowiska -->
<?php if ($offer['content']): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body">
    <div class="section-title"><i class="bi bi-file-text me-1"></i>O stanowisku</div>
    <div style="white-space:pre-wrap;line-height:1.7;color:#374151"><?= h($offer['content']) ?></div>
  </div>
</div>
<?php endif; ?>

<!-- Błędy -->
<?php if ($errors): ?>
<div class="alert alert-danger mb-4">
  <strong><i class="bi bi-exclamation-triangle-fill me-1"></i>Popraw błędy:</strong>
  <ul class="mb-0 mt-1 ps-3">
    <?php foreach ($errors as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>

<!-- Formularz -->
<div class="card border-0 shadow-sm mb-4">
<div class="card-body px-4 py-4">
<form method="post" novalidate>
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

  <div class="section-title"><i class="bi bi-person me-1"></i>Twoje dane</div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Imię i nazwisko <span class="required-star">*</span></label>
    <input type="text" name="candidate_name" class="form-control"
           value="<?= h($vals['candidate_name']) ?>"
           placeholder="Jan Kowalski" autocomplete="name">
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Adres e-mail <span class="required-star">*</span></label>
    <input type="email" name="candidate_email" class="form-control"
           value="<?= h($vals['candidate_email']) ?>"
           placeholder="jan@example.com" autocomplete="email">
  </div>

  <div class="mb-3">
    <label class="form-label fw-semibold">Numer telefonu</label>
    <input type="tel" name="candidate_phone" class="form-control"
           value="<?= h($vals['candidate_phone']) ?>"
           placeholder="500 123 456" autocomplete="tel">
  </div>

  <hr class="my-4">
  <div class="section-title"><i class="bi bi-calendar-range me-1"></i>Dostępność</div>

  <div class="row g-3 mb-3">
    <div class="col-6">
      <label class="form-label fw-semibold">Dostępny/a od</label>
      <input type="date" name="avail_from" class="form-control"
             value="<?= h($vals['avail_from']) ?>">
    </div>
    <div class="col-6">
      <label class="form-label fw-semibold">Dostępny/a do</label>
      <input type="date" name="avail_to" class="form-control"
             value="<?= h($vals['avail_to']) ?>">
    </div>
  </div>

  <?php if ($offer['custom_fields']): ?>
  <hr class="my-4">
  <div class="section-title"><i class="bi bi-ui-checks me-1"></i>Dodatkowe pytania</div>
  <?php foreach ($offer['custom_fields'] as $cf):
    $cf_key  = 'cf_' . md5($cf['label']);
    $cf_val  = $vals['form_data'][$cf['label']] ?? '';
    $req     = !empty($cf['required']);
  ?>
  <div class="mb-3">
    <label class="form-label fw-semibold">
      <?= h($cf['label']) ?><?php if ($req): ?> <span class="required-star">*</span><?php endif; ?>
    </label>
    <?php if ($cf['type'] === 'textarea'): ?>
    <textarea name="<?= h($cf_key) ?>" class="form-control" rows="4"><?= h($cf_val) ?></textarea>
    <?php elseif ($cf['type'] === 'date'): ?>
    <input type="date" name="<?= h($cf_key) ?>" class="form-control" value="<?= h($cf_val) ?>">
    <?php elseif ($cf['type'] === 'checkbox'): ?>
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="<?= h($cf_key) ?>"
             id="<?= h($cf_key) ?>" value="1" <?= $cf_val ? 'checked' : '' ?>>
      <label class="form-check-label" for="<?= h($cf_key) ?>"><?= h($cf['label']) ?></label>
    </div>
    <?php else: ?>
    <input type="text" name="<?= h($cf_key) ?>" class="form-control" value="<?= h($cf_val) ?>">
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <hr class="my-4">
  <div class="section-title"><i class="bi bi-info-circle me-1"></i>Dostępność i zgody</div>

  <div class="mb-3">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="simplified"
             id="simplified" value="1" <?= $vals['simplified'] ? 'checked' : '' ?>>
      <label class="form-check-label small" for="simplified">
        Potrzebuję uproszczonej formy komunikacji
        <span class="field-hint">(np. duże litery, prosty język, e-mail zamiast telefonu)</span>
      </label>
    </div>
  </div>

  <div class="rodo-box mb-3"><?= nl2br(h($rodo_text)) ?></div>

  <div class="mb-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" name="rodo_accepted"
             id="rodo_accepted" value="1"
             <?= $vals['rodo_accepted'] ? 'checked' : '' ?> required>
      <label class="form-check-label fw-semibold" for="rodo_accepted">
        Zapoznałem/am się z powyższą klauzulą informacyjną i wyrażam zgodę na przetwarzanie danych.
        <span class="required-star">*</span>
      </label>
    </div>
  </div>

  <div class="d-grid">
    <button type="submit" class="btn btn-primary btn-lg">
      <i class="bi bi-send-fill me-2"></i>Wyślij zgłoszenie
    </button>
  </div>
</form>
</div>
</div>

<?php endif; /* is_open */ ?>
<?php endif; /* offer / submitted */ ?>

</div><!-- /pub-wrap -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

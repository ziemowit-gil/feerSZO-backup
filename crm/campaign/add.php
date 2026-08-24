<?php
/**
 * crm/campaign/add.php — Nowa kampania mailowa: szablon + segment + harmonogram/teraz.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_campaign.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_consent.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_require('campaigns', 'write');

$can_write = can_write('crm') || is_admin();
if (!$can_write) { flash_set('error', 'Brak uprawnień.'); header('Location: ' . APP_URL . '/crm/campaign/index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $name         = trim($_POST['name'] ?? '');
    $template_id  = (int)($_POST['template_id'] ?? 0);
    $subject      = trim($_POST['subject'] ?? '');
    $segment_type = in_array($_POST['segment_type'] ?? '', ['tags', 'groups', 'all'], true) ? $_POST['segment_type'] : 'tags';
    $tags         = array_values(array_filter((array)($_POST['tags'] ?? [])));
    $group_ids    = array_values(array_filter(array_map('intval', (array)($_POST['group_ids'] ?? []))));
    $purpose_id   = (int)($_POST['purpose_id'] ?? 0);
    $when         = $_POST['when'] ?? 'now';
    $scheduled_at = trim($_POST['scheduled_at'] ?? '');

    if ($name === '') $errors[] = 'Nazwa kampanii jest wymagana.';
    if (!$template_id) $errors[] = 'Wybierz szablon.';
    if ($segment_type === 'tags' && !$tags) $errors[] = 'Wybierz co najmniej jeden tag.';
    if ($segment_type === 'groups' && !$group_ids) $errors[] = 'Wybierz co najmniej jedną grupę.';
    if ($when === 'schedule' && !$scheduled_at) $errors[] = 'Podaj datę i godzinę wysyłki.';

    $segment_config = match ($segment_type) {
        'tags'   => ['tags' => $tags],
        'groups' => ['group_ids' => $group_ids],
        default  => [],
    };

    if (!$errors) {
        $status = $when === 'schedule' ? 'scheduled' : 'draft';
        $campaign_id = db_insert('crm_campaigns', [
            'name'           => $name,
            'template_id'    => $template_id,
            'subject'        => $subject,
            'segment_type'   => $segment_type,
            'segment_config' => json_encode($segment_config, JSON_UNESCAPED_UNICODE),
            'purpose_id'     => $purpose_id ?: null,
            'status'         => $status,
            'scheduled_at'   => $when === 'schedule' ? str_replace('T', ' ', $scheduled_at) . ':00' : null,
            'created_by'     => (int)(current_user()['id'] ?? 0),
        ]);

        if ($when === 'now') {
            crm_campaign_queue_send($campaign_id);
        }

        flash_set('success', $when === 'now' ? 'Kampania wysyłana.' : 'Kampania zaplanowana.');
        header('Location: ' . APP_URL . '/crm/campaign/view.php?id=' . $campaign_id);
        exit;
    }
}

$templates = db_all("SELECT id, name, channel, subject FROM crm_templates WHERE channel='email' AND is_active=1 ORDER BY name");
$purposes  = array_filter(crm_consent_purposes(true), fn($p) => in_array($p['channel'], ['email', 'any'], true));
$p_counts  = crm_consent_counts();
$all_tags  = array_column(db_all("SELECT DISTINCT tag FROM crm_tags ORDER BY tag"), 'tag');
$groups    = db_all("SELECT id, name, color FROM crm_groups ORDER BY name");

$PAGE_TITLE = 'CRM — Nowa kampania';
include dirname(__DIR__) . '/includes/header_crm.php';
?>

<nav aria-label="breadcrumb" class="mb-3" style="font-size:.82rem">
  <ol class="breadcrumb mb-0">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/crm/dashboard.php">CRM</a></li>
    <li class="breadcrumb-item"><a href="index.php">Kampanie</a></li>
    <li class="breadcrumb-item active">Nowa kampania</li>
  </ol>
</nav>

<div class="crm-page-header mb-4">
  <div>
    <div class="crm-page-title"><i class="bi bi-megaphone-fill" style="color:var(--crm-primary)"></i> Nowa kampania mailowa</div>
    <div class="crm-page-subtitle">Wybierz szablon, odbiorców i moment wysyłki</div>
  </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex gap-2 mb-3">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <ul class="mb-0 ps-2"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<?php if (!$templates): ?>
<div class="alert alert-warning">
  Brak aktywnych szablonów e-mail. <a href="<?= APP_URL ?>/crm/templates.php">Utwórz szablon</a>
  (np. w <a href="<?= APP_URL ?>/crm/mosaico/index.php">edytorze Mosaico</a>) zanim utworzysz kampanię.
</div>
<?php endif; ?>

<form method="post" novalidate>
<input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

<div class="row g-3">
<div class="col-lg-8">

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="mb-3">
        <label class="form-label fw-semibold">Nazwa kampanii <span class="text-danger">*</span></label>
        <input name="name" class="form-control" value="<?= h($_POST['name'] ?? '') ?>" placeholder="np. Newsletter — lipiec 2026" required autofocus>
      </div>
      <div class="mb-3">
        <label class="form-label fw-semibold">Szablon <span class="text-danger">*</span></label>
        <select name="template_id" class="form-select" required onchange="campSubjectFromTpl(this)">
          <option value="">— wybierz szablon —</option>
          <?php foreach ($templates as $t): ?>
          <option value="<?= (int)$t['id'] ?>" data-subject="<?= h($t['subject'] ?? '') ?>"
                  <?= (($_POST['template_id'] ?? 0) == $t['id']) ? 'selected' : '' ?>><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label fw-semibold">Temat wiadomości</label>
        <input name="subject" id="camp_subject" class="form-control" value="<?= h($_POST['subject'] ?? '') ?>"
               placeholder="Zostaw puste, aby użyć tematu z szablonu">
        <div class="form-text">Obsługuje te same zmienne co szablony, np. <code>{imie}</code>.</div>
      </div>
    </div>
  </div>

  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="mb-3 fw-semibold">Cel wysyłki i zgoda</div>
      <label class="form-label small mb-1" for="camp_purpose">Cel, na który odbiorca wyraził zgodę</label>
      <select name="purpose_id" id="camp_purpose" class="form-select" onchange="campPurposeHint()">
        <option value="0" data-count="-1" <?= empty($_POST['purpose_id']) ? 'selected' : '' ?>>
          — bez celu: wyślij do wszystkich z segmentu —
        </option>
        <?php foreach ($purposes as $p): ?>
        <option value="<?= (int)$p['id'] ?>" data-count="<?= (int)($p_counts[(int)$p['id']] ?? 0) ?>"
                <?= (($_POST['purpose_id'] ?? 0) == $p['id']) ? 'selected' : '' ?>>
          <?= h($p['nazwa']) ?> — <?= (int)($p_counts[(int)$p['id']] ?? 0) ?> kontakt(ów) ze zgodą
        </option>
        <?php endforeach; ?>
      </select>
      <div id="camp_purpose_hint" class="form-text mt-2"></div>

      <hr class="my-3">

      <div class="mb-3 fw-semibold">Odbiorcy</div>
      <div class="d-flex gap-3 mb-3">
        <?php foreach (['tags' => 'Wg tagów', 'groups' => 'Wg grup', 'all' => 'Wszyscy aktywni z e-mailem'] as $sv => $sl): ?>
        <div class="form-check">
          <input class="form-check-input" type="radio" name="segment_type" value="<?= $sv ?>" id="seg_<?= $sv ?>"
                 onchange="campToggleSegment()" <?= (($_POST['segment_type'] ?? 'tags') === $sv) ? 'checked' : '' ?>>
          <label class="form-check-label" for="seg_<?= $sv ?>"><?= $sl ?></label>
        </div>
        <?php endforeach; ?>
      </div>

      <div id="seg_tags_box" class="d-flex flex-wrap gap-2">
        <?php foreach ($all_tags as $tag): ?>
        <div class="form-check form-check-inline border rounded px-2 py-1">
          <input class="form-check-input" type="checkbox" name="tags[]" value="<?= h($tag) ?>" id="tag_<?= h(md5($tag)) ?>"
                 <?= in_array($tag, (array)($_POST['tags'] ?? []), true) ? 'checked' : '' ?>>
          <label class="form-check-label" for="tag_<?= h(md5($tag)) ?>"><?= h($tag) ?></label>
        </div>
        <?php endforeach; ?>
        <?php if (!$all_tags): ?><span class="text-muted small">Brak tagów w CRM.</span><?php endif; ?>
      </div>

      <div id="seg_groups_box" class="d-flex flex-wrap gap-2" style="display:none">
        <?php foreach ($groups as $g): ?>
        <div class="form-check form-check-inline border rounded px-2 py-1">
          <input class="form-check-input" type="checkbox" name="group_ids[]" value="<?= (int)$g['id'] ?>" id="grp_<?= (int)$g['id'] ?>"
                 <?= in_array($g['id'], (array)($_POST['group_ids'] ?? [])) ? 'checked' : '' ?>>
          <label class="form-check-label" for="grp_<?= (int)$g['id'] ?>"><?= h($g['name']) ?></label>
        </div>
        <?php endforeach; ?>
        <?php if (!$groups): ?><span class="text-muted small">Brak grup w CRM.</span><?php endif; ?>
      </div>
    </div>
  </div>

</div>

<div class="col-lg-4">
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <div class="mb-3 fw-semibold">Wysyłka</div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="radio" name="when" value="now" id="when_now" onchange="campToggleWhen()"
               <?= (($_POST['when'] ?? 'now') === 'now') ? 'checked' : '' ?>>
        <label class="form-check-label" for="when_now">Wyślij teraz</label>
      </div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="radio" name="when" value="schedule" id="when_sched" onchange="campToggleWhen()"
               <?= (($_POST['when'] ?? '') === 'schedule') ? 'checked' : '' ?>>
        <label class="form-check-label" for="when_sched">Zaplanuj</label>
      </div>
      <input type="datetime-local" name="scheduled_at" id="camp_sched_at" class="form-control mt-2"
             value="<?= h($_POST['scheduled_at'] ?? '') ?>" style="display:none">
      <div class="form-text mt-2">Kolejka wysyła ok. 20 wiadomości/min — przy większej liczbie odbiorców dostarczenie potrwa dłużej.</div>
    </div>
  </div>
  <div class="d-grid gap-2">
    <button type="submit" class="btn btn-crm-primary"><i class="bi bi-check-lg me-1"></i>Utwórz kampanię</button>
    <a href="index.php" class="btn btn-outline-secondary">Anuluj</a>
  </div>
</div>
</div>
</form>

<script>
function campToggleSegment() {
  var v = document.querySelector('input[name=segment_type]:checked')?.value || 'tags';
  document.getElementById('seg_tags_box').style.display   = (v === 'tags')   ? '' : 'none';
  document.getElementById('seg_groups_box').style.display = (v === 'groups') ? '' : 'none';
}
function campToggleWhen() {
  var v = document.querySelector('input[name=when]:checked')?.value || 'now';
  document.getElementById('camp_sched_at').style.display = (v === 'schedule') ? '' : 'none';
}
// Wysyłka bez celu trafia do całego segmentu (poza globalnie wypisanymi) — to
// bywa właściwe dla komunikatów operacyjnych, ale przy treści marketingowej
// trzeba wskazać cel, bo inaczej nie ma czym wykazać podstawy wysyłki.
function campPurposeHint() {
  var sel  = document.getElementById('camp_purpose');
  var box  = document.getElementById('camp_purpose_hint');
  var opt  = sel.options[sel.selectedIndex];
  var cnt  = parseInt(opt.dataset.count, 10);
  if (cnt < 0) {
    box.innerHTML = '<span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>'
      + 'Bez celu wysyłka pójdzie do całego segmentu — pominięte będą tylko kontakty '
      + 'wypisane globalnie. Przy treści marketingowej wskaż cel.</span>';
  } else if (cnt === 0) {
    box.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>'
      + 'Żaden kontakt nie ma jeszcze zgody na ten cel — kampania nie znajdzie odbiorców. '
      + 'Zgody zapisuje się w kartotece kontaktu albo zbiera formularzem.</span>';
  } else {
    box.innerHTML = '<i class="bi bi-shield-check me-1"></i>Wyślemy tylko do kontaktów z aktualną zgodą '
      + 'na ten cel (' + cnt + ') — po przekrojeniu z wybranym segmentem może ich być mniej.';
  }
}

function campSubjectFromTpl(sel) {
  var subjInput = document.getElementById('camp_subject');
  if (!subjInput.value) {
    var opt = sel.options[sel.selectedIndex];
    subjInput.placeholder = opt.dataset.subject || subjInput.placeholder;
  }
}
campToggleSegment();
campToggleWhen();
campPurposeHint();
</script>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>

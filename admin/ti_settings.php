<?php
/**
 * admin/ti_settings.php — Ustawienia Panelu dydaktyka (TI).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ti_blackout.php';

require_role('admin');
$PAGE_TITLE = 'Panel dydaktyka — ustawienia';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['_op'] ?? 'save_settings';

    if ($op === 'blackout_save') {
        try {
            ti_blackout_save([
                'title'          => $_POST['bl_title']   ?? '',
                'message'        => $_POST['bl_message'] ?? '',
                'starts_at'      => $_POST['bl_from']     ?? '',
                'ends_at'        => $_POST['bl_to']       ?? '',
                'block_dydaktyk' => !empty($_POST['bl_dydaktyk']),
                'block_dziennik' => !empty($_POST['bl_dziennik']),
                'is_active'      => !empty($_POST['bl_active']),
            ], (int)($_POST['bl_id'] ?? 0) ?: null, (int)(current_user()['id'] ?? 0));
            flash_set('success', 'Okres wyłączenia zapisany.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: ti_settings.php#wylaczenia'); exit;
    }

    if ($op === 'blackout_delete') {
        ti_blackout_delete((int)($_POST['bl_id'] ?? 0));
        flash_set('success', 'Okres wyłączenia usunięty.');
        header('Location: ti_settings.php#wylaczenia'); exit;
    }

    $enabled = !empty($_POST['dyd_panel_enabled']) ? '1' : '0';
    $message = trim($_POST['dyd_panel_message'] ?? '');
    $resume  = trim($_POST['dyd_panel_resume'] ?? '');
    org_setting_set('dyd_panel_enabled', $enabled);
    org_setting_set('dyd_panel_message', $message);
    org_setting_set('dyd_panel_resume',  $resume);
    flash_set('success', 'Ustawienia panelu dydaktyka zapisane.');
    header('Location: ti_settings.php'); exit;
}

$enabled = org_setting('dyd_panel_enabled');
$enabled = ($enabled === '' || $enabled === '1'); // domyślnie włączony
$message = org_setting('dyd_panel_message');
$resume  = org_setting('dyd_panel_resume');

$blackouts = ti_blackout_list();
$bl_edit   = ti_blackout_get((int)($_GET['bl_edit'] ?? 0));
$bl        = $bl_edit ?: ['id'=>0,'title'=>'','message'=>'','starts_at'=>'','ends_at'=>'','block_dydaktyk'=>1,'block_dziennik'=>0,'is_active'=>1];

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/admin/index.php">Admin</a></li>
    <li class="breadcrumb-item active">Panel dydaktyka</li>
  </ol>
</nav>

<h1 class="h4 mb-4"><i class="bi bi-easel2 me-2"></i>Panel dydaktyka i dziennik — dostępność</h1>

<?php flash_render(); ?>

<form method="post" action="">
  <?= csrf_field() ?>
  <input type="hidden" name="_op" value="save_settings">

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-toggle-on text-primary"></i> Dostępność panelu
    </div>
    <div class="card-body">
      <div class="form-check form-switch mb-0">
        <input class="form-check-input" type="checkbox" role="switch"
               id="dyd_panel_enabled" name="dyd_panel_enabled"
               <?= $enabled ? 'checked' : '' ?>>
        <label class="form-check-label fw-semibold" for="dyd_panel_enabled">
          Panel dydaktyka jest włączony
        </label>
        <div class="form-text mt-1">
          Po wyłączeniu dydaktycy (prowadzący) widzą stronę przerwy z poniższym komunikatem.
          Administratorzy i pracownicy D3 nadal mają pełny dostęp.
        </div>
      </div>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
      <i class="bi bi-chat-square-text text-warning"></i> Komunikat dla dydaktyków
    </div>
    <div class="card-body">
      <div class="mb-3">
        <label for="dyd_panel_message" class="form-label">Treść komunikatu</label>
        <textarea class="form-control" id="dyd_panel_message" name="dyd_panel_message"
                  rows="3" maxlength="1000"
                  placeholder="np. Przerwa techniczna — panel dydaktyka jest tymczasowo niedostępny. Zapraszamy ponownie wkrótce."><?= h($message) ?></textarea>
        <div class="form-text">Pozostaw puste, aby wyświetlić domyślny komunikat.</div>
      </div>
      <div class="mb-0">
        <label for="dyd_panel_resume" class="form-label">Planowany czas wznowienia <span class="text-body-secondary">(opcjonalnie)</span></label>
        <input type="datetime-local" class="form-control" id="dyd_panel_resume" name="dyd_panel_resume"
               value="<?= h($resume) ?>" style="max-width:260px">
        <div class="form-text">Jeśli podasz datę i godzinę, wyświetli się ona na stronie przerwy.</div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-floppy me-1"></i>Zapisz ustawienia
    </button>
    <a href="/karty30/ti/dydaktyk/index.php" target="_blank" class="btn btn-outline-secondary">
      <i class="bi bi-box-arrow-up-right me-1"></i>Podgląd panelu
    </a>
  </div>
</form>


<hr class="my-4">

<h2 class="h5 mb-2" id="wylaczenia"><i class="bi bi-calendar-x me-2"></i>Zaplanowane wyłączenia (okresowe)</h2>
<p class="text-body-secondary small">
  Okno działa samo: włącza się i wyłącza po podanych datach — nie trzeba nic przestawiać ręcznie.
  Wyłączenie dotyczy prowadzących, kursantów i opiekunów; administracja i pracownicy D3 pracują
  normalnie i widzą tylko baner. Komentarz (np. <em>„Trwają przygotowania do nowego roku dydaktycznego"</em>)
  jest tym, co zobaczą użytkownicy zamiast panelu albo dziennika.
</p>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-list-ul text-secondary"></i> Okresy
    <span class="badge bg-secondary ms-1"><?= count($blackouts) ?></span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Zaplanowane okresy wyłączenia panelu dydaktyka i dziennika ocen</caption>
      <thead class="table-light"><tr>
        <th scope="col">Okres</th>
        <th scope="col">Co wyłącza</th>
        <th scope="col">Komentarz</th>
        <th scope="col">Stan</th>
        <th scope="col" class="text-end">Akcje</th>
      </tr></thead>
      <tbody>
        <?php if (!$blackouts): ?>
        <tr><td colspan="5" class="text-center text-muted py-3">Brak zaplanowanych wyłączeń.</td></tr>
        <?php endif; ?>
        <?php foreach ($blackouts as $b):
          $now      = date('Y-m-d H:i:s');
          $running  = $b['is_active'] && $b['starts_at'] <= $now && $b['ends_at'] >= $now;
          $finished = $b['ends_at'] < $now;
        ?>
        <tr<?= $finished ? ' class="text-muted"' : '' ?>>
          <td class="text-nowrap">
            <?= h(date('j.m.Y, G:i', strtotime($b['starts_at']))) ?><br>
            <span class="small">→ <?= h(date('j.m.Y, G:i', strtotime($b['ends_at']))) ?></span>
          </td>
          <td class="small">
            <?php if ($b['block_dydaktyk']): ?><span class="badge text-bg-primary">Panel dydaktyka</span><?php endif; ?>
            <?php if ($b['block_dziennik']): ?><span class="badge text-bg-info">Dziennik ocen</span><?php endif; ?>
          </td>
          <td class="small">
            <?php if (trim((string)$b['title']) !== ''): ?><div class="fw-semibold"><?= h($b['title']) ?></div><?php endif; ?>
            <?= h($b['message'] !== '' ? $b['message'] : '(komunikat domyślny)') ?>
          </td>
          <td class="text-nowrap">
            <?php if (!$b['is_active']): ?><span class="badge text-bg-secondary">nieaktywne</span>
            <?php elseif ($running): ?><span class="badge text-bg-warning">trwa</span>
            <?php elseif ($finished): ?><span class="badge text-bg-light border text-dark">zakończone</span>
            <?php else: ?><span class="badge text-bg-success">zaplanowane</span><?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <a href="?bl_edit=<?= (int)$b['id'] ?>#bl-form" class="btn btn-sm btn-outline-primary py-0 px-2" aria-label="Edytuj okres <?= h(ti_blackout_range_text($b)) ?>"><i class="bi bi-pencil"></i></a>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten okres wyłączenia?')">
              <?= csrf_field() ?>
              <input type="hidden" name="_op" value="blackout_delete">
              <input type="hidden" name="bl_id" value="<?= (int)$b['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2" aria-label="Usuń okres <?= h(ti_blackout_range_text($b)) ?>"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card shadow-sm mb-4" id="bl-form">
  <div class="card-header fw-semibold d-flex align-items-center gap-2">
    <i class="bi bi-<?= $bl_edit ? 'pencil' : 'plus-lg' ?> text-primary"></i>
    <?= $bl_edit ? 'Edytuj okres wyłączenia' : 'Nowy okres wyłączenia' ?>
  </div>
  <div class="card-body">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="_op" value="blackout_save">
      <input type="hidden" name="bl_id" value="<?= (int)$bl['id'] ?>">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label" for="bl_from">Od <span class="text-danger" aria-hidden="true">*</span></label>
          <input type="datetime-local" class="form-control" id="bl_from" name="bl_from" required
                 value="<?= h($bl['starts_at'] !== '' ? date('Y-m-d\TH:i', strtotime($bl['starts_at'])) : '') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label" for="bl_to">Do <span class="text-danger" aria-hidden="true">*</span></label>
          <input type="datetime-local" class="form-control" id="bl_to" name="bl_to" required
                 value="<?= h($bl['ends_at'] !== '' ? date('Y-m-d\TH:i', strtotime($bl['ends_at'])) : '') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label" for="bl_title">Nazwa okresu <span class="text-body-secondary">(wewnętrzna, opcjonalnie)</span></label>
          <input type="text" class="form-control" id="bl_title" name="bl_title" maxlength="200"
                 value="<?= h($bl['title']) ?>" placeholder="np. Przygotowanie roku 2026/2027">
        </div>
        <div class="col-12">
          <fieldset>
            <legend class="form-label">Co wyłączyć <span class="text-danger" aria-hidden="true">*</span></legend>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="bl_dydaktyk" name="bl_dydaktyk" value="1" <?= !empty($bl['block_dydaktyk']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="bl_dydaktyk">Panel dydaktyka — prowadzący widzą stronę przerwy z komunikatem</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="bl_dziennik" name="bl_dziennik" value="1" <?= !empty($bl['block_dziennik']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="bl_dziennik">Dziennik ocen — brak wpisywania ocen i wglądu dla kursantów oraz opiekunów</label>
            </div>
          </fieldset>
        </div>
        <div class="col-12">
          <label class="form-label" for="bl_message">Komentarz dla użytkowników</label>
          <textarea class="form-control" id="bl_message" name="bl_message" rows="2" maxlength="1000"
                    placeholder="np. Trwają przygotowania do nowego roku dydaktycznego"><?= h($bl['message']) ?></textarea>
          <div class="form-text">Pozostaw puste, aby wyświetlić komunikat domyślny.</div>
        </div>
        <div class="col-12">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" id="bl_active" name="bl_active" value="1" <?= !empty($bl['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="bl_active">Okres aktywny (odznacz, aby przygotować go bez uruchamiania)</label>
          </div>
        </div>
      </div>
      <div class="d-flex gap-2 mt-3">
        <button class="btn btn-primary"><i class="bi bi-floppy me-1"></i><?= $bl_edit ? 'Zapisz okres' : 'Dodaj okres' ?></button>
        <?php if ($bl_edit): ?><a href="ti_settings.php#wylaczenia" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

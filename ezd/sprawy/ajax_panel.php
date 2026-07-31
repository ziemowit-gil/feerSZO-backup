<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login(); require_module_enabled('ezd_enabled', ''); ezd_require_access();

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');

$id      = (int)($_GET['id'] ?? 0);
$sprawa  = ezd_sprawa_get($id);
if (!$sprawa) { http_response_code(404); echo '<p class="text-danger p-3">Koszulka nie istnieje.</p>'; exit; }

$user_id = (int)current_user()['id'];
$access  = ezd_sprawa_access($sprawa, $user_id);
if (!$access) { http_response_code(403); echo '<p class="text-danger p-3">Brak dostępu.</p>'; exit; }

$is_closed = $sprawa['status'] === 'closed';
$can_act   = $access === 'write' && !$is_closed;
$timeline  = ezd_timeline($id);
$pisma     = array_values(array_filter($timeline, fn($t) => $t['_typ'] === 'pismo'));
$dekr      = ezd_dekretacje_by_sprawa($id);
$pend      = count(array_filter($dekr, fn($d) => $d['status'] === 'oczekuje'));
$csrf      = csrf_token();

$media_icons = ['papier'=>'bi-file-earmark-text','email'=>'bi-at','epuap'=>'bi-shield-lock','faks'=>'bi-printer','inne'=>'bi-question-circle'];

$uid = 'kp' . $id; // unikalne prefiksy id dla WCAG (wielokrotne otwieranie tego samego)
?>
<!-- Metadane koszulki -->
<div class="d-flex align-items-start gap-3 mb-3 flex-wrap">
  <div class="flex-grow-1">
    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
      <code class="fw-bold text-primary" style="font-size:.78rem"><?= h($sprawa['znak_sprawy']) ?></code>
      <?= ezd_status_badge_sprawa($sprawa['status']) ?>
      <?= ezd_priority_badge($sprawa['priority']) ?>
      <?= ezd_etap_badge($sprawa['etap'] ?? 'wszczeta') ?>
    </div>
    <h2 class="h6 fw-bold mb-1" id="<?= $uid ?>Label"><?= h($sprawa['title']) ?></h2>
    <div class="text-muted" style="font-size:.75rem">
      <?php if ($sprawa['owner_name']): ?><i class="bi bi-person me-1"></i><?= h($sprawa['owner_name']) ?><?php endif; ?>
      <?php if ($sprawa['deadline']): ?>
        · <i class="bi bi-calendar-event me-1"></i>
        <span class="<?= (!$is_closed && $sprawa['deadline'] < date('Y-m-d')) ? 'text-danger fw-bold' : '' ?>">
          <?= date_pl($sprawa['deadline']) ?>
        </span>
      <?php endif; ?>
    </div>
  </div>
  <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary flex-shrink-0">
    <i class="bi bi-box-arrow-up-right me-1"></i>Pełny widok
  </a>
</div>

<!-- Zakładki WCAG -->
<ul class="nav nav-tabs mb-0" role="tablist" id="<?= $uid ?>Tabs" style="border-bottom:2px solid #e2e8f0">
  <li class="nav-item" role="presentation">
    <button class="nav-link active fw-semibold" id="<?= $uid ?>-pisma-tab" role="tab"
            data-bs-toggle="tab" data-bs-target="#<?= $uid ?>-pisma"
            aria-controls="<?= $uid ?>-pisma" aria-selected="true"
            style="font-size:.72rem;letter-spacing:.05em">
      <i class="bi bi-envelope me-1" aria-hidden="true"></i>PISMA
      <span class="badge bg-secondary ms-1" aria-label="<?= count($pisma) ?> pism"><?= count($pisma) ?></span>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link fw-semibold" id="<?= $uid ?>-zadania-tab" role="tab"
            data-bs-toggle="tab" data-bs-target="#<?= $uid ?>-zadania"
            aria-controls="<?= $uid ?>-zadania" aria-selected="false"
            style="font-size:.72rem;letter-spacing:.05em">
      <i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i>ZADANIA
      <?php if ($pend): ?>
      <span class="badge bg-warning text-dark ms-1" aria-label="<?= $pend ?> oczekujących"><?= $pend ?></span>
      <?php endif; ?>
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link fw-semibold" id="<?= $uid ?>-meta-tab" role="tab"
            data-bs-toggle="tab" data-bs-target="#<?= $uid ?>-meta"
            aria-controls="<?= $uid ?>-meta" aria-selected="false"
            style="font-size:.72rem;letter-spacing:.05em">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>METADANE
    </button>
  </li>
</ul>

<div class="tab-content border border-top-0 rounded-bottom" style="min-height:240px">

  <!-- PISMA -->
  <div class="tab-pane fade show active p-3" id="<?= $uid ?>-pisma"
       role="tabpanel" aria-labelledby="<?= $uid ?>-pisma-tab">

    <?php if ($can_act): ?>
    <div class="mb-3">
      <div class="d-flex align-items-start gap-2 flex-wrap">
        <div class="d-flex flex-column gap-2">
          <?php
          $qk = ['przychodzace' => ['↓ Przychodzące','info'],
                 'wychodzace'   => ['↑ Wychodzące','primary']];
          $qm = ['papier'=>['bi-file-earmark-text','Papier'],
                 'email' =>['bi-at','E-mail'],
                 'epuap' =>['bi-shield-lock','ePUAP'],
                 'faks'  =>['bi-printer','Faks']];
          foreach($qk as $kv=>[$klabel,$kclass]): ?>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-<?= $kclass ?> text-nowrap fw-semibold" style="font-size:.72rem;min-width:100px;text-align:center"><?= $klabel ?></span>
            <div class="btn-group btn-group-sm" role="group" aria-label="Nowe pismo <?= $klabel ?>">
              <?php foreach($qm as $mv=>[$micon,$mlabel]): ?>
              <a href="<?= APP_URL ?>/ezd/pisma/add.php?sprawa_id=<?= $id ?>&kierunek=<?= $kv ?>&medium=<?= $mv ?>"
                 class="btn btn-outline-secondary" title="<?= $klabel ?> · <?= $mlabel ?>">
                <i class="bi <?= $micon ?>" aria-hidden="true"></i> <?= $mlabel ?>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($pisma): ?>
    <div class="tl-wrap" role="list" aria-label="Lista pism">
      <?php foreach ($pisma as $p):
        $kier = EZD_KIERUNKI[$p['kierunek']] ?? ['label'=>$p['kierunek'],'icon'=>'bi-envelope','class'=>'secondary'];
        $kshort = str_replace(['przychodzace','wychodzace','wewnetrzne'],['in','out','int'],$p['kierunek']);
      ?>
      <div class="tl-item" role="listitem">
        <div class="tl-dot pismo-<?= $kshort ?>" aria-hidden="true"></div>
        <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= (int)$p['id'] ?>&from_sprawa=<?= $id ?>"
           class="tl-card pismo-<?= $kshort ?> text-decoration-none"
           aria-label="Pismo: <?= h($p['title']) ?>, <?= h($kier['label']) ?>, <?= date('d.m.Y', strtotime($p['_date'])) ?>">
          <?php if (!empty($p['sygnatura'])): ?>
          <div class="tl-syg" aria-hidden="true"><?= h($p['sygnatura']) ?></div>
          <?php endif; ?>
          <div class="tl-title"><?= h(mb_substr($p['title'], 0, 70)) ?></div>
          <div class="tl-meta" aria-hidden="true">
            <span><?= h($kier['label']) ?></span>
            <?php if (!empty($p['nadawca'])): ?><span><?= h($p['nadawca']) ?></span><?php endif; ?>
            <?php if (!empty($p['status'])): ?><span class="badge bg-light text-dark border" style="font-size:.6rem"><?= h($p['status']) ?></span><?php endif; ?>
            <span class="ms-auto"><?= date('d.m.Y', strtotime($p['_date'])) ?></span>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-muted text-center py-3" style="font-size:.84rem">Brak pism w tej koszulce.</p>
    <?php endif; ?>
  </div>

  <!-- ZADANIA -->
  <div class="tab-pane fade p-3" id="<?= $uid ?>-zadania"
       role="tabpanel" aria-labelledby="<?= $uid ?>-zadania-tab">
    <?php if ($dekr): ?>
    <?php foreach ($dekr as $d): ?>
    <div class="d-flex align-items-start gap-2 py-2 border-bottom" style="font-size:.82rem">
      <span class="badge bg-<?= $d['status']==='oczekuje'?'warning text-dark':'success' ?> flex-shrink-0 mt-1" aria-label="Status: <?= h($d['status']) ?>">
        <?= h(EZD_DYSPOZYCJE[$d['dyspozycja']] ?? $d['dyspozycja']) ?>
      </span>
      <div class="flex-grow-1">
        <div class="fw-semibold"><?= h($d['wykonawca_name'] ?? '—') ?></div>
        <?php if ($d['tresc']): ?>
        <div class="text-muted" style="font-size:.74rem"><?= h(mb_substr($d['tresc'],0,80)) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($d['deadline']): ?>
      <div class="text-muted text-nowrap" style="font-size:.72rem">
        <time datetime="<?= h($d['deadline']) ?>"><?= date_pl($d['deadline']) ?></time>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php else: ?>
    <p class="text-muted text-center py-3" style="font-size:.84rem">Brak dekretacji.</p>
    <?php endif; ?>
  </div>

  <!-- METADANE -->
  <div class="tab-pane fade p-3" id="<?= $uid ?>-meta"
       role="tabpanel" aria-labelledby="<?= $uid ?>-meta-tab">
    <dl class="row mb-0" style="font-size:.83rem;row-gap:.3rem">
      <dt class="col-5 text-muted fw-normal">Znak koszulki</dt>
      <dd class="col-7 mb-0 font-monospace fw-bold"><?= h($sprawa['znak_sprawy']) ?></dd>
      <dt class="col-5 text-muted fw-normal">Segregator</dt>
      <dd class="col-7 mb-0">
        <a href="<?= APP_URL ?>/ezd/teczki/view.php?id=<?= $sprawa['teczka_id'] ?>" class="text-decoration-none">
          <?= h($sprawa['teczka_symbol']) ?>
        </a>
      </dd>
      <dt class="col-5 text-muted fw-normal">Status</dt><dd class="col-7 mb-0"><?= ezd_status_badge_sprawa($sprawa['status']) ?></dd>
      <dt class="col-5 text-muted fw-normal">Priorytet</dt><dd class="col-7 mb-0"><?= ezd_priority_badge($sprawa['priority']) ?></dd>
      <dt class="col-5 text-muted fw-normal">Etap</dt><dd class="col-7 mb-0"><?= ezd_etap_badge($sprawa['etap'] ?? 'wszczeta') ?></dd>
      <dt class="col-5 text-muted fw-normal">Właściciel</dt>
      <dd class="col-7 mb-0"><?= h($sprawa['owner_name'] ?? '—') ?></dd>
      <?php if ($sprawa['deadline']): ?>
      <dt class="col-5 text-muted fw-normal">Termin</dt>
      <dd class="col-7 mb-0 <?= (!$is_closed && $sprawa['deadline'] < date('Y-m-d')) ? 'text-danger fw-bold' : '' ?>">
        <time datetime="<?= h($sprawa['deadline']) ?>"><?= date_pl($sprawa['deadline']) ?></time>
      </dd>
      <?php endif; ?>
      <?php if ($sprawa['description']): ?>
      <dt class="col-5 text-muted fw-normal">Opis</dt>
      <dd class="col-7 mb-0" style="white-space:pre-line;font-size:.78rem"><?= h($sprawa['description']) ?></dd>
      <?php endif; ?>
    </dl>
  </div>

</div>

<?php
  // Oceny końcowe z zatwierdzonych protokołów — dopiero zatwierdzony protokół
  // jest deklaracją, więc oceny w toku nie są tu pokazywane.
  require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_protocols.php';
  $_final = ti_protocol_final_grades_for_client((int)$_fg_client_id);
?>
<?php if ($_final): ?>
<div class="kp-card p-3 mb-3">
  <h2 class="h6 fw-bold mb-2"><i class="bi bi-award me-2" aria-hidden="true"></i>Oceny końcowe</h2>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Oceny końcowe z zatwierdzonych protokołów</caption>
      <thead><tr>
        <th scope="col">Zajęcia</th>
        <th scope="col">Okres</th>
        <th scope="col" class="text-center" style="width:7rem">Ocena</th>
        <th scope="col">Uwagi</th>
      </tr></thead>
      <tbody>
        <?php foreach ($_final as $_f): ?>
        <tr>
          <td class="small fw-semibold"><?= h($_f['course_name']) ?></td>
          <td class="small"><?= h($_f['period'] !== '' ? $_f['period'] : '—') ?></td>
          <td class="text-center"><span class="badge text-bg-primary" style="font-size:.95rem"><?= h($_f['value']) ?></span></td>
          <td class="small text-body-secondary"><?= h($_f['note']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="small text-body-secondary mb-0 mt-2">
    Ocena końcowa pochodzi z protokołu zatwierdzonego przez prowadzącego i jest niezależna
    od średniej ocen bieżących poniżej.
  </p>
</div>
<?php endif; ?>

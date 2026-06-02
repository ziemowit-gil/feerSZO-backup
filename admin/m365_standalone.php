<?php
/**
 * Konta M365 bez umowy — tylko dla administratorów.
 * Pozwala tworzyć, zarządzać i usuwać konta Microsoft 365
 * nieprzypisane do żadnej umowy w systemie.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/approval.php';

require_role('admin');
$PAGE_TITLE = 'Konta M365 bez umowy';

$m365_enabled = m365_setting('m365_enabled') === '1';
$accounts     = db_all(
    "SELECT a.*, u.name AS linked_user_name, u.email AS linked_user_email,
            cb.name AS created_by_name
     FROM m365_standalone_accounts a
     LEFT JOIN users u  ON u.id = a.linked_user_id
     LEFT JOIN users cb ON cb.id = a.created_by
     ORDER BY a.created_at DESC"
);

// Hasło jednorazowe z sesji
auth_start();
$_creds = null;
if (!empty($_SESSION['m365sa_new_login'])) {
    $_creds = [
        'login' => $_SESSION['m365sa_new_login'],
        'pass'  => $_SESSION['m365sa_new_pass'],
        'sent'  => $_SESSION['m365sa_sent'] ?? false,
        'email' => $_SESSION['m365sa_email'] ?? '',
    ];
    unset($_SESSION['m365sa_new_login'], $_SESSION['m365sa_new_pass'],
          $_SESSION['m365sa_sent'],      $_SESSION['m365sa_email']);
}

$local_users = db_all("SELECT id, name, email, microsoft_id FROM users WHERE is_active=1 ORDER BY name");

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0">
    <i class="bi bi-microsoft text-primary"></i> Konta M365 bez umowy
  </h4>
  <?php if ($m365_enabled): ?>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createModal">
    <i class="bi bi-person-plus"></i> Nowe konto M365
  </button>
  <?php endif; ?>
</div>

<?= flash_html() ?>

<?php if (!$m365_enabled): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle"></i>
  Integracja Microsoft 365 jest wyłączona.
  <a href="<?= APP_URL ?>/admin/m365_settings.php" class="alert-link">Włącz w ustawieniach M365 →</a>
</div>
<?php endif; ?>

<?php if ($_creds): ?>
<div class="alert alert-warning border-warning">
  <strong><i class="bi bi-key-fill"></i> Hasło jednorazowe — zapisz teraz!</strong><br>
  Login: <code><?= h($_creds['login']) ?></code> &nbsp;/&nbsp;
  Hasło: <code><?= h($_creds['pass']) ?></code>
  <?php if ($_creds['sent']): ?>
  <br><small class="text-success"><i class="bi bi-check-circle"></i> Mail wysłany na: <?= h($_creds['email']) ?></small>
  <?php elseif ($_creds['email']): ?>
  <br><small class="text-warning"><i class="bi bi-exclamation-triangle"></i> Mail nie wysłany.</small>
  <?php else: ?>
  <br><small class="text-muted"><i class="bi bi-info-circle"></i> Nie podano adresu e-mail — mail nie został wysłany.</small>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- ── Lista kont ─────────────────────────────────────────────────────────── -->
<div class="card shadow-sm">
<div class="card-header fw-semibold">
  <i class="bi bi-people"></i> Konta (<?= count($accounts) ?>)
</div>
<?php if ($accounts): ?>
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0">
  <thead class="table-light">
    <tr>
      <th>Imię i nazwisko</th>
      <th>E-mail</th>
      <th>Login M365</th>
      <th>Status</th>
      <th>Licencja</th>
      <th>Powiązanie lokalne</th>
      <th>Utworzono</th>
      <th>Przez</th>
      <th class="text-end">Akcje</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($accounts as $acc): ?>
  <tr>
    <td class="fw-semibold">
      <?= h($acc['imie_nazwisko']) ?>
      <?php if ($acc['opis']): ?>
      <br><small class="text-muted"><?= h(mb_strimwidth($acc['opis'], 0, 50, '…')) ?></small>
      <?php endif; ?>
    </td>
    <td class="small">
      <?= $acc['email'] ? '<a href="mailto:' . h($acc['email']) . '">' . h($acc['email']) . '</a>' : '<span class="text-muted">—</span>' ?>
    </td>
    <td class="font-monospace small">
      <?= $acc['m365_login'] ? h($acc['m365_login']) : '<span class="text-muted">—</span>' ?>
    </td>
    <td>
      <?php if (!$acc['m365_login']): ?>
        <span class="badge bg-light text-dark border">Brak konta</span>
      <?php elseif ($acc['m365_konto_aktywne']): ?>
        <span class="badge bg-success"><i class="bi bi-check-circle"></i> Aktywne</span>
      <?php else: ?>
        <span class="badge bg-secondary"><i class="bi bi-pause-circle"></i> Wyłączone</span>
      <?php endif; ?>
    </td>
    <td class="text-center">
      <?= $acc['m365_licencja_przypisana']
          ? '<i class="bi bi-check-circle text-success" title="Licencja przypisana"></i>'
          : '<span class="text-muted">—</span>' ?>
    </td>
    <td class="small">
      <?php if ($acc['linked_user_name']): ?>
        <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">
          <i class="bi bi-link-45deg"></i> <?= h($acc['linked_user_name']) ?>
        </span>
      <?php else: ?>
        <span class="text-muted">—</span>
      <?php endif; ?>
    </td>
    <td class="small text-nowrap"><?= date_pl($acc['m365_data_utworzenia'] ?: $acc['created_at']) ?></td>
    <td class="small"><?= h($acc['created_by_name'] ?? '—') ?></td>
    <td class="text-end text-nowrap">
      <!-- Dropdown akcji -->
      <div class="btn-group btn-group-sm">

        <?php if (!$acc['m365_login'] && $m365_enabled): ?>
        <!-- Utwórz konto -->
        <form method="post" action="m365_standalone_action.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id" value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="create">
          <button class="btn btn-sm btn-primary" title="Utwórz konto M365">
            <i class="bi bi-microsoft"></i> Utwórz
          </button>
        </form>

        <?php elseif ($acc['m365_login']): ?>
        <!-- Toggle aktywności -->
        <form method="post" action="m365_standalone_action.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id" value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="<?= $acc['m365_konto_aktywne'] ? 'disable' : 'enable' ?>">
          <button class="btn btn-sm <?= $acc['m365_konto_aktywne'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                  title="<?= $acc['m365_konto_aktywne'] ? 'Wyłącz konto' : 'Włącz konto' ?>">
            <i class="bi bi-<?= $acc['m365_konto_aktywne'] ? 'pause-circle' : 'play-circle' ?>"></i>
          </button>
        </form>

        <?php if ($acc['email']): ?>
        <!-- Wyślij mail z hasłem -->
        <form method="post" action="m365_standalone_action.php" class="d-inline">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id" value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="send_email">
          <button class="btn btn-sm btn-outline-primary" title="Wyślij mail z nowym hasłem">
            <i class="bi bi-envelope-at"></i>
          </button>
        </form>
        <?php endif; ?>

        <?php endif; ?>

        <!-- Dropdown: Edytuj, Powiąż, Usuń -->
        <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle dropdown-toggle-split"
                data-bs-toggle="dropdown" aria-expanded="false"></button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <button class="dropdown-item" data-bs-toggle="modal"
                    data-bs-target="#editModal<?= $acc['id'] ?>">
              <i class="bi bi-pencil me-2"></i>Edytuj dane
            </button>
          </li>
          <?php if ($acc['m365_user_id']): ?>
          <li>
            <button class="dropdown-item" data-bs-toggle="modal"
                    data-bs-target="#linkModal<?= $acc['id'] ?>">
              <i class="bi bi-link-45deg me-2"></i>Powiąż z kontem lokalnym
            </button>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li>
            <button class="dropdown-item text-warning" data-bs-toggle="modal"
                    data-bs-target="#unlinkModal<?= $acc['id'] ?>">
              <i class="bi bi-unlink me-2"></i>Odepnij konto M365
            </button>
          </li>
          <li>
            <button class="dropdown-item text-danger" data-bs-toggle="modal"
                    data-bs-target="#deleteM365Modal<?= $acc['id'] ?>">
              <i class="bi bi-microsoft me-2"></i>Usuń konto z Azure AD
            </button>
          </li>
          <li><hr class="dropdown-divider"></li>
          <?php endif; ?>
          <li>
            <button class="dropdown-item text-danger" data-bs-toggle="modal"
                    data-bs-target="#deleteRowModal<?= $acc['id'] ?>">
              <i class="bi bi-trash3 me-2"></i>Usuń wpis z rejestru
            </button>
          </li>
        </ul>
      </div>
    </td>
  </tr>

  <?php
  /* ── Modalne dla każdego wiersza ─────────────────────────────────── */

  // EDIT
  ?>
  <div class="modal fade" id="editModal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-pencil"></i> Edytuj dane</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="post" action="m365_standalone_action.php">
          <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id"   value="<?= $acc['id'] ?>">
          <input type="hidden" name="action"  value="edit">
          <div class="modal-body row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
              <input type="text" name="imie_nazwisko" class="form-control"
                     value="<?= h($acc['imie_nazwisko']) ?>" required>
            </div>
            <div class="col-12">
              <label class="form-label">Adres e-mail</label>
              <input type="email" name="email" class="form-control"
                     value="<?= h($acc['email'] ?? '') ?>">
            </div>
            <div class="col-12">
              <label class="form-label">Opis / notatka</label>
              <textarea name="opis" class="form-control" rows="2"><?= h($acc['opis'] ?? '') ?></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-floppy"></i> Zapisz</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php if ($acc['m365_user_id']): ?>
  <!-- LINK LOCAL USER -->
  <div class="modal fade" id="linkModal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header"><h5 class="modal-title"><i class="bi bi-link-45deg"></i> Powiąż konto lokalne</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="post" action="m365_standalone_action.php">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="link_local_user">
          <div class="modal-body">
            <p class="text-muted small">Powiązanie umożliwia logowanie przez Microsoft 365 do konta lokalnego w systemie.</p>
            <label class="form-label fw-semibold">Konto lokalne</label>
            <select name="local_user_id" class="form-select" required>
              <option value="">— wybierz użytkownika —</option>
              <?php foreach ($local_users as $lu): ?>
              <option value="<?= intval($lu['id']) ?>"
                      <?= ($acc['linked_user_id'] == $lu['id']) ? 'selected' : '' ?>>
                <?= h($lu['name']) ?> &lt;<?= h($lu['email']) ?>&gt;
                <?= $lu['microsoft_id'] ? ' ✓' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg"></i> Powiąż</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- UNLINK -->
  <div class="modal fade" id="unlinkModal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-warning"><h5 class="modal-title"><i class="bi bi-unlink"></i> Odepnij konto M365</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <form method="post" action="m365_standalone_action.php">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="unlink">
          <div class="modal-body">
            <p>Konto <strong><?= h($acc['m365_login']) ?></strong> zostanie odpięte od wpisu w rejestrze.<br>
            <strong>Konto w Azure AD NIE zostanie usunięte.</strong></p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button type="submit" class="btn btn-warning"><i class="bi bi-unlink"></i> Odepnij</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- DELETE M365 -->
  <div class="modal fade" id="deleteM365Modal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-microsoft"></i> Usuń konto z Azure AD</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <form method="post" action="m365_standalone_action.php">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="delete_m365">
          <div class="modal-body">
            <p class="text-danger fw-bold">Tej operacji nie można cofnąć!</p>
            <p>Konto <strong><?= h($acc['m365_login']) ?></strong> zostanie trwale usunięte z Azure AD.</p>
            <?php if ($acc['linked_user_name']): ?>
            <div class="alert alert-warning py-2 small">
              <i class="bi bi-exclamation-triangle"></i>
              Powiązane konto lokalne <strong><?= h($acc['linked_user_name']) ?></strong> zostanie dezaktywowane.
            </div>
            <?php endif; ?>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Usuń z Azure AD</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- DELETE ROW -->
  <div class="modal fade" id="deleteRowModal<?= $acc['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-danger text-white"><h5 class="modal-title"><i class="bi bi-trash3"></i> Usuń wpis z rejestru</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <form method="post" action="m365_standalone_action.php">
          <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
          <input type="hidden" name="sa_id"  value="<?= $acc['id'] ?>">
          <input type="hidden" name="action" value="delete_row">
          <div class="modal-body">
            <?php if ($acc['m365_login']): ?>
            <div class="alert alert-warning py-2 small mb-2">
              <i class="bi bi-exclamation-triangle"></i>
              Usunie tylko wpis z rejestru. Konto <strong><?= h($acc['m365_login']) ?></strong> w Azure AD <strong>NIE zostanie usunięte</strong>.
            </div>
            <?php endif; ?>
            <p>Usuwa wpis dla <strong><?= h($acc['imie_nazwisko']) ?></strong> z lokalnego rejestru.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button type="submit" class="btn btn-danger"><i class="bi bi-trash3"></i> Usuń wpis</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php else: ?>
<div class="card-body text-muted">
  Brak kont. Użyj przycisku <strong>Nowe konto M365</strong>, aby utworzyć konto bez umowy.
</div>
<?php endif; ?>
</div><!-- /card -->

<!-- ── Modal: Nowe konto ──────────────────────────────────────────────────── -->
<div class="modal fade" id="createModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="bi bi-microsoft"></i> Nowe konto M365 bez umowy</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" action="m365_standalone_action.php">
        <input type="hidden" name="_csrf"  value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create">
        <div class="modal-body">
          <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle"></i>
            Konto zostanie utworzone w Azure AD (tenant: <strong><?= h(m365_setting('m365_domain') ?: 'nie skonfigurowano') ?></strong>)
            i zapisane w rejestrze <em>bez powiązania z umową</em>.
          </div>
          <div class="row g-3">
            <div class="col-md-8">
              <label class="form-label fw-semibold">Imię i nazwisko <span class="text-danger">*</span></label>
              <input type="text" name="imie_nazwisko" class="form-control"
                     placeholder="np. Jan Kowalski" required>
              <div class="form-text">Na podstawie imienia i nazwiska zostanie wygenerowany login M365.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Aktywuj od razu?</label>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="konto_aktywne" id="kaNew" value="1" checked>
                <label class="form-check-label" for="kaNew">Konto aktywne od razu</label>
              </div>
            </div>
            <div class="col-md-8">
              <label class="form-label">Adres e-mail (do wysyłki danych logowania)</label>
              <input type="email" name="email" class="form-control" placeholder="np. jan.kowalski@email.pl">
              <div class="form-text">Jeśli podasz adres, zostanie wysłany mail z danymi logowania.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Przypisz licencję?</label>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" name="przypisz_licencje" id="plNew" value="1" checked>
                <label class="form-check-label" for="plNew">
                  <?php
                  $sku = m365_setting('m365_license_sku_id');
                  echo $sku ? 'Tak (domyślna)' : '<span class="text-muted">Brak SKU w ustawieniach</span>';
                  ?>
                </label>
              </div>
            </div>
            <div class="col-12">
              <label class="form-label">Opis / notatka <span class="text-muted small">(widoczny tylko w rejestrze)</span></label>
              <textarea name="opis" class="form-control" rows="2"
                        placeholder="np. Pracownik administracyjny, konto techniczne, …"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">
            <i class="bi bi-microsoft"></i> Utwórz konto M365
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

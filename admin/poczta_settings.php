<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/poczta.php';

require_role('admin');
$PAGE_TITLE = 'Ustawienia modułu Poczty';

$settings_keys = [
    'poczta_scan_interval_min',
    'poczta_scan_hour_from',
    'poczta_scan_hour_to',
    'poczta_store_html',
    'poczta_download_attachments',
    'poczta_attach_max_kb',
    'poczta_worker_batch',
    'poczta_webmail_url',
    'poczta_rc_login_notice',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $values = [
        'poczta_scan_interval_min'     => (string)max(1, (int)($_POST['poczta_scan_interval_min'] ?? 10)),
        'poczta_scan_hour_from'        => (string)max(0, min(23, (int)($_POST['poczta_scan_hour_from'] ?? 6))),
        'poczta_scan_hour_to'          => (string)max(0, min(23, (int)($_POST['poczta_scan_hour_to'] ?? 23))),
        'poczta_store_html'            => isset($_POST['poczta_store_html']) ? '1' : '0',
        'poczta_download_attachments'  => isset($_POST['poczta_download_attachments']) ? '1' : '0',
        'poczta_attach_max_kb'         => (string)max(1, (int)($_POST['poczta_attach_max_kb'] ?? 5120)),
        'poczta_worker_batch'          => (string)max(1, (int)($_POST['poczta_worker_batch'] ?? 5)),
        'poczta_webmail_url'           => trim((string)($_POST['poczta_webmail_url'] ?? '')),
        'poczta_rc_login_notice'       => trim((string)($_POST['poczta_rc_login_notice'] ?? '')),
    ];
    foreach ($values as $k => $v) {
        org_setting_set($k, $v);
    }

    flash_set('success', 'Ustawienia zapisane.');
    header('Location: ' . APP_URL . '/admin/poczta_settings.php'); exit;
}

$cfg = [];
foreach ($settings_keys as $k) $cfg[$k] = org_setting($k);
if ($cfg['poczta_scan_interval_min']    === '') $cfg['poczta_scan_interval_min']    = '10';
if ($cfg['poczta_scan_hour_from']       === '') $cfg['poczta_scan_hour_from']       = '6';
if ($cfg['poczta_scan_hour_to']         === '') $cfg['poczta_scan_hour_to']         = '23';
if ($cfg['poczta_attach_max_kb']        === '') $cfg['poczta_attach_max_kb']        = '5120';
if ($cfg['poczta_worker_batch']         === '') $cfg['poczta_worker_batch']         = '5';
if ($cfg['poczta_rc_login_notice']      === '') $cfg['poczta_rc_login_notice']      = poczta_rc_login_notice_default();

$_mb_count = 0;
try { $_mb_count = (int)(db_one("SELECT COUNT(*) AS n FROM poczta_mailboxes")['n'] ?? 0); } catch (\Throwable $e) {}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2">
    <h4 class="mb-0"><i class="bi bi-envelope text-primary me-2"></i>Ustawienia modułu Poczty</h4>
    <a href="<?= APP_URL ?>/poczta/dashboard.php" class="btn btn-sm btn-outline-secondary ms-auto">
        <i class="bi bi-envelope me-1"></i>Przejdź do Poczty
    </a>
    <a href="<?= APP_URL ?>/poczta/index.php" class="btn btn-sm btn-outline-primary">
        <i class="bi bi-inboxes me-1"></i>Skrzynki (<?= $_mb_count ?>)
    </a>
</div>

<?= flash_html() ?>

<div class="row g-4" style="max-width:820px">
    <div class="col-12">
        <form method="post">
            <?= csrf_field() ?>

            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="bi bi-clock-history me-2 text-primary"></i>Harmonogram skanowania</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Interwał (minuty)</label>
                            <input type="number" min="1" class="form-control" name="poczta_scan_interval_min" value="<?= h($cfg['poczta_scan_interval_min']) ?>">
                            <div class="form-text">Jak często generowane są zadania skanowania per skrzynka.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Godzina od</label>
                            <input type="number" min="0" max="23" class="form-control" name="poczta_scan_hour_from" value="<?= h($cfg['poczta_scan_hour_from']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Godzina do</label>
                            <input type="number" min="0" max="23" class="form-control" name="poczta_scan_hour_to" value="<?= h($cfg['poczta_scan_hour_to']) ?>">
                        </div>
                    </div>
                    <div class="form-text mt-2">Skrypt <code>cron/poczta_dispatch.php</code> sam pomija uruchomienia poza tym oknem i przed upływem interwału. Dyspozytor (<code>cron/dispatcher.php</code>) sprawdza go co 10 minut — to najmniejsza możliwa granulacja, nawet gdy interwał tutaj jest krótszy.</div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="bi bi-file-earmark-text me-2 text-primary"></i>Treść wiadomości</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="poczta_store_html" name="poczta_store_html" <?= $cfg['poczta_store_html'] !== '0' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="poczta_store_html">Przechowuj pełny HTML wiadomości</label>
                        <div class="form-text">Wyłączenie zapisuje tylko wersję tekstową (mniej danych, brak podglądu formatowania).</div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="bi bi-paperclip me-2 text-primary"></i>Załączniki</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="poczta_download_attachments" name="poczta_download_attachments" <?= $cfg['poczta_download_attachments'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="poczta_download_attachments">Pobieraj zawartość załączników</label>
                        <div class="form-text">Domyślnie zapisywane są tylko metadane (nazwa, typ, rozmiar) — bez pobierania treści plików.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Maks. rozmiar pojedynczego załącznika (KB)</label>
                        <input type="number" min="1" class="form-control" name="poczta_attach_max_kb" value="<?= h($cfg['poczta_attach_max_kb']) ?>">
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="bi bi-hdd-network me-2 text-primary"></i>Kolejka (RabbitMQ)</div>
                <div class="card-body">
                    <div class="col-md-4">
                        <label class="form-label">Rozmiar partii workera</label>
                        <input type="number" min="1" class="form-control" name="poczta_worker_batch" value="<?= h($cfg['poczta_worker_batch']) ?>">
                        <div class="form-text">Ile zadań ze skrzynki <code>poczta_skanowanie</code> przetwarza jeden przebieg <code>cron/poczta_worker.php</code>. Bez skonfigurowanego RabbitMQ (zmienna <code>RABBITMQ_HOST</code>) skanowanie odbywa się synchronicznie w <code>cron/poczta_dispatch.php</code>.</div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header fw-semibold"><i class="bi bi-envelope-open me-2 text-primary"></i>Webmail (Roundcube)</div>
                <div class="card-body">
                    <label class="form-label">Adres webmaila</label>
                    <input type="url" class="form-control" name="poczta_webmail_url" placeholder="https://rc.feer.org.pl" value="<?= h($cfg['poczta_webmail_url']) ?>">
                    <div class="form-text">Osobny serwis Docker (<code>rc</code>/Roundcube, <code>docker/docker-compose.rc.yml</code>) — logowanie OAuth2 do Microsoft 365, dołączanie plików z OneDrive. Wypełnienie tego pola pokazuje przycisk „Otwórz Roundcube" w panelu Poczty. Wdrożenie i konfiguracja Azure AD: <code>docker/roundcube/README.md</code>.</div>

                    <label class="form-label mt-3">Komunikat na stronie logowania Roundcube</label>
                    <textarea class="form-control" name="poczta_rc_login_notice" rows="5"><?= h($cfg['poczta_rc_login_notice']) ?></textarea>
                    <div class="form-text">
                        Widoczny na stronie logowania <code>rc.feer.org.pl</code>, zanim ktoś kliknie „Zaloguj się przez Microsoft 365".
                        Dopuszczone proste tagi HTML (<code>&lt;strong&gt;</code>, <code>&lt;br&gt;</code>, <code>&lt;a&gt;</code>) — to pole jest widoczne tylko dla adminów, ale trafia bez zmian na publiczną stronę logowania.
                        Kontener Roundcube pobiera tę treść przez wewnętrzne API (<code>api/internal/rc_login_notice.php</code>) z cache ~5&nbsp;min — zmiana tutaj widoczna jest z niewielkim opóźnieniem, bez potrzeby restartu kontenera.
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Zapisz ustawienia</button>
        </form>
    </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>

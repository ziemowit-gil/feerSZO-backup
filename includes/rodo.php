<?php
/**
 * includes/rodo.php — Moduł RODO
 * Upoważnienia do przetwarzania danych + rejestr + szkolenia
 */

// ── Predefiniowane zakresy § 2 ────────────────────────────────────────────────
const RODO_SCOPE_ITEMS = [
    'podopieczni' => 'obsługi podopiecznych i beneficjentów organizacji',
    'ewidencja'   => 'prowadzenia ewidencji zgłoszeń, wniosków i dokumentacji',
    'projekty'    => 'realizacji projektów i programów statutowych',
    'wolontariat' => 'organizacji i koordynacji wolontariatu',
    'kontrahenci' => 'obsługi kontrahentów, partnerów i darczyńców',
    'media'       => 'prowadzenia komunikacji i mediów społecznościowych',
    'it'          => 'administrowania systemami informatycznymi organizacji',
    'ksiegowosc'  => 'prowadzenia rozliczeń i dokumentacji finansowej',
    'rekrutacja'  => 'procesu rekrutacji, selekcji i onboardingu',
];

// Tematy szkoleń
const RODO_TRAINING_TOPICS = [
    'przepisy_rodo' => 'Przepisy RODO i krajowe przepisy o ochronie danych',
    'polityka'      => 'Wewnętrzna polityka bezpieczeństwa danych',
    'prawa_osob'    => 'Prawa osób, których dane dotyczą',
    'incydenty'     => 'Postępowanie w przypadku naruszenia ochrony danych',
    'it_bezp'       => 'Zasady bezpiecznego korzystania z systemów IT',
    'tajemnica'     => 'Obowiązek zachowania tajemnicy danych',
];

// ── Typy podstaw upoważnienia (umowy + osoby bez umowy) ───────────────────────
// table/name/pesel/end/open — kolumny tabeli umowy; doc_* — sformułowania w dokumentach.
const RODO_CONTRACT_TYPES = [
    'wolontariat' => [
        'label' => 'Wolontariat', 'table' => 'umowy_wolontariat',
        'name' => 'imie_nazwisko', 'pesel' => 'pesel', 'end' => 'data_zakonczenia', 'open' => 'bezterminowa',
        'doc_contract' => 'porozumienia o wolontariacie', 'doc_contract_short' => 'Porozumienie',
        'doc_relation' => 'stosunku wolontariatu', 'person_role' => 'wolontariusza',
    ],
    'zlecenie' => [
        'label' => 'Umowa zlecenia', 'table' => 'umowy_zlecenie',
        'name' => 'imie_nazwisko', 'pesel' => 'pesel', 'end' => 'data_zakonczenia', 'open' => null,
        'doc_contract' => 'umowy zlecenia', 'doc_contract_short' => 'Umowa zlecenia',
        'doc_relation' => 'współpracy na podstawie umowy zlecenia', 'person_role' => 'zleceniobiorcy',
    ],
    'praca' => [
        'label' => 'Umowa o pracę', 'table' => 'umowy_praca',
        'name' => 'imie_nazwisko', 'pesel' => 'pesel', 'end' => 'data_zakonczenia', 'open' => null,
        'doc_contract' => 'umowy o pracę', 'doc_contract_short' => 'Umowa o pracę',
        'doc_relation' => 'stosunku pracy', 'person_role' => 'pracownika',
    ],
    'dzielo' => [
        'label' => 'Umowa o dzieło', 'table' => 'umowy_dzielo',
        'name' => 'imie_nazwisko', 'pesel' => 'pesel', 'end' => 'termin_oddania', 'open' => null,
        'doc_contract' => 'umowy o dzieło', 'doc_contract_short' => 'Umowa o dzieło',
        'doc_relation' => 'współpracy na podstawie umowy o dzieło', 'person_role' => 'wykonawcy',
    ],
    'uslugi' => [
        'label' => 'Umowa o świadczenie usług', 'table' => 'umowy_uslugi',
        'name' => 'nazwa_wykonawcy', 'pesel' => null, 'end' => 'data_zakonczenia', 'open' => 'czas_nieokreslony',
        'doc_contract' => 'umowy o świadczenie usług', 'doc_contract_short' => 'Umowa o świadczenie usług',
        'doc_relation' => 'współpracy na podstawie umowy o świadczenie usług', 'person_role' => 'wykonawcy',
    ],
    'inne' => [
        'label' => 'Inna umowa', 'table' => 'umowy_inne',
        'name' => 'strona_umowy', 'pesel' => null, 'end' => 'data_zakonczenia', 'open' => 'czas_nieokreslony',
        'doc_contract' => 'umowy', 'doc_contract_short' => 'Umowa',
        'doc_relation' => 'współpracy', 'person_role' => 'osoby upoważnionej',
    ],
    'bez_umowy' => [
        'label' => 'Bez umowy (funkcja, członkostwo, staż)', 'table' => null,
        'name' => null, 'pesel' => null, 'end' => null, 'open' => null,
        'doc_contract' => 'pełnionej funkcji', 'doc_contract_short' => 'Podstawa',
        'doc_relation' => 'współpracy z Administratorem', 'person_role' => 'osoby upoważnionej',
    ],
];

/** Metadane typu podstawy; nieznany/pusty typ → wolontariat (zgodność wsteczna). */
function rodo_type_meta(?string $type): array {
    return RODO_CONTRACT_TYPES[$type ?? ''] ?? RODO_CONTRACT_TYPES['wolontariat'];
}

/** Walidacja typu z żądania — zwraca klucz z rejestru albo ''. */
function rodo_clean_type(?string $type): string {
    $type = (string)$type;
    return isset(RODO_CONTRACT_TYPES[$type]) ? $type : '';
}

/**
 * Wiersz umowy znormalizowany do pól używanych przez moduł RODO.
 * Zwraca null gdy typ nie ma tabeli albo umowy nie ma.
 */
function rodo_contract_fetch(string $type, int $id): ?array {
    $m = RODO_CONTRACT_TYPES[$type] ?? null;
    if (!$m || !$m['table'] || !$id) return null;
    try {
        $c = db_one("SELECT * FROM {$m['table']} WHERE id=?", [$id]);
    } catch (\Throwable $e) { return null; }
    if (!$c) return null;
    $pesel = $m['pesel'] ? ($c[$m['pesel']] ?? '') : '';
    // uslugi/inne trzymają PESEL razem z NIP/KRS — bierzemy tylko 11 cyfr
    if (!$pesel) {
        $raw = preg_replace('/\D/', '', (string)($c['nip_pesel'] ?? $c['pesel_nip_krs'] ?? ''));
        if (strlen($raw) === 11) $pesel = $raw;
    }
    return [
        'person_name'     => (string)($c[$m['name']] ?? ''),
        'person_pesel'    => (string)$pesel,
        'contract_number' => (string)($c['numer_umowy'] ?? ''),
        'contract_date'   => (string)($c['data_zawarcia'] ?? ''),
        'start_date'      => (string)(($c['data_rozpoczecia'] ?? '') ?: ($c['data_zawarcia'] ?? '')),
        'end_date'        => (string)($c[$m['end']] ?? ''),
        'open_ended'      => $m['open'] ? !empty($c[$m['open']]) : false,
        'status'          => (string)($c['status'] ?? ''),
    ];
}

/**
 * Umowy danej osoby (po e-mailu / loginie M365 / ID Microsoft) we wszystkich typach
 * z RODO_CONTRACT_TYPES — dla samoobsługi (portal tożsamości, eksport RODO).
 * Nie każda tabela ma kolumny m365_* — każde zapytanie osobno w try.
 * @return array<string,int[]> typ => lista id
 */
function rodo_contract_ids_for_login(string $login, string $microsoft_id = ''): array {
    $out = [];
    foreach (RODO_CONTRACT_TYPES as $type => $m) {
        if (!$m['table']) continue;
        $ids = [];
        $queries = [];
        if ($login !== '') {
            $queries[] = ["SELECT id FROM {$m['table']} WHERE email=?", [$login]];
            $queries[] = ["SELECT id FROM {$m['table']} WHERE m365_login=?", [$login]];
        }
        if ($microsoft_id !== '') {
            $queries[] = ["SELECT id FROM {$m['table']} WHERE m365_user_id=?", [$microsoft_id]];
        }
        foreach ($queries as [$sql, $params]) {
            try {
                foreach (db_all($sql, $params) as $r) $ids[(int)$r['id']] = true;
            } catch (\Throwable $e) {} // brak kolumny w tej tabeli
        }
        if ($ids) $out[$type] = array_keys($ids);
    }
    return $out;
}

/** Link do widoku umowy powiązanej z upoważnieniem (null dla „bez umowy”). */
function rodo_contract_url(string $type, int $id): ?string {
    $m = RODO_CONTRACT_TYPES[$type] ?? null;
    if (!$m || !$m['table'] || !$id) return null;
    $url = APP_URL . "/contracts/{$type}/view.php?id={$id}";
    return $type === 'wolontariat' ? $url . '&tab=rodo' : $url;
}

/**
 * Karta „Upoważnienia RODO” do bocznej kolumny widoku umowy
 * (lista upoważnień powiązanych z umową + nadanie nowego).
 */
function rodo_contract_card(string $type, int $id): void {
    if (!isset(RODO_CONTRACT_TYPES[$type]) || !$id) return;
    try {
        rodo_migrate();
        $rows = db_all(
            "SELECT id, number, status, training_done FROM rodo_authorizations
             WHERE contract_type=? AND contract_id=? ORDER BY created_at DESC",
            [$type, $id]
        );
    } catch (\Throwable $e) { return; }
    $can_edit = in_array(current_user()['role'] ?? '', ['admin', 'editor'], true);
    ?>
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock text-primary" aria-hidden="true"></i>
        <span>Upoważnienia RODO</span>
        <?php if ($rows): ?><span class="badge bg-secondary ms-auto"><?= count($rows) ?></span><?php endif; ?>
      </div>
      <div class="card-body small">
        <?php if (!$rows): ?>
        <p class="text-muted mb-2">Brak upoważnienia do przetwarzania danych osobowych dla tej umowy.</p>
        <?php else: ?>
        <ul class="list-unstyled mb-2">
          <?php foreach ($rows as $r): ?>
          <li class="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom">
            <a href="<?= APP_URL ?>/rodo/view.php?id=<?= (int)$r['id'] ?>" class="font-monospace text-decoration-none"><?= h($r['number']) ?></a>
            <?= rodo_status_badge($r['status']) ?>
            <?php if (!$r['training_done'] && $r['status'] === 'aktywne'): ?>
            <span class="badge bg-warning text-dark">bez szkolenia</span>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
        <?php if ($can_edit): ?>
        <a href="<?= APP_URL ?>/rodo/new.php?contract_type=<?= h($type) ?>&amp;contract_id=<?= $id ?>"
           class="btn btn-sm btn-outline-primary w-100">
          <i class="bi bi-shield-plus me-1" aria-hidden="true"></i>Nadaj upoważnienie RODO
        </a>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

// ── Migracja ──────────────────────────────────────────────────────────────────
function rodo_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $pdo  = db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS rodo_authorizations (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_type   TEXT    NOT NULL DEFAULT 'wolontariat',
        contract_id     INTEGER,
        number          TEXT    NOT NULL UNIQUE,
        person_name     TEXT    NOT NULL,
        person_pesel    TEXT,
        org_name        TEXT    NOT NULL DEFAULT '',
        org_address     TEXT    NOT NULL DEFAULT '',
        org_nip         TEXT    NOT NULL DEFAULT '',
        scope_items     TEXT    NOT NULL DEFAULT '[]',
        scope_custom    TEXT,
        authorized_from DATE    NOT NULL,
        authorized_until DATE,
        contract_number TEXT,
        contract_date   DATE,
        status          TEXT    NOT NULL DEFAULT 'aktywne',
        signed_by_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        signed_by_name  TEXT,
        signed_at       DATE,
        vol_signed_at   DATE,
        training_done   INTEGER NOT NULL DEFAULT 0,
        notes           TEXT,
        created_by      INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rodo_contract ON rodo_authorizations(contract_type,contract_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rodo_status   ON rodo_authorizations(status)");

    // Pliki podpisanych dokumentów — idempotentne
    try { $pdo->exec("ALTER TABLE rodo_authorizations ADD COLUMN signed_doc_path     TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE rodo_authorizations ADD COLUMN vol_signed_doc_path TEXT"); } catch (\Throwable $e) {}
    try { $pdo->exec("ALTER TABLE rodo_authorizations ADD COLUMN revoke_doc_path     TEXT"); } catch (\Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS rodo_trainings (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        authorization_id INTEGER NOT NULL REFERENCES rodo_authorizations(id) ON DELETE CASCADE,
        training_date    DATE    NOT NULL,
        topics           TEXT    NOT NULL DEFAULT '[]',
        trainer_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        trainer_name     TEXT    NOT NULL DEFAULT '',
        notes            TEXT,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_rodo_train ON rodo_trainings(authorization_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rodo_revocations (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        authorization_id INTEGER NOT NULL REFERENCES rodo_authorizations(id) ON DELETE CASCADE,
        revoked_by_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
        revoked_by_name  TEXT    NOT NULL DEFAULT '',
        revoked_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        reason           TEXT
    )");

    // Log usunięć (audit trail — dane osobowe muszą być usuwane ale usunięcie musi być odnotowane)
    $pdo->exec("CREATE TABLE IF NOT EXISTS rodo_deletion_log (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        auth_number     TEXT    NOT NULL,
        auth_person     TEXT    NOT NULL,
        auth_pesel      TEXT,
        auth_contract   TEXT,
        reason          TEXT,
        deleted_by_id   INTEGER REFERENCES users(id) ON DELETE SET NULL,
        deleted_by_name TEXT    NOT NULL DEFAULT '',
        deleted_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Auto-update org_name w settings → pełna nazwa z konstant ORG_NAME jeśli krótsza
    if (defined('ORG_NAME') && ORG_NAME !== '') {
        try {
            $stored = db_one("SELECT value FROM settings WHERE key_='org_name'");
            $current = $stored['value'] ?? '';
            if (strlen(ORG_NAME) > strlen($current)) {
                if ($stored) {
                    $pdo->prepare("UPDATE settings SET value=? WHERE key_='org_name'")->execute([ORG_NAME]);
                } else {
                    $pdo->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute(['org_name', ORG_NAME]);
                }
            }
        } catch (\Throwable $e) {}
    }
}

// ── Numer upoważnienia ────────────────────────────────────────────────────────
/**
 * Generuje numer upoważnienia.
 * Jeśli podano numer umowy: [nr_umowy]/RODO (+ sufiks gdy istnieje kilka)
 * Jeśli nie podano: RODO/RRRR/NNNN
 */
function rodo_next_number(string $contract_number = ''): string {
    $contract_number = trim($contract_number);
    if ($contract_number !== '') {
        // Format pochodny od numeru umowy
        $base = $contract_number . '/RODO';
        $exists = db_one("SELECT COUNT(*) AS c FROM rodo_authorizations WHERE number LIKE ?", [$base . '%']);
        $cnt = (int)($exists['c'] ?? 0);
        return $cnt === 0 ? $base : $base . '-' . ($cnt + 1);
    }
    // Bez umowy — sekwencyjny
    $year = date('Y');
    $last = db_one(
        "SELECT number FROM rodo_authorizations WHERE number LIKE ? ORDER BY id DESC LIMIT 1",
        ["RODO/{$year}/%"]
    );
    if ($last) {
        $parts = explode('/', $last['number']);
        $seq   = (int)($parts[2] ?? 0) + 1;
    } else {
        $seq = 1;
    }
    return "RODO/{$year}/" . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── Walidacja okresu upoważnienia ─────────────────────────────────────────────
/**
 * authorized_until nie może przekroczyć daty zakończenia powiązanej umowy
 * (dowolny typ z RODO_CONTRACT_TYPES; umowy bezterminowe bez ograniczenia).
 * Zwraca null gdy OK, string z komunikatem błędu gdy naruszenie.
 */
function rodo_validate_period(string $contract_type, int $contract_id, string $authorized_until): ?string {
    if (!$contract_id || !$authorized_until) return null;
    $c = rodo_contract_fetch($contract_type, $contract_id);
    if (!$c || $c['open_ended'] || !$c['end_date']) return null;
    if ($authorized_until > $c['end_date']) {
        return 'Upoważnienie RODO nie może być dłuższe niż okres ' . rodo_type_meta($contract_type)['doc_contract']
             . ' (max. ' . date('d.m.Y', strtotime($c['end_date'])) . ').';
    }
    return null;
}

// ── Dane organizacji ──────────────────────────────────────────────────────────
function rodo_org_data(): array {
    $stored = org_setting('org_name');
    $const  = defined('ORG_NAME') ? ORG_NAME : '';
    // Preferuj pełną nazwę — jeśli stała jest dłuższa niż zapisana w settings, użyj stałej
    $name = (strlen($const) > strlen($stored)) ? $const : ($stored ?: $const);
    return [
        'name'    => $name,
        'address' => org_setting('org_adres') ?: '',
        'city'    => org_setting('org_miejscowosc') ?: '',
        'nip'     => org_setting('org_nip') ?: '',
        'krs'     => org_setting('org_krs') ?: '',
    ];
}

/**
 * Szybkie utworzenie upoważnienia RODO wprost z formularza dodawania umowy.
 * $c: numer_umowy, data_zawarcia, imie_nazwisko, pesel, scope_items(array),
 *     scope_custom, authorized_until. Zwraca id lub null (gdy nic nie wybrano).
 */
function rodo_quick_create(string $contract_type, int $contract_id, array $c): ?int {
    $scope = array_values(array_filter((array)($c['scope_items'] ?? [])));
    if (!$scope && empty($c['scope_custom'])) return null; // brak zakresu → nic nie twórz
    $org = rodo_org_data();
    $uid = (int)(function_exists('current_user') ? (current_user()['id'] ?? 0) : 0);
    $name = trim((string)($c['imie_nazwisko'] ?? ''));
    $from = ($c['data_zawarcia'] ?? '') ?: date('Y-m-d');
    return db_insert('rodo_authorizations', [
        'number'           => rodo_next_number($c['numer_umowy'] ?? ''),
        'contract_type'    => $contract_type,
        'contract_id'      => $contract_id,
        'person_name'      => $name,
        'person_pesel'     => ($c['pesel'] ?? '') ?: null,
        'org_name'         => $org['name'] ?? '',
        'org_address'      => trim(($org['address'] ?? '') . ', ' . ($org['city'] ?? ''), ', '),
        'org_nip'          => $org['nip'] ?? '',
        'scope_items'      => json_encode($scope, JSON_UNESCAPED_UNICODE),
        'scope_custom'     => ($c['scope_custom'] ?? '') ?: null,
        'authorized_from'  => $from,
        'authorized_until' => ($c['authorized_until'] ?? '') ?: null,
        'contract_number'  => ($c['numer_umowy'] ?? '') ?: null,
        'contract_date'    => ($c['data_zawarcia'] ?? '') ?: null,
        'status'           => 'aktywne',
        'signed_by_id'     => $uid ?: null,
        'signed_by_name'   => function_exists('current_user') ? (current_user()['name'] ?? '') : '',
        'created_by'       => $uid ?: null,
    ]);
}

/**
 * Renderuje sekcję formularza „Upoważnienie RODO" do wstawienia w add.php umowy.
 * Bez <form> — osadzana w istniejącym formularzu. Pola: rodo_grant, scope_items[],
 * rodo_scope_custom, rodo_authorized_until.
 */
function rodo_grant_form_section(): void {
    ?>
    <div class="card shadow-sm mb-4" id="rodo-grant-card">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock text-primary"></i> Upoważnienie do przetwarzania danych (RODO)
      </div>
      <div class="card-body">
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" role="switch" id="rodo_grant" name="rodo_grant" value="1"
                 onchange="var b=document.getElementById('rodo-grant-body');if(b)b.hidden=!this.checked;">
          <label class="form-check-label fw-semibold" for="rodo_grant">
            Nadaj upoważnienie RODO wraz z umową
          </label>
        </div>
        <div id="rodo-grant-body" hidden>
          <p class="text-muted small mb-2">Zakres upoważnienia (§ 2) — zaznacz cele przetwarzania:</p>
          <div class="row g-1 mb-3">
            <?php foreach (RODO_SCOPE_ITEMS as $key => $label): ?>
            <div class="col-md-6">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="scope_items[]" value="<?= h($key) ?>" id="rsi_<?= h($key) ?>">
                <label class="form-check-label small" for="rsi_<?= h($key) ?>"><?= h(ucfirst($label)) ?></label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="row g-2">
            <div class="col-md-8">
              <label class="form-label small fw-semibold" for="rodo_scope_custom">Dodatkowy zakres (opcjonalnie)</label>
              <input type="text" class="form-control form-control-sm" id="rodo_scope_custom" name="rodo_scope_custom" placeholder="np. obsługa konkretnego programu">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold" for="rodo_authorized_until">Ważne do (opcjonalnie)</label>
              <input type="date" class="form-control form-control-sm" id="rodo_authorized_until" name="rodo_authorized_until">
            </div>
          </div>
          <div class="form-text mt-2"><i class="bi bi-info-circle me-1"></i>Domyślnie od daty zawarcia umowy do jej zakończenia. Numer upoważnienia nadamy automatycznie.</div>
        </div>
      </div>
    </div>
    <?php
}

// ── Statusy ───────────────────────────────────────────────────────────────────
function rodo_status_badge(string $status): string {
    $map = [
        'aktywne'  => ['bg-success',   'Aktywne'],
        'cofnięte' => ['bg-danger',    'Odwołane'],
        'wygasłe'  => ['bg-secondary', 'Wygasłe'],
    ];
    [$cls, $lbl] = $map[$status] ?? ['bg-light text-dark border', $status];
    return "<span class=\"badge {$cls}\">" . h($lbl) . '</span>';
}

// ── Auto-cofnięcie przy zamkniętych umowach ───────────────────────────────────
function rodo_auto_expire(): void {
    try {
        // Upoważnienia z datą końcową (m.in. „bez umowy”) — wygasają po upływie terminu
        db()->prepare("UPDATE rodo_authorizations SET status='wygasłe', updated_at=?
                       WHERE status='aktywne' AND authorized_until IS NOT NULL AND authorized_until <> '' AND authorized_until < ?")
            ->execute([date('Y-m-d H:i:s'), date('Y-m-d')]);

        $active = db_all(
            "SELECT r.id, r.contract_type, r.contract_id
             FROM rodo_authorizations r
             WHERE r.status = 'aktywne' AND r.contract_id IS NOT NULL"
        );
        $today = date('Y-m-d');
        $ended_statuses = ['zakończona','rozwiązana','anulowana','wygasła'];
        foreach ($active as $a) {
            $m = RODO_CONTRACT_TYPES[$a['contract_type']] ?? null;
            if (!$m || !$m['table']) continue; // „bez umowy” — wygasa tylko datą authorized_until
            try {
                $c = db_one("SELECT status, {$m['end']} AS data_koniec FROM {$m['table']} WHERE id=?", [(int)$a['contract_id']]);
            } catch (\Throwable $e) { continue; }
            if (!$c) continue;
            $date_passed = !empty($c['data_koniec']) && $c['data_koniec'] < $today;
            if (in_array($c['status'], $ended_statuses, true) || $date_passed) {
                db()->prepare("UPDATE rodo_authorizations SET status='wygasłe', updated_at=? WHERE id=?")
                    ->execute([date('Y-m-d H:i:s'), $a['id']]);
            }
        }
    } catch (\Throwable $e) {}
}

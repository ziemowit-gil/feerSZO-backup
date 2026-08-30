-- ============================================================================
-- Moduł Oświadczeń (wolontariat) — skrypt tworzący tabele.
--
-- W samej aplikacji tabele powstają automatycznie (self-healing, patrz
-- includes/oswiadczenia.php, CREATE TABLE IF NOT EXISTS uruchamiane przy
-- pierwszym użyciu modułu — działa identycznie na MySQL i SQLite). Ten plik
-- to ta sama definicja jako samodzielny skrypt SQL — do ręcznego uruchomienia
-- lub jako dokumentacja schematu.
--
-- Wariant: MySQL / MariaDB (utf8mb4). Wersja SQLite — patrz sekcja na dole.
-- ============================================================================

CREATE TABLE IF NOT EXISTS szablony_oswiadczen (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kod           VARCHAR(50)  NOT NULL COMMENT 'unikalny identyfikator szablonu, np. regulamin_wolontariatu',
    tytul         VARCHAR(255) NOT NULL,
    tresc         TEXT         NOT NULL COMMENT 'treść oświadczenia (HTML)',
    wersja        INT          NOT NULL DEFAULT 1 COMMENT 'zmiana treści = nowa wersja; podpisy wcześniejszych wersji pozostają ważne dla swojej treści',
    aktywny       TINYINT(1)   NOT NULL DEFAULT 1,
    wymaga_2fa    TINYINT(1)   NOT NULL DEFAULT 1,
    utworzyl_id   INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_szablony_oswiadczen_kod (kod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS uzytkownik_oswiadczenie (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL COMMENT 'FK -> users.id',
    szablon_id         INT UNSIGNED NOT NULL COMMENT 'FK -> szablony_oswiadczen.id',
    status             VARCHAR(20)  NOT NULL DEFAULT 'oczekujace' COMMENT 'oczekujace | podpisane',
    tresc_hash         VARCHAR(64)  NULL COMMENT 'SHA-256 treści szablonu w chwili podpisu — wiąże podpis z dokładną wersją treści',
    kod_hash           VARCHAR(255) NULL COMMENT 'hash bcrypt aktywnego kodu OTP; NULL po zużyciu lub przed wygenerowaniem',
    kod_kanal          VARCHAR(10)  NULL COMMENT 'kanał, którym wysłano ostatni kod: sms | email',
    kod_wygasa_o       DATETIME     NULL,
    kod_prob           INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'liczba nieudanych prób weryfikacji bieżącego kodu (limit: 5)',
    podpisano_at       DATETIME     NULL,
    podpis_ip          VARCHAR(45)  NULL,
    podpis_user_agent  VARCHAR(255) NULL,
    podpis_hash        VARCHAR(64)  NULL COMMENT 'SHA-256 zdarzenia podpisu (user_id|szablon_id|tresc_hash|znacznik czasu|IP|losowa sól) — dowód integralności',
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY ux_uzytk_oswiadcz_user_szablon (user_id, szablon_id),
    KEY ix_uzytk_oswiadcz_user (user_id),
    CONSTRAINT fk_uo_user    FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_uo_szablon FOREIGN KEY (szablon_id) REFERENCES szablony_oswiadczen (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- Wariant SQLite (identyczny model, składnia dostosowana):
-- ============================================================================
--
-- CREATE TABLE IF NOT EXISTS szablony_oswiadczen (
--     id            INTEGER PRIMARY KEY AUTOINCREMENT,
--     kod           VARCHAR(50)  NOT NULL,
--     tytul         VARCHAR(255) NOT NULL,
--     tresc         TEXT         NOT NULL,
--     wersja        INTEGER      NOT NULL DEFAULT 1,
--     aktywny       INTEGER      NOT NULL DEFAULT 1,
--     wymaga_2fa    INTEGER      NOT NULL DEFAULT 1,
--     utworzyl_id   INTEGER,
--     created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     updated_at    DATETIME
-- );
-- CREATE UNIQUE INDEX IF NOT EXISTS ux_szablony_oswiadczen_kod ON szablony_oswiadczen (kod);
--
-- CREATE TABLE IF NOT EXISTS uzytkownik_oswiadczenie (
--     id                 INTEGER PRIMARY KEY AUTOINCREMENT,
--     user_id            INTEGER      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
--     szablon_id         INTEGER      NOT NULL REFERENCES szablony_oswiadczen(id),
--     status             VARCHAR(20)  NOT NULL DEFAULT 'oczekujace',
--     tresc_hash         VARCHAR(64),
--     kod_hash           VARCHAR(255),
--     kod_kanal          VARCHAR(10),
--     kod_wygasa_o       DATETIME,
--     kod_prob           INTEGER      NOT NULL DEFAULT 0,
--     podpisano_at       DATETIME,
--     podpis_ip          VARCHAR(45),
--     podpis_user_agent  VARCHAR(255),
--     podpis_hash        VARCHAR(64),
--     created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
--     updated_at         DATETIME
-- );
-- CREATE UNIQUE INDEX IF NOT EXISTS ux_uzytk_oswiadcz_user_szablon ON uzytkownik_oswiadczenie (user_id, szablon_id);
-- CREATE INDEX IF NOT EXISTS ix_uzytk_oswiadcz_user ON uzytkownik_oswiadczenie (user_id);

-- ============================================================================
-- Przykładowy szablon (opcjonalnie, do testów):
-- ============================================================================
-- INSERT INTO szablony_oswiadczen (kod, tytul, tresc, wersja, aktywny)
-- VALUES (
--     'regulamin_wolontariatu',
--     'Oświadczenie o zapoznaniu się z regulaminem wolontariatu',
--     '<p>Oświadczam, że zapoznałem/-am się z regulaminem wolontariatu organizacji i zobowiązuję się do jego przestrzegania.</p>',
--     1, 1
-- );

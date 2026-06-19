<?php
/**
 * includes/canva.php — Aprowizacja i logowanie do Canva przez SAML SSO (IdP).
 *
 * Model: SZO jest dostawcą tożsamości (SAML IdP). Canva rejestrowana jako
 * Service Provider (preset „canva", entity_id https://www.canva.com).
 * „Zaloguj do Canva" w panelu wolontariusza uruchamia IdP-initiated SSO
 * (/saml/sso.php?sp=<entity>), a konto Canva powstaje Just-In-Time przy
 * pierwszym logowaniu. Dostęp jest bramkowany decyzją administratora
 * (system Zatwierdzeń → canva_access).
 *
 * Jeśli SP Canvy nie jest zarejestrowany lub IdP wyłączony, helpery zwracają
 * null/false — interfejs degraduje się wtedy do logowania „Continue with
 * Microsoft" na canva.com.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/saml_idp.php';

/** Entity ID presetu Canva w katalogu SP. */
const CANVA_SP_ENTITY = 'https://www.canva.com';

/**
 * Zwraca rekord zarejestrowanego, aktywnego SP Canvy lub null.
 * Kolejność: ustawienie canva_saml_sp_entity → preset entity → kolumna preset='canva'.
 */
function canva_saml_sp(): ?array
{
    if (!saml_idp_enabled()) {
        return null;
    }

    $sp = null;

    // 1) Jawnie wskazana encja w ustawieniach (gdy ktoś użył innego entity_id).
    $override = trim(saml_setting('canva_saml_sp_entity', ''));
    if ($override !== '') {
        $sp = saml_sp_by_entity($override);
    }

    // 2) Domyślna encja presetu Canva.
    if (!$sp) {
        $sp = saml_sp_by_entity(CANVA_SP_ENTITY);
    }

    // 3) Dowolny SP oznaczony presetem „canva".
    if (!$sp) {
        try {
            $sp = db_one("SELECT * FROM saml_sp WHERE preset = 'canva' LIMIT 1");
        } catch (\Throwable $e) {
            $sp = null;
        }
    }

    if (!$sp || (int)($sp['is_active'] ?? 0) !== 1) {
        return null;
    }
    return $sp;
}

/** Czy logowanie do Canva przez SSO jest skonfigurowane i aktywne. */
function canva_sso_configured(): bool
{
    return canva_saml_sp() !== null;
}

/**
 * URL IdP-initiated SSO do Canvy (logowanie + aprowizacja JIT) lub null,
 * gdy SSO nie jest skonfigurowane.
 */
function canva_sso_url(): ?string
{
    $sp = canva_saml_sp();
    if (!$sp) {
        return null;
    }
    $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');
    return $base . '/saml/sso.php?sp=' . urlencode((string)$sp['entity_id']);
}

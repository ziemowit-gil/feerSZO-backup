<?php
/**
 * includes/module_switcher.php — zgodność wsteczna: przełącznik modułów w nagłówkach
 * CRM, Zadań, Dydaktyki, Poczty, Katalogu, Wydarzeń i Strategii.
 *
 * Od 2026-09-29 to tylko adapter do wspólnego launchera modules/launcher/
 * (jeden rejestr modułów, uprawnienia per rola z admin/module_perms.php,
 * trzy układy, wyszukiwarka, „Ostatnio używane”). Parametry jak dotąd:
 *   $msw_active — klucz aktywnego modułu (patrz launcherCatalog()),
 *   $msw_dark   — true, gdy pasek nagłówka jest ciemny.
 */
if (!isset($msw_active)) $msw_active = '';
if (!isset($msw_dark))   $msw_dark   = false;
$launcherActive = (string)$msw_active;
$launcherDark   = (bool)$msw_dark;
require dirname(__DIR__) . '/modules/launcher/launcher.php';

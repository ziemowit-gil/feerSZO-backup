<?php
/**
 * karty30/ti/sso.php — strona informacyjna „Czym jest SSO”. Nie wymaga logowania.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Czym jest SSO — Panel TI</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family: "Segoe UI", system-ui, sans-serif; background: #f3f4f6; margin: 0; color: #1d1d1d; }
  .bar { background: #1d1d1d; color: #fff; padding: .75rem 1rem; font-weight: 600; }
  .card-sso { background: #fff; padding: 2rem; max-width: 720px; margin: 2rem auto; box-shadow: 0 2px 10px rgba(0,0,0,.08); }
  .card-sso h1 { font-size: 1.5rem; font-weight: 700; }
  .card-sso h2 { font-size: 1.1rem; font-weight: 700; margin-top: 1.5rem; }
  .card-sso a { color: #1d4ed8; }
  .btn-back { border-radius: 0; background: #1ba1e2; color: #fff; border: 0; padding: .6rem 1.2rem; text-decoration: none; display: inline-block; font-weight: 600; }
  .btn-back:hover { background: #1790c9; color: #fff; }
  .btn-back:focus-visible, .card-sso a:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
</style>
</head>
<body>
<header class="bar">FEER · Panel TI</header>
<main class="card-sso">
  <h1>Czym jest SSO?</h1>
  <p><strong>SSO</strong> (Single Sign-On) to logowanie jednorazowe. Logujesz się raz, a system potwierdza Twoją tożsamość w jednym miejscu. Inne systemy, które z tego korzystają, nie pytają Cię o hasło ponownie.</p>

  <h2>Jak to wygląda w praktyce?</h2>
  <p>Wchodzisz na stronę logowania, wpisujesz dane i wracasz do panelu. Nie musisz pamiętać osobnych haseł do każdego modułu, bo wszystkie korzystają z jednego miejsca logowania.</p>

  <h2>Co jest już w systemie?</h2>
  <p>Logowanie kursantów odbywa się przez jedną, centralną stronę w module Tożsamość. Prowadzący, rodzice i osoby upoważnione mają nadal swoje formularze. Pełne SSO, czyli jedno logowanie do wszystkich systemów, nie jest jeszcze wdrożone.</p>

  <h2>Dlaczego to ma znaczenie?</h2>
  <ul>
    <li>Mniej haseł do zapamiętania.</li>
    <li>Jedno miejsce kontroli bezpieczeństwa i wylogowania.</li>
    <li>Łatwiejsze zarządzanie kontami przez administratorów.</li>
  </ul>

  <h2>Czy moje dane są bezpieczne?</h2>
  <p>Hasło wpisujesz tylko na stronie logowania systemu. Obrazek bezpieczeństwa pomaga sprawdzić, że strona jest prawdziwa. W razie wątpliwości zgłoś to kierownikowi jednostki.</p>

  <p class="mt-4"><a class="btn-back" href="login.php">Wróć do logowania</a></p>
</main>
</body>
</html>

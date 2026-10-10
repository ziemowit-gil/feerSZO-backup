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
<title>Czym jest SSO — Panel kursanta</title>
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
<header class="bar">FEER · System Tożsamości</header>
<main class="card-sso">
  <h1>Czym jest SSO?</h1>
  <p><strong>SSO</strong> (Single Sign-On) to jedno logowanie do wielu systemów. Gdyby takie logowanie działało tutaj, wpisywałbyś dane raz, a kolejne systemy nie pytałyby o hasło ponownie.</p>

  <h2>Co działa teraz?</h2>
  <p>Logowanie kursantów odbywa się przez jedną stronę w module Tożsamość. Każde konto ma swoje hasło, więc nadal logujesz się osobno do każdego systemu. Pełne SSO nie jest jeszcze wdrożone.</p>

  <h2>Jak wygląda logowanie kursanta?</h2>
  <ol>
    <li><strong>Pierwsze logowanie:</strong> wpisujesz login. Kod przychodzi SMS-em na Twój telefon.</li>
    <li><strong>Obrazek bezpieczeństwa:</strong> po wpisaniu loginu pojawia się Twój obrazek. Ma opis tekstowy, który czyta czytnik ekranu.</li>
    <li><strong>Hasło:</strong> po pierwszym logowaniu ustawiasz własne hasło.</li>
  </ol>
  <p>Jeśli nie widzisz swojego obrazka albo widzisz inny, nie wpisuj hasła.</p>

  <h2>Dlaczego to ma znaczenie?</h2>
  <ul>
    <li>Jedno miejsce logowania dla kursantów.</li>
    <li>Obrazek pomaga sprawdzić, że strona jest prawdziwa.</li>
    <li>Administratorzy zarządzają kontami z jednego miejsca.</li>
  </ul>

  <p class="mt-4"><a class="btn-back" href="login.php">Wróć do logowania</a></p>
</main>
</body>
</html>

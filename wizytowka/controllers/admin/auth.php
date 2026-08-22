<?php
/**
 * auth.php (panel) — logowanie, wylogowanie, konto administratora.
 */
declare(strict_types=1);

/** Formularz i obsługa logowania. */
function admin_login(): void
{
    if (is_logged_in()) redirect('/admin');

    $error = '';
    $email = post('email');
    $lock  = login_lock_remaining();

    if (is_post()) {
        csrf_check();
        if ($lock > 0) {
            $error = 'Zbyt wiele nieudanych prób. Spróbuj ponownie za ' . ceil($lock / 60) . ' min.';
        } elseif (attempt_login($email, (string)($_POST['password'] ?? ''))) {
            // Wróć tam, gdzie użytkownik został zatrzymany (tylko adresy panelu)
            $to = (string)($_SESSION['after_login'] ?? '');
            unset($_SESSION['after_login']);
            if ($to !== '' && str_starts_with($to, base_path() . '/admin')) {
                header('Location: ' . $to, true, 302);
                exit;
            }
            redirect('/admin');
        } else {
            $error = 'Nieprawidłowy e-mail lub hasło.';
        }
    }

    require APP_ROOT . '/views/admin/login.php';
}

/** Wylogowanie (POST z panelu, GET jako awaryjne). */
function admin_logout(): void
{
    if (is_post()) csrf_check();
    logout();
    redirect('/admin/login');
}

/** Konto: zmiana hasła i danych logowania. */
function admin_account(): void
{
    $user   = require_admin();
    $errors = [];

    if (is_post()) {
        csrf_check();
        $current = (string)($_POST['current'] ?? '');
        $new     = (string)($_POST['password'] ?? '');
        $new2    = (string)($_POST['password2'] ?? '');
        $email   = post('email', (string)$user['email']);
        $name    = post('name', (string)$user['name']);

        if (!password_verify($current, (string)$user['password_hash'])) {
            $errors[] = 'Aktualne hasło jest nieprawidłowe.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Podaj prawidłowy adres e-mail.';
        }
        if ($new !== '' || $new2 !== '') {
            $errors = array_merge($errors, password_problems($new, $new2));
        }

        if (!$errors) {
            $data = ['email' => $email, 'name' => $name, 'updated_at' => date('Y-m-d H:i:s')];
            if ($new !== '') $data['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
            db_update('users', (int)$user['id'], $data);
            flash('success', 'Dane konta zaktualizowane.');
            redirect('/admin/account');
        }
    }

    admin_render('account', ['page_title' => 'Konto', 'errors' => $errors, 'account' => $user]);
}

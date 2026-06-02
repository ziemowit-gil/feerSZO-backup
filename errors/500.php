<?php
http_response_code(500);
$_app_url = '/';
if (is_file(dirname(__DIR__) . '/config.php')) {
    try { require_once dirname(__DIR__) . '/config.php'; $_app_url = defined('APP_URL') ? APP_URL : '/'; } catch (\Throwable $e) {}
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>500 – Błąd serwera</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: #f0f4ff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .error-card {
            background: #fff;
            border-radius: 1.25rem;
            box-shadow: 0 4px 32px rgba(30, 109, 255, 0.10);
            padding: 3rem 2.5rem;
            max-width: 480px;
            width: 100%;
            text-align: center;
        }
        .error-icon {
            font-size: 4rem;
            color: #e85c3c;
            margin-bottom: 0.5rem;
        }
        .error-code {
            font-size: 5rem;
            font-weight: 800;
            color: #1E6DFF;
            line-height: 1;
            letter-spacing: -2px;
        }
        .error-title {
            font-size: 1.35rem;
            font-weight: 600;
            color: #1a1a2e;
            margin: 0.75rem 0 0.5rem;
        }
        .error-desc {
            color: #6c757d;
            font-size: 0.97rem;
            margin-bottom: 2rem;
        }
        .btn-home {
            background: #1E6DFF;
            color: #fff;
            border: none;
            border-radius: 0.6rem;
            padding: 0.65rem 1.75rem;
            font-size: 0.97rem;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            transition: background 0.2s;
        }
        .btn-home:hover {
            background: #1558d6;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon"><i class="bi bi-exclamation-triangle"></i></div>
        <div class="error-code">500</div>
        <div class="error-title">Błąd serwera</div>
        <p class="error-desc">Coś poszło nie tak. Administrator został powiadomiony.</p>
        <a href="<?= htmlspecialchars($_app_url, ENT_QUOTES, 'UTF-8') ?>" class="btn-home">
            <i class="bi bi-house-door"></i> Wróć do strony głównej
        </a>
    </div>
</body>
</html>

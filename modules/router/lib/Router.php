<?php
/**
 * modules/router/lib/Router.php — router modułów na wzór Laravela (funkcjonalność współdzielona).
 *
 * Moduł ma plik tras modules/{moduł}/router.php:
 *
 *   $r = new Router('/modules/ti/router.php');
 *   $r->get('/nabor/zapisy', [NaborPublicController::class, 'form'])->name('nabor.form');
 *   $r->group(['dyd.staff'], function (Router $r) {
 *       $r->post('/nabor/zgloszenia/{id}', [NaborPanelController::class, 'update'])->name('nabor.update');
 *   });
 *   $r->dispatch();
 *
 * Adresowanie:
 *   'query' (domyślne) — trasa w ?_route=/nabor/zapisy. Na serwerze wszystko z „.php” w adresie to plik, więc adres
 *                        typu router.php/nabor/zapisy (PATH_INFO) nie działa — nie budujemy takich adresów.
 *                        Stare linki router.php/… (wysłane e-maile, iCal) obsługuje alias w .htaccess (→ ?_route=)
 *                        oraz odczyt PATH_INFO, gdy brak ?_route=. Formularz GET do trasy innej niż „/” potrzebuje
 *                        ukrytego pola trasy: <?= route_field('nazwa') ?> (przeglądarka gubi query z action).
 *   'path'             — tylko z $publicBase (czyste adresy z .htaccess, np. /nabor/…, /zlecenie/…, /seeder-test).
 * Parametry {id} trafiają do akcji jako argumenty (cyfry → int). Akcja: [Klasa::class, 'metoda'] albo Closure.
 * Middleware: 'auth' (require_login), 'role:a,b' (require_role), 'guest', oraz własne aliasy (aliasMiddleware).
 * Każdy POST przechodzi przez weryfikację CSRF (domyślnie csrf_check() SZO, pole _csrf; inne przez csrfUsing()),
 * pusty token jest zawsze odrzucany. Wynik akcji: string = HTML, tablica = JSON, null = akcja sama wysłała odpowiedź.
 */
final class Router
{
    /** @var array<int, array{method:string, path:string, regex:string, params:array, action:mixed, middleware:array, name:?string, csrf:bool}> */
    private array $routes = [];
    /** @var array<string, callable> */
    private array $aliases = [];
    private static ?Router $current = null;
    /** @var callable */
    private $csrfCheck;
    /** @var callable */
    private $csrfToken;
    private string $csrfFieldName = '_csrf';
    private string $prefix = '';

    /**
     * $entry — plik wejścia (np. '/modules/ti/router.php'); $mode — 'path' albo 'query';
     * $publicBase — dla czystych adresów z .htaccess (np. '' przy regule ^nabor(/.*)?$): adresy tras budowane jako
     * ścieżki od korzenia bieżącego hosta ('/nabor/zapisy'), bez pliku wejścia i bez APP_URL. null = zwykłe adresy.
     */
    public function __construct(private string $entry, private string $mode = 'query', private ?string $publicBase = null)
    {
        self::$current = $this;
        $this->csrfCheck = fn() => csrf_check();
        $this->csrfToken = fn() => csrf_token();
    }

    public static function current(): ?Router { return self::$current; }

    public function get(string $path, $action): self  { return $this->add('GET', $path, $action); }
    public function post(string $path, $action): self { return $this->add('POST', $path, $action); }

    private function add(string $method, string $path, $action): self
    {
        $path = '/' . trim($this->prefix . '/' . trim($path, '/'), '/');
        preg_match_all('/\{(\w+)\}/', $path, $m);
        $regex = '#^' . preg_replace('/\\\{(\w+)\\\}/', '([^/]+)', preg_quote($path, '#')) . '$#';
        $this->routes[] = ['method' => $method, 'path' => $path, 'regex' => $regex, 'params' => $m[1],
                           'action' => $action, 'middleware' => [], 'name' => null, 'csrf' => true];
        return $this;
    }

    private function &last(): array { return $this->routes[array_key_last($this->routes)]; }

    public function middleware(string ...$mw): self { $r = &$this->last(); array_push($r['middleware'], ...$mw); return $this; }
    public function name(string $name): self        { $r = &$this->last(); $r['name'] = $name; return $this; }
    public function withoutCsrf(): self             { $r = &$this->last(); $r['csrf'] = false; return $this; }

    /** Grupa tras ze wspólnym middleware i prefiksem ścieżki (jak Route::prefix()->middleware()->group()). */
    public function group(array $middleware, callable $define, string $prefix = ''): void
    {
        $from = count($this->routes);
        $keep = $this->prefix;
        $this->prefix = rtrim($keep . '/' . trim($prefix, '/'), '/');
        $define($this);
        $this->prefix = $keep;
        for ($i = $from; $i < count($this->routes); $i++) array_unshift($this->routes[$i]['middleware'], ...$middleware);
    }

    /** Własne middleware (np. sesja panelu dydaktyka): callable bez argumentów albo z argumentem po dwukropku. */
    public function aliasMiddleware(string $name, callable $fn): self { $this->aliases[$name] = $fn; return $this; }

    /** Inna ochrona CSRF (np. dyd_token_check() i pole _token w panelu dydaktyka). */
    public function csrfUsing(callable $check, callable $token, string $field): self
    {
        $this->csrfCheck = $check; $this->csrfToken = $token; $this->csrfFieldName = $field;
        return $this;
    }

    /** Ukryte pole trasy do formularza GET (tryb query; action z ?_route= przeglądarka pomija). */
    public static function routeField(string $name, array $params = []): string
    {
        $r = self::$current;
        if (!$r || $r->mode !== 'query' || $r->publicBase !== null) return '';
        parse_str((string)parse_url($r->route($name, $params), PHP_URL_QUERY), $q);
        $h = '';
        foreach ($q as $k => $v) if (is_string($v)) $h .= '<input type="hidden" name="' . htmlspecialchars($k, ENT_QUOTES) . '" value="' . htmlspecialchars($v, ENT_QUOTES) . '">';
        return $h;
    }

    /** Ukryte pole CSRF bieżącego routera (do formularzy w widokach). */
    public static function csrfField(): string
    {
        $r = self::$current;
        return '<input type="hidden" name="' . htmlspecialchars($r->csrfFieldName, ENT_QUOTES) . '" value="' . htmlspecialchars(($r->csrfToken)(), ENT_QUOTES) . '">';
    }

    public static function csrfToken(): string { return (string)(self::$current->csrfToken)(); }

    /** Adres trasy po nazwie (jak route('nabor.show', ['id' => 5])) — pozostałe parametry idą do query stringu. */
    public function route(string $name, array $params = []): string
    {
        foreach ($this->routes as $r) {
            if ($r['name'] !== $name) continue;
            $path = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$params) {
                $v = $params[$m[1]] ?? '';
                unset($params[$m[1]]);
                return rawurlencode((string)$v);
            }, $r['path']);
            return $this->url($path, $params);
        }
        throw new InvalidArgumentException('Nieznana trasa: ' . $name);
    }

    public function url(string $path = '/', array $query = []): string
    {
        $path = '/' . ltrim($path, '/');
        if ($this->publicBase !== null) {
            return rtrim($this->publicBase, '/') . $path . ($query ? '?' . http_build_query($query) : '');
        }
        $base = rtrim(APP_URL, '/') . $this->entry;
        if ($this->mode === 'query') {
            return $base . '?' . http_build_query(($path === '/' ? [] : ['_route' => $path]) + $query);
        }
        return $base . ($path === '/' ? '' : $path) . ($query ? '?' . http_build_query($query) : '');
    }

    public function currentPath(): string
    {
        // Jawne ?_route= ma pierwszeństwo (tryb query oraz wejścia przez RewriteRule …/router.php?_route=…, gdzie serwer
        // potrafi ustawić PATH_INFO na '' albo na pierwotny adres, np. /nabor); w trybie path poza tym PATH_INFO.
        if (isset($_GET['_route'])) {
            $p = (string)$_GET['_route'];
            // route('x') . '?a=1' daje ?_route=/x?a=1 — reszta po „?” to zwykłe parametry
            if (str_contains($p, '?')) {
                [$p, $rest] = explode('?', $p, 2);
                parse_str($rest, $extra);
                $_GET += $extra; $_REQUEST += $extra;
                $_GET['_route'] = $p;
            }
        } elseif ($this->mode === 'query') {
            // stary adres router.php/… (tam, gdzie serwer go przepuści) — trasa z PATH_INFO
            $pi = trim((string)($_SERVER['PATH_INFO'] ?? ''), '/');
            $p = $pi !== '' && !$this->isShortUrl() ? '/' . $pi : '/';
        } elseif (trim((string)($_SERVER['PATH_INFO'] ?? ''), '/') !== '' && !$this->isShortUrl()) {
            $p = (string)$_SERVER['PATH_INFO'];
        } else {
            // Krótki adres przepisany w .htaccess (np. /nabor → router.php): serwer potrafi zgubić query z reguły
            // i ustawić PATH_INFO na pierwotny adres — wtedy trasą jest sama ścieżka żądania.
            $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
            $p = $this->isShortUrl() ? $uri : '/';
        }
        return '/' . trim($p, '/');
    }

    /** Żądanie przyszło krótkim adresem (ścieżka nie zaczyna się od pliku wejścia routera). */
    private function isShortUrl(): bool
    {
        $uri = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $entryPath = (string)parse_url(rtrim(APP_URL, '/') . $this->entry, PHP_URL_PATH);
        return $uri !== '' && !str_starts_with($uri, $entryPath) && !str_ends_with($uri, '.php');
    }

    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] === 'HEAD' ? 'GET' : $_SERVER['REQUEST_METHOD'];
        $path = $this->currentPath();
        $allowed = [];
        foreach ($this->routes as $r) {
            if (!preg_match($r['regex'], $path, $m)) continue;
            if ($r['method'] !== $method) { $allowed[] = $r['method']; continue; }
            foreach ($r['middleware'] as $mw) $this->runMiddleware($mw);
            if ($method === 'POST' && $r['csrf']) {
                // csrf_check() przepuszcza pusty token, gdy sesja jeszcze go nie ma — pusty zawsze odrzucamy
                if ((string)($_POST[$this->csrfFieldName] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) === '') {
                    http_response_code(403); echo 'Błąd CSRF. Odśwież stronę i spróbuj ponownie.'; return;
                }
                ($this->csrfCheck)();
            }
            $args = [];
            foreach ($r['params'] as $i => $name) $args[] = ctype_digit($m[$i + 1]) ? (int)$m[$i + 1] : rawurldecode($m[$i + 1]);
            $this->send($this->call($r['action'], $args));
            return;
        }
        http_response_code($allowed ? 405 : 404);
        if ($allowed) header('Allow: ' . implode(', ', array_unique($allowed)));
        echo $allowed ? 'Metoda niedozwolona.' : 'Nie znaleziono strony.';
    }

    private function call($action, array $args)
    {
        if ($action instanceof Closure) return $action(...$args);
        [$class, $method] = $action;
        return (new $class())->$method(...$args);
    }

    private function runMiddleware(string $mw): void
    {
        [$name, $arg] = array_pad(explode(':', $mw, 2), 2, '');
        if (isset($this->aliases[$name])) { ($this->aliases[$name])($arg); return; }
        match ($name) {
            'auth'  => require_login(),
            'role'  => require_role(...array_map('trim', explode(',', $arg))),
            'guest' => null,
            default => throw new InvalidArgumentException('Nieznane middleware: ' . $mw),
        };
    }

    private function send($out): void
    {
        if ($out === null) return;
        if (is_array($out)) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($out, JSON_UNESCAPED_UNICODE);
            return;
        }
        echo $out;
    }

    /** Przekierowanie (303 po POST) — zwraca null, żeby akcja mogła napisać `return Router::redirect(...)`. */
    public static function redirect(string $url): ?string
    {
        header('Location: ' . $url, true, 303);
        return null;
    }

    /** Renderuje szablon z katalogu view/ modułu ze zmiennymi (jak view('x', [...])). */
    public static function view(string $file, array $data = []): string
    {
        extract($data, EXTR_SKIP);
        ob_start();
        try { include $file; } catch (\Throwable $e) { ob_end_clean(); throw $e; }
        return (string)ob_get_clean();
    }
}

/** Skrót: adres nazwanej trasy bieżącego routera. */
function route(string $name, array $params = []): string
{
    return Router::current()->route($name, $params);
}

/** Skrót: ukryte pola trasy do formularza GET (<form method="get" action="<?= route('x') ?>"><?= route_field('x') ?>). */
function route_field(string $name, array $params = []): string
{
    return Router::routeField($name, $params);
}

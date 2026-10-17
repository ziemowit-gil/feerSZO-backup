<?php
/**
 * modules/router/lib/Router.php — router modułów na wzór Laravela (funkcjonalność współdzielona).
 *
 * Moduł ma jeden plik wejścia modules/{nazwa}/router.php:
 *
 *   $r = new Router('/modules/zajecia_pozalekcyjne/router.php');
 *   $r->get('/', [ZpAdminController::class, 'index'])->name('zp.index')->middleware('role:admin,editor');
 *   $r->post('/zgloszenia/{id}', [ZpAdminController::class, 'update'])->middleware('role:admin,editor');
 *   $r->dispatch();
 *
 * Adres trasy = plik wejścia + ścieżka w PATH_INFO: /modules/x/router.php/zgloszenia/12 (działa bez
 * mod_rewrite; awaryjnie ?_route=/zgloszenia/12). Parametry {id} trafiają do akcji jako argumenty
 * (liczby całkowite rzutowane na int). Akcja: [Klasa::class, 'metoda'] (nowa instancja) albo Closure.
 * Middleware: 'auth' (require_login), 'role:a,b' (require_role), 'guest' (bez wymagań).
 * Każdy POST przechodzi przez csrf_check() (jak VerifyCsrfToken), chyba że trasa ma ->withoutCsrf().
 * Wynik akcji: string = HTML, tablica = JSON, null = akcja sama wysłała odpowiedź.
 */
final class Router
{
    /** @var array<int, array{method:string, path:string, regex:string, params:array, action:mixed, middleware:array, name:?string, csrf:bool}> */
    private array $routes = [];
    private static ?Router $current = null;

    public function __construct(private string $entry)
    {
        self::$current = $this;
    }

    public static function current(): ?Router { return self::$current; }

    public function get(string $path, $action): self  { return $this->add('GET', $path, $action); }
    public function post(string $path, $action): self { return $this->add('POST', $path, $action); }

    private function add(string $method, string $path, $action): self
    {
        $path = '/' . trim($path, '/');
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

    /** Grupa tras ze wspólnym middleware (jak Route::middleware(...)->group()). */
    public function group(array $middleware, callable $define): void
    {
        $from = count($this->routes);
        $define($this);
        for ($i = $from; $i < count($this->routes); $i++) array_unshift($this->routes[$i]['middleware'], ...$middleware);
    }

    /** Adres trasy po nazwie (jak route('zp.show', ['id' => 5])) — pozostałe parametry idą do query stringu. */
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
        return rtrim(APP_URL, '/') . $this->entry . ($path === '/' ? '' : $path) . ($query ? '?' . http_build_query($query) : '');
    }

    public function currentPath(): string
    {
        $p = (string)($_SERVER['PATH_INFO'] ?? ($_GET['_route'] ?? '/'));
        return '/' . trim($p, '/');
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
                if ((string)($_POST['_csrf'] ?? '') === '') { http_response_code(403); echo 'Błąd CSRF. Odśwież stronę i spróbuj ponownie.'; return; }
                csrf_check();
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

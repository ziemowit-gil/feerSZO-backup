package pl.feer.exam.http;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

import com.sun.net.httpserver.Headers;
import com.sun.net.httpserver.HttpExchange;
import com.sun.net.httpserver.HttpServer;

import pl.feer.exam.ExamGenerator;
import pl.feer.exam.Grader;
import pl.feer.exam.Json;
import pl.feer.exam.authoring.ExamAuthoring;
import pl.feer.exam.authoring.ExamStats;
import pl.feer.exam.authoring.ManualGrading;
import pl.feer.exam.authoring.QuestionAuthoring;
import pl.feer.exam.model.Answer;
import pl.feer.exam.model.GradingContext;
import pl.feer.exam.model.Question;
import pl.feer.exam.model.QuestionResult;
import pl.feer.exam.sandbox.Language;
import pl.feer.exam.sandbox.RunResult;
import pl.feer.exam.sandbox.Sandbox;

/**
 * Warstwa HTTP silnika egzaminów — wbudowany serwer z JDK, bez frameworka.
 *
 * Punkty wejścia:
 *   GET  /health                  — stan silnika i lista dostępnych języków,
 *   GET  /api/authoring/catalogue — słownik typów pytań, formuł i języków,
 *   POST /api/authoring/question  — walidacja i budowa definicji pytania,
 *   POST /api/authoring/exam      — walidacja i normalizacja ustawień egzaminu,
 *   POST /api/exam/generate       — złożenie wariantu testu dla podejścia,
 *   POST /api/exam/grade          — ocena kompletu odpowiedzi,
 *   POST /api/exam/manual-grade   — przeliczenie po ocenie ręcznej prowadzącego,
 *   POST /api/exam/stats          — statystyka zestawu i pytań,
 *   POST /api/code/run            — uruchomienie kodu (podgląd w trybie treningowym).
 *
 * Uwierzytelnienie: nagłówek `X-Exam-Token` musi zgadzać się ze zmienną
 * środowiskową EXAM_ENGINE_TOKEN. Gdy zmiennej nie ma, silnik startuje
 * w trybie otwartym i wypisuje ostrzeżenie — dopuszczalne tylko lokalnie,
 * bo w produkcji usługa i tak stoi w sieci wewnętrznej Dockera.
 */
public final class ExamServer {

    private static final int MAX_BODY = 8 * 1024 * 1024;

    private final int      port;
    private final String   token;
    private final Sandbox  sandbox;
    private final Grader   grader;
    private HttpServer     server;
    private ExecutorService pool;

    public ExamServer(int port, String token, Sandbox sandbox) {
        this.port    = port;
        this.token   = token == null ? "" : token;
        this.sandbox = sandbox;
        this.grader  = new Grader(sandbox);
    }

    public void start() throws IOException {
        int workers = Math.max(4, Runtime.getRuntime().availableProcessors() * 2);
        String w = System.getenv("EXAM_WORKERS");
        if (w != null && !w.trim().isEmpty()) {
            try { workers = Math.max(1, Integer.parseInt(w.trim())); } catch (NumberFormatException e) { /* domyślne */ }
        }
        pool   = Executors.newFixedThreadPool(workers);
        server = HttpServer.create(new InetSocketAddress(port), 64);
        server.setExecutor(pool);

        server.createContext("/health",                  this::health);
        server.createContext("/api/authoring/catalogue", this::catalogue);
        server.createContext("/api/authoring/question",  ex -> guarded(ex, QuestionAuthoring::build));
        server.createContext("/api/authoring/exam",      ex -> guarded(ex, ExamAuthoring::normalize));
        server.createContext("/api/exam/generate",       ex -> guarded(ex, this::generate));
        server.createContext("/api/exam/grade",          ex -> guarded(ex, this::gradeAttempt));
        server.createContext("/api/exam/manual-grade",   ex -> guarded(ex, ManualGrading::apply));
        server.createContext("/api/exam/stats",          ex -> guarded(ex, ExamStats::compute));
        server.createContext("/api/code/run",            ex -> guarded(ex, this::runCode));
        server.createContext("/",                        ex -> send(ex, 404, Json.obj("error", "Nieznany zasób.")));

        server.start();
        System.out.println("[exam-engine] nasłuchuję na porcie " + port + ", wątków: " + workers);
        if (this.token.isEmpty()) {
            System.out.println("[exam-engine] UWAGA: EXAM_ENGINE_TOKEN nie ustawiony — brak uwierzytelnienia wywołań.");
        }
    }

    public void stop() {
        if (server != null) server.stop(1);
        if (pool != null) pool.shutdownNow();
    }

    // ── Zasoby ────────────────────────────────────────────────────────────────

    private void health(HttpExchange ex) throws IOException {
        List<Object> langs = new ArrayList<Object>();
        for (Object o : Language.describeAll()) {
            Map<String, Object> m = Json.asMap(o);
            Language l = Language.byId(Json.str(m, "id", ""));
            m.put("available", Boolean.valueOf(sandbox.available(l)));
            langs.add(m);
        }
        send(ex, 200, Json.obj(
            "status",    "ok",
            "service",   "feer-exam-engine",
            "version",   Version.VALUE,
            "java",      System.getProperty("java.version"),
            "authRequired", Boolean.valueOf(!token.isEmpty()),
            "languages", langs));
    }

    /**
     * Słownik dla panelu prowadzącego: typy pytań, formuły testów, języki
     * i tryby porównywania wyjścia. Jedno źródło etykiet i możliwości —
     * interfejs PHP nie musi utrzymywać własnej listy typów.
     */
    private void catalogue(HttpExchange ex) throws IOException {
        if (!authorized(ex)) { send(ex, 401, Json.obj("error", "Brak lub zły nagłówek X-Exam-Token.")); return; }
        List<Object> langs = new ArrayList<Object>();
        for (Object o : Language.describeAll()) {
            Map<String, Object> m = Json.asMap(o);
            m.put("available", Boolean.valueOf(sandbox.available(Language.byId(Json.str(m, "id", "")))));
            langs.add(m);
        }
        send(ex, 200, Json.obj(
            "questionTypes", QuestionAuthoring.catalogue(),
            "modes",         ExamAuthoring.modes(),
            "languages",     langs,
            "matchModes",    Json.obj(
                "trim",    "Pomijaj białe znaki na końcach linii (zalecane)",
                "exact",   "Dosłownie, znak w znak",
                "tokens",  "Ciąg tokenów rozdzielonych białymi znakami",
                "numeric", "Jak tokeny, liczby z tolerancją",
                "regex",   "Oczekiwane wyjście jest wyrażeniem regularnym"),
            "negMarking",    Json.obj(
                "partial",        "Cząstkowa z karą — (trafione − błędne) / poprawnych, nie mniej niż 0",
                "none",           "Cząstkowa bez kary — trafione / poprawnych",
                "all_or_nothing", "Wszystko albo nic — punkty tylko za komplet")));
    }

    private Map<String, Object> generate(Map<String, Object> body) {
        return ExamGenerator.generate(body);
    }

    private Map<String, Object> gradeAttempt(Map<String, Object> body) {
        return grader.grade(body);
    }

    /**
     * Uruchomienie kodu bez oceniania (przycisk „Uruchom" w trybie treningowym).
     * Z przypadkami testowymi zwraca ich wyniki, bez nich — pojedynczy przebieg
     * na podanym wejściu standardowym.
     */
    private Map<String, Object> runCode(Map<String, Object> body) {
        String langId = Json.str(body, "language", "python");
        String source = Json.str(body, "source", Json.str(body, "code", ""));
        int timeoutMs = Json.integer(body, "timeLimitMs", 3000);
        int memoryMb  = Json.integer(body, "memoryMb", 128);
        List<Object> cases = Json.list(body, "cases");

        if (cases.isEmpty()) {
            Language lang = Language.byId(langId);
            if (lang == null) return Json.obj("error", "Nieobsługiwany język: " + langId);
            if (!sandbox.available(lang)) {
                return Json.obj("error", "W obrazie silnika brakuje narzędzi dla języka: " + lang.label);
            }
            RunResult rr = sandbox.run(lang, source, Json.str(body, "stdin", ""), timeoutMs, memoryMb);
            Map<String, Object> out = new LinkedHashMap<String, Object>(rr.toJson());
            out.put("language", langId);
            return out;
        }

        Map<String, Object> qm = new LinkedHashMap<String, Object>();
        qm.put("id",     Long.valueOf(0));
        qm.put("type",   Question.T_CODE_RUN);
        qm.put("points", Double.valueOf(1));
        qm.put("config", Json.obj("language", langId,
                                  "timeLimitMs", Integer.valueOf(timeoutMs),
                                  "memoryMb",    Integer.valueOf(memoryMb),
                                  "forbidden",   Json.list(body, "forbidden"),
                                  "required",    Json.list(body, "required")));
        qm.put("cases",  cases);

        Question q = Question.fromJson(qm);
        GradingContext ctx = new GradingContext(GradingContext.MODE_TRAINING,
                                                GradingContext.NEG_PARTIAL,
                                                Json.bool(body, "revealAnswers", false),
                                                sandbox);
        QuestionResult r = q.grade(new Answer(Json.obj("code", source)), ctx);
        Map<String, Object> out = new LinkedHashMap<String, Object>(r.toJson());
        out.put("language", langId);
        return out;
    }

    // ── Infrastruktura ────────────────────────────────────────────────────────

    private interface Handler {
        Map<String, Object> handle(Map<String, Object> body);
    }

    private void guarded(HttpExchange ex, Handler h) throws IOException {
        try {
            if (!"POST".equalsIgnoreCase(ex.getRequestMethod())) {
                send(ex, 405, Json.obj("error", "Dozwolona wyłącznie metoda POST."));
                return;
            }
            if (!authorized(ex)) {
                send(ex, 401, Json.obj("error", "Brak lub zły nagłówek X-Exam-Token."));
                return;
            }
            byte[] raw = readBody(ex);
            if (raw == null) {
                send(ex, 413, Json.obj("error", "Żądanie przekracza dopuszczalny rozmiar."));
                return;
            }
            Map<String, Object> body;
            try {
                body = Json.asMap(Json.parse(new String(raw, StandardCharsets.UTF_8)));
            } catch (RuntimeException e) {
                send(ex, 400, Json.obj("error", "Nieprawidłowy JSON: " + e.getMessage()));
                return;
            }
            send(ex, 200, h.handle(body));
        } catch (RuntimeException e) {
            send(ex, 500, Json.obj("error", e.getClass().getSimpleName()
                                            + (e.getMessage() == null ? "" : ": " + e.getMessage())));
        }
    }

    private boolean authorized(HttpExchange ex) {
        if (token.isEmpty()) return true;
        String given = ex.getRequestHeaders().getFirst("X-Exam-Token");
        if (given == null) return false;
        byte[] a = given.getBytes(StandardCharsets.UTF_8);
        byte[] b = token.getBytes(StandardCharsets.UTF_8);
        return java.security.MessageDigest.isEqual(a, b);
    }

    private byte[] readBody(HttpExchange ex) throws IOException {
        java.io.ByteArrayOutputStream buf = new java.io.ByteArrayOutputStream();
        byte[] chunk = new byte[8192];
        try (InputStream is = ex.getRequestBody()) {
            int n;
            while ((n = is.read(chunk)) > 0) {
                if (buf.size() + n > MAX_BODY) return null;
                buf.write(chunk, 0, n);
            }
        }
        return buf.toByteArray();
    }

    private void send(HttpExchange ex, int status, Map<String, Object> payload) throws IOException {
        byte[] out = Json.write(payload).getBytes(StandardCharsets.UTF_8);
        Headers h = ex.getResponseHeaders();
        h.set("Content-Type", "application/json; charset=utf-8");
        h.set("Cache-Control", "no-store");
        h.set("X-Content-Type-Options", "nosniff");
        ex.sendResponseHeaders(status, out.length);
        try (OutputStream os = ex.getResponseBody()) { os.write(out); }
    }
}

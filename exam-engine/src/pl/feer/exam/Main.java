package pl.feer.exam;

import java.io.IOException;

import pl.feer.exam.http.ExamServer;
import pl.feer.exam.sandbox.Sandbox;

/**
 * Punkt wejścia silnika egzaminów FEER SZO (moduł TI).
 *
 * Zmienne środowiskowe:
 *   EXAM_PORT              — port nasłuchu (domyślnie 8090),
 *   EXAM_ENGINE_TOKEN      — wspólny sekret dla wywołań z aplikacji PHP,
 *   EXAM_SANDBOX_DIR       — katalog roboczy piaskownicy (domyślnie /tmp),
 *   EXAM_SANDBOX_UNSHARE   — "1" włącza `unshare -n` (wymaga CAP_SYS_ADMIN),
 *   EXAM_MAX_OUTPUT        — limit znaków przechwytywanego wyjścia,
 *   EXAM_MAX_SOURCE        — limit długości kodu kursanta,
 *   EXAM_MAX_TIMEOUT_MS    — górny limit czasu jednego uruchomienia,
 *   EXAM_WORKERS           — liczba wątków obsługi HTTP.
 */
public final class Main {

    public static void main(String[] args) throws IOException {
        int port = 8090;
        String p = System.getenv("EXAM_PORT");
        if (p != null && !p.trim().isEmpty()) {
            try { port = Integer.parseInt(p.trim()); } catch (NumberFormatException e) { /* domyślny */ }
        }
        for (int i = 0; i < args.length - 1; i++) {
            if ("--port".equals(args[i])) {
                try { port = Integer.parseInt(args[i + 1]); } catch (NumberFormatException e) { /* domyślny */ }
            }
        }

        Sandbox sandbox = Sandbox.fromEnv();
        final ExamServer server = new ExamServer(port, System.getenv("EXAM_ENGINE_TOKEN"), sandbox);
        server.start();
        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            System.out.println("[exam-engine] zatrzymuję…");
            server.stop();
        }));
    }
}

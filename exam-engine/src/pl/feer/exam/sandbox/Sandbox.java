package pl.feer.exam.sandbox;

import java.io.File;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.Reader;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.Paths;
import java.util.ArrayList;
import java.util.Comparator;
import java.util.List;
import java.util.Map;
import java.util.concurrent.Executors;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Future;
import java.util.concurrent.TimeUnit;
import java.util.stream.Stream;

/**
 * Piaskownica uruchamiania kodu kursanta.
 *
 * Warstwy ograniczeń (od najbliższej do najdalszej):
 *   1. proces  — `ulimit -t` (czas CPU), `ulimit -f` (rozmiar pliku),
 *                `ulimit -v` (pamięć wirtualna, poza JVM/Node), umask 077,
 *                twardy limit czasu ściennego pilnowany przez silnik,
 *                obcinanie strumieni wyjścia po ustalonej liczbie znaków;
 *   2. katalog — świeży katalog roboczy per uruchomienie, kasowany po wszystkim,
 *                brak dostępu do kodu aplikacji (kontener silnika nie montuje repo);
 *   3. konto   — silnik działa jako użytkownik bez uprawnień (patrz Dockerfile),
 *                więc proces potomny dziedziczy to konto;
 *   4. kontener— limity CPU/RAM/PIDs w compose i sieć wewnętrzna bez wyjścia
 *                na świat; opcjonalnie `unshare -n` odcina sieć całkiem
 *                (wymaga uprawnienia SYS_ADMIN — patrz EXAM_SANDBOX_UNSHARE).
 *
 * Klasa jest bezstanowa względem zadania — nadaje się do współbieżnych wywołań.
 */
public final class Sandbox {

    private static final String RUNNER = String.join("\n",
        "#!/bin/sh",
        "# Runner piaskownicy — ustawia limity zasobów i oddaje sterowanie programowi kursanta.",
        "ulimit -t \"${EXAM_CPU:-10}\"   2>/dev/null || true",
        "ulimit -f \"${EXAM_FSIZE:-8192}\" 2>/dev/null || true",
        "if [ -n \"${EXAM_AS:-}\" ]; then ulimit -v \"$EXAM_AS\" 2>/dev/null || true; fi",
        "umask 077",
        "exec \"$@\"",
        "");

    private final Path    root;
    private final boolean unshareNet;
    private final int     maxOutputChars;
    private final int     maxSourceChars;
    private final int     maxTimeoutMs;
    private final ExecutorService pumps;

    public Sandbox(Path root, boolean unshareNet, int maxOutputChars, int maxSourceChars, int maxTimeoutMs) {
        this.root           = root;
        this.unshareNet     = unshareNet;
        this.maxOutputChars = maxOutputChars > 0 ? maxOutputChars : 64 * 1024;
        this.maxSourceChars = maxSourceChars > 0 ? maxSourceChars : 200 * 1024;
        this.maxTimeoutMs   = maxTimeoutMs > 0 ? maxTimeoutMs : 15000;
        this.pumps = Executors.newCachedThreadPool(r -> {
            Thread t = new Thread(r, "exam-sandbox-pump");
            t.setDaemon(true);
            return t;
        });
    }

    public static Sandbox fromEnv() {
        String dir = System.getenv("EXAM_SANDBOX_DIR");
        Path p = Paths.get(dir == null || dir.isEmpty() ? System.getProperty("java.io.tmpdir", "/tmp") : dir);
        boolean unshare = "1".equals(System.getenv("EXAM_SANDBOX_UNSHARE"));
        return new Sandbox(p, unshare,
            envInt("EXAM_MAX_OUTPUT", 64 * 1024),
            envInt("EXAM_MAX_SOURCE", 200 * 1024),
            envInt("EXAM_MAX_TIMEOUT_MS", 15000));
    }

    private static int envInt(String key, int def) {
        String v = System.getenv(key);
        if (v == null || v.trim().isEmpty()) return def;
        try { return Integer.parseInt(v.trim()); } catch (NumberFormatException e) { return def; }
    }

    /** Czy dany język ma w obrazie działający interpreter/kompilator. */
    public boolean available(Language lang) {
        if (lang == null) return false;
        // Dla języków kompilowanych o dostępności decyduje kompilator (program
        // wynikowy `./solution` powstaje dopiero w katalogu roboczym).
        String bin = (lang.compileArgs != null) ? lang.compileArgs.get(0) : lang.runArgs.get(0);
        return which(bin) != null;
    }

    private static String which(String bin) {
        if (bin == null || bin.isEmpty() || bin.startsWith("./") || bin.startsWith("/")) return bin;
        String path = System.getenv("PATH");
        if (path == null) path = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin";
        for (String dir : path.split(File.pathSeparator)) {
            File f = new File(dir, bin);
            if (f.isFile() && f.canExecute()) return f.getAbsolutePath();
        }
        return null;
    }

    /**
     * Uruchamia kod kursanta na jednym wejściu.
     *
     * @param timeoutMs limit czasu ściennego pojedynczego uruchomienia (przycinany do EXAM_MAX_TIMEOUT_MS)
     * @param memoryMb  limit pamięci procesu
     */
    public RunResult run(Language lang, String source, String stdin, int timeoutMs, int memoryMb) {
        if (lang == null) return RunResult.engineFailure("Nieznany język zadania.");
        if (source == null) source = "";
        if (source.length() > maxSourceChars) {
            return RunResult.engineFailure("Kod przekracza dopuszczalny rozmiar (" + maxSourceChars + " znaków).");
        }
        int wall = Math.min(timeoutMs > 0 ? timeoutMs : 5000, maxTimeoutMs);
        int mem  = memoryMb > 0 ? memoryMb : 128;

        Path dir = null;
        try {
            dir = Files.createTempDirectory(root, "exam-");
            Path runner = dir.resolve("runner.sh");
            Files.write(runner, RUNNER.getBytes(StandardCharsets.UTF_8));
            runner.toFile().setExecutable(true, true);

            Path src = dir.resolve(lang.sourceFileName(source));
            Files.write(src, source.getBytes(StandardCharsets.UTF_8));

            Path in = dir.resolve("stdin.txt");
            Files.write(in, (stdin == null ? "" : stdin).getBytes(StandardCharsets.UTF_8));

            RunResult result = new RunResult();

            // ── Kompilacja (jeśli język tego wymaga) ─────────────────────────
            if (lang.compileArgs != null) {
                List<String> cc = new ArrayList<String>();
                cc.add("/bin/sh");
                cc.add(runner.toString());
                cc.addAll(lang.resolve(lang.compileArgs, source, mem));
                Exec ce = exec(cc, dir, null, Math.max(wall, 10000), mem, false);
                if (!ce.engineError.isEmpty()) return RunResult.engineFailure(ce.engineError);
                if (ce.timedOut || ce.exitCode != 0) {
                    result.started      = true;
                    result.compiled     = false;
                    result.compileError = cap(ce.timedOut
                        ? "Kompilacja przekroczyła limit czasu."
                        : (ce.stderr.isEmpty() ? ce.stdout : ce.stderr));
                    result.durationMs   = ce.durationMs;
                    return result;
                }
            }

            // ── Uruchomienie ────────────────────────────────────────────────
            List<String> rc = new ArrayList<String>();
            if (unshareNet && which("unshare") != null) {
                rc.add("unshare");
                rc.add("-n");
                rc.add("--");
            }
            rc.add("/bin/sh");
            rc.add(runner.toString());
            rc.addAll(lang.resolve(lang.runArgs, source, mem));

            Exec re = exec(rc, dir, in, wall, mem, lang.limitVirtualMemory);
            if (!re.engineError.isEmpty()) return RunResult.engineFailure(re.engineError);

            result.started    = true;
            result.stdout     = cap(re.stdout);
            result.stderr     = cap(re.stderr);
            result.exitCode   = re.exitCode;
            result.timedOut   = re.timedOut;
            result.durationMs = re.durationMs;
            return result;

        } catch (IOException e) {
            return RunResult.engineFailure("Piaskownica: " + e.getMessage());
        } finally {
            deleteTree(dir);
        }
    }

    private String cap(String s) {
        if (s == null) return "";
        if (s.length() <= maxOutputChars) return s;
        return s.substring(0, maxOutputChars) + "\n… (obcięto — wyjście przekroczyło " + maxOutputChars + " znaków)";
    }

    // ── Uruchamianie procesu z twardym limitem czasu ──────────────────────────

    private static final class Exec {
        String stdout = "";
        String stderr = "";
        int    exitCode = -1;
        boolean timedOut;
        long   durationMs;
        String engineError = "";
    }

    private Exec exec(List<String> cmd, Path dir, Path stdinFile, int wallMs, int memoryMb, boolean limitAs) {
        Exec out = new Exec();
        long t0 = System.nanoTime();
        Process p = null;
        try {
            ProcessBuilder pb = new ProcessBuilder(cmd);
            pb.directory(dir.toFile());
            if (stdinFile != null) pb.redirectInput(stdinFile.toFile());
            else pb.redirectInput(ProcessBuilder.Redirect.from(new File("/dev/null")));

            Map<String, String> env = pb.environment();
            env.clear();                                   // czyste środowisko — bez sekretów aplikacji
            env.put("PATH",  "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin");
            env.put("HOME",  dir.toString());
            env.put("TMPDIR", dir.toString());
            env.put("LANG",  "C.UTF-8");
            env.put("LC_ALL", "C.UTF-8");
            env.put("EXAM_CPU",   Integer.toString(Math.max(1, wallMs / 1000 + 1)));
            env.put("EXAM_FSIZE", "8192");
            if (limitAs) env.put("EXAM_AS", Integer.toString(memoryMb * 1024));

            p = pb.start();
            Future<String> fo = pumps.submit(reader(p.getInputStream()));
            Future<String> fe = pumps.submit(reader(p.getErrorStream()));

            boolean done = p.waitFor(wallMs, TimeUnit.MILLISECONDS);
            if (!done) {
                out.timedOut = true;
                killTree(p);
                p.waitFor(2000, TimeUnit.MILLISECONDS);
            }
            out.exitCode = p.isAlive() ? -1 : p.exitValue();
            out.stdout   = get(fo);
            out.stderr   = get(fe);

        } catch (IOException e) {
            out.engineError = "Nie udało się uruchomić procesu (" + cmd.get(0) + "): " + e.getMessage();
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            out.engineError = "Przerwano uruchomienie kodu.";
            if (p != null) killTree(p);
        } finally {
            out.durationMs = (System.nanoTime() - t0) / 1_000_000L;
        }
        return out;
    }

    private java.util.concurrent.Callable<String> reader(final InputStream is) {
        final int limit = maxOutputChars + 1024;
        return () -> {
            StringBuilder b = new StringBuilder();
            char[] buf = new char[4096];
            try (Reader r = new InputStreamReader(is, StandardCharsets.UTF_8)) {
                int n;
                while ((n = r.read(buf)) >= 0) {
                    if (b.length() < limit) b.append(buf, 0, Math.min(n, limit - b.length()));
                    // Czytamy do końca nawet po przekroczeniu limitu — inaczej proces zablokuje się na zapisie.
                }
            } catch (IOException e) {
                // Strumień zamknięty przy ubijaniu procesu — to normalne przy przekroczeniu czasu.
            }
            return b.toString();
        };
    }

    private static String get(Future<String> f) {
        try { return f.get(3, TimeUnit.SECONDS); }
        catch (Exception e) { return ""; }
    }

    private static void killTree(Process p) {
        try { p.descendants().forEach(ProcessHandle::destroyForcibly); } catch (RuntimeException e) { /* nic */ }
        p.destroyForcibly();
    }

    private static void deleteTree(Path dir) {
        if (dir == null) return;
        try (Stream<Path> walk = Files.walk(dir)) {
            walk.sorted(Comparator.reverseOrder()).forEach(p -> {
                try { Files.deleteIfExists(p); } catch (IOException e) { /* sprzątanie best-effort */ }
            });
        } catch (IOException e) {
            // Katalog i tak leży w tmpfs kontenera — nieudane sprzątanie nie jest błędem krytycznym.
        }
    }
}

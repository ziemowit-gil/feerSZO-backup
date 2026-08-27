package pl.feer.exam.sandbox;

import java.util.LinkedHashMap;
import java.util.Map;

/** Wynik jednego uruchomienia kodu kursanta w piaskownicy. */
public final class RunResult {

    public boolean started;
    public boolean compiled = true;
    public String  compileError = "";
    public String  stdout   = "";
    public String  stderr   = "";
    public int     exitCode = -1;
    public boolean timedOut;
    public long    durationMs;
    /** Błąd infrastruktury (brak interpretera, awaria piaskownicy) — nie wina kursanta. */
    public String  engineError = "";

    public boolean ok() {
        return started && compiled && !timedOut && exitCode == 0 && engineError.isEmpty();
    }

    public static RunResult engineFailure(String message) {
        RunResult r = new RunResult();
        r.engineError = message == null ? "Nieznany błąd piaskownicy." : message;
        return r;
    }

    public Map<String, Object> toJson() {
        Map<String, Object> m = new LinkedHashMap<String, Object>();
        m.put("started",      Boolean.valueOf(started));
        m.put("compiled",     Boolean.valueOf(compiled));
        m.put("compileError", compileError);
        m.put("stdout",       stdout);
        m.put("stderr",       stderr);
        m.put("exitCode",     Integer.valueOf(exitCode));
        m.put("timedOut",     Boolean.valueOf(timedOut));
        m.put("durationMs",   Long.valueOf(durationMs));
        m.put("engineError",  engineError);
        return m;
    }
}

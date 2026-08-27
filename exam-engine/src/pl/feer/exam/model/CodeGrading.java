package pl.feer.exam.model;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Pattern;
import java.util.regex.PatternSyntaxException;

import pl.feer.exam.Json;
import pl.feer.exam.sandbox.Language;
import pl.feer.exam.sandbox.RunResult;
import pl.feer.exam.sandbox.Sandbox;

/**
 * Wspólna maszyneria oceny zadań, w których kod kursanta jest uruchamiany
 * na zestawach danych wejście/wyjście. Używana przez zadania programistyczne
 * (`code_run`), poprawianie kodu w trybie przepisania (`code_fix`) oraz
 * uzupełnianie kodu z weryfikacją uruchomieniową (`code_completion`).
 */
public final class CodeGrading {

    private CodeGrading() { }

    /** Zbiorczy wynik przebiegu wszystkich przypadków testowych. */
    public static final class Outcome {
        public double  fraction;          // 0..1 — ważony udział zdanych przypadków
        public boolean compiled = true;
        public String  compileError = "";
        public String  engineError  = "";
        public boolean blocked;           // kod odrzucony przez regułę treści
        public String  blockReason = "";
        public final List<Object> rows = new ArrayList<Object>();
        public int passed;
        public int total;
    }

    public static Outcome runCases(Question q, String languageId, String code,
                                   List<TestCase> cases, GradingContext ctx) {
        Outcome out = new Outcome();
        out.total = cases.size();

        if (code == null || code.trim().isEmpty()) {
            out.engineError = "";
            out.blocked     = true;
            out.blockReason = "Nie przesłano kodu.";
            return out;
        }

        // Reguły treści kodu — np. zakaz sztywnego wypisania oczekiwanego wyniku
        // albo wymóg użycia konkretnej konstrukcji językowej.
        String block = checkPatterns(code, Json.strings(q.config, "forbidden"), true);
        if (block == null) block = checkPatterns(code, Json.strings(q.config, "required"), false);
        if (block != null) {
            out.blocked     = true;
            out.blockReason = block;
            return out;
        }

        if (ctx.sandbox == null) {
            out.engineError = "Piaskownica jest niedostępna — zadanie wymaga ręcznej oceny.";
            return out;
        }
        Language lang = Language.byId(languageId);
        if (lang == null) {
            out.engineError = "Nieobsługiwany język zadania: " + languageId;
            return out;
        }
        if (!ctx.sandbox.available(lang)) {
            out.engineError = "W obrazie silnika brakuje narzędzi dla języka: " + lang.label;
            return out;
        }
        if (cases.isEmpty()) {
            out.engineError = "Zadanie nie ma przypadków testowych — wymaga oceny prowadzącego.";
            return out;
        }

        int timeoutMs = Json.integer(q.config, "timeLimitMs", 3000);
        int memoryMb  = Json.integer(q.config, "memoryMb", 128);

        double gained = 0, totalWeight = 0;
        for (TestCase tc : cases) {
            totalWeight += tc.weight;
            RunResult rr = ctx.sandbox.run(lang, code, tc.stdin, timeoutMs, memoryMb);

            if (!rr.engineError.isEmpty()) {
                out.engineError = rr.engineError;
                return out;
            }
            if (!rr.compiled) {
                out.compiled     = false;
                out.compileError = rr.compileError;
                out.fraction     = 0;
                out.rows.clear();
                return out;
            }

            boolean passed = rr.exitCode == 0 && !rr.timedOut && Text.outputMatches(rr.stdout, tc);
            if (passed) { gained += tc.weight; out.passed++; }

            Map<String, Object> row = new LinkedHashMap<String, Object>();
            row.put("index",    Integer.valueOf(tc.index));
            row.put("name",     tc.name);
            row.put("passed",   Boolean.valueOf(passed));
            row.put("hidden",   Boolean.valueOf(tc.hidden));
            row.put("timedOut", Boolean.valueOf(rr.timedOut));
            row.put("exitCode", Integer.valueOf(rr.exitCode));
            row.put("timeMs",   Long.valueOf(rr.durationMs));
            // Treść wejścia/wyjścia pokazujemy tylko dla przypadków jawnych —
            // ukryte służą do wykrywania rozwiązań „pod testy" i pozostają tajne.
            if (!tc.hidden) {
                row.put("stdin",    Text.cap(tc.stdin, 2000));
                row.put("stdout",   Text.cap(rr.stdout, 4000));
                row.put("stderr",   Text.cap(rr.stderr, 2000));
                if (ctx.revealAnswers) row.put("expected", Text.cap(tc.expected, 4000));
            }
            out.rows.add(row);
        }

        out.fraction = totalWeight > 0 ? gained / totalWeight : 0;
        return out;
    }

    /**
     * Sprawdza wzorce treści kodu.
     * @param mustNotMatch true = lista wzorców zakazanych, false = lista wymaganych
     * @return komunikat o naruszeniu albo null gdy w porządku
     */
    private static String checkPatterns(String code, List<String> patterns, boolean mustNotMatch) {
        for (String p : patterns) {
            if (p == null || p.trim().isEmpty()) continue;
            boolean found;
            try {
                found = Pattern.compile(p, Pattern.CASE_INSENSITIVE | Pattern.UNICODE_CASE | Pattern.DOTALL)
                               .matcher(code).find();
            } catch (PatternSyntaxException e) {
                found = code.toLowerCase().contains(p.toLowerCase());
            }
            if (mustNotMatch && found)  return "Rozwiązanie używa konstrukcji wykluczonej w tym zadaniu.";
            if (!mustNotMatch && !found) return "Rozwiązanie nie zawiera konstrukcji wymaganej w tym zadaniu.";
        }
        return null;
    }

    /** Przenosi wynik przebiegu do wyniku pytania (punkty, komunikat, szczegóły). */
    public static void apply(Outcome o, Question q, QuestionResult r) {
        r.details.put("cases",  o.rows);
        r.details.put("passed", Integer.valueOf(o.passed));
        r.details.put("total",  Integer.valueOf(o.total));

        if (o.blocked) {
            r.award(0).withFeedback(o.blockReason);
            return;
        }
        if (!o.engineError.isEmpty()) {
            r.needsReview = true;
            r.award(0).withFeedback(o.engineError);
            r.details.put("engineError", o.engineError);
            return;
        }
        if (!o.compiled) {
            r.details.put("compileError", o.compileError);
            r.award(0).withFeedback("Kod nie kompiluje się — zobacz komunikat kompilatora.");
            return;
        }
        boolean allOrNothing = Json.bool(q.config, "allOrNothing", false);
        double score = allOrNothing ? (o.passed == o.total ? q.points : 0) : q.points * o.fraction;
        r.award(score);
        r.withFeedback("Zdane przypadki testowe: " + o.passed + " z " + o.total + ".");
    }
}

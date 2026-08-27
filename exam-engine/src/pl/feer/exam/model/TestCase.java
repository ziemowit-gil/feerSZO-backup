package pl.feer.exam.model;

import java.util.Map;

import pl.feer.exam.Json;

/**
 * Przypadek testowy wejście/wyjście dla zadań programistycznych.
 *
 * matchMode:
 *   exact   — dosłowne porównanie strumienia wyjścia,
 *   trim    — porównanie po obcięciu białych znaków na końcach linii i całości (domyślne),
 *   tokens  — porównanie ciągów tokenów rozdzielonych białymi znakami,
 *   numeric — jak tokens, ale liczby porównywane z tolerancją `tolerance`,
 *   regex   — `expected` jest wyrażeniem regularnym dopasowywanym do całego wyjścia.
 */
public final class TestCase {

    public final int     index;
    public final String  name;
    public final String  stdin;
    public final String  expected;
    public final String  matchMode;
    public final double  weight;
    public final boolean hidden;
    public final double  tolerance;

    public TestCase(int index, String name, String stdin, String expected,
                    String matchMode, double weight, boolean hidden, double tolerance) {
        this.index     = index;
        this.name      = (name == null || name.isEmpty()) ? ("Przypadek " + (index + 1)) : name;
        this.stdin     = stdin == null ? "" : stdin;
        this.expected  = expected == null ? "" : expected;
        this.matchMode = (matchMode == null || matchMode.isEmpty()) ? "trim" : matchMode;
        this.weight    = weight > 0 ? weight : 1.0;
        this.hidden    = hidden;
        this.tolerance = tolerance;
    }

    public static TestCase fromJson(Map<String, Object> m, int index) {
        return new TestCase(
            index,
            Json.str(m, "name", ""),
            Json.str(m, "stdin", ""),
            Json.str(m, "expected", Json.str(m, "expectedStdout", "")),
            Json.str(m, "matchMode", "trim"),
            Json.num(m, "weight", 1.0),
            Json.bool(m, "hidden", false),
            Json.num(m, "tolerance", 1e-6)
        );
    }
}

package pl.feer.exam.model;

import java.util.List;
import java.util.Map;
import java.util.regex.Pattern;
import java.util.regex.PatternSyntaxException;

import pl.feer.exam.Json;

/**
 * Normalizacja i porównywanie tekstu — wspólne dla pytań z luką, krótkiej
 * odpowiedzi i porównywania wyjścia programów kursanta.
 *
 * Ustawienia normalizacji czytane są z konfiguracji pytania:
 *   caseSensitive   (domyślnie false) — rozróżniaj wielkość liter,
 *   trim            (domyślnie true)  — obetnij białe znaki na krańcach,
 *   collapseSpaces  (domyślnie true)  — zwielokrotnione spacje → jedna,
 *   ignoreAccents   (domyślnie false) — „lacznosc" ≡ „łączność",
 *   ignorePunct     (domyślnie false) — pomiń znaki interpunkcyjne,
 *   regex           (domyślnie false) — wzorce akceptowane jako wyrażenia regularne.
 */
public final class Text {

    private Text() { }

    private static final String ACCENTED = "ąćęłńóśźżĄĆĘŁŃÓŚŹŻ";
    private static final String PLAIN    = "acelnoszzACELNOSZZ";

    public static String stripAccents(String s) {
        StringBuilder b = new StringBuilder(s.length());
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            int k = ACCENTED.indexOf(c);
            b.append(k >= 0 ? PLAIN.charAt(k) : c);
        }
        return b.toString();
    }

    public static String normalize(String raw, Map<String, Object> cfg) {
        String s = raw == null ? "" : raw;
        s = s.replace("\r\n", "\n").replace('\r', '\n');
        if (Json.bool(cfg, "trim", true)) s = s.trim();
        if (Json.bool(cfg, "collapseSpaces", true)) s = s.replaceAll("[ \\t]+", " ");
        if (!Json.bool(cfg, "caseSensitive", false)) s = s.toLowerCase();
        if (Json.bool(cfg, "ignoreAccents", false)) s = stripAccents(s);
        if (Json.bool(cfg, "ignorePunct", false)) s = s.replaceAll("[\\p{Punct}]", "");
        return s;
    }

    /** Czy odpowiedź kursanta pasuje do któregokolwiek z wzorców akceptowanych. */
    public static boolean matchesAny(String given, List<String> accept, Map<String, Object> cfg) {
        if (accept == null || accept.isEmpty()) return false;
        boolean regex = Json.bool(cfg, "regex", false);
        String g = normalize(given, cfg);
        for (String pattern : accept) {
            if (pattern == null) continue;
            if (regex) {
                try {
                    int flags = Pattern.UNICODE_CASE
                              | (Json.bool(cfg, "caseSensitive", false) ? 0 : Pattern.CASE_INSENSITIVE);
                    if (Pattern.compile(pattern, flags).matcher(given == null ? "" : given.trim()).matches()) return true;
                } catch (PatternSyntaxException e) {
                    // Błędny wzorzec prowadzącego nie może wywrócić oceniania — traktujemy jak tekst.
                    if (normalize(pattern, cfg).equals(g)) return true;
                }
            } else if (normalize(pattern, cfg).equals(g)) {
                return true;
            }
        }
        return false;
    }

    /** Czy tekst zawiera którekolwiek ze słów kluczowych (dopasowanie po normalizacji). */
    public static boolean containsAny(String haystack, List<String> needles, Map<String, Object> cfg) {
        if (needles == null || needles.isEmpty()) return false;
        String h = normalize(haystack, cfg);
        for (String n : needles) {
            if (n == null || n.trim().isEmpty()) continue;
            if (Json.bool(cfg, "regex", false)) {
                try {
                    int flags = Pattern.UNICODE_CASE
                              | (Json.bool(cfg, "caseSensitive", false) ? 0 : Pattern.CASE_INSENSITIVE);
                    if (Pattern.compile(n, flags).matcher(haystack == null ? "" : haystack).find()) return true;
                    continue;
                } catch (PatternSyntaxException e) { /* niżej: dopasowanie dosłowne */ }
            }
            if (h.contains(normalize(n, cfg))) return true;
        }
        return false;
    }

    // ── Porównywanie wyjścia programu ─────────────────────────────────────────

    /** Zwraca true, gdy wyjście programu odpowiada oczekiwanemu wg trybu dopasowania. */
    public static boolean outputMatches(String actual, TestCase tc) {
        String a = actual == null ? "" : actual.replace("\r\n", "\n").replace('\r', '\n');
        String e = tc.expected.replace("\r\n", "\n").replace('\r', '\n');
        String mode = tc.matchMode;

        if ("exact".equals(mode)) return a.equals(e);

        if ("regex".equals(mode)) {
            try { return Pattern.compile(e, Pattern.DOTALL | Pattern.UNICODE_CASE).matcher(a).matches(); }
            catch (PatternSyntaxException ex) { return false; }
        }

        if ("tokens".equals(mode) || "numeric".equals(mode)) {
            String[] at = a.trim().split("\\s+");
            String[] et = e.trim().split("\\s+");
            if (at.length == 1 && at[0].isEmpty()) at = new String[0];
            if (et.length == 1 && et[0].isEmpty()) et = new String[0];
            if (at.length != et.length) return false;
            for (int i = 0; i < at.length; i++) {
                if (at[i].equals(et[i])) continue;
                if (!"numeric".equals(mode)) return false;
                try {
                    double da = Double.parseDouble(at[i]);
                    double de = Double.parseDouble(et[i]);
                    double diff = Math.abs(da - de);
                    if (diff > tc.tolerance && diff > Math.abs(de) * tc.tolerance) return false;
                } catch (NumberFormatException nfe) {
                    return false;
                }
            }
            return true;
        }

        // "trim" (domyślny): pomija białe znaki na końcach linii i puste linie końcowe
        return trimLines(a).equals(trimLines(e));
    }

    private static String trimLines(String s) {
        String[] lines = s.split("\n", -1);
        StringBuilder b = new StringBuilder();
        int last = lines.length - 1;
        while (last >= 0 && lines[last].trim().isEmpty()) last--;
        for (int i = 0; i <= last; i++) {
            if (i > 0) b.append('\n');
            b.append(rtrim(lines[i]));
        }
        return b.toString();
    }

    private static String rtrim(String s) {
        int end = s.length();
        while (end > 0 && Character.isWhitespace(s.charAt(end - 1))) end--;
        return s.substring(0, end);
    }

    /** Skraca tekst do limitu znaków — chroni odpowiedź HTTP przed zalaniem. */
    public static String cap(String s, int max) {
        if (s == null) return "";
        if (s.length() <= max) return s;
        return s.substring(0, max) + "\n… (obcięto, wyjście dłuższe niż " + max + " znaków)";
    }
}

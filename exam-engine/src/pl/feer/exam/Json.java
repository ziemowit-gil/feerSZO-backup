package pl.feer.exam;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Minimalny parser i serializator JSON — bez zależności zewnętrznych.
 *
 * Silnik egzaminów świadomie nie używa Jacksona/Gsona: obraz Dockera buduje się
 * samym `javac`, bez Mavena i bez dostępu do sieci przy budowaniu. Zakres jest
 * w pełni wystarczający dla protokołu PHP ↔ Java (obiekty, tablice, teksty,
 * liczby, wartości logiczne, null).
 *
 * Reprezentacja po sparsowaniu:
 *   obiekt  → LinkedHashMap&lt;String,Object&gt; (kolejność kluczy zachowana)
 *   tablica → ArrayList&lt;Object&gt;
 *   tekst   → String
 *   liczba  → Double
 *   logiczna→ Boolean
 *   null    → null
 */
public final class Json {

    private Json() { }

    // ── Parsowanie ────────────────────────────────────────────────────────────

    public static Object parse(String s) {
        if (s == null) throw new IllegalArgumentException("Pusty dokument JSON");
        Parser p = new Parser(s);
        p.ws();
        Object v = p.value();
        p.ws();
        if (p.pos < p.len) throw new IllegalArgumentException("Nadmiarowe dane na pozycji " + p.pos);
        return v;
    }

    private static final class Parser {
        final String s;
        final int len;
        int pos;

        Parser(String s) { this.s = s; this.len = s.length(); }

        void ws() { while (pos < len && Character.isWhitespace(s.charAt(pos))) pos++; }

        char peek() {
            if (pos >= len) throw new IllegalArgumentException("Nieoczekiwany koniec dokumentu JSON");
            return s.charAt(pos);
        }

        Object value() {
            char c = peek();
            if (c == '{') return object();
            if (c == '[') return array();
            if (c == '"') return string();
            if (c == 't') { literal("true");  return Boolean.TRUE;  }
            if (c == 'f') { literal("false"); return Boolean.FALSE; }
            if (c == 'n') { literal("null");  return null;          }
            return number();
        }

        void literal(String word) {
            if (!s.startsWith(word, pos)) throw new IllegalArgumentException("Nieznany literał na pozycji " + pos);
            pos += word.length();
        }

        Map<String, Object> object() {
            Map<String, Object> m = new LinkedHashMap<String, Object>();
            pos++; // '{'
            ws();
            if (peek() == '}') { pos++; return m; }
            while (true) {
                ws();
                String key = string();
                ws();
                if (peek() != ':') throw new IllegalArgumentException("Brak ':' na pozycji " + pos);
                pos++;
                ws();
                m.put(key, value());
                ws();
                char c = peek();
                if (c == ',') { pos++; continue; }
                if (c == '}') { pos++; return m; }
                throw new IllegalArgumentException("Zły separator obiektu na pozycji " + pos);
            }
        }

        List<Object> array() {
            List<Object> l = new ArrayList<Object>();
            pos++; // '['
            ws();
            if (peek() == ']') { pos++; return l; }
            while (true) {
                ws();
                l.add(value());
                ws();
                char c = peek();
                if (c == ',') { pos++; continue; }
                if (c == ']') { pos++; return l; }
                throw new IllegalArgumentException("Zły separator tablicy na pozycji " + pos);
            }
        }

        String string() {
            if (peek() != '"') throw new IllegalArgumentException("Oczekiwano tekstu na pozycji " + pos);
            pos++;
            StringBuilder b = new StringBuilder();
            while (true) {
                if (pos >= len) throw new IllegalArgumentException("Niedomknięty tekst w JSON");
                char c = s.charAt(pos++);
                if (c == '"') return b.toString();
                if (c != '\\') { b.append(c); continue; }
                if (pos >= len) throw new IllegalArgumentException("Niedomknięta sekwencja ucieczki");
                char e = s.charAt(pos++);
                switch (e) {
                    case '"':  b.append('"');  break;
                    case '\\': b.append('\\'); break;
                    case '/':  b.append('/');  break;
                    case 'b':  b.append('\b'); break;
                    case 'f':  b.append('\f'); break;
                    case 'n':  b.append('\n'); break;
                    case 'r':  b.append('\r'); break;
                    case 't':  b.append('\t'); break;
                    case 'u':
                        if (pos + 4 > len) throw new IllegalArgumentException("Skrócona sekwencja \\u");
                        b.append((char) Integer.parseInt(s.substring(pos, pos + 4), 16));
                        pos += 4;
                        break;
                    default: throw new IllegalArgumentException("Nieznana sekwencja \\" + e);
                }
            }
        }

        Double number() {
            int start = pos;
            if (pos < len && (s.charAt(pos) == '-' || s.charAt(pos) == '+')) pos++;
            while (pos < len) {
                char c = s.charAt(pos);
                boolean digit = c >= '0' && c <= '9';
                boolean expo  = c == 'e' || c == 'E';
                boolean sign  = (c == '+' || c == '-') && pos > start
                                && (s.charAt(pos - 1) == 'e' || s.charAt(pos - 1) == 'E');
                if (digit || expo || sign || c == '.') pos++;
                else break;
            }
            if (start == pos) throw new IllegalArgumentException("Oczekiwano liczby na pozycji " + pos);
            return Double.valueOf(Double.parseDouble(s.substring(start, pos)));
        }
    }

    // ── Serializacja ──────────────────────────────────────────────────────────

    public static String write(Object v) {
        StringBuilder b = new StringBuilder();
        writeTo(v, b);
        return b.toString();
    }

    private static void writeTo(Object v, StringBuilder b) {
        if (v == null)             { b.append("null"); return; }
        if (v instanceof String)   { escape((String) v, b); return; }
        if (v instanceof Boolean)  { b.append(((Boolean) v).booleanValue() ? "true" : "false"); return; }
        if (v instanceof Number) {
            double d = ((Number) v).doubleValue();
            if (Double.isNaN(d) || Double.isInfinite(d)) { b.append("null"); return; }
            if (d == Math.rint(d) && Math.abs(d) < 1e15) b.append((long) d);
            else b.append(Math.round(d * 1e6) / 1e6);
            return;
        }
        if (v instanceof Map) {
            b.append('{');
            boolean first = true;
            for (Map.Entry<?, ?> e : ((Map<?, ?>) v).entrySet()) {
                if (!first) b.append(',');
                first = false;
                escape(String.valueOf(e.getKey()), b);
                b.append(':');
                writeTo(e.getValue(), b);
            }
            b.append('}');
            return;
        }
        if (v instanceof Iterable) {
            b.append('[');
            boolean first = true;
            for (Object o : (Iterable<?>) v) {
                if (!first) b.append(',');
                first = false;
                writeTo(o, b);
            }
            b.append(']');
            return;
        }
        escape(String.valueOf(v), b);
    }

    private static void escape(String s, StringBuilder b) {
        b.append('"');
        for (int i = 0; i < s.length(); i++) {
            char c = s.charAt(i);
            switch (c) {
                case '"':  b.append("\\\""); break;
                case '\\': b.append("\\\\"); break;
                case '\n': b.append("\\n");  break;
                case '\r': b.append("\\r");  break;
                case '\t': b.append("\\t");  break;
                case '\b': b.append("\\b");  break;
                case '\f': b.append("\\f");  break;
                default:
                    if (c < 0x20 || c == 0x7f) b.append(String.format("\\u%04x", Integer.valueOf(c)));
                    else b.append(c);
            }
        }
        b.append('"');
    }

    // ── Bezpieczne akcesory ───────────────────────────────────────────────────

    @SuppressWarnings("unchecked")
    public static Map<String, Object> asMap(Object o) {
        return (o instanceof Map) ? (Map<String, Object>) o : new LinkedHashMap<String, Object>();
    }

    @SuppressWarnings("unchecked")
    public static List<Object> asList(Object o) {
        return (o instanceof List) ? (List<Object>) o : new ArrayList<Object>();
    }

    public static Map<String, Object> map(Map<String, Object> m, String key) {
        return asMap(m == null ? null : m.get(key));
    }

    public static List<Object> list(Map<String, Object> m, String key) {
        return asList(m == null ? null : m.get(key));
    }

    public static boolean has(Map<String, Object> m, String key) {
        return m != null && m.containsKey(key) && m.get(key) != null;
    }

    public static String str(Map<String, Object> m, String key, String def) {
        Object v = (m == null) ? null : m.get(key);
        if (v == null) return def;
        if (v instanceof String) return (String) v;
        if (v instanceof Boolean) return ((Boolean) v).booleanValue() ? "1" : "0";
        if (v instanceof Number) {
            double d = ((Number) v).doubleValue();
            if (d == Math.rint(d) && Math.abs(d) < 1e15) return Long.toString((long) d);
            return Double.toString(d);
        }
        return String.valueOf(v);
    }

    public static double num(Map<String, Object> m, String key, double def) {
        Object v = (m == null) ? null : m.get(key);
        if (v instanceof Number) return ((Number) v).doubleValue();
        if (v instanceof Boolean) return ((Boolean) v).booleanValue() ? 1 : 0;
        if (v instanceof String) {
            try { return Double.parseDouble(((String) v).trim()); } catch (RuntimeException e) { return def; }
        }
        return def;
    }

    public static int integer(Map<String, Object> m, String key, int def) {
        return (int) Math.round(num(m, key, def));
    }

    public static long id(Map<String, Object> m, String key, long def) {
        return Math.round(num(m, key, def));
    }

    public static boolean bool(Map<String, Object> m, String key, boolean def) {
        Object v = (m == null) ? null : m.get(key);
        if (v instanceof Boolean) return ((Boolean) v).booleanValue();
        if (v instanceof Number)  return ((Number) v).doubleValue() != 0;
        if (v instanceof String) {
            String s = ((String) v).trim().toLowerCase();
            if (s.equals("1") || s.equals("true") || s.equals("tak") || s.equals("yes") || s.equals("on")) return true;
            if (s.equals("0") || s.equals("false") || s.equals("nie") || s.equals("no") || s.equals("off")) return false;
        }
        return def;
    }

    /** Lista tekstów spod klucza — pojedynczy tekst też jest akceptowany. */
    public static List<String> strings(Map<String, Object> m, String key) {
        List<String> out = new ArrayList<String>();
        Object v = (m == null) ? null : m.get(key);
        if (v instanceof String) { out.add((String) v); return out; }
        for (Object o : asList(v)) if (o != null) out.add(String.valueOf(o));
        return out;
    }

    /** Skrótowy budowniczy obiektu: obj("a", 1, "b", "x"). */
    public static Map<String, Object> obj(Object... kv) {
        Map<String, Object> m = new LinkedHashMap<String, Object>();
        for (int i = 0; i + 1 < kv.length; i += 2) m.put(String.valueOf(kv[i]), kv[i + 1]);
        return m;
    }
}

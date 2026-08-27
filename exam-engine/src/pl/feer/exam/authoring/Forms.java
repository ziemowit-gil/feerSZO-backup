package pl.feer.exam.authoring;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/** Drobne narzędzia do czytania surowych pól formularza prowadzącego. */
public final class Forms {

    private Forms() { }

    /** Pole wielolinijkowe → lista niepustych wierszy. */
    public static List<String> lines(Map<String, Object> m, String key) {
        List<String> out = new ArrayList<String>();
        Object v = (m == null) ? null : m.get(key);
        if (v instanceof List) {
            for (Object o : Json.asList(v)) {
                if (o == null) continue;
                String s = String.valueOf(o).trim();
                if (!s.isEmpty()) out.add(s);
            }
            return out;
        }
        String raw = Json.str(m, key, "");
        for (String line : raw.split("\\R")) {
            String s = line.trim();
            if (!s.isEmpty()) out.add(s);
        }
        return out;
    }

    /** Pole „7, 8, 12” → lista liczb całkowitych (bez duplikatów, bez zer). */
    public static List<Object> intList(Map<String, Object> m, String key) {
        List<Object> out = new ArrayList<Object>();
        Object v = (m == null) ? null : m.get(key);
        List<String> parts = new ArrayList<String>();
        if (v instanceof List) {
            for (Object o : Json.asList(v)) parts.add(String.valueOf(o));
        } else {
            for (String p : Json.str(m, key, "").split("[,;\\s]+")) parts.add(p);
        }
        for (String p : parts) {
            String s = p.trim();
            if (s.isEmpty()) continue;
            try {
                Integer n = Integer.valueOf((int) Math.round(Double.parseDouble(s)));
                if (n.intValue() > 0 && !out.contains(n)) out.add(n);
            } catch (NumberFormatException e) { /* pomijamy śmieci */ }
        }
        return out;
    }

    /** Liczba z pola tekstowego — akceptuje przecinek dziesiętny. */
    public static double decimal(Map<String, Object> m, String key, double def) {
        Object v = (m == null) ? null : m.get(key);
        if (v instanceof Number) return ((Number) v).doubleValue();
        String s = Json.str(m, key, "").trim().replace(',', '.');
        if (s.isEmpty()) return def;
        try { return Double.parseDouble(s); } catch (NumberFormatException e) { return def; }
    }

    public static int clampInt(double v, int lo, int hi) {
        int i = (int) Math.round(v);
        if (i < lo) return lo;
        if (i > hi) return hi;
        return i;
    }

    public static double clamp(double v, double lo, double hi) {
        if (Double.isNaN(v)) return lo;
        return Math.max(lo, Math.min(hi, v));
    }

    /** Wpis na listę błędów walidacji. */
    public static Map<String, Object> error(String field, String message) {
        return Json.obj("field", field, "message", message);
    }
}

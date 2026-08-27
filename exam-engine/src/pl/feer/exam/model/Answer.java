package pl.feer.exam.model;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/**
 * Odpowiedź kursanta na jedno pytanie — surowy obiekt JSON z panelu PHP.
 *
 * Kształt zależy od typu pytania:
 *   single/multi      → {"optionIds":[3,5]}
 *   truefalse         → {"statements":{"12":true,"13":false}}
 *   fill_blank        → {"blanks":{"1":"echo","2":"return"}}
 *   short_answer      → {"text":"…"}
 *   code_fix (linia)  → {"line":7}
 *   code_fix (popraw) → {"code":"…"}
 *   code_completion   → {"blanks":{…}}  (opcjonalnie też {"code":"…"})
 *   code_run          → {"code":"…"}
 */
public final class Answer {

    public static final Answer EMPTY = new Answer(new LinkedHashMap<String, Object>());

    public final Map<String, Object> raw;

    public Answer(Map<String, Object> raw) {
        this.raw = raw == null ? new LinkedHashMap<String, Object>() : raw;
    }

    public static Answer fromJson(Object o) {
        if (o instanceof Map) return new Answer(Json.asMap(o));
        if (o instanceof String) return new Answer(Json.obj("text", o));
        return EMPTY;
    }

    public boolean isBlank() {
        return raw.isEmpty();
    }

    public List<Long> optionIds() {
        List<Long> out = new ArrayList<Long>();
        Object v = raw.get("optionIds");
        if (v == null) v = raw.get("option_ids");
        if (v instanceof Number) { out.add(Long.valueOf(Math.round(((Number) v).doubleValue()))); return out; }
        if (v instanceof String) {
            for (String part : ((String) v).split(",")) {
                String p = part.trim();
                if (p.isEmpty()) continue;
                try { out.add(Long.valueOf(Long.parseLong(p))); } catch (RuntimeException e) { /* pomijamy śmieci */ }
            }
            return out;
        }
        for (Object o : Json.asList(v)) {
            if (o == null) continue;
            try { out.add(Long.valueOf(Math.round(Double.parseDouble(String.valueOf(o).trim())))); }
            catch (RuntimeException e) { /* pomijamy śmieci */ }
        }
        return out;
    }

    public String text() {
        return Json.str(raw, "text", "");
    }

    public String code() {
        return Json.str(raw, "code", "");
    }

    /** -1 gdy kursant nie wskazał linii. */
    public int line() {
        return Json.integer(raw, "line", -1);
    }

    /** Klucz = numer luki jako tekst ("1", "2", …) lub identyfikator luki. */
    public Map<String, String> blanks() {
        Map<String, String> out = new LinkedHashMap<String, String>();
        Map<String, Object> m = Json.map(raw, "blanks");
        for (Map.Entry<String, Object> e : m.entrySet()) {
            out.put(e.getKey(), e.getValue() == null ? "" : String.valueOf(e.getValue()));
        }
        return out;
    }

    /** Klucz = id twierdzenia jako tekst, wartość = zaznaczenie kursanta (prawda/fałsz). */
    public Map<String, Boolean> statements() {
        Map<String, Boolean> out = new LinkedHashMap<String, Boolean>();
        Map<String, Object> m = Json.map(raw, "statements");
        for (Map.Entry<String, Object> e : m.entrySet()) {
            Object v = e.getValue();
            if (v == null) continue;
            Map<String, Object> one = Json.obj("v", v);
            out.put(e.getKey(), Boolean.valueOf(Json.bool(one, "v", false)));
        }
        return out;
    }
}

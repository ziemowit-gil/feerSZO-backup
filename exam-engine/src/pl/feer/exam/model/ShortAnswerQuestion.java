package pl.feer.exam.model;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/**
 * Pytanie otwarte z kryteriami — krótka odpowiedź opisowa.
 *
 * Ocena automatyczna po obecności słów kluczowych:
 *   config = {
 *     "keywords":[ {"label":"rekurencja","any":["rekurencj","wywołuje sam"],"points":1,"required":false}, … ],
 *     "manual":false,        // true = zawsze do oceny prowadzącego (automat daje tylko podpowiedź)
 *     "minChars":0,          // minimalna długość odpowiedzi
 *     "ignoreAccents":true
 *   }
 * Gdy kryteriów nie ma albo prowadzący wymusił ocenę ręczną, pytanie trafia
 * na listę „do sprawdzenia" wraz z sugerowaną punktacją.
 */
public final class ShortAnswerQuestion extends Question {

    @Override
    public boolean optionsShufflable() { return false; }

    @Override
    public QuestionResult grade(Answer answer, GradingContext ctx) {
        QuestionResult r = result();
        String text = answer.text();
        List<Object> criteria = Json.list(config, "keywords");
        boolean manual = Json.bool(config, "manual", false);

        int minChars = Json.integer(config, "minChars", 0);
        if (text.trim().isEmpty()) {
            return r.award(0).withFeedback("Brak odpowiedzi.");
        }
        if (minChars > 0 && text.trim().length() < minChars) {
            r.needsReview = manual;
            return r.award(0).withFeedback("Odpowiedź jest krótsza niż wymagane " + minChars + " znaków.");
        }

        if (criteria.isEmpty()) {
            r.needsReview = true;
            r.details.put("suggestedPoints", Double.valueOf(0));
            return r.award(0).withFeedback("Odpowiedź czeka na ocenę prowadzącego.");
        }

        double gained = 0, total = 0;
        boolean missingRequired = false;
        List<Object> rows = new ArrayList<Object>();

        for (Object o : criteria) {
            Map<String, Object> c = Json.asMap(o);
            double w = Json.num(c, "points", 1.0);
            if (w <= 0) w = 1.0;
            total += w;

            Map<String, Object> norm = new LinkedHashMap<String, Object>(config);
            norm.putAll(c);
            List<String> any = Json.strings(c, "any");
            if (any.isEmpty()) any = Json.strings(c, "keywords");

            boolean hit = Text.containsAny(text, any, norm);
            if (hit) gained += w;
            else if (Json.bool(c, "required", false)) missingRequired = true;

            Map<String, Object> row = new LinkedHashMap<String, Object>();
            row.put("label", Json.str(c, "label", any.isEmpty() ? "kryterium" : any.get(0)));
            row.put("met",   Boolean.valueOf(hit));
            if (ctx.revealAnswers) row.put("any", any);
            rows.add(row);
        }

        double auto = missingRequired || total <= 0 ? 0 : points * (gained / total);
        r.details.put("criteria",        rows);
        r.details.put("suggestedPoints", Double.valueOf(Math.round(auto * 1000.0) / 1000.0));
        r.details.put("missingRequired", Boolean.valueOf(missingRequired));

        if (manual) {
            r.needsReview = true;
            r.award(0);
            return r.withFeedback("Odpowiedź czeka na ocenę prowadzącego (wstępna punktacja automatu: "
                                  + fmt(auto) + " / " + fmt(points) + ").");
        }

        r.award(auto);
        if (missingRequired) return r.withFeedback("W odpowiedzi brakuje kryterium wymaganego w tym pytaniu.");
        return r.withFeedback("Spełnione kryteria: " + countMet(rows) + " z " + criteria.size() + ".");
    }

    private static int countMet(List<Object> rows) {
        int n = 0;
        for (Object o : rows) if (Json.bool(Json.asMap(o), "met", false)) n++;
        return n;
    }

    private static String fmt(double d) {
        if (d == Math.rint(d)) return Long.toString((long) d);
        return String.valueOf(Math.round(d * 100.0) / 100.0);
    }

    @Override
    public Map<String, Object> correctAnswerJson() {
        return Json.obj("criteria", Json.list(config, "keywords"));
    }
}

package pl.feer.exam.model;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/**
 * Pytanie z luką — kursant wpisuje brakujące słowo kluczowe albo pojęcie.
 *
 * Treść zawiera znaczniki [[1]], [[2]] … w miejscach luk, a konfiguracja listę
 * luk z akceptowanymi odpowiedziami:
 *   config = {
 *     "blanks":[ {"accept":["rekurencja","rekurencyjna"],"hint":"…","points":1}, … ],
 *     "caseSensitive":false, "ignoreAccents":true, "regex":false,
 *     "allOrNothing":false
 *   }
 * Punkty rozkładają się proporcjonalnie do wagi luk (domyślnie równo).
 */
public class FillBlankQuestion extends Question {

    @Override
    public boolean optionsShufflable() { return false; }

    @Override
    public QuestionResult grade(Answer answer, GradingContext ctx) {
        QuestionResult r = result();
        List<Object> blanks = Json.list(config, "blanks");

        if (blanks.isEmpty()) {
            r.needsReview = true;
            return r.award(0).withFeedback("Pytanie nie ma zdefiniowanych luk — do sprawdzenia przez prowadzącego.");
        }

        Map<String, String> given = answer.blanks();
        BlankScore bs = scoreBlanks(blanks, given, ctx);

        boolean allOrNothing = Json.bool(config, "allOrNothing", false);
        double score = allOrNothing
            ? (bs.hits == blanks.size() ? points : 0)
            : (bs.totalWeight > 0 ? points * (bs.gained / bs.totalWeight) : 0);

        r.award(score);
        r.details.put("blanks", bs.rows);
        r.details.put("filled", Integer.valueOf(bs.hits));
        r.details.put("total",  Integer.valueOf(blanks.size()));
        r.withFeedback("Uzupełnione poprawnie: " + bs.hits + " z " + blanks.size() + ".");
        return r;
    }

    /** Wspólna ocena luk — używana też przez uzupełnianie kodu. */
    protected BlankScore scoreBlanks(List<Object> blanks, Map<String, String> given, GradingContext ctx) {
        BlankScore bs = new BlankScore();
        for (int i = 0; i < blanks.size(); i++) {
            Map<String, Object> b = Json.asMap(blanks.get(i));
            String key = Json.str(b, "key", Integer.toString(i + 1));
            double w   = Json.num(b, "points", 1.0);
            if (w <= 0) w = 1.0;
            bs.totalWeight += w;

            // Ustawienia normalizacji: najpierw luka, w razie braku — całe pytanie.
            Map<String, Object> norm = new LinkedHashMap<String, Object>(config);
            norm.putAll(b);

            String value = given.get(key);
            if (value == null) value = given.get(Integer.toString(i + 1));
            List<String> accept = Json.strings(b, "accept");
            boolean ok = value != null && Text.matchesAny(value, accept, norm);
            if (ok) { bs.hits++; bs.gained += w; }

            Map<String, Object> row = new LinkedHashMap<String, Object>();
            row.put("key",     key);
            row.put("given",   value == null ? "" : value);
            row.put("correct", Boolean.valueOf(ok));
            if (ctx.revealAnswers) row.put("accept", accept);
            bs.rows.add(row);
        }
        return bs;
    }

    /** Cząstkowy wynik uzupełniania luk. */
    protected static final class BlankScore {
        int hits;
        double gained;
        double totalWeight;
        final List<Object> rows = new ArrayList<Object>();
    }

    @Override
    public Map<String, Object> correctAnswerJson() {
        Map<String, Object> expected = new LinkedHashMap<String, Object>();
        List<Object> blanks = Json.list(config, "blanks");
        for (int i = 0; i < blanks.size(); i++) {
            Map<String, Object> b = Json.asMap(blanks.get(i));
            expected.put(Json.str(b, "key", Integer.toString(i + 1)), Json.strings(b, "accept"));
        }
        return Json.obj("expectedBlanks", expected);
    }
}

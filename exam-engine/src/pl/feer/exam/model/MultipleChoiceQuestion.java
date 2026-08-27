package pl.feer.exam.model;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

import pl.feer.exam.Json;

/**
 * Pytanie wielokrotnego wyboru z precyzyjnym przeliczaniem punktów.
 *
 * Domyślnie działa punktacja z karą za błędne zaznaczenie:
 *   wynik = punkty × max(0, (trafione − błędne) / wszystkich poprawnych)
 * dzięki czemu strategia „zaznaczam wszystko" daje dokładnie 0 punktów.
 * Prowadzący może zmienić zasadę per pytanie (`config.negMarking`) albo per test.
 */
public final class MultipleChoiceQuestion extends Question {

    @Override
    public QuestionResult grade(Answer answer, GradingContext ctx) {
        QuestionResult r = result();
        List<Option> correct = correctOptions();

        if (correct.isEmpty()) {
            r.needsReview = true;
            return r.award(0).withFeedback("Pytanie nie ma wskazanej poprawnej odpowiedzi — do sprawdzenia przez prowadzącego.");
        }

        Set<Long> picked = new HashSet<Long>(answer.optionIds());
        if (picked.isEmpty()) return r.award(0).withFeedback("Brak odpowiedzi.");

        Set<Long> correctIds = new HashSet<Long>();
        for (Option o : correct) correctIds.add(Long.valueOf(o.id));
        Set<Long> allIds = new HashSet<Long>();
        for (Option o : options) allIds.add(Long.valueOf(o.id));

        int hits = 0, misses = 0;
        for (Long id : picked) {
            if (!allIds.contains(id)) continue;              // wariant spoza pytania — ignorujemy
            if (correctIds.contains(id)) hits++; else misses++;
        }

        String rule = Json.str(config, "negMarking", ctx.negMarking);
        double score;
        if (GradingContext.NEG_ALL.equals(rule)) {
            score = (hits == correctIds.size() && misses == 0) ? points : 0;
        } else if (GradingContext.NEG_NONE.equals(rule)) {
            score = points * ((double) hits / correctIds.size());
        } else { // partial — z karą za zgadywanie
            score = points * Math.max(0.0, (double) (hits - misses) / correctIds.size());
        }

        r.award(score);
        r.details.put("hits",       Integer.valueOf(hits));
        r.details.put("misses",     Integer.valueOf(misses));
        r.details.put("totalCorrect", Integer.valueOf(correctIds.size()));
        r.details.put("negMarking", rule);
        r.withFeedback(buildFeedback(hits, misses, correctIds.size(), rule));
        if (ctx.revealAnswers) r.details.putAll(correctAnswerJson());
        return r;
    }

    private String buildFeedback(int hits, int misses, int total, String rule) {
        if (hits == total && misses == 0) return "Wszystkie poprawne warianty zaznaczone.";
        StringBuilder b = new StringBuilder();
        b.append("Trafione ").append(hits).append(" z ").append(total);
        if (misses > 0) {
            b.append(", błędnie zaznaczone: ").append(misses);
            if (GradingContext.NEG_PARTIAL.equals(rule)) b.append(" (obniża punktację)");
        }
        b.append('.');
        return b.toString();
    }

    @Override
    public Map<String, Object> correctAnswerJson() {
        List<Object> ids = new ArrayList<Object>();
        for (Option o : correctOptions()) ids.add(Long.valueOf(o.id));
        return Json.obj("correctOptionIds", ids);
    }
}

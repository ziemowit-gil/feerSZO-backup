package pl.feer.exam.model;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/**
 * Zestaw twierdzeń do szybkiej weryfikacji prawda/fałsz.
 *
 * Każdy wariant pytania jest jednym twierdzeniem; `isCorrect` znaczy tu
 * „twierdzenie jest prawdziwe". Kursant zaznacza P albo F przy każdym.
 * Punkty rozkładają się równo na twierdzenia; przy zasadzie `partial`
 * błędne zaznaczenie odejmuje jedno trafienie (brak zysku ze zgadywania),
 * a twierdzenie pominięte po prostu nie punktuje.
 */
public final class TrueFalseQuestion extends Question {

    @Override
    public QuestionResult grade(Answer answer, GradingContext ctx) {
        QuestionResult r = result();
        if (options.isEmpty()) {
            r.needsReview = true;
            return r.award(0).withFeedback("Pytanie nie zawiera twierdzeń — do sprawdzenia przez prowadzącego.");
        }

        Map<String, Boolean> given = answer.statements();
        int hits = 0, misses = 0, skipped = 0;
        List<Object> perStatement = new ArrayList<Object>();

        for (Option o : options) {
            Boolean v = given.get(Long.toString(o.id));
            Map<String, Object> row = new LinkedHashMap<String, Object>();
            row.put("optionId", Long.valueOf(o.id));
            if (v == null) {
                skipped++;
                row.put("answered", Boolean.FALSE);
                row.put("correct",  Boolean.FALSE);
            } else {
                boolean ok = v.booleanValue() == o.correct;
                if (ok) hits++; else misses++;
                row.put("answered", Boolean.TRUE);
                row.put("given",    v);
                row.put("correct",  Boolean.valueOf(ok));
            }
            if (ctx.revealAnswers) row.put("expected", Boolean.valueOf(o.correct));
            perStatement.add(row);
        }

        String rule = Json.str(config, "negMarking", ctx.negMarking);
        int total = options.size();
        double score;
        if (GradingContext.NEG_ALL.equals(rule)) {
            score = (hits == total) ? points : 0;
        } else if (GradingContext.NEG_NONE.equals(rule)) {
            score = points * ((double) hits / total);
        } else {
            score = points * Math.max(0.0, (double) (hits - misses) / total);
        }

        r.award(score);
        r.details.put("statements", perStatement);
        r.details.put("hits",    Integer.valueOf(hits));
        r.details.put("misses",  Integer.valueOf(misses));
        r.details.put("skipped", Integer.valueOf(skipped));
        r.withFeedback("Poprawnie ocenione twierdzenia: " + hits + " z " + total
                       + (skipped > 0 ? " (pominięto " + skipped + ")" : "") + ".");
        return r;
    }

    @Override
    public Map<String, Object> correctAnswerJson() {
        Map<String, Object> expected = new LinkedHashMap<String, Object>();
        for (Option o : options) expected.put(Long.toString(o.id), Boolean.valueOf(o.correct));
        return Json.obj("expectedStatements", expected);
    }
}

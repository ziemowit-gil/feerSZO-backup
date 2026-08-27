package pl.feer.exam.authoring;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/**
 * Panel oceny pytań opisowych — reguły przyznawania punktów przez prowadzącego.
 *
 * Silnik decyduje o: przycięciu punktów do zakresu pytania, uznaniu odpowiedzi
 * za poprawną, zdjęciu znacznika „do sprawdzenia", przeliczeniu sumy podejścia
 * i o tym, czy praca jest już w pełni oceniona i zaliczona.
 *
 * Wejście:
 *   { "passPct":60,
 *     "questions":[{"id":12,"points":2}],
 *     "answers":[{"questionId":12,"points":1.0,"needsReview":true,"manual":false}],
 *     "marks":{"12":"1,5"}, "notes":{"12":"Brakuje uzasadnienia"} }
 *
 * Wyjście:
 *   { "answers":[{questionId, points, correct, needsReview, note, changed}],
 *     "attempt":{score, maxScore, percent, needsReview, status, passed} }
 */
public final class ManualGrading {

    private ManualGrading() { }

    /** Wstawiany, gdy prowadzący nie napisał komentarza — znacznik decyzji człowieka. */
    public static final String DEFAULT_NOTE = "Ocena prowadzącego";

    public static Map<String, Object> apply(Map<String, Object> req) {
        double passPct = Json.num(req, "passPct", 0);

        Map<Long, Double> maxByQuestion = new LinkedHashMap<Long, Double>();
        for (Object o : Json.list(req, "questions")) {
            Map<String, Object> q = Json.asMap(o);
            maxByQuestion.put(Long.valueOf(Json.id(q, "id", 0)), Double.valueOf(Json.num(q, "points", 0)));
        }

        Map<String, Object> marks = Json.map(req, "marks");
        Map<String, Object> notes = Json.map(req, "notes");

        List<Object> rows        = new ArrayList<Object>();
        double score = 0, maxScore = 0;
        boolean stillPending = false;

        for (Object o : Json.list(req, "answers")) {
            Map<String, Object> a = Json.asMap(o);
            long qid   = Json.id(a, "questionId", 0);
            String key = Long.toString(qid);
            double max = maxByQuestion.containsKey(Long.valueOf(qid))
                       ? maxByQuestion.get(Long.valueOf(qid)).doubleValue()
                       : Json.num(a, "maxPoints", 0);
            maxScore += max;

            boolean hasMark = marks.containsKey(key) && !Json.str(marks, key, "").trim().isEmpty();
            double  points;
            boolean review;
            String  note    = Json.str(notes, key, "").trim();
            boolean changed = false;

            if (hasMark) {
                points  = Forms.clamp(Forms.decimal(marks, key, 0), 0, max);
                review  = false;
                changed = true;
                if (note.isEmpty()) note = DEFAULT_NOTE;
            } else if (Json.has(a, "points")) {
                points = Forms.clamp(Json.num(a, "points", 0), 0, max);
                review = Json.bool(a, "needsReview", false);
            } else {
                points = 0;
                review = true;
            }

            if (review) stillPending = true;
            score += review ? 0 : points;

            Map<String, Object> row = new LinkedHashMap<String, Object>();
            row.put("questionId",  Long.valueOf(qid));
            row.put("points",      review ? null : Double.valueOf(round(points)));
            row.put("maxPoints",   Double.valueOf(round(max)));
            row.put("correct",     Boolean.valueOf(!review && max > 0 && points >= max - 1e-9));
            row.put("needsReview", Boolean.valueOf(review));
            row.put("note",        note);
            row.put("changed",     Boolean.valueOf(changed));
            rows.add(row);
        }

        double pct = maxScore > 0 ? (100.0 * score / maxScore) : 0;

        Map<String, Object> attempt = new LinkedHashMap<String, Object>();
        attempt.put("score",       Double.valueOf(round(score)));
        attempt.put("maxScore",    Double.valueOf(round(maxScore)));
        attempt.put("percent",     Double.valueOf(Math.round(pct * 100.0) / 100.0));
        attempt.put("needsReview", Boolean.valueOf(stillPending));
        attempt.put("status",      stillPending ? "submitted" : "graded");
        attempt.put("passed",      Boolean.valueOf(!stillPending && (passPct <= 0 || pct >= passPct)));

        Map<String, Object> out = new LinkedHashMap<String, Object>();
        out.put("answers", rows);
        out.put("attempt", attempt);
        return out;
    }

    private static double round(double v) {
        return Math.round(v * 1000.0) / 1000.0;
    }
}

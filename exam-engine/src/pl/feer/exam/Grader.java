package pl.feer.exam;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import pl.feer.exam.model.Answer;
import pl.feer.exam.model.GradingContext;
import pl.feer.exam.model.Question;
import pl.feer.exam.model.QuestionResult;
import pl.feer.exam.sandbox.Sandbox;

/**
 * Weryfikacja odpowiedzi i ocenianie całego podejścia.
 *
 * Wejście (JSON):
 *   { "mode":"exam|quiz|training", "negMarking":"partial|none|all_or_nothing",
 *     "passPct":60, "revealAnswers":true,
 *     "questions":[ …pełne definicje pytań… ],
 *     "answers": { "<idPytania>": { …odpowiedź… } } }
 *
 * Wyjście:
 *   { "score":…, "maxScore":…, "percent":…, "passed":bool, "needsReview":bool,
 *     "results":[ {questionId, points, maxPoints, correct, needsReview, feedback, details} ] }
 *
 * Pytanie, którego ocena rzuci wyjątkiem, nie wywraca całego podejścia — dostaje
 * 0 punktów i znacznik „do sprawdzenia", żeby prowadzący zobaczył problem.
 */
public final class Grader {

    private final Sandbox sandbox;

    public Grader(Sandbox sandbox) {
        this.sandbox = sandbox;
    }

    public Map<String, Object> grade(Map<String, Object> request) {
        String mode       = Json.str(request, "mode", GradingContext.MODE_EXAM);
        String negMarking = Json.str(request, "negMarking", GradingContext.NEG_PARTIAL);
        boolean reveal    = Json.bool(request, "revealAnswers", GradingContext.MODE_TRAINING.equals(mode));
        double passPct    = Json.num(request, "passPct", 0);

        GradingContext ctx = new GradingContext(mode, negMarking, reveal, sandbox);
        Map<String, Object> answers = Json.map(request, "answers");

        double score = 0, maxScore = 0;
        boolean needsReview = false;
        List<Object> results = new ArrayList<Object>();

        for (Object o : Json.list(request, "questions")) {
            Question q = Question.fromJson(Json.asMap(o));
            Answer a   = Answer.fromJson(answers.get(Long.toString(q.id)));

            QuestionResult r;
            try {
                r = q.grade(a, ctx);
            } catch (RuntimeException e) {
                r = new QuestionResult(q);
                r.needsReview = true;
                r.award(0).withFeedback("Błąd oceniania pytania: " + e.getClass().getSimpleName()
                                        + (e.getMessage() == null ? "" : " — " + e.getMessage()));
            }
            if (!ctx.revealAnswers) r.explanation = "";

            score       += r.points;
            maxScore    += r.maxPoints;
            needsReview |= r.needsReview;
            results.add(r.toJson());
        }

        double pct = maxScore > 0 ? (100.0 * score / maxScore) : 0;

        Map<String, Object> out = new LinkedHashMap<String, Object>();
        out.put("score",       Double.valueOf(Math.round(score * 1000.0) / 1000.0));
        out.put("maxScore",    Double.valueOf(Math.round(maxScore * 1000.0) / 1000.0));
        out.put("percent",     Double.valueOf(Math.round(pct * 100.0) / 100.0));
        out.put("passed",      Boolean.valueOf(!needsReview && (passPct <= 0 || pct >= passPct)));
        out.put("needsReview", Boolean.valueOf(needsReview));
        out.put("mode",        mode);
        out.put("results",     results);
        return out;
    }
}

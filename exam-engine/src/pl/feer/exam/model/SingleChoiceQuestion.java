package pl.feer.exam.model;

import java.util.ArrayList;
import java.util.List;

/** Pytanie jednokrotnego wyboru (klasyczne ABC) — punkty za trafienie jedynego poprawnego wariantu. */
public final class SingleChoiceQuestion extends Question {

    @Override
    public QuestionResult grade(Answer answer, GradingContext ctx) {
        QuestionResult r = result();
        List<Long> picked = answer.optionIds();

        if (picked.isEmpty()) return r.award(0).withFeedback("Brak odpowiedzi.");
        if (picked.size() > 1) return r.award(0).withFeedback("Wskazano więcej niż jedną odpowiedź — pytanie dopuszcza jedną.");

        long pick = picked.get(0).longValue();
        boolean hit = false;
        String optFeedback = "";
        for (Option o : options) {
            if (o.id == pick) { hit = o.correct; optFeedback = o.feedback; break; }
        }
        r.award(hit ? points : 0);
        r.withFeedback(!optFeedback.isEmpty() ? optFeedback
                                              : (hit ? "Odpowiedź poprawna." : "Odpowiedź niepoprawna."));
        if (ctx.revealAnswers) r.details.putAll(correctAnswerJson());
        return r;
    }

    @Override
    public java.util.Map<String, Object> correctAnswerJson() {
        List<Object> ids = new ArrayList<Object>();
        for (Option o : correctOptions()) ids.add(Long.valueOf(o.id));
        return pl.feer.exam.Json.obj("correctOptionIds", ids);
    }
}

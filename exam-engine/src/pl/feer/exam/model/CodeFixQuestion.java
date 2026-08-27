package pl.feer.exam.model;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/**
 * Analiza i poprawa kodu (code review / code fix).
 *
 * Dwa tryby odpowiedzi:
 *   answerMode = "line"    — kursant wskazuje numer błędnej linii z załączonego
 *                            fragmentu; akceptowane numery w `acceptLines`
 *                            (albo pojedynczy `correctLine`),
 *   answerMode = "rewrite" — kursant przepisuje kod poprawnie, a rozwiązanie
 *                            jest uruchamiane na przypadkach testowych.
 *
 *   config = {
 *     "language":"python", "snippet":"…kod z błędem…",
 *     "answerMode":"line", "correctLine":7, "acceptLines":[7,8],
 *     "requireExplanation":false,   // dopisz uzasadnienie → ocena prowadzącego
 *     "timeLimitMs":3000, "memoryMb":128
 *   }
 */
public final class CodeFixQuestion extends Question {

    @Override
    public boolean optionsShufflable() { return false; }

    @Override
    public QuestionResult grade(Answer answer, GradingContext ctx) {
        QuestionResult r = result();
        String lang = Json.str(config, "language", "python");
        r.details.put("language", lang);

        if ("rewrite".equals(Json.str(config, "answerMode", "line"))) {
            CodeGrading.Outcome o = CodeGrading.runCases(this, lang, answer.code(), cases, ctx);
            CodeGrading.apply(o, this, r);
            return r;
        }

        // ── Tryb „wskaż błędną linię" ────────────────────────────────────────
        List<Integer> accept = acceptedLines();
        int given = answer.line();
        r.details.put("givenLine", Integer.valueOf(given));
        if (ctx.revealAnswers) r.details.put("acceptLines", accept);

        if (accept.isEmpty()) {
            r.needsReview = true;
            return r.award(0).withFeedback("Pytanie nie ma wskazanej błędnej linii — do sprawdzenia przez prowadzącego.");
        }
        if (given <= 0) return r.award(0).withFeedback("Nie wskazano żadnej linii.");

        boolean hit = accept.contains(Integer.valueOf(given));
        boolean needExplanation = Json.bool(config, "requireExplanation", false);
        String explanationText = answer.text();

        if (!needExplanation) {
            r.award(hit ? points : 0);
            return r.withFeedback(hit ? "Wskazana linia jest tą z błędem."
                                      : "To nie jest linia zawierająca błąd.");
        }

        // Uzasadnienie ocenia prowadzący; trafienie linii idzie jako podpowiedź.
        double suggested = hit ? points / 2.0 : 0;
        r.needsReview = true;
        r.details.put("explanation", Text.cap(explanationText, 4000));
        r.details.put("lineCorrect", Boolean.valueOf(hit));
        r.details.put("suggestedPoints", Double.valueOf(Math.round(suggested * 1000.0) / 1000.0));
        r.award(0);
        return r.withFeedback(hit
            ? "Linia wskazana poprawnie — uzasadnienie czeka na ocenę prowadzącego."
            : "Wskazana linia jest inna niż oczekiwana — uzasadnienie czeka na ocenę prowadzącego.");
    }

    private List<Integer> acceptedLines() {
        List<Integer> out = new ArrayList<Integer>();
        for (Object o : Json.list(config, "acceptLines")) {
            try { out.add(Integer.valueOf((int) Math.round(Double.parseDouble(String.valueOf(o).trim())))); }
            catch (RuntimeException e) { /* pomijamy śmieci */ }
        }
        int single = Json.integer(config, "correctLine", 0);
        if (single > 0 && !out.contains(Integer.valueOf(single))) out.add(Integer.valueOf(single));
        return out;
    }

    @Override
    public Map<String, Object> correctAnswerJson() {
        return Json.obj("acceptLines", acceptedLines());
    }
}

package pl.feer.exam.model;

import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/**
 * Luki w kodzie — kursant uzupełnia brakujące słowa kluczowe, zmienne
 * albo konstrukcje składniowe w gotowym szablonie.
 *
 *   config = {
 *     "language":"php",
 *     "template":"foreach ($a as $x) {\n  ___1___ $x;\n}",
 *     "blanks":[ {"key":"1","accept":["echo","print"],"hint":"instrukcja wypisania"} ],
 *     "runAfterFill":false     // true = złożony kod jest dodatkowo uruchamiany na przypadkach testowych
 *   }
 *
 * Przy `runAfterFill` liczą się wyniki uruchomienia (uzupełnienia mogą być
 * dowolne, byle kod działał); w przeciwnym razie punktują same dopasowania luk.
 */
public final class CodeCompletionQuestion extends FillBlankQuestion {

    @Override
    public boolean optionsShufflable() { return false; }

    @Override
    public QuestionResult grade(Answer answer, GradingContext ctx) {
        QuestionResult r = result();
        String lang = Json.str(config, "language", "python");
        r.details.put("language", lang);

        List<Object> blanks = Json.list(config, "blanks");
        Map<String, String> given = answer.blanks();

        boolean run = Json.bool(config, "runAfterFill", false) && !cases.isEmpty();
        if (run) {
            String assembled = assemble(Json.str(config, "template", ""), blanks, given);
            r.details.put("assembledCode", Text.cap(assembled, 8000));
            CodeGrading.Outcome o = CodeGrading.runCases(this, lang, assembled, cases, ctx);
            CodeGrading.apply(o, this, r);
            return r;
        }

        if (blanks.isEmpty()) {
            r.needsReview = true;
            return r.award(0).withFeedback("Zadanie nie ma zdefiniowanych luk — do sprawdzenia przez prowadzącego.");
        }

        BlankScore bs = scoreBlanks(blanks, given, ctx);
        boolean allOrNothing = Json.bool(config, "allOrNothing", false);
        double score = allOrNothing
            ? (bs.hits == blanks.size() ? points : 0)
            : (bs.totalWeight > 0 ? points * (bs.gained / bs.totalWeight) : 0);

        r.award(score);
        r.details.put("blanks", bs.rows);
        r.withFeedback("Poprawnie uzupełnione luki: " + bs.hits + " z " + blanks.size() + ".");
        return r;
    }

    /** Podstawia odpowiedzi kursanta w miejsca znaczników ___klucz___ w szablonie. */
    private String assemble(String template, List<Object> blanks, Map<String, String> given) {
        String out = template;
        for (int i = 0; i < blanks.size(); i++) {
            Map<String, Object> b = Json.asMap(blanks.get(i));
            String key = Json.str(b, "key", Integer.toString(i + 1));
            String val = given.get(key);
            if (val == null) val = given.get(Integer.toString(i + 1));
            if (val == null) val = "";
            out = out.replace("___" + key + "___", val);
            out = out.replace("[[" + key + "]]", val);
        }
        return out;
    }
}

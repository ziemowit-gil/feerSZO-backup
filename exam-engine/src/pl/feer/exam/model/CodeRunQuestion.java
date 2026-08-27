package pl.feer.exam.model;

import pl.feer.exam.Json;

/**
 * Krótkie zadanie programistyczne — kod kursanta jest uruchamiany w piaskownicy
 * i sprawdzany na zestawach danych wejście/wyjście.
 *
 *   config = {
 *     "language":"python", "starter":"# tu wpisz rozwiązanie",
 *     "timeLimitMs":3000, "memoryMb":128,
 *     "allOrNothing":false,
 *     "forbidden":["import\\s+socket"], "required":["def\\s+"]
 *   }
 * Przypadki testowe siedzą w `cases`; ukryte nie pokazują kursantowi
 * ani wejścia, ani oczekiwanego wyjścia.
 */
public final class CodeRunQuestion extends Question {

    @Override
    public boolean optionsShufflable() { return false; }

    @Override
    public QuestionResult grade(Answer answer, GradingContext ctx) {
        QuestionResult r = result();
        String lang = Json.str(config, "language", "python");
        r.details.put("language", lang);
        CodeGrading.Outcome o = CodeGrading.runCases(this, lang, answer.code(), cases, ctx);
        CodeGrading.apply(o, this, r);
        return r;
    }
}

package pl.feer.exam.model;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/**
 * Pytanie egzaminacyjne — wspólna podstawa wszystkich typów.
 *
 * Definicja pytania przychodzi z PHP jako JSON; pola stałe (id, treść, punkty,
 * warianty, przypadki testowe) są odwzorowane wprost, a specyfika typu siedzi
 * w mapie `config`, którą interpretuje dana podklasa.
 */
public abstract class Question {

    // Typy pytań — muszą odpowiadać stałej K30_TI_EXAM_TYPES po stronie PHP.
    public static final String T_SINGLE          = "single";
    public static final String T_MULTI           = "multi";
    public static final String T_TRUEFALSE       = "truefalse";
    public static final String T_FILL_BLANK      = "fill_blank";
    public static final String T_SHORT_ANSWER    = "short_answer";
    public static final String T_CODE_FIX        = "code_fix";
    public static final String T_CODE_COMPLETION = "code_completion";
    public static final String T_CODE_RUN        = "code_run";

    public long   id;
    public String type     = "";
    public int    position;
    public double points   = 1.0;
    public boolean inBank;
    public String prompt      = "";
    public String explanation = "";

    public final List<Option>   options = new ArrayList<Option>();
    public final List<TestCase> cases   = new ArrayList<TestCase>();
    public Map<String, Object>  config  = new LinkedHashMap<String, Object>();

    /** Ocena odpowiedzi kursanta. Implementacja nie może rzucać — błędy zamienia w 0 pkt + komunikat. */
    public abstract QuestionResult grade(Answer answer, GradingContext ctx);

    /** Czy warianty tego pytania wolno tasować (dla prawda/fałsz kolejność twierdzeń też wolno). */
    public boolean optionsShufflable() { return true; }

    /** Poprawna odpowiedź w formie do pokazania kursantowi (tryb treningowy / po oddaniu). */
    public Map<String, Object> correctAnswerJson() {
        return new LinkedHashMap<String, Object>();
    }

    protected QuestionResult result() {
        QuestionResult r = new QuestionResult(this);
        r.explanation = explanation;
        return r;
    }

    protected List<Option> correctOptions() {
        List<Option> out = new ArrayList<Option>();
        for (Option o : options) if (o.correct) out.add(o);
        return out;
    }

    // ── Budowa z JSON ─────────────────────────────────────────────────────────

    public static Question fromJson(Map<String, Object> m) {
        String type = Json.str(m, "type", T_SINGLE);
        Question q = instantiate(type);
        q.id          = Json.id(m, "id", 0);
        q.type        = type;
        q.position    = Json.integer(m, "position", 0);
        q.points      = Json.num(m, "points", 1.0);
        if (q.points < 0) q.points = 0;
        q.inBank      = Json.bool(m, "inBank", false);
        q.prompt      = Json.str(m, "prompt", "");
        q.explanation = Json.str(m, "explanation", "");
        q.config      = Json.map(m, "config");

        int i = 0;
        for (Object o : Json.list(m, "options")) {
            Map<String, Object> om = Json.asMap(o);
            if (!om.containsKey("position")) om.put("position", Integer.valueOf(i));
            q.options.add(Option.fromJson(om));
            i++;
        }
        int c = 0;
        for (Object o : Json.list(m, "cases")) {
            q.cases.add(TestCase.fromJson(Json.asMap(o), c));
            c++;
        }
        return q;
    }

    private static Question instantiate(String type) {
        if (T_MULTI.equals(type))           return new MultipleChoiceQuestion();
        if (T_TRUEFALSE.equals(type))       return new TrueFalseQuestion();
        if (T_FILL_BLANK.equals(type))      return new FillBlankQuestion();
        if (T_SHORT_ANSWER.equals(type))    return new ShortAnswerQuestion();
        if (T_CODE_FIX.equals(type))        return new CodeFixQuestion();
        if (T_CODE_COMPLETION.equals(type)) return new CodeCompletionQuestion();
        if (T_CODE_RUN.equals(type))        return new CodeRunQuestion();
        return new SingleChoiceQuestion();
    }
}

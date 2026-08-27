package pl.feer.exam.authoring;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;
import pl.feer.exam.model.GradingContext;

/**
 * Logika autorska ustawień egzaminu: formuła testu narzuca sensowne wartości
 * domyślne i pilnuje, żeby ustawienia nie przeczyły sobie nawzajem.
 *
 * Wejście:  { "form": { …pola formularza prowadzącego… } }
 * Wyjście:  { "ok":bool, "errors":[…], "warnings":[…], "exam":{ …kanoniczne pola… } }
 *
 * Zwrócony obiekt `exam` odpowiada jeden do jednego kolumnom tabeli
 * k30_ti_exams — PHP tylko go zapisuje.
 */
public final class ExamAuthoring {

    private ExamAuthoring() { }

    public static final String MODE_EXAM     = GradingContext.MODE_EXAM;
    public static final String MODE_QUIZ     = GradingContext.MODE_QUIZ;
    public static final String MODE_TRAINING = GradingContext.MODE_TRAINING;

    private static final List<String> FEEDBACK = List.of("never", "after_submit", "immediate");

    public static Map<String, Object> normalize(Map<String, Object> req) {
        Map<String, Object> f = Json.map(req, "form");
        List<Object> errors   = new ArrayList<Object>();
        List<Object> warnings = new ArrayList<Object>();

        String mode = Json.str(f, "mode", MODE_EXAM);
        if (!mode.equals(MODE_EXAM) && !mode.equals(MODE_QUIZ) && !mode.equals(MODE_TRAINING)) mode = MODE_EXAM;

        String title = Json.str(f, "title", "").trim();
        if (title.isEmpty()) errors.add(Forms.error("title", "Podaj tytuł egzaminu."));

        int timeLimit   = Forms.clampInt(Forms.decimal(f, "timeLimitMin", defaultTimeLimit(mode)), 0, 600);
        int passPct     = Forms.clampInt(Forms.decimal(f, "passPct", 0), 0, 100);
        int maxAttempts = Forms.clampInt(Forms.decimal(f, "maxAttempts", defaultAttempts(mode)), 0, 50);
        int gradeWeight = Forms.clampInt(Forms.decimal(f, "gradeWeight", 3), 1, 10);
        int fixedDraw   = Forms.clampInt(Forms.decimal(f, "fixedDraw", 0), 0, 500);
        int bankDraw    = Forms.clampInt(Forms.decimal(f, "bankDraw", 0), 0, 500);

        String feedback = Json.str(f, "showFeedback", defaultFeedback(mode));
        if (!FEEDBACK.contains(feedback)) feedback = defaultFeedback(mode);

        String neg = Json.str(f, "negMarking", GradingContext.NEG_PARTIAL);
        if (!neg.equals(GradingContext.NEG_PARTIAL)
         && !neg.equals(GradingContext.NEG_NONE)
         && !neg.equals(GradingContext.NEG_ALL)) neg = GradingContext.NEG_PARTIAL;

        // ── Reguły spójności formuły ────────────────────────────────────────
        if (MODE_EXAM.equals(mode)) {
            if (maxAttempts != 1) {
                warnings.add(Forms.error("maxAttempts",
                    "Kolokwium zwykle daje jedno podejście — ustawiono " + describeAttempts(maxAttempts) + "."));
            }
            if ("immediate".equals(feedback)) {
                errors.add(Forms.error("showFeedback",
                    "W kolokwium nie wolno pokazywać poprawnych odpowiedzi w trakcie rozwiązywania."));
                feedback = "never";
            }
        }
        if (MODE_QUIZ.equals(mode)) {
            if (timeLimit == 0) {
                warnings.add(Forms.error("timeLimitMin", "Kartkówka bez limitu czasu traci sens — rozważ 5–10 minut."));
            }
            if (timeLimit > 20) {
                warnings.add(Forms.error("timeLimitMin", "To już nie jest kartkówka — przy tym limicie wybierz formułę kolokwium."));
            }
        }
        if (MODE_TRAINING.equals(mode)) {
            // Tryb treningowy z natury pokazuje wynik od razu i nie ogranicza podejść.
            feedback    = "immediate";
            maxAttempts = 0;
            if (timeLimit > 0) {
                warnings.add(Forms.error("timeLimitMin", "W trybie treningowym limit czasu zwykle przeszkadza."));
            }
        }

        String openAt  = Json.str(f, "openAt", "").trim();
        String closeAt = Json.str(f, "closeAt", "").trim();
        if (!openAt.isEmpty() && !closeAt.isEmpty() && closeAt.compareTo(openAt) < 0) {
            errors.add(Forms.error("closeAt", "Termin zamknięcia wypada przed terminem otwarcia."));
        }

        boolean active = Json.bool(f, "isActive", false);
        if (active && Json.integer(f, "questionCount", 0) == 0) {
            errors.add(Forms.error("isActive", "Nie da się udostępnić egzaminu bez pytań."));
            active = false;
        }
        if (Json.bool(f, "syncGrade", false) && passPct == 0) {
            warnings.add(Forms.error("passPct",
                "Bez progu zaliczenia ocena w dzienniku powstaje wyłącznie z procentów."));
        }

        Map<String, Object> exam = new LinkedHashMap<String, Object>();
        exam.put("title",            title);
        exam.put("description",      Json.str(f, "description", "").trim());
        exam.put("mode",             mode);
        exam.put("timeLimitMin",     Integer.valueOf(timeLimit));
        exam.put("passPct",          Integer.valueOf(passPct));
        exam.put("maxAttempts",      Integer.valueOf(maxAttempts));
        exam.put("shuffleQuestions", Boolean.valueOf(Json.bool(f, "shuffleQuestions", MODE_TRAINING.equals(mode) ? false : true)));
        exam.put("shuffleOptions",   Boolean.valueOf(Json.bool(f, "shuffleOptions",   MODE_TRAINING.equals(mode) ? false : true)));
        exam.put("fixedDraw",        Integer.valueOf(fixedDraw));
        exam.put("bankDraw",         Integer.valueOf(bankDraw));
        exam.put("showFeedback",     feedback);
        exam.put("negMarking",       neg);
        exam.put("openAt",           openAt);
        exam.put("closeAt",          closeAt);
        exam.put("isActive",         Boolean.valueOf(active));
        exam.put("syncGrade",        Boolean.valueOf(Json.bool(f, "syncGrade", false)));
        exam.put("gradeWeight",      Integer.valueOf(gradeWeight));
        exam.put("sessionId",        Integer.valueOf(Forms.clampInt(Forms.decimal(f, "sessionId", 0), 0, Integer.MAX_VALUE)));

        Map<String, Object> out = new LinkedHashMap<String, Object>();
        out.put("ok",       Boolean.valueOf(errors.isEmpty()));
        out.put("errors",   errors);
        out.put("warnings", warnings);
        out.put("exam",     exam);
        return out;
    }

    private static String describeAttempts(int n) {
        return n == 0 ? "brak limitu" : (n + (n == 1 ? " podejście" : " podejścia/podejść"));
    }

    private static int defaultTimeLimit(String mode) {
        if (MODE_QUIZ.equals(mode))     return 5;
        if (MODE_TRAINING.equals(mode)) return 0;
        return 45;
    }

    private static int defaultAttempts(String mode) {
        return MODE_TRAINING.equals(mode) ? 0 : 1;
    }

    private static String defaultFeedback(String mode) {
        if (MODE_TRAINING.equals(mode)) return "immediate";
        if (MODE_QUIZ.equals(mode))     return "after_submit";
        return "never";
    }

    /** Opis formuł dla interfejsu prowadzącego. */
    public static List<Object> modes() {
        List<Object> out = new ArrayList<Object>();
        out.add(Json.obj("id", MODE_EXAM, "label", "Kolokwium / egzamin", "icon", "mortarboard",
            "hint", "Ograniczony czasowo, jedno podejście, losowa kolejność pytań i wariantów, bez podpowiedzi."));
        out.add(Json.obj("id", MODE_QUIZ, "label", "Wejściówka / kartkówka", "icon", "lightning-charge",
            "hint", "Krótki zestaw 3–5 pytań z ostrym limitem czasu."));
        out.add(Json.obj("id", MODE_TRAINING, "label", "Tryb treningowy", "icon", "arrow-repeat",
            "hint", "Bez limitu czasu; po każdej odpowiedzi wynik i wyjaśnienie, dowolna liczba podejść."));
        return out;
    }
}

package pl.feer.exam.authoring;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Pattern;
import java.util.regex.PatternSyntaxException;

import pl.feer.exam.Json;
import pl.feer.exam.model.GradingContext;
import pl.feer.exam.model.Question;
import pl.feer.exam.sandbox.Language;

/**
 * Logika autorska panelu prowadzącego: z surowego formularza buduje kanoniczną
 * definicję pytania i sprawdza jej sensowność.
 *
 * Reguły „co jest poprawnym pytaniem" mieszkają wyłącznie tutaj — PHP nie
 * decyduje o niczym, tylko przekazuje pola formularza i zapisuje to, co wróci.
 * Dzięki temu model pytania ma jedno źródło prawdy: klasy z pakietu
 * pl.feer.exam.model razem z tą walidacją.
 *
 * Wejście:
 *   { "type":"code_run", "prompt":"…", "points":"2", "inBank":false,
 *     "explanation":"…", "form": { …pola formularza… } }
 *
 * Wyjście:
 *   { "ok":true, "errors":[], "warnings":[],
 *     "question": { type, prompt, points, inBank, explanation, config, options, cases } }
 */
public final class QuestionAuthoring {

    private QuestionAuthoring() { }

    public static Map<String, Object> build(Map<String, Object> req) {
        String type = Json.str(req, "type", Question.T_SINGLE);
        if (!K_TYPES.contains(type)) type = Question.T_SINGLE;

        Map<String, Object> form   = Json.map(req, "form");
        List<Object> errors        = new ArrayList<Object>();
        List<Object> warnings      = new ArrayList<Object>();

        String prompt = Json.str(req, "prompt", "").trim();
        if (prompt.isEmpty()) errors.add(Forms.error("prompt", "Treść polecenia jest wymagana."));

        double points = Forms.decimal(req, "points", 1.0);
        if (points <= 0) {
            errors.add(Forms.error("points", "Liczba punktów musi być większa od zera."));
            points = 1.0;
        }
        points = Forms.clamp(points, 0.01, 1000);

        Map<String, Object> config  = new LinkedHashMap<String, Object>();
        List<Object>        options = new ArrayList<Object>();
        List<Object>        cases   = new ArrayList<Object>();

        readOptions(form, options);
        readCases(form, cases);

        switch (type) {
            case Question.T_SINGLE:          single(options, errors);                       break;
            case Question.T_MULTI:           multi(form, config, options, errors);          break;
            case Question.T_TRUEFALSE:       trueFalse(form, config, options, errors);      break;
            case Question.T_FILL_BLANK:      fillBlank(form, config, prompt, errors, warnings); break;
            case Question.T_SHORT_ANSWER:    shortAnswer(form, config, errors, warnings);   break;
            case Question.T_CODE_FIX:        codeFix(form, config, cases, errors, warnings);          break;
            case Question.T_CODE_COMPLETION: codeCompletion(form, config, cases, errors, warnings);   break;
            case Question.T_CODE_RUN:        codeRun(form, config, cases, errors, warnings);          break;
            default: break;
        }

        // Warianty i przypadki mają sens tylko tam, gdzie typ ich używa —
        // resztę odrzucamy, żeby w bazie nie zostawały sieroty po zmianie typu.
        Map<String, Object> meta = TYPE_META.get(type);
        if (!Json.bool(meta, "options", false)) options.clear();
        if (!Json.bool(meta, "cases", false))   cases.clear();

        Map<String, Object> question = new LinkedHashMap<String, Object>();
        question.put("type",        type);
        question.put("prompt",      prompt);
        question.put("points",      Double.valueOf(points));
        question.put("inBank",      Boolean.valueOf(Json.bool(req, "inBank", false)));
        question.put("explanation", Json.str(req, "explanation", "").trim());
        question.put("config",      config);
        question.put("options",     options);
        question.put("cases",       cases);

        Map<String, Object> out = new LinkedHashMap<String, Object>();
        out.put("ok",       Boolean.valueOf(errors.isEmpty()));
        out.put("errors",   errors);
        out.put("warnings", warnings);
        out.put("question", question);
        return out;
    }

    // ── Wspólne części formularza ─────────────────────────────────────────────

    private static void readOptions(Map<String, Object> form, List<Object> out) {
        for (Object o : Json.list(form, "options")) {
            Map<String, Object> m = Json.asMap(o);
            String label = Json.str(m, "label", "").trim();
            if (label.isEmpty()) continue;
            out.add(Json.obj(
                "label",    label,
                "correct",  Boolean.valueOf(Json.bool(m, "correct", false)),
                "feedback", Json.str(m, "feedback", "").trim()));
        }
    }

    private static void readCases(Map<String, Object> form, List<Object> out) {
        int i = 0;
        for (Object o : Json.list(form, "cases")) {
            Map<String, Object> m = Json.asMap(o);
            String expected = Json.str(m, "expected", "");
            String stdin    = Json.str(m, "stdin", "");
            if (expected.trim().isEmpty() && stdin.trim().isEmpty()) continue;
            String mode = Json.str(m, "matchMode", "trim");
            if (!MATCH_MODES.contains(mode)) mode = "trim";
            String name = Json.str(m, "name", "").trim();
            if (name.isEmpty()) name = "Przypadek " + (i + 1);
            out.add(Json.obj(
                "name",      name,
                "stdin",     stdin,
                "expected",  expected,
                "matchMode", mode,
                "weight",    Double.valueOf(Forms.clamp(Forms.decimal(m, "weight", 1), 0.01, 1000)),
                "hidden",    Boolean.valueOf(Json.bool(m, "hidden", false)),
                "tolerance", Double.valueOf(Forms.clamp(Forms.decimal(m, "tolerance", 1e-6), 0, 1))));
            i++;
        }
    }

    private static void normalization(Map<String, Object> form, Map<String, Object> config) {
        config.put("caseSensitive",  Boolean.valueOf(Json.bool(form, "caseSensitive", false)));
        config.put("ignoreAccents",  Boolean.valueOf(Json.bool(form, "ignoreAccents", false)));
        config.put("collapseSpaces", Boolean.TRUE);
        config.put("trim",           Boolean.TRUE);
    }

    private static void negMarking(Map<String, Object> form, Map<String, Object> config) {
        String neg = Json.str(form, "negMarking", "").trim();
        if (neg.equals(GradingContext.NEG_PARTIAL)
         || neg.equals(GradingContext.NEG_NONE)
         || neg.equals(GradingContext.NEG_ALL)) {
            config.put("negMarking", neg);
        }
    }

    private static int countCorrect(List<Object> options) {
        int n = 0;
        for (Object o : options) if (Json.bool(Json.asMap(o), "correct", false)) n++;
        return n;
    }

    private static String language(Map<String, Object> form, Map<String, Object> config, List<Object> errors) {
        String lang = Json.str(form, "language", "python").trim();
        if (Language.byId(lang) == null) {
            errors.add(Forms.error("language", "Nieobsługiwany język zadania: " + lang));
            lang = "python";
        }
        config.put("language", lang);
        return lang;
    }

    private static void runLimits(Map<String, Object> form, Map<String, Object> config, List<Object> warnings) {
        config.put("timeLimitMs", Integer.valueOf(Forms.clampInt(Forms.decimal(form, "timeLimitMs", 3000), 200, 15000)));
        config.put("memoryMb",    Integer.valueOf(Forms.clampInt(Forms.decimal(form, "memoryMb", 128), 32, 512)));
        config.put("forbidden",   Forms.lines(form, "forbidden"));
        config.put("required",    Forms.lines(form, "required"));
        config.put("allOrNothing", Boolean.valueOf(Json.bool(form, "allOrNothing", false)));
        config.put("starter",     Json.str(form, "starter", ""));
        checkPatterns(config, warnings);
    }

    private static void checkPatterns(Map<String, Object> config, List<Object> warnings) {
        for (String key : new String[] { "forbidden", "required" }) {
            for (Object o : Json.list(config, key)) {
                String p = String.valueOf(o);
                try { Pattern.compile(p); }
                catch (PatternSyntaxException e) {
                    warnings.add(Forms.error(key, "Wzorzec „" + p + "” nie jest poprawnym wyrażeniem regularnym — zadziała jak zwykły tekst."));
                }
            }
        }
    }

    // ── Reguły poszczególnych typów ───────────────────────────────────────────

    private static void single(List<Object> options, List<Object> errors) {
        if (options.size() < 2) errors.add(Forms.error("options", "Podaj co najmniej dwa warianty odpowiedzi."));
        int c = countCorrect(options);
        if (c == 0) errors.add(Forms.error("options", "Zaznacz wariant poprawny."));
        if (c > 1)  errors.add(Forms.error("options", "Pytanie jednokrotnego wyboru może mieć tylko jeden poprawny wariant."));
    }

    private static void multi(Map<String, Object> form, Map<String, Object> config,
                              List<Object> options, List<Object> errors) {
        negMarking(form, config);
        if (options.size() < 2) errors.add(Forms.error("options", "Podaj co najmniej dwa warianty odpowiedzi."));
        if (countCorrect(options) == 0) errors.add(Forms.error("options", "Zaznacz przynajmniej jeden poprawny wariant."));
        if (countCorrect(options) == options.size() && options.size() > 1) {
            errors.add(Forms.error("options", "Wszystkie warianty są poprawne — pytanie nie rozróżnia odpowiedzi."));
        }
    }

    private static void trueFalse(Map<String, Object> form, Map<String, Object> config,
                                  List<Object> options, List<Object> errors) {
        negMarking(form, config);
        if (options.size() < 2) errors.add(Forms.error("options", "Podaj co najmniej dwa twierdzenia do oceny."));
        // Tu „correct" znaczy „twierdzenie prawdziwe", więc komplet zaznaczeń
        // jest dopuszczalny — walidujemy tylko liczbę twierdzeń.
    }

    private static void fillBlank(Map<String, Object> form, Map<String, Object> config,
                                  String prompt, List<Object> errors, List<Object> warnings) {
        normalization(form, config);
        config.put("regex",        Boolean.valueOf(Json.bool(form, "regex", false)));
        config.put("allOrNothing", Boolean.valueOf(Json.bool(form, "allOrNothing", false)));

        List<Object> blanks = readBlanks(form, errors);
        config.put("blanks", blanks);
        if (blanks.isEmpty()) {
            errors.add(Forms.error("blanks", "Zdefiniuj przynajmniej jedną lukę."));
            return;
        }
        for (Object o : blanks) {
            String key = Json.str(Json.asMap(o), "key", "");
            if (!prompt.contains("[[" + key + "]]")) {
                warnings.add(Forms.error("prompt",
                    "Treść nie zawiera znacznika [[" + key + "]] — kursant nie zobaczy pola dla tej luki w tekście."));
            }
        }
    }

    private static List<Object> readBlanks(Map<String, Object> form, List<Object> errors) {
        List<Object> out = new ArrayList<Object>();
        List<String> keys = new ArrayList<String>();
        int i = 0;
        for (Object o : Json.list(form, "blanks")) {
            Map<String, Object> m = Json.asMap(o);
            String key = Json.str(m, "key", "").trim();
            if (key.isEmpty()) key = Integer.toString(i + 1);
            List<String> accept = Forms.lines(m, "accept");
            if (accept.isEmpty()) { i++; continue; }
            if (keys.contains(key)) {
                errors.add(Forms.error("blanks", "Klucz luki „" + key + "” powtarza się."));
                i++;
                continue;
            }
            keys.add(key);
            out.add(Json.obj(
                "key",    key,
                "accept", accept,
                "hint",   Json.str(m, "hint", "").trim(),
                "points", Double.valueOf(Forms.clamp(Forms.decimal(m, "points", 1), 0.01, 1000))));
            i++;
        }
        return out;
    }

    private static void shortAnswer(Map<String, Object> form, Map<String, Object> config,
                                    List<Object> errors, List<Object> warnings) {
        normalization(form, config);
        boolean manual = Json.bool(form, "manual", false);
        config.put("manual",   Boolean.valueOf(manual));
        config.put("minChars", Integer.valueOf(Forms.clampInt(Forms.decimal(form, "minChars", 0), 0, 10000)));

        List<Object> crit = new ArrayList<Object>();
        for (Object o : Json.list(form, "criteria")) {
            Map<String, Object> m = Json.asMap(o);
            List<String> any = Forms.lines(m, "any");
            if (any.isEmpty()) continue;
            String label = Json.str(m, "label", "").trim();
            if (label.isEmpty()) label = any.get(0);
            crit.add(Json.obj(
                "label",    label,
                "any",      any,
                "points",   Double.valueOf(Forms.clamp(Forms.decimal(m, "points", 1), 0.01, 1000)),
                "required", Boolean.valueOf(Json.bool(m, "required", false))));
        }
        config.put("keywords", crit);

        if (crit.isEmpty() && !manual) {
            errors.add(Forms.error("criteria",
                "Bez kryteriów słów kluczowych zaznacz „ocena prowadzącego” — inaczej pytania nie da się ocenić."));
        }
        if (!crit.isEmpty() && !manual) {
            warnings.add(Forms.error("criteria",
                "Ocena po słowach kluczowych bywa zawodna przy odpowiedziach opisowych — rozważ kontrolę ręczną."));
        }
    }

    private static void codeFix(Map<String, Object> form, Map<String, Object> config,
                                List<Object> cases, List<Object> errors, List<Object> warnings) {
        language(form, config, errors);
        String snippet = Json.str(form, "snippet", "");
        config.put("snippet", snippet);
        if (snippet.trim().isEmpty()) errors.add(Forms.error("snippet", "Wklej fragment kodu do analizy."));

        boolean rewrite = "rewrite".equals(Json.str(form, "answerMode", "line"));
        config.put("answerMode", rewrite ? "rewrite" : "line");

        if (rewrite) {
            runLimits(form, config, warnings);
            if (cases.isEmpty()) {
                errors.add(Forms.error("cases", "W trybie przepisania kodu potrzebny jest choć jeden przypadek testowy."));
            }
            return;
        }

        config.put("requireExplanation", Boolean.valueOf(Json.bool(form, "requireExplanation", false)));
        List<Object> lines = Forms.intList(form, "acceptLines");
        config.put("acceptLines", lines);
        if (lines.isEmpty()) {
            errors.add(Forms.error("acceptLines", "Podaj numer linii zawierającej błąd."));
            return;
        }
        int total = snippet.isEmpty() ? 0 : snippet.split("\\R", -1).length;
        for (Object o : lines) {
            int n = ((Integer) o).intValue();
            if (total > 0 && n > total) {
                errors.add(Forms.error("acceptLines",
                    "Linia " + n + " wykracza poza fragment, który ma tylko " + total + " linii."));
            }
        }
    }

    private static void codeCompletion(Map<String, Object> form, Map<String, Object> config,
                                       List<Object> cases, List<Object> errors, List<Object> warnings) {
        normalization(form, config);
        language(form, config, errors);
        config.put("regex",        Boolean.valueOf(Json.bool(form, "regex", false)));
        config.put("allOrNothing", Boolean.valueOf(Json.bool(form, "allOrNothing", false)));

        String template = Json.str(form, "template", "");
        config.put("template", template);
        if (template.trim().isEmpty()) errors.add(Forms.error("template", "Wklej szablon kodu z lukami."));

        boolean run = Json.bool(form, "runAfterFill", false);
        config.put("runAfterFill", Boolean.valueOf(run));
        if (run) runLimits(form, config, warnings);

        List<Object> blanks = readBlanks(form, errors);
        config.put("blanks", blanks);

        if (blanks.isEmpty() && !run) {
            errors.add(Forms.error("blanks", "Zdefiniuj luki albo włącz sprawdzanie uruchomieniowe."));
        }
        if (run && cases.isEmpty()) {
            errors.add(Forms.error("cases", "Sprawdzanie uruchomieniowe wymaga przypadków testowych."));
        }
        for (Object o : blanks) {
            String key = Json.str(Json.asMap(o), "key", "");
            if (!template.contains("___" + key + "___") && !template.contains("[[" + key + "]]")) {
                errors.add(Forms.error("template",
                    "Szablon nie zawiera znacznika ___" + key + "___ dla zdefiniowanej luki."));
            }
        }
    }

    private static void codeRun(Map<String, Object> form, Map<String, Object> config,
                                List<Object> cases, List<Object> errors, List<Object> warnings) {
        language(form, config, errors);
        runLimits(form, config, warnings);
        if (cases.isEmpty()) {
            errors.add(Forms.error("cases", "Dodaj przynajmniej jeden przypadek testowy wejście/wyjście."));
            return;
        }
        boolean anyVisible = false;
        for (Object o : cases) if (!Json.bool(Json.asMap(o), "hidden", false)) anyVisible = true;
        if (!anyVisible) {
            errors.add(Forms.error("cases",
                "Zostaw przynajmniej jeden przypadek jawny — kursant musi widzieć, czego dotyczy zadanie."));
        }
    }

    // ── Metadane typów (kopia słownika dla walidacji) ──────────────────────────

    private static final List<String> K_TYPES = List.of(
        Question.T_SINGLE, Question.T_MULTI, Question.T_TRUEFALSE, Question.T_FILL_BLANK,
        Question.T_SHORT_ANSWER, Question.T_CODE_FIX, Question.T_CODE_COMPLETION, Question.T_CODE_RUN);

    private static final List<String> MATCH_MODES = List.of("trim", "exact", "tokens", "numeric", "regex");

    private static final Map<String, Map<String, Object>> TYPE_META = buildTypeMeta();

    private static Map<String, Map<String, Object>> buildTypeMeta() {
        Map<String, Map<String, Object>> m = new LinkedHashMap<String, Map<String, Object>>();
        m.put(Question.T_SINGLE,          Json.obj("options", Boolean.TRUE,  "cases", Boolean.FALSE));
        m.put(Question.T_MULTI,           Json.obj("options", Boolean.TRUE,  "cases", Boolean.FALSE));
        m.put(Question.T_TRUEFALSE,       Json.obj("options", Boolean.TRUE,  "cases", Boolean.FALSE));
        m.put(Question.T_FILL_BLANK,      Json.obj("options", Boolean.FALSE, "cases", Boolean.FALSE));
        m.put(Question.T_SHORT_ANSWER,    Json.obj("options", Boolean.FALSE, "cases", Boolean.FALSE));
        m.put(Question.T_CODE_FIX,        Json.obj("options", Boolean.FALSE, "cases", Boolean.TRUE));
        m.put(Question.T_CODE_COMPLETION, Json.obj("options", Boolean.FALSE, "cases", Boolean.TRUE));
        m.put(Question.T_CODE_RUN,        Json.obj("options", Boolean.FALSE, "cases", Boolean.TRUE));
        return m;
    }

    /** Słownik typów dla interfejsu prowadzącego — źródło etykiet w panelu PHP. */
    public static List<Object> catalogue() {
        List<Object> out = new ArrayList<Object>();
        out.add(t(Question.T_SINGLE,          "Jednokrotny wybór",        "record-circle",     "Klasyczne ABC — dokładnie jedna odpowiedź poprawna."));
        out.add(t(Question.T_MULTI,           "Wielokrotny wybór",        "check2-square",     "Kilka poprawnych wariantów; punktacja z karą za zgadywanie."));
        out.add(t(Question.T_TRUEFALSE,       "Prawda / Fałsz",           "toggles",           "Zestaw twierdzeń — kursant ocenia każde osobno."));
        out.add(t(Question.T_FILL_BLANK,      "Pytanie z luką",           "input-cursor-text", "Wpisanie brakującego słowa kluczowego lub pojęcia."));
        out.add(t(Question.T_SHORT_ANSWER,    "Krótka odpowiedź",         "chat-left-text",    "Odpowiedź opisowa — automat po słowach kluczowych albo ocena prowadzącego."));
        out.add(t(Question.T_CODE_FIX,        "Analiza / poprawa kodu",   "bug",               "Fragment kodu z błędem — wskazanie linii albo przepisanie poprawnie."));
        out.add(t(Question.T_CODE_COMPLETION, "Luki w kodzie",            "braces",            "Uzupełnienie brakujących słów kluczowych i konstrukcji składniowych."));
        out.add(t(Question.T_CODE_RUN,        "Zadanie programistyczne",  "terminal",          "Kod uruchamiany w piaskownicy i sprawdzany na danych wejście/wyjście."));
        return out;
    }

    private static Map<String, Object> t(String id, String label, String icon, String hint) {
        Map<String, Object> meta = TYPE_META.get(id);
        return Json.obj("id", id, "label", label, "icon", icon, "hint", hint,
                        "options", meta.get("options"), "cases", meta.get("cases"));
    }
}

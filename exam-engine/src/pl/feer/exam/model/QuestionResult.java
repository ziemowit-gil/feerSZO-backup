package pl.feer.exam.model;

import java.util.LinkedHashMap;
import java.util.Map;

/** Wynik oceny jednego pytania. */
public final class QuestionResult {

    public long    questionId;
    public String  type        = "";
    public double  points;
    public double  maxPoints;
    public boolean correct;
    /** true = automat nie rozstrzyga, potrzebna ocena prowadzącego. */
    public boolean needsReview;
    /** Krótki komunikat dla kursanta (pokazywany zależnie od trybu testu). */
    public String  feedback    = "";
    /** Wyjaśnienie prowadzącego — tylko w trybie treningowym / po oddaniu. */
    public String  explanation = "";
    /** Szczegóły per typ (poprawne warianty, wyniki przypadków testowych, …). */
    public final Map<String, Object> details = new LinkedHashMap<String, Object>();

    public QuestionResult(Question q) {
        this.questionId = q.id;
        this.type       = q.type;
        this.maxPoints  = q.points;
    }

    /** Przycina punktację do przedziału [0, maxPoints] — chroni przed błędem konfiguracji pytania. */
    public QuestionResult award(double raw) {
        double v = raw;
        if (Double.isNaN(v) || v < 0) v = 0;
        if (v > maxPoints) v = maxPoints;
        this.points  = Math.round(v * 1000.0) / 1000.0;
        this.correct = maxPoints > 0 && this.points >= maxPoints - 1e-9;
        return this;
    }

    public QuestionResult withFeedback(String f) {
        this.feedback = f == null ? "" : f;
        return this;
    }

    public Map<String, Object> toJson() {
        Map<String, Object> m = new LinkedHashMap<String, Object>();
        m.put("questionId",  Long.valueOf(questionId));
        m.put("type",        type);
        m.put("points",      Double.valueOf(points));
        m.put("maxPoints",   Double.valueOf(maxPoints));
        m.put("correct",     Boolean.valueOf(correct));
        m.put("needsReview", Boolean.valueOf(needsReview));
        m.put("feedback",    feedback);
        m.put("explanation", explanation);
        m.put("details",     details);
        return m;
    }
}

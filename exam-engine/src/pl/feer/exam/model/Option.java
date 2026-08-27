package pl.feer.exam.model;

import java.util.Map;

import pl.feer.exam.Json;

/** Wariant odpowiedzi (ABC) albo twierdzenie w pytaniu prawda/fałsz. */
public final class Option {

    public final long    id;
    public final String  label;
    /** Dla single/multi: wariant poprawny. Dla prawda/fałsz: twierdzenie prawdziwe. */
    public final boolean correct;
    public final String  feedback;

    public Option(long id, String label, boolean correct, String feedback) {
        this.id       = id;
        this.label    = label == null ? "" : label;
        this.correct  = correct;
        this.feedback = feedback == null ? "" : feedback;
    }

    public static Option fromJson(Map<String, Object> m) {
        return new Option(
            Json.id(m, "id", 0),
            Json.str(m, "label", ""),
            Json.bool(m, "isCorrect", Json.bool(m, "correct", false)),
            Json.str(m, "feedback", "")
        );
    }
}

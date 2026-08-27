package pl.feer.exam.model;

import pl.feer.exam.sandbox.Sandbox;

/**
 * Warunki oceniania wspólne dla całego podejścia.
 *
 * mode:
 *   exam     — kolokwium/egzamin: bez podpowiedzi w trakcie, jedno podejście,
 *   quiz     — wejściówka/kartkówka: krótka, ostry limit czasu,
 *   training — tryb treningowy: po każdej odpowiedzi wynik + wyjaśnienie.
 *
 * negMarking (punktacja pytań wielokrotnego wyboru i prawda/fałsz):
 *   partial         — (trafione − błędne)/wszystkie poprawne, obcięte do zera;
 *                     zgadywanie „zaznaczam wszystko" nie daje punktów,
 *   none            — trafione/wszystkie poprawne, bez kary,
 *   all_or_nothing  — punkty tylko za komplet.
 */
public final class GradingContext {

    public static final String MODE_EXAM     = "exam";
    public static final String MODE_QUIZ     = "quiz";
    public static final String MODE_TRAINING = "training";

    public static final String NEG_PARTIAL = "partial";
    public static final String NEG_NONE    = "none";
    public static final String NEG_ALL     = "all_or_nothing";

    public final String  mode;
    public final String  negMarking;
    /** Czy dołączać do wyniku wyjaśnienia i poprawne odpowiedzi. */
    public final boolean revealAnswers;
    public final Sandbox sandbox;

    public GradingContext(String mode, String negMarking, boolean revealAnswers, Sandbox sandbox) {
        this.mode          = (mode == null || mode.isEmpty()) ? MODE_EXAM : mode;
        this.negMarking    = (negMarking == null || negMarking.isEmpty()) ? NEG_PARTIAL : negMarking;
        this.revealAnswers = revealAnswers;
        this.sandbox       = sandbox;
    }

    public boolean isTraining() { return MODE_TRAINING.equals(mode); }
}

package pl.feer.exam;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Random;

import pl.feer.exam.model.Option;
import pl.feer.exam.model.Question;

/**
 * Składanie wariantu testu dla konkretnego podejścia.
 *
 * Zestaw egzaminacyjny powstaje z dwóch części:
 *   • pytania stałe   (inBank = false) — wchodzą zawsze, chyba że prowadzący
 *                       ustawił losowanie `fixedDraw` z tej puli,
 *   • bank pytań      (inBank = true)  — losowane jest `bankDraw` pozycji.
 * Dodatkowo można wymieszać kolejność pytań i kolejność wariantów odpowiedzi.
 *
 * Losowanie jest deterministyczne względem ziarna: to samo ziarno daje ten sam
 * wariant, więc PHP zapisuje w podejściu wyłącznie ziarno i wylosowane
 * identyfikatory, a wariant da się odtworzyć przy wglądzie i przy odwołaniu.
 */
public final class ExamGenerator {

    private ExamGenerator() { }

    public static Map<String, Object> generate(Map<String, Object> request) {
        Map<String, Object> bp = Json.map(request, "blueprint");
        long seed = Json.id(request, "seed", System.nanoTime());
        Random rnd = new Random(seed);

        List<Question> fixed = new ArrayList<Question>();
        List<Question> bank  = new ArrayList<Question>();
        Map<Long, Question> byId = new LinkedHashMap<Long, Question>();

        for (Object o : Json.list(request, "questions")) {
            Question q = Question.fromJson(Json.asMap(o));
            byId.put(Long.valueOf(q.id), q);
            if (q.inBank) bank.add(q); else fixed.add(q);
        }

        int fixedDraw = Json.integer(bp, "fixedDraw", 0);
        int bankDraw  = Json.integer(bp, "bankDraw", Json.integer(bp, "drawCount", 0));

        List<Question> chosen = new ArrayList<Question>();
        chosen.addAll(pick(fixed, fixedDraw, rnd));
        chosen.addAll(pick(bank,  bankDraw,  rnd));

        if (Json.bool(bp, "shuffleQuestions", false)) {
            Collections.shuffle(chosen, rnd);
        } else {
            chosen.sort((a, b) -> {
                if (a.position != b.position) return Integer.compare(a.position, b.position);
                return Long.compare(a.id, b.id);
            });
        }

        boolean shuffleOptions = Json.bool(bp, "shuffleOptions", false);
        List<Object> order = new ArrayList<Object>();
        Map<String, Object> optionOrder = new LinkedHashMap<String, Object>();
        double maxScore = 0;

        for (Question q : chosen) {
            order.add(Long.valueOf(q.id));
            maxScore += q.points;
            if (q.options.isEmpty()) continue;
            List<Object> ids = new ArrayList<Object>();
            for (Option op : q.options) ids.add(Long.valueOf(op.id));
            if (shuffleOptions && q.optionsShufflable()) Collections.shuffle(ids, rnd);
            optionOrder.put(Long.toString(q.id), ids);
        }

        Map<String, Object> out = new LinkedHashMap<String, Object>();
        out.put("seed",          Long.valueOf(seed));
        out.put("questionOrder", order);
        out.put("optionOrder",   optionOrder);
        out.put("maxScore",      Double.valueOf(Math.round(maxScore * 1000.0) / 1000.0));
        out.put("count",         Integer.valueOf(order.size()));
        return out;
    }

    /** Zwraca całą pulę gdy `n` <= 0 lub przekracza jej rozmiar; inaczej losowe `n` pozycji. */
    private static List<Question> pick(List<Question> pool, int n, Random rnd) {
        if (n <= 0 || n >= pool.size()) return new ArrayList<Question>(pool);
        List<Question> copy = new ArrayList<Question>(pool);
        Collections.shuffle(copy, rnd);
        return new ArrayList<Question>(copy.subList(0, n));
    }
}

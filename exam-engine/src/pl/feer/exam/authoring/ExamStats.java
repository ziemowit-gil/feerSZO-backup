package pl.feer.exam.authoring;

import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

import pl.feer.exam.Json;

/**
 * Statystyka zestawu dla prowadzącego — odpowiada na pytanie „czy ten egzamin
 * dobrze mierzy" i „które pytania wymagają poprawki".
 *
 * Liczone miary:
 *   • łatwość pytania (p) — średni ułamek zdobytych punktów; wartości bliskie 1
 *     znaczą pytanie zbyt łatwe, bliskie 0 — zbyt trudne albo źle sformułowane,
 *   • moc różnicująca (D) — różnica łatwości między najlepszą i najsłabszą
 *     ćwiartką podejść; poniżej 0,2 pytanie nie odróżnia poziomów, poniżej 0
 *     działa odwrotnie do zamierzenia (typowy objaw błędu w kluczu),
 *   • rozkład wyników i odsetek zaliczeń dla całego zestawu.
 *
 * Wejście:
 *   { "passPct":60,
 *     "questions":[{"id":1,"prompt":"…","type":"single","points":2}],
 *     "attempts":[{"score":8,"maxScore":10,"status":"graded",
 *                  "answers":[{"questionId":1,"points":2,"maxPoints":2}]}] }
 */
public final class ExamStats {

    private ExamStats() { }

    public static Map<String, Object> compute(Map<String, Object> req) {
        double passPct = Json.num(req, "passPct", 0);

        List<Object> questions = Json.list(req, "questions");
        List<Object> attempts  = new ArrayList<Object>();
        for (Object o : Json.list(req, "attempts")) {
            Map<String, Object> a = Json.asMap(o);
            if ("graded".equals(Json.str(a, "status", ""))) attempts.add(a);
        }

        // ── Podsumowanie zestawu ─────────────────────────────────────────────
        List<Double> pcts = new ArrayList<Double>();
        int passed = 0;
        for (Object o : attempts) {
            Map<String, Object> a = Json.asMap(o);
            double max = Json.num(a, "maxScore", 0);
            double pct = max > 0 ? 100.0 * Json.num(a, "score", 0) / max : 0;
            pcts.add(Double.valueOf(pct));
            if (passPct <= 0 || pct >= passPct) passed++;
        }
        List<Double> sorted = new ArrayList<Double>(pcts);
        Collections.sort(sorted);

        Map<String, Object> summary = new LinkedHashMap<String, Object>();
        summary.put("attempts",  Integer.valueOf(attempts.size()));
        summary.put("mean",      Double.valueOf(round(mean(pcts))));
        summary.put("median",    Double.valueOf(round(median(sorted))));
        summary.put("min",       Double.valueOf(sorted.isEmpty() ? 0 : round(sorted.get(0).doubleValue())));
        summary.put("max",       Double.valueOf(sorted.isEmpty() ? 0 : round(sorted.get(sorted.size() - 1).doubleValue())));
        summary.put("passRate",  Double.valueOf(attempts.isEmpty() ? 0 : round(100.0 * passed / attempts.size())));
        summary.put("histogram", histogram(pcts));

        // ── Ćwiartki do mocy różnicującej ────────────────────────────────────
        List<Map<String, Object>> ranked = new ArrayList<Map<String, Object>>();
        for (Object o : attempts) ranked.add(Json.asMap(o));
        ranked.sort((x, y) -> Double.compare(fraction(y), fraction(x)));
        int cut = Math.max(1, (int) Math.round(ranked.size() * 0.27));
        List<Map<String, Object>> top    = ranked.subList(0, Math.min(cut, ranked.size()));
        List<Map<String, Object>> bottom = ranked.subList(Math.max(0, ranked.size() - cut), ranked.size());

        // ── Statystyka per pytanie ───────────────────────────────────────────
        List<Object> rows = new ArrayList<Object>();
        for (Object qo : questions) {
            Map<String, Object> q = Json.asMap(qo);
            long qid = Json.id(q, "id", 0);

            double p       = questionEase(ranked, qid);
            double pTop    = questionEase(top, qid);
            double pBottom = questionEase(bottom, qid);
            int answered   = countAnswered(ranked, qid);

            Map<String, Object> row = new LinkedHashMap<String, Object>();
            row.put("questionId",     Long.valueOf(qid));
            row.put("prompt",         Json.str(q, "prompt", ""));
            row.put("type",           Json.str(q, "type", ""));
            row.put("points",         Double.valueOf(Json.num(q, "points", 0)));
            row.put("answered",       Integer.valueOf(answered));
            row.put("ease",           Double.valueOf(round(p)));
            row.put("discrimination", Double.valueOf(round(pTop - pBottom)));
            row.put("flag",           flag(p, pTop - pBottom, answered));
            rows.add(row);
        }

        Map<String, Object> out = new LinkedHashMap<String, Object>();
        out.put("summary",   summary);
        out.put("questions", rows);
        return out;
    }

    /** Etykieta ostrzeżenia dla prowadzącego — „ok", „too_easy", „too_hard", „weak", „inverted". */
    private static String flag(double ease, double disc, int answered) {
        if (answered < 5)     return "insufficient";
        if (disc < 0)         return "inverted";
        if (ease > 0.95)      return "too_easy";
        if (ease < 0.20)      return "too_hard";
        if (disc < 0.20)      return "weak";
        return "ok";
    }

    private static double fraction(Map<String, Object> attempt) {
        double max = Json.num(attempt, "maxScore", 0);
        return max > 0 ? Json.num(attempt, "score", 0) / max : 0;
    }

    private static double questionEase(List<Map<String, Object>> attempts, long qid) {
        double sum = 0;
        int n = 0;
        for (Map<String, Object> a : attempts) {
            for (Object o : Json.list(a, "answers")) {
                Map<String, Object> ans = Json.asMap(o);
                if (Json.id(ans, "questionId", -1) != qid) continue;
                double max = Json.num(ans, "maxPoints", 0);
                if (max <= 0) continue;
                sum += Json.num(ans, "points", 0) / max;
                n++;
            }
        }
        return n > 0 ? sum / n : 0;
    }

    private static int countAnswered(List<Map<String, Object>> attempts, long qid) {
        int n = 0;
        for (Map<String, Object> a : attempts) {
            for (Object o : Json.list(a, "answers")) {
                if (Json.id(Json.asMap(o), "questionId", -1) == qid) n++;
            }
        }
        return n;
    }

    private static List<Object> histogram(List<Double> pcts) {
        int[] buckets = new int[5];              // 0–19, 20–39, 40–59, 60–79, 80–100
        for (Double d : pcts) {
            int b = (int) Math.floor(d.doubleValue() / 20.0);
            if (b < 0) b = 0;
            if (b > 4) b = 4;
            buckets[b]++;
        }
        String[] labels = { "0–19%", "20–39%", "40–59%", "60–79%", "80–100%" };
        List<Object> out = new ArrayList<Object>();
        for (int i = 0; i < buckets.length; i++) {
            out.add(Json.obj("label", labels[i], "count", Integer.valueOf(buckets[i])));
        }
        return out;
    }

    private static double mean(List<Double> v) {
        if (v.isEmpty()) return 0;
        double s = 0;
        for (Double d : v) s += d.doubleValue();
        return s / v.size();
    }

    private static double median(List<Double> sorted) {
        int n = sorted.size();
        if (n == 0) return 0;
        if (n % 2 == 1) return sorted.get(n / 2).doubleValue();
        return (sorted.get(n / 2 - 1).doubleValue() + sorted.get(n / 2).doubleValue()) / 2.0;
    }

    private static double round(double v) {
        return Math.round(v * 1000.0) / 1000.0;
    }
}

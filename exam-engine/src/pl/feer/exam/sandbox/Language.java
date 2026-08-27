package pl.feer.exam.sandbox;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.regex.Matcher;
import java.util.regex.Pattern;

/**
 * Obsługiwane języki zadań programistycznych.
 *
 * Każdy język opisuje: nazwę pliku źródłowego, opcjonalne polecenie kompilacji
 * i polecenie uruchomienia. W argumentach dostępne są podstawienia:
 *   {main} — nazwa klasy/pliku wejściowego (istotne dla Javy),
 *   {mem}  — limit pamięci w MB.
 */
public final class Language {

    public final String id;
    public final String label;
    public final String sourceName;
    /** Kompilacja — null gdy język jest interpretowany. */
    public final List<String> compileArgs;
    public final List<String> runArgs;
    /**
     * Czy ograniczać pamięć przez `ulimit -v`. Dla JVM nie — maszyna wirtualna
     * rezerwuje duże obszary adresowe i przy `ulimit -v` nie wystartuje;
     * tam limit ustawia -Xmx, a twardym zabezpieczeniem jest limit pamięci kontenera.
     */
    public final boolean limitVirtualMemory;

    private Language(String id, String label, String sourceName,
                     List<String> compileArgs, List<String> runArgs, boolean limitVirtualMemory) {
        this.id                 = id;
        this.label              = label;
        this.sourceName         = sourceName;
        this.compileArgs        = compileArgs == null ? null : Collections.unmodifiableList(compileArgs);
        this.runArgs            = Collections.unmodifiableList(runArgs);
        this.limitVirtualMemory = limitVirtualMemory;
    }

    private static final Map<String, Language> REGISTRY = new LinkedHashMap<String, Language>();

    static {
        add(new Language("python", "Python 3", "solution.py",
            null,
            Arrays.asList("python3", "-I", "solution.py"), true));

        add(new Language("php", "PHP", "solution.php",
            null,
            Arrays.asList("php", "-d", "display_errors=stderr", "-d", "error_reporting=E_ALL", "solution.php"), true));

        add(new Language("javascript", "JavaScript (Node.js)", "solution.js",
            null,
            Arrays.asList("node", "--max-old-space-size={mem}", "solution.js"), false));

        add(new Language("java", "Java", "{main}.java",
            Arrays.asList("javac", "-encoding", "UTF-8", "-nowarn", "-d", ".", "{main}.java"),
            Arrays.asList("java", "-Xmx{mem}m", "-XX:+UseSerialGC", "-XX:TieredStopAtLevel=1", "-cp", ".", "{main}"), false));

        add(new Language("c", "C", "solution.c",
            Arrays.asList("gcc", "-O2", "-std=c11", "-o", "solution", "solution.c", "-lm"),
            Arrays.asList("./solution"), true));

        add(new Language("cpp", "C++", "solution.cpp",
            Arrays.asList("g++", "-O2", "-std=c++17", "-o", "solution", "solution.cpp"),
            Arrays.asList("./solution"), true));

        add(new Language("shell", "Powłoka (sh)", "solution.sh",
            null,
            Arrays.asList("sh", "solution.sh"), true));
    }

    private static void add(Language l) { REGISTRY.put(l.id, l); }

    public static Language byId(String id) {
        if (id == null) return null;
        String key = id.trim().toLowerCase();
        if (key.equals("py"))                      key = "python";
        if (key.equals("js") || key.equals("node")) key = "javascript";
        if (key.equals("c++"))                     key = "cpp";
        if (key.equals("bash") || key.equals("sh")) key = "shell";
        return REGISTRY.get(key);
    }

    public static List<String> ids() {
        return new ArrayList<String>(REGISTRY.keySet());
    }

    public static List<Object> describeAll() {
        List<Object> out = new ArrayList<Object>();
        for (Language l : REGISTRY.values()) {
            Map<String, Object> m = new LinkedHashMap<String, Object>();
            m.put("id",       l.id);
            m.put("label",    l.label);
            m.put("compiled", Boolean.valueOf(l.compileArgs != null));
            out.add(m);
        }
        return out;
    }

    /** Nazwa klasy publicznej w kodzie Javy — decyduje o nazwie pliku źródłowego. */
    private static final Pattern JAVA_CLASS =
        Pattern.compile("public\\s+(?:final\\s+|abstract\\s+)?class\\s+([A-Za-z_$][A-Za-z0-9_$]*)");

    public String mainName(String source) {
        if (!"java".equals(id)) return "solution";
        if (source != null) {
            Matcher m = JAVA_CLASS.matcher(source);
            if (m.find()) return m.group(1);
        }
        return "Main";
    }

    public String sourceFileName(String source) {
        return sourceName.replace("{main}", mainName(source));
    }

    public List<String> resolve(List<String> template, String source, int memoryMb) {
        List<String> out = new ArrayList<String>();
        String main = mainName(source);
        for (String a : template) {
            out.add(a.replace("{main}", main).replace("{mem}", Integer.toString(memoryMb)));
        }
        return out;
    }
}

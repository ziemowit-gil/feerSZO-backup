# ── FEER SZO — silnik egzaminów TI (Java) ─────────────────────────────────────
#
# Etap 1: kompilacja samym `javac` — projekt nie ma zależności zewnętrznych,
#         więc budowanie nie wymaga Mavena ani dostępu do repozytoriów.
# Etap 2: obraz uruchomieniowy z narzędziami języków dla piaskownicy.
#
# Obraz uruchomieniowy celowo zawiera JDK, nie JRE: kod kursanta w Javie
# musi dać się skompilować wewnątrz piaskownicy.

FROM eclipse-temurin:21-jdk-jammy AS build
WORKDIR /build
COPY src ./src
# `jar cfe` (krótka forma) zamiast długich opcji --file/--main-class: krótkie
# przełączniki rozumie każda wersja narzędzia, długie bywają wybredne co do
# tego, czy wartość podaje się po spacji, czy po znaku równości.
RUN set -eux; \
    mkdir -p out; \
    find src -name '*.java' > sources.txt; \
    javac -encoding UTF-8 -Xlint:-options -d out @sources.txt; \
    test -f out/pl/feer/exam/Main.class; \
    jar cfe exam-engine.jar pl.feer.exam.Main -C out .

FROM eclipse-temurin:21-jdk-jammy

# Warstwa obowiązkowa: bez tego obraz nie ma sensu.
# curl służy wyłącznie kontroli stanu kontenera (HEALTHCHECK).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends curl ca-certificates; \
    rm -rf /var/lib/apt/lists/*

# Warstwa języków zadań programistycznych — świadomie „best effort”.
# Gdyby któryś pakiet zniknął z repozytorium dystrybucji, nie chcemy wywrócić
# całego obrazu: silnik wystartuje bez tego języka, a zasób /health pokaże go
# jako niedostępny i prowadzący od razu to zobaczy w panelu.
RUN set -eux; \
    apt-get update; \
    for pkg in python3 php-cli nodejs gcc g++ libc6-dev; do \
        apt-get install -y --no-install-recommends "$pkg" \
            || echo "[exam-engine] UWAGA: nie udało się zainstalować $pkg — język będzie niedostępny"; \
    done; \
    rm -rf /var/lib/apt/lists/*

# Konto bez uprawnień — kod kursanta dziedziczy je po procesie silnika.
RUN useradd --create-home --uid 10001 --shell /usr/sbin/nologin exam

COPY --from=build /build/exam-engine.jar /opt/exam-engine/exam-engine.jar

USER exam
WORKDIR /tmp

ENV EXAM_PORT=8090 \
    EXAM_SANDBOX_DIR=/tmp \
    EXAM_MAX_TIMEOUT_MS=15000 \
    EXAM_MAX_OUTPUT=65536 \
    EXAM_MAX_SOURCE=204800

EXPOSE 8090

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD curl -fsS "http://127.0.0.1:${EXAM_PORT}/health" > /dev/null || exit 1

ENTRYPOINT ["java", "-XX:MaxRAMPercentage=60", "-jar", "/opt/exam-engine/exam-engine.jar"]

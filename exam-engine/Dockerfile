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
RUN set -eux; \
    mkdir -p out; \
    find src -name '*.java' > sources.txt; \
    javac -encoding UTF-8 -Xlint:-options -d out @sources.txt; \
    jar --create --file exam-engine.jar --main-class pl.feer.exam.Main -C out .

FROM eclipse-temurin:21-jdk-jammy

# Narzędzia języków dostępnych w zadaniach programistycznych.
# curl służy wyłącznie kontroli stanu kontenera (HEALTHCHECK).
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        python3 php-cli nodejs gcc g++ libc6-dev curl ca-certificates; \
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

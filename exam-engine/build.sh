#!/usr/bin/env bash
# build.sh — kompilacja silnika egzaminów bez Dockera (do pracy lokalnej).
#
#   ./build.sh          — kompiluje do out/ i buduje exam-engine.jar
#   ./build.sh run      — kompiluje i uruchamia silnik na porcie 8090
#
# Wymaga wyłącznie JDK 17+ w PATH; projekt nie ma zależności zewnętrznych.

set -euo pipefail
cd "$(dirname "$0")"

command -v javac >/dev/null || { echo "Brak javac w PATH — zainstaluj JDK 17+." >&2; exit 1; }

rm -rf out
mkdir -p out
find src -name '*.java' > sources.txt
javac -encoding UTF-8 -Xlint:-options -d out @sources.txt
rm -f sources.txt
jar cfe exam-engine.jar pl.feer.exam.Main -C out .
echo "✔ exam-engine.jar gotowy"

if [[ "${1:-}" == "run" ]]; then
    exec java -jar exam-engine.jar
fi

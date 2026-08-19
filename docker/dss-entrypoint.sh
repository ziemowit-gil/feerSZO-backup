#!/bin/sh
# Entrypoint kontenera EU DSS.
# Przy pierwszym starcie pobiera dss-demo-webapp JAR/WAR z dostępnego źródła.
# Przy kolejnych startach używa pliku z wolumenu (szybki restart).
set -e

JAR="/opt/dss/dss.jar"
VER="${DSS_VERSION:-6.4}"

if [ ! -f "$JAR" ]; then
    echo "[DSS] Pierwsze uruchomienie — pobieranie eu-dss v${VER} (~120-200 MB)..."
    echo "[DSS] Plik zostanie zapisany w wolumenie dss_cache i zachowany między restartami."
    echo ""

    # Lista źródeł (próbowane po kolei)
    URL_LIST="
https://nexus.joinup.ec.europa.eu/repository/maven-releases/eu/europa/ec/joinup/sd-dss/dss-demo-webapp/${VER}/dss-demo-webapp-${VER}.war
https://nexus.joinup.ec.europa.eu/repository/maven-public/eu/europa/ec/joinup/sd-dss/dss-demo-webapp/${VER}/dss-demo-webapp-${VER}.war
https://github.com/esig/dss-demonstrations/releases/download/${VER}/dss-demo-webapp-${VER}.jar
https://github.com/esig/dss-demonstrations/releases/download/${VER}/dss-demo-webapp-${VER}.war
"

    DOWNLOADED=0
    for URL in $URL_LIST; do
        echo "[DSS] Próba: ${URL}"
        if curl -fsSL --retry 2 --retry-delay 3 --connect-timeout 30 --max-time 300 \
                "$URL" -o "$JAR.tmp" 2>/dev/null; then
            SIZE=$(stat -c%s "$JAR.tmp" 2>/dev/null || echo 0)
            if [ "${SIZE}" -gt 5000000 ]; then
                mv "$JAR.tmp" "$JAR"
                echo "[DSS] Pobrano pomyślnie ($(( SIZE / 1024 / 1024 )) MB) z:"
                echo "      ${URL}"
                DOWNLOADED=1
                break
            else
                echo "[DSS] Plik zbyt mały (${SIZE} B) — prawdopodobnie błąd 404. Próbuję dalej..."
                rm -f "$JAR.tmp"
            fi
        else
            echo "[DSS] Niedostępne."
            rm -f "$JAR.tmp"
        fi
    done

    if [ "${DOWNLOADED}" = "0" ]; then
        echo ""
        echo "====================================================================="
        echo " BŁĄD: Nie udało się automatycznie pobrać EU DSS v${VER}."
        echo "====================================================================="
        echo ""
        echo " Pobierz ręcznie plik dss-demo-webapp-X.Y.war lub .jar ze strony:"
        echo "   https://github.com/esig/dss-demonstrations/releases"
        echo "   lub z: https://nexus.joinup.ec.europa.eu"
        echo ""
        echo " Następnie skopiuj go do kontenera:"
        echo "   docker cp dss-demo-webapp.war feer-dss:/opt/dss/dss.jar"
        echo "   docker restart feer-dss"
        echo ""
        echo " Albo zamontuj gotowy plik:"
        echo "   volumes:"
        echo "     - /local/path/dss-demo-webapp.war:/opt/dss/dss.jar:ro"
        echo ""
        exit 1
    fi
fi

echo ""
echo "[DSS] Uruchamianie serwera DSS na porcie ${SERVER_PORT:-8080}..."
echo "[DSS] REST API: http://localhost:${SERVER_PORT:-8080}/services/rest/validation/validateSignature"
echo "[DSS] Przy pierwszym starcie pobierana jest lista LOTL (może potrwać 1-2 min)."
echo ""

exec java ${JAVA_OPTS} \
     -jar "${JAR}" \
     --server.port="${SERVER_PORT:-8080}"

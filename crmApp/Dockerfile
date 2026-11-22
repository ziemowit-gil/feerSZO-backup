# SZO CRM jako osobna aplikacja (np. crm-app.feer.org.pl). Budowanie z baseURL "/" i serwowanie przez nginx.
# Adres SZO ustawia się w czasie działania: zmienna SZO_URL → config.json (bez przebudowy obrazu).
FROM node:22-alpine AS build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
ENV NUXT_APP_BASE_URL=/
RUN npx nuxt generate

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/.output/public /usr/share/nginx/html
ENV SZO_URL=""
# Przy starcie kontenera: config.json z adresem SZO.
RUN printf '#!/bin/sh\nprintf "{\\n  \\"szoUrl\\": \\"%%s\\"\\n}\\n" "$SZO_URL" > /usr/share/nginx/html/config.json\n' > /docker-entrypoint.d/40-szo-config.sh \
 && chmod +x /docker-entrypoint.d/40-szo-config.sh
EXPOSE 80

# ── Stage 1: build Angular ───────────────────────────────────────────────────
FROM node:22-alpine AS builder
WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npx ng build --configuration production --base-href=/newUI/

# ── Stage 2: serve with nginx ────────────────────────────────────────────────
FROM nginx:alpine AS runner

COPY --from=builder /app/dist/kursant-app/browser /usr/share/nginx/html
COPY nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80

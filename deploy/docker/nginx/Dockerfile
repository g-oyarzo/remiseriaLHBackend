# NGINX con los archivos públicos del backend (public/). Multi-arquitectura
# (la imagen oficial publica linux/arm64).
FROM nginx:1.28-alpine

COPY public /var/www/html/public
RUN rm -f /var/www/html/public/hot /etc/nginx/conf.d/default.conf

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD wget -q -O /dev/null http://127.0.0.1/up -T 5 --header "Host: localhost" || exit 1

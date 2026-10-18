# =============================================================================
# Hefesto Studio — ClinicaDental: imagen de la interfaz
# =============================================================================
# Node 24 sobre Alpine. La version cumple los motores que Nuxt 4.6.2 declara:
# node ^22.22.3 || ^24.15.0 || >=26.
#
# Compila en la etapa de construccion y se lleva solo el resultado al runtime,
# como en las demas aplicaciones de Hefesto: la imagen final no tiene ni el
# codigo fuente ni las dependencias de desarrollo.
# =============================================================================

FROM node:24-alpine3.24 AS construccion

WORKDIR /app

# Dependencias primero: la capa se reutiliza mientras el lock no cambie.
COPY web/package.json web/package-lock.json* ./

RUN npm ci --no-audit --no-fund

COPY web/ ./

# `NUXT_API_BASE` se usa en la construccion para resolver el BFF. El valor real
# llega como variable de entorno en ejecucion: aca solo hace falta un marcador
# valido para que la compilacion no falle.
ENV NUXT_API_BASE=http://api:8000

RUN npm run build

# -----------------------------------------------------------------------------
FROM node:24-alpine3.24 AS runtime

WORKDIR /app

ENV NODE_ENV=production \
    NITRO_HOST=0.0.0.0 \
    NITRO_PORT=3000

COPY --from=construccion /app/.output ./.output

EXPOSE 3000

# El chequeo va contra `/entrar` y no contra la API: si la API no esta arriba
# todavia, la interfaz sigue sirviendo la pantalla de ingreso y eso es correcto.
# Un chequeo contra la API daria falso negativo durante un despliegue en frio.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD node -e "fetch('http://127.0.0.1:3000/entrar').then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))"

CMD ["node", ".output/server/index.mjs"]
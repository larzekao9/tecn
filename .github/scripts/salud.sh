#!/usr/bin/env bash
# Comprueba el servidor y la base de datos de Canal 11 en tecnoweb. Uso: salud.sh antes|despues|vigilar
#   antes   : antes de desplegar (si el backend viejo aun no tiene /api/salud, solo avisa)
#   despues : despues de desplegar (todo debe responder, y laravel/.env no debe poder bajarse)
#   vigilar : revision programada
# Requiere URL (ej. https://www.tecnoweb.org.bo/inf513/grupo12sa/proyecto2). Cada falla sale en rojo en Actions
# (::error::) y en el resumen de la ejecucion; el script termina con codigo 1 para que GitHub avise por correo.
set -u
MODO="${1:-vigilar}"
URL="${URL%/}"
RESUMEN="${GITHUB_STEP_SUMMARY:-/dev/null}"
fallas=0

fila() { echo "| $1 | $2 | $3 |" >> "$RESUMEN"; }
mal() { echo "::error title=$1::$2"; fila "$1" "❌" "$2"; fallas=$((fallas + 1)); }
bien() { echo "OK  $1: $2"; fila "$1" "✅" "$2"; }
aviso() { echo "::warning title=$1::$2"; fila "$1" "⚠️" "$2"; }

# codigo HTTP de una URL (000 = no hubo respuesta); el cuerpo queda en /tmp/cuerpo
pedir() { curl -sS -L --max-time 40 --connect-timeout 20 -o /tmp/cuerpo -w '%{http_code}' "$1" 2>/tmp/curl_err || true; }

{
  echo "### Salud del servidor ($MODO) — $(date -u '+%Y-%m-%d %H:%M UTC')"
  echo "URL: $URL/"
  echo ""
  echo "| Prueba | Estado | Detalle |"
  echo "|---|---|---|"
} >> "$RESUMEN"

# 1) El servidor web responde (con reintentos: a veces tarda en volver)
codigo=000
for intento in 1 2 3; do
  codigo=$(pedir "$URL/")
  [ "$codigo" = 200 ] && break
  sleep 20
done
case "$codigo" in
  200) bien "Servidor web" "responde (200)" ;;
  000) mal "Servidor web" "NO responde: el servidor esta caido o sin red ($(head -c 200 /tmp/curl_err))" ;;
  *)   mal "Servidor web" "responde con error HTTP $codigo" ;;
esac

# 2) La API y la base de datos (/api/salud). Si el servidor no respondio, no tiene sentido seguir.
if [ "$codigo" = 200 ]; then
  for intento in 1 2 3; do            # PostgreSQL tarda ~1 min en aceptar conexiones tras un reinicio
    codigo=$(pedir "$URL/api/salud")
    [ "$codigo" = 200 ] && break
    [ "$codigo" = 404 ] && break
    sleep 25
  done
  motivo=$(python3 -c "import json;print(json.load(open('/tmp/cuerpo')).get('motivo',''))" 2>/dev/null || true)
  case "$codigo" in
    200) bien "Base de datos" "conectada y con sus tablas" ;;
    503) mal "Base de datos" "${motivo:-no responde}" ;;
    404) if [ "$MODO" = antes ]; then
           aviso "Base de datos" "el backend publicado aun no tiene /api/salud (se agrega con este despliegue)"
         else
           mal "Base de datos" "/api/salud no existe: el backend no quedo publicado"
         fi ;;
    000) mal "API" "no responde (PHP/Laravel colgado o sin red)" ;;
    *)   mal "API" "responde con error HTTP $codigo (revisar laravel/storage/logs en el servidor)" ;;
  esac
fi

# 3) Solo despues de desplegar: Laravel arranca y la configuracion no queda expuesta
if [ "$MODO" = despues ]; then
  codigo=$(pedir "$URL/up")
  [ "$codigo" = 200 ] && bien "Laravel (/up)" "arranca (200)" || mal "Laravel (/up)" "responde $codigo"
  codigo=$(pedir "$URL/laravel/.env")
  [ "$codigo" = 403 ] && bien "laravel/.env" "protegido (403)" || mal "laravel/.env" "responde $codigo (deberia ser 403)"
fi

echo "" >> "$RESUMEN"
if [ "$fallas" -gt 0 ]; then
  if [ "$MODO" = antes ]; then
    echo "**No se subio nada**: corrija lo de arriba (o espere a que vuelva el servidor) y vuelva a ejecutar el despliegue." >> "$RESUMEN"
    echo "::error::Despliegue cancelado ANTES de subir nada: $fallas problema(s) en el servidor o la base de datos."
  else
    echo "::error::$fallas problema(s) en el servidor o la base de datos."
  fi
  exit 1
fi
echo "Todo en orden."

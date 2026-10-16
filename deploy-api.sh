#!/usr/bin/env bash
# ==============================================================================
# Script de Despliegue Local para Padelblox API (Ferozo / DonWeb)
# Uso:
#   ./deploy-api.sh dev   -> Sincroniza a /public_html/api/dev/
#   ./deploy-api.sh prod  -> Sincroniza a /public_html/api/prd/
# ==============================================================================

set -e

ENV_FILE=".env.deploy"
TARGET_ENV="${1:-dev}"

# 1. Cargar variables si existe archivo local .env.deploy
if [ -f "$ENV_FILE" ]; then
    echo "📋 Cargando credenciales desde $ENV_FILE..."
    export $(grep -v '^#' "$ENV_FILE" | xargs)
fi

SFTP_SERVER=${SFTP_SERVER:-"c2632100.ferozo.com"}
SFTP_USERNAME=${SFTP_USERNAME:-"c2632100"}
SFTP_PORT=${SFTP_PORT:-21}

if [ "$TARGET_ENV" = "prod" ] || [ "$TARGET_ENV" = "prd" ]; then
    SFTP_REMOTE_DIR="/public_html/api/prd/"
    LABEL="PRODUCCIÓN (PRD)"
else
    SFTP_REMOTE_DIR="/public_html/api/dev/"
    LABEL="DESARROLLO (DEV)"
fi

echo "🎾 [Padelblox API] Iniciando despliegue a $LABEL ($SFTP_REMOTE_DIR)..."

# 2. Verificar contraseña
if [ -z "$SFTP_PASSWORD" ]; then
    echo "⚠️  Contraseña no encontrada en variable SFTP_PASSWORD ni en .env.deploy"
    echo ""
    echo "Puedes crear un archivo 'api/.env.deploy' con:"
    echo "----------------------------------------"
    echo "SFTP_SERVER=$SFTP_SERVER"
    echo "SFTP_USERNAME=$SFTP_USERNAME"
    echo "SFTP_PASSWORD=tu-contraseña"
    echo "SFTP_PORT=$SFTP_PORT"
    echo "----------------------------------------"
    echo "O simplemente sube tus ramas a GitHub para que GitHub Actions despliegue automáticamente:"
    echo "  git push origin dev   (Despliega a /public_html/api/dev/)"
    echo "  git push origin prod  (Despliega a /public_html/api/prd/)"
    exit 1
fi

# 3. Sincronizar carpeta api
echo "🚀 Sincronizando API a $SFTP_SERVER:$SFTP_REMOTE_DIR por FTPS (Puerto $SFTP_PORT)..."

if command -v lftp &> /dev/null; then
    echo "Usando lftp con soporte TLS..."
    lftp -u "$SFTP_USERNAME","$SFTP_PASSWORD" -p "$SFTP_PORT" "ftp://$SFTP_SERVER" <<EOF
set ftp:ssl-force yes
set ftp:ssl-protect-data yes
set ssl:verify-certificate no
mirror -R --delete --verbose --exclude-glob .git/ --exclude-glob .github/ --exclude-glob resp/ --exclude-glob scratch/ --exclude-glob *.log --exclude-glob *.zip --exclude-glob *.tar.gz ./ "$SFTP_REMOTE_DIR"
quit
EOF
else
    echo "💡 lftp no está instalado localmente. Para instalarlo: brew install lftp"
    echo "El despliegue automático por GitHub Actions está listo y configurado."
fi

echo "✅ Proceso finalizado para $LABEL."

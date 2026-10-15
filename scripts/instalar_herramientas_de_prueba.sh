#!/usr/bin/env bash
# ============================================================
# Instala las herramientas de prueba. Son SOLO de desarrollo: la aplicación no las usa ni las necesita.
#
#   PHPUnit 11   tools/phpunit/phpunit-<versión>.phar   (un solo archivo: no hace falta Composer)
#   Cypress      tests/e2e/node_modules                 (con npm; baja también su binario, ~600 MB, a la caché de Cypress)
#
# Uso:
#   bash scripts/instalar_herramientas_de_prueba.sh              las dos
#   bash scripts/instalar_herramientas_de_prueba.sh phpunit      solo PHPUnit
#   bash scripts/instalar_herramientas_de_prueba.sh cypress      solo Cypress
#
# PHPUnit se descarga de su sitio oficial (phar.phpunit.de) y NO se usa hasta comprobar la firma GPG de su autor, Sebastian Bergmann
# (huella D840 6D0D 8294 7747 2937 7831 4AA3 9408 6372 C20A, la que publica el propio proyecto): un .phar adulterado correría con los
# mismos permisos que los tests. La clave se baja a un llavero temporal, que se borra al terminar: no toca el de nadie.
# Cypress lo instala npm con la versión EXACTA de package.json y las sumas de package-lock.json (npm ci).
#
# Hace falta: curl, gpg y, para Cypress, Node.js 22 o más nuevo con npm.
# ============================================================
set -euo pipefail

VERSION_PHPUNIT="11.5.57"
HUELLA_PHPUNIT="D8406D0D82947747293778314AA394086372C20A"

RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
QUE="${1:-todo}"

instalar_phpunit() {
    local destino="$RAIZ/tools/phpunit"
    local phar="$destino/phpunit-${VERSION_PHPUNIT}.phar"

    command -v curl >/dev/null || { echo "Falta curl." >&2; return 1; }
    command -v gpg  >/dev/null || { echo "Falta gpg (GnuPG): sin él no se puede verificar la firma de PHPUnit." >&2; return 1; }

    mkdir -p "$destino"
    local tmp; tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' RETURN
    chmod 700 "$tmp" 2>/dev/null || true       # en Windows (Git Bash) los permisos de /tmp no se pueden cambiar: no es un error

    echo "==> PHPUnit ${VERSION_PHPUNIT}: descargando de phar.phpunit.de"
    curl -fsSL -o "$tmp/phpunit.phar"     "https://phar.phpunit.de/phpunit-${VERSION_PHPUNIT}.phar"
    curl -fsSL -o "$tmp/phpunit.phar.asc" "https://phar.phpunit.de/phpunit-${VERSION_PHPUNIT}.phar.asc"

    echo "==> Clave pública del autor (keys.openpgp.org), en un llavero temporal"
    curl -fsSL -o "$tmp/clave.asc" "https://keys.openpgp.org/vks/v1/by-fingerprint/${HUELLA_PHPUNIT}"
    export GNUPGHOME="$tmp/gnupg"
    mkdir "$GNUPGHOME"; chmod 700 "$GNUPGHOME" 2>/dev/null || true
    gpg --batch --quiet --import "$tmp/clave.asc" 2>/dev/null
    local huella_real
    huella_real="$(gpg --batch --with-colons --fingerprint "$HUELLA_PHPUNIT" 2>/dev/null | awk -F: '$1=="fpr"{print $10; exit}')"
    if [ "$huella_real" != "$HUELLA_PHPUNIT" ]; then
        echo "La clave descargada NO tiene la huella esperada (${huella_real:-ninguna}). Se aborta." >&2
        return 1
    fi

    echo "==> Verificando la firma"
    # --status-fd: la salida legible para programas; GOODSIG + VALIDSIG con la huella esperada es lo único que se acepta.
    local estado
    estado="$(gpg --batch --status-fd 1 --verify "$tmp/phpunit.phar.asc" "$tmp/phpunit.phar" 2>/dev/null || true)"
    if ! grep -q "^\[GNUPG:\] GOODSIG" <<<"$estado" || ! grep -q "^\[GNUPG:\] VALIDSIG ${HUELLA_PHPUNIT}" <<<"$estado"; then
        echo "La firma de phpunit-${VERSION_PHPUNIT}.phar NO es válida. No se instala." >&2
        return 1
    fi

    install -m 755 "$tmp/phpunit.phar" "$phar"
    cp "$tmp/phpunit.phar.asc" "$phar.asc"
    # tests/run.php usa el phpunit-<versión>.phar más nuevo de esa carpeta: para actualizar, cambiar VERSION_PHPUNIT y correr el script.
    echo "    OK  ${phar#"$RAIZ"/}  sha256 $(sha256sum "$phar" | cut -d' ' -f1)"
}

instalar_cypress() {
    command -v node >/dev/null || { echo "Falta Node.js (22 o más nuevo)." >&2; return 1; }
    command -v npm  >/dev/null || { echo "Falta npm." >&2; return 1; }
    echo "==> Cypress: npm ci en tests/e2e (baja el paquete y su binario)"
    ( cd "$RAIZ/tests/e2e" && npm ci --no-audit --no-fund )
    ( cd "$RAIZ/tests/e2e" && npx cypress verify )
}

case "$QUE" in
    phpunit) instalar_phpunit ;;
    cypress) instalar_cypress ;;
    todo)    instalar_phpunit; instalar_cypress ;;
    *) echo "Uso: $0 [phpunit|cypress]" >&2; exit 2 ;;
esac
echo "==> Listo."

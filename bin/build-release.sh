#!/usr/bin/env bash
#
# bin/build-release.sh — package wb-gamification for distribution.
#
# Reads `Version:` from wb-gamification.php, runs the production build, copies
# the release-relevant files via rsync (with the standard exclude list), and
# zips the result to `dist/wb-gamification-<version>.zip`.
#
# Usage:
#     bash bin/build-release.sh
#
# Per wp-plugin-development standard Part 17.7: Plugin Check must be run
# against the BUILT zip (extracted to a tmp dir), not the source tree.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"
SLUG="wb-gamification"
DIST_DIR="${ROOT_DIR}/dist"

cd "${ROOT_DIR}"

VERSION="$(grep -oE '^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*[0-9]+\.[0-9]+\.[0-9]+' "${ROOT_DIR}/${SLUG}.php" | awk '{print $NF}')"
if [ -z "${VERSION}" ]; then
    echo "ERROR: could not parse Version from ${SLUG}.php" >&2
    exit 1
fi

echo "→ Building ${SLUG} v${VERSION}"

# Ensure production deps + build artefacts are fresh.
if [ -f "${ROOT_DIR}/composer.json" ]; then
    composer install --no-dev --optimize-autoloader --quiet
fi
if [ -f "${ROOT_DIR}/package.json" ] && command -v npm >/dev/null 2>&1; then
    if [ ! -d "${ROOT_DIR}/node_modules" ]; then
        npm install --silent
    fi
    npm run build --silent
fi

# Stage the release in dist/<slug>/
STAGE="${DIST_DIR}/${SLUG}"
rm -rf "${STAGE}" "${DIST_DIR}/${SLUG}-${VERSION}.zip"
mkdir -p "${STAGE}"

rsync -a --delete \
    --exclude='.git/' --exclude='.github/' --exclude='.gitignore' --exclude='.gitattributes' \
    --exclude='.editorconfig' --exclude='.distignore' --exclude='.DS_Store' --exclude='.phpunit.result.cache' \
    --exclude='.idea/' --exclude='.vscode/' \
    --exclude='node_modules/' \
    --exclude='tests/' --exclude='plans/' --exclude='docs/' --exclude='audit/' --exclude='examples/' \
    --exclude='dist/' --exclude='bin/' --exclude='src/' \
    --exclude='*.map' --exclude='package.json' --exclude='package-lock.json' \
    --exclude='composer.json' --exclude='composer.lock' \
    --exclude='webpack.config.js' --exclude='phpcs.xml*' --exclude='.phpcs.xml*' \
    --exclude='phpstan.neon*' --exclude='phpstan-bootstrap.php' --exclude='phpstan-stubs/' \
    --exclude='phpunit.xml*' \
    --exclude='CLAUDE.md' --exclude='*.log' --exclude='wp-content/' \
    "${ROOT_DIR}/" "${STAGE}/"

cd "${DIST_DIR}"
zip -qr "${SLUG}-${VERSION}.zip" "${SLUG}"
ZIP_PATH="${DIST_DIR}/${SLUG}-${VERSION}.zip"
SIZE_KB=$(($(wc -c < "${ZIP_PATH}") / 1024))

# Restore composer dev deps for continued local work.
if [ -f "${ROOT_DIR}/composer.json" ]; then
    composer install --quiet
fi

cd "${ROOT_DIR}"
echo "→ ${ZIP_PATH} (${SIZE_KB} KB)"
echo "→ Next: extract to a tmp dir + run \`wp plugin check\` per release-gate (Part 17.7.3)."

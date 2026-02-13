#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"

if [[ -z "${VERSION}" ]]; then
  VERSION="$(sed -n 's/^Version:[[:space:]]*//p' "${ROOT_DIR}/simple-product-export.php" | head -n1 | tr -d '\r\n')"
fi

if [[ -z "${VERSION}" ]]; then
  echo "ERROR: Could not determine plugin version." >&2
  exit 1
fi

PKG_NAME="simple-product-export"
OUT_ZIP="${ROOT_DIR}/${PKG_NAME}-v${VERSION}.zip"
ALIAS_ZIP="${ROOT_DIR}/${PKG_NAME}.zip"

TMP_DIR="$(mktemp -d)"
cleanup() {
  rm -rf "${TMP_DIR}"
}
trap cleanup EXIT

mkdir -p "${TMP_DIR}/${PKG_NAME}"
cp -R \
  "${ROOT_DIR}/simple-product-export.php" \
  "${ROOT_DIR}/README.md" \
  "${ROOT_DIR}/includes" \
  "${ROOT_DIR}/docs" \
  "${TMP_DIR}/${PKG_NAME}/"

rm -f "${OUT_ZIP}" "${ALIAS_ZIP}"
(
  cd "${TMP_DIR}"
  zip -rq "${OUT_ZIP}" "${PKG_NAME}"
)
cp "${OUT_ZIP}" "${ALIAS_ZIP}"

echo "Created release package:"
echo "  - ${OUT_ZIP}"
echo "  - ${ALIAS_ZIP}"

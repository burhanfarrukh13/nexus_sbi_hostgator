#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
WEB_DIR="$ROOT_DIR/web"
ARTIFACT_DIR="$ROOT_DIR/artifacts/hostgator-package"
OUTPUT_PATH="${1:-$ROOT_DIR/artifacts/hostgator-package.zip}"

if [ ! -d "$BACKEND_DIR" ]; then
  echo "Backend directory not found: $BACKEND_DIR" >&2
  exit 1
fi

if [ ! -d "$WEB_DIR" ]; then
  echo "Web directory not found: $WEB_DIR" >&2
  exit 1
fi

if [ ! -d "$WEB_DIR/dist" ]; then
  echo "Static web build not found at $WEB_DIR/dist; run the front-end build before packaging."
  exit 1
fi

rm -rf "$ARTIFACT_DIR"
mkdir -p "$ARTIFACT_DIR"

cp -R "$BACKEND_DIR"/. "$ARTIFACT_DIR/"
mkdir -p "$ARTIFACT_DIR/web-dist"
cp -R "$WEB_DIR/dist"/. "$ARTIFACT_DIR/web-dist/"

mkdir -p "$(dirname "$OUTPUT_PATH")"
rm -f "$OUTPUT_PATH"
cd "$ARTIFACT_DIR"
zip -r "$OUTPUT_PATH" .

printf '\nHostGator package created at: %s\n' "$OUTPUT_PATH"
printf 'Upload or extract this archive into the correct HostGator API/web folders.\n'
printf 'Then configure backend/.env, MySQL credentials, JWT secrets, and cloud embeddings.\n'

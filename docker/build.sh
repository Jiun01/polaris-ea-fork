#!/usr/bin/env bash
# Build and push the Polaris EA fork production image to GHCR.
#
# Usage:
#   ./docker/build.sh [--push] [--tag <version>]
#
# Defaults:
#   --tag  polaris/v1.6.0-r2  (reads from git tag if available)
#   --push false              (pass --push to actually push)
#
# Requires (for --push only):
#   docker buildx, and a GHCR write:packages token. The token is read from the
#   monorepo secrets file polaris/.env (single source of truth) — see below.
#   Or `docker login ghcr.io -u jiun01` manually and skip the token entirely.

set -euo pipefail

# GHCR push token — read securely from the monorepo secrets file polaris/.env
# (sibling of this fork), NOT from this fork's own .env. We extract ONLY the
# GITHUB_TOKEN line; the file is never `source`d, so polaris/.env's other secrets
# (POLARIS_KMS_KEY, DB URLs, API keys) never enter this build's environment.
# Precedence: an already-exported GITHUB_TOKEN wins; POLARIS_ENV_FILE overrides
# the path. The value is piped to `docker login --password-stdin` — never echoed.
if [[ -z "${GITHUB_TOKEN:-}" ]]; then
    _script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    POLARIS_ENV_FILE="${POLARIS_ENV_FILE:-${_script_dir}/../../polaris/.env}"
    if [[ -f "$POLARIS_ENV_FILE" ]]; then
        _tok="$(sed -n 's/^[[:space:]]*GITHUB_TOKEN[[:space:]]*=//p' "$POLARIS_ENV_FILE" | tail -n1 | tr -d '\r')"
        _tok="${_tok#"${_tok%%[![:space:]]*}"}"   # ltrim whitespace
        _tok="${_tok%"${_tok##*[![:space:]]}"}"   # rtrim whitespace
        _tok="${_tok%\"}"; _tok="${_tok#\"}"       # strip paired double quotes
        _tok="${_tok%\'}"; _tok="${_tok#\'}"       # strip paired single quotes
        [[ -n "$_tok" ]] && export GITHUB_TOKEN="$_tok"
        unset _tok
    fi
    unset _script_dir
fi

IMAGE="ghcr.io/jiun01/polaris-ea"
VERSION="${TAG:-$(git describe --tags --match 'polaris/v*' --abbrev=0 2>/dev/null || echo 'polaris/v1.6.0-r2')}"
# Strip the polaris/ prefix for the Docker tag.
DOCKER_TAG="${VERSION#polaris/}"
PUSH=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --push) PUSH=true ;;
        --tag)  DOCKER_TAG="$2"; shift ;;
        *) echo "Unknown argument: $1" >&2; exit 1 ;;
    esac
    shift
done

if [[ "$PUSH" == "true" ]]; then
    if [[ -z "${GITHUB_TOKEN:-}" ]]; then
        echo "Error: GITHUB_TOKEN is not set. Add a write:packages PAT to polaris/.env," >&2
        echo "       export GITHUB_TOKEN, set POLARIS_ENV_FILE, or run" >&2
        echo "       'docker login ghcr.io -u jiun01' manually and re-run." >&2
        exit 1
    fi
    echo "$GITHUB_TOKEN" | docker login ghcr.io -u jiun01 --password-stdin
fi

echo "▶ Building ${IMAGE}:${DOCKER_TAG}"

# Build args in an array so unquoted word-splitting cannot corrupt the command.
build_args=(
    --platform linux/amd64
    --target production
    --tag "${IMAGE}:${DOCKER_TAG}"
    --tag "${IMAGE}:latest"
    --file Dockerfile
)
[[ "$PUSH" == "true" ]] && build_args+=(--push)

docker buildx build "${build_args[@]}" .

echo "✔ Build complete: ${IMAGE}:${DOCKER_TAG}"
if [[ "$PUSH" == "true" ]]; then
    echo "  Pushed to GHCR."
else
    echo "  Pass --push to upload to GHCR."
fi

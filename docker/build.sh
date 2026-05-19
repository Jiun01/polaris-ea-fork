#!/usr/bin/env bash
# Build and push the Polaris EA fork production image to GHCR.
#
# Usage:
#   ./docker/build.sh [--push] [--tag <version>]
#
# Defaults:
#   --tag  polaris/v1.5.2-r1  (reads from git tag if available)
#   --push false              (pass --push to actually push)
#
# Requires:
#   docker buildx, logged in to ghcr.io as jiun01:
#     echo $GITHUB_TOKEN | docker login ghcr.io -u jiun01 --password-stdin

set -euo pipefail

IMAGE="ghcr.io/jiun01/polaris-ea"
VERSION="${TAG:-$(git describe --tags --match 'polaris/v*' --abbrev=0 2>/dev/null || echo 'polaris/v1.5.2-r1')}"
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

echo "▶ Building ${IMAGE}:${DOCKER_TAG}"
docker buildx build \
    --platform linux/amd64 \
    --target production \
    --tag "${IMAGE}:${DOCKER_TAG}" \
    --tag "${IMAGE}:latest" \
    --file Dockerfile \
    ${PUSH:+--push} \
    .

echo "✔ Build complete: ${IMAGE}:${DOCKER_TAG}"
if [[ "$PUSH" == "true" ]]; then
    echo "  Pushed to GHCR."
else
    echo "  Pass --push to upload to GHCR."
fi

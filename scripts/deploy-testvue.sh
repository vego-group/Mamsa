#!/bin/bash
# deploy-testvue.sh — build, upload, and switch what testvue.mamsaa.com serves.
#
#   ./scripts/deploy-testvue.sh staging   build staging variant → upload → switch to it
#   ./scripts/deploy-testvue.sh live      build live variant    → upload → switch to it
#   ./scripts/deploy-testvue.sh both      build + upload both, keep current switch
#   ./scripts/deploy-testvue.sh status    show which release is active
#
# Requires the `mamsa` SSH host alias and the server-side ~/bin/switch-frontend
# script (rsyncs ~/domains/testvue.mamsaa.com/releases/<name>/ into public_html).
set -euo pipefail

cd "$(dirname "$0")/../frontend"
REMOTE_BASE='~/domains/testvue.mamsaa.com/releases'

build_and_upload() { # $1 = live|staging
  if [ "$1" = "live" ]; then
    npm run build
    rsync -az --delete dist/ "mamsa:$REMOTE_BASE/live/"
  else
    npx vite build --mode staging --outDir dist-staging
    rsync -az --delete dist-staging/ "mamsa:$REMOTE_BASE/staging/"
  fi
  echo "release '$1' uploaded"
}

case "${1:-}" in
  live|staging)
    build_and_upload "$1"
    ssh mamsa "~/bin/switch-frontend $1"
    ;;
  both)
    build_and_upload live
    build_and_upload staging
    ssh mamsa '~/bin/switch-frontend status'
    ;;
  status)
    ssh mamsa '~/bin/switch-frontend status'
    ;;
  *)
    echo "usage: $0 live|staging|both|status" >&2
    exit 1
    ;;
esac

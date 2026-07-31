#!/usr/bin/env bash
#
# Sync local changes to the DigitalOcean droplet WITHOUT GitHub.
#
# Copies both repos to the droplet over SSH with rsync (creating the remote
# dirs on the fly via --rsync-path), then rebuilds the Docker stack. Run it
# from your laptop, from inside this backend repo:
#
#     ./deploy.sh
#
# One-time setup:
#     cp deploy.env.example deploy.env      # then edit deploy.env with your droplet
#     ssh-copy-id root@YOUR_DROPLET_IP      # so rsync/ssh don't ask for a password
#
# The droplet's production .env is never touched (excluded below).
set -euo pipefail

cd "$(dirname "$0")"

# ── Config ────────────────────────────────────────────────────────────────
if [[ -f deploy.env ]]; then
  # shellcheck disable=SC1091
  source deploy.env
fi
: "${DROPLET_HOST:?Set DROPLET_HOST in deploy.env (e.g. 206.189.84.21)}"
DROPLET_USER="${DROPLET_USER:-root}"
REMOTE_DIR="${REMOTE_DIR:-/root/avion}"
# Canonical local frontend copy (the one with the changes) — NOT the Documents copy.
FRONTEND_DIR="${FRONTEND_DIR:-../AirlineSystemFront-End}"
SSH_TARGET="${DROPLET_USER}@${DROPLET_HOST}"

if [[ ! -d "$FRONTEND_DIR" ]]; then
  echo "!! Frontend repo not found at '$FRONTEND_DIR'." >&2
  echo "   Set FRONTEND_DIR in deploy.env to wherever AirlineSystemFront-End lives." >&2
  exit 1
fi

echo "==> Syncing backend  -> ${SSH_TARGET}:${REMOTE_DIR}/airline_backend_CS394"
rsync -az \
  --rsync-path="mkdir -p '${REMOTE_DIR}/airline_backend_CS394' && rsync" \
  --exclude .git --exclude vendor --exclude node_modules \
  --exclude .env --exclude 'deploy.env' --exclude .DS_Store \
  --exclude 'storage/logs/*' --exclude 'bootstrap/cache/*' \
  ./ "${SSH_TARGET}:${REMOTE_DIR}/airline_backend_CS394/"

echo "==> Syncing frontend -> ${SSH_TARGET}:${REMOTE_DIR}/AirlineSystemFront-End"
rsync -az \
  --rsync-path="mkdir -p '${REMOTE_DIR}/AirlineSystemFront-End' && rsync" \
  --exclude node_modules --exclude .git --exclude dist --exclude .env --exclude .DS_Store \
  "${FRONTEND_DIR}/" "${SSH_TARGET}:${REMOTE_DIR}/AirlineSystemFront-End/"

echo "==> Rebuilding the Docker stack on the droplet (migrations run on boot)"
ssh "$SSH_TARGET" "cd '${REMOTE_DIR}/airline_backend_CS394' && docker compose -f docker-compose.prod.yml up -d --build && docker image prune -f"

echo "==> Done. App: http://${DROPLET_HOST}"

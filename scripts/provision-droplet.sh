#!/usr/bin/env bash
#
# Provision a fresh Ubuntu droplet for running the Bookshare production stack.
# Installs Docker Engine + the compose plugin, git, certbot and a firewall,
# and adds a swap file as a safety net on low-memory VPSes.
#
# Idempotent — safe to re-run. Run as root (or with sudo):
#   sudo bash scripts/provision-droplet.sh
#
set -euo pipefail

SWAP_SIZE="${SWAP_SIZE:-2G}"
SWAP_FILE="/swapfile"
TARGET_USER="${SUDO_USER:-${TARGET_USER:-}}"

log() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must run as root (use sudo)." >&2
    exit 1
fi

log "Updating apt and installing base packages…"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get upgrade -y
apt-get install -y \
    ca-certificates curl gnupg git ufw \
    certbot unattended-upgrades

# ── Docker Engine + compose plugin (official apt repository) ────────────────
if ! command -v docker >/dev/null 2>&1; then
    log "Installing Docker Engine + compose plugin…"
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        | gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    chmod a+r /etc/apt/keyrings/docker.gpg
    . /etc/os-release
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] \
https://download.docker.com/linux/ubuntu ${VERSION_CODENAME} stable" \
        > /etc/apt/sources.list.d/docker.list
    apt-get update -y
    apt-get install -y \
        docker-ce docker-ce-cli containerd.io \
        docker-buildx-plugin docker-compose-plugin
else
    log "Docker already installed — skipping."
fi

systemctl enable --now docker

# Let the invoking (non-root) user run docker without sudo.
if [ -n "$TARGET_USER" ] && [ "$TARGET_USER" != "root" ]; then
    log "Adding '$TARGET_USER' to the docker group…"
    usermod -aG docker "$TARGET_USER"
fi

# ── Swap (build/runtime safety on low-memory droplets) ──────────────────────
if ! swapon --show | grep -q "$SWAP_FILE" && [ ! -f "$SWAP_FILE" ]; then
    log "Creating ${SWAP_SIZE} swap file at ${SWAP_FILE}…"
    fallocate -l "$SWAP_SIZE" "$SWAP_FILE" || dd if=/dev/zero of="$SWAP_FILE" bs=1M count=2048
    chmod 600 "$SWAP_FILE"
    mkswap "$SWAP_FILE"
    swapon "$SWAP_FILE"
    grep -q "$SWAP_FILE" /etc/fstab || echo "$SWAP_FILE none swap sw 0 0" >> /etc/fstab
else
    log "Swap already configured — skipping."
fi

# ── Firewall ────────────────────────────────────────────────────────────────
log "Configuring ufw (OpenSSH + HTTP + HTTPS)…"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw --force enable

# ── Automatic security updates ──────────────────────────────────────────────
log "Enabling unattended security upgrades…"
dpkg-reconfigure -f noninteractive unattended-upgrades || true

log "Provisioning complete."
echo "Next: clone the repo, create .env.prod.local + config/jwt keys, then run 'make prod-deploy'."
echo "If you were added to the docker group, log out and back in for it to take effect."

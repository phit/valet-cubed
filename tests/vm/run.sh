#!/usr/bin/env bash
# Run one legacy DNS migration scenario in a disposable QEMU virtual machine.
#
#   tests/vm/run.sh DISTRO SCENARIO [--keep]
#
# Scenarios:
#   legacy        original release install, upgrade, migrate, reboot, rollback,
#                 migrate again, uninstall
#   legacy-watcher  the same with the valet-dns watcher release
#   legacy-user-dnsmasq  legacy install next to an unrelated dnsmasq zone
#   legacy-uninstalled  legacy install and legacy uninstall before upgrading
#   fresh         install of this checkout on a clean system
#
# Requires qemu-system-x86_64, qemu-img, cloud-localds, ssh and curl. Uses KVM
# when /dev/kvm is usable and falls back to TCG emulation otherwise.
# Artifacts: $VALET_VM_WORK (default tests/vm/.work)/<distro>-<scenario>/.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
# shellcheck source=tests/vm/images.sh
. "$ROOT/tests/vm/images.sh"

DISTRO="${1:?usage: run.sh DISTRO SCENARIO [--keep]}"
SCENARIO="${2:?usage: run.sh DISTRO SCENARIO [--keep]}"
KEEP="${3:-}"
CACHE="${VALET_VM_CACHE:-$HOME/.cache/valet-vm}"
WORK="${VALET_VM_WORK:-$ROOT/tests/vm/.work}/$DISTRO-$SCENARIO"
MEMORY="${VALET_VM_MEMORY:-3072}"
CPUS="${VALET_VM_CPUS:-2}"

case "$SCENARIO" in
    legacy|legacy-watcher|legacy-user-dnsmasq|legacy-uninstalled|fresh) ;;
    *) echo "Unknown scenario: $SCENARIO" >&2; exit 64 ;;
esac

image_for "$DISTRO"
rm -rf "$WORK"
mkdir -p "$CACHE" "$WORK/artifacts"
exec > >(tee "$WORK/artifacts/host.log") 2>&1

result() {
    # result STATUS MESSAGE: machine-readable outcome for the CI summary.
    printf '%s\t%s\t%s\t%s\n' "$DISTRO" "$SCENARIO" "$1" "$2" > "$WORK/artifacts/result.tsv"
    echo "RESULT: $DISTRO $SCENARIO $1 - $2"
}

if [[ -n "$IMAGE_GAP" ]]; then
    result gap "$IMAGE_GAP"
    exit 0
fi

for tool in qemu-system-x86_64 qemu-img cloud-localds ssh scp curl; do
    command -v "$tool" >/dev/null || { echo "Missing $tool" >&2; exit 69; }
done

# --- image ---------------------------------------------------------------
BASE="$CACHE/$(basename "$IMAGE_URL")"
if [[ ! -f "$BASE" ]]; then
    echo "Downloading $IMAGE_URL"
    if ! curl -fsSL --retry 3 --retry-all-errors --http1.1 -o "$BASE.part" "$IMAGE_URL"; then
        result fail "image download failed for $IMAGE_URL"
        exit 1
    fi
    mv "$BASE.part" "$BASE"
fi
algorithm="${IMAGE_SUM%%:*}"
expected="${IMAGE_SUM#*:}"
actual="$("${algorithm}sum" "$BASE" | cut -d' ' -f1)"
if [[ "$actual" != "$expected" ]]; then
    rm -f "$BASE"
    result gap "image checksum mismatch for $IMAGE_URL (the pinned image is no longer published)"
    exit 1
fi
echo "Image: $IMAGE_URL ($IMAGE_SUM)" | tee "$WORK/artifacts/image.txt"

DISK="$WORK/disk.qcow2"
qemu-img create -q -f qcow2 -b "$BASE" -F qcow2 "$DISK" 24G

# --- cloud-init ----------------------------------------------------------
ssh-keygen -q -t ed25519 -N '' -f "$WORK/id"
cat > "$WORK/user-data" <<EOF
#cloud-config
users:
  - name: valet
    shell: /bin/bash
    sudo: "ALL=(ALL) NOPASSWD:ALL"
    lock_passwd: true
    ssh_authorized_keys:
      - $(cat "$WORK/id.pub")
ssh_pwauth: false
growpart: {mode: auto, devices: ["/"]}
EOF
printf 'instance-id: valet-%s\nlocal-hostname: valet-vm\n' "$(date +%s)" > "$WORK/meta-data"
cloud-localds "$WORK/seed.img" "$WORK/user-data" "$WORK/meta-data"

# --- boot ----------------------------------------------------------------
ACCEL=(-accel tcg -cpu max)
if [[ -r /dev/kvm && -w /dev/kvm ]]; then
    ACCEL=(-accel kvm -cpu host)
fi
PORT="$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1", 0)); print(s.getsockname()[1])')"
SSH=(ssh -i "$WORK/id" -p "$PORT" -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null
    -o LogLevel=ERROR -o SetEnv=LC_ALL=C.UTF-8 -o ConnectTimeout=10 -o ServerAliveInterval=15 valet@127.0.0.1)

boot() {
    qemu-system-x86_64 "${ACCEL[@]}" -m "$MEMORY" -smp "$CPUS" \
        -drive "file=$DISK,if=virtio,format=qcow2" \
        -drive "file=$WORK/seed.img,if=virtio,format=raw" \
        -netdev "user,id=n0,hostfwd=tcp:127.0.0.1:$PORT-:22" -device virtio-net-pci,netdev=n0 \
        -serial "file:$WORK/artifacts/console-$1.log" -monitor none -display none \
        -daemonize -pidfile "$WORK/qemu.pid"
    for _ in $(seq 1 180); do
        if "${SSH[@]}" true 2>/dev/null; then
            return 0
        fi
        sleep 5
    done
    echo "The VM did not become reachable over SSH." >&2
    return 1
}

shutdown_vm() {
    if [[ -f "$WORK/qemu.pid" ]] && kill -0 "$(cat "$WORK/qemu.pid")" 2>/dev/null; then
        "${SSH[@]}" 'sudo systemctl poweroff' 2>/dev/null || true
        for _ in $(seq 1 30); do
            kill -0 "$(cat "$WORK/qemu.pid")" 2>/dev/null || break
            sleep 2
        done
        kill "$(cat "$WORK/qemu.pid")" 2>/dev/null || true
    fi
}

collect() {
    "${SSH[@]}" 'sudo tar -C /var/tmp -czf - valet-vm 2>/dev/null' > "$WORK/artifacts/guest.tgz" 2>/dev/null || true
    tar -C "$WORK/artifacts" -xzf "$WORK/artifacts/guest.tgz" 2>/dev/null || true
}

cleanup() {
    status=$?
    collect || true
    shutdown_vm || true
    if [[ "$KEEP" != "--keep" ]]; then
        rm -f "$DISK" "$WORK/seed.img"
    fi
    if [[ ! -f "$WORK/artifacts/result.tsv" && -f "$WORK/artifacts/valet-vm/result.tsv" ]]; then
        cp "$WORK/artifacts/valet-vm/result.tsv" "$WORK/artifacts/result.tsv"
    fi
    if [[ ! -f "$WORK/artifacts/result.tsv" ]] || grep -qP '\trunning\t' "$WORK/artifacts/result.tsv"; then
        result fail "harness exited with status $status (see host.log and guest logs)"
    fi
}
trap cleanup EXIT

reboot_vm() {
    "${SSH[@]}" 'sudo systemctl reboot' || true
    sleep 5
    for _ in $(seq 1 60); do
        kill -0 "$(cat "$WORK/qemu.pid")" 2>/dev/null || break
        if "${SSH[@]}" 'test ! -e /run/valet-vm-booted' 2>/dev/null; then
            "${SSH[@]}" 'sudo touch /run/valet-vm-booted'
            return 0
        fi
        sleep 5
    done
    echo "The VM did not come back after reboot." >&2
    return 1
}

guest() {
    # guest PHASE: run one phase of tests/vm/guest.sh as root inside the VM.
    echo "=== guest phase: $1"
    "${SSH[@]}" "sudo VALET_VM_DISTRO=$DISTRO VALET_VM_SCENARIO=$SCENARIO bash /opt/valet-checkout/tests/vm/guest.sh $1"
}

boot first
"${SSH[@]}" 'cloud-init status --wait >/dev/null 2>&1 || true; sudo touch /run/valet-vm-booted'

# Copy the working tree, including uncommitted changes, without build output.
tar -C "$ROOT" --exclude=./vendor --exclude=./.git --exclude=./tests/vm/.work --exclude=./coverage -czf - . \
    | "${SSH[@]}" 'sudo rm -rf /opt/valet-checkout && sudo mkdir -p /opt/valet-checkout && sudo tar -C /opt/valet-checkout -xzf - && sudo chown -R valet: /opt/valet-checkout'

guest provision
reboot_vm
guest scenario
reboot_vm
guest after-reboot
"${SSH[@]}" 'sudo cat /var/tmp/valet-vm/result.tsv' > "$WORK/artifacts/result.tsv"
cat "$WORK/artifacts/result.tsv"

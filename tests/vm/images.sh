# shellcheck shell=bash
# Pinned cloud images for the legacy DNS migration VM tests.
#
# Each entry sets IMAGE_URL, IMAGE_SUM (algorithm:hash) and FAMILY. Rolling
# image names are marked; their checksum fails once the vendor replaces the
# file, which is reported as an image gap instead of silently using a
# different image.

# Original installer under test: the upstream baseline commit 42d1a94
# (dev-master). The newest published release, 2.0.3 (0744e33), has the same
# DNS code but cannot install on a machine without ~/.valet: its
# migrateConfig() crashes before DNS is configured (fixed upstream in 222c5cc).
LEGACY_SPEC="genesisweb/valet-linux-plus:dev-master#42d1a94df3fa28bc85d4a26545e3d7a314e8db3b"
# Last release of the valet-dns watcher layout.
LEGACY_WATCHER_SPEC="genesisweb/valet-linux-plus:1.5.8"

COMPOSER_VERSION="2.11.1"
COMPOSER_SHA256="6f1b891e1d4927d62740276c540fb490e47689e19e102c49e8da849794882f8c"

DISTROS="ubuntu-26.04 ubuntu-24.04 debian-13 fedora-44 almalinux-10 arch opensuse-leap-16.0 alt-p11 solus"

image_for() {
    IMAGE_URL=""
    IMAGE_SUM=""
    FAMILY=""
    IMAGE_GAP=""
    case "$1" in
        ubuntu-26.04)
            IMAGE_URL="https://cloud-images.ubuntu.com/releases/resolute/release-20261016/ubuntu-26.04-server-cloudimg-amd64.img"
            IMAGE_SUM="sha256:5645d0318ad21f643ebb1ac4567514620e9893380b07b0201fda163baa5b7eb5"
            FAMILY="apt"
            ;;
        ubuntu-24.04)
            IMAGE_URL="https://cloud-images.ubuntu.com/releases/noble/release-20261017/ubuntu-24.04-server-cloudimg-amd64.img"
            IMAGE_SUM="sha256:04e8f2461a16a44e78a370284471f29616cdd9baf1893df25807b71f985b6c94"
            FAMILY="apt"
            ;;
        debian-13)
            IMAGE_URL="https://cloud.debian.org/images/cloud/trixie/20261103-2651/debian-13-genericcloud-amd64-20261103-2651.qcow2"
            IMAGE_SUM="sha512:d25999de0770d4cde1be72c299ba3ad94eac7ea8fd4d18f8cc86c69df69337e2e8a91d64465a9bfd8f2a78c566fcbcb0842eacc91c09efdef72d719d9f13b535"
            FAMILY="apt"
            ;;
        fedora-44)
            IMAGE_URL="https://download.fedoraproject.org/pub/fedora/linux/releases/44/Cloud/x86_64/images/Fedora-Cloud-Base-Generic-44-1.7.x86_64.qcow2"
            IMAGE_SUM="sha256:28680fe5b371a5a82ebf43a31926e086a168e59949d03969c5093e7071f90b7f"
            FAMILY="dnf"
            ;;
        almalinux-10)
            IMAGE_URL="https://repo.almalinux.org/almalinux/10/cloud/x86_64/images/AlmaLinux-10-GenericCloud-10.2-20261012.0.x86_64.qcow2"
            IMAGE_SUM="sha256:cbee4726fc0f5a60694bb4f289003cc221e715dfbacebcce76b54daf386d8eca"
            FAMILY="dnf"
            ;;
        arch)
            IMAGE_URL="https://geo.mirror.pkgbuild.com/images/v20261101.620452/Arch-Linux-x86_64-cloudimg-20261101.620452.qcow2"
            IMAGE_SUM="sha256:794eb4889b39e1f0ed7d5ae998b2a1ed2137db8cf8c3476cf2962c7437ecb003"
            FAMILY="pacman"
            ;;
        opensuse-leap-16.0)
            IMAGE_URL="https://download.opensuse.org/distribution/leap/16.0/appliances/Leap-16.0-Minimal-VM.x86_64-Cloud-Build18.89.qcow2"
            IMAGE_SUM="sha256:2f328a0135f9c8bf62c5ec012405169f76188ea7abf18f9c65cb0ea5374c042b"
            FAMILY="zypper"
            ;;
        alt-p11)
            # Rolling name: ALT replaces this file in place.
            IMAGE_URL="https://ftp.altlinux.org/pub/distributions/ALTLinux/images/p11/cloud/x86_64/alt-server-11.1-p11-cloud-x86_64.qcow2"
            IMAGE_SUM="sha256:b6ffa3bd52b3dbe1e8e2389cc988daffcb351e7e38918265cad58e1641e2374f"
            FAMILY="altapt"
            ;;
        solus)
            IMAGE_GAP="Solus publishes no cloud image; an installer-based image build is needed."
            ;;
        *)
            echo "Unknown distribution: $1" >&2
            return 1
            ;;
    esac
}

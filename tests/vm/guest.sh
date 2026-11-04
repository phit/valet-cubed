#!/usr/bin/env bash
# Guest side of the legacy DNS migration VM test. Runs as root inside a
# disposable VM; never run it on a real machine.
#
#   guest.sh provision      packages, NetworkManager, controlled LAN DNS
#   guest.sh scenario       baseline, original install, upgrade, migration
#   guest.sh after-reboot   persistence, rollback, migrate again, uninstall
set -uo pipefail

PHASE="${1:?phase}"
SCENARIO="${VALET_VM_SCENARIO:?}"
DISTRO="${VALET_VM_DISTRO:?}"
CHECKOUT=/opt/valet-checkout
OUT=/var/tmp/valet-vm
STATE="$OUT/state"
LAN_NAME=nas.lan.vmtest
LAN_ADDRESS=10.53.0.10
HOSTS_NAME=printer-static.lan.vmtest
USER_ZONE_NAME=printer.office.vmtest

# shellcheck source=tests/vm/images.sh
. "$CHECKOUT/tests/vm/images.sh"
image_for "$DISTRO"

mkdir -p "$OUT" "$STATE"
exec > >(tee -a "$OUT/guest-$PHASE.log") 2>&1
echo "=== $(date -Is) $PHASE ($DISTRO, $SCENARIO)"

FAILURES=0

result() {
    printf '%s\t%s\t%s\t%s\n' "$DISTRO" "$SCENARIO" "$1" "$2" > "$OUT/result.tsv"
    echo "RESULT: $1 - $2"
}

gap() {
    result gap "$1"
    exit 0
}

die() {
    result fail "$1"
    exit 1
}

check() {
    # check DESCRIPTION COMMAND...: record an assertion without aborting.
    local description="$1"
    shift
    if "$@" >>"$OUT/checks.log" 2>&1; then
        printf 'PASS\t%s\t%s\n' "$PHASE" "$description" | tee -a "$OUT/checks.tsv"
    else
        printf 'FAIL\t%s\t%s\n' "$PHASE" "$description" | tee -a "$OUT/checks.tsv"
        FAILURES=$((FAILURES + 1))
    fi
}

note() {
    printf 'NOTE\t%s\t%s\n' "$PHASE" "$1" | tee -a "$OUT/checks.tsv"
}

as_valet() {
    sudo -u valet -H env PATH="/home/valet/.config/composer/vendor/bin:/home/valet/.composer/vendor/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin" \
        COMPOSER_HOME=/home/valet/.config/composer bash -c "cd ~ && $1"
}

resolves_to() {
    # resolves_to NAME ADDRESS: NSS lookup returns ADDRESS.
    getent ahosts "$1" | awk '{print $1}' | grep -qx "$2"
}

resolves() {
    getent ahosts "$1" >/dev/null
}

resolves_tcp() {
    # Query the first configured nameserver over TCP; log full answers so a
    # failure shows whether the stub or the upstream server misbehaves.
    local server answer
    server="$(awk '/^nameserver/ {print $2; exit}' /etc/resolv.conf)"
    [[ -n "$server" ]] || return 1
    answer="$(dig +tcp +time=3 +tries=2 "@$server" "$1" A 2>&1)"
    if grep -qE "^$1\.\s.*\sA\s+$2$" <<<"$answer"; then
        return 0
    fi
    echo "--- dig +tcp @$server $1"; echo "$answer"
    echo "--- dig +tcp @10.53.0.1 $1"; dig +tcp +time=3 +tries=1 @10.53.0.1 "$1" A 2>&1
    echo "--- dig +notcp @$server $1"; dig +notcp +time=3 +tries=1 "@$server" "$1" A 2>&1
    return 1
}

unit_state() {
    printf '%s/%s' "$(systemctl is-enabled "$1" 2>/dev/null || true)" "$(systemctl is-active "$1" 2>/dev/null || true)"
}

upstreams() {
    # 127.0.0.2 is Valet's own responder, delegated only for its domain.
    if systemctl is-active -q systemd-resolved; then
        resolvectl dns 2>/dev/null | grep -oE '([0-9]{1,3}\.){3}[0-9]{1,3}' | grep -vx 127.0.0.2 | sort -u
    else
        awk '/^nameserver/ {print $2}' "$(readlink -f /etc/resolv.conf)" 2>/dev/null | sort -u
    fi
}

DNS_PATHS=(
    /etc/resolv.conf /etc/resolv.conf.bak /etc/dnsmasq.conf /etc/dnsmasq.conf.bak /etc/default/dnsmasq
    /etc/systemd/resolved.conf /etc/systemd/resolved.conf.bak /etc/hosts
    /etc/systemd/system/valet-dns.service /etc/init.d/valet-dns
    /etc/systemd/system/valet-linux-plus-dns.service /etc/systemd/dns-delegate.d/valet-linux-plus.dns-delegate
)

snapshot() {
    # snapshot LABEL: record DNS-related state for later comparison.
    local dir="$STATE/$1"
    rm -rf "$dir"
    mkdir -p "$dir/files"
    {
        for path in "${DNS_PATHS[@]}" /etc/dnsmasq.d/* /etc/NetworkManager/conf.d/* /opt/valet-linux /opt/valet-linux/*; do
            [[ -e "$path" || -L "$path" ]] || continue
            printf '%s\t%s\t%s\t%s\t' "$path" "$(stat -c '%F' "$path")" "$(stat -c '%a' "$path")" "$(stat -c '%U:%G' "$path")"
            if [[ -L "$path" ]]; then
                printf 'link:%s\n' "$(readlink "$path")"
            elif [[ -f "$path" ]]; then
                printf '%s\n' "$(sha256sum "$path" | cut -d' ' -f1)"
                cp -a "$path" "$dir/files/$(echo "$path" | tr / _)"
            else
                printf -- '-\n'
            fi
            if [[ -f "$path" ]] && lsattr -d "$path" 2>/dev/null | cut -d' ' -f1 | grep -q i; then
                printf '%s\timmutable\n' "$path"
            fi
        done
    } > "$dir/files.tsv"
    for unit in systemd-resolved dnsmasq NetworkManager valet-dns valet-linux-plus-dns; do
        printf '%s\t%s\n' "$unit" "$(unit_state "$unit")"
    done > "$dir/units.tsv"
    {
        echo "resolv.conf -> $(readlink -f /etc/resolv.conf)"
        cat /etc/resolv.conf 2>&1
        echo "--- NetworkManager"
        NetworkManager --print-config 2>/dev/null | grep -E '^\[main\]|^dns=|^rc-manager=' || true
        nmcli -t -f IP4.DNS device show 2>/dev/null || true
        echo "--- resolvectl"
        resolvectl status 2>/dev/null || true
        echo "--- listeners"
        ss -lunp 2>/dev/null | grep ':53 ' || true
    } > "$dir/resolver.txt"
    upstreams > "$dir/upstreams.txt"
    for name in example.com "$LAN_NAME" "$HOSTS_NAME" "$USER_ZONE_NAME" app.test sub.app.test; do
        printf '%s\t%s\n' "$name" "$(getent ahosts "$name" | awk '{print $1}' | sort -u | tr '\n' ' ')"
    done > "$dir/lookups.tsv"
    case "$FAMILY" in
        apt) dpkg --verify dnsmasq dnsmasq-base network-manager 2>&1 | grep -v '^$' ;;
        dnf|zypper|altapt) rpm -V dnsmasq NetworkManager 2>&1 ;;
        pacman) pacman -Qkk dnsmasq networkmanager 2>&1 | grep -v ' 0 altered' ;;
    esac > "$dir/packages.txt" || true
}

same_resolver_as() {
    # same_resolver_as LABEL: resolver setup equals a snapshot.
    local dir="$STATE/$1"
    diff <(grep -E '^/etc/resolv.conf\s' "$dir/files.tsv" | cut -f1,2,5) \
        <(grep -E '^/etc/resolv.conf\s' "$STATE/now/files.tsv" | cut -f1,2,5) &&
    diff <(grep -E '^(systemd-resolved|NetworkManager)\s' "$dir/units.tsv") \
        <(grep -E '^(systemd-resolved|NetworkManager)\s' "$STATE/now/units.tsv") &&
    diff "$dir/upstreams.txt" "$STATE/now/upstreams.txt" &&
    diff <(sed -n '/^--- NetworkManager/,/^--- resolvectl/p' "$dir/resolver.txt" | grep -E '^dns=|^rc-manager=') \
        <(sed -n '/^--- NetworkManager/,/^--- resolvectl/p' "$STATE/now/resolver.txt" | grep -E '^dns=|^rc-manager=')
}

same_files_as() {
    # same_files_as LABEL PATTERN: listed files are identical.
    diff <(grep -E "$2" "$STATE/$1/files.tsv") <(grep -E "$2" "$STATE/now/files.tsv")
}

no_public_upstreams_added() {
    local baseline="$STATE/baseline/upstreams.txt"
    for server in 1.1.1.1 8.8.8.8; do
        if grep -qx "$server" "$STATE/now/upstreams.txt" && ! grep -qx "$server" "$baseline"; then
            return 1
        fi
    done
}

dnsmasq_binary() {
    command -v dnsmasq || echo /usr/sbin/dnsmasq
}

valet_install_package() {
    # valet_install_package SPEC: install Valet through Composer like a user.
    as_valet "composer global config --no-interaction allow-plugins true >/dev/null; composer global require --no-interaction -W '$1'"
}

legacy_markers() {
    [[ -f /etc/dnsmasq.d/valet ]]
}

online() {
    [[ "$(grep -P '^example.com\t' "$STATE/baseline/lookups.tsv" | cut -f2)" != "" ]]
}

verify_restored() {
    # Normal, LAN and Valet resolution after migration.
    snapshot now
    check "LAN name resolves through the network-provided server (UDP)" resolves_to "$LAN_NAME" "$LAN_ADDRESS"
    check "LAN name resolves over TCP" resolves_tcp "$LAN_NAME" "$LAN_ADDRESS"
    check "/etc/hosts entries still resolve" resolves_to "$HOSTS_NAME" 10.53.0.11
    if online; then
        check "public name resolves" resolves example.com
    else
        note "baseline had no public DNS; public lookups not checked"
    fi
    check "resolver setup matches the pre-Valet baseline" same_resolver_as baseline
    check "legacy public upstreams are gone" no_public_upstreams_added
    check "unrelated NetworkManager configuration unchanged" same_files_as baseline '^/etc/NetworkManager/conf.d/90-user.conf'
    check "Valet site name resolves locally" resolves_to app.test 127.0.0.1
    if curl -s --max-time 10 http://app.test/ | grep -qx valet-ok; then
        check "Valet site serves over HTTP" true
    else
        {
            curl -sv --max-time 10 http://app.test/ 2>&1
            echo "--- nginx: $(systemctl is-active nginx 2>&1)"
            journalctl -u nginx --no-pager -b -n 20 2>&1
            ausearch -m AVC -ts boot -c nginx 2>/dev/null | tail -10
        } > "$OUT/http-$PHASE.log"
        check "Valet site serves over HTTP" false
    fi
    if [[ "$(getenforce 2>/dev/null)" == Enforcing ]]; then
        check "only nginx and PHP-FPM run permissive under SELinux" bash -c "semanage permissive -l -n | grep -qx '\s*httpd_t\s*'"
    fi
    if [[ -f /etc/systemd/dns-delegate.d/valet-linux-plus.dns-delegate ]]; then
        check "wildcard subdomain resolves through delegation" resolves_to sub.app.test 127.0.0.1
        check "delegation does not answer for other domains" bash -c "! getent ahosts unregistered-name.example.invalid"
    else
        local systemd_version
        systemd_version="$(systemctl --version | awk 'NR==1 {print $2}')"
        note "using the /etc/hosts backend (systemd $systemd_version)"
        if (( systemd_version >= 258 )); then
            # Delegation was expected; keep the evidence of why it fell back.
            {
                echo "--- valet-linux-plus-dns journal"
                journalctl -u valet-linux-plus-dns --no-pager -n 50 2>&1
                echo "--- SELinux"
                getenforce 2>&1
                ausearch -m AVC -ts recent 2>/dev/null || grep denied /var/log/audit/audit.log 2>/dev/null | tail -20
            } > "$OUT/delegation-fallback-$PHASE.log"
        fi
    fi
    check "no legacy integration remains" bash -c "as_valet 'valet dns:migrate --dry-run --json' | grep -q '\"generation\": \"none\"'"
    if [[ "$SCENARIO" == legacy-user-dnsmasq ]]; then
        check "unrelated dnsmasq zone still answers" bash -c "dig +short @10.54.0.1 $USER_ZONE_NAME | grep -qx 10.54.0.20"
        check "unrelated dnsmasq configuration restored" same_files_as baseline '^/etc/dnsmasq.conf\s'
    fi
}
export -f as_valet

provision() {
    case "$FAMILY" in
        apt)
            export DEBIAN_FRONTEND=noninteractive
            apt-get update -q || die "apt-get update failed"
            apt-get install -y -q network-manager dnsmasq-base php-cli php-curl php-mbstring php-xml php-zip \
                unzip git jq xsel libnss3-tools bind9-dnsutils curl e2fsprogs || die "package installation failed"
            ;;
        dnf)
            if grep -q '^ID="almalinux"' /etc/os-release; then
                dnf install -y epel-release && dnf config-manager --set-enabled crb || die "EPEL setup failed"
            fi
            dnf install -y NetworkManager dnsmasq php-cli php-mbstring php-xml php-pdo php-process php-zip \
                unzip git jq xsel nss-tools bind-utils curl e2fsprogs || die "package installation failed"
            ;;
        pacman)
            pacman -Syu --noconfirm networkmanager dnsmasq php unzip git jq xsel nss bind curl e2fsprogs which \
                || die "package installation failed"
            sed -i -E 's/^;(extension=(iconv|zip))/\1/' /etc/php/php.ini
            ;;
        zypper)
            zypper -n refresh && zypper -n install NetworkManager dnsmasq php8-cli php8-mbstring php8-xmlreader \
                php8-dom php8-pdo php8-posix php8-curl php8-zip php8-phar php8-openssl php8-iconv php8-tokenizer \
                php8-ctype unzip git jq xsel mozilla-nss-tools bind-utils curl e2fsprogs \
                || die "package installation failed"
            ;;
        altapt)
            apt-get update && apt-get install -y NetworkManager-daemon dnsmasq php8.3 php8.3-mbstring php8.3-xmlreader \
                php8.3-dom php8.3-pdo php8.3-posix php8.3-curl php8.3-zip php8.3-phar php8.3-openssl unzip git jq xsel \
                nss-utils bind-utils curl e2fsprogs || gap "ALT package set unavailable; see guest-provision.log"
            ;;
    esac
    command -v php >/dev/null || ln -s "$(ls /usr/bin/php[0-9]* | head -1)" /usr/local/bin/php

    curl -fsSL -o /usr/local/bin/composer "https://getcomposer.org/download/$COMPOSER_VERSION/composer.phar" \
        && echo "$COMPOSER_SHA256  /usr/local/bin/composer" | sha256sum -c - \
        && chmod 755 /usr/local/bin/composer || die "composer download failed"

    # A controlled LAN DNS server, like a home router. It runs in its own
    # network namespace behind a veth pair so that it never competes for
    # port 53 with dnsmasq instances on the host (the legacy install binds
    # every address). It answers lan.vmtest and forwards everything else
    # through a relay on the host side to QEMU's resolver.
    local dnsmasq
    dnsmasq="$(dnsmasq_binary)"
    cat > /etc/systemd/system/vmtest-lan-dns.service <<UNIT
[Unit]
Description=VM test LAN DNS server
Before=NetworkManager.service network-pre.target
Wants=network-pre.target

[Service]
ExecStartPre=-/sbin/ip netns add lan
ExecStartPre=-/sbin/ip link add lan0 type veth peer name lan0p
ExecStartPre=-/sbin/ip link set lan0p netns lan
ExecStartPre=-/sbin/ip addr add 10.53.0.2/24 dev lan0
ExecStartPre=/sbin/ip link set lan0 up
ExecStartPre=-/usr/bin/nsenter --net=/run/netns/lan /sbin/ip addr add 10.53.0.1/24 dev lan0p
ExecStartPre=/usr/bin/nsenter --net=/run/netns/lan /sbin/ip link set lan0p up
ExecStartPre=/usr/bin/nsenter --net=/run/netns/lan /sbin/ip link set lo up
ExecStart=/usr/bin/nsenter --net=/run/netns/lan $dnsmasq --keep-in-foreground --conf-file=/dev/null --no-resolv --no-hosts --bind-interfaces --listen-address=10.53.0.1 --server=10.53.0.2#5354 --local=/lan.vmtest/ --host-record=$LAN_NAME,$LAN_ADDRESS --pid-file=
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT
    cat > /etc/systemd/system/vmtest-lan-relay.service <<UNIT
[Unit]
Description=VM test LAN DNS upstream relay
After=vmtest-lan-dns.service
Requires=vmtest-lan-dns.service

[Service]
ExecStart=$dnsmasq --keep-in-foreground --conf-file=/dev/null --no-resolv --no-hosts --bind-interfaces --listen-address=10.53.0.2 --port=5354 --server=10.0.2.3 --pid-file=
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable vmtest-lan-dns.service vmtest-lan-relay.service
    if command -v getenforce >/dev/null && [[ "$(getenforce)" == Enforcing ]]; then
        # The relay is test infrastructure; let dnsmasq bind its port.
        command -v semanage >/dev/null || dnf install -y policycoreutils-python-utils >/dev/null
        semanage port -a -t dns_port_t -p udp 5354 && semanage port -a -t dns_port_t -p tcp 5354 \
            || die "cannot label the LAN relay port for SELinux"
    fi

    # Desktop-like networking: NetworkManager owns the wired link and uses
    # the LAN server as its DNS server.
    mkdir -p /etc/NetworkManager/conf.d /etc/NetworkManager/system-connections /etc/cloud/cloud.cfg.d
    echo 'network: {config: disabled}' > /etc/cloud/cloud.cfg.d/99-valet-vm-network.cfg
    : > /etc/NetworkManager/conf.d/10-globally-managed-devices.conf
    printf '[connectivity]\nenabled=false\n' > /etc/NetworkManager/conf.d/90-user.conf
    printf '[keyfile]\nunmanaged-devices=interface-name:lan0;interface-name:lan1\n' > /etc/NetworkManager/conf.d/91-vmtest-dummy.conf
    cat > /etc/NetworkManager/system-connections/vmtest-wired.nmconnection <<EOF
[connection]
id=vmtest-wired
type=ethernet
autoconnect=true
autoconnect-priority=100

[ipv4]
method=auto
ignore-auto-dns=true
dns=10.53.0.1;

[ipv6]
method=ignore
EOF
    chmod 600 /etc/NetworkManager/system-connections/vmtest-wired.nmconnection
    # Cloud images ship their own wired profiles (cloud-init, "System eth0").
    for profile in /etc/NetworkManager/system-connections/* /etc/sysconfig/network-scripts/ifcfg-eth* /etc/sysconfig/network-scripts/ifcfg-ens*; do
        [[ -e "$profile" && "$profile" != */vmtest-wired.nmconnection ]] || continue
        if grep -qiE 'type=(ethernet|802-3-ethernet)|^TYPE=Ethernet' "$profile"; then
            echo "removing cloud network profile $profile"
            rm -f "$profile"
        fi
    done
    rm -f /etc/netplan/*.yaml
    if [[ -f /etc/network/interfaces ]] && grep -qE '^(auto|allow-hotplug) (eth|en)' /etc/network/interfaces /etc/network/interfaces.d/* 2>/dev/null; then
        sed -i -E 's/^((auto|allow-hotplug|iface) (eth|en))/#\1/' /etc/network/interfaces /etc/network/interfaces.d/* 2>/dev/null
    fi
    rm -f /etc/systemd/network/*.network
    systemctl disable systemd-networkd.service systemd-networkd.socket 2>/dev/null || true
    for unit in systemd-networkd-wait-online.service; do systemctl disable "$unit" 2>/dev/null || true; done
    systemctl enable NetworkManager.service NetworkManager-wait-online.service 2>/dev/null || systemctl enable NetworkManager.service

    printf '10.53.0.11\t%s\n' "$HOSTS_NAME" >> /etc/hosts

    if [[ "$SCENARIO" == legacy-user-dnsmasq ]]; then
        case "$FAMILY" in
            apt) apt-get install -y -q dnsmasq || die "dnsmasq installation failed" ;;
        esac
        cat > /etc/systemd/system/vmtest-office-link.service <<UNIT
[Unit]
Description=VM test office network link
Before=dnsmasq.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=-/sbin/ip link add lan1 type dummy
ExecStart=-/sbin/ip addr add 10.54.0.1/24 dev lan1
ExecStart=/sbin/ip link set lan1 up

[Install]
WantedBy=multi-user.target
UNIT
        systemctl enable vmtest-office-link.service
        printf '\n# Local office zone\nbind-interfaces\nlisten-address=10.54.0.1\naddress=/%s/10.54.0.20\n' "$USER_ZONE_NAME" >> /etc/dnsmasq.conf
        mkdir -p /etc/systemd/system/dnsmasq.service.d
        printf '[Unit]\nAfter=vmtest-office-link.service\nRequires=vmtest-office-link.service\n' > /etc/systemd/system/dnsmasq.service.d/vmtest.conf
        systemctl daemon-reload
        systemctl enable dnsmasq.service
    fi
    result running "provisioned"
}

scenario() {
    systemctl is-active -q NetworkManager || die "NetworkManager is not running after provisioning"
    snapshot baseline
    if ! resolves_to "$LAN_NAME" "$LAN_ADDRESS"; then
        {
            journalctl -u vmtest-lan-dns -u vmtest-lan-relay --no-pager -b 2>&1 | tail -40
            ip netns list; ip -n lan addr 2>&1; ip addr show lan0 2>&1
            dig +time=2 +tries=1 @10.53.0.1 "$LAN_NAME" 2>&1
            getenforce 2>&1
            ausearch -m AVC -ts boot 2>/dev/null | tail -20
        } > "$OUT/lan-dns-diagnostics.log"
        die "the controlled LAN DNS server does not resolve before Valet; harness problem (see lan-dns-diagnostics.log)"
    fi
    online || note "no public DNS in this VM; public lookups are skipped"
    as_valet "mkdir -p ~/sites/app && echo '<?php echo \"valet-ok\";' > ~/sites/app/index.php"

    if [[ "$SCENARIO" == fresh ]]; then
        install_checkout
        snapshot now
        check "fresh install leaves the resolver unchanged" same_resolver_as baseline
        check "fresh install leaves dnsmasq and NetworkManager files unchanged" \
            same_files_as baseline '^/etc/(dnsmasq|NetworkManager|resolv|default/dnsmasq)'
        verify_restored
        finish_phase
        return
    fi

    local release="$LEGACY_SPEC"
    [[ "$SCENARIO" == legacy-watcher ]] && release="$LEGACY_WATCHER_SPEC"
    echo "--- installing original $release"
    valet_install_package "$release" || gap "original $release cannot be installed with Composer on $DISTRO"
    as_valet "composer global show genesisweb/valet-linux-plus" | tee "$OUT/legacy-package.txt"
    as_valet "valet install --no-interaction --ignore-selinux" > "$OUT/legacy-install.log" 2>&1
    echo "original installer exit status: $?" | tee -a "$OUT/legacy-install.log"
    if ! legacy_markers; then
        # The full installer stops before its DNS step on some distributions
        # (for example Fedora: Debian-style php package names). Run only the
        # original DnsMasq::install() from the installed release, unmodified.
        note "the original installer failed before configuring DNS ($(grep -m1 -iE 'unable to|error|fatal' "$OUT/legacy-install.log" | tr -s ' ' | cut -c1-120)); running only its DNS component"
        legacy_dns_component >> "$OUT/legacy-install.log" 2>&1
        legacy_markers || gap "the original $release DNS component did not configure DNS on $DISTRO (see legacy-install.log)"
    fi
    as_valet "cd ~/sites && valet park" >> "$OUT/legacy-install.log" 2>&1
    sleep 2
    snapshot legacy
    if resolves_to "$LAN_NAME" "$LAN_ADDRESS"; then
        note "LAN DNS still worked after the original install"
    else
        note "the original install broke LAN DNS (expected for 1.6.1+, which forces public upstreams)"
    fi
    if resolves_to sub.app.test 127.0.0.1; then
        note "the original install resolves its wildcard"
    else
        note "the original install's own wildcard does not resolve (its dnsmasq is $(systemctl is-active dnsmasq 2>/dev/null))"
    fi

    if [[ "$SCENARIO" == legacy-uninstalled ]]; then
        as_valet "valet uninstall --no-interaction" > "$OUT/legacy-uninstall.log" 2>&1
        snapshot legacy-uninstalled
        if resolves_to "$LAN_NAME" "$LAN_ADDRESS"; then note "LAN DNS works after the legacy uninstall"; else note "LAN DNS is broken after the legacy uninstall"; fi
        install_checkout
        snapshot now
        check "upgrade does not modify the legacy uninstall residue" \
            same_files_as legacy-uninstalled '^/etc/(dnsmasq|NetworkManager|resolv|default/dnsmasq|systemd/resolved)'
        as_valet "valet dns:migrate --dry-run" | tee "$OUT/plan.txt"
        check "no automatic migration is offered for residue" bash -c "as_valet 'valet dns:migrate --dry-run --json' | grep -q '\"steps\": \\[\\]'"
        finish_phase
        return
    fi

    install_checkout
    snapshot now
    check "normal install does not modify legacy DNS files" \
        same_files_as legacy '^/(etc/(dnsmasq|NetworkManager|resolv|default/dnsmasq|systemd/(resolved|system/valet-dns))|opt/valet-linux)'
    check "normal install warns about the legacy integration" grep -q 'dns:migrate' "$OUT/install.log"
    snapshot pre-migration

    as_valet "valet dns:migrate --dry-run" | tee "$OUT/plan.txt"
    as_valet "valet dns:migrate --dry-run --json" > "$OUT/plan.json"
    snapshot now
    check "dry run changes nothing" same_files_as pre-migration '.'
    local verify=(--verify-host="$LAN_NAME")
    [[ "$SCENARIO" == legacy-user-dnsmasq ]] && verify+=(--verify-host="$USER_ZONE_NAME")
    if ! as_valet "valet dns:migrate --yes ${verify[*]}" | tee "$OUT/migrate.log"; then
        die "migration failed or was blocked (see plan.txt and migrate.log)"
    fi
    # As the install warning advises: finish the install now that DNS works.
    valet_install > "$OUT/install-after-migration.log" 2>&1
    check "valet install succeeds after migration" test "$?" -eq 0
    verify_restored
    snapshot migrated
    finish_phase
}

legacy_dns_component() {
    local home
    home="$(as_valet 'composer global config home' | tail -1)"
    cat > /tmp/legacy-dns.php <<'PHP'
<?php
require getenv('LEGACY_HOME') . '/vendor/autoload.php';
Illuminate\Container\Container::setInstance(new Illuminate\Container\Container());
Valet\Facades\Valet::environmentSetup();
Valet\Facades\DnsMasq::install('test');
echo "original DnsMasq::install() finished\n";
PHP
    env HOME=/home/valet LEGACY_HOME="$home" php /tmp/legacy-dns.php
}

valet_install() {
    # --no-interaction aborts at Valet's database prompts, so answer on stdin.
    # New MySQL: password "root", then link php (default). Existing MariaDB:
    # user root, empty password (socket auth as root), no retry, link php.
    # Debian ships no mysql-server; Valet falls back to MariaDB there.
    as_valet "printf 'root\\n\\nno\\nyes\\n' | valet install"
}

install_checkout() {
    echo "--- installing this checkout"
    as_valet "composer global config repositories.valet-checkout '{\"type\": \"path\", \"url\": \"$CHECKOUT\", \"options\": {\"symlink\": false}}'" \
        || die "composer repository setup failed"
    valet_install_package "genesisweb/valet-linux-plus:*@dev" || die "this checkout cannot be installed with Composer"
    valet_install > "$OUT/install.log" 2>&1
    echo "install exit status: $?" | tee -a "$OUT/install.log"
    as_valet "cd ~/sites && valet park" >> "$OUT/install.log" 2>&1
}

after_reboot() {
    if [[ "$(cut -f3 "$OUT/result.tsv" 2>/dev/null)" =~ ^(gap|fail)$ ]]; then
        echo "Earlier phase ended with $(cut -f3-4 "$OUT/result.tsv"); nothing to do."
        exit 0
    fi
    if [[ "$SCENARIO" == legacy-uninstalled ]]; then
        snapshot now
        check "nothing changed the residue across reboot" \
            same_files_as legacy-uninstalled '^/etc/(dnsmasq|NetworkManager|resolv|default/dnsmasq|systemd/resolved)'
        finish_phase final
        return
    fi

    verify_restored

    if [[ "$SCENARIO" != fresh ]]; then
        as_valet "valet dns:rollback --yes" | tee "$OUT/rollback.log"
        check "rollback reports success" grep -q 'restored and verified' "$OUT/rollback.log"
        snapshot now
        check "rollback restores the pre-migration files" \
            same_files_as pre-migration '^/(etc/(dnsmasq|NetworkManager|resolv|default/dnsmasq|systemd/(resolved|system/valet-dns|dns-delegate))|opt/valet-linux)'
        # Rollback stops a unit that had failed; "failed" and "inactive" are both stopped.
        check "rollback restores the pre-migration services" \
            diff <(sed 's#/failed$#/inactive#' "$STATE/pre-migration/units.tsv") <(sed 's#/failed$#/inactive#' "$STATE/now/units.tsv")
        check "legacy wildcard works again after rollback" resolves_to sub.app.test 127.0.0.1

        local verify=(--verify-host="$LAN_NAME")
        [[ "$SCENARIO" == legacy-user-dnsmasq ]] && verify+=(--verify-host="$USER_ZONE_NAME")
        as_valet "valet dns:migrate --yes ${verify[*]}" | tee "$OUT/migrate-again.log"
        check "migration succeeds again after rollback" grep -q 'Migration completed' "$OUT/migrate-again.log"
        verify_restored
    fi

    as_valet "valet uninstall --no-interaction" > "$OUT/uninstall.log" 2>&1
    snapshot now
    check "uninstall leaves the baseline resolver" same_resolver_as baseline
    check "LAN DNS works after uninstall" resolves_to "$LAN_NAME" "$LAN_ADDRESS"
    check "Valet delegation removed" test ! -e /etc/systemd/dns-delegate.d/valet-linux-plus.dns-delegate
    check "Valet hosts block removed" bash -c "! grep -q 'Valet Linux+ hosts start' /etc/hosts"
    check "user /etc/hosts entry preserved" grep -q "$HOSTS_NAME" /etc/hosts
    finish_phase final
}

finish_phase() {
    if (( FAILURES > 0 )); then
        result fail "$FAILURES check(s) failed in $PHASE; see checks.tsv"
        exit 1
    fi
    if [[ "${1:-}" == final ]]; then
        result pass "all checks passed"
    else
        result running "$PHASE passed"
    fi
}

case "$PHASE" in
    provision) provision ;;
    scenario) scenario ;;
    after-reboot) after_reboot ;;
    *) die "unknown phase $PHASE" ;;
esac

#!/usr/bin/env bash

set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a

PROJECT_DIR="/data/asstats"
REPO_URL="https://github.com/remontti/AS-Stats.git"
LOG_DIR="/var/log/asstats-installer"
LOG_FILE="${LOG_DIR}/install-$(date +%Y%m%d-%H%M%S).log"

mkdir -p "${LOG_DIR}"
exec > >(tee -a "${LOG_FILE}") 2>&1

ASSTATS_PORT_NETFLOW="${ASSTATS_PORT_NETFLOW:-9000}"
ASSTATS_PORT_SFLOW="${ASSTATS_PORT_SFLOW:-6343}"
ASSTATS_MY_ASN="${ASSTATS_MY_ASN:-1234}"
ASSTATS_WEB_ALIAS="${ASSTATS_WEB_ALIAS:-as-stats}"
ASSTATS_ENABLE_UFW="${ASSTATS_ENABLE_UFW:-yes}"

info() {
  printf "\n[INFO] %s\n" "$1"
}

warn() {
  printf "\n[WARN] %s\n" "$1"
}

fail() {
  printf "\n[ERRO] %s\n" "$1" >&2
  exit 1
}

require_root() {
  [[ "${EUID}" -eq 0 ]] || fail "Execute como root: sudo ./install_asstats_ubuntu.sh"
}

detect_ubuntu() {
  [[ -r /etc/os-release ]] || fail "Nao foi possivel ler /etc/os-release"
  # shellcheck disable=SC1091
  source /etc/os-release

  [[ "${ID:-}" == "ubuntu" ]] || fail "Este instalador foi preparado para Ubuntu. Detectado: ${ID:-desconhecido}"

  UBUNTU_VERSION="${VERSION_ID:-}"
  UBUNTU_CODENAME="${VERSION_CODENAME:-}"
  UBUNTU_NAME="${PRETTY_NAME:-Ubuntu}"

  case "${UBUNTU_VERSION}" in
    20.04|22.04|24.04) ;;
    *)
      warn "Versao Ubuntu ${UBUNTU_VERSION} nao foi validada diretamente. Vou continuar no modo generico."
      ;;
  esac

  info "Ubuntu detectado: ${UBUNTU_NAME} (${UBUNTU_CODENAME})"
}

preflight() {
  info "Executando preflight"

  command -v apt-get >/dev/null 2>&1 || fail "apt-get nao encontrado"
  command -v systemctl >/dev/null 2>&1 || fail "systemctl nao encontrado"
  command -v curl >/dev/null 2>&1 || fail "curl nao encontrado"
  command -v wget >/dev/null 2>&1 || fail "wget nao encontrado"
  command -v git >/dev/null 2>&1 || warn "git ainda nao instalado, sera instalado"

  local free_kb
  free_kb="$(df --output=avail / | tail -n 1 | tr -d ' ')"
  [[ -n "${free_kb}" ]] || fail "Nao foi possivel verificar espaco livre"
  (( free_kb >= 2097152 )) || fail "Espaco livre insuficiente em /. Minimo recomendado: 2 GB"

  curl -fsSI https://github.com >/dev/null || fail "Sem acesso ao GitHub"
  curl -fsSI https://blog.remontti.com.br/5129 >/dev/null || warn "Sem acesso ao blog do tutorial, mas o instalador pode continuar"
}

configure_repos() {
  info "Atualizando APT e habilitando universe/multiverse"
  apt-get update
  apt-get install -y software-properties-common ca-certificates gnupg curl wget
  add-apt-repository -y universe || true
  add-apt-repository -y multiverse || true
  apt-get update
}

install_packages() {
  info "Instalando pacotes do sistema"
  apt-get install -y \
    git unzip wget net-tools curl dnsutils whois build-essential \
    perl cpanminus make gcc \
    libnet-patricia-perl libjson-xs-perl netcat-openbsd python3-requests \
    libdbd-sqlite3-perl libtrycatch-perl rrdtool librrds-perl librrdp-perl \
    librrdtool-oo-perl python3-rrdtool librrd-dev rrdcollect \
    apache2 libapache2-mod-php php php-sqlite3 php-cli php-gmp php-gd \
    php-bcmath php-mbstring php-pear php-curl php-xml php-zip libyaml-perl \
    snmp snmp-mibs-downloader
}

install_perl_modules() {
  info "Instalando modulos Perl via cpanminus"
  cpanm --notest Net::sFlow File::Find::Rule
}

install_project() {
  info "Baixando AS-Stats"
  mkdir -p /data

  if [[ -d "${PROJECT_DIR}/.git" ]]; then
    git -C "${PROJECT_DIR}" pull --ff-only
  else
    rm -rf "${PROJECT_DIR}"
    git clone "${REPO_URL}" "${PROJECT_DIR}"
  fi

  mkdir -p "${PROJECT_DIR}/rrd"
  mkdir -p "${PROJECT_DIR}/asstats"
  mkdir -p "${PROJECT_DIR}/www/asset"

  if [[ -f "${PROJECT_DIR}/ip2asn/ip2as.pm" ]]; then
    install -m 0644 "${PROJECT_DIR}/ip2asn/ip2as.pm" /usr/local/share/perl/5.*/ 2>/dev/null || true
    install -m 0644 "${PROJECT_DIR}/ip2asn/ip2as.pm" /usr/share/perl5/ip2as.pm 2>/dev/null || true
  fi
}

configure_snmp() {
  info "Configurando ferramentas SNMP"
  [[ -f /etc/snmp/snmp.conf ]] && cp /etc/snmp/snmp.conf /etc/snmp/snmp.conf.bak-asstats
  : > /etc/snmp/snmp.conf
}

configure_web() {
  info "Configurando acesso web"
  ln -sfn "${PROJECT_DIR}/www" "/var/www/html/${ASSTATS_WEB_ALIAS}"

  if [[ -f "${PROJECT_DIR}/www/config.inc" ]]; then
    sed -i "s/\\\$my_asn = \".*\";/\\\$my_asn = \"${ASSTATS_MY_ASN}\";/" "${PROJECT_DIR}/www/config.inc" || true
  fi

  chown -R www-data:www-data "${PROJECT_DIR}/www/asset"
  a2enmod rewrite >/dev/null 2>&1 || true
  systemctl enable --now apache2
}

configure_knownlinks() {
  info "Criando arquivo conhecido de links"
  if [[ ! -f "${PROJECT_DIR}/conf/knownlinks" ]]; then
    cat > "${PROJECT_DIR}/conf/knownlinks" <<'EOF'
# IP_DO_EXPORTADOR<TAB>IFINDEX<TAB>TAG<TAB>DESCRICAO<TAB>CORHEX<TAB>SAMPLING
# Exemplo:
# 10.20.30.2	6	uplink01	Uplink Principal	1F78B4	1
EOF
  fi
}

install_systemd_units() {
  info "Criando servico e timer do AS-Stats"

  cat > /etc/default/asstats <<EOF
ASSTATS_PORT_NETFLOW=${ASSTATS_PORT_NETFLOW}
ASSTATS_PORT_SFLOW=${ASSTATS_PORT_SFLOW}
ASSTATS_MY_ASN=${ASSTATS_MY_ASN}
ASSTATS_PROJECT_DIR=${PROJECT_DIR}
EOF

  cat > /usr/local/bin/asstatsd-wrapper.sh <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
source /etc/default/asstats

KNOWNLINKS="${ASSTATS_PROJECT_DIR}/conf/knownlinks"
RRD_DIR="${ASSTATS_PROJECT_DIR}/rrd"

[[ -f "${KNOWNLINKS}" ]] || { echo "Arquivo knownlinks nao encontrado: ${KNOWNLINKS}" >&2; exit 1; }

exec perl "${ASSTATS_PROJECT_DIR}/bin/asstatd.pl" \
  -r "${RRD_DIR}" \
  -k "${KNOWNLINKS}" \
  -p "${ASSTATS_PORT_NETFLOW}" \
  -P "${ASSTATS_PORT_SFLOW}" \
  -a "${ASSTATS_MY_ASN}"
EOF
  chmod +x /usr/local/bin/asstatsd-wrapper.sh

  cat > /usr/local/bin/asstats-extract-wrapper.sh <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
source /etc/default/asstats

exec perl "${ASSTATS_PROJECT_DIR}/bin/rrd-extractstats.pl" \
  "${ASSTATS_PROJECT_DIR}/rrd" \
  "${ASSTATS_PROJECT_DIR}/conf/knownlinks" \
  "${ASSTATS_PROJECT_DIR}/asstats/asstats_day.txt"
EOF
  chmod +x /usr/local/bin/asstats-extract-wrapper.sh

  cat > /etc/systemd/system/asstatsd.service <<'EOF'
[Unit]
Description=AS-Stats collector daemon
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/usr/local/bin/asstatsd-wrapper.sh
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

  cat > /etc/systemd/system/asstats-extract.service <<'EOF'
[Unit]
Description=AS-Stats extract hourly summaries
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/asstats-extract-wrapper.sh
EOF

  cat > /etc/systemd/system/asstats-extract.timer <<'EOF'
[Unit]
Description=Run AS-Stats extract every hour

[Timer]
OnCalendar=hourly
Persistent=true

[Install]
WantedBy=timers.target
EOF

  systemctl daemon-reload
  systemctl enable --now asstatsd.service
  systemctl enable --now asstats-extract.timer
}

configure_firewall() {
  [[ "${ASSTATS_ENABLE_UFW}" == "yes" ]] || return
  info "Configurando UFW"
  ufw allow "${ASSTATS_PORT_NETFLOW}/udp" || true
  ufw allow "${ASSTATS_PORT_SFLOW}/udp" || true
  ufw allow 80/tcp || true
}

run_correctives() {
  info "Aplicando corretivas"
  if [[ -f "${PROJECT_DIR}/www/config.inc" ]]; then
    grep -q '\$my_asn' "${PROJECT_DIR}/www/config.inc" || warn "Nao encontrei \$my_asn em config.inc"
  fi

  if [[ ! -e "${PROJECT_DIR}/asstats/asstats_day.txt" ]]; then
    touch "${PROJECT_DIR}/asstats/asstats_day.txt"
  fi
}

verify_installation() {
  info "Validando instalacao"
  systemctl is-enabled asstatsd.service >/dev/null
  systemctl is-enabled asstats-extract.timer >/dev/null
  systemctl is-active apache2 >/dev/null

  if ! systemctl is-active asstatsd.service >/dev/null 2>&1; then
    warn "O servico asstatsd nao subiu. Isso normalmente acontece quando o knownlinks ainda nao foi preenchido corretamente."
  fi

  [[ -f /etc/default/asstats ]] || fail "Arquivo /etc/default/asstats nao foi criado"
  [[ -f "${PROJECT_DIR}/conf/knownlinks" ]] || fail "knownlinks nao foi criado"
  [[ -d "${PROJECT_DIR}/rrd" ]] || fail "Diretorio RRD nao foi criado"
  [[ -L "/var/www/html/${ASSTATS_WEB_ALIAS}" ]] || fail "Atalho web nao foi criado"
}

show_summary() {
  cat <<EOF

Instalacao concluida.

Ubuntu detectado: ${UBUNTU_NAME} (${UBUNTU_CODENAME})
Projeto: ${PROJECT_DIR}
Log: ${LOG_FILE}

Web:
  http://IP_DO_SERVIDOR/${ASSTATS_WEB_ALIAS}

Portas:
  NetFlow/IPFIX: ${ASSTATS_PORT_NETFLOW}/udp
  sFlow: ${ASSTATS_PORT_SFLOW}/udp

Arquivos importantes:
  ${PROJECT_DIR}/conf/knownlinks
  ${PROJECT_DIR}/www/config.inc
  /etc/default/asstats

Servicos:
  systemctl status asstatsd.service
  systemctl status asstats-extract.timer

Proximos passos:
1. Edite ${PROJECT_DIR}/conf/knownlinks com IP, ifIndex, tag, descricao, cor e sampling.
2. Ajuste o ASN local em ${PROJECT_DIR}/www/config.inc se necessario.
3. Configure o roteador para exportar NetFlow v8/v9 AS ou sFlow para este servidor.
4. Rode manualmente:
   perl ${PROJECT_DIR}/bin/rrd-extractstats.pl ${PROJECT_DIR}/rrd ${PROJECT_DIR}/conf/knownlinks ${PROJECT_DIR}/asstats/asstats_day.txt
5. Acesse:
   http://IP_DO_SERVIDOR/${ASSTATS_WEB_ALIAS}

Comandos uteis:
  snmpwalk -v2c -c public IP_DO_ROUTER IF-MIB::ifDescr
  snmpwalk -v2c -c public IP_DO_ROUTER IF-MIB::ifIndex
  journalctl -u asstatsd.service -n 100 --no-pager
EOF
}

main() {
  require_root
  detect_ubuntu
  preflight
  configure_repos
  install_packages
  install_perl_modules
  install_project
  configure_snmp
  configure_web
  configure_knownlinks
  install_systemd_units
  configure_firewall
  run_correctives
  verify_installation
  show_summary
}

main "$@"

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
ASSTATS_EXPORTER_HOST="${ASSTATS_EXPORTER_HOST:-}"
ASSTATS_SNMP_VERSION="${ASSTATS_SNMP_VERSION:-2c}"
ASSTATS_SNMP_COMMUNITY="${ASSTATS_SNMP_COMMUNITY:-public}"
ASSTATS_SNMP_PORT="${ASSTATS_SNMP_PORT:-161}"
ASSTATS_SAMPLING_RATE="${ASSTATS_SAMPLING_RATE:-1}"

info() {
  printf "\n[INFO] %s\n" "$1"
}

warn() {
  printf "\n[WARN] %s\n" "$1"
}

log_error_file() {
  local message="$1"
  printf "[%s] %s\n" "$(date '+%F %T')" "${message}" >> "${LOG_DIR}/errors.log"
}

fail() {
  printf "\n[ERRO] %s\n" "$1" >&2
  log_error_file "$1"
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

build_package_lists() {
  REQUIRED_PACKAGES=(
    git unzip wget net-tools curl dnsutils whois build-essential
    perl cpanminus make gcc
    libnet-patricia-perl libjson-xs-perl netcat-openbsd python3-requests
    libdbd-sqlite3-perl libtrycatch-perl rrdtool librrds-perl librrdp-perl
    librrdtool-oo-perl python3-rrdtool librrd-dev
    apache2 libapache2-mod-php php php-sqlite3 php-cli php-gmp php-gd
    php-bcmath php-mbstring php-pear php-curl php-xml php-zip libyaml-perl
    snmp snmp-mibs-downloader
  )

  OPTIONAL_PACKAGES=(
    rrdcollect
    python3-rrdtool-dbg
  )
}

run_package_corrective() {
  local pkg="$1"
  PACKAGE_CORRECTIVE_TARGET=""

  warn "Pacote ausente detectado: ${pkg}. Tentando corretiva automatica..."

  apt-get update || true

  case "${pkg}" in
    rrdcollect)
      warn "rrdcollect e opcional e costuma nao existir no Ubuntu ${UBUNTU_VERSION}. Vou seguir sem ele."
      return 1
      ;;
    netcat)
      if apt-cache show netcat-openbsd >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="netcat-openbsd"
        return 0
      fi
      ;;
    netcat-openbsd)
      if apt-cache show netcat-traditional >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="netcat-traditional"
        return 0
      fi
      ;;
    python3-rrdtool-dbg)
      warn "python3-rrdtool-dbg e opcional e pode nao existir nesta versao do Ubuntu."
      return 1
      ;;
    php)
      if apt-cache show php8.3 >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3"
        return 0
      fi
      ;;
    libapache2-mod-php)
      if apt-cache show libapache2-mod-php8.3 >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="libapache2-mod-php8.3"
        return 0
      fi
      ;;
    php-cli)
      if apt-cache show php8.3-cli >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3-cli"
        return 0
      fi
      ;;
    php-curl)
      if apt-cache show php8.3-curl >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3-curl"
        return 0
      fi
      ;;
    php-gd)
      if apt-cache show php8.3-gd >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3-gd"
        return 0
      fi
      ;;
    php-xml)
      if apt-cache show php8.3-xml >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3-xml"
        return 0
      fi
      ;;
    php-mbstring)
      if apt-cache show php8.3-mbstring >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3-mbstring"
        return 0
      fi
      ;;
    php-sqlite3)
      if apt-cache show php8.3-sqlite3 >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3-sqlite3"
        return 0
      fi
      ;;
    php-bcmath)
      if apt-cache show php8.3-bcmath >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3-bcmath"
        return 0
      fi
      ;;
    php-gmp)
      if apt-cache show php8.3-gmp >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3-gmp"
        return 0
      fi
      ;;
    php-zip)
      if apt-cache show php8.3-zip >/dev/null 2>&1; then
        PACKAGE_CORRECTIVE_TARGET="php8.3-zip"
        return 0
      fi
      ;;
    php-pear)
      if apt-cache show php-pear >/dev/null 2>&1; then
        return 0
      fi
      ;;
  esac

  return 1
}

filter_installable_packages() {
  INSTALL_PACKAGES=()
  SKIPPED_PACKAGES=()

  local pkg
  for pkg in "${REQUIRED_PACKAGES[@]}"; do
    if apt-cache show "${pkg}" >/dev/null 2>&1; then
      INSTALL_PACKAGES+=("${pkg}")
    else
      if run_package_corrective "${pkg}"; then
        if [[ -n "${PACKAGE_CORRECTIVE_TARGET:-}" ]] && apt-cache show "${PACKAGE_CORRECTIVE_TARGET}" >/dev/null 2>&1; then
          INSTALL_PACKAGES+=("${PACKAGE_CORRECTIVE_TARGET}")
        elif apt-cache show "${pkg}" >/dev/null 2>&1; then
          INSTALL_PACKAGES+=("${pkg}")
        else
          fail "Corretiva executada, mas o pacote ainda nao ficou disponivel: ${pkg}"
        fi
      else
        fail "Pacote obrigatorio nao encontrado no Ubuntu detectado e sem corretiva valida: ${pkg}"
      fi
    fi
  done

  for pkg in "${OPTIONAL_PACKAGES[@]}"; do
    if apt-cache show "${pkg}" >/dev/null 2>&1; then
      INSTALL_PACKAGES+=("${pkg}")
    else
      SKIPPED_PACKAGES+=("${pkg}")
    fi
  done
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
  build_package_lists
  filter_installable_packages

  if (( ${#SKIPPED_PACKAGES[@]} > 0 )); then
    warn "Pacotes opcionais nao encontrados e ignorados: ${SKIPPED_PACKAGES[*]}"
    log_error_file "Pacotes opcionais ignorados: ${SKIPPED_PACKAGES[*]}"
  fi

  apt-get install -y "${INSTALL_PACKAGES[@]}"
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

prompt_exporter_config() {
  info "Configurando descoberta automatica do exportador via SNMP"

  read -r -p "IP ou hostname do equipamento que exporta flow: " input_exporter_host
  [[ -n "${input_exporter_host}" ]] && ASSTATS_EXPORTER_HOST="${input_exporter_host}"
  [[ -n "${ASSTATS_EXPORTER_HOST}" ]] || fail "Voce precisa informar o host exportador"

  read -r -p "Versao SNMP [2c]: " input_snmp_version
  [[ -n "${input_snmp_version}" ]] && ASSTATS_SNMP_VERSION="${input_snmp_version}"

  read -r -p "Comunidade SNMP [public]: " input_snmp_community
  [[ -n "${input_snmp_community}" ]] && ASSTATS_SNMP_COMMUNITY="${input_snmp_community}"

  read -r -p "Sampling rate [1]: " input_sampling
  [[ -n "${input_sampling}" ]] && ASSTATS_SAMPLING_RATE="${input_sampling}"

  info "Testando acesso SNMP ao exportador ${ASSTATS_EXPORTER_HOST}:${ASSTATS_SNMP_PORT}"
  snmpwalk -v2c -c "${ASSTATS_SNMP_COMMUNITY}" \
    "${ASSTATS_EXPORTER_HOST}:${ASSTATS_SNMP_PORT}" \
    1.3.6.1.2.1.1.1 >/dev/null || fail "Falha no acesso SNMP ao exportador ${ASSTATS_EXPORTER_HOST}"
}

generate_tag() {
  local source candidate suffix
  source="$1"
  candidate="$(printf '%s' "${source}" | tr -c '[:alnum:]' '_' | sed 's/^_*//; s/_*$//; s/__*/_/g' | cut -c1-12)"
  [[ -n "${candidate}" ]] || candidate="if${2}"

  if [[ -z "${USED_TAGS[${candidate}]:-}" ]]; then
    USED_TAGS["${candidate}"]=1
    printf '%s' "${candidate}"
    return
  fi

  suffix=1
  while :; do
    candidate="$(printf '%.10s%02d' "$(printf '%s' "${source}" | tr -c '[:alnum:]' '_' | sed 's/^_*//; s/_*$//; s/__*/_/g')" "${suffix}")"
    if [[ -z "${USED_TAGS[${candidate}]:-}" ]]; then
      USED_TAGS["${candidate}"]=1
      printf '%s' "${candidate}"
      return
    fi
    suffix=$((suffix + 1))
  done
}

discover_and_fill_knownlinks() {
  info "Descobrindo interfaces ativas via SNMP e preenchendo knownlinks"

  declare -gA IF_DESCRS=()
  declare -gA IF_ALIASES=()
  declare -gA IF_OPER=()
  declare -gA USED_TAGS=()

  local oid_ifdescr oid_ifalias oid_ifoper line index value desc alias color_index tag description
  local preview_file confirm
  oid_ifdescr="1.3.6.1.2.1.2.2.1.2"
  oid_ifalias="1.3.6.1.2.1.31.1.1.1.18"
  oid_ifoper="1.3.6.1.2.1.2.2.1.8"

  while IFS= read -r line; do
    [[ "${line}" =~ \.([0-9]+)[[:space:]]*=[[:space:]]*(.*)$ ]] || continue
    index="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"
    value="${value#STRING: }"
    value="${value#Hex-STRING: }"
    value="${value#INTEGER: }"
    value="${value#\"}"
    value="${value%\"}"
    IF_DESCRS["${index}"]="${value}"
  done < <(snmpwalk -v2c -c "${ASSTATS_SNMP_COMMUNITY}" -On \
      "${ASSTATS_EXPORTER_HOST}:${ASSTATS_SNMP_PORT}" "${oid_ifdescr}")

  while IFS= read -r line; do
    [[ "${line}" =~ \.([0-9]+)[[:space:]]*=[[:space:]]*(.*)$ ]] || continue
    index="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"
    value="${value#STRING: }"
    value="${value#\"}"
    value="${value%\"}"
    IF_ALIASES["${index}"]="${value}"
  done < <(snmpwalk -v2c -c "${ASSTATS_SNMP_COMMUNITY}" -On \
      "${ASSTATS_EXPORTER_HOST}:${ASSTATS_SNMP_PORT}" "${oid_ifalias}" 2>/dev/null || true)

  while IFS= read -r line; do
    [[ "${line}" =~ \.([0-9]+)[[:space:]]*=[[:space:]]*(.*)$ ]] || continue
    index="${BASH_REMATCH[1]}"
    value="${BASH_REMATCH[2]}"
    value="${value#INTEGER: }"
    value="${value%%(*}"
    value="${value// /}"
    IF_OPER["${index}"]="${value}"
  done < <(snmpwalk -v2c -c "${ASSTATS_SNMP_COMMUNITY}" -On \
      "${ASSTATS_EXPORTER_HOST}:${ASSTATS_SNMP_PORT}" "${oid_ifoper}")

  preview_file="$(mktemp)"

  cat > "${preview_file}" <<'EOF'
# IP_DO_EXPORTADOR<TAB>IFINDEX<TAB>TAG<TAB>DESCRICAO<TAB>CORHEX<TAB>SAMPLING
# Gerado automaticamente pelo instalador
EOF

  local -a colors
  colors=(1F78B4 33A02C E31A1C FF7F00 6A3D9A A6CEE3 B2DF8A FB9A99 CAB2D6 FDBF6F)
  color_index=0

  while IFS= read -r index; do
    [[ -n "${IF_DESCRS[${index}]:-}" ]] || continue
    [[ "${IF_OPER[${index}]:-2}" == "1" ]] || continue

    desc="${IF_DESCRS[${index}]}"
    alias="${IF_ALIASES[${index}]:-}"
    description="${alias:-${desc}}"
    tag="$(generate_tag "${desc}" "${index}")"

    printf '%s\t%s\t%s\t%s\t%s\t%s\n' \
      "${ASSTATS_EXPORTER_HOST}" \
      "${index}" \
      "${tag}" \
      "${description}" \
      "${colors[$((color_index % ${#colors[@]}))]}" \
      "${ASSTATS_SAMPLING_RATE}" >> "${preview_file}"

    color_index=$((color_index + 1))
  done < <(printf '%s\n' "${!IF_DESCRS[@]}" | sort -n)

  if ! grep -qvE '^\s*#|^\s*$' "${preview_file}"; then
    rm -f "${preview_file}"
    fail "Nenhuma interface ativa foi encontrada via SNMP para gerar o knownlinks"
  fi

  info "Interfaces encontradas para o knownlinks:"
  printf "\n"
  awk -F '\t' 'BEGIN { printf "%-16s %-8s %-14s %-40s %-8s %-8s\n", "EXPORTADOR", "IFINDEX", "TAG", "DESCRICAO", "COR", "SAMPLE" }
    /^[[:space:]]*#/ { next }
    NF >= 6 { printf "%-16s %-8s %-14s %-40s %-8s %-8s\n", $1, $2, $3, $4, $5, $6 }' "${preview_file}"
  printf "\n"

  read -r -p "Confirmar gravacao do knownlinks com essas interfaces? [Y/n]: " confirm
  confirm="${confirm:-Y}"

  case "${confirm}" in
    Y|y|yes|YES)
      mv "${preview_file}" "${PROJECT_DIR}/conf/knownlinks"
      ;;
    *)
      rm -f "${preview_file}"
      fail "Gravacao do knownlinks cancelada pelo usuario"
      ;;
  esac

  info "knownlinks gerado automaticamente com $(grep -cvE '^\s*#|^\s*$' "${PROJECT_DIR}/conf/knownlinks") interfaces"
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
  prompt_exporter_config
  discover_and_fill_knownlinks
}

install_systemd_units() {
  info "Criando servico e timer do AS-Stats"

  cat > /etc/default/asstats <<EOF
ASSTATS_PORT_NETFLOW=${ASSTATS_PORT_NETFLOW}
ASSTATS_PORT_SFLOW=${ASSTATS_PORT_SFLOW}
ASSTATS_MY_ASN=${ASSTATS_MY_ASN}
ASSTATS_PROJECT_DIR=${PROJECT_DIR}
ASSTATS_EXPORTER_HOST=${ASSTATS_EXPORTER_HOST}
ASSTATS_SNMP_VERSION=${ASSTATS_SNMP_VERSION}
ASSTATS_SNMP_COMMUNITY=${ASSTATS_SNMP_COMMUNITY}
ASSTATS_SNMP_PORT=${ASSTATS_SNMP_PORT}
ASSTATS_SAMPLING_RATE=${ASSTATS_SAMPLING_RATE}
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
  if grep -qvE '^\s*#|^\s*$' "${PROJECT_DIR}/conf/knownlinks"; then
    systemctl enable --now asstatsd.service
  else
    warn "knownlinks sem interfaces validas. O servico asstatsd nao sera iniciado automaticamente."
  fi
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
2. Revise o arquivo ${PROJECT_DIR}/conf/knownlinks e ajuste se necessario.
3. Ajuste o ASN local em ${PROJECT_DIR}/www/config.inc se necessario.
4. Configure o roteador para exportar NetFlow v8/v9 AS ou sFlow para este servidor.
5. Rode manualmente:
   perl ${PROJECT_DIR}/bin/rrd-extractstats.pl ${PROJECT_DIR}/rrd ${PROJECT_DIR}/conf/knownlinks ${PROJECT_DIR}/asstats/asstats_day.txt
6. Acesse:
   http://IP_DO_SERVIDOR/${ASSTATS_WEB_ALIAS}

Comandos uteis:
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

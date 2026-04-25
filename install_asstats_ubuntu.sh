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
ASSTATS_SNMP_COMMUNITY="${ASSTATS_SNMP_COMMUNITY:-public}"
ASSTATS_SAMPLING_RATE="${ASSTATS_SAMPLING_RATE:-128}"
RAW_WALK_FILE=""

info() {
  printf "\n[INFO] %s\n" "$1"
}

warn() {
  printf "\n[WARN] %s\n" "$1"
}

parse_snmp_integer() {
  local raw="$1"

  raw="${raw#INTEGER: }"
  raw="${raw// /}"

  if [[ "${raw}" =~ \(([0-9]+)\) ]]; then
    printf '%s' "${BASH_REMATCH[1]}"
    return
  fi

  raw="${raw%%(*}"
  printf '%s' "${raw}"
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
  command -v timeout >/dev/null 2>&1 || warn "timeout nao encontrado, o snmpwalk bruto vai depender apenas da resposta do equipamento"
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
    libdbd-sqlite3-perl sqlite3 libtrycatch-perl rrdtool librrds-perl librrdp-perl
    librrdtool-oo-perl python3-rrdtool librrd-dev
    apache2 libapache2-mod-php php php-sqlite3 php-cli php-gmp php-gd
    php-bcmath php-mbstring php-pear php-curl php-xml php-zip libyaml-perl
    snmp snmp-mibs-downloader tcpdump
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
  mkdir -p "${PROJECT_DIR}/conf"
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

  read -r -p "Comunidade SNMP [public]: " input_snmp_community
  [[ -n "${input_snmp_community}" ]] && ASSTATS_SNMP_COMMUNITY="${input_snmp_community}"

  info "Testando acesso SNMP ao exportador ${ASSTATS_EXPORTER_HOST}"
  snmpwalk -v2c -c "${ASSTATS_SNMP_COMMUNITY}" \
    "${ASSTATS_EXPORTER_HOST}" \
    1.3.6.1.2.1.1.1 >/dev/null || fail "Falha no acesso SNMP ao exportador ${ASSTATS_EXPORTER_HOST}"
}

validate_snmp_raw_walk() {
  info "Validando comunicacao SNMP bruta antes de aplicar filtros"

  local raw_sample_file raw_count
  raw_sample_file="$(mktemp)"

  if command -v timeout >/dev/null 2>&1; then
    if ! timeout 60 snmpwalk -v2c -c "${ASSTATS_SNMP_COMMUNITY}" "${ASSTATS_EXPORTER_HOST}" \
      2>>"${LOG_DIR}/errors.log" | tee "${raw_sample_file}" >/dev/null; then
      if [[ "${PIPESTATUS[0]:-1}" != "124" ]]; then
        rm -f "${raw_sample_file}"
        fail "Falha no snmpwalk bruto contra ${ASSTATS_EXPORTER_HOST}"
      fi
    fi
  elif ! snmpwalk -v2c -c "${ASSTATS_SNMP_COMMUNITY}" "${ASSTATS_EXPORTER_HOST}" \
    2>>"${LOG_DIR}/errors.log" | tee "${raw_sample_file}" >/dev/null; then
    rm -f "${raw_sample_file}"
    fail "Falha no snmpwalk bruto contra ${ASSTATS_EXPORTER_HOST}"
  fi

  raw_count="$(grep -cve '^\s*$' "${raw_sample_file}" || true)"
  if [[ "${raw_count}" == "0" ]]; then
    rm -f "${raw_sample_file}"
    fail "O snmpwalk bruto respondeu vazio para ${ASSTATS_EXPORTER_HOST}"
  fi

  info "SNMP bruto respondeu ${raw_count} linhas"
  info "Amostra da resposta SNMP bruta:"
  head -n 10 "${raw_sample_file}"
  {
    printf '[%s] Amostra snmpwalk bruto para %s\n' "$(date '+%F %T')" "${ASSTATS_EXPORTER_HOST}"
    head -n 20 "${raw_sample_file}"
    printf '\n'
  } >> "${LOG_DIR}/errors.log"

  RAW_WALK_FILE="${raw_sample_file}"
}

generate_tag() {
  local candidate suffix
  candidate="if$2"

  if [[ -z "${USED_TAGS[${candidate}]:-}" ]]; then
    USED_TAGS["${candidate}"]=1
    printf '%s' "${candidate}"
    return
  fi

  suffix=1
  while :; do
    candidate="if${2}_${suffix}"
    if [[ -z "${USED_TAGS[${candidate}]:-}" ]]; then
      USED_TAGS["${candidate}"]=1
      printf '%s' "${candidate}"
      return
    fi
    suffix=$((suffix + 1))
  done
}

discover_and_fill_knownlinks() {
  info "Processando o dump bruto SNMP e preenchendo knownlinks"

  declare -gA IF_INDEXES=()
  declare -gA IF_DESCRS=()
  declare -gA IF_ALIASES=()
  declare -gA USED_TAGS=()

  local line index value desc alias color_index tag description
  local index_count descr_count alias_count selected_count
  local preview_file confirm
  [[ -n "${RAW_WALK_FILE}" && -f "${RAW_WALK_FILE}" ]] || fail "Dump bruto SNMP nao encontrado para processar"

  while IFS= read -r line; do
    [[ "${line}" =~ (IF-MIB::ifIndex|\.1\.3\.6\.1\.2\.1\.2\.2\.1\.1)\.([0-9]+)[[:space:]]*=[[:space:]]*(.*)$ ]] || continue
    index="${BASH_REMATCH[2]}"
    IF_INDEXES["${index}"]="${index}"
  done < "${RAW_WALK_FILE}"

  while IFS= read -r line; do
    [[ "${line}" =~ (IF-MIB::ifDescr|\.1\.3\.6\.1\.2\.1\.2\.2\.1\.2)\.([0-9]+)[[:space:]]*=[[:space:]]*(.*)$ ]] || continue
    index="${BASH_REMATCH[2]}"
    value="${BASH_REMATCH[3]}"
    value="${value#STRING: }"
    value="${value#Hex-STRING: }"
    value="${value#INTEGER: }"
    value="${value#\"}"
    value="${value%\"}"
    IF_DESCRS["${index}"]="${value}"
  done < "${RAW_WALK_FILE}"

  while IFS= read -r line; do
    [[ "${line}" =~ (IF-MIB::ifAlias|IF-MIB::ifName|\.1\.3\.6\.1\.2\.1\.31\.1\.1\.1\.18|\.1\.3\.6\.1\.2\.1\.31\.1\.1\.1\.1)\.([0-9]+)[[:space:]]*=[[:space:]]*(.*)$ ]] || continue
    index="${BASH_REMATCH[2]}"
    value="${BASH_REMATCH[3]}"
    value="${value#STRING: }"
    value="${value#\"}"
      value="${value%\"}"
      IF_ALIASES["${index}"]="${value}"
  done < "${RAW_WALK_FILE}"
 
  index_count="${#IF_INDEXES[@]}"
  descr_count="${#IF_DESCRS[@]}"
  alias_count="${#IF_ALIASES[@]}"

  info "Interfaces mapeadas no dump bruto: ifIndex=${index_count}, ifDescr=${descr_count}, ifAlias/ifName=${alias_count}"
  {
    printf '[%s] Resumo do dump bruto para %s: ifIndex=%s ifDescr=%s ifAlias/ifName=%s\n' \
      "$(date '+%F %T')" "${ASSTATS_EXPORTER_HOST}" "${index_count}" "${descr_count}" "${alias_count}"
  } >> "${LOG_DIR}/errors.log"

  preview_file="$(mktemp)"

  cat > "${preview_file}" <<'EOF'
# IP_DO_EXPORTADOR<TAB>IFINDEX<TAB>TAG<TAB>DESCRICAO<TAB>CORHEX<TAB>SAMPLING
# Gerado automaticamente pelo instalador
EOF

  local -a colors
  colors=(1F78B4 33A02C E31A1C FF7F00 6A3D9A A6CEE3 B2DF8A FB9A99 CAB2D6 FDBF6F)
  color_index=0
  selected_count=0

  while IFS= read -r index; do
    [[ -n "${IF_INDEXES[${index}]:-}" ]] || continue
    [[ -n "${IF_DESCRS[${index}]:-}" ]] || continue

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
    selected_count=$((selected_count + 1))
  done < <(printf '%s\n' "${!IF_INDEXES[@]}" | sort -n)

  if ! grep -qvE '^\s*#|^\s*$' "${preview_file}"; then
    log_error_file "Nenhuma interface elegivel encontrada via SNMP. ifIndex=${index_count} ifDescr=${descr_count} ifAlias/ifName=${alias_count}"
    {
      printf '[%s] Primeiros ifIndex/ifDescr parseados:\n' "$(date '+%F %T')"
      printf '%s\n' "${!IF_INDEXES[@]}" | sort -n | head -n 15 | while IFS= read -r idx; do
        printf 'ifIndex=%s ifDescr=%s\n' "${idx}" "${IF_DESCRS[${idx}]:-}"
      done
      printf '\n'
    } >> "${LOG_DIR}/errors.log"
    rm -f "${preview_file}"
    fail "Nenhuma interface elegivel foi encontrada via SNMP para gerar o knownlinks"
  fi

  info "Interfaces elegiveis encontradas apos filtro: ${selected_count}"
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
        chmod 0644 "${PROJECT_DIR}/conf/knownlinks"
        ;;
    *)
      rm -f "${preview_file}"
      fail "Gravacao do knownlinks cancelada pelo usuario"
      ;;
  esac

  info "knownlinks gerado automaticamente com $(grep -cvE '^\s*#|^\s*$' "${PROJECT_DIR}/conf/knownlinks") interfaces"
}

customize_web_ui() {
  info "Aplicando tema futurista CECTI na WebUI"

  [[ -d "${PROJECT_DIR}/www/css" ]] || {
    warn "Diretorio de CSS da WebUI nao encontrado em ${PROJECT_DIR}/www/css"
    return
  }

  cat > "${PROJECT_DIR}/www/css/custom.css" <<'EOF'
:root {
  --cecti-bg: #07111f;
  --cecti-panel: rgba(10, 21, 38, 0.82);
  --cecti-border: rgba(77, 212, 255, 0.28);
  --cecti-text: #eaf7ff;
  --cecti-muted: #8aa6bf;
  --cecti-accent: #4dd4ff;
  --cecti-accent-2: #00ffa6;
  --cecti-shadow: 0 22px 50px rgba(0, 0, 0, 0.42);
}

html,
body {
  min-height: 100%;
  background:
    radial-gradient(circle at top left, rgba(0, 255, 166, 0.10), transparent 26%),
    radial-gradient(circle at top right, rgba(77, 212, 255, 0.13), transparent 28%),
    linear-gradient(180deg, #06101c 0%, #081321 48%, #050b14 100%);
  color: var(--cecti-text);
}

body.hold-transition {
  font-family: "Segoe UI", "Trebuchet MS", sans-serif;
}

.wrapper,
.content-wrapper,
.main-footer,
.main-header .navbar {
  background: transparent !important;
}

.content-wrapper {
  position: relative;
  min-height: calc(100vh - 56px);
  padding-bottom: 26px;
}

.content-wrapper::before {
  content: "";
  position: fixed;
  inset: 0;
  background-image:
    linear-gradient(rgba(77, 212, 255, 0.05) 1px, transparent 1px),
    linear-gradient(90deg, rgba(77, 212, 255, 0.05) 1px, transparent 1px);
  background-size: 32px 32px;
  pointer-events: none;
  z-index: 0;
}

.content-wrapper > * {
  position: relative;
  z-index: 1;
}

.main-header .navbar {
  border: 0;
  box-shadow: none;
}

.main-header .container {
  margin-top: 14px;
  padding: 0 18px;
  border: 1px solid var(--cecti-border);
  border-radius: 20px;
  background: rgba(7, 17, 31, 0.78);
  backdrop-filter: blur(14px);
  box-shadow: var(--cecti-shadow);
}

.navbar-brand {
  display: flex !important;
  align-items: center;
  gap: 10px;
  height: 58px;
  padding: 0 18px !important;
  color: var(--cecti-text) !important;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.brand-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 68px;
  height: 32px;
  padding: 0 12px;
  border: 1px solid rgba(77, 212, 255, 0.35);
  border-radius: 999px;
  background: linear-gradient(135deg, rgba(77, 212, 255, 0.22), rgba(0, 255, 166, 0.12));
  box-shadow: 0 0 24px rgba(77, 212, 255, 0.16);
  font-size: 11px;
  font-weight: 700;
}

.brand-text {
  font-size: 12px;
  font-weight: 600;
  color: var(--cecti-muted);
}

.navbar-nav > li > a,
.navbar-form .btn,
.navbar-toggle {
  color: var(--cecti-text) !important;
}

.navbar-nav > li > a {
  height: 58px;
  padding-top: 19px;
  padding-bottom: 19px;
  font-size: 13px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.navbar-nav > .active > a,
.navbar-nav > li > a:hover,
.navbar-nav > li > a:focus,
.navbar-nav > .open > a,
.navbar-nav > .open > a:hover,
.navbar-nav > .open > a:focus {
  background: rgba(77, 212, 255, 0.10) !important;
  color: #ffffff !important;
}

.dropdown-menu {
  border: 1px solid var(--cecti-border);
  border-radius: 16px;
  background: rgba(8, 20, 37, 0.96);
  box-shadow: var(--cecti-shadow);
}

.dropdown-menu > li > a {
  color: var(--cecti-text);
}

.dropdown-menu > li > a:hover {
  background: rgba(77, 212, 255, 0.12);
  color: #ffffff;
}

.content-header {
  margin: 26px 18px 20px;
  padding: 24px 28px;
  border: 1px solid var(--cecti-border);
  border-radius: 26px;
  background:
    linear-gradient(135deg, rgba(77, 212, 255, 0.12), rgba(0, 255, 166, 0.05)),
    rgba(7, 17, 31, 0.78);
  box-shadow: var(--cecti-shadow);
}

.content-header > h1 {
  margin: 0;
  font-size: 30px;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: 0.03em;
}

.content-header > h1 > small {
  display: inline-block;
  margin-left: 10px;
  color: var(--cecti-accent);
  font-size: 15px;
}

.content-header > .breadcrumb {
  position: static;
  float: none;
  margin-top: 14px;
  padding: 0;
  background: transparent;
  color: var(--cecti-muted);
  font-size: 13px;
}

hr {
  display: none;
}

.content {
  padding: 0 18px 28px;
}

.box {
  overflow: hidden;
  border: 1px solid var(--cecti-border);
  border-radius: 24px;
  background: var(--cecti-panel);
  box-shadow: var(--cecti-shadow);
}

.box::before {
  content: "";
  display: block;
  height: 3px;
  background: linear-gradient(90deg, var(--cecti-accent), var(--cecti-accent-2));
}

.box-header,
.box-header.with-border {
  border-bottom: 1px solid rgba(77, 212, 255, 0.12);
}

.box-title,
.box-header > .fa {
  color: #ffffff !important;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.box-body,
.box-footer {
  background: transparent;
  color: var(--cecti-text);
}

.btn,
.form-control,
.input-group .input-group-btn .btn {
  border-radius: 14px !important;
}

.btn {
  border: 1px solid rgba(77, 212, 255, 0.24);
  background: linear-gradient(135deg, rgba(77, 212, 255, 0.20), rgba(0, 255, 166, 0.12));
  color: #ffffff;
}

.btn:hover,
.btn:focus {
  color: #ffffff;
  box-shadow: 0 0 24px rgba(77, 212, 255, 0.16);
}

.form-control {
  border: 1px solid rgba(77, 212, 255, 0.18);
  background: rgba(4, 12, 23, 0.92);
  color: #ffffff;
}

.form-control:focus {
  border-color: rgba(77, 212, 255, 0.45);
  box-shadow: 0 0 0 3px rgba(77, 212, 255, 0.08);
}

.li-padding {
  margin-bottom: 14px;
  padding: 18px 16px;
  border: 1px solid rgba(77, 212, 255, 0.10);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.02);
}

li.even {
  background: rgba(77, 212, 255, 0.05);
}

.rank {
  color: var(--cecti-accent);
  text-shadow: 0 0 20px rgba(77, 212, 255, 0.18);
}

.small,
small {
  color: var(--cecti-muted) !important;
}

a {
  color: var(--cecti-accent);
}

a:hover,
a:focus {
  color: #8febff;
}

.main-footer {
  margin: 22px 18px 12px;
  padding: 18px 24px;
  border: 1px solid var(--cecti-border);
  border-radius: 20px;
  background: rgba(7, 17, 31, 0.78);
  color: var(--cecti-muted);
  box-shadow: var(--cecti-shadow);
}

.main-footer strong {
  color: #ffffff;
}

img.img-responsive {
  border-radius: 14px;
  box-shadow: 0 18px 34px rgba(0, 0, 0, 0.34);
}

table,
pre {
  color: var(--cecti-text);
}

.searchBox {
  background-image: url('../images/ajax-loader.gif');
  background-repeat: no-repeat;
  background-position: center;
}

@media (max-width: 767px) {
  .main-header .container,
  .content-header,
  .content,
  .main-footer {
    margin-left: 10px;
    margin-right: 10px;
  }

  .navbar-brand {
    gap: 8px;
    padding-left: 12px !important;
    padding-right: 12px !important;
  }

  .brand-text {
    display: none;
  }

  .content-header > h1 {
    font-size: 24px;
  }
}
EOF

  if [[ -f "${PROJECT_DIR}/www/func.inc" ]]; then
    sed -i 's|<a href="index.php" class="navbar-brand"><b>AS-Stats</b></a>|<a href="index.php" class="navbar-brand"><span class="brand-mark">CECTI</span><span class="brand-text">Flow Observatory</span></a>|' "${PROJECT_DIR}/www/func.inc"
    sed -i 's|<b>GUI Version</b> 0.2|<b>Interface</b> Futuristic Edition|' "${PROJECT_DIR}/www/func.inc"
    sed -i 's|<strong>AS-Stats v1.6</strong> por Manuel Kasper|<strong>CECTI Flow Observatory</strong>|' "${PROJECT_DIR}/www/func.inc"
    sed -i 's| - GUI por Nicolas Debrigode||' "${PROJECT_DIR}/www/func.inc"
    sed -i 's| - Personalizado e traduzido por Rudimar Remontti| - personalizado por CECTI|' "${PROJECT_DIR}/www/func.inc"
    sed -i 's|<a href="index.php">Top AS</a>|<a href="index.php">Radar AS</a>|g' "${PROJECT_DIR}/www/func.inc"
    sed -i 's|Top AS <span class="caret"></span>|Radar AS <span class="caret"></span>|g' "${PROJECT_DIR}/www/func.inc"
    sed -i 's|Top AS - |Radar AS - |g' "${PROJECT_DIR}/www/func.inc"
    sed -i 's|<a href="history.php">Ver AS</a>|<a href="history.php">Consultar AS</a>|' "${PROJECT_DIR}/www/func.inc"
    sed -i 's|<a href="asset.php">Ver AS-SET</a>|<a href="asset.php">Consultar AS-SET</a>|' "${PROJECT_DIR}/www/func.inc"
    sed -i 's|<a href="ix.php">Ver IX Stats</a>|<a href="ix.php">IX Analytics</a>|' "${PROJECT_DIR}/www/func.inc"
    sed -i 's|Link Usage|Fluxo por Link|g' "${PROJECT_DIR}/www/func.inc"
  else
    warn "Arquivo ${PROJECT_DIR}/www/func.inc nao encontrado para personalizacao"
  fi
}

configure_web() {
  info "Configurando acesso web"
  ln -sfn "${PROJECT_DIR}/www" "/var/www/html/${ASSTATS_WEB_ALIAS}"

  if [[ -f "${PROJECT_DIR}/www/config.inc" ]]; then
    sed -i "s/\\\$my_asn = \".*\";/\\\$my_asn = \"${ASSTATS_MY_ASN}\";/" "${PROJECT_DIR}/www/config.inc" || true
  fi

  customize_web_ui

  chown -R www-data:www-data "${PROJECT_DIR}/www/asset"
  chmod 0755 /data "${PROJECT_DIR}" "${PROJECT_DIR}/conf" "${PROJECT_DIR}/asstats" "${PROJECT_DIR}/www" || true
  a2enmod rewrite >/dev/null 2>&1 || true
  systemctl enable --now apache2
}

configure_knownlinks() {
  prompt_exporter_config
  validate_snmp_raw_walk
  discover_and_fill_knownlinks
  [[ -n "${RAW_WALK_FILE}" && -f "${RAW_WALK_FILE}" ]] && rm -f "${RAW_WALK_FILE}"
}

install_systemd_units() {
  info "Criando servico e timer do AS-Stats"

  cat > /etc/default/asstats <<EOF
ASSTATS_PORT_NETFLOW=${ASSTATS_PORT_NETFLOW}
ASSTATS_PORT_SFLOW=${ASSTATS_PORT_SFLOW}
ASSTATS_MY_ASN=${ASSTATS_MY_ASN}
ASSTATS_PROJECT_DIR=${PROJECT_DIR}
ASSTATS_EXPORTER_HOST=${ASSTATS_EXPORTER_HOST}
ASSTATS_SNMP_COMMUNITY=${ASSTATS_SNMP_COMMUNITY}
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

detect_flow_exporter_ip() {
  info "Tentando detectar o IP de origem do flow"

  local detected_flow_ip tcpdump_output
  if ! command -v tcpdump >/dev/null 2>&1; then
    warn "tcpdump nao encontrado; nao vou detectar o IP real de origem do flow"
    return 0
  fi

  tcpdump_output="$(timeout 20 tcpdump -ni any -c 1 "udp port ${ASSTATS_PORT_NETFLOW}" 2>/dev/null || true)"
  if [[ -z "${tcpdump_output}" ]]; then
    warn "Nao capturei flow em ate 20 segundos. Vou manter o IP do exportador SNMP no knownlinks."
    return 0
  fi

  detected_flow_ip="$(printf '%s\n' "${tcpdump_output}" | sed -nE 's/.* IP ([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+)\.[0-9]+ > .*/\1/p' | head -n 1)"
  if [[ -z "${detected_flow_ip}" ]]; then
    warn "Nao consegui extrair o IP de origem do flow a partir do tcpdump"
    return 0
  fi

  info "IP de origem do flow detectado: ${detected_flow_ip}"
  if [[ "${detected_flow_ip}" != "${ASSTATS_EXPORTER_HOST}" ]]; then
    warn "O flow chegou com IP ${detected_flow_ip}, diferente do host SNMP ${ASSTATS_EXPORTER_HOST}. Vou ajustar o knownlinks."
    awk -v new_ip="${detected_flow_ip}" 'BEGIN { OFS="\t" }
      /^[[:space:]]*#/ || /^[[:space:]]*$/ { print; next }
      NF >= 6 { $1 = new_ip; print $1, $2, $3, $4, $5, $6; next }
      { print }' "${PROJECT_DIR}/conf/knownlinks" > "${PROJECT_DIR}/conf/knownlinks.tmp"
    mv "${PROJECT_DIR}/conf/knownlinks.tmp" "${PROJECT_DIR}/conf/knownlinks"
    chmod 0644 "${PROJECT_DIR}/conf/knownlinks" || true
    systemctl restart asstatsd.service
  fi
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

  chmod 0755 /data "${PROJECT_DIR}" "${PROJECT_DIR}/conf" "${PROJECT_DIR}/asstats" "${PROJECT_DIR}/www" || true
  [[ -f "${PROJECT_DIR}/conf/knownlinks" ]] && chmod 0644 "${PROJECT_DIR}/conf/knownlinks" || true

  if [[ -f "${PROJECT_DIR}/conf/knownlinks" ]]; then
    if ! perl "${PROJECT_DIR}/bin/rrd-extractstats.pl" \
      "${PROJECT_DIR}/rrd" \
      "${PROJECT_DIR}/conf/knownlinks" \
      "${PROJECT_DIR}/asstats/asstats_day.txt"; then
      warn "Nao foi possivel gerar o asstats_day.txt automaticamente neste momento"
      log_error_file "Falha ao executar rrd-extractstats.pl durante as corretivas"
    else
      chmod 0644 "${PROJECT_DIR}/asstats/asstats_day.txt" || true
    fi
  elif [[ ! -e "${PROJECT_DIR}/asstats/asstats_day.txt" ]]; then
    touch "${PROJECT_DIR}/asstats/asstats_day.txt"
    chmod 0644 "${PROJECT_DIR}/asstats/asstats_day.txt" || true
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
  [[ -f "${PROJECT_DIR}/www/plugins/mobile-detect/Mobile_Detect.php" ]] || fail "Dependencia da WebUI ausente: ${PROJECT_DIR}/www/plugins/mobile-detect/Mobile_Detect.php"
  [[ -f "${PROJECT_DIR}/www/config.inc" ]] || fail "Arquivo ${PROJECT_DIR}/www/config.inc nao encontrado"
  [[ -f "${PROJECT_DIR}/www/func.inc" ]] || fail "Arquivo ${PROJECT_DIR}/www/func.inc nao encontrado"

  php -m | grep -qi '^sqlite3$' || fail "Modulo PHP sqlite3 nao esta carregado"
  php -m | grep -qi '^gd$' || fail "Modulo PHP gd nao esta carregado"
  php -m | grep -qi '^curl$' || fail "Modulo PHP curl nao esta carregado"
  php -m | grep -qi '^mbstring$' || fail "Modulo PHP mbstring nao esta carregado"
  php -m | grep -qi '^xml$' || fail "Modulo PHP xml nao esta carregado"

  if [[ -f "${PROJECT_DIR}/asstats/asstats_day.txt" ]]; then
    if command -v sqlite3 >/dev/null 2>&1; then
      if ! sqlite3 "${PROJECT_DIR}/asstats/asstats_day.txt" '.tables' | grep -qw 'stats'; then
        warn "A WebUI oficial espera a tabela 'stats' em ${PROJECT_DIR}/asstats/asstats_day.txt"
        log_error_file "Tabela stats nao encontrada em ${PROJECT_DIR}/asstats/asstats_day.txt"
      fi
    fi
  else
    warn "Arquivo ${PROJECT_DIR}/asstats/asstats_day.txt ainda nao existe"
    log_error_file "Arquivo ${PROJECT_DIR}/asstats/asstats_day.txt nao existe"
  fi
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
  tail -n 100 /var/log/apache2/error.log
  sqlite3 ${PROJECT_DIR}/asstats/asstats_day.txt '.tables'
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
  detect_flow_exporter_ip
  configure_firewall
  run_correctives
  verify_installation
  show_summary
}

main "$@"

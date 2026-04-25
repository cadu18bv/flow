#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="${1:-/data/asstats}"
WEB_ALIAS="${2:-flow}"
WWW_DIR="${PROJECT_DIR}/www"
RUNTIME_DIR="${PROJECT_DIR}/runtime"
FLOW_AUTH_DB="${RUNTIME_DIR}/flow_auth.db"
FUNC_FILE="${WWW_DIR}/func.inc"
CSS_FILE="${WWW_DIR}/css/custom.css"
KNOWNLINKS_FILE="${PROJECT_DIR}/conf/knownlinks"
ENV_FILE="${RUNTIME_DIR}/flow-web.env"
TEMPLATE_DIR="${SCRIPT_DIR}/flow_webui"
COLLECTOR_PATCHER="${SCRIPT_DIR}/flow_patch_collector.py"

require_file() {
  local path="$1"
  [[ -e "${path}" ]] || {
    echo "Arquivo ou diretorio nao encontrado: ${path}" >&2
    exit 1
  }
}

apply_css() {
  mkdir -p "${WWW_DIR}/css"

  cat > "${CSS_FILE}" <<'EOF'
:root {
  --flow-bg: #07111f;
  --flow-panel: rgba(10, 21, 38, 0.84);
  --flow-panel-2: rgba(7, 17, 31, 0.90);
  --flow-border: rgba(77, 212, 255, 0.28);
  --flow-text: #eaf7ff;
  --flow-muted: #9ab7ce;
  --flow-accent: #4dd4ff;
  --flow-accent-2: #00ffa6;
  --flow-shadow: 0 22px 50px rgba(0, 0, 0, 0.42);
}

html,
body {
  min-height: 100%;
  background:
    radial-gradient(circle at top left, rgba(0, 255, 166, 0.10), transparent 26%),
    radial-gradient(circle at top right, rgba(77, 212, 255, 0.13), transparent 28%),
    linear-gradient(180deg, #06101c 0%, #081321 48%, #050b14 100%);
  color: var(--flow-text);
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
  padding-bottom: 28px;
}

.content-wrapper::before {
  content: "";
  position: fixed;
  inset: 0;
  background-image:
    linear-gradient(rgba(77, 212, 255, 0.04) 1px, transparent 1px),
    linear-gradient(90deg, rgba(77, 212, 255, 0.04) 1px, transparent 1px);
  background-size: 30px 30px;
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
  border: 1px solid var(--flow-border);
  border-radius: 20px;
  background: rgba(7, 17, 31, 0.80);
  backdrop-filter: blur(14px);
  box-shadow: var(--flow-shadow);
}

.navbar-brand {
  display: flex !important;
  align-items: center;
  gap: 10px;
  height: 58px;
  padding: 0 18px !important;
  color: var(--flow-text) !important;
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
  color: var(--flow-muted);
}

.navbar-nav > li > a,
.navbar-form .btn,
.navbar-toggle {
  color: var(--flow-text) !important;
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
  border: 1px solid var(--flow-border);
  border-radius: 16px;
  background: rgba(8, 20, 37, 0.96);
  box-shadow: var(--flow-shadow);
}

.dropdown-menu > li > a {
  color: var(--flow-text);
}

.dropdown-menu > li > a:hover {
  background: rgba(77, 212, 255, 0.12);
  color: #ffffff;
}

.content-header {
  margin: 26px 18px 20px;
  padding: 24px 28px;
  border: 1px solid var(--flow-border);
  border-radius: 26px;
  background:
    linear-gradient(135deg, rgba(77, 212, 255, 0.12), rgba(0, 255, 166, 0.05)),
    rgba(7, 17, 31, 0.78);
  box-shadow: var(--flow-shadow);
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
  color: var(--flow-accent);
  font-size: 15px;
}

.content-header > .breadcrumb {
  position: static;
  float: none;
  margin-top: 14px;
  padding: 0;
  background: transparent;
  color: var(--flow-muted);
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
  border: 1px solid var(--flow-border);
  border-radius: 24px;
  background: var(--flow-panel);
  box-shadow: var(--flow-shadow);
}

.box::before {
  content: "";
  display: block;
  height: 3px;
  background: linear-gradient(90deg, var(--flow-accent), var(--flow-accent-2));
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
  color: var(--flow-text);
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

.menu-input,
.button-input {
  height: 40px;
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
  color: var(--flow-accent);
  text-shadow: 0 0 20px rgba(77, 212, 255, 0.18);
}

.small,
small {
  color: var(--flow-muted) !important;
}

a {
  color: var(--flow-accent);
}

a:hover,
a:focus {
  color: #8febff;
}

.main-footer {
  margin: 22px 18px 12px;
  padding: 18px 24px;
  border: 1px solid var(--flow-border);
  border-radius: 20px;
  background: rgba(7, 17, 31, 0.78);
  color: var(--flow-muted);
  box-shadow: var(--flow-shadow);
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
  color: var(--flow-text);
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
}

patch_branding() {
  sed -i 's|<a href="index.php" class="navbar-brand"><b>AS-Stats</b></a>|<a href="index.php" class="navbar-brand"><span class="brand-mark">CECTI</span><span class="brand-text">Flow Observatory</span></a>|' "${FUNC_FILE}"
  sed -i 's|<b>GUI Version</b> 0.2|<b>Interface</b> Futuristic Edition|' "${FUNC_FILE}"
  sed -i 's|<strong>AS-Stats v1.6</strong> por Manuel Kasper|<strong>CECTI Flow Observatory</strong>|' "${FUNC_FILE}"
  sed -i 's| - GUI por Nicolas Debrigode||' "${FUNC_FILE}"
  sed -i 's| - Personalizado e traduzido por Rudimar Remontti| - personalizado por CECTI|' "${FUNC_FILE}"
  sed -i 's|<a href="index.php">Top AS</a>|<a href="index.php">Radar AS</a>|g' "${FUNC_FILE}"
  sed -i 's|Top AS <span class="caret"></span>|Radar AS <span class="caret"></span>|g' "${FUNC_FILE}"
  sed -i 's|Top AS - |Radar AS - |g' "${FUNC_FILE}"
  sed -i 's|<a href="history.php">Ver AS</a>|<a href="history.php">Consultar AS</a>|' "${FUNC_FILE}"
  sed -i 's|<a href="asset.php">Ver AS-SET</a>|<a href="asset.php">Consultar AS-SET</a>|' "${FUNC_FILE}"
  sed -i 's|<a href="ix.php">Ver IX Stats</a>|<a href="ix.php">IX Analytics</a>|' "${FUNC_FILE}"
  sed -i 's|Link Usage|Fluxo por Link|g' "${FUNC_FILE}"
}

patch_pages() {
  find "${WWW_DIR}" -maxdepth 1 -type f -name '*.php' -exec sed -i 's|AS-Stats |Flow |g' {} +

  if [[ -f "${WWW_DIR}/index.php" ]]; then
    sed -i 's|content_header('"'"'Top '"'"' \. $ntop \. '"'"' AS'"'"', '"'"'('"'"'.$label.'"'"')'"'"')|content_header('"'"'Radar de '"'"' . $ntop . '"'"' AS'"'"', '"'"'('"'"' . $label . '"'"')'"'"')|' "${WWW_DIR}/index.php"
  fi

  if [[ -f "${WWW_DIR}/linkusage.php" ]]; then
    sed -i 's|content_header('"'"'Top 10 AS - por uso do link'"'"', '"'"'('"'"' . $label . '"'"')'"'"')|content_header('"'"'Fluxo por link'"'"', '"'"'('"'"' . $label . '"'"')'"'"')|' "${WWW_DIR}/linkusage.php"
    sed -i 's|Uso do link|Fluxo do link|g' "${WWW_DIR}/linkusage.php"
  fi

  if [[ -f "${WWW_DIR}/history.php" ]]; then
    sed -i 's|Pesquisar AS|Consultar ASN|g' "${WWW_DIR}/history.php"
    sed -i 's|Custom Links|Atalhos operacionais|g' "${WWW_DIR}/history.php"
  fi

  if [[ -f "${WWW_DIR}/asset.php" ]]; then
    sed -i 's|HistÃ³rico para AS-SET|Painel do AS-SET|g' "${WWW_DIR}/asset.php"
    sed -i 's|View AS-SET|Painel AS-SET|g' "${WWW_DIR}/asset.php"
  fi

  if [[ -f "${WWW_DIR}/ix.php" ]]; then
    sed -i 's|Top IX|IX Analytics|g' "${WWW_DIR}/ix.php"
  fi
}

patch_graphs() {
  local replacement
  replacement='--color BACK#05101a00 --color CANVAS#08131ecc --color SHADEA#05101a00 --color SHADEB#05101a00 --color FONT#ffffff --color AXIS#e2f3ff --color ARROW#d7f6ff --color FRAME#2c5375 --color GRID#456a8558 --color MGRID#6ae3ff8e '

  if [[ -f "${WWW_DIR}/gengraph.php" ]]; then
    perl -0pi -e "s/--color BACK#ffffff00 --color SHADEA#ffffff00 --color SHADEB#ffffff00 /${replacement}/g; s/HRULE:0#00000080/HRULE:0#c9f5ff88/g" "${WWW_DIR}/gengraph.php"
  fi

  if [[ -f "${WWW_DIR}/linkgraph.php" ]]; then
    perl -0pi -e "s/--color BACK#ffffff00 --color SHADEA#ffffff00 --color SHADEB#ffffff00 /${replacement}/g; s/HRULE:0#00000080/HRULE:0#c9f5ff88/g" "${WWW_DIR}/linkgraph.php"
  fi
}

apply_flow_templates() {
  require_file "${TEMPLATE_DIR}"
  require_file "${TEMPLATE_DIR}/custom.css"
  require_file "${TEMPLATE_DIR}/flow_ui.php"

  install -m 0644 "${TEMPLATE_DIR}/custom.css" "${WWW_DIR}/css/custom.css"
  install -m 0644 "${TEMPLATE_DIR}/auth.php" "${WWW_DIR}/auth.php"
  install -m 0644 "${TEMPLATE_DIR}/flow_ui.php" "${WWW_DIR}/flow_ui.php"
  install -m 0644 "${TEMPLATE_DIR}/dashboard.php" "${WWW_DIR}/dashboard.php"
  install -m 0644 "${TEMPLATE_DIR}/ddos.php" "${WWW_DIR}/ddos.php"
  install -m 0644 "${TEMPLATE_DIR}/noc.php" "${WWW_DIR}/noc.php"
  install -m 0644 "${TEMPLATE_DIR}/asdrill.php" "${WWW_DIR}/asdrill.php"
  install -m 0644 "${TEMPLATE_DIR}/index.php" "${WWW_DIR}/index.php"
  install -m 0644 "${TEMPLATE_DIR}/linkusage.php" "${WWW_DIR}/linkusage.php"
  install -m 0644 "${TEMPLATE_DIR}/history.php" "${WWW_DIR}/history.php"
  install -m 0644 "${TEMPLATE_DIR}/ipsearch.php" "${WWW_DIR}/ipsearch.php"
  install -m 0644 "${TEMPLATE_DIR}/asset.php" "${WWW_DIR}/asset.php"
  install -m 0644 "${TEMPLATE_DIR}/ix.php" "${WWW_DIR}/ix.php"
  install -m 0644 "${TEMPLATE_DIR}/config.php" "${WWW_DIR}/config.php"
  install -m 0644 "${TEMPLATE_DIR}/login.php" "${WWW_DIR}/login.php"
  install -m 0644 "${TEMPLATE_DIR}/logout.php" "${WWW_DIR}/logout.php"
  install -m 0644 "${TEMPLATE_DIR}/favicon.svg" "${WWW_DIR}/favicon.svg"
  install -m 0644 "${TEMPLATE_DIR}/site.webmanifest" "${WWW_DIR}/site.webmanifest"
}

apply_flow_collector_patch() {
  [[ -f "${PROJECT_DIR}/bin/asstatd.pl" ]] || return 0
  require_file "${COLLECTOR_PATCHER}"
  if grep -q "flow_events" "${PROJECT_DIR}/bin/asstatd.pl" && grep -q "getopts('r:p:P:k:a:nm:q:R:'" "${PROJECT_DIR}/bin/asstatd.pl"; then
    return 0
  fi
  python3 "${COLLECTOR_PATCHER}" "${PROJECT_DIR}/bin/asstatd.pl"
}

configure_runtime_support() {
  local flow_db flow_retention
  flow_db="${PROJECT_DIR}/asstats/flow_events.db"
  flow_retention="${ASSTATS_FLOW_RETENTION_DAYS:-14}"

  mkdir -p "${RUNTIME_DIR}"
  chown root:www-data "${RUNTIME_DIR}" || true
  chmod 0770 "${RUNTIME_DIR}" || true

  if [[ -f "${PROJECT_DIR}/asstats/flow_auth.db" && ! -f "${FLOW_AUTH_DB}" ]]; then
    mv "${PROJECT_DIR}/asstats/flow_auth.db" "${FLOW_AUTH_DB}"
  fi

  chown root:www-data "${FLOW_AUTH_DB}" 2>/dev/null || true
  chmod 0660 "${FLOW_AUTH_DB}" 2>/dev/null || true

  if [[ -f /etc/default/asstats ]]; then
    grep -q '^ASSTATS_FLOW_DB=' /etc/default/asstats && \
      sed -i "s|^ASSTATS_FLOW_DB=.*|ASSTATS_FLOW_DB=${flow_db}|" /etc/default/asstats || \
      printf 'ASSTATS_FLOW_DB=%s\n' "${flow_db}" >> /etc/default/asstats

    grep -q '^ASSTATS_FLOW_RETENTION_DAYS=' /etc/default/asstats && \
      sed -i "s|^ASSTATS_FLOW_RETENTION_DAYS=.*|ASSTATS_FLOW_RETENTION_DAYS=${flow_retention}|" /etc/default/asstats || \
      printf 'ASSTATS_FLOW_RETENTION_DAYS=%s\n' "${flow_retention}" >> /etc/default/asstats
  fi

  if [[ -f /usr/local/bin/asstatsd-wrapper.sh ]]; then
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
  -a "${ASSTATS_MY_ASN}" \
  -q "${ASSTATS_FLOW_DB}" \
  -R "${ASSTATS_FLOW_RETENTION_DAYS}"
EOF
    chmod +x /usr/local/bin/asstatsd-wrapper.sh
  fi
}

configure_apache_hardening() {
  local apache_conf
  apache_conf="/etc/apache2/conf-available/flow-observatory.conf"

  cat > "${apache_conf}" <<EOF
# Managed by Flow Observatory customization
ServerTokens Prod
ServerSignature Off
TraceEnable Off
FileETag None

Alias /${WEB_ALIAS} ${WWW_DIR}
Alias /${WEB_ALIAS}/ ${WWW_DIR}/

<Directory "${WWW_DIR}">
    Options -Indexes +FollowSymLinks
    AllowOverride None
    Require all granted
    DirectoryIndex login.php index.php
</Directory>

<Directory "${RUNTIME_DIR}">
    Require all denied
</Directory>

<Directory "${PROJECT_DIR}/conf">
    Require all denied
</Directory>

<Directory "${PROJECT_DIR}/asstats">
    Require all denied
</Directory>

<Directory "${PROJECT_DIR}/bin">
    Require all denied
</Directory>

<FilesMatch "^(config\\.inc|func\\.inc|auth\\.php|flow_ui\\.php)$">
    Require all denied
</FilesMatch>

<FilesMatch "\\.(db|sqlite|sqlite3|bak|orig|dist|sh|py|pl|log|env|md|ini)$">
    Require all denied
</FilesMatch>

<IfModule mod_headers.c>
    Header always unset X-Powered-By
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "strict-origin-when-cross-origin"
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"
</IfModule>
EOF

  mkdir -p /var/www/html
  rm -f "/var/www/html/${WEB_ALIAS}" || true
  rm -f /var/www/html/as-stats || true
  a2enmod headers rewrite >/dev/null 2>&1 || true
  a2enconf flow-observatory >/dev/null 2>&1 || true
}

install_router_management_tool() {
  cat > /usr/local/bin/asstats-add-router.sh <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="${1:-/data/asstats}"
KNOWNLINKS_FILE="${PROJECT_DIR}/conf/knownlinks"
DEFAULTS_FILE="/etc/default/asstats"

[[ -f "${KNOWNLINKS_FILE}" ]] || { echo "Arquivo knownlinks nao encontrado: ${KNOWNLINKS_FILE}" >&2; exit 1; }
[[ -f "${DEFAULTS_FILE}" ]] && source "${DEFAULTS_FILE}"

sampling_default="${ASSTATS_SAMPLING_RATE:-128}"

read -r -p "IP ou hostname do novo exportador: " exporter_host
[[ -n "${exporter_host}" ]] || { echo "Exportador nao informado" >&2; exit 1; }

read -r -p "Comunidade SNMP [${ASSTATS_SNMP_COMMUNITY:-public}]: " snmp_community
snmp_community="${snmp_community:-${ASSTATS_SNMP_COMMUNITY:-public}}"

read -r -p "Sampling padrao [${sampling_default}]: " sampling_rate
sampling_rate="${sampling_rate:-${sampling_default}}"

tmp_walk="$(mktemp)"
tmp_preview="$(mktemp)"
trap 'rm -f "${tmp_walk}" "${tmp_preview}"' EXIT

echo "[INFO] Executando descoberta SNMP em ${exporter_host}"
snmpwalk -v2c -c "${snmp_community}" "${exporter_host}" > "${tmp_walk}"

declare -A IF_INDEXES=()
declare -A IF_DESCRS=()
declare -A IF_ALIASES=()
declare -A USED_TAGS=()

while IFS=$'\t' read -r _ _ tag _; do
  [[ -n "${tag:-}" ]] && USED_TAGS["${tag}"]=1
done < <(awk -F '\t' '!/^[[:space:]]*#/ && NF >= 4 { print $1 "\t" $2 "\t" $3 "\t" $4 }' "${KNOWNLINKS_FILE}")

while IFS= read -r line; do
  [[ "${line}" =~ (IF-MIB::ifIndex|\.1\.3\.6\.1\.2\.1\.2\.2\.1\.1)\.([0-9]+)[[:space:]]*=[[:space:]]*(.*)$ ]] || continue
  index="${BASH_REMATCH[2]}"
  IF_INDEXES["${index}"]="${index}"
done < "${tmp_walk}"

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
done < "${tmp_walk}"

while IFS= read -r line; do
  [[ "${line}" =~ (IF-MIB::ifAlias|IF-MIB::ifName|\.1\.3\.6\.1\.2\.1\.31\.1\.1\.1\.18|\.1\.3\.6\.1\.2\.1\.31\.1\.1\.1\.1)\.([0-9]+)[[:space:]]*=[[:space:]]*(.*)$ ]] || continue
  index="${BASH_REMATCH[2]}"
  value="${BASH_REMATCH[3]}"
  value="${value#STRING: }"
  value="${value#\"}"
  value="${value%\"}"
  IF_ALIASES["${index}"]="${value}"
done < "${tmp_walk}"

generate_tag() {
  local ifindex="$1"
  local candidate suffix
  candidate="if${ifindex}"
  if [[ -z "${USED_TAGS[${candidate}]:-}" ]]; then
    USED_TAGS["${candidate}"]=1
    printf '%s' "${candidate}"
    return
  fi

  suffix=1
  while :; do
    candidate="if${ifindex}_${suffix}"
    if [[ -z "${USED_TAGS[${candidate}]:-}" ]]; then
      USED_TAGS["${candidate}"]=1
      printf '%s' "${candidate}"
      return
    fi
    suffix=$((suffix + 1))
  done
}

cat > "${tmp_preview}" <<'PREVIEW'
# novas interfaces para anexar
PREVIEW

color_palette=(1F78B4 33A02C E31A1C FF7F00 6A3D9A A6CEE3 B2DF8A FB9A99 CAB2D6 FDBF6F)
color_index=0

while IFS= read -r index; do
  [[ -n "${IF_DESCRS[${index}]:-}" ]] || continue
  description="${IF_ALIASES[${index}]:-${IF_DESCRS[${index}]}}"
  tag="$(generate_tag "${index}")"
  color="${color_palette[$((color_index % ${#color_palette[@]}))]}"
  printf '%s\t%s\t%s\t%s\t%s\t%s\n' \
    "${exporter_host}" \
    "${index}" \
    "${tag}" \
    "${description}" \
    "${color}" \
    "${sampling_rate}" >> "${tmp_preview}"
  color_index=$((color_index + 1))
done < <(printf '%s\n' "${!IF_INDEXES[@]}" | sort -n)

if ! grep -qvE '^\s*#|^\s*$' "${tmp_preview}"; then
  echo "Nenhuma interface elegivel encontrada via SNMP para ${exporter_host}" >&2
  exit 1
fi

echo
echo "[INFO] Interfaces encontradas:"
awk -F '\t' 'BEGIN { printf "%-16s %-8s %-14s %-42s %-8s %-8s\n", "EXPORTADOR", "IFINDEX", "TAG", "DESCRICAO", "COR", "SAMPLE" }
  /^[[:space:]]*#/ { next }
  NF >= 6 { printf "%-16s %-8s %-14s %-42s %-8s %-8s\n", $1, $2, $3, $4, $5, $6 }' "${tmp_preview}"
echo

read -r -p "Adicionar essas interfaces ao knownlinks? [Y/n]: " confirm
confirm="${confirm:-Y}"
case "${confirm}" in
  Y|y|yes|YES) ;;
  *)
    echo "Operacao cancelada."
    exit 0
    ;;
esac

awk '!/^[[:space:]]*#/ && NF >= 6 { print }' "${tmp_preview}" >> "${KNOWNLINKS_FILE}"
chmod 0644 "${KNOWNLINKS_FILE}"

echo "[INFO] Reiniciando coletor e atualizando base web"
systemctl restart asstatsd.service
systemctl start asstats-extract.service || true

echo
echo "Roteador anexado com sucesso."
echo "Arquivo atualizado: ${KNOWNLINKS_FILE}"
echo "Valide com:"
echo "  grep -n '${exporter_host}' ${KNOWNLINKS_FILE}"
echo "  systemctl status asstatsd.service"
EOF

  chmod +x /usr/local/bin/asstats-add-router.sh
}

install_maintenance_helper() {
  cat > /usr/local/bin/flow-maintenance-helper.sh <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

source /etc/default/asstats 2>/dev/null || true

PROJECT_DIR="${ASSTATS_PROJECT_DIR:-/data/asstats}"
KNOWNLINKS_FILE="${PROJECT_DIR}/conf/knownlinks"
RRD_DIR="${PROJECT_DIR}/rrd"
ASSTATS_DB="${PROJECT_DIR}/asstats/asstats_day.txt"
FLOW_DB="${PROJECT_DIR}/asstats/flow_events.db"

action="${1:-}"

case "${action}" in
  refresh-collection)
    systemctl restart asstatsd.service
    systemctl start asstats-extract.service || true
    echo "Coleta reiniciada e extrator acionado."
    ;;
  reset-collection)
    systemctl stop asstatsd.service
    systemctl stop asstats-extract.timer || true
    systemctl stop asstats-extract.service || true
    rm -rf "${RRD_DIR}"
    rm -f "${ASSTATS_DB}" "${FLOW_DB}"
    mkdir -p "${RRD_DIR}" "${PROJECT_DIR}/asstats"
    touch "${ASSTATS_DB}"
    chown -R root:root "${RRD_DIR}" "${PROJECT_DIR}/asstats" || true
    chown root:www-data "${KNOWNLINKS_FILE}" "${ASSTATS_DB}" || true
    chmod 0755 "${RRD_DIR}" "${PROJECT_DIR}/asstats" || true
    chmod 0660 "${KNOWNLINKS_FILE}" || true
    chmod 0664 "${ASSTATS_DB}" || true
    systemctl start asstatsd.service
    systemctl start asstats-extract.timer || true
    systemctl start asstats-extract.service || true
    echo "Coleta zerada e ambiente reiniciado."
    ;;
  tail-collector-log)
    journalctl -u asstatsd.service -n 80 --no-pager
    ;;
  tail-extractor-log)
    journalctl -u asstats-extract.service -n 80 --no-pager
    ;;
  tail-apache-log)
    tail -n 80 /var/log/apache2/error.log
    ;;
  validate-flow)
    netflow_port="${ASSTATS_PORT_NETFLOW:-9000}"
    sflow_port="${ASSTATS_PORT_SFLOW:-6343}"
    echo "== Diagnostico de chegada de flow =="
    echo "Projeto: ${PROJECT_DIR}"
    echo "Porta NetFlow/IPFIX: ${netflow_port}/udp"
    echo "Porta sFlow: ${sflow_port}/udp"
    echo
    echo "== Servico do coletor =="
    systemctl status asstatsd.service --no-pager || true
    echo
    echo "== Portas UDP em escuta =="
    if command -v ss >/dev/null 2>&1; then
      ss -lunp | grep -E "(:${netflow_port}\b|:${sflow_port}\b)" || echo "Nenhuma porta UDP encontrada em escuta para flow."
    else
      echo "Comando ss nao encontrado."
    fi
    echo
    echo "== Ultimos logs do coletor =="
    journalctl -u asstatsd.service -n 30 --no-pager || true
    echo
    echo "== Captura rapida de pacotes UDP =="
    if command -v tcpdump >/dev/null 2>&1; then
      timeout 12 tcpdump -ni any -c 8 "udp port ${netflow_port} or udp port ${sflow_port}" 2>/dev/null || echo "Nenhum pacote de flow capturado na janela de teste."
    else
      echo "tcpdump nao encontrado."
    fi
    ;;
  *)
    echo "Uso: $0 {refresh-collection|reset-collection|tail-collector-log|tail-extractor-log|tail-apache-log|validate-flow}" >&2
    exit 1
    ;;
esac
EOF

  chmod 0750 /usr/local/bin/flow-maintenance-helper.sh
  chown root:root /usr/local/bin/flow-maintenance-helper.sh

  cat > /etc/sudoers.d/flow-maintenance-www-data <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/local/bin/flow-maintenance-helper.sh
EOF
  chmod 0440 /etc/sudoers.d/flow-maintenance-www-data
}

configure_admin_permissions() {
  [[ -f "${WWW_DIR}/config.inc" ]] && chown root:www-data "${WWW_DIR}/config.inc" && chmod 0660 "${WWW_DIR}/config.inc" || true
  [[ -f "${KNOWNLINKS_FILE}" ]] && chown root:www-data "${KNOWNLINKS_FILE}" && chmod 0660 "${KNOWNLINKS_FILE}" || true
  [[ -f "${FLOW_AUTH_DB}" ]] && chown root:www-data "${FLOW_AUTH_DB}" && chmod 0660 "${FLOW_AUTH_DB}" || true
  [[ -d "${RUNTIME_DIR}" ]] && chown root:www-data "${RUNTIME_DIR}" && chmod 0770 "${RUNTIME_DIR}" || true
}

check_cdn_link() {
  [[ -f "${KNOWNLINKS_FILE}" ]] || return 0

  local cdn_lines
  cdn_lines="$(grep -in 'cdn' "${KNOWNLINKS_FILE}" || true)"
  if [[ -z "${cdn_lines}" ]]; then
    echo "Aviso: nao encontrei nenhuma linha com 'cdn' em ${KNOWNLINKS_FILE}"
    return 0
  fi

  echo "Linha(s) encontradas para CDN:"
  echo "${cdn_lines}"
  echo "Observacao: se o grafico IPv6 do CDN continuar vazio, o problema tende a ser de exportacao/coleta do link e nao do tema."
}

reload_services() {
  systemctl reload apache2 2>/dev/null || true
  systemctl restart asstatsd.service 2>/dev/null || true
}

export_web_url() {
  local server_ip flow_web_url

  server_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  if [[ -z "${server_ip}" ]]; then
    server_ip="$(hostname -f 2>/dev/null || hostname 2>/dev/null || true)"
  fi
  if [[ -z "${server_ip}" ]]; then
    server_ip="IP_DO_SERVIDOR"
  fi

  flow_web_url="http://${server_ip}/${WEB_ALIAS}/"
  export FLOW_WEB_URL="${flow_web_url}"

  cat > "${ENV_FILE}" <<EOF
export FLOW_WEB_URL="${FLOW_WEB_URL}"
export FLOW_WEB_ALIAS="${WEB_ALIAS}"
EOF
}

main() {
  require_file "${WWW_DIR}"
  require_file "${FUNC_FILE}"

  apply_flow_templates
  apply_flow_collector_patch
  configure_runtime_support
  install_router_management_tool
  install_maintenance_helper
  patch_graphs
  configure_apache_hardening
  configure_admin_permissions
  reload_services
  export_web_url
  check_cdn_link

  echo
  echo "Customizacao completa aplicada em ${WWW_DIR}"
  echo "URL: ${FLOW_WEB_URL}"
  echo "Variavel exportada nesta execucao: FLOW_WEB_URL=${FLOW_WEB_URL}"
  echo "Arquivo gerado: ${ENV_FILE}"
}

main "$@"

#!/usr/bin/env bash

set -euo pipefail

PROJECT_DIR="${1:-/data/asstats}"
WEB_ALIAS="${2:-flow}"
WWW_DIR="${PROJECT_DIR}/www"
FUNC_FILE="${WWW_DIR}/func.inc"
CSS_FILE="${WWW_DIR}/css/custom.css"
KNOWNLINKS_FILE="${PROJECT_DIR}/conf/knownlinks"

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
    sed -i "s|content_header('Top ' \\. \$ntop \\. ' AS', '(\\.\\$label\\.)')|content_header('Radar de ' . \$ntop . ' AS', '(' . \$label . ')')|" "${WWW_DIR}/index.php"
  fi

  if [[ -f "${WWW_DIR}/linkusage.php" ]]; then
    sed -i "s|content_header('Top 10 AS - por uso do link', '(' \\. \\$label \\. ')')|content_header('Fluxo por link', '(' . \$label . ')')|" "${WWW_DIR}/linkusage.php"
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
  replacement='--color BACK#07111f00 --color CANVAS#0b1623ee --color SHADEA#07111f00 --color SHADEB#07111f00 --color FONT#d8f7ff --color AXIS#8ecfff --color ARROW#8ecfff --color FRAME#244d73 --color GRID#36536d55 --color MGRID#4dd4ff77 '

  if [[ -f "${WWW_DIR}/gengraph.php" ]]; then
    perl -0pi -e "s/--color BACK#ffffff00 --color SHADEA#ffffff00 --color SHADEB#ffffff00 /${replacement}/g; s/HRULE:0#00000080/HRULE:0#8ecfff88/g" "${WWW_DIR}/gengraph.php"
  fi

  if [[ -f "${WWW_DIR}/linkgraph.php" ]]; then
    perl -0pi -e "s/--color BACK#ffffff00 --color SHADEA#ffffff00 --color SHADEB#ffffff00 /${replacement}/g; s/HRULE:0#00000080/HRULE:0#8ecfff88/g" "${WWW_DIR}/linkgraph.php"
  fi
}

configure_alias() {
  mkdir -p /var/www/html
  ln -sfn "${WWW_DIR}" "/var/www/html/${WEB_ALIAS}"
  if [[ "${WEB_ALIAS}" != "as-stats" && -L /var/www/html/as-stats ]]; then
    rm -f /var/www/html/as-stats
  fi
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
}

main() {
  require_file "${WWW_DIR}"
  require_file "${FUNC_FILE}"

  apply_css
  patch_branding
  patch_pages
  patch_graphs
  configure_alias
  reload_services
  check_cdn_link

  echo
  echo "Customizacao completa aplicada em ${WWW_DIR}"
  echo "URL: /${WEB_ALIAS}/"
}

main "$@"

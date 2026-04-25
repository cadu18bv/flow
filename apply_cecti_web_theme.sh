#!/usr/bin/env bash

set -euo pipefail

PROJECT_DIR="${1:-/data/asstats}"
WWW_DIR="${PROJECT_DIR}/www"
FUNC_FILE="${WWW_DIR}/func.inc"
CSS_FILE="${WWW_DIR}/css/custom.css"

[[ -d "${WWW_DIR}" ]] || {
  echo "Diretorio WebUI nao encontrado: ${WWW_DIR}" >&2
  exit 1
}

[[ -f "${FUNC_FILE}" ]] || {
  echo "Arquivo nao encontrado: ${FUNC_FILE}" >&2
  exit 1
}

mkdir -p "${WWW_DIR}/css"

cat > "${CSS_FILE}" <<'EOF'
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

echo "Tema futurista CECTI aplicado em ${WWW_DIR}"

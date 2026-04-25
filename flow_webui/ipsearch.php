<?php
require_once("func.inc");
require_once("flow_ui.php");

function flow_query_db_path() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'asstats' . DIRECTORY_SEPARATOR . 'flow_events.db';
}

function flow_query_hours_options() {
    return array(
        1 => '1 hora',
        6 => '6 horas',
        24 => '24 horas',
        72 => '72 horas',
        168 => '7 dias',
    );
}

function flow_query_timezone_name() {
    static $timezoneName = null;

    if ($timezoneName !== null) {
        return $timezoneName;
    }

    $candidates = array();

    $envTimezone = getenv('TZ');
    if ($envTimezone) {
        $candidates[] = trim($envTimezone);
    }

    $iniTimezone = ini_get('date.timezone');
    if ($iniTimezone) {
        $candidates[] = trim($iniTimezone);
    }

    if (DIRECTORY_SEPARATOR === '/' && is_readable('/etc/timezone')) {
        $fileTimezone = trim((string)@file_get_contents('/etc/timezone'));
        if ($fileTimezone !== '') {
            $candidates[] = $fileTimezone;
        }
    }

    $candidates[] = date_default_timezone_get();
    $candidates[] = 'America/Fortaleza';
    $candidates[] = 'America/Sao_Paulo';

    foreach ($candidates as $candidate) {
        if (!$candidate) {
            continue;
        }

        try {
            $timezone = new DateTimeZone($candidate);
            $timezoneName = $timezone->getName();
            return $timezoneName;
        } catch (Exception $exception) {
        }
    }

    $timezoneName = 'UTC';
    return $timezoneName;
}

function flow_format_timestamp_local($timestamp, $format) {
    $date = new DateTimeImmutable('@' . (int)$timestamp);
    $date = $date->setTimezone(new DateTimeZone(flow_query_timezone_name()));
    return $date->format($format);
}

function flow_format_query_time($timestamp) {
    return flow_format_timestamp_local($timestamp, 'd/m H:i');
}

function flow_render_select($name, $value, $options) {
    $html = '<select class="flow-input" name="' . htmlspecialchars($name) . '">';
    foreach ($options as $optionValue => $label) {
        $selected = ((string)$optionValue === (string)$value) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($optionValue) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }
    $html .= '</select>';
    return $html;
}

function flow_validate_asn_filter($asn) {
    $asn = trim((string)$asn);
    return $asn === '' || ctype_digit($asn);
}

function flow_render_mode_switch($current) {
    $options = array(
        'any' => 'Qualquer lado',
        'src' => 'Origem',
        'dst' => 'Destino',
    );

    $html = '<div class="flow-mode-switch" role="radiogroup" aria-label="Modo da consulta">';
    foreach ($options as $value => $label) {
        $checked = ($current === $value) ? ' checked' : '';
        $active = ($current === $value) ? ' is-active' : '';
        $html .= '<label class="flow-mode-chip' . $active . '">';
        $html .= '<input type="radio" name="mode" value="' . htmlspecialchars($value) . '"' . $checked . '>';
        $html .= '<span>' . htmlspecialchars($label) . '</span>';
        $html .= '</label>';
    }
    $html .= '</div>';
    return $html;
}

function flow_query_link_labels() {
    static $labels = null;

    if ($labels !== null) {
        return $labels;
    }

    $labels = array();
    if (!function_exists('getknownlinks')) {
        return $labels;
    }

    $knownlinks = getknownlinks();
    if (!is_array($knownlinks)) {
        return $labels;
    }

    foreach ($knownlinks as $link) {
        if (!isset($link['tag'])) {
            continue;
        }
        $tag = trim((string)$link['tag']);
        if ($tag === '') {
            continue;
        }
        $labels[$tag] = isset($link['descr']) ? trim((string)$link['descr']) : '';
    }

    return $labels;
}

function flow_query_link_options() {
    $options = array('' => 'Todas as interfaces');
    foreach (flow_query_link_labels() as $tag => $description) {
        $options[$tag] = ($description !== '' ? $description . ' (' . $tag . ')' : $tag);
    }
    return $options;
}

function flow_validate_ip_filter($filter) {
    $filter = trim((string)$filter);
    if ($filter === '') {
        return false;
    }

    if (strpos($filter, '/') === false) {
        return filter_var($filter, FILTER_VALIDATE_IP) !== false;
    }

    list($network, $prefix) = array_pad(explode('/', $filter, 2), 2, null);
    if (!filter_var($network, FILTER_VALIDATE_IP)) {
        return false;
    }
    if ($prefix === null || $prefix === '' || !ctype_digit((string)$prefix)) {
        return false;
    }

    $maxPrefix = (strpos($network, ':') !== false) ? 128 : 32;
    $prefix = (int)$prefix;
    return $prefix >= 0 && $prefix <= $maxPrefix;
}

function flow_ip_matches_filter($candidate, $filter) {
    $candidate = trim((string)$candidate);
    $filter = trim((string)$filter);

    if ($candidate === '' || $filter === '') {
        return false;
    }

    if (strpos($filter, '/') === false) {
        return $candidate === $filter;
    }

    list($network, $prefixLength) = explode('/', $filter, 2);
    $candidatePacked = @inet_pton($candidate);
    $networkPacked = @inet_pton($network);
    if ($candidatePacked === false || $networkPacked === false || strlen($candidatePacked) !== strlen($networkPacked)) {
        return false;
    }

    $prefixLength = (int)$prefixLength;
    $fullBytes = intdiv($prefixLength, 8);
    $remainingBits = $prefixLength % 8;

    if ($fullBytes > 0 && substr($candidatePacked, 0, $fullBytes) !== substr($networkPacked, 0, $fullBytes)) {
        return false;
    }

    if ($remainingBits === 0) {
        return true;
    }

    $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
    return (ord($candidatePacked[$fullBytes]) & $mask) === (ord($networkPacked[$fullBytes]) & $mask);
}

function flow_bgp_he_url($asn) {
    $asn = (int)$asn;
    if ($asn <= 0) {
        return '';
    }
    return 'https://bgp.he.net/AS' . $asn;
}

function flow_render_asn_link($asn) {
    $asn = (int)$asn;
    if ($asn <= 0) {
        return '<small>AS0</small>';
    }

    $url = htmlspecialchars(flow_bgp_he_url($asn));
    return '<small>AS' . htmlspecialchars((string)$asn) . ' '
        . '<a class="flow-asn-link" href="' . $url . '" target="_blank" rel="noopener noreferrer" title="Consultar AS' . htmlspecialchars((string)$asn) . ' no bgp.he">'
        . '<i class="fa fa-external-link"></i></a></small>';
}

function flow_render_endpoint_cell($ip, $asn) {
    return htmlspecialchars((string)$ip) . ' ' . flow_render_asn_link($asn);
}

function flow_render_link_badge($tag) {
    $labels = flow_query_link_labels();
    $tagText = htmlspecialchars((string)$tag);
    $description = isset($labels[$tag]) ? trim((string)$labels[$tag]) : '';

    if ($description !== '' && strcasecmp($description, (string)$tag) !== 0) {
        return '<div class="flow-link-cell">'
            . '<span class="flow-pill">' . $tagText . '</span>'
            . '<small>' . htmlspecialchars($description) . '</small>'
            . '</div>';
    }

    return '<span class="flow-pill">' . $tagText . '</span>';
}

function flow_build_export_url($ip, $mode, $hours, $link, $asn) {
    $url = 'ipsearch.php?ip=' . rawurlencode($ip) . '&mode=' . rawurlencode($mode) . '&hours=' . rawurlencode($hours);
    if ($link !== '') {
        $url .= '&link=' . rawurlencode($link);
    }
    if ($asn !== '') {
        $url .= '&asn=' . rawurlencode($asn);
    }
    $url .= '&export=pdf';
    return $url;
}

function flow_query_open_db($dbPath, &$error = null) {
    $error = null;

    if (!is_file($dbPath)) {
        $error = 'A base flow_events.db ainda nao existe neste ambiente.';
        return null;
    }

    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(2000);
        @$db->exec('PRAGMA busy_timeout = 2000');
        return $db;
    } catch (Exception $exception) {
        $error = 'Nao foi possivel abrir a base de eventos por IP.';
        return null;
    }
}

function flow_query_has_events_table($db) {
    if (!$db instanceof SQLite3) {
        return false;
    }
    $exists = @$db->querySingle("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'flow_events' LIMIT 1");
    return $exists === 'flow_events';
}

function flow_query_register_ip_matcher($db) {
    if (!$db instanceof SQLite3) {
        return false;
    }
    return @$db->createFunction('flow_ip_filter_match', function ($candidate, $filter) {
        return flow_ip_matches_filter($candidate, $filter) ? 1 : 0;
    }, 2);
}

function flow_query_bind_filters($stmt, $windowStart, $queryIp, $queryLink, $queryAsn) {
    if (!$stmt) {
        return false;
    }

    $stmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
    $stmt->bindValue(':ip_filter', $queryIp, SQLITE3_TEXT);
    if ($queryLink !== '') {
        $stmt->bindValue(':link_tag', $queryLink, SQLITE3_TEXT);
    }
    if ($queryAsn !== '') {
        $stmt->bindValue(':asn_filter', (int)$queryAsn, SQLITE3_INTEGER);
    }

    return true;
}

function flow_query_execute_assoc($stmt, &$error, $message) {
    if (!$stmt) {
        $error = $message;
        return false;
    }

    $result = @$stmt->execute();
    if ($result === false) {
        $error = $message;
        return false;
    }

    return $result;
}

function flow_render_pdf_document($queryIp, $queryMode, $queryHours, $queryLink, $queryAsn, $summaryCards, $chartHtml, $topCounterpartsHtml, $recentEventsHtml) {
    $title = 'Flow Observatory | IP Lens Report';
    $modeLabel = strtoupper($queryMode);
    $windowLabel = htmlspecialchars(flow_query_hours_options()[$queryHours]);
    $generatedAt = htmlspecialchars(flow_format_timestamp_local(time(), 'd/m/Y H:i:s'));
    $timezoneName = htmlspecialchars(flow_query_timezone_name());
    $linkLabel = $queryLink !== '' ? htmlspecialchars((string)$queryLink) : 'Todas as interfaces';
    $asnLabel = $queryAsn !== '' ? 'AS' . htmlspecialchars((string)$queryAsn) : 'Todos os ASN';
    $summaryHtml = '';
    foreach ($summaryCards as $card) {
        $summaryHtml .= '<div class="report-stat"><span>' . htmlspecialchars($card['label']) . '</span><strong>' . htmlspecialchars($card['value']) . '</strong></div>';
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title>{$title}</title>
  <style>
    body { font-family: "Segoe UI", Arial, sans-serif; margin: 24px; color: #0f1720; }
    .report-header { margin-bottom: 24px; }
    .report-header h1 { margin: 0 0 8px; font-size: 28px; }
    .report-header p { margin: 0; color: #4c6073; font-size: 14px; }
    .report-meta { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 14px; }
    .report-chip { padding: 8px 12px; border: 1px solid #bfd4e5; border-radius: 999px; font-size: 12px; color: #20435f; background: #f5fbff; }
    .report-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin: 20px 0 24px; }
    .report-stat { padding: 14px; border: 1px solid #d7e6f2; border-radius: 14px; background: #fbfdff; }
    .report-stat span { display: block; margin-bottom: 6px; color: #60788f; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; }
    .report-stat strong { font-size: 20px; }
    .report-section { margin-bottom: 28px; }
    .report-section h2 { margin: 0 0 12px; font-size: 18px; }
    .flow-svg-chart { border: 1px solid #d7e6f2; border-radius: 16px; overflow: hidden; }
    .flow-table-wrap { overflow: visible; border: 1px solid #d7e6f2; border-radius: 16px; }
    .flow-table { width: 100%; border-collapse: collapse; font-size: 12px; }
    .flow-table th, .flow-table td { padding: 10px 12px; border-bottom: 1px solid #e4eef6; text-align: left; vertical-align: top; }
    .flow-table th { background: #f6fbff; color: #54718a; text-transform: uppercase; font-size: 11px; letter-spacing: .06em; }
    .flow-pill { display: inline-block; padding: 4px 8px; border-radius: 999px; background: #edf8ff; border: 1px solid #cce4f5; font-size: 11px; color: #1d4b6d; }
    .flow-empty-state { padding: 16px; border: 1px dashed #bfd4e5; border-radius: 14px; color: #5b7388; background: #fbfdff; }
    .report-actions { margin-bottom: 18px; }
    .report-actions button { padding: 10px 16px; border-radius: 10px; border: 1px solid #0f5f84; background: #0f6e99; color: #fff; font-weight: 700; cursor: pointer; }
    @media print {
      .report-actions { display: none; }
      body { margin: 0; }
      .report-section { break-inside: avoid; }
    }
  </style>
</head>
<body>
  <div class="report-actions">
    <button onclick="window.print()">Salvar como PDF</button>
  </div>
  <header class="report-header">
    <h1>Flow Observatory | IP Lens</h1>
    <p>Relatorio operacional de consulta por IP origem/destino.</p>
    <div class="report-meta">
      <span class="report-chip">IP: {$queryIp}</span>
      <span class="report-chip">Modo: {$modeLabel}</span>
      <span class="report-chip">Janela: {$windowLabel}</span>
      <span class="report-chip">Interface: {$linkLabel}</span>
      <span class="report-chip">ASN: {$asnLabel}</span>
      <span class="report-chip">Gerado em: {$generatedAt}</span>
      <span class="report-chip">Timezone: {$timezoneName}</span>
    </div>
  </header>
  <section class="report-stats">{$summaryHtml}</section>
  <section class="report-section">
    <h2>Serie temporal</h2>
    {$chartHtml}
  </section>
  <section class="report-section">
    <h2>Top contrapartes</h2>
    {$topCounterpartsHtml}
  </section>
  <section class="report-section">
    <h2>Eventos recentes</h2>
    {$recentEventsHtml}
  </section>
</body>
</html>
HTML;
}

function flow_render_query_chart($points) {
    if (empty($points)) {
        return flow_render_empty_state('Sem pontos de telemetria', 'Nenhuma amostra agregada por minuto foi encontrada para o filtro atual.');
    }

    $values = array_values($points);
    $max = max($values);
    if ($max <= 0) {
        $max = 1;
    }

    $width = 920;
    $height = 260;
    $paddingLeft = 54;
    $paddingRight = 18;
    $paddingTop = 18;
    $paddingBottom = 36;
    $plotWidth = $width - $paddingLeft - $paddingRight;
    $plotHeight = $height - $paddingTop - $paddingBottom;
    $count = count($points);
    $stepX = $count > 1 ? $plotWidth / ($count - 1) : 0;

    $linePoints = array();
    $areaPoints = array();
    $labels = array();
    $index = 0;
    foreach ($points as $timestamp => $bytes) {
        $x = $paddingLeft + ($stepX * $index);
        $y = $paddingTop + $plotHeight - (($bytes / $max) * $plotHeight);
        $linePoints[] = sprintf('%.2f,%.2f', $x, $y);
        $areaPoints[] = sprintf('%.2f,%.2f', $x, $y);
        if ($index === 0 || $index === $count - 1 || $index % max(1, (int)floor($count / 5)) === 0) {
            $labels[] = array('x' => $x, 'label' => flow_format_query_time($timestamp));
        }
        $index++;
    }
    $lineString = implode(' ', $linePoints);

    $area = sprintf(
        '%s %.2f,%.2f %.2f,%.2f',
        implode(' ', $areaPoints),
        $paddingLeft + $plotWidth,
        $paddingTop + $plotHeight,
        $paddingLeft,
        $paddingTop + $plotHeight
    );

    $grid = '';
    for ($i = 0; $i <= 4; $i++) {
        $y = $paddingTop + ($plotHeight / 4 * $i);
        $value = format_bytes((int)round($max - (($max / 4) * $i)));
        $grid .= '<line x1="' . $paddingLeft . '" y1="' . $y . '" x2="' . ($paddingLeft + $plotWidth) . '" y2="' . $y . '" />';
        $grid .= '<text x="8" y="' . ($y + 4) . '">' . htmlspecialchars($value) . '</text>';
    }

    $ticks = '';
    foreach ($labels as $tick) {
        $ticks .= '<line x1="' . $tick['x'] . '" y1="' . ($paddingTop + $plotHeight) . '" x2="' . $tick['x'] . '" y2="' . ($paddingTop + $plotHeight + 6) . '" />';
        $ticks .= '<text x="' . $tick['x'] . '" y="' . ($height - 10) . '">' . htmlspecialchars($tick['label']) . '</text>';
    }

    return <<<HTML
<div class="flow-svg-chart">
  <svg viewBox="0 0 {$width} {$height}" role="img" aria-label="Serie temporal de bytes">
    <defs>
      <linearGradient id="flowAreaGradient" x1="0" x2="0" y1="0" y2="1">
        <stop offset="0%" stop-color="rgba(77,212,255,0.55)" />
        <stop offset="100%" stop-color="rgba(0,255,166,0.06)" />
      </linearGradient>
      <filter id="flowGlow">
        <feGaussianBlur stdDeviation="4" result="blur" />
        <feMerge>
          <feMergeNode in="blur" />
          <feMergeNode in="SourceGraphic" />
        </feMerge>
      </filter>
    </defs>
    <g class="grid">{$grid}</g>
    <g class="ticks">{$ticks}</g>
    <polygon class="area" points="{$area}"></polygon>
    <polyline class="line" points="{$lineString}"></polyline>
  </svg>
</div>
HTML;
}

function flow_render_query_table($headers, $rows) {
    if (empty($rows)) {
        return flow_render_empty_state('Sem resultados', 'A consulta retornou zero registros agregados para a janela selecionada.');
    }

    $html = '<div class="flow-table-wrap"><table class="flow-table"><thead><tr>';
    foreach ($headers as $header) {
        $html .= '<th>' . htmlspecialchars($header) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            $html .= '<td>' . $cell . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

function flow_query_pipeline_snapshot($dbPath) {
    $snapshot = array(
        'db_ready' => is_file($dbPath),
        'total_rows' => 0,
        'recent_rows' => 0,
        'last_seen' => null,
    );

    if (!$snapshot['db_ready']) {
        return $snapshot;
    }

    $dbError = null;
    $db = flow_query_open_db($dbPath, $dbError);
    if (!$db) {
        $snapshot['db_ready'] = false;
        return $snapshot;
    }
    if (!flow_query_has_events_table($db)) {
        $snapshot['db_ready'] = false;
        $db->close();
        return $snapshot;
    }

    $row = @$db->querySingle('SELECT COUNT(*) AS total_rows, MAX(minute_ts) AS last_seen FROM flow_events', true);
    if (is_array($row)) {
        $snapshot['total_rows'] = isset($row['total_rows']) ? (int)$row['total_rows'] : 0;
        $snapshot['last_seen'] = isset($row['last_seen']) && $row['last_seen'] !== null ? (int)$row['last_seen'] : null;
    }

    $recentStmt = @$db->prepare('SELECT COUNT(*) AS recent_rows FROM flow_events WHERE minute_ts >= :start');
    if ($recentStmt) {
        $recentStmt->bindValue(':start', time() - 3600, SQLITE3_INTEGER);
        $recentResult = @$recentStmt->execute();
        if ($recentResult) {
            $recentRow = @$recentResult->fetchArray(SQLITE3_ASSOC);
            if (is_array($recentRow)) {
                $snapshot['recent_rows'] = isset($recentRow['recent_rows']) ? (int)$recentRow['recent_rows'] : 0;
            }
        }
    }

    $db->close();
    return $snapshot;
}

$queryIp = isset($_GET['ip']) ? trim($_GET['ip']) : '';
$queryMode = isset($_GET['mode']) ? $_GET['mode'] : 'any';
$queryLink = isset($_GET['link']) ? trim($_GET['link']) : '';
$queryAsn = isset($_GET['asn']) ? trim($_GET['asn']) : '';
$queryHours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
$exportPdf = isset($_GET['export']) && $_GET['export'] === 'pdf';
$queryHours = array_key_exists($queryHours, flow_query_hours_options()) ? $queryHours : 24;
$dbPath = flow_query_db_path();
$dbReady = file_exists($dbPath);
$pipeline = flow_query_pipeline_snapshot($dbPath);
$searchError = '';
$windowStart = time() - ($queryHours * 3600);

$summaryCards = array(
    array('label' => 'Base de consulta', 'value' => $dbReady ? 'ativa' : 'indisponivel'),
    array('label' => 'Janela', 'value' => flow_query_hours_options()[$queryHours]),
    array('label' => 'Filtro', 'value' => strtoupper($queryMode)),
);

$chartHtml = flow_render_empty_state('Aguardando IP', 'Informe um IP de origem ou destino para abrir a trilha operacional.');
$topCounterpartsHtml = flow_render_empty_state('Sem analise', 'A consulta sera populada quando um IP valido for informado.');
$recentEventsHtml = flow_render_empty_state('Sem eventos', 'Nenhum evento agregado foi selecionado ainda.');
$insightsHtml = flow_render_empty_state('Pipeline indisponivel', 'A base flow_events.db ainda nao foi criada por esse coletor.');

if ($pipeline['db_ready']) {
    $insightsHtml = '<div class="flow-kpi-strip">'
        . '<div class="flow-kpi"><span>Base</span><strong>flow_events.db</strong></div>'
        . '<div class="flow-kpi"><span>Linhas totais</span><strong>' . htmlspecialchars(number_format($pipeline['total_rows'], 0, ',', '.')) . '</strong></div>'
        . '<div class="flow-kpi"><span>Ultima amostra</span><strong>' . htmlspecialchars($pipeline['last_seen'] ? flow_format_query_time($pipeline['last_seen']) : 'sem dados') . '</strong></div>'
        . '<div class="flow-kpi"><span>Ultima hora</span><strong>' . htmlspecialchars(number_format($pipeline['recent_rows'], 0, ',', '.')) . ' eventos</strong></div>'
        . '</div>';
}

if ($queryIp !== '') {
    if (!flow_validate_ip_filter($queryIp)) {
        $searchError = 'Informe um IP valido ou prefixo CIDR, como 45.173.100.0/22 ou 2804:5af4::/32.';
    } elseif (!flow_validate_asn_filter($queryAsn)) {
        $searchError = 'O filtro de ASN precisa ser numerico.';
    } elseif (!$dbReady) {
        $searchError = 'A base flow_events.db ainda nao existe neste ambiente. Rode a corretiva do coletor e aguarde novas amostras.';
    } else {
        $dbError = '';
        $db = flow_query_open_db($dbPath, $dbError);
        if (!$db) {
            $searchError = $dbError !== '' ? $dbError : 'Nao foi possivel abrir a base de eventos por IP.';
        } elseif (!flow_query_has_events_table($db)) {
            $searchError = 'A base flow_events.db existe, mas a tabela flow_events ainda nao foi criada pelo coletor.';
            $db->close();
        } elseif (!flow_query_register_ip_matcher($db)) {
            $searchError = 'Nao foi possivel habilitar o filtro de IP/CIDR no SQLite.';
            $db->close();
        } else {
            $where = 'minute_ts >= :start';
            if ($queryMode === 'src') {
                $where .= ' AND flow_ip_filter_match(src_ip, :ip_filter) = 1';
            } elseif ($queryMode === 'dst') {
                $where .= ' AND flow_ip_filter_match(dst_ip, :ip_filter) = 1';
            } else {
                $where .= ' AND (flow_ip_filter_match(src_ip, :ip_filter) = 1 OR flow_ip_filter_match(dst_ip, :ip_filter) = 1)';
            }
            if ($queryLink !== '') {
                $where .= ' AND link_tag = :link_tag';
            }
            if ($queryAsn !== '') {
                if ($queryMode === 'src') {
                    $where .= ' AND src_asn = :asn_filter';
                } elseif ($queryMode === 'dst') {
                    $where .= ' AND dst_asn = :asn_filter';
                } else {
                    $where .= ' AND (src_asn = :asn_filter OR dst_asn = :asn_filter)';
                }
            }

            $queryFailureMessage = 'A base de eventos por IP esta ocupada no momento. Tente novamente em alguns segundos.';
            $summary = array(
                'total_bytes' => 0,
                'total_samples' => 0,
                'link_count' => 0,
                'counterpart_count' => 0,
                'matched_rows' => 0,
            );

            $summaryStmt = @$db->prepare(
                "SELECT
                    COALESCE(SUM(bytes), 0) AS total_bytes,
                    COALESCE(SUM(samples), 0) AS total_samples,
                    COUNT(DISTINCT link_tag) AS link_count,
                    COUNT(DISTINCT CASE WHEN flow_ip_filter_match(src_ip, :ip_filter) = 1 THEN dst_ip ELSE src_ip END) AS counterpart_count,
                    COUNT(*) AS matched_rows
                 FROM flow_events
                 WHERE {$where}"
            );
            flow_query_bind_filters($summaryStmt, $windowStart, $queryIp, $queryLink, $queryAsn);
            $summaryResult = flow_query_execute_assoc($summaryStmt, $searchError, $queryFailureMessage);
            if ($summaryResult !== false) {
                $summaryRow = @$summaryResult->fetchArray(SQLITE3_ASSOC);
                if (is_array($summaryRow)) {
                    $summary = array_merge($summary, $summaryRow);
                }

                $summaryCards[] = array('label' => 'Bytes', 'value' => format_bytes((int)$summary['total_bytes']));
                $summaryCards[] = array('label' => 'Amostras', 'value' => number_format((int)$summary['total_samples'], 0, ',', '.'));
                $summaryCards[] = array('label' => 'Links', 'value' => (int)$summary['link_count']);
                $summaryCards[] = array('label' => 'Contrapartes', 'value' => (int)$summary['counterpart_count']);
                $summaryCards[] = array('label' => 'Linhas filtradas', 'value' => number_format((int)$summary['matched_rows'], 0, ',', '.'));
                if ($queryLink !== '') {
                    $summaryCards[] = array('label' => 'Interface', 'value' => $queryLink);
                }
                if ($queryAsn !== '') {
                    $summaryCards[] = array('label' => 'ASN', 'value' => 'AS' . $queryAsn);
                }

                $timelineStmt = @$db->prepare(
                    "SELECT minute_ts, SUM(bytes) AS total_bytes
                     FROM flow_events
                     WHERE {$where}
                     GROUP BY minute_ts
                     ORDER BY minute_ts"
                );
                flow_query_bind_filters($timelineStmt, $windowStart, $queryIp, $queryLink, $queryAsn);
                $timelineResult = flow_query_execute_assoc($timelineStmt, $searchError, $queryFailureMessage);
                if ($timelineResult !== false) {
                    $timelinePoints = array();
                    while ($row = @$timelineResult->fetchArray(SQLITE3_ASSOC)) {
                        $timelinePoints[(int)$row['minute_ts']] = (int)$row['total_bytes'];
                    }
                    $chartHtml = flow_render_query_chart($timelinePoints);
                }

                if ($searchError === '') {
                    $counterpartsStmt = @$db->prepare(
                        "SELECT
                            CASE WHEN flow_ip_filter_match(src_ip, :ip_filter) = 1 THEN dst_ip ELSE src_ip END AS counterpart_ip,
                            CASE WHEN flow_ip_filter_match(src_ip, :ip_filter) = 1 THEN dst_asn ELSE src_asn END AS counterpart_asn,
                            SUM(bytes) AS total_bytes,
                            COUNT(DISTINCT link_tag) AS link_count,
                            MAX(minute_ts) AS last_seen
                         FROM flow_events
                         WHERE {$where}
                         GROUP BY counterpart_ip, counterpart_asn
                         ORDER BY total_bytes DESC
                         LIMIT 20"
                    );
                    flow_query_bind_filters($counterpartsStmt, $windowStart, $queryIp, $queryLink, $queryAsn);
                    $counterpartsResult = flow_query_execute_assoc($counterpartsStmt, $searchError, $queryFailureMessage);
                    if ($counterpartsResult !== false) {
                        $counterpartRows = array();
                        while ($row = @$counterpartsResult->fetchArray(SQLITE3_ASSOC)) {
                            $counterpartRows[] = array(
                                '<span class="flow-pill">' . htmlspecialchars($row['counterpart_ip']) . '</span>',
                                flow_render_asn_link($row['counterpart_asn']),
                                htmlspecialchars(format_bytes((int)$row['total_bytes'])),
                                htmlspecialchars((string)$row['link_count']),
                                htmlspecialchars(flow_format_query_time($row['last_seen'])),
                            );
                        }
                        $topCounterpartsHtml = flow_render_query_table(
                            array('Contraparte', 'ASN', 'Bytes', 'Links', 'Ultimo seen'),
                            $counterpartRows
                        );
                    }
                }

                if ($searchError === '') {
                    $recentStmt = @$db->prepare(
                        "SELECT
                            minute_ts,
                            link_tag,
                            direction,
                            ip_version,
                            src_ip,
                            src_asn,
                            dst_ip,
                            dst_asn,
                            SUM(bytes) AS total_bytes,
                            SUM(samples) AS total_samples
                         FROM flow_events
                         WHERE {$where}
                         GROUP BY minute_ts, link_tag, direction, ip_version, src_ip, src_asn, dst_ip, dst_asn
                         ORDER BY minute_ts DESC, total_bytes DESC
                         LIMIT 40"
                    );
                    flow_query_bind_filters($recentStmt, $windowStart, $queryIp, $queryLink, $queryAsn);
                    $recentResult = flow_query_execute_assoc($recentStmt, $searchError, $queryFailureMessage);
                    if ($recentResult !== false) {
                        $recentRows = array();
                        while ($row = @$recentResult->fetchArray(SQLITE3_ASSOC)) {
                            $recentRows[] = array(
                                htmlspecialchars(flow_format_query_time($row['minute_ts'])),
                                flow_render_link_badge($row['link_tag']),
                                htmlspecialchars(strtoupper($row['direction'])),
                                'IPv' . htmlspecialchars($row['ip_version']),
                                flow_render_endpoint_cell($row['src_ip'], $row['src_asn']),
                                flow_render_endpoint_cell($row['dst_ip'], $row['dst_asn']),
                                htmlspecialchars(format_bytes((int)$row['total_bytes'])),
                                htmlspecialchars((string)$row['total_samples']),
                            );
                        }
                        $recentEventsHtml = flow_render_query_table(
                            array('Minuto', 'Link', 'Direcao', 'IP', 'Origem', 'Destino', 'Bytes', 'Samples'),
                            $recentRows
                        );
                    }
                }

                $insightsHtml = '<div class="flow-kpi-strip">'
                    . '<div class="flow-kpi"><span>Lookup</span><strong>' . htmlspecialchars($queryIp) . '</strong></div>'
                    . '<div class="flow-kpi"><span>Modo</span><strong>' . htmlspecialchars(strtoupper($queryMode)) . '</strong></div>'
                    . '<div class="flow-kpi"><span>Interface</span><strong>' . htmlspecialchars($queryLink !== '' ? $queryLink : 'Todas') . '</strong></div>'
                    . '<div class="flow-kpi"><span>ASN</span><strong>' . htmlspecialchars($queryAsn !== '' ? 'AS' . $queryAsn : 'Todos') . '</strong></div>'
                    . '<div class="flow-kpi"><span>Base</span><strong>' . htmlspecialchars(basename($dbPath)) . '</strong></div>'
                    . '<div class="flow-kpi"><span>Linhas filtradas</span><strong>' . htmlspecialchars(number_format((int)$summary['matched_rows'], 0, ',', '.')) . '</strong></div>'
                    . '<div class="flow-kpi"><span>Ultima amostra global</span><strong>' . htmlspecialchars($pipeline['last_seen'] ? flow_format_query_time($pipeline['last_seen']) : 'sem dados') . '</strong></div>'
                    . '</div>';
            }

            $db->close();
        }
    }
}

if ($exportPdf) {
    if ($queryIp === '' || $searchError !== '') {
        echo 'Nao foi possivel gerar o relatorio PDF com os parametros atuais.';
        exit;
    }
    flow_render_pdf_document(
        htmlspecialchars($queryIp),
        $queryMode,
        $queryHours,
        $queryLink,
        $queryAsn,
        $summaryCards,
        $chartHtml,
        $topCounterpartsHtml,
        $recentEventsHtml
    );
    exit;
}

flow_render_shell_start('Flow | IP Lens', 'ipsearch');
echo flow_render_hero(
    'ip lens',
    'Investigacao por IP origem/destino',
    'Consulta operacional em base paralela agregada por minuto, com correlacao imediata de IP, ASN, link monitorado e direcao.',
    $summaryCards
);

$form = '<form method="get" action="ipsearch.php" class="flow-form-stack flow-search-form">'
    . '<label>IP de origem ou destino</label>'
    . flow_render_mode_switch($queryMode)
    . '<div class="flow-search-stack">'
    . '<input class="flow-input flow-input-xl flow-search-ip" type="text" name="ip" value="' . htmlspecialchars($queryIp) . '" placeholder="Ex.: 8.8.8.8, 45.173.100.0/22 ou 2804:5af4::/32" />'
    . '<label>ASN opcional</label>'
    . '<input class="flow-input flow-input-xl" type="text" name="asn" value="' . htmlspecialchars($queryAsn) . '" placeholder="Ex.: 268840" />'
    . '<label>Interface e janela</label>'
    . '<div class="flow-search-filter-grid">'
    . str_replace('class="flow-input"', 'class="flow-input flow-search-link"', flow_render_select('link', $queryLink, flow_query_link_options()))
    . str_replace('class="flow-input"', 'class="flow-input flow-search-hours"', flow_render_select('hours', $queryHours, flow_query_hours_options()))
    . '</div>'
    . '<button class="flow-button flow-button-xl flow-search-submit" type="submit">Investigar</button>'
    . '</div>'
    . '</form>';

if ($searchError !== '') {
    $form .= '<div class="flow-inline-alert">' . htmlspecialchars($searchError) . '</div>';
} elseif ($queryIp !== '') {
    $form .= '<div class="flow-search-actions">'
        . '<a class="flow-button flow-button-ghost flow-button-export" href="' . htmlspecialchars(flow_build_export_url($queryIp, $queryMode, $queryHours, $queryLink, $queryAsn)) . '" target="_blank" rel="noopener noreferrer">Exportar PDF</a>'
        . '<span class="flow-search-hint">Abre um relatorio limpo para salvar em PDF com o filtro atual.</span>'
        . '</div>';
}

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Console de busca', $form, 'fa-search');
echo flow_render_panel('Telemetria da consulta', $insightsHtml, 'fa-bolt');
echo '</div>';
echo '<div class="flow-stack">';
echo flow_render_panel('Serie temporal', $chartHtml, 'fa-area-chart');
echo flow_render_panel('Top contrapartes', $topCounterpartsHtml, 'fa-exchange');
echo flow_render_panel('Eventos recentes', $recentEventsHtml, 'fa-table');
echo '</div>';
echo '</div>';

flow_render_shell_end();

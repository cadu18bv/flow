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

function flow_format_query_time($timestamp) {
    return date('d/m H:i', (int)$timestamp);
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

$queryIp = isset($_GET['ip']) ? trim($_GET['ip']) : '';
$queryMode = isset($_GET['mode']) ? $_GET['mode'] : 'any';
$queryHours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
$queryHours = array_key_exists($queryHours, flow_query_hours_options()) ? $queryHours : 24;
$dbPath = flow_query_db_path();
$dbReady = file_exists($dbPath);
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

if ($queryIp !== '') {
    if (!filter_var($queryIp, FILTER_VALIDATE_IP)) {
        $searchError = 'O valor informado nao e um IP valido.';
    } elseif (!$dbReady) {
        $searchError = 'A base flow_events.db ainda nao existe neste ambiente. Rode a corretiva do coletor e aguarde novas amostras.';
    } else {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);

        $where = 'minute_ts >= :start';
        if ($queryMode === 'src') {
            $where .= ' AND src_ip = :ip';
        } elseif ($queryMode === 'dst') {
            $where .= ' AND dst_ip = :ip';
        } else {
            $where .= ' AND (src_ip = :ip OR dst_ip = :ip)';
        }

        $summaryStmt = $db->prepare(
            "SELECT
                COALESCE(SUM(bytes), 0) AS total_bytes,
                COALESCE(SUM(samples), 0) AS total_samples,
                COUNT(DISTINCT link_tag) AS link_count,
                COUNT(DISTINCT CASE WHEN src_ip = :ip THEN dst_ip ELSE src_ip END) AS counterpart_count
             FROM flow_events
             WHERE {$where}"
        );
        $summaryStmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
        $summaryStmt->bindValue(':ip', $queryIp, SQLITE3_TEXT);
        $summary = $summaryStmt->execute()->fetchArray(SQLITE3_ASSOC);

        $summaryCards[] = array('label' => 'Bytes', 'value' => format_bytes((int)$summary['total_bytes']));
        $summaryCards[] = array('label' => 'Amostras', 'value' => number_format((int)$summary['total_samples'], 0, ',', '.'));
        $summaryCards[] = array('label' => 'Links', 'value' => (int)$summary['link_count']);
        $summaryCards[] = array('label' => 'Contrapartes', 'value' => (int)$summary['counterpart_count']);

        $timelineStmt = $db->prepare(
            "SELECT minute_ts, SUM(bytes) AS total_bytes
             FROM flow_events
             WHERE {$where}
             GROUP BY minute_ts
             ORDER BY minute_ts"
        );
        $timelineStmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
        $timelineStmt->bindValue(':ip', $queryIp, SQLITE3_TEXT);
        $timelineResult = $timelineStmt->execute();
        $timelinePoints = array();
        while ($row = $timelineResult->fetchArray(SQLITE3_ASSOC)) {
            $timelinePoints[(int)$row['minute_ts']] = (int)$row['total_bytes'];
        }
        $chartHtml = flow_render_query_chart($timelinePoints);

        $counterpartsStmt = $db->prepare(
            "SELECT
                CASE WHEN src_ip = :ip THEN dst_ip ELSE src_ip END AS counterpart_ip,
                CASE WHEN src_ip = :ip THEN dst_asn ELSE src_asn END AS counterpart_asn,
                SUM(bytes) AS total_bytes,
                COUNT(DISTINCT link_tag) AS link_count,
                MAX(minute_ts) AS last_seen
             FROM flow_events
             WHERE {$where}
             GROUP BY counterpart_ip, counterpart_asn
             ORDER BY total_bytes DESC
             LIMIT 20"
        );
        $counterpartsStmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
        $counterpartsStmt->bindValue(':ip', $queryIp, SQLITE3_TEXT);
        $counterpartsResult = $counterpartsStmt->execute();
        $counterpartRows = array();
        while ($row = $counterpartsResult->fetchArray(SQLITE3_ASSOC)) {
            $counterpartRows[] = array(
                '<span class="flow-pill">' . htmlspecialchars($row['counterpart_ip']) . '</span>',
                'AS' . htmlspecialchars($row['counterpart_asn']),
                htmlspecialchars(format_bytes((int)$row['total_bytes'])),
                htmlspecialchars((string)$row['link_count']),
                htmlspecialchars(flow_format_query_time($row['last_seen'])),
            );
        }
        $topCounterpartsHtml = flow_render_query_table(
            array('Contraparte', 'ASN', 'Bytes', 'Links', 'Ultimo seen'),
            $counterpartRows
        );

        $recentStmt = $db->prepare(
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
        $recentStmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
        $recentStmt->bindValue(':ip', $queryIp, SQLITE3_TEXT);
        $recentResult = $recentStmt->execute();
        $recentRows = array();
        while ($row = $recentResult->fetchArray(SQLITE3_ASSOC)) {
            $recentRows[] = array(
                htmlspecialchars(flow_format_query_time($row['minute_ts'])),
                '<span class="flow-pill">' . htmlspecialchars($row['link_tag']) . '</span>',
                htmlspecialchars(strtoupper($row['direction'])),
                'IPv' . htmlspecialchars($row['ip_version']),
                htmlspecialchars($row['src_ip']) . ' <small>AS' . htmlspecialchars($row['src_asn']) . '</small>',
                htmlspecialchars($row['dst_ip']) . ' <small>AS' . htmlspecialchars($row['dst_asn']) . '</small>',
                htmlspecialchars(format_bytes((int)$row['total_bytes'])),
                htmlspecialchars((string)$row['total_samples']),
            );
        }
        $recentEventsHtml = flow_render_query_table(
            array('Minuto', 'Link', 'Direcao', 'IP', 'Origem', 'Destino', 'Bytes', 'Samples'),
            $recentRows
        );

        $insightsHtml = '<div class="flow-kpi-strip">'
            . '<div class="flow-kpi"><span>Lookup</span><strong>' . htmlspecialchars($queryIp) . '</strong></div>'
            . '<div class="flow-kpi"><span>Modo</span><strong>' . htmlspecialchars(strtoupper($queryMode)) . '</strong></div>'
            . '<div class="flow-kpi"><span>Base</span><strong>' . htmlspecialchars(basename($dbPath)) . '</strong></div>'
            . '</div>';

        $db->close();
    }
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
    . '<div class="flow-inline-form flow-search-row">'
    . '<input class="flow-input flow-input-xl flow-search-ip" type="text" name="ip" value="' . htmlspecialchars($queryIp) . '" placeholder="Ex.: 8.8.8.8 ou 2800:3f0:4001:..." />'
    . str_replace('class="flow-input"', 'class="flow-input flow-search-select"', flow_render_select('mode', $queryMode, array('any' => 'Qualquer lado', 'src' => 'Somente origem', 'dst' => 'Somente destino')))
    . str_replace('class="flow-input"', 'class="flow-input flow-search-hours"', flow_render_select('hours', $queryHours, flow_query_hours_options()))
    . '<button class="flow-button flow-button-xl flow-search-submit" type="submit">Investigar</button>'
    . '</div>'
    . '</form>';

if ($searchError !== '') {
    $form .= '<div class="flow-inline-alert">' . htmlspecialchars($searchError) . '</div>';
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

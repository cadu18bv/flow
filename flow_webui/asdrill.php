<?php
require_once("func.inc");
require_once("flow_ui.php");

function flow_as_drill_db_path() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'asstats' . DIRECTORY_SEPARATOR . 'flow_events.db';
}

function flow_as_drill_timezone_name() {
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
    $date = $date->setTimezone(new DateTimeZone(flow_as_drill_timezone_name()));
    return $date->format($format);
}

function flow_as_drill_selected_links() {
    $selected = array();
    foreach ($_GET as $key => $value) {
        if (strpos($key, 'link_') === 0 && $value === 'on') {
            $selected[] = substr($key, 5);
        }
    }

    if (!empty($selected)) {
        return $selected;
    }

    if (function_exists('getknownlinks')) {
        foreach (getknownlinks() as $link) {
            if (isset($link['tag'])) {
                $selected[] = $link['tag'];
            }
        }
    }

    return $selected;
}

function flow_as_drill_link_labels() {
    static $labels = null;
    if ($labels !== null) {
        return $labels;
    }

    $labels = array();
    if (!function_exists('getknownlinks')) {
        return $labels;
    }

    foreach (getknownlinks() as $link) {
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

function flow_as_drill_sampling_map() {
    static $sampling = null;
    if ($sampling !== null) {
        return $sampling;
    }

    $sampling = array();
    if (!function_exists('getknownlinks')) {
        return $sampling;
    }

    foreach (getknownlinks() as $link) {
        if (!is_array($link) || !isset($link['tag'])) {
            continue;
        }
        $tag = trim((string)$link['tag']);
        if ($tag === '') {
            continue;
        }

        $value = null;
        foreach (array('sampling', 'samplingrate', 'linksamplingrate') as $key) {
            if (isset($link[$key]) && trim((string)$link[$key]) !== '') {
                $value = (int)trim((string)$link[$key]);
                break;
            }
        }
        if ($value === null || $value <= 0) {
            $value = 1;
        }
        $sampling[$tag] = $value;
    }

    return $sampling;
}

function flow_as_drill_scale_bytes($bytes, $linkTag) {
    $raw = (float)$bytes;
    $tag = trim((string)$linkTag);
    $map = flow_as_drill_sampling_map();
    $factor = isset($map[$tag]) ? (int)$map[$tag] : 1;
    if ($factor <= 0) {
        $factor = 1;
    }
    return $raw * $factor;
}

function flow_as_drill_link_badge($tag) {
    $labels = flow_as_drill_link_labels();
    $tagText = htmlspecialchars((string)$tag);
    $description = isset($labels[$tag]) ? trim((string)$labels[$tag]) : '';

    if ($description !== '' && strcasecmp($description, (string)$tag) !== 0) {
        return '<div class="flow-link-cell"><span class="flow-pill">' . $tagText . '</span><small>' . htmlspecialchars($description) . '</small></div>';
    }

    return '<span class="flow-pill">' . $tagText . '</span>';
}

function flow_as_drill_bgp_he_url($asn) {
    $asn = (int)$asn;
    if ($asn <= 0) {
        return '';
    }
    return 'https://bgp.he.net/AS' . $asn;
}

function flow_as_drill_asn_link($asn) {
    $asn = (int)$asn;
    if ($asn <= 0) {
        return '<small>AS0</small>';
    }

    $url = htmlspecialchars(flow_as_drill_bgp_he_url($asn));
    return '<small>AS' . htmlspecialchars((string)$asn) . ' <a class="flow-asn-link" href="' . $url . '" target="_blank" rel="noopener noreferrer" title="Consultar AS' . htmlspecialchars((string)$asn) . ' no bgp.he"><i class="fa fa-external-link"></i></a></small>';
}

function flow_as_drill_endpoint_cell($ip, $asn) {
    return htmlspecialchars((string)$ip) . ' ' . flow_as_drill_asn_link($asn);
}

function flow_as_drill_query_table($headers, $rows) {
    if (empty($rows)) {
        return flow_render_empty_state('Sem resultados', 'Nenhum registro agregado foi encontrado para este ASN na janela selecionada.');
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

function flow_as_drill_query_chart($points) {
    if (empty($points)) {
        return flow_render_empty_state('Sem pontos de telemetria', 'Nenhuma amostra agregada por minuto foi encontrada para este ASN.');
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
            $labels[] = array('x' => $x, 'label' => flow_format_timestamp_local($timestamp, 'd/m H:i'));
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
  <svg viewBox="0 0 {$width} {$height}" role="img" aria-label="Serie temporal do ASN">
    <defs>
      <linearGradient id="flowAsDrillAreaGradient" x1="0" x2="0" y1="0" y2="1">
        <stop offset="0%" stop-color="rgba(77,212,255,0.55)" />
        <stop offset="100%" stop-color="rgba(0,255,166,0.06)" />
      </linearGradient>
      <filter id="flowAsDrillGlow">
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

$queryAs = isset($_GET['as']) ? (int)$_GET['as'] : 0;
$queryHours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 4;
$queryHours = $queryHours > 0 ? $queryHours : 4;
$selectedLinks = flow_as_drill_selected_links();
$windowStart = time() - ($queryHours * 3600);
$dbPath = flow_as_drill_db_path();
$summaryCards = array(
    array('label' => 'Janela', 'value' => $queryHours . ' horas'),
    array('label' => 'Base', 'value' => flow_events_available() ? flow_events_db_label() : 'indisponivel'),
    array('label' => 'Links', 'value' => empty($selectedLinks) ? 'todos' : (string)count($selectedLinks)),
);

$chartHtml = flow_render_empty_state('Aguardando ASN', 'Clique em um grafico do Radar AS para abrir o detalhamento dos IPs observados.');
$counterpartsHtml = flow_render_empty_state('Sem contrapartes', 'As principais contrapartes IP deste ASN aparecerao aqui.');
$localIpsHtml = flow_render_empty_state('Sem IPs locais', 'Os enderecos mais ativos deste ASN serao mostrados aqui.');
$eventsHtml = flow_render_empty_state('Sem eventos', 'Os eventos agregados por minuto aparecerao neste painel.');
$telemetryHtml = flow_render_empty_state('Sem telemetria', 'Ainda nao ha resumo operacional para o ASN selecionado.');
$heroBody = 'Clique em um grafico do radar para abrir a trilha detalhada por IP, com contrapartes, amostras recentes e serie temporal agregada.';
$title = 'Drilldown por ASN';

if ($queryAs > 0 && flow_events_available()) {
    $dbError = null;
    $db = flow_events_open_connection($dbError);
}
if ($queryAs > 0 && isset($db) && $db) {
    $linkConditions = array();
    foreach ($selectedLinks as $index => $tag) {
        $placeholder = ':link' . $index;
        $linkConditions[] = $placeholder;
    }

    $where = 'minute_ts >= :start AND (src_asn = :asn OR dst_asn = :asn)';
    if (!empty($linkConditions)) {
        $where .= ' AND link_tag IN (' . implode(', ', $linkConditions) . ')';
    }

    $bindLinks = function($statement) use ($selectedLinks) {
        foreach ($selectedLinks as $index => $tag) {
            $statement->bindValue(':link' . $index, $tag, SQLITE3_TEXT);
        }
    };

    $summaryStmt = $db->prepare('SELECT link_tag, bytes, samples, minute_ts, CASE WHEN src_asn = :asn THEN dst_ip ELSE src_ip END AS counterpart_ip FROM flow_events WHERE ' . $where);
    $summaryStmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
    $summaryStmt->bindValue(':asn', $queryAs, SQLITE3_INTEGER);
    $bindLinks($summaryStmt);
    $summaryResult = $summaryStmt->execute();
    $summaryTotalBytes = 0.0;
    $summaryTotalSamples = 0;
    $summaryLastSeen = 0;
    $summaryLinks = array();
    $summaryCounterparts = array();
    while ($summaryResult && ($summaryRow = $summaryResult->fetchArray(SQLITE3_ASSOC))) {
        $tag = isset($summaryRow['link_tag']) ? (string)$summaryRow['link_tag'] : '';
        $summaryTotalBytes += flow_as_drill_scale_bytes((float)$summaryRow['bytes'], $tag);
        $summaryTotalSamples += (int)$summaryRow['samples'];
        $summaryLastSeen = max($summaryLastSeen, (int)$summaryRow['minute_ts']);
        if ($tag !== '') {
            $summaryLinks[$tag] = true;
        }
        $counterpartIp = isset($summaryRow['counterpart_ip']) ? trim((string)$summaryRow['counterpart_ip']) : '';
        if ($counterpartIp !== '') {
            $summaryCounterparts[$counterpartIp] = true;
        }
    }

    if ($summaryTotalBytes > 0) {
        $summaryCards = array(
            array('label' => 'ASN', 'value' => 'AS' . $queryAs),
            array('label' => 'Trafego agregado (estimado)', 'value' => format_bytes((int)round($summaryTotalBytes))),
            array('label' => 'Samples', 'value' => number_format((int)$summaryTotalSamples, 0, ',', '.')),
            array('label' => 'Contrapartes', 'value' => number_format(count($summaryCounterparts), 0, ',', '.')),
            array('label' => 'Links ativos', 'value' => number_format(count($summaryLinks), 0, ',', '.')),
            array('label' => 'Ultima amostra', 'value' => $summaryLastSeen > 0 ? flow_format_timestamp_local((int)$summaryLastSeen, 'd/m H:i') : 'sem dados'),
        );
        $heroBody = 'Relatorio detalhado de consumo por IP do AS' . $queryAs . ', considerando a janela atual e os links filtrados no Radar AS.';
        $title = 'Drilldown AS' . $queryAs;

        $timelineStmt = $db->prepare('SELECT minute_ts, link_tag, SUM(bytes) AS total_bytes FROM flow_events WHERE ' . $where . ' GROUP BY minute_ts, link_tag ORDER BY minute_ts');
        $timelineStmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
        $timelineStmt->bindValue(':asn', $queryAs, SQLITE3_INTEGER);
        $bindLinks($timelineStmt);
        $timelineResult = $timelineStmt->execute();
        $points = array();
        while ($row = $timelineResult->fetchArray(SQLITE3_ASSOC)) {
            $minuteTs = (int)$row['minute_ts'];
            if (!isset($points[$minuteTs])) {
                $points[$minuteTs] = 0.0;
            }
            $points[$minuteTs] += flow_as_drill_scale_bytes((float)$row['total_bytes'], isset($row['link_tag']) ? $row['link_tag'] : '');
        }
        $chartHtml = flow_as_drill_query_chart($points);

        $counterpartStmt = $db->prepare('SELECT CASE WHEN src_asn = :asn THEN dst_ip ELSE src_ip END AS counterpart_ip, CASE WHEN src_asn = :asn THEN dst_asn ELSE src_asn END AS counterpart_asn, link_tag, SUM(bytes) AS total_bytes, SUM(samples) AS total_samples, MAX(minute_ts) AS last_seen FROM flow_events WHERE ' . $where . ' GROUP BY counterpart_ip, counterpart_asn, link_tag ORDER BY total_bytes DESC LIMIT 500');
        $counterpartStmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
        $counterpartStmt->bindValue(':asn', $queryAs, SQLITE3_INTEGER);
        $bindLinks($counterpartStmt);
        $counterpartResult = $counterpartStmt->execute();
        $counterpartAgg = array();
        while ($row = $counterpartResult->fetchArray(SQLITE3_ASSOC)) {
            $key = (string)$row['counterpart_ip'] . '|' . (string)$row['counterpart_asn'];
            $scaledBytes = flow_as_drill_scale_bytes((float)$row['total_bytes'], isset($row['link_tag']) ? $row['link_tag'] : '');
            if (!isset($counterpartAgg[$key])) {
                $counterpartAgg[$key] = array(
                    'ip' => (string)$row['counterpart_ip'],
                    'asn' => (int)$row['counterpart_asn'],
                    'bytes' => 0.0,
                    'links' => array(),
                    'samples' => 0,
                    'last_seen' => 0,
                );
            }
            $counterpartAgg[$key]['bytes'] += $scaledBytes;
            $counterpartAgg[$key]['samples'] += (int)$row['total_samples'];
            $counterpartAgg[$key]['last_seen'] = max((int)$counterpartAgg[$key]['last_seen'], (int)$row['last_seen']);
            $tag = isset($row['link_tag']) ? trim((string)$row['link_tag']) : '';
            if ($tag !== '') {
                $counterpartAgg[$key]['links'][$tag] = true;
            }
        }
        uasort($counterpartAgg, function ($a, $b) {
            if ($a['bytes'] == $b['bytes']) {
                return 0;
            }
            return ($a['bytes'] > $b['bytes']) ? -1 : 1;
        });
        $counterpartRows = array();
        $i = 0;
        foreach ($counterpartAgg as $item) {
            if ($i >= 30) {
                break;
            }
            $counterpartRows[] = array(
                flow_as_drill_endpoint_cell($item['ip'], $item['asn']),
                format_bytes((int)round($item['bytes'])),
                number_format(count($item['links']), 0, ',', '.'),
                $item['last_seen'] > 0 ? flow_format_timestamp_local((int)$item['last_seen'], 'd/m H:i') : '-',
            );
            $i++;
        }
        $counterpartsHtml = flow_as_drill_query_table(array('Contraparte', 'Bytes', 'Links', 'Ultima atividade'), $counterpartRows);

        $localStmt = $db->prepare('SELECT CASE WHEN src_asn = :asn THEN src_ip ELSE dst_ip END AS local_ip, link_tag, SUM(bytes) AS total_bytes, COUNT(*) AS row_count, MAX(minute_ts) AS last_seen FROM flow_events WHERE ' . $where . ' GROUP BY local_ip, link_tag ORDER BY total_bytes DESC LIMIT 500');
        $localStmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
        $localStmt->bindValue(':asn', $queryAs, SQLITE3_INTEGER);
        $bindLinks($localStmt);
        $localResult = $localStmt->execute();
        $localAgg = array();
        while ($row = $localResult->fetchArray(SQLITE3_ASSOC)) {
            $key = (string)$row['local_ip'];
            $scaledBytes = flow_as_drill_scale_bytes((float)$row['total_bytes'], isset($row['link_tag']) ? $row['link_tag'] : '');
            if (!isset($localAgg[$key])) {
                $localAgg[$key] = array(
                    'ip' => $key,
                    'bytes' => 0.0,
                    'events' => 0,
                    'last_seen' => 0,
                );
            }
            $localAgg[$key]['bytes'] += $scaledBytes;
            $localAgg[$key]['events'] += (int)$row['row_count'];
            $localAgg[$key]['last_seen'] = max((int)$localAgg[$key]['last_seen'], (int)$row['last_seen']);
        }
        uasort($localAgg, function ($a, $b) {
            if ($a['bytes'] == $b['bytes']) {
                return 0;
            }
            return ($a['bytes'] > $b['bytes']) ? -1 : 1;
        });
        $localRows = array();
        $i = 0;
        foreach ($localAgg as $item) {
            if ($i >= 30) {
                break;
            }
            $localRows[] = array(
                htmlspecialchars((string)$item['ip']),
                format_bytes((int)round($item['bytes'])),
                number_format((int)$item['events'], 0, ',', '.'),
                $item['last_seen'] > 0 ? flow_format_timestamp_local((int)$item['last_seen'], 'd/m H:i') : '-',
            );
            $i++;
        }
        $localIpsHtml = flow_as_drill_query_table(array('IP do ASN', 'Bytes', 'Eventos', 'Ultima atividade'), $localRows);

        $eventsStmt = $db->prepare('SELECT minute_ts, link_tag, ip_version, direction, src_ip, src_asn, dst_ip, dst_asn, bytes, samples FROM flow_events WHERE ' . $where . ' ORDER BY minute_ts DESC, bytes DESC LIMIT 60');
        $eventsStmt->bindValue(':start', $windowStart, SQLITE3_INTEGER);
        $eventsStmt->bindValue(':asn', $queryAs, SQLITE3_INTEGER);
        $bindLinks($eventsStmt);
        $eventsResult = $eventsStmt->execute();
        $eventRows = array();
        while ($row = $eventsResult->fetchArray(SQLITE3_ASSOC)) {
            $scaledEventBytes = flow_as_drill_scale_bytes((float)$row['bytes'], isset($row['link_tag']) ? $row['link_tag'] : '');
            $eventRows[] = array(
                flow_format_timestamp_local((int)$row['minute_ts'], 'd/m H:i'),
                flow_as_drill_link_badge($row['link_tag']),
                htmlspecialchars((string)$row['direction']),
                htmlspecialchars((string)$row['ip_version']),
                flow_as_drill_endpoint_cell($row['src_ip'], $row['src_asn']),
                flow_as_drill_endpoint_cell($row['dst_ip'], $row['dst_asn']),
                format_bytes((int)round($scaledEventBytes)),
                number_format((int)$row['samples'], 0, ',', '.'),
            );
        }
        $eventsHtml = flow_as_drill_query_table(array('Minuto', 'Link', 'Direcao', 'IP', 'Origem', 'Destino', 'Bytes', 'Samples'), $eventRows);

        $telemetryHtml = '<div class="flow-kpi-strip">'
            . '<div class="flow-kpi"><span>ASN analisado</span><strong>AS' . htmlspecialchars((string)$queryAs) . '</strong></div>'
            . '<div class="flow-kpi"><span>Janela</span><strong>' . htmlspecialchars((string)$queryHours) . ' horas</strong></div>'
            . '<div class="flow-kpi"><span>Links filtrados</span><strong>' . htmlspecialchars(empty($selectedLinks) ? 'todos' : implode(', ', $selectedLinks)) . '</strong></div>'
            . '<div class="flow-kpi"><span>Base</span><strong>flow_events (' . htmlspecialchars(flow_events_db_label()) . ')</strong></div>'
            . '</div>';
    }

    $db->close();
}

flow_render_shell_start($title, 'overview');

echo '<section class="flow-hero">';
echo '<div><div class="flow-eyebrow">ASN Drilldown</div><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($heroBody) . '</p></div>';
echo '<div class="flow-hero-stats">';
foreach ($summaryCards as $card) {
    echo '<div class="flow-metric-card"><span>' . htmlspecialchars($card['label']) . '</span><strong>' . htmlspecialchars($card['value']) . '</strong></div>';
}
echo '</div></section>';

echo '<section class="flow-layout">';
echo '<aside class="flow-sidebar">';
echo '<div class="flow-panel"><header class="flow-panel-head"><i class="fa fa-filter"></i><span>Contexto</span></header><div class="flow-panel-body">';
echo flow_render_filter_form($queryHours, 20, $selectedLinks, 'asdrill.php', array('as' => $queryAs));
echo '</div></div>';
echo '<div class="flow-panel"><header class="flow-panel-head"><i class="fa fa-bolt"></i><span>Telemetria</span></header><div class="flow-panel-body">' . $telemetryHtml . '</div></div>';
echo '</aside>';

echo '<div class="flow-content">';
echo '<section class="flow-panel flow-panel-wide"><header class="flow-panel-head"><i class="fa fa-line-chart"></i><span>Serie temporal do ASN</span></header><div class="flow-panel-body">' . $chartHtml . '</div></section>';
echo '<section class="flow-grid-2">';
echo '<div class="flow-panel"><header class="flow-panel-head"><i class="fa fa-exchange"></i><span>Top contrapartes IP</span></header><div class="flow-panel-body">' . $counterpartsHtml . '</div></div>';
echo '<div class="flow-panel"><header class="flow-panel-head"><i class="fa fa-sitemap"></i><span>IPs do proprio ASN</span></header><div class="flow-panel-body">' . $localIpsHtml . '</div></div>';
echo '</section>';
echo '<section class="flow-panel flow-panel-wide"><header class="flow-panel-head"><i class="fa fa-list"></i><span>Eventos recentes</span></header><div class="flow-panel-body">' . $eventsHtml . '</div></section>';
echo '</div></section>';

flow_render_shell_end();

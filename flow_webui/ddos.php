<?php
require_once("func.inc");
require_once("flow_ui.php");

function flow_ddos_db_open() {
    $dbPath = flow_events_db_path();
    if (!is_file($dbPath)) {
        return null;
    }

    try {
        $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
        $db->busyTimeout(2000);
        @$db->exec('PRAGMA busy_timeout = 2000');
        return $db;
    } catch (Exception $exception) {
        return null;
    }
}

function flow_ddos_db_health($db) {
    $health = array('ready' => false, 'rows' => 0, 'last_seen' => null);
    if (!$db instanceof SQLite3) {
        return $health;
    }
    $table = @$db->querySingle("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'flow_events' LIMIT 1");
    if ($table !== 'flow_events') {
        return $health;
    }
    $health['ready'] = true;
    $row = @$db->querySingle('SELECT COUNT(*) AS rows, MAX(minute_ts) AS last_seen FROM flow_events', true);
    if (is_array($row)) {
        $health['rows'] = isset($row['rows']) ? (int)$row['rows'] : 0;
        $health['last_seen'] = isset($row['last_seen']) && $row['last_seen'] !== null ? (int)$row['last_seen'] : null;
    }
    return $health;
}

function flow_ddos_selected_links($knownlinks) {
    $selected = array();
    foreach ($knownlinks as $link) {
        if (!isset($link['tag'])) {
            continue;
        }
        if (isset($_GET['link_' . $link['tag']])) {
            $selected[] = $link['tag'];
        }
    }

    return $selected;
}

function flow_ddos_bind_links($stmt, $selectedLinks, $prefix = 'link_') {
    $index = 0;
    foreach ($selectedLinks as $tag) {
        $stmt->bindValue(':' . $prefix . $index, (string)$tag, SQLITE3_TEXT);
        $index++;
    }
}

function flow_ddos_link_clause($selectedLinks, $prefix = 'link_') {
    if (empty($selectedLinks)) {
        return '';
    }

    $parts = array();
    foreach (array_values($selectedLinks) as $index => $tag) {
        $parts[] = ':' . $prefix . $index;
    }
    return ' AND link_tag IN (' . implode(', ', $parts) . ') ';
}

function flow_ddos_bgp_he($asn) {
    $asn = (int)$asn;
    if ($asn <= 0) {
        return '<span class="flow-inline-asn">AS0</span>';
    }

    $url = 'https://bgp.he.net/AS' . $asn;
    return '<a class="flow-inline-asn" href="' . htmlspecialchars($url) . '" target="_blank" rel="noopener noreferrer">AS' . htmlspecialchars((string)$asn) . '</a>';
}

function flow_ddos_ip_cell($ip, $asn) {
    return '<div class="flow-ip-cell"><strong>' . htmlspecialchars((string)$ip) . '</strong>' . flow_ddos_bgp_he($asn) . '</div>';
}

function flow_ddos_value_chip($label, $value) {
    return '<span class="flow-mini-chip"><small>' . htmlspecialchars($label) . '</small><strong>' . htmlspecialchars($value) . '</strong></span>';
}

function flow_ddos_render_table($headers, $rows) {
    if (empty($rows)) {
        return flow_render_empty_state('Sem dados', 'Nenhum registro foi agregado para esta grade.');
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

function flow_ddos_metric_strip($items) {
    $html = '<div class="flow-mini-chip-row">';
    foreach ($items as $item) {
        $html .= flow_ddos_value_chip($item['label'], $item['value']);
    }
    $html .= '</div>';
    return $html;
}

function flow_ddos_query_targets($db, $windowStart, $selectedLinks, $limit) {
    $sql = "
        SELECT
            dst_ip AS ip,
            MAX(dst_asn) AS asn,
            COUNT(DISTINCT src_ip) AS unique_sources,
            COUNT(DISTINCT src_asn) AS unique_source_asns,
            COUNT(DISTINCT link_tag) AS links,
            SUM(bytes) AS total_bytes,
            SUM(samples) AS total_samples,
            MAX(bytes) AS peak_bytes,
            COUNT(DISTINCT minute_ts) AS active_minutes
        FROM flow_events
        WHERE minute_ts >= :start
          " . flow_ddos_link_clause($selectedLinks, 'target_link_') . "
        GROUP BY dst_ip
        HAVING SUM(bytes) > 0
        ORDER BY unique_sources DESC, total_samples DESC, total_bytes DESC
        LIMIT :limit
    ";

    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return array();
    }

    $stmt->bindValue(':start', (int)$windowStart, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', (int)$limit, SQLITE3_INTEGER);
    flow_ddos_bind_links($stmt, $selectedLinks, 'target_link_');

    $result = @$stmt->execute();
    if ($result === false) {
        return array();
    }

    $rows = array();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function flow_ddos_query_attackers($db, $windowStart, $selectedLinks, $limit) {
    $sql = "
        SELECT
            src_ip AS ip,
            MAX(src_asn) AS asn,
            COUNT(DISTINCT dst_ip) AS unique_targets,
            COUNT(DISTINCT link_tag) AS links,
            SUM(bytes) AS total_bytes,
            SUM(samples) AS total_samples,
            MAX(bytes) AS peak_bytes,
            COUNT(DISTINCT minute_ts) AS active_minutes
        FROM flow_events
        WHERE minute_ts >= :start
          " . flow_ddos_link_clause($selectedLinks, 'attacker_link_') . "
        GROUP BY src_ip
        HAVING SUM(bytes) > 0
        ORDER BY unique_targets DESC, total_samples DESC, total_bytes DESC
        LIMIT :limit
    ";

    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return array();
    }

    $stmt->bindValue(':start', (int)$windowStart, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', (int)$limit, SQLITE3_INTEGER);
    flow_ddos_bind_links($stmt, $selectedLinks, 'attacker_link_');

    $result = @$stmt->execute();
    if ($result === false) {
        return array();
    }

    $rows = array();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function flow_ddos_query_bursts($db, $windowStart, $selectedLinks, $limit) {
    $sql = "
        SELECT
            minute_ts,
            link_tag,
            dst_ip,
            MAX(dst_asn) AS dst_asn,
            COUNT(DISTINCT src_ip) AS unique_sources,
            SUM(bytes) AS total_bytes,
            SUM(samples) AS total_samples
        FROM flow_events
        WHERE minute_ts >= :start
          " . flow_ddos_link_clause($selectedLinks, 'burst_link_') . "
        GROUP BY minute_ts, link_tag, dst_ip
        HAVING SUM(bytes) > 0
        ORDER BY total_samples DESC, total_bytes DESC
        LIMIT :limit
    ";

    $stmt = @$db->prepare($sql);
    if (!$stmt) {
        return array();
    }

    $stmt->bindValue(':start', (int)$windowStart, SQLITE3_INTEGER);
    $stmt->bindValue(':limit', (int)$limit, SQLITE3_INTEGER);
    flow_ddos_bind_links($stmt, $selectedLinks, 'burst_link_');

    $result = @$stmt->execute();
    if ($result === false) {
        return array();
    }

    $rows = array();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $rows[] = $row;
    }

    return $rows;
}

function flow_ddos_overview($targets, $attackers, $bursts) {
    $victims = 0;
    $highFanout = 0;
    $burstSamples = 0;

    foreach ($targets as $row) {
        if ((int)$row['unique_sources'] >= 10) {
            $victims++;
        }
    }

    foreach ($attackers as $row) {
        if ((int)$row['unique_targets'] >= 5) {
            $highFanout++;
        }
    }

    foreach ($bursts as $row) {
        $burstSamples = max($burstSamples, (int)$row['total_samples']);
    }

    return array(
        'suspect_victims' => $victims,
        'suspect_attackers' => $highFanout,
        'peak_samples' => $burstSamples,
    );
}

function flow_ddos_targets_table($targets) {
    if (empty($targets)) {
        return flow_render_empty_state('Sem alvos em destaque', 'Nenhum IP de destino concentrou volume anormal de origens na janela selecionada.');
    }

    $rows = array();
    foreach ($targets as $row) {
        $rows[] = array(
            flow_ddos_ip_cell($row['ip'], $row['asn']),
            htmlspecialchars((string)$row['unique_sources']),
            htmlspecialchars((string)$row['unique_source_asns']),
            htmlspecialchars((string)$row['links']),
            htmlspecialchars(format_bytes((float)$row['total_bytes'])),
            htmlspecialchars((string)$row['total_samples']),
            htmlspecialchars(format_bytes((float)$row['peak_bytes']) . '/min'),
            htmlspecialchars((string)$row['active_minutes']) . ' min',
        );
    }

    return flow_ddos_render_table(
        array('IP sob ataque', 'Fontes unicas', 'ASN remotos', 'Links', 'Bytes', 'Samples', 'Pico', 'Persistencia'),
        $rows
    );
}

function flow_ddos_attackers_table($attackers) {
    if (empty($attackers)) {
        return flow_render_empty_state('Sem emissores em destaque', 'Nenhum IP de origem apresentou fan-out expressivo na janela analisada.');
    }

    $rows = array();
    foreach ($attackers as $row) {
        $rows[] = array(
            flow_ddos_ip_cell($row['ip'], $row['asn']),
            htmlspecialchars((string)$row['unique_targets']),
            htmlspecialchars((string)$row['links']),
            htmlspecialchars(format_bytes((float)$row['total_bytes'])),
            htmlspecialchars((string)$row['total_samples']),
            htmlspecialchars(format_bytes((float)$row['peak_bytes']) . '/min'),
            htmlspecialchars((string)$row['active_minutes']) . ' min',
        );
    }

    return flow_ddos_render_table(
        array('IP emissor', 'Destinos unicos', 'Links', 'Bytes', 'Samples', 'Pico', 'Persistencia'),
        $rows
    );
}

function flow_ddos_burst_cards($bursts) {
    if (empty($bursts)) {
        return flow_render_empty_state('Sem bursts detectados', 'Nenhum burst expressivo foi agregado por minuto e link na janela atual.');
    }

    $html = '<div class="flow-threat-grid">';
    foreach ($bursts as $row) {
        $html .= '<article class="flow-threat-card">';
        $html .= '<header><span>' . htmlspecialchars(date('d/m H:i', (int)$row['minute_ts'])) . '</span><strong>' . htmlspecialchars((string)$row['link_tag']) . '</strong></header>';
        $html .= '<div class="flow-threat-copy">' . flow_ddos_ip_cell($row['dst_ip'], $row['dst_asn']) . '</div>';
        $html .= flow_ddos_metric_strip(array(
            array('label' => 'Fontes', 'value' => (string)$row['unique_sources']),
            array('label' => 'Samples', 'value' => (string)$row['total_samples']),
            array('label' => 'Bytes', 'value' => format_bytes((float)$row['total_bytes'])),
        ));
        $html .= '</article>';
    }
    $html .= '</div>';

    return $html;
}

$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 24;
$hours = $hours > 0 ? $hours : 24;
$limit = isset($_GET['n']) ? (int)$_GET['n'] : 20;
$limit = $limit > 0 ? min($limit, 100) : 20;
$knownlinks = getknownlinks();
$selectedLinks = flow_ddos_selected_links($knownlinks);
$windowStart = time() - ($hours * 3600);
$db = flow_ddos_db_open();

$targets = array();
$attackers = array();
$bursts = array();
$dbHealth = array('ready' => false, 'rows' => 0, 'last_seen' => null);

if ($db) {
    $dbHealth = flow_ddos_db_health($db);
    if ($dbHealth['ready']) {
        $targets = flow_ddos_query_targets($db, $windowStart, $selectedLinks, $limit);
        $attackers = flow_ddos_query_attackers($db, $windowStart, $selectedLinks, $limit);
        $bursts = flow_ddos_query_bursts($db, $windowStart, $selectedLinks, 12);
    }
    $db->close();
}

$overview = flow_ddos_overview($targets, $attackers, $bursts);
$heroStats = array(
    array('label' => 'Janela', 'value' => statsLabelForHours($hours)),
    array('label' => 'Vitimas suspeitas', 'value' => (string)$overview['suspect_victims']),
    array('label' => 'Emissores suspeitos', 'value' => (string)$overview['suspect_attackers']),
    array('label' => 'Peak samples/min', 'value' => (string)$overview['peak_samples']),
    array('label' => 'Eventos na base', 'value' => number_format((int)$dbHealth['rows'], 0, ',', '.')),
);

flow_render_shell_start('Flow | DDoS', 'ddos');
echo flow_render_hero(
    'threat surface',
    'Painel DDoS',
    'Leitura operacional para identificar IPs sob ataque, origens com comportamento de flood e bursts anormais concentrados por link e minuto.',
    $heroStats
);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Controles de observacao', flow_render_filter_form($hours, $limit, $selectedLinks, 'ddos.php'), 'fa-shield');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selectedLinks, $hours, $limit, 'ddos.php'), 'fa-random');
echo flow_render_panel(
    'Heuristica aplicada',
    '<div class="flow-copy-block"><p>Os alvos sao ranqueados por numero de origens unicas, samples agregados e persistencia. A leitura agora usa todo evento de destino disponivel, sem depender exclusivamente de direction=in.</p><p>Base: ' . htmlspecialchars($dbHealth['ready'] ? 'flow_events pronta' : 'flow_events indisponivel') . ' | Ultima amostra: ' . htmlspecialchars($dbHealth['last_seen'] ? date('d/m H:i', (int)$dbHealth['last_seen']) : 'sem dados') . '</p></div>',
    'fa-info-circle'
);
echo '</div>';

echo '<div class="flow-stack">';
echo flow_render_panel('IPs sob maior pressão', flow_ddos_targets_table($targets), 'fa-crosshairs');
echo flow_render_panel('Origens com maior fan-out', flow_ddos_attackers_table($attackers), 'fa-bullhorn');
echo flow_render_panel('Burst timeline operacional', flow_ddos_burst_cards($bursts), 'fa-bolt');
echo '</div>';
echo '</div>';

flow_render_shell_end();

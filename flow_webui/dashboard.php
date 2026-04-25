<?php
require_once("func.inc");
require_once("flow_ui.php");

function flow_dashboard_providers() {
    return array(
        array('key' => 'netflix', 'label' => 'Netflix', 'asn' => 2906, 'eyebrow' => 'streaming core'),
        array('key' => 'facebook', 'label' => 'Facebook', 'asn' => 32934, 'eyebrow' => 'social delivery'),
        array('key' => 'google', 'label' => 'Google', 'asn' => 15169, 'eyebrow' => 'search + video'),
        array('key' => 'akamai', 'label' => 'Akamai', 'asn' => 20940, 'eyebrow' => 'cdn edge'),
        array('key' => 'amazon', 'label' => 'Amazon', 'asn' => 16509, 'eyebrow' => 'cloud platform'),
        array('key' => 'microsoft', 'label' => 'Microsoft', 'asn' => 8075, 'eyebrow' => 'cloud + enterprise'),
    );
}

function flow_dashboard_usage_for_as($asn, $statsfile, $selectedLinks) {
    $top = getasstats_top(1, $statsfile, $selectedLinks, array((int)$asn));
    if (isset($top[$asn])) {
        return $top[$asn];
    }
    $keys = array_keys($top);
    if (!empty($keys) && (int)$keys[0] === (int)$asn) {
        return $top[$keys[0]];
    }
    return array(0, 0, 0, 0);
}

function flow_render_dashboard_provider_card($provider, $hours, $start, $end, $selectedLinks, $peerusage, $showv6) {
    $as = (int)$provider['asn'];
    $asinfo = getASInfo($as);
    $descr = isset($asinfo['descr']) && trim((string)$asinfo['descr']) !== '' ? $asinfo['descr'] : $provider['label'];
    $graph4 = getHTMLUrl($as, 4, $descr, $start, $end, $peerusage, $selectedLinks);
    $graph6 = $showv6 ? getHTMLUrl($as, 6, $descr, $start, $end, $peerusage, $selectedLinks) : '';
    $stats4 = flow_fetch_rrd_graph_stats($as, 4, $start, $end, $peerusage, $selectedLinks);
    $stats6 = $showv6 ? flow_fetch_rrd_graph_stats($as, 6, $start, $end, $peerusage, $selectedLinks) : null;
    $usage = flow_dashboard_usage_for_as($as, isset($peerusage) && $peerusage ? $GLOBALS['daypeerstatsfile'] : statsFileForHours($hours), $selectedLinks);

    $in4 = isset($usage[0]) ? format_bytes($usage[0]) : '0 bytes';
    $out4 = isset($usage[1]) ? format_bytes($usage[1]) : '0 bytes';
    $in6 = isset($usage[2]) ? format_bytes($usage[2]) : '0 bytes';
    $out6 = isset($usage[3]) ? format_bytes($usage[3]) : '0 bytes';

    $html = '<article class="flow-provider-card flow-provider-' . htmlspecialchars($provider['key']) . '">';
    $html .= '<header class="flow-provider-head">';
    $html .= '<div class="flow-provider-copy">';
    $html .= '<span class="flow-eyebrow">' . htmlspecialchars($provider['eyebrow']) . '</span>';
    $html .= '<h3>' . htmlspecialchars($provider['label']) . '</h3>';
    $html .= '<p>AS' . htmlspecialchars((string)$as) . ' • ' . htmlspecialchars($descr) . '</p>';
    $html .= '</div>';
    $html .= '<div class="flow-provider-actions">';
    $html .= '<a class="flow-button flow-button-ghost" href="history.php?as=' . urlencode((string)$as) . '">Abrir ASN</a>';
    $html .= '</div>';
    $html .= '</header>';
    $html .= '<div class="flow-provider-micro">';
    $html .= '<span>IPv4 IN total ' . htmlspecialchars($in4) . '</span>';
    $html .= '<span>IPv4 OUT total ' . htmlspecialchars($out4) . '</span>';
    if ($showv6) {
        $html .= '<span>IPv6 IN total ' . htmlspecialchars($in6) . '</span>';
        $html .= '<span>IPv6 OUT total ' . htmlspecialchars($out6) . '</span>';
    }
    $html .= '</div>';
    $html .= '<div class="flow-graph-pair">';
    $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv4</div>' . $graph4 . flow_render_graph_stats($stats4) . '</div>';
    if ($showv6) {
        $html .= '<div class="flow-graph-card"><div class="flow-graph-label">IPv6</div>' . $graph6 . flow_render_graph_stats($stats6) . '</div>';
    }
    $html .= '</div>';
    $html .= '</article>';

    return $html;
}

$ntop = 6;
$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 24;
if ($hours < 1) $hours = 24;
$label = statsLabelForHours($hours);
$knownlinks = getknownlinks();
$selected_links = array();
$peerusage = isset($peerusage) ? $peerusage : 0;
$start = time() - $hours * 3600;
$end = time();
$providers = flow_dashboard_providers();

foreach ($knownlinks as $link) {
    if (isset($_GET["link_{$link['tag']}"])) {
        $selected_links[] = $link['tag'];
    }
}

$heroStats = array(
    array('label' => 'Janela', 'value' => $label),
    array('label' => 'Provedores', 'value' => count($providers)),
    array('label' => 'Links filtrados', 'value' => count($selected_links) ? count($selected_links) : count($knownlinks)),
    array('label' => 'Camadas', 'value' => $showv6 ? 'IPv4 + IPv6' : 'IPv4'),
);

$cards = '';
foreach ($providers as $provider) {
    $cards .= flow_render_dashboard_provider_card($provider, $hours, $start, $end, $selected_links, $peerusage, $showv6);
}

flow_render_shell_start('Flow | Dashboard', 'dashboard');
echo flow_render_hero('traffic board', 'Dashboard de consumo por plataforma', 'Painel executivo com foco em hyperscalers e plataformas de maior relevancia operacional para a borda.', $heroStats);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Controles do dashboard', flow_render_filter_form($hours, $ntop, $selected_links, 'dashboard.php'), 'fa-sliders');
echo flow_render_panel('Links monitorados', flow_render_legend_form($knownlinks, $selected_links, $hours, $ntop, 'dashboard.php'), 'fa-random');
echo '</div>';
echo '<div class="flow-stack">';
echo flow_render_panel('Hyperscalers observados', '<div class="flow-provider-grid">' . $cards . '</div>', 'fa-dashboard');
echo '</div>';
echo '</div>';

flow_render_shell_end();

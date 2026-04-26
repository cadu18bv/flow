<?php
require_once("func.inc");
require_once("flow_ui.php");

if (!isset($showv6)) {
    $showv6 = 1;
}
if (!isset($default_graph_width) || (int)$default_graph_width <= 0) {
    $default_graph_width = 880;
}
if (!isset($default_graph_height) || (int)$default_graph_height <= 0) {
    $default_graph_height = 260;
}

$selected_links = array();
$knownlinks = getknownlinks();
if (!is_array($knownlinks)) {
    $knownlinks = array();
}
$hours = isset($_GET['numhours']) ? (int)$_GET['numhours'] : 24;
$hours = $hours > 0 ? $hours : 24;
$label = statsLabelForHours($hours);

$heroStats = array(
    array('label' => 'Janela', 'value' => $label),
    array('label' => 'Links', 'value' => count($knownlinks)),
    array('label' => 'Camadas', 'value' => $showv6 ? 'IPv4 + IPv6' : 'IPv4')
);

flow_render_shell_start('Flow | Link Flow', 'links');
echo flow_render_hero('link flow matrix', 'Fluxo por link', 'Visão operacional por circuito, com leitura visual contínua para IPv4 e IPv6.', $heroStats);

$cards = '';
foreach ($knownlinks as $link) {
    if (!isset($link['tag']) || !isset($link['descr'])) {
        continue;
    }
    $stats4 = flow_fetch_link_flow_stats($link['tag'], 4, $hours);
    $stats6 = $showv6 ? flow_fetch_link_flow_stats($link['tag'], 6, $hours) : null;
    if ($stats4 === null && (!$showv6 || $stats6 === null)) {
        continue;
    }
    $graph4 = '<img alt="Fluxo IPv4" src="linkgraph.php?link=' . urlencode($link['tag']) . '&numhours=' . $hours . '&width=' . $default_graph_width . '&height=' . $default_graph_height . '&dname=' . rawurlencode($link['descr'] . ' - IPv4') . '&v=4" />';
    $graph6 = $showv6 ? '<img alt="Fluxo IPv6" src="linkgraph.php?link=' . urlencode($link['tag']) . '&numhours=' . $hours . '&width=' . $default_graph_width . '&height=' . $default_graph_height . '&dname=' . rawurlencode($link['descr'] . ' - IPv6') . '&v=6" />' : '';
    $cards .= flow_render_link_card($link['descr'], $graph4, $graph6, $stats4, $stats6);
}

if ($cards === '') {
    $cards = flow_render_empty_state('Sem circuitos renderizados', 'Nao foi possivel montar os graficos de link com o knownlinks atual.');
}

echo '<div class="flow-grid-single">';
echo flow_render_panel('Grade de circuitos', '<div class="flow-link-grid">' . $cards . '</div>', 'fa-exchange');
echo '</div>';

flow_render_shell_end();

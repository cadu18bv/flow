<?php
require_once("func.inc");
require_once("flow_ui.php");

$selected_links = array();
$val_searchas = isset($_GET['as']) ? $_GET['as'] : '';
$aff_customlinks = '';
$graphs = '';

if (isset($_GET['as'])) {
    $as = str_replace('as', '', str_replace(' ', '', strtolower($_GET['as'])));
    if ($as) $asinfo = flow_enrich_as_info($as, getASInfo($as));
    $title = 'Flow | ASN Explorer';
    $header = 'ASN Explorer';
    $header_small = 'AS' . $as;

    if (isset($_GET['peerusage']) && $_GET['peerusage'] == '1') $peerusage = 1;
    else $peerusage = 0;

    $daily_graph_v4 = getHTMLImg($as, 4, $asinfo['descr'], time() - 24 * 3600, time(), $peerusage, 'daily graph', 'detailgraph', true);
    $weekly_graph_v4 = getHTMLImg($as, 4, $asinfo['descr'], time() - 6.9 * 86400, time(), $peerusage, 'weekly graph', 'detailgraph', true);
    $monthly_graph_v4 = getHTMLImg($as, 4, $asinfo['descr'], time() - 30 * 86400, time(), $peerusage, 'monthly graph', 'detailgraph', true);
    $yearly_graph_v4 = getHTMLImg($as, 4, $asinfo['descr'], time() - 365 * 86400, time(), $peerusage, 'yearly graph', 'detailgraph', true);

    if ($showv6) {
        $daily_graph_v6 = getHTMLImg($as, 6, $asinfo['descr'], time() - 24 * 3600, time(), $peerusage, 'daily graph', 'detailgraph', true);
        $weekly_graph_v6 = getHTMLImg($as, 6, $asinfo['descr'], time() - 6.9 * 86400, time(), $peerusage, 'weekly graph', 'detailgraph', true);
        $monthly_graph_v6 = getHTMLImg($as, 6, $asinfo['descr'], time() - 30 * 86400, time(), $peerusage, 'monthly graph', 'detailgraph', true);
        $yearly_graph_v6 = getHTMLImg($as, 6, $asinfo['descr'], time() - 365 * 86400, time(), $peerusage, 'yearly graph', 'detailgraph', true);
    }

    foreach ($customlinks as $linkname => $url) {
        $url = str_replace("%as%", $as, $url);
        $aff_customlinks .= '<a class="flow-button flow-button-ghost" href="' . htmlspecialchars($url) . '" target="_blank">' . htmlspecialchars($linkname) . '</a> ';
    }

    $graphs .= flow_render_dual_graph('Janela diária', $daily_graph_v4, $showv6 ? $daily_graph_v6 : '');
    $graphs .= flow_render_dual_graph('Janela semanal', $weekly_graph_v4, $showv6 ? $weekly_graph_v6 : '');
    $graphs .= flow_render_dual_graph('Janela mensal', $monthly_graph_v4, $showv6 ? $monthly_graph_v6 : '');
    $graphs .= flow_render_dual_graph('Janela anual', $yearly_graph_v4, $showv6 ? $yearly_graph_v6 : '');

    $heroStats = array(
        array('label' => 'ASN', 'value' => 'AS' . $as),
        array('label' => 'Descrição', 'value' => isset($asinfo['descr']) ? $asinfo['descr'] : 'N/A'),
        array('label' => 'Camadas', 'value' => $showv6 ? 'IPv4 + IPv6' : 'IPv4')
    );
} else {
    $title = 'Flow | ASN Explorer';
    $header = 'ASN Explorer';
    $header_small = 'Pesquisa operacional';
    $heroStats = array(
        array('label' => 'Busca', 'value' => 'ASN'),
        array('label' => 'Modo', 'value' => $showv6 ? 'IPv4 + IPv6' : 'IPv4')
    );
}

flow_render_shell_start($title, 'history');
echo flow_render_hero('asn explorer', $header, 'Pesquisa dedicada por sistema autônomo com histórico visual em múltiplas escalas.', $heroStats);

echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Pesquisar ASN', flow_render_search_form('as', $val_searchas, 'Digite o ASN', 'history.php', array(), 'Consultar'), 'fa-search');
if ($aff_customlinks !== '') {
    echo flow_render_panel('Atalhos operacionais', $aff_customlinks, 'fa-external-link');
}
echo '</div>';

echo '<div class="flow-stack">';
if ($graphs !== '') {
    echo $graphs;
} else {
    echo flow_render_panel('Nenhum ASN selecionado', flow_render_empty_state('Aguardando consulta', 'Informe um ASN para abrir a visão histórica completa.'), 'fa-search');
}
echo '</div>';
echo '</div>';

flow_render_shell_end();

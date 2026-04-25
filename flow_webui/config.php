<?php
require_once("func.inc");
require_once("flow_ui.php");

flow_auth_require_role(array('master', 'admin'));

function flow_config_inc_path() {
    return __DIR__ . DIRECTORY_SEPARATOR . 'config.inc';
}

function flow_knownlinks_path() {
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'conf' . DIRECTORY_SEPARATOR . 'knownlinks';
}

function flow_read_local_asn() {
    $path = flow_config_inc_path();
    if (!is_file($path)) {
        return '';
    }
    $content = (string)file_get_contents($path);
    if (preg_match('/\$my_asn\s*=\s*"([^"]+)";/', $content, $matches)) {
        return $matches[1];
    }
    return '';
}

function flow_write_local_asn($asn) {
    $path = flow_config_inc_path();
    $content = (string)file_get_contents($path);
    $content = preg_replace('/\$my_asn\s*=\s*"([^"]+)";/', '$my_asn = "' . $asn . '";', $content, 1);
    file_put_contents($path, $content);
}

function flow_read_knownlinks_text() {
    $path = flow_knownlinks_path();
    return is_file($path) ? (string)file_get_contents($path) : '';
}

function flow_write_knownlinks_text($text) {
    file_put_contents(flow_knownlinks_path(), rtrim((string)$text) . PHP_EOL);
}

function flow_knownlinks_entries() {
    $entries = array();
    $lines = preg_split('/\r\n|\r|\n/', flow_read_knownlinks_text());

    foreach ($lines as $lineNumber => $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        $parts = explode("\t", $line);
        if (count($parts) < 6) {
            continue;
        }

        $entries[] = array(
            'line' => $lineNumber + 1,
            'exporter' => trim((string)$parts[0]),
            'ifindex' => trim((string)$parts[1]),
            'tag' => trim((string)$parts[2]),
            'description' => trim((string)$parts[3]),
            'color' => strtoupper(trim((string)$parts[4])),
            'sampling' => trim((string)$parts[5]),
        );
    }

    return $entries;
}

function flow_validate_knownlink_fields($exporter, $ifindex, $tag, $description, $color, $sampling, $existingTag = '') {
    $exporter = trim((string)$exporter);
    $ifindex = trim((string)$ifindex);
    $tag = strtoupper(trim((string)$tag));
    $description = preg_replace('/[\r\n\t]+/', ' ', trim((string)$description));
    $color = strtoupper(trim((string)$color));
    $sampling = trim((string)$sampling);

    if ($exporter === '') {
        return array(false, 'Informe o IP ou hostname do exportador.');
    }
    if ($ifindex === '' || !ctype_digit($ifindex)) {
        return array(false, 'O ifIndex precisa ser numerico.');
    }
    if ($tag === '' || !preg_match('/^[A-Z0-9._-]+$/', $tag)) {
        return array(false, 'A TAG deve usar apenas letras, numeros, ponto, underscore ou hifen.');
    }
    if ($description === '') {
        return array(false, 'Informe a descricao do link.');
    }
    if (!preg_match('/^[0-9A-F]{6}$/', $color)) {
        return array(false, 'A cor precisa estar em hexadecimal de 6 caracteres, como 33A02C.');
    }
    if ($sampling === '' || !ctype_digit($sampling)) {
        return array(false, 'O sampling precisa ser numerico.');
    }

    foreach (flow_knownlinks_entries() as $entry) {
        if ($entry['tag'] === $tag && $entry['tag'] !== strtoupper(trim((string)$existingTag))) {
            return array(false, 'Ja existe uma TAG igual no knownlinks: ' . $tag);
        }
    }

    return array(true, array(
        'exporter' => $exporter,
        'ifindex' => $ifindex,
        'tag' => $tag,
        'description' => $description,
        'color' => $color,
        'sampling' => $sampling,
    ));
}

function flow_validate_knownlinks_text($text) {
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    foreach ($lines as $index => $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '' || strpos($trimmed, '#') === 0) {
            continue;
        }

        $parts = explode("\t", $line);
        if (count($parts) !== 6) {
            return array(false, 'Linha ' . ($index + 1) . ' invalida. O arquivo precisa usar TAB e exatamente 6 colunas.');
        }

        list($ok, $payload) = flow_validate_knownlink_fields($parts[0], $parts[1], $parts[2], $parts[3], $parts[4], $parts[5], $parts[2]);
        if (!$ok) {
            return array(false, 'Linha ' . ($index + 1) . ': ' . $payload);
        }
    }

    return array(true, null);
}

function flow_render_audit_table($rows) {
    if (empty($rows)) {
        return flow_render_empty_state('Sem eventos recentes', 'As alteracoes administrativas e acessos relevantes passarao a aparecer aqui.');
    }

    $html = '<div class="flow-table-wrap"><table class="flow-table"><thead><tr><th>Quando</th><th>Usuario</th><th>Perfil</th><th>Acao</th><th>Alvo</th><th>Origem</th><th>Detalhes</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        $html .= '<td>' . htmlspecialchars($row['created_at']) . '</td>';
        $html .= '<td>' . htmlspecialchars($row['username'] ?: 'sistema') . '</td>';
        $html .= '<td>' . htmlspecialchars(strtoupper($row['role'] ?: 'N/A')) . '</td>';
        $html .= '<td><span class="flow-audit-action">' . htmlspecialchars($row['action']) . '</span></td>';
        $html .= '<td>' . htmlspecialchars($row['target'] ?: '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['ip_address'] ?: '-') . '</td>';
        $html .= '<td>' . htmlspecialchars($row['details'] ?: '-') . '</td>';
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    return $html;
}

function flow_config_handle_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = isset($_POST['action']) ? $_POST['action'] : '';
    $currentUser = flow_auth_current_user();
    $db = flow_auth_connect();

    switch ($action) {
        case 'save_local_asn':
            $asn = trim((string)($_POST['local_asn'] ?? ''));
            if ($asn === '' || !ctype_digit($asn)) {
                flow_auth_set_flash('Informe um ASN numerico valido.', 'error');
            } else {
                flow_write_local_asn($asn);
                flow_auth_audit('config.local_asn.updated', 'ASN local ajustado para AS' . $asn, 'config.inc');
                flow_auth_set_flash('ASN local atualizado com sucesso.', 'success');
            }
            break;

        case 'save_knownlinks':
            $knownlinks = (string)($_POST['knownlinks'] ?? '');
            list($ok, $message) = flow_validate_knownlinks_text($knownlinks);
            if (!$ok) {
                flow_auth_set_flash($message, 'error');
            } else {
                flow_write_knownlinks_text($knownlinks);
                flow_auth_audit('config.knownlinks.updated', 'Arquivo knownlinks salvo pelo painel', 'knownlinks');
                flow_auth_set_flash('Arquivo knownlinks atualizado. Reinicie o coletor para aplicar.', 'success');
            }
            break;

        case 'append_knownlink':
            list($ok, $payload) = flow_validate_knownlink_fields(
                $_POST['exporter_host'] ?? '',
                $_POST['ifindex'] ?? '',
                $_POST['tag'] ?? '',
                $_POST['description'] ?? '',
                $_POST['color'] ?? '',
                $_POST['sampling'] ?? ''
            );
            if (!$ok) {
                flow_auth_set_flash($payload, 'error');
                break;
            }

            $line = implode("\t", array(
                $payload['exporter'],
                $payload['ifindex'],
                $payload['tag'],
                $payload['description'],
                $payload['color'],
                $payload['sampling'],
            ));
            $current = rtrim(flow_read_knownlinks_text());
            $next = $current === '' ? $line : ($current . PHP_EOL . $line);
            flow_write_knownlinks_text($next);
            flow_auth_audit('config.knownlinks.appended', 'Novo link anexado ao knownlinks', $payload['tag']);
            flow_auth_set_flash('Novo link adicionado com sucesso ao knownlinks.', 'success');
            break;

        case 'create_user':
            $username = trim((string)($_POST['username'] ?? ''));
            $role = trim((string)($_POST['role'] ?? 'read'));
            $password = (string)($_POST['password'] ?? '');

            if ($username === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
                flow_auth_set_flash('Usuario invalido. Use apenas letras, numeros, ponto, underline ou hifen.', 'error');
                break;
            }
            if (!in_array($role, flow_auth_roles(), true)) {
                flow_auth_set_flash('Perfil invalido.', 'error');
                break;
            }
            if (!flow_auth_can_manage_target($role)) {
                flow_auth_set_flash('Seu perfil nao pode criar usuarios desse nivel.', 'error');
                break;
            }
            if ($password === '') {
                flow_auth_set_flash('A senha do novo usuario nao pode ficar vazia.', 'error');
                break;
            }

            $stmt = $db->prepare('INSERT INTO users (username, role, password_hash, is_active, created_at, updated_at) VALUES (:username, :role, :password_hash, 1, datetime(\'now\'), datetime(\'now\'))');
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':role', $role, SQLITE3_TEXT);
            $stmt->bindValue(':password_hash', password_hash($password, PASSWORD_DEFAULT), SQLITE3_TEXT);
            @$stmt->execute();
            if ($db->lastErrorCode() !== 0) {
                flow_auth_set_flash('Nao foi possivel criar o usuario: ' . $db->lastErrorMsg(), 'error');
            } else {
                flow_auth_audit('auth.user.created', 'Novo usuario provisionado via painel', $username);
                flow_auth_set_flash('Usuario criado com sucesso.', 'success');
            }
            break;

        case 'reset_password':
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPassword = (string)($_POST['new_password'] ?? '');
            $target = null;
            foreach (flow_auth_all_users() as $userRow) {
                if ((int)$userRow['id'] === $userId) {
                    $target = $userRow;
                    break;
                }
            }
            if (!$target || !flow_auth_can_manage_target($target['role'])) {
                flow_auth_set_flash('Voce nao pode alterar a senha desse usuario.', 'error');
                break;
            }
            if ($newPassword === '') {
                flow_auth_set_flash('A nova senha nao pode ficar vazia.', 'error');
                break;
            }
            $stmt = $db->prepare('UPDATE users SET password_hash = :password_hash, updated_at = datetime(\'now\') WHERE id = :id');
            $stmt->bindValue(':password_hash', password_hash($newPassword, PASSWORD_DEFAULT), SQLITE3_TEXT);
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();
            flow_auth_audit('auth.user.password_reset', 'Senha redefinida por operador autenticado', $target['username']);
            flow_auth_set_flash('Senha atualizada com sucesso.', 'success');
            break;

        case 'toggle_user':
            $userId = (int)($_POST['user_id'] ?? 0);
            $target = null;
            foreach (flow_auth_all_users() as $userRow) {
                if ((int)$userRow['id'] === $userId) {
                    $target = $userRow;
                    break;
                }
            }
            if (!$target || !flow_auth_can_manage_target($target['role'])) {
                flow_auth_set_flash('Voce nao pode alterar esse usuario.', 'error');
                break;
            }
            if ($currentUser && $currentUser['username'] === $target['username']) {
                flow_auth_set_flash('Voce nao pode desativar sua propria conta nesta tela.', 'error');
                break;
            }
            $nextStatus = ((int)$target['is_active'] === 1) ? 0 : 1;
            $stmt = $db->prepare('UPDATE users SET is_active = :is_active, updated_at = datetime(\'now\') WHERE id = :id');
            $stmt->bindValue(':is_active', $nextStatus, SQLITE3_INTEGER);
            $stmt->bindValue(':id', $userId, SQLITE3_INTEGER);
            $stmt->execute();
            flow_auth_audit('auth.user.status_changed', $nextStatus === 1 ? 'Conta reativada' : 'Conta desativada', $target['username']);
            flow_auth_set_flash('Status do usuario atualizado.', 'success');
            break;
    }

    header('Location: config.php');
    exit;
}

function flow_render_flash_message() {
    $flash = flow_auth_take_flash();
    if (!$flash) {
        return '';
    }

    $class = ($flash['type'] === 'error') ? 'flow-inline-alert' : 'flow-inline-success';
    return '<div class="' . $class . '">' . htmlspecialchars($flash['message']) . '</div>';
}

flow_config_handle_post();

$flashHtml = flow_render_flash_message();
$localAsn = flow_read_local_asn();
$knownlinksText = flow_read_knownlinks_text();
$knownlinksEntries = flow_knownlinks_entries();
$users = flow_auth_all_users();
$auditRows = flow_auth_recent_audit_logs(40);
$heroStats = array(
    array('label' => 'Perfil', 'value' => strtoupper(flow_auth_current_user()['role'])),
    array('label' => 'Usuarios', 'value' => count($users)),
    array('label' => 'ASN local', 'value' => $localAsn !== '' ? 'AS' . $localAsn : 'nao definido'),
    array('label' => 'Links', 'value' => count($knownlinksEntries)),
);

$settingsBody = $flashHtml;
$settingsBody .= '<form method="post" class="flow-form-stack">';
$settingsBody .= '<input type="hidden" name="action" value="save_local_asn">';
$settingsBody .= '<label>ASN local do cliente</label>';
$settingsBody .= '<input class="flow-input" type="text" name="local_asn" value="' . htmlspecialchars($localAsn) . '" placeholder="Ex.: 268840">';
$settingsBody .= '<button class="flow-button" type="submit">Salvar configuracao</button>';
$settingsBody .= '</form>';

$knownlinksBody = '<form method="post" class="flow-form-stack">';
$knownlinksBody .= '<input type="hidden" name="action" value="save_knownlinks">';
$knownlinksBody .= '<label>Arquivo knownlinks</label>';
$knownlinksBody .= '<textarea class="flow-input flow-textarea" name="knownlinks">' . htmlspecialchars($knownlinksText) . '</textarea>';
$knownlinksBody .= '<button class="flow-button" type="submit">Salvar knownlinks</button>';
$knownlinksBody .= '<span class="flow-search-hint">Use TAB entre as 6 colunas. A tela agora valida exporter, ifIndex, TAG, descricao, cor e sampling antes de salvar.</span>';
$knownlinksBody .= '</form>';

$appendKnownlinkBody = '<form method="post" class="flow-form-stack">';
$appendKnownlinkBody .= '<input type="hidden" name="action" value="append_knownlink">';
$appendKnownlinkBody .= '<label>Novo roteador / link no knownlinks</label>';
$appendKnownlinkBody .= '<div class="flow-inline-form">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="exporter_host" placeholder="IP ou hostname do exportador">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="ifindex" placeholder="ifIndex">';
$appendKnownlinkBody .= '</div>';
$appendKnownlinkBody .= '<div class="flow-inline-form">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="tag" placeholder="TAG ex.: VLANIF2984">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="description" placeholder="Descricao do link">';
$appendKnownlinkBody .= '</div>';
$appendKnownlinkBody .= '<div class="flow-inline-form">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="color" placeholder="Cor HEX ex.: 33A02C">';
$appendKnownlinkBody .= '<input class="flow-input" type="text" name="sampling" value="128" placeholder="Sampling">';
$appendKnownlinkBody .= '<button class="flow-button" type="submit">Adicionar ao knownlinks</button>';
$appendKnownlinkBody .= '</div>';
$appendKnownlinkBody .= '<span class="flow-search-hint">Esse formulario monta a linha com TAB automaticamente e evita duplicidade de TAG.</span>';
$appendKnownlinkBody .= '</form>';

if (!empty($knownlinksEntries)) {
    $appendKnownlinkBody .= '<div class="flow-table-wrap"><table class="flow-table"><thead><tr><th>Exportador</th><th>IfIndex</th><th>TAG</th><th>Descricao</th><th>Cor</th><th>Sampling</th></tr></thead><tbody>';
    foreach ($knownlinksEntries as $entry) {
        $appendKnownlinkBody .= '<tr>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['exporter']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['ifindex']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['tag']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['description']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['color']) . '</td>';
        $appendKnownlinkBody .= '<td>' . htmlspecialchars($entry['sampling']) . '</td>';
        $appendKnownlinkBody .= '</tr>';
    }
    $appendKnownlinkBody .= '</tbody></table></div>';
}

$usersBody = '<div class="flow-user-admin">';
$usersBody .= '<div class="flow-table-wrap"><table class="flow-table"><thead><tr><th>Usuario</th><th>Perfil</th><th>Status</th><th>Ultimo login</th><th>Acoes</th></tr></thead><tbody>';
foreach ($users as $userRow) {
    $usersBody .= '<tr>';
    $usersBody .= '<td>' . htmlspecialchars($userRow['username']) . '</td>';
    $usersBody .= '<td>' . htmlspecialchars(strtoupper($userRow['role'])) . '</td>';
    $usersBody .= '<td>' . ((int)$userRow['is_active'] === 1 ? 'ativo' : 'inativo') . '</td>';
    $usersBody .= '<td>' . htmlspecialchars($userRow['last_login_at'] ?: 'nunca') . '</td>';
    $usersBody .= '<td class="flow-user-actions">';
    if (flow_auth_can_manage_target($userRow['role'])) {
        $usersBody .= '<form method="post" class="flow-inline-form flow-user-form">';
        $usersBody .= '<input type="hidden" name="action" value="reset_password">';
        $usersBody .= '<input type="hidden" name="user_id" value="' . (int)$userRow['id'] . '">';
        $usersBody .= '<input class="flow-input" type="password" name="new_password" placeholder="Nova senha">';
        $usersBody .= '<button class="flow-button flow-button-ghost" type="submit">Trocar senha</button>';
        $usersBody .= '</form>';
        $usersBody .= '<form method="post" class="flow-inline-form flow-user-form">';
        $usersBody .= '<input type="hidden" name="action" value="toggle_user">';
        $usersBody .= '<input type="hidden" name="user_id" value="' . (int)$userRow['id'] . '">';
        $usersBody .= '<button class="flow-button flow-button-ghost" type="submit">' . ((int)$userRow['is_active'] === 1 ? 'Desativar' : 'Ativar') . '</button>';
        $usersBody .= '</form>';
    } else {
        $usersBody .= '<span class="flow-search-hint">Gerenciado apenas por master</span>';
    }
    $usersBody .= '</td></tr>';
}
$usersBody .= '</tbody></table></div>';

$usersBody .= '<form method="post" class="flow-form-stack flow-user-create">';
$usersBody .= '<input type="hidden" name="action" value="create_user">';
$usersBody .= '<label>Novo usuario</label>';
$usersBody .= '<div class="flow-inline-form">';
$usersBody .= '<input class="flow-input" type="text" name="username" placeholder="usuario">';
$usersBody .= '<select class="flow-input" name="role">';
foreach (flow_auth_roles() as $role) {
    if (!flow_auth_can_manage_target($role)) {
        continue;
    }
    $usersBody .= '<option value="' . htmlspecialchars($role) . '">' . htmlspecialchars(strtoupper($role)) . '</option>';
}
$usersBody .= '</select>';
$usersBody .= '<input class="flow-input" type="password" name="password" placeholder="senha inicial">';
$usersBody .= '<button class="flow-button" type="submit">Criar usuario</button>';
$usersBody .= '</div>';
$usersBody .= '</form>';
$usersBody .= '</div>';

$auditBody = flow_render_audit_table($auditRows);

flow_render_shell_start('Flow | Config', 'config');
echo flow_render_hero('config center', 'Administracao do Flow', 'Gerencie autenticacao, configuracoes locais e o arquivo knownlinks da plataforma.', $heroStats);
echo '<div class="flow-grid">';
echo '<div class="flow-stack">';
echo flow_render_panel('Parametros locais', $settingsBody, 'fa-sliders');
echo flow_render_panel('Controle de acesso', $usersBody, 'fa-lock');
echo flow_render_panel('Trilha de auditoria', $auditBody, 'fa-shield');
echo '</div>';
echo '<div class="flow-stack">';
echo flow_render_panel('Adicionar roteador / link', $appendKnownlinkBody, 'fa-plus-circle');
echo flow_render_panel('Editor de knownlinks', $knownlinksBody, 'fa-code');
echo '</div>';
echo '</div>';
flow_render_shell_end();

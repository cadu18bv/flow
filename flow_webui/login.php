<?php
require_once("auth.php");

if (flow_auth_is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$next = isset($_GET['next']) ? $_GET['next'] : 'index.php';
$next = (preg_match('/^(https?:)?\/\//i', $next) || strpos($next, 'login.php') !== false) ? 'index.php' : $next;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
    $next = isset($_POST['next']) ? $_POST['next'] : 'index.php';
    $next = (preg_match('/^(https?:)?\/\//i', $next) || strpos($next, 'login.php') !== false) ? 'index.php' : $next;

    if (!file_exists(flow_auth_db_path())) {
        $error = 'Base de autenticacao nao encontrada. Inicialize o acesso pelo instalador.';
    } elseif (flow_auth_login($username, $password)) {
        $currentUser = flow_auth_current_user();
        $currentRole = (is_array($currentUser) && isset($currentUser['role'])) ? $currentUser['role'] : 'authenticated';
        flow_auth_audit('auth.login.success', 'Login concluido com redirecionamento para ' . $next, $username, $username, $currentRole);
        header('Location: ' . ($next !== '' ? $next : 'index.php'));
        exit;
    } else {
        flow_auth_audit('auth.login.failure', 'Tentativa de login invalida', $username, $username !== '' ? $username : 'anonimo', 'anonymous');
        $error = 'Usuario ou senha invalidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
  <meta name="application-name" content="CECTI Flow Observatory">
  <meta name="description" content="Acesso administrativo da plataforma CECTI Flow Observatory.">
  <meta name="theme-color" content="#07111f">
  <title>Flow | Login</title>
  <link rel="icon" href="favicon.svg" type="image/svg+xml">
  <link rel="shortcut icon" href="favicon.svg" type="image/svg+xml">
  <link rel="manifest" href="site.webmanifest">
  <link rel="stylesheet" href="bootstrap/css/bootstrap.min.css">
  <link rel="stylesheet" href="plugins/font-awesome/font-awesome.min.css">
  <link rel="stylesheet" href="css/custom.css">
</head>
<body class="flow-body flow-login-body">
  <main class="flow-login-shell">
    <section class="flow-login-card">
      <div class="flow-login-brand">
        <span class="flow-brand-mark">FLOW</span>
        <div class="flow-brand-copy">
          <strong>CECTI Flow Observatory</strong>
          <span>Acesso administrativo da plataforma</span>
        </div>
      </div>
      <h1>Entrar</h1>
      <p>Use suas credenciais para acessar o painel de observabilidade.</p>
      <?php if ($error !== ''): ?>
        <div class="flow-inline-alert"><?php echo htmlspecialchars($error); ?></div>
      <?php endif; ?>
      <form method="post" class="flow-form-stack">
        <input type="hidden" name="next" value="<?php echo htmlspecialchars($next); ?>">
        <label>Usuario</label>
        <input class="flow-input flow-input-xl" type="text" name="username" autocomplete="username" required>
        <label>Senha</label>
        <input class="flow-input flow-input-xl" type="password" name="password" autocomplete="current-password" required>
        <button class="flow-button flow-button-xl" type="submit">Acessar</button>
      </form>
    </section>
  </main>
</body>
</html>

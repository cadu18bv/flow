<?php
require_once("auth.php");

flow_auth_audit('auth.logout', 'Sessao encerrada pelo usuario');
flow_auth_logout();
header('Location: login.php');
exit;

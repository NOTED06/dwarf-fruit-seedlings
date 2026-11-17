<?php
ini_set('session.gc_maxlifetime', 86400 * 30);
session_set_cookie_params(86400 * 30);
session_start();
session_unset();
session_destroy();
header('Location: index.php');
exit;
?>

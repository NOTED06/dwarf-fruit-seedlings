<?php

$serverHost = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
$isLocal = in_array($serverHost, ['localhost', '127.0.0.1', '::1'], true)
    || strpos($serverHost, 'localhost:') === 0
    || strpos($serverHost, '127.0.0.1:') === 0
    || (php_sapi_name() === 'cli' && empty(getenv('INFINITYFREE_HOST')));

if ($isLocal) {
    
    $sname    = "localhost";
    $uname    = "root";
    $password = "";
    $db_name  = "zians";
} else {
    
    $sname    = "sql212.infinityfree.com";
    $uname    = "if0_42846382";
    $password = "IMiDXNc42h0";
    $db_name  = "if0_42846382_zians";
}

mysqli_report(MYSQLI_REPORT_OFF);

$conn = false;
$dbError = '';

try {
    $conn = @mysqli_connect($sname, $uname, $password, $db_name);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

if (!$conn && !$isLocal) {
    $altPassword = (substr($password, -1) === '0') ? substr_replace($password, 'O', -1) : (substr($password, -1) === 'O' ? substr_replace($password, '0', -1) : '');
    if (!empty($altPassword)) {
        try {
            $conn = @mysqli_connect($sname, $uname, $altPassword, $db_name);
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }
    }
}

if (!$conn) {
    $errMsg = !empty($dbError) ? $dbError : mysqli_connect_error();
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_POST['action']))
        || (!empty($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $errMsg]);
        exit;
    }
    die("Database connection failed: " . $errMsg);
}

mysqli_set_charset($conn, "utf8mb4");
?>

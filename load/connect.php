<?php

include("config.php");

// Credentials
$sql_user = SQL_USERNAME;
$sql_password = SQL_PASSWORD;
$sql_host = SQL_HOST;
$sql_dbname = SQL_DATABASE;

// -------------------

// Establish Connection (PDO)
$conn_pdo = new PDO("mysql:host=$sql_host;dbname=$sql_dbname", $sql_user, $sql_password);
$conn_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Establish Connection (SQLI)
$conn_sqli = mysqli_connect($sql_host, $sql_user, $sql_password, $sql_dbname);

if (!$conn_sqli) {
    die('Connection failed: ' . mysqli_connect_error());
}

?>
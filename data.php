<?php
/**
 * data.php - Redirect to sheet.php
 * 
 * This file has been deprecated. The data sheet functionality
 * is now consolidated in sheet.php.
 * 
 * @deprecated Use sheet.php instead
 */
header("Location: sheet.php?" . $_SERVER['QUERY_STRING']);
exit;

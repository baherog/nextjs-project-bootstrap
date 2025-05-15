<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');  // Change to your Issabel database user
define('DB_PASS', '');      // Change to your Issabel database password
define('DB_NAME', 'asteriskcdrdb');

// Error Reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('UTC');

// CORS Headers
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Session Configuration
session_start();

// Asterisk Manager Interface Configuration
define('AMI_HOST', 'localhost');
define('AMI_PORT', '5038');
define('AMI_USERNAME', 'admin');  // Change to your AMI username
define('AMI_SECRET', '');         // Change to your AMI password

// Report Configuration
define('SLA_THRESHOLD', 20);      // Service Level Agreement threshold in seconds
define('REFRESH_INTERVAL', 5);    // Real-time refresh interval in seconds
define('MAX_RECORDS', 10000);     // Maximum records to fetch in one query

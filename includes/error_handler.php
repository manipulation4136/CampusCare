<?php
// includes/error_handler.php

// 1. Hide raw errors from the user
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Optional: Set log file location if needed, otherwise uses default php_error.log
// ini_set('error_log', __DIR__ . '/../logs/app_errors.log');

// 2. Custom Exception Handler (Catch Defaults & Fatal Errors)
function customExceptionHandler($e) {
    // Log the detailed error for the developer
    error_log("🔥 Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    
    // Redirect user to the friendly error page
    if (!headers_sent()) {
        header("Location: " . BASE_URL . "views/error.php");
    } else {
        // Fallback if headers already sent
        echo "<script>window.location.href='" . BASE_URL . "views/error.php';</script>";
        exit;
    }
    exit;
}

// 3. Custom Error Handler (Catch Warnings/Notices)
function customErrorHandler($errno, $errstr, $errfile, $errline) {
    // Ignore suppressed errors (using @)
    if (!(error_reporting() & $errno)) {
        return false;
    }

    // Log it
    error_log("⚠️ Error [$errno]: $errstr in $errfile on line $errline");

    // For critical errors, we might want to stop execution
    switch ($errno) {
        case E_USER_ERROR:
            // Log and Redirect
             if (!headers_sent()) {
                header("Location: " . BASE_URL . "views/error.php");
                 exit;
            }
            break;
            
        case E_USER_WARNING:
        case E_WARNING:
        case E_NOTICE:
        case E_USER_NOTICE:
            // Just log and continue (don't break the app for minor warnings)
            return true; 
            break;

        default:
            return false;
    }
    return true;
}

// Register Handlers
set_exception_handler('customExceptionHandler');
set_error_handler('customErrorHandler');
?>

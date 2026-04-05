<?php
// Set the content type and return the contents of a static file
// eg $uri = "/demo/css/style.css"
// content type => text/css
// require __DIR__ . "/css/style.css"

// Remove the "/demo" from the URI and the query string
$path = str_replace("/demo", "", strtok($_SERVER["REQUEST_URI"], "?"));

// Security: Resolve the real path and ensure it stays within the demo directory
$resolvedPath = realpath(__DIR__ . $path);
$demoDir = realpath(__DIR__);
if ($resolvedPath === false || strpos($resolvedPath, $demoDir) !== 0) {
    http_response_code(404);
    exit("Not found");
}

// Only serve static files, not PHP files (except preview.php)
$extension = pathinfo($resolvedPath, PATHINFO_EXTENSION);
if ($extension === "php" && basename($resolvedPath) !== "preview.php") {
    http_response_code(403);
    exit("Forbidden");
}

// Set the content type based on the file extension
switch ($extension) {
    case "css":
        header("Content-Type: text/css");
        break;
    case "js":
        header("Content-Type: text/javascript");
        break;
    case "svg":
        header("Content-Type: image/svg+xml");
        break;
    case "png":
        header("Content-Type: image/png");
        break;
    case "php":
        // For PHP files, include them (preview.php)
        include $resolvedPath;
        exit;
    default:
        break;
}

// Return the contents of the file
readfile($resolvedPath);

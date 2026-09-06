<?php
// Set the content type and return the contents of a static file
// eg $uri = "/demo/css/style.css"
// content type => text/css
// require __DIR__ . "/css/style.css"

/** @var Closure(): never $notFound */
$notFound = static function (): never {
    http_response_code(404);
    exit("Not found");
};

$allowedAssets = [
    "apple-touch-icon.png",
    "favicon-16x16.png",
    "favicon-32x32.png",
    "icon.svg",
    "css/style.css",
    "css/toggle-dark.css",
    "js/accordion.js",
    "js/jscolor.min.js",
    "js/script.js",
    "js/toggle-dark.js",
];

// Vercel passes the captured path as an internal query parameter. The URI
// fallback keeps this helper compatible with self-hosted routing and tests.
$path = $_GET["__demo_path"] ?? null;
if ($path !== null) {
    unset($_GET["__demo_path"]);
}
if ($path === null) {
    $requestUri = $_SERVER["REQUEST_URI"] ?? "";
    $requestPath = is_string($requestUri) ? parse_url($requestUri, PHP_URL_PATH) : false;
    if (!is_string($requestPath) || !str_starts_with($requestPath, "/demo/")) {
        $notFound();
    }
    $path = substr($requestPath, strlen("/demo/"));
}
if (!is_string($path) || $path === "" || str_contains($path, "\0")) {
    $notFound();
}
$segments = preg_split("~[\\\\/]~", $path);
if ($segments === false || in_array("..", $segments, true)) {
    $notFound();
}

// Security: Resolve the real path and ensure it stays within the demo directory
$resolvedPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace("/", DIRECTORY_SEPARATOR, $path));
$demoDir = realpath(__DIR__);
if (!is_string($resolvedPath) || !is_string($demoDir)) {
    $notFound();
}
$demoPrefix = rtrim($demoDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$pathIsContained =
    strncmp($resolvedPath, $demoPrefix, strlen($demoPrefix)) === 0 ||
    (DIRECTORY_SEPARATOR === "\\" && strncasecmp($resolvedPath, $demoPrefix, strlen($demoPrefix)) === 0);
if (!$pathIsContained) {
    $notFound();
}

$canonicalPath = str_replace("\\", "/", substr($resolvedPath, strlen($demoPrefix)));

$extension = pathinfo($resolvedPath, PATHINFO_EXTENSION);
if ($extension === "php" && $canonicalPath !== "preview.php") {
    http_response_code(403);
    exit("Forbidden");
}
if ($canonicalPath !== "preview.php" && !in_array($canonicalPath, $allowedAssets, true)) {
    $notFound();
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
    case "json":
        header("Content-Type: application/json");
        break;
    case "php":
        // The only executable demo path is the explicitly approved preview.
        include $resolvedPath;
        exit();
    default:
        header("Content-Type: application/octet-stream");
        break;
}

// Return the contents of the file
readfile($resolvedPath);

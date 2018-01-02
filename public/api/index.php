<?php

// Comment if you don't want to allow posts from other domains
header('Access-Control-Allow-Origin: *');

// Allow the following methods to access this file
header('Access-Control-Allow-Methods: OPTIONS, GET, DELETE, POST');

// Load the FilePond helper class
require_once('FilePond/RequestHandler.class.php');

// catch server exceptions and auto jump to 500 response code if caught
FilePond\RequestHandler::catchExceptions();


/**
 * Route by request method
 */
switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET': handleGET(); break;
    case 'POST': handlePOST(); break;
    case 'DELETE': handleDELETE(); break;
}


/**
 * Routes, "fetch", "restore" and "load" requests to the matching functions
 */
function handleGET() {
    $handlers = array(
        'fetch' => 'handleFetch',
        'restore' => 'handleRestore',
        'load' => 'handleLoad'
    );
    foreach ($handlers as $param => $handler) {
        if (isset($_GET[$param])) {
            call_user_func($handler, $_GET[$param]);
        }
    }
}

/**
 * Handle loading of already saved files
 */
function handleLoad($id) {

    // Stop here if no id supplied
    if (empty($id)) {

        // Nope, Bad Request
        http_response_code(400);
        return;
    }

    // 
    // In this example implementation the file id is simply the filename and 
    // we request the file from the uploads folder, it could very well be 
    // that the file should be fetched from a database or other system.
    //

    // Let's get the temp file content
    $file = FilePond\RequestHandler::getFile($id, 'uploads');

    // Server error while reading the file
    if ($file === null) {

        // Nope, Bail out
        http_response_code(500);
        return;
    }

    // Return file
    // Allow to read Content Disposition (so we can read the file name on the client side)
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Content-Type: ' . $file['type']);
    header('Content-Length: ' . $file['length']);
    header('Content-Disposition: inline; filename="' . $file['name'] . '"');
    echo $file['content'];
}


/**
 * Handle restoring of temporary files
 */
function handleRestore($id) {

    // Stop here if no id supplied
    if (empty($id)) {

        // Nope, Bad Request
        http_response_code(400);
        return;
    }

    // Is this a valid id (should be same regex as client)
    if (!FilePond\RequestHandler::isFileId($id)) {

        // Nope, Bad Request
        http_response_code(400);
        return;
    }

    // Let's get the temp file content
    $file = FilePond\RequestHandler::getTempFile($id);

    // No file returned, file probably not found
    if ($file === false) {

        // Nope, File not found
        http_response_code(404);
        return;
    }

    // Server error while reading the file
    if ($file === null) {

        // Nope, Bail out
        http_response_code(500);
        return;
    }

    // Return file
    // Allow to read Content Disposition (so we can read the file name on the client side)
    header('Access-Control-Expose-Headers: Content-Disposition');
    header('Content-Type: ' . $file['type']);
    header('Content-Length: ' . $file['length']);
    header('Content-Disposition: inline; filename="' . $file['name'] . '"');
    echo $file['content'];
}



/**
 * Fetches data from a remote URL and returns it to the client
 */
function handleFetch($url) {

    // Stop here if no data supplied
    if (empty($url)) {

        // Nope, Bad Request
        http_response_code(400);
        return;
    }

    // Is this a valid url
    if (!FilePond\RequestHandler::isURL($url)) {

        // Nope, Bad Request
        http_response_code(400);
        return;
    }

    // Let's get the remote file content
    $response = FilePond\RequestHandler::getRemoteURLData($url);

    // Something went wrong
    if ($response === null) {

        // Nope, Probably a problem while fetching the resource
        http_response_code(500);
        return;
    }

    // remote server returned invalid response
    if (!$response['success']) {

        // Clone response code and communicate to client
        http_response_code($response['code']);
        return;
    }
    
    // Return file
    header('Content-Type: ' . $response['type']);
    header('Content-Length: ' . $response['length']);
    echo $response['content'];
}




/**
 * Uploads a new file, file contents is supplied as POST body
 */
function handlePOST() {

    // Get submitted field data item, will always be one item in case of async upload
    $items = FilePond\RequestHandler::loadFilesByField('filepond');

    // If no items, exit
    if (count($items) === 0) {

        // Something went wrong, most likely a field name mismatch
        http_response_code(400);
        return;
    }

    // Returns plain text content
    header('Content-Type: text/plain');

    // Remove item from array Response contains uploaded file server id
    echo array_shift($items)->getId();
}



/**
 * Removes a temp file, temp file id is supplied as DELETE request body
 */
function handleDELETE() {

    $id = file_get_contents('php://input');

    // test if id was supplied
    if (!isset($id)) {
        
        // Nope, Bad Request
        http_response_code(400);
        return;
    }

    // Find the file and remove it from the server
    $success = FilePond\RequestHandler::deleteTempFile($id);

    // will always return success, client has no use for failure state

    // no content to return
    http_response_code(204);
}
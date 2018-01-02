<?php

namespace FilePond;


require_once('Item.class.php');

/**
 * FilePond RequestHandler helper class
 */

/*
1. get files (from $files and $post)
2. store files in tmp/ directory and give them a unique server id
3. return server id's to client
4. either client reverts upload or finalizes form
5. call revert($server_id) to remove file from tmp/ directory
6. call save() to save file to final directory
*/
class RequestHandler
{
    // the default location to save tmp files to
    public static $tmp_dir = 'tmp' . DIRECTORY_SEPARATOR;

    // regex to use for testing if a string is a file id
    public static $file_id_format = '/^[0-9a-fA-F]{32}$/';

    /**
     * @param $str
     * @return bool
     */
    public static function isFileId($str) {
        return preg_match(self::$file_id_format, $str);
    }

    /**
     * @param $str
     * @return bool
     */
    public static function isURL($str) {
        return filter_var($str, FILTER_VALIDATE_URL);
    }

    /**
     * Catch all exceptions so we can return a 500 error when the server bugs out
     */
    public static function catchExceptions() {
        set_exception_handler('FilePond\RequestHandler::handleException');
    }

    public static function handleException($ex) {

        // write to error log so we can still find out what's up
        error_log('Uncaught exception in class="' . get_class($ex) . '" message="' . $ex->getMessage() . '" line="' . $ex->getLine() . '"');
        
        // clean up buffer
        ob_end_clean();

        // server error mode go!
        http_response_code(500);
    }

    private static function createItem($args) {
        return new namespace\Item($args);
    }

    /**
     * @param $fieldName
     * @return array
     */
    public static function loadFilesByField($fieldName) {

        // See if files are posted as JSON string (each file being base64 encoded)
        $base64Items = self::loadBase64FormattedFiles($fieldName);
        
        // retrieves posted file objects
        $fileItems = self::loadFileObjects($fieldName);
        
        // retrieves files already on server
        $tmpItems = self::loadFilesFromTemp($fieldName);
        
        // save newly received files to temp files folder (tmp items already are in that folder)
        self::saveAsTempFiles(array_merge($base64Items, $fileItems));
        
        // return items
        return array_merge($base64Items, $fileItems, $tmpItems);
    }

    private static function loadFileObjects($fieldName) {
        
        $items = [];

        if ( !isset($_FILES[$fieldName]) ) {
            return $items;
        }
        
        $FILE = $_FILES[$fieldName];

        if (is_array($FILE['tmp_name'])) {

            foreach( $FILE['tmp_name'] as $index => $tmpName ) {

                array_push( $items, self::createItem( array(
                    'tmp_name' => $FILE['tmp_name'][$index],
                    'name' => $FILE['name'][$index],
                    'size' => $FILE['size'][$index],
                    'error' => $FILE['error'][$index],
                    'type' => $FILE['type'][$index]
                )) );

            }

        }
        else {
            array_push( $items, self::createItem($FILE) );
        }

        return $items;
    }

    private static function loadBase64FormattedFiles($fieldName) {

        /*
        // format:
        {
            "id": "iuhv2cpsu",
            "name": "picture.jpg",
            "type": "image/jpeg",
            "size": 20636,
            "data": "/9j/4AAQSkZJRgABAQEASABIAA..."
        }
        */

        $items = [];

        if ( !isset($_POST[$fieldName] ) ) {
            return $items;
        }

        // Handle posted files array
        $values = $_POST[$fieldName];

        // Turn values in array if is submitted as single value
        if (!is_array($values)) {
            $values = isset($values) ? array($values) : array();
        }

        // If files are found, turn base64 strings into actual file objects
        foreach ($values as $value) {
            $obj = @json_decode($value);
            // skip values that failed to be decoded
            if (!isset($obj)) {
                continue;
            }
            array_push($items, self::createItem( self::createTempFile($obj) ) );
        }
        
        return $items;
    }

    private static function loadFilesFromTemp($fieldName) {
        
        $items = [];

        if ( !isset($_POST[$fieldName] ) ) {
            return $items;
        }

        // Handle posted ids array
        $values = $_POST[$fieldName];

        // Turn values in array if is submitted as single value
        if (!is_array($values)) {
            $values = isset($values) ? array($values) : array();
        }

        // test if value is actually a file id
        foreach ($values as $value) {
            if ( self::isFileId($value) ) {
                array_push($items, $value);
            }
        }
    
        return $items;

    }

    public static function save($files, $path = 'uploads' . DIRECTORY_SEPARATOR) {

        // is list of files
        if ( is_array($files) ) {
            $results = [];
            foreach($files as $file) {
                array_push($results, self::saveFile($file, $path));
            }
            return $results;
        }

        // is single item
        return self::saveFile($files, $path);
    }

    /**
     * @param $file_id
     * @return bool
     */
    public static function deleteTempFile($file_id) {
        return self::deleteTempDirectory($file_id);
    }

    /**
     * @param $url
     * @return array|bool
     */
    public static function getRemoteURLData($url)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            $content = curl_exec($ch);
            if ($content === FALSE) {
                throw new Exception(curl_error($ch), curl_errno($ch));
            }

            $type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $length = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close ($ch);

            $success = $code >= 200 && $code < 300;

            return array(
                'code' => $code,
                'content' => $content,
                'type' => $type,
                'length' => $length,
                'success' => $success
            );
            
        }
        catch(Exception $e) {
            return null;
        }
    }

    private static function saveAsTempFiles($items) {
        foreach($items as $item) {
            self::saveTempFile($item);
        }
    }

    private static function saveTempFile($file) {

        // make sure path name is safe
        $path = self::getSecureTempPath() . $file->getId() . DIRECTORY_SEPARATOR;

        // Creates a secure temporary directory to store the files in
        self::createSecureDirectory($path);

        // get source and target values
        $source = $file->getFilename();
        $target = $path . $file->getName();

        // Move uploaded file to this new secure directory
        $result = self::moveFile($source, $target);

        // Was not saved
        if ($result !== true) { return $result; }

        // Make sure file is secure
        self::setSecureFilePermissions($target);

        // temp file stored successfully
        return true;
    }

    public static function getTempFile($fileId) {

        // select all files in directory except .htaccess
        foreach(glob(self::getSecureTempPath() . $fileId . DIRECTORY_SEPARATOR . '*.*') as $file) {

            try {
                
                $handle = fopen($file, 'r');
                $content = fread($handle, filesize($file));
                fclose($handle);
                
                return array(
                    'name' => basename($file),
                    'content' => $content,
                    'type' => mime_content_type($file),
                    'length' => filesize($file)
                );

            }
            catch(Exception $e) {
                return null;
            }
        }

        return false;
    }

    public static function getFile($file, $path) {

        try {
            
            $filename = $path . DIRECTORY_SEPARATOR . $file;
            $handle = fopen($filename, 'r');
            $content = fread($handle, filesize($filename));
            fclose($handle);
            
            return array(
                'name' => basename($filename),
                'content' => $content,
                'type' => mime_content_type($filename),
                'length' => filesize($filename)
            );

        }
        catch(Exception $e) {
            return null;
        }

    }

    private static function saveFile($file, $path) {

        // nope
        if (!isset($file)) {
            return false;
        }

        // if is file id
        if (is_string($file)) {
            return self::moveFileById($file, $path);
        }

        // is file object
        else {
            return self::moveFileById($file->getId(), $path);
        }

    }

    private static function moveFileById($fileId, $path) {

        // select all files in directory except .htaccess
        foreach(glob(self::getSecureTempPath() . $fileId . DIRECTORY_SEPARATOR . '*.*') as $file) {

            $source = $file;
            $target = self::getSecurePath($path);

            self::createDirectory($target);

            rename($source, $target . basename($file));
        }

        // remove directory
        self::deleteTempDirectory($fileId);

        // done!
        return true;
    }

    private static function deleteTempDirectory($id) {

        @array_map('unlink', glob(self::getSecureTempPath() . $id . DIRECTORY_SEPARATOR . '{.,}*', GLOB_BRACE));

        // remove temp directory
        @rmdir(self::getSecureTempPath() . $id);

    }

    private static function createTempFile($file) {

        $tmp = tmpfile();
        fwrite($tmp, base64_decode($file->data));
        $meta = stream_get_meta_data($tmp);
        $filename = $meta['uri'];

        return array(
            'error' => 0,
            'size' => filesize($filename),
            'type' => $file->type,
            'name' => $file->name,
            'tmp_name' => $filename,
            'tmp' => $tmp
        );

    }

    private static function moveFile($source, $target) {

        if (is_uploaded_file($source)) {
            return move_uploaded_file($source, $target);
        }
        else {
            $tmp = fopen($source, 'r');
            $result = file_put_contents( $target, fread($tmp, filesize($source) ) );
            fclose($tmp);
            return $result;
        }

    }

    private static function getSecurePath($path) {
        return pathinfo($path)['dirname'] . DIRECTORY_SEPARATOR . basename($path) . DIRECTORY_SEPARATOR;
    }

    private static function getSecureTempPath() {
        return self::getSecurePath(self::$tmp_dir);
    }

    private static function setSecureFilePermissions($target) {
        $stat  = stat( dirname($target) );
        $perms = $stat['mode'] & 0000666;
        @chmod($target, $perms);
    }

    private static function createDirectory($path) {
        if (is_dir($path)) {
            return false;
        }
        mkdir($path, 0755, true);
        return true;
    }

    private static function createSecureDirectory($path) {

        // !! If directory already exists we assume security is handled !!

        // Test if directory already exists and correct
        if (self::createDirectory($path)) {

            // Add .htaccess file for security purposes
            $content = '# Don\'t list directory contents
IndexIgnore *
# Disable script execution
AddHandler cgi-script .php .pl .jsp .asp .sh .cgi
Options -ExecCGI -Indexes';
            file_put_contents($path . '.htaccess', $content);

        }

    }

}



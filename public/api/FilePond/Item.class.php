<?php

namespace FilePond;

/**
 * A wrapper class for easier access to $_FILES object
 */

class Item {

    // counter that helps in ensuring each file receives a truly unique id
    public static $item_counter = 0;

    // item props
    private $id;
    private $file;
    private $name;

    public function __construct($file) {
        $this->id = md5( uniqid(self::$item_counter++, true) );
        $this->file = $file;
        $this->name = $file['name'];
    }

    public function rename($name, $extension) {
        $info = pathinfo($this->name);
        $this->name = $name . '.' . ( isset($extension) ? $extension : $info['extension'] );
    }

    public function getId() {
        return $this->id;
    }

    public function getFilename() {
        return $this->file['tmp_name'];
    }

    public function getName() {
        return basename($this->name);
    }

    public function getSize() {
        return $this->file['size'];
    }

    public function getType() {
        return $this->file['mime'];
    }
}


<?php

// Comment if you don't want to allow posts from other domains
header('Access-Control-Allow-Origin: *');

// Allow the following methods to access this file
header('Access-Control-Allow-Methods: POST');

// Load the FilePond helper class
require_once('FilePond/RequestHandler.class.php');

// catch server exceptions and auto jump to 500 response code if caught
FilePond\RequestHandler::catchExceptions();

// Get submitted field data items, pass input field name along
$items = FilePond\RequestHandler::loadFilesByField('filepond');

// Items will always be an array as multiple files could be submitted
$result = FilePond\RequestHandler::save($items, 'uploads');

foreach( $result as $key=>$value)
{
	if($value == 1) // The files which have been moved successfully. 
	{
		// The name of the file that can be used to save in database etc. 
		echo $items[$key]->getName(); // 				
	}	
}

<?php

use ZipStream\DeflateStream;
use ZipStream\File;

error_reporting(E_ALL | E_STRICT);
ini_set('error_reporting', E_ALL | E_STRICT);
ini_set('display_errors', 1);
$ZIP1 = "https://www.wellenvogel.net/software/avnav/downloads/release/20210115/avnav-20210115.zip";
$ZIP2="https://raw.githubusercontent.com/wellenvogel/simple-cpp-threads/master/SimpleThread.h";
require '../vendor/autoload.php';

//print("Hello World");
$outFile = "ZipMerge.test1.zip";
$zipMerge = new \ZipMerge\Zip\Stream\ZipMerge($outFile);
try {
    $fp = fopen($ZIP1, 'r', false);
    $zipMerge->appendZip($fp,$ZIP1);
    fclose($fp);
    $fp = fopen($ZIP2, 'r', false);
    $zipMerge->appendZip($fp,$ZIP2);
    fclose($fp);
}catch (\Exception $e){
    $zipMerge->writeError($e->getMessage());
}

/*
$handle = fopen("ZipStreamExample1.zip", 'r');
$zipMerge->appendZip($handle, "ZipStreamExample1.zip");
fclose($handle);
*/
$zipMerge->finalize();
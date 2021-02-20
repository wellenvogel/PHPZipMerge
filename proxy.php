<?php
require 'vendor/autoload.php';
function error($text,$error=404){
    http_response_code($error);
    print("Error: $error ".$text);
    die();
}
function getParam($name,$required=true){
    if (! isset($_REQUEST[$name])){
        if ($required){
            error("missing parameter $name",400);
        }
        return null;
    }
    return $_REQUEST[$name];
}

function getRemoteInfo($url,$raise=true){
    $TIME_HEADER='Last-Modified';
    $context  = stream_context_create(array('http' =>array('method'=>'HEAD')));
    $headers=get_headers($url,1,$context);
    if ($headers === false || ! isset($headers[0])
        || ! preg_match('/200/',$headers[0])){
        if ($raise) {
            throw new \Exception("unable to open url $url");
        }
        else{
            return false;
        }
    }
    if (isset($headers[$TIME_HEADER])){
        return strtotime($headers[$TIME_HEADER]);
    }
    return time();
}

$zip=getParam('zipfile');
if (! preg_match('/^https*[:]/',$zip)){
    error("invalid zipfile $zip");
}
$outname=getParam('outname');
$add=getParam('file');
if (! preg_match('/^https*[:]/',$add)){
    error("invalid file $add");
}
$addName=getParam('filename');
$ziptime=null;
$addtime=null;
try{
    $ziptime=getRemoteInfo($zip);
    $addtime=getRemoteInfo($add);
}catch (\Exception $e){
    error($e->getMessage());
}
$stream=new \ZipMerge\Zip\Stream\ZipMerge($outname);
try{
    $fp=fopen($zip,'rb');
    $stream->appendZip($fp,$zip);
    fclose($fp);
    $fp=fopen($add,'rb');
    $stream->addStream($addName,$addtime,$fp);
    fclose($fp);
} catch(\Exception $e){
    $stream->writeError($e->getMessage());
}
$stream->finalize();

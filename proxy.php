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
    $fp=fopen($url,'rb',false,$context);

    $headers=stream_get_meta_data($fp);
    fclose($fp);
    $error=null;
    if ($headers === null){
        $error="unable to open";
    }
    else {
        $headers=$headers['wrapper_data'];
        if (!isset($headers[0])) {
            $error = "invalid get_headers - missing response";
        } else {
            if (!preg_match('/200/', $headers[0])) {
                $error = "invalid response " . $headers[0];
            }
        }
    }
    if ($error != null){
        if ($raise) {
            throw new \Exception("$error, url $url");
        }
        else{
            return false;
        }
    }
    foreach($headers as $header){
        $nv=preg_split('/ *: */',$header,2);
        if (count($nv) > 1 && $nv[0] == $TIME_HEADER){
            return strtotime($nv[1]);
        }
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

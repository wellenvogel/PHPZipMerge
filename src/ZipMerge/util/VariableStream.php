<?php
namespace ZipMerge\util;
//https://www.php.net/manual/de/stream.streamwrapper.example-1.php
stream_wrapper_register('var','\ZipMerge\util\VariableStream');

class VariableStream {
    var $position=0;
    var $varname=null;
    var $startpos=0;

    static $registry=array();
    static function registerObject($name,&$object,$offset=0){
        self::$registry[$name]=array($object,$offset);
    }
    static function deregisterObject($name){
        if (isset(self::$registry[$name])){
           unset(self::$registry[$name]);
        }
    }

    function stream_open($path, $mode, $options, &$opened_path)
    {
        $url = parse_url($path);
        $this->varname = $url["host"];
        if (! isset(self::$registry[$this->varname])){
            return false;
        }
        $this->startpos = self::$registry[$this->varname][1];
        $this->position=0;
        return true;
    }
    private function &get_var(){
        return self::$registry[$this->varname][0];
    }

    function stream_read($count)
    {
        $ret = substr($this->get_var(), $this->position+$this->startpos, $count);
        $this->position += strlen($ret);
        return $ret;
    }

    function stream_write($data)
    {
        $left = substr($this->get_var(), 0, $this->position+$this->startpos);
        $right = substr($this->get_var(), $this->position + $this->startpos+strlen($data));
        self::$registry[$this->varname][0] = $left . $data . $right;
        $this->position += strlen($data);
        return strlen($data);
    }

    function stream_tell()
    {
        return $this->position;
    }

    function stream_eof()
    {
        return $this->position + $this->startpos >= strlen($this->get_var());
    }

    function stream_seek($offset, $whence)
    {
        switch ($whence) {
            case SEEK_SET:
                if ($offset < strlen($this->get_var()) && $offset >= 0) {
                    $this->position = $offset;
                    return true;
                } else {
                    return false;
                }
                break;

            case SEEK_CUR:
                if ($offset >= 0) {
                    $this->position += $offset;
                    return true;
                } else {
                    return false;
                }
                break;

            case SEEK_END:
                if (strlen($this->get_var()) + $offset >= 0) {
                    $this->position = strlen($this->get_var()) + $offset;
                    return true;
                } else {
                    return false;
                }
                break;

            default:
                return false;
        }
    }

    function stream_metadata($path, $option, $var)
    {
        if($option == STREAM_META_TOUCH) {
            $url = parse_url($path);
            $varname = $url["host"];
            if(!isset(self::$registry[$varname])) {
                self::$registry[$varname] = array('',0);
            }
            return true;
        }
        return false;
    }
}
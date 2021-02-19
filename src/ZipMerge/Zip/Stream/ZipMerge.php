<?php
/**
 * ZipMerge will allow the user to combine multiple Zip files into one, while streaming the result directly to
 *  the client.
 * The contents of the Zip Files added will NOT be re-compressed.
 *
 * The primary use is for the user to be able to pre-assemble often used, static content, saving server time on
 *  subsequent use.
 * Another use case is to combine collections of for existing packages/collections of data the client may have
 *  purchased, and allow them to download these on the fly in a single file.
 * @author Grandt
 */

namespace ZipMerge\Zip\Stream;

use com\grandt\BinStringStatic;
use ZipMerge\util\VariableStream;
use ZipMerge\Zip\Core\AbstractException;
use ZipMerge\Zip\Core\AbstractZipWriter;
use ZipMerge\Zip\Core\Header\EndOfCentralDirectory;
use ZipMerge\Zip\Core\Header\ZipFileEntry;
use ZipMerge\Zip\Core\Header\AbstractZipHeader;
use ZipMerge\Zip\Exception\HeaderPositionError;
use ZipMerge\Zip\Exception\BufferNotEmpty;
use ZipMerge\Zip\Exception\HeadersSent;
use ZipMerge\Zip\Exception\IncompatiblePhpVersion;
use ZipMerge\Zip\File\File;
use ZipMerge\Zip\Listener\ZipArchiveListener;


class ZipMerge {
    const APP_NAME = 'PHPZipMerge';
    const VERSION = "1.0.2";
    const MIN_PHP_VERSION = 5.3; // for namespaces
    
    const CONTENT_TYPE = 'application/zip';

    const MODE_STREAM = 0;
    const MODE_INLINE = 1;

    private $_listeners = array();

    protected $isFinalized = false;

    protected $FILES = array();
    protected $eocd = null;
    protected $LFHindex = 0;
    protected $CDRindex = 0;
    protected $entryOffset = 0;
    protected $streamChunkSize = 65536; // 64kb
    protected $mode = self::MODE_STREAM;
    protected $firstHeader=null;
    /** @var $writer AbstractZipWriter */
    public $writer = null;
    public $id = '';

    /**
     * Constructor.
     * If $fileName is set to null, the class will not be streaming the data, and instead expect to receive a $writer
     * class for callbacks in the appendZip function.
     *
     * @param String $fileName The name of the Zip archive, in ISO-8859-1 (or ASCII) encoding, ie. "archive.zip". Optional, defaults to NULL, which means that no ISO-8859-1 encoded file name will be specified.
     * @param String $contentType Content mime type. Optional, defaults to "application/zip".
     * @param String $utf8FileName The name of the Zip archive, in UTF-8 encoding. Optional, defaults to NULL, which means that no UTF-8 encoded file name will be specified.
     * @param bool $inline Use Content-Disposition with "inline" instead of "attached". Optional, defaults to false.
     */
    public function __construct($fileName = null, $contentType = "application/zip", $utf8FileName = null, $inline = false,$streamChunkSize=2000000) {
        $this->checkVersion();
        $this->id=uniqid('ZipMerge');
        if ($fileName !== null) {
            $this->buildResponseHeader($fileName, $contentType, $utf8FileName, $inline);
            $this->zipFlushBuffer();
            $this->eocd = new EndOfCentralDirectory();
        } else {
            $this->mode = self::MODE_INLINE;
        }
        $this->streamChunkSize=$streamChunkSize;
    }

    public function __destruct() {
        $this->isFinalized = true;
        unset ($this->FILES);
    }

    /**
     * Append the contents of an existing zip file to the current, WITHOUT re-compressing the data within it.
     *
     * @param string $file the path to the zip file to be added.
     * @return bool true for success.
     */
    public function appendZip($file) {
        if ($this->isFinalized) {
            return false;
        }
        if (is_string($file) && is_file($file)) {
            $handle = fopen($file, 'r');
            $this->processStream($handle);
            fclose($handle);
        } else if (is_resource($file) && get_resource_type($file) == "stream") {
            $this->processStream($file);
        }
        return true;
    }

    private function open_buffer_stream(&$buffer,$offset=0){
        VariableStream::deregisterObject($this->id);
        VariableStream::registerObject($this->id,$buffer,$offset);
        return fopen('var://'.$this->id,'rb');
    }
    private function close_buffer_stream(&$stream){
        fclose($stream);
        VariableStream::deregisterObject($this->id);
    }
    
    private function processStream($handle) {
        $buffer='';
        $startOfStreamOffset=$this->entryOffset;
        while (true) {
            $data = fread($handle, $this->streamChunkSize);
            $len=strlen($data);
            if ($len > 0) {
                $this->entryOffset+=$len;
                $this->zipWrite($data);
                if (strlen($buffer) < ($this->streamChunkSize + $len)){
                    $buffer.=$data;
                }
                else{
                    $buffer=substr($buffer,$this->streamChunkSize-$len).$data;
                }
            }
            else{
                break;
            }
            //reached the end
            //now we search for the directory in $buffer

        }
        $pkHeader = null;
        $pkHeaderAndPtr = AbstractZipHeader::seekPKHeaderInBuffer($buffer);
        if ($pkHeaderAndPtr[0] !== AbstractZipHeader::ZIP_END_OF_CENTRAL_DIRECTORY){
            die("unable to find zip directory in buffer");
        }
        $eocdPtr=$pkHeaderAndPtr[1];
        $readBuffer=substr($buffer,$eocdPtr);
        $s=$this->open_buffer_stream($readBuffer);
        $this->eocd = new EndOfCentralDirectory($s);
        $this->close_buffer_stream($s);
        $dirsize=$this->eocd->cdrLength;
        if (($eocdPtr-$dirsize) <0){
            die("buffer too small for central directory");
        }
        $start=$eocdPtr-$dirsize;
        $handle=$this->open_buffer_stream($buffer,$start);
        while(true) {
            $fileEntry = new ZipFileEntry($handle);
            $fileEntry->offset+=$startOfStreamOffset;
            $this->FILES[$this->LFHindex++] = $fileEntry;
            $nextHeader = substr($buffer, $start+ftell($handle), 4);
            if ($nextHeader === AbstractZipHeader::ZIP_END_OF_CENTRAL_DIRECTORY) {
                //we are done
                break;
            }
            if ($nextHeader !== AbstractZipHeader::ZIP_CENTRAL_FILE_HEADER) {
                die("invalid central directory");
            }
        }
    }

    public function addDirectory($subPath)
    {
        if (!empty($subPath)) {
            $subPath = \RelativePath::getRelativePath($subPath);
            $subPath = rtrim($subPath, '/');

            if (!empty($subPath)) {
                $path = explode('/', $subPath);
                $subPath .= '/';
                $nPath = '';

                foreach ($path as $dir) {
                    $nPath .= $dir . '/';
                    $fileEntry = ZipFileEntry::createDirEntry($nPath, time());

                    $lf = $fileEntry->getLocalHeader();
                    $this->zipWrite($lf);
                    $lfLen =  BinStringStatic::_strlen($lf);
                    $fileEntry->offset = $this->entryOffset;
                    $this->entryOffset += $lfLen;

                    $this->FILES[$this->LFHindex++] = $fileEntry;
                    $this->CDRindex++;
                }
            }
        }
    }

    public function addStream($name,$time,$stream)
    {
        $exploded=explode('/',$name);
        if (count($exploded) > 1){
            $subPath=join('/',array_slice($exploded,0,-1));
            $this->addDirectory($subPath);
        }
        $streamFile=new File($name,$this,$this->entryOffset,$time);
        $streamFile->readStream($stream);
        $this->entryOffset+=$streamFile->written;
        $this->FILES[$this->LFHindex++]=$streamFile->fileHeader;
    }
    
    /**
     * Close the archive.
     * A closed archive can no longer have new files added to it.
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @return array|bool boole true/false for stream mode, an array of ZipFileEntry for inline mode.
     */
    public function finalize() {
        if ($this->mode == self::MODE_STREAM) {
            if (!$this->isFinalized) {
                $this->eocd->cdrStart = $this->entryOffset;
                $this->eocd->cdrLength = 0;
                $this->eocd->cdrCount1 = 0;

                foreach ($this->FILES as $fileEntry) {
                    /* @var $fileEntry ZipFileEntry */
                    $this->eocd->cdrCount1++;
                    $cd = $fileEntry->getCentralDirectoryHeader();

                    $this->eocd->cdrLength += BinStringStatic::_strlen($cd);
                    $this->zipWrite($cd);
                }

                $this->eocd->cdrCount2 = $this->eocd->cdrCount1;
                $this->zipWrite(''.$this->eocd);
                $this->isFinalized = true;
                return true;
            }
            return false;
        } else {
            $this->isFinalized = true;
            return $this->FILES;
        }
    }

    public function getFileEntries() {
        return $this->FILES;
    }

    /**
     * @return null|\ZipMerge\Zip\Core\Header\EndOfCentralDirectory
     */
    public function getEocd() {
        return $this->eocd;
    }

    /**
     * @return int
     */
    public function getEntryOffset() {
        return $this->entryOffset;
    }

    /*
     * ************************************************************************
     * protected methods.
     * ************************************************************************
     */

    /**
     * Build the base standard response headers, and ensure the content can be streamed.
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @param String $fileName The name of the Zip archive, in ISO-8859-1 (or ASCII) encoding, ie. "archive.zip". Optional, defaults to null, which means that no ISO-8859-1 encoded file name will be specified.
     * @param String $contentType Content mime type. Optional, defaults to "application/zip".
     * @param String $utf8FileName The name of the Zip archive, in UTF-8 encoding. Optional, defaults to null, which means that no UTF-8 encoded file name will be specified.
     * @param bool   $inline Use Content-Disposition with "inline" instead of "attached". Optional, defaults to false.
     *
     * @return bool Always returns true (for backward compatibility).
     * 
      * @throws \ZipMerge\Zip\Exception\BufferNotEmpty, HeadersSent In case of errors
     */
    protected function buildResponseHeader($fileName = null, $contentType = self::CONTENT_TYPE, $utf8FileName = null, $inline = false) {
        $ob = null;
        $headerFile = null;
        $headerLine = null;
        $zlibConfig = 'zlib.output_compression';

        $ob = ob_get_contents();
        if ($ob !== false && BinStringStatic::_strlen($ob)) {
            $this->_throwException(new BufferNotEmpty(array(
                'outputBuffer' => $ob,
                'fileName' => $fileName,
            )));
        }

        if (headers_sent($headerFile, $headerLine)) {
            $this->_throwException(new HeadersSent(array(
                'headerFile' => $headerFile,
                'headerLine' => $headerLine,
                'fileName' => $fileName,
            )));
        }

        if (@ini_get($zlibConfig)) {
            @ini_set($zlibConfig, 'Off');
        }
        
        $cd = 'Content-Disposition: ' . ($inline ? 'inline' : 'attached');

        if ($fileName) {
            $cd .= '; filename="' . $fileName . '"';
        }

        if ($utf8FileName) {
            $cd .= "; filename*=UTF-8''" . rawurlencode($utf8FileName);
        }

        header('Pragma: public');
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s T'));
        header('Expires: 0');
        header('Accept-Ranges: bytes');
        header('Connection: close');
        header('Content-Type: ' . $contentType);
        header($cd);

        return true;
    }

    /**
     * Check PHP version.
     *
     * @author A. Grandt <php@grandt.com>
     */
    public function checkVersion() {
        if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<') || !function_exists('sys_get_temp_dir') ) {
            $this->_throwException(new IncompatiblePhpVersion(array(
                'appName' => self::APP_NAME,
                'appVersion' => self::VERSION,
                'minVersion' => self::MIN_PHP_VERSION,
            )));
            return false;
        }
        return true;
    }

    /*
     * ************************************************************************
     * Listener methods.
     * ************************************************************************
     */

    /**
     * Listen to events fired by this class.
     *
     * @author Greg Kappatos
     *
     * @param ZipArchiveListener $listener Class that implements the ZipArchiveListener interface.
     */
    public function addListener(ZipArchiveListener $listener) {
        $this->_listeners[] = $listener;
    }

    /**
     * Stop listening to events fired by this class.
     *
     * @author Greg Kappatos
     *
     * @param ZipArchiveListener $listener Class that implements the ZipArchiveListener interface.
     */
    public function removeListener(ZipArchiveListener $listener) {
        $key = array_search($listener, $this->_listeners);

        if ($key !== false) {
            unset($this->_listeners[$key]);
        }
    }

    /**
     * Helper method to fire appropriate event.
     *
     * @author Greg Kappatos
     *
     * @param string|null $method (Optional) The name of the event to fire. If this is null, then the calling method is used.
     * @param array       $data Method parameters passed as an array.
     */
    private function _notifyListeners($method = null, array $data = array()) {
        if (is_null($method)) {
            $trace = debug_backtrace();
            $trace = $trace[1];
            $method = 'on' . ucwords($trace['function']);
        }

        foreach ($this->_listeners as $listener) {
            if (count($data) > 0) {
                $listener->$method($data);
            } else {
                $listener->$method();
            }
        }
    }

    /**
     * Helper method to fire OnException event for listeners and then throw the appropriate exception.
     *
     * @author Greg Kappatos
     *
     * @param AbstractException $exception Whatever exception needs to be thrown.
     *
     * @throws AbstractException $exception
     */
    private function _throwException(AbstractException $exception) {
        $this->_notifyListeners('Exception', array(
            'exception' => $exception,
        ));

        throw $exception;
    }


    // ***********************************
    // ** Abstract functions            **
    // ***********************************

    /**
     * Verify if the memory buffer is about to be exceeded.
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @param int $gzLength length of the pending data.
     */
    public function zipVerifyMemBuffer($gzLength) {
        // Does nothing, used to "streamline" code differences between PHPZip and PHPZipStream
    }

    /**
     *
     * @author A. Grandt <php@grandt.com>
     *
     * @param string $data
     */
    public function zipWrite($data) {
        if ($this->writer == null || $this->mode == self::MODE_STREAM) {
            print($data);
        } else {
//            print "<pre>" . __CLASS__ . "->zipWrite: " . strlen($data) . ":" . bin2hex($data) . "</pre>\n";
            call_user_func_array(array($this->writer, "zipWrite"), array($data));
        }
    }

    /**
     * Flush Zip Data stored in memory, to a temp file.
     *
     * @author A. Grandt <php@grandt.com>
     *
     */
    public function zipFlush() {
        // Does nothing, used to "streamline" code differences between PHPZip and PHPZipStream
    }

    /**
     *
     * @author A. Grandt <php@grandt.com>
     *
     */
    public function zipFlushBuffer() {
        if ($this->mode == self::MODE_STREAM) {
            flush();
        }
    }
}

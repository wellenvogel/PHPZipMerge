<?php
#taken from maennchen/zipstream-php


namespace ZipMerge\Zip\File;


use ZipMerge\Zip\Core\Header\AbstractZipHeader;
use ZipMerge\Zip\Core\Header\ZipFileEntry;
use ZipMerge\Zip\Core\ZipUtils;
use ZipMerge\Zip\Stream\ZipMerge;

class File
{
    const HASH_ALGORITHM = 'crc32b';

    const BIT_ZERO_HEADER = 0x0008;
    const BIT_EFS_UTF8 = 0x0800;

    const COMPUTE = 1;
    const SEND = 2;

    const CHUNKED_READ_BLOCK_SIZE = 1048576;
    const DEFLATE = 0x0014; // 2.00

    /**
     * @var string
     */
    public $name;


    /**
     * @var int
     */
    public $len;
    /**
     * @var int
     */
    public $zlen;

    /** @var  int */
    public $crc;

    /**
     * @var int
     */
    public $written;

    /**
     * @var resource
     */
    private $hash=null;


    private $time;
    private $offset = 0;
    /**
     * @var ZipMerge
     */
    private $zipstream = null;
    public $fileHeader = null;


    public function __construct($name, $zipStream, $offset, $time = null)
    {
        $this->zipstream = $zipStream;
        $this->offset = $offset;
        $this->time = ($time == null) ? time() : $time;
        $this->name = static::filterFilename($name);
        $this->fileHeader = $this->getFileHeader();
        $hdr = $this->fileHeader->getLocalHeader();
        $this->zipstream->zipWrite($hdr);
        $this->written += strlen($hdr);
    }


    /**
     * Strip characters that are not legal in Windows filenames
     * to prevent compatibility issues
     *
     * @param string $filename Unprocessed filename
     * @return string
     */
    public static function filterFilename($filename)
    {
        // strip leading slashes from file name
        // (fixes bug in windows archive viewer)
        $filename = preg_replace('/^\\/+/', '', $filename);

        return str_replace(array('\\', ':', '*', '?', '"', '<', '>', '|'), '_', $filename);
    }


    public function getFileHeader()
    {
        $rt = new ZipFileEntry();
        $rt->fileCRC32 = 0;
        $rt->dosTime = ZipUtils::getDosTime($this->time);
        $rt->gzType = 0; //STORE
        $rt->offset = $this->offset;
        $rt->dataLength = 0;
        $rt->gzLength = 0;
        $rt->gpFlags = self::BIT_ZERO_HEADER; //length not known
        $rt->path = $this->name;
        $rt->externalFileAttributes = AbstractZipHeader::EXT_FILE_ATTR_FILE664;
        return $rt;
    }


    /**
     * update zipEntry
     */

    private function UpdateZipEntry()
    {
        $this->fileHeader->gzLength = $this->zlen;
        $this->fileHeader->dataLength = $this->len;
        $this->fileHeader->fileCRC32 = $this->crc;
        return $this->fileHeader;
    }

    public function readStream($stream,$finalize=true)
    {
        while (true) {
            $data = fread($stream,self::CHUNKED_READ_BLOCK_SIZE);
            $len = strlen($data);
            if ($len <= 0) break;
            $this->readData($data,false);
        }
        $this->readData('',$finalize);
    }
    public function readData($data,$finalize=true)
    {
        if ($this->hash == null) $this->hash = hash_init(self::HASH_ALGORITHM);
        $len = strlen($data);
        if ($len > 0) {
            $this->len += $len;
            hash_update($this->hash, $data);
            $this->zipstream->zipWrite($data);
            $this->written += $len;
        }
        if (! $finalize) return;
        $this->zlen=$this->len;
        $this->crc = hexdec(hash_final($this->hash));
        $this->UpdateZipEntry();
        $footer=$this->fileHeader->getFooter();
        $this->zipstream->zipWrite($footer);
        $this->written+=strlen($footer);
    }

}
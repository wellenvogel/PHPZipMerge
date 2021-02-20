<?php
/**
 *
 * @author Greg Kappatos
 *
 * This class serves as a concrete exception.
 * It will be thrown if any headers have been sent, or if any
 * output has been printed or echoed.
 *
 */

namespace ZipMerge\Zip\Exception;

use ZipMerge\Zip\Core\AbstractException;

class InvalidStructure extends AbstractException {

    private $_info = null;
    private $_fileName = null;

    /**
     * Constructor
     *
     * @author A. Vogel <andreas@wellenvogel.net>
     * @author A. Grandt <php@grandt.com>
     * @author Greg Kappatos
     *
     * @param array $config Configuration array containing info and fileName
     */
    public function __construct(array $config){
        $this->_info = $config['info'];
        $this->_fileName = isset($config['fileName']) ? $config['fileName'] : null;

        $message = 'Invalid structure '.$this->_info;
        $message .= is_null($this->_fileName) ? '' : "in '{$this->_fileName}'. ";

        parent::__construct($message);
    }


    public function getFileName(){
        return $this->_fileName;
    }
    public function getInfo(){
        return $this->_info;
    }
}
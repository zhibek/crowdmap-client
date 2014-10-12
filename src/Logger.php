<?php

namespace Zhibek\CrowdmapClient;

class Logger
{

    private $_object;

    private $_method;
    
    const EMERGENCY = LOG_EMERG;    // 0
    const ALERT     = LOG_ALERT;    // 1
    const CRITICAL  = LOG_CRIT;     // 2
    const ERROR     = LOG_ERR;      // 3
    const WARNING   = LOG_WARNING;  // 4
    const NOTICE    = LOG_NOTICE;   // 5
    const INFO      = LOG_INFO;     // 6
    const DEBUG     = LOG_DEBUG; // 7
    
    
    public function __construct ($options = array())
    {
        $this->_object = new $options['object']();
        $this->_method = $options['method'];
    }
    
    public function log ($type, $message)
    {
        $result = $this->_object->{$this->_method}($type, $message);
    }
    
}
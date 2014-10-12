<?php

namespace Zhibek\CrowdmapClient;

class Client
{

    const API_SCHEME = 'https';

    const API_HOST = 'api.crowdmap.com';

    const API_PATH = '/v1';

    /**
     * @var string
     */
    protected $_publicKey;

    /**
     * @var string
     */
    protected $_privateKey;

    /**
     * @var Cache
     */
    protected $_cache;

    /**
     * @var Logger
     */
    protected $_logger;

    /**
     * @var Profiler
     */
    protected $_profiler;

    /**
     * Request methods
     */
    const REQUEST_METHOD_GET     = 'GET';
    const REQUEST_METHOD_POST    = 'POST';
    const REQUEST_METHOD_PUT     = 'PUT';
    const REQUEST_METHOD_DELETE  = 'DELETE';

    /**
     * @var array
     */
    protected static $_cachableMethods = array(
        self::REQUEST_METHOD_GET
    );

    /**
     * @var string
     */
    protected $_sessionKey;

    public function __construct($publicKey, $privateKey, $cache = null, $logOptions = null, $profiler = 'Zhibek\CrowdmapClient\Profiler')
    {
        $this->_publicKey = $publicKey;
        $this->_privateKey = $privateKey;
        $this->_cache = $cache;

        if ($logOptions) {
            $this->_setLogger($logOptions);
        }

        $this->_profiler = $profiler;
    }

    protected function _generateSignature($method, $resource)
    {
        $date = time();
        return 'A' . $this->_publicKey . hash_hmac('sha1',
                "{$method}\n{$date}\n{$resource}\n", $this->_privateKey);
    }

    protected function _request($method, $resource, $data = array(), $files = array())
    {
        $url = self::API_SCHEME . '://' . self::API_HOST . self::API_PATH . $resource;
        $userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0 Crowdmap Client';
        $headers = array(
            'User-Agent: ' . $userAgent,
        );

        // Don't use two ? characters if we are already building our query outside the class.
        $query_separator = '?';
        if(strpos($url,'?')) $query_separator = '&';

        switch ($method) {
            case self::REQUEST_METHOD_GET:
            case self::REQUEST_METHOD_DELETE:
                $options = array(
                    'method' => $method,
                    'header' => implode("\r\n", $headers),
                    'ignore_errors' => true,
                );
                $url .= $query_separator.http_build_query($data);
                break;

            case self::REQUEST_METHOD_POST:
            case self::REQUEST_METHOD_PUT:
                if ($files) {
                    $boundary = '----' . substr(md5(rand(0,32000)), 0, 10);
                    $headers[] = 'Content-type: multipart/form-data; boundary=' . $boundary;
                    $content = array();
                    foreach ($files as $key => $file) {
                        $content[] = '--' . $boundary;
                        $content[] = sprintf('Content-Disposition: form-data; name="%s"; filename="%s"', $key, $file['name']);
                        $content[] = sprintf('Content-Type: %s', $file['type']);
                        $content[] = 'Content-Transfer-Encoding: binary';
                        $content[] = '';
                        $content[] = file_get_contents($file['tmp_name']);
                        //$content[] = substr(file_get_contents($file['tmp_name']), 0, 100);
                    }
                    $content[] = '--' . $boundary . '--';
                    $options = array(
                        'method' => $method,
                        'header' => implode("\r\n", $headers),
                        'ignore_errors' => true,
                        'content' => implode("\r\n", $content),
                    );
                    $url .= $query_separator.http_build_query($data);
                } else {
                    $headers[] = 'Content-type: application/x-www-form-urlencoded';
                    $options = array(
                        'method' => $method,
                        'header' => implode("\r\n", $headers),
                        'ignore_errors' => true,
                        'content' => http_build_query($data),
                    );
                }
                break;

            default:
                throw new Exception(sprintf('Invalid method: "%s"', $method));
                break;
        }

        $context = stream_context_create(
                array(
                    'http' => $options,
                ));
        $this->log(Logger::DEBUG, sprintf('API - REQUEST - %s %s', $method, $url));
        $result = file_get_contents($url, false, $context);
        if (! $result) {
            throw new Exception(
                    sprintf('Request Error: %s', $http_response_header[0]));
        }

        $data = @json_decode($result);

        if (! $data) {
            throw new Exception(sprintf('API Error: "%s"', $result));
        }

        if (isset($data->error) && $data->error) {
            if (isset($data->message) && $data->message) {
                throw new Exception( $data->message);
            } else {
                throw new Exception( $data->error);
            }
        }

        $this->log(Logger::DEBUG, sprintf('API - RESPONSE - %s %s - RETURN %d bytes', $method, $url, strlen($result)));

        return $data;
    }

    protected function _isCachableMethod($method)
    {
        return in_array($method, self::$_cachableMethods);
    }

    protected function _getCache($method, $resource, $data, $cacheTags = array())
    {
        if (is_null($this->_cache) || false === $cacheTags) {
            return false;
        } else {
            return $this->_cache->get($method, $resource, $data);
        }
    }

    protected function _deleteCache($tag=false)
    {
        if (is_null($this->_cache) || false === $tag) {
            return false;
        } else {
            return $this->_cache->deleteTag($tag);
        }
    }

    protected function _setCache($method, $resource, $data, $toStore, $cacheTags)
    {
        if (is_null($this->_cache) || false === $cacheTags) {
            return false;
        } else {
            return $this->_cache->set($method, $resource, $data, $toStore, $cacheTags);
        }
    }

    public function call($method, $resource, $data = array(), $addSessionKey = false, $cacheTags = array(), $invalidateCacheTags = array())
    {
        $this->log(Logger::DEBUG, sprintf('API - CALL %s %s %s', $method, $resource, serialize($data)));

        // add session key (if exists and not ignored)
        if ($addSessionKey && $this->_sessionKey) {
            $data['session'] = $this->_sessionKey;
        }

        if (!empty($invalidateCacheTags)) {
            foreach($invalidateCacheTags AS $tag) {
                $this->_deleteCache($tag);
            }
        }

        $forceRefreshCache = false;
        if(isset($_GET['forceRefreshCache'])) {
            $forceRefreshCache = filter_var($_GET['forceRefreshCache'], FILTER_VALIDATE_BOOLEAN);
        }

        // If we are forcing a cache refresh, we want to skip returning the cache
        if($forceRefreshCache == false) {
            // check in cache before calling
            if ($this->_isCachableMethod($method)) {
                $cached = $this->_getCache($method, $resource, $data, $cacheTags);
                if (null != $cached) {
                    $this->log(Logger::DEBUG, sprintf('API - CALL - CACHED RESPONSE %s %s', $method, $resource));
                    $this->profile($method, $resource, Profiler::KEY_CACHED);
                    return $cached;
                }
            }
        }

        // generate apikey (signature)
        $data['apikey'] = $this->_generateSignature($method, $resource);

        // handle file uploads
        $files = array();
        if (isset($data['_files'])) {
            $files = $data['_files'];
            unset($data['_files']);
        }

        $startTime = microtime(true);

        // make api call
        $response = $this->_request($method, $resource, $data, $files);

        $time = (int)((microtime(true) - $startTime) * 1000);

        $this->profile($method, $resource, Profiler::KEY_LIVE, $time);

        // save in cache
        if ($this->_isCachableMethod($method)) {
            $this->_setCache($method, $resource, $data, $response, $cacheTags);
        }

        return $response;
    }

    public function setSessionKey($sessionKey)
    {
        $this->_sessionKey = $sessionKey;
    }

    public function cleanCacheTag($tag)
    {
        if (is_null($this->_cache)) {
            return false;
        }

        return $this->_cache->deleteTag($tag);
    }

    protected function _setLogger($logOptions)
    {
        $this->_logger = new Logger(array(
            'object' => $logOptions->object,
            'method' => $logOptions->method,
        ));
    }

    public function log($type, $message)
    {
        if ($this->_logger) {
            $this->_logger->log($type, $message);
        }
    }

    public function profile($method, $resource, $cached, $time = null)
    {
        if ($this->_profiler) {
            call_user_func_array(array($this->_profiler, 'request'), array($method, $resource, $cached, $time));
        }
    }

}
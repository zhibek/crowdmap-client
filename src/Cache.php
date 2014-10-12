<?php

namespace Zhibek\CrowdmapClient;

class Cache
{

    private $_cachedData;

    private $_lifetime;

    const KEY_PREFIX_USER = 'USER_';

    const KEY_PREFIX_MAP = 'MAP_';

    const KEY_PREFIX_POST = 'POST_';

    /**
     * @var Cache_Redis
     */
    private $_cacheAdapter;

    public function __construct($params, $config)
    {
        $this->_cacheAdapter = new $params['cacheAdapter']($config);
        $this->_lifetime = $params['lifetime'];
        if (isset($params['data'])) {
            $this->_cachedData = $params['data'];
        }
    }

    private function generateKey($method, $resource, $data)
    {
        // Explicitly unset "apikey" as it always changes
        unset($data['apikey']);

        // Generate cache key from method, resource and data
        return $method . '-' . $resource . '-' . serialize($data);
    }

    public function get($method, $resource, $data)
    {
        $key = $this->generateKey($method, $resource, $data);
        $this->_cachedData = $this->_cacheAdapter->get($key);
        return unserialize($this->_cachedData);
    }

    public function set($method, $resource, $data, $cachedData, $cacheTags)
    {
        $key = $this->generateKey($method, $resource, $data);
        return $this->_cacheAdapter->set_with_tags($key, serialize($cachedData), $this->_lifetime, $cacheTags);
    }

    public function deleteTag($tag)
    {
        return $this->_cacheAdapter->delete_tag($tag);
    }

}
<?php

namespace Zhibek\CrowdmapClient;

class Profiler
{

    const KEY_LIVE = false;
    const KEY_CACHED = true;

    protected static $_requests = array(
        self::KEY_LIVE => array(),
        self::KEY_CACHED => array(),
    );

    public static function request($method, $resource, $cached, $time = null)
    {
        if (!$cached) {
            $time = sprintf(' %dms', $time);
        }
        self::$_requests[$cached][] = sprintf('%s %s%s', $method, $resource, $time);
    }

    public static function getProfileSummary()
    {
        return sprintf('crowdmap (%d live, %d cached)', count(self::$_requests[self::KEY_LIVE]), count(self::$_requests[self::KEY_CACHED]));
    }

    public static function getProfileData()
    {
        $data = array();

        $data[] = 'LIVE';
        $data[] = print_r(self::$_requests[self::KEY_LIVE], true);
        $data[] = 'CACHED';
        $data[] = print_r(self::$_requests[self::KEY_CACHED], true);

        $data = implode(PHP_EOL, $data);

        return nl2br($data);
    }

}
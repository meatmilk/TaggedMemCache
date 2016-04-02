<?php

namespace TaggedCache;

/**
 * Class TaggedRedisCache
 */
class Redis implements BasicCache
{
    const CLEANING_MODE_ALL = 'all';
    const CLEANING_MODE_MATCHING_TAG = 'matchingTag';
    const CLEANING_MODE_MATCHING_ANY_TAG = 'matchingAnyTag';

    private $cache = false;
    private $connected = false;
    private $namespace = false;
    private $server = '';
    private $prefix = '';

    /**
     * TaggedRedisCache constructor.
     *
     * @param string $server // ip of a redis server
     */
    public function __construct($server)
    {
        $this->server = $server;
        $this->connect();
        if ($this->connected)
        {
            $this->namespace = $this->cache->get("RKC:NAMESPACE");
            if (!$this->namespace)
            {
                $this->namespace = rand(1, 10000);
                $this->cache->set("RKC:NAMESPACE", $this->namespace);
            }
            $this->cleanTags();
        }
    }

    private function connect()
    {
        if (!$this->connected)
        {
            if (class_exists("\\Redis"))
            {
                $this->cache     = new \Redis();
                $this->connected = $this->cache->pconnect($this->server, 6379);
                $this->cache->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
            }
            else
            {
                $this->cache     = new \Predis\Client('tcp://' . $this->server . ':6379');
                $this->connected = $this->cache->ping("connected");
            }
            $this->cache->select(0);
        }
    }

    /**
     * Save variable in cache
     *
     * @param mixed  $data    // variable to store
     * @param string $key     // unique key
     * @param array  $tags    // array of tags for simple delete of key groups
     * @param int    $timeout // timeout in seconds
     *
     * @return string "+OK\r\n" or "-Error message\r\n" etc
     */
    public function save($data, $key, $tags = [], $timeout = 3600)
    {
        if ($this->connected)
        {
            $key        = $this->genkey($key, $tags);
            $compressed = gzcompress(json_encode($data, JSON_UNESCAPED_UNICODE), 9);

            return $this->cache->setex($key, $timeout, $compressed);
        }
    }

    /**
     * Try load Variable from Cache
     *
     * @param string $key  // unique key
     * @param array  $tags // array of tags for simple delete of key groups
     *
     * @return bool|mixed
     */
    public function load($key, $tags = [])
    {
        if ($this->connected)
        {
            $key  = $this->genkey($key, $tags);
            $dane = $this->cache->get($key);

            if ($dane)
            {
                return json_decode(gzuncompress($dane), true);
            }
            else
            {
                return false;
            }
        }
    }

    /**
     * Clean whole Cache or tag group
     *
     * @param       $mode // one of 'all', 'matchingTag', 'matchingAnyTag'
     * @param array $tags // tag or tags
     */
    public function clean($mode, $tags = [])
    {
        if ($this->connected)
        {
            switch ($mode)
            {
                case self::CLEANING_MODE_ALL:
                    $this->cache->incr("RKC:NAMESPACE");
                    $this->namespace = $this->cache->get("RKC:NAMESPACE");
                    break;
                case self::CLEANING_MODE_MATCHING_TAG:
                case self::CLEANING_MODE_MATCHING_ANY_TAG:
                    if (count($tags))
                    {
                        foreach ($tags as $tag)
                        {
                            $this->incrementTag($tag);
                        }
                    }
                    break;
            }
        }
    }

    private function genkey($string, $tags = null)
    {
        $tags_str = '_';
        $tags_val = 0;
        if (is_array($tags) && count($tags))
        {
            asort($tags);
            foreach ($tags as $tag)
            {
                $tags_str = $tags_str . '_' . $tag;
                $tags_val = $tags_val . '_' . $this->getTagValue($tag);
            }
        }

        $hash_this = $this->prefix . '_keys_' . $string . '_' . $tags_str . '_' . $tags_val;

        $key = 'RKC:' . $this->namespace . ':' . rtrim(strtr(base64_encode(hash('sha256', $hash_this, true)), '+/', '-_'), '=');

        return $key;
    }

    private function incrementTag($tag)
    {
        return $this->cache->hincrby("RKC:TAGS", $tag, 1);
    }


    private function getTagValue($tag)
    {
        $this->cache->hset("RKC:TIME", $tag, time());

        if (!$newval = $this->cache->hget("RKC:TAGS", $tag))
        {
            $this->cache->hset("RKC:TAGS", $tag, 1);
            $newval = 1;
        }

        return $newval;
    }

    private function cleanTags()
    {
        if ($this->connected)
        {
            if (!$this->cache->exists("RKC:REFRESH"))
            {
                $this->cache->setex("RKC:REFRESH", 7200, 1);
                $keys = $this->cache->hgetall("RKC:TIME");
                if (is_array($keys) && count($keys))
                {
                    foreach ($keys as $key => $time)
                    {
                        $age = time() - $time;
                        if ($age > 86400)
                        {
                            $this->cache->hdel("RKC:TIME", $key);
                            $this->cache->hdel("RKC:TAGS", $key);
                        }
                    }
                }
            }
        }
    }

    /**
     * Set prefix, for cache separation in some scenarios
     *
     * @param string $prefix
     */
    public function prefix($prefix)
    {
        $this->prefix = (string)$prefix;
    }

}


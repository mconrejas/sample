<?php
/**
 * Created by PhpStorm.
 * User: darwinj
 * Date: 5/16/17
 * Time: 10:20 AM
 */

namespace ADZbuzzCore;


class Cache
{
    private static $instance = NULL;

    private $prefix = NULL; // this is just an identifier of who is calling, all clients with same prefix share same connection
    private $port = 6379;
    private $server = "localhost";

    private $mcache = NULL;

    private $is_connected = false;

    const DEFAULT_EXPIRATION = 60;

    private function __construct($server = NULL, $port = NULL, $prefix = NULL)
    {
        if (!is_null($server)) {
            $this->server = $server;
        }
        if (!is_null($port)) {
            $this->port = $port;
        }
        $this->setPrefix($prefix);
        self::$instance =& $this;
    }

    public static function &get_instance($server = NULL, $port = NULL, $prefix = NULL)
    {
        if (is_null(self::$instance)) {
            $class=__CLASS__;
            $cacheObj = new $class($server, $port, $prefix);
            return $cacheObj;
        } else {
            return self::$instance;
        }
    }

    function setPrefix($prefix){
        if (!is_null($prefix)) {
            $this->prefix = $prefix;
        }else{
            if(defined("STAGING") && STAGING) $this->prefix = "adzbuzz-staging";
            else $this->prefix = "adzbuzz-live";
        }
    }

    function connect ( $server = NULL, $port = NULL, $prefix = NULL )
    {
        if ( ! isset($this) ) {
            error_log("error: ".__CLASS__ . " was used statically, please call get_instance() and use that object instead");
            return false;
        }

        if (!is_null($server)) {
            $this->server = $server;
        }
        if (!is_null($port)) {
            $this->port = $port;
        }
        if (!is_null($prefix)) {
            $this->prefix = $prefix;
        }

        #$this->mcache = new \Redis();
        if(!class_exists('Redis', false)) {
            $this->mcache = new \Predis\Client();
        } else {
            $this->mcache = new \Redis();
        }
        if (false === $this->mcache->connect($this->server,$this->port)) {
            error_log("error: ".__CLASS__ . " - Could not add server " . $this->server . ":" . $this->port);
            return false;
        }

        $this->is_connected = true;
        return true;
    }

    function set($namespace, $key, $value, $expires = self::DEFAULT_EXPIRATION)
    {   
         
        if ( ! isset($this) ) {
            error_log("error: ".__CLASS__ . " was used statically, please call get_instance() and use that object instead");
            return false;
        }

        if (!$this->is_connected && ! $this->connect() ) return false;

        $lookup = $this->prefix . "." . $namespace . "." . $key;

        if (false !== $this->mcache->set($lookup, $value)) {
            $this->mcache->expire($lookup, $expires);
            return true;
        } else {
             error_log("error: Could not store key '$key'");
             return false;
        }
    }

    function get($namespace, $key)
    {
        if ( ! isset($this) ) {
            error_log("error: ".__CLASS__ . " was used statically, please call get_instance() and use that object instead");
            return false;
        }

        if (!$this->is_connected && ! $this->connect() ) return false;

        $lookup = $this->prefix . "." . $namespace . "." . $key;
        
        if (false !== ($info = $this->mcache->get($lookup))) return $info;
        return false;
    }

    function is_set($namespace, $key)
    {
        return (false !== $this->get($namespace, $key));
    }

    function delete($namespace, $key)
    {
        if ( ! isset($this) ) {
            error_log("error ".__CLASS__ . " was used statically, please call get_instance() and use that object instead");
            return false;
        }

        if (!$this->is_connected && !$this->connect()) return false;

        $lookup = $this->prefix . "." . $namespace . "." . $key;

        if (false !== $this->mcache->del($lookup)) {
            return true;
        }

        return false;
    }

    function multi_delete($namespace, $key)
    {
        if ( ! isset($this) ) {
            error_log("error ".__CLASS__ . " was used statically, please call get_instance() and use that object instead");
            return false;
        }

        if (!$this->is_connected && !$this->connect()) return false;

        $lookup = $this->prefix . '.' . $namespace . '.' . $key . '*';

        $keys = $this->mcache->keys($lookup);

        foreach ($keys as $key => $cacheKey) {
            $this->mcache->del($cacheKey);
        }

        $keys = $this->mcache->keys($lookup);

        if(empty($keys)) return true;
        return false;
    }

    function exists($namespace, $key)
    {
        if ( ! isset($this) ) {
            error_log("error: ".__CLASS__ . " was used statically, please call get_instance() and use that object instead");
            return false;
        }

        if (!$this->is_connected && ! $this->connect() ) return false;

        $lookup = $this->prefix . "." . $namespace . "." . $key;

        if ($this->mcache->exists($lookup)) return true;
        return false;
    }
}
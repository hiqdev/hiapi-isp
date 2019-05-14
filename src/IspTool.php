<?php

namespace hiapi\isp;

use hiapi\isp\exceptions\InvalidConfigException;
use hiapi\isp\modules\AbstractModule;
use hiapi\isp\modules\DomainModule;

class IspTool
{
    /** @var array tool configuration */
    protected $config;

    protected $url;
    protected $login;
    protected $password;
    protected $customer_id;
    protected $default_nss = ['ns1.topdns.me', 'ns2.topdns.me'];

    protected $modules = [
        'domain'    => DomainModule::class,
    ];

    /**
     * TODO: remove from construct
     */
    protected $base;

    public function __construct($base = null, array $config)
    {
        echo 'hello!';
        $this->checkConfig($config);
        $this->initTool($config);
    }

    private function checkConfig(array $config): void
    {
        foreach (['login','password'] as $field) {
            if (!empty($config[$field])) {
                continue;
            }
            throw new InvalidConfigException("`$field` must be given for IspTool");
        }
    }

    private function initTool(array $config): void
    {
        $fields = array_keys(get_object_vars($this));

        foreach ($fields as $field) {
            if (key_exists($field, $config)) {
                $this->{$field} = $config[$field];
            }
        }
    }

    /**
     * @param string $command
     * @param array $args
     * @return array
     */
    public function __call(string $command, array $args): array
    {
        $parts = preg_split('/(?=[A-Z])/', $command);
        $entity = reset($parts);
        $module = $this->getModule($entity);

        return call_user_func_array([$module, $command], $args);
    }

    public function getModule(string $name): AbstractModule
    {
        if (empty($this->modules[$name])) {
            throw new InvalidCallException("module `$name` not found");
        }
        $module = $this->modules[$name];
        if (!is_object($module)) {
            $this->modules[$name] = $this->createModule($module);
        }

        return $this->modules[$name];
    }
}

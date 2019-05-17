<?php

namespace hiapi\isp;

use hiapi\isp\exceptions\InvalidCallException;
use hiapi\isp\exceptions\InvalidConfigException;
use hiapi\isp\exceptions\IspToolException;
use hiapi\isp\helpers\ErrorHelper;
use hiapi\isp\modules\AbstractModule;
use hiapi\isp\modules\DomainModule;

class IspTool
{
    /** @var array tool configuration */
    protected $config;

    protected $base;

    private $url;
    private $login;
    private $password;
    private $customer_id;
    private $default_nss = ['ns1.topdns.me', 'ns2.topdns.me'];

    /** @var IspClient */
    private $client;

    protected $modules = [
        'domain'    => DomainModule::class,
    ];


    public function __construct($base, array $config = [])
    {
        $this->base = $base;
        $this->checkConfig($config);
        $this->initTool($config);
    }

    /**
     * @param array $config
     */
    private function checkConfig(array $config): void
    {
        foreach (['login','password'] as $field) {
            if (!empty($config[$field])) {
                continue;
            }
            throw new InvalidConfigException("`$field` must be given for IspTool");
        }
    }

    /**
     * @param array $config
     */
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

    /**
     * @param string $name
     * @return AbstractModule
     */
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

    /**
     * @param string $class
     * @return AbstractModule
     */
    public function createModule(string $class): AbstractModule
    {
        return new $class($this);
    }

    /**
     * @param array $data
     * @param string $addUrl
     * @return mixed
     */
    public function request (array $data, string $addUrl = 'manager/billmgr')
    {
        if (ErrorHelper::is($data)) {
            throw new IspToolException();
        }
        $url = $this->url . $addUrl;
        if (!$url) {
            throw new IspToolException('wrong url');
        }
        if (!$data || !is_array($data)) {
            throw new IspToolException('wrong format');
        }
        $data = $this->prepareRequest($data);
        $res = $this->client->request($url, $data);

        return $this->prepareRequestAnswer($res, $data['out']);
    }

    /**
     * @param array $data
     * @return array
     */
    public function prepareRequest (array $data): array
    {
        $data['sok'] = $data['sok'] ?? 'ok';
        $data['authinfo'] = "{$this->login}:{$this->password}";
        $data['out'] = $data['out'] ?? 'json';

        return $data;
    }

    /**
     * @return IspClient
     */
    public function getIspClient(): IspClient
    {
        if ($this->client === null) {
            $guzzle = new \GuzzleHttp\Client(['base_uri' => $this->url]);
            $this->client = new IspClient($guzzle);
        }

        return $this->client;
    }
}

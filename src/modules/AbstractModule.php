<?php

namespace hiapi\isp\modules;

use hiapi\isp\IspTool;

class AbstractModule
{
    /** @var IspTool */
    public $tool;

    public $base;

    private $constcodes;

    public function __construct(IspTool $tool)
    {
        $this->tool = $tool;
        $this->base = $tool->getBase();
    }

    protected function getRegistrar($tld)
    {
        return $this->getRecordValue($tld, 'registrar');
    }

    protected function getRecordValue($tld, $type)
    {
        $data = $this->getRecord($tld);
        if (empty($data[$type])) {
            throw new IspToolException("no domain {$type} found");
        }

        return $data[$type];
    }

    protected function getRecord($tld)
    {
        if (empty($this->constcodes)) {
            $this->setConstCodes();
        }

        if (empty($this->constcodes)) {
            throw new IspToolException('no codes found for registrar');
        }

        if (empty($this->constcodes[$tld])) {
            throw new IspToolException('no codes found for TLD');
        }

        return $this->constcodes[$tld];
    }

    protected function setConstCodes()
    {
        $cache = $this->base->di->get('cache');
        $registrar = 13;
        $data = $cache->getOrSet([__METHOD__, $this->url], function() use ($registrar) {
            $data = file_get_contents("{$this->url}/manimg/userdata/json/domainprice_ru.json");
            $data = json_decode($data, true);
            $result = [];
            foreach ($data as $d) {
                $period = reset($d['period']);
                $res[$d['tld']][$d['registrar_id']] = [
                    'price' => $d['id'],
                    'period' => $period['id'],
                    'registrar' => $d['registrar_id'],
                ];
                if (idn_to_ascii($d['tld']) !== $d['tld']) {
                    $res[idn_to_ascii($d['tld'])][$d['registrar_id']] = $res[$d['tld']][$d['registrar_id']];
                }
            }

            foreach ($res as $tld => $registrarData) {
                foreach ($registrarData[$registrar] as $key => $data) {
                    $result[$tld][$key] = $data;
                }
            }

            return $result;
        }, 24 * 3600);

        $this->constcodes = $data;
    }
}

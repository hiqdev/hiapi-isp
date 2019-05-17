<?php

namespace hiapi\isp\modules;

use hiapi\isp\exceptions\IspToolException;
use hiapi\isp\helpers\ArrayHelper;
use hiapi\isp\helpers\ErrorHelper;

class HostModule extends AbstractModule
{
    public function hostCreate(array $row)
    {
        throw new IspToolException('unsupported zone');
    }

    public function hostsCreate(array $rows)
    {
        throw new IspToolException('unsupported zone');
    }

    public function hostSet(array $row): array
    {
        $host = $row['host'];
        $domain = $row['domain'] ?: substr($host, strpos($host, '.') + 1);
        $nss = $this->tool->_prepareNSs([
            'domain' => $domain,
            'nss'    => ArrayHelper::get($this->base->domainGetNSs(compact('domain')), 'nss'),
        ]);

        $tmp_nss[$host] = $host . '/' . (!empty($row['ips']) ? implode(",", $row['ips']) : $row['ip']);
        if (count($tmp_nss) < 2) $tmp_nss['ns1.topdns.me'] = 'ns1.topdns.me';
        $res = $this->tool->domainUpdate([
            'domain' => $domain,
            'nss'    => $tmp_nss,
        ]);
        if (ErrorHelper::is($res)) {
            throw new IspToolException();
        }
        return $this->tool->domainUpdate([
            'domain' => $domain,
            'nss'    => $nss ?: [],
        ]);
    }
}

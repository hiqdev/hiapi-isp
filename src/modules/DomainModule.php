<?php

namespace hiapi\isp\modules;

use hiapi\isp\exceptions\IspToolException;
use hiapi\isp\helpers\ArrayHelper;
use hiapi\isp\helpers\ErrorHelper;
use hiapi\legacy\lib\deps\arr;
use hiapi\legacy\lib\deps\cfg;
use hiapi\legacy\lib\deps\err;
use hiapi\legacy\lib\deps\format;
use hiapi\legacy\lib\deps\retrieve;
use InvalidArgumentException;

class DomainModule extends AbstractModule
{
    /**
     * @param array $row
     * @return array
     */
    public function _domainPrepareData(array $row)
    {
        $registrant = $this->_domainPrepareRegistrant($row);
        return $this->_domainSetRegistrant($row, $registrant);
    }

    /**
     * @param array $row
     * @return array
     */
    public function _prepareNSs(array $row): array
    {
        $domain = $row['domain'];
        $nss = [];
        foreach (arr::csplit($row['nss']) as $host) {
            if (substr($host, -strlen($domain)) == $domain) {
                $my_nss[$host] = $host;
            } else {
                $nss[$host] = $host;
            }
        }
        if ($my_nss) {
            $his = $this->base->hostsGetInfo(arr::make_sub($my_nss, 'host'));
            if (err::is($his)) {
                return $his;
            }
            foreach ($his as $k => $v) {
                $nss[$v['host']] = retrieve::punyCode($v['host']) . "/$v[ips]";
            }
        }

        return $nss;
    }

    /**
     * @param array $row
     * @return array
     */
    public function _domainGetRegistrant(array $row): array
    {
        return $this->base->domainGetContacts(arr::mget($row, 'domain'))['registrant']['remote'];
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainRegister(array $row): array
    {
        $row['name'] = substr($row['domain'], 0, strpos($row['domain'], '.'));
        if (strlen($row['name']) < 2) {
            $message = 'according to rules of zone registration is impossible';
            throw new InvalidArgumentException($message);
        }
        if (!$this->domainCheck($row)) {
            throw new IspToolException('domain already exists');
        }
        $row = $this->_domainPrepareData($row);
        if (ErrorHelper::is($row)) {
            return $row;
        }
        if (cfg::get('ONLY_CREATE_CONTACT')) {
            throw new IspToolException('CONTACT CREATED');
        }
        if (!$this->getRecord($row['zone'])) {
            throw new IspToolException('wrong zone');
        }
        $request_data = [
            'contact'       => $row['registrant'],
            'owner'         => $row['registrant'],
            'registrar'     => $this->getRegistrar($row['zone']),
            'countdomain'   => 1,
            'domain'        => $row['name'],
            'domainname_0'  => $row['name'],
            'domainpropind' => 0,
            'lang'          => $row['zone'],
            'tld'           => $row['zone'],
            'nslist_0'      => ArrayHelper::join($row['nss'], ' '),
            'projectns'     => ArrayHelper::join($row['nss'], ' '),
            'func'          => 'domain.order.4',
            'operation'     => 'register',
            'paynow'        => 'on',
            'price'         => $this->getPrice($row['zone']),
            'pricelist_0'   => $this->getPrice($row['zone']),
            'period_0'      => $this->getPeriod($row['zone']),
        ];
        $res = $this->tool->request($request_data);
        if (!$res['item.id']) {
            throw new IspToolException('no remote id');
        }
        $row['remoteid'] = $res['item.id'];
        $res = $this->tool->domainGetInfo($row, true);
        if (ErrorHelper::is($res)) {
            throw new IspToolException();
        }
        $this->base->domainSetInfo($row);
        return $row;
    }

    public function domainsRegister(array $rows): array
    {
        foreach ($rows as $key => $row) {
            $res[$key] = $this->domainRegister($row);
        }

        return err::reduce($res);
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainTransfer(array $row): array
    {
        $row['name'] = substr($row['domain'], 0, strpos($row['domain'], '.'));
        if (strlen($row['name']) < 2) {
            throw new IspToolException('according to rules of zone registration is inpossible');
        }
        if ($this->domainCheck($row)) {
            throw new IspToolException($row, 'domain does not exist');
        }
        $row = $this->_domainPrepareData($row);
        if (err::is($row)) {
            return $row;
        }
        $request_data = [
            'contact'       => $row['registrant'],
            'owner'         => $row['registrant'],
            'registrar'     => $this->getRegistrar($row['zone']),
            'countdomain'   => 1,
            'domain'        => $row['name'],
            'domainname_0'  => $row['name'],
            'domainpropind' => 0,
            'lang'          => $row['zone'],
            'tld'           => $row['zone'],
            'nslist_0'      => arr::cjoin($row['nss'], " "),
            'projectns'     => arr::cjoin($row['nss'], " "),
            'period_0'      => 1,
            'func'          => 'domain.order.4',
            'operation'     => 'transfer',
            'paynow'        => 'on',
            'price'         => $this->getPrice($row['zone']),
            'pricelist_0'   => $this->getPrice($row['zone']),
            'period_0'      => $this->getPeriod($row['zone']),
            'auth_code'     => $row['password'],
        ];
        $res = $this->tool->request($request_data);
        if (err::is($res)) {
            throw new IspToolException();
        }
        if (!$res['item.id']) {
            throw new IspToolException();
        }
        $row['remoteid'] = $res['item.id'];
        $res = $this->domainGetInfo($row, true);
        if (err::is($res)) return $res;
        $this->base->domainSetInfo($row);

        return $row;
    }

    /**
     * @param array $rows
     * @return array
     */
    public function domainsTransfer(array $rows): array
    {
        foreach ($rows as $key => $row) {
            $res[$key] = $this->domainTransfer($row);
        }

        return err::reduce($res);
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainCheckTransfer(array $row): array
    {
        $info = $this->base->domainGetWhois($row);
        if (err::is($info)) {
            throw new IspToolException('domain does not exist');
        }
        if ($info['transferto']) {
            throw new IspToolException('domain is already being transfered');
        }
        if (strtotime('+8 days', time()) > strtotime($info['expires'])) {
            throw new IspToolException('transfer is inpossible. Please renew domain first');
        }

        return $row;
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainRenew(array $row): array
    {
        $domainInfo = $this->base->domainGetInfo($row);
        return $this->tool->request([
            'func'       => 'domain.renew',
            'elid'       => $domainInfo['remoteid'],
            'autoperiod' => $this->getPeriod(retrieve::zone($row['domain'])),
            'paynow'     => 'on',
        ]);
    }

    /**
     * @param array $rows
     * @return array
     */
    public function domainsRenew(array $rows): array
    {
        $res = [];
        foreach ($rows as $key => $row) {
            $res[$key] = $this->domainRenew($row);
        }

        return err::reduce($res);
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainUpdate(array $row): array
    {
        $row = $this->_domainPrepareData($row);
        $domainInfo = $this->base->domainGetInfo($row);
        if (err::is($domainInfo)) {
            throw new IspToolException();
        }
        $row['nss'] = $this->_prepareNSs($row);
        $nses = [];
        for ($i = 0; $i < 4; $i++) {
            $nses["ns$i"] = !empty($row['nss']) ? array_shift($row['nss']) : '';
        }

        return $this->tool->request(array_merge([
            'func'       => 'domain.edit',
            'elid'       => $domainInfo['remoteid'],
            'owner'      => $row['registrant'],
            'tld'        => retrieve::zone($row['domain']),
            'name'       => $domainInfo['name'],
            'dopns'      => '',
            'dopns_list' => '',
            'autoperiod' => '',
            'changes'    => 'on',
            'sok'        => 'ok',
            'changens'   => 'on',
        ], $nses));
    }

    /**
     * @param array $rows
     */
    public function domainsUpdate(array $rows)
    {
        $res = [];
        foreach ($rows as $key => $row) {
            $res[$key] = $this->domainUpdate($row);
        }

        return err::reduce($res);
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainSetNSs(array $row): array
    {
        return $this->domainUpdate($row);
    }

    /**
     * @param array $rows
     */
    public function domainsSetNSs(array $rows)
    {
        $res = [];
        foreach ($rows as $key => $row) {
            $res[$key] = $this->domainSetNSs($row);
        }

        return err::reduce($res);
    }

    /**
     * @param array $row
     */
    public function domainSetContacts(array $row)
    {
        throw new IspToolException('domain contacts could not be changed');
    }

    /**
     * @param array $rows
     * @return array
     */
    public function domainsSetContacts(array $rows)
    {
        $res = [];
        foreach ($rows as $key => $row) {
            $res[$key] = $this->domainSetContacts($row);
        }

        return err::reduce($res);
    }

    /**
     * @param array $row
     * @param bool $force
     * @return array
     */
    public function domainGetInfo(array $row, $force = false)
    {
        $remoteid = $row['remoteid'];
        $_row = $this->base->domainGetInfo($row);
        if (err::not($_row)) {
            $row = $_row;
        }
        $row['remoteid'] = $row['remoteid'] ?: $remoteid;
        $res = $this->tool->request(array_filter([
            'func'  => 'domain.getinfo',
            'dname' => $row['remoteid'] ? null : $row['domain'],
            'elid'  => $row['remoteid'] ?: null,
            'out'   => 'json',
        ]), 'manager/billmgr');
        if (err::is($res)) {
            throw new IspToolException('domain is not registered through our tool');
        }

        $whoisInfo = $this->base->domainGetWhois($row);

        for ($i = 0; $i < 4; $i++) {
            if ($res["ns$i"]) {
                $nses[$i] = $res["ns$i"];
                if (preg_match("/" . retrieve::punyCode($res['name']) . "/i", $res["ns$i"])) {
                    $hosts[$i] = $nses[$i];
                }
            }
        }

        if (is_array($whoisInfo['nss'])) {
            foreach ($whoisInfo['nss'] as $ns => $nsIP) {
                $nssWhois[] = $ns;
                if (preg_match("/" . retrieve::punyCode($row['domain']) . "/i", $ns)) {
                    $hostWhois[$ns] = "$ns/$nsIP";
                }
            }
        }

        if (!$force && ($res['expire'] == 'on') && ($this->domainStatuses[$res['domainstatus']] != 'incoming')) {
            throw new IspToolException('load failed. try later');
        }

        if (strtotime($res['expire']) < strtotime('-1 year', time())) {
            $res['orig_expire'] = $res['expire'];
            unset($res['expire']);
        }

        return [
            'id'              => $res['elid'] ?: $res['id'],
            'remoteid'        => $res['elid'] ?: $res['id'],
            'domain'          => retrieve::punyCode($res['name']) . "." . retrieve::punyCode($res['tld']),
            'nameservers'     => arr::cjoin($nses) ?: arr::cjoin($nssWhois),
            'created_date'    => $whoisInfo['created'] ?: ($row['since'] ? (date("Y", strtotime($row['since'])) . date("-m-d", strtotime($res['expire']))) : date("Y-m-d", strtotime("-1 year", strtotime($res['expire'])))),
            'expiration_date' => format::date($res['expire'] ?: ($whoisInfo['expires'] ?: $res['orig_expire']), 'iso'),
            'state'           => $this->domainStatuses[$res['domainstatus']],
            'hosts'           => arr::cjoin($hosts) ?: arr::cjoin($hostWhois),
            'statuses'        => $this->domainStatuses[$res['domainstatus']],
            'transferto'      => $whoisInfo['transferto'],
        ];
    }

    /**
     * @param array $rows
     * @return array
     */
    public function domainsGetInfo(array $rows)
    {
        $res = [];
        foreach ($rows as $key => $row) {
            $res[$key] = $this->domainGetInfo($row);
        }

        return err::reduce($res);
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainInfo(array $row)
    {
        return $this->domainGetInfo($row);
    }

    /**
     * @param $rows
     * @return array
     */
    public function domainsInfo(array $rows)
    {
        return $this->domainsGetInfo($rows);
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainLoadInfo(array $row)
    {
        return $row;
    }

    /**
     * @param array $rows
     * @return array
     */
    public function domainsLoadInfo(array $rows)
    {
        return $rows;
    }

    /**
     * @param array $row
     * @return bool
     */
    public function domainSaveContacts(array $row)
    {
        return true;
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainSetPassword(array $row)
    {
        return $row;
    }

    /**
     * @param array $rows
     */
    public function domainsEnableLock(array $rows)
    {
        throw new IspToolException('unsupported for this zone');
    }

    /**
     * @param array $rows
     */
    public function domainsDisableLock(array $rows)
    {
        throw new IspToolException('unsupported for this zone');
    }

    /**
     * @param array $row
     * @return bool
     */
    public function domainCheck(array $row): bool
    {
        $name = substr($row['domain'], 0, strpos($row['domain'], '.'));
        if (strlen($name) < 2) {
            return 0;
        }
        $rc = $this->tool->request(array_merge($row, ['sok' => '', 'out' => 'xml']), "mancgi/domaininfo");
        if (ErrorHelper::is($rc)) {
            return $rc;
        }

        return $rc['status'] == 'free' ? 1 : 0;
    }

    /**
     * @param array $jrow
     * @return array
     */
    public function domainsCheck(array $jrow)
    {
        $res = [];
        foreach ($jrow['domains'] as $domain) {
            $data = $this->base->_domainStartProfile(compact('domain'), 'domainCheck');
            $check = $this->domainCheck($data);

            $log = ['tool' => 'isp'];
            if ($check !== 0 && err::is($check)) {
                $log['_error'] = err::get($check);
            } else {
                $log['result'] = (string)$check;
            }
            $this->base->_profilingEnd($data, $log);

            $res[$domain] = err::is($check) ? null : $check;
        };

        return err::reduce($res);
    }

    /**
     * @param array $jrow
     * @return mixed
     */
    public function domainsGetFullList(array $jrow)
    {
        return $this->tool->request([
            'func' => 'domain',
            'out'  => 'json',
            'sok'  => 'ok',
        ], 'manager/billmgr');
    }

    /**
     * @param array $jrow
     * @return mixed
     */
    public function domainsCleanGoneRu(array $jrow)
    {
        $res = $this->domainsGetFullList($jrow);
        if (err::is($res)) {
            throw new IspToolException();
        }

        return $res['elem'] ?: $res;
    }

    /**
     * @param array $row
     * @return array
     */
    public function domainDelete(array $row)
    {
        return err::set($row, 'command does not supportet in zone ' . retrieve::zone($row['domain']));
    }

    /**
     * @param array $rows
     * @return array
     */
    public function domainsDelete(array $rows)
    {
        foreach ($rows as $id => $row) {
            $res[$id] = $this->domainDelete($row);
        }

        return err::reduce($res);
    }


    /**
     * @param array $row
     * @return mixed
     */
    protected function _domainPrepareRegistrant(array $row)
    {
        $cinfo = $this->base->domainGetContactsInfo(ArrayHelper::extract($row, ['domain', 'id']));
        if (ErrorHelper::is($cinfo)) {
            throw new IspToolException();
        }
        $data = ArrayHelper::has($row, 'whois_protected') ? $row : $cinfo;
        $row['whois_protected'] = (bool)ArrayHelper::get($data, 'whois_protected');
        $id = $row['registrant'] ?: $cinfo['registrant']['id'];
        if (!$id) {
            throw new IspToolException('wrong registrant');
        }

        return $this->base->contactGetInfo(compact('id'));
    }

    /**
     * @param array $row
     * @param array $registrant
     * @return array
     */
    protected function _domainSetRegistrant(array $row, array $registrant): array
    {
        $remoteid = $registrant['remote'];
        if (!$remoteid || !$this->tool->contactExists(compact('remoteid'))) {
            $contact = $this->tool->contactCreate($registrant);
            if (ErrorHelper::is($contact)) {
                throw new IspToolException();
            }
            $row['registrant'] = $contact['id'];
        } else {
            $contactExists = $this->tool->_contactExists(compact('remoteid'));
            if ($contactExists['error']) {
                if ($contactExists['error']['code'] != 8) {
                    throw new IspToolException($contactExists['error']['msg']);
                }
            } else {
                $contact = $this->tool->contactUpdate($registrant);
                if (ErrorHelper::is($contact)) {
                    throw new IspToolException();
                }
            }
            $row['registrant'] = $registrant['remote'];
        }
        $row['zone'] = retrieve::zone($row['domain']);

        return $row;
    }
}

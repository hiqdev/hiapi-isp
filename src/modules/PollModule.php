<?php

namespace hiapi\isp\modules;

use hiapi\isp\helpers\ErrorHelper;

class PollModule extends AbstractModule
{
    /**
     * @return array
     */
    private function _pollGetConfig(): array
    {
        return [
            'incoming'         => [
                'cond'   => "
                    AND     (zo.create_time < now() - 5 * '1 day'::interval AND (zs.time < now() - '1 hour'::interval OR zs.time IS NULL))
                ",
                'states' => [
                    'ok'      => [
                        'type'    => "serverApproved",
                        'message' => "Transfer completed",
                    ],
                    'deleted' => [
                        'type'    => "clientRejected",
                        'message' => "Transfer rejected",
                    ],
                ],
            ],
            'preincoming'      => [
                'cond'      => "
                    AND     ((zs.time < now() - '1 hour'::interval OR zs.time IS NULL) OR zd.remoteid IS NULL)
                ",
                'states'    => [
                    'ok'       => [
                        'type'        => "pending",
                        'message'     => "Transfer requested",
                        'action_date' => date("Y-m-d H:i:s", strtotime('+5 days', time())),
                    ],
                    'incoming' => [
                        'type'        => "pending",
                        'message'     => "Transfer requested",
                        'action_date' => date("Y-m-d H:i:s", strtotime('+5 days', time())),
                    ],
                    'deleted'  => [
                        'type'    => "clientRejected",
                        'message' => "Transfer rejected",
                    ],
                ],
                'prefunc'   => function ($row) {
                    if (!$row['remoteid']) {
                        $res = $this->base->domainsApprovePreincoming(['domains' => $row['domain']]);
                        if (ErrorHelper::not($res)) {
                            $this->base->dbc->exec("UPDATE domain SET state_id = state_id('domain,preincoming') WHERE obj_id = {$row['obj_id']}");
                        }
                        return true;
                    }
                    return false;
                },
                'postcheck' => function ($row) {
                    return ((bool)$row['transferto']) || $row['state'] === 'deleted';
                },
                'postfunc'  => function ($info, $id) {
                    $this->base->dbc->exec("UPDATE domain SET state_id = state_id('domain,incoming') WHERE obj_id = $id");
                },
            ],
            'checked4deleting' => [
                'cond'     => "
                    AND     zd.expires < now() - '1 month'::interval
                    AND     (zs.time < now() - '1 hour'::interval OR zs.time IS NULL)
                ",
                'states'   => [
                    'deleted' => [
                        'type'        => "pendingDelete",
                        'message'     => "Domain deleted",
                        'action_date' => date("Y-m-d H:i:s", strtotime('+1 days', time())),
                    ],
                ],
                'postfunc' => function ($info, $id) {
                    $this->base->dbc->exec("UPDATE domain SET state_id = state_id('domain,deleted') WHERE obj_id = $id");
                },
            ],
        ];

    }

    /**
     * @param string $status
     * @param string $cond
     * @return array
     */
    private function _pollsFind(string $status, string $cond): array
    {
        $states = $status === 'checked4deleting' ? 'expired,deleting' : $status;
        $statusFunc = $status === 'checked4deleting' ? 'status_id' : 'state_id';
        return $this->base->dbc->hashrows("
            SELECT      zd.obj_id,coalesce(zd.idn,zd.domain) as domain, zd.remoteid, zo.create_time, dz.name AS state
            FROM        domainz     zd
            JOIN        obj         zo ON zo.obj_id=zd.obj_id AND zo.class_id=class_id('domain')
            LEFT JOIN   zone        zi ON zi.obj_id = zd.zone_id
            LEFT JOIN   status      zs ON zs.object_id = zd.obj_id AND zs.type_id = $statusFunc('domain,$status')
            JOIN        ref         dz ON dz.obj_id=zd.state_id
            JOIN        zclient     zc ON zc.obj_id=zd.client_id
            WHERE       TRUE
                AND     zd.access_id = access_id('isp@evoplus')
                AND     (zi.name IN ('.ru', '.su') OR zi.idn = '.рф')
                AND     zd.state_id=ANY(state_ids('domain','$states'))
                $cond
        ");
    }

    /**
     * @param array $row
     * @param array $data
     * @return array
     */
    private function _pollBuild(array $row, array $data): array
    {
        return array_merge([
            'class'          => 'domain',
            'name'           => $row['domain'],
            'request_client' => 'ARDIS-RU',
            'request_date'   => date("Y-m-d H:i:s"),
            'action_client'  => 'ARDIS-RU',
            'outgoing'       => false,
            'action_date'    => date("Y-m-d H:i:s"),
        ], $data);
    }

    /**
     * @param string $status
     * @param array $params
     * @return bool|array
     */
    private function _pollsGetNew(string $status, array $params)
    {
        $domains = $this->_pollsFind($status, $params['cond']);
        if (!$domains) return true;
        $status_id = $this->base->dbc->value("SELECT " . ($status === 'checked4deleting' ? "status_id" : 'state_id') . "('domain,$status')");
        foreach ($domains as $id => $row) {
            if (array_key_exists('prefunc', $params)) {
                if ($params['prefunc']($row)) continue;
            }
            $info = $this->domainGetInfo($row, true);
            if (array_key_exists('postcheck', $params)) {
                $check = $params['postcheck']($info);
            } else $check = true;
            if (ErrorHelper::not($info) && (in_array($info['state'], array_keys($params['states']))) && $check) {
                $polls[] = $this->_pollBuild($row, $params['states'][$info['state']]);
                if (array_key_exists('postfunc', $params)) $params['postfunc']($info, $id);
            } else {
                $this->base->dbc->exec("SELECT set_status($id, $status_id, now())");
            }
        }

        return $polls ?? true;
    }

    /**
     * @param array $jrow
     * @return bool
     */
    public function pollsGetNew(array $jrow)
    {
        $pollsType = $this->_pollGetConfig();
        $id = $jrow['since_id'];
        foreach ($pollsType as $type => $params) {
            $polls = $this->_pollsGetNew($type, $params);
            if (($polls === true) || (!$polls)) continue;
            foreach ($polls as $poll) {
                $id++;
                $result[$id] = $poll;
                $result[$id]['id'] = $id;
            }
        }

        return $result ?? true;
    }
}

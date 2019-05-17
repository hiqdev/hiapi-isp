<?php

namespace hiapi\isp\modules;

use hiapi\isp\exceptions\IspToolException;
use hiapi\isp\helpers\ArrayHelper;
use hiapi\isp\helpers\ErrorHelper;
use hiapi\legacy\lib\deps\format;
use hiapi\legacy\lib\deps\retrieve;

class ContactModule extends AbstractModule
{
    /**
     * @param array $row
     * @return array
     */
    protected function _contactPreparePhones(array $row): array
    {
        foreach (['fax_phone', 'voice_phone'] as $part) {
            if (!$row[$part]) continue;
            $phone = str_replace('.', '', $row[$part]);
            $row[$part] = format::e123($phone);
        }

        return $row;
    }

    /**
     * @return array
     */
    protected function _contactCountriesCodes(): array
    {
        $countries = json_decode(file_get_contents('https://ardis.ru/json/country.json'), true);
        $countryCodes = [];
        foreach ($countries['elem'] as $value) {
            $key = $value['iso2'] === 'HK' && $value["id"] == "45"
                ? 'CN'
                : $value['iso2'];
            $countryCodes[$key] = $value['id'];
        }
        if (empty($countryCodes)) {
            throw new IspToolException('could not get data');
        }

        return $countryCodes;
    }

    /**
     * @param array $row
     * @return array
     */
    protected function _contactPrepare(array $row): array
    {
        $this->countryCodes = ErrorHelper::is($this->countryCodes)
            ? $this->_contactCountriesCodes()
            : $this->countryCodes;
        if (ErrorHelper::is($this->countryCodes)) {
            throw new IspToolException('could not get country code');
        }
        $row['country_code'] = $this->countryCodes[strtoupper($row['country'])];
        if (!$row['country_code']) {
            throw new IspToolException('wrong country');
        }
        $row = $this->_contactPreparePhones($row);
        $street = [];
        foreach (['street1', 'street2', 'street3'] as $key) {
            if (!isset($row[$key])) {
                continue;
            }
            $row[$key] = trim($row[$key]);
            if (isset($street[$row[$key]])) {
                continue;
            }
            $street[$row[$key]] = $row[$key];
        }
        $row['street'] = trim(ArrayHelper::join($street, ' '));

        foreach (['first_name', 'last_name'] as $key) {
            $row[$key] = preg_replace('/[^A-Za-z]/', '', $row[$key]);
        }

        return $row;
    }

    /**
     * @param array $row
     * @return bool
     */
    public function contactExists(array $row): bool
    {
        if (!$row['remoteid']) {
            return false;
        }
        $res = $this->_contactExists($row);

        return $res['error']
            ? $res['error']['code'] == 8
            : true;
    }

    /**
     * @param array $row
     * @param string $type
     * @return array
     */
    private function _contactCreate(array $row, $type = 'org'): array
    {
        $res = $this->tool->request([
            'func'  => 'contcat.create.1',
            'ctype' => $type == 'person' ? 'person' : 'company',
            'cname' => $row['id'],
        ]);

        if (ErrorHelper::is($res)) {
            throw new IspToolException();
        }
        $row = array_merge($row, ['remoteid' => $res['domaincontact.id']]);
        $res = $this->base->_contactSet($row);

        return ErrorHelper::is($res)
            ? $res
            : $this->{"contactUpdate" . ucfirst($type)}($row);
    }

    /**
     * @param array $row
     * @return array
     */
    protected function _contactExists(array $row): array
    {
        return $this->tool->request([
            'func' => 'domaincontact.edit',
            'elid' => $row['remoteid'],
            'out'  => 'json',
            'sok'  => '',
        ]);
    }

    /**
     * @param array $row
     * @return array
     */
    public function contactCreateOrg(array $row): array
    {
        return $this->_contactCreate($row, 'org');
    }

    /**
     * @param $row
     * @return mixed
     */
    public function contactUpdateOrg($row)
    {
        $row = $this->_contactPrepare($row);

        return $this->tool->request([
            'func'         => 'domaincontact.edit',
            'ctype'        => 'company',
            'elid'         => $row['remote_id'] ?: $row['remoteid'] ?: $row['remote'],
            'company'      => retrieve::transliteration($row['organization_ru']),
            'company_ru'   => $row['organization_ru'],
            'la_country'   => $row['country_code'],
            'email'        => $row['email'],
            'fax'          => $row['fax_phone'],
            'inn'          => $row['inn'],
//            'kpp'           => $row['kpp'],
            'phone'        => $row['voice_phone'],
            'mobile'       => $row['voice_phone'],
            'la_address'   => $row['street'],
            'la_city'      => $row['city'],
            'la_postcode'  => $row['postal_code'],
            'la_state'     => $row['province'],
            'pa_address'   => $row['street'],
            'pa_addressee' => $row['street'],
            'pa_city'      => $row['city'],
            'pa_postcode'  => $row['postal_code'],
            'pa_state'     => $row['province'],
            'pa_country'   => $row['country_code'],
        ]);
    }

    /**
     * @param array $row
     * @return array
     */
    public function contactCreatePerson(array $row): array
    {
        return $this->_contactCreate($row, 'person');
    }

    /**
     * @param array $row
     * @return array
     */
    public function contactUpdatePerson(array $row): array
    {
        $row = $this->_contactPrepare($row);
        return $this->tool->request([
            'func'            => 'domaincontact.edit',
            'ctype'           => 'person',
            'elid'            => $row['remote_id'] ?: $row['remoteid'] ?: $row['remote'],
            'firstname_ru'    => $row['first_name'],
            'middlename_ru'   => "-",
            'lastname_ru'     => $row['last_name'],
            'firstname'       => $row['first_name'],
            'middlename'      => "-",
            'lastname'        => $row['last_name'],
            'birthdate'       => $row['birth_date'],
            'phone'           => $row['voice_phone'],
            'mobile'          => $row['voice_phone'],
            'fax'             => $row['fax_phone'],
            'email'           => $row['email'],
            'inn'             => $row['inn'],
            'orgn'            => $row['inn'],
            'private'         => $row['whois_protected'] ? 'on' : 'off',
            'la_address'      => $row['street'],
            'la_city'         => $row['city'],
            'la_postcode'     => $row['postal_code'],
            'la_state'        => $row['province'],
            'la_country'      => $row['country_code'],
            'pa_address'      => $row['street'],
            'pa_addressee'    => $row['street'],
            'pa_city'         => $row['city'],
            'pa_postcode'     => $row['postal_code'],
            'pa_state'        => $row['province'],
            'pa_country'      => $row['country_code'],
            'passport_series' => $row['passport_no'],
            'passport_org'    => $row['passport_by'],
            'passport_date'   => $row['passport_date'],
        ]);
    }

    /**
     * @param array $row
     * @return array
     */
    public function contactSet(array $row): array
    {
        if (ErrorHelper::is($row)) {
            throw new IspToolException();
        }
        $data = array_merge($row, $this->base->contactCheckCompatibleRU($row));
        if (ErrorHelper::is($data)) {
            throw new IspToolException();
        }
        $data['remoteid'] = $data['remote'];
        $op = ($row['remote'] && $this->contactExists($data))
            ? 'Update'
            : 'Create';

        return $this->{'contact' . $op . strtoupper($data['type'])}($data);
    }

    /**
     * @param array $row
     * @return array
     */
    public function contactCreate(array $row): array
    {
        return $this->contactSet($row);
    }

    /**
     * @param array $row
     * @return array
     */
    public function contactUpdate(array $row): array
    {
        return $this->contactSet($row);
    }

    /**
     * @param array $row
     * @return array
     */
    public function contactSave(array $row): array
    {
        return $this->contactSet($row);
    }
}

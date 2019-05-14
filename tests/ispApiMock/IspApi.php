<?php

class IspApi
{
    private $command;

    private $params = [];

    /**
     * IspApi constructor.
     * @param string $query
     */
    public function __construct(string $query)
    {
        $this->parseQuery($query);
//
//        var_dump($this->params);
//        var_dump($this->command);
    }

    /**
     * @param string $query
     */
    private function parseQuery(string $query): void
    {
        parse_str($query, $this->params);
        $this->command = array_shift($this->params);
        $this->command = lcfirst(str_replace('.', '', ucwords($this->command, '.')));
    }

    public function run()
    {
        header("Content-type:application/json");
        $this->{$this->command}($this->params);
    }

    private function contcatCreate1(array $params)
    {
        echo '{
            "result" : "OK",
            "domaincontact.id":"1",
            "ok": "",
            "redirect":"location=billmgrfunc=contcat.create.2&authinfo=$LOGIN:$PASSWORD&cname=CONTACT%5FNAME&contactid=$CONTACT_ID&ctype=person;"
         }';
    }

}

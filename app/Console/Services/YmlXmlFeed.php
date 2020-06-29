<?php


namespace App\Console\Services;


use Spatie\ArrayToXml\ArrayToXml;

class YmlXmlFeed
{
    private $name;
    private $company;
    private $url;
    private $shopModel;


    /**
     * YmlXmlFeed constructor.
     * @param $tableNamePrefix  - префикс для таблиц парсинга
     * @param $name             - Короткое название магазина
     * @param $company          - Полное наименование компании, владеющей магазином.
     * @param $url              - URL главной страницы магазина.
     */
    public function __construct($tableNamePrefix, $name, $company, $url)
    {
        $this->name = $name;
        $this->company = $company;
        $this->url = $url;
        $this->shopModel = new ShopModel($tableNamePrefix);
    }

    public function getXml()
    {
        $arrData = [
            'currencies' => $this->getCurrencies(),
            'categories' => $this->getCategories(),
            'offers' => $this->getOffers(),
        ];
        $result = ArrayToXml::convert($arrData, [
            'rootElementName' => 'yml_catalog',
            '_attributes' => [
                'date' => date('Y-m-d H:i'),
            ]
        ], true, 'UTF-8');
        return $result;
    }

    private function getCurrencies() {
        return [
            ['currency' => [
                    '_attributes' => ['id' => 'UAH', 'rate' => 1]
            ]]
        ];
    }

    private function getCategories() {

    }

    private function getOffers() {

    }


}

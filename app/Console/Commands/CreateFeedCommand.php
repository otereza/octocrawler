<?php

namespace App\Console\Commands;

use App\Categories;
use App\Console\Services\ShopModel;
use App\Prods;
use Illuminate\Console\Command;
use Spatie\ArrayToXml\ArrayToXml;

class CreateFeedCommand extends Command
{
    /** @var ShopModel */
    private $shopModel;


    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'feed:create {shop} {company} {url} {xmlFileName?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Создание фида для магазина {shop} на основе спаршенных данных. Если передать путь к файлу, буден сознан файл фида';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $shop = $this->argument('shop');
        $company = $this->argument('company');
        $url = $this->argument('url');
        $xmlFileName = $this->argument('xmlFileName');

        $tableNamePrefix = "shop_{$shop}_";
        $this->shopModel = new ShopModel($tableNamePrefix);

        $arrData = [
            'shop' => [
                'name' => $shop,
                'company' => $company,
                'url' => $url,
                'currencies' => $this->getCurrencies(),
                'categories' => $this->getCategories(),
                'offers' => $this->getOffers(),
            ]
        ];
        $arrToXml = new ArrayToXml($arrData, [
            'rootElementName' => 'yml_catalog',
            '_attributes' => [
                'date' => date('Y-m-d H:i'),
            ]
        ], true, 'UTF-8');
        $xmlFeed = $arrToXml->prettify()->toXml();

        if($xmlFileName) {
            file_put_contents($xmlFileName, $xmlFeed);
        } else {
            echo $xmlFeed;
        }

        return true;
    }

    private function getCurrencies() {
        return [
            ['currency' => [
                '_attributes' => ['id' => 'UAH', 'rate' => 1]
            ]]
        ];
    }

    private function getCategories() {
        $res = [];
        /** @var Categories $model */
        $model = $this->shopModel->get('categories');
        $categories = $model->newQuery()->get(['id','name','parent_id']);
        foreach ($categories as $category) {
            if($category->parent_id == 0) {
                $res[] = [
                    '_attributes' => [
                        'id' => $category->id,
                    ],
                    '_value' => $category->name,
                ];
            } else {
                $res[] = [
                    '_attributes' => [
                        'id' => $category->id,
                        'parentId' => $category->parent_id
                    ],
                    '_value' => $category->name,
                ];
            }
        }
        return ['category' => $res];
    }

    private function getOffers() {
        $res= [];
        /** @var Prods $model */
        $model = $this->shopModel->get('prods');
        $prods = $model->newQuery()->get();
        foreach ($prods as $prod) {
            $offer = [];
            $params = [];
            foreach (json_decode($prod->params, true) as $attr => $val) {
                if($attr == 'Штрихкод') {
                    $offer['barcode'] = $val;
                } elseif($attr == 'Бренд' || $attr == 'Артикул') {
                    //continue;
                } elseif($attr == 'Фасовка') {
                    preg_match('/([\d+\.\,]*)\s*(.*)/u', $val, $matches);
                    if(isset($matches[2])) {
                        $params[] = [
                            '_attributes' => ['name' => $attr, 'unit' => $matches[2]],
                            '_value' => $matches[1] ?? 1
                        ];
                    } else {
                        $params[] = [
                            '_attributes' => ['name' => $attr],
                            '_value' => $matches[1] ?? 1
                        ];
                    }
                } else {
                    $params[] = [
                        '_attributes' => ['name' => $attr],
                        '_value' => $val
                    ];
                }
            }

            $offer['url'] = $prod->url;
            $offer['currencyId'] = $prod->currency;
            $offer['categoryId'] = $prod->category_id;
            $images = explode(';', $prod->images);
            if(!empty($images)) {
                $offer['picture'] = $images;
            }
            $offer['name'] = $prod->title;
            $offer['vendor'] = $prod->vendor;
            if(!empty($prod->description)) {
                $offer['description'] = ['_cdata' => $prod->description];
            }
            if(!empty($params)) {
                $offer['param'] = $params;
            }

            if($prod->special_price) {
                $offer['price'] = $prod->special_price;
                $offer['oldprice'] = $prod->regular_price;
                $offer['dateEnd'] = substr($prod->special_price_end_date, 0, 10);
            } else {
                $offer['price'] = $prod->regular_price;
            }

            $offer['_attributes'] = ['id' => $prod->id];
            $res['offer'][] = $offer;
        }
        return $res;
    }

}

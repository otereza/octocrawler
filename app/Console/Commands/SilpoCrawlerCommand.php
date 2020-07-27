<?php

namespace App\Console\Commands;

use App\Console\Services\ShopModel;
use App\Prods;
use Faker\Provider\DateTime;
use Illuminate\Console\Command;
use GuzzleHttp\Client;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

class SilpoCrawlerCommand extends Command
{
    const SHOP_NAME = 'silpo';
    const SHOP_URL = 'https://shop.silpo.ua';
    const FILIAL_ID = 2734;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:silpo {xmlFileName? : Путь к файлу для сохранения фида} {--echo : Выводить сообщения при выполнении}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Парсинг магазина Silpo';

    /**
     * Класс определения модели нужной таблицы на основе префикса и имени таблицы
     *
     * @var ShopModel
     */
    protected $shopModel;

    /**
     * @var Client
     */
    private $client;


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
        $tableNamePrefix = 'shop_' . self::SHOP_NAME . '_';
        $this->shopModel = new ShopModel($tableNamePrefix);

        $this->client = new Client();

        $mainCategories = $this->getMainCategories();
        foreach ($mainCategories as $catId => $cat) {
            if($this->option('echo')) {
                print '[' . date('Y-m-d H:i:s') . '] ' . sprintf('%6s', $cat->itemsCount) . ' -- ' . $cat->name . PHP_EOL;
            }
            try {
                $response = $this->client->request('post', 'https://api.catalog.ecom.silpo.ua/api/2.0/exec/EcomCatalogGlobal',
                    [
                        'headers' => [
                            'Content-Type' => 'application/json;charset=UTF-8'
                        ],
                        'body' => '{"method":"GetSimpleCatalogItems","data":{"deliveryType":1,"filialId":2734,"From":1,"businessId":1,"To":'.$cat->itemsCount.',"categoryId":'.$catId.',"RangeFilters":{},"MultiFilters":{},"UniversalFilters":[],"CategoryFilter":[],"Promos":[]}}',
                    ]);
                $data = $response->getBody()->getContents();
                $data = json_decode($data);
                if(isset($data->items)) {
                    foreach ($data->items as $item) {
                        $this->parsePagesByJson($item);
                    }
                }

            } catch (\Exception $e) {
                print '[' . date('Y-m-d H:i:s') . '] ' . PHP_EOL;
                print_r([$e->getMessage(),$e->getFile(),$e->getLine(), $e->getTraceAsString()]); die;
            }
        }

        return $this->call('feed:create', [
            'shop' => self::SHOP_NAME,
            'company' => 'ТОВ «Сільпо-Фуд»',
            'url' => self::SHOP_URL,
            'xmlFileName' => $this->argument('xmlFileName')
        ]);

    }

    private function parsePagesByJson(&$product)
    {
        try {
            $model = $this->shopModel->get('prods');
            $model = $model->newQuery()->find($product->id);
            if (!$model) {
                $model = $this->shopModel->get('prods');
            }

//            if($this->option('echo')) {
//                print '[' . date('Y-m-d H:i:s') . '] ' . $product->name . PHP_EOL;
//            }

            $model->id = $product->id;
            $model->url = self::SHOP_URL . '/detail/' . $product->id;
            $model->title = $product->name;
            $images = [];
            foreach ($product->images as $img) {
                $images[] = $img->path ?? '';
            }
            $model->images = implode(';', array_filter($images));
            $model->category_id = intval($product->category->id);
            $model->category_name = $product->category->name;
            $model->packing = $product->unit;
            if($product->oldPrice) {
                $model->regular_price = $product->oldPrice;
                $model->special_price = $product->price;
                if($dateObj = \DateTime::createFromFormat('d.m.y', $product->priceStopAfter)) {
                    $model->special_price_end_date = $dateObj->format('Y-m-d');
                }
            } else {
                $model->regular_price = $product->price;
            }
            $model->currency = 'UAH';
            $model->description = '';
            $model->vendor = '';
            $params = [];
            if(!empty($product->parameters)) {
                foreach ($product->parameters as $item) {
                    if($item->key == 'trademark') {
                        $model->vendor = $item->value;
                    }
                    $params[$item->name] = $item->value;
                }
            }
            $model->params = json_encode($params, JSON_UNESCAPED_UNICODE);

            $model->save();

        } catch (\Exception $e) {
            print '[' . date('Y-m-d H:i:s') . '] ' . PHP_EOL;
            dd([$e->getMessage(),$e->getFile(),$e->getLine(), $e->getTraceAsString()]);
        }
    }

    private function getMainCategories()
    {
        $categories = [];

        $response = $this->client->request('post', 'https://api.catalog.ecom.silpo.ua/api/2.0/exec/EcomCatalogGlobal',
            [
                'json' => [
                    "method" => "GetCategories",
                    "data" => [
                        "deliveryType" => 1,
                        "filialId" => 2734
                    ]
                ]
            ]);
        $data = $response->getBody()->getContents();
        $data = json_decode($data);
        if(!empty($data->tree)) {
            foreach ($data->tree as $cat) {
                $catUrl = self::SHOP_URL . '/category/' . $cat->id;
                $this->saveCategory($cat->id, $cat->name, $catUrl, $cat->parentId);
                if(is_null($cat->parentId)) {
                    $categories[$cat->id] = $cat;
                }
            }
        }

        return $categories;
    }

    private function saveCategory($id, $name, $url, $parent_id = null)
    {
        try {
            $model = $this->shopModel->get('categories');
            $model = $model->newQuery()->find($id);
            if (!$model) {
                $model = $this->shopModel->get('categories');
            }

            $model->id = $id;
            $model->name = trim($name);
            $model->url = $url;
            $model->parent_id = intval($parent_id);
            $model->save();

        } catch (\Exception $e) {
            print '[' . date('Y-m-d H:i:s') . '] ' . PHP_EOL;
            dd([$e->getMessage(),$e->getFile(),$e->getLine(), $e->getTraceAsString()]);
        }
    }


}

<?php

namespace App\Console\Commands;

use App\Console\Services\ShopModel;
use App\Prods;
use Illuminate\Console\Command;
use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\HttpClient;

class FozzyCrawlerCommand extends Command
{
    const SHOP_NAME = 'fozzy';
    const SHOP_URL = 'https://fozzyshop.com.ua';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'crawler:fozzy {xmlFileName? : Путь к файлу для сохранения фида} {--echo : Выводить сообщения при выполнении}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Парсинг магазина FOZZY';

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

        $this->client = new Client(HttpClient::create(['timeout' => 60]));

        $arrProdLinks = [];
        $pagesModel = $this->shopModel->get('pages');
        if($pagesModel->newQuery()->where('is_processed', 0)->first()) {
            $pagesModel->newQuery()->where('is_processed', 0)->each(function ($row) use(&$arrProdLinks){
                $arrProdLinks[$row->id] = $row->url;
            });
        } else {
            $mainCategoryUrls = $this->getMainCategoryUrls();
            foreach ($mainCategoryUrls as $categoryUrl) {
                $this->fillArrProdLinks($categoryUrl, $arrProdLinks);
            }
        }

        foreach ($arrProdLinks as $prodId => $prodLink) {
            try {
                $crawler = $this->client->request('GET', $prodLink);
                $model = $this->shopModel->get('prods');
                $model = $model->newQuery()->find($prodId);
                if( !$model) {
                    $model = $this->shopModel->get('prods');
                }

                $node = $crawler->filter('section#wrapper');
                $model->id = $prodId;
                $model->url = $prodLink;

                if($node->filter('#product-details')->count() && !empty($node->filter('#product-details')->attr('data-product'))) {
                    $this->parsePagesByJson($node, $model);
                } else {
                    $this->parsePagesByHtml($node, $model);
                }
//                $this->parsePagesByHtml($node, $model);

                if($this->option('echo')) {
                    print '[' . date('Y-m-d H:i:s') . '] ' . $model->title . PHP_EOL;
                }
                $pagesModel = $pagesModel->newQuery()->find($prodId);
                $pagesModel->is_processed = 1;
                $pagesModel->save();

            } catch (\Exception $e) {
                print '[' . date('Y-m-d H:i:s') . '] ' . PHP_EOL;
                dd([$e->getMessage(),$e->getFile(),$e->getLine(), $e->getTraceAsString()]);
            }
        }

        return $this->call('feed:create', [
            'shop' => self::SHOP_NAME,
            'company' => 'Гипермаркет FOZZY',
            'url' => self::SHOP_URL,
            'xmlFileName' => $this->argument('xmlFileName')
        ]);

    }

    private function saveCategory($name, $url, $parent_id = null)
    {
        try {
            $id = intval(preg_replace('/.*\/(\d+)-.*$/', '$1', $url));
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
        return $id;
    }

    private function fillArrProdLinks($url, &$arrProdLinks)
    {
        while (true) {
            if($this->option('echo')) {
                print '[' . date('Y-m-d H:i:s') . '] ' . '-- ' . $url . PHP_EOL;
            }
            try {
                $crawler = $this->client->request('GET', $url);
                $crawler->filter('.js-product-miniature-wrapper')->each(function ($node) use (&$arrProdLinks) {
                    $id = $node->filter('article')->attr('data-id-product');
                    $pagesModel = $this->shopModel->get('pages');
                    $pagesModel = $pagesModel->newQuery()->find($id);
                    if(!$pagesModel) {
                        $pagesModel = $this->shopModel->get('pages');
                    }
                    $pagesModel->id = $id;
                    $pagesModel->url = $node->filter('.thumbnail-container > a')->attr('href');
                    $pagesModel->is_processed = 0;
                    $pagesModel->save();
                    $arrProdLinks[$pagesModel->id] = $pagesModel->url;
                });

                $nextPageLinkEl = $crawler->filter('.next.js-search-link');
                if ($nextPageLinkEl->count() > 0) {
                    $url = trim($nextPageLinkEl->attr('href'));
                } else {
                    break;
                }
            } catch(\Exception $e) {
                print '[' . date('Y-m-d H:i:s') . '] ' . PHP_EOL;
                dd([$e->getMessage(),$e->getFile(),$e->getLine(), $e->getTraceAsString()]);
            }
        }
        return $arrProdLinks;
    }

    private function parsePagesByJson(&$node, &$model)
    {
        $arrCurrencies = [
            'грн' => 'UAH'
        ];
        $product = json_decode($node->filter('#product-details')->attr('data-product'));
        $model->title = $product->name;
        $images = [];
        foreach ($product->images as $img) {
            $images[] = $img->large->url ?? ( $img->medium->url ?? ($img->small->url ?? ''));
        }
        $model->images = implode(';', array_filter($images));

        $lastId = null;
        $node->filter('.breadcrumb ol > li')->slice(1, -1)->each(function($item) use(&$categoryTree, &$lastId) {
            $name = $item->filter('a')->text();
            $link = $item->filter('a')->attr('href');
            $lastId = $this->saveCategory($name, $link, $lastId);
        });

        $model->category_id = intval($product->id_category_default);
        $model->category_name = $product->category_name;
        $model->packing = $product->unity;
        $currency = preg_replace('/[\d\.\,]+\s(.*)/u', '$1', $product->price);
        $price = preg_replace('/([\d\.\,]+)\s.*/u', '$1', $product->price);
        $model->regular_price = str_replace(',', '.', ($product->price_without_reduction ?? $price));
        if($product->specific_prices) {
            $model->special_price = $price ? str_replace(',', '.', $price) : null;
            $model->special_price_end_date = $product->specific_prices->to ?? null;
        }
        $model->currency = $arrCurrencies[$currency] ?? $currency;
        $model->description = $product->description;

        // Берем с HTML
        $params = [];
        $node->filter('.product-features .data-sheet > .name')->each(function($item) use(&$params) {
            $params[$item->text()] = $item->nextAll()->first()->text();
        });
        $model->params = json_encode($params, JSON_UNESCAPED_UNICODE);
        $model->vendor = $params['Бренд'] ?? '';

        $model->save();
    }

    /**
     * @param $node Crawler
     * @param $model Prods
     */
    private function parsePagesByHtml(&$node, &$model)
    {
        $model->title = $node->filter('h1')->text();

        $images = [];
        $node->filter('.col-left-product-cover .product-images-large img')->each(function ($imgEl) use (&$images) {
            $images[] = $imgEl->attr('data-image-large-src') ?? $imgEl->attr('src');
        });
        $model->images = implode(';', $images);

        $categoryTree = [];
        $lastId = null;
        $node->filter('.breadcrumb ol > li')->slice(1, -1)->each(function($item) use(&$categoryTree, &$lastId) {
            $name = $item->filter('a')->text();
            $link = $item->filter('a')->attr('href');
            $lastId = $this->saveCategory($name, $link, $lastId);
            $categoryTree[$lastId] = $name;
        });
        $values = array_values($categoryTree);
        $keys = array_keys($categoryTree);
        $model->category_name = array_pop($values);
        $model->category_id = array_pop($keys);

        $model->packing = $node->filter('.product-features .data-sheet dt:contains("Фасовка")')->nextAll()->first()->text();
        if($node->filter('.product-prices .product-discount')->count()) {
            $regular_price = preg_replace('/([\d\.\,]+)\s.*/u', '$1', ($node->filter('.product-prices .regular-price')->count() ? $node->filter('.product-prices .regular-price')->text() : ''));
            $model->regular_price = str_replace(',', '.', $regular_price);
            $special_price = preg_replace('/([\d\.\,]+)\s.*/u', '$1', ($node->filter('.product-prices span[itemprop="price"]')->count() ? $node->filter('.product-prices span[itemprop="price"]')->attr('content') : ''));
            $model->special_price = str_replace(',', '.', $special_price);
            $model->special_price_end_date = $node->filter('.product-prices meta[itemprop="priceValidUntil"]')->count() ? $node->filter('.product-prices meta[itemprop="priceValidUntil"]')->attr('content') : '';
        } else {
            if(!$node->filter('.product-prices span[itemprop="price"]')->count()) {
                return;
            }
            $regular_price = $node->filter('.product-prices span[itemprop="price"]')->attr('content');
            $model->regular_price = str_replace(',', '.', $regular_price);
            $model->special_price = null;
            $model->special_price_end_date = null;
        }
        $model->currency = $node->filter('.product-prices meta[itemprop="priceCurrency"]')->count() ? $node->filter('.product-prices meta[itemprop="priceCurrency"]')->attr('content') : '';
        $model->description =  $node->filter('.product-description-section .product-description')->count() ? $node->filter('.product-description-section .product-description')->text() : '';

        $params = [];
        $node->filter('.product-features .data-sheet > .name')->each(function($item) use(&$params) {
            $params[$item->text()] = $item->nextAll()->first()->text();
        });
        $model->params = json_encode($params, JSON_UNESCAPED_UNICODE);
        $model->vendor = $params['Бренд'] ?? '';

        $model->save();
    }

    private function getMainCategoryUrls()
    {
        $urls = [];
        $crawler = $this->client->request('GET', self::SHOP_URL);
        $crawler->filter('.category-top-menu.block-content')->children('.category-sub-menu > li')->each(function (Crawler $node) use (&$urls) {
            if($node->children('.collapse')->count() !== 0) {
                $urls[] = $node->children('a')->attr('href');
            }
        });

        return $urls;
    }

}

<?php


namespace App\Console\Services;


use Illuminate\Support\Facades\DB;

class ShopModel
{
    private $tableNamePrefix;

    public function __construct($tableNamePrefix)
    {
        $this->tableNamePrefix = $tableNamePrefix;
    }

    public function get($name)
    {
        $tableName = $this->tableNamePrefix . $name;
        DB::statement("CREATE TABLE IF NOT EXISTS {$tableName} LIKE {$name}");
        $className = 'App\\' . ucfirst($name);
        $modelProds = new $className();
        $modelProds->setTable($tableName);
        return $modelProds;
    }
}

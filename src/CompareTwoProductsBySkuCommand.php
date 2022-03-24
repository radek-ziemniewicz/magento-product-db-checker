<?php

namespace MagentoProductDbChecker;

use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Filesystem;

class CompareTwoProductsBySkuCommand extends Command
{
    protected static $defaultName = 'check-product:by-two-skus';

    protected function configure()
    {
        $this->addArgument('sku1', InputArgument::REQUIRED, 'SKU of the first product');
        $this->addArgument('sku2', InputArgument::REQUIRED, 'SKU of the second product');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dbName = $_ENV['DB_NAME'];
        $sku1 = $input->getArgument('sku1');
        $sku2 = $input->getArgument('sku2');
        $products1 = DB::select('select * from catalog_product_entity where sku = ? limit 1', [$sku1]);

        if (empty($products1)) {
            throw new \InvalidArgumentException("Product {$sku1} not found");
        }

        $products2 = DB::select('select * from catalog_product_entity where sku = ? limit 1', [$sku2]);

        if (empty($products2)) {
            throw new \InvalidArgumentException("Product {$sku2} not found");
        }

        $filesystem = new Filesystem();
        $filesystem->touch('pre.txt');
        $filesystem->touch('post.txt');

        $tables = DB::select('show tables');
        $tablesNum = count($tables);
        $output->writeln("<info>Found {$tablesNum} tables, searching product SKU #{$sku1} and SKU #{$sku2} in them.</info>");
        $preResults = $this->loopThroughTables($dbName, $tables, $products1[0]);
        $filesystem->dumpFile('pre.txt', serialize($preResults));

        $postResults = $this->loopThroughTables($dbName, $tables, $products2[0]);
        $filesystem->dumpFile('post.txt', serialize($postResults));

        dd($this->array_diff_assoc_recursive($preResults, $postResults));
    }

    private function runSelectQueries(string $tableName, string $columnName, $value): array
    {
        try {
            return array_map(function ($value) {
                return (array)$value;
            }, DB::select("select * from {$tableName} where {$columnName} = ?", [$value]));
        } catch (\Exception $exception) {
            if (!strstr($exception->getMessage(), 'Column not found')) {
                throw $exception;
            }
        }

        return [];
    }

    private function loopThroughTables(string $dbName, array $tables, \stdClass $product): array
    {
        $results = [];

        foreach ($tables as $table) {
            $propertyName = "Tables_in_{$dbName}";
            $tableName = $table->$propertyName;

            $skuRows = $this->runSelectQueries($tableName, 'sku', $product->sku);
            $entityIdRows = $this->runSelectQueries($tableName, 'entity_id', $product->entity_id);
            $rows = array_merge($skuRows, $entityIdRows);

            if (empty($rows)) {
                continue;
            }

            $results[$tableName] = $rows;
        }

        return $results;
    }

    private function array_diff_assoc_recursive(array $array1, array $array2): array
    {
        $difference = [];

        foreach ($array1 as $key => $value) {
            if (is_array($value)) {
                if (!isset($array2[$key]) || !is_array($array2[$key])) {
                    $difference[$key]['sku1'] = $value;
                    $difference[$key]['sku2'] = $array2[$key] ?? '(no value)';
                } else {
                    $newDiff = $this->array_diff_assoc_recursive($value, $array2[$key]);

                    if (!empty($newDiff)) {
                        $difference[$key] = $newDiff;
                    }
                }
            } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                $difference[$key]['sku1'] = $value;
                $difference[$key]['sku2'] = $array2[$key] ?? '(no value)';
            }
        }

        return $difference;
    }
}

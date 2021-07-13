<?php

namespace MagentoProductDbChecker;

use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckProductBySkuCommand extends Command
{
    protected static $defaultName = 'check-product:by-sku';

    protected function configure()
    {
        $this->addArgument('sku', InputArgument::REQUIRED, 'SKU of the product');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $dbName = $_ENV['DB_NAME'];
        $sku = $input->getArgument('sku');
        $products = DB::select('select * from catalog_product_entity where sku = ? limit 1', [$sku]);

        if (empty($products)) {
            throw new \InvalidArgumentException('Product not found');
        }

        $product = $products[0];
        $tables = DB::select('show tables');
        $tablesNum = count($tables);
        $output->writeln("Found {$tablesNum} tables, searching product in:");
        $i = 0;

        foreach ($tables as $table) {
            $propertyName = "Tables_in_{$dbName}";
            $tableName = $table->$propertyName;
            $skuRows = [];
            $entityIdRows = [];

            try {
                $skuRows = DB::select("select * from {$tableName} where sku = ?", [$sku]);
            } catch (\Exception $exception) {
                if (!strstr($exception->getMessage(), 'Column not found')) {
                    throw $exception;
                }
            }

            try {
                $entityIdRows = DB::select("select * from {$tableName} where entity_id = ?", [$product->entity_id]);
            } catch (\Exception $exception) {
                if (!strstr($exception->getMessage(), 'Column not found')) {
                    throw $exception;
                }
            }

            $rows = array_merge($skuRows, $entityIdRows);

            if (empty($rows)) {
                continue;
            }

            ++$i;
            $output->write("{$i}. <info>{$tableName}</info>: ");
            dump($rows);
        }

        return 0;
    }
}
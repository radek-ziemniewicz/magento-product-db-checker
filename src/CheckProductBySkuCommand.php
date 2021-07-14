<?php

namespace MagentoProductDbChecker;

use Illuminate\Database\Capsule\Manager as DB;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Filesystem;

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
        $filesystem = new Filesystem();
        $filesystem->touch('pre.txt');
        $filesystem->touch('post.txt');

        if (empty($products)) {
            throw new \InvalidArgumentException('Product not found');
        }

        $tables = DB::select('show tables');
        $tablesNum = count($tables);
        $output->writeln("<info>Found {$tablesNum} tables, searching product SKU #{$sku} in them.</info>");
        $preResults = $this->loopThroughTables($dbName, $tables, $products[0]);
        $filesystem->dumpFile('pre.txt', serialize($preResults));

        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('Continue with this action? (y/n) ', false, '/^(y|j)/i');

        if (!$helper->ask($input, $output, $question)) {
            return Command::SUCCESS;
        }

        $postResults = $this->loopThroughTables($dbName, $tables, $products[0]);
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

            $results[] = [
                'table' => $tableName,
                'rows' => $rows,
            ];
        }

        return $results;
    }

    private function array_diff_assoc_recursive(array $array1, array $array2): array
    {
        $difference = [];

        foreach ($array1 as $key => $value) {
            if (is_array($value)) {
                if (!isset($array2[$key]) || !is_array($array2[$key])) {
                    $difference[$key]['pre'] = $value;
                    $difference[$key]['post'] = $array2[$key] ?? '-';
                } else {
                    $newDiff = $this->array_diff_assoc_recursive($value, $array2[$key]);

                    if (!empty($newDiff)) {
                        $difference[$key] = $newDiff;
                    }
                }
            } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                $difference[$key]['pre'] = $value;
                $difference[$key]['post'] = $array2[$key] ?? '-';
            }
        }

        return $difference;
    }
}

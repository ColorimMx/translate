<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class TranslateWalmart extends Command
{
    protected $signature = 'app:translate-walmart {filePath}';
    protected $description = 'Processes a Walmart orders file and generates the output file.';

    public function handle()
    {
        $filePath = $this->argument('filePath');
        if (!file_exists($filePath)) {
            $this->error("The file {$filePath} does not exist.");
            return Command::FAILURE;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines || count($lines) <= 1) {
            $this->error("The file {$filePath} has no data or only one line.");
            return Command::FAILURE;
        }

        // Genera el archivo en el disco 'edi'
        $outputFilePath = 'data_in/850_EXP.CIM';

        $outputData = [];

        $customer = 'DF';
        $typeTrans = '100';
        $typeTransLine = '300';
        $tpCode = 'N0420';
        $trxCode = '850';

        $previousOrder = null;

        foreach (array_slice($lines, 1) as $line) {
            $columns = str_getcsv($line);

            if (count($columns) < 6) {
                $this->error("A line has fewer than 6 columns: {$line}");
                continue;
            }

            $currentOrder = $columns[0];

            if ($currentOrder !== $previousOrder) {
                $line1 =
                    str_pad(substr($customer, 0, 2), 2) .
                    str_pad(substr($currentOrder, 0, 22), 22) .
                    str_pad(substr($columns[2], 0, 8), 8) .
                    str_pad('', 7) .
                    str_pad(substr($typeTrans, 0, 3), 3) .
                    str_pad('', 11) .
                    str_pad(substr($tpCode, 0, 5), 5) .
                    str_pad('', 15) .
                    str_pad(substr($trxCode, 0, 3), 3) .
                    PHP_EOL;

                $outputData[] = $line1;
                $previousOrder = $currentOrder;
            }

            $priceFormatted = $this->formatPrice($columns[11]);

            $line2 =
                str_pad(substr($customer, 0, 2), 2) .
                str_pad(substr($currentOrder, 0, 22), 22) .
                str_pad('', 9) .
                str_pad('', 6) .
                str_pad(substr($typeTransLine, 0, 3), 3) .
                str_pad('', 11) .
                str_pad(substr($tpCode, 0, 5), 5) .
                str_pad('', 85) .
                str_pad(substr('00000', 0, 15), 15) .
                str_pad('', 31) .
                str_pad(substr($columns[5], 0, 30), 30) .
                str_pad('', 30) .
                str_pad(str_pad($columns[10], 9, '0', STR_PAD_LEFT), 9) .
                str_pad(substr('PZ', 0, 2), 2) .
                $priceFormatted .
                str_pad('', 2) .
                str_pad('', 68) .
                str_pad(substr($columns[3], 0, 8), 8) .
                PHP_EOL;

            $outputData[] = $line2;
        }

        Storage::disk('edi')->put($outputFilePath, implode('', $outputData));

        $fileFullPath = Storage::disk('edi')->path($outputFilePath);
        chmod($fileFullPath, 0664);

        $this->info("Data processed successfully and written to {$outputFilePath}");

        return Command::SUCCESS;
    }

    private function formatPrice($price)
    {
        if (strpos($price, '.') === false) {
            return str_pad($price, 9, '0', STR_PAD_LEFT) . '00000';
        }

        list($integerPart, $decimalPart) = explode('.', $price);
        $integerPart = str_pad($integerPart, 9, '0', STR_PAD_LEFT);
        $decimalPart = str_pad(substr($decimalPart, 0, 5), 5, '0');

        return $integerPart . $decimalPart;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ProcessOrdersFile extends Command
{
    protected $signature = 'app:process-orders-file';
    protected $description = 'Reads an orders file and processes it based on conditions.';

    public function handle()
    {
        $directoryPath = Storage::disk('edi')->path('translate');
        $inputFiles = glob("{$directoryPath}/*.INF");

        if (empty($inputFiles)) {
            $this->error("No .INF files found in the directory: {$directoryPath}");
            return Command::FAILURE;
        }


        $logFileName = 'process_log_' . now()->format('Y-m-d') . '.txt';
        $logFilePath = Storage::disk('edi')->path('translate_log/' . $logFileName);
        $logData = [];

        foreach ($inputFiles as $inputFilePath) {
            $fileName = basename($inputFilePath);
            $this->info("Processing file: {$fileName}");

            if (Storage::disk('edi')->exists('data_in/850_EXP.CIM')) {
                $logData[] = $this->generateLogMessage("Error: {$fileName} - 850_EXP.CIM exists in data_in. Moving to translate_error.
                Process the file in Syteline form EDI Transaction Load Routine or delete the file to generate 850_EXP_CIM again");
                $this->logToFile($logFilePath, $logData);
                // Mueve el archivo INF a la subcarpeta errores_ordenes_inf
                Storage::disk('edi')->move("translate/{$fileName}", "translate_error/{$fileName}");
                continue;
            }

            $lines = file($inputFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines || count($lines) <= 1) {
                $this->error("The file {$fileName} has no data or only one line.");
                $logData[] = $this->generateLogMessage("Error: {$fileName} has no data or only one line.");
                $this->logToFile($logFilePath, $logData);
                continue;
            }

            $firstLineColumns = str_getcsv($lines[0]);
            $firstColumn = $firstLineColumns[0] ?? null;

            if ($firstColumn === '007850 001') {
                $this->info("Condition 1 met: {$firstColumn} Orden de Nadro");
                $this->call('app:translate-nadro', ['filePath' => $inputFilePath]);
                $logData[] = $this->generateLogMessage("Nadro met: {$fileName} - Orden de Nadro");
            } elseif ($firstColumn === '010850 001') {
                $this->info("Condition 2 met: {$firstColumn} Orden de Walmart");
                $this->call('app:translate-walmart', ['filePath' => $inputFilePath]);
                $logData[] = $this->generateLogMessage("Walmart met: {$fileName} - Orden de Walmart");
            } elseif ($firstColumn === '026850 002') {
                $this->info("Condition 3 met: {$firstColumn} Orden de Chedraui");
                $this->call('app:translate-chedraui', ['filePath' => $inputFilePath]);
                $logData[] = $this->generateLogMessage("Chedraui met: {$fileName} - Orden de Chedraui");
            } else {
                $this->warn("No conditions met for: {$firstColumn}");
                $logData[] = $this->generateLogMessage("No conditions met: {$fileName}");
            }

            Storage::disk('edi')->move("translate/{$fileName}", "translate_process/{$fileName}");
            $logData[] = $this->generateLogMessage("Processed successfully: {$fileName} - Moved to translate_process - File has been generated 850_EXP.CIM.");
            $this->logToFile($logFilePath, $logData);
        }

        return Command::SUCCESS;
    }

    private function logToFile($logFilePath, $logData)
    {
        $logMessage = implode(PHP_EOL, $logData) . PHP_EOL;
        file_put_contents($logFilePath, $logMessage, FILE_APPEND);
    }

    private function generateLogMessage($message)
    {
        return "[" . now()->format('Y-m-d H:i:s') . "] {$message}";
    }
}

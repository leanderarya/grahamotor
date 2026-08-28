<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class MonthlySalesReportImporter
{
    /**
     * @return array{
     *     imported_days:int,
     *     skipped_days:int,
     *     created_transactions:int,
     *     updated_transactions:int,
     *     created_products:int,
     *     imported_items:int,
     *     updated_stock_products:int,
     *     matched_days:int,
     *     out_of_month_days:int,
     *     first_transaction_date:string|null,
     *     last_transaction_date:string|null,
     *     detected_months:list<string>,
     *     validation_status:string,
     *     validation_notes:string,
     *     days:list<array{
     *         date:string,
     *         invoice_number:string,
     *         item_count:int,
     *         total_amount:float,
     *         total_profit:float,
     *         status:string
     *     }>
     * }
     */
    public function preview(string $filePath, CarbonInterface|string|null $targetMonth = null): array
    {
        $targetMonth = $this->normalizeTargetMonth($targetMonth);
        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($filePath);

        $summary = [
            'imported_days' => 0,
            'skipped_days' => 0,
            'created_transactions' => 0,
            'updated_transactions' => 0,
            'created_products' => 0,
            'imported_items' => 0,
            'updated_stock_products' => 0,
            'matched_days' => 0,
            'out_of_month_days' => 0,
            'first_transaction_date' => null,
            'last_transaction_date' => null,
            'detected_months' => [],
            'validation_status' => $targetMonth ? 'match' : 'unchecked',
            'validation_notes' => $targetMonth
                ? 'Semua sheet berada dalam bulan laporan.'
                : 'Belum ada validasi terhadap bulan laporan.',
            'days' => [],
        ];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            if (! ctype_digit((string) $worksheet->getTitle())) {
                continue;
            }

            $dayPreview = $this->previewWorksheet($worksheet);

            if ($dayPreview === null) {
                $summary['skipped_days']++;

                continue;
            }

            $summary['imported_days']++;
            $summary['created_transactions'] += $dayPreview['status'] === 'create' ? 1 : 0;
            $summary['updated_transactions'] += $dayPreview['status'] === 'update' ? 1 : 0;
            $summary['created_products'] += $dayPreview['created_products'];
            $summary['imported_items'] += $dayPreview['item_count'];
            $summary = $this->appendValidationDay($summary, $dayPreview['date'], $targetMonth);
            $summary['days'][] = [
                'date' => $dayPreview['date']->format('d M Y'),
                'invoice_number' => $dayPreview['invoice_number'],
                'item_count' => $dayPreview['item_count'],
                'total_amount' => $dayPreview['total_amount'],
                'total_profit' => $dayPreview['total_profit'],
                'status' => $dayPreview['status'],
            ];
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $summary = $this->finalizeValidationSummary($summary, $targetMonth);

        return $summary;
    }

    /**
     * @return array{
     *     imported_days:int,
     *     skipped_days:int,
     *     created_transactions:int,
     *     updated_transactions:int,
     *     created_products:int,
     *     imported_items:int,
     *     updated_stock_products:int,
     *     matched_days:int,
     *     out_of_month_days:int,
     *     first_transaction_date:string|null,
     *     last_transaction_date:string|null,
     *     detected_months:list<string>,
     *     validation_status:string,
     *     validation_notes:string
     * }
     */
    public function import(string $filePath, User $user, CarbonInterface|string|null $targetMonth = null): array
    {
        $targetMonth = $this->normalizeTargetMonth($targetMonth);
        $summary = $this->preview($filePath, $targetMonth);

        $reader = IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);
        $latestStockDate = null;
        $latestStockRows = [];

        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            if (! ctype_digit((string) $worksheet->getTitle())) {
                continue;
            }

            $worksheetDate = $this->resolveWorksheetDate($worksheet);

            if (
                $worksheetDate !== null
                && ($latestStockDate === null || $worksheetDate->greaterThan($latestStockDate))
            ) {
                $latestStockDate = $worksheetDate;
                $latestStockRows = $this->extractRows($worksheet, true);
            }

            $imported = $this->importWorksheet($worksheet, $user);

            if ($imported === null) {
                continue;
            }

        }

        if ($latestStockRows !== []) {
            $stockSync = $this->syncStockRows($latestStockRows);
            $summary['updated_stock_products'] = $stockSync['updated_stock_products'];
            $summary['created_products'] += $stockSync['created_products'];
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        unset($summary['days']);

        return $summary;
    }

    /**
     * @return array{
     *     created_transaction:bool,
     *     created_products:int,
     *     imported_items:int
     * }|null
     */
    private function importWorksheet(Worksheet $worksheet, User $user): ?array
    {
        $date = $this->resolveWorksheetDate($worksheet);

        if ($date === null) {
            return null;
        }

        $items = [];
        $createdProducts = 0;
        foreach ($this->extractRows($worksheet) as $row) {
            $product = $this->findOrCreateProduct(
                $row['product_name'],
                $row['volume_liter'],
                $row['fallback_cost'],
                $row['fallback_price'],
                $createdProducts,
            );

            if ($row['old_quantity'] > 0) {
                $items[] = $this->makeItemPayload(
                    $product->id,
                    $row['old_quantity'],
                    $row['old_cost'],
                    $row['old_price'],
                    $row['fallback_cost'],
                    $row['fallback_price'],
                );
            }

            if ($row['new_quantity'] > 0) {
                $items[] = $this->makeItemPayload(
                    $product->id,
                    $row['new_quantity'],
                    $row['new_cost'],
                    $row['new_price'],
                    $row['fallback_cost'],
                    $row['fallback_price'],
                );
            }
        }

        if ($items === []) {
            return null;
        }

        $invoiceNumber = sprintf('IMP-%s', $date->format('Ymd'));
        $totalAmount = collect($items)->sum(fn (array $item) => $item['quantity'] * $item['price_at_time']);
        $totalProfit = collect($items)->sum(
            fn (array $item) => $item['quantity'] * ($item['price_at_time'] - $item['cost_at_time'])
        );

        $createdTransaction = false;

        DB::transaction(function () use (
            $date,
            $invoiceNumber,
            $items,
            $totalAmount,
            $totalProfit,
            $user,
            &$createdTransaction
        ): void {
            $transaction = Transaction::firstOrNew([
                'invoice_number' => $invoiceNumber,
            ]);

            $createdTransaction = ! $transaction->exists;

            $transaction->fill([
                'user_id' => $user->id,
                'payment_method' => 'import_excel',
                'amount_paid' => $totalAmount,
                'change_amount' => 0,
                'total_amount' => $totalAmount,
                'total_profit' => $totalProfit,
                'created_at' => $date->copy()->endOfDay(),
            ]);

            $transaction->save();

            $transaction->transactionItems()->delete();
            $transaction->transactionItems()->createMany($items);
        });

        return [
            'created_transaction' => $createdTransaction,
            'created_products' => $createdProducts,
            'imported_items' => count($items),
        ];
    }

    /**
     * @return array{
     *     date:Carbon,
     *     invoice_number:string,
     *     item_count:int,
     *     total_amount:float,
     *     total_profit:float,
     *     created_products:int,
     *     status:string
     * }|null
     */
    private function previewWorksheet(Worksheet $worksheet): ?array
    {
        $date = $this->resolveWorksheetDate($worksheet);

        if ($date === null) {
            return null;
        }

        $createdProducts = 0;
        $items = [];

        foreach ($this->extractRows($worksheet) as $row) {
            $productExists = Product::query()
                ->whereRaw('LOWER(name) = ?', [Str::lower($row['product_name'])])
                ->where('volume_liter', $row['volume_liter'])
                ->exists();

            if (! $productExists) {
                $createdProducts++;
            }

            if ($row['old_quantity'] > 0) {
                $items[] = $this->makeItemPayload(
                    0,
                    $row['old_quantity'],
                    $row['old_cost'],
                    $row['old_price'],
                    $row['fallback_cost'],
                    $row['fallback_price'],
                );
            }

            if ($row['new_quantity'] > 0) {
                $items[] = $this->makeItemPayload(
                    0,
                    $row['new_quantity'],
                    $row['new_cost'],
                    $row['new_price'],
                    $row['fallback_cost'],
                    $row['fallback_price'],
                );
            }
        }

        if ($items === []) {
            return null;
        }

        $invoiceNumber = sprintf('IMP-%s', $date->format('Ymd'));

        return [
            'date' => $date,
            'invoice_number' => $invoiceNumber,
            'item_count' => count($items),
            'total_amount' => collect($items)->sum(fn (array $item) => $item['quantity'] * $item['price_at_time']),
            'total_profit' => collect($items)->sum(
                fn (array $item) => $item['quantity'] * ($item['price_at_time'] - $item['cost_at_time'])
            ),
            'created_products' => $createdProducts,
            'status' => Transaction::query()->where('invoice_number', $invoiceNumber)->exists() ? 'update' : 'create',
        ];
    }

    private function resolveWorksheetDate(Worksheet $worksheet): ?Carbon
    {
        $rawValue = $this->getNumericValue($worksheet, 'B5');

        if ($rawValue <= 0) {
            return null;
        }

        return Carbon::instance(ExcelDate::excelToDateTimeObject($rawValue))->startOfDay();
    }

    /**
     * @return list<array{
     *     product_name:string,
     *     volume_liter:float,
     *     old_quantity:int,
     *     new_quantity:int,
     *     old_cost:float,
     *     new_cost:float,
     *     old_price:float,
     *     new_price:float,
     *     remaining_old_stock:int,
     *     remaining_new_stock:int,
     *     remaining_total_stock:int,
     *     fallback_cost:float,
     *     fallback_price:float
     * }>
     */
    private function extractRows(Worksheet $worksheet, bool $includeWithoutSales = false): array
    {
        $rows = [];
        $highestRow = $worksheet->getHighestDataRow();

        for ($row = 10; $row <= $highestRow; $row++) {
            $productName = trim($this->getStringValue($worksheet, "B{$row}"));

            if ($productName === '') {
                continue;
            }

            if (Str::upper($productName) === 'TOTAL') {
                break;
            }

            $oldQuantity = (int) round($this->getNumericValue($worksheet, "H{$row}"));
            $newQuantity = (int) round($this->getNumericValue($worksheet, "I{$row}"));

            if (! $includeWithoutSales && $oldQuantity <= 0 && $newQuantity <= 0) {
                continue;
            }

            $oldCost = $this->getNumericValue($worksheet, "L{$row}");
            $newCost = $this->getNumericValue($worksheet, "M{$row}");
            $oldPrice = $this->getNumericValue($worksheet, "N{$row}");
            $newPrice = $this->getNumericValue($worksheet, "O{$row}");
            $remainingOldStock = (int) round($this->getNumericValue($worksheet, "J{$row}"));
            $remainingNewStock = (int) round($this->getNumericValue($worksheet, "K{$row}"));

            $rows[] = [
                'product_name' => $productName,
                'volume_liter' => round($this->getNumericValue($worksheet, "C{$row}"), 2),
                'old_quantity' => max($oldQuantity, 0),
                'new_quantity' => max($newQuantity, 0),
                'old_cost' => $oldCost,
                'new_cost' => $newCost,
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'remaining_old_stock' => max($remainingOldStock, 0),
                'remaining_new_stock' => max($remainingNewStock, 0),
                'remaining_total_stock' => max($remainingOldStock, 0) + max($remainingNewStock, 0),
                'fallback_cost' => $oldCost > 0 ? $oldCost : $newCost,
                'fallback_price' => $oldPrice > 0 ? $oldPrice : $newPrice,
            ];
        }

        return $rows;
    }

    /**
     * @return array{product_id:int, quantity:int, cost_at_time:float, price_at_time:float}
     */
    private function makeItemPayload(
        int $productId,
        int $quantity,
        float $cost,
        float $price,
        float $fallbackCost,
        float $fallbackPrice
    ): array {
        return [
            'product_id' => $productId,
            'quantity' => $quantity,
            'cost_at_time' => $cost > 0 ? $cost : $fallbackCost,
            'price_at_time' => $price > 0 ? $price : $fallbackPrice,
        ];
    }

    private function findOrCreateProduct(
        string $productName,
        float $volumeLiter,
        float $fallbackCost,
        float $fallbackPrice,
        int &$createdProducts
    ): Product {
        $product = Product::query()
            ->whereRaw('LOWER(name) = ?', [Str::lower($productName)])
            ->where('volume_liter', $volumeLiter)
            ->first();

        if ($product) {
            $updates = [];

            if ((float) $product->cost_price <= 0 && $fallbackCost > 0) {
                $updates['cost_price'] = $fallbackCost;
            }

            if ((float) $product->sell_price <= 0 && $fallbackPrice > 0) {
                $updates['sell_price'] = $fallbackPrice;
            }

            if ($updates !== []) {
                $product->update($updates);
            }

            return $product;
        }

        $createdProducts++;

        return Product::create([
            'sku' => $this->generateImportSku($productName, $volumeLiter),
            'name' => $productName,
            'volume_liter' => $volumeLiter,
            'stock' => 0,
            'cost_price' => $fallbackCost,
            'sell_price' => $fallbackPrice,
        ]);
    }

    private function generateImportSku(string $productName, float $volumeLiter): string
    {
        $slug = Str::upper(Str::slug($productName.'-'.$this->normalizeVolumeForSku($volumeLiter).'L', '-'));
        $slug = trim(Str::limit($slug, 40, ''), '-');

        return sprintf(
            'IMP-%s-%s',
            $slug ?: 'PRODUK',
            Str::upper(substr(md5($productName.'|'.$volumeLiter), 0, 6))
        );
    }

    private function getStringValue(Worksheet $worksheet, string $cell): string
    {
        $value = $worksheet->getCell($cell)->getFormattedValue();

        return is_string($value) ? $value : (string) $value;
    }

    private function getNumericValue(Worksheet $worksheet, string $cell): float
    {
        $worksheetCell = $worksheet->getCell($cell);
        $value = $worksheetCell->getValue();

        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && str_starts_with($value, '=')) {
            $cachedValue = $worksheetCell->getOldCalculatedValue();

            if (is_numeric($cachedValue)) {
                return (float) $cachedValue;
            }
        }

        $formatted = preg_replace('/[^0-9.,-]/', '', $worksheetCell->getFormattedValue());

        if ($formatted === null || $formatted === '') {
            return 0.0;
        }

        $normalized = str_contains($formatted, ',') && str_contains($formatted, '.')
            ? str_replace(',', '', $formatted)
            : str_replace(',', '.', $formatted);

        return is_numeric($normalized) ? (float) $normalized : 0.0;
    }

    private function normalizeTargetMonth(CarbonInterface|string|null $targetMonth): ?Carbon
    {
        if ($targetMonth === null || $targetMonth === '') {
            return null;
        }

        if ($targetMonth instanceof CarbonInterface) {
            return Carbon::instance($targetMonth)->startOfMonth();
        }

        return Carbon::createFromFormat('Y-m', $targetMonth)->startOfMonth();
    }

    /**
     * @param  array{
     *     imported_days:int,
     *     skipped_days:int,
     *     created_transactions:int,
     *     updated_transactions:int,
     *     created_products:int,
     *     imported_items:int,
     *     matched_days:int,
     *     out_of_month_days:int,
     *     first_transaction_date:string|null,
     *     last_transaction_date:string|null,
     *     detected_months:list<string>,
     *     validation_status:string,
     *     validation_notes:string,
     *     days:list<array<string, mixed>>
     * } $summary
     * @return array{
     *     imported_days:int,
     *     skipped_days:int,
     *     created_transactions:int,
     *     updated_transactions:int,
     *     created_products:int,
     *     imported_items:int,
     *     matched_days:int,
     *     out_of_month_days:int,
     *     first_transaction_date:string|null,
     *     last_transaction_date:string|null,
     *     detected_months:list<string>,
     *     validation_status:string,
     *     validation_notes:string,
     *     days:list<array<string, mixed>>
     * }
     */
    private function appendValidationDay(array $summary, Carbon $worksheetDate, ?Carbon $targetMonth): array
    {
        $dateKey = $worksheetDate->toDateString();
        $monthKey = ucfirst($worksheetDate->locale('id')->translatedFormat('F Y'));

        if ($summary['first_transaction_date'] === null || $dateKey < $summary['first_transaction_date']) {
            $summary['first_transaction_date'] = $dateKey;
        }

        if ($summary['last_transaction_date'] === null || $dateKey > $summary['last_transaction_date']) {
            $summary['last_transaction_date'] = $dateKey;
        }

        if (! in_array($monthKey, $summary['detected_months'], true)) {
            $summary['detected_months'][] = $monthKey;
        }

        if ($targetMonth === null) {
            return $summary;
        }

        if ($worksheetDate->isSameMonth($targetMonth)) {
            $summary['matched_days']++;

            return $summary;
        }

        $summary['out_of_month_days']++;

        return $summary;
    }

    /**
     * @param  array{
     *     imported_days:int,
     *     skipped_days:int,
     *     created_transactions:int,
     *     updated_transactions:int,
     *     created_products:int,
     *     imported_items:int,
     *     matched_days:int,
     *     out_of_month_days:int,
     *     first_transaction_date:string|null,
     *     last_transaction_date:string|null,
     *     detected_months:list<string>,
     *     validation_status:string,
     *     validation_notes:string,
     *     days:list<array<string, mixed>>
     * } $summary
     * @return array{
     *     imported_days:int,
     *     skipped_days:int,
     *     created_transactions:int,
     *     updated_transactions:int,
     *     created_products:int,
     *     imported_items:int,
     *     matched_days:int,
     *     out_of_month_days:int,
     *     first_transaction_date:string|null,
     *     last_transaction_date:string|null,
     *     detected_months:list<string>,
     *     validation_status:string,
     *     validation_notes:string,
     *     days:list<array<string, mixed>>
     * }
     */
    private function finalizeValidationSummary(array $summary, ?Carbon $targetMonth): array
    {
        sort($summary['detected_months']);

        if ($targetMonth === null) {
            $summary['validation_status'] = 'unchecked';
            $summary['validation_notes'] = 'Belum ada validasi terhadap bulan laporan.';

            return $summary;
        }

        $targetLabel = ucfirst($targetMonth->locale('id')->translatedFormat('F Y'));

        if ($summary['imported_days'] === 0) {
            $summary['validation_status'] = 'empty';
            $summary['validation_notes'] = "Belum ada hari transaksi yang terbaca untuk {$targetLabel}.";

            return $summary;
        }

        if ($summary['out_of_month_days'] === 0) {
            $summary['validation_status'] = 'match';
            $summary['validation_notes'] = "Semua {$summary['matched_days']} hari cocok dengan {$targetLabel}.";

            return $summary;
        }

        if ($summary['matched_days'] === 0) {
            $summary['validation_status'] = 'outside';
            $summary['validation_notes'] = "Semua {$summary['out_of_month_days']} hari berada di luar {$targetLabel}.";

            return $summary;
        }

        $summary['validation_status'] = 'mixed';
        $summary['validation_notes'] = "{$summary['matched_days']} hari cocok, {$summary['out_of_month_days']} hari di luar {$targetLabel}.";

        return $summary;
    }

    /**
     * @param  array<string, int>  $stockSnapshot
     */
    private function applyStockSnapshot(array $stockSnapshot): int
    {
        $updatedCount = 0;

        foreach ($stockSnapshot as $productId => $stock) {
            $updated = Product::query()
                ->whereKey((int) $productId)
                ->where('stock', '!=', $stock)
                ->update([
                    'stock' => $stock,
                ]);

            $updatedCount += $updated;
        }

        return $updatedCount;
    }

    /**
     * @param  list<array{
     *     product_name:string,
     *     volume_liter:float,
     *     old_quantity:int,
     *     new_quantity:int,
     *     old_cost:float,
     *     new_cost:float,
     *     old_price:float,
     *     new_price:float,
     *     remaining_old_stock:int,
     *     remaining_new_stock:int,
     *     remaining_total_stock:int,
     *     fallback_cost:float,
     *     fallback_price:float
     * }>  $rows
     * @return array{updated_stock_products:int, created_products:int}
     */
    private function syncStockRows(array $rows): array
    {
        $createdProducts = 0;
        $stockSnapshot = [];

        foreach ($rows as $row) {
            $product = $this->findOrCreateProduct(
                $row['product_name'],
                $row['volume_liter'],
                $row['fallback_cost'],
                $row['fallback_price'],
                $createdProducts,
            );

            $stockSnapshot[(string) $product->id] = (int) ($stockSnapshot[(string) $product->id] ?? 0)
                + $row['remaining_total_stock'];
        }

        return [
            'updated_stock_products' => $this->applyStockSnapshot($stockSnapshot),
            'created_products' => $createdProducts,
        ];
    }

    private function normalizeVolumeForSku(float $volumeLiter): string
    {
        return str_replace('.', '-', rtrim(rtrim(number_format($volumeLiter, 2, '.', ''), '0'), '.'));
    }
}

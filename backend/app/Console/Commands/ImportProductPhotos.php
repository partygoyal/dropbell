<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportProductPhotos extends Command
{
    protected $signature = 'catalogue:import-photos {--apply : Apply verified product names and photos} {--manifest= : Override the bundled photo manifest} {--production : Explicitly allow import on the live database}';

    protected $description = 'Preview or import stored, verified brand and pack photos without replacing admin uploads';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) && ! $this->option('production')) {
            $this->error('Use --production to explicitly authorize this import on a live database.');

            return self::FAILURE;
        }

        $manifest = $this->option('manifest') ?: database_path('data/product-photos.json');
        if (! is_file($manifest)) {
            $this->error('The product photo manifest is missing.');

            return self::FAILURE;
        }
        $rows = json_decode(file_get_contents($manifest), true, 512, JSON_THROW_ON_ERROR);
        foreach ($rows as $row) {
            if (! preg_match('/\Ablinkit-[0-9]+\.webp\z/', $row['asset'])) {
                $this->error('Invalid photo filename.');

                return self::FAILURE;
            }
            $file = public_path('assets/products/'.$row['asset']);
            if (! is_file($file) || ! hash_equals($row['sha256'], hash_file('sha256', $file))) {
                $this->error('A photo is missing or has changed: '.$row['asset']);

                return self::FAILURE;
            }
        }

        $updated = 0;
        $skipped = 0;
        DB::transaction(function () use ($rows, &$updated, &$skipped): void {
            foreach ($rows as $row) {
                $templates = DB::table('products')->where('category', $row['category'])->where('pack_size', $row['pack_size']);
                $product = (clone $templates)->where('name', $row['original_name'])->lockForUpdate()->first()
                    ?? (clone $templates)->where('name', $row['product_name'])->lockForUpdate()->first();
                $path = 'products/'.$row['asset'];
                if (! $product || ($product->image_path && $product->image_path !== $path)) {
                    $skipped++;

                    continue;
                }
                if ($product->name === $row['product_name'] && $product->image_path === $path && Storage::disk('public')->exists($path)) {
                    $skipped++;

                    continue;
                }
                $updated++;
                if (! $this->option('apply')) {
                    continue;
                }
                if (! Storage::disk('public')->put($path, file_get_contents(public_path('assets/products/'.$row['asset'])))) {
                    throw new \RuntimeException('Unable to save product photo.');
                }
                $changes = ['name' => $row['product_name'], 'image_path' => $path, 'updated_at' => now()];
                if (str_starts_with($product->description ?? '', $row['original_name'].' · ')) {
                    $changes['description'] = $row['product_name'].' · '.$row['pack_size'].'. Check the product packaging for ingredients and manufacturer details.';
                }
                DB::table('products')->where('id', $product->id)->update($changes);
            }
        });
        $this->info(($this->option('apply') ? 'Imported' : 'Would import')." {$updated} product photos; skipped {$skipped} existing uploads, completed entries or missing templates.");

        return self::SUCCESS;
    }
}

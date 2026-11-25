<?php

namespace Tachyon\MediaLibrary\Console;

use Tachyon\MediaLibrary\Support\UntrackedMediaImporter;
use Illuminate\Console\Command;

class ImportUntrackedMedia extends Command
{
    protected $signature = 'media:import-untracked {--dry-run : Yalnız listele, kayıt ekleme}';

    protected $description = 'Diskte olup medya kütüphanesinde kaydı olmayan dosyaları kütüphaneye kayıt olarak ekler (dosyalara dokunmaz).';

    public function handle(UntrackedMediaImporter $importer): int
    {
        $scan = $importer->scan();

        $byFolder = collect($scan['importable'])
            ->countBy(fn (string $path): string => str_contains($path, '/') ? dirname($path) : '(kök)')
            ->sortDesc();

        $this->info('Eklenecek dosya: '.count($scan['importable']));
        $this->table(['Klasör', 'Dosya'], $byFolder->map(fn ($count, $folder) => [$folder, $count])->values()->all());

        if ($scan['skipped'] !== []) {
            $this->warn('Atlanan: '.count($scan['skipped']));
            foreach ($scan['skipped'] as $path => $reason) {
                $this->line("  - {$path} ({$reason})");
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('Deneme modu: hiçbir kayıt eklenmedi.');

            return self::SUCCESS;
        }

        $result = $importer->import();

        $this->info("Eklendi: {$result['imported']}");

        foreach ($result['failed'] as $path => $error) {
            $this->error("Hata: {$path} — {$error}");
        }

        return $result['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }
}

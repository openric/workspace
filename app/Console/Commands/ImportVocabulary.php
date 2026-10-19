<?php

// OpenRiC Workspace - import SKOS vocabularies from URLs or files.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Console\Commands;

use App\Vocabulary\SkosImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportVocabulary extends Command
{
    protected $signature = 'workspace:vocabulary:import
        {source?* : URL or file path of a SKOS vocabulary in Turtle}
        {--configured : Import every vocabulary listed in config/workspace.php}
        {--bundled : Import the Workspace\'s own vocabularies (resources/vocabularies)}';

    protected $description = 'Load SKOS vocabularies into the Workspace; safe to run again';

    public function handle(SkosImporter $importer): int
    {
        $sources = match (true) {
            (bool) $this->option('bundled') => array_map(fn ($f) => resource_path("vocabularies/$f"), config('workspace.bundled_vocabularies')),
            (bool) $this->option('configured') => config('workspace.vocabularies'),
            default => $this->argument('source'),
        };
        if ($sources === []) {
            $this->error('Give at least one source, or --bundled or --configured.');

            return self::INVALID;
        }

        $failed = 0;
        foreach ($sources as $source) {
            try {
                $result = $importer->import($this->read($source), $this->isUrl($source) ? $source : null);
                $this->info($result->summary());
                foreach ($result->missing as $iri) {
                    $this->warn("  kept, no longer in the source: $iri");
                }
                Log::info('vocabulary imported', ['source' => $source, 'summary' => $result->summary()]);
            } catch (Throwable $e) {
                // One bad source does not stop the others; each import is its own transaction.
                $failed++;
                $this->error("$source: {$e->getMessage()}");
                Log::error('vocabulary import failed', ['source' => $source, 'error' => $e->getMessage()]);
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function read(string $source): string
    {
        if ($this->isUrl($source)) {
            return Http::timeout(30)->accept('text/turtle')->get($source)->throw()->body();
        }
        if (! is_readable($source)) {
            throw new \RuntimeException('file not readable');
        }

        return file_get_contents($source);
    }

    private function isUrl(string $source): bool
    {
        return str_starts_with($source, 'https://') || str_starts_with($source, 'http://');
    }
}

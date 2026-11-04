<?php

// OpenRiC Workspace - the code reference (roadmap phase 20d), after Heratio's ahg-functions-docs FunctionsDocsService:
// catalogues of what the Workspace is made of, for who administers it and for developers: its PHP classes with their
// public methods, its Blade templates, its routes, its console commands and its bundled vocabularies, each entry with
// what its own comment says and where it is.
// Changed from Heratio: built from the code itself, by reflection and by reading each file's header comment, rather
// than read from the Markdown files a KM generator writes to /opt/ai/km; a catalogue is cached until a file it is built
// from changes.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Help;

use App\Models\Concept;
use App\Vocabulary\Dropdowns;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Throwable;

class FunctionsDocs
{
    /** Each catalogue: its title, what an entry is grouped by, and how many groups a page. */
    public const KINDS = [
        'php' => ['title' => 'PHP classes and methods', 'group' => 'Namespace', 'per_page' => 40],
        'blade' => ['title' => 'Blade templates', 'group' => 'Folder', 'per_page' => 60],
        'routes' => ['title' => 'Routes', 'group' => 'Address', 'per_page' => 60],
        'commands' => ['title' => 'Console commands', 'group' => 'Group', 'per_page' => 60],
        'vocabularies' => ['title' => 'Bundled vocabularies', 'group' => 'Kind', 'per_page' => 60],
    ];

    /** @return array<string, array{title: string, entries: int}> */
    public function index(): array
    {
        return collect(self::KINDS)->map(fn ($meta, $kind) => ['title' => $meta['title'], 'entries' => collect($this->catalogue($kind))->sum(fn ($items) => count($items))])->all();
    }

    /**
     * One catalogue: group => its entries, each a name, a signature, what it says and where it is.
     *
     * @return array<string, list<array{name: string, signature: string, summary: string, where: string}>>
     */
    public function catalogue(string $kind): array
    {
        $sources = match ($kind) {
            'php', 'commands' => [app_path()], 'blade' => [resource_path('views')], 'routes' => [base_path('routes'), app_path('Http/Controllers')],
            'vocabularies' => [resource_path('vocabularies')], default => [],
        };
        $stamp = collect($sources)->map(fn ($dir) => collect(Finder::create()->files()->in($dir))->max(fn ($f) => $f->getMTime()))->max();

        return Cache::remember("functions-docs:{$kind}:{$stamp}", now()->addDay(), fn () => match ($kind) {
            'php' => $this->classes(), 'blade' => $this->templates(), 'routes' => $this->routes(), 'commands' => $this->commands(), 'vocabularies' => $this->vocabularies(),
        });
    }

    private function classes(): array
    {
        $groups = [];
        foreach (Finder::create()->files()->name('*.php')->in(app_path())->sortByName() as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            try {
                $reflection = new ReflectionClass($class);
            } catch (Throwable) {
                continue;
            }
            $where = 'app/'.$file->getRelativePathname();
            $entries = [['name' => $reflection->getShortName(), 'signature' => $this->kindOf($reflection), 'summary' => $this->header($file->getContents()), 'where' => $where.':'.$reflection->getStartLine()]];
            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->getDeclaringClass()->getName() !== $class || $method->getFileName() !== $reflection->getFileName()) {
                    continue;
                }
                $entries[] = ['name' => '  '.$method->getName(), 'signature' => $this->signature($method), 'summary' => $this->docSummary((string) $method->getDocComment()), 'where' => $where.':'.$method->getStartLine()];
            }
            $groups[$reflection->getNamespaceName()][] = $entries;
        }
        ksort($groups);

        return array_map(fn ($classes) => array_merge(...$classes), $groups);
    }

    private function templates(): array
    {
        $groups = [];
        foreach (Finder::create()->files()->name('*.blade.php')->in(resource_path('views'))->sortByName() as $file) {
            $name = str_replace(['/', '.blade.php'], ['.', ''], $file->getRelativePathname());
            preg_match('/\{\{--\s*(.*?)--\}\}/s', $file->getContents(), $m);
            $groups[$file->getRelativePath() ?: '.'][] = ['name' => $name, 'signature' => '@include(\''.$name.'\')', 'summary' => $this->clean($m[1] ?? ''),
                'where' => 'resources/views/'.$file->getRelativePathname()];
        }
        ksort($groups);

        return $groups;
    }

    private function routes(): array
    {
        $groups = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            $summary = '';
            if (str_contains($action, '@') && method_exists(...explode('@', $action))) {
                $summary = $this->docSummary((string) (new ReflectionMethod(...explode('@', $action)))->getDocComment());
            }
            $groups['/'.explode('/', ltrim($route->uri(), '/'))[0]][] = ['name' => (string) ($route->getName() ?? ''), 'signature' => implode('|', array_diff($route->methods(), ['HEAD'])).' /'.ltrim($route->uri(), '/'),
                'summary' => $summary, 'where' => str_replace('App\\Http\\Controllers\\', '', $action).($route->gatherMiddleware() ? ' ['.implode(', ', array_map(fn ($m) => is_string($m) ? $m : 'closure', $route->gatherMiddleware())).']' : '')];
        }
        ksort($groups);

        return $groups;
    }

    private function commands(): array
    {
        $groups = [];
        foreach (Artisan::all() as $name => $command) {
            if (! str_starts_with($command::class, 'App\\')) {
                continue;
            }
            $groups[str_contains($name, ':') ? explode(':', $name)[0] : $name][] = ['name' => $name, 'signature' => 'php artisan '.$command->getSynopsis(),
                'summary' => (string) $command->getDescription(), 'where' => str_replace('App\\', 'app/', str_replace('\\', '/', $command::class)).'.php'];
        }
        ksort($groups);

        return $groups;
    }

    private function vocabularies(): array
    {
        $dropdowns = app(Dropdowns::class);
        $counts = Concept::whereNull('institution_id')->whereNull('deprecated_at')->selectRaw('vocabulary_id, count(*) as n')->groupBy('vocabulary_id')->pluck('n', 'vocabulary_id');
        $groups = [];
        foreach ($dropdowns->vocabularies() as $vocabulary) {
            $name = Dropdowns::name($vocabulary);
            $groups[$dropdowns->isOpen($name) ? 'Open lists' : 'Fixed lists'][] = ['name' => $name, 'signature' => trans_choice(':count term|:count terms', $counts[$vocabulary->id] ?? 0, ['count' => $counts[$vocabulary->id] ?? 0]),
                'summary' => (string) $vocabulary->description, 'where' => 'resources/vocabularies/'.$name.'.ttl'];
        }
        ksort($groups);

        return $groups;
    }

    private function kindOf(ReflectionClass $reflection): string
    {
        return match (true) {
            $reflection->isInterface() => 'interface', $reflection->isTrait() => 'trait', $reflection->isEnum() => 'enum', $reflection->isAbstract() => 'abstract class',
            default => 'class'.(($parent = $reflection->getParentClass()) ? ' extends '.$parent->getShortName() : ''),
        };
    }

    private function signature(ReflectionMethod $method): string
    {
        $parameters = array_map(fn ($p) => ($p->hasType() ? $p->getType().' ' : '').'$'.$p->getName().($p->isDefaultValueAvailable() ? ' = '.var_export($p->getDefaultValue(), true) : ''), $method->getParameters());

        return ($method->isStatic() ? 'static ' : '').$method->getName().'('.implode(', ', $parameters).')'.($method->hasReturnType() ? ': '.$method->getReturnType() : '');
    }

    /** The comment at the top of a PHP file, without the copyright lines. */
    private function header(string $source): string
    {
        preg_match('/^<\?php\s+((?:\/\/.*\n)+)/', $source, $m);

        return $this->clean(preg_replace('/^\/\/ ?/m', '', $m[1] ?? ''));
    }

    /** The first paragraph of a docblock. */
    private function docSummary(string $doc): string
    {
        $text = trim(preg_replace('/^\s*\/?\*+\/?\s?/m', '', $doc));

        return $this->clean(preg_split('/\n\s*\n|\n@/', $text)[0] ?? '');
    }

    private function clean(string $text): string
    {
        $text = preg_replace('/\s*(Copyright \(C\)|SPDX-License-Identifier).*$/s', '', $text);

        return trim(preg_replace('/\s+/', ' ', preg_replace('/^OpenRiC Workspace - /', '', trim($text))));
    }
}

<?php

// OpenRiC Workspace - make the Workspace's own vocabulary IRIs resolve.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later
//
// https://workspace.openric.org/vocab/{scheme}/ and each concept IRI under it
// answer with Turtle when asked for it, and with an HTML page otherwise. These
// are public: they hold only the published vocabulary, the one deliberate
// exception to default deny (routes/web.php). Only the bundled vocabularies are
// served; OpenRiC's own are published at openric.org.

namespace App\Http\Controllers;

use App\Models\Concept;
use App\Models\Vocabulary;
use App\Support\Vocab;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VocabularyController extends Controller
{
    public function scheme(Request $request, string $scheme): Response
    {
        $file = $this->file($scheme);
        if ($this->wantsTurtle($request)) {
            return $this->turtle($file);
        }
        $vocabulary = Vocabulary::where('iri', Vocab::BASE.$scheme.'/')->firstOrFail();
        $concepts = $vocabulary->concepts()->with('labels', 'broader')->orderBy('notation')->get();

        return response()->view('vocab.scheme', ['vocabulary' => $vocabulary, 'concepts' => $concepts]);
    }

    public function turtleFile(string $scheme): Response
    {
        return $this->turtle($this->file($scheme));
    }

    public function concept(Request $request, string $scheme, string $notation): Response
    {
        $file = $this->file($scheme);
        if ($this->wantsTurtle($request)) {
            return $this->turtle($file);
        }
        $concept = Concept::where('iri', Vocab::BASE.$scheme.'/'.$notation)
            ->with('labels', 'vocabulary', 'broader', 'replacedBy')->firstOrFail();

        return response()->view('vocab.concept', ['concept' => $concept]);
    }

    private function file(string $scheme): string
    {
        $name = $scheme.'.ttl';
        abort_unless(in_array($name, config('workspace.bundled_vocabularies'), true), 404);

        return resource_path('vocabularies/'.$name);
    }

    private function wantsTurtle(Request $request): bool
    {
        return str_contains((string) $request->header('Accept'), 'text/turtle');
    }

    private function turtle(string $file): Response
    {
        return response(file_get_contents($file), 200, [
            'Content-Type' => 'text/turtle; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }
}

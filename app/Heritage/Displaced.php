<?php

/*
 * OpenRiC Workspace - copied from Heratio, packages/ahg-semantic-search/src/Services/DisplacedHeritageService.php at
 * commit bca348f16d70f4a7defb2c6d4923248a88c4068f (governance/CODE_PROVENANCE.md, roadmap phase 17d3): the
 * displaced-heritage register, a conservative, heuristic origin-against-holding test that flags for curatorial review
 * a description whose recorded origin and the place it is held resolve to different countries or regions, and never
 * one where either is unknown; the table of places, peoples and cultures and of country codes, regionFor (the longest
 * whole-word match first), the order of the register, its disclaimer, and the confidence of each trace (Heratio's
 * DisplacedHeritageRegisterController::confidenceBand). Changed: Heratio reads its museum fields (place of creation,
 * of discovery, cultural group and context), which the Workspace has from phase 21; until then the origin is the
 * location of the earliest owner in the description's provenance, and where it is held is the country of its
 * repository's contact point, else the location of its latest owner; it is read per institution, and for the public
 * catalogue only from what it shows; the namespace and code style.
 * Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace App\Heritage;

use App\Api\PublicCatalogue;
use App\Models\ContactPoint;
use App\Models\Institution;
use App\Models\RecordResource;
use App\Relations\EntityKinds;
use App\Support\Vocab;
use Illuminate\Support\Facades\DB;

class Displaced
{
    public const DISCLAIMER = 'This register is a curatorial review aid only. It heuristically flags catalogue records whose recorded place of origin appears to differ from where the object is now held. A flag is not a determination that an object was wrongfully removed, not a repatriation claim, and not legal advice. Origin, ownership and lawful-transfer history must be assessed by qualified staff against the relevant evidence and applicable law in every case.';

    private const REGIONS = [
        // --- Africa ---
        'south africa' => 'South Africa', 'south african' => 'South Africa',
        'egypt' => 'Egypt', 'egyptian' => 'Egypt', 'ancient egypt' => 'Egypt', 'ancient egyptian' => 'Egypt',
        'nubia' => 'Sudan', 'nubian' => 'Sudan', 'sudan' => 'Sudan', 'sudanese' => 'Sudan',
        'nigeria' => 'Nigeria', 'nigerian' => 'Nigeria', 'benin' => 'Nigeria', 'yoruba' => 'Nigeria',
        'ethiopia' => 'Ethiopia', 'ethiopian' => 'Ethiopia',
        'ghana' => 'Ghana', 'ghanaian' => 'Ghana', 'asante' => 'Ghana', 'ashanti' => 'Ghana',
        'mali' => 'Mali', 'malian' => 'Mali',
        'zimbabwe' => 'Zimbabwe', 'zimbabwean' => 'Zimbabwe',
        'kenya' => 'Kenya', 'kenyan' => 'Kenya',
        'morocco' => 'Morocco', 'moroccan' => 'Morocco',
        // --- Europe ---
        'united kingdom' => 'United Kingdom', 'great britain' => 'United Kingdom', 'britain' => 'United Kingdom',
        'british' => 'United Kingdom', 'english' => 'United Kingdom', 'england' => 'United Kingdom',
        'scotland' => 'United Kingdom', 'scottish' => 'United Kingdom', 'wales' => 'United Kingdom', 'welsh' => 'United Kingdom',
        'edwardian' => 'United Kingdom', 'victorian' => 'United Kingdom',
        'france' => 'France', 'french' => 'France', 'gaul' => 'France', 'gallic' => 'France',
        'germany' => 'Germany', 'german' => 'Germany',
        'italy' => 'Italy', 'italian' => 'Italy', 'rome' => 'Italy', 'roman' => 'Italy',
        'greece' => 'Greece', 'greek' => 'Greece', 'hellenic' => 'Greece',
        'spain' => 'Spain', 'spanish' => 'Spain',
        'portugal' => 'Portugal', 'portuguese' => 'Portugal',
        'netherlands' => 'Netherlands', 'dutch' => 'Netherlands', 'holland' => 'Netherlands',
        'belgium' => 'Belgium', 'belgian' => 'Belgium',
        'austria' => 'Austria', 'austrian' => 'Austria',
        'russia' => 'Russia', 'russian' => 'Russia',
        // --- Middle East / Asia ---
        'turkey' => 'Turkey', 'turkish' => 'Turkey', 'ottoman' => 'Turkey', 'anatolia' => 'Turkey', 'anatolian' => 'Turkey',
        'iran' => 'Iran', 'iranian' => 'Iran', 'persia' => 'Iran', 'persian' => 'Iran',
        'iraq' => 'Iraq', 'iraqi' => 'Iraq', 'mesopotamia' => 'Iraq', 'mesopotamian' => 'Iraq', 'assyrian' => 'Iraq', 'babylonian' => 'Iraq',
        'india' => 'India', 'indian' => 'India',
        'china' => 'China', 'chinese' => 'China',
        'japan' => 'Japan', 'japanese' => 'Japan',
        'korea' => 'Korea', 'korean' => 'Korea',
        'thailand' => 'Thailand', 'thai' => 'Thailand',
        'cambodia' => 'Cambodia', 'cambodian' => 'Cambodia', 'khmer' => 'Cambodia',
        // --- Americas / Oceania ---
        'united states' => 'United States', 'usa' => 'United States', 'american' => 'United States',
        'mexico' => 'Mexico', 'mexican' => 'Mexico', 'aztec' => 'Mexico', 'maya' => 'Mexico', 'mayan' => 'Mexico',
        'peru' => 'Peru', 'peruvian' => 'Peru', 'inca' => 'Peru', 'incan' => 'Peru',
        'australia' => 'Australia', 'australian' => 'Australia', 'aboriginal' => 'Australia',
        'new zealand' => 'New Zealand', 'maori' => 'New Zealand',
    ];

    private const COUNTRY_CODES = [
        'ZA' => 'South Africa', 'EG' => 'Egypt', 'SD' => 'Sudan', 'NG' => 'Nigeria',
        'ET' => 'Ethiopia', 'GH' => 'Ghana', 'ML' => 'Mali', 'ZW' => 'Zimbabwe',
        'KE' => 'Kenya', 'MA' => 'Morocco',
        'GB' => 'United Kingdom', 'UK' => 'United Kingdom', 'FR' => 'France', 'DE' => 'Germany',
        'IT' => 'Italy', 'GR' => 'Greece', 'ES' => 'Spain', 'PT' => 'Portugal',
        'NL' => 'Netherlands', 'BE' => 'Belgium', 'AT' => 'Austria', 'RU' => 'Russia',
        'TR' => 'Turkey', 'IR' => 'Iran', 'IQ' => 'Iraq', 'IN' => 'India',
        'CN' => 'China', 'JP' => 'Japan', 'KR' => 'Korea', 'TH' => 'Thailand', 'KH' => 'Cambodia',
        'US' => 'United States', 'MX' => 'Mexico', 'PE' => 'Peru', 'AU' => 'Australia', 'NZ' => 'New Zealand',
    ];

    /**
     * The register: every description of the institution whose origin and holding resolve to different regions, the
     * largest groups of origin first. $catalogue limits it to what the public catalogue shows.
     *
     * @return array{disclaimer: string, scanned: int, evaluated: int, flagged_count: int, truncated: bool, records: list<array>, by_origin: list<array{region: string, count: int}>}
     */
    public function scan(Institution $institution, ?PublicCatalogue $catalogue = null, int $limit = 500): array
    {
        $ids = DB::table('provenance_entries')->where('institution_id', $institution->id)->whereNull('deleted_at')->whereNotNull('owner_location')
            ->distinct()->pluck('record_resource_id');
        $flagged = [];
        $evaluated = 0;
        $byOrigin = [];
        foreach (RecordResource::whereIn('id', $ids)->whereNull('deleted_at')->with('repository')->get() as $record) {
            if ($catalogue !== null && $catalogue->find('records', $record->id) === null) {
                continue;
            }
            $trace = $this->trace($record);
            if ($trace['evaluated']) {
                $evaluated++;
            }
            if ($trace['record'] !== null) {
                $flagged[] = $trace['record'];
                $byOrigin[$trace['record']['origin_region']] = ($byOrigin[$trace['record']['origin_region']] ?? 0) + 1;
            }
        }
        // Heratio's order: the largest groups of origin first, then the region's name, then the description.
        usort($flagged, fn ($a, $b) => [-$byOrigin[$a['origin_region']], $a['origin_region'], $a['title']] <=> [-$byOrigin[$b['origin_region']], $b['origin_region'], $b['title']]);
        arsort($byOrigin);
        $count = count($flagged);

        return ['disclaimer' => self::DISCLAIMER, 'scanned' => $ids->count(), 'evaluated' => $evaluated, 'flagged_count' => $count,
            'truncated' => $limit > 0 && $count > $limit, 'records' => $limit > 0 ? array_slice($flagged, 0, $limit) : $flagged,
            'by_origin' => array_map(fn ($r, $c) => ['region' => $r, 'count' => $c], array_keys($byOrigin), $byOrigin)];
    }

    /**
     * One description's trace: whether both sides resolved, and the register's record where they differ.
     *
     * @return array{evaluated: bool, record: ?array}
     */
    public function trace(RecordResource $record): array
    {
        $steps = DB::table('provenance_entries')->where('record_resource_id', $record->id)->whereNull('deleted_at')->whereNotNull('owner_location')
            ->orderBy('position')->get(['owner_location', 'owner_location_tgn']);
        $origin = null;
        foreach ($steps as $step) {
            if ($region = $this->regionFor((string) $step->owner_location)) {
                $origin = ['field' => 'provenance', 'label' => __('Location of the earliest recorded owner'), 'value' => (string) $step->owner_location, 'region' => $region, 'tgn' => $step->owner_location_tgn];
                break;
            }
        }
        $holding = $this->holding($record, $steps->reverse()->values());
        // Conservative: nothing is flagged unless both sides are known; the same region is not displaced.
        if ($origin === null || $holding === null) {
            return ['evaluated' => false, 'record' => null];
        }
        if ($origin['region'] === $holding['region']) {
            return ['evaluated' => true, 'record' => null];
        }

        return ['evaluated' => true, 'record' => ['id' => $record->id, 'title' => EntityKinds::label($record, Vocab::languages()),
            'origin_region' => $origin['region'], 'holding_region' => $holding['region'], 'origin' => $origin, 'holding' => $holding,
            'confidence' => $this->confidence($origin, $holding),
            'reason' => __('Recorded origin region ":origin" (:origin_label: ":origin_value") differs from holding region ":holding" (:holding_label: ":holding_value"). Flagged for curatorial review - not a determination of wrongful removal.',
                ['origin' => $origin['region'], 'origin_label' => $origin['label'], 'origin_value' => $this->snippet($origin['value']),
                    'holding' => $holding['region'], 'holding_label' => $holding['label'], 'holding_value' => $this->snippet($holding['value'])])]];
    }

    /** The register's record for one description, or null where it is not traced. */
    public function find(RecordResource $record): ?array
    {
        return $this->trace($record)['record'];
    }

    /** The region a text names: the longest known place, people or culture in it, whole words only; null when none. */
    public function regionFor(string $text): ?string
    {
        $norm = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower(trim($text)))));
        if ($norm === '') {
            return null;
        }
        $tokens = array_keys(self::REGIONS);
        usort($tokens, fn ($a, $b) => strlen($b) <=> strlen($a));
        foreach ($tokens as $token) {
            if (preg_match('/(?<![a-z])'.preg_quote($token, '/').'(?![a-z])/', $norm)) {
                return self::REGIONS[$token];
            }
        }

        return null;
    }

    /** Where it is held: its repository's country, else the location of its latest owner that resolves. */
    private function holding(RecordResource $record, $stepsLatestFirst): ?array
    {
        if ($record->repository) {
            $code = ContactPoint::where(['subject_table' => $record->repository->getTable(), 'subject_id' => $record->repository->id])->whereNotNull('country_code')
                ->orderByDesc('is_primary')->value('country_code');
            if ($code && isset(self::COUNTRY_CODES[$code])) {
                return ['field' => 'repository_country_code', 'label' => __('Holding repository country'), 'value' => $code, 'region' => self::COUNTRY_CODES[$code]];
            }
        }
        foreach ($stepsLatestFirst as $step) {
            if ($region = $this->regionFor((string) $step->owner_location)) {
                return ['field' => 'provenance', 'label' => __('Location of the latest recorded owner'), 'value' => (string) $step->owner_location, 'region' => $region];
            }
        }

        return null;
    }

    /** Heratio's confidence bands: a place on both sides is well documented, on one side documented. */
    private function confidence(array $origin, array $holding): string
    {
        $originIsPlace = ($origin['tgn'] ?? null) !== null;
        $holdingIsPlace = $holding['field'] === 'repository_country_code';

        return $originIsPlace && $holdingIsPlace ? 'well-documented' : ($originIsPlace || $holdingIsPlace ? 'documented' : 'indicative');
    }

    private function snippet(string $value, int $max = 80): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));

        return mb_strlen($value) <= $max ? $value : mb_substr($value, 0, $max - 1).'…';
    }
}

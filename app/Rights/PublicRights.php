<?php

// OpenRiC Workspace - the rights and Local Contexts Labels of a unit of description or an instantiation, as the public
// may see them (WS-007, roadmap phase 2): rights in force today, a holder only if the view may read it. For the public
// catalogue, the files API and every export, so they all say the same.
// Copyright (C) 2026 Johan Pieterse / Plain Sailing Information Systems
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Rights;

use App\Exchange\ExportView;
use App\Models\Right;
use App\Support\Vocab;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class PublicRights
{
    public function __construct(private ExportView $view) {}

    /** @return Collection<int, Right> in force today, each with its holder unset when the view may not read it */
    public function rights(Model $subject): Collection
    {
        return app(RightsService::class)->of($subject)->filter(fn (Right $r) => $r->inForce())
            ->each(fn (Right $r) => $r->holder && ! $this->view->readable($r->holder) ? $r->setRelation('holder', null) : null)->values();
    }

    public function labels(Model $subject): Collection
    {
        return app(CommunityLabelService::class)->of($subject)->each(fn ($l) => $l->community && ! $this->view->readable($l->community) ? $l->setRelation('community', null) : null);
    }

    /** One line for a right: its basis, statement and licence. */
    public static function summary(Right $right): string
    {
        $lang = Vocab::languages();

        return collect([$right->basis->label($lang), $right->statement?->label($lang), $right->licence?->label($lang)])->filter()->join(' · ');
    }
}

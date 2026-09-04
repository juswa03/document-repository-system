<?php

namespace Database\Seeders;

use App\Models\StrategicObjective;
use Illuminate\Database\Seeder;

/**
 * PLACEHOLDER strategic-objective tree. The real one comes from the
 * parent objectives document referenced by decision 0.8 ("item 1.4
 * Generate compliant reports") — replace these rows (or manage them
 * through the admin screen) once it is supplied. Nothing in the code
 * depends on these specific codes.
 */
class StrategicObjectiveSeeder extends Seeder
{
    private const TREE = [
        ['G1', 'Deliver quality, relevant and accessible programs', [
            ['G1.1', 'Sustain program accreditation and quality assurance'],
            ['G1.2', 'Improve student outcomes and completion'],
        ]],
        ['G2', 'Advance research, extension and innovation', [
            ['G2.1', 'Grow externally funded research'],
            ['G2.2', 'Translate research into community extension'],
        ]],
        ['G3', 'Strengthen governance, resources and services', [
            ['G3.1', 'Sound fiscal management and resource generation'],
            ['G3.2', 'Efficient, digitalised administrative services'],
            ['G3.3', 'Compliant, transparent and accountable operations'],
        ]],
    ];

    public function run(): void
    {
        foreach (self::TREE as $i => [$code, $title, $children]) {
            $goal = StrategicObjective::updateOrCreate(
                ['code' => $code],
                ['title' => $title, 'parent_id' => null, 'sort_order' => $i, 'is_active' => true],
            );

            foreach ($children as $j => [$childCode, $childTitle]) {
                StrategicObjective::updateOrCreate(
                    ['code' => $childCode],
                    ['title' => $childTitle, 'parent_id' => $goal->id, 'sort_order' => $j, 'is_active' => true],
                );
            }
        }
    }
}

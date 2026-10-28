<?php

namespace App\Data\Analytics;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ein Lehrling in der Gruppen-Auswertung (Block „Lehrlinge").
 */
readonly class GroupMemberRow
{
    /**
     * @param  bool  $isInactive  länger als die Gruppen-Schwelle ohne Aktivität — oder nie aktiv
     */
    public function __construct(
        public User $member,
        public int $averagePercent,
        public int $completedPaths,
        public int $totalPaths,
        public ?Carbon $lastActivityAt,
        public bool $isInactive,
    ) {
        //
    }
}

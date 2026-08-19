<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Arrears follow-up
    |--------------------------------------------------------------------------
    |
    | How many days a logged contact stays "recent" before the tenant returns
    | to the worklist as needing another follow-up. A tenant with an OPEN
    | promise is parked until the promised date regardless of this.
    |
    */

    'follow_up_stale_days' => (int) env('COLLECTIONS_FOLLOW_UP_STALE_DAYS', 14),

];

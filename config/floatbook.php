<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Demo mode
    |--------------------------------------------------------------------------
    |
    | When this is on, the landing page offers one-click sign-ins to the demo
    | business ("Ade & Sons POS") and its data is rebuilt every night. It is off
    | unless DEMO_MODE=true is set, so a real deployment can never drift into it.
    |
    */

    'demo' => [
        'enabled' => env('DEMO_MODE', false),

        // Local time of day to rebuild the demo data, in the business's own zone.
        'reset_at' => env('DEMO_RESET_AT', '02:30'),
    ],

];

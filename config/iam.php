<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Platform admin bootstrap
    |--------------------------------------------------------------------------
    |
    | The identities that hold `tech_admin` from a standing start. `tech_admin`
    | is the only role the staff editor cannot grant (see
    | Role::isAssignableByAdmin) — it is issued from the platform portal, behind
    | a passcode, by someone who already holds it. That is a closed loop, so
    | somebody has to be inside it to begin with, and this is who.
    |
    | Everyone beyond these is added the normal way:
    | POST /v1/platform/admins, which requires an existing platform admin, their
    | 6-digit passcode, and refuses to act on the caller's own account.
    |
    | Matched on email or on the canonical +233 form of the phone. Entries that
    | match no existing user are reported and skipped — this grants a role to an
    | account, it does not create one.
    |
    */

    'tech_admin_bootstrap' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'IAM_TECH_ADMIN_BOOTSTRAP',
            'richardsomdajnr@gmail.com,0592123054',
        )),
    ))),

];

<?php
/* Copy to config_supabase.php (same folder as api_supabase.php) and fill in.
 * Keep this file server-side only — never commit real keys. */
return [
    'url'          => 'https://ljximlcmikpvdtvodwwt.supabase.co',
    'service_key'  => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6ImxqeGltbGNtaWtwdmR0dm9kd3d0Iiwicm9sZSI6InNlcnZpY2Vfcm9sZSIsImlhdCI6MTc5MTM4MTE1NiwiZXhwIjoyMTA2OTU3MTU2fQ.YDi1MrdcRMgfg36IPGdHSR8umI93zQcmjQZK-DYYWVY',     // or sb_secret_... ; ROTATE it if it was ever exposed
    'bucket'       => 'mywms',

    // Long random secret used to sign login tokens. Generate one, e.g.:
    //   php -r "echo bin2hex(random_bytes(32));"
    // Changing it signs everybody out.
    'token_secret' => '7a34c32e29a0bf1fafa264cbcace52a2fad01e65e782b1d8a052f534ff3723a21058c7351d1003effd698c17dc7f8a9b5b65d3cb50eaa925fbe9135ed91935b1',
    'token_ttl'    => 12 * 3600,

    // Browser origins (scheme + host [+ port]) allowed to call the API.
    'allowed_origins' => ['https://myib.online/'],

    // Optional legacy app key. It is visible in the page source, so it is NOT a
    // security control; leave empty unless an old client still needs it.
    'api_key'      => 'smywms',

    // Only used when the users list is completely empty (fresh install):
    // lets "superadmin" sign in once with this password. Clear it afterwards.
    'bootstrap_password' => '',
];
   
<?php
/* Copy this file to config_supabase.php (same folder as api_supabase.php) and fill in.
 * Keep this file server-side only — never commit real keys. */
return [
    'url'          => 'https://YOUR-PROJECT.supabase.co',
    'service_key'  => '',     // or sb_secret_... ; ROTATE it if it was ever exposed
    'bucket'       => 'mywms',

    // Long random secret used to sign login tokens. Generate one, e.g.:
    //   php -r "echo bin2hex(random_bytes(32));"
    // Changing it signs everybody out.
    'token_secret' => '',
    'token_ttl'    => 12 * 3600,

    // Browser origins (scheme + host [+ port]) allowed to call the API.
    'allowed_origins' => ['https://your-domain.example'],

    // Optional legacy app key. It is visible in the page source, so it is NOT a
    // security control; leave empty unless an old client still needs it.
    'api_key'      => 'smywms',

    // Only used when the users list is completely empty (fresh install):
    // lets "superadmin" sign in once with this password. Clear it afterwards.
    'bootstrap_password' => '',
];
   
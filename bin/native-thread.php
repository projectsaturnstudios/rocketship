<?php

/*
|--------------------------------------------------------------------------
| Bootstrap A Native Worker Thread
|--------------------------------------------------------------------------
|
| Every parallel runtime starts as a fresh request. Octane's dd() fix has to
| be in place before the application's autoloader defines its own, so this
| file loads it first, then the autoloader the supervisor put in the env.
|
*/

ini_set('display_errors', 'stderr');

require __DIR__.'/../fixes/fix-symfony-dd.php';

require getenv('ROCKETSHIP_AUTOLOAD');

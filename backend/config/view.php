<?php

return [

    'paths' => [
        resource_path('views'),
    ],

    // Do not use realpath here: a fresh Railway container does not have the
    // ignored storage directories yet, so realpath() would return false and
    // mail notifications that render Markdown views would fail.
    'compiled' => env('VIEW_COMPILED_PATH', storage_path('framework/views')),

];

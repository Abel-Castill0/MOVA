<?php

return [

    // AZ-3F: el FQDN temporal de Azure Container Apps es público y sin esto
    // quedaba indexable — robots.txt no restringía nada y no había
    // X-Robots-Tag. Default seguro: false. Se activa deliberadamente como
    // parte del cutover a dominio final, no antes.
    'indexing_enabled' => (bool) env('SEARCH_INDEXING_ENABLED', false),

];

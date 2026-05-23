<?php
/**
 * Plantilla de configuración. Copiar a config.local.php (gitignored)
 * y rellenar con las credenciales reales del entorno.
 */
return [
    'db' => [
        'socket'   => '/run/mysqld/mysqld10.sock',  // Synology MariaDB10
        'host'     => null,                          // se usa socket; null = ignorar host/port
        'port'     => 3306,
        'name'     => 'siscormed',
        'user'     => 'siscormed_app',
        'password' => 'CAMBIAR_AQUI',
    ],
    'make_webhook' => 'https://hook.us2.make.com/q2djoj1p4lkshf6bwjzd4xxhpeagbo4q',
    // Orígenes permitidos para CORS. Vacío = permitir mismo-origen (el HTML está servido
    // por el mismo nginx que el API, así que CORS no debería ser necesario para producción).
    'cors_origins' => [],
];

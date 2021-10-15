<?php
require __DIR__ . '/vendor/autoload.php';

$argv = [
    './bin/deploy',
    '--deploy-cache-dir=./test/deploy-cache',
    '--deploy-dir=./test',
    '--revision=123456',
    '--symlinks={"shared/config/env":".env","shared/storage":"storage"}'
];

require __DIR__ . '/atomic-deploy.php';

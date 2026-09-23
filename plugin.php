<?php
return array(
    'id' => 'm365-copilot-response-generator:osticket',
    'version' => '0.2.0',
    'name' => 'Microsoft 365 Copilot Response Generator',
    'description' => 'Creates Microsoft 365 Copilot solution suggestions as internal ticket notes.',
    'author' => 'Nettdrift AS',
    'ost_version' => MAJOR_VERSION,
    'plugin' => 'src/AIResponsePlugin.php:AIResponseGeneratorPlugin',
    'include_path' => '',
    'url' => 'https://github.com/mhajder/ai-response-generator',
);

<?php

// Apply custom config if available
if (file_exists(__DIR__ . '/local.settings.php')) {
    include __DIR__ . '/local.settings.php';
}

// This is the default config. See `deploy_config/README.md' for more info.
$config = array(
    'debug' => true,
    'monolog.level' => \Monolog\Logger::DEBUG,
    'monolog.logfile' => 'php://stderr',
    'twig.path' => __DIR__ . '/../src/resources/views',
	'db.dsn_from_env_var' => $_ENV['DATABASE_URL_ENV_NAME'],
	'swiftmailer.options.host' => 'smtp.sendgrid.net',
	'swiftmailer.options.port' => '587',
	'swiftmailer.options.username' => 'toplink',
	'swiftmailer.options.password.env' => 'HEROKU_SENDGRID_PASSWORD',
    'twig.options' => array(
        'cache' => __DIR__ . '/cache/twig',
    ),
);


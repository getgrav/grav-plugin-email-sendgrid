<?php return array(
    'root' => array(
        'name' => 'getgrav/email-sendgrid',
        'pretty_version' => '1.0.0+no-version-set',
        'version' => '1.0.0.0',
        'reference' => NULL,
        'type' => 'grav-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'getgrav/email-sendgrid' => array(
            'pretty_version' => '1.0.0+no-version-set',
            'version' => '1.0.0.0',
            'reference' => NULL,
            'type' => 'grav-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'psr/event-dispatcher' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'symfony/mailer' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '*',
            ),
        ),
        'symfony/sendgrid-mailer' => array(
            'pretty_version' => 'v5.4.7',
            'version' => '5.4.7.0',
            'reference' => '8c1ef727aed18460e91a3ec54237a0e48b80a1dd',
            'type' => 'symfony-mailer-bridge',
            'install_path' => __DIR__ . '/../symfony/sendgrid-mailer',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);

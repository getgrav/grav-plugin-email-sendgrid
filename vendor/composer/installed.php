<?php return array(
    'root' => array(
        'name' => 'getgrav/email-sendgrid',
        'pretty_version' => 'dev-develop',
        'version' => 'dev-develop',
        'reference' => 'e19eed2fa22ab2427a1002c5db63c0211fb91901',
        'type' => 'grav-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'getgrav/email-sendgrid' => array(
            'pretty_version' => 'dev-develop',
            'version' => 'dev-develop',
            'reference' => 'e19eed2fa22ab2427a1002c5db63c0211fb91901',
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
            'pretty_version' => 'v5.4.35',
            'version' => '5.4.35.0',
            'reference' => '3d06d0cd4f689d0f541069f8c44399723568863e',
            'type' => 'symfony-mailer-bridge',
            'install_path' => __DIR__ . '/../symfony/sendgrid-mailer',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);

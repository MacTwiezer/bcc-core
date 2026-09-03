<?php

function bcc_demo_login_enabled()
{
    global $BCC_DEMO_LOGIN;

    return isset($BCC_DEMO_LOGIN) && $BCC_DEMO_LOGIN === true;
}

function bcc_demo_password()
{
    global $BCC_DEMO_PASSWORD;

    return (isset($BCC_DEMO_PASSWORD) && is_string($BCC_DEMO_PASSWORD) && $BCC_DEMO_PASSWORD !== '')
        ? $BCC_DEMO_PASSWORD
        : null;
}

function bcc_demo_accounts()
{
    $password = bcc_demo_password();
    if ($password === null) {
        return array();
    }

    return array(
        array(
            'email' => 'owner@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Owner',
            'role' => 'owner',
            'label' => 'Owner',
            'hint' => 'Base oluşturur/siler, rol atar',
        ),
        array(
            'email' => 'editor@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Editor',
            'role' => 'editor',
            'label' => 'Editor',
            'hint' => 'Kayıt/alan düzenler, base OLUŞTURAMAZ',
        ),
        array(

            'email' => 'commenter@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Commenter',
            'role' => 'commenter',
            'label' => 'Commenter',
            'hint' => 'Yorum yazar, kayıt/alan DÜZENLEYEMEZ',
        ),
        array(
            'email' => 'viewer@bcc.local',
            'password' => $password,
            'full_name' => 'Demo Viewer',
            'role' => 'viewer',
            'label' => 'Viewer',
            'hint' => 'Yalnızca görüntüler',
        ),
    );
}

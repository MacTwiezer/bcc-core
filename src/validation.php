<?php

function bcc_is_valid_email($email)
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function bcc_is_valid_password($password)
{
    $len = strlen($password);

    return $len >= 8 && $len <= 72;
}

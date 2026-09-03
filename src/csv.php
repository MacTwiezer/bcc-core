<?php

function bcc_csv_injection_guard($value)
{
    $value = (string) $value;

    return ($value !== '' && preg_match('/^[=+\-@]/', $value) === 1) ? ("'" . $value) : $value;
}

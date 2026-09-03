<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die("Bu dosya yalnizca komut satirindan kullanilabilir.\n");
}

function bcc_test_silence_slack()
{
    $hooks = bcc_fetch_all('SELECT id FROM slack_webhooks WHERE is_active = 1');

    register_shutdown_function(function () use ($hooks) {
        foreach ($hooks as $h) {
            bcc_execute('UPDATE slack_webhooks SET is_active = 1 WHERE id = :i', array('i' => (int) $h['id']));
        }
    });

    foreach ($hooks as $h) {
        bcc_execute('UPDATE slack_webhooks SET is_active = 0 WHERE id = :i', array('i' => (int) $h['id']));
    }

    return count($hooks);
}

function bcc_test_purge_own_audit()
{
    $since = (int) bcc_fetch_column('SELECT COALESCE(MAX(id), 0) FROM audit_log');

    register_shutdown_function(function () use ($since) {
        register_shutdown_function(function () use ($since) {
            bcc_execute(
                'DELETE FROM audit_log WHERE id > :s AND user_id IS NULL',
                array('s' => $since)
            );
        });
    });
}

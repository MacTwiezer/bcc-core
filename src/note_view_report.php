<?php

const BCC_NOTE_VIEW_WINDOW_DAYS = 15;

const BCC_NOTE_VIEW_LIST_LIMIT = 200;

function bcc_note_view_duration_text($seconds)
{
    if ($seconds === null) {
        return null;
    }

    $seconds = (int) $seconds;

    if ($seconds < 60) {
        return $seconds . ' sn';
    }

    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' dk ' . str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT) . ' sn';
    }

    return intdiv($seconds, 3600) . ' sa ' . str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT) . ' dk';
}

function bcc_note_view_rows($recordId)
{
    return bcc_fetch_all(
        'SELECT rvl.id, rvl.user_id, rvl.role_at_view, rvl.opened_at,
                rvl.closed_at, rvl.duration_seconds, u.full_name
           FROM record_view_log rvl
           LEFT JOIN users u ON u.id = rvl.user_id
          WHERE rvl.record_id = :record_id
            AND rvl.opened_at >= (NOW() - INTERVAL ' . BCC_NOTE_VIEW_WINDOW_DAYS . ' DAY)
          ORDER BY rvl.opened_at DESC
          LIMIT ' . BCC_NOTE_VIEW_LIST_LIMIT,
        array('record_id' => (int) $recordId)
    );
}

function bcc_note_view_record_title($recordId)
{
    $row = bcc_fetch_one(
        'SELECT cv.value_text
           FROM records r
           INNER JOIN fields f ON f.table_id = r.table_id
           LEFT JOIN cell_values cv ON cv.record_id = r.id AND cv.field_id = f.id
          WHERE r.id = :record_id
          ORDER BY f.position, f.id
          LIMIT 1',
        array('record_id' => (int) $recordId)
    );

    if (!$row || $row['value_text'] === null || trim((string) $row['value_text']) === '') {
        return '(başlıksız not)';
    }

    return (string) $row['value_text'];
}

function bcc_note_view_sweep_old()
{
    if (mt_rand(1, 100) !== 1) {
        return;
    }

    bcc_execute(
        'DELETE FROM record_view_log
          WHERE opened_at < (NOW() - INTERVAL ' . BCC_NOTE_VIEW_WINDOW_DAYS . ' DAY)
          LIMIT 500'
    );
}

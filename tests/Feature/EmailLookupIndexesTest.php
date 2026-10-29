<?php

use Illuminate\Support\Facades\Schema;

it('indexes email relations used by list and detail queries', function (string $table) {
    expect(collect(Schema::getIndexes($table))->contains(
        fn (array $index): bool => $index['columns'] === ['email_id'],
    ))->toBeTrue();
})->with(['email_recipients', 'email_events', 'email_attachments']);

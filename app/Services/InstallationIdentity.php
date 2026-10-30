<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class InstallationIdentity
{
    public function id(): string
    {
        $id = DB::table('larasend_installation')->where('id', 1)->value('instance_id');

        abort_unless(is_string($id) && $id !== '', 503, 'Installation identity is not initialized.');

        return $id;
    }
}

<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

Artisan::command('ams:prune {--days=90 : Delete attendance photos older than this many days}', function () {
    $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
    if (! $days || $days < 1) {
        $this->error('Days must be a positive integer.');

        return 1;
    }
    DB::table('access_tokens')->where('expires_at', '<', now())->delete();
    DB::table('face_challenges')->where('expires_at', '<', now())->orderBy('id')->chunkById(100, function ($rows) {
        foreach ($rows as $row) {
            if ($row->photo) {
                Storage::disk('local')->delete($row->photo);
            }
            DB::table('face_challenges')->where('id', $row->id)->delete();
        }
    });
    $count = 0;
    DB::table('attendances')->where('work_date', '<', now('Asia/Dhaka')->subDays($days)->toDateString())->whereNotNull('check_out')->where('in_photo', '!=', '')->orderBy('id')->chunkById(100, function ($rows) use (&$count) {
        foreach ($rows as $row) {
            Storage::disk('local')->delete(array_filter([$row->in_photo, $row->out_photo]));
            DB::table('attendances')->where('id', $row->id)->update(['in_photo' => '', 'out_photo' => null]);
            $count++;
        }
    });
    $this->info("Pruned photos for {$count} closed attendance records; timestamps retained.");
})->purpose('Apply the attendance photo retention policy and remove expired access tokens');

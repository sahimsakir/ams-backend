<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class FaceProof
{
    public static function lockUsers(int $actor, int $subject): void
    {
        DB::table('users')->whereIn('id', array_unique([$actor, $subject]))->orderBy('id')->lockForUpdate()->get();
    }

    public static function profileHash(?object $profile): ?string
    {
        return $profile ? hash('sha256', $profile->embedding) : null;
    }

    // Call inside a transaction, after locking the user. The same lock order is used everywhere.
    public static function consume(int $uid, string $id, string $purpose): object
    {
        $c = DB::table('face_challenges')->where('id', $id)->where('user_id', $uid)->lockForUpdate()->first();
        abort_unless($c && $c->purpose === $purpose, 422, 'Invalid camera verification. Start again.');
        abort_if($c->consumed_at || ! $c->completed_at || CarbonImmutable::parse($c->expires_at)->isPast(), 422, 'Camera verification is incomplete, expired or already used. Start again.');
        $subject = (int) ($c->subject_id ?? $uid);
        abort_if($purpose !== 'enroll' && $subject !== $uid, 422, 'Invalid attendance subject.');
        $profile = DB::table('face_profiles')->where('user_id', $subject)->first();
        abort_unless($c->profile_hash === self::profileHash($profile), 422, 'Face profile changed. Start again.');
        abort_if($purpose !== 'enroll' && (! $profile || $profile->verification_version < 2), 422, 'Complete your face enrollment first.');
        DB::table('face_challenges')->where('id', $id)->update(['consumed_at' => now()]);
        $c->decoded = json_decode(Crypt::decryptString($c->state), true);

        abort_if($purpose !== 'enroll' && (($c->decoded['face_verified'] ?? false) !== true || ($c->decoded['liveness_verified'] ?? false) !== true || ($c->decoded['liveness_model'] ?? null) !== 'minivision-b6d5f04-ensemble-v1' || ! $c->capture_hash), 422, 'Capture an image matching your enrolled face before giving attendance.');

        return $c;
    }

    public static function attendance(?object $row): ?object
    {
        if (! $row) {
            return null;
        }
        foreach (['check_in', 'check_out'] as $key) {
            $row->$key = $row->$key ? CarbonImmutable::parse($row->$key, 'UTC')->utc()->toISOString() : null;
        }
        // Never expose private storage paths or matching scores in attendance JSON.
        unset($row->in_photo, $row->out_photo, $row->in_distance, $row->out_distance, $row->in_capture_hash, $row->out_capture_hash);

        return $row;
    }
}

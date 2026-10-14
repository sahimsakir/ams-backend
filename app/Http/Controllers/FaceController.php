<?php

namespace App\Http\Controllers;

use App\Services\FaceProof;
use App\Services\Workflow;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FaceController extends Controller
{
    private function view(object $c): array
    {
        $steps = json_decode($c->steps, true);

        return ['id' => $c->id, 'step' => $c->step, 'total' => count($steps), 'pose' => $steps[$c->step] ?? null, 'nonce' => $c->nonce, 'complete' => (bool) $c->completed_at, 'expires_at' => CarbonImmutable::parse($c->expires_at)->utc()->toISOString()];
    }

    public function start(Request $r)
    {
        $d = $r->validate(['purpose' => 'required|in:enroll,in,out', 'consent' => 'required|accepted', 'user_id' => 'nullable|integer|exists:users,id']);

        return DB::transaction(function () use ($r, $d) {
            $uid = $r->user()->id;
            $subject = (int) ($d['user_id'] ?? $uid);
            abort_if($subject !== $uid && ($d['purpose'] !== 'enroll' || $r->user()->role !== 'owner'), 403, 'Only owners can enroll other users.');
            FaceProof::lockUsers($uid, $subject);
            abort_unless(DB::table('users')->where('id', $subject)->where('active', true)->exists(), 422, 'This account is inactive.');
            abort_if($subject !== $uid && ! DB::table('face_profiles')->where('user_id', $uid)->where('verification_version', '>=', 2)->exists(), 403, 'Enroll your own face first.');
            $profile = DB::table('face_profiles')->where('user_id', $subject)->first();
            if ($d['purpose'] === 'enroll') {
                abort_if($profile && $profile->verification_version >= 2, 409, 'Already enrolled. Ask an owner to reset your profile if needed.');
            } else {
                abort_unless($profile && $profile->verification_version >= 2, 422, 'Complete your own guided face enrollment first.');
            }
            // Starting again revokes old challenges, including completed but unused ones.
            DB::table('face_challenges')->where(fn ($q) => $q->where('subject_id', $subject)->orWhere(fn ($q) => $q->whereNull('subject_id')->where('user_id', $subject)))->whereNull('consumed_at')->update(['expires_at' => now()]);
            $steps = $d['purpose'] === 'enroll' ? ['front', 'left', 'right'] : ['capture'];
            $state = ['templates' => [], 'hashes' => [], 'baseline' => null, 'distance' => 0];
            if ($profile) {
                $known = json_decode(Crypt::decryptString($profile->embedding), true);
                $state['known'] = $profile->verification_version >= 2 ? $known['templates'] : [$known];
            }
            $id = (string) Str::uuid();
            DB::table('face_challenges')->insert(['id' => $id, 'user_id' => $uid, 'subject_id' => $subject, 'purpose' => $d['purpose'], 'steps' => json_encode($steps), 'nonce' => Str::random(48), 'profile_hash' => FaceProof::profileHash($profile), 'state' => Crypt::encryptString(json_encode($state)), 'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now()]);

            return $this->view(DB::table('face_challenges')->where('id', $id)->first());
        });
    }

    public function step(Request $r, string $id)
    {
        $d = $r->validate(['nonce' => 'required|string', 'frames' => 'required|array|min:4|max:16', 'frames.*' => 'required|file|mimes:jpeg|max:512']);

        return DB::transaction(function () use ($r, $id, $d) {
            $uid = $r->user()->id;
            $candidate = DB::table('face_challenges')->where('id', $id)->where('user_id', $uid)->first();
            abort_unless($candidate, 404);
            $subject = (int) ($candidate->subject_id ?? $uid);
            abort_if($subject !== $uid && $r->user()->role !== 'owner', 403);
            FaceProof::lockUsers($uid, $subject);
            $c = DB::table('face_challenges')->where('id', $id)->where('user_id', $uid)->lockForUpdate()->first();
            abort_unless($c, 404);
            abort_unless($c->purpose === 'enroll', 422, 'Attendance requires a fresh anti-spoof camera capture.');
            abort_if($c->consumed_at || $c->completed_at || CarbonImmutable::parse($c->expires_at)->isPast(), 422, 'Camera session expired. Restart the camera.');
            abort_unless(hash_equals($c->nonce, $d['nonce']), 409, 'This step was already submitted. Restart the camera.');
            abort_unless($c->profile_hash === FaceProof::profileHash(DB::table('face_profiles')->where('user_id', $subject)->first()), 422, 'Profile changed. Restart the camera.');
            abort_if(CarbonImmutable::parse($c->updated_at)->diffInMilliseconds(now(), false) < 700, 422, 'Follow the live camera prompt before submitting.');
            $state = json_decode(Crypt::decryptString($c->state), true);
            $steps = json_decode($c->steps, true);
            $pose = $steps[$c->step];
            $hashes = array_map(fn ($f) => hash_file('sha256', $f->path()), $r->file('frames'));
            abort_if(count(array_unique($hashes)) !== count($hashes) || array_intersect($hashes, $state['hashes']), 422, 'Repeated or frozen frames detected. Use your live camera.');
            $http = Http::timeout(90)->withHeaders(['X-Service-Key' => config('ams.face_key')]);
            foreach ($r->file('frames') as $i => $f) {
                $http->attach('frames', file_get_contents($f->path()), "frame-$i.jpg");
            }
            try {
                $response = $http->post(config('ams.face_url').'/verify-step', ['pose' => $pose, 'baseline' => json_encode($state['baseline']), 'known' => json_encode($state['known'] ?? $state['templates']), 'threshold' => (string) config('ams.threshold')]);
            } catch (\Throwable $e) {
                abort(503, 'Face service unavailable. Retry this step.');
            }
            abort_if($response->serverError(), 503, 'Face service unavailable. Retry this step.');
            abort_unless($response->successful(), 422, 'Step not verified. Keep one face well lit, follow the prompt and retry.');
            $v = $response->json();
            $vector = $v['embedding'] ?? null;
            abort_unless(is_array($vector) && count($vector) === 128 && collect($vector)->every(fn ($n) => is_numeric($n) && is_finite((float) $n)) && isset($v['baseline']['yaw'], $v['baseline']['pitch'], $v['baseline']['ear']) && is_numeric($v['distance'] ?? null) && is_finite((float) $v['distance']), 503, 'Invalid face service response.');
            abort_unless(collect($v['baseline'])->every(fn ($n) => is_numeric($n) && is_finite((float) $n)), 503, 'Invalid face service response.');
            abort_if($v['distance'] < 0 || $v['distance'] > config('ams.threshold'), 422, 'Face does not match your profile.');
            $state['baseline'] ??= $v['baseline'];
            $state['templates'][] = $vector;
            // Even enrollment stays bound to its initial face throughout all steps.
            $state['known'] ??= [$vector];
            $state['distance'] = max($state['distance'], $v['distance']);
            $state['hashes'] = array_merge($state['hashes'], $hashes);
            $photo = $c->photo;
            if ($c->step === 0) {
                $photo = $r->file('frames')[0]->store('face-challenges', 'local');
            }
            $complete = $c->step + 1 === count($steps);
            DB::table('face_challenges')->where('id', $id)->update(['state' => Crypt::encryptString(json_encode($state)), 'photo' => $photo, 'step' => $c->step + 1, 'nonce' => Str::random(48), 'updated_at' => now(), 'completed_at' => $complete ? now() : null, 'expires_at' => $complete ? now()->addMinutes(2) : $c->expires_at]);

            return $this->view(DB::table('face_challenges')->where('id', $id)->first());
        });
    }

    public function capture(Request $r, string $id)
    {
        $d = $r->validate(['nonce' => 'required|string', 'photo' => 'required|file|mimes:jpeg|max:1024']);

        return DB::transaction(function () use ($r, $id, $d) {
            $uid = $r->user()->id;
            FaceProof::lockUsers($uid, $uid);
            $c = DB::table('face_challenges')->where('id', $id)->where('user_id', $uid)->lockForUpdate()->first();
            abort_unless($c && in_array($c->purpose, ['in', 'out']) && (int) $c->subject_id === $uid, 422, 'Invalid attendance camera session.');
            abort_if($c->consumed_at || $c->completed_at || CarbonImmutable::parse($c->expires_at)->isPast(), 422, 'Camera session expired or used. Restart the camera.');
            abort_unless(hash_equals($c->nonce, $d['nonce']), 409, 'Invalid capture nonce. Restart the camera.');
            $profile = DB::table('face_profiles')->where('user_id', $uid)->first();
            abort_unless($profile && $profile->verification_version >= 2 && $c->profile_hash === FaceProof::profileHash($profile), 422, 'Face profile changed. Restart the camera.');
            $hash = hash_file('sha256', $r->file('photo')->path());
            $reused = DB::table('attendances')->where('user_id', $uid)->where(fn ($q) => $q->where('in_capture_hash', $hash)->orWhere('out_capture_hash', $hash))->exists();
            abort_if($reused, 422, 'This capture was already used. Take a fresh camera photo.');
            $state = json_decode(Crypt::decryptString($c->state), true);
            try {
                $response = Http::timeout(45)->withHeaders(['X-Service-Key' => config('ams.face_key')])->attach('photo', file_get_contents($r->file('photo')->path()), 'capture.jpg')->post(config('ams.face_url').'/verify-capture', ['known' => json_encode($state['known']), 'threshold' => (string) config('ams.threshold')]);
            } catch (\Throwable $e) {
                abort(503, 'Face and anti-spoof verification unavailable. Try again.');
            }
            abort_if($response->serverError(), 503, 'Anti-spoof verification unavailable. No attendance recorded.');
            abort_unless($response->successful(), 422, 'Face not verified. Use your own clear face in good lighting.');
            $distance = $response->json('distance');
            $score = $response->json('live_score');
            abort_unless(is_numeric($distance) && is_finite((float) $distance) && $distance >= 0 && is_numeric($score) && is_finite((float) $score) && $score >= 0 && $score <= 1, 503, 'Invalid verification response.');
            abort_if($distance > config('ams.threshold'), 422, 'Face does not match your enrolled profile.');
            $minimum = config('ams.live_min_score');
            abort_unless(is_finite($minimum) && $minimum >= .6 && $minimum <= 1, 503, 'Invalid anti-spoof configuration.');
            Log::info('ams.attendance.face_verification', [
                'user_id' => $uid,
                'result' => $score >= $minimum ? 'passed' : 'uncertain',
                'live_score' => round((float) $score, 4),
                'required_live_score' => $minimum,
                'face_distance' => round((float) $distance, 4),
            ]);
            abort_if($score < $minimum, 422, 'We could not confirm this capture is a live face. Try brighter, even lighting and keep your face close to the camera.');
            $state['distance'] = (float) $distance;
            $state['passive_verified'] = true;
            $photo = $r->file('photo')->store('face-challenges', 'local');
            DB::table('face_challenges')->where('id', $id)->update(['state' => Crypt::encryptString(json_encode($state)), 'photo' => $photo, 'capture_hash' => $hash, 'step' => 1, 'nonce' => Str::random(48), 'completed_at' => now(), 'updated_at' => now(), 'expires_at' => now()->addMinutes(2)]);

            return $this->view(DB::table('face_challenges')->where('id', $id)->first());
        });
    }

    public function enroll(Request $r)
    {
        $d = $r->validate(['challenge_id' => 'required|uuid']);

        return DB::transaction(function () use ($r, $d) {
            $uid = $r->user()->id;
            $candidate = DB::table('face_challenges')->where('id', $d['challenge_id'])->where('user_id', $uid)->first();
            abort_unless($candidate, 422, 'Invalid camera verification.');
            $subject = (int) ($candidate->subject_id ?? $uid);
            abort_if($subject !== $uid && $r->user()->role !== 'owner', 403);
            FaceProof::lockUsers($uid, $subject);
            $c = FaceProof::consume($uid, $d['challenge_id'], 'enroll');
            abort_if(DB::table('face_profiles')->where('user_id', $subject)->where('verification_version', '>=', 2)->exists(), 409, 'Already enrolled.');
            DB::table('face_profiles')->updateOrInsert(['user_id' => $subject], ['embedding' => Crypt::encryptString(json_encode(['templates' => $c->decoded['templates']])), 'verification_version' => 2, 'enrolled_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
            Workflow::audit($uid, $subject === $uid ? 'face.self_enrolled' : 'face.owner_enrolled', (string) $subject);

            return ['message' => $subject === $uid ? 'Your face is enrolled. Your workspace is ready.' : 'Face enrolled successfully for this user.', 'face_enrolled' => true, 'user_id' => $subject];
        });
    }
}

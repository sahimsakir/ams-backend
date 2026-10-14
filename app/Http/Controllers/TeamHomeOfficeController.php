<?php

namespace App\Http\Controllers;

use App\Services\Workflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamHomeOfficeController extends Controller
{
    public function index(Request $r)
    {
        return ['teams' => DB::table('teams')->orderBy('name')->get(['id', 'name']), 'data' => DB::table('team_home_office')->leftJoin('teams', 'teams.id', '=', 'team_id')->join('users', 'users.id', '=', 'created_by')->select('team_home_office.*', 'teams.name as team_name', 'users.name as creator')->orderByDesc('team_home_office.id')->get()];
    }

    public function store(Request $r)
    {
        $d = $r->validate(['team_id' => 'nullable|integer|exists:teams,id', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date', 'reason' => 'required|string|min:5|max:2000']);
        $id = DB::transaction(function () use ($r, $d) {
            $id = DB::table('team_home_office')->insertGetId([...$d, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            Workflow::audit($r->user()->id, 'team_home_office.created', (string) $id);

            return $id;
        });

        return response()->json(['id' => $id, 'message' => 'Team home office assigned'], 201);
    }

    public function destroy(Request $r, int $id)
    {
        DB::transaction(function () use ($r, $id) {
            $row = DB::table('team_home_office')->where('id', $id)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->cancelled_at, 409, 'Already cancelled.');
            DB::table('team_home_office')->where('id', $id)->update(['cancelled_at' => now(), 'updated_at' => now()]);
            Workflow::audit($r->user()->id, 'team_home_office.cancelled', (string) $id);
        });

        return ['message' => 'Assignment cancelled. Existing attendance retained.'];
    }
}

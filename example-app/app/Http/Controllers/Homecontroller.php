<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use App\Models\Champ;
use App\Models\User;
use App\Models\EvaluationInterne;
use App\Models\Invitation;
use App\Models\Fichier;
use App\Models\Filiereinvite;
use Illuminate\Support\Facades\Storage;

class HomeController extends Controller
{
    public function indexevaluation()
{
    $user = auth()->user();
    $idFiliere = $user->filières_id;
    $isUserInvited = $user->invitation == 1;


    $activeInvitation = Invitation::where('statue', 1)->first(); 
    $hasActiveInvitation = $isUserInvited && $activeInvitation !== null;

    if (!$hasActiveInvitation) {
        return view('layout.liste', [
            'message' => 'Aucune invitation active trouvée.',
            'hasActiveInvitation' => $hasActiveInvitation,
            'champNonEvaluer' => null,
            'tauxConformites' => [],
            'moyenneConformite' => 0,
            'nomsChamps' => [],
            'CHNEV' => [],
        ]);
    }

    // Get the invitation ID for the filiere
    $idfiliereinvite = Filiereinvite::where('idfiliere', $idFiliere)
        ->where('idcampagne', $activeInvitation->id)
        ->where('invitation', 1)
        ->pluck('id')
        ->first();

    if (!$idfiliereinvite) {
        return view('layout.liste', [
            'message' => 'Aucun enregistrement de filière pour cette invitation.',
            'hasActiveInvitation' => true,
            'champNonEvaluer' => null,
            'tauxConformites' => [],
            'moyenneConformite' => 0,
            'nomsChamps' => [],
            'CHNEV' => [],
        ]);
    }

    // Fetch all champs with their related references, criteres, and preuves
    $champs = Champ::with('references.criteres.preuves')->get();

    // Get all evaluated champs for the given idfiliereinvite
    $evaluatedChampsIds = EvaluationInterne::where('idfiliereinvite', $idfiliereinvite)
        ->pluck('idchamps')
        ->unique();

    // Filter out the evaluated champs from the list of all champs
    $CHNEV = $champs->filter(function ($champ) use ($evaluatedChampsIds) {
        return !$evaluatedChampsIds->contains($champ->id);
    });

    // Get the first non-evaluated champ if there are any
    $champNonEvaluer = $CHNEV->first();
    $tauxConformites = [];
    $nomsChamps = [];
    $moyenneConformite = 0;

    // Calculate conformity rates for all champs
    foreach ($champs as $champ) {
        $totalEvaluations = EvaluationInterne::where('idchamps', $champ->id)
            ->where('idfiliereinvite', $idfiliereinvite)
            ->count();

        $positiveEvaluations = EvaluationInterne::where('idchamps', $champ->id)
            ->where('idfiliereinvite', $idfiliereinvite)
            ->where('score', '>', 0)
            ->count();

        $tauxConformite = $totalEvaluations > 0 ? ($positiveEvaluations * 100 / $totalEvaluations) : 0;
        $tauxConformites[$champ->id] = $tauxConformite;
    }

    // Calculate the average conformity
    $moyenneConformite = count($tauxConformites) > 0 ? array_sum($tauxConformites) / count($tauxConformites) : 0;

    return view('layout.liste', compact('CHNEV', 'champNonEvaluer', 'hasActiveInvitation', 'tauxConformites', 'moyenneConformite', 'nomsChamps'));
}

public function evaluate(Request $request)
{
    $data = $request->all();
    $user = Auth::user();
    $activeInvitation = Invitation::where('statue', 1)->first();

    if (!$activeInvitation) {
        return redirect('/scores_champ')->withErrors('Aucune invitation active trouvée.');
    }

    // Get the idfiliereinvite for the current user and active invitation
    $idfiliereinvite = Filiereinvite::where('idfiliere', $user->filières_id)
        ->where('idcampagne', $activeInvitation->id)
        ->where('invitation', 1)
        ->pluck('id')
        ->first();

    if (!$idfiliereinvite) {
        return redirect('/scores_champ')->withErrors('Aucune invitation valide trouvée pour cette filière.');
    }

    foreach ($data['evaluations'] as $evaluation) {
        $score = 0;
        if ($evaluation['value'] === 'oui') {
            $score = 2;
        } elseif ($evaluation['value'] === 'non') {
            $score = -1;
        }

        // Create the evaluation record
        EvaluationInterne::create([
            'idcritere' => $evaluation['idcritere'],
            'idchamps' => $data['idchamps'],
            'idpreuve' => $evaluation['idpreuve'],
            'idfiliereinvite' => $idfiliereinvite,
            'idcampagne' => $activeInvitation->id,
            'score' => $score,
            'commentaire' => $evaluation['commentaire'] ?? null,
            'idfiliere' => $user->filières_id,
        ]);

        // Handle file upload
        if ($request->hasFile('file-' . $evaluation['idpreuve'])) {
            $filePath = $request->file('file-' . $evaluation['idpreuve'])->store('preuves');

            Fichier::create([
                'fichier' => $filePath,
                'idfiliere' => $user->filières_id,
                'idpreuve' => $evaluation['idpreuve'],
            ]);
        }
    }

    return redirect('/scores_champ');
}

public function getScores()
{
    $user = auth()->user();
    $idFiliere = $user->filières_id;

    // Obtenez les invitations actives pour cette filiere
    $invitationIds = Filiereinvite::where('idfiliere', $idFiliere)
        ->where('invitation', 1)
        ->pluck('id');

    $result = [];

    if ($invitationIds->isEmpty()) {
        $message = "Vous n'avez pas encore évalué de champs.";
        return response()->json(['message' => $message], 200);
    }

    $champsEvaluer = EvaluationInterne::whereIn('idfiliereinvite', $invitationIds)
        ->groupBy('idchamps')
        ->pluck('idchamps');

    if ($champsEvaluer->isEmpty()) {
        $message = "Vous n'avez pas encore évalué de champs.";
        return response()->json(['message' => $message], 200);
    }

    foreach ($champsEvaluer as $idchamps) {
        $champ = Champ::with(['references.criteres'])->find($idchamps);
        $criteresScores = [];

        foreach ($champ->references as $reference) {
            foreach ($reference->criteres as $critere) {
                $score = EvaluationInterne::where('idcritere', $critere->id)
                    ->where('idchamps', $idchamps)
                    ->whereIn('idfiliereinvite', $invitationIds)
                    ->sum('score');
                $criteresScores[] = [
                    'critere' => $critere->signature,
                    'score' => $score,
                ];
            }
        }

        $totalEvaluations = EvaluationInterne::where('idchamps', $idchamps)
            ->whereIn('idfiliereinvite', $invitationIds)
            ->count();
        $positiveEvaluations = EvaluationInterne::where('idchamps', $idchamps)
            ->whereIn('idfiliereinvite', $invitationIds)
            ->where('score', 2)
            ->count();
        $tauxConformite = ($totalEvaluations > 0) ? ($positiveEvaluations * 100 / $totalEvaluations) : 0;

        $result[] = [
            'champ' => $champ->name,
            'criteres' => $criteresScores,
            'tauxConformite' => $tauxConformite,
        ];
    }

    return response()->json($result, 200);
}


}

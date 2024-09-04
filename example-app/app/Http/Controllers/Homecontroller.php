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
use PDF;
class HomeController extends Controller

{
    public function indexevaluation()
    {
        $user = auth()->user();
        $idFiliere = $user->filières_id;
        $isUserInvited = $user->invitation == 1;
        $hasActiveInvitation = $isUserInvited && Invitation::where('statue', 1)->exists();
        $champs = Champ::with('references.criteres.preuves')->get();
        $champsEvaluer = $champs->filter(function($champ) use ($idFiliere) {
            foreach ($champ->references as $reference) {
                foreach ($reference->criteres as $critere) {
                    foreach ($critere->preuves as $preuve) {
                        if (EvaluationInterne::where('idpreuve', $preuve->id)->where('idfiliere', $idFiliere)->exists()) {
                            return true;
                        }
                    }
                }
            }
            return false;
        });
        $CHNEV = $champs->diff($champsEvaluer);
        $champNonEvaluer = $CHNEV->first();
        $tauxConformites = [];
        $nomsChamps = [];
    $moyenneConformite = 0;

    if ($champNonEvaluer === null) { // Tous les champs sont évalués
        foreach ($champs as $champ) {
            $totalEvaluations = EvaluationInterne::where('idchamps', $champ->id)->where('idfiliere', $idFiliere)->count();
            $positiveEvaluations = EvaluationInterne::where('idchamps', $champ->id)->where('idfiliere', $idFiliere)->where('score', '>', 0)->count(); // Assuming positive score is > 0
            $tauxConformite = $totalEvaluations > 0 ? ($positiveEvaluations * 100 / $totalEvaluations) : 0;
            $tauxConformites[$champ->id] = $tauxConformite;
            $nomsChamps[] = $champ;
        }
        $moyenneConformite = count($tauxConformites) > 0 ? array_sum($tauxConformites) / count($tauxConformites) : 0;
    }

    
        return view('layout.liste', compact('CHNEV','champNonEvaluer', 'hasActiveInvitation' , 'tauxConformites', 'moyenneConformite', 'nomsChamps'));
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
public function generatePDF()
{
    $data = $this->getDataForPdf(); 

    // Assurez-vous que les données sont en UTF-8
    array_walk_recursive($data, function(&$value) {
        $value = mb_convert_encoding($value, 'UTF-8', 'auto');
    });

    // Vérifiez si les images de graphiques sont correctement générées
    foreach ($data['champs'] as $index => $champ) {
        $labels = [];
        $scores = [];

        foreach ($champ['graph'] as $critereScore) {
            $labels[] = mb_convert_encoding($critereScore['critere'], 'UTF-8', 'auto');
            $scores[] = $critereScore['score'];
        }

        $chartPath = $this->generateChartWithGD($labels, $scores);
        
        if (!file_exists(public_path('storage/' . $chartPath))) {
            return response()->json(['error' => 'Erreur lors de la création des graphiques.'], 500);
        }

        $data['champs'][$index]['graph_image'] = public_path('storage/' . $chartPath);
    }

    // Charger la vue avec les données
    try {
        $pdf = PDF::loadView('layout.rapport-auto-evaluation', $data)
                  ->setPaper('a4', 'portrait');

        return $pdf->download('rapport-auto-evaluation.pdf');
    } catch (\Exception $e) {
        return response()->json(['error' => 'Erreur lors de la génération du PDF: ' . $e->getMessage()], 500);
    }
}
public function generateChartWithGD($labels, $scores)
{
    $width = 700;
    $height = 230;
    $image = imagecreatetruecolor($width, $height);
    $bgColor = imagecolorallocate($image, 255, 255, 255);
    $barColor = imagecolorallocate($image, 54, 162, 235);
    $textColor = imagecolorallocate($image, 0, 0, 0);

    imagefill($image, 0, 0, $bgColor);

    // Draw bars
    $barWidth = 40;
    $barSpacing = 20;
    $x = 50;
    foreach ($scores as $score) {
        imagefilledrectangle($image, $x, $height - $score * 10 - 30, $x + $barWidth, $height - 30, $barColor);
        $x += $barWidth + $barSpacing;
    }

    // Draw labels
    $x = 50;
    foreach ($labels as $label) {
        imagestring($image, 3, $x, $height - 20, $label, $textColor);
        $x += $barWidth + $barSpacing;
    }

    // Save image
    $chartPath = 'charts/chart.png';
    imagepng($image, public_path('storage/' . $chartPath));
    imagedestroy($image);

    return $chartPath;
}


private function getDataForPdf()
{
    $user = auth()->user();
    $idFiliere = $user->filières_id;
    $champs = Champ::with('references.criteres.preuves')->get();
    $invitationIds = Filiereinvite::where('idfiliere', $idFiliere)
        ->where('invitation', 1)
        ->pluck('id');

    $data = [
        'title' => 'Rapport d\'Autoévaluation',
        'authority' => 'Autorité Mauritanienne d\'Assurance Qualité de l\'Enseignement Supérieur',
        'champs' => [],
    ];

    foreach ($champs as $champ) {
        $criteresScores = [];
        $champData = [
            'name' => $champ->name,
            'references' => [],
            'graph' => [],
        ];

        foreach ($champ->references as $reference) {
            $referenceData = [
                'signature' => $reference->signature,
                'nom' => $reference->nom,
                'criteres' => [],
            ];

            foreach ($reference->criteres as $critere) {
                $critereData = [
                    'signature' => $critere->signature,
                    'nom' => $critere->nom,
                    'preuves' => [],
                ];

                foreach ($critere->preuves as $preuve) {
                    $evaluation = EvaluationInterne::where('idpreuve', $preuve->id)
                        ->whereIn('idfiliereinvite', $invitationIds)
                        ->first();

                    $response = $this->mapScoreToResponse($evaluation->score ?? 0);

                    $preuveData = [
                        'description' => $preuve->description,
                        'response' => ucfirst($response),
                        'commentaire' => $evaluation->commentaire ?? '',
                        'fichier' => $evaluation->fichier ? Storage::url($evaluation->fichier->fichier) : null,
                    ];

                    $critereData['preuves'][] = $preuveData;
                }

                $referenceData['criteres'][] = $critereData;
            }

            $champData['references'][] = $referenceData;
        }

        // Calcul des scores par critère
        foreach ($champ->references as $reference) {
            foreach ($reference->criteres as $critere) {
                $score = EvaluationInterne::where('idcritere', $critere->id)
                    ->where('idchamps', $champ->id)
                    ->whereIn('idfiliereinvite', $invitationIds)
                    ->sum('score');

                $criteresScores[] = [
                    'critere' => $critere->signature,
                    'score' => $score,
                ];
            }
        }

        $champData['graph'] = $criteresScores;
        $data['champs'][] = $champData;
    }

    return $data;
}

private function mapScoreToResponse($score)
{
    switch ($score) {
        case 2:
            return 'oui';
        case 0:
            return 'na';
        case -1:
            return 'non';
        default:
            return 'non défini';
    }
}

}

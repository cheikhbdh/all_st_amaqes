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
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Worksheet\Chart;
use QuickChart;

class Homecontroller extends Controller
{
    public function indexevaluation()
    {
        $user = auth()->user();
        $idFiliere = $user->filières_id;
        $isUserInvited = $user->invitation == 1;
        $hasActiveInvitation = $isUserInvited && Invitation::where('statue', 1)->exists();
        $champs = Champ::with('references.criteres.preuves')->get();
        $champsEvaluer = $champs->filter(function ($champ) use ($idFiliere) {
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
    public function generatePDF()
    {
        $scoresData = $this->getScores()->getData();
    
        $chartImages = [];
        $tauxConformiteText = [];
        $labelsChamp = [];
        $tauxConformiteChamp = [];
    
        foreach ($scoresData as $scoreItem) {
            $labels = [];
            $scores = [];
            $colors = [];
    
            foreach ($scoreItem->criteres as $critere) {
                $labels[] = $critere->critere; 
                $score = (int) round($critere->score); 
                $scores[] = $score; 
    
                if ($score > 0) {
                    $colors[] = '#078C03'; 
                } elseif ($score < 0) {
                    $colors[] = '#F20505'; 
                } else {
                    $colors[] = '#F20505'; 
                }
            }
    
            // Instancier QuickChart pour générer un graphique pour chaque champ
            $quickChart = new QuickChart([
                'width' => 600,
                'height' => 400,
            ]);

            $minScore = min($scores) - 1;
            $maxScore = max($scores) + 1;
            if(min($scores) == 0){
                $minScore = 5;
            }elseif(max($scores) == 0){
                $maxScore = 5;
            }

    
            $quickChart->setConfig('{
                type: "bar",
                data: {
                    labels: ' . json_encode($labels) . ',
                    datasets: [{
                        label: "Scores par Critère (Vert = Positif, Rouge = Négatif)",
                        data: ' . json_encode($scores) . ',
                        backgroundColor: ' . json_encode($colors) . '
                    }]
                },
                options: {
                    legend: {
                        display: true,
                        position: "top",
                        labels: {
                            boxWidth: 15, 
                            usePointStyle: true 
                        }
                    },
                    scales: {
                        yAxes: [{
                            ticks: {
                                beginAtZero: true,
                                min: ' . $minScore . ',
                                max: ' . $maxScore . ',
                            }
                        }]
                    }
                }
            }');
            
            



    
            // Obtenir l'image en base64
            $chartImage = base64_encode(file_get_contents($quickChart->getUrl()));
            $chartBase64 = 'data:image/png;base64,' . $chartImage;
    
            // Stocker chaque image de graphique et taux de conformité
            $chartImages[$scoreItem->champ] = $chartBase64;
            $tauxConformiteText[$scoreItem->champ] = round($scoreItem->tauxConformite, 2) . '% de conformité';
    
            // Ajouter les labels et taux de conformité pour le graphique global
            $labelsChamp[] = $scoreItem->champ;
            $tauxConformiteChamp[] = round($scoreItem->tauxConformite, 2);
        }
    
        // Calculer la moyenne des taux de conformité
        $moyenneConformite = !empty($tauxConformiteChamp) ? array_sum($tauxConformiteChamp) / count($tauxConformiteChamp) : 0;
        
        // Ajouter un label pour la moyenne globale
        $labelsChamp[] = 'Moyenne Globale';
        $tauxConformiteChamp[] = round($moyenneConformite, 2); // Arrondir la moyenne à 2 décimales
    
        // Instancier QuickChart pour générer un graphique global pour tous les champs
        $quickChartGlobal = new QuickChart([
            'width' => 600,
            'height' => 400,
        ]);
    
        // Configurer le graphique global avec les labels des champs et leurs taux de conformité
        $quickChartGlobal->setConfig('{
            type: "bar",
            data: {
                labels: ' . json_encode($labelsChamp) . ',
                datasets: [{
                    label: "Taux de Conformité des Champs",
                    data: ' . json_encode($tauxConformiteChamp) . ',
                    backgroundColor: [
                        ' . str_repeat('"#F2E205", ', count($labelsChamp) - 1) . ' "#0511F2" // Couleur différente pour la moyenne
                    ]
                }]
            },
            options: {
                plugins: {
                    datalabels: {
                        display: true,
                        color: "black",
                        align: "top",
                        formatter: function(value) {
                            return value + "%";
                        }
                    }
                }
            }
        }');
    
        // Obtenir l'image du graphique global en base64
        $chartImageGlobal = base64_encode(file_get_contents($quickChartGlobal->getUrl()));
        $chartBase64Global = 'data:image/png;base64,' . $chartImageGlobal;
    
        // Récupérer les autres données à inclure dans le PDF
        $data = $this->getDataForPdf();
    
        // Ajouter les graphiques en base64, le taux de conformité au texte, et le graphique global
        $data['chartImages'] = $chartImages;  // Graphiques par champ
        $data['tauxConformiteText'] = $tauxConformiteText; // Taux de conformité par champ
        $data['chartBase64Global'] = $chartBase64Global; // Graphique global
    
        // Générer le PDF
        $pdf = PDF::loadView('layout.rapport-auto-evaluation', $data)
                  ->setPaper('a4', 'portrait');
    
        // Télécharger le PDF
        return $pdf->download('rapport-auto-evaluation.pdf');
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

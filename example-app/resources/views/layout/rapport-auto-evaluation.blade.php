

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 12px; }
        .page-break { page-break-after: always; }
        h1, h2, h3, h4 { text-align: center; }
        .header {
            text-align: center;
            margin-bottom: 50px;
        }
        .authority {
            font-size: 18px;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .chapter {
            margin-bottom: 30px;
        }
        .reference {
            margin-left: 20px;
        }
        .critere {
            margin-left: 40px;
        }
        .preuve {
            margin-left: 60px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table, th, td {
            border: 1px solid black;
        }
        th, td {
            padding: 5px;
            text-align: left;
        }
        .graph {
            text-align: center;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <!-- Page de Garde -->
    <div class="header">
        <h1>{{ $title }}</h1>
        <div class="authority">{{ $authority }}</div>
    </div>
    <div class="page-break"></div>

    <!-- Contenu des Champs -->
    @foreach($champs as $champ)
        <div class="chapter">
            <h2>{{ $champ['name'] }}</h2>
            
            @foreach($champ['references'] as $reference)
                <div class="reference">
                    <h3>{{ $reference['signature'] }} : {{ $reference['nom'] }}</h3>
                    
                    @foreach($reference['criteres'] as $critere)
                        <div class="critere">
                            <h4>{{ $critere['signature'] }} : {{ $critere['nom'] }}</h4>
                            
                            <table>
                                <thead>
                                    <tr>
                                        <th>Description de la Preuve</th>
                                        <th>Réponse</th>
                                        <th>Commentaire</th>

                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($critere['preuves'] as $preuve)
                                        <tr>
                                            <td>{{ $preuve['description'] }}</td>
                                            <td>{{ $preuve['response'] }}</td>
                                            <td>{{ $preuve['commentaire'] }}</td>
                                           
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>
            @endforeach

            <!-- Graphique des Scores par Critère -->
            <div class="graph">
                <h3>Scores par Critère</h3>
                <img src="{{ public_path('charts/champ_'.$loop->index.'_graph.png') }}" alt="Graphique des Scores">

            </div>
        </div>
        <div class="page-break"></div>
    @endforeach
</body>
</html>


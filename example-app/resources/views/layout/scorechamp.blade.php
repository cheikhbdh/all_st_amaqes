@extends('layout.layout-index')

@section('content')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Graphique des Scores des Critères</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-3d@0.1.1/dist/chartjs-plugin-3d.min.js"></script>
    <!-- Inclure Font Awesome pour les icônes -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        .custom-container {
            max-width: 1200px;
            margin: auto;
            padding: 20px;
        }
        .box {
            border: 1px solid #000;
            padding: 20px;
            margin-bottom: 20px;
            width: 48%;
            box-sizing: border-box;
            position: relative; /* Pour positionner le bouton à l'intérieur */
            overflow: hidden; /* Pour masquer l'icône qui déborde */
        }
        .box-title {
            font-weight: bold;
            margin-bottom: 10px;
        }
        .champ-title {
            color: blue; /* Couleur bleue uniquement pour le nom du champ */
        }
        .box-footer {
            margin-top: 10px;
            padding: 10px;
            border-top: 1px solid #000;
        }
        .box-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
        }
        canvas {
            display: block;
            margin: 0 auto;
        }
        .download-btn {
            position: absolute;
            top: 10px; /* Position en haut */
            right: 10px; /* Position à droite */
            background-color: #007bff;
            color: white;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 5px;
            z-index: 1; /* Pour empêcher le texte de couvrir l'icône */
        }
        .message-box {
            width: 100%;
            text-align: center;
            padding: 20px;
            background-color: #f0f0f0;
            border: 1px solid #ccc;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <main id="main" class="main">
        <div class="custom-container">
            <div id="chartsContainer" class="box-container"></div>
            <div id="messageBox" class="message-box" style="display: none;">
                Aucun champ évalué pour le moment.
            </div>
            <button id="go-home" class="btn btn-secondary mt-3">Retour à l'accueil</button>
        </div>
    </main>

    <script>
        async function fetchScores() {
            const response = await fetch('/get-scores');
            return await response.json();
        }

        function generateChart(containerId, champData) {
            const ctx = document.getElementById(containerId).getContext('2d');
            const labels = champData.criteres.map(critere => critere.critere);
            const data = champData.criteres.map(critere => critere.score);

            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: [{
                        label: `Scores des Critères - ${champData.champ}`,
                        data: data.map(score => {
                            if (score === 0) {
                                return 0.1; 
                            } else {
                                return score; 
                            }
                        }),
                        backgroundColor: data.map(score => {
                            if (score > 0) {
                                return 'green';
                            } else if (score < 0) {
                                return 'yellow';
                            } else {
                                return 'red'; 
                            }
                        }),
                        borderColor: 'black',
                        borderWidth: data.map(score => score === 0 ? 0.5 : 1),
                    }]
                },
                options: {
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    },
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                        },
                        tooltip: {
                            enabled: true,
                        },
                        '3d': {
                            enabled: true,
                            alpha: 45,
                            beta: 45,
                            depth: 50,
                            viewDistance: 25,
                        }
                    }
                }
            });
        }

        document.addEventListener('DOMContentLoaded', async () => {
            const scores = await fetchScores();
            const chartsContainer = document.getElementById('chartsContainer');
            const messageBox = document.getElementById('messageBox');

            // Vérifier s'il y a un message à afficher (aucun champ évalué)
            if (scores.hasOwnProperty('message')) {
                messageBox.style.display = 'block';
                return; // Pas besoin de continuer si aucun champ n'est évalué
            }

            scores.forEach((champData, index) => {
                const box = document.createElement('div');
                box.className = 'box';

                const downloadBtn = document.createElement('button');
                downloadBtn.className = 'download-btn';
                downloadBtn.id = 'download-stats';
                downloadBtn.innerHTML = '<i class="fas fa-download"></i>'; // Icône de téléchargement
                downloadBtn.addEventListener('click', () => {
                    // Action de téléchargement ici
                    html2canvas(box).then(canvas => {
                        const link = document.createElement('a');
                        link.download = `stats-${champData.champ}.png`;
                        link.href = canvas.toDataURL('image/png');
                        link.click();
                    });
                });
                box.appendChild(downloadBtn);

                const boxTitle = document.createElement('div');
                boxTitle.className = 'box-title';
                boxTitle.innerHTML = `Niveau de conformité du champ: <span class="champ-title">${champData.champ}</span>`;
                box.appendChild(boxTitle);

                const canvasId = `chart-${index}`;
                const canvas = document.createElement('canvas');
                canvas.id = canvasId;
                canvas.width = 400;
                canvas.height = 200;
                box.appendChild(canvas);

                const boxFooter = document.createElement('div');
                boxFooter.className = 'box-footer';
                boxFooter.innerHTML = `Taux de conformité au critère du <span class="champ-title">${champData.champ}</span> : ${champData.tauxConformite.toFixed(2)}%`;
                box.appendChild(boxFooter);

                chartsContainer.appendChild(box);

                generateChart(canvasId, champData);
            });

            // Ajouter l'événement click à l'élément #download-stats
            document.getElementById('download-stats').addEventListener('click', () => {
                console.log('Téléchargement du graphique...');
            });
        });
        document.getElementById('go-home').addEventListener('click', () => {
            window.location.href = '/indexevaluation'; // Assurez-vous que '/' est l'URL correcte pour la page d'accueil de votre application
        });
    </script>
</body>
</html>
@endsection
